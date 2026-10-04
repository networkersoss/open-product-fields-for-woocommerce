<?php
/** WAPF 3.1.5 field-model comparison for the real admin builder roundtrip. */

use OPF\Engine\FieldGroup;
use OPF\Service\FieldGroups;
use OPF\Service\WapfExporter;

if ( '/tmp/opf-child-builder-save-reload-wp' !== realpath( ABSPATH ) ) {
	throw new RuntimeException( 'Refusing to run outside the disposable child-builder clone.' );
}
$source = rtrim( (string) getenv( 'OPF_WAPF_SOURCE_PATH' ), '/' );
if ( '/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended' !== $source ) {
	throw new RuntimeException( 'Use the installed WAPF Extended 3.1.5 source path.' );
}
$version_file = $source . '/advanced-product-fields-for-woocommerce-extended.php';
if ( ! is_file( $version_file ) || ! preg_match( '/Version:\s*3\.1\.5\b/', file_get_contents( $version_file ) ) ) {
	throw new RuntimeException( 'Installed WAPF source version is not 3.1.5.' );
}
$dir = '/tmp/opf-child-builder-save-reload-evidence';
$state = json_decode( file_get_contents( $dir . '/state.json' ), true );
$saved = json_decode( file_get_contents( $dir . '/saved-group.json' ), true );
$check_results = [];
$check = static function ( string $label, bool $pass ) use ( &$check_results ): void {
	$check_results[] = [ 'label' => $label, 'pass' => $pass ];
	if ( ! $pass ) {
		throw new RuntimeException( $label );
	}
};

if ( empty( $saved['id'] ) || count( $saved['data']['fields'] ?? [] ) !== 3 ) {
	throw new RuntimeException( 'Saved group artifact is missing or malformed.' );
}
$group = FieldGroups::group_from_post( get_post( (int) $saved['id'] ) );
$check( 'the builder-created group still exists in the saved database', $group instanceof FieldGroup );
$canonical = static function ( $value ) use ( &$canonical ) {
	if ( is_int( $value ) || is_float( $value ) ) {
		return (float) $value;
	}
	if ( ! is_array( $value ) ) {
		return $value;
	}
	foreach ( $value as &$child ) {
		$child = $canonical( $child );
	}
	unset( $child );
	if ( ! array_is_list( $value ) ) {
		ksort( $value );
	}
	return $value;
};
$type_differences = [];
$compare_types = static function ( $left, $right, string $path = '' ) use ( &$compare_types, &$type_differences ): void {
	if ( is_array( $left ) && is_array( $right ) ) {
		foreach ( $left as $key => $value ) {
			if ( array_key_exists( $key, $right ) ) {
				$compare_types( $value, $right[ $key ], $path . '/' . $key );
			}
		}
		return;
	}
	if ( ( is_int( $left ) || is_float( $left ) ) && ( is_int( $right ) || is_float( $right ) ) && gettype( $left ) !== gettype( $right ) ) {
		$type_differences[] = [ 'path' => $path, 'database_type' => gettype( $left ), 'response_type' => gettype( $right ), 'value' => $left ];
	}
};
$compare_types( $group->data, $saved['data'] );
$check( 'database model exactly matches the final REST save response', $canonical( $group->data ) === $canonical( $saved['data'] ) );

