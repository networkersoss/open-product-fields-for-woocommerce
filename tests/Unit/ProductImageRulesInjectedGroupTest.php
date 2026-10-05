<?php
/**
 * Frontend product-image rules for hook-injected groups.
 *
 * `FieldGroups::all()` is the value handed to `opf_groups_for_product`, so a
 * group a WAPF add-on injects through `wapf/product_field_groups` is rendered —
 * markup and client registry — without ever being in it. The emitted
 * `window.OPF_IMAGE_RULES` payload must follow the ids the page rendered,
 * injected ones included, because the reader indexes it by `data-opf-group`.
 *
 * Runs in separate processes with its own guarded stub harness (the pattern
 * `PricingHintsTest` uses): the suite's shared `apply_filters` stubs read other
 * globals, so this file has to drive its own filter registry.
 */

namespace {
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $tag, $callback, $priority = 10, $accepted = 1 ): void {
			$GLOBALS['opf_injected_group_hooks'][ $tag ][ (int) $priority ][] = [ $callback, (int) $accepted ];
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $tag, $value, ...$args ) {
			foreach ( $GLOBALS['opf_injected_group_hooks'][ $tag ] ?? [] as $callbacks ) {
				foreach ( $callbacks as $hook ) {
					$value = call_user_func( $hook[0], $value, ...array_slice( $args, 0, $hook[1] - 1 ) );
				}
			}
			return $value;
		}
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
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			private int $id;

			public function __construct( int $id = 42 ) { $this->id = $id; }
			public function get_id(): int { return $this->id; }
			public function get_parent_id(): int { return 0; }
			public function get_type(): string { return 'simple'; }
		}
	}
}

namespace OPF\Service {
	if ( ! function_exists( 'OPF\\Service\\get_posts' ) ) {
		function get_posts( array $args = [] ): array { return []; }
	}
	if ( ! function_exists( 'OPF\\Service\\wp_enqueue_script' ) ) {
		function wp_enqueue_script( ...$args ): void {}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_enqueue_style' ) ) {
		function wp_enqueue_style( ...$args ): void {}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_print_inline_script_tag' ) ) {
		function wp_print_inline_script_tag( $javascript, $attributes = [] ): void {
			$GLOBALS['opf_inline_scripts'][] = $javascript;
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\Assets;
	use OPF\Service\FieldGroups;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
	use PHPUnit\Framework\TestCase;

	#[RunTestsInSeparateProcesses]
	#[PreserveGlobalState( false )]
	final class ProductImageRulesInjectedGroupTest extends TestCase {
		private const INJECTED_ID = 99001;

		protected function setUp(): void {
			$GLOBALS['opf_injected_group_hooks'] = [];
			$GLOBALS['opf_test_options']         = [ 'opf_theme_compat' => 'no' ];
			unset( $GLOBALS['WOOCS'] );
			FieldGroups::flush_cache();
		}

		protected function tearDown(): void {
			$GLOBALS['opf_injected_group_hooks'] = [];
			unset( $GLOBALS['opf_inline_scripts'] );
			FieldGroups::flush_cache();
		}

		public function test_rules_of_a_filter_injected_group_reach_the_frontend_payload(): void {
			$group = new FieldGroup(
				[
					'fields'          => [
						[ 'id' => 'size', 'label' => 'Size', 'type' => 'select', 'choices' => [ [ 'slug' => 'small', 'label' => 'Small' ], [ 'slug' => 'large', 'label' => 'Large' ] ] ],
					],
					'rule_groups'     => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '42' ] ] ] ] ],
					'image_rule_mode' => 'last',
					'image_rules'     => [ [ 'target_url' => '/injected-small.png', 'conditions' => [ [ 'field' => 'size', 'value' => 'small' ] ] ] ],
				]
			);
			$this->inject( $group );

			// The renderer resolves placement first, then emits for what it rendered.
			$groups = FieldGroups::for_product( new \WC_Product( 42 ) );
			$this->assertSame( [ self::INJECTED_ID ], array_column( $groups, 'id' ) );
			$this->assertSame( [], FieldGroups::all(), 'The injected group is not a stored group.' );

			$scripts = $this->emit( [ (string) self::INJECTED_ID => [ [ 'id' => 'size', 'type' => 'select' ] ] ] );

			$this->assertSame(
				[ (string) self::INJECTED_ID => [ [ 'target_url' => '/injected-small.png', 'conditions' => [ [ 'field' => 'size', 'value' => 'small' ] ] ] ] ],
				$this->emitted( $scripts, 'OPF_IMAGE_RULES' )
			);
			$this->assertSame( [ (string) self::INJECTED_ID => 'last' ], $this->emitted( $scripts, 'OPF_IMAGE_RULE_MODES' ) );
		}

		public function test_a_rule_less_injected_group_emits_no_image_rules_globals(): void {
			$this->inject(
				new FieldGroup( [ 'fields' => [ [ 'id' => 'size', 'label' => 'Size', 'type' => 'select', 'choices' => [ [ 'slug' => 'small', 'label' => 'Small' ] ] ] ] ] )
			);

			FieldGroups::for_product( new \WC_Product( 42 ) );
			$scripts = $this->emit( [ (string) self::INJECTED_ID => [ [ 'id' => 'size', 'type' => 'select' ] ] ] );

			$this->assertNull( $this->emitted( $scripts, 'OPF_IMAGE_RULES' ) );
			$this->assertNull( $this->emitted( $scripts, 'OPF_IMAGE_RULE_MODES' ) );
			$this->assertSame( [ (string) self::INJECTED_ID => [ [ 'id' => 'size', 'type' => 'select' ] ] ], $this->emitted( $scripts, 'OPF_FIELDS' ) );
		}

		/** Inject one entry the way a WAPF add-on does, through the public filter. */
		private function inject( FieldGroup $group ): void {
			add_filter(
				'opf_groups_for_product',
				static function () use ( $group ) {
					return [ [ 'id' => self::INJECTED_ID, 'title' => 'Injected image rules', 'lang' => '', 'group' => $group ] ];
				},
				10,
				2
			);
		}

		/** @param array<string,mixed> $registry */
		private function emit( array $registry ): array {
			$GLOBALS['opf_inline_scripts'] = [];
			Assets::enqueue_frontend( $registry );
			$scripts = $GLOBALS['opf_inline_scripts'];
			unset( $GLOBALS['opf_inline_scripts'] );
			return $scripts;
		}

		/**
		 * Decode one emitted global, or null when it was never printed.
		 *
		 * @param array<int,string> $scripts Printed inline scripts.
		 */
		private function emitted( array $scripts, string $name ): ?array {
			foreach ( $scripts as $script ) {
				if ( preg_match( '/window\.' . preg_quote( $name, '/' ) . ' = (.*?);(?:window\.|$)/s', $script, $match ) ) {
					return json_decode( $match[1], true );
				}
			}
			return null;
		}
	}
}
