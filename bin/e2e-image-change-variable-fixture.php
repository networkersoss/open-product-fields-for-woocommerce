<?php
/**
 * Disposable WooCommerce variable-product fixture for the OPF / WAPF-Extended
 * image-change variation lifecycle (ledger row WAPF-INTERACTION-IMAGE-CHANGE).
 *
 * Usage (inside the disposable clone only):
 *
 *   wp --path=/tmp/opf-image-change-var-wp eval-file \
 *     bin/e2e-image-change-variable-fixture.php prepare|cleanup
 *
 * Prepares four variable products — one per engine (`OPF`, `WAPF`) x swap mode
 * (`rules`, `last`) — that share the exact same shape:
 *
 *   attribute        size = { small, large }
 *   variation images size=small -> v-small.png, size=large -> v-large.png
 *   featured image   main.png  (the "original" the engines must restore)
 *   gallery          [ v-small, v-large, rule-a, rule-b ]
 *   field ids        finish { none, gold, silver } , edge { none, xl }
 *   rule 1           finish=gold -> rule-a
 *   rule 2           edge=xl     -> rule-b
 *
 * `rule-a` / `rule-b` are gallery attachments, so a matching rule navigates the
 * real WooCommerce flexslider in the plain-gallery run. The fixture state is
 * written to docs/compatibility/image-change-variable-20261006/fixture-state.json
 * for bin/e2e-image-change-variable-browser.mjs.
 *
 * `last` mode only lets the field the shopper changed last select a rule, so
 * `finish=gold` + `edge=xl` resolves to rule-a (`rules` mode resolves to rule-b
 * because the later rule wins) — the modes are observationally distinct.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'opf_icv_fail' ) ) {
	/**
	 * Abort the harness with a non-zero status (thrown, not WP_CLI, so the
	 * file stays analyzable without WP-CLI stubs; sibling harnesses such as
	 * bin/e2e-bulk-choice-fixture.php use the same contract).
	 *
	 * @param string $message Failure detail.
	 */
	function opf_icv_fail( string $message ): void {
		throw new RuntimeException( $message );
	}
}
if ( ! function_exists( 'opf_icv_ok' ) ) {
	/**
	 * Report a successful phase.
	 *
	 * @param string $message Success detail.
	 */
	function opf_icv_ok( string $message ): void {
		echo 'SUCCESS ' . $message . "\n";
	}
}
if ( ! function_exists( 'opf_icv_log' ) ) {
	/**
	 * Report machine-readable state.
	 *
	 * @param string $line Line to print.
	 */
	function opf_icv_log( string $line ): void {
		echo $line . "\n";
	}
}

$action  = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$clone   = '/tmp/opf-image-change-var-wp';
$art_dir = dirname( __DIR__ ) . '/docs/compatibility/image-change-variable-20261006';

if ( realpath( ABSPATH ) !== realpath( $clone ) && '1' !== getenv( 'OPF_ICV_ALLOW' ) ) {
	opf_icv_fail( 'Guarded: run inside the disposable clone ' . $clone . ' (or set OPF_ICV_ALLOW=1).' );
}
if ( ! class_exists( 'WC_Product_Variable' ) ) {
	opf_icv_fail( 'WooCommerce must be active.' );
}
if ( ! defined( 'OPF_VERSION' ) ) {
	opf_icv_fail( 'OPF must be active.' );
}

$products = [
	'opf-rules'  => [ 'engine' => 'opf', 'mode' => 'rules', 'title' => 'OPF Image Change Var Rules', 'slug' => 'opf-icv-rules' ],
	'opf-last'   => [ 'engine' => 'opf', 'mode' => 'last', 'title' => 'OPF Image Change Var Last', 'slug' => 'opf-icv-last' ],
	'wapf-rules' => [ 'engine' => 'wapf', 'mode' => 'rules', 'title' => 'WAPF Image Change Var Rules', 'slug' => 'wapf-icv-rules' ],
	'wapf-last'  => [ 'engine' => 'wapf', 'mode' => 'last', 'title' => 'WAPF Image Change Var Last', 'slug' => 'wapf-icv-last' ],
];

// The `wp server`/`php -S` router rewrites home/siteurl per request; authoring
// every URL from the served origin keeps rule targets and variant posts aligned
// with the browser run. Defaults to the clone constants, overridable for a
// different loopback port.
$base_url = untrailingslashit( trim( (string) getenv( 'OPF_ICV_BASE_URL' ) ) );
if ( '' !== $base_url ) {
	add_filter( 'option_home', static function () use ( $base_url ) { return $base_url; }, 20 );
	add_filter( 'option_siteurl', static function () use ( $base_url ) { return $base_url; }, 20 );
	add_filter( 'upload_dir', static function ( array $uploads ) use ( $base_url ) {
		foreach ( [ 'baseurl', 'url' ] as $key ) {
			if ( ! empty( $uploads[ $key ] ) ) {
				$uploads[ $key ] = $base_url . wp_parse_url( $uploads[ $key ], PHP_URL_PATH );
			}
		}
		return $uploads;
	} );
}

