<?php
/** Store API child-products coactivation fixture; disposable clone only. */

use OPF\Engine\FieldGroup;
use OPF\Service\FieldGroups;

if ( '1' !== getenv( 'OPF_CHILD_STORE_ORDER_ALLOW' ) || '/tmp/opf-child-store-api-order-wp' !== realpath( ABSPATH ) ) {
	throw new RuntimeException( 'Disposable child Store API clone and explicit opt-in required.' );
}
$active = (array) get_option( 'active_plugins', [] );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$wapf_file = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php';
$wapf_data = get_file_data( $wapf_file, [ 'Version' => 'Version' ], 'plugin' );
if ( ! in_array( 'open-product-fields-for-woocommerce/open-product-fields-for-woocommerce.php', $active, true )
	|| ! in_array( 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php', $active, true )
	|| '3.1.5' !== ( $wapf_data['Version'] ?? '' ) ) {
	throw new RuntimeException( 'OPF and WAPF Extended 3.1.5 must both be active.' );
}
$dir = getenv( 'OPF_CHILD_STORE_ORDER_ARTIFACTS' ) ?: '/tmp/opf-child-store-api-order-evidence';
if ( ! is_dir( $dir ) ) { mkdir( $dir, 0700, true ); }
$phase = getenv( 'OPF_CHILD_STORE_ORDER_PHASE' ) ?: 'setup';
$state = get_option( 'opf_child_store_order_state', [] );
$checks = [];
$check = static function ( string $label, bool $passed ) use ( &$checks ): void {
	$checks[] = [ 'label' => $label, 'pass' => $passed ];
	if ( ! $passed ) { throw new RuntimeException( $label ); }
};

if ( 'setup' === $phase ) {
	$check( 'fresh fixture state', [] === $state );
	$term = wp_insert_term( 'OPF Store API coactive proof', 'product_cat', [ 'slug' => 'opf-store-api-coactive-proof' ] );
	$check( 'owned category created', ! is_wp_error( $term ) );
	$make = static function ( string $name, float $price, bool $child ) use ( $term ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( (string) $price );
		$product->set_category_ids( $child ? [ (int) $term['term_id'] ] : [] );
		return $product->save();
	};
	$parent = $make( 'OPF Store API parent', 20, false );
	$alpha = $make( 'OPF Store API alpha', 8, true );
	$beta = $make( 'OPF Store API beta', 12, true );
	$fields = [];
	foreach ( [ 'fixed_card', 'none_card', 'fixed_qty', 'none_qty' ] as $id ) {
		$fields[] = [
			'id' => $id, 'label' => $id, 'type' => 'products',
			'subtype' => str_ends_with( $id, 'qty' ) ? 'card-qty' : 'card',
			'product_selection' => 'category', 'qty_method' => 'parent',
			'product_query' => [ 'query_id' => (int) $term['term_id'], 'query_label' => 'OPF Store API coactive proof', 'limit' => 50, 'sort' => 'name_asc', 'pricing_type' => str_starts_with( $id, 'none' ) ? 'none' : 'fixed' ],
			'incl_img' => false, 'slot_1' => 'price',
		];
	}
	$data = FieldGroup::normalize( [ 'fields' => $fields, 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $parent ] ] ] ] ] ] );
	$group = FieldGroups::save( 0, $data, [ 'title' => 'OPF Store API coactive fixture', 'status' => 'publish' ] );
	$check( 'owned OPF group persisted', $group > 0 );
	$checkout = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'OPF coactive checkout', 'post_name' => 'opf-coactive-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	$account = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'OPF coactive account', 'post_name' => 'opf-coactive-account', 'post_content' => '[woocommerce_my_account]' ] );
	$old = [];
	foreach ( [ 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_bacs_settings', 'woocommerce_currency', 'woocommerce_calc_taxes', 'woocommerce_enable_guest_checkout' ] as $key ) {
		$old[ $key ] = [ 'exists' => false !== get_option( $key, false ), 'value' => get_option( $key ) ];
	}
	update_option( 'woocommerce_checkout_page_id', $checkout );
	update_option( 'woocommerce_myaccount_page_id', $account );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_enable_guest_checkout', 'yes' );
	$state = [ 'parent' => $parent, 'alpha' => $alpha, 'beta' => $beta, 'group' => $group, 'category' => (int) $term['term_id'], 'checkout' => $checkout, 'account' => $account, 'old_options' => $old ];
	update_option( 'opf_child_store_order_state', $state, false );
	file_put_contents( $dir . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
} elseif ( 'prepare-order-again' === $phase ) {
	$order_id = (int) ( json_decode( file_get_contents( $dir . '/orders.json' ), true )['store-api'] ?? 0 );
	$order = wc_get_order( $order_id );
	$user = get_user_by( 'login', 'parity-admin' );
	$check( 'Store API order exists', $order instanceof WC_Order );
	$check( 'disposable fixture owner exists', $user instanceof WP_User );
	$order->set_customer_id( $user->ID );
	$order->set_status( 'completed' );
	$order->save();
	$check( 'order owned/completed for native account Order Again', $order->get_customer_id() === $user->ID && $order->has_status( 'completed' ) );
} elseif ( 'verify' === $phase ) {
	$order_id = (int) ( json_decode( file_get_contents( $dir . '/orders.json' ), true )['store-api'] ?? 0 );
	$order = wc_get_order( $order_id );
	$check( 'checkout-generated order exists', $order instanceof WC_Order );
	$items = array_values( $order->get_items() );
	$check( 'order stores parent plus four child lines', 5 === count( $items ) );
	$check( 'persisted order total is USD 92', 92.0 === (float) $order->get_total() );
	$parent = null;
	$children = [];
	foreach ( $items as $item ) {
		if ( $item->get_meta( '_opf_fields' ) ) { $parent = $item; }
		if ( $item->get_meta( '_opf_child_full' ) ) { $children[] = $item; }
	}
	$check( 'parent persists OPF values and old cart key', $parent instanceof WC_Order_Item_Product && '' !== (string) $parent->get_meta( '_opf_cart_item_key' ) );
	$check( 'all four children persist full parent/field/price mapping', 4 === count( $children ) && count( array_filter( $children, static function ( $item ) { $meta = $item->get_meta( '_opf_child_full' ); return is_array( $meta ) && ! empty( $meta['parent'] ) && ! empty( $meta['field'] ) && isset( $meta['price_type'] ); } ) ) === 4 );
	$check( 'paid and zero-priced child totals persist', 16.0 === (float) $items[1]->get_total() && 0.0 === (float) $items[2]->get_total() && 36.0 === (float) $items[3]->get_total() && 0.0 === (float) $items[4]->get_total() );
} elseif ( 'stock-refund' === $phase ) {
	$order_id = (int) ( json_decode( file_get_contents( $dir . '/orders.json' ), true )['store-api'] ?? 0 );
	$order = wc_get_order( $order_id );
	$check( 'unrefunded Store API order exists', $order instanceof WC_Order && [] === $order->get_refunds() );
	foreach ( [ $state['alpha'], $state['beta'] ] as $pid ) { $product = wc_get_product( $pid ); $product->set_manage_stock( true ); $product->set_stock_quantity( 50 ); $product->save(); }
	$order->get_data_store()->set_stock_reduced( $order_id, false );
	wc_reduce_stock_levels( $order_id );
	$check( 'native child lines reduce alpha by six and beta by five', 44 === (int) wc_get_product( $state['alpha'] )->get_stock_quantity() && 45 === (int) wc_get_product( $state['beta'] )->get_stock_quantity() );
	$line_items = [];
	foreach ( $order->get_items() as $item_id => $item ) {
		if ( $item->get_meta( '_opf_child_full' ) ) { $line_items[ $item_id ] = [ 'qty' => $item->get_quantity(), 'refund_total' => (float) $item->get_total(), 'refund_tax' => [] ]; }
	}
	$refund = wc_create_refund( [ 'order_id' => $order_id, 'amount' => 52, 'reason' => 'OPF coactive lifecycle fixture', 'line_items' => $line_items, 'refund_payment' => false, 'restock_items' => true ] );
	$check( 'refund stores all four paid/free child lines', $refund instanceof WC_Order_Refund && 4 === count( $refund->get_items() ) && 52.0 === (float) $refund->get_amount() );
	$after = [ 'alpha' => (int) wc_get_product( $state['alpha'] )->get_stock_quantity(), 'beta' => (int) wc_get_product( $state['beta'] )->get_stock_quantity(), 'stock_reduced' => $order->get_data_store()->get_stock_reduced( $order ), 'order_status' => $order->get_status() ];
	$check( 'Woo refund event restores child stock in this request', 50 === $after['alpha'] && 50 === $after['beta'] );
	file_put_contents( $dir . '/stock-after-refund.json', wp_json_encode( $after, JSON_PRETTY_PRINT ) );
	file_put_contents( $dir . '/stock-refund-checks.json', wp_json_encode( [ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'wapf' => $wapf_data['Version'], 'checks' => $checks ], JSON_PRETTY_PRINT ) );
	echo count( $checks ) . " checks passed ($phase).\n";
	return;
} elseif ( 'stock-state' === $phase ) {
	$order_id = (int) ( json_decode( file_get_contents( $dir . '/orders.json' ), true )['store-api'] ?? 0 );
	$order = wc_get_order( $order_id );
	$after = [ 'alpha' => (int) wc_get_product( $state['alpha'] )->get_stock_quantity(), 'beta' => (int) wc_get_product( $state['beta'] )->get_stock_quantity(), 'stock_reduced' => $order->get_data_store()->get_stock_reduced( $order ), 'order_status' => $order->get_status() ];
	file_put_contents( $dir . '/stock-state.json', wp_json_encode( $after, JSON_PRETTY_PRINT ) );
	$check( 'fresh request observed stock state', true );
} elseif ( 'cleanup' === $phase ) {
	foreach ( json_decode( file_get_contents( $dir . '/owned-orders.json' ), true ) ?: [] as $id ) {
		$order = wc_get_order( (int) $id );
		if ( $order ) { foreach ( $order->get_refunds() as $refund ) { $refund->delete( true ); } $order->delete( true ); }
	}
	foreach ( [ 'parent', 'alpha', 'beta', 'group', 'checkout', 'account' ] as $key ) { if ( ! empty( $state[ $key ] ) ) { wp_delete_post( (int) $state[ $key ], true ); } }
	wp_delete_term( (int) $state['category'], 'product_cat' );
	foreach ( $state['old_options'] as $key => $old ) { if ( $old['exists'] ) { update_option( $key, $old['value'] ); } else { delete_option( $key ); } }
	delete_option( 'opf_child_store_order_state' );
	FieldGroups::flush_cache();
} else {
	throw new RuntimeException( 'Unknown phase.' );
}

file_put_contents( $dir . '/' . $phase . '-checks.json', wp_json_encode( [ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'wapf' => $wapf_data['Version'], 'checks' => $checks ], JSON_PRETTY_PRINT ) );
echo count( $checks ) . " checks passed ($phase).\n";
