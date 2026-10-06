<?php
/**
 * Field-group CPT registration and the published-only storefront query.
 *
 * Scheduled groups are native WordPress `future` posts: the admin list shows
 * them (core list table), and the storefront/evaluator source query must ask
 * for `publish` only so a scheduled group stays invisible until WordPress
 * flips it. Admin title search is core behavior that needs `title` support and
 * a `search_items` label on the CPT.
 */

namespace {
	if ( ! function_exists( 'register_post_type' ) ) {
		function register_post_type( $post_type, $args = [] ) {
			$GLOBALS['opf_registered_post_types'][ $post_type ] = $args;
			return (object) [ 'name' => $post_type ];
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Service\FieldGroups;
	use PHPUnit\Framework\TestCase;

	final class FieldGroupsRegistrationTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['opf_registered_post_types']   = [];
			$GLOBALS['opf_auth_test_cache']         = [];
			$GLOBALS['opf_auth_test_post_statuses'] = [];
			$GLOBALS['opf_pll_current']             = [];
			FieldGroups::flush_cache();
		}

		protected function tearDown(): void {
			unset(
				$GLOBALS['opf_registered_post_types'],
				$GLOBALS['opf_auth_test_posts'],
				$GLOBALS['opf_auth_test_post_statuses'],
				$GLOBALS['opf_pll_current']
			);
			FieldGroups::flush_cache();
		}

		public function test_group_cpt_registers_title_support_and_the_search_label(): void {
			FieldGroups::register_cpt();

			$args = $GLOBALS['opf_registered_post_types']['opf_field_group'] ?? null;
			$this->assertIsArray( $args );
			$this->assertTrue( $args['show_ui'], 'the group CPT renders a core admin list' );
			$this->assertSame( [ 'title' ], $args['supports'], 'core title search needs title support' );
			$this->assertSame( 'Search Field Groups', $args['labels']['search_items'] );
			$this->assertSame( 'Field Groups', $args['labels']['name'] );
		}

		public function test_storefront_query_returns_published_groups_only(): void {
			$GLOBALS['opf_auth_test_posts'] = [
				self::group_post( 11, 'Live' ),
				self::group_post( 12, 'Scheduled' ),
				self::group_post( 13, 'Draft' ),
			];
			// WordPress-side status of each fixture: only 11 is published.
			$GLOBALS['opf_auth_test_post_statuses'] = [ 12 => 'future', 13 => 'draft' ];
			FieldGroups::flush_cache();

			// The shared get_posts() stub has to honour post_status, otherwise the
			// assertions below would be vacuous.
			$this->assertCount( 3, \OPF\Service\get_posts( [] ) );
			$this->assertCount( 1, \OPF\Service\get_posts( [ 'post_status' => 'publish' ] ) );

			$this->assertSame( [ 'Live' ], array_column( FieldGroups::all(), 'title' ) );
			$this->assertSame(
				[ 'Live' ],
				array_column( FieldGroups::for_product( new \WC_Product() ), 'title' ),
				'the storefront resolver never sees a scheduled or draft group'
			);
		}

		public function test_storefront_query_returns_a_published_group_after_it_is_scheduled_through(): void {
			$GLOBALS['opf_auth_test_posts']         = [ self::group_post( 12, 'Scheduled' ) ];
			$GLOBALS['opf_auth_test_post_statuses'] = [ 12 => 'future' ];
			FieldGroups::flush_cache();
			$this->assertSame( [], array_column( FieldGroups::all(), 'title' ) );

			// WordPress's future-publish callback flips the post to publish.
			$GLOBALS['opf_auth_test_post_statuses'][12] = 'publish';
			FieldGroups::flush_cache();
			$this->assertSame( [ 'Scheduled' ], array_column( FieldGroups::all(), 'title' ) );
		}

		private static function group_post( int $id, string $title ): \WP_Post {
			$data = [ 'fields' => [ [ 'id' => 'note', 'type' => 'text', 'label' => $title ] ] ];
			return new \WP_Post( $id, $title, (string) json_encode( $data ) );
		}
	}
}
