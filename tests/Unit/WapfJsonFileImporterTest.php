<?php
/**
 * WAPF Tools JSON payload import tests.
 *
 * The importer's write path runs through FieldGroups::save(), so the stubs
 * below mirror ImporterTest's fixture registry: when another test file has
 * already declared the OPF\Service WordPress wrappers, the guard keeps those
 * declarations and the same $GLOBALS['opf_importer_test'] registry drives both.
 */

namespace {
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args = [] ): array {
			return [];
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
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return false;
		}
	}
	if ( ! function_exists( 'wp_insert_post' ) ) {
		function wp_insert_post( $postarr, $wp_error = false ): int {
			$GLOBALS['opf_importer_test']['posts'][] = $postarr;
			return 501;
		}
	}
	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( $post_id, $key, $value ): bool {
			$GLOBALS['opf_importer_test']['meta'][ $post_id ][ $key ] = $value;
			return true;
		}
	}
}

namespace OPF\Service {
	if ( ! function_exists( 'OPF\\Service\\get_posts' ) ) {
		function get_posts( array $args = [] ): array {
			return $GLOBALS['opf_auth_test_posts'] ?? [];
		}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_json_encode' ) ) {
		function wp_json_encode( $value, int $flags = 0 ) {
			return json_encode( $value, $flags );
		}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_slash' ) ) {
		function wp_slash( $value ) {
			return $value;
		}
	}
	if ( ! function_exists( 'OPF\\Service\\is_wp_error' ) ) {
		function is_wp_error( $value ): bool {
			return false;
		}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_insert_post' ) ) {
		function wp_insert_post( array $args, bool $return_error = false ): int {
			$GLOBALS['opf_importer_test']['posts'][] = $args;
			return 501;
		}
	}
	if ( ! function_exists( 'OPF\\Service\\update_post_meta' ) ) {
		function update_post_meta( int $id, string $key, $value ): bool {
			$GLOBALS['opf_importer_test']['meta'][ $id ][ $key ] = $value;
			return true;
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\WapfMapper;
	use OPF\Service\FieldGroups;
	use OPF\Service\WapfJsonFileImporter;
	use PHPUnit\Framework\TestCase;

	final class WapfJsonFileImporterTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['opf_importer_test']   = [ 'posts' => [], 'meta' => [] ];
			$GLOBALS['opf_auth_test_posts'] = [];
			$GLOBALS['opf_woocs_products']  = [];
			FieldGroups::flush_cache();
		}

		protected function tearDown(): void {
			unset( $GLOBALS['opf_importer_test'], $GLOBALS['opf_auth_test_posts'], $GLOBALS['opf_woocs_products'] );
		}

		public function test_normalizes_flat_field_options_and_flags_unknown_field_options(): void {
			$prepared = WapfJsonFileImporter::prepare_payload( [
				'fields' => [
					[ 'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text', 'placeholder' => 'Name', 'choices' => [], 'unknown_option' => 'keep in source' ],
				],
				'conditions' => [],
				'layout' => [ 'labels_position' => 'above' ],
				'variables' => [],
			] );

			$this->assertSame( 'keep in source', $prepared['payload']['fields'][0]['options']['unknown_option'] );
			$this->assertSame( 'Name', $prepared['payload']['fields'][0]['options']['placeholder'] );
			$this->assertSame( [], $prepared['payload']['rule_groups'] );
			$this->assertSame( [ 'labels_position' => 'above' ], $prepared['payload']['layout'] );
			$this->assertTrue( $this->has_note( $prepared['notes'], 'source group ID and title' ) );
			$this->assertTrue( $this->has_note( $prepared['notes'], 'unknown_option' ) );
			$this->assertFalse( $this->has_note( $prepared['notes'], 'placeholder' ) );
		}

		public function test_maps_placement_conditions_the_current_mapper_supports(): void {
			$prepared = WapfJsonFileImporter::prepare_payload( [
				'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
				'conditions' => [ [ 'rules' => [
					[ 'condition' => 'products', 'subject' => 'product', 'value' => [ [ 'id' => '42', 'text' => 'Product' ] ] ],
					[ 'condition' => 'auth', 'subject' => 'user', 'value' => [] ],
				] ] ],
			] );

			$mapped = WapfMapper::map( $prepared['payload'] );
			$rules  = $mapped['group']['rule_groups'][0]['rules'];

			$this->assertSame( 'product', $rules[0]['subject'] );
			$this->assertSame( [ '42' ], $rules[0]['terms'] );
			$this->assertSame( 'user_auth', $rules[1]['subject'] );
			$this->assertSame( [ 'logged_in' ], $rules[1]['terms'] );
			$this->assertFalse( $mapped['needs_review'] );
		}

		public function test_flags_mappable_and_unmappable_variable_definitions_differently(): void {
			$mappable = WapfJsonFileImporter::prepare_payload( [
				'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
				'variables' => [ [ 'name' => 'total', 'default' => '1', 'rules' => [] ] ],
			] );
			$this->assertFalse( $this->has_note( $mappable['notes'], 'variable definitions' ) );
			$this->assertSame( 'total', WapfMapper::map( $mappable['payload'] )['group']['variables'][0]['name'] );

			$unmappable = WapfJsonFileImporter::prepare_payload( [
				'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
				'variables' => [ 'discount' => [ 'value' => 10 ] ],
			] );
			$this->assertTrue( $this->has_note( $unmappable['notes'], 'cannot be mapped' ) );
			$this->assertSame( [ 'discount' => [ 'value' => 10 ] ], $unmappable['payload']['variables'] );
		}

		public function test_flags_unknown_top_level_payload_keys_instead_of_dropping_them(): void {
			$prepared = WapfJsonFileImporter::prepare_payload( [
				'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
				'replace' => true,
			] );

			$this->assertTrue( $this->has_note( $prepared['notes'], 'not part of the four-part Tools payload' ) );
			$this->assertTrue( $this->has_note( $prepared['notes'], 'replace' ) );
		}

		public function test_rejects_missing_or_oversized_field_lists(): void {
			try {
				WapfJsonFileImporter::prepare_payload( [ 'fields' => [] ] );
				$this->fail( 'An empty fields list must fail closed.' );
			} catch ( \InvalidArgumentException $exception ) {
				$this->assertStringContainsString( 'non-empty fields list', $exception->getMessage() );
			}

			$this->expectException( \InvalidArgumentException::class );
			$this->expectExceptionMessage( '500-field import limit' );
			WapfJsonFileImporter::prepare_payload( [ 'fields' => array_fill( 0, 501, [ 'id' => 'f', 'type' => 'text' ] ) ] );
		}

		public function test_rejects_malformed_group_conditions_instead_of_importing_a_broader_group(): void {
			$this->expectException( \InvalidArgumentException::class );
			WapfJsonFileImporter::prepare_payload( [
				'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
				'conditions' => [ [ 'rules' => 'not-a-rule-list' ] ],
			] );
		}

		public function test_rejects_malformed_json_and_out_of_range_files(): void {
			$malformed = $this->temp_file( '{"fields": [' );
			try {
				WapfJsonFileImporter::inspect_file( $malformed );
				$this->fail( 'Malformed JSON must fail closed.' );
			} catch ( \InvalidArgumentException $exception ) {
				$this->assertStringContainsString( 'malformed', $exception->getMessage() );
			} finally {
				unlink( $malformed );
			}

			$oversize = $this->temp_file( str_repeat( ' ', WapfJsonFileImporter::MAX_FILE_BYTES + 1 ) );
			try {
				WapfJsonFileImporter::inspect_file( $oversize );
				$this->fail( 'An oversize payload must fail closed.' );
			} catch ( \InvalidArgumentException $exception ) {
				$this->assertStringContainsString( 'between 2 bytes and 5 MiB', $exception->getMessage() );
			} finally {
				unlink( $oversize );
			}

			$tiny = $this->temp_file( ' ' );
			try {
				WapfJsonFileImporter::inspect_file( $tiny );
				$this->fail( 'A 1-byte payload must fail closed.' );
			} catch ( \InvalidArgumentException $exception ) {
				$this->assertStringContainsString( 'between 2 bytes and 5 MiB', $exception->getMessage() );
			} finally {
				unlink( $tiny );
			}
		}

		public function test_rejects_a_missing_file(): void {
			$this->expectException( \InvalidArgumentException::class );
			$this->expectExceptionMessage( 'readable regular file' );
			WapfJsonFileImporter::inspect_file( sys_get_temp_dir() . '/opf-wapf-json-does-not-exist.json' );
		}

		public function test_inspects_a_valid_json_file_and_keeps_supported_group_conditions(): void {
			$path = $this->temp_file( (string) json_encode( [
				'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
				'conditions' => [ [ 'rules' => [ [ 'condition' => 'products', 'subject' => 'product', 'value' => [ [ 'id' => '42', 'text' => 'Product' ] ] ] ] ] ],
				'layout' => [ 'mark_required' => false ],
				'variables' => [],
			] ) );
			try {
				$prepared = WapfJsonFileImporter::inspect_file( $path );
				$mapped   = WapfMapper::map( $prepared['payload'] );
				$this->assertSame( 'product', $mapped['group']['rule_groups'][0]['rules'][0]['subject'] );
				$this->assertSame( [ '42' ], $mapped['group']['rule_groups'][0]['rules'][0]['terms'] );
				$this->assertFalse( $mapped['needs_review'] );
				$this->assertFalse( $mapped['group']['mark_required'] );
			} finally {
				unlink( $path );
			}
		}

		public function test_dry_run_reports_the_mapping_without_writing(): void {
			$report = WapfJsonFileImporter::run( $this->prepared(), 'Imported fields', false );

			$this->assertSame( 'dry-run', $report['result'] );
			$this->assertSame( 0, $report['opf_id'] );
			$this->assertSame( 'Imported fields', $report['title'] );
			$this->assertSame( 1, $report['fields'] );
			$this->assertTrue( $report['needs_review'] );
			$this->assertSame( [], $GLOBALS['opf_importer_test']['posts'] );
			$this->assertSame( [], $GLOBALS['opf_importer_test']['meta'] );
		}

		public function test_commit_writes_a_review_draft_with_provenance(): void {
			$report = WapfJsonFileImporter::run( $this->prepared(), 'Imported fields', true );

			$this->assertSame( 'draft-created', $report['result'] );
			$this->assertSame( 501, $report['opf_id'] );
			$this->assertSame( 'draft', $GLOBALS['opf_importer_test']['posts'][0]['post_status'] );
			$this->assertSame( 'Imported fields', $GLOBALS['opf_importer_test']['posts'][0]['post_title'] );
			$this->assertStringStartsWith( 'wapf-json:', (string) $GLOBALS['opf_importer_test']['posts'][0]['meta_input']['_opf_imported_from'] );
			$this->assertNotEmpty( $GLOBALS['opf_importer_test']['meta'][501]['_opf_needs_review'] );
		}

		public function test_already_imported_payload_is_reported_without_writing_again(): void {
			$GLOBALS['opf_auth_test_posts'] = [ 77 ];

			$report = WapfJsonFileImporter::run( $this->prepared(), 'Imported fields', true );

			$this->assertSame( 'already-imported', $report['result'] );
			$this->assertSame( 77, $report['opf_id'] );
			$this->assertSame( [], $GLOBALS['opf_importer_test']['posts'] );
		}

		public function test_rejects_a_product_id_that_does_not_exist_and_an_invalid_title(): void {
			try {
				WapfJsonFileImporter::run( $this->prepared(), 'Imported fields', false, 4242 );
				$this->fail( 'An unknown product must fail closed.' );
			} catch ( \InvalidArgumentException $exception ) {
				$this->assertStringContainsString( 'product does not exist', $exception->getMessage() );
			}

			$this->expectException( \InvalidArgumentException::class );
			$this->expectExceptionMessage( 'title between 1 and 200 characters' );
			WapfJsonFileImporter::run( $this->prepared(), '   ', false );
		}

		private function prepared(): array {
			return WapfJsonFileImporter::prepare_payload( [
				'fields' => [ [ 'id' => 'f1', 'label' => 'Field', 'type' => 'text' ] ],
			] );
		}

		private function temp_file( string $contents ): string {
			$path = tempnam( sys_get_temp_dir(), 'opf-wapf-json-' );
			$this->assertNotFalse( $path );
			file_put_contents( $path, $contents );
			return (string) $path;
		}

		private function has_note( array $notes, string $needle ): bool {
			return (bool) array_filter( $notes, static fn ( $note ) => false !== strpos( $note, $needle ) );
		}
	}
}
