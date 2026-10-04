<?php
/** Real plugin boot regression. Run with wp eval-file on a disposable clone. */

if ( '1' !== getenv( 'OPF_BOOT_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-' ) ) {
	throw new RuntimeException( 'Explicit disposable clone authorization required.' );
}
// WordPress has already executed plugins_loaded, so this file cannot run at
// all when opf_boot() fatals. Check actual hooks rather than just class files.
$checks = [
	'WooCommerce booted' => class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ),
	'OPF booted once' => 1 === did_action( 'plugins_loaded' ),
	'subscription adapter autoloaded' => class_exists( OPF\Service\SubscriptionIntegration::class ),
	'recurring base hook registered' => 10 === has_filter( 'opf_cart_item_base_price', [ OPF\Service\SubscriptionIntegration::class, 'cart_base_price' ] ),
	'renewal validation hook registered' => 10 === has_filter( 'opf_skip_validation', [ OPF\Service\SubscriptionIntegration::class, 'skip_validation' ] ),
	'normal validation remains enabled' => false === apply_filters( 'opf_skip_validation', false ),
	'existing validation exemption preserved' => true === apply_filters( 'opf_skip_validation', true ),
];
$p = new WC_Product_Simple();
$checks['simple product base remains unchanged'] = 12.5 === apply_filters( 'opf_cart_item_base_price', 12.5, $p, [] );
if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
	$subscription = new class extends WC_Product_Simple {
		public function get_type() { return 'subscription'; }
	};
	$checks['absent subscription API preserves supplied base'] = 13.5 === apply_filters( 'opf_cart_item_base_price', 13.5, $subscription, [] );
}
do_action( 'wcs_before_early_renewal_setup_cart_subscription', null );
$checks['early renewal skips fieldless validation'] = true === apply_filters( 'opf_skip_validation', false );
do_action( 'wcs_before_renewal_setup_cart_subscriptions', null, null );
do_action( 'wcs_after_renewal_setup_cart_subscriptions', null, null );
$checks['nested renewal keeps outer exemption'] = true === apply_filters( 'opf_skip_validation', false );
do_action( 'wcs_after_early_renewal_setup_cart_subscription', null );
$checks['renewal teardown restores validation'] = false === apply_filters( 'opf_skip_validation', false );
do_action( 'wcs_after_early_renewal_setup_cart_subscription', null );
$checks['unmatched teardown cannot latch skip'] = false === apply_filters( 'opf_skip_validation', false );
foreach ( $checks as $name => $passed ) {
	if ( ! $passed ) { throw new RuntimeException( $name ); }
}
echo wp_json_encode( [ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'licensed_subscriptions_loaded' => class_exists( 'WC_Subscriptions_Product' ), 'checks' => $checks ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
