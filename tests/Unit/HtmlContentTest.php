<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class HtmlContentTest extends TestCase {
	public function test_html_content_is_sanitized_and_non_submittable(): void {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'notice', 'type' => 'html', 'content' => '<strong>Ships soon</strong><script>alert(1)</script>' ] ] ] );
		ob_start();
		Renderer::render_group( '17', 'Notice', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<strong>Ships soon</strong>', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'name="opf[17][notice]"', $html );
	}
}
