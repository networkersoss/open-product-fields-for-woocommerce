<?php
/**
 * Disposable hook-surface proof for the OPF <-> WPML row (WAPF-LOCALE-WPML).
 *
 * WPML is commercial and absent from this host, so this fixture exercises
 * WpmlIntegration against bin/e2e-wpml-stub.php — a documented contract stub of
 * WPML's public hook surface. It re-proves the checks the 2026-10-03 evidence
 * doc claimed, from hook emission and argument shapes only.
 *
 * Bounds (honest): it cannot prove anything requiring real WPML element
 * records — the real string/element ids WPML assigns, and the real
 * `wpml_object_id` translation-group mapping. The row stays `partial`.
 *
 * Guarded to a /tmp WordPress clone and OPF_WPML_PROOF_ALLOW=1.
 */

global $wpdb;

if ( '1' !== getenv( 'OPF_WPML_PROOF_ALLOW' ) || 0 !== strpos( (string) realpath( ABSPATH ), '/tmp/' ) ) {
	throw new RuntimeException( 'Explicit disposable /tmp authorization required (OPF_WPML_PROOF_ALLOW=1).' );
}

$proof_dir = getenv( 'OPF_WPML_PROOF_DIR' );
if ( ! is_string( $proof_dir ) || '' === $proof_dir ) {
	$proof_dir = defined( 'OPF_FILE' ) ? dirname( constant( 'OPF_FILE' ) ) : '';
}
$stub = rtrim( (string) $proof_dir, '/' ) . '/bin/e2e-wpml-stub.php';
if ( ! is_file( $stub ) ) {
	throw new RuntimeException( 'WPML contract stub not found: ' . $stub );
}
require_once $stub;

$GLOBALS['opf_wpml_results'] = [];

function wpml_check( string $name, bool $pass, string $note = '' ): void {
	$GLOBALS['opf_wpml_results'][] = [ 'name' => $name, 'pass' => $pass, 'note' => $note ];
	printf( "%s %s%s\n", $pass ? 'PASS' : 'FAIL', $name, '' !== $note ? ' — ' . $note : '' );
}

function wpml_skip( string $name, string $reason ): void {
	$GLOBALS['opf_wpml_results'][] = [ 'name' => $name, 'pass' => null, 'note' => $reason ];
	printf( "SKIP %s — %s\n", $name, $reason );
}

/** Fixture group: 8 display strings plus non-display data that must survive. */
function wpml_group(): array {
	return [
		'schema'      => 1,
		'fields'      => [
			[
				'id'          => 'gift',
				'type'        => 'select',
				'label'       => 'Gift wrap',
				'description' => 'Choose wrapping',
				'placeholder' => 'Select',
				'choices'     => [ [ 'slug' => 'red', 'label' => 'Red', 'pricing' => [ 'type' => 'fixed', 'amount' => 5 ] ] ],
				'pricing'     => [ 'type' => 'formula', 'formula' => '[field.width]*2' ],
				'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'other', 'operator' => 'is', 'value' => 'yes' ] ] ] ],
				'repeat'      => [ 'enabled' => true, 'mode' => 'button', 'add' => 'Add', 'del' => 'Remove', 'label' => 'Copy {n}' ],
			],
			[ 'id' => 'info', 'type' => 'paragraph', 'content' => '<b>Information</b>', 'content_format' => 'html' ],
		],
		'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '12' ] ],
			[ 'subject' => 'product_cat', 'operator' => 'not_in', 'terms' => [ '3' ] ],
			[ 'subject' => 'product_tag', 'operator' => 'in', 'terms' => [ '4' ] ],
			[ 'subject' => 'user_role', 'operator' => 'in', 'terms' => [ 'customer' ] ],
		] ] ],
	];
}

function wpml_post_object( array $group, string $status = 'publish', string $title = 'Gift' ): object {
	return (object) [
		'post_type'    => 'opf_field_group',
		'post_status'  => $status,
		'post_title'   => $title,
		'post_content' => wp_json_encode( $group ),
	];
}

