<?php
/** Disposable formula-driven weight cart/order test. Usage: wp eval-file ... prepare|run|cleanup */
defined( 'ABSPATH' ) || exit;

$action       = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title        = 'OPF E2E Formula Weight Group';
$product_name = 'OPF E2E Formula Weight Product';
$order_option = 'opf_e2e_formula_weight_order';
$groups       = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products     = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'meta_key' => '_opf_formula_weight_fixture', 'meta_value' => '1', 'status' => [ 'publish', 'draft', 'private' ] ] );

if ( 'cleanup' === $action ) {
	$order_id = (int) get_option( $order_option, 0 );
	if ( $order_id && wc_get_order( $order_id ) ) {
		wc_get_order( $order_id )->delete( true );
	}
	foreach ( $groups as $group ) {
		wp_delete_post( (int) $group->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	delete_option( $order_option );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Formula-weight E2E fixtures removed.' );
	return;
}

if ( 'prepare' === $action ) {
	$product = $products ? $products[0] : new WC_Product_Simple();
	$product->set_name( $product_name );
	$product->set_slug( sanitize_title( $product_name ) );
	$product->set_regular_price( '12.00' );
	$product->set_weight( '0.25' );
	$product->set_status( 'publish' );
	$product_id = $product->save();
	update_post_meta( $product_id, '_opf_formula_weight_fixture', '1' );
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [ [ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number', 'required' => true, 'min' => 0.25, 'step' => 0.25, 'weight_formula' => '[field.weight] * 1' ] ],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
	if ( ! $product_id || ! $group_id ) {
		WP_CLI::error( 'Formula-weight fixture could not be saved.' );
	}
	WP_CLI::log( 'FORMULA_WEIGHT_PRODUCT=' . $product_id );
	WP_CLI::log( 'FORMULA_WEIGHT_GROUP=' . $group_id );
	WP_CLI::log( 'FORMULA_WEIGHT_URL=' . get_permalink( $product_id ) );
	WP_CLI::success( 'Formula-weight fixture prepared.' );
	return;
}

if ( 'run' !== $action || ! $products || ! $groups ) {
	WP_CLI::error( 'Prepare the formula-weight fixture before running the test.' );
}

$product   = $products[0];
$product_id = (int) $product->get_id();
$group_id  = (string) $groups[0]->ID;
$checks    = 0;
$check     = static function ( string $name, bool $passed ) use ( &$checks ): void {
	$checks++;
	if ( ! $passed ) {
		WP_CLI::error( 'Formula-weight E2E failed: ' . $name );
	}
	WP_CLI::log( '  ok    ' . $name );
};
$cart = WC()->cart;
$cart->empty_cart( true );
$_POST['opf'] = [ $group_id => [ 'weight' => '1.75' ] ];
$added = $cart->add_to_cart( $product_id, 2 );
unset( $_POST['opf'] );
$check( 'real WooCommerce add-to-cart accepts the validated number field', (bool) $added );
$cart->calculate_totals();
$cart_items = $cart->get_cart();
$cart_item  = reset( $cart_items );
$check( 'formula adds requested per-item weight to the product base weight', $cart_item && abs( (float) $cart_item['data']->get_weight() - 2.0 ) < 0.00001 );
$check( 'WooCommerce cart and shipping weight multiply per-item weight by line quantity', abs( (float) $cart->get_cart_contents_weight() - 4.0 ) < 0.00001 );
$packages = $cart->get_shipping_packages();
$package_weight = 0.0;
foreach ( (array) ( $packages[0]['contents'] ?? [] ) as $package_item ) {
	if ( isset( $package_item['data'] ) && $package_item['data'] instanceof WC_Product ) {
		$package_weight += (float) $package_item['data']->get_weight() * (int) ( $package_item['quantity'] ?? 0 );
	}
}
$check( 'WooCommerce shipping package receives formula-adjusted item and quantity weight', abs( $package_weight - 4.0 ) < 0.00001 );

$order = wc_create_order();
$line_id = $order->add_product( $cart_item['data'], (int) $cart_item['quantity'] );
$line = $order->get_item( $line_id );
OPF\Service\CartIntegration::persist_order_item( $line, (string) key( $cart_items ), $cart_item, $order );
$line->save();
$order->calculate_totals();
$order->save();
$stored = json_decode( (string) $line->get_meta( '_opf_fields', true ), true );
$check( 'order line persists the numeric selection required to reproduce formula weight', is_array( $stored ) && ( $stored[ $group_id ]['weight'] ?? '' ) === '1.75' );
update_option( $order_option, (int) $order->get_id(), false );

$restored = OPF\Service\CartIntegration::restore_order_again( [], $line, $order );
$check( 'order-again restores the validated field structure', isset( $restored[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ]['weight'] ) && '1.75' === $restored[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ]['weight'] );
$cart->empty_cart( true );
$cart->add_to_cart( $product_id, 2, 0, [], $restored );
$cart->calculate_totals();
$again_items = $cart->get_cart();
$again_item = reset( $again_items );
$check( 'order-again cart recalculates identical WooCommerce shipping weight', $again_item && abs( (float) $again_item['data']->get_weight() - 2.0 ) < 0.00001 && abs( (float) $cart->get_cart_contents_weight() - 4.0 ) < 0.00001 );
$again_packages = $cart->get_shipping_packages();
$again_package_weight = 0.0;
foreach ( (array) ( $again_packages[0]['contents'] ?? [] ) as $package_item ) {
	if ( isset( $package_item['data'] ) && $package_item['data'] instanceof WC_Product ) {
		$again_package_weight += (float) $package_item['data']->get_weight() * (int) ( $package_item['quantity'] ?? 0 );
	}
}
$check( 'order-again shipping package receives the same formula-adjusted weight', abs( $again_package_weight - 4.0 ) < 0.00001 );

$group_data = OPF\Service\FieldGroups::group_from_post( $groups[0] )->data;
$group_data['fields'][0]['weight_formula'] = '[field.weight] * -1';
OPF\Service\FieldGroups::save( (int) $groups[0]->ID, new OPF\Engine\FieldGroup( $group_data ), [ 'title' => $title ] );
$cart->empty_cart( true );
$_POST['opf'] = [ $group_id => [ 'weight' => '3' ] ];
$cart->add_to_cart( $product_id, 1 );
unset( $_POST['opf'] );
$cart->calculate_totals();
$negative_items = $cart->get_cart();
$negative_item = reset( $negative_items );
$check( 'negative formula cannot set WooCommerce product weight below zero', $negative_item && 0.0 === (float) $negative_item['data']->get_weight() );
$cart->empty_cart( true );
WP_CLI::log( 'FORMULA_WEIGHT_CHECKS=' . $checks );
WP_CLI::success( 'Formula-weight cart/order E2E passed.' );
