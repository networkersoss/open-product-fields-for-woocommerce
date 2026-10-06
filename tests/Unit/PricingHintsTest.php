<?php
/**
 * WAPF-DISPLAY-PRICE-HINTS — per-value `pricing_hint` parity proof.
 *
 * Covers the WAPF Extended 3.1.5 contract:
 *  - Helper::format_pricing_hint (default `(+{x})`, sign collapse, percent
 *    on shop vs money on cart, fx `...` for the unpriced preview),
 *  - Helper::maybe_add_tax / adjust_addon_price / format_price (Woo display
 *    options, `woocommerce_tax_display_cart` incl/excl split, HTML-entity
 *    currency symbols like `&#36;`),
 *  - Cart::calculate_cart_item_prices value loop (per-value `calc_price`
 *    → `pricing_hint`, `hide_price_hint` suppression, zero-amount skip),
 *  - Helper::values_to_display_string (`label <span …>` in cart item_data)
 *    and values_to_simple_string(…, 'order') (`label (+$x)` raw order meta),
 *  - the `_wapf_meta` fields.*.values[] snapshot surface
 *    (API::field_snapshot_for_product `values` key).
 *
 * Runs in separate processes with a small WordPress/WooCommerce stub harness
 * (guarded definitions, `$GLOBALS['opf_test_options']` for settings and a
 * tiny filter registry) exactly like WapfHooksBridgeTest.
 */

