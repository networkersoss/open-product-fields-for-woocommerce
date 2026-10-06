<?php
/** Dump the real WPML DB rows OPF produced on the 5.1.0 stack. */
global $wpdb;

if ( 0 !== strpos( (string) realpath( ABSPATH ), '/var/www/html' ) || ! str_contains( (string) home_url(), '127.0.0.1:8096' ) ) {
	throw new RuntimeException( 'Refusing to run outside the disposable /tmp clone.' );
}

$ids      = (array) get_option( 'opf_proof_ids', [] );
$group_id = (int) ( $ids['group_id'] ?? 0 );
echo "group_post_id={$group_id}\n";

echo "\n-- icl_string_packages --\n";
$packages = $wpdb->get_results( "SELECT ID, kind_slug, kind, name, title FROM {$wpdb->prefix}icl_string_packages ORDER BY ID", ARRAY_A );
foreach ( $packages as $r ) {
	printf( "ID=%s kind_slug=%s kind=%s name=%s title=%s\n", $r['ID'], $r['kind_slug'], $r['kind'], $r['name'], $r['title'] );
}
printf( "package_rows=%d\n", count( $packages ) );

$package_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}icl_string_packages WHERE kind_slug=%s AND name=%s", 'open-product-fields', (string) $group_id ) );
$context    = 'open-product-fields-' . $group_id;
printf( "opf_package_id=%d opf_package_context=%s\n", $package_id, $context );

echo "\n-- icl_strings of the OPF package --\n";
$strings = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, value, type, context, status, string_package_id FROM {$wpdb->prefix}icl_strings WHERE string_package_id=%d ORDER BY id", $package_id ), ARRAY_A );
foreach ( $strings as $r ) {
	printf( "id=%s name=%s type=%s status=%s context=%s package=%s value=%s\n", $r['id'], $r['name'], $r['type'], $r['status'], $r['context'], $r['string_package_id'], str_replace( "\n", ' ', (string) $r['value'] ) );
}
printf( "opf_string_count=%d\n", count( $strings ) );
printf( "strings_with_opf_package_context=%d\n", (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}icl_strings WHERE context=%s", $context ) ) );

echo "\n-- icl_string_translations for the OPF package --\n";
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.string_id, t.language, t.status, t.value, s.name FROM {$wpdb->prefix}icl_string_translations t INNER JOIN {$wpdb->prefix}icl_strings s ON s.id=t.string_id WHERE s.string_package_id=%d ORDER BY s.id", $package_id ), ARRAY_A );
foreach ( $rows as $r ) {
	printf( "string_id=%s name=%s language=%s status=%s value=%s\n", $r['string_id'], $r['name'], $r['language'], $r['status'], str_replace( "\n", ' ', (string) $r['value'] ) );
}
printf( "opf_translation_count=%d\n", count( $rows ) );

echo "\n-- icl_translations rows for the fixture elements --\n";
$element_ids = array_filter( array_map( 'intval', [ $ids['en_product_id'] ?? 0, $ids['es_product_id'] ?? 0, $ids['en_other_id'] ?? 0, $ids['es_other_id'] ?? 0 ] ) );
$in          = implode( ',', $element_ids );
foreach ( $wpdb->get_results( "SELECT element_id, element_type, trid, language_code, source_language_code FROM {$wpdb->prefix}icl_translations WHERE element_id IN ({$in}) ORDER BY element_id", ARRAY_A ) as $r ) {
	printf( "element_id=%s type=%s language=%s source=%s trid=%s\n", $r['element_id'], $r['element_type'], $r['language_code'], $r['source_language_code'], $r['trid'] );
}

echo "\n-- wpml_object_id remaps (the rule terms OPF must remap) --\n";
printf( "product %d -> es = %s\n", (int) ( $ids['en_product_id'] ?? 0 ), var_export( apply_filters( 'wpml_object_id', (int) ( $ids['en_product_id'] ?? 0 ), 'product', true, 'es' ), true ) );
printf( "product %d -> en = %s\n", (int) ( $ids['es_product_id'] ?? 0 ), var_export( apply_filters( 'wpml_object_id', (int) ( $ids['es_product_id'] ?? 0 ), 'product', true, 'en' ), true ) );
printf( "cat %d -> es = %s\n", (int) ( $ids['cat_id'] ?? 0 ), var_export( apply_filters( 'wpml_object_id', (int) ( $ids['cat_id'] ?? 0 ), 'product_cat', true, 'es' ), true ) );
printf( "tag %d -> es = %s\n", (int) ( $ids['tag_id'] ?? 0 ), var_export( apply_filters( 'wpml_object_id', (int) ( $ids['tag_id'] ?? 0 ), 'product_tag', true, 'es' ), true ) );
printf( "unrelated product %d -> es = %s\n", (int) ( $ids['en_other_id'] ?? 0 ), var_export( apply_filters( 'wpml_object_id', (int) ( $ids['en_other_id'] ?? 0 ), 'product', true, 'es' ), true ) );

echo "\n-- OPF runtime placement (what the storefront will render) --\n";
foreach ( [ 'en' => (int) ( $ids['en_product_id'] ?? 0 ), 'es' => (int) ( $ids['es_product_id'] ?? 0 ), 'en_other' => (int) ( $ids['en_other_id'] ?? 0 ), 'es_other' => (int) ( $ids['es_other_id'] ?? 0 ) ] as $label => $product_id ) {
	$language = str_starts_with( $label, 'es' ) ? 'es' : 'en';
	do_action( 'wpml_switch_language', $language );
	$groups = \OPF\Service\FieldGroups::for_product( wc_get_product( $product_id ) );
	$titles = [];
	foreach ( $groups as $entry ) {
		$titles[] = $entry['id'] . ':' . $entry['title'] . ':' . ( $entry['group']->data['fields'][0]['label'] ?? '' );
	}
	printf( "[%s] product=%d groups=%d %s\n", $language, $product_id, count( $groups ), implode( ' | ', $titles ) );
}
do_action( 'wpml_switch_language', 'en' );
