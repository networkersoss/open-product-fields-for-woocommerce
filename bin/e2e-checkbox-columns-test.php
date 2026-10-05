<?php
/** Disposable checkbox-column cart/order fixture. Usage: wp eval-file ... prepare|verify|cleanup */
defined( 'ABSPATH' ) || exit;

$action    = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_checkbox_columns_state';
$title     = 'OPF E2E Checkbox Columns Group';
$state     = get_option( $state_key, [] );

if ( 'cleanup' === $action ) {
	if ( is_array( $state ) ) {
		foreach ( (array) ( $state['group_ids'] ?? [] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( ! empty( $state['product_id'] ) ) {
			wp_delete_post( (int) $state['product_id'], true );
		}
	}
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] ) as $post ) {
		wp_delete_post( (int) $post->ID, true );
	}
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => 'OPF E2E Checkbox Columns Product' ] ) as $product ) {
		$product->delete( true );
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Checkbox-column fixtures removed.' );
	return;
}

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Checkbox-column fixture already exists; clean it before retrying.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF E2E Checkbox Columns Product' );
	$product->set_slug( 'opf-e2e-checkbox-columns-product' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product_id = $product->save();
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'columns' => 3, 'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap', 'pricing' => [ 'type' => 'fixed', 'amount' => 2.5 ] ],
				[ 'slug' => 'note', 'label' => 'Gift note', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
				[ 'slug' => 'rush', 'label' => 'Rush packing', 'pricing' => [ 'type' => 'fixed', 'amount' => 1.25 ] ],
			] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
	if ( ! $product_id || ! $group_id ) {
		WP_CLI::error( 'Checkbox-column fixture failed to save.' );
	}
	$state = [ 'product_id' => $product_id, 'group_ids' => [ $group_id ] ];
	update_option( $state_key, $state, false );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::log( 'CHECKBOX_COLUMNS_URL=' . get_permalink( $product_id ) );
	WP_CLI::log( 'CHECKBOX_COLUMNS_IDS=' . wp_json_encode( $state ) );
	WP_CLI::success( 'Checkbox-column fixture prepared.' );
	return;
}

if ( 'verify' === $action ) {
	if ( ! is_array( $state ) || empty( $state['product_id'] ) || empty( $state['group_ids'][0] ) ) {
		WP_CLI::error( 'Checkbox-column fixture is missing.' );
	}
	$product_id = (int) $state['product_id'];
	$group_id   = (string) $state['group_ids'][0];
	$product    = wc_get_product( $product_id );
	$groups     = $product instanceof WC_Product ? OPF\Service\FieldGroups::for_product( $product ) : [];
	$columns    = null;
	foreach ( $groups as $entry ) {
		$group_data = $entry['group']->data ?? [];
		if ( isset( $group_data['fields'][0]['id'] ) && 'extras' === $group_data['fields'][0]['id'] ) {
			$columns = $group_data['fields'][0]['columns'] ?? null;
		}
	}
	$stored_row   = get_post( (int) $group_id );
	$schema       = is_object( $stored_row ) ? json_decode( (string) $stored_row->post_content, true ) : null;
	$stored_columns = is_array( $schema ) ? ( $schema['fields'][0]['columns'] ?? null ) : null;
	if ( 3 !== (int) $columns || 3 !== (int) $stored_columns ) {
		WP_CLI::error( 'Saved checkbox column count did not persist through resolver/reload.' );
	}

	$cart  = WC()->cart;
	$order = null;
	$key   = '';
	try {
		$_POST['opf'] = [ $group_id => [ 'extras' => [ 'gift', 'rush' ] ] ];
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1, 0, [], [] );
		if ( ! $passed ) {
			WP_CLI::error( 'Selected checkbox values failed add-to-cart validation.' );
		}
		$key  = (string) $cart->add_to_cart( $product_id, 1 );
		$cart->calculate_totals();
		$item = $key ? $cart->get_cart_item( $key ) : null;
		$data = $item[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ]['extras'] ?? null;
		if ( [ 'gift', 'rush' ] !== $data || 13.75 !== (float) $item['line_total'] ) {
			WP_CLI::error( 'Selected checkbox values or their fixed prices did not survive into cart.' );
		}

		$order = wc_create_order( [ 'status' => 'pending' ] );
		if ( is_wp_error( $order ) ) {
			WP_CLI::error( 'Could not create disposable checkbox-column order.' );
		}
		$order_item = new WC_Order_Item_Product();
		$order_item->set_product( $item['data'] );
		$order_item->set_quantity( 1 );
		$order_item->set_subtotal( 13.75 );
		$order_item->set_total( 13.75 );
		OPF\Service\CartIntegration::persist_order_item( $order_item, $key, $item, $order );
		$order->add_item( $order_item );
		$order->save();
		$stored = json_decode( (string) $order_item->get_meta( '_opf_fields', true ), true );
		if ( [ 'gift', 'rush' ] !== ( $stored[ $group_id ]['extras'] ?? null ) ) {
			WP_CLI::error( 'Selected checkbox values did not persist in order metadata.' );
		}
		$again = OPF\Service\CartIntegration::restore_order_again( [], $order_item, $order );
		if ( [ 'gift', 'rush' ] !== ( $again[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ]['extras'] ?? null ) ) {
			WP_CLI::error( 'Order-again did not restore checkbox selections.' );
		}
		WP_CLI::success( 'Three-column configuration survived save/resolution; selected choices and $3.75 add-on total survived cart, order metadata, and order-again.' );
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
