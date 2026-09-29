<?php
/**
 * Validate submitted child-product selections before they reach WooCommerce.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class ChildProductSelection {

	/**
	 * Validate a submitted child-product value against products currently
	 * eligible for the field and resolve each selected product's cart quantity.
	 *
	 * @param array<string,mixed> $field       Normalized field settings.
	 * @param mixed               $submitted   Raw product IDs or ID-to-quantity map.
	 * @param int[]               $eligible_ids Product IDs resolved for this field.
	 * @param int                 $parent_qty  Requested quantity of the parent product.
	 * @return array{selection:array<int,int>,errors:string[]}
	 */
	public static function quantities( array $field, $submitted, array $eligible_ids, int $parent_qty ): array {
		$label         = (string) ( $field['label'] ?? '' );
		$quantity_input = ! empty( $field['quantity_input'] );
		$eligible      = [];
		foreach ( $eligible_ids as $eligible_id ) {
			if ( is_numeric( $eligible_id ) && (int) $eligible_id > 0 ) {
				$eligible[ (int) $eligible_id ] = true;
			}
		}

		if ( null === $submitted || '' === $submitted || [] === $submitted ) {
			$selected = [];
		} elseif ( is_array( $submitted ) ) {
			$selected = $submitted;
		} elseif ( is_scalar( $submitted ) ) {
			$selected = [ $submitted ];
		} else {
			return self::failure( sprintf( '"%s" contains an invalid product selection.', $label ) );
		}

		$raw_quantities = [];
		if ( $quantity_input ) {
			foreach ( $selected as $key => $raw_quantity ) {
				$id = is_int( $key ) ? $key : ( ctype_digit( (string) $key ) ? (int) $key : 0 );
				if ( $id < 1 || ! is_scalar( $raw_quantity ) || ! preg_match( '/^[0-9]+$/', (string) $raw_quantity ) ) {
					return self::failure( sprintf( '"%s" has an invalid product quantity.', $label ) );
				}
				$quantity = (int) $raw_quantity;
				if ( $quantity > 0 ) {
					$raw_quantities[ $id ] = $quantity;
				}
			}
		} else {
			foreach ( $selected as $raw_id ) {
				if ( ! is_scalar( $raw_id ) || ! preg_match( '/^[1-9][0-9]*$/', (string) $raw_id ) ) {
					return self::failure( sprintf( '"%s" contains an invalid product selection.', $label ) );
				}
				$id = (int) $raw_id;
				if ( isset( $raw_quantities[ $id ] ) ) {
					return self::failure( sprintf( '"%s" contains a duplicate product selection.', $label ) );
				}
				$raw_quantities[ $id ] = 1;
			}
		}

		foreach ( $raw_quantities as $product_id => $quantity ) {
			if ( ! isset( $eligible[ $product_id ] ) ) {
				return self::failure( sprintf( '"%s" contains an unavailable product.', $label ) );
			}
		}

		$count = count( $raw_quantities );
		if ( empty( $field['multiple'] ) && $count > 1 ) {
			return self::failure( sprintf( '"%s" allows one selection.', $label ) );
		}
		if ( 0 === $count ) {
			if ( ! empty( $field['required'] ) ) {
				return self::failure( sprintf( '"%s" is a required field.', $label ) );
			}
			if ( isset( $field['min_selections'] ) && (int) $field['min_selections'] > 0 ) {
				return self::failure( sprintf( '"%s" requires at least %d selection(s).', $label, (int) $field['min_selections'] ) );
			}
			return [ 'selection' => [], 'errors' => [] ];
		}
		if ( isset( $field['min_selections'] ) && $count < (int) $field['min_selections'] ) {
			return self::failure( sprintf( '"%s" requires at least %d selection(s).', $label, (int) $field['min_selections'] ) );
		}
		if ( isset( $field['max_selections'] ) && $count > (int) $field['max_selections'] ) {
			return self::failure( sprintf( '"%s" allows at most %d selection(s).', $label, (int) $field['max_selections'] ) );
		}

		$parent_qty = max( 1, $parent_qty );
		$multiply   = $quantity_input && 'multiply_parent' === ( $field['quantity_mode'] ?? '' );
		$quantities = [];
		foreach ( $raw_quantities as $product_id => $quantity ) {
			$factor = $quantity_input ? $quantity : 1;
			if ( $multiply || ! $quantity_input ) {
				if ( $factor > intdiv( PHP_INT_MAX, $parent_qty ) ) {
					return self::failure( sprintf( '"%s" has an invalid product quantity.', $label ) );
				}
				$factor *= $parent_qty;
			}
			$quantities[ (int) $product_id ] = $factor;
		}

		return [ 'selection' => $quantities, 'errors' => [] ];
	}

	/** @return array{selection:array<int,int>,errors:string[]} */
	private static function failure( string $message ): array {
		return [ 'selection' => [], 'errors' => [ $message ] ];
	}
}
