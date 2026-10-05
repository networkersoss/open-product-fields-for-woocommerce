<?php
/** Disposable layered-image Product Data and renderer fixture. */

defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$group_title = 'OPF E2E Layered Image Group';
$product_slug = 'opf-e2e-layered-image-product';
$pngs = [];
foreach ( [ 'main' => [ 240, 240, 240, 0 ], 'target' => [ 230, 230, 230, 0 ], 'base' => [ 255, 255, 255, 0 ], 'red' => [ 220, 40, 40, 55 ], 'blue' => [ 40, 80, 220, 55 ], 'gold' => [ 220, 170, 30, 55 ] ] as $key => $rgba ) {
	$image = imagecreatetruecolor( 8, 4 );
	imagesavealpha( $image, true );
	$transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
	imagefill( $image, 0, 0, $transparent );
	$color = imagecolorallocatealpha( $image, $rgba[0], $rgba[1], $rgba[2], $rgba[3] );
	imagefilledrectangle( $image, 1, 0, 6, 3, $color );
	ob_start();
	imagepng( $image );
	$pngs[ $key ] = ob_get_clean();
	imagedestroy( $image );
}
$mismatch_image = imagecreatetruecolor( 5, 4 );
imagesavealpha( $mismatch_image, true );
imagefill( $mismatch_image, 0, 0, imagecolorallocatealpha( $mismatch_image, 0, 0, 0, 127 ) );
imagefilledrectangle( $mismatch_image, 0, 0, 4, 3, imagecolorallocatealpha( $mismatch_image, 15, 160, 70, 55 ) );
ob_start();
imagepng( $mismatch_image );
$pngs['mismatch'] = ob_get_clean();
imagedestroy( $mismatch_image );
$opaque_image = imagecreatetruecolor( 8, 4 );
imagefill( $opaque_image, 0, 0, imagecolorallocate( $opaque_image, 10, 10, 10 ) );
ob_start();
imagepng( $opaque_image );
$pngs['opaque'] = ob_get_clean();
imagedestroy( $opaque_image );
$opaque_alpha_image = imagecreatetruecolor( 8, 4 );
imagesavealpha( $opaque_alpha_image, true );
imagefill( $opaque_alpha_image, 0, 0, imagecolorallocatealpha( $opaque_alpha_image, 30, 30, 30, 0 ) );
ob_start();
imagepng( $opaque_alpha_image );
$pngs['opaque_alpha'] = ob_get_clean();
imagedestroy( $opaque_alpha_image );
$names = [ 'opf-layered-gallery-main.png', 'opf-layered-gallery-target.png', 'opf-layered-base.png', 'opf-layered-red.png', 'opf-layered-blue.png', 'opf-layered-gold.png', 'opf-layered-mismatch.png', 'opf-layered-opaque.png', 'opf-layered-opaque-alpha.png' ];
$image_keys = [ 'main', 'target', 'base', 'red', 'blue', 'gold', 'mismatch', 'opaque', 'opaque_alpha' ];
$upload = wp_upload_dir();
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $group_title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'name' => $product_slug, 'status' => [ 'publish', 'draft', 'private' ] ] );
$attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_opf_layered_image_fixture', 'meta_value' => '1' ] );
$orders = wc_get_orders( [ 'limit' => -1, 'return' => 'objects', 'meta_key' => '_opf_layered_image_fixture', 'meta_value' => '1' ] );