namespace {
	if ( ! class_exists( 'WP_Hook' ) ) {
		class WP_Hook {
			public array $callbacks = [];
		}
	}
	if ( ! function_exists( 'opf_pricing_hint_hook_key' ) ) {
		function opf_pricing_hint_hook_key( $callback ): string {
			if ( is_array( $callback ) ) {
				$owner = is_object( $callback[0] ) ? spl_object_hash( $callback[0] ) : (string) $callback[0];
				return $owner . '::' . $callback[1];
			}
			return is_object( $callback ) ? spl_object_hash( $callback ) : (string) $callback;
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $tag, $callback, $priority = 10, $accepted = 1 ) {
			$key = opf_pricing_hint_hook_key( $callback );
			$GLOBALS['wp_hooks'][ $tag ][ (int) $priority ][ $key ] = [ 'cb' => $callback, 'accepted' => (int) $accepted ];
			ksort( $GLOBALS['wp_hooks'][ $tag ] );
			return true;
		}
		function add_action( $tag, $callback, $priority = 10, $accepted = 1 ) {
			return add_filter( $tag, $callback, $priority, $accepted );
		}
		function remove_filter( $tag, $callback, $priority = 10 ) {
			unset( $GLOBALS['wp_hooks'][ $tag ][ (int) $priority ][ opf_pricing_hint_hook_key( $callback ) ] );
			return true;
		}
		function apply_filters( $tag, $value, ...$args ) {
			$GLOBALS['opf_test_filter_calls'][ $tag ] = ( $GLOBALS['opf_test_filter_calls'][ $tag ] ?? 0 ) + 1;
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
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $key, $value, $autoload = false ) { $GLOBALS['opf_test_options'][ $key ] = $value; return true; }
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) { return $GLOBALS['opf_test_options'][ $name ] ?? $default; }
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ): string { return (string) $text; }
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $url ): string { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $value ): string { return strip_tags( (string) $value ); }
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
	}
	if ( ! function_exists( 'wp_list_pluck' ) ) {
		function wp_list_pluck( array $list, string $field ): array {
			return array_values( array_map( static fn( $item ) => is_array( $item ) ? ( $item[ $field ] ?? null ) : null, $list ) );
		}
	}
	if ( ! function_exists( 'is_cart' ) ) {
		function is_cart(): bool { return (bool) ( $GLOBALS['opf_test_is_cart'] ?? false ); }
	}
	if ( ! function_exists( 'is_checkout' ) ) {
		function is_checkout(): bool { return (bool) ( $GLOBALS['opf_test_is_checkout'] ?? false ); }
	}
	if ( ! function_exists( 'wp_doing_ajax' ) ) {
		function wp_doing_ajax(): bool { return false; }
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in(): bool { return false; }
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		function wp_get_current_user() { return (object) [ 'roles' => [] ]; }
	}
	if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
		function wc_get_product_term_ids( $product_id, $taxonomy ): array { return []; }
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $id ) { return $GLOBALS['opf_test_products'][ (int) $id ] ?? false; }
	}
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args = [] ): array { return []; }
	}
	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $key, $group = '' ) { return false; }
	}
	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $key, $data, $group = '', $expire = 0 ): bool { return true; }
	}
	if ( ! function_exists( 'wp_cache_supports' ) ) {
		function wp_cache_supports( $feature ): bool { return false; }
	}
	if ( ! function_exists( 'wp_cache_flush_group' ) ) {
		function wp_cache_flush_group( $group ): bool { return true; }
	}
	if ( ! function_exists( 'wp_get_referer' ) ) {
		function wp_get_referer() { return false; }
	}
	if ( ! function_exists( 'url_to_postid' ) ) {
		function url_to_postid( $url ): int { return 0; }
	}
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		function wc_get_page_id( $page ): int { return 0; }
	}

	/* ----------------------------------------------------- WooCommerce money */
	if ( ! function_exists( 'wc_get_price_decimals' ) ) {
		function wc_get_price_decimals(): int { return (int) ( $GLOBALS['opf_test_options']['woocommerce_price_num_decimals'] ?? 2 ); }
	}
	if ( ! function_exists( 'wc_get_price_decimal_separator' ) ) {
		function wc_get_price_decimal_separator(): string { return '.'; }
	}
	if ( ! function_exists( 'wc_get_price_thousand_separator' ) ) {
		function wc_get_price_thousand_separator(): string { return ','; }
	}
	if ( ! function_exists( 'get_woocommerce_price_format' ) ) {
		function get_woocommerce_price_format(): string { return '%1$s%2$s'; }
	}
	if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
		// WAPF's real `$` symbol: the HTML entity reaches raw order meta.
		function get_woocommerce_currency_symbol(): string { return '&#36;'; }
	}
	if ( ! function_exists( 'wc_trim_zeros' ) ) {
		function wc_trim_zeros( $price ) { return preg_replace( '/' . preg_quote( wc_get_price_decimal_separator(), '/' ) . '0+$/', '', (string) $price ); }
	}
	if ( ! function_exists( 'wc_price' ) ) {
		function wc_price( $price ) { return sprintf( get_woocommerce_price_format(), get_woocommerce_currency_symbol(), number_format( (float) $price, wc_get_price_decimals(), '.', ',' ) ); }
	}

	/* ------------------------------------------------------------------ tax */
	if ( ! function_exists( 'wc_tax_enabled' ) ) {
		function wc_tax_enabled(): bool { return (bool) ( $GLOBALS['opf_test_options']['opf_test_tax_enabled'] ?? false ); }
	}
	if ( ! function_exists( 'wc_get_price_including_tax' ) ) {
		function wc_get_price_including_tax( $product, $args = [] ) {
			$GLOBALS['opf_test_tax_calls']['incl'] = ( $GLOBALS['opf_test_tax_calls']['incl'] ?? 0 ) + 1;
			return (float) $args['price'] * ( 1 + (float) ( $GLOBALS['opf_test_options']['opf_test_tax_rate'] ?? 0.20 ) );
		}
	}
	if ( ! function_exists( 'wc_get_price_excluding_tax' ) ) {
		function wc_get_price_excluding_tax( $product, $args = [] ) {
			$GLOBALS['opf_test_tax_calls']['excl'] = ( $GLOBALS['opf_test_tax_calls']['excl'] ?? 0 ) + 1;
			$rate = (float) ( $GLOBALS['opf_test_options']['opf_test_tax_rate'] ?? 0.20 );
			// Prices entered incl. tax: strip it (WC excl semantics).
			return (float) $args['price'] / ( 1 + $rate );
		}
	}
	if ( ! function_exists( 'wc_get_price_to_display' ) ) {
		function wc_get_price_to_display( $product, $args = [] ) {
			$GLOBALS['opf_test_tax_calls']['to_display'] = ( $GLOBALS['opf_test_tax_calls']['to_display'] ?? 0 ) + 1;
			$rate = (float) ( $GLOBALS['opf_test_options']['opf_test_tax_rate'] ?? 0.20 );
			return 'incl' === ( $GLOBALS['opf_test_options']['woocommerce_tax_display_shop'] ?? 'excl' )
				? (float) $args['price'] * ( 1 + $rate )
				: (float) $args['price'] / ( 1 + $rate );
		}
	}
	if ( ! function_exists( 'wc_prices_include_tax' ) ) {
		function wc_prices_include_tax(): bool { return true; }
	}

	/* ----------------------------------------------------------------- stubs */
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			private array $data;
			public function __construct( array $data = [] ) {
				$this->data = array_merge( [ 'id' => 42, 'parent_id' => 0, 'price' => 20.0 ], $data );
			}
			public function get_id(): int { return (int) $this->data['id']; }
			public function get_parent_id(): int { return (int) $this->data['parent_id']; }
			public function get_price( $context = 'view' ) { return $this->data['price']; }
			public function set_price( $price ): void { $this->data['price'] = $price; }
			public function get_type(): string { return 'simple'; }
			public function is_taxable(): bool { return true; }
			public function get_tax_class( $context = 'view' ): string { return ''; }
		}
	}
	if ( ! class_exists( 'WC_Cart' ) ) {
		class WC_Cart {
			public array $cart_contents = [];
			public function get_cart(): array { return $this->cart_contents; }
		}
	}
	if ( ! class_exists( 'WC_Order' ) ) {
		class WC_Order {
			public function get_id(): int { return 501; }
		}
	}
	if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
		class WC_Order_Item_Product {
			public array $meta = [];
			private $product;
			private $quantity;
			public function __construct( $product = null, int $quantity = 1 ) {
				$this->product  = $product;
				$this->quantity = $quantity;
			}
			public function get_product() { return $this->product; }
			public function get_quantity(): int { return $this->quantity; }
			public function get_id(): int { return 7; }
			public function add_meta_data( $key, $value, $unique = false ): void { $this->meta[ $key ] = $value; }
			public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
		}
	}
}

