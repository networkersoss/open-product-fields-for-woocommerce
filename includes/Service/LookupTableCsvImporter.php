<?php
/** Import reusable lookup tables from the CSV layouts documented by WAPF. */

namespace OPF\Service;

use InvalidArgumentException;
use OPF\Engine\FieldGroup;

defined( 'ABSPATH' ) || exit;

final class LookupTableCsvImporter {
	public const MAX_FILE_BYTES = 5 * 1024 * 1024;
	public const MAX_DATA_ROWS  = 5000;
	public const MAX_COLUMNS    = 65; // 64 dimensions plus price.
	private const OPTION        = 'opf_lookup_tables';

	/**
	 * Parse one grid or row-list CSV into the normalized table format.
	 *
	 * @return array<string,array<int,array<int,string|float>>>
	 * @throws InvalidArgumentException On malformed or over-limit input.
	 */
	public static function parse_csv( string $csv, string $filename ): array {
		if ( strlen( $csv ) > self::MAX_FILE_BYTES ) {
			throw new InvalidArgumentException( 'CSV exceeds the 5 MiB upload limit.' );
		}
		$name = '';
		$csv  = preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) ?? $csv;
		$stream = fopen( 'php://temp', 'w+b' );
		if ( false === $stream ) {
			throw new InvalidArgumentException( 'Unable to read CSV data.' );
		}
		fwrite( $stream, $csv );
		rewind( $stream );
		$records = [];
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
			if ( [ null ] === $row || ( 1 === count( $row ) && null === $row[0] ) ) {
				continue;
			}
			if ( array_filter( $row, static fn( $cell ) => null !== $cell && '' !== trim( (string) $cell ) ) ) {
				$records[] = array_map( static fn( $cell ) => trim( (string) $cell ), $row );
			}
			if ( count( $records ) > self::MAX_DATA_ROWS + 1 ) {
				fclose( $stream );
				throw new InvalidArgumentException( 'CSV has more than 5,000 data rows.' );
			}
		}
		fclose( $stream );
		if ( count( $records ) < 2 ) {
			throw new InvalidArgumentException( 'CSV must contain a header/grid row and at least one data row.' );
		}
		foreach ( $records as $row ) {
			if ( count( $row ) > self::MAX_COLUMNS ) {
				throw new InvalidArgumentException( 'CSV has more than 65 columns.' );
			}
		}

		$first = $records[0];
		$last_header = strtolower( (string) end( $first ) );
		$is_row_list = 'price' === $last_header;
		$is_grid = ! $is_row_list
			&& count( $first ) >= 2
			&& ( '' === $first[0] || count( $first ) >= 3 )
			&& ( count( $first ) >= 3 || '' === $first[0] )
			&& self::grid_price_cells_are_numeric( $records, count( $first ) );
		if ( $is_grid ) {
			if ( '' !== $first[0] && ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $first[0] ) ) {
				throw new InvalidArgumentException( 'Grid table name in cell A1 must use 1–64 letters, numbers, or underscores.' );
			}
			if ( count( $first ) < 2 ) {
				throw new InvalidArgumentException( 'Lookup grid needs at least one column coordinate.' );
			}
			if ( ( count( $records ) - 1 ) * ( count( $first ) - 1 ) > self::MAX_DATA_ROWS ) {
				throw new InvalidArgumentException( 'Expanded lookup grid has more than 5,000 rows.' );
			}
			$name = '' !== $first[0] ? $first[0] : self::filename_table_name( $filename );
			$rows = [];
			foreach ( array_slice( $records, 1 ) as $row ) {
				if ( count( $row ) !== count( $first ) || '' === $row[0] ) {
					throw new InvalidArgumentException( 'Grid rows must have one row coordinate and one price per column coordinate.' );
				}
				foreach ( array_slice( $row, 1 ) as $index => $price ) {
					if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) ) {
						throw new InvalidArgumentException( 'Every lookup table price must be a finite number.' );
					}
					$rows[] = [ $row[0], $first[ $index + 1 ], (float) $price ];
				}
			}
		} else {
			$name = self::filename_table_name( $filename );
			$column_count = count( $first );
			if ( $column_count < 2 || $column_count > self::MAX_COLUMNS ) {
				throw new InvalidArgumentException( 'Row-list CSV must have at least one dimension and one price column.' );
			}
			if ( 2 === $column_count - 1 ) {
				throw new InvalidArgumentException( 'Two-dimensional lookup tables must use the grid layout.' );
			}
			$rows = [];
			foreach ( array_slice( $records, 1 ) as $row ) {
				if ( count( $row ) !== $column_count ) {
					throw new InvalidArgumentException( 'Every row-list record must have the same number of columns.' );
				}
				$price = array_pop( $row );
				if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) ) {
					throw new InvalidArgumentException( 'Every lookup table price must be a finite number.' );
				}
				$rows[] = array_merge( $row, [ (float) $price ] );
			}
		}

		$normalized = FieldGroup::normalize_lookup_tables( [ $name => $rows ] );
		if ( ! isset( $normalized[ $name ] ) || count( $normalized[ $name ] ) !== count( $rows ) ) {
			throw new InvalidArgumentException( 'CSV contains an invalid table name, coordinate, or row.' );
		}
		return $normalized;
	}

	/** Register the WooCommerce submenu and its capability-protected upload handler. */
	public static function init_admin(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
		add_action( 'admin_post_opf_import_lookup_csv', [ __CLASS__, 'handle_admin_upload' ] );
	}

	public static function register_admin_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Lookup table CSV', 'open-product-fields-for-woocommerce' ),
			__( 'Lookup table CSV', 'open-product-fields-for-woocommerce' ),
			'manage_woocommerce',
			'opf-lookup-csv',
			[ __CLASS__, 'render_admin_page' ]
		);
	}

	/** WooCommerce custom settings field linking to the dedicated multipart form. */
	public static function render_settings_link( array $setting ): void {
		$label = (string) ( $setting['title'] ?? __( 'Lookup table CSV', 'open-product-fields-for-woocommerce' ) );
		echo '<tr valign="top"><th scope="row" class="titledesc"><label>' . esc_html( $label ) . '</label></th><td class="forminp"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=opf-lookup-csv' ) ) . '">' . esc_html__( 'Upload lookup table CSV', 'open-product-fields-for-woocommerce' ) . '</a><p class="description">' . esc_html__( 'Import documented matrix or row-list CSV files. Uploading a table with the same name replaces its existing rows.', 'open-product-fields-for-woocommerce' ) . '</p></td></tr>';
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage lookup tables.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 403 ] );
		}
		$user_id = get_current_user_id();
		$key     = self::notice_key( $user_id );
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			echo '<div class="notice ' . ( 0 === strpos( $message, 'success:' ) ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( substr( $message, strpos( $message, ':' ) + 1 ) ) . '</p></div>';
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Import lookup table CSV', 'open-product-fields-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Upload a UTF-8 comma-separated file up to 5 MiB. Grid files use the first cell for the table name (or the filename when blank); row-list files use the filename. The final row-list column is the price.', 'open-product-fields-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="opf_import_lookup_csv">';
		wp_nonce_field( 'opf_import_lookup_csv', 'opf_lookup_csv_nonce' );
		echo '<input type="file" name="opf_lookup_csv" accept=".csv,text/csv" required> ';
		submit_button( __( 'Import CSV', 'open-product-fields-for-woocommerce' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	/** Handle the upload without persisting the uploaded file. */
	public static function handle_admin_upload(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage lookup tables.', 'open-product-fields-for-woocommerce' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'opf_import_lookup_csv', 'opf_lookup_csv_nonce' );
		$message = '';
		try {
			$file = $_FILES['opf_lookup_csv'] ?? null;
			if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['name'], $file['error'], $file['size'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
				throw new InvalidArgumentException( 'Choose a CSV file and retry the import.' );
			}
			if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
				throw new InvalidArgumentException( 'The uploaded file could not be verified.' );
			}
			if ( (int) $file['size'] > self::MAX_FILE_BYTES ) {
				throw new InvalidArgumentException( 'CSV exceeds the 5 MiB upload limit.' );
			}
			$tables = self::import_csv_file( (string) $file['tmp_name'], sanitize_file_name( (string) $file['name'] ) );
			$message = 'success:Imported ' . implode( ', ', array_keys( $tables ) ) . '. Same-name tables were replaced.';
		} catch ( InvalidArgumentException $error ) {
			$message = 'error:' . $error->getMessage();
		} catch ( \Throwable $error ) {
			$message = 'error:Lookup table import failed. Check the CSV and try again.';
		}
		set_transient( self::notice_key( get_current_user_id() ), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=opf-lookup-csv' ) );
		exit;
	}

	private static function notice_key( int $user_id ): string {
		return 'opf_lookup_csv_' . $user_id;
	}

	/** Import a validated CSV as a single atomic same-name replacement. */
	public static function import_csv_file( string $path, string $filename ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new InvalidArgumentException( 'Uploaded CSV could not be read.' );
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_FILE_BYTES ) {
			throw new InvalidArgumentException( 'CSV exceeds the 5 MiB upload limit.' );
		}
		$csv = file_get_contents( $path );
		if ( false === $csv ) {
			throw new InvalidArgumentException( 'Uploaded CSV could not be read.' );
		}
		$tables = self::parse_csv( $csv, $filename );
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			throw new InvalidArgumentException( 'WordPress options are unavailable.' );
		}
		$existing = FieldGroup::normalize_lookup_tables( get_option( self::OPTION, [] ) );
		$combined = array_replace( $existing, $tables );
		if ( count( $combined ) > 32 ) {
			throw new InvalidArgumentException( 'A maximum of 32 reusable lookup tables can be stored.' );
		}
		if ( ! update_option( self::OPTION, $combined, false ) && $combined !== get_option( self::OPTION, [] ) ) {
			throw new InvalidArgumentException( 'Unable to save lookup tables.' );
		}
		return $tables;
	}

	private static function filename_table_name( string $filename ): string {
		if ( basename( $filename ) !== $filename || ! preg_match( '/\.csv$/i', $filename ) ) {
			throw new InvalidArgumentException( 'Upload a CSV filename containing only letters, numbers, or underscores before .csv.' );
		}
		$name = substr( $filename, 0, -4 );
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $name ) ) {
			throw new InvalidArgumentException( 'CSV filename must use 1–64 letters, numbers, or underscores.' );
		}
		return $name;
	}

	private static function all_numeric( array $cells ): bool {
		foreach ( $cells as $cell ) {
			if ( '' === $cell || ! is_numeric( $cell ) || ! is_finite( (float) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/** A grid can use categorical axis values; only its price cells must be numeric. */
	private static function grid_price_cells_are_numeric( array $records, int $width ): bool {
		foreach ( array_slice( $records, 1 ) as $row ) {
			if ( count( $row ) !== $width || '' === $row[0] || ! self::all_numeric( array_slice( $row, 1 ) ) ) {
				return false;
			}
		}
		return true;
	}
}
