<?php
/**
 * Recurring-price and renewal-validation bridge for WooCommerce Subscriptions.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class SubscriptionIntegration {

	/** Nesting keeps normal validation disabled until all renewal setups finish. */
	private static $renewal_depth = 0;

	public static function init(): void {
		add_filter( 'opf_cart_item_base_price', [ __CLASS__, 'cart_base_price' ], 10, 3 );
		add_filter( 'opf_skip_validation', [ __CLASS__, 'skip_validation' ] );
		foreach ( [ 'early_renewal_setup_cart_subscription', 'renewal_setup_cart_subscriptions' ] as $setup ) {
			add_action( 'wcs_before_' . $setup, [ __CLASS__, 'begin_renewal' ] );
			add_action( 'wcs_after_' . $setup, [ __CLASS__, 'end_renewal' ] );
		}
	}

	/** Renewals restore previously validated order fields without new POST data. */
	public static function begin_renewal(): void {
		++self::$renewal_depth;
	}

	public static function end_renewal(): void {
		self::$renewal_depth = max( 0, self::$renewal_depth - 1 );
	}

	/** Preserve other integrations' validation exemptions. */
	public static function skip_validation( $skip ): bool {
		return (bool) $skip || self::$renewal_depth > 0;
	}

	/** Consume the recurring base before later currency adapters normalize it. */
	public static function cart_base_price( float $price, \WC_Product $product, array $cart_item = [] ): float {
		if ( ! in_array( $product->get_type(), [ 'subscription', 'variable-subscription', 'subscription_variation' ], true )
			|| ! is_callable( [ '\\WC_Subscriptions_Product', 'get_price' ] ) ) {
			return $price;
		}
		$recurring = \WC_Subscriptions_Product::get_price( $product );
		return is_numeric( $recurring ) && is_finite( (float) $recurring ) ? (float) $recurring : $price;
	}
}
