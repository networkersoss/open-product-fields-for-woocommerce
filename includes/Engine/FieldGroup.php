<?php
/**
 * OPF field group data model (array-based, schema-versioned).
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Field group value object. Pure PHP — no WordPress dependencies.
 */
final class FieldGroup {

	/**
	 * Current schema version.
	 */
	public const SCHEMA = 1;

	/**
	 * Supported field types.
	 */
	public const FIELD_TYPES = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'upload', 'toggle', 'select', 'radio', 'checkbox', 'swatch', 'child_products', 'paragraph', 'html', 'shortcode', 'content_image', 'section', 'calculation' ];

	/**
	 * Supported pricing types.
	 */
	public const PRICING_TYPES = [ 'none', 'fixed', 'percent', 'formula', 'quantity', 'characters', 'value' ];

	/**
	 * @var array<string,mixed>
	 */
	public array $data;

	/**
	 * Build from an associative array, normalizing missing keys.
	 *
	 * @param array<string,mixed> $data Raw group data.
	 */
	public function __construct( array $data ) {
		$this->data = self::normalize( $data );
	}

	/**
	 * Upgrade a persisted group to the current schema before normalization.
	 *
	 * Schema 0 represents groups written before OPF stored an explicit schema
	 * number. Keeping this migration separate from normalize() gives later
	 * schema changes one deterministic place to preserve old records.
	 *
	 * @param array<string,mixed> $data Persisted group data.
	 * @return array<string,mixed>
	 */
	public static function migrate( array $data ): array {
		$raw_schema = $data['schema'] ?? 0;
		if ( ! is_int( $raw_schema ) && ! ( is_string( $raw_schema ) && ctype_digit( $raw_schema ) ) ) {
			throw new \InvalidArgumentException( 'OPF field group schema must be a non-negative integer.' );
		}

		$schema = (int) $raw_schema;
		if ( $schema > self::SCHEMA ) {
			throw new \InvalidArgumentException( 'OPF field group schema is newer than this plugin version.' );
		}

		while ( $schema < self::SCHEMA ) {
			switch ( $schema ) {
				case 0:
					// Legacy OPF groups had no explicit schema value.
					$data['schema'] = 1;
					$schema         = 1;
					break;

				default:
					throw new \InvalidArgumentException( 'No OPF field group migration exists for schema ' . $schema . '.' );
			}
		}

		return $data;
	}

	/**
	 * Normalize raw data to the canonical shape.
	 *
	 * @param array<string,mixed> $data Raw data.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $data ): array {
		$data = self::migrate( $data );

		$fields = [];
		foreach ( ( $data['fields'] ?? [] ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$fields[] = self::normalize_field( $field );
		}

		$rule_groups = [];
		foreach ( ( $data['rule_groups'] ?? [] ) as $group ) {
			$rules = [];
			foreach ( ( is_array( $group ) ? ( $group['rules'] ?? [] ) : [] ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$rules[] = [
					'subject'  => (string) ( $rule['subject'] ?? 'product' ),
					'operator' => (string) ( $rule['operator'] ?? 'in' ),
					'terms'    => array_map( 'strval', (array) ( $rule['terms'] ?? [] ) ),
				];
			}
			if ( $rules ) {
				$rule_groups[] = [ 'rules' => $rules ];
			}
		}

		$normalized = [
			'schema'       => self::SCHEMA,
			'fields'       => $fields,
			'rule_groups'  => $rule_groups,
			'mark_required' => (bool) ( $data['mark_required'] ?? true ),
			'labels_position' => ( $data['labels_position'] ?? 'above' ) === 'below' ? 'below' : 'above',
		];
		$lookup_tables = self::normalize_lookup_tables( $data['lookup_tables'] ?? [] );
		if ( $lookup_tables ) {
			$normalized['lookup_tables'] = $lookup_tables;
		}
		$formula_variables = self::normalize_formula_variables( $data['formula_variables'] ?? [] );
		if ( $formula_variables ) {
			$normalized['formula_variables'] = $formula_variables;
		}
		$image_rules = self::normalize_image_rules( $data['image_rules'] ?? [], $fields );
		if ( $image_rules ) {
			$normalized['image_rules'] = $image_rules;
			if ( 'last' === ( $data['image_rule_mode'] ?? '' ) ) {
				$normalized['image_rule_mode'] = 'last';
			}
		}
		return $normalized;
	}

	/** Normalize bounded product-gallery image rules (AND within each rule, last matching rule wins). */
	public static function normalize_image_rules( $raw_rules, array $fields = [] ): array {
		if ( ! is_array( $raw_rules ) ) {
			return [];
		}
		$allowed_fields = [];
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && in_array( $field['type'] ?? '', [ 'select', 'radio', 'checkbox', 'swatch', 'toggle' ], true ) ) {
				$values = 'toggle' === ( $field['type'] ?? '' ) ? [ '0', '1' ] : array_map( 'strval', array_column( $field['choices'] ?? [], 'slug' ) );
				$allowed_fields[ (string) $field['id'] ] = array_fill_keys( $values, true );
			}
		}
		$rules = [];
		foreach ( array_slice( $raw_rules, 0, 64 ) as $raw_rule ) {
			if ( ! is_array( $raw_rule ) || ! is_string( $raw_rule['target_url'] ?? null ) ) {
				continue;
			}
			$url = trim( $raw_rule['target_url'] );
			if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7F]/', $url ) || ! preg_match( '#^(?:https?://[^/\s]+|/(?!/))#i', $url ) ) {
				continue;
			}
			$conditions = [];
			foreach ( array_slice( is_array( $raw_rule['conditions'] ?? null ) ? $raw_rule['conditions'] : [], 0, 32 ) as $condition ) {
				if ( ! is_array( $condition ) || ! is_scalar( $condition['field'] ?? null ) || ! is_scalar( $condition['value'] ?? null ) ) {
					continue;
				}
				$field_id = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $condition['field'] ) );
				$value = trim( (string) $condition['value'] );
				if ( '' === $field_id || ! isset( $allowed_fields[ $field_id ] ) || '' === $value || strlen( $value ) > 256 || ( '*' !== $value && ! isset( $allowed_fields[ $field_id ][ $value ] ) ) ) {
					continue;
				}
				$conditions[] = [ 'field' => $field_id, 'value' => $value ];
			}
			if ( $conditions ) {
				$rules[] = [ 'target_url' => $url, 'conditions' => $conditions ];
			}
		}
		return $rules;
	}

	/** Normalize native numeric formula variables and ordered conditional changes. */
	public static function normalize_formula_variables( $raw_variables ): array {
		if ( ! is_array( $raw_variables ) ) {
			return [];
		}
		$variables = [];
		foreach ( array_slice( $raw_variables, 0, 64, true ) as $name => $raw ) {
			if ( ! is_scalar( $name ) ) {
				continue;
			}
			$name = strtolower( (string) $name );
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) || ! is_array( $raw ) ) {
				continue;
			}
			$default = $raw['default'] ?? null;
			if ( ! is_numeric( $default ) || ! is_finite( (float) $default ) ) {
				continue;
			}
			$changes = [];
			foreach ( array_slice( is_array( $raw['changes'] ?? null ) ? $raw['changes'] : [], 0, 100 ) as $change ) {
				if ( ! is_array( $change ) || ! is_numeric( $change['value'] ?? null ) || ! is_finite( (float) $change['value'] ) ) {
					continue;
				}
				$rules = [];
				foreach ( array_slice( is_array( $change['rules'] ?? null ) ? $change['rules'] : [], 0, 32 ) as $rule ) {
					if ( ! is_array( $rule ) ) {
						continue;
					}
					if ( ! is_scalar( $rule['field'] ?? null ) || ! is_scalar( $rule['operator'] ?? null ) ) {
						continue;
					}
					$field = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $rule['field'] ) );
					$operator = (string) $rule['operator'];
					if ( '' === $field || ! in_array( $operator, [ 'is', 'is_not', 'contains', 'greater', 'less', 'empty', 'not_empty' ], true ) || ! is_scalar( $rule['value'] ?? '' ) ) {
						continue;
					}
					$rules[] = [ 'field' => $field, 'operator' => $operator, 'value' => (string) ( $rule['value'] ?? '' ) ];
				}
				if ( ! $rules ) {
					continue;
				}
				$changes[] = [
					'value' => (float) $change['value'],
					'logic' => 'any' === ( $change['logic'] ?? 'all' ) ? 'any' : 'all',
					'rules' => $rules,
				];
			}
			$variables[ $name ] = [ 'default' => (float) $default, 'changes' => $changes ];
		}
		return $variables;
	}

	/** Resolve defaults plus the first matching ordered change for each variable. */
	public static function resolve_formula_variables( array $variables, array $field_values ): array {
		$resolved = [];
		foreach ( self::normalize_formula_variables( $variables ) as $name => $definition ) {
			$value = $definition['default'];
			foreach ( $definition['changes'] as $change ) {
				if ( Evaluator::conditional_passes( [ 'logic' => $change['logic'], 'rules' => $change['rules'] ], $field_values ) ) {
					$value = $change['value'];
					break;
				}
			}
			$resolved[ $name ] = $value;
		}
		return $resolved;
	}

	/** Resolve configured variables plus numeric ACF values for one product. */
	public static function resolve_product_formula_variables( array $group_data, array $field_values, int $product_id ): array {
		$variables = self::resolve_formula_variables( self::formula_variables_for_group( $group_data ), $field_values );
		return array_replace( $variables, AcfFormula::variable_values_for_group( $group_data, $product_id ) );
	}

	/** Merge reusable site variables with group-local definitions; local names override site names. */
	public static function formula_variables_for_group( array $group_data ): array {
		$saved_variables = function_exists( 'get_option' ) ? get_option( 'opf_formula_variables', [] ) : [];
		$site_variables = function_exists( 'apply_filters' ) ? apply_filters( 'opf_formula_variables', $saved_variables ) : $saved_variables;
		$site_variables = self::normalize_formula_variables( $site_variables );
		$local_variables = self::normalize_formula_variables( $group_data['formula_variables'] ?? [] );
		return array_replace( $site_variables, $local_variables );
	}

	/**
	 * Normalize a single field.
	 *
	 * @param array<string,mixed> $field Raw field.
	 * @return array<string,mixed>
	 */
	public static function normalize_field( array $field ): array {
		$type = (string) ( $field['type'] ?? 'text' );
		if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
			$type = 'text';
		}

		$choices = [];
		foreach ( ( $field['choices'] ?? [] ) as $choice ) {
			if ( ! is_array( $choice ) || ( $choice['label'] ?? '' ) === '' ) {
				continue;
			}
			$pricing   = is_array( $choice['pricing'] ?? null ) ? $choice['pricing'] : [];
			$normalized_choice = [
				'slug'     => (string) ( $choice['slug'] ?? '' ),
				'label'    => (string) $choice['label'],
				'selected' => (bool) ( $choice['selected'] ?? false ),
				'disabled' => (bool) ( $choice['disabled'] ?? false ),
				'pricing'  => self::normalize_pricing( $pricing ),
			];
			if ( isset( $choice['color'] ) && is_string( $choice['color'] ) && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $choice['color'] ) ) {
				$normalized_choice['color'] = strtolower( $choice['color'] );
			}
			if ( isset( $choice['image'] ) && is_string( $choice['image'] ) && strlen( $choice['image'] ) <= 2048 && preg_match( '#^(?:https?://|/)#i', $choice['image'] ) ) {
				$normalized_choice['image'] = $choice['image'];
			}
			if ( isset( $choice['description'] ) && is_scalar( $choice['description'] ) ) {
				$description = (string) $choice['description'];
				$description = preg_replace( '#<(script|style)\b[^>]*>.*?(?:</\1\s*>|$)#is', '', $description );
				$description = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', strip_tags( (string) $description ) ) );
				$description = function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 500 ) : substr( $description, 0, 500 );
				if ( '' !== $description ) {
					$normalized_choice['description'] = $description;
				}
			}
			if ( isset( $choice['weight'] ) && is_numeric( $choice['weight'] ) && is_finite( (float) $choice['weight'] ) ) {
				$normalized_choice['weight'] = (float) $choice['weight'];
			}
			$choices[] = $normalized_choice;
		}

		$conditionals = [];
		foreach ( ( $field['conditionals'] ?? [] ) as $conditional ) {
			if ( ! is_array( $conditional ) ) {
				continue;
			}
			$rules = [];
			foreach ( ( $conditional['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
					continue;
				}
				$rules[] = [
					'field'    => (string) $rule['field'],
					'operator' => (string) ( $rule['operator'] ?? 'is' ),
					'value'    => (string) ( $rule['value'] ?? '' ),
				];
			}
			if ( $rules ) {
				$conditionals[] = [
					'action' => ( $conditional['action'] ?? 'show' ) === 'hide' ? 'hide' : 'show',
					'logic'  => ( $conditional['logic'] ?? 'all' ) === 'any' ? 'any' : 'all',
					'rules'  => $rules,
				];
			}
		}

		$pricing = self::normalize_pricing( is_array( $field['pricing'] ?? null ) ? $field['pricing'] : [] );
		$repeat = RepeaterField::normalize( $field['repeat'] ?? [], [ 'type' => $type, 'pricing' => $pricing, 'choices' => $choices, 'image_quantities' => ! empty( $field['image_quantities'] ) ] );
		$content = is_scalar( $field['content'] ?? null ) ? (string) $field['content'] : '';
		if ( 'shortcode' === $type ) {
			$content = substr( $content, 0, 10000 );
		}
		$heading = (string) ( $field['heading'] ?? $field['label'] ?? '' );
		$has_default = array_key_exists( 'default', $field );
		$default = $field['default'] ?? null;
		if ( $has_default && is_object( $default ) ) {
			$has_default = false;
		} elseif ( $has_default && is_array( $default ) ) {
			$default = ( ! empty( $field['image_quantities'] ) || ( 'child_products' === $type && ! empty( $field['quantity_input'] ) ) )
				? array_map( 'strval', $default )
				: array_values( array_map( 'strval', $default ) );
		} elseif ( $has_default && is_scalar( $default ) ) {
			$default = (string) $default;
		} else {
			$has_default = false;
		}
		$constraints = [];
		foreach ( [ 'min', 'max', 'step' ] as $key ) {
			if ( array_key_exists( $key, $field ) && is_numeric( $field[ $key ] ) ) {
				$value = (float) $field[ $key ];
				if ( 'step' !== $key || $value > 0 ) {
					$constraints[ $key ] = $value;
				}
			}
		}
		foreach ( [ 'maxlength', 'minlength' ] as $key ) {
			if ( array_key_exists( $key, $field ) && is_numeric( $field[ $key ] ) && (int) $field[ $key ] >= 0 ) {
				$constraints[ $key ] = (int) $field[ $key ];
			}
		}
		if ( 'number' === $type && in_array( $field['number_mode'] ?? null, [ 'integer', 'decimal' ], true ) ) {
			$constraints['number_mode'] = $field['number_mode'];
		}
		if ( isset( $field['pattern'] ) && is_string( $field['pattern'] ) && '' !== $field['pattern'] ) {
			$constraints['pattern'] = $field['pattern'];
		}
		if ( isset( $field['prefill_param'] ) && is_string( $field['prefill_param'] ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $field['prefill_param'] ) ) {
			$constraints['prefill_param'] = $field['prefill_param'];
		}
		if ( 'date' === $type ) {
			foreach ( [ 'allow_past', 'allow_future' ] as $key ) {
				if ( array_key_exists( $key, $field ) ) {
					$value = $field[ $key ];
					if ( ! in_array( $value, [ true, false, 0, 1, '0', '1' ], true ) ) {
						throw new \InvalidArgumentException( sprintf( 'Date field %s must be a boolean.', $key ) );
					}
					$constraints[ $key ] = in_array( $value, [ true, 1, '1' ], true );
				} else {
					// Existing date fields leave both sides selectable by default.
					$constraints[ $key ] = true;
				}
			}
			foreach ( [ 'min_date', 'max_date' ] as $key ) {
				if ( isset( $field[ $key ] ) && is_string( $field[ $key ] ) && '' !== $field[ $key ] ) {
					$boundary = trim( $field[ $key ] );
					if ( ! FieldValue::is_date_boundary( $boundary ) ) {
						throw new \InvalidArgumentException( sprintf( 'Field %s must use YYYY-MM-DD or a relative date offset such as 7d.', $key ) );
					}
					$constraints[ $key ] = $boundary;
				}
			}
			if ( isset( $field['disabled_weekdays'] ) ) {
				if ( ! is_array( $field['disabled_weekdays'] ) ) {
					throw new \InvalidArgumentException( 'Disabled weekdays must be a list of weekday numbers from 0 to 6.' );
				}
				$weekdays = [];
				foreach ( $field['disabled_weekdays'] as $weekday ) {
					if ( ! is_scalar( $weekday ) || ! preg_match( '/^[0-6]$/', (string) $weekday ) ) {
						throw new \InvalidArgumentException( 'Disabled weekdays must be a list of weekday numbers from 0 to 6.' );
					}
					$weekdays[] = (int) $weekday;
				}
				$constraints['disabled_weekdays'] = array_values( array_unique( $weekdays ) );
			}
			if ( isset( $field['disabled_dates'] ) ) {
				if ( ! is_array( $field['disabled_dates'] ) || count( $field['disabled_dates'] ) > 512 ) {
					throw new \InvalidArgumentException( 'Disabled dates must be a list of at most 512 dates.' );
				}
				$disabled_dates = [];
				foreach ( $field['disabled_dates'] as $disabled_date ) {
					if ( ! is_string( $disabled_date ) || ! FieldValue::is_disabled_date( trim( $disabled_date ) ) ) {
						throw new \InvalidArgumentException( 'Disabled dates must use a date, recurring month-day, or valid inclusive range.' );
					}
					$disabled_dates[] = trim( $disabled_date );
				}
				$constraints['disabled_dates'] = array_values( array_unique( $disabled_dates ) );
			}
			if ( isset( $field['cutoff_time'] ) && '' !== (string) $field['cutoff_time'] ) {
				$cutoff = trim( (string) $field['cutoff_time'] );
				if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff ) ) {
					throw new \InvalidArgumentException( 'Date cutoff time must use 24-hour HH:MM format.' );
				}
				$constraints['cutoff_time'] = $cutoff;
			}
		}
		$multiple = 'swatch' === $type && ! empty( $field['multiple'] );
		$image_quantities = 'swatch' === $type && ! empty( $field['image_quantities'] ) && count( $choices ) > 0;
		if ( $image_quantities ) {
			foreach ( $choices as $choice ) {
				if ( empty( $choice['image'] ) ) {
					$image_quantities = false;
					break;
				}
			}
		}
		if ( $image_quantities ) {
			$multiple = false;
			foreach ( [ 'min', 'max', 'step' ] as $key ) {
				if ( array_key_exists( $key, $field ) && is_numeric( $field[ $key ] ) && (float) $field[ $key ] <= 0 ) {
					throw new \InvalidArgumentException( 'Image quantity minimum, maximum, and step must be positive whole numbers.' );
				}
			}
			foreach ( [ 'min', 'max', 'step' ] as $key ) {
				if ( isset( $constraints[ $key ] ) ) {
					if ( floor( $constraints[ $key ] ) !== $constraints[ $key ] ) {
					throw new \InvalidArgumentException( 'Image quantities require whole-number minimum, maximum, and step values.' );
					}
					$constraints[ $key ] = (int) $constraints[ $key ];
				}
			}
			if ( ( isset( $constraints['min'] ) && $constraints['min'] < 1 ) || ( isset( $constraints['max'] ) && $constraints['max'] < 1 ) || ( isset( $constraints['step'] ) && $constraints['step'] < 1 ) ) {
				throw new \InvalidArgumentException( 'Image quantity minimum, maximum, and step must be positive whole numbers.' );
			}
		}
		if ( 'checkbox' === $type || 'child_products' === $type || $multiple || $image_quantities ) {
			foreach ( [ 'min_selections', 'max_selections' ] as $key ) {
				if ( array_key_exists( $key, $field ) && is_numeric( $field[ $key ] ) && (int) $field[ $key ] >= 0 ) {
					$constraints[ $key ] = 'child_products' === $type ? (int) $field[ $key ] : min( count( $choices ), (int) $field[ $key ] );
				}
			}
		}
		if ( $image_quantities ) {
			foreach ( [ 'min_total_quantity', 'max_total_quantity' ] as $key ) {
				if ( isset( $field[ $key ] ) && is_numeric( $field[ $key ] ) && (int) $field[ $key ] >= 0 ) {
					$constraints[ $key ] = (int) $field[ $key ];
				}
			}
			if ( isset( $constraints['min_total_quantity'], $constraints['max_total_quantity'] ) && $constraints['min_total_quantity'] > $constraints['max_total_quantity'] ) {
				throw new \InvalidArgumentException( 'Minimum total quantity cannot exceed its maximum.' );
			}
		}
		if ( 'upload' === $type ) {
			$raw_max_files = (int) ( $field['max_files'] ?? 1 );
			$constraints['max_files'] = -1 === $raw_max_files ? -1 : max( 1, min( 20, $raw_max_files ) );
			$constraints['min_files'] = max( 0, min( 20, (int) ( $field['min_files'] ?? 0 ) ) );
			if ( -1 !== $constraints['max_files'] && $constraints['min_files'] > $constraints['max_files'] ) {
				throw new \InvalidArgumentException( 'Minimum file count cannot exceed maximum file count.' );
			}
			$constraints['max_size'] = max( 1, min( 104857600, (int) ( $field['max_size'] ?? 10485760 ) ) );
			if ( isset( $field['min_size_mb'] ) && is_numeric( $field['min_size_mb'] ) && (float) $field['min_size_mb'] > 0 ) {
				$constraints['min_size_mb'] = min( 100.0, (float) $field['min_size_mb'] );
				if ( (int) ceil( $constraints['min_size_mb'] * 1048576 ) > $constraints['max_size'] ) {
					throw new \InvalidArgumentException( 'Minimum file size cannot exceed maximum file size.' );
				}
			}
			foreach ( [ 'min_width', 'min_height' ] as $dimension ) {
				if ( isset( $field[ $dimension ] ) && is_numeric( $field[ $dimension ] ) && (int) $field[ $dimension ] > 0 ) {
					$constraints[ $dimension ] = min( 20000, (int) $field[ $dimension ] );
				}
			}
			if ( in_array( $field['auto_resize'] ?? false, [ true, 1, '1' ], true ) ) {
				foreach ( [ 'max_width', 'max_height' ] as $dimension ) {
					if ( isset( $field[ $dimension ] ) && is_numeric( $field[ $dimension ] ) && (int) $field[ $dimension ] > 0 ) {
						$constraints[ $dimension ] = min( 20000, (int) $field[ $dimension ] );
					}
				}
				if ( empty( $constraints['max_width'] ) && empty( $constraints['max_height'] ) ) {
					throw new \InvalidArgumentException( 'Automatic image resize requires a maximum width or height.' );
				}
				$constraints['auto_resize'] = true;
			}
			$editor_mode = (string) ( $field['image_editor_mode'] ?? '' );
			if ( in_array( $editor_mode, [ 'optional', 'forced' ], true ) ) {
				$constraints['image_editor_mode'] = $editor_mode;
				foreach ( [ 'image_editor_crop', 'image_editor_rotate', 'image_editor_flip', 'image_editor_resize' ] as $editor_option ) {
					$constraints[ $editor_option ] = ! array_key_exists( $editor_option, $field ) || in_array( $field[ $editor_option ], [ true, 1, '1', 'yes', 'true' ], true );
				}
				$aspect_ratio = (string) ( $field['image_editor_aspect_ratio'] ?? 'free' );
				$constraints['image_editor_aspect_ratio'] = in_array( $aspect_ratio, [ 'free', '1:1', '4:3', '3:2', '16:9', '2:3', '9:16' ], true ) ? $aspect_ratio : 'free';
			}
			if ( isset( $field['allowed_types'] ) && is_array( $field['allowed_types'] ) ) {
				$constraints['allowed_types'] = array_values( array_filter( array_map( static fn( $type ): string => strtolower( preg_replace( '/[^a-z0-9.*\/-]/i', '', (string) $type ) ), $field['allowed_types'] ) ) );
			}
		}
		if ( isset( $constraints['min'], $constraints['max'] ) && $constraints['min'] > $constraints['max'] ) {
			throw new \InvalidArgumentException( 'Field minimum cannot exceed its maximum.' );
		}
		$min_date = isset( $constraints['min_date'] ) ? FieldValue::resolve_date_boundary( $constraints['min_date'] ) : null;
		$max_date = isset( $constraints['max_date'] ) ? FieldValue::resolve_date_boundary( $constraints['max_date'] ) : null;
		if ( null !== $min_date && null !== $max_date && $min_date > $max_date ) {
			throw new \InvalidArgumentException( 'Date minimum cannot exceed its maximum.' );
		}
		if ( isset( $constraints['min_selections'], $constraints['max_selections'] ) && $constraints['min_selections'] > $constraints['max_selections'] ) {
			throw new \InvalidArgumentException( 'Minimum selections cannot exceed maximum selections.' );
		}

		// Field ids become input name fragments and DOM hooks: restrict to a
		// conservative slug charset regardless of the source.
		$field_id = (string) ( $field['id'] ?? '' );
		$field_id = strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $field_id ) );
		if ( '' === $field_id ) {
			$field_id = 'field';
		}

		if ( in_array( $type, [ 'paragraph', 'html', 'shortcode', 'content_image', 'section', 'calculation' ], true ) ) {
			$choices = [];
			$pricing = self::normalize_pricing( [] );
		}

		$normalized = [
			'id'           => $field_id,
			'label'        => (string) ( $field['label'] ?? '' ),
			'description'  => (string) ( $field['description'] ?? '' ),
			'type'         => $type,
		'required'     => ! in_array( $type, [ 'paragraph', 'html', 'shortcode', 'content_image', 'section', 'calculation' ], true ) && (bool) ( $field['required'] ?? false ),
			'width'        => max( 25, min( 100, (int) ( $field['width'] ?? 100 ) ) ),
			'css_class'    => (string) ( $field['css_class'] ?? '' ),
			'placeholder'  => (string) ( $field['placeholder'] ?? '' ),
			'choices'      => $choices,
			'pricing'      => $pricing,
			'conditionals' => $conditionals,
		];
		if ( isset( $field['weight_formula'] ) && is_string( $field['weight_formula'] ) && strlen( $field['weight_formula'] ) <= 4096 && '' !== trim( $field['weight_formula'] ) ) {
			$normalized['weight_formula'] = trim( $field['weight_formula'] );
		}
		if ( 'tooltip' === ( $field['description_presentation'] ?? 'inline' ) ) {
			$normalized['description_presentation'] = 'tooltip';
		}
		foreach ( [ 'hide_cart', 'hide_checkout', 'hide_order' ] as $visibility_option ) {
			if ( in_array( $field[ $visibility_option ] ?? false, [ true, 1, '1', 'yes', 'true' ], true ) ) {
				$normalized[ $visibility_option ] = true;
			}
		}
		if ( in_array( $type, [ 'toggle', 'checkbox' ], true ) && ! empty( $field['switch_control'] ) ) {
			$normalized['switch_control'] = true;
		}
		if ( 'checkbox' === $type && isset( $field['columns'] ) && is_numeric( $field['columns'] ) && is_finite( (float) $field['columns'] ) ) {
			$normalized['columns'] = max( 1, min( 12, (int) $field['columns'] ) );
		}
		if ( 'radio' === $type && in_array( $field['card_layout'] ?? null, [ 'horizontal', 'vertical' ], true ) ) {
			$normalized['card_layout'] = $field['card_layout'];
		}
		if ( 'swatch' === $type && $image_quantities && ! empty( $field['image_quantity_zoom'] ) && array_filter( $choices, static fn( array $choice ): bool => ! empty( $choice['image'] ) ) ) {
			$normalized['image_quantity_zoom'] = true;
		}
		if ( 'swatch' === $type && ! $image_quantities && ! empty( $field['image_zoom'] ) && array_filter( $choices, static fn( array $choice ): bool => ! empty( $choice['image'] ) ) ) {
			$normalized['image_zoom'] = true;
		}
		if ( 'child_products' === $type ) {
			$normalized = array_replace( $normalized, ChildProductConfig::normalize( $field ) );
		}
		if ( ! empty( $field['change_product_image'] ) && in_array( $type, [ 'swatch', 'select', 'radio', 'checkbox' ], true ) && array_filter( $choices, static fn( array $choice ): bool => ! empty( $choice['image'] ) ) ) {
			$normalized['change_product_image'] = true;
		}
		if ( in_array( $type, [ 'paragraph', 'html', 'shortcode', 'content_image', 'section', 'calculation' ], true ) ) {
			$normalized['content'] = $content;
		}
		if ( 'calculation' === $type ) {
			$normalized['formula'] = isset( $field['formula'] ) && is_string( $field['formula'] ) && strlen( $field['formula'] ) <= 4096 ? $field['formula'] : '';
			$result_text = isset( $field['result_text'] ) && is_string( $field['result_text'] ) ? $field['result_text'] : '{result}';
			$normalized['result_text'] = substr( $result_text, 0, 500 );
			$normalized['calculation_type'] = isset( $field['calculation_type'] ) && 'price' === $field['calculation_type'] ? 'price' : 'informational';
		}
		if ( 'content_image' === $type ) {
			$raw_image_url = $field['image_url'] ?? '';
			$image_url = is_string( $raw_image_url ) ? trim( $raw_image_url ) : '';
			if ( strlen( $image_url ) > 2048 || preg_match( '/[\x00-\x20\x7F]/', $image_url ) || ! preg_match( '#^(?:https?://[^/\s]+|/(?!/))#i', $image_url ) ) {
				$image_url = '';
			}
			$raw_alt = $field['alt'] ?? '';
			$raw_alt = is_scalar( $raw_alt ) ? (string) $raw_alt : '';
			$alt = trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', strip_tags( $raw_alt ) ) );
			$normalized['image_url'] = $image_url;
			$normalized['alt'] = function_exists( 'mb_substr' ) ? mb_substr( $alt, 0, 500 ) : substr( $alt, 0, 500 );
		}
		if ( 'section' === $type ) {
			$normalized['heading'] = $heading;
		}
		if ( $has_default && ! in_array( $type, [ 'paragraph', 'html', 'shortcode', 'content_image', 'section', 'calculation' ], true ) ) {
			$normalized['default'] = $default;
		}
		if ( $multiple ) {
			$normalized['multiple'] = true;
		}
		if ( $image_quantities ) {
			$normalized['image_quantities'] = true;
		}
		if ( 'upload' === $type && ( -1 === $constraints['max_files'] || $constraints['max_files'] > 1 ) ) {
			$normalized['multiple'] = true;
		}
		if ( $repeat ) {
			$normalized['repeat'] = $repeat;
		}
		foreach ( $constraints as $key => $value ) {
			$normalized[ $key ] = $value;
		}
		if ( 'number' === $type && $has_default ) {
			$number_default = $normalized['default'];
			if ( ! is_scalar( $number_default ) || ( '' !== (string) $number_default && FieldValue::validate( $normalized, (string) $number_default, true ) ) ) {
				throw new \InvalidArgumentException( 'Number field default must satisfy its number mode and constraints.' );
			}
		}
		return $normalized;
	}

	/**
	 * Normalize a pricing block.
	 *
	 * Native quantity semantics follow WAPF's documented flat vs quantity-based
	 * modes; import mapping still requires proof of the serialized source key.
	 *  - percent, characters, value : quantity-based by default; explicit
	 *    `per_unit => false` keeps the computed add-on flat per cart line.
	 *  - formula : per-unit — scales with line quantity (imported WAPF
	 *              formulas have their qty-compensation factor stripped).
	 *  - fixed   : FLAT per line by default (`per_unit` opt-in to scale).
	 *
	 * @param array<string,mixed> $pricing Raw pricing.
	 * @return array<string,mixed>
	 */
	public static function normalize_pricing( array $pricing ): array {
		$type   = (string) ( $pricing['type'] ?? 'none' );
		$amount = is_numeric( $pricing['amount'] ?? null ) ? (float) $pricing['amount'] : 0.0;
		if ( 'formula' === $type ) {
			$amount = 0.0;
		}
		if ( ! in_array( $type, self::PRICING_TYPES, true ) ) {
			$type   = 'none';
			$amount = 0.0;
		}
		$per_unit = 'fixed' === $type
			? (bool) ( $pricing['per_unit'] ?? false )
			: ( in_array( $type, [ 'percent', 'characters', 'value' ], true )
				? (bool) ( $pricing['per_unit'] ?? true )
				: true );

		return [
			'type'        => $type,
			'amount'      => $amount,
			'formula'     => (string) ( $pricing['formula'] ?? '' ),
			'formula_raw' => (string) ( $pricing['formula_raw'] ?? '' ),
			'per_unit'    => $per_unit,
		];
	}

	/** Normalize bounded group-owned matrix/list lookup tables. */
	public static function normalize_lookup_tables( $raw_tables ): array {
		if ( ! is_array( $raw_tables ) ) {
			return [];
		}
		$tables = [];
		foreach ( $raw_tables as $name => $raw_rows ) {
			$name = (string) $name;
			if ( count( $tables ) >= 32 || ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $name ) || ! is_array( $raw_rows ) ) {
				continue;
			}
			$rows = [];
			$column_count = null;
			foreach ( array_slice( $raw_rows, 0, 5000 ) as $raw_row ) {
				if ( ! is_array( $raw_row ) || count( $raw_row ) < 2 || count( $raw_row ) > 65 ) {
					continue;
				}
				if ( null !== $column_count && count( $raw_row ) !== $column_count ) {
					continue;
				}
				$price = array_pop( $raw_row );
				if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) ) {
					continue;
				}
				$normalized_row = [];
				$valid = true;
				foreach ( $raw_row as $cell ) {
					if ( ! is_scalar( $cell ) || strlen( (string) $cell ) > 128 ) {
						$valid = false;
						break;
					}
					$normalized_row[] = trim( (string) $cell );
				}
				if ( ! $valid || in_array( '', $normalized_row, true ) ) {
					continue;
				}
				$column_count = count( $normalized_row ) + 1;
				$normalized_row[] = (float) $price;
				$rows[] = $normalized_row;
			}
			if ( $rows ) {
				$tables[ $name ] = $rows;
			}
		}
		return $tables;
	}

	/**
	 * Merge reusable site tables with group-local tables (local wins).
	 *
	 * Sites may register reusable tables with the `opf_lookup_tables` filter.
	 * The filter returns the same table-name => rows shape stored by the builder.
	 *
	 * @param array<string,mixed> $group_data Normalized group data.
	 * @return array<string,array>
	 */
	public static function lookup_tables_for_group( array $group_data ): array {
		$site_tables = function_exists( 'apply_filters' )
			? apply_filters( 'opf_lookup_tables', function_exists( 'get_option' ) ? get_option( 'opf_lookup_tables', [] ) : [] )
			: [];
		$site_tables = self::normalize_lookup_tables( $site_tables );
		$local_tables = self::normalize_lookup_tables( $group_data['lookup_tables'] ?? [] );
		return array_replace( $site_tables, $local_tables );
	}

	/**
	 * Does any field in this group carry pricing?
	 */
	public function is_priced(): bool {
		foreach ( $this->data['fields'] as $field ) {
			if ( 'none' !== $field['pricing']['type'] ) {
				return true;
			}
			foreach ( $field['choices'] as $choice ) {
				if ( 'none' !== $choice['pricing']['type'] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Find a field by id.
	 */
	public function field( string $id ): ?array {
		foreach ( $this->data['fields'] as $field ) {
			if ( $field['id'] === $id ) {
				return $field;
			}
		}
		return null;
	}
}
