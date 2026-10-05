<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * Coexistence contract for the choice-grid wrapper.
 *
 * WAPF Extended 3.1.5 ships
 * `.wapf-checkboxes,.wapf-radios{display:inline-grid;grid-template-columns:auto}`
 * (`assets/css/frontend.min.css`) and OPF reuses those shared wrapper classes
 * for WAPF theme compatibility. A bare `.opf-checkboxes--columns` rule ties on
 * specificity (0,1,0) with WAPF's one-class rule and therefore loses on
 * stylesheet order — WAPF's stylesheet loads after `opf-frontend.css` — which
 * collapsed OPF's configured columns to a single column. OPF's rule must be
 * scoped with `.opf-swatch-wrapper` (specificity 0,2,0) so it wins while only
 * matching OPF-rendered fields. WAPF's own markup never carries `opf-*`
 * classes, so its rules keep applying unchanged.
 */
final class FrontendChoiceGridCssTest extends TestCase {
	private function css(): string {
		return (string) file_get_contents( OPF_DIR . 'assets/css/opf-frontend.css' );
	}

	public function test_checkbox_column_rule_outranks_the_shared_wapf_wrapper_class(): void {
		$css = $this->css();

		$this->assertStringContainsString( '.opf-swatch-wrapper.opf-checkboxes--columns {', $css );

		// The pre-fix bare selector would tie with `.wapf-checkboxes`; it must
		// not remain anywhere (including the responsive media queries).
		$this->assertDoesNotMatchRegularExpression(
			'/(^|[^.\w-])\.opf-checkboxes--columns\s*\{/m',
			$css,
			'A bare `.opf-checkboxes--columns` rule ties with WAPF\'s one-class `.wapf-checkboxes` rule.'
		);

		$this->assertMatchesRegularExpression(
			'/@media\s*\(max-width:\s*720px\)\s*\{[^}]*\.opf-swatch-wrapper\.opf-checkboxes--columns\s*\{/',
			$css,
			'The tablet column override keeps the winning scope.'
		);
		$this->assertMatchesRegularExpression(
			'/@media\s*\(max-width:\s*420px\)\s*\{[^}]*\.opf-swatch-wrapper\.opf-checkboxes--columns\s*\{/',
			$css,
			'The mobile column override keeps the winning scope.'
		);
	}

	public function test_rendered_checkbox_wrapper_carries_the_scoping_class_alongside_the_wapf_aliases(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'columns' => 4, 'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap' ],
				[ 'slug' => 'note', 'label' => 'Gift note' ],
			] ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Options', $group, 10.0 );
		$html = (string) ob_get_clean();

		// The scoped selector only matches because the renderer emits the
		// OPF wrapper class; the shared WAPF aliases stay in place.
		$this->assertStringContainsString( 'class="opf-swatch-wrapper opf-checkboxes--columns wapf-checkboxes"', $html );
	}

	public function test_checkbox_and_radio_wrappers_keep_the_shared_wapf_classes(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap' ],
			] ],
			[ 'id' => 'size', 'label' => 'Size', 'type' => 'radio', 'choices' => [
				[ 'slug' => 's', 'label' => 'Small' ],
			] ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Options', $group, 10.0 );
		$html = (string) ob_get_clean();

		// Renaming these would break themes written against WAPF; the fix
		// raises OPF's specificity instead.
		$this->assertStringContainsString( 'class="opf-swatch-wrapper wapf-checkboxes"', $html );
		$this->assertStringContainsString( 'class="opf-swatch-wrapper wapf-radios"', $html );
	}
}
