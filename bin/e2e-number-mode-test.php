<?php
/** Disposable number-mode cart validation test. Usage: wp eval-file ... prepare|run|cleanup */
defined( 'ABSPATH' ) || exit;

$action   = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title    = 'OPF E2E Number Mode Group';
$name     = 'OPF E2E Number Mode Product';
$groups   = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'meta_key' => '_opf_number_mode_fixture', 'meta_value' => '1', 'status' => [ 'publish', 'draft', 'private' ] ] );

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) {
		wp_delete_post( (int) $group->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	OPF\Service\FieldGroups::flush_cache();
	WC()->cart->empty_cart( true );
	WP_CLI::success( 'Number-mode E2E fixtures removed.' );
	return;
}

if ( 'prepare' === $action ) {
	$product = $products ? $products[0] : new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( sanitize_title( $name ) );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product_id = $product->save();
	update_post_meta( $product_id, '_opf_number_mode_fixture', '1' );
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'count', 'label' => 'Count', 'type' => 'number', 'number_mode' => 'integer', 'min' => 1, 'step' => 1 ],
			[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number', 'number_mode' => 'decimal', 'min' => 1, 'step' => 0.25 ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
	if ( ! $product_id || ! $group_id ) {
		WP_CLI::error( 'Number-mode fixture could not be saved.' );
	}
	WP_CLI::log( 'NUMBER_MODE_PRODUCT=' . $product_id );
	WP_CLI::log( 'NUMBER_MODE_GROUP=' . $group_id );
	WP_CLI::log( 'NUMBER_MODE_URL=' . get_permalink( $product_id ) );
	WP_CLI::success( 'Number-mode fixture prepared.' );
	return;
}

if ( 'run' !== $action || ! $products || ! $groups ) {
	WP_CLI::error( 'Prepare the number-mode fixture before running the test.' );
}

$product_id = (int) $products[0]->get_id();
$group_id   = (string) $groups[0]->ID;
$matched_groups = OPF\Service\FieldGroups::for_product( wc_get_product( $product_id ) );
if ( ! in_array( (int) $group_id, array_map( 'intval', array_column( $matched_groups, 'id' ) ), true ) ) {
	WP_CLI::error( 'The fixture group did not match the test product; unrelated groups are not used for this test.' );
}
$checks     = 0;
$check      = static function ( string $label, bool $passed ) use ( &$checks ): void {
	$checks++;
	if ( ! $passed ) {
		WP_CLI::error( 'Number-mode E2E failed: ' . $label );
	}
	WP_CLI::log( '  ok    ' . $label );
};
$cart = WC()->cart;
$cart->empty_cart( true );
$_POST['opf'] = [ $group_id => [ 'count' => '2.5', 'weight' => '1.75' ] ];
$rejected_fraction = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );
if ( $rejected_fraction ) {
	$cart->add_to_cart( $product_id, 1 );
}
unset( $_POST['opf'] );
$notices = wc_get_notices( 'error' );
$check( 'server rejects fractional integer value even when submitted outside the browser', false === $rejected_fraction && str_contains( wp_strip_all_tags( wp_json_encode( $notices ) ), 'whole number' ) );
wc_clear_notices();

$_POST['opf'] = [ $group_id => [ 'count' => '2', 'weight' => '1.75' ] ];
$accepted = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );
if ( $accepted ) {
	$accepted = $cart->add_to_cart( $product_id, 1 );
}
unset( $_POST['opf'] );
$check( 'integer value and decimal value aligned to configured step pass real Woo add-to-cart', (bool) $accepted );
$cart_item = $accepted ? $cart->get_cart_item( $accepted ) : null;
$persisted = is_array( $cart_item[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ] ?? null ) ? $cart_item[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ] : [];
$check( 'accepted whole and decimal values persist on the Woo cart item', ( $persisted['count'] ?? '' ) === '2' && ( $persisted['weight'] ?? '' ) === '1.75' );
$cart->empty_cart( true );

$_POST['opf'] = [ $group_id => [ 'count' => '2', 'weight' => '1.80' ] ];
$rejected_step = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );
if ( $rejected_step ) {
	$cart->add_to_cart( $product_id, 1 );
}
unset( $_POST['opf'] );
$check( 'decimal mode still enforces the configured step from its minimum', false === $rejected_step );
wc_clear_notices();
$cart->empty_cart( true );
WP_CLI::log( 'NUMBER_MODE_CHECKS=' . $checks );
WP_CLI::success( 'Number-mode cart validation passed.' );
