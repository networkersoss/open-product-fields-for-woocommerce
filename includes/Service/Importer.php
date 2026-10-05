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
use OPF\Engine\DateFormat;
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
		$report['date_format'] = self::migrate_date_format( $commit );
		$report['price_hints'] = self::migrate_price_hint_settings( $commit );

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
					// Dry-run imports have no opf_id yet — report the source
					// identifier so the review list stays meaningful.
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
					// Dry-run imports have no opf_id yet — report the source
					// identifier so the review list stays meaningful.
					$report['needs_review'][] = $result['opf_id'] ?: $result['source'];
				}
			} else {
				$report['skipped']++;
			}
		}

		return $report;
	}

	/** Copy WAPF's site option once, without replacing an explicit OPF preference. */
	private static function migrate_date_format( bool $commit ): array {
		$source = get_option( 'wapf_date_format', null );
		$existing = get_option( 'opf_date_format', null );
		if ( null !== $existing ) {
			return [ 'result' => 'already-configured', 'value' => DateFormat::normalize( $existing ) ];
		}
		if ( null === $source ) {
			return [ 'result' => 'source-absent' ];
		}
		if ( ! DateFormat::is_valid( $source ) ) {
			return [ 'result' => 'invalid-source', 'source' => $source ];
		}
		$value = DateFormat::normalize( $source );
		if ( ! $commit ) {
			return [ 'result' => 'would-import', 'value' => $value ];
		}
		return update_option( 'opf_date_format', $value )
			? [ 'result' => 'imported', 'value' => $value ]
			: [ 'result' => 'write-failed', 'value' => $value ];
	}

	/**
	 * Copy WAPF's pricing-hint options once, without replacing explicit OPF
	 * preferences (WAPF-DISPLAY-PRICE-HINTS): `wapf_show_pricing_hints` feeds
	 * `opf_show_price_hints`, `wapf_hint_format` feeds `opf_hint_format`.
	 */
	private static function migrate_price_hint_settings( bool $commit ): array {
		return [
			'show_hints' => self::migrate_hint_option( 'wapf_show_pricing_hints', 'opf_show_price_hints', $commit, static function ( $value ) {
				return in_array( $value, [ 'yes', 'no' ], true ) ? $value : null;
			} ),
			'format'     => self::migrate_hint_option( 'wapf_hint_format', 'opf_hint_format', $commit, static function ( $value ) {
				return is_string( $value ) && '' !== trim( $value ) ? $value : null;
			} ),
		];
	}

	/**
	 * Copy one scalar WAPF site option when the OPF preference is unset.
	 * Same lifecycle as migrate_date_format: already-configured wins,
	 * absent/invalid sources never write, dry runs report only.
	 *
	 * @param callable $sanitize Returns the importable value or null.
	 */
	private static function migrate_hint_option( string $source_key, string $option_key, bool $commit, callable $sanitize ): array {
		$existing = get_option( $option_key, null );
		if ( null !== $existing ) {
			return [ 'result' => 'already-configured', 'value' => $existing ];
		}
		$source = get_option( $source_key, null );
		if ( null === $source ) {
			return [ 'result' => 'source-absent' ];
		}
		$value = $sanitize( $source );
		if ( null === $value ) {
			return [ 'result' => 'invalid-source', 'source' => $source ];
		}
		if ( ! $commit ) {
			return [ 'result' => 'would-import', 'value' => $value ];
		}
		return update_option( $option_key, $value )
			? [ 'result' => 'imported', 'value' => $value ]
			: [ 'result' => 'write-failed', 'value' => $value ];
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
			// get_posts stubs/fixtures may hand back WP_Post objects even when
			// ids were requested; accept both shapes.
			$existing_id = is_object( $existing[0] ) ? (int) ( $existing[0]->ID ?? 0 ) : (int) $existing[0];
			return [ 'source' => $source_key, 'result' => 'already-imported', 'opf_id' => $existing_id ];
		}

		$empty_fields = empty( $wapf['fields'] );
		if ( $empty_fields ) {
			return [ 'source' => $source_key, 'result' => 'no-fields' ];
		}

		$mapped = WapfMapper::map( $wapf, $overrides );
		$status = $mapped['needs_review'] ? 'draft' : 'publish';

		if ( $commit ) {
			$meta = [ '_opf_imported_from' => $source_key ];
			// WAPF translates global CPTs and product-local fields independently.
			// Read the source element's language, never the importing admin's.
			$source_post_id = ctype_digit( $source_key ) ? (int) $source_key : 0;
			$source_type = 'wapf_product';
			if ( preg_match( '/^meta:([1-9][0-9]*)$/D', $source_key, $matches ) ) {
				$source_post_id = (int) $matches[1];
				$source_type = 'product';
			}
			$language = WpmlIntegration::source_language( $source_post_id, $source_type );
			if ( '' !== $language ) {
				$meta['_opf_wpml_source_language'] = $language;
			}
			$opf_id = FieldGroups::save( 0, new FieldGroup( $mapped['group'] ), [
				'title'      => $title,
				'status'     => $status,
				'menu_order' => $menu_order,
				'meta_input' => $meta,
			] );
			if ( ! $opf_id ) {
				return [ 'source' => $source_key, 'result' => 'save-failed' ];
			}

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
			'fields'       => count( $mapped['group']['fields'] ),
			'status'       => $status,
			'post_status'  => $status,
			'needs_review' => $mapped['needs_review'],
			'notes'        => array_slice( $mapped['notes'], 0, 10 ),
		];
	}
}
