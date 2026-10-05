<?php
/**
 * Disposable linked child-products fixture for the real currency-plugin lane
 * (CURCY / VillaTheme Multi Currency 2.4.3). Clone-only.
 *
 * Runtime: executed with `wp eval-file` inside a live WordPress + WooCommerce
 * process, so the fully-qualified `\WC_*` / WP helper symbols resolve from the
 * loaded plugins.
 *
 * Phases (OPF_CHILD_CUR_ALLOW=1 required):
 *  - setup     create parent/children/group/checkout/account pages, base tax rate, persist state
 *  - tax-on    create a non-default tax class + 5% rate and assign it to children
 *  - tax-off   unassign children, delete the non-default tax class + rate
 *  - cleanup   remove created orders/products/pages, restore touched options/rates
 */
if ( '1' !== getenv( 'OPF_CHILD_CUR_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-child-currency-wp' ) ) {
	throw new RuntimeException( 'Explicit isolated clone authorization required.' );
}
if ( ! function_exists( 'opf_cur_assert' ) ) {
	function opf_cur_assert( string $label, bool $pass ): void {
		if ( ! $pass ) {
			throw new RuntimeException( $label );
		}
		echo "ok $label\n";
	}
}

$dir = getenv( 'OPF_CHILD_CUR_ARTIFACT_DIR' ) ?: '/tmp/opf-child-currency-evidence';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}
$phase = getenv( 'OPF_CHILD_CUR_PHASE' ) ?: 'setup';
$state = \get_option( 'opf_child_currency_state', [] );

if ( 'setup' === $phase ) {
	opf_cur_assert( 'fresh isolated fixtures', empty( $state ) );
	$make = static function ( string $name, string $slug, string $price ): int {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_slug( $slug );
		$p->set_status( 'publish' );
		$p->set_regular_price( $price );
		$p->set_virtual( true );
		$p->set_tax_status( 'taxable' );
		return $p->save();
	};
	$parent = $make( 'OPF Currency Parent', 'opf-currency-parent', '20' );
	$alpha  = $make( 'OPF Currency Alpha', 'opf-currency-alpha', '8' );
	$beta   = $make( 'OPF Currency Beta', 'opf-currency-beta', '12' );
	$gamma  = $make( 'OPF Currency Gamma', 'opf-currency-gamma', '5' );

	$fields = [
		[
			'id' => 'linked_cards', 'label' => 'Included products', 'type' => 'products', 'subtype' => 'card',
			'product_selection' => 'manual', 'qty_method' => 'parent',
			'incl_img' => false, 'incl_desc' => false, 'slot_1' => 'price', 'items_per_row' => 2,
			'choices' => [
				[ 'product_id' => $alpha, 'slug' => 'child-alpha', 'pricing_type' => 'fixed' ],
				[ 'product_id' => $beta, 'slug' => 'child-beta', 'pricing_type' => 'fixed' ],
				[ 'product_id' => $gamma, 'slug' => 'child-gamma', 'pricing_type' => 'none' ],
			],
		],
	];
	$gid = \OPF\Service\FieldGroups::save( 0, [
		'fields'      => $fields,
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $parent ] ] ] ] ],
	], [ 'title' => 'Linked products currency lane', 'status' => 'publish' ] );
	opf_cur_assert( 'field group persisted', $gid > 0 );
	$group = \OPF\Service\FieldGroups::group_from_post( \get_post( $gid ) );
	opf_cur_assert( 'group round-trips products field', 1 === count( $group->data['fields'] ) && 'products' === $group->data['fields'][0]['type'] );
	opf_cur_assert( 'manual choices keep product ids', $alpha === (int) $group->data['fields'][0]['choices'][0]['product_id'] );

	$checkout = \wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'OPF currency checkout', 'post_name' => 'opf-currency-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	$account  = \wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'OPF currency account', 'post_name' => 'opf-currency-account', 'post_content' => '[woocommerce_my_account]' ] );
	$old = [];
	foreach ( [ 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_bacs_settings', 'woocommerce_currency', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart', 'woocommerce_tax_based_on', 'woocommerce_enable_guest_checkout' ] as $key ) {
		$old[ $key ] = [ 'exists' => false !== \get_option( $key, false ), 'value' => \get_option( $key ) ];
	}
	\update_option( 'woocommerce_checkout_page_id', $checkout );
	\update_option( 'woocommerce_myaccount_page_id', $account );
	\update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
	\update_option( 'woocommerce_currency', 'USD' );
	\update_option( 'woocommerce_calc_taxes', 'yes' );
	\update_option( 'woocommerce_prices_include_tax', 'no' );
	\update_option( 'woocommerce_tax_display_shop', 'excl' );
	\update_option( 'woocommerce_tax_display_cart', 'excl' );
	\update_option( 'woocommerce_tax_based_on', 'base' );
	\update_option( 'woocommerce_enable_guest_checkout', 'yes' );

	// Base-class 10% rate so child lines carry tax (task 1). Removed in cleanup.
	$base_rate_id = \WC_Tax::_insert_tax_rate( [
		'tax_rate_country'  => '',
		'tax_rate_state'    => '',
		'tax_rate'          => '10.0000',
		'tax_rate_name'     => 'OPF currency base 10',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 0,
		'tax_rate_order'    => 1,
		'tax_rate_class'    => '',
	] );
	opf_cur_assert( 'base 10% tax rate inserted', $base_rate_id > 0 );

	$state = [
		'parent' => $parent, 'alpha' => $alpha, 'beta' => $beta, 'gamma' => $gamma,
		'group' => $gid, 'checkout' => $checkout, 'account' => $account, 'base_rate_id' => $base_rate_id,
		'old_options' => $old,
	];
	\update_option( 'opf_child_currency_state', $state, false );
	file_put_contents( $dir . '/state.json', \wp_json_encode( $state, JSON_PRETTY_PRINT ) );
	echo "SUCCESS child-products currency fixture\n";
	return;
}

