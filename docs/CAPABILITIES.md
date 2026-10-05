# Supported capabilities

This document describes the capability set implemented in OPF 0.1.0. The
canonical source is `OPF\Engine\FieldGroup::FIELD_TYPES` and
`OPF\Engine\FieldGroup::PRICING_TYPES`; documentation must not promise a
capability that is absent from those registries and their renderer, cart, and
validation paths.

## Available now

| Area | Implemented behavior |
| --- | --- |
| Field types | `text`, `textarea`, `email`, `url`, `number`, `date`, `toggle`, `select`, `radio`, `checkbox`, text/image/color `swatch` (single or multiple selection), `image_quantity` (image choices with bounded quantity inputs, choice pricing, and sum-of-quantities formula support), `upload` (private single/multiple upload foundation; limitations below), static `paragraph` (plain text or restricted HTML with optional shortcodes), sanitized `html` content, registered `shortcode` output, informative `content_image`, `section` / `section_end` layout markers, linked child `child_products` / `products` (WAPF-style purchasable child line items: checkbox/radio/dropdown/image/card/vcard/card-qty/vcard-qty subtypes, manual or category-driven selection, child quantity methods, and per-choice child pricing), and `calc` / `calculation` (WAPF Extended informational/cost calculations: formula-driven results with result_format/result_text templating, live recalculation, condition-subject support, and signed cost pricing) |
| Upload foundation | Native multipart and modern Ajax selection, drag/drop, progress, removal, type/size validation, session-owned opaque tokens, classic/Store API cart and checkout, order references and authorized downloads, temporary cleanup, and nonce-protected admin deletion. Upload definitions currently require REST/JSON configuration. Upload pricing and upload-specific repeaters remain open. WAPF upload import/export and the upload builder controls are implemented, and secure order-again reissue is implemented and proven; see `IMPEXP-LANE-EVIDENCE-2026-10-03.md` and `UPLOAD-REISSUE-EVIDENCE-2026-10-03.md`. |
| Email | Browser email input plus server-side rejection of malformed non-empty values |
| Toggle | Boolean input stored as `1` when checked and `0` when unchecked; a required toggle must be checked |
| Choice behavior | Defaults, disabled choices, single-select controls, and multiple checkbox selections |
| Conditions | Show or hide a field using `is`, `is_not`, `contains`, `not_contains`, `greater`, `less`, `empty`, and `not_empty` rules; condition blocks support all/any logic |
| Product placement | Product and product-term inclusion/exclusion rules |
| Visitor targeting | Field groups can target logged-in visitors, logged-out visitors, selected WordPress roles, and current WPML/Polylang language; product-group cache keys vary by viewer context. |
| Pricing | No price, fixed amount, percentage of product price, or safe arithmetic formula. The server calculates the cart price. Fixed prices are flat per line unless `per_unit` is enabled; percentage and formula prices are per unit. |
| Commerce flow | Classic product-form add to cart plus Store API add to cart; cart, block cart/checkout display, order-item storage, and order-again restoration |
| WooCommerce features | The plugin declares compatibility with HPOS and cart/checkout blocks |
| Administration | Field-group builder, authenticated `opf/v1` REST endpoints, WAPF import command, WP-CLI OPF archive export/import, and limited WAPF Tools JSON export |
| WAPF import | Maps supported field types and pricing, including Extended `image-swatch-qty` choices with defaults, per-choice min/max, and field-level `max_choices` caps; Free `content` / `paragraph` text; Extended `p` content with restricted HTML and optional shortcode processing; informative `img` content with URL/attachment references; and nested `section` / `sectionend` wrappers with conditions, plus `true-false` toggles, group `auth` / `!auth`, `role` / `!role`, `lang` / `!lang` rules, and field-condition operators `==`, `!=`, `==contains`, `!=contains`, `gt`, `lt`, `empty`, and `!empty`. HTML in Free paragraph payloads is stripped and flagged for review; site-local image attachments and repeated sections are flagged to review/remap; unsupported types, repeaters, and pricing remain review-required. |
| OPF archive migration | `wp opf import-archive <file>` validates a versioned export with a 5 MiB and 500-group limit, defaults to dry-run, rejects data the installed schema would drop, and imports repeated-safe groups. Portability warnings force affected groups to draft. Isolated WordPress round-trip proof remains open. |
| WAPF Tools JSON export | `wp opf export --group=<id> --format=wapf-json` exports one group in WAPF's `fields`, `conditions`, `layout`, and `variables` shape. Unsupported or lossy settings stop export; site-local placement IDs still need destination review. |
| WAPF WXR export | `wp opf export --all|--group=<id> --format=wapf-wxr --output=<file>` exports WAPF-compatible global groups as WordPress WXR for **Tools → Import → WordPress**. Unsupported or lossy groups stop export. OPF placement, media, language assignments, and OPF-only settings need destination review. |

## Not implemented in 0.1.0

OPF does not currently provide complete WAPF upload parity, time fields, repeatable fields,
child/linked products, visual previews, or third-party
integration adapters. These remain roadmap work. Lookup-table storage, CSV
import and `lookuptable()` evaluation are implemented (see
`WAPF-CAPABILITY-LEDGER.md` `WAPF-PRICE-MATRIX`).

There is no general compatibility guarantee for a theme, page builder,
currency plugin, translation plugin, subscription plugin, or another product
extension until OPF ships and documents an adapter and its tests.

## Formula syntax

Formula pricing accepts numbers, `+`, `-`, `*`, `/`, parentheses, and the
variables `[price]`, `[qty]`, `[addons]` (or `[options_total]`), and `[val]`.
Invalid formulas, division by zero, and non-finite results evaluate to zero.
OPF does not evaluate PHP or JavaScript expressions.

## Release claims

New field types and integrations remain roadmap work until their registry,
rendering, validation, server-side pricing where applicable, cart/order flow,
and automated coverage have shipped. Historical migration reports are not a
claim of support for capabilities outside this document.
