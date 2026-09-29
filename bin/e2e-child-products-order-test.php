<?php
/** Verify child line order metadata and WooCommerce order-again linkage. */
defined( 'ABSPATH' ) || exit;

$parent = wc_get_product( 15091 );
$child = wc_get_product( 15088 );
if ( ! $parent || ! $child ) {
	WP_CLI::error( 'Prepare the child-product E2E fixture first.' );
}
$entries = OPF\Service\FieldGroups::for_product( $parent );
$group = $entries ? $entries[0]['group'] : null;
if ( ! $group instanceof OPF\Engine\FieldGroup ) {
	WP_CLI::error( 'Child-product field group is missing.' );
}
$gid = (string) $entries[0]['id'];
$selection = [ (int) $child->get_id() => '1' ];
$values = [ $gid => [ 'bundle_items' => $selection ] ];
$old_parent_key = 'opf-order-e2e-parent-key';
$order = wc_create_order();
$order->set_billing_country( 'US' );
$order->set_billing_state( 'CA' );
$parent_item = new WC_Order_Item_Product();
$parent_item->set_product( $parent );
$parent_item->set_quantity( 1 );
$parent_item->set_subtotal( (float) $parent->get_price() );
$parent_item->set_total( (float) $parent->get_price() );
$parent_cart_data = [ 'data' => $parent, 'quantity' => 1, OPF\Service\CartIntegration::ITEM_KEY => $values, 'opf_base_price' => (float) $parent->get_price() ];
OPF\Service\CartIntegration::persist_order_item( $parent_item, $old_parent_key, $parent_cart_data, $order );
$order->add_item( $parent_item );
$child_cart_key = 'opf-order-e2e-child-key';
$child_item = new WC_Order_Item_Product();
$child_item->set_product( $child );
$child_item->set_quantity( 1 );
$child_item->set_subtotal( (float) $child->get_price() );
$child_item->set_total( (float) $child->get_price() );
$child_cart_data = [
	'data' => $child,
	'quantity' => 1,
	'opf_child_parent_key' => $old_parent_key,
	'opf_child_field' => 'bundle_items',
	'opf_child_base_quantity' => 1,
	'opf_child_scale_with_parent' => true,
];
OPF\Service\CartIntegration::persist_order_item( $child_item, $child_cart_key, $child_cart_data, $order );
$order->add_item( $child_item );
$order->calculate_taxes();
$order->calculate_totals( false );
$order->save();
$parent_item_id = $parent_item->get_id();
$child_item_id = $child_item->get_id();
$stored_fields = json_decode( (string) $parent_item->get_meta( '_opf_fields', true ), true );
if ( (string) $parent_item->get_meta( '_opf_cart_key', true ) !== $old_parent_key || (string) $child_item->get_meta( '_opf_child_parent_key', true ) !== $old_parent_key || ! isset( $stored_fields[ $gid ]['bundle_items'] ) ) {
	$order->delete( true );
	WP_CLI::error( 'Parent/child order metadata was not persisted.' );
}

$woocommerce = WC();
if ( ! $woocommerce->session ) {
	$woocommerce->initialize_session();
}
if ( ! $woocommerce->cart ) {
	$woocommerce->initialize_cart();
}
$woocommerce->cart->empty_cart();
$restored_parent_data = OPF\Service\CartIntegration::restore_order_again( [], $parent_item, $order );
$new_parent_key = $woocommerce->cart->add_to_cart( (int) $parent->get_id(), 1, 0, [], $restored_parent_data );
$restored_child_data = OPF\Service\CartIntegration::restore_order_again( [], $child_item, $order );
$new_child_key = $woocommerce->cart->add_to_cart( (int) $child->get_id(), 1, 0, [], $restored_child_data );
$restored_cart = $woocommerce->cart->get_cart();
$restored_children = array_filter( $restored_cart, static fn( $item ): bool => (int) ( $item['product_id'] ?? 0 ) === (int) $child->get_id() );
$linked_to_new_parent = $new_parent_key
	&& $new_child_key
	&& ( $restored_child_data['opf_child_parent_key'] ?? '' ) === $new_parent_key
	&& 2 === count( $restored_cart )
	&& 1 === count( $restored_children )
	&& (int) reset( $restored_children )['quantity'] === 1
	&& ( reset( $restored_children )['opf_child_parent_key'] ?? '' ) === $new_parent_key;
$debug_data = [
	'new_parent_key' => $new_parent_key,
	'old_parent_key' => $parent_item->get_meta( '_opf_cart_key', true ),
	'restored_parent_markers' => array_intersect_key( $restored_parent_data, array_flip( [ 'opf_order_again_has_child_lines', 'opf_order_again_original_cart_key', OPF\Service\CartIntegration::ITEM_KEY ] ) ),
	'restored_child_data' => $restored_child_data,
	'cart' => array_map( static fn( $item ): array => [ 'product' => $item['product_id'] ?? 0, 'quantity' => $item['quantity'] ?? 0, 'parent' => $item['opf_child_parent_key'] ?? '' ], $restored_cart ),
];
$woocommerce->cart->empty_cart();
$order_id = $order->get_id();
$order->delete( true );
if ( ! $linked_to_new_parent ) {
	WP_CLI::log( 'ORDER_E2E_DEBUG=' . wp_json_encode( $debug_data ) );
	WP_CLI::error( 'Order-again did not link the restored child cart line to the restored parent.' );
}
WP_CLI::log( 'ORDER_E2E_PARENT_ITEM=' . $parent_item_id );
WP_CLI::log( 'ORDER_E2E_CHILD_ITEM=' . $child_item_id );
WP_CLI::log( 'ORDER_E2E_DELETED_ORDER=' . $order_id );
WP_CLI::success( 'Parent and child order metadata and order-again linkage verified.' );
