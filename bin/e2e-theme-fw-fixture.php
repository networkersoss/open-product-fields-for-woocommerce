<?php
/**
 * Disposable WooCommerce fixture for the OPF vs WAPF-Extended theme runs
 * (ledger rows WAPF-COMPAT-FLATSOME / WAPF-COMPAT-WOODMART).
 *
 * Usage (inside one of the two disposable theme clones only):
 *
 *   wp --path=/tmp/opf-theme-flatsome-wp eval-file \
 *     bin/e2e-theme-fw-fixture.php prepare|cleanup
 *
 *   wp --path=/tmp/opf-theme-woodmart-wp eval-file \
 *     bin/e2e-theme-fw-fixture.php prepare|cleanup
 *
 * Builds two variable products that differ only by engine:
 *
 *   slug `opf-theme-fields`  -> OPF-native group (OPF\Engine\FieldGroup)
 *   slug `wapf-theme-fields` -> WAPF Extended 3.1.5 group (`_wapf_fieldgroup`)
 *
 * Both products share the exact same shape:
 *
 *   attribute        size = { small, large }
 *   variation images size=small -> v-small.png, size=large -> v-large.png
 *   featured image   main.png  (the "original" the engines must restore)
 *   gallery          [ v-small, v-large, rule-a, rule-b ]
 *   field `finish`   select { none, gold (+10.00), silver (+5.00) }
 *   field `edge`     select { none, xl (+7.00) }
 *   rule 1           finish=gold -> rule-a
 *   rule 2           edge=xl     -> rule-b
 *
 * `rule-a` / `rule-b` are gallery attachments, so a matching rule navigates
 * (or paints) the theme's real product gallery. Both engines are authored in
 * one run — the theme clones keep OPF and WAPF installed side by side and the
 * browser runs deactivate one engine at a time.
 *
 * State (theme, versions, product/group/attachment ids, every URL, the
 * selectors the browser harness uses) is written to
 * docs/compatibility/themes-fw-20261006/fixture-state-<theme>.json.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'opf_tfw_fail' ) ) {
	/**
	 * Abort the harness with a non-zero status (thrown, not WP_CLI, so the
	 * file stays analyzable without WP-CLI stubs; sibling harnesses such as
	 * bin/e2e-image-change-variable-fixture.php use the same contract).
	 *
	 * @param string $message Failure detail.
	 */
	function opf_tfw_fail( string $message ): void {
		throw new RuntimeException( $message );
	}
}
if ( ! function_exists( 'opf_tfw_ok' ) ) {
	/**
	 * Report a successful phase.
	 *
	 * @param string $message Success detail.
	 */
	function opf_tfw_ok( string $message ): void {
		echo 'SUCCESS ' . $message . "\n";
	}
}

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';

