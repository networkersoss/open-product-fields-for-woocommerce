<?php
/** Disposable variable-product field fixture. Usage: wp eval-file ... prepare|verify|cleanup */
defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_variable_fields_state';
$state = get_option( $state_key, [] );
$group_titles = [ 'OPF E2E Variable Parent Group', 'OPF E2E Variable Red Group', 'OPF E2E Variable Blue Group' ];

if ( 'cleanup' === $action ) {
	if ( is_array( $state ) ) {
		foreach ( (array) ( $state['group_ids'] ?? [] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( (array) ( $state['variation_ids'] ?? [] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( ! empty( $state['product_id'] ) ) {
			wp_delete_post( (int) $state['product_id'], true );
		}
	}
	foreach ( $group_titles as $title ) {
		foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] ) as $post ) {
			wp_delete_post( (int) $post->ID, true );
		}
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Variable product field fixture removed.' );
	return;
}

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Variable product fixture already exists; clean it before retrying.' );
	}
	$product = new WC_Product_Variable();
	$product->set_name( 'OPF E2E Variable Fields Product' );
	$product->set_slug( 'opf-e2e-variable-fields-product' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '10.00' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Color' );
	$attribute->set_options( [ 'Red', 'Blue' ] );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$product->set_attributes( [ $attribute ] );
	$product_id = $product->save();
	$variation_ids = [];
	foreach ( [ 'Red' => [ 'regular' => '14.00', 'sale' => '12.00', 'stock' => 'instock' ], 'Blue' => [ 'regular' => '20.00', 'sale' => '', 'stock' => 'outofstock' ] ] as $color => $pricing ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes( [ 'color' => $color ] );
		$variation->set_regular_price( $pricing['regular'] );
		if ( '' !== $pricing['sale'] ) {
			$variation->set_sale_price( $pricing['sale'] );
		}
		$variation->set_stock_status( $pricing['stock'] );
		$variation->set_status( 'publish' );
		$variation_ids[ $color ] = $variation->save();
	}
	$definitions = [
		[ 'title' => $group_titles[0], 'field' => 'parent_note', 'label' => 'Parent note', 'target' => $product_id ],
		[ 'title' => $group_titles[1], 'field' => 'red_note', 'label' => 'Red note', 'target' => $variation_ids['Red'], 'variation' => true ],
		[ 'title' => $group_titles[2], 'field' => 'blue_note', 'label' => 'Blue note', 'target' => $variation_ids['Blue'], 'variation' => true ],
	];
	$group_ids = [];
	foreach ( $definitions as $definition ) {
		$data = new OPF\Engine\FieldGroup( [
			'fields' => [ [ 'id' => $definition['field'], 'label' => $definition['label'], 'type' => 'text', 'required' => true ] ],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => ! empty( $definition['variation'] ) ? 'product_variation' : 'product', 'operator' => 'in', 'terms' => [ (string) $definition['target'] ] ] ] ] ],
		] );
		$group_ids[] = OPF\Service\FieldGroups::save( 0, $data, [ 'title' => $definition['title'] ] );
	}
	$state = [ 'product_id' => $product_id, 'variation_ids' => array_values( $variation_ids ), 'group_ids' => $group_ids ];
	update_option( $state_key, $state, false );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::log( 'VARIABLE_FIELDS_URL=' . get_permalink( $product_id ) );
	WP_CLI::log( 'VARIABLE_FIELDS_IDS=' . wp_json_encode( $state ) );
	WP_CLI::success( 'Variable product field fixture prepared.' );
	return;
}

