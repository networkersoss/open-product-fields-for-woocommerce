<?php
namespace OPF\Tests\Unit;

use OPF\Service\SubscriptionIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * WAPF-PRODUCT-SUBSCRIPTION contract: `WooCommerce_Subscriptions` adapter —
 * recurring base on `wapf/pricing/cart_item_base` for subscription,
 * variable-subscription and subscription_variation types, plus
 * `wcs_before/after_*_renewal_*` actions gating `wapf/skip_cart_validation`
 * during renewal cart rebuilds.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SubscriptionIntegrationTest extends TestCase {
	protected function setUp(): void {
		require dirname( __DIR__ ) . '/fixtures/subscription-contract.php';
		$GLOBALS['opf_subs_hooks']     = [];
		$GLOBALS['opf_subs_recurring'] = [];
		unset(
			$GLOBALS['opf_subs_recurring_default'],
			$GLOBALS['opf_subs_recurring_result']
		);
	}

	private function enable_subscriptions_api(): void {
		require dirname( __DIR__ ) . '/fixtures/subscription-api.php';
	}

	public function test_boot_registers_recurring_base_and_renewal_bridges(): void {
		SubscriptionIntegration::init();

		$this->assertSame( 10, key( $GLOBALS['opf_subs_hooks']['opf_cart_item_base_price'] ) );
		$this->assertSame( [ [ SubscriptionIntegration::class, 'cart_base_price' ], 3 ], $GLOBALS['opf_subs_hooks']['opf_cart_item_base_price'][10][0] );
		$this->assertSame( [ [ SubscriptionIntegration::class, 'skip_validation' ], 1 ], $GLOBALS['opf_subs_hooks']['opf_skip_validation'][10][0] );

		// WAPF hooks wcs_before/after_early_renewal_setup_cart_subscription and
		// wcs_before/after_renewal_setup_cart_subscriptions — OPF parity set.
		foreach ( [ 'early_renewal_setup_cart_subscription', 'renewal_setup_cart_subscriptions' ] as $setup ) {
			$this->assertArrayHasKey( 'wcs_before_' . $setup, $GLOBALS['opf_subs_hooks'] );
			$this->assertArrayHasKey( 'wcs_after_' . $setup, $GLOBALS['opf_subs_hooks'] );
		}
	}

	public function test_renewal_setup_skips_validation_until_every_setup_ends(): void {
		SubscriptionIntegration::init();

		$this->assertFalse( SubscriptionIntegration::skip_validation( false ), 'Normal carts still validate.' );

		// Nested begin/end proves the depth counter, not a boolean flag.
		SubscriptionIntegration::begin_renewal();
		SubscriptionIntegration::begin_renewal();
		$this->assertTrue( SubscriptionIntegration::skip_validation( false ) );
		SubscriptionIntegration::end_renewal();
		$this->assertTrue( SubscriptionIntegration::skip_validation( false ), 'An inner renewal ending must not re-enable validation.' );
		SubscriptionIntegration::end_renewal();
		$this->assertFalse( SubscriptionIntegration::skip_validation( false ) );

		// Extra end_renewal calls cannot push the counter below zero.
		SubscriptionIntegration::end_renewal();
		SubscriptionIntegration::end_renewal();
		$this->assertFalse( SubscriptionIntegration::skip_validation( false ) );
	}

	public function test_renewal_actions_drive_skip_validation_through_hooks(): void {
		SubscriptionIntegration::init();

		$this->assertFalse( (bool) apply_filters( 'opf_skip_validation', false ) );
		do_action( 'wcs_before_renewal_setup_cart_subscriptions' );
		$this->assertTrue( (bool) apply_filters( 'opf_skip_validation', false ), 'Renewal cart rebuilds skip OPF validation like WAPF.' );
		do_action( 'wcs_after_renewal_setup_cart_subscriptions' );
		$this->assertFalse( (bool) apply_filters( 'opf_skip_validation', false ) );

		do_action( 'wcs_before_early_renewal_setup_cart_subscription' );
		$this->assertTrue( (bool) apply_filters( 'opf_skip_validation', false ) );
		do_action( 'wcs_after_early_renewal_setup_cart_subscription' );

		// An existing exemption from another listener is preserved.
		$this->assertTrue( SubscriptionIntegration::skip_validation( true ) );
	}

	public function test_subscription_types_use_recurring_price(): void {
		$this->enable_subscriptions_api();
		$GLOBALS['opf_subs_recurring'] = [ 11 => 9.0 ];

		foreach ( [ 'subscription', 'variable-subscription', 'subscription_variation' ] as $type ) {
			$product = new \WC_Product( 11, 20.0, $type );
			$this->assertSame( 9.0, SubscriptionIntegration::cart_base_price( 20.0, $product, [] ), $type );
		}
		$this->assertSame( [ 11, 11, 11 ], \WC_Subscriptions_Product::$calls, 'Every subscription type reads the recurring price.' );
	}

	public function test_non_subscription_types_and_absent_api_are_untouched(): void {
		$this->enable_subscriptions_api();
		$this->assertSame( 20.0, SubscriptionIntegration::cart_base_price( 20.0, new \WC_Product( 12, 20.0, 'simple' ), [] ) );
		$this->assertSame( 20.0, SubscriptionIntegration::cart_base_price( 20.0, new \WC_Product( 12, 20.0, 'variation' ), [] ) );
		$this->assertSame( [], \WC_Subscriptions_Product::$calls, 'Non-subscription products never reach the WCS API.' );

		// Defensive: a non-numeric/invalid API result cannot poison the base.
		$GLOBALS['opf_subs_recurring_result'] = 'not-a-price';
		$this->assertSame( 20.0, SubscriptionIntegration::cart_base_price( 20.0, new \WC_Product( 11, 20.0, 'subscription' ), [] ) );
		$GLOBALS['opf_subs_recurring_result'] = INF;
		$this->assertSame( 20.0, SubscriptionIntegration::cart_base_price( 20.0, new \WC_Product( 11, 20.0, 'subscription' ), [] ) );
	}

	public function test_absent_subscriptions_plugin_fails_closed(): void {
		$this->assertFalse( class_exists( 'WC_Subscriptions_Product', false ), 'Fixture must not predefine the WCS API.' );
		SubscriptionIntegration::init();
		$this->assertSame( 20.0, SubscriptionIntegration::cart_base_price( 20.0, new \WC_Product( 11, 20.0, 'subscription' ), [] ) );
		$this->assertFalse( SubscriptionIntegration::skip_validation( false ) );
	}
}
