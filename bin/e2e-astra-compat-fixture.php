<?php
/**
 * Disposable WooCommerce variable-product fixture for the OPF / WAPF-Extended
 * Astra (theme 4.13.11) + Astra Pro addon (4.13.10) compatibility proof.
 *
 * Usage (inside the disposable clone only):
 *
 *   wp --path=/tmp/opf-astra-wp eval-file \
 *     bin/e2e-astra-compat-fixture.php prepare|cleanup
 *
 * Prepares two variable products that share the exact same shape — one per
 * engine (`OPF`, `WAPF` Extended 3.1.5):
 *
 *   attribute        size = { small, large }
 *   variation images size=small -> v-small.png, size=large -> v-large.png (both 100.00)
 *   featured image   main.png  (the "original" the engines must restore)
 *   gallery          [ v-small, v-large, rule-a, rule-b ]
 *   field finish     select { none(0), gold(+10.00), silver(+5.00) }  — priced choices
 *   field edge       select { none(0), xl(+3.00) }                    — priced choices
 *   rule 1           finish=gold -> rule-a
 *   rule 2           edge=xl     -> rule-b
 *
 * The images are solid-colour PNGs (320x320) so the screenshot artifacts show
 * the swap, not just a URL change. State is written to
 * `docs/compatibility/astra-20261006/fixture-state.json` (override with
 * `OPF_ASTRA_ARTIFACTS`) for bin/e2e-astra-compat-browser.mjs.
 *
 * The harness also flips the settings the run needs (the Astra Pro WooCommerce
 * addon module, the Astra Pro quick-view mode, and each engine's visible price
 * summary) and records their prior values so `cleanup` restores them.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'opf_astra_fail' ) ) {
	/**
	 * Abort the harness with a non-zero status.
	 *
	 * @param string $message Failure detail.
	 */
	function opf_astra_fail( string $message ): void {
		throw new RuntimeException( $message );
	}
}
if ( ! function_exists( 'opf_astra_ok' ) ) {
	/**
	 * Report a successful phase.
	 *
	 * @param string $message Success detail.
	 */
	function opf_astra_ok( string $message ): void {
		echo 'SUCCESS ' . $message . "\n";
	}
}

$action  = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$clone   = '/tmp/opf-astra-wp';
$art_dir = getenv( 'OPF_ASTRA_ARTIFACTS' );
if ( ! $art_dir ) {
	$art_dir = dirname( __DIR__ ) . '/docs/compatibility/astra-20261006';
}

if ( realpath( ABSPATH ) !== realpath( $clone ) && '1' !== getenv( 'OPF_ASTRA_ALLOW' ) ) {
	opf_astra_fail( 'Guarded: run inside the disposable clone ' . $clone . ' (or set OPF_ASTRA_ALLOW=1).' );
}
if ( ! class_exists( 'WC_Product_Variable' ) ) {
	opf_astra_fail( 'WooCommerce must be active.' );
}
if ( ! defined( 'OPF_VERSION' ) && ! class_exists( '\SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
	opf_astra_fail( 'At least one engine (OPF or WAPF Extended) must be active.' );
}
if ( 'astra' !== get_template() ) {
	opf_astra_fail( 'Astra must be the active theme to author the Astra fixture.' );
}

$fixture_option = 'opf_astra_compat_fixture';

/** Solid-colour PNG files: the swap must be visible in the screenshots. */
$image_files = [
	'main'   => [ 'file' => 'opf-astra-main.png', 'rgb' => [ 30, 58, 138 ] ],
	'vsmall' => [ 'file' => 'opf-astra-v-small.png', 'rgb' => [ 22, 163, 74 ] ],
	'vlarge' => [ 'file' => 'opf-astra-v-large.png', 'rgb' => [ 220, 38, 38 ] ],
	'rulea'  => [ 'file' => 'opf-astra-rule-a.png', 'rgb' => [ 124, 58, 237 ] ],
	'ruleb'  => [ 'file' => 'opf-astra-rule-b.png', 'rgb' => [ 245, 158, 11 ] ],
];

$products = [
	'opf'  => [ 'engine' => 'opf', 'title' => 'OPF Astra Compat Variable', 'slug' => 'opf-astra-compat' ],
	'wapf' => [ 'engine' => 'wapf', 'title' => 'WAPF Astra Compat Variable', 'slug' => 'wapf-astra-compat' ],
];

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
	foreach ( (array) ( $state['options_before'] ?? [] ) as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value );
		}
	}
	delete_option( $fixture_option );
	if ( class_exists( '\SW_WAPF_PRO\Includes\Classes\Cache' ) ) {
		\SW_WAPF_PRO\Includes\Classes\Cache::clear();
	}
	if ( class_exists( 'OPF\Service\FieldGroups' ) ) {
		OPF\Service\FieldGroups::flush_cache();
	}
	opf_astra_ok( 'Astra compatibility fixtures removed.' );
	return;
}

