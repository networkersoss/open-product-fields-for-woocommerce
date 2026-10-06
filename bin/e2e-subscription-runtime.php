<?php
/**
 * Real-runtime lifecycle proof for the WAPF-PRODUCT-SUBSCRIPTION ledger row.
 *
 * Runs inside a disposable WordPress clone that has the untouched WooCommerce
 * Subscriptions 9.2.0 source active, so every phase drives the real plugin
 * instead of a stub:
 *
 *   setup        real `subscription` + `variable-subscription` products with
 *                `_subscription_price` meta and one OPF group whose required
 *                text field carries a +5 fixed addon.
 *   cart         classic add-to-cart, then repeated totals passes. The
 *                recurring base must stay `WC_Subscriptions_Product::get_price()`
 *                (30.00) and must never absorb the addon (35.00 max).
 *   checkout     real `WC_Checkout::process_checkout()` through the `cod`
 *                gateway with manual renewals enabled; the order and the
 *                WCS-created subscription must carry `_opf_fields`.
 *   renewal      `wcs_create_renewal_order()` plus the scheduled-payment
 *                dispatch, renewal line meta and the renewal cart rebuild
 *                (`wcs_before/after_renewal_setup_cart_subscriptions`).
 *   order-again  real `$_GET['order_again']` reload through
 *                `WC_Cart_Session::get_cart_from_session()`, then re-checkout.
 *   refund       real `wc_create_refund()` partial refund.
 *
 * Phases: `OPF_SUB_RT_PHASE` env var. Guarded to a /tmp WordPress clone and the
 * explicit `OPF_SUB_RT_ALLOW=1` token.
 */

if ( '1' !== getenv( 'OPF_SUB_RT_ALLOW' ) || 0 !== strpos( (string) realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Explicit disposable /tmp authorization required (OPF_SUB_RT_ALLOW=1).' );
}

const OPF_SUB_RT_STATE_OPTION = 'opf_sub_rt_state';
const OPF_SUB_RT_SLUG_PREFIX  = 'opf-runtime-subscription';
const OPF_SUB_RT_CUSTOMER     = 'opf-runtime-customer@example.test';
const OPF_SUB_RT_FIELD_ID     = 'rt_note';
const OPF_SUB_RT_FIELD_VALUE  = 'runtime monthly plan';
const OPF_SUB_RT_PERIOD_PRICE = 30.0;
const OPF_SUB_RT_ADDON        = 5.0;
const OPF_SUB_RT_VARIATION    = 20.0;

$GLOBALS['opf_sub_rt_checks'] = [];

/** Record one assertion; failures are collected, never aborting mid-phase. */
function opf_sub_rt_check( string $name, bool $pass, $detail = null ): void {
	$GLOBALS['opf_sub_rt_checks'][] = [
		'name'   => $name,
		'pass'   => $pass,
		'detail' => $detail,
	];
}

/** Read/write the cross-process fixture state. */
function opf_sub_rt_state( ?array $set = null ): array {
	if ( null !== $set ) {
		update_option( OPF_SUB_RT_STATE_OPTION, $set, false );
		opf_sub_rt_artifacts( 'state.json', $set );
		return $set;
	}
	return (array) get_option( OPF_SUB_RT_STATE_OPTION, [] );
}

/** Write a JSON artifact into the phase artifact directory. */
function opf_sub_rt_artifacts( string $name, array $payload ): void {
	$dir = (string) ( getenv( 'OPF_SUB_RT_ARTIFACTS' ) ?: '' );
	if ( '' === $dir ) {
		return;
	}
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents( trailingslashit( $dir ) . $name, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}

/** Persist the phase result and stop the process with a non-zero status on failure. */
function opf_sub_rt_finish( string $phase, array $extra = [] ): void {
	$checks  = $GLOBALS['opf_sub_rt_checks'];
	$failed  = array_values( array_filter( $checks, static fn( $c ) => ! $c['pass'] ) );
	$payload = [
		'phase'   => $phase,
		'passed'  => count( $checks ) - count( $failed ),
		'failed'  => count( $failed ),
		'checks'  => $checks,
		'extra'   => $extra,
	];
	opf_sub_rt_artifacts( $phase . '.json', $payload );
	echo "phase=$phase passed=" . $payload['passed'] . " failed=" . $payload['failed'] . "\n";
	foreach ( $failed as $f ) {
		echo "  FAIL {$f['name']}: " . wp_json_encode( $f['detail'] ) . "\n";
	}
	if ( $failed ) {
		throw new RuntimeException( "OPF subscription runtime phase {$phase} failed " . count( $failed ) . ' assertion(s).' );
	}
	echo "SUCCESS subscription-runtime {$phase}\n";
}

/** Enable the store settings the real lifecycle needs (manual renewals + COD). */
function opf_sub_rt_store_settings(): void {
	update_option( 'woocommerce_subscriptions_accept_manual_renewals', 'yes' );
	update_option(
		'woocommerce_cod_settings',
		[
			'enabled'      => 'yes',
			'title'        => 'Cash on delivery',
			'description'  => 'Disposable runtime clone.',
			'instructions' => '',
		]
	);
	update_option( 'woocommerce_enable_guest_checkout', 'no' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_currency', 'USD' );
}

/** Remove everything a previous run of this fixture created. */
function opf_sub_rt_reset(): array {
	$removed = [ 'products' => 0, 'variations' => 0, 'groups' => 0, 'orders' => 0, 'subscriptions' => 0 ];

	foreach ( [ 'product', 'product_variation' ] as $type ) {
		foreach ( get_posts( [ 'post_type' => $type, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ] ) as $id ) {
			if ( 0 !== strpos( (string) get_post_field( 'post_name', $id ), OPF_SUB_RT_SLUG_PREFIX ) ) {
				continue;
			}
			wp_delete_post( $id, true );
			++$removed[ 'product_variation' === $type ? 'variations' : 'products' ];
		}
	}

	foreach ( [ 'opf_field_group' => 'groups', 'shop_subscription' => 'subscriptions' ] as $type => $key ) {
		foreach ( get_posts( [ 'post_type' => $type, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ] ) as $id ) {
			wp_delete_post( $id, true );
			++$removed[ $key ];
		}
	}

	foreach ( wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'status' => 'any' ] ) as $order_id ) {
		wp_delete_post( $order_id, true );
		++$removed['orders'];
	}

	foreach ( get_users( [ 'search' => OPF_SUB_RT_CUSTOMER, 'search_columns' => [ 'user_email' ], 'number' => 1 ] ) as $user ) {
		wp_delete_user( $user->ID );
	}

	\OPF\Service\FieldGroups::flush_cache();

	return $removed;
}

/** Real simple subscription product with `_subscription_price` meta. */
function opf_sub_rt_create_subscription_product( string $slug, string $name, string $price ): int {
	$product = new \WC_Product_Subscription();
	$product->set_name( $name );
	$product->set_slug( $slug );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_virtual( true );
	$product->set_regular_price( $price );
	$product->set_sale_price( '' );
	$product->set_price( $price );
	$id = (int) $product->save();

	update_post_meta( $id, '_subscription_price', $price );
	update_post_meta( $id, '_subscription_period', 'month' );
	update_post_meta( $id, '_subscription_period_interval', '1' );
	update_post_meta( $id, '_subscription_length', '0' );
	update_post_meta( $id, '_subscription_trial_length', '0' );
	update_post_meta( $id, '_subscription_trial_period', 'month' );
	update_post_meta( $id, '_subscription_sign_up_fee', '' );
	WC_Product_Variable::sync( $id );

	return $id;
}

/** Variable subscription parent plus the monthly/yearly variations. */
function opf_sub_rt_create_variable_subscription( string $slug, string $name ): array {
	$parent = new \WC_Product_Variable_Subscription();
	$parent->set_name( $name );
	$parent->set_slug( $slug );
	$parent->set_status( 'publish' );
	$parent->set_catalog_visibility( 'visible' );
	$parent->set_virtual( true );
	$parent_id = (int) $parent->save();

	$attribute = new \WC_Product_Attribute();
	$attribute->set_name( 'billing_term' );
	$attribute->set_options( [ 'monthly', 'yearly' ] );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$parent->set_attributes( [ 'billing_term' => $attribute ] );
	$parent->save();

	$variations = [];
	foreach ( [ 'monthly' => '20', 'yearly' => '200' ] as $term => $price ) {
		$variation = new \WC_Product_Subscription_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );
		$variation->set_virtual( true );
		$variation->set_regular_price( $price );
		$variation->set_price( $price );
		$variation->set_attributes( [ 'billing_term' => $term ] );
		$variation_id = (int) $variation->save();

		update_post_meta( $variation_id, '_subscription_price', $price );
		update_post_meta( $variation_id, '_subscription_period', 'month' );
		update_post_meta( $variation_id, '_subscription_period_interval', '1' );
		update_post_meta( $variation_id, '_subscription_length', '0' );
		update_post_meta( $variation_id, '_subscription_sign_up_fee', '' );
		$variations[ $term ] = $variation_id;
	}

	WC_Product_Variable::sync( $parent_id );

	return [ 'parent' => $parent_id ] + $variations;
}

