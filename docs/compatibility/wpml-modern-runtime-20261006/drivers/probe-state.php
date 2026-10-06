<?php
/**
 * Disposable-clone stack probe (WPML 5.1.0 set). Read-only state dump.
 * Runs only inside the /tmp clone.
 */
global $wpdb, $sitepress;

if ( 0 !== strpos( (string) realpath( ABSPATH ), '/var/www/html' ) || ! str_contains( (string) home_url(), '127.0.0.1:8096' ) ) {
	throw new RuntimeException( 'Refusing to run outside the disposable /tmp clone.' );
}

printf( "wp=%s php=%s\n", get_bloginfo( 'version' ), PHP_VERSION );
printf( "ICL_SITEPRESS_VERSION=%s\n", defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : '-' );
printf( "WPML_ST_VERSION=%s\n", defined( 'WPML_ST_VERSION' ) ? WPML_ST_VERSION : '-' );
printf( "WCML_VERSION=%s\n", defined( 'WCML_VERSION' ) ? WCML_VERSION : '-' );
printf( "WPML_TM_VERSION=%s\n", defined( 'WPML_TM_VERSION' ) ? WPML_TM_VERSION : 'undefined' );
printf( "StringTranslation_object=%s\n", isset( $GLOBALS['WPML_String_Translation'] ) ? get_class( $GLOBALS['WPML_String_Translation'] ) : 'unset' );
printf( "woocommerce_wpml_object=%s\n", isset( $GLOBALS['woocommerce_wpml'] ) ? get_class( $GLOBALS['woocommerce_wpml'] ) : 'unset' );
printf( "class WPML_ST_Outdated_Stand_In defined=%s\n", class_exists( 'WPML_ST_Outdated_Stand_In', false ) ? 'yes' : 'no' );
printf( "did_action(wpml_loaded)=%d\n", did_action( 'wpml_loaded' ) );
printf( "did_action(wcml_loaded)=%d\n", did_action( 'wcml_loaded' ) );
printf( "isStOutdated=%s\n", var_export( WPML_Plugins_Check::isStOutdated(), true ) );
printf( "class_exists WPML_Package_Translation=%s WPML_ST_Package_Factory=%s WPML_Package=%s\n",
	class_exists( 'WPML_Package_Translation' ) ? 'yes' : 'no',
	class_exists( 'WPML_ST_Package_Factory' ) ? 'yes' : 'no',
	class_exists( 'WPML_Package' ) ? 'yes' : 'no' );
printf( "setup_complete=%s\n", (string) icl_get_setting( 'setup_complete' ) );
printf( "active_languages=%s\n", implode( ',', array_keys( (array) $sitepress->get_active_languages() ) ) );

foreach ( [
	'wpml_register_string'                  => 'action',
	'wpml_start_string_package_registration' => 'action',
	'wpml_delete_unused_package_strings'    => 'action',
	'wpml_delete_package'                   => 'action',
	'wpml_translate_string'                 => 'filter',
	'wpml_st_get_string_package'            => 'filter',
	'wpml_active_string_package_kinds'      => 'filter',
	'wpml_object_id'                        => 'filter',
] as $hook => $kind ) {
	printf( "%s(%s)=%s\n", $kind, $hook, var_export( 'action' === $kind ? has_action( $hook ) : has_filter( $hook ), true ) );
}

printf( "icl_string_packages table=%s rows=%d\n",
	$wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}icl_string_packages'" ) ? 'exists' : 'MISSING',
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}icl_string_packages" ) );
printf( "icl_strings rows=%d\n", (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}icl_strings" ) );

// OPF guard state: the guard is private; its observable surface is whether the
// registration emission happens (rows appear) and whether the notice renders.
$guard = new ReflectionClass( OPF\Service\WpmlIntegration::class );
$method = $guard->getMethod( 'strings_api_available' );
$method->setAccessible( true );
printf( "OPF strings_api_available=%s\n", var_export( $method->invoke( null ), true ) );

printf( "action wpml_register_string handlers=%s\n", opf_probe_names( 'wpml_register_string' ) );

function opf_probe_names( string $hook ): string {
	$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;
	if ( ! $wp_hook instanceof WP_Hook ) {
		return 'none';
	}
	$names = [];
	foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn = $cb['function'];
			if ( is_array( $fn ) ) {
				$fn = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1];
			} elseif ( $fn instanceof Closure ) {
				$fn = 'closure';
			}
			$names[] = $priority . ':' . $fn;
		}
	}
	return $names ? implode( ',', $names ) : 'none';
}
