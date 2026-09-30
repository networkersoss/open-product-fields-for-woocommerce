<?php
/** Exercise server-side upload resizing on a disposable WordPress clone. */
defined( 'ABSPATH' ) || exit;
if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) WP_CLI::error( 'GD PNG support is required for the resize E2E.' );
$source = tempnam( sys_get_temp_dir(), 'opf-resize-source-' );
$image = imagecreatetruecolor( 4, 2 );
imagefill( $image, 0, 0, imagecolorallocate( $image, 240, 20, 20 ) );
imagepng( $image, $source );
imagedestroy( $image );
$allowed = static function ( $is_uploaded, $path ) use ( $source ) {
	return $path === $source || $is_uploaded;
};
add_filter( 'opf_upload_is_uploaded_file', $allowed, 10, 2 );
$document_root = static function ( $root ) { return ABSPATH; };
add_filter( 'opf_upload_document_root', $document_root );
$field = OPF\Engine\FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'allowed_types' => [ 'png' ], 'auto_resize' => true, 'max_width' => 2 ] );
$stored = OPF\Engine\UploadService::store( [ 'name' => 'source.png', 'type' => 'image/png', 'size' => filesize( $source ), 'error' => UPLOAD_ERR_OK, 'tmp_name' => $source ], $field );
remove_filter( 'opf_upload_is_uploaded_file', $allowed, 10 );
if ( null === $stored ) {
	remove_filter( 'opf_upload_document_root', $document_root );
	unlink( $source );
	WP_CLI::error( 'UploadService rejected the resizable PNG.' );
}
$path = OPF\Engine\UploadService::path( $stored['token'] );
$info = $path ? getimagesize( $path ) : false;
$stored_size = $path ? filesize( $path ) : false;
$passed = is_array( $info ) && 2 === (int) $info[0] && 1 === (int) $info[1] && $stored_size === $stored['size'];
OPF\Engine\UploadService::delete( $stored['token'] );
remove_filter( 'opf_upload_document_root', $document_root );
if ( is_file( $source ) ) unlink( $source );
if ( ! $passed ) WP_CLI::error( 'Resized output did not have bounded 2×1 dimensions (aspect ratio 2:1).' );
WP_CLI::log( 'Stored output is 2×1 from a 4×2 source; aspect ratio preserved and token cleaned.' );
WP_CLI::success( 'Upload resize E2E passed.' );