/** The OPF group: required text field, +5 fixed, scoped to subscription types. */
function opf_sub_rt_create_group(): int {
	$group_id = \OPF\Service\FieldGroups::save(
		0,
		[
			'fields'      => [
				[
					'id'       => OPF_SUB_RT_FIELD_ID,
					'label'    => 'Runtime note',
					'type'     => 'text',
					'required' => true,
					'pricing'  => [ 'type' => 'fixed', 'amount' => OPF_SUB_RT_ADDON, 'formula' => '', 'per_unit' => true ],
				],
			],
			'rule_groups' => [
				[
					'rules' => [
						[
							'subject'  => 'product_type',
							'operator' => 'in',
							'terms'    => [ 'subscription', 'variable-subscription' ],
						],
					],
				],
			],
		],
		[ 'title' => 'OPF runtime subscription group', 'status' => 'publish' ]
	);
	\OPF\Service\FieldGroups::flush_cache();
	return (int) $group_id;
}

/** Become the runtime customer for the rest of the request. */
function opf_sub_rt_act_as_customer( int $user_id ): void {
	wp_set_current_user( $user_id );
	if ( ! WC()->customer instanceof \WC_Customer || (int) WC()->customer->get_id() !== $user_id ) {
		WC()->customer = new \WC_Customer( $user_id, true );
	}
}

/** Submit the OPF payload the storefront form would post. */
function opf_sub_rt_submit( int $group_id, string $value = OPF_SUB_RT_FIELD_VALUE ): void {
	$_POST['opf'] = [ (string) $group_id => [ OPF_SUB_RT_FIELD_ID => $value ] ];
}

/** Cart item lookups for the fixture's single line. */
function opf_sub_rt_line( string $key ): array {
	return (array) WC()->cart->get_cart_item( $key );
}

/** Numeric recurring cart totals, keyed by billing period. */
function opf_sub_rt_recurring_totals(): array {
	$totals = [];
	foreach ( (array) WC()->cart->recurring_carts as $key => $cart ) {
		$totals[ $key ] = [
			'total'    => (float) $cart->get_total( 'edit' ),
			'subtotal' => (float) $cart->get_subtotal(),
			'items'    => count( $cart->get_cart() ),
			'prices'   => array_map( static fn( $i ) => (float) $i['data']->get_price( 'edit' ), array_values( $cart->get_cart() ) ),
		];
	}
	return $totals;
}

/**
 * Drive the real `WC_Checkout::process_checkout()`. The storefront redirect
 * that follows a successful payment terminates a CLI request, so the success
 * result is intercepted with a sentinel exception before the redirect.
 */
function opf_sub_rt_run_checkout(): array {
	$sentinel  = 'opf_runtime_checkout_redirect';
	$intercepted = false;
	$intercept = static function ( $result ) use ( $sentinel, &$intercepted ) {
		$intercepted = true;
		throw new \Exception( $sentinel );
	};

	add_filter( 'woocommerce_payment_successful_result', $intercept, 1, 1 );
	wc_clear_notices();
	WC()->checkout()->process_checkout();
	remove_filter( 'woocommerce_payment_successful_result', $intercept, 1 );

	$errors = [];
	foreach ( wc_get_notices( 'error' ) as $notice ) {
		$message = is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice;
		if ( $sentinel !== $message ) {
			$errors[] = $message;
		}
	}
	wc_clear_notices();

	return [ 'errors' => $errors, 'payment_reached' => $intercepted ];
}

/**
 * Confirm the offline (COD) payment the way a store manager does, and return
 * the reloaded order. `payment_complete()` records the paid date; the order is
 * then completed so it is eligible for the customer-facing reorder path.
 */
function opf_sub_rt_mark_order_paid( \WC_Order $order ): \WC_Order {
	$order->payment_complete();
	$order = wc_get_order( $order->get_id() );
	if ( 'completed' !== $order->get_status() ) {
		$order->update_status( 'completed', 'OPF runtime: offline payment confirmed by the store.' );
		$order = wc_get_order( $order->get_id() );
	}
	return $order;
}

/**
 * WooCommerce Subscriptions public API. Resolved by name at call time so this
 * driver parses without the Subscriptions stubs (the repository's composer dev
 * dependencies ship WooCommerce and WordPress stubs only) and so a clone
 * without Subscriptions fails with a precise message instead of a fatal.
 */
