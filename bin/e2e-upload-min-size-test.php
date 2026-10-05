<?php
/** Disposable minimum-upload-size fixture. Usage: wp eval-file ... prepare|cleanup */
defined( 'ABSPATH' ) || exit;
$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title = 'OPF E2E Minimum Upload Size Group';
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'meta_key' => '_opf_upload_min_size_fixture', 'meta_value' => '1', 'status' => [ 'publish', 'draft', 'private' ] ] );
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) wp_delete_post( (int) $group->ID, true );
	foreach ( $products as $product ) $product->delete( true );
	OPF\Service\FieldGroups::flush_cache();
	WC()->cart->empty_cart( true );
	WP_CLI::success( 'Minimum-upload-size fixtures removed.' );
	return;
}
if ( 'prepare' !== $action ) WP_CLI::error( 'Use prepare or cleanup.' );
$product = $products ? $products[0] : new WC_Product_Simple();
$product->set_name( 'OPF E2E Minimum Upload Size Product' );
$product->set_slug( 'opf-e2e-min-size-product' );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
update_post_meta( $product_id, '_opf_upload_min_size_fixture', '1' );
$group = new OPF\Engine\FieldGroup( [
	'fields' => [ [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'min_size_mb' => 0.01, 'allowed_types' => [ 'txt' ] ] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
if ( ! $product_id || ! $group_id ) WP_CLI::error( 'Minimum-upload-size fixture could not be saved.' );
WP_CLI::log( 'UPLOAD_MIN_SIZE_PRODUCT=' . $product_id );
WP_CLI::log( 'UPLOAD_MIN_SIZE_GROUP=' . $group_id );
WP_CLI::success( 'Minimum-upload-size fixture prepared.' );
