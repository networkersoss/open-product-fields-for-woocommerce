<?php
/** WooCommerce settings for OPF-wide presentation behavior. */

namespace OPF\Service\Admin;

use OPF\Engine\DateFormat;
use OPF\Service\LivePreviewFonts;
use OPF\Service\LookupTableCsvImporter;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public static function init(): void {
		add_filter( 'woocommerce_get_sections_products', [ __CLASS__, 'add_product_fields_section' ] );
		add_filter( 'woocommerce_get_settings_products', [ __CLASS__, 'product_fields_settings' ], 10, 2 );
		LivePreviewFonts::init();
		add_filter( 'woocommerce_admin_settings_sanitize_option_opf_date_format', [ __CLASS__, 'sanitize_date_format' ], 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_opf_choice_accent', [ __CLASS__, 'sanitize_color' ], 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_opf_choice_border', [ __CLASS__, 'sanitize_color' ], 10, 3 );
		add_action( 'woocommerce_admin_field_opf_lookup_csv_link', [ LookupTableCsvImporter::class, 'render_settings_link' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_preview_font_settings' ] );
		LookupTableCsvImporter::init_admin();
	}

	public static function enqueue_preview_font_settings( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $screen && 'woocommerce_page_wc-settings' === $screen->id && 'opf_live_preview' === $section ) {
			wp_enqueue_script( 'opf-preview-font-settings', OPF_URL . 'assets/js/opf-preview-font-settings.js', [], OPF_VERSION, true );
		}
	}

	/** @param array<string,string> $sections Product settings sections. */
	public static function add_product_fields_section( array $sections ): array {
		$sections['opf_product_fields'] = __( 'Product fields', 'open-product-fields-for-woocommerce' );
		$sections['opf_live_preview'] = __( 'Live Content Preview', 'open-product-fields-for-woocommerce' );
		return $sections;
	}

	/** @return array<int,array<string,mixed>> */
	public static function product_fields_settings( array $settings, string $current_section ): array {
		if ( 'opf_live_preview' === $current_section ) {
			return [
				[
					'title' => __( 'Live Content Preview', 'open-product-fields-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( 'Register custom fonts for product-local live text previews.', 'open-product-fields-for-woocommerce' ),
					'id'    => 'opf_live_preview',
				],
				[
					'title' => __( 'Custom preview fonts', 'open-product-fields-for-woocommerce' ),
					'type'  => 'opf_preview_font_manager',
					'id'    => 'opf_preview_font_manager',
				],
				[ 'type' => 'sectionend', 'id' => 'opf_live_preview' ],
			];
		}
		if ( 'opf_product_fields' !== $current_section ) {
			return $settings;
		}
		return [
			[
				'title' => __( 'Open Product Fields', 'open-product-fields-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Configure price summary, date presentation, price hints, number controls, and the modern file uploader for product fields.', 'open-product-fields-for-woocommerce' ),
				'id'    => 'opf_product_fields',
			],
			[
				'title'   => __( 'Display pricing summary', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Show the base price, options total, and grand total; show only the grand total; or hide the summary.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_price_summary_mode',
				'type'    => 'select',
				'default' => 'three_line',
				'options' => [
					'three_line' => __( 'Show 3-line summary', 'open-product-fields-for-woocommerce' ),
					'grand_total' => __( 'Show only grand total', 'open-product-fields-for-woocommerce' ),
					'hidden' => __( 'Hide summary', 'open-product-fields-for-woocommerce' ),
				],
			],
			[
				'title'   => __( 'Edit product options from cart', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Add an edit link to eligible customized cart lines. Upload and linked-child-product lines are excluded until their replacement lifecycle is supported.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_edit_cart',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Styled checkbox and radio controls', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Use the configured accent and border colors while keeping native checkbox and radio semantics.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_styled_choice_controls',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Choice control accent color', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Used for selected checkbox and radio controls when styling is enabled.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_choice_accent',
				'type'    => 'color',
				'default' => '#2271b1',
			],
			[
				'title'   => __( 'Choice control border color', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Used for checkbox and radio outlines when styling is enabled.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_choice_border',
				'type'    => 'color',
				'default' => '#68717c',
			],
			[
				'title'   => __( 'Number field plus and minus buttons', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Add accessible increment and decrement buttons beside number fields. The native number input remains the submitted value.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_number_buttons',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Modern file uploader', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Enable drag and drop, upload progress, file removal, and image previews. The standard browser file input remains available when disabled.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_modern_uploader',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'       => __( 'Date format', 'open-product-fields-for-woocommerce' ),
				'desc'        => __( 'Available placeholders: mm, m, dd, d, yyyy, yy. Use each of year, month, and day once; for example mm-dd-yyyy or d/m/yy.', 'open-product-fields-for-woocommerce' ),
				'id'          => 'opf_date_format',
				'type'        => 'text',
				'default'     => DateFormat::DEFAULT_FORMAT,
				'autoload'    => false,
			],
			[
				'title'   => __( 'Show Price Hints', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Show each choice’s configured price next to its label.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_show_price_hints',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( 'Percentage coupons discount base product price only', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Exclude Open Product Fields price add-ons from percentage coupon discounts. Fixed product and fixed cart coupons are unchanged.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_coupon_discount_base_only',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Wrap price hints in parentheses', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_price_hint_brackets',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'   => __( 'Show plus sign for positive price hints', 'open-product-fields-for-woocommerce' ),
				'desc'    => __( 'Formula-based hints keep the plus sign, matching WAPF behavior.', 'open-product-fields-for-woocommerce' ),
				'id'      => 'opf_price_hint_plus',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title' => __( 'Lookup table CSV', 'open-product-fields-for-woocommerce' ),
				'type'  => 'opf_lookup_csv_link',
				'id'    => 'opf_lookup_csv_link',
			],
			[
				'type' => 'sectionend',
				'id'   => 'opf_product_fields',
			],
		];
	}

	/** Preserve the last valid setting if an invalid value is posted. */
	public static function sanitize_date_format( $value, array $option = [], $raw_value = null ) {
		if ( ! is_string( $value ) || ! DateFormat::is_valid( $value ) ) {
			return null;
		}
		return DateFormat::normalize( $value );
	}

	/** Accept only valid CSS hex colors from WooCommerce settings. */
	public static function sanitize_color( $value, array $option = [], $raw_value = null ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( function_exists( 'sanitize_hex_color' ) ) {
			return sanitize_hex_color( $value );
		}
		return preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : null;
	}
}
