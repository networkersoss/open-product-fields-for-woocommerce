<?php
/**
 * Button-based repeated input normalization and validation.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class RepeaterField {
	/** OPF request-resource ceiling; WAPF's quantity-mode cap is not established. */
	public const MAX_QUANTITY_ROWS = 1000;
	/** Leave room for WooCommerce, nonce, and unrelated form variables. */
	public const INPUT_VAR_RESERVE = 32;

	/** Supported single-field repeater definitions. */
	public static function normalize( $raw, array $field ): array {
		if ( ! is_array( $raw ) || empty( $raw['enabled'] ) ) {
			return [];
		}
		$mode = (string) ( $raw['mode'] ?? 'button' );
		if ( ! in_array( $mode, [ 'button', 'quantity' ], true ) ) {
			throw new \InvalidArgumentException( 'This repeater mode is not supported.' );
		}
		$type = (string) ( $field['type'] ?? 'text' );
		if ( ! in_array( $type, [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch' ], true ) ) {
			throw new \InvalidArgumentException( 'This field type cannot be repeated yet.' );
		}
		if ( ! empty( $field['image_quantities'] ) ) {
			throw new \InvalidArgumentException( 'Image quantity fields cannot be repeated yet.' );
		}
		if ( 'none' !== ( $field['pricing']['type'] ?? 'none' ) ) {
			throw new \InvalidArgumentException( 'Repeated field pricing is not supported yet.' );
		}
		foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
			if ( 'none' !== ( $choice['pricing']['type'] ?? 'none' ) ) {
				throw new \InvalidArgumentException( 'Repeated choice pricing is not supported yet.' );
			}
			if ( 0.0 !== (float) ( $choice['weight'] ?? 0.0 ) ) {
				throw new \InvalidArgumentException( 'Repeated choice weight adjustments are not supported yet.' );
			}
		}
		if ( 'quantity' === $mode ) {
			return [ 'enabled' => true, 'mode' => 'quantity' ];
		}
		return [
			'enabled' => true,
			'mode' => 'button',
			'max' => max( 1, min( 20, (int) ( $raw['max'] ?? 5 ) ) ),
		];
	}

	/** Normalize each submitted instance without reordering it. */
	public static function sanitize( array $field, $raw, callable $sanitize_row, int $quantity = 1, ?int $quantity_row_limit = null ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$rows = [];
		if ( 'quantity' === ( $field['repeat']['mode'] ?? 'button' ) ) {
			$effective_limit = min( self::MAX_QUANTITY_ROWS, max( 0, $quantity_row_limit ?? self::MAX_QUANTITY_ROWS ) );
			$row_limit = min( self::MAX_QUANTITY_ROWS + 1, max( 1, min( max( 1, $quantity ), $effective_limit ) ) + 1 );
		} else {
			$row_limit = 21;
		}
		foreach ( array_slice( array_values( $raw ), 0, $row_limit ) as $row ) {
			$rows[] = $sanitize_row( $row );
		}
		return $rows ?: null;
	}

	/** Validate every submitted row and the configured maximum. */
	public static function validate( array $field, array $rows, ?int $quantity = null, ?int $quantity_row_limit = null ): array {
		$label = (string) ( $field['label'] ?? '' );
		if ( 'quantity' === ( $field['repeat']['mode'] ?? 'button' ) && null !== $quantity ) {
			$effective_limit = min( self::MAX_QUANTITY_ROWS, max( 0, $quantity_row_limit ?? self::MAX_QUANTITY_ROWS ) );
			if ( $quantity > $effective_limit ) {
				return [ sprintf( '"%s" supports at most %d product units for this request.', $label, $effective_limit ) ];
			}
			if ( count( $rows ) !== max( 1, $quantity ) ) {
				return [ sprintf( '"%s" needs one repeated row for each product unit.', $label ) ];
			}
		}
		$max = (int) ( $field['repeat']['max'] ?? 5 );
		if ( 'button' === ( $field['repeat']['mode'] ?? 'button' ) && count( $rows ) > $max ) {
			return [ sprintf( '"%s" allows at most %d repeated rows.', $label, $max ) ];
		}
		if ( ! $rows && ! empty( $field['required'] ) ) {
			return [ sprintf( '"%s" is a required field.', $label ) ];
		}
		$errors = [];
		foreach ( $rows as $index => $value ) {
			$empty = null === $value || '' === $value || [] === $value || ( 'toggle' === ( $field['type'] ?? '' ) && '0' === $value );
			if ( ! empty( $field['required'] ) && $empty ) {
				$errors[] = sprintf( '"%s" is required in repeated row %d.', $label, $index + 1 );
				continue;
			}
			if ( $empty ) {
				continue;
			}
			$instance_errors = self::validate_instance( $field, $value );
			foreach ( $instance_errors as $error ) {
				$errors[] = preg_replace( '/\\.$/', sprintf( ' in repeated row %d.', $index + 1 ), $error );
			}
		}
		return $errors;
	}

	/**
	 * Derive an explicit product-quantity ceiling from PHP's form-variable limit.
	 * The count is intentionally conservative: all potentially submitted fixed
	 * controls are reserved, even when a conditional may hide them at runtime.
	 *
	 * @param array<int,array<string,mixed>> $fields All fields rendered for product.
	 */
	public static function quantity_row_limit( array $fields, ?int $max_input_vars = null, int $reserve = self::INPUT_VAR_RESERVE ): int {
		if ( null === $max_input_vars ) {
			$configured = ini_get( 'max_input_vars' );
			$max_input_vars = is_string( $configured ) && is_numeric( $configured ) && (int) $configured > 0
				? (int) $configured
				: 1000;
		}

		$fixed_inputs = 0;
		$inputs_per_quantity_row = 0;
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$input_count = self::input_count( $field );
			if ( ! empty( $field['repeat']['enabled'] ) && 'quantity' === ( $field['repeat']['mode'] ?? 'button' ) ) {
				$inputs_per_quantity_row += $input_count;
			} elseif ( ! empty( $field['repeat']['enabled'] ) && 'button' === ( $field['repeat']['mode'] ?? 'button' ) ) {
				$fixed_inputs += $input_count * max( 1, (int) ( $field['repeat']['max'] ?? 5 ) );
			} else {
				$fixed_inputs += $input_count;
			}
		}

		if ( $inputs_per_quantity_row < 1 ) {
			return 0;
		}
		$available = max( 0, $max_input_vars - $fixed_inputs - max( 0, $reserve ) );
		return min( self::MAX_QUANTITY_ROWS, intdiv( $available, $inputs_per_quantity_row ) );
	}

	/** Conservative maximum number of form variables submitted by one field instance. */
	private static function input_count( array $field ): int {
		$type = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, [ 'paragraph', 'html', 'section', 'calculation' ], true ) ) {
			return 0;
		}
		if ( 'upload' === $type ) {
			return max( 1, (int) ( $field['max_files'] ?? 1 ) );
		}
		if ( 'swatch' === $type && ! empty( $field['image_quantities'] ) ) {
			return max( 1, count( (array) ( $field['choices'] ?? [] ) ) );
		}
		if ( 'toggle' === $type ) {
			return 2; // Hidden false value plus the checkbox value.
		}
		if ( 'checkbox' === $type || ( 'swatch' === $type && ! empty( $field['multiple'] ) ) ) {
			return max( 1, count( (array) ( $field['choices'] ?? [] ) ) );
		}
		return 1;
	}

	private static function validate_instance( array $field, $value ): array {
		$type = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle' ], true ) ) {
			return FieldValue::validate( array_merge( $field, [ 'required' => false ] ), is_scalar( $value ) ? (string) $value : null, true );
		}
		if ( 'checkbox' === $type || ( 'swatch' === $type && ! empty( $field['multiple'] ) ) ) {
			return FieldValue::validate_choices( array_merge( $field, [ 'required' => false ] ), $value, true );
		}
		return [];
	}
}