function opf_sub_rt_wcs( string $function, ...$args ) {
	if ( ! function_exists( $function ) ) {
		throw new RuntimeException( 'WooCommerce Subscriptions is not active: ' . $function . '() is undefined.' );
	}
	return call_user_func_array( $function, $args );
}

/**
 * Call a WooCommerce Subscriptions-specific method. `WC_Subscription` extends
 * `WC_Order`, but its extra API (`is_manual()`, `get_date()`, ...) is not part
 * of the repository's WooCommerce dev stubs, so those calls are resolved by
 * name and reported precisely when the subscription API is not present.
 */
function opf_sub_rt_sub_call( $subscription, string $method, ...$args ) {
	if ( ! is_object( $subscription ) || ! method_exists( $subscription, $method ) ) {
		return null;
	}
	return call_user_func_array( [ $subscription, $method ], $args );
}

/** Order/subscription line item meta helpers. */
function opf_sub_rt_order_item_fields( \WC_Order $order ): array {
	$out = [];
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			continue;
		}
		$out[] = [
			'name'         => $item->get_name(),
			'product_id'   => (int) $item->get_product_id(),
			'variation_id' => (int) $item->get_variation_id(),
			'total'        => (float) $item->get_total(),
			'opf_fields'   => $item->get_meta( '_opf_fields', true ),
			'snapshot'     => '' !== (string) $item->get_meta( '_opf_fields_snapshot', true ),
		];
	}
	return $out;
}

$phase = (string) ( getenv( 'OPF_SUB_RT_PHASE' ) ?: 'setup' );

/* ------------------------------------------------------------------ setup -- */

if ( 'setup' === $phase ) {
	$state = opf_sub_rt_state();

	// A re-run inside the same clone must start from a clean slate; the harness
	// otherwise provisions a fresh clone.
	$state['removed_on_setup'] = opf_sub_rt_reset();
	opf_sub_rt_store_settings();

	$simple_id = opf_sub_rt_create_subscription_product(
		OPF_SUB_RT_SLUG_PREFIX,
		'OPF Runtime Subscription',
		(string) OPF_SUB_RT_PERIOD_PRICE
	);

	$variable = opf_sub_rt_create_variable_subscription(
		OPF_SUB_RT_SLUG_PREFIX . '-variable',
		'OPF Runtime Variable Subscription'
	);

	$group_id = opf_sub_rt_create_group();

	$user_id = 0;
	$existing = get_user_by( 'email', OPF_SUB_RT_CUSTOMER );
	if ( $existing ) {
		$user_id = (int) $existing->ID;
		$existing->set_role( 'customer' );
	} else {
		$user_id = (int) wp_insert_user(
			[
				'user_login'   => 'opf-runtime-customer',
				'user_email'   => OPF_SUB_RT_CUSTOMER,
				'user_pass'    => wp_generate_password( 20 ),
				'display_name' => 'OPF Runtime Customer',
				'first_name'   => 'OPF',
				'last_name'    => 'Runtime',
				'role'         => 'customer',
			]
		);
	}

	$simple   = wc_get_product( $simple_id );
	$variable_parent = wc_get_product( $variable['parent'] );
	$variation = wc_get_product( $variable['monthly'] );

	// Plain assignments, not `+=`: a re-run inside the same clone must replace
	// the ids recorded by the previous run instead of keeping the stale ones.
	$state['simple_product']    = $simple_id;
	$state['variable_product']  = $variable['parent'];
	$state['variation_monthly'] = $variable['monthly'];
	$state['variation_yearly']  = $variable['yearly'];
	$state['group']             = $group_id;
	$state['customer']          = $user_id;
	foreach ( [ 'order', 'subscription', 'renewal_order', 'reordered_order', 'refund', 'checkout_cart_item_key' ] as $stale ) {
		unset( $state[ $stale ] );
	}
	opf_sub_rt_state( $state );

	$active_plugins = (array) get_option( 'active_plugins', [] );
	$environment    = [
		'wordpress'   => get_bloginfo( 'version' ),
		'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
		'subscriptions' => class_exists( 'WC_Subscriptions' ) ? WC_Subscriptions::$version : 'absent',
		'php'         => PHP_VERSION,
		'site_url'    => get_option( 'siteurl' ),
		'plugins'     => $active_plugins,
		'hpos'        => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off',
	];

	opf_sub_rt_check( 'real WooCommerce Subscriptions class loaded', class_exists( 'WC_Subscriptions_Product' ) );
	opf_sub_rt_check( 'simple product is a real subscription', $simple && 'subscription' === $simple->get_type(), $simple ? $simple->get_type() : null );
	opf_sub_rt_check(
		'simple product recurring base is 30',
		$simple && abs( (float) WC_Subscriptions_Product::get_price( $simple ) - OPF_SUB_RT_PERIOD_PRICE ) < 0.000001,
		$simple ? WC_Subscriptions_Product::get_price( $simple ) : null
	);
	opf_sub_rt_check( 'variable parent is a real variable-subscription', $variable_parent && 'variable-subscription' === $variable_parent->get_type(), $variable_parent ? $variable_parent->get_type() : null );
	opf_sub_rt_check( 'monthly variation is a real subscription variation', $variation && 'subscription_variation' === $variation->get_type(), $variation ? $variation->get_type() : null );
	opf_sub_rt_check( 'monthly variation recurring base is 20', $variation && abs( (float) WC_Subscriptions_Product::get_price( $variation ) - 20.0 ) < 0.000001, $variation ? WC_Subscriptions_Product::get_price( $variation ) : null );
	opf_sub_rt_check( 'OPF group saved', $group_id > 0, $group_id );
	opf_sub_rt_check( 'OPF group matches the subscription product', in_array( $group_id, array_map( static fn( $e ) => (int) $e['id'], \OPF\Service\FieldGroups::for_product( $simple ) ), true ) );
	opf_sub_rt_check( 'customer ready', $user_id > 0, $user_id );
	opf_sub_rt_check( 'manual renewals enabled', 'yes' === get_option( 'woocommerce_subscriptions_accept_manual_renewals' ) );
	opf_sub_rt_check( 'action scheduler present', class_exists( 'ActionScheduler_Store' ) );

	opf_sub_rt_finish( $phase, [ 'environment' => $environment, 'state' => $state ] );
	return;
}

$state    = opf_sub_rt_state();
$group_id = (int) ( $state['group'] ?? 0 );
$user_id  = (int) ( $state['customer'] ?? 0 );

if ( $group_id <= 0 || $user_id <= 0 ) {
	throw new RuntimeException( 'Runtime fixture state missing; run the setup phase first.' );
}

opf_sub_rt_act_as_customer( $user_id );
opf_sub_rt_store_settings();

/* ------------------------------------------------------------------- cart -- */

