<?php
/**
 * Prefill WooCommerce's product quantity from the `qty` URL parameter.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

final class QuantityPrefill {
	/** Register product-form quantity prefill. */
	public static function init(): void {
		add_filter( 'woocommerce_quantity_input_args', [ __CLASS__, 'filter_product_quantity' ], 10, 2 );
	}

	/** Apply a valid URL quantity to a product page's WooCommerce quantity input. */
	public static function filter_product_quantity( array $args, $product ): array {
		if ( function_exists( 'is_product' ) && ! is_product() ) {
			return $args;
		}
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $args;
		}
		if ( function_exists( 'get_queried_object_id' ) ) {
			$queried_product_id = (int) get_queried_object_id();
			$product_id         = (int) $product->get_id();
			$parent_product_id  = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			if ( $queried_product_id > 0 && $queried_product_id !== $product_id && $queried_product_id !== $parent_product_id ) {
				return $args;
			}
		}
		if ( ! isset( $_GET['qty'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only quantity prefill; WooCommerce validates the submitted quantity.
			return $args;
		}

		$query_quantity = wp_unslash( $_GET['qty'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::apply( $args, $query_quantity );
	}

	/**
	 * Apply a positive decimal quantity only when it fits the input's min/max/step.
	 *
	 * Invalid input is ignored instead of changing WooCommerce's existing default.
	 *
	 * @param array<string,mixed> $args WooCommerce quantity input arguments.
	 * @param mixed               $query_quantity Raw `qty` URL value.
	 * @return array<string,mixed>
	 */
	public static function apply( array $args, $query_quantity ): array {
		if ( ! is_string( $query_quantity ) || ! preg_match( '/^[0-9]+(?:\.[0-9]+)?$/D', $query_quantity ) ) {
			return $args;
		}

		$quantity = (float) $query_quantity;
		if ( ! is_finite( $quantity ) || $quantity <= 0 ) {
			return $args;
		}

		$minimum = isset( $args['min_value'] ) && is_numeric( $args['min_value'] ) ? (float) $args['min_value'] : 0.0;
		$maximum = isset( $args['max_value'] ) && is_numeric( $args['max_value'] ) && (float) $args['max_value'] >= 0 ? (float) $args['max_value'] : null;
		if ( $quantity < $minimum || ( null !== $maximum && $quantity > $maximum ) ) {
			return $args;
		}

		$step = $args['step'] ?? 1;
		if ( is_numeric( $step ) && (float) $step > 0 ) {
			$steps_from_minimum = ( $quantity - $minimum ) / (float) $step;
			if ( abs( $steps_from_minimum - round( $steps_from_minimum ) ) > 1e-9 ) {
				return $args;
			}
		}

		$args['input_value'] = floor( $quantity ) === $quantity ? (int) $quantity : $quantity;
		return $args;
	}
}
