<?php
/**
 * Contract stub for WPML's public hook surface, used by bin/e2e-wpml-proof.php.
 *
 * WPML is commercial and absent from this host, so the proof exercises
 * WpmlIntegration against this in-process stand-in, modelled on the
 * `$GLOBALS` registry used by tests/Unit/WpmlIntegrationTest.php. It reproduces
 * the hook names, argument shapes and return semantics WPML documents.
 *
 * It is NOT WPML: anything requiring real WPML element records (real string /
 * element ids, the real `wpml_object_id` translation-group mapping) cannot be
 * proven with it. Loaded only inside a guarded /tmp clone.
 */

defined( 'ABSPATH' ) || exit;

/** Reset the recorded hook state. */
function opf_wpml_stub_reset(): void {
	$GLOBALS['opf_wpml_stub'] = [
		'actions'           => [],
		'strings'           => [],
		'translations'      => [],
		'object_map'        => [],
		'object_calls'      => [],
		'element_languages' => [],
		'current_language'  => null,
		'default_language'  => 'en',
	];
}

/** @return array<string,mixed> */
function opf_wpml_stub(): array {
	if ( ! isset( $GLOBALS['opf_wpml_stub'] ) ) {
		opf_wpml_stub_reset();
	}
	return $GLOBALS['opf_wpml_stub'];
}

/** Register the WPML contract hooks for the current request. */
function opf_wpml_stub_install(): void {
	opf_wpml_stub_reset();

	add_action(
		'wpml_start_string_package_registration',
		static function ( $package ): void {
			$GLOBALS['opf_wpml_stub']['actions'][] = [ 'wpml_start_string_package_registration', [ $package ] ];
		},
		10,
		1
	);
	add_action(
		'wpml_register_string',
		static function ( $text, $name, $package, $title, $type ): void {
			$GLOBALS['opf_wpml_stub']['strings'][] = [ 'text' => $text, 'name' => $name, 'package' => $package, 'title' => $title, 'type' => $type ];
		},
		10,
		5
	);
	add_action(
		'wpml_delete_unused_package_strings',
		static function ( $package ): void {
			$GLOBALS['opf_wpml_stub']['actions'][] = [ 'wpml_delete_unused_package_strings', [ $package ] ];
		},
		10,
		1
	);
	add_action(
		'wpml_delete_package',
		static function ( $name, $kind ): void {
			$GLOBALS['opf_wpml_stub']['actions'][] = [ 'wpml_delete_package', [ $name, $kind ] ];
		},
		10,
		2
	);
	add_filter(
		'wpml_current_language',
		static function ( $value ) {
			return $GLOBALS['opf_wpml_stub']['current_language'];
		},
		10,
		1
	);
	add_filter(
		'wpml_translate_string',
		static function ( $text, $name, $package ) {
			$language = $GLOBALS['opf_wpml_stub']['current_language'];
			if ( is_string( $language ) && isset( $GLOBALS['opf_wpml_stub']['translations'][ $language ][ $name ] ) ) {
				return $GLOBALS['opf_wpml_stub']['translations'][ $language ][ $name ];
			}
			return $text;
		},
		10,
		3
	);
	add_filter(
		'wpml_object_id',
		static function ( $id, $type, $fallback, $language ) {
			$GLOBALS['opf_wpml_stub']['object_calls'][] = [ $id, $type, $fallback, $language ];
			$key = $type . ':' . $id;
			if ( isset( $GLOBALS['opf_wpml_stub']['object_map'][ $key ] ) ) {
				return $GLOBALS['opf_wpml_stub']['object_map'][ $key ];
			}
			return $fallback ? $id : null;
		},
		10,
		4
	);
	add_filter(
		'wpml_element_language_code',
		static function ( $language, $args ) {
			$key = (string) ( $args['element_type'] ?? '' ) . ':' . (int) ( $args['element_id'] ?? 0 );
			return $GLOBALS['opf_wpml_stub']['element_languages'][ $key ] ?? null;
		},
		10,
		2
	);
}
