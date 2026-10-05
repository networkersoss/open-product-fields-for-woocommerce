<?php
/**
 * WAPF `disable_today` selection policy (class-config.php:742,
 * class-cart.php validate_date_field).
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class DateDisableTodayTest extends TestCase {
	public function test_disable_today_is_normalized_and_rejects_the_site_date(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'booking', 'type' => 'date', 'label' => 'Booking date', 'disable_today' => true ] );
		$this->assertTrue( $field['disable_today'] );

		$today = new \DateTimeImmutable( '2026-06-15 09:00:00', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( [ '"Booking date" cannot be today.' ], FieldValue::validate( $field, '2026-06-15', true, $today ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-16', true, $today ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-14', true, $today ) );
	}

	public function test_disable_today_defaults_off_and_rejects_non_boolean_values(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'delivery-date', 'type' => 'date' ] );
		$this->assertArrayNotHasKey( 'disable_today', $field );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-15', true, new \DateTimeImmutable( '2026-06-15 09:00:00', new \DateTimeZone( 'UTC' ) ) ) );

		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'delivery-date', 'type' => 'date', 'disable_today' => 'maybe' ] );
	}
}
