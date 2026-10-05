<?php
/** Child-product setting normalization tests. */

namespace OPF\Tests\Unit;

use OPF\Engine\ChildProductConfig;
use PHPUnit\Framework\TestCase;

final class ChildProductConfigTest extends TestCase {
	public function test_normalizes_specific_and_category_sources_and_selection_modes(): void {
		$config = ChildProductConfig::normalize( [
			'product_source' => 'categories',
			'product_ids' => [ 14, '14', 0, -2, 'invalid', 27 ],
			'category_ids' => [ 3, '3', 0, 'invalid', 8 ],
			'product_display' => 'cards',
			'multiple' => false,
			'quantity_input' => true,
			'image_zoom' => false,
			'quantity_mode' => 'multiply_parent',
			'min_selections' => 1,
			'max_selections' => 4,
		] );

		$this->assertSame( [
			'product_source' => 'categories',
			'product_ids' => [ 14, 27 ],
			'category_ids' => [ 3, 8 ],
			'product_display' => 'cards',
			'multiple' => false,
			'quantity_input' => true,
			'image_zoom' => false,
			'quantity_mode' => 'multiply_parent',
			'min_selections' => 1,
			'max_selections' => 4,
		], $config );
	}

	public function test_invalid_config_uses_safe_specific_checkbox_defaults(): void {
		$this->assertSame( [
			'product_source' => 'specific',
			'product_ids' => [],
			'category_ids' => [],
			'product_display' => 'checkboxes',
			'multiple' => true,
			'quantity_input' => false,
			'image_zoom' => false,
			'quantity_mode' => 'per_parent',
		], ChildProductConfig::normalize( [
			'product_source' => 'unknown',
			'product_display' => 'marquee',
			'quantity_input' => 'false',
			'quantity_mode' => 'unbounded',
		] ) );
	}

	public function test_contradictory_selection_bounds_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		ChildProductConfig::normalize( [ 'min_selections' => 3, 'max_selections' => 2 ] );
	}

	public function test_image_display_defaults_to_multiple_and_can_enable_zoom(): void {
		$this->assertSame( true, ChildProductConfig::normalize( [ 'product_display' => 'images', 'image_zoom' => true ] )['multiple'] );
		$this->assertSame( true, ChildProductConfig::normalize( [ 'product_display' => 'images', 'image_zoom' => true ] )['image_zoom'] );
	}
}