if ( 'cleanup' === $action ) {
	$order_image_files = [];
	foreach ( $orders as $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$url = (string) $item->get_meta( OPF\Service\LayeredImages::ORDER_IMAGE_META_KEY, true );
			$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			if ( preg_match( '/^[1-9][0-9]{0,9}-[a-f0-9]{64}\.png$/D', $filename ) ) {
				$order_image_files[] = trailingslashit( $upload['basedir'] ) . 'opf-layered-images/orders/' . $filename;
			}
		}
		$order->delete( true );
	}
	foreach ( $order_image_files as $order_image_file ) {
		if ( is_file( $order_image_file ) ) {
			WP_CLI::error( 'Order image snapshot was not removed with its fixture order.' );
		}
	}
	foreach ( $groups as $group ) {
		wp_delete_post( (int) $group->ID, true );
	}
	foreach ( $products as $product ) {
		OPF\Service\LayeredImages::delete_cart_image_cache( (int) $product->get_id() );
		$product->delete( true );
	}
	foreach ( $attachments as $attachment ) {
		$file = get_attached_file( (int) $attachment->ID );
		wp_delete_attachment( (int) $attachment->ID, true );
		if ( $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	foreach ( $names as $index => $name ) {
		$file = trailingslashit( $upload['basedir'] ) . $name;
		if ( is_file( $file ) && hash_file( 'sha256', $file ) === hash( 'sha256', $pngs[ $image_keys[ $index ] ] ) ) {
			wp_delete_file( $file );
		}
	}
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Layered image fixtures, cart composites, and order image snapshots removed.' );
	return;
}

if ( 'verify' === $action ) {
	$product = $products[0] ?? null;
	$resolved = $product ? OPF\Service\LayeredImages::for_product( $product ) : [];
	$config = $product ? $product->get_meta( OPF\Service\LayeredImages::META_KEY, true ) : [];
	if ( count( $resolved ) !== 1 || $resolved[0]['target'] !== 'gallery' || $resolved[0]['target_index'] !== 1 || count( $resolved[0]['layers'] ) !== 3 || empty( $resolved[0]['delay'] ) || empty( $resolved[0]['auto_scroll'] ) || count( $config['layers'] ?? [] ) !== 3 ) {
		$groups = $product ? OPF\Service\FieldGroups::for_product( $product ) : [];
		WP_CLI::error( 'Saved layered-image configuration did not resolve: ' . wp_json_encode( [ 'resolved' => $resolved, 'stored' => $config, 'groups' => array_map( static function ( $entry ) { return [ 'id' => $entry['id'], 'fields' => $entry['group']->data['fields'] ]; }, $groups ) ] ) );
	}
	$mismatch = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_layered_image_fixture_kind', 'meta_value' => 'mismatch' ] );
	$opaque = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_layered_image_fixture_kind', 'meta_value' => 'opaque' ] );
	$opaque_alpha = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_layered_image_fixture_kind', 'meta_value' => 'opaque_alpha' ] );
	if ( ! $mismatch || ! $opaque || ! $opaque_alpha ) {
		WP_CLI::error( 'Layered-image invalid PNG fixtures missing.' );
	}
	$size_test = $config;
	$size_test['layers'][0]['image_id'] = (int) $mismatch[0]->ID;
	$product->update_meta_data( OPF\Service\LayeredImages::META_KEY, $size_test );
	$size_resolved = OPF\Service\LayeredImages::for_product( $product );
	$alpha_test = $config;
	$alpha_test['layers'][0]['image_id'] = (int) $opaque[0]->ID;
	$product->update_meta_data( OPF\Service\LayeredImages::META_KEY, $alpha_test );
	$alpha_resolved = OPF\Service\LayeredImages::for_product( $product );
	$opaque_alpha_test = $config;
	$opaque_alpha_test['layers'][0]['image_id'] = (int) $opaque_alpha[0]->ID;
	$product->update_meta_data( OPF\Service\LayeredImages::META_KEY, $opaque_alpha_test );
	$opaque_alpha_resolved = OPF\Service\LayeredImages::for_product( $product );
	$product->update_meta_data( OPF\Service\LayeredImages::META_KEY, $config );
	if ( count( $size_resolved[0]['layers'] ?? [] ) !== 2 || count( $alpha_resolved[0]['layers'] ?? [] ) !== 2 || count( $opaque_alpha_resolved[0]['layers'] ?? [] ) !== 2 ) {
		WP_CLI::error( 'Layered-image runtime accepted a dimension-mismatched or opaque PNG layer.' );
	}
	WP_CLI::success( 'Layered-image config resolves to gallery slide 1; runtime excludes mismatched-size, opaque RGB, and opaque RGBA PNG layers.' );
	return;
}

if ( 'verify-cache' === $action ) {
	$product = $products[0] ?? null;
	$upload = wp_upload_dir();
	$files = $product ? glob( trailingslashit( $upload['basedir'] ) . 'opf-layered-images/' . (int) $product->get_id() . '-[a-f0-9]*.png' ) : [];
	$count = is_array( $files ) ? count( $files ) : 0;
	if ( $count < 3 || $count > 12 ) {
		WP_CLI::error( 'Expected three cart composites and a per-product cache bound of 12; found ' . $count . '.' );
	}
	WP_CLI::success( 'Three distinct cart composites exist; the per-product cache remains within its 12-file bound.' );
	return;
}

if ( 'verify-email' === $action ) {
	$product = $products[0] ?? null;
	$group = $groups[0] ?? null;
	if ( ! $product || ! $group ) {
		WP_CLI::error( 'Layered email fixture product/group missing.' );
	}
	$mail_intercepts = 0;
	$prevent_mail_transport = static function ( $pre_wp_mail, $mail_args ) use ( &$mail_intercepts ) {
		++$mail_intercepts;
		return true;
	};
	add_filter( 'pre_wp_mail', $prevent_mail_transport, PHP_INT_MAX, 2 );
	$order = wc_create_order( [ 'status' => 'pending' ] );
	$values = [ (string) $group->ID => [ 'color' => [ 'blue' ], 'trim' => [ 'gold' ] ] ];
	$order->update_meta_data( '_opf_layered_image_fixture', '1' );
	$order->save();
	$cart_item_key = 'opf-layered-email-fixture-line';
	$cart_item = [
		'key' => $cart_item_key,
		'data' => $product,
		'product_id' => $product->get_id(),
		'variation_id' => 0,
		'variation' => [],
		'quantity' => 1,
		'line_subtotal' => (float) $product->get_price(),
		'line_total' => (float) $product->get_price(),
		'line_subtotal_tax' => 0,
		'line_tax' => 0,
		'line_tax_data' => [ 'subtotal' => [], 'total' => [] ],
		OPF\Service\CartIntegration::ITEM_KEY => $values,
		'opf_base_price' => (float) $product->get_price(),
	];
	WC()->cart->cart_contents = [ $cart_item_key => $cart_item ];
	WC()->checkout()->create_order_line_items( $order, WC()->cart );
	$order->update_meta_data( '_opf_layered_image_fixture', '1' );
	$order->calculate_totals();
	$order->save();
	$mail_result = wp_mail( 'opf-layered-email-fixture@example.invalid', 'OPF E2E transport interception', 'This message must not be delivered.' );
	remove_filter( 'pre_wp_mail', $prevent_mail_transport, PHP_INT_MAX );
	$order_items = $order->get_items();
	$item = $order_items ? reset( $order_items ) : null;
	$snapshot_url = $item ? (string) $item->get_meta( OPF\Service\LayeredImages::ORDER_IMAGE_META_KEY, true ) : '';
	$snapshot_file = trailingslashit( $upload['basedir'] ) . 'opf-layered-images/orders/' . basename( (string) wp_parse_url( $snapshot_url, PHP_URL_PATH ) );
	$source_images = OPF\Service\LayeredImages::filter_store_api_cart_item_images( [], $cart_item, $cart_item_key );
	$source_url = isset( $source_images[0]->src ) ? (string) $source_images[0]->src : '';
	$source_file = trailingslashit( $upload['basedir'] ) . 'opf-layered-images/' . basename( (string) wp_parse_url( $source_url, PHP_URL_PATH ) );
	$read_pixel = static function ( string $path ): ?array {
		$image = is_file( $path ) ? @imagecreatefrompng( $path ) : false;
		if ( ! $image ) {
			return null;
		}
		$color = imagecolorsforindex( $image, imagecolorat( $image, 3, 2 ) );
		imagedestroy( $image );
		return [ (int) $color['red'], (int) $color['green'], (int) $color['blue'], (int) $color['alpha'] ];
	};
	$source_pixel = $read_pixel( $source_file );
	$config = $product->get_meta( OPF\Service\LayeredImages::META_KEY, true );
	$red_attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_layered_image_fixture_kind', 'meta_value' => 'red' ] );
	if ( ! $red_attachments ) {
		WP_CLI::error( 'Fixture red image attachment missing before mapping mutation.' );
	}
	$config['layers'][1]['image_id'] = (int) $red_attachments[0]->ID;
	$product->update_meta_data( OPF\Service\LayeredImages::META_KEY, $config );
	$product->save();
	$changed_images = OPF\Service\LayeredImages::filter_store_api_cart_item_images( [], $cart_item, $cart_item_key );
	$changed_url = isset( $changed_images[0]->src ) ? (string) $changed_images[0]->src : '';
	$changed_file = trailingslashit( $upload['basedir'] ) . 'opf-layered-images/' . basename( (string) wp_parse_url( $changed_url, PHP_URL_PATH ) );
	$snapshot_pixel = $read_pixel( $snapshot_file );
	$changed_pixel = $read_pixel( $changed_file );
	$default_html = $product->get_image( 'woocommerce_thumbnail' );
	$filtered_html = apply_filters( 'woocommerce_order_item_thumbnail', $default_html, $item );
	$persisted_fields = $item ? $item->get_meta( '_opf_fields', true ) : null;
	if ( is_string( $persisted_fields ) ) {
		$persisted_fields = json_decode( $persisted_fields, true );
	}
	if ( ! $item || ! is_array( $persisted_fields ) || empty( $persisted_fields[ (string) $group->ID ]['color'] ) || '' === $snapshot_url || ! is_file( $snapshot_file ) || false === strpos( $snapshot_url, '/opf-layered-images/orders/' ) || ! is_string( $filtered_html ) || false === strpos( $filtered_html, $snapshot_url ) || ! $source_pixel || $snapshot_pixel !== $source_pixel || ! $changed_pixel || $changed_pixel === $source_pixel ) {
		WP_CLI::error( 'OPF did not persist and return the order-time composite after product mappings changed.' );
	}
	ob_start();
	wc_get_template( 'emails/email-order-items.php', [
		'order' => $order,
		'items' => $order->get_items(),
		'show_download_links' => false,
		'show_sku' => false,
		'show_image' => true,
		'image_size' => 'woocommerce_thumbnail',
		'show_purchase_note' => false,
		'sent_to_admin' => false,
		'plain_text' => false,
		'email' => null,
	] );
	$email_html = ob_get_clean();
	if ( ! is_string( $email_html ) || false === strpos( $email_html, $snapshot_url ) || ! $mail_result || 1 !== $mail_intercepts ) {
		WP_CLI::error( 'WooCommerce email order-items template did not render the saved order-time layered image snapshot.' );
	}
	WP_CLI::log( 'SOURCE_PIXEL=' . implode( ',', $source_pixel ) );
	WP_CLI::log( 'ORDER_SNAPSHOT_PIXEL=' . implode( ',', $snapshot_pixel ) );
	WP_CLI::log( 'MUTATED_MAPPING_PIXEL=' . implode( ',', $changed_pixel ) );
	WP_CLI::log( 'ORDER_SNAPSHOT_SHA256=' . hash_file( 'sha256', $snapshot_file ) );
	WP_CLI::log( 'MUTATED_MAPPING_SHA256=' . hash_file( 'sha256', $changed_file ) );
	WP_CLI::log( 'MAIL_TRANSPORT_INTERCEPTS=' . $mail_intercepts );
	WP_CLI::success( 'Woo checkout line-item creation invoked production persistence hooks; the order snapshot preserved the original composite after product mapping changed. Woo’s shipped email template rendered it, and pre_wp_mail intercepted the single mail probe with no delivery.' );
	return;
}

if ( 'prepare' !== $action ) {
	WP_CLI::error( 'Use prepare, verify, verify-cache, verify-email, or cleanup.' );
}

$product = $products[0] ?? new WC_Product_Simple();
$product->set_name( 'OPF E2E Layered Image Product' );
$product->set_slug( $product_slug );
$product->set_regular_price( '12.00' );
$product->set_status( 'publish' );
$product_id = $product->save();
$ids = [];
foreach ( $names as $index => $name ) {
	$png = $pngs[ $image_keys[ $index ] ];
	$path = trailingslashit( $upload['basedir'] ) . $name;
	if ( is_file( $path ) && hash_file( 'sha256', $path ) !== hash( 'sha256', $png ) ) {
		WP_CLI::error( 'Fixture image path contains unexpected bytes: ' . $name );
	}
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $png );
	}
	$found = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 1, 'meta_key' => '_opf_layered_image_fixture', 'meta_value' => '1', 'meta_query' => [ [ 'key' => '_wp_attached_file', 'value' => basename( $path ), 'compare' => 'LIKE' ] ] ] );
	$id = $found ? (int) $found[0]->ID : wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF layered image ' . $index, 'post_status' => 'inherit' ], $path, $product_id, true );
	if ( is_wp_error( $id ) || ! $id ) {
		WP_CLI::error( 'Could not create layered-image fixture PNG.' );
	}
	update_post_meta( (int) $id, '_opf_layered_image_fixture', '1' );
	$metadata = wp_generate_attachment_metadata( (int) $id, $path );
	if ( is_array( $metadata ) ) {
		wp_update_attachment_metadata( (int) $id, $metadata );
	}
	update_post_meta( (int) $id, '_opf_layered_image_fixture_kind', $image_keys[ $index ] );
	$ids[] = (int) $id;
}
$product = wc_get_product( $product_id );
$product->set_image_id( $ids[0] );
$product->set_gallery_image_ids( [ $ids[1] ] );
$product->update_meta_data( OPF\Service\LayeredImages::META_KEY, OPF\Engine\LayeredImageConfig::normalize( [] ) );
$product->save();

