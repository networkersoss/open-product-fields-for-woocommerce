<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class DateFieldTest extends TestCase {
	public function test_date_constraints_are_normalized_and_validated(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'event-date', 'type' => 'date', 'label' => 'Event date', 'min_date' => '2026-01-01', 'max_date' => '2026-12-31' ] );
		$this->assertSame( 'date', $field['type'] );
		$this->assertSame( [ '"Event date" must be on or after 2026-01-01.' ], FieldValue::validate( $field, '2025-12-31', true ) );
		$this->assertSame( [ '"Event date" must be a valid date.' ], FieldValue::validate( $field, '2026-02-31', true ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-15', true ) );
	}

	public function test_relative_date_bounds_resolve_from_a_fixed_local_date(): void {
		$today = new \DateTimeImmutable( '2026-06-15', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( '2026-06-22', FieldValue::resolve_date_boundary( '7d', $today ) );
		$this->assertSame( '2026-05-15', FieldValue::resolve_date_boundary( '-1m', $today ) );
		$this->assertSame( '2027-06-15', FieldValue::resolve_date_boundary( '+1y', $today ) );
		$this->assertNull( FieldValue::resolve_date_boundary( 'next Tuesday', $today ) );
	}

	public function test_relative_date_constraints_validate_against_their_resolved_dates(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'delivery-date', 'type' => 'date', 'label' => 'Delivery date', 'min_date' => '7d', 'max_date' => '2m' ] );
		$this->assertSame( '7d', $field['min_date'] );
		$this->assertSame( '2m', $field['max_date'] );
		$today = new \DateTimeImmutable( '2026-06-15', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-22', true, $today ) );
		$this->assertSame( [ '"Delivery date" must be on or after 2026-06-22.' ], FieldValue::validate( $field, '2026-06-21', true, $today ) );
		$this->assertSame( [ '"Delivery date" must be on or before 2026-08-15.' ], FieldValue::validate( $field, '2026-08-16', true, $today ) );
	}

	public function test_invalid_relative_date_bound_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'delivery-date', 'type' => 'date', 'min_date' => 'tomorrow' ] );
	}

	public function test_disabled_weekdays_and_exact_or_recurring_dates_are_enforced(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'delivery-date',
			'type' => 'date',
			'label' => 'Delivery date',
			'disabled_weekdays' => [ 0, '6' ],
			'disabled_dates' => [ '2026-12-25', '02-29' ],
		] );
		$this->assertSame( [ 0, 6 ], $field['disabled_weekdays'] );
		$this->assertSame( [ '2026-12-25', '02-29' ], $field['disabled_dates'] );
		$this->assertSame( [ '"Delivery date" is unavailable on this weekday.' ], FieldValue::validate( $field, '2026-06-21', true ) );
		$this->assertSame( [ '"Delivery date" contains a disallowed date.' ], FieldValue::validate( $field, '2026-12-25', true ) );
		$this->assertSame( [ '"Delivery date" contains a disallowed date.' ], FieldValue::validate( $field, '2028-02-29', true ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-15', true ) );
	}

	public function test_disabled_date_configuration_rejects_invalid_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'date', 'type' => 'date', 'disabled_dates' => [ '2026-02-30' ] ] );
	}

	public function test_same_day_cutoff_is_normalized_and_enforced_at_the_configured_site_time(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'delivery-date', 'type' => 'date', 'label' => 'Delivery date', 'cutoff_time' => '14:30' ] );
		$this->assertSame( '14:30', $field['cutoff_time'] );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-15', true, new \DateTimeImmutable( '2026-06-15 14:29:59', new \DateTimeZone( 'UTC' ) ) ) );
		$this->assertSame( [ '"Delivery date" is no longer available for today.' ], FieldValue::validate( $field, '2026-06-15', true, new \DateTimeImmutable( '2026-06-15 14:30:00', new \DateTimeZone( 'UTC' ) ) ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-16', true, new \DateTimeImmutable( '2026-06-15 16:00:00', new \DateTimeZone( 'UTC' ) ) ) );
	}

	public function test_past_and_future_selection_policies_validate_submitted_values_and_default_to_allowed(): void {
		$today = new \DateTimeImmutable( '2026-06-15 10:00:00', new \DateTimeZone( 'UTC' ) );
		$dates = [ 'past' => '2026-06-14', 'today' => '2026-06-15', 'future' => '2026-06-16' ];
		foreach ( [ [ true, true ], [ true, false ], [ false, true ], [ false, false ] ] as [ $allow_past, $allow_future ] ) {
			$field = FieldGroup::normalize_field( [
				'id'          => 'event-date',
				'type'        => 'date',
				'label'       => 'Event date',
				'allow_past'  => $allow_past,
				'allow_future' => $allow_future,
			] );
			$this->assertSame( $allow_past, $field['allow_past'] );
			$this->assertSame( $allow_future, $field['allow_future'] );
			foreach ( $dates as $kind => $date ) {
				$blocked = ( 'past' === $kind && ! $allow_past ) || ( 'future' === $kind && ! $allow_future );
				$this->assertSame( $blocked ? [ sprintf( '"Event date" cannot be in the %s.', $kind ) ] : [], FieldValue::validate( $field, $date, true, $today ), sprintf( '%s date with allow_past=%s and allow_future=%s', $kind, var_export( $allow_past, true ), var_export( $allow_future, true ) ) );
			}
		}
		$default = FieldGroup::normalize_field( [ 'id' => 'event-date', 'type' => 'date', 'label' => 'Event date' ] );
		$this->assertSame( true, $default['allow_past'] );
		$this->assertSame( true, $default['allow_future'] );
	}

	public function test_past_future_policy_combines_with_bounds_and_cutoff(): void {
		$field = FieldGroup::normalize_field( [
			'id'           => 'event-date',
			'type'         => 'date',
			'label'        => 'Event date',
			'allow_past'   => false,
			'allow_future' => false,
			'min_date'     => '-2d',
			'max_date'     => '2d',
			'cutoff_time'  => '10:00',
		] );
		$before_cutoff = new \DateTimeImmutable( '2026-06-15 09:59:00', new \DateTimeZone( 'UTC' ) );
		$at_cutoff = new \DateTimeImmutable( '2026-06-15 10:00:00', new \DateTimeZone( 'UTC' ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-15', true, $before_cutoff ) );
		$this->assertSame( [ '"Event date" cannot be in the past.' ], FieldValue::validate( $field, '2026-06-14', true, $before_cutoff ) );
		$this->assertSame( [ '"Event date" cannot be in the future.' ], FieldValue::validate( $field, '2026-06-16', true, $before_cutoff ) );
		$this->assertSame( [ '"Event date" is no longer available for today.' ], FieldValue::validate( $field, '2026-06-15', true, $at_cutoff ) );
	}

	public function test_invalid_same_day_cutoff_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'date', 'type' => 'date', 'cutoff_time' => '25:00' ] );
	}
}
