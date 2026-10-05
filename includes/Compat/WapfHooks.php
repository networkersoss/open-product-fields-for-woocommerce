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
		add_filter( 'opf_cart_edit_text', [ __CLASS__, 'shim_cart_edit_text' ], 10, 2 );
		add_filter( 'opf_disable_cart_edit_when_invisible', [ __CLASS__, 'shim_disable_cart_edit_when_invisible' ], 10, 1 );
		add_filter( 'opf_add_to_cart_redirect_when_editing', [ __CLASS__, 'shim_add_to_cart_redirect_when_editing' ], 10, 1 );
		add_filter( 'opf_store_api_cart_data', [ __CLASS__, 'shim_store_api_cart_data' ], 10, 2 );
		add_filter( 'opf_store_api_cart_schema', [ __CLASS__, 'shim_store_api_cart_schema' ], 10, 1 );
		// Runs last so a `wapf/pricing/display_options` listener has the final
		// say over the WOOCS/Aelia merged frontend price-format block.
		add_filter( 'opf_frontend_config', [ __CLASS__, 'shim_pricing_display_options' ], 100, 1 );

		// `wapf/features/change_price_html` gates WAPF's catalog price-html
		// override. OPF's override is ProductPriceDisplay::filter_price_html
		// (registered at priority 101 by ProductPriceDisplay::init, which runs
		// before this bridge). Re-register the same callback behind the WAPF
		// switch; when it is not registered there is nothing to gate.
		if (
			class_exists( '\OPF\Service\ProductPriceDisplay' )
			&& function_exists( 'has_filter' )
			&& function_exists( 'remove_filter' )
			&& has_filter( 'woocommerce_get_price_html' )
			&& remove_filter( 'woocommerce_get_price_html', [ \OPF\Service\ProductPriceDisplay::class, 'filter_price_html' ], 101 )
		) {
			add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'feature_change_price_html' ], 101, 2 );
		}
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
	 * `wapf/cart_edit_text` — WAPF's inline cart-edit link label.
	 * WAPF shape: ($cart_text, $cart_item).
	 *
	 * @param string $text      Link text.
	 * @param array  $cart_item Cart line.
	 */
	public static function shim_cart_edit_text( $text, $cart_item = [] ) {
		return apply_filters( 'wapf/cart_edit_text', $text, $cart_item );
	}

	/**
	 * `wapf/disable_cart_edit_when_invisible` — boolean gate; WAPF shape ($disable).
	 *
	 * @param bool $disable Whether an invisible product's line loses its edit link.
	 */
	public static function shim_disable_cart_edit_when_invisible( $disable ) {
		return apply_filters( 'wapf/disable_cart_edit_when_invisible', $disable );
	}

	/**
	 * `wapf/add_to_cart_redirect_when_editing` — boolean gate; WAPF shape ($redirect).
	 *
	 * @param bool $redirect Whether an edit submit redirects to the cart.
	 */
	public static function shim_add_to_cart_redirect_when_editing( $redirect ) {
		return apply_filters( 'wapf/add_to_cart_redirect_when_editing', $redirect );
	}

	/**
	 * `wapf/store_api/cart/data_callback` — WAPF shape ($data, $cart_item).
	 *
	 * @param array $data      Store API cart-item extension data.
	 * @param array $cart_item Cart line.
	 */
	public static function shim_store_api_cart_data( $data, $cart_item = [] ) {
		return apply_filters( 'wapf/store_api/cart/data_callback', $data, $cart_item );
	}

	/**
	 * `wapf/store_api/cart/schema_callback` — WAPF shape ($schema).
	 *
	 * @param array $schema Store API cart-item extension schema.
	 */
	public static function shim_store_api_cart_schema( $schema ) {
		return apply_filters( 'wapf/store_api/cart/schema_callback', $schema );
	}

	/**
	 * `wapf/pricing/display_options` — WAPF's frontend price-format options.
	 *
	 * OPF publishes a reduced `display_options` block inside
	 * `opf_frontend_config` (printed as `window.opf_config`) and the theme JS
	 * formats prices from it. The block is shaped for OPF's own keys
	 * (`symbol`/`thousand`/`decimal`/`decimals` plus `format` or
	 * `price_format`), while WAPF's `Util::get_price_display_options(true)`
	 * hands listeners the frontend shape (`format`, `symbol`, `decimals`,
	 * `decimal`, `thousand`, `trim_zeroes`, `tax_suffix`, `tax_enabled`,
	 * `price_incl_tax`, `tax_display`). This shim rebuilds that WAPF shape from
	 * the incoming OPF block, dispatches the WAPF hook with `$for_frontend`
	 * true, and writes only the keys the incoming block already carried back —
	 * so a listener that changes the symbol or the format pattern is
	 * reflected, and an unmodified dispatch returns the block unchanged.
	 *
	 * @param mixed $config `opf_frontend_config` value.
	 * @return mixed
	 */
	public static function shim_pricing_display_options( $config ) {
		if ( ! is_array( $config ) || ! isset( $config['display_options'] ) || ! is_array( $config['display_options'] ) ) {
			return $config;
		}

		$opf = $config['display_options'];
		$wapf = [
			'format'         => array_key_exists( 'format', $opf )
				? (string) $opf['format']
				: self::wc_price_format( (string) ( $opf['price_format'] ?? '' ) ),
			'symbol'         => (string) ( $opf['symbol'] ?? '' ),
			'decimals'       => (int) ( $opf['decimals'] ?? 2 ),
			'decimal'        => (string) ( $opf['decimal'] ?? '' ),
			'thousand'       => (string) ( $opf['thousand'] ?? '' ),
			'trim_zeroes'    => function_exists( 'apply_filters' ) ? (bool) apply_filters( 'woocommerce_price_trim_zeros', false ) : false,
			'tax_suffix'     => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_price_display_suffix', '' ) : '',
			'tax_enabled'    => function_exists( 'wc_tax_enabled' ) ? (bool) wc_tax_enabled() : false,
			'price_incl_tax' => function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : false,
			'tax_display'    => function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_tax_display_shop', '' ) : '',
		];

		$wapf = (array) apply_filters( 'wapf/pricing/display_options', $wapf, true );

		foreach ( [ 'symbol', 'thousand', 'decimal', 'decimals' ] as $key ) {
			if ( array_key_exists( $key, $opf ) && array_key_exists( $key, $wapf ) ) {
				$config['display_options'][ $key ] = 'decimals' === $key ? (int) $wapf[ $key ] : (string) $wapf[ $key ];
			}
		}
		if ( array_key_exists( 'format', $opf ) && array_key_exists( 'format', $wapf ) ) {
			$config['display_options']['format'] = (string) $wapf['format'];
		}
		if ( array_key_exists( 'price_format', $opf ) && array_key_exists( 'format', $wapf ) ) {
			$config['display_options']['price_format'] = str_replace( [ '%1$s', '%2$s' ], [ 'symbol', 'price' ], (string) $wapf['format'] );
		}

		return $config;
	}

	/**
	 * WAPF's storefront price-format pattern: turn OPF's `symbolprice` shape
	 * back into a `sprintf()` pattern, defaulting to WooCommerce's own.
	 *
	 * @param string $price_format `symbolprice`-style pattern ('' when absent).
	 */
	private static function wc_price_format( string $price_format ): string {
		if ( '' !== $price_format ) {
			return str_replace( [ 'symbol', 'price' ], [ '%1$s', '%2$s' ], $price_format );
		}
		return function_exists( 'get_woocommerce_price_format' ) ? (string) get_woocommerce_price_format() : '%1$s%2$s';
	}

	/**
	 * `wapf/features/change_price_html` — gate OPF's catalog price-html
	 * override. WAPF shape: ($change, ) with a boolean default of true.
	 *
	 * @param string $price_html Native WooCommerce price markup.
	 * @param mixed  $product    Product being rendered.
	 */
	public static function feature_change_price_html( $price_html, $product = null ) {
		if ( ! apply_filters( 'wapf/features/change_price_html', true ) ) {
			return $price_html;
		}
		return \OPF\Service\ProductPriceDisplay::filter_price_html( $price_html, $product );
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
		$err = self::dispatch_without_native_wapf(
			'wapf/validate',
			false,
			[ [ 'error' => false ], $value, $field, $product_id, 0, $quantity, false, null ]
		);
		if ( is_array( $err ) && ! empty( $err['error'] ) && isset( $err['message'] ) && '' !== (string) $err['message'] ) {
			return [ (string) $err['message'] ];
		}
		return [];
	}

	/**
	 * WAPF namespaces. The Free package declares `SW_WAPF\…` and the
	 * Pro/Extended package `SW_WAPF_PRO\…`; both register their listeners with a
	 * WAPF `Field` object as the field argument.
	 */
	private const NATIVE_WAPF_NAMESPACES = [ 'SW_WAPF_PRO\\', 'SW_WAPF\\' ];

	/**
	 * Global functions WAPF registers on a bridged hook. WAPF Extended's date
	 * extension adds `wapfe_validate_cart_data` to `wapf/validate`
	 * (`extend/date.php:152`) and dereferences its third argument as an object
	 * (`extend/date.php:157`).
	 */
	private const NATIVE_WAPF_FUNCTIONS = [ 'wapfe_validate_cart_data' ];

	/**
	 * Dispatch a WAPF-named hook without handing WAPF's own listeners an OPF
	 * field.
	 *
	 * WAPF's contract for these hooks is a `Field` object: `Cart::validate_cart_data()`
	 * passes `$field_group->fields` entries (`includes/classes/class-cart.php:180`),
	 * and every listener WAPF registers on them dereferences that argument as an
	 * object — `Linked_Products_Controller::validate_cart()` even declares it as a
	 * typed parameter (`includes/controllers/class-linked-products-controller.php:455`),
	 * `wapfe_validate_cart_data()` reads `$field->type` (`extend/date.php:157`) and
	 * `maybe_add_pricing_class()` reads `$field->type` on
	 * `wapf/html/field_container_classes`
	 * (`includes/controllers/class-linked-products-controller.php:1021`). OPF works
	 * on normalized arrays, so a WAPF-native listener cannot accept its field
	 * argument: the typed one raises a `TypeError` and the others read a property
	 * off an array. Third-party WAPF listeners still receive the OPF field in the
	 * documented WAPF argument shape, and WAPF's own dispatch of the same hook
	 * still reaches its native listeners.
	 *
	 * Native listeners are removed only for this synchronous dispatch and
	 * restored at their original priority and position before returning,
	 * including when another listener throws.
	 *
	 * @param string  $hook_name Bridged WAPF hook.
	 * @param bool    $is_action Dispatch with do_action() instead of apply_filters().
	 * @param mixed[] $args      Hook arguments, beginning with the filtered value for filters.
	 * @return mixed
	 */
	private static function dispatch_without_native_wapf( string $hook_name, bool $is_action, array $args ) {
		$registry  = $GLOBALS['wp_filter'][ $hook_name ] ?? null;
		$callbacks = ( is_object( $registry ) && isset( $registry->callbacks ) && is_array( $registry->callbacks ) )
			? $registry->callbacks
			: ( is_array( $registry ) ? $registry : [] );
		$removed = [];

		foreach ( $callbacks as $priority => $priority_callbacks ) {
			foreach ( $priority_callbacks as $entry ) {
				$callback = $entry['function'] ?? null;
				if ( ! self::is_native_wapf_listener( $callback ) ) {
					continue;
				}

				$removed[] = [
					'callback'       => $callback,
					'priority'       => (int) $priority,
					'accepted_args'  => (int) ( $entry['accepted_args'] ?? 1 ),
					'priority_order' => array_keys( $priority_callbacks ),
				];
				remove_filter( $hook_name, $callback, (int) $priority );
			}
		}

		try {
			return $is_action ? do_action( $hook_name, ...$args ) : apply_filters( $hook_name, ...$args );
		} finally {
			foreach ( $removed as $entry ) {
				add_filter( $hook_name, $entry['callback'], $entry['priority'], $entry['accepted_args'] );
				self::restore_hook_callback_order( $hook_name, $entry );
			}
		}
	}

	/**
	 * Whether a hook callback is WAPF's own code rather than a third-party
	 * integration. The receiver's namespace is the discriminator, so a WAPF
	 * validator under a different class or method name is suspended too.
	 *
	 * @param mixed $callback WordPress filter/action callback.
	 */
	private static function is_native_wapf_listener( $callback ): bool {
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_object( $callback[0] ) && is_string( $callback[1] ) ) {
			$class_name = ltrim( get_class( $callback[0] ), '\\' );
			foreach ( self::NATIVE_WAPF_NAMESPACES as $namespace ) {
				if ( 0 === strpos( $class_name, $namespace ) ) {
					return true;
				}
			}
			return false;
		}

		return is_string( $callback ) && in_array( strtolower( ltrim( $callback, '\\' ) ), self::NATIVE_WAPF_FUNCTIONS, true );
	}

	/**
	 * Dispatch WAPF's order-again action for an OPF field without sending the
	 * normalized OPF array to WAPF's native linked-products Field-object handler.
	 *
	 * @param mixed ...$args Action arguments.
	 */
	private static function do_order_again_action_for_opf_field( ...$args ): void {
		self::dispatch_without_native_wapf( 'wapf/order_again/before_cart_item_field', true, $args );
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
	 * WAPF registers `Linked_Products_Controller::maybe_add_pricing_class()` on
	 * this hook and it reads `$field->type`
	 * (`includes/controllers/class-linked-products-controller.php:1021`), so the
	 * OPF field array must not reach it.
	 *
	 * @param string[] $classes Container classes.
	 * @param array    $field   Field definition.
	 */
	public static function field_container_classes( array $classes, array $field ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $classes;
		}
		return (array) self::dispatch_without_native_wapf( 'wapf/html/field_container_classes', false, [ $classes, $field ] );
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
