<?php
/** Read the OPF package translations back in a fresh process. */
global $wpdb;
$ids      = (array) get_option( 'opf_proof_ids', [] );
$group_id = (int) $ids['group_id'];

echo "-- icl_string_translations rows --\n";
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.string_id, t.language, t.status, t.value, s.name, s.status AS string_status FROM {$wpdb->prefix}icl_string_translations t INNER JOIN {$wpdb->prefix}icl_strings s ON s.id=t.string_id WHERE s.string_package_id=1 ORDER BY t.string_id", [] ), ARRAY_A ) as $r ) {
	echo wp_json_encode( $r ), "\n";
}

$package_array = [ 'kind' => 'Open Product Fields', 'kind_slug' => 'open-product-fields', 'name' => (string) $group_id, 'title' => 'Gift options' ];
echo "\n-- wpml_translate_string, fresh process, per language --\n";
foreach ( [ 'en', 'es' ] as $language ) {
	do_action( 'wpml_switch_language', $language );
	foreach ( [ 'field:gift:label' => 'Gift wrap', 'field:gift:choice:red' => 'Red', 'field:extra:repeat:add' => 'Add another' ] as $name => $value ) {
		printf( "[%s] %s => %s\n", $language, $name, (string) apply_filters( 'wpml_translate_string', $value, $name, $package_array ) );
	}
	printf( "[%s] current=%s\n", $language, (string) apply_filters( 'wpml_current_language', null ) );
}
do_action( 'wpml_switch_language', 'en' );

echo "\n-- OPF runtime group, fresh process --\n";
foreach ( [ 'en' => (int) $ids['en_product_id'], 'es' => (int) $ids['es_product_id'] ] as $language => $product_id ) {
	do_action( 'wpml_switch_language', $language );
	foreach ( \OPF\Service\FieldGroups::for_product( wc_get_product( $product_id ) ) as $entry ) {
		$field = $entry['group']->data['fields'][0];
		printf( "[%s] label=%s choices=%s\n", $language, $field['label'], wp_json_encode( wp_list_pluck( $field['choices'], 'label' ) ) );
	}
}
do_action( 'wpml_switch_language', 'en' );
