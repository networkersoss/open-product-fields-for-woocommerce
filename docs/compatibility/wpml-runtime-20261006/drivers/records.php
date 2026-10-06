<?php
global $wpdb;
$ids = (array) get_option( 'opf_proof_ids', [] );
$group_id = (int) ( $ids['group_id'] ?? 0 );
echo "ids=" . wp_json_encode( $ids ) . "\ngroup_id={$group_id}\n";
echo "\n-- icl_string_packages --\n";
foreach ( $wpdb->get_results( "SELECT ID, kind_slug, kind, name, title FROM {$wpdb->prefix}icl_string_packages", ARRAY_A ) as $r ) { printf( "ID=%s kind_slug=%s name=%s title=%s\n", $r['ID'], $r['kind_slug'], $r['name'], $r['title'] ); }
echo "\n-- icl_strings of the OPF package (string_package_id={$group_id}) --\n";
$strings = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, value, type, context, status FROM {$wpdb->prefix}icl_strings WHERE string_package_id=%d ORDER BY name", $group_id ), ARRAY_A );
foreach ( $strings as $r ) { printf( "id=%s name=%s type=%s status=%s value=%s\n", $r['id'], $r['name'], $r['type'], $r['status'], str_replace( "\n", ' ', (string) $r['value'] ) ); }
printf( "opf_string_count=%d\n", count( $strings ) );
echo "\n-- icl_string_translations for the OPF package --\n";
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.string_id, t.language, t.status, t.value, s.name FROM {$wpdb->prefix}icl_string_translations t INNER JOIN {$wpdb->prefix}icl_strings s ON s.id=t.string_id WHERE s.string_package_id=%d ORDER BY s.name", $group_id ), ARRAY_A );
foreach ( $rows as $r ) { printf( "string_id=%s name=%s language=%s status=%s value=%s\n", $r['string_id'], $r['name'], $r['language'], $r['status'], str_replace( "\n", ' ', (string) $r['value'] ) ); }
printf( "opf_translation_count=%d\n", count( $rows ) );
echo "\n-- language element rows --\n";
foreach ( $wpdb->get_results( "SELECT element_id, element_type, trid, language_code, source_language_code FROM {$wpdb->prefix}icl_translations WHERE element_type IN ('post_product','tax_product_cat','tax_product_tag') ORDER BY element_type, language_code", ARRAY_A ) as $r ) {
	printf( "element_id=%s type=%s language=%s source=%s trid=%s\n", $r['element_id'], $r['element_type'], $r['language_code'], $r['source_language_code'], $r['trid'] );
}
printf( "EN product language=%s\n", var_export( apply_filters( 'wpml_element_language_code', null, [ 'element_id' => (int) ( $ids['en_product_id'] ?? 0 ), 'element_type' => 'product' ] ), true ) );
printf( "ES product language=%s\n", var_export( apply_filters( 'wpml_element_language_code', null, [ 'element_id' => (int) ( $ids['es_product_id'] ?? 0 ), 'element_type' => 'product' ] ), true ) );
printf( "product %d -> es = %s\n", (int) ( $ids['en_product_id'] ?? 0 ), var_export( apply_filters( 'wpml_object_id', (int) ( $ids['en_product_id'] ?? 0 ), 'product', true, 'es' ), true ) );
printf( "cat %d -> es = %s\n", (int) ( $ids['cat_id'] ?? 0 ), var_export( apply_filters( 'wpml_object_id', (int) ( $ids['cat_id'] ?? 0 ), 'product_cat', true, 'es' ), true ) );
printf( "tag %d -> es = %s\n", (int) ( $ids['tag_id'] ?? 0 ), var_export( apply_filters( 'wpml_object_id', (int) ( $ids['tag_id'] ?? 0 ), 'product_tag', true, 'es' ), true ) );
