<?php
/**
 * Declarative fixtures for OPF's WAPF replacement capability ledger.
 *
 * A fixture is intentionally data-only. Later feature slices can execute the
 * declared request/result through browser and WooCommerce harnesses without
 * making the acceptance contract depend on a particular test implementation.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Engine;

defined( 'ABSPATH' ) || exit;

final class CapabilityFixtureRegistry {

	/**
	 * Flows named by the capability ledger's promotion rule.
	 */
	public const FLOWS = [
		'storefront',
		'submission',
		'server_pricing',
		'classic_cart',
		'block_cart_checkout',
		'order_storage',
		'order_email_meta',
		'order_again',
	];

	/**
	 * Return all implemented capability fixtures, keyed by stable ledger ID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$fixtures = [
			'WAPF-FIELD-TEXT' => [
				'ledger_id' => 'WAPF-FIELD-TEXT',
				'title'     => 'Text field lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'       => 'engraving',
							'label'    => 'Engraving',
							'type'     => 'text',
							'required' => true,
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'engraving',
							'label'        => 'Engraving',
							'description'  => '',
							'type'         => 'text',
							'required'     => true,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'engraving' => 'Ada' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-EMAIL' => [
				'ledger_id' => 'WAPF-FIELD-EMAIL',
				'title'     => 'Email field lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'       => 'contact-email',
							'label'    => 'Contact email',
							'type'     => 'email',
							'required' => true,
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'contact-email',
							'label'        => 'Contact email',
							'description'  => '',
							'type'         => 'email',
							'required'     => true,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'contact-email' => 'ada@example.test' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-TOGGLE' => [
				'ledger_id' => 'WAPF-FIELD-TOGGLE',
				'title'     => 'Toggle field lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'       => 'gift-wrap',
							'label'    => 'Gift wrap',
							'type'     => 'toggle',
							'required' => false,
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'gift-wrap',
							'label'        => 'Gift wrap',
							'description'  => '',
							'type'         => 'toggle',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'gift-wrap' => '0' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-SWATCH-TEXT' => [
				'ledger_id' => 'WAPF-FIELD-SWATCH-TEXT',
				'title'     => 'Priced text swatch lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'    => 'finish',
							'label' => 'Finish',
							'type'  => 'swatch',
							'choices' => [
								[
									'slug'    => 'gold',
									'label'   => 'Gold',
									'pricing' => [ 'type' => 'fixed', 'amount' => 3 ],
								],
							],
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'finish',
							'label'        => 'Finish',
							'description'  => '',
							'type'         => 'swatch',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [
								[
									'slug'     => 'gold',
									'label'    => 'Gold',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'fixed', 'amount' => 3.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => false ],
								],
							],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'swatch_style' => 'text',
							'multiple' => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'finish' => 'gold' ],
					'addon_per_unit'  => 3.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-DATE' => [
				'ledger_id' => 'WAPF-FIELD-DATE',
				'title'     => 'Date field lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'       => 'delivery-date',
							'label'    => 'Delivery date',
							'type'     => 'date',
							'required' => true,
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'delivery-date',
							'label'        => 'Delivery date',
							'description'  => '',
							'type'         => 'date',
							'required'     => true,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'allow_past'    => true,
							'allow_future'  => true,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'delivery-date' => '2026-06-15' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-SECTION' => [
				'ledger_id' => 'WAPF-FIELD-SECTION',
				'title'     => 'Section layout lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'    => 'details',
							'label' => 'Details',
							'type'  => 'section',
						],
						[
							'id'    => 'name',
							'label' => 'Name',
							'type'  => 'text',
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'details',
							'label'        => 'Details',
							'description'  => '',
							'type'         => 'section',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'heading'      => 'Details',
							'content'      => '',
						],
						[
							'id'           => 'name',
							'label'        => 'Name',
							'description'  => '',
							'type'         => 'text',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'name' => 'Ada' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-CONTENT-IMAGE' => [
				'ledger_id' => 'WAPF-FIELD-CONTENT-IMAGE',
				'title'     => 'Content image lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'        => 'banner',
							'label'     => 'Banner',
							'type'      => 'content_image',
							'image_url' => 'https://example.test/red.png',
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'banner',
							'label'        => 'Banner',
							'description'  => '',
							'type'         => 'content_image',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'image_url'    => 'https://example.test/red.png',
							'alt'          => '',
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-SWATCH-MULTI' => [
				'ledger_id' => 'WAPF-FIELD-SWATCH-MULTI',
				'title'     => 'Multi-select swatch lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'       => 'colors',
							'label'    => 'Colors',
							'type'     => 'swatch',
							'multiple' => true,
							'choices'  => [
								[ 'slug' => 'red', 'label' => 'Red' ],
								[ 'slug' => 'blue', 'label' => 'Blue' ],
							],
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'colors',
							'label'        => 'Colors',
							'description'  => '',
							'type'         => 'swatch',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [
								[
									'slug'     => 'red',
									'label'    => 'Red',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
								],
								[
									'slug'     => 'blue',
									'label'    => 'Blue',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
								],
							],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'swatch_style' => 'text',
							'multiple'     => true,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'colors' => [ 'red', 'blue' ] ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-SWATCH-COLOUR' => [
				'ledger_id' => 'WAPF-FIELD-SWATCH-COLOUR',
				'title'     => 'Colour swatch lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'           => 'finish',
							'label'        => 'Finish',
							'type'         => 'swatch',
							'swatch_style' => 'color',
							'choices'      => [
								[ 'slug' => 'red', 'label' => 'Red', 'color' => '#f00' ],
							],
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'finish',
							'label'        => 'Finish',
							'description'  => '',
							'type'         => 'swatch',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [
								[
									'slug'     => 'red',
									'label'    => 'Red',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
									'color'    => '#F00',
								],
							],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'swatch_style' => 'color',
							'color_layout' => 'circle',
							'color_label_pos' => 'tooltip',
							'color_size'   => 30,
							'multiple'     => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'finish' => 'red' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-SWATCH-IMAGE' => [
				'ledger_id' => 'WAPF-FIELD-SWATCH-IMAGE',
				'title'     => 'Image swatch lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'           => 'pattern',
							'label'        => 'Pattern',
							'type'         => 'swatch',
							'swatch_style' => 'image',
							'choices'      => [
								[ 'slug' => 'red', 'label' => 'Red', 'image' => 'https://example.test/red.png' ],
							],
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'pattern',
							'label'        => 'Pattern',
							'description'  => '',
							'type'         => 'swatch',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [
								[
									'slug'     => 'red',
									'label'    => 'Red',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
									'image'    => 'https://example.test/red.png',
								],
							],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'swatch_style' => 'image',
							'label_pos'    => 'out',
							'grid_layout'  => 'fixed',
							'item_width'   => 68,
							'items_per_row' => 3,
							'items_per_row_tablet' => 3,
							'items_per_row_mobile' => 3,
							'multiple'     => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'pattern' => 'red' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-CHECKBOX' => [
				'ledger_id' => 'WAPF-FIELD-CHECKBOX',
				'title'     => 'Bounded checkbox lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'          => 'extras',
							'label'       => 'Extras',
							'type'        => 'checkbox',
							'max_choices' => 2,
							'choices'     => [
								[ 'slug' => 'a', 'label' => 'A' ],
								[ 'slug' => 'b', 'label' => 'B' ],
								[ 'slug' => 'c', 'label' => 'C' ],
							],
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'extras',
							'label'        => 'Extras',
							'description'  => '',
							'type'         => 'checkbox',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [
								[
									'slug'     => 'a',
									'label'    => 'A',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
								],
								[
									'slug'     => 'b',
									'label'    => 'B',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
								],
								[
									'slug'     => 'c',
									'label'    => 'C',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
								],
							],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'max_choices'  => 2,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'extras' => [ 'a', 'b' ] ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-CONTENT-HTML' => [
				'ledger_id' => 'WAPF-FIELD-CONTENT-HTML',
				'title'     => 'HTML content lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'             => 'intro',
							'label'          => 'Intro',
							'type'           => 'paragraph',
							'content_format' => 'html',
							'content'        => '<b>Hi</b>',
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'intro',
							'label'        => 'Intro',
							'description'  => '',
							'type'         => 'paragraph',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'content'      => '<b>Hi</b>',
							'content_format' => 'html',
							'process_shortcodes' => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-CALCULATION' => [
				'ledger_id' => 'WAPF-FIELD-CALCULATION',
				'title'     => 'Informational calculation lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'      => 'total',
							'label'   => 'Total',
							'type'    => 'calc',
							'formula' => '[field.qty] * 2',
						],
						[
							'id'    => 'qty',
							'label' => 'Qty',
							'type'  => 'number',
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'total',
							'label'        => 'Total',
							'description'  => '',
							'type'         => 'calc',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'calc_type'    => 'default',
							'formula'      => '[field.qty] * 2',
							'result_format' => 'number',
							'result_text'  => '{result}',
						],
						[
							'id'           => 'qty',
							'label'        => 'Qty',
							'description'  => '',
							'type'         => 'number',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'qty' => '3' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-COMMERCE-WEIGHT' => [
				'ledger_id' => 'WAPF-COMMERCE-WEIGHT',
				'title'     => 'Choice weight lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'      => 'pack',
							'label'   => 'Packaging',
							'type'    => 'swatch',
							'choices' => [
								[ 'slug' => 'box', 'label' => 'Box', 'weight' => '0.5' ],
							],
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'pack',
							'label'        => 'Packaging',
							'description'  => '',
							'type'         => 'swatch',
							'required'     => false,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [
								[
									'slug'     => 'box',
									'label'    => 'Box',
									'selected' => false,
									'disabled' => false,
									'pricing'  => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
									'weight'   => '0.5',
								],
							],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
							'swatch_style' => 'text',
							'multiple'     => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'pack' => 'box' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-NUMBER' => [
				'ledger_id' => 'WAPF-FIELD-NUMBER',
				'title'     => 'Number field lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[
							'id'       => 'count',
							'label'    => 'Count',
							'type'     => 'number',
							'required' => true,
						],
					],
				],
				'expected_normalization' => [
					'schema' => FieldGroup::SCHEMA,
					'fields' => [
						[
							'id'           => 'count',
							'label'        => 'Count',
							'description'  => '',
							'type'         => 'number',
							'required'     => true,
							'width'        => 100,
							'css_class'    => '',
							'placeholder'  => '',
							'choices'      => [],
							'pricing'      => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ],
							'conditionals' => [],
							'description_presentation' => 'inline',
							'hide_cart'                => false,
							'hide_checkout'            => false,
							'hide_order'               => false,
						],
					],
					'rule_groups'     => [],
					'mark_required'   => true,
					'labels_position' => 'above',
				],
				'expected_result' => [
					'submitted_values' => [ 'count' => '2' ],
					'addon_per_unit'  => 0.0,
				],
				'supported_flows' => self::FLOWS,
			],
		];

		foreach ( $fixtures as $id => $fixture ) {
			self::validate( $fixture );
			if ( $id !== $fixture['ledger_id'] ) {
				throw new \InvalidArgumentException( 'Capability fixture key must match ledger_id.' );
			}
		}

		return $fixtures;
	}

	/**
	 * Validate one capability fixture and return it unchanged.
	 *
	 * @param array<string,mixed> $fixture Fixture declaration.
	 * @return array<string,mixed>
	 */
	public static function validate( array $fixture ): array {
		foreach ( [ 'ledger_id', 'title', 'field_group', 'expected_normalization', 'expected_result', 'supported_flows' ] as $key ) {
			if ( ! array_key_exists( $key, $fixture ) ) {
				throw new \InvalidArgumentException( 'Capability fixture requires ' . $key . '.' );
			}
		}

		if ( ! is_string( $fixture['ledger_id'] ) || ! preg_match( '/^WAPF-[A-Z0-9-]+$/', $fixture['ledger_id'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture ledger_id must be a WAPF ledger ID.' );
		}
		if ( ! is_string( $fixture['title'] ) || '' === trim( $fixture['title'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture title must be a non-empty string.' );
		}
		if ( ! is_array( $fixture['field_group'] ) || empty( $fixture['field_group']['fields'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture field_group must contain fields.' );
		}
		if ( ! is_array( $fixture['expected_normalization'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_normalization must be an array.' );
		}
		if ( FieldGroup::normalize( $fixture['field_group'] ) !== $fixture['expected_normalization'] ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_normalization must match FieldGroup::normalize().' );
		}
		if ( ! is_array( $fixture['expected_result'] ) || empty( $fixture['expected_result'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_result must be a non-empty array.' );
		}
		if ( ! isset( $fixture['expected_result']['submitted_values'] ) || ! is_array( $fixture['expected_result']['submitted_values'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_result must declare submitted_values.' );
		}
		if ( isset( $fixture['expected_result']['addon_per_unit'] ) && ! is_numeric( $fixture['expected_result']['addon_per_unit'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture expected_result addon_per_unit must be numeric.' );
		}
		if ( ! is_array( $fixture['supported_flows'] ) || empty( $fixture['supported_flows'] ) ) {
			throw new \InvalidArgumentException( 'Capability fixture supported_flows must be a non-empty array.' );
		}
		foreach ( $fixture['supported_flows'] as $flow ) {
			if ( ! is_string( $flow ) || ! in_array( $flow, self::FLOWS, true ) ) {
				throw new \InvalidArgumentException( 'Capability fixture supported_flows contains an unknown flow.' );
			}
		}
		if ( count( $fixture['supported_flows'] ) !== count( array_unique( $fixture['supported_flows'] ) ) ) {
			throw new \InvalidArgumentException( 'Capability fixture supported_flows must not contain duplicates.' );
		}

		return $fixture;
	}
}
