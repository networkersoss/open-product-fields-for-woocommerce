<?php

namespace OPF\Tests\Unit;

use OPF\Engine\FieldGroup;
use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererUploadLimitsTest extends TestCase {
	public function test_upload_renderer_supports_min_count_and_unlimited_multiple_input(): void {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'min_files' => 2, 'max_files' => -1 ] ] ] );
		ob_start();
		Renderer::render_group( '19', 'Upload group', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'multiple', $html );
		$this->assertStringContainsString( 'data-opf-upload-max="-1"', $html );
		$this->assertStringContainsString( 'data-opf-upload-min="2"', $html );
	}

	public function test_upload_renderer_emits_configured_image_editor_controls(): void {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'image_editor_mode' => 'forced', 'image_editor_crop' => true, 'image_editor_resize' => false, 'image_editor_rotate' => true, 'image_editor_flip' => false, 'image_editor_aspect_ratio' => '4:3' ] ] ] );
		ob_start();
		Renderer::render_group( '19', 'Upload group', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-opf-upload-editor="forced"', $html );
		$this->assertStringContainsString( 'data-opf-editor-crop="1"', $html );
		$this->assertStringContainsString( 'data-opf-editor-resize="0"', $html );
		$this->assertStringContainsString( 'data-opf-editor-rotate="1"', $html );
		$this->assertStringContainsString( 'data-opf-editor-flip="0"', $html );
		$this->assertStringContainsString( 'data-opf-editor-aspect="4:3"', $html );
	}

	public function test_staged_required_upload_uses_token_validation_not_native_empty_file_constraint(): void {
		$group = new FieldGroup( [ 'fields' => [ [ 'id' => 'art', 'label' => 'Artwork', 'type' => 'upload', 'required' => true ] ] ] );
		ob_start();
		Renderer::render_group( '19', 'Upload group', $group, 10.0 );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-opf-upload-min="1"', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]+type="file"[^>]+required/', $html );
	}
}
