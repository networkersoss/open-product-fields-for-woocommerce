<?php
/**
 * Language-resolution fallback tests.
 *
 * PolylangIntegrationTest shims Polylang in-process, which makes the
 * "Polylang absent" branch of FieldGroups::for_product() unreachable there.
 * These tests run in isolated child processes whose fixture defines no
 * Polylang functions, so the documented order
 * `pll_current_language('locale')` -> `ICL_LANGUAGE_CODE` -> `'default'`
 * is exercised for the two fallback legs.
 */

namespace OPF\Tests\Unit;

use OPF\Service\FieldGroups;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class LocaleFallbackTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/fixtures/locale-fallback-contract.php';
		$GLOBALS['opf_fallback_filters']    = [];
		$GLOBALS['opf_fallback_cache']      = [];
		$GLOBALS['opf_fallback_logged_in']  = false;
		$GLOBALS['opf_fallback_posts']      = [
			self::language_post( 7, 'Language', [ 'nl_NL' ] ),
			self::language_post( 8, 'Default', [ 'default' ] ),
		];
		FieldGroups::flush_cache();
	}

	public function test_polylang_is_absent_in_this_process(): void {
		$this->assertFalse( function_exists( 'pll_current_language' ) );
		$this->assertFalse( function_exists( 'pll_get_post_language' ) );
	}

	public function test_wpml_current_language_is_used_when_polylang_is_absent(): void {
		$GLOBALS['opf_fallback_filters']['wpml_current_language'] = static fn() => 'nl_NL';
		$this->assertSame( [ 7 ], array_column( FieldGroups::for_product( new \WC_Product() ), 'id' ) );
	}

	public function test_icl_language_code_is_used_when_wpml_is_absent(): void {
		define( 'ICL_LANGUAGE_CODE', 'nl_NL' );
		$resolved = array_column( FieldGroups::for_product( new \WC_Product() ), 'id' );
		$this->assertSame( [ 7 ], $resolved );
	}

	public function test_default_is_the_last_resort(): void {
		FieldGroups::flush_cache();
		$resolved = array_column( FieldGroups::for_product( new \WC_Product() ), 'id' );
		$this->assertSame( [ 8 ], $resolved );
	}

	private static function language_post( int $id, string $title, array $terms ): \WP_Post {
		$data = [
			'fields'      => [ [ 'id' => 'note', 'type' => 'text', 'label' => $title ] ],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'user_language', 'operator' => 'in', 'terms' => $terms ] ] ] ],
		];
		return new \WP_Post( $id, $title, json_encode( $data ) );
	}
}
