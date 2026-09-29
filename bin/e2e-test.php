<?php
/**
 * OPF end-to-end integration test.
 *
 * Run inside a disposable WordPress + WooCommerce install:
 *   wp eval-file bin/e2e-test.php
 *
 * Exercises the full lifecycle: group creation → placement matching →
 * classic add-to-cart capture → Store API add-to-cart capture → cart
 * pricing → display data → order persistence → order-again restore →
 * validation failures. Exits non-zero on any failure.
 */

defined( 'ABSPATH' ) || exit;

global $failures;
$failures = 0;

function check( $label, $condition ): void {
	global $failures;
	if ( $condition ) {
		WP_CLI::log( "  ok    $label" );
	} else {
		$failures++;
		WP_CLI::log( "  FAIL  $label" );
	}
}

WP_CLI::log( '== OPF E2E ==' );

// Exercise the real WooCommerce settings save path. Restore this option in
// wrapup so the disposable test install keeps its previous merchant value.
$original_date_format = get_option( 'opf_date_format', null );
$original_coupon_base_only = get_option( 'opf_coupon_discount_base_only', null );
$original_sequential_discount = get_option( 'woocommerce_calc_discounts_sequentially', null );
$original_tax_options = [];
foreach ( [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on' ] as $tax_option_name ) {
	$original_tax_options[ $tax_option_name ] = get_option( $tax_option_name, null );
}
$opf_coupon_test_tax_rate_id = 0;
register_shutdown_function( static function () use ( $original_coupon_base_only, $original_sequential_discount, $original_tax_options, &$opf_coupon_test_tax_rate_id ): void {
	if ( null === $original_coupon_base_only ) {
		delete_option( 'opf_coupon_discount_base_only' );
	} else {
		update_option( 'opf_coupon_discount_base_only', $original_coupon_base_only );
	}
	if ( null === $original_sequential_discount ) {
		delete_option( 'woocommerce_calc_discounts_sequentially' );
	} else {
		update_option( 'woocommerce_calc_discounts_sequentially', $original_sequential_discount );
	}
	foreach ( $original_tax_options as $tax_option_name => $tax_option_value ) {
		if ( null === $tax_option_value ) {
			delete_option( $tax_option_name );
		} else {
			update_option( $tax_option_name, $tax_option_value );
		}
	}
	if ( $opf_coupon_test_tax_rate_id > 0 && class_exists( 'WC_Tax' ) ) {
		WC_Tax::_delete_tax_rate( $opf_coupon_test_tax_rate_id );
	}
} );
$opf_sections = apply_filters( 'woocommerce_get_sections_products', [] );
$opf_settings = OPF\Service\Admin\Settings::product_fields_settings( [], 'opf_product_fields' );
$date_setting = null;
$coupon_setting = null;
foreach ( $opf_settings as $setting ) {
	if ( ( $setting['id'] ?? '' ) === 'opf_date_format' ) {
		$date_setting = $setting;
	}
	if ( ( $setting['id'] ?? '' ) === 'opf_coupon_discount_base_only' ) {
		$coupon_setting = $setting;
	}
}
check( 'date format: setting is discoverable under WooCommerce product settings', ( $opf_sections['opf_product_fields'] ?? '' ) === 'Product fields' && is_array( $date_setting ) );
check( 'coupon scope: base-only percentage setting is discoverable and defaults off', ( $opf_sections['opf_product_fields'] ?? '' ) === 'Product fields' && is_array( $coupon_setting ) && ( $coupon_setting['default'] ?? null ) === 'no' );
if ( is_array( $date_setting ) ) {
	WC_Admin_Settings::save_fields( [ $date_setting ], [ 'opf_date_format' => 'd/m/yy' ] );
	check( 'date format: valid format persists through WooCommerce settings save', get_option( 'opf_date_format' ) === 'd/m/yy' );
	WC_Admin_Settings::save_fields( [ $date_setting ], [ 'opf_date_format' => 'yyyy-mm' ] );
	check( 'date format: invalid format preserves the last valid setting', get_option( 'opf_date_format' ) === 'd/m/yy' );
}
if ( is_array( $coupon_setting ) ) {
	WC_Admin_Settings::save_fields( [ $coupon_setting ], [ 'opf_coupon_discount_base_only' => '1' ] );
	check( 'coupon scope: WooCommerce settings save enables base-only percentage discounts', 'yes' === get_option( 'opf_coupon_discount_base_only' ) );
	WC_Admin_Settings::save_fields( [ $coupon_setting ], [ 'save' => 'Save changes' ] );
	check( 'coupon scope: WooCommerce settings save disables unchecked base-only setting', 'no' === get_option( 'opf_coupon_discount_base_only' ) );
}

// The transition gate (admin/e2e-only) must be off for the behavioral suite.
update_option( 'opf_admin_only', 'no' );

// Enable a payment gateway for the Store API checkout leg.
update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );
WC()->payment_gateways()->init();

// ---------------------------------------------------------------- fixtures.
$existing_cat = get_term_by( 'name', 'E2E Premium', 'product_cat' );
$cat_id       = $existing_cat ? (int) $existing_cat->term_id : (int) ( wp_insert_term( 'E2E Premium', 'product_cat' )['term_id'] ?? 0 );
$existing_tag = get_term_by( 'name', 'e2e-fast', 'product_tag' );
$tag_id       = $existing_tag ? (int) $existing_tag->term_id : (int) ( wp_insert_term( 'e2e-fast', 'product_tag' )['term_id'] ?? 0 );

// Product attribute targeting uses taxonomy-qualified term ids so two
// attributes can safely contain terms with the same numeric id.
$attribute_slug = 'e2e_finish';
$attribute_id   = 0;
foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
	if ( $attribute_slug === (string) $attribute->attribute_name ) {
		$attribute_id = (int) $attribute->attribute_id;
		break;
	}
}
if ( ! $attribute_id && function_exists( 'wc_create_attribute' ) ) {
	$created_attribute = wc_create_attribute( [ 'name' => 'E2E Finish', 'slug' => $attribute_slug ] );
	$attribute_id      = is_wp_error( $created_attribute ) ? 0 : (int) $created_attribute;
	delete_transient( 'wc_attribute_taxonomies' );
}
foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
	if ( $attribute_slug === (string) $attribute->attribute_name ) {
		$attribute_id = (int) $attribute->attribute_id;
		break;
	}
}
$attribute_taxonomy = $attribute_id ? wc_attribute_taxonomy_name( $attribute_slug ) : '';
if ( $attribute_taxonomy && ! taxonomy_exists( $attribute_taxonomy ) ) {
	register_taxonomy( $attribute_taxonomy, [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false ] );
}
$attribute_term = $attribute_taxonomy ? get_term_by( 'name', 'Matte', $attribute_taxonomy ) : false;
if ( $attribute_taxonomy && ! $attribute_term ) {
	$inserted = wp_insert_term( 'Matte', $attribute_taxonomy );
	$attribute_term = ! is_wp_error( $inserted ) ? get_term( (int) $inserted['term_id'], $attribute_taxonomy ) : false;
}

// Reuse or create products (idempotent across runs).
$matched_id = 0;
foreach ( wc_get_products( [ 'limit' => 50, 'return' => 'objects' ] ) as $p ) {
	if ( 'E2E Matched Product' === $p->get_name() ) {
		$matched_id = $p->get_id();
		$p->set_regular_price( '100.00' );
		$p->set_status( 'publish' );
		$p->set_tag_ids( [ (int) $tag_id ] );
		$p->save();
	}
}
if ( ! $matched_id ) {
	$matched = new WC_Product_Simple();
	$matched->set_name( 'E2E Matched Product' );
	$matched->set_regular_price( '100.00' );
	$matched->set_status( 'publish' );
	$matched->set_tag_ids( [ (int) $tag_id ] );
	$matched->set_category_ids( [ (int) $cat_id ] );
	$matched_id = $matched->save();
}

$unmatched_id = 0;
foreach ( wc_get_products( [ 'limit' => 50, 'return' => 'objects' ] ) as $p ) {
	if ( 'E2E Unmatched Product' === $p->get_name() ) {
		$unmatched_id = $p->get_id();
		$p->set_regular_price( '50.00' );
		$p->set_status( 'publish' );
		$p->set_tag_ids( [] );
		$p->save();
	}
}

$repeat_product_id = 0;
foreach ( wc_get_products( [ 'limit' => 50, 'return' => 'objects' ] ) as $p ) {
	if ( 'E2E Repeat Product' === $p->get_name() ) {
		$repeat_product_id = $p->get_id();
		$p->set_regular_price( '25.00' );
		$p->set_status( 'publish' );
		$p->set_tag_ids( [] );
		$p->set_category_ids( [] );
		$p->save();
	}
}
if ( ! $repeat_product_id ) {
	$repeat_product = new WC_Product_Simple();
	$repeat_product->set_name( 'E2E Repeat Product' );
	$repeat_product->set_regular_price( '25.00' );
	$repeat_product->set_status( 'publish' );
	$repeat_product_id = $repeat_product->save();
}
if ( ! $unmatched_id ) {
	$unmatched = new WC_Product_Simple();
	$unmatched->set_name( 'E2E Unmatched Product' );
	$unmatched->set_regular_price( '50.00' );
	$unmatched->set_status( 'publish' );
	$unmatched_id = $unmatched->save();
}

$upload_product_id = 0;
foreach ( wc_get_products( [ 'limit' => 50, 'return' => 'objects' ] ) as $p ) {
	if ( 'E2E Upload Product' === $p->get_name() ) {
		$upload_product_id = $p->get_id();
		$p->set_regular_price( '80.00' );
		$p->set_status( 'publish' );
		$p->set_tag_ids( [] );
		$p->save();
	}
}
if ( ! $upload_product_id ) {
	$upload_product = new WC_Product_Simple();
	$upload_product->set_name( 'E2E Upload Product' );
	$upload_product->set_regular_price( '80.00' );
	$upload_product->set_status( 'publish' );
	$upload_product_id = $upload_product->save();
}

// Remove stale types groups from previous runs BEFORE placement assertions.
foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => 'E2E Types Group' ] ) as $stale ) {
	wp_delete_post( (int) $stale->ID, true );
}
foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => 'E2E Attribute Group' ] ) as $stale ) {
	wp_delete_post( (int) $stale->ID, true );
}
foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => 'E2E Calc Only Group' ] ) as $stale ) {
	wp_delete_post( (int) $stale->ID, true );
}
foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => 'E2E Calculation Group' ] ) as $stale ) {
	wp_delete_post( (int) $stale->ID, true );
}
// The upload group is created late in this script; remove any stale copy so
// earlier runs do not make its required field block the other flows.
foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => 'E2E Upload Group' ] ) as $stale ) {
	wp_delete_post( (int) $stale->ID, true );
}
foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => 'E2E Repeat Group' ] ) as $stale ) {
	wp_delete_post( (int) $stale->ID, true );
}
foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => 'E2E Quantity Repeat Group' ] ) as $stale ) {
	wp_delete_post( (int) $stale->ID, true );
}
OPF\Service\FieldGroups::flush_cache();

check( 'fixtures: products created', $matched_id > 0 && $unmatched_id > 0 && $upload_product_id > 0 && $repeat_product_id > 0 );

// ------------------------------------------------------------ field group.
$group_data = [
	'fields'  => [
		[
			'id'          => 'delivery',
			'label'       => 'Delivery speed',
			'description' => 'How fast',
			'type'        => 'swatch',
			'required'    => true,
			'width'       => 100,
			'choices'     => [
				[ 'slug' => 'normal', 'label' => 'Normal', 'selected' => true, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ] ],
				[ 'slug' => 'plus', 'label' => 'Plus', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'percent', 'amount' => 20.0, 'formula' => '' ] ],
				[ 'slug' => 'boost', 'label' => 'Boost', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 5.0, 'formula' => '' ] ],
				[ 'slug' => 'formula', 'label' => 'Formula', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '([price] + [addons]) * 0.1' ] ],
			],
			'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
		[
			'id'          => 'boost_note',
			'label'       => 'Boost instructions',
			'description' => '',
			'type'        => 'textarea',
			'required'    => false,
			'width'       => 100,
			'choices'     => [],
			'pricing'     => [ 'type' => 'fixed', 'amount' => 2.0, 'formula' => '' ],
			'conditionals'=> [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'delivery', 'operator' => 'is', 'value' => 'boost' ] ] ] ],
		],
		[
			'id'          => 'gift_code',
			'label'       => 'Gift code',
			'type'        => 'text',
			'minlength'   => 3,
			'maxlength'   => 5,
			'pattern'     => '^[A-Z]+$',
			'choices'     => [],
		],
		[
			'id'          => 'delivery_date',
			'label'       => 'Delivery date',
			'type'        => 'date',
			'cutoff_time' => '00:00',
			'disabled_weekdays' => [ 0 ],
			'disabled_dates' => [ '2099-12-25', '01-01' ],
			'choices'     => [],
		],
		[
			'id'          => 'disabled_option',
			'label'       => 'Disabled option',
			'description' => '',
			'type'        => 'swatch',
			'required'    => false,
			'width'       => 100,
			'choices'     => [ [ 'slug' => 'disabled', 'label' => 'Disabled', 'selected' => false, 'disabled' => true, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ] ] ],
			'pricing'     => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals'=> [],
		],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ (string) $tag_id ] ] ] ] ],
	'mark_required' => true,
	'labels_position' => 'above',
];

