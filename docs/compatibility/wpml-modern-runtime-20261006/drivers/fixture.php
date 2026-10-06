<?php
/**
 * Disposable-clone fixture for the WPML 5.1.0 runtime proof (WAPF-LOCALE-WPML-MODERN).
 *
 * Creates translated product categories/tags, product A (EN "Gift Card") with
 * its ES translation ("Tarjeta Regalo"), an unrelated product B with its ES
 * translation, and an OPF field group whose placement rules target the EN
 * elements of product A only — so the runtime must remap them through
 * wpml_object_id to keep matching on the ES page, and must not match B.
 *
 * Runs under `wp eval-file` inside /tmp/opf-wpml-modern-wp/stack510 only.
 */
global $wpdb, $sitepress;

if ( 0 !== strpos( (string) realpath( ABSPATH ), '/var/www/html' ) || ! str_contains( (string) home_url(), '127.0.0.1:8096' ) ) {
	throw new RuntimeException( 'Refusing to run outside the disposable /tmp clone.' );
}

// Package tables are created on admin page loads; WP-CLI never reaches that
// path, so drive WPML's own schema update exactly as the admin would.
WPML_Package_Translation_Schema::run_update();

$out = [];

/** Link an element to a language through WPML's public action. */
function opf_wpml_link( int $element_id, string $element_type, string $language, ?int $trid = null, ?string $source = null ): void {
	do_action(
		'wpml_set_element_language_details',
		[
			'element_id'           => $element_id,
			'element_type'         => $element_type,
			'trid'                 => $trid,
			'language_code'        => $language,
			'source_language_code' => $source,
		]
	);
}

// ---- terms ---------------------------------------------------------------
$en_cat = wp_insert_term( 'Gifts', 'product_cat' );
$en_cat = (int) ( is_wp_error( $en_cat ) ? get_term_by( 'slug', 'gifts', 'product_cat' )->term_id : $en_cat['term_id'] );
$es_cat = wp_insert_term( 'Regalos', 'product_cat', [ 'slug' => 'regalos' ] );
$es_cat = (int) ( is_wp_error( $es_cat ) ? get_term_by( 'slug', 'regalos', 'product_cat' )->term_id : $es_cat['term_id'] );

$en_tag = wp_insert_term( 'handmade', 'product_tag' );
$en_tag = (int) ( is_wp_error( $en_tag ) ? get_term_by( 'slug', 'handmade', 'product_tag' )->term_id : $en_tag['term_id'] );
$es_tag = wp_insert_term( 'hecho a mano', 'product_tag', [ 'slug' => 'hecho-a-mano' ] );
$es_tag = (int) ( is_wp_error( $es_tag ) ? get_term_by( 'slug', 'hecho-a-mano', 'product_tag' )->term_id : $es_tag['term_id'] );

opf_wpml_link( $en_cat, 'tax_product_cat', 'en' );
opf_wpml_link( $es_cat, 'tax_product_cat', 'es', apply_filters( 'wpml_element_trid', null, $en_cat, 'tax_product_cat' ), 'en' );
opf_wpml_link( $en_tag, 'tax_product_tag', 'en' );
opf_wpml_link( $es_tag, 'tax_product_tag', 'es', apply_filters( 'wpml_element_trid', null, $en_tag, 'tax_product_tag' ), 'en' );

// ---- product A: EN + ES translation -------------------------------------
$en_product = new WC_Product_Simple();
$en_product->set_name( 'Gift Card' );
$en_product->set_slug( 'gift-card' );
$en_product->set_status( 'publish' );
$en_product->set_catalog_visibility( 'visible' );
$en_product->set_regular_price( '20' );
$en_product->set_price( '20' );
$en_product->set_sku( 'opf-wpml-gift' );
$en_product->set_category_ids( [ $en_cat ] );
$en_product->set_tag_ids( [ $en_tag ] );
$en_product_id = (int) $en_product->save();
opf_wpml_link( $en_product_id, 'post_product', 'en' );
$trid_a = apply_filters( 'wpml_element_trid', null, $en_product_id, 'post_product' );

