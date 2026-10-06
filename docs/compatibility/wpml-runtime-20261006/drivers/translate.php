<?php
/** Write the Spanish translations of the OPF package strings through ST's own API. */
global $wpdb;
if ( 0 !== strpos( (string) realpath( ABSPATH ), '/var/www/html' ) || ! str_contains( (string) home_url(), '127.0.0.1:8097' ) ) {
	throw new RuntimeException( 'Refusing to run outside the disposable clone.' );
}
$ids = (array) get_option( 'opf_proof_ids', [] );
$group_id = (int) ( $ids['group_id'] ?? 0 );

$spanish = [
	'field:gift:label'         => 'Envoltorio de regalo',
	'field:gift:description'   => 'Elige el envoltorio',
	'field:gift:placeholder'   => 'Selecciona una opción',
	'field:gift:choice:red'    => 'Rojo',
	'field:gift:choice:blue'   => 'Azul',
	'field:note:label'         => 'Nota',
	'field:note:placeholder'   => 'Añade una nota',
	'field:info:content'       => '<b>Información</b>',
	'field:extra:label'        => 'Extras',
	'field:extra:choice:card'  => 'Tarjeta',
	'field:extra:repeat:add'   => 'Añadir otro',
	'field:extra:repeat:del'   => 'Quitar',
	'field:extra:repeat:label' => 'Copia {n}',
];

$status = defined( 'ICL_TM_COMPLETE' ) ? ICL_TM_COMPLETE : 10;
$package = ( new WPML_ST_Package_Factory() )->create( [ 'kind' => 'Open Product Fields', 'kind_slug' => 'open-product-fields', 'name' => (string) $group_id, 'title' => 'Gift options' ] );
printf( "package_id=%s context=%s\n", $package->ID, $package->get_string_context_from_package() );

$strings = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, value FROM {$wpdb->prefix}icl_strings WHERE string_package_id=%d ORDER BY name", (int) $package->ID ), ARRAY_A );
foreach ( $strings as $row ) {
	$name = (string) $row['name'];
	if ( ! isset( $spanish[ $name ] ) ) { printf( "UNMAPPED %s\n", $name ); continue; }
	$result = icl_add_string_translation( (int) $row['id'], 'es', $spanish[ $name ], $status );
	printf( "string_id=%s name=%s written=%s\n", $row['id'], $name, var_export( $result, true ) );
}

echo "\n-- read back through the runtime filter OPF uses (wpml_translate_string) --\n";
foreach ( [ 'en', 'es' ] as $language ) {
	do_action( 'wpml_switch_language', $language );
	printf( "[%s] current=%s\n", $language, (string) apply_filters( 'wpml_current_language', null ) );
	foreach ( $strings as $row ) {
		$translated = apply_filters( 'wpml_translate_string', $row['value'], $row['name'], [ 'kind' => 'Open Product Fields', 'kind_slug' => 'open-product-fields', 'name' => (string) $group_id, 'title' => 'Gift options' ] );
		printf( "   %s => %s\n", $row['name'], str_replace( "\n", ' ', (string) $translated ) );
	}
}
do_action( 'wpml_switch_language', 'en' );
