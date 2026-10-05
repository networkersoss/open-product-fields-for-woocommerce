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

use OPF\Engine\DateFormat;

use OPF\Engine\Calculator;
use OPF\Engine\Evaluator;
use OPF\Engine\FieldGroup;
use OPF\Engine\FieldValue;
use OPF\Engine\RepeaterField;

defined( 'ABSPATH' ) || exit;

final class CartIntegration {

	/**
	 * Cart item key holding our structured data.
	 */
	public const ITEM_KEY = 'opf_fields';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_add_to_cart' ], 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'attach' ], 10, 3 );
		add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'split_quantity_repeat_cart_item' ], 10, 6 );
		add_filter( 'woocommerce_get_cart_item_from_session', [ __CLASS__, 'restore_from_session' ], 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'apply_prices' ], 20, 1 );
		// WAPF maybe_calculate_weight parity: option weights merge into the
		// cart item product's weight so shipping sees the combined mass.
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'apply_weights' ], 30, 1 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'persist_order_item' ], 10, 4 );
		add_filter( 'woocommerce_order_again_cart_item_data', [ __CLASS__, 'restore_order_again' ], 10, 3 );
		add_filter( 'woocommerce_coupon_get_apply_quantity', [ __CLASS__, 'capture_coupon_apply_quantity' ], 10, 4 );
		add_filter( 'woocommerce_coupon_get_discount_amount', [ __CLASS__, 'filter_coupon_discount_amount' ], 1000, 5 );
		// Hide internal OPF meta from admin/customer order item display.
		add_filter( 'woocommerce_hidden_order_itemmeta', [ __CLASS__, 'hidden_order_meta' ] );
		add_filter( 'woocommerce_store_api_add_to_cart_data', [ __CLASS__, 'capture_store_api' ], 10, 2 );

		// Edit-in-cart (WAPF-INTERACTION-CART-EDIT): links, prefill, replace.
		CartEdit::init();
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
		if ( null === $submitted ) {
			// Unregistered params can be stripped from the param bag; the raw
			// body is the fallback.
			$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_string( $raw ) && '' !== $raw ) {
				$parsed    = json_decode( $raw, true );
				$submitted = is_array( $parsed ) ? ( $parsed['opf_fields'] ?? null ) : null;
			}
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
				}
			);
		}
		return $add_to_cart_data;
	}

	/**
	 * Raw Store API payload for this request. Validation runs before cart item
	 * data exists, so the capture step stashes the payload here.
	 *
	 * @var array|null
	 */
	private static $store_api_raw = null;

	/** Prevent recursive quantity splitting when a per-unit cart line is added. */
	private static $splitting_quantity_repeats = false;

	/** Quantity WooCommerce is applying for the current coupon line. */
	private static $coupon_apply_quantity = 0;

	/** Capture the eligible unit count Woo passes immediately before discount calculation. */
	public static function capture_coupon_apply_quantity( $apply_quantity, $item, $coupon, $discounts ) {
		self::$coupon_apply_quantity = max( 0, (int) $apply_quantity );
		return $apply_quantity;
	}

	/**
	 * Validate on add-to-cart. Runs for classic AND Store API paths.
	 *
	 * @param bool  $passed         Whether validation passed so far.
	 * @param int   $product_id     Product id.
	 * @param int   $quantity       Quantity.
	 * @param int   $variation_id   Variation id, when supplied by WooCommerce.
	 * @param array $variation      Variation attributes.
	 * @param array $cart_item_data Existing cart data, including order-again selections.
	 * @return bool
	 */
	public static function validate_add_to_cart( bool $passed, int $product_id, int $quantity, int $variation_id = 0, array $variation = [], array $cart_item_data = [] ): bool {
		if ( ! $passed ) {
			return false;
		}

		// Child lines inserted by LinkedProducts bypass OPF validation (the
		// selection was already validated on the parent's add).
		if ( LinkedProducts::adding() ) {
			return $passed;
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

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return $passed;
		}

		// WooCommerce restores order-again values before this filter and builds
		// the cart line directly, without a fresh form/Store API submission.
		// Recheck against current definitions, preserving the structured format
		// used for image quantities rather than interpreting it as a raw POST.
		$values = null === self::$store_api_raw && isset( $cart_item_data[ self::ITEM_KEY ] ) && is_array( $cart_item_data[ self::ITEM_KEY ] )
			? self::sanitize_submitted( $product, $cart_item_data[ self::ITEM_KEY ], true )
			: self::collect_submitted( $product, self::$store_api_raw );
		self::$store_api_raw = null; // Consumed: never leak into the next add.
		// Non-visible (conditional) values never validate, price or persist.
		$values = self::drop_hidden_values( $product, $values );
		// A group that only holds a price calculation submits nothing yet
		// still prices; keep an empty entry so validation/pricing see it.
		$values = self::retain_price_calculation_groups( $values, FieldGroups::for_product( $product ) );
		$errors = self::validate_values( $product, $values, $quantity );

		foreach ( $errors as $error ) {
			wc_add_notice( $error, 'error' );
		}

		return empty( $errors );
	}

	/**
	 * Attach validated data to the cart item.
	 *
	 * @param array $cart_item_data Incoming cart item data.
	 * @param int   $product_id     Product id.
	 * @param int   $variation_id   Variation id.
	 */
	public static function attach( array $cart_item_data, int $product_id, int $variation_id = 0 ): array {
		if ( self::$splitting_quantity_repeats || LinkedProducts::adding() ) {
			return $cart_item_data;
		}

		if ( ! Renderer::visible_to_viewer() ) {
			return $cart_item_data;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
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

		$restored = null === $raw && isset( $cart_item_data[ self::ITEM_KEY ] ) && is_array( $cart_item_data[ self::ITEM_KEY ] );
		$values = $restored
			? self::sanitize_submitted( $product, $cart_item_data[ self::ITEM_KEY ], true )
			: self::collect_submitted( $product, $raw );
		// Hidden fields/sections are disabled in the browser (mirroring WAPF's
		// conditional handler). Apply the same rule to forged payloads before
		// cart/order persistence and pricing, including per-row repeats.
		$values = self::drop_hidden_values( $product, $values );
		$values = self::retain_price_calculation_groups( $values, FieldGroups::for_product( $product ) );
		$upload_errors = Uploads::validate_product( $product, $values );
		if ( $upload_errors ) {
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST && class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'opf_upload_invalid', implode( ' ', $upload_errors ), 400 );
			}
			throw new \Exception( implode( ' ', $upload_errors ) );
		}
		if ( empty( $values ) ) {
			if ( $restored ) {
				unset( $cart_item_data[ self::ITEM_KEY ], $cart_item_data['opf_base_price'], $cart_item_data['opf_base_weight'] );
			}
			return $cart_item_data;
		}
		Uploads::mark_cart( $values );

		$cart_item_data[ self::ITEM_KEY ] = $values;
		$cart_item_data['opf_base_price'] = (float) $product->get_price( 'edit' );
		// Canonical product weight at add time; apply_weights re-anchors on
		// every totals pass so repeated recalculation cannot double-add.
		$cart_item_data['opf_base_weight'] = is_callable( [ $product, 'get_weight' ] ) ? (float) $product->get_weight() : 0.0;

		return $cart_item_data;
	}

	/**
	 * Split quantity-repeated field values into per-unit cart lines, merging
	 * identical clone configurations by increasing that line's quantity.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product id.
	 * @param int    $quantity      Quantity added by this request.
	 * @param int    $variation_id  Variation id.
	 * @param array  $variation     Variation attributes.
	 * @param array  $cart_item_data Submitted cart item data.
	 */
	public static function split_quantity_repeat_cart_item( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
		if ( self::$splitting_quantity_repeats || LinkedProducts::adding() || (int) $quantity < 1 || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		$item = $cart->get_cart_item( $cart_item_key );
		$values = is_array( $cart_item_data[ self::ITEM_KEY ] ?? null )
			? $cart_item_data[ self::ITEM_KEY ]
			: ( $item[ self::ITEM_KEY ] ?? [] );
		// Items without OPF values carry nothing to split. They are foreign
		// (e.g. WAPF items on a product that also has an OPF repeat group) or
		// OPF items whose only fields went unsubmitted — rewriting their
		// quantity from the submitted count would undo another engine's split.
		if ( ! $item || ! is_array( $values ) || [] === $values ) {
			return;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return;
		}

		$quantity_fields = [];
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			$group_fields = $entry['group']->data['fields'];
			$section_repeats = self::section_repeat_context( $group_fields );
			foreach ( $group_fields as $field ) {
				$section_repeat = empty( $field['repeat']['enabled'] ) && isset( $section_repeats[ $field['id'] ] );
				$repeat = $section_repeat ? $section_repeats[ $field['id'] ] : ( $field['repeat'] ?? [] );
				if ( ! in_array( $field['type'], [ 'section', 'section_end' ], true ) && ! empty( $repeat['enabled'] ) && 'quantity' === ( $repeat['mode'] ?? '' ) ) {
					$quantity_fields[] = [ $gid, (string) $field['id'], $field, $repeat, $section_repeat ];
				}
			}
		}
		if ( ! $quantity_fields ) {
			return;
		}

		$clone_groups = [];
		for ( $unit_index = 0; $unit_index < (int) $quantity; $unit_index++ ) {
			$unit_values = $values;
			$canonical_values = $values;
			foreach ( $quantity_fields as [ $gid, $fid ] ) {
				$source_rows = $values[ $gid ][ $fid ] ?? [];
				if ( ! is_array( $source_rows ) ) {
					$source_rows = [ $source_rows ];
				}
				if ( ! array_key_exists( $unit_index, $source_rows ) || null === $source_rows[ $unit_index ] || '' === $source_rows[ $unit_index ] || [] === $source_rows[ $unit_index ] ) {
					unset( $unit_values[ $gid ][ $fid ], $canonical_values[ $gid ][ $fid ] );
					continue;
				}

				$row = $source_rows[ $unit_index ];
				// WAPF ignores clone labels for qty clones. Keep row identity
				// canonical so numbered labels cannot prevent equal rows merging.
				$unit_values[ $gid ][ $fid ] = [ 0 => $row ];
				$canonical_values[ $gid ][ $fid ] = [ 0 => $row ];
			}

			$signature = hash( 'sha256', serialize( [ (int) $product_id, (int) $variation_id, $canonical_values ] ) );
			if ( ! isset( $clone_groups[ $signature ] ) ) {
				$clone_groups[ $signature ] = [ 'values' => $unit_values, 'quantity' => 0 ];
			}
			$clone_groups[ $signature ]['quantity']++;
		}

		if ( ! $clone_groups ) {
			return;
		}

		$original_quantity = (int) ( $item['quantity'] ?? $quantity );
		$original_values = $item[ self::ITEM_KEY ] ?? $values;
		$previous_quantity = max( 0, $original_quantity - (int) $quantity );
		$first = array_shift( $clone_groups );
		$cart->cart_contents[ $cart_item_key ][ self::ITEM_KEY ] = $first['values'];
		$cart->set_quantity( $cart_item_key, $previous_quantity + $first['quantity'], false );

		if ( ! $clone_groups ) {
			return;
		}

		$added_lines = [];
		self::$splitting_quantity_repeats = true;
		$add_failed = false;
		try {
			foreach ( $clone_groups as $clone_group ) {
				$clone_data = $cart_item_data;
				$clone_data[ self::ITEM_KEY ] = $clone_group['values'];
				$clone_data['opf_base_price'] = (float) ( $item['opf_base_price'] ?? 0.0 );
				$clone_data['opf_base_weight'] = (float) ( $item['opf_base_weight'] ?? 0.0 );
				unset( $clone_data['opf_fields_raw'] );
				$previous_line_quantities = [];
				foreach ( $cart->get_cart() as $existing_key => $existing_item ) {
					$previous_line_quantities[ $existing_key ] = (int) $existing_item['quantity'];
				}
				$added_key = $cart->add_to_cart( $product_id, $clone_group['quantity'], $variation_id, $variation, $clone_data );
				if ( false === $added_key ) {
					$add_failed = true;
					break;
				}
				$added_lines[] = [
					'key' => $added_key,
					'previous_quantity' => array_key_exists( $added_key, $previous_line_quantities ) ? $previous_line_quantities[ $added_key ] : null,
				];
			}
		} finally {
			self::$splitting_quantity_repeats = false;
		}

		if ( $add_failed ) {
			foreach ( array_reverse( $added_lines ) as $added_line ) {
				if ( null === $added_line['previous_quantity'] ) {
					$cart->remove_cart_item( $added_line['key'] );
				} else {
					$cart->set_quantity( $added_line['key'], (int) $added_line['previous_quantity'], false );
				}
			}
			$cart->cart_contents[ $cart_item_key ][ self::ITEM_KEY ] = $original_values;
			$cart->set_quantity( $cart_item_key, $original_quantity, false );
		}
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

		// Same re-anchor for weight: the session-restored product object is a
		// fresh fetch, so its weight is the canonical catalog value again.
		$current_weight = $product instanceof \WC_Product && is_callable( [ $product, 'get_weight' ] ) ? $product->get_weight() : '';
		$cart_item['opf_base_weight'] = '' !== $current_weight && null !== $current_weight
			? (float) $current_weight
			: (float) ( $values['opf_base_weight'] ?? 0.0 );

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

			$quantity = max( 1, (int) $cart_item['quantity'] );
			$base     = (float) apply_filters( 'opf_cart_item_base_price', (float) $cart_item['opf_base_price'], $product, $cart_item );
			// WAPF alias bridge: wapf/pricing/base + wapf/pricing/cart_item_base.
			$base     = \OPF\Compat\WapfHooks::cart_base_price( $base, $product, $quantity, $cart_item );
			$per_unit = self::addons_per_unit( $product, $cart_item[ self::ITEM_KEY ], $base, $quantity );
			// WAPF alias bridge: wapf/pricing/cart_item_options.
			$per_unit = \OPF\Compat\WapfHooks::cart_item_options( $per_unit, $product, $quantity, $cart_item );
			$target   = max( 0.0, $base + $per_unit );

			if ( abs( (float) $product->get_price( 'edit' ) - $target ) > 0.000001 ) {
				$recursing = true;
				$product->set_price( (string) $target );
				$recursing = false;
			}
		}
	}

	/**
	 * Merge option weights into the cart item product — WAPF 3.1.5
	 * Extended_Controller::maybe_calculate_weight parity. The merged weight is
	 * what WC_Cart::get_cart_contents_weight() and shipping packages read.
	 *
	 * Where WAPF adds the delta once per request on top of the current weight,
	 * OPF anchors on the stored canonical base (`opf_base_weight`, re-derived
	 * from the session-restored product), so repeated totals passes in one
	 * request stay idempotent and `[qty]` expressions follow quantity changes.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function apply_weights( \WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::ITEM_KEY ] ) || ! isset( $cart_item['opf_base_weight'] ) ) {
				continue;
			}
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof \WC_Product || ( is_callable( [ $product, 'get_virtual' ] ) && $product->get_virtual() ) ) {
				continue;
			}
			$additional = self::addon_weight( $product, $cart_item[ self::ITEM_KEY ], max( 1, (int) $cart_item['quantity'] ) );
			$target     = max( 0.0, (float) $cart_item['opf_base_weight'] + $additional );
			$current    = is_callable( [ $product, 'get_weight' ] ) ? (float) $product->get_weight() : null;
			if ( ( null === $current || abs( $current - $target ) > 0.000001 ) && is_callable( [ $product, 'set_weight' ] ) ) {
				$product->set_weight( (string) $target );
			}
		}
	}

	/**
	 * Sum option weights for one cart line, in the store's weight unit.
	 * Fields hidden by conditional evaluation contribute nothing — WAPF never
	 * records them as cart fields, so they never reach weight math.
	 *
	 * @param \WC_Product           $product  Product.
	 * @param array<int|string, array<string, mixed>> $values gid => fid => value(s).
	 * @param int                   $quantity Line quantity.
	 */
	private static function addon_weight( \WC_Product $product, array $values, int $quantity ): float {
		$weight = 0.0;

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}
			$group_values    = (array) $values[ $gid ];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'content_image', 'section', 'section_end', 'products' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) ) {
					continue;
				}
				$weight_field = $field;
				if ( empty( $weight_field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] ) ) {
					$weight_field['repeat'] = $section_repeats[ $fid ];
				}
				if ( ! empty( $weight_field['repeat']['enabled'] ) ) {
					$instance_field = $weight_field;
					unset( $instance_field['repeat'] );
					$rows = is_array( $group_values[ $fid ] ) ? $group_values[ $fid ] : [ $group_values[ $fid ] ];
					foreach ( $rows as $row_index => $row_value ) {
						$clone_values = self::values_for_clone( $group->data['fields'], $group_values, $section_repeats, (int) $row_index );
						if ( ! Evaluator::is_visible( $field, $clone_values ) ) {
							continue;
						}
						$weight += Calculator::field_weight( $instance_field, $row_value, $quantity );
					}
					continue;
				}
				if ( ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$weight += Calculator::field_weight( $weight_field, $group_values[ $fid ], $quantity );
			}
		}

		return $weight;
	}

	/**
	 * Keep percentage coupon discounts off OPF option prices when the coupon's
	 * per-coupon exclusion is enabled. WAPF 3.1.5 stores that choice as
	 * `wapf_excl_addons`, which also preserves imported coupon behavior.
	 *
	 * @param float       $discount           Proposed discount amount.
	 * @param float       $discounting_amount Eligible amount after Woo limits.
	 * @param array       $cart_item          Cart line.
	 * @param bool        $single             Whether this is a single-item discount.
	 * @param object|null $coupon             Coupon being calculated.
	 */
	public static function filter_coupon_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( ! \OPF\Service\Admin\CouponSettings::coupon_excludes_addons( $coupon ) ) {
			return $discount;
		}
		if ( ! is_object( $coupon ) || ! method_exists( $coupon, 'is_type' ) || ! $coupon->is_type( 'percent' ) ) {
			return $discount;
		}
		if ( ! is_array( $cart_item ) || ! isset( $cart_item['opf_base_price'], $cart_item['data'] ) || ! $cart_item['data'] instanceof \WC_Product || ! method_exists( $coupon, 'get_amount' ) ) {
			return $discount;
		}

		return self::base_only_percent_discount(
			(float) $discounting_amount,
			(float) $cart_item['opf_base_price'],
			(float) $cart_item['data']->get_price( 'edit' ),
			max( 0, (int) self::$coupon_apply_quantity ),
			(float) $coupon->get_amount()
		);
	}

	/**
	 * Calculate the discount on the eligible base-price share of a line.
	 * WooCommerce's amount already includes prior sequential reductions; remove
	 * the unchanged option add-on for each eligible unit before taking the
	 * current coupon percentage.
	 */
	public static function base_only_percent_discount( float $discounting_amount, float $base_unit_price, float $adjusted_unit_price, int $eligible_quantity, float $percent ): float {
		if ( $discounting_amount <= 0 || $base_unit_price < 0 || $adjusted_unit_price <= 0 || $percent <= 0 ) {
			return 0.0;
		}

		$addon_unit_price = $adjusted_unit_price - $base_unit_price;
		$eligible_base    = max( 0.0, $discounting_amount - ( $addon_unit_price * max( 0, $eligible_quantity ) ) );
		// WooCommerce rounds the returned amount; premature flooring loses cents.
		$base_discount = $eligible_base * ( $percent / 100 );
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
	public static function addons_per_unit( \WC_Product $product, array $values, float $base, int $quantity ): float {
		$per_unit = 0.0;
		$field_prices = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}

			$group_values = (array) $values[ $gid ];
			// WAPF `[field.X]` resolves to the submitted value's label, not its slug.
			$field_labels = [];
			foreach ( $group->data['fields'] as $label_field ) {
				foreach ( (array) ( $label_field['choices'] ?? [] ) as $label_choice ) {
					if ( isset( $label_choice['slug'], $label_choice['label'] ) ) {
						$field_labels[ strtolower( (string) $label_field['id'] ) ][ (string) $label_choice['slug'] ] = (string) $label_choice['label'];
					}
				}
			}
			// WAPF prices cost calculations from server-computed results, not
			// the client-submitted display value — and a calc needs no input to
			// price. Resolve calculations (dependency-ordered, cycle-safe) and
			// merge only their keys so conditional rules on calc results see
			// computed values while repeat-row submissions stay untouched.
			$resolved = Calculator::resolve_calculation_values(
				$group->data['fields'],
				$group_values,
				[
					'price'         => $base,
					'qty'           => $quantity,
					'addons'        => $per_unit,
					'product_id'    => $product->get_id(),
					'field_prices'  => $field_prices,
					'field_labels'  => $field_labels,
					'variables'     => is_array( $group->data['variables'] ?? null ) ? $group->data['variables'] : [],
					'fields'        => $group->data['fields'],
					'lookup_tables' => is_array( $group->data['lookup_tables'] ?? null ) ? $group->data['lookup_tables'] : [],
				]
			);
			foreach ( $group->data['fields'] as $calc_field ) {
				if ( ! in_array( (string) ( $calc_field['type'] ?? '' ), [ 'calc', 'calculation' ], true ) ) {
					continue;
				}
				$calc_id = strtolower( (string) $calc_field['id'] );
				if ( array_key_exists( $calc_id, $resolved )
					&& self::field_visibility( $calc_field, $group->data['fields'], $group_values ) ) {
					$group_values[ $calc_field['id'] ] = $resolved[ $calc_id ];
				} else {
					unset( $group_values[ $calc_field['id'] ] );
				}
			}

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'section', 'section_end', 'products' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) ) {
					continue;
				}
				$priced_field = $field;
				if ( empty( $priced_field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] ) ) {
					$priced_field['repeat'] = $section_repeats[ $fid ];
				}
				if ( ! empty( $priced_field['repeat']['enabled'] ) ) {
					$instance_field = $priced_field;
					unset( $instance_field['repeat'] );
					$rows = is_array( $group_values[ $fid ] ) ? $group_values[ $fid ] : [ $group_values[ $fid ] ];
					$row_prices = [];
					foreach ( $rows as $row_index => $row_value ) {
						$clone_values = self::values_for_clone( $group->data['fields'], $group_values, $section_repeats, (int) $row_index );
						if ( ! Evaluator::is_visible( $field, $clone_values ) ) {
							continue;
						}
						$clone_prices = [];
						foreach ( $field_prices as $previous_id => $previous_price ) {
							$clone_prices[ $previous_id ] = is_array( $previous_price )
								? (float) ( $previous_price[ $row_index ] ?? 0.0 )
								: $previous_price;
						}
						$row_addon = Calculator::field_addon(
							$instance_field,
							$row_value,
							[
								'price'        => $base,
								'qty'          => $quantity,
								'addons'       => $per_unit,
								'field_values' => $clone_values,
								'field_prices' => $clone_prices,
								'field_labels' => $field_labels,
								'product_id'   => $product->get_id(),
								'variables'    => is_array( $group->data['variables'] ?? null ) ? $group->data['variables'] : [],
								'fields'       => $group->data['fields'],
								// WAPF clone_type=qty → qty_based do_pricing row.
								'qty_based'    => 'quantity' === (string) ( $priced_field['repeat']['mode'] ?? '' ),
							]
						);
						$row_prices[ $row_index ] = $row_addon;
						$per_unit += $row_addon;
					}
					if ( ! array_key_exists( $fid, $field_prices ) ) {
						$field_prices[ $fid ] = $row_prices;
					}
					continue;
				}
				if ( ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$field_addon = Calculator::field_addon(
					$priced_field,
					$group_values[ $fid ],
					[
						'price'  => $base,
						'qty'    => $quantity,
						'addons' => $per_unit,
						'field_values' => $group_values,
						'field_prices' => $field_prices,
						'field_labels' => $field_labels,
						'product_id' => $product->get_id(),
						'variables'  => is_array( $group->data['variables'] ?? null ) ? $group->data['variables'] : [],
						'fields'     => $group->data['fields'],
					]
				);
				if ( ! array_key_exists( $fid, $field_prices ) ) {
					$field_prices[ $fid ] = $field_addon;
				}
				$per_unit += $field_addon;
			}
		}

		return apply_filters( 'opf_addon_price', $per_unit, $product, $values, $base );
	}

	/**
	 * Cart (classic) + block cart/checkout display.
	 *
	 * WAPF-DISPLAY-PRICE-HINTS parity: `value` stays the plain label join
	 * (values_to_simple_string 'cart'), `display` carries the per-value
	 * `<span class="opf-pricing-hint">` markup (values_to_display_string).
	 *
	 * @param array $other_data Display data so far.
	 * @param array $cart_item  Cart item.
	 * @return array<int,array{name:string,value:string,display:string}>
	 */
	public static function display_item_data( array $other_data, array $cart_item ): array {
		if ( empty( $cart_item[ self::ITEM_KEY ] ) ) {
			return $other_data;
		}

		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return $other_data;
		}

		foreach ( self::visible_selections( $product, $cart_item[ self::ITEM_KEY ], self::item_data_context(), $cart_item ) as $selection ) {
			$other_data[] = [
				'name'    => $selection['label'],
				'value'   => $selection['value'],
				// WAPF values_to_display_string parity: priced values render
				// `label <span class="opf-pricing-hint">(+…)</span>`; `value`
				// stays the plain label join. Empty when nothing is priced.
				'display' => self::cart_selection_display( $selection ),
			];
		}

		// WAPF alias bridge: wapf/cart/item_data.
		return \OPF\Compat\WapfHooks::cart_item_data( $other_data, $cart_item );
	}

	/**
	 * Which customer-facing surface is asking for cart item data. Mirrors
	 * WAPF Extended's context detection: classic template tags first, then
	 * the mini-cart Ajax heuristic, then the Store API route (Cart block /
	 * Checkout block), with the REST referer as the last signal.
	 *
	 * @return 'cart'|'checkout'|'mini_cart'|null
	 */
	private static function item_data_context(): ?string {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}
		// Classic checkout's order-review Ajax reports is_checkout() itself
		// (WOOCOMMERCE_CHECKOUT); every other Ajax item-data request is the
		// mini cart — WAPF hides hide_cart values there too.
		if ( wp_doing_ajax() && ( ! isset( $_GET['wc-ajax'] ) || 'update_order_review' !== $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return 'mini_cart';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
			if ( false !== strpos( $route, '/cart' ) ) {
				return 'cart';
			}
			if ( false !== strpos( $route, '/checkout' ) ) {
				return 'checkout';
			}
			$ref = function_exists( 'wp_get_referer' ) ? wp_get_referer() : false;
			if ( is_string( $ref ) && '' !== $ref && function_exists( 'wc_get_page_id' ) ) {
				$ref_id = url_to_postid( $ref );
				if ( 0 < $ref_id ) {
					if ( $ref_id === (int) wc_get_page_id( 'cart' ) ) {
						return 'cart';
					}
					if ( $ref_id === (int) wc_get_page_id( 'checkout' ) ) {
						return 'checkout';
					}
				}
			}
		}
		return null;
	}

	/**
	 * Whether a field's hide_* flag suppresses it on the current surface.
	 * Unknown surfaces keep legacy behaviour (always shown).
	 *
	 * @param array<string,mixed> $field   Normalized field.
	 * @param string|null         $context cart|checkout|mini_cart|order|null.
	 */
	private static function hidden_in_context( array $field, ?string $context ): bool {
		if ( 'cart' === $context || 'mini_cart' === $context ) {
			return ! empty( $field['hide_cart'] );
		}
		if ( 'checkout' === $context ) {
			return ! empty( $field['hide_checkout'] );
		}
		if ( 'order' === $context ) {
			return ! empty( $field['hide_order'] );
		}
		return false;
	}

	/**
	 * Visible label/value pairs for a cart item's selections.
	 *
	 * @param \WC_Product         $product   Product.
	 * @param array<int|string, array<string, mixed>> $values Stored values.
	 * @param string|null         $context   Surface context; null detects the
	 *                                       cart/checkout surface, 'order'
	 *                                       applies hide_order for order meta.
	 * @param array<string,mixed>|null $cart_item Cart line — when given, each
	 *                                       selection carries a `segments`
	 *                                       list of per-value
	 *                                       [label, slug, hint] rows whose
	 *                                       `hint` is the WAPF `pricing_hint`
	 *                                       parity string ('' when unpriced
	 *                                       or suppressed).
	 * @return array<int,array{label:string,value:string,field:array<string,mixed>,segments:array<int,array{label:string,slug:string,hint:string}>}>
	 */
	public static function visible_selections( \WC_Product $product, array $values, ?string $context = null, ?array $cart_item = null ): array {
		$out = [];
		// Per-value hint strings priced against the stored cart line; keyed
		// gid => fid => row index (0 for non-repeated) => segment index.
		$hints = null !== $cart_item ? self::value_pricing_hints( $product, $values, $cart_item ) : [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}
			$group_values = (array) $values[ $gid ];

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'section', 'section_end' ], true ) ) {
					continue;
				}
				// Linked products display as their own cart lines carrying an
				// "Included with" note — the parent line doesn't list them
				// (WAPF should_use_cart_field parity).
				if ( 'products' === $field['type'] ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) || ! Evaluator::is_visible( $field, $group_values ) || self::hidden_in_context( $field, $context ) ) {
					continue;
				}
				$raw = $group_values[ $fid ];
				$section_repeat = empty( $field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] );
				$repeat_field = $field;
				if ( $section_repeat ) {
					$repeat_field['repeat'] = $section_repeats[ $fid ];
				}
				if ( ! empty( $repeat_field['repeat']['enabled'] ) ) {
					foreach ( (array) $raw as $index => $row ) {
						if ( null === $row || '' === $row || [] === $row ) {
							continue;
						}
						$row_display = self::display_value( $field, $row );
						if ( '' !== $row_display ) {
							$label = self::repeated_selection_label( $repeat_field, $section_repeat, (int) $index );
							$out[] = [
								'label'    => $label,
								'value'    => $row_display,
								'field'    => $field,
								'segments' => self::selection_segments( $field, $row, $hints[ $gid ][ $fid ][ (int) $index ] ?? [] ),
							];
						}
					}
					continue;
				} elseif ( '' === $raw || [] === $raw ) {
					continue;
				} else {
					$value = self::display_value( $field, $raw );
				}
				if ( '' !== $value ) {
					$out[] = [
						'label'    => $field['label'],
						'value'    => $value,
						'field'    => $field,
						'segments' => self::selection_segments( $field, $raw, $hints[ $gid ][ $fid ][0] ?? [] ),
					];
				}
			}
		}

		return $out;
	}

	/**
	 * WAPF labels quantity clones only in the frontend; cart/order field labels
	 * are rewritten only for button clones.
	 *
	 * @param array<string,mixed> $field Field with effective repeat settings.
	 */
	private static function repeated_selection_label( array $field, bool $section_repeat, int $index ): string {
		$label = (string) ( $field['label'] ?? '' );
		$repeat = $field['repeat'] ?? [];
		if ( $index < 1 || 'button' !== ( $repeat['mode'] ?? '' ) || empty( $repeat['label'] ) ) {
			return $label;
		}

		$repeat_label = str_replace( '{n}', (string) ( $index + 1 ), (string) $repeat['label'] );
		return $section_repeat ? $repeat_label . ' - ' . $label : $repeat_label;
	}

	/**
	 * Human display for a stored value (choice labels, not slugs).
	 *
	 * @param array<string,mixed> $field Field data.
	 * @param string|array        $raw   Stored value.
	 */
	private static function display_value( array $field, $raw ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- called from visible_selections().
		if ( 'upload' === $field['type'] ) {
			return Uploads::display( is_array( $raw ) ? $raw : [] );
		}
		if ( 'image_quantity' === $field['type'] ) {
			$map = [];
			$quantities = is_array( $raw ) ? ( $raw['quantities'] ?? [] ) : [];
			foreach ( $field['choices'] as $choice ) {
				$count = (int) ( $quantities[ $choice['slug'] ] ?? 0 );
				if ( $count > 0 ) {
					$map[] = $choice['label'] . ': ' . $count;
				}
			}
			return implode( ', ', $map );
		}
		if ( 'calc' === $field['type'] ) {
			return self::display_calc( $field, $raw );
		}
		if ( 'date' === $field['type'] ) {
			return DateFormat::format( (string) $raw, DateFormat::configured() );
		}
		if ( 'toggle' === $field['type'] ) {
			return '1' === $raw
				? __( 'Yes', 'open-product-fields-for-woocommerce' )
				: __( 'No', 'open-product-fields-for-woocommerce' );
		}
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
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
	 * Render a stored calc result through its WAPF `result_text`/`result_format`
	 * template. `cost` calcs display as currency; informational calcs honor the
	 * number-formatted or unformatted result.
	 *
	 * @param array<string,mixed> $field Normalized calc field.
	 * @param mixed               $raw   Stored raw numeric result.
	 */
	private static function display_calc( array $field, $raw ): string {
		$result = is_numeric( $raw ) ? (float) $raw : 0.0;
		$formatted = self::format_calc_result( $field, $result );
		$text = (string) ( $field['result_text'] ?? '{result}' );
		if ( '' === trim( $text ) ) {
			$text = '{result}';
		}
		return trim( str_replace( '{result}', $formatted, $text ) );
	}

	/**
	 * Format one calc result. Cost calcs use the store currency (wc_price when
	 * available); informational calcs use the configured price decimals or the
	 * verbatim value for `result_format=none` (WAPF formatNumber parity).
	 *
	 * @param array<string,mixed> $field  Normalized calc field.
	 * @param float               $result Computed result.
	 */
	private static function format_calc_result( array $field, float $result ): string {
		if ( 'cost' === (string) ( $field['calc_type'] ?? 'default' ) ) {
			if ( function_exists( 'wc_price' ) ) {
				$html = \wc_price( $result );
				return function_exists( 'html_entity_decode' ) ? html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) : wp_strip_all_tags( (string) $html );
			}
			$decimals = function_exists( 'wc_get_price_decimals' ) ? \wc_get_price_decimals() : 2;
			return number_format( $result, $decimals, '.', ',' );
		}
		if ( 'none' === ( $field['result_format'] ?? 'number' ) ) {
			$plain = rtrim( rtrim( number_format( $result, 10, '.', '' ), '0' ), '.' );
			return '' === $plain || '-' === $plain ? '0' : $plain;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? \wc_get_price_decimals() : 2;
		return number_format( $result, $decimals, '.', ',' );
	}

	/**
	 * Per-value display breakdown for one stored field value — the parallel
	 * of display_value() that keeps each joined segment separate so the cart
	 * and order paths can attach a pricing hint per value
	 * (WAPF-DISPLAY-PRICE-HINTS: WAPF cart fields carry values[] with a
	 * label/price/hint per selected value).
	 *
	 * Each segment: label (the exact text display_value() would join in),
	 * slug (choice slug or ''), val (the WAPF $v equivalent: choice label,
	 * entered image-quantity count, or the raw scalar), pricing (the pricing
	 * block to charge — choice pricing for choice controls, field pricing for
	 * scalars — or null when the segment can never carry a charge), scalar
	 * (route to field_pricing_addon vs choice_addon) and disabled.
	 *
	 * @param array<string,mixed> $field Normalized field.
	 * @param string|array        $raw   Stored value.
	 * @return array<int,array{label:string,slug:string,val:string,pricing:?array,scalar:bool,disabled:bool}>
	 */
	private static function display_segments( array $field, $raw ): array {
		$type = (string) ( $field['type'] ?? '' );

		if ( 'upload' === $type ) {
			return [ self::segment( Uploads::display( is_array( $raw ) ? $raw : [] ) ) ];
		}

		if ( 'image_quantity' === $type ) {
			$segments   = [];
			$quantities = is_array( $raw ) ? ( $raw['quantities'] ?? [] ) : [];
			foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
				$count = (int) ( $quantities[ $choice['slug'] ] ?? 0 );
				if ( $count > 0 ) {
					// WAPF passes the entered count as $v so nr/nrq/[x]
					// formulas price per unit (Calculator::field_addon parity).
					$segments[] = self::segment(
						(string) $choice['label'] . ': ' . $count,
						(string) $choice['slug'],
						is_array( $choice['pricing'] ?? null ) ? $choice['pricing'] : null,
						(string) $count,
						false,
						! empty( $choice['disabled'] )
					);
				}
			}
			return $segments;
		}

		if ( 'calc' === $type ) {
			// Cost calcs never carry a value hint (WAPF force-hides them via
			// hide_price_hint); the result text already formats the amount.
			return [ self::segment( self::display_calc( $field, $raw ) ) ];
		}

		if ( 'date' === $type ) {
			return [ self::segment( DateFormat::format( (string) $raw, DateFormat::configured() ), '', self::field_pricing( $field ), (string) $raw, true ) ];
		}

		if ( 'toggle' === $type ) {
			$label = '1' === $raw
				? __( 'Yes', 'open-product-fields-for-woocommerce' )
				: __( 'No', 'open-product-fields-for-woocommerce' );
			// field_addon charges a toggle only while checked ('1').
			return [ self::segment( $label, '', '1' === $raw ? self::field_pricing( $field ) : null, (string) $raw, true ) ];
		}

		if ( in_array( $type, [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			$slugs    = is_array( $raw ) ? $raw : [ $raw ];
			$segments = [];
			foreach ( $slugs as $slug ) {
				foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
					if ( (string) $choice['slug'] === (string) $slug ) {
						$segments[] = self::segment(
							(string) $choice['label'],
							(string) $choice['slug'],
							is_array( $choice['pricing'] ?? null ) ? $choice['pricing'] : null,
							(string) ( $choice['label'] ?? '' ),
							false,
							! empty( $choice['disabled'] )
						);
						break;
					}
				}
			}
			return $segments;
		}

		return [ self::segment( (string) $raw, '', self::field_pricing( $field ), is_scalar( $raw ) ? (string) $raw : '', true ) ];
	}

	/**
	 * One display segment row.
	 *
	 * @return array{label:string,slug:string,val:string,pricing:?array,scalar:bool,disabled:bool}
	 */
	private static function segment( string $label, string $slug = '', ?array $pricing = null, string $val = '', bool $scalar = true, bool $disabled = false ): array {
		return [
			'label'    => $label,
			'slug'     => $slug,
			'val'      => $val,
			'pricing'  => $pricing,
			'scalar'   => $scalar,
			'disabled' => $disabled,
		];
	}

	/** Field-level pricing block when it can actually charge. */
	private static function field_pricing( array $field ): ?array {
		return is_array( $field['pricing'] ?? null ) ? $field['pricing'] : null;
	}

	/**
	 * Attach hint strings to a field value's display segments.
	 *
	 * @param array<string,mixed>          $field Normalized field.
	 * @param string|array                 $raw   Stored value.
	 * @param array<int|string,string>     $hints Segment index => hint string.
	 * @return array<int,array{label:string,slug:string,hint:string}>
	 */
	private static function selection_segments( array $field, $raw, array $hints ): array {
		$segments = [];
		foreach ( self::display_segments( $field, $raw ) as $index => $segment ) {
			$segments[] = [
				'label' => $segment['label'],
				'slug'  => $segment['slug'],
				'hint'  => (string) ( $hints[ $index ] ?? '' ),
			];
		}
		return $segments;
	}

	/**
	 * Cart/checkout display string for a selection — WAPF
	 * `values_to_display_string` parity: `label <span class="opf-pricing-
	 * hint">hint</span>` per priced value, joined ', '. Returns '' when no
	 * value carries a hint so unpriced fields keep the historical display.
	 *
	 * @param array<string,mixed> $selection One visible_selections() row.
	 */
	private static function cart_selection_display( array $selection ): string {
		$has_hint = false;
		$parts    = [];
		foreach ( (array) ( $selection['segments'] ?? [] ) as $segment ) {
			$part = $segment['label'];
			if ( '' !== (string) ( $segment['hint'] ?? '' ) ) {
				$has_hint = true;
				$part    .= ' <span class="opf-pricing-hint">' . $segment['hint'] . '</span>';
			}
			$parts[] = $part;
		}
		return $has_hint ? implode( ', ', $parts ) : '';
	}

	/**
	 * Order-meta display string for a selection — WAPF
	 * `values_to_simple_string( ..., 'order' )` parity: plain `label hint`
	 * per priced value (no span) so raw meta reads `Gold (+&#36;5.00)`.
	 * Falls back to the stored display value when nothing is priced.
	 *
	 * @param array<string,mixed> $selection One visible_selections() row.
	 */
	private static function order_selection_value( array $selection ): string {
		$has_hint = false;
		$parts    = [];
		foreach ( (array) ( $selection['segments'] ?? [] ) as $segment ) {
			$part = $segment['label'];
			if ( '' !== (string) ( $segment['hint'] ?? '' ) ) {
				$has_hint = true;
				$part    .= ' ' . $segment['hint'];
			}
			$parts[] = $part;
		}
		return $has_hint ? implode( ', ', $parts ) : (string) ( $selection['value'] ?? '' );
	}

	/**
	 * Flat per-field value breakdown for a cart line — the `_wapf_meta`
	 * fields.*.values[] parity surface consumed by the order snapshot
	 * (pricing_hint is '' when suppressed or unpriced).
	 *
	 * @param \WC_Product                  $product   Product.
	 * @param array<int|string, array<string, mixed>> $values Stored values.
	 * @param array<string,mixed>|null     $cart_item Cart line for pricing context.
	 * @return array<string,array<string,array<int,array{label:string,slug:string,pricing_hint:string,row:int}>>>
	 */
	public static function selection_value_breakdown( \WC_Product $product, array $values, ?array $cart_item = null ): array {
		$out   = [];
		$hints = null !== $cart_item ? self::value_pricing_hints( $product, $values, $cart_item ) : [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}
			$group_values    = (array) $values[ $gid ];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'section', 'section_end', 'products' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) ) {
					continue;
				}
				$raw           = $group_values[ $fid ];
				$repeat_field  = $field;
				if ( empty( $repeat_field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] ) ) {
					$repeat_field['repeat'] = $section_repeats[ $fid ];
				}
				$is_repeat = ! empty( $repeat_field['repeat']['enabled'] ) && is_array( $raw );
				$rows      = $is_repeat ? $raw : [ 0 => $raw ];
				foreach ( $rows as $row_index => $row ) {
					if ( null === $row || '' === $row || [] === $row ) {
						continue;
					}
					foreach ( self::selection_segments( $field, $row, $hints[ $gid ][ $fid ][ $is_repeat ? (int) $row_index : 0 ] ?? [] ) as $segment ) {
						$entry_row = [
							'label'        => $segment['label'],
							'slug'         => $segment['slug'],
							'pricing_hint' => $segment['hint'],
						];
						if ( $is_repeat ) {
							$entry_row['row'] = (int) $row_index;
						}
						$out[ $gid ][ $fid ][] = $entry_row;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a field must never surface a per-value pricing hint — WAPF
	 * force-flags `hide_price_hint` on cost calcs; sections never carry a
	 * charge. Both the normalized `hide_price_hint` key and the raw WAPF
	 * `options.hide_price_hint` spelling are honored.
	 *
	 * @param array<string,mixed> $field Normalized field.
	 */
	private static function field_hint_suppressed( array $field ): bool {
		if ( in_array( (string) ( $field['type'] ?? '' ), [ 'calc', 'calculation', 'section', 'section_end' ], true ) ) {
			return true;
		}
		return ! empty( $field['hide_price_hint'] ) || ! empty( $field['options']['hide_price_hint'] );
	}

	/**
	 * Whether a pricing block carries a configured charge — WAPF's
	 * `price === 0 || price_type === 'none'` skip: zero-amount fixed/percent
	 * and empty formulas render no hint.
	 *
	 * @param array<string,mixed>|null $pricing Normalized pricing block.
	 */
	private static function pricing_has_charge( ?array $pricing ): bool {
		if ( null === $pricing ) {
			return false;
		}
		$type = (string) ( $pricing['type'] ?? 'none' );
		if ( 'none' === $type || '' === $type ) {
			return false;
		}
		if ( 'formula' === $type ) {
			return '' !== trim( (string) ( $pricing['formula'] ?? '' ) );
		}
		return ! empty( $pricing['amount'] );
	}

	/**
	 * Per-value pricing hints for a cart line, keyed gid => fid => row index
	 * (0 for non-repeated fields) => segment index => hint string.
	 *
	 * Mirrors addons_per_unit()'s walk — same calc resolution, running
	 * `addons`, field_prices accumulation and per-row clone context — so a
	 * hint is priced against exactly the inputs apply_prices() charges.
	 * calc_price is the PER-UNIT contribution (WAPF do_pricing space); the
	 * hint is formatted with for_page='cart' once and shared by the cart
	 * item_data display and the order meta, exactly like WAPF stores
	 * `pricing_hint` on the cart field values.
	 *
	 * @param \WC_Product                  $product   Product.
	 * @param array<int|string, array<string, mixed>> $values Stored values.
	 * @param array<string,mixed>          $cart_item Cart line (base + qty).
	 * @return array<string,array<string,array<int,array<int,string>>>>
	 */
	private static function value_pricing_hints( \WC_Product $product, array $values, array $cart_item ): array {
		$map = [];
		if ( ! PricingHints::enabled() ) {
			return $map;
		}

		$quantity = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
		$base     = isset( $cart_item['opf_base_price'] )
			? (float) $cart_item['opf_base_price']
			: ( is_callable( [ $product, 'get_price' ] ) ? (float) $product->get_price( 'edit' ) : 0.0 );
		// Same base apply_prices() charges: filters see the stored raw base.
		$base = (float) apply_filters( 'opf_cart_item_base_price', $base, $product, $cart_item );
		$base = \OPF\Compat\WapfHooks::cart_base_price( $base, $product, $quantity, $cart_item );

		$per_unit     = 0.0;
		$field_prices = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );
			if ( ! isset( $values[ $gid ] ) ) {
				continue;
			}

			$group_values = (array) $values[ $gid ];
			$field_labels = [];
			foreach ( $group->data['fields'] as $label_field ) {
				foreach ( (array) ( $label_field['choices'] ?? [] ) as $label_choice ) {
					if ( isset( $label_choice['slug'], $label_choice['label'] ) ) {
						$field_labels[ strtolower( (string) $label_field['id'] ) ][ (string) $label_choice['slug'] ] = (string) $label_choice['label'];
					}
				}
			}
			// Resolve cost calcs exactly like addons_per_unit() so [field.X]
			// formulas see server-computed values.
			$resolved = Calculator::resolve_calculation_values(
				$group->data['fields'],
				$group_values,
				[
					'price'         => $base,
					'qty'           => $quantity,
					'addons'        => $per_unit,
					'product_id'    => $product->get_id(),
					'field_prices'  => $field_prices,
					'field_labels'  => $field_labels,
					'variables'     => is_array( $group->data['variables'] ?? null ) ? $group->data['variables'] : [],
					'fields'        => $group->data['fields'],
					'lookup_tables' => is_array( $group->data['lookup_tables'] ?? null ) ? $group->data['lookup_tables'] : [],
				]
			);
			foreach ( $group->data['fields'] as $calc_field ) {
				if ( ! in_array( (string) ( $calc_field['type'] ?? '' ), [ 'calc', 'calculation' ], true ) ) {
					continue;
				}
				$calc_id = strtolower( (string) $calc_field['id'] );
				if ( array_key_exists( $calc_id, $resolved )
					&& self::field_visibility( $calc_field, $group->data['fields'], $group_values ) ) {
					$group_values[ $calc_field['id'] ] = $resolved[ $calc_id ];
				} else {
					unset( $group_values[ $calc_field['id'] ] );
				}
			}

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'section', 'section_end', 'products' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $group_values ) ) {
					continue;
				}
				$priced_field = $field;
				if ( empty( $priced_field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] ) ) {
					$priced_field['repeat'] = $section_repeats[ $fid ];
				}
				$suppressed = self::field_hint_suppressed( $field );

				if ( ! empty( $priced_field['repeat']['enabled'] ) ) {
					$instance_field = $priced_field;
					unset( $instance_field['repeat'] );
					$rows = is_array( $group_values[ $fid ] ) ? $group_values[ $fid ] : [ $group_values[ $fid ] ];
					$row_prices = [];
					foreach ( $rows as $row_index => $row_value ) {
						$clone_values = self::values_for_clone( $group->data['fields'], $group_values, $section_repeats, (int) $row_index );
						if ( ! Evaluator::is_visible( $field, $clone_values ) ) {
							continue;
						}
						$clone_prices = [];
						foreach ( $field_prices as $previous_id => $previous_price ) {
							$clone_prices[ $previous_id ] = is_array( $previous_price )
								? (float) ( $previous_price[ $row_index ] ?? 0.0 )
								: $previous_price;
						}
						$context = [
							'price'        => $base,
							'qty'          => $quantity,
							'addons'       => $per_unit,
							'field_values' => $clone_values,
							'field_prices' => $clone_prices,
							'field_labels' => $field_labels,
							'product_id'   => $product->get_id(),
							'variables'    => is_array( $group->data['variables'] ?? null ) ? $group->data['variables'] : [],
							'fields'       => $group->data['fields'],
							'qty_based'    => 'quantity' === (string) ( $priced_field['repeat']['mode'] ?? '' ),
						];
						if ( ! $suppressed ) {
							$map[ $gid ][ $fid ][ (int) $row_index ] = self::segment_hints( $field, $row_value, $context, $product );
						}
						$row_addon = Calculator::field_addon( $instance_field, $row_value, $context );
						$row_prices[ $row_index ] = $row_addon;
						$per_unit += $row_addon;
					}
					if ( ! array_key_exists( $fid, $field_prices ) ) {
						$field_prices[ $fid ] = $row_prices;
					}
					continue;
				}

				if ( ! Evaluator::is_visible( $field, $group_values ) ) {
					continue;
				}
				$context = [
					'price'        => $base,
					'qty'          => $quantity,
					'addons'       => $per_unit,
					'field_values' => $group_values,
					'field_prices' => $field_prices,
					'field_labels' => $field_labels,
					'product_id'   => $product->get_id(),
					'variables'    => is_array( $group->data['variables'] ?? null ) ? $group->data['variables'] : [],
					'fields'       => $group->data['fields'],
				];
				if ( ! $suppressed ) {
					$map[ $gid ][ $fid ][0] = self::segment_hints( $field, $group_values[ $fid ], $context, $product );
				}
				$field_addon = Calculator::field_addon( $priced_field, $group_values[ $fid ], $context );
				if ( ! array_key_exists( $fid, $field_prices ) ) {
					$field_prices[ $fid ] = $field_addon;
				}
				$per_unit += $field_addon;
			}
		}

		return $map;
	}

	/**
	 * Hint string per display segment of one stored value — the per-value
	 * `calc_price` of WAPF's cart loop: choice segments route to
	 * choice_addon, scalar segments to field_pricing_addon, then the result
	 * is formatted with for_page='cart' (WAPF computes it once at pricing
	 * time and reuses it for cart display and order meta).
	 *
	 * @param array<string,mixed> $field   Normalized field.
	 * @param string|array        $raw     Stored value.
	 * @param array<string,mixed> $context Calculator context for this field/row.
	 * @param \WC_Product         $product Product.
	 * @return array<int,string> Segment index => hint ('' when unpriced).
	 */
	private static function segment_hints( array $field, $raw, array $context, \WC_Product $product ): array {
		$hints     = [];
		$qty_based = ! empty( $context['qty_based'] );
		$options   = array_intersect_key( $context, array_flip( [ 'variables', 'fields', 'lookup_tables', 'formula_variables' ] ) );
		// WAPF feeds each value the running options_total — a multi-select
		// field's second [addons] formula sees its own first segment priced.
		$addons    = (float) $context['addons'];

		foreach ( self::display_segments( $field, $raw ) as $index => $segment ) {
			$hints[ $index ] = '';
			$pricing = $segment['pricing'];
			if ( $segment['disabled'] || ! self::pricing_has_charge( $pricing ) ) {
				continue;
			}
			if ( $segment['scalar'] ) {
				$calc_price = Calculator::field_pricing_addon(
					$pricing,
					$segment['val'],
					(float) $context['price'],
					max( 1, (int) $context['qty'] ),
					$addons,
					is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [],
					(int) ( $context['product_id'] ?? 0 ),
					is_array( $context['field_prices'] ?? null ) ? $context['field_prices'] : [],
					$qty_based,
					is_array( $context['field_labels'] ?? null ) ? $context['field_labels'] : [],
					$options
				);
			} else {
				$calc_price = Calculator::choice_addon(
					$pricing,
					(float) $context['price'],
					max( 1, (int) $context['qty'] ),
					$addons,
					is_array( $context['field_values'] ?? null ) ? $context['field_values'] : [],
					(int) ( $context['product_id'] ?? 0 ),
					is_array( $context['field_prices'] ?? null ) ? $context['field_prices'] : [],
					$qty_based,
					is_array( $context['field_labels'] ?? null ) ? $context['field_labels'] : [],
					$options,
					$segment['val']
				);
			}
			$addons           += $calc_price;
			$hints[ $index ]   = PricingHints::format(
				(string) $pricing['type'],
				$calc_price,
				$product,
				'cart',
				$field,
				null
			);
		}

		return $hints;
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
		if ( empty( $cart_item[ self::ITEM_KEY ] ) ) {
			return;
		}
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$values = $cart_item[ self::ITEM_KEY ];
		Uploads::persist( $values, $item, $order );
		// WAPF parity: hide_order fields stay out of the visible order-item
		// meta on every surface but remain in _opf_fields (order-again,
		// exports) — hidden data is suppressed from display, never lost.
		// Priced values carry the plain `label (+$x)` hint WAPF writes via
		// values_to_simple_string( ..., 'order' ) (WAPF-DISPLAY-PRICE-HINTS).
		foreach ( self::visible_selections( $product, $values, 'order', $cart_item ) as $selection ) {
			$field       = is_array( $selection['field'] ?? null ) ? $selection['field'] : [];
			$meta_value  = self::order_selection_value( $selection );
			// WAPF alias bridge: wapf/order/order_item_field + wapf/order_item/meta_display_value.
			$meta_field = \OPF\Compat\WapfHooks::order_item_field(
				[
					'id'    => (string) ( $field['id'] ?? '' ),
					'type'  => (string) ( $field['type'] ?? '' ),
					'label' => (string) $selection['label'],
					'value' => $meta_value,
				],
				$cart_item,
				$field
			);
			$display = \OPF\Compat\WapfHooks::meta_display_value( $meta_field['value'] ?? $meta_value, $meta_field );
			$item->add_meta_data( $selection['label'], $display );
		}
		$item->add_meta_data( '_opf_fields', wp_json_encode( $values, JSON_UNESCAPED_UNICODE ), true );
		$item->add_meta_data( '_opf_cart_item_key', $cart_item_key, true );
		$snapshot = \OPF\API::field_snapshot_for_product( $product, $values, $cart_item );
		$item->add_meta_data( '_opf_fields_snapshot', wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE ), true );
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
		$keys[] = '_opf_fields_snapshot';
		$keys[] = '_opf_cart_item_key';
		$keys[] = '_opf_uploads';
		$keys[] = '_opf_child';
		$keys[] = '_opf_child_full';
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
		$stored = $order_item->get_meta( '_opf_fields', true );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				$quantity = max( 1, (int) $order_item->get_quantity() );
				$product  = $order_item->get_product();
				// Order-again prices the current catalog product, as WooCommerce
				// does for a fresh cart line. Addons require this unadjusted base.
				if ( $product instanceof \WC_Product ) {
					$cart_item_data['opf_base_price'] = (float) $product->get_price( 'edit' );
					// WAPF alias bridge: wapf/order_again/before_cart_item_field.
					foreach ( FieldGroups::for_product( $product ) as $entry ) {
						$gid = (string) $entry['id'];
						foreach ( $entry['group']->data['fields'] as $field ) {
							if ( in_array( $field['type'], [ 'section', 'section_end', 'paragraph' ], true ) ) {
								continue;
							}
							\OPF\Compat\WapfHooks::order_again_field( $order_item, $field, 0, $decoded[ $gid ][ $field['id'] ] ?? null );
						}
					}
				}
				if ( $quantity > 1 && $product instanceof \WC_Product ) {
					foreach ( FieldGroups::for_product( $product ) as $entry ) {
						$gid = (string) $entry['id'];
						$section_repeats = self::section_repeat_context( $entry['group']->data['fields'] );
						foreach ( $entry['group']->data['fields'] as $field ) {
							$repeat = ! empty( $field['repeat']['enabled'] )
								? $field['repeat']
								: ( $section_repeats[ $field['id'] ] ?? [] );
							$rows = $decoded[ $gid ][ $field['id'] ] ?? null;
							if ( 'quantity' === ( $repeat['mode'] ?? '' ) && is_array( $rows ) && 1 === count( $rows ) ) {
								$decoded[ $gid ][ $field['id'] ] = array_fill( 0, $quantity, reset( $rows ) );
							}
						}
					}
				}
				$cart_item_data[ self::ITEM_KEY ] = $product instanceof \WC_Product
					? self::retain_price_calculation_groups( self::sanitize_submitted( $product, $decoded, true ), FieldGroups::for_product( $product ) )
					: $decoded;
			}
		}
		return $cart_item_data;
	}

	/**
	 * Read submitted values from the Store API payload or the classic POST.
	 *
	 * @param \WC_Product      $product Product (variation resolved to parent).
	 * @param array<mixed>|null $raw     Raw payload from the Store API path, if any.
	 * @return array<string,mixed> gid => fid => value(s).
	 */
	private static function collect_submitted( \WC_Product $product, ?array $raw ): array {
		$native = null === $raw ? Uploads::native( $product ) : [];
		// Classic form POST.
		if ( null === $raw && isset( $_POST['opf'] ) && is_array( $_POST['opf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['opf'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		}

		if ( $native ) {
			// Native transport produces upload tokens for fields the shopper
			// just chose files for. Cart-edit prefill may also post existing
			// tokens through hidden `opf[gid][fid][]` inputs — index-wise
			// `array_replace_recursive` would silently overwrite kept files
			// with new ones, so upload token lists union instead. `tokens()`
			// dedupes and `validate_tokens` still fails closed on 'invalid'.
			$raw = is_array( $raw ) ? $raw : [];
			foreach ( $native as $native_gid => $native_fields ) {
				foreach ( (array) $native_fields as $native_fid => $native_tokens ) {
					$existing = isset( $raw[ $native_gid ][ $native_fid ] ) && is_array( $raw[ $native_gid ][ $native_fid ] )
						? $raw[ $native_gid ][ $native_fid ]
						: [];
					$raw[ $native_gid ][ $native_fid ] = array_values( array_unique( array_merge( $existing, (array) $native_tokens ) ) );
				}
			}
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}

		return self::sanitize_submitted( $product, $raw );
	}

	/**
	 * Sanitize raw submitted data against known groups/fields/types.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $raw     Raw submitted array.
	 * @param bool        $structured Whether values came from stored cart/order data.
	 * @return array<int|string, array<string, mixed>>
	 */
	private static function sanitize_submitted( \WC_Product $product, array $raw, bool $structured = false ): array {
		$values = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );
			$submitted = isset( $raw[ $gid ] ) && is_array( $raw[ $gid ] ) ? $raw[ $gid ] : [];

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'section', 'section_end' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				$repeat_field = $field;
				if ( empty( $repeat_field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] ) ) {
					$repeat_field['repeat'] = $section_repeats[ $fid ];
				}
				if ( ! array_key_exists( $fid, $submitted ) ) {
					if ( in_array( $field['type'], [ 'text', 'url', 'toggle' ], true ) && empty( $repeat_field['repeat']['enabled'] ) && isset( $field['default'] ) ) {
						$submitted[ $fid ] = $field['default'];
					} else {
						continue;
					}
				}
				$value = ! empty( $repeat_field['repeat']['enabled'] )
					? RepeaterField::sanitize( $repeat_field, $submitted[ $fid ], static function ( $row ) use ( $field, $structured ) {
						return self::sanitize_value( $field, $row, $structured );
					} )
					: self::sanitize_value( $field, $submitted[ $fid ], $structured );
				if ( null !== $value ) {
					$values[ $gid ][ $fid ] = $value;
				}
			}
		}

		return $values;
	}

	/**
	 * Map fields inside a repeated section to the repeat settings inherited from it.
	 *
	 * @param array<int,array<string,mixed>> $fields Normalized group fields.
	 * @return array<string,array<string,mixed>> Field ID to repeat configuration.
	 */
	private static function section_repeat_context( array $fields ): array {
		$context = [];
		$stack   = [];
		$active  = [];

		foreach ( $fields as $field ) {
			if ( 'section_end' === $field['type'] ) {
				array_pop( $stack );
				$active = $stack ? end( $stack ) : [];
				continue;
			}

			if ( 'section' === $field['type'] ) {
				$repeat = ! empty( $field['repeat']['enabled'] ) ? $field['repeat'] : $active;
				$stack[] = $repeat;
				$active  = $repeat;
				continue;
			}

			if ( $active ) {
				$context[ $field['id'] ] = $active;
			}
		}

		return $context;
	}

	/**
	 * Sanitize one value by field type. Null when nothing was submitted.
	 *
	 * @param array<string,mixed> $field  Field definition.
	 * @param mixed               $value  Submitted value.
	 * @param bool                $structured Whether this is a stored cart/order value.
	 */
	private static function sanitize_value( array $field, $value, bool $structured = false ) {
		if ( 'products' === $field['type'] ) {
			return LinkedProducts::sanitize_value( $field, $value, $structured );
		}
		if ( 'upload' === $field['type'] ) {
			return Uploads::tokens( $value );
		}
		if ( 'calc' === $field['type'] ) {
			// The submitted value is the client-computed raw result. Keep a
			// bounded canonical numeric string; forged non-numeric payloads
			// drop the field entirely (fail closed).
			if ( is_array( $value ) ) {
				return null;
			}
			$raw = trim( (string) $value );
			if ( '' === $raw || ! is_numeric( $raw ) ) {
				return null;
			}
			return (string) ( 0 + $raw );
		}
		if ( in_array( $field['type'], [ 'url', 'email' ], true ) ) {
			// Validate the submitted scalar itself without stripping malformed
			// characters into a different, valid-looking address.
			return FieldValue::sanitize( $field, $value );
		}
		if ( 'image_quantity' === $field['type'] ) {
			if ( $structured && is_array( $value ) && 'image_quantity' === ( $value['_opf_type'] ?? '' ) ) {
				$value = $value['quantities'] ?? [];
			}
			if ( ! is_array( $value ) ) {
				return null;
			}
			$clean = [];
			$invalid = [];
			foreach ( $field['choices'] as $choice ) {
				$raw_quantity = $value[ $choice['slug'] ] ?? 0;
				if ( ! is_scalar( $raw_quantity ) || ! preg_match( '/^\\d+$/', (string) $raw_quantity ) ) {
					$invalid[] = $choice['slug'];
					$clean[ $choice['slug'] ] = 0;
					continue;
				}
				$quantity = (int) $raw_quantity;
				if ( $quantity < $choice['quantity']['min'] || $quantity > $choice['quantity']['max'] || ( ! empty( $choice['disabled'] ) && $quantity > 0 ) ) {
					$invalid[] = $choice['slug'];
				}
				$clean[ $choice['slug'] ] = ! empty( $choice['disabled'] ) ? 0 : $quantity;
			}
			return [ '_opf_type' => 'image_quantity', 'quantities' => $clean, 'invalid' => $invalid ];
		}
		if ( in_array( $field['type'], [ 'swatch', 'select', 'radio', 'checkbox' ], true ) ) {
			$valid_slugs = wp_list_pluck( $field['choices'], 'slug' );
			$multi_swatch = 'swatch' === $field['type'] && ! empty( $field['multiple'] );
			$multi_value = 'checkbox' === $field['type'] || $multi_swatch;
			// Multi-value inputs carry each slug independently, so a forged
			// disabled selection is dropped during sanitize (image_quantity
			// zeroes disabled choices the same way). Single-value inputs keep
			// the slug so validate_choices() can report it as unavailable.
			$disabled_slugs = [];
			if ( $multi_value ) {
				foreach ( $field['choices'] as $choice ) {
					if ( ! empty( $choice['disabled'] ) ) {
						$disabled_slugs[] = (string) $choice['slug'];
					}
				}
			}
			$slugs       = (array) $value;
			$clean       = [];
			foreach ( $slugs as $slug ) {
				$slug = sanitize_text_field( (string) $slug );
				if ( in_array( $slug, $valid_slugs, true ) && ! in_array( $slug, $disabled_slugs, true ) ) {
					$clean[] = $slug;
				}
			}
			if ( empty( $clean ) ) {
				return null;
			}
			return in_array( $field['type'], [ 'select', 'radio' ], true ) || ( 'swatch' === $field['type'] && ! $multi_swatch ) ? $clean[0] : array_values( array_unique( $clean ) );
		}

		if ( is_array( $value ) ) {
			return null;
		}

		switch ( $field['type'] ) {
			case 'number':
				return is_numeric( $value ) ? (string) ( $value + 0 ) : null;
			case 'textarea':
				$text = sanitize_textarea_field( (string) $value );
				return '' === trim( $text ) ? null : $text;
			case 'date':
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
	private static function validate_values( \WC_Product $product, array $values, int $product_quantity = 1 ): array {
		$errors = [];

		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			$given = $values[ $gid ] ?? [];
			$section_repeats = self::section_repeat_context( $group->data['fields'] );

			foreach ( $group->data['fields'] as $field ) {
				if ( in_array( $field['type'], [ 'section', 'section_end' ], true ) ) {
					continue;
				}
				$provided = array_key_exists( $field['id'], $given );
				$repeat_field = $field;
				if ( empty( $repeat_field['repeat']['enabled'] ) && isset( $section_repeats[ $field['id'] ] ) ) {
					$repeat_field['repeat'] = $section_repeats[ $field['id'] ];
				}
				if ( ! empty( $repeat_field['repeat']['enabled'] ) ) {
					$rows = $provided && is_array( $given[ $field['id'] ] ) ? $given[ $field['id'] ] : [];
					foreach ( $rows as $row_index => $row ) {
						$clone_values = self::values_for_clone( $group->data['fields'], $given, $section_repeats, (int) $row_index );
						if ( self::field_visibility( $field, $group->data['fields'], $clone_values ) ) {
							$errors = array_merge( $errors, FieldValue::validate_choices( $field, $row ) );
						}
					}
					$errors = array_merge( $errors, RepeaterField::validate(
						$repeat_field,
						$rows,
						$provided,
						$product_quantity,
						static function ( int $row_index ) use ( $field, $group, $given, $section_repeats ): bool {
							$clone_values = self::values_for_clone( $group->data['fields'], $given, $section_repeats, $row_index );
							return self::field_visibility( $field, $group->data['fields'], $clone_values );
						}
					) );
					continue;
				}
				if ( ! self::field_visibility( $field, $group->data['fields'], $given ) ) {
					continue;
				}
				// WAPF alias bridge: wapf/validate (visible, non-repeat fields).
				$errors = array_merge(
					$errors,
					\OPF\Compat\WapfHooks::validate_field( $field, $provided ? $given[ $field['id'] ] : null, $product, $product_quantity )
				);
				if ( 'upload' === $field['type'] ) {
					$tokens = $provided && is_array( $given[ $field['id'] ] ) ? $given[ $field['id'] ] : [];
					$errors = array_merge( $errors, Uploads::validate_tokens( $field, $tokens, $product->get_id(), $gid ) );
					continue;
				}
				if ( in_array( $field['type'], [ 'select', 'radio', 'checkbox', 'swatch' ], true ) && $provided ) {
					// Disabled choices plus WAPF min_choices/max_choices bounds;
					// the min binds only once a value exists (empty optional is
					// accepted, empty required is covered by the required check).
					// See validate_multiple_choice_field() in WAPF class-cart.php.
					$errors = array_merge( $errors, FieldValue::validate_choices( $field, $given[ $field['id'] ] ) );
				}
				if ( 'image_quantity' === $field['type'] ) {
					$submitted = $provided && is_array( $given[ $field['id'] ] ) ? $given[ $field['id'] ] : [];
					$errors = array_merge( $errors, self::validate_image_quantity( $field, $submitted ) );
					continue;
				}
				if ( 'products' === $field['type'] ) {
					$errors = array_merge( $errors, LinkedProducts::validate_field(
						$field,
						$provided ? $given[ $field['id'] ] : null,
						$product_quantity,
						$product
					) );
					continue;
				}
				$value    = $provided && ! is_array( $given[ $field['id'] ] ) ? (string) $given[ $field['id'] ] : null;
				if ( in_array( $field['type'], [ 'email', 'url', 'date', 'toggle', 'number', 'text', 'textarea' ], true ) ) {
					$errors = array_merge( $errors, FieldValue::validate( $field, $value, $provided ) );
				} elseif ( $field['required'] && ! $provided ) {
					$errors[] = sprintf( '"%s" is a required field.', $field['label'] );
				}
			}
		}

		return $errors;
	}

	/** Validate per-choice and aggregate image quantities. */
	private static function validate_image_quantity( array $field, array $submitted ): array {
		$errors = [];
		$quantities = is_array( $submitted['quantities'] ?? null ) ? $submitted['quantities'] : $submitted;
		$invalid = is_array( $submitted['invalid'] ?? null ) ? $submitted['invalid'] : [];
		$total = 0;
		foreach ( $field['choices'] as $choice ) {
			$q = (int) ( $quantities[ $choice['slug'] ] ?? 0 );
			$total += $q;
			if ( in_array( $choice['slug'], $invalid, true ) ) {
				$errors[] = sprintf( '"%s" quantity is invalid.', $choice['label'] );
			} elseif ( $q < $choice['quantity']['min'] || $q > $choice['quantity']['max'] ) {
				$errors[] = sprintf( '"%s" quantity must be between %d and %d.', $choice['label'], $choice['quantity']['min'], $choice['quantity']['max'] );
			}
		}
		if ( isset( $field['min_choices'] ) && $total < $field['min_choices'] ) {
			$errors[] = sprintf( '"%s" requires at least %d total items.', $field['label'], $field['min_choices'] );
		}
		if ( isset( $field['max_choices'] ) && $total > $field['max_choices'] ) {
			$errors[] = sprintf( '"%s" allows at most %d total items.', $field['label'], $field['max_choices'] );
		}
		return $errors;
	}

	/**
	 * Build conditional values for one repeated clone while leaving non-repeated
	 * fields at their group-wide values.
	 *
	 * @param array<int,array<string,mixed>> $fields          Normalized group fields.
	 * @param array<string,mixed>             $given           Sanitized group values.
	 * @param array<string,array<string,mixed>> $section_repeats Inherited section repeat settings.
	 */
	private static function values_for_clone( array $fields, array $given, array $section_repeats, int $row_index ): array {
		$values = $given;
		foreach ( $fields as $field ) {
			if ( in_array( $field['type'], [ 'paragraph', 'section', 'section_end' ], true ) ) {
				continue;
			}
			$repeat = ! empty( $field['repeat']['enabled'] )
				? $field['repeat']
				: ( $section_repeats[ $field['id'] ] ?? [] );
			if ( empty( $repeat['enabled'] ) ) {
				continue;
			}
			$rows = $given[ $field['id'] ] ?? [];
			$values[ $field['id'] ] = is_array( $rows ) && array_key_exists( $row_index, $rows ) ? $rows[ $row_index ] : null;
		}
		return $values;
	}

	/**
	 * Keep an entry for groups whose only submittable content is a price
	 * calculation.
	 *
	 * Calculation fields carry no user input: a group containing nothing but
	 * a cost calc (OPF `calc` + `calc_type=cost`, or the legacy
	 * `calculation` + `calculation_type=price` spelling) would otherwise
	 * collect to an empty array, vanish from the cart payload, and lose its
	 * price contribution. WAPF prices those groups regardless of submission,
	 * so the group id is retained with an empty value map.
	 *
	 * @param array<int|string,array<string,mixed>> $values gid => fid => value(s).
	 * @param array<int,array{id:int|string,group:FieldGroup}> $groups FieldGroups::for_product() rows.
	 * @return array<int|string,array<string,mixed>>
	 */
	private static function retain_price_calculation_groups( array $values, array $groups ): array {
		foreach ( $groups as $entry ) {
			$gid   = (string) $entry['id'];
			$group = $entry['group'];
			if ( isset( $values[ $gid ] ) ) {
				continue;
			}
			$has_price_calculation = false;
			foreach ( $group->data['fields'] as $field ) {
				$type = (string) ( $field['type'] ?? '' );
				if ( ( 'calculation' === $type && 'price' === (string) ( $field['calculation_type'] ?? 'informational' ) )
					|| ( 'calc' === $type && 'cost' === (string) ( $field['calc_type'] ?? 'default' ) ) ) {
					$has_price_calculation = true;
					break;
				}
			}
			if ( $has_price_calculation ) {
				$values[ $gid ] = [];
			}
		}
		return $values;
	}

	/**
	 * Drop conditionally-hidden values before validation, pricing and persist.
	 *
	 * Visibility follows WAPF's merged-section semantics: a field inside a
	 * hidden section is hidden, and repeated fields/sections are filtered per
	 * row. This mirrors the browser (hidden controls are disabled) so forged
	 * payloads cannot smuggle values into a cart/order.
	 *
	 * @param \WC_Product                            $product Product.
	 * @param array<int|string, array<string,mixed>> $values  Sanitized values.
	 * @return array<int|string, array<string,mixed>>
	 */
	private static function drop_hidden_values( \WC_Product $product, array $values ): array {
		foreach ( FieldGroups::for_product( $product ) as $entry ) {
			$gid = (string) $entry['id'];
			if ( empty( $values[ $gid ] ) || ! is_array( $values[ $gid ] ) ) {
				continue;
			}
			$fields = $entry['group']->data['fields'];
			$given  = $values[ $gid ];
			$section_repeats = self::section_repeat_context( $fields );

			foreach ( $fields as $field ) {
				if ( in_array( $field['type'], [ 'paragraph', 'content_image', 'section', 'section_end' ], true ) ) {
					continue;
				}
				$fid = $field['id'];
				if ( ! array_key_exists( $fid, $given ) ) {
					continue;
				}
				$repeat_field = $field;
				if ( empty( $repeat_field['repeat']['enabled'] ) && isset( $section_repeats[ $fid ] ) ) {
					$repeat_field['repeat'] = $section_repeats[ $fid ];
				}
				if ( empty( $repeat_field['repeat']['enabled'] ) ) {
					if ( ! self::field_visibility( $field, $fields, $given ) ) {
						unset( $values[ $gid ][ $fid ] );
					}
					continue;
				}
				if ( ! is_array( $given[ $fid ] ) ) {
					if ( ! self::field_visibility( $field, $fields, $given ) ) {
						unset( $values[ $gid ][ $fid ] );
					}
					continue;
				}
				$kept = [];
				foreach ( $given[ $fid ] as $row_index => $row ) {
					$clone_values = self::values_for_clone( $fields, $given, $section_repeats, (int) $row_index );
					if ( self::field_visibility( $field, $fields, $clone_values ) ) {
						$kept[ $row_index ] = $row;
					}
				}
				if ( $kept ) {
					$values[ $gid ][ $fid ] = $kept;
				} else {
					unset( $values[ $gid ][ $fid ] );
				}
			}
			if ( empty( $values[ $gid ] ) ) {
				unset( $values[ $gid ] );
			}
		}
		return $values;
	}

	/**
	 * Effective visibility for a field: its own conditionals AND every enclosing
	 * section's conditionals. WAPF merges section conditions into enclosed
	 * fields at parse time (class-field-groups.php:463-537); OPF evaluates the
	 * enclosing sections directly.
	 *
	 * @param array<string,mixed>            $field  Field definition.
	 * @param array<int,array<string,mixed>> $fields Whole group field list.
	 * @param array<string,mixed>            $values Current values.
	 */
	private static function field_visibility( array $field, array $fields, array $values ): bool {
		if ( ! Evaluator::is_visible( $field, $values ) ) {
			return false;
		}
		$ancestors = [];
		foreach ( $fields as $candidate ) {
			if ( (string) $candidate['id'] === (string) $field['id'] ) {
				break;
			}
			if ( 'section_end' === $candidate['type'] ) {
				array_pop( $ancestors );
				continue;
			}
			if ( 'section' === $candidate['type'] ) {
				$ancestors[] = $candidate;
			}
		}
		foreach ( $ancestors as $section ) {
			if ( ! Evaluator::is_visible( $section, $values ) ) {
				return false;
			}
		}
		return true;
	}
}
