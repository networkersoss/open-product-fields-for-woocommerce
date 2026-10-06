<?php
/**
 * Disposable WooCommerce fixture for the Barn2 Quick View Pro comparison
 * (ledger row WAPF-COMPAT-QUICK-VIEW-PRO).
 *
 * Usage (inside the disposable clone only):
 *
 *   wp --path=/tmp/opf-qv-barn2-wp eval-file \
 *     bin/e2e-quick-view-pro-fixture.php prepare|cleanup
 *
 * Prepares two variable products that share one shape — an OPF group and a
 * WAPF-Extended group authored from the same data:
 *
 *   attribute        size = { small, large } (variation price 100.00)
 *   variation images size=small -> v-small.png, size=large -> v-large.png
 *   featured image   main.png  (the "original" the engines must restore)
 *   gallery          [ v-small, v-large, rule-a, rule-b ]
 *   field ids        finish { none, gold(+15), silver(+5) } , edge { none, xl(+3) }
 *   rule 1           finish=gold -> rule-a
 *   rule 2           edge=xl     -> rule-b
 *
 * The two engines store the same field group in their own format, so each
 * product can only render fields while its engine is active; the browser lane
 * activates one engine at a time. State lands in
 * docs/compatibility/quick-view-pro-20261006/fixture-state.json for
 * bin/e2e-quick-view-pro-browser.mjs, plus the `qv-shop` page carrying the
 * `[products]` shortcode the quick-view button renders in.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'opf_qv_fail' ) ) {
	/**
	 * Abort the harness with a non-zero status.
	 *
	 * @param string $message Failure detail.
	 */
	function opf_qv_fail( string $message ): void {
		throw new RuntimeException( $message );
	}
}
if ( ! function_exists( 'opf_qv_ok' ) ) {
	/**
	 * Report a successful phase.
	 *
	 * @param string $message Success detail.
	 */
	function opf_qv_ok( string $message ): void {
		echo 'SUCCESS ' . $message . "\n";
	}
}
if ( ! function_exists( 'opf_qv_log' ) ) {
	/**
	 * Report machine-readable state.
	 *
	 * @param string $line Line to print.
	 */
	function opf_qv_log( string $line ): void {
		echo $line . "\n";
	}
}

$action  = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$clone   = '/tmp/opf-qv-barn2-wp';
$art_dir = dirname( __DIR__ ) . '/docs/compatibility/quick-view-pro-20261006';

if ( realpath( ABSPATH ) !== realpath( $clone ) && '1' !== getenv( 'OPF_QV_ALLOW' ) ) {
	opf_qv_fail( 'Guarded: run inside the disposable clone ' . $clone . ' (or set OPF_QV_ALLOW=1).' );
}
if ( ! class_exists( 'WC_Product_Variable' ) ) {
	opf_qv_fail( 'WooCommerce must be active.' );
}
if ( ! defined( 'OPF_VERSION' ) ) {
	opf_qv_fail( 'OPF must be active.' );
}

$image_files = [
	'main'   => 'opf-qv-main.png',
	'vsmall' => 'opf-qv-v-small.png',
	'vlarge' => 'opf-qv-v-large.png',
	'rulea'  => 'opf-qv-rule-a.png',
	'ruleb'  => 'opf-qv-rule-b.png',
];

/** 1x1 transparent PNG — the URL/filename, not the pixels, is what the harness observes. */
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' );

$fixture_option = 'opf_qv_fixture';

// A fresh WooCommerce install ships with "Coming soon" store visibility on,
// which hides the shop loop (and with it the quick-view buttons). Record the
// original values so cleanup restores them.
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
	foreach ( [ 'page_id', 'page_inline_id' ] as $page_key ) {
		if ( ! empty( $state[ $page_key ] ) ) {
			wp_delete_post( (int) $state[ $page_key ], true );
		}
	}
	delete_option( $fixture_option );
	update_option( 'woocommerce_coming_soon', (string) ( $state['coming_soon'] ?? 'no' ) );
	update_option( 'woocommerce_store_pages_only', (string) ( $state['store_pages_only'] ?? 'no' ) );
	if ( class_exists( 'OPF\Service\FieldGroups' ) ) {
		OPF\Service\FieldGroups::flush_cache();
	}
	opf_qv_ok( 'Quick-view fixtures removed.' );
	return;
}

if ( 'prepare' !== $action ) {
	opf_qv_fail( 'Expected prepare or cleanup.' );
}

update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );

