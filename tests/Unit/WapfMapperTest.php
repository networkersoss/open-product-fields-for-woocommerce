<?php
/**
 * WapfMapper unit tests — production-shaped WAPF payloads map to OPF.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\WapfMapper;
use PHPUnit\Framework\TestCase;

final class WapfMapperTest extends TestCase {
	public function test_email_import_preserves_native_type_required_and_placeholder(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [ 'id' => 'contact', 'type' => 'email', 'label' => 'Contact email', 'required' => true, 'options' => [ 'placeholder' => 'Email address' ] ] ] ] );
		$this->assertCount( 1, $mapped['group']['fields'] );
		$this->assertSame( 'email', $mapped['group']['fields'][0]['type'] );
		$this->assertTrue( $mapped['group']['fields'][0]['required'] );
		$this->assertSame( 'Email address', $mapped['group']['fields'][0]['placeholder'] );
		$this->assertFalse( $mapped['needs_review'] );
	}
	public function test_extended_checkboxes_import_preserves_choice_availability_and_flat_fees(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'checkboxes',
			'options' => [ 'choices' => [
				[ 'slug' => 'unavailable', 'label' => 'Unavailable', 'disabled' => true, 'selected' => true, 'pricing_type' => 'fixed', 'pricing_amount' => 99 ],
				[ 'slug' => 'available', 'label' => 'Available', 'disabled' => false, 'pricing_type' => 'fixed', 'pricing_amount' => 1 ],
			] ],
		] ] ] );
		$this->assertCount( 1, $mapped['group']['fields'] );
		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'checkbox', $field['type'] );
		$this->assertSame( [ true, false ], array_column( $field['choices'], 'disabled' ) );
		$this->assertFalse( $field['choices'][0]['selected'] );
		$this->assertSame( [ 99.0, 1.0 ], array_column( array_column( $field['choices'], 'pricing' ), 'amount' ) );
		$this->assertFalse( $field['choices'][1]['pricing']['per_unit'] );
		$this->assertFalse( $mapped['needs_review'] );
	}

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

	public function test_image_swatches_preserve_media_and_map_display_settings_for_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[
					'id' => 'finish',
					'label' => 'Finish',
					'type' => 'image-swatch',
					'options' => [
						'large_image' => true,
						'label_pos' => 'tooltip',
						'grid_layout' => 'flexible',
						'items_per_row' => 4,
						'items_per_row_tablet' => 2,
						'items_per_row_mobile' => 1,
						'choices' => [
							[ 'slug' => 'oak', 'label' => 'Oak', 'attachment' => 481, 'image' => 'https://example.test/oak.jpg', 'pricing_type' => 'none' ],
						],
					],
				],
			],
		] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'image swatch', implode( ' ', $mapped['notes'] ) );
		$this->assertSame( 'image', $mapped['group']['fields'][0]['swatch_style'] );
		$this->assertTrue( $mapped['group']['fields'][0]['image_zoom'] );
		$this->assertSame( 'tooltip', $mapped['group']['fields'][0]['label_pos'] );
		$this->assertSame( 'flexible', $mapped['group']['fields'][0]['grid_layout'] );
		$this->assertSame( [ 4, 2, 1 ], [ $mapped['group']['fields'][0]['items_per_row'], $mapped['group']['fields'][0]['items_per_row_tablet'], $mapped['group']['fields'][0]['items_per_row_mobile'] ] );
		$this->assertSame( 'oak', $mapped['group']['fields'][0]['choices'][0]['slug'] );
		$this->assertSame( 'https://example.test/oak.jpg', $mapped['group']['fields'][0]['choices'][0]['image'] );
		$this->assertSame( 481, $mapped['group']['fields'][0]['choices'][0]['image_id'] );
	}

	public function test_maps_wapf_extended_image_quantity_swatches_and_bounds_with_review_notes(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[
					'id' => 'prints',
					'label' => 'Prints',
					'type' => 'image-swatch-qty',
					'options' => [
						'large_image' => true,
						'label_pos' => 'tooltip',
						'items_per_row' => 4,
						'display' => 'plus_min',
						'max_choices' => 8,
						'choices' => [
							[
								'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg',
								'attachment' => 481, 'pricing_type' => 'fixed', 'pricing_amount' => 2.5,
								'options' => [ 'min' => 2, 'max' => 12, 'default' => 4, 'weight' => '0.25' ],
							],
							[
								'slug' => 'ash', 'label' => 'Ash', 'pricing_type' => 'none',
								'options' => [ 'max' => 5 ],
							],
						],
					],
				],
			],
		] );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'image_quantity', $field['type'] );
		$this->assertTrue( $field['image_zoom'], 'WAPF 3.1.5 large_image is nested in the image-swatch-qty options.' );
		$this->assertFalse( $field['multiple'] );
		$this->assertCount( 2, $field['choices'] );
		$this->assertSame( 8, $field['max_choices'] );
		$this->assertSame( [ 'default' => 4, 'min' => 2, 'max' => 12 ], $field['choices'][0]['quantity'] );
		$this->assertSame( [ 'default' => 0, 'min' => 0, 'max' => 5 ], $field['choices'][1]['quantity'] );
		$this->assertSame( 481, $field['choices'][0]['image_id'] );
		$this->assertSame( 'fixed', $field['choices'][0]['pricing']['type'] );
		// WAPF-COMMERCE-WEIGHT: choice options.weight is preserved verbatim for
		// the qty_selector multiply path (no longer a dropped-feature note).
		$this->assertSame( '0.25', $field['choices'][0]['weight'] );
		$this->assertTrue( $mapped['needs_review'], 'Image media and layout features not represented by OPF need review.' );
		$this->assertStringContainsString( 'image swatch', implode( ' ', $mapped['notes'] ) );
		$this->assertStringContainsString( 'label position', implode( ' ', $mapped['notes'] ) );
		$this->assertStringNotContainsString( 'does not preserve that zoom behavior', implode( ' ', $mapped['notes'] ) );
		$this->assertStringNotContainsString( 'weight', implode( ' ', $mapped['notes'] ) );
	}

	public function test_image_quantity_import_preserves_wapf_default_maximum(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'prints', 'label' => 'Prints', 'type' => 'image-swatch-qty',
			'options' => [ 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak' ] ] ],
		] ] ] );

		$quantity = $mapped['group']['fields'][0]['choices'][0]['quantity'];
		$this->assertSame( [ 'default' => 0, 'min' => 0, 'max' => 999999 ], $quantity );

		$empty_cap = WapfMapper::map( [ 'fields' => [ [
			'id' => 'prints', 'type' => 'image-swatch-qty',
			'options' => [ 'max_choices' => '', 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak' ] ] ],
		] ] ] );
		$this->assertSame( 999999, $empty_cap['group']['fields'][0]['choices'][0]['quantity']['max'] );

		$zero_cap = WapfMapper::map( [ 'fields' => [ [
			'id' => 'prints', 'type' => 'image-swatch-qty',
			'options' => [ 'max_choices' => 0, 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak' ] ] ],
		] ] ] );
		$this->assertSame( 0, $zero_cap['group']['fields'][0]['max_choices'] );
		$this->assertSame( 999999, $zero_cap['group']['fields'][0]['choices'][0]['quantity']['max'] );

		$clamped_default = WapfMapper::map( [ 'fields' => [ [
			'id' => 'prints', 'type' => 'image-swatch-qty',
			'options' => [ 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'options' => [ 'min' => 2 ] ] ] ],
		] ] ] );
		$this->assertSame( [ 'default' => 2, 'min' => 2, 'max' => 999999 ], $clamped_default['group']['fields'][0]['choices'][0]['quantity'] );
		$this->assertStringContainsString( 'bounds/default', implode( ' ', $clamped_default['notes'] ) );
	}

	public function test_image_quantity_import_maps_aggregate_minimum_and_maximum_without_clamping_choices(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'prints', 'type' => 'image-swatch-qty',
			'options' => [
				'min_choices' => '3', 'max_choices' => '8',
				'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'options' => [ 'max' => 12 ] ] ],
			],
		] ] ] );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( [ 3, 8 ], [ $field['min_choices'], $field['max_choices'] ] );
		$this->assertSame( 12, $field['choices'][0]['quantity']['max'] );
	}

	public function test_maps_multi_color_swatches_selection_limits_and_color_choices(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[
					'id' => 'palette',
					'label' => 'Palette',
					'type' => 'multi-color-swatch',
					'options' => [
						'min_choices' => 1,
						'max_choices' => 2,
						'layout' => 'rounded',
						'size' => 36,
						'label_pos' => 'default',
						'choices' => [
							[ 'slug' => 'navy', 'label' => 'Navy', 'color' => '#123456', 'pricing_type' => 'none' ],
							[ 'slug' => 'gold', 'label' => 'Gold', 'color' => '#D4AF37', 'pricing_type' => 'fixed', 'pricing_amount' => 4 ],
						],
					],
				],
			],
		] );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'swatch', $field['type'] );
		$this->assertSame( 'color', $field['swatch_style'] );
		$this->assertTrue( $field['multiple'] );
		$this->assertSame( 1, $field['min_choices'] );
		$this->assertSame( 2, $field['max_choices'] );
		$this->assertSame( 'rounded', $field['color_layout'] );
		$this->assertSame( 36, $field['color_size'] );
		$this->assertSame( '#123456', $field['choices'][0]['color'] );
		$this->assertSame( '#D4AF37', $field['choices'][1]['color'] );
		$this->assertFalse( $mapped['needs_review'] );
	}

	public function test_maps_the_other_multi_and_single_swatch_variants_without_loss(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'text', 'label' => 'Text', 'type' => 'multi-text-swatch', 'options' => [
				'min_choices' => 1, 'max_choices' => 2,
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing_type' => 'none' ] ],
			] ],
			[ 'id' => 'image', 'label' => 'Image', 'type' => 'multi-image-swatch', 'options' => [
				'min_choices' => 2, 'max_choices' => 3, 'label_pos' => 'out', 'grid_layout' => 'flexible',
				'items_per_row' => 4, 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => '/oak.jpg', 'pricing_type' => 'none' ] ],
			] ],
			[ 'id' => 'color', 'label' => 'Color', 'type' => 'color-swatch', 'options' => [
				'layout' => 'square', 'size' => 24, 'label_pos' => 'hide',
				'choices' => [ [ 'slug' => 'black', 'label' => 'Black', 'color' => '#000', 'pricing_type' => 'none' ] ],
			] ],
		] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'image swatch', implode( ' ', $mapped['notes'] ) );
		$this->assertSame( [ true, true, false ], array_column( $mapped['group']['fields'], 'multiple' ) );
		$this->assertSame( [ 'text', 'image', 'color' ], array_column( $mapped['group']['fields'], 'swatch_style' ) );
		$this->assertSame( [ 1, 2 ], [ $mapped['group']['fields'][0]['min_choices'], $mapped['group']['fields'][0]['max_choices'] ] );
		$this->assertSame( [ 2, 3 ], [ $mapped['group']['fields'][1]['min_choices'], $mapped['group']['fields'][1]['max_choices'] ] );
		$this->assertSame( 'flexible', $mapped['group']['fields'][1]['grid_layout'] );
		$this->assertSame( '#000', $mapped['group']['fields'][2]['choices'][0]['color'] );
	}

	public function test_maps_free_content_and_legacy_paragraph_fields_as_static_text(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'intro', 'label' => '', 'type' => 'content', 'options' => [ 'p_content' => "First line\nSecond line" ], 'pricing' => [ 'enabled' => false ] ],
				[ 'id' => 'legacy-intro', 'label' => '', 'type' => 'paragraph', 'p_content' => 'Legacy plain text', 'pricing' => [ 'enabled' => false ] ],
			],
		] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'paragraph', 'paragraph' ], array_column( $mapped['group']['fields'], 'type' ) );
		$this->assertSame( "First line\nSecond line", $mapped['group']['fields'][0]['content'] );
		$this->assertSame( 'Legacy plain text', $mapped['group']['fields'][1]['content'] );
	}

	public function test_maps_extended_paragraph_html_and_shortcodes_without_flattening(): void {
		$content = '<strong>Special offer</strong><br>[site_name]<img src="/badge.png" alt="Badge">';
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'offer', 'label' => 'Offer', 'type' => 'p',
			'options' => [ 'p_content' => $content ],
		] ] ] );
		$field = $mapped['group']['fields'][0];

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'paragraph', $field['type'] );
		$this->assertSame( $content, $field['content'] );
		$this->assertSame( 'html', $field['content_format'] );
		$this->assertTrue( $field['process_shortcodes'] );
	}

	public function test_maps_wapf_informative_image_url_and_attachment(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'img-1', 'label' => 'Fabric guide', 'type' => 'img',
			'options' => [ 'image' => 'https://example.test/fabric.jpg', 'attachment' => 481 ],
		] ] ] );
		$field = $mapped['group']['fields'][0];

		$this->assertSame( 'content_image', $field['type'] );
		$this->assertSame( 'https://example.test/fabric.jpg', $field['image_url'] );
		$this->assertSame( 481, $field['image_id'] );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'remap the attachment', implode( ' ', $mapped['notes'] ) );
	}

	public function test_maps_nested_wapf_sections_and_preserves_conditional_class_data(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'options' => [ 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak' ] ] ] ],
			[ 'id' => 'outer', 'label' => 'Outer', 'type' => 'section', 'class' => 'outer-style', 'conditionals' => [ [ 'rules' => [ [ 'field' => 'finish', 'condition' => '==', 'value' => 'oak' ] ] ] ] ],
			[ 'id' => 'inner', 'label' => 'Inner', 'type' => 'section' ],
			[ 'id' => 'end-inner', 'type' => 'sectionend' ],
			[ 'id' => 'end-outer', 'type' => 'sectionend' ],
		] ] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'select', 'section', 'section', 'section_end', 'section_end' ], array_column( $mapped['group']['fields'], 'type' ) );
		$this->assertSame( 'outer-style', $mapped['group']['fields'][1]['css_class'] );
		$this->assertSame( 'finish', $mapped['group']['fields'][1]['conditionals'][0]['rules'][0]['field'] );
	}

	public function test_flags_unclosed_wapf_sections_for_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [ 'id' => 'open', 'type' => 'section' ] ] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'no matching section-end marker', implode( ' ', $mapped['notes'] ) );
	}

	public function test_preserves_button_and_quantity_clone_modes_for_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'name', 'label' => 'Name', 'type' => 'text', 'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => 8 ] ],
			[ 'id' => 'attendees', 'label' => 'Attendees', 'type' => 'section', 'clone' => [ 'enabled' => true, 'type' => 'qty' ] ],
			[ 'id' => 'attendees-end', 'type' => 'sectionend' ],
			[ 'id' => 'unlimited', 'label' => 'Unlimited', 'type' => 'text', 'clone' => [ 'enabled' => true, 'type' => 'button' ] ],
		] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 8 ], $mapped['group']['fields'][0]['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'quantity' ], $mapped['group']['fields'][1]['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 10000 ], $mapped['group']['fields'][3]['repeat'] );
		$this->assertStringContainsString( 'quantity or section repeat behavior', implode( ' ', $mapped['notes'] ) );
	}

	public function test_maps_supported_quantity_repeated_fields_without_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'ticket-holder', 'label' => 'Ticket holder', 'type' => 'text',
			'clone' => [ 'enabled' => true, 'type' => 'qty', 'label' => 'Ticket {n}' ],
		] ] ] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Ticket {n}' ], $mapped['group']['fields'][0]['repeat'] );
	}

	public function test_flags_custom_clone_settings_while_preserving_button_maximum(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'name', 'label' => 'Name', 'type' => 'text',
			'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => 2000, 'add' => 'Add attendee', 'del' => 'Remove attendee', 'label' => 'Attendee {n}', 'vendor_option' => 'unknown' ],
		] ] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 2000, 'add' => 'Add attendee', 'del' => 'Remove attendee', 'label' => 'Attendee {n}' ], $mapped['group']['fields'][0]['repeat'] );
		$this->assertStringContainsString( 'unsupported WAPF clone settings (vendor_option)', implode( ' ', $mapped['notes'] ) );
	}

	public function test_maps_supported_button_repeat_labels_without_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'guest', 'label' => 'Guest', 'type' => 'text',
			'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => 4, 'add' => 'Add guest', 'del' => 'Remove guest', 'label' => 'Guest {n}' ],
		] ] ] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 4, 'add' => 'Add guest', 'del' => 'Remove guest', 'label' => 'Guest {n}' ], $mapped['group']['fields'][0]['repeat'] );
	}

	public function test_flags_wapf_button_maxima_outside_integer_range(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'name', 'label' => 'Name', 'type' => 'text',
			'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => '999999999999999999999999999999' ],
		] ] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertArrayNotHasKey( 'repeat', $mapped['group']['fields'][0] );
		$this->assertStringContainsString( 'invalid or unrepresentable repeater settings', implode( ' ', $mapped['notes'] ) );
	}

	public function test_flags_clone_settings_on_a_section_end_without_throwing(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [ 'id' => 'end', 'type' => 'sectionend', 'clone' => [ 'enabled' => true, 'type' => 'button' ] ] ] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( 'section_end', $mapped['group']['fields'][0]['type'] );
		$this->assertArrayNotHasKey( 'repeat', $mapped['group']['fields'][0] );
		$this->assertStringContainsString( 'section-end marker with clone settings', implode( ' ', $mapped['notes'] ) );
	}

	public function test_html_in_plain_wapf_content_is_preserved_as_text_and_flagged_for_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [ [ 'id' => 'intro', 'label' => '', 'type' => 'content', 'options' => [ 'p_content' => '<strong>Care</strong>' ] ] ],
		] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'contains HTML', $mapped['notes'][0] );
		$this->assertSame( 'Care', $mapped['group']['fields'][0]['content'] );
	}

	public function test_maps_product_tag_placement(): void {
		$mapped = WapfMapper::map( $this->swatch_group() );
		$this->assertSame( 'product_tag', $mapped['group']['rule_groups'][0]['rules'][0]['subject'] );
		$this->assertSame( 'in', $mapped['group']['rule_groups'][0]['rules'][0]['operator'] );
		$this->assertSame( [ '8768' ], $mapped['group']['rule_groups'][0]['rules'][0]['terms'] );
	}

	public function test_maps_variation_and_attribute_placement(): void {
		$wapf = [
			'fields' => [],
			'rule_groups' => [ [ 'rules' => [
				[ 'condition' => 'product_var', 'subject' => 'product_variation', 'value' => [ [ 'id' => '901', 'text' => 'Red' ] ] ],
				[ 'condition' => '!product_var', 'subject' => 'product_variation', 'value' => [ [ 'id' => '902', 'text' => 'Blue' ] ] ],
				[ 'condition' => 'patts', 'subject' => 'var_att', 'value' => [ [ 'id' => 'color|red', 'text' => 'Color - Red' ] ] ],
				[ 'condition' => '!patts', 'subject' => 'var_att', 'value' => [ [ 'id' => 'color|*', 'text' => 'Color - Any' ] ] ],
			] ] ],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame(
			[
				[ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '901' ] ],
				[ 'subject' => 'product_var', 'operator' => 'not_in', 'terms' => [ '902' ] ],
				[ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red' ] ],
				[ 'subject' => 'var_att', 'operator' => 'not_in', 'terms' => [ 'color|*' ] ],
			],
			$mapped['group']['rule_groups'][0]['rules']
		);
	}

	public function test_maps_user_and_language_placement(): void {
		$wapf = [
			'fields' => [],
			'rule_groups' => [ [ 'rules' => [
				[ 'condition' => 'auth', 'subject' => 'user', 'value' => [] ],
				[ 'condition' => 'role', 'subject' => 'user', 'value' => [ [ 'id' => 'wholesale', 'text' => 'Wholesale' ] ] ],
				[ 'condition' => '!role', 'subject' => 'user', 'value' => [ [ 'id' => 'suspended', 'text' => 'Suspended' ] ] ],
				[ 'condition' => 'lang', 'subject' => 'system', 'value' => [ [ 'id' => 'nl_NL', 'text' => 'Nederlands' ] ] ],
				[ 'condition' => '!lang', 'subject' => 'system', 'value' => [ [ 'id' => 'fr_FR', 'text' => 'Français' ] ] ],
			] ] ],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame(
			[
				[ 'subject' => 'user_auth', 'operator' => 'in', 'terms' => [ 'logged_in' ] ],
				[ 'subject' => 'user_role', 'operator' => 'in', 'terms' => [ 'wholesale' ] ],
				[ 'subject' => 'user_role', 'operator' => 'not_in', 'terms' => [ 'suspended' ] ],
				[ 'subject' => 'user_language', 'operator' => 'in', 'terms' => [ 'nl_NL' ] ],
				[ 'subject' => 'user_language', 'operator' => 'not_in', 'terms' => [ 'fr_FR' ] ],
			],
			$mapped['group']['rule_groups'][0]['rules']
		);
	}

	public function test_maps_variation_attribute_and_type_placement(): void {
		$wapf = [
			'fields' => [],
			'rule_groups' => [ [ 'rules' => [
				[ 'condition' => 'product_var', 'subject' => 'product_variation', 'value' => [ [ 'id' => '55', 'text' => '#55' ] ] ],
				[ 'condition' => '!product_var', 'subject' => 'product_variation', 'value' => [ [ 'id' => '56', 'text' => '#56' ] ] ],
				[ 'condition' => 'patts', 'subject' => 'var_att', 'value' => [ [ 'id' => 'color|red', 'text' => 'Red' ] ] ],
				[ 'condition' => '!patts', 'subject' => 'var_att', 'value' => [ [ 'id' => 'size|xl', 'text' => 'XL' ] ] ],
				[ 'condition' => 'product_type', 'subject' => 'product_type', 'value' => [ [ 'id' => 'variable', 'text' => 'Variable' ] ] ],
				[ 'condition' => '!product_type', 'subject' => 'product_type', 'value' => [ [ 'id' => 'grouped', 'text' => 'Grouped' ] ] ],
			] ] ],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertFalse( $mapped['needs_review'], implode( ' | ', $mapped['notes'] ) );
		$this->assertSame(
			[
				[ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55' ] ],
				[ 'subject' => 'product_var', 'operator' => 'not_in', 'terms' => [ '56' ] ],
				[ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red' ] ],
				[ 'subject' => 'var_att', 'operator' => 'not_in', 'terms' => [ 'size|xl' ] ],
				[ 'subject' => 'product_type', 'operator' => 'in', 'terms' => [ 'variable' ] ],
				[ 'subject' => 'product_type', 'operator' => 'not_in', 'terms' => [ 'grouped' ] ],
			],
			$mapped['group']['rule_groups'][0]['rules']
		);
	}

	public function test_maps_variation_scoped_field_conditionals(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'size', 'label' => 'Size', 'type' => 'text', 'conditionals' => [ [ 'rules' => [
					[ 'condition' => 'patts', 'value' => [ [ 'id' => 'fabric|cotton', 'text' => 'Cotton' ] ] ],
				] ] ], 'options' => [], 'pricing' => [ 'enabled' => false ] ],
				[ 'id' => 'note', 'label' => 'Note', 'type' => 'text', 'conditionals' => [ [ 'rules' => [
					[ 'condition' => '!product_var', 'value' => [ [ 'id' => '77', 'text' => '#77' ] ] ],
				] ] ], 'options' => [], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertFalse( $mapped['needs_review'], implode( ' | ', $mapped['notes'] ) );
		$this->assertSame(
			[ [ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'fabric|cotton' ] ] ],
			$mapped['group']['fields'][0]['conditionals'][0]['rules']
		);
		$this->assertSame(
			[ [ 'subject' => 'product_var', 'operator' => 'not_in', 'terms' => [ '77' ] ] ],
			$mapped['group']['fields'][1]['conditionals'][0]['rules']
		);
	}

	public function test_attaches_local_groups_to_host_product(): void {
		$mapped = WapfMapper::map( $this->swatch_group(), [ 'attach_product_ids' => [ 199813 ] ] );
		$rules  = $mapped['group']['rule_groups'][0]['rules'];
		$this->assertSame( 'product', $rules[0]['subject'] );
		$this->assertSame( [ '199813' ], $rules[0]['terms'] );
	}

	public function test_local_product_import_keeps_user_context_conditions(): void {
		$source = $this->swatch_group();
		$source['rule_groups'] = [ [ 'rules' => [
			[ 'condition' => 'auth', 'subject' => 'user', 'value' => [] ],
			[ 'condition' => 'role', 'subject' => 'user', 'value' => [ [ 'id' => 'wholesale', 'text' => 'Wholesale' ] ] ],
		] ] ];

		$mapped = WapfMapper::map( $source, [ 'attach_product_ids' => [ 199813 ] ] );
		$rules = $mapped['group']['rule_groups'][0]['rules'];
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( [ 'product', 'user_auth', 'user_role' ], array_column( $rules, 'subject' ) );
		$this->assertSame( [ 'in', 'in', 'in' ], array_column( $rules, 'operator' ) );
		$this->assertSame( [ 'wholesale' ], $rules[2]['terms'] );
	}

	public function test_maps_wapf_negative_contains_to_runtime_supported_operator(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'source', 'label' => 'Source', 'type' => 'text', 'conditionals' => [], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
				[ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'rules' => [ [ 'field' => 'source', 'condition' => '!=contains', 'value' => 'blocked' ] ] ] ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [],
		];

		$mapped = WapfMapper::map( $wapf );
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'not_contains', $mapped['group']['fields'][1]['conditionals'][0]['rules'][0]['operator'] );
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
				[ 'id' => 'f1', 'label' => 'Choice card', 'type' => 'card', 'required' => false, 'conditionals' => [], 'clone' => [ 'enabled' => false ], 'options' => [ 'choices' => [] ], 'pricing' => [ 'enabled' => false ] ],
			],
			'rule_groups' => [],
		];
		$mapped = WapfMapper::map( $wapf );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertEmpty( $mapped['group']['fields'] );
	}

	public function test_wapf_file_field_maps_to_upload(): void {
		$wapf = [
			'fields' => [
				[
					'id' => 'up1', 'label' => 'Artwork', 'type' => 'file', 'required' => true,
					'conditionals' => [], 'clone' => [ 'enabled' => false ],
					'options' => [ 'multiple' => true, 'accept' => 'jpg|jpeg|jpe,pdf', 'maxsize' => 4, 'choices' => [] ],
					'pricing' => [ 'enabled' => false ],
				],
			],
			'rule_groups' => [],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertFalse( $mapped['needs_review'], 'clean file field should not flag review: ' . implode( ' | ', $mapped['notes'] ) );
		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'upload', $field['type'] );
		$this->assertTrue( $field['required'] );
		$this->assertTrue( $field['multiple'] );
		$this->assertSame( 4.0, $field['max_size'] );
		$this->assertSame( [ 'jpg', 'jpeg', 'jpe', 'pdf' ], $field['accepted_types'] );
		$this->assertSame( 'none', $field['pricing']['type'] );
	}

	public function test_wapf_file_field_pricing_is_dropped_with_review(): void {
		$wapf = [
			'fields' => [
				[
					'id' => 'up1', 'label' => 'Paid upload', 'type' => 'file',
					'options' => [ 'multiple' => false ],
					'pricing' => [ 'enabled' => true, 'type' => 'fixed', 'amount' => 5 ],
				],
			],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'pricing', implode( ' ', $mapped['notes'] ) );
		$this->assertSame( 'none', $mapped['group']['fields'][0]['pricing']['type'] );
	}

	public function test_wapf_file_field_repeat_is_dropped_with_review(): void {
		$wapf = [
			'fields' => [
				[
					'id' => 'up1', 'label' => 'Repeat upload', 'type' => 'file',
					'clone' => [ 'enabled' => true, 'type' => 'button', 'max' => 3 ],
					'options' => [],
					'pricing' => [ 'enabled' => false ],
				],
			],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'repeat', implode( ' ', $mapped['notes'] ) );
		$this->assertArrayNotHasKey( 'repeat', $mapped['group']['fields'][0] );
	}

	public function test_wapf_products_manual_field_maps_choices_and_settings(): void {
		$wapf = [
			'fields' => [
				[
					'id' => 'linked', 'label' => 'Linked products', 'type' => 'products', 'subtype' => 'card',
					'required' => false,
					'options' => [
						'product_selection' => 'manual',
						'qty_method' => 'parent',
						'choices' => [
							[ 'id' => 12, 'slug' => 'gift', 'label' => 'Gift wrap', 'selected' => true, 'disabled' => false, 'options' => [], 'pricing_type' => 'fixed' ],
							[ 'id' => '34', 'slug' => 'extra', 'label' => '', 'selected' => false, 'disabled' => true, 'options' => [], 'pricing_type' => 'none' ],
							[ 'slug' => 'broken', 'label' => 'No product', 'options' => [], 'pricing_type' => 'fixed' ],
						],
						'items_per_row' => 3,
						'items_per_row_tablet' => 2,
						'items_per_row_mobile' => 1,
						'incl_img' => true,
						'incl_desc' => false,
						'slot_1' => 'price',
						'slot_2' => 'stock',
						'slot_3' => 'link',
						'hide_cart' => true,
					],
					'conditionals' => [], 'clone' => [ 'enabled' => false ],
					'pricing' => [ 'type' => 'none', 'amount' => 0, 'enabled' => false ],
				],
			],
			'rule_groups' => [],
		];
		$mapped = WapfMapper::map( $wapf );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'products', $field['type'] );
		$this->assertSame( 'card', $field['subtype'] );
		$this->assertSame( 'manual', $field['product_selection'] );
		$this->assertSame( 'parent', $field['qty_method'] );
		$this->assertTrue( $field['hide_cart'] );
		$this->assertSame( 3, $field['items_per_row'] );
		$this->assertSame( 2, $field['items_per_row_tablet'] );
		$this->assertSame( 1, $field['items_per_row_mobile'] );
		$this->assertTrue( $field['incl_img'] );
		$this->assertFalse( $field['incl_desc'] );
		$this->assertSame( [ 'price', 'stock', 'link' ], [ $field['slot_1'], $field['slot_2'], $field['slot_3'] ] );
		$this->assertCount( 2, $field['choices'] );
		$this->assertSame( 12, $field['choices'][0]['product_id'] );
		$this->assertSame( 'gift', $field['choices'][0]['slug'] );
		$this->assertSame( 'fixed', $field['choices'][0]['pricing_type'] );
		$this->assertTrue( $field['choices'][0]['selected'] );
		$this->assertSame( 34, $field['choices'][1]['product_id'] );
		$this->assertSame( 'none', $field['choices'][1]['pricing_type'] );
		$this->assertTrue( $field['choices'][1]['disabled'] );
		$this->assertTrue( $mapped['needs_review'], 'a choice without a product reference must flag review' );
		$this->assertStringContainsString( 'without a product reference', implode( ' ', $mapped['notes'] ) );
	}

	public function test_wapf_products_image_large_image_maps_separately_from_gallery_swap(): void {
		$mapped = WapfMapper::map( [
			'fields' => [ [
				'id' => 'linked-image', 'label' => 'Linked images', 'type' => 'products-image',
				'options' => [
					'product_selection' => 'manual', 'large_image' => '1', 'image_zoom' => false,
					'choices' => [ [ 'id' => 12, 'slug' => 'gift', 'label' => 'Gift', 'options' => [], 'pricing_type' => 'fixed' ] ],
				],
				'conditionals' => [], 'clone' => [ 'enabled' => false ],
				'pricing' => [ 'enabled' => false ],
			] ],
			'rule_groups' => [],
		] );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'image', $field['subtype'] );
		$this->assertTrue( $field['large_image'] );
		$this->assertFalse( $field['image_zoom'] );
		$this->assertFalse( $mapped['needs_review'] );
	}

	public function test_wapf_products_flattened_subtype_and_qty_choices(): void {
		$wapf = [
			'fields' => [
				[
					'id' => 'qty-addons', 'label' => 'Addons', 'type' => 'products-card-qty',
					'options' => [
						'display' => 'plus_min',
						'min_choices' => 1,
						'max_choices' => 9,
						'choices' => [
							[ 'id' => 51, 'slug' => 'a', 'label' => '', 'options' => [ 'default' => 2, 'min' => 1, 'max' => 8 ], 'pricing_type' => 'fixed' ],
						],
						'slot_1' => 'stock',
						'items_per_row' => 2,
					],
				],
			],
		];
		$mapped = WapfMapper::map( $wapf );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'products', $field['type'] );
		$this->assertSame( 'card-qty', $field['subtype'] );
		$this->assertSame( 'plus_min', $field['display'] );
		$this->assertSame( 1, $field['min_choices'] );
		$this->assertSame( 9, $field['max_choices'] );
		$this->assertSame( [ 'default' => 2, 'min' => 1, 'max' => 8 ], $field['choices'][0]['quantity'] );
		$this->assertSame( 'stock', $field['slot_1'] );
		$this->assertFalse( $mapped['needs_review'], 'clean qty products field should not flag review: ' . implode( ' | ', $mapped['notes'] ) );
	}

	public function test_wapf_products_category_selection_maps_product_query(): void {
		$wapf = [
			'fields' => [
				[
					'id' => 'related', 'label' => 'Related', 'type' => 'products', 'subtype' => 'vcard',
					'options' => [
						'product_selection' => 'category',
						'product_query' => [ 'query_id' => 44, 'query_label' => 'Extras', 'limit' => 7, 'sort' => 'name_asc', 'pricing_type' => 'none' ],
						'choices' => [ [ 'id' => 9, 'slug' => 'x', 'label' => 'Ignored' ] ],
						'img_fit' => 'contain',
						'items_per_row' => 4,
					],
				],
			],
		];
		$mapped = WapfMapper::map( $wapf );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'category', $field['product_selection'] );
		$this->assertSame( [], $field['choices'] );
		$this->assertSame( [ 'query_id' => 44, 'query_label' => 'Extras', 'limit' => 7, 'sort' => 'name_asc', 'pricing_type' => 'none' ], $field['product_query'] );
		$this->assertSame( 'contain', $field['img_fit'] );
		$this->assertFalse( $mapped['needs_review'], 'clean category products field should not flag review: ' . implode( ' | ', $mapped['notes'] ) );
	}

	public function test_wapf_products_selection_limit_flags_review(): void {
		$wapf = [
			'fields' => [
				[
					'id' => 'pick', 'label' => 'Pick', 'type' => 'products', 'subtype' => 'checkbox',
					'options' => [ 'product_selection' => 'manual', 'min_choices' => 2, 'choices' => [ [ 'id' => 7, 'slug' => 'a' ] ] ],
				],
			],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'min_choices', implode( ' ', $mapped['notes'] ) );
	}

	public function test_wapf_products_inside_repeated_section_is_dropped(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'grp', 'label' => 'Group', 'type' => 'section', 'clone' => [ 'enabled' => true, 'type' => 'button' ], 'options' => [] ],
				[ 'id' => 'linked', 'label' => 'Linked', 'type' => 'products', 'subtype' => 'checkbox', 'options' => [ 'choices' => [ [ 'id' => 7, 'slug' => 'a' ] ] ] ],
				[ 'id' => 'grp-end', 'type' => 'sectionend' ],
			],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'repeated section', implode( ' ', $mapped['notes'] ) );
		$types = array_column( $mapped['group']['fields'], 'type' );
		$this->assertNotContains( 'products', $types );
	}

	public function test_wapf_hide_options_map_to_normalized_visibility_flags(): void {
		$wapf = [
			'fields' => [
				[ 'id' => 'f1', 'label' => 'Note', 'type' => 'text', 'options' => [ 'hide_cart' => true, 'hide_order' => 'true' ] ],
			],
		];
		$mapped = WapfMapper::map( $wapf );

		$this->assertTrue( $mapped['group']['fields'][0]['hide_cart'] );
		$this->assertFalse( $mapped['group']['fields'][0]['hide_checkout'] );
		$this->assertTrue( $mapped['group']['fields'][0]['hide_order'] );
	}

	public function test_formula_normalizer_stacks_qty_strip(): void {
		$this->assertSame( '[price] * 0.5', WapfMapper::normalize_formula( '[price] * 0.5 * [qty]' ) );
		$this->assertSame( '[price] * 0.5', WapfMapper::normalize_formula( '[qty] * [price] * 0.5' ) );
		$this->assertSame( '[qty] + 1', WapfMapper::normalize_formula( '[qty] + 1' ), 'interior qty references are kept' );
		$this->assertNull( WapfMapper::normalize_formula( '[eval] * 2' ) );
		$this->assertNull( WapfMapper::normalize_formula( '' ) );
	}

	/**
	 * Installed WAPF Extended 3.1.5 `class-cart.php:56-58` treats `qty_based`
	 * as an independent field option, and `class-fields.php:298-311` preserves
	 * the formula's quantity factor when it is enabled. Source files were read
	 * from the disposable installed tree at
	 * /tmp/opf-sumqty-woo-wordpress/wp-content/plugins/advanced-product-fields-for-woocommerce-extended.
	 */
	public function test_qty_based_field_preserves_formula_quantity_factor_for_choice_and_field_pricing(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[
				'id' => 'plan',
				'label' => 'Plan',
				'type' => 'select',
				'qty_based' => true,
				'options' => [ 'choices' => [
					[ 'slug' => 'selected', 'label' => 'Selected', 'pricing_type' => 'fx', 'pricing_amount' => '([price] + [options_total]) * 2 * [qty]' ],
				] ],
				'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => '([price] + [options_total]) * 3 * [qty]' ],
			],
			[
				'id' => 'ordinary',
				'label' => 'Ordinary',
				'type' => 'select',
				'qty_based' => false,
				'options' => [ 'choices' => [
					[ 'slug' => 'selected', 'label' => 'Selected', 'pricing_type' => 'fx', 'pricing_amount' => '([price] + [options_total]) * 2 * [qty]' ],
				] ],
			],
		] ] );
		$this->assertSame( '([price] + [addons]) * 2 * [qty]', $mapped['group']['fields'][0]['choices'][0]['pricing']['formula'] );
		$this->assertSame( '([price] + [options_total]) * 2 * [qty]', $mapped['group']['fields'][0]['choices'][0]['pricing']['formula_raw'] );
		$this->assertSame( '([price] + [addons]) * 3 * [qty]', $mapped['group']['fields'][0]['pricing']['formula'] );
		$this->assertSame( '([price] + [addons]) * 2', $mapped['group']['fields'][1]['choices'][0]['pricing']['formula'] );
		$this->assertSame( 60.0, \OPF\Engine\Calculator::evaluate_formula( $mapped['group']['fields'][0]['choices'][0]['pricing']['formula'], 10.0, 3, 0.0 ) );
		$this->assertSame( 90.0, \OPF\Engine\Calculator::evaluate_formula( $mapped['group']['fields'][0]['pricing']['formula'], 10.0, 3, 0.0 ) );
		$this->assertSame( 20.0, \OPF\Engine\Calculator::evaluate_formula( $mapped['group']['fields'][1]['choices'][0]['pricing']['formula'], 10.0, 3, 0.0 ) );
	}

	public function test_formula_field_references_map_to_destination_ids_including_later_fields(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[
					'id' => 'choice-src',
					'label' => 'Plan',
					'type' => 'text-swatch',
					'options' => [
						'choices' => [
							[ 'slug' => 'custom', 'label' => 'Custom', 'pricing_type' => 'fx', 'pricing_amount' => '[field.weight-src] + checked(choice-src) * [qty]' ],
						],
					],
				],
				[
					'id' => 'weight-src',
					'label' => 'Weight',
					'type' => 'number',
					'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => '[field.choice-src] * [qty]' ],
				],
			],
		] );

		$mapped_choice = $mapped['group']['fields'][0]['choices'][0];
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'formula', $mapped_choice['pricing']['type'] );
		$this->assertSame( '[field.weight] + checked(plan) * [qty]', $mapped_choice['pricing']['formula_raw'] );
		$this->assertSame( '[field.weight] + checked(plan)', $mapped_choice['pricing']['formula'] );
		$this->assertSame( 4.0, \OPF\Engine\Calculator::evaluate_formula( $mapped_choice['pricing']['formula'], 10.0, 1, 0.0, '', null, [ 'weight' => '4' ] ) );
		// Field-level fx keeps the verbatim expression like choice fx does;
		// its outermost *[qty] marks it as intended per-unit scaling.
		$this->assertSame( '[field.plan] * [qty]', $mapped['group']['fields'][1]['pricing']['formula_raw'] );
		$this->assertSame( '[field.plan]', $mapped['group']['fields'][1]['pricing']['formula'] );
		$this->assertTrue( $mapped['group']['fields'][1]['pricing']['per_unit'] );
		$this->assertTrue( $mapped_choice['pricing']['per_unit'] );
	}

	public function test_formula_reference_to_unavailable_field_is_flagged_for_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[
					'id' => 'choice-src',
					'label' => 'Plan',
					'type' => 'text-swatch',
					'options' => [ 'choices' => [ [ 'slug' => 'custom', 'label' => 'Custom', 'pricing_type' => 'fx', 'pricing_amount' => '[field.missing] * 2' ] ] ],
				],
			],
		] );

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( 'none', $mapped['group']['fields'][0]['choices'][0]['pricing']['type'] );
		$this->assertStringContainsString( 'unavailable or ambiguous WAPF field IDs', implode( ' ', $mapped['notes'] ) );
	}

	public function test_formula_references_without_runtime_support_are_preserved_and_flagged(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[
					'id' => 'choice-src',
					'label' => 'Plan',
					'type' => 'select',
					'options' => [ 'choices' => [ [ 'slug' => 'custom', 'label' => 'Custom', 'pricing_type' => 'fx', 'pricing_amount' => '[price.weight-src] + files(weight-src) * [qty]' ] ] ],
				],
				[ 'id' => 'weight-src', 'label' => 'Weight', 'type' => 'number' ],
			],
		] );
		$pricing = $mapped['group']['fields'][0]['choices'][0]['pricing'];

		$this->assertTrue( $mapped['needs_review'] );
		$this->assertSame( '[price.weight] + files(weight) * [qty]', $pricing['formula_raw'] );
		$this->assertSame( '[price.weight] + files(weight)', $pricing['formula'] );
		$this->assertStringContainsString( 'references that require runtime review', implode( ' ', $mapped['notes'] ) );
	}

	public function test_sumqty_image_quantity_formula_reference_is_remapped_and_raw_source_is_preserved(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'wapf-image-id-91', 'label' => 'Prints', 'type' => 'image-swatch-qty', 'options' => [ 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak' ] ] ] ],
			[ 'id' => 'wapf-fee-id-92', 'label' => 'Fee', 'type' => 'select', 'options' => [ 'choices' => [ [ 'slug' => 'selected', 'label' => 'Selected', 'pricing_type' => 'fx', 'pricing_amount' => 'sumQty(wapf-image-id-91)*[qty]' ] ] ] ],
		] ] );
		$pricing = $mapped['group']['fields'][1]['choices'][0]['pricing'];

		$this->assertSame( 'prints', $mapped['group']['fields'][0]['id'] );
		$this->assertSame( 'sumQty(prints)*[qty]', $pricing['formula_raw'] );
		$this->assertSame( 'sumQty(prints)', $pricing['formula'] );
		$this->assertTrue( $mapped['needs_review'], 'The separate image-media review must remain visible.' );
		$this->assertStringNotContainsString( 'sumqty(prints)', strtolower( implode( ' ', $mapped['notes'] ) ) );
	}

	public function test_imported_fx_without_qty_factor_is_flat_per_line(): void {
		// WAPF fx on a normal field divides the result by line qty, so a
		// formula WITHOUT an outermost *[qty] imports as flat per line.
		$mapped = WapfMapper::map( [
			'fields' => [ [
				'id' => 'plan', 'label' => 'Plan', 'type' => 'select',
				'options' => [ 'choices' => [
					[ 'slug' => 'flat', 'label' => 'Flat', 'pricing_type' => 'fx', 'pricing_amount' => '[price] * 0.2' ],
					[ 'slug' => 'scaled', 'label' => 'Scaled', 'pricing_type' => 'fx', 'pricing_amount' => '([price] * 0.2) * [qty]' ],
				] ],
			] ],
		] );
		$this->assertFalse( $mapped['needs_review'] );
		$flat = $mapped['group']['fields'][0]['choices'][0]['pricing'];
		$this->assertSame( 'formula', $flat['type'] );
		$this->assertFalse( $flat['per_unit'] );
		$this->assertSame( '[price] * 0.2', $flat['formula'] );
		$this->assertSame( '[price] * 0.2', $flat['formula_raw'] );
		$scaled = $mapped['group']['fields'][0]['choices'][1]['pricing'];
		$this->assertTrue( $scaled['per_unit'] );
		$this->assertSame( '([price] * 0.2)', $scaled['formula'] );
		$this->assertSame( '([price] * 0.2) * [qty]', $scaled['formula_raw'] );
	}

	public function test_imported_p_is_flat_percent_and_qt_is_per_unit_fixed(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'gift', 'label' => 'Gift', 'type' => 'text', 'pricing' => [ 'enabled' => true, 'type' => 'p', 'amount' => 10 ] ],
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'enabled' => true, 'type' => 'qt', 'amount' => 5 ] ],
			],
		] );
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'percent', $mapped['group']['fields'][0]['pricing']['type'] );
		$this->assertFalse( $mapped['group']['fields'][0]['pricing']['per_unit'] );
		$this->assertSame( 'fixed', $mapped['group']['fields'][1]['pricing']['type'] );
		$this->assertTrue( $mapped['group']['fields'][1]['pricing']['per_unit'] );
	}

	public function test_prior_field_price_reference_is_mapped_without_manual_review(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'base-src', 'label' => 'Base', 'type' => 'text', 'pricing' => [ 'enabled' => true, 'type' => 'fixed', 'amount' => 5 ] ],
				[ 'id' => 'choice-src', 'label' => 'Plan', 'type' => 'select', 'options' => [ 'choices' => [ [ 'slug' => 'custom', 'label' => 'Custom', 'pricing_type' => 'fx', 'pricing_amount' => '[price.base-src] + 1' ] ] ] ],
			],
		] );

		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( '[price.base] + 1', $mapped['group']['fields'][1]['choices'][0]['pricing']['formula_raw'] );
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

	public function test_maps_supported_wapf_extended_date_constraints(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'delivery', 'label' => 'Delivery date', 'type' => 'date',
			'options' => [
				'placeholder' => 'Choose a date', 'disable_past' => '1', 'disable_future' => '0',
				'min_date' => '10-02-2026', 'max_date' => '7d', 'disabled_days' => [ 0, '6' ],
				'disabled_dates' => '12-25, 12-31-2026 01-02-2027', 'disable_today_after' => '14:30',
			],
		] ] ] );

		$field = $mapped['group']['fields'][0];
		$this->assertFalse( $mapped['needs_review'] );
		$this->assertSame( 'date', $field['type'] );
		$this->assertSame( 'Choose a date', $field['placeholder'] );
		$this->assertFalse( $field['allow_past'] );
		$this->assertTrue( $field['allow_future'] );
		$this->assertSame( '2026-10-02', $field['min_date'] );
		$this->assertSame( '7d', $field['max_date'] );
		$this->assertSame( [ 0, 6 ], $field['disabled_weekdays'] );
		$this->assertSame( [ '12-25', '2026-12-31 2027-01-02' ], $field['disabled_dates'] );
		$this->assertSame( '14:30', $field['cutoff_time'] );
	}

	public function test_flags_unmappable_wapf_date_rules_with_source_values(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'booking', 'label' => 'Booking date', 'type' => 'date',
			'options' => [ 'disable_today' => true, 'min_date' => '[field.start]+1d', 'default' => '10-03-2026', 'mystery' => 'keep-me' ],
		] ] ] );

		$this->assertTrue( $mapped['needs_review'] );
		$notes = implode( ' ', $mapped['notes'] );
		$this->assertStringContainsString( '[field.start]+1d', $notes );
		$this->assertStringContainsString( 'disable_today value true', $notes );
		$this->assertStringContainsString( '10-03-2026', $notes );
		$this->assertStringContainsString( 'mystery', $notes );
	}

	public function test_text_field_import_preserves_wapf_default_and_absent_state(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text',
			'options' => [ 'default' => 'Happy Birthday' ],
		] ] ] );
		$this->assertSame( 'Happy Birthday', $mapped['group']['fields'][0]['default'] );
		$this->assertFalse( $mapped['needs_review'] );

		$zero = WapfMapper::map( [ 'fields' => [ [
			'id' => 'copies', 'label' => 'Copies', 'type' => 'text',
			'options' => [ 'default' => '0' ],
		] ] ] );
		$this->assertSame( '0', $zero['group']['fields'][0]['default'] );

		$absent = WapfMapper::map( [ 'fields' => [ [
			'id' => 'note', 'label' => 'Note', 'type' => 'text',
			'options' => [ 'placeholder' => 'Add a note' ],
		] ] ] );
		$this->assertArrayNotHasKey( 'default', $absent['group']['fields'][0] );
	}

	public function test_wapf_variables_import_with_remapped_field_references(): void {
		$mapped = WapfMapper::map( [
			'fields'    => [
				[ 'id' => 'size-src', 'label' => 'Size', 'type' => 'select', 'options' => [ 'choices' => [ [ 'slug' => 'lg', 'label' => 'Large' ] ] ] ],
				[ 'id' => 'upload-src', 'label' => 'Artwork', 'type' => 'text' ],
				[ 'id' => 'fee-src', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => '[var_rate] * [qty]' ] ],
			],
			'variables' => [
				[ 'name' => 'rate', 'default' => '[field.size-src] * files(upload-src)', 'rules' => [
					[ 'type' => 'field', 'field' => 'size-src', 'condition' => '==', 'value' => 'lg', 'variable' => '2.5' ],
					[ 'type' => 'qty', 'field' => 'qty', 'condition' => 'gt', 'value' => '5', 'variable' => '1.5' ],
				] ],
			],
		] );

		$this->assertSame( 'size', $mapped['group']['fields'][0]['id'] );
		$this->assertSame( 'artwork', $mapped['group']['fields'][1]['id'] );
		$this->assertArrayHasKey( 'variables', $mapped['group'] );
		$this->assertSame( 'rate', $mapped['group']['variables'][0]['name'] );
		$this->assertSame( '[field.size] * files(artwork)', $mapped['group']['variables'][0]['default'] );
		$this->assertSame( 'size', $mapped['group']['variables'][0]['rules'][0]['field'] );
		$this->assertSame( '2.5', $mapped['group']['variables'][0]['rules'][0]['variable'] );
		$this->assertSame( 'qty', $mapped['group']['variables'][0]['rules'][1]['field'] );
		// Variable bodies are not pricing formulas: no *[qty] stripping.
		$this->assertSame( '[var_rate]', $mapped['group']['fields'][2]['pricing']['formula'] );
	}

	public function test_wapf_products_image_subtype_maps_label_pos_and_default_item_width(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'imgprod', 'label' => 'Image products', 'type' => 'products', 'subtype' => 'image',
			'options' => [ 'product_selection' => 'manual', 'label_pos' => 'out', 'choices' => [ [ 'id' => 7, 'slug' => 'a' ] ] ],
		] ] ] );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'image', $field['subtype'] );
		$this->assertSame( 'out', $field['label_pos'] );
		$this->assertSame( 68, $field['item_width'], 'WAPF default swatch width is made explicit' );
		$this->assertFalse( $mapped['needs_review'], implode( ' | ', $mapped['notes'] ) );
	}

	public function test_wapf_products_category_without_query_id_flags_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'cat', 'label' => 'Category products', 'type' => 'products', 'subtype' => 'dropdown',
			'options' => [ 'product_selection' => 'category', 'product_query' => [] ],
		] ] ] );

		$field = $mapped['group']['fields'][0];
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'without a category', implode( ' ', $mapped['notes'] ) );
		$this->assertSame( 0, $field['product_query']['query_id'] );
		$this->assertSame( [], $field['choices'] );
	}

	public function test_wapf_products_unsupported_subtype_falls_back_to_checkbox(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'p', 'label' => 'Products', 'type' => 'products', 'subtype' => 'grid',
			'options' => [ 'choices' => [ [ 'id' => 7, 'slug' => 'a' ] ] ],
		] ] ] );

		$field = $mapped['group']['fields'][0];
		$this->assertSame( 'checkbox', $field['subtype'] );
		$this->assertTrue( $mapped['needs_review'] );
		$this->assertStringContainsString( 'unsupported subtype', implode( ' ', $mapped['notes'] ) );
	}

	public function test_wapf_file_field_invalid_maxsize_and_token_flag_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'up', 'label' => 'Upload', 'type' => 'file',
			'options' => [ 'maxsize' => 'huge', 'accept' => 'application/pdf' ],
			'pricing' => [ 'enabled' => false ],
		] ] ] );

		$field = $mapped['group']['fields'][0];
		$this->assertTrue( $mapped['needs_review'] );
		$notes = implode( ' ', $mapped['notes'] );
		$this->assertStringContainsString( 'invalid maximum size', $notes );
		$this->assertStringContainsString( 'unrecognized accepted-type token', $notes );
		// Invalid values are not carried; the OPF normalizer defaults apply.
		$this->assertSame( 1.0, $field['max_size'] );
		$this->assertSame( [], $field['accepted_types'] );
	}

	public function test_wapf_hide_checkout_and_order_options_map(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'f1', 'label' => 'Note', 'type' => 'text',
			'options' => [ 'hide_checkout' => 'true', 'hide_order' => true ],
		] ] ] );

		$field = $mapped['group']['fields'][0];
		$this->assertTrue( $field['hide_checkout'] );
		$this->assertTrue( $field['hide_order'] );
		$this->assertFalse( $field['hide_cart'] );
	}

	public function test_lookuptable_formula_dimension_ids_are_remapped(): void {
		$mapped = WapfMapper::map( [
			'fields' => [
				[ 'id' => 'width-src', 'label' => 'Width', 'type' => 'number' ],
				[ 'id' => 'height-src', 'label' => 'Height', 'type' => 'number' ],
				[ 'id' => 'fee-src', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'enabled' => true, 'type' => 'fx', 'amount' => 'lookuptable(cutting;width-src;height-src) * [qty]' ] ],
			],
		] );
		$pricing = $mapped['group']['fields'][2]['pricing'];

		$this->assertSame( 'lookuptable(cutting;width;height)', $pricing['formula'] );
		$this->assertTrue( $mapped['needs_review'], 'lookuptable depends on runtime tables and stays review-flagged' );
		$this->assertStringContainsString( 'lookuptable(', implode( ' ', $mapped['notes'] ) );
	}

	public function test_checkboxes_min_and_max_choices_map_from_options_and_flat_keys(): void {
		$from_options = WapfMapper::map( [ 'fields' => [ [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'checkboxes',
			'options' => [ 'min_choices' => 1, 'max_choices' => 2, 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ] ],
		] ] ] )['group']['fields'][0];
		$this->assertSame( [ 1, 2 ], [ $from_options['min_choices'], $from_options['max_choices'] ] );

		// Raw Tools payload stores the limits top-level.
		$flat = WapfMapper::map( [ 'fields' => [ [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'checkboxes',
			'min_choices' => '2', 'max_choices' => '3', 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ],
		] ] ] )['group']['fields'][0];
		$this->assertSame( [ 2, 3 ], [ $flat['min_choices'], $flat['max_choices'] ] );
	}

	public function test_checkbox_columns_map_from_tools_and_wxr_option_shapes(): void {
		$flat = WapfMapper::map( [ 'fields' => [ [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'checkboxes',
			'columns' => '14', 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ],
		] ] ] )['group']['fields'][0];
		$this->assertSame( 14, $flat['columns'] );

		$nested = WapfMapper::map( [ 'fields' => [ [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'checkboxes',
			'options' => [ 'columns' => 4, 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ] ],
		] ] ] )['group']['fields'][0];
		$this->assertSame( 4, $nested['columns'] );

		$invalid = WapfMapper::map( [ 'fields' => [ [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'checkboxes',
			'columns' => 0, 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ],
		] ] ] );
		$this->assertTrue( $invalid['needs_review'] );
		$this->assertSame( 1, $invalid['group']['fields'][0]['columns'] ?? 1 );
	}

	public function test_inconsistent_checkbox_limits_are_dropped_with_review(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'checkboxes',
			'min_choices' => 4, 'max_choices' => 2, 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ],
		] ] ] );
		$field = $mapped['group']['fields'][0];
		$this->assertArrayNotHasKey( 'min_choices', $field );
		$this->assertArrayNotHasKey( 'max_choices', $field );
		$this->assertTrue( $mapped['needs_review'] );
	}

	public function test_text_validation_keys_map_from_flat_and_options_shapes(): void {
		$flat = WapfMapper::map( [ 'fields' => [ [
			'id' => 'txt', 'label' => 'Text', 'type' => 'text',
			'minlength' => 3, 'maxlength' => 5, 'pattern' => '[a-z]+',
		] ] ] )['group']['fields'][0];
		$this->assertSame( [ 3, 5, '[a-z]+' ], [ $flat['minlength'], $flat['maxlength'], $flat['pattern'] ] );

		$nested = WapfMapper::map( [ 'fields' => [ [
			'id' => 'area', 'label' => 'Area', 'type' => 'textarea',
			'options' => [ 'minlength' => '2', 'maxlength' => '9' ],
		] ] ] )['group']['fields'][0];
		$this->assertSame( [ 2, 9 ], [ $nested['minlength'], $nested['maxlength'] ] );
		$this->assertArrayNotHasKey( 'pattern', $nested );
	}

	public function test_nrq_and_charq_map_to_value_based_formula_pricing(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			// nrq on an image quantity choice consumes the entered count per unit.
			[ 'id' => 'prints', 'label' => 'Prints', 'type' => 'image-swatch-qty',
				'options' => [ 'choices' => [ [ 'slug' => 'one', 'label' => 'One', 'pricing_type' => 'nrq', 'pricing_amount' => 2 ] ] ] ],
			// charq on a text field consumes the character count per unit.
			[ 'id' => 'note', 'label' => 'Note', 'type' => 'text',
				'pricing' => [ 'enabled' => true, 'type' => 'charq', 'amount' => 0.5 ] ],
		] ] );
		$choice_pricing = $mapped['group']['fields'][0]['choices'][0]['pricing'];
		$this->assertSame( 'formula', $choice_pricing['type'] );
		$this->assertSame( '[x] * 2', $choice_pricing['formula'] );
		$this->assertTrue( $choice_pricing['per_unit'] );

		$field_pricing = $mapped['group']['fields'][1]['pricing'];
		$this->assertSame( 'formula', $field_pricing['type'] );
		$this->assertSame( 'len([x]) * 0.5', $field_pricing['formula'] );
		$this->assertTrue( $field_pricing['per_unit'] );
		$this->assertSame( [], preg_grep( '/nrq|charq.*not supported/i', $mapped['notes'] ) );
	}

	public function test_nr_and_char_map_to_flat_per_line_formula_pricing(): void {
		$mapped = WapfMapper::map( [ 'fields' => [
			[ 'id' => 'qty', 'label' => 'Qty', 'type' => 'number',
				'pricing' => [ 'enabled' => true, 'type' => 'nr', 'amount' => 3 ] ],
			[ 'id' => 'note', 'label' => 'Note', 'type' => 'textarea',
				'pricing' => [ 'enabled' => true, 'type' => 'char', 'amount' => 1 ] ],
		] ] );
		$this->assertSame( '[x] * 3', $mapped['group']['fields'][0]['pricing']['formula'] );
		$this->assertFalse( $mapped['group']['fields'][0]['pricing']['per_unit'] );
		$this->assertSame( 'len([x]) * 1', $mapped['group']['fields'][1]['pricing']['formula'] );
		$this->assertFalse( $mapped['group']['fields'][1]['pricing']['per_unit'] );
	}
}
