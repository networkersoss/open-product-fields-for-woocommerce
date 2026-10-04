<?php
/**
 * Disposable real proof for WAPF-PRODUCT-SUBSCRIPTION parity.
 *
 * WooCommerce Subscriptions is commercial and not installed, so this fixture
 * installs a scoped mu-plugin that mirrors its product-type surface:
 *
 *  - `subscription` / `variable-subscription` / `subscription_variation`
 *    product classes registered through `woocommerce_product_class`;
 *  - `WC_Subscriptions_Product::get_price()` returning the recurring price
 *    WAPF's adapter uses as the addon base;
 *  - the `wcs_before_*` / `wcs_after_*` renewal-setup hooks fired around a
 *    fieldless renewal-style add.
 *
 * Phases: setup, assert, cleanup. Guarded to a /tmp WordPress clone and the
 * explicit OPF_SUB_E2E_ALLOW=1 token.
 *
 * Bounds (documented honestly): the stub mirrors the public product-type API
 * the adapter touches, not real Subscriptions renewal/period internals. The
 * real plugin's sign-up-fee line and period labels are product-level concerns
 * handled by Subscriptions itself and are not exercised here.
 */
if ( '1' !== getenv( 'OPF_SUB_E2E_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Explicit disposable /tmp authorization required.' );
}

function sub_check( string $name, bool $pass ): void {
	if ( ! $pass ) {
		throw new RuntimeException( $name );
	}
	echo "ok $name\n";
}

$phase     = getenv( 'OPF_SUB_E2E_PHASE' ) ?: 'assert';
$state     = get_option( 'opf_sub_e2e_state', [] );
$artifacts = getenv( 'OPF_SUB_ARTIFACT_DIR' ) ?: '/tmp/opf-lane-prodtypes-evidence/subscription';
$state_file = $artifacts . '/state.json';
$stub_file  = trailingslashit( WPMU_PLUGIN_DIR ) . 'zz-opf-sub-stub.php';

$stub_source = <<<'PHP'
<?php
/**
 * Scoped WooCommerce Subscriptions product-type stub for the OPF prodtypes lane.
 * Removed by the fixture cleanup phase.
 */
if ( ! class_exists( 'WC_Subscriptions_Product', false ) ) {
	class WC_Subscriptions_Product {
		public static function get_price( $product ) {
			if ( is_numeric( $product ) ) {
				$product = wc_get_product( $product );
			}
			if ( ! is_object( $product ) ) {
				return 0.0;
			}
			// Distinguishable recurring bases per type prove the adapter calls
			// through to the subscription API for both simple and variation.
			switch ( $product->get_type() ) {
				case 'subscription':
					return 42.5;
				case 'variable-subscription':
				case 'subscription_variation':
					return 100.0;
			}
			return (float) $product->get_price();
		}
	}
}

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return;
		}
		if ( ! class_exists( 'OPF_Stub_Product_Subscription', false ) ) {
			class OPF_Stub_Product_Subscription extends WC_Product_Simple {
				public function get_type() {
					return 'subscription';
				}
				public function is_type( $type ) {
					return in_array( $type, [ 'simple', 'subscription' ], true ) || parent::is_type( $type );
				}
			}
			class OPF_Stub_Product_Variable_Subscription extends WC_Product_Variable {
				public function get_type() {
					return 'variable-subscription';
				}
				public function is_type( $type ) {
					return in_array( $type, [ 'variable', 'variable-subscription' ], true ) || parent::is_type( $type );
				}
			}
			class OPF_Stub_Product_Subscription_Variation extends WC_Product_Variation {
				public function get_type() {
					return 'subscription_variation';
				}
				public function is_type( $type ) {
					return in_array( $type, [ 'variation', 'subscription_variation' ], true ) || parent::is_type( $type );
				}
			}
		}
	},
	20
);

// Route the mirrored types to the matching WooCommerce data stores: the base
// WC_Product constructor resolves `WC_Data_Store::load( 'product-' . get_type() )`,
// so an unmapped custom type silently falls back to the generic CPT store and
// breaks variation attribute persistence.
add_filter(
	'woocommerce_data_stores',
	static function ( $stores ) {
		$stores['product-subscription']            = 'WC_Product_Data_Store_CPT';
		$stores['product-variable-subscription']   = 'WC_Product_Variable_Data_Store_CPT';
		$stores['product-subscription_variation']  = 'WC_Product_Variation_Data_Store_CPT';
		return $stores;
	}
);