if ( 'cart' === $phase ) {
	$product_id = (int) $state['simple_product'];
	$product    = wc_get_product( $product_id );
	$meta_price = (string) get_post_meta( $product_id, '_subscription_price', true );

	WC()->cart->empty_cart();
	opf_sub_rt_submit( $group_id );
	$valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );
	$key   = WC()->cart->add_to_cart( $product_id, 1 );
	WC()->cart->calculate_totals();

	$line         = opf_sub_rt_line( (string) $key );
	$line_price   = $line ? (float) $line['data']->get_price( 'edit' ) : 0.0;
	$base_stored  = $line ? (float) $line['opf_base_price'] : 0.0;
	$recurring    = opf_sub_rt_recurring_totals();
	$cart_total   = (float) WC()->cart->get_total( 'edit' );

	opf_sub_rt_check( 'add-to-cart validation passes with the required field', true === $valid, $valid );
	opf_sub_rt_check( 'cart line created', is_string( $key ) && '' !== $key, $key );
	opf_sub_rt_check( 'stored OPF base is the recurring price 30', abs( $base_stored - OPF_SUB_RT_PERIOD_PRICE ) < 0.000001, $base_stored );
	opf_sub_rt_check( 'cart line is recurring base 30 + addon 5 = 35', abs( $line_price - 35.0 ) < 0.000001, $line_price );
	opf_sub_rt_check( 'cart total is 35', abs( $cart_total - 35.0 ) < 0.000001, $cart_total );
	$recurring_first = array_values( $recurring )[0] ?? null;

	opf_sub_rt_check( 'one recurring cart exists', 1 === count( $recurring ), $recurring );
	opf_sub_rt_check( 'recurring cart total is 35, not 40', null !== $recurring_first && abs( $recurring_first['total'] - 35.0 ) < 0.000001, $recurring );
	opf_sub_rt_check( 'recurring cart line price is 35', null !== $recurring_first && isset( $recurring_first['prices'][0] ) && abs( $recurring_first['prices'][0] - 35.0 ) < 0.000001, $recurring );

	// Repeated totals passes are the compounding trap: the base must be
	// re-read from the subscription API every pass.
	$passes = [];
	for ( $i = 1; $i <= 3; $i++ ) {
		WC()->cart->calculate_totals();
		$passes[] = [
			'pass'      => $i,
			'line'      => (float) opf_sub_rt_line( (string) $key )['data']->get_price( 'edit' ),
			'recurring' => (float) ( array_values( opf_sub_rt_recurring_totals() )[0]['total'] ?? 0 ),
			'total'     => (float) WC()->cart->get_total( 'edit' ),
		];
	}
	opf_sub_rt_check(
		'four totals passes stay at 35 (no addon compounding)',
		! array_filter( $passes, static fn( $p ) => abs( $p['line'] - 35.0 ) > 0.000001 || abs( $p['recurring'] - 35.0 ) > 0.000001 ),
		$passes
	);

	// Direct filter contract: even with a product object already mutated to the
	// addon-inclusive price, the WCS adapter re-anchors on the recurring price.
	$mutated = wc_get_product( $product_id );
	$mutated->set_price( '35' );
	$filtered = (float) apply_filters( 'opf_cart_item_base_price', 35.0, $mutated, opf_sub_rt_line( (string) $key ) );
	opf_sub_rt_check( 'adapter re-anchors 35 back to the 30 recurring base', abs( $filtered - 30.0 ) < 0.000001, $filtered );

	// The catalog product is never dirtied by cart pricing.
	$fresh = wc_get_product( $product_id );
	opf_sub_rt_check( 'catalog product price still 30', abs( (float) $fresh->get_price( 'edit' ) - 30.0 ) < 0.000001, $fresh->get_price( 'edit' ) );
	opf_sub_rt_check( 'catalog _subscription_price meta still 30', '30' === $meta_price || abs( (float) $meta_price - 30.0 ) < 0.000001, $meta_price );
	opf_sub_rt_check( 'WCS recurring base still 30', abs( (float) WC_Subscriptions_Product::get_price( $fresh ) - 30.0 ) < 0.000001, WC_Subscriptions_Product::get_price( $fresh ) );

	// Renewal-style rebuild must skip OPF validation (WAPF parity).
	opf_sub_rt_check( 'validation active outside renewal', false === apply_filters( 'opf_skip_validation', false ) );
	do_action( 'wcs_before_renewal_setup_cart_subscriptions', [], null );
	opf_sub_rt_check( 'renewal setup skips OPF validation', true === apply_filters( 'opf_skip_validation', false ) );
	do_action( 'wcs_after_renewal_setup_cart_subscriptions', [], null );
	opf_sub_rt_check( 'renewal teardown restores validation', false === apply_filters( 'opf_skip_validation', false ) );

	// Variable subscription: the same recurring-base contract must hold for a
	// real `subscription_variation` product through the real WCS product class.
	$variation_parent = (int) $state['variable_product'];
	$variation_id     = (int) $state['variation_monthly'];
	WC()->cart->empty_cart();
	opf_sub_rt_submit( $group_id );
	$variation_attributes = [ 'billing_term' => 'monthly' ];
	$variation_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $variation_parent, 1, $variation_id, $variation_attributes );
	$variation_key   = WC()->cart->add_to_cart( $variation_parent, 1, $variation_id, $variation_attributes );
	WC()->cart->calculate_totals();

	$variation_line  = $variation_key ? opf_sub_rt_line( (string) $variation_key ) : [];
	$variation_price = $variation_line ? (float) $variation_line['data']->get_price( 'edit' ) : 0.0;
	$variation_base  = $variation_line ? (float) ( $variation_line['opf_base_price'] ?? 0 ) : 0.0;
	$variation_cart  = array_values( opf_sub_rt_recurring_totals() )[0]['total'] ?? 0.0;
	$variation_object = wc_get_product( $variation_id );

	opf_sub_rt_check( 'variation add-to-cart validation passes', true === $variation_valid, $variation_valid );
	opf_sub_rt_check( 'subscription variation is a real subscription_variation', $variation_object && 'subscription_variation' === $variation_object->get_type(), $variation_object ? $variation_object->get_type() : null );
	opf_sub_rt_check( 'variation recurring base is 20', $variation_object && abs( (float) WC_Subscriptions_Product::get_price( $variation_object ) - 20.0 ) < 0.000001, $variation_object ? WC_Subscriptions_Product::get_price( $variation_object ) : null );
	opf_sub_rt_check( 'variation cart line is 20 + 5 = 25', abs( $variation_price - 25.0 ) < 0.000001, $variation_price );
	opf_sub_rt_check( 'variation stored OPF base is 20', abs( $variation_base - 20.0 ) < 0.000001, $variation_base );
	opf_sub_rt_check( 'variation recurring cart total is 25', abs( (float) $variation_cart - 25.0 ) < 0.000001, $variation_cart );

	WC()->cart->empty_cart();

	$result = [
		'cart_item_key'   => $key,
		'line_price'      => $line_price,
		'stored_base'     => $base_stored,
		'cart_total'      => $cart_total,
		'recurring_carts' => $recurring,
		'passes'          => $passes,
		'reanchored_base' => $filtered,
		'product_price'   => (float) $fresh->get_price( 'edit' ),
		'subscription_price_meta' => $meta_price,
		'wcs_api_price'   => (float) WC_Subscriptions_Product::get_price( $fresh ),
		'variation'       => [
			'variation_id'    => $variation_id,
			'line_price'      => $variation_price,
			'stored_base'     => $variation_base,
			'recurring_total' => (float) $variation_cart,
			'wcs_api_price'   => $variation_object ? (float) WC_Subscriptions_Product::get_price( $variation_object ) : null,
		],
		'submitted'       => [ 'opf' => [ (string) $group_id => [ OPF_SUB_RT_FIELD_ID => OPF_SUB_RT_FIELD_VALUE ] ] ],
	];
	opf_sub_rt_finish( $phase, $result );
	return;
}

