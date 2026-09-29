<?php

namespace OPF\Tests\Unit;

use OPF\Engine\RepeaterField;
use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RepeaterFieldTest extends TestCase {
	public function test_button_repeat_keeps_instance_order_and_validates_each_row(): void {
		$field = [
			'id' => 'names',
			'label' => 'Name',
			'type' => 'text',
			'required' => true,
			'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ],
	];
		$rows = RepeaterField::sanitize( $field, [ 'Second', 'First' ], static fn( $row ) => is_scalar( $row ) ? trim( (string) $row ) : null );

		$this->assertSame( [ 'Second', 'First' ], $rows );
		$this->assertSame( [], RepeaterField::validate( $field, $rows ) );
		$this->assertSame( [ '"Name" is required in repeated row 2.' ], RepeaterField::validate( $field, [ 'Second', '' ] ) );
		$this->assertSame( [ '"Name" is a required field.' ], RepeaterField::validate( $field, [] ) );
	}

	public function test_button_repeat_validates_each_number_instance(): void {
		$field = [ 'label' => 'Copies', 'type' => 'number', 'required' => false, 'min' => 1, 'max' => 5, 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ] ];
		$this->assertSame( [ '"Copies" must be at least 1 in repeated row 2.' ], RepeaterField::validate( $field, [ '2', '0' ] ) );
	}

	public function test_button_repeat_rejects_excess_rows_and_preserves_the_detectable_overflow(): void {
		$field = [ 'label' => 'Name', 'type' => 'text', 'required' => false, 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 2 ] ];
		$rows = RepeaterField::sanitize( $field, [ 'A', 'B', 'C' ], static fn( $row ) => is_scalar( $row ) ? (string) $row : null );

		$this->assertSame( [ 'A', 'B', 'C' ], $rows );
		$this->assertSame( [ '"Name" allows at most 2 repeated rows.' ], RepeaterField::validate( $field, $rows ) );
	}

	public function test_button_repeat_normalization_rejects_unproven_pricing(): void {
		$this->expectException( \InvalidArgumentException::class );
		RepeaterField::normalize( [ 'enabled' => true, 'mode' => 'button', 'max' => 2 ], [ 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 3 ], 'choices' => [] ] );
	}

	public function test_button_repeat_rejects_unproven_quantity_semantics(): void {
		$this->expectException( \InvalidArgumentException::class );
		RepeaterField::normalize( [ 'enabled' => true ], [ 'type' => 'swatch', 'image_quantities' => true, 'pricing' => [ 'type' => 'none' ], 'choices' => [] ] );
	}

	public function test_button_repeat_rejects_nonzero_choice_weight_deltas(): void {
		$this->expectException( \InvalidArgumentException::class );
		RepeaterField::normalize( [ 'enabled' => true ], [ 'type' => 'radio', 'pricing' => [ 'type' => 'none' ], 'choices' => [ [ 'weight' => 2.0, 'pricing' => [ 'type' => 'none' ] ] ] ] );
	}

	public function test_quantity_repeat_normalizes_and_requires_one_ordered_row_per_unit(): void {
		$repeat = RepeaterField::normalize( [ 'enabled' => true, 'mode' => 'quantity' ], [ 'type' => 'text', 'pricing' => [ 'type' => 'none' ], 'choices' => [] ] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'quantity' ], $repeat );
		$field = [ 'id' => 'names', 'label' => 'Name', 'type' => 'text', 'required' => true, 'repeat' => $repeat ];
		$this->assertSame( [], RepeaterField::validate( $field, [ 'A', 'B' ], 2 ) );
		$this->assertSame( [ '"Name" needs one repeated row for each product unit.' ], RepeaterField::validate( $field, [ 'A' ], 2 ) );
		$this->assertSame( [ '"Name" needs one repeated row for each product unit.' ], RepeaterField::validate( $field, [ 'A', 'B' ], 3 ) );
	}

	public function test_quantity_repeat_request_limit_reserves_fixed_inputs_and_all_repeated_slots(): void {
		$fields = [
			[ 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'a' ], [ 'slug' => 'b' ], [ 'slug' => 'c' ] ] ],
			[ 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ],
			[ 'type' => 'textarea', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ],
		];

		$this->assertSame( 32, RepeaterField::quantity_row_limit( $fields, 100, 32 ) );
	}

	public function test_quantity_repeat_request_limit_accounts_for_button_rows_and_zero_budget(): void {
		$fields = [
			[ 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 4 ] ],
			[ 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ],
		];

		$this->assertSame( 1, RepeaterField::quantity_row_limit( $fields, 37, 32 ) );
		$this->assertSame( 0, RepeaterField::quantity_row_limit( $fields, 35, 32 ) );
	}

	public function test_quantity_repeat_request_limit_counts_toggle_hidden_and_checkbox_inputs(): void {
		$fields = [
			[ 'type' => 'toggle' ],
			[ 'type' => 'upload', 'max_files' => 3 ],
			[ 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ],
		];

		$this->assertSame( 3, RepeaterField::quantity_row_limit( $fields, 40, 32 ) );
	}

	public function test_quantity_repeat_request_limit_counts_image_quantity_and_upload_slots(): void {
		$fields = [
			[ 'type' => 'swatch', 'image_quantities' => true, 'choices' => [ [ 'slug' => 'a' ], [ 'slug' => 'b' ], [ 'slug' => 'c' ] ] ],
			[ 'type' => 'upload', 'max_files' => 2 ],
			[ 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ],
		];

		$this->assertSame( 3, RepeaterField::quantity_row_limit( $fields, 40, 32 ) );
	}

	public function test_quantity_repeat_over_limit_is_reported_and_sanitization_keeps_one_overflow_sentinel(): void {
		$field = [ 'label' => 'Name', 'type' => 'text', 'required' => true, 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ];
		$rows = RepeaterField::sanitize( $field, [ 'A', 'B', 'C', 'D' ], static fn( $row ) => (string) $row, 3, 2 );

		$this->assertSame( [ 'A', 'B', 'C' ], $rows );
		$this->assertSame( [ '"Name" supports at most 2 product units for this request.' ], RepeaterField::validate( $field, $rows, 3, 2 ) );
		$this->assertSame( [ '"Name" needs one repeated row for each product unit.' ], RepeaterField::validate( $field, [ 'A' ], 2, 2 ) );
	}

	public function test_normalized_repeat_renders_ordered_php_rows_and_keeps_plain_fields_unchanged(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'names', 'label' => 'Name', 'type' => 'text', 'required' => true, 'repeat' => [ 'enabled' => true, 'max' => 3 ] ],
			[ 'id' => 'note', 'label' => 'Note', 'type' => 'text' ],
		] ] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 3 ], $group->data['fields'][0]['repeat'] );
		ob_start();
		Renderer::render_group( '42', 'Repeat', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-opf-repeat="1" data-opf-repeat-mode="button" data-opf-repeat-max="3"', $html );
		$this->assertStringContainsString( 'name="opf[42][names][0]"', $html );
		$this->assertStringContainsString( 'required', $html );
		$this->assertStringContainsString( 'name="opf[42][note]"', $html );
		$this->assertStringNotContainsString( 'opf[note][0]', $html );
	}

	public function test_quantity_repeat_renders_quantity_mode_without_button_controls(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'names', 'label' => 'Name', 'type' => 'text', 'required' => true, 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ],
		] ] );
		ob_start();
		Renderer::render_group( '42', 'Repeat', $group, 10.0, 17 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-opf-repeat-mode="quantity" data-opf-repeat-max="17"', $html );
		$this->assertStringContainsString( 'name="opf[42][names][0]"', $html );
		$this->assertStringNotContainsString( 'data-opf-repeat-add', $html );
		$this->assertStringNotContainsString( 'data-opf-repeat-remove', $html );
	}
}
