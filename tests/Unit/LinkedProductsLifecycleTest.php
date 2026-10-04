<?php

namespace {
	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {
			public function get_param( $key ) { return $GLOBALS['opf_submitted']; }
		}
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) { return $value; }
	}
	if ( ! class_exists( 'WC_Cart' ) ) {
		class WC_Cart {}
	}
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			private $data;
			public function __construct( array $data = [] ) {
				$this->data = array_merge( [
					'id' => 42, 'parent_id' => 0, 'name' => 'Product 42', 'title' => 'Product 42',
					'price' => 10.0, 'type' => 'simple', 'purchasable' => true, 'in_stock' => true,
					'stock' => null, 'sold_individually' => false, 'image_id' => 0,
					'availability' => 'In stock', 'permalink' => 'https://example.test/p/42',
					'short_description' => 'Short', 'description' => 'Long',
				], $data );
			}
			public function get_id(): int { return (int) $this->data['id']; }
			public function get_parent_id(): int { return (int) $this->data['parent_id']; }
			public function get_name(): string { return (string) $this->data['name']; }
			public function get_title(): string { return (string) $this->data['title']; }
			public function get_price( string $context = 'view' ) { return $this->data['price']; }
			public function set_price( $price ): void { $this->data['price'] = $price; }
			public function set_sale_price( $price ): void { $this->data['sale_price'] = $price; }
			public function get_type(): string { return (string) $this->data['type']; }
			public function is_purchasable(): bool { return (bool) $this->data['purchasable']; }
			public function is_in_stock(): bool { return (bool) $this->data['in_stock']; }
			public function has_enough_stock( $qty ): bool { return null === $this->data['stock'] || $qty <= $this->data['stock']; }
			public function is_sold_individually(): bool { return (bool) $this->data['sold_individually']; }
			public function get_image_id(): int { return (int) $this->data['image_id']; }
			public function get_availability(): array { return [ 'availability' => $this->data['availability'] ]; }
			public function get_permalink(): string { return (string) $this->data['permalink']; }
			public function get_short_description(): string { return (string) $this->data['short_description']; }
			public function get_description(): string { return (string) $this->data['description']; }
		}
	}
	if ( ! function_exists( 'WC' ) ) {
		function WC() { return (object) [ 'cart' => $GLOBALS['opf_repeat_test_cart'] ?? null ]; }
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $id ) { return $GLOBALS['opf_woocs_products'][ $id ] ?? ( $GLOBALS['opf_linked_products'][ $id ] ?? false ); }
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ): string { return (string) $text; }
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ): string { return (string) $text; }
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ): string { return (string) $text; }
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $text ): string { return (string) $text; }
	}
	if ( ! function_exists( 'wc_add_notice' ) ) {
		function wc_add_notice( $message, $type ): void { $GLOBALS['opf_notices'][] = [ $type, $message ]; }
	}
	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin(): bool { return (bool) ( $GLOBALS['opf_is_admin'] ?? false ); }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( 'wc_price' ) ) {
		function wc_price( $price ): string { return '$' . $price; }
	}
	if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
		function wp_get_attachment_image_src( $id, $size ) { return [ 'https://example.test/img-' . $id . '-' . $size . '.jpg' ]; }
	}
	if ( ! function_exists( 'wc_placeholder_img_src' ) ) {
		function wc_placeholder_img_src( $size ) { return 'https://example.test/placeholder.jpg'; }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $name, $value, ...$args ) {
			$callback = $GLOBALS['opf_wpml_filters'][ $name ] ?? null;
			return $callback ? $callback( $value, ...$args ) : $value;
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $name, $callback, $priority = 10, $accepted = 1 ): void {
			$GLOBALS['opf_woocs_hooks'][ $name ] = [ $callback, $priority, $accepted ];
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $name, $callback, $priority = 10, $accepted = 1 ): void {
			$GLOBALS['opf_woocs_hooks'][ $name ] = [ $callback, $priority, $accepted ];
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( $name, ...$args ): void { $GLOBALS['opf_wpml_actions'][] = [ $name, $args ]; }
	}
	if ( ! function_exists( 'remove_filter' ) ) {
		function remove_filter( ...$args ): void { $GLOBALS['opf_removed_filters'][] = $args; }
	}
	if ( ! function_exists( 'current_filter' ) ) {
		function current_filter(): string { return $GLOBALS['opf_current_filter'] ?? ''; }
	}
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args = [] ): array { return $GLOBALS['opf_auth_test_posts'] ?? []; }
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in(): bool { return (bool) ( $GLOBALS['opf_auth_test_logged_in'] ?? false ); }
	}
	if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
		function wc_get_product_term_ids( $product_id, $taxonomy ): array { return []; }
	}
	if ( ! function_exists( 'wp_cache_supports' ) ) {
		function wp_cache_supports( string $feature ): bool { return 'flush_group' === $feature; }
	}
	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, string $group = '' ) { return $GLOBALS['opf_auth_test_cache'][ $group ][ $key ] ?? false; }
	}
	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $value, string $group = '' ): bool { $GLOBALS['opf_auth_test_cache'][ $group ][ $key ] = $value; return true; }
	}
	if ( ! function_exists( 'wp_cache_flush_group' ) ) {
		function wp_cache_flush_group( string $group ): bool { $GLOBALS['opf_auth_test_cache'][ $group ] = []; return true; }
	}
}

