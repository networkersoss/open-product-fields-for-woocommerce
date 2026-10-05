<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use PHPUnit\Framework\TestCase;

final class WapfExporterTest extends TestCase {

	public function test_exports_supported_tools_json_and_preserves_field_and_placement_rules(): void {
		$group = FieldGroup::normalize( [
			'labels_position' => 'below',
			'mark_required' => false,
			'fields' => [
				[ 'id' => 'source', 'label' => 'Source', 'type' => 'text' ],
				[
					'id' => 'finish', 'label' => 'Finish', 'type' => 'radio',
					'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'source', 'operator' => 'is', 'value' => 'ready' ] ] ] ],
					'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen', 'selected' => true, 'pricing' => [ 'type' => 'fixed', 'amount' => 4, 'per_unit' => true ] ] ],
				],
			],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'not_in', 'terms' => [ '42', '43' ] ] ] ] ],
		] );

		$payload = WapfExporter::build_payload( $group );

		$this->assertSame( [ 'fields', 'conditions', 'layout', 'variables' ], array_keys( $payload ) );
		$this->assertSame( 'radio', $payload['fields'][1]['type'] );
		$this->assertSame( 'qt', $payload['fields'][1]['choices'][0]['pricing_type'] );
		$this->assertSame( [ 'field' => 'source', 'condition' => '==', 'value' => 'ready' ], $payload['fields'][1]['conditionals'][0]['rules'][0] );
		$this->assertSame( '!products', $payload['conditions'][0]['rules'][0]['condition'] );
		$this->assertSame( 'product', $payload['conditions'][0]['rules'][0]['subject'] );
		$this->assertSame( [ [ 'id' => '42', 'text' => '42' ], [ 'id' => '43', 'text' => '43' ] ], $payload['conditions'][0]['rules'][0]['value'] );
		$this->assertSame( [ 'labels_position' => 'below', 'instructions_position' => 'field', 'mark_required' => false ], $payload['layout'] );
		$this->assertSame( [], $payload['variables'] );

		$wapf_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices', 'placeholder', 'default', 'p_content', 'minimum', 'maximum' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $wapf_fields, 'rule_groups' => $payload['conditions'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( 'source', $round_trip['group']['fields'][1]['conditionals'][0]['rules'][0]['field'] );
		$this->assertTrue( $round_trip['group']['fields'][1]['choices'][0]['pricing']['per_unit'] );
		$this->assertSame( [ '42', '43' ], $round_trip['group']['rule_groups'][0]['rules'][0]['terms'] );
	}

	public function test_maps_product_category_and_tag_placement_condition_ids(): void {
		$group = FieldGroup::normalize( [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'category', 'operator' => 'in', 'terms' => [ '12' ] ],
			[ 'subject' => 'tag', 'operator' => 'not_in', 'terms' => [ '34' ] ],
		] ] ] ] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( [ 'product_cats', 'product' ], [ $payload['conditions'][0]['rules'][0]['condition'], $payload['conditions'][0]['rules'][0]['subject'] ] );
		$this->assertSame( '!p_tags', $payload['conditions'][0]['rules'][1]['condition'] );
	}

	public function test_exports_variation_and_attribute_placement_rules(): void {
		$group = FieldGroup::normalize( [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '901' ] ],
			[ 'subject' => 'product_var', 'operator' => 'not_in', 'terms' => [ '902' ] ],
			[ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red' ] ],
			[ 'subject' => 'var_att', 'operator' => 'not_in', 'terms' => [ 'color|*' ] ],
		] ] ] ] );

		$payload = WapfExporter::build_payload( $group );
		$rules = $payload['conditions'][0]['rules'];

		$this->assertSame( [ 'product_variation', 'product_variation', 'var_att', 'var_att' ], array_column( $rules, 'subject' ) );
		$this->assertSame( [ 'product_var', '!product_var', 'patts', '!patts' ], array_column( $rules, 'condition' ) );
		$this->assertSame( [ '901' ], array_column( $rules[0]['value'], 'id' ) );
		$this->assertSame( [ 'color|red' ], array_column( $rules[2]['value'], 'id' ) );

		$round_trip = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $payload['conditions'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( 'product_var', $round_trip['group']['rule_groups'][0]['rules'][0]['subject'] );
		$this->assertSame( [ '901' ], $round_trip['group']['rule_groups'][0]['rules'][0]['terms'] );
		$this->assertSame( 'var_att', $round_trip['group']['rule_groups'][0]['rules'][2]['subject'] );
		$this->assertSame( [ 'color|red' ], $round_trip['group']['rule_groups'][0]['rules'][2]['terms'] );
	}

	public function test_exports_canonical_imported_product_category_and_tag_placement_rules(): void {
		$group = FieldGroup::normalize( [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product_cat', 'operator' => 'in', 'terms' => [ '12' ] ],
			[ 'subject' => 'product_tag', 'operator' => 'not_in', 'terms' => [ '34' ] ],
		] ] ] ] );

		$payload = WapfExporter::build_payload( $group );

		$this->assertSame( 'product_cats', $payload['conditions'][0]['rules'][0]['condition'] );
		$this->assertSame( '!p_tags', $payload['conditions'][0]['rules'][1]['condition'] );
		$this->assertSame( [ '12' ], array_column( $payload['conditions'][0]['rules'][0]['value'], 'id' ) );
		$this->assertSame( [ '34' ], array_column( $payload['conditions'][0]['rules'][1]['value'], 'id' ) );

		$round_trip = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $payload['conditions'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( 'product_cat', $round_trip['group']['rule_groups'][0]['rules'][0]['subject'] );
		$this->assertSame( [ '12' ], $round_trip['group']['rule_groups'][0]['rules'][0]['terms'] );
		$this->assertSame( 'product_tag', $round_trip['group']['rule_groups'][0]['rules'][1]['subject'] );
		$this->assertSame( 'not_in', $round_trip['group']['rule_groups'][0]['rules'][1]['operator'] );
	}

	public function test_disabled_choices_round_trip_through_wapf_tools_payload(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'finish', 'label' => 'Finish', 'type' => 'select',
			'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'disabled' => true ],
				[ 'slug' => 'ash', 'label' => 'Ash', 'disabled' => false ],
			],
		] ] ] ) );

		$this->assertTrue( $payload['fields'][0]['choices'][0]['disabled'] );
		$source_field = $payload['fields'][0];
		$source_field['options'] = [ 'choices' => $source_field['choices'] ];
		$round_trip = WapfMapper::map( [ 'fields' => [ $source_field ] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertTrue( $round_trip['group']['fields'][0]['choices'][0]['disabled'] );
	}

	public function test_date_settings_round_trip_through_wapf_tools_payload(): void {
		$opf = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'when', 'label' => 'When', 'type' => 'date',
			'allow_past' => false,
			'allow_future' => true,
			'min_date' => '2027-02-10',
			'max_date' => '1y',
			'disabled_weekdays' => [ 0, 6 ],
			'disabled_dates' => [ '2027-02-10 2027-02-12', '12-25' ],
			'cutoff_time' => '12:00',
		] ] ] );

		$payload = WapfExporter::build_payload( $opf );
		$field   = $payload['fields'][0];
		$this->assertSame( 'date', $field['type'] );
		$this->assertTrue( $field['disable_past'] );
		$this->assertFalse( $field['disable_future'] );
		$this->assertSame( '02-10-2027', $field['min_date'] );
		$this->assertSame( '1y', $field['max_date'] );
		$this->assertSame( '0,6', $field['disabled_days'] );
		$this->assertSame( '02-10-2027 02-12-2027,12-25', $field['disabled_dates'] );
		$this->assertSame( '12:00', $field['disable_today_after'] );

		$date_keys = [ 'disable_past', 'disable_future', 'min_date', 'max_date', 'disabled_days', 'disabled_dates', 'disable_today_after' ];
		$field['options'] = array_intersect_key( $field, array_flip( $date_keys ) );
		$round_trip = WapfMapper::map( [ 'fields' => [ $field ] ] );

		$this->assertFalse( $round_trip['needs_review'] );
		$mapped = $round_trip['group']['fields'][0];
		$this->assertFalse( $mapped['allow_past'] );
		$this->assertTrue( $mapped['allow_future'] );
		$this->assertSame( '2027-02-10', $mapped['min_date'] );
		$this->assertSame( '1y', $mapped['max_date'] );
		$this->assertSame( [ 0, 6 ], $mapped['disabled_weekdays'] );
		$this->assertSame( [ '2027-02-10 2027-02-12', '12-25' ], $mapped['disabled_dates'] );
		$this->assertSame( '12:00', $mapped['cutoff_time'] );
	}

	public function test_date_export_keeps_wapf_bare_zero_weekday_and_relative_bounds(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'when', 'label' => 'When', 'type' => 'date',
			'min_date' => '-1m -7d',
			'disabled_weekdays' => [ 0 ],
		] ] ] ) );

		$this->assertSame( '-1m -7d', $payload['fields'][0]['min_date'] );
		$this->assertSame( '0', $payload['fields'][0]['disabled_days'] );
	}

	public function test_date_disable_today_and_field_relative_bounds_round_trip_through_wapf_tools_payload(): void {
		$opf = FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'start', 'label' => 'Start', 'type' => 'date' ],
			[ 'id' => 'end', 'label' => 'End', 'type' => 'date', 'disable_today' => true, 'min_date' => '[field.start]+1d', 'max_date' => '[field.start]3m' ],
		] ] );

		$payload = WapfExporter::build_payload( $opf );
		$end = $payload['fields'][1];
		$this->assertTrue( $end['disable_today'] );
		$this->assertSame( '[field.start]+1d', $end['min_date'] );
		$this->assertSame( '[field.start]3m', $end['max_date'] );

		$date_keys = [ 'disable_past', 'disable_future', 'disable_today', 'min_date', 'max_date', 'disabled_days', 'disabled_dates', 'disable_today_after' ];
		$start = $payload['fields'][0];
		$start['options'] = array_intersect_key( $start, array_flip( $date_keys ) );
		$end['options'] = array_intersect_key( $end, array_flip( $date_keys ) );

		$round_trip = WapfMapper::map( [ 'fields' => [ $start, $end ] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$mapped = $round_trip['group']['fields'][1];
		$this->assertTrue( $mapped['disable_today'] );
		$this->assertSame( '[field.start]+1d', $mapped['min_date'] );
		$this->assertSame( '[field.start]3m', $mapped['max_date'] );
	}

	public function test_number_display_plus_min_round_trips_through_wapf_tools_payload(): void {
		$opf = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'qty', 'label' => 'Qty', 'type' => 'number', 'display' => 'plus_min', 'number_mode' => 'integer',
		] ] ] );

		$payload = WapfExporter::build_payload( $opf );
		$this->assertSame( 'plus_min', $payload['fields'][0]['display'] );

		$field = $payload['fields'][0];
		$field['options'] = array_intersect_key( $field, array_flip( [ 'number_type', 'display', 'minimum', 'maximum', 'default', 'placeholder', 'hide_zero' ] ) );
		$round_trip = WapfMapper::map( [ 'fields' => [ $field ] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( 'plus_min', $round_trip['group']['fields'][0]['display'] );
	}

	public function test_number_integer_mode_round_trips_bounds_and_default(): void {
		$opf = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'count', 'label' => 'Count', 'type' => 'number',
			'number_mode' => 'integer', 'min' => 1, 'max' => 10, 'step' => 1, 'default' => '4',
		] ] ] );

		$field = WapfExporter::build_payload( $opf )['fields'][0];
		$this->assertSame( 'int', $field['number_type'] );
		$this->assertSame( 1.0, $field['minimum'] );
		$this->assertSame( 10.0, $field['maximum'] );
		$this->assertSame( '4', $field['default'] );
		// OPF constraint keys never leak, and WAPF 3.1.5 stores no `step` key.
		$this->assertArrayNotHasKey( 'min', $field );
		$this->assertArrayNotHasKey( 'max', $field );
		$this->assertArrayNotHasKey( 'step', $field );
		$this->assertArrayNotHasKey( 'number_mode', $field );

		$mapped = $this->round_trip_number_field( $field );
		$this->assertSame( [ 1.0, 10.0, 'integer', '4' ], [ $mapped['min'], $mapped['max'], $mapped['number_mode'], $mapped['default'] ] );
	}

	public function test_number_decimal_mode_exports_any_when_no_step_is_configured(): void {
		$opf = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'weight', 'label' => 'Weight', 'type' => 'number',
			'number_mode' => 'decimal', 'min' => 0.5, 'default' => '2.5',
		] ] ] );

		$field = WapfExporter::build_payload( $opf )['fields'][0];
		$this->assertSame( 'any', $field['number_type'] );
		$this->assertSame( 0.5, $field['minimum'] );
		$this->assertArrayNotHasKey( 'maximum', $field );

		$mapped = $this->round_trip_number_field( $field );
		$this->assertSame( [ 'decimal', '2.5' ], [ $mapped['number_mode'], $mapped['default'] ] );
		$this->assertSame( 0.5, $mapped['min'] );
	}

	public function test_number_decimal_mode_carries_custom_step_in_number_type(): void {
		$opf = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'length', 'label' => 'Length', 'type' => 'number',
			'number_mode' => 'decimal', 'min' => 1, 'max' => 3, 'step' => 0.25, 'default' => '2',
		] ] ] );

		$field = WapfExporter::build_payload( $opf )['fields'][0];
		// WAPF writes `number_type` verbatim into its `step` attribute.
		$this->assertSame( '0.25', $field['number_type'] );
		$this->assertArrayNotHasKey( 'step', $field );

		$mapped = $this->round_trip_number_field( $field );
		$this->assertSame( [ 'decimal', 0.25 ], [ $mapped['number_mode'], $mapped['step'] ] );
		$this->assertSame( [ 1.0, 3.0, '2' ], [ $mapped['min'], $mapped['max'], $mapped['default'] ] );
	}

	public function test_number_export_omits_unset_bounds_and_keeps_decimal_mode(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'free', 'label' => 'Free', 'type' => 'number' ],
			[ 'id' => 'floor', 'label' => 'Floor', 'type' => 'number', 'number_mode' => 'decimal', 'min' => 2, 'step' => 0 ],
		] ] ) );

		$free = $payload['fields'][0];
		$this->assertSame( 'int', $free['number_type'] );
		$this->assertArrayNotHasKey( 'minimum', $free );
		$this->assertArrayNotHasKey( 'maximum', $free );
		$this->assertSame( 'integer', $this->round_trip_number_field( $free )['number_mode'] );

		// A dropped (non-positive) step leaves a decimal field at `any`.
		$floor = $payload['fields'][1];
		$this->assertSame( 'any', $floor['number_type'] );
		$this->assertSame( 2.0, $floor['minimum'] );
		$this->assertArrayNotHasKey( 'maximum', $floor );
		$mapped = $this->round_trip_number_field( $floor );
		$this->assertSame( [ 'decimal', 2.0 ], [ $mapped['number_mode'], $mapped['min'] ] );
	}

	public function test_sumqty_image_quantity_formula_round_trips_with_remapped_field_id_and_raw_expression(): void {
		$source = [ 'fields' => [
			[ 'id' => 'wapf-image-id-91', 'label' => 'Prints', 'type' => 'image-swatch-qty', 'options' => [ 'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'options' => [ 'min' => 0, 'max' => 8, 'default' => 0 ] ] ] ] ],
			[ 'id' => 'wapf-fee-id-92', 'label' => 'Fee', 'type' => 'select', 'options' => [ 'choices' => [ [ 'slug' => 'selected', 'label' => 'Selected', 'pricing_type' => 'fx', 'pricing_amount' => 'sumQty(wapf-image-id-91)*[qty]' ] ] ] ],
		] ];
		$imported = WapfMapper::map( $source );
		$payload = WapfExporter::build_payload( $imported['group'] );
		$round_trip_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices', 'min_choices', 'max_choices', 'large_image', 'label_pos', 'items_per_row', 'items_per_row_tablet', 'items_per_row_mobile' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $round_trip_fields ] );

		$this->assertSame( 'image-swatch-qty', $payload['fields'][0]['type'] );
		$this->assertSame( [ 'min' => 0, 'max' => 8, 'default' => 0 ], $payload['fields'][0]['choices'][0]['options'] );
		$this->assertSame( [ 'type' => 'fx', 'amount' => 'sumQty(prints)*[qty]' ], [ 'type' => $payload['fields'][1]['choices'][0]['pricing_type'], 'amount' => $payload['fields'][1]['choices'][0]['pricing_amount'] ] );
		$this->assertSame( 'sumQty(prints)*[qty]', $round_trip['group']['fields'][1]['choices'][0]['pricing']['formula_raw'] );
		$this->assertSame( 'sumQty(prints)', $round_trip['group']['fields'][1]['choices'][0]['pricing']['formula'] );
		$this->assertTrue( $round_trip['needs_review'], 'The formula pricing review flag must survive export/import.' );
	}

	public function test_exports_paragraph_as_wapf_content_with_plain_p_content(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'care-note', 'label' => '', 'type' => 'paragraph', 'content' => "Wash cold.\nDo not bleach." ] ],
		] ) );

		$this->assertSame( 'content', $payload['fields'][0]['type'] );
		$this->assertSame( "Wash cold.\nDo not bleach.", $payload['fields'][0]['p_content'] );
		$this->assertFalse( $payload['fields'][0]['required'] );
		$this->assertFalse( $payload['fields'][0]['pricing']['enabled'] );
	}

	public function test_refuses_lossy_html_in_free_wapf_paragraph_export(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'sanitizes paragraph content' );
		WapfExporter::build_payload( FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'care-note', 'type' => 'paragraph', 'content' => '<strong>Wash cold</strong>' ] ],
		] ) );
	}

	public function test_exports_extended_html_paragraph_as_p_with_shortcode_processing(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'offer', 'label' => 'Offer', 'type' => 'paragraph',
			'content_format' => 'html', 'process_shortcodes' => true,
			'content' => '<strong>Special</strong> [site_name]',
		] ] ] ) );

		$this->assertSame( 'p', $payload['fields'][0]['type'] );
		$this->assertSame( '<strong>Special</strong> [site_name]', $payload['fields'][0]['p_content'] );
		$round_trip = WapfMapper::map( [ 'fields' => $payload['fields'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( 'html', $round_trip['group']['fields'][0]['content_format'] );
		$this->assertTrue( $round_trip['group']['fields'][0]['process_shortcodes'] );
	}

	public function test_refuses_extended_paragraph_export_when_shortcode_policy_would_change(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'always processes shortcodes' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'offer', 'type' => 'paragraph', 'content_format' => 'html',
			'process_shortcodes' => false, 'content' => '[site_name]',
		] ] ] ) );
	}

	public function test_exports_informative_image_as_wapf_img_with_media_references(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'fabric-guide', 'label' => 'Fabric guide', 'type' => 'content_image',
			'image_url' => 'https://example.test/fabric.jpg', 'image_id' => 481,
		] ] ] ) );

		$this->assertSame( 'img', $payload['fields'][0]['type'] );
		$this->assertSame( 'https://example.test/fabric.jpg', $payload['fields'][0]['image'] );
		$this->assertSame( 481, $payload['fields'][0]['attachment'] );
		$this->assertFalse( $payload['fields'][0]['required'] );
	}

	public function test_exports_nested_sections_and_rejects_unbalanced_markers(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'details', 'type' => 'section' ],
			[ 'id' => 'details-inner', 'type' => 'section' ],
			[ 'id' => 'inner-end', 'type' => 'section_end' ],
			[ 'id' => 'outer-end', 'type' => 'section_end' ],
		] ] ) );
		$this->assertSame( [ 'section', 'section', 'sectionend', 'sectionend' ], array_column( $payload['fields'], 'type' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'section-end marker without an open section' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'orphan', 'type' => 'section_end' ] ] ] ) );
	}

	public function test_rejects_unclosed_section_on_export(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'section without a matching section-end marker' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'open', 'type' => 'section' ] ] ] ) );
	}

	public function test_exports_logged_in_and_logged_out_placement(): void {
		$group = FieldGroup::normalize( [ 'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'user_auth', 'operator' => 'not_in', 'terms' => [ 'logged_in' ] ] ] ] ] ] );
		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( [ 'subject' => 'user', 'condition' => '!auth', 'value' => [] ], $payload['conditions'][0]['rules'][0] );
		$round_trip = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $payload['conditions'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( [ 'operator' => 'not_in', 'terms' => [ 'logged_in' ] ], array_intersect_key( $round_trip['group']['rule_groups'][0]['rules'][0], array_flip( [ 'operator', 'terms' ] ) ) );
	}

	public function test_exports_role_and_language_rules_as_wapf_user_and_system_conditions(): void {
		$group = FieldGroup::normalize( [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'user_role', 'operator' => 'in', 'terms' => [ 'wholesale' ] ],
			[ 'subject' => 'user_role', 'operator' => 'not_in', 'terms' => [ 'suspended' ] ],
			[ 'subject' => 'user_language', 'operator' => 'in', 'terms' => [ 'nl_NL' ] ],
		] ] ] ] );
		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( [ 'user', 'role' ], [ $payload['conditions'][0]['rules'][0]['subject'], $payload['conditions'][0]['rules'][0]['condition'] ] );
		$this->assertSame( '!role', $payload['conditions'][0]['rules'][1]['condition'] );
		$this->assertSame( [ 'system', 'lang' ], [ $payload['conditions'][0]['rules'][2]['subject'], $payload['conditions'][0]['rules'][2]['condition'] ] );
		$round_trip = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $payload['conditions'] ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( [ 'user_role', 'user_role', 'user_language' ], array_column( $round_trip['group']['rule_groups'][0]['rules'], 'subject' ) );
	}

	public function test_exports_variation_attribute_and_type_placement(): void {
		$group = FieldGroup::normalize( [ 'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55' ] ],
			[ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red', 'size|*' ] ],
			[ 'subject' => 'product_type', 'operator' => 'not_in', 'terms' => [ 'grouped' ] ],
		] ] ] ] );
		$payload = WapfExporter::build_payload( $group );
		$rules = $payload['conditions'][0]['rules'];
		$this->assertSame( [ 'product_variation', 'product_var' ], [ $rules[0]['subject'], $rules[0]['condition'] ] );
		$this->assertSame( [ 'var_att', 'patts' ], [ $rules[1]['subject'], $rules[1]['condition'] ] );
		$this->assertSame(
			[ [ 'id' => 'color|red', 'text' => 'color|red' ], [ 'id' => 'size|*', 'text' => 'size|*' ] ],
			$rules[1]['value']
		);
		$this->assertSame( [ 'product_type', '!product_type' ], [ $rules[2]['subject'], $rules[2]['condition'] ] );

		$round_trip = WapfMapper::map( [ 'fields' => [], 'rule_groups' => $payload['conditions'] ] );
		$this->assertFalse( $round_trip['needs_review'], implode( ' | ', $round_trip['notes'] ) );
		$this->assertSame(
			[
				[ 'subject' => 'product_var', 'operator' => 'in', 'terms' => [ '55' ] ],
				[ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red', 'size|*' ] ],
				[ 'subject' => 'product_type', 'operator' => 'not_in', 'terms' => [ 'grouped' ] ],
			],
			$round_trip['group']['rule_groups'][0]['rules']
		);
	}

	public function test_refuses_variation_scoped_field_conditional_on_export(): void {
		$group = FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [
				[ 'subject' => 'var_att', 'operator' => 'in', 'terms' => [ 'color|red' ] ],
			] ] ] ],
		] ] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'variation-scoped field conditional' );
		WapfExporter::build_payload( $group );
	}

	public function test_expands_any_rules_into_or_conditionals_and_maps_wapf_pro_operators(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'source', 'label' => 'Source', 'type' => 'text' ],
				[ 'id' => 'other', 'label' => 'Other', 'type' => 'text' ],
				[ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'action' => 'show', 'logic' => 'any', 'rules' => [
					[ 'field' => 'source', 'operator' => 'not_contains', 'value' => 'bad' ],
					[ 'field' => 'other', 'operator' => 'greater', 'value' => '3' ],
				] ] ] ],
			],
		] );

		$payload = WapfExporter::build_payload( $group );

		$this->assertCount( 2, $payload['fields'][2]['conditionals'] );
		$this->assertSame( '!=contains', $payload['fields'][2]['conditionals'][0]['rules'][0]['condition'] );
		$this->assertSame( 'gt', $payload['fields'][2]['conditionals'][1]['rules'][0]['condition'] );
	}

	public function test_refuses_settings_that_wapf_tools_import_would_not_preserve(): void {
		$group = FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'action' => 'hide', 'logic' => 'all', 'rules' => [ [ 'field' => 'source', 'operator' => 'is', 'value' => 'bad' ] ] ] ] ] ],
		] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'hide conditionals' );
		WapfExporter::build_payload( $group );
	}

	public function test_maps_toggle_condition_truth_values(): void {
		$group = FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'enabled', 'label' => 'Enabled', 'type' => 'toggle' ],
			[ 'id' => 'target', 'label' => 'Target', 'type' => 'text', 'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [
				[ 'field' => 'enabled', 'operator' => 'is', 'value' => '0' ],
			] ] ] ],
		] ] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( 'true-false', $payload['fields'][0]['type'] );
		$this->assertSame( '!check', $payload['fields'][1]['conditionals'][0]['rules'][0]['condition'] );
	}

	public function test_refuses_unknown_group_and_field_data_instead_of_silently_dropping_it(): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [ 'id' => 'target', 'label' => 'Target', 'type' => 'text' ] ] ] );
		$group['fields'][0]['unknown'] = 'loss';

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot preserve' );
		WapfExporter::build_payload( $group );
	}

	public function test_exports_image_swatches_and_media_references(): void {
		$group = FieldGroup::normalize( [
			'fields' => [ [
				'id' => 'finish',
				'label' => 'Finish',
				'type' => 'swatch',
				'swatch_style' => 'image',
				'image_zoom' => true,
				'label_pos' => 'tooltip',
				'grid_layout' => 'flexible',
				'items_per_row' => 4,
				'items_per_row_tablet' => 2,
				'items_per_row_mobile' => 1,
				'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg', 'image_id' => 481 ] ],
			] ],
		] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( 'image-swatch', $payload['fields'][0]['type'] );
		$this->assertTrue( $payload['fields'][0]['large_image'] );
		$this->assertSame( 'tooltip', $payload['fields'][0]['label_pos'] );
		$this->assertSame( [ 4, 2, 1 ], [ $payload['fields'][0]['items_per_row'], $payload['fields'][0]['items_per_row_tablet'], $payload['fields'][0]['items_per_row_mobile'] ] );
		$this->assertSame( 'https://example.test/oak.jpg', $payload['fields'][0]['choices'][0]['image'] );
		$this->assertSame( 481, $payload['fields'][0]['choices'][0]['attachment'] );
	}

	public function test_products_image_exports_large_image_without_gallery_swap_setting(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [
			'fields' => [ [
				'id' => 'linked-image', 'label' => 'Linked images', 'type' => 'products', 'subtype' => 'image',
				'large_image' => true, 'image_zoom' => false,
				'choices' => [ [ 'product_id' => 481 ] ],
			] ],
		] ) );

		$this->assertTrue( $payload['fields'][0]['large_image'] );
		$this->assertArrayNotHasKey( 'image_zoom', $payload['fields'][0] );
	}

	public function test_large_image_is_not_allowed_on_non_product_image_fields(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown field data: large_image' );
		WapfExporter::build_payload( [ 'fields' => [ [
			'id' => 'note', 'label' => 'Note', 'type' => 'text', 'large_image' => true,
		] ] ] );
	}

	public function test_exports_multi_color_swatches_and_selection_limits(): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'palette', 'label' => 'Palette', 'type' => 'swatch', 'swatch_style' => 'color',
			'multiple' => true, 'min_choices' => 1, 'max_choices' => 2,
			'color_layout' => 'rounded', 'color_size' => 36, 'color_label_pos' => 'default',
			'choices' => [ [ 'slug' => 'navy', 'label' => 'Navy', 'color' => '#123456' ] ],
		] ] ] );

		$field = WapfExporter::build_payload( $group )['fields'][0];
		$this->assertSame( 'multi-color-swatch', $field['type'] );
		$this->assertSame( [ 1, 2 ], [ $field['min_choices'], $field['max_choices'] ] );
		$this->assertSame( [ 'rounded', 36, 'default' ], [ $field['layout'], $field['size'], $field['label_pos'] ] );
		$this->assertSame( '#123456', $field['choices'][0]['color'] );
	}

	public function test_checkbox_columns_export_as_wapf_tools_key(): void {
		$group = FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'columns' => 3,
			'choices' => [ [ 'slug' => 'gift-wrap', 'label' => 'Gift wrap' ] ],
		] ] ] );

		$field = WapfExporter::build_payload( $group )['fields'][0];
		$this->assertSame( 'checkboxes', $field['type'] );
		$this->assertSame( 3, $field['columns'] );
	}

	public function test_exports_formula_field_references_as_resolvable_wapf_ids(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number',
					'pricing' => [ 'type' => 'formula', 'formula' => '[field.plan] + [price.fee]', 'per_unit' => false ] ],
				[ 'id' => 'plan', 'label' => 'Plan', 'type' => 'select',
					'choices' => [
						[ 'slug' => 'custom', 'label' => 'Custom', 'pricing' => [ 'type' => 'formula', 'formula' => '[field.weight] + checked(plan)', 'per_unit' => true ] ],
						[ 'slug' => 'prior', 'label' => 'Prior', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.fee] * 2', 'per_unit' => false ] ],
					] ],
			],
		] );

		$payload = WapfExporter::build_payload( $group );

		$this->assertSame( 'fx', $payload['fields'][1]['pricing']['type'] );
		$this->assertSame( '[field.plan] + [price.fee]', $payload['fields'][1]['pricing']['amount'] );
		$this->assertSame( 'fx', $payload['fields'][2]['choices'][0]['pricing_type'] );
		$this->assertSame( '([field.weight] + checked(plan)) * [qty]', $payload['fields'][2]['choices'][0]['pricing_amount'] );
		$this->assertSame( '[price.fee] * 2', $payload['fields'][2]['choices'][1]['pricing_amount'] );

		$wapf_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices', 'placeholder', 'default', 'p_content', 'minimum', 'maximum' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $wapf_fields ] );

		$this->assertFalse( $round_trip['needs_review'] );
		$weight = $round_trip['group']['fields'][1]['pricing'];
		$this->assertSame( 'formula', $weight['type'] );
		$this->assertSame( '[field.plan] + [price.fee]', $weight['formula'] );
		$this->assertFalse( $weight['per_unit'] );
		$custom = $round_trip['group']['fields'][2]['choices'][0]['pricing'];
		$this->assertSame( '([field.weight] + checked(plan)) * [qty]', $custom['formula_raw'] );
		$this->assertSame( '([field.weight] + checked(plan))', $custom['formula'] );
		$this->assertTrue( $custom['per_unit'] );
		$prior = $round_trip['group']['fields'][2]['choices'][1]['pricing'];
		$this->assertSame( '[price.fee] * 2', $prior['formula'] );
		$this->assertSame( 6.0, \OPF\Engine\Calculator::evaluate_formula( $weight['formula'], 100.0, 1, 0.0, '', null, [ 'plan' => '1' ], 0, [ 'fee' => 5.0 ] ) );
	}

	public function test_exported_formula_references_keep_importer_review_flags(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'plan', 'label' => 'Plan', 'type' => 'select',
					'choices' => [
						[ 'slug' => 'bulk', 'label' => 'Bulk', 'pricing' => [ 'type' => 'formula', 'formula' => 'sumQty(plan) + files(plan) + [price.fee]', 'per_unit' => false ] ],
					] ],
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
			],
		] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( 'sumQty(plan) + files(plan) + [price.fee]', $payload['fields'][0]['choices'][0]['pricing_amount'] );

		$wapf_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $wapf_fields ] );

		// sumQty() on a non-quantity target, files(), and forward [price.*]
		// references need runtime review; IDs resolve and the formula survives.
		$this->assertTrue( $round_trip['needs_review'] );
		$this->assertContains( 'choice "Bulk" formula contains references that require runtime review ([price.fee], sumqty(plan), files(plan)); pricing needs review.', $round_trip['notes'] );
		$pricing = $round_trip['group']['fields'][0]['choices'][0]['pricing'];
		$this->assertSame( 'formula', $pricing['type'] );
		$this->assertSame( 'sumQty(plan) + files(plan) + [price.fee]', $pricing['formula'] );
	}

	public function test_normalizes_formula_reference_case_to_exported_field_ids(): void {
		$group = FieldGroup::normalize( [
			'fields' => [
				[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ],
				[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number',
					'pricing' => [ 'type' => 'formula', 'formula' => '[FIELD.Plan] + [PRICE.Fee] + CHECKED(Plan)', 'per_unit' => false ] ],
				[ 'id' => 'plan', 'label' => 'Plan', 'type' => 'select',
					'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ] ],
			],
		] );

		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( '[field.plan] + [price.fee] + CHECKED(plan)', $payload['fields'][1]['pricing']['amount'] );

		$wapf_fields = array_map( static function ( array $field ): array {
			$field['options'] = array_intersect_key( $field, array_flip( [ 'choices' ] ) );
			return $field;
		}, $payload['fields'] );
		$round_trip = WapfMapper::map( [ 'fields' => $wapf_fields ] );
		$this->assertFalse( $round_trip['needs_review'] );
		$this->assertSame( '[field.plan] + [price.fee] + CHECKED(plan)', $round_trip['group']['fields'][1]['pricing']['formula'] );
	}

	public function test_rejects_formula_references_absent_from_export(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown field IDs: ghost' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'weight', 'label' => 'Weight', 'type' => 'number',
				'pricing' => [ 'type' => 'formula', 'formula' => '[field.weight] + sumQty(ghost)', 'per_unit' => false ] ],
		] ] ) );
	}

	public function test_rejects_choice_formula_references_absent_from_export(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot preserve formula references' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'plan', 'label' => 'Plan', 'type' => 'select',
				'choices' => [ [ 'slug' => 'a', 'label' => 'A', 'pricing' => [ 'type' => 'formula', 'formula' => '[price.missing] * 2', 'per_unit' => false ] ] ] ],
		] ] ) );
	}

	public function test_exports_upload_field_as_wapf_file(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'artwork', 'label' => 'Artwork', 'type' => 'upload', 'required' => true,
			'multiple' => true, 'max_size' => 4.5, 'accepted_types' => [ 'jpg', 'jpeg', 'pdf' ],
			'hide_order' => true,
		] ] ] ) );

		$field = $payload['fields'][0];
		$this->assertSame( 'file', $field['type'] );
		$this->assertTrue( $field['required'] );
		$this->assertTrue( $field['multiple'] );
		$this->assertSame( 4.5, $field['maxsize'] );
		$this->assertSame( 'jpg,jpeg,pdf', $field['accept'] );
		$this->assertSame( 'true', $field['hide_order'] );
		$this->assertFalse( $field['pricing']['enabled'] );

		// WAPF's importer folds flat keys back into `options`; reproduce that
		// and verify the OPF import path restores the same normalized field.
		$source_field = $field;
		$source_field['options'] = array_intersect_key( $field, array_flip( [ 'multiple', 'accept', 'maxsize', 'hide_order' ] ) );
		$round_trip = WapfMapper::map( [ 'fields' => [ $source_field ] ] );
		$this->assertFalse( $round_trip['needs_review'], implode( ' | ', $round_trip['notes'] ) );
		$imported = $round_trip['group']['fields'][0];
		$this->assertSame( 'upload', $imported['type'] );
		$this->assertTrue( $imported['multiple'] );
		$this->assertSame( 4.5, $imported['max_size'] );
		$this->assertSame( [ 'jpg', 'jpeg', 'pdf' ], $imported['accepted_types'] );
		$this->assertTrue( $imported['hide_order'] );
	}

	public function test_exports_manual_products_field_with_choices_and_card_layout(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'linked', 'label' => 'Linked products', 'type' => 'products', 'subtype' => 'card',
			'product_selection' => 'manual', 'qty_method' => 'parent',
			'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap', 'selected' => true, 'product_id' => 12, 'pricing_type' => 'fixed' ],
				[ 'slug' => 'extra', 'label' => '', 'disabled' => true, 'product_id' => 34, 'pricing_type' => 'none' ],
			],
			'items_per_row' => 3, 'items_per_row_tablet' => 2, 'items_per_row_mobile' => 1,
			'incl_img' => true, 'incl_desc' => false,
			'slot_1' => 'price', 'slot_2' => 'stock', 'slot_3' => 'link',
			'hide_cart' => true,
		] ] ] ) );

		$field = $payload['fields'][0];
		$this->assertSame( 'products', $field['type'] );
		$this->assertSame( 'card', $field['subtype'] );
		$this->assertSame( 'manual', $field['product_selection'] );
		$this->assertSame( 'parent', $field['qty_method'] );
		$this->assertSame( 3, $field['items_per_row'] );
		$this->assertTrue( $field['incl_img'] );
		$this->assertFalse( $field['incl_desc'] );
		$this->assertSame( [ 'price', 'stock', 'link' ], [ $field['slot_1'], $field['slot_2'], $field['slot_3'] ] );
		$this->assertSame( 'true', $field['hide_cart'] );
		$this->assertFalse( $field['pricing']['enabled'] );
		$this->assertCount( 2, $field['choices'] );
		$this->assertSame( 12, $field['choices'][0]['id'] );
		$this->assertSame( 'gift', $field['choices'][0]['slug'] );
		$this->assertSame( 'fixed', $field['choices'][0]['pricing_type'] );
		$this->assertSame( 'none', $field['choices'][1]['pricing_type'] );
		$this->assertTrue( $field['choices'][1]['disabled'] );

		$source_field = $field;
		$source_field['options'] = array_intersect_key( $field, array_flip( [
			'choices', 'product_selection', 'qty_method', 'items_per_row',
			'items_per_row_tablet', 'items_per_row_mobile', 'incl_img', 'incl_desc',
			'slot_1', 'slot_2', 'slot_3', 'hide_cart',
		] ) );
		$round_trip = WapfMapper::map( [ 'fields' => [ $source_field ] ] );
		$this->assertFalse( $round_trip['needs_review'], implode( ' | ', $round_trip['notes'] ) );
		$imported = $round_trip['group']['fields'][0];
		$this->assertSame( 'products', $imported['type'] );
		$this->assertSame( 'card', $imported['subtype'] );
		$this->assertSame( 'parent', $imported['qty_method'] );
		$this->assertSame( 3, $imported['items_per_row'] );
		$this->assertSame( [ 'price', 'stock', 'link' ], [ $imported['slot_1'], $imported['slot_2'], $imported['slot_3'] ] );
		$this->assertSame( [ 12, 34 ], array_column( $imported['choices'], 'product_id' ) );
		$this->assertTrue( $imported['hide_cart'] );
	}

	public function test_exports_category_products_field_and_qty_subtypes(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[
				'id' => 'related', 'label' => 'Related', 'type' => 'products', 'subtype' => 'vcard',
				'product_selection' => 'category',
				'product_query' => [ 'query_id' => 44, 'query_label' => 'Extras', 'limit' => 7, 'sort' => 'name_asc', 'pricing_type' => 'none' ],
				'img_fit' => 'contain',
			],
			[
				'id' => 'addons', 'label' => 'Addons', 'type' => 'products', 'subtype' => 'card-qty',
				'product_selection' => 'manual', 'display' => 'plus_min', 'min_choices' => 1, 'max_choices' => 9,
				'choices' => [
					[ 'slug' => 'a', 'label' => '', 'product_id' => 51, 'pricing_type' => 'fixed', 'quantity' => [ 'default' => 2, 'min' => 1, 'max' => 8 ] ],
				],
			],
		] ] ) );

		$category_field = $payload['fields'][0];
		$this->assertSame( 'category', $category_field['product_selection'] );
		$this->assertArrayNotHasKey( 'choices', $category_field );
		$this->assertSame( [ 'query_id' => 44, 'query_label' => 'Extras', 'limit' => 7, 'sort' => 'name_asc', 'pricing_type' => 'none' ], $category_field['product_query'] );
		$this->assertSame( 'contain', $category_field['img_fit'] );

		$qty_field = $payload['fields'][1];
		$this->assertSame( 'card-qty', $qty_field['subtype'] );
		$this->assertSame( 'plus_min', $qty_field['display'] );
		$this->assertSame( 1, $qty_field['min_choices'] );
		$this->assertSame( 9, $qty_field['max_choices'] );
		$this->assertArrayNotHasKey( 'qty_method', $qty_field );
		$this->assertSame( [ 'min' => 1, 'max' => 8, 'default' => 2 ], $qty_field['choices'][0]['options'] );

		foreach ( $payload['fields'] as $index => $field ) {
			$source_field = $field;
			$source_field['options'] = array_intersect_key( $field, array_flip( [
				'choices', 'product_selection', 'product_query', 'display', 'min_choices',
				'max_choices', 'items_per_row', 'items_per_row_tablet',
				'items_per_row_mobile', 'incl_img', 'incl_desc', 'slot_1', 'slot_2', 'slot_3', 'img_fit',
			] ) );
			$round_trip = WapfMapper::map( [ 'fields' => [ $source_field ] ] );
			$this->assertFalse( $round_trip['needs_review'], sprintf( 'field %d: %s', $index, implode( ' | ', $round_trip['notes'] ) ) );
		}
	}

	public function test_rejects_products_field_pricing_export(): void {
		// Products choices price themselves; generic OPF addon pricing on a
		// product choice has no WAPF meaning and must fail closed.
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'addon pricing on a product choice' );
		WapfExporter::build_payload( [
			'schema' => 1, 'rule_groups' => [], 'mark_required' => true, 'labels_position' => 'above',
			'fields' => [ [
				'id' => 'linked', 'label' => 'Linked', 'type' => 'products', 'subtype' => 'checkbox',
				'description' => '', 'required' => false, 'width' => 100, 'css_class' => '',
				'placeholder' => '', 'conditionals' => [],
				'pricing' => [ 'type' => 'none', 'amount' => 0, 'enabled' => false ],
				'product_selection' => 'manual',
				'choices' => [ [ 'slug' => 'a', 'label' => '', 'product_id' => 7, 'pricing_type' => 'fixed', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ] ],
			] ],
		] );
	}

	public function test_exports_variables_with_field_references(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'width', 'label' => 'Width', 'type' => 'number' ] ],
			'variables' => [ [
				'name' => 'fee', 'default' => '[field.width] * 2',
				'rules' => [
					[ 'type' => 'field', 'field' => 'width', 'condition' => '>', 'value' => '10', 'variable' => 'lookuptable(cutting;width;5)' ],
					[ 'type' => 'qty', 'field' => 'qty', 'condition' => '', 'value' => '', 'variable' => '9' ],
				],
			] ],
		] ) );

		$this->assertCount( 1, $payload['variables'] );
		$variable = $payload['variables'][0];
		$this->assertSame( 'fee', $variable['name'] );
		$this->assertSame( '[field.width] * 2', $variable['default'] );
		$this->assertSame( 'lookuptable(cutting;width;5)', $variable['rules'][0]['variable'] );
		$this->assertSame( 'qty', $variable['rules'][1]['type'] );

		$round_trip = WapfMapper::map( [ 'fields' => $payload['fields'], 'variables' => $payload['variables'] ] );
		$this->assertSame( 'fee', $round_trip['group']['variables'][0]['name'] );
		$this->assertSame( '[field.width] * 2', $round_trip['group']['variables'][0]['default'] );
	}

	public function test_rejects_variable_rules_referencing_absent_fields(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'rule reference' );
		WapfExporter::build_payload( FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'width', 'label' => 'Width', 'type' => 'number' ] ],
			'variables' => [ [
				'name' => 'fee', 'default' => '1',
				'rules' => [ [ 'type' => 'field', 'field' => 'ghost', 'condition' => '==', 'value' => 'x', 'variable' => '1' ] ],
			] ],
		] ) );
	}

	public function test_rejects_lookuptable_dimensions_referencing_absent_fields(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown field IDs: ghostfield' );
		WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'widthf', 'label' => 'Width', 'type' => 'number',
				'pricing' => [ 'type' => 'formula', 'formula' => 'lookuptable(cutting;widthf;ghostfield)', 'per_unit' => false ] ],
		] ] ) );
	}

	public function test_exports_products_image_subtype_with_label_pos_and_item_width(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [ [
			'id' => 'img', 'label' => 'Images', 'type' => 'products', 'subtype' => 'image',
			'product_selection' => 'manual',
			'choices' => [ [ 'slug' => 'a', 'label' => '', 'product_id' => 5, 'pricing_type' => 'fixed' ] ],
			'label_pos' => 'out', 'item_width' => 90,
		] ] ] ) );

		$field = $payload['fields'][0];
		$this->assertSame( 'products', $field['type'] );
		$this->assertSame( 'image', $field['subtype'] );
		$this->assertSame( 'out', $field['label_pos'] );
		$this->assertSame( 90, $field['item_width'] );
		$this->assertSame( 5, $field['choices'][0]['id'] );
	}

	public function test_exports_lookuptable_formula_with_canonical_field_ids(): void {
		$payload = WapfExporter::build_payload( FieldGroup::normalize( [ 'fields' => [
			[ 'id' => 'widthf', 'label' => 'Width', 'type' => 'number' ],
			[ 'id' => 'fee', 'label' => 'Fee', 'type' => 'text',
				'pricing' => [ 'type' => 'formula', 'formula' => 'lookuptable(cutting;WIDTHF;2)', 'per_unit' => false ] ],
		] ] ) );

		$this->assertSame( 'fx', $payload['fields'][1]['pricing']['type'] );
		$this->assertSame( 'lookuptable(cutting;widthf;2)', $payload['fields'][1]['pricing']['amount'] );
	}

	/**
	 * Feed an exported number field back through the mapper the way WAPF's
	 * Tools import stores it: the number options land under `options` and the
	 * default is sanitized inside `number_type`'s value space (`int` unless
	 * the type is exactly `any` — class-field-groups.php).
	 *
	 * @param array<string,mixed> $field Exported WAPF Tools field.
	 * @return array<string,mixed> Re-imported OPF field.
	 */
	private function round_trip_number_field( array $field ): array {
		$field['options'] = array_intersect_key( $field, array_flip( [ 'placeholder', 'default', 'minimum', 'maximum', 'number_type' ] ) );
		if ( isset( $field['options']['default'], $field['options']['number_type'] ) ) {
			$field['options']['default'] = 'any' === $field['options']['number_type']
				? floatval( $field['options']['default'] )
				: intval( $field['options']['default'] );
		}

		$mapped = WapfMapper::map( [ 'fields' => [ $field ] ] );
		$this->assertFalse( $mapped['needs_review'] );
		return $mapped['group']['fields'][0];
	}
}
