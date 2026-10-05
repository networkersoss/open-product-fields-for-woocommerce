<?php
/**
 * Disposable WordPress integration check for lookup CSV import/replacement/pricing.
 * Run only inside a disposable WordPress + WooCommerce install:
 *   wp eval-file bin/e2e-lookup-table-csv.php
 */

defined( 'ABSPATH' ) || exit;

// Permit this script to exercise the source checkout against a separate
// disposable WordPress install without copying files into that install.
spl_autoload_register(
	static function ( $class ): void {
		if ( 'OPF\\Service\\LookupTableCsvImporter' === $class ) {
		require_once dirname( __DIR__ ) . '/includes/Service/LookupTableCsvImporter.php';
		}
	},
	true,
	true
);

$previous_tables = get_option( 'opf_lookup_tables', null );
$temporary_csv   = wp_tempnam( 'opf-lookup-csv-e2e.csv' );
$failures        = 0;
$option_filter   = static function ( array $tables ): array {
	return array_replace( $tables, get_option( 'opf_lookup_tables', [] ) );
};
add_filter( 'opf_lookup_tables', $option_filter );

$check = static function ( string $label, bool $condition ) use ( &$failures ): void {
	WP_CLI::log( ( $condition ? '  ok    ' : '  FAIL  ' ) . $label );
	if ( ! $condition ) {
		$failures++;
	}
};

try {
	if ( ! $temporary_csv ) {
		throw new RuntimeException( 'Could not create temporary CSV file.' );
	}
	WP_CLI::log( '== OPF lookup table CSV E2E ==' );

	file_put_contents( $temporary_csv, "Width,Price\n1,10\n2,25\n" );
	$first = OPF\Service\LookupTableCsvImporter::import_csv_file( $temporary_csv, 'csv_rates.csv' );
	$tables = OPF\Engine\FieldGroup::lookup_tables_for_group( [] );
	$first_price = OPF\Engine\Calculator::evaluate_formula( 'lookuptable(csv_rates; width)', 0, 1, 0, '', [ 'width' => '2' ], null, [], $tables );
	$check( 'row-list import persists reusable table through WordPress option', isset( $first['csv_rates'] ) && isset( get_option( 'opf_lookup_tables', [] )['csv_rates'] ) );
	$check( 'imported table participates in server-side lookup pricing', 25.0 === $first_price );

	file_put_contents( $temporary_csv, "Width,Price\n1,11\n2,31\n" );
	$replacement = OPF\Service\LookupTableCsvImporter::import_csv_file( $temporary_csv, 'csv_rates.csv' );
	$tables = OPF\Engine\FieldGroup::lookup_tables_for_group( [] );
	$replacement_price = OPF\Engine\Calculator::evaluate_formula( 'lookuptable(csv_rates; width)', 0, 1, 0, '', [ 'width' => '2' ], null, [], $tables );
	$check( 'same-name upload replaces the previous table rows', [ [ '1', 11.0 ], [ '2', 31.0 ] ] === $replacement['csv_rates'] );
	$check( 'pricing reads the replacement table value', 31.0 === $replacement_price );

	file_put_contents( $temporary_csv, "grid_rates,Red,Blue\nSmall,10,12\nLarge,20,22\n" );
	$grid = OPF\Service\LookupTableCsvImporter::import_csv_file( $temporary_csv, 'ignored.csv' );
	$tables = OPF\Engine\FieldGroup::lookup_tables_for_group( [] );
	$grid_price = OPF\Engine\Calculator::evaluate_formula( 'lookuptable(grid_rates; color; size)', 0, 1, 0, '', [ 'color' => 'Large', 'size' => 'Blue' ], null, [], $tables );
	$check( 'two-dimensional grid survives option storage and server-side formula lookup', isset( $grid['grid_rates'] ) && 22.0 === $grid_price );

	file_put_contents( $temporary_csv, "Quantity,Paper size,Paper type,Price\n10,A4,Matte,3.5\n20,A3,Gloss,7.5\n" );
	$list3 = OPF\Service\LookupTableCsvImporter::import_csv_file( $temporary_csv, 'print_prices.csv' );
	$tables = OPF\Engine\FieldGroup::lookup_tables_for_group( [] );
	$list3_price = OPF\Engine\Calculator::evaluate_formula( 'lookuptable(print_prices; quantity; paper_size; paper_type)', 0, 1, 0, '', [ 'quantity' => '20', 'paper_size' => 'A3', 'paper_type' => 'Gloss' ], null, [], $tables );
	$check( 'three-dimensional row list survives option storage and server-side formula lookup', isset( $list3['print_prices'] ) && 7.5 === $list3_price );

	file_put_contents( $temporary_csv, "Quantity,Paper size,Paper type,Price\n20,A3,Gloss,free\n" );
	try {
		OPF\Service\LookupTableCsvImporter::import_csv_file( $temporary_csv, 'print_prices.csv' );
		$malformed_preserved = false;
	} catch ( InvalidArgumentException $error ) {
		$tables = OPF\Engine\FieldGroup::lookup_tables_for_group( [] );
		$malformed_preserved = 7.5 === OPF\Engine\Calculator::evaluate_formula( 'lookuptable(print_prices; quantity; paper_size; paper_type)', 0, 1, 0, '', [ 'quantity' => '20', 'paper_size' => 'A3', 'paper_type' => 'Gloss' ], null, [], $tables );
	}
	$check( 'malformed same-name upload leaves the saved pricing table unchanged', $malformed_preserved );

	ob_start();
	OPF\Service\LookupTableCsvImporter::render_settings_link( [ 'title' => 'Lookup table CSV' ] );
	$settings_link = (string) ob_get_clean();
	$check( 'WooCommerce product settings link opens the CSV uploader', false !== strpos( $settings_link, 'page=opf-lookup-csv' ) );
} catch ( Throwable $error ) {
	$failures++;
	WP_CLI::warning( $error->getMessage() );
} finally {
	remove_filter( 'opf_lookup_tables', $option_filter );
	if ( $temporary_csv && file_exists( $temporary_csv ) ) {
		unlink( $temporary_csv );
	}
	if ( null === $previous_tables ) {
		delete_option( 'opf_lookup_tables' );
	} else {
		update_option( 'opf_lookup_tables', $previous_tables, false );
	}
}

if ( $failures > 0 ) {
	WP_CLI::error( $failures . ' lookup CSV integration check(s) failed.' );
}
WP_CLI::success( 'Lookup CSV import, replacement, and pricing checks passed.' );