$allowed_clones = [
	'/tmp/opf-theme-flatsome-wp',
	'/tmp/opf-theme-woodmart-wp',
];
$clone = realpath( ABSPATH );
if ( ! in_array( $clone, $allowed_clones, true ) ) {
	opf_tfw_fail( 'Guarded: run inside ' . implode( ' or ', $allowed_clones ) . '.' );
}
if ( ! class_exists( 'WC_Product_Variable' ) ) {
	opf_tfw_fail( 'WooCommerce must be active.' );
}
if ( ! class_exists( 'OPF\Engine\FieldGroup' ) && ! class_exists( '\SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
	opf_tfw_fail( 'OPF or WAPF Extended must be active to author the fixture.' );
}
$opf_available  = class_exists( 'OPF\Engine\FieldGroup' );
$wapf_available = class_exists( '\SW_WAPF_PRO\Includes\Classes\Field_Groups' );
$previous_state = get_option( 'opf_tfw_fixture_' . strtolower( (string) wp_get_theme()->get( 'Name' ) ), [] );

$theme       = wp_get_theme();
$theme_key   = strtolower( (string) $theme->get( 'Name' ) ); // flatsome | woodmart
$theme_name  = (string) $theme->get( 'Name' );
$theme_ver   = (string) $theme->get( 'Version' );
$art_dir     = dirname( __DIR__ ) . '/docs/compatibility/themes-fw-20261006';
$state_file  = $art_dir . '/fixture-state-' . $theme_key . '.json';
$fixture_option = 'opf_tfw_fixture_' . $theme_key;

$image_files = [
	'main'   => 'opf-tfw-main.png',
	'vsmall' => 'opf-tfw-v-small.png',
	'vlarge' => 'opf-tfw-v-large.png',
	'rulea'  => 'opf-tfw-rule-a.png',
	'ruleb'  => 'opf-tfw-rule-b.png',
];

// 64x64 solid-colour PNGs so the harness screenshots show the swap without
// reading the src attribute: main = dark grey, v-small = blue, v-large = green,
// rule-a = red, rule-b = orange.
$png_bytes = [
	'main'   => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAATElEQVR42u3PMQ0AAAwDoAqrf12VsHsJOCB9LgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIClwEYSECmvrm0WAAAAABJRU5ErkJggg==' ),
	'vsmall' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAT0lEQVR42u3PQQkAAAgEsItjJhMbywi+hcEKLNXzWgQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQELgtZCaEttSk+KwAAAABJRU5ErkJggg==' ),
	'vlarge' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAT0lEQVR42u3PQQkAAAgEsEtiMIOZ1wi+hcEKLDX9WgQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQELgtMEODxIZ5kjQAAAABJRU5ErkJggg==' ),
	'rulea'  => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAT0lEQVR42u3PQQkAAAgEsItz/VMYywi+hcEKLNO+FgEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQGBywLvDoEAVSaz4wAAAABJRU5ErkJggg==' ),
	'ruleb'  => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAT0lEQVR42u3PQQkAAAgEsEtibCOawwi+hcEKLNP1WgQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQELgvsHKF4YOwHoAAAAABJRU5ErkJggg==' ),
];

// A fresh WooCommerce install ships with "Coming soon" store visibility on,
// which hides the single-product add-to-cart form — and with it every field
// group hooked on `woocommerce_before_add_to_cart_button`.
$coming_soon      = get_option( 'woocommerce_coming_soon', 'no' );
$store_pages_only = get_option( 'woocommerce_store_pages_only', 'no' );
$summary_mode     = get_option( 'opf_price_summary_mode', null );
$wapf_summary     = get_option( 'wapf_pricing_summary', null );

// The clone's cart page ships the WooCommerce cart *block*, which renders the
// line items client-side through the Store API, so `.cart_item` is never in the
// served DOM. Both engines publish cart-line data through the classic
// `woocommerce_get_item_data` path, so the run switches the cart page to the
// classic shortcode and restores the previous content on cleanup.
$cart_page_id      = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'cart' ) : 0;
$cart_page_content = $cart_page_id > 0 ? (string) get_post_field( 'post_content', $cart_page_id ) : '';

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
	if ( null === $state['summary_mode'] ) {
		delete_option( 'opf_price_summary_mode' );
	} else {
		update_option( 'opf_price_summary_mode', $state['summary_mode'] );
	}
	if ( null === $state['wapf_summary'] ) {
		delete_option( 'wapf_pricing_summary' );
	} else {
		update_option( 'wapf_pricing_summary', $state['wapf_summary'] );
	}
	if ( ! empty( $state['cart_page_id'] ) ) {
		wp_update_post( [ 'ID' => (int) $state['cart_page_id'], 'post_content' => (string) ( $state['cart_page_content'] ?? '' ) ] );
	}
	if ( class_exists( 'OPF\Service\FieldGroups' ) ) {
		OPF\Service\FieldGroups::flush_cache();
	}
	opf_tfw_ok( 'Theme fixture removed for ' . $theme_name . '.' );
	return;
}

if ( 'prepare' !== $action ) {
	opf_tfw_fail( 'Expected prepare or cleanup.' );
}

update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
// Both engines only write their visible totals rows when their own summary
// option says so; the run asserts on those rows.
update_option( 'opf_price_summary_mode', 'three_line' );
update_option( 'wapf_pricing_summary', 'lines' );
if ( $cart_page_id > 0 && false === strpos( $cart_page_content, '[woocommerce_cart]' ) ) {
	wp_update_post( [ 'ID' => $cart_page_id, 'post_content' => '[woocommerce_cart]' ] );
}