$group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'color', 'label' => 'Color', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'red', 'label' => 'Red' ], [ 'slug' => 'blue', 'label' => 'Blue' ] ] ],
		[ 'id' => 'trim', 'label' => 'Trim', 'type' => 'checkbox', 'choices' => [ [ 'slug' => 'gold', 'label' => 'Gold' ] ] ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $group, [ 'title' => $group_title ] );
OPF\Service\FieldGroups::flush_cache();
if ( ! $product_id || ! $group_id || ! OPF\Service\FieldGroups::group_from_post( get_post( $group_id ) ) ) {
	WP_CLI::error( 'Layered-image fixture product/group did not save.' );
}
$product = wc_get_product( $product_id );
$product->update_meta_data( OPF\Service\LayeredImages::META_KEY, OPF\Engine\LayeredImageConfig::normalize( [
	'enabled' => true,
	'target' => 'gallery',
	'target_image_id' => $ids[1],
	'base_image_id' => $ids[2],
	'delay' => true,
	'auto_scroll' => true,
	'layers' => [
		[ 'group_id' => $group_id, 'field_id' => 'color', 'choice' => 'red', 'image_id' => $ids[3] ],
		[ 'group_id' => $group_id, 'field_id' => 'color', 'choice' => 'blue', 'image_id' => $ids[4] ],
		[ 'group_id' => $group_id, 'field_id' => 'trim', 'choice' => 'gold', 'image_id' => $ids[5] ],
	],
] ) );
$product->save();

