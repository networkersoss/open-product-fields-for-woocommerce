<?php
/** Disposable customer-value visibility fixture. */

defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_hide_values_state';
$title = 'OPF E2E Hide Values Group';
$slug = 'opf-e2e-hide-values-product';
$state = get_option( $state_key, [] );

if ( 'cleanup' === $action ) {
	if ( ! is_array( $state ) ) {
		$state = [];
	}
	if ( ! empty( $state['order_id'] ) ) {
		$order = wc_get_order( (int) $state['order_id'] );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] ) as $post ) {
		wp_delete_post( (int) $post->ID, true );
	}
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ], 'name' => $slug ] ) as $product ) {
		$product->delete( true );
	}
	foreach ( (array) ( $state['pages'] ?? [] ) as $page_state ) {
		if ( ! empty( $page_state['id'] ) && null !== ( $page_state['content'] ?? null ) ) {
			wp_update_post( [ 'ID' => (int) $page_state['id'], 'post_content' => (string) $page_state['content' ] ] );
		}
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Hide-values fixture products, group, and order removed.' );
	return;
}

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Hide-values fixture already exists; clean it before retrying.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF E2E Hide Values Product' );
	$product->set_slug( $slug );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product_id = $product->save();
	$pages = [];
	foreach ( [ 'cart', 'checkout' ] as $page_type ) {
		$page_id = (int) wc_get_page_id( $page_type );
		$page = $page_id ? get_post( $page_id ) : null;
		if ( ! $page ) {
			WP_CLI::error( 'Could not resolve Woo ' . $page_type . ' page for classic-template proof.' );
		}
		$pages[ $page_type ] = [ 'id' => $page_id, 'content' => (string) $page->post_content ];
		$state = [ 'product_id' => $product_id, 'pages' => $pages ];
		update_option( $state_key, $state, false );
		wp_update_post( [ 'ID' => $page_id, 'post_content' => '[woocommerce_' . $page_type . ']' ] );
	}
	$group = new OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'hide_cart' => true, 'choices' => [
				[ 'slug' => 'premium', 'label' => 'Premium', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
			] ],
			[ 'id' => 'cart_note', 'label' => 'Cart secret', 'type' => 'text', 'hide_cart' => true ],
			[ 'id' => 'checkout_note', 'label' => 'Checkout secret', 'type' => 'text', 'hide_checkout' => true ],
			[ 'id' => 'order_note', 'label' => 'Order secret', 'type' => 'text', 'hide_order' => true ],
			[ 'id' => 'public_note', 'label' => 'Public note', 'type' => 'text' ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => $title ] );
	if ( ! $product_id || ! $group_id ) {
		WP_CLI::error( 'Could not prepare hide-values fixture.' );
	}
	$state = [ 'product_id' => $product_id, 'group_id' => $group_id, 'pages' => $pages ];
	update_option( $state_key, $state, false );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::log( 'HIDE_VALUES_PRODUCT=' . $product_id );
	WP_CLI::log( 'HIDE_VALUES_GROUP=' . $group_id );
	WP_CLI::log( 'HIDE_VALUES_URL=' . get_permalink( $product_id ) );
	WP_CLI::success( 'Hide-values fixture prepared.' );
	return;
}

