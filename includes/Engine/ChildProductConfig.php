<?php
/**
 * Normalize settings unique to a WAPF-compatible child-product field.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class ChildProductConfig {
	private const MAX_SPECIFIC_PRODUCTS = 500;
	private const MAX_CATEGORIES = 100;

	/**
	 * Return the bounded child-product settings stored on a field.
	 *
	 * @param array<string,mixed> $field Raw field settings.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $field ): array {
		$source = 'categories' === ( $field['product_source'] ?? '' ) ? 'categories' : 'specific';
		$display = in_array( $field['product_display'] ?? '', [ 'checkboxes', 'radio', 'select', 'cards', 'images' ], true )
			? $field['product_display']
			: 'checkboxes';
		$quantity_input = in_array( $field['quantity_input'] ?? false, [ true, 1, '1' ], true );
		$multiple = in_array( $field['multiple'] ?? ( 'checkboxes' === $display || 'images' === $display ), [ true, 1, '1' ], true );
		$min = self::nonnegative_integer( $field['min_selections'] ?? null );
		$max = self::nonnegative_integer( $field['max_selections'] ?? null );
		if ( null !== $min && null !== $max && $min > $max ) {
			throw new \InvalidArgumentException( 'Minimum child-product selections cannot exceed the maximum.' );
		}

		$normalized = [
			'product_source' => $source,
			'product_ids' => self::positive_ids( $field['product_ids'] ?? [], self::MAX_SPECIFIC_PRODUCTS ),
			'category_ids' => self::positive_ids( $field['category_ids'] ?? [], self::MAX_CATEGORIES ),
			'product_display' => $display,
			'multiple' => $multiple,
			'quantity_input' => $quantity_input,
			'image_zoom' => in_array( $field['image_zoom'] ?? false, [ true, 1, '1' ], true ),
			'quantity_mode' => $quantity_input && 'multiply_parent' === ( $field['quantity_mode'] ?? '' ) ? 'multiply_parent' : 'per_parent',
		];
		if ( null !== $min ) {
			$normalized['min_selections'] = $min;
		}
		if ( null !== $max ) {
			$normalized['max_selections'] = $max;
		}
		return $normalized;
	}

	/** @param mixed $value Raw integer setting. */
	private static function nonnegative_integer( $value ): ?int {
		if ( ! is_scalar( $value ) || ! preg_match( '/^(?:0|[1-9][0-9]*)$/', (string) $value ) ) {
			return null;
		}
		return (int) $value;
	}

	/** @param mixed $values Raw ID list. @return int[] */
	private static function positive_ids( $values, int $limit ): array {
		if ( ! is_array( $values ) ) {
			return [];
		}
		$ids = [];
		foreach ( array_slice( $values, 0, $limit ) as $value ) {
			if ( is_scalar( $value ) && preg_match( '/^[1-9][0-9]*$/', (string) $value ) ) {
				$ids[] = (int) $value;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
