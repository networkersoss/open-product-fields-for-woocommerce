<?php
/** Simple-product WooCommerce price label controls. */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class ProductPriceDisplay {
	public static function init(): void {
		add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'filter_price_html' ], 20, 2 );
		add_action( 'woocommerce_product_options_pricing', [ __CLASS__, 'render_product_fields' ] );
		add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_product_fields' ] );
	}

	/** Add the display controls to Product Data → General → Pricing for simple products. */
	public static function render_product_fields(): void {
		$product_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( $product && ! $product->is_type( 'simple' ) ) {
			return;
		}

		woocommerce_wp_select( [
			'id'            => '_opf_price_display',
			'label'         => __( 'Product price display', 'open-product-fields-for-woocommerce' ),
			'description'   => __( 'Keep, hide, or add a label around the WooCommerce price on this simple product page.', 'open-product-fields-for-woocommerce' ),
			'desc_tip'      => true,
			'value'         => $product ? ( $product->get_meta( '_opf_price_display', true ) ?: 'default' ) : 'default',
			'options'       => self::mode_labels(),
			'wrapper_class' => 'show_if_simple',
		] );
		woocommerce_wp_text_input( [
			'id'            => '_opf_price_label',
			'label'         => __( 'Product price label', 'open-product-fields-for-woocommerce' ),
			'description'   => __( 'Plain text shown before or after the price when selected above.', 'open-product-fields-for-woocommerce' ),
			'desc_tip'      => true,
			'value'         => $product ? $product->get_meta( '_opf_price_label', true ) : '',
			'wrapper_class' => 'show_if_simple',
		] );
	}

	/** Save only the two supported metadata values. */
	public static function save_product_fields( $product ): void {
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
			return;
		}
		$mode = isset( $_POST['_opf_price_display'] ) ? sanitize_text_field( wp_unslash( $_POST['_opf_price_display'] ) ) : 'default'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! array_key_exists( $mode, self::mode_labels() ) ) {
			$mode = 'default';
		}
		if ( 'default' === $mode ) {
			$product->delete_meta_data( '_opf_price_display' );
		} else {
			$product->update_meta_data( '_opf_price_display', $mode );
		}

		$label = isset( $_POST['_opf_price_label'] ) ? sanitize_text_field( wp_unslash( $_POST['_opf_price_label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $label ) {
			$product->delete_meta_data( '_opf_price_label' );
		} else {
			$product->update_meta_data( '_opf_price_label', $label );
		}
	}

	/** Keep the setting scoped to simple-product detail pages. */
	public static function filter_price_html( string $price_html, $product ): string {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
			return $price_html;
		}
		if ( function_exists( 'get_queried_object_id' ) && (int) $product->get_id() !== (int) get_queried_object_id() ) {
			return $price_html;
		}

		$mode = (string) $product->get_meta( '_opf_price_display', true );
		$mode = '' === $mode ? 'default' : $mode;
		$label = $product->get_meta( '_opf_price_label', true );
		return self::format( $price_html, $mode, is_scalar( $label ) ? (string) $label : '' );
	}

	/** @return array<string,string> */
	private static function mode_labels(): array {
		return [
			'default' => __( 'Show WooCommerce price', 'open-product-fields-for-woocommerce' ),
			'hide'    => __( 'Hide price', 'open-product-fields-for-woocommerce' ),
			'replace' => __( 'Replace price with text', 'open-product-fields-for-woocommerce' ),
			'before'  => __( 'Label before price', 'open-product-fields-for-woocommerce' ),
			'after'   => __( 'Label after price', 'open-product-fields-for-woocommerce' ),
		];
	}

	/** Format the original WooCommerce price markup without altering the price itself. */
	public static function format( string $price_html, string $mode, string $label ): string {
		if ( 'hide' === $mode ) {
			return '';
		}
		if ( ! in_array( $mode, [ 'replace', 'before', 'after' ], true ) || '' === trim( $label ) ) {
			return $price_html;
		}

		$label_html = '<span class="opf-product-price-label">' . esc_html( trim( $label ) ) . '</span>';
		if ( 'replace' === $mode ) {
			return $label_html;
		}
		return 'before' === $mode ? $label_html . ' ' . $price_html : $price_html . ' ' . $label_html;
	}
}
