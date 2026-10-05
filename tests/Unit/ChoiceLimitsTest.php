<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class ChoiceLimitsTest extends TestCase {
	public function test_checkbox_limits_are_normalized_and_enforced(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'extras', 'type' => 'checkbox', 'label' => 'Extras', 'min_selections' => 1, 'max_selections' => 2, 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ], [ 'slug' => 'b', 'label' => 'B' ], [ 'slug' => 'c', 'label' => 'C' ] ] ] );
		$this->assertSame( 1, $field['min_selections'] );
		$this->assertSame( 2, $field['max_selections'] );
		$this->assertSame( [ '"Extras" requires at least 1 selection(s).' ], FieldValue::validate_choices( $field, [], true ) );
		$this->assertSame( [ '"Extras" allows at most 2 selection(s).' ], FieldValue::validate_choices( $field, [ 'a', 'b', 'c' ], true ) );
		$this->assertSame( [], FieldValue::validate_choices( $field, [ 'a', 'b' ], true ) );
	}

	public function test_multiple_swatch_limits_are_normalized_and_enforced(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'colors', 'type' => 'swatch', 'multiple' => true, 'min_selections' => 1, 'max_selections' => 2, 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ], [ 'slug' => 'green', 'label' => 'Green' ] ] ] );
		$this->assertTrue( $field['multiple'] );
		$this->assertSame( 1, $field['min_selections'] );
		$this->assertSame( 2, $field['max_selections'] );
		$this->assertSame( [ '"" requires at least 1 selection(s).' ], FieldValue::validate_choices( $field, [], true ) );
		$this->assertSame( [ '"" allows at most 2 selection(s).' ], FieldValue::validate_choices( $field, [ 'red', 'blue', 'green' ], true ) );
		$this->assertSame( [], FieldValue::validate_choices( $field, [ 'red', 'blue' ], true ) );
	}
}
