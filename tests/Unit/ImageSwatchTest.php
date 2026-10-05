<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class ImageSwatchTest extends TestCase {
	public function test_image_metadata_allows_safe_urls_and_rejects_script_protocols(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'finish', 'type' => 'swatch', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'image' => 'https://example.test/red.png' ], [ 'slug' => 'bad', 'label' => 'Bad', 'image' => 'javascript:alert(1)' ] ] ] );
		$this->assertSame( 'https://example.test/red.png', $field['choices'][0]['image'] );
		$this->assertArrayNotHasKey( 'image', $field['choices'][1] );
	}
}