$image_files = [
	'main'   => 'opf-icv-main.png',
	'vsmall' => 'opf-icv-v-small.png',
	'vlarge' => 'opf-icv-v-large.png',
	'rulea'  => 'opf-icv-rule-a.png',
	'ruleb'  => 'opf-icv-rule-b.png',
];

/** 1x1 transparent PNG — the URL/filename, not the pixels, is what the harness observes. */
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' );

$fixture_option = 'opf_icv_fixture';

// A fresh WooCommerce install ships with "Coming soon" store visibility on,
// which hides the single-product add-to-cart form — and with it every field
// group hooked on `woocommerce_before_add_to_cart_button`. Record the original
// values so cleanup restores them, then make the storefront visible for the run.
$coming_soon      = get_option( 'woocommerce_coming_soon', 'no' );
$store_pages_only = get_option( 'woocommerce_store_pages_only', 'no' );

if ( 'cleanup' === $action ) {
	$state = get_option( $fixture_option, [] );
	foreach ( (array) ( $state['product_ids'] ?? [] ) as $pid ) {
		$product = wc_get_product( (int) $pid );
		if ( $product ) {
			$product->delete( true );
		}
		wp_delete_post( (int) $pid, true );
	}
	foreach ( (array) ( $state['group_ids'] ?? [] ) as $gid ) {
		wp_delete_post( (int) $gid, true );
	}
	foreach ( (array) ( $state['attachment_ids'] ?? [] ) as $aid ) {
		$file = get_attached_file( (int) $aid );
		wp_delete_attachment( (int) $aid, true );
		if ( $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	delete_option( $fixture_option );
	update_option( 'woocommerce_coming_soon', (string) ( $state['coming_soon'] ?? 'no' ) );
	update_option( 'woocommerce_store_pages_only', (string) ( $state['store_pages_only'] ?? 'no' ) );
	if ( class_exists( 'OPF\Service\FieldGroups' ) ) {
		OPF\Service\FieldGroups::flush_cache();
	}
	opf_icv_ok( 'Image-change variable fixtures removed.' );
	return;
}

if ( 'prepare' !== $action ) {
	opf_icv_fail( 'Expected prepare or cleanup.' );
}

update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );

$upload       = wp_upload_dir();
$attachment_id = [];
foreach ( $image_files as $key => $filename ) {
	$path = trailingslashit( $upload['basedir'] ) . $filename;
	if ( is_file( $path ) && hash( 'sha256', $png ) !== hash_file( 'sha256', $path ) ) {
		opf_icv_fail( 'Image path exists with different content: ' . $filename );
	}
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $png );
	}
	$existing = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_icv_fixture', 'meta_value' => $key ] );
	if ( $existing ) {
		$attachment_id[ $key ] = (int) $existing[0]->ID;
		continue;
	}
	$aid = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF ICV ' . $key, 'post_status' => 'inherit' ], $path, 0, true );
	if ( is_wp_error( $aid ) || ! $aid ) {
		opf_icv_fail( 'Could not create attachment: ' . $filename );
	}
	update_post_meta( (int) $aid, '_opf_icv_fixture', $key );
	$attachment_id[ $key ] = (int) $aid;
}

$urls = [];
foreach ( $attachment_id as $key => $aid ) {
	$urls[ $key ] = wp_get_attachment_url( $aid );
}

