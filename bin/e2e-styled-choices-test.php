<?php
/** Disposable styled-choice cart/order fixture. Usage: wp eval-file ... prepare|verify|cleanup */
defined( 'ABSPATH' ) || exit;

$action    = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_styled_choices_state';
$title     = 'OPF E2E Styled Choices Group';
$state     = get_option( $state_key, [] );
$option_keys = [ 'opf_styled_choice_controls', 'opf_choice_accent', 'opf_choice_border' ];

if ( 'cleanup' === $action ) {
	if ( is_array( $state ) ) {
		foreach ( (array) ( $state['group_ids'] ?? [] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( ! empty( $state['product_id'] ) ) {
			wp_delete_post( (int) $state['product_id'], true );
		}
		foreach ( $option_keys as $key ) {
			$previous = $state['previous_options'][ $key ] ?? null;
			null === $previous ? delete_option( $key ) : update_option( $key, $previous );
		}
	}
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] ) as $post ) {
		wp_delete_post( (int) $post->ID, true );
	}
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => 'OPF E2E Styled Choices Product' ] ) as $product ) {
		$product->delete( true );
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Styled-choice fixtures removed and prior settings restored.' );
	return;
}

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Styled-choice fixture already exists; clean it before retrying.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF E2E Styled Choices Product' );
	$product->set_slug( 'opf-e2e-styled-choices-product' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product_id = $product->save();
	$previous = [];
	foreach ( $option_keys as $key ) {
		$previous[ $key ] = get_option( $key, null );
	}
	$state = [ 'product_id' => $product_id, 'group_ids' => [], 'previous_options' => $previous ];
	update_option( $state_key, $state, false );
	update_option( 'opf_styled_choice_controls', 'yes' );
	update_option( 'opf_choice_accent', '#145a9e' );
	update_option( 'opf_choice_border', '#333333' );
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift wrap' ], [ 'slug' => 'note', 'label' => 'Gift note' ] ] ],
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'radio', 'choices' => [ [ 'slug' => 'blue', 'label' => 'Blue' ], [ 'slug' => 'red', 'label' => 'Red' ] ] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
	if ( ! $product_id || ! $group_id ) {
		WP_CLI::error( 'Styled-choice fixture failed to save.' );
	}
	$state['group_ids'] = [ $group_id ];
	update_option( $state_key, $state, false );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::log( 'STYLED_CHOICES_URL=' . get_permalink( $product_id ) );
	WP_CLI::log( 'STYLED_CHOICES_IDS=' . wp_json_encode( $state ) );
	WP_CLI::success( 'Styled-choice fixture prepared.' );
	return;
}

if ( 'verify' === $action ) {
	if ( ! is_array( $state ) || empty( $state['product_id'] ) || empty( $state['group_ids'][0] ) ) {
		WP_CLI::error( 'Styled-choice fixture is missing.' );
	}
	$product_id = (int) $state['product_id'];
	$group_id = (string) $state['group_ids'][0];
	$cart = WC()->cart;
	$order = null;
	$key = '';
	try {
		$_POST['opf'] = [ $group_id => [ 'extras' => [ 'gift' ], 'finish' => 'red' ] ];
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1, 0, [], [] );
		if ( ! $passed ) {
			WP_CLI::error( 'Selected native checkbox/radio values failed cart validation.' );
		}
		$key = (string) $cart->add_to_cart( $product_id, 1 );
		$item = $key ? $cart->get_cart_item( $key ) : null;
		$values = $item[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ] ?? [];
		if ( [ 'gift' ] !== ( $values['extras'] ?? null ) || 'red' !== ( $values['finish'] ?? null ) ) {
			WP_CLI::error( 'Native selected values did not survive cart insertion.' );
		}
		$order = wc_create_order( [ 'status' => 'pending' ] );
		if ( is_wp_error( $order ) ) {
			WP_CLI::error( 'Could not create disposable styled-choice order.' );
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
		if ( $values !== ( $stored[ $group_id ] ?? null ) || $values !== ( $again[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ] ?? null ) ) {
			WP_CLI::error( 'Native selected values did not persist to order metadata/order-again.' );
		}
		WP_CLI::success( 'Native checkbox/radio selections survived cart, order metadata, and order-again.' );
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
