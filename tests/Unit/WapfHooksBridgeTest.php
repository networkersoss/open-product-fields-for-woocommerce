<?php
/**
 * WAPF `wapf/*` alias bridge — lifecycle proof.
 *
 * Runs in its own process with a real (tiny) WordPress hook registry so the
 * bridge's `add_filter()` shims and unwrapped dispatches are exercised exactly
 * as they would be in production: a `wapf/…` listener is registered, an OPF
 * lifecycle runs, and the listener must fire with the WAPF argument shape.
 */

namespace {
	if ( ! class_exists( 'WP_Hook' ) ) {
		class WP_Hook {
			public array $callbacks = [];
		}
	}

	function opf_test_hook_key( $callback ): string {
		if ( is_array( $callback ) ) {
			$owner = is_object( $callback[0] ) ? spl_object_hash( $callback[0] ) : (string) $callback[0];
			return $owner . '::' . $callback[1];
		}
		return is_object( $callback ) ? spl_object_hash( $callback ) : (string) $callback;
	}

	if ( ! function_exists( 'wapfe_validate_cart_data' ) ) {
		/** WAPF Extended 3.1.5 date validator contract; its field argument is an object. */
		function wapfe_validate_cart_data( $error, $value, $field, $product_id, $clone_index, $qty, $is_order_again, $cart_item_data ) {
			$GLOBALS['opf_wapfe_date_validator_calls'] = ( $GLOBALS['opf_wapfe_date_validator_calls'] ?? 0 ) + 1;
			$field->type;
			return $error;
		}
	}

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	/* ---------------------------------------------------------- hook harness */

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $tag, $callback, $priority = 10, $accepted = 1 ) {
			$priority = (int) $priority;
			$key = opf_test_hook_key( $callback );
			$GLOBALS['wp_hooks'][ $tag ][ $priority ][ $key ] = [ 'cb' => $callback, 'accepted' => (int) $accepted ];
			ksort( $GLOBALS['wp_hooks'][ $tag ] );
			$GLOBALS['wp_filter'][ $tag ] ??= new WP_Hook();
			$GLOBALS['wp_filter'][ $tag ]->callbacks[ $priority ][ $key ] = [ 'function' => $callback, 'accepted_args' => (int) $accepted ];
			return true;
		}
		function add_action( $tag, $callback, $priority = 10, $accepted = 1 ) {
			return add_filter( $tag, $callback, $priority, $accepted );
		}
		function remove_action( $tag, $callback, $priority = 10 ) {
			return remove_filter( $tag, $callback, $priority );
		}
		function apply_filters( $tag, $value, ...$args ) {
			foreach ( $GLOBALS['wp_hooks'][ $tag ] ?? [] as $callbacks ) {
				foreach ( $callbacks as $hook ) {
					$pass  = array_slice( $args, 0, max( 0, $hook['accepted'] - 1 ) );
					$value = call_user_func( $hook['cb'], $value, ...$pass );
				}
			}
			return $value;
		}
		function do_action( $tag, ...$args ) {
			foreach ( $GLOBALS['wp_hooks'][ $tag ] ?? [] as $callbacks ) {
				foreach ( $callbacks as $hook ) {
					call_user_func( $hook['cb'], ...array_slice( $args, 0, $hook['accepted'] ) );
				}
			}
		}
		function has_filter( $tag ) {
			return ! empty( $GLOBALS['wp_hooks'][ $tag ] );
		}
		function remove_filter( $tag, $callback, $priority = 10 ) {
			$key = opf_test_hook_key( $callback );
			unset( $GLOBALS['wp_hooks'][ $tag ][ (int) $priority ][ $key ] );
			unset( $GLOBALS['wp_filter'][ $tag ]->callbacks[ (int) $priority ][ $key ] );
			return true;
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ) { return (string) $text; }
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $text ) { return (string) $text; }
	}
	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( $text ) { return (string) $text; }
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	}
	if ( ! function_exists( 'sanitize_textarea_field' ) ) {
		function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
	}
	if ( ! function_exists( 'wp_list_pluck' ) ) {
		function wp_list_pluck( $list, $field ) {
			$out = [];
			foreach ( (array) $list as $item ) {
				if ( is_array( $item ) && array_key_exists( $field, $item ) ) {
					$out[] = $item[ $field ];
				}
			}
			return $out;
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) { return $value; }
	}
	if ( ! function_exists( 'selected' ) ) {
		function selected( $a, $b, $echo = true ) { return ''; }
	}
	if ( ! function_exists( 'wc_price' ) ) {
		function wc_price( $price ) { return '$' . $price; }
	}
	if ( ! function_exists( 'wc_add_notice' ) ) {
		function wc_add_notice( $message, $type = 'success' ) { $GLOBALS['opf_notices'][] = [ $type, $message ]; }
	}
	if ( ! function_exists( 'wp_doing_ajax' ) ) {
		function wp_doing_ajax() { return false; }
	}
	if ( ! function_exists( 'is_cart' ) ) {
		function is_cart() { return false; }
	}
	if ( ! function_exists( 'is_checkout' ) ) {
		function is_checkout() { return false; }
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in() { return false; }
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		function wp_get_current_user() { return (object) [ 'roles' => [] ]; }
	}
	if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
		function wc_get_product_term_ids( $product_id, $taxonomy ) { return []; }
	}
	if ( ! function_exists( 'wp_cache_supports' ) ) {
		function wp_cache_supports( $feature ) { return false; }
	}
	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, $group = '' ) { return false; }
	}
	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $value, $group = '' ) { return true; }
	}
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args = [] ) { return []; }
	}
	if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
		function wp_get_attachment_image_src( $id, $size ) { return [ 'https://example.test/img-' . $id . '-' . $size . '.jpg' ]; }
	}
	if ( ! function_exists( 'wp_get_attachment_image' ) ) {
		function wp_get_attachment_image( $id, $size, $icon = false, $attr = [] ) { return '<img src="https://example.test/img-' . $id . '-' . ( is_array( $size ) ? 'array' : $size ) . '.jpg" />'; }
	}
	if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
		function wp_get_attachment_image_url( $id, $size ) { return 'https://example.test/img-' . $id . '-full.jpg'; }
	}
	if ( ! function_exists( 'wc_placeholder_img_src' ) ) {
		function wc_placeholder_img_src( $size ) { return 'https://example.test/placeholder.jpg'; }
	}
	if ( ! function_exists( 'wp_enqueue_script' ) ) {
		function wp_enqueue_script( ...$args ) {}
	}
	if ( ! function_exists( 'wp_enqueue_style' ) ) {
		function wp_enqueue_style( ...$args ) {}
	}
	if ( ! function_exists( 'wp_print_inline_script_tag' ) ) {
		function wp_print_inline_script_tag( $script ) {}
	}
	if ( ! function_exists( 'admin_url' ) ) {
		function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
	}
	if ( ! function_exists( 'get_locale' ) ) {
		function get_locale() { return 'en_US'; }
	}
	if ( ! function_exists( 'get_woocommerce_currency' ) ) {
		function get_woocommerce_currency() { return 'USD'; }
	}
	if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
		function get_woocommerce_currency_symbol() { return '$'; }
	}
	if ( ! function_exists( 'wc_get_price_thousand_separator' ) ) {
		function wc_get_price_thousand_separator() { return ','; }
	}
	if ( ! function_exists( 'wc_get_price_decimal_separator' ) ) {
		function wc_get_price_decimal_separator() { return '.'; }
	}
	if ( ! function_exists( 'wc_get_price_decimals' ) ) {
		function wc_get_price_decimals() { return 2; }
	}
	if ( ! function_exists( 'get_woocommerce_price_format' ) ) {
		function get_woocommerce_price_format() { return '%1$s%2$s'; }
	}
	if ( ! function_exists( 'wc_prices_include_tax' ) ) {
		function wc_prices_include_tax() { return false; }
	}
	if ( ! function_exists( 'wc_get_products' ) ) {
		function wc_get_products( $args ) {
			$all = $GLOBALS['opf_linked_products'] ?? [];
			$out = [];
			foreach ( $all as $id => $product ) {
				if ( isset( $args['include'] ) && ! in_array( (int) $id, array_map( 'intval', (array) $args['include'] ), true ) ) {
					continue;
				}
				if ( isset( $args['exclude'] ) && in_array( (int) $id, array_map( 'intval', (array) $args['exclude'] ), true ) ) {
					continue;
				}
				$out[] = $product;
			}
			return $out;
		}
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $id ) { return $GLOBALS['opf_products'][ $id ] ?? false; }
	}
	if ( ! function_exists( 'WC' ) ) {
		function WC() { return (object) [ 'cart' => $GLOBALS['opf_cart'] ?? null ]; }
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $key, $value, $autoload = false ) { $GLOBALS['opf_test_options'][ $key ] = $value; return true; }
	}
	if ( ! function_exists( 'add_query_arg' ) ) {
		function add_query_arg( $key, $value, $url ) { return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . $key . '=' . $value; }
	}
	if ( ! function_exists( 'wc_get_cart_url' ) ) {
		function wc_get_cart_url() { return 'https://shop.test/cart/'; }
	}
	if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
		function woocommerce_store_api_register_endpoint_data( $args ) { $GLOBALS['opf_wapf_test_store_api'] = $args; }
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			private $data;
			public function __construct( array $data = [] ) {
				$this->data = array_merge( [
					'id' => 42, 'parent_id' => 0, 'name' => 'Product 42', 'title' => 'Product 42',
					'price' => 20.0, 'type' => 'simple', 'purchasable' => true, 'in_stock' => true,
					'stock' => null, 'sold_individually' => false, 'image_id' => 0,
					'availability' => 'In stock', 'permalink' => 'https://example.test/p/42',
					'short_description' => 'Short', 'description' => 'Long', 'attributes' => [],
					'visible' => true, 'meta' => [],
				], $data );
			}
			public function get_id() { return (int) $this->data['id']; }
			public function get_parent_id() { return (int) $this->data['parent_id']; }
			public function get_name() { return (string) $this->data['name']; }
			public function get_title() { return (string) $this->data['title']; }
			public function get_price( $context = 'view' ) { return $this->data['price']; }
			public function set_price( $price ) { $this->data['price'] = $price; }
			public function set_sale_price( $price ) {}
			public function get_type() { return (string) $this->data['type']; }
			public function is_purchasable() { return (bool) $this->data['purchasable']; }
			public function is_in_stock() { return (bool) $this->data['in_stock']; }
			public function is_taxable() { return 'none' !== ( $this->data['tax_status'] ?? 'taxable' ); }
			public function get_tax_class( $context = 'view' ) { return (string) ( $this->data['tax_class'] ?? '' ); }
			public function has_enough_stock( $qty ) { return null === $this->data['stock'] || $qty <= $this->data['stock']; }
			public function is_sold_individually() { return (bool) $this->data['sold_individually']; }
			public function get_image_id() { return (int) $this->data['image_id']; }
			public function get_availability() { return [ 'availability' => $this->data['availability'] ]; }
			public function get_permalink() { return (string) $this->data['permalink']; }
			public function get_short_description() { return (string) $this->data['short_description']; }
			public function get_description() { return (string) $this->data['description']; }
			public function get_attributes() { return $this->data['attributes']; }
			public function is_visible() { return (bool) ( $this->data['visible'] ?? true ); }
			public function get_meta( $key = '', $single = true ) { return $this->data['meta'][ $key ] ?? ''; }
		}
	}
	if ( ! class_exists( 'WC_Cart' ) ) {
		class WC_Cart {
			public $cart_contents = [];
			public function get_cart() { return $this->cart_contents; }
			public function get_cart_item( $key ) { return $this->cart_contents[ $key ] ?? []; }
			public function set_quantity( $key, $quantity, $refresh = true ) { $this->cart_contents[ $key ]['quantity'] = $quantity; }
			public function add_to_cart( $product_id, $quantity, $variation_id, $variation, $data ) {
				$key = 'child-' . count( $this->cart_contents );
				$this->cart_contents[ $key ] = [ 'key' => $key, 'product_id' => $product_id, 'quantity' => $quantity ] + $data;
				return $key;
			}
			public function remove_cart_item( $key ) { unset( $this->cart_contents[ $key ] ); }
		}
	}
	if ( ! class_exists( 'WC_Order' ) ) {
		class WC_Order {
			public function get_id() { return 1001; }
		}
	}
	if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
		class WC_Order_Item_Product {
			public $meta = [];
			private $product;
			private $quantity;
			public function __construct( $product = null, $quantity = 1, array $meta = [] ) {
				$this->product  = $product;
				$this->quantity = $quantity;
				$this->meta     = $meta;
			}
			public function get_product() { return $this->product; }
			public function get_quantity() { return $this->quantity; }
			public function get_id() { return 5; }
			public function add_meta_data( $key, $value, $unique = false ) { $this->meta[ $key ] = $value; }
			public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
		}
	}
}

