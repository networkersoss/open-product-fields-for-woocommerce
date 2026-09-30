<?php
/** Disposable upload-count validation test. Usage: wp eval-file ... prepare|run|cleanup */
defined( 'ABSPATH' ) || exit;

$action   = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title    = 'OPF E2E Upload Count Group';
$name     = 'OPF E2E Upload Count Product';
$groups   = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'meta_key' => '_opf_upload_count_fixture', 'meta_value' => '1', 'status' => [ 'publish', 'draft', 'private' ] ] );

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) {
		wp_delete_post( (int) $group->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	OPF\Service\FieldGroups::flush_cache();
	WC()->cart->empty_cart( true );
	WP_CLI::success( 'Upload-count E2E fixtures removed.' );
	return;
}

if ( 'prepare' === $action ) {
	$product = $products ? $products[0] : new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( sanitize_title( $name ) );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product_id = $product->save();
	update_post_meta( $product_id, '_opf_upload_count_fixture', '1' );
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [ [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'min_files' => 2, 'max_files' => -1, 'allowed_types' => [ 'png' ] ] ],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
	if ( ! $product_id || ! $group_id ) {
		WP_CLI::error( 'Upload-count fixture could not be saved.' );
	}
	WP_CLI::log( 'UPLOAD_COUNT_PRODUCT=' . $product_id );
	WP_CLI::log( 'UPLOAD_COUNT_GROUP=' . $group_id );
	WP_CLI::log( 'UPLOAD_COUNT_URL=' . get_permalink( $product_id ) );
	WP_CLI::success( 'Upload-count fixture prepared.' );
	return;
}

if ( 'run' !== $action || ! $products || ! $groups ) {
	WP_CLI::error( 'Prepare the upload-count fixture before running the test.' );
}

$product_id = (int) $products[0]->get_id();
$group_id   = (string) $groups[0]->ID;
$_POST['opf'] = [ $group_id => [] ];
$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );
$errors = wc_get_notices( 'error' );
unset( $_POST['opf'] );
wc_clear_notices();
if ( $passed || ! str_contains( wp_strip_all_tags( wp_json_encode( $errors ) ), 'requires at least 2 file(s)' ) ) {
	WP_CLI::error( 'The production Woo validation filter did not reject an empty field below its minimum file count.' );
}
WP_CLI::log( '  ok    server add-to-cart validation enforces minimum file count' );
WP_CLI::success( 'Upload-count validation passed.' );
