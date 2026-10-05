<?php
/**
 * Activation-cycle + uninstall cleanliness test.
 *
 * Run LAST in the test sequence (it deletes OPF groups):
 *   wp eval-file bin/e2e-uninstall-test.php
 *
 * Verifies:
 *  - deactivate → reactivate leaves data intact and re-registers services
 *  - the uninstall routine removes options + field group posts
 *  - order item meta survives (historical data must outlive the plugin)
 *
 * Exits non-zero on failure.
 */

defined( 'ABSPATH' ) || exit;

global $failures;
$failures = 0;

function check( $label, $condition ): void {
	global $failures;
	if ( $condition ) {
		WP_CLI::log( "  ok    $label" );
	} else {
		$failures++;
		WP_CLI::log( "  FAIL  $label" );
	}
}

WP_CLI::log( '== OPF activation-cycle + uninstall ==' );

// Seed one group and one order with meta to assert historical survival.
$gid = OPF\Service\FieldGroups::save(
	0,
	new OPF\Engine\FieldGroup( [
		'fields' => [
			[
				'id' => 'note', 'label' => 'Note', 'description' => '', 'type' => 'text', 'required' => false,
				'width' => 100, 'choices' => [],
				'pricing' => [ 'type' => 'none', 'amount' => 0.0, 'formula' => '' ],
				'conditionals' => [],
			],
		],
		'rule_groups' => [],
		'mark_required' => false,
		'labels_position' => 'above',
	] ),
	[ 'title' => 'Uninstall Probe' ]
);
update_post_meta( $gid, '_opf_imported_from', 'probe' );
check( 'seed: probe group created', $gid > 0 );

$order = wc_create_order();
$item  = new WC_Order_Item_Product();
$item->set_props( [ 'product' => wc_get_products( [ 'limit' => 1, 'return' => 'objects' ] )[0], 'quantity' => 1 ] );
$item->add_meta_data( '_opf_fields', wp_json_encode( [ '999' => [ 'note' => 'keep me' ] ] ), true );
$order->add_item( $item );
$order->save();
$order_id = $order->get_id();
check( 'seed: probe order with _opf_fields meta', $order_id > 0 );

// Customer upload bytes must outlive plugin removal just like order meta.
$e2e_webroot = realpath( ABSPATH );
add_filter( 'opf_upload_document_root', static fn( string $root ): string => false !== $e2e_webroot ? $e2e_webroot : $root );
$upload_dir   = OPF\Engine\UploadService::dir();
$upload_token = bin2hex( random_bytes( 24 ) );
$upload_path  = is_string( $upload_dir ) ? $upload_dir . '/' . $upload_token . '.txt' : '';
$upload_bytes = 'uninstall must preserve referenced customer files';
check( 'seed: private upload storage is outside webroot', is_string( $upload_dir ) && false !== $e2e_webroot && 0 !== strpos( $upload_dir, rtrim( $e2e_webroot, '/' ) . '/' ) );
check( 'seed: private upload probe written', '' !== $upload_path && false !== file_put_contents( $upload_path, $upload_bytes ) );

// ------------------------------------------------ deactivate / reactivate.
deactivate_plugins( 'open-product-fields-for-woocommerce/open-product-fields-for-woocommerce.php', true );
// Simulate a fresh request (a running request keeps registrations alive).
unregister_post_type( 'opf_field_group' );
check( 'deactivate: CPT unregistered', ! post_type_exists( 'opf_field_group' ) );
check( 'deactivate: group data still on disk', get_post( $gid ) instanceof WP_Post && '' !== get_post_field( 'post_content', $gid ) );

activate_plugin( 'open-product-fields-for-woocommerce/open-product-fields-for-woocommerce.php', '', false, true );
// What opf_boot() registers on the next request:
OPF\Service\FieldGroups::init();
OPF\Service\FieldGroups::register_cpt();
check( 'reactivate: CPT re-registered', post_type_exists( 'opf_field_group' ) );
check( 'reactivate: group intact', OPF\Service\FieldGroups::group_from_post( get_post( $gid ) ) instanceof OPF\Engine\FieldGroup );

// ------------------------------------------------------- uninstall routine.
define( 'WP_UNINSTALL_PLUGIN', true );
include OPF_DIR . 'uninstall.php';

check( 'uninstall: option removed', ! get_option( 'opf_version' ) );
check( 'uninstall: field group posts removed', 0 === count( get_posts( [ 'post_type' => 'opf_field_group', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ] ) ) );
$probe_order = wc_get_order( $order_id );
$probe_item  = $probe_order ? array_values( $probe_order->get_items() )[0] : null;
check( 'uninstall: historical order meta survives', $probe_item && is_string( $probe_item->get_meta( '_opf_fields', true ) ) );
check( 'uninstall: private customer upload survives', '' !== $upload_path && is_file( $upload_path ) && file_get_contents( $upload_path ) === $upload_bytes );
if ( '' !== $upload_path && is_file( $upload_path ) ) {
	unlink( $upload_path );
}

WP_CLI::log( '' );
if ( $failures > 0 ) {
	WP_CLI::error( "$failures check(s) failed." );
} else {
	WP_CLI::success( 'All activation-cycle + uninstall checks passed.' );
}
