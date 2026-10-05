<?php
/**
 * Comparative OPF-vs-WAPF-Extended-3.1.5 harness for
 * `WAPF-FIELD-CHILD-PRODUCTS-IMAGE-ZOOM`.
 *
 * Renders the same linked-child image field with the installed WAPF 3.1.5
 * renderer and with OPF's renderer, both with the hover-zoom option on and off,
 * and stages the emitted markup + a normalized attribute comparison.
 *
 * Usage (clone path guarded, WAPF + OPF both active):
 *   OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=<repo>/docs/compatibility/wapf-reference-proof-20261005 \
 *     wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-child-products-image-zoom.php setup|render|cleanup
 */

defined( 'ABSPATH' ) || exit;

use OPF\Engine\FieldGroup;
use OPF\Service\FieldGroups;
use OPF\Service\Renderer;
use SW_WAPF_PRO\Includes\Classes\Field_Groups as WapfFieldGroups;
use SW_WAPF_PRO\Includes\Classes\Html as WapfHtml;

$clone = '/tmp/opf-wapfref-compare-wp';
$out   = getenv( 'OPF_WAPFREF_OUT' ) ?: __DIR__;
$art   = trailingslashit( $out ) . 'row1-child-products-image-zoom';

if ( '1' !== getenv( 'OPF_WAPFREF_ALLOW' ) || realpath( ABSPATH ) !== $clone ) {
	throw new RuntimeException( 'Guarded: disposable comparative clone + OPF_WAPFREF_ALLOW=1 only.' );
}
if ( ! class_exists( '\SW_WAPF_PRO\WAPF' ) ) {
	throw new RuntimeException( 'WAPF Extended 3.1.5 must be active for the comparative render.' );
}
if ( ! defined( 'OPF_VERSION' ) ) {
	throw new RuntimeException( 'OPF must be active for the comparative render.' );
}

$phase = $args[0] ?? '';

