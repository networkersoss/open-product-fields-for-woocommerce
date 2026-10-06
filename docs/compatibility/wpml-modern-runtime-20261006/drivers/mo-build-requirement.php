<?php
/**
 * Why ST 5.1.0 can deliver (or not) an OPF package translation:
 * the package context is an MO domain and its .mo file must be buildable under
 * WP_LANG_DIR/wpml. WP_Filesystem_Direct::mkdir() does not create parents.
 */
global $wpdb;
$ids     = (array) get_option( 'opf_proof_ids', [] );
$context = 'open-product-fields-' . (int) ( $ids['group_id'] ?? 0 );

$manager = WPML\ST\MO\File\ManagerFactory::create();
$r       = new ReflectionObject( $manager );
$p       = $r->getProperty( 'filesystem' );
$p->setAccessible( true );
$fs      = $p->getValue( $manager );
$subdir  = $manager->getSubdir();

printf( "WP_LANG_DIR=%s\n", WP_LANG_DIR );
printf( "filesystem=%s\n", is_object( $fs ) ? get_class( $fs ) : 'null' );
printf( "subdir=%s\n", $subdir );
printf( "subdir_exists=%s\n", var_export( is_dir( $subdir ), true ) );
printf( "parent_exists=%s parent_writable=%s\n", var_export( is_dir( dirname( $subdir ) ), true ), var_export( is_writable( dirname( $subdir ) ), true ) );
printf( "WPML_ST_SYNC_TRANSLATION_FILES=%s\n", defined( 'WPML_ST_SYNC_TRANSLATION_FILES' ) ? var_export( constant( 'WPML_ST_SYNC_TRANSLATION_FILES' ), true ) : 'undefined' );
printf( "useFileSynchronization=%s\n", var_export( WPML\ST\TranslationFile\Hooks::useFileSynchronization(), true ) );
printf( "handles(%s)=%s\n", $context, var_export( $manager->handles( $context ), true ) );
printf( "manager->add(%s, es_ES)=%s\n", $context, var_export( $manager->add( $context, 'es_ES' ), true ) );
$path = $manager->getFilepath( $context, 'es_ES' );
printf( "mo_path=%s mo_exists=%s\n", $path, var_export( file_exists( $path ), true ) );

$mapper = WPML\Container\make( WPML\ST\DB\Mappers\StringsRetrieve::class );
printf( "db_rows_for_mo(es, partial)=%d\n", count( $mapper->get( 'es', $context, true ) ) );

do_action( 'wpml_switch_language', 'es' );
printf(
	"wpml_translate_string(es)=%s\n",
	(string) apply_filters( 'wpml_translate_string', 'Gift wrap', 'field:gift:label', [ 'kind' => 'Open Product Fields', 'kind_slug' => 'open-product-fields', 'name' => (string) ( $ids['group_id'] ?? 0 ), 'title' => 'Gift options' ] )
);
do_action( 'wpml_switch_language', 'en' );
