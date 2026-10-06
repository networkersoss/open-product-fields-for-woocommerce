<?php
/**
 * Ships the frontend bundle to pages that can open a quick-view modal.
 *
 * @package open-product-fields-for-woocommerce
 */

namespace OPF\Service;

defined( 'ABSPATH' ) || exit;

/**
 * The frontend module is enqueued from `Renderer::render()`, i.e. only on the
 * request that renders fields. A shop/archive page whose quick view injects the
 * product markup through Ajax therefore gets field markup with no runtime: the
 * modal shows the fields and nothing behind them works (no conditionals, no
 * totals, no image rules). This service adds the bundle — plus the modal
 * re-init adapter `assets/js/opf-quick-view.js` — on pages where one of the
 * supported quick views can open:
 *
 *  - Barn2 WooCommerce Quick View Pro: its runtime decides per request whether
 *    to load, so `wc-quick-view-pro` being enqueued (or its
 *    `wc_quick_view_pro_load_scripts` action having fired while rendering a
 *    loop button) is the exact signal.
 *  - Astra + Astra Pro (`shop-quick-view-enable`), Flatsome (`disable_quick_view`
 *    off) and Woodmart (`quick_view` on): enabled in the theme's own settings
 *    and rendered from a product loop, so the loop context is checked too.
 *
 * "Zero bytes where no fields render" still holds: nothing is enqueued unless a
 * quick-view surface is detected on the current request.
 */
final class QuickView {

	/** Registered in Assets::register_frontend(). */
	const ADAPTER_HANDLE = 'opf-quick-view';

	/** Kept for the surface probe; the Barn2 plugin registers this handle. */
	const BARN2_HANDLE = 'wc-quick-view-pro';

	/** Set once the bundle has been enqueued for this request. */
	private static $enqueued = false;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		// Barn2 loads its runtime while rendering a loop button, which happens
		// after wp_enqueue_scripts on a page that is not a WooCommerce archive.
		add_action( 'wc_quick_view_pro_load_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ], 20 );
	}

	/**
	 * Enqueue on any request a quick-view surface can open on.
	 */
	public static function maybe_enqueue(): void {
		if ( '' !== self::surface() ) {
			self::enqueue();
		}
	}

	/**
	 * Quick-view surface detected for this request.
	 *
	 * @return string 'barn2' | 'astra' | 'flatsome' | 'woodmart' | '' when none can open.
	 */
	public static function surface(): string {
		// Exact signal: the Quick View Pro runtime only enqueues its own script
		// on a request where it will render a button — including a plain page
		// carrying the `[products]` / `[quick_view]` shortcode, where it loads
		// while the loop renders (after this hook) and our action handler runs.
		if ( function_exists( 'wp_script_is' ) && wp_script_is( self::BARN2_HANDLE, 'enqueued' ) ) {
			return 'barn2';
		}
		if ( ! self::renders_product_loop() ) {
			return '';
		}
		if ( self::astra_quick_view() ) {
			return 'astra';
		}
		if ( self::flatsome_quick_view() ) {
			return 'flatsome';
		}
		if ( self::woodmart_quick_view() ) {
			return 'woodmart';
		}
		return '';
	}

	/**
	 * Enqueue the frontend module + stylesheet and the modal re-init adapter.
	 * Idempotent: both hooks can fire on one request.
	 */
	public static function enqueue(): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		// No registry exists on this request, and the config must not carry the
		// base price of whatever the loop renders: the injected group is priced
		// from its own data-opf-product-price.
		Assets::enqueue_frontend( [], false );
		wp_enqueue_script( self::ADAPTER_HANDLE );
	}

	/**
	 * Whether this request is a WooCommerce page that can render a product loop.
	 * Astra's, Flatsome's and Woodmart's quick-view buttons are printed from the
	 * loop (`woocommerce_after_shop_loop_item` and the themes' own loop hooks),
	 * and a product loop also appears in the related/upsell lists of a single
	 * product and the cross-sells of the cart, so `is_woocommerce()` + the cart
	 * cover every loop surface a quick view can attach to. Product shortcodes and
	 * blocks embedded in a plain page (a `[products]` landing page) are checked
	 * too, since those are the pages that carry a loop without a WooCommerce
	 * template.
	 */
	private static function renders_product_loop(): bool {
		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
			return true;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		$post    = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		$content = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';
		if ( '' === $content ) {
			return false;
		}
		foreach ( [ 'products', 'product_category', 'product_categories', 'recent_products', 'featured_products', 'sale_products', 'best_selling_products', 'top_rated_products' ] as $shortcode ) {
			if ( function_exists( 'has_shortcode' ) && has_shortcode( $content, $shortcode ) ) {
				return true;
			}
		}
		foreach ( [ 'woocommerce/product-collection', 'woocommerce/all-products', 'woocommerce/handpicked-products', 'woocommerce/product-best-sellers', 'woocommerce/product-new', 'woocommerce/product-top-rated' ] as $block ) {
			if ( function_exists( 'has_block' ) && has_block( $block, $post ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Astra Pro quick view, enabled per store in Astra's WooCommerce addon
	 * (`shop-quick-view-enable`: on-image, on-image-click, after-summary, or
	 * 'disabled'). Astra's own option reader merges the stored settings with the
	 * theme defaults, so a site without the addon never matches.
	 */
	private static function astra_quick_view(): bool {
		if ( ! function_exists( 'astra_get_option' ) ) {
			return false;
		}
		$enabled = \astra_get_option( 'shop-quick-view-enable' );
		return ! empty( $enabled ) && 'disabled' !== $enabled;
	}

	/**
	 * Flatsome quick view, on unless the store disabled it (the theme loads its
	 * quick-view extension on exactly this check).
	 */
	private static function flatsome_quick_view(): bool {
		if ( ! function_exists( 'get_template' ) || 'flatsome' !== get_template() ) {
			return false;
		}
		return ! get_theme_mod( 'disable_quick_view', 0 );
	}

	/**
	 * Woodmart quick view. `woodmart_get_opt()` ships with the theme and merges
	 * the theme defaults (`quick_view` defaults to on), so the function check is
	 * the theme check.
	 */
	private static function woodmart_quick_view(): bool {
		return function_exists( 'woodmart_get_opt' ) && (bool) \woodmart_get_opt( 'quick_view' );
	}
}
