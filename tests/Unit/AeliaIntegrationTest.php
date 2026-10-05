<?php
namespace OPF\Tests\Unit;

use OPF\Service\AeliaIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AeliaIntegrationTest extends TestCase {
	protected function setUp(): void {
		require dirname( __DIR__ ) . '/fixtures/aelia-contract.php';
		$GLOBALS['woocommerce-aelia-currencyswitcher'] = new \stdClass();
		$GLOBALS['opf_test_options'] = [ 'woocommerce_currency' => 'USD', 'woocommerce_tax_display_shop' => 'excl' ];
		$GLOBALS['aelia_rate'] = 2.0;
		$GLOBALS['aelia_currency'] = 'EUR';
		$GLOBALS['aelia_products'] = [ 42 => new \WC_Product( 42, 10, 20 ) ];
		$GLOBALS['aelia_hooks'] = [];
		$GLOBALS['aelia_conversions'] = [];
	}

	public function test_hooks_run_currency_conversion_after_opf_calculation(): void {
		AeliaIntegration::init();
		$this->assertArrayHasKey( 21, $GLOBALS['aelia_hooks']['woocommerce_before_calculate_totals'] );
		$this->assertArrayHasKey( 5, $GLOBALS['aelia_hooks']['wp_footer'] );
		$this->assertSame( 3, $GLOBALS['aelia_hooks']['opf_cart_item_base_price'][30][0][1] );
		$this->assertSame( 2, $GLOBALS['aelia_hooks']['opf_formula_base_price'][20][0][1] );
		$this->assertSame( 4, $GLOBALS['aelia_hooks']['opf_pricing_hint_amount'][10][0][1] );
		$this->assertSame( 3, $GLOBALS['aelia_hooks']['opf/linked_products/choice'][10][0][1] );
		$this->assertSame( 3, $GLOBALS['aelia_hooks']['woocommerce_available_variation'][20][0][1] );
	}

	public function test_original_formula_base_differs_from_fixed_foreign_percentage_base(): void {
		$product = new \WC_Product( 42, 10, 50 );
		$GLOBALS['aelia_products'][42] = $product;
		// WAPF parity (real-plugin verified): the cart base is the CONVERTED
		// view price, so percent addons compute on it like WAPF's.
		$this->assertSame( 50.0, AeliaIntegration::cart_base_price( 99, $product ) );
		$this->assertSame( 10.0, AeliaIntegration::formula_base_price( 25, 42 ) );
		$GLOBALS['opf_test_options']['woocommerce_tax_display_shop'] = 'incl';
		$this->assertSame( 12.0, AeliaIntegration::original_product_price( $product ) );
		$data = AeliaIntegration::variation_data( [ 'display_price' => 60 ], $product, $product );
		$this->assertSame( 60.0, $data['opf_base_price'] );
		$this->assertSame( 12.0, $data['opf_formula_base_price'] );
	}

	public function test_cart_conversion_ignores_plain_lines_and_uses_documented_filter(): void {
		// Real-plugin-verified WAPF shape: USD-10 product, EUR rate 2, cart
		// pipeline produced converted base 20 + shop-priced options 15 (=35 in
		// 'edit'); conversion yields WAPF's 20 + 15×2 = 50.
		$product = new \WC_Product( 42, 10, 20 );
		$product->set_price( '35' );
		$plain = new \WC_Product( 43, 7, 7 );
		$cart = new \WC_Cart();
		$cart->items = [ [ 'data' => $product, 'opf_base_price' => 10, 'opf_fields' => [ 'g' => [ 'f' => 'yes' ] ] ], [ 'data' => $plain ] ];
		AeliaIntegration::convert_cart_prices( $cart );
		$this->assertSame( 50.0, $product->get_price( 'edit' ) );
		$this->assertSame( 7.0, $plain->get_price() );
		$this->assertSame( [ [ 15.0, 'USD', 'EUR' ] ], $GLOBALS['aelia_conversions'] );
		// The OPF priority-20 callback resets the shop target on the next pass.
		$product->set_price( '35' );
		AeliaIntegration::convert_cart_prices( $cart );
		$this->assertSame( 50.0, $product->get_price( 'edit' ) );
	}

	public function test_config_keeps_existing_values_and_exposes_current_formatting(): void {
		$GLOBALS['product'] = $GLOBALS['aelia_products'][42];
		$config = AeliaIntegration::merge_frontend_config( [ 'ajax' => 'endpoint', 'display_options' => [ 'price_format' => 'symbolprice' ] ] );
		$this->assertSame( 20.0, $config['product_base_price'] );
		$this->assertSame( 10.0, $config['formula_base_price'] );
		$this->assertSame( 2.0, $config['currency_rate'] );
		$this->assertSame( 'EUR', $config['currency'] );
		$this->assertSame( '%2$s&nbsp;%1$s', $config['display_options']['format'] );
		$this->assertSame( 'endpoint', $config['ajax'] );
		$this->assertSame( 'symbolprice', $config['display_options']['price_format'] );
		ob_start();
		AeliaIntegration::print_frontend_config();
		$this->assertStringContainsString( 'window.opf_config=Object.assign', ob_get_clean() );
	}

	public function test_rate_changes_and_small_rates_are_not_cached_or_rounded(): void {
		$this->assertSame( 2.0, AeliaIntegration::currency_info()['rate'] );
		$GLOBALS['aelia_rate'] = 0.0012345;
		$this->assertSame( 0.0012345, AeliaIntegration::currency_info()['rate'] );
		$this->assertEqualsWithDelta( 0.12345, AeliaIntegration::convert_amount( 100 ), 0.000000001 );
		$GLOBALS['aelia_currency'] = 'USD';
		$this->assertSame( 1.0, AeliaIntegration::currency_info()['rate'] );
		$this->assertSame( 100.0, AeliaIntegration::convert_amount( 100 ) );
	}

	public function test_invalid_api_result_cannot_poison_cart_or_browser(): void {
		$GLOBALS['aelia_bad_result'] = INF;
		$this->assertSame( 100.0, AeliaIntegration::convert_amount( 100 ) );
		$GLOBALS['aelia_rate'] = 0;
		$this->assertNull( AeliaIntegration::frontend_config() );
		$this->assertSame( 99.0, AeliaIntegration::cart_base_price( 99, $GLOBALS['aelia_products'][42] ) );
	}

	public function test_linked_product_choice_restores_shop_price_for_browser_rate(): void {
		$child  = $GLOBALS['aelia_products'][42]; // edit 10, view 20 (converted).
		$choice = [ 'slug' => 'p42', 'pricing_type' => 'fixed', 'pricing_amount' => 20.0 ];
		$out    = AeliaIntegration::linked_product_choice( $choice, [ 'id' => 'f' ], $child );
		$this->assertSame( 10.0, $out['pricing_amount'], 'WAPF change_product_choice_price parity: shop price, not the converted view.' );

		// Non-product context and absent Aelia leave the choice untouched.
		$this->assertSame( $choice, AeliaIntegration::linked_product_choice( $choice, [], null ) );
		unset( $GLOBALS['woocommerce-aelia-currencyswitcher'] );
		$this->assertSame( $choice, AeliaIntegration::linked_product_choice( $choice, [], $child ) );
	}

	public function test_hints_and_absent_plugin_preserve_contract(): void {
		// pricing_hint follows the opf_pricing_hint_amount WAPF shape
		// ($amount, $product, $type, $page); Aelia ignores the product arg.
		$this->assertSame( 6.0, AeliaIntegration::pricing_hint( 3, null, 'fixed' ) );
		$this->assertSame( 3.0, AeliaIntegration::pricing_hint( 3, null, 'formula' ) );
		$this->assertSame( 6.0, AeliaIntegration::pricing_hint( 3, null, 'formula', 'cart' ) );
		unset( $GLOBALS['woocommerce-aelia-currencyswitcher'] );
		$this->assertNull( AeliaIntegration::frontend_config() );
		$this->assertSame( 3.0, AeliaIntegration::convert_amount( 3 ) );
		$this->assertSame( 99.0, AeliaIntegration::formula_base_price( 99, 42 ) );
		$this->assertSame( [ 'display_price' => 20 ], AeliaIntegration::variation_data( [ 'display_price' => 20 ], $GLOBALS['aelia_products'][42], $GLOBALS['aelia_products'][42] ) );
		$this->assertSame( [ 'ajax' => 'endpoint' ], AeliaIntegration::merge_frontend_config( [ 'ajax' => 'endpoint' ] ) );
	}
}
