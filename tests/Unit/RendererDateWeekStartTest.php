<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererDateWeekStartTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['opf_test_options']['start_of_week'] );
		parent::tearDown();
	}

	public function test_date_field_receives_wordpress_monday_week_start(): void {
		$GLOBALS['opf_test_options']['start_of_week'] = 1;
		$html = $this->render_date_field();

		$this->assertStringContainsString( 'data-opf-week-start="1"', $html );
	}

	public function test_invalid_week_start_falls_back_to_sunday(): void {
		$GLOBALS['opf_test_options']['start_of_week'] = 7;
		$html = $this->render_date_field();

		$this->assertStringContainsString( 'data-opf-week-start="0"', $html );
	}

	private function render_date_field(): string {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'delivery-date', 'label' => 'Delivery date', 'type' => 'date' ] ] ] );
		ob_start();
		Renderer::render_group( '17', 'Delivery', $group, 10.0 );
		return (string) ob_get_clean();
	}
}