$import_image = static function ( string $src, string $title ): int {
	if ( ! file_exists( $src ) ) {
		throw new RuntimeException( "Missing source image $src" );
	}
	$uploads = wp_upload_dir();
	$dest    = trailingslashit( $uploads['path'] ) . basename( $src );
	copy( $src, $dest );
	$type = wp_check_filetype( $dest );
	$id   = wp_insert_attachment(
		[ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit' ],
		$dest
	);
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
	return (int) $id;
};

$wapf_raw = static function ( string $field_id, array $choice, bool $large_image ): array {
	return [
		'id'         => 'wapfref_cpi',
		'type'       => 'wapf_product',
		'fields'     => [ [
			'id'                => $field_id,
			'type'              => 'products',
			'subtype'           => 'image',
			'label'             => 'Items',
			'width'             => 100,
			'class'             => '',
			'required'          => false,
			'product_selection' => 'manual',
			'large_image'       => $large_image ? '1' : '0',
			'item_width'        => 68,
			'items_per_row'     => 3,
			'label_pos'         => 'tooltip',
			'choices'           => [ $choice ],
			'conditionals'      => [],
			'pricing'           => [ 'enabled' => 'false', 'type' => 'none', 'amount' => '0' ],
		] ],
		'conditions' => [],
		'layout'     => [ 'labels_position' => 'above', 'instructions_position' => 'label', 'mark_required' => true ],
		'variables'  => [],
	];
};

$extract = static function ( string $html, string $kind ): array {
	// Isolate the wrapper div for the rendered field and pull its classes +
	// data-zoom-url plus the input and preview-image facts.
	$result = [ 'found' => false, 'classes' => [], 'data_zoom_url' => null, 'input' => null, 'preview_images' => 0 ];
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	$xp = new DOMXPath( $doc );
	$nodes = 'wapf' === $kind
		? $xp->query( '//div[contains(concat(" ", normalize-space(@class), " "), " wapf-swatch ")]' )
		: $xp->query( '//div[contains(concat(" ", normalize-space(@class), " "), " opf-product-choice ")]' );
	if ( 0 === $nodes->length ) {
		return $result;
	}
	$node = $nodes->item( 0 );
	if ( ! $node instanceof \DOMElement ) {
		return $result;
	}
	$result['found']         = true;
	$result['classes']       = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
	$result['data_zoom_url'] = $node->getAttribute( 'data-zoom-url' ) ?: null;
	foreach ( $xp->query( './/input', $node ) as $input ) {
		if ( ! $input instanceof \DOMElement ) {
			continue;
		}
		$result['input'] = [
			'name'          => $input->getAttribute( 'name' ),
			'value'         => $input->getAttribute( 'value' ),
			'data_field_id' => $input->getAttribute( 'data-field-id' ),
			'data_wapf_label' => $input->getAttribute( 'data-wapf-label' ),
		];
	}
	$result['preview_images'] = $xp->query( './/img[contains(@class, "zoom-preview")]', $node )->length;
	$result['preview_markup'] = false;
	foreach ( $xp->query( './/img', $node ) as $img ) {
		if ( $img instanceof \DOMElement && false !== strpos( $img->getAttribute( 'class' ), 'zoom-preview' ) ) {
			$result['preview_markup'] = (string) $doc->saveHTML( $img );
		}
	}
	return $result;
};

if ( 'setup' === $phase ) {
	if ( get_option( 'opf_wapfref_cpi_parent' ) ) {
		throw new RuntimeException( 'Row-1 fixture already exists; clean it before retrying.' );
	}
	$thumb = $import_image( trailingslashit( $out ) . 'fixtures/child-thumb.png', 'WAPFREF child thumb' );
	$zoom  = $import_image( trailingslashit( $out ) . 'fixtures/child-zoom.png', 'WAPFREF child zoom' );

	$child = new WC_Product_Simple();
	$child->set_name( 'WAPFREF zoom child' );
	$child->set_slug( 'wapfref-zoom-child' );
	$child->set_regular_price( '5' );
	$child->set_status( 'publish' );
	$child->set_catalog_visibility( 'visible' );
	$child->set_virtual( true );
	$child->set_image_id( $zoom );
	$child_id = (int) $child->save();

	$make_parent = static function ( string $name, string $slug ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_slug( $slug );
		$p->set_regular_price( '20' );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( 'visible' );
		$p->set_virtual( true );
		$p->set_image_id( 0 );
		return (int) $p->save();
	};
	$parent_on  = $make_parent( 'WAPFREF CPI zoom on', 'wapfref-cpi-on' );
	$parent_off = $make_parent( 'WAPFREF CPI zoom off', 'wapfref-cpi-off' );

	$choice = [ 'slug' => (string) $child_id, 'label' => 'WAPFREF zoom child', 'id' => (string) $child_id, 'pricing_type' => 'none' ];
	$wapf_on  = WapfFieldGroups::raw_json_to_field_group( $wapf_raw( 'items', $choice, true ) );
	$wapf_off = WapfFieldGroups::raw_json_to_field_group( $wapf_raw( 'items', $choice, false ) );
	// Local per-product WAPF group: this is how 3.1.5 attaches a group without
	// the CPT/condition machinery, and it round-trips serialized options.
	update_post_meta( $parent_on, '_wapf_fieldgroup', $wapf_on->to_array() );
	update_post_meta( $parent_off, '_wapf_fieldgroup', $wapf_off->to_array() );

	$opf_group = static function ( int $parent_id, bool $large_image ): int {
		$group = new FieldGroup( [
			'fields'      => [ [
				'id'         => 'items',
				'label'      => 'Items',
				'type'       => 'products',
				'subtype'    => 'image',
				'large_image'=> $large_image,
				'item_width' => 68,
				'items_per_row' => 3,
				'label_pos'  => 'tooltip',
				'choices'    => [],
				'product_selection' => 'manual',
			] ],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $parent_id ] ] ] ] ],
		] );
		return (int) FieldGroups::save( 0, $group, [ 'title' => 'WAPFREF CPI ' . ( $large_image ? 'zoom on' : 'zoom off' ), 'status' => 'publish' ] );
	};
	$opf_on  = $opf_group( $parent_on, true );
	$opf_off = $opf_group( $parent_off, false );

	// For the OPF manual-products choices we need product ids in the stored
	// field; update the saved groups' choices to the fixture child.
	foreach ( [ $opf_on => true, $opf_off => false ] as $gid => $zoom_flag ) {
		$post = get_post( $gid );
		$data = json_decode( (string) $post->post_content, true );
		$data['fields'][0]['choices'] = [ [
			'slug'           => (string) $child_id,
			'label'          => 'WAPFREF zoom child',
			'product_id'     => $child_id,
			'image'          => wp_get_attachment_image_url( $zoom, 'medium' ),
			'zoom_url'       => wp_get_attachment_image_url( $zoom, 'full' ),
			'pricing_type'   => 'none',
			'pricing_amount' => 0,
		] ];
		wp_update_post( [ 'ID' => $gid, 'post_content' => wp_slash( wp_json_encode( $data ) ) ] );
	}

	$saved = [
		'parent_on'  => $parent_on,
		'parent_off' => $parent_off,
		'child'      => $child_id,
		'thumb'      => $thumb,
		'zoom'       => $zoom,
		'opf_on'     => $opf_on,
		'opf_off'    => $opf_off,
	];
	update_option( 'opf_wapfref_cpi_parent', $parent_on, false );
	update_option( 'opf_wapfref_cpi_off_parent', $parent_off, false );
	update_option( 'opf_wapfref_cpi_groups', [ 'on' => $opf_on, 'off' => $opf_off ], false );
	update_option( 'opf_wapfref_attachments', [ 'thumb' => $thumb, 'zoom' => $zoom ], false );
	if ( ! is_dir( $art ) ) {
		mkdir( $art, 0700, true );
	}
	file_put_contents( '/tmp/opf-wapfref-evidence/cpi-state.json', wp_json_encode( $saved ) );
	FieldGroups::flush_cache();
	echo "ok row1 setup parent_on=$parent_on parent_off=$parent_off child=$child_id opf_on=$opf_on opf_off=$opf_off zoom=$zoom\n";
	return;
}

