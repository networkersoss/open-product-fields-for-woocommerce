<?php
/**
 * WAPF dynamic date bounds: the y/m/d grammar, days → months → years
 * application order, and `[field.x]<period>` references
 * (extend/date.php wapfe_period_to_date + wapfe_get_minmax_day).
 */

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use PHPUnit\Framework\TestCase;

final class DateDynamicBoundsTest extends TestCase {
	public function test_relative_periods_apply_days_then_months_then_years_like_wapf(): void {
		$base = new \DateTimeImmutable( '2026-01-31', new \DateTimeZone( 'UTC' ) );
		// WAPF adds the day interval first, then months, then years. Month-end
		// arithmetic makes the order observable: days-first gives Mar 1, while
		// years→months→days would give Mar 4.
		$this->assertSame( '2026-03-01', FieldValue::resolve_date_boundary( '1m 1d', $base ) );
		$this->assertSame( '2026-02-07', FieldValue::resolve_date_boundary( '7d', $base ) );
		// PHP/WAPF month addition overflows Jan 31 + 1 month to Mar 3 (Feb 31).
		$this->assertSame( '2026-03-03', FieldValue::resolve_date_boundary( '1m', $base ) );
	}

	public function test_field_relative_bounds_resolve_against_the_referenced_value(): void {
		$this->assertTrue( FieldValue::is_date_boundary( '[field.start]+1d' ) );
		$this->assertSame( 'start', FieldValue::date_boundary_reference( '[field.start]+1d' ) );
		$this->assertNull( FieldValue::date_boundary_reference( '7d' ) );

		$this->assertSame( '2026-06-16', FieldValue::resolve_date_boundary( '[field.start]+1d', null, '2026-06-15' ) );
		$this->assertSame( '2026-06-15', FieldValue::resolve_date_boundary( '[field.start]', null, '2026-06-15' ) );
		$this->assertSame( '2026-09-15', FieldValue::resolve_date_boundary( '[field.start]3m', null, '2026-06-15' ) );
	}

	public function test_empty_or_non_iso_reference_yields_no_bound_like_wapf(): void {
		// WAPF's `if( $target_value )` gate leaves the bound unset when the
		// referenced field has no value; OPF matches that rather than failing.
		$this->assertNull( FieldValue::resolve_date_boundary( '[field.start]+1d', null, '' ) );
		$this->assertNull( FieldValue::resolve_date_boundary( '[field.start]+1d', null, null ) );
		$this->assertNull( FieldValue::resolve_date_boundary( '[field.start]+1d', null, 'not-a-date' ) );
	}

	public function test_field_relative_grammar_rejects_malformed_expressions(): void {
		$this->assertTrue( FieldValue::is_date_boundary( '[field.start]+1y 9m 3d' ) );
		$this->assertFalse( FieldValue::is_date_boundary( '[field.start] nope' ) );
		$this->assertFalse( FieldValue::is_date_boundary( '[field.]' ) );
		$this->assertFalse( FieldValue::is_date_boundary( '[field.start]+1x' ) );
	}

	public function test_field_relative_bounds_validate_against_the_submitted_sibling(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'end', 'type' => 'date', 'label' => 'End date', 'min_date' => '[field.start]+1d' ] );
		$this->assertSame( '[field.start]+1d', $field['min_date'] );

		$this->assertSame(
			[ '"End date" must be on or after 2026-06-16.' ],
			FieldValue::validate( $field, '2026-06-15', true, null, [ 'start' => '2026-06-15' ] )
		);
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-16', true, null, [ 'start' => '2026-06-15' ] ) );
		// A missing/empty sibling leaves the bound unset (WAPF parity).
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-15', true, null, [ 'start' => '' ] ) );
		$this->assertSame( [], FieldValue::validate( $field, '2026-06-15', true, null, [] ) );
	}

	public function test_malformed_field_relative_bound_is_rejected_by_normalization(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'end', 'type' => 'date', 'min_date' => '[field.start] nope' ] );
	}
}
