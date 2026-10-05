<?php

namespace OPF\Tests\Unit;

use OPF\Service\QuantityPrefill;
use PHPUnit\Framework\TestCase;

final class QuantityPrefillTest extends TestCase {
	public function test_valid_quantity_prefills_product_quantity(): void {
		$args = [ 'input_value' => 1, 'min_value' => 1, 'max_value' => 20, 'step' => 1 ];
		$this->assertSame( 4, QuantityPrefill::apply( $args, '4' )['input_value'] );
	}

	public function test_decimal_quantity_respects_product_step(): void {
		$args = [ 'input_value' => 1, 'min_value' => 0.5, 'max_value' => 5, 'step' => 0.5 ];
		$this->assertSame( 2.5, QuantityPrefill::apply( $args, '2.5' )['input_value'] );
	}

	public function test_invalid_or_out_of_bounds_query_values_keep_existing_quantity(): void {
		$args = [ 'input_value' => 3, 'min_value' => 1, 'max_value' => 5, 'step' => 2 ];
		foreach ( [ null, [], '0', '-1', '6', '2', '1e2', '2,5', '2x' ] as $query ) {
			$this->assertSame( 3, QuantityPrefill::apply( $args, $query )['input_value'], 'Query value should be ignored: ' . var_export( $query, true ) );
		}
	}

	public function test_unbounded_maximum_is_supported(): void {
		$args = [ 'input_value' => 1, 'min_value' => 1, 'max_value' => -1, 'step' => 1 ];
		$this->assertSame( 5000, QuantityPrefill::apply( $args, '5000' )['input_value'] );
	}
}
