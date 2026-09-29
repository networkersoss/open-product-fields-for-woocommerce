<?php
/**
 * Disposable WooCommerce conditional-image fixture.
 *
 * Usage: wp eval-file bin/e2e-image-change-test.php prepare|cleanup
 */

defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title = 'OPF E2E Conditional Image Group';
$product_name = 'OPF E2E Conditional Image Product';
$upload = wp_upload_dir();
$image_files = [
	'front' => [ 'name' => 'opf-image-change-front.png', 'data' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' ) ],
	'back' => [ 'name' => 'opf-image-change-back.png', 'data' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' ) ],
	'side' => [ 'name' => 'opf-image-change-side.png', 'data' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' ) ],
	'external' => [ 'name' => 'opf-image-change-external.png', 'data' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' ) ],
];
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'name' => sanitize_title( $product_name ), 'status' => [ 'publish', 'draft', 'private' ] ] );
$attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_opf_image_change_fixture', 'meta_value' => '1' ] );
$attachment_by_file = [];
foreach ( $attachments as $fixture_attachment ) {
	$attached_file = get_attached_file( (int) $fixture_attachment->ID );
	if ( $attached_file ) {
		$attachment_by_file[ basename( $attached_file ) ] = (int) $fixture_attachment->ID;
	}
}

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group_post ) {
		wp_delete_post( (int) $group_post->ID, true );
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
	foreach ( $image_files as $fixture ) {
		$path = trailingslashit( $upload['basedir'] ) . $fixture['name'];
		if ( is_file( $path ) && hash( 'sha256', $fixture['data'] ) === hash_file( 'sha256', $path ) ) {
			wp_delete_file( $path );
		}
	}
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Conditional image E2E fixtures removed.' );
	return;
}

$product = $products ? $products[0] : new WC_Product_Simple();
$product->set_name( $product_name );
$product->set_slug( sanitize_title( $product_name ) );
$product->set_regular_price( '100.00' );
$product->set_status( 'publish' );
$product_id = $product->save();

$ids = [];
foreach ( [ 'front', 'back', 'side' ] as $key ) {
	$fixture = $image_files[ $key ];
	$path = trailingslashit( $upload['basedir'] ) . $fixture['name'];
	if ( is_file( $path ) && hash( 'sha256', $fixture['data'] ) !== hash_file( 'sha256', $path ) ) {
		WP_CLI::error( 'Image fixture path already exists with different content: ' . $fixture['name'] );
	}
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $fixture['data'] );
	}
	$attachment_id = $attachment_by_file[ $fixture['name'] ] ?? wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF image change ' . $key, 'post_status' => 'inherit' ], $path, $product_id, true );
	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		WP_CLI::error( 'Could not create image attachment: ' . $fixture['name'] );
	}
	update_post_meta( (int) $attachment_id, '_opf_image_change_fixture', '1' );
	$ids[ $key ] = (int) $attachment_id;
}
$product = wc_get_product( $product_id );
$product->set_image_id( $ids['front'] );
$product->set_gallery_image_ids( [ $ids['back'], $ids['side'] ] );
$product->save();

$external_path = trailingslashit( $upload['basedir'] ) . $image_files['external']['name'];
if ( is_file( $external_path ) && hash( 'sha256', $image_files['external']['data'] ) !== hash_file( 'sha256', $external_path ) ) {
	WP_CLI::error( 'External image fixture path already exists with different content.' );
}
if ( ! is_file( $external_path ) ) {
	wp_mkdir_p( dirname( $external_path ) );
	file_put_contents( $external_path, $image_files['external']['data'] );
}

$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'color', 'label' => 'Color', 'type' => 'select', 'default' => 'green', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ], [ 'slug' => 'green', 'label' => 'Green' ] ] ],
		[ 'id' => 'size', 'label' => 'Size', 'type' => 'select', 'default' => 'small', 'choices' => [ [ 'slug' => 'small', 'label' => 'Small' ], [ 'slug' => 'large', 'label' => 'Large' ] ] ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	'image_rules' => [
		[ 'target_url' => wp_get_attachment_url( $ids['back'] ), 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => 'large' ] ] ],
		[ 'target_url' => trailingslashit( $upload['baseurl'] ) . $image_files['external']['name'], 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => '*' ] ] ],
		[ 'target_url' => wp_get_attachment_url( $ids['side'] ), 'conditions' => [ [ 'field' => 'color', 'value' => 'blue' ], [ 'field' => 'size', 'value' => '*' ] ] ],
	],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
$saved = $group_id ? OPF\Service\FieldGroups::group_from_post( get_post( $group_id ) ) : null;
if ( ! $product_id || ! $group_id || ! $saved || count( $saved->data['image_rules'] ?? [] ) !== 3 || count( $product->get_gallery_image_ids() ) !== 2 ) {
	WP_CLI::error( 'Conditional image fixture did not round-trip with its product gallery and three rules.' );
}

WP_CLI::log( 'IMAGE_CHANGE_PRODUCT=' . $product_id );
WP_CLI::log( 'IMAGE_CHANGE_GROUP=' . $group_id );
WP_CLI::log( 'IMAGE_CHANGE_URL=' . get_permalink( $product_id ) );
WP_CLI::log( 'IMAGE_CHANGE_INITIAL=' . wp_get_attachment_url( $ids['front'] ) );
WP_CLI::log( 'IMAGE_CHANGE_GALLERY=' . wp_get_attachment_url( $ids['back'] ) . ',' . wp_get_attachment_url( $ids['side'] ) );
WP_CLI::log( 'IMAGE_CHANGE_EXTERNAL=' . trailingslashit( $upload['baseurl'] ) . $image_files['external']['name'] );
WP_CLI::success( 'Conditional image fixture prepared.' );
