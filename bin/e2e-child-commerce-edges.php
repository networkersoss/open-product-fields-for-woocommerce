<?php
/**
 * Linked child tax/currency and cart-edit proof. Disposable child clone only.
 * Requires the fixture state produced by e2e-child-products-lifecycle.php.
 */
if ( ! defined( 'FQDB' ) || 0 !== strpos( FQDB, '/tmp/opf-image-child-wp/' ) ) {
	throw new RuntimeException( 'Disposable child-products clone only.' );
}

$state = get_option( 'opf_child_lifecycle_state' );
if ( ! is_array( $state ) || empty( $state['parent'] ) || empty( $state['alpha'] ) || empty( $state['group'] ) ) {
	throw new RuntimeException( 'Missing disposable child-products fixture state.' );
}

final class OPF_Child_Edge_FX {
	public $current_currency = 'USD';
	public $default_currency = 'USD';
	public $rate = 2.0;

	public function get_currencies(): array {
		return [
			'EUR' => [ 'rate' => $this->rate, 'symbol' => '€' ],
			'USD' => [ 'rate' => 1, 'symbol' => '$' ],
		];
	}

	public function back_convert( $price, $rate, $precision ) {
		return round( $price / $rate, $precision );
	}
}

$option_names = [
	'woocommerce_calc_taxes',
	'woocommerce_prices_include_tax',
	'woocommerce_tax_display_shop',
	'woocommerce_tax_display_cart',
	'woocommerce_tax_based_on',
	'woocommerce_default_country',
	'woocs_is_multiple_allowed',
	'woocs_is_fixed_enabled',
];
$old_options = [];
foreach ( $option_names as $name ) {
	$old_options[ $name ] = [ 'exists' => false !== get_option( $name, false ), 'value' => get_option( $name ) ];
}
$parent = wc_get_product( (int) $state['parent'] );
$child  = wc_get_product( (int) $state['alpha'] );
if ( ! $parent instanceof WC_Product || ! $child instanceof WC_Product ) {
	throw new RuntimeException( 'Fixture products unavailable.' );
}
$old_tax_status = [ $parent->get_id() => $parent->get_tax_status(), $child->get_id() => $child->get_tax_status() ];
$old_woocs_exists = array_key_exists( 'WOOCS', $GLOBALS );
$old_woocs = $GLOBALS['WOOCS'] ?? null;
$rate_id = 0;

$restore = static function () use ( $old_options, $old_tax_status, $old_woocs_exists, $old_woocs, &$rate_id ): void {
	if ( function_exists( 'WC' ) && WC()->cart ) {
		WC()->cart->empty_cart( true );
	}
	if ( $rate_id ) {
		WC_Tax::_delete_tax_rate( $rate_id );
	}
	foreach ( $old_tax_status as $product_id => $status ) {
		$product = wc_get_product( (int) $product_id );
		if ( $product instanceof WC_Product ) {
			$product->set_tax_status( $status );
			$product->save();
		}
	}
	foreach ( $old_options as $name => $old ) {
		if ( $old['exists'] ) {
			update_option( $name, $old['value'] );
		} else {
			delete_option( $name );
		}
	}
	if ( $old_woocs_exists ) {
		$GLOBALS['WOOCS'] = $old_woocs;
	} else {
		unset( $GLOBALS['WOOCS'] );
	}
};

