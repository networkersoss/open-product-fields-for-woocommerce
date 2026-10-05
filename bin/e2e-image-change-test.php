<?php
/**
 * Disposable WooCommerce conditional-image fixture.
 *
 * Usage: wp eval-file bin/e2e-image-change-test.php prepare|cleanup
 *
 * Prepares two fixtures: the rules-mode product and the OPF-native `last`-mode
 * product. Set OPF_BASE_URL to the origin the browser harness will load so every
 * authored URL (uploads baseurl, permalinks) is in that origin — the harness
 * does not need the disposable `wp-config.php` touched.
 */

defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title = 'OPF E2E Conditional Image Group';
$product_name = 'OPF E2E Conditional Image Product';
$last_title = 'OPF E2E Last Image Group';
$last_product_name = 'OPF E2E Last Image Product';

// `wp server` serves the disposable site from its own origin (its router
// rewrites the stored home/siteurl per request), so authoring URLs from the
// stored WP_HOME constant can never match a served gallery slide.
// `_config_wp_home`/`_config_wp_siteurl` hook `option_home`/`option_siteurl` at
// priority 10, so these at 20 win over the constants. Upload URLs need the
// `upload_dir` filter as well: with an empty `upload_path`,
// `_wp_upload_dir()` builds the baseurl from the bootstrap-time
// `WP_CONTENT_URL` constant, which no option filter can reach.
$base_url = untrailingslashit( trim( (string) getenv( 'OPF_BASE_URL' ) ) );
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
$upload = wp_upload_dir();
$image_files = [
	'front' => [ 'name' => 'opf-image-change-front.png', 'data' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' ) ],
	'back' => [ 'name' => 'opf-image-change-back.png', 'data' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' ) ],
	'side' => [ 'name' => 'opf-image-change-side.png', 'data' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' ) ],
	'external' => [ 'name' => 'opf-image-change-external.png', 'data' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' ) ],
];
$groups = [];
foreach ( [ $title, $last_title ] as $fixture_title ) {
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $fixture_title ] ) as $group_post ) {
		$groups[ (int) $group_post->ID ] = $group_post;
	}
}
$products = [];
foreach ( [ $product_name, $last_product_name ] as $fixture_name ) {
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'name' => sanitize_title( $fixture_name ), 'status' => [ 'publish', 'draft', 'private' ] ] ) as $fixture_product ) {
		$products[ (int) $fixture_product->get_id() ] = $fixture_product;
	}
}
$attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_opf_image_change_fixture', 'meta_value' => '1' ] );
$group_by_title = [];
foreach ( $groups as $fixture_group_post ) {
	$group_by_title[ $fixture_group_post->post_title ] = $fixture_group_post;
}
$group_post_id      = isset( $group_by_title[ $title ] ) ? (int) $group_by_title[ $title ]->ID : 0;
$last_group_post_id = isset( $group_by_title[ $last_title ] ) ? (int) $group_by_title[ $last_title ]->ID : 0;
$attachment_by_file = [];
foreach ( $attachments as $fixture_attachment ) {
	$attached_file = get_attached_file( (int) $fixture_attachment->ID );
	if ( $attached_file ) {
		$attachment_by_file[ basename( $attached_file ) ] = (int) $fixture_attachment->ID;
	}
}

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group_post ) {
		wp_delete_post( (int) $group_post->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	foreach ( $attachments as $attachment ) {
		$file = get_attached_file( (int) $attachment->ID );
		wp_delete_attachment( (int) $attachment->ID, true );
		if ( $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	foreach ( $image_files as $fixture ) {
		$path = trailingslashit( $upload['basedir'] ) . $fixture['name'];
		if ( is_file( $path ) && hash( 'sha256', $fixture['data'] ) === hash_file( 'sha256', $path ) ) {
			wp_delete_file( $path );
		}
	}
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Conditional image E2E fixtures removed.' );
	return;
}

$product_by_slug = [];
foreach ( $products as $fixture_product ) {
	$product_by_slug[ $fixture_product->get_slug() ] = $fixture_product;
}
$product      = $product_by_slug[ sanitize_title( $product_name ) ] ?? new WC_Product_Simple();
$last_product = $product_by_slug[ sanitize_title( $last_product_name ) ] ?? new WC_Product_Simple();
foreach ( [ $product_name => $product, $last_product_name => $last_product ] as $fixture_name => $fixture_product ) {
	$fixture_product->set_name( $fixture_name );
	$fixture_product->set_slug( sanitize_title( $fixture_name ) );
	$fixture_product->set_regular_price( '100.00' );
	$fixture_product->set_status( 'publish' );
	$fixture_product->save();
}
$product_id      = (int) $product->get_id();
$last_product_id = (int) $last_product->get_id();

$ids = [];
foreach ( [ 'front', 'back', 'side' ] as $key ) {
	$fixture = $image_files[ $key ];
	$path = trailingslashit( $upload['basedir'] ) . $fixture['name'];
	if ( is_file( $path ) && hash( 'sha256', $fixture['data'] ) !== hash_file( 'sha256', $path ) ) {
		WP_CLI::error( 'Image fixture path already exists with different content: ' . $fixture['name'] );
	}
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $fixture['data'] );
	}
	$attachment_id = $attachment_by_file[ $fixture['name'] ] ?? wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF image change ' . $key, 'post_status' => 'inherit' ], $path, $product_id, true );
	if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
		WP_CLI::error( 'Could not create image attachment: ' . $fixture['name'] );
	}
	update_post_meta( (int) $attachment_id, '_opf_image_change_fixture', '1' );
	$ids[ $key ] = (int) $attachment_id;
}
$product = wc_get_product( $product_id );
$product->set_image_id( $ids['front'] );
$product->set_gallery_image_ids( [ $ids['back'], $ids['side'] ] );
$product->save();

