<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use PHPUnit\Framework\TestCase;

final class DefaultsTest extends TestCase {
	public function test_scalar_and_checkbox_defaults_are_preserved(): void {
		$text = FieldGroup::normalize_field( [ 'id' => 'engraving', 'type' => 'text', 'default' => 'Ada' ] );
		$checks = FieldGroup::normalize_field( [ 'id' => 'extras', 'type' => 'checkbox', 'default' => [ 'gift', 'card' ] ] );
		$this->assertSame( 'Ada', $text['default'] );
		$this->assertSame( [ 'gift', 'card' ], $checks['default'] );
	}
}
