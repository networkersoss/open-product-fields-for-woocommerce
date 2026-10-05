<?php
/** Disposable WordPress future-post field-group fixture. Usage: wp eval-file ... prepare|verify|cleanup */
defined( 'ABSPATH' ) || exit;

$action    = isset( $args[0] ) ? (string) $args[0] : 'prepare';
$state_key = 'opf_e2e_scheduled_group_state';
$title     = 'OPF E2E Scheduled Group';
$name      = 'OPF E2E Scheduled Product';
$state     = get_option( $state_key, [] );

if ( 'cleanup' === $action ) {
	if ( is_array( $state ) ) {
		foreach ( (array) ( $state['group_ids'] ?? [] ) as $id ) {
			wp_clear_scheduled_hook( 'publish_future_post', [ (int) $id ] );
			wp_delete_post( (int) $id, true );
		}
		if ( ! empty( $state['product_id'] ) ) {
			wp_delete_post( (int) $state['product_id'], true );
		}
	}
	foreach ( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => -1, 'title' => $title ] ) as $post ) {
		wp_clear_scheduled_hook( 'publish_future_post', [ (int) $post->ID ] );
		wp_delete_post( (int) $post->ID, true );
	}
	foreach ( wc_get_products( [ 'limit' => -1, 'return' => 'objects', 'name' => sanitize_title( $name ), 'status' => [ 'publish', 'draft', 'private' ] ] ) as $product ) {
		$product->delete( true );
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::success( 'Scheduled-group fixtures removed.' );
	return;
}

if ( 'prepare' === $action ) {
	if ( $state ) {
		WP_CLI::error( 'Scheduled-group fixture already exists; clean it before retrying.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_slug( sanitize_title( $name ) );
	$product->set_regular_price( '10.00' );
	$product->set_status( 'publish' );
	$product_id = $product->save();
	$group = new OPF\Engine\FieldGroup( [ 'fields' => [ [ 'id' => 'scheduled_note', 'label' => 'Scheduled note', 'type' => 'text' ] ] ] );
	$future_gmt = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
	$future_local = get_date_from_gmt( $future_gmt );
	$group_id = wp_insert_post( wp_slash( [
		'post_type' => 'opf_field_group',
		'post_status' => 'future',
		'post_title' => $title,
		'post_content' => wp_json_encode( $group->data ),
		'post_date' => $future_local,
		'post_date_gmt' => $future_gmt,
	] ), true );
	if ( is_wp_error( $group_id ) || ! $product_id || get_post_status( $group_id ) !== 'future' ) {
		WP_CLI::error( 'Could not prepare scheduled group fixture.' );
	}
	$state = [ 'product_id' => $product_id, 'group_ids' => [ (int) $group_id ] ];
	update_option( $state_key, $state, false );
	OPF\Service\FieldGroups::flush_cache();
	WP_CLI::log( 'SCHEDULED_GROUP_IDS=' . wp_json_encode( $state ) );
	WP_CLI::log( 'SCHEDULED_GROUP_ADMIN_URL=' . admin_url( 'edit.php?post_type=opf_field_group&s=' . rawurlencode( $title ) ) );
	WP_CLI::success( 'Future field-group fixture prepared.' );
	return;
}

if ( 'verify' === $action ) {
	if ( ! is_array( $state ) || empty( $state['product_id'] ) || empty( $state['group_ids'][0] ) ) {
		WP_CLI::error( 'Scheduled-group fixture is missing.' );
	}
	$group_id = (int) $state['group_ids'][0];
	$product = wc_get_product( (int) $state['product_id'] );
	$post = get_post( $group_id );
	$scheduled_event = wp_next_scheduled( 'publish_future_post', [ $group_id ] );
	$admin_list_entry = get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => [ 'publish', 'future' ], 'posts_per_page' => -1, 'title' => $title ] );
	OPF\Service\FieldGroups::flush_cache();
	$before = array_map( static fn( $entry ): int => (int) $entry['id'], OPF\Service\FieldGroups::for_product( $product ) );
	if ( ! $post || 'future' !== $post->post_status || ! $scheduled_event || ! in_array( $group_id, wp_list_pluck( $admin_list_entry, 'ID' ), true ) || in_array( $group_id, $before, true ) ) {
		WP_CLI::error( 'Future group was not scheduled, admin-visible, or withheld from storefront fields.' );
	}

	$past_gmt = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
	$past_local = get_date_from_gmt( $past_gmt );
	wp_update_post( [ 'ID' => $group_id, 'post_date' => $past_local, 'post_date_gmt' => $past_gmt ] );
	check_and_publish_future_post( $group_id );
	OPF\Service\FieldGroups::flush_cache();
	$after = array_map( static fn( $entry ): int => (int) $entry['id'], OPF\Service\FieldGroups::for_product( $product ) );
	if ( 'publish' !== get_post_status( $group_id ) || ! in_array( $group_id, $after, true ) ) {
		WP_CLI::error( 'WordPress future-post publication did not activate the group on the storefront.' );
	}
	WP_CLI::success( 'Scheduled group appeared in the admin list, stayed off the storefront while future, then became available when WordPress published it.' );
	return;
}

WP_CLI::error( 'Use prepare, verify, or cleanup.' );
