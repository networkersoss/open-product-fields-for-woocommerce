<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererNumberModeTest extends TestCase {
	public function test_number_input_hints_respect_mode_and_explicit_step(): void {
		$this->assertStringContainsString( 'step="1"', $this->render( [ 'number_mode' => 'integer' ] ) );
		$this->assertStringContainsString( 'step="any"', $this->render( [ 'number_mode' => 'decimal' ] ) );
		$this->assertStringContainsString( 'step="0.25"', $this->render( [ 'number_mode' => 'decimal', 'step' => 0.25 ] ) );
	}

	private function render( array $overrides ): string {
		$field = array_merge( [ 'id' => 'amount', 'label' => 'Amount', 'type' => 'number' ], $overrides );
		$group = new FieldGroup( [ 'fields' => [ $field ] ] );
		ob_start();
		Renderer::render_group( '18', 'Number group', $group, 10.0 );
		return (string) ob_get_clean();
	}
}
