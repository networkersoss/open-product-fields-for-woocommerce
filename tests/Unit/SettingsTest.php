<?php

namespace OPF\Tests\Unit;

use OPF\Service\Admin\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {
	public function test_product_fields_settings_expose_global_number_stepper_toggle_with_safe_default(): void {
		$settings = Settings::product_fields_settings( [], 'opf_product_fields' );
		$number_buttons = array_values( array_filter( $settings, static fn( array $setting ): bool => 'opf_number_buttons' === ( $setting['id'] ?? '' ) ) );

		$this->assertCount( 1, $number_buttons );
		$this->assertSame( 'checkbox', $number_buttons[0]['type'] );
		$this->assertSame( 'no', $number_buttons[0]['default'] );
		$styled_controls = array_values( array_filter( $settings, static fn( array $setting ): bool => 'opf_styled_choice_controls' === ( $setting['id'] ?? '' ) ) );
		$this->assertCount( 1, $styled_controls );
		$this->assertSame( 'no', $styled_controls[0]['default'] );
		$accent = array_values( array_filter( $settings, static fn( array $setting ): bool => 'opf_choice_accent' === ( $setting['id'] ?? '' ) ) );
		$border = array_values( array_filter( $settings, static fn( array $setting ): bool => 'opf_choice_border' === ( $setting['id'] ?? '' ) ) );
		$this->assertSame( 'color', $accent[0]['type'] );
		$this->assertSame( 'color', $border[0]['type'] );
		$edit_cart = array_values( array_filter( $settings, static fn( array $setting ): bool => 'opf_edit_cart' === ( $setting['id'] ?? '' ) ) );
		$this->assertCount( 1, $edit_cart );
		$this->assertSame( 'checkbox', $edit_cart[0]['type'] );
		$this->assertSame( 'no', $edit_cart[0]['default'] );
		$this->assertArrayHasKey( 'opf_live_preview', Settings::add_product_fields_section( [] ) );
		$font_settings = Settings::product_fields_settings( [], 'opf_live_preview' );
		$this->assertSame( 'opf_preview_font_manager', $font_settings[1]['type'] );
		$this->assertCount( 0, array_filter( $settings, static fn( array $setting ): bool => in_array( $setting['id'] ?? '', [ 'opf_simple_price_display', 'opf_simple_price_label' ], true ) ) );
	}

	public function test_product_fields_settings_expose_three_price_summary_modes(): void {
		$settings = Settings::product_fields_settings( [], 'opf_product_fields' );
		$summary = array_values( array_filter( $settings, static fn( array $setting ): bool => 'opf_price_summary_mode' === ( $setting['id'] ?? '' ) ) );

		$this->assertCount( 1, $summary );
		$this->assertSame( 'select', $summary[0]['type'] );
		$this->assertSame( 'three_line', $summary[0]['default'] );
		$this->assertSame( [ 'three_line' => 'Show 3-line summary', 'grand_total' => 'Show only grand total', 'hidden' => 'Hide summary' ], $summary[0]['options'] );
	}
}
