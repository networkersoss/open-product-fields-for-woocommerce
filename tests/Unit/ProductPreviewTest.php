<?php

namespace OPF\Tests\Unit;

use OPF\Engine\ProductPreview;
use PHPUnit\Framework\TestCase;

final class ProductPreviewTest extends TestCase {
	public function test_normalizes_text_overlay_with_gallery_target_and_responsive_style(): void {
		$preview = ProductPreview::normalize( [
			[
				'id' => 'engraving', 'group_id' => 17, 'field_id' => 'engraving_text', 'source' => 'text',
				'target' => 'gallery', 'image_id' => 42,
				'box' => [ 'x' => 12.5, 'y' => 20, 'width' => 60, 'height' => 24 ],
				'text' => [ 'color' => '#ffffff', 'font_family' => 'Montserrat', 'font_size' => 32, 'mobile_font_size' => 20, 'font_weight' => 700, 'font_style' => 'italic', 'alignment' => 'center', 'mobile_alignment' => 'left' ],
				'dynamic' => [
					'color' => [ 'field_id' => 'ink', 'values' => [ 'red' => '#ff0000' ], 'default' => '#ffffff' ],
					'font_family' => [ 'field_id' => 'font', 'values' => [ 'serif' => 'Georgia, serif' ], 'default' => 'Arial, sans-serif' ],
					'font_size' => [ 'field_id' => 'size', 'values' => [ 'large' => 48 ], 'default' => 18 ],
					'alignment' => [ 'field_id' => 'align', 'values' => [ 'left' => 'left' ], 'default' => 'center' ],
				],
			],
		] );

		$this->assertCount( 1, $preview );
		$this->assertSame( 'gallery', $preview[0]['target'] );
		$this->assertSame( 42, $preview[0]['image_id'] );
		$this->assertSame( [ 'x' => 12.5, 'y' => 20.0, 'width' => 60.0, 'height' => 24.0 ], $preview[0]['box'] );
		$this->assertSame( 20, $preview[0]['text']['mobile_font_size'] );
		$this->assertSame( '#ff0000', $preview[0]['dynamic']['color']['values']['red'] );
		$this->assertSame( 'Georgia, serif', $preview[0]['dynamic']['font_family']['values']['serif'] );
		$this->assertSame( 48, $preview[0]['dynamic']['font_size']['values']['large'] );
		$this->assertSame( 'left', $preview[0]['dynamic']['alignment']['values']['left'] );
	}

	public function test_normalizes_upload_shape_and_aspect_preserving_fit(): void {
		$preview = ProductPreview::normalize( [
			[
				'id' => 'artwork', 'group_id' => 17, 'field_id' => 'logo', 'source' => 'upload',
				'target' => 'main', 'box' => [ 'x' => 5, 'y' => 8, 'width' => 40, 'height' => 30 ],
				'image' => [ 'shape' => 'oval', 'fit' => 'contain' ],
			],
		] );

		$this->assertSame( 'upload', $preview[0]['source'] );
		$this->assertSame( [ 'shape' => 'oval', 'fit' => 'contain' ], $preview[0]['image'] );
	}

	public function test_rejects_out_of_bounds_and_invalid_identifiers(): void {
		$this->assertSame( [], ProductPreview::normalize( [
			[ 'id' => 'bad', 'group_id' => 0, 'field_id' => 'bad id', 'source' => 'text', 'target' => 'main', 'box' => [ 'x' => 90, 'y' => 0, 'width' => 20, 'height' => 20 ] ],
		] ) );
	}

	public function test_caps_preview_items_and_rejects_invalid_dynamic_styles(): void {
		$items = [];
		for ( $i = 0; $i < 22; $i++ ) {
			$items[] = [ 'id' => 'preview-' . $i, 'group_id' => 1, 'field_id' => 'name', 'source' => 'text', 'target' => 'main', 'box' => [ 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100 ] ];
		}
		$normalized = ProductPreview::normalize( $items );
		$this->assertCount( 20, $normalized );
		$this->assertSame( [], ProductPreview::normalize( [
			[ 'id' => 'bad-style', 'group_id' => 1, 'field_id' => 'name', 'source' => 'text', 'target' => 'main', 'box' => [ 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100 ], 'dynamic' => [ 'url' => [ 'field_id' => 'name', 'values' => [ '*' => 'javascript:alert(1)' ] ] ] ],
		] )[0]['dynamic'] );
	}
}
