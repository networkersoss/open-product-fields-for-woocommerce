<?php
/**
 * WAPF per-field number stepper (`display` = default|plus_min),
 * class-config.php:614 and views/frontend/fields/number.php:3.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class NumberFieldDisplayTest extends TestCase {
	public function test_display_is_normalized_and_invalid_values_fall_back_to_default(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'qty', 'type' => 'number', 'display' => 'plus_min' ] );
		$this->assertSame( 'plus_min', $field['display'] );

		$fallback = FieldGroup::normalize_field( [ 'id' => 'qty', 'type' => 'number', 'display' => 'nonsense' ] );
		$this->assertSame( 'default', $fallback['display'] );
	}

	public function test_absent_display_is_not_persisted_so_the_global_default_applies(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'qty', 'type' => 'number' ] );
		$this->assertArrayNotHasKey( 'display', $field );
	}

	public function test_display_is_ignored_for_non_number_fields(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'note', 'type' => 'text', 'display' => 'plus_min' ] );
		$this->assertArrayNotHasKey( 'display', $field );
	}
}
