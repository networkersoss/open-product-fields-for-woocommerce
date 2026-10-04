<?php
/** Delete only this lane's fixture records from the marked disposable clone. */

if ( '1' !== getenv( 'OPF_CHILD_BUILDER_ALLOW' ) || '/tmp/opf-child-builder-save-reload-wp' !== realpath( ABSPATH ) ) {
	throw new RuntimeException( 'Explicitly opt in on /tmp/opf-child-builder-save-reload-wp only.' );
}
$option = 'opf_child_builder_roundtrip_state';
$state = get_option( $option, [] );
$groups = get_posts( [
	'post_type' => 'opf_field_group',
	'post_status' => 'any',
	'numberposts' => -1,
	'fields' => 'ids',
	'title' => 'OPF child-products builder save reload proof',
] );
$deleted_groups = [];
foreach ( $groups as $group_id ) {
	$post = get_post( $group_id );
	if ( ! $post || 'opf_field_group' !== $post->post_type || 'OPF child-products builder save reload proof' !== $post->post_title ) {
		throw new RuntimeException( 'Refusing to delete a field group that does not match the owned proof title.' );
	}
	if ( wp_delete_post( $group_id, true ) ) {
		$deleted_groups[] = (int) $group_id;
	}
}
$deleted_products = [];
foreach ( (array) ( $state['products'] ?? [] ) as $product_id ) {
	$product = wc_get_product( (int) $product_id );
	if ( ! $product || ! in_array( $product->get_name(), [ 'Builder child alpha', 'Builder child beta', 'Builder child gamma' ], true ) ) {
		throw new RuntimeException( 'Refusing to delete a product that does not match the owned proof names.' );
	}
	if ( wp_delete_post( (int) $product_id, true ) ) {
		$deleted_products[] = (int) $product_id;
	}
}
$term = get_term_by( 'slug', 'builder-child-proof', 'product_cat' );
$deleted_category = false;
if ( $term && (int) $term->term_id === (int) ( $state['category'] ?? 0 ) && 'Builder child proof' === $term->name ) {
	$result = wp_delete_term( $term->term_id, 'product_cat' );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	$deleted_category = true;
}
delete_option( $option );
$result = [ 'deleted_group_ids' => $deleted_groups, 'deleted_product_ids' => $deleted_products, 'deleted_category' => $deleted_category, 'state_option_removed' => false === get_option( $option, false ) ];
$dir = '/tmp/opf-child-builder-save-reload-evidence';
file_put_contents( $dir . '/cleanup-results.json', wp_json_encode( $result, JSON_PRETTY_PRINT ) );
echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
