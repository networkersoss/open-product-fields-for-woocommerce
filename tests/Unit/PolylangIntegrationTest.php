<?php
/**
 * Polylang integration parity tests.
 *
 * Polylang is optional and not installed in the unit environment, so its public
 * API is shimmed in-process (same style as WpmlIntegrationTest): the shims read
 * `$GLOBALS['opf_pll_*']` registries and report "no language" unless a test sets
 * one, which keeps the suite's Polylang-absent behaviour intact.
 */

namespace {
	if ( ! function_exists( 'pll_current_language' ) ) {
		/**
		 * Mirrors Polylang: slug by default, locale for `locale`, false when no
		 * language is current.
		 *
		 * @param string $field 'slug' (default) or 'locale'.
		 * @return string|false
		 */
		function pll_current_language( $field = 'slug' ) {
			$current = $GLOBALS['opf_pll_current'] ?? null;
			if ( ! is_array( $current ) ) {
				return false;
			}
			return 'locale' === $field ? ( $current['locale'] ?? false ) : ( $current['slug'] ?? false );
		}
	}
	if ( ! function_exists( 'pll_get_post_language' ) ) {
		/**
		 * @return string|false
		 */
		function pll_get_post_language( $post_id, $field = 'slug' ) {
			return $GLOBALS['opf_pll_post_languages'][ (int) $post_id ] ?? false;
		}
	}
	if ( ! function_exists( 'pll_set_post_language' ) ) {
		function pll_set_post_language( $post_id, $language ) {
			$GLOBALS['opf_pll_set_calls'][]                   = [ (int) $post_id, (string) $language ];
			$GLOBALS['opf_pll_post_languages'][ (int) $post_id ] = (string) $language;
			return true;
		}
	}
}

namespace OPF\Tests\Unit {
	use OPF\Service\FieldGroups;
	use OPF\Service\Importer;
	use PHPUnit\Framework\TestCase;

	final class PolylangIntegrationTest extends TestCase {
		protected function setUp(): void {
			// Self-sufficient shims: required here (not at load time) so the
			// guarded declarations cannot clash with sibling test files' shims.
			require_once dirname( __DIR__ ) . '/fixtures/polylang-integration-contract.php';
			$GLOBALS['opf_pll_current']         = [];
			$GLOBALS['opf_pll_post_languages']  = [];
			$GLOBALS['opf_pll_set_calls']       = [];
			$GLOBALS['opf_woocs_meta']          = [];
			$GLOBALS['opf_wpml_filters']        = [];
			$GLOBALS['opf_woocs_hooks']         = [];
			$GLOBALS['opf_auth_test_posts']     = [];
			$GLOBALS['opf_auth_test_cache']     = [];
			$GLOBALS['opf_import_saved_posts']  = [];
			FieldGroups::flush_cache();
		}

		protected function tearDown(): void {
			FieldGroups::flush_cache();
			unset(
				$GLOBALS['opf_pll_current'],
				$GLOBALS['opf_pll_post_languages'],
				$GLOBALS['opf_pll_set_calls'],
				$GLOBALS['opf_woocs_meta'],
				$GLOBALS['opf_wpml_filters'],
				$GLOBALS['opf_woocs_hooks'],
				$GLOBALS['opf_auth_test_posts'],
				$GLOBALS['opf_auth_test_cache'],
				$GLOBALS['opf_import_saved_posts']
			);
		}

		/** P1: the field-group CPT is registered with Polylang. */
		public function test_pll_get_post_types_registers_the_field_group_cpt(): void {
			FieldGroups::init();
			$this->assertSame(
				[ [ FieldGroups::class, 'add_cpt_to_polylang' ], 10, 2 ],
				$GLOBALS['opf_woocs_hooks']['pll_get_post_types'] ?? null
			);
			$this->assertSame(
				[ 'post' => 'post', 'opf_field_group' => 'opf_field_group' ],
				FieldGroups::add_cpt_to_polylang( [ 'post' => 'post' ], false )
			);
		}

