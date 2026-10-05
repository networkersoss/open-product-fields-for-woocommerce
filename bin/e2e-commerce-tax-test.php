<?php
/** Disposable WooCommerce tax fixture. Usage: wp eval-file ... prepare|record-rate|mode|verify|cleanup. */
defined( 'ABSPATH' ) || exit;

$action    = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_commerce_tax_state';
$state     = get_option( $state_key, [] );
$option_keys = [
	'woocommerce_calc_taxes',
	'woocommerce_prices_include_tax',
	'woocommerce_tax_display_shop',
	'woocommerce_tax_display_cart',
	'woocommerce_tax_based_on',
	'woocommerce_default_customer_address',
	'woocommerce_tax_round_at_subtotal',
	'woocommerce_tax_classes',
];

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Tax fixture already exists; run cleanup before retrying.' );
	}
	$state = [ 'options' => [], 'rate_ids' => [], 'product_ids' => [], 'variation_ids' => [], 'group_ids' => [], 'order_ids' => [] ];
	foreach ( $option_keys as $key ) {
		$sentinel = '__OPF_E2E_OPTION_ABSENT__';
		$value = get_option( $key, $sentinel );
		$state['options'][ $key ] = [ 'exists' => $sentinel !== $value, 'value' => $sentinel !== $value ? $value : null ];
	}
	$state['active_plugins'] = (array) get_option( 'active_plugins', [] );
	$existing_rates_json = (string) WP_CLI::runcommand( 'wc tax list --format=json --user=admin', [ 'return' => 'stdout' ] );
	$existing_rates = json_decode( trim( $existing_rates_json ), true );
	$state['existing_rate_ids'] = array_map( 'absint', array_column( is_array( $existing_rates ) ? $existing_rates : [], 'id' ) );
	$state['rate_tag'] = 'OPF E2E Tax ' . gmdate( 'YmdHis' ) . ' ' . wp_generate_password( 5, false, false );
	update_option( $state_key, $state, false ); // Rollback snapshot precedes all fixture writes.

	$base_location = wc_get_base_location();
	foreach ( [ [ '5', 'Local', 1, '' ], [ '8.25', 'Regional', 2, '' ], [ '2', 'Reduced', 1, 'reduced-rate' ] ] as $fixture_rate ) {
		$rate_name = $state['rate_tag'] . ' ' . $fixture_rate[1];
		$class_argument = '' !== $fixture_rate[3] ? ' --class=' . escapeshellarg( (string) $fixture_rate[3] ) : '';
		$command = 'wc tax create --country=' . escapeshellarg( (string) $base_location['country'] )
			. ' --state=' . escapeshellarg( (string) $base_location['state'] )
			. ' --rate=' . escapeshellarg( $fixture_rate[0] )
			. ' --name=' . escapeshellarg( $rate_name )
			. ' --priority=' . absint( $fixture_rate[2] )
			. $class_argument
			. ' --compound=0 --shipping=0 --porcelain --user=admin';
		$output = trim( (string) WP_CLI::runcommand( $command, [ 'return' => 'stdout' ] ) );
		if ( ! preg_match( '/(?:^|\s)(\d+)\s*$/', $output, $matches ) ) {
			WP_CLI::error( 'WooCommerce did not return a tax-rate ID for fixture rate ' . $fixture_rate[1] . '.' );
		}
		$state['rate_ids'][] = (int) $matches[1];
		update_option( $state_key, $state, false );
	}

	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_tax_based_on', 'base' );
	update_option( 'woocommerce_default_customer_address', 'base' );
	update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
	update_option( 'woocommerce_tax_classes', 'Reduced rate' );

	$taxable = new WC_Product_Simple();
	$taxable->set_name( 'OPF E2E Commerce Tax Product' );
	$taxable->set_slug( 'opf-e2e-commerce-tax-product' );
	$taxable->set_regular_price( '10.00' );
	$taxable->set_tax_status( 'taxable' );
	$taxable->set_status( 'publish' );
	$taxable->set_catalog_visibility( 'visible' );
	$taxable_id = $taxable->save();
	$state['product_ids'][] = $taxable_id;
	$state['taxable_product_id'] = $taxable_id;
	update_option( $state_key, $state, false );

	$exempt = new WC_Product_Simple();
	$exempt->set_name( 'OPF E2E Non Taxable Product' );
	$exempt->set_slug( 'opf-e2e-non-taxable-product' );
	$exempt->set_regular_price( '10.00' );
	$exempt->set_tax_status( 'none' );
	$exempt->set_status( 'publish' );
	$exempt->set_catalog_visibility( 'visible' );
	$exempt_id = $exempt->save();
	$state['product_ids'][] = $exempt_id;
	$state['non_taxable_product_id'] = $exempt_id;
	update_option( $state_key, $state, false );

	$variable = new WC_Product_Variable();
	$variable->set_name( 'OPF E2E Variable Tax Product' );
	$variable->set_slug( 'opf-e2e-variable-tax-product' );
	$variable->set_regular_price( '10.00' );
	$variable->set_tax_status( 'taxable' );
	$variable->set_status( 'publish' );
	$variable->set_catalog_visibility( 'visible' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Color' );
	$attribute->set_options( [ 'Standard', 'Reduced' ] );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$variable->set_attributes( [ $attribute ] );
	$variable_id = $variable->save();
	$state['product_ids'][] = $variable_id;
	$state['variable_product_id'] = $variable_id;
	update_option( $state_key, $state, false );
	$variation_ids = [];
	foreach ( [ 'Standard' => [ 'price' => '10.00', 'tax_class' => '' ], 'Reduced' => [ 'price' => '12.00', 'tax_class' => 'reduced-rate' ] ] as $label => $variation_config ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $variable_id );
		$variation->set_attributes( [ 'color' => $label ] );
		$variation->set_regular_price( $variation_config['price'] );
		$variation->set_tax_class( $variation_config['tax_class'] );
		$variation->set_status( 'publish' );
		$variation_ids[ $label ] = $variation->save();
		$state['variation_ids'] = array_values( $variation_ids );
		$state['variation_by_label'] = $variation_ids;
		update_option( $state_key, $state, false );
	}

	$group = new OPF\Engine\FieldGroup( [
		'fields' => [
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'choices' => [
				[ 'slug' => 'standard', 'label' => 'Standard' ],
				[ 'slug' => 'premium', 'label' => 'Premium', 'pricing' => [ 'type' => 'fixed', 'amount' => 5, 'per_unit' => true ] ],
			] ],
			[ 'id' => 'setup', 'label' => 'Setup', 'type' => 'checkbox', 'choices' => [
				[ 'slug' => 'rush', 'label' => 'Rush setup', 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ],
			] ],
		],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $taxable_id, (string) $exempt_id, (string) $variable_id ] ] ] ] ],
	] );
	$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'OPF E2E Commerce Tax Group' ] );
	if ( ! $taxable_id || ! $exempt_id || ! $group_id ) {
		WP_CLI::error( 'Could not create the tax fixture.' );
	}
	$state['group_ids'][] = $group_id;
	$state['group_id'] = $group_id;
	update_option( $state_key, $state, false );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::log( 'TAX_PRODUCT_ID=' . $taxable_id );
	WP_CLI::log( 'TAX_PRODUCT_URL=' . get_permalink( $taxable_id ) );
	WP_CLI::log( 'NON_TAXABLE_PRODUCT_ID=' . $exempt_id );
	WP_CLI::log( 'TAX_GROUP_ID=' . $group_id );
	WP_CLI::log( 'VARIABLE_TAX_IDS=' . wp_json_encode( [ 'parent' => $variable_id, 'variations' => $variation_ids ] ) );
	WP_CLI::log( 'TAX_RATE_IDS=' . implode( ',', $state['rate_ids'] ) );
	WP_CLI::success( 'Tax fixture prepared with rollback state saved before mutation.' );
	return;
}

