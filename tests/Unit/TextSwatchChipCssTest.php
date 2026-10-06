<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfDesign;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * WAPF text-swatch chip decoration contract: base, hover and selected.
 *
 * WAPF Extended 3.1.5 ships two chip stylesheets. With design settings saved it
 * serves the generated `frontend.min.css` (written from
 * `assets/css/frontend-themed.min.css`, `class-design-helper.php:1446-1478`),
 * which applies four declarations to `.wapf-swatch--text`:
 *
 *   border: var(--apf-ts-border, none);
 *   color: var(--apf-ts-color, inherit);
 *   background: var(--apf-ts-bg, transparent);
 *   border-radius: var(--apf-ts-radius, 4px);
 *
 * plus two state rules on the same element — `.wapf-swatch--text:hover`
 * (`color: var(--apf-ts-color-hov, inherit)`,
 * `border-color: var(--apf-ts-border-color-hov, transparent)`,
 * `background: var(--apf-ts-bg-hov, transparent)`) and
 * `.wapf-swatch--text.wapf-checked` (`border-color: var(--apf-ts-border-color-sel,
 * transparent)`, `background: var(--apf-ts-bg-sel, transparent)`,
 * `color: var(--apf-ts-color-sel, inherit)`). `wapf-checked` is the class WAPF
 * renders on a selected choice (`includes/classes/class-html.php:597`) and
 * toggles on `.wapf-swatch input` change; OPF's equivalent is `opf-checked`
 * (`includes/Service/Renderer.php:1221`), which the frontend script toggles on
 * every `.opf-swatch`.
 *
 * **Without design settings** `generate_css()` defers to `set_default_css()`
 * (`class-design-helper.php:1137-1146`, `:1308-1311`), which byte-copies
 * `assets/css/frontend-default.min.css` over `frontend.min.css`; the public
 * controller enqueues that file (`class-public-controller.php:182-186`). That
 * stylesheet states the chip as plain declarations (single line, char offsets):
 *
 *   char 4976 `.wapf-swatch--text{…;border-radius:4px;border:1px solid #ccc}`
 *   char 5098 `.wapf-swatch--text:hover{border-color:#353c4e}`
 *   char 5144 `.wapf-swatch--text.wapf-checked{border-color:#353c4e;background:#353c4e;color:#fff}`
 *
 * OPF reproduces that regime from the emit side instead of from the fallbacks:
 * while the design option is empty `WapfDesign::css()` returns those values as
 * `--apf-ts-*` variables, so both regimes drive the same rules and the fallbacks
 * stay WAPF's themed ones. The default stylesheet's hover rule re-colours the
 * border only, so the hover colour and background resolve to
 * `inherit`/`transparent` there too; the radius fallback stays 4px, matching both
 * stylesheets.
 *
 * **The regime decision is WAPF's own `empty()` on the option**:
 * `generate_css()` takes `$raw_settings = get_option( 'wapf_design_settings',
 * false )` and calls `set_default_css()` when `empty( $raw_settings )`
 * (`class-design-helper.php:1139-1144`). A missing option and a saved-but-empty
 * array both serve the default stylesheet; any saved key, whether it is a chip
 * state key or not, serves the generated one. A variable whose setting is empty
 * is not emitted (`class-design-helper.php:1527`), so that state resolves through
 * the themed fallbacks asserted below.
 *
 * The `--apf-ts-*` values come from the migrated `wapf_design_settings` option
 * (`includes/classes/class-design-helper.php:1514-1516`; OPF re-emits them in
 * `Engine\WapfDesign::css()`), so a configured design still overrides every
 * fallback. WAPF renders text swatches as
 * `views/frontend/fields/text-swatch.php:17` →
 * `<div class="wapf-swatch wapf-swatch--text …">` and
 * `views/frontend/fields/multi-text-swatch.php:18` for the multi choice, while
 * OPF reuses `.opf-swatch--text` for plain checkbox/radio choices too, so every
 * rule is scoped to `.opf-text-swatch-wrapper` — the class the renderer adds
 * only to a real text swatch.
 */
final class TextSwatchChipCssTest extends TestCase {
	private const CHIP_SELECTOR  = '.opf-text-swatch-wrapper .opf-swatch--text {';
	private const HOVER_SELECTOR = '.opf-text-swatch-wrapper .opf-swatch--text:hover {';
	private const CHECKED_SELECTOR = '.opf-text-swatch-wrapper .opf-swatch--text.opf-checked {';

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

