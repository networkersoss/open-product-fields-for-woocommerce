<?php
/**
 * Maps legacy WAPF field group data to OPF group data.
 *
 * Behavioral notes preserved from production data analysis (Sept 2026):
 *  - WAPF choice pricing types in the wild: none, fixed, percent, fx (formula).
 *  - WAPF formula amounts end in "* [qty]" because WAPF normalized per-unit
 *    results by dividing by quantity; OPF pricing is already per-unit, so the
 *    mapper strips a trailing quantity multiplication.
 *  - WAPF groups whose placement rule had an empty condition evaluated FALSE
 *    in WAPF (dead groups). The mapper flags those rather than silently
 *    turning them into "show everywhere".
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class WapfMapper {

	/**
	 * WAPF field type → OPF field type.
	 */
	private const TYPE_MAP = [
		'text'          => 'text',
		'textarea'      => 'textarea',
		'email'         => 'email',
		'url'           => 'url',
		'card'          => 'radio',
		'number'        => 'number',
		'date'          => 'date',
		'toggle'        => 'toggle',
		'true-false'    => 'toggle',
		'select'        => 'select',
		'radio'         => 'radio',
		'checkbox'      => 'checkbox',
		'paragraph'     => 'paragraph',
		'content'       => 'paragraph',
		'file'          => 'upload',
		'upload'        => 'upload',
		'html'          => 'html',
		'content-html'  => 'html',
		'shortcode'     => 'shortcode',
		'section'       => 'section',
		'text-swatch'   => 'swatch',
		'color-swatch'  => 'swatch',
		'image-swatch'  => 'swatch',
		'multi-text-swatch'  => 'swatch',
		'multi-color-swatch' => 'swatch',
		'multi-image-swatch' => 'swatch',
	];

	/**
	 * WAPF condition → OPF operator.
	 */
	private const CONDITION_MAP = [
		'is'        => 'is',
		'is_not'    => 'is_not',
		'not_is'    => 'is_not',
		'contains'  => 'contains',
		'gt'        => 'greater',
		'lt'        => 'less',
		'greater'   => 'greater',
		'less'      => 'less',
		'empty'     => 'empty',
		'not_empty' => 'not_empty',
	];

	/**
	 * Map a parsed WAPF group array to an OPF group data array.
	 *
	 * @param array<string,mixed> $wapf      Parsed WAPF group payload.
	 * @param array<string,mixed> $overrides Optional overrides: ['attach_product_ids' => int[]].
	 * @return array{group:array<string,mixed>,notes:string[],needs_review:bool}
	 */
	public static function map( array $wapf, array $overrides = [] ): array {
		$notes        = [];
		$needs_review = false;
		$layout       = is_array( $wapf['layout'] ?? null ) ? $wapf['layout'] : [];
		if ( ! empty( $wapf['variables'] ) ) {
			$notes[]      = 'WAPF custom variable definitions were not imported; their serialized structure and condition scope require review.';
			$needs_review = true;
		}

		$fields        = [];
		$unsupported   = [];
		$seen_ids      = [];
		$field_ids     = [];
		$legacy_ids    = [];
		$source_fields = $wapf['fields'] ?? [];
		if ( ! is_array( $source_fields ) ) {
			$notes[]      = 'malformed fields collection was skipped.';
			$needs_review = true;
			$source_fields = [];
		}

		// Resolve every ID first so a conditional can reference a later field.
		foreach ( $source_fields as $index => $wapf_field ) {
			if ( ! is_array( $wapf_field ) ) {
				continue;
			}
			$raw_type = $wapf_field['type'] ?? 'text';
			$type = is_scalar( $raw_type ) ? (string) $raw_type : 'text';
			if ( '' === $type ) {
				$type = 'text';
			}
			if ( ! isset( self::TYPE_MAP[ $type ] ) ) {
				continue;
			}
			$field_id = self::field_id( self::safe_text( $wapf_field['label'] ?? '' ), self::safe_text( $wapf_field['id'] ?? '' ), $seen_ids );
			$seen_ids[ $field_id ] = true;
			$field_ids[ $index ] = $field_id;
			if ( isset( $wapf_field['id'] ) && is_scalar( $wapf_field['id'] ) && '' !== (string) $wapf_field['id'] ) {
				$legacy_ids[ (string) $wapf_field['id'] ] = $field_id;
			}
		}

		foreach ( $source_fields as $index => $wapf_field ) {
			if ( ! is_array( $wapf_field ) ) {
				$notes[] = sprintf( 'field entry at index %s is malformed and was skipped.', (string) $index );
				continue;
			}
			$raw_type = $wapf_field['type'] ?? 'text';
			$wapf_type = is_scalar( $raw_type ) ? (string) $raw_type : '';
			$options   = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
			foreach ( [ 'label', 'description' ] as $text_key ) {
				if ( array_key_exists( $text_key, $wapf_field ) && ! is_scalar( $wapf_field[ $text_key ] ) ) {
					$notes[]      = sprintf( 'field at index %s has malformed %s text; value was omitted.', (string) $index, $text_key );
					$needs_review = true;
				}
			}
			if ( ! isset( $wapf_field['type'] ) ) {
				$notes[]      = sprintf( 'field at index %s has no type; imported as text for review.', (string) $index );
				$needs_review = true;
				$wapf_type    = 'text';
			} elseif ( ! is_scalar( $raw_type ) ) {
				$notes[]      = sprintf( 'field at index %s has a malformed type; imported as text for review.', (string) $index );
				$needs_review = true;
				$wapf_type    = 'text';
			} elseif ( '' === $wapf_type ) {
				$notes[]      = sprintf( 'field at index %s has an empty type; imported as text for review.', (string) $index );
				$needs_review = true;
				$wapf_type    = 'text';
			}

			if ( ! isset( self::TYPE_MAP[ $wapf_type ] ) ) {
				$unsupported[] = $wapf_type . ':' . self::safe_text( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
				continue;
			}

			$field_id = $field_ids[ $index ];

			if ( 'date' === self::TYPE_MAP[ $wapf_type ] ) {
				self::review_unsupported_date_options( $wapf_field, $notes, $needs_review );
			}
			$card_layout = null;
			if ( 'card' === $wapf_type ) {
				// WAPF's public docs establish the Card field and its orientations,
				// but no local WAPF export proves the serialized option key/semantics.
				// Preserve a recognized value for review; never silently claim parity.
				$raw_layout = $options['layout'] ?? $options['card_layout'] ?? null;
				if ( is_string( $raw_layout ) && in_array( $raw_layout, [ 'horizontal', 'vertical' ], true ) ) {
					$card_layout = $raw_layout;
				} elseif ( null !== $raw_layout ) {
					$notes[] = sprintf( 'card field "%s" has an unrecognized card layout; layout was omitted for review.', self::safe_text( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
				}
				$notes[] = sprintf( 'card field "%s" was mapped to radio choices, but WAPF card serialization and presentation options have no matching local export evidence; verify before publishing.', self::safe_text( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
			}
			if ( 'swatch' === self::TYPE_MAP[ $wapf_type ] ) {
				$explicit_multi = 0 === strpos( $wapf_type, 'multi-' );
				foreach ( $options as $option_name => $option_value ) {
					$is_limit = in_array( (string) $option_name, [ 'min', 'max', 'min_selections', 'max_selections' ], true );
					if ( ( $is_limit && $explicit_multi ) || ( ! $is_limit && ! preg_match( '/multi|multiple|select|selection|cardinality/i', (string) $option_name ) ) ) {
						continue;
					}
					if ( empty( $option_value ) ) {
						continue;
					}
					$notes[]      = sprintf( 'swatch option "%s" indicates selection cardinality that was not recognized; field requires review.', (string) $option_name );
					$needs_review = true;
				}
			}

			$number_constraints = [];
			$number_mode = null;
			if ( 'number' === $wapf_type ) {
				$raw_number_type = $options['number_type'] ?? null;
				if ( null === $raw_number_type ) {
					// WAPF Free declares `int` as the default and renders native step=1
					// when the option is omitted from its stored options payload.
					$number_mode = 'integer';
				} elseif ( 'int' === $raw_number_type ) {
					$number_mode = 'integer';
				} elseif ( 'any' === $raw_number_type ) {
					$number_mode = 'decimal';
				} elseif ( null !== $raw_number_type ) {
					$notes[]      = sprintf( 'number field "%s" has an unrecognized number_type; whole/decimal mode was not imported.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
				}
				foreach ( $options as $option_name => $option_value ) {
					if ( in_array( (string) $option_name, [ 'min', 'max', 'step', 'minimum', 'maximum', 'number_type', 'placeholder' ], true ) || empty( $option_value ) ) {
						continue;
					}
					$notes[]      = sprintf( 'number option "%s" is not supported and was not imported.', (string) $option_name );
					$needs_review = true;
				}
				foreach ( [ 'min' => 'minimum', 'max' => 'maximum' ] as $constraint => $source_key ) {
					$value = $options[ $source_key ] ?? $wapf_field[ $source_key ] ?? $options[ $constraint ] ?? $wapf_field[ $constraint ] ?? null;
					if ( null === $value || '' === $value ) {
						continue;
					}
					if ( ! is_numeric( $value ) ) {
						$notes[]      = sprintf( 'number field "%s" has invalid %s constraint; value was not imported.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ), $constraint );
						$needs_review = true;
						continue;
					}
					$number_constraints[ $constraint ] = (float) $value;
				}
				$raw_step = $options['step'] ?? $wapf_field['step'] ?? null;
				if ( null !== $raw_step && '' !== $raw_step ) {
					if ( ! is_numeric( $raw_step ) || (float) $raw_step <= 0 ) {
						$notes[] = sprintf( 'number field "%s" has invalid step constraint; value was not imported.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					} else {
						$notes[] = sprintf( 'number field "%s" has a step setting whose current Extended serialization is unverified; it was not imported.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					}
					$needs_review = true;
				}
				if ( isset( $number_constraints['min'], $number_constraints['max'] ) && $number_constraints['min'] > $number_constraints['max'] ) {
					$notes[]      = sprintf( 'number field "%s" has a minimum above its maximum; both bounds were omitted.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
					unset( $number_constraints['min'], $number_constraints['max'] );
				}
			}
			$date_bounds = [];
			if ( 'date' === $wapf_type ) {
				foreach ( [ 'min_date' => 'min', 'max_date' => 'max' ] as $target => $fallback ) {
					$value = self::safe_text( $options[ $target ] ?? $options[ $fallback ] ?? '' );
					if ( FieldValue::is_date_boundary( $value ) ) {
						$date_bounds[ $target ] = $value;
					}
				}
				$resolved_min = isset( $date_bounds['min_date'] ) ? FieldValue::resolve_date_boundary( $date_bounds['min_date'] ) : null;
				$resolved_max = isset( $date_bounds['max_date'] ) ? FieldValue::resolve_date_boundary( $date_bounds['max_date'] ) : null;
				if ( null !== $resolved_min && null !== $resolved_max && $resolved_min > $resolved_max ) {
					$notes[]      = sprintf( 'date field "%s" has a minimum after its maximum; both bounds were omitted.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
					$date_bounds  = [];
				}
			}

			$has_choices = in_array( self::TYPE_MAP[ $wapf_type ], [ 'swatch', 'select', 'radio', 'checkbox' ], true );
			$description_presentation = 'tooltip' === ( $layout['instructions_position'] ?? 'field' ) && '' !== self::safe_text( $wapf_field['description'] ?? '' ) ? 'tooltip' : null;
			if ( 'label' === ( $layout['instructions_position'] ?? 'field' ) && '' !== self::safe_text( $wapf_field['description'] ?? '' ) ) {
				$notes[]      = sprintf( 'field "%s" uses WAPF instructions beside the label; OPF only supports inline or tooltip instructions, so placement needs review.', self::safe_text( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
				$needs_review = true;
			} elseif ( ! in_array( $layout['instructions_position'] ?? 'field', [ 'field', 'label', 'tooltip' ], true ) ) {
				$notes[]      = 'group has an unrecognized WAPF instructions position; OPF inline instructions were used and need review.';
				$needs_review = true;
			}

			$field = FieldGroup::normalize_field(
				[
					'id'           => $field_id,
					'label'        => self::safe_text( $wapf_field['label'] ?? '' ),
					'description'  => self::safe_text( $wapf_field['description'] ?? '' ),
					'description_presentation' => $description_presentation,
					'type'         => self::TYPE_MAP[ $wapf_type ],
					'multiple'     => 0 === strpos( $wapf_type, 'multi-' ),
					'required'     => (bool) ( $wapf_field['required'] ?? false ),
					'width'        => (int) ( $wapf_field['width'] ?? 100 ),
					'css_class'    => self::safe_text( $wapf_field['class'] ?? '' ),
					'placeholder'  => self::safe_text( $options['placeholder'] ?? '' ),
					'hide_cart'   => $options['hide_cart'] ?? false,
					'hide_checkout' => $options['hide_checkout'] ?? false,
					'hide_order'  => $options['hide_order'] ?? false,
					'min'          => $number_constraints['min'] ?? null,
					'max'          => $number_constraints['max'] ?? null,
					'step'         => $number_constraints['step'] ?? null,
					'number_mode'  => $number_mode,
					'min_date'     => $date_bounds['min_date'] ?? '',
					'max_date'     => $date_bounds['max_date'] ?? '',
					'content'      => self::safe_text( $options['p_content'] ?? '' ),
					'min_selections' => (int) ( $options['min_selections'] ?? $options['min'] ?? 0 ),
					'max_selections' => (int) ( $options['max_selections'] ?? $options['max'] ?? 0 ),
					'choices'      => $has_choices ? self::map_choices( $wapf_field, $notes, $legacy_ids ) : [],
					'pricing'      => self::map_field_pricing( $wapf_field, $notes, $legacy_ids ),
					'conditionals' => self::map_conditionals( $wapf_field, $notes, $legacy_ids ),
					'card_layout'  => $card_layout,
				]
			);

			if ( ! empty( $wapf_field['clone']['enabled'] ) ) {
				$notes[]      = sprintf( 'field "%s" uses WAPF clone (repeatable fields) which OPF does not support yet.', $field['label'] );
				$needs_review = true;
			}

			if ( 'upload' === $field['type'] ) {
				$notes[]      = sprintf( 'field "%s": WAPF upload constraints are not imported; OPF defaults apply until this field is reviewed.', $field['label'] );
				$needs_review = true;
			}

			if ( array_key_exists( 'weight', $options ) || array_key_exists( 'weight', $wapf_field ) ) {
				$notes[]      = sprintf( 'field "%s" has a WAPF weight setting, but its serialized key and formula semantics are not verified for import.', $field['label'] );
				$needs_review = true;
			}

			$fields[] = $field;
		}

		if ( $unsupported ) {
			$notes[]      = 'unsupported field types dropped: ' . implode( ', ', $unsupported );
			$needs_review = true;
		}

		$placement = self::map_placement( $wapf, $overrides, $notes, $needs_review );
		$image_rule_mode = 'rules';
		$image_rules = self::map_gallery_images( $layout, $legacy_ids, $fields, $notes, $needs_review, $image_rule_mode );
		// Every mapper warning represents source behavior or data that was not
		// preserved. Never publish such a partial migration as a live group.
		$needs_review = $needs_review || ! empty( $notes );

		$group = FieldGroup::normalize(
			[
				'fields'          => $fields,
				'rule_groups'     => $placement,
				'mark_required'   => (bool) ( $wapf['layout']['mark_required'] ?? true ),
				'labels_position' => ( $wapf['layout']['labels_position'] ?? 'above' ),
				'image_rules'     => $image_rules,
				'image_rule_mode' => $image_rule_mode,
			]
		);

		return [
			'group'        => $group,
			'notes'        => $notes,
			'needs_review' => $needs_review,
		];
	}

	/**
	 * Map WAPF's gallery image rules and last-changed-field mode.
	 *
	 * @param array<string,mixed> $layout     WAPF group layout.
	 * @param array<string,string> $legacy_ids WAPF field IDs to OPF field IDs.
	 * @param array<int,array> $fields Mapped OPF fields.
	 * @param string[] $notes Review notes.
	 * @param bool $needs_review Review flag.
	 * @return array<int,array>
	 */
	private static function map_gallery_images( array $layout, array $legacy_ids, array $fields, array &$notes, bool &$needs_review, string &$image_rule_mode ): array {
		if ( empty( $layout['enable_gallery_images'] ) ) {
			return [];
		}

		$source_images = $layout['gallery_images'] ?? [];
		if ( ! is_array( $source_images ) ) {
			$notes[]      = 'WAPF gallery image rules are malformed and were not imported.';
			$needs_review = true;
			return [];
		}
		if ( count( $source_images ) > 64 ) {
			$notes[]      = 'WAPF has more gallery image rules than OPF imports at once; rules after the first 64 were not imported.';
			$needs_review = true;
		}

		$swap_type = $layout['swap_type'] ?? 'rules';
		if ( ! in_array( $swap_type, [ 'rules', 'last' ], true ) ) {
			$notes[]      = 'WAPF gallery images use an unrecognized swap mode; no image rules were imported.';
			$needs_review = true;
			return [];
		}
		$image_rule_mode = $swap_type;

		$field_by_id = [];
		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				$field_by_id[ (string) ( $field['id'] ?? '' ) ] = $field;
			}
		}

		$rules = [];
		foreach ( array_slice( $source_images, 0, 64 ) as $source_image ) {
			if ( ! is_array( $source_image ) || ! is_scalar( $source_image['url'] ?? null ) ) {
				$notes[]      = 'malformed WAPF gallery image was skipped.';
				$needs_review = true;
				continue;
			}

			$url = trim( (string) $source_image['url'] );
			$source_values = $source_image['values'] ?? [];
			if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7F]/', $url ) || ! preg_match( '#^(?:https?://[^/\s]+|/(?!/))#i', $url ) || ! is_array( $source_values ) || ! $source_values ) {
				$notes[]      = 'WAPF gallery image has no usable URL or conditions and was skipped.';
				$needs_review = true;
				continue;
			}
			if ( count( $source_values ) > 32 ) {
				$notes[]      = 'WAPF gallery image has more conditions than OPF imports at once; the image rule was skipped.';
				$needs_review = true;
				continue;
			}

			$conditions = [];
			$valid_rule = true;
			foreach ( $source_values as $source_value ) {
				if ( ! is_array( $source_value ) || ! is_scalar( $source_value['field'] ?? null ) || ! is_scalar( $source_value['value'] ?? null ) ) {
					$valid_rule = false;
					break;
				}
				$legacy_field_id = (string) $source_value['field'];
				$opf_field_id = $legacy_ids[ $legacy_field_id ] ?? '';
				$value = trim( (string) $source_value['value'] );
				$field = $field_by_id[ $opf_field_id ] ?? null;
				$choice_slugs = is_array( $field ) ? array_map( 'strval', array_column( $field['choices'] ?? [], 'slug' ) ) : [];
				$valid_toggle_value = is_array( $field ) && 'toggle' === ( $field['type'] ?? '' ) && in_array( $value, [ '0', '1' ], true );
				if ( '' === $opf_field_id || '' === $value || strlen( $value ) > 256 || ! is_array( $field ) || ( ! in_array( $field['type'] ?? '', [ 'select', 'radio', 'checkbox', 'swatch' ], true ) && ! $valid_toggle_value ) || ( '*' !== $value && ! $valid_toggle_value && ! in_array( $value, $choice_slugs, true ) ) ) {
					$valid_rule = false;
					break;
				}
				$conditions[] = [ 'field' => $opf_field_id, 'value' => $value ];
			}

			if ( ! $valid_rule || ! $conditions ) {
				$notes[]      = 'WAPF gallery image references an unmapped field or choice and was skipped.';
				$needs_review = true;
				continue;
			}
			$rules[] = [ 'target_url' => $url, 'conditions' => $conditions ];
		}

		return $rules;
	}

	/**
	 * Map choices with pricing.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @param string[]            $notes      Collector.
	 * @return array<int,array>
	 */
	private static function map_choices( array $wapf_field, array &$notes, array $legacy_ids ): array {
		$choices = [];
		$source_choices = $wapf_field['options']['choices'] ?? [];
		if ( ! is_array( $source_choices ) ) {
			$notes[] = 'malformed choice list was skipped.';
			return [];
		}
		foreach ( $source_choices as $choice ) {
			if ( ! is_array( $choice ) ) {
				$notes[] = 'malformed choice data was skipped.';
				continue;
			}
			$slug  = (string) ( $choice['slug'] ?? '' );
			$ptype = (string) ( $choice['pricing_type'] ?? 'none' );
			$amt   = $choice['pricing_amount'] ?? 0;

			$pricing = [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];

			switch ( $ptype ) {
				case 'fixed':
					// WAPF fixed = flat fee per line (qty_based is an opt-in).
					$pricing = [ 'type' => 'fixed', 'amount' => (float) $amt, 'formula' => '', 'per_unit' => false ];
					break;
				case 'qt':
					// qt: amount*qty total → per-unit fixed.
					$pricing = [ 'type' => 'fixed', 'amount' => (float) $amt, 'formula' => '', 'per_unit' => true ];
					break;
				case 'percent':
				case 'p':
					$pricing = [ 'type' => 'percent', 'amount' => (float) $amt, 'formula' => '' ];
					break;
				case 'fx':
					$formula = self::normalize_formula( (string) $amt, $legacy_ids );
					if ( null === $formula ) {
						$notes[] = sprintf( 'choice "%s" formula could not be translated: %s', $choice['label'] ?? $slug, (string) $amt );
						$pricing = [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
					} else {
						if ( self::has_price_field_reference( (string) $amt ) ) {
							$notes[] = sprintf( 'choice "%s" formula uses [price.{id}]; OPF has runtime support, but imported reference behavior remains unverified against a real WAPF export.', $choice['label'] ?? $slug );
						}
						if ( self::has_lookup_table_reference( (string) $amt ) ) {
							$notes[] = sprintf( 'choice "%s" formula references a WAPF lookup table, but its site-level table data is not included in the imported group.', $choice['label'] ?? $slug );
						}
						if ( self::has_formula_variable_reference( (string) $amt ) ) {
							$notes[] = sprintf( 'choice "%s" formula uses WAPF custom variables, but their definitions and condition scope were not mapped.', $choice['label'] ?? $slug );
						}
						if ( self::has_acf_formula_reference( (string) $amt ) ) {
							$notes[] = sprintf( 'choice "%s" formula uses ACF values; verify the referenced numeric fields exist on the target product/options page before publishing.', $choice['label'] ?? $slug );
						}
						// formula_raw keeps the legacy expression (incl. its qty
						// factor) for the theme's live-total display math.
						$pricing = [ 'type' => 'formula', 'amount' => 0.0, 'formula' => $formula, 'formula_raw' => (string) $amt, 'per_unit' => true ];
					}
					break;
				case 'none':
					break;
				default:
					$notes[] = sprintf( 'choice "%s" uses pricing type "%s" which is not supported; imported without pricing.', $choice['label'] ?? $slug, $ptype );
					break;
			}

			$mapped_choice = [
				'slug'     => $slug,
				'label'    => (string) ( $choice['label'] ?? '' ),
				'selected' => (bool) ( $choice['selected'] ?? false ),
				'disabled' => (bool) ( $choice['disabled'] ?? false ),
				'pricing'  => $pricing,
			];
			if ( isset( $choice['color'] ) && is_string( $choice['color'] ) ) {
				$mapped_choice['color'] = $choice['color'];
			}
			if ( isset( $choice['image'] ) && is_string( $choice['image'] ) ) {
				$mapped_choice['image'] = $choice['image'];
			}
			if ( isset( $choice['description'] ) && is_scalar( $choice['description'] ) ) {
				$mapped_choice['description'] = self::safe_text( $choice['description'] );
			}
			if ( isset( $choice['weight'] ) && is_numeric( $choice['weight'] ) ) {
				$mapped_choice['weight'] = (float) $choice['weight'];
			}
			$choices[] = $mapped_choice;
		}
		return $choices;
	}

	/**
	 * WAPF formula → OPF formula.
	 *
	 * WAPF normalized per-unit results by dividing by quantity, so formulas
	 * in the wild end with "* [qty]" to compensate. OPF is per-unit, so a
	 * trailing quantity multiplication is stripped. Variables map 1:1
	 * ([price], [options_total]→[addons], [qty], [val]), and references are
	 * translated through the complete source-ID map.
	 *
	 * @param array<string,string> $legacy_ids Legacy WAPF ID to OPF ID map.
	 */
	public static function normalize_formula( string $formula, array $legacy_ids = [] ): ?string {
		$formula = trim( $formula );
		if ( '' === $formula ) {
			return null;
		}
		$formula = str_replace( '[options_total]', '[addons]', $formula );
		$unresolved = false;
		$formula = preg_replace_callback(
			'/lookuptable\s*\(([^()]*)\)/i',
			static function ( array $match ) use ( $legacy_ids, &$unresolved ): string {
				$args = array_map( 'trim', explode( ';', $match[1] ) );
				$table_name = array_shift( $args );
				if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', (string) $table_name ) || empty( $args ) ) {
					$unresolved = true;
					return $match[0];
				}
				foreach ( $args as &$field_id ) {
					if ( ! isset( $legacy_ids[ $field_id ] ) ) {
						$unresolved = true;
						continue;
					}
					$field_id = $legacy_ids[ $field_id ];
				}
				unset( $field_id );
				return 'lookuptable(' . $table_name . '; ' . implode( '; ', $args ) . ')';
			},
			$formula
		);
		$formula = preg_replace_callback(
			'/\[(field|price)\.([a-zA-Z0-9_-]+)\]/i',
			static function ( array $match ) use ( $legacy_ids, &$unresolved ): string {
				if ( ! isset( $legacy_ids[ $match[2] ] ) ) {
					$unresolved = true;
					return $match[0];
				}
				return '[' . strtolower( $match[1] ) . '.' . $legacy_ids[ $match[2] ] . ']';
			},
			$formula
		);
		$formula = preg_replace_callback(
			'/\b(checked|files|sumQty)\s*\(\s*([a-zA-Z0-9_-]+)\s*\)/i',
			static function ( array $match ) use ( $legacy_ids, &$unresolved ): string {
				if ( ! isset( $legacy_ids[ $match[2] ] ) ) {
					$unresolved = true;
					return $match[0];
				}
				return $match[1] . '(' . $legacy_ids[ $match[2] ] . ')';
			},
			$formula
		);
		if ( $unresolved ) {
			return null;
		}
		// Strip compensating quantity factor (repeat, e.g. "* [qty]" or "[qty]*").
		$changed = true;
		while ( $changed ) {
			$changed = false;
			if ( preg_match( '/\*\s*\[\s*qty\s*\]\s*$/i', $formula ) ) {
				$formula = substr( $formula, 0, strrpos( $formula, '*' ) );
				$formula = trim( $formula );
				$changed = true;
			} elseif ( preg_match( '/^\[\s*qty\s*\]\s*\*\s*/i', $formula ) ) {
				$formula = preg_replace( '/^\[\s*qty\s*\]\s*\*\s*/i', '', $formula );
				$formula = trim( (string) $formula );
				$changed = true;
			}
		}
		if ( '' === $formula ) {
			return null;
		}
		// Validate by round-tripping through the safe evaluator with sample vars.
		$probe = preg_replace( '/lookuptable\s*\([^()]*\)/i', '1', $formula );
		$probe = preg_replace( '/\[(?:field|price)\.[a-zA-Z0-9_-]+\]/i', '1', (string) $probe );
		$probe = preg_replace( '/\b(?:checked|files|sumQty)\s*\(\s*[a-zA-Z0-9_-]+\s*\)/i', '1', (string) $probe );
		$probe = preg_replace( '/\bacf(?:_option)?\s*\(\s*[a-zA-Z][a-zA-Z0-9_-]{0,63}\s*\)/i', '1', (string) $probe );
		$probe = preg_replace( '/\[var_[a-zA-Z][a-zA-Z0-9_]{0,63}\]/i', '1', (string) $probe );
		$probe = str_replace( [ '[price]', '[qty]', '[addons]', '[val]' ], '1', (string) $probe );
		if ( preg_match( '/[^0-9+\-*\/().\s]/', $probe ) ) {
			return null;
		}
		return $formula;
	}

	/**
	 * Field-level pricing (text-like fields).
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @return array<string,mixed>
	 */
	private static function map_field_pricing( array $wapf_field, array &$notes, array $legacy_ids ): array {
		$pricing = $wapf_field['pricing'] ?? [];
		if ( ! is_array( $pricing ) || empty( $pricing['enabled'] ) ) {
			return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
		}
		$type = (string) ( $pricing['type'] ?? 'none' );
		$amt  = (float) ( $pricing['amount'] ?? 0 );
		switch ( $type ) {
			case 'none':
				return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
			case 'fixed':
			case 'qt':
				return [ 'type' => 'fixed', 'amount' => $amt, 'formula' => '', 'per_unit' => 'qt' === $type ];
			case 'percent':
			case 'p':
				return [ 'type' => 'percent', 'amount' => $amt, 'formula' => '' ];
			case 'fx':
				$source_formula = (string) ( $pricing['amount'] ?? '' );
				$formula = self::normalize_formula( $source_formula, $legacy_ids );
				if ( null !== $formula ) {
					if ( self::has_price_field_reference( $source_formula ) ) {
						$notes[] = sprintf( 'field "%s" formula uses [price.{id}]; OPF has runtime support, but imported reference behavior remains unverified against a real WAPF export.', $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
					}
					if ( self::has_lookup_table_reference( $source_formula ) ) {
						$notes[] = sprintf( 'field "%s" formula references a WAPF lookup table, but its site-level table data is not included in the imported group.', $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
					}
					if ( self::has_formula_variable_reference( $source_formula ) ) {
						$notes[] = sprintf( 'field "%s" formula uses WAPF custom variables, but their definitions and condition scope were not mapped.', $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
					}
					if ( self::has_acf_formula_reference( $source_formula ) ) {
						$notes[] = sprintf( 'field "%s" formula uses ACF values; verify the referenced numeric fields exist on the target product/options page before publishing.', $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
					}
					return [ 'type' => 'formula', 'amount' => 0.0, 'formula' => $formula ];
				}
				$notes[] = sprintf( 'field "%s" formula could not be translated: %s', $wapf_field['label'] ?? $wapf_field['id'] ?? '?', (string) ( $pricing['amount'] ?? '' ) );
				break;
			default:
				$notes[] = sprintf( 'field "%s" uses pricing type "%s" which is not supported.', $wapf_field['label'] ?? $wapf_field['id'] ?? '?', $type );
				break;
		}
		return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
	}

	/**
	 * Does a formula reference the selected price for a specific field?
	 *
	 * @param string $formula Source formula.
	 */
	private static function has_price_field_reference( string $formula ): bool {
		return 1 === preg_match( '/\[price\.[a-zA-Z0-9_-]+\]/i', $formula );
	}

	/** Does a formula use WAPF's lookup-table function? */
	private static function has_lookup_table_reference( string $formula ): bool {
		return 1 === preg_match( '/\blookuptable\s*\(/i', $formula );
	}

	/** Does a formula reference a WAPF custom variable token? */
	private static function has_formula_variable_reference( string $formula ): bool {
		return 1 === preg_match( '/\[var_[a-zA-Z][a-zA-Z0-9_]{0,63}\]/', $formula );
	}

	/** Does a formula depend on values provided by the ACF add-on? */
	private static function has_acf_formula_reference( string $formula ): bool {
		return 1 === preg_match( '/\bacf(?:_option)?\s*\(/i', $formula );
	}

	/**
	 * Field conditionals.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @param string[]            $notes      Collector.
	 * @param array<string,string> $legacy_ids Legacy WAPF ID to OPF ID map.
	 * @return array<int,array>
	 */
	private static function map_conditionals( array $wapf_field, array &$notes, array $legacy_ids ): array {
		$out = [];
		$source_conditionals = $wapf_field['conditionals'] ?? [];
		if ( ! is_array( $source_conditionals ) ) {
			$notes[] = 'malformed conditional list was skipped.';
			return [];
		}
		foreach ( $source_conditionals as $conditional ) {
			if ( ! is_array( $conditional ) ) {
				$notes[] = 'malformed conditional data was skipped.';
				continue;
			}
			$source_rules = $conditional['rules'] ?? null;
			if ( ! is_array( $source_rules ) ) {
				$notes[] = 'conditional group has malformed rules and was skipped.';
				continue;
			}
			$rules = [];
			foreach ( $source_rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					$notes[] = 'malformed conditional rule was skipped.';
					continue;
				}
				$raw_condition = $rule['condition'] ?? '';
				if ( ! is_scalar( $raw_condition ) ) {
					$notes[] = 'conditional rule has a malformed condition and was dropped.';
					continue;
				}
				$condition = (string) $raw_condition;
				$operator  = self::CONDITION_MAP[ $condition ] ?? null;
				// WAPF's raw JSON importer and Field model use "field" for
				// conditional references. Accept "subject" as well for older
				// normalized snapshots already handled by this mapper.
				$raw_subject = $rule['field'] ?? $rule['subject'] ?? '';
				if ( ! is_scalar( $raw_subject ) ) {
					$notes[] = 'conditional rule has a malformed subject and was dropped.';
					continue;
				}
				$subject   = (string) $raw_subject;
				$subject   = $legacy_ids[ $subject ] ?? $subject;
				if ( null === $operator || '' === $subject ) {
					$notes[] = sprintf( 'conditional rule with condition "%s" dropped.', $condition );
					continue;
				}
				$value = $rule['value'] ?? '';
				if ( is_array( $value ) ) {
					$value = implode( ', ', array_map( 'strval', $value ) );
				}
				$rules[] = [
					'field'    => $subject,
					'operator' => $operator,
					'value'    => (string) $value,
				];
			}
			if ( $rules ) {
				$out[] = [
					'action' => 'show',
					'logic'  => 'all',
					'rules'  => $rules,
				];
			} elseif ( $source_rules ) {
				$notes[] = 'conditional group had no usable rules and was dropped.';
			} elseif ( array_key_exists( 'rules', $conditional ) ) {
				$notes[] = 'empty conditional group was dropped.';
			}
		}
		return $out;
	}

	/**
	 * Flag WAPF date behavior that OPF cannot currently preserve.
	 *
	 * @param array<string,mixed> $wapf_field Source date field.
	 * @param string[]            $notes      Collector.
	 * @param bool                $needs_review Flag ref.
	 */
	private static function review_unsupported_date_options( array $wapf_field, array &$notes, bool &$needs_review ): void {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$mapped  = [ 'max', 'max_date', 'min', 'min_date', 'placeholder' ];

		foreach ( $options as $name => $value ) {
			if ( in_array( (string) $name, $mapped, true ) || null === $value || '' === $value ) {
				continue;
			}
			$notes[]      = sprintf( 'date option "%s" is not supported and was not imported.', (string) $name );
			$needs_review = true;
		}

		foreach ( [ 'min_date' => 'min', 'max_date' => 'max' ] as $date_key => $fallback_key ) {
			$value = self::safe_text( $options[ $date_key ] ?? $options[ $fallback_key ] ?? '' );
			if ( '' !== $value && ! FieldValue::is_date_boundary( $value ) ) {
				$notes[]      = sprintf( 'date boundary "%s" for %s is invalid and was not imported.', $value, $date_key );
				$needs_review = true;
			}
		}
	}

	/**
	 * Placement rule groups.
	 *
	 * @param array<string,mixed> $wapf      WAPF group.
	 * @param array<string,mixed> $overrides attach_product_ids.
	 * @param string[]            $notes     Collector.
	 * @param bool                $needs_review Flag ref.
	 * @return array<int,array>
	 */
	private static function map_placement( array $wapf, array $overrides, array &$notes, bool &$needs_review ): array {
		if ( ! empty( $overrides['attach_product_ids'] ) ) {
			return [
				[ 'rules' => [
					[
						'subject'  => 'product',
						'operator' => 'in',
						'terms'    => array_map( 'strval', (array) $overrides['attach_product_ids'] ),
					],
				] ],
			];
		}

		$source_groups = $wapf['rule_groups'] ?? [];
		if ( ! is_array( $source_groups ) ) {
			$notes[]      = 'malformed placement rule groups were skipped.';
			$needs_review = true;
			return [];
		}
		$out = [];
		foreach ( $source_groups as $rule_group ) {
			if ( ! is_array( $rule_group ) || ! isset( $rule_group['rules'] ) || ! is_array( $rule_group['rules'] ) ) {
				$notes[]      = 'malformed placement rule group was skipped.';
				$needs_review = true;
				continue;
			}
			$rules = [];
			foreach ( $rule_group['rules'] as $rule ) {
				if ( ! is_array( $rule ) ) {
					$notes[]      = 'malformed placement rule was skipped.';
					$needs_review = true;
					continue;
				}
				$raw_condition = $rule['condition'] ?? '';
				$raw_subject   = $rule['subject'] ?? '';
				if ( ! is_scalar( $raw_condition ) || ! is_scalar( $raw_subject ) ) {
					$notes[]      = 'placement rule has a malformed condition or subject and was skipped.';
					$needs_review = true;
					continue;
				}
				$condition = (string) $raw_condition;
				$subject   = (string) $raw_subject;
				$value     = $rule['value'] ?? null;

				// WAPF evaluated empty conditions as FALSE (dead rule). Flag,
				// don't silently broaden scope.
				if ( '' === $condition ) {
					$notes[]      = 'group had a WAPF rule with empty condition which WAPF evaluated as never-matching; imported as review-needed.';
					$needs_review = true;
					continue;
				}

				$negate = isset( $condition[0] ) && '!' === $condition[0];
				$cond   = ltrim( $condition, '!' );

				$map = [
					'product'      => 'product',
					'products'     => 'product',
					'product_cat'  => 'product_cat',
					'product_cats' => 'product_cat',
					'p_tags'       => 'product_tag',
					'product_tag'  => 'product_tag',
				];
				if ( ! isset( $map[ $cond ] ) ) {
					$notes[]      = sprintf( 'placement condition "%s" has no OPF equivalent; rule dropped.', $condition );
					$needs_review = true;
					continue;
				}

				$terms = [];
				if ( is_array( $value ) ) {
					foreach ( $value as $v ) {
						if ( is_array( $v ) && isset( $v['id'] ) ) {
							$terms[] = (string) $v['id'];
						} elseif ( is_array( $v ) ) {
							$notes[]      = 'malformed placement term was skipped.';
							$needs_review = true;
						} elseif ( is_scalar( $v ) ) {
							$terms[] = (string) $v;
						} else {
							$notes[]      = 'malformed placement term was skipped.';
							$needs_review = true;
						}
					}
				} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
					$terms[] = (string) $value;
				}
				if ( ! $terms ) {
					$notes[]      = sprintf( 'placement condition "%s" has no usable terms; rule dropped.', $condition );
					$needs_review = true;
					continue;
				}

				$rules[] = [
					'subject'  => $map[ $cond ],
					'operator' => ( $negate ? 'not_in' : 'in' ),
					'terms'    => $terms,
				];
			}
			if ( $rules ) {
				$out[] = [ 'rules' => $rules ];
			}
		}
		return $out;
	}

	/**
	 * Derive a stable, human-readable field id.
	 *
	 * @param string             $label    Field label.
	 * @param string             $fallback WAPF hex id.
	 * @param array<string,bool> $seen     Already-used ids.
	 */
	private static function field_id( string $label, string $fallback, array $seen ): string {
		$slug = self::slugify( $label );
		if ( '' === $slug ) {
			$slug = 'field';
		}
		$candidate = $slug;
		$i         = 2;
		while ( isset( $seen[ $candidate ] ) ) {
			$candidate = $slug . '-' . $i;
			$i++;
		}
		return $candidate;
	}

	/** Convert scalar source values without emitting PHP cast warnings. */
	private static function safe_text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * WP-independent slugify (mirrors sanitize_title for latin/extended-latin).
	 *
	 * @param string $text Text to slugify.
	 */
	public static function slugify( string $text ): string {
		$text = mb_strtolower( $text, 'UTF-8' );
		$translit = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
		if ( false !== $translit && '' !== trim( $translit ) ) {
			$text = strtolower( $translit );
		}
		$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
		$text = trim( (string) $text, '-' );
		return substr( $text, 0, 40 );
	}
}
