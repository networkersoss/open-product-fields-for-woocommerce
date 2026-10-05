<?php
/**
 * Resolve eligible WooCommerce products for child-product fields.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class ChildProductCatalog {

	/**
	 * Return products allowed by a normalized child-product field.
	 *
	 * @param array<string,mixed> $field     Normalized child-products field.
	 * @param int                 $parent_id Parent product ID to exclude.
	 * @return \WC_Product[]
	 */
	public static function products( array $field, int $parent_id = 0 ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$category_slugs = [];
		if ( 'categories' === ( $field['product_source'] ?? 'specific' ) ) {
			$category_ids = array_values( array_filter( array_map( 'absint', (array) ( $field['category_ids'] ?? [] ) ) ) );
			if ( ! $category_ids || ! function_exists( 'get_terms' ) ) {
				return [];
			}
			$terms = get_terms( [ 'taxonomy' => 'product_cat', 'include' => $category_ids, 'hide_empty' => true ] );
			if ( is_wp_error( $terms ) ) {
				return [];
			}
			$category_slugs = array_values( array_filter( array_map( static fn( $term ): string => is_object( $term ) && isset( $term->slug ) ? (string) $term->slug : '', (array) $terms ) ) );
			if ( ! $category_slugs ) {
				return [];
			}
		}

		$products = wc_get_products( self::query_args( $field, $category_slugs ) );
		if ( ! is_array( $products ) ) {
			return [];
		}

		$hide_out_of_stock = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		return array_values( array_filter( $products, static function ( $product ) use ( $parent_id, $hide_out_of_stock ): bool {
			if ( ! $product instanceof \WC_Product || $product->get_id() === $parent_id || ! $product->is_type( 'simple' ) ) {
				return false;
			}
			if ( 'publish' !== $product->get_status() || '' === (string) $product->get_price() || ! $product->is_purchasable() ) {
				return false;
			}
			return ! $hide_out_of_stock || $product->is_in_stock();
		} ) );
	}

	/**
	 * Build bounded WooCommerce query args. Category mode follows WAPF's
	 * documented cap of 50 products; specific product mode retains selected IDs.
	 *
	 * @param array<string,mixed> $field          Normalized child-products field.
	 * @param string[]            $category_slugs Resolved category slugs.
	 * @return array<string,mixed>
	 */
	public static function query_args( array $field, array $category_slugs = [] ): array {
		if ( 'categories' === ( $field['product_source'] ?? 'specific' ) ) {
			if ( ! $category_slugs ) {
				return [];
			}
			return [
				'category' => array_values( array_unique( $category_slugs ) ),
				'limit'    => 50,
				'orderby'  => 'title',
				'order'    => 'ASC',
				'status'   => 'publish',
				'return'   => 'objects',
			];
		}

		$product_ids = [];
		foreach ( (array) ( $field['product_ids'] ?? [] ) as $value ) {
			if ( is_scalar( $value ) && preg_match( '/^[1-9][0-9]*$/', (string) $value ) ) {
				$product_ids[] = (int) $value;
			}
		}
		$product_ids = array_values( array_unique( $product_ids ) );
		if ( ! $product_ids ) {
			return [];
		}
		return [
			'include' => $product_ids,
			'limit'   => count( $product_ids ),
			'orderby' => 'include',
			'status'  => 'publish',
			'return'  => 'objects',
		];
	}
}
