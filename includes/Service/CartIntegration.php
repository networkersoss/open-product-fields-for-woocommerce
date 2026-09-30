<?php
/**
 * Cart & order integration.
 *
 * Capture happens through `woocommerce_add_cart_item_data` (classic form POST)
 * and `woocommerce_store_api_add_to_cart_data` (block product pages). Display
 * uses `woocommerce_get_item_data`, which WooCommerce's own CartItemSchema
 * reuses for the block cart and checkout — one filter, both worlds.
 * Pricing is applied server-side only, per unit, in
 * `woocommerce_before_calculate_totals`.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

use OPF\Engine\Calculator;
use OPF\Engine\ChildProductSelection;
use OPF\Engine\DateFormat;
use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use OPF\Engine\RepeaterField;
use OPF\Engine\UploadService;

defined( 'ABSPATH' ) || exit;

final class CartIntegration {

	/**
	 * Cart item key holding our structured data.
	 */
	public const ITEM_KEY = 'opf_fields';

	/**
	 * Cart item key holding private upload references.
	 */
	public const FILES_KEY = 'opf_files';

	/**
	 * Hidden order-item meta: full upload references (JSON).
	 */
	public const FILE_META = '_opf_files';

	/**
	 * Hidden order-item meta: flat token list for authorization lookups.
	 */
	public const FILE_TOKENS_META = '_opf_upload_tokens';

	/**
	 * Uploads staged during validation, waiting for the cart item to attach.
	 *
	 * @var array<string,array<string,array<int,array<string,mixed>>>>|null
	 */
	private static $pending_uploads = null;

	/**
	 * Product the staged uploads belong to.
	 *
	 * @var int
	 */
	private static $pending_uploads_product = 0;

	/**
	 * Signature of the staged submission, so a repeated validation call for
	 * the same files (WooCommerce validates again inside add_to_cart) does
	 * not store them twice.
	 *
	 * @var string
	 */
	private static $pending_uploads_signature = '';

	/** Guard against recursively adding child products as new parents. */
	private static $adding_child_lines = false;

	/** Old order cart keys mapped to their newly restored parent cart keys. */
	private static $order_again_parent_keys = [];

	/** Store API cart/checkout surface while Woo builds a response. */
	private static $store_api_display_surface = null;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add_to_cart' ], 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'attach' ], 10, 4 );
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'consume_cart_upload_tickets' ], 20, 6 );
		add_filter( 'woocommerce_cart_item_permalink', [ __CLASS__, 'cart_item_edit_permalink' ], 20, 3 );
		add_filter( 'woocommerce_quantity_input_args', [ __CLASS__, 'prefill_edit_quantity' ], 20, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', [ __CLASS__, 'edit_add_to_cart_text' ], 20, 2 );
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'complete_cart_edit' ], 5, 6 );
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'add_child_product_lines' ], 30, 6 );
		add_action( 'woocommerce_cart_item_removed', [ __CLASS__, 'remove_child_product_lines' ], 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', [ __CLASS__, 'sync_child_product_quantities' ], 10, 4 );
		add_filter( 'woocommerce_get_cart_item_from_session', [ __CLASS__, 'restore_from_session' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'apply_prices' ], 20, 1 );
		// Woo's cart percent discount path passes the cart item (including our
		// captured base price) through this filter before line rounding/tax.
		add_filter( 'woocommerce_coupon_get_discount_amount', [ __CLASS__, 'filter_coupon_discount_amount' ], 10, 5 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_item_data' ], 10, 2 );
		add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'set_store_api_display_surface' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'clear_store_api_display_surface' ], 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'persist_order_item' ], 10, 4 );
		add_filter( 'woocommerce_order_again_cart_item_data', [ __CLASS__, 'restore_order_again' ], 10, 3 );
		// Hide internal OPF meta from admin/customer order item display.
		add_filter( 'woocommerce_hidden_order_itemmeta', [ __CLASS__, 'hidden_order_meta' ] );
		add_filter( 'woocommerce_store_api_add_to_cart_data', [ __CLASS__, 'capture_store_api' ], 10, 2 );
		// Link stored filenames to the authorized download endpoint.
		add_filter( 'woocommerce_order_item_display_meta_value', [ __CLASS__, 'linkify_file_meta' ], 20, 3 );
		// Remove private files when their order is permanently deleted.
		add_action( 'woocommerce_before_delete_order', [ __CLASS__, 'delete_order_uploads' ], 10, 1 );
	}

	/**
	 * Capture values posted by the block/Store API add-to-cart request and
	 * hand them to the cart controller via cart_item_data.
	 *
	 * @param array            $add_to_cart_data Data heading to CartController::add_to_cart.
	 * @param \WP_REST_Request $request          Store API request.
	 */
	public static function capture_store_api( array $add_to_cart_data, \WP_REST_Request $request ): array {
		$submitted = $request->get_param( 'opf_fields' );
		$upload_tokens = $request->get_param( 'opf_upload_tokens' );
		// The Store API can strip unregistered params individually. Recover the
		// raw JSON body whenever either custom payload is missing.
		$parsed = $request->get_json_params();
		if ( ! is_array( $parsed ) ) {
			$body = $request->get_body();
			$parsed = is_string( $body ) && '' !== $body ? json_decode( $body, true ) : null;
		}
		if ( ! is_array( $submitted ) && is_array( $parsed ) ) {
			$submitted = $parsed['opf_fields'] ?? null;
		}
		if ( ! is_array( $upload_tokens ) && is_array( $parsed ) ) {
			$upload_tokens = $parsed['opf_upload_tokens'] ?? null;
		}
		if ( is_array( $upload_tokens ) ) {
			self::$store_api_upload_tokens = $upload_tokens;
			$add_to_cart_data['cart_item_data']['opf_upload_tokens_raw'] = $upload_tokens;
		}
		if ( is_array( $submitted ) ) {
			// Validation runs after attach on the Store API path, so both need
			// the payload; neither consumes it. Cleared on shutdown.
			self::$store_api_raw = $submitted;
			$add_to_cart_data['cart_item_data']['opf_fields_raw'] = $submitted;
			add_action(
				'shutdown',
				static function () {
					CartIntegration::$store_api_raw = null;
					CartIntegration::$store_api_upload_tokens = null;
				}
			);
		}
		return $add_to_cart_data;
	}

	/** Add an edit URL to eligible OPF lines in both classic and Store API carts. */
	public static function cart_item_edit_permalink( string $permalink, array $cart_item, string $cart_item_key ): string {
		if ( 'yes' !== get_option( 'opf_edit_cart', 'no' ) || ! self::cart_item_is_editable( $cart_item, $cart_item_key ) ) {
			return $permalink;
		}
		$url = add_query_arg( 'opf_edit_cart_item', rawurlencode( $cart_item_key ), $permalink );
		// wp_nonce_url() HTML-escapes the query separators, which corrupts
		// the JSON permalink returned by WooCommerce's Store API.
		return add_query_arg( '_wpnonce', wp_create_nonce( 'opf_edit_cart_' . $cart_item_key ), $url );
	}

	/** Return validated cart data when the current product request edits a cart line. */
	public static function requested_cart_edit_item( \WC_Product $product ): ?array {
		if ( 'yes' !== get_option( 'opf_edit_cart', 'no' ) || ! isset( $_GET['opf_edit_cart_item'], $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}
		$key = sanitize_text_field( wp_unslash( (string) $_GET['opf_edit_cart_item'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$item = self::validated_cart_edit_item( $key, $nonce, (int) $product->get_id(), 0 );
		return $item;
	}

	/** Validate the posted edit token against this Woo session and product. */
	private static function posted_cart_edit_key( int $product_id, int $variation_id ): string {
		if ( ! isset( $_POST['opf_edit_cart_item'], $_POST['opf_edit_cart_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}
		$key = sanitize_text_field( wp_unslash( (string) $_POST['opf_edit_cart_item'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['opf_edit_cart_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return self::validated_cart_edit_item( $key, $nonce, $product_id, $variation_id ) ? $key : '';
	}

	private static function validated_cart_edit_item( string $key, string $nonce, int $product_id, int $variation_id ): ?array {
		if ( '' === $key || ! wp_verify_nonce( $nonce, 'opf_edit_cart_' . $key ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		$item = WC()->cart->get_cart_item( $key );
		if ( ! is_array( $item ) || (int) ( $item['product_id'] ?? 0 ) !== $product_id || (int) ( $item['variation_id'] ?? 0 ) !== $variation_id || ! self::cart_item_is_editable( $item, $key ) ) {
			return null;
		}
		return $item;
	}

	/** Exclude uploads and linked child products until their edit lifecycles are supported. */
	private static function cart_item_is_editable( array $cart_item, string $cart_item_key ): bool {
		if ( empty( $cart_item[ self::ITEM_KEY ] ) || ! empty( $cart_item[ self::FILES_KEY ] ) || ! empty( $cart_item['opf_child_parent_key'] ) || ! empty( $cart_item['opf_child_field'] ) ) {
			return false;
		}
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
			return false;
		}
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'upload', 'child_products' ], true ) ) {
					return false;
				}
			}
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $other_key => $other_item ) {
				if ( $cart_item_key === $other_key ) {
					continue;
				}
				if ( (string) ( $other_item['opf_child_parent_key'] ?? '' ) === $cart_item_key ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function prefill_edit_quantity( array $args, $product ): array {
		if ( ! $product instanceof \WC_Product ) {
			return $args;
		}
		$item = Renderer::visible_to_viewer() ? self::requested_cart_edit_item( $product ) : null;
		if ( $item ) {
			$args['input_value'] = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		}
		return $args;
	}

	public static function edit_add_to_cart_text( string $text, $product ): string {
		if ( ! $product instanceof \WC_Product || ! Renderer::visible_to_viewer() || ! self::requested_cart_edit_item( $product ) ) {
			return $text;
		}
		return __( 'Update options', 'open-product-fields-for-woocommerce' );
	}

	/** Keep the original Woo cart key while replacing its validated field data. */
	public static function complete_cart_edit( string $new_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
		$edit_key = sanitize_text_field( (string) ( $cart_item_data['opf_edit_cart_item_key'] ?? '' ) );
		if ( '' === $edit_key || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$cart = WC()->cart;
		$old_item = $cart->get_cart_item( $edit_key );
		$new_item = $cart->get_cart_item( $new_key );
		if ( ! is_array( $old_item ) || ! is_array( $new_item ) || ! self::cart_item_is_editable( $old_item, $edit_key ) || (int) ( $new_item['product_id'] ?? 0 ) !== $product_id ) {
			if ( $new_key !== $edit_key && isset( $cart->cart_contents[ $new_key ] ) ) {
				unset( $cart->cart_contents[ $new_key ] );
				$cart->set_session();
			}
			wc_add_notice( __( 'This cart item could not be updated. Please return to your cart and try again.', 'open-product-fields-for-woocommerce' ), 'error' );
			return;
		}
		unset( $new_item['opf_edit_cart_item_key'] );
		$new_item['key'] = $edit_key;
		$new_item['quantity'] = max( 1, $quantity );
		$cart->cart_contents[ $edit_key ] = $new_item;
		if ( $new_key !== $edit_key ) {
			unset( $cart->cart_contents[ $new_key ] );
		}
		$cart->calculate_totals();
		$cart->set_session();
	}

	/**
	 * Raw Store API payload for this request. Validation runs before cart item
	 * data exists, so the capture step stashes the payload here.
	 *
	 * @var array|null
	 */
	private static $store_api_raw = null;

	/** Staged upload tickets captured from Store API requests. */
	private static $store_api_upload_tokens = null;

	/**
	 * Validate on add-to-cart. Runs for classic AND Store API paths.
	 *
	 * @param bool $passed     Whether validation passed so far.
	 * @param int  $product_id Product id.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public static function validate_add_to_cart( bool $passed, int $product_id, int $quantity, int $variation_id = 0, array $variations = [], array $cart_item_data = [] ): bool {
		if ( self::$adding_child_lines || ! empty( $cart_item_data['opf_child_parent_key'] ) || ! empty( $cart_item_data['opf_child_order_again_line'] ) || ! empty( $cart_item_data['opf_order_again_has_child_lines'] ) ) {
			return $passed;
		}
		if ( isset( $_POST['opf_edit_cart_item'] ) && '' === self::posted_cart_edit_key( $product_id, $variation_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wc_add_notice( __( 'This cart item can no longer be edited. Please return to your cart and try again.', 'open-product-fields-for-woocommerce' ), 'error' );
			return false;
		}
		if ( ! $passed ) {
			return false;
		}

		// Escape hatch for automated E2E traffic (parity with the legacy
		// wapf/skip_cart_validation filters).
		if ( apply_filters( 'opf_skip_validation', false ) ) {
			return true;
		}

		// Transition gate: when OPF is admin/e2e-only, customers' carts carry
		// no OPF data and validation stays out of their way entirely.
		if ( ! Renderer::visible_to_viewer() ) {
			return $passed;
		}

		$product = self::product_for_cart_request( $product_id, $variation_id );
		if ( ! $product ) {
			return $passed;
		}

		if ( ! empty( $cart_item_data['opf_order_again_restored'] ) && ! empty( $cart_item_data[ self::ITEM_KEY ] ) ) {
			$groups = FieldGroups::for_product( $product );
			$values = self::retain_price_calculation_groups( (array) $cart_item_data[ self::ITEM_KEY ], $groups );
			$files = (array) ( $cart_item_data[ self::FILES_KEY ] ?? [] );
			$errors = array_merge(
				self::validate_values( $product, $values, max( 1, $quantity ), $files ),
				self::validate_uploads( $product, $values, true )
			);
			if ( self::has_disabled_choice_submission( $product, $values ) ) {
				$errors[] = __( 'The selected option is not available.', 'open-product-fields-for-woocommerce' );
			}
			foreach ( $errors as $error ) {
				wc_add_notice( $error, 'error' );
			}
			return empty( $errors );
		}

		$raw = self::$store_api_raw;
		if ( null === $raw && isset( $_POST['opf'] ) && is_array( $_POST['opf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['opf'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		}
		$initial_values = self::collect_submitted( $product, self::$store_api_raw, max( 1, $quantity ) );
		self::$store_api_raw = null; // Consumed: never leak into the next add.
		// Order-again restores the previous order's uploads instead of asking
		// the customer to re-upload; those inherited files satisfy the field.
		$inherited = ! empty( $cart_item_data[ self::FILES_KEY ] );
		$upload_errors = self::validate_uploads( $product, $initial_values, $inherited );
		$private_files = self::$pending_uploads_product === $product_id
			? (array) self::$pending_uploads
			: (array) ( $cart_item_data[ self::FILES_KEY ] ?? [] );
		$values = self::collect_submitted( $product, $raw, max( 1, $quantity ), $private_files );
		$errors = array_merge( self::validate_values( $product, $values, max( 1, $quantity ), $private_files ), $upload_errors );
		if ( self::has_disabled_choice_submission( $product, $raw ) ) {
			$errors[] = __( 'The selected option is not available.', 'open-product-fields-for-woocommerce' );
		}
		if ( $errors && self::$pending_uploads_product === $product_id ) {
			self::discard_pending_uploads();
		}

		foreach ( $errors as $error ) {
			wc_add_notice( $error, 'error' );
		}

		self::$store_api_upload_tokens = null;
		return empty( $errors );
	}

	/**
	 * Attach validated data to the cart item.
	 *
	 * @param array $cart_item_data Incoming cart item data.
	 * @param int   $product_id     Product id.
	 */
	public static function attach( array $cart_item_data, int $product_id, int $variation_id = 0, int $quantity = 1 ): array {
		if ( self::$adding_child_lines || ! empty( $cart_item_data['opf_child_parent_key'] ) || ! empty( $cart_item_data['opf_child_order_again_line'] ) || ! empty( $cart_item_data['opf_order_again_has_child_lines'] ) ) {
			return $cart_item_data;
		}
		if ( ! Renderer::visible_to_viewer() ) {
			return $cart_item_data;
		}
		if ( ! empty( $cart_item_data['opf_order_again_restored'] ) ) {
			unset( $cart_item_data['opf_order_again_restored'] );
			$product = self::product_for_cart_request( $product_id, $variation_id );
			if ( $product ) {
				$cart_item_data['opf_base_price'] = (float) $product->get_price( 'edit' );
				$cart_item_data['opf_base_weight'] = (float) $product->get_weight();
			}
			return $cart_item_data;
		}

		$product = self::product_for_cart_request( $product_id, $variation_id );
		if ( ! $product ) {
			return $cart_item_data;
		}

		// Capture always places the Store API payload in cart_item_data;
		// classic submissions come through $_POST. The static is validation-
		// only (consumed and cleared there) — reading it here could leak a
		// previous submission into this cart item.
		$raw = null;
		if ( isset( $cart_item_data['opf_fields_raw'] ) && is_array( $cart_item_data['opf_fields_raw'] ) ) {
			$raw = $cart_item_data['opf_fields_raw'];
			unset( $cart_item_data['opf_fields_raw'] );
		}

		$quantity = max( 1, $quantity );
		$upload_tokens = isset( $cart_item_data['opf_upload_tokens_raw'] ) && is_array( $cart_item_data['opf_upload_tokens_raw'] ) ? $cart_item_data['opf_upload_tokens_raw'] : [];
		unset( $cart_item_data['opf_upload_tokens_raw'] );
		$pending_files = self::$pending_uploads_product === $product_id ? (array) self::$pending_uploads : self::resolve_upload_tickets( $product, $upload_tokens );
		$values = self::retain_price_calculation_groups( self::collect_submitted( $product, $raw, $quantity, $pending_files ), FieldGroups::for_product( $product ) );
		if ( self::$pending_uploads_product === $product_id ) {
			$files                           = self::$pending_uploads;
			self::$pending_uploads           = null;
			self::$pending_uploads_product   = 0;
			self::$pending_uploads_signature = '';
		} else {
			// Staged files for another product can never attach; do not leak.
			self::discard_pending_uploads();
			$files = $pending_files ? $pending_files : null;
		}

		$edit_key = self::posted_cart_edit_key( $product_id, $variation_id );
		if ( '' !== $edit_key ) {
			$cart_item_data['opf_edit_cart_item_key'] = $edit_key;
		}
		if ( empty( $values ) && empty( $files ) && '' === $edit_key ) {
			return $cart_item_data;
		}

		if ( ! empty( $values ) ) {
			$cart_item_data[ self::ITEM_KEY ] = $values;
		}
		if ( ! empty( $files ) ) {
			$cart_item_data[ self::FILES_KEY ] = $files;
		}
		$cart_item_data['opf_base_price'] = (float) $product->get_price( 'edit' );
		$cart_item_data['opf_base_weight'] = (float) $product->get_weight();
		return $cart_item_data;
	}

	/** Resolve the selected variation as the product context for fields and pricing. */
	private static function product_for_cart_request( int $product_id, int $variation_id ): ?\WC_Product {
		$product = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		if ( $variation_id > 0 && ( ! $product || ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $product_id ) ) {
			return null;
		}
		return $product instanceof \WC_Product ? $product : null;
	}

	/** Add selected linked products as native WooCommerce cart lines. */
	public static function add_child_product_lines( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
		if ( self::$adding_child_lines || ! empty( $cart_item_data['opf_child_parent_key'] ) || ! empty( $cart_item_data['opf_edit_cart_item_key'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$cart = WC()->cart;
		$parent = $cart->get_cart_item( $cart_item_key );
		if ( ! is_array( $parent ) || empty( $parent[ self::ITEM_KEY ] ) ) {
			return;
		}
		if ( ! empty( $parent['opf_order_again_has_child_lines'] ) ) {
			$old_parent_key = (string) ( $parent['opf_order_again_original_cart_key'] ?? '' );
			if ( '' !== $old_parent_key ) {
				self::$order_again_parent_keys[ $old_parent_key ] = $cart_item_key;
			}
			unset( WC()->cart->cart_contents[ $cart_item_key ]['opf_order_again_has_child_lines'], WC()->cart->cart_contents[ $cart_item_key ]['opf_order_again_original_cart_key'] );
			return;
		}
		$product = $parent['data'] ?? wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$entries = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'child_products' !== $field['type'] ) {
					continue;
				}
				$submitted = $parent[ self::ITEM_KEY ][ $gid ][ $field['id'] ] ?? [];
				$available = ChildProductCatalog::products( $field, (int) $product->get_id() );
				$eligible = array_map( static fn( \WC_Product $child ): int => (int) $child->get_id(), $available );
				$resolved = ChildProductSelection::quantities( $field, $submitted, $eligible, max( 1, $quantity ) );
				if ( $resolved['errors'] ) {
					continue;
				}
				foreach ( $resolved['selection'] as $child_id => $child_quantity ) {
					$base_quantity = ! empty( $field['quantity_input'] ) ? (int) ( $submitted[ $child_id ] ?? 0 ) : 1;
					$line_data = [
						'opf_child_parent_key' => $cart_item_key,
						'opf_child_field' => (string) $field['id'],
						'opf_child_base_quantity' => $base_quantity,
						'opf_child_scale_with_parent' => empty( $field['quantity_input'] ) || 'multiply_parent' === ( $field['quantity_mode'] ?? 'per_parent' ),
					];
					$entries[] = [ (int) $child_id, (int) $child_quantity, $line_data ];
				}
			}
		}
		if ( ! $entries ) {
			return;
		}
		self::$adding_child_lines = true;
		$failed = false;
		try {
			foreach ( $entries as [ $child_id, $child_quantity, $line_data ] ) {
				if ( ! $cart->add_to_cart( $child_id, $child_quantity, 0, [], $line_data ) ) {
					$failed = true;
					break;
				}
			}
			if ( $failed ) {
				foreach ( $cart->get_cart() as $key => $item ) {
					if ( ( $item['opf_child_parent_key'] ?? '' ) === $cart_item_key || $key === $cart_item_key ) {
						$cart->remove_cart_item( $key );
					}
				}
			}
		} finally {
			self::$adding_child_lines = false;
		}
		if ( $failed ) {
			wc_add_notice( __( 'One of the selected products is no longer available in the requested quantity. Please review your selections.', 'open-product-fields-for-woocommerce' ), 'error' );
		}
	}

	/** Remove associated child lines when a configured parent line is removed. */
	public static function remove_child_product_lines( string $cart_item_key, $cart ): void {
		if ( self::$adding_child_lines || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ( $item['opf_child_parent_key'] ?? '' ) === $cart_item_key ) {
				self::$adding_child_lines = true;
				try {
					$cart->remove_cart_item( $key );
				} finally {
					self::$adding_child_lines = false;
				}
			}
		}
	}

	/** Keep linked-product quantities in step with changes to the parent line. */
	public static function sync_child_product_quantities( string $cart_item_key, int $quantity, int $old_quantity, $cart ): void {
		if ( self::$adding_child_lines || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		$changed = $cart->get_cart_item( $cart_item_key );
		if ( is_array( $changed ) && ! empty( $changed['opf_child_parent_key'] ) ) {
			$cart_item_key = (string) $changed['opf_child_parent_key'];
			$parent = $cart->get_cart_item( $cart_item_key );
			$quantity = is_array( $parent ) ? max( 1, (int) ( $parent['quantity'] ?? 1 ) ) : $quantity;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ( $item['opf_child_parent_key'] ?? '' ) !== $cart_item_key ) {
				continue;
			}
			$child_quantity = (int) ( $item['opf_child_base_quantity'] ?? 1 );
			if ( ! empty( $item['opf_child_scale_with_parent'] ) ) {
				$child_quantity *= max( 1, $quantity );
			}
			self::$adding_child_lines = true;
			try {
				$cart->set_quantity( $key, $child_quantity, false );
			} finally {
				self::$adding_child_lines = false;
			}
		}
	}

	/** Remove temporary session tickets only after WooCommerce inserted the cart line. */
	public static function consume_cart_upload_tickets( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
		$cart = function_exists( 'WC' ) && WC() ? WC()->cart : null;
		$item = $cart && method_exists( $cart, 'get_cart_item' ) ? $cart->get_cart_item( $cart_item_key ) : $cart_item_data;
		foreach ( self::file_tokens( (array) ( $item[ self::FILES_KEY ] ?? [] ) ) as $token ) {
			UploadService::consume_staged( $token );
		}
	}

	/** Retain empty group entries for price formulas that need no submitted input. */
	private static function retain_price_calculation_groups( array $values, array $groups ): array {
		foreach ( $groups as $entry ) {
			$has_price_calculation = false;
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'calculation' === $field['type'] && 'price' === ( $field['calculation_type'] ?? 'informational' ) ) {
					$has_price_calculation = true;
					break;
				}
			}
			if ( $has_price_calculation && ! isset( $values[ (string) $entry['id'] ] ) ) {
				$values[ (string) $entry['id'] ] = [];
			}
		}
		return $values;
	}

	/**
	 * Re-price items loaded from session. The base price is re-derived from
	 * the CURRENT product price so catalog price changes (sales ending, price
	 * updates) apply to existing cart lines; the stored value is only a
	 * fallback if the product no longer resolves a price.
	 *
	 * @param array $cart_item Cart item.
	 * @param array $values    Session values.
	 */
	public static function restore_from_session( array $cart_item, array $values ): array {
		if ( empty( $values[ self::ITEM_KEY ] ) ) {
			return $cart_item;
		}

		$product = $cart_item['data'] ?? null;
		$current = $product instanceof \WC_Product ? (float) $product->get_price( 'edit' ) : 0.0;

		$cart_item['opf_base_price'] = $current > 0
			? $current
			: (float) ( $values['opf_base_price'] ?? $current );
		if ( $product instanceof \WC_Product ) {
			$cart_item['opf_base_weight'] = (float) ( $values['opf_base_weight'] ?? $product->get_weight() );
		}

		return $cart_item;
	}

	/**
	 * Apply addon prices in the cart. Per unit, server-side only.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function apply_prices( \WC_Cart $cart ): void {
		static $recursing = false;
		if ( $recursing ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::ITEM_KEY ] ) || ! isset( $cart_item['opf_base_price'] ) ) {
				continue;
			}

			$product = $cart_item['data'];
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$base     = (float) $cart_item['opf_base_price'];
			$quantity = max( 1, (int) $cart_item['quantity'] );
			$per_unit = self::addons_per_unit( $product, $cart_item[ self::ITEM_KEY ], $base, $quantity, (array) ( $cart_item[ self::FILES_KEY ] ?? [] ) );
			$base_weight = (float) ( $cart_item['opf_base_weight'] ?? $product->get_weight() );
			$product->set_weight( (string) max( 0.0, $base_weight + self::weight_delta( $product, $cart_item[ self::ITEM_KEY ] ) ) );
			$target   = max( 0.0, $base + $per_unit );

			if ( abs( (float) $product->get_price( 'edit' ) - $target ) > 0.000001 ) {
				$recursing = true;
				$product->set_price( (string) $target );
				$recursing = false;
			}
		}
	}

	/**
	 * Exclude OPF add-on value from percentage coupons when enabled.
	 *
	 * WooCommerce invokes this filter for percentage and fixed-product coupon
	 * paths. WAPF's percentage-only setting is mirrored here; other coupon
	 * types retain WooCommerce's discount unchanged.
	 * Source: WooCommerce WC_Discounts::apply_coupon_percent() and
	 * https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/changelog/
	 *
	 * @param float       $discount          Proposed discount amount.
	 * @param float       $discounting_amount Current eligible line amount.
	 * @param array       $cart_item         WooCommerce cart item.
	 * @param bool        $single            Whether this is a single-item discount.
	 * @param object|null $coupon            Coupon being calculated.
	 * @return float
	 */
	public static function filter_coupon_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( 'yes' !== get_option( 'opf_coupon_discount_base_only', 'no' ) ) {
			return $discount;
		}
		if ( ! is_object( $coupon ) || ! method_exists( $coupon, 'is_type' ) || ! $coupon->is_type( 'percent' ) ) {
			return $discount;
		}
		if ( ! is_array( $cart_item ) || ! isset( $cart_item['opf_base_price'], $cart_item['data'] ) || ! $cart_item['data'] instanceof \WC_Product ) {
			return $discount;
		}
		if ( ! method_exists( $coupon, 'get_amount' ) ) {
			return $discount;
		}

		return self::base_only_percent_discount(
			(float) $discount,
			(float) $discounting_amount,
			(float) $cart_item['opf_base_price'],
			(float) $cart_item['data']->get_price( 'edit' ),
			(float) $coupon->get_amount(),
			function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2
		);
	}

	/**
	 * Return the percentage discount for the OPF base-price share of an
	 * eligible line. Woo passes discounting_amount in currency units, already
	 * reflecting any coupon per-item limit; dividing by the adjusted unit price
	 * recovers the eligible quantity before applying the base-price share.
	 */
	public static function base_only_percent_discount( float $discount, float $discounting_amount, float $base_unit_price, float $adjusted_unit_price, float $percent, int $decimals = 2 ): float {
		if ( $discounting_amount <= 0 || $base_unit_price < 0 || $adjusted_unit_price <= 0 || $percent <= 0 || $decimals < 0 || $decimals > 6 ) {
			return 0.0;
		}

		$eligible_base = $discounting_amount * ( $base_unit_price / $adjusted_unit_price );
		$scale         = 10 ** $decimals;
		$base_discount = floor( $eligible_base * ( $percent / 100 ) * $scale ) / $scale;
		return min( max( 0.0, $discounting_amount ), max( 0.0, $base_discount ) );
	}

	/**
	 * Compute total per-unit addons for a cart line.
	 *
	 * @param \WC_Product           $product  Product.
	 * @param array<int|string, array<string, mixed>> $values gid => fid => value(s).
	 * @param float                 $base     Base unit price.
	 * @param int                   $quantity Line quantity.
	 */
	public static function addons_per_unit( \WC_Product $product, array $values, float $base, int $quantity, array $files = [] ): float {
		$per_unit = 0.0;
		$calculation_groups = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}

			$group_values = (array) $values[ $gid ];
			$submitted_values = $group_values;
			$lookup_tables = FieldGroup::lookup_tables_for_group( $group->data );
			$formula_variables = FieldGroup::resolve_product_formula_variables( $group->data, $group_values, (int) $product->get_id() );
			$file_counts = self::formula_file_counts( $group, $submitted_values, (array) ( $files[ $gid ] ?? [] ) );
			$group_values = Calculator::resolve_calculation_values(
				$group->data['fields'],
				$group_values,
				[
					'price' => $base,
					'qty' => $quantity,
					'addons' => $per_unit,
					'lookup_tables' => $lookup_tables,
					'formula_variables' => $formula_variables,
					'file_counts' => $file_counts,
				]
			);
			$candidate_prices = Calculator::field_price_map(
				$group->data['fields'],
				$group_values,
				[ 'price' => $base, 'qty' => $quantity, 'addons' => $per_unit, 'lookup_tables' => $lookup_tables, 'formula_variables' => $formula_variables ]
			);
			$group_values = Calculator::resolve_calculation_values(
				$group->data['fields'],
				$submitted_values,
				[
					'price' => $base,
					'qty' => $quantity,
					'addons' => $per_unit,
					'lookup_tables' => $lookup_tables,
					'formula_variables' => $formula_variables,
					'file_counts' => $file_counts,
					'field_prices' => $candidate_prices,
				]
			);
			$field_prices = Calculator::field_price_map(
				$group->data['fields'],
				$group_values,
				[
					'price' => $base,
					'qty' => $quantity,
					'addons' => $per_unit,
					'lookup_tables' => $lookup_tables,
					'formula_variables' => $formula_variables,
				]
			);
			$calculation_groups[] = [
				'group'      => $group,
				'submitted_values' => $submitted_values,
				'values'     => $group_values,
				'file_counts' => $file_counts,
				'lookup_tables' => $lookup_tables,
				'formula_variables' => $formula_variables,
				'field_prices' => $field_prices,
			];

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'html', 'section', 'calculation' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) ) {
					continue;
				}
				if ( ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$per_unit += Calculator::field_addon(
					$field,
					$group_values[ $fid ],
					[
						'price'  => $base,
						'qty'    => $quantity,
						'addons' => $per_unit,
						'field_values' => $group_values,
						'lookup_tables' => $lookup_tables,
						'formula_variables' => $formula_variables,
						'field_prices' => $field_prices,
					]
				);
			}
		}

		// Evaluate after ordinary options across every matching group, matching
		// the browser's options_total context. Formulas may adjust either way.
		foreach ( $calculation_groups as $calculation_group ) {
			$group = $calculation_group['group'];
			$group_values = $calculation_group['values'];
			$file_counts = $calculation_group['file_counts'];
			$lookup_tables = $calculation_group['lookup_tables'];
			$formula_variables = $calculation_group['formula_variables'];
			$field_prices = $calculation_group['field_prices'];
			$group_values = Calculator::resolve_calculation_values(
				$group->data['fields'],
				$calculation_group['submitted_values'],
				[
					'price' => $base,
					'qty' => $quantity,
					'addons' => $per_unit,
					'lookup_tables' => $lookup_tables,
					'formula_variables' => $formula_variables,
					'file_counts' => $file_counts,
					'field_prices' => $field_prices,
				]
			);
			$field_prices = Calculator::field_price_map(
				$group->data['fields'],
				$group_values,
				[ 'price' => $base, 'qty' => $quantity, 'addons' => $per_unit, 'lookup_tables' => $lookup_tables, 'formula_variables' => $formula_variables ]
			);
			foreach ( $group->data['fields'] as $field ) {
				if ( 'calculation' !== $field['type'] || 'price' !== ( $field['calculation_type'] ?? 'informational' ) || ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$line_adjustment = Calculator::evaluate_formula(
					(string) ( $field['formula'] ?? '' ),
					$base,
					$quantity,
					$per_unit,
					'',
					$group_values,
					null,
					$file_counts,
					$lookup_tables,
					$formula_variables,
					$field_prices
				);
				$per_unit += $line_adjustment / max( 1, $quantity );
			}
		}

		return apply_filters( 'opf_addon_price', $per_unit, $product, $values, $base );
	}

	/** Count only existing private files for visible upload fields. */
	private static function formula_file_counts( FieldGroup $group, array $values, array $group_files ): array {
		$counts = [];
		foreach ( $group->data['fields'] as $field ) {
			if ( 'upload' !== $field['type'] || ! Evaluator::is_visible( $field, $values ) ) {
				continue;
			}
			$count = 0;
			foreach ( (array) ( $group_files[ $field['id'] ] ?? [] ) as $file ) {
				$token = is_array( $file ) && isset( $file['token'] ) && is_string( $file['token'] ) ? $file['token'] : '';
				if ( '' !== $token && null !== UploadService::path( $token ) ) {
					$count++;
				}
			}
			$counts[ strtolower( (string) $field['id'] ) ] = $count;
		}
		return $counts;
	}

	/** Resolve calculation dependencies for the cart/order display visibility pass. */
	private static function resolved_group_values_for_display( \WC_Product $product, FieldGroup $group, array $values, int $quantity, array $group_files, ?float $base_price, float $addons ): array {
		$base_price = null !== $base_price ? $base_price : (float) $product->get_price( 'edit' );
		$lookup_tables = FieldGroup::lookup_tables_for_group( $group->data );
		$formula_variables = FieldGroup::resolve_product_formula_variables( $group->data, $values, (int) $product->get_id() );
		$context = [
			'price' => $base_price,
			'qty' => max( 1, $quantity ),
			'addons' => $addons,
			'lookup_tables' => $lookup_tables,
			'formula_variables' => $formula_variables,
			'file_counts' => self::formula_file_counts( $group, $values, $group_files ),
		];
		$resolved = Calculator::resolve_calculation_values( $group->data['fields'], $values, $context );
		$context['file_counts'] = self::formula_file_counts( $group, $resolved, $group_files );
		$context['field_prices'] = Calculator::field_price_map( $group->data['fields'], $resolved, $context );
		return Calculator::resolve_calculation_values( $group->data['fields'], $values, $context );
	}

	/** Count only stored private upload tokens, without trusting request metadata. */
	private static function private_file_counts_for_group( FieldGroup $group, array $group_files ): array {
		$counts = [];
		foreach ( $group->data['fields'] as $field ) {
			if ( 'upload' !== $field['type'] ) {
				continue;
			}
			$count = 0;
			foreach ( (array) ( $group_files[ $field['id'] ] ?? [] ) as $file ) {
				$token = is_array( $file ) && isset( $file['token'] ) && is_string( $file['token'] ) ? $file['token'] : '';
				if ( '' !== $token && null !== UploadService::path( $token ) ) {
					$count++;
				}
			}
			$counts[ strtolower( (string) $field['id'] ) ] = $count;
		}
		return $counts;
	}

	/** Compute selected choice weight in the shop's configured weight unit. */
	public static function weight_delta( \WC_Product $product, array $values ): float {
		$delta = 0.0;
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			$group_values = (array) ( $values[ $gid ] ?? [] );
			$group = $entry['group'];
			$formula_variables = FieldGroup::resolve_product_formula_variables( $group->data, $group_values, (int) $product->get_id() );
			$lookup_tables = FieldGroup::lookup_tables_for_group( $group->data );
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( ! array_key_exists( $field['id'], $group_values ) || ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$delta += Calculator::field_weight_delta( $field, $group_values[ $field['id'] ], $group_values, $lookup_tables, $formula_variables );
			}
		}
		return $delta;
	}

	/** Set a per-request display surface while Woo Store API serializes its response. */
	public static function set_store_api_display_surface( $response, $handler, $request ) {
		self::$store_api_display_surface = null;
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		$route = (string) $request->get_route();
		if ( preg_match( '#^/wc/store/v[0-9]+/checkout(?:/|$)#', $route ) ) {
			self::$store_api_display_surface = 'checkout';
		} elseif ( preg_match( '#^/wc/store/v[0-9]+/cart(?:/|$)#', $route ) ) {
			self::$store_api_display_surface = 'cart';
			$referer = (string) $request->get_header( 'referer' );
			$referer_path = (string) wp_parse_url( $referer, PHP_URL_PATH );
			$checkout_path = (string) wp_parse_url( wc_get_page_permalink( 'checkout' ), PHP_URL_PATH );
			$checkout_path = '/' . trim( $checkout_path, '/' ) . '/';
			if ( '' !== $referer_path && '' !== $checkout_path && 0 === strpos( trailingslashit( $referer_path ), $checkout_path ) ) {
				self::$store_api_display_surface = 'checkout';
			}
		}
		return $response;
	}

	/** Clear request context after Woo finishes serializing the Store API response. */
	public static function clear_store_api_display_surface( $response, $handler, $request ) {
		self::$store_api_display_surface = null;
		return $response;
	}

	/** Resolve the customer-facing surface for a cart item-data render. */
	private static function display_surface(): string {
		if ( in_array( self::$store_api_display_surface, [ 'cart', 'checkout' ], true ) ) {
			return self::$store_api_display_surface;
		}
		return function_exists( 'is_checkout' ) && is_checkout() ? 'checkout' : 'cart';
	}

	/** Whether a field is configured to hide on this customer-facing surface. */
	private static function is_field_hidden_on_surface( array $field, string $surface ): bool {
		$option = [ 'cart' => 'hide_cart', 'checkout' => 'hide_checkout', 'order' => 'hide_order' ][ $surface ] ?? '';
		return '' !== $option && ! empty( $field[ $option ] );
	}

	/**
	 * Cart (classic) + block cart/checkout display.
	 *
	 * @param array $other_data Display data so far.
	 * @param array $cart_item  Cart item.
	 * @return array<int,array{name:string,value:string}>
	 */
	public static function display_item_data( array $other_data, array $cart_item ): array {
		if ( empty( $cart_item[ self::ITEM_KEY ] ) && empty( $cart_item[ self::FILES_KEY ] ) ) {
			return $other_data;
		}

		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return $other_data;
		}

		$values = (array) ( $cart_item[ self::ITEM_KEY ] ?? [] );
		$files  = (array) ( $cart_item[ self::FILES_KEY ] ?? [] );

		$quantity = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
		$base_price = isset( $cart_item['opf_base_price'] ) ? (float) $cart_item['opf_base_price'] : (float) $product->get_price( 'edit' );
		$addons = (float) $product->get_price( 'edit' ) - $base_price;
		$surface = self::display_surface();
		foreach ( self::visible_selections( $product, $values, $quantity, $files, $base_price, $addons, $surface ) as $selection ) {
			$other_data[] = [
				'name'    => $selection['label'],
				'value'   => $selection['value'],
				'display' => '',
			];
		}

		foreach ( self::visible_file_selections( $product, $values, $files, $quantity, $base_price, $addons, $surface ) as $selection ) {
			$other_data[] = [
				'name'    => $selection['label'],
				'value'   => $selection['value'],
				'display' => '',
			];
		}

		return $other_data;
	}

	/**
	 * Visible label/value pairs for a cart item's selections.
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<int|string, array<string, mixed>> $values Stored values.
	 * @param int $quantity Cart quantity.
	 * @param array $files Stored private uploads.
	 * @param float|null $base_price Base unit price.
	 * @param float $addons Existing per-unit adjustment.
	 * @return array<int,array{label:string,value:string}>
	 */
	public static function visible_selections( \WC_Product $product, array $values, int $quantity = 1, array $files = [], ?float $base_price = null, float $addons = 0.0, string $surface = 'cart' ): array {
		$out = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}
			$group_values = (array) $values[ $gid ];
			$visible_values = self::resolved_group_values_for_display( $product, $group, $group_values, $quantity, (array) ( $files[ $gid ] ?? [] ), $base_price, $addons );

			foreach ( $group->data['fields'] as $field ) {
				if ( self::is_field_hidden_on_surface( $field, $surface ) ) {
					continue;
				}
				if ( in_array( $field['type'], [ 'paragraph', 'html', 'section', 'calculation', 'upload' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) || ! Evaluator::is_visible( $field, $visible_values ) ) {
					continue;
				}
				$raw = $group_values[ $fid ];
				if ( '' === $raw || [] === $raw ) {
					continue;
				}

				$value = ! empty( $field['repeat']['enabled'] ) && is_array( $raw )
					? implode( '; ', array_filter( array_map( static function ( $instance, $index ) use ( $field ): string {
						$display = self::display_value( $field, $instance );
						return '' === $display ? '' : sprintf( '%d. %s', $index + 1, $display );
					}, $raw, array_keys( $raw ) ) ) )
					: self::display_value( $field, $raw );
				if ( '' !== $value ) {
					$out[] = [
						'label' => $field['label'],
						'value' => $value,
					];
				}
			}
		}

		return $out;
	}

	/**
	 * Human display for a stored value (choice labels, not slugs).
	 *
	 * @param array<string,mixed> $field Field data.
	 * @param string|array        $raw   Stored value.
	 */
	private static function display_value( array $field, $raw ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- called from visible_selections().
		if ( 'child_products' === $field['type'] ) {
			$selected = [];
			if ( ! empty( $field['quantity_input'] ) && is_array( $raw ) ) {
				foreach ( $raw as $product_id => $quantity ) {
					if ( is_numeric( $product_id ) && (int) $quantity > 0 ) {
						$selected[ (int) $product_id ] = (int) $quantity;
					}
				}
			} else {
				foreach ( is_array( $raw ) ? $raw : [ $raw ] as $product_id ) {
					if ( is_scalar( $product_id ) && ctype_digit( (string) $product_id ) && (int) $product_id > 0 ) {
						$selected[ (int) $product_id ] = 1;
					}
				}
			}
			$labels = [];
			foreach ( $selected as $product_id => $quantity ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$labels[] = sprintf( '%s%s', $product->get_name(), $quantity > 1 ? ' × ' . $quantity : '' );
				}
			}
			return implode( ', ', $labels );
		}
		if ( 'date' === $field['type'] ) {
			return DateFormat::format( (string) $raw, get_option( 'opf_date_format', DateFormat::DEFAULT_FORMAT ) );
		}
		if ( 'toggle' === $field['type'] ) {
			return '1' === $raw
				? __( 'Yes', 'open-product-fields-for-woocommerce' )
				: __( 'No', 'open-product-fields-for-woocommerce' );
		}
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			if ( 'swatch' === $field['type'] && ! empty( $field['image_quantities'] ) && is_array( $raw ) ) {
				$labels = [];
				foreach ( $field['choices'] as $choice ) {
					$slug = (string) $choice['slug'];
					$quantity = (int) ( $raw[ $slug ] ?? 0 );
					if ( $quantity > 0 ) {
						$labels[] = sprintf( '%s × %d', (string) $choice['label'], $quantity );
					}
				}
				return implode( ', ', $labels );
			}
			$slugs = is_array( $raw ) ? $raw : [ $raw ];
			$map   = [];
			foreach ( $field['choices'] as $choice ) {
				$map[ $choice['slug'] ] = $choice['label'];
			}
			$labels = [];
			foreach ( $slugs as $slug ) {
				if ( isset( $map[ $slug ] ) ) {
					$labels[] = $map[ $slug ];
				}
			}
			return implode( ', ', $labels );
		}
		return (string) $raw;
	}

	/**
	 * Persist selections to the order item: one display meta per field plus a
	 * hidden structured record for re-order and admin tooling.
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $cart_item     Cart item.
	 * @param \WC_Order              $order         Order.
	 */
	public static function persist_order_item( \WC_Order_Item_Product $item, string $cart_item_key, array $cart_item, \WC_Order $order ): void {
		$item->add_meta_data( '_opf_cart_key', $cart_item_key, true );
		foreach ( [ 'opf_child_parent_key', 'opf_child_field', 'opf_child_base_quantity', 'opf_child_scale_with_parent' ] as $key ) {
			if ( array_key_exists( $key, $cart_item ) ) {
				$item->add_meta_data( '_' . $key, $cart_item[ $key ], true );
			}
		}
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$values = (array) ( $cart_item[ self::ITEM_KEY ] ?? [] );
		$files  = (array) ( $cart_item[ self::FILES_KEY ] ?? [] );
		if ( empty( $values ) && empty( $files ) ) {
			return;
		}

		$quantity = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
		$base_price = isset( $cart_item['opf_base_price'] ) ? (float) $cart_item['opf_base_price'] : (float) $product->get_price( 'edit' );
		$addons = (float) $product->get_price( 'edit' ) - $base_price;
		foreach ( self::visible_selections( $product, $values, $quantity, $files, $base_price, $addons, 'order' ) as $selection ) {
			$item->add_meta_data( $selection['label'], $selection['value'] );
		}
		foreach ( self::visible_file_selections( $product, $values, $files, $quantity, $base_price, $addons, 'order' ) as $selection ) {
			$item->add_meta_data( $selection['label'], $selection['value'] );
		}
		if ( $values ) {
			$item->add_meta_data( '_opf_fields', wp_json_encode( $values, JSON_UNESCAPED_UNICODE ), true );
		}
		if ( $files ) {
			$item->add_meta_data( self::FILE_META, wp_json_encode( $files, JSON_UNESCAPED_UNICODE ), true );
			$tokens = self::file_tokens( $files );
			if ( $tokens ) {
				$item->add_meta_data( self::FILE_TOKENS_META, $tokens, true );
				$existing = $order->get_meta( self::FILE_TOKENS_META, true );
				$existing = is_array( $existing ) ? $existing : [];
				$order->update_meta_data( self::FILE_TOKENS_META, array_values( array_unique( array_merge( $existing, $tokens ) ) ) );
			}
		}
	}

	/**
	 * Restore selections on "order again".
	 *
	 * @param array                 $cart_item_data Cart item data being built.
	 * @param \WC_Order_Item_Product $order_item    Order item.
	 * @param \WC_Order             $order          Order.
	 */
	public static function hidden_order_meta( array $keys ): array {
		$keys[] = '_opf_fields';
		$keys[] = self::FILE_META;
		$keys[] = self::FILE_TOKENS_META;
		$keys[] = '_opf_cart_key';
		$keys[] = '_opf_child_parent_key';
		$keys[] = '_opf_child_field';
		$keys[] = '_opf_child_base_quantity';
		$keys[] = '_opf_child_scale_with_parent';
		// Otros plugins que ensucian el display de órdenes
		$keys = array_merge( $keys, [
			'_nova_start_url',
			'_nova_start_type',
			'_nova_start_at',
			'_nova_start_metric',
			'_nova_start_count',
			'_nova_start_label',
			'_nova_start_name',
			'_nova_start_snapshot',
			'_wc_cog_item_cost',
			'_wc_cog_item_total_cost',
		] );
		return array_unique( $keys );
	}

	/**
	 * Restore selections on "order again".
	 *
	 * @param array                 $cart_item_data Cart item data being built.
	 * @param \WC_Order_Item_Product $order_item    Order item.
	 * @param \WC_Order             $order          Order.
	 */
	public static function restore_order_again( array $cart_item_data, \WC_Order_Item_Product $order_item, \WC_Order $order ): array {
		$old_parent_key = (string) $order_item->get_meta( '_opf_child_parent_key', true );
		if ( '' !== $old_parent_key ) {
			$cart_item_data['opf_child_parent_key'] = self::$order_again_parent_keys[ $old_parent_key ] ?? '';
			$cart_item_data['opf_child_order_again_line'] = true;
			$cart_item_data['opf_child_field'] = (string) $order_item->get_meta( '_opf_child_field', true );
			$cart_item_data['opf_child_base_quantity'] = max( 1, (int) $order_item->get_meta( '_opf_child_base_quantity', true ) );
			$cart_item_data['opf_child_scale_with_parent'] = '1' === (string) $order_item->get_meta( '_opf_child_scale_with_parent', true );
			return $cart_item_data;
		}
		$product = $order_item->get_product();
		if ( ! $product instanceof \WC_Product ) {
			return $cart_item_data;
		}
		$order_files = $order_item->get_meta( self::FILE_META, true );
		if ( is_string( $order_files ) && '' !== $order_files ) {
			$order_files = json_decode( $order_files, true );
		}
		$order_files = is_array( $order_files ) ? $order_files : [];

		$stored = $order_item->get_meta( '_opf_fields', true );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				$stored = $decoded;
			}
		}
		if ( is_array( $stored ) && $stored ) {
			$values = self::sanitize_submitted( $product, $stored, max( 1, (int) $order_item->get_quantity() ), $order_files );
			if ( $values ) {
				$cart_item_data[ self::ITEM_KEY ] = $values;
			}
			foreach ( FieldGroups::for_product( $product ) as $entry ) {
				foreach ( $entry['group']->data['fields'] as $field ) {
					if ( 'child_products' === $field['type'] && isset( $values[ (string) $entry['id'] ][ $field['id'] ] ) ) {
						// Historical child lines are restored separately by WooCommerce.
						// Avoid creating a second set from the parent field values.
						$old_cart_key = (string) $order_item->get_meta( '_opf_cart_key', true );
						if ( '' !== $old_cart_key ) {
							$cart_item_data['opf_order_again_has_child_lines'] = true;
							$cart_item_data['opf_order_again_original_cart_key'] = $old_cart_key;
						}
						break 2;
					}
				}
			}
		}

		// Give the new order its own copies of the previous order's uploads so
		// deleting the old order cannot remove files the new one depends on.
		if ( is_array( $order_files ) && $order_files ) {
			$duplicated = [];
			foreach ( $order_files as $gid => $group_files ) {
				foreach ( (array) $group_files as $fid => $entries ) {
					foreach ( (array) $entries as $entry ) {
						if ( ! is_array( $entry ) || empty( $entry['token'] ) ) {
							continue;
						}
						$new_token = UploadService::duplicate( (string) $entry['token'] );
						if ( null === $new_token ) {
							continue;
						}
						$entry['token']                    = $new_token;
						$duplicated[ (string) $gid ][ (string) $fid ][] = $entry;
					}
				}
			}
			if ( $duplicated ) {
				$cart_item_data[ self::FILES_KEY ] = $duplicated;
			}
		}

		// Pre-cutover orders have only WAPF's display-oriented metadata. Convert
		// it by exact field labels and unambiguous choice labels/slugs; never map
		// a duplicate label by position or by a fuzzy match.
		if ( empty( $cart_item_data[ self::ITEM_KEY ] ) ) {
			$legacy = $order_item->get_meta( '_wapf_meta', true );
			if ( is_string( $legacy ) && '' !== $legacy ) {
				$legacy = maybe_unserialize( $legacy );
			}
			if ( is_array( $legacy ) ) {
				$converted = self::convert_legacy_order_values( $product, $legacy );
				if ( $converted ) {
					$cart_item_data[ self::ITEM_KEY ] = $converted;
				}
			}
		}
		$restored_values = self::retain_price_calculation_groups(
			(array) ( $cart_item_data[ self::ITEM_KEY ] ?? [] ),
			FieldGroups::for_product( $product )
		);
		if ( $restored_values ) {
			$cart_item_data[ self::ITEM_KEY ] = $restored_values;
		}

		if ( $product instanceof \WC_Product && isset( $cart_item_data[ self::ITEM_KEY ] ) ) {
			$cart_item_data['opf_base_price'] = (float) $product->get_price( 'edit' );
			$cart_item_data['opf_order_again_restored'] = true;
		}
		return $cart_item_data;
	}

	/**
	 * Convert the display-oriented WAPF order snapshot to OPF's gid/fid map.
	 *
	 * WAPF did not persist the field-group id in `_wapf_meta`, so matching is
	 * deliberately conservative. Duplicate labels are accepted only when the
	 * normalized type or a choice value leaves exactly one candidate.
	 *
	 * @param \WC_Product        $product Product being reordered.
	 * @param array<string,mixed> $legacy  WAPF order metadata.
	 * @return array<string,array<string,string|array>>
	 */
	private static function convert_legacy_order_values( \WC_Product $product, array $legacy ): array {
		$legacy_fields = $legacy['fields'] ?? [];
		if ( ! is_array( $legacy_fields ) ) {
			return [];
		}

		$type_map = [
			'text-swatch'  => 'swatch',
			'image-swatch' => 'swatch',
			'multi-text-swatch'  => 'swatch',
			'multi-image-swatch' => 'swatch',
			'multi-color-swatch' => 'swatch',
			'color-swatch' => 'swatch',
			'card'         => 'select',
			'vcard'        => 'select',
			'checkboxes'   => 'checkbox',
		];
		$candidates = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			foreach ( $entry['group']->data['fields'] as $field ) {
				$key = self::order_label_key( $field['label'] ?? '' );
				if ( '' === $key ) {
					continue;
				}
				$candidates[ $key ][] = [ 'gid' => $gid, 'field' => $field ];
			}
		}

		$out = [];
		foreach ( $legacy_fields as $legacy_field ) {
			if ( ! is_array( $legacy_field ) ) {
				continue;
			}
			$key = self::order_label_key( $legacy_field['label'] ?? '' );
			$matches = $candidates[ $key ] ?? [];
			if ( ! $matches ) {
				continue;
			}

			if ( count( $matches ) > 1 ) {
				$legacy_type = (string) ( $legacy_field['type'] ?? '' );
				$wanted_type = $type_map[ $legacy_type ] ?? $legacy_type;
				$typed = array_values( array_filter( $matches, static function ( array $match ) use ( $wanted_type ): bool {
					return '' !== $wanted_type && $match['field']['type'] === $wanted_type;
				} ) );
				if ( 1 === count( $typed ) ) {
					$matches = $typed;
				} else {
					$legacy_tokens = self::legacy_value_tokens( $legacy_field );
					$by_choice = array_values( array_filter( $matches, static function ( array $match ) use ( $legacy_tokens ): bool {
						if ( ! in_array( $match['field']['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
							return false;
						}
						foreach ( $legacy_tokens as $token ) {
							foreach ( $match['field']['choices'] as $choice ) {
								if ( self::order_label_key( $token ) === self::order_label_key( $choice['label'] ) || $token === (string) $choice['slug'] ) {
									return true;
								}
							}
						}
						return false;
					} ) );
					if ( 1 !== count( $by_choice ) ) {
						continue;
					}
					$matches = $by_choice;
				}
			}

			if ( 1 !== count( $matches ) ) {
				continue;
			}
			$match = $matches[0];
			$value = self::legacy_value_for_field( $match['field'], $legacy_field );
			if ( null === $value ) {
				continue;
			}
			$clean = self::sanitize_value( $match['field'], $value );
			if ( null !== $clean ) {
				$out[ $match['gid'] ][ $match['field']['id'] ] = $clean;
			}
		}

		return $out;
	}

	/** Normalize a historical field label for exact matching. */
	private static function order_label_key( $label ): string {
		$label = wp_strip_all_tags( (string) $label );
		$label = preg_replace( '/\s+/u', ' ', trim( $label ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $label, 'UTF-8' ) : strtolower( (string) $label );
	}

	/** Extract all historical display/value tokens for choice matching. */
	private static function legacy_value_tokens( array $legacy_field ): array {
		$tokens = [];
		$raw_values = $legacy_field['values'] ?? [];
		if ( is_array( $raw_values ) ) {
			foreach ( $raw_values as $item ) {
				if ( is_array( $item ) ) {
					foreach ( [ 'slug', 'value', 'label' ] as $key ) {
						if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) {
							$tokens[] = (string) $item[ $key ];
						}
					}
				} elseif ( is_scalar( $item ) ) {
					$tokens[] = (string) $item;
				}
			}
		}
		if ( isset( $legacy_field['value'] ) && is_scalar( $legacy_field['value'] ) ) {
			$tokens[] = (string) $legacy_field['value'];
		}
		return array_values( array_unique( array_filter( $tokens, static fn( $token ): bool => '' !== trim( $token ) ) ) );
	}

	/** Convert historical choice labels/slugs to the current OPF choice slug(s). */
	private static function legacy_value_for_field( array $field, array $legacy_field ) {
		if ( ! in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			return $legacy_field['value'] ?? null;
		}

		$tokens = self::legacy_value_tokens( $legacy_field );
		$slugs = [];
		foreach ( $tokens as $token ) {
			$matches = array_values( array_filter( $field['choices'], static function ( array $choice ) use ( $token ): bool {
				return empty( $choice['disabled'] ) && ( (string) $choice['slug'] === $token || self::order_label_key( $choice['label'] ) === self::order_label_key( $token ) );
			} ) );
			if ( 1 !== count( $matches ) ) {
				continue;
			}
			$slugs[] = (string) $matches[0]['slug'];
		}
		$slugs = array_values( array_unique( $slugs ) );
		if ( ! $slugs ) {
			return null;
		}
		return 'checkbox' === $field['type'] || ( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) ) ? $slugs : $slugs[0];
	}

	/**
	 * Read submitted values from the Store API payload or the classic POST.
	 *
	 * @param \WC_Product      $product Product (variation resolved to parent).
	 * @param array<mixed>|null $raw     Raw payload from the Store API path, if any.
	 * @return array<string,mixed> gid => fid => value(s).
	 */
	private static function collect_submitted( \WC_Product $product, ?array $raw, int $quantity = 1, array $private_files = [] ): array {
		// Classic form POST.
		if ( null === $raw && isset( $_POST['opf'] ) && is_array( $_POST['opf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['opf'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		return self::sanitize_submitted( $product, $raw, $quantity, $private_files );
	}

	/**
	 * Sanitize raw submitted data against known groups/fields/types.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $raw     Raw submitted array.
	 * @return array<int|string, array<string, mixed>>
	 */
	private static function sanitize_submitted( \WC_Product $product, array $raw, int $quantity = 1, array $private_files = [] ): array {
		$values = [];
		$quantity_row_limit = self::quantity_row_limit_for_product( $product );

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$group_raw = isset( $raw[ $gid ] ) && is_array( $raw[ $gid ] ) ? $raw[ $gid ] : [];

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'html', 'section', 'calculation', 'upload' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				$raw_value = array_key_exists( $fid, $group_raw ) ? $group_raw[ $fid ] : ( $field['default'] ?? null );
				if ( ! array_key_exists( $fid, $group_raw ) && ! array_key_exists( 'default', $field ) ) {
					continue;
				}
				if ( ! empty( $field['repeat']['enabled'] ) ) {
					if ( ! array_key_exists( $fid, $group_raw ) && array_key_exists( 'default', $field ) ) {
						$raw_value = [ $field['default'] ];
					}
					$value = RepeaterField::sanitize( $field, $raw_value, static fn( $row ) => self::sanitize_value( $field, $row ), $quantity, $quantity_row_limit );
				} else {
					$value = self::sanitize_value( $field, $raw_value );
				}
				if ( null !== $value ) {
					$values[ $gid ][ $fid ] = $value;
				}
			}
		}

		// Evaluate conditions against the complete sanitized submission once, then
		// discard values belonging to hidden fields before validation, pricing, or
		// order persistence can observe them.
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			$group = $entry['group'];
			$group_values = $values[ $gid ] ?? [];
			$lookup_tables = FieldGroup::lookup_tables_for_group( $group->data );
			$formula_variables = FieldGroup::resolve_product_formula_variables( $group->data, $group_values, (int) $product->get_id() );
			$context = [
				'price' => (float) $product->get_price( 'edit' ),
				'qty' => max( 1, $quantity ),
				'lookup_tables' => $lookup_tables,
				'formula_variables' => $formula_variables,
				'file_counts' => self::private_file_counts_for_group( $group, (array) ( $private_files[ $gid ] ?? [] ) ),
			];
			$resolved_values = Calculator::resolve_calculation_values(
				$group->data['fields'],
				$group_values,
				$context
			);
			$context['file_counts'] = self::formula_file_counts( $group, $resolved_values, (array) ( $private_files[ $gid ] ?? [] ) );
			$context['field_prices'] = Calculator::field_price_map( $group->data['fields'], $resolved_values, $context );
			$resolved_values = Calculator::resolve_calculation_values( $group->data['fields'], $group_values, $context );
			foreach ( $group->data['fields'] as $field ) {
				if ( ! isset( $values[ $gid ][ $field['id'] ] ) || Evaluator::is_visible( $field, $resolved_values ) ) {
					continue;
				}
				unset( $values[ $gid ][ $field['id'] ] );
			}
			if ( empty( $values[ $gid ] ) ) {
				unset( $values[ $gid ] );
			}
		}

		return $values;
	}

	/**
	 * Sanitize one value by field type. Null when nothing was submitted.
	 *
	 * @param array<string,mixed> $field  Field definition.
	 * @param mixed               $value  Submitted value.
	 */
	private static function sanitize_value( array $field, $value ) {
		if ( 'child_products' === $field['type'] ) {
			// ChildProductSelection validates the submitted structure and current
			// catalog membership before any value is persisted or used in cart.
			return is_array( $value ) || is_scalar( $value ) ? $value : null;
		}
		if ( 'swatch' === $field['type'] && ! empty( $field['image_quantities'] ) ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			$valid_choices = [];
			foreach ( $field['choices'] as $choice ) {
				if ( empty( $choice['disabled'] ) ) {
					$valid_choices[ (string) $choice['slug'] ] = true;
				}
			}
			$clean = [];
			foreach ( $value as $slug => $quantity ) {
				$slug = is_string( $slug ) || is_int( $slug ) ? (string) $slug : '';
				if ( ! isset( $valid_choices[ $slug ] ) || ! is_scalar( $quantity ) || ! preg_match( '/^\d+$/', (string) $quantity ) ) {
					continue;
				}
				$clean[ $slug ] = (string) max( 0, (int) $quantity );
			}
			return array_filter( $clean, static fn( string $quantity ): bool => (int) $quantity > 0 ) ?: null;
		}
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			$valid_slugs = wp_list_pluck(
				array_filter(
					$field['choices'],
					static fn( array $choice ): bool => empty( $choice['disabled'] )
				),
				'slug'
			);
			$slugs       = (array) $value;
			$clean       = [];
			foreach ( $slugs as $slug ) {
				$slug = sanitize_text_field( (string) $slug );
				if ( in_array( $slug, $valid_slugs, true ) && ! in_array( $slug, $clean, true ) ) {
					$clean[] = $slug;
				}
			}
			if ( empty( $clean ) ) {
				return null;
			}
			return in_array( $field['type'], [ 'select', 'radio' ], true ) || ( 'swatch' === $field['type'] && empty( $field['multiple'] ) ) ? $clean[0] : $clean;
		}

		if ( is_array( $value ) ) {
			return null;
		}

			switch ( $field['type'] ) {
			case 'number':
				$number = trim( (string) $value );
				return '' === $number || ! is_numeric( $number ) ? null : $number;
			case 'date':
				$date = trim( (string) $value );
				return '' === $date ? null : $date;
			case 'url':
				$url = esc_url_raw( trim( (string) $value ) );
				return '' === $url ? null : $url;
			case 'textarea':
				$text = sanitize_textarea_field( (string) $value );
				return '' === trim( $text ) ? null : $text;
			case 'email':
				return FieldValue::sanitize( $field, sanitize_text_field( (string) $value ) );
			case 'toggle':
				return FieldValue::sanitize( $field, $value );
			default:
				$text = sanitize_text_field( (string) $value );
				return '' === trim( $text ) ? null : $text;
		}
	}

	/**
	 * Validation errors for a product's submitted values.
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<int|string, array<string, mixed>> $values Sanitized values.
	 * @return string[]
	 */
	private static function validate_values( \WC_Product $product, array $values, int $quantity = 1, array $private_files = [] ): array {
		$errors = [];
		$quantity_row_limit = self::quantity_row_limit_for_product( $product );
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( ! empty( $field['repeat']['enabled'] ) && 'quantity' === ( $field['repeat']['mode'] ?? 'button' ) && $quantity > $quantity_row_limit ) {
					return RepeaterField::validate( $field, [], $quantity, $quantity_row_limit );
				}
			}
		}

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$given = $values[ $gid ] ?? [];
			$lookup_tables = FieldGroup::lookup_tables_for_group( $group->data );
			$formula_variables = FieldGroup::resolve_product_formula_variables( $group->data, $given, (int) $product->get_id() );
			$context = [
				'price' => (float) $product->get_price( 'edit' ),
				'qty' => max( 1, $quantity ),
				'lookup_tables' => $lookup_tables,
				'formula_variables' => $formula_variables,
				'file_counts' => self::private_file_counts_for_group( $group, (array) ( $private_files[ $gid ] ?? [] ) ),
			];
			$resolved_given = Calculator::resolve_calculation_values(
				$group->data['fields'],
				$given,
				$context
			);
			$context['file_counts'] = self::formula_file_counts( $group, $resolved_given, (array) ( $private_files[ $gid ] ?? [] ) );
			$context['field_prices'] = Calculator::field_price_map( $group->data['fields'], $resolved_given, $context );
			$resolved_given = Calculator::resolve_calculation_values( $group->data['fields'], $given, $context );

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'html', 'section', 'calculation', 'upload' ], true ) ) {
					continue;
				}
				if ( ! Evaluator::is_visible( $field, $resolved_given ) ) {
					continue;
				}
				$provided = array_key_exists( $field['id'], $given );
				$value    = $provided && ! is_array( $given[ $field['id'] ] ) ? (string) $given[ $field['id'] ] : null;
				if ( ! empty( $field['repeat']['enabled'] ) ) {
					$rows = $provided && is_array( $given[ $field['id'] ] ) ? $given[ $field['id'] ] : [];
					$errors = array_merge( $errors, RepeaterField::validate( $field, $rows, $quantity, $quantity_row_limit ) );
				} elseif ( 'child_products' === $field['type'] ) {
					$available = ChildProductCatalog::products( $field, (int) $product->get_id() );
					$eligible = array_map( static fn( \WC_Product $child ): int => (int) $child->get_id(), $available );
					$selection = ChildProductSelection::quantities( $field, $provided ? $given[ $field['id'] ] : null, $eligible, max( 1, $quantity ) );
					$errors = array_merge( $errors, $selection['errors'] );
					if ( ! $selection['errors'] ) {
						foreach ( $selection['selection'] as $child_id => $child_quantity ) {
							$child_product = wc_get_product( (int) $child_id );
							if ( ! $child_product || ! $child_product->has_enough_stock( (int) $child_quantity ) ) {
								$errors[] = sprintf( '"%s" does not have enough stock for the requested quantity.', $child_product ? $child_product->get_name() : $field['label'] );
							}
						}
					}
				} elseif ( in_array( $field['type'], [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle' ], true ) ) {
					$errors = array_merge( $errors, FieldValue::validate( $field, $value, $provided ) );
				} elseif ( 'swatch' === $field['type'] && ! empty( $field['image_quantities'] ) ) {
					$errors = array_merge( $errors, FieldValue::validate_image_quantities( $field, $provided ? $given[ $field['id'] ] : [], $provided ) );
				} elseif ( 'checkbox' === $field['type'] || ( 'swatch' === $field['type'] && ! empty( $field['multiple'] ) ) ) {
					$errors = array_merge( $errors, FieldValue::validate_choices( $field, $provided ? $given[ $field['id'] ] : [], $provided ) );
				} elseif ( $field['required'] && ! $provided ) {
					$errors[] = sprintf( '"%s" is a required field.', $field['label'] );
				}
			}
		}

		return $errors;
	}

	/** Product-wide quantity repeater limit, accounting for other matched group inputs. */
	private static function quantity_row_limit_for_product( \WC_Product $product ): int {
		$fields = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			if ( ! $entry['group'] instanceof FieldGroup ) {
				continue;
			}
			$fields = array_merge( $fields, $entry['group']->data['fields'] );
		}
		return RepeaterField::quantity_row_limit( $fields );
	}

	/**
	 * Normalized uploads submitted with this request, keyed by gid → fid.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,array<string,array<int,array<string,mixed>>>>
	 */
	private static function collect_files( \WC_Product $product ): array {
		if ( ! isset( $_FILES['opf'] ) || ! is_array( $_FILES['opf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return [];
		}

		$out = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'upload' !== $field['type'] ) {
					continue;
				}
				$files = UploadService::normalize_files( $_FILES['opf'], $gid, $field['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( $files ) {
					$out[ $gid ][ $field['id'] ] = $files;
				}
			}
		}
		return $out;
	}

	/**
	 * Validate required/constraint rules for upload fields, then move the
	 * accepted files into private storage so a rejected add leaves nothing.
	 *
	 * @param \WC_Product $product   Product.
	 * @param array<int|string, array<string, mixed>> $values Sanitized values.
	 * @param bool        $inherited Whether the cart item already carries uploads restored from an order.
	 * @return string[]
	 */
	private static function validate_uploads( \WC_Product $product, array $values, bool $inherited = false ): array {
		$files       = self::collect_files( $product );
		$tokens      = self::posted_upload_tokens();
		$staged      = [];
		$errors      = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$given = isset( $values[ $gid ] ) && is_array( $values[ $gid ] ) ? $values[ $gid ] : [];
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'upload' !== $field['type'] || ! Evaluator::is_visible( $field, $given ) ) {
					continue;
				}
				$field_id     = (string) $field['id'];
				$field_files  = isset( $files[ $gid ][ $field_id ] ) ? (array) $files[ $gid ][ $field_id ] : [];
				$field_tokens = isset( $tokens[ $gid ][ $field_id ] ) ? (array) $tokens[ $gid ][ $field_id ] : [];
				foreach ( $field_tokens as $token ) {
					$stored = is_string( $token ) ? UploadService::staged_file( $token, $product->get_id(), $gid, $field_id ) : null;
					if ( null === $stored ) {
						$errors[] = sprintf( '"%s" contains an invalid or expired upload.', (string) $field['label'] );
						continue;
					}
					$staged[ $gid ][ $field_id ][] = $stored;
					$field_files[] = [ 'name' => $stored['name'], 'type' => $stored['mime'], 'size' => $stored['size'], 'error' => UPLOAD_ERR_OK, 'token' => $stored['token'] ];
				}
				if ( 0 === UploadService::attempts( $field_files ) ) {
					if ( ! empty( $field['required'] ) && ! $inherited && empty( $field['min_files'] ) ) {
						$errors[] = sprintf( '"%s" is a required field.', (string) $field['label'] );
					}
				}
				$errors = array_merge( $errors, UploadService::validate( $field_files, $field ) );
			}
		}

		$signature = self::uploads_signature( $files, $tokens );
		if ( self::$pending_uploads !== null && self::$pending_uploads_product === $product->get_id() && self::$pending_uploads_signature === $signature ) {
			// WooCommerce runs add-to-cart validation again inside add_to_cart;
			// the same submission must not be stored twice.
			return $errors;
		}

		if ( ! empty( $errors ) ) {
			self::discard_pending_uploads();
			return $errors;
		}

		return self::stage_uploads( $product, $values, $files, $signature, $staged );
	}

	/** Return sanitized staged ticket references posted beside the regular fields. */
	private static function posted_upload_tokens(): array {
		if ( is_array( self::$store_api_upload_tokens ) ) {
			$raw = self::$store_api_upload_tokens;
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = isset( $_POST['opf_upload_tokens'] ) && is_array( $_POST['opf_upload_tokens'] ) ? wp_unslash( $_POST['opf_upload_tokens'] ) : [];
		}
		$out = [];
		foreach ( $raw as $gid => $group ) {
			if ( ! is_array( $group ) ) continue;
			foreach ( $group as $fid => $items ) {
				if ( ! is_array( $items ) ) $items = [ $items ];
				foreach ( $items as $token ) {
					if ( is_scalar( $token ) && preg_match( UploadService::TOKEN_PATTERN, (string) $token ) ) {
						$out[ (string) $gid ][ (string) $fid ][] = (string) $token;
					}
				}
			}
		}
		return $out;
	}

	/** Resolve only session-owned tickets attached to upload fields on product. */
	private static function resolve_upload_tickets( \WC_Product $product, array $tokens ): array {
		$files = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'upload' !== $field['type'] ) continue;
				$fid = (string) $field['id'];
				$items = isset( $tokens[ $gid ][ $fid ] ) && is_array( $tokens[ $gid ][ $fid ] ) ? $tokens[ $gid ][ $fid ] : [];
				foreach ( $items as $token ) {
					$stored = is_scalar( $token ) ? UploadService::staged_file( (string) $token, $product->get_id(), $gid, $fid ) : null;
					if ( null !== $stored ) $files[ $gid ][ $fid ][] = $stored;
				}
			}
		}
		return $files;
	}

	/**
	 * Signature that identifies the exact submitted file set.
	 *
	 * @param array<string,array<string,array<int,array<string,mixed>>>> $files Files.
	 */
	private static function uploads_signature( array $files, array $tokens = [] ): string {
		$parts = [];
		foreach ( $files as $gid => $group ) {
			foreach ( (array) $group as $fid => $entries ) {
				foreach ( (array) $entries as $entry ) {
					if ( ! is_array( $entry ) || (int) ( $entry['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
						continue;
					}
					$parts[] = $gid . '/' . $fid . '|' . (string) ( $entry['tmp_name'] ?? '' ) . '|' . (string) ( $entry['name'] ?? '' ) . '|' . (int) ( $entry['size'] ?? 0 );
				}
			}
		}
		foreach ( $tokens as $gid => $group_tokens ) {
			foreach ( (array) $group_tokens as $fid => $field_tokens ) {
				foreach ( (array) $field_tokens as $token ) $parts[] = $gid . '/' . $fid . '|ticket|' . (string) $token;
			}
		}
		return hash( 'sha256', implode( "\n", $parts ) );
	}

	/**
	 * Delete and forget staged uploads that will not reach a cart item.
	 */
	private static function discard_pending_uploads(): void {
		if ( self::$pending_uploads === null ) {
			return;
		}
		foreach ( self::file_tokens( self::$pending_uploads ) as $token ) {
			if ( UploadService::is_staged_token( $token ) ) continue;
			UploadService::delete( $token );
		}
		self::$pending_uploads           = null;
		self::$pending_uploads_product   = 0;
		self::$pending_uploads_signature = '';
	}

	/**
	 * Store accepted uploads for the attach step. Any failure rolls the whole
	 * batch back so a rejected add cannot leave half-visible files.
	 *
	 * @param \WC_Product $product Product.
	 * @param array<int|string, array<string, mixed>> $values Sanitized values.
	 * @param array<string,array<string,array<int,array<string,mixed>>>> $files     Normalized files.
	 * @param string                                                     $signature Submitted file-set signature.
	 * @return string[]
	 */
	private static function stage_uploads( \WC_Product $product, array $values, array $files, string $signature, array $pre_staged = [] ): array {
		$stored = $pre_staged;
		$errors = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$given = isset( $values[ $gid ] ) && is_array( $values[ $gid ] ) ? $values[ $gid ] : [];
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'upload' !== $field['type'] || ! Evaluator::is_visible( $field, $given ) ) {
					continue;
				}
				$field_files = isset( $files[ $gid ][ $field['id'] ] ) ? (array) $files[ $gid ][ $field['id'] ] : [];
				if ( 0 === UploadService::attempts( $field_files ) ) {
					continue;
				}
				foreach ( $field_files as $file ) {
					if ( ! is_array( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
						continue;
					}
					$stored_file = UploadService::store( $file, $field );
					if ( null === $stored_file ) {
						$errors[] = sprintf( '"%s" contains a file that could not be stored.', (string) $field['label'] );
						continue;
					}
					$stored[ $gid ][ $field['id'] ][] = $stored_file;
				}
			}
		}

		if ( ! empty( $errors ) ) {
			foreach ( self::file_tokens( $stored ) as $token ) {
				UploadService::delete( $token );
			}
			return $errors;
		}

		self::$pending_uploads           = $stored ? $stored : null;
		self::$pending_uploads_product   = $product->get_id();
		self::$pending_uploads_signature = $signature;
		return [];
	}

	/**
	 * Flat, unique token list for a nested gid/fid/entries structure.
	 *
	 * @param array<string,array<string,array<int,array<string,mixed>>>> $files Files.
	 * @return string[]
	 */
	private static function file_tokens( array $files ): array {
		$tokens = [];
		foreach ( $files as $group_files ) {
			foreach ( (array) $group_files as $entries ) {
				foreach ( (array) $entries as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['token'] ) ) {
						$tokens[] = (string) $entry['token'];
					}
				}
			}
		}
		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Visible label/value pairs for stored uploads.
	 *
	 * @param \WC_Product $product Product.
	 * @param array<int|string, array<string, mixed>> $values Sanitized values.
	 * @param array<string,array<string,array<int,array<string,mixed>>>> $files Stored uploads.
	 * @param int $quantity Cart quantity.
	 * @param float|null $base_price Base unit price.
	 * @param float $addons Existing per-unit adjustment.
	 * @return array<int,array{label:string,value:string}>
	 */
	public static function visible_file_selections( \WC_Product $product, array $values, array $files, int $quantity = 1, ?float $base_price = null, float $addons = 0.0, string $surface = 'cart' ): array {
		$out = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid          = (string) $entry['id'];
			$group_values = isset( $values[ $gid ] ) && is_array( $values[ $gid ] ) ? $values[ $gid ] : [];
			$visible_values = self::resolved_group_values_for_display( $product, $entry['group'], $group_values, $quantity, (array) ( $files[ $gid ] ?? [] ), $base_price, $addons );
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( 'upload' !== $field['type'] || self::is_field_hidden_on_surface( $field, $surface ) || ! Evaluator::is_visible( $field, $visible_values ) ) {
					continue;
				}
				$label = '' !== (string) $field['label'] ? (string) $field['label'] : __( 'Upload', 'open-product-fields-for-woocommerce' );
				foreach ( (array) ( $files[ $gid ][ $field['id'] ] ?? [] ) as $file ) {
					if ( is_array( $file ) && ! empty( $file['name'] ) ) {
						$out[] = [
							'label' => $label,
							'value' => (string) $file['name'],
						];
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Turn a stored filename shown in order item meta into an authorized
	 * download link. WC escapes the value with wp_kses_post before output.
	 *
	 * @param string|array         $display_value Value about to display.
	 * @param object               $meta          Meta object.
	 * @param \WC_Order_Item|null  $item          Order item.
	 * @return string|array
	 */
	public static function linkify_file_meta( $display_value, $meta = null, $item = null ) {
		if ( ! is_string( $display_value ) || ! $item instanceof \WC_Order_Item_Product ) {
			return $display_value;
		}

		$stored = $item->get_meta( self::FILE_META, true );
		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = json_decode( $stored, true );
		}
		if ( ! is_array( $stored ) || ! $stored ) {
			return $display_value;
		}

		$map = [];
		foreach ( $stored as $group_files ) {
			foreach ( (array) $group_files as $entries ) {
				foreach ( (array) $entries as $file ) {
					if ( is_array( $file ) && ! empty( $file['token'] ) && isset( $file['name'] ) ) {
						$map[ (string) $file['name'] ] = (string) $file['token'];
					}
				}
			}
		}

		if ( ! isset( $map[ $display_value ] ) ) {
			return $display_value;
		}
		$token = $map[ $display_value ];
		if ( ! UploadService::viewer_can_access( $token, get_current_user_id() ) ) {
			return $display_value;
		}

		$args = [ '_wpnonce' => wp_create_nonce( 'wp_rest' ) ];
		$order = $item->get_order_id() ? wc_get_order( $item->get_order_id() ) : null;
		if ( $order instanceof \WC_Order ) {
			// Guests authenticate with the secret order key, the same
			// credential WooCommerce already uses for guest order access.
			$args['key'] = $order->get_order_key();
		}
		$url = add_query_arg( $args, rest_url( 'opf/v1/uploads/' . $token ) );
		return '<a href="' . esc_url( $url ) . '" rel="nofollow">' . esc_html( $display_value ) . '</a>';
	}

	/**
	 * Delete private files when their order is permanently deleted.
	 *
	 * @param int $order_id Order id.
	 */
	public static function delete_order_uploads( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$tokens = $order->get_meta( self::FILE_TOKENS_META, true );
		$tokens = is_array( $tokens ) ? $tokens : [];
		foreach ( $order->get_items() as $item ) {
			$item_tokens = $item->get_meta( self::FILE_TOKENS_META, true );
			if ( is_array( $item_tokens ) ) {
				$tokens = array_merge( $tokens, $item_tokens );
			}
		}

		foreach ( array_unique( $tokens ) as $token ) {
			UploadService::delete( (string) $token );
		}
	}

	/**
	 * Detect disabled choices before sanitization silently drops them.
	 *
	 * @param \WC_Product $product Product.
	 * @param array|null  $raw     Raw submitted values.
	 */
	private static function has_disabled_choice_submission( \WC_Product $product, ?array $raw ): bool {
		if ( ! is_array( $raw ) ) {
			return false;
		}

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid         = (string) $entry['id'];
			$group_values = isset( $raw[ $gid ] ) && is_array( $raw[ $gid ] ) ? $raw[ $gid ] : [];
			foreach ( $entry['group']->data['fields'] as $field ) {
				if ( ! in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) || ! array_key_exists( $field['id'], $group_values ) ) {
					continue;
				}
				$submitted_value = $group_values[ $field['id'] ];
				$submitted = 'swatch' === $field['type'] && ! empty( $field['image_quantities'] ) && is_array( $submitted_value )
					? array_map( 'strval', array_keys( $submitted_value ) )
					: array_map( 'strval', (array) $submitted_value );
				foreach ( $field['choices'] as $choice ) {
					if ( ! empty( $choice['disabled'] ) && in_array( (string) $choice['slug'], $submitted, true ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}
}
