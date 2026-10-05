<?php
/** Disposable live-preview wizard fixture. Usage: wp eval-file ... prepare|verify|cleanup. */

defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$group_title = 'OPF E2E Live Preview Group';
$product_name = 'OPF E2E Live Preview Product';
$file_names = [ 'opf-live-preview-main.png', 'opf-live-preview-gallery.png' ];
$upload_file_name = 'opf-live-preview-upload-4x2.png';
$upload = wp_upload_dir();
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAADwyuo0AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQImWP8ICLCAANMDEgAABtqARyVdUwxAAAAAElFTkSuQmCC', true );
$upload_png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAADwyuo0AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQImWP8ICLCAANMDEgAABtqARyVdUwxAAAAAElFTkSuQmCC', true );
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $group_title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'name' => sanitize_title( $product_name ), 'status' => [ 'publish', 'draft', 'private' ] ] );
$attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_opf_live_preview_fixture', 'meta_value' => '1' ] );

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
	foreach ( $file_names as $name ) {
		$file = trailingslashit( $upload['basedir'] ) . $name;
		if ( is_file( $file ) && hash_file( 'sha256', $file ) === hash( 'sha256', $png ) ) {
			wp_delete_file( $file );
		}
	}
	$upload_file = trailingslashit( $upload['basedir'] ) . $upload_file_name;
	if ( is_file( $upload_file ) && hash_file( 'sha256', $upload_file ) === hash( 'sha256', $upload_png ) ) {
		wp_delete_file( $upload_file );
	}
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Live preview fixtures removed.' );
	return;
}

if ( 'verify' === $action ) {
	$product = $products[0] ?? null;
	if ( ! $product || ! $groups ) {
		WP_CLI::error( 'Live preview product/group missing.' );
	}
	$resolved = OPF\Service\LivePreview::for_product( $product );
	$config = $product->get_meta( OPF\Service\LivePreview::META_KEY, true );
	if ( count( $resolved ) !== 1 || 'text' !== $resolved[0]['source'] || 'gallery' !== $resolved[0]['target'] || (int) $resolved[0]['target_index'] !== 1 || 'preview_1_engraving' !== $resolved[0]['id'] || count( $config ) !== 1 || empty( $config[0]['dynamic']['color']['values']['blue'] ) ) {
		WP_CLI::error( 'Saved preview did not resolve to the selected gallery image identity and target field.' );
	}
	WP_CLI::success( 'Saved visual preview list retains the text item and resolves it to gallery slide index 1 after item removal.' );
	return;
}

if ( 'prepare' !== $action ) {
	WP_CLI::error( 'Use prepare, verify, or cleanup.' );
}

$product = $products[0] ?? new WC_Product_Simple();
$product->set_name( $product_name );
$product->set_slug( sanitize_title( $product_name ) );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
$image_ids = [];
foreach ( $file_names as $index => $name ) {
	$path = trailingslashit( $upload['basedir'] ) . $name;
	if ( is_file( $path ) && hash_file( 'sha256', $path ) !== hash( 'sha256', $png ) ) {
		WP_CLI::error( 'Live preview fixture path contains unexpected bytes: ' . $name );
	}
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $png );
	}
	$found = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_live_preview_fixture', 'meta_value' => '1', 'meta_query' => [ [ 'key' => '_wp_attached_file', 'value' => basename( $path ), 'compare' => 'LIKE' ] ] ] );
	$id = $found ? (int) $found[0]->ID : wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF live preview image ' . $index, 'post_status' => 'inherit' ], $path, $product_id, true );
	if ( is_wp_error( $id ) || ! $id ) {
		WP_CLI::error( 'Could not create live preview fixture image.' );
	}
	update_post_meta( (int) $id, '_opf_live_preview_fixture', '1' );
	$image_ids[] = (int) $id;
}
$upload_file = trailingslashit( $upload['basedir'] ) . $upload_file_name;
if ( is_file( $upload_file ) && hash_file( 'sha256', $upload_file ) !== hash( 'sha256', $upload_png ) ) {
	WP_CLI::error( 'Live preview upload fixture path contains unexpected bytes.' );
}
if ( ! is_file( $upload_file ) ) {
	file_put_contents( $upload_file, $upload_png );
}
$upload_dimensions = getimagesize( $upload_file );
if ( ! is_array( $upload_dimensions ) || 4 !== (int) $upload_dimensions[0] || 2 !== (int) $upload_dimensions[1] ) {
	WP_CLI::error( 'Live preview upload fixture must be a 4×2 image.' );
}
$product = wc_get_product( $product_id );
$product->set_image_id( $image_ids[0] );
$product->set_gallery_image_ids( [ $image_ids[1] ] );
$product->update_meta_data( OPF\Service\LivePreview::META_KEY, [] );
$product->save();

$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'engraving', 'label' => 'Engraving text', 'type' => 'text', 'required' => false ],
		[ 'id' => 'ink_multi', 'label' => 'Ink colors', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'red', 'label' => 'Ruby' ], [ 'slug' => 'blue', 'label' => 'Ocean' ] ] ],
		[ 'id' => 'size_choice', 'label' => 'Text size', 'type' => 'select', 'choices' => [ [ 'slug' => 'small', 'label' => 'Small' ], [ 'slug' => 'large', 'label' => 'Large' ] ] ],
		[ 'id' => 'font_choice', 'label' => 'Font family', 'type' => 'select', 'choices' => [ [ 'slug' => 'serif', 'label' => 'Serif' ], [ 'slug' => 'sans', 'label' => 'Sans' ] ] ],
		[ 'id' => 'align_choice', 'label' => 'Text alignment', 'type' => 'select', 'choices' => [ [ 'slug' => 'left', 'label' => 'Left' ], [ 'slug' => 'right', 'label' => 'Right' ] ] ],
		[ 'id' => 'logo', 'label' => 'Upload image overlay', 'type' => 'upload', 'max_files' => 1, 'max_size' => 1048576 ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $group_title ] );
OPF\Service\FieldGroups::flush_cache();
if ( ! $product_id || ! $group_id || ! OPF\Service\FieldGroups::group_from_post( get_post( $group_id ) ) ) {
	WP_CLI::error( 'Live preview fixture product/group did not save.' );
}

WP_CLI::log( 'LIVE_PREVIEW_PRODUCT=' . $product_id );
WP_CLI::log( 'LIVE_PREVIEW_GROUP=' . $group_id );
WP_CLI::log( 'LIVE_PREVIEW_PRODUCT_URL=' . get_permalink( $product_id ) );
WP_CLI::log( 'LIVE_PREVIEW_UPLOAD_FILE=' . $upload_file );
WP_CLI::success( 'Live preview fixture prepared.' );
