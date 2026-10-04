<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

/**
 * Schema normalization for the linked-products field type
 * (WAPF `products-*` parity).
 */
final class LinkedProductsSchemaTest extends TestCase {

	public function test_products_type_is_registered(): void {
		$this->assertContains( 'products', FieldGroup::FIELD_TYPES );
		$this->assertSame(
			[ 'checkbox', 'radio', 'dropdown', 'image', 'card', 'vcard', 'card-qty', 'vcard-qty' ],
			FieldGroup::PRODUCTS_SUBTYPES
		);
		$this->assertSame( [ 'fixed', 'none' ], FieldGroup::PRODUCTS_PRICING_TYPES );
	}

	public function test_wapf_style_type_spelling_normalizes_to_subtype(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'label' => 'Add-ons', 'type' => 'products-card-qty',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertSame( 'products', $field['type'] );
		$this->assertSame( 'card-qty', $field['subtype'] );
	}

	public function test_unknown_subtype_falls_back_to_checkbox(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'subtype' => 'bogus',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertSame( 'checkbox', $field['subtype'] );
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products-bogus',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertSame( 'products', $field['type'] );
		$this->assertSame( 'checkbox', $field['subtype'] );
	}

	public function test_manual_choices_require_a_product_id_and_get_p_slugs(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products',
			'choices' => [
				[ 'product_id' => 12 ],
				[ 'product_id' => '34', 'pricing_type' => 'none' ],
				[ 'product_id' => 'bogus' ],
				[ 'label' => 'No product' ],
				[ 'product_id' => 0 ],
			],
		] );
		$this->assertCount( 2, $field['choices'] );
		$this->assertSame( 12, $field['choices'][0]['product_id'] );
		$this->assertSame( 'p12', $field['choices'][0]['slug'] );
		$this->assertSame( 'fixed', $field['choices'][0]['pricing_type'] );
		$this->assertSame( 34, $field['choices'][1]['product_id'] );
		$this->assertSame( 'p34', $field['choices'][1]['slug'] );
		$this->assertSame( 'none', $field['choices'][1]['pricing_type'] );
	}

	public function test_products_field_never_carries_addon_pricing(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products',
			'pricing' => [ 'type' => 'fixed', 'amount' => 25 ],
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertSame( 'none', $field['pricing']['type'] );
	}

	public function test_qty_subtype_normalizes_bounds_display_and_aggregate_limits(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'subtype' => 'vcard-qty',
			'display' => 'plus_min', 'min_choices' => 2, 'max_choices' => 6,
			'choices' => [
				[ 'product_id' => 7, 'quantity' => [ 'default' => 3, 'min' => 1, 'max' => 9 ] ],
			],
		] );
		$this->assertSame( 'plus_min', $field['display'] );
		$this->assertSame( 2, $field['min_choices'] );
		$this->assertSame( 6, $field['max_choices'] );
		$this->assertSame( [ 'default' => 3, 'min' => 1, 'max' => 9 ], $field['choices'][0]['quantity'] );
	}

	public function test_qty_subtype_rejects_inverted_aggregate_limits(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'subtype' => 'card-qty',
			'min_choices' => 5, 'max_choices' => 2,
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
	}

	public function test_category_selection_normalizes_query_and_caps_at_50(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products',
			'product_selection' => 'category',
			'choices' => [ [ 'product_id' => 7 ] ],
			'product_query' => [ 'query_id' => 9, 'query_label' => 'Extras', 'limit' => 500, 'sort' => 'name_asc', 'pricing_type' => 'none' ],
		] );
		$this->assertSame( 'category', $field['product_selection'] );
		$this->assertSame( 50, $field['product_query']['limit'] );
		$this->assertSame( 'name_asc', $field['product_query']['sort'] );
		$this->assertSame( 'none', $field['product_query']['pricing_type'] );
		$this->assertSame( [], $field['choices'] );
	}

	public function test_category_query_rejects_unknown_sort_and_pricing(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products',
			'product_selection' => 'category',
			'product_query' => [ 'query_id' => 9, 'limit' => 5, 'sort' => 'random', 'pricing_type' => 'percent' ],
		] );
		$this->assertSame( 'date_desc', $field['product_query']['sort'] );
		$this->assertSame( 'fixed', $field['product_query']['pricing_type'] );
	}

	public function test_qty_method_normalizes_to_one_or_parent(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'qty_method' => 'parent',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertSame( 'parent', $field['qty_method'] );
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'qty_method' => 'bogus',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertSame( 'one', $field['qty_method'] );
	}

	public function test_hide_flags_and_image_zoom_normalize(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products',
			'hide_cart' => true, 'hide_checkout' => 1, 'hide_order' => false,
			'image_zoom' => '1',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertTrue( $field['hide_cart'] );
		$this->assertTrue( $field['hide_checkout'] );
		$this->assertFalse( $field['hide_order'] );
		$this->assertTrue( $field['image_zoom'] );
	}

	public function test_image_zoom_rejects_non_boolean(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'image_zoom' => 'yes',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
	}

	public function test_products_image_large_image_setting_is_separate_from_gallery_swap(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'subtype' => 'image',
			'large_image' => 'true', 'image_zoom' => false,
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertTrue( $field['large_image'] );
		$this->assertFalse( $field['image_zoom'] );

		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'subtype' => 'image',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertFalse( $field['large_image'], 'Hover zoom is off when no setting is stored.' );
	}

	public function test_products_image_large_image_rejects_invalid_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'subtype' => 'image', 'large_image' => 'yes',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
	}

	public function test_card_options_and_columns_normalize(): void {
		$field = FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'subtype' => 'vcard',
			'items_per_row' => 3, 'items_per_row_tablet' => 2, 'items_per_row_mobile' => 1,
			'incl_img' => false, 'incl_desc' => true,
			'slot_1' => 'price', 'slot_2' => 'bogus', 'slot_3' => 'stock',
			'img_fit' => 'contain',
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
		$this->assertSame( 3, $field['items_per_row'] );
		$this->assertSame( 2, $field['items_per_row_tablet'] );
		$this->assertSame( 1, $field['items_per_row_mobile'] );
		$this->assertFalse( $field['incl_img'] );
		$this->assertTrue( $field['incl_desc'] );
		$this->assertSame( 'price', $field['slot_1'] );
		$this->assertSame( 'none', $field['slot_2'] );
		$this->assertSame( 'stock', $field['slot_3'] );
		$this->assertSame( 'contain', $field['img_fit'] );
	}

	public function test_card_columns_reject_out_of_range_values(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products', 'subtype' => 'card',
			'items_per_row' => 99,
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
	}

	public function test_products_fields_cannot_repeat(): void {
		$this->expectException( \InvalidArgumentException::class );
		FieldGroup::normalize_field( [
			'id' => 'addons', 'type' => 'products',
			'repeat' => [ 'enabled' => true, 'mode' => 'button' ],
			'choices' => [ [ 'product_id' => 7 ] ],
		] );
	}

	public function test_products_fields_cannot_live_inside_repeated_sections(): void {
		$this->expectException( \InvalidArgumentException::class );
		new FieldGroup( [
			'schema' => FieldGroup::SCHEMA,
			'fields' => [
				[ 'id' => 'sec', 'type' => 'section', 'repeat' => [ 'enabled' => true, 'mode' => 'button' ] ],
				[ 'id' => 'addons', 'type' => 'products', 'choices' => [ [ 'product_id' => 7 ] ] ],
				[ 'id' => 'secend', 'type' => 'section_end' ],
			],
		] );
	}
}
