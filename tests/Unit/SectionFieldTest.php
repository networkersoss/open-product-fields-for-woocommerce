<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class SectionFieldTest extends TestCase {
	public function test_section_is_a_non_submittable_layout_field(): void {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'details', 'type' => 'section', 'heading' => 'Details', 'content' => 'Choose your options.', 'required' => true ] ] ] );
		$this->assertFalse( $group->data['fields'][0]['required'] );
		ob_start();
		Renderer::render_group( '17', 'Details', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<h3>Details</h3>', $html );
		$this->assertStringNotContainsString( 'name="opf[17][details]"', $html );
	}
}
