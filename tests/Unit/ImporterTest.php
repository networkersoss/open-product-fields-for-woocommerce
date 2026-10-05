<?php

namespace {
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args = [] ): array {
			return [];
		}
	}
	if ( ! function_exists( 'get_post' ) ) {
		function get_post( $post_id ) {
			$source = $GLOBALS['opf_importer_test']['source_product'] ?? null;
			if ( is_object( $source ) && (int) $source->ID === (int) $post_id ) {
				return $source;
			}
			return $GLOBALS['opf_importer_test']['existing_post'] ?? null;
		}
	}
	if ( ! function_exists( 'get_post_field' ) ) {
		function get_post_field( $field, $post_id ) {
			$post = get_post( $post_id );
			return $post->$field ?? '';
		}
	}
	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( $post_id, $key, $single = false ) {
			return $GLOBALS['opf_importer_test']['source_meta'][ $post_id ][ $key ] ?? '';
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0 ) {
			return json_encode( $data, $options );
		}
	}
	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'wp_insert_post' ) ) {
		function wp_insert_post( $postarr, $wp_error = false ): int {
			$GLOBALS['opf_importer_test']['posts'][] = $postarr;
			return 501;
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return false;
		}
	}
	if ( ! function_exists( 'wp_cache_flush_group' ) ) {
		function wp_cache_flush_group( $group ): bool {
			return true;
		}
	}
	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( $post_id, $key, $value ): bool {
			$GLOBALS['opf_importer_test']['meta'][ $post_id ][ $key ] = $value;
			return true;
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\FieldGroups;
	use OPF\Service\Importer;
	use PHPUnit\Framework\TestCase;

	final class ImporterTest extends TestCase {

		public function test_groups_needing_review_are_imported_as_drafts(): void {
			$report = $this->run_import(
				[
					$this->text_field( 'name', 'Name' ),
					[ 'id' => 'colour', 'label' => 'Colour', 'type' => 'color' ],
				],
			);

			$this->assertSame( 1, $report['imported'] );
			$this->assertTrue( $report['groups'][0]['needs_review'] );
			$this->assertSame( 'draft', $report['groups'][0]['post_status'] );
			$this->assertSame( 'draft', $GLOBALS['opf_importer_test']['posts'][0]['post_status'] );
			$this->assertArrayHasKey( '_opf_needs_review', $GLOBALS['opf_importer_test']['meta'][501] );
		}

		public function test_fully_mapped_groups_remain_publishable(): void {
			$report = $this->run_import( [ $this->text_field( 'name', 'Name' ) ] );

			$this->assertFalse( $report['groups'][0]['needs_review'] );
			$this->assertSame( 'publish', $report['groups'][0]['post_status'] );
			$this->assertSame( 'publish', $GLOBALS['opf_importer_test']['posts'][0]['post_status'] );
		}

		public function test_saving_an_existing_review_draft_keeps_it_draft(): void {
			$GLOBALS['opf_importer_test'] = [
				'posts'         => [],
				'meta'          => [],
				'existing_post' => (object) [ 'post_status' => 'draft', 'post_title' => 'Needs review' ],
			];
			$group = new FieldGroup( [ 'fields' => [ $this->text_field( 'name', 'Name' ) ] ] );

			$this->assertSame( 501, FieldGroups::save( 501, $group, [ 'title' => 'Needs review' ] ) );
			$this->assertSame( 'draft', $GLOBALS['opf_importer_test']['posts'][0]['post_status'] );
		}

		public function test_dry_run_reports_review_draft_without_writing(): void {
			$report = $this->run_import(
				[ $this->text_field( 'name', 'Name' ), [ 'id' => 'colour', 'label' => 'Colour', 'type' => 'color' ] ],
				false
			);

			$this->assertSame( 'dry-run', $report['mode'] );
			$this->assertSame( 'draft', $report['groups'][0]['post_status'] );
			$this->assertNotContains( 0, $report['needs_review'] );
			$this->assertSame( [], $GLOBALS['opf_importer_test']['posts'] );
		}

		public function test_product_local_review_groups_are_saved_as_drafts(): void {
			$payload = [ 'fields' => [ $this->text_field( 'name', 'Name' ), [ 'id' => 'colour', 'label' => 'Colour', 'type' => 'color' ] ] ];
			$GLOBALS['opf_importer_test'] = [
				'posts'       => [],
				'meta'        => [],
				'source_meta' => [ 88 => [ '_wapf_fieldgroup' => $payload ] ],
				'source_product' => (object) [ 'ID' => 88, 'post_type' => 'product', 'post_title' => 'Canvas' ],
			];
			$GLOBALS['wpdb'] = new class {
				public string $posts = 'wp_posts';
				public string $postmeta = 'wp_postmeta';

				public function get_results( string $query ): array { return []; }
				public function get_col( string $query ): array { return [ 88 ]; }
			};

			$report = Importer::run( true );

			$this->assertSame( 'draft', $report['groups'][0]['post_status'] );
			$this->assertSame( 'draft', $GLOBALS['opf_importer_test']['posts'][0]['post_status'] );
		}

		private function run_import( array $fields, bool $commit = true ): array {
			$GLOBALS['opf_importer_test'] = [ 'posts' => [], 'meta' => [] ];
			$payload = [ 'fields' => $fields, 'rule_groups' => [] ];
			$GLOBALS['wpdb'] = new class( $payload ) {
				public string $posts = 'wp_posts';
				public string $postmeta = 'wp_postmeta';
				private array $rows;

				public function __construct( array $payload ) {
					$this->rows = [ (object) [
						'ID'           => 77,
						'post_title'   => 'Imported fields',
						'post_status'  => 'publish',
						'post_content' => serialize( $payload ),
					] ];
				}

				public function get_results( string $query ): array {
					return $this->rows;
				}

				public function get_col( string $query ): array {
					return [];
				}
			};

			return Importer::run( $commit );
		}

		private function text_field( string $id, string $label ): array {
			return [
				'id'          => $id,
				'label'       => $label,
				'type'        => 'text',
				'required'    => false,
				'options'     => [ 'choices' => [] ],
				'pricing'     => [ 'enabled' => false ],
				'conditionals'=> [],
			];
		}
	}
}
