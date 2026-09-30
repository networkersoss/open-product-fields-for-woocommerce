# Supported capabilities

This document describes the capability set implemented in OPF 0.1.0. The
canonical source is `OPF\Engine\FieldGroup::FIELD_TYPES` and
`OPF\Engine\FieldGroup::PRICING_TYPES`; documentation must not promise a
capability that is absent from those registries and their renderer, cart, and
validation paths.

## Available now

| Area | Implemented behavior |
| --- | --- |
| Field types | `text`, `textarea`, `email`, `url`, `number`, `date`, `upload`, `toggle`, `select`, `radio`, horizontal/vertical single-choice radio cards, `checkbox`, text/image/colour `swatch`, linked `child_products`, static `paragraph`, sanitized `html` content, registered WordPress `shortcode` content, non-submittable `content_image`, `section`, and informational or price-adjusting `calculation` fields |
| Paragraph | Static escaped content between inputs; never submitted, stored, or priced |
| Shortcode | Non-submittable content field that executes registered WordPress shortcodes on the product page; output follows the shortcode provider’s behavior |
| Scalar constraints | Number whole/decimal mode plus min/max/step and text-like min/max length plus regex constraints are normalized, rendered as browser hints, and enforced server-side; WAPF's number mode/default maps from verified `number_type` behavior, while Extended step import still needs source proof |
| Defaults | Scalar and choice defaults are normalized, rendered, and applied server-side when omitted from a submission |
| URL quantity prefill | `?qty=` preselects the queried WooCommerce product quantity when it is a positive value within product min/max/step constraints; invalid values preserve the product default. Signed field-prefill payloads are supported separately; unsigned WAPF per-field query keys remain unsupported. |
| Date | Native date control with strict `YYYY-MM-DD` validation, optional static or relative minimum/maximum dates (`7d`, `-1m`) resolved in the WordPress site timezone, and a custom accessible calendar that follows WordPress’s configured `Week Starts On` setting |
| Email | Browser email input plus server-side rejection of malformed non-empty values |
| Toggle | Boolean input stored as `1` when checked and `0` when unchecked; a required toggle must be checked |
| Choice behavior | Defaults, disabled choices, single-select controls, radio cards with optional images/descriptions and optional main-image changes, conditional display and choice pricing, image swatch quantity inputs, linked-product checkbox/radio/select/image-card displays with optional quantity inputs and accessible image zoom, and checkbox/product selections with server-enforced minimum/maximum limits |
| Linked products | Builder can select specific products or product categories (category query limited to 50). Eligible children are real WooCommerce cart/order lines with their own SKU, stock, price, and tax. Classic cart and Store API flows sync child quantities to the parent, cascade parent removal, and reject insufficient child stock atomically. Category `price_type` semantics and import mapping remain unverified; see the parity ledger. |
| Bulk choice entry | Builder can append or replace select/radio/checkbox/swatch choices from newline or tab-separated text; optional fixed prices are parsed strictly and malformed batches leave the current choices intact |
| Field/choice capacity | No application-level field or choice count cap; schema regression coverage preserves 512 fields and 512 choices |
| Colour swatches | Swatch choices accept validated hex colours and expose them as accessible, non-authoritative presentation metadata |
| Image swatches | Swatch choices accept bounded HTTP(S)/relative image URLs with the choice label used as alternative text; optional per-field zoom enlarges images on hover or keyboard focus and respects reduced-motion preference |
| Image+quantity swatches | Each image choice can have its own quantity and limits; a separate per-field zoom setting enlarges images on hover or keyboard focus and respects reduced-motion preference |
| Conditional product image | Ordered AND-combination rules support exact choice values and `Any` wildcards, navigate to matching WooCommerce gallery images, or show independently selected Media Library/external image URLs; unmatched rules restore the original image and gallery slide. Image-bearing radio cards can also target gallery or external images and restore the original when unmatched or cleared. |
| Field instructions | Instructions can render inline or in an accessible hover/focus/click tooltip; WAPF Pro tooltip-mode import remains unverified because its public export guide does not document the serialized setting |
| HTML content | Admin-authored content is passed through WordPress post HTML sanitization, is never submitted, and does not execute shortcodes |
| Content image | Admin-authored HTTP(S) or local-path image with escaped URL/alt text; conditionally shown; non-submittable and non-priced |
| Sections | Non-submittable heading and plain-text content for layout grouping |
| Calculation fields | Informational formulas and signed price adjustments support arithmetic, numeric `[field.{id}]` references, direct comparisons to unquoted text literals, `[price]`, `[qty]`, `[options_total]`, `{result}` text, `len(string; ignoreSpaces)`, numeric comparisons with `if`/`and`/`or`, `acf(field_name)`, `acf_option(field_name)`, and `min`, `max`, `abs`, `round`, `ceil`, `floor`, `pow`, and `sqrt`. ACF functions require ACF to be installed and accept finite numeric values only; missing or unsupported values resolve to zero. Price adjustments are recalculated from validated values on the server, included in cart/order line totals, and mirrored in the browser. Field-to-field text comparisons, other WAPF functions, and calculation import mapping remain unsupported or review-required. |
| File uploads | Classic and Store API product forms accept single or multiple files under normalized minimum/maximum count, size, type, minimum size in MB, and minimum pixel dimensions; `-1` means no configured maximum. Minimum size is checked against measured temporary/private bytes. Dimension checks derive MIME and dimensions from temporary/private file bytes, with Fileinfo and WordPress MIME agreement required before `getimagesize`; unverified and undersized files fail closed. Optional image resize uses WordPress image editors to scale oversized images to configured max dimensions without cropping, preserving aspect ratio and original MIME. The default Ajax uploader supports drag/drop, progress, removal, and image previews; pending transfers block add-to-cart, and minimum-count rules block submission. Files are content-checked, executables/markup are refused, and opaque 48-hex tokens are stored outside the served webroot by default (`opf_upload_storage_dir` can select another verified outside-root path). CLI/cron must provide `OPF_WEB_ROOT` or the `opf_upload_document_root` filter; storage fails closed when the served root is unknown. Existing token files in `uploads/.opf-private` are migrated with content verification. Uploads attach to the cart line, persist on the order as display names plus hidden references, download through an authorized REST endpoint (managers, order owners, or the guest order-key link), are copied to new tokens on order-again, and are deleted with the order or after a week as unreferenced orphans. Uninstall preserves upload bytes referenced by historical orders. Image-editor/crop behavior remains partial. |
| URL prefill | Configured fields accept allow-listed query keys, including percent-decoded text and comma-separated multi-choice values; alternatively, bounded HMAC-signed `opf_prefill` payloads are supported. Normal field validation applies. |
| Conditions | Show or hide a field using `is`, `is_not`, `contains`, `greater`, `less`, `empty`, and `not_empty` rules; condition blocks support all/any logic |
| Product placement | Product and product-term inclusion/exclusion rules |
| Pricing | No price, fixed amount, percentage, quantity, character-count, numeric-value, or safe arithmetic formula. Formulas support bounded `min`, `max`, `abs`, `round`, `ceil`, `floor`, `pow`, `sqrt`, `len`, direct unquoted text comparisons, numeric `if`/`and`/`or`, numeric comparisons, and numeric ACF `acf(field_name)`/`acf_option(field_name)` references; the server calculates the cart price. ACF functions require ACF. Fixed prices are flat per line unless `per_unit` is enabled; other modes are per unit. |
| Weight | Fixed choice weight deltas, image-quantity weight scaling, and per-field arithmetic formulas over numeric `[field.{id}]` values are applied to the cart product in WooCommerce's configured weight unit before shipping; cart and order-again lifecycle are verified. WAPF formula-weight import serialization remains review-required. |
| Commerce flow | Classic product-form add to cart plus Store API add to cart; cart, block cart/checkout display, order-item storage, and order-again restoration |
| WooCommerce features | The plugin declares compatibility with HPOS and cart/checkout blocks |
| Administration | Field-group builder, authenticated `opf/v1` REST endpoints, WAPF import command, and `wp opf export` for versioned OPF archives, one-group WAPF JSON, or all compatible WAPF groups as WXR |
| WAPF import | Maps supported field types and pricing. Unsupported types, repeaters, upload constraints, and unsupported pricing are recorded for review; tooltip-mode serialization is not yet mapped because its Pro export key is unverified. Groups needing review are imported as drafts and are never published as partial forms. |

