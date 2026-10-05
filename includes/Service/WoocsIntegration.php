<?php
/**
 * WOOCS/FOX bridge. OPF writes shop-currency addon prices to the cart;
 * WOOCS owns the final cart conversion. Formula bases are read afresh so a
 * previous OPF calculation or a converted view price cannot become a base.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class WoocsIntegration {

	/** Register currency bridges without changing behavior when WOOCS is absent. */
	public static function init(): void {
		add_filter( 'opf_frontend_config', [ __CLASS__, 'merge_frontend_config' ] );
		add_filter( 'opf_cart_item_base_price', [ __CLASS__, 'cart_base_price' ], 20, 3 );
		add_filter( 'opf_formula_base_price', [ __CLASS__, 'formula_base_price' ], 10, 2 );
		add_filter( 'opf_pricing_hint_amount', [ __CLASS__, 'pricing_hint' ], 10, 4 );
		add_filter( 'opf/linked_products/choice', [ __CLASS__, 'linked_product_choice' ], 10, 3 );
		add_filter( 'woocommerce_available_variation', [ __CLASS__, 'variation_data' ], 10, 3 );
		add_action( 'wp_footer', [ __CLASS__, 'print_frontend_config' ], 100 );
	}

	/** Return a usable active WOOCS API, or leave other currency plugins alone. */
	private static function api() {
		global $WOOCS;
		return null !== self::frontend_config( $WOOCS ?? null ) ? $WOOCS : null;
	}

	private static function is_foreign_currency(): bool {
		$api = self::api();
		return null !== $api && 0 !== strcasecmp( (string) $api->current_currency, (string) $api->default_currency );
	}

	/** WAPF gates back-conversion on WOOCS's multiple-currency setting. */
	public static function back_convert( float $price ): float {
		$api = self::api();
		$config = self::frontend_config( $api );
		if ( null === $api || ! self::is_foreign_currency() || 1 !== (int) get_option( 'woocs_is_multiple_allowed' ) || ! is_callable( [ $api, 'back_convert' ] ) ) {
			return $price;
		}
		$value = $api->back_convert( $price, $config['currency_rate'], 8 );
		return is_numeric( $value ) && is_finite( (float) $value ) ? (float) $value : $price;
	}

	/** Recognize both fixed sale and regular prices, as WAPF does. */
	public static function has_fixed_price( \WC_Product $product ): bool {
		$api = self::api();
		if ( null === $api || 1 !== (int) get_option( 'woocs_is_fixed_enabled' ) ) {
			return false;
		}
		foreach ( [ 'regular', 'sale' ] as $kind ) {
			if ( (float) get_post_meta( $product->get_id(), '_woocs_' . $kind . '_price_' . $api->current_currency, true ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/** Fresh product prices prevent successive totals calculations compounding addons. */
	public static function cart_base_price( float $price, \WC_Product $product, array $cart_item = [] ): float {
		$config = self::frontend_config();
		if ( null === $config || ! self::is_foreign_currency() ) {
			return $price;
		}
		$fresh = wc_get_product( $product->get_id() );
		return $fresh instanceof \WC_Product ? self::back_convert( (float) $fresh->get_price() ) : $price;
	}

	/** Formula [price] uses the original product base even for fixed currency prices. */
	public static function formula_base_price( float $price, int $product_id ): float {
		$config = self::frontend_config();
		if ( null === $config || ! self::is_foreign_currency() ) {
			return $price;
		}
		$product = wc_get_product( $product_id );
		return $product instanceof \WC_Product ? self::original_product_price( $product ) : $price;
	}

	/** Linked-product adapters can obtain the original choice price without double conversion. */
	public static function original_product_price( \WC_Product $product ): float {
		$fresh = wc_get_product( $product->get_id() );
		$price = (float) ( $fresh instanceof \WC_Product ? $fresh : $product )->get_price( 'edit' );
		$args = [ 'qty' => 1, 'price' => $price ];
		return 'incl' === get_option( 'woocommerce_tax_display_shop' )
			? (float) wc_get_price_including_tax( $product, $args )
			: (float) wc_get_price_excluding_tax( $product, $args );
	}

	/**
	 * `wapf/linked_products/choice` parity — WAPF's `change_product_choice_price`
	 * restores the shop-currency child price because the storefront applies
	 * `currency_rate` itself and the child's own cart line converts exactly once.
	 * `expand_choice()` stores the WOOCS-converted view price, which would
	 * double-convert in the browser; a fresh `edit`-context read is the original
	 * price. OPF keeps the raw figure — the frontend tax factor, not shop-tax
	 * display, adds tax. Runs whenever the adapter is active, like WAPF.
	 */
	public static function linked_product_choice( array $choice, $field, $product ): array {
		if ( ! $product instanceof \WC_Product || null === self::api() ) {
			return $choice;
		}
		$fresh = wc_get_product( $product->get_id() );
		if ( $fresh instanceof \WC_Product ) {
			$choice['pricing_amount'] = (float) $fresh->get_price( 'edit' );
		}
		return $choice;
	}

	/** Provide both percentage and formula bases to the variation browser lifecycle. */
	public static function variation_data( array $data, \WC_Product $parent, \WC_Product $variation ): array {
		if ( null !== self::frontend_config() ) {
			$data['opf_base_price'] = self::cart_base_price( (float) $variation->get_price( 'edit' ), $variation );
			$data['opf_formula_base_price'] = self::formula_base_price( (float) $variation->get_price( 'edit' ), $variation->get_id() );
		}
		return $data;
	}

	/** Active settings must exist before the frontend module computes its first total. */
	public static function merge_frontend_config( array $config ): array {
		$currency = self::frontend_config();
		if ( null === $currency ) {
			return $config;
		}
		$config = array_replace_recursive( $config, $currency );
		global $product;
		if ( $product instanceof \WC_Product ) {
			$config['product_base_price'] = self::cart_base_price( (float) $product->get_price( 'edit' ), $product );
			// WAPF normalizes non-fixed simple previews even when cart conversion is disabled.
			if ( 1 !== (int) get_option( 'woocs_is_multiple_allowed' ) && self::is_foreign_currency()
				&& in_array( $product->get_type(), [ 'simple', 'subscription' ], true ) && ! self::has_fixed_price( $product ) ) {
				$config['product_base_price'] = self::original_product_price( $product );
			}
			$config['formula_base_price'] = self::formula_base_price( (float) $product->get_price( 'edit' ), $product->get_id() );
		}
		return $config;
	}

	/**
	 * `wapf/html/pricing_hint/amount` parity — hint amounts are stored in shop
	 * currency, so convert them for display at the current rate. Formula hints
	 * on the product page are dynamic ("…") and are left alone; in the cart the
	 * evaluated formula amount is already shop currency and must convert. WAPF's
	 * `Woocs::product_has_fixed_price()` guard is preserved through `instanceof`
	 * because the hook also passes product ids or null.
	 *
	 * @param float                $amount  Hint amount in shop currency.
	 * @param \WC_Product|int|null $product Product context (object, id or null).
	 * @param string               $type    Pricing type.
	 * @param string               $page    'product'/'shop' or 'cart'.
	 */
	public static function pricing_hint( float $amount, $product, string $type, string $page = 'product' ): float {
		$config = self::frontend_config();
		if ( null === $config || ( 'formula' === $type && 'cart' !== $page )
			|| ( $product instanceof \WC_Product && self::has_fixed_price( $product ) ) ) {
			return $amount;
		}
		return $amount * $config['currency_rate'];
	}

	/**
	 * Read only the WOOCS API used by WAPF's adapter and normalize its active
	 * currency settings for OPF's totals formatter.
	 *
	 * @param object|null $woocs WOOCS API object, injectable for tests.
	 * @return array<string,mixed>|null
	 */
	public static function frontend_config( $woocs = null ): ?array {
		if ( null === $woocs ) {
			global $WOOCS;
			$woocs = $WOOCS ?? null;
		}
		if ( ! is_object( $woocs ) || ! isset( $woocs->current_currency, $woocs->default_currency ) || ! is_callable( [ $woocs, 'get_currencies' ] ) ) {
			return null;
		}

		$currencies = $woocs->get_currencies();
		$current    = (string) $woocs->current_currency;
		if ( ! is_array( $currencies ) || ! isset( $currencies[ $current ] ) || ! is_array( $currencies[ $current ] ) ) {
			return null;
		}

		$info       = $currencies[ $current ];
		$is_default = 0 === strcasecmp( $current, (string) $woocs->default_currency );
		$rate       = isset( $info['rate'] ) && is_numeric( $info['rate'] ) && is_finite( (float) $info['rate'] ) && (float) $info['rate'] > 0
			? (float) $info['rate']
			: 1.0;
		$position   = (string) ( $info['position'] ?? 'left' );
		$format     = [
			'left'        => '%1$s%2$s',
			'right'       => '%2$s%1$s',
			'left_space'  => '%1$s&nbsp;%2$s',
			'right_space' => '%2$s&nbsp;%1$s',
		][$position] ?? '%1$s%2$s';

		$thousand = ',';
		$decimal  = '.';
		switch ( (string) ( $info['separators'] ?? '' ) ) {
			case '1':
				$thousand = '.';
				$decimal  = ',';
				break;
			case '2':
				$thousand = ' ';
				break;
			case '3':
				$thousand = ' ';
				$decimal  = ',';
				break;
			case '4':
				$thousand = '';
				break;
			case '5':
				$thousand = '';
				$decimal  = ',';
				break;
		}

		$decimals = isset( $info['decimals'] ) && is_numeric( $info['decimals'] ) ? max( 0, min( 8, (int) $info['decimals'] ) ) : 2;
		if ( ! empty( $info['hide_cents'] ) ) {
			$decimals = 0;
		}

		return [
			'currency_rate' => $is_default ? 1.0 : $rate,
			'display_options' => [
				'symbol'       => (string) ( $info['symbol'] ?? '' ),
				'thousand'     => $thousand,
				'decimal'      => $decimal,
				'decimals'     => $decimals,
				'format'        => $format,
			],
		];
	}

	/**
	 * Return the shop-currency price for the OPF preview when WOOCS has
	 * converted the public/view price. Other currency plugins retain the
	 * existing WooCommerce view-price behavior.
	 *
	 * @param \WC_Product $product Product being rendered.
	 */
	public static function preview_base_price( \WC_Product $product ): float {
		$config = self::frontend_config();
		if ( null !== $config && 1.0 !== $config['currency_rate'] ) {
			return (float) $product->get_price( 'edit' );
		}
		return (float) $product->get_price();
	}

	/** Emit a JSON-safe override after OPF's default display config. */
	public static function print_frontend_config(): void {
		$config = self::frontend_config();
		if ( null === $config || ! function_exists( 'wp_json_encode' ) ) {
			return;
		}
		$json = wp_json_encode( self::merge_frontend_config( [] ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $json ) ) {
			return;
		}
		echo '<script>window.opf_config=Object.assign(window.opf_config||{},' . $json . ');</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is hex-escaped.
	}
}
