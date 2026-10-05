<?php
/**
 * Formula-advanced commerce lane fixture (CURCY multi-currency + taxes +
 * built-in gateway checkout). Never runs on production.
 *
 * Modes: setup | cleanup. Artifacts are written to $OPF_FORMULAADV_OUT.
 */

use OPF\Service\FieldGroups;

defined( 'ABSPATH' ) || exit;

/*
 * Analyzer-only declarations. This fixture runs inside a bootstrapped
 * WordPress through `wp eval-file`; the engine-only PHP analyzer does not
 * load WordPress. Every guard below is false at runtime, so nothing here
 * defines, shadows, or replaces real WordPress / WooCommerce code.
 */
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) { return true; }
	function delete_option( $name ) { return true; }
	function get_post( $post = null ) { return null; }
	function wp_update_post( $postarr = [], $wp_error = false ) { return 0; }
	function wp_delete_post( $post_id = 0, $force_delete = false ) { return false; }
	function get_post_field( $field, $post = null, $context = 'display' ) { return ''; }
	function wp_create_user( $username, $password = '', $email = '' ) { return 0; }
	function wp_delete_user( $id, $reassign = null ) { return false; }
	function is_wp_error( $thing ) { return false; }
	function home_url( $path = '', $scheme = null ) { return ''; }
	function get_bloginfo( $show = '', $filter = 'raw' ) { return ''; }
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
	function wc_get_orders( $args = [] ) { return []; }
	function wc_get_page_id( $page ) { return 0; }
	function get_plugin_data( $plugin_file, $markup = true, $translate = true ) { return []; }
	function deactivate_plugins( $plugins, $silent = false, $network_wide = false ) { return null; }
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '' );
}
if ( ! defined( 'WC_VERSION' ) ) {
	define( 'WC_VERSION', '' );
}
if ( ! class_exists( 'WC_Tax' ) ) {
	class WC_Tax {
		public static function _insert_tax_rate( $tax_rate ) { return 0; }
		public static function _delete_tax_rate( $tax_rate_id ) { return false; }
	}
}
if ( ! class_exists( 'WC_Product_Simple' ) ) {
	class WC_Product_Simple {
		public function set_name( $name ) { return $this; }
		public function set_regular_price( $price ) { return $this; }
		public function set_virtual( $virtual ) { return $this; }
		public function set_tax_class( $tax_class ) { return $this; }
		public function set_status( $status ) { return $this; }
		public function save() { return 0; }
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function get_error_message() { return ''; }
	}
}

