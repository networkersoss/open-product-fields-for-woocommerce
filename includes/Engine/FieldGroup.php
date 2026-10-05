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
	 *
	 * 1 → 2: pricing `per_unit` semantics became WAPF-faithful — formula
	 * defaults to flat-per-line (fx) instead of forced per-unit. Schema 0/1
	 * records get per_unit=true injected on unflagged percent/formula pricing
	 * so stored data keeps its original behavior.
	 */
	public const SCHEMA = 2;

	/**
	 * Supported field types.
	 */
	public const FIELD_TYPES = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch', 'image_quantity', 'upload', 'paragraph', 'html', 'shortcode', 'content_image', 'section', 'section_end', 'child_products', 'products', 'calc', 'calculation' ];

	/**
	 * Linked-products subtypes (WAPF `products-*` field types).
	 */
	public const PRODUCTS_SUBTYPES = [ 'checkbox', 'radio', 'dropdown', 'image', 'card', 'vcard', 'card-qty', 'vcard-qty' ];

	/**
	 * Product-choice pricing types (WAPF `pricing_type` on product choices).
	 * `fixed` = the child product's own price; `none` = free child line.
	 */
	public const PRODUCTS_PRICING_TYPES = [ 'fixed', 'none' ];

	/**
	 * Supported pricing types.
	 */
	public const PRICING_TYPES = [ 'none', 'fixed', 'percent', 'formula' ];

	/**
	 * @var array<string,mixed>
	 */
	public $data;

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
		$declared_schema = $schema;

		while ( $schema < self::SCHEMA ) {
				switch ( $schema ) {
				case 0:
					// Legacy groups had no schema marker. Preserve their former
					// forced per-unit defaults before advancing the version.
					$data['schema'] = 1;
					$schema         = 1;
					break;

				case 1:
					// Schema 1 forced percent/formula pricing to per_unit.
					// Only records that explicitly declared schema 1 (stored
					// groups) get the flag injected so behavior is preserved.
					if ( in_array( $declared_schema, [ 0, 1 ], true ) ) {
						$data = self::migrate_legacy_pricing( $data );
					}
					$data['schema'] = 2;
					$schema         = 2;
					break;

				default:
					throw new \InvalidArgumentException( 'No OPF field group migration exists for schema ' . $schema . '.' );
			}
		}

		return $data;
	}

	/**
	 * Legacy schema 0/1 → 2: make old forced per_unit explicit.
	 *
	 * @param array<string,mixed> $data Persisted group data.
	 * @return array<string,mixed>
	 */
	private static function migrate_legacy_pricing( array $data ): array {
		foreach ( ( $data['fields'] ?? [] ) as $field_index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( isset( $field['pricing'] ) && is_array( $field['pricing'] ) ) {
				$type = (string) ( $field['pricing']['type'] ?? 'none' );
				if ( in_array( $type, [ 'percent', 'formula' ], true ) && ! array_key_exists( 'per_unit', $field['pricing'] ) ) {
					$data['fields'][ $field_index ]['pricing']['per_unit'] = true;
				}
			}
			foreach ( ( $field['choices'] ?? [] ) as $choice_index => $choice ) {
				if ( ! is_array( $choice ) || ! is_array( $choice['pricing'] ?? null ) ) {
					continue;
				}
				$type = (string) ( $choice['pricing']['type'] ?? 'none' );
				if ( in_array( $type, [ 'percent', 'formula' ], true ) && ! array_key_exists( 'per_unit', $choice['pricing'] ) ) {
					$data['fields'][ $field_index ]['choices'][ $choice_index ]['pricing']['per_unit'] = true;
				}
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
		$repeated_sections = [];
		foreach ( ( $data['fields'] ?? [] ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$field = self::normalize_field( $field );
			if ( 'section' === $field['type'] ) $repeated_sections[] = ! empty( $field['repeat']['enabled'] ) || in_array( true, $repeated_sections, true );
			if ( 'section_end' === $field['type'] ) array_pop( $repeated_sections );
			if ( 'upload' === $field['type'] && in_array( true, $repeated_sections, true ) ) throw new \InvalidArgumentException( 'Upload fields cannot be inside repeated sections yet.' );
			if ( 'products' === $field['type'] && in_array( true, $repeated_sections, true ) ) throw new \InvalidArgumentException( 'Products fields cannot be inside repeated sections.' );
			$fields[] = $field;
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
					'terms'    => self::normalize_rule_terms( (array) ( $rule['terms'] ?? [] ) ),
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

		// WAPF formula variables: `[var_name]` custom variables authored in the
		// builder or imported by WapfMapper. Normalized to WAPF's canonical
		// {name,default,rules[]} shape with {type,field,condition,value,variable}
		// rules (Field_Groups::sanitize parity) so authored and migrated data
		// round-trip through the WAPF Tools exporter unchanged. The key is only
		// emitted when variables exist, keeping variable-less groups canonical.
		$variables = self::normalize_variables( $data['variables'] ?? [] );
		if ( $variables ) {
			$normalized['variables'] = $variables;
		}

		// WAPF group `layout` gallery-image rules ("Change product image"):
		// enable_gallery_images + swap_type ('rules'|'last') + gallery_images[]
		// of {source,id,url,values:[{field,value}]}. Stored verbatim under
		// `layout` so OPF↔WAPF tooling can carry it 1:1; `gallery` flat key is
		// accepted as a friendly input alias.
		$layout_input = is_array( $data['layout'] ?? null ) ? $data['layout'] : [];
		foreach ( [ 'enable_gallery_images', 'swap_type', 'gallery_images' ] as $key ) {
			if ( array_key_exists( $key, $layout_input ) ) {
				$normalized['layout'][ $key ] = $layout_input[ $key ];
			}
		}
		if ( isset( $normalized['layout'] ) || isset( $data['gallery'] ) ) {
			$normalized['layout'] = self::normalize_gallery_layout( $normalized['layout'] ?? [], $data['gallery'] ?? null );
		}

		// OPF presentation-keys parity: group-owned lookup tables, numeric
		// formula variables, and bounded product-image swap rules (AND within
		// each rule, last matching rule wins — `image_rule_mode => 'last'`).
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

	/**
	 * Normalize bounded product-gallery image rules (AND within each rule,
	 * last matching rule wins).
	 *
	 * @param mixed                    $raw_rules Raw rules payload.
	 * @param array<int,array>         $fields    Normalized group fields.
	 * @return array<int,array{target_url:string,conditions:array<int,array{field:string,value:string}>}>
	 */
	public static function normalize_image_rules( $raw_rules, array $fields = [] ): array {
		if ( ! is_array( $raw_rules ) ) {
			return [];
		}
		$allowed_fields = [];
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && in_array( $field['type'] ?? '', [ 'select', 'radio', 'checkbox', 'swatch', 'toggle', 'child_products' ], true ) ) {
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
				$value    = trim( (string) $condition['value'] );
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
					$field    = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $rule['field'] ) );
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
		$site_variables  = function_exists( 'apply_filters' ) ? apply_filters( 'opf_formula_variables', $saved_variables ) : $saved_variables;
		$site_variables  = self::normalize_formula_variables( $site_variables );
		$local_variables = self::normalize_formula_variables( $group_data['formula_variables'] ?? [] );
		return array_replace( $site_variables, $local_variables );
	}

	/**
	 * Normalize the group's custom-variable block.
	 *
	 * WAPF Extended 3.1.5 `Field_Groups` sanitization parity: each variable
	 * carries `name` (plain key), a `default` value/formula and an ordered list
	 * of `{type,field,condition,value,variable}` rules. Scalars are cast to
	 * strings; malformed entries are dropped rather than persisted. Field and
	 * variable bodies stay verbatim — Calculator/WapfMapper resolve their
	 * `[field.*]`, `[var_*]`, `files()`, `lookuptable()` references.
	 *
	 * @param mixed $variables Raw variables payload.
	 * @return array<int,array{name:string,default:string,rules:array<int,array{type:string,field:string,condition:string,value:string,variable:string}>}>
	 */
	private static function normalize_variables( $variables ): array {
		$out = [];
		foreach ( (array) $variables as $variable ) {
			if ( ! is_array( $variable ) ) {
				continue;
			}
			$name = is_scalar( $variable['name'] ?? null ) ? (string) $variable['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$rules = [];
			foreach ( (array) ( $variable['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$type = is_scalar( $rule['type'] ?? null ) ? (string) $rule['type'] : 'field';
				$rules[] = [
					'type'      => in_array( $type, [ 'field', 'qty' ], true ) ? $type : 'field',
					'field'     => is_scalar( $rule['field'] ?? null ) ? (string) $rule['field'] : '',
					'condition' => is_scalar( $rule['condition'] ?? null ) ? (string) $rule['condition'] : '',
					'value'     => is_scalar( $rule['value'] ?? null ) ? (string) $rule['value'] : '',
					'variable'  => is_scalar( $rule['variable'] ?? null ) ? (string) $rule['variable'] : '',
				];
			}
			$out[] = [
				'name'    => $name,
				'default' => is_scalar( $variable['default'] ?? null ) ? (string) $variable['default'] : '',
				'rules'   => $rules,
			];
		}
		return $out;
	}

	/**
	 * Normalize the WAPF `layout` gallery-image block.
	 *
	 * @param array<string,mixed> $layout   Raw layout fragment.
	 * @param array<string,mixed>|null $alias Optional flat `gallery` alias.
	 * @return array<string,mixed>
	 */
	private static function normalize_gallery_layout( array $layout, ?array $alias ): array {
		if ( is_array( $alias ) ) {
			$layout = array_merge( $layout, [
				'enable_gallery_images' => $alias['enabled'] ?? $alias['enable_gallery_images'] ?? $layout['enable_gallery_images'] ?? null,
				'swap_type'             => $alias['swap_type'] ?? $layout['swap_type'] ?? null,
				'gallery_images'        => $alias['images'] ?? $alias['gallery_images'] ?? $layout['gallery_images'] ?? null,
			] );
		}

		$enabled = $layout['enable_gallery_images'] ?? false;
		$out = [
			'enable_gallery_images' => in_array( $enabled, [ true, 1, '1' ], true ),
			'swap_type'             => 'rules',
		];
		if ( isset( $layout['swap_type'] ) ) {
			$out['swap_type'] = 'last' === $layout['swap_type'] ? 'last' : 'rules';
		}

		$out['gallery_images'] = [];
		foreach ( (array) ( $layout['gallery_images'] ?? [] ) as $gallery_image ) {
			if ( ! is_array( $gallery_image ) ) {
				continue;
			}
			$values = [];
			foreach ( (array) ( $gallery_image['values'] ?? [] ) as $value ) {
				if ( ! is_array( $value ) || ! isset( $value['field'] ) ) {
					continue;
				}
				$values[] = [
					'field' => (string) $value['field'],
					'value' => (string) ( $value['value'] ?? '*' ),
				];
			}
			$source = (string) ( $gallery_image['source'] ?? 'upload' );
			$out['gallery_images'][] = [
				'source' => in_array( $source, [ 'upload', 'product' ], true ) ? $source : 'upload',
				'url'    => is_scalar( $gallery_image['url'] ?? null ) ? (string) $gallery_image['url'] : '',
				'id'     => is_scalar( $gallery_image['id'] ?? null ) ? (string) $gallery_image['id'] : '',
				'values' => $values,
			];
		}

		return $out;
	}

	/**
	 * Normalize a single field.
	 *
	 * @param array<string,mixed> $field Raw field.
	 * @return array<string,mixed>
	 */
	public static function normalize_field( array $field ): array {
		$type = (string) ( $field['type'] ?? 'text' );
		// WAPF writes products fields as type `products-<subtype>`; accept that
		// spelling as well as type `products` + separate `subtype` key.
		$products_subtype = '';
		if ( str_starts_with( $type, 'products-' ) ) {
			$products_subtype = substr( $type, strlen( 'products-' ) );
			$type             = 'products';
		}
		if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
			$type = 'text';
		}
		if ( 'products' === $type ) {
			if ( '' === $products_subtype ) {
				$products_subtype = (string) ( $field['subtype'] ?? 'checkbox' );
				$products_subtype = preg_replace( '/^products-/', '', $products_subtype );
			}
			if ( ! in_array( $products_subtype, self::PRODUCTS_SUBTYPES, true ) ) {
				$products_subtype = 'checkbox';
			}
		}
		$products_qty_subtype = in_array( $products_subtype, [ 'card-qty', 'vcard-qty' ], true );

		$choices = [];
		foreach ( ( $field['choices'] ?? [] ) as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}
			if ( 'products' === $type ) {
				// Manual product choices reference a real product; the label
				// falls back to the product name at render time.
				$product_id = $choice['product_id'] ?? null;
				if ( ( is_int( $product_id ) || ( is_string( $product_id ) && ctype_digit( $product_id ) ) ) && (int) $product_id > 0 ) {
					$choice['product_id'] = (int) $product_id;
				} else {
					continue;
				}
			} elseif ( ( $choice['label'] ?? '' ) === '' ) {
				continue;
			}
			$pricing   = is_array( $choice['pricing'] ?? null ) ? $choice['pricing'] : [];
			$disabled = (bool) ( $choice['disabled'] ?? false );
			$normalized_choice = [
				'slug'     => (string) ( $choice['slug'] ?? '' ),
				'label'    => (string) ( $choice['label'] ?? '' ),
				'selected' => ! $disabled && (bool) ( $choice['selected'] ?? false ),
				'disabled' => $disabled,
				'pricing'  => self::normalize_pricing( $pricing ),
			];
			// WAPF choice weight (options.choices[].options.weight): a small
			// expression evaluated per selection — [qty] and [x] tokens.
			$choice_weight = self::normalize_weight( $choice['weight'] ?? ( $choice['options']['weight'] ?? null ) );
			if ( null !== $choice_weight ) {
				$normalized_choice['weight'] = $choice_weight;
			}
			if ( 'image_quantity' === ( $field['type'] ?? '' ) || ( 'products' === $type && $products_qty_subtype ) ) {
				$quantity_settings = is_array( $choice['quantity'] ?? null ) ? $choice['quantity'] : [];
				$minimum = max( 0, min( 999999, (int) ( $quantity_settings['min'] ?? 0 ) ) );
				$maximum = max( $minimum, min( 999999, (int) ( $quantity_settings['max'] ?? 999999 ) ) );
				$normalized_choice['quantity'] = [
					'default' => max( $minimum, min( $maximum, (int) ( $quantity_settings['default'] ?? 0 ) ) ),
					'min' => $minimum,
					'max' => $maximum,
				];
			}
			if ( 'products' === $type ) {
				$normalized_choice['product_id'] = (int) $choice['product_id'];
				$pricing_type = (string) ( $choice['pricing_type'] ?? 'fixed' );
				$normalized_choice['pricing_type'] = in_array( $pricing_type, self::PRODUCTS_PRICING_TYPES, true ) ? $pricing_type : 'fixed';
				if ( '' === $normalized_choice['slug'] ) {
					$normalized_choice['slug'] = 'p' . $normalized_choice['product_id'];
				}
			}
			$image = $choice['image'] ?? null;
			if ( is_string( $image ) ) {
				$image = trim( $image );
				if ( strlen( $image ) <= 2048 && ! preg_match( '/[\x00-\x20\x7F]/', $image ) && preg_match( '#^(?:https?://[^/\s]+|/(?!/))#i', $image ) ) {
					$normalized_choice['image'] = $image;
				}
			}
			$image_id = $choice['image_id'] ?? null;
			if ( ( is_int( $image_id ) || ( is_string( $image_id ) && ctype_digit( $image_id ) ) ) && (int) $image_id > 0 ) {
				$normalized_choice['image_id'] = (int) $image_id;
			}
			$color = $choice['color'] ?? null;
			if ( is_string( $color ) && preg_match( '/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{5})?$/', $color ) ) {
				$normalized_choice['color'] = strtoupper( $color );
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
			$choices[] = $normalized_choice;
		}

		$conditionals = [];
		foreach ( ( $field['conditionals'] ?? [] ) as $conditional ) {
			if ( ! is_array( $conditional ) ) {
				continue;
			}
			$rules = [];
			foreach ( ( $conditional['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				// Variation-scoped subjects (WAPF `product_var`/`patts` field
				// conditions) carry `subject`/`terms` instead of `field`/`value`.
				$subject = (string) ( $rule['subject'] ?? '' );
				if ( in_array( $subject, Evaluator::VARIATION_SUBJECTS, true ) ) {
					$rules[] = [
						'subject'  => $subject,
						'operator' => 'not_in' === ( $rule['operator'] ?? 'in' ) ? 'not_in' : 'in',
						'terms'    => self::normalize_rule_terms( (array) ( $rule['terms'] ?? [] ) ),
					];
					continue;
				}
				if ( empty( $rule['field'] ) ) {
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

		if ( 'upload' === $type ) {
			$upload_pricing = $field['pricing'] ?? [];
			if ( ! is_array( $upload_pricing ) || 'none' !== ( $upload_pricing['type'] ?? 'none' ) ) {
				throw new \InvalidArgumentException( 'Upload pricing is not supported yet.' );
			}
		}
		$pricing = self::normalize_pricing( is_array( $field['pricing'] ?? null ) ? $field['pricing'] : [] );
		$calc_type = 'default';
		$calc_formula = '';
		$calc_result_format = 'number';
		$calc_result_text = '{result}';
		if ( 'calc' === $type ) {
			// WAPF Extended `calc`: an informational computed value (display
			// only) or a `cost` calculation that enters the pricing pipeline as
			// a signed formula addon. The formula may reference other fields via
			// `[field.ID]`/`[price.ID]` and runs in the shared Evaluator space.
			$raw_calc_type = (string) ( $field['calc_type'] ?? 'default' );
			$calc_type = in_array( $raw_calc_type, [ 'default', 'cost' ], true ) ? $raw_calc_type : 'default';
			$formula = is_scalar( $field['formula'] ?? null ) ? trim( (string) $field['formula'] ) : '';
			// WAPF writes the line-space options total token; OPF's evaluator
			// uses `[addons]` (identical expansion).
			$formula = str_replace( '[options_total]', '[addons]', $formula );
			$calc_formula = strlen( $formula ) <= 4096 ? $formula : '';
			$raw_format = (string) ( $field['result_format'] ?? 'number' );
			// WAPF's empty string means "format as number"; `none` is the
			// explicit opt-out exposed by the editor.
			$calc_result_format = 'none' === $raw_format ? 'none' : 'number';
			$text = is_scalar( $field['result_text'] ?? null ) ? trim( (string) $field['result_text'] ) : '';
			$calc_result_text = '' === $text ? '{result}' : $text;
			// Cost calcs price through the existing signed-formula pipeline;
			// default calcs never price. The formula is stored verbatim
			// (formula === formula_raw) so the per-line WAPF fx semantics hold.
			$pricing = 'cost' === $calc_type
				? self::normalize_pricing( [
					'type'        => 'formula',
					'formula'     => $calc_formula,
					'formula_raw' => $calc_formula,
					'per_unit'    => false,
				] )
				: self::normalize_pricing( [] );
		}

		// Field ids become input name fragments and DOM hooks: restrict to a
		// conservative slug charset regardless of the source.
		$field_id = (string) ( $field['id'] ?? '' );
		$field_id = strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $field_id ) );
		if ( '' === $field_id ) {
			$field_id = 'field';
		}

		$normalized = [
			'id'           => $field_id,
			'label'        => (string) ( $field['label'] ?? '' ),
			'description'  => (string) ( $field['description'] ?? '' ),
			'type'         => $type,
			'required'     => (bool) ( $field['required'] ?? false ),
			'width'        => max( 25, min( 100, (int) ( $field['width'] ?? 100 ) ) ),
			'css_class'    => (string) ( $field['css_class'] ?? '' ),
			'placeholder'  => (string) ( $field['placeholder'] ?? '' ),
			'choices'      => $choices,
			'pricing'      => $pricing,
			'conditionals' => $conditionals,
		];
		// Descriptions render inline unless the editor explicitly chooses the
		// tooltip presentation; anything else falls back to inline.
		$normalized['description_presentation'] = in_array( $field['description_presentation'] ?? 'inline', [ 'inline', 'tooltip' ], true )
			? ( $field['description_presentation'] ?? 'inline' )
			: 'inline';
		// WAPF per-field visibility flags (options.hide_* in the WAPF
		// schema): the value stays stored and priced; only the
		// customer-facing display on that surface is suppressed.
		foreach ( [ 'hide_cart', 'hide_checkout', 'hide_order' ] as $visibility_option ) {
			$normalized[ $visibility_option ] = ! empty( $field[ $visibility_option ] ) || ! empty( $field['options'][ $visibility_option ] );
		}
		// WAPF "Weight formula" (options.weight_formula): verbatim formula string
		// resolved at cart time; bounded to keep payloads sane.
		if ( isset( $field['weight_formula'] ) && is_string( $field['weight_formula'] ) && strlen( $field['weight_formula'] ) <= 4096 && '' !== trim( $field['weight_formula'] ) ) {
			$normalized['weight_formula'] = trim( $field['weight_formula'] );
		}
		// WAPF "Prefill from URL" parameter name (options.prefill_param): a
		// bounded, allow-listed query key — anything else is dropped.
		if ( isset( $field['prefill_param'] ) && is_string( $field['prefill_param'] ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $field['prefill_param'] ) ) {
			$normalized['prefill_param'] = $field['prefill_param'];
		}
		// iOS-style switch rendering is offered on toggle and checkbox fields.
		if ( in_array( $type, [ 'toggle', 'checkbox' ], true ) && ! empty( $field['switch_control'] ) ) {
			$normalized['switch_control'] = true;
		}
		// Card presentation is a radio-field layout option.
		if ( 'radio' === $type && in_array( $field['card_layout'] ?? null, [ 'horizontal', 'vertical' ], true ) ) {
			$normalized['card_layout'] = $field['card_layout'];
		}
		// WAPF "change product image": a choice's image swaps the gallery image.
		// Only meaningful on image-bearing single/multi choice controls.
		if ( ! empty( $field['change_product_image'] ) && in_array( $type, [ 'swatch', 'select', 'radio', 'checkbox' ], true ) && array_filter( $choices, static function ( array $choice ): bool {
			return ! empty( $choice['image'] ) || ! empty( $choice['image_id'] );
		} ) ) {
			$normalized['change_product_image'] = true;
		}
		// WAPF "Extra weight" field option (options.weight): kept verbatim for
		// Calculator::field_weight. Dropping it on save was the WAPF-COMMERCE-
		// WEIGHT data-loss gap.
		$field_weight = self::normalize_weight( $field['weight'] ?? ( $field['options']['weight'] ?? null ) );
		if ( null !== $field_weight ) {
			$normalized['weight'] = $field_weight;
		}
		if ( 'toggle' === $type ) {
			if ( array_key_exists( 'message', $field ) ) {
				if ( ! is_scalar( $field['message'] ) ) {
					throw new \InvalidArgumentException( 'Toggle message must be a scalar value.' );
				}
				$normalized['message'] = trim( strip_tags( (string) $field['message'] ) );
			}
			if ( array_key_exists( 'default', $field ) ) {
				if ( ! in_array( $field['default'], [ true, false, 1, 0, '1', '0' ], true ) ) {
					throw new \InvalidArgumentException( 'Toggle default must be boolean.' );
				}
				$normalized['default'] = in_array( $field['default'], [ true, 1, '1' ], true ) ? '1' : '0';
			}
		}
		if ( 'url' === $type && array_key_exists( 'default', $field ) ) {
			if ( ! is_scalar( $field['default'] ) ) {
				throw new \InvalidArgumentException( 'URL default must be a scalar value.' );
			}
			$url_default = FieldValue::sanitize( $field, $field['default'] );
			if ( null !== $url_default && FieldValue::validate( [ 'type' => 'url', 'label' => 'URL default' ], $url_default, true ) ) {
				throw new \InvalidArgumentException( 'URL default must be a valid URL.' );
			}
			$normalized['default'] = $url_default ?? '';
		}
		if ( 'text' === $type && array_key_exists( 'default', $field ) ) {
			if ( ! is_scalar( $field['default'] ) ) {
				throw new \InvalidArgumentException( 'Text default must be a scalar value.' );
			}
			$normalized['default'] = trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $field['default'] ) ) );
		}
		// WAPF exposes "Default value" for every scalar input type; keep
		// parity for email/number/textarea (text/url/toggle handled above).
		if ( in_array( $type, [ 'email', 'number' ], true ) && array_key_exists( 'default', $field ) ) {
			if ( ! is_scalar( $field['default'] ) ) {
				throw new \InvalidArgumentException( ucfirst( $type ) . ' default must be a scalar value.' );
			}
			$normalized['default'] = trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $field['default'] ) ) );
		}
		if ( 'textarea' === $type && array_key_exists( 'default', $field ) ) {
			if ( ! is_scalar( $field['default'] ) ) {
				throw new \InvalidArgumentException( 'Textarea default must be a scalar value.' );
			}
			// Textareas may legitimately hold multi-line defaults.
			$normalized['default'] = trim( strip_tags( (string) $field['default'] ) );
		}
		if ( 'paragraph' === $type ) {
			$normalized['content'] = is_scalar( $field['content'] ?? null ) ? (string) $field['content'] : '';
			$content_format = $field['content_format'] ?? 'plain';
			if ( ! in_array( $content_format, [ 'plain', 'html' ], true ) ) {
				throw new \InvalidArgumentException( 'Paragraph content format must be plain or html.' );
			}
			$process_shortcodes = $field['process_shortcodes'] ?? false;
			if ( ! in_array( $process_shortcodes, [ true, false, 0, 1, '0', '1' ], true ) ) {
				throw new \InvalidArgumentException( 'Paragraph shortcode processing setting must be boolean.' );
			}
			$normalized['content_format'] = $content_format;
			$normalized['process_shortcodes'] = 'html' === $content_format && in_array( $process_shortcodes, [ true, 1, '1' ], true );
			$normalized['required'] = false;
			$normalized['choices'] = [];
			$normalized['pricing'] = self::normalize_pricing( [] );
		}
		if ( 'calc' === $type ) {
			$normalized['required'] = false;
			$normalized['choices'] = [];
			$normalized['calc_type'] = $calc_type;
			$normalized['formula'] = $calc_formula;
			$normalized['result_format'] = $calc_result_format;
			$normalized['result_text'] = $calc_result_text;
		}
		if ( 'upload' === $type ) {
			$multiple = $field['multiple'] ?? false;
			if ( ! in_array( $multiple, [ true, false, 0, 1, '0', '1' ], true ) ) throw new \InvalidArgumentException( 'Upload multiple setting must be boolean.' );
			$normalized['multiple'] = in_array( $multiple, [ true, 1, '1' ], true );
			$size = $field['max_size'] ?? 1;
			if ( ! is_numeric( $size ) || ! is_finite( (float) $size ) || (float) $size < 0 ) {
				throw new \InvalidArgumentException( 'Upload maximum size must be non-negative MB.' );
			}
			$normalized['max_size'] = (float) $size;
			$types = $field['accepted_types'] ?? [];
			if ( is_string( $types ) ) {
				$types = preg_split( '/[\s,]+/', $types, -1, PREG_SPLIT_NO_EMPTY );
			}
			if ( ! is_array( $types ) ) {
				throw new \InvalidArgumentException( 'Upload accepted types must contain file extensions.' );
			}
			$extensions = [];
			foreach ( $types as $extension ) {
				if ( ! is_string( $extension ) || ! preg_match( '/^\.?[a-zA-Z0-9]+(?:\|[a-zA-Z0-9]+)*$/', $extension ) ) {
					throw new \InvalidArgumentException( 'Upload accepted types must contain file extensions.' );
				}
				$extensions = array_merge( $extensions, explode( '|', strtolower( ltrim( $extension, '.' ) ) ) );
			}
			$normalized['accepted_types'] = array_values( array_unique( $extensions ) );
			// WAPF image-upload add-on file-count limits: -1 = unlimited max;
			// min may not exceed a finite max.
			if ( array_key_exists( 'max_files', $field ) ) {
				$raw_max_files = (int) $field['max_files'];
				$normalized['max_files'] = -1 === $raw_max_files ? -1 : max( 1, min( 20, $raw_max_files ) );
			}
			if ( array_key_exists( 'min_files', $field ) ) {
				$normalized['min_files'] = max( 0, min( 20, (int) $field['min_files'] ) );
			}
			if ( isset( $normalized['max_files'], $normalized['min_files'] ) && -1 !== $normalized['max_files'] && $normalized['min_files'] > $normalized['max_files'] ) {
				throw new \InvalidArgumentException( 'Minimum file count cannot exceed maximum file count.' );
			}
			if ( isset( $normalized['max_files'] ) && ( -1 === $normalized['max_files'] || $normalized['max_files'] > 1 ) ) {
				$normalized['multiple'] = true;
			}
			// Minimum file size in MB (must fit inside the configured max).
			if ( isset( $field['min_size_mb'] ) && is_numeric( $field['min_size_mb'] ) && (float) $field['min_size_mb'] > 0 ) {
				$normalized['min_size_mb'] = min( 100.0, (float) $field['min_size_mb'] );
				if ( $normalized['min_size_mb'] > $normalized['max_size'] ) {
					throw new \InvalidArgumentException( 'Minimum file size cannot exceed maximum file size.' );
				}
			}
			// Minimum image dimensions in pixels (positive values only).
			foreach ( [ 'min_width', 'min_height' ] as $dimension ) {
				if ( isset( $field[ $dimension ] ) && is_numeric( $field[ $dimension ] ) && (int) $field[ $dimension ] > 0 ) {
					$normalized[ $dimension ] = min( 20000, (int) $field[ $dimension ] );
				}
			}
			// Client-side auto-resize requires at least one pixel bound.
			if ( in_array( $field['auto_resize'] ?? false, [ true, 1, '1' ], true ) ) {
				foreach ( [ 'max_width', 'max_height' ] as $dimension ) {
					if ( isset( $field[ $dimension ] ) && is_numeric( $field[ $dimension ] ) && (int) $field[ $dimension ] > 0 ) {
						$normalized[ $dimension ] = min( 20000, (int) $field[ $dimension ] );
					}
				}
				if ( empty( $normalized['max_width'] ) && empty( $normalized['max_height'] ) ) {
					throw new \InvalidArgumentException( 'Automatic image resize requires a maximum width or height.' );
				}
				$normalized['auto_resize'] = true;
			}
			// In-browser image editor: optional (customer chooses) or forced.
			$editor_mode = (string) ( $field['image_editor_mode'] ?? '' );
			if ( in_array( $editor_mode, [ 'optional', 'forced' ], true ) ) {
				$normalized['image_editor_mode'] = $editor_mode;
				foreach ( [ 'image_editor_crop', 'image_editor_rotate', 'image_editor_flip', 'image_editor_resize' ] as $editor_option ) {
					$normalized[ $editor_option ] = ! array_key_exists( $editor_option, $field ) || in_array( $field[ $editor_option ], [ true, 1, '1', 'yes', 'true' ], true );
				}
				$aspect_ratio = (string) ( $field['image_editor_aspect_ratio'] ?? 'free' );
				$normalized['image_editor_aspect_ratio'] = in_array( $aspect_ratio, [ 'free', '1:1', '4:3', '3:2', '16:9', '2:3', '9:16' ], true ) ? $aspect_ratio : 'free';
			}
			// MIME allow-list (WAPF `allowed_types`), sanitized per entry.
			if ( isset( $field['allowed_types'] ) && is_array( $field['allowed_types'] ) ) {
				$normalized['allowed_types'] = array_values( array_filter( array_map( static function ( $allowed ): string {
					return strtolower( preg_replace( '/[^a-z0-9.*\/-]/i', '', (string) $allowed ) );
				}, $field['allowed_types'] ) ) );
			}
		}
		if ( 'image_quantity' === $type ) {
			$normalized['multiple'] = false;
			$normalized['required'] = false;
			$normalized['pricing'] = self::normalize_pricing( [] );
			// WAPF `large_image` on image-swatch-qty (3.2.1 exposes it in the
			// builder; 3.1.5 already emits data-zoom-url for the serialized
			// option) → OPF canonical `image_zoom`. Both spellings accepted.
			$image_zoom = $field['image_zoom'] ?? $field['large_image'] ?? ( $field['options']['large_image'] ?? false );
			if ( ! in_array( $image_zoom, [ true, false, 0, 1, '0', '1' ], true ) ) {
				throw new \InvalidArgumentException( 'Image quantity zoom setting must be boolean.' );
			}
			$normalized['image_zoom'] = in_array( $image_zoom, [ true, 1, '1' ], true );
			foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
				if ( ! array_key_exists( $key, $field ) || '' === $field[ $key ] || null === $field[ $key ] ) {
					continue;
				}
				$normalized[ $key ] = self::bounded_integer( $field[ $key ], 0, 999999, 'Image quantity ' . $key );
			}
			if ( isset( $normalized['min_choices'], $normalized['max_choices'] ) && $normalized['min_choices'] > $normalized['max_choices'] ) {
				throw new \InvalidArgumentException( 'Image quantity minimum total cannot exceed maximum total.' );
			}
		}
		if ( 'products' === $type ) {
			$normalized['subtype'] = $products_subtype;
			// Children are native cart lines priced by their own product data;
			// the field itself never carries an addon price.
			$normalized['pricing'] = self::normalize_pricing( [] );
			$selection = (string) ( $field['product_selection'] ?? 'manual' );
			$normalized['product_selection'] = in_array( $selection, [ 'manual', 'category' ], true ) ? $selection : 'manual';
			$qty_method = (string) ( $field['qty_method'] ?? 'one' );
			$normalized['qty_method'] = in_array( $qty_method, [ 'one', 'parent' ], true ) ? $qty_method : 'one';
			foreach ( [ 'hide_cart', 'hide_checkout', 'hide_order' ] as $key ) {
				$normalized[ $key ] = (bool) ( $field[ $key ] ?? false );
			}
			$image_zoom = $field['image_zoom'] ?? false;
			if ( ! in_array( $image_zoom, [ true, false, 0, 1, '0', '1' ], true ) ) {
				throw new \InvalidArgumentException( 'Products image zoom setting must be boolean.' );
			}
			$normalized['image_zoom'] = in_array( $image_zoom, [ true, 1, '1' ], true );
			if ( 'category' === $normalized['product_selection'] ) {
				$query = is_array( $field['product_query'] ?? null ) ? $field['product_query'] : [];
				$sort  = (string) ( $query['sort'] ?? 'date_desc' );
				$pricing_type = (string) ( $query['pricing_type'] ?? 'fixed' );
				$normalized['product_query'] = [
					'query_id'     => max( 0, (int) ( $query['query_id'] ?? 0 ) ),
					'query_label'  => (string) ( $query['query_label'] ?? '' ),
					'limit'        => max( 1, min( 50, (int) ( $query['limit'] ?? 10 ) ) ),
					'sort'         => in_array( $sort, [ 'name_asc', 'name_desc', 'date_desc', 'date_asc' ], true ) ? $sort : 'date_desc',
					'pricing_type' => in_array( $pricing_type, self::PRODUCTS_PRICING_TYPES, true ) ? $pricing_type : 'fixed',
				];
				$normalized['choices'] = [];
			}
			if ( $products_qty_subtype ) {
				foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
					if ( ! array_key_exists( $key, $field ) || '' === $field[ $key ] || null === $field[ $key ] ) {
						continue;
					}
					$normalized[ $key ] = self::bounded_integer( $field[ $key ], 0, 999999, 'Products ' . $key );
				}
				if ( isset( $normalized['min_choices'], $normalized['max_choices'] ) && $normalized['min_choices'] > $normalized['max_choices'] ) {
					throw new \InvalidArgumentException( 'Products minimum total quantity cannot exceed maximum total quantity.' );
				}
				$display = (string) ( $field['display'] ?? 'default' );
				$normalized['display'] = in_array( $display, [ 'default', 'plus_min' ], true ) ? $display : 'default';
			}
			if ( 'image' === $products_subtype ) {
				$label_pos = (string) ( $field['label_pos'] ?? 'tooltip' );
				if ( ! in_array( $label_pos, [ 'default', 'out', 'hide', 'tooltip' ], true ) ) {
					throw new \InvalidArgumentException( 'Products image label position must be default, out, hide, or tooltip.' );
				}
				$normalized['label_pos'] = $label_pos;
				$normalized['item_width'] = self::bounded_integer( $field['item_width'] ?? 60, 30, 300, 'Products image width' );
				// WAPF `large_image` is the Products-image hover zoom option. Keep
				// it separate from OPF's `image_zoom`, which swaps the main gallery.
				$large_image = $field['large_image'] ?? false;
				if ( ! in_array( $large_image, [ true, false, 0, 1, '0', '1', 'true', 'false' ], true ) ) {
					throw new \InvalidArgumentException( 'Products large image setting must be boolean.' );
				}
				$normalized['large_image'] = in_array( $large_image, [ true, 1, '1', 'true' ], true );
			}
			if ( in_array( $products_subtype, [ 'card', 'vcard', 'card-qty', 'vcard-qty' ], true ) ) {
				$normalized['items_per_row']        = self::bounded_integer( $field['items_per_row'] ?? 2, 1, 4, 'Products desktop columns' );
				$normalized['items_per_row_tablet'] = self::bounded_integer( $field['items_per_row_tablet'] ?? 1, 1, 4, 'Products tablet columns' );
				$normalized['items_per_row_mobile'] = self::bounded_integer( $field['items_per_row_mobile'] ?? 1, 1, 4, 'Products mobile columns' );
				foreach ( [ 'incl_img', 'incl_desc' ] as $key ) {
					$value = $field[ $key ] ?? true;
					if ( ! in_array( $value, [ true, false, 0, 1, '0', '1' ], true ) ) {
						throw new \InvalidArgumentException( sprintf( 'Products %s must be boolean.', $key ) );
					}
					$normalized[ $key ] = in_array( $value, [ true, 1, '1' ], true );
				}
				foreach ( [ 'slot_1', 'slot_2', 'slot_3' ] as $key ) {
					$slot = (string) ( $field[ $key ] ?? 'none' );
					$normalized[ $key ] = in_array( $slot, [ 'none', 'price', 'stock', 'link' ], true ) ? $slot : 'none';
				}
				if ( in_array( $products_subtype, [ 'vcard', 'vcard-qty' ], true ) ) {
					$fit = (string) ( $field['img_fit'] ?? 'cover' );
					$normalized['img_fit'] = in_array( $fit, [ 'cover', 'contain' ], true ) ? $fit : 'cover';
				}
			}
		}
		if ( 'content_image' === $type ) {
			$normalized['required'] = false;
			$normalized['choices'] = [];
			$normalized['pricing'] = self::normalize_pricing( [] );
			$image_url = is_string( $field['image_url'] ?? null ) ? trim( $field['image_url'] ) : '';
			if ( strlen( $image_url ) > 2048 || preg_match( '/[\x00-\x20\x7F]/', $image_url ) || ! preg_match( '#^(?:https?://[^/\s]+|/(?!/))#i', $image_url ) ) {
				$image_url = '';
			}
			$normalized['image_url'] = $image_url;
			$image_id = filter_var( $field['image_id'] ?? null, FILTER_VALIDATE_INT );
			if ( false !== $image_id && null !== $image_id && $image_id > 0 ) {
				$normalized['image_id'] = $image_id;
			}
			$raw_alt = $field['alt'] ?? '';
			$raw_alt = is_scalar( $raw_alt ) ? (string) $raw_alt : '';
			$alt     = trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', strip_tags( $raw_alt ) ) );
			$normalized['alt'] = function_exists( 'mb_substr' ) ? mb_substr( $alt, 0, 500 ) : substr( $alt, 0, 500 );
		}
		if ( in_array( $type, [ 'section', 'section_end' ], true ) ) {
			$normalized['required'] = false;
			$normalized['choices'] = [];
			$normalized['pricing'] = self::normalize_pricing( [] );
		}
		if ( 'section' === $type ) {
			// WAPF section fields carry a heading plus descriptive content.
			$normalized['heading'] = (string) ( $field['heading'] ?? $field['label'] ?? '' );
			$normalized['content'] = is_scalar( $field['content'] ?? null ) ? (string) $field['content'] : '';
		}
		$repeat = RepeaterField::normalize( $field['repeat'] ?? [] );
		if ( 'products' === $type && $repeat ) {
			throw new \InvalidArgumentException( 'Products fields cannot repeat.' );
		}
		if ( 'upload' === $type && $repeat ) {
			throw new \InvalidArgumentException( 'Upload fields cannot repeat yet.' );
		}
		if ( 'image_quantity' === $type && $repeat ) {
			throw new \InvalidArgumentException( 'Image quantity fields cannot repeat.' );
		}
		if ( 'section_end' === $type && $repeat ) {
			throw new \InvalidArgumentException( 'A section-end marker cannot repeat.' );
		}
		if ( 'calc' === $type && $repeat ) {
			// OPF's calc is computed per group; WAPF clone/repeat execution is
			// not ported, so the repeat marker is dropped (the field still
			// renders and computes once). WapfMapper flags this for review.
			$repeat = [];
		}
		if ( $repeat ) {
			$normalized['repeat'] = $repeat;
		}
		if ( 'swatch' === $type ) {
			$style = $field['swatch_style'] ?? 'text';
			if ( ! in_array( $style, [ 'text', 'image', 'color' ], true ) ) {
				throw new \InvalidArgumentException( 'Swatch style must be text, image, or color.' );
			}
			$has_image_choice = (bool) array_filter( $choices, static function ( array $choice ): bool {
				return ! empty( $choice['image'] ) || ! empty( $choice['image_id'] );
			} );
			if ( 'image' === $style || ( 'text' === $style && $has_image_choice ) ) {
				// WAPF parity: a text swatch carrying choice images stays a text
				// swatch (WAPF has no swatch_style); the image-swatch grid keys
				// only apply to the explicit image style.
				$normalized['swatch_style'] = 'image' === $style ? 'image' : 'text';
				if ( 'image' === $style ) {
					$label_pos = $field['label_pos'] ?? 'out';
					if ( ! in_array( $label_pos, [ 'default', 'out', 'hide', 'tooltip' ], true ) ) {
						throw new InvalidArgumentException( 'Image swatch label position must be default, out, hide, or tooltip.' );
					}
					$grid_layout = $field['grid_layout'] ?? 'fixed';
					if ( ! in_array( $grid_layout, [ 'fixed', 'flexible' ], true ) ) {
						throw new InvalidArgumentException( 'Image swatch grid layout must be fixed or flexible.' );
					}
					$normalized['label_pos'] = $label_pos;
					$normalized['grid_layout'] = $grid_layout;
					$normalized['item_width'] = self::bounded_integer( $field['item_width'] ?? 68, 20, 300, 'Image swatch width' );
					$normalized['items_per_row'] = self::bounded_integer( $field['items_per_row'] ?? 3, 1, 15, 'Image swatch desktop columns' );
					$normalized['items_per_row_tablet'] = self::bounded_integer( $field['items_per_row_tablet'] ?? 3, 1, 10, 'Image swatch tablet columns' );
					$normalized['items_per_row_mobile'] = self::bounded_integer( $field['items_per_row_mobile'] ?? 3, 1, 10, 'Image swatch mobile columns' );
				}
				$image_zoom = $field['image_zoom'] ?? false;
				if ( ! in_array( $image_zoom, [ true, false, 0, 1, '0', '1' ], true ) ) {
					throw new InvalidArgumentException( 'Image swatch zoom setting must be boolean.' );
				}
				if ( in_array( $image_zoom, [ true, 1, '1' ], true ) && $has_image_choice ) {
					$normalized['image_zoom'] = true;
				}
			} elseif ( 'color' === $style ) {
				$normalized['swatch_style'] = 'color';
				$layout = $field['color_layout'] ?? 'circle';
				if ( ! in_array( $layout, [ 'square', 'rounded', 'circle' ], true ) ) {
					throw new \InvalidArgumentException( 'Color swatch layout must be square, rounded, or circle.' );
				}
				$label_pos = $field['color_label_pos'] ?? 'tooltip';
				if ( ! in_array( $label_pos, [ 'default', 'hide', 'tooltip' ], true ) ) {
					throw new \InvalidArgumentException( 'Color swatch label position must be default, hide, or tooltip.' );
				}
				$normalized['color_layout'] = $layout;
				$normalized['color_label_pos'] = $label_pos;
				$normalized['color_size'] = self::bounded_integer( $field['color_size'] ?? 30, 5, 500, 'Color swatch size' );
			} else {
				$normalized['swatch_style'] = 'text';
			}
			$multiple = $field['multiple'] ?? false;
			if ( ! in_array( $multiple, [ true, false, 0, 1, '0', '1' ], true ) ) {
				throw new \InvalidArgumentException( 'Swatch multiple setting must be boolean.' );
			}
			$normalized['multiple'] = in_array( $multiple, [ true, 1, '1' ], true );
			if ( $normalized['multiple'] ) {
				foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
					if ( ! array_key_exists( $key, $field ) || '' === $field[ $key ] || null === $field[ $key ] ) {
						continue;
					}
					$normalized[ $key ] = self::bounded_integer( $field[ $key ], 1, 10000, 'Swatch ' . $key );
				}
				if ( isset( $normalized['min_choices'], $normalized['max_choices'] ) && $normalized['min_choices'] > $normalized['max_choices'] ) {
					throw new \InvalidArgumentException( 'Swatch minimum choices cannot exceed maximum choices.' );
				}
			}
		}

		if ( 'checkbox' === $type ) {
			// WAPF 3.2 changelog documents `columns` but not its upper bound or
			// responsive breakpoints; retain any explicit positive platform integer.
			if ( array_key_exists( 'columns', $field ) ) {
				$normalized['columns'] = self::positive_integer( $field['columns'], 'Checkbox columns' );
			}
			// WAPF `checkboxes` serializes min_choices/max_choices as flat keys
			// (class-field-groups.php:284-290) and enforces the max server-side.
			// Mirror the multi-swatch bounds (int 1..10000, min <= max).
			foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
				if ( ! array_key_exists( $key, $field ) || '' === $field[ $key ] || null === $field[ $key ] ) {
					continue;
				}
				$normalized[ $key ] = self::bounded_integer( $field[ $key ], 1, 10000, 'Checkbox ' . $key );
			}
			if ( isset( $normalized['min_choices'], $normalized['max_choices'] ) && $normalized['min_choices'] > $normalized['max_choices'] ) {
				throw new \InvalidArgumentException( 'Checkbox minimum choices cannot exceed maximum choices.' );
			}
		}

		if ( in_array( $type, [ 'text', 'textarea' ], true ) ) {
			// WAPF renders minlength/maxlength/pattern as native constraints but
			// never validates them server-side (class-html.php:748-756). OPF stores
			// the same raw keys and emits the same attrs.
			foreach ( [ 'minlength', 'maxlength' ] as $key ) {
				if ( ! array_key_exists( $key, $field ) || '' === $field[ $key ] || null === $field[ $key ] ) {
					continue;
				}
				$normalized[ $key ] = self::bounded_integer( $field[ $key ], 1, 1000000, ucfirst( $type ) . ' ' . $key );
			}
			if ( 'text' === $type && array_key_exists( 'pattern', $field ) && is_scalar( $field['pattern'] ) ) {
				$pattern = trim( (string) $field['pattern'] );
				if ( '' !== $pattern && strlen( $pattern ) <= 2048 ) {
					$normalized['pattern'] = $pattern;
				}
			}
		}

		if ( 'date' === $type ) {
			foreach ( [ 'allow_past', 'allow_future' ] as $key ) {
				$value = $field[ $key ] ?? true;
				if ( ! in_array( $value, [ true, false, 0, 1, '0', '1' ], true ) ) {
					throw new \InvalidArgumentException( sprintf( 'Date field %s must be a boolean.', $key ) );
				}
				$normalized[ $key ] = in_array( $value, [ true, 1, '1' ], true );
			}
			foreach ( [ 'min_date', 'max_date' ] as $key ) {
				if ( ! array_key_exists( $key, $field ) ) {
					continue;
				}
				if ( ! is_string( $field[ $key ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Date field %s must be a string.', $key ) );
				}
				$boundary = trim( $field[ $key ] );
				if ( '' === $boundary ) {
					continue;
				}
				if ( ! FieldValue::is_date_boundary( $boundary ) ) {
					throw new \InvalidArgumentException( sprintf( 'Date field %s must be YYYY-MM-DD or a WAPF relative period such as 7d or 1y 9m 3d.', $key ) );
				}
				$normalized[ $key ] = $boundary;
			}
			$min_date = isset( $normalized['min_date'] ) ? FieldValue::resolve_date_boundary( $normalized['min_date'] ) : null;
			$max_date = isset( $normalized['max_date'] ) ? FieldValue::resolve_date_boundary( $normalized['max_date'] ) : null;
			if ( null !== $min_date && null !== $max_date && $min_date > $max_date ) {
				throw new \InvalidArgumentException( 'Date minimum cannot exceed its maximum.' );
			}
			if ( array_key_exists( 'disabled_weekdays', $field ) ) {
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
				$normalized['disabled_weekdays'] = array_values( array_unique( $weekdays ) );
			}
			if ( array_key_exists( 'disabled_dates', $field ) ) {
				if ( ! is_array( $field['disabled_dates'] ) || count( $field['disabled_dates'] ) > 512 ) {
					throw new \InvalidArgumentException( 'Disabled dates must be a list of at most 512 rules.' );
				}
				$disabled_dates = [];
				foreach ( $field['disabled_dates'] as $disabled_date ) {
					if ( ! is_string( $disabled_date ) || ! FieldValue::is_disabled_date( $disabled_date ) ) {
						throw new \InvalidArgumentException( 'Disabled dates must be YYYY-MM-DD, MM-DD, or an inclusive range using two dates.' );
					}
					$disabled_dates[] = trim( $disabled_date );
				}
				$normalized['disabled_dates'] = array_values( array_unique( $disabled_dates ) );
			}
			if ( array_key_exists( 'cutoff_time', $field ) && '' !== $field['cutoff_time'] ) {
				if ( ! is_string( $field['cutoff_time'] ) ) {
					throw new \InvalidArgumentException( 'Date cutoff time must be a string in 24-hour HH:MM format.' );
				}
				$cutoff = trim( $field['cutoff_time'] );
				if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff ) ) {
					throw new \InvalidArgumentException( 'Date cutoff time must use 24-hour HH:MM format.' );
				}
				$normalized['cutoff_time'] = $cutoff;
			}
		}

		// WAPF static content field types: html (sanitized markup), shortcode
		// (do_shortcode output), and the legacy `calculation` display field.
		// None of them are submittable or priced.
		if ( 'html' === $type ) {
			$normalized['required'] = false;
			$normalized['choices']  = [];
			$normalized['pricing']  = self::normalize_pricing( [] );
			$normalized['content']  = is_scalar( $field['content'] ?? null ) ? (string) $field['content'] : '';
		}
		if ( 'shortcode' === $type ) {
			$normalized['required'] = false;
			$normalized['choices']  = [];
			$normalized['pricing']  = self::normalize_pricing( [] );
			$content = is_scalar( $field['content'] ?? null ) ? (string) $field['content'] : '';
			$normalized['content'] = substr( $content, 0, 10000 );
		}
		if ( 'calculation' === $type ) {
			$normalized['required'] = false;
			$normalized['choices']  = [];
			$normalized['pricing']  = self::normalize_pricing( [] );
			$normalized['formula']  = isset( $field['formula'] ) && is_string( $field['formula'] ) && strlen( $field['formula'] ) <= 4096 ? $field['formula'] : '';
			$result_text = isset( $field['result_text'] ) && is_string( $field['result_text'] ) ? $field['result_text'] : '{result}';
			$normalized['result_text'] = substr( $result_text, 0, 500 );
			$normalized['calculation_type'] = isset( $field['calculation_type'] ) && 'price' === $field['calculation_type'] ? 'price' : 'informational';
			$normalized['content'] = is_scalar( $field['content'] ?? null ) ? (string) $field['content'] : '';
		}

		// WAPF child-products field: bounded source selection, display mode,
		// quantity input mode, zoom, and selection bounds.
		if ( 'child_products' === $type ) {
			$normalized = array_replace( $normalized, ChildProductConfig::normalize( $field ) );
			// Children are native cart lines priced by their own product data;
			// the field itself never carries an addon price.
			$normalized['pricing'] = self::normalize_pricing( [] );
		}

		// Number constraints (WAPF validate_number_field): numeric min/max,
		// positive step, and an integer|decimal input mode.
		if ( 'number' === $type ) {
			foreach ( [ 'min', 'max', 'step' ] as $key ) {
				if ( array_key_exists( $key, $field ) && is_numeric( $field[ $key ] ) ) {
					$value = (float) $field[ $key ];
					if ( 'step' !== $key || $value > 0 ) {
						$normalized[ $key ] = $value;
					}
				}
			}
			if ( isset( $normalized['min'], $normalized['max'] ) && $normalized['min'] > $normalized['max'] ) {
				throw new \InvalidArgumentException( 'Field minimum cannot exceed its maximum.' );
			}
			if ( in_array( $field['number_mode'] ?? null, [ 'integer', 'decimal' ], true ) ) {
				$normalized['number_mode'] = $field['number_mode'];
			}
			// A stored default must satisfy the same constraints a customer
			// submission would (integer mode, min/max, step from the minimum).
			if ( array_key_exists( 'default', $normalized ) && '' !== (string) $normalized['default'] && FieldValue::validate( $normalized, (string) $normalized['default'], true ) ) {
				throw new \InvalidArgumentException( 'Number field default must satisfy its number mode and constraints.' );
			}
		}

		// WAPF `image_quantities` swatch: every choice gets its own quantity
		// input — requires all choices to carry an image, forces single-select
		// semantics, and adds per-choice + aggregate quantity bounds.
		$image_quantities = 'swatch' === $type && ! empty( $field['image_quantities'] ) && count( $choices ) > 0;
		if ( $image_quantities ) {
			foreach ( $choices as $choice ) {
				if ( empty( $choice['image'] ) && empty( $choice['image_id'] ) ) {
					$image_quantities = false;
					break;
				}
			}
		}
		if ( $image_quantities ) {
			$normalized['multiple'] = false;
			unset( $normalized['image_zoom'], $normalized['min_choices'], $normalized['max_choices'] );
			foreach ( [ 'min', 'max', 'step' ] as $key ) {
				if ( array_key_exists( $key, $field ) && is_numeric( $field[ $key ] ) ) {
					$value = (float) $field[ $key ];
					if ( $value <= 0 || floor( $value ) !== $value ) {
						throw new \InvalidArgumentException( 'Image quantity minimum, maximum, and step must be positive whole numbers.' );
					}
					$normalized[ $key ] = (int) $value;
				}
			}
			foreach ( [ 'min_total_quantity', 'max_total_quantity' ] as $key ) {
				if ( isset( $field[ $key ] ) && is_numeric( $field[ $key ] ) && (int) $field[ $key ] >= 0 ) {
					$normalized[ $key ] = (int) $field[ $key ];
				}
			}
			if ( isset( $normalized['min_total_quantity'], $normalized['max_total_quantity'] ) && $normalized['min_total_quantity'] > $normalized['max_total_quantity'] ) {
				throw new \InvalidArgumentException( 'Minimum total quantity cannot exceed its maximum.' );
			}
			foreach ( [ 'min_selections', 'max_selections' ] as $key ) {
				if ( array_key_exists( $key, $field ) && is_numeric( $field[ $key ] ) && (int) $field[ $key ] >= 0 ) {
					$normalized[ $key ] = min( count( $choices ), (int) $field[ $key ] );
				}
			}
			if ( isset( $normalized['min_selections'], $normalized['max_selections'] ) && $normalized['min_selections'] > $normalized['max_selections'] ) {
				throw new \InvalidArgumentException( 'Minimum selections cannot exceed maximum selections.' );
			}
			if ( ! empty( $field['image_quantity_zoom'] ) ) {
				$normalized['image_quantity_zoom'] = true;
			}
			$normalized['image_quantities'] = true;
		} elseif ( ! empty( $field['image_quantities'] ) && 'swatch' === $type ) {
			unset( $normalized['image_quantities'] );
		}

		// WAPF `min_selections`/`max_selections` are aliases for the canonical
		// `min_choices`/`max_choices` bounds on multi-choice controls.
		if ( 'checkbox' === $type || ( 'swatch' === $type && ! empty( $normalized['multiple'] ) ) ) {
			foreach ( [ 'min_selections' => 'min_choices', 'max_selections' => 'max_choices' ] as $alias => $canonical ) {
				if ( ! isset( $normalized[ $canonical ] ) && array_key_exists( $alias, $field ) && '' !== $field[ $alias ] && null !== $field[ $alias ] ) {
					$normalized[ $canonical ] = self::bounded_integer( $field[ $alias ], 1, 10000, ucfirst( $type ) . ' ' . $canonical );
				}
			}
			if ( isset( $normalized['min_choices'], $normalized['max_choices'] ) && $normalized['min_choices'] > $normalized['max_choices'] ) {
				throw new \InvalidArgumentException( ucfirst( $type ) . ' minimum choices cannot exceed maximum choices.' );
			}
		}

		// WAPF serializes "Default value" for every submittable type: scalars
		// stay strings, multi-choice fields keep an array of selected slugs and
		// keyed quantity defaults (image_quantities, child_products with a
		// quantity input) keep their choice/product keys. Scalar types above
		// already validated and stored their own defaults; static content
		// types have no submittable value.
		if ( array_key_exists( 'default', $field )
			&& ! array_key_exists( 'default', $normalized )
			&& ! in_array( $type, [ 'paragraph', 'html', 'shortcode', 'content_image', 'section', 'section_end', 'calc', 'calculation' ], true ) ) {
			$default = $field['default'];
			if ( is_array( $default ) ) {
				$default = array_filter( $default, 'is_scalar' );
				$normalized['default'] = ( ! empty( $normalized['image_quantities'] ) || ( 'child_products' === $type && ! empty( $normalized['quantity_input'] ) ) )
					? array_map( 'strval', $default )
					: array_values( array_map( 'strval', $default ) );
			} elseif ( is_scalar( $default ) ) {
				$normalized['default'] = (string) $default;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize a WAPF weight expression ('0.5', '[qty]', '[x]', '-10', or a
	 * composite like '[x]*0.5'). WAPF stores it verbatim and floatvals the
	 * token-substituted string at cart time; anything non-scalar or empty is
	 * dropped. Bounded to keep POST payloads sane.
	 *
	 * @param mixed $value Raw weight option.
	 */
	private static function normalize_weight( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$weight = trim( substr( trim( (string) $value ), 0, 255 ) );
		return '' === $weight ? null : $weight;
	}

	/** Validate bounded integer field settings. */
	private static function bounded_integer( $value, int $minimum, int $maximum, string $label ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?[0-9]+$/', $value ) ) ) {
			throw new \InvalidArgumentException( $label . ' must be an integer.' );
		}
		$value = (int) $value;
		if ( $value < $minimum || $value > $maximum ) {
			throw new \InvalidArgumentException( sprintf( '%s must be between %d and %d.', $label, $minimum, $maximum ) );
		}
		return $value;
	}

	/** Validate a positive integer setting where the upstream maximum is undocumented. */
	private static function positive_integer( $value, string $label ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^[0-9]+$/', $value ) ) ) {
			throw new \InvalidArgumentException( $label . ' must be a positive integer.' );
		}
		$value = is_string( $value ) ? ltrim( $value, '0' ) : (string) $value;
		$value = '' === $value ? '0' : $value;
		$max = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $max ) || ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) ) {
			throw new \InvalidArgumentException( $label . ' must be a positive platform integer.' );
		}
		$value = (int) $value;
		if ( $value < 1 ) {
			throw new \InvalidArgumentException( $label . ' must be a positive integer.' );
		}
		return $value;
	}

	/**
	 * Normalize a pricing block.
	 *
	 * Semantics (WAPF 3.1.5 parity — see class-fields.php::do_pricing):
	 * every type computes a `result` (fixed=amount, percent=base*a/100,
	 * formula=evaluated expression); `per_unit` decides whether the line
	 * total scales with quantity:
	 *  - per_unit=false → per-unit addon is result/qty (flat per line).
	 *  - per_unit=true  → per-unit addon is result (scales with line qty).
	 *
	 * Defaults when `per_unit` is absent (WAPF faithful):
	 *  - fixed   → flat (WAPF "fixed" price type).
	 *  - formula → flat (WAPF "fx" on a normal field).
	 *  - percent → per-unit (WAPF "percent").
	 * Schema-1 records keep their old forced per-unit flag via migrate().
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
		$per_unit = array_key_exists( 'per_unit', $pricing )
			? (bool) $pricing['per_unit']
			: ! in_array( $type, [ 'fixed', 'formula' ], true );

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
			$rows         = [];
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
				$valid          = true;
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
				$column_count     = count( $normalized_row ) + 1;
				$normalized_row[] = (float) $price;
				$rows[]           = $normalized_row;
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
		$site_tables  = self::normalize_lookup_tables( $site_tables );
		$local_tables = self::normalize_lookup_tables( $group_data['lookup_tables'] ?? [] );
		return array_replace( $site_tables, $local_tables );
	}

	/**
	 * Normalize a placement/variation rule term list.
	 *
	 * Scalar terms are coerced to strings; WAPF-style select2 payloads
	 * (`{id: ..., text: ...}`) are unwrapped to their `id`; anything else is
	 * dropped instead of producing `strval(array)` warnings.
	 *
	 * @param array<int,mixed> $terms Raw terms.
	 * @return array<int,string>
	 */
	private static function normalize_rule_terms( array $terms ): array {
		$out = [];
		foreach ( $terms as $term ) {
			if ( is_array( $term ) ) {
				$term = $term['id'] ?? null;
			}
			if ( null === $term || is_bool( $term ) || is_array( $term ) || is_object( $term ) ) {
				continue;
			}
			$out[] = (string) $term;
		}
		return $out;
	}

	/**
	 * Merge generated variation gates into every field of this group.
	 *
	 * WAPF 3.1.5 parity (Field_Groups::merge_frontend_conditions): when a
	 * group matched partly through variation rules, the same rules gate each
	 * rendered field so visibility, validation, and pricing follow the
	 * selected variation. Generated `var` conditionals are stripped before
	 * appending so re-injection stays idempotent.
	 *
	 * @param array<int,array{subject:string,operator:string,terms:array<int,string>}> $rules Variation rules of the matching rule group.
	 * @param array{variable:bool,id:int,attributes:array<string,string>}|null $context Optional deterministic variation context baked into fields (`_var_ctx`). Pass it for concrete variation products; pass null when the context is request- or selection-dependent.
	 */
	public function inject_variation_rules( array $rules, ?array $context = null ): void {
		foreach ( $this->data['fields'] as &$field ) {
			$field['conditionals'] = array_values( array_filter(
				(array) ( $field['conditionals'] ?? [] ),
				static function ( $conditional ): bool {
					return ! ( is_array( $conditional )
						&& 'var' === ( $conditional['action'] ?? '' )
						&& ! empty( $conditional['generated'] ) );
				}
			) );
			if ( $rules ) {
				$field['conditionals'][] = [
					'action'    => 'var',
					'logic'     => 'all',
					'generated' => true,
					'rules'     => array_values( $rules ),
				];
			}
			if ( null !== $context ) {
				$field['_var_ctx'] = $context;
			} else {
				unset( $field['_var_ctx'] );
			}
		}
		unset( $field );
	}

	/**
	 * Clone normalized group data and remap references between its fields.
	 *
	 * @param array<string,mixed> $data Group data.
	 * @return array{group:array<string,mixed>,field_id_map:array<string,string>}
	 */
	public static function duplicate( array $data ): array {
		$group = self::normalize( $data );
		$ids = array_column( $group['fields'], 'id' );
		if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
			throw new \InvalidArgumentException( 'Cannot duplicate a field group with repeated field IDs.' );
		}

		$used_ids = array_fill_keys( $ids, true );
		$id_map = [];
		foreach ( $ids as $field_id ) {
			$base = substr( $field_id, 0, 48 ) . '-copy';
			$new_id = $base;
			$suffix = 2;
			while ( isset( $used_ids[ $new_id ] ) ) {
				$new_id = $base . '-' . $suffix;
				$suffix++;
			}
			$used_ids[ $new_id ] = true;
			$id_map[ $field_id ] = $new_id;
		}

		foreach ( $group['fields'] as &$field ) {
			$field['id'] = $id_map[ $field['id'] ];
			foreach ( $field['conditionals'] as &$conditional ) {
				foreach ( $conditional['rules'] as &$rule ) {
					if ( isset( $id_map[ $rule['field'] ] ) ) {
						$rule['field'] = $id_map[ $rule['field'] ];
					}
				}
				unset( $rule );
			}
			unset( $conditional );

			$field['pricing'] = self::duplicate_pricing_references( $field['pricing'], $id_map );
			foreach ( $field['choices'] as &$choice ) {
				$choice['pricing'] = self::duplicate_pricing_references( $choice['pricing'], $id_map );
			}
			unset( $choice );
		}
		unset( $field );

		return [ 'group' => $group, 'field_id_map' => $id_map ];
	}

	/**
	 * Remap recognized field tokens in a pricing block.
	 *
	 * @param array<string,mixed> $pricing Pricing data.
	 * @param array<string,string> $id_map Field ID map.
	 * @return array<string,mixed>
	 */
	private static function duplicate_pricing_references( array $pricing, array $id_map ): array {
		foreach ( [ 'formula', 'formula_raw' ] as $key ) {
			if ( ! isset( $pricing[ $key ] ) || ! is_string( $pricing[ $key ] ) ) {
				continue;
			}
			$pricing[ $key ] = (string) preg_replace_callback(
				'~\\[(field|price)\\.([A-Za-z0-9_-]+)\\]~',
				static function ( array $match ) use ( $id_map ): string {
					return isset( $id_map[ $match[2] ] ) ? '[' . $match[1] . '.' . $id_map[ $match[2] ] . ']' : $match[0];
				},
				$pricing[ $key ]
			);
		}
		return $pricing;
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
