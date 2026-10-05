<?php
/** Disposable linked-products WooCommerce fixture. Usage: wp eval-file ... prepare|cleanup */
defined( 'ABSPATH' ) || exit;

$action = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$title = 'OPF E2E Child Products Group';
$parent_name = 'OPF E2E Child Products Parent';
$child_names = [ 'OPF E2E Child A', 'OPF E2E Child B', 'OPF E2E Child Low Stock' ];
$category_slug = 'opf-e2e-child-products';
$options_key = 'opf_e2e_child_products_original_options';
$rate_key = 'opf_e2e_child_products_tax_rate';
$cart_content_key = 'opf_e2e_child_products_cart_content';
$image_name = 'opf-e2e-child-product.png';
$image_data = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+nAAAAABJRU5ErkJggg==' );
$upload = wp_upload_dir();
$image_path = trailingslashit( $upload['basedir'] ) . $image_name;
$groups = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] );
$products = wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'status' => [ 'publish', 'draft', 'private' ] ] );
$products = array_values( array_filter( $products, static fn( $product ): bool => in_array( $product->get_name(), array_merge( $child_names, [ $parent_name ] ), true ) ) );
$attachments = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_opf_child_products_fixture', 'meta_value' => '1' ] );

if ( 'cleanup' === $action ) {
	foreach ( $groups as $group ) {
		wp_delete_post( (int) $group->ID, true );
	}
	foreach ( $products as $product ) {
		$product->delete( true );
	}
	foreach ( $attachments as $attachment ) {
		wp_delete_attachment( (int) $attachment->ID, true );
	}
	$term = get_term_by( 'slug', $category_slug, 'product_cat' );
	if ( $term && ! is_wp_error( $term ) ) {
		wp_delete_term( (int) $term->term_id, 'product_cat' );
	}
	$rate_id = (int) get_option( $rate_key, 0 );
	if ( $rate_id && class_exists( 'WC_Tax' ) ) {
		WC_Tax::_delete_tax_rate( $rate_id );
	}
	$original = get_option( $options_key, [] );
	foreach ( (array) $original as $key => $value ) {
		update_option( $key, $value );
	}
	delete_option( $options_key );
	delete_option( $rate_key );
	$cart_content = get_option( $cart_content_key, false );
	$cart_page_id = (int) wc_get_page_id( 'cart' );
	if ( false !== $cart_content && $cart_page_id > 0 ) {
		wp_update_post( [ 'ID' => $cart_page_id, 'post_content' => (string) $cart_content ] );
	}
	delete_option( $cart_content_key );
	if ( is_file( $image_path ) && hash( 'sha256', $image_data ) === hash_file( 'sha256', $image_path ) ) {
		wp_delete_file( $image_path );
	}
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Child-product E2E fixtures and temporary tax configuration removed.' );
	return;
}

if ( ! get_option( $options_key, false ) ) {
	$keys = [ 'woocommerce_calc_taxes', 'woocommerce_default_country', 'woocommerce_tax_based_on' ];
	$original = [];
	foreach ( $keys as $key ) {
		$original[ $key ] = get_option( $key, false );
	}
	add_option( $options_key, $original, '', false );
}
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_tax_based_on', 'base' );
$cart_page_id = (int) wc_get_page_id( 'cart' );
if ( $cart_page_id > 0 && ! get_option( $cart_content_key, false ) ) {
	add_option( $cart_content_key, (string) get_post_field( 'post_content', $cart_page_id ), '', false );
	wp_update_post( [ 'ID' => $cart_page_id, 'post_content' => '[woocommerce_cart]' ] );
}
if ( ! get_option( $rate_key, 0 ) ) {
	$rate_id = WC_Tax::_insert_tax_rate( [
		'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '10.0000',
		'tax_rate_name' => 'OPF Child E2E', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
		'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '',
	] );
	update_option( $rate_key, (int) $rate_id );
}

