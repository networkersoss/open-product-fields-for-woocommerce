<?php
/** WPML 4.2.8 setup for the disposable clone: en (default) + es, directory URLs. */
global $wpdb, $sitepress;

if ( 0 !== strpos( (string) realpath( ABSPATH ), '/var/www/html' ) || ! str_contains( (string) home_url(), '127.0.0.1:8097' ) ) {
	throw new RuntimeException( 'Refusing to run outside the disposable clone.' );
}

SitePress_Setup::fill_languages();
SitePress_Setup::fill_languages_translations();

$active = [ 'en', 'es' ];
foreach ( $active as $code ) {
	$locale = $wpdb->get_var( $wpdb->prepare( "SELECT default_locale FROM {$wpdb->prefix}icl_languages WHERE code=%s", $code ) );
	if ( $locale && ! $wpdb->get_var( $wpdb->prepare( "SELECT code FROM {$wpdb->prefix}icl_locale_map WHERE code=%s", $code ) ) ) {
		$wpdb->insert( "{$wpdb->prefix}icl_locale_map", [ 'code' => $code, 'locale' => $locale ] );
	}
	SitePress_Setup::insert_default_category( $code );
}
$wpdb->query( "UPDATE {$wpdb->prefix}icl_languages SET active=1 WHERE code IN ('en','es')" );
$wpdb->query( "UPDATE {$wpdb->prefix}icl_languages SET active=0 WHERE code NOT IN ('en','es')" );

$settings = $sitepress->get_settings();
$settings['active_languages']               = $active;
$settings['default_language']               = 'en';
$settings['language_negotiation_type']      = 1; // directory URLs (/es/)
$settings['existing_content_language_verified'] = 1;
$settings['setup_wizard_step']              = 3;
$settings['dont_show_help_admin_notice']    = true;
$sitepress->save_settings( $settings );

icl_set_setting( 'default_language', 'en', true );
icl_set_setting( 'existing_content_language_verified', 1, true );
icl_set_setting( 'setup_complete', 1, true );
if ( function_exists( 'icl_save_settings' ) ) {
	icl_save_settings();
}
icl_cache_clear();
if ( function_exists( 'wpml_reload_active_languages_setting' ) ) {
	wpml_reload_active_languages_setting( true );
}
do_action( 'wpml_setup_completed' );

$sitepress->set_default_language( 'en' );

echo "\n-- active languages --\n";
foreach ( $sitepress->get_active_languages() as $code => $lang ) {
	printf( "%s\t%s\t%s\n", $code, $lang['english_name'], $lang['default_locale'] );
}
printf( "default_language=%s\n", (string) $sitepress->get_default_language() );
printf( "current via filter=%s\n", (string) apply_filters( 'wpml_current_language', null ) );
printf( "icl_languages active=%s\n", implode( ',', $wpdb->get_col( "SELECT code FROM {$wpdb->prefix}icl_languages WHERE active=1 ORDER BY code" ) ) );
printf( "setup_complete=%s\n", (string) icl_get_setting( 'setup_complete' ) );
printf( "is_setup_complete=%s\n", var_export( $sitepress->is_setup_complete(), true ) );
printf( "language_negotiation_type=%s\n", (string) $sitepress->get_setting( 'language_negotiation_type' ) );
