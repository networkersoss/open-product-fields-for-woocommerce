<?php

namespace OPF\Tests\Unit;

use OPF\Service\Admin\Settings;
use OPF\Service\Uploads;
use PHPUnit\Framework\TestCase;

/**
 * The admin "Modern file uploader" toggle must be the single control over the
 * uploader the renderer emits (`Uploads::modern()`), with the WAPF 3.1.7
 * on-by-default and the migrated `opf_upload_ajax`/`wapf_upload_ajax` values
 * still honoured until the toggle is saved.
 */
final class ModernUploaderSettingTest extends TestCase {
	private $previous_options;

	protected function setUp(): void {
		$this->previous_options = $GLOBALS['opf_test_options'] ?? [];
		$GLOBALS['opf_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['opf_test_options'] = $this->previous_options;
	}

	public function test_modern_uploader_is_on_by_default(): void {
		// WAPF 3.1.7: the modern file uploader is on unless unchecked.
		$this->assertTrue( Uploads::modern() );
		$this->assertTrue( Uploads::modern_default() );
		$this->assertSame( 'yes', $this->modern_uploader_setting()['default'] );
	}

	public function test_saved_toggle_controls_the_uploader(): void {
		$GLOBALS['opf_test_options'] = [ 'opf_modern_uploader' => 'no' ];
		$this->assertFalse( Uploads::modern() );

		$GLOBALS['opf_test_options'] = [ 'opf_modern_uploader' => 'yes' ];
		$this->assertTrue( Uploads::modern() );
	}

	public function test_saved_toggle_wins_over_legacy_values(): void {
		// The migrated option must not keep the exposed toggle inert.
		$GLOBALS['opf_test_options'] = [ 'opf_modern_uploader' => 'yes', 'opf_upload_ajax' => 'no', 'wapf_upload_ajax' => 'no' ];
		$this->assertTrue( Uploads::modern() );

		$GLOBALS['opf_test_options'] = [ 'opf_modern_uploader' => 'no', 'opf_upload_ajax' => 'yes', 'wapf_upload_ajax' => 'yes' ];
		$this->assertFalse( Uploads::modern() );
	}

	public function test_legacy_opf_upload_ajax_is_honoured_until_the_toggle_is_saved(): void {
		$GLOBALS['opf_test_options'] = [ 'opf_upload_ajax' => 'no' ];
		$this->assertFalse( Uploads::modern() );
		$this->assertFalse( Uploads::modern_default() );

		$GLOBALS['opf_test_options'] = [ 'opf_upload_ajax' => 'yes' ];
		$this->assertTrue( Uploads::modern() );
	}

	public function test_migrated_wapf_upload_ajax_is_honoured_and_ranks_below_opf(): void {
		$GLOBALS['opf_test_options'] = [ 'wapf_upload_ajax' => 'yes' ];
		$this->assertTrue( Uploads::modern() );

		$GLOBALS['opf_test_options'] = [ 'wapf_upload_ajax' => 'no' ];
		$this->assertFalse( Uploads::modern() );

		$GLOBALS['opf_test_options'] = [ 'opf_upload_ajax' => 'yes', 'wapf_upload_ajax' => 'no' ];
		$this->assertTrue( Uploads::modern() );
	}

	public function test_settings_checkbox_default_matches_the_effective_legacy_state(): void {
		// The rendered toggle must not claim "on" while a migrated value keeps
		// the storefront on the native input.
		$GLOBALS['opf_test_options'] = [ 'wapf_upload_ajax' => 'no' ];
		$this->assertSame( 'no', $this->modern_uploader_setting()['default'] );

		$GLOBALS['opf_test_options'] = [ 'opf_upload_ajax' => 'yes' ];
		$this->assertSame( 'yes', $this->modern_uploader_setting()['default'] );
	}

	/** @return array<string,mixed> */
	private function modern_uploader_setting(): array {
		$settings = array_values( array_filter(
			Settings::product_fields_settings( [], 'opf_product_fields' ),
			static fn( array $setting ): bool => 'opf_modern_uploader' === ( $setting['id'] ?? '' )
		) );
		$this->assertCount( 1, $settings );
		$this->assertSame( 'checkbox', $settings[0]['type'] );
		return $settings[0];
	}
}
