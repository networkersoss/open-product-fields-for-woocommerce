<?php
/** Percentage coupon discount scope calculations. */

use OPF\Service\CartIntegration;
use PHPUnit\Framework\TestCase;

final class CouponDiscountTest extends TestCase {
	public function test_base_only_percentage_discount_uses_base_price_for_quantity(): void {
		$this->assertSame( 10.0, CartIntegration::base_only_percent_discount( 12.0, 120.0, 100.0, 120.0, 10.0 ) );
		$this->assertSame( 30.0, CartIntegration::base_only_percent_discount( 36.0, 360.0, 100.0, 120.0, 10.0 ) );
	}

	public function test_quantity_limited_discount_uses_only_eligible_base_units(): void {
		$this->assertSame( 10.0, CartIntegration::base_only_percent_discount( 12.0, 120.0, 100.0, 120.0, 10.0 ) );
	}

	public function test_discount_is_capped_by_eligible_line_amount_and_rounded_down(): void {
		$this->assertSame( 120.0, CartIntegration::base_only_percent_discount( 150.0, 120.0, 200.0, 100.0, 100.0 ) );
		$this->assertSame( 3.33, CartIntegration::base_only_percent_discount( 3.34, 33.37, 33.37, 33.37, 10.0 ) );
	}

	public function test_invalid_or_zero_values_fail_closed_without_a_discount(): void {
		$this->assertSame( 0.0, CartIntegration::base_only_percent_discount( 10.0, 100.0, 100.0, 0.0, 10.0 ) );
		$this->assertSame( 0.0, CartIntegration::base_only_percent_discount( 10.0, 100.0, -5.0, 100.0, 10.0 ) );
	}
}
