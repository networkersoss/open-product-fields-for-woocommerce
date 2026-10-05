<?php
/**
 * Asset loading. Zero bytes on any page that doesn't render fields.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\DateFormat;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register_admin' ] );
	}

	/**
	 * Register (not enqueue) frontend assets; Renderer enqueues on render.
	 */
	public static function register_frontend(): void {
		$ver = OPF_VERSION;

		if ( function_exists( 'wp_register_script_module' ) ) {
			wp_register_script_module(
				'opf-frontend',
				OPF_URL . 'assets/js/opf-frontend.js',
				[
					[
						'id'     => '@wordpress/interactivity',
						'import' => 'static',
					],
				],
				$ver
			);
		} else {
			wp_register_script( 'opf-frontend', OPF_URL . 'assets/js/opf-frontend.js', [], $ver, true );
		}

		wp_register_style( 'opf-frontend', OPF_URL . 'assets/css/opf-frontend.css', [], $ver );
		wp_register_script( 'opf-uploads', OPF_URL . 'assets/js/opf-uploads.js', [], $ver, true );
	}

	/**
	 * Enqueue what the renderer needs. Only called when fields exist on page.
	 *
	 * @param array<string,mixed> $registry Field metadata for the client.
	 */
	public static function enqueue_frontend( array $registry = [] ): void {
		foreach ( $registry as $fields ) {
			if ( in_array( 'upload', array_column( $fields, 'type' ), true ) ) { wp_enqueue_script( 'opf-uploads' ); break; }
		}
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( 'opf-frontend' );
		} else {
			wp_enqueue_script( 'opf-frontend' );
		}
		// Script modules bypass wp_scripts, so wp_add_inline_script() is a
		// no-op here. Classic inline scripts execute immediately — before the
		// deferred module — which is exactly the ordering the registry needs.
		if ( $registry ) {
			$date_format = self::frontend_date_format();
			$today       = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
			wp_print_inline_script_tag(
				'window.OPF_FIELDS = ' . wp_json_encode( $registry, JSON_UNESCAPED_UNICODE ) . ';'
				. 'window.OPF_DATE_FORMAT = ' . wp_json_encode( $date_format ) . ';'
				. 'window.OPF_TODAY = ' . wp_json_encode( $today ) . ';'
				. 'window.OPF_I18N = ' . wp_json_encode( self::frontend_i18n(), JSON_UNESCAPED_UNICODE ) . ';'
			);
			$image_rules = self::frontend_image_rules( $registry );
			if ( $image_rules ) {
				wp_print_inline_script_tag(
					'window.OPF_IMAGE_RULES = ' . wp_json_encode( $image_rules['rules'], JSON_UNESCAPED_UNICODE ) . ';'
					. 'window.OPF_IMAGE_RULE_MODES = ' . wp_json_encode( $image_rules['modes'], JSON_UNESCAPED_UNICODE ) . ';'
				);
			}
		}
		// WAPF lookup-table parity: WAPF injects `var wapf_lookup_tables` from
		// its `wapf/lookup_tables` filter so lookuptable() previews resolve
		// client-side. OPF exposes the same tables under its own global —
		// `opf_lookup_tables` first, WAPF's filter as the migration fallback —
		// which the evaluator merges ahead of opts.lookupTables.
		$lookup_tables = (array) apply_filters( 'opf_lookup_tables', [], [] );
		if ( ! $lookup_tables ) {
			$lookup_tables = (array) apply_filters( 'wapf/lookup_tables', [] );
		}
		if ( $lookup_tables ) {
			wp_print_inline_script_tag(
				'window.OPF_LOOKUP_TABLES = ' . wp_json_encode( $lookup_tables, JSON_UNESCAPED_UNICODE ) . ';'
			);
		}
		if ( Renderer::compat() || null !== WoocsIntegration::frontend_config() ) {
			// Theme integration reads this global for price formatting (opf_config; wapf_config fallback lives in the theme JS).
			wp_print_inline_script_tag(
				'window.opf_config = ' . wp_json_encode( self::compat_config() ) . ';'
			);
		}
		wp_enqueue_style( 'opf-frontend' );
	}

	/**
	 * Product-image rules authored on the rendered groups, keyed by the client
	 * group id the frontend reads from `data-opf-group` (the registry key) —
	 * the OPF-native `image_rules` model, not the WAPF-shaped
	 * `layout.gallery_images` payload the renderer already ships as
	 * `data-opf-gi`. Entries come from the repository's client ids rather than
	 * `FieldGroups::all()` alone, so a group injected through
	 * `opf_groups_for_product` / `wapf/product_field_groups` (which is rendered
	 * with its own `image_rules`) publishes them too. Groups without rules are
	 * omitted so rule-less pages emit nothing. Shape per group:
	 * {target_url,conditions:[{field,value}]} plus the group's swap mode
	 * ('rules' | 'last').
	 *
	 * @param array<string,mixed> $registry Client registry (gid => fields).
	 * @return array{rules:array<string,mixed>,modes:array<string,string>}|array{}
	 */
	private static function frontend_image_rules( array $registry ): array {
		$rules = [];
		$modes = [];
		foreach ( FieldGroups::entries_by_id( array_keys( $registry ) ) as $gid => $entry ) {
			$group_rules = $entry['group']->data['image_rules'] ?? [];
			if ( ! is_array( $group_rules ) || ! $group_rules ) {
				continue;
			}
			// JSON must see a list; a sparse stored map would serialize as an object.
			$rules[ $gid ] = array_values( $group_rules );
			$modes[ $gid ] = 'last' === ( $entry['group']->data['image_rule_mode'] ?? '' ) ? 'last' : 'rules';
		}
		return $rules ? [ 'rules' => $rules, 'modes' => $modes ] : [];
	}

	/**
	 * Predefined frontend strings served to the field scripts.
	 *
	 * Script modules cannot use wp_set_script_translations, so the translated
	 * strings ride along the existing window.OPF_* registry. Every key has a
	 * matching English fallback inside the JavaScript; '%d'/'%s' placeholders
	 * are substituted client-side. Weekday abbreviations come from WP_Locale
	 * so the date picker heading follows the site language.
	 *
	 * @return array<string,string|array<int,string>>
	 */
	private static function frontend_i18n(): array {
		global $wp_locale;
		$weekdays = [];
		for ( $i = 0; $i < 7; $i++ ) {
			$weekdays[] = is_object( $wp_locale ) && method_exists( $wp_locale, 'get_weekday_abbrev' )
				? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $i ) )
				: [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ][ $i ];
		}
		return [
			'site_locale'               => function_exists( 'get_locale' ) ? str_replace( '_', '-', (string) get_locale() ) : '',
			/* translators: %d: minimum number of items. */
			'choose_at_least_items'     => __( 'Choose at least %d items in total.', 'open-product-fields-for-woocommerce' ),
			/* translators: %d: maximum number of items. */
			'choose_no_more_items'      => __( 'Choose no more than %d items in total.', 'open-product-fields-for-woocommerce' ),
			/* translators: %d: maximum number of selectable options. */
			'select_no_more_options'    => __( 'Select no more than %d options.', 'open-product-fields-for-woocommerce' ),
			'required_choices_rows'     => __( 'Select the required choices in every repeated row.', 'open-product-fields-for-woocommerce' ),
			'choose_date'               => __( 'Choose date', 'open-product-fields-for-woocommerce' ),
			'choose_a_date'             => __( 'Choose a date', 'open-product-fields-for-woocommerce' ),
			'previous_month'            => __( 'Previous month', 'open-product-fields-for-woocommerce' ),
			'next_month'                => __( 'Next month', 'open-product-fields-for-woocommerce' ),
			'calendar_dates'            => __( 'Calendar dates', 'open-product-fields-for-woocommerce' ),
			'no_selectable_dates'       => __( 'No selectable dates this month.', 'open-product-fields-for-woocommerce' ),
			'date_unavailable'          => __( 'This date is unavailable.', 'open-product-fields-for-woocommerce' ),
			'weekday_abbreviations'     => $weekdays,
			/* translators: %d: repeated-row number. */
			'remove_row'                => __( 'Remove row %d', 'open-product-fields-for-woocommerce' ),
			'remove'                    => __( 'Remove', 'open-product-fields-for-woocommerce' ),
			'wait_for_uploads'          => __( 'Wait for uploads to finish.', 'open-product-fields-for-woocommerce' ),
			'choose_a_file'             => __( 'Choose a file.', 'open-product-fields-for-woocommerce' ),
			'file_upload_progress'      => __( 'File upload progress', 'open-product-fields-for-woocommerce' ),
			'choose_files_or_drop'      => __( 'Choose files or drop them here.', 'open-product-fields-for-woocommerce' ),
			'remove_file_first'         => __( 'Remove a file before uploading another.', 'open-product-fields-for-woocommerce' ),
			/* translators: %s: file name. */
			'uploading'                 => __( 'Uploading %s', 'open-product-fields-for-woocommerce' ),
			'upload_failed'             => __( 'Upload failed. Try again.', 'open-product-fields-for-woocommerce' ),
			'uploads_unavailable'       => __( 'Uploads are unavailable. Refresh the page.', 'open-product-fields-for-woocommerce' ),
			/* translators: %s: file name. */
			'remove_file'               => __( 'Remove %s', 'open-product-fields-for-woocommerce' ),
			'could_not_remove'          => __( 'Could not remove this file.', 'open-product-fields-for-woocommerce' ),
			'file_removed'              => __( 'File removed.', 'open-product-fields-for-woocommerce' ),
			'upload_complete'           => __( 'Upload complete.', 'open-product-fields-for-woocommerce' ),
		];
	}

	/**
	 * Frontend date format precedence: an explicit valid OPF setting wins,
	 * then a valid WAPF value, then the canonical default.
	 */
	private static function frontend_date_format(): string {
		return DateFormat::configured();
	}

	/**
	 * Subset of the legacy pricing-format config the theme integration reads.
	 */
	private static function compat_config(): array {
		return apply_filters( 'opf_frontend_config', [
			'ajax'            => admin_url( 'admin-ajax.php' ),
			'currency'        => get_woocommerce_currency(),
			'display_options' => [
				'symbol'      => get_woocommerce_currency_symbol(),
				'thousand'    => wc_get_price_thousand_separator(),
				'decimal'     => wc_get_price_decimal_separator(),
				'decimals'    => wc_get_price_decimals(),
				'price_format' => str_replace( array( '%1$s', '%2$s' ), array( 'symbol', 'price' ), get_woocommerce_price_format() ),
			],
		] );
	}

	/**
	 * Admin assets for the builder screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function register_admin( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, [ 'opf_field_group' ], true ) || ! str_contains( $hook, 'post.php' ) && ! str_contains( $hook, 'post-new.php' ) ) {
			return;
		}
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'opf-builder', OPF_URL . 'assets/css/opf-builder.css', [], OPF_VERSION );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'opf-builder', OPF_URL . 'assets/js/opf-builder.js', [ 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wc-enhanced-select' ], OPF_VERSION, true );
		wp_set_script_translations( 'opf-builder', 'open-product-fields-for-woocommerce', OPF_DIR . 'languages' );
	}
}
