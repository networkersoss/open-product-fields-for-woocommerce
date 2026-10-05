<?php
/**
 * Inspect a persisted linked-child order and exercise Order Again for the real
 * currency-plugin lane. Clone-only.
 *
 * Runtime: executed with `wp eval-file` inside a live WordPress + WooCommerce
 * process. CURCY's frontend hooks early-return under WP_CLI, so for the
 * Order Again reconstruction this script re-registers CURCY's own price hooks
 * after setting its current currency (the same call the frontend makes).
 *
 * Env:
 *  - OPF_CHILD_CUR_ALLOW=1
 *  - OPF_CHILD_CUR_ARTIFACT_DIR
 *  - OPF_CHILD_CUR_ORDER_ID  (persisted order to inspect)
 *  - OPF_CHILD_CUR_ORDER_CURRENCY  (EUR|USD; default EUR)
 *  - OPF_CHILD_CUR_OUT  (JSON output file basename, default order-<id>.json)
 */
if ( '1' !== getenv( 'OPF_CHILD_CUR_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-child-currency-wp' ) ) {
	throw new RuntimeException( 'Explicit isolated clone authorization required.' );
}
$dir      = getenv( 'OPF_CHILD_CUR_ARTIFACT_DIR' ) ?: '/tmp/opf-child-currency-evidence';
$order_id = (int) getenv( 'OPF_CHILD_CUR_ORDER_ID' );
$currency = getenv( 'OPF_CHILD_CUR_ORDER_CURRENCY' ) ?: 'EUR';
$outfile  = getenv( 'OPF_CHILD_CUR_OUT' ) ?: ( 'order-' . $order_id . '.json' );
if ( '1' !== getenv( 'OPF_CHILD_CUR_ALLOW' ) || ! $order_id ) {
	throw new RuntimeException( 'Missing order id.' );
}

$order = \wc_get_order( $order_id );
if ( ! $order instanceof \WC_Order ) {
	throw new RuntimeException( 'Order not found: ' . $order_id );
}

$round  = static fn ( $v ) => round( (float) $v, 2 );
$lines  = [];
$parent = null;
$children = [];
foreach ( $order->get_items() as $item_id => $item ) {
	$full = $item->get_meta( '_opf_child_full' );
	$row  = [
		'item_id'     => $item_id,
		'name'        => $item->get_name(),
		'product_id'  => $item->get_product_id(),
		'quantity'    => $item->get_quantity(),
		'subtotal'    => $round( $item->get_subtotal() ),
		'total'       => $round( $item->get_total() ),
		'total_tax'   => $round( $item->get_total_tax() ),
		'child'       => (bool) $item->get_meta( '_opf_child' ),
		'child_full'  => is_array( $full ) ? $full : null,
	];
	$lines[] = $row;
	if ( ! $row['child'] ) {
		$parent = $row;
	} else {
		$children[] = $row;
	}
}

$report = [
	'order_id'      => $order_id,
	'currency'      => $order->get_currency(),
	'status'        => $order->get_status(),
	'subtotal'      => $round( $order->get_subtotal() ),
	'total_tax'     => $round( $order->get_total_tax() ),
	'total'         => $round( $order->get_total() ),
	'line_count'    => count( $lines ),
	'child_count'   => count( $children ),
	'parent'        => $parent,
	'children'      => $children,
	'lines'         => $lines,
];

// Order Again: rebuild the cart exactly like WC_Cart_Session::order_again().
\WOOMULTI_CURRENCY_Data::get_ins()->set_current_currency( $currency );
( new \WOOMULTI_CURRENCY_Frontend_Price() )->add_change_price_hooks();

