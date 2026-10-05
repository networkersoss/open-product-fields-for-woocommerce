<?php
/** ACF pricing formula integration tests. */

namespace OPF\Tests\Unit;

use OPF\Engine\AcfFormula;
use OPF\Engine\Calculator;
use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class AcfFormulaTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['opf_test_acf_fields'] );
		parent::tearDown();
	}

	public function test_product_and_options_page_acf_values_resolve_only_as_finite_numbers(): void {
		$GLOBALS['opf_test_acf_fields'] = [
			'product:42:unit_cost' => '12.5',
			'option::gold_price' => 3.25,
			'product:42:bad_value' => [ 'not', 'numeric' ],
		];
		$group = [
			'fields' => [
				[ 'pricing' => [ 'formula' => 'acf(unit_cost) + acf_option(gold_price)' ] ],
				[ 'formula' => 'acf(bad_value) + acf(missing)' ],
			],
		];
		$values = AcfFormula::variable_values_for_group( $group, 42 );

		$this->assertSame( 12.5, $values['opf_acf_field_unit_cost'] );
		$this->assertSame( 3.25, $values['opf_acf_option_gold_price'] );
		$this->assertSame( 0.0, $values['opf_acf_field_bad_value'] );
		$this->assertSame( 0.0, $values['opf_acf_field_missing'] );
		// Master signature: ($formula, $price, $qty, $addons, $val, $today,
		// $field_values, $product_id, $field_prices, $field_labels, $options).
		$options = [ 'formula_variables' => $values ];
		$this->assertSame( 15.75, Calculator::evaluate_formula( 'acf(unit_cost) + acf_option(gold_price)', 0, 1, 0, '', null, [], 0, [], [], $options ) );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'acf(bad_value) + acf(missing)', 0, 1, 0, '', null, [], 0, [], [], $options ) );
		$this->assertSame( 12.5, Calculator::field_addon(
			[ 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => 'acf(unit_cost) * [qty]' ] ],
			'custom',
			[ 'price' => 0, 'qty' => 4, 'formula_variables' => $values ]
		) );
	}

	public function test_invalid_selector_and_non_numeric_acf_formula_value_fail_closed(): void {
		$GLOBALS['opf_test_acf_fields'] = [ 'product:42:valid' => INF ];
		$group = [ 'fields' => [ [ 'formula' => 'acf(valid) + acf_option(1 + system)' ] ] ];
		$values = AcfFormula::variable_values_for_group( $group, 42 );

		$this->assertSame( 0.0, $values['opf_acf_field_valid'] );
		$this->assertArrayNotHasKey( 'opf_acf_option_1', $values );
		$this->assertSame( 0.0, Calculator::evaluate_formula( 'acf(valid) + acf_option(1 + system)', 0, 1, 0, '', null, [], 0, [], [], [ 'formula_variables' => $values ] ) );
	}

	public function test_product_group_variables_feed_calculation_field_values(): void {
		$GLOBALS['opf_test_acf_fields'] = [ 'option::gold_price' => 3.25 ];
		$group = FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'total', 'type' => 'calc', 'formula' => 'acf_option(gold_price) * 2' ] ],
		] );
		// Canonical live path: AcfFormula::variable_values_for_group resolves
		// the group's acf()/acf_option() selectors into formula_variables.
		$variables = AcfFormula::variable_values_for_group( $group, 42 );
		$values = Calculator::resolve_calculation_values(
			$group['fields'],
			[],
			[ 'formula_variables' => $variables ]
		);

		$this->assertSame( 6.5, $values['total'] );
	}

}
