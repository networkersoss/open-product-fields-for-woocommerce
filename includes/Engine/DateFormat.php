<?php
/** Canonical ISO date presentation helpers. */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class DateFormat {
	public const DEFAULT_FORMAT = 'mm-dd-yyyy';

	/** Normalize a date format that contains one year, month, and day token. */
	public static function normalize( $format ): string {
		if ( ! is_string( $format ) ) {
			return self::DEFAULT_FORMAT;
		}
		$format = strtolower( trim( (string) $format ) );
		if ( ! self::is_valid( $format ) ) {
			return self::DEFAULT_FORMAT;
		}
		return $format;
	}

	/** Check a WAPF-style format containing mm/m, dd/d, and yyyy/yy. */
	public static function is_valid( $format ): bool {
		if ( ! is_string( $format ) || ! preg_match( '/^(?:yyyy|yy|mm|m|dd|d)(?:[-\/., ](?:yyyy|yy|mm|m|dd|d)){2}$/i', $format ) ) {
			return false;
		}
		$tokens = preg_split( '/[-\/., ]+/', strtolower( $format ) );
		if ( ! is_array( $tokens ) || 3 !== count( $tokens ) ) {
			return false;
		}
		$has_year  = 1 === count( array_intersect( $tokens, [ 'yyyy', 'yy' ] ) );
		$has_month = 1 === count( array_intersect( $tokens, [ 'mm', 'm' ] ) );
		$has_day   = 1 === count( array_intersect( $tokens, [ 'dd', 'd' ] ) );
		return $has_year && $has_month && $has_day;
	}

	/** Format a canonical YYYY-MM-DD date without changing its stored value. */
	public static function format( $iso_date, $format = self::DEFAULT_FORMAT ): string {
		$iso_date = (string) $iso_date;
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $iso_date, $date_parts ) ) {
			return '';
		}
		$year  = (int) $date_parts[1];
		$month = (int) $date_parts[2];
		$day   = (int) $date_parts[3];
		if ( ! checkdate( $month, $day, $year ) ) {
			return '';
		}
		$format = self::normalize( $format );
		$values = [
			'yyyy' => sprintf( '%04d', $year ),
			'yy'   => sprintf( '%02d', $year % 100 ),
			'mm'   => sprintf( '%02d', $month ),
			'm'    => (string) $month,
			'dd'   => sprintf( '%02d', $day ),
			'd'    => (string) $day,
		];
		return (string) preg_replace_callback(
			'/yyyy|yy|mm|m|dd|d/i',
			static function ( $match ) use ( $values ) {
				return $values[ strtolower( $match[0] ) ];
			},
			$format
		);
	}
}
