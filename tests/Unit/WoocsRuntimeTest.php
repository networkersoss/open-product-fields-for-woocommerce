<?php
namespace {
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function get_id(): int { return 42; }
			public function get_parent_id(): int { return 0; }
		}
	}
}

namespace OPF\Service {
	function wc_get_product( int $id ) { return $GLOBALS['opf_woocs_products'][ $id ] ?? false; }
	function get_post_meta( int $id, string $key, bool $single ) {
		// ImporterTest fixtures expose their source rows through the
		// opf_importer_test registry; everything else uses woocs_meta.
		return $GLOBALS['opf_importer_test']['source_meta'][ $id ][ $key ]
			?? $GLOBALS['opf_woocs_meta'][ $id ][ $key ]
			?? '';
	}
	function wc_get_price_including_tax( $product, array $args ): float { return $args['price'] * 1.2; }
	function wc_get_price_excluding_tax( $product, array $args ): float { return $args['price']; }
	function add_filter( $name, $callback, $priority = 10, $accepted = 1 ): void { $GLOBALS['opf_woocs_hooks'][ $name ] = [ $callback, $priority, $accepted ]; }
	function add_action( $name, $callback, $priority = 10, $accepted = 1 ): void { add_filter( $name, $callback, $priority, $accepted ); }
}

namespace OPF\Tests\Unit {
	use OPF\Service\WoocsIntegration;
	use PHPUnit\Framework\TestCase;

	final class WoocsRuntimeProduct extends \WC_Product {
		private int $id;
		private float $original;
		private float $view;
		private string $type;
		public function __construct( int $id, float $original, float $view, string $type = 'simple' ) { $this->id = $id; $this->original = $original; $this->view = $view; $this->type = $type; }
		public function get_id(): int { return $this->id; }
		public function get_type(): string { return $this->type; }
		public function get_price( string $context = 'view' ): float { return 'edit' === $context ? $this->original : $this->view; }
	}

	final class RuntimeWoocsStub {
		public string $current_currency = 'EUR';
		public string $default_currency = 'USD';
		public array $calls = [];
		public function get_currencies(): array { return [ 'EUR' => [ 'rate' => 2, 'symbol' => '€', 'position' => 'right_space', 'decimals' => 2, 'separators' => '1' ], 'USD' => [ 'rate' => 1, 'symbol' => '$' ] ]; }
		public function back_convert( float $price, float $rate, int $precision ): float { $this->calls[] = [ $price, $rate, $precision ]; return round( $price / $rate, $precision ); }
	}