if ( 'render' === $phase ) {
	$parent_on  = (int) get_option( 'opf_wapfref_cpi_parent' );
	$parent_off = (int) get_option( 'opf_wapfref_cpi_off_parent' );
	$groups     = get_option( 'opf_wapfref_cpi_groups', [] );
	$att        = get_option( 'opf_wapfref_attachments', [] );
	if ( ! $parent_on || ! $parent_off || empty( $groups['on'] ) || empty( $att['zoom'] ) ) {
		throw new RuntimeException( 'Row-1 fixture missing.' );
	}
	if ( ! is_dir( $art ) ) {
		mkdir( $art, 0700, true );
	}

	$wapf_html = static function ( int $parent_id ) {
		$product = wc_get_product( $parent_id );
		$raw     = get_post_meta( $parent_id, '_wapf_fieldgroup', true );
		$fg      = \SW_WAPF_PRO\Includes\Classes\Field_Groups::process_data( $raw );
		return WapfHtml::display_field_groups( [ $fg ], $product );
	};
	$opf_html = static function ( int $gid, float $base ) {
		$group = FieldGroups::group_from_post( get_post( $gid ) );
		ob_start();
		Renderer::render_group( (string) $gid, (string) $gid, $group, $base, wc_get_product( (int) get_option( 'opf_wapfref_cpi_parent' ) ) );
		return ob_get_clean();
	};

	$wapf_on  = $wapf_html( $parent_on );
	$wapf_off = $wapf_html( $parent_off );
	$opf_on   = $opf_html( (int) $groups['on'], 20.0 );
	$opf_off  = $opf_html( (int) $groups['off'], 20.0 );

	file_put_contents( $art . '/wapf-zoom-on.html', $wapf_on );
	file_put_contents( $art . '/wapf-zoom-off.html', $wapf_off );
	file_put_contents( $art . '/opf-zoom-on.html', $opf_on );
	file_put_contents( $art . '/opf-zoom-off.html', $opf_off );

	$compare = [
		'utc'          => gmdate( 'c' ),
		'wapf_version' => defined( 'SW_WAPF_PRO_VERSION' ) ? SW_WAPF_PRO_VERSION : '3.1.5',
		'opf_version'  => OPF_VERSION,
		'zoom_url_full' => wp_get_attachment_image_url( (int) $att['zoom'], 'full' ),
		'wapf_on'  => $extract( $wapf_on, 'wapf' ),
		'wapf_off' => $extract( $wapf_off, 'wapf' ),
		'opf_on'   => $extract( $opf_on, 'opf' ),
		'opf_off'  => $extract( $opf_off, 'opf' ),
	];
	file_put_contents( $art . '/markup-compare.json', wp_json_encode( $compare, JSON_PRETTY_PRINT ) );
	echo wp_json_encode( $compare, JSON_PRETTY_PRINT ) . "\n";
	return;
}

if ( 'cleanup' === $phase ) {
	$parent_on  = (int) get_option( 'opf_wapfref_cpi_parent', 0 );
	$parent_off = (int) get_option( 'opf_wapfref_cpi_off_parent', 0 );
	$groups     = get_option( 'opf_wapfref_cpi_groups', [] );
	$att        = get_option( 'opf_wapfref_attachments', [] );
	foreach ( [ $parent_on, $parent_off ] as $pid ) {
		if ( $pid ) {
			$p = wc_get_product( $pid );
			if ( $p ) {
				$p->delete( true );
			}
			wp_delete_post( $pid, true );
		}
	}
	foreach ( (array) $groups as $gid ) {
		if ( $gid ) {
			wp_delete_post( (int) $gid, true );
		}
	}
	foreach ( [ 'thumb', 'zoom' ] as $key ) {
		if ( ! empty( $att[ $key ] ) ) {
			wp_delete_attachment( (int) $att[ $key ], true );
		}
	}
	delete_option( 'opf_wapfref_cpi_parent' );
	delete_option( 'opf_wapfref_cpi_off_parent' );
	delete_option( 'opf_wapfref_cpi_groups' );
	delete_option( 'opf_wapfref_attachments' );
	if ( file_exists( '/tmp/opf-wapfref-evidence/cpi-state.json' ) ) {
		unlink( '/tmp/opf-wapfref-evidence/cpi-state.json' );
	}
	FieldGroups::flush_cache();
	echo "ok row1 cleanup\n";
	return;
}

throw new RuntimeException( 'Expected setup, render, or cleanup.' );