if ( '1' !== getenv( 'OPF_FORMULAADV_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-lane-formulaadv-wp' || ! defined( 'FQDB' ) || realpath( FQDB ) !== realpath( ABSPATH ) . '/wp-content/database/.ht.sqlite' ) {
	throw new RuntimeException( 'Owned formulaadv SQLite clone and explicit opt-in required.' );
}

$key = 'opf_formulaadv_fixture';
$state = get_option( $key, [] );
$mode = getenv( 'OPF_FORMULAADV_MODE' ) ?: 'setup';
$out = getenv( 'OPF_FORMULAADV_OUT' );
if ( ! $out || ! is_dir( $out ) ) {
	throw new RuntimeException( 'Existing artifact directory required.' );
}
$write = static function ( $name, $data ) use ( $out ) {
	file_put_contents( $out . '/' . $name, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
};

if ( 'cleanup' === $mode ) {
	if ( ! $state ) {
		throw new RuntimeException( 'Missing owned fixture.' );
	}
	foreach ( $state['statuses'] ?? [] as $pid => $status ) {
		if ( get_post( (int) $pid ) ) {
			wp_update_post( [ 'ID' => (int) $pid, 'post_status' => $status ] );
		}
	}
	foreach ( wc_get_orders( [ 'limit' => -1, 'return' => 'objects' ] ) as $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( in_array( (int) $item->get_product_id(), $state['products'], true ) ) {
				$order->delete( true );
				break;
			}
		}
	}
	foreach ( array_reverse( $state['owned'] ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $state['pages'] ?? [] as $pid => $content ) {
		if ( get_post( (int) $pid ) ) {
			wp_update_post( [ 'ID' => (int) $pid, 'post_content' => $content ] );
		}
	}
	if ( ! empty( $state['user'] ) ) {
		wp_delete_user( $state['user'] );
	}
	// Tax rates inserted by this fixture.
	foreach ( $state['tax_rates'] ?? [] as $rate_id ) {
		if ( class_exists( 'WC_Tax' ) ) {
			WC_Tax::_delete_tax_rate( (int) $rate_id );
		}
	}
	foreach ( $state['options'] as $name => $entry ) {
		if ( $entry['exists'] ) {
			update_option( $name, $entry['value'] );
		} else {
			delete_option( $name );
		}
	}
	delete_option( $key );
	// Remove the CURCY clone copy (deactivate first, then delete the folder).
	$curcy = WP_PLUGIN_DIR . '/woocommerce-multi-currency/woocommerce-multi-currency.php';
	$curcy_removed = false;
	if ( file_exists( $curcy ) ) {
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( 'woocommerce-multi-currency/woocommerce-multi-currency.php' );
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_PLUGIN_DIR . '/woocommerce-multi-currency', FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $entry ) {
			if ( $entry->isDir() ) {
				@rmdir( $entry->getPathname() );
			} else {
				@unlink( $entry->getPathname() );
			}
		}
		$curcy_removed = @rmdir( WP_PLUGIN_DIR . '/woocommerce-multi-currency' );
	}
	if ( class_exists( \OPF\Service\FieldGroups::class ) ) {
		FieldGroups::flush_cache();
	}
	$write( 'cleanup.json', [
		'owned' => $state['owned'],
		'remaining_products' => array_values( array_filter( $state['products'], 'get_post' ) ),
		'remaining_groups' => array_values( array_filter( $state['groups'], 'get_post' ) ),
		'restored_options' => array_keys( $state['options'] ),
		'restored_pages' => array_map( 'intval', array_keys( $state['pages'] ?? [] ) ),
		'deleted_tax_rates' => $state['tax_rates'] ?? [],
		'curcy_removed' => $curcy_removed,
		'remaining_wc_orders' => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'active_plugins' => get_option( 'active_plugins' ),
	] );
	echo 'Cleaned owned products/groups/orders/user/tax rates and restored clone options.';
	return;
}

if ( 'setup' !== $mode || $state ) {
	throw new RuntimeException( 'Setup only once.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$reference = WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce-extended/';
if ( '3.1.5' !== get_plugin_data( $reference . 'advanced-product-fields-for-woocommerce-extended.php' )['Version'] ) {
	throw new RuntimeException( 'Actual Extended 3.1.5 required.' );
}
if ( ! file_exists( WP_PLUGIN_DIR . '/woocommerce-multi-currency/woocommerce-multi-currency.php' ) ) {
	throw new RuntimeException( 'CURCY 2.4.3 required.' );
}

$state = [ 'owned' => [], 'options' => [], 'products' => [], 'groups' => [], 'pages' => [], 'tax_rates' => [], 'user' => 0 ];
$snapshot = [
	'active_plugins', 'opf_admin_only', 'opf_show_totals', 'woocommerce_calc_taxes',
	'woocommerce_enable_guest_checkout', 'woocommerce_default_country', 'woocommerce_currency',
	'woocommerce_cod_settings', 'woocommerce_cheque_settings', 'woocommerce_prices_include_tax',
	'woocommerce_tax_display_cart', 'woocommerce_tax_display_shop', 'wapf_datepicker',
	'woo_multi_currency_params', 'woocommerce_tax_classes', 'woocommerce_currency_pos',
	'woocommerce_coming_soon', 'woocommerce_store_pages_only',
];
foreach ( $snapshot as $name ) {
	$value = get_option( $name, '__opf_formulaadv_missing__' );
	$state['options'][ $name ] = [ 'exists' => '__opf_formulaadv_missing__' !== $value, 'value' => $value ];
}
update_option( $key, $state );

$own = static function ( $id ) use ( &$state, $key ) {
	if ( ! $id || is_wp_error( $id ) ) {
		throw new RuntimeException( 'Fixture insert failed.' );
	}
	$state['owned'][] = (int) $id;
	update_option( $key, $state );
	return (int) $id;
};

// --- Store / tax configuration -------------------------------------------------
update_option( 'opf_admin_only', 'no' );
update_option( 'opf_show_totals', 'yes' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_display_cart', 'excl' );
update_option( 'woocommerce_tax_display_shop', 'excl' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_cod_settings', [ 'enabled' => 'yes', 'title' => 'Cash on delivery (proof)', 'enable_for_virtual' => 'yes' ] );
update_option( 'woocommerce_cheque_settings', [ 'enabled' => 'yes', 'title' => 'Cheque (proof)' ] );
update_option( 'wapf_datepicker', 'yes' );
// Tax classes: standard (default), reduced-rate, zero-rate already exist by default.
foreach ( [ 'Reduced rate', 'Zero rate' ] as $class_name ) {
	// no-op: classes are created by WooCommerce defaults; kept explicit for docs.
}
$state['options']['woocommerce_tax_classes'] = [
	'exists' => true,
	'value'  => get_option( 'woocommerce_tax_classes', "Reduced rate\nZero rate" ),
];

$tax_rates = [
	[ 'tax_rate' => '10.0000', 'tax_rate_name' => 'VAT 10', 'tax_rate_class' => '', 'tax_rate_priority' => 1, 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0 ],
	[ 'tax_rate' => '5.0000', 'tax_rate_name' => 'VAT 5', 'tax_rate_class' => 'reduced-rate', 'tax_rate_priority' => 1, 'tax_rate_country' => '', 'tax_rate_state' => '', 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0 ],
];
foreach ( $tax_rates as $rate ) {
	$rate_id = WC_Tax::_insert_tax_rate( $rate );
	if ( $rate_id ) {
		$state['tax_rates'][] = (int) $rate_id;
	}
}
update_option( $key, $state );

// --- CURCY multi-currency configuration (base USD, EUR at rate 1.5) -----------
$curcy = [
	'enable'               => 1,
	'enable_multi_payment' => 1,
	'currency_default'     => 'USD',
	'checkout_currency'    => 'USD',
	'checkout_currency_args' => [ 'USD', 'EUR' ],
	'currency'             => [ 'USD', 'EUR' ],
	'currency_rate'        => [ '1', '1.5' ],
	'currency_rate_fee'    => [ 0, 0 ],
	'currency_rate_fee_type' => [ 'fixed', 'fixed' ],
	'currency_decimals'    => [ 2, 2 ],
	'currency_pos'         => [ 'left', 'left' ],
	'currency_custom'      => [ '', '' ],
	'currency_thousand_separator' => [ ',', ',' ],
	'currency_decimal_separator'  => [ '.', '.' ],
	'currency_flag'        => [ '', '' ],
	'currency_hidden'      => [ 0, 0 ],
	'enable_fixed_price'   => 0,
	'ignore_exchange_rate' => 0,
	'currency_core'        => 'USD',
	'decimals_core'        => 2,
];
update_option( 'woo_multi_currency_params', $curcy );
$state['options']['woo_multi_currency_params'] = [
	'exists' => false,
	'value'  => '',
];
update_option( $key, $state );

// --- Classic shortcode cart/checkout for the real checkout leg ----------------
$state['pages'] = $state['pages'] ?? [];
foreach ( [ 'cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]' ] as $page_key => $shortcode ) {
	$page_id = (int) wc_get_page_id( $page_key );
	if ( $page_id > 0 && ! isset( $state['pages'][ $page_id ] ) ) {
		$state['pages'][ $page_id ] = get_post_field( 'post_content', $page_id );
		wp_update_post( [ 'ID' => $page_id, 'post_content' => $shortcode ] );
	}
}
update_option( $key, $state );

// --- Products, OPF groups and WAPF reference meta ------------------------------
$plain = [ 'type' => 'none', 'amount' => 0, 'formula' => '' ];

// base USD 10 each, qty 1. expected_unit_eur = (10 + addon) * 1.5
$cases = [
	[
		'id' => 'arith', 'tax_class' => '', 'addon' => 2, 'expected_unit_eur' => 18.00, 'tax_rate' => 10,
		'formula' => 'round(sqrt(pow([field.x];2)))*[qty]',
		'inputs' => [ [ 'id' => 'x', 'label' => 'x', 'type' => 'text' ] ],
		'values' => [ 'x' => '2' ],
		'wapf_values' => [ 'field_x' => '2' ],
	],
	[
		'id' => 'minmax', 'tax_class' => 'reduced-rate', 'addon' => 5, 'expected_unit_eur' => 22.50, 'tax_rate' => 5,
		'formula' => 'max(3;min([field.x];7))*[qty]',
		'inputs' => [ [ 'id' => 'x', 'label' => 'x', 'type' => 'text' ] ],
		'values' => [ 'x' => '5' ],
		'wapf_values' => [ 'field_x' => '5' ],
	],
	[
		'id' => 'len', 'tax_class' => 'zero-rate', 'addon' => 2, 'expected_unit_eur' => 18.00, 'tax_rate' => 0,
		'formula' => 'len([field.t];true)*[qty]',
		'inputs' => [ [ 'id' => 't', 'label' => 't', 'type' => 'text' ] ],
		'values' => [ 't' => '  a b ' ],
		'wapf_values' => [ 'field_t' => '  a b ' ],
	],
	[
		'id' => 'date', 'tax_class' => '', 'addon' => 42, 'expected_unit_eur' => 78.00, 'tax_rate' => 10,
		'formula' => 'dow([field.d])*7*[qty]',
		'inputs' => [ [ 'id' => 'd', 'label' => 'd', 'type' => 'date' ] ],
		'values' => [ 'd' => '2026-10-03' ],
		'wapf_values' => [ 'field_d' => '10-03-2026' ],
	],
	[
		'id' => 'sumqty', 'tax_class' => 'reduced-rate', 'addon' => 5, 'expected_unit_eur' => 22.50, 'tax_rate' => 5,
		'formula' => 'sumQty(prints)*[qty]',
		'inputs' => [ [ 'id' => 'prints', 'label' => 'prints', 'type' => 'image_quantity', 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'min' => 1, 'max' => 12 ] ], [ 'slug' => 'ash', 'label' => 'Ash', 'quantity' => [ 'min' => 0, 'max' => 12 ] ] ] ] ],
		'values' => [ 'prints' => [ 'oak' => 2, 'ash' => 3 ] ],
		'wapf_values' => [ 'field_prints_oak' => '2', 'field_prints_ash' => '3' ],
	],
	[
		'id' => 'customvar', 'tax_class' => 'zero-rate', 'addon' => 4, 'expected_unit_eur' => 21.00, 'tax_rate' => 0,
		'formula' => '[var_rate]*[qty]',
		'variables' => [ [ 'name' => 'rate', 'default' => '4', 'rules' => [] ] ],
		'inputs' => [ [ 'id' => 'note', 'label' => 'note', 'type' => 'text' ] ],
		'values' => [ 'note' => 'ok' ],
		'wapf_values' => [ 'field_note' => 'ok' ],
	],
];

foreach ( $cases as $case ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Disposable FormulaAdv ' . $case['id'] );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->set_tax_class( $case['tax_class'] );
	$product->set_status( 'publish' );
	$state['products'][ $case['id'] ] = $own( $product->save() );
	update_option( $key, $state );

	$opf_fields = [];
	foreach ( $case['inputs'] as $field ) {
		$opf_field = [ 'id' => $field['id'], 'label' => $field['label'], 'type' => $field['type'], 'pricing' => $plain ];
		if ( ! empty( $field['choices'] ) ) {
			$opf_field['choices'] = array_map( static function ( $c ) use ( $plain ) {
				return [ 'slug' => $c['slug'], 'label' => $c['label'], 'pricing' => $plain, 'quantity' => $c['quantity'] ];
			}, $field['choices'] );
		}
		if ( 'image_quantity' === $field['type'] ) {
			$opf_field['min_choices'] = 1;
			$opf_field['max_choices'] = 8;
		}
		$opf_fields[] = $opf_field;
	}
	$opf_fields[] = [
		'id' => 'fee', 'label' => 'fee', 'type' => 'select', 'required' => true,
		'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => $case['formula'], 'per_unit' => true ] ] ],
		'pricing' => $plain,
	];
	$group = [
		'fields' => $opf_fields,
		'variables' => $case['variables'] ?? [],
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $state['products'][ $case['id'] ] ] ] ] ] ],
	];
	$state['groups'][ $case['id'] ] = $own( FieldGroups::save( 0, $group, [ 'title' => 'FormulaAdv ' . $case['id'], 'status' => 'publish' ] ) );
	update_option( $key, $state );

	$wapf_fields = [];
	foreach ( $case['inputs'] as $field ) {
		$wapf_type = 'image_quantity' === $field['type'] ? 'image-swatch-qty' : $field['type'];
		$wapf_field = [ 'id' => $field['id'], 'label' => $field['label'], 'type' => $wapf_type, 'required' => false, 'options' => [] ];
		if ( ! empty( $field['choices'] ) ) {
			$wapf_field['choices'] = array_map( static function ( $c ) {
				return [ 'slug' => $c['slug'], 'label' => $c['label'], 'options' => [ 'min' => (string) $c['quantity']['min'], 'max' => (string) $c['quantity']['max'] ] ];
			}, $field['choices'] );
		}
		$wapf_fields[] = $wapf_field;
	}
	$wapf_fields[] = [ 'id' => 'fee', 'label' => 'fee', 'type' => 'select', 'required' => true, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing_type' => 'fx', 'pricing_amount' => '(' . $case['formula'] . ')' ] ] ];
	$raw = [ 'id' => 'p_' . $state['products'][ $case['id'] ], 'type' => 'wapf_product', 'fields' => $wapf_fields, 'conditions' => [], 'layout' => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ], 'variables' => $case['variables'] ?? [] ];
	$wapf_class = 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups';
	$model = call_user_func( [ $wapf_class, 'raw_json_to_field_group' ], $raw );
	update_post_meta( $state['products'][ $case['id'] ], '_wapf_fieldgroup', $model->to_array() );
}

