<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class ColourSwatchTest extends TestCase {
	public function test_hex_colour_metadata_is_normalized_and_invalid_values_are_omitted(): void {
		$field = FieldGroup::normalize_field( [ 'id' => 'finish', 'type' => 'swatch', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'color' => '#F00' ], [ 'slug' => 'bad', 'label' => 'Bad', 'color' => 'red' ] ] ] );
		$this->assertSame( '#f00', $field['choices'][0]['color'] );
		$this->assertArrayNotHasKey( 'color', $field['choices'][1] );
	}
}
