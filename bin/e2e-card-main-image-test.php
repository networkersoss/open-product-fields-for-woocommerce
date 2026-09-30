<?php
/**
 * Disposable radio-card main-image fixture.
 *
 * Usage: wp eval-file bin/e2e-card-main-image-test.php prepare|cleanup
 */

defined( 'ABSPATH' ) || exit;

$action       = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title        = 'OPF E2E Card Main Image Group';
$product_name = 'OPF E2E Card Main Image Product';
$upload       = wp_upload_dir();
$images       = [
	'front'    => 'opf-card-main-front.png',
	'gallery'  => 'opf-card-main-gallery.png',
	'external' => 'opf-card-main-external.png',
];
$png         = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' );
$groups      = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products    = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'name' => sanitize_title( $product_name ), 'status' => [ 'publish', 'draft', 'private' ] ] );
$attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_opf_card_main_image_fixture', 'meta_value' => '1' ] );
$attachment_by_file = [];
foreach ( $attachments as $attachment ) {
	$file = get_attached_file( (int) $attachment->ID );
	if ( $file ) {
		$attachment_by_file[ basename( $file ) ] = (int) $attachment->ID;
	}
}

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) {
		wp_delete_post( (int) $group->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	foreach ( $attachments as $attachment ) {
		$file = get_attached_file( (int) $attachment->ID );
		wp_delete_attachment( (int) $attachment->ID, true );
		if ( $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	foreach ( $images as $filename ) {
		$path = trailingslashit( $upload['basedir'] ) . $filename;
		if ( is_file( $path ) && hash( 'sha256', $png ) === hash_file( 'sha256', $path ) ) {
			wp_delete_file( $path );
		}
	}
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Card main-image E2E fixtures removed.' );
	return;
}

$product = $products ? $products[0] : new WC_Product_Simple();
$product->set_name( $product_name );
$product->set_slug( sanitize_title( $product_name ) );
$product->set_regular_price( '100.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
$image_ids  = [];
foreach ( $images as $key => $filename ) {
	$path = trailingslashit( $upload['basedir'] ) . $filename;
	if ( is_file( $path ) && hash( 'sha256', $png ) !== hash_file( 'sha256', $path ) ) {
		WP_CLI::error( 'Card main-image fixture path already contains different bytes: ' . $filename );
	}
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $png );
	}
	$id = $attachment_by_file[ $filename ] ?? wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF card main image ' . $key, 'post_status' => 'inherit' ], $path, $product_id, true );
	if ( is_wp_error( $id ) || ! $id ) {
		WP_CLI::error( 'Could not create card main-image attachment: ' . $filename );
	}
	update_post_meta( (int) $id, '_opf_card_main_image_fixture', '1' );
	$image_ids[ $key ] = (int) $id;
}
$product = wc_get_product( $product_id );
$product->set_image_id( $image_ids['front'] );
$product->set_gallery_image_ids( [ $image_ids['gallery'] ] );
$product->save();

$external_path = trailingslashit( $upload['basedir'] ) . $images['external'];
$group = new OPF\Engine\FieldGroup( [
	'fields'      => [
		[
			'id' => 'finish', 'label' => 'Choose a finish', 'type' => 'radio', 'card_layout' => 'horizontal', 'change_product_image' => true,
			'choices' => [
				[ 'slug' => 'gallery', 'label' => 'Gallery image', 'image' => wp_get_attachment_url( $image_ids['gallery'] ) ],
				[ 'slug' => 'external', 'label' => 'External target', 'image' => trailingslashit( $upload['baseurl'] ) . $images['external'] ],
				[ 'slug' => 'unmatched', 'label' => 'No image target' ],
			],
		],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
$saved    = $group_id ? OPF\Service\FieldGroups::group_from_post( get_post( $group_id ) ) : null;
if ( ! $product_id || ! $group_id || ! $saved || empty( $saved->data['fields'][0]['change_product_image'] ) || count( $product->get_gallery_image_ids() ) !== 1 || ! is_file( $external_path ) ) {
	WP_CLI::error( 'Card main-image fixture failed to save its choices, gallery, and external target.' );
}

WP_CLI::log( 'CARD_IMAGE_PRODUCT=' . $product_id );
WP_CLI::log( 'CARD_IMAGE_GROUP=' . $group_id );
WP_CLI::log( 'CARD_IMAGE_URL=' . get_permalink( $product_id ) );
WP_CLI::log( 'CARD_IMAGE_INITIAL=' . wp_get_attachment_url( $image_ids['front'] ) );
WP_CLI::log( 'CARD_IMAGE_GALLERY=' . wp_get_attachment_url( $image_ids['gallery'] ) );
WP_CLI::log( 'CARD_IMAGE_EXTERNAL=' . trailingslashit( $upload['baseurl'] ) . $images['external'] );
WP_CLI::success( 'Card main-image E2E fixture prepared.' );
