<?php
/**
 * Asset loading. Zero bytes on any page that doesn't render fields.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use WC_Product;
use function add_action;
use function admin_url;
use function get_current_screen;
use function get_option;
use function get_queried_object_id;
use function get_woocommerce_currency;
use function get_woocommerce_currency_symbol;
use function get_woocommerce_price_format;
use function is_product;
use function wp_date;
use function wp_enqueue_media;
use function wp_enqueue_script;
use function wp_enqueue_script_module;
use function wp_enqueue_style;
use function wp_print_inline_script_tag;
use function wp_register_script;
use function wp_register_script_module;
use function wp_register_style;
use function wc_get_price_decimal_separator;
use function wc_get_price_decimals;
use function wc_get_price_thousand_separator;
use function wc_get_product;
use function wp_json_encode;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_live_preview_fonts' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register_admin' ] );
	}

	/** Enqueue preview fonts before wp_head prints stylesheets. */
	public static function enqueue_live_preview_fonts(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! Renderer::visible_to_viewer() || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof WC_Product || empty( LivePreview::for_product( $product ) ) || empty( LivePreviewFonts::registered() ) ) {
			return;
		}

		// Renderer::render() runs at woocommerce_before_add_to_cart_button,
		// which can be after wp_head. This inline-only handle also survives
		// themes that bundle and dequeue the frontend stylesheet.
		wp_enqueue_style( 'opf-live-preview-font-faces' );
		LivePreviewFonts::enqueue_faces( 'opf-live-preview-font-faces' );
	}

	/**
	 * Register (not enqueue) frontend assets; Renderer enqueues on render.
	 */
	public static function register_frontend(): void {
		$ver = OPF_VERSION;

		if ( function_exists( 'wp_register_script_module' ) ) {
			wp_register_script_module(
				'opf-frontend',
				OPF_URL . 'assets/js/opf-frontend.min.js',
				// The frontend uses native DOM APIs and imports no modules.
				// A static dependency here downloads the unused Interactivity
				// runtime on every product that renders fields.
				[],
				$ver
			);
		} else {
			wp_register_script( 'opf-frontend', OPF_URL . 'assets/js/opf-frontend.min.js', [], $ver, true );
		}

		wp_register_style( 'opf-frontend', OPF_URL . 'assets/css/opf-frontend.css', [], $ver );
		wp_register_style( 'opf-live-preview-font-faces', false, [], $ver );
	}

	/**
	 * Enqueue what the renderer needs. Only called when fields exist on page.
	 *
	 * @param array<string,mixed> $registry Field metadata for the client.
	 */
	public static function enqueue_frontend( array $registry = [] ): void {
		// Keep the native-DOM module external. WordPress's safe inline-script
		// serializer encodes ampersands in raw-text script bodies, which turns
		// JavaScript operators such as && into literal entity text and prevents
		// the browser from parsing the module.
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( 'opf-frontend' );
		} else {
			wp_enqueue_script( 'opf-frontend' );
		}
		// Script modules bypass wp_scripts, so wp_add_inline_script() is a
		// no-op here. Classic inline scripts execute immediately — before the
		// deferred module — which is exactly the ordering the registry needs.
		if ( $registry ) {
			$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
			wp_print_inline_script_tag(
				'window.OPF_FIELDS = ' . wp_json_encode( $registry['fields'] ?? [], $json_flags ) . ';' .
				'window.OPF_LOOKUP_TABLES = ' . wp_json_encode( $registry['lookup_tables'] ?? [], $json_flags ) . ';' .
				'window.OPF_IMAGE_RULES = ' . wp_json_encode( $registry['image_rules'] ?? [], $json_flags ) . ';' .
				'window.OPF_IMAGE_RULE_MODES = ' . wp_json_encode( $registry['image_rule_modes'] ?? [], $json_flags ) . ';' .
				'window.OPF_FORMULA_VARIABLES = ' . wp_json_encode( $registry['formula_variables'] ?? [], $json_flags ) . ';' .
				'window.OPF_ACF_VARIABLES = ' . wp_json_encode( $registry['acf_variables'] ?? [], $json_flags ) . ';' .
				'window.OPF_LIVE_PREVIEWS = ' . wp_json_encode( $registry['live_previews'] ?? [], $json_flags ) . ';' .
				'window.OPF_LAYERED_IMAGES = ' . wp_json_encode( $registry['layered_images'] ?? [], $json_flags ) . ';'
			);
		}
		$site_today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		wp_print_inline_script_tag( 'window.OPF_TODAY = ' . wp_json_encode( $site_today ) . ';' );
		$hint_settings = [
			'show'     => 'yes' === get_option( 'opf_show_price_hints', 'yes' ),
			'brackets' => 'yes' === get_option( 'opf_price_hint_brackets', 'yes' ),
			'plus'     => 'yes' === get_option( 'opf_price_hint_plus', 'yes' ),
		];
		$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		wp_print_inline_script_tag(
			'window.OPF_PRICE_DISPLAY = ' . wp_json_encode( self::price_display_options(), $json_flags ) . ';' .
			'window.OPF_PRICE_HINTS = ' . wp_json_encode( $hint_settings, $json_flags ) . ';'
		);
		if ( Renderer::compat() ) {
			// Theme integration reads this global for price formatting (opf_config; wapf_config fallback lives in the theme JS).
			wp_print_inline_script_tag(
				'window.opf_config = ' . wp_json_encode( self::compat_config() ) . ';'
			);
		}
		wp_enqueue_style( 'opf-frontend' );
	}

	/**
	 * Subset of the legacy pricing-format config the theme integration reads.
	 */
	private static function compat_config(): array {
		return [
			'ajax'            => admin_url( 'admin-ajax.php' ),
			'currency'        => get_woocommerce_currency(),
			'display_options' => self::price_display_options(),
		];
	}

	/** Currency display contract shared by price hints and legacy compatibility. */
	private static function price_display_options(): array {
		return [
			'symbol'       => get_woocommerce_currency_symbol(),
			'thousand'     => wc_get_price_thousand_separator(),
			'decimal'      => wc_get_price_decimal_separator(),
			'decimals'     => wc_get_price_decimals(),
			'price_format' => str_replace( [ '%1$s', '%2$s' ], [ 'symbol', 'price' ], get_woocommerce_price_format() ),
		];
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
		wp_enqueue_style( 'opf-builder', OPF_URL . 'assets/css/opf-builder.css', [], OPF_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'opf-builder', OPF_URL . 'assets/js/opf-builder.js', [ 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n' ], OPF_VERSION, true );
	}
}