/* --------------------------------------------------------------- checkout -- */

if ( 'checkout' === $phase ) {
	$product_id = (int) $state['simple_product'];
	$uid        = get_current_user_id();

	WC()->cart->empty_cart();
	opf_sub_rt_submit( $group_id );
	$valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );
	$key   = WC()->cart->add_to_cart( $product_id, 1 );
	WC()->cart->calculate_totals();

	$checkout_fields = [
		'billing_first_name'         => 'OPF',
		'billing_last_name'          => 'Runtime',
		'billing_company'            => '',
		'billing_country'            => 'US',
		'billing_address_1'          => '1 Runtime Street',
		'billing_address_2'          => '',
		'billing_city'               => 'Testville',
		'billing_state'              => 'CA',
		'billing_postcode'           => '90210',
		'billing_phone'              => '5550001111',
		'billing_email'              => OPF_SUB_RT_CUSTOMER,
		'shipping_first_name'        => 'OPF',
		'shipping_last_name'         => 'Runtime',
		'shipping_country'           => 'US',
		'shipping_address_1'         => '1 Runtime Street',
		'shipping_address_2'         => '',
		'shipping_city'              => 'Testville',
		'shipping_state'             => 'CA',
		'shipping_postcode'          => '90210',
		'ship_to_different_address'  => '0',
		'order_comments'             => '',
		'payment_method'             => 'cod',
		'terms'                      => '1',
		'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
		'opf'                        => [ (string) $group_id => [ OPF_SUB_RT_FIELD_ID => OPF_SUB_RT_FIELD_VALUE ] ],
	];
	$_POST  = $checkout_fields;
	$_REQUEST = $checkout_fields;

	$checkout_result = opf_sub_rt_run_checkout();
	$errors          = $checkout_result['errors'];

	$orders = wc_get_orders( [ 'limit' => 1, 'customer_id' => $uid, 'orderby' => 'date', 'order' => 'DESC', 'status' => 'any', 'type' => 'shop_order' ] );
	$order  = $orders ? $orders[0] : null;

	opf_sub_rt_check( 'add-to-cart validation passes', true === $valid, $valid );
	opf_sub_rt_check( 'checkout raised no WooCommerce error notices', [] === $errors, $errors );
	opf_sub_rt_check( 'checkout reached the payment gateway', true === $checkout_result['payment_reached'] );
	opf_sub_rt_check( 'checkout created an order', $order instanceof \WC_Order, $errors );

	if ( ! $order instanceof \WC_Order ) {
		opf_sub_rt_finish( $phase, [ 'errors' => $errors, 'checkout' => $checkout_result ] );
		return;
	}

	$order_id = $order->get_id();
	// COD leaves the order in an unbilled state; confirming the offline payment
	// is the public path that activates a manual-renewal subscription.
	$status_before = $order->get_status();
	$order         = opf_sub_rt_mark_order_paid( $order );
	$order->update_meta_data( '_opf_rt_checkout_status_before_payment', $status_before );
	$order->save();

	$subscriptions = (array) opf_sub_rt_wcs( 'wcs_get_subscriptions_for_order', $order_id, [ 'order_type' => 'any' ] );
	$subscription  = $subscriptions ? array_values( $subscriptions )[0] : null;
	$is_sub_object = is_object( $subscription ) && 'WC_Subscription' === get_class( $subscription );

	$items = opf_sub_rt_order_item_fields( $order );
	$line  = $items[0] ?? [];

	opf_sub_rt_check( 'order status is completed after payment', 'completed' === $order->get_status(), $order->get_status() );
	opf_sub_rt_check( 'order total is 35 (base 30 + addon 5)', abs( (float) $order->get_total() - 35.0 ) < 0.000001, $order->get_total() );
	opf_sub_rt_check( 'order line persisted _opf_fields', isset( $line['opf_fields'] ) && '' !== (string) $line['opf_fields'], $line['opf_fields'] ?? null );
	$decoded = json_decode( (string) ( $line['opf_fields'] ?? '' ), true );
	opf_sub_rt_check(
		'order line _opf_fields carries the submitted value',
		is_array( $decoded ) && ( ( $decoded[ (string) $group_id ][ OPF_SUB_RT_FIELD_ID ] ?? null ) === OPF_SUB_RT_FIELD_VALUE ),
		$decoded
	);
	opf_sub_rt_check( 'order line persisted the field snapshot', ! empty( $line['snapshot'] ), $line );
	opf_sub_rt_check( 'WCS created a subscription for the order', $is_sub_object, array_keys( $subscriptions ) );

	if ( $subscription instanceof \WC_Order ) {
		$sub_items = opf_sub_rt_order_item_fields( $subscription );
		$sub_line  = $sub_items[0] ?? [];
		$sub_decoded = json_decode( (string) ( $sub_line['opf_fields'] ?? '' ), true );
		opf_sub_rt_check( 'the related order is a WC_Subscription object', $is_sub_object, is_object( $subscription ) ? get_class( $subscription ) : null );
		opf_sub_rt_check( 'subscription is active', $subscription->has_status( 'active' ), $subscription->get_status() );
		opf_sub_rt_check( 'subscription recurring total is 35', abs( (float) $subscription->get_total() - 35.0 ) < 0.000001, $subscription->get_total() );
		opf_sub_rt_check( 'subscription line persisted _opf_fields', isset( $sub_line['opf_fields'] ) && '' !== (string) $sub_line['opf_fields'], $sub_line['opf_fields'] ?? null );
		opf_sub_rt_check(
			'subscription line _opf_fields carries the submitted value',
			is_array( $sub_decoded ) && ( $sub_decoded[ (string) $group_id ][ OPF_SUB_RT_FIELD_ID ] ?? null ) === OPF_SUB_RT_FIELD_VALUE,
			$sub_decoded
		);
		opf_sub_rt_check( 'subscription requires manual renewal (COD)', true === opf_sub_rt_sub_call( $subscription, 'is_manual' ), opf_sub_rt_sub_call( $subscription, 'is_manual' ) );
		$sub_next_payment = (string) opf_sub_rt_sub_call( $subscription, 'get_date', 'next_payment' );
		opf_sub_rt_check( 'subscription next payment scheduled', '' !== $sub_next_payment, $sub_next_payment );
	}

	$state['order']        = $order_id;
	$state['subscription'] = $subscription instanceof \WC_Order ? $subscription->get_id() : 0;
	$state['checkout_cart_item_key'] = $key;
	opf_sub_rt_state( $state );

	$scheduled = [];
	if ( $subscription instanceof \WC_Order && function_exists( 'as_get_scheduled_actions' ) ) {
		$scheduled = (array) as_get_scheduled_actions(
			[
				'hook'     => 'woocommerce_scheduled_subscription_payment',
				'args'     => [ $subscription->get_id() ],
				'per_page' => -1,
			],
			'ids'
		);
	}

	opf_sub_rt_finish(
		$phase,
		[
			'order_id'            => $order_id,
			'order_status'        => $order->get_status(),
			'order_total'         => (float) $order->get_total(),
			'order_items'         => $items,
			'subscription_id'     => $state['subscription'],
			'scheduled_payments'  => array_values( $scheduled ),
			'notices'             => $errors,
		]
	);
	return;
}