if ( 'verify' === $action ) {
	if ( ! is_array( $state ) || empty( $state['product_id'] ) ) {
		WP_CLI::error( 'Variable product fixture is missing.' );
	}
	$product = wc_get_product( (int) $state['product_id'] );
	$red = wc_get_product( (int) $state['variation_ids'][0] );
	$blue = wc_get_product( (int) $state['variation_ids'][1] );
	$parent_ids = array_map( static fn( $entry ): int => (int) $entry['id'], OPF\Service\FieldGroups::for_product( $product ) );
	$red_ids = array_map( static fn( $entry ): int => (int) $entry['id'], OPF\Service\FieldGroups::for_product( $red ) );
	$blue_ids = array_map( static fn( $entry ): int => (int) $entry['id'], OPF\Service\FieldGroups::for_product( $blue ) );
	$parent_group_id = (int) $state['group_ids'][0];
	$red_group_id = (int) $state['group_ids'][1];
	$blue_group_id = (int) $state['group_ids'][2];
	if ( ! in_array( $parent_group_id, $parent_ids, true ) || ! in_array( $parent_group_id, $red_ids, true ) || ! in_array( $parent_group_id, $blue_ids, true ) || ! in_array( $red_group_id, $red_ids, true ) || in_array( $red_group_id, $blue_ids, true ) || ! in_array( $blue_group_id, $blue_ids, true ) || in_array( $blue_group_id, $red_ids, true ) ) {
		WP_CLI::error( 'Parent/variation field group selection is incorrect: ' . wp_json_encode( [ $parent_ids, $red_ids, $blue_ids ] ) );
	}
	$_POST['opf'] = [ (string) $state['group_ids'][0] => [ 'parent_note' => 'Parent value' ] ];
	$missing_required_variation = apply_filters( 'woocommerce_add_to_cart_validation', true, (int) $state['product_id'], 1, (int) $state['variation_ids'][0], [], [] );
	if ( $missing_required_variation ) {
		WP_CLI::error( 'Missing required variation field passed cart validation.' );
	}
	$_POST['opf'][ (string) $state['group_ids'][1] ] = [ 'red_note' => 'Red value' ];
	$valid = apply_filters( 'woocommerce_add_to_cart_validation', true, (int) $state['product_id'], 1, (int) $state['variation_ids'][0], [], [] );
	if ( ! $valid ) {
		WP_CLI::error( 'Valid parent + variation values were rejected.' );
	}
	$cart = WC()->cart;
	$key = $cart->add_to_cart( (int) $state['product_id'], 1, (int) $state['variation_ids'][0], [ 'attribute_color' => 'Red' ] );
	$item = $key ? $cart->get_cart_item( $key ) : null;
	if ( ! is_array( $item ) || empty( $item['opf_fields'][ (string) $state['group_ids'][0] ]['parent_note'] ) || empty( $item['opf_fields'][ (string) $state['group_ids'][1] ]['red_note'] ) || isset( $item['opf_fields'][ (string) $state['group_ids'][2] ] ) || (float) $item['opf_base_price'] !== 12.0 ) {
		WP_CLI::error( 'Variation cart line did not retain selected variation fields/base price.' );
	}
	$order = wc_create_order( [ 'status' => 'pending' ] );
	if ( is_wp_error( $order ) ) {
		WP_CLI::error( 'Could not create disposable variation order.' );
	}
	try {
		$order_item = new WC_Order_Item_Product();
		$order_item->set_product( $item['data'] );
		$order_item->set_quantity( 1 );
		$order_item->set_subtotal( 12.0 );
		$order_item->set_total( 12.0 );
		OPF\Service\CartIntegration::persist_order_item( $order_item, (string) $key, $item, $order );
		$order->add_item( $order_item );
		$order->save();
		$stored = $order_item->get_meta( '_opf_fields', true );
		if ( is_string( $stored ) ) {
			$stored = json_decode( $stored, true );
		}
		$restored = OPF\Service\CartIntegration::restore_order_again( [], $order_item, $order );
		if ( ! is_array( $stored ) || empty( $stored[ (string) $state['group_ids'][0] ]['parent_note'] ) || empty( $stored[ (string) $state['group_ids'][1] ]['red_note'] ) || empty( $restored['opf_fields'][ (string) $state['group_ids'][1] ]['red_note'] ) || isset( $restored['opf_fields'][ (string) $state['group_ids'][2] ] ) ) {
			WP_CLI::error( 'Variation order persistence/order-again did not retain the selected variation field groups.' );
		}
	} finally {
		$order->delete( true );
	}
	$cart->remove_cart_item( $key );
	unset( $_POST['opf'] );
	WP_CLI::success( 'Parent + exact variation placement, required validation, cart values, and variation base price verified.' );
	return;
}

WP_CLI::error( 'Use prepare, verify, or cleanup.' );
