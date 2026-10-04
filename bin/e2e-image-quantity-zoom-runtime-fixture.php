<?php
/** Disposable runtime fixture for image-quantity zoom parity. */
if ( '1' !== getenv( 'OPF_IQZ_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-image-quantity-zoom-wp-bab69b5' ) {
	throw new RuntimeException( 'Explicit image-quantity zoom clone authorization required.' );
}

use OPF\Service\FieldGroups;

$phase = getenv( 'OPF_IQZ_PHASE' ) ?: 'setup';
$state = get_option( 'opf_iqz_state', [] );
$assert = static function ( string $label, bool $pass ): void {
	if ( ! $pass ) { throw new RuntimeException( $label ); }
	echo ( $pass ? "ok " : "FAIL " ) . $label . "\n";
};
$image_id = static function (): int {
	$ids = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 100, 'fields' => 'ids' ] );
	foreach ( $ids as $id ) { if ( wp_get_attachment_image_src( (int) $id, 'full' ) ) { return (int) $id; } }
	throw new RuntimeException( 'No existing image attachment in isolated clone.' );
};
$make_product = static function ( string $name, string $slug, int $image ): int {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '20' );
	$product->set_virtual( true );
	$product->set_image_id( $image );
	return (int) $product->save();
};

if ( 'setup' === $phase ) {
	$assert( 'fresh fixture state', empty( $state ) );
	$existing = get_user_by( 'login', 'opf_iqz_admin' );
	$assert( 'no pre-existing test administrator', ! $existing );
	$password = getenv( 'OPF_IQZ_ADMIN_PASSWORD' );
	$assert( 'temporary test administrator password supplied', is_string( $password ) && strlen( $password ) >= 24 );
	$image = $image_id();
	$on = $make_product( 'OPF IQZ Zoom On', 'opf-iqz-on', $image );
	$off = $make_product( 'OPF IQZ Zoom Off', 'opf-iqz-off', $image );
	$field = static function ( bool $zoom, int $image_id ): array {
		return [ 'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity', 'image_zoom' => $zoom,
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image_id' => $image_id,
				'quantity' => [ 'default' => 0, 'min' => 0, 'max' => 4 ],
				'pricing' => [ 'type' => 'fixed', 'amount' => 2.5, 'per_unit' => true ] ] ] ];
	};
	$group_on = FieldGroups::save( 0, [ 'fields' => [ $field( false, $image ) ], 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $on ] ] ] ] ] ], [ 'title' => 'IQZ zoom enabled', 'status' => 'publish' ] );
	$group_off = FieldGroups::save( 0, [ 'fields' => [ $field( false, $image ) ], 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $off ] ] ] ] ] ], [ 'title' => 'IQZ zoom disabled', 'status' => 'publish' ] );
	$assert( 'two comparable products and field groups created', $on > 0 && $off > 0 && $group_on > 0 && $group_off > 0 );
	$checkout = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'IQZ checkout', 'post_name' => 'iqz-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	$prev_checkout = get_option( 'woocommerce_checkout_page_id' );
	$prev_bacs = get_option( 'woocommerce_bacs_settings' );
	update_option( 'woocommerce_checkout_page_id', $checkout );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	$admin_id = wp_create_user( 'opf_iqz_admin', $password, 'opf-iqz-admin@example.invalid' );
	$assert( 'temporary admin user created', ! is_wp_error( $admin_id ) );
	(new WP_User( $admin_id))->set_role( 'administrator' );
	$state = [ 'products' => [ $on, $off ], 'groups' => [ $group_on, $group_off ], 'checkout' => $checkout, 'admin' => $admin_id,
		'prev_checkout' => $prev_checkout, 'prev_bacs' => $prev_bacs, 'image_id' => $image ];
	update_option( 'opf_iqz_state', $state, false );
	file_put_contents( '/tmp/opf-image-quantity-zoom-evidence/runtime-state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
	$assert( 'initial zoom configuration is disabled', empty( FieldGroups::group_from_post( get_post( $group_on ) )->data['fields'][0]['image_zoom'] ) );
	echo "SUCCESS IQZ setup\n";
	return;
}

