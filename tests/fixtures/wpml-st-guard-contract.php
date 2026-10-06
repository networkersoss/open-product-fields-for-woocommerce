<?php
/**
 * In-process WordPress/WPML shims for WpmlStringTranslationGuardTest.
 *
 * The guard tests must pass both in the full suite — where sibling test files
 * declare some of these shims unguarded — and alone
 * (`vendor/bin/phpunit tests/Unit/WpmlStringTranslationGuardTest.php`). Every
 * declaration is guarded and the file is required from setUp() rather than at
 * load time, the same pattern as fixtures/polylang-integration-contract.php:
 * PHPUnit loads every test file before running any test, so a guarded
 * re-declaration is skipped once a sibling has declared the name.
 *
 * `has_action()` and `WPML_ST_Outdated_Stand_In` are the two WPML-surface
 * signals the guard reads; `current_user_can()` and `is_admin()` drive the
 * admin notice. All of them read the shared `opf_*` registries so the isolated
 * and full-suite paths observe identical state.
 */

namespace {
	if ( ! class_exists( 'WPML_ST_Outdated_Stand_In' ) ) {
		/**
		 * WPML core installs this stand-in (and registers no string-package
		 * handler) when it disables an outdated String Translation companion.
		 */
		class WPML_ST_Outdated_Stand_In {}
	}
	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin(): bool { return (bool) ( $GLOBALS['opf_is_admin'] ?? false ); }
	}
}

namespace OPF\Service {
	if ( ! function_exists( __NAMESPACE__ . '\\apply_filters' ) ) {
		function apply_filters( $name, $value, ...$args ) {
			$callback = $GLOBALS['opf_wpml_filters'][ $name ] ?? null;
			return $callback ? $callback( $value, ...$args ) : $value;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\do_action' ) ) {
		function do_action( $name, ...$args ): void {
			$GLOBALS['opf_wpml_actions'][] = [ $name, $args ];
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\add_filter' ) ) {
		function add_filter( $name, $callback, $priority = 10, $accepted = 1 ): void {
			$GLOBALS['opf_woocs_hooks'][ $name ] = [ $callback, $priority, $accepted ];
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
		function add_action( $name, $callback, $priority = 10, $accepted = 1 ): void {
			add_filter( $name, $callback, $priority, $accepted );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\get_post_meta' ) ) {
		function get_post_meta( int $id, string $key, bool $single ) {
			return $GLOBALS['opf_woocs_meta'][ $id ][ $key ] ?? '';
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\has_action' ) ) {
		/** Mirrors WP: registered priority, or false when nothing is subscribed. */
		function has_action( $tag, $callback = false ) {
			return $GLOBALS['opf_has_action'][ $tag ] ?? false;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\current_user_can' ) ) {
		function current_user_can( $cap ) { return ! empty( $GLOBALS['opf_can'][ $cap ] ); }
	}
}
