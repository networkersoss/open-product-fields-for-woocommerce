<?php
/**
 * Comparative harness for `WAPF-FIELD-CHECKBOX-COLUMNS`.
 *
 *  - `wapf-options` empirically lists the checkbox-field controls WAPF Extended
 *    3.1.5 offers (Config::get_field_options) and reports whether any of them is
 *    a column count, so the absence of a 3.1.5 comparison is evidenced, not
 *    assumed.
 *  - `setup` builds an OPF checkbox field group (`columns: 2`) and a temporary
 *    administrator for the real admin REST save/reload check driven by
 *    bin/e2e-wapfref-checkbox-columns-admin-browser.mjs.
 *  - `verify` reports the persisted column count after the REST save.
 *
 * Usage (clone path guarded; WAPF active for `wapf-options`; the stub include
 * only supplies intelephense symbols):
 *   OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=<repo>/docs/compatibility/wapf-reference-proof-20261005 \
 *     wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-checkbox-columns-admin.php setup|wapf-options|verify|cleanup
 */

defined( 'ABSPATH' ) || exit;

use OPF\Engine\FieldGroup;
use OPF\Service\FieldGroups;

$clone = '/tmp/opf-wapfref-compare-wp';
$out   = getenv( 'OPF_WAPFREF_OUT' ) ?: __DIR__;
$art   = trailingslashit( $out ) . 'row3-checkbox-columns';
$phase = $args[0] ?? '';

if ( '1' !== getenv( 'OPF_WAPFREF_ALLOW' ) || realpath( ABSPATH ) !== $clone ) {
	throw new RuntimeException( 'Guarded: disposable comparative clone + OPF_WAPFREF_ALLOW=1 only.' );
}
if ( ! defined( 'OPF_VERSION' ) ) {
	throw new RuntimeException( 'OPF must be active.' );
}

