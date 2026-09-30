<?php
/**
 * Submitted-value normalization and validation tests.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class FieldValueTest extends TestCase {

	public function test_email_keeps_a_nonempty_value_for_server_side_format_validation(): void {
		$field = [ 'type' => 'email', 'label' => 'Contact email', 'required' => false ];

		$this->assertSame( 'not-an-email', FieldValue::sanitize( $field, ' not-an-email ' ) );
		$this->assertSame( [ '"Contact email" must be a valid email address.' ], FieldValue::validate( $field, 'not-an-email', true ) );
	}

	public function test_email_accepts_valid_nonempty_value_and_allows_an_empty_optional_value(): void {
		$field = [ 'type' => 'email', 'label' => 'Contact email', 'required' => false ];

		$this->assertSame( 'ada@example.test', FieldValue::sanitize( $field, ' ada@example.test ' ) );
		$this->assertSame( [], FieldValue::validate( $field, 'ada@example.test', true ) );
		$this->assertNull( FieldValue::sanitize( $field, '   ' ) );
		$this->assertSame( [], FieldValue::validate( $field, null, false ) );
	}

	public function test_toggle_normalizes_checked_and_unchecked_values_and_required_means_checked(): void {
		$field = [ 'type' => 'toggle', 'label' => 'Gift wrap', 'required' => true ];

		$this->assertSame( '1', FieldValue::sanitize( $field, 'on' ) );
		$this->assertSame( '1', FieldValue::sanitize( $field, true ) );
		$this->assertSame( '0', FieldValue::sanitize( $field, '0' ) );
		$this->assertSame( '0', FieldValue::sanitize( $field, 'anything-else' ) );
		$this->assertSame( [], FieldValue::validate( $field, '1', true ) );
		$this->assertSame( [ '"Gift wrap" is a required field.' ], FieldValue::validate( $field, '0', true ) );
	}

	public function test_number_integer_mode_rejects_fractions_while_decimal_mode_allows_them(): void {
		$integer = [ 'type' => 'number', 'label' => 'Count', 'number_mode' => 'integer' ];
		$decimal = [ 'type' => 'number', 'label' => 'Weight', 'number_mode' => 'decimal', 'step' => 0.25 ];
		$this->assertSame( [], FieldValue::validate( $integer, '2', true ) );
		$this->assertSame( [ '"Count" must be a whole number.' ], FieldValue::validate( $integer, '2.5', true ) );
		$this->assertSame( [], FieldValue::validate( $decimal, '1.75', true ) );
		$this->assertSame( [ '"Weight" must use increments of 0.25.' ], FieldValue::validate( $decimal, '1.80', true ) );
	}
}
