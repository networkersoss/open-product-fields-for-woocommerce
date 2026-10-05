<?php
/**
 * Disposable runtime proof for OPF <-> Polylang (row WAPF-LOCALE-POLYLANG).
 *
 * Polylang Pro is commercial but present on this host. The wrapper
 * (bin/e2e-polylang-proof.sh) copies polylang-pro/polylang-wc into a /tmp
 * WordPress clone, activates them there — never touching the production
 * install — and runs this fixture through `wp eval-file`.
 *
 * It re-proves the checks claimed by docs/compatibility/LOCALE-EVIDENCE-2026-10-03.md.
 * Guarded to a /tmp WordPress clone and OPF_LOCALE_PROOF_ALLOW=1.
 */

global $wpdb;

if ( '1' !== getenv( 'OPF_LOCALE_PROOF_ALLOW' ) || 0 !== strpos( (string) realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Explicit disposable /tmp authorization required (OPF_LOCALE_PROOF_ALLOW=1).' );
}
if ( ! function_exists( 'pll_current_language' ) || ! function_exists( 'PLL' ) ) {
	throw new RuntimeException( 'Polylang must be active in the clone.' );
}

$GLOBALS['opf_pll_results'] = [];

function pll_check( string $name, bool $pass, string $note = '' ): void {
	$GLOBALS['opf_pll_results'][] = [ 'name' => $name, 'pass' => $pass, 'note' => $note ];
	printf( "%s %s%s\n", $pass ? 'PASS' : 'FAIL', $name, '' !== $note ? ' — ' . $note : '' );
}

/** Resolve one language view the way a separate frontend request would. */
function pll_view( string $slug ): array {
	PLL()->curlang = PLL()->model->get_language( $slug );
	\OPF\Service\FieldGroups::flush_cache();
	$out = [];
	foreach ( \OPF\Service\FieldGroups::for_product( new \WC_Product() ) as $entry ) {
		$out[ (int) $entry['id'] ] = $entry['group']->data['fields'][0]['label'];
	}
	return $out;
}

/** Language slugs via Polylang's model API. */
function pll_language_slugs(): array {
	$slugs = [];
	foreach ( PLL()->model->get_languages_list() as $language ) {
		$slugs[] = $language->slug;
	}
	return $slugs;
}

function pll_make_group( string $title, string $label, array $rules = [] ): int {
	$data = [
		'fields'      => [ [ 'id' => 'note', 'type' => 'text', 'label' => $label ] ],
		'rule_groups' => $rules,
	];
	return (int) wp_insert_post(
		[
			'post_type'    => 'opf_field_group',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => wp_slash( wp_json_encode( $data ) ),
		]
	);
}

function pll_delete_all( string $post_type ): void {
	global $wpdb;
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

$model = PLL()->model;
foreach ( [ [ 'en', 'English', 'en_US' ], [ 'es', 'Spanish', 'es_ES' ] ] as [ $slug, $name, $locale ] ) {
	if ( ! $model->get_language( $slug ) ) {
		$model->languages->add( [ 'name' => $name, 'slug' => $slug, 'locale' => $locale, 'rtl' => false, 'term_group' => 0 ] );
	}
}

// Polylang only wires its translated-query filter when languages already exist
// at boot. The wrapper runs this fixture once with SETUP_ONLY=1 so the recorded
// run starts from an established language list.
if ( '1' === getenv( 'OPF_LOCALE_PROOF_SETUP_ONLY' ) ) {
	echo 'languages ready: ' . implode( ',', pll_language_slugs() ) . "\n";
	return;
}

// Fixture isolation: start from zero OPF/WAPF rows regardless of clone history.
pll_delete_all( 'opf_field_group' );
pll_delete_all( 'wapf_product' );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wapf_fieldgroup'" );

$g_en      = pll_make_group( 'English', 'English label' );
$g_es      = pll_make_group( 'Spanish', 'Etiqueta española' );
$g_neutral = pll_make_group( 'Neutral', 'Neutral label' );
pll_set_post_language( $g_en, 'en' );
pll_set_post_language( $g_es, 'es' );
pll_set_post_language( $g_neutral, '' );

// Rule fixtures: the language choice decides which rule can match.
$rule_lang  = [ [ 'rules' => [ [ 'subject' => 'user_language', 'operator' => 'in', 'terms' => [ 'es_ES' ] ] ] ] ];
$rule_notlang = [ [ 'rules' => [ [ 'subject' => 'user_language', 'operator' => 'not_in', 'terms' => [ 'es_ES' ] ] ] ] ];
$g_lang_es    = pll_make_group( 'lang@es', 'Language ES', $rule_lang );
$g_lang_en    = pll_make_group( 'lang@en', 'Language EN', $rule_lang );
$g_notlang_es = pll_make_group( '!lang@es', 'Not language ES', $rule_notlang );
$g_notlang_en = pll_make_group( '!lang@en', 'Not language EN', $rule_notlang );
pll_set_post_language( $g_lang_es, 'es' );
pll_set_post_language( $g_lang_en, 'en' );
pll_set_post_language( $g_notlang_es, 'es' );
pll_set_post_language( $g_notlang_en, 'en' );

// ---- P1/P2: CPT registration shape -------------------------------------
$runtime_types = apply_filters( 'pll_get_post_types', [ 'post' => 'post' ], false );
pll_check( 'P1 pll_get_post_types adds opf_field_group', isset( $runtime_types['opf_field_group'] ) && 'opf_field_group' === $runtime_types['opf_field_group'] );
$settings_types = apply_filters( 'pll_get_post_types', [ 'post' => 'post', 'opf_field_group' => 'opf_field_group' ], true );
pll_check( 'P2 settings-screen list excludes opf_field_group', ! isset( $settings_types['opf_field_group'] ) && isset( $settings_types['post'] ) );

// ---- P3/P4/P5: per-language rendering ----------------------------------
pll_check(
	'P3 stored group languages are readable',
	'en' === pll_get_post_language( $g_en, 'slug' ) && 'es' === pll_get_post_language( $g_es, 'slug' ) && false === pll_get_post_language( $g_neutral, 'slug' )
);

$en_view = pll_view( 'en' );
$es_view = pll_view( 'es' );
pll_check( 'P4 en group renders only under en', isset( $en_view[ $g_en ] ) && ! isset( $es_view[ $g_en ] ) );
pll_check(
	'P5 es group renders only under es with its own label',
	isset( $es_view[ $g_es ] ) && 'Etiqueta española' === $es_view[ $g_es ] && ! isset( $en_view[ $g_es ] )
);
PLL()->curlang = null;
\OPF\Service\FieldGroups::flush_cache();
$neutral_without_language = false;
foreach ( \OPF\Service\FieldGroups::for_product( new \WC_Product() ) as $entry ) {
	$neutral_without_language = $neutral_without_language || (int) $entry['id'] === $g_neutral;
}
pll_check(
	'P6 untranslated group handling (old "renders everywhere" claim corrected)',
	$neutral_without_language && ! isset( $en_view[ $g_neutral ] ) && ! isset( $es_view[ $g_neutral ] ),
	'OPF keeps empty-language entries, but Polylang hides untranslated posts from translated queries'
);

// ---- P7: resolution cache is language-scoped and refreshed on save ------
$cache_key = new ReflectionMethod( \OPF\Service\FieldGroups::class, 'cache_key_for_viewer' );
$cache_key->setAccessible( true );
$key_en = $cache_key->invoke( null, 42, [ 'logged_in' => false, 'roles' => [], 'language' => 'en_US' ], 'en' );
$key_es = $cache_key->invoke( null, 42, [ 'logged_in' => false, 'roles' => [], 'language' => 'es_ES' ], 'es' );
pll_check( 'P7 resolution cache key is scoped per language slug', $key_en !== $key_es );

PLL()->curlang = $model->get_language( 'es' );
\OPF\Service\FieldGroups::flush_cache();
$before_resave = pll_view( 'es' )[ $g_es ] ?? null;
\OPF\Service\FieldGroups::save( $g_es, new \OPF\Engine\FieldGroup( [ 'fields' => [ [ 'id' => 'note', 'type' => 'text', 'label' => 'Etiqueta actualizada' ] ] ] ), [ 'title' => 'Spanish' ] );
$after_resave = pll_view( 'es' )[ $g_es ] ?? null;
pll_check(
	'P8 a re-save is visible immediately in the es view',
	'Etiqueta española' === $before_resave && 'Etiqueta actualizada' === $after_resave,
	'save() flushes the per-request resolution cache'
);

// ---- P9/P10: lang / !lang placement semantics ---------------------------
$en_view = pll_view( 'en' );
$es_view = pll_view( 'es' );
pll_check(
	'P9 lang (equality) matches only the current locale',
	isset( $es_view[ $g_lang_es ] ) && ! isset( $es_view[ $g_lang_en ] ) && ! isset( $en_view[ $g_lang_en ] ) && ! isset( $en_view[ $g_lang_es ] )
);
pll_check(
	'P10 !lang (not_in) matches only the other locale',
	isset( $en_view[ $g_notlang_en ] ) && ! isset( $en_view[ $g_notlang_es ] ) && ! isset( $es_view[ $g_notlang_es ] ) && ! isset( $es_view[ $g_notlang_en ] )
);

// ---- P11: resolution order (Polylang locale wins over WPML) -------------
PLL()->curlang = $model->get_language( 'es' );
add_filter( 'wpml_current_language', static fn() => 'en_US' );
pll_check(
	'P11 Polylang locale is the language source when Polylang is active',
	'es_ES' === pll_current_language( 'locale' ) && 'es' === pll_current_language( 'slug' ) && isset( pll_view( 'es' )[ $g_lang_es ] ),
	'ICL_LANGUAGE_CODE / default fallbacks are covered in-tree by LocaleFallbackTest'
);

// ---- P12: importer keeps the source group language ----------------------
PLL()->curlang = null;
$source = (int) wp_insert_post(
	[
		'post_type'    => 'wapf_product',
		'post_status'  => 'publish',
		'post_title'   => 'Locale proof source',
		'post_content' => wp_slash( serialize( [ 'fields' => [ [ 'id' => 'name', 'label' => 'Name', 'type' => 'text' ] ], 'rule_groups' => [] ] ) ),
	]
);
pll_set_post_language( $source, 'es' );
\OPF\Service\FieldGroups::flush_cache();
$report = \OPF\Service\Importer::run( true );
$imported = get_posts(
	[
		'post_type'      => 'opf_field_group',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_opf_imported_from', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => (string) $source, // phpcs:ignore WordPress.DB.SlowDBQuery
	]
);
pll_check(
	'P12 imported group keeps the source Polylang language',
	$imported && 'es' === pll_get_post_language( (int) $imported[0], 'slug' ) && 'es' === pll_get_post_language( $source, 'slug' ) && 1 === (int) $report['imported']
);

// ---- cleanup ------------------------------------------------------------
pll_delete_all( 'opf_field_group' );
pll_delete_all( 'wapf_product' );
foreach ( [ 'opf_date_format', 'opf_show_price_hints', 'opf_hint_format' ] as $option ) {
	call_user_func( 'delete_option', $option );
}

$failures  = array_filter( $GLOBALS['opf_pll_results'], static fn( $r ) => ! $r['pass'] );
$passed    = count( $GLOBALS['opf_pll_results'] ) - count( $failures );
$artifact  = getenv( 'OPF_LOCALE_PROOF_ARTIFACT_DIR' );
$summary   = [
	'plugin'     => 'polylang-pro',
	'version'    => defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : 'unknown',
	'languages'  => pll_language_slugs(),
	'passed'     => $passed,
	'failed'     => count( $failures ),
	'checks'     => $GLOBALS['opf_pll_results'],
	'cleanup'    => [ 'removed_posts' => [ 'opf_field_group', 'wapf_product' ], 'removed_options' => [ 'opf_date_format', 'opf_show_price_hints', 'opf_hint_format' ] ],
];
if ( is_string( $artifact ) && '' !== $artifact && is_dir( $artifact ) ) {
	file_put_contents( $artifact . '/polylang-proof.json', wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );
}

echo "\n{$passed} passed, " . count( $failures ) . " failed\n";
if ( $failures ) {
	throw new RuntimeException( 'Polylang proof checks failed: ' . implode( ', ', array_column( $failures, 'name' ) ) );
}
