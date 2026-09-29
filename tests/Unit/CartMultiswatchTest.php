<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use PHPUnit\Framework\TestCase;

final class CartMultiswatchTest extends TestCase {
	public function test_sanitizer_keeps_all_valid_multi_swatch_choices_as_array(): void {
		$field = FieldGroup::normalize_field(
			[
				'id' => 'colors', 'label' => 'Colors', 'type' => 'swatch', 'multiple' => true,
				'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ], [ 'slug' => 'off', 'label' => 'Disabled', 'disabled' => true ] ],
			]
		);
		$method = new \ReflectionMethod( CartIntegration::class, 'sanitize_value' );
		$this->assertSame( [ 'red', 'blue' ], $method->invoke( null, $field, [ 'red', 'red', 'blue', 'off', 'unknown' ] ) );
	}

	public function test_legacy_multi_swatch_order_values_remain_arrays(): void {
		$field = FieldGroup::normalize_field(
			[
				'id' => 'colors', 'label' => 'Colors', 'type' => 'swatch', 'multiple' => true,
				'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ] ],
			]
		);
		$method = new \ReflectionMethod( CartIntegration::class, 'legacy_value_for_field' );
		$this->assertSame( [ 'red', 'blue' ], $method->invoke( null, $field, [ 'values' => [ 'Red', 'blue' ] ] ) );
	}
}