namespace OPF\Tests\Unit {

	use OPF\API;
	use OPF\Engine\FieldGroup;
	use OPF\Service\CartIntegration;
	use OPF\Service\FieldGroups;
	use OPF\Service\PricingHints;
	use OPF\Service\Renderer;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
	use PHPUnit\Framework\TestCase;

	#[RunTestsInSeparateProcesses]
	#[PreserveGlobalState( false )]
	final class PricingHintsTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wp_hooks']              = [];
			$GLOBALS['opf_test_options']      = [];
			$GLOBALS['opf_test_filter_calls'] = [];
			$GLOBALS['opf_test_tax_calls']    = [];
			$GLOBALS['opf_test_is_cart']      = false;
			$GLOBALS['opf_test_is_checkout']  = false;
			$GLOBALS['opf_test_products']     = [ 42 => new \WC_Product( [ 'id' => 42, 'price' => 20.0 ] ) ];
			FieldGroups::flush_cache();
		}

		protected function tearDown(): void {
			FieldGroups::flush_cache();
			unset( $GLOBALS['wp'] );
		}

		private function product(): \WC_Product {
			return $GLOBALS['opf_test_products'][42];
		}

		/** Feed one field group through the public `opf_groups_for_product` filter. */
		private function group( array $fields ): void {
			$entry = [ 'id' => 77, 'title' => 'Group', 'lang' => '', 'group' => new FieldGroup( [ 'schema' => FieldGroup::SCHEMA, 'fields' => $fields ] ) ];
			add_filter( 'opf_groups_for_product', static function () use ( $entry ) {
				return [ $entry ];
			} );
		}

		private function cart_item( array $values, int $quantity = 1, float $base = 20.0 ): array {
			return [
				'key'            => 'line1',
				'product_id'     => 42,
				'quantity'       => $quantity,
				'data'           => $this->product(),
				'opf_base_price' => $base,
				CartIntegration::ITEM_KEY => $values,
			];
		}

		private function item_display( array $rows ): array {
			$by_name = [];
			foreach ( $rows as $row ) {
				$by_name[ $row['name'] ] = $row;
			}
			return $by_name;
		}

		/* ============================================ PricingHints::format ==== */

		public function test_default_format_wraps_money_in_plus_parens(): void {
			$this->assertSame( '(+{x})', PricingHints::hint_format(), 'WAPF default hint format.' );
			$this->assertSame( '(+&#36;5.00)', PricingHints::format( 'fixed', 5.0, $this->product(), 'cart' ) );
		}

		public function test_negative_amount_collapses_the_plus_placeholder(): void {
			// ar_sign='' removes the '+' from '(+{x})' → '(-$x)'.
			$this->assertSame( '(-&#36;2.50)', PricingHints::format( 'fixed', -2.5, $this->product(), 'cart' ) );
		}

		public function test_percent_is_money_on_cart_and_percent_on_shop(): void {
			// Cart context receives the CALCULATED contribution (WAPF calc_price).
			$this->assertSame( '(+&#36;2.00)', PricingHints::format( 'percent', 2.0, $this->product(), 'cart' ) );
			$this->assertSame( '(+10%)', PricingHints::format( 'percent', 10, $this->product(), 'shop' ) );
		}

		public function test_formula_type_uses_money_and_ellipsis_when_unpriced(): void {
			$this->assertSame( '(+&#36;7.50)', PricingHints::format( 'formula', 7.5, $this->product(), 'cart' ) );
			$this->assertSame( '(+...)', PricingHints::format( 'formula', '', $this->product(), 'cart' ) );
		}

		public function test_custom_hint_format_option(): void {
			$GLOBALS['opf_test_options']['opf_hint_format'] = '+{x} add-on';
			$this->assertSame( '+&#36;5.00 add-on', PricingHints::format( 'fixed', 5, $this->product(), 'cart' ) );
			$GLOBALS['opf_test_options']['opf_hint_format'] = '';
			$this->assertSame( '(+&#36;5.00)', PricingHints::format( 'fixed', 5, $this->product(), 'cart' ), 'Empty option must fall back to (+{x}).' );
		}

		public function test_show_price_hints_option_and_filter(): void {
			$this->assertTrue( PricingHints::enabled() );
			$GLOBALS['opf_test_options']['opf_show_price_hints'] = 'no';
			$this->assertFalse( PricingHints::enabled() );
			add_filter( 'opf_show_price_hints', static fn() => true );
			$this->assertTrue( PricingHints::enabled(), 'opf_show_price_hints filter must win over the option.' );
		}

		public function test_pricing_hint_filters_fire_with_wapf_argument_shape(): void {
			$seen = [];
			add_filter( 'opf_pricing_hint_format', static function ( $format, $product, $amount, $type ) use ( &$seen ) {
				$seen['format'] = [ $format, $amount, $type ];
				return $format;
			}, 10, 4 );
			add_filter( 'opf_pricing_hint_amount', static function ( $amount, $product, $type, $for_page ) {
				return $amount * 2;
			}, 10, 4 );
			add_filter( 'opf_pricing_hint', static function ( $hint, $product, $amount, $type, $field, $option ) use ( &$seen ) {
				$seen['hint'] = [ $hint, $amount, $type ];
				return $hint;
			}, 10, 6 );

			$hint = PricingHints::format( 'fixed', 5, $this->product(), 'cart' );
			$this->assertSame( '(+&#36;10.00)', $hint, 'opf_pricing_hint_amount must feed the formatted amount.' );
			$this->assertSame( [ '(+{x})', 5, 'fixed' ], $seen['format'] );
			$this->assertSame( [ '(+&#36;10.00)', 10, 'fixed' ], $seen['hint'] );
		}

		public function test_cart_tax_paths_use_woocommerce_tax_display_cart(): void {
			$GLOBALS['opf_test_options']['opf_test_tax_enabled'] = true;
			$GLOBALS['opf_test_options']['opf_test_tax_rate']    = 0.20;

			$GLOBALS['opf_test_options']['woocommerce_tax_display_cart'] = 'incl';
			$this->assertSame( '(+&#36;12.00)', PricingHints::format( 'fixed', 10.0, $this->product(), 'cart' ) );
			$this->assertSame( 1, $GLOBALS['opf_test_tax_calls']['incl'] ?? 0 );
			$this->assertSame( 0, $GLOBALS['opf_test_tax_calls']['excl'] ?? 0 );

			$GLOBALS['opf_test_options']['woocommerce_tax_display_cart'] = 'excl';
			// 10 incl → excl(10/1.2 = 8.333…) — the excl helper is picked.
			$this->assertSame( '(+&#36;8.33)', PricingHints::format( 'fixed', 10.0, $this->product(), 'cart' ) );
			$this->assertSame( 1, $GLOBALS['opf_test_tax_calls']['excl'] ?? 0 );
		}

		public function test_shop_context_uses_wc_get_price_to_display(): void {
			$GLOBALS['opf_test_options']['opf_test_tax_enabled']        = true;
			$GLOBALS['opf_test_options']['opf_test_tax_rate']           = 0.20;
			$GLOBALS['opf_test_options']['woocommerce_tax_display_shop'] = 'incl';
			$this->assertSame( '(+&#36;12.00)', PricingHints::format( 'fixed', 10.0, $this->product(), 'shop' ) );
			$this->assertSame( 1, $GLOBALS['opf_test_tax_calls']['to_display'] ?? 0 );
			$this->assertSame( 0, $GLOBALS['opf_test_tax_calls']['incl'] ?? 0 );
		}

		public function test_tax_disabled_and_percent_leave_amounts_raw(): void {
			$GLOBALS['opf_test_options']['opf_test_tax_enabled'] = false;
			$this->assertSame( '(+&#36;10.00)', PricingHints::format( 'fixed', 10.0, $this->product(), 'cart' ) );

			$GLOBALS['opf_test_options']['opf_test_tax_enabled'] = true;
			// Percent-derived amounts are already final: never tax-converted.
			$this->assertSame( '(+&#36;2.00)', PricingHints::format( 'percent', 2.0, $this->product(), 'cart' ) );
			$this->assertSame( 0, count( $GLOBALS['opf_test_tax_calls'] ), 'Percent and tax-disabled amounts must not touch tax helpers.' );
		}

		public function test_price_with_tax_and_addon_filters_fire(): void {
			add_filter( 'opf_pricing_price_with_tax', static fn( $out ) => $out + 1, 10, 4 );
			add_filter( 'opf_pricing_addon', static fn( $amount ) => $amount + 1, 10, 4 );
			// 5 → +1 (price_with_tax) → +1 (addon) = 7.
			$this->assertSame( '(+&#36;7.00)', PricingHints::format( 'fixed', 5.0, $this->product(), 'cart' ) );
		}

		/* =========================================== cart display surface ==== */

		public function test_cart_item_data_carries_per_value_span_hints(): void {
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'select', 'choices' => [
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
					[ 'slug' => 'silver', 'label' => 'Silver' ],
				] ],
				[ 'id' => 'engrave', 'label' => 'Engraving', 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ],
			] );

			$cart_item = $this->cart_item( [ '77' => [ 'material' => 'gold', 'engrave' => 'Monogram' ] ] );
			$rows      = $this->item_display( CartIntegration::display_item_data( [], $cart_item ) );

			$this->assertSame( 'Gold', $rows['Material']['value'], 'value stays the plain label join.' );
			$this->assertSame(
				'Gold <span class="opf-pricing-hint">(+&#36;5.00)</span>',
				$rows['Material']['display'],
				'WAPF values_to_display_string parity: label + <span>hint</span>.'
			);
			$this->assertSame(
				'Monogram <span class="opf-pricing-hint">(+&#36;2.00)</span>',
				$rows['Engraving']['display'],
				'Scalar field pricing hints attach to the entered value.'
			);
		}

		public function test_unpriced_and_zero_priced_values_emit_no_hint(): void {
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'select', 'choices' => [
					[ 'slug' => 'silver', 'label' => 'Silver' ],
					[ 'slug' => 'zero', 'label' => 'Zero', 'pricing' => [ 'type' => 'fixed', 'amount' => 0 ] ],
				] ],
				[ 'id' => 'note', 'label' => 'Note', 'type' => 'text' ],
			] );

			$rows = $this->item_display( CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'material' => 'silver', 'note' => 'hi' ] ] ) ) );
			$this->assertSame( '', $rows['Material']['display'], 'Unpriced selection keeps the historical empty display.' );
			$this->assertSame( '', $rows['Note']['display'] );

			$rows = $this->item_display( CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'material' => 'zero' ] ] ) ) );
			$this->assertSame( '', $rows['Material']['display'], 'WAPF skips price === 0 values.' );
		}

		public function test_multi_value_segments_get_independent_hints(): void {
			$this->group( [
				[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [
					[ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'fixed', 'amount' => 1 ] ],
					[ 'slug' => 'b', 'label' => 'B', 'pricing' => [ 'type' => 'fixed', 'amount' => 0.5 ] ],
					[ 'slug' => 'c', 'label' => 'C' ],
				] ],
			] );

			$rows = $this->item_display( CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'extras' => [ 'a', 'b', 'c' ] ] ] ) ) );
			$this->assertSame( 'A, B, C', $rows['Extras']['value'] );
			$this->assertSame(
				'A <span class="opf-pricing-hint">(+&#36;1.00)</span>, B <span class="opf-pricing-hint">(+&#36;0.50)</span>, C',
				$rows['Extras']['display'],
				'Each joined segment carries its own WAPF-style hint.'
			);
		}

		public function test_percent_and_fixed_hints_use_per_unit_cart_price(): void {
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'radio', 'choices' => [
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'percent', 'amount' => 10 ] ],
				] ],
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 4, 'per_unit' => false ] ],
			] );

			// qty 2, base 20: percent → 2.00 per unit; non-per-unit fixed 4 → 2.00/unit share.
			$rows = $this->item_display( CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'material' => 'gold', 'fee' => 'x' ] ], 2 ) ) );
			$this->assertSame( 'Gold <span class="opf-pricing-hint">(+&#36;2.00)</span>', $rows['Material']['display'] );
			$this->assertSame( 'x <span class="opf-pricing-hint">(+&#36;2.00)</span>', $rows['Fee']['display'] );
		}

		public function test_formula_hint_prices_the_evaluated_value(): void {
			$this->group( [
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => '[x]*2+3', 'per_unit' => true ] ],
			] );

			// [x]=4 → 4*2+3 = 11 per unit.
			$rows = $this->item_display( CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'fee' => '4' ] ] ) ) );
			$this->assertSame( '4 <span class="opf-pricing-hint">(+&#36;11.00)</span>', $rows['Fee']['display'] );
		}

		public function test_calculation_field_never_emits_a_value_hint(): void {
			$this->group( [
				[ 'id' => 'cost', 'label' => 'Cost', 'type' => 'calc', 'calc_type' => 'cost', 'formula' => '[price]*0.5', 'result_text' => 'Cost: {result}' ],
			] );

			// Stored cost-calc value (server-resolved 10 = 20 * 0.5).
			$rows = $this->item_display( CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'cost' => '10' ] ] ) ) );
			$this->assertSame( 'Cost: $10.00', $rows['Cost']['value'], 'The calc result text still displays.' );
			$this->assertSame( '', $rows['Cost']['display'], 'WAPF force-hides hints on cost calcs.' );
		}

		public function test_field_level_hide_price_hint_suppression(): void {
			// FieldGroup normalization does not yet carry `hide_price_hint`
			// (protected file) — prove the suppression seam honors both WAPF
			// spellings when the flag reaches runtime.
			$method = new \ReflectionMethod( CartIntegration::class, 'field_hint_suppressed' );
			$this->assertTrue( $method->invoke( null, [ 'type' => 'text', 'hide_price_hint' => true ] ) );
			$this->assertTrue( $method->invoke( null, [ 'type' => 'text', 'options' => [ 'hide_price_hint' => true ] ] ) );
			$this->assertTrue( $method->invoke( null, [ 'type' => 'calc' ] ), 'calc fields are always suppressed.' );
			$this->assertTrue( $method->invoke( null, [ 'type' => 'calculation' ] ) );
			$this->assertTrue( $method->invoke( null, [ 'type' => 'section' ] ) );
			$this->assertFalse( $method->invoke( null, [ 'type' => 'text' ] ) );
		}

		public function test_disabled_choice_never_hints(): void {
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'checkbox', 'choices' => [
					[ 'slug' => 'gone', 'label' => 'Gone', 'disabled' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
					[ 'slug' => 'live', 'label' => 'Live', 'pricing' => [ 'type' => 'fixed', 'amount' => 1 ] ],
				] ],
			] );

			$rows = $this->item_display( CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'material' => [ 'gone', 'live' ] ] ] ) ) );
			$this->assertSame(
				'Gone, Live <span class="opf-pricing-hint">(+&#36;1.00)</span>',
				$rows['Material']['display'],
				'Disabled choices display but never carry a hint (do_pricing skips them).'
			);
		}

		public function test_global_disable_keeps_display_plain(): void {
			$GLOBALS['opf_test_options']['opf_show_price_hints'] = 'no';
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'select', 'choices' => [
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				] ],
			] );

			$rows = $this->item_display( CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'material' => 'gold' ] ] ) ) );
			$this->assertSame( 'Gold', $rows['Material']['value'] );
			$this->assertSame( '', $rows['Material']['display'], 'opf_show_price_hints=no must suppress every cart hint.' );
		}

		/* ============================================ order meta surface ====== */

		public function test_order_meta_appends_plain_hints(): void {
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'select', 'choices' => [
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				] ],
				[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [
					[ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'fixed', 'amount' => 1 ] ],
					[ 'slug' => 'b', 'label' => 'B' ],
				] ],
			] );

			$cart_item  = $this->cart_item( [ '77' => [ 'material' => 'gold', 'extras' => [ 'a', 'b' ] ] ] );
			$order_item = new \WC_Order_Item_Product( $this->product(), 1 );
			CartIntegration::persist_order_item( $order_item, 'line1', $cart_item, new \WC_Order() );

			$this->assertSame( 'Gold (+&#36;5.00)', $order_item->meta['Material'], 'WAPF values_to_simple_string(order): plain label + hint, no span.' );
			$this->assertSame( 'A (+&#36;1.00), B', $order_item->meta['Extras'] );
		}

		public function test_order_meta_suppressed_field_stays_plain(): void {
			$GLOBALS['opf_test_options']['opf_show_price_hints'] = 'no';
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'select', 'choices' => [
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				] ],
			] );

			$order_item = new \WC_Order_Item_Product( $this->product(), 1 );
			CartIntegration::persist_order_item( $order_item, 'line1', $this->cart_item( [ '77' => [ 'material' => 'gold' ] ] ), new \WC_Order() );
			$this->assertSame( 'Gold', $order_item->meta['Material'] );
		}

		public function test_snapshot_carries_per_value_pricing_hints(): void {
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'select', 'choices' => [
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
					[ 'slug' => 'silver', 'label' => 'Silver' ],
				] ],
				[ 'id' => 'note', 'label' => 'Note', 'type' => 'text' ],
			] );

			$cart_item = $this->cart_item( [ '77' => [ 'material' => 'gold', 'note' => 'hi' ] ] );
			$snapshot  = API::field_snapshot_for_product( $this->product(), [ '77' => [ 'material' => 'gold', 'note' => 'hi' ] ], $cart_item );

			$by_id = [];
			foreach ( $snapshot as $record ) {
				$by_id[ $record['id'] ] = $record;
			}
			$this->assertSame( 'material', $by_id['material']['id'] );
			$this->assertSame( 'gold', $by_id['material']['value'], 'Existing value key is untouched.' );
			$this->assertSame(
				[ [ 'label' => 'Gold', 'slug' => 'gold', 'pricing_hint' => '(+&#36;5.00)' ] ],
				$by_id['material']['values'],
				'WAPF _wapf_meta fields.*.values[] parity: label/slug/pricing_hint per value.'
			);
			$this->assertSame(
				[ [ 'label' => 'hi', 'slug' => '', 'pricing_hint' => '' ] ],
				$by_id['note']['values'],
				'Unpriced values carry an empty pricing_hint.'
			);
		}

		public function test_snapshot_pricing_hint_empty_when_suppressed(): void {
			$GLOBALS['opf_test_options']['opf_show_price_hints'] = 'no';
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'select', 'choices' => [
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				] ],
			] );

			$snapshot = API::field_snapshot_for_product( $this->product(), [ '77' => [ 'material' => 'gold' ] ], $this->cart_item( [ '77' => [ 'material' => 'gold' ] ] ) );
			$this->assertSame( '', $snapshot[0]['values'][0]['pricing_hint'] );
		}

		/* ============================ repeats + image-quantity coverage ====== */

		public function test_repeat_field_rows_hint_per_row(): void {
			$this->group( [
				[ 'id' => 'name', 'label' => 'Name', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'label' => 'Name {n}' ], 'pricing' => [ 'type' => 'fixed', 'amount' => 3 ] ],
			] );

			$rows = CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'name' => [ 'Ann', 'Bo' ] ] ] ) );
			$names = array_map( static fn( $r ) => $r['display'], $rows );
			$this->assertContains( 'Ann <span class="opf-pricing-hint">(+&#36;3.00)</span>', $names );
			$this->assertContains( 'Bo <span class="opf-pricing-hint">(+&#36;3.00)</span>', $names );
		}

		public function test_image_quantity_hints_per_entered_count(): void {
			$this->group( [
				[ 'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity', 'choices' => [
					[ 'slug' => 'oak', 'label' => 'Oak', 'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ] ],
					[ 'slug' => 'pine', 'label' => 'Pine' ],
				] ],
			] );

			$value = [ '_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 2, 'pine' => 0 ], 'invalid' => [] ];
			$rows  = $this->item_display( CartIntegration::display_item_data( [], $this->cart_item( [ '77' => [ 'prints' => $value ] ] ) ) );
			$this->assertSame( 'Oak: 2', $rows['Prints']['value'] );
			// choice_addon(fixed 2, per_unit) → per-unit contribution 2.00.
			$this->assertSame( 'Oak: 2 <span class="opf-pricing-hint">(+&#36;2.00)</span>', $rows['Prints']['display'] );
		}

		/* ============ store product-price conversion: hint == charged line == */

		/**
		 * A currency plugin that converts product prices — the general Woo path
		 * CURCY uses (`woocommerce_product_get_price` → `wmc_get_price()`), which
		 * is also the path the converted cart line total takes.
		 */
		private function currency_plugin( float $rate ): void {
			add_filter( 'woocommerce_product_get_price', static fn( $price ) => (float) $price * $rate, 10, 2 );
		}

		public function test_cart_hint_follows_the_store_product_price_conversion(): void {
			$this->currency_plugin( 1.5 );
			// Fixed and formula amounts convert with the line the customer pays.
			$this->assertSame( '(+&#36;3.00)', PricingHints::format( 'fixed', 2.0, $this->product(), 'cart' ) );
			$this->assertSame( '(+&#36;63.00)', PricingHints::format( 'formula', 42.0, $this->product(), 'cart' ) );
			// Percent amounts never convert (WAPF adjust_addon_price early return).
			$this->assertSame( '(+&#36;1.00)', PricingHints::format( 'percent', 1.0, $this->product(), 'cart' ) );
		}

		public function test_no_currency_plugin_leaves_the_hint_byte_identical(): void {
			// No listener rewrites the product price: the hint keeps today's value.
			$this->assertSame( '(+&#36;2.00)', PricingHints::format( 'fixed', 2.0, $this->product(), 'cart' ) );
			$this->assertSame( '(+&#36;42.00)', PricingHints::format( 'formula', 42.0, $this->product(), 'cart' ) );
			$this->assertSame( '(+&#36;1.00)', PricingHints::format( 'percent', 1.0, $this->product(), 'cart' ) );
		}

		public function test_amount_adapter_conversion_is_not_repeated_by_the_store_path(): void {
			// WOOCS/Aelia convert on `wapf/html/pricing_hint/amount`; the store
			// price filter is present too. The amount must convert exactly once.
			$this->currency_plugin( 1.5 );
			add_filter( 'opf_pricing_hint_amount', static fn( $amount ) => (float) $amount * 1.5, 10, 4 );
			$this->assertSame( '(+&#36;3.00)', PricingHints::format( 'fixed', 2.0, $this->product(), 'cart' ) );
		}

		public function test_cart_display_and_order_meta_carry_the_converted_hint(): void {
			$this->currency_plugin( 1.5 );
			$this->group( [
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'select', 'choices' => [
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ],
				] ],
			] );

			$cart_item = $this->cart_item( [ '77' => [ 'material' => 'gold' ] ] );
			$rows      = $this->item_display( CartIntegration::display_item_data( [], $cart_item ) );
			$this->assertSame(
				'Gold <span class="opf-pricing-hint">(+&#36;3.00)</span>',
				$rows['Material']['display'],
				'The displayed cart hint is the converted amount the line charges.'
			);

			// WAPF stores the converted `pricing_hint` it computed for the cart,
			// so the persisted order meta carries the converted value too.
			$order_item = new \WC_Order_Item_Product( $this->product(), 1 );
			CartIntegration::persist_order_item( $order_item, 'line1', $cart_item, new \WC_Order() );
			$this->assertSame( 'Gold (+&#36;3.00)', $order_item->meta['Material'] );
			$this->assertStringContainsString( '"pricing_hint":"(+&#36;3.00)"', $order_item->meta['_opf_fields_snapshot'] );
		}

		public function test_storefront_hint_uses_the_same_conversion(): void {
			$this->currency_plugin( 1.5 );
			$this->assertSame(
				' <span class="opf-pricing-hint">+ &#36;3.00</span>',
				Renderer::pricing_hint_html( [ 'type' => 'fixed', 'amount' => 2 ], 10.0, $this->product() )
			);
		}

		public function test_hint_conversion_factor_is_the_store_price_ratio(): void {
			// No conversion: identity, so the module keeps the shop-currency value.
			$this->assertSame( 1.0, PricingHints::hint_conversion_factor( 10.0, $this->product() ) );
			$this->assertSame( 1.0, PricingHints::hint_conversion_factor( 0.0, $this->product() ), 'Zero reference degrades to identity.' );
			$this->assertSame( 1.0, PricingHints::hint_conversion_factor( 10.0, null ), 'No product context degrades to identity.' );

			$this->currency_plugin( 1.5 );
			$this->assertSame( 1.5, PricingHints::hint_conversion_factor( 10.0, $this->product() ) );
		}

		public function test_storefront_group_publishes_the_hint_conversion_factor(): void {
			$group = new FieldGroup( [ 'fields' => [
				[ 'id' => 'finish', 'type' => 'select', 'label' => 'Finish', 'choices' => [
					[ 'slug' => 'a', 'label' => 'a', 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ],
				] ],
			] ] );

			ob_start();
			Renderer::render_group( '77', 'Group', $group, 10.0, $this->product() );
			$plain = (string) ob_get_clean();
			$this->assertStringNotContainsString( 'data-opf-hint-conversion', $plain, 'No currency plugin → no extra markup.' );

			$this->currency_plugin( 1.5 );
			ob_start();
			Renderer::render_group( '77', 'Group', $group, 10.0, $this->product() );
			$converted = (string) ob_get_clean();
			$this->assertStringContainsString( 'data-opf-hint-conversion="1.5"', $converted );
			$this->assertStringContainsString( 'data-opf-product-price="10"', $converted, 'The preview base the totals depend on is unchanged.' );
		}
	}
}
