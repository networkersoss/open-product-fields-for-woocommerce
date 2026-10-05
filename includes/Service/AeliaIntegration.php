<?php
/**
 * Aelia currency bridge. Calculate OPF amounts in shop currency, then convert
 * the complete cart target once after OPF's priority-20 pricing callback.
 *
 * @package open-product-fields-for-woocommerce
 */
namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class AeliaIntegration {

	public static function init(): void {
		add_filter( 'opf_frontend_config', [ __CLASS__, 'merge_frontend_config' ], 20 );
		add_filter( 'opf_cart_item_base_price', [ __CLASS__, 'cart_base_price' ], 30, 3 );
		add_filter( 'opf_formula_base_price', [ __CLASS__, 'formula_base_price' ], 20, 2 );
		add_filter( 'opf_pricing_hint_amount', [ __CLASS__, 'pricing_hint' ], 10, 4 );
		add_filter( 'opf/linked_products/choice', [ __CLASS__, 'linked_product_choice' ], 10, 3 );
		add_filter( 'woocommerce_available_variation', [ __CLASS__, 'variation_data' ], 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'convert_cart_prices' ], 21 );
		// Also supplies config when theme compatibility mode is disabled.
		// Classic scripts run at priority 20; config must precede first totals.
		add_action( 'wp_footer', [ __CLASS__, 'print_frontend_config' ], 5 );
	}

	/** Official Aelia integration guide documents this instance global. */
	private static function active(): bool {
		return ! empty( $GLOBALS['woocommerce-aelia-currencyswitcher'] );
	}

	/** Do not cache: rates and selected currency may change within a request. */
	public static function currency_info(): ?array {
		if ( ! self::active() ) {
			return null;
		}
		$base = (string) get_option( 'woocommerce_currency' );
		$current = (string) get_woocommerce_currency();
		if ( '' === $base || '' === $current ) {
			return null;
		}
		// WAPF reads the unrounded rate from settings. Converting one monetary
		// unit can round a small rate to zero for currencies with no decimals.
		$settings = is_callable( [ '\WC_Aelia_CurrencySwitcher', 'settings' ] ) ? \WC_Aelia_CurrencySwitcher::settings() : null;
		$rate = 0 === strcasecmp( $base, $current ) ? 1.0 : (
			is_callable( [ $settings, 'get_exchange_rate' ] ) ? $settings->get_exchange_rate( $current ) : apply_filters( 'wc_aelia_cs_convert', 1.0, $base, $current )
		);
		if ( ! is_numeric( $rate ) || ! is_finite( (float) $rate ) || (float) $rate <= 0 ) {
			return null;
		}
		return [ 'base' => $base, 'current' => $current, 'rate' => (float) $rate ];
	}

	/** Convert amounts through Aelia's documented API, preserving finite values. */
	public static function convert_amount( float $amount ): float {
		$info = self::currency_info();
		if ( null === $info || 0 === strcasecmp( $info['base'], $info['current'] ) ) {
			return $amount;
		}
		$result = apply_filters( 'wc_aelia_cs_convert', $amount, $info['base'], $info['current'] );
		return is_numeric( $result ) && is_finite( (float) $result ) ? (float) $result : $amount;
	}

	/**
	 * A fresh current price preserves Aelia's manually entered currency prices.
	 *
	 * WAPF parity: Aelia converts `get_price()` itself, so WAPF's cart base is
	 * the CONVERTED view price (verified live: `wapf_item_pricing.base` = 20 at
	 * rate 2 for a 10-USD product). Returning the converted price here makes
	 * percent-type addons compute on it exactly like WAPF (`10% × 20 = 2`).
	 */
	public static function cart_base_price( float $price, \WC_Product $product, array $cart_item = [] ): float {
		$info = self::currency_info();
		if ( null === $info ) {
			return $price;
		}
		$fresh = wc_get_product( $product->get_id() );
		return $fresh instanceof \WC_Product ? (float) $fresh->get_price() : $price;
	}

	/** WAPF formulas and linked choices use original shop prices with shop tax display. */
	public static function original_product_price( \WC_Product $product ): float {
		$fresh = wc_get_product( $product->get_id() );
		$price = (float) ( $fresh instanceof \WC_Product ? $fresh : $product )->get_price( 'edit' );
		return self::display_price( $product, $price );
	}

	private static function display_price( \WC_Product $product, float $price ): float {
		$args = [ 'qty' => 1, 'price' => $price ];
		return 'incl' === get_option( 'woocommerce_tax_display_shop' )
			? (float) wc_get_price_including_tax( $product, $args )
			: (float) wc_get_price_excluding_tax( $product, $args );
	}

	public static function formula_base_price( float $price, int $product_id ): float {
		$info = self::currency_info();
		if ( null === $info || 0 === strcasecmp( $info['base'], $info['current'] ) ) {
			return $price;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? self::original_product_price( $product ) : $price;
	}

	/**
	 * `wapf/linked_products/choice` parity — WAPF's `Aelia::change_product_choice_price`
	 * restores the shop-currency child price: the storefront applies
	 * `currency_rate` itself and the child's own cart line converts exactly once.
	 * `expand_choice()` stores the Aelia-converted view price, which would
	 * double-convert in the browser; a fresh `edit`-context read is the original
	 * price (also preserves Aelia's manually entered currency prices, which live
	 * outside `get_price('edit')` either way). OPF keeps the raw figure — the
	 * frontend tax factor, not shop-tax display, adds tax.
	 */
	public static function linked_product_choice( array $choice, $field, $product ): array {
		if ( ! $product instanceof \WC_Product || null === self::currency_info() ) {
			return $choice;
		}
		$fresh = wc_get_product( $product->get_id() );
		if ( $fresh instanceof \WC_Product ) {
			$choice['pricing_amount'] = (float) $fresh->get_price( 'edit' );
		}
		return $choice;
	}

	/**
	 * Only OPF lines are converted; OPF resets their shop target on each totals pass.
	 *
	 * WAPF 3.1.5 shape (verified against the real licensed plugin): the cart base
	 * is the Aelia-converted product price; the options total is converted on top
	 * of it. Percent addons therefore convert twice — once inside the converted
	 * base, once inside the options total — which real WAPF emits as
	 * `base 20 + options 30 = 50` for a USD-10 product at rate 2 with a 10%
	 * addon. Mirrored here as `conv_base + convert(price − conv_base)`.
	 */
	public static function convert_cart_prices( \WC_Cart $cart ): void {
		if ( null === self::currency_info() ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ CartIntegration::ITEM_KEY ] ) || ! isset( $item['opf_base_price'] ) || ! ( $item['data'] ?? null ) instanceof \WC_Product ) {
				continue;
			}
			$product   = $item['data'];
			$conv_base = self::cart_base_price( (float) $item['opf_base_price'], $product, $item );
			$target    = (float) $product->get_price( 'edit' );
			$options   = max( 0.0, $target - $conv_base );
			$product->set_price( (string) ( $conv_base + self::convert_amount( $options ) ) );
		}
	}

	public static function frontend_config(): ?array {
		$info = self::currency_info();
		if ( null === $info ) {
			return null;
		}
		return [
			'currency' => $info['current'],
			'currency_rate' => $info['rate'],
			'display_options' => [
				'symbol' => get_woocommerce_currency_symbol( $info['current'] ),
				'thousand' => wc_get_price_thousand_separator(),
				'decimal' => wc_get_price_decimal_separator(),
				'decimals' => wc_get_price_decimals(),
				'format' => get_woocommerce_price_format(),
			],
		];
	}

	public static function merge_frontend_config( array $config ): array {
		$currency = self::frontend_config();
		if ( null === $currency ) {
			return $config;
		}
		$config = array_replace_recursive( $config, $currency );
		global $product;
		if ( $product instanceof \WC_Product ) {
			$config['product_base_price'] = self::display_price( $product, self::cart_base_price( (float) $product->get_price( 'edit' ), $product ) );
			$config['formula_base_price'] = self::original_product_price( $product );
		}
		return $config;
	}

	public static function variation_data( array $data, \WC_Product $parent, \WC_Product $variation ): array {
		if ( null !== self::currency_info() ) {
			$data['opf_base_price'] = self::display_price( $variation, self::cart_base_price( (float) $variation->get_price( 'edit' ), $variation ) );
			$data['opf_formula_base_price'] = self::original_product_price( $variation );
		}
		return $data;
	}

	/**
	 * `wapf/html/pricing_hint/amount` parity — hint amounts are stored in shop
	 * currency and convert for display; formula previews convert at runtime in
	 * the browser, so only the cart's evaluated formula amount converts here.
	 *
	 * @param float                $amount  Hint amount in shop currency.
	 * @param \WC_Product|int|null $product Product context (unused by Aelia).
	 * @param string               $type    Pricing type.
	 * @param string               $page    'product'/'shop' or 'cart'.
	 */
	public static function pricing_hint( float $amount, $product, string $type, string $page = 'product' ): float {
		return 'formula' === $type && 'cart' !== $page ? $amount : self::convert_amount( $amount );
	}

	public static function print_frontend_config(): void {
		if ( ( function_exists( 'is_product' ) && ! is_product() ) || null === self::frontend_config() ) {
			return;
		}
		$json = wp_json_encode( self::merge_frontend_config( [] ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( is_string( $json ) ) {
			echo '<script>window.opf_config=Object.assign(window.opf_config||{},' . $json . ');</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hex-escaped JSON.
		}
	}
}
