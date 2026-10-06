<?php
/**
 * Pricing-hint conversion lane fixture (CURCY multi-currency, no taxes).
 *
 * Builds the same priced fields for OPF and for WAPF Extended 3.1.5 on six
 * disposable products so the cart/checkout hint strings can be compared side
 * by side at a real EUR rate of 1.5 (base USD).
 *
 * Modes: setup | cleanup. Artifacts are written to $OPF_HINTCONV_OUT.
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

if ( '1' !== getenv( 'OPF_HINTCONV_ALLOW' ) || realpath( ABSPATH ) !== '/tmp/opf-lane-hintconv-wp' || ! defined( 'FQDB' ) || realpath( FQDB ) !== realpath( ABSPATH ) . '/wp-content/database/.ht.sqlite' ) {
	throw new RuntimeException( 'Owned hintconv SQLite clone and explicit opt-in required.' );
}

$key   = 'opf_hintconv_fixture';
$state = get_option( $key, [] );
$mode  = getenv( 'OPF_HINTCONV_MODE' ) ?: 'setup';
$out   = getenv( 'OPF_HINTCONV_OUT' );
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
	foreach ( $state['options'] as $name => $entry ) {
		if ( $entry['exists'] ) {
			update_option( $name, $entry['value'] );
		} else {
			delete_option( $name );
		}
	}
	delete_option( $key );
	// Remove the CURCY clone copy (deactivate first, then delete the folder).
	$curcy         = WP_PLUGIN_DIR . '/woocommerce-multi-currency/woocommerce-multi-currency.php';
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
		'owned'               => $state['owned'],
		'remaining_products'  => count( wc_get_products( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'restored_options'    => array_keys( $state['options'] ),
		'restored_pages'      => array_map( 'intval', array_keys( $state['pages'] ?? [] ) ),
		'curcy_removed'       => $curcy_removed,
		'remaining_wc_orders' => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids' ] ) ),
		'active_plugins'      => get_option( 'active_plugins' ),
	] );
	echo 'Cleaned owned products/groups/orders/user and restored clone options.';
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

$state    = [ 'owned' => [], 'options' => [], 'products' => [], 'groups' => [], 'pages' => [], 'user' => 0 ];
$snapshot = [
	'active_plugins', 'opf_admin_only', 'opf_show_totals', 'opf_show_price_hints', 'opf_hint_format',
	'woocommerce_calc_taxes', 'woocommerce_enable_guest_checkout', 'woocommerce_default_country',
	'woocommerce_currency', 'woocommerce_cod_settings', 'woocommerce_cheque_settings',
	'woocommerce_prices_include_tax', 'woocommerce_tax_display_cart', 'woocommerce_tax_display_shop',
	'wapf_datepicker', 'wapf_show_pricing_hints', 'wapf_hint_format',
	'woo_multi_currency_params', 'woocommerce_currency_pos', 'woocommerce_coming_soon',
	'woocommerce_store_pages_only', 'permalink_structure',
];
foreach ( $snapshot as $name ) {
	$value                    = get_option( $name, '__opf_hintconv_missing__' );
	$state['options'][ $name ] = [ 'exists' => '__opf_hintconv_missing__' !== $value, 'value' => $value ];
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

// --- Store configuration (taxes off: this lane measures display conversion) ----
update_option( 'opf_admin_only', 'no' );
update_option( 'opf_show_totals', 'yes' );
update_option( 'opf_show_price_hints', 'yes' );
update_option( 'woocommerce_calc_taxes', 'no' );
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
update_option( 'wapf_show_pricing_hints', 'yes' );
update_option( 'wapf_hint_format', '' );

// --- CURCY multi-currency configuration (base USD, EUR at rate 1.5) -----------
update_option( 'woo_multi_currency_params', [
	'enable'                      => 1,
	'enable_multi_payment'        => 1,
	'currency_default'            => 'USD',
	'checkout_currency'           => 'USD',
	'checkout_currency_args'      => [ 'USD', 'EUR' ],
	'currency'                    => [ 'USD', 'EUR' ],
	'currency_rate'               => [ '1', '1.5' ],
	'currency_rate_fee'           => [ 0, 0 ],
	'currency_rate_fee_type'      => [ 'fixed', 'fixed' ],
	'currency_decimals'           => [ 2, 2 ],
	'currency_pos'                => [ 'left', 'left' ],
	'currency_custom'             => [ '', '' ],
	'currency_thousand_separator' => [ ',', ',' ],
	'currency_decimal_separator'  => [ '.', '.' ],
	'currency_flag'               => [ '', '' ],
	'currency_hidden'             => [ 0, 0 ],
	'enable_fixed_price'          => 0,
	'ignore_exchange_rate'        => 0,
	'currency_core'               => 'USD',
	'decimals_core'               => 2,
] );
$state['options']['woo_multi_currency_params'] = [ 'exists' => false, 'value' => '' ];
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

// Base product price USD 10, quantity 1, CURCY EUR rate 1.5.
// addon = base-currency per-value hint amount; hint_eur = addon * 1.5 (the
// converted form the customer is charged). Percent-produced hints stay
// unconverted in WAPF 3.1.5 (Helper::adjust_addon_price early-returns
// percent), so their `hint_eur` equals the base amount.
$cases = [
	[
		'id'            => 'fixed',
		'addon'         => 4.0,
		'unit_eur'      => 21.00,
		'kind'          => 'fixed choice + fixed scalar',
		'fields'        => [
			[ 'id' => 'fee', 'label' => 'fee', 'type' => 'radio', 'required' => true, 'pricing' => $plain, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ] ] ],
			[ 'id' => 'engrave', 'label' => 'engrave', 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ],
		],
		'values'        => [ 'fee' => 'a', 'engrave' => 'Monogram' ],
		'wapf_values'   => [ 'field_engrave' => 'Monogram' ],
		'wapf_radio'    => [ 'field_fee' => 'a' ],
	],
	[
		'id'            => 'percent',
		'addon'         => 1.0,
		'unit_eur'      => 16.50,
		'kind'          => 'percent choice',
		'fields'        => [
			[ 'id' => 'fee', 'label' => 'fee', 'type' => 'radio', 'required' => true, 'pricing' => $plain, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing' => [ 'type' => 'percent', 'amount' => 10 ] ] ] ],
		],
		'values'        => [ 'fee' => 'a' ],
		'wapf_values'   => [],
		'wapf_radio'    => [ 'field_fee' => 'a' ],
	],
	[
		'id'            => 'minmax',
		'addon'         => 5.0,
		'unit_eur'      => 22.50,
		'kind'          => 'formula min/max choice',
		'fields'        => [
			[ 'id' => 'x', 'label' => 'x', 'type' => 'text', 'pricing' => $plain ],
			[ 'id' => 'fee', 'label' => 'fee', 'type' => 'select', 'required' => true, 'pricing' => $plain, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'max(3;min([field.x];7))*[qty]', 'per_unit' => true ] ] ] ],
		],
		'values'        => [ 'x' => '5', 'fee' => 'a' ],
		'wapf_values'   => [ 'field_x' => '5' ],
		'wapf_radio'    => [],
	],
	[
		'id'            => 'date',
		'addon'         => 42.0,
		'unit_eur'      => 78.00,
		'kind'          => 'formula date choice',
		'fields'        => [
			[ 'id' => 'd', 'label' => 'd', 'type' => 'date', 'pricing' => $plain ],
			[ 'id' => 'fee', 'label' => 'fee', 'type' => 'select', 'required' => true, 'pricing' => $plain, 'choices' => [ [ 'slug' => 'a', 'label' => 'a', 'pricing' => [ 'type' => 'formula', 'amount' => 0, 'formula' => 'dow([field.d])*7*[qty]', 'per_unit' => true ] ] ] ],
		],
		'values'        => [ 'd' => '2026-10-03', 'fee' => 'a' ],
		'wapf_values'   => [ 'field_d' => '10-03-2026' ],
		'wapf_radio'    => [],
	],
];

foreach ( $cases as $case ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Disposable HintConv ' . $case['id'] );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->set_tax_class( '' );
	$product->set_status( 'publish' );
	$state['products'][ $case['id'] ] = $own( $product->save() );
	update_option( $key, $state );

	$opf_fields = [];
	foreach ( $case['fields'] as $field ) {
		$opf_field = [ 'id' => $field['id'], 'label' => $field['label'], 'type' => $field['type'], 'pricing' => $field['pricing'] ?? $plain ];
		if ( ! empty( $field['required'] ) ) {
			$opf_field['required'] = true;
		}
		if ( ! empty( $field['choices'] ) ) {
			$opf_field['choices'] = $field['choices'];
		}
		$opf_fields[] = $opf_field;
	}
	$group                                     = [
		'fields'      => $opf_fields,
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $state['products'][ $case['id'] ] ] ] ] ] ],
	];
	$state['groups'][ $case['id'] ]            = $own( FieldGroups::save( 0, $group, [ 'title' => 'HintConv ' . $case['id'], 'status' => 'publish' ] ) );
	update_option( $key, $state );

	$wapf_fields = [];
	foreach ( $case['fields'] as $field ) {
		$wapf_field = [
			'id'           => $field['id'],
			'label'        => $field['label'],
			'type'         => $field['type'],
			'required'     => ! empty( $field['required'] ),
			'options'      => [],
			'conditionals' => [],
			'pricing'      => [
				'enabled' => isset( $field['pricing'] ) && 'none' !== $field['pricing']['type'] ? 'true' : 'false',
				'type'    => $field['pricing']['type'] ?? 'fixed',
				'amount'  => (string) ( $field['pricing']['amount'] ?? '0' ),
			],
		];
		if ( ! empty( $field['choices'] ) ) {
			$wapf_field['choices'] = array_map( static function ( $c ) {
				$type = 'percent' === $c['pricing']['type'] ? 'p' : ( 'formula' === $c['pricing']['type'] ? 'fx' : $c['pricing']['type'] );
				return [
					'slug'           => $c['slug'],
					'label'          => $c['label'],
					'pricing_type'   => $type,
					'pricing_amount' => 'fx' === $type ? '(' . $c['pricing']['formula'] . ')' : (string) $c['pricing']['amount'],
				];
			}, $field['choices'] );
		}
		$wapf_fields[] = $wapf_field;
	}
	$raw          = [
		'id'         => 'p_' . $state['products'][ $case['id'] ],
		'type'       => 'wapf_product',
		'fields'     => $wapf_fields,
		'conditions' => [],
		'layout'     => [ 'labels_position' => 'above', 'instructions_position' => 'field', 'mark_required' => true ],
	];
	$wapf_class   = 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups';
	$model        = call_user_func( [ $wapf_class, 'raw_json_to_field_group' ], $raw );
	update_post_meta( $state['products'][ $case['id'] ], '_wapf_fieldgroup', $model->to_array() );
}

// --- Customer used for checkout -----------------------------------------------
$uid = wp_create_user( 'hintconv-proof', 'hintconv-proof-pass-123', 'hintconv-proof@example.test' );
if ( is_wp_error( $uid ) ) {
	/** @var \WP_Error $uid_error */
	$uid_error = $uid;
	throw new RuntimeException( 'Customer create failed: ' . $uid_error->get_error_message() );
}
$state['user'] = (int) $uid;
update_option( $key, $state );

FieldGroups::flush_cache();
$state['cases']   = $cases;
$state['runtime'] = [
	'base'        => home_url(),
	'clone'       => ABSPATH,
	'database'    => FQDB,
	'wordpress'   => get_bloginfo( 'version' ),
	'woocommerce' => WC_VERSION,
	'php'         => PHP_VERSION,
	'extended'    => '3.1.5',
	'curcy'       => '2.4.3',
	'opf'         => '0.1.0',
	'eur_rate'    => 1.5,
	'taxes'       => 'off',
];
update_option( $key, $state );
$write( 'state.json', $state );
echo wp_json_encode( [ 'products' => $state['products'], 'groups' => $state['groups'], 'user' => $state['user'] ] );
