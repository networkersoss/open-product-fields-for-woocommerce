<?php
/**
 * WPML String Translation packages for OPF's JSON-backed field groups.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class WpmlIntegration {

	private const KIND = 'Open Product Fields';
	private const SLUG = 'open-product-fields';
	private const SOURCE_LANGUAGE_META = '_opf_wpml_source_language';

	/** Guards the unavailable-API notice so it is queued once per request. */
	private static $unavailable_notice_queued = false;

	/** Wire after WordPress and WPML have loaded. No WPML dependency when absent. */
	public static function init(): void {
		add_filter( 'wpml_active_string_package_kinds', [ __CLASS__, 'package_kinds' ] );
		add_action( 'save_post_opf_field_group', [ __CLASS__, 'register_post' ], 20, 2 );
		add_action( 'before_delete_post', [ __CLASS__, 'delete_post' ], 10, 2 );
		add_filter( 'opf_groups_for_product', [ __CLASS__, 'translate_groups' ] );
		add_action( 'wpml_switch_language', [ FieldGroups::class, 'flush_cache' ], 100 );
		// WPML core disables an outdated String Translation without telling
		// anyone. Detect that at boot so the administrator is warned instead of
		// staring at an empty package editor. The notice re-checks availability
		// when it renders, so an early false positive is harmless.
		if ( ! self::strings_api_available() ) {
			self::queue_unavailable_notice();
		}
	}

	/**
	 * Whether WPML's String Translation package API is actually consuming
	 * registration hooks.
	 *
	 * WPML core replaces the String Translation bootstrap with
	 * `WPML_ST_Outdated_Stand_In` and removes every registration handler when it
	 * disables an outdated companion, so a version constant alone cannot prove
	 * the API is live. With no WPML loaded at all the hooks stay inert as before
	 * and nothing is skipped.
	 */
	private static function strings_api_available(): bool {
		if ( self::outdated_string_translation_stand_in() ) {
			return false;
		}
		return ! self::wpml_active() || self::registration_handler_present();
	}

	/** WPML core's marker for a String Translation it switched off. */
	private static function outdated_string_translation_stand_in(): bool {
		$st = $GLOBALS['WPML_String_Translation'] ?? null;
		return is_object( $st )
			&& class_exists( 'WPML_ST_Outdated_Stand_In', false )
			&& $st instanceof \WPML_ST_Outdated_Stand_In;
	}

	/** WPML core or String Translation loaded, however healthy. */
	private static function wpml_active(): bool {
		return isset( $GLOBALS['WPML_String_Translation'] )
			|| defined( 'ICL_SITEPRESS_VERSION' )
			|| defined( 'WPML_ST_VERSION' )
			|| class_exists( 'SitePress', false );
	}

	/**
	 * A live String Translation subscribes a handler to the package
	 * registration action (WPML_Package_Translation::register_string_action).
	 * When nothing is subscribed the emitted action is a silent no-op.
	 */
	private static function registration_handler_present(): bool {
		if ( ! \function_exists( 'has_action' ) && ! function_exists( __NAMESPACE__ . '\\has_action' ) ) {
			return true; // Plain PHP: no hook API to inspect, assume the real surface.
		}
		return false !== has_action( 'wpml_register_string' )
			|| false !== has_action( 'wpml_start_string_package_registration' );
	}

	/** Queue the admin warning at most once per request. */
	private static function queue_unavailable_notice(): bool {
		if ( self::$unavailable_notice_queued ) {
			return false;
		}
		self::$unavailable_notice_queued = true;
		add_action( 'admin_notices', [ __CLASS__, 'render_unavailable_notice' ] );
		return true;
	}

	/**
	 * Admin-only warning: WPML cannot receive OPF's translatable strings.
	 * Silent on the front end and for users who cannot act on it.
	 */
	public static function render_unavailable_notice(): void {
		if ( ! is_admin() || self::strings_api_available() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__(
				'Open Product Fields: WPML String Translation is unavailable or incompatible with the installed WPML core, so field labels cannot be registered for translation. Update WPML String Translation to the version required by WPML core.',
				'open-product-fields-for-woocommerce'
			)
		);
	}

	public static function package_kinds( array $kinds ): array {
		$kinds[ self::SLUG ] = [ 'title' => self::KIND, 'slug' => self::SLUG, 'plural' => self::KIND ];
		return $kinds;
	}

	private static function package( int $id, string $title = '' ): array {
		return [
			'kind' => self::KIND,
			'kind_slug' => self::SLUG,
			'name' => (string) $id,
			'title' => $title . ' (#' . $id . ')',
		];
	}

	/** Source content ownership comes from WPML's element record, never context. */
	public static function source_language( int $id, string $post_type ): string {
		if ( $id < 1 || ! in_array( $post_type, [ 'wapf_product', 'product' ], true ) ) {
			return '';
		}
		$language = apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $id, 'element_type' => $post_type ] );
		return self::valid_language( $language ) ? $language : '';
	}

	private static function valid_language( $language ): bool {
		return is_string( $language ) && 'all' !== $language && 1 === preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $language );
	}

	private static function is_imported( int $id ): bool {
		return '' !== (string) get_post_meta( $id, '_opf_imported_from', true ) || '' !== (string) get_post_meta( $id, '_opf_archive_import_key', true );
	}

	/** Saving an existing group also makes pre-WPML groups available to translators. */
	public static function register_post( int $id, $post ): void {
		if ( ! is_object( $post ) || 'opf_field_group' !== ( $post->post_type ?? '' ) || 'auto-draft' === ( $post->post_status ?? '' ) ) {
			return;
		}
		// Imported payloads may already be independently translated. Package
		// registration has no documented source-language argument; do not assign
		// their strings to the administrator's language or rewrite old packages.
		if ( self::is_imported( $id ) ) {
			return;
		}
		$data = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $data ) || ! is_array( $data['fields'] ?? null ) ) {
			return;
		}
		// Without a live package API every registration hook below is dropped by
		// WPML's stand-in. Skip emission and tell the administrator once.
		if ( ! self::strings_api_available() ) {
			self::queue_unavailable_notice();
			return;
		}
		$package = self::package( $id, (string) $post->post_title );
		do_action( 'wpml_start_string_package_registration', $package );
		self::map_text( $data, static function ( string $text, string $name, string $type ) use ( $package ): string {
			do_action( 'wpml_register_string', $text, $name, $package, $name, $type );
			return $text;
		} );
		do_action( 'wpml_delete_unused_package_strings', $package );
	}

	public static function delete_post( int $id, $post ): void {
		if ( is_object( $post ) && 'opf_field_group' === ( $post->post_type ?? '' ) ) {
			do_action( 'wpml_delete_package', (string) $id, self::SLUG );
		}
	}

	/**
	 * Translate runtime copies only. Never mutate stored JSON, exports or editor data.
	 * Product placement also covers variation parent IDs used by FieldGroups.
	 */
	public static function translate_groups( array $entries ): array {
		$language = apply_filters( 'wpml_current_language', null );
		if ( ! is_string( $language ) || '' === $language || 'all' === $language ) {
			return $entries;
		}
		foreach ( $entries as $key => $entry ) {
			if ( ! ( $entry['group'] ?? null ) instanceof FieldGroup ) {
				continue;
			}
			// WAPF owns a separate payload per language. Keep its labels and target
			// IDs together; remapping every localized import would duplicate fields.
			if ( self::is_imported( (int) $entry['id'] ) ) {
				$source_language = get_post_meta( (int) $entry['id'], self::SOURCE_LANGUAGE_META, true );
				if ( self::valid_language( $source_language ) && $source_language !== $language ) {
					unset( $entries[ $key ] );
				}
				// Historical and archive imports without ownership stay unchanged.
				continue;
			}
			$package = self::package( (int) $entry['id'], (string) ( $entry['title'] ?? '' ) );
			$data = self::map_text( $entry['group']->data, static function ( string $text, string $name ) use ( $package ): string {
				// WPML's package filter uses its current language; do not switch it here.
				$value = apply_filters( 'wpml_translate_string', $text, $name, $package );
				return is_string( $value ) ? $value : $text;
			} );
			foreach ( $data['rule_groups'] ?? [] as $group_index => $rule_group ) {
				foreach ( $rule_group['rules'] ?? [] as $rule_index => $rule ) {
					$type = $rule['subject'] ?? '';
					if ( ! in_array( $type, [ 'product', 'product_cat', 'product_tag' ], true ) ) {
						continue;
					}
					foreach ( $rule['terms'] ?? [] as $term_index => $term ) {
						if ( ! ctype_digit( (string) $term ) || (int) $term < 1 ) {
							continue;
						}
						$translated = apply_filters( 'wpml_object_id', (int) $term, $type, true, $language );
						if ( is_numeric( $translated ) && (int) $translated > 0 ) {
							$data['rule_groups'][ $group_index ]['rules'][ $rule_index ]['terms'][ $term_index ] = (string) (int) $translated;
						}
					}
				}
			}
			// A translated label must never alter IDs, choice slugs or pricing data.
			$group = clone $entry['group'];
			$group->data = $data;
			$entries[ $key ]['group'] = $group;
		}
		return $entries;
	}

	/** Transform only display strings; names survive field/choice reordering. */
	public static function map_text( array $data, callable $map ): array {
		foreach ( $data['fields'] ?? [] as $index => $field ) {
			if ( ! is_array( $field ) || ! is_string( $field['id'] ?? null ) ) {
				continue;
			}
			$prefix = 'field:' . rawurlencode( $field['id'] );
			foreach ( [ 'label', 'description', 'placeholder', 'content' ] as $key ) {
				if ( is_string( $field[ $key ] ?? null ) && '' !== $field[ $key ] ) {
					$type = in_array( $key, [ 'description', 'content' ], true ) ? 'AREA' : 'LINE';
					if ( 'content' === $key && 'html' === ( $field['content_format'] ?? '' ) ) {
						$type = 'VISUAL';
					}
					$data['fields'][ $index ][ $key ] = $map( $field[ $key ], $prefix . ':' . $key, $type );
				}
			}
			foreach ( $field['choices'] ?? [] as $choice_index => $choice ) {
				if ( is_string( $choice['label'] ?? null ) && '' !== $choice['label'] && is_string( $choice['slug'] ?? null ) ) {
					$data['fields'][ $index ]['choices'][ $choice_index ]['label'] = $map( $choice['label'], $prefix . ':choice:' . rawurlencode( $choice['slug'] ), 'LINE' );
				}
			}
			foreach ( [ 'add', 'del', 'label' ] as $key ) {
				if ( is_string( $field['repeat'][ $key ] ?? null ) && '' !== $field['repeat'][ $key ] ) {
					$data['fields'][ $index ]['repeat'][ $key ] = $map( $field['repeat'][ $key ], $prefix . ':repeat:' . $key, 'LINE' );
				}
			}
		}
		return $data;
	}
}
