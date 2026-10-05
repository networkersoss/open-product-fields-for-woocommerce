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

	public function test_plain_checkbox_and_radio_render_the_wapf_custom_skin_markup(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift wrap' ] ] ],
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'radio', 'choices' => [ [ 'slug' => 'blue', 'label' => 'Blue' ] ] ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Options', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'wapf-checkboxes', $html );
		$this->assertStringContainsString( 'wapf-radios', $html );
		$this->assertStringContainsString( 'opf-swatch--text wapf-checkbox"', $html );
		$this->assertStringContainsString( 'opf-swatch--text wapf-radio opf-single-select"', $html );
		$this->assertStringContainsString( 'class="wapf-input-label"', $html );
		$this->assertStringContainsString( 'class="wapf-label-text"', $html );
		$this->assertStringContainsString( '<span class="wapf-custom" aria-hidden="true"></span>', $html );
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'type="radio"', $html );
	}

	public function test_switch_and_card_choices_keep_their_markup_without_the_wapf_skin(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'sw', 'label' => 'Switch', 'type' => 'checkbox', 'switch_control' => true, 'choices' => [ [ 'slug' => 'on', 'label' => 'On' ] ] ],
			[ 'id' => 'card', 'label' => 'Card', 'type' => 'radio', 'card_layout' => 'horizontal', 'choices' => [ [ 'slug' => 'a', 'label' => 'A' ] ] ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Options', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'wapf-custom', $html );
		$this->assertStringNotContainsString( 'wapf-checkbox', $html );
		$this->assertStringNotContainsString( 'wapf-radio', $html );
	}

	public function test_migrated_wapf_design_settings_emit_the_root_variables_and_skin(): void {
		$previous = $GLOBALS['opf_test_options'] ?? [];
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [
			'apf-cb-display'      => 'styled',
			'apf-cb-radius'       => '4px',
			'apf-cb-border-width' => '2px',
			'apf-cb-border-color' => '#445566',
			'apf-cb-bg'           => '#eeeeee',
		];
		try {
			$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift wrap' ] ] ] ] ] );
			ob_start();
			Renderer::render_group( '17', 'Options', $group, 10.0 );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'id="opf-wapf-design-css"', $html );
			$this->assertStringContainsString( '--apf-cb-bg:#eeeeee', $html );
			$this->assertStringContainsString( '.wapf-checkbox .wapf-custom{', $html );
			$this->assertStringContainsString( '--apf-cb-border:2px solid #445566', $html );
		} finally {
			$GLOBALS['opf_test_options'] = $previous;
		}
	}
}
