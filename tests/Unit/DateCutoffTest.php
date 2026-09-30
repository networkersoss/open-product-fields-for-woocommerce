<?php
/**
 * Site-time same-day cutoff behavior.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class DateCutoffTest extends TestCase {
	public function test_cutoff_rejects_today_only_after_the_configured_instant(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'delivery-date', 'type' => 'date', 'label' => 'Delivery date', 'cutoff_time' => '14:30' ] );

		$this->assertSame( [], FieldValue::validate( $field, '2026-06-15', true, new \DateTimeImmutable( '2026-06-15 14:29:59', new \DateTimeZone( 'UTC' ) ) ) );
		$this->assertSame( [ '"Delivery date" is no longer available for today.' ], FieldValue::validate( $field, '2026-06-15', true, new \DateTimeImmutable( '2026-06-15 14:30:00', new \DateTimeZone( 'UTC' ) ) ) );
		$this->assertSame( [ '"Delivery date" is no longer available for today.' ], FieldValue::validate( $field, '2026-06-15', true, new \DateTimeImmutable( '2026-06-15 14:30:01', new \DateTimeZone( 'UTC' ) ) ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-16', true, new \DateTimeImmutable( '2026-06-15 18:00:00', new \DateTimeZone( 'UTC' ) ) ) );
	}

	public function test_invalid_cutoff_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'delivery-date', 'type' => 'date', 'cutoff_time' => '24:00' ] );
	}
}
