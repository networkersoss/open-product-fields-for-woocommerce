<?php
/** Disposable instruction-tooltip fixture. Usage: wp eval-file ... prepare|cleanup */
defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title = 'OPF E2E Instruction Tooltip Group';
$product_name = 'OPF E2E Instruction Tooltip Product';
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'name' => sanitize_title( $product_name ), 'status' => [ 'publish', 'draft', 'private' ] ] );

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) {
		wp_delete_post( (int) $group->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Instruction-tooltip fixtures removed.' );
	return;
}

$product = $products ? $products[0] : new WC_Product_Simple();
$product->set_name( $product_name );
$product->set_slug( sanitize_title( $product_name ) );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
$group = new OPF\Engine\FieldGroup( [
	'fields' => [ [ 'id' => 'engraving', 'label' => 'Engraving', 'description' => 'Use up to 12 characters.', 'description_presentation' => 'tooltip', 'type' => 'text' ] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
$saved = $group_id ? OPF\Service\FieldGroups::group_from_post( get_post( $group_id ) ) : null;
if ( ! $product_id || ! $group_id || ! $saved || ( $saved->data['fields'][0]['description_presentation'] ?? '' ) !== 'tooltip' ) {
	WP_CLI::error( 'Instruction-tooltip fixture failed to save.' );
}
WP_CLI::log( 'TOOLTIP_URL=' . get_permalink( $product_id ) );
WP_CLI::success( 'Instruction-tooltip fixture prepared.' );