/* ---------------------------------------------------------------- renewal -- */

if ( 'renewal' === $phase ) {
	$subscription_id = (int) ( $state['subscription'] ?? 0 );
	$subscription    = $subscription_id ? opf_sub_rt_wcs( 'wcs_get_subscription', $subscription_id ) : null;
	opf_sub_rt_check( 'subscription resolves', $subscription instanceof \WC_Order, $subscription_id );

	if ( ! $subscription instanceof \WC_Order ) {
		opf_sub_rt_finish( $phase );
		return;
	}

	$next_payment = (string) opf_sub_rt_sub_call( $subscription, 'get_date', 'next_payment' );
	$status_before_renewal = (string) $subscription->get_status();

	// The hook Action Scheduler fires for the `next_payment` date. Dispatching it
	// runs WCS_Subscriptions_Manager::prepare_renewal(), which is the real
	// renewal-order creator, instead of waiting for cron.
	do_action( 'woocommerce_scheduled_subscription_payment', $subscription_id );
	$subscription_after_dispatch = opf_sub_rt_wcs( 'wcs_get_subscription', $subscription_id );
	$status_on_due               = (string) opf_sub_rt_sub_call( $subscription_after_dispatch, 'get_status' );
	$last_renewal_id             = opf_sub_rt_sub_call( $subscription_after_dispatch, 'get_last_order', 'ids', [ 'renewal' ] );

	// `get_last_order( 'ids', ... )` returns a single id for the newest match.
	$renewal_id = is_array( $last_renewal_id ) ? (int) ( $last_renewal_id[0] ?? 0 ) : (int) $last_renewal_id;
	$renewal    = $renewal_id ? wc_get_order( $renewal_id ) : null;
	$created_by = 'woocommerce_scheduled_subscription_payment';

	if ( ! $renewal instanceof \WC_Order ) {
		// Fallback when the scheduled dispatch does not produce one (for example
		// a subscription whose next-payment date is not yet due).
		$created_by = 'wcs_create_renewal_order';
		$renewal    = opf_sub_rt_wcs( 'wcs_create_renewal_order', $subscription_after_dispatch );
	}

	opf_sub_rt_check( 'a renewal order was created', $renewal instanceof \WC_Order, $renewal_id );

	if ( ! $renewal instanceof \WC_Order ) {
		opf_sub_rt_finish( $phase, [ 'renewal_created_by' => $created_by, 'status_on_due' => $status_on_due ] );
		return;
	}

	$renewal_id = $renewal->get_id();
	$items      = opf_sub_rt_order_item_fields( $renewal );
	$line       = $items[0] ?? [];
	$decoded    = json_decode( (string) ( $line['opf_fields'] ?? '' ), true );

	opf_sub_rt_check( 'renewal order detected by WCS', (bool) opf_sub_rt_wcs( 'wcs_order_contains_renewal', $renewal ), $renewal_id );
	opf_sub_rt_check( 'manual subscription goes on hold while the renewal is due', 'on-hold' === $status_on_due, $status_on_due );
	opf_sub_rt_check( 'renewal total is 35 (no compounding on renewal)', abs( (float) $renewal->get_total() - 35.0 ) < 0.000001, $renewal->get_total() );
	opf_sub_rt_check( 'renewal line persisted _opf_fields', isset( $line['opf_fields'] ) && '' !== (string) $line['opf_fields'], $line['opf_fields'] ?? null );
	opf_sub_rt_check(
		'renewal line _opf_fields carries the submitted value',
		is_array( $decoded ) && ( $decoded[ (string) $state['group'] ][ OPF_SUB_RT_FIELD_ID ] ?? null ) === OPF_SUB_RT_FIELD_VALUE,
		$decoded
	);

	// Renewal cart rebuild: WCS restores the order item's data into the cart
	// through `woocommerce_order_again_cart_item_data`, and OPF must skip field
	// validation while that setup runs (WAPF parity).
	$rebuild_failed = null;
	$skipped_during = null;
	$skipped_after  = null;
	$restored       = null;
	try {
		$subscription_for_rebuild = opf_sub_rt_wcs( 'wcs_get_subscription', $subscription_id );
		$item = is_object( $subscription_for_rebuild ) ? array_values( $subscription_for_rebuild->get_items() )[0] ?? null : null;
		$restored = $item ? (array) apply_filters( 'woocommerce_order_again_cart_item_data', [], $item, $subscription_for_rebuild ) : [];

		do_action( 'wcs_before_renewal_setup_cart_subscriptions', [ $subscription_for_rebuild ], $renewal );
		$skipped_during = (bool) apply_filters( 'opf_skip_validation', false );
		do_action( 'wcs_after_renewal_setup_cart_subscriptions', [ $subscription_for_rebuild ], $renewal );
		$skipped_after = (bool) apply_filters( 'opf_skip_validation', false );
	} catch ( \Throwable $e ) {
		$rebuild_failed = $e->getMessage();
	}

	$restored_values = $restored[ \OPF\Service\CartIntegration::ITEM_KEY ] ?? null;
	opf_sub_rt_check( 'renewal cart rebuild did not throw', null === $rebuild_failed, $rebuild_failed );
	opf_sub_rt_check(
		'renewal cart rebuild restores the OPF values',
		is_array( $restored_values ) && ( $restored_values[ (string) $state['group'] ][ OPF_SUB_RT_FIELD_ID ] ?? null ) === OPF_SUB_RT_FIELD_VALUE,
		$restored_values
	);
	opf_sub_rt_check( 'renewal cart setup skips OPF validation', true === $skipped_during, $skipped_during );
	opf_sub_rt_check( 'renewal cart teardown restores validation', false === $skipped_after, $skipped_after );

	// Confirming the offline renewal payment is the customer/store path that
	// reactivates a manual subscription; WCS keeps `next_payment` at its already
	// scheduled date because it is more than the 2-hour activation threshold away.
	$status_before_payment = $renewal->get_status();
	$renewal               = opf_sub_rt_mark_order_paid( $renewal );
	$subscription_after    = opf_sub_rt_wcs( 'wcs_get_subscription', $subscription_id );
	$next_payment_after    = is_object( $subscription_after ) ? (string) opf_sub_rt_sub_call( $subscription_after, 'get_date', 'next_payment' ) : '';
	$status_after_payment  = (string) opf_sub_rt_sub_call( $subscription_after, 'get_status' );

	opf_sub_rt_check( 'renewal order paid', $renewal->is_paid(), $renewal->get_status() );
	opf_sub_rt_check( 'subscription active again after the renewal payment', 'active' === $status_after_payment, $status_after_payment );
	opf_sub_rt_check( 'next payment date still scheduled', '' !== $next_payment_after, $next_payment_after );

	$state['renewal_order'] = $renewal_id;
	opf_sub_rt_state( $state );

	opf_sub_rt_finish(
		$phase,
		[
			'renewal_order_id'      => $renewal_id,
			'renewal_created_by'    => $created_by,
			'renewal_status_before' => $status_before_payment,
			'renewal_status_after'  => $renewal->get_status(),
			'renewal_total'         => (float) $renewal->get_total(),
			'renewal_items'         => $items,
			'subscription_status_before_renewal' => $status_before_renewal,
			'subscription_status_on_due'         => $status_on_due,
			'subscription_status_after_payment'  => $status_after_payment,
			'next_payment_before'   => $next_payment,
			'next_payment_after'    => $next_payment_after,
			'rebuild_error'         => $rebuild_failed,
		]
	);
	return;
}

