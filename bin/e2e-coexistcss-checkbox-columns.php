<?php
/**
 * Disposable WordPress fixture for the OPF/WAPF shared-class CSS coexistence
 * check (`assets/css/opf-frontend.css` vs WAPF Extended 3.1.5's
 * `.wapf-checkboxes,.wapf-radios{grid-template-columns:auto}`).
 *
 * Builds one product that renders, side by side:
 *   - an OPF checkbox field with `columns:4` (OPF wrapper
 *     `.opf-swatch-wrapper.opf-checkboxes--columns.wapf-checkboxes`)
 *   - an OPF radio field (OPF wrapper `.opf-swatch-wrapper.wapf-radios`)
 *   - a WAPF Extended 3.1.5 native checkbox field (WAPF wrapper
 *     `.wapf-checkboxes`, no `opf-*` classes)
 *   - a WAPF Extended 3.1.5 native radio field (WAPF wrapper `.wapf-radios`)
 *
 * Usage (disposable clone only):
 *   wp --path=<clone> --allow-root eval-file bin/e2e-coexistcss-checkbox-columns.php prepare
 *   wp --path=<clone> --allow-root eval-file bin/e2e-coexistcss-checkbox-columns.php verify
 *   wp --path=<clone> --allow-root eval-file bin/e2e-coexistcss-checkbox-columns.php cleanup
 *
 * @package open-product-fields-for-woocommerce
 */

use OPF\Engine\FieldGroup as OpfFieldGroup;
use OPF\Service\FieldGroups as OpfFieldGroups;
use SW_WAPF_PRO\Includes\Classes\Field_Groups as WapfFieldGroups;
use SW_WAPF_PRO\Includes\Models\FieldGroup as WapfFieldGroup;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( OpfFieldGroups::class ) || ! class_exists( WapfFieldGroups::class ) ) {
	throw new RuntimeException( 'Both OPF and WAPF Extended must be active on the disposable clone.' );
}

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state  = (array) get_option( 'opf_coexistcss_state', [] );

if ( 'cleanup' === $action ) {
	foreach ( (array) ( $state['post_ids'] ?? [] ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	if ( ! empty( $state['product_id'] ) ) {
		wp_delete_post( (int) $state['product_id'], true );
	}
	OpfFieldGroups::flush_cache();
	if ( function_exists( 'wapf_cache' ) ) {
		// WAPF caches rendered groups; clear it so cleanup does not leave stale ids.
	}
	delete_option( 'opf_coexistcss_state' );
	WP_CLI::success( 'Coexistence CSS fixtures removed.' );
	return;
}

if ( 'verify' === $action ) {
	$product_id = (int) ( $state['product_id'] ?? 0 );
	if ( ! $product_id || ! get_post( $product_id ) ) {
		WP_CLI::error( 'Fixture product is missing.' );
	}
	$url = get_permalink( $product_id );
	WP_CLI::log( 'COEXIST_CSS_URL=' . $url );
	WP_CLI::success( 'Coexistence CSS fixture is present.' );
	return;
}

if ( $state ) {
	WP_CLI::error( 'Fixture already exists; run cleanup first.' );
}

// -------------------------------------------------------------------------
// Product.
// -------------------------------------------------------------------------
$product = new WC_Product_Simple();
$product->set_name( 'OPF/WAPF Coexistence CSS Product' );
$product->set_slug( 'opf-wapf-coexistcss-product' );
$product->set_regular_price( '10.00' );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'visible' );
$product_id = (int) $product->save();
if ( ! $product_id ) {
	WP_CLI::error( 'Could not create the fixture product.' );
}

// -------------------------------------------------------------------------
// OPF group: 4-column checkbox + radio.
// -------------------------------------------------------------------------
$opf_group = new OpfFieldGroup( [
	'fields' => [
		[
			'id'      => 'extras',
			'label'   => 'OPF extras',
			'type'    => 'checkbox',
			'columns' => 4,
			'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap' ],
				[ 'slug' => 'note', 'label' => 'Gift note' ],
				[ 'slug' => 'rush', 'label' => 'Rush packing' ],
				[ 'slug' => 'engrave', 'label' => 'Engraving' ],
			],
		],
		[
			'id'      => 'size',
			'label'   => 'OPF size',
			'type'    => 'radio',
			'choices' => [
				[ 'slug' => 's', 'label' => 'Small' ],
				[ 'slug' => 'm', 'label' => 'Medium' ],
				[ 'slug' => 'l', 'label' => 'Large' ],
			],
		],
	],
	'rule_groups' => [
		[ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ],
	],
] );
$opf_group_id = OpfFieldGroups::save( 0, $opf_group, [ 'title' => 'Coexist CSS OPF group', 'status' => 'publish' ] );
if ( ! $opf_group_id ) {
	WP_CLI::error( 'Could not save the OPF field group.' );
}