		/** P2: the Polylang settings screen list must not expose the CPT. */
		public function test_settings_screen_list_excludes_the_group_cpt(): void {
			$this->assertSame(
				[ 'page' => 'page' ],
				FieldGroups::add_cpt_to_polylang( [ 'page' => 'page', 'opf_field_group' => 'opf_field_group' ], true )
			);
			$this->assertArrayHasKey( 'opf_field_group', FieldGroups::add_cpt_to_polylang( [], false ) );
		}

		/** P3/P4/P5: per-language rendering plus language-less groups everywhere. */
		public function test_groups_render_only_in_their_own_language_and_language_less_groups_everywhere(): void {
			$GLOBALS['opf_auth_test_posts']    = [
				$this->post( 7, 'English', 'English label' ),
				$this->post( 8, 'Spanish', 'Etiqueta española' ),
				$this->post( 9, 'Neutral', 'Neutral label' ),
			];
			$GLOBALS['opf_pll_post_languages'] = [ 7 => 'en', 8 => 'es' ];

			$GLOBALS['opf_pll_current'] = [ 'slug' => 'es', 'locale' => 'es_ES' ];
			$spanish = FieldGroups::for_product( new \WC_Product() );
			$this->assertSame( [ 8, 9 ], array_column( $spanish, 'id' ) );
			$this->assertSame( 'Etiqueta española', $spanish[0]['group']->data['fields'][0]['label'] );
			$this->assertSame( [ 'es', '' ], array_column( $spanish, 'lang' ) );

			$GLOBALS['opf_pll_current'] = [ 'slug' => 'en', 'locale' => 'en_US' ];
			$english = FieldGroups::for_product( new \WC_Product() );
			$this->assertSame( [ 7, 9 ], array_column( $english, 'id' ) );
			$this->assertSame( 'English label', $english[0]['group']->data['fields'][0]['label'] );
			$this->assertSame( [ 'en', '' ], array_column( $english, 'lang' ) );
		}

		/** P6/P7: resolution is cached per language slug and refreshed after a save. */
		public function test_resolution_cache_is_scoped_per_language_slug_and_refreshed_after_save(): void {
			$GLOBALS['opf_auth_test_posts']    = [
				$this->post( 7, 'English', 'English label' ),
				$this->post( 8, 'Spanish', 'Etiqueta' ),
			];
			$GLOBALS['opf_pll_post_languages'] = [ 7 => 'en', 8 => 'es' ];

			$GLOBALS['opf_pll_current'] = [ 'slug' => 'es', 'locale' => 'es_ES' ];
			$this->assertSame( [ 8 ], array_column( FieldGroups::for_product( new \WC_Product() ), 'id' ) );

			$GLOBALS['opf_pll_current'] = [ 'slug' => 'en', 'locale' => 'en_US' ];
			$this->assertSame( [ 7 ], array_column( FieldGroups::for_product( new \WC_Product() ), 'id' ) );

			$GLOBALS['opf_pll_current'] = [ 'slug' => 'es', 'locale' => 'es_ES' ];
			$this->assertSame( [ 8 ], array_column( FieldGroups::for_product( new \WC_Product() ), 'id' ) );

			// A re-save flushes the request cache; the new label is visible at once.
			$GLOBALS['opf_auth_test_posts'][1] = $this->post( 8, 'Spanish', 'Etiqueta actualizada' );
			FieldGroups::flush_cache();
			$refreshed = FieldGroups::for_product( new \WC_Product() );
			$this->assertSame( 'Etiqueta actualizada', $refreshed[0]['group']->data['fields'][0]['label'] );
		}

