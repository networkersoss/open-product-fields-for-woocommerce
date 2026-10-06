<?php
/**
 * Admin builder script translations (`wp_set_script_translations`).
 *
 * The builder is a wp-i18n JavaScript app: its ~190 literals are wrapped with
 * the plugin text domain and the generated JED catalog has to be registered
 * against the `opf-builder` handle, otherwise the admin UI stays English.
 */

namespace {
	if ( ! function_exists( 'get_current_screen' ) ) {
		function get_current_screen() { return $GLOBALS['opf_test_current_screen'] ?? null; }
	}
	if ( ! function_exists( 'wp_set_script_translations' ) ) {
		function wp_set_script_translations( ...$args ) {
			$GLOBALS['opf_script_translations'][] = $args;
			return true;
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	}
}

namespace OPF\Service {
	if ( ! function_exists( 'OPF\\Service\\wp_enqueue_script' ) ) {
		function wp_enqueue_script( ...$args ): void {}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_enqueue_style' ) ) {
		function wp_enqueue_style( ...$args ): void {}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Service\Assets;
	use PHPUnit\Framework\TestCase;

	final class AssetsScriptTranslationsTest extends TestCase {

		private const DOMAIN = 'open-product-fields-for-woocommerce';

		private $previous_screen;

		protected function setUp(): void {
			$this->previous_screen = $GLOBALS['opf_test_current_screen'] ?? null;
			$GLOBALS['opf_script_translations'] = [];
			$GLOBALS['opf_test_current_screen'] = (object) [ 'post_type' => 'opf_field_group' ];
		}

		protected function tearDown(): void {
			if ( null !== $this->previous_screen ) {
				$GLOBALS['opf_test_current_screen'] = $this->previous_screen;
			} else {
				unset( $GLOBALS['opf_test_current_screen'] );
			}
			unset( $GLOBALS['opf_script_translations'] );
		}

		public function test_builder_handle_gets_script_translations_from_the_plugin_languages_dir(): void {
			Assets::register_admin( 'post.php' );

			$this->assertCount( 1, $GLOBALS['opf_script_translations'] );
			$this->assertSame(
				[ 'opf-builder', self::DOMAIN, OPF_DIR . 'languages' ],
				$GLOBALS['opf_script_translations'][0]
			);
		}

		public function test_script_translations_are_not_registered_off_the_builder_screen(): void {
			$GLOBALS['opf_test_current_screen'] = (object) [ 'post_type' => 'product' ];
			Assets::register_admin( 'post.php' );
			$this->assertSame( [], $GLOBALS['opf_script_translations'] );

			$GLOBALS['opf_test_current_screen'] = (object) [ 'post_type' => 'opf_field_group' ];
			Assets::register_admin( 'edit.php' );
			$this->assertSame( [], $GLOBALS['opf_script_translations'] );
		}

		public function test_pot_and_jed_catalogs_match_the_registered_domain_and_handle(): void {
			$pot = file_get_contents( OPF_DIR . 'languages/' . self::DOMAIN . '.pot' );
			$this->assertIsString( $pot );
			$this->assertStringContainsString( 'X-Domain: ' . self::DOMAIN, $pot );
			// A builder-only literal, wrapped with the same text domain.
			$this->assertStringContainsString( 'msgid "Duplicate field"', $pot );

			// wp_set_script_translations() looks the catalog up by the script
			// path md5, so the shipped JED has to be named for opf-builder.js.
			$jed_path = OPF_DIR . 'languages/' . self::DOMAIN . '-es_ES-' . md5( 'assets/js/opf-builder.js' ) . '.json';
			$this->assertFileExists( $jed_path );
			$jed = json_decode( (string) file_get_contents( $jed_path ), true );
			$this->assertIsArray( $jed );
			$this->assertSame( 'assets/js/opf-builder.js', $jed['source'] );
			$msgids = $jed['locale_data']['messages'];
			unset( $msgids[''] );
			$this->assertGreaterThanOrEqual( 190, count( $msgids ), 'the builder catalog covers the wrapped builder literals' );
			$this->assertArrayHasKey( 'Duplicate field', $msgids );
			$this->assertNotSame( '', $msgids['Duplicate field'][0] );
		}
	}
}