try {
	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	update_option( 'woocommerce_tax_display_shop', 'excl' );
	update_option( 'woocommerce_tax_display_cart', 'excl' );
	update_option( 'woocommerce_tax_based_on', 'base' );
	update_option( 'woocommerce_default_country', 'US:CA' );
	// Match WAPF 3.1.5's WOOCS back-conversion gate.
	update_option( 'woocs_is_multiple_allowed', '1' );
	update_option( 'woocs_is_fixed_enabled', '1' );
	$rate_id = WC_Tax::_insert_tax_rate( [
		'tax_rate_country'  => 'US',
		'tax_rate_state'    => 'CA',
		'tax_rate'          => '10.0000',
		'tax_rate_name'     => 'OPF child edge 10',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 0,
		'tax_rate_order'    => 1,
		'tax_rate_class'    => '',
	] );
	if ( ! $rate_id ) {
		throw new RuntimeException( 'Could not insert disposable 10% tax rate.' );
	}
	foreach ( [ $parent, $child ] as $product ) {
		$product->set_tax_status( 'taxable' );
		$product->save();
	}
	WC()->customer->set_billing_country( 'US' );
	WC()->customer->set_billing_state( 'CA' );
	WC()->customer->set_billing_postcode( '90210' );

	$GLOBALS['WOOCS'] = new OPF_Child_Edge_FX();
	$currency = 'USD';
	// Fake only the WOOCS API shape and product-price conversion. This is not
	// the commercial WOOCS plugin; its production runtime remains unverified.
	$currency_filter = static function ( $price ) use ( &$currency ) {
		return 'EUR' === $currency ? (float) $price * 2 : $price;
	};
	add_filter( 'woocommerce_product_get_price', $currency_filter, 100, 1 );

	$run_currency = static function ( string $code ) use ( $state, &$currency ): array {
		WC()->cart->empty_cart( true );
		$currency = $code;
		$GLOBALS['WOOCS']->current_currency = $code;
		$group_id = (int) $state['group'];
		$cart_data = [
			OPF\Service\CartIntegration::ITEM_KEY => [
				(string) $group_id => [ 'linked_cards' => [ 'child-alpha' ] ],
			],
		];
		$parent_key = WC()->cart->add_to_cart( (int) $state['parent'], 2, 0, [], $cart_data );
		if ( ! $parent_key ) {
			throw new RuntimeException( 'Parent add failed: ' . wc_print_notices( true ) );
		}
		WC()->cart->calculate_totals();
		$items = WC()->cart->get_cart();
		$children = array_filter( $items, static fn( $item ) => OPF\Service\LinkedProducts::is_child( $item ) );
		if ( 1 !== count( $children ) ) {
			throw new RuntimeException( 'Expected one native linked child line; got ' . count( $children ) . '.' );
		}
		$child_key = (string) array_key_first( $children );
		$parent_item = WC()->cart->get_cart_item( $parent_key );
		$child_item  = WC()->cart->get_cart_item( $child_key );
		$snapshot = [
			'currency'       => $code,
			'parent_qty'     => (int) $parent_item['quantity'],
			'parent_unit'    => (float) $parent_item['data']->get_price(),
			'child_qty'      => (int) $child_item['quantity'],
			'child_unit'     => (float) $child_item['data']->get_price(),
			'subtotal'       => (float) WC()->cart->get_subtotal(),
			'tax'            => (float) WC()->cart->get_total_tax(),
			'total'          => (float) WC()->cart->get_total( 'edit' ),
			'parent_line_tax'=> (float) $parent_item['line_tax'],
			'child_line_tax' => (float) $child_item['line_tax'],
		];
		echo wp_json_encode( $snapshot, JSON_PRETTY_PRINT ) . "\n";

		WC()->cart->set_quantity( $parent_key, 3, false );
		WC()->cart->calculate_totals();
		$items = WC()->cart->get_cart();
		$children = array_filter( $items, static fn( $item ) => OPF\Service\LinkedProducts::is_child( $item ) );
		if ( 3 !== (int) WC()->cart->get_cart_item( $parent_key )['quantity'] || 3 !== (int) reset( $children )['quantity'] ) {
			throw new RuntimeException( 'Editing the parent from 2 to 3 did not sync its native child line.' );
		}
		echo "ok $code parent quantity edit 2→3 syncs child quantity 2→3\n";

		WC()->cart->remove_cart_item( $parent_key );
		WC()->cart->calculate_totals();
		if ( [] !== WC()->cart->get_cart() ) {
			throw new RuntimeException( 'Parent removal left linked child cart lines.' );
		}
		echo "ok $code removing parent leaves no orphan child line\n";
		return $snapshot;
	};

	$usd = $run_currency( 'USD' );
	$eur = $run_currency( 'EUR' );
	if ( abs( $usd['subtotal'] - 56.0 ) > 0.01 || abs( $usd['tax'] - 5.6 ) > 0.02 ) {
		throw new RuntimeException( 'USD subtotal/tax mismatch.' );
	}
	if ( abs( $eur['subtotal'] - 112.0 ) > 0.01 || abs( $eur['tax'] - 11.2 ) > 0.03 ) {
		throw new RuntimeException( 'EUR subtotal/tax mismatch.' );
	}
	if ( abs( $eur['child_unit'] - 16.0 ) > 0.01 ) {
		throw new RuntimeException( 'Foreign currency did not convert the native child price once.' );
	}
	echo "ok USD subtotal/tax 56.00/5.60\nok EUR(2x) subtotal/tax 112.00/11.20\n";
} finally {
	if ( isset( $currency_filter ) && is_callable( $currency_filter ) ) {
		remove_filter( 'woocommerce_product_get_price', $currency_filter, 100 );
	}
	$restore();
}