// Reuse the E2E group if a previous run created it.
$existing_group = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => 1, 'title' => 'E2E Group' ] );
$gid            = $existing_group ? (int) $existing_group[0]->ID : 0;
$gid            = OPF\Service\FieldGroups::save( $gid, new OPF\Engine\FieldGroup( $group_data ), [ 'title' => 'E2E Group' ] );
check( 'field group saved', $gid > 0 );
check( 'group data round-trips', OPF\Service\FieldGroups::group_from_post( get_post( $gid ) )->data['fields'][0]['id'] === 'delivery' );
$saved_group = OPF\Service\FieldGroups::group_from_post( get_post( $gid ) );
$repeat_group_data = [
	'fields' => [ [
		'id' => 'names', 'label' => 'Guest names', 'type' => 'text', 'required' => true,
		'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 2 ], 'pricing' => [ 'type' => 'none' ],
	] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $repeat_product_id ] ] ] ] ],
];
$repeat_group_id = OPF\Service\FieldGroups::save( 0, new OPF\Engine\FieldGroup( $repeat_group_data ), [ 'title' => 'E2E Repeat Group' ] );
check( 'repeater: button group saved with bounded max', $repeat_group_id > 0 && OPF\Service\FieldGroups::group_from_post( get_post( $repeat_group_id ) )->data['fields'][0]['repeat']['max'] === 2 );
$quantity_repeat_group_data = [
	'fields' => [ [
		'id' => 'unit_name', 'label' => 'Name for each unit', 'type' => 'text', 'required' => true,
		'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ], 'pricing' => [ 'type' => 'none' ],
	] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $repeat_product_id ] ] ] ] ],
];
$quantity_repeat_group_id = OPF\Service\FieldGroups::save( 0, new OPF\Engine\FieldGroup( $quantity_repeat_group_data ), [ 'title' => 'E2E Quantity Repeat Group' ] );
check( 'repeater: quantity group saves the supported mode', $quantity_repeat_group_id > 0 && OPF\Service\FieldGroups::group_from_post( get_post( $quantity_repeat_group_id ) )->data['fields'][0]['repeat'] === [ 'enabled' => true, 'mode' => 'quantity' ] );
ob_start();
OPF\Service\Renderer::render_group( 'e2e-text-constraints', 'Text constraints', $saved_group, 100.0 );
$text_constraint_html = (string) ob_get_clean();
check( 'text validation: renderer exposes browser length and complete-value pattern hints', false !== strpos( $text_constraint_html, 'minlength="3"' ) && false !== strpos( $text_constraint_html, 'maxlength="5"' ) && false !== strpos( $text_constraint_html, 'pattern="^[A-Z]+$"' ) );
check( 'date renderer exposes cutoff, site clock, date format, and blocked-date rules', false !== strpos( $text_constraint_html, 'data-opf-date-cutoff="00:00"' ) && false !== strpos( $text_constraint_html, 'data-opf-date-site-epoch=' ) && false !== strpos( $text_constraint_html, 'data-opf-date-timezone=' ) && false !== strpos( $text_constraint_html, 'data-opf-date-format="d/m/yy"' ) && false !== strpos( $text_constraint_html, 'data-opf-disabled-weekdays="[0]"' ) && false !== strpos( $text_constraint_html, 'data-opf-disabled-dates=' ) );

$attribute_term_id = $attribute_term instanceof WP_Term ? (int) $attribute_term->term_id : 0;
$attribute_group_data = [
	'fields'      => [ [ 'id' => 'finish_note', 'label' => 'Finish note', 'type' => 'text', 'choices' => [] ] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product_attribute', 'operator' => 'in', 'terms' => [ $attribute_taxonomy . ':' . $attribute_term_id ] ] ] ] ],
];
$attribute_group_id = $attribute_taxonomy && $attribute_term_id
	? OPF\Service\FieldGroups::save( 0, new OPF\Engine\FieldGroup( $attribute_group_data ), [ 'title' => 'E2E Attribute Group' ] )
	: 0;

// ------------------------------------------------------ placement matching.
$matched_product   = wc_get_product( $matched_id );
$unmatched_product = wc_get_product( $unmatched_id );
$matched_titles    = wp_list_pluck( OPF\Service\FieldGroups::for_product( $matched_product ), 'title' );
$unmatched_titles  = wp_list_pluck( OPF\Service\FieldGroups::for_product( $unmatched_product ), 'title' );
// The types group is created later in this script; at this point only the
// tag-scoped E2E Group should match.
check( 'placement: tagged product matches the E2E group', in_array( 'E2E Group', $matched_titles, true ) );
check( 'placement: untagged product matches nothing yet', empty( $unmatched_titles ) );
if ( $attribute_taxonomy ) {
	wp_set_object_terms( $matched_id, [], $attribute_taxonomy, false );
}
$not_yet_matching = wp_list_pluck( OPF\Service\FieldGroups::for_product( $matched_product ), 'title' );
check( 'placement: unassigned attribute does not match', ! in_array( 'E2E Attribute Group', $not_yet_matching, true ) );
if ( $attribute_taxonomy && $attribute_term_id ) {
	wp_set_object_terms( $matched_id, [ $attribute_term_id ], $attribute_taxonomy, false );
}
$attribute_titles = wp_list_pluck( OPF\Service\FieldGroups::for_product( $matched_product ), 'title' );
check( 'placement: global product attribute term change refreshes cached matches', $attribute_group_id > 0 && in_array( 'E2E Attribute Group', $attribute_titles, true ) );
if ( $attribute_group_id > 0 ) {
	ob_start();
	OPF\Service\Admin\Builder::render_placement( get_post( $attribute_group_id ) );
	$placement_html = (string) ob_get_clean();
	$attribute_option = 'value="' . $attribute_taxonomy . ':' . $attribute_term_id . '"';
	check( 'builder: product attribute selector renders the selected taxonomy term', false !== strpos( $placement_html, 'id="opf-placement-attributes"' ) && false !== strpos( $placement_html, $attribute_option ) && (bool) preg_match( '/<option ' . preg_quote( $attribute_option, '/' ) . '[^>]*selected/', $placement_html ) );
}

$date_group = new OPF\Engine\FieldGroup(
	[
		'fields' => [ [ 'id' => 'delivery_date', 'label' => 'Delivery date', 'type' => 'date', 'required' => true, 'min_date' => '7d', 'max_date' => '2m' ] ],
	]
);
$date_min = OPF\Engine\FieldValue::resolve_date_boundary( '7d' );
$date_max = OPF\Engine\FieldValue::resolve_date_boundary( '2m' );
ob_start();
OPF\Service\Renderer::render_group( 'e2e-date', 'Date constraints', $date_group, 100.0 );
$date_html = (string) ob_get_clean();
check( 'date: relative bounds render as current ISO min/max attributes', null !== $date_min && null !== $date_max && false !== strpos( $date_html, 'min="' . $date_min . '"' ) && false !== strpos( $date_html, 'max="' . $date_max . '"' ) );
$before_date_min = ( new DateTimeImmutable( $date_min, current_datetime()->getTimezone() ) )->modify( '-1 day' )->format( 'Y-m-d' );
check( 'date: server rejects values before the relative minimum', null !== $date_min && ! empty( OPF\Engine\FieldValue::validate( $date_group->data['fields'][0], $before_date_min, true ) ) );

$date_policy_data = [
	'fields' => [ [ 'id' => 'restricted_date', 'label' => 'Restricted date', 'type' => 'date', 'allow_past' => false, 'allow_future' => false ] ],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $matched_id ] ] ] ] ],
];
$existing_date_policy_group = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => 1, 'title' => 'E2E Date Policy Group' ] );
$date_policy_group_id = OPF\Service\FieldGroups::save( $existing_date_policy_group ? (int) $existing_date_policy_group[0]->ID : 0, new OPF\Engine\FieldGroup( $date_policy_data ), [ 'title' => 'E2E Date Policy Group' ] );
$date_policy_group = OPF\Service\FieldGroups::group_from_post( get_post( $date_policy_group_id ) );
ob_start();
OPF\Service\Renderer::render_group( 'e2e-date-policy', 'Date policy', $date_policy_group, 100.0 );
$date_policy_html = (string) ob_get_clean();
check( 'date policy: renderer publishes both disabled sides and site clock', false !== strpos( $date_policy_html, 'data-opf-allow-past="0"' ) && false !== strpos( $date_policy_html, 'data-opf-allow-future="0"' ) && false !== strpos( $date_policy_html, 'data-opf-date-site-epoch=' ) );
$site_today = current_datetime();
$date_policy_dates = [
	'past' => $site_today->modify( '-1 day' )->format( 'Y-m-d' ),
	'today' => $site_today->format( 'Y-m-d' ),
	'future' => $site_today->modify( '+1 day' )->format( 'Y-m-d' ),
];
foreach ( $date_policy_dates as $kind => $date_policy_value ) {
	$_POST['opf'] = [
		(string) $gid => [ 'delivery' => 'normal' ],
		(string) $date_policy_group_id => [ 'restricted_date' => $date_policy_value ],
	];
	$date_policy_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
	unset( $_POST['opf'] );
	check( 'date policy: production add-to-cart ' . ( 'today' === $kind ? 'accepts' : 'rejects' ) . ' forged ' . $kind . ' date', 'today' === $kind ? true === $date_policy_valid : false === $date_policy_valid );
}

// ------------------------------------------------- classic add-to-cart path.
$_POST['opf'] = [
	(string) $gid => [
		'delivery'   => 'plus',
		'boost_note' => '', // hidden under delivery=plus → stripped.
	],
];
$passed  = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
$cart    = WC()->cart;
$cart->empty_cart();
$item_key = $cart->add_to_cart( $matched_id, 2 );
unset( $_POST['opf'] );

check( 'classic: validation passes', $passed );
check( 'classic: item added', false !== $item_key );
$cart_item = $cart->get_cart_item( $item_key );
check( 'classic: values attached', ( $cart_item['opf_fields'][ (string) $gid ]['delivery'] ?? '' ) === 'plus' );
check( 'classic: hidden field stripped', ! isset( $cart_item['opf_fields'][ (string) $gid ]['boost_note'] ) );
check( 'classic: base price stored', abs( (float) $cart_item['opf_base_price'] - 100.0 ) < 0.001 );

$cart->calculate_totals();
$line = $cart->get_cart_item( $item_key );
$expected_unit = 100.0 + ( 100.0 * 0.20 ); // plus: 20% of unit price.
check( 'classic: price = base + percent addon', abs( (float) $line['data']->get_price() - $expected_unit ) < 0.001 );
check( 'classic: cart total = unit * qty', abs( (float) $cart->get_total( 'edit' ) - ( $expected_unit * 2 ) ) < 0.001 );

$display = apply_filters( 'woocommerce_get_item_data', [], $line );
check( 'classic: display shows label', ( $display[0]['name'] ?? '' ) === 'Delivery speed' && ( $display[0]['value'] ?? '' ) === 'Plus' );

// ----------------------------------------- percentage coupon scope.
// WAPF added a setting to exclude option pricing from percentage coupons.
// Keep the OPF setting off by default unless the merchant enables it.
$coupon_id = wc_get_coupon_id_by_code( 'opf-coupon-scope-e2e' );
$percent_coupon = $coupon_id ? new WC_Coupon( $coupon_id ) : new WC_Coupon();
$percent_coupon->set_code( 'opf-coupon-scope-e2e' );
$percent_coupon->set_discount_type( 'percent' );
$percent_coupon->set_amount( 10 );
$percent_coupon->set_product_ids( [ $matched_id ] );
$percent_coupon->set_usage_limit( 0 );
$percent_coupon->save();

$fixed_coupon_id = wc_get_coupon_id_by_code( 'opf-coupon-fixed-scope-e2e' );
$fixed_coupon = $fixed_coupon_id ? new WC_Coupon( $fixed_coupon_id ) : new WC_Coupon();
$fixed_coupon->set_code( 'opf-coupon-fixed-scope-e2e' );
$fixed_coupon->set_discount_type( 'fixed_product' );
$fixed_coupon->set_amount( 5 );
$fixed_coupon->set_product_ids( [ $matched_id ] );
$fixed_coupon->set_usage_limit( 0 );
$fixed_coupon->save();

$fixed_cart_coupon_id = wc_get_coupon_id_by_code( 'opf-coupon-fixed-cart-e2e' );
$fixed_cart_coupon = $fixed_cart_coupon_id ? new WC_Coupon( $fixed_cart_coupon_id ) : new WC_Coupon();
$fixed_cart_coupon->set_code( 'opf-coupon-fixed-cart-e2e' );
$fixed_cart_coupon->set_discount_type( 'fixed_cart' );
$fixed_cart_coupon->set_amount( 10 );
$fixed_cart_coupon->set_product_ids( [ $matched_id ] );
$fixed_cart_coupon->set_usage_limit( 0 );
$fixed_cart_coupon->save();

$limited_coupon_id = wc_get_coupon_id_by_code( 'opf-coupon-limited-scope-e2e' );
$limited_coupon = $limited_coupon_id ? new WC_Coupon( $limited_coupon_id ) : new WC_Coupon();
$limited_coupon->set_code( 'opf-coupon-limited-scope-e2e' );
$limited_coupon->set_discount_type( 'percent' );
$limited_coupon->set_amount( 10 );
$limited_coupon->set_product_ids( [ $matched_id ] );
$limited_coupon->set_limit_usage_to_x_items( 1 );
$limited_coupon->set_usage_limit( 0 );
$limited_coupon->save();

update_option( 'opf_coupon_discount_base_only', 'no' );
foreach ( [ 1 => 12.0, 3 => 36.0 ] as $coupon_qty => $expected_discount ) {
	$cart->empty_cart();
	$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'plus' ] ];
	$coupon_key = $cart->add_to_cart( $matched_id, $coupon_qty );
	unset( $_POST['opf'] );
	$percent_applied = $cart->apply_coupon( 'opf-coupon-scope-e2e' );
	$cart->calculate_totals();
	check( 'coupon scope: percentage behavior stays unchanged when setting is off at quantity ' . $coupon_qty, false !== $coupon_key && true === $percent_applied && abs( (float) $cart->get_discount_total() - $expected_discount ) < 0.001 && abs( (float) $cart->get_cart_contents_total() - ( 120.0 * $coupon_qty - $expected_discount ) ) < 0.001 );
}

update_option( 'opf_coupon_discount_base_only', 'yes' );
foreach ( [ 1 => 10.0, 3 => 30.0 ] as $coupon_qty => $expected_discount ) {
	$cart->empty_cart();
	$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'plus' ] ];
	$coupon_key = $cart->add_to_cart( $matched_id, $coupon_qty );
	unset( $_POST['opf'] );
	$percent_applied = $cart->apply_coupon( 'opf-coupon-scope-e2e' );
	$cart->calculate_totals();
	check( 'coupon scope: percentage discount excludes OPF add-on at quantity ' . $coupon_qty, false !== $coupon_key && true === $percent_applied && abs( (float) $cart->get_discount_total() - $expected_discount ) < 0.001 && abs( (float) $cart->get_cart_contents_total() - ( 120.0 * $coupon_qty - $expected_discount ) ) < 0.001 );
}

$cart->empty_cart();
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'plus' ] ];
$limited_key = $cart->add_to_cart( $matched_id, 3 );
unset( $_POST['opf'] );
$limited_applied = $cart->apply_coupon( 'opf-coupon-limited-scope-e2e' );
$cart->calculate_totals();
check( 'coupon scope: quantity-limited percentage discount uses base price for eligible unit', false !== $limited_key && true === $limited_applied && abs( (float) $cart->get_discount_total() - 10.0 ) < 0.001 && abs( (float) $cart->get_cart_contents_total() - 350.0 ) < 0.001 );

$cart->empty_cart();
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'plus' ] ];
$fixed_key = $cart->add_to_cart( $matched_id, 1 );
unset( $_POST['opf'] );
$fixed_applied = $cart->apply_coupon( 'opf-coupon-fixed-scope-e2e' );
$cart->calculate_totals();
check( 'coupon scope: fixed-product coupon remains unchanged when base-only setting is on', false !== $fixed_key && true === $fixed_applied && abs( (float) $cart->get_discount_total() - 5.0 ) < 0.001 && abs( (float) $cart->get_cart_contents_total() - 115.0 ) < 0.001 );

