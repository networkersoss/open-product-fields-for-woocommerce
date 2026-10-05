<?php
/** Disposable optional/forced browser-editor fixture. Usage: prepare|cleanup */
defined( 'ABSPATH' ) || exit;
$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title = 'OPF E2E Upload Editor Group';
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'meta_key' => '_opf_upload_editor_fixture', 'meta_value' => '1', 'status' => [ 'publish', 'draft', 'private' ] ] );
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) wp_delete_post( (int) $group->ID, true );
	foreach ( $products as $product ) $product->delete( true );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Upload-editor fixtures removed.' );
	return;
}
if ( 'prepare' !== $action ) WP_CLI::error( 'Use prepare or cleanup.' );
$product = $products ? $products[0] : new WC_Product_Simple();
$product->set_name( 'OPF E2E Upload Editor Product' );
$product->set_slug( 'opf-e2e-upload-editor-product' );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
update_post_meta( $product_id, '_opf_upload_editor_fixture', '1' );
$editor = [ 'type' => 'upload', 'allowed_types' => [ 'png' ], 'image_editor_crop' => true, 'image_editor_resize' => true, 'image_editor_rotate' => true, 'image_editor_flip' => true, 'image_editor_aspect_ratio' => '1:1' ];
$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		array_merge( $editor, [ 'id' => 'forced_art', 'label' => 'Forced artwork', 'image_editor_mode' => 'forced' ] ),
		array_merge( $editor, [ 'id' => 'optional_art', 'label' => 'Optional artwork', 'image_editor_mode' => 'optional' ] ),
		array_merge( $editor, [ 'id' => 'rounded_art', 'label' => 'Rounded artwork', 'image_editor_mode' => 'forced', 'image_editor_aspect_ratio' => '16:9' ] ),
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
if ( ! $product_id || ! $group_id ) WP_CLI::error( 'Upload-editor fixture could not be saved.' );
WP_CLI::log( 'UPLOAD_EDITOR_PRODUCT=' . $product_id );
WP_CLI::log( 'UPLOAD_EDITOR_GROUP=' . $group_id );
WP_CLI::success( 'Upload-editor fixture prepared.' );
