<?php
/**
 * Comparative OPF-vs-WAPF-Extended-3.1.5 harness for `WAPF-DISPLAY-PRICE-HINTS`.
 *
 * Builds one priced `select` field on two otherwise identical products — one
 * group authored in WAPF 3.1.5 (local `_wapf_fieldgroup` meta), one in OPF — then
 * drives the real WooCommerce cart and order pipeline for each and captures the
 * rendered cart hint markup and the persisted order-item metadata. Also compares
 * the storefront/cart hint string from each plugin's own formatter and the hint
 * settings that drive them.
 *
 * Usage (clone path guarded, WAPF + OPF both active; stub include supplies
 * intelephense symbols only):
 *   OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=<repo>/docs/compatibility/wapf-reference-proof-20261005 \
 *     wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-display-price-hints.php setup|render|cart|cleanup
 */

defined( 'ABSPATH' ) || exit;

use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;
use OPF\Service\PricingHints;
use OPF\Service\Renderer;
use SW_WAPF_PRO\Includes\Classes\Field_Groups as WapfFieldGroups;
use SW_WAPF_PRO\Includes\Classes\Helper as WapfHelper;
use SW_WAPF_PRO\Includes\Classes\Html as WapfHtml;
use SW_WAPF_PRO\Includes\Classes\Util as WapfUtil;

$clone = '/tmp/opf-wapfref-compare-wp';
$out   = getenv( 'OPF_WAPFREF_OUT' ) ?: __DIR__;
$art   = trailingslashit( $out ) . 'row4-display-price-hints';

if ( '1' !== getenv( 'OPF_WAPFREF_ALLOW' ) || realpath( ABSPATH ) !== $clone ) {
	throw new RuntimeException( 'Guarded: disposable comparative clone + OPF_WAPFREF_ALLOW=1 only.' );
}
if ( ! class_exists( '\SW_WAPF_PRO\WAPF' ) ) {
	throw new RuntimeException( 'WAPF Extended 3.1.5 must be active.' );
}
if ( ! defined( 'OPF_VERSION' ) ) {
	throw new RuntimeException( 'OPF must be active.' );
}

$phase = $args[0] ?? '';

$wapf_raw = static function (): array {
	return [
		'id'         => 'wapfref_hint_wapf',
		'type'       => 'wapf_product',
		'fields'     => [ [
			'id'          => 'finish',
			'type'        => 'select',
			'label'       => 'Finish',
			'width'       => 100,
			'class'       => '',
			'required'    => false,
			'choices'     => [
				[ 'slug' => 'gold', 'label' => 'Gold', 'pricing_type' => 'fx', 'pricing_amount' => '2.50', 'selected' => false, 'disabled' => false, 'options' => [] ],
				[ 'slug' => 'plain', 'label' => 'Plain', 'pricing_type' => 'none', 'pricing_amount' => '', 'selected' => false, 'disabled' => false, 'options' => [] ],
			],
			'conditionals'=> [],
			'pricing'     => [ 'enabled' => 'false', 'type' => 'none', 'amount' => '0' ],
		] ],
		'conditions' => [],
		'layout'     => [ 'labels_position' => 'above', 'instructions_position' => 'label', 'mark_required' => true ],
		'variables'  => [],
	];
};

$make_product = static function ( string $name, string $slug ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_slug( $slug );
	$p->set_regular_price( '20' );
	$p->set_status( 'publish' );
	$p->set_catalog_visibility( 'visible' );
	$p->set_virtual( true );
	return (int) $p->save();
};

if ( 'setup' === $phase ) {
	if ( get_option( 'opf_wapfref_hint_wapf_product' ) ) {
		throw new RuntimeException( 'Row-4 fixture already exists.' );
	}
	$wapf_product = $make_product( 'WAPFREF hint WAPF', 'wapfref-hint-wapf' );
	$opf_product  = $make_product( 'WAPFREF hint OPF', 'wapfref-hint-opf' );

	// WAPF resolves a per-product local group by the `p_<product_id>` id
	// convention (Field_Groups::get_by_id); add-to-cart uses it too.
	$raw_local = $wapf_raw();
	$raw_local['id'] = 'p_' . $wapf_product;
	$fg = WapfFieldGroups::raw_json_to_field_group( $raw_local );
	update_post_meta( $wapf_product, '_wapf_fieldgroup', $fg->to_array() );

	$opf_group_id = FieldGroups::save(
		0,
		[
			'fields' => [ [
				'id'       => 'finish',
				'label'    => 'Finish',
				'type'     => 'select',
				'choices'  => [
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 2.5 ] ],
					[ 'slug' => 'plain', 'label' => 'Plain', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
				],
			] ],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $opf_product ] ] ] ] ],
		],
		[ 'title' => 'WAPFREF hint OPF', 'status' => 'publish' ]
	);

	update_option( 'opf_wapfref_hint_wapf_product', $wapf_product, false );
	update_option( 'opf_wapfref_hint_opf_product', $opf_product, false );
	update_option( 'opf_wapfref_hint_opf_group', $opf_group_id, false );
	FieldGroups::flush_cache();
	echo "ok row4 setup wapf_product=$wapf_product opf_product=$opf_product opf_group=$opf_group_id\n";
	return;
}

