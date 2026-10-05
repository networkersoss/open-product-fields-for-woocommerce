<?php
/**
 * WAPF `wapf/*` hook compatibility bridge.
 *
 * Open Product Fields is a clean-room reimplementation of Advanced Product
 * Fields for WooCommerce (WAPF Extended 3.1.5). Third-party code written
 * against WAPF's developer hooks would otherwise silently stop working after
 * a migration. This bridge re-exposes the subset of WAPF's extension points
 * that have real developer value — pricing, cart, field rendering, validation
 * and order meta — under their original `wapf/…` (and legacy `wapf_…`) names.
 *
 * Design:
 *  - Direct shims register on OPF's existing `opf/…` filters and re-dispatch
 *    the identically-shaped WAPF hook. No core edit required.
 *  - Where OPF has no matching filter but does have the right data at a known
 *    lifecycle point, a single wrapped dispatch line calls one of the public
 *    helpers below (documented at the call site).
 *  - When no `wapf/…` listener is registered, `apply_filters()` returns its
 *    input unchanged and `do_action()` is a no-op: zero behavior change.
 *
 * This file is deliberately separate so the bridge can be audited or removed
 * without touching the canonical OPF hook surface.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Compat;

defined( 'ABSPATH' ) || exit;

final class WapfHooks {

	/**
	 * Register aliases on OPF's own hook surface.
	 *
	 * Every callback is a pure pass-through to the WAPF-named filter/action,
	 * so registering them costs nothing until someone listens on `wapf/…`.
	 */
	public static function init(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'opf_features_linked_products', [ __CLASS__, 'shim_linked_products_enabled' ], 10, 1 );
		add_filter( 'opf/linked_products/choice', [ __CLASS__, 'shim_linked_products_choice' ], 10, 3 );
		add_filter( 'opf/linked_products/query_args', [ __CLASS__, 'shim_products_query' ], 10, 3 );
		add_filter( 'opf_groups_for_product', [ __CLASS__, 'shim_product_field_groups' ], 10, 2 );
		add_filter( 'opf_lookup_tables', [ __CLASS__, 'shim_lookup_tables' ], 10, 2 );
		add_filter( 'opf_skip_validation', [ __CLASS__, 'shim_skip_validation' ], 10, 1 );
		add_filter( 'opf_show_totals', [ __CLASS__, 'shim_show_totals' ], 10, 1 );
		add_filter( 'opf_pricing_hint_format', [ __CLASS__, 'shim_pricing_hint_format' ], 10, 4 );
		add_filter( 'opf_pricing_hint_amount', [ __CLASS__, 'shim_pricing_hint_amount' ], 10, 4 );
		add_filter( 'opf_pricing_hint', [ __CLASS__, 'shim_pricing_hint' ], 10, 6 );
		add_filter( 'opf_pricing_addon', [ __CLASS__, 'shim_pricing_addon' ], 10, 4 );
		add_filter( 'opf_pricing_price_with_tax', [ __CLASS__, 'shim_pricing_price_with_tax' ], 10, 4 );
		add_filter( 'opf_formula_base_price', [ __CLASS__, 'shim_formula_base_price' ], 10, 2 );
	}

	/* ------------------------------------------------------------------
	 * Direct shims registered on OPF's existing `opf/…` hooks.
	 * ------------------------------------------------------------------ */

	/** @param bool $enabled */
	public static function shim_linked_products_enabled( $enabled ) {
		return apply_filters( 'wapf/features/linked_products', $enabled );
	}

	/**
	 * @param array        $choice Choice data.
	 * @param array|object $field  Field definition.
	 * @param mixed        $product Product.
	 */
	public static function shim_linked_products_choice( $choice, $field, $product ) {
		return apply_filters( 'wapf/linked_products/choice', $choice, $field, $product );
	}

	/**
	 * @param array $args        WC product query args.
	 * @param array $query       OPF query config.
	 * @param mixed $main_product Parent product.
	 */
	public static function shim_products_query( $args, $query, $main_product ) {
		return apply_filters( 'wapf/products/query', $args, $query, $main_product );
	}

	/**
	 * @param array $groups  Matching field groups.
	 * @param mixed $product Product.
	 */
	public static function shim_product_field_groups( $groups, $product ) {
		return apply_filters( 'wapf/product_field_groups', $groups, $product );
	}

	/**
	 * @param array $tables  Lookup tables.
	 * @param array $context Evaluation context (ignored by WAPF listeners).
	 */
	public static function shim_lookup_tables( $tables, $context = [] ) {
		return apply_filters( 'wapf/lookup_tables', $tables );
	}

	/** @param bool $skip */
	public static function shim_skip_validation( $skip ) {
		$skip = apply_filters( 'wapf/skip_cart_validation', $skip );
		return apply_filters( 'wapf/skip_fieldgroup_validation', $skip );
	}

	/** @param bool $show */
	public static function shim_show_totals( $show ) {
		$result = apply_filters( 'wapf/pricing_summary', $show, null );
		// WAPF returns a mode string ('lines'/'hide'); OPF expects a boolean gate.
		return ( 'hide' === $result ) ? false : (bool) $result;
	}

	/**
	 * `wapf/html/pricing_hint/format` — WAPF shape
	 * ($format, $product, $amount, $type).
	 */
	public static function shim_pricing_hint_format( $format, $product = null, $amount = 0, $type = '' ) {
		return apply_filters( 'wapf/html/pricing_hint/format', $format, $product, $amount, $type );
	}

	/**
	 * `wapf/html/pricing_hint/amount` — WAPF shape
	 * ($amount, $product, $type, $for_page). This is the hook WAPF's own WOOCS
	 * and Aelia adapters hang their hint conversion off, so migrated currency
	 * integrations keep working.
	 */
	public static function shim_pricing_hint_amount( $amount, $product = null, $type = '', $for_page = 'product' ) {
		return apply_filters( 'wapf/html/pricing_hint/amount', $amount, $product, $type, $for_page );
	}

	/**
	 * `wapf/html/pricing_hint` — WAPF shape
	 * ($hint, $product, $amount, $type, $field, $option).
	 */
	public static function shim_pricing_hint( $hint, $product = null, $amount = 0, $type = '', $field = null, $option = null ) {
		return apply_filters( 'wapf/html/pricing_hint', $hint, $product, $amount, $type, $field, $option );
	}

	/**
	 * `wapf/pricing/addon` — WAPF shape ($amount, $product, $type, $for),
	 * fired inside Helper::adjust_addon_price for non-percent amounts.
	 */
	public static function shim_pricing_addon( $amount, $product = null, $type = '', $for = 'shop' ) {
		return apply_filters( 'wapf/pricing/addon', $amount, $product, $type, $for );
	}

	/**
	 * `wapf/pricing/price_with_tax` — WAPF shape
	 * ($price_with_tax, $price, $product, $for_page).
	 */
	public static function shim_pricing_price_with_tax( $price_with_tax, $price = 0, $product = null, $for_page = 'shop' ) {
		return apply_filters( 'wapf/pricing/price_with_tax', $price_with_tax, $price, $product, $for_page );
	}

	/**
	 * `wapf/pricing/cart_item_base_for_formulas` — WAPF passes
	 * ($price, $product, $quantity, $cart_item). OPF's `opf_formula_base_price`
	 * carries the product id only (formula evaluation is context-free, it runs
	 * for previews as well as carts), so the product is resolved when
	 * WooCommerce is loaded and quantity/cart context default to a single unit.
	 */
	public static function shim_formula_base_price( float $price, int $product_id ): float {
		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		return (float) apply_filters( 'wapf/pricing/cart_item_base_for_formulas', $price, $product, 1, [] );
	}

	/* ------------------------------------------------------------------
	 * Wrapped dispatch helpers, called from the canonical OPF lifecycle.
	 * Each preserves the WAPF argument shape.
	 * ------------------------------------------------------------------ */

	/**
	 * `wapf/pricing/base` then `wapf/pricing/cart_item_base`.
	 *
	 * @param float        $base      Base unit price.
	 * @param mixed        $product   Product.
	 * @param int          $quantity  Line quantity.
	 * @param array<mixed> $cart_item Cart line.
	 */
	public static function cart_base_price( float $base, $product, int $quantity, array $cart_item ): float {
		$base = (float) apply_filters( 'wapf/pricing/base', $base, $product, $quantity );
		return (float) apply_filters( 'wapf/pricing/cart_item_base', $base, $product, $quantity, $cart_item );
	}

	/**
	 * `wapf/pricing/cart_item_options`.
	 *
	 * @param float        $options_total Per-unit addon total.
	 * @param mixed        $product       Product.
	 * @param int          $quantity      Line quantity.
	 * @param array<mixed> $cart_item     Cart line.
	 */
	public static function cart_item_options( float $options_total, $product, int $quantity, array $cart_item ): float {
		return (float) apply_filters( 'wapf/pricing/cart_item_options', $options_total, $product, $quantity, $cart_item );
	}

	/**
	 * `wapf/pricing/product`.
	 *
	 * @param float|string $price   Product price.
	 * @param mixed        $product Product.
	 */
	public static function pricing_product( $price, $product ) {
		return apply_filters( 'wapf/pricing/product', $price, $product );
	}

	/**
	 * `wapf/cart/item_data`.
	 *
	 * @param array        $item_data Cart item display data.
	 * @param array<mixed> $cart_item Cart line.
	 */
	public static function cart_item_data( array $item_data, array $cart_item ): array {
		return (array) apply_filters( 'wapf/cart/item_data', $item_data, $cart_item );
	}

	/**
	 * `wapf/validate` — WAPF's per-field validation filter.
	 *
	 * WAPF callbacks receive `$err` = ['error'=>bool, 'message'=>string] and
	 * return the same shape. OPF works in plain error strings, so a returned
	 * message is appended as an error string. `cart_item_data` is not
	 * available at OPF's validation stage and is passed as null.
	 *
	 * @param array        $field    Normalized field definition.
	 * @param mixed        $value    Submitted value.
	 * @param mixed        $product  Product.
	 * @param int          $quantity Product quantity.
	 * @return string[]
	 */
	public static function validate_field( array $field, $value, $product, int $quantity ): array {
		if ( is_object( $product ) ) {
			$product_id = is_callable( [ $product, 'get_id' ] ) ? (int) $product->get_id() : 0;
		} else {
			$product_id = (int) $product;
		}
		$err = self::apply_validation_filter_for_opf_field(
			[ 'error' => false ],
			$value,
			$field,
			$product_id,
			0,
			$quantity,
			false,
			null
		);
		if ( is_array( $err ) && ! empty( $err['error'] ) && isset( $err['message'] ) && '' !== (string) $err['message'] ) {
			return [ (string) $err['message'] ];
		}
		return [];
	}

	/**
	 * Dispatch the shared validation hook without passing OPF arrays to WAPF
	 * validators that require a WAPF Field object. Other WAPF-named third-party
	 * listeners still receive the OPF field.
	 *
	 * The native callback is removed only for this synchronous dispatch and
	 * restored at its original priority and position before returning.
	 *
	 * @param mixed ...$args Filter arguments, beginning with the error array.
	 * @return mixed
	 */
	private static function apply_validation_filter_for_opf_field( ...$args ) {
		$hook_name = 'wapf/validate';
		$registry  = $GLOBALS['wp_filter'][ $hook_name ] ?? null;
		$callbacks = ( is_object( $registry ) && isset( $registry->callbacks ) && is_array( $registry->callbacks ) )
			? $registry->callbacks
			: ( is_array( $registry ) ? $registry : [] );
		$removed = [];

		foreach ( $callbacks as $priority => $priority_callbacks ) {
			foreach ( $priority_callbacks as $key => $entry ) {
				$callback = $entry['function'] ?? null;
				if (
					! self::is_native_wapf_linked_product_validator( $callback )
					&& ! self::is_native_wapf_extended_date_validator( $callback )
				) {
					continue;
				}

				$removed[] = [
					'callback'       => $callback,
					'priority'       => (int) $priority,
					'accepted_args'  => (int) ( $entry['accepted_args'] ?? 1 ),
					'key'            => $key,
					'priority_order' => array_keys( $priority_callbacks ),
				];
				remove_filter( $hook_name, $callback, (int) $priority );
			}
		}

		try {
			return apply_filters( $hook_name, ...$args );
		} finally {
			foreach ( $removed as $entry ) {
				add_filter( $hook_name, $entry['callback'], $entry['priority'], $entry['accepted_args'] );
				self::restore_hook_callback_order( $hook_name, $entry );
			}
		}
	}

	/** @param mixed $callback WordPress filter callback. */
	private static function is_native_wapf_linked_product_validator( $callback ): bool {
		return is_array( $callback )
			&& isset( $callback[0], $callback[1] )
			&& is_object( $callback[0] )
			&& is_string( $callback[1] )
			&& is_a( $callback[0], 'SW_WAPF_PRO\\Includes\\Controllers\\Linked_Products_Controller' )
			&& 'validate_cart' === strtolower( $callback[1] );
	}

	/** @param mixed $callback WordPress filter callback. */
	private static function is_native_wapf_extended_date_validator( $callback ): bool {
		return is_string( $callback ) && 'wapfe_validate_cart_data' === strtolower( ltrim( $callback, '\\' ) );
	}

	/**
	 * Dispatch WAPF's order-again action for an OPF field without sending the
	 * normalized OPF array to WAPF's native linked-products Field-object handler.
	 *
	 * @param mixed ...$args Action arguments.
	 */
	private static function do_order_again_action_for_opf_field( ...$args ): void {
		$hook_name = 'wapf/order_again/before_cart_item_field';
		$registry  = $GLOBALS['wp_filter'][ $hook_name ] ?? null;
		$callbacks = ( is_object( $registry ) && isset( $registry->callbacks ) && is_array( $registry->callbacks ) )
			? $registry->callbacks
			: ( is_array( $registry ) ? $registry : [] );
		$removed = [];

		foreach ( $callbacks as $priority => $priority_callbacks ) {
			foreach ( $priority_callbacks as $key => $entry ) {
				$callback = $entry['function'] ?? null;
				if ( ! self::is_native_wapf_linked_product_order_again_handler( $callback ) ) {
					continue;
				}

				$removed[] = [
					'callback'       => $callback,
					'priority'       => (int) $priority,
					'accepted_args'  => (int) ( $entry['accepted_args'] ?? 1 ),
					'priority_order' => array_keys( $priority_callbacks ),
				];
				remove_action( $hook_name, $callback, (int) $priority );
			}
		}

		try {
			do_action( $hook_name, ...$args );
		} finally {
			foreach ( $removed as $entry ) {
				add_action( $hook_name, $entry['callback'], $entry['priority'], $entry['accepted_args'] );
				self::restore_hook_callback_order( $hook_name, $entry );
			}
		}
	}

	/** @param mixed $callback WAPF linked-products callback. */
	private static function is_native_wapf_linked_product_order_again_handler( $callback ): bool {
		return is_array( $callback )
			&& isset( $callback[0], $callback[1] )
			&& is_object( $callback[0] )
			&& is_string( $callback[1] )
			&& is_a( $callback[0], 'SW_WAPF_PRO\\Includes\\Controllers\\Linked_Products_Controller' )
			&& 'prepare_order_again_cart_item' === strtolower( $callback[1] );
	}

	/**
	 * Restore the callback's former order among same-priority filters.
	 *
	 * @param string               $hook_name Hook name.
	 * @param array<string, mixed> $entry     Removed callback metadata.
	 */
	private static function restore_hook_callback_order( string $hook_name, array $entry ): void {
		$registry = $GLOBALS['wp_filter'][ $hook_name ] ?? null;
		if ( is_object( $registry ) && isset( $registry->callbacks[ $entry['priority'] ] ) && is_array( $registry->callbacks[ $entry['priority'] ] ) ) {
			$current = $registry->callbacks[ $entry['priority'] ];
		} elseif ( is_array( $registry ) && isset( $registry[ $entry['priority'] ] ) && is_array( $registry[ $entry['priority'] ] ) ) {
			$current = $registry[ $entry['priority'] ];
		} else {
			return;
		}

		$ordered = [];
		foreach ( $entry['priority_order'] as $key ) {
			if ( isset( $current[ $key ] ) ) {
				$ordered[ $key ] = $current[ $key ];
			}
		}
		foreach ( $current as $key => $callback ) {
			if ( ! isset( $ordered[ $key ] ) ) {
				$ordered[ $key ] = $callback;
			}
		}

		if ( is_object( $registry ) ) {
			$registry->callbacks[ $entry['priority'] ] = $ordered;
		} else {
			$registry[ $entry['priority'] ] = $ordered;
			$GLOBALS['wp_filter'][ $hook_name ] = $registry;
		}
	}

	/**
	 * `wapf/order/order_item_field`.
	 *
	 * @param array        $meta_field WAPF-shaped field meta.
	 * @param array<mixed> $cart_item  Cart line.
	 * @param array        $field      Field definition.
	 */
	public static function order_item_field( array $meta_field, array $cart_item, array $field ): array {
		return (array) apply_filters( 'wapf/order/order_item_field', $meta_field, $cart_item, $field );
	}

	/**
	 * `wapf/order_item/meta_display_value`.
	 *
	 * @param mixed $display_value Formatted value.
	 * @param array $field         Field meta.
	 */
	public static function meta_display_value( $display_value, array $field ) {
		return apply_filters( 'wapf/order_item/meta_display_value', $display_value, $field );
	}

	/**
	 * `wapf/order_again/before_cart_item_field` (action).
	 *
	 * @param mixed $order_item Order item.
	 * @param array $field      Field definition.
	 * @param int   $clone_idx  Clone index (OPF has no clone index here).
	 * @param mixed $raw_values Raw stored values.
	 */
	public static function order_again_field( $order_item, array $field, int $clone_idx, $raw_values ): void {
		if ( function_exists( 'do_action' ) ) {
			self::do_order_again_action_for_opf_field( $order_item, $field, $clone_idx, $raw_values );
		}
	}

	/**
	 * `wapf/html/field_container_classes`.
	 *
	 * @param string[] $classes Container classes.
	 * @param array    $field   Field definition.
	 */
	public static function field_container_classes( array $classes, array $field ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $classes;
		}
		return (array) apply_filters( 'wapf/html/field_container_classes', $classes, $field );
	}

	/**
	 * `wapf/html/field_label`.
	 *
	 * @param string $label_content Label content.
	 * @param array  $field         Field definition.
	 * @param mixed  $product       Product.
	 */
	public static function field_label( string $label_content, array $field, $product = null ): string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $label_content;
		}
		return (string) apply_filters( 'wapf/html/field_label', $label_content, $field, $product );
	}

	/**
	 * `wapf/html/field_description`.
	 *
	 * @param string $html  Description HTML.
	 * @param array  $field Field definition.
	 */
	public static function field_description( string $html, array $field ): string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $html;
		}
		return (string) apply_filters( 'wapf/html/field_description', $html, $field );
	}

	/**
	 * `wapf/html/option_wrapper_classes`.
	 *
	 * @param string[] $classes Option wrapper classes.
	 * @param array    $field   Field definition.
	 * @param mixed    $product Product.
	 * @param mixed    $option  Choice/option.
	 */
	public static function option_wrapper_classes( array $classes, array $field, $product, $option ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $classes;
		}
		return (array) apply_filters( 'wapf/html/option_wrapper_classes', $classes, $field, $product, $option );
	}

	/**
	 * `wapf/html/image_swatch_size`.
	 *
	 * @param mixed $size    Image size.
	 * @param array $field   Field definition.
	 * @param mixed $product Product.
	 * @param mixed $choice  Choice.
	 */
	public static function image_swatch_size( $size, array $field, $product, $choice ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $size;
		}
		return apply_filters( 'wapf/html/image_swatch_size', $size, $field, $product, $choice );
	}

	/**
	 * `wapf/html/section_container_classes`.
	 *
	 * @param string[] $classes Section classes.
	 * @param array    $field   Section field.
	 */
	public static function section_container_classes( array $classes, array $field ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $classes;
		}
		return (array) apply_filters( 'wapf/html/section_container_classes', $classes, $field );
	}

	/** `wapf_after_product_totals` (legacy action). @param mixed $product */
	public static function after_product_totals( $product ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'wapf_after_product_totals', $product );
		}
	}

	/** `wapf_before_wrapper` (legacy action). @param mixed $product */
	public static function before_wrapper( $product ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'wapf_before_wrapper', $product );
		}
	}

	/** `wapf_before_product_totals` (legacy action). @param mixed $product */
	public static function before_product_totals( $product ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'wapf_before_product_totals', $product );
		}
	}

	/**
	 * `wapf/linked_products/cart_choice`.
	 *
	 * @param array $choice          Resolved child choice.
	 * @param array $field           Field definition.
	 * @param int   $main_product_id Parent product id.
	 */
	public static function linked_products_cart_choice( array $choice, array $field, int $main_product_id ): array {
		return (array) apply_filters( 'wapf/linked_products/cart_choice', $choice, $field, $main_product_id );
	}
}