## Not implemented in 0.1.0

OPF does not currently provide repeatable or quantity-cloned fields,
quantity inputs inside radio cards,
layered images, upload image editing, ACF-backed formula functions,
multi-step configurators, or third-party integration adapters. Image-quantity swatches and
price-adjusting calculation fields are implemented as described above; their
remaining WAPF compatibility and import differences are tracked in the
capability ledger. WAPF card imports remain review-required until the source
serialization is verified.

There is no general compatibility guarantee for a theme, page builder,
currency plugin, translation plugin, subscription plugin, or another product
extension until OPF ships and documents an adapter and its tests.

WP-CLI and cron processes that manipulate uploads must define `OPF_WEB_ROOT`
or provide the `opf_upload_document_root` filter. OPF does not treat `ABSPATH`
as the public root because Bedrock and other nested WordPress installations
may serve a different directory.

## Formula syntax

Formula pricing accepts numbers, `+`, `-`, `*`, `/`, parentheses, and the
variables `[price]`, `[qty]`, `[addons]` (or `[options_total]`), and `[val]`.
Informational calculation fields also resolve numeric `[field.{id}]` references.
Invalid formulas, division by zero, and non-finite results evaluate to zero.
OPF does not evaluate PHP or JavaScript expressions.

## Release claims

New field types and integrations remain roadmap work until their registry,
rendering, validation, server-side pricing where applicable, cart/order flow,
and automated coverage have shipped. Historical migration reports are not a
claim of support for capabilities outside this document.