// --- Customer used for checkout + order-again ---------------------------------
$uid = wp_create_user( 'formulaadv-proof', 'formulaadv-proof-pass-123', 'formulaadv-proof@example.test' );
if ( is_wp_error( $uid ) ) {
	/** @var \WP_Error $uid_error */
	$uid_error = $uid;
	throw new RuntimeException( 'Customer create failed: ' . $uid_error->get_error_message() );
}
$state['user'] = (int) $uid;
update_option( $key, $state );

FieldGroups::flush_cache();
$state['cases'] = array_map( static function ( $c ) {
	unset( $c['inputs'] );
	return $c;
}, $cases );
$state['runtime'] = [
	'base' => home_url(),
	'clone' => ABSPATH,
	'database' => FQDB,
	'wordpress' => get_bloginfo( 'version' ),
	'woocommerce' => WC_VERSION,
	'php' => PHP_VERSION,
	'extended' => '3.1.5',
	'curcy' => '2.4.3',
	'opf' => '0.1.0',
	'eur_rate' => 1.5,
];
update_option( $key, $state );
$write( 'state.json', $state );
echo wp_json_encode( [ 'products' => $state['products'], 'groups' => $state['groups'], 'user' => $state['user'], 'tax_rates' => $state['tax_rates'] ] );