if ( ! is_array( $state ) || ( empty( $state['taxable_product_id'] ) && 'cleanup' !== $action ) ) {
	WP_CLI::error( 'Tax fixture state is missing.' );
}
$state = is_array( $state ) ? $state : [];

if ( 'record-rate' === $action ) {
	$rate_id = absint( $args[1] ?? 0 );
	if ( ! $rate_id ) {
		WP_CLI::error( 'A WooCommerce tax-rate ID is required.' );
	}
	$state['rate_ids'][] = $rate_id;
	update_option( $state_key, $state, false );
	WP_CLI::success( 'Recorded fixture tax-rate ID ' . $rate_id . '.' );
	return;
}

if ( 'mode' === $action ) {
	$prices_include = ( 'yes' === (string) ( $args[1] ?? '' ) ) ? 'yes' : 'no';
	$display = ( 'incl' === (string) ( $args[2] ?? '' ) ) ? 'incl' : 'excl';
	update_option( 'woocommerce_prices_include_tax', $prices_include );
	update_option( 'woocommerce_tax_display_shop', $display );
	update_option( 'woocommerce_tax_display_cart', $display );
	WP_CLI::log( 'TAX_MODE=' . $prices_include . '/' . $display );
	return;
}

if ( 'verify' === $action ) {
	$verify_request = json_decode( (string) ( $args[1] ?? '' ), true );
	$verify_request = is_array( $verify_request ) ? $verify_request : [];
	$variation_id = absint( $verify_request['variation_id'] ?? 0 );
	$product_id = $variation_id ? $variation_id : ( ! empty( $verify_request['non_taxable'] ) ? (int) $state['non_taxable_product_id'] : (int) $state['taxable_product_id'] );
	$product = wc_get_product( $product_id );
	$cart_product_id = $variation_id ? (int) $product->get_parent_id() : $product_id;
	$group_id = (string) $state['group_id'];
	$submitted_groups = isset( $verify_request['groups'] ) && is_array( $verify_request['groups'] )
		? $verify_request['groups']
		: [ $group_id => [ 'finish' => 'premium', 'setup' => [ 'rush' ] ] ];
	$quantity = max( 1, min( 10000, absint( $verify_request['quantity'] ?? 3 ) ) );
	$expected_options = isset( $verify_request['options'] ) && is_numeric( $verify_request['options'] )
		? (float) $verify_request['options']
		: 5.0 + ( 2.0 / $quantity );
	$cart = WC()->cart;
	$key = '';
	$order = null;
	try {
		$key = (string) $cart->add_to_cart(
			$cart_product_id,
			$quantity,
			$variation_id,
			$variation_id ? $product->get_variation_attributes() : [],
			[
				OPF\Service\CartIntegration::ITEM_KEY => $submitted_groups,
				'opf_base_price' => (float) $product->get_price( 'edit' ),
			]
		);
		if ( '' === $key ) {
			WP_CLI::error( 'WooCommerce rejected the tax fixture cart item.' );
		}
		$cart->calculate_totals();
		$item = $cart->get_cart_item( $key );
		$cart_product = $item['data'];
		$raw_base = (float) $product->get_price( 'edit' );
		$raw_final = (float) $cart_product->get_price( 'edit' );
		$base_shop = wc_get_price_to_display( $cart_product, [ 'price' => $raw_base, 'qty' => $quantity, 'display_context' => 'shop' ] );
		$final_shop = wc_get_price_to_display( $cart_product, [ 'price' => $raw_final, 'qty' => $quantity, 'display_context' => 'shop' ] );
		$base_cart = wc_get_price_to_display( $cart_product, [ 'price' => $raw_base, 'qty' => $quantity, 'display_context' => 'cart' ] );
		$final_cart = wc_get_price_to_display( $cart_product, [ 'price' => $raw_final, 'qty' => $quantity, 'display_context' => 'cart' ] );
		$factor = $raw_base > 0 ? (float) $base_shop / ( $raw_base * $quantity ) : 1.0;
		$factor_grand = ( $raw_final * $quantity ) * $factor;
		WP_CLI::log( 'PREVIEW=' . wp_json_encode( [ 'base' => $base_shop, 'options' => $final_shop - $base_shop, 'grand' => $final_shop ] ) );
		WP_CLI::log( 'SCALAR_FACTOR_GRAND=' . $factor_grand . '; exact=' . $final_shop . '; delta=' . ( $factor_grand - $final_shop ) );
		WP_CLI::log( 'CART=' . wp_json_encode( [ 'product_id' => $cart_product_id, 'variation_id' => $variation_id, 'tax_class' => $product->get_tax_class(), 'raw_unit' => $raw_final, 'line_total' => $item['line_total'], 'line_tax' => $item['line_tax'], 'display_total' => $cart->get_product_subtotal( $cart_product, $quantity ), 'helper_shop' => $final_shop, 'helper_cart' => $final_cart, 'grand' => $cart->get_total( 'edit' ) ] ) );
		$expected_line_tax = $product->is_taxable() ? null : 0.0;
		if ( abs( (float) $item['data']->get_price( 'edit' ) - ( $raw_base + $expected_options ) ) > 0.001 || abs( (float) $cart->get_total( 'edit' ) - ( (float) $item['line_total'] + (float) $item['line_tax'] ) ) > 0.02 || ( null !== $expected_line_tax && abs( (float) $item['line_tax'] - $expected_line_tax ) > 0.001 ) ) {
			WP_CLI::error( 'Cart price or tax does not match the selected OPF price.' );
		}

		$order = wc_create_order( [ 'status' => 'pending' ] );
		if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
			WP_CLI::error( 'Could not create disposable tax order.' );
		}
		$state['order_ids'][] = $order->get_id();
		update_option( $state_key, $state, false );
		$base_location = wc_get_base_location();
		$address = [ 'country' => $base_location['country'], 'state' => $base_location['state'], 'postcode' => get_option( 'woocommerce_store_postcode', '' ), 'city' => get_option( 'woocommerce_store_city', '' ), 'address_1' => get_option( 'woocommerce_store_address', '' ) ];
		$order->set_address( $address, 'billing' );
		$order->set_address( $address, 'shipping' );
		WC()->checkout()->create_order_line_items( $order, $cart );
		$order->calculate_taxes();
		$order->calculate_totals( false );
		$order_item = current( $order->get_items() );
		$stored = $order_item ? json_decode( (string) $order_item->get_meta( '_opf_fields', true ), true ) : null;
		WP_CLI::log( 'ORDER=' . wp_json_encode( [ 'line_total' => $order_item ? $order_item->get_total() : null, 'line_tax' => $order_item ? $order_item->get_total_tax() : null, 'grand' => $order->get_total(), 'field_values' => $stored ] ) );
		$stored_matches = is_array( $stored );
		foreach ( $submitted_groups as $submitted_group_id => $submitted_values ) {
			foreach ( (array) $submitted_values as $field_id => $submitted_value ) {
				if ( ! array_key_exists( (string) $field_id, (array) ( $stored[ (string) $submitted_group_id ] ?? [] ) ) || (array) $stored[ (string) $submitted_group_id ][ (string) $field_id ] !== (array) $submitted_value ) {
					$stored_matches = false;
				}
			}
		}
		if ( ! $order_item || abs( (float) $order_item->get_total() - (float) $item['line_total'] ) > 0.02 || abs( (float) $order_item->get_total_tax() - (float) $item['line_tax'] ) > 0.02 || ! $stored_matches ) {
			WP_CLI::error( 'Order line does not match the WooCommerce cart line.' );
		}
		WP_CLI::success( 'Shop preview, cart tax/line, and order persistence were measured.' );
	} finally {
		if ( $order instanceof WC_Order ) {
			$order->delete( true );
			$state['order_ids'] = array_values( array_diff( (array) ( $state['order_ids'] ?? [] ), [ $order->get_id() ] ) );
			update_option( $state_key, $state, false );
		}
		if ( $key ) {
			$cart->remove_cart_item( $key );
			$cart->calculate_totals();
		}
	}
	return;
}

