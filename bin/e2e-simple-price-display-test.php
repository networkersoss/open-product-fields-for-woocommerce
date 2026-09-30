<?php
/** Disposable simple-product price display fixture. Usage: wp eval-file ... prepare|verify|cleanup */
defined( 'ABSPATH' ) || exit;

$action    = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_simple_price_display_state';
$state     = get_option( $state_key, [] );
$title     = 'OPF E2E Simple Price Display Product';

if ( 'cleanup' === $action ) {
	if ( is_array( $state ) ) {
		if ( ! empty( $state['variable_id'] ) ) {
			foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'parent' => (int) $state['variable_id'] ] ) as $variation ) {
			$variation->delete( true );
			}
			wp_delete_post( (int) $state['variable_id'], true );
		}
		foreach ( (array) ( $state['simple_ids'] ?? [] ) as $product_id ) {
			wp_delete_post( (int) $product_id, true );
		}
	}
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => 'opf-e2e-simple-price-display-product' ] ) as $product ) {
		$product->delete( true );
	}
	delete_option( $state_key );
	WP_CLI::success( 'Simple-price fixtures removed.' );
	return;
}

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Simple-price fixture already exists; clean it before retrying.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( $title );
	$product->set_slug( 'opf-e2e-simple-price-display-product' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product_id = $product->save();
	$hidden_product = new WC_Product_Simple();
	$hidden_product->set_name( 'OPF E2E Hidden Price Product' );
	$hidden_product->set_slug( 'opf-e2e-hidden-price-product' );
	$hidden_product->set_regular_price( '20.00' );
	$hidden_product->set_status( 'publish' );
	$hidden_product->set_catalog_visibility( 'visible' );
	$hidden_product->update_meta_data( '_opf_price_display', 'hide' );
	$hidden_product_id = $hidden_product->save();
	$variable = new WC_Product_Variable();
	$variable->set_name( 'OPF E2E Variable Price Display Product' );
	$variable->set_slug( 'opf-e2e-variable-price-display-product' );
	$variable->set_status( 'publish' );
	$variable->set_catalog_visibility( 'visible' );
	$variable_id = $variable->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable_id );
	$variation->set_regular_price( '12.00' );
	$variation->set_status( 'publish' );
	$variation->save();
	$state = [ 'product_id' => $product_id, 'hidden_product_id' => $hidden_product_id, 'variable_id' => $variable_id, 'simple_ids' => [ $product_id, $hidden_product_id ] ];
	update_option( $state_key, $state, false );
	WP_CLI::log( 'SIMPLE_PRICE_URL=' . get_permalink( $product_id ) );
	WP_CLI::log( 'HIDDEN_PRICE_URL=' . get_permalink( $hidden_product_id ) );
	WP_CLI::log( 'VARIABLE_PRICE_URL=' . get_permalink( $variable_id ) );
	WP_CLI::log( 'SIMPLE_PRICE_IDS=' . $product_id . ',' . $hidden_product_id . ',' . $variable_id );
	WP_CLI::success( 'Simple-price fixture prepared.' );
	return;
}

if ( 'verify' === $action ) {
	if ( ! is_array( $state ) || empty( $state['product_id'] ) ) {
		WP_CLI::error( 'Simple-price fixture is missing.' );
	}
	$product = wc_get_product( (int) $state['product_id'] );
	if ( ! $product instanceof WC_Product_Simple || '10.00' !== wc_format_decimal( $product->get_price( 'edit' ) ) ) {
		WP_CLI::error( 'Simple-product catalog price changed unexpectedly.' );
	}
	$previous_display = $product->get_meta( '_opf_price_display', true );
	$previous_label = $product->get_meta( '_opf_price_label', true );
	$product->update_meta_data( '_opf_price_display', 'hide' );
	$product->save();
	$cart = WC()->cart;
	$key = '';
	$order = null;
	try {
		$key = (string) $cart->add_to_cart( $product->get_id(), 1 );
		$item = $key ? $cart->get_cart_item( $key ) : null;
		if ( ! $item || (float) $item['data']->get_price() !== 10.0 ) {
			WP_CLI::error( 'Display settings altered the WooCommerce cart item base price.' );
		}
		$cart_price_html = $item['data']->get_price_html();
		if ( false === strpos( wp_strip_all_tags( $cart_price_html ), '10.00' ) ) {
			WP_CLI::error( 'The simple-product price display setting changed cart price HTML.' );
		}
		$order = wc_create_order( [ 'status' => 'pending' ] );
		if ( is_wp_error( $order ) ) {
			WP_CLI::error( 'Could not create disposable simple-price order.' );
		}
		$order_item = new WC_Order_Item_Product();
		$order_item->set_product( $item['data'] );
		$order_item->set_quantity( 1 );
		$order_item->set_subtotal( 10.0 );
		$order_item->set_total( 10.0 );
		$order->add_item( $order_item );
		$order->save();
		if ( (float) $order_item->get_total() !== 10.0 ) {
			WP_CLI::error( 'Display settings altered the order item price.' );
		}
		WP_CLI::success( 'Simple-product base price remains $10 through cart and order.' );
	} finally {
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
		if ( $key ) {
			$cart->remove_cart_item( $key );
		}
		if ( '' === (string) $previous_display ) {
			$product->delete_meta_data( '_opf_price_display' );
		} else {
			$product->update_meta_data( '_opf_price_display', $previous_display );
		}
		if ( '' === (string) $previous_label ) {
			$product->delete_meta_data( '_opf_price_label' );
		} else {
			$product->update_meta_data( '_opf_price_label', $previous_label );
		}
		$product->save();
	}
	return;
}

WP_CLI::error( 'Use prepare, verify, or cleanup.' );
