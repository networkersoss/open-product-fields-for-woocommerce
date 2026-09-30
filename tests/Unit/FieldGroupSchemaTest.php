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

	public function test_switch_control_is_only_normalized_for_true_false_and_checkbox_fields(): void {
		$toggle = FieldGroup::normalize_field( [ 'id' => 'enabled', 'type' => 'toggle', 'switch_control' => true ] );
		$checkbox = FieldGroup::normalize_field( [ 'id' => 'extras', 'type' => 'checkbox', 'switch_control' => true ] );
		$radio = FieldGroup::normalize_field( [ 'id' => 'finish', 'type' => 'radio', 'switch_control' => true ] );

		$this->assertTrue( $toggle['switch_control'] );
		$this->assertTrue( $checkbox['switch_control'] );
		$this->assertArrayNotHasKey( 'switch_control', $radio );
	}

	public function test_checkbox_columns_are_bounded_and_ignored_for_other_field_types(): void {
		$checkbox = FieldGroup::normalize_field( [ 'id' => 'extras', 'type' => 'checkbox', 'columns' => 99 ] );
		$radio = FieldGroup::normalize_field( [ 'id' => 'finish', 'type' => 'radio', 'columns' => 3 ] );

		$this->assertSame( 12, $checkbox['columns'] );
		$this->assertArrayNotHasKey( 'columns', $radio );
		$this->assertSame( 1, FieldGroup::normalize_field( [ 'id' => 'extras', 'type' => 'checkbox', 'columns' => 0 ] )['columns'] );
	}

	public function test_card_radio_layout_and_choice_content_are_normalized_safely(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'finish',
			'type' => 'radio',
			'card_layout' => 'vertical',
			'choices' => [
				[ 'slug' => 'linen', 'label' => 'Linen', 'image' => '/uploads/linen.jpg', 'description' => '<script>bad()</script>Soft woven finish' ],
				[ 'slug' => 'bad', 'label' => 'Bad', 'image' => 'javascript:alert(1)', 'description' => [ 'invalid' ] ],
				[ 'slug' => 'unsafe', 'label' => 'Unsafe', 'description' => '<script>alert(1)' ],
			],
		] );

		$this->assertSame( 'vertical', $field['card_layout'] );
		$this->assertSame( '/uploads/linen.jpg', $field['choices'][0]['image'] );
		$this->assertSame( 'Soft woven finish', $field['choices'][0]['description'] );
		$this->assertArrayNotHasKey( 'image', $field['choices'][1] );
		$this->assertArrayNotHasKey( 'description', $field['choices'][1] );
		$this->assertArrayNotHasKey( 'description', $field['choices'][2] );
	}

	public function test_instruction_presentation_accepts_tooltip_and_defaults_invalid_values_to_inline(): void {
		$this->assertSame( 'tooltip', FieldGroup::normalize_field( [ 'id' => 'note', 'type' => 'text', 'description_presentation' => 'tooltip' ] )['description_presentation'] );
		$this->assertArrayNotHasKey( 'description_presentation', FieldGroup::normalize_field( [ 'id' => 'note', 'type' => 'text', 'description_presentation' => 'popup' ] ) );
	}

	public function test_customer_surface_visibility_flags_are_normalized_independently(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'gift-note',
			'type' => 'text',
			'hide_cart' => true,
			'hide_checkout' => '1',
			'hide_order' => false,
		] );

		$this->assertTrue( $field['hide_cart'] );
		$this->assertTrue( $field['hide_checkout'] );
		$this->assertArrayNotHasKey( 'hide_order', $field );
		$this->assertArrayNotHasKey( 'hide_cart', FieldGroup::normalize_field( [ 'id' => 'legacy', 'type' => 'text' ] ) );
	}

	public function test_child_products_keep_bounded_source_selection_and_zoom_settings(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'bundle',
			'type' => 'child_products',
			'product_source' => 'categories',
			'category_ids' => [ 11, '12', 0 ],
			'product_display' => 'images',
			'multiple' => true,
			'quantity_input' => true,
			'quantity_mode' => 'multiply_parent',
			'image_zoom' => true,
			'min_selections' => 1,
			'max_selections' => 4,
		] );
		$this->assertSame( 'categories', $field['product_source'] );
		$this->assertSame( [ 11, 12 ], $field['category_ids'] );
		$this->assertSame( 'images', $field['product_display'] );
		$this->assertTrue( $field['multiple'] );
		$this->assertTrue( $field['quantity_input'] );
		$this->assertSame( 'multiply_parent', $field['quantity_mode'] );
		$this->assertTrue( $field['image_zoom'] );
	}

	public function test_field_and_choice_collections_are_not_truncated_at_an_arbitrary_count(): void {
		$fields = [];
		$choices = [];
		for ( $index = 0; $index < 512; $index++ ) {
			$fields[] = [ 'id' => 'field-' . $index, 'type' => 'text' ];
			$choices[] = [ 'slug' => 'choice-' . $index, 'label' => 'Choice ' . $index ];
		}

		$normalized_group = FieldGroup::normalize( [ 'fields' => $fields ] );
		$normalized_field = FieldGroup::normalize_field( [ 'id' => 'options', 'type' => 'select', 'choices' => $choices ] );

		$this->assertCount( 512, $normalized_group['fields'] );
		$this->assertCount( 512, $normalized_field['choices'] );
		$this->assertSame( 'field-511', $normalized_group['fields'][511]['id'] );
		$this->assertSame( 'choice-511', $normalized_field['choices'][511]['slug'] );
	}

	public function test_card_layout_is_omitted_for_non_radio_fields_or_invalid_orientation(): void {
		$this->assertArrayNotHasKey( 'card_layout', FieldGroup::normalize_field( [ 'id' => 'finish', 'type' => 'select', 'card_layout' => 'horizontal' ] ) );
		$this->assertArrayNotHasKey( 'card_layout', FieldGroup::normalize_field( [ 'id' => 'finish', 'type' => 'radio', 'card_layout' => 'grid' ] ) );
	}

	public function test_product_image_switch_is_only_kept_for_supported_choice_fields_with_images(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'finish', 'type' => 'swatch', 'change_product_image' => true,
			'choices' => [ [ 'slug' => 'blue', 'label' => 'Blue', 'image' => '/blue.jpg' ] ],
		] );
		$this->assertTrue( $field['change_product_image'] );
		$this->assertArrayNotHasKey( 'change_product_image', FieldGroup::normalize_field( [
			'id' => 'finish', 'type' => 'swatch', 'change_product_image' => true,
			'choices' => [ [ 'slug' => 'blue', 'label' => 'Blue' ] ],
		] ) );
		$this->assertArrayNotHasKey( 'change_product_image', FieldGroup::normalize_field( [
			'id' => 'note', 'type' => 'text', 'change_product_image' => true,
		] ) );
	}

	public function test_image_zoom_requires_image_swatch_choices(): void {
		$zoomed = FieldGroup::normalize_field( [
			'id' => 'fabric', 'type' => 'swatch', 'image_zoom' => true,
			'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen', 'image' => '/linen.jpg' ] ],
		] );
		$this->assertTrue( $zoomed['image_zoom'] );

		$this->assertArrayNotHasKey( 'image_zoom', FieldGroup::normalize_field( [
			'id' => 'fabric', 'type' => 'swatch', 'image_zoom' => true,
			'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen' ] ],
		] ) );
		$quantity_zoom = FieldGroup::normalize_field( [
			'id' => 'fabric', 'type' => 'swatch', 'image_quantity_zoom' => true, 'image_quantities' => true,
			'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen', 'image' => '/linen.jpg' ] ],
		] );
		$this->assertTrue( $quantity_zoom['image_quantity_zoom'] );
		$this->assertArrayNotHasKey( 'image_zoom', $quantity_zoom );
	}

	public function test_image_rules_keep_valid_targets_and_all_field_conditions_with_any_wildcards(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'color', 'type' => 'select', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ] ] ],
				[ 'id' => 'size', 'type' => 'radio', 'choices' => [ [ 'slug' => 'large', 'label' => 'Large' ] ] ],
			],
			'image_rules' => [
				[ 'target_url' => 'https://cdn.example/red-large.jpg', 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => '*' ], [ 'field' => 'color', 'value' => 'not-a-choice' ] ] ],
				[ 'target_url' => 'javascript:alert(1)', 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ] ] ],
			],
		] );
		$this->assertSame( [
			[ 'target_url' => 'https://cdn.example/red-large.jpg', 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => '*' ] ] ],
		], $group['image_rules'] );
	}

	public function test_group_lookup_tables_keep_valid_rows_and_drop_invalid_shapes(): void {
		$group = FieldGroup::normalize(
			[
				'fields' => [],
				'lookup_tables' => [
					'blinds_v1' => [ [ '200', '100', 70 ], [ '220', '160', '78.5' ], [ 'bad-row' ], [ '220', '160', 'invalid-price' ] ],
					'bad table name' => [ [ 'a', 1 ] ],
				],
			]
		);

		$this->assertSame( [ [ '200', '100', 70.0 ], [ '220', '160', 78.5 ] ], $group['lookup_tables']['blinds_v1'] );
		$this->assertArrayNotHasKey( 'bad table name', $group['lookup_tables'] );
	}

	public function test_formula_variables_normalize_and_resolve_first_matching_change(): void {
		$group = FieldGroup::normalize(
			[
				'fields' => [],
				'formula_variables' => [
					'wrap_cost' => [
						'default' => 1,
						'changes' => [
							[ 'value' => 1.5, 'logic' => 'all', 'rules' => [ [ 'field' => 'size', 'operator' => 'is', 'value' => 'large' ] ] ],
							[ 'value' => 2, 'logic' => 'any', 'rules' => [ [ 'field' => 'size', 'operator' => 'is', 'value' => 'large' ] ] ],
						],
					],
					'bad name' => [ 'default' => 8 ],
				],
			]
		);

		$this->assertSame( [ 'wrap_cost' ], array_keys( $group['formula_variables'] ) );
		$this->assertSame( 1.5, FieldGroup::resolve_formula_variables( $group['formula_variables'], [ 'size' => 'large' ] )['wrap_cost'] );
		$this->assertSame( 1.0, FieldGroup::resolve_formula_variables( $group['formula_variables'], [ 'size' => 'small' ] )['wrap_cost'] );
	}

	public function test_formula_weight_is_bounded_and_round_trips_on_input_fields(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'weight', 'type' => 'number', 'weight_formula' => '[field.weight] * 1' ] );
		$this->assertSame( '[field.weight] * 1', $field['weight_formula'] );
		$this->assertArrayNotHasKey( 'weight_formula', FieldGroup::normalize_field( [ 'id' => 'weight', 'type' => 'number', 'weight_formula' => str_repeat( 'x', 4097 ) ] ) );
		$this->assertArrayNotHasKey( 'weight_formula', FieldGroup::normalize_field( [ 'id' => 'weight', 'type' => 'number', 'weight_formula' => 42 ] ) );
	}

	public function test_number_mode_is_normalized_only_for_number_fields(): void {
		$this->assertSame( 'integer', FieldGroup::normalize_field( [ 'id' => 'count', 'type' => 'number', 'number_mode' => 'integer' ] )['number_mode'] );
		$this->assertSame( 'decimal', FieldGroup::normalize_field( [ 'id' => 'weight', 'type' => 'number', 'number_mode' => 'decimal' ] )['number_mode'] );
		$this->assertArrayNotHasKey( 'number_mode', FieldGroup::normalize_field( [ 'id' => 'count', 'type' => 'number', 'number_mode' => 'whole' ] ) );
		$this->assertArrayNotHasKey( 'number_mode', FieldGroup::normalize_field( [ 'id' => 'count', 'type' => 'text', 'number_mode' => 'integer' ] ) );
	}

	public function test_number_default_must_match_mode_and_step_constraints(): void {
		$this->assertSame( '2', FieldGroup::normalize_field( [ 'id' => 'count', 'type' => 'number', 'number_mode' => 'integer', 'default' => '2' ] )['default'] );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Number field default must satisfy' );
		FieldGroup::normalize_field( [ 'id' => 'count', 'type' => 'number', 'number_mode' => 'integer', 'default' => '2.5' ] );
	}

	public function test_number_default_must_align_with_configured_step(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Number field default must satisfy' );
		FieldGroup::normalize_field( [ 'id' => 'weight', 'type' => 'number', 'number_mode' => 'decimal', 'min' => 1, 'step' => 0.25, 'default' => '1.8' ] );
	}

	public function test_upload_file_count_limits_include_minimum_and_unlimited_maximum(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'min_files' => 2, 'max_files' => -1 ] );
		$this->assertSame( 2, $field['min_files'] );
		$this->assertSame( -1, $field['max_files'] );
		$this->assertTrue( FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'max_files' => -1 ] )['multiple'] );
	}

	public function test_upload_minimum_cannot_exceed_finite_maximum(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Minimum file count cannot exceed maximum file count.' );
		FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'min_files' => 3, 'max_files' => 2 ] );
	}

	public function test_upload_minimum_image_dimensions_are_normalized_as_positive_pixels(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'min_width' => '640', 'min_height' => 480 ] );
		$this->assertSame( 640, $field['min_width'] );
		$this->assertSame( 480, $field['min_height'] );
		$this->assertArrayNotHasKey( 'min_width', FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'min_width' => -1 ] ) );
	}

	public function test_upload_minimum_file_size_is_normalized_in_megabytes(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'min_size_mb' => '0.25' ] );
		$this->assertSame( 0.25, $field['min_size_mb'] );
		$this->assertArrayNotHasKey( 'min_size_mb', FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'min_size_mb' => -1 ] ) );
	}

	public function test_upload_auto_resize_requires_at_least_one_positive_pixel_bound(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'auto_resize' => true, 'max_width' => 1200 ] );
		$this->assertTrue( $field['auto_resize'] );
		$this->assertSame( 1200, $field['max_width'] );
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [ 'id' => 'other', 'type' => 'upload', 'auto_resize' => true ] );
	}

	public function test_upload_image_editor_options_are_normalized_and_allow_aspect_presets(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'art', 'type' => 'upload', 'image_editor_mode' => 'forced', 'image_editor_crop' => false, 'image_editor_rotate' => true, 'image_editor_flip' => false, 'image_editor_resize' => true, 'image_editor_aspect_ratio' => '4:3' ] );
		$this->assertSame( 'forced', $field['image_editor_mode'] );
		$this->assertFalse( $field['image_editor_crop'] );
		$this->assertTrue( $field['image_editor_rotate'] );
		$this->assertFalse( $field['image_editor_flip'] );
		$this->assertTrue( $field['image_editor_resize'] );
		$this->assertSame( '4:3', $field['image_editor_aspect_ratio'] );
		$this->assertArrayNotHasKey( 'image_editor_mode', FieldGroup::normalize_field( [ 'id' => 'plain', 'type' => 'upload', 'image_editor_mode' => 'sometimes' ] ) );
	}
}
