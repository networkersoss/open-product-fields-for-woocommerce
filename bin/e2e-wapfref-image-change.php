<?php
/**
 * Comparative OPF-vs-WAPF-Extended-3.1.5 harness for
 * `WAPF-INTERACTION-IMAGE-CHANGE`.
 *
 * Builds the same two-rule gallery-image mapping on a WAPF-authored product and
 * an OPF-authored product (both rules deliberately match `finish=gold`, in a
 * fixed order), then compares the serialized `data-*-gi` rule payload and swap
 * type each plugin emits. The order is chosen so the shipped renderers/JS
 * (which reverse the rule list before matching) resolve the LAST rule — the
 * browser lane reads the live gallery to confirm which image wins.
 *
 * Usage (clone path guarded, WAPF + OPF both active; stub include supplies
 * intelephense symbols only):
 *   OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=<repo>/docs/compatibility/wapf-reference-proof-20261005 \
 *     wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-image-change.php setup|render|cleanup
 */

defined( 'ABSPATH' ) || exit;

use OPF\Engine\FieldGroup;
use OPF\Service\FieldGroups;
use OPF\Service\Renderer;
use SW_WAPF_PRO\Includes\Classes\Field_Groups as WapfFieldGroups;
use SW_WAPF_PRO\Includes\Classes\Html as WapfHtml;

$clone = '/tmp/opf-wapfref-compare-wp';
$out   = getenv( 'OPF_WAPFREF_OUT' ) ?: __DIR__;
$art   = trailingslashit( $out ) . 'row2-image-change';
$phase = $args[0] ?? '';

if ( '1' !== getenv( 'OPF_WAPFREF_ALLOW' ) || realpath( ABSPATH ) !== $clone ) {
	throw new RuntimeException( 'Guarded: disposable comparative clone + OPF_WAPFREF_ALLOW=1 only.' );
}
if ( ! class_exists( '\SW_WAPF_PRO\WAPF' ) ) {
	throw new RuntimeException( 'WAPF Extended 3.1.5 must be active.' );
}
if ( ! defined( 'OPF_VERSION' ) ) {
	throw new RuntimeException( 'OPF must be active.' );
}

$import_image = static function ( string $src, string $title ): int {
	$uploads = wp_upload_dir();
	$dest    = trailingslashit( $uploads['path'] ) . basename( $src );
	copy( $src, $dest );
	$type = wp_check_filetype( $dest );
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit' ], $dest );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
	return (int) $id;
};

$wapf_raw = static function ( string $group_id, int $g1, int $g2 ): array {
	return [
		'id'         => $group_id,
		'type'       => 'wapf_product',
		'fields'     => [ [
			'id'       => 'finish',
			'type'     => 'select',
			'label'    => 'Finish',
			'width'    => 100,
			'class'    => '',
			'required' => false,
			'choices'  => [
				[ 'slug' => 'amber', 'label' => 'Gold', 'pricing_type' => 'none', 'pricing_amount' => '', 'selected' => false, 'disabled' => false, 'options' => [] ],
				[ 'slug' => 'steel', 'label' => 'Silver', 'pricing_type' => 'none', 'pricing_amount' => '', 'selected' => false, 'disabled' => false, 'options' => [] ],
			],
			'conditionals' => [],
			'pricing'      => [ 'enabled' => 'false', 'type' => 'none', 'amount' => '0' ],
		] ],
		'conditions' => [],
		'layout'     => [
			'labels_position'       => 'above',
			'instructions_position' => 'label',
			'mark_required'         => true,
			'enable_gallery_images' => true,
			'swap_type'             => 'rules',
			'gallery_images'        => [
				[ 'source' => 'upload', 'url' => wp_get_attachment_image_url( $g1, 'full' ), 'id' => (string) $g1, 'values' => [ [ 'field' => 'finish', 'value' => 'steel' ] ] ],
				[ 'source' => 'upload', 'url' => wp_get_attachment_image_url( $g2, 'full' ), 'id' => (string) $g2, 'values' => [ [ 'field' => 'finish', 'value' => 'steel' ] ] ],
			],
		],
		'variables'  => [],
	];
};

