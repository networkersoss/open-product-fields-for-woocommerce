<?php
/** Disposable custom-calendar week-start fixture. Usage: wp eval-file ... prepare|cleanup */
defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_date_week_start_state';
$title = 'OPF E2E Date Week Start Group';
$product_name = 'OPF E2E Date Week Start Product';
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => $product_name ] );

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) {
		wp_delete_post( (int) $group->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	$state = get_option( $state_key, [] );
	if ( is_array( $state ) && array_key_exists( 'start_of_week', $state ) ) {
		update_option( 'start_of_week', $state['start_of_week'] );
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Date week-start fixture removed and WordPress week setting restored.' );
	return;
}

if ( get_option( $state_key, false ) ) {
	WP_CLI::error( 'Date week-start fixture is already prepared.' );
}
add_option( $state_key, [ 'start_of_week' => get_option( 'start_of_week', 0 ) ], '', false );
update_option( 'start_of_week', 0 );

$product = $products ? $products[0] : new WC_Product_Simple();
$product->set_name( $product_name );
$product->set_slug( 'opf-e2e-date-week-start-product' );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
$group = new OPF\Engine\FieldGroup( [
	'fields' => [ [ 'id' => 'delivery_date', 'label' => 'Delivery date', 'type' => 'date' ] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
if ( ! $product_id || ! $group_id ) {
	WP_CLI::error( 'Date week-start fixture could not be created; run cleanup before retrying.' );
}
OPF\Service\FieldGroups::flush_cache();
WP_CLI::log( 'DATE_WEEK_START_URL=' . get_permalink( $product_id ) );
WP_CLI::success( 'Date week-start fixture prepared.' );
