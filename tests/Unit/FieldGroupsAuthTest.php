<?php

namespace {
	if ( ! function_exists( 'wp_cache_supports' ) ) {
		function wp_cache_supports( string $feature ): bool { return 'flush_group' === $feature; }
	}
	if ( ! function_exists( 'wp_cache_flush_group' ) ) {
		function wp_cache_flush_group( string $group ): bool { return \OPF\Service\wp_cache_flush_group( $group ); }
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in(): bool { return (bool) ( $GLOBALS['opf_auth_test_logged_in'] ?? false ); }
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
	if ( ! class_exists( 'WC_Product' ) ) {
		class WC_Product {
			public function get_parent_id(): int { return 0; }
			public function get_id(): int { return 42; }
		}
	}
}

namespace OPF\Service {
	function is_user_logged_in(): bool { return (bool) ( $GLOBALS['opf_auth_test_logged_in'] ?? false ); }
	function get_posts( array $args = [] ): array {
		$posts  = $GLOBALS['opf_auth_test_posts'] ?? [];
		$status = $args['post_status'] ?? '';
		if ( ! $status || 'any' === $status ) {
			return $posts;
		}
		// Emulate WP_Query's post_status filter so tests can assert which statuses
		// the caller asked for. Fixtures without a registered status count as
		// published, which keeps every existing fixture behaving as before.
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
	function wc_get_product_term_ids( int $product_id, string $taxonomy ): array { return []; }
	function wp_cache_get( $key, string $group = '' ) { return $GLOBALS['opf_auth_test_cache'][ $group ][ $key ] ?? false; }
	function wp_cache_set( $key, $value, string $group = '' ): bool { $GLOBALS['opf_auth_test_cache'][ $group ][ $key ] = $value; return true; }
	function wp_cache_flush_group( string $group ): bool { $GLOBALS['opf_auth_test_cache'][ $group ] = []; return true; }
}

namespace OPF\Tests\Unit {
	use OPF\Service\FieldGroups;
	use PHPUnit\Framework\TestCase;

	final class FieldGroupsAuthTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['opf_auth_test_logged_in'] = false;
			$GLOBALS['opf_auth_test_cache'] = [];
			$GLOBALS['opf_auth_test_posts'] = [
				self::post( 1, 'Everyone', [] ),
				self::post( 2, 'Members', [ 'subject' => 'user_auth', 'operator' => 'in', 'terms' => [ 'logged_in' ] ] ),
				self::post( 3, 'Guests', [ 'subject' => 'user_auth', 'operator' => 'not_in', 'terms' => [ 'logged_in' ] ] ),
			];
			FieldGroups::flush_cache();
		}

		public function test_product_group_cache_keeps_login_states_separate(): void {
			$product = new \WC_Product();
			$guest_titles = array_column( FieldGroups::for_product( $product ), 'title' );
			$GLOBALS['opf_auth_test_logged_in'] = true;
			$member_titles = array_column( FieldGroups::for_product( $product ), 'title' );

			$this->assertSame( [ 'Everyone', 'Guests' ], $guest_titles );
			$this->assertSame( [ 'Everyone', 'Members' ], $member_titles );
		}

		private static function post( int $id, string $title, array $rule = [] ): \WP_Post {
			$rules = $rule ? [ [ 'rules' => [ $rule + [ 'terms' => [] ] ] ] ] : [];
			$data = [ 'fields' => [ [ 'id' => 'note', 'label' => 'Note' ] ], 'rule_groups' => $rules ];
			return new \WP_Post( $id, $title, json_encode( $data ) );
		}
	}
}
