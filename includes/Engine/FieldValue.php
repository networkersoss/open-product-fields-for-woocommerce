<?php
/**
 * Canonical submitted-value behavior for fields with non-text semantics.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class FieldValue {

	/**
	 * Normalize scalar values after the transport layer has sanitized them.
	 *
	 * A malformed, non-empty email remains a value here so validation can
	 * distinguish it from an omitted optional field and return the right error.
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @param mixed               $value Submitted scalar value.
	 * @return string|null
	 */
	public static function sanitize( array $field, $value ): ?string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		if ( 'toggle' === ( $field['type'] ?? '' ) ) {
			return in_array( $value, [ true, 1, '1', 'true', 'on', 'yes' ], true ) ? '1' : '0';
		}

		$text = trim( (string) $value );
		return '' === $text ? null : $text;
	}

	/** Whether a date boundary is a canonical date or a supported relative offset. */
	public static function is_date_boundary( string $boundary ): bool {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $boundary ) ) {
			$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $boundary );
			$errors = \DateTimeImmutable::getLastErrors();
			return $date instanceof \DateTimeImmutable && ( ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $boundary;
		}

		if ( 0 === preg_match_all( '/[+-]?\d{1,5}[dwmy]/i', $boundary, $matches ) || ! preg_match( '/^(?:[+-]?\d{1,5}[dwmy])(?:\s+[+-]?\d{1,5}[dwmy])*$/i', $boundary ) ) {
			return false;
		}
		foreach ( $matches[0] as $component ) {
			if ( abs( (int) substr( $component, 0, -1 ) ) > 36500 ) {
				return false;
			}
		}
		return true;
	}

	/** Whether a disabled date is an exact date or recurring month/day. */
	public static function is_disabled_date( string $date ): bool {
		if ( preg_match( '/^\\d{2}-\\d{2}$/', $date ) ) {
			$check = \DateTimeImmutable::createFromFormat( '!Y-m-d', '2000-' . $date, new \DateTimeZone( 'UTC' ) );
			$errors = \DateTimeImmutable::getLastErrors();
			return $check instanceof \DateTimeImmutable && ( ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $check->format( 'm-d' ) === $date;
		}
		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $date ) ) {
			return self::is_date_boundary( $date );
		}
		$parts = preg_split( '/\\s+/', $date );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		[ $start, $end ] = $parts;
		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $start ) && preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $end ) ) {
			return self::is_disabled_date( $start ) && self::is_disabled_date( $end ) && $start <= $end;
		}
		if ( preg_match( '/^\\d{2}-\\d{2}$/', $start ) && preg_match( '/^\\d{2}-\\d{2}$/', $end ) ) {
			return self::is_disabled_date( $start ) && self::is_disabled_date( $end );
		}
		return false;
	}

	/** Whether a date matches an exact, recurring, or inclusive date-range blackout. */
	public static function matches_disabled_date( string $date, array $disabled_dates ): bool {
		if ( ! self::is_disabled_date( $date ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $date ) ) {
			return false;
		}
		$month_day = substr( $date, 5 );
		foreach ( $disabled_dates as $rule ) {
			if ( ! is_string( $rule ) ) {
				continue;
			}
			$rule = trim( $rule );
			if ( $date === $rule || $month_day === $rule ) {
				return true;
			}
			$parts = preg_split( '/\\s+/', $rule );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			[ $start, $end ] = $parts;
			if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $start ) && preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $end ) && $date >= $start && $date <= $end ) {
				return true;
			}
			if ( preg_match( '/^\\d{2}-\\d{2}$/', $start ) && preg_match( '/^\\d{2}-\\d{2}$/', $end ) && ( $start <= $end ? $month_day >= $start && $month_day <= $end : $month_day >= $start || $month_day <= $end ) ) {
				return true;
			}
		}
		return false;
	}

	/** Resolve a static date or relative offset such as `7d` in the site timezone. */
	public static function resolve_date_boundary( string $boundary, ?\DateTimeImmutable $today = null ): ?string {
		if ( ! self::is_date_boundary( $boundary ) ) {
			return null;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $boundary ) ) {
			return $boundary;
		}

		if ( null === $today ) {
			$today = function_exists( 'current_datetime' )
				? current_datetime()
				: new \DateTimeImmutable( 'today', new \DateTimeZone( 'UTC' ) );
		}

		$units    = [ 'd' => 'days', 'w' => 'weeks', 'm' => 'months', 'y' => 'years' ];
		$resolved = $today->setTime( 0, 0 );
		preg_match_all( '/([+-]?\d{1,5})([dwmy])/i', $boundary, $components, PREG_SET_ORDER );
		foreach ( $components as $component ) {
			$amount = (int) $component[1];
			$unit   = strtolower( $component[2] );
			$offset = ( $amount >= 0 ? '+' : '' ) . $amount . ' ' . $units[ $unit ];
			try {
				$resolved = $resolved->modify( $offset );
			} catch ( \Exception $exception ) {
				return null;
			}
			if ( ! $resolved instanceof \DateTimeImmutable ) {
				return null;
			}
		}

		return $resolved instanceof \DateTimeImmutable ? $resolved->format( 'Y-m-d' ) : null;
	}

	/**
	 * Return server-side validation messages for a submitted field value.
	 *
	 * @param array<string,mixed> $field    Field definition.
	 * @param string|null         $value    Sanitized value.
	 * @param bool                $provided Whether this input appeared in the payload.
	 * @return string[]
	 */
	public static function validate( array $field, ?string $value, bool $provided, ?\DateTimeImmutable $today = null ): array {
		$label = (string) ( $field['label'] ?? '' );
		$type  = (string) ( $field['type'] ?? '' );

		if ( 'toggle' === $type ) {
			if ( ! empty( $field['required'] ) && ( ! $provided || '1' !== $value ) ) {
				return [ sprintf( '"%s" is a required field.', $label ) ];
			}
			return [];
		}

		if ( ! $provided || null === $value ) {
			return ! empty( $field['required'] ) ? [ sprintf( '"%s" is a required field.', $label ) ] : [];
		}

		if ( 'email' === $type && false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return [ sprintf( '"%s" must be a valid email address.', $label ) ];
		}

		if ( 'number' === $type ) {
			if ( ! is_numeric( $value ) ) {
				return [ sprintf( '"%s" must be a number.', $label ) ];
			}
			$number = (float) $value;
			if ( 'integer' === ( $field['number_mode'] ?? null ) && abs( $number - round( $number ) ) > 0.0000001 ) {
				return [ sprintf( '"%s" must be a whole number.', $label ) ];
			}
			if ( isset( $field['min'] ) && $number < (float) $field['min'] ) {
				return [ sprintf( '"%s" must be at least %s.', $label, (string) $field['min'] ) ];
			}
			if ( isset( $field['max'] ) && $number > (float) $field['max'] ) {
				return [ sprintf( '"%s" must be at most %s.', $label, (string) $field['max'] ) ];
			}
			$step_ratio = isset( $field['step'] ) ? ( $number - (float) ( $field['min'] ?? 0 ) ) / (float) $field['step'] : 0.0;
			if ( isset( $field['step'] ) && abs( $step_ratio - round( $step_ratio ) ) > 0.0000001 ) {
				return [ sprintf( '"%s" must use increments of %s.', $label, (string) $field['step'] ) ];
			}
		}

		if ( 'date' === $type ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ) {
				return [ sprintf( '"%s" must be a valid date.', $label ) ];
			}
			$min_date = isset( $field['min_date'] ) ? self::resolve_date_boundary( (string) $field['min_date'], $today ) : null;
			$max_date = isset( $field['max_date'] ) ? self::resolve_date_boundary( (string) $field['max_date'], $today ) : null;
			$current = $today ?? ( function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
			$site_today = $current->format( 'Y-m-d' );
			if ( array_key_exists( 'allow_past', $field ) && false === $field['allow_past'] && $value < $site_today ) {
				return [ sprintf( '"%s" cannot be in the past.', $label ) ];
			}
			if ( array_key_exists( 'allow_future', $field ) && false === $field['allow_future'] && $value > $site_today ) {
				return [ sprintf( '"%s" cannot be in the future.', $label ) ];
			}
			if ( null !== $min_date && $value < $min_date ) {
				return [ sprintf( '"%s" must be on or after %s.', $label, $min_date ) ];
			}
			if ( null !== $max_date && $value > $max_date ) {
				return [ sprintf( '"%s" must be on or before %s.', $label, $max_date ) ];
			}
			if ( isset( $field['cutoff_time'] ) ) {
				$current = $today ?? ( function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
				if ( $value === $current->format( 'Y-m-d' ) && $current->format( 'H:i' ) >= (string) $field['cutoff_time'] ) {
					return [ sprintf( '"%s" is no longer available for today.', $label ) ];
				}
			}
			$weekday = (int) $date->format( 'w' );
			if ( in_array( $weekday, $field['disabled_weekdays'] ?? [], true ) ) {
				return [ sprintf( '"%s" is unavailable on this weekday.', $label ) ];
			}
			$month_day = $date->format( 'm-d' );
			if ( self::matches_disabled_date( $value, $field['disabled_dates'] ?? [] ) ) {
				return [ sprintf( '"%s" contains a disallowed date.', $label ) ];
			}
		}

		$value_length = self::text_length( $value );
		if ( isset( $field['minlength'] ) && $value_length < (int) $field['minlength'] ) {
			return [ sprintf( '"%s" must be at least %d characters.', $label, (int) $field['minlength'] ) ];
		}
		if ( isset( $field['maxlength'] ) && $value_length > (int) $field['maxlength'] ) {
			return [ sprintf( '"%s" must be at most %d characters.', $label, (int) $field['maxlength'] ) ];
		}
		if ( isset( $field['pattern'] ) && 1 !== self::matches_pattern( (string) $field['pattern'], $value ) ) {
			return [ sprintf( '"%s" has an invalid format.', $label ) ];
		}

		return [];
	}

	/** Count Unicode characters rather than UTF-8 bytes. */
	private static function text_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}
		if ( function_exists( 'wp_strlen' ) ) {
			return wp_strlen( $value );
		}
		$count = preg_match_all( '/./us', $value, $matches );
		return false === $count ? strlen( $value ) : $count;
	}

	/** Match a complete submitted string using the authored PCRE pattern. */
	private static function matches_pattern( string $pattern, string $value ): int {
		$delimiter = '~';
		foreach ( [ '~', '#', '%', '!', '@', ';', '`', '/' ] as $candidate ) {
			if ( false === strpos( $pattern, $candidate ) ) {
				$delimiter = $candidate;
				break;
			}
		}
		$expression = $delimiter . '\\A(?:' . $pattern . ')\\z' . $delimiter . 'u';
		return @preg_match( $expression, $value );
	}

	/** Validate a checkbox selection array after published-choice filtering. */
	public static function validate_choices( array $field, $value, bool $provided ): array {
		$label = (string) ( $field['label'] ?? '' );
		$selected = is_array( $value ) ? array_values( array_filter( $value, 'is_scalar' ) ) : [];
		$count = count( $selected );
		if ( ! $provided || 0 === $count ) {
			if ( ! empty( $field['required'] ) && ( ! isset( $field['min_selections'] ) || 0 === (int) $field['min_selections'] ) ) {
				return [ sprintf( '"%s" is a required field.', $label ) ];
			}
			if ( isset( $field['min_selections'] ) && $field['min_selections'] > 0 ) {
				return [ sprintf( '"%s" requires at least %d selection(s).', $label, (int) $field['min_selections'] ) ];
			}
			return [];
		}
		if ( isset( $field['min_selections'] ) && $count < (int) $field['min_selections'] ) {
			return [ sprintf( '"%s" requires at least %d selection(s).', $label, (int) $field['min_selections'] ) ];
		}
		if ( isset( $field['max_selections'] ) && $count > (int) $field['max_selections'] ) {
			return [ sprintf( '"%s" allows at most %d selection(s).', $label, (int) $field['max_selections'] ) ];
		}
		return [];
	}

	/** Validate quantity counts keyed by image-choice slug. */
	public static function validate_image_quantities( array $field, $value, bool $provided ): array {
		$label = (string) ( $field['label'] ?? '' );
		$quantities = is_array( $value ) ? $value : [];
		$valid_choices = [];
		foreach ( $field['choices'] ?? [] as $choice ) {
			if ( empty( $choice['disabled'] ) && isset( $choice['slug'] ) ) {
				$valid_choices[ (string) $choice['slug'] ] = true;
			}
		}
		$selected = 0;
		$total_quantity = 0;
		$minimum = (int) ( $field['min'] ?? 1 );
		$maximum = isset( $field['max'] ) ? (int) $field['max'] : PHP_INT_MAX;
		$step = isset( $field['step'] ) ? (int) $field['step'] : 1;
		foreach ( $quantities as $slug => $raw_quantity ) {
			$slug = is_string( $slug ) || is_int( $slug ) ? (string) $slug : '';
			if ( ! isset( $valid_choices[ $slug ] ) || ! is_scalar( $raw_quantity ) || ! preg_match( '/^\d+$/', (string) $raw_quantity ) ) {
				return [ sprintf( '"%s" contains an invalid image quantity.', $label ) ];
			}
			$quantity = (int) $raw_quantity;
			if ( 0 === $quantity ) {
				continue;
			}
			if ( $quantity < $minimum || $quantity > $maximum || 0 !== ( $quantity - $minimum ) % max( 1, $step ) ) {
				return [ sprintf( '"%s" has an image quantity outside its allowed range.', $label ) ];
			}
			++$selected;
			$total_quantity += $quantity;
		}
		if ( isset( $field['min_total_quantity'] ) && $total_quantity < (int) $field['min_total_quantity'] ) {
			return [ sprintf( '"%s" requires a total quantity of at least %d.', $label, (int) $field['min_total_quantity'] ) ];
		}
		if ( ! $provided || 0 === $selected ) {
			if ( ! empty( $field['required'] ) && ( ! isset( $field['min_selections'] ) || 0 === (int) $field['min_selections'] ) ) {
				return [ sprintf( '"%s" is a required field.', $label ) ];
			}
			if ( isset( $field['min_selections'] ) && (int) $field['min_selections'] > 0 ) {
				return [ sprintf( '"%s" requires at least %d selection(s).', $label, (int) $field['min_selections'] ) ];
			}
			return [];
		}
		if ( isset( $field['min_selections'] ) && $selected < (int) $field['min_selections'] ) {
			return [ sprintf( '"%s" requires at least %d selection(s).', $label, (int) $field['min_selections'] ) ];
		}
		if ( isset( $field['max_selections'] ) && $selected > (int) $field['max_selections'] ) {
			return [ sprintf( '"%s" allows at most %d selection(s).', $label, (int) $field['max_selections'] ) ];
		}
		if ( isset( $field['max_total_quantity'] ) && $total_quantity > (int) $field['max_total_quantity'] ) {
			return [ sprintf( '"%s" allows a total quantity of at most %d.', $label, (int) $field['max_total_quantity'] ) ];
		}
		return [];
	}
}