WP_CLI::log( 'LAYERED_PRODUCT=' . $product_id );
WP_CLI::log( 'LAYERED_GROUP=' . $group_id );
WP_CLI::log( 'LAYERED_PRODUCT_URL=' . get_permalink( $product_id ) );
WP_CLI::log( 'LAYERED_TARGET_IMAGE=' . $ids[1] );
WP_CLI::log( 'LAYERED_BASE_IMAGE=' . $ids[2] );
WP_CLI::log( 'LAYERED_RED_IMAGE=' . $ids[3] );
WP_CLI::log( 'LAYERED_BLUE_IMAGE=' . $ids[4] );
WP_CLI::log( 'LAYERED_GOLD_IMAGE=' . $ids[5] );
WP_CLI::log( 'LAYERED_MISMATCH_IMAGE=' . $ids[6] );
WP_CLI::log( 'LAYERED_OPAQUE_IMAGE=' . $ids[7] );
WP_CLI::log( 'LAYERED_OPAQUE_ALPHA_IMAGE=' . $ids[8] );
WP_CLI::log( 'LAYERED_UPLOAD_FILE=' . trailingslashit( $upload['basedir'] ) . $names[3] );
WP_CLI::log( 'LAYERED_OPAQUE_ALPHA_FILE=' . trailingslashit( $upload['basedir'] ) . $names[8] );
WP_CLI::success( 'Layered-image fixture prepared.' );