if ( ! \WC()->cart ) {
	\wc_load_cart();
}
\WC()->cart->empty_cart();
$restored = [];
foreach ( $order->get_items() as $item ) {
	$restored[] = [
		'item' => $item,
		'data' => \apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $order ),
	];
}
$cart = \WC()->cart->get_cart();
foreach ( $restored as $row ) {
	$product_id     = $row['item']->get_product_id();
	$variation_id   = $row['item']->get_variation_id();
	$cart_item_data = $row['data'];
	if ( ! \apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $row['item']->get_quantity(), $variation_id, [], $cart_item_data ) ) {
		continue;
	}
	$cart_id      = \WC()->cart->generate_cart_id( $product_id, $variation_id, [], $cart_item_data );
	$product_data = \wc_get_product( $variation_id ? $variation_id : $product_id );
	$cart[ $cart_id ] = \apply_filters( 'woocommerce_add_order_again_cart_item', array_merge( $cart_item_data, [
		'key'          => $cart_id,
		'product_id'   => $product_id,
		'variation_id' => $variation_id,
		'variation'    => [],
		'quantity'     => $row['item']->get_quantity(),
		'data'         => $product_data,
		'data_hash'    => \wc_get_cart_item_data_hash( $product_data ),
	] ), $cart_id );
}
\do_action_ref_array( 'woocommerce_ordered_again', [ $order->get_id(), $order->get_items(), &$cart ] );
\WC()->cart->cart_contents = $cart;
\WC()->cart->set_session();
\WC()->cart->calculate_totals();

$again_parent_key = null;
$again_children   = [];
$cart_lines       = [];
foreach ( \WC()->cart->get_cart() as $key => $item ) {
	$is_child = \OPF\Service\LinkedProducts::is_child( $item );
	if ( ! $is_child && ! empty( $item['old_cart_item_key'] ) ) {
		$again_parent_key = $key;
	}
	if ( $is_child ) {
		$again_children[] = [
			'key'          => $key,
			'name'         => $item['data']->get_name(),
			'quantity'     => $item['quantity'],
			'parent'       => $item[ \OPF\Service\LinkedProducts::CHILD_KEY ]['parent'] ?? null,
			'price_type'   => $item[ \OPF\Service\LinkedProducts::CHILD_KEY ]['price_type'] ?? null,
			'line_total'   => $round( $item['line_total'] ),
			'line_tax'     => $round( $item['line_tax'] ),
		];
	}
	$cart_lines[] = [
		'name'       => $item['data']->get_name(),
		'quantity'   => $item['quantity'],
		'line_total' => $round( $item['line_total'] ),
		'line_tax'   => $round( $item['line_tax'] ),
		'child'      => $is_child,
	];
}
$remapped = 0;
foreach ( $again_children as $child ) {
	if ( null !== $again_parent_key && $child['parent'] === $again_parent_key ) {
		$remapped++;
	}
}
$report['order_again'] = [
	'cart_currency'   => \get_woocommerce_currency(),
	'line_count'      => count( $cart_lines ),
	'child_count'     => count( $again_children ),
	'parent_key'      => $again_parent_key,
	'remapped_children' => $remapped,
	'lines'           => $cart_lines,
	'children'        => $again_children,
	'subtotal'        => $round( \WC()->cart->get_subtotal() ),
	'total_tax'       => $round( \WC()->cart->get_total_tax() ),
	'total'           => $round( \WC()->cart->get_total( 'edit' ) ),
	'matches_order'   => $round( \WC()->cart->get_total( 'edit' ) ) === $report['total'],
];

file_put_contents( $dir . '/' . $outfile, \wp_json_encode( $report, JSON_PRETTY_PRINT ) );
echo "order {$order_id} currency={$report['currency']} total={$report['total']} tax={$report['total_tax']} children={$report['child_count']}\n";
echo "order-again lines={$report['order_again']['line_count']} remapped={$remapped} subtotal={$report['order_again']['subtotal']} total={$report['order_again']['total']} matches_order=" . ( $report['order_again']['matches_order'] ? 'yes' : 'no' ) . "\n";

if ( false ) {
	// Analysis-only symbol stubs; unreachable at runtime (wp eval-file defines these).
	class WC_Order {
		public function get_items() { return []; }
		public function get_currency() { return ''; }
		public function get_status() { return ''; }
		public function get_subtotal() { return 0; }
		public function get_total_tax() { return 0; }
		public function get_total() { return 0; }
		public function get_id() { return 0; }
	}
	class WC_Product {
		public function get_name() { return ''; }
	}
	class WOOMULTI_CURRENCY_Data {
		public static function get_ins() { return new self(); }
		public function set_current_currency( $currency, $checkout = true ) {}
	}
	class WOOMULTI_CURRENCY_Frontend_Price {
		public function add_change_price_hooks() {}
	}
	function wc_get_order( $the_order = false ) { return null; }
	function wc_get_product( $the_product = false ) { return null; }
	function wc_load_cart() {}
	function wc_get_cart_item_data_hash( $product ) { return ''; }
	function do_action_ref_array( $hook_name, $args ) {}
}
