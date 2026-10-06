<?php
/**
 * Frontend product-image rules payload (`window.OPF_IMAGE_RULES` /
 * `window.OPF_IMAGE_RULE_MODES`).
 *
 * The builder's image-rules editor stores the OPF-native `image_rules` model
 * and the storefront reader (`assets/js/opf-frontend.js`) indexes it by the
 * group id it reads from `data-opf-group`. These tests pin the emitted payload
 * to that exact id and to the rule shape the reader consumes.
 *
 * The stubs are guarded so this file also runs standalone; in the full suite
 * the first-loaded definitions win (they are equivalent).
 */

namespace {
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $name, $value, ...$args ) { return $value; }
	}
	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public int $ID;
			public string $post_title;
			public string $post_content;

			public function __construct( int $id, string $title, string $content ) {
				$this->ID = $id;
				$this->post_title = $title;
				$this->post_content = $content;
			}
		}
	}
}

namespace OPF\Service {
	if ( ! function_exists( 'OPF\\Service\\get_posts' ) ) {
		// Kept behaviourally identical to the FieldGroupsAuthTest stub so this
		// file also runs standalone (see the file docblock).
		function get_posts( array $args = [] ): array {
			$posts  = $GLOBALS['opf_auth_test_posts'] ?? [];
			$status = $args['post_status'] ?? '';
			if ( ! $status || 'any' === $status ) {
				return $posts;
			}
			$statuses = array_map( 'strval', (array) $status );
			$by_id    = $GLOBALS['opf_auth_test_post_statuses'] ?? [];
			return array_values(
				array_filter(
					$posts,
					static function ( $post ) use ( $statuses, $by_id ): bool {
						$id = is_object( $post ) ? ( $post->ID ?? null ) : ( is_int( $post ) ? $post : null );
						if ( null === $id ) {
							return true;
						}
						return in_array( $by_id[ $id ] ?? 'publish', $statuses, true );
					}
				)
			);
		}
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
	use OPF\Service\Renderer;
	use PHPUnit\Framework\TestCase;

	final class ProductImageRulesPayloadTest extends TestCase {
		private const GROUP_ID = 15086;

		private $previous_options;
		private $previous_woocs;

		protected function setUp(): void {
			$this->previous_options = $GLOBALS['opf_test_options'] ?? [];
			$this->previous_woocs   = $GLOBALS['WOOCS'] ?? null;
			$GLOBALS['opf_test_options'] = [ 'opf_theme_compat' => 'no' ];
			unset( $GLOBALS['WOOCS'], $GLOBALS['opf_auth_test_posts'] );
			FieldGroups::flush_cache();
		}

		protected function tearDown(): void {
			unset( $GLOBALS['opf_auth_test_posts'] );
			$GLOBALS['opf_test_options'] = $this->previous_options;
			if ( null !== $this->previous_woocs ) {
				$GLOBALS['WOOCS'] = $this->previous_woocs;
			}
			FieldGroups::flush_cache();
		}

		public function test_rules_and_mode_are_emitted_under_the_rendered_group_id(): void {
			$group = $this->group_with_rules( 'last' );
			$this->store_group( $group );

			$scripts = $this->emit( [ (string) self::GROUP_ID => [ [ 'id' => 'color', 'type' => 'select' ] ] ] );

			$this->assertSame(
				[
					(string) self::GROUP_ID => [
						[ 'target_url' => '/red-any.png', 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => '*' ] ] ],
						[ 'target_url' => '/red-large.png', 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => 'large' ] ] ],
						[ 'target_url' => '/blue-any.png', 'conditions' => [ [ 'field' => 'color', 'value' => 'blue' ], [ 'field' => 'size', 'value' => '*' ] ] ],
					],
				],
				$this->emitted( $scripts, 'OPF_IMAGE_RULES' ),
				'Rules keep their authored order (last matching rule wins) and the reader shape {target_url,conditions:[{field,value}]}.'
			);
			$this->assertSame( [ (string) self::GROUP_ID => 'last' ], $this->emitted( $scripts, 'OPF_IMAGE_RULE_MODES' ) );

			// The frontend indexes both globals by the `data-opf-group` value.
			ob_start();
			Renderer::render_group( (string) self::GROUP_ID, 'Image rules', $group, 10.0 );
			$markup = (string) ob_get_clean();
			$this->assertStringContainsString( 'data-opf-group="' . self::GROUP_ID . '"', $markup );
		}

		public function test_group_without_rules_emits_no_image_rules_globals(): void {
			$this->store_group( $this->group_with_rules( '', false ) );

			$scripts = $this->emit( [ (string) self::GROUP_ID => [ [ 'id' => 'color', 'type' => 'select' ] ] ] );

			$this->assertNull( $this->emitted( $scripts, 'OPF_IMAGE_RULES' ) );
			$this->assertNull( $this->emitted( $scripts, 'OPF_IMAGE_RULE_MODES' ) );
			$this->assertSame( [ (string) self::GROUP_ID => [ [ 'id' => 'color', 'type' => 'select' ] ] ], $this->emitted( $scripts, 'OPF_FIELDS' ) );
		}

		public function test_rules_are_not_emitted_for_a_group_the_page_does_not_render(): void {
			$this->store_group( $this->group_with_rules() );

			$scripts = $this->emit( [ '999' => [ [ 'id' => 'color', 'type' => 'select' ] ] ] );

			$this->assertNull( $this->emitted( $scripts, 'OPF_IMAGE_RULES' ) );
		}

		public function test_mode_defaults_to_rules_when_the_group_does_not_opt_into_last(): void {
			$this->store_group( $this->group_with_rules() );

			$scripts = $this->emit( [ (string) self::GROUP_ID => [ [ 'id' => 'color', 'type' => 'select' ] ] ] );

			$this->assertSame( [ (string) self::GROUP_ID => 'rules' ], $this->emitted( $scripts, 'OPF_IMAGE_RULE_MODES' ) );
		}

		/**
		 * Group fixture. The select fields are the rule subjects, so the stored
		 * payload survives FieldGroup normalization unchanged.
		 *
		 * @param string $mode      `image_rule_mode` value ('' omits the key).
		 * @param bool   $with_rules Whether the group authors image rules.
		 */
		private function group_with_rules( string $mode = '', bool $with_rules = true ): FieldGroup {
			$data = [
				'fields' => [
					[ 'id' => 'color', 'label' => 'Color', 'type' => 'select', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ], [ 'slug' => 'green', 'label' => 'Green' ] ] ],
					[ 'id' => 'size', 'label' => 'Size', 'type' => 'select', 'choices' => [ [ 'slug' => 'small', 'label' => 'Small' ], [ 'slug' => 'large', 'label' => 'Large' ] ] ],
				],
			];
			if ( $with_rules ) {
				$data['image_rules'] = [
					[ 'target_url' => '/red-any.png', 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => '*' ] ] ],
					[ 'target_url' => '/red-large.png', 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => 'large' ] ] ],
					[ 'target_url' => '/blue-any.png', 'conditions' => [ [ 'field' => 'color', 'value' => 'blue' ], [ 'field' => 'size', 'value' => '*' ] ] ],
				];
			}
			if ( '' !== $mode ) {
				$data['image_rule_mode'] = $mode;
			}
			return new FieldGroup( $data );
		}

		/** Persist the group as FieldGroups::all() would read it from the CPT. */
		private function store_group( FieldGroup $group ): void {
			$GLOBALS['opf_auth_test_posts'] = [ new \WP_Post( self::GROUP_ID, 'Image rules', wp_json_encode( $group->data ) ) ];
			FieldGroups::flush_cache();
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