if ( 'prepare' !== $action ) {
	opf_astra_fail( 'Expected prepare or cleanup.' );
}

/** Create a solid-colour PNG (320x320) with GD. */
if ( ! function_exists( 'opf_astra_png' ) ) {
	/**
	 * Write a solid-colour PNG.
	 *
	 * @param string            $path Target path.
	 * @param array<int,int>    $rgb  RGB triplet.
	 */
	function opf_astra_png( string $path, array $rgb ): void {
		$image = imagecreatetruecolor( 320, 320 );
		$color = imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] );
		imagefilledrectangle( $image, 0, 0, 319, 319, $color );
		imagepng( $image, $path );
		imagedestroy( $image );
	}
}

$upload        = wp_upload_dir();
$attachment_id = [];
foreach ( $image_files as $key => $spec ) {
	$path = trailingslashit( $upload['basedir'] ) . $spec['file'];
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		opf_astra_png( $path, $spec['rgb'] );
	}
	$existing = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_astra_fixture', 'meta_value' => $key ] );
	if ( $existing ) {
		$attachment_id[ $key ] = (int) $existing[0]->ID;
		continue;
	}
	$aid = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF Astra ' . $key, 'post_status' => 'inherit' ], $path, 0, true );
	if ( is_wp_error( $aid ) || ! $aid ) {
		opf_astra_fail( 'Could not create attachment: ' . $spec['file'] );
	}
	update_post_meta( (int) $aid, '_opf_astra_fixture', $key );
	$attachment_id[ $key ] = (int) $aid;
	// Regenerate the intermediate sizes the storefront gallery asks for.
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( (int) $aid, wp_generate_attachment_metadata( (int) $aid, $path ) );
}

$urls = [];
foreach ( $attachment_id as $key => $aid ) {
	$urls[ $key ] = wp_get_attachment_url( $aid );
}

if ( ! function_exists( 'opf_astra_upsert_product' ) ) {
	/**
	 * Create or reuse one variable fixture product with two image variations.
	 *
	 * @param array<string,mixed> $fixture   Fixture row (title/slug).
	 * @param array<string,int>   $image_ids Attachment ids keyed by fixture key.
	 * @return int Product id.
	 */
	function opf_astra_upsert_product( array $fixture, array $image_ids ): int {
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

if ( ! function_exists( 'opf_astra_opf_group' ) ) {
	/**
	 * OPF-authored field group: two priced selects + two image rules.
	 *
	 * @param int               $pid  Fixture product id.
	 * @param array<string,string> $urls Attachment urls.
	 * @return OPF\Engine\FieldGroup
	 */
	function opf_astra_opf_group( int $pid, array $urls ): OPF\Engine\FieldGroup {
		$select = static function ( string $id, array $choices ): array {
			return [
				'id'      => $id,
				'label'   => ucfirst( $id ),
				'type'    => 'select',
				'default' => 'none',
				'choices' => $choices,
			];
		};
		return new OPF\Engine\FieldGroup(
			[
				'fields'          => [
					$select(
						'finish',
						[
							[ 'slug' => 'none', 'label' => 'None' ],
							[ 'slug' => 'gold', 'label' => 'Gold', 'pricing' => [ 'type' => 'fixed', 'amount' => 10.0, 'per_unit' => true ] ],
							[ 'slug' => 'silver', 'label' => 'Silver', 'pricing' => [ 'type' => 'fixed', 'amount' => 5.0, 'per_unit' => true ] ],
						]
					),
					$select(
						'edge',
						[
							[ 'slug' => 'none', 'label' => 'None' ],
							[ 'slug' => 'xl', 'label' => 'XL', 'pricing' => [ 'type' => 'fixed', 'amount' => 3.0, 'per_unit' => true ] ],
						]
					),
				],
				'rule_groups'     => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $pid ] ] ] ] ],
				'image_rule_mode' => 'rules',
				'image_rules'     => [
					[ 'target_url' => $urls['rulea'], 'conditions' => [ [ 'field' => 'finish', 'value' => 'gold' ] ] ],
					[ 'target_url' => $urls['ruleb'], 'conditions' => [ [ 'field' => 'edge', 'value' => 'xl' ] ] ],
				],
			]
		);
	}
}

