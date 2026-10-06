<?php

namespace {
	require_once __DIR__ . '/LinkedProductsLifecycleTest.php';
	if ( ! function_exists( 'wp_trim_words' ) ) {
		function wp_trim_words( $text, $num_words = 55, $more = null ) {
			$words = preg_split( '/\s+/', trim( strip_tags( (string) $text ) ) );
			return implode( ' ', array_slice( $words, 0, (int) $num_words ) );
		}
	}
}

namespace OPF\Tests\Unit {

use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use OPF\Service\LinkedProducts;
use OPF\Service\Renderer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Multi-value choice controls post `opf[gid][fid][]`, so their "nothing
 * selected" sentinel companion must spell the name the same way. An
 * unbracketed companion beside a bracketed sibling makes a third-party form
 * serializer that folds duplicate names into an array push onto a string
 * (Barn2 Quick View Pro's `serializeArray()` reducer threw
 * `TypeError: t[o].push is not a function`).
 *
 * The WC product stubs come from LinkedProductsLifecycleTest so the linked
 * "products" field markup can be rendered too; the file is process-isolated so
 * its `$GLOBALS['opf_linked_products']` registry cannot leak into other tests.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class RendererMultiValueCompanionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['opf_linked_products'] = [
			11 => new \WC_Product( [ 'id' => 11, 'name' => 'Mug', 'price' => 8.0 ] ),
			12 => new \WC_Product( [ 'id' => 12, 'name' => 'Coaster', 'price' => 4.0 ] ),
		];
		$GLOBALS['opf_linked_product_cats'] = [];
		$GLOBALS['opf_test_options']         = [];
		$GLOBALS['opf_wpml_filters']         = [];
	}

	/**
	 * @param array<string,mixed> $field Field definition.
	 * @return array{html:string,field:array<string,mixed>} Rendered markup and the normalized field.
	 */
	private function render( array $field ): array {
		$group = new FieldGroup( [ 'fields' => [ $field ] ] );
		ob_start();
		Renderer::render_group( '18', 'Options', $group, 10.0 );
		return [ 'html' => (string) ob_get_clean(), 'field' => $group->data['fields'][0] ];
	}

	/** @return string[] Every `name` attribute in document order. */
	private function names( string $html ): array {
		preg_match_all( '/name="([^"]+)"/', $html, $matches );
		return $matches[1];
	}

	private function checkbox_field(): array {
		return [
			'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox',
			'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift' ],
				[ 'slug' => 'wrap', 'label' => 'Wrap' ],
			],
		];
	}

	public function test_checkbox_companion_uses_the_bracketed_name_of_its_siblings(): void {
		$html = $this->render( $this->checkbox_field() )['html'];

		$this->assertStringContainsString( 'class="opf-tf-h" data-fid="extras" value="0" name="opf[18][extras][]"', $html );
		$this->assertSame( 3, substr_count( $html, 'name="opf[18][extras][]"' ), 'sentinel companion plus the two choice inputs' );
		$this->assertStringNotContainsString( 'name="opf[18][extras]"', $html );
	}

	public function test_single_value_choice_companion_stays_unbracketed(): void {
		$radio = $this->render( [
			'id' => 'finish', 'label' => 'Finish', 'type' => 'radio',
			'choices' => [ [ 'slug' => 'matte', 'label' => 'Matte' ], [ 'slug' => 'gloss', 'label' => 'Gloss' ] ],
		] )['html'];
		$this->assertStringContainsString( 'name="opf[18][finish]"', $radio );
		$this->assertStringNotContainsString( 'name="opf[18][finish][]"', $radio );

		$swatch = $this->render( [
			'id' => 'color', 'label' => 'Color', 'type' => 'swatch', 'swatch_style' => 'text',
			'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ] ],
		] )['html'];
		$this->assertStringContainsString( 'name="opf[18][color]"', $swatch );
		$this->assertStringNotContainsString( 'name="opf[18][color][]"', $swatch );
	}

	public function test_products_checkbox_companion_uses_the_bracketed_name_of_its_siblings(): void {
		$html = $this->render( [
			'id' => 'addons', 'label' => 'Add-ons', 'type' => 'products', 'subtype' => 'checkbox',
			'choices' => [ [ 'product_id' => 11 ], [ 'product_id' => 12 ] ],
		] )['html'];

		$this->assertStringContainsString( 'class="opf-tf-h" data-fid="addons" value="0" name="opf[18][addons][]"', $html );
		$this->assertSame( 3, substr_count( $html, 'name="opf[18][addons][]"' ), 'sentinel companion plus the two linked products' );
		$this->assertStringNotContainsString( 'name="opf[18][addons]"', $html );
	}

	public function test_products_image_and_card_companions_use_the_bracketed_name_of_their_siblings(): void {
		foreach ( [ 'image', 'card' ] as $subtype ) {
			$html = $this->render( [
				'id' => 'addons', 'label' => 'Add-ons', 'type' => 'products', 'subtype' => $subtype,
				'choices' => [ [ 'product_id' => 11 ], [ 'product_id' => 12 ] ],
			] )['html'];

			$this->assertSame( 3, substr_count( $html, 'name="opf[18][addons][]"' ), "$subtype companion plus the two linked products" );
			$this->assertStringNotContainsString( 'name="opf[18][addons]"', $html );
		}
	}

	public function test_products_radio_companion_stays_unbracketed(): void {
		$html = $this->render( [
			'id' => 'addons', 'label' => 'Add-ons', 'type' => 'products', 'subtype' => 'radio',
			'choices' => [ [ 'product_id' => 11 ], [ 'product_id' => 12 ] ],
		] )['html'];

		$this->assertStringContainsString( 'name="opf[18][addons]"', $html );
		$this->assertStringNotContainsString( 'name="opf[18][addons][]"', $html );
	}

	/**
	 * The browser posts the sentinel first, then one entry per checked choice:
	 * the bracketed companion now contributes a literal `0` element, which the
	 * sanitizer must drop without counting any slug twice.
	 */
	public function test_bracketed_companion_sentinel_is_dropped_and_values_are_not_double_counted(): void {
		[ 'html' => $html, 'field' => $field ] = $this->render( $this->checkbox_field() );
		$names = array_values( array_unique( $this->names( $html ) ) );
		$this->assertSame( [ 'opf[18][extras][]' ], $names, 'the rendered control and its companion share one name' );

		$sanitize = new ReflectionMethod( CartIntegration::class, 'sanitize_value' );

		parse_str( 'opf[18][extras][]=0&opf[18][extras][]=gift', $submitted );
		$this->assertSame( [ '0', 'gift' ], $submitted['opf'][18]['extras'], 'the sentinel arrives as a real array element' );
		$this->assertSame( [ 'gift' ], $sanitize->invoke( null, $field, $submitted['opf'][18]['extras'] ) );

		parse_str( 'opf[18][extras][]=0&opf[18][extras][]=gift&opf[18][extras][]=gift&opf[18][extras][]=wrap', $repeated );
		$this->assertSame(
			[ 'gift', 'wrap' ],
			$sanitize->invoke( null, $field, $repeated['opf'][18]['extras'] ),
			'duplicated slugs collapse and the sentinel never becomes a selection'
		);

		parse_str( 'opf[18][extras][]=0', $empty );
		$this->assertNull( $sanitize->invoke( null, $field, $empty['opf'][18]['extras'] ), 'nothing selected still sanitizes to null' );
	}

	public function test_products_sanitizer_drops_the_bracketed_sentinel_and_deduplicates(): void {
		[ 'field' => $field ] = $this->render( [
			'id' => 'addons', 'label' => 'Add-ons', 'type' => 'products', 'subtype' => 'checkbox',
			'choices' => [ [ 'product_id' => 11 ], [ 'product_id' => 12 ] ],
		] );

		parse_str( 'opf[18][addons][]=0&opf[18][addons][]=p11&opf[18][addons][]=p11', $submitted );
		$this->assertSame( [ 'p11' ], LinkedProducts::sanitize_value( $field, $submitted['opf'][18]['addons'] ) );

		parse_str( 'opf[18][addons][]=0', $empty );
		$this->assertNull( LinkedProducts::sanitize_value( $field, $empty['opf'][18]['addons'] ) );
	}
}
}
