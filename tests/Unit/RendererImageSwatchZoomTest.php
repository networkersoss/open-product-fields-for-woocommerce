<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererImageSwatchZoomTest extends TestCase {
	public function test_enabled_image_swatch_zoom_is_scoped_to_each_image_choice(): void {
		$group = new FieldGroup( [ 'fields' => [
			[
				'id' => 'fabric', 'label' => 'Fabric', 'type' => 'swatch', 'image_zoom' => true,
				'choices' => [
					[ 'slug' => 'linen', 'label' => 'Linen', 'image' => '/linen.jpg' ],
					[ 'slug' => 'cotton', 'label' => 'Cotton' ],
				],
			],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Fabric', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="opf-swatch opf-swatch--text opf-single-select opf-swatch--image-zoom"', $html );
		$this->assertStringContainsString( 'src="/linen.jpg"', $html );
		$this->assertStringContainsString( 'class="opf-swatch opf-swatch--text opf-single-select"', $html );
	}

	public function test_enabled_image_quantity_zoom_is_scoped_to_each_image_choice(): void {
		$group = new FieldGroup( [ 'fields' => [
			[
				'id' => 'prints', 'label' => 'Prints', 'type' => 'swatch', 'image_quantities' => true, 'image_quantity_zoom' => true,
				'choices' => [ [ 'slug' => 'small', 'label' => 'Small', 'image' => '/small.jpg' ] ],
			],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Prints', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="opf-image-quantity-choice opf-image-quantity--zoom"', $html );
		$this->assertStringContainsString( 'class="opf-swatch-image" src="/small.jpg"', $html );
		$this->assertStringContainsString( 'type="number"', $html );
	}
}