if ( 'wapf-options' === $phase ) {
	if ( ! class_exists( '\SW_WAPF_PRO\WAPF' ) ) {
		throw new RuntimeException( 'WAPF Extended 3.1.5 must be active for the capability probe.' );
	}
	$all = \SW_WAPF_PRO\Includes\Classes\Config::get_field_options();
	$flatten = static function ( $node ) use ( &$flatten ): array {
		$out = [];
		if ( ! is_array( $node ) ) {
			return $out;
		}
		foreach ( $node as $key => $value ) {
			$out[] = (string) $key;
			if ( is_array( $value ) ) {
				$out = array_merge( $out, $flatten( $value ) );
			} elseif ( is_scalar( $value ) ) {
				$out[] = (string) $value;
			}
		}
		return $out;
	};
	$checkbox_tokens = array_merge( $flatten( $all['checkboxes'] ?? [] ), $flatten( $all['true-false'] ?? [] ) );
	$column_hits     = array_values( array_filter( $checkbox_tokens, static fn ( $t ) => false !== stripos( $t, 'column' ) ) );
	$result = [
		'utc'            => gmdate( 'c' ),
		'wapf_version'   => defined( 'SW_WAPF_PRO_VERSION' ) ? SW_WAPF_PRO_VERSION : '3.1.5',
		'checkbox_option_tokens' => array_values( array_unique( $checkbox_tokens ) ),
		'column_related_tokens'  => $column_hits,
		'has_column_control'     => [] !== $column_hits,
	];
	if ( ! is_dir( $art ) ) {
		mkdir( $art, 0700, true );
	}
	file_put_contents( $art . '/wapf-3.1.5-checkbox-options.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
	return;
}

if ( 'setup' === $phase ) {
	if ( get_option( 'opf_wapfref_cb_product' ) ) {
		throw new RuntimeException( 'Row-3 fixture already exists.' );
	}
	$password = getenv( 'OPF_WAPFREF_ADMIN_PASSWORD' );
	if ( ! is_string( $password ) || strlen( $password ) < 24 ) {
		throw new RuntimeException( 'Set OPF_WAPFREF_ADMIN_PASSWORD (>=24 chars) for the temporary administrator.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'WAPFREF checkbox columns' );
	$product->set_slug( 'wapfref-checkbox-columns' );
	$product->set_regular_price( '10' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_virtual( true );
	$product_id = (int) $product->save();

	$group_id = FieldGroups::save(
		0,
		[
			'fields'      => [ [
				'id'      => 'extras',
				'label'   => 'Extras',
				'type'    => 'checkbox',
				'columns' => 2,
				'choices' => [
					[ 'slug' => 'gift', 'label' => 'Gift wrap', 'pricing' => [ 'type' => 'fixed', 'amount' => 2.5 ] ],
					[ 'slug' => 'note', 'label' => 'Gift note', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
					[ 'slug' => 'rush', 'label' => 'Rush packing', 'pricing' => [ 'type' => 'fixed', 'amount' => 1.25 ] ],
					[ 'slug' => 'engrave', 'label' => 'Engraving', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ],
				],
			] ],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
		],
		[ 'title' => 'WAPFREF checkbox columns', 'status' => 'publish' ]
	);
	$existing = get_user_by( 'login', 'opf_wapfref_admin' );
	if ( $existing ) {
		throw new RuntimeException( 'Temporary administrator already exists.' );
	}
	$admin_id = wp_create_user( 'opf_wapfref_admin', $password, 'opf-wapfref-admin@example.invalid' );
	if ( is_wp_error( $admin_id ) ) {
		throw new RuntimeException( 'Could not create temporary administrator.' );
	}
	( new WP_User( $admin_id ) )->set_role( 'administrator' );

	update_option( 'opf_wapfref_cb_product', $product_id, false );
	update_option( 'opf_wapfref_cb_group', $group_id, false );
	update_option( 'opf_wapfref_cb_admin', (int) $admin_id, false );
	if ( ! is_dir( $art ) ) {
		mkdir( $art, 0700, true );
	}
	file_put_contents( $art . '/browser-state.json', wp_json_encode( [
		'utc'        => gmdate( 'c' ),
		'product'    => $product_id,
		'group'      => $group_id,
		'slug'       => 'wapfref-checkbox-columns',
		'admin_id'   => (int) $admin_id,
		'admin_user' => 'opf_wapfref_admin',
	] ) );
	FieldGroups::flush_cache();
	echo "ok row3 setup product=$product_id group=$group_id admin=$admin_id permalink=" . get_permalink( $product_id ) . "\n";
	return;
}

if ( 'verify' === $phase ) {
	$group_id = (int) get_option( 'opf_wapfref_cb_group', 0 );
	$post     = get_post( $group_id );
	$schema   = is_object( $post ) ? json_decode( (string) $post->post_content, true ) : null;
	$stored   = is_array( $schema ) ? ( $schema['fields'][0]['columns'] ?? null ) : null;
	$group    = FieldGroups::group_from_post( $post );
	$resolved = $group->data['fields'][0]['columns'] ?? null;
	$result = [
		'utc'              => gmdate( 'c' ),
		'group'            => $group_id,
		'stored_columns'   => $stored,
		'resolved_columns' => $resolved,
	];
	if ( ! is_dir( $art ) ) {
		mkdir( $art, 0700, true );
	}
	file_put_contents( $art . '/admin-rest-save-reload.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
	if ( 4 !== (int) $stored || 4 !== (int) $resolved ) {
		throw new RuntimeException( 'Admin REST save did not persist columns:4 through reload.' );
	}
	return;
}

if ( 'cleanup' === $phase ) {
	$pid = (int) get_option( 'opf_wapfref_cb_product', 0 );
	if ( $pid ) {
		$p = wc_get_product( $pid );
		if ( $p ) {
			$p->delete( true );
		}
		wp_delete_post( $pid, true );
	}
	$gid = (int) get_option( 'opf_wapfref_cb_group', 0 );
	if ( $gid ) {
		wp_delete_post( $gid, true );
	}
	$aid = (int) get_option( 'opf_wapfref_cb_admin', 0 );
	if ( $aid ) {
		wp_delete_user( $aid );
	}
	delete_option( 'opf_wapfref_cb_product' );
	delete_option( 'opf_wapfref_cb_group' );
	delete_option( 'opf_wapfref_cb_admin' );
	FieldGroups::flush_cache();
	echo "ok row3 cleanup\n";
	return;
}

throw new RuntimeException( 'Expected setup, wapf-options, verify, or cleanup.' );
