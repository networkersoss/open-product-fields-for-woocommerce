<?php
/**
 * Cross-site linked-child remap proof, destination side. Destination clone only.
 *
 * Creates destination products with the SAME slugs as the source choices but new
 * IDs, imports the OPF archive, and records whether any child product ID was
 * remapped. Also runs WAPF Extended 3.1.5's own parser over the WAPF Tools
 * payload to record its policy.
 *
 * Env: OPF_REMAP_ALLOW=1, OPF_CHILD_CUR_ARTIFACT_DIR, OPF_REMAP_DEST_CLONE.
 */
$dest = getenv( 'OPF_REMAP_DEST_CLONE' ) ?: '/tmp/opf-child-remap-wp';
if ( '1' !== getenv( 'OPF_REMAP_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), $dest ) ) {
	throw new RuntimeException( 'Explicit isolated destination clone required.' );
}
$dir = getenv( 'OPF_CHILD_CUR_ARTIFACT_DIR' ) ?: '/tmp/opf-child-currency-evidence';

$source = json_decode( file_get_contents( $dir . '/source-choices.json' ), true );
$archive = \OPF\Service\ArchiveImporter::decode( file_get_contents( $dir . '/group-archive.json' ) );
$source_ids  = array_map( static fn ( $c ) => (int) $c['product_id'], $source['choices'] );
$source_slugs = array_map( static fn ( $c ) => (string) $c['slug'], $source['choices'] );

// Destination: same slugs, brand new IDs.
$dest_products = [];
foreach ( $source['choices'] as $choice ) {
	$p = new \WC_Product_Simple();
	$p->set_name( 'Dest ' . $choice['slug'] );
	$p->set_slug( $choice['slug'] );
	$p->set_status( 'publish' );
	$p->set_regular_price( '9' );
	$p->save();
	$dest_products[ $choice['slug'] ] = $p->get_id();
}
$dest_parent = new \WC_Product_Simple();
$dest_parent->set_name( 'Dest parent' );
$dest_parent->set_slug( 'dest-remap-parent' );
$dest_parent->set_status( 'publish' );
$dest_parent->set_regular_price( '20' );
$dest_parent->save();
$dest_ids = array_values( $dest_products );

$report = \OPF\Service\ArchiveImporter::import( $archive, true );
$imported_id = (int) ( $report['groups'][0]['opf_id'] ?? 0 );
$imported_post = $imported_id ? \get_post( $imported_id ) : null;
$imported_group = $imported_id ? \OPF\Service\FieldGroups::group_from_post( $imported_post ) : null;
$imported_choices = [];
$imported_field = null;
if ( $imported_group ) {
	foreach ( $imported_group->data['fields'] as $field ) {
		if ( 'products' !== ( $field['type'] ?? '' ) ) {
			continue;
		}
		$imported_field = $field;
		foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
			$imported_choices[] = [
				'slug'       => (string) ( $choice['slug'] ?? '' ),
				'product_id' => (int) ( $choice['product_id'] ?? 0 ),
				'exists'     => (bool) \wc_get_product( (int) ( $choice['product_id'] ?? 0 ) ),
			];
		}
	}
}
$imported_ids = array_map( static fn ( $c ) => (int) $c['product_id'], $imported_choices );

// Can the imported field resolve any child on the destination?
$resolved = [];
if ( $imported_field ) {
	foreach ( \OPF\Service\LinkedProducts::product_choices( $imported_field, $dest_parent ) as $choice ) {
		$resolved[] = $choice['slug'] ?? '';
	}
}

// WAPF Extended 3.1.5 parser policy over the same payload.
$wapf = [ 'available' => class_exists( '\SW_WAPF_PRO\Includes\Classes\Field_Groups' ), 'choices' => [] ];
if ( $wapf['available'] ) {
	$raw = json_decode( file_get_contents( $dir . '/wapf-tools.json' ), true );
	$raw['id']   = 'opf-remap-test';
	$raw['type'] = 'wapf_fieldgroup';
	$fg = \call_user_func( [ 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups', 'raw_json_to_field_group' ], $raw );
	if ( $fg && isset( $fg->fields[0]->options['choices'] ) ) {
		foreach ( $fg->fields[0]->options['choices'] as $choice ) {
			$wapf['choices'][] = [ 'slug' => (string) ( $choice['slug'] ?? '' ), 'id' => (int) ( $choice['id'] ?? 0 ) ];
		}
	}
}

$out = [
	'source_choices'   => $source['choices'],
	'destination_products' => $dest_products,
	'import'           => $report,
	'imported_group'   => [
		'id'          => $imported_id,
		'status'      => $imported_post ? $imported_post->post_status : null,
		'needs_review' => $imported_id ? \get_post_meta( $imported_id, '_opf_needs_review', true ) : null,
		'choices'     => $imported_choices,
	],
	'imported_ids_equal_source' => $imported_ids === $source_ids,
	'imported_ids_equal_destination' => $imported_ids === $dest_ids,
	'remapped'         => $imported_ids !== $source_ids,
	'resolved_children_on_destination' => $resolved,
	'wapf'             => $wapf,
];
file_put_contents( $dir . '/remap-import.json', \wp_json_encode( $out, JSON_PRETTY_PRINT ) );
echo 'source ids:      ' . \wp_json_encode( $source_ids ) . "\n";
echo 'dest ids:        ' . \wp_json_encode( $dest_ids ) . "\n";
echo 'imported ids:    ' . \wp_json_encode( $imported_ids ) . "\n";
echo 'remapped:        ' . ( $out['remapped'] ? 'yes' : 'no' ) . ' | status=' . ( $out['imported_group']['status'] ?? '?' ) . "\n";
echo 'needs_review:    ' . \wp_json_encode( $out['imported_group']['needs_review'] ) . "\n";
echo 'resolved childs: ' . \wp_json_encode( $resolved ) . "\n";
echo 'wapf parser ids: ' . \wp_json_encode( $wapf['choices'] ) . "\n";
echo "SUCCESS remap import\n";

// Cleanup destination-owned fixtures.
$imported_id && \wp_delete_post( $imported_id, true );
foreach ( $dest_products as $pid ) {
	\wp_delete_post( (int) $pid, true );
}
\wp_delete_post( $dest_parent->get_id(), true );
echo 'cleanup done: deleted ' . ( 1 + count( $dest_products ) + ( $imported_id ? 1 : 0 ) ) . " posts\n";

if ( false ) {
	// Analysis-only symbol stubs; unreachable at runtime.
	class WC_Product_Simple {
		public function set_name( $name ) {}
		public function set_slug( $slug ) {}
		public function set_status( $status ) {}
		public function set_regular_price( $price ) {}
		public function save() { return 1; }
		public function get_id() { return 1; }
	}
	function wc_get_product( $the_product = false ) { return null; }
}
