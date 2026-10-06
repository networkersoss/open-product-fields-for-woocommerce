=== Open Product Fields for WooCommerce ===
Contributors: ssthormess
Tags: woocommerce, product fields, product addons, custom fields, conditional logic
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 9.0
WC tested up to: 11.1
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build custom product fields and add-ons for WooCommerce — free, open source, with conditional logic and pricing.

== Description ==

Open Product Fields lets you add custom fields and add-ons to WooCommerce product pages. Version 0.1.2 ships text, textarea, email, URL, number, and toggle fields; select, radio, and checkbox choices; text, image, and color swatches; date fields; file uploads; and content, section, and paragraph blocks. Fields support conditional logic, repeaters, and server-side pricing with fixed amounts, percentages, formulas, lookup tables, and quantity or weight modifiers. It runs on classic WooCommerce product pages and the Store API/block checkout, targets child products, imports and exports WAPF definitions, and translates through WPML or Polylang. See the [supported capabilities](https://github.com/networkersoss/open-product-fields-for-woocommerce/blob/master/docs/CAPABILITIES.md) for the complete current scope and limitations.

Private file uploads are implemented with builder controls (multiple files, accepted types, maximum size), native and Ajax transport, thumbnail previews, private order downloads, and classic/Store API checkout. WAPF upload settings round-trip through Tools JSON and WXR, and order-again reissues an authorized private copy. Upload-specific repeaters and upload pricing remain open, so this is not full WAPF upload parity.

= Why "Open"? =

This plugin is 100% free and open source (GPLv2 or later). No license keys, no nags, no crippled Lite version, no upsell walls. Every feature is in every copy.

**Features:**

* Field types: text, textarea, URL, number, select, radio, checkbox, text, image, and color swatches with single or multiple selection
* Conditional logic (show/hide fields based on other values)
* Pricing per choice: fixed, percentage of product price, and math formulas — always computed server-side
* Works on classic product pages AND block-based cart/checkout (Store API)
* Per-field order-item metadata stored through WooCommerce's order-item API; compatible with declared HPOS support
* Migration tool: imports the supported WAPF field types and flags unsupported fields or repeaters for review; it can recover corrupted legacy payloads (`wp opf import-wapf`)
* Optional legacy-markup compatibility mode for migrations from themes that expect WAPF-style classes and attributes
* REST API (`opf/v1`) and dependency-free JavaScript builder
* Zero asset weight on pages without fields; no jQuery

== Installation ==

1. Install and activate WooCommerce.
2. Upload the `open-product-fields-for-woocommerce` folder to `/wp-content/plugins/`, or install from the Plugins screen.
3. Activate "Open Product Fields for WooCommerce" and open the field builder from a product's edit screen (Field Groups, under the WooCommerce menu).

== Frequently Asked Questions ==

= Is it really free? =

Yes. GPLv2 or later — use it on any number of sites, for any purpose, at no cost.

= Does it work with my theme? =

OPF renders fields through standard WooCommerce product hooks. Validate it with your theme before launch. Its optional compatibility mode emits the legacy WAPF-style markup used by followersya's migration; it is not a general compatibility guarantee for third-party themes or field plugins.

= Migrating from Advanced Product Fields (WAPF)? =

Run `wp opf import-wapf` (dry run first, then `--commit`). Supported placement rules, field types, choices, and pricing are mapped; unsupported field types, repeaters, and pricing are flagged for review. See docs/MIGRATION.md and docs/CAPABILITIES.md.

== Changelog ==

= 0.1.2 =
* Quick views work: the frontend bundle and a modal adapter now ship on the pages whose quick view can open fields (Barn2 Quick View Pro, Astra + Astra Pro, Flatsome, Woodmart), field groups can be re-initialized after they are injected, and each rendered group carries its own settings payload for markup added after page load.
* Gallery image rules and selected-variation images paint the main image directly when the active gallery is Swiper or Flickity, the active slide is read from WooCommerce's own slide viewport, an unchanged gallery is no longer rewritten, and multi-value companion inputs no longer break Barn2 Quick View Pro's modal add-to-cart.
* Choice price hints print the decoded currency symbol (for example `Gold (+$10)`) instead of the escaped HTML entity.
* Weight expressions are evaluated by the pricing parser (with `[x]`, `[qty]` and numeric `[field.id]` references) instead of being read as a plain number, and the Modern file uploader setting now controls the storefront uploader.
* Localized catalogs refreshed for 0.1.2; WPML was proven on its current 5.1.0 stack, and the compatibility audit now records 122 supported capabilities with 9 documented differences.

= 0.1.1 =
* Import WAPF Tools JSON exports from a file, with a dry-run review of every group and field before anything is written (`wp opf import-wapf` and the admin import page).
* Round-trip WAPF switch controls for toggle and checkbox fields through export and import.
* Fall back to the selected variation's image when an image-change rule has no matching image.
* Skip WPML string-package registration with a warning when String Translation is unavailable, instead of failing on the missing API.
* Release archives no longer ship the dead `assets/js/opf-frontend.min.js` or the internal `tasks/` planning notes.

= 0.1.0 =
* Field groups with conditional logic and server-side pricing.
* Classic and block cart/checkout support, order persistence, order-again restore.
* WAPF import tool with corruption repair and WP-CLI command.