$cart->empty_cart();
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'plus' ] ];
$fixed_cart_key = $cart->add_to_cart( $matched_id, 1 );
unset( $_POST['opf'] );
$fixed_cart_applied = $cart->apply_coupon( 'opf-coupon-fixed-cart-e2e' );
$cart->calculate_totals();
check( 'coupon scope: fixed-cart coupon remains unchanged when base-only setting is on', false !== $fixed_cart_key && true === $fixed_cart_applied && abs( (float) $cart->get_discount_total() - 10.0 ) < 0.001 && abs( (float) $cart->get_cart_contents_total() - 110.0 ) < 0.001 );

update_option( 'woocommerce_calc_discounts_sequentially', 'yes' );
$cart->empty_cart();
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'plus' ] ];
$sequential_key = $cart->add_to_cart( $matched_id, 3 );
unset( $_POST['opf'] );
$sequential_applied = $cart->apply_coupon( 'opf-coupon-scope-e2e' );
$cart->calculate_totals();
check( 'coupon scope: base-only percentage is preserved with Woo sequential setting at one coupon', false !== $sequential_key && true === $sequential_applied && abs( (float) $cart->get_discount_total() - 30.0 ) < 0.001 && abs( (float) $cart->get_cart_contents_total() - 330.0 ) < 0.001 );
if ( null === $original_sequential_discount ) {
	delete_option( 'woocommerce_calc_discounts_sequentially' );
} else {
	update_option( 'woocommerce_calc_discounts_sequentially', $original_sequential_discount );
}

// WooCommerce applies percentage coupon discounts to line prices before it
// calculates line tax. Verify the OPF filter changes the taxable amount while
// leaving tax calculation itself to WooCommerce's cart totals implementation.
$opf_coupon_test_tax_rate_id = WC_Tax::_insert_tax_rate( [
	'tax_rate_country'   => '',
	'tax_rate_state'     => '',
	'tax_rate'           => '10.0000',
	'tax_rate_name'      => 'OPF coupon E2E',
	'tax_rate_priority'  => 1,
	'tax_rate_compound'  => 0,
	'tax_rate_shipping'  => 0,
	'tax_rate_order'     => 999,
	'tax_rate_class'     => '',
] );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_based_on', 'base' );
$cart->empty_cart();
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'plus' ] ];
$tax_coupon_key = $cart->add_to_cart( $matched_id, 1 );
unset( $_POST['opf'] );
$tax_coupon_applied = $cart->apply_coupon( 'opf-coupon-scope-e2e' );
$cart->calculate_totals();
check( 'coupon scope: taxable cart calculates tax after base-only percentage discount', false !== $tax_coupon_key && true === $tax_coupon_applied && abs( (float) $cart->get_discount_total() - 10.0 ) < 0.001 && abs( (float) $cart->get_cart_contents_total() - 110.0 ) < 0.001 && abs( (float) $cart->get_cart_contents_tax() - 11.0 ) < 0.001 && abs( (float) $cart->get_total( 'edit' ) - 121.0 ) < 0.001 );
WC_Tax::_delete_tax_rate( $opf_coupon_test_tax_rate_id );
$opf_coupon_test_tax_rate_id = 0;
foreach ( $original_tax_options as $tax_option_name => $tax_option_value ) {
	if ( null === $tax_option_value ) {
		delete_option( $tax_option_name );
	} else {
		update_option( $tax_option_name, $tax_option_value );
	}
}

// --------------------------------------- formula + conditional visibility.
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'boost', 'boost_note' => 'rush it' ] ];
$cart->empty_cart();
$key2 = $cart->add_to_cart( $matched_id, 1 );
unset( $_POST['opf'] );
$cart->calculate_totals();
$line2 = $cart->get_cart_item( $key2 );
// boost: fixed 5 + visible boost_note fixed 2 → per-unit 7 over base 100.
check( 'formula-mode: conditional visible → both priced', abs( (float) $line2['data']->get_price() - 107.0 ) < 0.001 );
$display2 = apply_filters( 'woocommerce_get_item_data', [], $line2 );
$names    = wp_list_pluck( $display2, 'name' );
check( 'formula-mode: both selections displayed', in_array( 'Boost instructions', $names, true ) );

// ---------------------------------------------- required-field validation.
$_POST['opf'] = [ (string) $gid => [ 'boost_note' => 'no delivery chosen' ] ];
wc_clear_notices();
$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
unset( $_POST['opf'] );
check( 'validation: missing required choice rejected', false === $ok );
check( 'validation: error notice queued', wc_notice_count( 'error' ) > 0 );

// Text regex/length settings must be repeated by the production add-to-cart
// validation hook; browser attributes are hints only.
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'normal', 'gift_code' => 'AB1' ] ];
$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
unset( $_POST['opf'] );
check( 'text validation: server rejects a value that violates configured pattern', false === $ok );

// Disabled choices must be rejected even when the field itself is optional.
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'normal', 'disabled_option' => 'disabled' ] ];
$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
unset( $_POST['opf'] );
check( 'validation: disabled choice rejected', false === $ok );

$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'normal', 'delivery_date' => current_datetime()->format( 'Y-m-d' ) ] ];
$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
unset( $_POST['opf'] );
check( 'date cutoff: production add-to-cart validation rejects today after site-local cutoff', false === $ok );

$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'normal', 'delivery_date' => '2099-12-25' ] ];
$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
unset( $_POST['opf'] );
check( 'date restrictions: production add-to-cart rejects an exact disabled date', false === $ok );

$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'normal', 'delivery_date' => '2099-01-01' ] ];
$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
unset( $_POST['opf'] );
check( 'date restrictions: production add-to-cart rejects a recurring disabled date', false === $ok );

$disabled_weekday = current_datetime()->modify( 'next Sunday' )->format( 'Y-m-d' );
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'normal', 'delivery_date' => $disabled_weekday ] ];
$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
unset( $_POST['opf'] );
check( 'date restrictions: production add-to-cart rejects a disabled weekday', false === $ok );

// --------------------------------------------------- Store API add-to-cart.
$cart->empty_cart();
wc_clear_notices();
$rest_server = rest_get_server();
$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$request->set_param( 'id', $matched_id );
$request->set_param( 'quantity', 1 );
$future_delivery = current_datetime()->modify( '+1 day' );
if ( '0' === $future_delivery->format( 'w' ) ) {
	$future_delivery = $future_delivery->modify( '+1 day' );
}
$future_delivery_date = $future_delivery->format( 'Y-m-d' );
$request->set_param( 'opf_fields', [ (string) $gid => [ 'delivery' => 'formula', 'delivery_date' => $future_delivery_date ] ] );
$response = $rest_server->dispatch( $request );
check( 'store api: add-item accepted (status 201/200)', in_array( $response->get_status(), [ 200, 201 ], true ) );
if ( ! in_array( $response->get_status(), [ 200, 201 ], true ) ) {
	WP_CLI::log( '        store api error: ' . wp_json_encode( $response->get_data() ) );
}
$cart_after = $cart->get_cart();
check( 'store api: item in cart', count( $cart_after ) === 1 );
if ( count( $cart_after ) === 1 ) {
	$rest_item = reset( $cart_after );
	check( 'store api: values captured', ( $rest_item['opf_fields'][ (string) $gid ]['delivery'] ?? '' ) === 'formula' );
	$cart->calculate_totals();
	$rest_line = $cart->get_cart_item( $rest_item['key'] );
	// Formula output is a line adjustment; without [qty], 10 remains flat at qty 1.
	check( 'store api: flat formula priced at quantity one', isset( $rest_line ) && abs( (float) $rest_line['data']->get_price() - 110.0 ) < 0.001 );
} else {
	$rest_item = [ 'key' => '' ];
	check( 'store api: values captured', false );
	check( 'store api: formula priced', false );
}
$rest_data = $response->get_data();
$items     = $rest_data['items'] ?? [];
$has_label  = false;
$has_formatted_date = false;
foreach ( $items as $api_item ) {
	foreach ( ( $api_item['item_data'] ?? [] ) as $entry ) {
		if ( ( $entry['name'] ?? '' ) === 'Delivery speed' && ( $entry['value'] ?? '' ) === 'Formula' ) {
			$has_label = true;
		}
		if ( ( $entry['name'] ?? '' ) === 'Delivery date' && ( $entry['value'] ?? '' ) === OPF\Engine\DateFormat::format( $future_delivery_date, 'd/m/yy' ) ) {
			$has_formatted_date = true;
		}
	}
}
check( 'store api: block-checkout display includes selection', $has_label );
check( 'date format: Store API checkout displays formatted date', $has_formatted_date );

// ------------------------------------------------------ order persistence.
WC()->session->set( 'cart', $cart->get_cart_for_session() );
$order = wc_create_order();
$order_items = [];
foreach ( $cart->get_cart() as $ci_key => $ci ) {
	$item_id = $order->add_product( $ci['data'], $ci['quantity'] );
	$order_items[ $ci_key ] = $order->get_item( $item_id );
	do_action( 'woocommerce_checkout_create_order_line_item', $order_items[ $ci_key ], $ci_key, $ci, $order );
}
// Real checkout saves items after the hook fires; mirror that.
foreach ( $order_items as $oi ) {
	$oi->save();
}
$order->update_status( 'processing' );
$order->save();

$first_item = array_values( $order->get_items() )[0];
$stored = $first_item->get_meta( '_opf_fields', true );
check( 'order: structured meta persisted', is_string( $stored ) && false !== strpos( (string) $stored, 'formula' ) );
check( 'order: display meta persisted', '' !== $first_item->get_meta( 'Delivery speed', true ) );
check( 'date format: order display is formatted while structured value stays canonical', ( $first_item->get_meta( 'Delivery date', true ) === OPF\Engine\DateFormat::format( $future_delivery_date, 'd/m/yy' ) ) && ( ( json_decode( (string) $stored, true )[ (string) $gid ]['delivery_date'] ?? '' ) === $future_delivery_date ) );

// -------------------------------------------------------- order-again flow.
$again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $first_item, $order );
check( 'order again: selections restored', ( $again['opf_fields'][ (string) $gid ]['delivery'] ?? '' ) === 'formula' );
check( 'order again: current base price restored', isset( $again['opf_base_price'] ) && abs( (float) $again['opf_base_price'] - 100.0 ) < 0.001 );

// ------------------------------------------------------ theme compat layer.
$GLOBALS['product'] = $matched_product;
ob_start();
do_action( 'woocommerce_before_add_to_cart_button' );
$compat_html = ob_get_clean();
check( 'compat: opf container classes rendered', false !== strpos( $compat_html, 'opf-field-container' ) && false !== strpos( $compat_html, 'opf-field-text-swatch' ) );
check( 'compat: data-opf-price attributes rendered', false !== strpos( $compat_html, 'data-opf-price' ) );
check( 'compat: selected swatch has opf-checked', false !== strpos( $compat_html, 'opf-checked' ) );
$GLOBALS['product'] = null;

// ------------------------------------- extra field types + multi-checkbox.
$type_group_data = [
	'fields'  => [
		[
			'id' => 'addons', 'label' => 'Extras', 'description' => '', 'type' => 'checkbox', 'required' => false,
			'width' => 100,
			'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 3.0, 'formula' => '' ] ],
				[ 'slug' => 'priority', 'label' => 'Priority queue', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 4.0, 'formula' => '' ] ],
			],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
		[
			'id' => 'quantity_extra', 'label' => 'Extra units', 'description' => '', 'type' => 'number', 'required' => false,
			'width' => 100, 'choices' => [],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
		[
			'id' => 'source_url', 'label' => 'Source link', 'description' => '', 'type' => 'url', 'required' => false,
			'width' => 100, 'choices' => [],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
		[
			'id' => 'pricing_mode', 'label' => 'Pricing mode', 'description' => '', 'type' => 'select', 'required' => false,
			'width' => 100,
			'choices' => [
				[ 'slug' => 'percent_flat', 'label' => 'Flat percentage', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'percent', 'amount' => 10.0, 'per_unit' => false ] ],
				[ 'slug' => 'percent_qty', 'label' => 'Quantity percentage', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'percent', 'amount' => 10.0, 'per_unit' => true ] ],
				[ 'slug' => 'fixed_flat', 'label' => 'Flat fixed fee', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 5.0, 'per_unit' => false ] ],
				[ 'slug' => 'fixed_qty', 'label' => 'Quantity fixed fee', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 5.0, 'per_unit' => true ] ],
			],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [],
		],
		[
			'id' => 'engraving_flat', 'label' => 'Flat character fee', 'description' => '', 'type' => 'text', 'required' => false,
			'width' => 100, 'choices' => [], 'pricing' => [ 'type' => 'characters', 'amount' => 2.0, 'per_unit' => false ], 'conditionals' => [],
		],
		[
			'id' => 'engraving_qty', 'label' => 'Quantity character fee', 'description' => '', 'type' => 'text', 'required' => false,
			'width' => 100, 'choices' => [], 'pricing' => [ 'type' => 'characters', 'amount' => 2.0, 'per_unit' => true ], 'conditionals' => [],
		],
		[
			'id' => 'donation_flat', 'label' => 'Flat value fee', 'description' => '', 'type' => 'number', 'required' => false,
			'width' => 100, 'choices' => [], 'pricing' => [ 'type' => 'value', 'amount' => 2.0, 'per_unit' => false ], 'conditionals' => [],
		],
		[
			'id' => 'donation_qty', 'label' => 'Quantity value fee', 'description' => '', 'type' => 'number', 'required' => false,
			'width' => 100, 'choices' => [], 'pricing' => [ 'type' => 'value', 'amount' => 2.0, 'per_unit' => true ], 'conditionals' => [],
		],
	],
	'rule_groups' => [],
	'mark_required' => false,
	'labels_position' => 'above',
];
$type_gid = OPF\Service\FieldGroups::save( 0, new OPF\Engine\FieldGroup( $type_group_data ), [ 'title' => 'E2E Types Group' ] );
check( 'types: group saved', $type_gid > 0 );

// Global group (empty placement) must be present for BOTH products, even if
// unrelated global groups are also present in the disposable site.
$matched_global_titles = wp_list_pluck( OPF\Service\FieldGroups::for_product( $matched_product ), 'title' );
$unmatched_global_titles = wp_list_pluck( OPF\Service\FieldGroups::for_product( $unmatched_product ), 'title' );
check( 'types: exact global group matches tagged product', in_array( 'E2E Types Group', $matched_global_titles, true ) );
check( 'types: exact global group matches untagged product', in_array( 'E2E Types Group', $unmatched_global_titles, true ) );

