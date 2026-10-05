<?php
/**
 * OPF/WAPF 3.1.5 coexistence harness for the image-quantity add-to-cart
 * `TypeError` residual on `WAPF-FIELD-IMAGE-QUANTITY-ZOOM`.
 *
 * Runs only inside the disposable comparative clone. It:
 *  - builds an OPF image_quantity + required text fixture on a published product;
 *  - reproduces the described defect directly by dispatching `wapf/validate`
 *    with OPF's normalized array field (the pre-bridge call shape), capturing
 *    the exact TypeError and stack trace;
 *  - proves the shipped `OPF\Compat\WapfHooks::validate_field()` bridge does not
 *    raise it;
 *  - leaves the product published for the real storefront add-to-cart check in
 *    `bin/e2e-wapfref-image-quantity-zoom-coexistence-browser.mjs`.
 *
 * Usage (clone path guarded, WAPF + OPF both active):
 *   OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=<repo>/docs/compatibility/wapf-reference-proof-20261005 \
 *     wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-image-quantity-zoom-coexistence.php setup|reproduce|cleanup
 *
 */

defined( 'ABSPATH' ) || exit;

use OPF\Compat\WapfHooks;
use OPF\Service\FieldGroups;

$clone = '/tmp/opf-wapfref-compare-wp';
$out   = getenv( 'OPF_WAPFREF_OUT' ) ?: __DIR__;

if ( '1' !== getenv( 'OPF_WAPFREF_ALLOW' ) || realpath( ABSPATH ) !== $clone ) {
	throw new RuntimeException( 'Guarded: disposable comparative clone + OPF_WAPFREF_ALLOW=1 only.' );
}
if ( ! class_exists( '\SW_WAPF_PRO\WAPF' ) ) {
	throw new RuntimeException( 'WAPF Extended 3.1.5 must be active for the coexistence harness.' );
}
if ( ! defined( 'OPF_VERSION' ) ) {
	throw new RuntimeException( 'OPF must be active for the coexistence harness.' );
}

$phase = $args[0] ?? '';
$state_file = '/tmp/opf-wapfref-evidence/iqz-coexistence-state.json';

$attachments = static function (): array {
	$existing = get_option( 'opf_wapfref_attachments', [] );
	return is_array( $existing ) ? $existing : [];
};

