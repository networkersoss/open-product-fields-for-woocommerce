<?php
/**
 * WapfMapper unit tests — production-shaped WAPF payloads map to OPF.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\WapfMapper;
use PHPUnit\Framework\TestCase;

final class WapfMapperTest extends TestCase {

	/**
	 * Shaped after production group 607379 (swatch with fx formulas).
	 */
	private function swatch_group(): array {
		return [
			'id'     => 'p_607379',
			'type'   => 'wapf_product',
			'layout' => [ 'mark_required' => true, 'labels_position' => 'above' ],
			'fields' => [
				[
					'id'          => 'produration',
					'label'       => 'Duración',
					'description' => '',
					'type'        => 'text-swatch',
					'required'    => true,
					'conditionals'=> [],
					'clone'       => [ 'enabled' => false ],
					'options'     => [
						'choices' => [
							[ 'slug' => 'd30', 'label' => '30 días', 'selected' => true, 'disabled' => false, 'options' => [], 'pricing_type' => 'none', 'pricing_amount' => 0 ],
							[ 'slug' => 'd365', 'label' => '1 año', 'selected' => false, 'disabled' => false, 'options' => [], 'pricing_type' => 'fixed', 'pricing_amount' => 180 ],
							[ 'slug' => 'boost', 'label' => 'Boost', 'selected' => false, 'disabled' => false, 'options' => [], 'pricing_type' => 'fx', 'pricing_amount' => '(([price] + [options_total]) * 0.2) * [qty]' ],
							[ 'slug' => 'pct', 'label' => 'Percent', 'selected' => false, 'disabled' => false, 'options' => [], 'pricing_type' => 'percent', 'pricing_amount' => 15.5 ],
						],
					],
					'pricing'     => [ 'type' => 'fixed', 'amount' => 0, 'enabled' => false ],
				],
			],
			'rule_groups' => [
				[ 'rules' => [ [ 'value' => [ [ 'id' => '8768', 'text' => 'fast' ] ], 'condition' => 'p_tags', 'subject' => 'product_tag' ] ] ],
			],
		];
	}

	public function test_maps_swatch_types_and_pricing(): void {
		$mapped = WapfMapper::map( $this->swatch_group() );
		$field  = $mapped['group']['fields'][0];

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'swatch', $field['type'] );
		$this->assertSame( 'duracion', $field['id'] );
		$this->assertCount( 4, $field['choices'] );

		$this->assertSame( 'none', $field['choices'][0]['pricing']['type'] );
		$this->assertSame( 'fixed', $field['choices'][1]['pricing']['type'] );
		$this->assertSame( 180.0, $field['choices'][1]['pricing']['amount'] );
		// WAPF fixed = flat per line; qty-scaled fixed only for qt type.
		$this->assertFalse( $field['choices'][1]['pricing']['per_unit'] );

		// fx formula: qty compensation stripped, [options_total] → [addons].
		$this->assertSame( 'formula', $field['choices'][2]['pricing']['type'] );
		$this->assertSame( '(([price] + [addons]) * 0.2)', $field['choices'][2]['pricing']['formula'] );

		$this->assertSame( 'percent', $field['choices'][3]['pricing']['type'] );
		$this->assertSame( 15.5, $field['choices'][3]['pricing']['amount'] );
	}

	public function test_maps_email_and_toggle_fields(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true ],
					[ 'id' => 'updates', 'label' => 'Updates', 'type' => 'toggle', 'required' => false ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'email', 'toggle' ], array_column( $mapped['group']['fields'], 'type' ) );
		$this->assertTrue( $mapped['group']['fields'][0]['required'] );
	}

	public function test_maps_wapf_field_instructions_as_inline_text_when_tooltip_mode_is_absent(): void {
		$mapped = WapfMapper::map( [
			'fields' => [ [ 'id' => 'engraving', 'label' => 'Engraving', 'description' => 'Use up to 12 characters.', 'type' => 'text' ] ],
			'rule_groups' => [],
		] );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'Use up to 12 characters.', $field['description'] );
		$this->assertArrayNotHasKey( 'description_presentation', $field );
	}

	public function test_maps_wapf_group_tooltips_and_ordered_gallery_rules(): void {
		$mapped = WapfMapper::map( [
			'layout' => [
				'instructions_position' => 'tooltip',
				'enable_gallery_images' => true,
				'swap_type' => 'rules',
				'gallery_images' => [
					[ 'source' => 'upload', 'url' => 'https://shop.example/red.jpg', 'id' => '101', 'values' => [ [ 'field' => 'finish-id', 'value' => 'red' ] ] ],
					[ 'source' => 'upload', 'url' => 'https://shop.example/blue.jpg', 'id' => '102', 'values' => [ [ 'field' => 'finish-id', 'value' => 'blue' ] ] ],
				],
			],
			'fields' => [
				[ 'id' => 'finish-id', 'label' => 'Finish', 'description' => 'Choose a finish.', 'type' => 'radio', 'options' => [ 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ] ] ] ],
			],
			'rule_groups' => [],
		] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'tooltip', $mapped['group']['fields'][0]['description_presentation'] );
		$this->assertSame( [
			[ 'target_url' => 'https://shop.example/red.jpg', 'conditions' => [ [ 'field' => 'finish', 'value' => 'red' ] ] ],
			[ 'target_url' => 'https://shop.example/blue.jpg', 'conditions' => [ [ 'field' => 'finish', 'value' => 'blue' ] ] ],
		], $mapped['group']['image_rules'] );
	}

	public function test_maps_wapf_last_changed_gallery_mode_without_review_loss(): void {
		$mapped = WapfMapper::map( [
			'layout' => [
				'enable_gallery_images' => true,
				'swap_type' => 'last',
				'gallery_images' => [ [ 'source' => 'upload', 'url' => 'https://shop.example/red.jpg', 'id' => '101', 'values' => [ [ 'field' => 'finish-id', 'value' => 'red' ] ] ] ],
			],
			'fields' => [
				[ 'id' => 'finish-id', 'label' => 'Finish', 'description' => 'Choose a finish.', 'type' => 'radio', 'options' => [ 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ] ] ],
			],
			'rule_groups' => [],
		] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'last', $mapped['group']['image_rule_mode'] );
		$this->assertSame( [ [ 'target_url' => 'https://shop.example/red.jpg', 'conditions' => [ [ 'field' => 'finish', 'value' => 'red' ] ] ] ], $mapped['group']['image_rules'] );
	}

	public function test_maps_wapf_true_false_gallery_conditions_to_toggle_values(): void {
		$mapped = WapfMapper::map( [
			'layout' => [
				'enable_gallery_images' => true,
				'swap_type' => 'rules',
				'gallery_images' => [ [ 'source' => 'upload', 'url' => 'https://shop.example/gift.jpg', 'id' => '103', 'values' => [ [ 'field' => 'gift-id', 'value' => '1' ] ] ] ],
			],
			'fields' => [ [ 'id' => 'gift-id', 'label' => 'Gift wrap', 'type' => 'true-false' ] ],
			'rule_groups' => [],
		] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'toggle', $mapped['group']['fields'][0]['type'] );
		$this->assertSame( [ [ 'target_url' => 'https://shop.example/gift.jpg', 'conditions' => [ [ 'field' => 'gift-wrap', 'value' => '1' ] ] ] ], $mapped['group']['image_rules'] );
	}

	public function test_maps_wapf_colour_swatches(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[
						'id' => 'colour', 'label' => 'Colour', 'type' => 'color-swatch',
						'options' => [ 'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'color' => '#F00' ] ] ],
					],
				],
				'rule_groups' => [],
			]
		);

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'swatch', $mapped['group']['fields'][0]['type'] );
		$this->assertSame( '#f00', $mapped['group']['fields'][0]['choices'][0]['color'] );
	}

	public function test_maps_explicit_card_type_and_preserves_supported_presentation_with_review_flag(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[
						'id' => 'finish', 'label' => 'Finish', 'type' => 'card', 'required' => true,
						'options' => [
							'layout' => 'vertical',
							'choices' => [
								[ 'slug' => 'linen', 'label' => 'Linen', 'image' => '/uploads/linen.jpg', 'description' => 'Soft woven finish', 'pricing_type' => 'fixed', 'pricing_amount' => 4 ],
							],
						],
						'conditionals' => [],
					],
				],
				'rule_groups' => [],
			]
		);

		$field = $mapped['group']['fields'][0];
		$this->assertTrue( $mapped['needs_review'], 'Card serialization is not represented in the local WAPF export corpus.' );
		$this->assertSame( 'radio', $field['type'] );
		$this->assertSame( 'vertical', $field['card_layout'] );
		$this->assertSame( '/uploads/linen.jpg', $field['choices'][0]['image'] );
		$this->assertSame( 'Soft woven finish', $field['choices'][0]['description'] );
		$this->assertSame( 'fixed', $field['choices'][0]['pricing']['type'] );
		$this->assertStringContainsString( 'serialization', implode( ' ', $mapped['notes'] ) );
	}

	public function test_ambiguous_card_layout_is_not_guessed_and_requires_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'card', 'options' => [ 'layout' => 'tiles', 'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen' ] ] ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertArrayNotHasKey( 'card_layout', $mapped['group']['fields'][0] );
		$this->assertStringContainsString( 'card layout', implode( ' ', $mapped['notes'] ) );
	}

	public function test_maps_verified_number_mode_and_limits_but_flags_unverified_extended_step(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number', 'options' => [ 'minimum' => 0.5, 'maximum' => 25, 'number_type' => 'any', 'step' => 0.25 ] ],
				],
				'rule_groups' => [],
			]
		);

		$field = $mapped['group']['fields'][0];
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( 0.5, $field['min'] );
		$this->assertSame( 25.0, $field['max'] );
		$this->assertSame( 'decimal', $field['number_mode'] );
		$this->assertArrayNotHasKey( 'step', $field );
		$this->assertStringContainsString( 'Extended serialization is unverified', implode( ' ', $mapped['notes'] ) );
	}

	public function test_maps_wapf_integer_number_mode_and_flags_unknown_values(): void {
		$integer = WapfMapper::map( [ 'fields' => [ [ 'id' => 'count', 'type' => 'number', 'options' => [ 'number_type' => 'int' ] ] ] ] );
		$this->assertSame( 'integer', $integer['group']['fields'][0]['number_mode'] );
		$this->assertFalse( $integer['needs_review'] );

		$unknown = WapfMapper::map( [ 'fields' => [ [ 'id' => 'count', 'type' => 'number', 'options' => [ 'number_type' => 'whole' ] ] ] ] );
		$this->assertTrue( $unknown['needs_review'] );
		$this->assertStringContainsString( 'unrecognized number_type', implode( ' ', $unknown['notes'] ) );
	}

	public function test_missing_wapf_number_type_uses_documented_integer_default(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [ 'id' => 'count', 'type' => 'number', 'options' => [] ] ] ] );
		$this->assertSame( 'integer', $mapped['group']['fields'][0]['number_mode'] );
		$this->assertFalse( $mapped['needs_review'] );
	}

	public function test_unverified_field_weight_formula_import_is_flagged_for_review(): void {
		$result = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'weight', 'type' => 'number', 'label' => 'Weight', 'options' => [ 'weight' => '[field.weight] * 1' ] ],
			],
		] );
		$this->assertTrue( $result['needs_review'] );
		$this->assertStringContainsString( 'weight setting', implode( ' ', $result['notes'] ) );
	}

	public function test_invalid_number_constraints_require_review_without_throwing(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number', 'options' => [ 'min' => 10, 'max' => 1, 'step' => 0 ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertArrayNotHasKey( 'min', $mapped['group']['fields'][0] );
		$this->assertArrayNotHasKey( 'max', $mapped['group']['fields'][0] );
		$this->assertStringContainsString( 'invalid step', implode( ' ', $mapped['notes'] ) );
	}

	public function test_unrecognized_number_mode_requires_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'options' => [ 'minimum' => 1, 'number_type' => 'integer' ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( 1.0, $mapped['group']['fields'][0]['min'] );
		$this->assertStringContainsString( 'unrecognized number_type', implode( ' ', $mapped['notes'] ) );
	}

	public function test_explicit_multi_swatch_type_maps_array_cardinality_and_limits(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[
						'id' => 'colors', 'label' => 'Colors', 'type' => 'multi-color-swatch',
						'options' => [
							'min_selections' => 1,
							'max_selections' => 2,
							'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'color' => '#f00' ], [ 'slug' => 'blue', 'label' => 'Blue', 'color' => '#00f' ] ],
						],
					],
					[ 'id' => 'labels', 'label' => 'Labels', 'type' => 'multi-text-swatch', 'options' => [ 'choices' => [ [ 'slug' => 'one', 'label' => 'One' ], [ 'slug' => 'two', 'label' => 'Two' ] ] ] ],
					[ 'id' => 'pictures', 'label' => 'Pictures', 'type' => 'multi-image-swatch', 'options' => [ 'choices' => [ [ 'slug' => 'one', 'label' => 'One', 'image' => 'https://example.test/one.png' ] ] ] ],
				],
				'rule_groups' => [],
			]
		);

		$field = $mapped['group']['fields'][0];
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'swatch', $field['type'] );
		$this->assertTrue( $field['multiple'] );
		$this->assertSame( 1, $field['min_selections'] );
		$this->assertSame( 2, $field['max_selections'] );
		$this->assertSame( [ true, true ], array_column( array_slice( $mapped['group']['fields'], 1 ), 'multiple' ) );
	}

	public function test_unrecognized_active_swatch_cardinality_option_requires_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [ [ 'id' => 'colors', 'label' => 'Colors', 'type' => 'text-swatch', 'options' => [ 'multiple' => true, 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ] ] ] ],
				'rule_groups' => [],
			]
		);
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'selection cardinality', implode( ' ', $mapped['notes'] ) );
	}

	public function test_maps_wapf_surface_visibility_flags_to_native_opf_options(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [ [ 'id' => 'extra', 'label' => 'Extra', 'type' => 'text', 'options' => [ 'hide_cart' => true, 'hide_checkout' => '1', 'hide_order' => true ] ] ],
				'rule_groups' => [],
			]
		);
		$this->assertFalse( $mapped['needs_review'] );
		$field = $mapped['group']['fields'][0];
		$this->assertTrue( $field['hide_cart'] );
		$this->assertTrue( $field['hide_checkout'] );
		$this->assertTrue( $field['hide_order'] );
	}

	public function test_dropped_choice_pricing_requires_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[
						'id' => 'finish', 'label' => 'Finish', 'type' => 'select',
						'options' => [ 'choices' => [ [ 'slug' => 'gold', 'label' => 'Gold', 'pricing_type' => 'future-mode', 'pricing_amount' => 5 ] ] ],
					],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'future-mode', implode( ' ', $mapped['notes'] ) );
	}

	public function test_dropped_field_pricing_requires_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'gift', 'label' => 'Gift wrap', 'type' => 'text', 'pricing' => [ 'enabled' => true, 'type' => 'future-mode', 'amount' => 5 ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'future-mode', implode( ' ', $mapped['notes'] ) );
	}

	public function test_dropped_conditionals_require_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'gift', 'label' => 'Gift wrap', 'type' => 'text', 'conditionals' => [ [ 'rules' => [ [ 'subject' => 'gift', 'condition' => 'future-op', 'value' => 'yes' ] ] ] ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'future-op', implode( ' ', $mapped['notes'] ) );
	}

	public function test_unsupported_date_rules_require_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'delivery', 'label' => 'Delivery date', 'type' => 'date', 'options' => [ 'min_date' => '7d', 'disabled_weekdays' => [ 0, 6 ] ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'date', implode( ' ', $mapped['notes'] ) );
		$this->assertSame( '7d', $mapped['group']['fields'][0]['min_date'] );
	}

	public function test_false_valued_unmapped_date_option_requires_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'delivery', 'label' => 'Delivery date', 'type' => 'date', 'options' => [ 'unmapped_policy' => false ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'unmapped_policy', implode( ' ', $mapped['notes'] ) );
	}

	public function test_relative_date_bounds_import_without_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'delivery', 'label' => 'Delivery date', 'type' => 'date', 'options' => [ 'min_date' => '7d', 'max_date' => '2m' ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( '7d', $mapped['group']['fields'][0]['min_date'] );
		$this->assertSame( '2m', $mapped['group']['fields'][0]['max_date'] );
	}

	public function test_fixed_date_bounds_import_without_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'delivery', 'label' => 'Delivery date', 'type' => 'date', 'options' => [ 'min_date' => '2026-10-01', 'max_date' => '2026-12-31' ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( '2026-10-01', $mapped['group']['fields'][0]['min_date'] );
		$this->assertSame( '2026-12-31', $mapped['group']['fields'][0]['max_date'] );
	}

	public function test_malformed_placement_rules_require_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [ [ 'id' => 'name', 'label' => 'Name', 'type' => 'text' ] ],
				'rule_groups' => [ [ 'rules' => [ 'malformed rule' ] ] ],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'placement', implode( ' ', $mapped['notes'] ) );
	}

	public function test_malformed_fields_collection_requires_review_without_warning(): void {
		$mapped = WapfMapper::map( [ 'fields' => 'not-an-array', 'rule_groups' => [] ] );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( [], $mapped['group']['fields'] );
		$this->assertStringContainsString( 'fields collection', implode( ' ', $mapped['notes'] ) );
	}

	public function test_non_scalar_field_type_is_reviewed_without_warning(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [ [ 'label' => 'Malformed', 'type' => [ 'number' ] ] ],
				'rule_groups' => [],
			]
		);
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( 'text', $mapped['group']['fields'][0]['type'] );
		$this->assertStringContainsString( 'malformed type', implode( ' ', $mapped['notes'] ) );
	}

	public function test_non_scalar_placement_discriminator_is_reviewed_without_warning(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [ [ 'id' => 'name', 'label' => 'Name', 'type' => 'text' ] ],
				'rule_groups' => [ [ 'rules' => [ [ 'condition' => [ 'product' ], 'subject' => 'product', 'value' => '17' ] ] ] ],
			]
		);
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertEmpty( $mapped['group']['rule_groups'] );
		$this->assertStringContainsString( 'malformed condition', implode( ' ', $mapped['notes'] ) );
	}

	public function test_impossible_date_bound_is_not_imported(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'delivery', 'label' => 'Delivery date', 'type' => 'date', 'options' => [ 'min_date' => '2026-02-31' ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertArrayNotHasKey( 'min_date', $mapped['group']['fields'][0] );
	}

	public function test_reversed_date_bounds_require_review_without_throwing(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'delivery', 'label' => 'Delivery date', 'type' => 'date', 'options' => [ 'min_date' => '2026-12-31', 'max_date' => '2026-01-01' ] ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertArrayNotHasKey( 'min_date', $mapped['group']['fields'][0] );
		$this->assertArrayNotHasKey( 'max_date', $mapped['group']['fields'][0] );
	}

	public function test_maps_product_tag_placement(): void {
		$mapped = WapfMapper::map( $this->swatch_group() );
		$this->assertSame( 'product_tag', $mapped['group']['rule_groups'][0]['rules'][0]['subject'] );
		$this->assertSame( 'in', $mapped['group']['rule_groups'][0]['rules'][0]['operator'] );
		$this->assertSame( [ '8768' ], $mapped['group']['rule_groups'][0]['rules'][0]['terms'] );
	}

	public function test_attaches_local_groups_to_host_product(): void {
		$mapped = WapfMapper::map( $this->swatch_group(), [ 'attach_product_ids' => [ 199813 ] ] );
		$rules  = $mapped['group']['rule_groups'][0]['rules'];
		$this->assertSame( 'product', $rules[0]['subject'] );
		$this->assertSame( [ '199813' ], $rules[0]['terms'] );
	}

	public function test_empty_condition_flags_needs_review_not_match_all(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'f1', 'label' => 'Note', 'type' => 'textarea', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [
				[ 'rules' => [ [ 'value' => null, 'condition' => '', 'subject' => 'product' ] ] ],
			],
		];
		$mapped = WapfMapper::map( $wapf );

		// WAPF evaluated empty conditions as FALSE — the group was dead. The
		// mapper must flag it, not silently make it global.
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'empty condition', implode( ' ', $mapped['notes'] ) );
	}

	public function test_unsupported_types_are_dropped_and_flagged(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'f1', 'label' => 'Colour picker', 'type' => 'color', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [],
		];
		$mapped = WapfMapper::map( $wapf );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertEmpty( $mapped['group']['fields'] );
	}

	public function test_upload_types_map_to_reviewable_upload_fields(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'f1', 'label' => 'Artwork', 'type' => 'file', 'required' => true, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( 'upload', $mapped['group']['fields'][0]['type'] );
		$this->assertSame( 'none', $mapped['group']['fields'][0]['pricing']['type'] );
		$this->assertStringContainsString( 'upload constraints', implode( ' ', $mapped['notes'] ) );
	}

	public function test_formula_normalizer_stacks_qty_strip(): void {
		$this->assertSame( '[price] * 0.5', WapfMapper::normalize_formula( '[price] * 0.5 * [qty]' ) );
		$this->assertSame( '[price] * 0.5', WapfMapper::normalize_formula( '[qty] * [price] * 0.5' ) );
		$this->assertSame( '[qty] + 1', WapfMapper::normalize_formula( '[qty] + 1' ), 'interior qty references are kept' );
		$this->assertNull( WapfMapper::normalize_formula( '[eval] * 2' ) );
		$this->assertNull( WapfMapper::normalize_formula( '' ) );
		$this->assertSame( '[var_wrap_cost] * 2', WapfMapper::normalize_formula( '[var_wrap_cost] * 2' ) );
		$this->assertSame( 'acf_option(gold_price) + 1', WapfMapper::normalize_formula( 'acf_option(gold_price) + 1' ) );
	}

	public function test_acf_formula_import_is_preserved_and_requires_target_field_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [ [
				'id' => 'charge', 'label' => 'Gold charge', 'type' => 'text',
				'options' => [ 'choices' => [] ],
				'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => 'acf_option(gold_price) * [qty]' ],
			] ],
			'rule_groups' => [],
		] );

		$this->assertSame( 'acf_option(gold_price)', $mapped['group']['fields'][0]['pricing']['formula'] );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'verify the referenced numeric fields', implode( ' ', $mapped['notes'] ) );
	}

	public function test_formula_variable_import_is_preserved_but_flagged_for_missing_definitions(): void {
		$mapped = WapfMapper::map( [
			'fields' => [ [
				'id' => 'charge', 'label' => 'Charge', 'type' => 'text',
				'options' => [ 'choices' => [] ],
				'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => '[var_wrap_cost] * [qty]' ],
			] ],
			'rule_groups' => [],
		] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( '[var_wrap_cost]', $mapped['group']['fields'][0]['pricing']['formula'] );
		$this->assertStringContainsString( 'custom variables', implode( ' ', $mapped['notes'] ) );
	}

	public function test_formula_references_remap_legacy_ids_including_later_fields(): void {
		$wapf = [
			'fields' => [
				[
					'id' => 'legacy-price', 'label' => 'Price Rule', 'type' => 'text-swatch',
					'options' => [ 'choices' => [
						[ 'slug' => 'dependent', 'label' => 'Dependent', 'pricing_type' => 'fx', 'pricing_amount' => 'checked(legacy-choice) + files(legacy-upload) + sumQty(legacy-qty) + [field.legacy-later]' ],
					] ],
					'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => 'checked(legacy-choice) + [field.legacy-later]' ],
				],
				[ 'id' => 'legacy-choice', 'label' => 'Choice Set', 'type' => 'checkbox', 'options' => [ 'choices' => [] ] ],
				[ 'id' => 'legacy-upload', 'label' => 'Upload Set', 'type' => 'file', 'options' => [ 'choices' => [] ] ],
				[ 'id' => 'legacy-qty', 'label' => 'Quantity Set', 'type' => 'number', 'options' => [ 'choices' => [] ] ],
				[ 'id' => 'legacy-later', 'label' => 'Later Field', 'type' => 'number', 'options' => [ 'choices' => [] ] ],
			],
			'rule_groups' => [],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertSame( 'checked(choice-set) + files(upload-set) + sumQty(quantity-set) + [field.later-field]', $mapped['group']['fields'][0]['choices'][0]['pricing']['formula'] );
		$this->assertSame( 'checked(choice-set) + [field.later-field]', $mapped['group']['fields'][0]['pricing']['formula'] );
	}

	public function test_unresolved_formula_ids_are_review_required_and_price_refs_note_import_uncertainty(): void {
		$wapf = [
			'fields' => [
				[
					'id' => 'legacy-main', 'label' => 'Main', 'type' => 'text-swatch',
					'options' => [ 'choices' => [
						[ 'slug' => 'unknown', 'label' => 'Unknown', 'pricing_type' => 'fx', 'pricing_amount' => '[field.not-in-group] + 1' ],
						[ 'slug' => 'price-ref', 'label' => 'Price Ref', 'pricing_type' => 'fx', 'pricing_amount' => '[price.legacy-main] * 0.2' ],
					] ],
				],
			],
			'rule_groups' => [],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( 'none', $mapped['group']['fields'][0]['choices'][0]['pricing']['type'] );
		$this->assertSame( '[price.main] * 0.2', $mapped['group']['fields'][0]['choices'][1]['pricing']['formula'] );
		$this->assertStringContainsString( 'runtime support', implode( ' ', $mapped['notes'] ) );
		$this->assertStringContainsString( 'real WAPF export', implode( ' ', $mapped['notes'] ) );
	}

	public function test_lookup_formula_ids_remap_but_missing_wapf_table_data_requires_review(): void {
		$mapped = WapfMapper::map(
			[
				'fields' => [
					[ 'id' => 'wapf-width', 'label' => 'Width', 'type' => 'number', 'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => 'lookuptable(blinds; wapf-width; wapf-height)' ] ],
					[ 'id' => 'wapf-height', 'label' => 'Height', 'type' => 'number' ],
				],
				'rule_groups' => [],
			]
		);

		$this->assertSame( 'lookuptable(blinds; width; height)', $mapped['group']['fields'][0]['pricing']['formula'] );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'table data is not included', implode( ' ', $mapped['notes'] ) );
	}

	public function test_field_ids_are_stable_and_unique(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'a1', 'label' => 'Target Country', 'type' => 'text', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
				[ 'id' => 'a2', 'label' => 'Target Country', 'type' => 'text', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [],
		];
		$mapped = WapfMapper::map( $wapf );
		$this->assertSame( 'target-country', $mapped['group']['fields'][0]['id'] );
		$this->assertSame( 'target-country-2', $mapped['group']['fields'][1]['id'] );
	}

	public function test_conditionals_translate_legacy_ids_including_later_fields(): void {
		$field = static function ( string $id, string $label, array $conditionals = [] ): array {
			return [
				'id' => $id, 'label' => $label, 'type' => 'text', 'required' => false,
				'conditionals' => $conditionals, 'clone' => [ 'enabled' => false ],
				'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ],
			];
		};
		$wapf = [
			'fields' => [
				$field( 'legacy-note', 'Instructions', [
					[ 'rules' => [ [ 'subject' => 'legacy-speed', 'condition' => 'is', 'value' => 'fast' ] ] ],
				] ),
				$field( 'legacy-speed', 'Delivery Speed' ),
			],
			'rule_groups' => [],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertSame(
			'delivery-speed',
			$mapped['group']['fields'][0]['conditionals'][0]['rules'][0]['field']
		);
	}
}