if ( 'render' === $phase ) {
	$product = wc_get_product( (int) get_option( 'opf_wapfref_hint_wapf_product' ) );
	if ( ! $product ) {
		throw new RuntimeException( 'Row-4 fixture missing.' );
	}
	$fg     = WapfFieldGroups::process_data( get_post_meta( $product->get_id(), '_wapf_fieldgroup', true ) );
	$wfield = $fg->fields[0];
	$woption = $wfield->options['choices'][0];

	$opf_group = FieldGroups::group_from_post( get_post( (int) get_option( 'opf_wapfref_hint_opf_group' ) ) );
	$ofield    = $opf_group->data['fields'][0];
	$ooption   = $ofield['choices'][0];
	$opricing  = $ooption['pricing'];

	$result = [
		'utc'           => gmdate( 'c' ),
		'wapf_version'  => defined( 'SW_WAPF_PRO_VERSION' ) ? SW_WAPF_PRO_VERSION : '3.1.5',
		'opf_version'   => OPF_VERSION,
		'settings'      => [
			'wapf_hint_format'        => get_option( 'wapf_hint_format', '(default (+{x}))' ),
			'wapf_show_pricing_hints' => get_option( 'wapf_show_pricing_hints', '(default yes)' ),
			'opf_hint_format'         => get_option( 'opf_hint_format', '(default (+{x}))' ),
			'opf_show_price_hints'    => get_option( 'opf_show_price_hints', '(default yes)' ),
		],
		'wapf' => [
			'shop_fixed'      => WapfHelper::format_pricing_hint( 'fx', 2.5, $product, 'shop', $wfield, $woption ),
			'cart_fixed'      => WapfHelper::format_pricing_hint( 'fx', 2.5, $product, 'cart', $wfield, $woption ),
			'option_html'     => WapfHtml::frontend_option_pricing_hint( $woption, $wfield, $product ),
		],
		'opf' => [
			'shop_fixed'      => PricingHints::format( 'fixed', 2.5, $product, 'shop', $ofield, $ooption ),
			'cart_fixed'      => PricingHints::format( 'fixed', 2.5, $product, 'cart', $ofield, $ooption ),
			'storefront_html' => Renderer::pricing_hint_html( [ 'type' => $opricing['type'] ?? 'fixed', 'amount' => $opricing['amount'] ?? 2.5 ], 20.0, $product ),
		],
	];
	$result['parity'] = [
		'shop_fixed_equal'  => $result['wapf']['shop_fixed'] === $result['opf']['shop_fixed'],
		'cart_fixed_equal'  => $result['wapf']['cart_fixed'] === $result['opf']['cart_fixed'],
		'wrapper_equal_shape' => ( false !== strpos( (string) $result['wapf']['option_html'], 'wapf-pricing-hint' ) ) && ( false !== strpos( (string) $result['opf']['storefront_html'], 'opf-pricing-hint' ) ),
	];
	if ( ! is_dir( $art ) ) {
		mkdir( $art, 0700, true );
	}
	file_put_contents( $art . '/hint-format-compare.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
	return;
}

if ( 'cart' === $phase ) {
	$wapf_product = wc_get_product( (int) get_option( 'opf_wapfref_hint_wapf_product' ) );
	$opf_product  = wc_get_product( (int) get_option( 'opf_wapfref_hint_opf_product' ) );
	$opf_group    = (int) get_option( 'opf_wapfref_hint_opf_group' );
	if ( ! $wapf_product || ! $opf_product ) {
		throw new RuntimeException( 'Row-4 fixture missing.' );
	}
	$cart = WC()->cart;
	$cart->empty_cart( true );
	$captured = [ 'wapf' => [], 'opf' => [] ];
	$created_orders = [];

	// --- WAPF product: real WAPF cart + order pipeline -------------------
	$_REQUEST['wapf_field_groups'] = (string) WapfFieldGroups::process_data( get_post_meta( $wapf_product->get_id(), '_wapf_fieldgroup', true ) )->id;
	$_REQUEST['wapf']              = [ 'field_finish' => 'gold' ];
	$_POST['wapf']                 = $_REQUEST['wapf'];
	$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $wapf_product->get_id(), 1, 0, [], [] );
	if ( ! $passed ) {
		throw new RuntimeException( 'WAPF add-to-cart validation failed.' );
	}
	$key = $cart->add_to_cart( $wapf_product->get_id(), 1 );
	$cart->calculate_totals();
	$item = $key ? $cart->get_cart_item( $key ) : null;
	if ( ! $item ) {
		throw new RuntimeException( 'WAPF cart add failed.' );
	}
	$item_data = apply_filters( 'woocommerce_get_item_data', [], $item );
	$order = wc_create_order( [ 'status' => 'pending' ] );
	$order_item = new WC_Order_Item_Product();
	$order_item->set_product( $item['data'] );
	$order_item->set_quantity( 1 );
	$order_item->set_subtotal( $item['line_subtotal'] );
	$order_item->set_total( $item['line_total'] );
	do_action( 'woocommerce_checkout_create_order_line_item', $order_item, $key, $item, $order );
	$order->add_item( $order_item );
	$order->save();
	$captured['wapf'] = [
		'line_total' => (float) $item['line_total'],
		'cart_item_data' => array_map( static function ( $d ) {
			return [ 'name' => $d['name'] ?? '', 'value' => $d['value'] ?? '', 'display' => $d['display'] ?? '' ];
		}, $item_data ),
		// WAPF's cart page gates its own item-data on is_cart()/REST context,
		// unavailable under WP-CLI, so render the exact cart markup it would emit
		// through the same helper the cart template calls.
		'cart_display_markup' => ! empty( $item['wapf'][0] ) ? WapfHelper::values_to_display_string( $item['wapf'][0], $item ) : null,
		'order_meta__wapf_meta' => $order_item->get_meta( '_wapf_meta', true ),
	];
	$created_orders[] = $order->get_id();
	$cart->remove_cart_item( $key );
	unset( $_REQUEST['wapf'], $_REQUEST['wapf_field_groups'], $_POST['wapf'] );

	// --- OPF product: real OPF cart + order pipeline ----------------------
	$_POST['opf'] = [ (string) $opf_group => [ 'finish' => 'gold' ] ];
	$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $opf_product->get_id(), 1, 0, [], [] );
	if ( ! $passed ) {
		throw new RuntimeException( 'OPF add-to-cart validation failed.' );
	}
	$key = $cart->add_to_cart( $opf_product->get_id(), 1 );
	$cart->calculate_totals();
	$item = $key ? $cart->get_cart_item( $key ) : null;
	if ( ! $item ) {
		throw new RuntimeException( 'OPF cart add failed.' );
	}
	$item_data = apply_filters( 'woocommerce_get_item_data', [], $item );
	$order = wc_create_order( [ 'status' => 'pending' ] );
	$order_item = new WC_Order_Item_Product();
	$order_item->set_product( $item['data'] );
	$order_item->set_quantity( 1 );
	$order_item->set_subtotal( $item['line_subtotal'] );
	$order_item->set_total( $item['line_total'] );
	CartIntegration::persist_order_item( $order_item, $key, $item, $order );
	do_action( 'woocommerce_checkout_create_order_line_item', $order_item, $key, $item, $order );
	$order->add_item( $order_item );
	$order->save();
	$captured['opf'] = [
		'line_total' => (float) $item['line_total'],
		'cart_item_data' => array_map( static function ( $d ) {
			return [ 'name' => $d['name'] ?? '', 'value' => $d['value'] ?? '', 'display' => $d['display'] ?? '' ];
		}, $item_data ),
		'order_meta__opf_fields' => $order_item->get_meta( '_opf_fields', true ),
	];
	$created_orders[] = $order->get_id();
	$cart->remove_cart_item( $key );
	unset( $_POST['opf'] );

	update_option( 'opf_wapfref_hint_orders', $created_orders, false );
	$captured['utc'] = gmdate( 'c' );
	$captured['orders'] = $created_orders;
	if ( ! is_dir( $art ) ) {
		mkdir( $art, 0700, true );
	}
	file_put_contents( $art . '/cart-order-hints.json', wp_json_encode( $captured, JSON_PRETTY_PRINT ) );
	echo wp_json_encode( $captured, JSON_PRETTY_PRINT ) . "\n";
	return;
}

if ( 'cleanup' === $phase ) {
	foreach ( (array) get_option( 'opf_wapfref_hint_orders', [] ) as $oid ) {
		$o = wc_get_order( (int) $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	foreach ( [ 'opf_wapfref_hint_wapf_product', 'opf_wapfref_hint_opf_product' ] as $opt ) {
		$pid = (int) get_option( $opt, 0 );
		if ( $pid ) {
			$p = wc_get_product( $pid );
			if ( $p ) {
				$p->delete( true );
			}
			wp_delete_post( $pid, true );
		}
		delete_option( $opt );
	}
	$gid = (int) get_option( 'opf_wapfref_hint_opf_group', 0 );
	if ( $gid ) {
		wp_delete_post( $gid, true );
	}
	delete_option( 'opf_wapfref_hint_opf_group' );
	delete_option( 'opf_wapfref_hint_orders' );
	FieldGroups::flush_cache();
	echo "ok row4 cleanup\n";
	return;
}

throw new RuntimeException( 'Expected setup, render, cart, or cleanup.' );