	final class WoocsRuntimeTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['WOOCS'] = new RuntimeWoocsStub();
			$GLOBALS['opf_test_options'] = [ 'woocs_is_multiple_allowed' => 1, 'woocs_is_fixed_enabled' => 1, 'woocommerce_tax_display_shop' => 'excl' ];
			$GLOBALS['opf_woocs_products'] = [ 42 => new WoocsRuntimeProduct( 42, 10, 20 ) ];
			$GLOBALS['opf_woocs_meta'] = [];
			$GLOBALS['opf_woocs_hooks'] = [];
		}
		protected function tearDown(): void {
			unset( $GLOBALS['WOOCS'], $GLOBALS['product'], $GLOBALS['opf_woocs_products'], $GLOBALS['opf_woocs_meta'], $GLOBALS['opf_woocs_hooks'] );
			$GLOBALS['opf_test_options'] = [];
		}

		public function test_boot_registers_cart_formula_variation_and_frontend_bridges(): void {
			WoocsIntegration::init();
			$this->assertSame( [ 20, 3 ], array_slice( $GLOBALS['opf_woocs_hooks']['opf_cart_item_base_price'], 1 ) );
			$this->assertSame( [ 10, 2 ], array_slice( $GLOBALS['opf_woocs_hooks']['opf_formula_base_price'], 1 ) );
			$this->assertSame( [ 10, 4 ], array_slice( $GLOBALS['opf_woocs_hooks']['opf_pricing_hint_amount'], 1 ) );
			$this->assertSame( [ 10, 3 ], array_slice( $GLOBALS['opf_woocs_hooks']['opf/linked_products/choice'], 1 ) );
			$this->assertSame( [ 10, 3 ], array_slice( $GLOBALS['opf_woocs_hooks']['woocommerce_available_variation'], 1 ) );
			$this->assertArrayHasKey( 'opf_frontend_config', $GLOBALS['opf_woocs_hooks'] );
		}

		public function test_cart_base_back_converts_fresh_price_without_compounding_prior_addons(): void {
			$mutated = new WoocsRuntimeProduct( 42, 17, 34 );
			$this->assertSame( 10.0, WoocsIntegration::cart_base_price( 17, $mutated ) );
			$this->assertSame( 10.0, WoocsIntegration::cart_base_price( 17, $mutated ) );
			$this->assertSame( [ [ 20.0, 2.0, 8 ], [ 20.0, 2.0, 8 ] ], $GLOBALS['WOOCS']->calls );
		}

		public function test_back_conversion_matches_default_and_multiple_currency_gates(): void {
			$GLOBALS['opf_test_options']['woocs_is_multiple_allowed'] = 0;
			$this->assertSame( 20.0, WoocsIntegration::back_convert( 20 ) );
			$GLOBALS['opf_test_options']['woocs_is_multiple_allowed'] = 1;
			$GLOBALS['WOOCS']->current_currency = 'USD';
			$this->assertSame( 20.0, WoocsIntegration::back_convert( 20 ) );
			$this->assertSame( [], $GLOBALS['WOOCS']->calls );
		}

		public function test_foreign_simple_preview_normalizes_when_multiple_currency_is_disabled(): void {
			$GLOBALS['opf_test_options']['woocs_is_multiple_allowed'] = 0;
			$GLOBALS['product'] = $GLOBALS['opf_woocs_products'][42];
			$config = WoocsIntegration::merge_frontend_config( [] );
			$this->assertSame( 10.0, $config['product_base_price'] );
			$this->assertSame( 10.0, $config['formula_base_price'] );
			$this->assertSame( 2.0, $config['currency_rate'] );
			// Cart back-conversion keeps its independent multiple-currency gate.
			$this->assertSame( 20.0, WoocsIntegration::cart_base_price( 10, $GLOBALS['product'] ) );
			$this->assertSame( [], $GLOBALS['WOOCS']->calls );
		}

		public function test_disabled_multiple_currency_preview_uses_original_shop_tax_price(): void {
			$GLOBALS['opf_test_options']['woocs_is_multiple_allowed'] = 0;
			$GLOBALS['opf_test_options']['woocommerce_tax_display_shop'] = 'incl';
			$GLOBALS['product'] = $GLOBALS['opf_woocs_products'][42];
			$config = WoocsIntegration::merge_frontend_config( [] );
			$this->assertSame( 12.0, $config['product_base_price'] );
			$this->assertSame( 12.0, $config['formula_base_price'] );
		}

		public function test_subscription_preview_matches_simple_without_changing_variable_base(): void {
			$GLOBALS['opf_test_options']['woocs_is_multiple_allowed'] = 0;
			foreach ( [ 'subscription' => 10.0, 'variable' => 20.0 ] as $type => $expected ) {
				$GLOBALS['product'] = new WoocsRuntimeProduct( 42, 10, 20, $type );
				$this->assertSame( $expected, WoocsIntegration::merge_frontend_config( [] )['product_base_price'] );
			}
		}

		public function test_fixed_foreign_preview_and_default_currency_preserve_existing_bases(): void {
			$GLOBALS['product'] = new WoocsRuntimeProduct( 42, 10, 50 );
			$GLOBALS['opf_woocs_products'][42] = $GLOBALS['product'];
			foreach ( [ 'regular', 'sale' ] as $kind ) {
				$GLOBALS['opf_woocs_meta'][42] = [ '_woocs_' . $kind . '_price_EUR' => 50 ];
				foreach ( [ 0 => 50.0, 1 => 25.0 ] as $multiple => $expected ) {
					$GLOBALS['opf_test_options']['woocs_is_multiple_allowed'] = $multiple;
					$config = WoocsIntegration::merge_frontend_config( [] );
					$this->assertSame( $expected, $config['product_base_price'] );
					$this->assertSame( 10.0, $config['formula_base_price'] );
				}
			}
			$GLOBALS['WOOCS']->current_currency = 'USD';
			$GLOBALS['opf_test_options']['woocs_is_multiple_allowed'] = 0;
			$config = WoocsIntegration::merge_frontend_config( [] );
			$this->assertSame( 10.0, $config['product_base_price'] );
			$this->assertSame( 1.0, $config['currency_rate'] );
		}

		public function test_fixed_currency_percentage_base_and_formula_base_are_distinct(): void {
			$product = new WoocsRuntimeProduct( 42, 10, 50 );
			$GLOBALS['opf_woocs_products'][42] = $product;
			$GLOBALS['opf_woocs_meta'][42]['_woocs_sale_price_EUR'] = 50;
			$this->assertTrue( WoocsIntegration::has_fixed_price( $product ) );
			$this->assertSame( 25.0, WoocsIntegration::cart_base_price( 10, $product ) );
			$this->assertSame( 10.0, WoocsIntegration::formula_base_price( 25, 42 ) );
			$GLOBALS['opf_test_options']['woocs_is_fixed_enabled'] = 0;
			$this->assertFalse( WoocsIntegration::has_fixed_price( $product ) );
		}

		public function test_formula_and_linked_product_base_use_original_shop_tax_price(): void {
			$GLOBALS['opf_test_options']['woocommerce_tax_display_shop'] = 'incl';
			$this->assertSame( 12.0, WoocsIntegration::formula_base_price( 20, 42 ) );
			$this->assertSame( 12.0, WoocsIntegration::original_product_price( $GLOBALS['opf_woocs_products'][42] ) );
		}

		public function test_variation_contract_and_first_browser_config_include_both_bases(): void {
			$product = $GLOBALS['opf_woocs_products'][42];
			$variation = new WoocsRuntimeProduct( 43, 15, 30 );
			$GLOBALS['opf_woocs_products'][43] = $variation;
			$this->assertSame( [ 'display_price' => 30, 'opf_base_price' => 15.0, 'opf_formula_base_price' => 15.0 ], WoocsIntegration::variation_data( [ 'display_price' => 30 ], $product, $variation ) );
			$GLOBALS['product'] = $product;
			$config = WoocsIntegration::merge_frontend_config( [ 'ajax' => 'endpoint', 'display_options' => [ 'price_format' => 'symbolprice' ] ] );
			$this->assertSame( 10.0, $config['product_base_price'] );
			$this->assertSame( 10.0, $config['formula_base_price'] );
			$this->assertSame( 2.0, $config['currency_rate'] );
			$this->assertSame( 'endpoint', $config['ajax'] );
			$this->assertSame( 'symbolprice', $config['display_options']['price_format'] );
		}

		public function test_hint_conversion_preserves_formula_preview_and_fixed_price_gates(): void {
			$product = $GLOBALS['opf_woocs_products'][42];
			$this->assertSame( 6.0, WoocsIntegration::pricing_hint( 3, $product, 'fixed' ) );
			$this->assertSame( 3.0, WoocsIntegration::pricing_hint( 3, $product, 'formula' ) );
			$this->assertSame( 6.0, WoocsIntegration::pricing_hint( 3, $product, 'formula', 'cart' ) );
			$GLOBALS['opf_woocs_meta'][42]['_woocs_regular_price_EUR'] = 50;
			$this->assertSame( 3.0, WoocsIntegration::pricing_hint( 3, $product, 'fixed' ) );
		}

		public function test_pricing_hint_accepts_id_and_null_contexts(): void {
			// `opf_pricing_hint_amount` passes whatever the renderer has: a
			// WC_Product, an id or null. Non-product contexts cannot run the
			// fixed-price meta check, so they convert at the current rate.
			$this->assertSame( 6.0, WoocsIntegration::pricing_hint( 3, 42, 'fixed' ) );
			$this->assertSame( 6.0, WoocsIntegration::pricing_hint( 3, null, 'fixed' ) );
			$this->assertSame( 3.0, WoocsIntegration::pricing_hint( 3, null, 'formula' ) );
		}

		public function test_linked_product_choice_restores_shop_price_under_conversion(): void {
			$child  = $GLOBALS['opf_woocs_products'][42]; // edit 10, view 20 (converted).
			$choice = [ 'slug' => 'p42', 'pricing_type' => 'fixed', 'pricing_amount' => 20.0 ];
			$out    = WoocsIntegration::linked_product_choice( $choice, [ 'id' => 'f' ], $child );
			$this->assertSame( 10.0, $out['pricing_amount'], 'WAPF change_product_choice_price parity: shop price, not the converted view.' );

			// 'none' children are free but still carry the original figure like WAPF.
			$free = WoocsIntegration::linked_product_choice( [ 'pricing_type' => 'none', 'pricing_amount' => 0.0 ], [], $child );
			$this->assertSame( 10.0, $free['pricing_amount'] );

			// Non-product context and absent WOOCS leave the choice untouched.
			$this->assertSame( $choice, WoocsIntegration::linked_product_choice( $choice, [], null ) );
			unset( $GLOBALS['WOOCS'] );
			$this->assertSame( $choice, WoocsIntegration::linked_product_choice( $choice, [], $child ) );
		}

		public function test_absent_woocs_preserves_cart_formula_variation_and_config(): void {
			unset( $GLOBALS['WOOCS'] );
			$product = $GLOBALS['opf_woocs_products'][42];
			$this->assertSame( 17.0, WoocsIntegration::cart_base_price( 17, $product ) );
			$this->assertSame( 17.0, WoocsIntegration::formula_base_price( 17, 42 ) );
			$this->assertSame( [ 'display_price' => 20 ], WoocsIntegration::variation_data( [ 'display_price' => 20 ], $product, $product ) );
			$this->assertSame( [ 'ajax' => 'endpoint' ], WoocsIntegration::merge_frontend_config( [ 'ajax' => 'endpoint' ] ) );
		}
	}
}