$upload        = wp_upload_dir();
$attachment_id = [];
foreach ( $image_files as $key => $filename ) {
	$path = trailingslashit( $upload['basedir'] ) . $filename;
	if ( is_file( $path ) && hash( 'sha256', $png ) !== hash_file( 'sha256', $path ) ) {
		opf_qv_fail( 'Image path exists with different content: ' . $filename );
	}
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $png );
	}
	$existing = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_qv_fixture', 'meta_value' => $key ] );
	if ( $existing ) {
		$attachment_id[ $key ] = (int) $existing[0]->ID;
		continue;
	}
	$aid = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF QV ' . $key, 'post_status' => 'inherit' ], $path, 0, true );
	if ( is_wp_error( $aid ) || ! $aid ) {
		opf_qv_fail( 'Could not create attachment: ' . $filename );
	}
	update_post_meta( (int) $aid, '_opf_qv_fixture', $key );
	$attachment_id[ $key ] = (int) $aid;
}

$urls = [];
foreach ( $attachment_id as $key => $aid ) {
	$urls[ $key ] = wp_get_attachment_url( $aid );
}

if ( ! function_exists( 'opf_qv_checkbox_field' ) ) {
	/**
	 * Shared OPF checkbox-field definition with priced choices (the `name[]`
	 * multi-value submission path the quick-view plugin has to serialize).
	 *
	 * @param string              $id      Field id.
	 * @param array<int,string[]> $choices [slug,label,amount] triples.
	 * @return array<string,mixed>
	 */
	function opf_qv_checkbox_field( string $id, array $choices ): array {
		return [
			'id'      => $id,
			'label'   => ucfirst( $id ),
			'type'    => 'checkbox',
			'choices' => array_map( static function ( array $choice ): array {
				return [ 'slug' => $choice[0], 'label' => $choice[1], 'pricing' => [ 'type' => 'fixed', 'amount' => (float) $choice[2] ] ];
			}, $choices ),
		];
	}
}