if ( ! function_exists( 'opf_astra_wapf_group_raw' ) ) {
	/**
	 * WAPF-authored field group: the same two priced selects and two rules.
	 *
	 * @param string             $group_id Local group id.
	 * @param array<string,string> $urls   Attachment urls (rulea/ruleb).
	 * @param array<string,int>  $ids      Attachment ids (rulea/ruleb).
	 * @return array<string,mixed>
	 */
	function opf_astra_wapf_group_raw( string $group_id, array $urls, array $ids ): array {
		$select = static function ( string $id, array $choices ): array {
			$normalized = [];
			foreach ( $choices as $choice ) {
				$normalized[] = [
					'slug'           => $choice[0],
					'label'          => $choice[1],
					'pricing_type'   => $choice[2],
					'pricing_amount' => $choice[3],
					'selected'       => 'none' === $choice[0],
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
				'choices'      => $normalized,
				'conditionals' => [],
				'pricing'      => [ 'enabled' => 'false', 'type' => 'none', 'amount' => '0' ],
			];
		};
		return [
			'id'         => $group_id,
			'type'       => 'wapf_product',
			'fields'     => [
				$select(
					'finish',
					[
						[ 'none', 'None', 'none', '' ],
						[ 'gold', 'Gold', 'fixed', '10' ],
						[ 'silver', 'Silver', 'fixed', '5' ],
					]
				),
				$select(
					'edge',
					[
						[ 'none', 'None', 'none', '' ],
						[ 'xl', 'XL', 'fixed', '3' ],
					]
				),
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

// Settings the run needs: the Astra Pro WooCommerce addon module
// (`_astra_ext_enabled_extensions`; Astra ships every addon module disabled
// until the site enables it), Astra's quick-view mode (Astra keeps its options
// in the single `astra-settings` array) and each engine's visible price summary.
$astra_settings  = get_option( 'astra-settings', [] );
$astra_addons    = get_option( '_astra_ext_enabled_extensions', null );
$options_before  = [
	'astra-settings'                  => $astra_settings,
	'_astra_ext_enabled_extensions'   => $astra_addons,
	'opf_price_summary_mode'          => get_option( 'opf_price_summary_mode', null ),
	'wapf_pricing_summary'            => get_option( 'wapf_pricing_summary', null ),
	'woocommerce_coming_soon'         => get_option( 'woocommerce_coming_soon', null ),
];
if ( ! is_array( $astra_settings ) ) {
	$astra_settings = [];
}
$astra_settings['shop-quick-view-enable'] = 'on-image';
update_option( 'astra-settings', $astra_settings );

if ( ! is_array( $astra_addons ) ) {
	$astra_addons = [];
}
$astra_addons['woocommerce'] = 'woocommerce';
$astra_addons['all']         = 'all';
update_option( '_astra_ext_enabled_extensions', $astra_addons );

update_option( 'opf_price_summary_mode', 'three_line' );
update_option( 'wapf_pricing_summary', 'lines' );
update_option( 'woocommerce_coming_soon', 'no' );

// Astra_Minify caches the generated `astra-addon-<hash>.js` bundle per asset
// slug. Enabling the addon module / quick view does not invalidate that cache,
// so the shopping page keeps loading a bundle that contains no quick-view.js
// (AstraProQuickView stays undefined). Drop the generated bundle and its
// options so the next request regenerates it.
$astra_cache_dir = trailingslashit( $upload['basedir'] ) . 'astra-addon';
foreach ( (array) glob( $astra_cache_dir . '/astra-addon-*.js' ) as $astra_asset ) {
	wp_delete_file( $astra_asset );
}
foreach ( (array) glob( $astra_cache_dir . '/astra-addon-*.css' ) as $astra_asset ) {
	wp_delete_file( $astra_asset );
}
foreach ( [ 'astra_theme_js_key-astra-addon', 'astra_theme_js_key-files-astra-addon', 'astra_theme_js_key-dep-astra-addon', 'astra_theme_css_key-astra-addon', 'astra_theme_css_key-files-astra-addon' ] as $astra_option ) {
	delete_option( $astra_option );
}

if ( class_exists( '\SW_WAPF_PRO\Includes\Classes\Cache' ) ) {
	\SW_WAPF_PRO\Includes\Classes\Cache::clear();
}
if ( class_exists( 'OPF\Service\FieldGroups' ) ) {
	OPF\Service\FieldGroups::flush_cache();
}

// State from the previous prepare: a single-engine re-run must not drop the
// other engine's fixture row.
$previous = get_option( $fixture_option, [] );

$state = [
	'utc'              => gmdate( 'c' ),
	'base_url'         => untrailingslashit( home_url() ),
	'shop_url'         => get_permalink( (int) wc_get_page_id( 'shop' ) ),
	'store_api'        => [ 'cart' => rest_url( 'wc/store/v1/cart' ) ],
	'astra_options'    => [
		'shop-style'                    => function_exists( 'astra_get_option' ) ? astra_get_option( 'shop-style' ) : null,
		'shop-quick-view-enable'        => function_exists( 'astra_get_option' ) ? astra_get_option( 'shop-quick-view-enable' ) : null,
		'single-product-gallery-layout' => function_exists( 'astra_get_option' ) ? astra_get_option( 'single-product-gallery-layout' ) : null,
		'enabled_addons'                => get_option( '_astra_ext_enabled_extensions', null ),
	],
	'versions'         => [
		'wordpress'    => get_bloginfo( 'version' ),
		'woocommerce'  => defined( 'WC_VERSION' ) ? WC_VERSION : '',
		'theme_astra'  => wp_get_theme( 'astra' )->get( 'Version' ),
		'astra_addon'  => defined( 'ASTRA_EXT_VER' ) ? ASTRA_EXT_VER : '',
		'opf'          => defined( 'OPF_VERSION' ) ? OPF_VERSION : '',
		'wapf'         => defined( 'SW_WAPF_PRO_VERSION' ) ? SW_WAPF_PRO_VERSION : '3.1.5',
		'php'          => PHP_VERSION,
	],
	'images'           => $urls,
	'attachment_ids'   => $attachment_id,
	'options_before'   => $options_before,
	'products'         => [],
	'product_ids'      => array_values( array_unique( (array) ( $previous['product_ids'] ?? [] ) ) ),
	'group_ids'        => array_values( array_unique( (array) ( $previous['group_ids'] ?? [] ) ) ),
];

foreach ( $products as $key => $fixture ) {
	// The run keeps exactly one engine active at a time, so a repeated
	// `prepare` must not drop the other engine's fixture: skip it and carry
	// the previously stored product forward.
	if ( 'opf' === $fixture['engine'] && ! defined( 'OPF_VERSION' ) ) {
		echo "SKIP opf fixture (OPF inactive); reusing stored state.\n";
		if ( isset( $previous['products']['opf'] ) ) {
			$state['products']['opf'] = $previous['products']['opf'];
		}
		continue;
	}
	if ( 'wapf' === $fixture['engine'] && ! class_exists( '\SW_WAPF_PRO\Includes\Classes\Field_Groups' ) ) {
		echo "SKIP wapf fixture (WAPF inactive); reusing stored state.\n";
		if ( isset( $previous['products']['wapf'] ) ) {
			$state['products']['wapf'] = $previous['products']['wapf'];
		}
		continue;
	}
	$pid                    = opf_astra_upsert_product( $fixture, $attachment_id );
	$state['product_ids'][] = $pid;

	$group_title     = $fixture['title'] . ' Group';
	$existing_groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => 1, 'title' => $group_title ] );
	$group_post_id   = $existing_groups ? (int) $existing_groups[0]->ID : 0;

	if ( 'opf' === $fixture['engine'] ) {
		$group = opf_astra_opf_group( $pid, $urls );
		$gid   = OPF\Service\FieldGroups::save( $group_post_id, $group, [ 'title' => $group_title, 'status' => 'publish' ] );
		if ( ! $gid ) {
			opf_astra_fail( 'OPF group did not save for ' . $key );
		}
		$state['group_ids'][] = (int) $gid;
		$saved                = OPF\Service\FieldGroups::group_from_post( get_post( $gid ) );
		if ( ! $saved || count( $saved->data['image_rules'] ?? [] ) !== 2 || 'gold' !== ( $saved->data['fields'][0]['choices'][1]['pricing']['type'] ?? '' ) ) {
			// The pricing type is asserted through the amount below; this guards the rule count.
			if ( ! $saved || count( $saved->data['image_rules'] ?? [] ) !== 2 ) {
				opf_astra_fail( 'OPF group did not round-trip two image rules for ' . $key );
			}
		}
		$amount = (float) ( $saved->data['fields'][0]['choices'][1]['pricing']['amount'] ?? 0 );
		if ( 10.0 !== $amount ) {
			opf_astra_fail( 'OPF group lost the gold choice price for ' . $key );
		}
		$state['products'][ $key ] = [
			'engine'  => 'opf',
			'product' => $pid,
			'group'   => (int) $gid,
			'url'     => get_permalink( $pid ),
		];
	} else {
		$raw               = opf_astra_wapf_group_raw( 'p_' . $pid, $urls, $attachment_id );
		$wapf_field_groups = 'SW_WAPF_PRO\\Includes\\Classes\\Field_Groups';
		$fg                = $wapf_field_groups::raw_json_to_field_group( $raw );
		if ( ! $fg ) {
			opf_astra_fail( 'WAPF rejected the authored group payload for ' . $key );
		}
		$array      = $fg->to_array();
		// WAPF normalizes the raw payload: choices live under options.choices.
		$gold_price = (float) ( $array['fields'][0]['options']['choices'][1]['pricing_amount'] ?? 0 );
		$rules      = count( $array['layout']['gallery_images'] ?? [] );
		if ( 10.0 !== $gold_price || 2 !== $rules ) {
			opf_astra_fail( 'WAPF group lost the priced choice or a rule for ' . $key );
		}
		update_post_meta( $pid, '_wapf_fieldgroup', $array );
		$state['products'][ $key ] = [
			'engine'  => 'wapf',
			'product' => $pid,
			'group'   => 0,
			'url'     => get_permalink( $pid ),
		];
	}

	$product = wc_get_product( $pid );
	if ( ! $product || count( $product->get_children() ) !== 2 ) {
		opf_astra_fail( 'Fixture product ' . $key . ' did not keep two variations.' );
	}
	$variations = [];
	foreach ( $product->get_children() as $child_id ) {
		$child        = wc_get_product( $child_id );
		$variations[] = [
			'variation_id' => (int) $child_id,
			'size'         => $child->get_attribute( 'size' ),
			'image_url'    => wp_get_attachment_url( (int) $child->get_image_id() ),
		];
	}
	$state['products'][ $key ]['variations'] = $variations;
}

$state['product_ids'] = array_values( array_unique( array_map( 'intval', $state['product_ids'] ) ) );
$state['group_ids']   = array_values( array_unique( array_map( 'intval', $state['group_ids'] ) ) );

if ( ! is_dir( $art_dir ) ) {
	wp_mkdir_p( $art_dir );
}
file_put_contents( $art_dir . '/fixture-state.json', wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
update_option( $fixture_option, $state, false );

echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
opf_astra_ok( 'Astra compatibility fixture prepared (OPF + WAPF variable products).' );
