<?php
/** Disposable cart-edit fixture. Usage: wp eval-file ... prepare|cleanup. */

defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_cart_edit_state';
$title = 'OPF E2E Cart Edit Group';
$slug = 'opf-e2e-cart-edit-product';
$state = get_option( $state_key, [] );

if ( 'cleanup' === $action ) {
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] ) as $post ) {
		wp_delete_post( (int) $post->ID, true );
	}
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => $slug ] ) as $product ) {
		$product->delete( true );
	}
	if ( is_array( $state ) && array_key_exists( 'previous_edit_cart', $state ) ) {
		if ( null === $state['previous_edit_cart'] ) {
			delete_option( 'opf_edit_cart' );
		} else {
			update_option( 'opf_edit_cart', $state['previous_edit_cart'] );
		}
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Cart-edit fixture removed and previous setting restored.' );
	return;
}

if ( 'prepare' !== $action || $state ) {
	WP_CLI::error( 'Use prepare or cleanup; clean an existing fixture before retrying.' );
}

$previous = get_option( 'opf_edit_cart', null );
$state = [ 'previous_edit_cart' => $previous ];
update_option( $state_key, $state, false );
update_option( 'opf_edit_cart', 'yes' );

$product = new WC_Product_Simple();
$product->set_name( 'OPF E2E Cart Edit Product' );
$product->set_slug( $slug );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'visible' );
$product_id = $product->save();
$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'choices' => [
			[ 'slug' => 'blue', 'label' => 'Blue', 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ],
			[ 'slug' => 'red', 'label' => 'Red', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
		] ],
		[ 'id' => 'message', 'label' => 'Personal message', 'type' => 'text', 'default' => 'Original' ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
if ( ! $product_id || ! $group_id ) {
	WP_CLI::error( 'Cart-edit fixture failed to save.' );
}
$state['product_id'] = $product_id;
$state['group_id'] = $group_id;
update_option( $state_key, $state, false );
OPF\Service\FieldGroups::flush_cache();
WP_CLI::log( 'CART_EDIT_PRODUCT=' . $product_id );
WP_CLI::log( 'CART_EDIT_GROUP=' . $group_id );
WP_CLI::log( 'CART_EDIT_URL=' . get_permalink( $product_id ) );
WP_CLI::success( 'Cart-edit fixture prepared.' );
