<?php
/**
 * Disposable WordPress fixture for OPF/WAPF hook-shape coexistence.
 *
 * WAPF Extended 3.1.5's contract for the bridged hooks is a
 * `SW_WAPF_PRO\Includes\Models\Field` object: `Cart::validate_cart_data()`
 * passes `$field_group->fields` entries (includes/classes/class-cart.php:180)
 * and every WAPF listener registered on them dereferences that argument as an
 * object — `Linked_Products_Controller::validate_cart()` declares it as a typed
 * parameter (includes/controllers/class-linked-products-controller.php:455),
 * `wapfe_validate_cart_data()` reads `$field->type` (extend/date.php:157) and
 * `maybe_add_pricing_class()` reads `$field->type` on
 * `wapf/html/field_container_classes`
 * (includes/controllers/class-linked-products-controller.php:1021).
 *
 * Usage: wp --path=<disposable clone> eval-file this-file <phase>
 *   prepare   create the fixture product + field group
 *   repro     dispatch both hooks with OPF's array the way the unbridged
 *             dispatch did, and assert WAPF rejects it
 *   guard     dispatch through OPF's bridge and assert WAPF never sees it
 *   verify    the bridge restored WAPF's own listeners afterwards
 *   cleanup   delete the fixture
 */

use OPF\Compat\WapfHooks;
use OPF\Engine\FieldGroup;
use OPF\Service\CartIntegration;
use OPF\Service\FieldGroups;

