<?php
/** Disposable switch-control fixture. Usage: wp eval-file ... prepare|verify|cleanup */
defined( 'ABSPATH' ) || exit;

$action    = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_switch_control_state';
$title     = 'OPF E2E Switch Control Group';
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
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Switch-control fixtures removed.' );
	return;
}

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Switch-control fixture already exists; clean it before retrying.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF E2E Switch Control Product' );
	$product->set_slug( 'opf-e2e-switch-control-product' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product_id = $product->save();
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'enabled', 'label' => 'Enabled', 'type' => 'toggle', 'switch_control' => true ],
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'switch_control' => true, 'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap' ],
				[ 'slug' => 'note', 'label' => 'Gift note' ],
			] ],
			[ 'id' => 'conditional_note', 'label' => 'Conditional note', 'type' => 'text', 'conditionals' => [
				[ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'enabled', 'operator' => 'is', 'value' => '1' ] ] ],
			] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
	if ( ! $product_id || ! $group_id ) {
		WP_CLI::error( 'Switch-control fixture failed to save.' );
	}
	$state = [ 'product_id' => $product_id, 'group_ids' => [ $group_id ] ];
	update_option( $state_key, $state, false );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::log( 'SWITCH_CONTROL_URL=' . get_permalink( $product_id ) );
	WP_CLI::log( 'SWITCH_CONTROL_IDS=' . wp_json_encode( $state ) );
	WP_CLI::success( 'Switch-control fixture prepared.' );
	return;
}

if ( 'verify' === $action ) {
	if ( ! is_array( $state ) || empty( $state['product_id'] ) || empty( $state['group_ids'][0] ) ) {
		WP_CLI::error( 'Switch-control fixture is missing.' );
	}
	$group_id  = (string) $state['group_ids'][0];
	$product_id = (int) $state['product_id'];
	$cart      = WC()->cart;
	$cases     = [
		[ 'enabled' => '0', 'extras' => [ 'gift' ], 'conditional_note' => 'forged while hidden', 'stored_note' => null ],
		[ 'enabled' => '1', 'extras' => [ 'note' ], 'conditional_note' => 'visible value', 'stored_note' => 'visible value' ],
	];
	$keys  = [];
	$order = null;
	try {
		foreach ( $cases as $case ) {
			$_POST['opf'] = [ $group_id => [
				'enabled' => $case['enabled'],
				'extras' => $case['extras'],
				'conditional_note' => $case['conditional_note'],
			] ];
			$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1, 0, [], [] );
			if ( ! $passed ) {
				WP_CLI::error( 'Valid switch case failed add-to-cart validation.' );
			}
			$key = $cart->add_to_cart( $product_id, 1 );
			$item = $key ? $cart->get_cart_item( $key ) : null;
			$values = $item[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ] ?? null;
			if ( ! is_array( $values ) || (string) ( $values['enabled'] ?? '' ) !== $case['enabled'] || (array) ( $values['extras'] ?? [] ) !== $case['extras'] || ( $values['conditional_note'] ?? null ) !== $case['stored_note'] ) {
				WP_CLI::error( 'Cart item did not preserve the checked state and selected switches without leaking hidden conditional data.' );
			}
			$keys[] = (string) $key;
		}

		$order = wc_create_order( [ 'status' => 'pending' ] );
		if ( is_wp_error( $order ) ) {
			WP_CLI::error( 'Could not create disposable switch-control order.' );
		}
		foreach ( $keys as $index => $key ) {
			$item_data = $cart->get_cart_item( $key );
			$order_item = new WC_Order_Item_Product();
			$order_item->set_product( $item_data['data'] );
			$order_item->set_quantity( 1 );
			$order_item->set_subtotal( 10.0 );
			$order_item->set_total( 10.0 );
			OPF\Service\CartIntegration::persist_order_item( $order_item, $key, $item_data, $order );
			$order->add_item( $order_item );
			$order->save();
			$stored = json_decode( (string) $order_item->get_meta( '_opf_fields', true ), true );
			$expected = $cases[ $index ];
			if ( ! is_array( $stored ) || (string) ( $stored[ $group_id ]['enabled'] ?? '' ) !== $expected['enabled'] || (array) ( $stored[ $group_id ]['extras'] ?? [] ) !== $expected['extras'] || ( $stored[ $group_id ]['conditional_note'] ?? null ) !== $expected['stored_note'] ) {
				WP_CLI::error( 'Order item did not preserve the switch values or hidden-field filtering.' );
			}
			$restored = OPF\Service\CartIntegration::restore_order_again( [], $order_item, $order );
			$again = $restored[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ] ?? [];
			if ( (string) ( $again['enabled'] ?? '' ) !== $expected['enabled'] || (array) ( $again['extras'] ?? [] ) !== $expected['extras'] ) {
				WP_CLI::error( 'Order-again did not restore true/false and checkbox selection values.' );
			}
		}
		WP_CLI::success( 'Switch true/false states and independent checkbox choices survived cart, order, and order-again; hidden conditional data was excluded.' );
	} finally {
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
		}
		foreach ( $keys as $key ) {
			$cart->remove_cart_item( $key );
		}
		unset( $_POST['opf'] );
	}
	return;
}

WP_CLI::error( 'Use prepare, verify, or cleanup.' );
