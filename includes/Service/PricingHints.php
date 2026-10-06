<?php
/**
 * Per-value pricing hints for cart and order surfaces.
 *
 * WAPF Extended 3.1.5 parity (WAPF-DISPLAY-PRICE-HINTS):
 * `SW_WAPF_PRO\Includes\Classes\Helper::format_pricing_hint`,
 * `maybe_add_tax`, `adjust_addon_price` and `format_price`, plus
 * `Util::pricing_hint_format` / `Util::show_pricing_hints`.
 *
 * WAPF writes the hint once per cart-field value (`pricing_hint`) during
 * `Cart::calculate_cart_item_prices` with `$for_page = 'cart'`; that same
 * string feeds both the cart item_data `display` markup (wrapped in a
 * `wapf-pricing-hint` span) and the raw order-item meta value
 * (`Gold (+&#36;5.00)`). OPF stores raw values only, so hints are computed
 * on demand from the cart line's stored base price and quantity — see
 * `CartIntegration::value_pricing_hints` for the per-value pipeline.
 *
 * OPF filter names mirror the WAPF hook surface:
 *  - `wapf/html/pricing_hint/format`  → `opf_pricing_hint_format`
 *  - `wapf/html/pricing_hint/amount`  → `opf_pricing_hint_amount`
 *  - `wapf/html/pricing_hint`         → `opf_pricing_hint`
 *  - `wapf/pricing/price_with_tax`    → `opf_pricing_price_with_tax`
 *  - `wapf/pricing/addon`             → `opf_pricing_addon`
 *
 * `adjust_addon_price` additionally routes the amount through the store's own
 * product-price conversion (`convert_via_product_price`) so a currency plugin
 * that converts the cart line also converts the displayed hint, exactly where
 * CURCY hangs WAPF's conversion off `wapf/pricing/addon`.
 *
 * The storefront (product page) hint lives in Renderer::pricing_hint_html —
 * this service only covers the cart/order surface.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class PricingHints {

	/** Fallback hint format when `opf_hint_format` is unset (WAPF default). */
	public const DEFAULT_FORMAT = '(+{x})';

	/**
	 * WAPF Util::show_pricing_hints parity: the `opf_show_price_hints`
	 * setting gates every hint surface (storefront, cart display, order meta).
	 */
	public static function enabled(): bool {
		$show = 'yes' === get_option( 'opf_show_price_hints', 'yes' );
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'opf_show_price_hints', $show ) : $show;
	}

	/**
	 * WAPF Util::pricing_hint_format parity: `opf_hint_format` holds the
	 * `{x}`/`+` placeholder template; absent/empty falls back to `(+{x})`.
	 */
	public static function hint_format(): string {
		$hint = get_option( 'opf_hint_format', '' );
		return empty( $hint ) ? self::DEFAULT_FORMAT : (string) $hint;
	}

	/**
	 * WAPF Helper::format_pricing_hint parity.
	 *
	 * $amount is the CALCULATED per-value contribution (WAPF `calc_price`),
	 * not the configured amount — on 'cart' a percent value therefore renders
	 * as money (`(+$2.00)`), while 'shop' renders the percent itself
	 * (`(+10%)`). OPF pricing types are 'fixed', 'percent' and 'formula'
	 * (formula = WAPF `fx`); WAPF's char/nr quantities have no OPF equivalent.
	 *
	 * @param string                    $type     OPF pricing type.
	 * @param float|int|string          $amount   Per-unit contribution or raw amount.
	 * @param \WC_Product|int|null      $product  Product (or id) for tax context.
	 * @param string                    $for_page 'shop'|'cart'.
	 * @param array<string,mixed>|null  $field    Field definition (filter context).
	 * @param array<string,mixed>|null  $option   Choice definition (filter context).
	 */
	public static function format( $type, $amount, $product, string $for_page = 'shop', ?array $field = null, ?array $option = null ): string {
		$type   = (string) $type;
		$format = function_exists( 'apply_filters' )
			? (string) apply_filters( 'opf_pricing_hint_format', self::hint_format(), $product, $amount, $type )
			: self::hint_format();
		$raw_amount = $amount;
		$amount     = function_exists( 'apply_filters' )
			? apply_filters( 'opf_pricing_hint_amount', $amount, $product, $type, $for_page )
			: $amount;
		// A listener that rewrote the amount on `wapf/html/pricing_hint/amount`
		// already converted it (WOOCS/Aelia do — see convert_via_product_price).
		$converted = is_numeric( $raw_amount ) && is_numeric( $amount )
			? (float) $amount !== (float) $raw_amount
			: $amount !== $raw_amount;
		$ar_sign   = empty( $amount ) ? '+' : ( $amount < 0 ? '' : '+' );

		if ( 'shop' === $for_page && 'percent' === $type ) {
			$hint = str_replace( [ '{x}', '+' ], [ ( empty( $amount ) ? 0 : $amount ) . '%', $ar_sign ], $format );
			return self::filter_hint( $hint, $product, $amount, $type, $field, $option );
		}

		$price_output = self::format_price( self::adjust_addon_price( $product, empty( $amount ) ? 0 : $amount, $type, $for_page, true, $converted ) );

		if ( 'formula' === $type ) {
			$hint = str_replace( [ '{x}', '+' ], [ '' === $amount ? '...' : $price_output, $ar_sign ], $format );
			return self::filter_hint( $hint, $product, $amount, $type, $field, $option );
		}

		$hint = str_replace( [ '{x}', '+' ], [ $price_output, $ar_sign ], $format );
		return self::filter_hint( $hint, $product, $amount, $type, $field, $option );
	}

	/**
	 * WAPF Helper::maybe_add_tax parity: raw (filterable) for empty/negative
	 * amounts or a tax-free store; 'cart' reads `woocommerce_tax_display_cart`
	 * and picks incl/excl explicitly; every other page defers to
	 * wc_get_price_to_display.
	 *
	 * @param \WC_Product|int|null $product  Product (or id).
	 * @param float|int|string     $price    Amount.
	 * @param string               $for_page 'shop'|'cart'.
	 * @return float|int|string
	 */
	public static function maybe_add_tax( $product, $price, string $for_page = 'shop' ) {
		if ( empty( $price ) || $price < 0 || ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return function_exists( 'apply_filters' )
				? apply_filters( 'opf_pricing_price_with_tax', $price, $price, $product, $for_page )
				: $price;
		}

		if ( is_int( $product ) ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product ) : $product;
		}

		$args = [ 'qty' => 1, 'price' => $price ];

		if ( 'cart' === $for_page ) {
			$incl      = 'incl' === get_option( 'woocommerce_tax_display_cart' );
			$fn        = $incl ? 'wc_get_price_including_tax' : 'wc_get_price_excluding_tax';
			$price_out = $product && function_exists( $fn ) ? $fn( $product, $args ) : $price;
		} else {
			$price_out = $product && function_exists( 'wc_get_price_to_display' )
				? wc_get_price_to_display( $product, $args )
				: $price;
		}

		return function_exists( 'apply_filters' )
			? apply_filters( 'opf_pricing_price_with_tax', $price_out, $price, $product, $for_page )
			: $price_out;
	}

	/**
	 * WAPF Helper::adjust_addon_price parity: zero stays zero, percent
	 * amounts pass through untaxed (the percent-derived result is the final
	 * figure), everything else runs through maybe_add_tax.
	 *
	 * @param \WC_Product|int|null $product       Product (or id).
	 * @param float|int|string     $amount        Amount.
	 * OPF's `opf_pricing_addon` is the alias of WAPF's `wapf/pricing/addon`, and
	 * that is where OPF then routes the amount through the store's own product
	 * price conversion (`convert_via_product_price`) so the displayed hint
	 * matches the converted cart line the customer is charged. Percent
	 * amounts return before either stage, exactly like WAPF.
	 *
	 * @param \WC_Product|int|null $product           Product (or id).
	 * @param float|int|string     $amount            Amount.
	 * @param string               $type              OPF pricing type.
	 * @param string               $for               'shop'|'cart'.
	 * @param bool                 $maybe_add_tax     Whether to tax-adjust.
	 * @param bool                 $already_converted Amount was converted on
	 *                                                `opf_pricing_hint_amount`.
	 * @return float|int|string
	 */
	public static function adjust_addon_price( $product, $amount, string $type, string $for = 'shop', bool $maybe_add_tax = true, bool $already_converted = false ) {
		if ( 0 === $amount || 0.0 === $amount ) {
			return 0;
		}

		if ( 'percent' === $type ) {
			return $amount;
		}

		if ( $maybe_add_tax ) {
			$amount = self::maybe_add_tax( $product, $amount, $for );
		}

		$amount = function_exists( 'apply_filters' )
			? apply_filters( 'opf_pricing_addon', $amount, $product, $type, $for )
			: $amount;

		return self::convert_via_product_price( $amount, $product, $already_converted );
	}

	/**
	 * Convert a hint amount through the conversion WooCommerce applies to
	 * product prices — the path the converted cart line total takes.
	 *
	 * WAPF 3.1.5's cart hint is converted by whichever currency integration
	 * converts product prices: CURCY hooks `wapf/pricing/addon` — the filter
	 * Helper::adjust_addon_price fires after tax (class-helper.php:451) — and
	 * converts with `wmc_get_price()`
	 * (plugins/advanced_product_fields_for_woocommerce_pro.php:31 and :122).
	 * That is the same conversion the line total receives through
	 * `woocommerce_product_get_price` (frontend/price.php:213 → :1515 →
	 * `wmc_get_price()`, includes/functions.php:103). OPF has no such
	 * integration, so the amount is run through that same product-price filter
	 * here. With no currency plugin — or none that converts product prices —
	 * no listener rewrites the value and the hint is byte-identical.
	 *
	 * WOOCS and Aelia are the exception: their WAPF integrations convert on
	 * `wapf/html/pricing_hint/amount` (class-woocs.php:12,
	 * class-aelia.php:16) — OPF's `opf_pricing_hint_amount`, applied before
	 * tax. `$already_converted` marks that, so an adapter-converted amount is
	 * never converted a second time.
	 *
	 * @param float|int|string     $amount            Post-`opf_pricing_addon` amount.
	 * @param \WC_Product|int|null $product           Product whose price filter converts.
	 * @param bool                 $already_converted Amount already converted upstream.
	 * @return float|int|string
	 */
	public static function convert_via_product_price( $amount, $product, bool $already_converted = false ) {
		if ( $already_converted || ! function_exists( 'apply_filters' ) ) {
			return $amount;
		}

		if ( is_int( $product ) ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product ) : $product;
		}

		if ( ! $product instanceof \WC_Product ) {
			return $amount;
		}

		return apply_filters( 'woocommerce_product_get_price', $amount, $product );
	}

	/**
	 * WAPF Helper::format_price parity — Woo display options verbatim:
	 * absolute value → number_format(decimals, decimal_separator,
	 * thousand_separator) → optional wc_trim_zeros → sign +
	 * sprintf(price_format, symbol, price). Produces e.g. `&#36;5.00`.
	 *
	 * @param float|int|string $amount Amount.
	 */
	public static function format_price( $amount ): string {
		$decimals           = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$decimal_separator  = function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.';
		$thousand_separator = function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',';
		$price_format       = function_exists( 'get_woocommerce_price_format' ) ? (string) get_woocommerce_price_format() : '%1$s%2$s';
		$symbol             = function_exists( 'get_woocommerce_currency_symbol' ) ? (string) get_woocommerce_currency_symbol() : '';
		$trim_zeroes        = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'woocommerce_price_trim_zeros', false ) : false;

		$price    = (float) $amount;
		$negative = $price < 0;
		$price    = $negative ? $price * -1 : $price;
		$price    = number_format( $price, $decimals, $decimal_separator, $thousand_separator );

		if ( $trim_zeroes && $decimals > 0 && function_exists( 'wc_trim_zeros' ) ) {
			$price = wc_trim_zeros( $price );
		}

		return ( $negative ? '-' : '' ) . sprintf( $price_format, $symbol, $price );
	}

	/**
	 * Wrap a computed hint string in the `opf_pricing_hint` filter
	 * (`wapf/html/pricing_hint` parity — product, amount, type, field and
	 * option all reach the listener).
	 *
	 * @param string                    $hint    Formatted hint.
	 * @param \WC_Product|int|null      $product Product.
	 * @param float|int|string          $amount  Filtered amount.
	 * @param string                    $type    Pricing type.
	 * @param array<string,mixed>|null  $field   Field context.
	 * @param array<string,mixed>|null  $option  Choice context.
	 */
	private static function filter_hint( string $hint, $product, $amount, string $type, ?array $field, ?array $option ): string {
		return function_exists( 'apply_filters' )
			? (string) apply_filters( 'opf_pricing_hint', $hint, $product, $amount, $type, $field, $option )
			: $hint;
	}
}