	/** The declaration block of one scoped text-swatch rule. */
	private function declarations( string $selector ): string {
		$css = $this->css();
		$pos = strpos( $css, $selector );
		$this->assertNotFalse( $pos, 'The scoped text-swatch rule is missing: ' . $selector );
		$start = (int) $pos + strlen( $selector );
		return substr( $css, $start, (int) strpos( $css, '}', $start ) - $start );
	}

	public function test_base_rule_consumes_the_wapf_text_swatch_variables_with_the_wapf_themed_fallbacks(): void {
		$rule = $this->declarations( self::CHIP_SELECTOR );

		// Themed fallbacks: a chip setting left empty resolves to the element's own
		// initial values, exactly as `frontend-themed.min.css` does. With no design
		// settings the default chip arrives as the variables instead (see
		// test_a_missing_design_settings_option_emits_the_wapf_default_chip_variables).
		$this->assertStringContainsString( 'border: var(--apf-ts-border, none);', $rule );
		$this->assertStringContainsString( 'background: var(--apf-ts-bg, transparent);', $rule );
		$this->assertStringContainsString( 'color: var(--apf-ts-color, inherit);', $rule );

		// The corner radius keeps its OPF-first precedence chain (4px = WAPF's own
		// default radius in both stylesheets).
		$this->assertStringContainsString(
			'border-radius: var(--opf-text-swatch-radius, var(--apf-ts-radius, 4px));',
			$rule
		);
	}

	public function test_hover_rule_consumes_the_wapf_hover_variables_with_the_wapf_themed_fallbacks(): void {
		$rule = $this->declarations( self::HOVER_SELECTOR );

		$this->assertStringContainsString( 'color: var(--apf-ts-color-hov, inherit);', $rule );
		// Themed fallback; with no design settings the default stylesheet's
		// `border-color:#353c4e` (char 5098) is the emitted `--apf-ts-border-color-hov`.
		$this->assertStringContainsString( 'border-color: var(--apf-ts-border-color-hov, transparent);', $rule );
		$this->assertStringContainsString( 'background: var(--apf-ts-bg-hov, transparent);', $rule );

		// The hover state re-colours the chip; it never re-declares the base
		// decoration or the radius.
		$this->assertStringNotContainsString( 'border-radius', $rule );
		$this->assertStringNotContainsString( 'border:', $rule );
	}

	public function test_selected_rule_consumes_the_wapf_selected_variables_with_the_wapf_themed_fallbacks(): void {
		$rule = $this->declarations( self::CHECKED_SELECTOR );

		// Themed fallbacks; the default stylesheet's char 5144 values are the
		// emitted `--apf-ts-*-sel` variables while the design option is empty.
		$this->assertStringContainsString( 'border-color: var(--apf-ts-border-color-sel, transparent);', $rule );
		$this->assertStringContainsString( 'background: var(--apf-ts-bg-sel, transparent);', $rule );
		$this->assertStringContainsString( 'color: var(--apf-ts-color-sel, inherit);', $rule );

		$this->assertStringNotContainsString( 'border-radius', $rule );
		$this->assertStringNotContainsString( 'border:', $rule );
	}

	public function test_the_selected_state_rule_follows_the_hover_state_rule(): void {
		$css = $this->css();

		// WAPF's cascade: `:hover` and `.wapf-checked` have the same specificity,
		// so a selected chip that is also hovered must keep its selected values
		// because the selected rule is declared last.
		$this->assertLessThan(
			strpos( $css, self::CHECKED_SELECTOR ),
			strpos( $css, self::HOVER_SELECTOR ),
			'The selected rule must override the hover rule on a selected chip.'
		);
		$this->assertLessThan(
			strpos( $css, self::HOVER_SELECTOR ),
			strpos( $css, self::CHIP_SELECTOR ),
			'The base rule must be declared before the state rules.'
		);
	}