// Checkbox: both choices selected → both priced (3 + 4 = 7 per unit).
// The required `delivery` field from the first group must be satisfied too.
$_POST['opf'] = [
	(string) $gid => [ 'delivery' => 'normal' ],
	(string) $type_gid => [
		'addons'         => [ 'gift', 'gift', 'priority' ],
		'quantity_extra' => '2.5',
		'source_url'     => 'https://example.com/page?x=1',
	],
];
$cart->empty_cart();
$key3 = $cart->add_to_cart( $matched_id, 1 );
unset( $_POST['opf'] );
$cart->calculate_totals();
$line3 = $cart->get_cart_item( $key3 );
check( 'types: multi-checkbox both priced (3+4)', isset( $line3 ) && abs( (float) $line3['data']->get_price() - 107.0 ) < 0.001 );
check( 'types: duplicate checkbox slug stored once', ( $line3['opf_fields'][ (string) $type_gid ]['addons'] ?? [] ) === [ 'gift', 'priority' ] );
$display3 = apply_filters( 'woocommerce_get_item_data', [], $line3 );
$labels3  = wp_list_pluck( $display3, 'value' );
check( 'types: checkbox display lists both labels', in_array( 'Gift wrap, Priority queue', $labels3, true ) );
check( 'types: number value sanitized', '2.5' === ( $line3['opf_fields'][ (string) $type_gid ]['quantity_extra'] ?? '' ) );
check( 'types: url value sanitized', 'https://example.com/page?x=1' === ( $line3['opf_fields'][ (string) $type_gid ]['source_url'] ?? '' ) );

// Number: non-numeric input sanitizes to empty (never prices, never stores garbage).
$_POST['opf'] = [
	(string) $gid => [ 'delivery' => 'normal' ],
	(string) $type_gid => [ 'addons' => [ 'gift' ], 'quantity_extra' => 'abc' ],
];
$cart->empty_cart();
$key4 = $cart->add_to_cart( $matched_id, 1 );
unset( $_POST['opf'] );
$line4 = $cart->get_cart_item( $key4 );
check( 'types: non-numeric number input dropped', ! isset( $line4['opf_fields'][ (string) $type_gid ]['quantity_extra'] ) );
check( 'types: price reflects remaining checkbox only', isset( $line4 ) && abs( (float) $line4['data']->get_price() - 103.0 ) < 0.001 );

// -------------------------------------- flat-fixed fee does not scale by qty.
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'boost' ] ];
$cart->empty_cart();
$key5 = $cart->add_to_cart( $matched_id, 3 );
unset( $_POST['opf'] );
$cart->calculate_totals();
$line5 = $cart->get_cart_item( $key5 );
// boost fixed 5 is a flat fee: (100 + 5/3) per unit × 3 = 305 total.
check( 'flat-fee: fixed addon does not multiply by qty (line = 305)', isset( $line5 ) && abs( (float) $line5['data']->get_price() * 3 - 305.0 ) < 0.001 );

// -------------------------------------- pricing mode quantity semantics.
foreach ( [
	'percent_flat' => [ 1 => 110.0, 3 => 310.0 ],
	'percent_qty'  => [ 1 => 110.0, 3 => 330.0 ],
	'fixed_flat'   => [ 1 => 105.0, 3 => 305.0 ],
	'fixed_qty'    => [ 1 => 105.0, 3 => 315.0 ],
] as $mode => $expected_by_quantity ) {
	foreach ( $expected_by_quantity as $quantity => $expected_line_total ) {
		$_POST['opf'] = [
			(string) $gid => [ 'delivery' => 'normal' ],
			(string) $type_gid => [ 'pricing_mode' => $mode ],
		];
		$cart->empty_cart();
		$mode_key = $cart->add_to_cart( $matched_id, $quantity );
		unset( $_POST['opf'] );
		$cart->calculate_totals();
		$mode_line = $cart->get_cart_item( $mode_key );
		$actual_line_total = isset( $mode_line ) ? (float) $mode_line['data']->get_price() * $quantity : null;
		check( 'pricing mode: ' . $mode . ' quantity ' . $quantity . ' line total (actual=' . ( null === $actual_line_total ? 'missing' : $actual_line_total ) . ')', null !== $actual_line_total && abs( $actual_line_total - $expected_line_total ) < 0.001 );
	}
}

foreach ( [ 1 => 112.0, 3 => 324.0 ] as $quantity => $expected_line_total ) {
	$_POST['opf'] = [
		(string) $gid => [ 'delivery' => 'normal' ],
		(string) $type_gid => [ 'engraving_flat' => 'abc', 'engraving_qty' => 'abc' ],
	];
	$cart->empty_cart();
	$mode_key = $cart->add_to_cart( $matched_id, $quantity );
	unset( $_POST['opf'] );
	$cart->calculate_totals();
	$mode_line = $cart->get_cart_item( $mode_key );
	$actual_line_total = isset( $mode_line ) ? (float) $mode_line['data']->get_price() * $quantity : null;
	check( 'pricing mode: character flat/quantity mixed quantity ' . $quantity . ' line total (actual=' . ( null === $actual_line_total ? 'missing' : $actual_line_total ) . ')', null !== $actual_line_total && abs( $actual_line_total - $expected_line_total ) < 0.001 );
	$_POST['opf'] = [
		(string) $gid => [ 'delivery' => 'normal' ],
		(string) $type_gid => [ 'donation_flat' => '3', 'donation_qty' => '3' ],
	];
	$cart->empty_cart();
	$mode_key = $cart->add_to_cart( $matched_id, $quantity );
	unset( $_POST['opf'] );
	$cart->calculate_totals();
	$mode_line = $cart->get_cart_item( $mode_key );
	$actual_line_total = isset( $mode_line ) ? (float) $mode_line['data']->get_price() * $quantity : null;
	check( 'pricing mode: value flat/quantity mixed quantity ' . $quantity . ' line total (actual=' . ( null === $actual_line_total ? 'missing' : $actual_line_total ) . ')', null !== $actual_line_total && abs( $actual_line_total - $expected_line_total ) < 0.001 );
}

// Isolate price formulas from the general field-type fixture so each test's
// expected price describes only the behavior under test.
$previous_formula_variables = get_option( 'opf_formula_variables', null );
$previous_user_id = get_current_user_id();
$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] );
$formula_variable_admin_html = '';
if ( $admin_ids ) {
	wp_set_current_user( (int) $admin_ids[0] );
	$_POST['opf_formula_variables_nonce'] = wp_create_nonce( 'opf_formula_variables_save' );
	$_REQUEST['opf_formula_variables_nonce'] = $_POST['opf_formula_variables_nonce'];
	$_POST['opf_formula_variables_json'] = wp_slash( wp_json_encode( [ 'global_fee' => [ 'default' => 0.25, 'changes' => [] ] ] ) );
	ob_start();
	OPF\Service\Admin\FormulaVariables::render_page();
	$formula_variable_admin_html = (string) ob_get_clean();
	unset( $_POST['opf_formula_variables_nonce'], $_POST['opf_formula_variables_json'], $_REQUEST['opf_formula_variables_nonce'] );
}
check( 'formula variables: admin editor saves reusable site definitions', ! empty( $admin_ids ) && false !== strpos( $formula_variable_admin_html, 'Formula variables saved.' ) && isset( get_option( 'opf_formula_variables', [] )['global_fee'] ) );
$calc_gid = OPF\Service\FieldGroups::save(
	0,
	new OPF\Engine\FieldGroup(
		[
			'fields' => [
				[ 'id' => 'quantity_extra', 'label' => 'Calculation input', 'type' => 'number', 'pricing' => [ 'type' => 'none' ] ],
				[ 'id' => 'width', 'label' => 'Width', 'type' => 'number' ],
				[ 'id' => 'height', 'label' => 'Height', 'type' => 'number' ],
				[ 'id' => 'color', 'label' => 'Calculation text input', 'type' => 'text' ],
				[ 'id' => 'start_date', 'label' => 'Rental start date', 'type' => 'date' ],
				[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift wrap' ], [ 'slug' => 'priority', 'label' => 'Priority queue' ] ] ],
				[ 'id' => 'material', 'label' => 'Material', 'type' => 'select', 'choices' => [ [ 'slug' => 'premium', 'label' => 'Premium', 'pricing' => [ 'type' => 'fixed', 'amount' => 7, 'per_unit' => true ] ] ] ],
				[ 'id' => 'images', 'label' => 'Prints', 'type' => 'swatch', 'image_quantities' => true, 'min' => 1, 'max' => 5, 'step' => 1, 'min_selections' => 1, 'max_selections' => 2, 'min_total_quantity' => 2, 'max_total_quantity' => 6, 'choices' => [ [ 'slug' => 'small', 'label' => 'Small print', 'image' => 'https://example.test/small.jpg', 'pricing' => [ 'type' => 'fixed', 'amount' => 2, 'per_unit' => true ] ], [ 'slug' => 'large', 'label' => 'Large print', 'image' => 'https://example.test/large.jpg', 'pricing' => [ 'type' => 'fixed', 'amount' => 3, 'per_unit' => true ] ] ] ],
				[ 'id' => 'calculation_add', 'label' => 'Formula upcharge', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => 'if(and([field.quantity_extra] >= 4; [field.quantity_extra] < 5); [field.quantity_extra] * 2 * [qty]; 0)', 'result_text' => '+{result}' ],
				[ 'id' => 'calculation_subtract', 'label' => 'Formula credit', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => '0 - [field.quantity_extra] * [qty]', 'result_text' => '{result}' ],
				[ 'id' => 'calculation_text', 'label' => 'Text conditional adjustment', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => 'if([field.color] = Red; 3 * [qty]; 0)', 'result_text' => '+{result}' ],
				[ 'id' => 'calculation_dates', 'label' => 'Rental duration price', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => 'datediff([field.start_date]; today()) * 10 * [qty]', 'result_text' => '+{result}' ],
				[ 'id' => 'calculation_checked', 'label' => 'Selected extras price', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => 'checked(extras) * 5 * [qty]', 'result_text' => '+{result}' ],
				[ 'id' => 'calculation_sum_qty', 'label' => 'Print count price', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => 'sumQty(images) * 2 * [qty]', 'result_text' => '+{result}' ],
				[ 'id' => 'calculation_lookup', 'label' => 'Blinds matrix price', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => 'lookuptable(blinds; width; height) * [qty]', 'result_text' => '+{result}' ],
				[ 'id' => 'calculation_variable', 'label' => 'Variable charge', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => '[var_tool_fee] * [qty]', 'result_text' => '+{result}' ],
				[ 'id' => 'calculation_global_variable', 'label' => 'Global variable charge', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => '[var_global_fee] * [qty]', 'result_text' => '+{result}' ],
				[ 'id' => 'calculation_choice_price', 'label' => 'Referenced choice price', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => '[price.material] * [qty]', 'result_text' => '+{result}' ],
			],
			'formula_variables' => [ 'tool_fee' => [ 'default' => 0.5, 'changes' => [ [ 'value' => 2, 'logic' => 'all', 'rules' => [ [ 'field' => 'quantity_extra', 'operator' => 'greater', 'value' => '3' ] ] ] ] ] ],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $matched_id ] ] ] ] ],
		]
	),
	[ 'title' => 'E2E Calculation Group' ]
);
wp_set_current_user( $previous_user_id );
OPF\Service\FieldGroups::flush_cache();

// Site-level reusable tables follow the WAPF native PHP-table contract. The
// add-on CSV importer is intentionally outside this fixture.
$lookup_table_filter = static function ( array $tables ): array {
	$tables['blinds'] = [ [ '200', '100', 70 ], [ '220', '100', 74 ], [ '200', '160', 74 ], [ '220', '160', 78 ] ];
	return $tables;
};
add_filter( 'opf_lookup_tables', $lookup_table_filter );

$site_today = wp_date( 'Y-m-d' );
$rental_start_date = ( new DateTimeImmutable( $site_today, current_datetime()->getTimezone() ) )->modify( '-4 days' )->format( 'Y-m-d' );
global $product;
$previous_product = $product ?? null;
$product = wc_get_product( $matched_id );
ob_start();
OPF\Service\Renderer::render();
$calculation_page_html = (string) ob_get_clean();
$product = $previous_product;
check( 'calculation date: storefront renderer publishes the site date to browser formulas', false !== strpos( $calculation_page_html, 'window.OPF_TODAY = ' . wp_json_encode( $site_today ) . ';' ) );
check( 'calculation date: storefront renders the date formula for browser evaluation', false !== strpos( $calculation_page_html, 'data-opf-formula="datediff([field.start_date]; today()) * 10 * [qty]"' ) );
check( 'lookup table: storefront publishes reusable site rows to browser pricing', false !== strpos( $calculation_page_html, 'window.OPF_LOOKUP_TABLES' ) && false !== strpos( $calculation_page_html, '"blinds"' ) && false !== strpos( $calculation_page_html, '"220","160",78' ) );
check( 'formula variables: storefront publishes local and saved site definitions to browser', false !== strpos( $calculation_page_html, 'window.OPF_FORMULA_VARIABLES' ) && false !== strpos( $calculation_page_html, '"tool_fee"' ) && false !== strpos( $calculation_page_html, '"global_fee"' ) && false !== strpos( $calculation_page_html, '"default":0.5' ) );
check( 'image quantity: storefront renders quantity inputs and constraints', false !== strpos( $calculation_page_html, 'data-opf-quantity-choice="small"' ) && false !== strpos( $calculation_page_html, 'data-opf-min-quantity="1"' ) && false !== strpos( $calculation_page_html, 'data-opf-min-total-quantity="2"' ) && false !== strpos( $calculation_page_html, 'name="opf[' . $calc_gid . '][images][small]"' ) );

// ------------------------------------------------ price calculation adjusts server cart line totals.
	foreach ( [ 1 => 159.25, 3 => 477.75 ] as $quantity => $expected_line_total ) {
	$_POST['opf'] = [
		(string) $gid => [ 'delivery' => 'normal' ],
		(string) $calc_gid => [ 'quantity_extra' => '4', 'width' => '210', 'height' => '150', 'color' => 'Red', 'start_date' => $rental_start_date, 'extras' => [ 'gift', 'gift', 'priority' ], 'material' => 'premium', 'images' => [ 'small' => '1', 'large' => '2' ] ],
	];
	$cart->empty_cart();
	$calc_key = $cart->add_to_cart( $matched_id, $quantity );
	unset( $_POST['opf'] );
	$cart->calculate_totals();
	$calc_line = $cart->get_cart_item( $calc_key );
	$actual_line_total = isset( $calc_line ) ? (float) $calc_line['data']->get_price() * $quantity : null;
		$expected_line_total += 106.0 * $quantity;
	check( 'calculation price: add/subtract validated sibling values at quantity ' . $quantity . ' (actual=' . ( null === $actual_line_total ? 'missing' : $actual_line_total ) . ')', null !== $actual_line_total && abs( $actual_line_total - $expected_line_total ) < 0.001 );
		check( 'calculation price: duplicate checkbox slug counted once', ( $calc_line['opf_fields'][ (string) $calc_gid ]['extras'] ?? [] ) === [ 'gift', 'priority' ] );
	check( 'image quantity: sanitized quantities persist on cart line', ( $calc_line['opf_fields'][ (string) $calc_gid ]['images'] ?? [] ) === [ 'small' => '1', 'large' => '2' ] );
	$image_selections = OPF\Service\CartIntegration::visible_selections( wc_get_product( $matched_id ), $calc_line['opf_fields'] );
	check( 'image quantity: cart display labels choices with counts', in_array( [ 'label' => 'Prints', 'value' => 'Small print × 1, Large print × 2' ], $image_selections, true ) );
}

