<?php
/**
 * Migration service: converts legacy WAPF field groups to OPF groups.
 *
 * Sources read (site's own data, read-only):
 *  - Global groups: post_content of `wapf_product` posts (PHP-serialized payloads).
 *  - Local groups:  `_wapf_fieldgroup` meta on products (often charset-corrupted;
 *    the engine's recovering parser resurrects what unserialize() cannot).
 *
 * Clean-room note: this reads the site's stored data format only. No WAPF code
 * is executed or copied.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\FieldGroup;
use OPF\Engine\WapfMapper;
use OPF\Engine\WapfParser;

defined( 'ABSPATH' ) || exit;

final class Importer {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ], 60 );
		add_action( 'wp_ajax_opf_import_wapf', [ __CLASS__, 'ajax_import' ] );
	}

	/**
	 * Admin page under Tools.
	 */
	public static function register_page(): void {
		add_management_page(
			__( 'Import WAPF Fields', 'open-product-fields-for-woocommerce' ),
			__( 'Import WAPF Fields', 'open-product-fields-for-woocommerce' ),
			'manage_woocommerce',
			'opf-import',
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Render the import screen.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to run imports.', 'open-product-fields-for-woocommerce' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import WAPF field groups', 'open-product-fields-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Converts Advanced Product Fields (WAPF) groups into Open Product Fields groups. Placement, choices and pricing are mapped automatically; anything that needs a human decision is flagged for review.', 'open-product-fields-for-woocommerce' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="opf-import-run"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'opf_import' ) ); ?>">
					<?php esc_html_e( 'Run import (dry run)', 'open-product-fields-for-woocommerce' ); ?>
				</button>
				<label style="margin-left:12px;">
					<input type="checkbox" id="opf-import-commit" value="1" />
					<?php esc_html_e( 'Write imported groups (uncheck = dry run)', 'open-product-fields-for-woocommerce' ); ?>
				</label>
			</p>
			<pre id="opf-import-output" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:480px;overflow:auto;"></pre>
		</div>
		<script>
			document.getElementById('opf-import-run').addEventListener('click', function () {
				var btn = this, out = document.getElementById('opf-import-output');
				btn.disabled = true;
				out.textContent = 'Running…';
				var body = new FormData();
				body.append('action', 'opf_import_wapf');
				body.append('nonce', btn.dataset.nonce);
				body.append('commit', document.getElementById('opf-import-commit').checked ? '1' : '0');
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						out.textContent = j.data ? JSON.stringify(j.data, null, 2) : JSON.stringify(j, null, 2);
						btn.disabled = false;
					})
					.catch(function (e) { out.textContent = 'Failed: ' + e; btn.disabled = false; });
			});
		</script>
		<?php
	}

	/**
	 * AJAX entry point.
	 */
	public static function ajax_import(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'opf_import', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		$commit = isset( $_POST['commit'] ) && '1' === $_POST['commit'];
		wp_send_json_success( self::run( $commit ) );
	}

	/**
	 * Run the import.
	 *
	 * @param bool $commit false = dry run (no writes).
	 * @return array<string,mixed> Report.
	 */
	public static function run( bool $commit = false ): array {
		global $wpdb;

		$report = [
			'mode'        => $commit ? 'commit' : 'dry-run',
			'imported'    => 0,
			'skipped'     => 0,
			'repaired'    => 0,
			'needs_review' => [],
			'groups'      => [],
		];

		$post_status = [ 'publish' ];

		// 1. Global groups: published wapf_product posts with serialized content.
		// Ordered date-DESC — the same sequence the legacy plugin rendered in.
		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_status, post_content FROM {$wpdb->posts}
			 WHERE post_type = 'wapf_product' AND post_status = 'publish' AND post_content <> ''
			 ORDER BY post_date DESC, ID DESC"
		);

		$seq = 0;
		foreach ( $rows as $row ) {
			$strict = @unserialize( $row->post_content, [ 'allowed_classes' => false ] );
			$wapf   = is_array( $strict ) ? $strict : WapfParser::parse( $row->post_content );
			$repaired = ! is_array( $strict ) && is_array( $wapf );
			if ( $repaired ) {
				$report['repaired']++;
			}
			if ( ! is_array( $wapf ) ) {
				$report['skipped']++;
				$report['groups'][] = [ 'source' => $row->ID, 'title' => $row->post_title, 'result' => 'unparseable' ];
				continue;
			}

			$result = self::import_group( $wapf, $row->post_title, $row->ID, $commit, [], $seq );
			$seq++;
			$report['groups'][] = $result;
			if ( 'imported' === $result['result'] ) {
				$report['imported']++;
				if ( $result['needs_review'] ) {
					$report['needs_review'][] = $result['opf_id'] ?: $result['source'];
				}
			} else {
				$report['skipped']++;
			}
		}

		// 2. Local groups: product meta (host product becomes the placement rule).
		// Values are read through the metadata API — raw SQL reads can differ
		// under object-cache/storage transformations.
		$meta_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wapf_fieldgroup' AND meta_value <> ''"
		);
		foreach ( $meta_ids as $post_id ) {
			$product = get_post( (int) $post_id );
			if ( ! $product || 'product' !== $product->post_type ) {
				continue;
			}
			// get_post_meta already unserializes clean payloads into arrays;
			// corrupted ones stay strings and go through the repair parser.
			$meta_value = get_post_meta( (int) $post_id, '_wapf_fieldgroup', true );
			// WordPress may return false when a serialized value has damaged
			// string lengths. Recover from storage before treating it as absent.
			if ( false === $meta_value || '' === $meta_value ) {
				$meta_value = $wpdb->get_var( $wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
					(int) $post_id,
					'_wapf_fieldgroup'
				) );
			}
			if ( is_array( $meta_value ) ) {
				$wapf   = $meta_value;
				$strict = $wapf;
			} elseif ( is_string( $meta_value ) && '' !== $meta_value ) {
				$strict = @unserialize( $meta_value, [ 'allowed_classes' => false ] );
				$wapf   = is_array( $strict ) ? $strict : WapfParser::parse( $meta_value );
			} else {
				continue;
			}
			$repaired = ! is_array( $strict ) && is_array( $wapf );
			if ( $repaired ) {
				$report['repaired']++;
			}
			if ( ! is_array( $wapf ) || empty( $wapf['fields'] ) ) {
				$report['skipped']++;
				$report['groups'][] = [ 'source' => 'meta:' . $post_id, 'result' => 'unparseable-or-empty' ];
				continue;
			}

			$result = self::import_group(
				$wapf,
				$product->post_title . ' — ' . ( $wapf['fields'][0]['label'] ?? __( 'Fields', 'open-product-fields-for-woocommerce' ) ),
				'meta:' . $post_id,
				$commit,
				[ 'attach_product_ids' => [ (int) $post_id ] ]
			);
			$report['groups'][] = $result;
			if ( 'imported' === $result['result'] ) {
				$report['imported']++;
				if ( $result['needs_review'] ) {
					$report['needs_review'][] = $result['opf_id'] ?: $result['source'];
				}
			} else {
				$report['skipped']++;
			}
		}

		return $report;
	}

	/**
	 * Import one mapped group.
	 *
	 * @param array<string,mixed> $wapf      Parsed WAPF payload.
	 * @param string              $title     Group title.
	 * @param string|int          $source_id Source identifier.
	 * @param bool                $commit    Write?
	 * @param array<string,mixed> $overrides Mapper overrides.
	 * @return array<string,mixed>
	 */
	private static function import_group( array $wapf, string $title, $source_id, bool $commit, array $overrides = [], int $menu_order = 0 ): array {
		$source_key = (string) $source_id;

		// Idempotency: skip if this source was already imported.
		$existing = get_posts(
			[
				'post_type'      => 'opf_field_group',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_opf_imported_from', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $source_key, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		if ( ! empty( $existing ) ) {
			return [ 'source' => $source_key, 'result' => 'already-imported', 'opf_id' => (int) $existing[0] ];
		}

		$empty_fields = empty( $wapf['fields'] );
		if ( $empty_fields ) {
			return [ 'source' => $source_key, 'result' => 'no-fields' ];
		}

		$mapped = WapfMapper::map( $wapf, $overrides );
		$post_status = $mapped['needs_review'] ? 'draft' : 'publish';

		if ( $commit ) {
			$opf_id = FieldGroups::save(
				0,
				new FieldGroup( $mapped['group'] ),
				[ 'title' => $title, 'status' => $post_status, 'menu_order' => $menu_order ]
			);
			if ( ! $opf_id ) {
				return [ 'source' => $source_key, 'result' => 'save-failed' ];
			}
			update_post_meta( $opf_id, '_opf_imported_from', $source_key );

			// Preserve the source group's Polylang language so locale
			// targeting keeps working after migration.
			$src_pid = is_numeric( $source_key ) ? (int) $source_key : 0;
			if ( $src_pid > 0 && function_exists( 'pll_get_post_language' ) && function_exists( 'pll_set_post_language' ) ) {
				$lang = pll_get_post_language( $src_pid, 'slug' );
				if ( $lang ) {
					pll_set_post_language( $opf_id, $lang );
				}
			}
			if ( $mapped['needs_review'] ) {
				update_post_meta( $opf_id, '_opf_needs_review', array_slice( $mapped['notes'], 0, 20 ) );
			}
		} else {
			$opf_id = 0;
		}

		return [
			'source'       => $source_key,
			'result'       => 'imported',
			'opf_id'       => $opf_id,
			'title'        => $title,
			'post_status'  => $post_status,
			'fields'       => count( $mapped['group']['fields'] ),
			'needs_review' => $mapped['needs_review'],
			'notes'        => array_slice( $mapped['notes'], 0, 10 ),
		];
	}
}
