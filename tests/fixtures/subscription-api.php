<?php
/**
 * WooCommerce Subscriptions' recurring-price API surface. Loaded on demand by
 * SubscriptionIntegrationTest so the absent-plugin path stays fail-closed.
 *
 * `opf_subs_recurring` maps product id => recurring price; unknown ids fall
 * back to `opf_subs_recurring_default`; set `opf_subs_recurring_result` to a
 * non-numeric value to exercise the defensive guard.
 */

if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
	class WC_Subscriptions_Product {
		public static array $calls = [];
		public static function get_price( $product ) {
			self::$calls[] = $product->get_id();
			if ( isset( $GLOBALS['opf_subs_recurring_result'] ) ) {
				return $GLOBALS['opf_subs_recurring_result'];
			}
			$prices = $GLOBALS['opf_subs_recurring'] ?? [];
			return $prices[ $product->get_id() ] ?? $GLOBALS['opf_subs_recurring_default'] ?? 7;
		}
	}
}
