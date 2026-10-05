<?php
/** Disposable image-swatch zoom fixture. Usage: wp eval-file ... prepare|cleanup */
defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title = 'OPF E2E Image Swatch Zoom Group';
$product_name = 'OPF E2E Image Swatch Zoom Product';
$image_name = 'opf-e2e-image-swatch-zoom.png';
$image_data = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' );
$image_path = trailingslashit( wp_upload_dir()['basedir'] ) . $image_name;
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => $product_name ] );
$attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_opf_swatch_zoom_fixture', 'meta_value' => '1' ] );

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) {
		wp_delete_post( (int) $group->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	foreach ( $attachments as $attachment ) {
		wp_delete_attachment( (int) $attachment->ID, true );
	}
	if ( is_file( $image_path ) && hash( 'sha256', $image_data ) === hash_file( 'sha256', $image_path ) ) {
		wp_delete_file( $image_path );
	}
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Image-swatch zoom fixtures removed.' );
	return;
}

if ( $groups || $products || $attachments || is_file( $image_path ) ) {
	WP_CLI::error( 'A matching image-swatch fixture already exists; inspect it before cleanup or retry.' );
}
if ( ! wp_mkdir_p( dirname( $image_path ) ) || false === file_put_contents( $image_path, $image_data ) ) {
	WP_CLI::error( 'Could not write image-swatch fixture image.' );
}
$attachment_id = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => $title, 'post_status' => 'inherit' ], $image_path );
if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
	WP_CLI::error( 'Could not create image-swatch fixture attachment.' );
}
update_post_meta( (int) $attachment_id, '_opf_swatch_zoom_fixture', '1' );

$product = new WC_Product_Simple();
$product->set_name( $product_name );
$product->set_slug( 'opf-e2e-image-swatch-zoom-product' );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[
			'id' => 'fabric', 'label' => 'Fabric', 'type' => 'swatch', 'image_zoom' => true,
			'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen', 'image' => wp_get_attachment_url( (int) $attachment_id ) ] ],
		],
		[
			'id' => 'prints', 'label' => 'Prints', 'type' => 'swatch', 'image_quantities' => true, 'image_quantity_zoom' => true,
			'min' => 1, 'max' => 3, 'step' => 1,
			'choices' => [ [ 'slug' => 'small', 'label' => 'Small', 'image' => wp_get_attachment_url( (int) $attachment_id ) ] ],
		],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
if ( ! $product_id || ! $group_id ) {
	WP_CLI::error( 'Image-swatch zoom fixture did not save.' );
}
OPF\Service\FieldGroups::flush_cache();
WP_CLI::log( 'SWATCH_ZOOM_GROUP=' . $group_id );
WP_CLI::log( 'SWATCH_ZOOM_URL=' . get_permalink( $product_id ) );
WP_CLI::success( 'Image-swatch zoom fixture prepared.' );