$root  = realpath( ABSPATH );
$state = sys_get_temp_dir() . '/opf-wapf-coexistence-evidence/state.json';
$assert = static function ( string $label, bool $ok ): void {
	echo ( $ok ? 'ok ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};
$collect = static function ( callable $run ): array {
	$warnings = [];
	set_error_handler( static function ( $severity, $message ) use ( &$warnings ) {
		$warnings[] = $message;
		return true;
	} );
	try {
		$run();
	} finally {
		restore_error_handler();
	}
	return $warnings;
};

if ( false === $root || '/home/followersya-5hqi7/opf-test/wordpress' !== $root ) {
	throw new RuntimeException( 'Refusing to run outside the disposable coexistence clone.' );
}

$assert( 'WAPF Extended 3.1.5 is active beside OPF', class_exists( 'SW_WAPF_PRO\\Includes\\Controllers\\Linked_Products_Controller' ) );

$phase = $args[0] ?? '';

if ( 'prepare' === $phase ) {
	$assert( 'fixture state does not already exist', ! file_exists( $state ) );

	$product = new WC_Product_Simple();
	$product->set_name( 'OPF WAPF Coexistence' );
	$product->set_slug( 'opf-wapf-coexistence' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product_id = (int) $product->save();

	$group_id = FieldGroups::save(
		0,
		[
			'fields' => [
				[ 'id' => 'note', 'label' => 'Required note', 'type' => 'text', 'required' => true ],
				[
					'id' => 'prints',
					'label' => 'Prints',
					'type' => 'image_quantity',
					'choices' => [
						[
							'slug' => 'oak',
							'label' => 'Oak',
							'quantity' => [ 'default' => 0, 'min' => 0, 'max' => 4 ],
						],
					],
				],
				[ 'id' => 'delivery', 'label' => 'Delivery', 'type' => 'date' ],
			],
			'rule_groups' => [
				[ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ],
			],
		],
		[ 'title' => 'WAPF coexistence fixture', 'status' => 'publish' ]
	);
	$assert( 'product and field group created', $product_id > 0 && $group_id > 0 );

	if ( ! is_dir( dirname( $state ) ) && ! mkdir( dirname( $state ), 0700, true ) ) {
		throw new RuntimeException( 'Could not create the isolated evidence directory.' );
	}
	file_put_contents( $state, wp_json_encode( [ 'product_id' => $product_id, 'group_id' => $group_id ], JSON_PRETTY_PRINT ) );
	echo 'COEXISTENCE_URL=' . get_permalink( $product_id ) . PHP_EOL;
	echo "SUCCESS prepare\n";
	return;
}

$assert( 'fixture state exists', file_exists( $state ) );
$data = json_decode( (string) file_get_contents( $state ), true );

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

$assert( 'fixture product and group exist', get_post( (int) $data['product_id'] ) && get_post( (int) $data['group_id'] ) );
$product = wc_get_product( (int) $data['product_id'] );

$opf_field = FieldGroup::normalize_field( [
	'id' => 'prints',
	'label' => 'Prints',
	'type' => 'image_quantity',
	'choices' => [ [ 'slug' => 'oak', 'label' => 'Oak', 'quantity' => [ 'default' => 0, 'min' => 0, 'max' => 4 ] ] ],
] );
$value = [ 'oak' => 2 ];

if ( 'repro' === $phase ) {
	// The dispatch OPF's bridge performed before the native-listener guard:
	// WAPF's own listeners receive OPF's normalized array.
	$type_error = null;
	$warnings   = $collect( static function () use ( &$type_error, $opf_field, $value, $data ) {
		try {
			apply_filters( 'wapf/validate', [ 'error' => false ], $value, $opf_field, (int) $data['product_id'], 0, 1, false, null );
		} catch ( Throwable $error ) {
			$type_error = $error;
		}
	} );
	$assert( 'WAPF rejects the OPF array on wapf/validate', $type_error instanceof TypeError );
	$type_error_message = $type_error instanceof TypeError ? $type_error->getMessage() : '';
	$assert(
		'the rejection is WAPF\'s typed Field parameter',
		false !== strpos( $type_error_message, 'validate_cart(): Argument #3 ($field) must be of type SW_WAPF_PRO\\Includes\\Models\\Field, array given' )
	);
	echo 'REPRO TypeError: ' . $type_error_message . PHP_EOL;
	$assert( 'WAPF\'s date validator also reads the OPF array as an object', count( $warnings ) > 0 );
	echo 'REPRO warnings: ' . implode( ' | ', $warnings ) . PHP_EOL;

	$container_warnings = $collect( static function () use ( $opf_field ) {
		apply_filters( 'wapf/html/field_container_classes', [ 'opf-field-container' ], $opf_field );
	} );
	$assert( 'WAPF reads $field->type on wapf/html/field_container_classes', count( $container_warnings ) > 0 );
	echo 'REPRO container warnings: ' . implode( ' | ', $container_warnings ) . PHP_EOL;
	echo "SUCCESS repro\n";
	return;
}

if ( 'guard' === $phase ) {
	$seen = [];
	add_filter( 'wapf/validate', static function ( $error, $value = null, $field = null ) use ( &$seen ) {
		$seen['validate'] = $field;
		return $error;
	}, 20, 8 );
	add_filter( 'wapf/html/field_container_classes', static function ( $classes, $field = null ) use ( &$seen ) {
		$seen['container'][] = $field;
		return $classes;
	}, 20, 2 );

	$type_error = null;
	$errors     = null;
	$warnings   = $collect( static function () use ( &$type_error, &$errors, $opf_field, $value, $product, $data ) {
		try {
			$errors = WapfHooks::validate_field( $opf_field, $value, $product, 1 );
			WapfHooks::field_container_classes( [ 'opf-field-container' ], $opf_field );
			// The real render path: pre-fix this raised one "Attempt to read
			// property \"type\" on array" per rendered field.
			$group = FieldGroups::group_from_post( get_post( (int) $data['group_id'] ) );
			ob_start();
			\OPF\Service\Renderer::render_group( (string) $data['group_id'], 'WAPF coexistence fixture', $group, 10.0, $product );
			ob_end_clean();
		} catch ( Throwable $error ) {
			$type_error = $error;
		}
	} );

	$assert( 'OPF validation completes with WAPF active', null === $type_error && [] === $errors );
	$assert( 'WAPF raises no warning for the OPF field on validation, render, or container classes', [] === $warnings );
	$assert( 'a third-party listener still receives the OPF field on wapf/validate', ( $seen['validate'] ?? null ) === $opf_field );
	$assert(
		'a third-party listener still receives the OPF field on wapf/html/field_container_classes',
		in_array( $opf_field, $seen['container'] ?? [], true )
	);
	echo "SUCCESS guard\n";
	return;
}

if ( 'verify' === $phase ) {
	// The bridge must leave WAPF's own listeners registered and functional.
	$registry = $GLOBALS['wp_filter']['wapf/validate'];
	$native   = [];
	foreach ( $registry->callbacks[10] as $entry ) {
		$callback = $entry['function'];
		$native[] = is_array( $callback ) ? get_class( $callback[0] ) . '::' . $callback[1] : $callback;
	}
	$assert( 'WAPF\'s typed validator is registered after an OPF dispatch', in_array( 'SW_WAPF_PRO\\Includes\\Controllers\\Linked_Products_Controller::validate_cart', $native, true ) );
	$assert( 'WAPF\'s date validator is registered after an OPF dispatch', in_array( 'wapfe_validate_cart_data', $native, true ) );

	$container = [];
	foreach ( $GLOBALS['wp_filter']['wapf/html/field_container_classes']->callbacks[10] as $entry ) {
		$callback    = $entry['function'];
		$container[] = is_array( $callback ) ? get_class( $callback[0] ) . '::' . $callback[1] : $callback;
	}
	$assert( 'WAPF\'s container-class listener is registered after an OPF render', in_array( 'SW_WAPF_PRO\\Includes\\Controllers\\Linked_Products_Controller::maybe_add_pricing_class', $container, true ) );

	$field           = new \SW_WAPF_PRO\Includes\Models\Field();
	$field->type     = 'products';
	$field->options  = [ 'product_selection' => 'manual' ];
	$field->pricing  = new \SW_WAPF_PRO\Includes\Models\FieldPricing();
	$field->pricing->enabled = true;
	$classes = apply_filters( 'wapf/html/field_container_classes', [], $field );
	$assert( 'WAPF still applies its own pricing class for its own Field object', in_array( 'has-pricing', $classes, true ) );
	echo "SUCCESS verify\n";
	return;
}

throw new RuntimeException( 'Expected prepare, repro, guard, verify, or cleanup.' );
