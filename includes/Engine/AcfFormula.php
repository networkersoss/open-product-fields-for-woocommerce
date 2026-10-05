<?php
/**
 * Numeric ACF values exposed to pricing formulas.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class AcfFormula {

	/**
	 * Register the `acf(selector)` and `acf_option(selector)` formula
	 * functions with the calculator.
	 *
	 * Both read the pre-resolved numeric map carried in
	 * `$context['options']['formula_variables']` — produced by
	 * {@see variable_values_for_group()} (or the group-level
	 * FieldGroup::resolve_product_formula_variables bridge). Unresolved or
	 * malformed selectors evaluate to 0, matching WAPF's fail-closed ACF
	 * substitution.
	 */
	public static function register(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		$resolve = static function ( array $args, array $context, string $source ): float {
			$selector = trim( (string) ( $args[0] ?? '' ) );
			if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $selector ) ) {
				return 0.0;
			}
			$key    = 'opf_acf_' . $source . '_' . strtolower( $selector );
			$values = is_array( $context['options']['formula_variables'] ?? null ) ? $context['options']['formula_variables'] : [];
			$value  = $values[ $key ] ?? 0.0;
			return is_numeric( $value ) && is_finite( (float) $value ) ? (float) $value : 0.0;
		};
		Calculator::register_formula_function( 'acf', static function ( array $args, array $context ) use ( $resolve ): float {
			return $resolve( $args, $context, 'field' );
		} );
		Calculator::register_formula_function( 'acf_option', static function ( array $args, array $context ) use ( $resolve ): float {
			return $resolve( $args, $context, 'option' );
		} );
	}

	/** Resolve only numeric ACF fields referenced by formulas in a group. */
	public static function variable_values_for_group( array $group_data, int $product_id ): array {
		self::register();
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