/* ------------------------------------------------------------ order-again -- */

if ( 'order-again' === $phase ) {
	$order_id = (int) ( $state['order'] ?? 0 );
	$order    = $order_id ? wc_get_order( $order_id ) : null;
	opf_sub_rt_check( 'checkout order resolves', $order instanceof \WC_Order, $order_id );

	if ( ! $order instanceof \WC_Order ) {
		opf_sub_rt_finish( $phase );
		return;
	}

	opf_sub_rt_check( 'order is in a reorderable status', $order->has_status( 'completed' ), $order->get_status() );

	WC()->cart->empty_cart();
	unset( $_POST['opf'] );

	$_GET['order_again'] = (string) $order_id;
	$_GET['_wpnonce']    = wp_create_nonce( 'woocommerce-order_again' );
	$_REQUEST['order_again'] = (string) $order_id;
	$_REQUEST['_wpnonce']    = $_GET['_wpnonce'];

	// Real storefront entry point for "order again": rebuild the cart from the
	// order inside WC_Cart_Session. That method ends with a redirect back to the
	// cart page, so the redirect is turned into an exception; the cart is already
	// populated (and its totals calculated) by the time it fires.
	$redirect = static function ( $location ) {
		throw new \RuntimeException( 'opf_runtime_redirect:' . $location );
	};
	add_filter( 'wp_redirect', $redirect, 0 );
	$order_again_redirected = null;
	try {
		WC()->cart->get_cart_from_session();
	} catch ( \RuntimeException $e ) {
		if ( 0 !== strpos( $e->getMessage(), 'opf_runtime_redirect:' ) ) {
			remove_filter( 'wp_redirect', $redirect, 0 );
			throw $e;
		}
		$order_again_redirected = substr( $e->getMessage(), strlen( 'opf_runtime_redirect:' ) );
	}
	remove_filter( 'wp_redirect', $redirect, 0 );
	WC()->cart->calculate_totals();

	$cart      = WC()->cart->get_cart();
	$item      = $cart ? array_values( $cart )[0] : [];
	$line_key  = $item['key'] ?? '';
	$price     = $item ? (float) $item['data']->get_price( 'edit' ) : 0.0;
	$values    = $item[ \OPF\Service\CartIntegration::ITEM_KEY ] ?? null;
	$decoded   = is_array( $values ) ? $values : null;
	$recurring = opf_sub_rt_recurring_totals();

	opf_sub_rt_check( 'order-again followed the storefront redirect to the cart', null !== $order_again_redirected, $order_again_redirected );
	opf_sub_rt_check( 'order-again rebuilt one cart line', 1 === count( $cart ), array_keys( $cart ) );
	opf_sub_rt_check( 'order-again restored the OPF values', is_array( $decoded ) && ( $decoded[ (string) $state['group'] ][ OPF_SUB_RT_FIELD_ID ] ?? null ) === OPF_SUB_RT_FIELD_VALUE, $decoded );
	opf_sub_rt_check( 'order-again line price is 35', abs( $price - 35.0 ) < 0.000001, $price );
	opf_sub_rt_check( 'order-again stored base is 30', isset( $item['opf_base_price'] ) && abs( (float) $item['opf_base_price'] - 30.0 ) < 0.000001, $item['opf_base_price'] ?? null );
	opf_sub_rt_check( 'order-again recurring total is 35', isset( array_values( $recurring )[0] ) && abs( array_values( $recurring )[0]['total'] - 35.0 ) < 0.000001, $recurring );

	// Re-checkout the reordered cart: the new order must persist the restored
	// values without a fresh form submission.
	$uid              = get_current_user_id();
	$checkout_fields  = [
		'billing_first_name'         => 'OPF',
		'billing_last_name'          => 'Runtime',
		'billing_company'            => '',
		'billing_country'            => 'US',
		'billing_address_1'          => '1 Runtime Street',
		'billing_address_2'          => '',
		'billing_city'               => 'Testville',
		'billing_state'              => 'CA',
		'billing_postcode'           => '90210',
		'billing_phone'              => '5550001111',
		'billing_email'              => OPF_SUB_RT_CUSTOMER,
		'shipping_first_name'        => 'OPF',
		'shipping_last_name'         => 'Runtime',
		'shipping_country'           => 'US',
		'shipping_address_1'         => '1 Runtime Street',
		'shipping_address_2'         => '',
		'shipping_city'              => 'Testville',
		'shipping_state'             => 'CA',
		'shipping_postcode'          => '90210',
		'ship_to_different_address'  => '0',
		'order_comments'             => '',
		'payment_method'             => 'cod',
		'terms'                      => '1',
		'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
	];
	$_POST = $checkout_fields;
	$_REQUEST = $checkout_fields;
	unset( $_GET['order_again'], $_GET['_wpnonce'] );

	$checkout_result = opf_sub_rt_run_checkout();
	$errors          = $checkout_result['errors'];

	$orders = wc_get_orders( [ 'limit' => 1, 'customer_id' => $uid, 'orderby' => 'date', 'order' => 'DESC', 'status' => 'any', 'type' => 'shop_order' ] );
	$second = $orders ? $orders[0] : null;
	$second_items = $second instanceof \WC_Order ? opf_sub_rt_order_item_fields( $second ) : [];
	$second_line  = $second_items[0] ?? [];
	$second_decoded = json_decode( (string) ( $second_line['opf_fields'] ?? '' ), true );

	opf_sub_rt_check( 'order-again checkout raised no errors', [] === $errors, $errors );
	opf_sub_rt_check( 'order-again checkout reached the payment gateway', true === $checkout_result['payment_reached'] );
	opf_sub_rt_check( 'order-again checkout created a new order', $second instanceof \WC_Order && (int) $second->get_id() !== $order_id, $second ? $second->get_id() : null );
	if ( $second instanceof \WC_Order ) {
		$second = opf_sub_rt_mark_order_paid( $second );
		$second_items = opf_sub_rt_order_item_fields( $second );
		$second_line  = $second_items[0] ?? [];
		$second_decoded = json_decode( (string) ( $second_line['opf_fields'] ?? '' ), true );
		opf_sub_rt_check( 'reordered order total is 35', abs( (float) $second->get_total() - 35.0 ) < 0.000001, $second->get_total() );
		opf_sub_rt_check(
			'reordered order line persisted the restored _opf_fields',
			is_array( $second_decoded ) && ( $second_decoded[ (string) $state['group'] ][ OPF_SUB_RT_FIELD_ID ] ?? null ) === OPF_SUB_RT_FIELD_VALUE,
			$second_decoded
		);
		$state['reordered_order'] = $second->get_id();
		opf_sub_rt_state( $state );
	}

	opf_sub_rt_finish(
		$phase,
		[
			'source_order_id'   => $order_id,
			'cart_item_key'     => $line_key,
			'restored_values'   => $decoded,
			'line_price'        => $price,
			'stored_base'       => $item['opf_base_price'] ?? null,
			'recurring_carts'   => $recurring,
			'reordered_order'   => $second ? $second->get_id() : 0,
			'reordered_total'   => $second ? (float) $second->get_total() : null,
			'reordered_items'   => $second_items,
			'redirected_to'     => $order_again_redirected,
			'notices'           => $errors,
		]
	);
	return;
}

