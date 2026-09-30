<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererSwitchControlTest extends TestCase {
	public function test_true_false_switch_keeps_native_checkbox_value_and_exposes_switch_role(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'gift-wrap', 'label' => 'Gift wrap', 'type' => 'toggle', 'switch_control' => true ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Options', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'opf-field--switch-control', $html );
		$this->assertStringContainsString( 'type="checkbox" value="1"', $html );
		$this->assertStringContainsString( 'role="switch"', $html );
	}

	public function test_checkbox_choices_can_render_as_independent_switches(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'switch_control' => true, 'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap' ],
				[ 'slug' => 'note', 'label' => 'Gift note' ],
			] ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Options', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'opf-checkboxes--switches', $html );
		$this->assertSame( 2, substr_count( $html, 'role="switch"' ) );
		$this->assertSame( 2, substr_count( $html, 'type="checkbox"' ) );
	}
}
