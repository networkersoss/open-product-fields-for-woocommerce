<?php

namespace OPF\Tests\Unit;

use OPF\Engine\LayeredImageConfig;
use PHPUnit\Framework\TestCase;

final class LayeredImageConfigTest extends TestCase {
	public function test_normalizes_gallery_target_delay_and_choice_layers(): void {
		$config = LayeredImageConfig::normalize( [
			'enabled' => true,
			'target' => 'gallery',
			'target_image_id' => 23,
			'base_image_id' => 41,
			'delay' => true,
			'auto_scroll' => true,
			'layers' => [
				[ 'group_id' => 8, 'field_id' => 'color', 'choice' => 'blue', 'image_id' => 52 ],
				[ 'group_id' => '8', 'field_id' => 'trim', 'choice' => 'gold', 'image_id' => '53' ],
			],
		] );

		$this->assertTrue( $config['enabled'] );
		$this->assertSame( 'gallery', $config['target'] );
		$this->assertSame( 23, $config['target_image_id'] );
		$this->assertSame( 41, $config['base_image_id'] );
		$this->assertTrue( $config['delay'] );
		$this->assertTrue( $config['auto_scroll'] );
		$this->assertSame( [
			[ 'group_id' => 8, 'field_id' => 'color', 'choice' => 'blue', 'image_id' => 52 ],
			[ 'group_id' => 8, 'field_id' => 'trim', 'choice' => 'gold', 'image_id' => 53 ],
		], $config['layers'] );
	}

	public function test_rejects_invalid_rows_and_requires_base_and_gallery_identity(): void {
		$this->assertFalse( LayeredImageConfig::normalize( [ 'enabled' => true, 'base_image_id' => 0 ] )['enabled'] );
		$config = LayeredImageConfig::normalize( [
			'enabled' => true,
			'target' => 'gallery',
			'target_image_id' => 23,
			'base_image_id' => 41,
			'layers' => [
				[ 'group_id' => 8, 'field_id' => 'color', 'choice' => 'blue', 'image_id' => 52 ],
				[ 'group_id' => 8, 'field_id' => 'color', 'choice' => 'blue', 'image_id' => 53 ],
				[ 'group_id' => -1, 'field_id' => 'color', 'choice' => 'red', 'image_id' => 54 ],
			],
		] );
		$this->assertTrue( $config['enabled'] );
		$this->assertCount( 1, $config['layers'] );
		$this->assertSame( 'main', LayeredImageConfig::normalize( [ 'target' => 'invalid' ] )['target'] );
	}
}