if ( 'verify-order' === $action ) {
	$product = wc_get_product( (int) ( $state['product_id'] ?? 0 ) );
	$group_id = (int) ( $state['group_id'] ?? 0 );
	$group_post = get_post( $group_id );
	if ( ! $product || ! $group_post ) {
		WP_CLI::error( 'Hide-values product or field group missing.' );
	}
	$values = [ (string) $group_id => [
		'finish' => 'premium',
		'cart_note' => 'cart value',
		'checkout_note' => 'checkout value',
		'order_note' => 'order value',
		'public_note' => 'public value',
	] ];
	$order = wc_create_order( [ 'status' => 'pending' ] );
	if ( ! $order instanceof WC_Order ) {
		WP_CLI::error( 'Could not create hide-values fixture order.' );
	}
	$state['order_id'] = $order->get_id();
	update_option( $state_key, $state, false );
	$order->update_meta_data( '_opf_e2e_hide_values', '1' );
	$order->save();
	$line_key = 'opf-hide-values-fixture-line';
	$product->set_price( '15.00' );
	$cart_item = [
		'key' => $line_key,
		'data' => $product,
		'product_id' => $product->get_id(),
		'variation_id' => 0,
		'variation' => [],
		'quantity' => 1,
		'line_subtotal' => 15.0,
		'line_total' => 15.0,
		'line_subtotal_tax' => 0.0,
		'line_tax' => 0.0,
		'line_tax_data' => [ 'subtotal' => [], 'total' => [] ],
		OPF\Service\CartIntegration::ITEM_KEY => $values,
		'opf_base_price' => 10.0,
	];
	WC()->cart->cart_contents = [ $line_key => $cart_item ];
	WC()->checkout()->create_order_line_items( $order, WC()->cart );
	$order->calculate_totals();
	$order->save();
	$items = $order->get_items( 'line_item' );
	$item = $items ? reset( $items ) : null;
	if ( ! $item ) {
		WP_CLI::error( 'Checkout did not create a fixture order item.' );
	}
	$stored = json_decode( (string) $item->get_meta( '_opf_fields', true ), true );
	$formatted = $item->get_formatted_meta_data( '' );
	$labels = array_map( static function ( $meta ) { return (string) $meta->display_key; }, $formatted );
	$expected_stored = $values;
	$expected_display = [ 'Finish', 'Cart secret', 'Checkout secret', 'Public note' ];
	if ( $stored !== $expected_stored || array_diff( $expected_display, $labels ) || in_array( 'Order secret', $labels, true ) || abs( (float) $item->get_total() - 15.0 ) > 0.001 ) {
		WP_CLI::error( 'Order visibility/preservation assertion failed: ' . wp_json_encode( [ 'stored' => $stored, 'labels' => $labels, 'total' => $item->get_total() ] ) );
	}
	if ( function_exists( 'wpo_ips_display_item_meta' ) ) {
		$invoice_meta = wpo_ips_display_item_meta( $item, [ 'echo' => false ] );
		if ( false !== strpos( (string) $invoice_meta, 'Order secret' ) ) {
			WP_CLI::error( 'PDF Invoices & Packing Slips formatted metadata exposed a hidden order value.' );
		}
		WP_CLI::log( 'WCPDF_META_RENDER=' . ( '' === (string) $invoice_meta ? 'empty' : 'visible-fields-only' ) );
	} else {
		WP_CLI::log( 'WCPDF_META_RENDER=plugin-not-loaded; formatted-order-meta-only-verified' );
	}
	ob_start();
	wc_get_template( 'emails/email-order-items.php', [
		'order' => $order,
		'items' => $order->get_items(),
		'show_download_links' => false,
		'show_sku' => false,
		'show_image' => false,
		'image_size' => 'woocommerce_thumbnail',
		'show_purchase_note' => false,
		'sent_to_admin' => false,
		'plain_text' => false,
		'email' => null,
	] );
	$email_html = ob_get_clean();
	if ( false !== strpos( (string) $email_html, 'Order secret' ) || false === strpos( (string) $email_html, 'Public note' ) ) {
		WP_CLI::error( 'WooCommerce order email template did not honor hide_order display metadata.' );
	}
	ob_start();
	wc_get_template( 'order/order-details-item.php', [
		'item' => $item,
		'order' => $order,
		'product' => $item->get_product(),
		'item_id' => $item->get_id(),
		'show_purchase_note' => false,
		'purchase_note' => '',
	] );
	$received_html = ob_get_clean();
	if ( false !== strpos( (string) $received_html, 'Order secret' ) || false === strpos( (string) $received_html, 'Public note' ) ) {
		WP_CLI::error( 'WooCommerce order-received template did not honor hide_order display metadata.' );
	}
	WP_CLI::log( 'ORDER_EMAIL_AND_RECEIVED_TEMPLATE=hidden-order-value-omitted' );
	$restored = OPF\Service\CartIntegration::restore_order_again( [], $item, $order );
	if ( ( $restored[ OPF\Service\CartIntegration::ITEM_KEY ] ?? null ) !== $expected_stored || abs( (float) $item->get_total() - 15.0 ) > 0.001 ) {
		WP_CLI::error( 'Order-again restoration or order total was changed by display suppression.' );
	}
	$state['order_id'] = $order->get_id();
	update_option( $state_key, $state, false );
	WP_CLI::log( 'HIDDEN_FIELD_COUNT=' . count( array_diff( [ 'Cart secret', 'Checkout secret', 'Order secret' ], $labels ) ) );
	WP_CLI::log( 'STORED_FIELDS=' . count( $stored[ (string) $group_id ] ?? [] ) );
	WP_CLI::log( 'ORDER_LINE_TOTAL=' . $item->get_total() );
	WP_CLI::log( 'ORDER_AGAIN_FIELDS=' . count( $restored[ OPF\Service\CartIntegration::ITEM_KEY ][ (string) $group_id ] ?? [] ) );
	WP_CLI::log( 'ORDER_ID=' . $order->get_id() );
	WP_CLI::success( 'Checkout lifecycle preserved all field values and priced total while omitting only hide_order display metadata.' );
	return;
}

WP_CLI::error( 'Use prepare, verify-order, or cleanup.' );
