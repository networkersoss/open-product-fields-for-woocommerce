<?php

namespace OPF\Tests\Unit;

use OPF\Service\Uploads;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Upload-constraint coverage for the private upload service
 * (`OPF\Service\Uploads`).
 *
 * The optional field keys `min_files`, `max_files`, `min_size_mb`,
 * `min_width`, `min_height` and `image_editor_*` come from the image-upload
 * add-on surface: when the normalized schema carries them the server
 * enforces them at staging time and again when stored bytes are revalidated
 * against their token. Verification always measures real file bytes, never
 * client-reported size, dimensions, or MIME claims.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class UploadServiceTest extends TestCase {
	private string $dir;
	private string $token;

	protected function setUp(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/opf-test-content' );
		if ( ! defined( 'MB_IN_BYTES' ) ) define( 'MB_IN_BYTES', 1048576 );
		if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
		if ( ! defined( 'OPF_UPLOAD_PRIVATE_DIR' ) ) define( 'OPF_UPLOAD_PRIVATE_DIR', sys_get_temp_dir() . '/opf-uploads-' . uniqid() );
		eval( 'class WP_Error { public $code; public $message; public $data; public function __construct( $code, $message, $data ) { $this->code = $code; $this->message = $message; $this->data = $data; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; } }
		class WC_Order { public $id; public $status; public $total = 10.0; public $customer_id = 0;
			public function __construct( $id, $status ) { $this->id = $id; $this->status = $status; }
			public function get_id() { return $this->id; }
			public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
			public function needs_payment() { return $this->has_status( [ "pending", "failed" ] ) && $this->total > 0; }
			public function get_customer_id() { return $this->customer_id; } }
		class OPF_Test_Session { public $customer = "sess-owner"; public $data = [];
			public function get_customer_id() { return $this->customer; }
			public function get( $k ) { return $this->data[ $k ] ?? null; }
			public function set( $k, $v ) { $this->data[ $k ] = $v; }
			public function set_customer_session_cookie( $b ) {} }
		function WC() { return $GLOBALS["opf_wc"]; }
		function wc_get_order( $id ) { return $GLOBALS["opf_orders"][ $id ] ?? false; }
		function wc_load_cart() {}
		function wp_salt( $s ) { return "test-salt"; }
		function is_wp_error( $v ) { return $v instanceof WP_Error; }
		function update_option( $k, $v, $a = null ) { $GLOBALS["opf_test_options"][ $k ] = $v; return true; }
		function add_option( $k, $v, $d = "", $a = null ) { $GLOBALS["opf_test_options"][ $k ] = $v; return true; }
		function delete_option( $k ) { unset( $GLOBALS["opf_test_options"][ $k ] ); return true; }
		function apply_filters( $n, $v, ...$a ) { return $v; }
		function wp_max_upload_size() { return 64 * 1048576; }
		function get_allowed_mime_types() { return [ "png" => "image/png", "jpg|jpeg|jpe" => "image/jpeg", "txt" => "text/plain" ]; }
		function sanitize_file_name( $n ) { return preg_replace( "/[^A-Za-z0-9._-]/", "_", $n ); }
		function wp_check_filetype_and_ext( $file, $name, $mimes = null ) {
			$map = [ "png" => "image/png", "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "jpe" => "image/jpeg", "txt" => "text/plain" ];
			$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
			return isset( $map[ $ext ] ) ? [ "ext" => $ext, "type" => $map[ $ext ] ] : [ "ext" => false, "type" => false ]; }' );

		$GLOBALS['opf_wc']           = new class() { public $session; };
		$GLOBALS['opf_wc']->session  = new \OPF_Test_Session();
		$GLOBALS['opf_orders']       = [];
		$GLOBALS['opf_test_options'] = [];
		$this->dir                   = OPF_UPLOAD_PRIVATE_DIR;
		$this->token                 = str_repeat( 'a', 64 );
		mkdir( $this->dir, 0700, true );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: [] as $file ) {
			if ( ! is_link( $file ) && ! is_file( $file ) ) continue;
			unlink( $file );
		}
		foreach ( glob( $this->dir . '/.upload.lock' ) ?: [] as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) rmdir( $this->dir );
	}

	private function tmp_file( string $contents ): string {
		$tmp = tempnam( sys_get_temp_dir(), 'opf-upload-check-' );
		file_put_contents( $tmp, $contents );
		return $tmp;
	}

	private function store( string $contents, array $overrides = [] ): void {
		file_put_contents( $this->dir . '/' . $this->token . '.bin', $contents );
		chmod( $this->dir . '/' . $this->token . '.bin', 0600 );
		$GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ] = $overrides + [
			'name' => 'art.png', 'mime' => 'image/png', 'size' => strlen( $contents ),
			'owner' => Uploads::owner(), 'product_id' => 7, 'group_id' => 'g', 'field_id' => 'art',
			'created' => time(), 'order_id' => 0, 'cart' => true,
		];
	}

	public function test_field_file_count_limits_override_the_php_batch_default(): void {
		$ini = max( 1, (int) ini_get( 'max_file_uploads' ) );
		self::assertSame( 1, Uploads::max_files( [ 'multiple' => false ] ) );
		self::assertSame( $ini, Uploads::max_files( [ 'multiple' => true ] ) );
		self::assertSame( 3, Uploads::max_files( [ 'multiple' => true, 'max_files' => 3 ] ) );
		self::assertSame( PHP_INT_MAX, Uploads::max_files( [ 'multiple' => true, 'max_files' => -1 ] ), 'The -1 unlimited sentinel must not cap the field.' );
	}

	public function test_token_records_reject_traversal_and_non_opaque_tokens(): void {
		self::assertNull( Uploads::record( '../secret' ) );
		self::assertNull( Uploads::record( str_repeat( 'g', 64 ) ) );
		self::assertNull( Uploads::record( str_repeat( 'a', 48 ) ) );
	}

	public function test_validate_tokens_enforces_minimum_and_maximum_file_counts(): void {
		$field = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'min_files' => 2, 'max_files' => 3 ];
		self::assertSame( [ '"Artwork" requires at least 2 file(s).' ], Uploads::validate_tokens( $field, [], 7, 'g' ) );
		// Count checks run before token resolution: unknown tokens still hit them.
		self::assertSame( [ '"Artwork" requires at least 2 file(s).' ], Uploads::validate_tokens( $field, [ $this->token ], 7, 'g' ) );
		self::assertSame( [ 'Too many uploaded files.' ], Uploads::validate_tokens( $field, [ 'a', 'b', 'c', 'd' ], 7, 'g' ) );

		// A required field keeps its stronger empty-submission message.
		$required = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true, 'min_files' => 2 ];
		self::assertSame( [ '"Artwork" is a required field.' ], Uploads::validate_tokens( $required, [], 7, 'g' ) );

		// The -1 unlimited sentinel skips only the per-field cap: unknown
		// tokens still fail their own record lookup.
		$unlimited = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'max_files' => -1 ];
		self::assertSame(
			[ 'An uploaded file is unavailable. Please upload it again.' ],
			Uploads::validate_tokens( $unlimited, array_fill( 0, 25, str_repeat( 'b', 64 ) ), 7, 'g' )
		);
	}

	public function test_validate_file_reports_upload_errors_size_and_type_limits(): void {
		$field = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'max_size' => 1, 'accepted_types' => [ 'png' ] ];

		$error = Uploads::validate_file( [ 'name' => 'a.png', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 10, 'tmp_name' => '' ], $field );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'opf_upload_file', $error->get_error_code() );

		$oversized = $this->tmp_file( str_repeat( 'x', 12 ) );
		$error     = Uploads::validate_file( [ 'name' => 'a.png', 'error' => UPLOAD_ERR_OK, 'size' => 12, 'tmp_name' => $oversized ], [ 'max_size' => 0.00001, 'accepted_types' => [ 'png' ] ] );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'opf_upload_size', $error->get_error_code() );
		unlink( $oversized );

		$tmp = $this->tmp_file( 'x' );
		foreach ( [ 'a.php', 'a.gif' ] as $name ) {
			$error = Uploads::validate_file( [ 'name' => $name, 'error' => UPLOAD_ERR_OK, 'size' => 1, 'tmp_name' => $tmp ], $field );
			self::assertInstanceOf( \WP_Error::class, $error, $name );
			self::assertSame( 'opf_upload_type', $error->get_error_code(), $name );
		}
		unlink( $tmp );
	}

	public function test_minimum_file_size_uses_measured_bytes_not_reported_size(): void {
		$field = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'max_size' => 1, 'min_size_mb' => 0.00001, 'accepted_types' => [ 'txt' ] ];
		$tmp   = $this->tmp_file( '12345678' );

		// ceil(0.00001 MB) = 11 bytes; the reported size cannot bypass it.
		$error = Uploads::validate_file( [ 'name' => 'art.txt', 'type' => 'text/plain', 'size' => 999999, 'error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp ], $field );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'opf_upload_min_size', $error->get_error_code() );

		file_put_contents( $tmp, str_repeat( 'x', 12 ) );
		$checked = Uploads::validate_file( [ 'name' => 'art.txt', 'type' => 'text/plain', 'size' => 0, 'error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp ], $field );
		self::assertIsArray( $checked );
		self::assertSame( 12, $checked['size'] );
		unlink( $tmp );
	}

	public function test_minimum_image_dimensions_use_verified_file_bytes(): void {
		$png   = (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABh6FO1AAAAABJRU5ErkJggg==', true );
		$tmp   = $this->tmp_file( $png );
		$field = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'max_size' => 1, 'accepted_types' => [ 'png' ], 'min_width' => 2, 'min_height' => 1 ];
		$file  = [ 'name' => 'art.png', 'type' => 'image/png', 'size' => strlen( $png ), 'error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp ];

		// The stored image is 1x1: below the configured minimum width.
		$error = Uploads::validate_file( $file, $field );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'opf_upload_dimensions', $error->get_error_code() );

		$field['min_width'] = 1;
		self::assertIsArray( Uploads::validate_file( $file, $field ) );

		// A verified non-image file can never satisfy configured dimensions.
		$not_image = $this->tmp_file( 'plain text' );
		$error     = Uploads::validate_file( [ 'name' => 'art.txt', 'type' => 'text/plain', 'size' => 10, 'error' => UPLOAD_ERR_OK, 'tmp_name' => $not_image ], [ 'max_size' => 1, 'accepted_types' => [ 'txt' ], 'min_width' => 1 ] );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'opf_upload_image', $error->get_error_code() );
		unlink( $not_image );

		// A non-image upload fails the content-MIME agreement even earlier.
		file_put_contents( $tmp, 'not an image' );
		$error = Uploads::validate_file( $file, $field );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'opf_upload_type', $error->get_error_code() );
		unlink( $tmp );
	}

	public function test_forced_fixed_crop_ratio_is_verified_from_image_bytes(): void {
		$source = (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAADwyuo0AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQImWP8ICLCAANMDEgAABtqARyVdUwxAAAAAElFTkSuQmCC', true );
		$edited = (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=', true );
		$source_tmp = $this->tmp_file( $source );
		$edited_tmp = $this->tmp_file( $edited );
		$field      = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'max_size' => 1, 'accepted_types' => [ 'png' ], 'image_editor_mode' => 'forced', 'image_editor_crop' => true, 'image_editor_aspect_ratio' => '1:1' ];
		$upload     = static fn( string $path ): array => [ 'name' => 'art.png', 'type' => 'image/png', 'size' => filesize( $path ), 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path ];

		$error = Uploads::validate_file( $upload( $source_tmp ), $field );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'opf_upload_aspect', $error->get_error_code() );
		self::assertIsArray( Uploads::validate_file( $upload( $edited_tmp ), $field ) );

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			unlink( $source_tmp );
			unlink( $edited_tmp );
			$this->markTestSkipped( 'GD is not available to generate a rounded-ratio image.' );
		}
		$rounded_tmp = $this->tmp_file( '' );
		$image       = imagecreatetruecolor( 100, 56 );
		imagepng( $image, $rounded_tmp );
		$field['image_editor_aspect_ratio'] = '16:9';
		self::assertIsArray( Uploads::validate_file( $upload( $rounded_tmp ), $field ), 'Canvas output rounded to integer pixels must remain within the preset ratio tolerance.' );
		unlink( $rounded_tmp );
		unlink( $source_tmp );
		unlink( $edited_tmp );
	}

	public function test_stored_tokens_are_revalidated_against_current_field_constraints(): void {
		$png = (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABh6FO1AAAAABJRU5ErkJggg==', true );
		$this->store( $png );
		$field = [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true, 'max_size' => 1, 'accepted_types' => [ 'png' ], 'min_width' => 2 ];

		// An uploaded 1x1 image stops validating once the field requires wider media.
		self::assertSame(
			[ 'An uploaded file no longer meets this field’s requirements. Please upload it again.' ],
			Uploads::validate_tokens( $field, [ $this->token ], 7, 'g' )
		);

		$field['min_width'] = 1;
		self::assertSame( [], Uploads::validate_tokens( $field, [ $this->token ], 7, 'g' ) );

		// A tampered size record fails the re-validation too.
		$GLOBALS['opf_test_options'][ 'opf_upload_' . $this->token ]['size'] += 1;
		self::assertSame(
			[ 'An uploaded file no longer meets this field’s requirements. Please upload it again.' ],
			Uploads::validate_tokens( $field, [ $this->token ], 7, 'g' )
		);
	}
}
