<?php

namespace OPF\Tests\Unit;

use OPF\Service\LivePreviewFonts;
use PHPUnit\Framework\TestCase;

final class LivePreviewFontsTest extends TestCase {
	private string $font_file;

	protected function setUp(): void {
		$this->font_file = tempnam( sys_get_temp_dir(), 'opf-font-' );
	}

	protected function tearDown(): void {
		if ( is_file( $this->font_file ) ) {
			unlink( $this->font_file );
		}
	}

	public function test_accepts_only_matching_woff_and_woff2_signatures_within_size_limit(): void {
		file_put_contents( $this->font_file, 'wOFF' . str_repeat( "\0", 20 ) );
		$this->assertTrue( LivePreviewFonts::is_valid_font_file( $this->font_file, 'woff' ) );
		$this->assertTrue( LivePreviewFonts::is_valid_font_file( $this->font_file, 'woff', 'font/woff' ) );
		$this->assertFalse( LivePreviewFonts::is_valid_font_file( $this->font_file, 'woff', 'image/png' ) );
		$this->assertFalse( LivePreviewFonts::is_valid_font_file( $this->font_file, 'woff2' ) );

		file_put_contents( $this->font_file, 'wOF2' . str_repeat( "\0", 20 ) );
		$this->assertTrue( LivePreviewFonts::is_valid_font_file( $this->font_file, 'woff2' ) );
		$this->assertFalse( LivePreviewFonts::is_valid_font_file( $this->font_file, 'ttf' ) );
	}

	public function test_font_names_are_plain_bounded_css_family_names(): void {
		$this->assertSame( 'Custom Sans 2', LivePreviewFonts::normalize_font_name( ' Custom Sans 2 ' ) );
		$this->assertSame( '', LivePreviewFonts::normalize_font_name( 'Bad";body{color:red}' ) );
		$this->assertSame( '', LivePreviewFonts::normalize_font_name( str_repeat( 'a', 70 ) ) );
	}
}