// -------------------------------------------------------------------------
// WAPF Extended 3.1.5 group: native checkboxes + radios.
// -------------------------------------------------------------------------
$wapf_choices = [
	[ 'slug' => 'w-small', 'label' => 'W Small', 'pricing_type' => 'none', 'pricing_amount' => 0 ],
	[ 'slug' => 'w-medium', 'label' => 'W Medium', 'pricing_type' => 'none', 'pricing_amount' => 0 ],
	[ 'slug' => 'w-large', 'label' => 'W Large', 'pricing_type' => 'none', 'pricing_amount' => 0 ],
	[ 'slug' => 'w-xl', 'label' => 'W X-Large', 'pricing_type' => 'none', 'pricing_amount' => 0 ],
];
$make_choice_field = static function ( string $id, string $label, string $type ) use ( $wapf_choices ): array {
	return [
		'id'            => $id,
		'label'         => $label,
		'description'   => '',
		'type'          => $type,
		'required'      => false,
		'class'         => '',
		'width'         => null,
		'parent_clone'  => [],
		'options'       => [
			'choices'     => $wapf_choices,
			'hide_cart'   => false,
			'hide_checkout' => false,
			'hide_order'  => false,
			'meta'        => '',
			'group'       => 'field',
		],
		'conditionals'  => [],
		'clone'         => [ 'enabled' => false ],
		'pricing'       => [ 'type' => 'none', 'amount' => 0.0, 'enabled' => false ],
	];
};
$wapf_array = [
	'id'          => 0,
	'type'        => 'wapf_product',
	'layout'      => [
		'labels_position'       => 'above',
		'instructions_position' => 'field',
		'mark_required'         => true,
		'enable_gallery_images' => false,
		'gallery_images'        => [],
	],
	'variables'   => [],
	'fields'      => [
		$make_choice_field( 'wapfwcsscb', 'WAPF checkboxes', 'checkboxes' ),
		$make_choice_field( 'wapfwcssradio', 'WAPF radios', 'radio' ),
	],
	'rule_groups' => [
		[ 'rules' => [ [ 'value' => [ $product_id ], 'condition' => 'product', 'subject' => 'product' ] ] ],
	],
];
$wapf_group = new WapfFieldGroup();
$wapf_group->from_array( $wapf_array );
$wapf_group_id = WapfFieldGroups::save( $wapf_group, 'wapf_product', null, 'Coexist CSS WAPF group', 'publish' );
if ( ! $wapf_group_id ) {
	WP_CLI::error( 'Could not save the WAPF field group.' );
}

update_option( 'opf_coexistcss_state', [
	'product_id'   => $product_id,
	'opf_group_id' => $opf_group_id,
	'wapf_group_id' => $wapf_group_id,
	'post_ids'     => [ $opf_group_id, $wapf_group_id ],
], false );

OpfFieldGroups::flush_cache();
if ( function_exists( 'wapf_cache' ) && method_exists( 'wapf_cache', 'clear' ) ) {
	wapf_cache::clear();
}

WP_CLI::log( 'COEXIST_CSS_URL=' . get_permalink( $product_id ) );
WP_CLI::success( 'Coexistence CSS fixture prepared.' );
