<?php

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

/** Verify bounded, signed URL-prefill payloads. */
final class Prefill {
	/**
	 * Read an allow-listed, unsigned WAPF-style query value.
	 *
	 * PHP has already URL-decoded `$_GET` values. Choice values are matched
	 * exactly against normalized choice slugs; normal add-to-cart validation
	 * remains authoritative.
	 *
	 * @param array<string,mixed> $field Normalized field configuration.
	 * @param mixed               $query_value Raw query value.
	 * @return string|array<int,string>|null
	 */
	public static function from_query( array $field, $query_value ) {
		if ( empty( $field['prefill_param'] ) || ! is_string( $query_value ) || strlen( $query_value ) > 2048 ) {
			return null;
		}

		$is_multiple = 'checkbox' === ( $field['type'] ?? '' ) || ( 'swatch' === ( $field['type'] ?? '' ) && ! empty( $field['multiple'] ) && empty( $field['image_quantities'] ) );
		if ( ! $is_multiple ) {
			return $query_value;
		}

		$values = [];
		foreach ( explode( ',', $query_value ) as $value ) {
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}
		return array_values( array_unique( $values ) );
	}

	/**
	 * @return array<string,string|array<int,string>>
	 */
	public static function decode( string $payload, string $signature, string $secret ): array {
		if ( '' === $payload || '' === $signature || strlen( $payload ) > 16384 || '' === $secret ) {
			return [];
		}
		$expected = hash_hmac( 'sha256', $payload, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return [];
		}
		$encoded = strtr( $payload, '-_', '+/' );
		$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
		$json = base64_decode( $encoded, true );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) ) {
			return [];
		}
		$out = [];
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $key ) ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				$out[ $key ] = substr( (string) $value, 0, 2048 );
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = array_values( array_filter( array_map( 'strval', $value ), static fn( string $item ): bool => strlen( $item ) <= 2048 ) );
			}
		}
		return $out;
	}
}