$last_product = wc_get_product( $last_product_id );
$last_product->set_image_id( $ids['front'] );
$last_product->set_gallery_image_ids( [ $ids['back'], $ids['side'] ] );
$last_product->save();

$external_path = trailingslashit( $upload['basedir'] ) . $image_files['external']['name'];
if ( is_file( $external_path ) && hash( 'sha256', $image_files['external']['data'] ) !== hash_file( 'sha256', $external_path ) ) {
	WP_CLI::error( 'External image fixture path already exists with different content.' );
}
if ( ! is_file( $external_path ) ) {
	wp_mkdir_p( dirname( $external_path ) );
	file_put_contents( $external_path, $image_files['external']['data'] );
}

$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'color', 'label' => 'Color', 'type' => 'select', 'default' => 'green', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ], [ 'slug' => 'green', 'label' => 'Green' ] ] ],
		[ 'id' => 'size', 'label' => 'Size', 'type' => 'select', 'default' => 'small', 'choices' => [ [ 'slug' => 'small', 'label' => 'Small' ], [ 'slug' => 'large', 'label' => 'Large' ] ] ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
	'image_rules' => [
		// Rules resolve last-match-wins (WAPF 3.1.5 reverses the rule list), so
		// the broader `red + Any` rule precedes `red + large`: the AND rule takes
		// over once size matches, which is what the browser harness asserts.
		[ 'target_url' => trailingslashit( $upload['baseurl'] ) . $image_files['external']['name'], 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => '*' ] ] ],
		[ 'target_url' => wp_get_attachment_url( $ids['back'] ), 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ], [ 'field' => 'size', 'value' => 'large' ] ] ],
		[ 'target_url' => wp_get_attachment_url( $ids['side'] ), 'conditions' => [ [ 'field' => 'color', 'value' => 'blue' ], [ 'field' => 'size', 'value' => '*' ] ] ],
	],
] );
$group_id = OPF\Service\FieldGroups::save( $group_post_id, $group, [ 'title' => $title ] );
$saved = $group_id ? OPF\Service\FieldGroups::group_from_post( get_post( $group_id ) ) : null;
if ( ! $product_id || ! $group_id || ! $saved || count( $saved->data['image_rules'] ?? [] ) !== 3 || count( $product->get_gallery_image_ids() ) !== 2 ) {
	WP_CLI::error( 'Conditional image fixture did not round-trip with its product gallery and three rules.' );
}

// OPF-native `last` mode: the rule list is still scanned last-match-first, but
// every non-wildcard condition must also name the field the shopper changed
// last (`resolveProductImageRule`). Both rules carry a single condition, so
// `rules` and `last` disagree on the same values: with colour red and size
// small, `rules` mode takes the later size rule while `last` mode follows the
// field that actually changed. That disagreement is what the harness asserts.
$last_group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'color', 'label' => 'Color', 'type' => 'select', 'default' => 'green', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ], [ 'slug' => 'green', 'label' => 'Green' ] ] ],
		[ 'id' => 'size', 'label' => 'Size', 'type' => 'select', 'default' => 'large', 'choices' => [ [ 'slug' => 'small', 'label' => 'Small' ], [ 'slug' => 'large', 'label' => 'Large' ] ] ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $last_product_id ] ] ] ] ],
	'image_rule_mode' => 'last',
	'image_rules' => [
		[ 'target_url' => wp_get_attachment_url( $ids['back'] ), 'conditions' => [ [ 'field' => 'color', 'value' => 'red' ] ] ],
		[ 'target_url' => wp_get_attachment_url( $ids['side'] ), 'conditions' => [ [ 'field' => 'size', 'value' => 'small' ] ] ],
	],
] );
$last_group_id = OPF\Service\FieldGroups::save( $last_group_post_id, $last_group, [ 'title' => $last_title ] );
$last_saved = $last_group_id ? OPF\Service\FieldGroups::group_from_post( get_post( $last_group_id ) ) : null;
if ( ! $last_product_id || ! $last_group_id || ! $last_saved || count( $last_saved->data['image_rules'] ?? [] ) !== 2 || ( $last_saved->data['image_rule_mode'] ?? '' ) !== 'last' || count( $last_product->get_gallery_image_ids() ) !== 2 ) {
	WP_CLI::error( 'Last-mode fixture did not round-trip two rules with image_rule_mode=last.' );
}

WP_CLI::log( 'IMAGE_CHANGE_PRODUCT=' . $product_id );
WP_CLI::log( 'IMAGE_CHANGE_GROUP=' . $group_id );
WP_CLI::log( 'IMAGE_CHANGE_URL=' . get_permalink( $product_id ) );
WP_CLI::log( 'IMAGE_CHANGE_INITIAL=' . wp_get_attachment_url( $ids['front'] ) );
WP_CLI::log( 'IMAGE_CHANGE_GALLERY=' . wp_get_attachment_url( $ids['back'] ) . ',' . wp_get_attachment_url( $ids['side'] ) );
WP_CLI::log( 'IMAGE_CHANGE_EXTERNAL=' . trailingslashit( $upload['baseurl'] ) . $image_files['external']['name'] );
WP_CLI::log( 'IMAGE_CHANGE_LAST_PRODUCT=' . $last_product_id );
WP_CLI::log( 'IMAGE_CHANGE_LAST_GROUP=' . $last_group_id );
WP_CLI::log( 'IMAGE_CHANGE_LAST_URL=' . get_permalink( $last_product_id ) );
WP_CLI::success( 'Conditional image fixture prepared.' );
