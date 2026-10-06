# Changelog

User-facing changes to Open Product Fields for WooCommerce.

## Unreleased

### Added

- Formula pricing now includes WAPF math, text-length, and conditional functions
  in server calculations and browser totals.
- Extended `datediff()` formulas now calculate whole calendar days between
  configured date values, including `today()` and validated sibling fields.
- Extended `checked(field ID)` formulas now count selected multi-select values
  in server pricing and browser totals.
- Formula pricing resolves prior-field `[price.ID]` references across groups
  in server carts and browser totals; imported forward or self references
  remain flagged for review.
- WAPF formula imports remap recognized field references to generated OPF IDs
  and flag references that cannot be resolved safely.
- Text, image, and color swatches can accept multiple selections, with minimum
  and maximum selection limits.
- Color swatches support validated hex values, shape and size settings, and
  accessible labels.
- WAPF imports and exports preserve the matching single/multiple swatch type
  and its supported settings.
- WAPF local text-field imports preserve configured default values.
- WAPF WXR exports retain field-level formula pricing expressions.
- Paragraphs support plain text or restricted HTML, with optional WordPress
  shortcode processing. WAPF Extended `p` content imports and exports with its
  markup and shortcode payload preserved.
- Informative images support conditional display, Media Library selection, and
  WAPF `img` migration, preserving image URLs and attachment references for
  JSON/WXR export.
- Nested section markers support conditional wrappers and WAPF import/export;
  WAPF repeated-section settings stay review-required.
- WAPF button and quantity clone modes import into review drafts, preserving
  recognized modes and in-range button maxima for later migration work.
- Repeated fields and sections evaluate child conditions and date-based pricing
  formulas against values from the matching clone in browser totals and carts.

## 0.1.2 — 2026-10-06

### Added

- Quick views work: the frontend bundle and a modal adapter ship on the pages a
  quick view can open from (Barn2 Quick View Pro, Astra + Astra Pro, Flatsome,
  Woodmart), the module exposes a public re-init entry point
  (`OPF_FRONTEND.reinit(root)`, the `opf:reinit` event), and every rendered
  field group carries its own client payload (`data-opf-registry`) so a group
  injected after page load prices and renders without a page-level registry.
- Weight expressions stored on a field are evaluated by the pricing parser
  (`[x]`, `[qty]` and numeric `[field.id]` references) instead of being read as
  a plain number; an expression the parser rejects fails closed to 0.

### Fixed

- Gallery image rules and selected-variation images paint the product image
  directly when the active gallery is Swiper/Flickity, so a matched rule is no
  longer silently dropped on non-FlexSlider themes.
- The active gallery slide is read from WooCommerce's own slide viewport, so a
  theme thumbnail strip (Astra Pro) is no longer mistaken for the main slide
  and the original image is restored.
- Gallery restoration only rewrites image attributes when something actually
  changed, removing the Astra Pro re-render loop that ended in a FlexSlider
  `TypeError … 'animating'`.
- Choice price hints print the decoded currency symbol (`Gold (+$10)`) instead
  of the HTML-escaped entity.
- The "nothing selected" companion of a multi-value field posts a bracketed name
  beside its `[]` siblings, so Barn2 Quick View Pro's serializer no longer throws
  `TypeError: t[o].push is not a function` and the modal add-to-cart completes
  over its Ajax route.
- The admin **Modern file uploader** setting now controls the storefront
  uploader, and the setting's rendered default matches the effective state; an
  unsaved toggle still lets the migrated legacy option decide.

### Verified

- Localized catalogs refreshed for 0.1.2 (POT, `es_ES` PO/MO and the builder
  JED), with the shipped Spanish translations preserved.
- Four compatibility integrations promoted from `needs audit` to `supported` on
  executed OPF-vs-WAPF-Extended 3.1.5 comparisons: Barn2 Quick View Pro 15/15
  (from 7/15), Flatsome 13/14 (= WAPF), Woodmart 14/14 (= WAPF) and Astra 16/17
  (one by-design miss, one parity miss WAPF fails too).
- WPML proven on the current 5.1.0 stack (core 5.1.0 + String Translation 5.1.0
  + WCML 5.6.1): package editor, Spanish storefront render and multilingual
  cart/order, including the silent-no-op guard.
- Supported-row audit closed: fast-test coverage added for five code-only rows,
  two rows honestly re-graded, leaving 122 supported and 9 documented
  differences across the 131 edition rows.

## 0.1.1 — 2026-10-06

### Added

- WAPF Tools JSON exports can be imported from a file, with a dry-run review of
  every field and group before anything is written (`wp opf import-wapf` and the
  admin import page).
- WAPF switch controls survive an export/import round trip for toggle and
  checkbox fields.

### Fixed

- Image-change rules without a matching image fall back to the selected
  variation's image instead of leaving the previous variation's image in place.
- WPML string-package registration is skipped with a warning when String
  Translation is unavailable, instead of failing on the missing API.
- Release archives no longer ship the internal `tasks/` planning notes.

### Removed

- Stop shipping `assets/js/opf-frontend.min.js`: the file was tracked and
  packaged but never referenced, because `includes/Service/Assets.php`
  enqueues `assets/js/opf-frontend.js`.

### Verified

- Real WooCommerce Subscriptions 9.2.0 lifecycle parity: add-to-cart, renewal,
  refund, and order-again against a live subscription.
- WPML string translation proven at runtime on the supported WPML/WCML version
  sets, including multilingual cart and order flows.
