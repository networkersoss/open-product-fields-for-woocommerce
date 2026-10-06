<?php
/**
 * Quick-view asset shipping (Service/QuickView.php).
 *
 * The frontend module is otherwise enqueued only by the request that renders
 * fields, so without this service an archive page whose quick view injects the
 * product markup through Ajax ships field markup with no runtime. These tests
 * pin the surface detection (including the "no product loop, no bytes" rule)
 * and the handles the detected surface ships.
 *
 * The class runs process-isolated: it stubs the third-party theme readers
 * (`astra_get_option`, `woodmart_get_opt`) and the WordPress script registry,
 * and those names must not leak into the other suites.
 */

namespace {
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $name, $value, ...$args ) { return $value; }
	}
	if ( ! function_exists( 'admin_url' ) ) {
		function admin_url( $path = '' ): string { return 'https://shop.test/wp-admin/' . ltrim( (string) $path, '/' ); }
	}
	if ( ! function_exists( 'get_woocommerce_currency' ) ) {
		function get_woocommerce_currency(): string { return 'USD'; }
	}
	if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
		function get_woocommerce_currency_symbol(): string { return '&#36;'; }
	}
	if ( ! function_exists( 'wc_get_price_thousand_separator' ) ) {
		function wc_get_price_thousand_separator(): string { return ','; }
	}
	if ( ! function_exists( 'wc_get_price_decimal_separator' ) ) {
		function wc_get_price_decimal_separator(): string { return '.'; }
	}
	if ( ! function_exists( 'wc_get_price_decimals' ) ) {
		function wc_get_price_decimals(): int { return 2; }
	}
	if ( ! function_exists( 'get_woocommerce_price_format' ) ) {
		function get_woocommerce_price_format(): string { return '%1$s%2$s'; }
	}
	if ( ! function_exists( 'wp_script_is' ) ) {
		function wp_script_is( $handle, $list = 'enqueued' ): bool {
			return in_array( $handle, (array) ( $GLOBALS['opf_quick_view_runtime'] ?? [] ), true );
		}
	}
	if ( ! function_exists( 'get_template' ) ) {
		function get_template(): string { return (string) ( $GLOBALS['opf_quick_view_env']['template'] ?? '' ); }
	}
	if ( ! function_exists( 'get_theme_mod' ) ) {
		function get_theme_mod( $name, $default = false ) {
			return $GLOBALS['opf_quick_view_env']['theme_mods'][ $name ] ?? $default;
		}
	}
	if ( ! function_exists( 'astra_get_option' ) ) {
		function astra_get_option( $name, $default = false ) {
			return $GLOBALS['opf_quick_view_env']['astra'][ $name ] ?? $default;
		}
	}
	if ( ! function_exists( 'woodmart_get_opt' ) ) {
		function woodmart_get_opt( $name, $default = false ) {
			return $GLOBALS['opf_quick_view_env']['woodmart'][ $name ] ?? $default;
		}
	}
	if ( ! function_exists( 'is_woocommerce' ) ) {
		function is_woocommerce(): bool { return (bool) ( $GLOBALS['opf_quick_view_env']['is_woocommerce'] ?? false ); }
	}
	if ( ! function_exists( 'is_cart' ) ) {
		function is_cart(): bool { return (bool) ( $GLOBALS['opf_quick_view_env']['is_cart'] ?? false ); }
	}
	if ( ! function_exists( 'get_queried_object' ) ) {
		function get_queried_object() { return $GLOBALS['opf_quick_view_env']['queried'] ?? null; }
	}
	if ( ! function_exists( 'has_shortcode' ) ) {
		function has_shortcode( $content, $tag ): bool {
			return false !== strpos( (string) $content, '[' . $tag );
		}
	}
	if ( ! function_exists( 'has_block' ) ) {
		function has_block( $name, $post = null ): bool {
			$content = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';
			return false !== strpos( $content, 'wp:' . $name );
		}
	}
	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public int $ID;
			public string $post_title;
			public string $post_content;

			public function __construct( int $id = 0, string $title = '', string $content = '' ) {
				$this->ID = $id;
				$this->post_title = $title;
				$this->post_content = $content;
			}
		}
	}
}

