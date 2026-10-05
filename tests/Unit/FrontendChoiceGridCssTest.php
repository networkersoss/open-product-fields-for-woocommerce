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
	private const SELECTOR = '.opf-swatch-wrapper.opf-checkboxes--columns {';

	private function css(): string {
		return (string) file_get_contents( OPF_DIR . 'assets/css/opf-frontend.css' );
	}

	/**
	 * Every choice-grid rule in source order, with the media query that
	 * encloses it (null for the base rule) and its declaration block.
	 *
	 * @return array<int,array{media:?string,maxWidth:?int,declarations:string}>
	 */
	private function column_rules( string $css ): array {
		$rules  = [];
		$offset = 0;
		while ( false !== ( $pos = strpos( $css, self::SELECTOR, $offset ) ) ) {
			$media     = null;
			$media_pos = strrpos( substr( $css, 0, $pos ), '@media' );
			if ( false !== $media_pos ) {
				// Each media block holds exactly one rule, so the first `}` after
				// its `{` closes the block.
				$media_open  = strpos( $css, '{', $media_pos );
				$media_close = strpos( $css, '}', (int) $media_open );
				if ( $pos > $media_open && $pos < $media_close ) {
					$media = trim( substr( $css, (int) $media_pos, (int) $media_open - (int) $media_pos ) );
				}
			}

			$declarations_start = $pos + strlen( self::SELECTOR );
			$rules[]            = [
				'media'        => $media,
				'maxWidth'     => null === $media ? null : (int) preg_replace( '/\D/', '', $media ),
				'declarations' => substr( $css, $declarations_start, (int) strpos( $css, '}', $declarations_start ) - $declarations_start ),
			];
			$offset = $pos + 1;
		}

		return $rules;
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

	/**
	 * The configured 4/2/1 cascade needs three rules in source order: the base
	 * rule, the tablet override and the mobile override. A base rule placed
	 * after the media queries wins at equal specificity (0,2,0) and every
	 * viewport renders the configured desktop count, so this simulates the
	 * cascade per viewport instead of only checking that the rules exist.
	 */
	public function test_each_viewport_resolves_to_its_own_column_variable(): void {
		$rules = $this->column_rules( $this->css() );

		$this->assertCount( 3, $rules, 'One base rule and the two responsive overrides.' );
		$this->assertSame( [ null, 720, 420 ], array_column( $rules, 'maxWidth' ) );

		$expected = [
			1280 => '--opf-checkbox-columns,',
			720  => '--opf-checkbox-columns-tablet,',
			600  => '--opf-checkbox-columns-tablet,',
			420  => '--opf-checkbox-columns-mobile,',
			400  => '--opf-checkbox-columns-mobile,',
		];
		foreach ( $expected as $viewport => $variable ) {
			$winner = null;
			foreach ( $rules as $rule ) {
				// Equal specificity: the last applicable rule in source order wins.
				if ( null === $rule['maxWidth'] || $viewport <= $rule['maxWidth'] ) {
					$winner = $rule;
				}
			}

			$this->assertNotNull( $winner );
			$this->assertStringContainsString(
				$variable,
				$winner['declarations'],
				'A ' . $viewport . 'px viewport must resolve to ' . $variable . '; a base rule after the media queries would win instead.'
			);
		}
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