if ( 'setup' === $phase ) {
	$att = $attachments();
	if ( empty( $att['zoom'] ) || ! wp_get_attachment_image_src( (int) $att['zoom'], 'full' ) ) {
		throw new RuntimeException( 'Run the row-1 fixture first so the shared attachments exist.' );
	}
	$zoom = (int) $att['zoom'];

	if ( get_option( 'opf_wapfref_iqz_product' ) ) {
		throw new RuntimeException( 'IQZ coexistence fixture already exists; clean it before retrying.' );
	}

	$product = new WC_Product_Simple();
	$product->set_name( 'WAPFREF IQZ coexistence' );
	$product->set_slug( 'wapfref-iqz-coexistence' );
	$product->set_regular_price( '20' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_virtual( true );
	$product->set_image_id( $zoom );
	$product_id = (int) $product->save();

	$group_id = FieldGroups::save(
		0,
		[
			'fields' => [
				[ 'id' => 'note', 'label' => 'Required note', 'type' => 'text', 'required' => true ],
				[
					'id'         => 'prints',
					'label'      => 'Prints',
					'type'       => 'image_quantity',
					'image_zoom' => true,
					'choices'    => [
						[
							'slug'     => 'oak',
							'label'    => 'Oak',
							'image_id' => $zoom,
							'quantity' => [ 'default' => 0, 'min' => 0, 'max' => 4 ],
							'pricing'  => [ 'type' => 'fixed', 'amount' => 2.5, 'per_unit' => true ],
						],
					],
				],
			],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
		],
		[ 'title' => 'WAPFREF IQZ coexistence', 'status' => 'publish' ]
	);
	if ( ! $product_id || ! $group_id ) {
		throw new RuntimeException( 'Failed to create IQZ coexistence fixture.' );
	}

	update_option( 'opf_wapfref_iqz_product', $product_id, false );
	update_option( 'opf_wapfref_iqz_group', $group_id, false );
	if ( ! is_dir( dirname( $state_file ) ) ) {
		mkdir( dirname( $state_file ), 0700, true );
	}
	file_put_contents( $state_file, wp_json_encode( [ 'product' => $product_id, 'group' => $group_id, 'zoom' => $zoom ] ) );
	FieldGroups::flush_cache();
	echo "ok iqz coexistence setup product=$product_id group=$group_id permalink=" . get_permalink( $product_id ) . "\n";
	return;
}

if ( 'reproduce' === $phase ) {
	$product_id = (int) get_option( 'opf_wapfref_iqz_product' );
	$product    = wc_get_product( $product_id );
	if ( ! $product ) {
		throw new RuntimeException( 'IQZ coexistence fixture missing.' );
	}
	FieldGroups::flush_cache();

	$groups = FieldGroups::for_product( $product );
	$field  = null;
	foreach ( $groups as $entry ) {
		foreach ( ( $entry['group']->data['fields'] ?? [] ) as $candidate ) {
			if ( 'prints' === ( $candidate['id'] ?? '' ) ) {
				$field = $candidate;
			}
		}
	}
	if ( ! is_array( $field ) ) {
		throw new RuntimeException( 'OPF normalized image_quantity field not found.' );
	}

	// Confirm WAPF's native linked-products validator is really registered on
	// wapf/validate (the callback whose typed 3rd argument rejects an array).
	$native = null;
	$registry = $GLOBALS['wp_filter']['wapf/validate'] ?? null;
	$callbacks = is_object( $registry ) && isset( $registry->callbacks ) ? $registry->callbacks : ( is_array( $registry ) ? $registry : [] );
	foreach ( $callbacks as $priority => $entries ) {
		foreach ( $entries as $entry ) {
			$cb = $entry['function'] ?? null;
			if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) && is_object( $cb[0] )
				&& is_a( $cb[0], 'SW_WAPF_PRO\\Includes\\Controllers\\Linked_Products_Controller' )
				&& 'validate_cart' === strtolower( (string) $cb[1] ) ) {
				$native = [ 'class' => get_class( $cb[0] ), 'method' => $cb[1], 'priority' => (int) $priority ];
			}
		}
	}

	$results = [
		'utc'            => gmdate( 'c' ),
		'wapf_version'   => defined( 'SW_WAPF_PRO_VERSION' ) ? SW_WAPF_PRO_VERSION : '3.1.5 (header)',
		'opf_version'    => OPF_VERSION,
		'native_callback'=> $native,
		'field_id'       => $field['id'] ?? null,
		'field_type'     => $field['type'] ?? null,
		'dispatch'       => [],
	];

	// A. Raw pre-bridge call shape: OPF's normalized array passed as $field.
	try {
		$out_a = apply_filters(
			'wapf/validate',
			[ 'error' => false ],
			null,
			$field,
			$product_id,
			0,
			1,
			false,
			null
		);
		$results['dispatch']['raw_apply_filters'] = [
			'threw'     => false,
			'returned'  => is_array( $out_a ) ? $out_a : (string) $out_a,
			'type'      => gettype( $out_a ),
		];
	} catch ( \Throwable $e ) {
		$results['dispatch']['raw_apply_filters'] = [
			'threw'      => true,
			'exception'  => get_class( $e ),
			'message'    => $e->getMessage(),
			'file'       => $e->getFile(),
			'line'       => $e->getLine(),
			'trace'      => $e->getTraceAsString(),
			'payload'    => [
				'filter'      => 'wapf/validate',
				'arg1'        => [ 'error' => false ],
				'arg3_type'   => gettype( $field ),
				'arg3_id'     => $field['id'] ?? null,
				'arg3_keys'   => array_keys( $field ),
			],
		];
	}

	try {
		$value = null;
		$out_b = WapfHooks::validate_field( $field, $value, $product, 1 );
		$results['dispatch']['wapfhooks_bridge'] = [
			'threw'    => false,
			'returned' => $out_b,
		];
	} catch ( \Throwable $e ) {
		$results['dispatch']['wapfhooks_bridge'] = [
			'threw'     => true,
			'exception' => get_class( $e ),
			'message'   => $e->getMessage(),
			'trace'     => $e->getTraceAsString(),
		];
	}

	// C. Confirm the native callback survives the bridge dispatch (restored).
	$native_after = null;
	$callbacks = is_object( $registry ) && isset( $GLOBALS['wp_filter']['wapf/validate']->callbacks ) ? $GLOBALS['wp_filter']['wapf/validate']->callbacks : [];
	foreach ( $callbacks as $priority => $entries ) {
		foreach ( $entries as $entry ) {
			$cb = $entry['function'] ?? null;
			if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) && is_object( $cb[0] )
				&& is_a( $cb[0], 'SW_WAPF_PRO\\Includes\\Controllers\\Linked_Products_Controller' )
				&& 'validate_cart' === strtolower( (string) $cb[1] ) ) {
				$native_after = (int) $priority;
			}
		}
	}
	$results['native_callback_after_bridge'] = $native_after;

	$json = wp_json_encode( $results, JSON_PRETTY_PRINT );
	$target = trailingslashit( $out ) . 'row5-image-quantity-zoom/typeerror-reproduction.json';
	if ( ! is_dir( dirname( $target ) ) ) {
		mkdir( dirname( $target ), 0700, true );
	}
	file_put_contents( $target, $json );
	echo $json . "\n";
	return;
}

if ( 'cleanup' === $phase ) {
	$product_id = (int) get_option( 'opf_wapfref_iqz_product', 0 );
	$group_id   = (int) get_option( 'opf_wapfref_iqz_group', 0 );
	if ( $product_id ) {
		$p = wc_get_product( $product_id );
		if ( $p ) {
			$p->delete( true );
		}
		wp_delete_post( $product_id, true );
	}
	if ( $group_id ) {
		wp_delete_post( $group_id, true );
	}
	delete_option( 'opf_wapfref_iqz_product' );
	delete_option( 'opf_wapfref_iqz_group' );
	FieldGroups::flush_cache();
	if ( file_exists( $state_file ) ) {
		unlink( $state_file );
	}
	echo "ok iqz coexistence cleanup\n";
	return;
}

throw new RuntimeException( 'Expected setup, reproduce, or cleanup.' );
