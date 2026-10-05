<?php
/**
 * Uninstall routine. Only runs when the plugin is deleted via WP admin.
 *
 * Removes plugin configuration and field groups. Order item meta and private
 * upload bytes are intentionally preserved — historical orders must survive
 * plugin removal, and their files remain customer data.
 *
 * @package open-product-fields-for-woocommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'opf_version' );
delete_option( 'opf_formula_variables' );
delete_option( 'opf_lookup_tables' );
wp_clear_scheduled_hook( 'opf_cleanup_uploads' );

$groups = get_posts(
	[
		'post_type'      => 'opf_field_group',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	]
);

foreach ( $groups as $group_id ) {
	wp_delete_post( $group_id, true );
}