namespace SW_WAPF_PRO\Includes\Models {
	class Field {
		public string $type = 'products';
	}
}

namespace SW_WAPF_PRO\Includes\Controllers {
	class Linked_Products_Controller {
		public int $calls = 0;
		public int $order_again_calls = 0;

		public function validate_cart( $error, $value, \SW_WAPF_PRO\Includes\Models\Field $field, $product_id, $clone_idx, $quantity, $from_cart, $cart_item_data ) {
			++$this->calls;
			return $error;
		}

		public function prepare_order_again_cart_item( $order_item, $field, $clone_idx, $raw_values ) {
			++$this->order_again_calls;
		}
	}
}

namespace Automattic\WooCommerce\StoreApi\Schemas\V1 {
	if ( ! class_exists( CartItemSchema::class ) ) {
		class CartItemSchema {
			public const IDENTIFIER = 'cart-item';
		}
	}
}

namespace OPF\Tests\Unit {

use OPF\Compat\WapfHooks;
use OPF\Engine\FieldGroup;
use OPF\Service\CartEdit;
use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;
use OPF\Service\LinkedProducts;
use OPF\Service\PricingHints;
use OPF\Service\ProductPriceDisplay;
use OPF\Service\Renderer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WapfHooksBridgeTest extends TestCase {

	/** @var array<string,array<int,array>> */
	private array $fired = [];

	protected function setUp(): void {
		$GLOBALS['wp_hooks']           = [];
		$GLOBALS['opf_test_options']   = [];
		$GLOBALS['opf_products']       = [
			11 => new \WC_Product( [ 'id' => 11, 'name' => 'Mug', 'price' => 8.0 ] ),
			42 => new \WC_Product( [ 'id' => 42, 'name' => 'Parent', 'price' => 20.0 ] ),
		];
		$GLOBALS['opf_linked_products'] = $GLOBALS['opf_products'];
		$GLOBALS['opf_notices']         = [];
		$_POST                          = [];
		FieldGroups::flush_cache();

		$this->fired = [];

		// The bridge under test.
		WapfHooks::init();

		// A third-party WAPF integration listening on the WAPF names.
		foreach ( $this->bridged_hooks() as $hook ) {
			add_filter( $hook, function ( ...$args ) use ( $hook ) {
				$this->fired[ $hook ][] = $args;
				return $args[0];
			}, 10, 8 );
		}

		// Feed OPF the test group for any product.
		add_filter( 'opf_groups_for_product', fn( $groups, $product ) => [ $this->group_entry() ], 10, 2 );
	}

	/** The exact WAPF hook names this lane bridges. */
	private function bridged_hooks(): array {
		return [
			'wapf/features/linked_products', 'wapf/linked_products/choice', 'wapf/products/query',
			'wapf/product_field_groups', 'wapf/lookup_tables', 'wapf/skip_cart_validation',
			'wapf/skip_fieldgroup_validation', 'wapf/pricing_summary',
			'wapf/pricing/base', 'wapf/pricing/cart_item_base', 'wapf/pricing/cart_item_options',
			'wapf/pricing/product', 'wapf/cart/item_data', 'wapf/validate',
			'wapf/order/order_item_field', 'wapf/order_item/meta_display_value',
			'wapf/order_again/before_cart_item_field', 'wapf/html/field_container_classes',
			'wapf/html/field_label', 'wapf/html/field_description',
			'wapf/html/option_wrapper_classes', 'wapf/html/image_swatch_size',
			'wapf/html/section_container_classes', 'wapf/linked_products/cart_choice',
			'wapf_before_wrapper', 'wapf_before_product_totals', 'wapf_after_product_totals',
			'wapf/html/pricing_hint/format', 'wapf/html/pricing_hint/amount',
			'wapf/html/pricing_hint', 'wapf/pricing/addon', 'wapf/pricing/price_with_tax',
			'wapf/pricing/cart_item_base_for_formulas',
			'wapf/cart_edit_text', 'wapf/disable_cart_edit_when_invisible',
			'wapf/add_to_cart_redirect_when_editing',
			'wapf/store_api/cart/data_callback', 'wapf/store_api/cart/schema_callback',
			'wapf/pricing/display_options', 'wapf/features/change_price_html',
		];
	}

	private function field( array $overrides = [] ): array {
		return FieldGroup::normalize_field( array_merge( [
			'id' => 'name', 'label' => 'Name', 'type' => 'text',
			'pricing' => [ 'type' => 'fixed', 'amount' => 5 ],
		], $overrides ) );
	}

	private function group_entry(): array {
		$fields = [
			$this->field( [ 'id' => 'name', 'label' => 'Name', 'type' => 'text', 'description' => 'Your name', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ] ),
			$this->field( [
				'id' => 'opt', 'label' => 'Option', 'type' => 'select',
				'choices'  => [ [ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'fixed', 'amount' => 3 ] ], [ 'slug' => 'b', 'label' => 'B' ] ],
			] ),
			$this->field( [
				'id' => 'sw', 'label' => 'Swatch', 'type' => 'swatch', 'swatch_style' => 'image',
				'choices'  => [ [ 'slug' => 's1', 'label' => 'S1', 'image_id' => 7, 'image' => 'https://example.test/s1.jpg', 'pricing' => [ 'type' => 'fixed', 'amount' => 1 ] ] ],
			] ),
			$this->field( [ 'id' => 'fx', 'label' => 'Formula', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => 'lookuptable(tbl;1)' ] ] ),
			$this->field( [
				'id' => 'prods', 'label' => 'Extras', 'type' => 'products', 'product_selection' => 'manual',
				'choices' => [ [ 'product_id' => 11, 'pricing_type' => 'fixed' ] ],
			] ),
			$this->field( [ 'id' => 'sec', 'label' => 'Section', 'type' => 'section' ] ),
			$this->field( [ 'id' => 'secend', 'label' => '', 'type' => 'section_end' ] ),
		];
		$group = new FieldGroup( [ 'schema' => FieldGroup::SCHEMA, 'fields' => $fields ] );
		return [ 'id' => 77, 'title' => 'Group', 'lang' => '', 'group' => $group ];
	}

	private function assert_fired( string $hook ): void {
		$this->assertArrayHasKey( $hook, $this->fired, "WAPF alias '$hook' did not fire on the OPF lifecycle." );
		$this->assertNotEmpty( $this->fired[ $hook ][0], "WAPF alias '$hook' fired without arguments." );
	}

	private function fired( string $hook ): array {
		return $this->fired[ $hook ] ?? [];
	}

	/* ============================================================ lifecycle */

	public function test_wapf_aliases_fire_across_cart_lifecycle(): void {
		$product = $GLOBALS['opf_products'][42];

		// --- Service registration surfaces -----------------------------------
		LinkedProducts::init();
		$this->assert_fired( 'wapf/features/linked_products' );

		Renderer::show_totals();
		$this->assert_fired( 'wapf/pricing_summary' );

		// --- FieldGroups::for_product → product_field_groups ------------------
		FieldGroups::for_product( $product );
		$this->assert_fired( 'wapf/product_field_groups' );

		// --- Validation: skip + per-field validate ----------------------------
		$_POST['opf'] = [ '77' => [ 'name' => 'Hello', 'opt' => 'a', 'sw' => 's1', 'fx' => '2' ] ];
		$passed = CartIntegration::validate_add_to_cart( true, 42, 1, 0, [], [] );
		$this->assertTrue( $passed );
		$this->assert_fired( 'wapf/skip_cart_validation' );
		$this->assert_fired( 'wapf/skip_fieldgroup_validation' );
		$this->assert_fired( 'wapf/validate' );
		$validate_args = $this->fired( 'wapf/validate' )[0];
		$this->assertCount( 8, $validate_args, 'wapf/validate must receive the 8-argument WAPF shape.' );
		$this->assertIsArray( $validate_args[0], 'wapf/validate arg 0 is the error array.' );
		$this->assertArrayHasKey( 'error', $validate_args[0] );
		$this->assertSame( 42, $validate_args[3], 'wapf/validate arg 3 is the product id.' );

		// --- Attach + linked products ----------------------------------------
		$_POST['opf'] = [ '77' => [ 'name' => 'Hello', 'opt' => 'a', 'sw' => 's1', 'fx' => '2', 'prods' => [ 'p11' ] ] ];
		$cart_item = CartIntegration::attach( [], 42 );
		$this->assertArrayHasKey( CartIntegration::ITEM_KEY, $cart_item );

		$product->set_price( 20.0 );
		FieldGroups::flush_cache();
		$cart = new \WC_Cart();
		$cart->cart_contents = [ 'parent' => [ 'key' => 'parent', 'product_id' => 42, 'quantity' => 2, 'data' => $product ] + $cart_item ];
		$GLOBALS['opf_cart'] = $cart;

		LinkedProducts::add_children( 'parent', 42, 2, 0, [], $cart_item );
		$this->assert_fired( 'wapf/linked_products/cart_choice' );

		// product_choices triggers opf/linked_products/choice → wapf/linked_products/choice.
		$field = FieldGroup::normalize_field( [
			'id' => 'prods', 'label' => 'Extras', 'type' => 'products', 'product_selection' => 'manual',
			'choices' => [ [ 'product_id' => 11, 'pricing_type' => 'fixed' ] ],
		] );
		LinkedProducts::product_choices( $field, $product );
		$this->assert_fired( 'wapf/linked_products/choice' );

		// products_by_query triggers opf/linked_products/query_args → wapf/products/query.
		LinkedProducts::products_by_query( [ 'query_id' => 3 ], $product );
		$this->assert_fired( 'wapf/products/query' );

		// --- Pricing (apply_prices) ------------------------------------------
		CartIntegration::apply_prices( $cart );
		$this->assert_fired( 'wapf/pricing/base' );
		$this->assert_fired( 'wapf/pricing/cart_item_base' );
		$this->assert_fired( 'wapf/pricing/cart_item_options' );
		$this->assert_fired( 'wapf/lookup_tables' );

		$base_args = $this->fired( 'wapf/pricing/base' )[0];
		$this->assertCount( 3, $base_args, 'wapf/pricing/base WAPF shape is ($price,$product,$quantity).' );
		$this->assertSame( 2, $base_args[2] );
		$options_args = $this->fired( 'wapf/pricing/cart_item_options' )[0];
		$this->assertCount( 4, $options_args, 'wapf/pricing/cart_item_options WAPF shape is ($total,$product,$quantity,$cart_item).' );
		$this->assertArrayHasKey( 'key', $options_args[3] );

		// The 'fx' field's formula evaluation fires the formula-base alias.
		$this->assert_fired( 'wapf/pricing/cart_item_base_for_formulas' );
		$formula_base_args = $this->fired( 'wapf/pricing/cart_item_base_for_formulas' )[0];
		$this->assertCount( 4, $formula_base_args, 'WAPF shape is ($price,$product,$quantity,$cart_item).' );
		$this->assertInstanceOf( \WC_Product::class, $formula_base_args[1] );

		// --- Cart display -----------------------------------------------------
		CartIntegration::display_item_data( [], $cart->cart_contents['parent'] );
		$this->assert_fired( 'wapf/cart/item_data' );

		// Per-value cart hints run the full WAPF hint filter chain.
		$this->assert_fired( 'wapf/html/pricing_hint/format' );
		$this->assert_fired( 'wapf/html/pricing_hint/amount' );
		$this->assert_fired( 'wapf/pricing/price_with_tax' );
		$this->assert_fired( 'wapf/pricing/addon' );
		$this->assert_fired( 'wapf/html/pricing_hint' );
		$hint_amount_args = $this->fired( 'wapf/html/pricing_hint/amount' )[0];
		$this->assertCount( 4, $hint_amount_args, 'WAPF shape is ($amount,$product,$type,$for_page).' );
		$this->assertSame( 'cart', $hint_amount_args[3] );

		// --- Order meta -------------------------------------------------------
		$order      = new \WC_Order();
		$order_item = new \WC_Order_Item_Product( $product, 2 );
		CartIntegration::persist_order_item( $order_item, 'parent', $cart->cart_contents['parent'], $order );
		$this->assert_fired( 'wapf/order/order_item_field' );
		$this->assert_fired( 'wapf/order_item/meta_display_value' );
		$order_field_args = $this->fired( 'wapf/order/order_item_field' )[0];
		$this->assertCount( 3, $order_field_args, 'wapf/order/order_item_field WAPF shape is ($meta_field,$cart_item,$field).' );
		$this->assertArrayHasKey( 'value', $order_field_args[0] );

		// --- Order again ------------------------------------------------------
		$meta = $order_item->get_meta( '_opf_fields', true );
		if ( is_string( $meta ) && '' !== $meta ) {
			$again_item = new \WC_Order_Item_Product( $product, 1, [ '_opf_fields' => $meta ] );
			CartIntegration::restore_order_again( [], $again_item, $order );
			$this->assert_fired( 'wapf/order_again/before_cart_item_field' );
		}
	}

	public function test_opf_validation_skips_only_wapf_typed_linked_product_listener(): void {
		$native_validator = new \SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller();
		add_filter( 'wapf/validate', [ $native_validator, 'validate_cart' ], 10, 8 );
		add_filter( 'wapf/validate', static function ( $error ) {
			$error['error']   = true;
			$error['message'] = 'third-party validation rule';
			return $error;
		}, 11, 1 );
		$order_before = array_keys( $GLOBALS['wp_filter']['wapf/validate']->callbacks[10] );

		$errors = WapfHooks::validate_field(
			[ 'id' => 'image-options', 'type' => 'image_quantity', 'label' => 'Prints' ],
			[ 'oak' => 2 ],
			$GLOBALS['opf_products'][42],
			1
		);

		$this->assertSame( [ 'third-party validation rule' ], $errors, 'Third-party validation behavior is preserved for OPF-only fields.' );
		$this->assert_fired( 'wapf/validate' );
		$this->assertSame(
			[ 'id' => 'image-options', 'type' => 'image_quantity', 'label' => 'Prints' ],
			$this->fired( 'wapf/validate' )[0][2],
			'Third-party WAPF listeners continue to receive the normalized OPF field array.'
		);
		$this->assertSame( 0, $native_validator->calls, 'WAPF native typed validator must not receive an OPF normalized array.' );

		$registry = $GLOBALS['wp_filter']['wapf/validate'];
		$native_key = opf_test_hook_key( [ $native_validator, 'validate_cart' ] );
		$this->assertArrayHasKey( 10, $registry->callbacks );
		$this->assertArrayHasKey( $native_key, $registry->callbacks[10], 'Native WAPF validator is restored after OPF dispatch.' );
		$this->assertSame( $order_before, array_keys( $registry->callbacks[10] ), 'Same-priority callback order is preserved.' );

		apply_filters( 'wapf/validate', [ 'error' => false ], 'x', new \SW_WAPF_PRO\Includes\Models\Field(), 42, 0, 1, false, null );
		$this->assertSame( 1, $native_validator->calls, 'WAPF-native validation still invokes its own typed listener.' );
	}

	public function test_opf_date_validation_skips_only_wapf_extended_object_date_listener(): void {
		$GLOBALS['opf_wapfe_date_validator_calls'] = 0;
		add_filter( 'wapf/validate', 'wapfe_validate_cart_data', 10, 8 );
		$order_before = array_keys( $GLOBALS['wp_filter']['wapf/validate']->callbacks[10] );
		$date_group = new FieldGroup( [
			'schema' => FieldGroup::SCHEMA,
			'fields' => [ $this->field( [ 'id' => 'delivery', 'label' => 'Delivery', 'type' => 'date', 'required' => true ] ) ],
		] );
		add_filter( 'opf_groups_for_product', static function ( $groups, $product ) use ( $date_group ) {
			return [ [ 'id' => 78, 'title' => 'Date group', 'lang' => '', 'group' => $date_group ] ];
		}, 20, 2 );

		$method   = new \ReflectionMethod( CartIntegration::class, 'validate_values' );
		$warnings = [];
		set_error_handler( static function ( $severity, $message ) use ( &$warnings ) {
			$warnings[] = [ $severity, $message ];
			return true;
		} );
		try {
			$valid_errors   = $method->invoke( null, $GLOBALS['opf_products'][42], [ 78 => [ 'delivery' => '2027-06-15' ] ], 1 );
			$invalid_errors = $method->invoke( null, $GLOBALS['opf_products'][42], [ 78 => [ 'delivery' => '2027-02-30' ] ], 1 );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( [], $valid_errors, 'OPF accepts a valid ISO date with WAPF Extended active.' );
		$this->assertSame( [ '"Delivery" must be a valid date.' ], $invalid_errors, 'OPF rejects an invalid calendar date with WAPF Extended active.' );
		$this->assertSame( [], $warnings, 'The WAPF date extension must not read an OPF array as an object.' );
		$this->assertSame( 0, $GLOBALS['opf_wapfe_date_validator_calls'], 'WAPF Extended does not receive an OPF normalized field.' );
		$this->assert_fired( 'wapf/validate' );
		$this->assertIsArray( $this->fired( 'wapf/validate' )[0][2], 'Third-party WAPF validation callbacks still receive the normalized OPF date field.' );

		$registry = $GLOBALS['wp_filter']['wapf/validate'];
		$this->assertSame( $order_before, array_keys( $registry->callbacks[10] ), 'The WAPF date callback returns in its original same-priority position.' );
		apply_filters( 'wapf/validate', [ 'error' => false ], '2027-06-15', (object) [ 'type' => 'date' ], 42, 0, 1, false, null );
		$this->assertSame( 1, $GLOBALS['opf_wapfe_date_validator_calls'], 'WAPF-native date validation still invokes the extension callback for its object field.' );
	}

	public function test_native_wapf_validator_is_restored_when_another_listener_throws(): void {
		$native_validator = new \SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller();
		add_filter( 'wapf/validate', [ $native_validator, 'validate_cart' ], 10, 8 );
		$order_before = array_keys( $GLOBALS['wp_filter']['wapf/validate']->callbacks[10] );
		$thrower = static function ( $error ) {
			throw new \RuntimeException( 'third-party validation failure' );
		};
		add_filter( 'wapf/validate', $thrower, 9, 1 );

		try {
			WapfHooks::validate_field(
				[ 'id' => 'note', 'type' => 'text', 'label' => 'Note' ],
				'hello',
				$GLOBALS['opf_products'][42],
				1
			);
			$this->fail( 'The third-party exception should propagate.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'third-party validation failure', $exception->getMessage() );
		} finally {
			remove_filter( 'wapf/validate', $thrower, 9 );
		}

		$registry = $GLOBALS['wp_filter']['wapf/validate'];
		$this->assertSame( $order_before, array_keys( $registry->callbacks[10] ), 'Native listener is restored in the original order after an exception.' );
		$this->assertSame( 0, $native_validator->calls );
		apply_filters( 'wapf/validate', [ 'error' => false ], 'x', new \SW_WAPF_PRO\Includes\Models\Field(), 42, 0, 1, false, null );
		$this->assertSame( 1, $native_validator->calls, 'Restored native listener remains usable after a failed OPF dispatch.' );
	}

	public function test_opf_order_again_skips_only_wapf_linked_product_field_object_handler(): void {
		$native_controller = new \SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller();
		add_action( 'wapf/order_again/before_cart_item_field', [ $native_controller, 'prepare_order_again_cart_item' ], 10, 4 );
		$order_before = array_keys( $GLOBALS['wp_filter']['wapf/order_again/before_cart_item_field']->callbacks[10] );

		$field = [ 'id' => 'extras', 'type' => 'products', 'choices' => [] ];
		WapfHooks::order_again_field( new \WC_Order_Item_Product(), $field, 0, [ 'product' => 11 ] );

		$this->assert_fired( 'wapf/order_again/before_cart_item_field' );
		$this->assertSame( $field, $this->fired( 'wapf/order_again/before_cart_item_field' )[0][1], 'Third-party WAPF listeners still receive the normalized OPF field array.' );
		$this->assertSame( 0, $native_controller->order_again_calls, 'Native WAPF linked-products handler must not receive an OPF field array.' );
		$this->assertSame( $order_before, array_keys( $GLOBALS['wp_filter']['wapf/order_again/before_cart_item_field']->callbacks[10] ), 'Native handler is restored in its original callback order.' );

		do_action( 'wapf/order_again/before_cart_item_field', new \WC_Order_Item_Product(), new \SW_WAPF_PRO\Includes\Models\Field(), 0, [] );
		$this->assertSame( 1, $native_controller->order_again_calls, 'Native WAPF order-again dispatch still reaches its Field-object handler.' );
	}

	public function test_wapf_field_render_aliases_fire(): void {
		$GLOBALS['product'] = $GLOBALS['opf_products'][42];

		// render_group drives field label/description/classes/section/options/image.
		ob_start();
		Renderer::render_group( 77, 'Group', $this->group_entry()['group'], 20.0, $GLOBALS['product'] );
		ob_end_clean();

		$this->assert_fired( 'wapf/html/section_container_classes' );
		$this->assert_fired( 'wapf/html/field_container_classes' );
		$this->assert_fired( 'wapf/html/field_label' );
		$this->assert_fired( 'wapf/html/field_description' );
		$this->assert_fired( 'wapf/html/option_wrapper_classes' );
		$this->assert_fired( 'wapf/html/image_swatch_size' );

		// Full render drives pricing/product + the legacy wrapper/totals actions.
		ob_start();
		Renderer::render();
		ob_end_clean();

		$this->assert_fired( 'wapf/pricing/product' );
		$this->assert_fired( 'wapf_before_wrapper' );
		$this->assert_fired( 'wapf_before_product_totals' );
		$this->assert_fired( 'wapf_after_product_totals' );

		$label_args = $this->fired( 'wapf/html/field_label' )[0];
		$this->assertCount( 3, $label_args, 'wapf/html/field_label WAPF shape is ($label_content,$field,$product).' );
		$size_args = $this->fired( 'wapf/html/image_swatch_size' )[0];
		$this->assertCount( 4, $size_args, 'wapf/html/image_swatch_size WAPF shape is ($size,$field,$product,$choice).' );
		$this->assertSame( 'medium', $size_args[0] );
	}

	/** An OPF-carrying cart line, for the CartEdit/Store API surfaces. */
	private function editable_cart_item( array $overrides = [] ): array {
		return array_merge( [
			'key'                     => 'k1',
			'product_id'              => 42,
			'data'                    => new \WC_Product( [ 'id' => 42 ] ),
			CartIntegration::ITEM_KEY => [ '77' => [ 'name' => 'Hello' ] ],
		], $overrides );
	}

	/**
	 * `wapf/cart_edit_text` — the returned label is what the cart link shows.
	 */
	public function test_wapf_cart_edit_text_return_is_consumed(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$seen = [];
		add_filter( 'wapf/cart_edit_text', static function ( $text, $cart_item ) use ( &$seen ) {
			$seen = [ $text, $cart_item ];
			return 'Edit this item';
		}, 20, 2 );

		$item = $this->editable_cart_item();
		ob_start();
		CartEdit::add_edit_link( $item, 'k1' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Edit this item', $html, 'The value returned by wapf/cart_edit_text must be rendered into the cart link.' );
		$this->assertSame( '(edit)', $seen[0], 'wapf/cart_edit_text arg 0 is OPF\'s default label.' );
		$this->assertSame( $item, $seen[1], 'wapf/cart_edit_text arg 1 is the cart line.' );
		$this->assert_fired( 'wapf/cart_edit_text' );
	}

	/**
	 * `wapf/disable_cart_edit_when_invisible` — false restores the link on an
	 * invisible product, proving the returned boolean drives the gate.
	 */
	public function test_wapf_disable_cart_edit_when_invisible_return_is_consumed(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$item = $this->editable_cart_item( [ 'data' => new \WC_Product( [ 'id' => 42, 'visible' => false ] ) ] );

		ob_start();
		CartEdit::add_edit_link( $item, 'k1' );
		$this->assertSame( '', (string) ob_get_clean(), 'The default WAPF gate suppresses edit links for invisible products.' );
		$this->assert_fired( 'wapf/disable_cart_edit_when_invisible' );

		add_filter( 'wapf/disable_cart_edit_when_invisible', static fn( $disable ) => false, 20, 1 );
		ob_start();
		CartEdit::add_edit_link( $item, 'k1' );
		$this->assertStringContainsString( 'opf-edit-cartitem', (string) ob_get_clean(), 'Returning false from wapf/disable_cart_edit_when_invisible restores the link.' );
	}

	/**
	 * `wapf/add_to_cart_redirect_when_editing` — false keeps WooCommerce's own
	 * redirect, proving the returned boolean drives the branch.
	 */
	public function test_wapf_add_to_cart_redirect_when_editing_return_is_consumed(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$_POST[ CartEdit::POST_PARAM ] = 'k1';

		$this->assertSame( 'https://shop.test/cart/', CartEdit::redirect_to_cart( 'https://example.test/fallback', new \WC_Product() ) );
		$this->assert_fired( 'wapf/add_to_cart_redirect_when_editing' );

		add_filter( 'wapf/add_to_cart_redirect_when_editing', static fn( $redirect ) => false, 20, 1 );
		$this->assertSame( 'https://example.test/fallback', CartEdit::redirect_to_cart( 'https://example.test/fallback', new \WC_Product() ) );
	}

	/**
	 * `wapf/store_api/cart/{schema,data}_callback` — the returned schema and
	 * data are what gets registered with the Store API.
	 */
	public function test_wapf_store_api_callback_returns_are_consumed(): void {
		$GLOBALS['opf_test_options']['opf_edit_cart'] = 'yes';
		$GLOBALS['opf_wapf_test_store_api'] = null;

		CartEdit::register_store_api();
		$args = $GLOBALS['opf_wapf_test_store_api'] ?? null;
		$this->assertIsArray( $args );

		add_filter( 'wapf/store_api/cart/schema_callback', static function ( $schema ) {
			$schema['wapfExtra'] = [ 'type' => 'string' ];
			return $schema;
		}, 20, 1 );
		$schema = ( $args['schema_callback'] )();
		$this->assertArrayHasKey( 'wapfExtra', $schema, 'The object returned by wapf/store_api/cart/schema_callback must be registered.' );
		$this->assertArrayHasKey( 'editLink', $schema, 'OPF default schema keys survive the bridge.' );

		add_filter( 'wapf/store_api/cart/data_callback', static function ( $data, $cart_item ) {
			$data['wapfExtra'] = 'x';
			return $data;
		}, 20, 2 );
		$data = ( $args['data_callback'] )( $this->editable_cart_item() );
		$this->assertSame( 'x', $data['wapfExtra'] ?? null, 'The array returned by wapf/store_api/cart/data_callback must be registered.' );
		$this->assertArrayHasKey( 'editLink', $data, 'OPF default data survives the bridge.' );
		$this->assert_fired( 'wapf/store_api/cart/schema_callback' );
		$this->assert_fired( 'wapf/store_api/cart/data_callback' );
	}

	/**
	 * `wapf/pricing/display_options` — listener edits land in the OPF frontend
	 * config the theme JS formats prices from.
	 */
	public function test_wapf_pricing_display_options_return_is_consumed(): void {
		$config = static fn() => apply_filters( 'opf_frontend_config', [
			'ajax'            => 'endpoint',
			'currency'        => 'USD',
			'display_options' => [ 'symbol' => '$', 'thousand' => ',', 'decimal' => '.', 'decimals' => 2, 'price_format' => 'symbolprice' ],
		] );

		$unchanged = $config();
		$this->assertSame( '$', $unchanged['display_options']['symbol'] );
		$this->assertSame( 'symbolprice', $unchanged['display_options']['price_format'] );

		$captured = [];
		add_filter( 'wapf/pricing/display_options', static function ( $options, $for_frontend ) use ( &$captured ) {
			$captured = [ $options, $for_frontend ];
			$options['symbol'] = '€';
			$options['format'] = '%2$s%1$s';
			return $options;
		}, 20, 2 );

		$changed = $config();
		$this->assertSame( '€', $changed['display_options']['symbol'], 'Symbol edits must reach window.opf_config.display_options.' );
		$this->assertSame( 'pricesymbol', $changed['display_options']['price_format'], 'A changed format pattern must map back onto price_format.' );
		$this->assertSame( '%1$s%2$s', $captured[0]['format'], 'The listener receives WAPF\'s frontend shape, seeded from the OPF block.' );
		$this->assertTrue( $captured[1], 'The listener receives $for_frontend = true.' );
		$this->assertArrayHasKey( 'trim_zeroes', $captured[0], 'The WAPF frontend shape keys are supplied.' );
	}

	/**
	 * `wapf/features/change_price_html` — false stops OPF from overriding the
	 * catalog price html, proving the returned boolean is consumed.
	 */
	public function test_wapf_change_price_html_feature_gate_is_consumed(): void {
		$product = new \WC_Product( [
			'id'   => 77,
			'meta' => [ ProductPriceDisplay::META_DISPLAY => 'after', ProductPriceDisplay::META_LABEL => 'Sale' ],
		] );
		add_filter( 'woocommerce_get_price_html', [ ProductPriceDisplay::class, 'filter_price_html' ], 101, 2 );
		WapfHooks::init(); // Re-register OPF's override behind the WAPF switch.

		$on = apply_filters( 'woocommerce_get_price_html', '$10', $product );
		$this->assertStringContainsString( 'wapf-price-after', $on, 'The default (true) still applies OPF\'s configured price label.' );
		$this->assert_fired( 'wapf/features/change_price_html' );

		add_filter( 'wapf/features/change_price_html', static fn( $change ) => false, 20, 1 );
		$this->assertSame( '$10', apply_filters( 'woocommerce_get_price_html', '$10', $product ), 'Returning false must stop OPF from changing the price html.' );
	}

	/**
	 * `wapf/pricing/price_with_tax` — an existing bridge, proven consumed: the
	 * returned amount is what the hint math uses.
	 */
	public function test_wapf_pricing_price_with_tax_return_is_consumed(): void {
		add_filter( 'wapf/pricing/price_with_tax', static fn( $with_tax, $price, $product, $page ) => 99.0, 20, 4 );
		$this->assertSame( 99.0, PricingHints::maybe_add_tax( $GLOBALS['opf_products'][42], 5.0, 'shop' ) );
		$this->assert_fired( 'wapf/pricing/price_with_tax' );
		$args = $this->fired( 'wapf/pricing/price_with_tax' )[0];
		$this->assertCount( 4, $args, 'WAPF shape is ($price_with_tax,$price,$product,$for_page).' );
		$this->assertSame( 'shop', $args[3] );
	}

	/** No `wapf/…` listeners → OPF output must be byte-identical to no bridge. */
	public function test_bridge_is_a_no_op_without_wapf_listeners(): void {
		$GLOBALS['product'] = $GLOBALS['opf_products'][42];

		$GLOBALS['wp_hooks'] = []; // Drop every listener, including the bridge shims.

		ob_start();
		Renderer::render_group( 77, 'Group', $this->group_entry()['group'], 20.0, $GLOBALS['product'] );
		$without_bridge = ob_get_clean();

		WapfHooks::init();
		ob_start();
		Renderer::render_group( 77, 'Group', $this->group_entry()['group'], 20.0, $GLOBALS['product'] );
		$with_bridge = ob_get_clean();

		$this->assertSame( $without_bridge, $with_bridge );
	}
}

}