/* ----------------------------------------------------------------- refund -- */

if ( 'refund' === $phase ) {
	$order_id = (int) ( $state['order'] ?? 0 );
	$order    = $order_id ? wc_get_order( $order_id ) : null;
	opf_sub_rt_check( 'order resolves', $order instanceof \WC_Order, $order_id );

	if ( ! $order instanceof \WC_Order ) {
		opf_sub_rt_finish( $phase );
		return;
	}

	$before_items = opf_sub_rt_order_item_fields( $order );
	$before_total = (float) $order->get_total();
	$before_sub   = (int) ( $state['subscription'] ?? 0 ) ? opf_sub_rt_wcs( 'wcs_get_subscription', (int) $state['subscription'] ) : null;

	$refund = wc_create_refund(
		[
			'order_id'       => $order_id,
			'amount'         => 10,
			'reason'         => 'OPF runtime partial refund',
			'refund_payment' => false,
		]
	);

	opf_sub_rt_check( 'partial refund created', $refund instanceof \WC_Order_Refund, is_wp_error( $refund ) ? $refund->get_error_message() : gettype( $refund ) );

	if ( $refund instanceof \WC_Order_Refund ) {
		$reloaded       = wc_get_order( $order_id );
		$after_items    = [];
		$refund_total   = 0.0;
		$order_after    = 0.0;
		$sub_status     = null;
		$opfs_survived  = false;
		$lines_survived = false;

		if ( $reloaded instanceof \WC_Order ) {
			$after_items    = opf_sub_rt_order_item_fields( $reloaded );
			$refund_total   = (float) $reloaded->get_total_refunded();
			$order_after    = (float) $reloaded->get_total();
			$after_sub      = (int) ( $state['subscription'] ?? 0 ) ? opf_sub_rt_wcs( 'wcs_get_subscription', (int) $state['subscription'] ) : null;
			$sub_status     = opf_sub_rt_sub_call( $after_sub, 'get_status' );
			$opfs_survived  = ( $after_items[0]['opf_fields'] ?? '' ) === ( $before_items[0]['opf_fields'] ?? '__missing__' );
			$lines_survived = abs( (float) ( $after_items[0]['total'] ?? 0 ) - 35.0 ) < 0.000001;
		}

		opf_sub_rt_check( 'refunded amount is 10', abs( $refund_total - 10.0 ) < 0.000001, $refund_total );
		opf_sub_rt_check( 'order total unchanged by a partial refund', abs( $order_after - $before_total ) < 0.000001, $order_after );
		opf_sub_rt_check(
			'order line _opf_fields survives the refund',
			$opfs_survived,
			[ $before_items[0]['opf_fields'] ?? null, $after_items[0]['opf_fields'] ?? null ]
		);
		opf_sub_rt_check( 'order line total still 35', $lines_survived, $after_items[0]['total'] ?? null );
		opf_sub_rt_check( 'subscription survives the refund', 'active' === $sub_status, $sub_status );
		opf_sub_rt_check( 'subscription resolves before the refund', is_object( $before_sub ), is_object( $before_sub ) ? get_class( $before_sub ) : null );

		$state['refund'] = $refund->get_id();
		opf_sub_rt_state( $state );

		opf_sub_rt_finish(
			$phase,
			[
				'refund_id'        => $refund->get_id(),
				'refund_amount'    => (float) $refund->get_amount(),
				'refunded_total'   => $refund_total,
				'order_total'      => $order_after,
				'order_items_before' => $before_items,
				'order_items_after'  => $after_items,
				'subscription_status' => $sub_status,
			]
		);
		return;
	}

	opf_sub_rt_finish( $phase, [ 'refund_error' => is_wp_error( $refund ) ? $refund->get_error_message() : null ] );
	return;
}

/* ---------------------------------------------------------------- cleanup -- */

if ( 'cleanup' === $phase ) {
	$removed = opf_sub_rt_reset();
	delete_option( OPF_SUB_RT_STATE_OPTION );
	opf_sub_rt_finish( $phase, [ 'removed' => $removed ] );
	return;
}

throw new RuntimeException( 'Unknown phase: ' . $phase );
