<?php
/** Disposable automatic image-resize product fixture. Usage: prepare|cleanup */
defined( 'ABSPATH' ) || exit;
$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title = 'OPF E2E Upload Resize Group';
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'meta_key' => '_opf_upload_resize_fixture', 'meta_value' => '1', 'status' => [ 'publish', 'draft', 'private' ] ] );
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) wp_delete_post( (int) $group->ID, true );
	foreach ( $products as $product ) $product->delete( true );
	OPF\Service\FieldGroups::flush_cache();
	WC()->cart->empty_cart( true );
	WP_CLI::success( 'Upload-resize fixtures removed.' );
	return;
}
if ( 'prepare' !== $action ) WP_CLI::error( 'Use prepare or cleanup.' );
$product = $products ? $products[0] : new WC_Product_Simple();
$product->set_name( 'OPF E2E Upload Resize Product' );
$product->set_slug( 'opf-e2e-upload-resize-product' );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
update_post_meta( $product_id, '_opf_upload_resize_fixture', '1' );
$group = new OPF\Engine\FieldGroup( [
	'fields' => [ [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'allowed_types' => [ 'png' ], 'auto_resize' => true, 'max_width' => 2 ] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
if ( ! $product_id || ! $group_id ) WP_CLI::error( 'Upload-resize fixture could not be saved.' );
WP_CLI::log( 'UPLOAD_RESIZE_PRODUCT=' . $product_id );
WP_CLI::log( 'UPLOAD_RESIZE_GROUP=' . $group_id );
WP_CLI::success( 'Upload-resize fixture prepared.' );
