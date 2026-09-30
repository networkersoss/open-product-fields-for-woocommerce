<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererParagraphTest extends TestCase {
	public function test_paragraph_renders_escaped_static_content_without_a_control(): void {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'delivery-message', 'type' => 'paragraph', 'content' => '<strong>Delivery:</strong> 2 days<script>alert(1)</script>' ] ] ] );
		ob_start();
		Renderer::render_group( '17', 'Messages', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '&lt;strong&gt;Delivery:&lt;/strong&gt; 2 days&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( 'name="opf[17][delivery-message]"', $html );
		$this->assertStringNotContainsString( 'data-opf-price', $html );
		$this->assertStringNotContainsString( '<label', $html );
	}

	public function test_tooltip_instruction_remains_available_through_an_accessible_trigger(): void {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'engraving', 'label' => 'Engraving', 'description' => 'Use up to 12 characters.', 'description_presentation' => 'tooltip', 'type' => 'text' ] ] ] );
		ob_start();
		Renderer::render_group( '17', 'Instructions', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aria-describedby="opf-17-engraving-instruction"', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertStringContainsString( 'role="tooltip" id="opf-17-engraving-instruction"', $html );
		$this->assertStringContainsString( 'Use up to 12 characters.', $html );
		$this->assertStringNotContainsString( 'class="opf-field-description">Use up to 12 characters.', $html );
	}

	public function test_multi_swatch_renders_checkbox_controls_with_array_names(): void {
		$group = new FieldGroup(
			[
				'fields' => [
					[ 'id' => 'colors', 'label' => 'Colors', 'type' => 'swatch', 'multiple' => true, 'min_selections' => 1, 'max_selections' => 2, 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ] ] ],
				],
			]
		);
		ob_start();
		Renderer::render_group( '17', 'Colors', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'name="opf[17][colors][]"', $html );
		$this->assertStringContainsString( 'data-opf-min-selections="1"', $html );
		$this->assertStringContainsString( 'data-opf-max-selections="2"', $html );
		$this->assertStringNotContainsString( 'opf-single-select', $html );
	}

	public function test_priced_fields_and_choices_render_live_hint_targets(): void {
		$group = new FieldGroup( [
			'fields' => [
				[ 'id' => 'engraving', 'label' => 'Engraving', 'type' => 'text', 'pricing' => [ 'type' => 'formula', 'formula' => '5 * [qty]' ] ],
				[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'select', 'choices' => [ [ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 4 ] ] ] ],
				[ 'id' => 'surcharge', 'label' => 'Surcharge', 'type' => 'calculation', 'calculation_type' => 'price', 'formula' => '3 * [qty]' ],
			],
		] );
		ob_start();
		Renderer::render_group( '17', 'Hints', $group, 100.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-opf-field-hint="1"', $html );
		$this->assertStringContainsString( 'data-opf-choice-hint="gold"', $html );
		$this->assertStringContainsString( 'data-opf-base-label="Gold"', $html );
		$this->assertSame( 3, substr_count( $html, 'data-opf-field-hint="1"' ) + substr_count( $html, 'data-opf-choice-hint="gold"' ) );
	}

	public function test_card_radio_renders_layout_image_safe_description_pricing_and_accessible_group(): void {
		$group = new FieldGroup( [
			'fields' => [
				[
					'id' => 'finish', 'label' => 'Choose a finish', 'type' => 'radio', 'card_layout' => 'horizontal',
					'choices' => [
						[ 'slug' => 'linen', 'label' => 'Linen', 'image' => '/uploads/linen.jpg', 'description' => '<script>bad()</script>Soft woven finish', 'pricing' => [ 'type' => 'fixed', 'amount' => 4 ] ],
				],
				],
			],
		] );
		ob_start();
		Renderer::render_group( '17', 'Finishes', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'opf-cards opf-cards--horizontal', $html );
		$this->assertStringContainsString( 'role="radiogroup"', $html );
		$this->assertStringContainsString( 'aria-labelledby="opf-17-finish-label"', $html );
		$this->assertStringContainsString( 'src="/uploads/linen.jpg"', $html );
		$this->assertStringContainsString( 'Soft woven finish', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'data-opf-price="4"', $html );
	}
}