$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'normal' ], (string) $calc_gid => [ 'images' => [ 'small' => '2', 'large' => '5' ] ] ];
// WooCommerce's classic form handler and Store API apply this filter before
// adding a cart item; WC_Cart::add_to_cart() itself intentionally does not.
wc_clear_notices();
$validation_hook_seen = null;
$validation_trace = static function ( $passed ) use ( &$validation_hook_seen ) {
	$validation_hook_seen = (bool) $passed;
	return $passed;
};
add_filter( 'woocommerce_add_to_cart_validation', $validation_trace, 11, 1 );
$quantity_limit_passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $matched_id, 1 );
remove_filter( 'woocommerce_add_to_cart_validation', $validation_trace, 11 );
unset( $_POST['opf'] );
check( 'image quantity: production add-to-cart validation filter rejects total above maximum', false === $quantity_limit_passed && false === $validation_hook_seen );
check( 'image quantity: validation callback remains registered with WooCommerce', false !== has_filter( 'woocommerce_add_to_cart_validation', [ OPF\Service\CartIntegration::class, 'validate_add_to_cart' ] ) );

// Price calculations with no submitted fields still attach a marker and run.
$calc_only_gid = OPF\Service\FieldGroups::save(
	0,
	new OPF\Engine\FieldGroup(
		[
			'fields' => [ [ 'id' => 'surcharge', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => '[price] * 0.1 * [qty]' ] ],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $matched_id ] ] ] ] ],
		]
	),
	[ 'title' => 'E2E Calc Only Group' ]
);
OPF\Service\FieldGroups::flush_cache();
$_POST['opf'] = [ (string) $gid => [ 'delivery' => 'normal' ] ];
$cart->empty_cart();
$calc_only_key = $cart->add_to_cart( $matched_id, 3 );
unset( $_POST['opf'] );
$cart->calculate_totals();
$calc_only_line = $cart->get_cart_item( $calc_only_key );
$calc_only_total = isset( $calc_only_line ) ? (float) $calc_only_line['data']->get_price() * 3 : null;
check( 'calculation price: calc-only group and site formula variable contribute without posted values (actual=' . ( null === $calc_only_total ? 'missing' : $calc_only_total ) . ')', $calc_only_gid > 0 && isset( $calc_only_line['opf_fields'][ (string) $calc_only_gid ] ) && null !== $calc_only_total && abs( $calc_only_total - 332.25 ) < 0.001 );

// A calculation output can drive a later conditional field. The formula field
// deliberately precedes its width input to prove forward dependency resolution.
$condition_product_id = 0;
foreach ( wc_get_products( [ 'limit' => 50, 'return' => 'objects' ] ) as $existing_condition_product ) {
	if ( 'E2E Calculation Conditional Product' === $existing_condition_product->get_name() ) {
		$condition_product_id = $existing_condition_product->get_id();
		$existing_condition_product->set_regular_price( '100.00' );
		$existing_condition_product->set_status( 'publish' );
		$existing_condition_product->save();
	}
}
if ( ! $condition_product_id ) {
	$condition_product = new WC_Product_Simple();
	$condition_product->set_name( 'E2E Calculation Conditional Product' );
	$condition_product->set_regular_price( '100.00' );
	$condition_product->set_status( 'publish' );
	$condition_product_id = $condition_product->save();
}
$condition_gid = OPF\Service\FieldGroups::save(
	0,
	new OPF\Engine\FieldGroup(
		[
			'fields' => [
				[ 'id' => 'area', 'label' => 'Area', 'type' => 'calculation', 'formula' => '[field.width] * 2' ],
				[ 'id' => 'width', 'label' => 'Width', 'type' => 'number' ],
				[ 'id' => 'premium', 'label' => 'Premium option', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'yes', 'label' => 'Premium', 'pricing' => [ 'type' => 'fixed', 'amount' => 7, 'per_unit' => true ] ] ], 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'area', 'operator' => 'greater', 'value' => '10' ] ] ] ] ],
				[ 'id' => 'mode', 'label' => 'Mode', 'type' => 'select', 'choices' => [ [ 'slug' => 'show', 'label' => 'Show' ], [ 'slug' => 'hide', 'label' => 'Hide' ] ] ],
				[ 'id' => 'hidden_area', 'label' => 'Hidden area', 'type' => 'calculation', 'formula' => '20', 'conditionals' => [ [ 'action' => 'hide', 'logic' => 'all', 'rules' => [ [ 'field' => 'mode', 'operator' => 'is', 'value' => 'hide' ] ] ] ] ],
				[ 'id' => 'hidden_premium', 'label' => 'Hidden premium', 'type' => 'select', 'choices' => [ [ 'slug' => 'yes', 'label' => 'Hidden premium', 'pricing' => [ 'type' => 'fixed', 'amount' => 40, 'per_unit' => true ] ] ], 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'hidden_area', 'operator' => 'greater', 'value' => '10' ] ] ] ] ],
			],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $condition_product_id ] ] ] ] ],
		]
	),
	[ 'title' => 'E2E Calculation Conditional Group' ]
);
OPF\Service\FieldGroups::flush_cache();

$_POST['opf'] = [ (string) $condition_gid => [ 'width' => '6', 'premium' => [ 'yes' ], 'mode' => 'show' ] ];
wc_clear_notices();
$condition_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $condition_product_id, 2 );
$cart->empty_cart();
$condition_key = $condition_valid ? $cart->add_to_cart( $condition_product_id, 2 ) : false;
unset( $_POST['opf'] );
$cart->calculate_totals();
$condition_line = $condition_key ? $cart->get_cart_item( $condition_key ) : null;
$condition_total = $condition_line ? (float) $condition_line['data']->get_price() * 2 : null;
check( 'calculation conditional: add-to-cart accepts a valid forward calculation dependency', $condition_valid && ! empty( $condition_key ) );
check( 'calculation conditional: selected visible choice prices both cart units (actual=' . ( null === $condition_total ? 'missing' : $condition_total ) . ')', null !== $condition_total && abs( $condition_total - 214.0 ) < 0.001 );
check( 'calculation conditional: derived output is not persisted as a submitted value', ! isset( $condition_line['opf_fields'][ (string) $condition_gid ]['area'] ) && ( $condition_line['opf_fields'][ (string) $condition_gid ]['premium'] ?? [] ) === [ 'yes' ] );

$_POST['opf'] = [ (string) $condition_gid => [ 'width' => '6', 'mode' => 'hide', 'hidden_premium' => 'yes' ] ];
wc_clear_notices();
$hidden_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $condition_product_id, 1 );
$cart->empty_cart();
$hidden_key = $hidden_valid ? $cart->add_to_cart( $condition_product_id, 1 ) : false;
unset( $_POST['opf'] );
$cart->calculate_totals();
$hidden_line = $hidden_key ? $cart->get_cart_item( $hidden_key ) : null;
$hidden_total = $hidden_line ? (float) $hidden_line['data']->get_price() : null;
check( 'calculation conditional: hidden calc cannot expose a forged dependent selection or price', $hidden_valid && null !== $hidden_total && abs( $hidden_total - 100.0 ) < 0.001 && ! isset( $hidden_line['opf_fields'][ (string) $condition_gid ]['hidden_premium'] ) );

// Formula pricing returns a line adjustment. Convert once to per-unit price:
// `[qty]` formulas grow linearly and formulas without it stay flat.
$formula_product_id = 0;
foreach ( wc_get_products( [ 'limit' => 50, 'return' => 'objects' ] ) as $existing_formula_product ) {
	if ( 'E2E Formula Quantity Product' === $existing_formula_product->get_name() ) {
		$formula_product_id = $existing_formula_product->get_id();
		$existing_formula_product->set_regular_price( '100.00' );
		$existing_formula_product->set_status( 'publish' );
		$existing_formula_product->save();
	}
}
if ( ! $formula_product_id ) {
	$formula_product = new WC_Product_Simple();
	$formula_product->set_name( 'E2E Formula Quantity Product' );
	$formula_product->set_regular_price( '100.00' );
	$formula_product->set_status( 'publish' );
	$formula_product_id = $formula_product->save();
}
$formula_gid = OPF\Service\FieldGroups::save( 0, new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'formula_choice', 'label' => 'Formula choice', 'type' => 'select', 'choices' => [
			[ 'slug' => 'quantity', 'label' => 'Quantity based', 'pricing' => [ 'type' => 'formula', 'formula' => '5 * [qty]' ] ],
			[ 'slug' => 'flat', 'label' => 'Flat', 'pricing' => [ 'type' => 'formula', 'formula' => '5' ] ],
			[ 'slug' => 'credit', 'label' => 'Credit', 'pricing' => [ 'type' => 'formula', 'formula' => '-10 * [qty]' ] ],
		] ],
		[ 'id' => 'formula_text', 'label' => 'Formula text', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => '2 * [qty]' ] ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $formula_product_id ] ] ] ],
] ] ), [ 'title' => 'E2E Formula Quantity Group' ] );
OPF\Service\FieldGroups::flush_cache();
foreach ( [ 1, 3 ] as $formula_qty ) {
	foreach ( [ 'quantity' => 107.0 * $formula_qty, 'flat' => 102.0 * $formula_qty + 5.0, 'credit' => 92.0 * $formula_qty ] as $formula_choice => $expected_formula_total ) {
		$_POST['opf'] = [ (string) $formula_gid => [ 'formula_choice' => $formula_choice, 'formula_text' => 'engraved' ] ];
		$cart->empty_cart();
		$formula_key = $cart->add_to_cart( $formula_product_id, $formula_qty );
		unset( $_POST['opf'] );
		$cart->calculate_totals();
		$formula_line = $cart->get_cart_item( $formula_key );
		$actual_formula_total = isset( $formula_line ) ? (float) $formula_line['data']->get_price() * $formula_qty : null;
		check( 'formula pricing: ' . $formula_choice . ' choice + scalar field at quantity ' . $formula_qty . ' (actual=' . ( null === $actual_formula_total ? 'missing' : $actual_formula_total ) . ')', null !== $actual_formula_total && abs( $actual_formula_total - $expected_formula_total ) < 0.001 );
	}
}

// --------------------------------------- Store API checkout → real order.
$cart->empty_cart();
wc_clear_notices();
if ( null === $original_date_format ) {
	delete_option( 'opf_date_format' );
} else {
	update_option( 'opf_date_format', $original_date_format );
}
$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$request->set_param( 'id', $matched_id );
$request->set_param( 'quantity', 2 );
$request->set_param( 'opf_fields', [ (string) $gid => [ 'delivery' => 'plus' ], (string) $calc_gid => [ 'quantity_extra' => '4', 'width' => '210', 'height' => '150', 'color' => 'Red', 'start_date' => $rental_start_date, 'extras' => [ 'gift', 'gift', 'priority' ], 'material' => 'premium', 'images' => [ 'small' => '1', 'large' => '2' ] ] ] );
$response = rest_get_server()->dispatch( $request );
check( 'checkout: item in cart', in_array( $response->get_status(), [ 200, 201 ], true ) );
$condition_add_request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$condition_add_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$condition_add_request->set_param( 'id', $condition_product_id );
$condition_add_request->set_param( 'quantity', 2 );
$condition_add_request->set_param( 'opf_fields', [ (string) $condition_gid => [ 'width' => '6', 'premium' => [ 'yes' ], 'mode' => 'show' ] ] );
$condition_add_response = rest_get_server()->dispatch( $condition_add_request );
check( 'calculation conditional: Store API accepts calculation-dependent selection', in_array( $condition_add_response->get_status(), [ 200, 201 ], true ) );
$formula_add_request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$formula_add_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$formula_add_request->set_param( 'id', $formula_product_id );
$formula_add_request->set_param( 'quantity', 3 );
$formula_add_request->set_param( 'opf_fields', [ (string) $formula_gid => [ 'formula_choice' => 'quantity', 'formula_text' => 'engraved' ] ] );
$formula_add_response = rest_get_server()->dispatch( $formula_add_request );
check( 'formula pricing: Store API accepts quantity formula and scalar formula field', in_array( $formula_add_response->get_status(), [ 200, 201 ], true ) );
$store_coupon_applied = $cart->apply_coupon( 'opf-coupon-scope-e2e' );
$cart->calculate_totals();
check( 'coupon scope: Store API cart percent discount excludes OPF option pricing', true === $store_coupon_applied && abs( (float) $cart->get_discount_total() - 20.0 ) < 0.001 );

