<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Service\WapfExporter;
use PHPUnit\Framework\TestCase;

/**
 * Cards + zoom cluster export: image-quantity `large_image` and the group
 * gallery-image (`layout`) block survive a WAPF Tools round-trip.
 */
final class CardsZoomExportTest extends TestCase {

	public function test_wapf_image_quantity_zoom_survives_import_and_export(): void {
		$mapped = WapfMapper::map( [ 'fields' => [ [
			'id' => 'prints', 'label' => 'Prints', 'type' => 'image-swatch-qty',
			'options' => [
				'large_image' => true,
				'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'attachment' => 12, 'options' => [ 'min' => 0, 'max' => 4, 'default' => 1 ] ] ],
			],
		] ] ] );

		$this->assertTrue( $mapped['group']['fields'][0]['image_zoom'] );
		$this->assertStringNotContainsString( 'does not preserve that zoom behavior', implode( ' ', $mapped['notes'] ) );
		$payload = WapfExporter::build_payload( FieldGroup::normalize( $mapped['group'] ) );
		$this->assertSame( 'image-swatch-qty', $payload['fields'][0]['type'] );
		$this->assertTrue( $payload['fields'][0]['large_image'] );
	}

	public function test_image_quantity_zoom_exports_as_large_image(): void {
		$group = FieldGroup::normalize( [
			'fields' => [ [
				'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity', 'large_image' => true,
				'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg', 'image_id' => 12, 'quantity' => [ 'min' => 0, 'max' => 4, 'default' => 1 ] ] ],
			] ],
		] );
		$payload = WapfExporter::build_payload( $group );
		$this->assertSame( 'image-swatch-qty', $payload['fields'][0]['type'] );
		$this->assertTrue( $payload['fields'][0]['large_image'] );
		$this->assertSame( 'oak', $payload['fields'][0]['choices'][0]['slug'] );
		$this->assertSame( 12, $payload['fields'][0]['choices'][0]['attachment'] );
	}

	public function test_image_quantity_without_zoom_exports_large_image_false(): void {
		$group = FieldGroup::normalize( [
			'fields' => [ [
				'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity',
				'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'min' => 0, 'max' => 4 ] ] ],
			] ],
		] );
		$payload = WapfExporter::build_payload( $group );
		$this->assertFalse( $payload['fields'][0]['large_image'] );
	}

	public function test_group_gallery_rules_export_in_layout_block(): void {
		$group = FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'finish', 'label' => 'Finish', 'type' => 'radio', 'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen' ] ] ] ],
			'layout' => [
				'enable_gallery_images' => true,
				'swap_type'             => 'last',
				'gallery_images'        => [ [
					'source' => 'upload',
					'url'    => 'https://example.test/full.jpg',
					'id'     => 77,
					'values' => [ [ 'field' => 'finish', 'value' => 'linen' ] ],
				] ],
			],
		] );
		$payload = WapfExporter::build_payload( $group );
		$this->assertTrue( $payload['layout']['enable_gallery_images'] );
		$this->assertSame( 'last', $payload['layout']['swap_type'] );
		$this->assertSame( '77', $payload['layout']['gallery_images'][0]['id'] );
		$this->assertSame( [ [ 'field' => 'finish', 'value' => 'linen' ] ], $payload['layout']['gallery_images'][0]['values'] );
	}

	public function test_group_without_gallery_layout_keeps_plain_layout(): void {
		$group = FieldGroup::normalize( [
			'fields' => [ [ 'id' => 'finish', 'label' => 'Finish', 'type' => 'radio', 'choices' => [ [ 'slug' => 'linen', 'label' => 'Linen' ] ] ] ],
		] );
		$payload = WapfExporter::build_payload( $group );
		$this->assertArrayNotHasKey( 'enable_gallery_images', $payload['layout'] );
	}
}
