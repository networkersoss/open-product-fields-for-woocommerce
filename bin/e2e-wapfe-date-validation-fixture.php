<?php
/** Disposable WAPF Extended date validation proof for the isolated clone. */

use OPF\Service\FieldGroups;

$root  = realpath( ABSPATH );
$state = '/tmp/opf-wapfe-date-validation-evidence/state.json';
if ( false === $root || '/tmp/opf-wapf-validation-wp' !== $root ) {
	throw new RuntimeException( 'Refusing to run outside the disposable WAPF clone.' );
}

$phase = $args[0] ?? '';
if ( 'setup' === $phase ) {
	if ( file_exists( $state ) ) {
		throw new RuntimeException( 'Refusing to replace existing fixture state.' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'OPF WAPFE Date Validation' );
	$product->set_slug( 'opf-wapfe-date-validation' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product_id = (int) $product->save();
	$group_id   = FieldGroups::save(
		0,
		[
			'fields' => [
				[ 'id' => 'delivery', 'label' => 'Delivery', 'type' => 'date', 'required' => true ],
			],
			'rule_groups' => [
				[ 'rules' => [ [ 'subject' => 'product', 'operator' => 'in', 'terms' => [ (string) $product_id ] ] ] ],
			],
		],
		[ 'title' => 'WAPFE date validation regression', 'status' => 'publish' ]
	);
	if ( $product_id < 1 || $group_id < 1 ) {
		throw new RuntimeException( 'Could not create disposable date fixtures.' );
	}
	if ( ! is_dir( dirname( $state ) ) && ! mkdir( dirname( $state ), 0700, true ) ) {
		throw new RuntimeException( 'Could not create isolated evidence directory.' );
	}
	file_put_contents( $state, wp_json_encode( [ 'product_id' => $product_id, 'group_id' => $group_id ], JSON_PRETTY_PRINT ) );
	echo "ok disposable date product and OPF group created\n";
	return;
}

if ( 'verify' === $phase ) {
	if ( ! file_exists( $state ) || ! has_filter( 'wapf/validate', 'wapfe_validate_cart_data' ) ) {
		throw new RuntimeException( 'Fixture state or active WAPF Extended date callback is missing.' );
	}
	$data    = json_decode( file_get_contents( $state ), true );
	$product = wc_get_product( (int) $data['product_id'] );
	if ( ! $product || ! get_post( (int) $data['group_id'] ) ) {
		throw new RuntimeException( 'Disposable date fixtures are missing.' );
	}
	$method = new ReflectionMethod( OPF\Service\CartIntegration::class, 'validate_values' );
	$warnings = [];
	set_error_handler(
		static function ( $severity, $message ) use ( &$warnings ) {
			if ( E_WARNING === $severity || E_NOTICE === $severity || E_DEPRECATED === $severity ) {
				$warnings[] = [ 'severity' => $severity, 'message' => $message ];
				return true;
			}
			return false;
		}
	);
	try {
		$valid_errors   = $method->invoke( null, $product, [ (string) $data['group_id'] => [ 'delivery' => '2027-06-15' ] ], 1 );
		$invalid_errors = $method->invoke( null, $product, [ (string) $data['group_id'] => [ 'delivery' => '2027-02-30' ] ], 1 );
	} finally {
		restore_error_handler();
	}
	$restored = false !== has_filter( 'wapf/validate', 'wapfe_validate_cart_data' );
	$result   = [
		'valid_date_errors'   => $valid_errors,
		'invalid_date_errors' => $invalid_errors,
		'warnings'            => $warnings,
		'wapfe_callback_restored' => $restored,
	];
	echo wp_json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
	if ( [] !== $valid_errors || 1 !== count( $invalid_errors ) || false === strpos( $invalid_errors[0], 'valid date' ) || $warnings || ! $restored ) {
		throw new RuntimeException( 'WAPF-active OPF date validation proof failed.' );
	}
	echo "ok active WAPF Extended accepts valid date, rejects impossible date, emits no PHP warning, and restores callback\n";
	return;
}

if ( 'cleanup' === $phase ) {
	if ( ! file_exists( $state ) ) {
		throw new RuntimeException( 'Fixture state is missing.' );
	}
	$data = json_decode( file_get_contents( $state ), true );
	wp_delete_post( (int) $data['product_id'], true );
	wp_delete_post( (int) $data['group_id'], true );
	FieldGroups::flush_cache();
	unlink( $state );
	echo "ok disposable date product, group, and state removed\n";
	return;
}

throw new RuntimeException( 'Expected setup, verify, or cleanup.' );