$gateway = WC()->payment_gateways()->get_available_payment_gateways()['bacs'] ?? null;
if ( ! $gateway ) {
	check( 'checkout: bacs gateway available', false );
} else {
	$checkout_request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$checkout_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
	$checkout_request->set_param( 'payment_method', 'bacs' );
	$checkout_request->set_param( 'billing_address', [
		'first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'buyer@example.com',
		'address_1'  => '1 Test St', 'city' => 'Testville', 'postcode' => '12345',
		'country'    => 'US', 'state'    => 'CA',
	] );
	$checkout_request->set_param( 'customer_note', 'E2E order' );
	$checkout_response = rest_get_server()->dispatch( $checkout_request );
	check( 'checkout: Store API checkout accepted', in_array( $checkout_response->get_status(), [ 200, 201 ], true ) );
	if ( ! in_array( $checkout_response->get_status(), [ 200, 201 ], true ) ) {
		WP_CLI::log( '        checkout error: ' . wp_json_encode( $checkout_response->get_data() ) );
	} else {
		$order_id = $checkout_response->get_data()['order_id'] ?? 0;
		$checkout_order = wc_get_order( $order_id );
		check( 'checkout: order created', $checkout_order instanceof WC_Order );
		$co_item = array_values( $checkout_order->get_items() )[0] ?? null;
		$condition_order_item = array_values( $checkout_order->get_items() )[1] ?? null;
		$formula_order_item = array_values( $checkout_order->get_items() )[2] ?? null;
		$co_meta = $co_item ? $co_item->get_meta( '_opf_fields', true ) : '';
		check( 'checkout: order item carries structured fields', is_string( $co_meta ) && false !== strpos( (string) $co_meta, 'plus' ) );
		check( 'checkout: order item display meta', '' !== ( $co_item ? $co_item->get_meta( 'Delivery speed', true ) : '' ) );
		$expected_line_subtotal = 2 * ( 100.0 + 20.0 + 4.0 + 3.0 + 10.0 + 78.0 + 2.0 + 0.25 + 7.0 ) + 80.0 + 20.0 + 28.0 + 14.0; // Existing lifecycle total plus image choice pricing, sumQty, lookup, formula variables, and [price.ID] reference.
		$expected_line          = $expected_line_subtotal - 20.0; // Percent coupon excludes the two OPF-adjusted units' option charges.
		$actual_line_total = $co_item ? (float) $co_item->get_total() : null;
		check( 'checkout: order line totals include [price.ID] referenced choice price (actual=' . ( null === $actual_line_total ? 'missing' : $actual_line_total ) . ')', null !== $actual_line_total && abs( $actual_line_total - $expected_line ) < 0.001 );
		check( 'coupon scope: Store API order persists the base-only product discount', $co_item instanceof WC_Order_Item_Product && abs( (float) $co_item->get_subtotal() - $expected_line_subtotal ) < 0.001 && abs( (float) $co_item->get_total() - $expected_line ) < 0.001 && abs( (float) $checkout_order->get_discount_total() - 20.0 ) < 0.001 );
		$co_fields = $co_item ? json_decode( (string) $co_item->get_meta( '_opf_fields', true ), true ) : [];
		check( 'checkout: order preserves the date used by the calculation', is_array( $co_fields ) && ( $co_fields[ (string) $calc_gid ]['start_date'] ?? '' ) === $rental_start_date );
		check( 'checkout: order stores duplicate checkbox slug once', is_array( $co_fields ) && ( $co_fields[ (string) $calc_gid ]['extras'] ?? [] ) === [ 'gift', 'priority' ] );
		check( 'checkout: order preserves validated image quantities', is_array( $co_fields ) && ( $co_fields[ (string) $calc_gid ]['images'] ?? [] ) === [ 'small' => '1', 'large' => '2' ] );
		check( 'checkout: order preserves lookup dimension values', is_array( $co_fields ) && ( $co_fields[ (string) $calc_gid ]['width'] ?? '' ) === '210' && ( $co_fields[ (string) $calc_gid ]['height'] ?? '' ) === '150' );
		check( 'checkout: order display meta includes image choice counts', 'Small print × 1, Large print × 2' === $co_item->get_meta( 'Prints', true ) );
		$condition_order_fields = $condition_order_item ? json_decode( (string) $condition_order_item->get_meta( '_opf_fields', true ), true ) : [];
		$condition_order_total = $condition_order_item ? (float) $condition_order_item->get_total() : null;
		check( 'calculation conditional: order preserves selected dependent option and line total', $condition_order_item && ( $condition_order_fields[ (string) $condition_gid ]['premium'] ?? [] ) === [ 'yes' ] && null !== $condition_order_total && abs( $condition_order_total - 214.0 ) < 0.001 );
		$formula_order_fields = $formula_order_item ? json_decode( (string) $formula_order_item->get_meta( '_opf_fields', true ), true ) : [];
		$formula_order_total = $formula_order_item ? (float) $formula_order_item->get_total() : null;
		check( 'formula pricing: order line is linear in quantity and fields persist', $formula_order_item && null !== $formula_order_total && abs( $formula_order_total - 321.0 ) < 0.001 && ( $formula_order_fields[ (string) $formula_gid ]['formula_choice'] ?? '' ) === 'quantity' && ( $formula_order_fields[ (string) $formula_gid ]['formula_text'] ?? '' ) === 'engraved' );
	}
}
remove_filter( 'opf_lookup_tables', $lookup_table_filter );
if ( null === $previous_formula_variables ) {
	delete_option( 'opf_formula_variables' );
} else {
	update_option( 'opf_formula_variables', $previous_formula_variables );
}
wp_delete_post( $calc_only_gid, true );
wp_delete_post( $calc_gid, true );
wp_delete_post( $condition_gid, true );
wp_delete_post( $condition_product_id, true );
wp_delete_post( $formula_gid, true );
wp_delete_post( $formula_product_id, true );
OPF\Service\FieldGroups::flush_cache();

// ------------------------------------------------- meta display prettifier.
$probe_item = array_values( $order->get_items() )[0] ?? null;
if ( $probe_item ) {
	$pretty_key = apply_filters( 'woocommerce_order_item_display_meta_key', 'duracion', null, $probe_item );
	check( 'prettifier: legacy key humanized with accents', 'Duración' === $pretty_key );
	$pretty_key2 = apply_filters( 'woocommerce_order_item_display_meta_key', 'source_link', null, $probe_item );
	check( 'prettifier: lowercase key humanized', 'Source link' === $pretty_key2 );
	$pretty_key3 = apply_filters( 'woocommerce_order_item_display_meta_key', 'Delivery speed', null, $probe_item );
	check( 'prettifier: already-clean key untouched', 'Delivery speed' === $pretty_key3 );
	$pretty_val = apply_filters( 'woocommerce_order_item_display_meta_value', 'https://example.com/target', null, $probe_item );
	check( 'prettifier: URL value rendered as safe link', is_string( $pretty_val ) && false !== strpos( $pretty_val, '<a href="https://example.com/target"' ) && false !== strpos( $pretty_val, 'rel="noopener noreferrer"' ) );
	$pretty_val2 = apply_filters( 'woocommerce_order_item_display_meta_value', 'Boost', null, $probe_item );
	check( 'prettifier: plain value untouched', 'Boost' === $pretty_val2 );
	$pretty_val3 = apply_filters( 'woocommerce_order_item_display_meta_value', 'javascript:alert(1)', null, $probe_item );
	check( 'prettifier: javascript: URL not linked', 'javascript:alert(1)' === $pretty_val3 );
}

// ------------------------------------------------------- upload lifecycle.
$upload_group_data = [
	'fields'  => [
		[
			'id' => 'artwork', 'label' => 'Artwork', 'description' => '', 'type' => 'upload', 'required' => true,
			'width' => 100, 'choices' => [], 'max_files' => 2, 'max_size' => 100000, 'allowed_types' => [ 'png' ],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ], 'conditionals' => [],
		],
		[
			'id' => 'proof', 'label' => 'Proof', 'description' => '', 'type' => 'upload', 'required' => false,
			'width' => 100, 'choices' => [], 'max_files' => 1, 'max_size' => 100000, 'allowed_types' => [ 'image/*' ],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
			'conditionals' => [ [ 'action' => 'hide', 'logic' => 'all', 'rules' => [ [ 'field' => 'notes', 'operator' => 'is', 'value' => 'hide' ] ] ] ],
		],
		[
			'id' => 'notes', 'label' => 'Notes', 'description' => '', 'type' => 'text', 'required' => false,
			'width' => 100, 'choices' => [],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ], 'conditionals' => [],
		],
		[
			'id' => 'file_choice', 'label' => 'File count option', 'type' => 'select', 'required' => false,
			'choices' => [ [ 'slug' => 'yes', 'label' => 'File option', 'pricing' => [ 'type' => 'fixed', 'amount' => 7, 'per_unit' => true ] ] ],
			'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'upload_fee', 'operator' => 'greater', 'value' => '0' ] ] ] ],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
		],
		[
			'id' => 'upload_fee', 'label' => 'File count fee', 'type' => 'calculation', 'calculation_type' => 'price',
			'formula' => '(files(artwork) * 5 + files(proof) * 100) * [qty]', 'result_text' => '+{result}',
		],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $upload_product_id ] ] ] ] ],
	'mark_required' => false,
	'labels_position' => 'above',
];
$upload_gid = OPF\Service\FieldGroups::save( 0, new OPF\Engine\FieldGroup( $upload_group_data ), [ 'title' => 'E2E Upload Group' ] );
check( 'upload: group saved', $upload_gid > 0 );

check( 'upload: REST download route registered', (bool) preg_match( '#/opf/v1/uploads/\(\?P<token>\[a-f0-9\]\{48\}\)#', implode( ' ', array_keys( rest_get_server()->get_routes() ) ) ) );

