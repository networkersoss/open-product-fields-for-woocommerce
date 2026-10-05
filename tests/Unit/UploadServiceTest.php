<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\UploadService;
use PHPUnit\Framework\TestCase;

final class UploadServiceTest extends TestCase {
	public function test_upload_constraints_are_normalized_and_enforced(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'max_files' => 2, 'max_size' => 1000, 'allowed_types' => [ 'png' ] ] );
		$this->assertSame( 2, $field['max_files'] );
		$this->assertSame( [ '"Artwork" contains a file larger than the permitted size.' ], UploadService::validate( [ [ 'name' => 'a.png', 'size' => 1001, 'error' => UPLOAD_ERR_OK, 'type' => 'image/png' ] ], $field ) );
		$this->assertSame( [], UploadService::validate( [ [ 'name' => 'a.png', 'size' => 100, 'error' => UPLOAD_ERR_OK, 'type' => 'image/png' ] ], $field ) );
	}

	public function test_path_rejects_traversal_and_non_opaque_tokens(): void {
		$this->assertNull( UploadService::path( '../secret' ) );
		$this->assertNull( UploadService::path( str_repeat( 'g', 48 ) ) );
	}

	public function test_normalize_files_flattens_single_and_multi_inputs(): void {
		$single = UploadService::normalize_files(
			[
				'name'     => 'a.png',
				'type'     => 'image/png',
				'tmp_name' => '/tmp/a',
				'error'    => UPLOAD_ERR_OK,
				'size'     => 10,
			]
		);
		$this->assertCount( 1, $single );
		$this->assertSame( 'a.png', $single[0]['name'] );
		$this->assertSame( UPLOAD_ERR_OK, $single[0]['error'] );

		$multi = UploadService::normalize_files(
			[
				'name'     => [ 'a.png', 'b.png' ],
				'type'     => [ 'image/png', 'image/png' ],
				'tmp_name' => [ '/tmp/a', '/tmp/b' ],
				'error'    => [ UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE ],
				'size'     => [ 10, 0 ],
			]
		);
		$this->assertCount( 2, $multi );
		$this->assertSame( 'b.png', $multi[1]['name'] );
	}

	public function test_normalize_files_descends_into_group_and_field(): void {
		$files = UploadService::normalize_files(
			[
				'name'     => [ '7' => [ 'art' => [ 'one.png', 'two.png' ] ] ],
				'type'     => [ '7' => [ 'art' => [ 'image/png', 'image/png' ] ] ],
				'tmp_name' => [ '7' => [ 'art' => [ '/tmp/1', '/tmp/2' ] ] ],
				'error'    => [ '7' => [ 'art' => [ UPLOAD_ERR_OK, UPLOAD_ERR_OK ] ] ],
				'size'     => [ '7' => [ 'art' => [ 10, 20 ] ] ],
			],
			'7',
			'art'
		);
		$this->assertCount( 2, $files );
		$this->assertSame( 'two.png', $files[1]['name'] );
		$this->assertSame( '/tmp/2', $files[1]['tmp_name'] );
		$this->assertSame( 20, $files[1]['size'] );

		$single = UploadService::normalize_files(
			[
				'name'     => [ '7' => [ 'art' => 'one.png' ] ],
				'type'     => [ '7' => [ 'art' => 'image/png' ] ],
				'tmp_name' => [ '7' => [ 'art' => '/tmp/1' ] ],
				'error'    => [ '7' => [ 'art' => UPLOAD_ERR_OK ] ],
				'size'     => [ '7' => [ 'art' => 10 ] ],
			],
			'7',
			'art'
		);
		$this->assertCount( 1, $single );
		$this->assertSame( '/tmp/1', $single[0]['tmp_name'] );

		$this->assertSame( [], UploadService::normalize_files( [ 'name' => [ '7' => [] ] ], '7', 'art' ) );
	}

	public function test_attempts_ignores_empty_file_inputs(): void {
		$files = [
			[ 'name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '' ],
			[ 'name' => 'a.png', 'error' => UPLOAD_ERR_OK, 'size' => 5, 'tmp_name' => '/tmp/a' ],
		];
		$this->assertSame( 1, UploadService::attempts( $files ) );
		$this->assertTrue( UploadService::has_files( $files ) );
		$this->assertFalse( UploadService::has_files( [ [ 'name' => '', 'error' => UPLOAD_ERR_NO_FILE ] ] ) );
	}

	public function test_validate_counts_only_real_attempts_and_honors_wildcards(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'max_files' => 1, 'allowed_types' => [ 'image/*' ] ] );
		$empty = [ [ 'name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'type' => '' ] ];
		$this->assertSame( [], UploadService::validate( $empty, $field ) );

		$two = [
			[ 'name' => 'a.png', 'error' => UPLOAD_ERR_OK, 'size' => 10, 'type' => 'image/png' ],
			[ 'name' => 'b.png', 'error' => UPLOAD_ERR_OK, 'size' => 10, 'type' => 'image/png' ],
		];
		$this->assertSame( [ '"Artwork" allows at most 1 file(s).' ], UploadService::validate( $two, $field ) );

		$this->assertSame(
			[ '"Artwork" contains a file type that is not allowed.' ],
			UploadService::validate( [ [ 'name' => 'a.pdf', 'error' => UPLOAD_ERR_OK, 'size' => 10, 'type' => 'application/pdf' ] ], $field )
		);
	}

	public function test_minimum_file_count_and_unlimited_maximum_are_enforced(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'min_files' => 2, 'max_files' => -1 ] );
		$this->assertSame( 2, $field['min_files'] );
		$this->assertSame( -1, $field['max_files'] );
		$this->assertSame( [ '"Artwork" requires at least 2 file(s).' ], UploadService::validate( [], $field ) );
		$many = array_fill( 0, 21, [ 'name' => 'a.txt', 'error' => UPLOAD_ERR_OK, 'size' => 1, 'type' => 'text/plain' ] );
		$this->assertSame( [], UploadService::validate( $many, $field ) );
	}

	public function test_minimum_image_dimensions_use_verified_temporary_file_bytes(): void {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABh6FO1AAAAABJRU5ErkJggg==', true );
		$this->assertNotFalse( $png );
		$tmp = tempnam( sys_get_temp_dir(), 'opf-image-dimension-' );
		file_put_contents( $tmp, $png );
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'allowed_types' => [ 'png' ], 'min_width' => 2, 'min_height' => 1 ] );
		$valid = [ [ 'name' => 'art.png', 'type' => 'image/png', 'size' => strlen( $png ), 'error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp ] ];
		$this->assertSame( [ '"Artwork" image must be at least 2 pixels wide.' ], UploadService::validate( $valid, $field ) );
		$field['min_width'] = 1;
		$this->assertSame( [], UploadService::validate( $valid, $field ) );
		file_put_contents( $tmp, 'not an image' );
		$this->assertSame( [ '"Artwork" contains an image that could not be verified.' ], UploadService::validate( $valid, $field ) );
		unlink( $tmp );
	}

	public function test_minimum_file_size_uses_temporary_file_bytes_not_reported_size(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'opf-min-size-' );
		file_put_contents( $tmp, '12345678' );
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'min_size_mb' => 0.00001 ] );
		$file = [ [ 'name' => 'art.txt', 'type' => 'text/plain', 'size' => 999999, 'error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp ] ];
		$this->assertSame( [ '"Artwork" contains a file smaller than the minimum size.' ], UploadService::validate( $file, $field ) );
		file_put_contents( $tmp, str_repeat( 'x', 12 ) );
		$file[0]['size'] = 0;
		$this->assertSame( [], UploadService::validate( $file, $field ) );
		unlink( $tmp );
	}

	public function test_forced_fixed_crop_ratio_is_validated_from_image_bytes(): void {
		$source = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAADwyuo0AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQImWP8ICLCAANMDEgAABtqARyVdUwxAAAAAElFTkSuQmCC', true );
		$edited = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=', true );
		$this->assertNotFalse( $source );
		$this->assertNotFalse( $edited );
		$source_path = tempnam( sys_get_temp_dir(), 'opf-aspect-source-' );
		$edited_path = tempnam( sys_get_temp_dir(), 'opf-aspect-edited-' );
		file_put_contents( $source_path, $source );
		file_put_contents( $edited_path, $edited );
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'allowed_types' => [ 'png' ], 'image_editor_mode' => 'forced', 'image_editor_crop' => true, 'image_editor_aspect_ratio' => '1:1' ] );
		$upload = static fn( string $path ): array => [ [ 'name' => 'art.png', 'type' => 'image/png', 'size' => filesize( $path ), 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path ] ];
		$this->assertSame( [ '"Artwork" image does not match its required crop aspect ratio.' ], UploadService::validate( $upload( $source_path ), $field ) );
		$this->assertSame( [], UploadService::validate( $upload( $edited_path ), $field ) );

		$rounded_path = tempnam( sys_get_temp_dir(), 'opf-aspect-rounded-' );
		$rounded_image = imagecreatetruecolor( 100, 56 );
		imagepng( $rounded_image, $rounded_path );
		imagedestroy( $rounded_image );
		$field['image_editor_aspect_ratio'] = '16:9';
		$this->assertSame( [], UploadService::validate( $upload( $rounded_path ), $field ), 'Canvas output rounded to integer pixels must remain within the preset ratio tolerance.' );
		unlink( $rounded_path );
		unlink( $source_path );
		unlink( $edited_path );
	}

	public function test_validate_reports_upload_errors_and_accepts_dot_extensions(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'allowed_types' => [ '.png' ] ] );
		$this->assertSame(
			[ '"Artwork" contains an upload error.' ],
			UploadService::validate( [ [ 'name' => 'a.png', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 10, 'type' => 'image/png' ] ], $field )
		);
		$this->assertSame(
			[],
			UploadService::validate( [ [ 'name' => 'a.png', 'error' => UPLOAD_ERR_OK, 'size' => 10, 'type' => 'image/png' ] ], $field )
		);
	}

	public function test_store_refuses_non_uploads_and_bad_sizes(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'label' => 'Artwork', 'max_size' => 100, 'allowed_types' => [ 'png' ] ] );
		$this->assertNull( UploadService::store( [ 'tmp_name' => __FILE__, 'name' => 'a.png', 'size' => 10, 'type' => 'image/png' ], $field ) );
		$this->assertNull( UploadService::store( [ 'tmp_name' => '', 'name' => 'a.png', 'size' => 10, 'type' => 'image/png' ], $field ) );
	}

	public function test_viewer_access_rejects_malformed_tokens(): void {
		$this->assertFalse( UploadService::viewer_can_access( 'nope' ) );
		$this->assertFalse( UploadService::viewer_can_access( str_repeat( 'g', 48 ) ) );
	}

	public function test_legacy_copy_failure_keeps_all_public_sources_and_rolls_back_new_copies(): void {
		$source = sys_get_temp_dir() . '/opf-legacy-' . bin2hex( random_bytes( 8 ) );
		$target = sys_get_temp_dir() . '/opf-private-' . bin2hex( random_bytes( 8 ) );
		mkdir( $source, 0700 );
		mkdir( $target, 0700 ); // Existing target forces the cross-directory copy path.

		$token       = str_repeat( 'a', 48 );
		$source_file = $source . '/' . $token . '.txt';
		$target_file = $target . '/' . $token . '.txt';
		file_put_contents( $source_file, 'legacy customer file' );
		// This is a valid-looking token name with an unsafe non-file target.
		// It forces a mid-scan failure after the first source is staged.
		$linked_file = $source . '/' . str_repeat( 'f', 48 ) . '.png';
		if ( ! symlink( __FILE__, $linked_file ) ) {
			$this->remove_test_tree( $source, $target );
			$this->markTestSkipped( 'Symlinks are not available in this test environment.' );
		}
		$valid_entry = basename( $source_file );
		$linked_entry = basename( $linked_file );
		$entries = [];
		$handle = opendir( $source );
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' !== $entry && '..' !== $entry ) $entries[] = $entry;
		}
		closedir( $handle );
		if ( array_search( $valid_entry, $entries, true ) > array_search( $linked_entry, $entries, true ) ) {
			$this->remove_test_tree( $source, $target );
			$this->markTestSkipped( 'Filesystem returned the failure entry before the source to be staged.' );
		}

		$checked_property = new \ReflectionProperty( UploadService::class, 'legacy_migration_checked' );
		$error_property = new \ReflectionProperty( UploadService::class, 'legacy_migration_error_logged' );
		$was_checked = $checked_property->getValue();
		$was_logged = $error_property->getValue();
		$checked_property->setValue( null, false );
		$error_property->setValue( null, false );
		try {
			$migrate = new \ReflectionMethod( UploadService::class, 'migrate_legacy_storage' );
			$this->assertFalse( $migrate->invoke( null, $source, $target ) );
			$this->assertFileExists( $source_file, 'The original legacy token must remain after a later scan failure.' );
			$this->assertFileExists( $linked_file, 'The unverified source entry must remain untouched.' );
			$this->assertFileDoesNotExist( $target_file, 'A newly staged copy must be rolled back on batch failure.' );
		} finally {
			$checked_property->setValue( null, $was_checked );
			$error_property->setValue( null, $was_logged );
			$this->remove_test_tree( $source, $target );
		}
	}

	private function remove_test_tree( string $source, string $target ): void {
		foreach ( [ $source, $target ] as $directory ) {
			foreach ( (array) glob( $directory . '/*' ) as $path ) {
				if ( is_link( $path ) || is_file( $path ) ) unlink( $path );
			}
			rmdir( $directory );
		}
	}
}
