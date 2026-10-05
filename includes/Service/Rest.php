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
