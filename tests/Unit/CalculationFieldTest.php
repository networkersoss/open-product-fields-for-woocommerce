<?php

namespace OPF\Tests\Unit;

use OPF\Engine\Calculator;
use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class CalculationFieldTest extends TestCase {
	public function test_calculation_field_normalizes_as_non_submittable_information(): void {
		$group = new FieldGroup(
			[
				'fields' => [
					[ 'id' => 'area', 'label' => 'Area', 'type' => 'calc', 'required' => true, 'formula' => '[field.width] * [field.height]', 'result_text' => '{result} sq ft' ],
					[ 'id' => 'discount', 'type' => 'calc', 'calc_type' => 'cost', 'formula' => '0 - [field.width]' ],
					[ 'id' => 'width', 'type' => 'number', 'default' => '4' ],
					[ 'id' => 'height', 'type' => 'number', 'default' => '3' ],
				],
			]
		);
		$field = $group->data['fields'][0];

		$this->assertSame( 'calc', $field['type'] );
		$this->assertFalse( $field['required'] );
		$this->assertSame( '[field.width] * [field.height]', $field['formula'] );
		$this->assertSame( '{result} sq ft', $field['result_text'] );
		$this->assertSame( 'default', $field['calc_type'] );
		$this->assertSame( 'cost', $group->data['fields'][1]['calc_type'] );
		$this->assertSame( 'none', $field['pricing']['type'] );
	}

	public function test_unrecognized_calculation_mode_falls_back_to_default(): void {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'calc', 'type' => 'calc', 'calc_type' => 'formula_price', 'formula' => '[price] + 5' ] ] ] );
		$this->assertSame( 'default', $group->data['fields'][0]['calc_type'] );
	}

	public function test_cart_values_retain_empty_groups_with_price_calculations(): void {
		$groups = [
			[ 'id' => 42, 'group' => new FieldGroup( [ 'fields' => [ [ 'id' => 'surcharge', 'type' => 'calc', 'calc_type' => 'cost', 'formula' => '[price] * 0.1' ] ] ] ) ],
			[ 'id' => 43, 'group' => new FieldGroup( [ 'fields' => [ [ 'id' => 'display', 'type' => 'calc', 'formula' => '[price]' ] ] ] ) ],
		];
		$method = new \ReflectionMethod( CartIntegration::class, 'retain_price_calculation_groups' );
		$this->assertSame( [ '42' => [] ], $method->invoke( null, [], $groups ) );
	}

	public function test_calculation_formula_reads_only_numeric_field_values(): void {
		$this->assertSame( 12.0, Calculator::evaluate_formula( '[field.width] * [field.height]', 50.0, 2, 0.0, '', null, [ 'width' => '4', 'height' => '3' ] ) );
		$this->assertSame( 5.0, Calculator::evaluate_formula( '[field.width] + [field.note]', 50.0, 2, 0.0, '', null, [ 'width' => '5', 'note' => '<script>' ] ) );
		$this->assertSame( 110.0, Calculator::evaluate_formula( '[price] + [qty] * 5', 100.0, 2, 0.0, '', null, [] ) );
		$this->assertSame( 125.0, Calculator::evaluate_formula( '[price] + [options_total]', 100.0, 1, 25.0, '', null, [] ) );
	}

	public function test_visible_calculation_results_are_numeric_dependencies_for_later_fields(): void {
		$fields = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'area', 'type' => 'calc', 'formula' => '[field.width] * 2' ],
				[ 'id' => 'width', 'type' => 'number' ],
				[ 'id' => 'large_only', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'yes', 'label' => 'Yes' ] ], 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'area', 'operator' => 'greater', 'value' => '10' ] ] ] ] ],
			],
		] )['fields'];

		$large = Calculator::resolve_calculation_values( $fields, [ 'width' => '6', 'large_only' => [ 'yes' ] ], [ 'price' => 20.0, 'qty' => 1 ] );
		$this->assertSame( '6', $large['width'] );
		$this->assertSame( 12.0, $large['area'] );
		$this->assertSame( [ 'yes' ], $large['large_only'] );

		$small = Calculator::resolve_calculation_values( $fields, [ 'width' => '4', 'large_only' => [ 'yes' ] ], [ 'price' => 20.0, 'qty' => 1 ] );
		$this->assertSame( 8.0, $small['area'] );
		$this->assertArrayNotHasKey( 'large_only', $small );
	}

	public function test_calculation_dependency_cycles_are_unavailable_and_fail_closed(): void {
		$fields = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'first', 'type' => 'calc', 'formula' => '[field.second] + 1' ],
				[ 'id' => 'second', 'type' => 'calc', 'formula' => '[field.first] + 1' ],
				[ 'id' => 'dependent', 'type' => 'text', 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'first', 'operator' => 'not_empty', 'value' => '' ] ] ] ] ],
			],
		] )['fields'];

		$resolved = Calculator::resolve_calculation_values( $fields, [ 'dependent' => 'forged' ] );
		$this->assertArrayNotHasKey( 'first', $resolved );
		$this->assertArrayNotHasKey( 'second', $resolved );
		$this->assertArrayNotHasKey( 'dependent', $resolved );
	}

	public function test_calculation_resolver_uses_forward_lookup_and_formula_context(): void {
		$fields = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'result', 'type' => 'calc', 'formula' => '[price.option] + [addons] + [options_total] + [price] / 100 + [qty] + files(art) + lookuptable(rates; size)' ],
				[ 'id' => 'option', 'type' => 'select', 'choices' => [ [ 'slug' => 'yes', 'label' => 'Yes' ] ] ],
				[ 'id' => 'size', 'type' => 'number' ],
				[ 'id' => 'art', 'type' => 'text' ],
			],
		] )['fields'];
		$resolved = Calculator::resolve_calculation_values(
			$fields,
			[ 'option' => 'yes', 'size' => '5', 'art' => 'one,two' ],
			[
				'price' => 100.0,
				'qty' => 1,
				'addons' => 3.0,
				'lookup_tables' => [ 'rates' => [ '5' => 10 ] ],
				'field_prices' => [ 'option' => 4.0 ],
			]
		);
		$this->assertSame( 24.0, $resolved['result'] );
	}

	public function test_hidden_calculation_is_absent_from_later_conditions_and_pricing_values(): void {
		$fields = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'mode', 'type' => 'select', 'choices' => [ [ 'slug' => 'show', 'label' => 'Show' ], [ 'slug' => 'hide', 'label' => 'Hide' ] ] ],
			[ 'id' => 'area', 'type' => 'calc', 'formula' => '20', 'conditionals' => [ [ 'action' => 'hide', 'logic' => 'all', 'rules' => [ [ 'field' => 'mode', 'operator' => 'is', 'value' => 'hide' ] ] ] ] ],
			[ 'id' => 'large_only', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'yes', 'label' => 'Yes', 'pricing' => [ 'type' => 'fixed', 'amount' => 7 ] ] ], 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'area', 'operator' => 'greater', 'value' => '10' ] ] ] ] ],
			],
		] )['fields'];

		$hidden = Calculator::resolve_calculation_values( $fields, [ 'mode' => 'hide', 'large_only' => [ 'yes' ] ], [ 'price' => 20.0, 'qty' => 1 ] );
		$this->assertSame( 'hide', $hidden['mode'] );
		$this->assertArrayNotHasKey( 'area', $hidden );
		$this->assertArrayNotHasKey( 'large_only', $hidden );
		$this->assertSame( 0.0, Calculator::field_addon( $fields[2], $hidden['large_only'] ?? null, [ 'price' => 20.0, 'qty' => 1 ] ) );
	}

	public function test_calculation_field_renders_client_computed_shell_with_escaped_config(): void {
		$group = new FieldGroup(
			[
				'fields' => [
					[ 'id' => 'width', 'type' => 'number', 'default' => '4' ],
					[ 'id' => 'height', 'type' => 'number', 'default' => '3' ],
					[ 'id' => 'area', 'label' => 'Area', 'type' => 'calc', 'formula' => '[field.width] * [field.height]', 'result_text' => '{result} sq ft<script>' ],
				],
			]
		);
		ob_start();
		Renderer::render_group( '17', 'Calculator', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-opf-calc="1"', $html );
		$this->assertStringContainsString( 'data-opf-calc-type="default"', $html );
		$this->assertStringContainsString( 'data-opf-calc-text="{result} sq ft&lt;script&gt;"', $html );
		// The hidden input carries the client-computed raw result — that is
		// the calc contract, not a user-editable control.
		$this->assertStringContainsString( 'name="opf[17][area]"', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}
}
