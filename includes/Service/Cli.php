<?php
/**
 * WP-CLI commands.
 *
	 * wp opf import-wapf [--commit] [--format=json|table]
	 * wp opf export --all|--group=<id>|--product=<id> [--format=opf|wapf-json|wapf-wxr] [--output=<file>]
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
				$flag = ! empty( $group['needs_review'] )
					? sprintf( ' [REVIEW → %s]', strtoupper( $group['post_status'] ?? 'draft' ) )
					: '';
				\WP_CLI::line( sprintf( '  %s → %s (%s)%s', $group['source'] ?? '?', $group['result'] ?? '?', $group['title'] ?? '', $flag ) );
			}
		}

		\WP_CLI::success( sprintf( '%s %d group(s).', $commit ? 'Imported' : 'Would import', $report['imported'] ) );
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
	 * Export a portable OPF field-group package.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Export all non-trashed OPF groups, including drafts.
	 *
	 * [--group=<id>]
	 * : Export one OPF field-group post by ID.
	 *
	 * [--product=<id>]
	 * : Export the published groups currently matched to a WooCommerce product.
	 *
	 * [--output=<file>]
	 * : Write atomically to an absolute file path. Without this option, print the export to stdout.
	 *
	 * [--format=<format>]
	 * : Export format: opf (default), wapf-json (one WAPF-importable group), or wapf-wxr (all WAPF-importable groups).
	 *
	 * ## EXAMPLES
	 *
	 *     wp opf export --all --output=/tmp/opf-all.json
	 *     wp opf export --group=123
	 *     wp opf export --group=123 --format=wapf-json --output=/tmp/wapf-group.json
	 *     wp opf export --all --format=wapf-wxr --output=/tmp/wapf-groups.xml
	 *     wp opf export --product=456 --output=/tmp/opf-product.json
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function export( array $args, array $assoc_args = [] ): void {
		$selectors = array_values( array_filter( [
			array_key_exists( 'all', $assoc_args ) ? 'all' : null,
			array_key_exists( 'group', $assoc_args ) ? 'group' : null,
			array_key_exists( 'product', $assoc_args ) ? 'product' : null,
		] ) );
		if ( 1 !== count( $selectors ) ) {
			\WP_CLI::error( 'Choose exactly one selector: --all, --group=<id>, or --product=<id>.' );
		}

		$type = $selectors[0];
		$scope = [ 'type' => $type ];
		$posts = [];
		if ( 'all' === $type ) {
			$posts = get_posts( [
				'post_type' => 'opf_field_group',
				'post_status' => 'any',
				'posts_per_page' => -1,
				'no_found_rows' => true,
				'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC' ],
				'suppress_filters' => false,
				'lang' => '',
			] );
		} elseif ( 'group' === $type ) {
			$id = self::positive_id( $assoc_args['group'] ?? '' );
			$post = get_post( $id );
			if ( ! $post || 'opf_field_group' !== $post->post_type ) {
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

		$groups = [];
		foreach ( $posts as $post ) {
			$data = json_decode( (string) $post->post_content, true );
			if ( ! is_array( $data ) ) {
				\WP_CLI::error( sprintf( 'Field group #%d has invalid JSON; export stopped without writing a package.', (int) $post->ID ) );
			}
			$author = function_exists( 'get_the_author_meta' ) ? (string) get_the_author_meta( 'user_login', (int) $post->post_author ) : '';
			if ( '' === $author && function_exists( 'get_users' ) ) {
				$authors = get_users( [ 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => [ 'user_login' ] ] );
				$author = isset( $authors[0]->user_login ) ? (string) $authors[0]->user_login : '';
			}
			$groups[] = [
				'id' => (int) $post->ID,
				'title' => (string) $post->post_title,
				'status' => (string) $post->post_status,
				'menu_order' => (int) $post->menu_order,
				'date' => (string) $post->post_date,
				'date_gmt' => (string) $post->post_date_gmt,
				'slug' => (string) $post->post_name,
				'author' => $author,
				'lang' => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( (int) $post->ID, 'slug' ) : '',
				'data' => $data,
			];
		}

		$format = (string) ( $assoc_args['format'] ?? 'opf' );
		if ( ! in_array( $format, [ 'opf', 'wapf-json', 'wapf-wxr' ], true ) ) {
			\WP_CLI::error( 'Export format must be opf, wapf-json, or wapf-wxr.' );
		}
		if ( 'wapf-json' === $format ) {
			if ( 'all' === $type ) {
				\WP_CLI::error( 'WAPF JSON import accepts one field group. Use --group or a product with exactly one matched group.' );
			}
			if ( 1 !== count( $groups ) ) {
				\WP_CLI::error( sprintf( 'WAPF JSON export requires exactly one matched OPF group; found %d.', count( $groups ) ) );
			}
			try {
				$export = WapfExporter::build_payload( $groups[0]['data'], $groups[0]['title'] );
			} catch ( \InvalidArgumentException $exception ) {
				\WP_CLI::error( $exception->getMessage() );
			}
			\WP_CLI::warning( 'WAPF JSON transfers field data only; OPF group title, status, order, and language metadata are not included.' );
			$has_placement_ids = false;
			foreach ( (array) ( $groups[0]['data']['rule_groups'] ?? [] ) as $rule_group ) {
				foreach ( (array) ( $rule_group['rules'] ?? [] ) as $rule ) {
					if ( is_array( $rule ) && ! empty( $rule['terms'] ) ) {
						$has_placement_ids = true;
						break 2;
					}
				}
			}
			if ( $has_placement_ids ) {
				\WP_CLI::warning( 'WAPF placement product/category/tag IDs are site-local and may need remapping on the target site.' );
			}
			$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} elseif ( 'wapf-wxr' === $format ) {
			if ( 'all' !== $type ) {
				\WP_CLI::error( 'WAPF WXR export contains all global field groups. Use --all.' );
			}
			$has_placement_ids = false;
			foreach ( $groups as $group ) {
				foreach ( (array) ( $group['data']['rule_groups'] ?? [] ) as $rule_group ) {
					foreach ( (array) ( $rule_group['rules'] ?? [] ) as $rule ) {
						if ( is_array( $rule ) && ! empty( $rule['terms'] ) ) {
							$has_placement_ids = true;
							break 3;
						}
					}
				}
			}
			if ( $has_placement_ids ) {
				\WP_CLI::warning( 'WAPF placement product/category/tag IDs are site-local and may need remapping on the target site.' );
			}
			\WP_CLI::warning( 'WAPF WXR contains compatible global group records; image files, language assignments, and OPF-only metadata are not bundled.' );
			try {
				$json = WapfWxrExporter::build_document( $groups, [
					'site_url' => (string) get_bloginfo( 'url' ),
					'site_title' => (string) get_bloginfo( 'name' ),
					'language' => (string) get_bloginfo( 'language' ),
				] );
			} catch ( \InvalidArgumentException $exception ) {
				\WP_CLI::error( $exception->getMessage() );
			}
		} else {
			$package = Exporter::build_package( $groups, $scope );
			$json = wp_json_encode( $package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		if ( ! is_string( $json ) ) {
			\WP_CLI::error( 'Could not encode the export as JSON.' );
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
			\WP_CLI::success( sprintf( 'Exported %d field group(s) in %s format to %s.', count( $groups ), $format, $path ) );
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
}
