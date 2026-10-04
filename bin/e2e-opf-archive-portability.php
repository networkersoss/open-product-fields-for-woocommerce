<?php
/**
 * E2E proof that cross-site placement IDs and media references are retained
 * and held for review. Media files themselves are not portable, so the archive
 * keeps the URL/ID metadata and flags the gap for review.
 * Run only against a disposable WordPress clone with OPF and WooCommerce active:
 * OPF_ARCHIVE_E2E_ALLOW=1 wp eval-file bin/e2e-opf-archive-portability.php
 *
 * @package open-product-fields-for-woocommerce
 */

if ( '1' !== getenv( 'OPF_ARCHIVE_E2E_ALLOW' ) ) {
	throw new RuntimeException( 'Set OPF_ARCHIVE_E2E_ALLOW=1 only on a disposable WordPress clone.' );
}
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( OPF\Service\ArchiveImporter::class ) ) {
	throw new RuntimeException( 'Activate WooCommerce and OPF before running this proof.' );
}

$title = 'OPF archive portability lifecycle fixture';
if ( get_page_by_title( $title, OBJECT, 'opf_field_group' ) ) {
	throw new RuntimeException( 'A fixture with this title already exists; refusing to modify it.' );
}

$source_id = 0;
$target_id = 0;
try {
	$data = OPF\Engine\FieldGroup::normalize( [
		'fields' => [
			[ 'id' => 'transfer-choice', 'label' => 'Transfer choice', 'type' => 'select', 'choices' => [ [ 'slug' => 'standard', 'label' => 'Standard' ] ] ],
			[ 'id' => 'fabric-guide', 'label' => 'Fabric guide', 'type' => 'content_image', 'image_url' => 'https://source.example.test/fabric.jpg', 'image_id' => 481 ],
			[
				'id' => 'manual-children', 'label' => 'Manual child products', 'type' => 'products', 'subtype' => 'card-qty',
				'product_selection' => 'manual',
				'choices' => [
					[ 'slug' => 'gift-wrap', 'label' => 'Gift wrap', 'product_id' => 765432104, 'pricing_type' => 'fixed', 'quantity' => [ 'default' => 2, 'min' => 1, 'max' => 4 ] ],
				],
			],
			[
				'id' => 'category-children', 'label' => 'Category child products', 'type' => 'products', 'subtype' => 'vcard',
				'product_selection' => 'category',
				'product_query' => [ 'query_id' => 765432105, 'query_label' => 'Extras', 'limit' => 8, 'sort' => 'name_asc', 'pricing_type' => 'none' ],
			],
		],
		'rule_groups' => [ [ 'rules' => [
			[ 'subject' => 'product', 'operator' => 'in', 'terms' => [ '765432101' ] ],
			[ 'subject' => 'category', 'operator' => 'in', 'terms' => [ '765432102' ] ],
			[ 'subject' => 'tag', 'operator' => 'not_in', 'terms' => [ '765432103' ] ],
		] ] ],
	] );
	$source_id = OPF\Service\FieldGroups::save( 0, $data, [ 'title' => $title, 'status' => 'publish' ] );
	if ( ! $source_id ) {
		throw new RuntimeException( 'Could not create the OPF archive source fixture.' );
	}

	$source = get_post( $source_id );
	$source_data = json_decode( (string) $source->post_content, true );
	$package = OPF\Service\Exporter::build_package( [ [
		'id' => $source_id,
		'title' => $title,
		'status' => $source->post_status,
		'menu_order' => $source->menu_order,
		'language' => '',
		'data' => $source_data,
	] ], [ 'type' => 'group', 'id' => $source_id ] );
	$group_warnings = $package['groups'][0]['warnings'] ?? [];
	if ( ! in_array( 'product_target_ids_may_not_match', $group_warnings, true ) ) {
		throw new RuntimeException( 'Export did not warn that placement and linked child-product/category IDs are site-local.' );
	}
	if ( ! in_array( 'media_files_not_included', $group_warnings, true ) ) {
		throw new RuntimeException( 'Export did not warn that media files are not included.' );
	}
	$decoded = OPF\Service\ArchiveImporter::decode( (string) wp_json_encode( $package ) );
	$decoded_image = [];
	$decoded_children = [];
	foreach ( $decoded['groups'][0]['data']['fields'] ?? [] as $decoded_field ) {
		if ( 'content_image' === ( $decoded_field['type'] ?? '' ) ) {
			$decoded_image = $decoded_field;
		}
		if ( in_array( $decoded_field['id'] ?? '', [ 'manual-children', 'category-children' ], true ) ) {
			$decoded_children[ $decoded_field['id'] ] = $decoded_field;
		}
	}
	if ( 'https://source.example.test/fabric.jpg' !== ( $decoded_image['image_url'] ?? '' ) || 481 !== ( $decoded_image['image_id'] ?? 0 ) ) {
		throw new RuntimeException( 'Decoded archive dropped the content image URL or ID.' );
	}
	if ( 765432104 !== ( $decoded_children['manual-children']['choices'][0]['product_id'] ?? 0 )
		|| [ 'default' => 2, 'min' => 1, 'max' => 4 ] !== ( $decoded_children['manual-children']['choices'][0]['quantity'] ?? null ) ) {
		throw new RuntimeException( 'Decoded archive changed the linked child product reference or quantity settings.' );
	}
	if ( [ 'query_id' => 765432105, 'query_label' => 'Extras', 'limit' => 8, 'sort' => 'name_asc', 'pricing_type' => 'none' ] !== ( $decoded_children['category-children']['product_query'] ?? null ) ) {
		throw new RuntimeException( 'Decoded archive changed the linked child product category query.' );
	}

	$dry = OPF\Service\ArchiveImporter::import( $decoded, false );
	if ( 1 !== $dry['imported'] || 0 !== $dry['skipped'] || 'dry-run' !== $dry['mode'] ) {
		throw new RuntimeException( 'Archive dry-run did not report one planned import.' );
	}
	if ( 1 !== count( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'title' => $title, 'fields' => 'ids' ] ) ) ) {
		throw new RuntimeException( 'Archive dry-run wrote an imported group.' );
	}

	$committed = OPF\Service\ArchiveImporter::import( $decoded, true );
	if ( 1 !== $committed['imported'] || ! empty( $committed['skipped'] ) ) {
		throw new RuntimeException( 'Archive commit did not import the placement fixture.' );
	}
	$target_id = (int) ( $committed['groups'][0]['opf_id'] ?? 0 );
	$target = get_post( $target_id );
	$notes = get_post_meta( $target_id, '_opf_needs_review', true );
	$target_data = $target ? json_decode( (string) $target->post_content, true ) : null;
	$notes_text = is_array( $notes ) ? implode( ' ', $notes ) : '';
	if ( ! $target || 'draft' !== $target->post_status || ! is_array( $notes ) || false === strpos( $notes_text, 'Site-local product, category, or tag targets' ) || false === strpos( $notes_text, 'Media files are not included' ) ) {
		throw new RuntimeException( 'Imported site-local placement and media references were not held as a review draft.' );
	}
	$target_fields = array_column( (array) ( $target_data['fields'] ?? [] ), null, 'id' );
	if ( 765432104 !== ( $target_fields['manual-children']['choices'][0]['product_id'] ?? 0 )
		|| 765432105 !== ( $target_fields['category-children']['product_query']['query_id'] ?? 0 ) ) {
		throw new RuntimeException( 'Imported archive changed the linked child product references.' );
	}
	if ( $source_data !== $target_data ) {
		throw new RuntimeException( 'Import changed or dropped the source placement IDs.' );
	}

	$repeat = OPF\Service\ArchiveImporter::import( $decoded, true );
	if ( 0 !== $repeat['imported'] || 1 !== $repeat['skipped'] || 'already-imported' !== ( $repeat['groups'][0]['result'] ?? '' ) ) {
		throw new RuntimeException( 'Repeated archive import did not skip the existing source group.' );
	}
	echo "ok archive portability warnings, dry-run, preserved placement/child-product/category/media refs, review draft, and idempotent repeat\n";
} finally {
	$matches = get_posts( [
		'post_type' => 'opf_field_group',
		'post_status' => 'any',
		'posts_per_page' => -1,
		'fields' => 'ids',
		'title' => $title,
	] );
	foreach ( array_unique( array_merge( [ $source_id, $target_id ], array_map( 'intval', $matches ) ) ) as $post_id ) {
		if ( $post_id > 0 ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
}