function wpml_stub_string_names(): array {
	return array_column( opf_wpml_stub()['strings'], 'name' );
}

function wpml_stub_action( string $name ): ?array {
	foreach ( opf_wpml_stub()['actions'] as $entry ) {
		if ( $entry[0] === $name ) {
			return $entry[1];
		}
	}
	return null;
}

/** First display label of the resolved entry with the given group id. */
function wpml_entry_label( array $entries, int $id ): ?string {
	foreach ( $entries as $entry ) {
		if ( (int) $entry['id'] === $id ) {
			return $entry['group']->data['fields'][0]['label'] ?? null;
		}
	}
	return null;
}

opf_wpml_stub_install();
\OPF\Service\FieldGroups::flush_cache();

$group = wpml_group();
$native_id = 90001;
$native_post = wpml_post_object( $group );

// ---- W1: package kind registration --------------------------------------
$kinds = \OPF\Service\WpmlIntegration::package_kinds( [] );
wpml_check(
	'W1 wpml_active_string_package_kinds registers open-product-fields',
	isset( $kinds['open-product-fields'] ) && 'Open Product Fields' === $kinds['open-product-fields']['title'] && 'open-product-fields' === $kinds['open-product-fields']['slug'] && 'Open Product Fields' === $kinds['open-product-fields']['plural']
);

// ---- W2..W6: saving a native group --------------------------------------
\OPF\Service\WpmlIntegration::register_post( $native_id, $native_post );
$start  = wpml_stub_action( 'wpml_start_string_package_registration' );
$unused = wpml_stub_action( 'wpml_delete_unused_package_strings' );
$strings = opf_wpml_stub()['strings'];
$names  = wpml_stub_string_names();

wpml_check(
	'W2 save starts a string package with the native group id',
	is_array( $start ) && 'Open Product Fields' === $start[0]['kind'] && 'open-product-fields' === $start[0]['kind_slug'] && (string) $native_id === $start[0]['name']
);
wpml_check(
	'W3 package title carries the group title and id',
	is_array( $start ) && 'Gift (#' . $native_id . ')' === $start[0]['title']
);
wpml_check(
	'W4 eight display strings are registered',
	count( $strings ) === 8
);
$expected_names = [
	'field:gift:label',
	'field:gift:description',
	'field:gift:placeholder',
	'field:gift:choice:red',
	'field:gift:repeat:add',
	'field:gift:repeat:del',
	'field:gift:repeat:label',
	'field:info:content',
];
wpml_check(
	'W5 string names/namespaces are the stable display-string keys',
	[] === array_diff( $expected_names, $names ) && [] === array_diff( $names, $expected_names )
);
$types = [];
foreach ( $strings as $string ) {
	$types[ $string['name'] ] = $string['type'];
}
wpml_check(
	'W6 string types map LINE/AREA/VISUAL to the field content',
	'LINE' === $types['field:gift:label'] && 'AREA' === $types['field:gift:description'] && 'VISUAL' === $types['field:info:content'] && 'LINE' === $types['field:gift:choice:red']
);
wpml_check(
	'W7 delete-unused fires after registration',
	is_array( $unused ) && (string) $native_id === $unused[0]['name']
);
wpml_skip(
	'W8 real WPML string/element ids',
	'requires real WPML element records; the contract stub assigns no ids'
);

// ---- W9..W10: deletion lifecycle ----------------------------------------
\OPF\Service\WpmlIntegration::delete_post( $native_id, (object) [ 'post_type' => 'opf_field_group' ] );
$delete = wpml_stub_action( 'wpml_delete_package' );
wpml_check(
	'W9 before_delete_post deletes the package by id and slug',
	is_array( $delete ) && [ (string) $native_id, 'open-product-fields' ] === $delete
);
opf_wpml_stub_reset();
\OPF\Service\WpmlIntegration::delete_post( 90002, (object) [ 'post_type' => 'product' ] );
wpml_check( 'W10 non-opf posts never delete a package', [] === opf_wpml_stub()['actions'] );