	public function test_every_state_variable_has_exactly_one_scoped_consumer(): void {
		$css = $this->css();

		// One consumer per variable: a second (unscoped) consumer would decorate
		// the plain checkbox/radio choices that share `.opf-swatch--text`.
		foreach ( [
			'var(--apf-ts-border,',
			'var(--apf-ts-bg,',
			'var(--apf-ts-color,',
			'var(--apf-ts-radius,',
			'var(--apf-ts-color-hov,',
			'var(--apf-ts-border-color-hov,',
			'var(--apf-ts-bg-hov,',
			'var(--apf-ts-color-sel,',
			'var(--apf-ts-border-color-sel,',
			'var(--apf-ts-bg-sel,',
		] as $variable ) {
			$this->assertSame( 1, substr_count( $css, $variable ), $variable . ' must have exactly one consumer.' );
		}

		// The scope is required on every state: WAPF never puts
		// `.wapf-swatch--text` on plain checkbox/radio choices, OPF does.
		foreach ( [ self::CHIP_SELECTOR, self::HOVER_SELECTOR, self::CHECKED_SELECTOR ] as $selector ) {
			$this->assertSame( 1, substr_count( $css, $selector ), $selector . ' must be declared exactly once.' );
		}

		// Every `.opf-swatch--text` in a live rule sits behind the wrapper scope;
		// a bare selector (base, `:hover` or `.opf-checked`) would decorate plain
		// checkbox/radio choices.
		$this->assertSame(
			3,
			substr_count( $css, '.opf-swatch--text' ),
			'Every `.opf-swatch--text` selector must carry the text-swatch wrapper scope.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/(^|,)\s*\.opf-swatch--text(?::hover|\.opf-checked)?\s*\{/m',
			$css,
			'A bare `.opf-swatch--text` selector would decorate plain checkbox/radio choices.'
		);
	}

	public function test_migrated_design_settings_emit_the_variables_the_rules_read(): void {
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [
			'apf-ts-border-width'      => '2px',
			'apf-ts-border-color'      => '#cccccc',
			'apf-ts-bg'                => '#121212',
			'apf-ts-color'             => '#ffffff',
			'apf-ts-radius'            => '18px',
			'apf-ts-bg-hov'            => '#00ff00',
			'apf-ts-color-hov'         => '#0000ff',
			'apf-ts-border-color-hov'  => '#a4a4a4',
			'apf-ts-bg-sel'            => '#121212',
			'apf-ts-color-sel'         => '#ffffff',
			'apf-ts-border-color-sel'  => '#353c4e',
		];

		$css = WapfDesign::css();

		$this->assertStringContainsString( '--apf-ts-border:2px solid #cccccc', $css );
		$this->assertStringContainsString( '--apf-ts-bg:#121212', $css );
		$this->assertStringContainsString( '--apf-ts-color:#ffffff', $css );
		$this->assertStringContainsString( '--apf-ts-radius:18px', $css );
		$this->assertStringContainsString( '--apf-ts-bg-hov:#00ff00', $css );
		$this->assertStringContainsString( '--apf-ts-color-hov:#0000ff', $css );
		$this->assertStringContainsString( '--apf-ts-border-color-hov:#a4a4a4', $css );
		$this->assertStringContainsString( '--apf-ts-bg-sel:#121212', $css );
		$this->assertStringContainsString( '--apf-ts-color-sel:#ffffff', $css );
		$this->assertStringContainsString( '--apf-ts-border-color-sel:#353c4e', $css );

		// Regime B emits the configured values only; the default chip must be gone.
		$this->assertStringNotContainsString( '--apf-ts-border:1px solid #ccc', $css );
		$this->assertStringNotContainsString( '--apf-ts-radius:4px', $css );
	}

	public function test_a_missing_design_settings_option_emits_the_wapf_default_chip_variables(): void {
		unset( $GLOBALS['opf_test_options']['wapf_design_settings'] );

		$css = WapfDesign::css();

		// `frontend-default.min.css` chars 4976/5098/5144, emitted as the variables
		// the three scoped rules read: `generate_css()` calls `set_default_css()`
		// when `empty( get_option( 'wapf_design_settings', false ) )`
		// (class-design-helper.php:1139-1144).
		$this->assertStringContainsString( '--apf-ts-border:1px solid #ccc', $css );
		$this->assertStringContainsString( '--apf-ts-border-color-hov:#353c4e', $css );
		$this->assertStringContainsString( '--apf-ts-border-color-sel:#353c4e', $css );
		$this->assertStringContainsString( '--apf-ts-bg-sel:#353c4e', $css );
		$this->assertStringContainsString( '--apf-ts-color-sel:#fff', $css );
		$this->assertStringContainsString( '--apf-ts-radius:4px', $css );

		// The default stylesheet re-colours the border on hover and says nothing
		// about the base background/colour, so those variables stay unset and the
		// rules resolve `transparent`/`inherit` exactly as that stylesheet does.
		$this->assertStringNotContainsString( '--apf-ts-color:', $css );
		$this->assertStringNotContainsString( '--apf-ts-bg:', $css );
		$this->assertStringNotContainsString( '--apf-ts-color-hov:', $css );
		$this->assertStringNotContainsString( '--apf-ts-bg-hov:', $css );

		// Nothing else from the design layer is emitted in this regime.
		$this->assertStringNotContainsString( '.wapf-custom', $css );
		$this->assertStringNotContainsString( '--apf-radius', $css );
	}

