<?php

namespace OPF\Tests\Unit;

use OPF\Service\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererSummaryModeTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['opf_test_options']['opf_price_summary_mode'], $GLOBALS['opf_test_options']['opf_show_totals'] );
	}

	public function test_new_sites_default_to_three_line_summary_and_legacy_option_is_preserved(): void {
		$this->assertSame( 'three_line', Renderer::price_summary_mode() );
		$this->assertTrue( Renderer::show_totals() );

		$GLOBALS['opf_test_options']['opf_show_totals'] = 'no';
		$this->assertSame( 'hidden', Renderer::price_summary_mode() );
		$this->assertFalse( Renderer::show_totals() );
		$GLOBALS['opf_test_options']['opf_show_totals'] = 'yes';
		$this->assertSame( 'three_line', Renderer::price_summary_mode() );
		$this->assertTrue( Renderer::show_totals() );
	}

	public function test_explicit_supported_summary_mode_wins_and_invalid_values_fail_safe(): void {
		foreach ( [ 'three_line', 'grand_total', 'hidden' ] as $mode ) {
			$GLOBALS['opf_test_options']['opf_price_summary_mode'] = $mode;
			$this->assertSame( $mode, Renderer::price_summary_mode() );
			$this->assertSame( 'hidden' !== $mode, Renderer::show_totals() );
		}

		$GLOBALS['opf_test_options']['opf_price_summary_mode'] = 'unexpected';
		$this->assertSame( 'three_line', Renderer::price_summary_mode() );
	}
}
