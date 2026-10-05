<?php
/** Disposable defaults and placeholders lifecycle fixture. Usage: wp eval-file ... prepare|verify|cleanup */
defined( 'ABSPATH' ) || exit;

$action    = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_defaults_state';
$title     = 'OPF E2E Defaults Group';
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
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => 'OPF E2E Defaults Product' ] ) as $product ) {
		$product->delete( true );
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Defaults fixture removed.' );
	return;
}

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Defaults fixture already exists; clean it before retrying.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF E2E Defaults Product' );
	$product->set_slug( 'opf-e2e-defaults-product' );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product_id = $product->save();
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text', 'placeholder' => 'Type initials', 'default' => 'Ada' ],
			[ 'id' => 'count', 'label' => 'Count', 'type' => 'number', 'placeholder' => 'Number of items', 'default' => '0' ],
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'default' => 'blue', 'choices' => [
				[ 'slug' => 'blue', 'label' => 'Blue finish' ], [ 'slug' => 'red', 'label' => 'Red finish' ],
			] ],
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'default' => [ 'gift' ], 'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap' ], [ 'slug' => 'note', 'label' => 'Gift note' ],
			] ],
			[ 'id' => 'empty_extras', 'label' => 'Empty extras', 'type' => 'checkbox', 'default' => [], 'choices' => [
				[ 'slug' => 'bag', 'label' => 'Bag' ],
			] ],
			[ 'id' => 'unset_note', 'label' => 'Unset note', 'type' => 'text' ],
			[ 'id' => 'enabled', 'label' => 'Enable secret', 'type' => 'toggle', 'default' => '0' ],
			[ 'id' => 'secret', 'label' => 'Secret code', 'type' => 'text', 'default' => 'hidden-default', 'conditionals' => [
				[ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'enabled', 'operator' => 'is', 'value' => '1' ] ] ],
			] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
	if ( ! $product_id || ! $group_id ) {
		WP_CLI::error( 'Defaults fixture failed to save.' );
	}
	$state = [ 'product_id' => $product_id, 'group_ids' => [ $group_id ] ];
	update_option( $state_key, $state, false );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::log( 'DEFAULTS_URL=' . get_permalink( $product_id ) );
	WP_CLI::log( 'DEFAULTS_IDS=' . wp_json_encode( $state ) );
	WP_CLI::success( 'Defaults fixture prepared.' );
	return;
}

if ( 'verify' === $action ) {
	if ( ! is_array( $state ) || empty( $state['product_id'] ) || empty( $state['group_ids'][0] ) ) {
		WP_CLI::error( 'Defaults fixture is missing.' );
	}
	$product_id = (int) $state['product_id'];
	$group_id   = (string) $state['group_ids'][0];
	$cart       = WC()->cart;
	$order      = null;
	$key        = '';
	try {
		$_POST['opf'] = [];
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1, 0, [], [] );
		if ( ! $passed ) {
			WP_CLI::error( 'Omitted optional fields failed add-to-cart validation.' );
		}
		$key  = (string) $cart->add_to_cart( $product_id, 1 );
		$item = $key ? $cart->get_cart_item( $key ) : null;
		$values = $item[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ] ?? [];
		if ( 'Ada' !== ( $values['engraving'] ?? null ) || '0' !== (string) ( $values['count'] ?? '' ) || 'blue' !== ( $values['finish'] ?? null ) || [ 'gift' ] !== ( $values['extras'] ?? null ) || array_key_exists( 'empty_extras', $values ) || array_key_exists( 'unset_note', $values ) || '0' !== (string) ( $values['enabled'] ?? '' ) || array_key_exists( 'secret', $values ) ) {
			WP_CLI::error( 'Omitted submission did not preserve scalar/choice defaults, zero values, empty selection semantics, or exclusion of hidden/unconfigured fields.' );
		}

		$order = wc_create_order( [ 'status' => 'pending' ] );
		if ( is_wp_error( $order ) ) {
			WP_CLI::error( 'Could not create disposable defaults order.' );
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
		$restored = $again[ OPF\Service\CartIntegration::ITEM_KEY ][ $group_id ] ?? [];
		if ( $values !== ( $stored[ $group_id ] ?? null ) || $values !== $restored ) {
			WP_CLI::error( 'Defaults did not persist consistently through order metadata and order-again.' );
		}
		WP_CLI::success( 'With no submitted field values, defaults survived cart, order metadata, and order-again.' );
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
