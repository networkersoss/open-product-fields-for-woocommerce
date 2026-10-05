<?php

use OPF\Service\LookupTableCsvImporter;
use PHPUnit\Framework\TestCase;

final class LookupTableCsvImporterTest extends TestCase {
	public function test_grid_uses_cell_a1_name_and_converts_to_rows(): void {
		$this->assertSame(
			[ 'blinds' => [ [ '100', '200', 10.0 ], [ '100', '220', 12.0 ], [ '120', '200', 15.0 ], [ '120', '220', 17.0 ] ] ],
			LookupTableCsvImporter::parse_csv( "blinds,200,220\n100,10,12\n120,15,17\n", 'fallback.csv' )
		);
	}

	public function test_grid_uses_filename_when_cell_a1_is_empty(): void {
		$this->assertSame(
			[ 'area_rates' => [ [ '100', '200', 10.0 ], [ '120', '200', 15.0 ] ] ],
			LookupTableCsvImporter::parse_csv( ",200\n100,10\n120,15\n", 'area_rates.csv' )
		);
	}

	public function test_grid_accepts_categorical_axis_values(): void {
		$this->assertSame(
			[ 'fabric' => [ [ 'Red', 'Small', 10.0 ], [ 'Red', 'Large', 12.0 ], [ 'Blue', 'Small', 20.0 ], [ 'Blue', 'Large', 22.0 ] ] ],
			LookupTableCsvImporter::parse_csv( "fabric,Small,Large\nRed,10,12\nBlue,20,22\n", 'fallback.csv' )
		);
	}

	public function test_three_or_more_dimensions_use_filename_and_keep_row_order(): void {
		$this->assertSame(
			[ 'print_prices' => [ [ '10', 'A4', 'Matte', 3.5 ], [ '20', 'A3', 'Gloss', 7.0 ] ] ],
			LookupTableCsvImporter::parse_csv( "Quantity,Paper size,Paper type,Price\n10,A4,Matte,3.5\n20,A3,Gloss,7\n", 'print_prices.csv' )
		);
	}

	public function test_one_dimension_row_list_is_supported(): void {
		$this->assertSame(
			[ 'unit_rates' => [ [ 'small', 2.0 ], [ 'large', 4.5 ] ] ],
			LookupTableCsvImporter::parse_csv( "Size,Price\nsmall,2\nlarge,4.5\n", 'unit_rates.csv' )
		);
	}

	public function test_parser_rejects_rows_or_expanded_grid_entries_over_normalizer_limit(): void {
		$row_list = "Dimension,Price\n" . str_repeat( "1,2\n", LookupTableCsvImporter::MAX_DATA_ROWS + 1 );
		try {
			LookupTableCsvImporter::parse_csv( $row_list, 'too_many.csv' );
			$this->fail( 'Expected row-list limit rejection.' );
		} catch ( InvalidArgumentException $error ) {
			$this->assertStringContainsString( '5,000', $error->getMessage() );
		}

		$grid = "rates,Small,Large\n" . str_repeat( "row,1,2\n", 2501 );
		$this->expectException( InvalidArgumentException::class );
		LookupTableCsvImporter::parse_csv( $grid, 'expanded.csv' );
	}

	public function test_parser_rejects_too_many_columns(): void {
		$this->expectException( InvalidArgumentException::class );
		LookupTableCsvImporter::parse_csv( implode( ',', array_fill( 0, LookupTableCsvImporter::MAX_COLUMNS + 1, 'x' ) ) . "\n" . implode( ',', array_fill( 0, LookupTableCsvImporter::MAX_COLUMNS + 1, '1' ) ), 'wide.csv' );
	}

	/** @dataProvider invalid_csv_provider */
	public function test_rejects_invalid_csv( string $csv, string $filename ): void {
		$this->expectException( InvalidArgumentException::class );
		LookupTableCsvImporter::parse_csv( $csv, $filename );
	}

	public static function invalid_csv_provider(): array {
		return [
			'empty' => [ '', 'rates.csv' ],
			'invalid name' => [ "Size,Price\nsmall,10\n", '../rates.csv' ],
			'ragged grid' => [ "rates,200,220\n100,10\n", 'rates.csv' ],
			'invalid grid name' => [ "bad table,Small,Large\nRed,10,12\n", 'rates.csv' ],
			'two dimension list' => [ "Width,Height,Price\n10,20,30\n15,25,40\n", 'rates.csv' ],
			'non numeric price' => [ "Size,Price\nsmall,free\n", 'rates.csv' ],
			'oversized' => [ str_repeat( 'x', LookupTableCsvImporter::MAX_FILE_BYTES + 1 ), 'rates.csv' ],
		];
	}
}
