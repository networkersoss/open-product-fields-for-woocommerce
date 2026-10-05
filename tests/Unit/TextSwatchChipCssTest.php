<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfDesign;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * WAPF text-swatch chip decoration contract.
 *
 * WAPF Extended 3.1.5's themed stylesheet
 * (`assets/css/frontend-themed.min.css`) applies four declarations to
 * `.wapf-swatch--text`:
 *
 *   border: var(--apf-ts-border, none);
 *   color: var(--apf-ts-color, inherit);
 *   background: var(--apf-ts-bg, transparent);
 *   border-radius: var(--apf-ts-radius, 4px);
 *
 * The `--apf-ts-*` values come from the migrated `wapf_design_settings` option
 * (`includes/classes/class-design-helper.php:1514,1535-1536`; OPF re-emits them
 * in `Engine\WapfDesign::css()`). WAPF renders text swatches as
 * `views/frontend/fields/text-swatch.php:17` →
 * `<div class="wapf-swatch wapf-swatch--text …">`, while OPF reuses
 * `.opf-swatch--text` for plain checkbox/radio choices too, so the rule is
 * scoped to `.opf-text-swatch-wrapper` — the class the renderer adds only to a
 * real text swatch.
 */
final class TextSwatchChipCssTest extends TestCase {
	private const SELECTOR = '.opf-text-swatch-wrapper .opf-swatch--text {';

	/** @var array<string,mixed> */
	private array $options;

	protected function setUp(): void {
		$this->options = $GLOBALS['opf_test_options'] ?? [];
	}

	protected function tearDown(): void {
		$GLOBALS['opf_test_options'] = $this->options;
	}

	private function css(): string {
		$css = (string) file_get_contents( OPF_DIR . 'assets/css/opf-frontend.css' );
		// Comments quote the WAPF declarations; the contract is about live rules.
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/** The declaration block of the single scoped text-swatch rule. */
	private function rule(): string {
		$css = $this->css();
		$pos = strpos( $css, self::SELECTOR );
		$this->assertNotFalse( $pos, 'The scoped text-swatch chip rule is missing.' );
		$start = (int) $pos + strlen( self::SELECTOR );
		return substr( $css, $start, (int) strpos( $css, '}', $start ) - $start );
	}

	public function test_scoped_rule_consumes_the_wapf_text_swatch_variables_with_wapf_defaults(): void {
		$rule = $this->rule();

		$this->assertStringContainsString( 'border: var(--apf-ts-border, none);', $rule );
		$this->assertStringContainsString( 'background: var(--apf-ts-bg, transparent);', $rule );
		$this->assertStringContainsString( 'color: var(--apf-ts-color, inherit);', $rule );

		// The corner radius keeps its OPF-first precedence chain.
		$this->assertStringContainsString(
			'border-radius: var(--opf-text-swatch-radius, var(--apf-ts-radius, 4px));',
			$rule
		);
	}

	public function test_the_decoration_is_declared_only_inside_the_scoped_rule(): void {
		$css = $this->css();

		// One consumer per variable: a second (unscoped) consumer would decorate
		// the plain checkbox/radio choices that share `.opf-swatch--text`.
		$this->assertSame( 1, substr_count( $css, 'var(--apf-ts-border' ) );
		$this->assertSame( 1, substr_count( $css, 'var(--apf-ts-bg' ) );
		$this->assertSame( 1, substr_count( $css, 'var(--apf-ts-color' ) );

		// The scope is required: WAPF never puts `.wapf-swatch--text` on plain
		// checkbox/radio choices, OPF does.
		$this->assertSame( 1, substr_count( $css, self::SELECTOR ) );
		$this->assertDoesNotMatchRegularExpression(
			'/(^|,)\s*\.opf-swatch--text\s*\{/m',
			$css,
			'A bare `.opf-swatch--text` selector would decorate plain checkbox/radio choices.'
		);
	}

	public function test_migrated_design_settings_emit_the_variables_the_rule_reads(): void {
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [
			'apf-ts-border-width' => '2px',
			'apf-ts-border-color' => '#cccccc',
			'apf-ts-bg'           => '#121212',
			'apf-ts-color'        => '#ffffff',
			'apf-ts-radius'       => '18px',
		];

		$css = WapfDesign::css();

		$this->assertStringContainsString( '--apf-ts-border:2px solid #cccccc', $css );
		$this->assertStringContainsString( '--apf-ts-bg:#121212', $css );
		$this->assertStringContainsString( '--apf-ts-color:#ffffff', $css );
		$this->assertStringContainsString( '--apf-ts-radius:18px', $css );
	}

	public function test_only_real_text_swatches_receive_the_scoped_wrapper_class(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'swatch', 'swatch_style' => 'text', 'choices' => [
				[ 'slug' => 'matte', 'label' => 'Matte' ],
			] ],
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

		$this->assertStringContainsString( 'class="opf-swatch-wrapper opf-text-swatch-wrapper"', $html );
		$this->assertStringContainsString( 'opf-swatch opf-swatch--text opf-single-select', $html );

		// The plain choices carry `.opf-swatch--text` (the regression risk) but
		// their wrapper lacks the scoping class, so the chip rule cannot match.
		$this->assertStringContainsString( 'class="opf-swatch-wrapper wapf-checkboxes"', $html );
		$this->assertStringContainsString( 'class="opf-swatch-wrapper wapf-radios"', $html );
		$this->assertStringContainsString( 'opf-swatch opf-swatch--text wapf-checkbox"', $html );
		$this->assertStringContainsString( 'opf-swatch opf-swatch--text wapf-radio opf-single-select"', $html );
		$this->assertSame( 1, substr_count( $html, 'opf-text-swatch-wrapper' ) );
	}
}
