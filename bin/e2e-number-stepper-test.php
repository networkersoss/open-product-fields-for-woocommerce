<?php
/** Disposable number-stepper setting/cart fixture. Usage: wp eval-file ... prepare|verify|cleanup */
defined( 'ABSPATH' ) || exit;

$action    = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_number_stepper_state';
$title     = 'OPF E2E Number Stepper Group';
$state     = get_option( $state_key, [] );

if ( 'cleanup' === $action ) {
	if ( is_array( $state ) ) {
		foreach ( (array) ( $state['group_ids'] ?? [] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( ! empty( $state['product_id'] ) ) {
			wp_delete_post( (int) $state['product_id'], true );
		}
		if ( null === ( $state['previous_number_buttons'] ?? null ) ) {
			delete_option( 'opf_number_buttons' );
		} else {
			update_option( 'opf_number_buttons', $state['previous_number_buttons'] );
		}
	}
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] ) as $post ) {
		wp_delete_post( (int) $post->ID, true );
	}
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => 'OPF E2E Number Stepper Product' ] ) as $product ) {
		$product->delete( true );
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Number-stepper fixtures removed and prior global setting restored.' );
	return;
}

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Number-stepper fixture already exists; clean it before retrying.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF E2E Number Stepper Product' );
	$product->set_slug( 'opf-e2e-number-stepper-product' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product_id = $product->save();
	$previous = get_option( 'opf_number_buttons', null );
	$state = [ 'product_id' => $product_id, 'group_ids' => [], 'previous_number_buttons' => $previous ];
	update_option( $state_key, $state, false );
	update_option( 'opf_number_buttons', 'yes' );
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [ [ 'id' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'placeholder' => 'Enter amount', 'default' => '0.5', 'min' => 0, 'max' => 1, 'step' => 0.25 ] ],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
	if ( ! $product_id || ! $group_id ) {
		WP_CLI::error( 'Number-stepper fixture failed to save.' );
	}
	$state['group_ids'] = [ $group_id ];
	update_option( $state_key, $state, false );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::log( 'NUMBER_STEPPER_URL=' . get_permalink( $product_id ) );
	WP_CLI::log( 'NUMBER_STEPPER_IDS=' . wp_json_encode( $state ) );
	WP_CLI::success( 'Number-stepper fixture prepared.' );
	return;
}

if ( 'verify' === $action ) {
	if ( ! is_array( $state ) || empty( $state['product_id'] ) || empty( $state['group_ids'][0] ) ) {
		WP_CLI::error( 'Number-stepper fixture is missing.' );
	}
	$product_id = (int) $state['product_id'];
	$group_id   = (string) $state['group_ids'][0];
	$cart       = WC()->cart;
	$order      = null;
	$key        = '';
	try {
		$_POST['opf'] = [ $group_id => [ 'quantity' => '0.75' ] ];
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1, 0, [], [] );
		if ( ! $passed ) {
			WP_CLI::error( 'A valid 0.75 value aligned to the 0.25 step was rejected.' );
		}
		$key = (string) $cart->add_to_cart( $product_id, 1 );
		$item = $key ? $cart->get_cart_item( $key ) : null;
		$value = $item[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ]['quantity'] ?? null;
		if ( '0.75' !== (string) $value ) {
			WP_CLI::error( 'The native number value did not survive cart insertion.' );
		}
		$order = wc_create_order( [ 'status' => 'pending' ] );
		if ( is_wp_error( $order ) ) {
			WP_CLI::error( 'Could not create disposable number-stepper order.' );
		}
		$order_item = new WC_Order_Item_Product();
		$order_item->set_product( $item['data'] );
		$order_item->set_quantity( 1 );
		$order_item->set_subtotal( 10.0 );
		$order_item->set_total( 10.0 );
		OPF\Service\CartIntegration::persist_order_item( $order_item, $key, $item, $order );
		$order->add_item( $order_item );
		$order->save();
		$stored = json_decode( (string) $order_item->get_meta( '_opf_fields', true ), true );
		$again = OPF\Service\CartIntegration::restore_order_again( [], $order_item, $order );
		if ( '0.75' !== (string) ( $stored[ $group_id ]['quantity'] ?? '' ) || '0.75' !== (string) ( $again[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ]['quantity'] ?? '' ) ) {
			WP_CLI::error( 'Native number value did not persist in order metadata and order-again.' );
		}
		unset( $_POST['opf'][ $group_id ]['quantity'] );
		$_POST['opf'][ $group_id ]['quantity'] = '0.7';
		$rejected = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1, 0, [], [] );
		if ( $rejected ) {
			WP_CLI::error( 'Misaligned decimal value bypassed server step validation.' );
		}
		WP_CLI::success( 'Step buttons preserve Woo native input serialization; 0.75 survived cart/order/order-again and 0.7 was rejected server-side.' );
	} finally {
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
		if ( $key ) {
			$cart->remove_cart_item( $key );
		}
		unset( $_POST['opf'] );
	}
	return;
}

WP_CLI::error( 'Use prepare, verify, or cleanup.' );
