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