$tmp_png = tempnam( sys_get_temp_dir(), 'opf-e2e' );
$tmp_png = $tmp_png . '.png';
file_put_contents( $tmp_png, (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABh6FO1AAAAABJRU5ErkJggg==' ) );

$upload_files = static function ( int $gid, string $fid, string $name, string $type, string $tmp, int $size, int $error = UPLOAD_ERR_OK ): array {
	return [
		'name'     => [ (string) $gid => [ $fid => $name ] ],
		'type'     => [ (string) $gid => [ $fid => $type ] ],
		'tmp_name' => [ (string) $gid => [ $fid => $tmp ] ],
		'error'    => [ (string) $gid => [ $fid => $error ] ],
		'size'     => [ (string) $gid => [ $fid => $size ] ],
	];
};
$upload_multiple_files = static function ( int $gid, string $fid, array $entries ): array {
	$fields = [ 'name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => [] ];
	foreach ( $entries as $entry ) {
		foreach ( array_keys( $fields ) as $property ) {
			$fields[ $property ][] = $entry[ $property ];
		}
	}
	$out = [];
	foreach ( $fields as $property => $values ) {
		$out[ $property ] = [ (string) $gid => [ $fid => $values ] ];
	}
	return $out;
};
$write_png = static function () use ( $tmp_png ): void {
	file_put_contents( $tmp_png, (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABh6FO1AAAAABJRU5ErkJggg==' ) );
};
add_filter( 'opf_upload_is_uploaded_file', '__return_true' );

// Ajax staging uses the real Woo session and UploadService private storage.
$e2e_webroot = realpath( ABSPATH );
add_filter( 'opf_upload_document_root', static fn( string $root ): string => false !== $e2e_webroot ? $e2e_webroot : $root );
$make_upload_request = static function ( string $name, string $type, int $size ) use ( $upload_product_id, $upload_gid, $tmp_png ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', '/opf/v1/uploads' );
	$request->set_param( 'product_id', $upload_product_id );
	$request->set_param( 'group_id', (string) $upload_gid );
	$request->set_param( 'field_id', 'artwork' );
	$request->set_file_params( [ 'file' => [ 'name' => $name, 'type' => $type, 'tmp_name' => $tmp_png, 'error' => UPLOAD_ERR_OK, 'size' => $size ] ] );
	return $request;
};
$request_nonce = new WP_REST_Request( 'POST', '/opf/v1/uploads' );
$request_nonce->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
check( 'upload ajax: route requires REST nonce and Woo session', OPF\Service\Rest::upload_request_allowed( $request_nonce ) && ! OPF\Service\Rest::upload_request_allowed( new WP_REST_Request( 'POST', '/opf/v1/uploads' ) ) );
$ajax_staged_tokens = [];
$session = WC()->session;
register_shutdown_function( static function () use ( &$ajax_staged_tokens ): void {
	foreach ( $ajax_staged_tokens as $staged_token ) {
		OPF\Engine\UploadService::delete_staged( $staged_token );
		if ( ! OPF\Engine\UploadService::is_referenced( $staged_token ) ) OPF\Engine\UploadService::delete( $staged_token );
	}
} );
$legacy_upload_dir = trailingslashit( (string) wp_upload_dir()['basedir'] ) . OPF\Engine\UploadService::DIR_NAME;
wp_mkdir_p( $legacy_upload_dir );
$webroot_for_uploads = $e2e_webroot;
$legacy_private_dir  = false !== $webroot_for_uploads ? dirname( $webroot_for_uploads ) . DIRECTORY_SEPARATOR . OPF\Engine\UploadService::DIR_NAME : '';
$legacy_marker       = '' !== $legacy_private_dir ? $legacy_private_dir . DIRECTORY_SEPARATOR . '.legacy-migrated-v1' : '';
if ( '' !== $legacy_marker && is_file( $legacy_marker ) ) unlink( $legacy_marker );
$legacy_probe_token = bin2hex( random_bytes( 24 ) );
$legacy_probe_path  = $legacy_upload_dir . '/' . $legacy_probe_token . '.txt';
$legacy_probe_bytes = 'legacy-token-migration-preserves-content';
file_put_contents( $legacy_probe_path, $legacy_probe_bytes );
$legacy_files_before = [];
foreach ( (array) glob( $legacy_upload_dir . '/*' ) as $legacy_file ) {
	$legacy_name = basename( $legacy_file );
	if ( preg_match( '/^([a-f0-9]{48})\.[a-z0-9]{1,8}$/D', $legacy_name, $legacy_match ) && is_file( $legacy_file ) ) {
		$legacy_files_before[ $legacy_match[1] ] = hash_file( 'sha256', $legacy_file );
	}
}
$write_png();
$ajax_one = OPF\Service\Rest::stage_upload( $make_upload_request( 'ajax-one.png', 'image/png', (int) filesize( $tmp_png ) ) );
$ajax_one_token = ! is_wp_error( $ajax_one ) ? (string) ( $ajax_one->data['token'] ?? '' ) : '';
$ajax_staged_tokens[] = $ajax_one_token;
$private_upload_dir = OPF\Engine\UploadService::dir();
$webroot             = $e2e_webroot;
$private_real        = is_string( $private_upload_dir ) ? realpath( $private_upload_dir ) : false;
$webroot_prefix      = false !== $webroot ? rtrim( $webroot, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR : '';
check( 'upload: new private storage is outside the served webroot', false !== $private_real && '' !== $webroot_prefix && 0 !== strpos( $private_real, $webroot_prefix ) );
$legacy_data_preserved = true;
foreach ( $legacy_files_before as $legacy_token => $legacy_hash ) {
	$migrated_path = OPF\Engine\UploadService::path( $legacy_token );
	if ( ! is_string( $migrated_path ) || ! is_file( $migrated_path ) || ! is_string( $legacy_hash ) || ! hash_equals( $legacy_hash, (string) hash_file( 'sha256', $migrated_path ) ) ) {
		$legacy_data_preserved = false;
		break;
	}
}
check( 'upload: legacy in-webroot tokens resolve outside with byte content preserved', $legacy_data_preserved && null !== OPF\Engine\UploadService::path( $legacy_probe_token ) && $legacy_probe_bytes === file_get_contents( (string) OPF\Engine\UploadService::path( $legacy_probe_token ) ) );
$write_png();
$ajax_two = OPF\Service\Rest::stage_upload( $make_upload_request( 'ajax-two.png', 'image/png', (int) filesize( $tmp_png ) ) );
$ajax_two_token = ! is_wp_error( $ajax_two ) ? (string) ( $ajax_two->data['token'] ?? '' ) : '';
$ajax_staged_tokens[] = $ajax_two_token;
check( 'upload ajax: valid files staged in private storage', '' !== $ajax_one_token && '' !== $ajax_two_token && is_file( (string) OPF\Engine\UploadService::path( $ajax_one_token ) ) );
check( 'upload ajax: staged token bound to exact product/group/field', null !== OPF\Engine\UploadService::staged_file( $ajax_one_token, $upload_product_id, (string) $upload_gid, 'artwork' ) && null === OPF\Engine\UploadService::staged_file( $ajax_one_token, $upload_product_id + 1, (string) $upload_gid, 'artwork' ) && null === OPF\Engine\UploadService::staged_file( $ajax_one_token, $upload_product_id, 'wrong-group', 'artwork' ) && null === OPF\Engine\UploadService::staged_file( $ajax_one_token, $upload_product_id, (string) $upload_gid, 'proof' ) );
$write_png();
$ajax_over_limit = OPF\Service\Rest::stage_upload( $make_upload_request( 'ajax-three.png', 'image/png', (int) filesize( $tmp_png ) ) );
check( 'upload ajax: staged count limit enforced before accepting another file', is_wp_error( $ajax_over_limit ) && 'opf_upload_limit' === $ajax_over_limit->get_error_code() );
$ajax_delete_request = new WP_REST_Request( 'DELETE', '/opf/v1/uploads/' . $ajax_two_token );
$ajax_delete_request->set_param( 'token', $ajax_two_token );
$ajax_delete = OPF\Service\Rest::delete_staged_upload( $ajax_delete_request );
check( 'upload ajax: owner can remove staged upload', ! is_wp_error( $ajax_delete ) && null === OPF\Engine\UploadService::staged_file( $ajax_two_token, $upload_product_id, (string) $upload_gid, 'artwork' ) && ! is_file( (string) OPF\Engine\UploadService::path( $ajax_two_token ) ) );
$write_png();
$ajax_expired = OPF\Service\Rest::stage_upload( $make_upload_request( 'ajax-expired.png', 'image/png', (int) filesize( $tmp_png ) ) );
$ajax_expired_token = ! is_wp_error( $ajax_expired ) ? (string) ( $ajax_expired->data['token'] ?? '' ) : '';
$ajax_staged_tokens[] = $ajax_expired_token;
$staged_registry = (array) $session->get( 'opf_staged_uploads', [] );
if ( isset( $staged_registry[ $ajax_expired_token ] ) ) {
	$staged_registry[ $ajax_expired_token ]['created'] = time() - DAY_IN_SECONDS - 1;
	$session->set( 'opf_staged_uploads', $staged_registry );
}
$expired_file_path = (string) OPF\Engine\UploadService::path( $ajax_expired_token );
$count_after_expiry = OPF\Engine\UploadService::staged_count( $upload_product_id, (string) $upload_gid, 'artwork' );
check( 'upload ajax: expired staging ticket is pruned and private file deleted', '' !== $ajax_expired_token && 1 === $count_after_expiry && null === OPF\Engine\UploadService::staged_file( $ajax_expired_token, $upload_product_id, (string) $upload_gid, 'artwork' ) && ! is_file( $expired_file_path ) );
$write_png();
$ajax_too_large = OPF\Service\Rest::stage_upload( $make_upload_request( 'ajax-large.png', 'image/png', 200000 ) );
check( 'upload ajax: field size limit enforced', is_wp_error( $ajax_too_large ) && 'opf_upload_invalid' === $ajax_too_large->get_error_code() );
$write_png();
$ajax_wrong_type = OPF\Service\Rest::stage_upload( $make_upload_request( 'ajax-file.pdf', 'application/pdf', (int) filesize( $tmp_png ) ) );
check( 'upload ajax: disallowed extension rejected', is_wp_error( $ajax_wrong_type ) && 'opf_upload_invalid' === $ajax_wrong_type->get_error_code() );
$saved_staged = (array) $session->get( 'opf_staged_uploads', [] );
$session->set( 'opf_staged_uploads', [] );
$other_session_request = new WP_REST_Request( 'DELETE', '/opf/v1/uploads/' . $ajax_one_token );
$other_session_request->set_param( 'token', $ajax_one_token );
$other_session_delete = OPF\Service\Rest::delete_staged_upload( $other_session_request );
$session->set( 'opf_staged_uploads', $saved_staged );
check( 'upload ajax: a different session cannot delete staged token', is_wp_error( $other_session_delete ) && is_file( (string) OPF\Engine\UploadService::path( $ajax_one_token ) ) );
$_FILES = [];
$_POST['opf_upload_tokens'] = [ (string) $upload_gid => [ 'artwork' => [ $ajax_one_token ] ] ];
$cart->empty_cart();
wc_clear_notices();
$upload_group_data['fields'][2]['required'] = true;
OPF\Service\FieldGroups::save( $upload_gid, new OPF\Engine\FieldGroup( $upload_group_data ), [ 'title' => 'E2E Upload Group' ] );
$_POST['opf'] = [ (string) $upload_gid => [ 'notes' => '', 'file_choice' => 'yes' ] ];
$retry_rejected = apply_filters( 'woocommerce_add_to_cart_validation', true, $upload_product_id, 1 );
check( 'upload ajax: main-form validation failure preserves staged ticket for retry', false === $retry_rejected && null !== OPF\Engine\UploadService::staged_file( $ajax_one_token, $upload_product_id, (string) $upload_gid, 'artwork' ) );
wc_clear_notices();
$upload_group_data['fields'][2]['required'] = false;
OPF\Service\FieldGroups::save( $upload_gid, new OPF\Engine\FieldGroup( $upload_group_data ), [ 'title' => 'E2E Upload Group' ] );
$_POST['opf'] = [ (string) $upload_gid => [ 'notes' => 'hide', 'file_choice' => 'yes' ] ];
$ajax_cart_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $upload_product_id, 1 );
$ajax_cart_key = $cart->add_to_cart( $upload_product_id, 1 );
$ajax_cart_item = $cart->get_cart_item( $ajax_cart_key );
check( 'upload ajax: staged ticket passes classic add-to-cart and attaches private file', true === $ajax_cart_valid && false !== $ajax_cart_key && count( (array) ( $ajax_cart_item['opf_files'][ (string) $upload_gid ]['artwork'] ?? [] ) ) === 1 );
check( 'upload ajax: ticket consumed only after cart insertion', null === OPF\Engine\UploadService::staged_file( $ajax_one_token, $upload_product_id, (string) $upload_gid, 'artwork' ) && is_file( (string) OPF\Engine\UploadService::path( $ajax_one_token ) ) );
$cart->empty_cart();
OPF\Engine\UploadService::delete( $ajax_one_token );
unset( $_POST['opf'], $_POST['opf_upload_tokens'] );

// Block cart payload carries opaque tickets through the same session-bound validator.
$write_png();
$ajax_store_file = OPF\Service\Rest::stage_upload( $make_upload_request( 'block-upload.png', 'image/png', (int) filesize( $tmp_png ) ) );
$ajax_store_token = ! is_wp_error( $ajax_store_file ) ? (string) ( $ajax_store_file->data['token'] ?? '' ) : '';
$ajax_staged_tokens[] = $ajax_store_token;
$store_upload_request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$store_upload_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$store_upload_request->set_param( 'id', $upload_product_id );
$store_upload_request->set_param( 'quantity', 1 );
$store_upload_request->set_param( 'opf_fields', [ (string) $upload_gid => [ 'notes' => 'hide', 'file_choice' => 'yes' ] ] );
$store_upload_request->set_param( 'opf_upload_tokens', [ (string) $upload_gid => [ 'artwork' => [ $ajax_store_token ] ] ] );
$store_upload_request->set_header( 'Content-Type', 'application/json' );
$store_upload_request->set_body( wp_json_encode( [
	'id'                 => $upload_product_id,
	'quantity'           => 1,
	'cart_item_data'     => [],
	'extensions'         => [],
	'opf_fields'         => [ (string) $upload_gid => [ 'notes' => 'hide', 'file_choice' => 'yes' ] ],
	'opf_upload_tokens'  => [ (string) $upload_gid => [ 'artwork' => [ $ajax_store_token ] ] ],
] ) );
$capture_probe = OPF\Service\CartIntegration::capture_store_api( [ 'cart_item_data' => [] ], $store_upload_request );
$probe_ticket_present = isset( $capture_probe['cart_item_data']['opf_upload_tokens_raw'][ (string) $upload_gid ]['artwork'][0] );
$attach_probe = OPF\Service\CartIntegration::attach( (array) $capture_probe['cart_item_data'], $upload_product_id, 0, 1 );
$probe_file_count = count( (array) ( $attach_probe['opf_files'][ (string) $upload_gid ]['artwork'] ?? [] ) );
WP_CLI::log( '        Store API capture probe: ticket=' . ( $probe_ticket_present ? 'yes' : 'no' ) . ' direct_attach_files=' . $probe_file_count );
$store_upload_response = rest_get_server()->dispatch( $store_upload_request );
$store_upload_line = array_values( $cart->get_cart() )[0] ?? [];
$store_upload_data = $store_upload_response->get_data();
$store_error_code = is_array( $store_upload_data ) ? (string) ( $store_upload_data['code'] ?? '' ) : '';
$store_file_count = count( (array) ( $store_upload_line['opf_files'][ (string) $upload_gid ]['artwork'] ?? [] ) );
WP_CLI::log( '        Store API upload diagnostic: status=' . $store_upload_response->get_status() . ' error=' . $store_error_code . ' cart_items=' . count( $cart->get_cart() ) . ' files=' . $store_file_count . ' ticket_owned=' . ( null !== OPF\Engine\UploadService::staged_file( $ajax_store_token, $upload_product_id, (string) $upload_gid, 'artwork' ) ? 'yes' : 'no' ) );
check( 'upload ajax: Store API add-to-cart attaches session-owned staged file', '' !== $ajax_store_token && in_array( $store_upload_response->get_status(), [ 200, 201 ], true ) && count( (array) ( $store_upload_line['opf_files'][ (string) $upload_gid ]['artwork'] ?? [] ) ) === 1 );
check( 'upload ajax: Store API consumes ticket after insertion', null === OPF\Engine\UploadService::staged_file( $ajax_store_token, $upload_product_id, (string) $upload_gid, 'artwork' ) && is_file( (string) OPF\Engine\UploadService::path( $ajax_store_token ) ) );
$cart->empty_cart();
OPF\Engine\UploadService::delete( $ajax_store_token );

$tmp_pdf = tempnam( sys_get_temp_dir(), 'opf-e2e' );
$tmp_pdf = $tmp_pdf . '.pdf';
file_put_contents( $tmp_pdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF" );

// Missing required upload is rejected.
unset( $_FILES['opf'] );
wc_clear_notices();
$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $upload_product_id, 1 );
check( 'upload: required file rejected when missing', false === $passed );

// Over-sized file is rejected before storage.
$write_png();
$_FILES['opf'] = $upload_files( $upload_gid, 'artwork', 'big.png', 'image/png', $tmp_png, 200000 );
wc_clear_notices();
$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $upload_product_id, 1 );
check( 'upload: over-size file rejected', false === $passed );

// Disallowed type is rejected.
$write_png();
$_FILES['opf'] = $upload_files( $upload_gid, 'artwork', 'spec.pdf', 'application/pdf', $tmp_png, 100 );
wc_clear_notices();
$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $upload_product_id, 1 );
check( 'upload: disallowed type rejected', false === $passed );

// A spoofed MIME on a wildcard allow-list cannot smuggle non-image bytes.
$_FILES['opf'] = $upload_files( $upload_gid, 'proof', 'spec.pdf', 'image/png', $tmp_pdf, 100 );
wc_clear_notices();
$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $upload_product_id, 1 );
check( 'upload: spoofed MIME rejected at storage', false === $passed );

// Markup/executable bytes are refused even when WordPress would allow the type.
$write_png();
$blocked_probe = OPF\Engine\UploadService::store(
	[ 'name' => 'probe.html', 'type' => 'text/html', 'tmp_name' => $tmp_png, 'error' => UPLOAD_ERR_OK, 'size' => 100 ],
	OPF\Engine\FieldGroup::normalize_field( [ 'id' => 'artwork', 'type' => 'upload', 'label' => 'Artwork' ] )
);
check( 'upload: html bytes never stored', null === $blocked_probe );

// Valid upload is accepted, stored privately, and attached to the cart line.
$write_png();
$tmp_png_two = tempnam( sys_get_temp_dir(), 'opf-e2e' ) . '.png';
file_put_contents( $tmp_png_two, (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABh6FO1AAAAABJRU5ErkJggg==' ) );
$tmp_hidden_png = tempnam( sys_get_temp_dir(), 'opf-e2e' ) . '.png';
file_put_contents( $tmp_hidden_png, (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABh6FO1AAAAABJRU5ErkJggg==' ) );
$size_ok       = filesize( $tmp_png );
$artwork_uploads = $upload_multiple_files(
	$upload_gid,
	'artwork',
	[
		[ 'name' => 'spec.png', 'type' => 'image/png', 'tmp_name' => $tmp_png, 'error' => UPLOAD_ERR_OK, 'size' => (int) $size_ok ],
		[ 'name' => 'spec-two.png', 'type' => 'image/png', 'tmp_name' => $tmp_png_two, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize( $tmp_png_two ) ],
	]
);
$proof_upload = $upload_files( $upload_gid, 'proof', 'hidden-proof.png', 'image/png', $tmp_hidden_png, (int) filesize( $tmp_hidden_png ) );
$_FILES['opf'] = array_replace_recursive( $artwork_uploads, $proof_upload );
$_POST['opf'] = [ (string) $upload_gid => [ 'notes' => 'hide', 'file_choice' => 'yes' ] ];
$cart->empty_cart();
$passed   = apply_filters( 'woocommerce_add_to_cart_validation', true, $upload_product_id, 1 );
$upload_key = $cart->add_to_cart( $upload_product_id, 1 );
unset( $_FILES['opf'] );
unset( $_POST['opf'] );
$cart->calculate_totals();
check( 'upload: valid file accepted', true === $passed && false !== $upload_key );

$upload_item = $cart->get_cart_item( $upload_key );
$stored_files = $upload_item['opf_files'][ (string) $upload_gid ]['artwork'] ?? [];
$stored_tokens = array_values( array_filter( array_map( static fn( $file ): string => is_array( $file ) ? (string) ( $file['token'] ?? '' ) : '', $stored_files ) ) );
$token = is_array( $stored_files ) && isset( $stored_files[0]['token'] ) ? (string) $stored_files[0]['token'] : '';
check( 'upload: files formula counts only stored uploads and exposes dependent option', count( $stored_files ) === 2 && abs( (float) $upload_item['data']->get_price() - 97.0 ) < 0.001 && ( $upload_item['opf_fields'][ (string) $upload_gid ]['file_choice'] ?? '' ) === 'yes' );
check( 'upload: formula source uses two unique private tokens', count( array_unique( $stored_tokens ) ) === 2 );
check( 'upload: opaque token attached to cart', 1 === preg_match( '/^[a-f0-9]{48}$/', $token ) );
check( 'upload: original name retained as metadata', 'spec.png' === ( $stored_files[0]['name'] ?? '' ) );
$stored_path = '' !== $token ? OPF\Engine\UploadService::path( $token ) : null;
check( 'upload: stored under private uploads dir', is_string( $stored_path ) && false !== strpos( $stored_path, '.opf-private' ) && is_file( $stored_path ) );
$public_copies = [];
$upload_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( wp_upload_dir()['basedir'], FilesystemIterator::SKIP_DOTS ) );
foreach ( $upload_iterator as $upload_entry ) {
	if ( $upload_entry->isFile() && 'spec.png' === $upload_entry->getFilename() ) {
		$public_copies[] = (string) $upload_entry;
	}
}
check( 'upload: original filename never lands in public uploads', empty( $public_copies ) );

// Cart/checkout display lists the filename.
$display_files = apply_filters( 'woocommerce_get_item_data', [], $upload_item );
$upload_labels = wp_list_pluck( $display_files, 'name' );
check( 'upload: cart display lists field label', in_array( 'Artwork', $upload_labels, true ) );

// Persist to a real order.
$upload_order = wc_create_order();
$upload_item_id = $upload_order->add_product( $upload_item['data'], 1 );
$upload_order_item = $upload_order->get_item( $upload_item_id );
do_action( 'woocommerce_checkout_create_order_line_item', $upload_order_item, $upload_key, $upload_item, $upload_order );
$upload_order_item->save();
$upload_order->update_status( 'processing' );
$upload_order->save();

$saved_files = $upload_order_item->get_meta( '_opf_files', true );
$saved_tokens = $upload_order_item->get_meta( '_opf_upload_tokens', true );
check( 'upload: order item keeps file references', is_string( $saved_files ) && false !== strpos( $saved_files, $token ) );
check( 'upload: order item keeps token list', is_array( $saved_tokens ) && in_array( $token, $saved_tokens, true ) );
check( 'upload: order-level tokens recorded', in_array( $token, (array) $upload_order->get_meta( '_opf_upload_tokens', true ), true ) );
check( 'upload: order line total includes file-count price and calculation-dependent choice', abs( (float) $upload_order_item->get_total() - 97.0 ) < 0.001 && 'File option' === $upload_order_item->get_meta( 'File count option', true ) );
check( 'upload: referenced token reported live', OPF\Engine\UploadService::is_referenced( $token ) );

$admin_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
$admin_id  = (int) ( $admin_ids[0] ?? 0 );
// Empty the cart first so the manager check cannot pass through the session.
$cart->empty_cart();
if ( $admin_id ) {
	wp_set_current_user( $admin_id );
	check( 'upload: manager can access stored token', OPF\Engine\UploadService::viewer_can_access( $token, $admin_id ) );
}
wp_set_current_user( 0 );
check( 'upload: anonymous non-owner denied', ! OPF\Engine\UploadService::viewer_can_access( $token ) );
check( 'upload: malformed token denied', ! OPF\Engine\UploadService::viewer_can_access( str_repeat( 'g', 48 ) ) );
check( 'upload: guest order key grants access', OPF\Engine\UploadService::viewer_can_access( $token, 0, (string) $upload_order->get_order_key() ) );
check( 'upload: forged order key denied', ! OPF\Engine\UploadService::viewer_can_access( $token, 0, 'wc_order_forged' ) );

// A different customer is denied.
$other_customer = wp_insert_user( [ 'user_login' => 'opf-e2e-other-' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'customer' ] );
if ( ! is_wp_error( $other_customer ) ) {
	wp_set_current_user( (int) $other_customer );
	check( 'upload: unrelated customer denied', ! OPF\Engine\UploadService::viewer_can_access( $token ) );
	wp_set_current_user( 0 );
	wp_delete_user( (int) $other_customer );
}

// Order-again clones the previous order's uploads into a new cart item and
// the new order owns an independent copy.
$reorder_files = apply_filters( 'woocommerce_order_again_cart_item_data', [], $upload_order_item, $upload_order );
$reorder_token = (string) ( $reorder_files['opf_files'][ (string) $upload_gid ]['artwork'][0]['token'] ?? '' );
check( 'upload: order-again duplicates the file', '' !== $reorder_token && $reorder_token !== $token );
$reorder_path = '' !== $reorder_token ? OPF\Engine\UploadService::path( $reorder_token ) : null;
check( 'upload: duplicated file exists and matches', is_string( $reorder_path ) && is_file( $reorder_path ) && md5_file( $reorder_path ) === md5_file( (string) $stored_path ) );

// The inherited uploads satisfy the required field during reorder validation.
$reorder_passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $upload_product_id, 1, 0, [], $reorder_files );
check( 'upload: order-again validation accepts inherited files', true === $reorder_passed );

// Trashing the order keeps the file; only a permanent delete removes it.
$upload_order->update_status( 'trash' );
check( 'upload: trashed order still references file', OPF\Engine\UploadService::is_referenced( $token ) );
$upload_order = wc_get_order( $upload_order->get_id() );
$upload_order->delete( true );
check( 'upload: file removed with its order', ! is_file( (string) $stored_path ) );
check( 'upload: duplicated copy survives original order', is_file( (string) $reorder_path ) );
OPF\Engine\UploadService::delete( $reorder_token );

// Orphan expiration removes unreferenced old files only. The earlier store
// consumed the temp file, so write a fresh one.
$write_png();
$_FILES['opf'] = $upload_files( $upload_gid, 'artwork', 'orphan.png', 'image/png', $tmp_png, (int) $size_ok );
$cart->empty_cart();
apply_filters( 'woocommerce_add_to_cart_validation', true, $upload_product_id, 1 );
$orphan_key = $cart->add_to_cart( $upload_product_id, 1 );
unset( $_FILES['opf'] );
$orphan_item  = $cart->get_cart_item( $orphan_key );
$orphan_token = (string) ( $orphan_item['opf_files'][ (string) $upload_gid ]['artwork'][0]['token'] ?? '' );
$cart->empty_cart();
$orphan_path = '' !== $orphan_token ? OPF\Engine\UploadService::path( $orphan_token ) : null;
if ( is_string( $orphan_path ) && is_file( $orphan_path ) ) {
	touch( $orphan_path, time() - ( 8 * DAY_IN_SECONDS ) );
}
$removed = OPF\Engine\UploadService::cleanup_orphans( WEEK_IN_SECONDS );
check( 'upload: orphaned old file cleaned up', $removed >= 1 && ! is_file( (string) $orphan_path ) );

// ------------------------------------------------ button repeater lifecycle.
$cart->empty_cart();
$repeat_product = wc_get_product( $repeat_product_id );
$repeat_names = [ 'Second guest', 'First guest' ];
$_POST['opf'] = [ (string) $repeat_group_id => [ 'names' => $repeat_names ], (string) $quantity_repeat_group_id => [ 'unit_name' => [ 'Unit one' ] ] ];
$repeat_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $repeat_product_id, 1 );
unset( $_POST['opf'] );
check( 'repeater: each required row is validated by production add-to-cart hook', true === $repeat_valid );
$_POST['opf'] = [ (string) $repeat_group_id => [ 'names' => [ 'One', '' ] ], (string) $quantity_repeat_group_id => [ 'unit_name' => [ 'Unit one' ] ] ];
$blank_row_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $repeat_product_id, 1 );
unset( $_POST['opf'] );
check( 'repeater: blank required instance is rejected', false === $blank_row_valid );
$_POST['opf'] = [ (string) $repeat_group_id => [ 'names' => [ 'One', 'Two', 'Three' ] ], (string) $quantity_repeat_group_id => [ 'unit_name' => [ 'Unit one' ] ] ];
$overflow_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $repeat_product_id, 1 );
unset( $_POST['opf'] );
check( 'repeater: forged rows above configured maximum are rejected', false === $overflow_valid );
$_POST['opf'] = [ (string) $repeat_group_id => [ 'names' => $repeat_names ], (string) $quantity_repeat_group_id => [ 'unit_name' => [ 'Unit one' ] ] ];
$repeat_key = $cart->add_to_cart( $repeat_product_id, 1 );
unset( $_POST['opf'] );
$repeat_item = $repeat_key ? $cart->get_cart_item( $repeat_key ) : [];
$repeat_values = $repeat_item['opf_fields'][ (string) $repeat_group_id ]['names'] ?? [];
check( 'repeater: cart preserves submitted row order', $repeat_values === $repeat_names );
$repeat_display = OPF\Service\CartIntegration::visible_selections( $repeat_product, $repeat_item['opf_fields'] ?? [] );
$repeat_display_row = array_values( array_filter( $repeat_display, static fn( array $row ): bool => 'Guest names' === ( $row['label'] ?? '' ) ) );
check( 'repeater: cart displays each ordered value', ( $repeat_display_row[0]['value'] ?? '' ) === '1. Second guest; 2. First guest' );
check( 'repeater: repeat rows do not change product price', isset( $repeat_item['data'] ) && abs( (float) $repeat_item['data']->get_price() - 25.0 ) < 0.001 );

$repeat_order = wc_create_order();
$repeat_order_item_id = $repeat_order->add_product( $repeat_product, 1 );
$repeat_order_item = $repeat_order->get_item( $repeat_order_item_id );
do_action( 'woocommerce_checkout_create_order_line_item', $repeat_order_item, $repeat_key, $repeat_item, $repeat_order );
$repeat_order_item->save();
$repeat_order->save();
$repeat_stored = json_decode( (string) $repeat_order_item->get_meta( '_opf_fields', true ), true );
check( 'repeater: order stores ordered structured rows and display', ( $repeat_stored[ (string) $repeat_group_id ]['names'] ?? [] ) === $repeat_names && $repeat_order_item->get_meta( 'Guest names', true ) === '1. Second guest; 2. First guest' );
$repeat_again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $repeat_order_item, $repeat_order );
check( 'repeater: order-again restores ordered rows', ( $repeat_again['opf_fields'][ (string) $repeat_group_id ]['names'] ?? [] ) === $repeat_names );