$upload = wp_upload_dir();
$attachment_id = [];
foreach ( $image_files as $key => $filename ) {
	$path = trailingslashit( $upload['basedir'] ) . $filename;
	$png  = $png_bytes[ $key ];
	if ( is_file( $path ) && hash( 'sha256', $png ) !== hash_file( 'sha256', $path ) ) {
		// Re-preparing after a fixture-image change replaces the file in place.
		file_put_contents( $path, $png );
	}
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $png );
	}
	$existing = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_tfw_fixture', 'meta_value' => $key ] );
	if ( $existing ) {
		$attachment_id[ $key ] = (int) $existing[0]->ID;
		continue;
	}
	$aid = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF TFW ' . $key, 'post_status' => 'inherit' ], $path, 0, true );
	if ( is_wp_error( $aid ) || ! $aid ) {
		opf_tfw_fail( 'Could not create attachment: ' . $filename );
	}
	update_post_meta( (int) $aid, '_opf_tfw_fixture', $key );
	$attachment_id[ $key ] = (int) $aid;
}

$urls = [];
foreach ( $attachment_id as $key => $aid ) {
	$urls[ $key ] = wp_get_attachment_url( $aid );
}

if ( ! function_exists( 'opf_tfw_upsert_product' ) ) {
	/**
	 * Create or reuse one variable fixture product with two image variations.
	 *
	 * @param array<string,mixed> $fixture   Fixture row (title/slug).
	 * @param array<string,int>   $image_ids Attachment ids keyed by fixture key.
	 * @return int Product id.
	 */
	function opf_tfw_upsert_product( array $fixture, array $image_ids ): int {
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

if ( ! function_exists( 'opf_tfw_select_field' ) ) {
	/**
	 * Shared select-field definition so both engines author the same control.
	 *
	 * @param string             $id      Field id.
	 * @param array<int,array{0:string,1:string,2:float}> $choices [slug,label,price] rows (after `none`).
	 * @return array<string,mixed>
	 */
	function opf_tfw_select_field( string $id, array $choices ): array {
		$normalized = [ [ 'slug' => 'none', 'label' => 'None', 'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ] ] ];
		foreach ( $choices as $choice ) {
			$normalized[] = [
				'slug'    => $choice[0],
				'label'   => $choice[1],
				'pricing' => [ 'type' => 'fixed', 'amount' => (float) $choice[2], 'formula' => '' ],
			];
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

if ( ! function_exists( 'opf_tfw_wapf_group_raw' ) ) {
	/**
	 * WAPF-authored field group (two priced select fields, two gallery rules).
	 *
	 * @param string                $group_id Local group id.
	 * @param array<string,string>  $urls     Attachment urls (rulea/ruleb).
	 * @param array<string,int>     $ids      Attachment ids (rulea/ruleb).
	 * @return array<string,mixed>
	 */
	function opf_tfw_wapf_group_raw( string $group_id, array $urls, array $ids ): array {
		$select = static function ( string $id, array $choices ): array {
			$rows = [ [ 'slug' => 'none', 'label' => 'None', 'pricing_type' => 'none', 'pricing_amount' => '', 'selected' => true, 'disabled' => false, 'options' => [] ] ];
			foreach ( $choices as $choice ) {
				$rows[] = [
					'slug'           => $choice[0],
					'label'          => $choice[1],
					'pricing_type'   => 'fixed',
					'pricing_amount' => (string) $choice[2],
					'selected'       => false,
					'disabled'       => false,
					'options'        => [],
				];
			}
			return [
				'id'           => $id,
				'type'         => 'select',
				'label'        => ucfirst( $id ),
				'width'        => 100,
				'class'        => '',
				'required'     => false,
				'choices'      => $rows,
				'conditionals' => [],
				'pricing'      => [ 'enabled' => 'false', 'type' => 'none', 'amount' => '0' ],
			];
		};

		return [
			'id'         => $group_id,
			'type'       => 'wapf_product',
			'fields'     => [
				$select( 'finish', [ [ 'gold', 'Gold', 10 ], [ 'silver', 'Silver', 5 ] ] ),
				$select( 'edge', [ [ 'xl', 'XL', 7 ] ] ),
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

$products = [
	'opf-theme-fields'  => [ 'engine' => 'opf', 'title' => 'OPF Theme Fields ' . ucfirst( $theme_key ), 'slug' => 'opf-theme-fields' ],
	'wapf-theme-fields' => [ 'engine' => 'wapf', 'title' => 'WAPF Theme Fields ' . ucfirst( $theme_key ), 'slug' => 'wapf-theme-fields' ],
];

$state = [
	'utc'            => gmdate( 'c' ),
	'theme'          => $theme_key,
	'theme_name'     => $theme_name,
	'theme_version'  => $theme_ver,
	'base_url'       => untrailingslashit( home_url() ),
	'summary_mode'   => $summary_mode,
	'wapf_summary'   => $wapf_summary,
	'cart_page_id'   => $cart_page_id,
	'cart_page_content' => $cart_page_content,
	'coming_soon'    => $coming_soon,
	'store_pages_only' => $store_pages_only,
	'images'         => [
		'main'   => $urls['main'],
		'vsmall' => $urls['vsmall'],
		'vlarge' => $urls['vlarge'],
		'rulea'  => $urls['rulea'],
		'ruleb'  => $urls['ruleb'],
	],
	'attachment_ids' => $attachment_id,
	'products'       => [],
	'product_ids'    => [],
	'group_ids'      => [],
];

foreach ( $products as $key => $fixture ) {
	$pid = opf_tfw_upsert_product( $fixture, $attachment_id );
	$state['product_ids'][] = $pid;

	$group_title = $fixture['title'] . ' Group';
	$existing_groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => 1, 'title' => $group_title ] );
	$group_post_id   = $existing_groups ? (int) $existing_groups[0]->ID : 0;

	if ( 'opf' === $fixture['engine'] && ! $opf_available ) {
		// Re-preparing with the other engine active must not destroy the
		// existing product state (the browser runs toggle one engine at a time).
		$state['products'][ $key ] = $previous_state['products'][ $key ] ?? [];
		continue;
	}
	if ( 'wapf' === $fixture['engine'] && ! $wapf_available ) {
		$state['products'][ $key ] = $previous_state['products'][ $key ] ?? [];
		continue;
	}

	if ( 'opf' === $fixture['engine'] ) {
		$group = new OPF\Engine\FieldGroup( [
			'fields'      => [
				opf_tfw_select_field( 'finish', [ [ 'gold', 'Gold', 10 ], [ 'silver', 'Silver', 5 ] ] ),
				opf_tfw_select_field( 'edge', [ [ 'xl', 'XL', 7 ] ] ),
			],
			'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
			'image_rules' => [
				[ 'target_url' => $urls['rulea'], 'conditions' => [ [ 'field' => 'finish', 'value' => 'gold' ] ] ],
				[ 'target_url' => $urls['ruleb'], 'conditions' => [ [ 'field' => 'edge', 'value' => 'xl' ] ] ],
			],
		] );
		$gid = OPF\Service\FieldGroups::save( $group_post_id, $group, [ 'title' => $group_title, 'status' => 'publish' ] );
		if ( ! $gid ) {
			opf_tfw_fail( 'OPF group did not save for ' . $key );
		}
		$state['group_ids'][] = (int) $gid;
		$state['products'][ $key ] = [
			'engine'   => 'opf',
			'product'  => $pid,
			'group'    => (int) $gid,
			'url'      => get_permalink( $pid ),
			'finish'   => '[data-opf-field="finish"] select',
			'edge'     => '[data-opf-field="edge"] select',
			'totals'   => '.opf-product-totals',
			'options'  => '.opf-product-totals .opf-options-total',
			'grand'    => '.opf-product-totals .opf-grand-total',
		];
	} else {
		$raw = opf_tfw_wapf_group_raw( 'p_' . $pid, $urls, $attachment_id );
		// WAPF's own normalizer stays the single source of truth for the stored
		// `_wapf_fieldgroup` shape.
		$wapf_field_groups = 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups';
		$fg                = $wapf_field_groups::raw_json_to_field_group( $raw );
		if ( ! $fg ) {
			opf_tfw_fail( 'WAPF rejected the authored group payload for ' . $key );
		}
		update_post_meta( $pid, '_wapf_fieldgroup', $fg->to_array() );
		$state['products'][ $key ] = [
			'engine'   => 'wapf',
			'product'  => $pid,
			'group'    => 0,
			'url'      => get_permalink( $pid ),
			'finish'   => 'select[name="wapf[field_finish]"]',
			'edge'     => 'select[name="wapf[field_edge]"]',
			'totals'   => '.wapf-product-totals',
			'options'  => '.wapf-product-totals .wapf-options-total',
			'grand'    => '.wapf-product-totals .wapf-grand-total',
		];
	}

	$product = wc_get_product( $pid );
	if ( ! $product || count( $product->get_children() ) !== 2 ) {
		opf_tfw_fail( 'Fixture product ' . $key . ' did not keep two variations.' );
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
file_put_contents( $state_file, wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
update_option( $fixture_option, $state, false );

echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
opf_tfw_ok( 'Theme fixture prepared for ' . $theme_name . ' ' . $theme_ver . ' (2 products).' );
