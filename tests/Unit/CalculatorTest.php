<?php
/**
 * Calculator unit tests — pricing semantics and formula safety.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase {

	public function test_fixed_pricing_flat_per_line_wapf_parity(): void {
		$pricing = [ 'type' => 'fixed', 'amount' => 5.0, 'formula' => '', 'per_unit' => false ];
		// Flat fee: per-unit contribution shrinks with qty so the LINE total
		// adds exactly the fee (WAPF parity).
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertEqualsWithDelta( 5.0 / 3, Calculator::choice_addon( $pricing, 100.0, 3, 0.0 ), 0.000001 );
		$this->assertEqualsWithDelta( 5.0, Calculator::choice_addon( $pricing, 100.0, 3, 0.0 ) * 3, 0.000001 );
	}

	public function test_fixed_pricing_per_unit_opt_in(): void {
		$pricing = [ 'type' => 'fixed', 'amount' => 5.0, 'formula' => '', 'per_unit' => true ];
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertSame( 5.0, Calculator::choice_addon( $pricing, 100.0, 3, 0.0 ), 'per-unit fixed does not shrink' );
		$this->assertSame( 15.0, Calculator::choice_addon( $pricing, 100.0, 3, 0.0 ) * 3 );
	}

	public function test_percent_of_unit_price(): void {
		$pricing = [ 'type' => 'percent', 'amount' => 20.0, 'formula' => '' ];
		$this->assertSame( 20.0, Calculator::choice_addon( $pricing, 100.0, 1, 0.0 ) );
		$this->assertSame( 2.0, Calculator::choice_addon( $pricing, 10.0, 5, 0.0 ) );
	}

	public function test_percent_flat_and_quantity_based_fees_scale_differently(): void {
		$flat = [ 'type' => 'percent', 'amount' => 10.0, 'formula' => '', 'per_unit' => false ];
		$quantity_based = [ 'type' => 'percent', 'amount' => 10.0, 'formula' => '', 'per_unit' => true ];

		$this->assertEqualsWithDelta( 2.5, Calculator::choice_addon( $flat, 100.0, 4, 0.0 ), 0.000001 );
		$this->assertSame( 10.0, Calculator::choice_addon( $quantity_based, 100.0, 4, 0.0 ) );
		$this->assertSame( 10.0, Calculator::choice_addon( $flat, 100.0, 1, 0.0 ) * 1 );
		$this->assertSame( 10.0, Calculator::choice_addon( $quantity_based, 100.0, 1, 0.0 ) * 1 );
		$this->assertEqualsWithDelta( 10.0, Calculator::choice_addon( $flat, 100.0, 4, 0.0 ) * 4, 0.000001 );
		$this->assertSame( 40.0, Calculator::choice_addon( $quantity_based, 100.0, 4, 0.0 ) * 4 );
	}

	public function test_character_and_value_fees_can_be_flat_or_quantity_based(): void {
		$flat_characters = [ 'type' => 'characters', 'amount' => 2.0, 'formula' => '', 'per_unit' => false ];
		$quantity_characters = [ 'type' => 'characters', 'amount' => 2.0, 'formula' => '', 'per_unit' => true ];
		$flat_value = [ 'type' => 'value', 'amount' => 2.0, 'formula' => '', 'per_unit' => false ];
		$quantity_value = [ 'type' => 'value', 'amount' => 2.0, 'formula' => '', 'per_unit' => true ];

		$this->assertEqualsWithDelta( 1.5, Calculator::field_pricing_addon( $flat_characters, 'abc', 0.0, 4, 0.0 ), 0.000001 );
		$this->assertSame( 6.0, Calculator::field_pricing_addon( $quantity_characters, 'abc', 0.0, 4, 0.0 ) );
		$this->assertEqualsWithDelta( 1.5, Calculator::field_pricing_addon( $flat_value, '3', 0.0, 4, 0.0 ), 0.000001 );
		$this->assertSame( 6.0, Calculator::field_pricing_addon( $quantity_value, '3', 0.0, 4, 0.0 ) );
		$this->assertSame( 6.0, Calculator::field_pricing_addon( $flat_characters, 'abc', 0.0, 1, 0.0 ) * 1 );
		$this->assertSame( 6.0, Calculator::field_pricing_addon( $quantity_characters, 'abc', 0.0, 1, 0.0 ) * 1 );
		$this->assertEqualsWithDelta( 6.0, Calculator::field_pricing_addon( $flat_characters, 'abc', 0.0, 4, 0.0 ) * 4, 0.000001 );
		$this->assertSame( 24.0, Calculator::field_pricing_addon( $quantity_characters, 'abc', 0.0, 4, 0.0 ) * 4 );
		$this->assertSame( 6.0, Calculator::field_pricing_addon( $flat_value, '3', 0.0, 1, 0.0 ) * 1 );
		$this->assertSame( 6.0, Calculator::field_pricing_addon( $quantity_value, '3', 0.0, 1, 0.0 ) * 1 );
		$this->assertEqualsWithDelta( 6.0, Calculator::field_pricing_addon( $flat_value, '3', 0.0, 4, 0.0 ) * 4, 0.000001 );
		$this->assertSame( 24.0, Calculator::field_pricing_addon( $quantity_value, '3', 0.0, 4, 0.0 ) * 4 );
	}

	public function test_normalized_pricing_preserves_explicit_quantity_mode(): void {
		foreach ( [ 'percent', 'characters', 'value' ] as $type ) {
			$this->assertFalse( FieldGroup::normalize_pricing( [ 'type' => $type, 'amount' => 1, 'per_unit' => false ] )['per_unit'] );
			$this->assertTrue( FieldGroup::normalize_pricing( [ 'type' => $type, 'amount' => 1, 'per_unit' => true ] )['per_unit'] );
			$this->assertTrue( FieldGroup::normalize_pricing( [ 'type' => $type, 'amount' => 1 ] )['per_unit'] );
		}
	}

	public function test_formula_from_production_data(): void {
		// Real WAPF formula from production (after qty-compensation strip):
		// "(([price] + [options_total]) * 0.2) * [qty]" → "(([price] + [addons]) * 0.2)"
		$formula = '(([price] + [addons]) * 0.2)';
		$this->assertSame( 22.0, Calculator::evaluate_formula( $formula, 100.0, 3, 10.0 ) );
	}

	public function test_formula_pricing_results_are_line_amounts_converted_to_per_unit(): void {
		$quantity_formula = [ 'type' => 'formula', 'formula' => '5 * [qty]' ];
		$flat_formula = [ 'type' => 'formula', 'formula' => '5' ];
		$choice_field = [ 'type' => 'select', 'choices' => [
			[ 'slug' => 'quantity', 'disabled' => false, 'pricing' => $quantity_formula ],
			[ 'slug' => 'flat', 'disabled' => false, 'pricing' => $flat_formula ],
		] ];
		$text_field = [ 'type' => 'text', 'pricing' => $quantity_formula ];

		foreach ( [ 1, 4 ] as $qty ) {
			$this->assertEqualsWithDelta( 5.0, Calculator::choice_addon( $quantity_formula, 100, $qty, 0 ), 0.000001 );
			$this->assertEqualsWithDelta( 5.0 * $qty, Calculator::choice_addon( $quantity_formula, 100, $qty, 0 ) * $qty, 0.000001 );
			$this->assertEqualsWithDelta( 5.0, Calculator::choice_addon( $flat_formula, 100, $qty, 0 ) * $qty, 0.000001 );
			$this->assertEqualsWithDelta( 5.0, Calculator::field_addon( $choice_field, 'quantity', [ 'price' => 100, 'qty' => $qty ] ), 0.000001 );
			$this->assertEqualsWithDelta( 5.0 * $qty, Calculator::field_addon( $choice_field, 'quantity', [ 'price' => 100, 'qty' => $qty ] ) * $qty, 0.000001 );
			$this->assertEqualsWithDelta( 5.0, Calculator::field_addon( $choice_field, 'flat', [ 'price' => 100, 'qty' => $qty ] ) * $qty, 0.000001 );
			$this->assertEqualsWithDelta( 5.0, Calculator::field_pricing_addon( $text_field['pricing'], 'engraved', 100, $qty, 0 ), 0.000001 );
			$this->assertEqualsWithDelta( 5.0 * $qty, Calculator::field_pricing_addon( $text_field['pricing'], 'engraved', 100, $qty, 0 ) * $qty, 0.000001 );
		}
	}

	public function test_formula_and_direct_pricing_discounts_remain_signed_until_final_price_clamp(): void {
		foreach ( [ 1, 4 ] as $qty ) {
			$negative_formula = [ 'type' => 'formula', 'formula' => '-10 * [qty]' ];
			$this->assertEqualsWithDelta( -10.0, Calculator::choice_addon( $negative_formula, 100, $qty, 0 ), 0.000001 );
			$this->assertEqualsWithDelta( -10.0 * $qty, Calculator::choice_addon( $negative_formula, 100, $qty, 0 ) * $qty, 0.000001 );
			$this->assertEqualsWithDelta( -10.0, Calculator::field_addon( [ 'type' => 'select', 'choices' => [ [ 'slug' => 'discount', 'disabled' => false, 'pricing' => $negative_formula ] ] ], 'discount', [ 'price' => 100, 'qty' => $qty ] ), 0.000001 );
			$this->assertEqualsWithDelta( -10.0, Calculator::field_pricing_addon( [ 'type' => 'fixed', 'amount' => -10, 'per_unit' => false ], 'value', 100, $qty, 0 ) * $qty, 0.000001 );
		}
	}

	public function test_formula_variable_tokens_resolve_as_numeric_context(): void {
		$this->assertSame( 7.5, Calculator::evaluate_formula( '[var_wrap_cost] * 3', 100.0, 1, 0.0, '', [], null, [], [], [ 'wrap_cost' => 2.5 ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '[var_unknown] * 3', 100.0, 1, 0.0 ) );
		$group = FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'addon', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => '[var_wrap_cost]' ] ] ],
			'formula_variables' => [ 'wrap_cost' => [ 'default' => 1, 'changes' => [ [ 'value' => 2, 'logic' => 'all', 'rules' => [ [ 'field' => 'size', 'operator' => 'is', 'value' => 'large' ] ] ] ] ] ],
		] );
		$variables = FieldGroup::resolve_formula_variables( $group['formula_variables'], [ 'size' => 'large' ] );
		$this->assertSame( 2.0, Calculator::field_addon( $group['fields'][0], 'yes', [ 'field_values' => [ 'size' => 'large' ], 'formula_variables' => $variables ] ) );
	}

	public function test_price_field_tokens_resolve_selected_per_item_prices_including_forward_references(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'early', 'type' => 'select', 'choices' => [ [ 'label' => 'Early', 'slug' => 'yes', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.later] + [price.size]' ] ] ] ],
				[ 'id' => 'size', 'type' => 'select', 'choices' => [ [ 'label' => 'Large', 'slug' => 'large', 'pricing' => [ 'type' => 'fixed', 'amount' => 20, 'per_unit' => true ] ] ] ],
				[ 'id' => 'later', 'type' => 'select', 'choices' => [ [ 'label' => 'Gold', 'slug' => 'gold', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.size] * 0.25' ] ] ] ],
			],
		] );
		$values = [ 'early' => 'yes', 'size' => 'large', 'later' => 'gold' ];
		$prices = Calculator::field_price_map( $group['fields'], $values, [ 'price' => 100, 'qty' => 1 ] );

		$this->assertSame( 20.0, $prices['size'] );
		$this->assertSame( 5.0, $prices['later'] );
		$this->assertSame( 25.0, $prices['early'] );
		$this->assertSame( 25.0, Calculator::evaluate_formula( '[price.EARLY]', 100, 1, 0, '', [], null, [], [], [], $prices ) );
	}

	public function test_price_field_tokens_are_zero_for_hidden_unselected_or_unknown_fields(): void {
		$prices = Calculator::field_price_map( [
			[ 'id' => 'selected', 'type' => 'select', 'choices' => [ [ 'slug' => 'yes', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 8, 'per_unit' => true ] ] ], 'pricing' => [ 'type' => 'none', 'amount' => 0, 'formula' => '', 'per_unit' => true ], 'conditionals' => [] ],
		], [ 'selected' => '' ], [ 'price' => 100, 'qty' => 1 ] );

		$this->assertSame( 0.0, Calculator::evaluate_formula( '[price.selected] + [price.missing]', 100, 1, 0, '', [], null, [], [], [], $prices ) );
	}

	public function test_price_field_reference_cycles_fail_closed_for_every_member(): void {
		$fields = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'a', 'type' => 'select', 'choices' => [ [ 'slug' => 'yes', 'label' => 'Yes', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.b] + 1' ] ] ] ],
				[ 'id' => 'b', 'type' => 'select', 'choices' => [ [ 'slug' => 'yes', 'label' => 'Yes', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.a] + 1' ] ] ] ],
			],
		] )['fields'];
		$prices = Calculator::field_price_map( $fields, [ 'a' => 'yes', 'b' => 'yes' ], [ 'price' => 10.0, 'qty' => 1 ] );

		$this->assertSame( 0.0, $prices['a'] );
		$this->assertSame( 0.0, $prices['b'] );
	}

	public function test_formula_arithmetic(): void {
		$this->assertSame( 20.0, Calculator::evaluate_formula( '[price] * 0.1 + [qty] * 2', 100.0, 5, 0.0 ) );
		$this->assertSame( 6.0, Calculator::evaluate_formula( '(2 + 4) * (3 / 3)', 0.0, 1, 0.0 ) );
		$this->assertSame( -3.0, Calculator::evaluate_formula( '-3', 0.0, 1, 0.0 ) );
	}

	public function test_formula_safety_garbage_yields_zero(): void {
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'system("rm -rf /")', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '[price] *', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '2 + unknown_var', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( '1 / 0', 10.0, 1, 0.0 ) );
	}

	public function test_formula_allow_list_functions_are_bounded(): void {
		$this->assertSame( 4.0, Calculator::evaluate_formula( 'max( min([price], 4), abs(-2) )', 10.0, 1, 0.0 ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if([field.width] > 5; 10; 20)', 10.0, 1, 0.0, '', [ 'width' => '8' ] ) );
		$this->assertSame( 20.0, Calculator::evaluate_formula( 'if(and([field.width] >= 5; [field.width] < 10); 10; 20)', 10.0, 1, 0.0, '', [ 'width' => '4' ] ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if(or([field.width] = 8; [field.width] != 4); 10; 20)', 10.0, 1, 0.0, '', [ 'width' => '8' ] ) );
		$this->assertEqualsWithDelta( 0.3334, Calculator::evaluate_formula( 'round(0.33337; 4)', 10.0, 1, 0.0 ), 0.000001 );
		$this->assertSame( 5.0, Calculator::evaluate_formula( 'round(4.8)', 10.0, 1, 0.0 ) );
		$this->assertSame( 5.0, Calculator::evaluate_formula( 'ceil(4.1)', 10.0, 1, 0.0 ) );
		$this->assertSame( 4.0, Calculator::evaluate_formula( 'floor(4.8)', 10.0, 1, 0.0 ) );
		$this->assertSame( 16.0, Calculator::evaluate_formula( 'pow(4; 2)', 10.0, 1, 0.0 ) );
		$this->assertSame( 12.0, Calculator::evaluate_formula( 'sqrt(144)', 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'sqrt(-1)', 10.0, 1, 0.0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'round(2.5)', 10.0, 1, 0.0 ) );
		$this->assertSame( -3.0, Calculator::evaluate_formula( 'round(-2.5)', 10.0, 1, 0.0 ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( 'min(1)', 10.0, 1, 0.0 ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'min(4; 2; 3)', 10.0, 1, 0.0 ) );
		$this->assertSame( 9.0, Calculator::evaluate_formula( 'max(4; 9; 3)', 10.0, 1, 0.0 ) );
		$this->assertEqualsWithDelta( sin( 0.5 ), Calculator::evaluate_formula( 'sin(0.5)', 10.0, 1, 0.0 ), 0.000001 );
		$this->assertEqualsWithDelta( cos( 0.5 ), Calculator::evaluate_formula( 'cos(0.5)', 10.0, 1, 0.0 ), 0.000001 );
		$this->assertEqualsWithDelta( tan( 0.5 ), Calculator::evaluate_formula( 'tan(0.5)', 10.0, 1, 0.0 ), 0.000001 );
		$this->assertSame( 9.0, Calculator::evaluate_formula( 'datediff([field.start]; [field.end])', 10.0, 1, 0.0, '', [ 'start' => '2026-06-01', 'end' => '2026-06-10' ], '2026-06-15' ) );
		$this->assertSame( 360.0, Calculator::evaluate_formula( 'datediff([field.start]; [field.end]) * 40', 10.0, 1, 0.0, '', [ 'start' => '2026-06-01', 'end' => '2026-06-10' ], '2026-06-15' ) );
		$this->assertSame( -9.0, Calculator::evaluate_formula( 'datediff([field.end]; [field.start])', 10.0, 1, 0.0, '', [ 'start' => '2026-06-01', 'end' => '2026-06-10' ], '2026-06-15' ) );
		$this->assertSame( 14.0, Calculator::evaluate_formula( 'datediff([field.start]; today())', 10.0, 1, 0.0, '', [ 'start' => '2026-06-01' ], '2026-06-15' ) );
		$this->assertSame( 14.0, Calculator::evaluate_formula( 'datediff(today(); [field.end])', 10.0, 1, 0.0, '', [ 'end' => '2026-06-29' ], '2026-06-15' ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( "dow('01-10-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( 'dow([field.start])', 10.0, 1, 0.0, '', [ 'start' => '2024-01-01' ] ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( 'dow(today())', 10.0, 1, 0.0, '', [], '2026-06-15' ) );
		$this->assertSame( 1.0, Calculator::evaluate_formula( "month('01-03-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( "month('03-01-2023')", 10.0, 1, 0.0 ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( "dow('02-30-2023')", 10.0, 1, 0.0 ), 'invalid date inputs fail closed' );
		$previous_date_format = $GLOBALS['opf_test_options']['opf_date_format'] ?? null;
		try {
			$GLOBALS['opf_test_options']['opf_date_format'] = 'dd/mm/yyyy';
			$this->assertSame( 0.0, Calculator::evaluate_formula( "dow('31/12/2023')", 10.0, 1, 0.0 ) );
			$this->assertSame( 12.0, Calculator::evaluate_formula( "month('31/12/2023')", 10.0, 1, 0.0 ) );
		} finally {
			if ( null === $previous_date_format ) {
				unset( $GLOBALS['opf_test_options']['opf_date_format'] );
			} else {
				$GLOBALS['opf_test_options']['opf_date_format'] = $previous_date_format;
			}
		}
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'datediff([field.start]; [field.end])', 10.0, 1, 0.0, '', [ 'start' => '', 'end' => '2026-06-10' ], '2026-06-15' ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'datediff([field.start]; [field.end])', 10.0, 1, 0.0, '', [ 'start' => '2026-02-30', 'end' => '2026-06-10' ], '2026-06-15' ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'datediff([field.start]; [field.end])', 10.0, 1, 0.0, '', [ 'start' => '2024-02-28', 'end' => '2024-03-01' ], '2024-03-01' ) );
		$this->assertSame( 2.0, Calculator::evaluate_formula( 'checked(extras)', 10.0, 1, 0.0, '', [ 'extras' => [ 'gift', 'priority' ] ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'checked(extras)', 10.0, 1, 0.0, '', [ 'extras' => [] ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'checked(extras)', 10.0, 1, 0.0, '', [ 'extras' => 'gift' ] ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'checked(extras) * 5 * [qty]', 10.0, 1, 0.0, '', [ 'extras' => [ 'gift', 'priority' ] ] ) );
		$this->assertSame( 30.0, Calculator::evaluate_formula( 'files(artwork) * 5 * [qty]', 10.0, 3, 0.0, '', [], null, [ 'artwork' => 2 ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'files(artwork)', 10.0, 1, 0.0 ) );
		$this->assertSame( 5.0, Calculator::evaluate_formula( 'sumQty(images)', 10.0, 1, 0.0, '', [ 'images' => [ 'a' => 2, 'b' => 3 ] ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'sumQty(images)', 10.0, 1, 0.0, '', [ 'images' => [ 'a' => 'bad' ] ] ) );
		$this->assertSame( 14.0, Calculator::evaluate_formula( 'len(a quick brown fox; true)', 10.0, 1, 0.0 ) );
		$this->assertSame( 7.0, Calculator::evaluate_formula( 'len([field.title]; false)', 10.0, 1, 0.0, '', [ 'title' => 'Déjà vu' ] ) );
		$this->assertSame( 6.0, Calculator::evaluate_formula( 'len([field.title]; true)', 10.0, 1, 0.0, '', [ 'title' => 'Déjà vu' ] ) );
		$this->assertSame( 10.0, Calculator::evaluate_formula( 'if([field.color] = Red; 10; 20)', 10.0, 1, 0.0, '', [ 'color' => 'Red' ] ) );
		$this->assertSame( 20.0, Calculator::evaluate_formula( 'if([field.color] = Red; 10; 20)', 10.0, 1, 0.0, '', [ 'color' => 'Blue' ] ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( str_repeat( '1+', 3000 ) . '1', 10.0, 1, 0.0 ) );
	}

	public function test_formula_lookup_tables_support_exact_and_two_dimension_round_up(): void {
		$tables = [
			'quantity_price' => [ [ '100', 10 ], [ '200', 20 ] ],
			'blinds' => [
				[ '200', '100', 70 ],
				[ '220', '100', 74 ],
				[ '200', '160', 74 ],
				[ '220', '160', 78 ],
			],
			'print' => [
				[ '500', '4x6', 'Glossy', 30 ],
				[ '500', '4x6', 'Matte', 34 ],
				[ '1000', '4x6', 'Glossy', 56 ],
			],
		];

		$this->assertSame( 20.0, Calculator::evaluate_formula( 'lookuptable(quantity_price; quantity)', 0.0, 1, 0.0, '', [ 'quantity' => '150' ], null, [], $tables ) );
		$this->assertSame( 78.0, Calculator::evaluate_formula( 'lookuptable(blinds; width; height)', 0.0, 1, 0.0, '', [ 'width' => '210', 'height' => '150' ], null, [], $tables ) );
		$this->assertSame( 70.0, Calculator::evaluate_formula( 'lookuptable(blinds; width; height)', 0.0, 1, 0.0, '', [ 'width' => '200', 'height' => '100' ], null, [], $tables ) );
		$this->assertSame( 56.0, Calculator::evaluate_formula( 'lookuptable(print; quantity; size; paper)', 0.0, 1, 0.0, '', [ 'quantity' => '1000', 'size' => '4x6', 'paper' => 'Glossy' ], null, [], $tables ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'lookuptable(print; quantity; size; paper)', 0.0, 1, 0.0, '', [ 'quantity' => '1000', 'size' => '8x10', 'paper' => 'Glossy' ], null, [], $tables ) );
		$field = [
			'id' => 'price', 'type' => 'select',
			'choices' => [ [ 'slug' => 'custom', 'disabled' => false, 'pricing' => [ 'type' => 'formula', 'formula' => 'lookuptable(blinds; width; height)' ] ] ],
			'pricing' => [ 'type' => 'none', 'amount' => 0, 'formula' => '' ],
		];
		$this->assertSame( 39.0, Calculator::field_addon( $field, 'custom', [ 'price' => 0, 'qty' => 2, 'addons' => 0, 'field_values' => [ 'width' => '210', 'height' => '150' ], 'lookup_tables' => $tables ] ) );
	}

	public function test_image_quantity_choices_normalize_price_and_sum_quantities(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'photos',
			'type' => 'swatch',
			'image_quantities' => true,
			'min_selections' => 1,
			'max_selections' => 2,
			'min_total_quantity' => 2,
			'max_total_quantity' => 6,
			'min' => 1,
			'max' => 5,
			'step' => 1,
			'choices' => [
				[ 'slug' => 'a', 'label' => 'A', 'image' => '/a.jpg', 'weight' => 1.5, 'pricing' => [ 'type' => 'fixed', 'amount' => 2 ] ],
				[ 'slug' => 'b', 'label' => 'B', 'image' => '/b.jpg', 'weight' => 2.0, 'pricing' => [ 'type' => 'fixed', 'amount' => 3 ] ],
			],
		] );

		$this->assertTrue( $field['image_quantities'] );
		$this->assertFalse( $field['multiple'] ?? false );
		$this->assertSame( 2.0, Calculator::field_addon( $field, [ 'a' => 1 ], [ 'price' => 10, 'qty' => 1 ] ) );
		$this->assertSame( 8.0, Calculator::field_addon( $field, [ 'a' => 1, 'b' => 2 ], [ 'price' => 10, 'qty' => 1 ] ) );
		$this->assertSame( 3.0, Calculator::evaluate_formula( 'sumQty(photos)', 10, 1, 0, '', [ 'photos' => [ 'a' => 1, 'b' => 2 ] ] ) );
		$this->assertSame( 5.5, Calculator::field_weight_delta( $field, [ 'a' => 1, 'b' => 2 ] ) );
		$this->assertSame( [], FieldValue::validate_image_quantities( $field, [ 'a' => '1', 'b' => '2' ], true ) );
		$this->assertNotSame( [], FieldValue::validate_image_quantities( $field, [ 'a' => '0', 'b' => '6' ], true ) );
		$this->assertNotSame( [], FieldValue::validate_image_quantities( $field, [ 'a' => '1', 'b' => '2', 'rogue' => '1' ], true ) );
		$this->assertNotSame( [], FieldValue::validate_image_quantities( $field, [], false ), 'minimum total applies even when no quantity was submitted' );
		$this->assertNotSame( [], FieldValue::validate_image_quantities( $field, [ 'a' => '2', 'b' => '5' ], true ), 'total quantity is bounded independently from per-image quantities' );
		$stepped = FieldGroup::normalize_field( [ 'id' => 'stepped', 'type' => 'swatch', 'image_quantities' => true, 'min' => 2, 'max' => 6, 'step' => 2, 'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'image' => '/a.jpg' ] ] ] );
		$this->assertSame( [], FieldValue::validate_image_quantities( $stepped, [ 'a' => '4' ], true ) );
		$this->assertNotSame( [], FieldValue::validate_image_quantities( $stepped, [ 'a' => '3' ], true ) );
	}

	public function test_image_quantity_controls_reject_fractional_or_non_positive_bounds(): void {
		$choice = [ 'slug' => 'a', 'label' => 'A', 'image' => '/a.jpg' ];
		foreach ( [ [ 'min' => 1.5 ], [ 'max' => 0 ], [ 'step' => 0 ], [ 'step' => 0.5 ] ] as $constraint ) {
			try {
				FieldGroup::normalize_field( array_merge( [ 'id' => 'photos', 'type' => 'swatch', 'image_quantities' => true, 'choices' => [ $choice ] ], $constraint ) );
				$this->fail( 'Invalid image quantity bounds should throw.' );
			} catch ( \InvalidArgumentException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}
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

	public function test_multiple_swatch_prices_each_selected_choice(): void {
		$field = [
			'type' => 'swatch',
			'multiple' => true,
			'choices' => [
				[ 'slug' => 'a', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 2.0, 'formula' => '', 'per_unit' => true ] ],
				[ 'slug' => 'b', 'disabled' => false, 'pricing' => [ 'type' => 'fixed', 'amount' => 3.0, 'formula' => '', 'per_unit' => true ] ],
				[ 'slug' => 'x', 'disabled' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 999.0, 'formula' => '', 'per_unit' => true ] ],
			],
		];

		$this->assertSame( 5.0, Calculator::field_addon( $field, [ 'a', 'b' ], [ 'price' => 100.0, 'qty' => 1 ] ) );
		$this->assertSame( 2.0, Calculator::field_addon( $field, [ 'a', 'x' ], [ 'price' => 100.0, 'qty' => 1 ] ) );
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

	public function test_negative_formula_addons_preserve_signed_discount(): void {
		$field = [
			'type'    => 'text',
			'choices' => [],
			'pricing' => [ 'type' => 'formula', 'amount' => 0.0, 'formula' => '0 - 100' ],
		];
		$this->assertSame( -100.0, Calculator::field_addon( $field, 'x', [ 'price' => 10.0, 'qty' => 1 ] ) );
	}

	public function test_value_character_and_quantity_pricing_are_bounded(): void {
		$base = [ 'type' => 'text', 'choices' => [] ];
		$base['pricing'] = [ 'type' => 'characters', 'amount' => 2, 'formula' => '' ];
		$this->assertSame( 6.0, Calculator::field_addon( $base, 'éx!', [ 'qty' => 1 ] ) );
		$base['pricing'] = [ 'type' => 'value', 'amount' => 1.5, 'formula' => '' ];
		$this->assertSame( 6.0, Calculator::field_addon( $base, '4', [ 'qty' => 1 ] ) );
		$base['pricing'] = [ 'type' => 'quantity', 'amount' => 3, 'formula' => '' ];
		$this->assertSame( 3.0, Calculator::field_addon( $base, 'x', [ 'qty' => 4 ] ) );
	}

	public function test_weight_delta_ignores_disabled_and_unknown_choices(): void {
		$field = [ 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'a', 'disabled' => false, 'weight' => 1.25 ], [ 'slug' => 'b', 'disabled' => true, 'weight' => 99 ] ] ];
		$this->assertSame( 1.25, Calculator::field_weight_delta( $field, [ 'a', 'b', 'unknown' ] ) );
	}

	public function test_formula_weight_uses_numeric_field_values_and_keeps_choice_deltas(): void {
		$field = [
			'id' => 'weight', 'type' => 'number', 'weight_formula' => '[field.weight] * 1',
			'choices' => [],
		];
		$this->assertSame( 1.75, Calculator::field_weight_delta( $field, '1.75', [ 'weight' => '1.75' ] ) );
		$this->assertSame( 2.5, Calculator::field_weight_delta( [ 'type' => 'select', 'weight_formula' => '[field.amount] * 1', 'choices' => [ [ 'slug' => 'a', 'disabled' => false, 'weight' => 0.5 ] ] ], 'a', [ 'amount' => '2' ] ) );
		$this->assertSame( -3.0, Calculator::field_weight_delta( [ 'type' => 'number', 'weight_formula' => '[field.weight] * -1', 'choices' => [] ], '3', [ 'weight' => '3' ] ) );
		$this->assertSame( 0.0, Calculator::field_weight_delta( [ 'type' => 'number', 'weight_formula' => '[field.missing] / 0', 'choices' => [] ], '3', [ 'weight' => '3' ] ) );
	}
}
