<?php
/** Disposable manual-variation + relative-quantity fixture for WooCommerce. */
if ( '1' !== getenv( 'OPF_CHILD_VARIATION_ALLOW' ) || 0 !== strpos( realpath( ABSPATH ), '/tmp/opf-image-child-wp' ) ) {
	throw new RuntimeException( 'Explicit disposable /tmp/opf-image-child-wp clone authorization required.' );
}

$state_key = 'opf_child_variation_relative_state';
$state     = get_option( $state_key, [] );
$artifact  = getenv( 'OPF_CHILD_VARIATION_ARTIFACT_DIR' ) ?: '/tmp/opf-child-variation-relative-evidence';
if ( ! is_dir( $artifact ) ) {
	mkdir( $artifact, 0777, true );
}
$phase = getenv( 'OPF_CHILD_VARIATION_PHASE' ) ?: 'setup';

if ( 'cleanup' === $phase ) {
	if ( empty( $state ) ) {
		echo "No variation-relative fixture state to clean.\n";
		return;
	}
	foreach ( [ $state['variation'], $state['variable'], $state['relative'], $state['parent'], $state['group'] ] as $id ) {
		wp_delete_post( (int) $id, true );
	}
	delete_option( $state_key );
	OPF\Service\FieldGroups::flush_cache();
	@unlink( $artifact . '/state.json' );
	echo "SUCCESS variation-relative fixtures cleaned\n";
	return;
}

if ( 'setup' !== $phase || ! empty( $state ) ) {
	throw new RuntimeException( 'Unknown phase or stale fixture exists; run cleanup before setup.' );
}

$parent = new WC_Product_Simple();
$parent->set_name( 'OPF Variation Relative Parent' );
$parent->set_slug( 'opf-variation-relative-parent' );
$parent->set_status( 'publish' );
$parent->set_regular_price( '20' );
$parent->set_virtual( true );
$parent_id = $parent->save();

$relative = new WC_Product_Simple();
$relative->set_name( 'OPF Relative Child' );
$relative->set_slug( 'opf-relative-child' );
$relative->set_status( 'publish' );
$relative->set_regular_price( '4' );
$relative->set_virtual( true );
$relative_id = $relative->save();

$variable = new WC_Product_Variable();
$variable->set_name( 'OPF Variable Child' );
$variable->set_slug( 'opf-variable-child' );
$variable->set_status( 'publish' );
$variable->set_virtual( true );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Color' );
$attribute->set_options( [ 'red' ] );
$attribute->set_position( 0 );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( [ $attribute ] );
$variable_id = $variable->save();

$variation = new WC_Product_Variation();
$variation->set_parent_id( $variable_id );
$variation->set_attributes( [ 'attribute_color' => 'red' ] );
$variation->set_status( 'publish' );
$variation->set_virtual( true );
$variation->set_regular_price( '15' );
$variation_id = $variation->save();

$group = [
	'fields'      => [
		[
			'id' => 'linked_variation', 'label' => 'Variable child', 'type' => 'products', 'subtype' => 'card',
			'product_selection' => 'manual', 'qty_method' => 'parent',
			'choices' => [ [ 'product_id' => $variation_id, 'slug' => 'variable-red', 'pricing_type' => 'fixed' ] ],
		],
		[
			'id' => 'linked_relative', 'label' => 'Relative child quantity', 'type' => 'products', 'subtype' => 'card-qty',
			'product_selection' => 'manual', 'min_choices' => 0, 'max_choices' => 10,
			'choices' => [ [ 'product_id' => $relative_id, 'slug' => 'relative-child', 'quantity' => [ 'min' => 0, 'max' => 5 ] ] ],
		],
	],
	'rule_groups' => [ [ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $parent_id ] ] ] ] ],
];
$group_id = OPF\Service\FieldGroups::save( 0, $group, [ 'title' => 'OPF Variation Relative E2E', 'status' => 'publish' ] );
if ( ! $group_id ) {
	throw new RuntimeException( 'Could not persist variation-relative field group.' );
}
$state = [
	'parent' => $parent_id,
	'relative' => $relative_id,
	'variable' => $variable_id,
	'variation' => $variation_id,
	'group' => $group_id,
];
update_option( $state_key, $state, false );
file_put_contents( $artifact . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT ) );
echo 'SUCCESS variation-relative fixtures created: ' . wp_json_encode( $state ) . "\n";