	public function test_a_saved_but_empty_design_settings_option_is_the_default_chip_regime(): void {
		// WAPF decides with `empty( get_option( 'wapf_design_settings', false ) )`
		// (class-design-helper.php:1139-1144), so a saved-but-empty array serves the
		// default stylesheet just like a missing option does.
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [];

		$this->assertStringContainsString( '--apf-ts-border:1px solid #ccc', WapfDesign::css() );
	}

	public function test_a_saved_non_state_design_setting_switches_to_the_themed_regime(): void {
		// Any saved key selects the generated stylesheet, even one that has nothing
		// to do with the chip, so no default-chip value may be emitted.
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [ 'apf-radius' => '6px' ];

		$css = WapfDesign::css();

		// `create_border()` with no width/colour (class-design-helper.php:1652-1666).
		$this->assertStringContainsString( '--apf-ts-border:none', $css );
		$this->assertStringContainsString( '--apf-radius:6px', $css );
		$this->assertStringNotContainsString( '#353c4e', $css );
		$this->assertStringNotContainsString( '--apf-ts-radius:4px', $css );
	}

	public function test_state_keys_left_empty_do_not_emit_a_variable(): void {
		// WAPF skips a variable whose setting is empty (class-design-helper.php:1527),
		// so such a state resolves through the themed CSS fallbacks.
		$GLOBALS['opf_test_options']['wapf_design_settings'] = [
			'apf-radius'              => '6px',
			'apf-ts-border-color-hov' => '',
			'apf-ts-bg-hov'           => '',
			'apf-ts-color-hov'        => '',
			'apf-ts-border-color-sel' => '',
			'apf-ts-bg-sel'           => '',
			'apf-ts-color-sel'        => '',
		];

		$css = WapfDesign::css();

		$this->assertStringNotContainsString( '--apf-ts-border-color-hov', $css );
		$this->assertStringNotContainsString( '--apf-ts-bg-hov', $css );
		$this->assertStringNotContainsString( '--apf-ts-color-hov', $css );
		$this->assertStringNotContainsString( '--apf-ts-border-color-sel', $css );
		$this->assertStringNotContainsString( '--apf-ts-bg-sel', $css );
		$this->assertStringNotContainsString( '--apf-ts-color-sel', $css );
	}

	public function test_only_real_text_swatches_receive_the_scoped_wrapper_class(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'swatch', 'swatch_style' => 'text', 'choices' => [
				[ 'slug' => 'matte', 'label' => 'Matte' ],
			] ],
			[ 'id' => 'finish_multi', 'label' => 'Finishes', 'type' => 'swatch', 'swatch_style' => 'text', 'multiple' => true, 'choices' => [
				[ 'slug' => 'matte', 'label' => 'Matte' ],
				[ 'slug' => 'gloss', 'label' => 'Gloss' ],
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

		// Single-select (WAPF `text-swatch.php:17`) and multi
		// (`multi-text-swatch.php:18`) text swatches both get the scope.
		$this->assertSame( 2, substr_count( $html, 'class="opf-swatch-wrapper opf-text-swatch-wrapper"' ) );
		$this->assertStringContainsString( 'opf-swatch opf-swatch--text opf-single-select', $html );

		// The plain choices carry `.opf-swatch--text` (the regression risk) but
		// their wrapper lacks the scoping class, so the chip rules cannot match.
		$this->assertStringContainsString( 'class="opf-swatch-wrapper wapf-checkboxes"', $html );
		$this->assertStringContainsString( 'class="opf-swatch-wrapper wapf-radios"', $html );
		$this->assertStringContainsString( 'opf-swatch opf-swatch--text wapf-checkbox"', $html );
		$this->assertStringContainsString( 'opf-swatch opf-swatch--text wapf-radio opf-single-select"', $html );
		$this->assertSame( 2, substr_count( $html, 'opf-text-swatch-wrapper' ) );
	}

	public function test_the_selected_rule_keys_off_the_class_the_renderer_emits(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'finish', 'label' => 'Finish', 'type' => 'swatch', 'swatch_style' => 'text', 'choices' => [
				[ 'slug' => 'matte', 'label' => 'Matte', 'selected' => true ],
				[ 'slug' => 'gloss', 'label' => 'Gloss' ],
			] ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Options', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'opf-checked', $html, 'A selected text-swatch chip must carry `.opf-checked` for the state rule to match.' );
		$this->assertStringContainsString( 'opf-swatch opf-swatch--text opf-single-select opf-checked', $html );
		$this->assertSame( 1, substr_count( $html, 'opf-checked' ) );
	}
}