// ---- W11..W13: non-opf / auto-draft / imported no-ops --------------------
opf_wpml_stub_reset();
\OPF\Service\WpmlIntegration::register_post( 90003, wpml_post_object( $group, 'auto-draft' ) );
wpml_check( 'W11 auto-draft groups register nothing', [] === opf_wpml_stub()['strings'] && [] === opf_wpml_stub()['actions'] );

foreach ( [ [ '_opf_imported_from' => 'meta:12' ], [ '_opf_archive_import_key' => 'legacy-archive' ] ] as $meta ) {
	opf_wpml_stub_reset();
	$imported_id = 90004 + array_search( $meta, [ [ '_opf_imported_from' => 'meta:12' ], [ '_opf_archive_import_key' => 'legacy-archive' ] ], true );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $imported_id, $key, $value );
	}
	\OPF\Service\WpmlIntegration::register_post( $imported_id, wpml_post_object( $group ) );
	$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $imported_id, 'meta_key' => key( $meta ) ] );
}
wpml_check( 'W12 imported groups are never re-registered as the admin language', [] === opf_wpml_stub()['strings'] && [] === opf_wpml_stub()['actions'] );

opf_wpml_stub_reset();
\OPF\Service\WpmlIntegration::register_post( 90006, (object) [ 'post_type' => 'opf_field_group', 'post_status' => 'publish', 'post_title' => 'Broken', 'post_content' => 'not json' ] );
wpml_check( 'W13 malformed group data registers nothing', [] === opf_wpml_stub()['strings'] );

// ---- W14..W18: runtime translation + immutability ------------------------
$entries = [ [ 'id' => 77777, 'title' => 'Gift', 'lang' => '', 'group' => new \OPF\Engine\FieldGroup( $group ) ] ];
$source  = new \OPF\Engine\FieldGroup( $group );

$GLOBALS['opf_wpml_stub']['current_language'] = 'es';
$GLOBALS['opf_wpml_stub']['translations']['es'] = [
	'field:gift:label'       => 'Embalaje de regalo',
	'field:gift:description' => 'Elige el envoltorio',
	'field:gift:placeholder' => 'Selecciona',
	'field:gift:choice:red'  => 'Rojo',
	'field:gift:repeat:add'  => 'Añadir',
	'field:gift:repeat:del'  => 'Quitar',
	'field:gift:repeat:label' => 'Copia {n}',
	'field:info:content'     => '<b>Información</b>',
];
// Real WPML wpml_object_id returns integer element ids; WpmlIntegration only
// accepts numeric replacements (includes/Service/WpmlIntegration.php).
$GLOBALS['opf_wpml_stub']['object_map']['product:12'] = '15867';
$GLOBALS['opf_wpml_stub']['object_map']['product_cat:3'] = '316';
$GLOBALS['opf_wpml_stub']['object_map']['product_tag:4'] = '41';
$translated = \OPF\Service\WpmlIntegration::translate_groups( $entries );
$translated_group = $translated[0]['group'];

