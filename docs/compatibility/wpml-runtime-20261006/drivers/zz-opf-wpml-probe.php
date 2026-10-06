<?php
/**
 * Disposable-clone instrumentation (never shipped, /tmp clone only).
 * Records every WPML string-package interaction OPF emits, plus the handler
 * state WPML String Translation actually registered for those hooks.
 */
defined( 'ABSPATH' ) || exit;

const OPF_PROBE_LOG = '/out/opf-wpml-probe.log';

function opf_probe( string $line ): void {
	$ctx = defined( 'WP_ADMIN' ) ? 'admin' : ( defined( 'WP_CLI' ) ? 'cli' : 'front' );
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	@file_put_contents( OPF_PROBE_LOG, date( 'H:i:s' ) . " [$ctx] $uri " . $line . "\n", FILE_APPEND );
}

function opf_probe_handlers( string $hook, string $kind ): string {
	$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;
	if ( ! $wp_hook instanceof WP_Hook ) {
		return "$kind($hook)=none";
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
	return "$kind($hook)=" . ( $names ? implode( ',', $names ) : 'none' );
}

add_action( 'wpml_loaded', static function () {
	global $wpml_st_string_package_hooks;
	opf_probe( sprintf(
		'HOOK-STATE WPML_TM_VERSION=%s setup_complete=%s %s %s %s %s %s %s',
		defined( 'WPML_TM_VERSION' ) ? WPML_TM_VERSION : 'undefined',
		(string) icl_get_setting( 'setup_complete' ),
		opf_probe_handlers( 'wpml_register_string', 'action' ),
		opf_probe_handlers( 'wpml_translate_string', 'filter' ),
		opf_probe_handlers( 'wpml_start_string_package_registration', 'action' ),
		opf_probe_handlers( 'wpml_delete_unused_package_strings', 'action' ),
		opf_probe_handlers( 'wpml_st_get_string_package', 'filter' ),
		opf_probe_handlers( 'wpml_active_string_package_kinds', 'filter' )
	) );
}, 999 );

add_action( 'wpml_register_string', static function ( $value, $name, $package, $title, $type ) {
	opf_probe( sprintf( 'EMIT wpml_register_string name=%s type=%s value=%s package=%s/%s', $name, $type, str_replace( "\n", ' ', (string) $value ), $package['kind_slug'] ?? '?', $package['name'] ?? '?' ) );
}, 1, 5 );

add_action( 'wpml_start_string_package_registration', static function ( $package ) {
	opf_probe( sprintf( 'EMIT wpml_start_string_package_registration package=%s/%s title=%s', $package['kind_slug'] ?? '?', $package['name'] ?? '?', $package['title'] ?? '?' ) );
}, 1 );

add_action( 'wpml_delete_unused_package_strings', static function ( $package ) {
	opf_probe( sprintf( 'EMIT wpml_delete_unused_package_strings package=%s/%s', $package['kind_slug'] ?? '?', $package['name'] ?? '?' ) );
}, 1 );

add_filter( 'wpml_translate_string', static function ( $translated, $original, $package ) {
	opf_probe( sprintf( 'FILTER wpml_translate_string in=%s package=%s/%s out=%s', str_replace( "\n", ' ', (string) $original ), is_array( $package ) ? ( $package['kind_slug'] ?? '?' ) : get_class( (object) $package ), is_array( $package ) ? ( $package['name'] ?? '?' ) : '', str_replace( "\n", ' ', (string) $translated ) ) );
	return $translated;
}, 1, 3 );