// ------------------------------------------- quantity-driven repeater lifecycle.
$cart->empty_cart();
$quantity_repeat_names = [ 'Red', 'Blue', 'Green' ];
$_POST['opf'] = [
	(string) $repeat_group_id => [ 'names' => [ 'Companion' ] ],
	(string) $quantity_repeat_group_id => [ 'unit_name' => $quantity_repeat_names ],
];
$quantity_repeat_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $repeat_product_id, 3 );
unset( $_POST['opf'] );
check( 'quantity repeater: production add-to-cart accepts exactly one value per unit', true === $quantity_repeat_valid );
$_POST['opf'] = [
	(string) $repeat_group_id => [ 'names' => [ 'Companion' ] ],
	(string) $quantity_repeat_group_id => [ 'unit_name' => [ 'Only one' ] ],
];
$quantity_mismatch_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $repeat_product_id, 3 );
unset( $_POST['opf'] );
check( 'quantity repeater: production add-to-cart rejects a row-count mismatch', false === $quantity_mismatch_valid );
wc_clear_notices();
$quantity_over_limit_valid = apply_filters( 'woocommerce_add_to_cart_validation', true, $repeat_product_id, 2000 );
$quantity_over_limit_notices = implode( ' ', array_map( static fn( array $notice ): string => (string) ( $notice['notice'] ?? '' ), wc_get_notices( 'error' ) ) );
check( 'quantity repeater: production add-to-cart rejects over-budget quantity with an actionable notice', false === $quantity_over_limit_valid && false !== strpos( $quantity_over_limit_notices, 'supports at most' ) );
wc_clear_notices();
$_POST['opf'] = [
	(string) $repeat_group_id => [ 'names' => [ 'Companion' ] ],
	(string) $quantity_repeat_group_id => [ 'unit_name' => $quantity_repeat_names ],
];
$quantity_repeat_key = $cart->add_to_cart( $repeat_product_id, 3 );
unset( $_POST['opf'] );
$quantity_repeat_item = $quantity_repeat_key ? $cart->get_cart_item( $quantity_repeat_key ) : [];
$quantity_repeat_values = $quantity_repeat_item['opf_fields'][ (string) $quantity_repeat_group_id ]['unit_name'] ?? [];
check( 'quantity repeater: classic cart preserves one ordered value per unit', $quantity_repeat_values === $quantity_repeat_names );
$quantity_repeat_display = OPF\Service\CartIntegration::visible_selections( $repeat_product, $quantity_repeat_item['opf_fields'] ?? [] );
$quantity_display_row = array_values( array_filter( $quantity_repeat_display, static fn( array $row ): bool => 'Name for each unit' === ( $row['label'] ?? '' ) ) );
check( 'quantity repeater: cart display preserves row order', ( $quantity_display_row[0]['value'] ?? '' ) === '1. Red; 2. Blue; 3. Green' );
$cart->set_quantity( $quantity_repeat_key, 2, false );
$edited_quantity_item = $cart->get_cart_item( $quantity_repeat_key );
check( 'quantity repeater: cart quantity edit preserves submitted rows but does not resize them (remaining parity gap)', (int) ( $edited_quantity_item['quantity'] ?? 0 ) === 2 && ( $edited_quantity_item['opf_fields'][ (string) $quantity_repeat_group_id ]['unit_name'] ?? [] ) === $quantity_repeat_names );
$cart->set_quantity( $quantity_repeat_key, 3, false );

$quantity_order = wc_create_order();
$quantity_order_item_id = $quantity_order->add_product( $repeat_product, 3 );
$quantity_order_item = $quantity_order->get_item( $quantity_order_item_id );
do_action( 'woocommerce_checkout_create_order_line_item', $quantity_order_item, $quantity_repeat_key, $quantity_repeat_item, $quantity_order );
$quantity_order_item->save();
$quantity_order->save();
$quantity_order_values = json_decode( (string) $quantity_order_item->get_meta( '_opf_fields', true ), true );
check( 'quantity repeater: order persists all ordered values', ( $quantity_order_values[ (string) $quantity_repeat_group_id ]['unit_name'] ?? [] ) === $quantity_repeat_names );
$quantity_order_again = apply_filters( 'woocommerce_order_again_cart_item_data', [], $quantity_order_item, $quantity_order );
check( 'quantity repeater: order-again restores all ordered values', ( $quantity_order_again['opf_fields'][ (string) $quantity_repeat_group_id ]['unit_name'] ?? [] ) === $quantity_repeat_names );

$cart->empty_cart();
$repeat_request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$repeat_request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
$repeat_request->set_param( 'id', $repeat_product_id );
$repeat_request->set_param( 'quantity', 1 );
$repeat_request->set_param( 'opf_fields', [
	(string) $repeat_group_id => [ 'names' => [ 'Store Second', 'Store First' ] ],
	(string) $quantity_repeat_group_id => [ 'unit_name' => [ 'Store Red', 'Store Blue' ] ],
] );
$repeat_request->set_param( 'quantity', 2 );
$repeat_response = rest_get_server()->dispatch( $repeat_request );
$repeat_store_items = $cart->get_cart();
$repeat_store_item = $repeat_store_items ? reset( $repeat_store_items ) : [];
check( 'repeater: Store API accepts and preserves ordered button rows', in_array( $repeat_response->get_status(), [ 200, 201 ], true ) && ( $repeat_store_item['opf_fields'][ (string) $repeat_group_id ]['names'] ?? [] ) === [ 'Store Second', 'Store First' ] );
check( 'quantity repeater: Store API preserves one ordered value per unit', ( $repeat_store_item['opf_fields'][ (string) $quantity_repeat_group_id ]['unit_name'] ?? [] ) === [ 'Store Red', 'Store Blue' ] );

remove_filter( 'opf_upload_is_uploaded_file', '__return_true' );
unset( $_POST['opf'] );
foreach ( [ $tmp_png, $tmp_pdf ] as $tmp_cleanup ) {
	if ( file_exists( $tmp_cleanup ) ) {
		unlink( $tmp_cleanup );
	}
}
wp_set_current_user( 0 );

// ------------------------------------------------------------------ wrapup.
$cart->empty_cart();
wc_clear_notices();

WP_CLI::log( '' );
if ( $failures > 0 ) {
	WP_CLI::error( "$failures check(s) failed." );
} else {
	WP_CLI::success( 'All E2E checks passed.' );
}