wpml_check(
	'W14 runtime translation returns es display strings',
	'Embalaje de regalo' === $translated_group->data['fields'][0]['label']
	&& 'Elige el envoltorio' === $translated_group->data['fields'][0]['description']
	&& 'Rojo' === $translated_group->data['fields'][0]['choices'][0]['label']
	&& '<b>Información</b>' === $translated_group->data['fields'][1]['content']
);
wpml_check(
	'W15 source group is never mutated',
	'Gift wrap' === $source->data['fields'][0]['label']
	&& 'Red' === $source->data['fields'][0]['choices'][0]['label']
	&& '12' === $source->data['rule_groups'][0]['rules'][0]['terms'][0]
);
wpml_check(
	'W16 field ids, choice slugs, pricing and conditionals are untouched',
	$translated_group->data['fields'][0]['id'] === $source->data['fields'][0]['id']
	&& $translated_group->data['fields'][0]['choices'][0]['slug'] === $source->data['fields'][0]['choices'][0]['slug']
	&& $translated_group->data['fields'][0]['pricing'] === $source->data['fields'][0]['pricing']
	&& $translated_group->data['fields'][0]['conditionals'] === $source->data['fields'][0]['conditionals']
);
$object_calls = opf_wpml_stub()['object_calls'];
wpml_check(
	'W17 wpml_object_id is called with (term, subject, true, language) for product targets only',
	[
		[ 12, 'product', true, 'es' ],
		[ 3, 'product_cat', true, 'es' ],
		[ 4, 'product_tag', true, 'es' ],
	] === $object_calls
);
wpml_check(
	'W18 object-id results replace the placement terms',
	'15867' === $translated_group->data['rule_groups'][0]['rules'][0]['terms'][0]
	&& '316' === $translated_group->data['rule_groups'][0]['rules'][1]['terms'][0]
	&& '41' === $translated_group->data['rule_groups'][0]['rules'][2]['terms'][0]
	&& [ 'customer' ] === $translated_group->data['rule_groups'][0]['rules'][3]['terms']
);
wpml_skip(
	'W19 real wpml_object_id translation-group mapping',
	'requires real WPML translation groups; the stub returns fixture ids'
);

// ---- W20..W21: current-language handling --------------------------------
$unchanged = true;
foreach ( [ 'all', '', null ] as $language ) {
	$GLOBALS['opf_wpml_stub']['current_language'] = $language;
	$result = \OPF\Service\WpmlIntegration::translate_groups( $entries );
	$unchanged = $unchanged && $result === $entries && 'Gift wrap' === $result[0]['group']->data['fields'][0]['label'];
}
wpml_check( 'W20 empty/all current language leaves entries unchanged', $unchanged );

// ---- W22..W23: cache invalidation + language switch ----------------------
$GLOBALS['opf_wpml_stub']['current_language'] = 'es';
// for_product() resolves published posts, so the freshness check needs a real
// group row. The synthetic $group carries product rules a bare WC_Product
// (id 0) cannot match, so the fixture drops them to stay placement-neutral.
$w22_group = $group;
$w22_group['rule_groups'] = [];
$w22_id = (int) wp_insert_post(
	[
		'post_type'    => 'opf_field_group',
		'post_status'  => 'publish',
		'post_title'   => 'W22 Gift',
		'post_content' => wp_slash( wp_json_encode( $w22_group ) ),
	]
);
\OPF\Service\FieldGroups::flush_cache();
$GLOBALS['opf_wpml_stub']['translations']['es']['field:gift:label'] = 'Embalaje A';
$first = wpml_entry_label( \OPF\Service\FieldGroups::for_product( new \WC_Product() ), $w22_id );
$GLOBALS['opf_wpml_stub']['translations']['es']['field:gift:label'] = 'Embalaje B';
do_action( 'wpml_switch_language', 'es' );
$second = wpml_entry_label( \OPF\Service\FieldGroups::for_product( new \WC_Product() ), $w22_id );
wpml_check(
	'W21 wpml_switch_language is wired to flush the resolution cache',
	100 === call_user_func( 'has_action', 'wpml_switch_language', [ \OPF\Service\FieldGroups::class, 'flush_cache' ] )
);
wpml_check(
	'W22 a language switch yields fresh translated labels',
	'Embalaje A' === $first && 'Embalaje B' === $second
);

// ---- W23..W25: imported-group language ownership -------------------------
$imported_id = 90010;
update_post_meta( $imported_id, '_opf_imported_from', 'meta:12' );
update_post_meta( $imported_id, '_opf_wpml_source_language', 'en' );
$imported_entries = [ [ 'id' => $imported_id, 'title' => 'Imported', 'lang' => '', 'group' => new \OPF\Engine\FieldGroup( $group ) ] ];