namespace OPF\Service {
	// `wc_get_products` is the only OPF\Service-namespaced stub this file may
	// declare: every other name used here (apply_filters, wc_get_product,
	// add_filter, wp_cache_*, …) is already declared unconditionally by
	// another test file in this namespace, and the suite loader includes all
	// test files in one process. Those calls therefore resolve to that other
	// file's stub in suite context — or to the global stubs above when this
	// file runs alone in a separate process. Both paths are driven by the
	// same $GLOBALS registries (opf_wpml_filters, opf_woocs_products,
	// opf_repeat_test_cart, opf_auth_test_*) so behaviour is consistent.
	function wc_get_products( array $args ) {
		$all = $GLOBALS['opf_linked_products'] ?? [];
		$out = [];
		foreach ( $all as $id => $product ) {
			if ( isset( $args['include'] ) && ! in_array( $id, array_map( 'intval', (array) $args['include'] ), true ) ) {
				continue;
			}
			if ( isset( $args['exclude'] ) && in_array( $id, array_map( 'intval', (array) $args['exclude'] ), true ) ) {
				continue;
			}
			if ( isset( $args['product_category_id'] ) && ( ( $GLOBALS['opf_linked_product_cats'][ $id ] ?? 0 ) !== (int) $args['product_category_id'] ) ) {
				continue;
			}
			$out[ $id ] = $product;
		}
		if ( isset( $args['limit'] ) ) {
			$out = array_slice( $out, 0, (int) $args['limit'], true );
		}
		return array_values( $out );
	}
}