if ( is_file( $image_path ) && hash( 'sha256', $image_data ) !== hash_file( 'sha256', $image_path ) ) {
	WP_CLI::error( 'Child-product image fixture path already exists with different content.' );
}
if ( ! is_file( $image_path ) ) {
	wp_mkdir_p( dirname( $image_path ) );
	file_put_contents( $image_path, $image_data );
}
$attachment = $attachments ? (int) $attachments[0]->ID : wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'OPF child product fixture', 'post_status' => 'inherit' ], $image_path );
if ( is_wp_error( $attachment ) || ! $attachment ) {
	WP_CLI::error( 'Could not create child product image fixture.' );
}
update_post_meta( (int) $attachment, '_opf_child_products_fixture', '1' );

$term = get_term_by( 'slug', $category_slug, 'product_cat' );
if ( ! $term ) {
	$term = wp_insert_term( 'OPF E2E Child Products', 'product_cat', [ 'slug' => $category_slug ] );
	if ( is_wp_error( $term ) ) {
		WP_CLI::error( 'Could not create child-product category.' );
	}
	$term = get_term( (int) $term['term_id'], 'product_cat' );
}
$child_ids = [];
foreach ( $child_names as $index => $name ) {
	$product = null;
	foreach ( $products as $candidate ) {
		if ( $candidate->get_name() === $name ) {
			$product = $candidate;
			break;
		}
	}
	$product = $product ?: new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( sanitize_title( $name ) );
	$product->set_regular_price( [ '5.00', '7.00', '9.00' ][ $index ] );
	$product->set_status( 'publish' );
	$product->set_sku( 'opf-e2e-child-' . ( $index + 1 ) );
	$product->set_tax_status( 'taxable' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( 1 === $index ? 10 : ( 2 === $index ? 1 : 10 ) );
	$product->set_stock_status( 'instock' );
	$product->set_image_id( (int) $attachment );
	$product->save();
	wp_set_object_terms( $product->get_id(), [ (int) $term->term_id ], 'product_cat' );
	$child_ids[] = (int) $product->get_id();
}
$parent = null;
foreach ( $products as $candidate ) {
	if ( $candidate->get_name() === $parent_name ) {
		$parent = $candidate;
		break;
	}
}
$parent = $parent ?: new WC_Product_Simple();
$parent->set_name( $parent_name );
$parent->set_slug( sanitize_title( $parent_name ) );
$parent->set_regular_price( '20.00' );
$parent->set_status( 'publish' );
$parent_id = $parent->save();
$field_group = new OPF\Engine\FieldGroup( [
	'fields' => [
		[ 'id' => 'bundle_items', 'label' => 'Bundle items', 'type' => 'child_products', 'product_source' => 'specific', 'product_ids' => $child_ids, 'product_display' => 'images', 'multiple' => true, 'quantity_input' => true, 'quantity_mode' => 'multiply_parent', 'image_zoom' => true, 'min_selections' => 1 ],
		[ 'id' => 'category_items', 'label' => 'Category items', 'type' => 'child_products', 'product_source' => 'categories', 'category_ids' => [ (int) $term->term_id ], 'product_display' => 'select', 'multiple' => true ],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $parent_id ] ] ] ] ],
] );
$group_id = OPF\Service\FieldGroups::save( $groups ? (int) $groups[0]->ID : 0, $field_group, [ 'title' => $title ] );
$saved = $group_id ? OPF\Service\FieldGroups::group_from_post( get_post( $group_id ) ) : null;
if ( ! $parent_id || ! $group_id || ! $saved || count( $saved->data['fields'] ) !== 2 ) {
	WP_CLI::error( 'Child-product fixture did not round-trip.' );
}
WP_CLI::log( 'CHILD_PRODUCTS_URL=' . get_permalink( $parent_id ) );
WP_CLI::log( 'CHILD_PRODUCTS_PARENT=' . $parent_id );
WP_CLI::log( 'CHILD_PRODUCTS_IDS=' . implode( ',', $child_ids ) );
WP_CLI::log( 'CHILD_PRODUCTS_CATEGORY=' . (int) $term->term_id );
WP_CLI::log( 'CHILD_PRODUCTS_TAX_RATE=' . (int) get_option( $rate_key, 0 ) );
WP_CLI::success( 'Child-product E2E fixture prepared.' );
