<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererNumberStepperTest extends TestCase {
	public function test_global_setting_wraps_native_number_input_with_labeled_step_buttons(): void {
		$previous = $GLOBALS['opf_test_options']['opf_number_buttons'] ?? null;
		$GLOBALS['opf_test_options']['opf_number_buttons'] = 'yes';
		try {
			$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'min' => 0, 'max' => 5, 'step' => 0.25 ] ] ] );
			ob_start();
			Renderer::render_group( '17', 'Options', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'opf-number-stepper', $html );
			$this->assertStringContainsString( 'data-opf-number-step="down"', $html );
			$this->assertStringContainsString( 'aria-label="Decrease Quantity"', $html );
			$this->assertStringContainsString( 'data-opf-number-step="up"', $html );
			$this->assertStringContainsString( 'aria-label="Increase Quantity"', $html );
			$this->assertStringContainsString( 'type="number"', $html );
			$this->assertStringContainsString( 'step="0.25"', $html );
			$this->assertStringContainsString( 'min="0"', $html );
			$this->assertStringContainsString( 'max="5"', $html );
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['opf_test_options']['opf_number_buttons'] );
			} else {
				$GLOBALS['opf_test_options']['opf_number_buttons'] = $previous;
			}
		}
	}

	public function test_native_number_markup_is_unchanged_when_global_setting_is_off(): void {
		$previous = $GLOBALS['opf_test_options']['opf_number_buttons'] ?? null;
		$GLOBALS['opf_test_options']['opf_number_buttons'] = 'no';
		try {
			$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'quantity', 'label' => 'Quantity', 'type' => 'number' ] ] ] );
			ob_start();
			Renderer::render_group( '17', 'Options', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringNotContainsString( 'opf-number-stepper', $html );
			$this->assertStringContainsString( '<input type="number"', $html );
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['opf_test_options']['opf_number_buttons'] );
			} else {
				$GLOBALS['opf_test_options']['opf_number_buttons'] = $previous;
			}
		}
	}
}