		/** P8/P9: `lang` is equality, `!lang` is inequality (not_in). */
		public function test_language_placement_uses_equality_and_its_negation_uses_not_in(): void {
			$language    = [ [ 'rules' => [ [ 'subject' => 'user_language', 'operator' => 'in', 'terms' => [ 'es_ES' ] ] ] ] ];
			$not_language = [ [ 'rules' => [ [ 'subject' => 'user_language', 'operator' => 'not_in', 'terms' => [ 'es_ES' ] ] ] ] ];
			$GLOBALS['opf_auth_test_posts'] = [
				$this->post( 7, 'lang', 'Language', $language ),
				$this->post( 8, '!lang', 'Not language', $not_language ),
			];

			$GLOBALS['opf_pll_current'] = [ 'slug' => 'es', 'locale' => 'es_ES' ];
			$this->assertSame( [ 7 ], array_column( FieldGroups::for_product( new \WC_Product() ), 'id' ) );

			$GLOBALS['opf_pll_current'] = [ 'slug' => 'en', 'locale' => 'en_US' ];
			$this->assertSame( [ 8 ], array_column( FieldGroups::for_product( new \WC_Product() ), 'id' ) );
		}

		/** P10: the Polylang locale wins over the WPML current-language filter. */
		public function test_polylang_locale_takes_precedence_over_the_wpml_current_language_filter(): void {
			$GLOBALS['opf_wpml_filters']['wpml_current_language'] = static fn() => 'en_US';
			$GLOBALS['opf_pll_current'] = [ 'slug' => 'es', 'locale' => 'es_ES' ];
			$GLOBALS['opf_auth_test_posts'] = [
				$this->post( 7, 'Spanish', 'Language', [ [ 'rules' => [ [ 'subject' => 'user_language', 'operator' => 'in', 'terms' => [ 'es_ES' ] ] ] ] ] ),
			];
			$this->assertSame( [ 7 ], array_column( FieldGroups::for_product( new \WC_Product() ), 'id' ) );
		}

		/** P11: a global import keeps the source group's Polylang language. */
		public function test_import_preserves_the_source_groups_polylang_language(): void {
			$GLOBALS['opf_pll_post_languages'] = [ 12 => 'fr' ];
			$result = $this->import( 12 );
			$this->assertSame( 'imported', $result['result'] );
			$this->assertSame( [ [ $result['opf_id'], 'fr' ] ], $GLOBALS['opf_pll_set_calls'] );
			$this->assertSame( 'fr', $GLOBALS['opf_pll_post_languages'][ $result['opf_id'] ] );
		}

		/**
		 * P11 boundary, observed in the implementation: language ownership is
		 * only carried for global `wapf_product` sources. Dry runs, product-local
		 * `meta:<id>` imports and language-less sources must not write a language.
		 */
		public function test_import_language_ownership_is_limited_to_global_group_sources(): void {
			$GLOBALS['opf_pll_post_languages'] = [ 12 => 'fr', 42 => 'de' ];
			$field = [ [ 'id' => 'note', 'type' => 'text', 'label' => 'Note' ] ];

			$this->import( 12, false );
			$this->assertSame( [], $GLOBALS['opf_pll_set_calls'] );

			$this->import( 'meta:42' );
			$this->assertSame( [], $GLOBALS['opf_pll_set_calls'] );

			$this->import( 99 );
			$this->assertSame( [], $GLOBALS['opf_pll_set_calls'] );
		}

		private function import( $source, bool $commit = true ): array {
			$method = new \ReflectionMethod( Importer::class, 'import_group' );
			return $method->invoke(
				null,
				[ 'fields' => [ [ 'id' => 'note', 'type' => 'text', 'label' => 'Note' ] ] ],
				'Imported',
				$source,
				$commit
			);
		}

		private function post( int $id, string $title, string $label, array $rule_groups = [] ): \WP_Post {
			$data = [
				'fields'      => [ [ 'id' => 'note', 'type' => 'text', 'label' => $label ] ],
				'rule_groups' => $rule_groups,
			];
			return new \WP_Post( $id, $title, json_encode( $data ) );
		}
	}
}