if ( ! function_exists( 'opf_icv_upsert_product' ) ) {
	/**
	 * Create or reuse one variable fixture product with two image variations.
	 *
	 * @param array<string,mixed> $fixture   Fixture row (title/slug).
	 * @param array<string,int>   $image_ids Attachment ids keyed by fixture key.
	 * @return int Product id.
	 */
	function opf_icv_upsert_product( array $fixture, array $image_ids ): int {
		$existing = get_posts( [ 'post_type' => 'product', 'post_status' => [ 'publish', 'draft', 'private' ], 'posts_per_page' => 1, 'name' => $fixture['slug'] ] );
		$product  = $existing ? wc_get_product( $existing[0]->ID ) : new WC_Product_Variable();
		if ( ! $product instanceof WC_Product_Variable ) {
			$product = new WC_Product_Variable();
		}
		$product->set_name( $fixture['title'] );
		$product->set_slug( $fixture['slug'] );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_featured( false );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'size' );
		$attribute->set_options( [ 'small', 'large' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$product->set_attributes( [ $attribute ] );

		$product->set_image_id( $image_ids['main'] );
		$product->set_gallery_image_ids( [ $image_ids['vsmall'], $image_ids['vlarge'], $image_ids['rulea'], $image_ids['ruleb'] ] );
		$pid = (int) $product->save();

		foreach ( [ 'small' => $image_ids['vsmall'], 'large' => $image_ids['vlarge'] ] as $term => $image_id ) {
			$existing_variations = $product->get_children();
			$variation           = null;
			foreach ( $existing_variations as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof WC_Product_Variation && $child->get_attribute( 'size' ) === $term ) {
					$variation = $child;
					break;
				}
			}
			if ( ! $variation ) {
				$variation = new WC_Product_Variation();
			}
			$variation->set_parent_id( $pid );
			$variation->set_attributes( [ 'size' => $term ] );
			$variation->set_regular_price( '100.00' );
			$variation->set_status( 'publish' );
			$variation->set_image_id( $image_id );
			$variation->save();
		}

		WC_Product_Variable::sync( $pid );
		wc_delete_product_transients( $pid );

		return $pid;
	}
}

if ( ! function_exists( 'opf_icv_select_field' ) ) {
	/**
	 * Shared select-field definition so both engines author the same control.
	 *
	 * @param string              $id      Field id.
	 * @param array<int,string[]> $choices [slug,label] pairs (after `none`).
	 * @return array<string,mixed>
	 */
	function opf_icv_select_field( string $id, array $choices ): array {
		$normalized = [ [ 'slug' => 'none', 'label' => 'None' ] ];
		foreach ( $choices as $choice ) {
			$normalized[] = [ 'slug' => $choice[0], 'label' => $choice[1] ];
		}
		return [
			'id'      => $id,
			'label'   => ucfirst( $id ),
			'type'    => 'select',
			'default' => 'none',
			'choices' => $normalized,
		];
	}
}

if ( ! function_exists( 'opf_icv_wapf_group_raw' ) ) {
	/**
	 * WAPF-authored field group (two fields, two gallery rules).
	 *
	 * @param string             $group_id Local group id.
	 * @param string             $swap     rules|last.
	 * @param array<string,string> $urls   Attachment urls (rulea/ruleb).
	 * @param array<string,int>  $ids      Attachment ids (rulea/ruleb).
	 * @return array<string,mixed>
	 */
	function opf_icv_wapf_group_raw( string $group_id, string $swap, array $urls, array $ids ): array {
		$select = static function ( string $id ): array {
			return [
				'id'           => $id,
				'type'         => 'select',
				'label'        => ucfirst( $id ),
				'width'        => 100,
				'class'        => '',
				'required'     => false,
				'choices'      => [
					[ 'slug' => 'none', 'label' => 'None', 'pricing_type' => 'none', 'pricing_amount' => '', 'selected' => true, 'disabled' => false, 'options' => [] ],
					[ 'slug' => 'gold', 'label' => 'Gold', 'pricing_type' => 'none', 'pricing_amount' => '', 'selected' => false, 'disabled' => false, 'options' => [] ],
					[ 'slug' => 'silver', 'label' => 'Silver', 'pricing_type' => 'none', 'pricing_amount' => '', 'selected' => false, 'disabled' => false, 'options' => [] ],
				],
				'conditionals' => [],
				'pricing'      => [ 'enabled' => 'false', 'type' => 'none', 'amount' => '0' ],
			];
		};
		$edge = $select( 'edge' );
		$edge['choices'][1]['slug']  = 'xl';
		$edge['choices'][1]['label'] = 'XL';

		return [
			'id'         => $group_id,
			'type'       => 'wapf_product',
			'fields'     => [ $select( 'finish' ), $edge ],
			'conditions' => [],
			'layout'     => [
				'labels_position'       => 'above',
				'instructions_position' => 'label',
				'mark_required'         => true,
				'enable_gallery_images' => true,
				'swap_type'             => $swap,
				'gallery_images'        => [
					[ 'source' => 'upload', 'url' => $urls['rulea'], 'id' => (string) $ids['rulea'], 'values' => [ [ 'field' => 'finish', 'value' => 'gold' ] ] ],
					[ 'source' => 'upload', 'url' => $urls['ruleb'], 'id' => (string) $ids['ruleb'], 'values' => [ [ 'field' => 'edge', 'value' => 'xl' ] ] ],
				],
			],
			'variables'  => [],
		];
	}
}

