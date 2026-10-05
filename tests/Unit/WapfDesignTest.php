<?php
/**
 * WAPF `wapf_design_settings` design layer reproduction.
 */

namespace OPF\Tests\Unit;

use OPF\Engine\WapfDesign;
use PHPUnit\Framework\TestCase;

final class WapfDesignTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['opf_test_options']['wapf_design_settings'] );
		parent::tearDown();
	}

	public function test_empty_option_produces_no_css(): void {
		$this->assertSame( '', WapfDesign::css() );
	}

	public function test_styled_checkbox_and_radio_emit_variables_and_the_wapf_custom_skin(): void {
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [
			'apf-cb-display'           => 'styled',
			'apf-cb-radius'            => '4px',
			'apf-cb-border-width'      => '2px',
			'apf-cb-border-color'      => '#445566',
			'apf-cb-bg'                => '#eeeeee',
			'apf-cb-bg-sel'            => '#112233',
			'apf-cb-tick-color-sel'    => '#ffee00',
			'apf-radio-display'        => 'styled',
			'apf-radio-border-width'   => '2px',
			'apf-radio-border-color'   => '#556677',
			'apf-radio-bg-sel'         => '#223344',
		];

		$css = WapfDesign::css();
		$this->assertStringContainsString( ':root{', $css );
		$this->assertStringContainsString( '--apf-cb-bg:#eeeeee', $css );
		$this->assertStringContainsString( '--apf-cb-border:2px solid #445566', $css );
		$this->assertStringContainsString( '--apf-radio-border:2px solid #556677', $css );
		$this->assertStringContainsString( '.wapf-checkbox .wapf-custom{', $css );
		$this->assertStringContainsString( '.wapf-radio .wapf-custom{', $css );
		$this->assertStringContainsString( 'input[type=checkbox]:checked+.wapf-custom', $css );
		$this->assertStringContainsString( 'input[type=radio]:checked+.wapf-custom', $css );
		$this->assertStringContainsString( '%23ffee00', $css );
	}

	public function test_unstyled_controls_emit_variables_without_the_hidden_native_skin(): void {
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [
			'apf-cb-bg'  => '#eeeeee',
			'apf-radius' => '6px',
		];

		$css = WapfDesign::css();
		$this->assertStringContainsString( '--apf-radius:6px', $css );
		$this->assertStringNotContainsString( '.wapf-checkbox', $css );
		$this->assertStringNotContainsString( '.wapf-custom{', $css );
	}

	public function test_css_injection_in_a_stored_value_is_dropped(): void {
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [
			'apf-cb-display' => 'styled',
			'apf-cb-bg'      => '#fff} body{display:none}',
			'apf-cb-border-color' => '#445566',
		];

		$css = WapfDesign::css();
		$this->assertStringNotContainsString( 'body{', $css );
		$this->assertStringNotContainsString( 'display:none', $css );
	}

	public function test_setting_references_resolve_to_css_variables(): void {
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [
			'apf-input-border-color' => 'setting:apf-primary-color',
		];

		$this->assertStringContainsString( '--apf-input-border-color:var(--apf-primary-color)', WapfDesign::css() );
	}
}