$assert( 'fixture state exists', ! empty( $state['groups'] ) );
if ( 'verify' === $phase ) {
	FieldGroups::flush_cache();
	$zoom_group = FieldGroups::group_from_post( get_post( (int) $state['groups'][0] ) );
	$plain_group = FieldGroups::group_from_post( get_post( (int) $state['groups'][1] ) );
	$assert( 'admin-saved zoom setting reloads from WordPress', ! empty( $zoom_group->data['fields'][0]['image_zoom'] ) );
	$assert( 'control product remains zoom disabled', empty( $plain_group->data['fields'][0]['image_zoom'] ) );
	$order_id = (int) file_get_contents( '/tmp/opf-image-quantity-zoom-evidence/order-id.txt' );
	$order = wc_get_order( $order_id );
	$assert( 'classic checkout created a real order', $order instanceof WC_Order );
	$items = array_values( $order->get_items() );
	$assert( 'order has both comparison products', count( $items ) === 2 );
	$fields = [];
	foreach ( $items as $item ) {
		$raw_fields = $item->get_meta( '_opf_fields' );
		$fields[(int) $item->get_product_id()] = is_string( $raw_fields ) ? json_decode( $raw_fields, true ) : $raw_fields;
		$assert( 'each zoom variant has the same $22.50 order line total', abs( (float) $item->get_total() - 22.5 ) < 0.001 );
	}
	$assert( 'both order items persist oak quantity two', count( $fields ) === 2 && array_reduce( $fields, static function ( bool $ok, $data ): bool {
		if ( ! is_array( $data ) ) { return false; }
		$group_data = reset( $data );
		return $ok && is_array( $group_data ) && 2 === (int) ( $group_data['prints']['quantities']['oak'] ?? 0 );
	}, true ) );
	$assert( 'order grand total is $45 for both variants', abs( (float) $order->get_total() - 45.0 ) < 0.001 );
	file_put_contents( '/tmp/opf-image-quantity-zoom-evidence/order-verify.json', wp_json_encode( [ 'order_id' => $order_id, 'total' => (float) $order->get_total(), 'items' => count( $items ), 'fields' => $fields ], JSON_PRETTY_PRINT ) );
	echo "SUCCESS IQZ checkout/order\n";
	return;
}
if ( 'cleanup' === $phase ) {
	$order_id = (int) @file_get_contents( '/tmp/opf-image-quantity-zoom-evidence/order-id.txt' );
	if ( $order_id && wc_get_order( $order_id ) ) { wc_get_order( $order_id )->delete( true ); }
	foreach ( $state['products'] as $id ) { wp_delete_post( (int) $id, true ); }
	foreach ( $state['groups'] as $id ) { wp_delete_post( (int) $id, true ); }
	wp_delete_post( (int) $state['checkout'], true );
	wp_delete_user( (int) $state['admin'] );
	if ( false !== $state['prev_checkout'] ) { update_option( 'woocommerce_checkout_page_id', $state['prev_checkout'] ); }
	if ( false !== $state['prev_bacs'] ) { update_option( 'woocommerce_bacs_settings', $state['prev_bacs'] ); }
	delete_option( 'opf_iqz_state' );
	FieldGroups::flush_cache();
	$assert( 'order removed', ! $order_id || ! wc_get_order( $order_id ) );
	$assert( 'fixture products and groups removed', ! get_post( (int) $state['products'][0] ) && ! get_post( (int) $state['products'][1] ) && ! get_post( (int) $state['groups'][0] ) && ! get_post( (int) $state['groups'][1] ) );
	$assert( 'checkout page removed', ! get_post( (int) $state['checkout'] ) );
	$assert( 'temporary administrator removed', ! get_user_by( 'login', 'opf_iqz_admin' ) );
	$assert( 'checkout page and BACS options restored', get_option( 'woocommerce_checkout_page_id' ) === $state['prev_checkout'] && get_option( 'woocommerce_bacs_settings' ) === $state['prev_bacs'] );
	$assert( 'fixture state removed', false === get_option( 'opf_iqz_state', false ) );
	echo "SUCCESS IQZ cleanup\n";
	return;
}
throw new RuntimeException( 'Unknown phase: ' . $phase );
