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
		'number'        => 'number',
		'date'          => 'date',
		'true-false'   => 'toggle',
		'select'        => 'select',
		'radio'         => 'radio',
		'checkbox'      => 'checkbox',
		'checkboxes'    => 'checkbox',
		'image-swatch-qty' => 'image_quantity',
		'text-swatch'   => 'swatch',
		'multi-text-swatch' => 'swatch',
		'image-swatch'  => 'swatch',
		'multi-image-swatch' => 'swatch',
		'color-swatch' => 'swatch',
		'multi-color-swatch' => 'swatch',
		'content'       => 'paragraph',
		'paragraph'     => 'paragraph',
		'p'             => 'paragraph',
		// WAPF `shortcode`: non-submittable content whose p_content runs
		// through do_shortcode at render time (see the paragraph block below
		// for content extraction).
		'shortcode'      => 'shortcode',
		'img'            => 'content_image',
		'section'        => 'section',
		'sectionend'     => 'section_end',
		'file'           => 'upload',
		// WAPF Extended `calc`: informational or cost calculation. The mapper
		// ports calc_type/formula/result_format/result_text (see
		// map_calc_settings); a cost calc also becomes a signed formula price.
		'calc'           => 'calc',
		// Stored WAPF groups write type `products` with a separate `subtype`
		// key; the `products-*` spellings cover payloads that flattened the
		// subtype into the type name.
		'products'           => 'products',
		'products-checkbox'  => 'products',
		'products-radio'     => 'products',
		'products-dropdown'  => 'products',
		'products-image'     => 'products',
		'products-card'      => 'products',
		'products-vcard'     => 'products',
		'products-card-qty'  => 'products',
		'products-vcard-qty' => 'products',
	];

	/**
	 * WAPF condition → OPF operator.
	 */
	private const CONDITION_MAP = [
		'is'        => 'is',
		'=='        => 'is',
		'is_not'    => 'is_not',
		'not_is'    => 'is_not',
		'!='        => 'is_not',
		'contains'  => 'contains',
		'==contains' => 'contains',
		'!=contains' => 'not_contains',
		'gt'        => 'greater',
		'lt'        => 'less',
		'greater'   => 'greater',
		'less'      => 'less',
		'empty'     => 'empty',
		'not_empty' => 'not_empty',
		'!empty'    => 'not_empty',
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

		$fields        = [];
		$unsupported   = [];
		$seen_ids      = [];
		$opf_ids_by_index = [];
		$opf_ids_by_wapf_id = [];
		$source_order_by_wapf_id = [];
		$source_type_by_wapf_id = [];
		$sumqty_safe_wapf_ids = [];
		$unrepeated_by_index = [];
		$inside_repeated_section_by_index = [];
		$repeat_section_stack = [];
		$source_fields = is_array( $wapf['fields'] ?? null ) ? $wapf['fields'] : [];

		// Generate every destination ID first so conditional references can point
		// forward or backward in the source field order.
		foreach ( $source_fields as $index => $wapf_field ) {
			if ( ! is_array( $wapf_field ) ) {
				$notes[] = sprintf( 'field at index %s is malformed and was skipped.', (string) $index );
				$needs_review = true;
				continue;
			}
			$wapf_type = (string) ( $wapf_field['type'] ?? 'text' );
			$clone_disabled = in_array( $wapf_field['clone']['enabled'] ?? false, [ false, 0, '0', 'false', null ], true );
			if ( 'section' === $wapf_type ) {
				$repeat_section_stack[] = ! $clone_disabled;
			} elseif ( 'sectionend' === $wapf_type ) {
				array_pop( $repeat_section_stack );
			}
			// Source identity must include unsupported fields. A duplicate can
			// otherwise incorrectly resolve to the one supported occurrence.
			$source_id = is_scalar( $wapf_field['id'] ?? null ) ? (string) $wapf_field['id'] : '';
			$duplicate_source_id = '' !== $source_id && array_key_exists( $source_id, $opf_ids_by_wapf_id );
			if ( '' !== $source_id ) {
				$opf_ids_by_wapf_id[ $source_id ] = null;
				$source_order_by_wapf_id[ $source_id ] = null;
				$source_type_by_wapf_id[ $source_id ] = $wapf_type;
				$sumqty_safe_wapf_ids[ $source_id ] = false;
				if ( $duplicate_source_id ) {
					$notes[] = sprintf( 'WAPF field ID "%s" is duplicated; conditions referencing it need review.', $source_id );
					$needs_review = true;
				}
			}
			if ( ! isset( self::TYPE_MAP[ $wapf_type ] ) ) {
				$unsupported[] = $wapf_type . ':' . ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
				continue;
			}
			$field_id = self::field_id( (string) ( $wapf_field['label'] ?? '' ), (string) ( $wapf_field['id'] ?? '' ), $seen_ids );
			$seen_ids[ $field_id ] = true;
			$opf_ids_by_index[ $index ] = $field_id;
			$inside_repeated_section_by_index[ $index ] = in_array( true, $repeat_section_stack, true );
			$unrepeated_by_index[ $index ] = $clone_disabled && ! $inside_repeated_section_by_index[ $index ];
			if ( '' !== $source_id && ! $duplicate_source_id ) {
				$opf_ids_by_wapf_id[ $source_id ] = $field_id;
				$source_order_by_wapf_id[ $source_id ] = (int) $index;
				// sumQty consumes OPF's structured image quantities. Other
				// target types and clone scopes do not establish WAPF parity.
				$sumqty_safe_wapf_ids[ $source_id ] = 'image_quantity' === self::TYPE_MAP[ $wapf_type ] && $unrepeated_by_index[ $index ];
			}
		}

		foreach ( $source_fields as $index => $wapf_field ) {
			if ( ! is_array( $wapf_field ) || ! isset( $opf_ids_by_index[ $index ] ) ) {
				continue;
			}
			$wapf_type = (string) ( $wapf_field['type'] ?? 'text' );
			$mapped_type = self::TYPE_MAP[ $wapf_type ];
			$field_id = $opf_ids_by_index[ $index ];
			$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
			// Repeated consumers also need review: clone context propagation is
			// not established by ordinary image-quantity sumQty parity.
			$sumqty_references = $unrepeated_by_index[ $index ] ? $sumqty_safe_wapf_ids : [];

			if ( in_array( $mapped_type, [ 'upload', 'products', 'calc' ], true ) && ! empty( $inside_repeated_section_by_index[ $index ] ) ) {
				// FieldGroup rejects these types inside repeated sections (calc
				// is computed once per group), and WAPF clone propagation is not
				// ported, so the field is dropped like an unsupported type
				// instead of failing the whole group at cart time.
				$notes[] = sprintf( 'field "%s" is a %s field inside a repeated section; OPF does not support that placement and the field was dropped.', $label, $mapped_type );
				$needs_review = true;
				continue;
			}

			$has_choices = in_array( $mapped_type, [ 'swatch', 'image_quantity', 'select', 'radio', 'checkbox' ], true );
			$image_swatch_settings = in_array( $wapf_type, [ 'image-swatch', 'multi-image-swatch' ], true ) ? self::map_image_swatch_settings( $wapf_field, $notes, $needs_review ) : [];
			$color_swatch_settings = in_array( $wapf_type, [ 'color-swatch', 'multi-color-swatch' ], true ) ? self::map_color_swatch_settings( $wapf_field, $notes, $needs_review ) : [];
			$selection_limits = in_array( $wapf_type, [ 'multi-text-swatch', 'multi-image-swatch', 'multi-color-swatch' ], true ) ? self::map_swatch_selection_limits( $wapf_field, $notes, $needs_review ) : [];
			$checkbox_limits = 'checkboxes' === $wapf_type ? self::map_checkbox_limits( $wapf_field, $notes, $needs_review ) : [];
			$checkbox_columns = 'checkboxes' === $wapf_type ? self::map_checkbox_columns( $wapf_field, $notes, $needs_review ) : [];
			$text_validation = in_array( $wapf_type, [ 'text', 'textarea' ], true ) ? self::map_text_validation( $wapf_field, $notes, $needs_review ) : [];
			$quantity_limits = 'image-swatch-qty' === $wapf_type ? self::map_image_quantity_limits( $wapf_field, $notes, $needs_review ) : [];
			$content = '';
			$image_url = '';
			$image_id = 0;
			$content_format = 'plain';
			$process_shortcodes = false;
			if ( in_array( self::TYPE_MAP[ $wapf_type ], [ 'paragraph', 'shortcode' ], true ) ) {
				$content = (string) ( $wapf_field['options']['p_content'] ?? $wapf_field['p_content'] ?? '' );
				if ( 'p' === $wapf_type ) {
					$content_format = 'html';
					$process_shortcodes = true;
				} elseif ( 'paragraph' === self::TYPE_MAP[ $wapf_type ] && preg_match( '/<\/?[a-z][^>]*>/i', $content ) ) {
					$notes[] = sprintf( 'field "%s" contains HTML; the plain-text paragraph was imported with markup removed.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
					$content = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $content ) : strip_tags( $content );
				}
			}
			if ( 'img' === $wapf_type ) {
				$raw_image_url = $wapf_field['options']['image'] ?? $wapf_field['image'] ?? null;
				$raw_image_id = $wapf_field['options']['attachment'] ?? $wapf_field['attachment'] ?? null;
				$image_url = is_scalar( $raw_image_url ) ? (string) $raw_image_url : '';
				$image_id = is_scalar( $raw_image_id ) ? (int) $raw_image_id : 0;
			}
			$repeat = self::map_repeat_settings( $wapf_field, $notes, $needs_review );
			if ( in_array( $mapped_type, [ 'upload', 'products' ], true ) && $repeat ) {
				// FieldGroup cannot persist repeats on these types; the field
				// itself still imports so a human can decide how to keep it.
				$notes[] = sprintf( '%s field "%s" uses WAPF repeat behavior that OPF does not support on this field type; it was imported without repeat settings.', $mapped_type, $label );
				$needs_review = true;
				$repeat = [];
			}
			$date_settings = 'date' === $wapf_type ? self::map_date_settings( $wapf_field, $notes, $needs_review, $opf_ids_by_wapf_id ) : [];
			$number_settings = 'number' === $wapf_type ? self::map_number_settings( $wapf_field, $notes, $needs_review ) : [];
			$calc_settings = 'calc' === $wapf_type ? self::map_calc_settings( $wapf_field, $notes, $needs_review, $opf_ids_by_wapf_id, $source_order_by_wapf_id, (int) $index, $sumqty_references ) : [];
			$clone_disabled = in_array( $wapf_field['clone']['enabled'] ?? false, [ false, 0, '0', 'false', null ], true );
			if ( 'calc' === $wapf_type && ! $clone_disabled ) {
				$notes[] = sprintf( 'calc field "%s" uses WAPF clone/repeat behavior; OPF computes it once per group and the repeat marker was dropped.', $label );
				$needs_review = true;
			}
			$upload_settings = 'upload' === $mapped_type ? self::map_upload_settings( $wapf_field, $notes, $needs_review ) : [];
			$products_settings = 'products' === $mapped_type ? self::map_products_settings( $wapf_field, $notes, $needs_review ) : [];
			$toggle_settings = [];
			if ( 'true-false' === $wapf_type ) {
				$toggle_settings['message'] = (string) ( $wapf_field['options']['message'] ?? $wapf_field['message'] ?? '' );
				$default = $wapf_field['options']['default'] ?? $wapf_field['default'] ?? 'unchecked';
				if ( ! in_array( $default, [ 'checked', 'unchecked' ], true ) ) {
					$notes[] = 'Toggle default is unsupported; imported as unchecked.';
					$needs_review = true;
				}
				$toggle_settings['default'] = 'checked' === $default ? '1' : '0';
				// WAPF `label_true`/`label_false` are alternate cart/checkout/order
				// captions for the checked/unchecked states; OPF renders its
				// translated Yes/No values and has no per-field storage, so
				// custom captions are flagged rather than silently dropped.
				foreach ( [ 'label_true' => 'true', 'label_false' => 'false' ] as $state_key => $state_default ) {
					$state_label = $wapf_field['options'][ $state_key ] ?? $wapf_field[ $state_key ] ?? null;
					if ( null === $state_label || ( is_scalar( $state_label ) && $state_default === (string) $state_label ) ) {
						continue;
					}
					$notes[] = sprintf( 'toggle field "%s" uses custom %s text %s for cart/order display; OPF renders its translated Yes/No values instead and the label needs review.', $label, $state_key, self::review_value( $state_label ) );
					$needs_review = true;
				}
			}
			$text_settings = [];
			if ( in_array( $wapf_type, [ 'text', 'url' ], true ) && is_array( $wapf_field['options'] ?? null ) && array_key_exists( 'default', $wapf_field['options'] ) ) {
				$text_settings['default'] = $wapf_field['options']['default'];
			}

			$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
			$switch_settings = [];
			if ( in_array( $mapped_type, [ 'toggle', 'checkbox' ], true ) && self::truthy( $options['switch_control'] ?? $wapf_field['switch_control'] ?? false ) ) {
				// WAPF Pro serializes the is-switch presentation for true/false
				// and checkbox fields; OPF stores the same key on those two types.
				$switch_settings['switch_control'] = true;
			}
			$image_quantity_settings = [];
			if ( 'image-swatch-qty' === $wapf_type && array_key_exists( 'large_image', $options ) ) {
				$large_image = $options['large_image'];
				if ( in_array( $large_image, [ true, false, 0, 1, '0', '1' ], true ) ) {
					$image_quantity_settings['image_zoom'] = in_array( $large_image, [ true, 1, '1' ], true );
				} else {
					$notes[] = sprintf( 'image quantity field "%s" has an invalid large_image setting; zoom setting needs review.', $label );
					$needs_review = true;
				}
			}
			$field = FieldGroup::normalize_field(
				array_merge( [
					'id'           => $field_id,
					'label'        => (string) ( $wapf_field['label'] ?? '' ),
					'description'  => (string) ( $wapf_field['description'] ?? '' ),
					'type'         => $mapped_type,
					'required'     => (bool) ( $wapf_field['required'] ?? false ),
					'width'        => (int) ( $wapf_field['width'] ?? 100 ),
					'css_class'    => (string) ( $wapf_field['class'] ?? '' ),
					'placeholder'  => (string) ( $wapf_field['options']['placeholder'] ?? '' ),
					'swatch_style' => in_array( $wapf_type, [ 'image-swatch', 'multi-image-swatch' ], true ) ? 'image' : ( in_array( $wapf_type, [ 'color-swatch', 'multi-color-swatch' ], true ) ? 'color' : 'text' ),
					'multiple'     => in_array( $wapf_type, [ 'multi-text-swatch', 'multi-image-swatch', 'multi-color-swatch' ], true ),
					'choices'      => 'products' === $mapped_type
						? self::map_product_choices( $wapf_field, $products_settings, $notes, $needs_review )
						: ( $has_choices ? self::map_choices( $wapf_field, $notes, $needs_review, $opf_ids_by_wapf_id, $source_order_by_wapf_id, (int) $index, $sumqty_references ) : [] ),
					'pricing'      => in_array( $mapped_type, [ 'upload', 'products' ], true )
						// Child-product lines and uploads never carry field-level
						// addon pricing in OPF; enabled source pricing is noted
						// by the settings mappers instead of failing normalize.
						? [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ]
						: self::map_field_pricing( $wapf_field, $notes, $needs_review, $opf_ids_by_wapf_id, $source_order_by_wapf_id, (int) $index, $sumqty_references ),
					'conditionals' => self::map_conditionals( $wapf_field, $notes, $opf_ids_by_wapf_id, $needs_review, $source_type_by_wapf_id ),
					'hide_cart'    => self::truthy( $options['hide_cart'] ?? $wapf_field['hide_cart'] ?? false ),
					'hide_checkout' => self::truthy( $options['hide_checkout'] ?? $wapf_field['hide_checkout'] ?? false ),
					'hide_order'   => self::truthy( $options['hide_order'] ?? $wapf_field['hide_order'] ?? false ),
					'content'      => $content,
					'image_url'    => $image_url,
					'image_id'     => $image_id,
					'content_format' => $content_format,
					'process_shortcodes' => $process_shortcodes,
					'repeat' => $repeat,
					// WAPF-COMMERCE-WEIGHT: field-level options.weight survives
					// the import verbatim; Calculator::field_weight substitutes
					// [qty]/[x] and floatvals exactly like WAPF 3.1.5.
					'weight' => self::map_weight( $wapf_field ),
				], $image_swatch_settings, $image_quantity_settings, $color_swatch_settings, $selection_limits, $checkbox_limits, $checkbox_columns, $text_validation, $quantity_limits, $date_settings, $number_settings, $calc_settings, $upload_settings, $products_settings, $toggle_settings, $switch_settings, $text_settings )
			);
			if ( 'paragraph' === $field['type'] ) {
				if ( ! empty( $wapf_field['required'] ) ) {
					$notes[] = sprintf( 'field "%s" is static content; its required setting was removed.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
				}
				if ( ! empty( $wapf_field['pricing']['enabled'] ) ) {
					$notes[] = sprintf( 'field "%s" is static content; its field pricing was removed.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
					$needs_review = true;
				}
			}

			if ( in_array( $wapf_type, [ 'image-swatch', 'multi-image-swatch', 'image-swatch-qty' ], true ) ) {
				$notes[] = sprintf( 'field "%s" is an image swatch; choice media references are imported, but image files are not bundled and attachment IDs may need remapping on the destination site.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
				$needs_review = true;
			}
			if ( 'image-swatch-qty' === $wapf_type ) {
				$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
				$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
				if ( isset( $options['label_pos'] ) && 'default' !== $options['label_pos'] ) {
					$notes[] = sprintf( 'image quantity field "%s" uses label position "%s"; OPF renders the label with its quantity input.', $label, (string) $options['label_pos'] );
					$needs_review = true;
				}
				foreach ( [ 'items_per_row', 'items_per_row_tablet', 'items_per_row_mobile' ] as $layout_key ) {
					if ( array_key_exists( $layout_key, $options ) && (int) $options[ $layout_key ] !== 3 ) {
						$notes[] = sprintf( 'image quantity field "%s" has custom %s=%s; OPF does not preserve this WAPF column setting.', $label, $layout_key, (string) $options[ $layout_key ] );
						$needs_review = true;
					}
				}
				if ( ! empty( $wapf_field['required'] ) ) {
					$notes[] = sprintf( 'image quantity field "%s" is required in WAPF; OPF quantity choices remain optional.', $label );
					$needs_review = true;
				}
			}
			if ( 'img' === $wapf_type && ! empty( $field['image_id'] ) ) {
				$notes[] = sprintf( 'field "%s" uses a site-local image attachment ID; verify or remap the attachment on the destination site.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
				$needs_review = true;
			}

			$fields[] = $field;
		}

		$section_depth = 0;
		foreach ( $fields as $mapped_field ) {
			if ( 'section' === $mapped_field['type'] ) {
				$section_depth++;
			} elseif ( 'section_end' === $mapped_field['type'] ) {
				if ( 0 === $section_depth ) {
					$notes[] = sprintf( 'section-end field "%s" has no matching section and needs review.', $mapped_field['id'] );
					$needs_review = true;
				} else {
					$section_depth--;
				}
			}
		}
		if ( $section_depth > 0 ) {
			$notes[]      = sprintf( '%d section field(s) have no matching section-end marker and need review.', $section_depth );
			$needs_review = true;
		}

		if ( $unsupported ) {
			$notes[]      = 'unsupported field types dropped: ' . implode( ', ', $unsupported );
			$needs_review = true;
		}

		$placement = self::map_placement( $wapf, $overrides, $notes, $needs_review );

		$group = FieldGroup::normalize(
			[
				'fields'          => $fields,
				'rule_groups'     => $placement,
				'mark_required'   => (bool) ( $wapf['layout']['mark_required'] ?? true ),
				'labels_position' => ( $wapf['layout']['labels_position'] ?? 'above' ),
			]
		);

		// WAPF formula variables ride along verbatim in WAPF's own shape
		// ({name,default,rules[]}); normalize() does not model them, so they
		// are attached after normalization — consumers read the key directly.
		$variables = self::map_variables( $wapf['variables'] ?? [], $opf_ids_by_wapf_id, $source_order_by_wapf_id, $sumqty_safe_wapf_ids, $notes, $needs_review );
		if ( $variables ) {
			$group['variables'] = $variables;
		}

		return [
			'group'        => $group,
			'notes'        => $notes,
			'needs_review' => $needs_review,
		];
	}

	/**
	 * Map a WAPF `variables` list. Names stay verbatim (evaluation is
	 * case-sensitive); rule `field` ids and `[field.*]`/`[price.*]`/function
	 * references inside `default`/`variable` bodies remap to OPF ids.
	 * Variable bodies are evaluated verbatim at runtime — they are not
	 * pricing formulas, so no qty-factor normalization applies.
	 *
	 * @param array<int,mixed> $wapf_variables Raw WAPF variable definitions.
	 * @return array<int,array{name:string,default:string,rules:array}>
	 */
	private static function map_variables( $wapf_variables, array $opf_ids_by_wapf_id, array $source_order_by_wapf_id, array $sumqty_safe_wapf_ids, array &$notes, bool &$needs_review ): array {
		$out = [];
		foreach ( is_array( $wapf_variables ) ? $wapf_variables : [] as $index => $variable ) {
			if ( ! is_array( $variable ) || '' === (string) ( $variable['name'] ?? '' ) ) {
				continue;
			}
			$name    = (string) $variable['name'];
			$default = self::map_formula_references( (string) ( $variable['default'] ?? '' ), $opf_ids_by_wapf_id, $notes, $needs_review, $name, $source_order_by_wapf_id, PHP_INT_MAX, $sumqty_safe_wapf_ids, 'variable' );
			$rules   = [];
			foreach ( (array) ( $variable['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$subject     = (string) ( $rule['field'] ?? '' );
				$rule_field  = $subject;
				if ( 'qty' !== $subject && '' !== $subject ) {
					if ( isset( $opf_ids_by_wapf_id[ $subject ] ) && is_string( $opf_ids_by_wapf_id[ $subject ] ) ) {
						$rule_field = $opf_ids_by_wapf_id[ $subject ];
					} elseif ( ! isset( $opf_ids_by_wapf_id[ $subject ] ) ) {
						$notes[]       = sprintf( 'variable "%s" rule references unavailable WAPF field ID "%s"; the rule needs review.', $name, $subject );
						$needs_review  = true;
					}
				}
				$mapped_variable = self::map_formula_references( (string) ( $rule['variable'] ?? '' ), $opf_ids_by_wapf_id, $notes, $needs_review, $name, $source_order_by_wapf_id, PHP_INT_MAX, $sumqty_safe_wapf_ids, 'variable' );
				$rules[]         = [
					'type'      => (string) ( $rule['type'] ?? 'field' ),
					'field'     => $rule_field,
					'condition' => (string) ( $rule['condition'] ?? '' ),
					'value'     => (string) ( $rule['value'] ?? '' ),
					'variable'  => null === $mapped_variable ? (string) ( $rule['variable'] ?? '' ) : $mapped_variable,
				];
			}
			$out[] = [
				'name'    => $name,
				'default' => null === $default ? (string) ( $variable['default'] ?? '' ) : $default,
				'rules'   => $rules,
			];
		}
		return $out;
	}

	/** Map WAPF Extended date constraints supported by the OPF date schema. */
	/**
	 * Map a WAPF Extended `calc` field.
	 *
	 * `calc_type` selects informational vs cost pricing; `formula` is remapped
	 * to OPF field ids through the shared formula-reference remapper so
	 * dependencies resolve in either field order. Only genuinely unportable
	 * formula references flag the import for review.
	 *
	 * @param array<string,mixed> $wapf_field              WAPF field.
	 * @param string[]            $sumqty_safe_wapf_ids    Source ids whose sumQty() maps 1:1.
	 * @return array<string,mixed> Calc settings merged into the normalized field.
	 */
	private static function map_calc_settings( array $wapf_field, array &$notes, bool &$needs_review, array $opf_ids_by_wapf_id, array $source_order_by_wapf_id, int $current_order, array $sumqty_safe_wapf_ids ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$label   = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );

		$raw_type = (string) ( $options['calc_type'] ?? $wapf_field['calc_type'] ?? 'default' );
		$type     = in_array( $raw_type, [ 'default', 'cost' ], true ) ? $raw_type : 'default';
		if ( '' !== $raw_type && ! in_array( $raw_type, [ 'default', 'cost' ], true ) ) {
			$notes[]      = sprintf( 'calc field "%s" has unknown calc_type "%s"; imported as an informational calculation.', $label, $raw_type );
			$needs_review = true;
		}

		$formula = (string) ( $options['formula'] ?? $wapf_field['formula'] ?? '' );
		$formula = str_replace( '[options_total]', '[addons]', trim( $formula ) );
		if ( '' !== $formula ) {
			$mapped = self::map_formula_references( $formula, $opf_ids_by_wapf_id, $notes, $needs_review, $label, $source_order_by_wapf_id, $current_order, $sumqty_safe_wapf_ids, 'calc' );
			// A formula with dangling references fails closed to a literal zero
			// rather than dropping the whole field; the note above flags review.
			$formula = null === $mapped ? '0' : $mapped;
		}

		$raw_format = (string) ( $options['result_format'] ?? $wapf_field['result_format'] ?? 'number' );
		if ( ! in_array( $raw_format, [ '', 'none', 'number' ], true ) ) {
			$notes[]      = sprintf( 'calc field "%s" has unknown result_format "%s"; imported as a formatted number.', $label, $raw_format );
			$needs_review = true;
		}
		$text = (string) ( $options['result_text'] ?? $wapf_field['result_text'] ?? '' );

		return [
			'calc_type'     => $type,
			'formula'       => $formula,
			'result_format' => 'none' === $raw_format ? 'none' : 'number',
			'result_text'   => '' === trim( $text ) ? '{result}' : $text,
		];
	}

	private static function map_date_settings( array $wapf_field, array &$notes, bool &$needs_review, array $opf_ids_by_wapf_id = [] ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$settings = [];
		foreach ( [ 'disable_past' => 'allow_past', 'disable_future' => 'allow_future' ] as $source => $target ) {
			if ( array_key_exists( $source, $options ) && in_array( $options[ $source ], [ true, false, 0, 1, '0', '1' ], true ) ) {
				$settings[ $target ] = ! in_array( $options[ $source ], [ true, 1, '1' ], true );
			} elseif ( array_key_exists( $source, $options ) ) {
				$notes[] = sprintf( 'date field "%s" has invalid %s value %s; date selection needs review.', $label, $source, self::review_value( $options[ $source ] ) );
				$needs_review = true;
			}
		}
		foreach ( [ 'min_date' => 'min_date', 'max_date' => 'max_date' ] as $source => $target ) {
			if ( ! isset( $options[ $source ] ) || '' === $options[ $source ] ) {
				continue;
			}
			$value = is_scalar( $options[ $source ] ) ? trim( (string) $options[ $source ] ) : '';
			$boundary = self::wapf_date_boundary( $value, $opf_ids_by_wapf_id );
			if ( null !== $boundary ) {
				$settings[ $target ] = $boundary;
			} else {
				$notes[] = sprintf( 'date field "%s" has unsupported %s value "%s"; the original value was not mapped and needs manual review.', $label, $source, $value );
				$needs_review = true;
			}
		}
		if ( isset( $options['disabled_days'] ) && is_array( $options['disabled_days'] ) ) {
			$weekdays = [];
			foreach ( $options['disabled_days'] as $day ) {
				if ( is_scalar( $day ) && preg_match( '/^[0-6]$/', (string) $day ) ) {
					$weekdays[] = (int) $day;
				} else {
					$notes[] = sprintf( 'date field "%s" has an unrecognized disabled weekday "%s"; weekday rules need review.', $label, is_scalar( $day ) ? (string) $day : '[complex value]' );
					$needs_review = true;
				}
			}
			$settings['disabled_weekdays'] = array_values( array_unique( $weekdays ) );
		} elseif ( isset( $options['disabled_days'] ) && is_scalar( $options['disabled_days'] ) && '' !== trim( (string) $options['disabled_days'] ) ) {
			$weekdays = [];
			foreach ( preg_split( '/\s*,\s*/', trim( (string) $options['disabled_days'] ) ) as $day ) {
				if ( preg_match( '/^[0-6]$/', $day ) ) {
					$weekdays[] = (int) $day;
				} else {
					$weekdays = [];
					$notes[] = sprintf( 'date field "%s" has an unrecognized disabled weekday "%s"; weekday rules need review.', $label, $day );
					$needs_review = true;
					break;
				}
			}
			if ( $weekdays || ! $needs_review ) {
				$settings['disabled_weekdays'] = array_values( array_unique( $weekdays ) );
			}
		} elseif ( array_key_exists( 'disabled_days', $options ) ) {
			if ( isset( $options['disabled_days'] ) && is_scalar( $options['disabled_days'] ) && '' === trim( (string) $options['disabled_days'] ) ) {
				$settings['disabled_weekdays'] = [];
			} else {
				$notes[] = sprintf( 'date field "%s" has malformed disabled_days; weekday rules need review.', $label );
				$needs_review = true;
			}
		}
		if ( isset( $options['disabled_dates'] ) && '' !== $options['disabled_dates'] ) {
			$raw_dates = is_scalar( $options['disabled_dates'] ) ? (string) $options['disabled_dates'] : '';
			$dates = [];
			foreach ( preg_split( '/\s*,\s*/', trim( $raw_dates ) ) as $raw_rule ) {
				$parts = preg_split( '/\s+/', trim( $raw_rule ) );
				$converted = [];
				foreach ( $parts as $part ) {
					$date = self::wapf_date_boundary( $part );
					if ( null === $date && preg_match( '/^\d{2}-\d{2}$/', $part ) && FieldValue::is_disabled_date( $part ) ) {
						$date = $part;
					}
					if ( null === $date ) {
						$converted = [];
						break;
					}
					$converted[] = $date;
				}
				if ( count( $converted ) === count( $parts ) && in_array( count( $converted ), [ 1, 2 ], true ) ) {
					$dates[] = implode( ' ', $converted );
				} else {
					$notes[] = sprintf( 'date field "%s" has unsupported disabled date rule "%s"; original disabled_dates value "%s" needs manual review.', $label, $raw_rule, $raw_dates );
					$needs_review = true;
				}
			}
			if ( $dates ) {
				$settings['disabled_dates'] = $dates;
			}
		}
		// WAPF `disable_today` is the boolean selection policy beside
		// disable_past/disable_future (class-config.php true-falses group);
		// it bans the site's current date on both the picker and the server.
		if ( array_key_exists( 'disable_today', $options ) ) {
			if ( in_array( $options['disable_today'], [ true, false, 0, 1, '0', '1' ], true ) ) {
				$settings['disable_today'] = in_array( $options['disable_today'], [ true, 1, '1' ], true );
			} else {
				$notes[] = sprintf( 'date field "%s" has invalid disable_today value %s; the setting needs review.', $label, self::review_value( $options['disable_today'] ) );
				$needs_review = true;
			}
		}
		if ( isset( $options['default'] ) && '' !== $options['default'] ) {
			$default = is_scalar( $options['default'] ) ? (string) $options['default'] : '[complex value]';
			$notes[] = sprintf( 'date field "%s" has WAPF default "%s"; OPF date fields do not store a default value, so it needs manual review.', $label, $default );
			$needs_review = true;
		}
		$known_options = [ 'placeholder', 'default', 'disable_past', 'disable_future', 'disable_today', 'disable_today_after', 'disabled_days', 'disabled_dates', 'min_date', 'max_date' ];
		$unknown_options = array_diff( array_keys( $options ), $known_options );
		if ( $unknown_options ) {
			$unknown_values = [];
			foreach ( $unknown_options as $key ) {
				$unknown_values[] = (string) $key . '=' . self::review_value( $options[ $key ] );
			}
			$notes[] = sprintf( 'date field "%s" has unrecognized WAPF options (%s); original values need manual review.', $label, implode( ', ', $unknown_values ) );
			$needs_review = true;
		}
		if ( ! empty( $options['disable_today_after'] ) ) {
			$value = is_scalar( $options['disable_today_after'] ) ? (string) $options['disable_today_after'] : '';
			if ( preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
				$settings['cutoff_time'] = $value;
			} else {
				$notes[] = sprintf( 'date field "%s" has unsupported disable_today_after value "%s"; cutoff needs manual review.', $label, $value );
				$needs_review = true;
			}
		}
		return $settings;
	}

	/** Convert WAPF's mm-dd-yyyy literal or field-relative bound to OPF's ISO/expression.
	 *
	 * WAPF `wapfe_get_minmax_day` (extend/date.php:110) accepts a literal
	 * `mm-dd-yyyy`, a relative period, or `[field.<wapfId>]<period>` where the
	 * period is resolved against the referenced date field. References are
	 * remapped onto the generated OPF field id; an unresolved reference stays
	 * review-required rather than silently dropping the constraint.
	 */
	private static function wapf_date_boundary( string $value, array $opf_ids_by_wapf_id = [] ): ?string {
		if ( preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $value, $match ) ) {
			$date = sprintf( '%04d-%02d-%02d', (int) $match[3], (int) $match[1], (int) $match[2] );
			return FieldValue::is_date_boundary( $date ) ? $date : null;
		}
		if ( preg_match( '/^\[field\.([A-Za-z0-9_-]+)\](.*)$/s', $value, $match ) ) {
			$reference = $match[1];
			if ( ! array_key_exists( $reference, $opf_ids_by_wapf_id ) || null === $opf_ids_by_wapf_id[ $reference ] ) {
				return null;
			}
			$expression = '[field.' . (string) $opf_ids_by_wapf_id[ $reference ] . ']';
			$period = trim( $match[2] );
			if ( '' !== $period ) {
				$expression .= $period;
			}
			return FieldValue::is_date_boundary( $expression ) ? $expression : null;
		}
		return FieldValue::is_date_boundary( $value ) ? $value : null;
	}

	/** Format a source option value for an actionable migration review note. */
	private static function review_value( $value ): string {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		return false === $encoded ? '[unserializable value]' : (string) $encoded;
	}

	/**
	 * Map WAPF `number` field settings to the OPF number schema.
	 *
	 * WAPF Extended 3.1.5 serializes `number_type` (int/any, default int),
	 * `display` (default/plus_min), `default`, `placeholder`, `minimum`,
	 * `maximum`, and `hide_zero` under `options`; there is no stored `step`
	 * key — its renderer only emits the literal `step="any"` for
	 * `number_type` != 'int' (class-html.php), so a numeric `number_type`
	 * behaves as a decimal field with that increment. OPF equivalents are
	 * `min`/`max`/`step` floats plus `number_mode` ('integer'|'decimal')
	 * and the per-field `display` ('default'|'plus_min') stepper mode.
	 * OPF has no `hide_zero` equivalent, so that option is flagged for review
	 * instead of silently dropped.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @return array<string,mixed>
	 */
	private static function map_number_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options  = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$label    = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$settings = [];

		foreach ( [ 'minimum' => 'min', 'maximum' => 'max' ] as $source => $target ) {
			$raw = $options[ $source ] ?? $wapf_field[ $source ] ?? null;
			if ( null === $raw || '' === $raw ) {
				continue;
			}
			if ( is_numeric( $raw ) ) {
				$settings[ $target ] = (float) $raw;
			} else {
				$notes[] = sprintf( 'number field "%s" has a non-numeric %s bound %s; the bound was not imported and needs review.', $label, $source, self::review_value( $raw ) );
				$needs_review = true;
			}
		}
		if ( isset( $settings['min'], $settings['max'] ) && $settings['min'] > $settings['max'] ) {
			// WAPF stores any min/max pair while OPF normalization rejects
			// min > max; keep the minimum and drop the upper bound so the
			// field still imports, and flag the loss for manual review.
			$notes[] = sprintf( 'number field "%s" has a minimum (%s) above its maximum (%s); the maximum was not imported and the bounds need review.', $label, (string) $settings['min'], (string) $settings['max'] );
			$needs_review = true;
			unset( $settings['max'] );
		}

		$number_type = $options['number_type'] ?? $wapf_field['number_type'] ?? 'int';
		if ( ! is_scalar( $number_type ) ) {
			$settings['number_mode'] = 'integer';
			$notes[] = sprintf( 'number field "%s" has an invalid number_type; imported as an integer field and needs review.', $label );
			$needs_review = true;
		} elseif ( 'any' === (string) $number_type ) {
			$settings['number_mode'] = 'decimal';
		} elseif ( is_numeric( $number_type ) && (float) $number_type > 0 ) {
			// WAPF writes `number_type` verbatim into the step attribute, so a
			// numeric value acts as a custom increment on a decimal field.
			$settings['number_mode'] = 'decimal';
			$settings['step']        = (float) $number_type;
		} else {
			$settings['number_mode'] = 'integer';
			if ( 'int' !== (string) $number_type && '' !== (string) $number_type ) {
				$notes[] = sprintf( 'number field "%s" has an unrecognized number_type %s; imported as an integer field and needs review.', $label, self::review_value( $number_type ) );
				$needs_review = true;
			}
		}

		$raw_step = $options['step'] ?? $wapf_field['step'] ?? null;
		if ( null !== $raw_step && '' !== $raw_step ) {
			if ( is_numeric( $raw_step ) && (float) $raw_step > 0 ) {
				$settings['step'] = (float) $raw_step;
			}
			// Installed WAPF 3.1.5 never serializes `step`; the value is
			// preserved when valid but its source shape stays review-required.
			$notes[] = sprintf( 'number field "%s" carries a stored step %s; WAPF 3.1.5 does not serialize step, so the imported value needs review.', $label, self::review_value( $raw_step ) );
			$needs_review = true;
		}

		$display = $options['display'] ?? $wapf_field['display'] ?? null;
		if ( null !== $display ) {
			if ( ! is_scalar( $display ) ) {
				$notes[] = sprintf( 'number field "%s" has an invalid display setting; the standard number input applies.', $label );
				$needs_review = true;
			} elseif ( in_array( (string) $display, [ 'default', 'plus_min' ], true ) ) {
				// WAPF per-field stepper (class-config.php:614). Preserved so a
				// field that does not set it keeps the global OPF default.
				$settings['display'] = (string) $display;
			} elseif ( '' !== (string) $display ) {
				$notes[] = sprintf( 'number field "%s" has an unrecognized display %s; the standard number input applies.', $label, self::review_value( $display ) );
				$needs_review = true;
			}
		}

		if ( ! empty( $options['hide_zero'] ) || ! empty( $wapf_field['hide_zero'] ) ) {
			$notes[] = sprintf( 'number field "%s" hides zero values in the WAPF cart, checkout, and order screens; OPF has no equivalent and the setting needs review.', $label );
			$needs_review = true;
		}

		$raw_default = $options['default'] ?? $wapf_field['default'] ?? null;
		if ( null !== $raw_default && '' !== $raw_default ) {
			if ( is_scalar( $raw_default ) ) {
				// OPF stores the sanitized scalar; the default must satisfy
				// the same constraints normalize() enforces on it, so it is
				// pre-validated to keep a bad WAPF default from failing the
				// whole group import.
				$default = trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $raw_default ) ) );
				if ( '' !== $default ) {
					$probe = array_merge( [ 'type' => 'number', 'label' => $label, 'required' => false ], $settings );
					if ( [] === FieldValue::validate( $probe, $default, true ) ) {
						$settings['default'] = $raw_default;
					} else {
						$notes[] = sprintf( 'number field "%s" has a default %s that violates its imported constraints; the default was not mapped and needs review.', $label, self::review_value( $raw_default ) );
						$needs_review = true;
					}
				}
			} else {
				$notes[] = sprintf( 'number field "%s" has a non-scalar default; it was not mapped and needs review.', $label );
				$needs_review = true;
			}
		}

		$known_options   = [ 'number_type', 'display', 'default', 'placeholder', 'minimum', 'maximum', 'step', 'hide_zero', 'weight', 'pricing', 'hide_cart', 'hide_checkout', 'hide_order' ];
		$unknown_options = array_diff( array_keys( $options ), $known_options );
		if ( $unknown_options ) {
			$unknown_values = [];
			foreach ( $unknown_options as $key ) {
				$unknown_values[] = (string) $key . '=' . self::review_value( $options[ $key ] );
			}
			$notes[] = sprintf( 'number field "%s" has unrecognized WAPF options (%s); original values need manual review.', $label, implode( ', ', $unknown_values ) );
			$needs_review = true;
		}

		return $settings;
	}

	/** Map WAPF clone settings and flag only settings without an OPF equivalent. */
	private static function map_repeat_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$clone = $wapf_field['clone'] ?? [];
		if ( ! is_array( $clone ) || [] === $clone ) {
			return [];
		}
		$enabled = $clone['enabled'] ?? false;
		if ( in_array( $enabled, [ false, 0, '0', 'false', null ], true ) ) {
			return [];
		}
		if ( ! in_array( $enabled, [ true, 1, '1', 'true' ], true ) ) {
			$notes[] = sprintf( 'field "%s" has an invalid WAPF clone enabled flag; its repeat settings need manual review.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
			$needs_review = true;
			return [];
		}

		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$unknown_clone_keys = array_diff( array_keys( $clone ), [ 'enabled', 'type', 'max', 'add', 'del', 'label', 'field' ] );
		if ( $unknown_clone_keys ) {
			$notes[] = sprintf( 'field "%s" has unsupported WAPF clone settings (%s); they need manual review.', $label, implode( ', ', array_map( 'strval', $unknown_clone_keys ) ) );
			$needs_review = true;
		}
		if ( 'sectionend' === ( $wapf_field['type'] ?? '' ) ) {
			$notes[] = sprintf( 'field "%s" is a WAPF section-end marker with clone settings; the marker was imported without repeat settings and needs review.', $label );
			$needs_review = true;
			return [];
		}
		$type = (string) ( $clone['type'] ?? '' );
		if ( ! in_array( $type, [ 'button', 'qty' ], true ) ) {
			$notes[] = sprintf( 'field "%s" uses unsupported WAPF clone type "%s"; its repeat settings need manual review.', $label, $type );
			$needs_review = true;
			return [];
		}

		$repeat = [ 'enabled' => true, 'mode' => 'qty' === $type ? 'quantity' : 'button' ];
		$can_map_repeat = true;
		if ( array_key_exists( 'label', $clone ) ) {
			$repeat['label'] = $clone['label'];
		}
		if ( 'button' === $type ) {
			$max = $clone['max'] ?? RepeaterField::DEFAULT_BUTTON_ROWS;
			if ( '' === $max ) {
				$max = RepeaterField::DEFAULT_BUTTON_ROWS;
			}
			foreach ( [ 'add', 'del' ] as $key ) {
				if ( array_key_exists( $key, $clone ) && '' !== $clone[ $key ] ) {
					$repeat[ $key ] = $clone[ $key ];
				}
			}
			$repeat['max'] = $max;
		}
		try {
			$repeat = RepeaterField::normalize( $repeat );
		} catch ( \InvalidArgumentException $exception ) {
			$notes[] = sprintf( 'field "%s" has invalid or unrepresentable repeater settings; the repeat settings need manual review.', $label );
			$needs_review = true;
			$can_map_repeat = false;
		}
		$mapped_type = self::TYPE_MAP[ (string) ( $wapf_field['type'] ?? '' ) ] ?? '';
		$repeatable_types = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch' ];
		if ( 'section' === ( $wapf_field['type'] ?? '' ) || ( 'qty' === $type && ! in_array( $mapped_type, $repeatable_types, true ) ) ) {
			$notes[] = sprintf( 'field "%s" uses quantity or section repeat behavior that OPF does not implement yet.', $label );
			$needs_review = true;
		}
		if ( ! empty( $clone['field'] ) ) {
			$notes[] = sprintf( 'field "%s" uses a WAPF clone field reference that OPF does not preserve yet.', $label );
			$needs_review = true;
		}
		if ( ! $can_map_repeat ) {
			return [];
		}

		return $repeat;
	}

	/**
	 * Map choices with pricing.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @param string[]            $notes      Collector.
	 * @return array<int,array>
	 */
	private static function map_choices( array $wapf_field, array &$notes, bool &$needs_review, array $opf_ids_by_wapf_id, array $source_order_by_wapf_id, int $current_order, array $sumqty_safe_wapf_ids ): array {
		$choices = [];
		foreach ( ( $wapf_field['options']['choices'] ?? [] ) as $choice ) {
			if ( ! is_array( $choice ) ) {
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
					$pricing = [ 'type' => 'percent', 'amount' => (float) $amt, 'formula' => '', 'per_unit' => true ];
					break;
				case 'p':
					// WAPF p = percent of the base once per line (flat).
					$pricing = [ 'type' => 'percent', 'amount' => (float) $amt, 'formula' => '', 'per_unit' => false ];
					break;
				case 'fx':
					$formula_raw = self::map_formula_references( (string) $amt, $opf_ids_by_wapf_id, $notes, $needs_review, (string) ( $choice['label'] ?? $slug ), $source_order_by_wapf_id, $current_order, $sumqty_safe_wapf_ids, 'choice' );
					$formula = null === $formula_raw ? null : self::normalize_formula( $formula_raw, ! empty( $wapf_field['qty_based'] ) );
					if ( null === $formula ) {
						$notes[] = sprintf( 'choice "%s" formula could not be translated: %s', $choice['label'] ?? $slug, (string) $amt );
						$needs_review = true;
						$pricing = [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
					} else {
						// formula_raw keeps the legacy expression (incl. its qty
						// factor) for the theme's live-total display math. WAPF
						// fx on a normal field is flat per line; an outermost
						// *[qty] compensation factor means the author intended
						// per-unit scaling.
						$pricing = [ 'type' => 'formula', 'amount' => 0.0, 'formula' => $formula, 'formula_raw' => $formula_raw, 'per_unit' => self::formula_had_qty_factor( $formula_raw ) ];
					}
					break;
				case 'nr':
				case 'nrq':
				case 'char':
				case 'charq':
					// WAPF value-based pricing: amount × field value / character
					// count (class-fields.php:290-297). nr/char are flat per line;
					// nrq/charq scale per unit. Expressed as OPF [x]/len() formulas.
					$value_pricing = self::map_value_pricing( $ptype, (float) $amt );
					if ( null === $value_pricing ) {
						$notes[] = sprintf( 'choice "%s" %s pricing could not be translated; imported without pricing.', $choice['label'] ?? $slug, $ptype );
						$needs_review = true;
					} else {
						$pricing = $value_pricing;
					}
					break;
				case 'none':
					break;
				default:
					$notes[] = sprintf( 'choice "%s" uses pricing type "%s" which is not supported; imported without pricing.', $choice['label'] ?? $slug, $ptype );
					$needs_review = true;
					break;
			}

			$mapped_choice = [
				'slug'     => $slug,
				'label'    => (string) ( $choice['label'] ?? '' ),
				'selected' => (bool) ( $choice['selected'] ?? false ),
				'disabled' => (bool) ( $choice['disabled'] ?? false ),
				'pricing'  => $pricing,
			];
			// WAPF-COMMERCE-WEIGHT: choice options.weight (all choice-bearing
			// types) keeps its verbatim expression for Calculator::field_weight.
			$choice_weight = is_array( $choice['options'] ?? null ) ? ( $choice['options']['weight'] ?? null ) : null;
			if ( is_scalar( $choice_weight ) && '' !== trim( (string) $choice_weight ) ) {
				$mapped_choice['weight'] = trim( (string) $choice_weight );
			}
			if ( 'image-swatch-qty' === ( $wapf_field['type'] ?? '' ) ) {
				$choice_options = is_array( $choice['options'] ?? null ) ? $choice['options'] : [];
				$minimum = 0;
				$maximum = 999999;
				$default = 0;
				foreach ( [ 'min', 'max', 'default' ] as $key ) {
					if ( ! array_key_exists( $key, $choice_options ) || '' === $choice_options[ $key ] || null === $choice_options[ $key ] ) {
						continue;
					}
					$value = $choice_options[ $key ];
					if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) {
						if ( 'min' === $key ) {
							$minimum = (int) $value;
						} elseif ( 'max' === $key ) {
							$maximum = (int) $value;
						} else {
							$default = (int) $value;
						}
					} else {
						$notes[] = sprintf( 'image quantity choice "%s" has an invalid %s setting; WAPF integer conversion needs review.', $choice['label'] ?? $slug, $key );
						$needs_review = true;
					}
				}
				if ( $minimum < 0 || $minimum > 999999 || $maximum < $minimum || $maximum > 999999 || $default < $minimum || $default > $maximum ) {
					$notes[] = sprintf( 'image quantity choice "%s" has bounds/default that OPF normalizes; verify the imported quantity behavior.', $choice['label'] ?? $slug );
					$needs_review = true;
				}
				$mapped_choice['quantity'] = [ 'default' => max( $minimum, min( $maximum, $default ) ), 'min' => max( 0, min( 999999, $minimum ) ), 'max' => max( max( 0, min( 999999, $minimum ) ), min( 999999, $maximum ) ) ];
				// Choice weight is mapped above for every choice type, so the
				// qty_selector multiply path reaches Calculator::field_weight.
			}
			if ( is_string( $choice['image'] ?? null ) ) {
				$mapped_choice['image'] = $choice['image'];
			}
			if ( is_string( $choice['color'] ?? null ) ) {
				if ( preg_match( '/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{5})?$/', $choice['color'] ) ) {
					$mapped_choice['color'] = strtoupper( $choice['color'] );
				} else {
					$notes[] = sprintf( 'choice "%s" has an unsupported color value; color swatch needs review.', $choice['label'] ?? $slug );
					$needs_review = true;
				}
			} elseif ( in_array( $wapf_field['type'] ?? '', [ 'color-swatch', 'multi-color-swatch' ], true ) ) {
				$notes[] = sprintf( 'choice "%s" has no color value; color swatch needs review.', $choice['label'] ?? $slug );
				$needs_review = true;
			}
			$attachment_id = $choice['attachment'] ?? null;
			if ( ( is_int( $attachment_id ) || ( is_string( $attachment_id ) && ctype_digit( $attachment_id ) ) ) && (int) $attachment_id > 0 ) {
				$mapped_choice['image_id'] = (int) $attachment_id;
			}
			$choices[] = $mapped_choice;
		}
		return $choices;
	}

	/**
	 * Field-level WAPF "Extra weight" option (options.weight). The expression
	 * is kept verbatim — [qty]/[x] substitution and floatval happen at cart
	 * time exactly like WAPF 3.1.5. Returns null when unset so normalize()
	 * simply omits the key.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 */
	private static function map_weight( array $wapf_field ): ?string {
		$weight = $wapf_field['options']['weight'] ?? null;
		if ( ! is_scalar( $weight ) ) {
			return null;
		}
		$weight = trim( (string) $weight );
		return '' === $weight ? null : $weight;
	}

	/** Map the installed WAPF Extended image-swatch display settings. */
	private static function map_image_swatch_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$allowed = [
			'label_pos' => [ 'default', 'out', 'hide', 'tooltip' ],
			'grid_layout' => [ 'fixed', 'flexible' ],
		];
		foreach ( $allowed as $key => $values ) {
			if ( ! array_key_exists( $key, $options ) ) {
				continue;
			}
			if ( is_string( $options[ $key ] ) && in_array( $options[ $key ], $values, true ) ) {
				$settings[ $key ] = $options[ $key ];
			} else {
				$notes[] = sprintf( 'image swatch "%s" has an unsupported %s setting; WAPF default applies.', $label, $key );
				$needs_review = true;
			}
		}
		$integer_settings = [
			'item_width' => [ 20, 300 ],
			'items_per_row' => [ 1, 15 ],
			'items_per_row_tablet' => [ 1, 10 ],
			'items_per_row_mobile' => [ 1, 10 ],
		];
		foreach ( $integer_settings as $key => $range ) {
			if ( ! array_key_exists( $key, $options ) ) {
				continue;
			}
			$value = $options[ $key ];
			if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= $range[0] && (int) $value <= $range[1] ) {
				$settings[ $key ] = (int) $value;
			} else {
				$notes[] = sprintf( 'image swatch "%s" has an invalid %s setting; WAPF default applies.', $label, $key );
				$needs_review = true;
			}
		}
		if ( array_key_exists( 'large_image', $options ) ) {
			$value = $options['large_image'];
			if ( in_array( $value, [ true, false, 0, 1, '0', '1' ], true ) ) {
				$settings['image_zoom'] = in_array( $value, [ true, 1, '1' ], true );
			} else {
				$notes[] = sprintf( 'image swatch "%s" has an invalid large_image setting; zoom setting needs review.', $label );
				$needs_review = true;
			}
		}
		return $settings;
	}

	/** Map WAPF Extended color swatch layout, size, and selection limits. */
	private static function map_color_swatch_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		if ( isset( $options['layout'] ) ) {
			if ( in_array( $options['layout'], [ 'square', 'rounded', 'circle' ], true ) ) {
				$settings['color_layout'] = $options['layout'];
			} else {
				$notes[] = sprintf( 'color swatch "%s" has an unsupported layout; WAPF default applies.', $label );
				$needs_review = true;
			}
		}
		if ( isset( $options['size'] ) ) {
			$value = $options['size'];
			if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 5 && (int) $value <= 500 ) {
				$settings['color_size'] = (int) $value;
			} else {
				$notes[] = sprintf( 'color swatch "%s" has an invalid size; WAPF default applies.', $label );
				$needs_review = true;
			}
		}
		if ( isset( $options['label_pos'] ) ) {
			if ( in_array( $options['label_pos'], [ 'default', 'hide', 'tooltip' ], true ) ) {
				$settings['color_label_pos'] = $options['label_pos'];
			} else {
				$notes[] = sprintf( 'color swatch "%s" has an unsupported label position; WAPF default applies.', $label );
				$needs_review = true;
			}
		}
		return $settings;
	}

	/** Map cardinality options shared by WAPF's three multi-swatch types. */
	private static function map_swatch_selection_limits( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
			if ( ! array_key_exists( $key, $options ) || '' === $options[ $key ] || null === $options[ $key ] ) {
				continue;
			}
			$value = $options[ $key ];
			if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 1 && (int) $value <= 10000 ) {
				$settings[ $key ] = (int) $value;
			} else {
				$notes[] = sprintf( 'multi swatch "%s" has an invalid %s value; selection limit needs review.', $label, $key );
				$needs_review = true;
			}
		}
		if ( isset( $settings['min_choices'], $settings['max_choices'] ) && $settings['min_choices'] > $settings['max_choices'] ) {
			$notes[] = sprintf( 'multi swatch "%s" has min_choices greater than max_choices; selection limits need review.', $label );
			unset( $settings['min_choices'], $settings['max_choices'] );
			$needs_review = true;
		}
		return $settings;
	}

	/**
	 * Map WAPF image quantity aggregate limits without changing per-choice bounds.
	 *
	 * @param array<string,mixed> $wapf_field Source field.
	 * @param string[]            $notes      Import notes.
	 * @param bool                $needs_review Review flag.
	 * @return array<string,int>
	 */
	private static function map_image_quantity_limits( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
			if ( ! array_key_exists( $key, $options ) || '' === $options[ $key ] || null === $options[ $key ] ) {
				continue;
			}
			$value = $options[ $key ];
			if ( ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) || (int) $value < 0 || (int) $value > 999999 ) {
				$notes[] = sprintf( 'image quantity field "%s" has an invalid %s setting; the aggregate limit was not imported.', $label, $key );
				$needs_review = true;
				continue;
			}
			$settings[ $key ] = (int) $value;
		}
		if ( isset( $settings['min_choices'], $settings['max_choices'] ) && $settings['min_choices'] > $settings['max_choices'] ) {
			$notes[] = sprintf( 'image quantity field "%s" has min_choices greater than max_choices; aggregate limits need review.', $label );
			$needs_review = true;
			unset( $settings['min_choices'], $settings['max_choices'] );
		}
		return $settings;
	}

	/**
	 * Loose WAPF boolean check: stored groups use booleans while Tools/JSON
	 * payloads serialize flags as 'true'/'false' strings.
	 */
	private static function truthy( $value ): bool {
		return in_array( $value, [ true, 1, '1', 'true' ], true );
	}

	/**
	 * Map WAPF `file` upload settings to OPF `upload` keys.
	 *
	 * WAPF stores `multiple` (bool), `maxsize` (MB number) and `accept`
	 * (comma-separated wp mime-map keys such as "jpg|jpeg|jpe,pdf"). OPF keeps
	 * individual extensions; alternate-extension groups pass through the
	 * normalizer's `|` split.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @return array<string,mixed>
	 */
	private static function map_upload_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$label   = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$settings = [];

		if ( array_key_exists( 'multiple', $options ) ) {
			$settings['multiple'] = self::truthy( $options['multiple'] );
		}
		if ( array_key_exists( 'maxsize', $options ) && '' !== $options['maxsize'] && null !== $options['maxsize'] ) {
			if ( is_numeric( $options['maxsize'] ) && (float) $options['maxsize'] >= 0 ) {
				$settings['max_size'] = (float) $options['maxsize'];
			} else {
				$notes[] = sprintf( 'upload field "%s" has an invalid maximum size %s; the default applies.', $label, self::review_value( $options['maxsize'] ) );
				$needs_review = true;
			}
		}
		$accept = $options['accept'] ?? null;
		if ( is_array( $accept ) ) {
			$accept = implode( ',', array_map( 'strval', $accept ) );
		}
		if ( is_string( $accept ) && '' !== trim( $accept ) ) {
			$extensions = [];
			foreach ( preg_split( '/\s*,\s*/', trim( $accept ) ) as $token ) {
				// Tokens are wp mime-map keys (extension groups joined by |);
				// anything else cannot survive OPF upload normalization.
				if ( is_string( $token ) && preg_match( '/^\.?[a-zA-Z0-9]+(?:\|[a-zA-Z0-9]+)*$/', $token ) ) {
					$extensions[] = $token;
				} else {
					$notes[] = sprintf( 'upload field "%s" has an unrecognized accepted-type token %s; it needs review.', $label, self::review_value( $token ) );
					$needs_review = true;
				}
			}
			if ( $extensions ) {
				$settings['accepted_types'] = $extensions;
			}
		}

		$pricing = $wapf_field['pricing'] ?? [];
		if ( is_array( $pricing ) && ! empty( $pricing['enabled'] ) ) {
			$notes[] = sprintf( 'upload field "%s" has WAPF pricing enabled; OPF upload fields do not charge and it was imported without pricing.', $label );
			$needs_review = true;
		}
		return $settings;
	}

	/**
	 * Map WAPF `products` field settings to the OPF products schema.
	 *
	 * WAPF stores the subtype either flattened into the type (`products-card`)
	 * or as a `subtype` field key; every subtype-specific option lives under
	 * `options` and is gated by the subtype, mirroring WAPF's own
	 * Linked_Products_Controller::sanitize_field_data rules.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @return array<string,mixed>
	 */
	private static function map_products_settings( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$label   = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$wapf_type = (string) ( $wapf_field['type'] ?? '' );

		if ( str_starts_with( $wapf_type, 'products-' ) ) {
			$subtype = substr( $wapf_type, strlen( 'products-' ) );
		} else {
			$subtype = (string) ( $wapf_field['subtype'] ?? $options['subtype'] ?? 'checkbox' );
			$subtype = (string) preg_replace( '/^products-/', '', $subtype );
		}
		if ( ! in_array( $subtype, FieldGroup::PRODUCTS_SUBTYPES, true ) ) {
			$notes[] = sprintf( 'products field "%s" has an unsupported subtype "%s"; imported as checkboxes.', $label, $subtype );
			$needs_review = true;
			$subtype = 'checkbox';
		}
		$qty_subtype = in_array( $subtype, [ 'card-qty', 'vcard-qty' ], true );
		$settings = [ 'subtype' => $subtype ];

		$selection = (string) ( $options['product_selection'] ?? 'manual' );
		if ( ! in_array( $selection, [ 'manual', 'category' ], true ) ) {
			$notes[] = sprintf( 'products field "%s" has an unsupported product selection "%s"; manual selection applies.', $label, $selection );
			$needs_review = true;
			$selection = 'manual';
		}
		$settings['product_selection'] = $selection;

		if ( 'category' === $selection ) {
			$query = is_array( $options['product_query'] ?? null ) ? $options['product_query'] : [];
			if ( empty( $query['query_id'] ) || ! is_numeric( $query['query_id'] ) ) {
				$notes[] = sprintf( 'products field "%s" uses category selection without a category; the imported field has no choices and needs review.', $label );
				$needs_review = true;
			}
			$settings['product_query'] = [
				'query_id'     => isset( $query['query_id'] ) && is_numeric( $query['query_id'] ) ? (int) $query['query_id'] : 0,
				'query_label'  => (string) ( $query['query_label'] ?? '' ),
				'limit'        => isset( $query['limit'] ) && is_numeric( $query['limit'] ) ? (int) $query['limit'] : 5,
				'sort'         => in_array( $query['sort'] ?? '', [ 'name_asc', 'name_desc', 'date_asc', 'date_desc' ], true ) ? $query['sort'] : 'date_desc',
				'pricing_type' => in_array( $query['pricing_type'] ?? '', FieldGroup::PRODUCTS_PRICING_TYPES, true ) ? $query['pricing_type'] : 'fixed',
			];
		}

		if ( ! $qty_subtype ) {
			$qty_method = (string) ( $options['qty_method'] ?? 'one' );
			if ( ! in_array( $qty_method, [ 'one', 'parent' ], true ) ) {
				$notes[] = sprintf( 'products field "%s" has an unsupported quantity method "%s"; the default applies.', $label, $qty_method );
				$needs_review = true;
			} else {
				$settings['qty_method'] = $qty_method;
			}
		}

		if ( $qty_subtype ) {
			if ( isset( $options['display'] ) ) {
				if ( in_array( $options['display'], [ 'default', 'plus_min' ], true ) ) {
					$settings['display'] = $options['display'];
				} else {
					$notes[] = sprintf( 'products field "%s" has an unsupported quantity display "%s"; the default applies.', $label, (string) $options['display'] );
					$needs_review = true;
				}
			}
			foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
				if ( ! array_key_exists( $key, $options ) || '' === $options[ $key ] || null === $options[ $key ] ) {
					continue;
				}
				if ( ( is_int( $options[ $key ] ) || ( is_string( $options[ $key ] ) && preg_match( '/^-?\d+$/', $options[ $key ] ) ) ) && (int) $options[ $key ] >= 0 ) {
					$settings[ $key ] = (int) $options[ $key ];
				} else {
					$notes[] = sprintf( 'products field "%s" has an invalid %s setting; the aggregate quantity limit needs review.', $label, $key );
					$needs_review = true;
				}
			}
		} elseif ( ! in_array( $subtype, [ 'radio', 'dropdown' ], true ) ) {
			// WAPF min/max_choices on non-quantity subtypes cap the number of
			// selected products; OPF products fields have no equivalent yet.
			foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
				if ( array_key_exists( $key, $options ) && '' !== $options[ $key ] && null !== $options[ $key ] ) {
					$notes[] = sprintf( 'products field "%s" uses a WAPF %s selection limit that OPF does not enforce; it needs review.', $label, $key );
					$needs_review = true;
				}
			}
		}

		if ( 'image' === $subtype ) {
			if ( isset( $options['label_pos'] ) && in_array( $options['label_pos'], [ 'default', 'out', 'hide', 'tooltip' ], true ) ) {
				$settings['label_pos'] = $options['label_pos'];
			}
			// WAPF's admin default is 68px; OPF falls back to 60px when the key
			// is absent, so the WAPF default is made explicit here.
			$item_width = $options['item_width'] ?? 68;
			$settings['item_width'] = is_numeric( $item_width ) ? (int) $item_width : 68;
		}

		if ( in_array( $subtype, [ 'card', 'vcard', 'card-qty', 'vcard-qty' ], true ) ) {
			foreach ( [ 'items_per_row', 'items_per_row_tablet', 'items_per_row_mobile' ] as $key ) {
				if ( ! isset( $options[ $key ] ) ) {
					continue;
				}
				if ( is_numeric( $options[ $key ] ) && (int) $options[ $key ] >= 1 ) {
					$settings[ $key ] = (int) $options[ $key ];
				} else {
					$notes[] = sprintf( 'products field "%s" has an invalid %s setting; the card layout needs review.', $label, $key );
					$needs_review = true;
				}
			}
			foreach ( [ 'incl_img', 'incl_desc' ] as $key ) {
				if ( array_key_exists( $key, $options ) ) {
					$settings[ $key ] = self::truthy( $options[ $key ] );
				}
			}
			foreach ( [ 'slot_1', 'slot_2', 'slot_3' ] as $key ) {
				if ( ! isset( $options[ $key ] ) || '' === $options[ $key ] ) {
					continue;
				}
				if ( in_array( $options[ $key ], [ 'none', 'price', 'stock', 'link' ], true ) ) {
					$settings[ $key ] = $options[ $key ];
				} else {
					$notes[] = sprintf( 'products field "%s" has an unsupported %s value "%s"; the slot is empty.', $label, $key, (string) $options[ $key ] );
					$needs_review = true;
				}
			}
			if ( in_array( $subtype, [ 'vcard', 'vcard-qty' ], true ) && isset( $options['img_fit'] ) ) {
				if ( in_array( $options['img_fit'], [ 'cover', 'contain' ], true ) ) {
					$settings['img_fit'] = $options['img_fit'];
				} else {
					$notes[] = sprintf( 'products field "%s" has an unsupported image fit "%s"; the default applies.', $label, (string) $options['img_fit'] );
					$needs_review = true;
				}
			}
		}

		// WAPF 3.2.1 adds `large_image` specifically to Products-image fields.
		// OPF `image_zoom` is a separate gallery-swap behavior.
		if ( 'image' === $subtype && array_key_exists( 'large_image', $options ) ) {
			if ( in_array( $options['large_image'], [ true, false, 0, 1, '0', '1', 'true', 'false' ], true ) ) {
				$settings['large_image'] = self::truthy( $options['large_image'] );
			} else {
				$notes[] = sprintf( 'products field "%s" has an invalid large_image setting; linked-product image zoom needs review.', $label );
				$needs_review = true;
			}
		}
		if ( array_key_exists( 'image_zoom', $options ) && in_array( $options['image_zoom'], [ true, false, 0, 1, '0', '1', 'true', 'false' ], true ) ) {
			$settings['image_zoom'] = self::truthy( $options['image_zoom'] );
		} elseif ( 'image' !== $subtype && array_key_exists( 'large_image', $options ) && in_array( $options['large_image'], [ true, false, 0, 1, '0', '1', 'true', 'false' ], true ) ) {
			// Retain the historical OPF card/gallery mapping for older exports.
			$settings['image_zoom'] = self::truthy( $options['large_image'] );
		}

		$pricing = $wapf_field['pricing'] ?? [];
		if ( is_array( $pricing ) && ! empty( $pricing['enabled'] ) ) {
			$notes[] = sprintf( 'products field "%s" has WAPF pricing enabled; linked products price themselves, so the field pricing was removed.', $label );
			$needs_review = true;
		}
		return $settings;
	}

	/**
	 * Map WAPF `checkboxes` min_choices/max_choices into OPF's flat keys.
	 *
	 * WAPF stores the keys both top-level (raw Tools payload) and inside the
	 * parsed field `options`; accept either shape. Bounds mirror the multi-swatch
	 * importer (int 1..10000, min <= max).
	 *
	 * @param array<string,mixed> $wapf_field Source field.
	 * @param string[]            $notes      Import notes.
	 * @param bool                $needs_review Review flag.
	 * @return array<string,int>
	 */
	private static function map_checkbox_limits( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options  = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label    = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
			$raw = array_key_exists( $key, $options ) ? $options[ $key ] : ( $wapf_field[ $key ] ?? null );
			if ( null === $raw || '' === $raw ) {
				continue;
			}
			if ( ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) && (int) $raw >= 1 && (int) $raw <= 10000 ) {
				$settings[ $key ] = (int) $raw;
			} else {
				$notes[] = sprintf( 'checkbox field "%s" has an invalid %s value; selection limit needs review.', $label, $key );
				$needs_review = true;
			}
		}
		if ( isset( $settings['min_choices'], $settings['max_choices'] ) && $settings['min_choices'] > $settings['max_choices'] ) {
			$notes[] = sprintf( 'checkbox field "%s" has min_choices greater than max_choices; selection limits need review.', $label );
			unset( $settings['min_choices'], $settings['max_choices'] );
			$needs_review = true;
		}
		return $settings;
	}

	/**
	 * Map WAPF Pro's `columns` checkbox presentation setting.
	 *
	 * WAPF Tools JSON flattens unknown per-field settings onto the field object;
	 * serialized WXR models place them in `options`. Extended 3.1.5 does not
	 * define this setting, so preserving it is migration support, not proof of
	 * runtime parity against that older package.
	 *
	 * @param array<string,mixed> $wapf_field Source field.
	 * @param string[]            $notes      Import notes.
	 * @param bool                $needs_review Review flag.
	 * @return array<string,int>
	 */
	private static function map_checkbox_columns( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$raw = array_key_exists( 'columns', $options ) ? $options['columns'] : ( $wapf_field['columns'] ?? null );
		if ( null === $raw || '' === $raw ) {
			return [];
		}
		if ( is_int( $raw ) && $raw >= 1 ) {
			return [ 'columns' => $raw ];
		}
		if ( is_string( $raw ) && ctype_digit( $raw ) ) {
			$digits = ltrim( $raw, '0' );
			$digits = '' === $digits ? '0' : $digits;
			$max = (string) PHP_INT_MAX;
			if ( strlen( $digits ) <= strlen( $max ) && ( strlen( $digits ) < strlen( $max ) || strcmp( $digits, $max ) <= 0 ) && (int) $digits > 0 ) {
				return [ 'columns' => (int) $digits ];
			}
		}
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$notes[] = sprintf( 'checkbox field "%s" has an invalid columns value; display layout needs review.', $label );
		$needs_review = true;
		return [];
	}

	/**
	 * Map WAPF `products` choices: manual selections reference products by ID,
	 * quantity subtypes carry per-choice default/min/max under `options`.
	 *
	 * @param array<string,mixed> $wapf_field       WAPF field.
	 * @param array<string,mixed> $products_settings Resolved subtype/selection.
	 * @return array<int,array>
	 */
	private static function map_product_choices( array $wapf_field, array $products_settings, array &$notes, bool &$needs_review ): array {
		$options = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$label   = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		if ( 'category' === ( $products_settings['product_selection'] ?? 'manual' ) ) {
			// Category-mode choices resolve live from product_query; stored
			// choices are ignored by WAPF as well.
			return [];
		}
		$qty_subtype = in_array( (string) ( $products_settings['subtype'] ?? '' ), [ 'card-qty', 'vcard-qty' ], true );
		$choices = [];
		$skipped = 0;
		foreach ( ( $options['choices'] ?? [] ) as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}
			$product_id = $choice['id'] ?? $choice['product_id'] ?? null;
			if ( ! ( is_int( $product_id ) || ( is_string( $product_id ) && ctype_digit( $product_id ) ) ) || (int) $product_id <= 0 ) {
				// A choice without a product reference renders nothing in WAPF
				// either; it is dropped, not silently kept.
				$skipped++;
				continue;
			}
			$pricing_type = (string) ( $choice['pricing_type'] ?? 'fixed' );
			if ( ! in_array( $pricing_type, FieldGroup::PRODUCTS_PRICING_TYPES, true ) ) {
				$notes[] = sprintf( 'product choice "%s" in field "%s" uses unsupported pricing "%s"; it now uses the product price.', (string) ( $choice['slug'] ?? '#' . $product_id ), $label, $pricing_type );
				$needs_review = true;
				$pricing_type = 'fixed';
			}
			$mapped = [
				'slug'        => (string) ( $choice['slug'] ?? '' ),
				'label'       => (string) ( $choice['label'] ?? '' ),
				'selected'    => ! empty( $choice['selected'] ),
				'disabled'    => ! empty( $choice['disabled'] ),
				'product_id'  => (int) $product_id,
				'pricing_type' => $pricing_type,
			];
			if ( $qty_subtype ) {
				$quantity_options = is_array( $choice['options'] ?? null ) ? $choice['options'] : [];
				$quantity = [];
				foreach ( [ 'default', 'min', 'max' ] as $key ) {
					if ( ! array_key_exists( $key, $quantity_options ) || '' === $quantity_options[ $key ] || null === $quantity_options[ $key ] ) {
						continue;
					}
					if ( is_numeric( $quantity_options[ $key ] ) ) {
						$quantity[ $key ] = (int) $quantity_options[ $key ];
					} else {
						$notes[] = sprintf( 'product choice "%s" in field "%s" has a non-numeric %s quantity; the default applies.', (string) ( $choice['slug'] ?? '#' . $product_id ), $label, $key );
						$needs_review = true;
					}
				}
				if ( $quantity ) {
					$mapped['quantity'] = [
						'default' => (int) ( $quantity['default'] ?? 0 ),
						'min'     => (int) ( $quantity['min'] ?? 0 ),
						'max'     => (int) ( $quantity['max'] ?? 999999 ),
					];
				}
			}
			$choices[] = $mapped;
		}
		if ( $skipped ) {
			$notes[] = sprintf( 'products field "%s" had %d choice(s) without a product reference; they were dropped and need review.', $label, $skipped );
			$needs_review = true;
		}
		if ( ! $choices && ! empty( $options['choices'] ) ) {
			$notes[] = sprintf( 'products field "%s" has no usable product choices after import; it needs review.', $label );
			$needs_review = true;
		}
		return $choices;
	}

	/**
	 * Map WAPF text/textarea minlength/maxlength/pattern into OPF's flat keys.
	 * WAPF stores these raw and only renders them as native constraints.
	 *
	 * @param array<string,mixed> $wapf_field Source field.
	 * @param string[]            $notes      Import notes.
	 * @param bool                $needs_review Review flag.
	 * @return array<string,int|string>
	 */
	private static function map_text_validation( array $wapf_field, array &$notes, bool &$needs_review ): array {
		$options  = is_array( $wapf_field['options'] ?? null ) ? $wapf_field['options'] : [];
		$settings = [];
		$label    = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		foreach ( [ 'minlength', 'maxlength' ] as $key ) {
			$raw = array_key_exists( $key, $options ) ? $options[ $key ] : ( $wapf_field[ $key ] ?? null );
			if ( null === $raw || '' === $raw ) {
				continue;
			}
			if ( ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) && (int) $raw >= 1 && (int) $raw <= 1000000 ) {
				$settings[ $key ] = (int) $raw;
			} else {
				$notes[] = sprintf( 'text field "%s" has an invalid %s value; text length limit needs review.', $label, $key );
				$needs_review = true;
			}
		}
		if ( 'text' === ( $wapf_field['type'] ?? '' ) ) {
			$raw_pattern = array_key_exists( 'pattern', $options ) ? $options['pattern'] : ( $wapf_field['pattern'] ?? null );
			if ( is_scalar( $raw_pattern ) ) {
				$pattern = trim( (string) $raw_pattern );
				if ( '' !== $pattern ) {
					if ( strlen( $pattern ) <= 2048 ) {
						$settings['pattern'] = $pattern;
					} else {
						$notes[] = sprintf( 'text field "%s" has an over-long pattern; text validation needs review.', $label );
						$needs_review = true;
					}
				}
			}
		}
		return $settings;
	}

	/**
	 * WAPF formula → OPF formula.
	 *
	 * WAPF normally divides formula results by product quantity, so formulas
	 * in the wild end with "* [qty]" to compensate. OPF is per-unit, so a
	 * trailing quantity multiplication is stripped unless WAPF's qty_based
	 * field option disables that division. Variables map 1:1
	 * ([price], [options_total]→[addons], [qty], [val]); source field IDs
	 * are remapped to the destination IDs before the expression is stored.
	 */
	public static function normalize_formula( string $formula, bool $qty_based = false ): ?string {
		$formula = trim( $formula );
		if ( '' === $formula ) {
			return null;
		}
		$formula = str_replace( '[options_total]', '[addons]', $formula );
		if ( ! $qty_based ) {
			$formula = self::strip_outer_qty_factor( $formula );
		}
		if ( '' === $formula ) {
			return null;
		}
		// Validate supported arithmetic after replacing known dynamic inputs with
		// numeric probes; the runtime still evaluates the saved expression safely.
		$probe = str_replace( [ '[price]', '[qty]', '[addons]', '[val]', '[x]' ], '1', $formula );
		$probe = preg_replace( '/\[(?:field|price)\.[a-zA-Z0-9_-]+\]/i', '1', $probe );
		$probe = preg_replace( '/\[var_[a-zA-Z0-9_-]+\]/', '1', $probe );
		$probe = preg_replace( '/\b(?:checked|files|sumQty|len)\s*\(\s*[a-zA-Z0-9_-]+\s*\)/i', '1', $probe );
		// lookuptable requires a named table argument; a numeric-first call
		// (lookuptable(1;2)) is not valid lookup usage and stays rejected.
		$probe = preg_replace( '/\blookuptable\s*\(\s*[a-zA-Z_][^()]*\)/i', '1', $probe );
		if ( ! self::is_math_formula_probe( $probe ) ) {
			return null;
		}
		return $formula;
	}

	/**
	 * Strip compensating outermost quantity factors ("* [qty]" / "[qty] *").
	 */
	private static function strip_outer_qty_factor( string $formula ): string {
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
		return $formula;
	}

	/**
	 * Did the legacy WAPF expression carry an outermost *[qty] factor? That
	 * factor compensated WAPF's per-unit normalization (fx divides by qty),
	 * so its presence means the author intended per-unit scaling.
	 */
	private static function formula_had_qty_factor( string $formula_raw ): bool {
		$renamed = str_replace( '[options_total]', '[addons]', trim( $formula_raw ) );
		return self::strip_outer_qty_factor( $renamed ) !== $renamed;
	}

	/**
	 * Allow documented numeric functions while keeping other calls and text out.
	 * Arguments may be nested arithmetic; separators belong to function calls.
	 * Runtime evaluation remains the sandboxed Calculator's responsibility.
	 */
	private static function is_math_formula_probe( string $probe ): bool {
		$frames = [];
		$length = strlen( $probe );
		for ( $offset = 0; $offset < $length; $offset++ ) {
			$char = $probe[ $offset ];
			if ( preg_match( '/[a-z_]/i', $char ) ) {
				if ( $offset > 0 && preg_match( '/[a-z0-9_.]/i', $probe[ $offset - 1 ] ) ) {
					return false;
				}
				// Installed WAPF Extended 3.1.5 extend/formulas.php and the
				// public formula-functions-reference define these numeric calls.
				if ( ! preg_match( '/^(?:min|max|round|abs|floor|ceil|sqrt|pow|sin|cos|tan|len)\s*\(/i', substr( $probe, $offset ), $match ) ) {
					return false;
				}
				$frames[] = true;
				$offset += strlen( $match[0] ) - 1;
			} elseif ( '(' === $char ) {
				$frames[] = false;
			} elseif ( ')' === $char ) {
				if ( ! $frames ) {
					return false;
				}
				array_pop( $frames );
			} elseif ( ';' === $char || ',' === $char ) {
				if ( ! $frames || true !== end( $frames ) ) {
					return false;
				}
			} elseif ( ! preg_match( '/[0-9+\-*\/.\s]/', $char ) ) {
				return false;
			}
		}
		return ! $frames;
	}

	/**
	 * WAPF value-based pricing (`nr`/`nrq` number, `char`/`charq` character
	 * count) → OPF `[x]`/`len([x])` formula pricing.
	 *
	 * WAPF: nr/char are flat per line (result/qty for a normal field); nrq/charq
	 * always scale with quantity. `[x]`/`[val]` is the submitted value and
	 * `len([x])` measures the submitted text (Calculator parity).
	 *
	 * @param string $ptype  WAPF pricing type.
	 * @param float  $amount Per-value amount.
	 * @return array<string,mixed>|null Pricing block, or null when the type is
	 *                                   not value-based / the expression failed.
	 */
	private static function map_value_pricing( string $ptype, float $amount ): ?array {
		if ( ! in_array( $ptype, [ 'nr', 'nrq', 'char', 'charq' ], true ) ) {
			return null;
		}
		$expression = in_array( $ptype, [ 'char', 'charq' ], true ) ? 'len([x])' : '[x]';
		$expression .= ' * ' . (string) (float) $amount;
		$formula = self::normalize_formula( $expression );
		if ( null === $formula ) {
			return null;
		}
		return [
			'type'        => 'formula',
			'amount'      => 0.0,
			'formula'     => $formula,
			'formula_raw' => $formula,
			'per_unit'    => in_array( $ptype, [ 'nrq', 'charq' ], true ),
		];
	}

	/**
	 * Field-level pricing (text-like fields).
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @return array<string,mixed>
	 */
	private static function map_field_pricing( array $wapf_field, array &$notes, bool &$needs_review, array $opf_ids_by_wapf_id, array $source_order_by_wapf_id, int $current_order, array $sumqty_safe_wapf_ids ): array {
		$label = (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' );
		$pricing = $wapf_field['pricing'] ?? [];
		if ( ! is_array( $pricing ) || empty( $pricing['enabled'] ) ) {
			return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
		}
		$type = (string) ( $pricing['type'] ?? 'none' );
		$amt  = (float) ( $pricing['amount'] ?? 0 );
		switch ( $type ) {
			case 'fixed':
				return [ 'type' => 'fixed', 'amount' => $amt, 'formula' => '', 'per_unit' => false ];
			case 'qt':
				// qt = per-unit fixed (amount*qty line total).
				return [ 'type' => 'fixed', 'amount' => $amt, 'formula' => '', 'per_unit' => true ];
			case 'percent':
				return [ 'type' => 'percent', 'amount' => $amt, 'formula' => '', 'per_unit' => true ];
			case 'p':
				// p = percent of the base once per line (flat).
				return [ 'type' => 'percent', 'amount' => $amt, 'formula' => '', 'per_unit' => false ];
			case 'fx':
				$formula_raw = self::map_formula_references( (string) ( $pricing['amount'] ?? '' ), $opf_ids_by_wapf_id, $notes, $needs_review, $label, $source_order_by_wapf_id, $current_order, $sumqty_safe_wapf_ids, 'field' );
				$formula = null === $formula_raw ? null : self::normalize_formula( $formula_raw, ! empty( $wapf_field['qty_based'] ) );
				if ( null !== $formula ) {
					return [ 'type' => 'formula', 'amount' => 0.0, 'formula' => $formula, 'formula_raw' => $formula_raw, 'per_unit' => self::formula_had_qty_factor( $formula_raw ) ];
				}
				$notes[] = sprintf( 'field "%s" formula could not be translated: %s', $label, (string) ( $pricing['amount'] ?? '' ) );
				$needs_review = true;
				return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
			case 'nr':
			case 'nrq':
			case 'char':
			case 'charq':
				$value_pricing = self::map_value_pricing( $type, $amt );
				if ( null !== $value_pricing ) {
					return $value_pricing;
				}
				$notes[] = sprintf( 'field "%s" %s pricing could not be translated; imported without pricing.', $label, $type );
				$needs_review = true;
				return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
		}
		$notes[] = sprintf( 'field "%s" uses pricing type "%s" which is not supported.', (string) ( $wapf_field['label'] ?? '?' ), $type );
		$needs_review = true;
		return [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ];
	}

	/** Remap field IDs in WAPF formula variables without changing unrelated text. */
	private static function map_formula_references( string $formula, array $opf_ids_by_wapf_id, array &$notes, bool &$needs_review, string $label, array $source_order_by_wapf_id, int $current_order, array $sumqty_safe_wapf_ids, string $kind ): ?string {
		$unmapped = [];
		$review_references = [];
		$formula = preg_replace_callback(
			'/\[(field|price)\.([a-zA-Z0-9_-]+)\]/i',
			static function ( array $match ) use ( $opf_ids_by_wapf_id, $source_order_by_wapf_id, $current_order, &$unmapped, &$review_references ): string {
				$source_id = $match[2];
				if ( ! isset( $opf_ids_by_wapf_id[ $source_id ] ) || ! is_string( $opf_ids_by_wapf_id[ $source_id ] ) ) {
					$unmapped[] = $source_id;
					return $match[0];
				}
				if ( 'price' === strtolower( $match[1] ) ) {
					if ( ! isset( $source_order_by_wapf_id[ $source_id ] ) || $source_order_by_wapf_id[ $source_id ] >= $current_order ) {
						$review_references[] = '[price.' . $opf_ids_by_wapf_id[ $source_id ] . ']';
					}
				}
				return '[' . strtolower( $match[1] ) . '.' . $opf_ids_by_wapf_id[ $source_id ] . ']';
			},
			$formula
		);
		$formula = preg_replace_callback(
			'/\b(checked|files|sumQty)\s*\(\s*([a-zA-Z0-9_-]+)\s*\)/i',
			static function ( array $match ) use ( $opf_ids_by_wapf_id, $sumqty_safe_wapf_ids, &$unmapped, &$review_references ): string {
				$source_id = $match[2];
				if ( ! isset( $opf_ids_by_wapf_id[ $source_id ] ) || ! is_string( $opf_ids_by_wapf_id[ $source_id ] ) ) {
					$unmapped[] = $source_id;
					return $match[0];
				}
				$function = strtolower( $match[1] );
				if ( 'files' === $function || ( 'sumqty' === $function && empty( $sumqty_safe_wapf_ids[ $source_id ] ) ) ) {
					$review_references[] = strtolower( $match[1] ) . '(' . $opf_ids_by_wapf_id[ $source_id ] . ')';
				}
				return $match[1] . '(' . $opf_ids_by_wapf_id[ $source_id ] . ')';
			},
			$formula
		);
		// lookuptable(table;dim;…): dimension args resolve field ids at runtime
		// (args <6 chars are literals); remap any arg matching a known source
		// id and leave the table name and literals untouched.
		$formula = preg_replace_callback(
			'/\b(lookuptable)\s*\(([^()]*)\)/i',
			static function ( array $match ) use ( $opf_ids_by_wapf_id, &$unmapped, &$review_references ): string {
				$parts = array_map( 'trim', explode( ';', $match[2] ) );
				foreach ( $parts as $index => $arg ) {
					if ( 0 === $index ) {
						continue; // args[0] is the table name.
					}
					if ( isset( $opf_ids_by_wapf_id[ $arg ] ) && is_string( $opf_ids_by_wapf_id[ $arg ] ) ) {
						$parts[ $index ] = $opf_ids_by_wapf_id[ $arg ];
					} elseif ( strlen( $arg ) >= 6 && preg_match( '/^[a-zA-Z0-9_-]+$/', $arg ) ) {
						$unmapped[] = $arg;
					}
				}
				$review_references[] = 'lookuptable(' . $match[2] . ')';
				return $match[1] . '(' . implode( ';', $parts ) . ')';
			},
			$formula
		);
		if ( $unmapped ) {
			$unmapped = array_values( array_unique( $unmapped ) );
			$notes[] = sprintf( '%s "%s" formula references unavailable or ambiguous WAPF field IDs (%s); pricing needs review.', $kind, $label, implode( ', ', $unmapped ) );
			$needs_review = true;
			return null;
		}
		if ( $review_references ) {
			$review_references = array_values( array_unique( $review_references ) );
			$notes[] = sprintf( '%s "%s" formula contains references that require runtime review (%s); pricing needs review.', $kind, $label, implode( ', ', $review_references ) );
			$needs_review = true;
		}
		return is_string( $formula ) ? $formula : null;
	}

	/**
	 * Field conditionals.
	 *
	 * @param array<string,mixed> $wapf_field WAPF field.
	 * @param string[]            $notes      Collector.
	 * @param array<string,bool>  $seen_ids   Known field ids (incl. later ones skipped below).
	 * @return array<int,array>
	 */
	private static function map_conditionals( array $wapf_field, array &$notes, array $opf_ids_by_wapf_id, bool &$needs_review, array $source_type_by_wapf_id = [] ): array {
		$out = [];
		$conditionals = $wapf_field['conditionals'] ?? [];
		if ( ! is_array( $conditionals ) ) {
			$notes[] = sprintf( 'field "%s" has malformed conditional data.', (string) ( $wapf_field['label'] ?? '?' ) );
			$needs_review = true;
			return $out;
		}
		foreach ( $conditionals as $conditional ) {
			if ( ! is_array( $conditional ) ) {
				$notes[] = sprintf( 'field "%s" has a malformed conditional block.', (string) ( $wapf_field['label'] ?? '?' ) );
				$needs_review = true;
				continue;
			}
			$rules = [];
			$source_rules = $conditional['rules'] ?? [];
			if ( ! is_array( $source_rules ) ) {
				$notes[] = sprintf( 'field "%s" has a malformed conditional rule list.', (string) ( $wapf_field['label'] ?? '?' ) );
				$needs_review = true;
				continue;
			}
			foreach ( $source_rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					$notes[] = sprintf( 'field "%s" has a malformed conditional rule.', (string) ( $wapf_field['label'] ?? '?' ) );
					$needs_review = true;
					continue;
				}
				$condition = (string) ( $rule['condition'] ?? '' );
				// WAPF also merges variation-scoped group rules into field
				// conditionals (`product_var`/`patts`). They carry `value` terms
				// instead of a field reference; map them onto OPF's
				// `VariationRules` subjects so imported groups keep the gate.
				$variation_subject = self::variation_conditional_subject( $condition );
				if ( null !== $variation_subject ) {
					$terms = [];
					foreach ( (array) ( $rule['value'] ?? [] ) as $value ) {
						if ( is_array( $value ) && isset( $value['id'] ) ) {
							$terms[] = (string) $value['id'];
						} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
							$terms[] = (string) $value;
						}
					}
					if ( ! $terms ) {
						$notes[]      = sprintf( 'field "%s" has a variation conditional without terms; it needs review.', (string) ( $wapf_field['label'] ?? $wapf_field['id'] ?? '?' ) );
						$needs_review = true;
						continue;
					}
					$rules[] = [
						'subject'  => $variation_subject,
						'operator' => isset( $condition[0] ) && '!' === $condition[0] ? 'not_in' : 'in',
						'terms'    => $terms,
					];
					continue;
				}
				$operator  = self::CONDITION_MAP[ $condition ] ?? null;
				$source_field_id = is_scalar( $rule['field'] ?? $rule['subject'] ?? null ) ? (string) ( $rule['field'] ?? $rule['subject'] ) : '';
				$subject = isset( $opf_ids_by_wapf_id[ $source_field_id ] ) && is_string( $opf_ids_by_wapf_id[ $source_field_id ] )
					? $opf_ids_by_wapf_id[ $source_field_id ]
					: '';
				if ( 'check' === $condition ) {
					$operator = 'is';
				} elseif ( '!check' === $condition ) {
					$operator = 'is_not';
				}
				if ( null === $operator || '' === $subject ) {
					$notes[] = '' === $subject
						? sprintf( 'conditional rule references unavailable or ambiguous field ID "%s".', $source_field_id )
						: sprintf( 'conditional rule with condition "%s" dropped.', $condition );
					$needs_review = true;
					continue;
				}
				$value = in_array( $condition, [ 'check', '!check' ], true ) ? '1' : ( $rule['value'] ?? '' );
				if ( is_array( $value ) ) {
					$value = implode( ', ', array_map( 'strval', $value ) );
				}
				$source_type = (string) ( $source_type_by_wapf_id[ $source_field_id ] ?? '' );
				if ( '' !== $source_type && in_array( $operator, [ 'is', 'is_not', 'contains', 'not_contains' ], true )
					&& ( 'products' === ( self::TYPE_MAP[ $source_type ] ?? '' ) ) ) {
					// WAPF "product" conditions compare against product IDs;
					// OPF submits choice slugs. Keep the rule but flag it.
					$notes[] = sprintf( 'conditional rule on products field "%s" compares a WAPF product reference; OPF evaluates choice slugs, so the rule needs review.', $subject );
					$needs_review = true;
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
			}
		}
		return $out;
	}

	/**
	 * Map a WAPF variation-scoped field condition name onto an OPF subject.
	 *
	 * WAPF stores/merges `product_var`/`!product_var` (variation IDs) and
	 * `patts`/`!patts` (`attr|value` pairs) as field conditionals; OPF models
	 * these as `product_var` and `var_att` subjects in `Evaluator::VARIATION_SUBJECTS`.
	 *
	 * @param string $condition WAPF condition name (optionally `!`-negated).
	 * @return string|null OPF subject, or null when not variation-scoped.
	 */
	private static function variation_conditional_subject( string $condition ): ?string {
		$base = ltrim( $condition, '!' );
		if ( 'product_var' === $base ) {
			return 'product_var';
		}
		if ( 'patts' === $base ) {
			return 'var_att';
		}
		return null;
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
			$product_rule = [
				'subject'  => 'product',
				'operator' => 'in',
				'terms'    => array_map( 'strval', (array) $overrides['attach_product_ids'] ),
			];
			$source_groups = $wapf['rule_groups'] ?? [];
			if ( ! $source_groups ) {
				return [ [ 'rules' => [ $product_rule ] ] ];
			}
			$attached_groups = [];
			foreach ( $source_groups as $source_group ) {
				$has_user_condition = false;
				$user_rules = [];
				foreach ( ( $source_group['rules'] ?? [] ) as $source_rule ) {
					if ( ! is_array( $source_rule ) ) {
						continue;
					}
					$condition = ltrim( (string) ( $source_rule['condition'] ?? '' ), '!' );
					if ( ! in_array( $condition, [ 'auth', 'role', 'lang' ], true ) ) {
						continue;
					}
					$has_user_condition = true;
					$mapped = self::map_user_placement_rule( $source_rule, $notes, $needs_review );
					if ( null !== $mapped ) {
						$user_rules[] = $mapped;
					}
				}
				// A source OR group with no user restriction makes the local group
				// available to every visitor; preserve that by returning host-only.
				if ( ! $has_user_condition ) {
					return [ [ 'rules' => [ $product_rule ] ] ];
				}
				$attached_groups[] = [ 'rules' => array_merge( [ $product_rule ], $user_rules ) ];
			}
			return $attached_groups ?: [ [ 'rules' => [ $product_rule ] ] ];
		}

		$out = [];
		foreach ( ( $wapf['rule_groups'] ?? [] ) as $rule_group ) {
			$rules = [];
			foreach ( ( $rule_group['rules'] ?? [] ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$condition = (string) ( $rule['condition'] ?? '' );
				$subject   = (string) ( $rule['subject'] ?? '' );
				$value     = $rule['value'] ?? null;
				$cond = ltrim( $condition, '!' );
				if ( in_array( $cond, [ 'auth', 'role', 'lang' ], true ) ) {
					$mapped = self::map_user_placement_rule( $rule, $notes, $needs_review );
					if ( null !== $mapped ) {
						$rules[] = $mapped;
					}
					continue;
				}
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
					// Variation/attribute/type targeting added by the RULE lane;
					// WAPF `patts` is the `attr|value` pair list OPF evaluates as
					// `var_att` (Evaluator::VARIATION_SUBJECTS).
					'product_var'  => 'product_var',
					'patts'        => 'var_att',
					'product_type' => 'product_type',
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
						} elseif ( is_scalar( $v ) ) {
							$terms[] = (string) $v;
						}
					}
				} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
					$terms[] = (string) $value;
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
	 * Convert one WAPF user-context group rule without losing its target.
	 *
	 * @param array<string,mixed> $rule WAPF placement rule.
	 * @param string[]            $notes Collector.
	 */
	private static function map_user_placement_rule( array $rule, array &$notes, bool &$needs_review ): ?array {
		$condition = (string) ( $rule['condition'] ?? '' );
		$negate = isset( $condition[0] ) && '!' === $condition[0];
		$cond = ltrim( $condition, '!' );
		$value = $rule['value'] ?? null;
		if ( 'auth' === $cond ) {
			if ( ! empty( $value ) ) {
				$notes[] = sprintf( 'login visibility rule "%s" unexpectedly has a value and needs review.', $condition );
				$needs_review = true;
				return null;
			}
			return [ 'subject' => 'user_auth', 'operator' => $negate ? 'not_in' : 'in', 'terms' => [ 'logged_in' ] ];
		}
		$terms = [];
		if ( is_array( $value ) ) {
			foreach ( $value as $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$terms[] = (string) $entry['id'];
				} elseif ( is_scalar( $entry ) ) {
					$terms[] = (string) $entry;
				}
			}
		} elseif ( is_scalar( $value ) && '' !== (string) $value ) {
			$terms[] = (string) $value;
		}
		if ( ! in_array( $cond, [ 'role', 'lang' ], true ) || 1 !== count( $terms ) ) {
			$notes[] = sprintf( 'placement condition "%s" must have exactly one selected value; rule dropped.', $condition );
			$needs_review = true;
			return null;
		}
		return [
			'subject'  => 'role' === $cond ? 'user_role' : 'user_language',
			'operator' => $negate ? 'not_in' : 'in',
			'terms'    => $terms,
		];
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

	/**
	 * Render a WAPF pricing amount as a safe decimal literal for formulas.
	 * Fixed decimals (no exponent) so the Calculator tokenizer always accepts it.
	 *
	 * @param mixed $amount Raw pricing_amount from the WAPF export.
	 */
	private static function pricing_amount_literal( $amount ): string {
		$literal = rtrim( rtrim( sprintf( '%.10F', (float) $amount ), '0' ), '.' );
		if ( '' === $literal || '-' === $literal ) {
			$literal .= '0';
		}
		return $literal;
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
