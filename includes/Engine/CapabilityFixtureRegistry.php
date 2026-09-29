<?php
/**
 * Declarative schema fixtures for OPF's WAPF replacement capability ledger.
 *
 * A fixture is intentionally data-only: supported_flows records intended
 * coverage only. It is not evidence that a storefront/cart/order flow ran.
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
	 * Return declared schema fixtures, keyed by stable ledger ID.
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
					'schema' => 1,
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
						[ 'id' => 'contact-email', 'label' => 'Contact email', 'type' => 'email', 'required' => true ],
					],
				],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [
						[
							'id' => 'contact-email', 'label' => 'Contact email', 'description' => '', 'type' => 'email', 'required' => true,
							'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [],
							'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [],
						],
					],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'contact-email' => 'ada@example.test' ], 'addon_per_unit' => 0.0 ],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-TOGGLE' => [
				'ledger_id' => 'WAPF-FIELD-TOGGLE',
				'title'     => 'Toggle field lifecycle',
				'field_group' => [
					'schema' => 1,
					'fields' => [
						[ 'id' => 'gift-wrap', 'label' => 'Gift wrap', 'type' => 'toggle', 'required' => false ],
					],
				],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [
						[
							'id' => 'gift-wrap', 'label' => 'Gift wrap', 'description' => '', 'type' => 'toggle', 'required' => false,
							'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [],
							'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [],
						],
					],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'gift-wrap' => '0' ], 'addon_per_unit' => 0.0 ],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-NUMBER' => [
				'ledger_id' => 'WAPF-FIELD-NUMBER',
				'title' => 'Constrained number field',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'min' => 1, 'max' => 9, 'step' => 2 ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'quantity', 'label' => 'Quantity', 'description' => '', 'type' => 'number', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'min' => 1.0, 'max' => 9.0, 'step' => 2.0 ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'quantity' => '5' ], 'addon_per_unit' => 0.0 ],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-DATE' => [
				'ledger_id' => 'WAPF-FIELD-DATE',
				'title' => 'Constrained date field',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'event-date', 'label' => 'Event date', 'type' => 'date', 'min_date' => '2026-01-01', 'max_date' => '2026-12-31' ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'event-date', 'label' => 'Event date', 'description' => '', 'type' => 'date', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'allow_past' => true, 'allow_future' => true, 'min_date' => '2026-01-01', 'max_date' => '2026-12-31' ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'event-date' => '2026-06-15' ], 'addon_per_unit' => 0.0 ],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-SWATCH-COLOUR' => [
				'ledger_id' => 'WAPF-FIELD-SWATCH-COLOUR',
				'title' => 'Colour swatch choices',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'finish', 'type' => 'swatch', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'color' => '#F00' ] ] ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'finish', 'label' => '', 'description' => '', 'type' => 'swatch', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'color' => '#f00' ] ], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [] ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'finish' => 'red' ], 'addon_per_unit' => 0.0 ],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-SWATCH-IMAGE' => [
				'ledger_id' => 'WAPF-FIELD-SWATCH-IMAGE',
				'title' => 'Image swatch choices',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'finish', 'type' => 'swatch', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'image' => 'https://example.test/red.png' ] ] ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'finish', 'label' => '', 'description' => '', 'type' => 'swatch', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'image' => 'https://example.test/red.png' ] ], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [] ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'finish' => 'red' ], 'addon_per_unit' => 0.0 ],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-CHECKBOX' => [
				'ledger_id' => 'WAPF-FIELD-CHECKBOX',
				'title' => 'Checkbox selection limits',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'min_selections' => 1, 'max_selections' => 2, 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ], [ 'slug' => 'b', 'label' => 'B' ], [ 'slug' => 'c', 'label' => 'C' ] ] ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'extras', 'label' => 'Extras', 'description' => '', 'type' => 'checkbox', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ] ], [ 'slug' => 'b', 'label' => 'B', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ] ], [ 'slug' => 'c', 'label' => 'C', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ] ] ], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'min_selections' => 1, 'max_selections' => 2 ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'extras' => [ 'a', 'b' ] ], 'addon_per_unit' => 0.0 ],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-FIELD-SWATCH-MULTI' => [
				'ledger_id' => 'WAPF-FIELD-SWATCH-MULTI',
				'title' => 'Multiple swatch selections',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'colors', 'label' => 'Colors', 'type' => 'swatch', 'multiple' => true, 'min_selections' => 1, 'max_selections' => 2, 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ], [ 'slug' => 'green', 'label' => 'Green' ] ] ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'colors', 'label' => 'Colors', 'description' => '', 'type' => 'swatch', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ] ], [ 'slug' => 'blue', 'label' => 'Blue', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ] ], [ 'slug' => 'green', 'label' => 'Green', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ] ] ], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'multiple' => true, 'min_selections' => 1, 'max_selections' => 2 ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'colors' => [ 'red', 'blue' ] ], 'addon_per_unit' => 0.0 ],
				'supported_flows' => [ 'storefront', 'submission', 'server_pricing' ],
			],
			'WAPF-FIELD-CONTENT-HTML' => [
				'ledger_id' => 'WAPF-FIELD-CONTENT-HTML',
				'title' => 'Sanitized HTML content',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'notice', 'type' => 'html', 'content' => '<strong>Ships soon</strong>' ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'notice', 'label' => '', 'description' => '', 'type' => 'html', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'content' => '<strong>Ships soon</strong>' ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [], 'addon_per_unit' => 0.0 ],
				'supported_flows' => [ 'storefront' ],
			],
			'WAPF-FIELD-SHORTCODE' => [
				'ledger_id' => 'WAPF-FIELD-SHORTCODE',
				'title' => 'Registered shortcode content',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'booking', 'type' => 'shortcode', 'content' => '[opf_calendar]' ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'booking', 'label' => '', 'description' => '', 'type' => 'shortcode', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'content' => '[opf_calendar]' ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [], 'addon_per_unit' => 0.0 ],
				'supported_flows' => [ 'storefront' ],
			],
			'WAPF-FIELD-CONTENT-IMAGE' => [
				'ledger_id' => 'WAPF-FIELD-CONTENT-IMAGE',
				'title' => 'Static content image',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'guide', 'type' => 'content_image', 'image_url' => '/uploads/guide.jpg', 'alt' => 'Product guide' ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'guide', 'label' => '', 'description' => '', 'type' => 'content_image', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'content' => '', 'image_url' => '/uploads/guide.jpg', 'alt' => 'Product guide' ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [], 'addon_per_unit' => 0.0 ],
				'supported_flows' => [ 'storefront' ],
			],
			'WAPF-FIELD-SECTION' => [
				'ledger_id' => 'WAPF-FIELD-SECTION',
				'title' => 'Section layout field',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'details', 'type' => 'section', 'heading' => 'Details', 'content' => 'Choose your options.', 'required' => true ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'details', 'label' => '', 'description' => '', 'type' => 'section', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'content' => 'Choose your options.', 'heading' => 'Details' ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [], 'addon_per_unit' => 0.0 ],
				'supported_flows' => [ 'storefront' ],
			],
			'WAPF-FIELD-CALCULATION' => [
				'ledger_id' => 'WAPF-FIELD-CALCULATION',
				'title' => 'Informational calculation field',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'area', 'label' => 'Area', 'type' => 'calculation', 'formula' => '[field.width] * [field.height]', 'result_text' => '{result} sq ft' ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'area', 'label' => 'Area', 'description' => '', 'type' => 'calculation', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'content' => '', 'formula' => '[field.width] * [field.height]', 'result_text' => '{result} sq ft', 'calculation_type' => 'informational' ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [], 'addon_per_unit' => 0.0 ],
				'supported_flows' => [ 'storefront' ],
			],
			'WAPF-PRICE-CHARACTERS' => [
				'ledger_id' => 'WAPF-PRICE-CHARACTERS',
				'title' => 'Character-count pricing',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'engraving', 'type' => 'text', 'pricing' => [ 'type' => 'characters', 'amount' => 2 ] ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'engraving', 'label' => '', 'description' => '', 'type' => 'text', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [], 'pricing' => [ 'type' => 'characters', 'amount' => 2.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [] ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'engraving' => 'ABC' ], 'addon_per_unit' => 6.0 ],
				'supported_flows' => self::FLOWS,
			],
			'WAPF-COMMERCE-WEIGHT' => [
				'ledger_id' => 'WAPF-COMMERCE-WEIGHT',
				'title' => 'Choice weight adjustment',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'material', 'type' => 'select', 'choices' => [ [ 'slug' => 'steel', 'label' => 'Steel', 'weight' => 0.5 ] ] ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'material', 'label' => '', 'description' => '', 'type' => 'select', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [ [ 'slug' => 'steel', 'label' => 'Steel', 'selected' => false, 'disabled' => false, 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'weight' => 0.5 ] ], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [] ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [ 'material' => 'steel' ], 'weight_delta' => 0.5 ],
				'supported_flows' => [ 'classic_cart', 'block_cart_checkout' ],
			],
			'WAPF-FIELD-PARAGRAPH' => [
				'ledger_id' => 'WAPF-FIELD-PARAGRAPH',
				'title' => 'Static paragraph content',
				'field_group' => [ 'schema' => 1, 'fields' => [ [ 'id' => 'delivery-message', 'type' => 'paragraph', 'content' => 'Orders ship in two days.', 'required' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ] ] ],
				'expected_normalization' => [
					'schema' => 1,
					'fields' => [ [ 'id' => 'delivery-message', 'label' => '', 'description' => '', 'type' => 'paragraph', 'required' => false, 'width' => 100, 'css_class' => '', 'placeholder' => '', 'choices' => [], 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '', 'formula_raw' => '', 'per_unit' => true ], 'conditionals' => [], 'content' => 'Orders ship in two days.' ] ],
					'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
				],
				'expected_result' => [ 'submitted_values' => [], 'addon_per_unit' => 0.0 ],
				'supported_flows' => [ 'storefront' ],
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
					'schema' => 1,
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
	 * Validate one schema fixture and return it unchanged. Flow declarations are
	 * intentionally not treated as runtime coverage by this validator.
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