if ( 'rate-ids' === $action ) {
	WP_CLI::log( 'TAX_RATE_IDS=' . implode( ',', array_map( 'absint', (array) ( $state['rate_ids'] ?? [] ) ) ) );
	return;
}

if ( 'cleanup' === $action ) {
	foreach ( (array) ( $state['variation_ids'] ?? [] ) as $variation_id ) {
		wp_delete_post( (int) $variation_id, true );
	}
	$rate_ids = array_map( 'absint', (array) ( $state['rate_ids'] ?? [] ) );
	// Recover a rate created just before an interrupted setup could persist its ID.
	$rates_json = (string) WP_CLI::runcommand( 'wc tax list --format=json --user=admin', [ 'return' => 'stdout' ] );
	$rates = json_decode( trim( $rates_json ), true );
	foreach ( is_array( $rates ) ? $rates : [] as $rate ) {
		if ( ! empty( $state['rate_tag'] ) && isset( $rate['name'], $rate['id'] ) && 0 === strpos( (string) $rate['name'], (string) $state['rate_tag'] . ' ' ) ) {
			$rate_ids[] = absint( $rate['id'] );
		}
	}
	$rate_ids = array_values( array_unique( array_filter( $rate_ids ) ) );
	foreach ( $rate_ids as $rate_id ) {
		if ( $rate_id ) {
			WP_CLI::runcommand( 'wc tax delete ' . $rate_id . ' --force=true --user=admin', [ 'return' => 'stdout' ] );
			WP_CLI::log( 'DELETED_FIXTURE_TAX_RATE_ID=' . $rate_id );
		}
	}
	$remaining_rates_json = (string) WP_CLI::runcommand( 'wc tax list --format=json --user=admin', [ 'return' => 'stdout' ] );
	$remaining_rates = json_decode( trim( $remaining_rates_json ), true );
	$remaining_rate_ids = array_map( 'absint', array_column( is_array( $remaining_rates ) ? $remaining_rates : [], 'id' ) );
	$expected_rate_ids = array_map( 'absint', (array) ( $state['existing_rate_ids'] ?? [] ) );
	sort( $remaining_rate_ids );
	sort( $expected_rate_ids );
	if ( $remaining_rate_ids !== $expected_rate_ids ) {
		WP_CLI::error( 'Tax fixture cleanup did not restore the original WooCommerce tax-rate ID set.' );
	}
	WP_CLI::log( 'TAX_RATE_IDS_RESTORED=' . implode( ',', $remaining_rate_ids ) );
	foreach ( (array) ( $state['order_ids'] ?? [] ) as $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( (array) ( $state['group_ids'] ?? [] ) as $group_id ) {
		wp_delete_post( (int) $group_id, true );
	}
	foreach ( (array) ( $state['product_ids'] ?? [] ) as $product_id ) {
		wp_delete_post( (int) $product_id, true );
	}
	foreach ( (array) ( $state['options'] ?? [] ) as $key => $previous ) {
		if ( ! empty( $previous['exists'] ) ) {
			update_option( $key, $previous['value'] );
		} else {
			delete_option( $key );
		}
	}
	update_option( 'active_plugins', (array) ( $state['active_plugins'] ?? [] ) );
	foreach ( (array) ( $state['options'] ?? [] ) as $key => $previous ) {
		$sentinel = '__OPF_E2E_OPTION_ABSENT__';
		$current = get_option( $key, $sentinel );
		if ( ( $sentinel !== $current ) !== ! empty( $previous['exists'] ) || ( $sentinel !== $current && $current !== $previous['value'] ) ) {
			WP_CLI::error( 'WooCommerce option was not restored exactly: ' . $key );
		}
	}
	if ( (array) get_option( 'active_plugins', [] ) !== (array) ( $state['active_plugins'] ?? [] ) ) {
		WP_CLI::error( 'Active plugin list was not restored exactly.' );
	}
	WP_CLI::log( 'WOO_OPTIONS_AND_ACTIVE_PLUGINS_RESTORED=exact' );
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Tax fixture records removed and original Woo options/plugin list restored.' );
	return;
}

WP_CLI::error( 'Use prepare, record-rate, mode, verify, rate-ids, or cleanup.' );
