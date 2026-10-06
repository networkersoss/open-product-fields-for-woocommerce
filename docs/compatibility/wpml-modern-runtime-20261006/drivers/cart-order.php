<?php
/** Multilingual cart + order on the ES product, through OPF's real capture path. */
global $wpdb;
$ids      = (array) get_option( 'opf_proof_ids', [] );
$group_id = (int) $ids['group_id'];
$es_id    = (int) $ids['es_product_id'];

if ( 0 !== strpos( (string) realpath( ABSPATH ), '/var/www/html' ) || ! str_contains( (string) home_url(), '127.0.0.1:8096' ) ) {
	throw new RuntimeException( 'Refusing to run outside the disposable clone.' );
}

do_action( 'wpml_switch_language', 'es' );
if ( function_exists( 'wc_load_cart' ) ) {
	wc_load_cart();
}
if ( ! WC()->session ) {
	WC()->initialize_session();
}
WC()->customer = new WC_Customer( 0, true );

// Classic form POST payload, exactly the shape OPF reads from $_POST['opf'].
$_POST['opf']    = [ $group_id => [ 'gift' => 'red', 'note' => 'Hola', 'extra' => [ 0 => 'card' ] ] ];
$_REQUEST['opf'] = $_POST['opf'];

$key = WC()->cart->add_to_cart( $es_id, 1 );
printf( "current_language=%s es_product=%d cart_item_key=%s\n", (string) apply_filters( 'wpml_current_language', null ), $es_id, var_export( $key, true ) );
$item = WC()->cart->get_cart_item( (string) $key );
printf( "cart_item_data=%s\n", wp_json_encode( $item['opf_fields'] ?? null ) );
printf( "cart_totals=%s\n", wp_json_encode( [ 'total' => WC()->cart->get_total( 'edit' ), 'subtotal' => WC()->cart->get_subtotal() ] ) );
printf( "cart_line_name=%s line_total=%s\n", wp_json_encode( $item['data']->get_name() ), wp_json_encode( $item['line_total'] ) );
$line_display = wc_get_formatted_cart_item_data( $item, true );
printf( "cart_line_display=%s\n", wp_json_encode( $line_display, JSON_UNESCAPED_UNICODE ) );
$line_display_html = wc_get_formatted_cart_item_data( $item, false );
printf( "cart_line_display_html=%s\n", wp_json_encode( $line_display_html, JSON_UNESCAPED_UNICODE ) );

WC()->session->set( 'chosen_payment_method', 'cod' );
$checkout = WC()->checkout();
$order_id = $checkout->create_order(
	[
		'billing_first_name' => 'Ana',
		'billing_last_name'  => 'Pérez',
		'billing_email'      => 'ana@example.test',
		'billing_phone'      => '600000000',
		'billing_address_1'  => 'Calle 1',
		'billing_city'       => 'Madrid',
		'billing_country'    => 'ES',
		'payment_method'     => 'cod',
	]
);
if ( is_wp_error( $order_id ) ) {
	printf( "order_error=%s\n", $order_id->get_error_message() );
	return;
}
$order = wc_get_order( $order_id );
printf( "order_id=%d status=%s total=%s\n", $order_id, $order->get_status(), $order->get_total() );
foreach ( $order->get_items() as $order_item ) {
	printf( "order_item product=%d name=%s opf_fields=%s\n", $order_item->get_product_id(), $order_item->get_name(), wp_json_encode( $order_item->get_meta( '_opf_fields', true ) ) );
	$visible = [];
	foreach ( $order_item->get_meta_data() as $meta ) {
		$data = $meta->get_data();
		if ( '_' !== substr( (string) $data['key'], 0, 1 ) ) {
			$visible[ $data['key'] ] = $data['value'];
		}
	}
	printf( "order_item_visible_meta=%s\n", wp_json_encode( $visible, JSON_UNESCAPED_UNICODE ) );
}
printf( "order_language=%s\n", var_export( apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $order_id, 'element_type' => 'post_shop_order' ] ), true ) );
printf( "order_wpml_language_meta=%s\n", wp_json_encode( $order->get_meta( 'wpml_language' ) ) );
printf( "order_meta_lang_rows=%s\n", wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id=%d AND meta_key LIKE '%%lang%%'", $order_id ), ARRAY_A ) ) );
printf( "order_lang_meta_rows=%s\n", wp_json_encode( $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->prefix}postmeta WHERE post_id=%d AND meta_key LIKE '%%lang%%'", $order_id ) ) ) );
update_option( 'opf_proof_order', [ 'order_id' => $order_id, 'cart_key' => $key ], false );
