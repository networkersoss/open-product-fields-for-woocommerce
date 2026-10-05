<?php
/**
 * Export the linked-child fixture group for the cross-site remap proof.
 * Source clone only.
 *
 * Writes:
 *  - source-choices.json : the fixture's manual child choices (slug + product_id)
 *  - wapf-tools.json     : OPF-produced WAPF Extended Tools payload
 *  - group-archive.json  : OPF portable archive package
 *
 * Env: OPF_CHILD_CUR_ALLOW=1, OPF_CHILD_CUR_ARTIFACT_DIR, OPF_REMAP_SOURCE_CLONE.
 */
$source = getenv( 'OPF_REMAP_SOURCE_CLONE' ) ?: '/tmp/opf-child-currency-wp';
if ( '1' !== getenv( 'OPF_CHILD_CUR_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), $source ) ) {
	throw new RuntimeException( 'Explicit isolated source clone required.' );
}
$dir = getenv( 'OPF_CHILD_CUR_ARTIFACT_DIR' ) ?: '/tmp/opf-child-currency-evidence';
$state = \get_option( 'opf_child_currency_state', [] );
if ( empty( $state['group'] ) ) {
	throw new RuntimeException( 'Fixture state missing.' );
}
$post  = \get_post( (int) $state['group'] );
$group = \OPF\Service\FieldGroups::group_from_post( $post );
$data  = $group->data;

$choices = [];
foreach ( $data['fields'] as $field ) {
	if ( 'products' !== ( $field['type'] ?? '' ) ) {
		continue;
	}
	foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
		$choices[] = [
			'slug'         => (string) ( $choice['slug'] ?? '' ),
			'product_id'   => (int) ( $choice['product_id'] ?? 0 ),
			'pricing_type' => (string) ( $choice['pricing_type'] ?? 'fixed' ),
		];
	}
}

$payload = \OPF\Service\WapfExporter::build_payload( $data );
$archive = \OPF\Service\Exporter::build_package(
	[ [ 'id' => (int) $state['group'], 'title' => $post->post_title, 'status' => 'publish', 'menu_order' => 0, 'language' => '', 'data' => $data ] ],
	[ 'type' => 'selection', 'ids' => [ (int) $state['group'] ] ]
);

file_put_contents( $dir . '/source-choices.json', \wp_json_encode( [
	'group_id' => (int) $state['group'],
	'title'    => $post->post_title,
	'choices'  => $choices,
], JSON_PRETTY_PRINT ) );
file_put_contents( $dir . '/wapf-tools.json', \wp_json_encode( $payload, JSON_PRETTY_PRINT ) );
file_put_contents( $dir . '/group-archive.json', \wp_json_encode( $archive, JSON_PRETTY_PRINT ) );

echo 'source choices: ' . \wp_json_encode( $choices ) . "\n";
echo 'warnings: ' . \wp_json_encode( $archive['warnings'] ) . "\n";
echo "SUCCESS remap export\n";
