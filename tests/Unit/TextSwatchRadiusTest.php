<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Admin\Settings;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * WAPF Extended 3.1.5 keeps the text-swatch corner radius in one design
 * setting — `apf-ts-radius` (`includes/classes/class-design-helper.php:521`,
 * type `unit`, min 0, max 50, start_with 4px) — emitted as `--apf-ts-radius`
 * (`class-design-helper.php:1514`) and applied by the themed stylesheet as
 * `border-radius: var(--apf-ts-radius, 4px)` on `.wapf-swatch--text`
 * (`assets/css/frontend-themed.min.css`). Its field options carry no radius
 * key (`includes/classes/class-config.php:1437-1445`). OPF exposes the same
 * control as a WooCommerce product-fields setting.
 */
final class TextSwatchRadiusTest extends TestCase {
	/** @var array<string,mixed> */
	private array $options;

	protected function setUp(): void {
		$this->options = $GLOBALS['opf_test_options'] ?? [];
	}

	protected function tearDown(): void {
		$GLOBALS['opf_test_options'] = $this->options;
	}

	private function set_radius_option( $value ): void {
		if ( null === $value ) {
			unset( $GLOBALS['opf_test_options']['opf_text_swatch_radius'] );
			return;
		}
		$GLOBALS['opf_test_options']['opf_text_swatch_radius'] = $value;
	}

	private function render( array $field ): string {
		$group = new FieldGroup( [ 'fields' => [ array_merge( [ 'id' => 'finish', 'label' => 'Finish', 'type' => 'swatch' ], $field ) ] ] );
		ob_start();
		Renderer::render_group( '17', 'Options', $group, 10.0 );
		return (string) ob_get_clean();
	}

	public function test_product_fields_settings_expose_the_text_swatch_corner_radius(): void {
		$settings = Settings::product_fields_settings( [], 'opf_product_fields' );
		$radius = array_values( array_filter( $settings, static fn( array $setting ): bool => 'opf_text_swatch_radius' === ( $setting['id'] ?? '' ) ) );

		$this->assertCount( 1, $radius );
		$this->assertSame( 'number', $radius[0]['type'] );
		$this->assertSame( Settings::TEXT_SWATCH_RADIUS_DEFAULT, $radius[0]['default'] );
		$this->assertSame( 0, $radius[0]['custom_attributes']['min'] );
		$this->assertSame( Settings::TEXT_SWATCH_RADIUS_MAX, $radius[0]['custom_attributes']['max'] );
	}

	public function test_radius_normalization_clamps_to_the_wapf_bounds(): void {
		$this->assertSame( 18, Settings::sanitize_text_swatch_radius( '18' ) );
		$this->assertSame( 19, Settings::sanitize_text_swatch_radius( '18.6' ) );
		$this->assertSame( 0, Settings::sanitize_text_swatch_radius( '-4' ) );
		$this->assertSame( 50, Settings::sanitize_text_swatch_radius( '80' ) );

		$this->set_radius_option( '12' );
		$this->assertSame( 12, Settings::sanitize_text_swatch_radius( 'not-a-number' ), 'A non-numeric post keeps the last valid radius.' );
		$this->set_radius_option( null );
		$this->assertSame( Settings::TEXT_SWATCH_RADIUS_DEFAULT, Settings::sanitize_text_swatch_radius( 'not-a-number' ) );
	}

	public function test_configured_radius_is_read_back_and_clamped(): void {
		$this->set_radius_option( null );
		$this->assertNull( Settings::text_swatch_radius() );
		$this->set_radius_option( '24' );
		$this->assertSame( 24, Settings::text_swatch_radius() );
		$this->set_radius_option( '900' );
		$this->assertSame( 50, Settings::text_swatch_radius() );
		$this->set_radius_option( 'broken' );
		$this->assertNull( Settings::text_swatch_radius() );
	}

	public function test_text_swatch_field_renders_the_configured_corner_radius(): void {
		$this->set_radius_option( '18' );
		$html = $this->render( [
			'swatch_style' => 'text',
			'choices' => [ [ 'slug' => 'matte', 'label' => 'Matte' ], [ 'slug' => 'gloss', 'label' => 'Gloss' ] ],
		] );

		$this->assertStringContainsString( 'class="opf-swatch-wrapper opf-text-swatch-wrapper"', $html );
		$this->assertStringContainsString( 'style="--opf-text-swatch-radius:18px"', $html );
		$this->assertStringContainsString( 'opf-swatch opf-swatch--text opf-single-select', $html );
	}

	public function test_unconfigured_radius_leaves_the_imported_wapf_variable_in_charge(): void {
		$this->set_radius_option( null );
		$html = $this->render( [
			'swatch_style' => 'text',
			'choices' => [ [ 'slug' => 'matte', 'label' => 'Matte' ] ],
		] );

		$this->assertStringContainsString( 'opf-text-swatch-wrapper', $html );
		$this->assertStringNotContainsString( '--opf-text-swatch-radius', $html, 'The CSS falls back to the migrated --apf-ts-radius value.' );
	}

	public function test_other_choice_styles_keep_the_plain_wrapper(): void {
		$this->set_radius_option( '18' );

		$image = $this->render( [
			'swatch_style' => 'image',
			'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'image' => 'https://example.test/oak.jpg' ] ],
		] );
		$this->assertStringContainsString( 'opf-swatch-wrapper opf-image-swatch-wrapper', $image );
		$this->assertStringNotContainsString( 'opf-text-swatch-wrapper', $image );
		$this->assertStringNotContainsString( '--opf-text-swatch-radius', $image );

		$color = $this->render( [
			'swatch_style' => 'color',
			'choices' => [ [ 'slug' => 'red', 'label' => 'Red', 'color' => '#F00' ] ],
		] );
		$this->assertStringNotContainsString( 'opf-text-swatch-wrapper', $color );
		$this->assertStringNotContainsString( '--opf-text-swatch-radius', $color );

		$checkbox = $this->render( [ 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'gift', 'label' => 'Gift wrap' ] ] ] );
		$this->assertStringContainsString( 'class="opf-swatch-wrapper wapf-checkboxes"', $checkbox );
		$this->assertStringNotContainsString( 'opf-text-swatch-wrapper', $checkbox, 'Plain checkbox choices are not text swatches in WAPF.' );
		$this->assertStringNotContainsString( '--opf-text-swatch-radius', $checkbox );
	}
}
