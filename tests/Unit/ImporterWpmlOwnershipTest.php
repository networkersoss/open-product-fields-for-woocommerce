<?php

namespace OPF\Service {
	function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
	function wp_slash( $value ) { return $value; }
	function is_wp_error( $value ): bool { return false; }
	function get_post_field( string $field, int $id ) {
		// ImporterTest fixtures drive the same code paths through the global
		// get_post/get_post_field stubs via $GLOBALS['opf_importer_test'].
		if ( isset( $GLOBALS['opf_importer_test'] ) ) {
			$post = function_exists( '\\get_post' ) ? \get_post( $id ) : null;
			return is_object( $post ) ? ( $post->$field ?? '' ) : '';
		}
		return '';
	}
	function update_post_meta( int $id, string $key, $value ): bool {
		$GLOBALS['opf_woocs_meta'][ $id ][ $key ] = $value;
		if ( isset( $GLOBALS['opf_importer_test'] ) ) {
			$GLOBALS['opf_importer_test']['meta'][ $id ][ $key ] = $value;
		}
		return true;
	}
	function wp_insert_post( array $args, bool $return_error = false ): int {
		if ( isset( $GLOBALS['opf_importer_test'] ) ) {
			$GLOBALS['opf_importer_test']['posts'][] = $args;
			foreach ( $args['meta_input'] ?? [] as $key => $value ) {
				update_post_meta( 501, $key, $value );
			}
			return 501;
		}
		$saved = $GLOBALS['opf_import_saved_posts'] ?? [];
		$id = 71 + count( $saved );
		$saved[] = $args;
		$GLOBALS['opf_import_saved_posts'] = $saved;
		foreach ( $args['meta_input'] ?? [] as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		// WordPress persists meta_input before its save_post callbacks fire.
		WpmlIntegration::register_post( $id, (object) $args );
		return $id;
	}
}

namespace OPF\Tests\Unit {
	use OPF\Engine\FieldGroup;
	use OPF\Service\ArchiveImporter;
	use OPF\Service\FieldGroups;
	use OPF\Service\Importer;
	use PHPUnit\Framework\TestCase;

	final class ImporterWpmlOwnershipTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['opf_wpml_filters'] = [];
			$GLOBALS['opf_wpml_actions'] = [];
			$GLOBALS['opf_woocs_meta'] = [];
			$GLOBALS['opf_auth_test_posts'] = [];
			$GLOBALS['opf_import_saved_posts'] = [];
			$GLOBALS['opf_auth_test_cache'] = [];
		}

		protected function tearDown(): void {
			FieldGroups::flush_cache();
			unset( $GLOBALS['opf_wpml_filters'], $GLOBALS['opf_wpml_actions'], $GLOBALS['opf_woocs_meta'], $GLOBALS['opf_auth_test_posts'], $GLOBALS['opf_import_saved_posts'], $GLOBALS['opf_auth_test_cache'] );
		}

		private function import( $source, bool $commit = true ): array {
			$method = new \ReflectionMethod( Importer::class, 'import_group' );
			return $method->invoke( null, [ 'fields' => [ [ 'id' => 'note', 'type' => 'text', 'label' => 'Note' ] ] ], 'Imported', $source, $commit );
		}

		public function test_global_and_local_imports_persist_the_source_language_before_save_hooks(): void {
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'de';
			$calls = [];
			$GLOBALS['opf_wpml_filters']['wpml_element_language_code'] = static function ( $value, $args ) use ( &$calls ) {
				$calls[] = $args;
				return 'product' === $args['element_type'] ? 'fr' : 'en';
			};
			foreach ( [ [ 12, 'en' ], [ 'meta:42', 'fr' ] ] as [ $source, $language ] ) {
				$result = $this->import( $source );
				$this->assertSame( 'imported', $result['result'] );
				$saved = end( $GLOBALS['opf_import_saved_posts'] );
				$this->assertSame( [ '_opf_imported_from' => (string) $source, '_opf_wpml_source_language' => $language ], $saved['meta_input'] );
				$this->assertSame( $language, $GLOBALS['opf_woocs_meta'][ $result['opf_id'] ]['_opf_wpml_source_language'] );
			}
			$this->assertSame( [ [ 'element_id' => 12, 'element_type' => 'wapf_product' ], [ 'element_id' => 42, 'element_type' => 'product' ] ], $calls );
			$this->assertSame( [], $GLOBALS['opf_wpml_actions'] );
		}

		public function test_missing_ownership_is_not_guessed_from_the_admin_or_default_language(): void {
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'de';
			$GLOBALS['opf_wpml_filters']['wpml_default_language'] = static fn() => 'en';
			$this->import( 12 );
			$this->assertSame( [ '_opf_imported_from' => '12' ], $GLOBALS['opf_import_saved_posts'][0]['meta_input'] );
			$this->assertSame( [], $GLOBALS['opf_wpml_actions'] );
		}

		public function test_dry_run_and_already_imported_groups_do_not_write_or_backfill_ownership(): void {
			$GLOBALS['opf_wpml_filters']['wpml_element_language_code'] = static function () { throw new \RuntimeException( 'Do not backfill or resolve dry-run ownership.' ); };
			$this->assertSame( 'imported', $this->import( 12, false )['result'] );
			$GLOBALS['opf_auth_test_posts'] = [ 7 ];
			$GLOBALS['opf_woocs_meta'][7] = [ '_opf_imported_from' => '12' ];
			$this->assertSame( 'already-imported', $this->import( 12 )['result'] );
			$this->assertSame( [], $GLOBALS['opf_import_saved_posts'] );
			$this->assertSame( [ '_opf_imported_from' => '12' ], $GLOBALS['opf_woocs_meta'][7] );
		}

		public function test_archive_import_keeps_ownership_unresolved_and_provenance_precedes_save_hooks(): void {
			$data = FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'note', 'type' => 'text', 'label' => 'Note' ] ] ] );
			$package = [
				'format' => 'opf-field-groups', 'format_version' => 1, 'scope' => [],
				'groups' => [ [ 'source_id' => 12, 'title' => 'Archive', 'status' => 'draft', 'menu_order' => 0, 'language' => '', 'data' => $data, 'warnings' => [] ] ],
			];
			$GLOBALS['opf_wpml_filters']['wpml_element_language_code'] = static function () { throw new \RuntimeException( 'Archive IDs do not identify local WPML elements.' ); };
			$report = ArchiveImporter::import( $package, true );
			$this->assertSame( 1, $report['imported'] );
			$meta = $GLOBALS['opf_import_saved_posts'][0]['meta_input'];
			$this->assertSame( [ '_opf_archive_import_key', '_opf_archive_import_checksum' ], array_keys( $meta ) );
			$this->assertSame( [], $GLOBALS['opf_wpml_actions'] );
		}
	}
}
