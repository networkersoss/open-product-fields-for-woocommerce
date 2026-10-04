<?php
/**
 * Calculator unit tests — pricing semantics and formula safety.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use OPF\API;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase {

	public function test_fixed_pricing_flat_per_line_wapf_parity(): void {
		$pricing = [ 'type' => 'fixed', 'amount' => 5.0, 'formula' => '', 'per_unit' => false ];
		// Flat fee: per-unit contribution shrinks with qty so the LINE total
		// adds exactly the fee (WAPF parity).
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertEqualsWithDelta( 5.0 / 3, Calculator::choice_addon( $pricing, 100.0, 3, 0.0 ), 0.000001 );
	}

	public function test_fixed_pricing_per_unit_opt_in(): void {
		$pricing = [ 'type' => 'fixed', 'amount' => 5.0, 'formula' => '', 'per_unit' => true ];
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 3, 0.0 ), 'per-unit fixed does not shrink' );
	}

	public function test_percent_of_unit_price(): void {
		$pricing = [ 'type' => 'percent', 'amount' => 20.0, 'formula' => '' ];
		$this->assertSame( 20.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertSame( 2.0, Calculator::choice_addon( $pricing, 10.0, 5, 0.0 ) );
	}

	public function test_multiple_swatch_pricing_adds_each_selected_choice(): void {
		$field = [
			'type' => 'swatch', 'multiple' => true,
			'choices' => [
				[ 'slug' => 'navy', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 2.0, 'per_unit' => true ] ],
				[ 'slug' => 'gold', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 3.0, 'per_unit' => true ] ],
			],
		];

		$this->assertSame( 5.0, Calculator::field_addon( $field, [ 'navy', 'gold' ], [ 'price' => 10, 'qty' => 1 ] ) );
	}

	/**
	 * WAPF image-swatch-qty feeds each entered count into do_pricing() as the
	 * value label ($val): the pricing type decides whether the count
	 * multiplies the charge — `fixed` is a flat per-line fee per selected
	 * choice, `qt` is amount per product unit, and `nr`/`nrq` (OPF:
	 * [x]-formulas) consume the entered count. OPF therefore does NOT multiply
	 * the priced choice by the entered quantity; it passes the count as $val.
	 */
	public function test_image_quantity_pricing_and_sumqty_use_tagged_choice_quantities(): void {
		$values = [ '_opf_type' => 'image_quantity', 'quantities' => [ 'oak' => 2, 'ash' => 3 ] ];
		$field = [
			'id' => 'images', 'type' => 'image_quantity',
			'choices' => [
				// WAPF qt → per-unit fixed: $2 + $1 per product unit.
				[ 'slug' => 'oak', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 2.0, 'per_unit' => true ] ],
				[ 'slug' => 'ash', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 1.0, 'per_unit' => true ] ],
			],
		];
		$field['image_zoom'] = false;
		$without_zoom = Calculator::field_addon( $field, $values, [ 'price' => 10, 'qty' => 1 ] );
		$field['image_zoom'] = true;
		$this->assertSame( $without_zoom, Calculator::field_addon( $field, $values, [ 'price' => 10, 'qty' => 1 ] ), 'Zoom is presentation-only and must not change the cart addon.' );
		$this->assertSame( 3.0, Calculator::field_addon( $field, $values, [ 'price' => 10, 'qty' => 1 ] ) );
		$this->assertSame( 3.0, Calculator::field_addon( $field, $values, [ 'price' => 10, 'qty' => 3 ] ), 'qt-style pricing is per product unit, not per entered count' );

		// WAPF fixed → flat per line, per selected choice.
		$flat = [
			'id' => 'images', 'type' => 'image_quantity',
			'choices' => [
				[ 'slug' => 'oak', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 2.0, 'per_unit' => false ] ],
				[ 'slug' => 'ash', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 1.5, 'per_unit' => false ] ],
			],
		];
		$this->assertEqualsWithDelta( 3.5 / 3, Calculator::field_addon( $flat, $values, [ 'price' => 10, 'qty' => 3 ] ), 0.000001, 'fixed choices are flat per line' );

		// WAPF nr (val*amount flat per line) and nrq (val*amount per unit)
		// are expressed as [x]-formulas over the entered count.
		$nr = [
			'id' => 'images', 'type' => 'image_quantity',
			'choices' => [
				[ 'slug' => 'oak', 'disabled' => false, 'pricing' => [ 'type' => 'formula', 'formula' => '[x]*2', 'per_unit' => false ] ],
				[ 'slug' => 'ash', 'disabled' => false, 'pricing' => [ 'type' => 'formula', 'formula' => '[x]*3', 'per_unit' => true ] ],
			],
		];
		// oak nr: 2*2/3 per unit; ash nrq: 3*3 per unit → 4/3 + 9.
		$this->assertEqualsWithDelta( 4.0 / 3 + 9.0, Calculator::field_addon( $nr, $values, [ 'price' => 10, 'qty' => 3 ] ), 0.000001 );
		$this->assertEqualsWithDelta( 4.0 + 9.0, Calculator::field_addon( $nr, $values, [ 'price' => 10, 'qty' => 1 ] ), 0.000001 );

		$this->assertSame( 5.0, Calculator::evaluate_formula( 'sumQty(images)', 10, 1, 0, '', null, [ 'images' => $values ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'sumQty(unrelated)', 10, 1, 0, '', null, [ 'unrelated' => [ 2, 3 ] ] ) );
	}

	public function test_formula_from_production_data(): void {
		// Real WAPF formula from production (after qty-compensation strip):
		// "(([price] + [options_total]) * 0.2) * [qty]" → "(([price] + [addons]) * 0.2)"
		$formula = '(([price] + [addons]) * 0.2)';
		$this->assertSame( 22.0, Calculator::evaluate_formula( $formula, 100.0, 3, 10.0 ) );
	}

	public function test_formula_arithmetic(): void {
		$this->assertSame( 20.0, Calculator::evaluate_formula( '[price] * 0.1 + [qty] * 2', 100.0, 5, 0.0 ) );
		$this->assertSame( 6.0, Calculator::evaluate_formula( '(2 + 4) * (3 / 3)', 0.0, 1, 0.0 ) );
		$this->assertSame( -3.0, Calculator::evaluate_formula( '-3', 0.0, 1, 0.0 ) );
	}

	public function test_wapf_builtin_math_text_and_conditional_formula_functions(): void {
		$this->assertSame( 1.0, Calculator::evaluate_formula( 'min(5; 1; 3)', 0.0, 1, 0.0 ) );
		$this->assertSame( 8.0, Calculator::evaluate_formula( 'max(5; 8; 3)', 0.0, 1, 0.0 ) );
		$this->assertSame( 14.0, Calculator::evaluate_formula( 'len(a quick brown fox; true)', 0.0, 1, 0.0 ) );
		$this->assertSame( 17.0, Calculator::evaluate_formula( 'len(a quick brown fox)', 0.0, 1, 0.0 ) );
		$this->assertSame( 20.0, Calculator::evaluate_formula( 'abs(-4) + floor(2.9) + ceil(2.1) + sqrt(9) + pow(2; 3)', 0.0, 1, 0.0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'round(2.54)', 0.0, 1, 0.0 ) );
		$this->assertSame( 2.55, Calculator::evaluate_formula( 'round(2.546; 2)', 0.0, 1, 0.0 ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if(2 < 5; 10; 20)', 0.0, 1, 0.0 ) );
		$this->assertSame( 20.0, Calculator::evaluate_formula( 'if(or(2 < 1; 3 >= 3); 20; 10)', 0.0, 1, 0.0 ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if(and(2 < 1; 3 >= 3); 20; 10)', 0.0, 1, 0.0 ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( 'if(2 = 2; true; false)', 0.0, 1, 0.0 ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if([field.size]=Large;10;20)', 0.0, 1, 0.0, '', null, [ 'size' => 'Large' ] ) );
		$this->assertSame( 4.0, Calculator::evaluate_formula( 'min([field.count]+2; 7)', 0.0, 1, 0.0, '', null, [ 'count' => '2' ] ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'checked(TAGS)', 0.0, 1, 0.0, '', null, [ 'tags' => [ 'red', 'blue' ] ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'checked(missing)', 0.0, 1, 0.0, '', null, [ 'tags' => [ 'red' ] ] ) );
	}

	public function test_len_matches_wapf_server_semantics(): void {
		// WAPF's second argument is case-sensitive: only the literal 'true' strips.
		$this->assertSame( 17.0, Calculator::evaluate_formula( 'len(a quick brown fox; TRUE)', 0.0, 1, 0.0 ) );
		$this->assertSame( 17.0, Calculator::evaluate_formula( 'min(len(a quick brown fox; TRUE); 99)', 0.0, 1, 0.0 ) );
		// PHP \s strips ASCII whitespace only: NBSP, em-space, and NEL count.
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'len([field.t]; true)', 0.0, 1, 0.0, '', null, [ 't' => "A\u{00A0}B" ] ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'len([field.t]; true)', 0.0, 1, 0.0, '', null, [ 't' => "A\u{2003}B" ] ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'len([field.t]; true)', 0.0, 1, 0.0, '', null, [ 't' => "A\u{0085}B" ] ) );
		// mb_strlen counts code points (WAPF order side): emoji counts as one.
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'len([field.t])', 0.0, 1, 0.0, '', null, [ 't' => "A\u{1F600}B" ] ) );
		$this->assertSame( 4.0, Calculator::evaluate_formula( 'len([field.t])', 0.0, 1, 0.0, '', null, [ 't' => "Ae\u{0301}B" ] ) );
		// empty() treats a submitted "0" like WAPF: length zero.
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'len([field.t])', 0.0, 1, 0.0, '', null, [ 't' => '0' ] ) );
		// [field.X] resolves the submitted slug to its choice label.
		$this->assertSame( 11.0, Calculator::evaluate_formula( 'len([field.size])', 0.0, 1, 0.0, '', null, [ 'size' => 'xl' ], 0, [], [ 'size' => [ 'xl' => 'Extra Large' ] ] ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if([field.size]=Extra Large;10;20)', 0.0, 1, 0.0, '', null, [ 'size' => 'xl' ], 0, [], [ 'size' => [ 'xl' => 'Extra Large' ] ] ) );
		// The X_slug suffix picks one submitted value when a field has several.
		$this->assertSame( 6.0, Calculator::evaluate_formula( 'len([field.size_m])', 0.0, 1, 0.0, '', null, [ 'size' => [ 'xl', 'm' ] ], 0, [], [ 'size' => [ 'xl' => 'Extra Large', 'm' => 'Medium' ] ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'len([field.size_missing])', 0.0, 1, 0.0, '', null, [ 'size' => [ 'xl', 'm' ] ], 0, [], [ 'size' => [ 'xl' => 'Extra Large', 'm' => 'Medium' ] ] ) );
	}

	public function test_public_api_registers_safe_formula_functions_with_arguments_and_context(): void {
		API::add_formula_function(
			'opf_test_scale',
			static function ( array $args, array $context ) {
				return (float) $args[0] * (float) $args[1]
					+ (float) $context['price']
					+ (int) $context['qty']
					+ (float) $context['addons']
					+ (int) $context['product_id']
					+ (int) $context['field_values']['count'];
			}
		);

		$this->assertSame( 32.0, Calculator::evaluate_formula( 'opf_test_scale(2; 3)', 9.0, 2, 4.0, '', null, [ 'count' => 1 ], 10 ) );
	}

	public function test_registered_formula_functions_support_nested_calls_and_fail_closed(): void {
		API::add_formula_function(
			'opf_test_double',
			static fn( array $args ) => 1 === count( $args ) ? (float) $args[0] * 2 : 'invalid'
		);

		$this->assertSame( 8.0, Calculator::evaluate_formula( 'opf_test_double(opf_test_double(2))', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'opf_test_double(2;3)', 10.0, 1, 0.0 ) );
	}

	public function test_public_formula_api_rejects_reserved_names_and_handles_callback_failures(): void {
		$this->expectException( \InvalidArgumentException::class );
		API::add_formula_function( 'round', static fn() => 1 );
	}

	public function test_formula_callback_exception_fails_closed(): void {
		API::add_formula_function(
			'opf_test_throw',
			static function () {
				throw new \RuntimeException( 'callback failure' );
			}
		);
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'opf_test_throw(1)', 10.0, 1, 0.0 ) );
	}

	public function test_formula_safety_garbage_yields_zero(): void {
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'system("rm -rf /")', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '[price] *', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '2 + unknown_var', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '1 / 0', 10.0, 1, 0.0 ) );
	}

	public function test_wapf_date_formula_functions_match_weekday_month_and_today_semantics(): void {
		$this->assertSame( 2.0, Calculator::evaluate_formula( "dow('01-10-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( "month('03-01-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( "dow('2024-01-01')", 10.0, 1, 0.0 ) );
		$this->assertSame( 9.0, Calculator::evaluate_formula( 'month(today())', 10.0, 1, 0.0, '', '2026-09-30' ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( "datediff('01-10-2023'; '01-12-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( "datediff('01-12-2023'; '01-10-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( "datediff(today(); '06-18-2026')", 10.0, 1, 0.0, '', '2026-06-15' ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( "datediff('02-30-2023'; '03-01-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'dow([val])', 10.0, 1, 0.0, '01-10-2023' ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( "dow('02-30-2023')", 10.0, 1, 0.0 ) );

		$previous_format = $GLOBALS['opf_test_options']['wapf_date_format'] ?? null;
		try {
			$GLOBALS['opf_test_options']['wapf_date_format'] = 'dd/mm/yyyy';
			$this->assertSame( 3.0, Calculator::evaluate_formula( "dow('01/03/2023')", 10.0, 1, 0.0 ) );
			$this->assertSame( 12.0, Calculator::evaluate_formula( "month('31/12/2023')", 10.0, 1, 0.0 ) );
			$this->assertSame( 2.0, Calculator::evaluate_formula( "datediff('31/12/2023'; '02/01/2024')", 10.0, 1, 0.0 ) );
		} finally {
			if ( null === $previous_format ) {
				unset( $GLOBALS['opf_test_options']['wapf_date_format'] );
			} else {
				$GLOBALS['opf_test_options']['wapf_date_format'] = $previous_format;
			}
		}
	}

	public function test_wapf_date_formula_functions_read_validated_sibling_field_values(): void {
		$field = [
			'type'    => 'text',
			'choices' => [],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => 'month([field.end_date]) + dow([field.start_date])' ],
		];
		$this->assertSame( 3.0, Calculator::field_addon( $field, 'selected', [
			'price'        => 10.0,
			'qty'          => 1,
			'field_values' => [ 'end_date' => '2024-02-29', 'start_date' => '2024-01-01' ],
		] ) );
		$this->assertSame( 0.0, Calculator::field_addon( $field, 'selected', [
			'field_values' => [ 'end_date' => [ 'invalid' ], 'start_date' => [ 'invalid' ] ],
		] ) );
	}

	public function test_field_addon_choice_fields(): void {
		$field = [
			'type'    => 'swatch',
			'choices' => [
				[ 'slug' => 'a', 'label' => 'A', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 2.0, 'formula' => '' ] ],
				[ 'slug' => 'b', 'label' => 'B', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'percent', 'amount' => 50.0, 'formula' => '' ] ],
				[ 'slug' => 'x', 'label' => 'X', 'selected' => false, 'disabled' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 999.0, 'formula' => '' ] ],
			],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
		];
		$this->assertSame( 2.0, Calculator::field_addon( $field, 'a', [ 'price' => 100.0, 'qty' => 1 ] ) );
		$this->assertSame( 50.0, Calculator::field_addon( $field, 'b', [ 'price' => 100.0, 'qty' => 1 ] ) );
		$this->assertSame( 0.0, Calculator::field_addon( $field, 'x', [ 'price' => 100.0, 'qty' => 1 ] ), 'disabled choices never price' );
		$this->assertSame( 0.0, Calculator::field_addon( $field, 'zzz', [ 'price' => 100.0, 'qty' => 1 ] ), 'unknown slugs never price' );
	}

	public function test_formula_price_reference_reads_previously_calculated_field_addon(): void {
		$field = [
			'type' => 'select',
			'choices' => [ [ 'slug' => 'selected', 'disabled' => false, 'pricing' => [ 'type' => 'formula', 'formula' => '[price.plan] + 1' ] ] ],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
		];

		$this->assertSame( 6.0, Calculator::field_addon( $field, 'selected', [ 'price' => 10.0, 'qty' => 1, 'field_prices' => [ 'plan' => 5.0 ] ] ) );
		$this->assertSame( 12.0, Calculator::field_addon( $field, 'selected', [ 'price' => 10.0, 'qty' => 1, 'field_prices' => [ 'plan' => [ 5.0, 6.0 ] ] ] ) );
	}

	public function test_field_addon_text_fields_use_field_pricing(): void {
		$field = [
			'type'    => 'textarea',
			'choices' => [],
			'pricing' => [ 'type' => 'fixed', 'amount' => 1.5, 'formula' => '' ],
		];
		$this->assertSame( 1.5, Calculator::field_addon( $field, 'hello', [ 'price' => 0.0, 'qty' => 1 ] ) );
		$this->assertSame( 0.0, Calculator::field_addon( $field, '   ', [ 'price' => 0.0, 'qty' => 1 ] ), 'blank text never prices' );
		$this->assertSame( 0.0, Calculator::field_addon( $field, '', [ 'price' => 0.0, 'qty' => 1 ] ) );
	}

	public function test_repeated_text_field_prices_each_instance(): void {
		$field = [
			'type' => 'text',
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ],
			'choices' => [],
			'pricing' => [ 'type' => 'fixed', 'amount' => 2.0, 'formula' => '', 'per_unit' => true ],
		];

		$this->assertSame( 4.0, Calculator::field_addon( $field, [ 'First', 'Second' ], [ 'price' => 10.0, 'qty' => 1 ] ) );
	}

	public function test_repeated_checkbox_field_prices_each_instances_choices(): void {
		$field = [
			'type' => 'checkbox',
			'multiple' => true,
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ],
			'choices' => [
				[ 'slug' => 'a', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 1.0, 'per_unit' => true ] ],
				[ 'slug' => 'b', 'disabled' => false, 'pricing' => [ 'type' => 'percent', 'amount' => 10.0 ] ],
			],
			'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
		];

		$this->assertSame( 3.0, Calculator::field_addon( $field, [ [ 'a' ], [ 'b' ] ], [ 'price' => 20.0, 'qty' => 1 ] ) );
	}

	public function test_formula_addons_preserve_negative_contributions(): void {
		$field = [
			'type'    => 'text',
			'choices' => [],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '0 - 100' ],
		];
		$this->assertSame( -100.0, Calculator::field_addon( $field, 'x', [ 'price' => 10.0, 'qty' => 1 ] ) );
	}

	/**
	 * WAPF Extended 3.1.5 files(id): count( explode(',', values[0].label) ) —
	 * the comma-joined upload list of the submitted field, on any field
	 * type. OPF upload submissions carry token arrays → non-empty entries.
	 */
	public function test_files_counts_submitted_upload_list(): void {
		$values = [ 'upfiles' => [ 'tok_a', 'tok_b', 'tok_c' ], 'textf' => 'a,b', 'emptyf' => [] ];
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'files(upfiles)', 0.0, 1, 0.0, '', null, $values ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'files(textf)', 0.0, 1, 0.0, '', null, $values ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( 'files(upfiles)', 0.0, 1, 0.0, '', null, [ 'upfiles' => [ 'tok_a' ] ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'files(emptyf)', 0.0, 1, 0.0, '', null, $values ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'files(nope)', 0.0, 1, 0.0, '', null, $values ) );
		$this->assertSame( 6.0, Calculator::evaluate_formula( 'files(upfiles)*[qty]', 0.0, 2, 0.0, '', null, $values ) );
	}

	/**
	 * WAPF lookuptable(table;dim;…): dimension args under six chars are
	 * literals, longer args resolve a field's first submitted label, axes
	 * round up to the next defined key, below-first clamps, missing
	 * tables/fields/labels and beyond-last keys resolve to 0.
	 */
	public function test_lookuptable_traverses_wapf_nested_tables(): void {
		$tables = [ 'cutting' => [ 10 => [ 5 => 100, 20 => 150 ], 30 => [ 5 => 200, 20 => 300 ] ] ];
		$options = [ 'lookup_tables' => $tables ];
		$values = [ 'widthf' => '15', 'heightf' => '5' ];
		$this->assertSame( 200.0, Calculator::evaluate_formula( 'lookuptable(cutting;widthf;heightf)', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 300.0, Calculator::evaluate_formula( 'lookuptable(cutting;widthf;heightf)', 0.0, 1, 0.0, '', null, [ 'widthf' => '15', 'heightf' => '12' ], 0, [], [], $options ) );
		$this->assertSame( 100.0, Calculator::evaluate_formula( 'lookuptable(cutting;widthf;heightf)', 0.0, 1, 0.0, '', null, [ 'widthf' => '2', 'heightf' => '1' ], 0, [], [], $options ) );
		$this->assertSame( 200.0, Calculator::evaluate_formula( 'lookuptable(cutting;15;5)', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 100.0, Calculator::evaluate_formula( 'lookuptable(cutting;abcde;5)', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'lookuptable(cutting;zzzzzzz;5)', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'lookuptable(nothere;widthf;heightf)', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( "lookuptable('cutting';widthf;heightf)", 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		// WAPF fatals on a mid-chain beyond-last axis — OPF fails closed.
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'lookuptable(cutting;widthf;heightf)', 0.0, 1, 0.0, '', null, [ 'widthf' => '99', 'heightf' => '99' ], 0, [], [], $options ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'lookuptable(cutting;widthf;heightf)', 0.0, 1, 0.0, '', null, [ 'widthf' => '10', 'heightf' => '99' ], 0, [], [], $options ) );
		$this->assertSame( 600.0, Calculator::evaluate_formula( 'lookuptable(cutting;widthf;heightf)*[qty]', 0.0, 3, 0.0, '', null, $values, 0, [], [], $options ) );
	}

	/**
	 * Imported OPF field ids can be shorter than six chars (the mapper
	 * slugifies labels); a submitted field id always wins over WAPF's
	 * <6-char literal heuristic, which only holds because WAPF's own ids
	 * are always >=6 chars.
	 */
	public function test_lookuptable_resolves_short_imported_field_ids(): void {
		$tables  = [ 'cutting' => [ 10 => [ 5 => 100, 20 => 150 ], 30 => [ 5 => 200, 20 => 300 ] ] ];
		$options = [ 'lookup_tables' => $tables ];
		$values  = [ 'width' => '15', 'height' => '5' ];
		$this->assertSame( 200.0, Calculator::evaluate_formula( 'lookuptable(cutting;width;height)', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		// Short literals stay literal when no field of that id is submitted.
		$this->assertSame( 200.0, Calculator::evaluate_formula( 'lookuptable(cutting;15;5)', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'lookuptable(cutting;width;height)', 0.0, 1, 0.0, '', null, [ 'width' => '', 'height' => '5' ], 0, [], [], $options ) );
	}

	/**
	 * WAPF Helper::evaluate_variables parity: [var_name] resolves the first
	 * matching variable's first passing rule else its default, recursively;
	 * names are case-sensitive; unknown variables resolve to 0.
	 */
	public function test_custom_variables_resolve_wapf_style(): void {
		$options = [
			'variables' => [
				[ 'name' => 'fee', 'default' => '1.5', 'rules' => [] ],
				[ 'name' => 'dyn', 'default' => '1', 'rules' => [ [ 'type' => 'field', 'field' => 'sizes', 'condition' => '==', 'value' => 'lg', 'variable' => '2.5' ] ] ],
				[ 'name' => 'qtyvar', 'default' => '10', 'rules' => [ [ 'type' => 'qty', 'field' => 'qty', 'condition' => 'gt', 'value' => '2', 'variable' => '99' ] ] ],
				[ 'name' => 'nested', 'default' => '[var_fee]*2', 'rules' => [] ],
				[ 'name' => 'tworule', 'default' => '0', 'rules' => [ [ 'type' => 'field', 'field' => 'sizes', 'condition' => '==', 'value' => 'lg', 'variable' => '7' ], [ 'type' => 'field', 'field' => 'sizes', 'condition' => '==', 'value' => 'lg', 'variable' => '8' ] ] ],
			],
			'fields' => [ [ 'id' => 'sizes', 'type' => 'select' ] ],
		];
		$values = [ 'sizes' => 'lg' ];
		$this->assertSame( 3.0, Calculator::evaluate_formula( '[var_fee]*2', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 2.5, Calculator::evaluate_formula( '[var_dyn]', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( '[var_dyn]', 0.0, 1, 0.0, '', null, [ 'sizes' => 'sm' ], 0, [], [], $options ) );
		$this->assertSame( 99.0, Calculator::evaluate_formula( '[var_qtyvar]', 0.0, 5, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( '[var_qtyvar]', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( '[var_nested]', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 7.0, Calculator::evaluate_formula( '[var_tworule]', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '[var_nothere]', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '[VAR_FEE]', 0.0, 1, 0.0, '', null, $values, 0, [], [], $options ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( '([var_fee]+1)*[qty]', 0.0, 4, 0.0, '', null, $values, 0, [], [], $options ) );
		// A variable body may itself hold field refs and formula functions.
		$options['variables'][] = [ 'name' => 'fxvar', 'default' => '[field.widthf]*files(upfiles)', 'rules' => [] ];
		$this->assertSame( 45.0, Calculator::evaluate_formula( '[var_fxvar]', 0.0, 1, 0.0, '', null, [ 'widthf' => '15', 'upfiles' => [ 'a', 'b', 'c' ], 'sizes' => 'lg' ], 0, [], [], $options ) );
		// Variables also reach pricing formulas through field_addon context.
		$field = [
			'type'    => 'text',
			'choices' => [],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '[var_fee]*10' ],
		];
		$this->assertSame( 15.0, Calculator::field_addon( $field, 'x', [ 'price' => 10.0, 'qty' => 1, 'field_values' => $values, 'variables' => $options['variables'], 'fields' => $options['fields'] ] ) );
	}

	/**
	 * WAPF Extended 3.1.5 never registered map()/reduce() formula functions:
	 * unregistered calls fall through to evaluate_math_string's char-clean
	 * residual (keeping e/E), which yields map(1;2) === 12 and
	 * reduce(1;2) === 0 — an emergent quirk, mirrored here verbatim.
	 */
	public function test_unregistered_function_calls_hit_wapf_residual_eval(): void {
		$this->assertSame( 12.0, Calculator::evaluate_formula( 'map(1;2)', 0.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'reduce(1;2)', 0.0, 1, 0.0 ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'map(widthF;x*2)', 0.0, 1, 0.0 ) );
	}

	/** WAPF [x] aliases [val] (the current field input). */
	public function test_x_token_aliases_val(): void {
		$this->assertSame( 8.0, Calculator::evaluate_formula( '[x]*2', 10.0, 1, 0.0, '4' ) );
	}

	/**
	 * WAPF char/charq pricing is mb_strlen(val)*amount; a bare [x]/[val]
	 * inside len() must measure the submitted text, not the numeric ' V '
	 * placeholder. char → len*amount flat per line; charq → per unit.
	 */
	public function test_len_of_x_measures_submitted_text_for_char_pricing(): void {
		$this->assertSame( 4.0, Calculator::evaluate_formula( 'len([x])', 0.0, 1, 0.0, 'abcd' ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'len([x];true)', 0.0, 1, 0.0, ' a b ' ) );
		$field = [
			'type' => 'text', 'choices' => [],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => 'len([x])*2', 'per_unit' => false ],
		];
		// char: 4 chars * 2 = 8 per line → 8/3 per unit.
		$this->assertEqualsWithDelta( 8.0 / 3, Calculator::field_addon( $field, 'abcd', [ 'price' => 0.0, 'qty' => 3 ] ), 0.000001 );
		$field['pricing']['per_unit'] = true; // charq.
		$this->assertSame( 8.0, Calculator::field_addon( $field, 'abcd', [ 'price' => 0.0, 'qty' => 3 ] ) );
	}

	/**
	 * WAPF split_formula_variables is quote-blind: ';' inside quoted text
	 * still separates arguments, so len('a;b') measures the truncated "'a".
	 */
	public function test_semicolon_inside_quotes_still_separates_arguments(): void {
		$this->assertSame( 2.0, Calculator::evaluate_formula( "len('a;b')", 0.0, 1, 0.0 ) );
	}
}