add_filter(
	'woocommerce_product_class',
	static function ( $classname, $product_type, $post_type = '', $product_id = 0 ) {
		switch ( $product_type ) {
			case 'subscription':
				return class_exists( 'OPF_Stub_Product_Subscription', false ) ? 'OPF_Stub_Product_Subscription' : $classname;
			case 'variable-subscription':
				return class_exists( 'OPF_Stub_Product_Variable_Subscription', false ) ? 'OPF_Stub_Product_Variable_Subscription' : $classname;
			case 'subscription_variation':
				return class_exists( 'OPF_Stub_Product_Subscription_Variation', false ) ? 'OPF_Stub_Product_Subscription_Variation' : $classname;
		}
		// Variations store an empty product_type term; classify them by parent.
		if ( 'product_variation' === $post_type && $product_id ) {
			$parent_id = (int) wp_get_post_parent_id( (int) $product_id );
			$parent_terms = $parent_id ? wp_get_object_terms( $parent_id, 'product_type', [ 'fields' => 'slugs' ] ) : [];
			if ( is_array( $parent_terms ) && in_array( 'variable-subscription', $parent_terms, true ) && class_exists( 'OPF_Stub_Product_Subscription_Variation', false ) ) {
				return 'OPF_Stub_Product_Subscription_Variation';
			}
		}
		return $classname;
	},
	10,
	4
);
PHP;

if ( 'setup' === $phase ) {
	sub_check( 'fixture is new', empty( $state ) );
	$state['baseline'] = [
		'products'   => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product'" ),
		'variations' => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product_variation'" ),
		'groups'     => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='opf_field_group'" ),
		'orders'     => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'stub'       => file_exists( $stub_file ) ? 'present' : 'absent',
	];

	// Write the stub, then load its global API now. The mu-plugin's
	// `plugins_loaded` hook has already fired in this process, so declare the
	// WC product classes here for setup; later phases auto-load them from the
	// mu-plugin.
	file_put_contents( $stub_file, $stub_source );
	require $stub_file;
	if ( ! class_exists( 'OPF_Stub_Product_Subscription', false ) ) {
		class OPF_Stub_Product_Subscription extends WC_Product_Simple {
			public function get_type() {
				return 'subscription';
			}
			public function is_type( $type ) {
				return in_array( $type, [ 'simple', 'subscription' ], true ) || parent::is_type( $type );
			}
		}
		class OPF_Stub_Product_Variable_Subscription extends WC_Product_Variable {
			public function get_type() {
				return 'variable-subscription';
			}
			public function is_type( $type ) {
				return in_array( $type, [ 'variable', 'variable-subscription' ], true ) || parent::is_type( $type );
			}
		}
		class OPF_Stub_Product_Subscription_Variation extends WC_Product_Variation {
			public function get_type() {
				return 'subscription_variation';
			}
			public function is_type( $type ) {
				return in_array( $type, [ 'variation', 'subscription_variation' ], true ) || parent::is_type( $type );
			}
		}
	}
	sub_check( 'stub product classes available', class_exists( 'OPF_Stub_Product_Subscription', false ) && class_exists( 'WC_Subscriptions_Product' ) );

	// Simple subscription product.
	$simple = new OPF_Stub_Product_Subscription();
	$simple->set_name( 'OPF Stub Subscription' );
	$simple->set_slug( 'opf-stub-subscription' );
	$simple->set_status( 'publish' );
	$simple->set_catalog_visibility( 'visible' );
	$simple->set_regular_price( '30' );
	$simple->set_virtual( true );
	$simple->set_manage_stock( false );
	$state['sub'] = (int) $simple->save();
	sub_check( 'subscription product created', $state['sub'] > 0 );

	// Variable subscription with two variations.
	$parent = new OPF_Stub_Product_Variable_Subscription();
	$parent->set_name( 'OPF Stub Variable Subscription' );
	$parent->set_slug( 'opf-stub-variable-subscription' );
	$parent->set_status( 'publish' );
	$parent->set_catalog_visibility( 'visible' );
	$parent_id = (int) $parent->save();
	$state['vparent'] = $parent_id;
	sub_check( 'variable-subscription product created', $parent_id > 0 );

	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'subscription_term' );
	$attribute->set_options( [ 'monthly', 'yearly' ] );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$parent->set_attributes( [ 'subscription_term' => $attribute ] );
	$parent->save();

	$make_variation = static function ( string $value, string $price ) use ( $parent_id ) {
		$variation = new OPF_Stub_Product_Subscription_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );
		$variation->set_virtual( true );
		$variation->set_regular_price( $price );
		$variation->set_attributes( [ 'subscription_term' => $value ] );
		return (int) $variation->save();
	};
	$state['var_monthly'] = $make_variation( 'monthly', '20' );
	$state['var_yearly']  = $make_variation( 'yearly', '200' );
	WC_Product_Variable::sync( $parent_id );
	sub_check( 'subscription variations created', $state['var_monthly'] > 0 && $state['var_yearly'] > 0 );

	// Group targeted at subscription product types with a required field that
	// carries +5 fixed pricing, so base + addon is observable.
	$state['group'] = OPF\Service\FieldGroups::save(
		0,
		[
			'fields'      => [ [
				'id'       => 'sub_note',
				'label'    => 'Subscription note',
				'type'     => 'text',
				'required' => true,
				'pricing'  => [ 'type' => 'fixed', 'amount' => 5, 'formula' => '', 'per_unit' => true ],
			] ],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_type', 'operator' => 'in', 'terms' => [ 'subscription', 'variable-subscription' ] ] ] ] ],
		],
		[ 'title' => 'OPF stub subscription group', 'status' => 'publish' ]
	);
	sub_check( 'subscription group saved', $state['group'] > 0 );

	if ( ! is_dir( $artifacts ) ) {
		mkdir( $artifacts, 0755, true );
	}
	update_option( 'opf_sub_e2e_state', $state );
	file_put_contents( $state_file, wp_json_encode( $state, JSON_PRETTY_PRINT ) );
	echo "SUCCESS subscription setup\n";
	return;
}

