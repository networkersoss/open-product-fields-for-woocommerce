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
		// Keep malformed scalar-input payloads distinct from optional empty values.
		// The NUL marker is rejected before cart attachment.
		if ( in_array( $field['type'] ?? '', [ 'url', 'email' ], true ) && ! is_scalar( $value ) && null !== $value ) {
			return "\0";
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}

		if ( 'toggle' === ( $field['type'] ?? '' ) ) {
			return in_array( $value, [ true, 1, '1', 'true', 'on', 'yes' ], true ) ? '1' : '0';
		}

		// Boundary whitespace must not erase malformed NUL bytes.
		$text = in_array( $field['type'] ?? '', [ 'url', 'email' ], true )
			? trim( (string) $value, " \t\r\n\f" )
			: trim( (string) $value );
		return '' === $text ? null : $text;
	}

	/**
	 * Reject submitted values that select choices disabled by the editor or
	 * break the field's configured choice-count limits.
	 *
	 * WAPF enforces `min_choices`/`max_choices` only once a value exists —
	 * an absent input is the caller's required-check concern, so an
	 * unprovided field returns no errors here.
	 *
	 * @param array<string,mixed> $field    Field definition.
	 * @param mixed               $value    Submitted value(s).
	 * @param bool                $provided Whether this input appeared in the payload.
	 * @return string[]
	 */
	public static function validate_choices( array $field, $value, bool $provided = true ): array {
		if ( ! in_array( $field['type'] ?? '', [ 'select', 'radio', 'checkbox', 'swatch' ], true ) || ! $provided ) {
			return [];
		}
		$label    = (string) ( $field['label'] ?? '' );
		$selected = array_map( 'strval', is_array( $value ) ? $value : ( null === $value ? [] : [ $value ] ) );
		$errors   = [];
		$count    = count( $selected );
		if ( 0 === $count ) {
			// A provided-but-empty selection binds the configured minimum
			// (WAPF validate_multiple_choice_field); an explicit minimum also
			// stands in for the required check on multi-choice fields.
			if ( isset( $field['min_choices'] ) && (int) $field['min_choices'] > 0 ) {
				/* translators: 1: field label, 2: minimum selection count. */
				$errors[] = sprintf( __( '"%1$s" requires at least %2$d selection(s).', 'open-product-fields-for-woocommerce' ), $label, (int) $field['min_choices'] );
			} elseif ( ! empty( $field['required'] ) ) {
				/* translators: %s: field label. */
				$errors[] = sprintf( __( '"%s" is a required field.', 'open-product-fields-for-woocommerce' ), $label );
			}
			return $errors;
		}
		foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
			if ( ! empty( $choice['disabled'] ) && in_array( (string) ( $choice['slug'] ?? '' ), $selected, true ) ) {
				/* translators: 1: field label, 2: unavailable choice label. */
				$errors[] = sprintf( __( '"%1$s" includes unavailable choice "%2$s".', 'open-product-fields-for-woocommerce' ), $label, (string) ( $choice['label'] ?? '' ) );
			}
		}
		if ( isset( $field['min_choices'] ) && $count < (int) $field['min_choices'] ) {
			/* translators: 1: field label, 2: minimum selection count. */
			$errors[] = sprintf( __( '"%1$s" requires at least %2$d selection(s).', 'open-product-fields-for-woocommerce' ), $label, (int) $field['min_choices'] );
		}
		if ( isset( $field['max_choices'] ) && $count > (int) $field['max_choices'] ) {
			/* translators: 1: field label, 2: maximum selection count. */
			$errors[] = sprintf( __( '"%1$s" allows at most %2$d selection(s).', 'open-product-fields-for-woocommerce' ), $label, (int) $field['max_choices'] );
		}
		return $errors;
	}

	/** Return whether a date bound is canonical ISO or a WAPF relative period. */
	public static function is_date_boundary( string $boundary ): bool {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $boundary ) ) {
			$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $boundary, new \DateTimeZone( 'UTC' ) );
			$errors = \DateTimeImmutable::getLastErrors();
			return $date instanceof \DateTimeImmutable && ( ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $boundary;
		}

		if ( '' === trim( $boundary ) || strlen( $boundary ) > 128 ) {
			return false;
		}
		$matched = preg_match_all( '/[+-]?\d{1,5}[ymd]/i', trim( $boundary ), $tokens );
		if ( false === $matched || 0 === $matched || $matched > 12 ) {
			return false;
		}
		$remainder = preg_replace( '/[+-]?\d{1,5}[ymd]/i', '', trim( $boundary ) );
		if ( null === $remainder || '' !== trim( $remainder ) ) {
			return false;
		}
		foreach ( $tokens[0] as $token ) {
			if ( abs( (int) substr( $token, 0, -1 ) ) > 36500 ) {
				return false;
			}
		}
		return true;
	}

	/** Resolve WAPF periods such as `1y 9m 3d` in the WordPress site timezone. */
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
				: new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		}

		$totals = [ 'y' => 0, 'm' => 0, 'd' => 0 ];
		preg_match_all( '/([+-]?\d{1,5})([ymd])/i', trim( $boundary ), $parts, PREG_SET_ORDER );
		foreach ( $parts as $part ) {
			$unit = strtolower( $part[2] );
			$totals[ $unit ] += (int) $part[1];
		}

		try {
			$resolved = $today->setTime( 0, 0 );
			foreach ( [ 'y' => 'Y', 'm' => 'M', 'd' => 'D' ] as $unit => $interval_unit ) {
				$amount = $totals[ $unit ];
				if ( 0 === $amount ) {
					continue;
				}
				$interval = new \DateInterval( 'P' . abs( $amount ) . $interval_unit );
				if ( $amount < 0 ) {
					$interval->invert = 1;
				}
				$resolved = $resolved->add( $interval );
			}
		} catch ( \Exception $exception ) {
			return null;
		}

		return $resolved->format( 'Y-m-d' );
	}

	/** Validate a WAPF disabled date, recurring month/day, or inclusive range. */
	public static function is_disabled_date( string $rule ): bool {
		$parts = preg_split( '/\s+/', trim( $rule ) );
		if ( ! is_array( $parts ) || ! in_array( count( $parts ), [ 1, 2 ], true ) ) {
			return false;
		}
		foreach ( $parts as $part ) {
			if ( preg_match( '/^\d{2}-\d{2}$/', $part ) ) {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', '2000-' . $part, new \DateTimeZone( 'UTC' ) );
				$errors = \DateTimeImmutable::getLastErrors();
				if ( ! $date instanceof \DateTimeImmutable || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'm-d' ) !== $part ) {
					return false;
				}
			} elseif ( ! self::is_iso_date( $part ) ) {
				return false;
			}
		}
		if ( 2 === count( $parts ) ) {
			$first_is_month_day = (bool) preg_match( '/^\d{2}-\d{2}$/', $parts[0] );
			$second_is_month_day = (bool) preg_match( '/^\d{2}-\d{2}$/', $parts[1] );
			if ( $first_is_month_day !== $second_is_month_day || ( ! $first_is_month_day && $parts[0] > $parts[1] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Check a date against exact, recurring, and inclusive date-range rules. */
	public static function matches_disabled_date( string $value, array $rules ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date instanceof \DateTimeImmutable || ! self::is_iso_date( $value ) ) {
			return false;
		}
		$month_day = $date->format( 'm-d' );
		foreach ( $rules as $rule ) {
			if ( ! is_string( $rule ) || ! self::is_disabled_date( $rule ) ) {
				continue;
			}
			$parts = preg_split( '/\s+/', trim( $rule ) );
			if ( 1 === count( $parts ) ) {
				if ( $parts[0] === $value || $parts[0] === $month_day ) {
					return true;
				}
				continue;
			}
			$start = $parts[0];
			$end = $parts[1];
			if ( preg_match( '/^\d{2}-\d{2}$/', $start ) && preg_match( '/^\d{2}-\d{2}$/', $end ) ) {
				if ( ( $start <= $end && $month_day >= $start && $month_day <= $end ) || ( $start > $end && ( $month_day >= $start || $month_day <= $end ) ) ) {
					return true;
				}
			} else {
				if ( preg_match( '/^\d{2}-\d{2}$/', $start ) ) {
					$start = $date->format( 'Y' ) . '-' . $start;
				}
				if ( preg_match( '/^\d{2}-\d{2}$/', $end ) ) {
					$end = $date->format( 'Y' ) . '-' . $end;
				}
				if ( $start <= $value && $value <= $end ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function is_iso_date( string $value ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		return $date instanceof \DateTimeImmutable && ( ! is_array( $errors ) || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $value;
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
				/* translators: %s: field label. */
				return [ sprintf( __( '"%s" is a required field.', 'open-product-fields-for-woocommerce' ), $label ) ];
			}
			return [];
		}

		if ( ! $provided || null === $value ) {
			/* translators: %s: field label. */
			return ! empty( $field['required'] ) ? [ sprintf( __( '"%s" is a required field.', 'open-product-fields-for-woocommerce' ), $label ) ] : [];
		}

		// Match the HTML single-address email grammar used by WAPF's native input.
		// FILTER_VALIDATE_EMAIL rejects native-valid addresses such as a@localhost
		// and consecutive local-part dots while accepting quoted native-invalid ones.
		if ( 'email' === $type && ! preg_match( '/^[a-zA-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/D', $value ) ) {
			/* translators: %s: field label. */
			return [ sprintf( __( '"%s" must be a valid email address.', 'open-product-fields-for-woocommerce' ), $label ) ];
		}

		if ( 'url' === $type && ! UrlValue::is_valid( $value ) ) {
			/* translators: %s: field label. */
			return [ sprintf( __( '"%s" must be a valid URL.', 'open-product-fields-for-woocommerce' ), $label ) ];
		}

		if ( 'number' === $type ) {
			// WAPF validates number fields server-side (class-cart.php
			// validate_number_field): numeric input, optional integer mode,
			// min/max bounds, and step counted from the configured minimum.
			if ( ! is_numeric( $value ) ) {
				/* translators: %s: field label. */
				return [ sprintf( __( '"%s" must be a number.', 'open-product-fields-for-woocommerce' ), $label ) ];
			}
			$number = (float) $value;
			if ( 'integer' === ( $field['number_mode'] ?? null ) && abs( $number - round( $number ) ) > 0.0000001 ) {
				/* translators: %s: field label. */
				return [ sprintf( __( '"%s" must be a whole number.', 'open-product-fields-for-woocommerce' ), $label ) ];
			}
			if ( isset( $field['min'] ) && '' !== $field['min'] && $number < (float) $field['min'] ) {
				/* translators: 1: field label, 2: minimum allowed number. */
				return [ sprintf( __( '"%1$s" must be at least %2$s.', 'open-product-fields-for-woocommerce' ), $label, (string) $field['min'] ) ];
			}
			if ( isset( $field['max'] ) && '' !== $field['max'] && $number > (float) $field['max'] ) {
				/* translators: 1: field label, 2: maximum allowed number. */
				return [ sprintf( __( '"%1$s" must be at most %2$s.', 'open-product-fields-for-woocommerce' ), $label, (string) $field['max'] ) ];
			}
			if ( isset( $field['step'] ) && is_numeric( $field['step'] ) && (float) $field['step'] > 0 ) {
				$step_origin = isset( $field['min'] ) && is_numeric( $field['min'] ) ? (float) $field['min'] : 0.0;
				$step_ratio  = ( $number - $step_origin ) / (float) $field['step'];
				if ( abs( $step_ratio - round( $step_ratio ) ) > 0.0000001 ) {
					/* translators: 1: field label, 2: required increment. */
					return [ sprintf( __( '"%1$s" must use increments of %2$s.', 'open-product-fields-for-woocommerce' ), $label, (string) $field['step'] ) ];
				}
			}
		}

		if ( 'date' === $type ) {
			$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( ! $date instanceof \DateTimeImmutable || ( is_array( $errors ) && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ) {
				/* translators: %s: field label. */
				return [ sprintf( __( '"%s" must be a valid date.', 'open-product-fields-for-woocommerce' ), $label ) ];
			}
			$current = $today ?? ( function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
			$site_today = $current->format( 'Y-m-d' );
			if ( false === ( $field['allow_past'] ?? true ) && $value < $site_today ) {
				/* translators: %s: field label. */
				return [ sprintf( __( '"%s" cannot be in the past.', 'open-product-fields-for-woocommerce' ), $label ) ];
			}
			if ( false === ( $field['allow_future'] ?? true ) && $value > $site_today ) {
				/* translators: %s: field label. */
				return [ sprintf( __( '"%s" cannot be in the future.', 'open-product-fields-for-woocommerce' ), $label ) ];
			}
			foreach ( [ 'min_date', 'max_date' ] as $key ) {
				if ( ! isset( $field[ $key ] ) ) {
					continue;
				}
				$boundary = self::resolve_date_boundary( (string) $field[ $key ], $today );
				if ( null !== $boundary && ( 'min_date' === $key ? $value < $boundary : $value > $boundary ) ) {
					if ( 'min_date' === $key ) {
						/* translators: 1: field label, 2: earliest allowed ISO date. */
						return [ sprintf( __( '"%1$s" must be on or after %2$s.', 'open-product-fields-for-woocommerce' ), $label, $boundary ) ];
					}
					/* translators: 1: field label, 2: latest allowed ISO date. */
					return [ sprintf( __( '"%1$s" must be on or before %2$s.', 'open-product-fields-for-woocommerce' ), $label, $boundary ) ];
				}
			}
			if ( in_array( (int) $date->format( 'w' ), $field['disabled_weekdays'] ?? [], true ) ) {
				/* translators: %s: field label. */
				return [ sprintf( __( '"%s" is unavailable on this weekday.', 'open-product-fields-for-woocommerce' ), $label ) ];
			}
			if ( self::matches_disabled_date( $value, $field['disabled_dates'] ?? [] ) ) {
				/* translators: %s: field label. */
				return [ sprintf( __( '"%s" contains a disallowed date.', 'open-product-fields-for-woocommerce' ), $label ) ];
			}
			if ( isset( $field['cutoff_time'] ) && $value === $current->format( 'Y-m-d' ) && $current->format( 'H:i:s' ) >= $field['cutoff_time'] . ':00' ) {
				/* translators: %s: field label. */
				return [ sprintf( __( '"%s" is no longer available for today.', 'open-product-fields-for-woocommerce' ), $label ) ];
			}
		}

		// Length and pattern constraints are stored on text/textarea fields
		// (WAPF only emits them as native attributes; OPF enforces them
		// server-side as well). They apply to any type carrying the keys.
		$value_length = self::text_length( $value );
		if ( isset( $field['minlength'] ) && $value_length < (int) $field['minlength'] ) {
			/* translators: 1: field label, 2: minimum character count. */
			return [ sprintf( __( '"%1$s" must be at least %2$d characters.', 'open-product-fields-for-woocommerce' ), $label, (int) $field['minlength'] ) ];
		}
		if ( isset( $field['maxlength'] ) && $value_length > (int) $field['maxlength'] ) {
			/* translators: 1: field label, 2: maximum character count. */
			return [ sprintf( __( '"%1$s" must be at most %2$d characters.', 'open-product-fields-for-woocommerce' ), $label, (int) $field['maxlength'] ) ];
		}
		if ( isset( $field['pattern'] ) && 1 !== self::matches_pattern( (string) $field['pattern'], $value ) ) {
			/* translators: %s: field label. */
			return [ sprintf( __( '"%s" has an invalid format.', 'open-product-fields-for-woocommerce' ), $label ) ];
		}

		return [];
	}

	/**
	 * Count submitted characters the way the HTML minlength/maxlength
	 * attributes do: Unicode code points, not bytes.
	 */
	private static function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Match a stored pattern against the complete submitted value.
	 *
	 * HTML pattern semantics anchor the whole input, so the stored
	 * expression is wrapped in `\A(?: … )\z` before matching. A
	 * delimiter that cannot appear inside the pattern is picked so
	 * author-supplied expressions containing `/` still work.
	 */
	private static function matches_pattern( string $pattern, string $value ): int {
		if ( '' === $pattern ) {
			return 1;
		}
		foreach ( [ '~', '#', '%', '!' ] as $delimiter ) {
			if ( false === strpos( $pattern, $delimiter ) ) {
				$compiled = @preg_match( $delimiter . '\A(?:' . $pattern . ')\z' . $delimiter . 'u', $value );
				return false === $compiled ? 0 : $compiled;
			}
		}
		// Every safe delimiter occurs in the pattern: fall back to `/` and
		// let preg_quote handle nothing — the pattern simply cannot compile.
		$compiled = @preg_match( '/\A(?:' . str_replace( '/', '\/', $pattern ) . ')\z/u', $value );
		return false === $compiled ? 0 : $compiled;
	}
}