namespace OPF\Tests\Unit {

use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;
use OPF\Service\LinkedProducts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Linked-products service: sanitize, resolve, validate, cart lifecycle,
 * order handling. Runs in a separate process so the controlled WP/WC stubs
 * above cannot collide with other test files' globals.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class LinkedProductsLifecycleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['opf_linked_products'] = [
			11 => new \WC_Product( [ 'id' => 11, 'name' => 'Mug', 'price' => 8.0 ] ),
			12 => new \WC_Product( [ 'id' => 12, 'name' => 'Coaster', 'price' => 4.0 ] ),
			13 => new \WC_Product( [ 'id' => 13, 'name' => 'Poster', 'price' => 6.0, 'in_stock' => false ] ),
			14 => new \WC_Product( [ 'id' => 14, 'name' => 'Variant A', 'price' => 9.0, 'type' => 'variation', 'parent_id' => 40 ] ),
		];
		// Category membership for the query resolver.
		$GLOBALS['opf_linked_product_cats'] = [ 11 => 3, 12 => 3, 13 => 4, 14 => 0 ];
		$GLOBALS['opf_repeat_test_cart'] = new LinkedTestCart();
		$GLOBALS['opf_woocs_products'] = [];
		$GLOBALS['opf_notices'] = [];
		$GLOBALS['opf_test_options'] = [];
		$GLOBALS['opf_wpml_filters'] = [];
		$GLOBALS['opf_current_filter'] = '';
		$GLOBALS['opf_is_admin'] = false;
		$GLOBALS['opf_auth_test_cache'] = [];
		$GLOBALS['opf_auth_test_posts'] = [];
		$GLOBALS['opf_auth_test_logged_in'] = false;
		FieldGroups::flush_cache();
	}

	/* ---------------------------------------------------------------- helpers */

	private function field( array $overrides = [] ): array {
		return FieldGroup::normalize_field( array_merge( [
			'id' => 'addons', 'label' => 'Add-ons', 'type' => 'products',
			'choices' => [
				[ 'product_id' => 11 ],
				[ 'product_id' => 12, 'pricing_type' => 'none' ],
			],
		], $overrides ) );
	}

	private function group_with( array $field ): array {
		$group = new FieldGroup( [ 'schema' => FieldGroup::SCHEMA, 'fields' => [ $field ] ] );
		return [ 'id' => 77, 'title' => 'Group', 'lang' => '', 'group' => $group ];
	}

	private function parent_product( int $id = 42 ): \WC_Product {
		return new \WC_Product( [ 'id' => $id, 'name' => 'Parent', 'title' => 'Parent', 'price' => 20.0 ] );
	}

	/* ------------------------------------------------------- pure primitives */

	public function test_qty_type_mirrors_wapf_build_cache_data(): void {
		$this->assertSame( 'one', LinkedProducts::qty_type( $this->field() ) );
		$this->assertSame( 'parent', LinkedProducts::qty_type( $this->field( [ 'qty_method' => 'parent' ] ) ) );
		$this->assertSame( 'custom', LinkedProducts::qty_type( $this->field( [ 'subtype' => 'card-qty' ] ) ) );
		$this->assertSame( 'relative', LinkedProducts::qty_type( $this->field( [ 'subtype' => 'card-qty', 'max_choices' => 4 ] ) ) );
	}

	public function test_price_type_mirrors_wapf_get_product_price_type(): void {
		$this->assertSame( 'fixed', LinkedProducts::price_type( $this->field(), 'fixed' ) );
		$this->assertSame( 'none', LinkedProducts::price_type( $this->field(), 'none' ) );
		$this->assertSame( 'qt', LinkedProducts::price_type( $this->field( [ 'qty_method' => 'parent' ] ), 'fixed' ) );
		$this->assertSame( 'nr', LinkedProducts::price_type( $this->field( [ 'subtype' => 'card-qty' ] ), 'fixed' ) );
	}

	/* ---------------------------------------------------------------- sanitize */

	public function test_sanitize_multi_slug_list_strips_sentinels(): void {
		$field = $this->field();
		$this->assertSame( [ 'p11', 'p12' ], LinkedProducts::sanitize_value( $field, [ 'p11', '0', '', 'p12' ] ) );
		$this->assertNull( LinkedProducts::sanitize_value( $field, [ '0', '' ] ) );
	}

	public function test_sanitize_single_subtype_returns_scalar(): void {
		$field = $this->field( [ 'subtype' => 'radio' ] );
		$this->assertSame( 'p11', LinkedProducts::sanitize_value( $field, 'p11' ) );
	}

	public function test_sanitize_qty_selector_wraps_quantities_and_bounds(): void {
		$field = $this->field( [
			'subtype' => 'card-qty',
			'choices' => [
				[ 'product_id' => 11, 'quantity' => [ 'min' => 2, 'max' => 5, 'default' => 0 ] ],
				[ 'product_id' => 12, 'quantity' => [ 'min' => 0, 'max' => 99, 'default' => 0 ] ],
			],
		] );
		$out = LinkedProducts::sanitize_value( $field, [ 'p11' => 1, 'p12' => 3, 'foreign' => 9 ] );
		$this->assertSame( 'products', $out['_opf_type'] );
		$this->assertSame( [ 'p11' => 1, 'p12' => 3 ], $out['quantities'] );
		$this->assertSame( [ 'p11' ], $out['invalid'] ); // below per-choice min
		// Structured (stored) value passes straight through.
		$this->assertSame( $out, LinkedProducts::sanitize_value( $field, $out, true ) );
	}

	public function test_sanitize_qty_selector_category_mode_accepts_product_ids(): void {
		$field = $this->field( [
			'subtype' => 'card-qty',
			'product_selection' => 'category',
			'product_query' => [ 'query_id' => 3 ],
		] );
		$out = LinkedProducts::sanitize_value( $field, [ '11' => 2, 'garbage' => 1 ] );
		$this->assertSame( [ '11' => 2 ], $out['quantities'] );
	}

	/* ----------------------------------------------------------------- resolve */

	public function test_resolve_manual_qty_method_quantities(): void {
		$field = $this->field();
		$resolved = LinkedProducts::resolve( $field, [ 'p11', 'p12' ], 5, 42 );
		$this->assertSame( 'one', $resolved['qty_type'] );
		$this->assertSame( 1, $resolved['choices'][11]['qty'] );
		$this->assertSame( 1, $resolved['choices'][12]['qty'] );
		$this->assertSame( 'fixed', $resolved['choices'][11]['price_type'] );
		$this->assertSame( 'none', $resolved['choices'][12]['price_type'] );

		$resolved = LinkedProducts::resolve( $this->field( [ 'qty_method' => 'parent' ] ), [ 'p11' ], 5, 42 );
		$this->assertSame( 5, $resolved['choices'][11]['qty'] );
		$this->assertSame( 'qt', $resolved['choices'][11]['price_type'] );
	}

	public function test_resolve_rejects_unknown_slug_and_parent_product(): void {
		$field = $this->field( [ 'choices' => [ [ 'product_id' => 11 ], [ 'product_id' => 42 ] ] ] );
		$this->assertArrayHasKey( 'error', LinkedProducts::resolve( $field, [ 'nope' ], 1, 42 ) );
		$this->assertArrayHasKey( 'error', LinkedProducts::resolve( $field, [ 'p42' ], 1, 42 ) );
	}

	public function test_resolve_category_mode_uses_live_query(): void {
		$field = $this->field( [
			'product_selection' => 'category',
			'product_query' => [ 'query_id' => 3, 'limit' => 10 ],
		] );
		$resolved = LinkedProducts::resolve( $field, [ '11', '12' ], 1, 42 );
		$this->assertCount( 2, $resolved['choices'] );
		// Product outside the queried category is rejected.
		$this->assertArrayHasKey( 'error', LinkedProducts::resolve( $field, [ '14' ], 1, 42 ) );
	}

	public static function category_pricing_cases(): array {
		$cases = [];
		foreach ( [ 'fixed', 'none' ] as $price ) {
			foreach ( [ 'checkbox', 'radio', 'dropdown', 'image', 'card', 'vcard', 'card-qty', 'vcard-qty' ] as $subtype ) {
				foreach ( [ 'classic', 'store-api' ] as $transport ) {
					$cases[ "$price/$subtype/$transport" ] = [ $price, $subtype, $transport ];
				}
			}
		}
		return $cases;
	}

	#[DataProvider( 'category_pricing_cases' )]
	public function test_category_price_reaches_child_cart_order_and_order_again( string $price, string $subtype, string $transport ): void {
		$field = $this->field( [
			'subtype' => $subtype, 'qty_method' => 'parent', 'product_selection' => 'category',
			'product_query' => [ 'query_id' => 3, 'pricing_type' => $price ],
		] );
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = function () use ( $field ) {
			return [ $this->group_with( $field ) ];
		};
		$GLOBALS['opf_woocs_products'][42] = $this->parent_product();
		$is_qty = LinkedProducts::is_qty_subtype( $field );
		$payload = [ '77' => [ 'addons' => $is_qty ? [ '11' => 3 ] : '11' ] ];
		$_POST = [];
		$data = [];
		if ( 'classic' === $transport ) {
			$_POST['opf'] = $payload;
		} else {
			$GLOBALS['opf_submitted'] = $payload;
			$data = CartIntegration::capture_store_api( [], new \WP_REST_Request() )['cart_item_data'];
		}
		$this->assertTrue( CartIntegration::validate_add_to_cart( true, 42, 2, 0, [], $data ) );
		$data = CartIntegration::attach( $data, 42 );
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$cart->cart_contents['parentKey'] = [ 'key' => 'parentKey', 'product_id' => 42, 'quantity' => 2, 'data' => $GLOBALS['opf_woocs_products'][42] ] + $data;
		LinkedProducts::add_children( 'parentKey', 42, 2, 0, [], $data );
		LinkedProducts::apply_child_prices( $cart );
		$children = array_values( array_filter( $cart->get_cart(), [ LinkedProducts::class, 'is_child' ] ) );
		$this->assertCount( 1, $children );
		$child = $children[0];
		$expected_type = 'none' === $price ? 'none' : ( $is_qty ? 'nr' : 'qt' );
		$this->assertSame( $expected_type, $child[ LinkedProducts::CHILD_KEY ]['price_type'] );
		$this->assertSame( $is_qty ? 3 : 2, $child['quantity'] );
		$this->assertSame( 'none' === $price ? 0.0 : 8.0, (float) $child['data']->get_price() );

		$item = new LinkedTestOrderItem();
		LinkedProducts::persist_child_meta( $item, $child['key'], $child, null );
		$this->assertSame( $expected_type, $item->get_meta( '_opf_child_full' )['price_type'] );
		$restored = LinkedProducts::restore_child_order_again( [], $item, null );
		$this->assertSame( $child[ LinkedProducts::CHILD_KEY ], $restored[ LinkedProducts::CHILD_KEY ] );
		$restored_cart = [
			'newParent' => [ 'key' => 'newParent', 'old_cart_item_key' => 'parentKey' ],
			'newChild' => [ 'key' => 'newChild', 'quantity' => $child['quantity'], 'data' => new \WC_Product( [ 'price' => 8.0 ] ) ] + $restored,
		];
		LinkedProducts::remap_ordered_again( 1, [], $restored_cart );
		$this->assertSame( 'newParent', $restored_cart['newChild'][ LinkedProducts::CHILD_KEY ]['parent'] );
		$cart->cart_contents = $restored_cart;
		LinkedProducts::apply_child_prices( $cart );
		$this->assertSame( 'none' === $price ? 0.0 : 8.0, (float) $cart->cart_contents['newChild']['data']->get_price() );
	}

	/* ---------------------------------------------------------------- validate */

	public static function disabled_selection_cases(): array {
		$cases = [];
		foreach ( [ 'checkbox', 'radio', 'dropdown', 'image', 'card', 'vcard', 'card-qty', 'vcard-qty' ] as $subtype ) {
			foreach ( [ 'manual-authored', 'manual-filtered', 'category-filtered' ] as $source ) {
				foreach ( [ 'classic', 'store-api', 'order-again' ] as $transport ) {
					$cases[ "$subtype/$source/$transport" ] = [ $subtype, $source, $transport ];
				}
			}
		}
		return $cases;
	}

	#[DataProvider( 'disabled_selection_cases' )]
	public function test_disabled_child_selection_is_rejected_across_transports( string $subtype, string $source, string $transport ): void {
		$field = $this->field( [
			'subtype' => $subtype,
			'product_selection' => 'category-filtered' === $source ? 'category' : 'manual',
			'product_query' => [ 'query_id' => 3 ],
			'choices' => [
				[ 'product_id' => 11, 'disabled' => 'manual-authored' === $source ],
				[ 'product_id' => 12 ],
			],
		] );
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = function () use ( $field ) {
			return [ $this->group_with( $field ) ];
		};
		// Render-time filters can disable category and manual choices. A filter
		// must also never re-enable a choice marked unavailable by the author.
		$GLOBALS['opf_wpml_filters']['opf/linked_products/choice'] = static function ( $choice, $field, $product ) use ( $source ) {
			$choice['disabled'] = 'manual-authored' !== $source && 11 === $choice['product']->get_id();
			return $choice;
		};
		$GLOBALS['opf_woocs_products'][42] = $this->parent_product();
		$is_qty = LinkedProducts::is_qty_subtype( $field );
		$disabled_slug = 'category-filtered' === $source ? '11' : 'p11';
		$enabled_slug = 'category-filtered' === $source ? '12' : 'p12';

		foreach ( [ true, false ] as $select_disabled ) {
			if ( $is_qty ) {
				$value = [ $disabled_slug => $select_disabled ? 2 : 0, $enabled_slug => 1 ];
				if ( 'order-again' === $transport ) {
					$value = [ '_opf_type' => 'products', 'quantities' => $value, 'invalid' => [] ];
				}
			} else {
				$value = $select_disabled ? $disabled_slug : $enabled_slug;
			}
			$payload = [ '77' => [ 'addons' => $value ] ];
			$_POST = [];
			$GLOBALS['opf_notices'] = [];
			$data = [];
			if ( 'classic' === $transport ) {
				$_POST['opf'] = $payload;
			} elseif ( 'store-api' === $transport ) {
				$GLOBALS['opf_submitted'] = $payload;
				$data = CartIntegration::capture_store_api( [], new \WP_REST_Request() )['cart_item_data'];
				$data = CartIntegration::attach( $data, 42 );
			} else {
				$data[ CartIntegration::ITEM_KEY ] = $payload;
			}
			$this->assertSame( ! $select_disabled, CartIntegration::validate_add_to_cart( true, 42, 1, 0, [], $data ) );
			$this->assertCount( $select_disabled ? 1 : 0, $GLOBALS['opf_notices'] );
		}
	}

	public function test_validate_field_reports_required_and_errors(): void {
		$field = $this->field( [ 'required' => true ] );
		$errors = LinkedProducts::validate_field( $field, null, 1, $this->parent_product() );
		$this->assertSame( [ 'The field "Add-ons" is required.' ], $errors );
		$this->assertSame( [], LinkedProducts::validate_field( $this->field(), null, 1, $this->parent_product() ) );
		$this->assertSame( [], LinkedProducts::validate_field( $field, [ 'p11' ], 1, $this->parent_product() ) );
	}

	public function test_validate_field_reports_out_of_stock_children(): void {
		$field = $this->field( [ 'choices' => [ [ 'product_id' => 11 ], [ 'product_id' => 13 ] ] ] );
		$errors = LinkedProducts::validate_field( $field, [ 'p11', 'p13' ], 1, $this->parent_product() );
		$this->assertSame( [ 'The product "Poster" is no longer in stock.' ], $errors );
	}

	public function test_validate_field_qty_aggregate_and_stock_limits(): void {
		$field = $this->field( [
			'subtype' => 'card-qty', 'min_choices' => 2, 'max_choices' => 3,
			'choices' => [
				[ 'product_id' => 11, 'quantity' => [ 'min' => 0, 'max' => 5, 'default' => 0 ] ],
			],
		] );
		$value = [ '_opf_type' => 'products', 'quantities' => [ 'p11' => 1 ], 'invalid' => [] ];
		$errors = LinkedProducts::validate_field( $field, $value, 1, $this->parent_product() );
		$this->assertSame( [ '"Add-ons" requires a minimum of 2 total items.' ], $errors );
		$value['quantities']['p11'] = 4;
		$errors = LinkedProducts::validate_field( $field, $value, 1, $this->parent_product() );
		$this->assertSame( [ '"Add-ons" requires a maximum of 3 total items.' ], $errors );

		// Stock-limited child.
		$GLOBALS['opf_linked_products'][15] = new \WC_Product( [ 'id' => 15, 'name' => 'Limited', 'stock' => 2 ] );
		$field = $this->field( [
			'subtype' => 'card-qty',
			'choices' => [ [ 'product_id' => 15, 'quantity' => [ 'min' => 0, 'max' => 10, 'default' => 0 ] ] ],
		] );
		$errors = LinkedProducts::validate_field( $field, [ '_opf_type' => 'products', 'quantities' => [ 'p15' => 5 ], 'invalid' => [] ], 1, $this->parent_product() );
		$this->assertSame( [ 'The product "Limited" doesn\'t have enough stock. Please select a smaller quantity.' ], $errors );
	}

	/* ------------------------------------------------------------ cart lifecycle */

	public function test_add_children_inserts_native_child_lines(): void {
		$field = $this->field();
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = function () use ( $field ) {
			return [ $this->group_with( $field ) ];
		};
		$GLOBALS['opf_woocs_products'][42] = $this->parent_product();
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$cart->cart_contents['parentKey'] = [ 'key' => 'parentKey', 'product_id' => 42, 'quantity' => 1, 'data' => $GLOBALS['opf_woocs_products'][42] ];

		LinkedProducts::add_children( 'parentKey', 42, 1, 0, [], [
			CartIntegration::ITEM_KEY => [ '77' => [ 'addons' => [ 'p11', 'p12' ] ] ],
		] );

		$this->assertCount( 3, $cart->cart_contents );
		$children = array_values( array_filter( $cart->cart_contents, [ LinkedProducts::class, 'is_child' ] ) );
		$this->assertCount( 2, $children );
		$this->assertSame( 'parentKey', $children[0][ LinkedProducts::CHILD_KEY ]['parent'] );
		$this->assertSame( 'one', $children[0][ LinkedProducts::CHILD_KEY ]['qty_type'] );
		$this->assertSame( 'fixed', $children[0][ LinkedProducts::CHILD_KEY ]['price_type'] );
		$this->assertSame( 'none', $children[1][ LinkedProducts::CHILD_KEY ]['price_type'] );
		$this->assertSame( 1, $children[0]['quantity'] );
		// Child lines carry the add-on item key? No — they must NOT inherit opf_fields.
		foreach ( $children as $child ) {
			$this->assertArrayNotHasKey( CartIntegration::ITEM_KEY, $child );
		}
	}

	public function test_add_children_parent_qty_method_scales_children(): void {
		$field = $this->field( [ 'qty_method' => 'parent', 'choices' => [ [ 'product_id' => 11 ] ] ] );
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = function () use ( $field ) {
			return [ $this->group_with( $field ) ];
		};
		$GLOBALS['opf_woocs_products'][42] = $this->parent_product();
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$cart->cart_contents['parentKey'] = [ 'key' => 'parentKey', 'product_id' => 42, 'quantity' => 4, 'data' => $GLOBALS['opf_woocs_products'][42] ];

		LinkedProducts::add_children( 'parentKey', 42, 4, 0, [], [
			CartIntegration::ITEM_KEY => [ '77' => [ 'addons' => [ 'p11' ] ] ],
		] );
		$children = array_values( array_filter( $cart->cart_contents, [ LinkedProducts::class, 'is_child' ] ) );
		$this->assertSame( 4, $children[0]['quantity'] );
		$this->assertSame( 'parent', $children[0][ LinkedProducts::CHILD_KEY ]['qty_type'] );
		$this->assertSame( 'qt', $children[0][ LinkedProducts::CHILD_KEY ]['price_type'] );
	}

	public function test_add_children_qty_subtype_uses_submitted_quantities(): void {
		$field = $this->field( [
			'subtype' => 'card-qty',
			'choices' => [
				[ 'product_id' => 11, 'quantity' => [ 'min' => 0, 'max' => 10, 'default' => 0 ] ],
				[ 'product_id' => 12, 'quantity' => [ 'min' => 0, 'max' => 10, 'default' => 0 ] ],
			],
		] );
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = function () use ( $field ) {
			return [ $this->group_with( $field ) ];
		};
		$GLOBALS['opf_woocs_products'][42] = $this->parent_product();
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$cart->cart_contents['parentKey'] = [ 'key' => 'parentKey', 'product_id' => 42, 'quantity' => 1, 'data' => $GLOBALS['opf_woocs_products'][42] ];

		LinkedProducts::add_children( 'parentKey', 42, 1, 0, [], [
			CartIntegration::ITEM_KEY => [ '77' => [ 'addons' => [ '_opf_type' => 'products', 'quantities' => [ 'p11' => 3, 'p12' => 0 ], 'invalid' => [] ] ] ],
		] );
		$children = array_values( array_filter( $cart->cart_contents, [ LinkedProducts::class, 'is_child' ] ) );
		$this->assertCount( 1, $children );
		$this->assertSame( 3, $children[0]['quantity'] );
		$this->assertSame( 'nr', $children[0][ LinkedProducts::CHILD_KEY ]['price_type'] );
	}

	public function test_add_children_variation_resolves_parent_and_variation(): void {
		$field = $this->field( [ 'choices' => [ [ 'product_id' => 14 ] ] ] );
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = function () use ( $field ) {
			return [ $this->group_with( $field ) ];
		};
		$GLOBALS['opf_woocs_products'][42] = $this->parent_product();
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$cart->cart_contents['parentKey'] = [ 'key' => 'parentKey', 'product_id' => 42, 'quantity' => 1, 'data' => $GLOBALS['opf_woocs_products'][42] ];

		LinkedProducts::add_children( 'parentKey', 42, 1, 0, [], [
			CartIntegration::ITEM_KEY => [ '77' => [ 'addons' => [ 'p14' ] ] ],
		] );
		$added = end( $cart->added );
		$this->assertSame( 40, $added['product_id'] );   // variable parent id
		$this->assertSame( 14, $added['variation_id'] ); // the variation itself
	}

	public function test_orphaned_children_are_removed(): void {
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$cart->cart_contents['parentKey'] = [ 'key' => 'parentKey', 'quantity' => 1 ];
		$cart->cart_contents['childA'] = [ 'key' => 'childA', 'quantity' => 1, LinkedProducts::CHILD_KEY => [ 'parent' => 'parentKey' ] ];
		$cart->cart_contents['childB'] = [ 'key' => 'childB', 'quantity' => 1, LinkedProducts::CHILD_KEY => [ 'parent' => 'gone' ] ];
		LinkedProducts::remove_orphaned_children( $cart );
		$this->assertArrayHasKey( 'childA', $cart->cart_contents );
		$this->assertArrayNotHasKey( 'childB', $cart->cart_contents );
	}

	public function test_none_priced_children_are_zeroed(): void {
		$child_product = new \WC_Product( [ 'id' => 11, 'price' => 8.0 ] );
		$paid_product  = new \WC_Product( [ 'id' => 12, 'price' => 4.0 ] );
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$cart->cart_contents['free'] = [ 'key' => 'free', 'quantity' => 1, 'data' => $child_product, LinkedProducts::CHILD_KEY => [ 'parent' => 'p', 'price_type' => 'none' ] ];
		$cart->cart_contents['paid'] = [ 'key' => 'paid', 'quantity' => 1, 'data' => $paid_product, LinkedProducts::CHILD_KEY => [ 'parent' => 'p', 'price_type' => 'fixed' ] ];
		LinkedProducts::apply_child_prices( $cart );
		$this->assertSame( 0, $child_product->get_price() );
		$this->assertSame( 4.0, $paid_product->get_price() );
	}

	public function test_child_quantity_sync_mirrors_wapf_qty_types(): void {
		$field = $this->field( [ 'qty_method' => 'parent' ] );
		$GLOBALS['opf_wpml_filters']['opf_groups_for_product'] = function () use ( $field ) {
			return [ $this->group_with( $field ) ];
		};
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$parent = $this->parent_product();
		$cart->cart_contents['p'] = [ 'key' => 'p', 'quantity' => 3, 'product_id' => 42, 'data' => $parent, CartIntegration::ITEM_KEY => [ '77' => [ 'addons' => [ 'p11' ] ] ] ];
		$cart->cart_contents['c1'] = [ 'key' => 'c1', 'quantity' => 1, 'data' => new \WC_Product(), LinkedProducts::CHILD_KEY => [ 'parent' => 'p', 'qty_type' => 'one' ] ];
		$cart->cart_contents['c2'] = [ 'key' => 'c2', 'quantity' => 1, 'data' => new \WC_Product(), LinkedProducts::CHILD_KEY => [ 'parent' => 'p', 'qty_type' => 'parent' ] ];
		$cart->cart_contents['c3'] = [ 'key' => 'c3', 'quantity' => 2, 'data' => new \WC_Product(), LinkedProducts::CHILD_KEY => [ 'parent' => 'p', 'qty_type' => 'relative' ] ];
		$cart->cart_contents['c4'] = [ 'key' => 'c4', 'quantity' => 2, 'data' => new \WC_Product(), LinkedProducts::CHILD_KEY => [ 'parent' => 'p', 'qty_type' => 'custom' ] ];

		// Parent qty 3 → 6: relative doubles, parent tracks, one/custom stay.
		LinkedProducts::sync_child_quantities( 'p', 6, 3, $cart );
		$this->assertSame( 1, $cart->cart_contents['c1']['quantity'] );
		$this->assertSame( 6, $cart->cart_contents['c2']['quantity'] );
		$this->assertSame( 2 + ( 2 * 3 ), $cart->cart_contents['c3']['quantity'] ); // WAPF relative formula
		$this->assertSame( 2, $cart->cart_contents['c4']['quantity'] );
	}

	public function test_child_cart_controls_are_locked_down(): void {
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$child = [ 'key' => 'c', 'quantity' => 2, 'data' => new \WC_Product(), LinkedProducts::CHILD_KEY => [ 'parent' => 'p', 'qty_type' => 'one' ] ];
		// Non-child lines keep their remove link.
		$this->assertSame( '<a>x</a>', LinkedProducts::remove_link( '<a>x</a>', 'c' ) );
		$cart->cart_contents['c'] = $child;
		$this->assertSame( '', LinkedProducts::remove_link( '<a>x</a>', 'c' ) );
		$this->assertSame( '2', LinkedProducts::quantity_display( '<input>', 'c', $child ) );
		// 'custom' children keep editable quantity inputs.
		$child[ LinkedProducts::CHILD_KEY ]['qty_type'] = 'custom';
		$this->assertSame( '<input>', LinkedProducts::quantity_display( '<input>', 'c', $child ) );
	}

	public function test_child_item_data_shows_included_with_parent(): void {
		$cart = $GLOBALS['opf_repeat_test_cart'];
		$cart->cart_contents['p'] = [ 'key' => 'p', 'data' => $this->parent_product() ];
		$child = [ 'key' => 'c', 'data' => new \WC_Product(), LinkedProducts::CHILD_KEY => [ 'parent' => 'p' ] ];
		$cart->cart_contents['c'] = $child;
		$data = LinkedProducts::child_item_data( [], $child );
		$this->assertSame( 'Included with', $data[0]['key'] );
		$this->assertSame( 'Parent', $data[0]['value'] );
	}

	public function test_hide_flags_gate_child_visibility_per_surface(): void {
		$child = [ 'key' => 'c', LinkedProducts::CHILD_KEY => [ 'parent' => 'p', 'hide_cart' => true, 'hide_checkout' => true ] ];
		$GLOBALS['opf_current_filter'] = 'woocommerce_cart_item_visible';
		$this->assertFalse( LinkedProducts::child_visible( true, $child, 'c' ) );
		$GLOBALS['opf_current_filter'] = 'woocommerce_checkout_cart_item_visible';
		$this->assertFalse( LinkedProducts::child_visible( true, $child, 'c' ) );
		$child[ LinkedProducts::CHILD_KEY ]['hide_cart'] = false;
		$GLOBALS['opf_current_filter'] = 'woocommerce_cart_item_visible';
		$this->assertTrue( LinkedProducts::child_visible( true, $child, 'c' ) );
		// Non-child items pass through.
		$this->assertTrue( LinkedProducts::child_visible( true, [ 'key' => 'x' ], 'x' ) );
	}

	/* ------------------------------------------------------------------- order */

	public function test_child_order_meta_persists_and_hides_on_order(): void {
		$item = new LinkedTestOrderItem();
		LinkedProducts::persist_child_meta( $item, 'c', [
			LinkedProducts::CHILD_KEY => [ 'parent' => 'p', 'hide_order' => true, 'qty_type' => 'one' ],
		], null );
		$this->assertSame( [ 'hide_order' => true ], $item->meta[ LinkedProducts::CHILD_KEY ] );
		$this->assertSame( 'one', $item->meta['_opf_child_full']['qty_type'] );
		$this->assertFalse( LinkedProducts::order_item_visible( true, $item ) );
		// Admin always sees order lines.
		$GLOBALS['opf_is_admin'] = true;
		$this->assertTrue( LinkedProducts::order_item_visible( true, $item ) );
	}

	public function test_order_again_restores_and_remaps_children(): void {
		$child_item = new LinkedTestOrderItem( [ '_opf_child_full' => [ 'parent' => 'oldParentKey', 'qty_type' => 'one' ] ] );
		$data = LinkedProducts::restore_child_order_again( [], $child_item, null );
		$this->assertSame( 'oldParentKey', $data[ LinkedProducts::CHILD_KEY ]['parent'] );
		$this->assertTrue( $data['opf_order_again'] );

		$parent_item = new LinkedTestOrderItem( [ '_opf_fields' => '{}', '_opf_cart_item_key' => 'oldParentKey' ] );
		$data = LinkedProducts::restore_child_order_again( [], $parent_item, null );
		$this->assertSame( 'oldParentKey', $data['old_cart_item_key'] );
		$this->assertTrue( $data['opf_order_again'] );

		$cart = [
			'newParentKey' => [ 'key' => 'newParentKey', 'old_cart_item_key' => 'oldParentKey' ],
			'newChildKey'  => [ 'key' => 'newChildKey', LinkedProducts::CHILD_KEY => [ 'parent' => 'oldParentKey' ] ],
		];
		LinkedProducts::remap_ordered_again( 1, [], $cart );
		$this->assertSame( 'newParentKey', $cart['newChildKey'][ LinkedProducts::CHILD_KEY ]['parent'] );
	}

	/* ---------------------------------------------------------- display/render */

	public function test_display_value_lists_child_names_and_quantities(): void {
		$field = $this->field();
		$this->assertSame( 'Mug, Coaster', LinkedProducts::display_value( $field, [ 'p11', 'p12' ] ) );
		$qty_field = $this->field( [
			'subtype' => 'card-qty',
			'choices' => [
				[ 'product_id' => 11, 'quantity' => [ 'min' => 0, 'max' => 9, 'default' => 0 ] ],
				[ 'product_id' => 12, 'quantity' => [ 'min' => 0, 'max' => 9, 'default' => 0 ] ],
			],
		] );
		$this->assertSame(
			'Mug: 2',
			LinkedProducts::display_value( $qty_field, [ '_opf_type' => 'products', 'quantities' => [ 'p11' => 2, 'p12' => 0 ], 'invalid' => [] ] )
		);
	}

	public function test_product_choices_enrich_manual_choices(): void {
		$field = $this->field( [
			'subtype' => 'card',
			'incl_img' => true, 'incl_desc' => true,
			'slot_1' => 'price', 'slot_2' => 'stock', 'slot_3' => 'link',
		] );
		$choices = LinkedProducts::product_choices( $field, $this->parent_product() );
		$this->assertCount( 2, $choices );
		$this->assertSame( 'Mug', $choices[0]['label'] );
		$this->assertSame( 8.0, $choices[0]['pricing_amount'] );
		$this->assertSame( 'fixed', $choices[0]['pricing_type'] );
		$this->assertSame( 'none', $choices[1]['pricing_type'] );
		$this->assertSame( 0.0, $choices[1]['pricing_amount'] );
		$this->assertSame( '$8', $choices[0]['price'] );
		$this->assertSame( 'In stock', $choices[0]['stock'] );
		$this->assertSame( 'https://example.test/p/42', $choices[0]['link'] );
		$this->assertSame( 'Short', $choices[0]['desc'] );
	}

	public function test_product_choices_category_mode_excludes_parent_and_caps(): void {
		// Parent belongs to the queried category and must be excluded.
		$parent = new \WC_Product( [ 'id' => 42 ] );
		$GLOBALS['opf_linked_product_cats'][42] = 3;
		$GLOBALS['opf_linked_products'][42] = $parent;
		$field = $this->field( [
			'product_selection' => 'category',
			'product_query' => [ 'query_id' => 3, 'limit' => 1 ],
		] );
		$choices = LinkedProducts::product_choices( $field, $parent );
		$this->assertCount( 1, $choices ); // limit honoured
		$this->assertSame( 'fixed', $choices[0]['pricing_type'] );
		// The parent's own id never appears.
		$this->assertNotSame( 42, $choices[0]['product_id'] );
	}
}

