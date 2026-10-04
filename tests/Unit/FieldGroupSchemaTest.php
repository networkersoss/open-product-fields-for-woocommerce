<?php
/**
 * Field group schema-versioning tests.
 */

namespace OPF\Tests\Unit;

use InvalidArgumentException;
use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class FieldGroupSchemaTest extends TestCase {

	public function test_legacy_group_without_schema_is_upgraded_to_current_schema(): void {
		$group = FieldGroup::normalize(
			[
				'fields' => [ [ 'id' => 'note', 'label' => 'Note', 'type' => 'text' ] ],
			]
		);

		$this->assertSame( FieldGroup::SCHEMA, $group['schema'] );
		$this->assertSame( 'note', $group['fields'][0]['id'] );
	}

	public function test_unknown_future_schema_is_rejected_instead_of_silently_downgraded(): void {
		$this->expectException( InvalidArgumentException::class );

		FieldGroup::normalize( [ 'schema' => FieldGroup::SCHEMA + 1, 'fields' => [] ] );
	}

	public function test_email_and_toggle_are_canonical_field_types(): void {
		$group = FieldGroup::normalize(
			[
				'fields' => [
					[ 'id' => 'contact', 'label' => 'Contact email', 'type' => 'email' ],
					[ 'id' => 'gift-wrap', 'label' => 'Gift wrap', 'type' => 'toggle' ],
				],
			]
		);

		$this->assertSame( 'email', $group['fields'][0]['type'] );
		$this->assertSame( 'toggle', $group['fields'][1]['type'] );
	}

	public function test_date_is_a_canonical_field_type(): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'delivery-date', 'label' => 'Delivery date', 'type' => 'date' ] ] ] );

		$this->assertSame( 'date', $group['fields'][0]['type'] );
	}

	public function test_checkbox_columns_preserve_positive_integers_and_default_to_one_column(): void {
		$default = FieldGroup::normalize_field( [ 'id' => 'extras', 'type' => 'checkbox' ] );
		$this->assertSame( 1, $default['columns'] ?? 1 );
		$this->assertSame( 14, FieldGroup::normalize_field( [ 'id' => 'extras', 'type' => 'checkbox', 'columns' => '014' ] )['columns'] );

		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'extras', 'type' => 'checkbox', 'columns' => 0 ] );
	}

	public function test_checkbox_columns_survive_normalized_model_json_roundtrip(): void {
		$model = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'extras', 'type' => 'checkbox', 'columns' => 3,
			'choices' => [ [ 'slug' => 'wrap', 'label' => 'Gift wrap' ] ],
		] ] ] );
		$reloaded = FieldGroup::normalize( json_decode( json_encode( $model ), true ) );
		$this->assertSame( 3, $reloaded['fields'][0]['columns'] );
	}

	public function test_paragraph_is_static_text_without_submission_or_pricing(): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'care-note',
			'label' => 'Care note',
			'type' => 'paragraph',
			'content' => "Wash cold.\nDo not bleach.",
			'required' => true,
			'pricing' => [ 'type' => 'fixed', 'amount' => 9 ],
		] ] ] );

		$this->assertSame( 'paragraph', $group['fields'][0]['type'] );
		$this->assertSame( "Wash cold.\nDo not bleach.", $group['fields'][0]['content'] );
		$this->assertFalse( $group['fields'][0]['required'] );
		$this->assertSame( 'none', $group['fields'][0]['pricing']['type'] );
	}

	public function test_paragraph_html_mode_and_shortcode_policy_are_normalized(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'offer', 'type' => 'paragraph', 'content' => '<strong>Offer</strong> [tag]',
			'content_format' => 'html', 'process_shortcodes' => true,
		] );

		$this->assertSame( 'html', $field['content_format'] );
		$this->assertTrue( $field['process_shortcodes'] );

		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'offer', 'type' => 'paragraph', 'content_format' => 'script' ] );
	}

	public function test_content_image_is_static_and_rejects_unsafe_image_references(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'fabric-guide', 'type' => 'content_image', 'image_url' => 'https://example.test/fabric.jpg',
			'image_id' => '481', 'required' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ],
		] );
		$this->assertSame( 'content_image', $field['type'] );
		$this->assertFalse( $field['required'] );
		$this->assertSame( 'none', $field['pricing']['type'] );
		$this->assertSame( 'https://example.test/fabric.jpg', $field['image_url'] );
		$this->assertSame( 481, $field['image_id'] );

		$unsafe = FieldGroup::normalize_field( [ 'id' => 'bad', 'type' => 'content_image', 'image_url' => 'javascript:alert(1)' ] );
		$this->assertSame( '', $unsafe['image_url'] );
	}

	public function test_section_markers_are_non_submittable_and_unpriced(): void {
		$group = FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'details', 'type' => 'section', 'required' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 4 ] ],
			[ 'id' => 'details-end', 'type' => 'section_end' ],
		] ] );
		$this->assertSame( [ 'section', 'section_end' ], array_column( $group['fields'], 'type' ) );
		$this->assertFalse( $group['fields'][0]['required'] );
		$this->assertSame( 'none', $group['fields'][0]['pricing']['type'] );
	}

	public function test_repeater_settings_normalize_button_and_quantity_modes(): void {
		$button = FieldGroup::normalize_field( [ 'id' => 'name', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => 12 ] ] );
		$custom_labels = FieldGroup::normalize_field( [ 'id' => 'guests', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'add' => ' Add guest ', 'del' => '<b>Remove guest</b>', 'label' => '<em>Guest {n}</em>' ] ] );
		$default_button = FieldGroup::normalize_field( [ 'id' => 'name-default', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'button' ] ] );
		$quantity = FieldGroup::normalize_field( [ 'id' => 'ticket-name', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'qty', 'label' => 'Ticket {n}' ] ] );
		$section = FieldGroup::normalize_field( [ 'id' => 'attendees', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'quantity' ] ] );

		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 12 ], $button['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 10000, 'add' => 'Add guest', 'del' => 'Remove guest', 'label' => 'Guest {n}' ], $custom_labels['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'button', 'max' => 10000 ], $default_button['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'quantity', 'label' => 'Ticket {n}' ], $quantity['repeat'] );
		$this->assertSame( [ 'enabled' => true, 'mode' => 'quantity' ], $section['repeat'] );
	}

	public function test_image_quantity_choice_bounds_and_default_are_normalized(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'prints', 'type' => 'image_quantity',
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'default' => 12, 'min' => 2, 'max' => 8 ] ] ],
		] );
		$this->assertSame( [ 'default' => 8, 'min' => 2, 'max' => 8 ], $field['choices'][0]['quantity'] );
		$this->assertFalse( $field['required'] );
	}

	public function test_image_quantity_accepts_wapf_maximum_bound(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'prints', 'type' => 'image_quantity',
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'default' => 999999, 'min' => 0, 'max' => 999999 ] ] ],
		] );

		$this->assertSame( [ 'default' => 999999, 'min' => 0, 'max' => 999999 ], $field['choices'][0]['quantity'] );
	}

	public function test_image_quantity_normalizes_aggregate_quantity_limits(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'prints', 'type' => 'image_quantity', 'min_choices' => '3', 'max_choices' => 8,
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'max' => 12 ] ] ],
		] );

		$this->assertSame( [ 3, 8 ], [ $field['min_choices'], $field['max_choices'] ] );
		$this->assertSame( 12, $field['choices'][0]['quantity']['max'], 'The aggregate cap must not clamp a choice cap.' );
	}

	public function test_image_quantity_aggregate_minimum_cannot_exceed_maximum(): void {
		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'prints', 'type' => 'image_quantity', 'min_choices' => 9, 'max_choices' => 8,
		] );
	}

	public function test_disabled_repeaters_are_omitted_and_button_max_must_fit_integer_range(): void {
		$disabled = FieldGroup::normalize_field( [ 'id' => 'name', 'type' => 'text', 'repeat' => [ 'enabled' => false, 'mode' => 'button' ] ] );
		$this->assertArrayNotHasKey( 'repeat', $disabled );

		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'name', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'button', 'max' => '999999999999999999999999999999' ] ] );
	}

	public function test_invalid_repeater_modes_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'name', 'type' => 'text', 'repeat' => [ 'enabled' => true, 'mode' => 'clone' ] ] );
	}

	public function test_section_end_cannot_be_repeated(): void {
		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'end', 'type' => 'section_end', 'repeat' => [ 'enabled' => true ] ] );
	}

	public function test_image_choices_preserve_safe_url_and_positive_attachment_id(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'finish',
			'type' => 'swatch',
			'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg', 'image_id' => 481 ],
				[ 'slug' => 'bad-url', 'label' => 'Unsafe', 'image' => 'javascript:alert(1)', 'image_id' => -4 ],
			],
		] );

		$this->assertSame( 'https://example.test/oak.jpg', $field['choices'][0]['image'] );
		$this->assertSame( 481, $field['choices'][0]['image_id'] );
		$this->assertArrayNotHasKey( 'image', $field['choices'][1] );
		$this->assertArrayNotHasKey( 'image_id', $field['choices'][1] );
	}

	public function test_image_swatch_layout_settings_are_bounded_and_normalized(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'finish',
			'type' => 'swatch',
			'swatch_style' => 'image',
			'image_zoom' => true,
			'label_pos' => 'tooltip',
			'grid_layout' => 'flexible',
			'items_per_row' => 4,
			'items_per_row_tablet' => 2,
			'items_per_row_mobile' => 1,
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => '/oak.jpg' ] ],
		] );

		$this->assertSame( 'image', $field['swatch_style'] );
		$this->assertTrue( $field['image_zoom'] );
		$this->assertSame( 'tooltip', $field['label_pos'] );
		$this->assertSame( 'flexible', $field['grid_layout'] );
		$this->assertSame( [ 4, 2, 1 ], [ $field['items_per_row'], $field['items_per_row_tablet'], $field['items_per_row_mobile'] ] );
	}

	public function test_multi_color_swatch_options_and_colors_are_normalized_safely(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'palette',
			'type' => 'swatch',
			'swatch_style' => 'color',
			'multiple' => true,
			'min_choices' => 1,
			'max_choices' => 2,
			'color_layout' => 'rounded',
			'color_size' => 36,
			'color_label_pos' => 'hide',
			'choices' => [
				[ 'slug' => 'navy', 'label' => 'Navy', 'color' => '#123abc' ],
				[ 'slug' => 'bad', 'label' => 'Bad color', 'color' => 'url(javascript:bad)' ],
			],
		] );

		$this->assertTrue( $field['multiple'] );
		$this->assertSame( [ 1, 2 ], [ $field['min_choices'], $field['max_choices'] ] );
		$this->assertSame( [ 'rounded', 36, 'hide' ], [ $field['color_layout'], $field['color_size'], $field['color_label_pos'] ] );
		$this->assertSame( '#123ABC', $field['choices'][0]['color'] );
		$this->assertArrayNotHasKey( 'color', $field['choices'][1] );
	}

	public function test_multi_swatch_selection_limits_must_be_consistent(): void {
		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'options', 'type' => 'swatch', 'multiple' => true,
			'min_choices' => 3, 'max_choices' => 2,
		] );
	}

	public function test_checkbox_selection_limits_are_normalized_and_bounded(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'extras', 'type' => 'checkbox',
			'min_choices' => '1', 'max_choices' => 2,
			'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ],
		] );

		$this->assertSame( [ 1, 2 ], [ $field['min_choices'], $field['max_choices'] ] );
	}

	public function test_checkbox_selection_limits_must_be_consistent(): void {
		$this->expectException( InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'extras', 'type' => 'checkbox',
			'min_choices' => 3, 'max_choices' => 2,
		] );
	}

	public function test_text_validation_keys_are_normalized_and_bounded(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'code', 'type' => 'text',
			'minlength' => '3', 'maxlength' => 5, 'pattern' => '[a-z]+',
		] );
		$this->assertSame( [ 3, 5, '[a-z]+' ], [ $field['minlength'], $field['maxlength'], $field['pattern'] ] );

		$textarea = FieldGroup::normalize_field( [
			'id' => 'bio', 'type' => 'textarea', 'minlength' => 2, 'maxlength' => 40,
		] );
		$this->assertSame( [ 2, 40 ], [ $textarea['minlength'], $textarea['maxlength'] ] );

		$other = FieldGroup::normalize_field( [ 'id' => 'qty', 'type' => 'number', 'minlength' => 3, 'pattern' => 'x' ] );
		$this->assertArrayNotHasKey( 'minlength', $other );
		$this->assertArrayNotHasKey( 'pattern', $other );
	}

	public function test_duplicate_remaps_internal_field_references_and_formula_tokens(): void {
		$result = FieldGroup::duplicate(
			[
				'fields' => [
					[
						'id' => 'length',
						'label' => 'Length',
						'type' => 'number',
						'pricing' => [ 'type' => 'formula', 'formula' => '[field.length] + [field.length-extra] + [price.width]', 'formula_raw' => '[field.length] + [price.width]' ],
						'choices' => [ [ 'slug' => 'custom', 'label' => 'Custom', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.width]' ] ] ],
						'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'width', 'operator' => 'is', 'value' => 'long' ] ] ] ],
					],
					[ 'id' => 'width', 'label' => 'Width', 'type' => 'select', 'choices' => [ [ 'slug' => 'long', 'label' => 'Long' ] ] ],
				],
			]
		);

		$this->assertSame( [ 'length' => 'length-copy', 'width' => 'width-copy' ], $result['field_id_map'] );
		$this->assertSame( 'length-copy', $result['group']['fields'][0]['id'] );
		$this->assertSame( '[field.length-copy] + [field.length-extra] + [price.width-copy]', $result['group']['fields'][0]['pricing']['formula'] );
		$this->assertSame( '[field.length-copy] + [price.width-copy]', $result['group']['fields'][0]['pricing']['formula_raw'] );
		$this->assertSame( '[price.width-copy]', $result['group']['fields'][0]['choices'][0]['pricing']['formula'] );
		$this->assertSame( 'width-copy', $result['group']['fields'][0]['conditionals'][0]['rules'][0]['field'] );
		$this->assertSame( [ 'long' ], array_column( $result['group']['fields'][1]['choices'], 'slug' ) );
	}

	public function test_duplicate_ids_uses_collision_free_suffixes(): void {
		$result = FieldGroup::duplicate(
			[
				'fields' => [
					[ 'id' => 'length', 'label' => 'First', 'type' => 'text' ],
					[ 'id' => 'length-copy', 'label' => 'Existing copy', 'type' => 'text' ],
				],
			]
		);

		$this->assertSame( [ 'length' => 'length-copy-2', 'length-copy' => 'length-copy-copy' ], $result['field_id_map'] );
	}

	public function test_duplicate_rejects_repeated_source_field_ids(): void {
		$this->expectException( InvalidArgumentException::class );

		FieldGroup::duplicate( [ 'fields' => [ [ 'id' => 'same', 'label' => 'A' ], [ 'id' => 'same', 'label' => 'B' ] ] ] );
	}

	public function test_variables_normalize_to_wapf_canonical_shape(): void {
		$group = FieldGroup::normalize( [
			'fields'    => [ [ 'id' => 'size', 'type' => 'select' ] ],
			'variables' => [
				[
					'name'    => 'rate',
					'default' => 2,
					'rules'   => [
						[ 'type' => 'field', 'field' => 'size', 'condition' => '==', 'value' => 'lg', 'variable' => 5 ],
						[ 'type' => 'qty', 'field' => 'qty', 'condition' => 'gt', 'value' => 3, 'variable' => '1.5' ],
					],
				],
			],
		] );

		$this->assertSame(
			[ [
				'name'    => 'rate',
				'default' => '2',
				'rules'   => [
					[ 'type' => 'field', 'field' => 'size', 'condition' => '==', 'value' => 'lg', 'variable' => '5' ],
					[ 'type' => 'qty', 'field' => 'qty', 'condition' => 'gt', 'value' => '3', 'variable' => '1.5' ],
				],
			] ],
			$group['variables']
		);
	}

	public function test_variables_omit_key_when_absent_and_drop_malformed_entries(): void {
		$without = FieldGroup::normalize( [ 'fields' => [] ] );
		$this->assertArrayNotHasKey( 'variables', $without );

		$group = FieldGroup::normalize( [
			'fields'    => [],
			'variables' => [
				'not-an-array',
				[ 'default' => '1', 'rules' => [] ],
				[ 'name' => 'ok', 'default' => '1', 'rules' => [ 'malformed' ] ],
			],
		] );
		$this->assertSame(
			[ [ 'name' => 'ok', 'default' => '1', 'rules' => [] ] ],
			$group['variables']
		);
	}
}
