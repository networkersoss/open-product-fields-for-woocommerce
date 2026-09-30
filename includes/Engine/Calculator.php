<?php
/**
 * Server-side pricing engine. The only place addon money is computed.
 *
 * Semantics (documented contract):
 *  - Fixed pricing is flat per cart line unless its per_unit flag is enabled.
 *  - percent : unit_price * amount / 100, with explicit `per_unit => false`
 *              divided by quantity to keep the computed fee flat per line.
 *  - fixed   : amount (shop currency; currency plugins may convert via opf_fixed_price filter)
 *  - formula : expression over [price] (base unit price), [addons] (addons computed
 *              before this choice, per unit), [qty] (line quantity), [val] (text input).
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class Calculator {

	/**
	 * Compute the per-unit addon price for a field selection.
	 *
	 * @param array<string,mixed>      $field   Normalized field array.
	 * @param string|array<int,string> $value   Submitted value(s) (choice slugs or raw text).
	 * @param array{price?:float,qty?:int,addons?:float,field_values?:array<string,mixed>,lookup_tables?:array<string,array>,formula_variables?:array<string,float>,field_prices?:array<string,float>} $context Pricing context.
	 * @return float Signed per-unit addon; the final product price is clamped at zero.
	 */
	public static function field_addon( array $field, $value, array $context ): float {
		$price  = (float) ( $context['price'] ?? 0.0 );
		$qty    = max( 1, (int) ( $context['qty'] ?? 1 ) );
		$addons = (float) ( $context['addons'] ?? 0.0 );
		$field_values = is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [];
		$lookup_tables = is_array( $context['lookup_tables'] ?? null ) ? $context['lookup_tables'] : [];
		$formula_variables = is_array( $context['formula_variables'] ?? null ) ? $context['formula_variables'] : [];
		$field_prices = is_array( $context['field_prices'] ?? null ) ? $context['field_prices'] : [];

		$total = 0.0;

			switch ( $field['type'] ) {
			case 'swatch':
			case 'select':
			case 'radio':
			case 'checkbox':
				$quantities = 'swatch' === $field['type'] && ! empty( $field['image_quantities'] ) && is_array( $value );
				$slugs = $quantities ? array_keys( $value ) : ( is_array( $value ) ? $value : [ $value ] );
				foreach ( $slugs as $slug ) {
					foreach ( $field['choices'] as $choice ) {
						if ( $choice['slug'] === (string) $slug && ! $choice['disabled'] ) {
							$choice_quantity = $quantities ? max( 0, (int) ( $value[ $slug ] ?? 0 ) ) : 1;
							$total += self::choice_addon( $choice['pricing'], $price, $qty, $addons, '', $field_values, $lookup_tables, $formula_variables, $field_prices ) * $choice_quantity;
							if ( ! $quantities && 'checkbox' !== $field['type'] && ( 'swatch' !== $field['type'] || empty( $field['multiple'] ) ) ) {
								break;
							}
						}
					}
				}
				break;

			default:
				// Text-like fields use field-level pricing only.
				$amount = is_scalar( $value ) ? (string) $value : '';
			$total += self::field_pricing_addon( $field['pricing'], $amount, $price, $qty, $addons, $field_values, $lookup_tables, $formula_variables, $field_prices );
				break;
		}

		return (float) $total;
	}

	/** Return the configured product-weight delta for selected choices. */
	public static function field_weight_delta( array $field, $value, array $field_values = [], array $lookup_tables = [], array $formula_variables = [] ): float {
		$delta = 0.0;
		if ( isset( $field['weight_formula'] ) && is_string( $field['weight_formula'] ) && '' !== trim( $field['weight_formula'] ) ) {
			$numeric_values = array_change_key_case( $field_values, CASE_LOWER );
			$resolved_weight = self::evaluate_formula( $field['weight_formula'], 0.0, 1, 0.0, is_scalar( $value ) ? (string) $value : '', $numeric_values, null, [], $lookup_tables, $formula_variables );
			$delta += is_finite( $resolved_weight ) ? $resolved_weight : 0.0;
		}
		if ( ! in_array( $field['type'] ?? '', [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			return $delta;
		}
		$quantities = 'swatch' === $field['type'] && ! empty( $field['image_quantities'] ) && is_array( $value );
		$selected = $quantities ? array_keys( $value ) : ( is_array( $value ) ? $value : [ $value ] );
		$selected = array_map( 'strval', $selected );
		foreach ( $field['choices'] as $choice ) {
			if ( in_array( (string) $choice['slug'], $selected, true ) && empty( $choice['disabled'] ) ) {
				$quantity = $quantities ? max( 0, (int) ( $value[ $choice['slug'] ] ?? 0 ) ) : 1;
				$delta += (float) ( $choice['weight'] ?? 0.0 ) * $quantity;
			}
		}
		return $delta;
	}

	/**
	 * Addon for a single choice — expressed as the PER-UNIT contribution to
	 * the line price. Flat (per_unit=false) amounts are divided by quantity
	 * so the line total adds exactly the flat fee (WAPF parity).
	 *
	 * @param array<string,mixed> $pricing Normalized choice pricing.
	 */
	public static function choice_addon( array $pricing, float $price, int $qty, float $addons, string $value = '', array $field_values = [], array $lookup_tables = [], array $formula_variables = [], array $field_prices = [] ): float {
		$qty = max( 1, $qty );
		switch ( $pricing['type'] ) {
			case 'fixed':
				$amount = (float) $pricing['amount'];
				return self::quantity_scaled_amount( $amount, $pricing, $qty );
			case 'percent':
				return self::quantity_scaled_amount( $price * ( (float) $pricing['amount'] / 100 ), $pricing, $qty );
			case 'formula':
				return self::evaluate_formula( $pricing['formula'], $price, $qty, $addons, $value, $field_values, null, [], $lookup_tables, $formula_variables, $field_prices ) / $qty;
			default:
				return 0.0;
		}
	}

	/**
	 * Field-level pricing for text-like input — per-unit contribution
	 * (see normalize_pricing for the semantics table).
	 *
	 * @param array<string,mixed> $pricing Normalized field pricing.
	 */
	public static function field_pricing_addon( array $pricing, string $value, float $price, int $qty, float $addons, array $field_values = [], array $lookup_tables = [], array $formula_variables = [], array $field_prices = [] ): float {
		if ( '' === trim( $value ) ) {
			return 0.0;
		}
		$qty = max( 1, $qty );
		switch ( $pricing['type'] ) {
			case 'fixed':
				$amount = (float) $pricing['amount'];
				return self::quantity_scaled_amount( $amount, $pricing, $qty );
			case 'percent':
				return self::quantity_scaled_amount( $price * ( (float) $pricing['amount'] / 100 ), $pricing, $qty );
			case 'quantity':
				return (float) $pricing['amount'];
			case 'characters':
				$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
				return self::quantity_scaled_amount( (float) $pricing['amount'] * $length, $pricing, $qty );
			case 'value':
				return is_numeric( $value ) ? self::quantity_scaled_amount( (float) $pricing['amount'] * (float) $value, $pricing, $qty ) : 0.0;
			case 'formula':
				return self::evaluate_formula( $pricing['formula'], $price, $qty, $addons, $value, $field_values, null, [], $lookup_tables, $formula_variables, $field_prices ) / $qty;
			default:
				return 0.0;
		}
	}

	/** Convert a configured line total into its per-product contribution. */
	private static function quantity_scaled_amount( float $amount, array $pricing, int $qty ): float {
		$per_unit = array_key_exists( 'per_unit', $pricing )
			? (bool) $pricing['per_unit']
			: ( 'fixed' !== ( $pricing['type'] ?? '' ) );
		return $per_unit ? $amount : $amount / max( 1, $qty );
	}

	/**
	 * Resolve submitted values and derived calculation outputs in dependency order.
	 *
	 * Calculations may refer forward to other fields, so this uses a dependency
	 * walk instead of field display order. A dependency cycle is unavailable and
	 * omitted; it cannot make a conditional pass or contribute a price.
	 *
	 * @param array<int,array<string,mixed>> $fields Normalized group fields.
	 * @param array<string,mixed>            $submitted Sanitized submitted values.
	 * @param array<string,mixed>            $context Formula context.
	 * @return array<string,mixed> Visible input values plus visible numeric calculations.
	 */
	public static function resolve_calculation_values( array $fields, array $submitted, array $context = [] ): array {
		$by_id = [];
		$raw = [];
		foreach ( $fields as $field ) {
			$id = strtolower( (string) ( $field['id'] ?? '' ) );
			if ( '' !== $id ) {
				$by_id[ $id ] = $field;
			}
		}
		foreach ( $submitted as $id => $value ) {
			$raw[ strtolower( (string) $id ) ] = $value;
		}

		$values = [];
		$states = [];
		$stack = [];
		$cycles = [];
		$resolve = null;
		$resolve = static function ( string $id ) use ( &$resolve, &$values, &$states, &$stack, &$cycles, $by_id, $raw, $fields, $context ): bool {
			$id = strtolower( $id );
			if ( 'resolved' === ( $states[ $id ] ?? '' ) ) {
				return array_key_exists( $id, $values );
			}
			if ( 'resolving' === ( $states[ $id ] ?? '' ) ) {
				$cycle_start = array_search( $id, $stack, true );
				if ( false !== $cycle_start ) {
					foreach ( array_slice( $stack, $cycle_start ) as $cycle_id ) {
						$cycles[ $cycle_id ] = true;
					}
				}
				return false;
			}
			if ( ! isset( $by_id[ $id ] ) ) {
				return false;
			}

			$field = $by_id[ $id ];
			$states[ $id ] = 'resolving';
			$stack[] = $id;
			$dependencies = [];
			foreach ( (array) ( $field['conditionals'] ?? [] ) as $conditional ) {
				foreach ( (array) ( $conditional['rules'] ?? [] ) as $rule ) {
					$dependency = strtolower( (string) ( $rule['field'] ?? '' ) );
					if ( '' !== $dependency ) {
						$dependencies[] = $dependency;
					}
				}
			}
			if ( 'calculation' === ( $field['type'] ?? '' ) ) {
				preg_match_all( '/\\[field\\.([a-zA-Z0-9_-]+)\\]|(?:checked|files|sumQty)\\s*\\(\\s*([a-zA-Z0-9_-]+)\\s*\\)/i', (string) ( $field['formula'] ?? '' ), $matches, PREG_SET_ORDER );
				foreach ( $matches as $match ) {
					$dependency = strtolower( (string) ( $match[1] ?? $match[2] ?? '' ) );
					if ( '' !== $dependency ) {
						$dependencies[] = $dependency;
					}
				}
				if ( preg_match_all( '/lookuptable\\s*\\(([^()]*)\\)/i', (string) ( $field['formula'] ?? '' ), $lookup_matches ) ) {
					foreach ( $lookup_matches[1] as $lookup_args ) {
						$args = array_slice( array_map( 'trim', explode( ';', $lookup_args ) ), 1 );
						foreach ( $args as $dependency ) {
							$dependency = preg_replace( '/^\\[field\\.([a-zA-Z0-9_-]+)\\]$/', '$1', $dependency );
							if ( preg_match( '/^[a-zA-Z0-9_-]+$/', (string) $dependency ) ) {
								$dependencies[] = strtolower( (string) $dependency );
							}
						}
					}
				}
			}
			$dependencies = array_values( array_unique( $dependencies ) );
			$dependencies_available = true;
			foreach ( $dependencies as $dependency ) {
				$dependency_visible = $resolve( $dependency );
				if ( ( ! $dependency_visible && isset( $cycles[ $dependency ] ) ) || ( ! $dependency_visible && 'calculation' === ( $by_id[ $dependency ]['type'] ?? '' ) ) ) {
					$dependencies_available = false;
				}
			}
			array_pop( $stack );

			if ( isset( $cycles[ $id ] ) || ! $dependencies_available ) {
				$states[ $id ] = 'resolved';
				unset( $values[ $id ] );
				return false;
			}
			if ( ! empty( $field['conditionals'] ) ) {
				foreach ( $dependencies as $dependency ) {
					if ( isset( $cycles[ $dependency ] ) ) {
						$states[ $id ] = 'resolved';
						unset( $values[ $id ] );
						return false;
					}
				}
			}
			if ( ! Evaluator::is_visible( $field, $values ) ) {
				$states[ $id ] = 'resolved';
				unset( $values[ $id ] );
				return false;
			}

			if ( 'calculation' === ( $field['type'] ?? '' ) ) {
				$resolved = self::evaluate_formula(
					(string) ( $field['formula'] ?? '' ),
					(float) ( $context['price'] ?? 0.0 ),
					max( 1, (int) ( $context['qty'] ?? 1 ) ),
					(float) ( $context['addons'] ?? 0.0 ),
					'',
					$values,
					isset( $context['today'] ) ? (string) $context['today'] : null,
					(array) ( $context['file_counts'] ?? [] ),
					(array) ( $context['lookup_tables'] ?? [] ),
					(array) ( $context['formula_variables'] ?? [] ),
					(array) ( $context['field_prices'] ?? [] )
				);
				if ( ! is_finite( $resolved ) ) {
					$states[ $id ] = 'resolved';
					return false;
				}
				$values[ $id ] = $resolved;
			} elseif ( array_key_exists( $id, $raw ) ) {
				$values[ $id ] = $raw[ $id ];
			}
			$states[ $id ] = 'resolved';
			return array_key_exists( $id, $values );
		};

		foreach ( array_keys( $by_id ) as $id ) {
			$resolve( $id );
		}
		return $values;
	}

	/**
	 * Resolve each selected field's per-item option price for `[price.{id}]`.
	 * Formula references are resolved on demand, so later fields can be read;
	 * cyclic references fail closed to zero. `[addons]` retains field order.
	 *
	 * @param array<int,array<string,mixed>> $fields Normalized fields in group order.
	 * @param array<string,mixed>            $field_values Validated or preview values.
	 * @param array<string,mixed>            $context Base pricing context.
	 * @return array<string,float> Lowercase field ID => per-item addon.
	 */
	public static function field_price_map( array $fields, array $field_values, array $context = [] ): array {
		$fields_by_id = [];
		$indexes = [];
		foreach ( $fields as $index => $field ) {
			$id = strtolower( (string) ( $field['id'] ?? '' ) );
			if ( '' !== $id ) {
				$fields_by_id[ $id ] = $field;
				$indexes[ $id ] = $index;
			}
		}
		$values = [];
		foreach ( $field_values as $id => $value ) {
			$values[ strtolower( (string) $id ) ] = $value;
		}

		$prices = [];
		$states = [];
		$stack = [];
		$cycles = [];
		$resolve = null;
		$resolve = static function ( string $id ) use ( &$resolve, &$prices, &$states, &$stack, &$cycles, $fields, $fields_by_id, $indexes, $values, $context ): float {
			$id = strtolower( $id );
			if ( array_key_exists( $id, $prices ) ) {
				return $prices[ $id ];
			}
			if ( 'resolving' === ( $states[ $id ] ?? '' ) ) {
				$cycle_start = array_search( $id, $stack, true );
				if ( false !== $cycle_start ) {
					foreach ( array_slice( $stack, $cycle_start ) as $cycle_id ) {
						$cycles[ $cycle_id ] = true;
					}
				}
				return 0.0;
			}
			if ( ! isset( $fields_by_id[ $id ], $indexes[ $id ] ) ) {
				return 0.0;
			}
			$field = $fields_by_id[ $id ];
			if ( ! array_key_exists( $id, $values ) || ! Evaluator::is_visible( $field, $values ) ) {
				$prices[ $id ] = 0.0;
				return 0.0;
			}

			$states[ $id ] = 'resolving';
			$stack[] = $id;
			$value = $values[ $id ];
			foreach ( self::formula_price_references( $field, $value ) as $reference ) {
				$resolve( $reference );
			}
			$addons = (float) ( $context['addons'] ?? 0.0 );
			foreach ( $fields as $index => $preceding_field ) {
				if ( $index >= $indexes[ $id ] ) {
					break;
				}
				$preceding_id = strtolower( (string) ( $preceding_field['id'] ?? '' ) );
				if ( '' !== $preceding_id && 'resolving' !== ( $states[ $preceding_id ] ?? '' ) ) {
					$addons += $resolve( $preceding_id );
				}
			}
			$computed = self::field_addon(
				$field,
				$value,
				[
					'price' => (float) ( $context['price'] ?? 0.0 ),
					'qty' => max( 1, (int) ( $context['qty'] ?? 1 ) ),
					'addons' => $addons,
					'field_values' => $values,
					'lookup_tables' => (array) ( $context['lookup_tables'] ?? [] ),
					'formula_variables' => (array) ( $context['formula_variables'] ?? [] ),
					'field_prices' => $prices,
				]
			);
			array_pop( $stack );
			$prices[ $id ] = isset( $cycles[ $id ] ) ? 0.0 : $computed;
			$states[ $id ] = 'resolved';
			return $prices[ $id ];
		};

		foreach ( array_keys( $fields_by_id ) as $id ) {
			$resolve( $id );
		}
		return $prices;
	}

	/** Extract `[price.id]` dependencies from the selected field pricing rules. */
	private static function formula_price_references( array $field, $value ): array {
		$formulas = [];
		$type = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			$quantities = 'swatch' === $type && ! empty( $field['image_quantities'] ) && is_array( $value );
			$selected = $quantities ? array_map( 'strval', array_keys( $value ) ) : ( is_array( $value ) ? array_map( 'strval', $value ) : [ (string) $value ] );
			foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
				if ( in_array( (string) ( $choice['slug'] ?? '' ), $selected, true ) && empty( $choice['disabled'] ) && 'formula' === ( $choice['pricing']['type'] ?? '' ) ) {
					$formulas[] = (string) ( $choice['pricing']['formula'] ?? '' );
				}
			}
		} elseif ( 'formula' === ( $field['pricing']['type'] ?? '' ) ) {
			$formulas[] = (string) ( $field['pricing']['formula'] ?? '' );
		}
		$references = [];
		foreach ( $formulas as $formula ) {
			if ( preg_match_all( '/\[price\.([a-zA-Z0-9_-]+)\]/i', $formula, $matches ) ) {
				foreach ( $matches[1] as $reference ) {
					$references[] = strtolower( $reference );
				}
			}
		}
		return array_values( array_unique( $references ) );
	}

	/**
	 * Safely evaluate a formula expression.
	 *
	 * Supports arithmetic, numeric comparisons inside if/and/or, min/max/abs,
	 * and numeric context tokens. No eval() — recursive descent parser. Any
	 * syntax error, division by zero, or non-finite result yields 0.0. Lookup
	 * tables use rows of ordered dimension values followed by the price.
	 * Unknown [var_name] tokens also resolve to 0.0; WAPF imports containing
	 * custom variables are separately held for review until definitions map.
	 */
	public static function evaluate_formula( string $formula, float $price, int $qty, float $addons, string $val = '', array $field_values = [], ?string $today = null, array $file_counts = [], array $lookup_tables = [], array $formula_variables = [], array $field_prices = [] ): float {
		if ( '' === trim( $formula ) || strlen( $formula ) > 4096 ) {
			return 0.0;
		}
		$formula = preg_replace_callback(
			'/lookuptable\s*\(([^()]*)\)/i',
			static function ( array $match ) use ( $field_values, $lookup_tables ): string {
				$args = array_map( 'trim', explode( ';', $match[1] ) );
				$table_name = array_shift( $args );
				if ( count( $args ) < 1 || ! preg_match( '/^[a-zA-Z0-9_]+$/', (string) $table_name ) ) {
					return '0';
				}
				return (string) self::lookup_table_value( (string) $table_name, $args, $field_values, $lookup_tables );
			},
			$formula
		);
		if ( ! is_string( $formula ) ) {
			return 0.0;
		}
		$formula = preg_replace_callback(
			'/\bacf(_option)?\s*\(\s*([a-zA-Z][a-zA-Z0-9_-]{0,63})\s*\)/i',
			static function ( array $match ) use ( $formula_variables ): string {
				$source = empty( $match[1] ) ? 'field' : 'option';
				$value = $formula_variables[ 'opf_acf_' . $source . '_' . strtolower( $match[2] ) ] ?? 0.0;
				return is_numeric( $value ) && is_finite( (float) $value ) ? (string) (float) $value : '0';
			},
			$formula
		);
		if ( ! is_string( $formula ) ) {
			return 0.0;
		}
		$formula = preg_replace_callback(
			'/\[price\.([a-zA-Z0-9_-]+)\]/i',
			static function ( array $match ) use ( $field_prices ): string {
				$value = $field_prices[ strtolower( $match[1] ) ] ?? 0.0;
				return is_numeric( $value ) && is_finite( (float) $value ) ? (string) (float) $value : '0';
			},
			$formula
		);
		if ( ! is_string( $formula ) ) {
			return 0.0;
		}
		$formula = preg_replace_callback(
			'/\\[var_([a-zA-Z][a-zA-Z0-9_]{0,63})\\]/',
			static function ( array $match ) use ( $formula_variables ): string {
				$value = $formula_variables[ strtolower( $match[1] ) ] ?? 0;
				return is_numeric( $value ) && is_finite( (float) $value ) ? (string) (float) $value : '0';
			},
			$formula
		);
		if ( ! is_string( $formula ) ) {
			return 0.0;
		}
		$formula = preg_replace_callback(
			'/len\s*\(([^()]*)\)/i',
			static function ( array $match ) use ( $field_values ): string {
				$args = explode( ';', $match[1], 2 );
				$argument = trim( $args[0] );
				if ( preg_match( '/^\[field\.([a-zA-Z0-9_-]+)\]$/', $argument, $field_match ) ) {
					$value = $field_values[ strtolower( $field_match[1] ) ] ?? '';
					$argument = is_scalar( $value ) ? (string) $value : '';
				}
				$ignore_spaces = isset( $args[1] ) && 'true' === strtolower( trim( $args[1] ) );
				if ( isset( $args[1] ) && ! in_array( strtolower( trim( $args[1] ) ), [ 'true', 'false' ], true ) ) {
					return '0';
				}
				if ( $ignore_spaces ) {
					$argument = preg_replace( '/\s+/u', '', $argument );
					$argument = is_string( $argument ) ? $argument : '';
				}
				if ( function_exists( 'mb_strlen' ) ) {
					return (string) mb_strlen( $argument, 'UTF-8' );
				}
				return (string) ( preg_match_all( '/./us', $argument, $unused ) ?: 0 );
			},
			$formula
		);
		if ( ! is_string( $formula ) ) {
			return 0.0;
		}
		if ( null === $today || ! self::is_formula_date( $today ) ) {
			$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}
		$formula = preg_replace_callback(
			'/checked\s*\(\s*([a-zA-Z0-9_-]+)\s*\)/i',
			static function ( array $match ) use ( $field_values ): string {
				$value = $field_values[ strtolower( $match[1] ) ] ?? null;
				if ( ! is_array( $value ) ) {
					return '0';
				}
				$selected = array_filter( $value, static fn( $item ): bool => is_scalar( $item ) && '' !== (string) $item );
				return (string) count( $selected );
			},
			$formula
		);
		$formula = preg_replace_callback(
			'/files\s*\(\s*([a-zA-Z0-9_-]+)\s*\)/i',
			static function ( array $match ) use ( $file_counts ): string {
				return (string) max( 0, (int) ( $file_counts[ strtolower( $match[1] ) ] ?? 0 ) );
			},
			$formula
		);
		$formula = preg_replace_callback(
			'/sumQty\s*\(\s*([a-zA-Z0-9_-]+)\s*\)/i',
			static function ( array $match ) use ( $field_values ): string {
				$value = $field_values[ strtolower( $match[1] ) ] ?? null;
				if ( ! is_array( $value ) ) {
					return '0';
				}
				$total = 0;
				foreach ( $value as $quantity ) {
					if ( is_scalar( $quantity ) && preg_match( '/^\d+$/', (string) $quantity ) ) {
						$total += (int) $quantity;
					}
				}
				return (string) $total;
			},
			$formula
		);
		$formula = preg_replace( '/today\s*\(\s*\)/i', '__OPF_TODAY__', $formula );
		$formula = preg_replace_callback(
			'/\b(dow|month)\s*\(([^()]*)\)/i',
			static function ( array $match ) use ( $field_values, $today ): string {
				$date = self::formula_date_argument( $match[2], $field_values, (string) $today );
				if ( null === $date ) {
					return '0';
				}
				return 'dow' === strtolower( $match[1] ) ? $date->format( 'w' ) : $date->format( 'n' );
			},
			$formula
		);
		$formula = preg_replace_callback(
			'/datediff\s*\(([^()]*)\)/i',
			static function ( array $match ) use ( $field_values, $today ): string {
				$args = explode( ';', $match[1] );
				if ( 2 !== count( $args ) ) {
					return '0';
				}
				$dates = [];
				foreach ( $args as $argument ) {
					$date = self::formula_date_argument( $argument, $field_values, (string) $today );
					if ( null === $date ) {
						return '0';
					}
					$dates[] = $date;
				}
				return (string) $dates[0]->diff( $dates[1] )->format( '%r%a' );
			},
			$formula
		);
		if ( ! is_string( $formula ) ) {
			return 0.0;
		}
		$formula = preg_replace_callback(
			'/\[field\.([a-zA-Z0-9_-]+)\]\s*(>=|<=|!=|=|>|<)\s*([A-Za-z][^;,()]*)/i',
			static function ( array $match ) use ( $field_values ): string {
				$value = $field_values[ strtolower( $match[1] ) ] ?? null;
				$right = trim( $match[3] );
				if ( ! is_scalar( $value ) || is_numeric( $value ) || is_numeric( $right ) ) {
					return $match[0];
				}
				$left = (string) $value;
				$compare = strcmp( $left, $right );
				$passed = false;
				switch ( $match[2] ) {
					case '=':
						$passed = 0 === $compare;
						break;
					case '!=':
						$passed = 0 !== $compare;
						break;
					case '>':
						$passed = $compare > 0;
						break;
					case '<':
						$passed = $compare < 0;
						break;
					case '>=':
						$passed = $compare >= 0;
						break;
					case '<=':
						$passed = $compare <= 0;
						break;
				}
				return $passed ? '1' : '0';
			},
			$formula
		);
		if ( ! is_string( $formula ) ) {
			return 0.0;
		}
		$formula = preg_replace_callback(
			'/\[field\.([a-zA-Z0-9_-]+)\]/',
			static function ( array $match ) use ( $field_values ): string {
				$value = $field_values[ strtolower( $match[1] ) ] ?? null;
				return is_scalar( $value ) && is_numeric( $value ) && is_finite( (float) $value ) ? (string) (float) $value : '0';
			},
			$formula
		);
		if ( ! is_string( $formula ) ) {
			return 0.0;
		}
		$formula = str_replace(
			[ '[price]', '[qty]', '[addons]', '[options_total]', '[val]' ],
			[ ' P ', ' Q ', ' A ', ' A ', ' V ' ],
			$formula
		);
		$vars = [ 'P' => $price, 'Q' => (float) $qty, 'A' => $addons, 'V' => (float) $val ];

		$tokens = self::tokenize( $formula, $vars );
		if ( null === $tokens ) {
			return 0.0;
		}
		if ( count( $tokens ) > 512 ) {
			return 0.0;
		}
		$pos   = 0;
		$value = self::parse_expression( $tokens, $pos );
		if ( null === $value || $pos < count( $tokens ) ) {
			return 0.0;
		}
		// Negatives allowed here (formulas may offset other addons); the
		// final addon total is clamped at the field_addon boundary.
		return is_finite( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Resolve a native list-form lookup table.
	 *
	 * Rows contain one cell per input dimension followed by the price. Exact
	 * tuples work for any dimension count. Numeric ceiling fallback is applied
	 * to one- and two-dimensional tables, matching WAPF's documented matrix
	 * behavior; higher-dimensional lists require an exact tuple.
	 *
	 * @param string[]               $field_ids Field IDs in table-column order.
	 * @param array<string,mixed>     $field_values Submitted values.
	 * @param array<string,array>     $lookup_tables Name => row arrays.
	 */
	private static function lookup_table_value( string $table_name, array $field_ids, array $field_values, array $lookup_tables ): float {
		$table = $lookup_tables[ $table_name ] ?? null;
		$dimensions = count( $field_ids );
		if ( ! is_array( $table ) || $dimensions < 1 || $dimensions > 64 ) {
			return 0.0;
		}

		$input = [];
		foreach ( $field_ids as $field_id ) {
			if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $field_id ) ) {
				return 0.0;
			}
			$value = $field_values[ strtolower( $field_id ) ] ?? null;
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				return 0.0;
			}
			$input[] = trim( (string) $value );
		}

		$rows = [];
		foreach ( $table as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== $dimensions + 1 ) {
				continue;
			}
			$price = array_pop( $row );
			if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) ) {
				continue;
			}
			$coordinates = [];
			$valid = true;
			foreach ( $row as $coordinate ) {
				if ( ! is_scalar( $coordinate ) ) {
					$valid = false;
					break;
				}
				$coordinates[] = trim( (string) $coordinate );
			}
			if ( $valid ) {
				$rows[] = [ $coordinates, (float) $price ];
			}
		}
		if ( ! $rows ) {
			return 0.0;
		}

		foreach ( $rows as [ $coordinates, $price ] ) {
			if ( self::lookup_coordinates_equal( $input, $coordinates ) ) {
				return $price;
			}
		}
		if ( $dimensions > 2 ) {
			return 0.0;
		}

		$ceiling = [];
		for ( $dimension = 0; $dimension < $dimensions; $dimension++ ) {
			$candidates = [];
			$all_numeric = is_numeric( $input[ $dimension ] );
			foreach ( $rows as [ $coordinates ] ) {
				$coordinate = $coordinates[ $dimension ];
				if ( ! is_numeric( $coordinate ) ) {
					$all_numeric = false;
				}
				if ( self::lookup_value_equal( $input[ $dimension ], $coordinate ) ) {
					$candidates[] = $coordinate;
				} elseif ( is_numeric( $input[ $dimension ] ) && is_numeric( $coordinate ) && (float) $coordinate >= (float) $input[ $dimension ] ) {
					$candidates[] = $coordinate;
				}
			}
			if ( ! $candidates ) {
				return 0.0;
			}
			if ( $all_numeric && is_numeric( $input[ $dimension ] ) ) {
				$candidates = array_filter( $candidates, static fn( string $candidate ): bool => (float) $candidate >= (float) $input[ $dimension ] );
				if ( ! $candidates ) {
					return 0.0;
				}
				usort( $candidates, static fn( string $left, string $right ): int => (float) $left <=> (float) $right );
				$ceiling[] = $candidates[0];
			} else {
				$exact = array_values( array_filter( $candidates, static fn( string $candidate ): bool => self::lookup_value_equal( $input[ $dimension ], $candidate ) ) );
				if ( ! $exact ) {
					return 0.0;
				}
				$ceiling[] = $exact[0];
			}
		}

		foreach ( $rows as [ $coordinates, $price ] ) {
			if ( self::lookup_coordinates_equal( $ceiling, $coordinates ) ) {
				return $price;
			}
		}
		return 0.0;
	}

	/** Compare one lookup coordinate as exact text or numeric values. */
	private static function lookup_value_equal( string $left, string $right ): bool {
		return is_numeric( $left ) && is_numeric( $right )
			? (float) $left === (float) $right
			: $left === $right;
	}

	/** Compare all dimensions in a lookup tuple. */
	private static function lookup_coordinates_equal( array $left, array $right ): bool {
		if ( count( $left ) !== count( $right ) ) {
			return false;
		}
		foreach ( $left as $index => $value ) {
			if ( ! self::lookup_value_equal( (string) $value, (string) $right[ $index ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Resolve a date literal, today(), or validated date-field reference. */
	private static function formula_date_argument( string $argument, array $field_values, string $today ): ?\DateTimeImmutable {
		$argument = trim( $argument );
		if ( '__OPF_TODAY__' === $argument ) {
			$argument = $today;
		} elseif ( preg_match( '/^\[field\.([a-zA-Z0-9_-]+)\]$/', $argument, $field_match ) ) {
			$value = $field_values[ strtolower( $field_match[1] ) ] ?? null;
			$argument = is_scalar( $value ) ? (string) $value : '';
		}
		return self::formula_date( $argument );
	}

	/** Parse strict ISO or the configured WAPF-style date format. */
	private static function formula_date( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );
		if ( strlen( $value ) >= 2 && ( ( "'" === $value[0] && "'" === substr( $value, -1 ) ) || ( '"' === $value[0] && '"' === substr( $value, -1 ) ) ) ) {
			$value = substr( $value, 1, -1 );
		}
		if ( self::is_formula_date( $value ) ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
			return $date instanceof \DateTimeImmutable ? $date : null;
		}

		$format = function_exists( 'get_option' )
			? get_option( 'opf_date_format', get_option( 'wapf_date_format', DateFormat::DEFAULT_FORMAT ) )
			: DateFormat::DEFAULT_FORMAT;
		$format = DateFormat::normalize( $format );
		preg_match_all( '/yyyy|yy|mm|m|dd|d|[-\/., ]/i', $format, $format_tokens );
		if ( 5 !== count( $format_tokens[0] ) ) {
			return null;
		}
		$pattern = '';
		$capture_index = 0;
		$date_parts = [];
		foreach ( $format_tokens[0] as $token ) {
			$token = strtolower( $token );
			if ( in_array( $token, [ '-', '/', '.', ',', ' ' ], true ) ) {
				$pattern .= preg_quote( $token, '/' );
				continue;
			}
			$pattern .= '(\\d{' . ( in_array( $token, [ 'yyyy' ], true ) ? '4' : ( in_array( $token, [ 'yy', 'mm', 'dd' ], true ) ? '2' : '1,2' ) ) . '})';
			$date_parts[ $token ] = ++$capture_index;
		}
		if ( ! preg_match( '/^' . $pattern . '$/', $value, $date_tokens ) || 3 !== $capture_index ) {
			return null;
		}
		$parts = [];
		foreach ( $format_tokens[0] as $token ) {
			$token = strtolower( $token );
			if ( isset( $date_parts[ $token ] ) ) {
				$parts[ $token ] = (int) $date_tokens[ $date_parts[ $token ] ];
			}
		}
		$year = $parts['yyyy'] ?? $parts['yy'] ?? null;
		if ( isset( $parts['yy'] ) ) {
			$year = $year < 70 ? 2000 + $year : 1900 + $year;
		}
		$month = $parts['mm'] ?? $parts['m'] ?? null;
		$day   = $parts['dd'] ?? $parts['d'] ?? null;
		if ( null === $year || null === $month || null === $day || ! checkdate( $month, $day, $year ) ) {
			return null;
		}
		return \DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-%02d', $year, $month, $day ), new \DateTimeZone( 'UTC' ) ) ?: null;
	}

	/** Return true for an exact, valid YYYY-MM-DD date. */
	private static function is_formula_date( string $value ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		return $date instanceof \DateTimeImmutable && ( ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Tokenize. A variable letter is valid only as a standalone token.
	 *
	 * @param array<string,float> $vars Variable values.
	 * @return array<int,array{t:string,v:float}>|null
	 */
	private static function tokenize( string $formula, array $vars ): ?array {
		$tokens = [];
		$len    = strlen( $formula );
		$i      = 0;
		while ( $i < $len ) {
			$ch = $formula[ $i ];
			if ( ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch ) {
				$i++;
				continue;
			}
			if ( isset( $vars[ $ch ] ) && ( $i + 1 >= $len || ! ctype_alpha( $formula[ $i + 1 ] ) ) ) {
				$tokens[] = [ 't' => 'num', 'v' => $vars[ $ch ] ];
				$i++;
				continue;
			}
			if ( preg_match( '/(min|max|abs|if|and|or|round|ceil|floor|pow|sqrt|sin|cos|tan)\s*\(/Ai', substr( $formula, $i ), $m ) ) {
				$tokens[] = [ 't' => 'fn', 'v' => strtolower( $m[1] ) ];
				$i += strlen( $m[0] ) - 1;
				continue;
			}
			if ( preg_match( '/(>=|<=|!=|=|>|<)/A', substr( $formula, $i ), $m ) ) {
				$tokens[] = [ 't' => 'cmp', 'v' => $m[1] ];
				$i += strlen( $m[0] );
				continue;
			}
			if ( preg_match( '/\d+(?:\.\d+)?/', substr( $formula, $i ), $m ) && ( '.' === $ch || ctype_digit( $ch ) ) ) {
				$tokens[] = [ 't' => 'num', 'v' => (float) $m[0] ];
				$i       += strlen( $m[0] );
				continue;
			}
			if ( false !== strpos( '+-*/(),;', $ch ) && 1 === strlen( $ch ) ) {
				$tokens[] = [ 't' => $ch, 'v' => 0.0 ];
				$i++;
				continue;
			}
			return null;
		}
		return $tokens;
	}

	/**
	 * expression := term (('+'|'-') term)*
	 *
	 * @param array<int,array{t:string,v:float}> $tokens Tokens.
	 * @param int                            $pos    Cursor (by reference).
	 */
	private static function parse_expression( array $tokens, int &$pos ): ?float {
		$value = self::parse_term( $tokens, $pos );
		while ( null !== $value && $pos < count( $tokens ) && in_array( $tokens[ $pos ]['t'], [ '+', '-' ], true ) ) {
			$op = $tokens[ $pos ]['t'];
			$pos++;
			$right = self::parse_term( $tokens, $pos );
			if ( null === $right ) {
				return null;
			}
			$value = '+' === $op ? $value + $right : $value - $right;
		}
		return $value;
	}

	/**
	 * term := factor (('*'|'/') factor)*
	 *
	 * @param array<int,array{t:string,v:float}> $tokens Tokens.
	 * @param int                            $pos    Cursor (by reference).
	 */
	private static function parse_term( array $tokens, int &$pos ): ?float {
		$value = self::parse_factor( $tokens, $pos );
		while ( null !== $value && $pos < count( $tokens ) && in_array( $tokens[ $pos ]['t'], [ '*', '/' ], true ) ) {
			$op = $tokens[ $pos ]['t'];
			$pos++;
			$right = self::parse_factor( $tokens, $pos );
			if ( null === $right || ( '/' === $op && 0.0 === $right ) ) {
				return null;
			}
			$value = '*' === $op ? $value * $right : $value / $right;
		}
		return $value;
	}

	/**
	 * factor := number | '(' expression ')' | '-' factor
	 *
	 * @param array<int,array{t:string,v:float}> $tokens Tokens.
	 * @param int                            $pos    Cursor (by reference).
	 */
	private static function parse_factor( array $tokens, int &$pos ): ?float {
		if ( $pos >= count( $tokens ) ) {
			return null;
		}
		$token = $tokens[ $pos ];
		if ( 'num' === $token['t'] ) {
			$pos++;
			return $token['v'];
		}
		if ( '(' === $token['t'] ) {
			$pos++;
			$value = self::parse_expression( $tokens, $pos );
			if ( null === $value || $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] ) {
				return null;
			}
			$pos++;
			return $value;
		}
		if ( '-' === $token['t'] ) {
			$pos++;
			$value = self::parse_factor( $tokens, $pos );
			return null === $value ? null : -$value;
		}
		if ( 'fn' === $token['t'] ) {
			$function = $token['v'];
			$pos++;
			if ( $pos >= count( $tokens ) || '(' !== $tokens[ $pos ]['t'] ) {
				return null;
			}
			$pos++;
			if ( 'if' === $function ) {
				$condition = self::parse_condition( $tokens, $pos );
				if ( null === $condition || $pos >= count( $tokens ) || ! in_array( $tokens[ $pos ]['t'], [ ';', ',' ], true ) ) {
					return null;
				}
				$pos++;
				$when_true = self::parse_expression( $tokens, $pos );
				if ( null === $when_true || $pos >= count( $tokens ) || ! in_array( $tokens[ $pos ]['t'], [ ';', ',' ], true ) ) {
					return null;
				}
				$pos++;
				$when_false = self::parse_expression( $tokens, $pos );
				if ( null === $when_false || $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] ) {
					return null;
				}
				$pos++;
				return 0.0 !== $condition ? $when_true : $when_false;
			}
			if ( in_array( $function, [ 'and', 'or' ], true ) ) {
				$results = [];
				do {
					$result = self::parse_condition( $tokens, $pos );
					if ( null === $result ) {
						return null;
					}
					$results[] = 0.0 !== $result;
					if ( $pos >= count( $tokens ) || ! in_array( $tokens[ $pos ]['t'], [ ';', ',' ], true ) ) {
						break;
					}
					$pos++;
				} while ( true );
				if ( $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] || empty( $results ) ) {
					return null;
				}
				$pos++;
				$passed = 'and' === $function ? ! in_array( false, $results, true ) : in_array( true, $results, true );
				return $passed ? 1.0 : 0.0;
			}
			$first = self::parse_expression( $tokens, $pos );
			if ( null === $first ) {
				return null;
			}
			if ( in_array( $function, [ 'abs', 'ceil', 'floor', 'sqrt', 'sin', 'cos', 'tan' ], true ) ) {
				if ( $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] ) {
					return null;
				}
				$pos++;
				switch ( $function ) {
					case 'abs':
						return abs( $first );
					case 'ceil':
						return ceil( $first );
					case 'floor':
						return floor( $first );
					case 'sqrt':
						return sqrt( $first );
					case 'sin':
						return sin( $first );
					case 'cos':
						return cos( $first );
					default:
						return tan( $first );
				}
			}
			if ( 'round' === $function ) {
				$precision = 0;
				if ( $pos < count( $tokens ) && in_array( $tokens[ $pos ]['t'], [ ',', ';' ], true ) ) {
					$pos++;
					$precision_value = self::parse_expression( $tokens, $pos );
					if ( null === $precision_value || floor( $precision_value ) !== $precision_value || abs( $precision_value ) > 8 ) {
						return null;
					}
					$precision = (int) $precision_value;
				}
				if ( $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] ) {
					return null;
				}
				$pos++;
				return round( $first, $precision, PHP_ROUND_HALF_UP );
			}
			if ( in_array( $function, [ 'min', 'max' ], true ) && $pos < count( $tokens ) && ')' === $tokens[ $pos ]['t'] ) {
				$pos++;
				return $first;
			}
			if ( $pos >= count( $tokens ) || ! in_array( $tokens[ $pos ]['t'], [ ',', ';' ], true ) ) {
				return null;
			}
			$pos++;
			$second = self::parse_expression( $tokens, $pos );
			if ( null === $second ) {
				return null;
			}
			if ( 'pow' === $function ) {
				if ( $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] ) {
					return null;
				}
				$pos++;
				return pow( $first, $second );
			}
			$arguments = [ $first, $second ];
			while ( $pos < count( $tokens ) && in_array( $tokens[ $pos ]['t'], [ ',', ';' ], true ) ) {
				$pos++;
				$argument = self::parse_expression( $tokens, $pos );
				if ( null === $argument ) {
					return null;
				}
				$arguments[] = $argument;
			}
			if ( $pos >= count( $tokens ) || ')' !== $tokens[ $pos ]['t'] ) {
				return null;
			}
			$pos++;
			return 'min' === $function ? min( $arguments ) : max( $arguments );
		}
		return null;
	}

	/** comparison := expression [comparison-op expression] */
	private static function parse_condition( array $tokens, int &$pos ): ?float {
		$left = self::parse_expression( $tokens, $pos );
		if ( null === $left || $pos >= count( $tokens ) || 'cmp' !== $tokens[ $pos ]['t'] ) {
			return $left;
		}
		$operator = $tokens[ $pos ]['v'];
		$pos++;
		$right = self::parse_expression( $tokens, $pos );
		if ( null === $right ) {
			return null;
		}
		switch ( $operator ) {
			case '=':
				$passed = $left === $right;
				break;
			case '!=':
				$passed = $left !== $right;
				break;
			case '>':
				$passed = $left > $right;
				break;
			case '<':
				$passed = $left < $right;
				break;
			case '>=':
				$passed = $left >= $right;
				break;
			case '<=':
				$passed = $left <= $right;
				break;
			default:
				$passed = false;
		}
		return $passed ? 1.0 : 0.0;
	}
}