if ( ! function_exists( 'opf_qv_upsert_product' ) ) {
	/**
	 * Create or reuse one variable fixture product with two image variations.
	 *
	 * @param array<string,mixed> $fixture   Fixture row (title/slug).
	 * @param array<string,int>   $image_ids Attachment ids keyed by fixture key.
	 * @return int Product id.
	 */
	function opf_qv_upsert_product( array $fixture, array $image_ids ): int {
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
			$variation = null;
			foreach ( $product->get_children() as $child_id ) {
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

if ( ! function_exists( 'opf_qv_select_field' ) ) {
	/**
	 * Shared OPF select-field definition with priced choices.
	 *
	 * @param string              $id      Field id.
	 * @param array<int,string[]> $choices [slug,label,amount] triples (after `none`).
	 * @return array<string,mixed>
	 */
	function opf_qv_select_field( string $id, array $choices ): array {
		$normalized = [ [ 'slug' => 'none', 'label' => 'None', 'pricing' => [ 'type' => 'none', 'amount' => 0 ] ] ];
		foreach ( $choices as $choice ) {
			$normalized[] = [ 'slug' => $choice[0], 'label' => $choice[1], 'pricing' => [ 'type' => 'fixed', 'amount' => (float) $choice[2] ] ];
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

if ( ! function_exists( 'opf_qv_wapf_group_raw' ) ) {
	/**
	 * WAPF-authored field group matching opf_qv_select_field().
	 *
	 * @param string               $group_id Local group id.
	 * @param array<string,string> $urls     Attachment urls (rulea/ruleb).
	 * @param array<string,int>    $ids      Attachment ids (rulea/ruleb).
	 * @return array<string,mixed>
	 */
	function opf_qv_wapf_group_raw( string $group_id, array $urls, array $ids ): array {
		$select = static function ( string $id, array $choices ): array {
			$normalized = [ [ 'slug' => 'none', 'label' => 'None', 'pricing_type' => 'none', 'pricing_amount' => '', 'selected' => true, 'disabled' => false, 'options' => [] ] ];
			foreach ( $choices as $choice ) {
				$normalized[] = [ 'slug' => $choice[0], 'label' => $choice[1], 'pricing_type' => 'fixed', 'pricing_amount' => (string) $choice[2], 'selected' => false, 'disabled' => false, 'options' => [] ];
			}
			return [
				'id'           => $id,
				'type'         => 'select',
				'label'        => ucfirst( $id ),
				'width'        => 100,
				'class'        => '',
				'required'     => false,
				'choices'      => $normalized,
				'conditionals' => [],
				'pricing'      => [ 'enabled' => 'false', 'type' => 'none', 'amount' => '0' ],
			];
		};
		$checkboxes = static function ( string $id, array $choices ): array {
			$normalized = [];
			foreach ( $choices as $choice ) {
				$normalized[] = [ 'slug' => $choice[0], 'label' => $choice[1], 'pricing_type' => 'fixed', 'pricing_amount' => (string) $choice[2], 'selected' => false, 'disabled' => false, 'options' => [] ];
			}
			return [
				'id'           => $id,
				'type'         => 'checkboxes',
				'label'        => ucfirst( $id ),
				'width'        => 100,
				'class'        => '',
				'required'     => false,
				'choices'      => $normalized,
				'conditionals' => [],
				'pricing'      => [ 'enabled' => 'false', 'type' => 'none', 'amount' => '0' ],
			];
		};

		return [
			'id'         => $group_id,
			'type'       => 'wapf_product',
			'fields'     => [
				$select( 'finish', [ [ 'gold', 'Gold', 15 ], [ 'silver', 'Silver', 5 ] ] ),
				$select( 'edge', [ [ 'xl', 'XL', 3 ] ] ),
				$checkboxes( 'extras', [ [ 'gift', 'Gift', 2 ], [ 'wrap', 'Wrap', 1 ] ] ),
			],
			'conditions' => [],
			'layout'     => [
				'labels_position'       => 'above',
				'instructions_position' => 'label',
				'mark_required'         => true,
				'enable_gallery_images' => true,
				'swap_type'             => 'rules',
				'gallery_images'        => [
					[ 'source' => 'upload', 'url' => $urls['rulea'], 'id' => (string) $ids['rulea'], 'values' => [ [ 'field' => 'finish', 'value' => 'gold' ] ] ],
					[ 'source' => 'upload', 'url' => $urls['ruleb'], 'id' => (string) $ids['ruleb'], 'values' => [ [ 'field' => 'edge', 'value' => 'xl' ] ] ],
				],
			],
			'variables'  => [],
		];
	}
}

$base_url = untrailingslashit( home_url() );

$state = [
	'utc'            => gmdate( 'c' ),
	'base_url'       => $base_url,
	'images'         => [
		'main'   => $urls['main'],
		'vsmall' => $urls['vsmall'],
		'vlarge' => $urls['vlarge'],
		'rulea'  => $urls['rulea'],
		'ruleb'  => $urls['ruleb'],
	],
	'prices'         => [ 'base' => 100.0, 'gold' => 15.0, 'silver' => 5.0, 'xl' => 3.0, 'gift' => 2.0 ],
	'attachment_ids' => $attachment_id,
	'coming_soon'    => $coming_soon,
	'store_pages_only' => $store_pages_only,
	'products'       => [],
	'product_ids'    => [],
	'group_ids'      => [],
	'page_id'        => 0,
	'page_inline_id' => 0,
];

$product_ids = [];

foreach ( [ 'opf' => 'OPF Quick View Product', 'wapf' => 'WAPF Quick View Product' ] as $engine => $title ) {
	$pid = opf_qv_upsert_product( [ 'title' => $title, 'slug' => 'qv-' . $engine . '-product' ], $attachment_id );
	$state['product_ids'][] = $pid;
	$product_ids[ $engine ] = $pid;

	$group_title     = $title . ' Group';
	$existing_groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => 1, 'title' => $group_title ] );
	$group_post_id   = $existing_groups ? (int) $existing_groups[0]->ID : 0;

	if ( 'opf' === $engine ) {
		$group = new OPF\Engine\FieldGroup( [
			'fields'          => [
				opf_qv_select_field( 'finish', [ [ 'gold', 'Gold', 15 ], [ 'silver', 'Silver', 5 ] ] ),
				opf_qv_select_field( 'edge', [ [ 'xl', 'XL', 3 ] ] ),
				opf_qv_checkbox_field( 'extras', [ [ 'gift', 'Gift', 2 ], [ 'wrap', 'Wrap', 1 ] ] ),
			],
			'rule_groups'     => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
			'image_rule_mode' => 'rules',
			'image_rules'     => [
				[ 'target_url' => $urls['rulea'], 'conditions' => [ [ 'field' => 'finish', 'value' => 'gold' ] ] ],
				[ 'target_url' => $urls['ruleb'], 'conditions' => [ [ 'field' => 'edge', 'value' => 'xl' ] ] ],
			],
		] );
		$gid = OPF\Service\FieldGroups::save( $group_post_id, $group, [ 'title' => $group_title, 'status' => 'publish' ] );
		if ( ! $gid ) {
			opf_qv_fail( 'OPF group did not save.' );
		}
		$state['group_ids'][] = (int) $gid;
		$saved                = OPF\Service\FieldGroups::group_from_post( get_post( $gid ) );
		if ( ! $saved || count( $saved->data['image_rules'] ?? [] ) !== 2 ) {
			opf_qv_fail( 'OPF group did not round-trip two image rules.' );
		}
		$state['products'][ $engine ] = [
			'engine'         => 'opf',
			'product'        => $pid,
			'group'          => (int) $gid,
			'slug'           => 'qv-opf-product',
			'fields_root'    => '[data-opf-fields]',
			'group_root'     => '[data-opf-group="' . (int) $gid . '"]',
			'total_selector' => '.opf-product-totals .opf-grand-total',
			'selector'       => '[data-opf-field="finish"] select',
			'edge'           => '[data-opf-field="edge"] select',
			'gift'           => '[data-opf-field="extras"] input[type="checkbox"][value="gift"]',
		];
	} else {
		if ( ! class_exists( '\SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
			opf_qv_fail( 'WAPF Extended must be active to build the WAPF fixture.' );
		}
		$raw               = opf_qv_wapf_group_raw( 'p_' . $pid, $urls, $attachment_id );
		$wapf_field_groups = 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups';
		$fg                = $wapf_field_groups::raw_json_to_field_group( $raw );
		if ( ! $fg ) {
			opf_qv_fail( 'WAPF rejected the authored group payload.' );
		}
		update_post_meta( $pid, '_wapf_fieldgroup', $fg->to_array() );
		$state['products'][ $engine ] = [
			'engine'         => 'wapf',
			'product'        => $pid,
			'group'          => 0,
			'slug'           => 'qv-wapf-product',
			'fields_root'    => '.wapf-wrapper',
			'group_root'     => '.wapf-field-group',
			'total_selector' => '.wapf-product-totals .wapf-grand-total',
			'selector'       => 'select[name="wapf[field_finish]"]',
			'edge'           => 'select[name="wapf[field_edge]"]',
			'gift'           => 'input[name="wapf[field_extras][]"][value="gift"]',
		];
	}

	$product = wc_get_product( $pid );
	if ( ! $product || count( $product->get_children() ) !== 2 ) {
		opf_qv_fail( 'Fixture product ' . $engine . ' did not keep two variations.' );
	}
	foreach ( $product->get_children() as $child_id ) {
		$child        = wc_get_product( $child_id );
		$state['products'][ $engine ]['variations'][ (string) $child->get_attribute( 'size' ) ] = [
			'variation_id' => (int) $child_id,
			'image_id'     => (int) $child->get_image_id(),
			'image_url'    => wp_get_attachment_url( (int) $child->get_image_id() ),
		];
	}
	$state['products'][ $engine ]['url'] = get_permalink( $pid );
}

// The quick-view button renders in the classic shop loop; a plain page with the
// `[products]` shortcode is the smallest surface that has one and no OPF/WAPF
// fields in the initial HTML (the realistic third-party touchpoint).
$page = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 1, 'name' => 'qv-shop' ] );
$page_id = $page ? (int) $page[0]->ID : 0;
if ( ! $page_id ) {
	$page_id = (int) wp_insert_post( [
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Quick View Shop',
		'post_name'    => 'qv-shop',
		'post_content' => '[products limit="12" columns="2"]',
	] );
}
if ( ! $page_id ) {
	opf_qv_fail( 'Could not create the qv-shop page.' );
}
$state['page_id']    = $page_id;
$state['page_url']   = get_permalink( $page_id );
$state['shop_url']   = get_permalink( wc_get_page_id( 'shop' ) );

// Scenario B: the same shop loop on a page that ALSO renders the OPF product
// inline via `[product_page]`, so OPF's frontend bundle is already loaded and
// its group is already initialised when the quick-view modal is opened from
// the loop. It separates "the bundle was never shipped to this page" from "the
// bundle shipped but the modal content is never initialised".
$page_inline = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => 1, 'name' => 'qv-shop-inline' ] );
$page_inline_id = $page_inline ? (int) $page_inline[0]->ID : 0;
if ( ! $page_inline_id ) {
	$page_inline_id = (int) wp_insert_post( [
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Quick View Shop Inline OPF',
		'post_name'   => 'qv-shop-inline',
	] );
}
if ( ! $page_inline_id ) {
	opf_qv_fail( 'Could not create the qv-shop-inline page.' );
}
wp_update_post( [
	'ID'           => $page_inline_id,
	'post_content' => '[product_page id="' . $product_ids['opf'] . '"] [products limit="12" columns="2"]',
] );
$state['page_inline_id']  = $page_inline_id;
$state['page_inline_url'] = get_permalink( $page_inline_id );

if ( ! is_dir( $art_dir ) ) {
	wp_mkdir_p( $art_dir );
}
file_put_contents( $art_dir . '/fixture-state.json', wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
update_option( $fixture_option, $state, false );

opf_qv_log( wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
opf_qv_ok( 'Quick-view fixture prepared (2 products, 1 page).' );
