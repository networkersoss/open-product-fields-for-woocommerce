<?php

namespace {
if ( ! function_exists( 'do_shortcode' ) ) {
	function do_shortcode( $content ): string {
		$GLOBALS['opf_test_shortcode_calls'] = ( $GLOBALS['opf_test_shortcode_calls'] ?? 0 ) + 1;
		return str_replace(
			[ '[opf_calendar]', '[site_name]' ],
			[ '<iframe title="Booking calendar"></iframe>', '<em>followersya</em>' ],
			(string) $content
		);
	}
}
}

namespace OPF\Tests\Unit {

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class ShortcodeFieldTest extends TestCase {
	public function test_shortcode_is_a_non_submittable_content_field_and_renders_shortcode_output(): void {
		$group = new FieldGroup(
			[
				'fields' => [
					[
						'id'       => 'booking',
						'type'     => 'shortcode',
						'content'  => '[opf_calendar]',
						'required' => true,
					],
				],
			]
		);

		$this->assertSame( 'shortcode', $group->data['fields'][0]['type'] );
		$this->assertSame( '[opf_calendar]', $group->data['fields'][0]['content'] );
		$this->assertFalse( $group->data['fields'][0]['required'] );

		ob_start();
		Renderer::render_group( '17', 'Booking', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="opf-field-input opf-shortcode"><iframe title="Booking calendar"></iframe></div>', $html );
		$this->assertStringNotContainsString( '[opf_calendar]', $html );
		$this->assertStringNotContainsString( 'name="opf[17][booking]"', $html );
	}

	public function test_wapf_shortcode_field_maps_its_p_content_without_losing_the_shortcode(): void {
		$mapped = \OPF\Engine\WapfMapper::map(
			[
				'fields' => [
					[
						'id'      => 'booking',
						'label'   => 'Booking',
						'type'    => 'shortcode',
						'options' => [ 'p_content' => '[opf_calendar service="consultation"]' ],
					],
				],
			]
		);

		$this->assertSame( 'shortcode', $mapped['group']['fields'][0]['type'] );
		$this->assertSame( '[opf_calendar service="consultation"]', $mapped['group']['fields'][0]['content'] );
		$this->assertFalse( $mapped['needs_review'] );
	}
}
}
