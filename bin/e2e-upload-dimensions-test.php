<?php
/** Disposable upload-dimensions fixture. Usage: wp eval-file ... prepare|cleanup */
defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title  = 'OPF E2E Upload Dimensions Group';
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'meta_key' => '_opf_upload_dimensions_fixture', 'meta_value' => '1', 'status' => [ 'publish', 'draft', 'private' ] ] );
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) wp_delete_post( (int) $group->ID, true );
	foreach ( $products as $product ) $product->delete( true );
	OPF\Service\FieldGroups::flush_cache();
	WC()->cart->empty_cart( true );
	WP_CLI::success( 'Upload-dimensions fixtures removed.' );
	return;
}

if ( 'prepare' !== $action ) WP_CLI::error( 'Use prepare or cleanup.' );
$product = $products ? $products[0] : new WC_Product_Simple();
$product->set_name( 'OPF E2E Upload Dimensions Product' );
$product->set_slug( 'opf-e2e-upload-dimensions-product' );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
update_post_meta( $product_id, '_opf_upload_dimensions_fixture', '1' );
$group = new OPF\Engine\FieldGroup( [
	'fields' => [ [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'min_width' => 2, 'min_height' => 2, 'allowed_types' => [ 'png' ] ] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
if ( ! $product_id || ! $group_id ) WP_CLI::error( 'Upload-dimensions fixture could not be saved.' );
WP_CLI::log( 'UPLOAD_DIMENSIONS_PRODUCT=' . $product_id );
WP_CLI::log( 'UPLOAD_DIMENSIONS_GROUP=' . $group_id );
WP_CLI::log( 'UPLOAD_DIMENSIONS_URL=' . get_permalink( $product_id ) );
WP_CLI::success( 'Upload-dimensions fixture prepared.' );
