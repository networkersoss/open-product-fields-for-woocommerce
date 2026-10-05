<?php
/**
 * Server-side pricing engine. The only place addon money is computed.
 *
 * Semantics (documented contract — WAPF 3.1.5 do_pricing parity):
 *  - Every pricing type computes a `result` (fixed=amount,
 *    percent=base*amount/100, formula=evaluated expression).
 *  - per_unit=false → the line adds `result` once (per-unit share result/qty).
 *  - per_unit=true  → the line adds `result` per unit.
 *  - Quantity-repeat fields (WAPF clone_type=qty) use the qty_based row:
 *    per_unit=false → result per unit, per_unit=true → result*qty per unit.
 *  - formula : expression over [price] (base unit price), [addons] (addons computed
 *              before this choice, per unit), [qty] (line quantity), [val]/[x]
 *              (current value: entered text, choice label, or entered image
 *              quantity — the $v input of WAPF do_pricing()).
 *  - A formula that still references [qty] verbatim (not a mapper-normalized
 *    expression — i.e. no formula_raw, or formula_raw reduces to the stored
 *    formula) is a WAPF fx line-space expression: per_unit does not apply.
 *    The line adds exactly eval(formula): per-unit share result/qty on normal
 *    fields and result on qty_based fields. Mapper-stripped formulas keep
 *    [qty]-free per-unit semantics.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class Calculator {

	/** @var array<string,callable> */
	private static $formula_functions = [];

	/** Register a trusted extension callback through OPF\API. */
	public static function register_formula_function( string $function, callable $callback ): void {
		self::$formula_functions[ $function ] = $callback;
	}

	/**
	 * Compute the per-unit addon price for a field selection.
	 *
	 * @param array<string,mixed>      $field   Normalized field array.
	 * @param string|array<int,string> $value   Submitted value(s) (choice slugs or raw text).
	 * @param array{price?:float,qty?:int,addons?:float,field_values?:array<string,mixed>,field_prices?:array<string,float|array<int,float>>,product_id?:int,variables?:array,fields?:array,lookup_tables?:array} $context Pricing context.
	 * @return float Signed per-unit addon; the cart clamps the final product price.
	 */
	public static function field_addon( array $field, $value, array $context ): float {
		$price  = (float) ( $context['price'] ?? 0.0 );
		$qty    = max( 1, (int) ( $context['qty'] ?? 1 ) );
		$addons = (float) ( $context['addons'] ?? 0.0 );
		$field_values = is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [];
		$field_prices = is_array( $context['field_prices'] ?? null ) ? $context['field_prices'] : [];
		$field_labels = is_array( $context['field_labels'] ?? null ) ? $context['field_labels'] : [];
		// Optional WAPF formula context (variables, field defs, lookup tables,
		// resolved acf()/acf_option() variable values).
		$options = array_intersect_key( $context, array_flip( [ 'variables', 'fields', 'lookup_tables', 'formula_variables' ] ) );

		if ( ! empty( $field['repeat']['enabled'] ) ) {
			$instance_field = $field;
			unset( $instance_field['repeat'] );
			$instance_context = $context;
			// WAPF clone_type=qty → qty_based do_pricing semantics.
			$instance_context['qty_based'] = 'quantity' === (string) ( $field['repeat']['mode'] ?? '' );
			$instances = is_array( $value ) ? $value : ( null === $value ? [] : [ $value ] );
			$total = 0.0;
			foreach ( $instances as $instance_value ) {
				$total += self::field_addon( $instance_field, $instance_value, $instance_context );
			}
			return (float) $total;
		}

		$qty_based = ! empty( $context['qty_based'] );

		if ( 'image_quantity' === ( $field['type'] ?? '' ) ) {
			$total = 0.0;
			$quantities = is_array( $value ) && 'image_quantity' === ( $value['_opf_type'] ?? '' ) ? ( $value['quantities'] ?? [] ) : [];
			foreach ( $field['choices'] as $choice ) {
				$quantity = max( 0, (int) ( $quantities[ $choice['slug'] ] ?? 0 ) );
				if ( $quantity && empty( $choice['disabled'] ) ) {
					// WAPF image-swatch-qty passes the entered count into
					// do_pricing as $val (the value label) — nr/nrq/[x]
					// formulas consume it; the pricing type itself decides
					// whether the count multiplies the charge.
					$total += self::choice_addon( $choice['pricing'], $price, $qty, $addons, $field_values, (int) ( $context['product_id'] ?? 0 ), $field_prices, $qty_based, $field_labels, $options, (string) $quantity );
				}
			}
			return (float) $total;
		}

		$total = 0.0;
		if ( 'toggle' === $field['type'] && '1' !== (string) $value ) {
			return 0.0;
		}

		switch ( $field['type'] ) {
			case 'swatch':
			case 'select':
			case 'radio':
			case 'checkbox':
				$slugs = is_array( $value ) ? $value : [ $value ];
				foreach ( $slugs as $slug ) {
					foreach ( $field['choices'] as $choice ) {
						if ( $choice['slug'] === (string) $slug && ! $choice['disabled'] ) {
							// WAPF passes the selected choice's label as $val
							// (used by nr/char pricing and [x]/[val] formulas).
							$total += self::choice_addon( $choice['pricing'], $price, $qty, $addons, $field_values, (int) ( $context['product_id'] ?? 0 ), $field_prices, $qty_based, $field_labels, $options, (string) ( $choice['label'] ?? '' ) );
							if ( ! in_array( $field['type'], [ 'checkbox' ], true ) && !( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) ) ) {
								break;
							}
						}
					}
				}
				break;

			default:
				// Text-like fields use field-level pricing only.
				$amount = is_scalar( $value ) ? (string) $value : '';
				$total += self::field_pricing_addon( $field['pricing'], $amount, $price, $qty, $addons, $field_values, (int) ( $context['product_id'] ?? 0 ), $field_prices, $qty_based, $field_labels, $options );
				break;
		}

		return (float) $total;
	}

	/**
	 * Evaluate a WAPF Extended `calc` field's formula in pricing context.
	 *
	 * The informational and cost variants share the expression; only `cost`
	 * additionally feeds the result into `field_addon`. Reuses the single
	 * sandboxed Evaluator (no second engine).
	 *
	 * @param array<string,mixed> $field   Normalized calc field.
	 * @param array<string,mixed> $context Pricing context (price/qty/addons/field_values/...).
	 * @return float Signed computed value (0.0 when the formula is empty/invalid).
	 */
	public static function calc_value( array $field, array $context = [] ): float {
		if ( 'calc' !== ( $field['type'] ?? '' ) ) {
			return 0.0;
		}
		$formula = (string) ( $field['formula'] ?? '' );
		if ( '' === trim( $formula ) ) {
			return 0.0;
		}
		$options = array_intersect_key( $context, array_flip( [ 'variables', 'fields', 'lookup_tables', 'formula_variables' ] ) );
		$field_values = is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [];
		$field_prices = is_array( $context['field_prices'] ?? null ) ? $context['field_prices'] : [];
		$field_labels = is_array( $context['field_labels'] ?? null ) ? $context['field_labels'] : [];
		$value = is_scalar( $context['value'] ?? null ) ? (string) $context['value'] : '';
		return self::evaluate_formula(
			$formula,
			(float) ( $context['price'] ?? 0.0 ),
			max( 1, (int) ( $context['qty'] ?? 1 ) ),
			(float) ( $context['addons'] ?? 0.0 ),
			$value,
			null,
			$field_values,
			(int) ( $context['product_id'] ?? 0 ),
			$field_prices,
			$field_labels,
			$options
		);
	}

	/**
	 * Resolve submitted values and derived calculation outputs in dependency
	 * order.
	 *
	 * Calculation fields may refer forward to other fields, so this uses a
	 * dependency walk instead of field display order. A dependency cycle is
	 * unavailable and omitted; it cannot make a conditional pass or
	 * contribute a price. Conditional rules on calculation values therefore
	 * evaluate against server-computed results exactly like WAPF, never
	 * against forged submissions.
	 *
	 * Handles both field spellings: OPF's canonical `calc` and the legacy
	 * `calculation` type, sharing the `formula` key.
	 *
	 * @param array<int,array<string,mixed>> $fields    Normalized group fields.
	 * @param array<string,mixed>            $submitted Sanitized submitted values.
	 * @param array<string,mixed>            $context   Formula context.
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
		$is_calculation = static function ( array $field ): bool {
			return in_array( (string) ( $field['type'] ?? '' ), [ 'calculation', 'calc' ], true );
		};
		$options = array_intersect_key( $context, array_flip( [ 'variables', 'fields', 'lookup_tables', 'formula_variables' ] ) );

		$values = [];
		$states = [];
		$stack = [];
		$cycles = [];
		$resolve = null;
		$resolve = static function ( string $id ) use ( &$resolve, &$values, &$states, &$stack, &$cycles, $by_id, $raw, $context, $options, $is_calculation ): bool {
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
			if ( $is_calculation( $field ) ) {
				preg_match_all( '/\\[field\\.([a-zA-Z0-9_-]+)\\]|(?:checked|files|sumQty)\\s*\\(\\s*([a-zA-Z0-9_-]+)\\s*\\)/i', (string) ( $field['formula'] ?? '' ), $matches, PREG_SET_ORDER );
				foreach ( $matches as $match ) {
					// Unmatched alternation groups surface as '' (not null) —
					// files(art) must resolve the 'art' dependency, not ''.
					$dependency = '' !== (string) ( $match[1] ?? '' ) ? $match[1] : ( $match[2] ?? '' );
					$dependency = strtolower( (string) $dependency );
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
				if ( ( ! $dependency_visible && isset( $cycles[ $dependency ] ) ) || ( ! $dependency_visible && $is_calculation( $by_id[ $dependency ] ?? [] ) ) ) {
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

			if ( $is_calculation( $field ) ) {
				$resolved = self::evaluate_formula(
					(string) ( $field['formula'] ?? '' ),
					(float) ( $context['price'] ?? 0.0 ),
					max( 1, (int) ( $context['qty'] ?? 1 ) ),
					(float) ( $context['addons'] ?? 0.0 ),
					'',
					isset( $context['today'] ) ? (string) $context['today'] : null,
					$values,
					(int) ( $context['product_id'] ?? 0 ),
					(array) ( $context['field_prices'] ?? [] ),
					(array) ( $context['field_labels'] ?? [] ),
					$options
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
	 * Does this pricing block scale with line quantity? Explicit flags win;
	 * otherwise WAPF-type defaults: percent scales, fixed/formula are flat.
	 *
	 * @param array<string,mixed> $pricing Pricing block.
	 */
	private static function pricing_is_per_unit( array $pricing ): bool {
		return array_key_exists( 'per_unit', $pricing )
			? ! empty( $pricing['per_unit'] )
			: ! in_array( (string) ( $pricing['type'] ?? '' ), [ 'fixed', 'formula' ], true );
	}

	/**
	 * Addon for a single choice — expressed as the PER-UNIT contribution to
	 * the line price (WAPF do_pricing parity):
	 *  - normal fields      : per_unit ? result : result/qty
	 *  - qty-based fields   : per_unit ? result*qty : result
	 * (WAPF clone_type=qty; OPF repeat.mode=quantity splits those fields
	 * into cart lines whose quantity is the identical-unit count.)
	 *
	 * @param array<string,mixed> $pricing Normalized choice pricing.
	 * @param string              $val     WAPF $v for [x]/[val]/nr-style pricing:
	 *                                     the choice label or, for image_quantity,
	 *                                     the entered per-choice quantity.
	 */
	public static function choice_addon( array $pricing, float $price, int $qty, float $addons, array $field_values = [], int $product_id = 0, array $field_prices = [], bool $qty_based = false, array $field_labels = [], array $options = [], string $val = '' ): float {
		$qty = max( 1, $qty );
		$result = null;
		switch ( $pricing['type'] ) {
			case 'fixed':
				$result = (float) $pricing['amount'];
				break;
			case 'percent':
				$result = $price * ( (float) $pricing['amount'] / 100 );
				break;
			case 'formula':
				$result = self::evaluate_formula( $pricing['formula'], $price, $qty, $addons, $val, null, $field_values, $product_id, $field_prices, $field_labels, $options );
				break;
			default:
				return 0.0;
		}
		if ( self::formula_is_verbatim_wapf_expression( $pricing ) ) {
			// WAPF fx row: the line adds eval(formula) exactly once; per_unit
			// must not re-scale an expression that already consumed [qty].
			return $qty_based ? $result : $result / $qty;
		}
		if ( $qty_based ) {
			return self::pricing_is_per_unit( $pricing ) ? $result * $qty : $result;
		}
		return self::pricing_is_per_unit( $pricing ) ? $result : $result / $qty;
	}

	/**
	 * Field-level pricing for text-like input — per-unit contribution
	 * (same truth table as choice_addon; see normalize_pricing).
	 *
	 * @param array<string,mixed> $pricing Normalized field pricing.
	 */
	public static function field_pricing_addon( array $pricing, string $value, float $price, int $qty, float $addons, array $field_values = [], int $product_id = 0, array $field_prices = [], bool $qty_based = false, array $field_labels = [], array $options = [] ): float {
		if ( '' === trim( $value ) ) {
			return 0.0;
		}
		$qty = max( 1, $qty );
		$result = null;
		switch ( $pricing['type'] ) {
			case 'fixed':
				$result = (float) $pricing['amount'];
				break;
			case 'percent':
				$result = $price * ( (float) $pricing['amount'] / 100 );
				break;
			case 'formula':
				$result = self::evaluate_formula( $pricing['formula'], $price, $qty, $addons, $value, null, $field_values, $product_id, $field_prices, $field_labels, $options );
				break;
			default:
				return 0.0;
		}
		if ( self::formula_is_verbatim_wapf_expression( $pricing ) ) {
			// WAPF fx row: the line adds eval(formula) exactly once; per_unit
			// must not re-scale an expression that already consumed [qty].
			return $qty_based ? $result : $result / $qty;
		}
		if ( $qty_based ) {
			return self::pricing_is_per_unit( $pricing ) ? $result * $qty : $result;
		}
		return self::pricing_is_per_unit( $pricing ) ? $result : $result / $qty;
	}

	/**
	 * Additional product weight contributed by one field's submitted value,
	 * in the store's configured weight unit — WAPF 3.1.5
	 * Extended_Controller::maybe_calculate_weight parity (WAPF-COMMERCE-WEIGHT /
	 * WAPF-PRICE-FORMULA-WEIGHT):
	 *  - A field contributes only when it carries a `weight` expression itself
	 *    or one of its choices does (WAPF's calc_weight flag).
	 *  - Choice values (select/radio/checkbox/swatch) read the choice's weight
	 *    with [x] bound to the choice LABEL (WAPF $v = value label).
	 *  - Quantity-selector fields (image_quantity — WAPF qty_selector) evaluate
	 *    the choice weight per entered count and multiply by that count.
	 *  - Scalar fields read the field's weight with [x] bound to the submitted
	 *    value; empty submissions (WAPF raw === '') contribute nothing.
	 *  - Repeated fields sum each instance (each clone is its own WAPF cart
	 *    field).
	 * The signed sum is returned; the cart layer floors the merged product
	 * weight at zero exactly like WAPF.
	 *
	 * @param array<string,mixed> $field Normalized field array.
	 * @param string|array        $value Submitted value(s).
	 * @param int                 $qty   Cart line quantity ([qty] context).
	 * @return float Signed extra weight for this field on the cart line.
	 */
	public static function field_weight( array $field, $value, int $qty ): float {
		$has_weight = self::normalize_weight_string( $field['weight'] ?? null );
		$choices    = is_array( $field['choices'] ?? null ) ? $field['choices'] : [];
		if ( null === $has_weight ) {
			$has_weight = false;
			foreach ( $choices as $choice ) {
				if ( null !== self::normalize_weight_string( $choice['weight'] ?? null ) ) {
					$has_weight = true;
					break;
				}
			}
		} else {
			$has_weight = true;
		}
		if ( ! $has_weight ) {
			return 0.0;
		}

		if ( ! empty( $field['repeat']['enabled'] ) ) {
			$instance_field = $field;
			unset( $instance_field['repeat'] );
			$instances = is_array( $value ) ? $value : ( null === $value ? [] : [ $value ] );
			$total     = 0.0;
			foreach ( $instances as $instance_value ) {
				$total += self::field_weight( $instance_field, $instance_value, $qty );
			}
			return (float) $total;
		}

		$qty  = max( 1, $qty );
		$type = (string) ( $field['type'] ?? '' );

		// WAPF qty_selector: each entered count substitutes [x] and then
		// multiplies the evaluated weight (weight is configured PER COUNT).
		if ( 'image_quantity' === $type ) {
			$total      = 0.0;
			$quantities = is_array( $value ) && 'image_quantity' === ( $value['_opf_type'] ?? '' ) ? ( $value['quantities'] ?? [] ) : [];
			foreach ( $choices as $choice ) {
				$count = max( 0, (int) ( $quantities[ $choice['slug'] ] ?? 0 ) );
				if ( ! $count || ! empty( $choice['disabled'] ) ) {
					continue;
				}
				$weight = self::normalize_weight_string( $choice['weight'] ?? null );
				if ( null === $weight ) {
					continue;
				}
				$total += self::weight_expression( $weight, (string) $count, $qty ) * $count;
			}
			return (float) $total;
		}

		if ( in_array( $type, [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			$total = 0.0;
			$slugs = is_array( $value ) ? $value : [ $value ];
			foreach ( $slugs as $slug ) {
				foreach ( $choices as $choice ) {
					if ( $choice['slug'] === (string) $slug && empty( $choice['disabled'] ) ) {
						$weight = self::normalize_weight_string( $choice['weight'] ?? null );
						if ( null !== $weight ) {
							// WAPF substitutes [x] with the selected choice's
							// label for slugged values.
							$total += self::weight_expression( $weight, (string) ( $choice['label'] ?? '' ), $qty );
						}
						if ( ! in_array( $type, [ 'checkbox' ], true ) && ! ( 'swatch' === $type && ! empty( $field['multiple'] ) ) ) {
							break;
						}
					}
				}
			}
			return (float) $total;
		}

		// Value-bearing types without a usable weight path: linked products
		// contribute the child's own product weight via their cart line, and
		// static types carry no submission.
		if ( ! in_array( $type, [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'upload' ], true ) ) {
			return 0.0;
		}
		$field_weight = self::normalize_weight_string( $field['weight'] ?? null );
		if ( null === $field_weight ) {
			return 0.0;
		}
		if ( 'toggle' === $type && '1' !== (string) $value ) {
			return 0.0;
		}
		if ( is_scalar( $value ) ) {
			$raw = (string) $value;
			if ( '' === trim( $raw ) ) {
				return 0.0;
			}
			return self::weight_expression( $field_weight, $raw, $qty );
		}
		if ( is_array( $value ) ) {
			// Upload submissions are token arrays; WAPF joins the file names
			// into one scalar raw value and its cart field carries a SINGLE
			// slug-less value, so the field weight is applied exactly once with
			// [x] bound to the comma-joined raw (not once per file).
			$scalars = array_values( array_filter( array_map( static function ( $item ): string {
				return is_scalar( $item ) ? (string) $item : '';
			}, $value ), static function ( string $item ): bool {
				return '' !== $item;
			} ) );
			if ( ! $scalars ) {
				return 0.0;
			}
			return self::weight_expression( $field_weight, implode( ', ', $scalars ), $qty );
		}
		return 0.0;
	}

	/**
	 * Evaluate one WAPF weight expression — 3.1.5 substitutes [qty] and [x]
	 * into the stored string, runs it through the wapf/field_weight filter
	 * (OPF: opf_field_weight) and floatvals the result. It does NOT evaluate
	 * arithmetic ('[x]*0.5' with x=4 yields 4, not 2); true formula weight is
	 * a 3.2 feature.
	 *
	 * @param string $expression Stored weight expression.
	 * @param string $value      [x] context (raw submission or choice label).
	 * @param int    $qty        [qty] context (cart line quantity).
	 */
	private static function weight_expression( string $expression, string $value, int $qty ): float {
		$substituted = str_replace( [ '[qty]', '[x]' ], [ (string) $qty, $value ], $expression );
		if ( function_exists( 'apply_filters' ) ) {
			$substituted = (string) apply_filters(
				'opf_field_weight',
				$substituted,
				[
					'weight' => $expression,
					'value'  => $value,
					'qty'    => $qty,
				]
			);
		}
		return (float) $substituted; // floatval — WAPF 3.1.5 weight is not arithmetic.
	}

	/**
	 * Cast a stored weight option to its expression string, mirroring
	 * FieldGroup::normalize_weight acceptance (scalar, non-empty).
	 *
	 * @param mixed $weight Stored weight option.
	 */
	private static function normalize_weight_string( $weight ): ?string {
		if ( ! is_scalar( $weight ) ) {
			return null;
		}
		$weight = trim( (string) $weight );
		return '' === $weight ? null : $weight;
	}

	/**
	 * Is the stored formula an un-normalized WAPF fx line-space expression?
	 *
	 * WAPF fx evaluates the author's expression at the line quantity and
	 * divides by qty for the per-unit cart price (or returns it verbatim on
	 * qty_based fields). WAPFMapper strips a compensating outermost `* [qty]`
	 * for normal fields and records the original in `formula_raw`; a stored
	 * formula that still mentions [qty] while matching its raw source (or
	 * having no raw source at all) was never normalized, so it must be priced
	 * with the fx row — otherwise per_unit would multiply the line total by
	 * the quantity twice (WAPF↔OPF divergence at qty>1).
	 *
	 * @param array<string,mixed> $pricing Normalized pricing block.
	 */
	private static function formula_is_verbatim_wapf_expression( array $pricing ): bool {
		if ( 'formula' !== ( $pricing['type'] ?? '' ) ) {
			return false;
		}
		$formula = (string) ( $pricing['formula'] ?? '' );
		if ( '' === $formula || false === stripos( $formula, '[qty]' ) ) {
			return false;
		}
		$raw = $pricing['formula_raw'] ?? null;
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return true;
		}
		// The importer keeps [options_total] unrenamed in formula_raw; compare
		// against the equivalent normalized source.
		return $formula === str_replace( '[options_total]', '[addons]', trim( $raw ) );
	}

	/**
	 * Safely evaluate a formula expression.
	 *
	 * Supports arithmetic tokens plus WAPF `dow()`, `month()`, `today()`, and
	 * validated [field.{id}] date references. No eval() — recursive descent
	 * parser. Syntax errors and invalid dates fail closed to zero.
	 *
	 * WAPF Extended 3.1.5 additions (WAPF-PRICE-FORMULA-CUSTOM-VARIABLE row):
	 *  - `[x]` aliases `[val]` (WAPF replace_in_formula token).
	 *  - `[var_name]` custom variables resolve via $options['variables']
	 *    ({name, default, rules[]}) — first matching rule wins, values are
	 *    recursively evaluated, unknown variables become '0'
	 *    (Helper::evaluate_variables parity).
	 *  - `files(id)`, `lookuptable(table;dim;…)` built-ins and the unregistered
	 *    `name(…)` residual evaluation (WAPF strips the call text down to
	 *    [0-9.+-*\/()eE] inside evaluate_math_string; this is what WAPF emits
	 *    for the map()/reduce() spellings it never registered).
	 *  - $options['lookup_tables'] provides WAPF-shaped nested arrays; absent
	 *    context falls back to the opf_lookup_tables filter and WAPF's own
	 *    wapf/lookup_tables filter so migrated integrations keep working.
	 *
	 * @param array{variables?:array,fields?:array,lookup_tables?:array} $options Formula context extras.
	 */
	public static function evaluate_formula( string $formula, float $price, int $qty, float $addons, string $val = '', ?string $today = null, array $field_values = [], int $product_id = 0, array $field_prices = [], array $field_labels = [], array $options = [] ): float {
		if ( $product_id > 0 && function_exists( 'apply_filters' ) ) {
			$price = (float) apply_filters( 'opf_formula_base_price', $price, $product_id );
		}
		$today = $today ?? ( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
		if ( ! self::is_formula_iso_date( $today ) ) {
			$today = gmdate( 'Y-m-d' );
		}
		$formula = preg_replace_callback(
			'/\[price\.([a-zA-Z0-9_-]+)\]/i',
			static function ( array $match ) use ( $field_prices ): string {
				$value = $field_prices[ strtolower( $match[1] ) ] ?? 0;
				if ( is_array( $value ) ) {
					$value = array_sum( array_map( 'floatval', $value ) );
				}
				return is_numeric( $value ) ? (string) (float) $value : '0';
			},
			$formula
		);
		// WAPF substitutes the raw submitted label for [field.X] and its
		// evaluate_math_string then strips every non-numeric character, so a
		// non-numeric submission contributes nothing while the rest of the
		// arithmetic still runs. OPF's strict tokenizer fails the whole
		// formula on that garbage instead — which is the correct fail-closed
		// behavior for authored formula text ('[price] *' → 0). To recover
		// WAPF's user-data semantics without loosening the authored-formula
		// contract the [field.*] substitution + evaluation is run twice: a
		// poisoned first pass retries once with non-numeric field
		// substitutions zeroed. String consumers (if()/len()/comparisons)
		// always resolve on the first pass, so labels keep working there.
		$evaluate = static function ( bool $numeric_field_values ) use ( $formula, $price, $qty, $addons, $val, $today, $field_values, $field_prices, $field_labels, $product_id, $options ): ?float {
			$f = preg_replace_callback(
				'/\[field\.([a-zA-Z0-9_-]+)\]/i',
				static function ( array $match ) use ( $field_values, $field_labels, $numeric_field_values ): string {
					return self::formula_field_label( $match[1], $field_values, $field_labels, $numeric_field_values );
				},
				$formula
			);
			if ( null === $f ) {
				return null;
			}
			$f = preg_replace( '/today\s*\(\s*\)/i', '__OPF_TODAY__', $f );
			$f = preg_replace_callback(
				'/\bdatediff\s*\(([^()]*)\)/i',
				static function ( array $match ) use ( $val, $today, $field_values, $field_labels ): string {
					$args = self::split_formula_arguments( $match[1] );
					if ( 2 !== count( $args ) ) {
						return '0';
					}
					$date1 = self::parse_formula_date( $args[0], $val, $field_values, $today, $field_labels );
					$date2 = self::parse_formula_date( $args[1], $val, $field_values, $today, $field_labels );
					if ( null === $date1 || null === $date2 ) {
						return '0';
					}
					return (string) $date1->diff( $date2 )->days;
				},
				$f
			);
			$f = preg_replace_callback(
				'/\b(dow|month)\s*\(([^()]*)\)/i',
				static function ( array $match ) use ( $val, $today, $field_values, $field_labels ): string {
					$date = self::parse_formula_date( $match[2], $val, $field_values, $today, $field_labels );
					if ( null === $date ) {
						return '0';
					}
					return 'dow' === strtolower( $match[1] ) ? $date->format( 'w' ) : $date->format( 'n' );
				},
				$f
			);
			$f = str_ireplace(
				[ '[price]', '[qty]', '[addons]', '[options_total]', '[val]', '[x]' ],
				[ ' P ', ' Q ', ' A ', ' A ', ' V ', ' V ' ],
				$f
			);
			// WAPF evaluate_variables runs after token replacement: resolve
			// [var_name] to its evaluated numeric string before function expansion.
			$f = self::expand_formula_variables(
				$f,
				$options,
				$price,
				$qty,
				$addons,
				$val,
				$product_id,
				$field_values,
				$field_prices,
				$field_labels
			);
			if ( null === $f ) {
				return null;
			}
			$f = self::expand_formula_functions(
				$f,
				[
					'price'         => $price,
					'qty'           => $qty,
					'addons'        => $addons,
					'value'         => $val,
					'field_values'  => $field_values,
					'field_prices'  => $field_prices,
					'field_labels'  => $field_labels,
					'product_id'    => $product_id > 0 ? $product_id : null,
					'lookup_tables' => is_array( $options['lookup_tables'] ?? null ) ? $options['lookup_tables'] : [],
					'options'       => $options,
				]
			);
			if ( null === $f ) {
				return null;
			}
			$vars = [ 'P' => $price, 'Q' => (float) $qty, 'A' => $addons, 'V' => (float) $val ];

			$tokens = self::tokenize( $f, $vars );
			if ( null === $tokens ) {
				return null;
			}
			$pos   = 0;
			$value = self::parse_expression( $tokens, $pos );
			if ( null === $value || $pos < count( $tokens ) ) {
				return null;
			}
			return is_finite( $value ) ? (float) $value : 0.0;
		};

		$result = $evaluate( false );
		if ( null === $result ) {
			$result = $evaluate( true );
		}
		// Signed contributions may offset the base price or other addons.
		// Clamp only the final product price in CartIntegration::apply_prices().
		return $result ?? 0.0;
	}

	/**
	 * Expand registered functions into numeric literals before tokenization.
	 * No PHP evaluation is used. Nested calls and WAPF's semicolon argument
	 * separator are parsed explicitly. WAPF parity details: the scan is
	 * quote-blind (WAPF strpos-searches function names inside quoted text)
	 * and an unregistered `name(…)` call — e.g. the map()/reduce() spellings
	 * WAPF Extended 3.1.5 never registered — resolves through the same
	 * char-clean residual path as WAPF's evaluate_math_string.
	 *
	 * @param array<string,mixed> $context Formula callback context.
	 */
	private static function expand_formula_functions( string $formula, array $context, int $depth = 0 ): ?string {
		if ( $depth > 16 ) {
			return null;
		}

		$out = '';
		$length = strlen( $formula );
		for ( $i = 0; $i < $length; ) {
			$char = $formula[ $i ];

			if ( ctype_alpha( $char ) || '_' === $char ) {
				$name_end = $i + 1;
				while ( $name_end < $length && ( ctype_alnum( $formula[ $name_end ] ) || '_' === $formula[ $name_end ] ) ) {
					$name_end++;
				}
				$name = strtolower( substr( $formula, $i, $name_end - $i ) );
				$open = $name_end;
				while ( $open < $length && ctype_space( $formula[ $open ] ) ) {
					$open++;
				}
				$builtin_functions = self::builtin_formula_functions();
				$callback = $builtin_functions[ $name ] ?? ( self::$formula_functions[ $name ] ?? null );
				if ( $open < $length && '(' === $formula[ $open ] ) {
					$close = self::formula_call_end( $formula, $open );
					if ( null === $close ) {
						return null;
					}
					$inner = substr( $formula, $open + 1, $close - $open - 1 );
					$inner = self::expand_formula_functions( $inner, $context, $depth + 1 );
					if ( null === $inner ) {
						return null;
					}
					if ( null === $callback ) {
						// WAPF evaluate_math_string keeps [0-9.+-*\/()eE] of
						// the unregistered call text (so 'reduce' leaves 'ee')
						// and evaluates the residue as arithmetic.
						$out .= sprintf( '%.14g', self::wapf_residual_eval( substr( $formula, $i, $name_end - $i ) . '(' . $inner . ')' ) );
						$i = $close + 1;
						continue;
					}
					$args = self::split_formula_arguments( $inner );
					try {
						$result = $callback( $args, $context );
					} catch ( \Throwable $error ) {
						return null;
					}
					if ( is_bool( $result ) ) {
						$result = $result ? 1 : 0;
					}
					if ( ! is_numeric( $result ) || ! is_finite( (float) $result ) ) {
						return null;
					}
					$out .= sprintf( '%.14g', (float) $result );
					$i = $close + 1;
					continue;
				}
			}
			$out .= $char;
			$i++;
		}

		return $out;
	}

	/**
	 * Built-ins exposed by WAPF Free, Pro, and Extended formula references.
	 * Function names, arguments, and examples follow WAPF's reference:
	 * https://www.studiowombat.com/knowledge-base/formula-functions-reference/
	 */
	private static function builtin_formula_functions(): array {
		static $functions = null;
		if ( null !== $functions ) {
			return $functions;
		}

		$numeric = static function ( string $expression, array $context ): float {
			return self::formula_numeric_value( $expression, $context );
		};
		$functions = [
			'min' => static function ( array $args, array $context ) use ( $numeric ) {
				return $args ? min( array_map( static function ( $arg ) use ( $numeric, $context ): float {
					return $numeric( (string) $arg, $context );
				}, $args ) ) : 0;
			},
			'max' => static function ( array $args, array $context ) use ( $numeric ) {
				return $args ? max( array_map( static function ( $arg ) use ( $numeric, $context ): float {
					return $numeric( (string) $arg, $context );
				}, $args ) ) : 0;
			},
			'len' => static function ( array $args, array $context ): int {
				$text = empty( $args[0] ) ? '' : (string) $args[0];
				// A bare [x]/[val] measures the submitted text (WAPF replaces
				// the token with the raw value before len() runs); the numeric
				// ' V ' placeholder would otherwise measure itself.
				if ( 'v' === strtolower( trim( $text ) ) && isset( $context['value'] ) ) {
					$text = (string) $context['value'];
				}
				if ( isset( $args[1] ) && 'true' === $args[1] ) {
					$text = preg_replace( '/\s/', '', $text ) ?? $text;
				}
				return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
			},
			'checked' => static function ( array $args, array $context ): int {
				$field_id = trim( (string) ( $args[0] ?? '' ), " '\"" );
				$field_values = is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [];
				$field_id = strtolower( $field_id );
				$value = $field_values[ $field_id ] ?? null;
				return is_array( $value ) ? count( $value ) : 0;
			},
			'sumqty' => static function ( array $args, array $context ): int {
				$field_id = strtolower( trim( (string) ( $args[0] ?? '' ), " '\"" ) );
				$field_values = is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [];
				$value = $field_values[ $field_id ] ?? null;
				if ( ! is_array( $value ) || 'image_quantity' !== ( $value['_opf_type'] ?? '' ) || ! is_array( $value['quantities'] ?? null ) ) {
					return 0;
				}
				return array_sum( array_map( static function ( $quantity ): int {
					$is_quantity = is_int( $quantity ) || is_float( $quantity ) || is_string( $quantity );
					return $is_quantity && preg_match( '/^\\d+$/', (string) $quantity ) ? (int) $quantity : 0;
				}, $value['quantities'] ) );
			},
			// WAPF files(id): count( explode(',', values[0].label ) ) on the
			// submitted field — the comma-joined upload list of the first
			// value, on any field type. OPF upload submissions arrive as an
			// array of validated private tokens, so arrays count their
			// non-empty entries; scalar submissions split on commas.
			'files' => static function ( array $args, array $context ): int {
				$field_id = strtolower( trim( (string) ( $args[0] ?? '' ), " '\"" ) );
				$field_values = is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [];
				if ( ! array_key_exists( $field_id, $field_values ) ) {
					return 0;
				}
				$value = $field_values[ $field_id ];
				if ( is_array( $value ) ) {
					return count( array_filter( array_map( static function ( $item ): string {
						return is_scalar( $item ) ? trim( (string) $item ) : '';
					}, $value ) ) );
				}
				$scalar = is_scalar( $value ) ? trim( (string) $value ) : '';
				return '' === $scalar ? 0 : count( explode( ',', $scalar ) );
			},
			// WAPF lookuptable(table;dim;…): dimension args under six chars are
			// literals, longer args resolve to the first submitted label of a
			// field id. Each dimension picks the nearest axis key (round up to
			// the next defined key; below-first clamps to the first key) and
			// the nested result is returned. Missing tables, fields, empty
			// labels, traversal failures, and beyond-last keys resolve to 0 —
			// where WAPF's PHP reference fatals on a mid-chain null axis, OPF
			// fails closed.
			'lookuptable' => static function ( array $args, array $context ) {
				$tables = is_array( $context['lookup_tables'] ?? null ) ? $context['lookup_tables'] : [];
				if ( ! $tables && function_exists( 'apply_filters' ) ) {
					$tables = (array) apply_filters( 'opf_lookup_tables', [], $context );
					if ( ! $tables ) {
						// Migrate-friendly: WAPF's own extension point.
						$tables = (array) apply_filters( 'wapf/lookup_tables', [] );
					}
				}
				$table_name = trim( (string) ( $args[0] ?? '' ) );
				if ( ! isset( $tables[ $table_name ] ) || ! is_array( $tables[ $table_name ] ) ) {
					return 0;
				}
				$field_values = is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [];
				$field_labels = is_array( $context['field_labels'] ?? null ) ? $context['field_labels'] : [];
				$prev         = $tables[ $table_name ];
				$traversal    = [];
				for ( $k = 1; $k < count( $args ); $k++ ) {
					$arg      = trim( (string) $args[ $k ] );
					$field_id = strtolower( $arg );
					if ( array_key_exists( $field_id, $field_values ) ) {
						// Field-id dims resolve first regardless of length:
						// WAPF's <6-char literal heuristic only works because
						// WAPF ids are always >=6 chars, while imported OPF
						// ids can be shorter and would silently become
						// literals under WAPF's rule.
						$value = self::formula_field_label( $field_id, $field_values, $field_labels );
						if ( '' === $value ) {
							return 0;
						}
					} elseif ( strlen( $arg ) < 6 ) {
						$value = $arg;
					} else {
						// >=6-char arg that is not a submitted field id —
						// WAPF's missing-field path resolves to 0.
						return 0;
					}
					if ( ! is_array( $prev ) ) {
						return 0;
					}
					$next = self::lookup_nearest_axis_key( $value, $prev );
					if ( null === $next ) {
						return 0;
					}
					$traversal[] = $next;
					$prev        = $prev[ $next ];
				}
				$result = $tables[ $table_name ];
				foreach ( $traversal as $key ) {
					if ( ! is_array( $result ) || ! array_key_exists( $key, $result ) ) {
						return 0;
					}
					$result = $result[ $key ];
				}
				return is_numeric( $result ) ? (float) $result : 0.0;
			},
			'round' => static function ( array $args, array $context ) use ( $numeric ) {
				$value = $numeric( (string) ( $args[0] ?? '' ), $context );
				$precision = isset( $args[1] ) && '' !== trim( (string) $args[1] ) ? (int) $numeric( (string) $args[1], $context ) : 0;
				return round( $value, $precision );
			},
			'abs' => static function ( array $args, array $context ) use ( $numeric ): float {
				return abs( $numeric( (string) ( $args[0] ?? '' ), $context ) );
			},
			'floor' => static function ( array $args, array $context ) use ( $numeric ): float {
				return floor( $numeric( (string) ( $args[0] ?? '' ), $context ) );
			},
			'ceil' => static function ( array $args, array $context ) use ( $numeric ): float {
				return ceil( $numeric( (string) ( $args[0] ?? '' ), $context ) );
			},
			// Preserve sqrt's domain error so expansion fails the entire formula
			// closed, matching browser Math.sqrt and WAPF's native PHP sqrt.
			'sqrt' => static function ( array $args, array $context ) use ( $numeric ): float {
				return sqrt( $numeric( (string) ( $args[0] ?? '' ), $context ) );
			},
			'pow' => static function ( array $args, array $context ) use ( $numeric ): float {
				return 2 === count( $args ) ? pow( $numeric( (string) $args[0], $context ), $numeric( (string) $args[1], $context ) ) : NAN;
			},
			'sin' => static function ( array $args, array $context ) use ( $numeric ): float {
				return sin( $numeric( (string) ( $args[0] ?? '' ), $context ) );
			},
			'cos' => static function ( array $args, array $context ) use ( $numeric ): float {
				return cos( $numeric( (string) ( $args[0] ?? '' ), $context ) );
			},
			'tan' => static function ( array $args, array $context ) use ( $numeric ): float {
				return tan( $numeric( (string) ( $args[0] ?? '' ), $context ) );
			},
			'if' => static function ( array $args, array $context ) use ( $numeric ) {
				if ( 3 !== count( $args ) ) {
					return NAN;
				}
				return self::formula_condition( (string) $args[0], $context )
					? $numeric( (string) $args[1], $context )
					: $numeric( (string) $args[2], $context );
			},
			'or' => static function ( array $args, array $context ): bool {
				foreach ( $args as $arg ) {
					if ( self::formula_condition( (string) $arg, $context ) ) {
						return true;
					}
				}
				return false;
			},
			'and' => static function ( array $args, array $context ): bool {
				foreach ( $args as $arg ) {
					if ( ! self::formula_condition( (string) $arg, $context ) ) {
						return false;
					}
				}
				return true;
			},
		];

		return $functions;
	}

	/**
	 * Resolve a `[field.X]` token the way WAPF does: the first submitted
	 * value's label. Choice submissions store slugs, so submitted scalars are
	 * translated through the group's slug→label map when one is provided.
	 * WAPF's `field.X_slug` suffix selects one submitted value when a field
	 * has several; OPF field ids may contain underscores, so the full token
	 * is tried as a field id before the suffix split.
	 */
	private static function formula_field_label( string $token, array $field_values, array $field_labels, bool $numeric_only = false ): string {
		$resolved = null;
		$parts    = explode( '_', $token );
		$fid      = strtolower( (string) $parts[0] );
		$option   = $parts[1] ?? null;
		if ( ! array_key_exists( $fid, $field_values ) ) {
			$whole = strtolower( $token );
			if ( ! array_key_exists( $whole, $field_values ) ) {
				$resolved = '';
			} else {
				$fid    = $whole;
				$option = null;
			}
		}
		if ( null === $resolved ) {
			$values = is_array( $field_values[ $fid ] ) ? array_values( $field_values[ $fid ] ) : [ $field_values[ $fid ] ];
			if ( null !== $option && count( $values ) > 1 ) {
				$resolved = '0';
				foreach ( $values as $submitted ) {
					if ( is_scalar( $submitted ) && (string) $submitted === $option ) {
						$resolved = isset( $field_labels[ $fid ][ (string) $submitted ] ) ? (string) $field_labels[ $fid ][ (string) $submitted ] : '0';
						break;
					}
				}
			} else {
				$first    = $values[0] ?? '';
				$scalar   = is_scalar( $first ) ? (string) $first : '';
				$resolved = isset( $field_labels[ $fid ][ $scalar ] ) ? (string) $field_labels[ $fid ][ $scalar ] : $scalar;
			}
		}
		// Retry pass: user-supplied text is not formula source — a
		// non-numeric substitution contributes 0 to arithmetic instead of
		// failing the whole expression closed.
		if ( $numeric_only && ! is_numeric( $resolved ) ) {
			return '0';
		}
		return $resolved;
	}

	/**
	 * Expand `[var_name]` tokens — Helper::evaluate_variables parity.
	 *
	 * The first variable whose `name` matches exactly (case-sensitive) wins;
	 * its `default` text is used unless one of its `rules` matches (checked in
	 * order — the first valid rule wins). The chosen text is recursively
	 * expanded for nested `[var_*]` references and then fully evaluated to a
	 * numeric string, which is spliced back into the outer formula. Unknown
	 * variables resolve to '0'. WAPF has no recursion guard — a self-
	 * referencing variable loops forever — so OPF caps nesting and resolves
	 * the token to '0' (documented divergence on malformed definitions).
	 *
	 * @param array<string,mixed> $options      evaluate_formula $options.
	 * @param array<string,mixed> $field_values Submitted values by field id.
	 * @param array<string,float> $field_prices Field price totals.
	 * @param array<string,array> $field_labels Slug→label maps.
	 */
	private static function expand_formula_variables( string $formula, array $options, float $price, int $qty, float $addons, string $val, int $product_id, array $field_values, array $field_prices, array $field_labels, int $depth = 0 ): ?string {
		if ( false === strpos( $formula, '[var_' ) ) {
			return $formula;
		}
		if ( $depth > 16 ) {
			return null;
		}
		$variables = is_array( $options['variables'] ?? null ) ? $options['variables'] : [];
		// WAPF requires a field definition for rule subjects other than qty.
		// When the caller does not pass group field defs, submitted values are
		// a sufficient presence stand-in (documented OPF fallback).
		$fields = is_array( $options['fields'] ?? null ) ? $options['fields'] : [];
		if ( ! $fields ) {
			foreach ( $field_values as $field_id => $unused ) {
				$fields[] = [ 'id' => (string) $field_id, 'type' => '' ];
			}
		}
		$result = preg_replace_callback(
			'/\[var_.+?]/',
			static function ( array $match ) use ( $options, $variables, $fields, $price, $qty, $addons, $val, $product_id, $field_values, $field_prices, $field_labels, $depth ): string {
				$var_name = substr( $match[0], 5, -1 );
				$variable = null;
				foreach ( $variables as $candidate ) {
					if ( is_array( $candidate ) && (string) ( $candidate['name'] ?? '' ) === $var_name ) {
						$variable = $candidate;
						break;
					}
				}
				if ( null === $variable ) {
					return '0';
				}
				$text = (string) ( $variable['default'] ?? '' );
				foreach ( (array) ( $variable['rules'] ?? [] ) as $rule ) {
					if ( ! is_array( $rule ) ) {
						continue;
					}
					if ( self::variable_rule_passes( (string) ( $rule['field'] ?? '' ), (string) ( $rule['condition'] ?? '' ), (string) ( $rule['value'] ?? '' ), $fields, $field_values, $product_id, $qty ) ) {
						$text = (string) ( $rule['variable'] ?? '' );
						break;
					}
				}
				$nested = self::expand_formula_variables( $text, $options, $price, $qty, $addons, $val, $product_id, $field_values, $field_prices, $field_labels, $depth + 1 );
				if ( null === $nested ) {
					return '0';
				}
				$evaluated = self::evaluate_formula( $nested, $price, $qty, $addons, $val, null, $field_values, $product_id, $field_prices, $field_labels, $options );
				return sprintf( '%.14g', $evaluated );
			},
			$formula
		);
		return is_string( $result ) ? $result : null;
	}

	/**
	 * One WAPF variable rule — Fields::is_valid_rule parity. `$fields` carries
	 * normalized defs ({id, type}); `$field_values` supplies the submitted raw
	 * value WAPF reads as $cf['raw'] (select submissions hold the slug).
	 *
	 * @param array<int,array>        $fields       Field defs ({id,type}).
	 * @param array<string,mixed>     $field_values Submitted values by field id.
	 */
	private static function variable_rule_passes( string $subject, string $condition, string $rule_value, array $fields, array $field_values, int $product_id, int $qty ): bool {
		if ( 'qty' === $subject ) {
			$value = $qty;
		} else {
			$field = null;
			foreach ( $fields as $candidate ) {
				if ( is_array( $candidate ) && strtolower( (string) ( $candidate['id'] ?? '' ) ) === strtolower( $subject ) ) {
					$field = $candidate;
					break;
				}
			}
			if ( null === $field ) {
				return false;
			}
			if ( false !== strpos( $condition, 'product_var' ) ) {
				$ids = array_map( 'trim', explode( ',', $rule_value ) );
				$in  = in_array( $product_id, array_map( 'intval', $ids ), false ) || in_array( (string) $product_id, $ids, true );
				return 'product_var' === $condition ? $in : ! $in;
			}
			if ( false !== strpos( $condition, 'patts' ) ) {
				$has = self::product_has_attribute_values( $product_id, explode( ',', $rule_value ) );
				return 'patts' === $condition ? $has : ! $has;
			}
			$subject_key = strtolower( $subject );
			if ( ! array_key_exists( $subject_key, $field_values ) ) {
				return false;
			}
			$value = $field_values[ $subject_key ];
			if ( null === $value ) {
				return false;
			}
			if ( 'date' === (string) ( $field['type'] ?? '' ) && '' !== $rule_value ) {
				$rule_ts  = \DateTime::createFromFormat( 'm-d-Y', $rule_value );
				$rule_ts  = $rule_ts ? $rule_ts->setTime( 0, 0 ) : false;
				$value    = '' === trim( (string) $value ) ? $value : self::parse_formula_date( (string) $value, '', [], '', [] );
				$rule_value = $rule_ts;
			}
		}

		switch ( $condition ) {
			case 'check':
				return '1' === $value;
			case '!check':
				return '0' === $value;
			case '==':
				return $rule_value instanceof \DateTimeInterface
					? ( $value && $value instanceof \DateTimeInterface && $value->format( 'U' ) === $rule_value->format( 'U' ) )
					: in_array( $rule_value, (array) $value );
			case '!=':
				return $rule_value instanceof \DateTimeInterface
					? ( $value && $value instanceof \DateTimeInterface && $value->format( 'U' ) !== $rule_value->format( 'U' ) )
					: ! in_array( $rule_value, (array) $value );
			case 'empty':
				return empty( $value );
			case '!empty':
				return ! empty( $value );
			case '==contains':
				return is_array( $value ) ? in_array( $rule_value, $value ) : false !== strpos( (string) $value, $rule_value );
			case '!=contains':
				return is_array( $value ) ? ! in_array( $rule_value, $value ) : false === strpos( (string) $value, $rule_value );
			case 'lt':
				return (float) $value < (float) $rule_value;
			case 'gt':
				return (float) $value > (float) $rule_value;
			case 'gtd':
				return $value && $value > $rule_value;
			case 'ltd':
				return $value && $value < $rule_value;
		}
		return false;
	}

	/**
	 * WAPF 'patts' rule: the product must expose one of the attr|value pairs.
	 * Attribute slugs resolve through WooCommerce; a missing Woo runtime
	 * resolves false, matching WAPF's "no attributes → false" branch.
	 *
	 * @param array<int,string> $pairs attr|value pairs ('*' matches any value).
	 */
	private static function product_has_attribute_values( int $product_id, array $pairs ): bool {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! is_object( $product ) || ! is_callable( [ $product, 'get_attributes' ] ) ) {
			return false;
		}
		$attributes = [];
		foreach ( (array) $product->get_attributes() as $key => $attribute ) {
			if ( is_string( $attribute ) ) {
				$attributes[ $key ] = [ $attribute ];
				continue;
			}
			if ( ! is_object( $attribute ) || ! is_callable( [ $attribute, 'is_taxonomy' ] ) || ! $attribute->is_taxonomy() || ! is_callable( [ $attribute, 'get_terms' ] ) ) {
				continue;
			}
			$slugs = [];
			foreach ( (array) $attribute->get_terms() as $term ) {
				if ( is_object( $term ) && isset( $term->slug ) ) {
					$slugs[] = (string) $term->slug;
				}
			}
			if ( $slugs ) {
				$attributes[ (string) $attribute->get_name() ] = $slugs;
			}
		}
		foreach ( $pairs as $pair ) {
			$split = explode( '|', (string) $pair );
			if ( 2 !== count( $split ) ) {
				continue;
			}
			$attr_name = 'pa_' . $split[0];
			if ( isset( $attributes[ $attr_name ] ) && ( '*' === $split[1] || in_array( $split[1], $attributes[ $attr_name ], true ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * WAPF Helper::find_nearest parity: exact key hit, below-first clamps to
	 * the first key, between-keys rounds up to the next defined key, and
	 * beyond-last returns null (WAPF's `$keys[$i]` reads an undefined index —
	 * downstream this becomes 0 or a PHP TypeError; OPF fails closed).
	 *
	 * @param array<array-key,mixed> $axis Axis keys → next dimension/leaf.
	 * @return array-key|null Axis key or null.
	 */
	private static function lookup_nearest_axis_key( string $value, array $axis ) {
		if ( isset( $axis[ $value ] ) ) {
			return $value;
		}
		$keys    = array_keys( $axis );
		$numeric = (float) $value;
		if ( ! $keys ) {
			return null;
		}
		if ( $numeric <= (float) $keys[0] ) {
			return $keys[0];
		}
		$last = count( $keys ) - 1;
		for ( $i = 0; $i <= $last; $i++ ) {
			if ( ! array_key_exists( $i + 1, $keys ) ) {
				break;
			}
			if ( $numeric > (float) $keys[ $i ] && $numeric <= (float) $keys[ $i + 1 ] ) {
				return $keys[ $i + 1 ];
			}
		}
		return null;
	}

	/**
	 * Literal port of WAPF evaluate_math_string's $__eval — the residual
	 * evaluation unregistered `name(…)` calls fall through to after every
	 * non-numeric character is stripped (the 'e'/'E' survive for scientific
	 * notation, which is why reduce() leaves 'ee' and evaluates to 0 while
	 * map() drops to '(…)' and evaluates its concatenated arguments).
	 */
	private static function wapf_residual_eval( string $str ): float {
		$evaluate = static function ( string $s ) use ( &$evaluate ): float {
			$error   = false;
			$div_mul = false;
			$add_sub = false;
			$result  = 0.0;
			$s       = (string) preg_replace( '/[^\d.+\-*\/()E]/i', '', $s );
			$s       = rtrim( trim( $s, '/*+' ), '-' );
			if ( false !== strpos( $s, '(' ) && false !== strpos( $s, ')' ) ) {
				if ( preg_match( '/\(([\d.+\-*\/]+)\)/', $s, $paren ) ) {
					return $evaluate( (string) preg_replace( '/\(([\d.+\-*\/]+)\)/', (string) $evaluate( $paren[1] ), $s, 1 ) );
				}
			}
			$s = str_replace( [ '(', ')' ], '', $s );
			if ( false !== strpos( $s, '/' ) || false !== strpos( $s, '*' ) ) {
				$div_mul   = true;
				$operators = [ '/', '*' ];
				while ( ! $error && $operators ) {
					$operator = array_pop( $operators );
					while ( null !== $operator && false !== strpos( $s, $operator ) ) {
						if ( $error ) {
							break;
						}
						if ( preg_match( '/([\d.]+(?:E[+\-]?\d+)?)\\' . $operator . '(\-?[\d.]+(?:E[+\-]?\d+)?)/', $s, $m ) ) {
							if ( '*' === $operator ) {
								$result = (float) $m[1] * (float) $m[2];
							}
							if ( '/' === $operator ) {
								if ( (float) $m[2] ) {
									$result = (float) $m[1] / (float) $m[2];
								} else {
									$error = true;
								}
							}
							$s = (string) preg_replace( '/([\d.]+(?:E[+\-]?\d+)?)\\' . $operator . '(\-?[\d.]+(?:E[+\-]?\d+)?)/', (string) $result, $s, 1 );
							$s = str_replace( [ '++', '--', '-+', '+-' ], [ '+', '+', '-', '-' ], $s );
						} else {
							$error = true;
						}
					}
				}
			}
			if ( ! $error && ( false !== strpos( $s, '+' ) || false !== strpos( $s, '-' ) ) ) {
				$s       = str_replace( '--', '+', $s );
				$add_sub = true;
				preg_match_all( '/([\d\.]+(?:E[+\-]?\d+)?|[\+\-])/', $s, $tokens );
				if ( isset( $tokens[0] ) ) {
					$result   = 0.0;
					$operator = '+';
					foreach ( $tokens[0] as $token ) {
						if ( '+' === $token || '-' === $token ) {
							$operator = $token;
						} else {
							$result = '+' === $operator ? $result + (float) $token : $result - (float) $token;
						}
					}
				}
			}
			if ( ! $error && ! $div_mul && ! $add_sub ) {
				$result = (float) $s;
			}
			return $error ? 0.0 : $result;
		};
		return $evaluate( $str );
	}

	/** Evaluate an arithmetic expression using the current pricing context. */
	private static function formula_numeric_value( string $expression, array $context ): float {
		// Argument case is significant (e.g. len(x;TRUE) must not strip). Only
		// the true/false literals are matched case-insensitively.
		$expression = trim( $expression );
		$lower      = strtolower( $expression );
		if ( 'true' === $lower ) {
			return 1.0;
		}
		if ( 'false' === $lower ) {
			return 0.0;
		}
		return self::evaluate_formula(
			$expression,
			(float) ( $context['price'] ?? 0 ),
			(int) ( $context['qty'] ?? 1 ),
			(float) ( $context['addons'] ?? 0 ),
			(string) ( $context['value'] ?? '' ),
			null,
			(array) ( $context['field_values'] ?? [] ),
			(int) ( $context['product_id'] ?? 0 ),
			(array) ( $context['field_prices'] ?? [] ),
			(array) ( $context['field_labels'] ?? [] ),
			(array) ( $context['options'] ?? [] )
		);
	}

	/** Evaluate one WAPF-style comparison without PHP eval(). */
	private static function formula_condition( string $condition, array $context ): bool {
		$parts = self::formula_comparison_parts( trim( $condition ) );
		if ( null === $parts ) {
			$value = strtolower( trim( $condition ) );
			return in_array( $value, [ 'true', '1' ], true );
		}
		[ $left_raw, $operator, $right_raw ] = $parts;
		$left = self::formula_comparison_value( $left_raw, $context );
		$right = self::formula_comparison_value( $right_raw, $context );
		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			$left = (float) $left;
			$right = (float) $right;
		}
		switch ( $operator ) {
			case '=': return $left === $right;
			case '!=': return $left !== $right;
			case '<': return $left < $right;
			case '>': return $left > $right;
			case '<=': return $left <= $right;
			case '>=': return $left >= $right;
		}
		return false;
	}

	/** @return array{string,string,string}|null */
	private static function formula_comparison_parts( string $expression ): ?array {
		$depth = 0;
		$quote = '';
		$length = strlen( $expression );
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $expression[ $index ];
			if ( '' !== $quote ) {
				if ( $char === $quote && ( 0 === $index || '\\' !== $expression[ $index - 1 ] ) ) {
					$quote = '';
				}
				continue;
			}
			if ( in_array( $char, [ '\'', '"' ], true ) ) {
				$quote = $char;
				continue;
			}
			if ( '(' === $char ) {
				$depth++;
				continue;
			}
			if ( ')' === $char ) {
				$depth--;
				continue;
			}
			if ( 0 !== $depth ) {
				continue;
			}
			$operator = null;
			if ( in_array( substr( $expression, $index, 2 ), [ '!=', '<=', '>=' ], true ) ) {
				$operator = substr( $expression, $index, 2 );
			} elseif ( in_array( $char, [ '=', '<', '>' ], true ) ) {
				$operator = $char;
			}
			if ( null !== $operator ) {
				return [ trim( substr( $expression, 0, $index ) ), $operator, trim( substr( $expression, $index + strlen( $operator ) ) ) ];
			}
		}
		return null;
	}

	/** Evaluate a numeric comparison operand or retain unquoted text. */
	private static function formula_comparison_value( string $value, array $context ) {
		$value = trim( $value );
		if ( strlen( $value ) >= 2 && in_array( $value[0], [ '\'', '"' ], true ) && $value[0] === substr( $value, -1 ) ) {
			return substr( $value, 1, -1 );
		}
		if ( in_array( strtolower( $value ), [ 'true', 'false' ], true ) ) {
			return 'true' === strtolower( $value );
		}
		if ( is_numeric( $value ) || preg_match( '/^[\d\s().+*\/-]+$/', $value ) ) {
			return self::formula_numeric_value( $value, $context );
		}
		return $value;
	}

	/**
	 * Find the matching `)` for a call. WAPF closing_bracket_index parity:
	 * bracket depth only — quoted text is not special (a ')' inside quotes
	 * still closes the call, exactly like the reference).
	 */
	private static function formula_call_end( string $formula, int $open ): ?int {
		$depth = 0;
		$length = strlen( $formula );
		for ( $i = $open; $i < $length; $i++ ) {
			$char = $formula[ $i ];
			if ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char && 0 === --$depth ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Split call arguments on top-level `;` — WAPF split_formula_variables
	 * parity: depth-aware but quote-blind (a ';' inside quoted text still
	 * separates arguments) and a trailing separator does not yield an empty
	 * final argument.
	 *
	 * @return array<int,string>
	 */
	private static function split_formula_arguments( string $arguments ): array {
		$parts = [];
		$start = 0;
		$depth = 0;
		$length = strlen( $arguments );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $arguments[ $i ];
			if ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char ) {
				$depth--;
			} elseif ( ';' === $char && 0 === $depth ) {
				$parts[] = trim( substr( $arguments, $start, $i - $start ) );
				$start = $i + 1;
			}
		}
		$last = trim( substr( $arguments, $start ) );
		if ( '' !== $last || ! $parts ) {
			$parts[] = $last;
		}
		return $parts;
	}

	/** Resolve a WAPF date function argument to a strictly validated date. */
	private static function parse_formula_date( string $argument, string $val, array $field_values, string $today, array $field_labels = [] ): ?\DateTimeImmutable {
		$argument = trim( $argument );
		if ( strlen( $argument ) >= 2 && ( ( "'" === $argument[0] && "'" === substr( $argument, -1 ) ) || ( '"' === $argument[0] && '"' === substr( $argument, -1 ) ) ) ) {
			$argument = substr( $argument, 1, -1 );
		}
		if ( '__OPF_TODAY__' === $argument ) {
			$argument = $today;
		} elseif ( '[val]' === strtolower( $argument ) ) {
			$argument = $val;
		} elseif ( preg_match( '/^\[field\.([a-zA-Z0-9_-]+)\]$/i', $argument, $field_match ) ) {
			$argument = self::formula_field_label( $field_match[1], $field_values, $field_labels );
		}
		$argument = trim( $argument );

		if ( self::is_formula_iso_date( $argument ) ) {
			return \DateTimeImmutable::createFromFormat( '!Y-m-d', $argument, new \DateTimeZone( 'UTC' ) ) ?: null;
		}

		$date_format = DateFormat::configured();
		preg_match_all( '/yyyy|yy|mm|m|dd|d|[-\/., ]/i', $date_format, $format_tokens );
		if ( 5 !== count( $format_tokens[0] ) ) {
			return null;
		}

		$pattern = '';
		$parts   = [];
		foreach ( $format_tokens[0] as $token ) {
			$token = strtolower( $token );
			if ( in_array( $token, [ '-', '/', '.', ',', ' ' ], true ) ) {
				$pattern .= preg_quote( $token, '/' );
				continue;
			}
			$part = 'y' === substr( $token, 0, 1 ) ? 'year' : ( 'm' === $token[0] ? 'month' : 'day' );
			if ( isset( $parts[ $part ] ) ) {
				return null;
			}
			$parts[ $part ] = count( $parts ) + 1;
			$digits = 'yyyy' === $token ? '4' : ( in_array( $token, [ 'yy', 'mm', 'dd' ], true ) ? '2' : '1,2' );
			$pattern .= '(\\d{' . $digits . '})';
		}
		if ( 3 !== count( $parts ) || ! preg_match( '/^' . $pattern . '$/', $argument, $matches ) ) {
			return null;
		}

		$date = [];
		foreach ( $parts as $part => $index ) {
			$date[ $part ] = (int) $matches[ $index ];
		}
		$year = $date['year'];
		if ( 2 === strlen( (string) $matches[ $parts['year'] ] ) ) {
			$year = $year < 70 ? 2000 + $year : 1900 + $year;
		}
		if ( ! checkdate( $date['month'], $date['day'], $year ) ) {
			return null;
		}
		return \DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-%02d', $year, $date['month'], $date['day'] ), new \DateTimeZone( 'UTC' ) ) ?: null;
	}

	/** Return whether a value is an exact valid ISO calendar date. */
	private static function is_formula_iso_date( string $value ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $value ) );
		return checkdate( $month, $day, $year );
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
			if ( preg_match( '/^(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+\-]?\d+)?/', substr( $formula, $i ), $m ) ) {
				$tokens[] = [ 't' => 'num', 'v' => (float) $m[0] ];
				$i       += strlen( $m[0] );
				continue;
			}
			if ( false !== strpos( '+-*/()', $ch ) && 1 === strlen( $ch ) ) {
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
		return null;
	}
}
