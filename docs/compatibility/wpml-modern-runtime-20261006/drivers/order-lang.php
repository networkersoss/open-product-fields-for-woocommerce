<?php
/** WCML's own view of the order language for the ES order. */
global $woocommerce_wpml, $wpdb;
$proof    = (array) get_option( 'opf_proof_order', [] );
$order_id = (int) ( $proof['order_id'] ?? 0 );
$order    = wc_get_order( $order_id );
printf( "order_id=%d\n", $order_id );
printf( "wcml_get_order_language=%s\n", var_export( $woocommerce_wpml->emails->get_order_language( $order_id ), true ) );
printf( "wpml_language_meta=%s\n", wp_json_encode( $order->get_meta( 'wpml_language' ) ) );
printf( "postmeta_rows=%s\n", wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}postmeta WHERE post_id=%d AND meta_key LIKE '%%lang%%'", $order_id ), ARRAY_A ) ) );
foreach ( $order->get_items() as $item ) {
	printf( "item_language_by_item_id=%s\n", var_export( $woocommerce_wpml->orders->get_order_language_by_item_id( $item->get_id() ), true ) );
}
printf( "order_total=%s\n", $order->get_total() );