final class LinkedTestCart extends \WC_Cart {
	public $cart_contents = [];
	public $added = [];
	public function get_cart(): array { return $this->cart_contents; }
	public function get_cart_item( string $key ): array { return $this->cart_contents[ $key ] ?? []; }
	public function set_quantity( string $key, $quantity, bool $refresh_totals = true ): void {
		$this->cart_contents[ $key ]['quantity'] = $quantity;
	}
	public function add_to_cart( $product_id, $quantity, $variation_id, $variation, $data ) {
		$key = 'child-' . count( $this->cart_contents );
		$this->added[] = [ 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => $quantity, 'data' => $data ];
		$this->cart_contents[ $key ] = [ 'key' => $key, 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => $quantity, 'data' => $GLOBALS['opf_linked_products'][ $variation_id ?: $product_id ] ?? new \WC_Product( [ 'id' => $variation_id ?: $product_id ] ) ] + $data;
		return $key;
	}
	public function remove_cart_item( string $key ): void { unset( $this->cart_contents[ $key ] ); }
}

final class LinkedTestOrderItem {
	public $meta = [];
	public function __construct( array $meta = [] ) { $this->meta = $meta; }
	public function add_meta_data( $key, $value, $unique = false ): void { $this->meta[ $key ] = $value; }
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
}

}
