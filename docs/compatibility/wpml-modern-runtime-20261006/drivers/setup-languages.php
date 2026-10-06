<?php
/**
 * Disposable-clone WPML setup: default language en + second language es.
 * Runs under `wp eval-file` inside /tmp/opf-wpml-modern-wp/stack510 only.
 */
global $sitepress, $wpdb;

if ( 0 !== strpos( (string) realpath( ABSPATH ), '/var/www/html' ) || ! str_contains( (string) home_url(), '127.0.0.1:8096' ) ) {
	throw new RuntimeException( 'Refusing to run outside the disposable /tmp clone.' );
}

SitePress_Setup::fill_languages();
SitePress_Setup::fill_languages_translations();

$installation = new WPML_Installation( $wpdb, $sitepress );

$installation->finish_step1( 'en' );
$installation->finish_step2( [ 'en', 'es' ] );
$installation->finish_installation();

// Directory-based language URLs (the WPML default), explicitly recorded.
$settings = $sitepress->get_settings();
$settings['language_negotiation_type'] = 1;
$sitepress->save_settings( $settings );

echo "\n-- active languages --\n";
foreach ( $sitepress->get_active_languages() as $code => $lang ) {
	printf( "%s\t%s\t%s\n", $code, $lang['english_name'], $lang['default_locale'] );
}
printf( "default_language=%s\n", (string) $sitepress->get_default_language() );
printf( "current via filter=%s\n", (string) apply_filters( 'wpml_current_language', null ) );
printf( "icl_languages active=%s\n", implode( ',', $wpdb->get_col( "SELECT code FROM {$wpdb->prefix}icl_languages WHERE active=1 ORDER BY code" ) ) );
printf( "setup_complete=%s\n", (string) icl_get_setting( 'setup_complete' ) );
printf( "language_negotiation_type=%s\n", (string) $sitepress->get_setting( 'language_negotiation_type' ) );
printf( "permalink_structure=%s\n", (string) get_option( 'permalink_structure' ) );

// Pretty permalinks are required by the directory URL scheme.
if ( '' === (string) get_option( 'permalink_structure' ) ) {
	update_option( 'permalink_structure', '/%postname%/' );
	global $wp_rewrite;
	$wp_rewrite->init();
	$wp_rewrite->flush_rules( true );
	echo "permalink_structure=set\n";
}
