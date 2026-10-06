<?php
/**
 * Converts supported OPF groups to WAPF Tools JSON.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;

defined( 'ABSPATH' ) || exit;

final class WapfExporter {

	/**
	 * Field keys an export can preserve; type-specific additions sit below.
	 */
	private const FIELD_KEYS = [
		'id', 'label', 'description', 'type', 'required', 'width', 'css_class',
		'placeholder', 'choices', 'pricing', 'conditionals',
		'description_presentation', 'hide_cart', 'hide_checkout', 'hide_order',
		'content', 'content_format', 'process_shortcodes', 'image_url', 'image_id',
		'alt', 'heading',
		'swatch_style', 'multiple', 'min_choices', 'max_choices', 'color_layout',
		'color_size', 'color_label_pos', 'image_zoom', 'label_pos', 'grid_layout',
		'item_width', 'items_per_row', 'items_per_row_tablet', 'items_per_row_mobile',
		'allow_past', 'allow_future', 'min_date', 'max_date', 'disabled_weekdays',
		'disabled_dates', 'cutoff_time', 'disable_today',
		// Linked products (WAPF `products` type) and file upload fields.
		'subtype', 'product_selection', 'qty_method', 'product_query', 'display',
		'slot_1', 'slot_2', 'slot_3', 'incl_img', 'incl_desc', 'img_fit',
		'max_size', 'accepted_types',
		// WAPF Extended `calc` options.
		'calc_type', 'formula', 'result_format', 'result_text',
		'columns',
	];

	/**
	 * @param array<string,mixed> $field Normalized field.
	 * @return string[] Allowed keys for this field's type.
	 */
	private static function allowed_field_keys( array $field ): array {
		$type = $field['type'] ?? '';
		$extra = [];
		if ( 'toggle' === $type ) {
			$extra[] = 'message';
		}
		if ( in_array( $type, [ 'toggle', 'checkbox' ], true ) ) {
			// WAPF's is-switch presentation, serialized beside the field
			// settings. FieldGroup normalizes the key under this same type
			// rule, so a switch field round-trips while any other type still
			// fails the export closed instead of losing the key.
			$extra[] = 'switch_control';
		}
		if ( 'products' === $type && 'image' === ( $field['subtype'] ?? '' ) ) {
			$extra[] = 'large_image';
		}
		if ( in_array( $type, [ 'toggle', 'text', 'textarea', 'email', 'url', 'number' ], true ) ) {
			$extra[] = 'default';
		}
		if ( 'number' === $type ) {
			// Number constraints are serialized as WAPF `minimum`/`maximum`/
			// `number_type`; `step` and `number_mode` never reach the payload
			// under their OPF names.
			$extra[] = 'min';
			$extra[] = 'max';
			$extra[] = 'step';
			$extra[] = 'number_mode';
		}
		return array_merge( self::FIELD_KEYS, $extra );
	}

	/**
	 * Convert a normalized OPF group to WAPF's four-section Tools payload.
	 * Unsupported or lossy data fails closed.
	 *
	 * @param array<string,mixed> $group Normalized OPF group.
	 * @return array<string,mixed>
	 */
	public static function build_payload( array $group ): array {
		self::assert_keys( $group, [ 'schema', 'fields', 'rule_groups', 'mark_required', 'labels_position', 'layout', 'variables' ], 'group' );
		foreach ( ( $group['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) ) {
			self::assert_keys( $field, self::allowed_field_keys( $field ), 'field' );
			}
		}
		foreach ( ( $group['rule_groups'] ?? [] ) as $rule_group ) {
			if ( is_array( $rule_group ) ) {
				self::assert_keys( $rule_group, [ 'rules' ], 'placement group' );
				foreach ( ( $rule_group['rules'] ?? [] ) as $rule ) {
					if ( is_array( $rule ) ) {
						self::assert_keys( $rule, [ 'subject', 'operator', 'terms' ], 'placement rule' );
					}
				}
			}
		}
		$group = FieldGroup::normalize( $group );
		if ( FieldGroup::SCHEMA !== (int) ( $group['schema'] ?? 0 ) ) {
			throw new \InvalidArgumentException( 'WAPF export cannot preserve this OPF schema.' );
		}
		$fields = [];
		$section_depth = 0;
		foreach ( $group['fields'] as $field ) {
			if ( 'section' === $field['type'] ) {
				$section_depth++;
			} elseif ( 'section_end' === $field['type'] ) {
				if ( 0 === $section_depth ) {
					throw new \InvalidArgumentException( 'WAPF export cannot preserve a section-end marker without an open section.' );
				}
				$section_depth--;
			}
		}
		if ( $section_depth > 0 ) {
			throw new \InvalidArgumentException( 'WAPF export cannot preserve a section without a matching section-end marker.' );
		}
		$field_ids = array_column( $group['fields'], 'id' );
		$field_types = array_column( $group['fields'], 'type', 'id' );
		if ( count( array_unique( $field_ids ) ) !== count( $field_ids ) ) {
			throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve duplicate field IDs.' );
		}
		foreach ( $group['fields'] as $field ) {
			$fields[] = self::map_field( $field, $field_ids, $field_types );
		}
		$conditions = [];
		foreach ( $group['rule_groups'] as $rule_group ) {
			$rules = [];
			foreach ( $rule_group['rules'] as $rule ) {
				$rules[] = self::map_placement_rule( $rule );
			}
			if ( $rules ) {
				$conditions[] = [ 'rules' => $rules ];
			}
		}
		$layout = [
			'labels_position'       => $group['labels_position'],
			'instructions_position' => 'field',
			'mark_required'         => $group['mark_required'],
		];
		// WAPF group gallery-image rules ("Change product image") ride in the
		// same layout block WAPF Tools reads on import.
		if ( ! empty( $group['layout'] ) && is_array( $group['layout'] ) ) {
			$gallery_layout = $group['layout'];
			$layout['enable_gallery_images'] = ! empty( $gallery_layout['enable_gallery_images'] );
			$layout['swap_type']             = in_array( $gallery_layout['swap_type'] ?? 'rules', [ 'rules', 'last' ], true ) ? $gallery_layout['swap_type'] : 'rules';
			$layout['gallery_images']        = [];
			foreach ( (array) ( $gallery_layout['gallery_images'] ?? [] ) as $gallery_image ) {
				if ( ! is_array( $gallery_image ) ) {
					continue;
				}
				$images = [];
				foreach ( (array) ( $gallery_image['values'] ?? [] ) as $value ) {
					if ( is_array( $value ) && isset( $value['field'] ) ) {
						$images[] = [ 'field' => (string) $value['field'], 'value' => (string) ( $value['value'] ?? '*' ) ];
					}
				}
				$layout['gallery_images'][] = [
					'source' => in_array( $gallery_image['source'] ?? 'upload', [ 'upload', 'product' ], true ) ? $gallery_image['source'] : 'upload',
					'url'    => (string) ( $gallery_image['url'] ?? '' ),
					'id'     => (string) ( $gallery_image['id'] ?? '' ),
					'values' => $images,
				];
			}
		}
		$variables = [];
		foreach ( (array) ( $group['variables'] ?? [] ) as $variable ) {
			$variables[] = self::map_variable( $variable, $field_ids );
		}
		return [
			'fields'    => $fields,
			'conditions' => $conditions,
			'layout'    => $layout,
			'variables' => $variables,
		];
	}

	/**
	 * Export one OPF variable in WAPF's {name,default,rules[]} shape.
	 * Variable bodies carry formula-like expressions, so field references are
	 * rebound the same way pricing formulas are; unresolvable data fails.
	 *
	 * @param mixed    $variable  Source variable.
	 * @param string[] $field_ids Exported field ids.
	 */
	private static function map_variable( $variable, array $field_ids ): array {
		if ( ! is_array( $variable ) ) {
			throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a malformed variable.' );
		}
		self::assert_keys( $variable, [ 'name', 'default', 'rules' ], 'variable' );
		$name = is_string( $variable['name'] ?? null ) ? $variable['name'] : '';
		if ( '' === $name || ! preg_match( '/^[a-zA-Z0-9_]+$/', $name ) ) {
			throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a variable without a plain name.' );
		}
		$default = is_string( $variable['default'] ?? null ) ? $variable['default'] : '';
		$rules = [];
		foreach ( (array) ( $variable['rules'] ?? [] ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a malformed variable rule.' );
			}
			self::assert_keys( $rule, [ 'type', 'field', 'condition', 'value', 'variable' ], 'variable rule' );
			$rule_type = (string) ( $rule['type'] ?? 'field' );
			if ( ! in_array( $rule_type, [ 'field', 'qty' ], true ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF Tools export cannot preserve variable "%s" rule type "%s".', $name, $rule_type ) );
			}
			$subject = (string) ( $rule['field'] ?? '' );
			if ( 'qty' !== $rule_type && ! in_array( $subject, $field_ids, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'WAPF Tools export cannot preserve variable "%s" rule reference "%s".', $name, $subject ) );
			}
			$body = is_string( $rule['variable'] ?? null ) ? $rule['variable'] : '';
			$rules[] = [
				'type'      => $rule_type,
				'field'     => $subject,
				'condition' => (string) ( $rule['condition'] ?? '' ),
				'value'     => is_scalar( $rule['value'] ?? null ) ? (string) $rule['value'] : '',
				'variable'  => '' === trim( $body ) ? '' : self::map_formula_references( $body, $field_ids ),
			];
		}
		return [
			'name'    => $name,
			'default' => '' === trim( $default ) ? '' : self::map_formula_references( $default, $field_ids ),
			'rules'   => $rules,
		];
	}

	/** @param array<string,mixed> $field */
	private static function map_field( array $field, array $field_ids, array $field_types ): array {
			self::assert_keys( $field, self::allowed_field_keys( $field ), 'field' );
		$type_map = [
			'text' => 'text', 'textarea' => 'textarea', 'email' => 'email', 'url' => 'url',
			'number' => 'number', 'date' => 'date', 'toggle' => 'true-false', 'select' => 'select', 'image_quantity' => 'image-swatch-qty',
			'radio' => 'radio', 'checkbox' => 'checkboxes', 'swatch' => 'text-swatch', 'paragraph' => 'content', 'content_image' => 'img', 'section' => 'section', 'section_end' => 'sectionend',
			'products' => 'products', 'upload' => 'file', 'calc' => 'calc',
		];
		$type = $field['type'];
		if ( 'paragraph' === $type && 'html' === ( $field['content_format'] ?? 'plain' ) ) {
			$type_map['paragraph'] = 'p';
		}
		if ( 'swatch' === $type ) {
			$multi = ! empty( $field['multiple'] );
			$style = $field['swatch_style'] ?? 'text';
			$type_map['swatch'] = [
				'text' => $multi ? 'multi-text-swatch' : 'text-swatch',
				'image' => $multi ? 'multi-image-swatch' : 'image-swatch',
				'color' => $multi ? 'multi-color-swatch' : 'color-swatch',
			][ $style ] ?? null;
		}
		if ( ! isset( $type_map[ $type ] ) || null === $type_map[ $type ] ) {
			throw new \InvalidArgumentException( sprintf( 'WAPF Tools export cannot preserve OPF field type "%s".', $type ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]*$/', $field['css_class'] ) ) {
			throw new \InvalidArgumentException( 'WAPF Tools import only preserves one sanitized CSS class per field.' );
		}
		foreach ( [ $field['label'], $field['description'] ] as $text ) {
			if ( false !== strpos( $text, '<' ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools import sanitizes field labels and descriptions; HTML is not exported.' );
			}
		}
		$out = [
			'id'          => $field['id'],
			'label'       => $field['label'],
			'description' => $field['description'],
			'type'        => $type_map[ $type ],
			'required'    => $field['required'],
			'width'       => $field['width'],
			'class'       => $field['css_class'],
			'conditionals' => self::map_conditionals( $field, $field_ids, $field_types ),
			'pricing'     => self::map_field_pricing( $field['pricing'], $field_ids ),
		];
		if ( '' !== $field['placeholder'] ) {
			if ( preg_match( '/[<>\r\n]/', $field['placeholder'] ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools import sanitizes placeholders; this placeholder cannot be preserved.' );
			}
			$out['placeholder'] = $field['placeholder'];
		}
		if ( 'date' === $type ) {
			$out = array_merge( $out, self::map_date_settings( $field, $field_ids ) );
		}
		if ( in_array( $type, [ 'text', 'textarea', 'email', 'url', 'number' ], true ) && isset( $field['default'] ) ) {
			$out['default'] = $field['default'];
		}
		if ( 'number' === $type ) {
			$out = array_merge( $out, self::map_number_settings( $field ) );
		}
		if ( 'toggle' === $type ) {
			if ( isset( $field['message'] ) ) {
				if ( preg_match( '/[<>\r\n]/', $field['message'] ) ) {
					throw new \InvalidArgumentException( 'WAPF Tools import sanitizes toggle messages; this message cannot be preserved.' );
				}
				$out['message'] = $field['message'];
			}
			if ( isset( $field['default'] ) ) {
				$out['default'] = '1' === $field['default'] ? 'checked' : 'unchecked';
			}
		}
		if ( in_array( $type, [ 'toggle', 'checkbox' ], true ) && ! empty( $field['switch_control'] ) ) {
			// WAPF Pro renders true/false and checkbox fields as switches when
			// this key is set; an unset switch is omitted, not exported false.
			$out['switch_control'] = true;
		}
		if ( 'paragraph' === $type ) {
			if ( 'p' === $out['type'] && empty( $field['process_shortcodes'] ) ) {
				throw new \InvalidArgumentException( 'WAPF paragraph export always processes shortcodes; disablement cannot be preserved.' );
			}
			if ( 'content' === $out['type'] && preg_match( '/<\/?[a-z][^>]*>/i', $field['content'] ) ) {
				throw new \InvalidArgumentException( 'WAPF Free sanitizes paragraph content; HTML cannot be exported without loss.' );
			}
			$out['p_content'] = $field['content'];
		}
		if ( 'content_image' === $type ) {
			if ( '' !== (string) ( $field['image_url'] ?? '' ) ) {
				$out['image'] = $field['image_url'];
			}
			if ( ! empty( $field['image_id'] ) ) {
				$out['attachment'] = (int) $field['image_id'];
			}
		}
		if ( 'calc' === $type ) {
			// WAPF calc carries its formula in options, not in the pricing block.
			$out['pricing'] = [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ];
			$formula = str_replace( '[addons]', '[options_total]', (string) ( $field['formula'] ?? '' ) );
			$out['calc_type']     = in_array( $field['calc_type'] ?? 'default', [ 'default', 'cost' ], true ) ? $field['calc_type'] : 'default';
			$out['formula']       = self::map_formula_references( $formula, $field_ids );
			$out['result_format'] = 'none' === ( $field['result_format'] ?? 'number' ) ? 'none' : '';
			$out['result_text']   = (string) ( $field['result_text'] ?? '{result}' );
		}
		if ( 'swatch' === $type && in_array( $out['type'], [ 'image-swatch', 'multi-image-swatch' ], true ) ) {
			$out['large_image'] = ! empty( $field['image_zoom'] );
			$out['label_pos'] = $field['label_pos'];
			$out['grid_layout'] = $field['grid_layout'];
			$out['item_width'] = $field['item_width'];
			$out['items_per_row'] = $field['items_per_row'];
			$out['items_per_row_tablet'] = $field['items_per_row_tablet'];
			$out['items_per_row_mobile'] = $field['items_per_row_mobile'];
		}
		if ( 'swatch' === $type && 'color' === ( $field['swatch_style'] ?? '' ) ) {
			$out['layout'] = $field['color_layout'];
			$out['size'] = $field['color_size'];
			$out['label_pos'] = $field['color_label_pos'];
		}
		if ( 'swatch' === $type && ! empty( $field['multiple'] ) ) {
			if ( isset( $field['min_choices'] ) ) {
				$out['min_choices'] = $field['min_choices'];
			}
			if ( isset( $field['max_choices'] ) ) {
				$out['max_choices'] = $field['max_choices'];
			}
		}
		if ( 'image_quantity' === $type ) {
			// WAPF `large_image` on image-swatch-qty = OPF `image_zoom`.
			$out['large_image'] = ! empty( $field['image_zoom'] );
			foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
				if ( isset( $field[ $key ] ) ) {
					$out[ $key ] = $field[ $key ];
				}
			}
		}
		foreach ( [ 'hide_cart', 'hide_checkout', 'hide_order' ] as $hide_key ) {
			if ( ! empty( $field[ $hide_key ] ) ) {
				$out[ $hide_key ] = 'true';
			}
		}
		if ( 'upload' === $type ) {
			// WAPF file options are truthy-checked (`isset && $val`), so a
			// literal 'false' would mean "allow multiple" — emit bool-or-omit.
			if ( ! empty( $field['multiple'] ) ) {
				$out['multiple'] = true;
			}
			if ( isset( $field['max_size'] ) ) {
				$out['maxsize'] = $field['max_size'];
			}
			if ( ! empty( $field['accepted_types'] ) ) {
				$out['accept'] = implode( ',', array_map( 'strval', (array) $field['accepted_types'] ) );
			}
		}
		if ( 'products' === $type ) {
			$out['subtype'] = (string) ( $field['subtype'] ?? 'checkbox' );
			$out['product_selection'] = (string) ( $field['product_selection'] ?? 'manual' );
			if ( 'category' === $out['product_selection'] ) {
				$query = is_array( $field['product_query'] ?? null ) ? $field['product_query'] : [];
				$out['product_query'] = [
					'query_id'     => (int) ( $query['query_id'] ?? 0 ),
					'query_label'  => (string) ( $query['query_label'] ?? '' ),
					'limit'        => (int) ( $query['limit'] ?? 5 ),
					'sort'         => (string) ( $query['sort'] ?? 'date_desc' ),
					'pricing_type' => (string) ( $query['pricing_type'] ?? 'fixed' ),
				];
			} else {
				$out['choices'] = self::map_product_choices( (array) ( $field['choices'] ?? [] ), $field );
			}
			if ( ! in_array( $out['subtype'], [ 'card-qty', 'vcard-qty' ], true ) ) {
				$out['qty_method'] = (string) ( $field['qty_method'] ?? 'one' );
			} else {
				$out['display'] = (string) ( $field['display'] ?? 'default' );
				foreach ( [ 'min_choices', 'max_choices' ] as $key ) {
					if ( isset( $field[ $key ] ) ) {
						$out[ $key ] = (int) $field[ $key ];
					}
				}
			}
			if ( 'image' === $out['subtype'] ) {
				$out['label_pos'] = (string) ( $field['label_pos'] ?? 'default' );
				$out['item_width'] = (int) ( $field['item_width'] ?? 68 );
				$out['large_image'] = ! empty( $field['large_image'] );
			}
			if ( in_array( $out['subtype'], [ 'card', 'vcard', 'card-qty', 'vcard-qty' ], true ) ) {
				foreach ( [ 'items_per_row', 'items_per_row_tablet', 'items_per_row_mobile' ] as $key ) {
					if ( isset( $field[ $key ] ) ) {
						$out[ $key ] = (int) $field[ $key ];
					}
				}
				$out['incl_img'] = ! empty( $field['incl_img'] );
				$out['incl_desc'] = ! empty( $field['incl_desc'] );
				foreach ( [ 'slot_1', 'slot_2', 'slot_3' ] as $key ) {
					$out[ $key ] = (string) ( $field[ $key ] ?? 'none' );
				}
				if ( in_array( $out['subtype'], [ 'vcard', 'vcard-qty' ], true ) ) {
					$out['img_fit'] = (string) ( $field['img_fit'] ?? 'cover' );
				}
			}
		}
		if ( in_array( $type, [ 'select', 'radio', 'checkbox', 'swatch', 'image_quantity' ], true ) ) {
			$out['choices'] = [];
			foreach ( $field['choices'] as $choice ) {
				if ( 'swatch' === $type && ! in_array( $out['type'], [ 'image-swatch', 'multi-image-swatch' ], true ) && ( ! empty( $choice['image'] ) || ! empty( $choice['image_id'] ) ) ) {
					throw new \InvalidArgumentException( 'WAPF swatch export cannot preserve image media on a non-image swatch.' );
				}
				if ( false !== strpos( $choice['label'], '<' ) ) {
					throw new \InvalidArgumentException( 'WAPF Tools import sanitizes choice labels; HTML is not exported.' );
				}
				$pricing = $choice['pricing'];
				$pricing_type = self::map_choice_pricing( $pricing, $field_ids );
				$wapf_choice = [
					'slug' => $choice['slug'], 'label' => $choice['label'], 'selected' => $choice['selected'],
					'disabled' => $choice['disabled'],
					'pricing_type' => $pricing_type['type'], 'pricing_amount' => $pricing_type['amount'],
				];
				if ( in_array( $out['type'], [ 'image-swatch', 'multi-image-swatch' ], true ) ) {
					if ( ! empty( $choice['image'] ) ) {
						$wapf_choice['image'] = $choice['image'];
					}
					if ( ! empty( $choice['image_id'] ) ) {
						$wapf_choice['attachment'] = $choice['image_id'];
					}
				}
				if ( in_array( $out['type'], [ 'color-swatch', 'multi-color-swatch' ], true ) ) {
					if ( empty( $choice['color'] ) ) {
						throw new \InvalidArgumentException( 'WAPF color swatch export requires a color for every choice.' );
					}
					$wapf_choice['color'] = $choice['color'];
				}
				if ( 'image-swatch-qty' === $out['type'] ) {
					$wapf_choice['options'] = [
						'min' => $choice['quantity']['min'],
						'max' => $choice['quantity']['max'],
						'default' => $choice['quantity']['default'],
					];
					if ( ! empty( $choice['image'] ) ) {
						$wapf_choice['image'] = $choice['image'];
					}
					if ( ! empty( $choice['image_id'] ) ) {
						$wapf_choice['attachment'] = $choice['image_id'];
					}
				}
				$out['choices'][] = $wapf_choice;
			}
		}
		if ( 'checkbox' === $type && array_key_exists( 'columns', $field ) ) {
			$out['columns'] = (int) $field['columns'];
		}
		return $out;
	}

	/**
	 * Serialize OPF number-field constraints back into WAPF's stored option
	 * keys (the inverse of WapfMapper::map_number_settings):
	 *
	 *  - `min`/`max` → `minimum`/`maximum` (WAPF stores both as floats);
	 *  - integer mode → `number_type` `int`;
	 *  - decimal mode without a positive step → `number_type` `any`;
	 *  - decimal mode with a positive step → `number_type` carrying that step,
	 *    because WAPF 3.1.5 never serializes a `step` key: its renderer writes
	 *    `number_type` verbatim into the `step` attribute (class-html.php) and
	 *    only `int`/`any` appear in its admin (class-config.php);
	 *  - `display` (`default`/`plus_min`) exports the per-field WAPF stepper
	 *    choice verbatim so a migrated field keeps its control.
	 *
	 * WAPF also re-sanitizes the imported `default` inside `number_type`'s
	 * value space (class-field-groups.php: `int` unless the type is exactly
	 * `any`), and its integer fields always increment by 1 because no `step`
	 * attribute is emitted for `number_type` `int`. A fractional default
	 * beside a custom step, or a non-unit integer-mode step, therefore lands
	 * in WAPF's value space rather than being rejected here.
	 *
	 * @param array<string,mixed> $field Normalized OPF number field.
	 * @return array<string,mixed>
	 */
	private static function map_number_settings( array $field ): array {
		$out = [];
		foreach ( [ 'min' => 'minimum', 'max' => 'maximum' ] as $opf_key => $wapf_key ) {
			if ( isset( $field[ $opf_key ] ) ) {
				$out[ $wapf_key ] = (float) $field[ $opf_key ];
			}
		}
		$step = isset( $field['step'] ) ? (float) $field['step'] : 0.0;
		if ( 'decimal' !== ( $field['number_mode'] ?? 'integer' ) ) {
			$out['number_type'] = 'int';
		} elseif ( $step > 0 ) {
			$out['number_type'] = (string) $step;
		} else {
			$out['number_type'] = 'any';
		}
		if ( isset( $field['display'] ) && in_array( $field['display'], [ 'default', 'plus_min' ], true ) ) {
			$out['display'] = $field['display'];
		}
		return $out;
	}

	/**
	 * Serialize OPF date-field settings back into WAPF's stored option keys
	 * (the inverse of WapfMapper::map_date_settings):
	 *
	 *  - `allow_past`/`allow_future` invert to `disable_past`/`disable_future`;
	 *  - `disable_today` exports verbatim;
	 *  - ISO `YYYY-MM-DD` bounds/blackouts convert to WAPF `mm-dd-yyyy`, while
	 *    relative periods such as `7d`/`1y 9m 3d` and field-relative bounds
	 *    such as `[field.start]+1d` pass through unchanged;
	 *  - `disabled_weekdays` becomes the CSV scalar WAPF stores (a lone Sunday
	 *    keeps WAPF's bare `'0'` special case);
	 *  - inclusive date rules keep WAPF's space-separated range and CSV list;
	 *  - `cutoff_time` maps to `disable_today_after`.
	 *
	 * @param array<string,mixed> $field     Normalized OPF date field.
	 * @param string[]            $field_ids Exported field ids (field-relative refs must resolve).
	 * @return array<string,mixed>
	 */
	private static function map_date_settings( array $field, array $field_ids = [] ): array {
		$out = [];
		foreach ( [ 'allow_past' => 'disable_past', 'allow_future' => 'disable_future' ] as $opf_key => $wapf_key ) {
			$out[ $wapf_key ] = empty( $field[ $opf_key ] );
		}
		if ( ! empty( $field['disable_today'] ) ) {
			$out['disable_today'] = true;
		}
		foreach ( [ 'min_date', 'max_date' ] as $key ) {
			if ( isset( $field[ $key ] ) && '' !== (string) $field[ $key ] ) {
				$out[ $key ] = self::date_bound_to_wapf( (string) $field[ $key ], $field_ids );
			}
		}
		if ( ! empty( $field['disabled_weekdays'] ) ) {
			$out['disabled_days'] = implode( ',', array_map( 'strval', array_values( (array) $field['disabled_weekdays'] ) ) );
		}
		if ( ! empty( $field['disabled_dates'] ) ) {
			$rules = [];
			foreach ( (array) $field['disabled_dates'] as $rule ) {
				$parts = [];
				foreach ( preg_split( '/\s+/', trim( (string) $rule ) ) as $part ) {
					if ( '' !== $part ) {
						$parts[] = self::date_to_wapf( $part );
					}
				}
				if ( $parts ) {
					$rules[] = implode( ' ', $parts );
				}
			}
			if ( $rules ) {
				$out['disabled_dates'] = implode( ',', $rules );
			}
		}
		if ( ! empty( $field['cutoff_time'] ) ) {
			$out['disable_today_after'] = (string) $field['cutoff_time'];
		}
		return $out;
	}

	/** Convert an OPF ISO date token to WAPF `mm-dd-yyyy`; periods pass through. */
	private static function date_to_wapf( string $value ): string {
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match ) ) {
			return $match[2] . '-' . $match[3] . '-' . $match[1];
		}
		return $value;
	}

	/** Serialize a min/max bound, remapping field-relative references onto exported ids. */
	private static function date_bound_to_wapf( string $value, array $field_ids ): string {
		if ( null === FieldValue::date_boundary_reference( $value ) ) {
			return self::date_to_wapf( $value );
		}
		return self::map_formula_references( $value, $field_ids );
	}

	/**
	 * Serialize OPF product choices into WAPF `products` choices. WAPF
	 * references the linked product in `id`; quantity subtypes carry
	 * per-choice default/min/max under `options` — the same nested shape the
	 * importer (`raw_json_to_field_group`) preserves verbatim.
	 *
	 * @param array<int,array<string,mixed>> $choices OPF product choices.
	 * @param array<string,mixed>            $field   OPF products field.
	 */
	private static function map_product_choices( array $choices, array $field ): array {
		$qty_subtype = in_array( (string) ( $field['subtype'] ?? '' ), [ 'card-qty', 'vcard-qty' ], true );
		$out = [];
		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a malformed product choice.' );
			}
			self::assert_keys( $choice, [ 'slug', 'label', 'selected', 'disabled', 'product_id', 'pricing_type', 'quantity', 'image', 'image_id', 'color', 'pricing' ], 'product choice' );
			$product_id = $choice['product_id'] ?? 0;
			if ( ! ( is_int( $product_id ) || ( is_string( $product_id ) && ctype_digit( $product_id ) ) ) || (int) $product_id <= 0 ) {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a product choice without a product reference.' );
			}
			// Image/color keys and OPF generic choice pricing have no meaning on
			// WAPF linked products; non-empty values would be dropped silently.
			if ( ! empty( $choice['image'] ) || ! empty( $choice['image_id'] ) || ! empty( $choice['color'] ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve image or color data on a product choice.' );
			}
			$generic_pricing = is_array( $choice['pricing'] ?? null ) ? $choice['pricing'] : [];
			if ( 'none' !== ( $generic_pricing['type'] ?? 'none' ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve addon pricing on a product choice.' );
			}
			if ( false !== strpos( (string) ( $choice['label'] ?? '' ), '<' ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools import sanitizes choice labels; HTML is not exported.' );
			}
			$wapf_choice = [
				'id'           => (int) $product_id,
				'slug'         => (string) ( $choice['slug'] ?? '' ),
				'label'        => (string) ( $choice['label'] ?? '' ),
				'selected'     => ! empty( $choice['selected'] ),
				'disabled'     => ! empty( $choice['disabled'] ),
				'pricing_type' => 'none' === ( $choice['pricing_type'] ?? 'fixed' ) ? 'none' : 'fixed',
				'options'      => [],
			];
			if ( $qty_subtype ) {
				$quantity = is_array( $choice['quantity'] ?? null ) ? $choice['quantity'] : [];
				$wapf_choice['options'] = [
					'min'     => (int) ( $quantity['min'] ?? 0 ),
					'max'     => (int) ( $quantity['max'] ?? 999999 ),
					'default' => (int) ( $quantity['default'] ?? 0 ),
				];
			}
			$out[] = $wapf_choice;
		}
		return $out;
	}

	/** @param array<string,mixed> $pricing @param string[] $field_ids */
	private static function map_field_pricing( array $pricing, array $field_ids ): array {
		if ( 'none' === $pricing['type'] ) {
			return [ 'enabled' => false, 'type' => 'fixed', 'amount' => 0 ];
		}
		$mapped = self::map_wapf_pricing_type( $pricing, $field_ids );
		return [ 'enabled' => true, 'type' => $mapped['type'], 'amount' => $mapped['amount'] ];
	}

	/** @param array<string,mixed> $pricing @param string[] $field_ids @return array{type:string,amount:mixed} */
	private static function map_choice_pricing( array $pricing, array $field_ids ): array {
		if ( 'none' === $pricing['type'] ) {
			return [ 'type' => 'none', 'amount' => 0.0 ];
		}
		return self::map_wapf_pricing_type( $pricing, $field_ids );
	}

	/**
	 * Express an OPF pricing block in WAPF quantity semantics:
	 *  - fixed flat      → 'fixed'; fixed per-unit → 'qt'.
	 *  - percent per-unit → 'percent'; percent flat → 'p'.
	 *  - formula flat    → 'fx' verbatim; formula per-unit → 'fx' wrapped in
	 *    "* [qty]" so WAPF's fx/qty normalization returns the same line total.
	 *
	 * @param array<string,mixed> $pricing
	 * @param string[]            $field_ids Exported field ids.
	 * @return array{type:string,amount:mixed}
	 */
	private static function map_wapf_pricing_type( array $pricing, array $field_ids ): array {
		$per_unit = ! empty( $pricing['per_unit'] );
		if ( 'fixed' === $pricing['type'] ) {
			return [ 'type' => $per_unit ? 'qt' : 'fixed', 'amount' => $pricing['amount'] ];
		}
		if ( 'percent' === $pricing['type'] ) {
			return [ 'type' => $per_unit ? 'percent' : 'p', 'amount' => $pricing['amount'] ];
		}
		if ( 'formula' === $pricing['type'] ) {
			$raw_formula = $pricing['formula_raw'] ?? '';
			if ( is_string( $raw_formula ) && '' !== trim( $raw_formula ) ) {
				// Preserve imported source, including sumQty and its quantity factor.
				return self::map_formula_pricing( $pricing, $field_ids );
			}
			$expr = trim( (string) ( $pricing['formula'] ?? '' ) );
			if ( '' === $expr ) {
				return [ 'type' => 'none', 'amount' => 0.0 ];
			}
			// WAPF formulas read [options_total], not [addons].
			$expr = str_replace( '[addons]', '[options_total]', $expr );
			$expr = self::map_formula_references( $expr, $field_ids );
			return [ 'type' => 'fx', 'amount' => $per_unit ? '(' . $expr . ') * [qty]' : $expr ];
		}
		throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve this pricing mode.' );
	}

	/** Export only an imported raw formula whose syntax and field IDs remain representable. */
	private static function map_formula_pricing( array $pricing, array $field_ids ): array {
		$formula = $pricing['formula_raw'] ?? '';
		if ( ! is_string( $formula ) || '' === trim( $formula ) || null === \OPF\Engine\WapfMapper::normalize_formula( $formula ) ) {
			throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve formula pricing without a supported source expression.' );
		}
		$formula = self::map_formula_references( $formula, $field_ids );
		return [ 'type' => 'fx', 'amount' => $formula ];
	}

	/**
	 * Rebind OPF formula field references to the exported WAPF field ids.
	 *
	 * Exported fields keep their OPF ids, so a reference only changes when its
	 * letter case differs from the stored id (OPF resolves ids case-insensitively;
	 * WAPF's import remapper matches payload ids literally). A reference to an id
	 * absent from the export cannot resolve after WAPF remaps field ids, so the
	 * export fails closed instead of shipping a dangling reference.
	 *
	 * @param string   $expr      OPF formula expression.
	 * @param string[] $field_ids Exported field ids.
	 */
	private static function map_formula_references( string $expr, array $field_ids ): string {
		$ids_by_lower = [];
		foreach ( $field_ids as $field_id ) {
			$ids_by_lower[ strtolower( (string) $field_id ) ] = (string) $field_id;
		}
		$unresolved = [];
		$expr = preg_replace_callback(
			'/\[(field|price)\.([a-zA-Z0-9_-]+)\]/i',
			static function ( array $match ) use ( $ids_by_lower, &$unresolved ): string {
				$canonical = $ids_by_lower[ strtolower( $match[2] ) ] ?? null;
				if ( null === $canonical ) {
					$unresolved[] = $match[2];
					return $match[0];
				}
				return '[' . strtolower( $match[1] ) . '.' . $canonical . ']';
			},
			$expr
		);
		if ( is_string( $expr ) ) {
			$expr = preg_replace_callback(
				'/\b(checked|files|sumQty)\s*\(\s*([a-zA-Z0-9_-]+)\s*\)/i',
				static function ( array $match ) use ( $ids_by_lower, &$unresolved ): string {
					$canonical = $ids_by_lower[ strtolower( $match[2] ) ] ?? null;
					if ( null === $canonical ) {
						$unresolved[] = $match[2];
						return $match[0];
					}
					return $match[1] . '(' . $canonical . ')';
				},
				$expr
			);
		}
		if ( is_string( $expr ) ) {
			// lookuptable(table;dim;…): dimension args ≥6 chars resolve as field
			// ids at runtime; a table name (arg 0) and short literals pass.
			$expr = preg_replace_callback(
				'/\b(lookuptable)\s*\(([^()]*)\)/i',
				static function ( array $match ) use ( $ids_by_lower, &$unresolved ): string {
					$parts = array_map( 'trim', explode( ';', $match[2] ) );
					foreach ( $parts as $index => $arg ) {
						if ( 0 === $index ) {
							continue;
						}
						$canonical = $ids_by_lower[ strtolower( $arg ) ] ?? null;
						if ( null !== $canonical ) {
							$parts[ $index ] = $canonical;
						} elseif ( strlen( $arg ) >= 6 && preg_match( '/^[a-zA-Z0-9_-]+$/', $arg ) ) {
							$unresolved[] = $arg;
						}
					}
					return $match[1] . '(' . implode( ';', $parts ) . ')';
				},
				$expr
			);
		}
		if ( ! is_string( $expr ) ) {
			throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve this formula.' );
		}
		if ( $unresolved ) {
			throw new \InvalidArgumentException( sprintf( 'WAPF Tools export cannot preserve formula references to unknown field IDs: %s.', implode( ', ', array_unique( $unresolved ) ) ) );
		}
		return $expr;
	}

	/** @param array<string,mixed> $field @return array<int,array<string,mixed>> */
	private static function map_conditionals( array $field, array $field_ids, array $field_types ): array {
		$out = [];
		foreach ( $field['conditionals'] as $conditional ) {
			if ( 'show' !== $conditional['action'] ) {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve hide conditionals.' );
			}
			$blocks = 'any' === $conditional['logic'] ? array_map( static function ( $rule ) {
				return [ $rule ];
			}, $conditional['rules'] ) : [ $conditional['rules'] ];
			foreach ( $blocks as $rules ) {
				$mapped = [];
				foreach ( $rules as $rule ) {
					if ( ! isset( $rule['field'] ) || '' === (string) $rule['field'] ) {
						// WAPF Tools cannot represent variation-scoped field
						// conditionals: its own export projection strips
						// product_var/patts field rules. Fail closed instead of
						// silently losing the gate.
						throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a variation-scoped field conditional.' );
					}
					if ( ! in_array( $rule['field'], $field_ids, true ) ) {
						throw new \InvalidArgumentException( sprintf( 'WAPF Tools export cannot preserve unresolved field reference "%s".', $rule['field'] ) );
					}
					$operator = [
						'is' => '==', 'is_not' => '!=', 'contains' => '==contains',
						'not_contains' => '!=contains', 'greater' => 'gt', 'less' => 'lt',
						'empty' => 'empty', 'not_empty' => '!empty',
					][ $rule['operator'] ] ?? null;
					$value = $rule['value'];
					if ( 'toggle' === ( $field_types[ $rule['field'] ] ?? '' ) && in_array( $rule['operator'], [ 'is', 'is_not' ], true ) ) {
						if ( ! in_array( $value, [ '0', '1' ], true ) ) {
							throw new \InvalidArgumentException( 'WAPF toggle conditionals only preserve values 1 and 0.' );
						}
						$checked = '1' === $value;
						$operator = ( 'is' === $rule['operator'] ) === $checked ? 'check' : '!check';
						$value = '';
					}
					if ( null === $operator ) {
						throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve conditional operator.' );
					}
					$mapped[] = [ 'field' => $rule['field'], 'condition' => $operator, 'value' => $value ];
				}
				$out[] = [ 'rules' => $mapped ];
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $rule */
	private static function map_placement_rule( array $rule ): array {
		if ( 'user_auth' === $rule['subject'] ) {
			if ( [ 'logged_in' ] === $rule['terms'] && in_array( $rule['operator'], [ 'in', 'not_in' ], true ) ) {
				$logged_in = 'in' === $rule['operator'];
			} elseif ( [] === $rule['terms'] && in_array( $rule['operator'], [ 'logged_in', 'logged_out' ], true ) ) {
				$logged_in = 'logged_in' === $rule['operator'];
			} else {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve this visitor access rule.' );
			}
			return [
				'subject' => 'user',
				'condition' => $logged_in ? 'auth' : '!auth',
				'value' => [],
			];
		}
		if ( in_array( $rule['subject'], [ 'user_role', 'user_language' ], true ) ) {
			if ( ! in_array( $rule['operator'], [ 'in', 'not_in' ], true ) || 1 !== count( $rule['terms'] ) ) {
				throw new \InvalidArgumentException( 'WAPF Tools export requires exactly one role or language per placement rule.' );
			}
			$is_role = 'user_role' === $rule['subject'];
			return [
				'subject' => $is_role ? 'user' : 'system',
				'condition' => ( 'not_in' === $rule['operator'] ? '!' : '' ) . ( $is_role ? 'role' : 'lang' ),
				'value' => [ [ 'id' => (string) $rule['terms'][0], 'text' => (string) $rule['terms'][0] ] ],
			];
		}
		// Variation/attribute/type targeting maps back onto WAPF's group
		// conditions. WAPF Tools stores the discriminator in `condition` and
		// keeps `product_var`/`patts`/`product_type` intact (the export
		// projection only renames product/product_cat), so these round-trip.
		if ( in_array( $rule['subject'], [ 'product_var', 'var_att', 'product_type' ], true ) ) {
			if ( ! in_array( $rule['operator'], [ 'in', 'not_in' ], true ) || ! $rule['terms'] ) {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a variation placement rule without terms.' );
			}
			$condition_map = [
				'product_var'  => 'product_var',
				'var_att'      => 'patts',
				'product_type' => 'product_type',
			];
			$subject_map_wapf = [
				'product_var'  => 'product_variation',
				'var_att'      => 'var_att',
				'product_type' => 'product_type',
			];
			return [
				'subject'   => $subject_map_wapf[ $rule['subject'] ],
				'condition' => ( 'not_in' === $rule['operator'] ? '!' : '' ) . $condition_map[ $rule['subject'] ],
				'value'     => array_map( static function ( $term ) {
					return [ 'id' => (string) $term, 'text' => (string) $term ];
				}, $rule['terms'] ),
			];
		}
		$subject_map = [
			'product'     => 'products',
			'category'    => 'product_cats',
			'product_cat' => 'product_cats',
			'tag'         => 'p_tags',
			'product_tag' => 'p_tags',
		];
		// WAPF group rules carry variation-scoped subjects separately:
		// `product_variation`/`product_var` (variation IDs) and
		// `var_att`/`patts` (`attribute|slug` pairs). Both are accepted verbatim
		// by WAPF's native importer, so preserve them instead of failing closed.
		if ( in_array( $rule['subject'], [ 'product_var', 'var_att' ], true ) ) {
			if ( ! in_array( $rule['operator'], [ 'in', 'not_in' ], true ) || ! $rule['terms'] ) {
				throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a variation placement rule without targets.' );
			}
			$is_variation = 'product_var' === $rule['subject'];
			$condition    = ( 'not_in' === $rule['operator'] ? '!' : '' ) . ( $is_variation ? 'product_var' : 'patts' );
			return [
				'subject'   => $is_variation ? 'product_variation' : 'var_att',
				'condition' => $condition,
				'value'     => array_map( static function ( $term ) {
					return [ 'id' => (string) $term, 'text' => (string) $term ];
				}, $rule['terms'] ),
			];
		}
		if ( ! isset( $subject_map[ $rule['subject'] ] ) || ! in_array( $rule['operator'], [ 'in', 'not_in' ], true ) || ! $rule['terms'] ) {
			throw new \InvalidArgumentException( 'WAPF Tools export cannot preserve a group placement rule.' );
		}
		$subject = $subject_map[ $rule['subject'] ];
		if ( 'not_in' === $rule['operator'] ) {
			$subject = '!' . $subject;
		}
		return [
			'subject' => 'product',
			'condition' => $subject,
			'value' => array_map( static function ( $id ) {
				return [ 'id' => (string) $id, 'text' => (string) $id ];
			}, $rule['terms'] ),
		];
	}

	/** @param array<string,mixed> $data @param string[] $allowed */
	private static function assert_keys( array $data, array $allowed, string $label ): void {
		$unknown = array_diff( array_keys( $data ), $allowed );
		if ( $unknown ) {
			throw new \InvalidArgumentException( sprintf( 'WAPF Tools export cannot preserve unknown %s data: %s.', $label, implode( ', ', $unknown ) ) );
		}
	}
}