if ( 'setup' === $phase ) {
	if ( get_option( 'opf_wapfref_ic_wapf_product' ) ) {
		throw new RuntimeException( 'Row-2 fixture already exists.' );
	}
	$g1 = $import_image( trailingslashit( $out ) . 'fixtures/ic-g1.png', 'WAPFREF image change rule 1' );
	$g2 = $import_image( trailingslashit( $out ) . 'fixtures/ic-g2.png', 'WAPFREF image change rule 2' );
	$g3 = $import_image( trailingslashit( $out ) . 'fixtures/ic-g3.png', 'WAPFREF image change default' );

	$make = static function ( string $name, string $slug, int $featured, array $gallery ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_slug( $slug );
		$p->set_regular_price( '20' );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( 'visible' );
		$p->set_virtual( true );
		$p->set_image_id( $featured );
		$p->set_gallery_image_ids( $gallery );
		return (int) $p->save();
	};
	$wapf_product = $make( 'WAPFREF image change WAPF', 'wapfref-ic-wapf', $g3, [ $g1, $g2 ] );
	$opf_product  = $make( 'WAPFREF image change OPF', 'wapfref-ic-opf', $g3, [ $g1, $g2 ] );

	$fg = WapfFieldGroups::raw_json_to_field_group( $wapf_raw( 'p_' . $wapf_product, $g1, $g2 ) );
	update_post_meta( $wapf_product, '_wapf_fieldgroup', $fg->to_array() );

	$layout = [
		'enable_gallery_images' => true,
		'swap_type'             => 'rules',
		'gallery_images'        => [
			[ 'source' => 'upload', 'url' => wp_get_attachment_image_url( $g1, 'full' ), 'id' => (string) $g1, 'values' => [ [ 'field' => 'finish', 'value' => 'steel' ] ] ],
			[ 'source' => 'upload', 'url' => wp_get_attachment_image_url( $g2, 'full' ), 'id' => (string) $g2, 'values' => [ [ 'field' => 'finish', 'value' => 'steel' ] ] ],
		],
	];
	$opf_group_id = FieldGroups::save(
		0,
		[
			'fields'      => [ [
				'id'      => 'finish',
				'label'   => 'Finish',
				'type'    => 'select',
				'choices' => [
					[ 'slug' => 'amber', 'label' => 'Gold', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
					[ 'slug' => 'steel', 'label' => 'Silver', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
				],
			] ],
			'layout'      => $layout,
			// OPF drives its own JS gallery swap from the native `image_rules`
			// model; `layout.gallery_images` above is the WAPF-compat attribute.
			'image_rule_mode' => 'rules',
			'image_rules' => [
				[ 'target_url' => wp_get_attachment_image_url( $g1, 'full' ), 'conditions' => [ [ 'field' => 'finish', 'value' => 'steel' ] ] ],
				[ 'target_url' => wp_get_attachment_image_url( $g2, 'full' ), 'conditions' => [ [ 'field' => 'finish', 'value' => 'steel' ] ] ],
			],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $opf_product ] ] ] ] ],
		],
		[ 'title' => 'WAPFREF image change OPF', 'status' => 'publish' ]
	);

	update_option( 'opf_wapfref_ic_wapf_product', $wapf_product, false );
	update_option( 'opf_wapfref_ic_opf_product', $opf_product, false );
	update_option( 'opf_wapfref_ic_opf_group', $opf_group_id, false );
	update_option( 'opf_wapfref_ic_images', [ 'g1' => $g1, 'g2' => $g2, 'g3' => $g3 ], false );
	if ( ! is_dir( $art ) ) {
		mkdir( $art, 0700, true );
	}
	file_put_contents( $art . '/browser-state.json', wp_json_encode( [
		'utc'           => gmdate( 'c' ),
		'wapf_product'  => $wapf_product,
		'opf_product'   => $opf_product,
		'images'        => [ 'g1' => $g1, 'g2' => $g2, 'g3' => $g3 ],
		'expected_last' => $g2,
	] ) );
	FieldGroups::flush_cache();
	echo "ok row2 setup wapf_product=$wapf_product opf_product=$opf_product opf_group=$opf_group_id g1=$g1 g2=$g2 g3=$g3\n";
	return;
}

