<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererStyledChoiceTest extends TestCase {
	public function test_opt_in_styling_applies_colors_without_replacing_native_controls(): void {
	$previous = $GLOBALS['opf_test_options'] ?? [];
		$GLOBALS['opf_test_options']['opf_styled_choice_controls'] = 'yes';
		$GLOBALS['opf_test_options']['opf_choice_accent'] = '#145a9e';
		$GLOBALS['opf_test_options']['opf_choice_border'] = '#333333';
		try {
			$group = new FieldGroup( [ 'fields' => [
				[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift wrap' ] ] ],
				[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'radio', 'choices' => [ [ 'slug' => 'blue', 'label' => 'Blue' ] ] ],
			] ] );
			ob_start();
			Renderer::render_group( '17', 'Options', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'opf-field-group--styled-choice-controls', $html );
			$this->assertStringContainsString( '--opf-choice-accent:#145a9e', $html );
			$this->assertStringContainsString( '--opf-choice-border:#333333', $html );
			$this->assertStringContainsString( 'type="checkbox"', $html );
			$this->assertStringContainsString( 'type="radio"', $html );
			$this->assertStringContainsString( 'Gift wrap', $html );
			$this->assertStringContainsString( 'Blue', $html );
		} finally {
			$GLOBALS['opf_test_options'] = $previous;
		}
	}

	public function test_default_setting_keeps_theme_native_control_rendering(): void {
		$previous = $GLOBALS['opf_test_options']['opf_styled_choice_controls'] ?? null;
		unset( $GLOBALS['opf_test_options']['opf_styled_choice_controls'] );
		try {
			$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift wrap' ] ] ] ] ] );
			ob_start();
			Renderer::render_group( '17', 'Options', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringNotContainsString( 'opf-field-group--styled-choice-controls', $html );
			$this->assertStringContainsString( 'type="checkbox"', $html );
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['opf_test_options']['opf_styled_choice_controls'] );
			} else {
				$GLOBALS['opf_test_options']['opf_styled_choice_controls'] = $previous;
			}
		}
	}
}
