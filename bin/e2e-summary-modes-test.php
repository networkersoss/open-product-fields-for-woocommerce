<?php
/** Disposable summary-mode product/group. Usage: wp eval-file ... prepare|cleanup. */
defined( 'ABSPATH' ) || exit;

$action    = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_summary_modes_state';
$title     = 'OPF E2E Summary Modes Group';
$slug      = 'opf-e2e-summary-modes-product';
$state     = get_option( $state_key, [] );

if ( 'cleanup' === $action ) {
	$state = is_array( $state ) ? $state : [];
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] ) as $post ) {
		wp_delete_post( (int) $post->ID, true );
	}
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => $slug ] ) as $product ) {
		$product->delete( true );
	}
	if ( ! empty( $state['summary_mode_exists'] ) ) {
		update_option( 'opf_price_summary_mode', $state['summary_mode'] );
	} else {
		delete_option( 'opf_price_summary_mode' );
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Summary-mode fixture removed; previous setting restored.' );
	return;
}

if ( 'prepare' !== $action ) {
	WP_CLI::error( 'Use prepare or cleanup.' );
}
if ( $state ) {
	WP_CLI::error( 'Summary-mode fixture already exists; clean it before retrying.' );
}

$product = new WC_Product_Simple();
$product->set_name( 'OPF E2E Summary Modes Product' );
$product->set_slug( $slug );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'visible' );
$product_id = $product->save();
$previous_mode = get_option( 'opf_price_summary_mode', null );
$state = [
	'product_id'          => $product_id,
	'summary_mode_exists' => null !== $previous_mode,
	'summary_mode'        => $previous_mode,
];
update_option( $state_key, $state, false );

$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'choices' => [
			[ 'slug' => 'standard', 'label' => 'Standard' ],
			[ 'slug' => 'premium', 'label' => 'Premium', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
		] ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
if ( ! $product_id || ! $group_id ) {
	WP_CLI::error( 'Could not prepare summary-mode product and field group.' );
}
$state['group_id'] = $group_id;
update_option( $state_key, $state, false );
OPF\Service\FieldGroups::flush_cache();
WP_CLI::log( 'SUMMARY_MODE_PRODUCT=' . $product_id );
WP_CLI::log( 'SUMMARY_MODE_URL=' . get_permalink( $product_id ) );
WP_CLI::success( 'Summary-mode fixture prepared.' );