if ( 'render' === $phase ) {
	$wapf_product = (int) get_option( 'opf_wapfref_ic_wapf_product' );
	$images       = get_option( 'opf_wapfref_ic_images', [] );
	if ( ! $wapf_product || empty( $images['g1'] ) ) {
		throw new RuntimeException( 'Row-2 fixture missing.' );
	}
	$extract_attrs = static function ( string $html ): array {
		$out = [ 'wapf_st' => null, 'wapf_gi' => null, 'opf_st' => null, 'opf_gi' => null ];
		if ( preg_match( '/data-wapf-st="([^"]*)"/', $html, $m ) ) {
			$out['wapf_st'] = html_entity_decode( $m[1] );
		}
		if ( preg_match( '/data-wapf-gi="([^"]*)"/', $html, $m ) ) {
			$out['wapf_gi'] = json_decode( html_entity_decode( $m[1] ), true );
		}
		if ( preg_match( '/data-opf-st="([^"]*)"/', $html, $m ) ) {
			$out['opf_st'] = html_entity_decode( $m[1] );
		}
		if ( preg_match( '/data-opf-gi="([^"]*)"/', $html, $m ) ) {
			$out['opf_gi'] = json_decode( html_entity_decode( $m[1] ), true );
		}
		return $out;
	};

	$wp = wc_get_product( $wapf_product );
	$fg = WapfFieldGroups::process_data( get_post_meta( $wapf_product, '_wapf_fieldgroup', true ) );
	$wapf_html = WapfHtml::display_field_groups( [ $fg ], $wp );

	$opf_group = FieldGroups::group_from_post( get_post( (int) get_option( 'opf_wapfref_ic_opf_group' ) ) );
	$opf_gid   = (int) get_option( 'opf_wapfref_ic_opf_group' );
	ob_start();
	Renderer::render_group( (string) $opf_gid, 'OPF image change', $opf_group, 20.0, $wp );
	$opf_html = ob_get_clean();

	$wapf = $extract_attrs( $wapf_html );
	$opf  = $extract_attrs( $opf_html );

	$result = [
		'utc'          => gmdate( 'c' ),
		'wapf_version' => defined( 'SW_WAPF_PRO_VERSION' ) ? SW_WAPF_PRO_VERSION : '3.1.5',
		'opf_version'  => OPF_VERSION,
		'wapf'         => $wapf,
		'opf'          => $opf,
		'parity'       => [
			'swap_type_equal'     => $wapf['wapf_st'] === $opf['opf_st'],
			'same_rule_count'     => is_array( $wapf['wapf_gi'] ) && is_array( $opf['opf_gi'] ) && count( $wapf['wapf_gi']['rules'] ?? [] ) === count( $opf['opf_gi']['rules'] ?? [] ),
			'rule_order_equal'    => ( $wapf['wapf_gi']['rules'] ?? [] ) == ( $opf['opf_gi']['rules'] ?? [] ),
			'same_first_image'    => ( $wapf['wapf_gi']['rules'][0]['image'] ?? null ) === ( $opf['opf_gi']['rules'][0]['image'] ?? null ),
			'same_last_image'     => ( $wapf['wapf_gi']['rules'][count( $wapf['wapf_gi']['rules'] ?? [] ) - 1]['image'] ?? null ) === ( $opf['opf_gi']['rules'][count( $opf['opf_gi']['rules'] ?? [] ) - 1]['image'] ?? null ),
		],
	];
	if ( ! is_dir( $art ) ) {
		mkdir( $art, 0700, true );
	}
	file_put_contents( $art . '/image-change-markup-compare.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	file_put_contents( $art . '/wapf-field-group.html', $wapf_html );
	file_put_contents( $art . '/opf-field-group.html', $opf_html );
	echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
	return;
}

if ( 'cleanup' === $phase ) {
	foreach ( [ 'opf_wapfref_ic_wapf_product', 'opf_wapfref_ic_opf_product' ] as $opt ) {
		$pid = (int) get_option( $opt, 0 );
		if ( $pid ) {
			$p = wc_get_product( $pid );
			if ( $p ) {
				$p->delete( true );
			}
			wp_delete_post( $pid, true );
		}
		delete_option( $opt );
	}
	$gid = (int) get_option( 'opf_wapfref_ic_opf_group', 0 );
	if ( $gid ) {
		wp_delete_post( $gid, true );
	}
	delete_option( 'opf_wapfref_ic_opf_group' );
	foreach ( (array) get_option( 'opf_wapfref_ic_images', [] ) as $id ) {
		wp_delete_attachment( (int) $id, true );
	}
	delete_option( 'opf_wapfref_ic_images' );
	FieldGroups::flush_cache();
	echo "ok row2 cleanup\n";
	return;
}

throw new RuntimeException( 'Expected setup, render, or cleanup.' );