$es_product = new WC_Product_Simple();
$es_product->set_name( 'Tarjeta Regalo' );
$es_product->set_slug( 'tarjeta-regalo' );
$es_product->set_status( 'publish' );
$es_product->set_catalog_visibility( 'visible' );
$es_product->set_regular_price( '20' );
$es_product->set_price( '20' );
$es_product->set_category_ids( [ $es_cat ] );
$es_product->set_tag_ids( [ $es_tag ] );
// SKU is written after the language link on purpose: WCML only exempts a
// translation from WooCommerce's SKU uniqueness guard once the pair shares a
// trid (WCML_Products::check_product_sku). Writing it before the link throws
// WC_Data_Exception (captured in 04-fixture.log).
$es_product_id = (int) $es_product->save();
opf_wpml_link( $es_product_id, 'post_product', 'es', $trid_a, 'en' );
$es_product->set_sku( 'opf-wpml-gift' );
$es_product->save();
$out['sku_unique_after_link'] = wc_product_has_unique_sku( $es_product_id, 'opf-wpml-gift' );
$out['es_sku']               = $es_product->get_sku();

// ---- product B: unrelated control, EN + ES translation ------------------
$en_other = new WC_Product_Simple();
$en_other->set_name( 'Plain Mug' );
$en_other->set_slug( 'plain-mug' );
$en_other->set_status( 'publish' );
$en_other->set_catalog_visibility( 'visible' );
$en_other->set_regular_price( '9' );
$en_other->set_price( '9' );
$en_other->set_sku( 'opf-wpml-mug' );
$en_other_id = (int) $en_other->save();
opf_wpml_link( $en_other_id, 'post_product', 'en' );
$trid_b = apply_filters( 'wpml_element_trid', null, $en_other_id, 'post_product' );

$es_other = new WC_Product_Simple();
$es_other->set_name( 'Taza Lisa' );
$es_other->set_slug( 'taza-lisa' );
$es_other->set_status( 'publish' );
$es_other->set_catalog_visibility( 'visible' );
$es_other->set_regular_price( '9' );
$es_other->set_price( '9' );
$es_other_id = (int) $es_other->save();
opf_wpml_link( $es_other_id, 'post_product', 'es', $trid_b, 'en' );
$es_other->set_sku( 'opf-wpml-mug' );
$es_other->save();

$out['en_product_id']    = $en_product_id;
$out['es_product_id']    = $es_product_id;
$out['en_other_id']      = $en_other_id;
$out['es_other_id']      = $es_other_id;
$out['en_language']      = (string) apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $en_product_id, 'element_type' => 'product' ] );
$out['es_language']      = (string) apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $es_product_id, 'element_type' => 'product' ] );
$out['trid']             = $trid_a;
$out['cat_id']           = $en_cat;
$out['es_cat_id']        = $es_cat;
$out['tag_id']           = $en_tag;
$out['es_tag_id']        = $es_tag;
$out['es_product_remap'] = apply_filters( 'wpml_object_id', $en_product_id, 'product', true, 'es' );
$out['es_other_remap']   = apply_filters( 'wpml_object_id', $en_other_id, 'product', true, 'es' );
$out['es_cat_remap']     = apply_filters( 'wpml_object_id', $en_cat, 'product_cat', true, 'es' );
$out['es_tag_remap']     = apply_filters( 'wpml_object_id', $en_tag, 'product_tag', true, 'es' );
$out['es_product_cats']  = wp_get_post_terms( $es_product_id, 'product_cat', [ 'fields' => 'ids' ] );
$out['es_product_tags']  = wp_get_post_terms( $es_product_id, 'product_tag', [ 'fields' => 'ids' ] );
$out['en_product_cats']  = wp_get_post_terms( $en_product_id, 'product_cat', [ 'fields' => 'ids' ] );
$out['es_product_price'] = get_post_meta( $es_product_id, '_price', true );

// ---- OPF field group -----------------------------------------------------
$group = [
	'schema'      => 1,
	'fields'      => [
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
				[ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ (string) $en_cat ] ],
				[ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ (string) $en_tag ] ],
			],
		],
	],
];

$group_id = \OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'Gift options' ] );
$out['group_id'] = $group_id;

// Re-read what was persisted (source JSON must stay untranslated).
$out['stored_rule_terms'] = json_decode( (string) get_post( $group_id )->post_content, true )['rule_groups'][0]['rules'] ?? null;

update_option( 'opf_proof_ids', $out, false );

echo wp_json_encode( $out, JSON_PRETTY_PRINT ), "\n";
