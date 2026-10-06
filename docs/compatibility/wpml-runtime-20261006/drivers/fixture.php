<?php
/**
 * Disposable-clone fixture for the WPML runtime proof (WAPF-LOCALE-WPML).
 *
 * Creates EN terms, an EN simple product, its ES translation linked through
 * WPML's translation group, and an OPF field group whose placement rules
 * target those EN elements (so the runtime must remap them through
 * wpml_object_id to keep matching in Spanish).
 *
 * Runs under `wp eval-file` inside /tmp/opf-wpml-20261006/wp only.
 */
global $wpdb, $sitepress;

if ( 0 !== strpos( (string) realpath( ABSPATH ), '/var/www/html' ) || ! str_contains( (string) home_url(), '127.0.0.1:8097' ) ) {
	throw new RuntimeException( 'Refusing to run outside the disposable /tmp clone.' );
}

// Package tables are created on admin page loads; WP-CLI never reaches that path.
WPML_Package_Translation_Schema::run_update();

$out = [];

// ---- terms ---------------------------------------------------------------
$cat = wp_insert_term( 'Gifts', 'product_cat' );
if ( is_wp_error( $cat ) ) {
	$cat = get_term_by( 'slug', 'gifts', 'product_cat' );
}
$cat_id = (int) ( is_array( $cat ) ? $cat['term_id'] : $cat->term_id );

$tag = wp_insert_term( 'handmade', 'product_tag' );
if ( is_wp_error( $tag ) ) {
	$tag = get_term_by( 'slug', 'handmade', 'product_tag' );
}
$tag_id = (int) ( is_array( $tag ) ? $tag['term_id'] : $tag->term_id );

// ---- EN product ----------------------------------------------------------
$product = new WC_Product_Simple();
$product->set_name( 'Gift Card' );
$product->set_slug( 'gift-card' );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'visible' );
$product->set_regular_price( '20' );
$product->set_price( '20' );
$product->set_sku( 'opf-wpml-gift' );
$product->set_category_ids( [ $cat_id ] );
$product->set_tag_ids( [ $tag_id ] );
$en_product_id = (int) $product->save();

// ---- ES product, linked as a WPML translation of the EN product ----------
$translated = new WC_Product_Simple();
$translated->set_name( 'Tarjeta Regalo' );
$translated->set_slug( 'tarjeta-regalo' );
$translated->set_status( 'publish' );
$translated->set_catalog_visibility( 'visible' );
$translated->set_regular_price( '20' );
$translated->set_price( '20' );
// Translation shares the EN SKU in WCML, but WC's own uniqueness guard only
// knows WCML's bypass; leave it unset so the clone creates the pair directly.
$translated->set_category_ids( [ $cat_id ] );
$translated->set_tag_ids( [ $tag_id ] );
$es_product_id = (int) $translated->save();

$en_language = apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $en_product_id, 'element_type' => 'product' ] );
if ( '' === (string) $en_language ) {
	do_action(
		'wpml_set_element_language_details',
		[
			'element_id'    => $en_product_id,
			'element_type'  => 'post_product',
			'trid'          => null,
			'language_code' => 'en',
		]
	);
}
$en_language = apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $en_product_id, 'element_type' => 'product' ] );
$trid        = apply_filters( 'wpml_element_trid', null, $en_product_id, 'post_product' );

do_action(
	'wpml_set_element_language_details',
	[
		'element_id'           => $es_product_id,
		'element_type'         => 'post_product',
		'trid'                 => $trid,
		'language_code'        => 'es',
		'source_language_code' => 'en',
	]
);

$out['en_product_id']   = $en_product_id;
$out['es_product_id']   = $es_product_id;
$out['en_language']     = (string) $en_language;
$out['es_language']     = (string) apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $es_product_id, 'element_type' => 'product' ] );
$out['trid']            = $trid;
$out['cat_id']          = $cat_id;
$out['tag_id']          = $tag_id;
$out['es_cat_remap']    = apply_filters( 'wpml_object_id', $cat_id, 'product_cat', true, 'es' );
$out['es_tag_remap']    = apply_filters( 'wpml_object_id', $tag_id, 'product_tag', true, 'es' );
$out['es_product_remap'] = apply_filters( 'wpml_object_id', $en_product_id, 'product', true, 'es' );

// ---- OPF field group -----------------------------------------------------
$group = [
	'schema'  => 1,
	'fields'  => [
		[
			'id'          => 'gift',
			'type'        => 'select',
			'label'       => 'Gift wrap',
			'description' => 'Choose wrapping',
			'placeholder' => 'Select an option',
			'choices'     => [
				[ 'slug' => 'red', 'label' => 'Red', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				[ 'slug' => 'blue', 'label' => 'Blue', 'pricing' => [ 'type' => 'fixed', 'amount' => 0 ] ],
			],
		],
		[
			'id'          => 'note',
			'type'        => 'text',
			'label'       => 'Note',
			'placeholder' => 'Add a note',
		],
		[
			'id'             => 'info',
			'type'           => 'paragraph',
			'content'        => '<b>Information</b>',
			'content_format' => 'html',
		],
		[
			'id'      => 'extra',
			'type'    => 'select',
			'label'   => 'Extras',
			'choices' => [ [ 'slug' => 'card', 'label' => 'Card' ] ],
			'repeat'  => [ 'enabled' => true, 'mode' => 'button', 'add' => 'Add another', 'del' => 'Remove', 'label' => 'Copy {n}' ],
		],
	],
	'rule_groups' => [
		[
			'rules' => [
				[ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $en_product_id ] ],
				[ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ (string) $cat_id ] ],
				[ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ (string) $tag_id ] ],
			],
		],
	],
];

$group_id = \OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'Gift options' ] );
$out['group_id'] = $group_id;

update_option( 'opf_proof_ids', $out, false );

echo wp_json_encode( $out, JSON_PRETTY_PRINT ), "\n";
