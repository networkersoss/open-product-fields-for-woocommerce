<?php
/**
 * Predefined frontend string registry (`window.OPF_I18N`).
 *
 * Script modules cannot use wp_set_script_translations, so Assets::frontend_i18n()
 * ships the translated strings inside the window.OPF_* registry that the
 * frontend scripts read. These tests pin the emitted registry: every key the
 * JavaScript falls back on has to be present with a non-empty string.
 */

namespace {
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $name, $value, ...$args ) { return $value; }
	}
}

namespace OPF\Service {
	if ( ! function_exists( 'OPF\\Service\\wp_enqueue_script' ) ) {
		function wp_enqueue_script( ...$args ): void {}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_enqueue_style' ) ) {
		function wp_enqueue_style( ...$args ): void {}
	}
	if ( ! function_exists( 'OPF\\Service\\wp_print_inline_script_tag' ) ) {
		function wp_print_inline_script_tag( $javascript, $attributes = [] ): void {
			$GLOBALS['opf_inline_scripts'][] = $javascript;
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Service\Assets;
	use PHPUnit\Framework\TestCase;

	final class AssetsI18nRegistryTest extends TestCase {

		/**
		 * Every translatable key the frontend scripts fall back on. `site_locale`
		 * is a locale code, not a translatable string.
		 *
		 * @var array<int,string>
		 */
		private const TRANSLATABLE_KEYS = [
			'choose_at_least_items',
			'choose_no_more_items',
			'select_no_more_options',
			'required_choices_rows',
			'choose_date',
			'choose_a_date',
			'previous_month',
			'next_month',
			'calendar_dates',
			'no_selectable_dates',
			'date_unavailable',
			'remove_row',
			'remove',
			'wait_for_uploads',
			'choose_a_file',
			'file_upload_progress',
			'choose_files_or_drop',
			'remove_file_first',
			'uploading',
			'upload_failed',
			'uploads_unavailable',
			'remove_file',
			'could_not_remove',
			'file_removed',
			'upload_complete',
		];

		private $previous_options;
		private $previous_woocs;

		protected function setUp(): void {
			$this->previous_options = $GLOBALS['opf_test_options'] ?? [];
			$this->previous_woocs   = $GLOBALS['WOOCS'] ?? null;
			$GLOBALS['opf_test_options'] = [ 'opf_theme_compat' => 'no' ];
			unset( $GLOBALS['WOOCS'] );
		}

		protected function tearDown(): void {
			$GLOBALS['opf_test_options'] = $this->previous_options;
			if ( null !== $this->previous_woocs ) {
				$GLOBALS['WOOCS'] = $this->previous_woocs;
			}
			unset( $GLOBALS['opf_inline_scripts'] );
		}

		/**
		 * The registry as the frontend actually receives it.
		 *
		 * @return array<string,string|array<int,string>>
		 */
		private function emitted_registry(): array {
			$GLOBALS['opf_inline_scripts'] = [];
			Assets::enqueue_frontend( [ 'group' => [ [ 'id' => 'note', 'type' => 'text' ] ] ] );
			foreach ( $GLOBALS['opf_inline_scripts'] as $script ) {
				if ( ! str_contains( $script, 'window.OPF_I18N = ' ) ) {
					continue;
				}
				preg_match( '/window\.OPF_I18N = (\{.*\});\s*$/s', $script, $match );
				$registry = json_decode( $match[1], true );
				$this->assertIsArray( $registry );
				return $registry;
			}
			$this->fail( 'enqueue_frontend() must print the window.OPF_I18N registry.' );
		}

		public function test_registry_emits_the_expected_keys_and_strings(): void {
			$registry = $this->emitted_registry();

			$expected = array_merge( self::TRANSLATABLE_KEYS, [ 'site_locale', 'weekday_abbreviations' ] );
			sort( $expected );
			$keys = array_keys( $registry );
			sort( $keys );
			$this->assertSame( $expected, $keys );

			$this->assertSame( 'Choose at least %d items in total.', $registry['choose_at_least_items'] );
			$this->assertSame( 'Select the required choices in every repeated row.', $registry['required_choices_rows'] );
			$this->assertSame( 'Upload failed. Try again.', $registry['upload_failed'] );
			$this->assertSame( 'Remove %s', $registry['remove_file'] );
			$this->assertSame( 'Remove row %d', $registry['remove_row'] );

			// Weekday abbreviations come from WP_Locale and fall back to English
			// when WordPress is not loaded (the unit environment).
			$this->assertCount( 7, $registry['weekday_abbreviations'] );
			foreach ( $registry['weekday_abbreviations'] as $weekday ) {
				$this->assertNotSame( '', trim( $weekday ) );
			}
		}

		public function test_registry_excludes_empty_translatable_strings(): void {
			$registry = $this->emitted_registry();

			foreach ( self::TRANSLATABLE_KEYS as $key ) {
				$this->assertIsString( $registry[ $key ] );
				$this->assertNotSame( '', $registry[ $key ], "window.OPF_I18N['{$key}'] must not be empty" );
			}

			// site_locale is derived from the WordPress locale, never an empty
			// translatable msgid: it is the site locale or an empty string when
			// WordPress is absent.
			$this->assertIsString( $registry['site_locale'] );
			if ( function_exists( 'get_locale' ) ) {
				$this->assertSame( str_replace( '_', '-', (string) get_locale() ), $registry['site_locale'] );
			}
		}
	}
}