$state = [
	'utc'            => gmdate( 'c' ),
	'base_url'       => untrailingslashit( home_url() ),
	'images'         => [
		'main'   => $urls['main'],
		'vsmall' => $urls['vsmall'],
		'vlarge' => $urls['vlarge'],
		'rulea'  => $urls['rulea'],
		'ruleb'  => $urls['ruleb'],
	],
	'attachment_ids' => $attachment_id,
	'coming_soon'   => $coming_soon,
	'store_pages_only' => $store_pages_only,
	'products'       => [],
	'product_ids'    => [],
	'group_ids'      => [],
];

foreach ( $products as $key => $fixture ) {
	$pid = opf_icv_upsert_product( $fixture, $attachment_id );
	$state['product_ids'][] = $pid;

	$group_title = $fixture['title'] . ' Group';
	$existing_groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => 1, 'title' => $group_title ] );
	$group_post_id   = $existing_groups ? (int) $existing_groups[0]->ID : 0;

	if ( 'opf' === $fixture['engine'] ) {
		$group = new OPF\Engine\FieldGroup( [
			'fields'          => [
				opf_icv_select_field( 'finish', [ [ 'gold', 'Gold' ], [ 'silver', 'Silver' ] ] ),
				opf_icv_select_field( 'edge', [ [ 'xl', 'XL' ] ] ),
			],
			'rule_groups'     => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
			'image_rule_mode' => $fixture['mode'],
			'image_rules'     => [
				[ 'target_url' => $urls['rulea'], 'conditions' => [ [ 'field' => 'finish', 'value' => 'gold' ] ] ],
				[ 'target_url' => $urls['ruleb'], 'conditions' => [ [ 'field' => 'edge', 'value' => 'xl' ] ] ],
			],
		] );
		$gid = OPF\Service\FieldGroups::save( $group_post_id, $group, [ 'title' => $group_title, 'status' => 'publish' ] );
		if ( ! $gid ) {
			opf_icv_fail( 'OPF group did not save for ' . $key );
		}
		$state['group_ids'][] = (int) $gid;
		$saved                = OPF\Service\FieldGroups::group_from_post( get_post( $gid ) );
		if ( ! $saved || count( $saved->data['image_rules'] ?? [] ) !== 2 || ( $saved->data['image_rule_mode'] ?? 'rules' ) !== $fixture['mode'] ) {
			opf_icv_fail( 'OPF group did not round-trip two rules in ' . $fixture['mode'] . ' mode for ' . $key );
		}
		$state['products'][ $key ] = [
			'engine'   => 'opf',
			'mode'     => $fixture['mode'],
			'product'  => $pid,
			'group'    => (int) $gid,
			'url'      => get_permalink( $pid ),
			'selector' => '[data-opf-field="finish"] select',
			'edge'     => '[data-opf-field="edge"] select',
		];
	} else {
		if ( ! class_exists( '\SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
			opf_icv_fail( 'WAPF Extended must be active to build the WAPF fixtures.' );
		}
		$raw = opf_icv_wapf_group_raw( 'p_' . $pid, $fixture['mode'], $urls, $attachment_id );
		// WAPF's own normalizer stays the single source of truth for the stored
		// `_wapf_fieldgroup` shape; resolved at runtime so the harness needs no
		// WAPF class import at parse time.
		$wapf_field_groups = 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups';
		$fg                = $wapf_field_groups::raw_json_to_field_group( $raw );
		if ( ! $fg ) {
			opf_icv_fail( 'WAPF rejected the authored group payload for ' . $key );
		}
		update_post_meta( $pid, '_wapf_fieldgroup', $fg->to_array() );
		$state['products'][ $key ] = [
			'engine'   => 'wapf',
			'mode'     => $fixture['mode'],
			'product'  => $pid,
			'group'    => 0,
			'url'      => get_permalink( $pid ),
			'selector' => 'select[name="wapf[field_finish]"]',
			'edge'     => 'select[name="wapf[field_edge]"]',
		];
	}

	$product = wc_get_product( $pid );
	if ( ! $product || count( $product->get_children() ) !== 2 ) {
		opf_icv_fail( 'Fixture product ' . $key . ' did not keep two variations.' );
	}
	$variations = [];
	foreach ( $product->get_children() as $child_id ) {
		$child        = wc_get_product( $child_id );
		$variations[] = [
			'variation_id' => (int) $child_id,
			'size'         => $child->get_attribute( 'size' ),
			'image_id'     => (int) $child->get_image_id(),
			'image_url'    => wp_get_attachment_url( (int) $child->get_image_id() ),
		];
	}
	$state['products'][ $key ]['variations'] = $variations;
}

if ( ! is_dir( $art_dir ) ) {
	wp_mkdir_p( $art_dir );
}
file_put_contents( $art_dir . '/fixture-state.json', wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
update_option( $fixture_option, $state, false );

opf_icv_log( wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
opf_icv_ok( 'Image-change variable fixture prepared (4 products).' );
