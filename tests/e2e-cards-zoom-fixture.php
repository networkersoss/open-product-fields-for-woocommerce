<?php
/** Isolated cards + image-zoom fixture — disposable clone only.
 *
 * Phases (OPF_CARDS_ZOOM_PHASE):
 *  - setup    capture baseline, create products/attachments/field group/checkout
 *  - verify   assert the persisted order carries the expected parent/child lines
 *  - cleanup  remove every created object, restore options, assert baseline
 */
if ( '1' !== getenv( 'OPF_CARDS_ZOOM_ALLOW' ) || ( 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-image-cards-wp' ) && 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-child-image-zoom-wp' ) ) ) {
	throw new RuntimeException( 'Explicit isolated cards-zoom clone authorization required.' );
}
function oz_assert( string $label, bool $pass ): void {
	if ( ! $pass ) { throw new RuntimeException( $label ); }
	echo "ok $label\n";
}
$dir = getenv( 'OPF_CARDS_ARTIFACT_DIR' ) ?: '/tmp/opf-lane-cards-evidence';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}
$phase = getenv( 'OPF_CARDS_ZOOM_PHASE' ) ?: 'setup';
$state = get_option( 'opf_cards_zoom_state', [] );

$oz_snapshot = function (): array {
	global $wpdb;
	$rows = [];
	foreach ( $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) c FROM {$wpdb->posts} GROUP BY post_type, post_status", ARRAY_A ) as $row ) {
		$rows[ $row['post_type'] . '/' . $row['post_status'] ] = (int) $row['c'];
	}
	ksort( $rows );
	$options = [];
	foreach ( [ 'woocommerce_checkout_page_id', 'woocommerce_bacs_settings', 'woocommerce_hide_out_of_stock_items' ] as $option ) {
		$options[ $option ] = get_option( $option );
	}
	$user_count = (int) ( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) );
	return [ 'posts' => $rows, 'options' => $options, 'users' => $user_count ];
};

/** Solid-color PNG attachment so each object has a distinct image URL. */
$oz_make_image = function ( string $hex, string $name ): int {
	$img = imagecreatetruecolor( 800, 800 );
	[ $r, $g, $b ] = array_map( 'hexdec', [ substr( $hex, 1, 2 ), substr( $hex, 3, 2 ), substr( $hex, 5, 2 ) ] );
	imagefill( $img, 0, 0, imagecolorallocate( $img, $r, $g, $b ) );
	$tmp = tempnam( sys_get_temp_dir(), 'opfimg' ) . '.png';
	imagepng( $img, $tmp );
	imagedestroy( $img );
	$upload = wp_upload_bits( $name . '.png', null, file_get_contents( $tmp ) );
	unlink( $tmp );
	oz_assert( $name . ' upload ok', empty( $upload['error'] ) );
	$att_id = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit' ], $upload['file'] );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $upload['file'] ) );
	return (int) $att_id;
};
$oz_make_product = function ( string $name, string $slug, string $price, int $thumb ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_slug( $slug );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	$p->set_virtual( true );
	$p->set_image_id( $thumb );
	return (int) $p->save();
};