opf_cur_assert( 'fixture state exists', ! empty( $state['group'] ) );

if ( 'tax-on' === $phase ) {
	$existing = \get_option( 'opf_child_currency_tax_state', [] );
	opf_cur_assert( 'no prior tax class applied', empty( $existing['rate_id'] ) );
	$classes = \WC_Tax::get_tax_class_slugs();
	$slug    = 'opf-currency-child-5';
	if ( ! in_array( $slug, $classes, true ) ) {
		$created = \WC_Tax::create_tax_class( 'OPF currency child 5', $slug );
		opf_cur_assert( 'non-default tax class created', ! is_wp_error( $created ) );
		$slug = (string) $created;
	}
	opf_cur_assert( 'non-default tax class slug registered', in_array( $slug, \WC_Tax::get_tax_class_slugs(), true ) );
	$rate_id = \WC_Tax::_insert_tax_rate( [
		'tax_rate_country'  => '',
		'tax_rate_state'    => '',
		'tax_rate'          => '5.0000',
		'tax_rate_name'     => 'OPF child reduced 5',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 0,
		'tax_rate_order'    => 2,
		'tax_rate_class'    => $slug,
	] );
	opf_cur_assert( 'non-default tax rate inserted', $rate_id > 0 );
	$old_status = [];
	foreach ( [ 'alpha', 'beta', 'gamma' ] as $key ) {
		/** @var \WC_Product $product */
		$product = \wc_get_product( (int) $state[ $key ] );
		$old_status[ $key ] = $product->get_tax_class();
		$product->set_tax_class( $slug );
		$product->save();
	}
	\update_option( 'opf_child_currency_tax_state', [ 'rate_id' => $rate_id, 'slug' => $slug, 'old_status' => $old_status ], false );
	echo "SUCCESS child non-default tax class applied\n";
	return;
}

if ( 'tax-off' === $phase || 'cleanup' === $phase ) {
	$tax_state = \get_option( 'opf_child_currency_tax_state', [] );
	if ( ! empty( $tax_state['rate_id'] ) ) {
		\WC_Tax::_delete_tax_rate( (int) $tax_state['rate_id'] );
	}
	foreach ( (array) ( $tax_state['old_status'] ?? [] ) as $key => $status ) {
		/** @var \WC_Product $product */
		$product = \wc_get_product( (int) $state[ $key ] );
		if ( $product instanceof \WC_Product ) {
			$product->set_tax_class( (string) $status );
			$product->save();
		}
	}
	$classes = \WC_Tax::get_tax_class_slugs();
	if ( in_array( (string) ( $tax_state['slug'] ?? '' ), $classes, true ) ) {
		\WC_Tax::delete_tax_class_by( 'slug', (string) $tax_state['slug'] );
	}
	\delete_option( 'opf_child_currency_tax_state' );

	if ( 'tax-off' === $phase ) {
		echo "SUCCESS child non-default tax class removed\n";
		return;
	}
}

if ( 'cleanup' === $phase ) {
	foreach ( json_decode( file_get_contents( $dir . '/owned-orders.json' ), true ) ?: [] as $oid ) {
		$o = \wc_get_order( (int) $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	foreach ( [ 'parent', 'alpha', 'beta', 'gamma' ] as $key ) {
		\wp_delete_post( (int) $state[ $key ], true );
	}
	\wp_delete_post( (int) $state['group'], true );
	\wp_delete_post( (int) $state['checkout'], true );
	\wp_delete_post( (int) $state['account'], true );
	foreach ( (array) $state['old_options'] as $key => $old ) {
		if ( $old['exists'] ) {
			\update_option( $key, $old['value'] );
		} else {
			\delete_option( $key );
		}
	}
	if ( ! empty( $state['base_rate_id'] ) ) {
		\WC_Tax::_delete_tax_rate( (int) $state['base_rate_id'] );
	}
	\delete_option( 'opf_child_currency_state' );
	\OPF\Service\FieldGroups::flush_cache();
	echo "SUCCESS child-products currency cleanup\n";
	return;
}

throw new RuntimeException( 'Unknown phase: ' . $phase );

if ( false ) {
	// Analysis-only symbol stubs: this standalone file is always executed by
	// `wp eval-file`, where WordPress and WooCommerce define these symbols.
	// The guard keeps the block unreachable at runtime.
	class WC_Product {
		public function get_tax_class() { return ''; }
		public function set_tax_class( $class ) {}
		public function save() { return 1; }
	}
	class WC_Product_Simple extends WC_Product {
		public function set_name( $name ) {}
		public function set_slug( $slug ) {}
		public function set_status( $status ) {}
		public function set_regular_price( $price ) {}
		public function set_virtual( $virtual ) {}
		public function set_tax_status( $status ) {}
	}
	class WC_Order {
		public function delete( $force = false ) { return true; }
	}
	class WC_Tax {
		public static function _insert_tax_rate( $rate ) { return 0; }
		public static function _delete_tax_rate( $id ) {}
		public static function get_tax_class_slugs() { return []; }
		public static function create_tax_class( $name ) { return []; }
		public static function delete_tax_class_by( $field, $value ) {}
	}
	function delete_option( $option ) { return true; }
	function wc_get_order( $the_order = false ) { return null; }
	function wc_get_product( $the_product = false ) { return null; }
	function wp_delete_post( $post_id, $force_delete = false ) { return true; }
}
