<?php
/**
 * Disposable WooCommerce card field lifecycle fixture.
 *
 * Usage: wp eval-file bin/e2e-card-test.php prepare|cleanup
 */

defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title = 'OPF E2E Card Group';
$product_name = 'OPF E2E Card Product';
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="64" viewBox="0 0 96 64"><rect width="96" height="64" fill="#d5c2a4"/><path d="M0 16h96M0 32h96M0 48h96" stroke="#8a7559" stroke-width="3"/></svg>';

$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'name' => sanitize_title( $product_name ), 'status' => [ 'publish', 'draft', 'private' ] ] );

if ( 'cleanup' === $action ) {
	$upload = wp_upload_dir();
	$fixture_image = trailingslashit( $upload['basedir'] ) . 'opf-card-e2e-linen.svg';
	foreach ( $groups as $group_post ) {
		wp_delete_post( (int) $group_post->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	if ( is_file( $fixture_image ) && hash( 'sha256', $svg ) === hash_file( 'sha256', $fixture_image ) ) {
		unlink( $fixture_image );
	}
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Card E2E fixtures removed.' );
	return;
}

$product = $products ? $products[0] : new WC_Product_Simple();
$product->set_name( $product_name );
$product->set_slug( 'opf-e2e-card-product' );
$product->set_regular_price( '100.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
$upload = wp_upload_dir();
$fixture_image = trailingslashit( $upload['basedir'] ) . 'opf-card-e2e-linen.svg';
$fixture_image_url = '/wp-content/uploads/opf-card-e2e-linen.svg';
if ( is_file( $fixture_image ) && hash( 'sha256', $svg ) !== hash_file( 'sha256', $fixture_image ) ) {
	WP_CLI::error( 'Card E2E image path already exists with different content.' );
}
if ( ! is_file( $fixture_image ) ) {
	wp_mkdir_p( dirname( $fixture_image ) );
	if ( false === file_put_contents( $fixture_image, $svg ) ) {
		WP_CLI::error( 'Card E2E image fixture could not be written.' );
	}
}

$group = new OPF\Engine\FieldGroup(
	[
		'fields' => [
			[
				'id' => 'finish', 'label' => 'Choose a finish', 'type' => 'radio', 'required' => true, 'card_layout' => 'horizontal',
				'choices' => [
					[ 'slug' => 'linen', 'label' => 'Linen', 'image' => $fixture_image_url, 'description' => 'Soft woven finish', 'pricing' => [ 'type' => 'fixed', 'amount' => 4 ] ],
					[ 'slug' => 'velvet', 'label' => 'Velvet', 'description' => 'Smooth plush finish', 'pricing' => [ 'type' => 'fixed', 'amount' => 8 ] ],
				],
			],
			[
				'id' => 'dedication', 'label' => 'Personal note', 'type' => 'text',
				'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'finish', 'operator' => 'is', 'value' => 'velvet' ] ] ] ],
			],
			[
				'id' => 'packaging', 'label' => 'Packaging', 'type' => 'radio', 'card_layout' => 'vertical',
				'choices' => [ [ 'slug' => 'plain', 'label' => 'Plain box', 'pricing' => [ 'type' => 'none' ] ], [ 'slug' => 'gift', 'label' => 'Gift box', 'pricing' => [ 'type' => 'none' ] ] ],
			],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	]
);
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $title ] );
$saved_group = $group_id ? OPF\Service\FieldGroups::group_from_post( get_post( $group_id ) ) : null;

$checks = 0;
$check = static function ( string $label, bool $passed ) use ( &$checks ): void {
	$checks++;
	if ( ! $passed ) {
		WP_CLI::error( 'Card E2E failed: ' . $label );
	}
	WP_CLI::log( '  ok    ' . $label );
};

$check( 'fixture product and group saved', $product_id > 0 && $group_id > 0 && $saved_group instanceof OPF\Engine\FieldGroup );
$check( 'builder data round-trips card orientation, choices and choice prices', $saved_group && $saved_group->data['fields'][0]['card_layout'] === 'horizontal' && $saved_group->data['fields'][0]['choices'][1]['pricing']['amount'] === 8.0 );
$check( 'both card orientations render independently', $saved_group && $saved_group->data['fields'][2]['card_layout'] === 'vertical' );
$check( 'conditional rule remains attached to the dependent field', $saved_group && count( $saved_group->data['fields'][1]['conditionals'] ) === 1 );
$check( 'conditional field visibility follows the selected card', $saved_group && ! OPF\Engine\Evaluator::is_visible( $saved_group->data['fields'][1], [ 'finish' => 'linen' ] ) && OPF\Engine\Evaluator::is_visible( $saved_group->data['fields'][1], [ 'finish' => 'velvet' ] ) );

ob_start();
OPF\Service\Renderer::render_group( (string) $group_id, $title, $saved_group, 100.0 );
$html = (string) ob_get_clean();
$has = static fn( string $needle ): bool => false !== strpos( $html, $needle );
$check( 'storefront renders horizontal and vertical accessible radio groups with optional image, description and price', $has( 'opf-cards--horizontal' ) && $has( 'opf-cards--vertical' ) && $has( 'role="radiogroup"' ) && $has( 'opf-card__image' ) && $has( 'Soft woven finish' ) && $has( 'type="radio"' ) && $has( 'data-opf-price="8"' ) );

WC()->cart->empty_cart();
$_POST['opf'] = [ (string) $group_id => [ 'finish' => 'velvet', 'dedication' => 'For Alex' ] ];
$valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );
$cart_key = $valid ? WC()->cart->add_to_cart( $product_id, 1 ) : false;
unset( $_POST['opf'] );
WC()->cart->calculate_totals();
$cart_item = $cart_key ? WC()->cart->get_cart_item( $cart_key ) : [];
$saved_values = $cart_item['opf_fields'][ (string) $group_id ] ?? [];
$check( 'classic cart captures card choice and conditional value with matching add-on price', $valid && $cart_key && ( $saved_values['finish'] ?? '' ) === 'velvet' && ( $saved_values['dedication'] ?? '' ) === 'For Alex' && abs( (float) ( $cart_item['data']->get_price() ?? 0 ) - 108.0 ) < 0.001 );

$order = wc_create_order();
$order_item_id = $order->add_product( wc_get_product( $product_id ), 1 );
$order_item = $order->get_item( $order_item_id );
do_action( 'woocommerce_checkout_create_order_line_item', $order_item, $cart_key, $cart_item, $order );
$order_item->save();
$order_values = json_decode( (string) $order_item->get_meta( '_opf_fields', true ), true );
$order_again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $order_item, $order );
$check( 'order and order-again retain the selected card and conditional value', ( $order_values[ (string) $group_id ]['finish'] ?? '' ) === 'velvet' && ( $order_values[ (string) $group_id ]['dedication'] ?? '' ) === 'For Alex' && ( $order_again['opf_fields'][ (string) $group_id ]['finish'] ?? '' ) === 'velvet' );
$order->delete( true );

WC()->cart->empty_cart();
$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$request->set_param( 'id', $product_id );
$request->set_param( 'quantity', 1 );
$request->set_param( 'opf_fields', [ (string) $group_id => [ 'finish' => 'linen' ] ] );
$response = rest_get_server()->dispatch( $request );
$store_items = WC()->cart->get_cart();
$store_item = $store_items ? reset( $store_items ) : [];
$store_values = $store_item['opf_fields'][ (string) $group_id ] ?? [];
$check( 'Store API cart captures the card choice and applies its price', in_array( $response->get_status(), [ 200, 201 ], true ) && ( $store_values['finish'] ?? '' ) === 'linen' && abs( (float) ( $store_item['data']->get_price() ?? 0 ) - 104.0 ) < 0.001 );

WP_CLI::log( 'CARD_E2E_PRODUCT=' . $product_id );
WP_CLI::log( 'CARD_E2E_GROUP=' . $group_id );
WP_CLI::log( 'CARD_E2E_URL=' . get_permalink( $product_id ) );
WP_CLI::success( $checks . ' card checks passed.' );