if ( 'setup' === $phase ) {
	oz_assert( 'fresh isolated fixtures', empty( $state ) );
	$baseline = $oz_snapshot();

	$img_parent  = $oz_make_image( '#1f2a44', 'opf-oz-parent' );
	$img_alpha   = $oz_make_image( '#c0392b', 'opf-oz-alpha' );
	$img_beta    = $oz_make_image( '#27ae60', 'opf-oz-beta' );
	$img_gallery = $oz_make_image( '#8e44ad', 'opf-oz-gallery' );
	$img_printa  = $oz_make_image( '#e67e22', 'opf-oz-print-a' );
	$img_printb  = $oz_make_image( '#16a085', 'opf-oz-print-b' );
	$img_oak     = $oz_make_image( '#7d5a34', 'opf-oz-oak' );
	$img_ash     = $oz_make_image( '#bdc3c7', 'opf-oz-ash' );

	$parent = $oz_make_product( 'OPF Zoom Parent', 'opf-zoom-parent', '20', $img_parent );
	$alpha  = $oz_make_product( 'OPF Zoom Alpha', 'opf-zoom-alpha', '8', $img_alpha );
	$beta   = $oz_make_product( 'OPF Zoom Beta', 'opf-zoom-beta', '12', $img_beta );

	$fields = [
		[
			'id' => 'cards', 'label' => 'Choose a kit', 'type' => 'products', 'subtype' => 'card',
			'product_selection' => 'manual', 'qty_method' => 'parent', 'image_zoom' => true,
			'incl_img' => true, 'incl_desc' => false, 'slot_1' => 'price', 'items_per_row' => 2,
			'choices' => [
				[ 'product_id' => $alpha, 'slug' => 'child-alpha', 'pricing_type' => 'fixed' ],
				[ 'product_id' => $beta, 'slug' => 'child-beta', 'pricing_type' => 'none' ],
			],
		],
		[
			'id' => 'units', 'label' => 'Extra units', 'type' => 'products', 'subtype' => 'card-qty',
			'product_selection' => 'manual', 'display' => 'plus_min',
			'min_choices' => 0, 'max_choices' => 10,
			'incl_img' => true, 'incl_desc' => false, 'slot_1' => 'price', 'items_per_row' => 2,
			'choices' => [
				[ 'product_id' => $alpha, 'slug' => 'child-alpha', 'quantity' => [ 'min' => 0, 'max' => 5 ] ],
				[ 'product_id' => $beta, 'slug' => 'child-beta', 'quantity' => [ 'min' => 0, 'max' => 5 ] ],
			],
		],
		[
			'id' => 'child-images', 'label' => 'Child images', 'type' => 'products', 'subtype' => 'image',
			'product_selection' => 'manual', 'large_image' => true, 'image_zoom' => false,
			'choices' => [
				[ 'product_id' => $alpha, 'slug' => 'child-alpha-image', 'pricing_type' => 'fixed' ],
				[ 'product_id' => $beta, 'slug' => 'child-beta-image', 'pricing_type' => 'none' ],
			],
		],
		[
			'id' => 'note', 'label' => 'Add a note', 'type' => 'text',
			'conditionals' => [ [ 'action' => 'show', 'logic' => 'all', 'rules' => [ [ 'field' => 'units', 'operator' => 'not_empty', 'value' => '' ] ] ] ],
		],
		[
			'id' => 'prints', 'label' => 'Prints', 'type' => 'image_quantity', 'large_image' => true,
			'choices' => [
				[ 'slug' => 'print-a', 'label' => 'Print A', 'image_id' => $img_printa, 'quantity' => [ 'default' => 0, 'min' => 0, 'max' => 9 ] ],
				[ 'slug' => 'print-b', 'label' => 'Print B', 'image_id' => $img_printb, 'quantity' => [ 'default' => 0, 'min' => 0, 'max' => 9 ] ],
			],
		],
		[
			'id' => 'finish', 'label' => 'Finish', 'type' => 'swatch', 'swatch_style' => 'image', 'image_zoom' => true,
			'choices' => [
				[ 'slug' => 'oak', 'label' => 'Oak', 'image_id' => $img_oak ],
				[ 'slug' => 'ash', 'label' => 'Ash', 'image_id' => $img_ash ],
			],
		],
	];
	$gid = OPF\Service\FieldGroups::save( 0, [
		'fields'      => $fields,
		'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $parent ] ] ] ] ],
		'layout'      => [
			'enable_gallery_images' => true,
			'swap_type'             => 'rules',
			'gallery_images'        => [ [
				'source' => 'upload',
				'url'    => (string) wp_get_attachment_url( $img_gallery ),
				'id'     => (string) $img_gallery,
				'values' => [ [ 'field' => 'cards', 'value' => 'child-alpha' ] ],
			] ],
		],
	], [ 'title' => 'Cards + zoom lifecycle', 'status' => 'publish' ] );
	oz_assert( 'field group persisted', $gid > 0 );
	$group = OPF\Service\FieldGroups::group_from_post( get_post( $gid ) );
	oz_assert( 'group round-trips six fields', 6 === count( $group->data['fields'] ) );
	oz_assert( 'gallery layout round-trips', ! empty( $group->data['layout']['gallery_images'][0]['id'] ) );
	oz_assert( 'image quantity zoom round-trips', ! empty( $group->data['fields'][4]['image_zoom'] ) );
	oz_assert( 'linked-product large image zoom round-trips independently', ! empty( $group->data['fields'][2]['large_image'] ) && empty( $group->data['fields'][2]['image_zoom'] ) );

	$checkout = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Zoom proof checkout', 'post_name' => 'zoom-proof-checkout', 'post_content' => '[woocommerce_checkout]' ] );
	$prev_checkout = get_option( 'woocommerce_checkout_page_id' );
	$prev_bacs     = get_option( 'woocommerce_bacs_settings' );
	update_option( 'woocommerce_checkout_page_id', $checkout );
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Bank transfer' ] );

	$state = [
		'baseline' => $baseline,
		'parent' => $parent, 'alpha' => $alpha, 'beta' => $beta,
		'images' => [ $img_parent, $img_alpha, $img_beta, $img_gallery, $img_printa, $img_printb, $img_oak, $img_ash ],
		'gallery_image' => $img_gallery,
		'group' => $gid, 'checkout' => $checkout,
		'prev_checkout' => $prev_checkout, 'prev_bacs' => $prev_bacs,
	];
	update_option( 'opf_cards_zoom_state', $state, false );
	file_put_contents( $dir . '/cards-zoom-state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
	echo "SUCCESS cards-zoom fixtures\n";
	return;
}

oz_assert( 'fixture state exists', ! empty( $state['group'] ) );

if ( 'verify' === $phase ) {
	$order_id = (int) json_decode( (string) file_get_contents( $dir . '/cards-order-id.json' ), true );
	$order = wc_get_order( $order_id );
	oz_assert( 'persisted real order', $order instanceof WC_Order );
	$items = array_values( $order->get_items() );
	oz_assert( 'order has parent + two child lines', 3 === count( $items ) );
	$parent_item = null;
	$children = [];
	foreach ( $items as $item ) {
		if ( $item->get_meta( '_opf_fields' ) ) {
			$parent_item = $item;
		} elseif ( $item->get_meta( '_opf_child' ) ) {
			$children[] = $item;
		}
	}
	oz_assert( 'parent line carries opf_fields', $parent_item instanceof WC_Order_Item_Product );
	oz_assert( 'two qty-card child lines carry _opf_child', 2 === count( $children ) );
	$by_qty = [];
	foreach ( $children as $child ) {
		$by_qty[ $child->get_quantity() ] = (float) $child->get_total();
	}
	ksort( $by_qty );
	oz_assert( 'card child qty 1 x $8 and qty-card child qty 2 x $8', 1 === ( array_key_first( $by_qty ) ) && 8.0 === (float) $by_qty[ array_key_first( $by_qty ) ] );
	oz_assert( 'relative qty child scaled to 2', isset( $by_qty[2] ) && 16.0 === (float) $by_qty[2] );
	oz_assert( 'order total 20 + 8 + 16 = 44', 44.0 === (float) $order->get_total() );
	echo "SUCCESS cards-zoom order persistence\n";
	file_put_contents( $dir . '/cards-order-verify.json', wp_json_encode( [ 'order' => $order_id, 'items' => count( $items ), 'total' => (float) $order->get_total() ], JSON_PRETTY_PRINT ) );
	return;
}

if ( 'cleanup' === $phase ) {
	foreach ( json_decode( (string) @file_get_contents( $dir . '/cards-order-ids.json' ), true ) ?: [] as $oid ) {
		$o = wc_get_order( (int) $oid );
		if ( $o ) { $o->delete( true ); }
	}
	foreach ( [ $state['parent'], $state['alpha'], $state['beta'] ] as $pid ) {
		wp_delete_post( (int) $pid, true );
	}
	foreach ( $state['images'] as $att ) { wp_delete_attachment( (int) $att, true ); }
	wp_delete_post( (int) $state['group'], true );
	wp_delete_post( (int) $state['checkout'], true );
	if ( false !== $state['prev_checkout'] ) { update_option( 'woocommerce_checkout_page_id', $state['prev_checkout'] ); }
	if ( false !== $state['prev_bacs'] ) { update_option( 'woocommerce_bacs_settings', $state['prev_bacs'] ); }
	delete_option( 'opf_cards_zoom_state' );
	OPF\Service\FieldGroups::flush_cache();

	$after = $oz_snapshot();
	$baseline = $state['baseline'];
	$post_diff = array_diff_assoc( $after['posts'], $baseline['posts'] );
	$post_diff = array_merge( $post_diff, array_diff_assoc( $baseline['posts'], $after['posts'] ) );
	oz_assert( 'post counts restored to baseline', [] === $post_diff );
	oz_assert( 'user count restored to baseline', (int) $baseline['users'] === (int) $after['users'] );
	file_put_contents( $dir . '/cards-baseline-check.json', wp_json_encode( [ 'baseline' => $baseline, 'after' => $after, 'restored' => true ], JSON_PRETTY_PRINT ) );
	echo "SUCCESS cards-zoom cleanup\n";
	return;
}

throw new RuntimeException( 'Unknown phase: ' . $phase );