namespace OPF\Service {
	if ( ! function_exists( 'OPF\Service\get_posts' ) ) {
		function get_posts( array $args = [] ): array { return []; }
	}
	if ( ! function_exists( 'OPF\\Service\\wp_enqueue_script' ) ) {
		function wp_enqueue_script( ...$args ): void {
			$GLOBALS['opf_quick_view_scripts'][] = (string) $args[0];
		}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_enqueue_style' ) ) {
		function wp_enqueue_style( ...$args ): void {
			$GLOBALS['opf_quick_view_styles'][] = (string) $args[0];
		}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_print_inline_script_tag' ) ) {
		function wp_print_inline_script_tag( $javascript, $attributes = [] ): void {
			$GLOBALS['opf_quick_view_inline'][] = (string) $javascript;
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Service\Assets;
	use OPF\Service\FieldGroups;
	use OPF\Service\QuickView;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
	use PHPUnit\Framework\TestCase;

	#[RunTestsInSeparateProcesses]
	#[PreserveGlobalState( false )]
	final class QuickViewAssetsTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['opf_quick_view_env']     = [ 'theme_mods' => [], 'astra' => [], 'woodmart' => [] ];
			$GLOBALS['opf_quick_view_scripts'] = [];
			$GLOBALS['opf_quick_view_styles']  = [];
			$GLOBALS['opf_quick_view_inline']  = [];
			$GLOBALS['opf_quick_view_runtime'] = [];
			$GLOBALS['opf_test_options']       = [ 'opf_theme_compat' => 'no' ];
			FieldGroups::flush_cache();
		}

		/**
		 * A WooCommerce page, i.e. a request that renders a product loop.
		 *
		 * @param array<string,mixed> $env Environment overrides.
		 */
		private function shop_page( array $env = [] ): void {
			$GLOBALS['opf_quick_view_env']['is_woocommerce'] = true;
			$GLOBALS['opf_quick_view_env']                   = array_merge( $GLOBALS['opf_quick_view_env'], $env );
		}

		public function test_a_page_without_a_quick_view_surface_ships_nothing(): void {
			QuickView::maybe_enqueue();

			$this->assertSame( '', QuickView::surface() );
			$this->assertSame( [], $GLOBALS['opf_quick_view_scripts'] );
			$this->assertSame( [], $GLOBALS['opf_quick_view_styles'] );
			$this->assertSame( [], $GLOBALS['opf_quick_view_inline'] );
		}

		public function test_barn2_quick_view_pro_runtime_enqueued_is_the_exact_signal(): void {
			// The Barn2 runtime loads during the loop on a page that is not a
			// WooCommerce archive (the `[products]` shortcode page).
			$GLOBALS['opf_quick_view_runtime'][] = QuickView::BARN2_HANDLE;

			$this->assertSame( 'barn2', QuickView::surface() );

			QuickView::maybe_enqueue();
			$this->assertSame( [ 'opf-frontend', 'opf-quick-view' ], $GLOBALS['opf_quick_view_scripts'] );
			$this->assertSame( [ 'opf-frontend' ], $GLOBALS['opf_quick_view_styles'] );
		}

		public function test_barn2_enqueue_is_idempotent_across_both_hooks(): void {
			// `wc_quick_view_pro_load_scripts` (loop) and `wp_enqueue_scripts`
			// (archive) can both reach the service in one request.
			QuickView::enqueue();
			QuickView::enqueue();
			QuickView::maybe_enqueue();

			$this->assertSame( [ 'opf-frontend', 'opf-quick-view' ], $GLOBALS['opf_quick_view_scripts'] );
		}

		public function test_astra_pro_quick_view_on_a_shop_page_selects_astra(): void {
			$this->shop_page( [ 'astra' => [ 'shop-quick-view-enable' => 'on-image' ] ] );

			$this->assertSame( 'astra', QuickView::surface() );
		}

		public function test_a_disabled_astra_quick_view_ships_nothing(): void {
			$this->shop_page( [ 'astra' => [ 'shop-quick-view-enable' => 'disabled' ] ] );

			$this->assertSame( '', QuickView::surface() );
		}

		public function test_a_cart_page_counts_as_a_loop_for_its_cross_sells(): void {
			$this->shop_page( [ 'is_woocommerce' => false, 'is_cart' => true, 'astra' => [ 'shop-quick-view-enable' => 'on-image' ] ] );

			$this->assertSame( 'astra', QuickView::surface() );
		}

		public function test_a_theme_quick_view_without_a_product_loop_ships_nothing(): void {
			// A plain content page: no loop, so no Astra/Flatsome/Woodmart
			// quick-view button can render.
			$this->shop_page( [
				'is_woocommerce' => false,
				'astra'   => [ 'shop-quick-view-enable' => 'on-image' ],
				'queried' => new \WP_Post( 5, 'About', '<p>Nothing to buy here.</p>' ),
			] );

			$this->assertSame( '', QuickView::surface() );
		}

		public function test_a_page_embedding_a_products_shortcode_counts_as_a_loop(): void {
			$this->shop_page( [
				'is_woocommerce' => false,
				'astra'   => [ 'shop-quick-view-enable' => 'after-summary' ],
				'queried' => new \WP_Post( 6, 'Quick View Shop', '[products limit="12" columns="2"]' ),
			] );

			$this->assertSame( 'astra', QuickView::surface() );
		}

		public function test_a_page_embedding_a_product_collection_block_counts_as_a_loop(): void {
			$this->shop_page( [
				'is_woocommerce' => false,
				'queried' => new \WP_Post( 7, 'Grid', '<!-- wp:woocommerce/product-collection {"query":{"postType":"product"}} /-->' ),
				'woodmart' => [ 'quick_view' => true ],
			] );

			$this->assertSame( 'woodmart', QuickView::surface() );
		}

		public function test_flatsome_honours_the_disable_quick_view_theme_mod(): void {
			$this->shop_page( [ 'template' => 'flatsome', 'theme_mods' => [ 'disable_quick_view' => 0 ] ] );
			$this->assertSame( 'flatsome', QuickView::surface() );

			$GLOBALS['opf_quick_view_env']['theme_mods']['disable_quick_view'] = 1;
			$this->assertSame( '', QuickView::surface() );
		}

		public function test_woodmart_quick_view_option_selects_woodmart(): void {
			$this->shop_page( [ 'woodmart' => [ 'quick_view' => true ] ] );
			$this->assertSame( 'woodmart', QuickView::surface() );

			$GLOBALS['opf_quick_view_env']['woodmart']['quick_view'] = false;
			$this->assertSame( '', QuickView::surface() );
		}

		public function test_quick_view_page_prints_the_price_config_the_modal_needs(): void {
			// The modal has no page-level registry, but it still needs the store
			// currency/formatting. The product-scoped base price must stay out of it: a
			// price captured from the loop would outrank the injected group's own
			// data-opf-product-price, so the renderer that follows on the same request
			// still prints the full config.
			$GLOBALS['opf_test_options']['opf_theme_compat'] = 'yes';
			$this->shop_page( [ 'astra' => [ 'shop-quick-view-enable' => 'on-image' ] ] );

			QuickView::maybe_enqueue();
			Assets::enqueue_frontend( [ '18' => [ [ 'id' => 'finish', 'type' => 'select' ] ] ] );

			$configs = array_values( array_filter( $GLOBALS['opf_quick_view_inline'], static fn( $script ) => str_contains( $script, 'window.opf_config = ' ) ) );
			$this->assertCount( 2, $configs, 'the quick-view config and the renderer config both ship' );
			$quick_view = json_decode( substr( $configs[0], strlen( 'window.opf_config = ' ), -1 ), true );
			$renderer   = json_decode( substr( $configs[1], strlen( 'window.opf_config = ' ), -1 ), true );
			$this->assertArrayNotHasKey( 'product_base_price', $quick_view, 'the quick-view config is not product-scoped' );
			$this->assertArrayNotHasKey( 'formula_base_price', $quick_view );
			$this->assertSame( 'USD', $quick_view['currency'], 'the store currency still ships for the modal totals' );
			$this->assertArrayHasKey( 'display_options', $quick_view );
			$this->assertSame( 'USD', $renderer['currency'] );
			$this->assertArrayHasKey( 'ajax', $renderer );
			$registry_tags = array_filter( $GLOBALS['opf_quick_view_inline'], static fn( $script ) => str_contains( $script, 'window.OPF_FIELDS = ' ) );
			$this->assertCount( 1, $registry_tags, 'the renderer prints the page registry' );
		}
	}
}
