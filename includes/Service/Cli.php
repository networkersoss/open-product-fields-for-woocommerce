<?php
/**
 * WP-CLI commands.
 *
 *   wp opf import-wapf [--commit] [--format=json|table]
 *   wp opf report
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Register commands.
	 */
	public static function init(): void {
		\WP_CLI::add_command( 'opf', self::class );
		// WP-CLI names a class method subcommand exactly as the method is spelled,
		// so the dashed form documented for the file importer is registered on
		// purpose alongside `wp opf import_wapf_json`.
		\WP_CLI::add_command( 'opf import-wapf-json', [ self::class, 'import_wapf_json' ] );
	}

	/**
	 * Import WAPF field groups.
	 *
	 * ## OPTIONS
	 *
	 * [--commit]
	 * : Write the imported groups. Without this flag the import is a dry run.
	 *
	 * [--format=<format>]
	 * : Output format: table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opf import-wapf
	 *     wp opf import-wapf --commit --format=json
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import_wapf( array $args, array $assoc_args = [] ): void {
		$commit = isset( $assoc_args['commit'] );
		$format = $assoc_args['format'] ?? 'table';

		\WP_CLI::log( ( $commit ? 'Committing' : 'Dry-running' ) . ' WAPF → OPF import…' );

		$report = Importer::run( $commit );

		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			\WP_CLI::line( sprintf( 'Imported: %d', $report['imported'] ) );
			\WP_CLI::line( sprintf( 'Skipped:  %d', $report['skipped'] ) );
			\WP_CLI::line( sprintf( 'Repaired: %d', $report['repaired'] ) );
			\WP_CLI::line( sprintf( 'Needs review: %d', count( $report['needs_review'] ) ) );
			foreach ( $report['groups'] as $group ) {
				$flag = ! empty( $group['needs_review'] ) ? ' [REVIEW]' : '';
				\WP_CLI::line( sprintf( '  %s → %s (%s)%s', $group['source'] ?? '?', $group['result'] ?? '?', $group['title'] ?? '', $flag ) );
			}
		}

		\WP_CLI::success( sprintf( '%s %d group(s).', $commit ? 'Imported' : 'Would import', $report['imported'] ) );
	}

	/**
	 * Import a versioned OPF archive.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a JSON file created by `wp opf export`.
	 *
	 * [--commit]
	 * : Persist imported groups. Without this flag the command only reports the result.
	 *
	 * [--format=<format>]
	 * : Output format: table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opf import-archive /tmp/opf-groups.json
	 *     wp opf import-archive /tmp/opf-groups.json --commit --format=json
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import_archive( array $args, array $assoc_args = [] ): void {
		if ( 1 !== count( $args ) ) {
			\WP_CLI::error( 'Provide exactly one OPF archive JSON file.' );
		}
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, [ 'table', 'json' ], true ) ) {
			\WP_CLI::error( 'Archive import format must be table or json.' );
		}
		$path = realpath( (string) $args[0] );
		if ( false === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			\WP_CLI::error( 'OPF archive must be a readable regular file.' );
		}
		$size = filesize( $path );
		if ( false === $size || $size > ArchiveImporter::MAX_BYTES ) {
			\WP_CLI::error( 'OPF archive exceeds the 5 MiB import limit.' );
		}
		$json = file_get_contents( $path );
		if ( false === $json ) {
			\WP_CLI::error( 'Could not read the OPF archive.' );
		}
		try {
			$package = ArchiveImporter::decode( $json );
			$report = ArchiveImporter::import( $package, isset( $assoc_args['commit'] ) );
		} catch ( \InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			\WP_CLI::line( sprintf( 'Imported: %d', $report['imported'] ) );
			\WP_CLI::line( sprintf( 'Skipped: %d', $report['skipped'] ) );
			foreach ( $report['groups'] as $group ) {
				$review = ! empty( $group['needs_review'] ) ? ' [REVIEW → DRAFT]' : '';
				\WP_CLI::line( sprintf( '  #%d → %s%s', $group['source_id'] ?? 0, $group['result'] ?? '?', $review ) );
			}
		}
		\WP_CLI::success( sprintf( '%s %d group(s).', isset( $assoc_args['commit'] ) ? 'Imported' : 'Would import', $report['imported'] ) );
	}

	/**
	 * Import one JSON payload copied from WAPF Tools as a reviewable draft.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a WAPF Tools JSON payload (maximum 5 MiB).
	 *
	 * [--title=<title>]
	 * : Title for the new OPF draft. Defaults to the payload file name.
	 *
	 * [--commit]
	 * : Create the review draft. Without this flag the command only reports the mapping.
	 *
	 * [--product-id=<id>]
	 * : Attach the draft to one existing WooCommerce product, replacing imported placement.
	 *
	 * [--format=<format>]
	 * : Output format: table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opf import-wapf-json /tmp/wapf-fields.json
	 *     wp opf import-wapf-json /tmp/wapf-fields.json --commit
	 *     wp opf import-wapf-json /tmp/wapf-fields.json --title="Imported fields" --product-id=123 --commit
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import_wapf_json( array $args, array $assoc_args = [] ): void {
		if ( 1 !== count( $args ) ) {
			\WP_CLI::error( 'Provide exactly one WAPF Tools JSON file.' );
		}
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, [ 'table', 'json' ], true ) ) {
			\WP_CLI::error( 'WAPF JSON import format must be table or json.' );
		}
		$commit     = isset( $assoc_args['commit'] );
		$product_id = isset( $assoc_args['product-id'] ) ? self::positive_id( $assoc_args['product-id'] ) : 0;
		$title      = isset( $assoc_args['title'] ) ? (string) $assoc_args['title'] : self::payload_file_title( (string) $args[0] );
		try {
			$prepared = WapfJsonFileImporter::inspect_file( (string) $args[0] );
			$report   = WapfJsonFileImporter::run( $prepared, $title, $commit, $product_id );
		} catch ( \InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		$report['notes'] = is_array( $report['notes'] ?? null ) ? $report['notes'] : [];
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			\WP_CLI::line( sprintf( 'Result: %s', $report['result'] ) );
			\WP_CLI::line( sprintf( 'Title:  %s', $report['title'] ) );
			\WP_CLI::line( sprintf( 'Fields: %d', $report['fields'] ?? 0 ) );
			if ( $report['opf_id'] > 0 ) {
				\WP_CLI::line( sprintf( 'Group:  #%d', $report['opf_id'] ) );
			}
			if ( 'draft-created' === $report['result'] ) {
				\WP_CLI::line( 'Status: draft; review placement, title and options before publishing.' );
			} elseif ( 'dry-run' === $report['result'] ) {
				\WP_CLI::line( 'Status: dry run; no draft was written. Re-run with --commit to create the review draft.' );
			} else {
				\WP_CLI::line( 'Status: no changes written; inspect the result above.' );
			}
			foreach ( $report['notes'] as $note ) {
				\WP_CLI::line( '  Review: ' . $note );
			}
		}
		\WP_CLI::success( sprintf( '%s WAPF JSON payload as "%s".', $commit ? 'Imported' : 'Would import', $report['title'] ) );
	}

	/**
	 * Summarize OPF field groups.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function report( array $args, array $assoc_args = [] ): void {
		$groups = FieldGroups::all();
		\WP_CLI::line( sprintf( '%d OPF field groups:', count( $groups ) ) );
		foreach ( $groups as $entry ) {
			\WP_CLI::line( sprintf( '  #%d %s (%d fields)', $entry['id'], $entry['title'], count( $entry['group']->data['fields'] ) ) );
		}
	}

	/**
	 * Export field groups as an OPF archive, WAPF Tools JSON, or WXR transfer.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Export all non-trashed field groups, including drafts.
	 *
	 * [--group=<id>]
	 * : Export one field group by post ID.
	 *
	 * [--product=<id>]
	 * : Export the published groups currently matched to a WooCommerce product.
	 *
	 * [--output=<file>]
	 * : Write atomically to an absolute file path. Without this option, print the selected format to stdout.
	 *
	 * [--format=<format>]
	 * : Export format: opf (default), wapf-json, or wapf-wxr. WXR requires --all or --group.
	 *
	 * ## EXAMPLES
	 *
	 *     wp opf export --all --output=/tmp/opf-groups.json
	 *     wp opf export --group=123
	 *     wp opf export --product=456 --output=/tmp/opf-product.json
	 *     wp opf export --group=123 --format=wapf-json
	 *     wp opf export --all --format=wapf-wxr --output=/tmp/wapf-groups.xml
	 *     wp opf export --group=123 --format=wapf-wxr --output=/tmp/wapf-group.xml
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function export( array $args, array $assoc_args = [] ): void {
		$format = (string) ( $assoc_args['format'] ?? 'opf' );
		if ( ! in_array( $format, [ 'opf', 'wapf-json', 'wapf-wxr' ], true ) ) {
			\WP_CLI::error( 'Export format must be opf, wapf-json, or wapf-wxr.' );
		}
		$selectors = array_values( array_filter( [
			array_key_exists( 'all', $assoc_args ) ? 'all' : null,
			array_key_exists( 'group', $assoc_args ) ? 'group' : null,
			array_key_exists( 'product', $assoc_args ) ? 'product' : null,
		] ) );
		if ( 1 !== count( $selectors ) ) {
			\WP_CLI::error( 'Choose exactly one selector: --all, --group=<id>, or --product=<id>.' );
		}
		$type = $selectors[0];
		if ( 'wapf-wxr' === $format && ! in_array( $type, [ 'all', 'group' ], true ) ) {
			\WP_CLI::error( 'WAPF WXR export requires --all or --group because WXR transfers global field groups.' );
		}
		$scope = [ 'type' => $type ];
		$posts = [];
		if ( 'all' === $type ) {
			$posts = get_posts( [
				'post_type' => 'opf_field_group',
				'post_status' => 'any',
				'posts_per_page' => ArchiveImporter::MAX_GROUPS + 1,
				'no_found_rows' => true,
				'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC' ],
				'suppress_filters' => false,
				'lang' => '',
			] );
		} elseif ( 'group' === $type ) {
			$id = self::positive_id( $assoc_args['group'] ?? '' );
			$post = get_post( $id );
			if ( ! $post || 'opf_field_group' !== $post->post_type || 'trash' === $post->post_status ) {
				\WP_CLI::error( 'The requested OPF field group does not exist.' );
			}
			$scope['id'] = $id;
			$posts = [ $post ];
		} else {
			$id = self::positive_id( $assoc_args['product'] ?? '' );
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
			if ( ! $product ) {
				\WP_CLI::error( 'The requested WooCommerce product does not exist.' );
			}
			$scope['id'] = $id;
			foreach ( FieldGroups::for_product( $product ) as $entry ) {
				$post = get_post( (int) $entry['id'] );
				if ( $post && 'opf_field_group' === $post->post_type ) {
					$posts[] = $post;
				}
			}
		}
		if ( count( $posts ) > ArchiveImporter::MAX_GROUPS ) {
			\WP_CLI::error( 'OPF archive export is limited to 500 field groups.' );
		}

		$groups = [];
		$site_users = get_users( [ 'number' => 1 ] );
		$fallback_author = $site_users ? reset( $site_users ) : false;
		foreach ( $posts as $post ) {
			$data = json_decode( (string) $post->post_content, true );
			if ( ! is_array( $data ) ) {
				\WP_CLI::error( sprintf( 'Field group #%d has invalid JSON; export stopped.', (int) $post->ID ) );
			}
			$author = get_userdata( (int) $post->post_author );
			if ( ! $author ) {
				$author = $fallback_author;
			}
			$groups[] = [
				'id' => (int) $post->ID,
				'title' => (string) $post->post_title,
				'status' => (string) $post->post_status,
				'menu_order' => (int) $post->menu_order,
				'date' => (string) $post->post_date,
				'date_gmt' => (string) $post->post_date_gmt,
				'slug' => (string) $post->post_name,
				'author' => $author ? (string) $author->user_login : '',
				'language' => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( (int) $post->ID, 'slug' ) : '',
				'data' => $data,
			];
		}
		if ( 'wapf-wxr' === $format ) {
			try {
				$xml = WapfWxrExporter::build_document( $groups, [
					'site_url' => home_url(),
					'site_title' => get_bloginfo( 'name' ),
					'language' => get_bloginfo( 'language' ),
				] );
			} catch ( \InvalidArgumentException $exception ) {
				\WP_CLI::error( $exception->getMessage() );
			}
			\WP_CLI::warning( 'WAPF WXR does not preserve OPF product/category/tag placement, media files, language assignments, or OPF-only settings. Review imported groups before publishing.' );
			if ( isset( $assoc_args['output'] ) ) {
				$path = (string) $assoc_args['output'];
				$directory = dirname( $path );
				if ( '/' !== substr( $path, 0, 1 ) || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
					\WP_CLI::error( 'Export path must be absolute and its parent directory must be writable.' );
				}
				$temp = tempnam( $directory, '.opf-export-' );
				if ( false === $temp || false === file_put_contents( $temp, $xml, LOCK_EX ) || ! rename( $temp, $path ) ) {
					if ( is_string( $temp ) && file_exists( $temp ) ) {
						unlink( $temp );
					}
					\WP_CLI::error( 'Could not write the WAPF WXR export.' );
				}
				\WP_CLI::success( sprintf( 'Exported %d field group(s) as WAPF WXR to %s.', count( $groups ), $path ) );
				return;
			}
			\WP_CLI::line( $xml );
			return;
		}
		if ( 'wapf-json' === $format ) {
			if ( 1 !== count( $groups ) ) {
				\WP_CLI::error( 'WAPF Tools JSON export requires a selector that resolves to exactly one field group.' );
			}
			try {
				$payload = WapfExporter::build_payload( $groups[0]['data'] );
			} catch ( \InvalidArgumentException $exception ) {
				\WP_CLI::error( $exception->getMessage() );
			}
			$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) || strlen( $json ) > ArchiveImporter::MAX_BYTES ) {
				\WP_CLI::error( 'WAPF Tools JSON export could not be encoded or exceeds the 5 MiB transfer limit.' );
			}
			if ( isset( $assoc_args['output'] ) ) {
				$path = (string) $assoc_args['output'];
				$directory = dirname( $path );
				if ( '/' !== substr( $path, 0, 1 ) || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
					\WP_CLI::error( 'Export path must be absolute and its parent directory must be writable.' );
				}
				$temp = tempnam( $directory, '.opf-export-' );
				if ( false === $temp || false === file_put_contents( $temp, $json . "\n", LOCK_EX ) || ! rename( $temp, $path ) ) {
					if ( is_string( $temp ) && file_exists( $temp ) ) {
						unlink( $temp );
					}
					\WP_CLI::error( 'Could not write the WAPF Tools JSON export.' );
				}
				\WP_CLI::success( sprintf( 'Exported field group #%d as WAPF Tools JSON to %s.', $groups[0]['id'], $path ) );
				return;
			}
			\WP_CLI::line( $json );
			return;
		}
		$package = Exporter::build_package( $groups, $scope );
		$json = wp_json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			\WP_CLI::error( 'Could not encode the OPF export package.' );
		}
		if ( strlen( $json ) > ArchiveImporter::MAX_BYTES ) {
			\WP_CLI::error( 'OPF archive export exceeds the 5 MiB transfer limit.' );
		}
		try {
			ArchiveImporter::decode( $json );
		} catch ( \InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
		if ( isset( $assoc_args['output'] ) ) {
			$path = (string) $assoc_args['output'];
			$directory = dirname( $path );
			if ( '/' !== substr( $path, 0, 1 ) || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
				\WP_CLI::error( 'Export path must be absolute and its parent directory must be writable.' );
			}
			$temp = tempnam( $directory, '.opf-export-' );
			if ( false === $temp || false === file_put_contents( $temp, $json . "\n", LOCK_EX ) || ! rename( $temp, $path ) ) {
				if ( is_string( $temp ) && file_exists( $temp ) ) {
					unlink( $temp );
				}
				\WP_CLI::error( 'Could not write the OPF export package.' );
			}
			\WP_CLI::success( sprintf( 'Exported %d field group(s) to %s.', count( $groups ), $path ) );
			return;
		}
		\WP_CLI::line( $json );
	}

	private static function positive_id( $value ): int {
		if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]*$/', (string) $value ) ) {
			\WP_CLI::error( 'The selected ID must be a positive integer.' );
		}
		return (int) $value;
	}

	/**
	 * Draft title for a WAPF Tools payload file when --title is absent.
	 */
	private static function payload_file_title( string $path ): string {
		return (string) preg_replace( '/\.json$/i', '', basename( $path ) );
	}
}
