<?php
/**
 * Disposable WordPress fixture for OPF/WAPF validation-hook coexistence.
 *
 * Usage: wp --path=/tmp/opf-wapf-validation-wp eval-file this-file setup|verify|cleanup
 */

use OPF\Service\FieldGroups;

$root  = realpath( ABSPATH );
$state = '/tmp/opf-wapf-validation-evidence/state.json';
$assert = static function ( string $label, bool $ok ): void {
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( false === $root || '/tmp/opf-wapf-validation-wp' !== $root ) {
	throw new RuntimeException( 'Refusing to run outside the disposable coexistence clone.' );
}

$phase = $args[0] ?? '';
if ( 'setup' === $phase ) {
	$assert( 'fixture state does not already exist', ! file_exists( $state ) );

	$product = new WC_Product_Simple();
	$product->set_name( 'OPF WAPF Validation Coexistence' );
	$product->set_slug( 'opf-wapf-validation-coexistence' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product_id = (int) $product->save();

	$group_id = FieldGroups::save(
		0,
		[
			'fields' => [
				[ 'id' => 'required-note', 'label' => 'Required note', 'type' => 'text', 'required' => true ],
				[
					'id' => 'prints',
					'label' => 'Prints',
					'type' => 'image_quantity',
					'choices' => [
						[
							'slug' => 'oak',
							'label' => 'Oak',
							'quantity' => [ 'default' => 0, 'min' => 0, 'max' => 4 ],
							'pricing' => [ 'type' => 'fixed', 'amount' => 2.5, 'per_unit' => true ],
						],
					],
				],
			],
			'rule_groups' => [
				[
					'rules' => [
						[ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ],
					],
				],
			],
		],
		[ 'title' => 'WAPF coexistence regression', 'status' => 'publish' ]
	);
	$assert( 'product and field group created', $product_id > 0 && $group_id > 0 );

	if ( ! is_dir( dirname( $state ) ) && ! mkdir( dirname( $state ), 0700, true ) ) {
		throw new RuntimeException( 'Could not create isolated evidence directory.' );
	}
	file_put_contents( $state, wp_json_encode( [ 'product_id' => $product_id, 'group_id' => $group_id ], JSON_PRETTY_PRINT ) );
	echo "SUCCESS setup\n";
	return;
}

$assert( 'fixture state exists', file_exists( $state ) );
$data = json_decode( file_get_contents( $state ), true );
$assert( 'fixture product and group exist', get_post( (int) $data['product_id'] ) && get_post( (int) $data['group_id'] ) );

if ( 'cleanup' === $phase ) {
	if ( WC()->cart ) {
		WC()->cart->empty_cart( true );
	}
	wp_delete_post( (int) $data['product_id'], true );
	wp_delete_post( (int) $data['group_id'], true );
	FieldGroups::flush_cache();
	$assert( 'product and field group removed', ! get_post( (int) $data['product_id'] ) && ! get_post( (int) $data['group_id'] ) );
	unlink( $state );
	$assert( 'fixture state removed', ! file_exists( $state ) );
	echo "SUCCESS cleanup\n";
	return;
}

throw new RuntimeException( 'Expected setup, verify, or cleanup phase.' );