sub_check( 'fixture exists', ! empty( $state['group'] ) && ! empty( $state['sub'] ) );

if ( 'assert' === $phase ) {
	$sub    = wc_get_product( (int) $state['sub'] );
	$vparent = wc_get_product( (int) $state['vparent'] );
	$result = [ 'checks' => [] ];
	$record = static function ( string $name, bool $pass, $detail = null ) use ( &$result ) {
		sub_check( $name, $pass );
		$result['checks'][] = [ 'name' => $name, 'pass' => $pass, 'detail' => $detail ];
	};

	$record( 'simple subscription resolves through the WC factory', $sub instanceof WC_Product && 'subscription' === $sub->get_type(), $sub ? $sub->get_type() : null );
	$record( 'variable-subscription resolves through the WC factory', $vparent instanceof WC_Product && 'variable-subscription' === $vparent->get_type(), $vparent ? $vparent->get_type() : null );

	// Placement: product_type rule matches subscription types.
	OPF\Service\FieldGroups::flush_cache();
	$matched = array_map( static fn( $e ) => (int) $e['id'], OPF\Service\FieldGroups::for_product( $sub ) );
	$record( 'subscription matches product_type placement', in_array( (int) $state['group'], $matched, true ), $matched );

	// Baseline: required field is enforced without renewal context.
	WC()->cart->empty_cart();
	unset( $_POST['opf'] );
	$baseline = apply_filters( 'woocommerce_add_to_cart_validation', true, (int) $state['sub'], 1 );
	$record( 'fieldless add is blocked outside renewal', false === $baseline, $baseline );

	// Renewal context: WAPF toggles skip_cart_validation on the wcs hooks.
	$record( 'opf_skip_validation false before renewal', false === apply_filters( 'opf_skip_validation', false ) );
	do_action( 'wcs_before_renewal_setup_cart_subscriptions', null, null );
	$record( 'renewal setup sets skip', true === apply_filters( 'opf_skip_validation', false ) );
	$renewal = apply_filters( 'woocommerce_add_to_cart_validation', true, (int) $state['sub'], 1 );
	$record( 'fieldless renewal add passes during setup', true === $renewal, $renewal );
	do_action( 'wcs_after_renewal_setup_cart_subscriptions', null, null );
	$record( 'renewal teardown clears skip', false === apply_filters( 'opf_skip_validation', false ) );

	// Price bridge: subscription base = WC_Subscriptions_Product::get_price().
	$_POST['opf'] = [ (string) $state['group'] => [ 'sub_note' => 'monthly plan' ] ];
	$valid = apply_filters( 'woocommerce_add_to_cart_validation', true, (int) $state['sub'], 1 );
	$record( 'validated subscription add passes', true === $valid, $valid );
	$key = WC()->cart->add_to_cart( (int) $state['sub'], 1 );
	$record( 'subscription added to cart', is_string( $key ) && '' !== $key, $key );
	WC()->cart->calculate_totals();
	$line = WC()->cart->get_cart_item( $key );
	$line_price = $line ? (float) $line['data']->get_price( 'edit' ) : 0.0;
	$record( 'subscription cart price = recurring base 42.5 + addon 5', abs( $line_price - 47.5 ) < 0.000001, $line_price );
	WC()->cart->calculate_totals();
	$record( 'subscription repeat totals do not compound addons', 47.5 === (float) WC()->cart->get_cart_item( $key )['data']->get_price( 'edit' ) );

	// Variation base resolves through the subscription API too.
	WC()->cart->empty_cart();
	$_POST['opf'] = [ (string) $state['group'] => [ 'sub_note' => 'yearly plan' ] ];
	$_POST['variation_id'] = (string) $state['var_yearly'];
	$_POST['attribute_subscription_term'] = 'yearly';
	$vvalid = apply_filters( 'woocommerce_add_to_cart_validation', true, (int) $state['vparent'], 1, (int) $state['var_yearly'], [ 'subscription_term' => 'yearly' ] );
	$record( 'validated variable-subscription variation add passes', true === $vvalid, $vvalid );
	$vkey = WC()->cart->add_to_cart( (int) $state['vparent'], 1, (int) $state['var_yearly'], [ 'subscription_term' => 'yearly' ] );
	$record( 'variable-subscription variation added to cart', is_string( $vkey ) && '' !== $vkey, $vkey );
	WC()->cart->calculate_totals();
	$vline = WC()->cart->get_cart_item( $vkey );
	$vline_price = $vline ? (float) $vline['data']->get_price( 'edit' ) : 0.0;
	$record( 'variation cart price = subscription variation base 100 + addon 5', abs( $vline_price - 105.0 ) < 0.000001, $vline_price );
	WC()->cart->calculate_totals();
	$record( 'variation repeat totals do not compound addons', 105.0 === (float) WC()->cart->get_cart_item( $vkey )['data']->get_price( 'edit' ) );

	WC()->cart->empty_cart();
	unset( $_POST['opf'], $_POST['variation_id'], $_POST['attribute_subscription_term'] );
	file_put_contents( $artifacts . '/server-results.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	echo "SUCCESS subscription assert\n";
	return;
}

if ( 'cleanup' === $phase ) {
	WC()->cart->empty_cart();
	foreach ( [ 'sub', 'vparent', 'var_monthly', 'var_yearly' ] as $key ) {
		if ( ! empty( $state[ $key ] ) ) {
			wp_delete_post( (int) $state[ $key ], true );
		}
	}
	if ( ! empty( $state['group'] ) ) {
		wp_delete_post( (int) $state['group'], true );
	}
	OPF\Service\FieldGroups::flush_cache();
	if ( 'absent' === ( $state['baseline']['stub'] ?? 'absent' ) && file_exists( $stub_file ) ) {
		unlink( $stub_file );
	}
	delete_option( 'opf_sub_e2e_state' );

	$after = [
		'products'   => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product'" ),
		'variations' => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='product_variation'" ),
		'groups'     => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type='opf_field_group'" ),
		'orders'     => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'stub'       => file_exists( $stub_file ) ? 'present' : 'absent',
	];
	foreach ( $state['baseline'] as $key => $value ) {
		sub_check( "baseline restored: $key", $after[ $key ] === $value );
	}
	file_put_contents( $artifacts . '/cleanup.json', wp_json_encode( [ 'baseline' => $state['baseline'], 'after' => $after ], JSON_PRETTY_PRINT ) );
	echo "SUCCESS subscription cleanup\n";
	return;
}

throw new RuntimeException( 'Unknown phase: ' . $phase );
