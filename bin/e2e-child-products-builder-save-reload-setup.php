<?php
/** Create owned linked-products builder fixtures in the marked disposable clone. */

if ( '1' !== getenv( 'OPF_CHILD_BUILDER_ALLOW' ) || '/tmp/opf-child-builder-save-reload-wp' !== realpath( ABSPATH ) ) {
	throw new RuntimeException( 'Explicitly opt in on /tmp/opf-child-builder-save-reload-wp only.' );
}
$option = 'opf_child_builder_roundtrip_state';
$names = [ 'Builder child alpha', 'Builder child beta', 'Builder child gamma' ];
if ( get_option( $option, false ) || term_exists( 'builder-child-proof', 'product_cat' ) ) {
	throw new RuntimeException( 'Builder proof fixture already exists; clean it up before setup.' );
}
foreach ( $names as $name ) {
	if ( get_page_by_title( $name, OBJECT, 'product' ) ) {
		throw new RuntimeException( 'Refusing to reuse an existing product titled ' . $name );
	}
}
$term = wp_insert_term( 'Builder child proof', 'product_cat', [ 'slug' => 'builder-child-proof' ] );
if ( is_wp_error( $term ) ) {
	throw new RuntimeException( $term->get_error_message() );
}
$prices = [ 'Builder child alpha' => 8, 'Builder child beta' => 12, 'Builder child gamma' => 6 ];
$ids = [];
foreach ( $prices as $name => $price ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_regular_price( (string) $price );
	$product->set_virtual( true );
	$product->set_category_ids( [ (int) $term['term_id'] ] );
	$ids[ $name ] = $product->save();
}
$state = [
	'category' => (int) $term['term_id'],
	'products' => [
		'alpha' => (int) $ids['Builder child alpha'],
		'beta'  => (int) $ids['Builder child beta'],
		'gamma' => (int) $ids['Builder child gamma'],
	],
];
update_option( $option, $state, false );
$dir = '/tmp/opf-child-builder-save-reload-evidence';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0700, true );
}
file_put_contents( $dir . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
echo wp_json_encode( $state, JSON_PRETTY_PRINT ) . "\n";