$GLOBALS['opf_wpml_stub']['element_languages']['product:12'] = 'fr';
$GLOBALS['opf_wpml_stub']['element_languages']['wapf_product:34'] = 'en';
wpml_check(
	'W23 source_language reads WPML element records, never the admin language',
	'fr' === \OPF\Service\WpmlIntegration::source_language( 12, 'product' )
	&& 'en' === \OPF\Service\WpmlIntegration::source_language( 34, 'wapf_product' )
	&& '' === \OPF\Service\WpmlIntegration::source_language( 12, 'attachment' )
);
$GLOBALS['opf_wpml_stub']['current_language'] = 'fr';
$filtered = \OPF\Service\WpmlIntegration::translate_groups( $imported_entries );
$GLOBALS['opf_wpml_stub']['current_language'] = 'en';
$owned = \OPF\Service\WpmlIntegration::translate_groups( $imported_entries );
wpml_check(
	'W24 owned imports render once, in their own language, with original labels',
	[] === $filtered && $owned === $imported_entries && 'Gift wrap' === $owned[0]['group']->data['fields'][0]['label']
);
// delete_post_meta(), not a raw $wpdb->delete: a raw delete leaves the post-meta
// object cache populated, so is_imported() would still read stale ownership.
\delete_post_meta( $imported_id, '_opf_imported_from' );
\delete_post_meta( $imported_id, '_opf_wpml_source_language' );
$GLOBALS['opf_wpml_stub']['current_language'] = 'fr';
$unowned = \OPF\Service\WpmlIntegration::translate_groups( [ [ 'id' => $imported_id, 'title' => 'Imported', 'lang' => '', 'group' => new \OPF\Engine\FieldGroup( $group ) ] ] );
wpml_check(
	'W25 imports without ownership are never guessed at',
	'Gift wrap' === $unowned[0]['group']->data['fields'][0]['label']
);
wpml_skip(
	'W26 real WPML element record for imported ownership',
	'requires real WPML element records; the stub supplies fixture languages'
);

// ---- W27: stable string names under field reordering ---------------------
$reordered = $group;
$reordered['fields'] = array_reverse( $reordered['fields'] );
$reordered_names = [];
\OPF\Service\WpmlIntegration::map_text( $reordered, static function ( $text, $name ) use ( &$reordered_names ) {
	$reordered_names[] = $name;
	return $text;
} );
wpml_check( 'W27 string names survive field reordering', [] === array_diff( $expected_names, $reordered_names ) && [] === array_diff( $reordered_names, $expected_names ) );

// ---- cleanup -------------------------------------------------------------
foreach ( [ 90001, 90002, 90003, 90004, 90005, 90006, 90010 ] as $id ) {
	$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $id ] );
}
if ( isset( $w22_id ) ) {
	\wp_delete_post( $w22_id, true );
}
opf_wpml_stub_reset();

$results  = $GLOBALS['opf_wpml_results'];
$failures = array_filter( $results, static fn( $r ) => false === $r['pass'] );
$skipped  = array_filter( $results, static fn( $r ) => null === $r['pass'] );
$passed   = count( $results ) - count( $failures ) - count( $skipped );
$artifact = getenv( 'OPF_WPML_PROOF_ARTIFACT_DIR' );
$summary  = [
	'row'      => 'WAPF-LOCALE-WPML',
	'plugin'   => 'WPML String Translation (contract stub — WPML is not installed)',
	'passed'   => $passed,
	'failed'   => count( $failures ),
	'skipped'  => count( $skipped ),
	'checks'   => $results,
	'cleanup'  => [ 'removed_meta_for_posts' => [ 90001, 90002, 90003, 90004, 90005, 90006, 90010 ] ],
];
if ( is_string( $artifact ) && '' !== $artifact && is_dir( $artifact ) ) {
	file_put_contents( $artifact . '/wpml-proof.json', wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );
}

echo "\n{$passed} passed, " . count( $failures ) . ' failed, ' . count( $skipped ) . " unprovable without real WPML\n";
if ( $failures ) {
	throw new RuntimeException( 'WPML proof checks failed: ' . implode( ', ', array_column( $failures, 'name' ) ) );
}
