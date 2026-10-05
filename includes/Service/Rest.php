<?php
/**
 * REST API: opf/v1.
 *
 *  - GET  /opf/v1/groups          List groups (builder + tooling).
 *  - POST /opf/v1/groups          Create/update a group.
 *  - POST /opf/v1/preview         Render fields HTML for a group+product.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\FieldGroup;
use OPF\Engine\UploadService;
use OPF\Service\FieldGroups;

defined( 'ABSPATH' ) || exit;

final class Rest {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	/**
	 * Register routes.
	 */
	public static function routes(): void {
		register_rest_route(
			'opf/v1',
			'/price-preview',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'price_preview' ],
					'permission_callback' => static fn() => Renderer::visible_to_viewer(),
					'args'                => [
						'product_id' => [ 'type' => 'integer', 'required' => true ],
						'options'    => [ 'type' => 'number', 'required' => true ],
						'quantity'   => [ 'type' => 'integer', 'default' => 1 ],
					],
				],
			]
		);

		register_rest_route(
			'opf/v1',
			'/variation-fields',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'variation_fields' ],
					'permission_callback' => static fn() => Renderer::visible_to_viewer(),
					'args'                => [ 'variation_id' => [ 'type' => 'integer', 'required' => true ] ],
				],
			]
		);

		register_rest_route(
			'opf/v1',
			'/child-products',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'search_child_products' ],
					'permission_callback' => static fn() => current_user_can( 'manage_woocommerce' ),
					'args'                => [
						'source' => [ 'type' => 'string', 'default' => 'specific' ],
						'search' => [ 'type' => 'string', 'default' => '' ],
					],
				],
			]
		);

		register_rest_route(
			'opf/v1',
			'/groups',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'list_groups' ],
					'permission_callback' => static function () {
						return current_user_can( 'manage_woocommerce' );
					},
				],
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'save_group' ],
					'permission_callback' => static function () {
						return current_user_can( 'manage_woocommerce' );
					},
					'args'                => [
						'id'    => [ 'type' => 'integer', 'default' => 0 ],
						'title' => [ 'type' => 'string', 'required' => true ],
						'data'  => [ 'type' => 'object', 'required' => true ],
					],
				],
			]
		);

		register_rest_route(
			'opf/v1',
			'/preview',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'preview' ],
					'permission_callback' => static function () {
						return current_user_can( 'manage_woocommerce' );
					},
					'args'                => [
						'data'       => [ 'type' => 'object', 'required' => true ],
						'product_id' => [ 'type' => 'integer', 'default' => 0 ],
					],
				],
			]
		);

		register_rest_route(
			'opf/v1',
			'/uploads',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'stage_upload' ],
					'permission_callback' => [ __CLASS__, 'upload_request_allowed' ],
				],
			]
		);

		register_rest_route(
			'opf/v1',
			'/uploads/(?P<token>[a-f0-9]{48})',
			[
				[
					'methods'             => 'DELETE',
					'callback'            => [ __CLASS__, 'delete_staged_upload' ],
					'permission_callback' => [ __CLASS__, 'upload_request_allowed' ],
				],
			]
		);

		register_rest_route(
			'opf/v1',
			'/uploads/(?P<token>[a-f0-9]{48})',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'download_upload' ],
					'permission_callback' => static fn( \WP_REST_Request $request ) => UploadService::viewer_can_access( (string) $request->get_param( 'token' ), get_current_user_id(), (string) $request->get_param( 'key' ) ),
					'args'                => [
						'token' => [ 'type' => 'string', 'required' => true ],
						'key'   => [ 'type' => 'string', 'default' => '' ],
					],
				],
			]
		);
	}

	/** Return WooCommerce's shop-context tax display for the live option total. */
	public static function price_preview( \WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		if ( ! $product instanceof \WC_Product || 'publish' !== get_post_status( $product_id ) || ! is_numeric( $product->get_price() ) ) {
			return new \WP_Error( 'opf_price_product', __( 'The product price could not be verified.', 'open-product-fields-for-woocommerce' ), [ 'status' => 404 ] );
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( (int) $product->get_parent_id() );
			if ( ! $parent || ! $parent->is_type( 'variable' ) || 'publish' !== get_post_status( (int) $parent->get_id() ) ) {
				return new \WP_Error( 'opf_price_product', __( 'The product price could not be verified.', 'open-product-fields-for-woocommerce' ), [ 'status' => 404 ] );
			}
		}

		$options = $request->get_param( 'options' );
		$quantity = absint( $request->get_param( 'quantity' ) );
		if ( ! is_numeric( $options ) || ! is_finite( (float) $options ) || abs( (float) $options ) > 1000000 || $quantity < 1 || $quantity > 10000 ) {
			return new \WP_Error( 'opf_price_input', __( 'The product price preview request is invalid.', 'open-product-fields-for-woocommerce' ), [ 'status' => 400 ] );
		}

		$base  = max( 0.0, (float) $product->get_price() );
		$final = max( 0.0, $base + (float) $options );
		$base_display = wc_get_price_to_display(
			$product,
			[ 'price' => $base, 'qty' => $quantity, 'display_context' => 'shop' ]
		);
		$final_display = wc_get_price_to_display(
			$product,
			[ 'price' => $final, 'qty' => $quantity, 'display_context' => 'shop' ]
		);
		return [
			'product_total' => (float) $base_display,
			'options_total' => (float) $final_display - (float) $base_display,
			'grand_total'   => (float) $final_display,
		];
	}

	/** Return rendered fields for a published variation belonging to its supplied variable parent. */
	public static function variation_fields( \WP_REST_Request $request ) {
		$variation_id = absint( $request->get_param( 'variation_id' ) );
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) || 'publish' !== get_post_status( $variation_id ) ) {
			return new \WP_Error( 'opf_variation_invalid', __( 'The selected variation is unavailable.', 'open-product-fields-for-woocommerce' ), [ 'status' => 404 ] );
		}
		$parent = wc_get_product( (int) $variation->get_parent_id() );
		if ( ! $parent || ! $parent->is_type( 'variable' ) || 'publish' !== get_post_status( (int) $parent->get_id() ) ) {
			return new \WP_Error( 'opf_variation_parent_invalid', __( 'The selected product is unavailable.', 'open-product-fields-for-woocommerce' ), [ 'status' => 404 ] );
		}
		return Renderer::variation_payload( $variation );
	}

	/** Search published simple products or product categories for the builder. */
	public static function search_child_products( \WP_REST_Request $request ): array {
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$source = 'categories' === $request->get_param( 'source' ) ? 'categories' : 'specific';
		if ( 'categories' === $source ) {
			$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 20, 'search' => $search ] );
			if ( is_wp_error( $terms ) ) {
				return [];
			}
			return array_map( static fn( $term ): array => [ 'id' => (int) $term->term_id, 'name' => (string) $term->name ], $terms );
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}
		$products = wc_get_products( [ 'status' => 'publish', 'type' => [ 'simple' ], 's' => $search, 'limit' => 20, 'orderby' => 'title', 'order' => 'ASC', 'return' => 'objects' ] );
		return array_map( static fn( $product ): array => [ 'id' => (int) $product->get_id(), 'name' => (string) $product->get_name() ], array_filter( (array) $products, static fn( $product ): bool => $product instanceof \WC_Product ) );
	}

	/** Require a REST nonce and an initialized WooCommerce guest/customer session. */
	public static function upload_request_allowed( \WP_REST_Request $request ): bool {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) || ! function_exists( 'WC' ) || ! WC() ) {
			return false;
		}
		$woocommerce = WC();
		if ( ! isset( $woocommerce->session ) && method_exists( $woocommerce, 'initialize_session' ) ) {
			$woocommerce->initialize_session();
		}
		if ( ! isset( $woocommerce->session ) ) {
			return false;
		}
		// Guest REST requests do not necessarily initialize a session before
		// the first Ajax upload. Set WooCommerce's session cookie now so the
		// staged ticket is bound to the same session on subsequent requests.
		if ( method_exists( $woocommerce->session, 'set_customer_session_cookie' ) ) {
			$woocommerce->session->set_customer_session_cookie( true );
		}
		return true;
	}

	/** Validate product placement and securely stage one Ajax upload. */
	public static function stage_upload( \WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$group_id   = sanitize_text_field( (string) $request->get_param( 'group_id' ) );
		$field_id   = sanitize_key( (string) $request->get_param( 'field_id' ) );
		$product    = wc_get_product( $product_id );
		if ( ! $product || '' === $group_id || '' === $field_id ) {
			return new \WP_Error( 'opf_upload_context', __( 'The upload field could not be verified.', 'open-product-fields-for-woocommerce' ), [ 'status' => 400 ] );
		}

		$field = null;
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			if ( (string) $entry['id'] !== $group_id ) {
				continue;
			}
			foreach ( $entry['group']->data['fields'] as $candidate ) {
				if ( (string) $candidate['id'] === $field_id && 'upload' === $candidate['type'] ) {
					$field = $candidate;
					break 2;
				}
			}
		}
		if ( null === $field ) {
			return new \WP_Error( 'opf_upload_field', __( 'The upload field is unavailable for this product.', 'open-product-fields-for-woocommerce' ), [ 'status' => 400 ] );
		}
		$replace_token = sanitize_text_field( (string) $request->get_param( 'replace_token' ) );
		$replaced_file = '' !== $replace_token ? UploadService::staged_file( $replace_token, $product_id, $group_id, $field_id ) : null;
		if ( '' !== $replace_token && null === $replaced_file ) {
			return new \WP_Error( 'opf_upload_replace', __( 'The image being replaced is no longer available.', 'open-product-fields-for-woocommerce' ), [ 'status' => 409 ] );
		}
		$max_files = (int) ( $field['max_files'] ?? 1 );
		if ( -1 !== $max_files && UploadService::staged_count( $product_id, $group_id, $field_id ) >= $max_files && null === $replaced_file ) {
			return new \WP_Error( 'opf_upload_limit', sprintf( __( '"%s" allows at most %d file(s).', 'open-product-fields-for-woocommerce' ), (string) $field['label'], max( 1, (int) ( $field['max_files'] ?? 1 ) ) ), [ 'status' => 400 ] );
		}

		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : [];
		$normalized = UploadService::normalize_files( $file );
		$errors = UploadService::validate( $normalized, $field );
		if ( $errors || 1 !== UploadService::attempts( $normalized ) ) {
			return new \WP_Error( 'opf_upload_invalid', $errors ? implode( ' ', $errors ) : __( 'Choose one file to upload.', 'open-product-fields-for-woocommerce' ), [ 'status' => 400 ] );
		}

		$stored = UploadService::store( $normalized[0], $field );
		if ( null === $stored ) {
			return new \WP_Error( 'opf_upload_store', __( 'The file could not be securely stored.', 'open-product-fields-for-woocommerce' ), [ 'status' => 400 ] );
		}
		if ( ! UploadService::register_staged( $stored, $product_id, $group_id, $field_id ) ) {
			UploadService::delete( $stored['token'] );
			return new \WP_Error( 'opf_upload_session', __( 'The upload session is unavailable. Refresh the page and try again.', 'open-product-fields-for-woocommerce' ), [ 'status' => 503 ] );
		}
		if ( null !== $replaced_file && ! UploadService::delete_staged( $replace_token ) ) {
			UploadService::delete_staged( $stored['token'] );
			return new \WP_Error( 'opf_upload_replace', __( 'The original image changed while this edit was being saved. Try again.', 'open-product-fields-for-woocommerce' ), [ 'status' => 409 ] );
		}
		return rest_ensure_response( [ 'token' => $stored['token'], 'name' => $stored['name'], 'size' => $stored['size'], 'mime' => $stored['mime'] ] );
	}

	/** Delete only a staged upload owned by the current WooCommerce session. */
	public static function delete_staged_upload( \WP_REST_Request $request ) {
		if ( ! UploadService::delete_staged( (string) $request->get_param( 'token' ) ) ) {
			return new \WP_Error( 'opf_upload_missing', __( 'This staged upload is no longer available.', 'open-product-fields-for-woocommerce' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * Stream one private upload to an authorized viewer. Never expose the
	 * storage path; the Content-Disposition name is the original filename
	 * recovered from the owning order when available.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function download_upload( \WP_REST_Request $request ): void {
		$token = (string) $request->get_param( 'token' );
		$path  = UploadService::path( $token );
		if ( null === $path ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'File not found.';
			exit;
		}

		$name = self::upload_display_name( $token );
		if ( '' === $name ) {
			$ext  = pathinfo( $path, PATHINFO_EXTENSION );
			$name = $token . ( '' !== $ext ? '.' . $ext : '' );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Recover the customer-visible filename for a token from order meta.
	 *
	 * @param string $token Stored token.
	 */
	private static function upload_display_name( string $token ): string {
		if ( ! preg_match( UploadService::TOKEN_PATTERN, $token ) || ! function_exists( 'wc_get_orders' ) ) {
			return '';
		}
		$orders = wc_get_orders(
			[
				'limit'      => 5,
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_opf_upload_tokens',
						'value'   => $token,
						'compare' => 'LIKE',
					],
				],
			]
		);
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$stored = $item->get_meta( '_opf_files', true );
				if ( is_string( $stored ) && '' !== $stored ) {
					$stored = json_decode( $stored, true );
				}
				if ( ! is_array( $stored ) ) {
					continue;
				}
				foreach ( $stored as $group_files ) {
					foreach ( (array) $group_files as $entries ) {
						foreach ( (array) $entries as $file ) {
							if ( is_array( $file ) && isset( $file['token'], $file['name'] ) && hash_equals( (string) $file['token'], $token ) ) {
								return sanitize_file_name( (string) $file['name'] );
							}
						}
					}
				}
			}
		}
		return '';
	}

	/**
	 * List groups.
	 */
	public static function list_groups(): \WP_REST_Response {
		$out = [];
		foreach ( FieldGroups::all() as $entry ) {
			$out[] = [
				'id'    => $entry['id'],
				'title' => $entry['title'],
				'data'  => $entry['group']->data,
			];
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Create or update a group.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_group( \WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$data  = (array) $request->get_param( 'data' );

		try {
			$group = new FieldGroup( $data );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'opf_invalid_group', $e->getMessage(), [ 'status' => 400 ] );
		}

		$saved = FieldGroups::save( $id, $group, [ 'title' => $title ] );
		if ( ! $saved ) {
			return new \WP_Error( 'opf_save_failed', __( 'Could not save the field group.', 'open-product-fields-for-woocommerce' ), [ 'status' => 500 ] );
		}

		return rest_ensure_response(
			[
				'id'   => $saved,
				'title' => $title,
				'data' => FieldGroups::group_from_post( get_post( $saved ) )->data,
			]
		);
	}

	/**
	 * Server-render fields HTML for a candidate group + product. Used by the
	 * builder's live preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview( \WP_REST_Request $request ) {
		$data       = (array) $request->get_param( 'data' );
		$product_id = (int) $request->get_param( 'product_id' );
		$product    = $product_id ? wc_get_product( $product_id ) : wc_get_product( wc_get_products( [ 'limit' => 1, 'return' => 'ids', 'status' => 'publish' ] )[0] ?? 0 );

		if ( ! $product ) {
			return new \WP_Error( 'opf_no_product', __( 'Create a product first to see a preview.', 'open-product-fields-for-woocommerce' ), [ 'status' => 404 ] );
		}

		try {
			$group = new FieldGroup( $data );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'opf_invalid_group', $e->getMessage(), [ 'status' => 400 ] );
		}

		ob_start();
		Renderer::render_group(
			0,
			(string) ( $data['title'] ?? '' ),
			$group,
			(float) $product->get_price( 'edit' )
		);
		$html = ob_get_clean();

		return rest_ensure_response( [ 'html' => $html ] );
	}
}
