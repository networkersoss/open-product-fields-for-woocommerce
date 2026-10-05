<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererCheckboxColumnsTest extends TestCase {
	public function test_checkbox_column_count_is_rendered_without_changing_choice_values_or_labels(): void {
		$group = new FieldGroup( [ 'fields' => [
			[ 'id' => 'extras', 'label' => 'Extras', 'type' => 'checkbox', 'columns' => 3, 'choices' => [
				[ 'slug' => 'gift', 'label' => 'Gift wrap' ],
				[ 'slug' => 'note', 'label' => 'Gift note' ],
			] ],
		] ] );
		ob_start();
		Renderer::render_group( '17', 'Options', $group, 10.0 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'opf-checkboxes--columns', $html );
		$this->assertStringContainsString( '--opf-checkbox-columns:3', $html );
		$this->assertStringContainsString( 'value="gift" data-opf-label="Gift wrap"', $html );
		$this->assertStringContainsString( 'value="note" data-opf-label="Gift note"', $html );
	}
}
