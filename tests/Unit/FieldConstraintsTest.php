<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class FieldConstraintsTest extends TestCase {
	public function test_number_constraints_normalize_and_validate(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'size', 'type' => 'number', 'label' => 'Size', 'min' => 1, 'max' => 9, 'step' => 2 ] );
		$this->assertSame( 1.0, $field['min'] );
		$this->assertSame( [ '"Size" must use increments of 2.' ], FieldValue::validate( $field, '4', true ) );
		$this->assertSame( [], FieldValue::validate( $field, '5', true ) );
		$this->assertSame( [ '"Size" must be at most 9.' ], FieldValue::validate( $field, '11', true ) );
	}

	public function test_text_constraints_validate_length_and_pattern(): void {
		$field = [ 'type' => 'text', 'label' => 'Code', 'minlength' => 3, 'maxlength' => 5, 'pattern' => '^[A-Z]+$' ];
		$this->assertSame( [ '"Code" must be at least 3 characters.' ], FieldValue::validate( $field, 'ab', true ) );
		$this->assertSame( [ '"Code" has an invalid format.' ], FieldValue::validate( $field, 'abcd1', true ) );
		$this->assertSame( [], FieldValue::validate( $field, 'ABCD', true ) );
	}

	public function test_text_pattern_must_match_the_complete_value_and_support_delimiters(): void {
		$field = [ 'type' => 'text', 'label' => 'Code', 'pattern' => '[A-Z]+' ];
		$this->assertSame( [ '"Code" has an invalid format.' ], FieldValue::validate( $field, 'xABC', true ) );
		$this->assertSame( [], FieldValue::validate( $field, 'ABC', true ) );

		$delimiter_field = [ 'type' => 'text', 'label' => 'Marker', 'pattern' => 'a~b' ];
		$this->assertSame( [], FieldValue::validate( $delimiter_field, 'a~b', true ) );
	}

	public function test_text_length_limits_count_unicode_characters(): void {
		$field = [ 'type' => 'text', 'label' => 'Name', 'minlength' => 2, 'maxlength' => 2 ];
		$this->assertSame( [], FieldValue::validate( $field, 'é猫', true ) );
		$this->assertSame( [ '"Name" must be at most 2 characters.' ], FieldValue::validate( $field, 'é猫A', true ) );
	}

	public function test_contradictory_bounds_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'size', 'type' => 'number', 'min' => 9, 'max' => 1 ] );
	}

	public function test_contradictory_checkbox_limits_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'extras', 'type' => 'checkbox', 'min_selections' => 2, 'max_selections' => 1, 'choices' => [ [ 'label' => 'A' ], [ 'label' => 'B' ] ] ] );
	}
}
