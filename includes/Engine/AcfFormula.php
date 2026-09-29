<?php
/**
 * Numeric ACF values exposed to pricing formulas.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class AcfFormula {

	/** Resolve only numeric ACF fields referenced by formulas in a group. */
	public static function variable_values_for_group( array $group_data, int $product_id ): array {
		if ( ! function_exists( 'get_field' ) ) {
			return [];
		}

		$selectors = [ 'field' => [], 'option' => [] ];
		foreach ( (array) ( $group_data['fields'] ?? [] ) as $field ) {
			$formulas = [ (string) ( $field['formula'] ?? '' ), (string) ( $field['pricing']['formula'] ?? '' ) ];
			foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
				$formulas[] = (string) ( $choice['pricing']['formula'] ?? '' );
			}
			foreach ( $formulas as $formula ) {
				if ( preg_match_all( '/\bacf(_option)?\s*\(\s*([a-zA-Z][a-zA-Z0-9_-]{0,63})\s*\)/i', $formula, $matches, PREG_SET_ORDER ) ) {
					foreach ( $matches as $match ) {
						$source = empty( $match[1] ) ? 'field' : 'option';
						$selectors[ $source ][ strtolower( $match[2] ) ] = $match[2];
					}
				}
			}
		}

		$values = [];
		foreach ( $selectors as $source => $names ) {
			foreach ( $names as $normalized => $selector ) {
				if ( 'field' === $source && $product_id < 1 ) {
					continue;
				}
				$post_id = 'option' === $source ? 'option' : $product_id;
				$key = 'opf_acf_' . $source . '_' . $normalized;
				$values[ $key ] = self::numeric_value( get_field( $selector, $post_id, false ) );
			}
		}
		return $values;
	}

	/** ACF can return arrays/objects; formulas accept only finite numeric scalars. */
	private static function numeric_value( $value ): float {
		if ( is_bool( $value ) ) {
			return $value ? 1.0 : 0.0;
		}
		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && '' !== trim( $value ) && is_numeric( trim( $value ) ) ) ) {
			return 0.0;
		}
		$number = (float) $value;
		return is_finite( $number ) ? $number : 0.0;
	}
}