// Load WAPF classes read-only and only register the two callbacks needed by
// WAPF's own raw Tools JSON parser. No WAPF bootstrap or WooCommerce hooks run.
spl_autoload_register( static function ( string $class ) use ( $source ): void {
	if ( 0 !== strpos( $class, 'SW_WAPF_PRO\\' ) ) {
		return;
	}
	$parts = explode( '\\', substr( $class, 12 ) );
	$name = array_pop( $parts );
	$file = $source . '/' . implode( '/', array_map( 'strtolower', $parts ) ) . '/class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );
$controller_reflection = new ReflectionClass( SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller::class );
$controller = $controller_reflection->newInstanceWithoutConstructor();
add_filter( 'wapf/field_types', [ $controller, 'add_products_field_type' ] );
add_action( 'wapf/admin/sanitize_field', [ $controller, 'sanitize_field_data' ], 10, 2 );
$payload = WapfExporter::build_payload( $group->data ) + [ 'id' => 7654321, 'type' => 'wapf_product' ];
$native = SW_WAPF_PRO\Includes\Classes\Field_Groups::raw_json_to_field_group( $payload );
$native_dump = [];
foreach ( $native->fields as $field ) {
	$native_dump[] = [ 'id' => $field->id, 'type' => $field->type, 'subtype' => $field->subtype, 'options' => $field->options ];
}
file_put_contents( $dir . '/wapf-native-model.json', wp_json_encode( [ 'fields' => $native_dump ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
$check( 'WAPF native Tools parser returns three linked-products fields', is_object( $native ) && count( $native->fields ) === 3 );

$manual = $native->fields[0];
$check( 'manual product field subtype roundtrips to WAPF', 'card' === $manual->subtype );
$check( 'manual selection and edited one-child quantity mode roundtrip', 'manual' === $manual->options['product_selection'] && 'one' === $manual->options['qty_method'] );
$manual_choices = $manual->options['choices'];
$check( 'manual child ids, fixed/free pricing roundtrip',
	count( $manual_choices ) === 2 &&
	(int) $manual_choices[0]['id'] === (int) $state['products']['alpha'] &&
	(int) $manual_choices[1]['id'] === (int) $state['products']['beta'] &&
	'fixed' === $manual_choices[0]['pricing_type'] && 'none' === $manual_choices[1]['pricing_type']
);

$quantity = $native->fields[1];
$check( 'manual quantity field subtype and +/- mode roundtrip', 'card-qty' === $quantity->subtype && 'plus_min' === $quantity->options['display'] );
$check( 'manual quantity aggregate bounds roundtrip', 2 === $quantity->options['min_choices'] && 8 === $quantity->options['max_choices'] );
$quantity_choices = $quantity->options['choices'];
$check( 'per-child quantity bounds and fixed/free pricing roundtrip',
	count( $quantity_choices ) === 2 &&
	(int) $quantity_choices[0]['id'] === (int) $state['products']['alpha'] &&
	[ 'min' => 0, 'max' => 4, 'default' => 1 ] === array_map( 'intval', $quantity_choices[0]['options'] ) &&
	'fixed' === $quantity_choices[0]['pricing_type'] &&
	(int) $quantity_choices[1]['id'] === (int) $state['products']['gamma'] &&
	[ 'min' => 1, 'max' => 6, 'default' => 2 ] === array_map( 'intval', $quantity_choices[1]['options'] ) &&
	'none' === $quantity_choices[1]['pricing_type']
);

$category = $native->fields[2];
$query = $category->options['product_query'] ?? [];
$saved_query = $group->data['fields'][2]['product_query'];
$check( 'category selection and vcard subtype roundtrip', 'category' === $category->options['product_selection'] && 'vcard' === $category->subtype );
$check( 'category id, result limit, sort and free-child pricing roundtrip',
	(int) $query['query_id'] === (int) $state['category'] &&
	(int) $query['limit'] === (int) $saved_query['limit'] &&
	$query['sort'] === $saved_query['sort'] &&
	$query['pricing_type'] === $saved_query['pricing_type'] &&
	$query['query_label'] === $saved_query['query_label']
);
$check( 'category child quantity mode roundtrips', 'parent' === $category->options['qty_method'] );

file_put_contents( $dir . '/wapf-native-model.json', wp_json_encode( [
	'version' => '3.1.5',
	'source_file_sha256' => hash_file( 'sha256', $version_file ),
	'linked_products_controller_sha256' => hash_file( 'sha256', $source . '/includes/controllers/class-linked-products-controller.php' ),
	'field_groups_sha256' => hash_file( 'sha256', $source . '/includes/classes/class-field-groups.php' ),
	'database_vs_response_numeric_php_type_differences' => $type_differences,
	'fields' => $native_dump,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
file_put_contents( $dir . '/reference-results.json', wp_json_encode( $check_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
echo count( $check_results ) . " WAPF model checks passed.\n";
