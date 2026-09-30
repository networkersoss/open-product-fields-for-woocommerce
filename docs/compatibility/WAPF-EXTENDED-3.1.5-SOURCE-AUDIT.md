# WAPF Extended 3.1.5 source audit

Audit snapshot: 2026-09-30. The installed WAPF Extended package reports
version 3.1.5 in both its plugin header and the live WordPress plugin
registry; it was inactive during this read-only audit. Its package bundles
the Pro core plus Extended and linked-product controllers. The installed
directory contains 156 files, including 120 PHP files. No activation or
production behavior is inferred from static source.

Studio Wombat describes Extended as Pro plus Cards, linked Products,
Calculation, image swatches with quantities, extra formula functions, date
restrictions, weight changes, and enlarged image swatches. “Extended +
Addons” is a separate bundle that adds current and future add-ons; six
separate add-ons are tracked separately in the [full capability
ledger](WAPF-CAPABILITY-LEDGER.md).

Official edition boundary: [version comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/)
and [Extended changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/). The [product marketing page](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/) is audited separately below as a discovery and scope source.

## Official marketing claim crosswalk — checked 2026-09-30

The product page, tier comparison, and release changelog serve different
purposes. The tier comparison sets the Pro/Extended/add-ons boundary; the
landing page describes advertised use-cases and broad capabilities; the
version-specific changelogs record dated changes. Marketing claims help find
capabilities to reconcile, while source and lifecycle evidence remain required
for implementation acceptance.

| Published claim | Ledger scope | Audit disposition |
| --- | --- | --- |
| Pro has 16 field types; Extended includes Pro plus Cards, linked Products, Calculation, image swatches with quantities, extra date options, advanced formulas, weight changes, and image zoom | All `WAPF-FIELD-*`, `WAPF-DATE-*`, `WAPF-PRICE-*`, and `WAPF-COMMERCE-WEIGHT` rows | Cross-checked against the tier comparison and field-type guide. The installed 3.1.5 source inventory records the historical implementation; current release claims remain tied to the versioned changelog until source is available. |
| Product page advertises “20 different input types” and its FAQ enumerates field types | Field-type rows in the ledger | The marketing count is not a stable schema: the FAQ list repeats Cards, while the field guide groups some input types and separates content/shortcodes. The source registries and the ledger's individual capability rows define the auditable inventory; do not derive a missing feature from the headline count alone. |
| Conditional logic can show, hide, or adjust options and pricing | `WAPF-RULE-*`, field conditional rows, formula/pricing rows | Mapped to the dedicated placement, visibility, and pricing rows. Marketing wording does not specify operators, rule-group semantics, stored keys, or server revalidation. |
| Flat, quantity, percentage, formula, and lookup-table pricing; measurement-based products | `WAPF-PRICE-*`, `WAPF-FIELD-CALCULATION`, formula-function and lookup rows | Cross-checked with the pricing and formula documentation and installed 3.1.5 source. Pricing grammar, tax and cart/order results are accepted only from row-level source and commerce evidence. |
| Mix-and-match/bundles use existing linked products and stock; print-on-demand collects customer text, images, or files | `WAPF-FIELD-CHILD-PRODUCTS` and upload/content field rows | Mapped to linked child-product selection, native inventory/cart lifecycle, uploads, and content. Marketing scenarios do not establish support for every bundle/composite extension. |
| Global or per-product groups, repeaters, image switching, cart editing, WOOCS multi-currency, WPML/Polylang | Group/rule rows; `WAPF-INTERACTION-REPEAT`, `WAPF-INTERACTION-QUANTITY-REPEAT`, `WAPF-INTERACTION-IMAGE-CHANGE`, `WAPF-INTERACTION-CART-EDIT`, and integration/localization rows | Each maps to an existing ledger capability. The compatibility matrix and implementation source narrow each broad claim to named products, hooks, and verified lifecycle paths. |
| Theme and page-builder compatibility | `WAPF-COMPAT-*` rows | The product page qualifies compatibility by WooCommerce standards and names tested builders. The separate compatibility matrix supplies named integrations; neither claim is treated as universal compatibility proof. |
| “Extended + Addons” includes six separate add-ons; the landing page also shows live-preview imagery | `WAPF-ADDON-*` rows, excluded from the core Extended 1.0 gate | The tier comparison explicitly places add-ons in the separate bundle. Keep live-preview and the other add-on capabilities outside the core Extended parity denominator. |
| Product landing page displays version 3.2.2 while the tier-specific Extended changelog lists 3.2.1 and Pro changelog lists 3.2.2 | G0/G1 version boundary; all current-release delta rows | The landing page version label is not tier-specific. Use the separate Extended and Pro changelogs for their version targets; inspect the current Extended archive to determine whether the Pro 3.2.2 fix is bundled. |
| Marketing page states PHP 7.1+, WordPress 6.0+, and WooCommerce 7.0+ | `WAPF-COMPAT-MINIMUM-PLATFORM` | Recorded as the current paid-edition requirement claim; header/runtime source and actual compatibility testing determine the supported floor. |

Sources: [product marketing page](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/), [tier comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/), [all field types](https://www.studiowombat.com/knowledge-base/all-field-types/), [Extended changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/), and [Pro changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/changelog/).

## Extended capability inventory

| Capability IDs in ledger | Installed 3.1.5 source | Audited source behavior |
| --- | --- | --- |
| `WAPF-DATE-DYNAMIC-BOUNDS`, `WAPF-DATE-WEEKDAYS`, `WAPF-DATE-DISABLED-DATES`, `WAPF-DATE-CUTOFF` | `includes/controllers/class-extended-controller.php`, `extend/date.php`, `includes/classes/class-helper.php`, `includes/classes/class-cart.php`, `assets/js/extended.min.js`, `views/frontend/fields/date.php` | Options are `min_date`, `max_date`, `disabled_days`, `disabled_dates`, and `disable_today_after`. Bounds accept y/m/d offsets and references to another date field. Blackouts accept exact dates, recurring MM-DD dates, and inclusive ranges. The frontend receives corresponding data attributes; the server validation filter enforces blackouts, weekdays, bounds, and same-day cutoff. Display format is the global `wapf_date_format` option, default `mm-dd-yyyy`. |
| `WAPF-DATE-WEEK-START` | `assets/js/datepicker.min.js`; date configuration in `includes/controllers/class-extended-controller.php` | The installed Extended PHP settings do not expose a WordPress `start_of_week` option. OPF's configured-week-start support is an additional behavior, not a WAPF setting. |
| `WAPF-DATE-ACCESSIBILITY` | `views/frontend/fields/date.php`; `assets/js/datepicker.min.js` | The 3.1.5 input is `type="text"` with `inputmode="none"`; focus opens a custom calendar. Static picker markup has a generic `div` container, month/year controls at `tabindex="-1"` with labels, unlabeled `div` prev/next controls, and `li`/`span` day entries; the picked day alone receives `aria-selected`. The popup toggles `aria-hidden`. Input keydown blocks keys other than Backspace/Delete/Tab/Escape/Shift; the picker source has no `role` attributes or keyboard day-navigation handler. This is the 3.1.5 baseline only; the official 3.2.1 accessibility improvement must be compared against the current package and runtime behavior before accepting a contract. |
| `WAPF-FIELD-CARDS`, `WAPF-FIELD-CARDS-MAIN-IMAGE` | `includes/classes/class-config.php`, `includes/classes/class-html.php`, `includes/controllers/class-product-controller.php`, `assets/js/frontend.min.js` | `card` is a selectable, multi-choice field with choice pricing and appearance settings. Changelog v3.1 adds card-driven main-image switching. |
| `WAPF-FIELD-CHILD-PRODUCTS`, `WAPF-FIELD-CHILD-PRODUCTS-CATEGORY-PRICE-TYPE` | `includes/controllers/class-linked-products-controller.php`, `includes/classes/class-fields.php`, `includes/classes/class-cart.php`, `includes/classes/class-html.php`, `includes/classes/class-woocommerce-service.php`, `includes/controllers/class-product-controller.php` | The Products field stores `product_selection`, `product_query`, presentation subtype, selected products, quantity settings, selection bounds, and optional display slots. Category query `pricing_type` accepts `fixed` (default) or `none`; `none` sets the child line price to zero. Quantity selectors price from child quantity. Selected children become Woo cart/order lines; source hooks validate stock, synchronize quantity/removal, update stock, extend Store API data, persist parent-child order metadata, and restore order-again links. Changelog v3.0.8 adds category price type and caps category results at 50. |
| `WAPF-FIELD-CALCULATION` | `includes/controllers/class-extended-controller.php`, `includes/classes/class-config.php`, `includes/classes/class-fields.php`, `includes/classes/class-html.php`, `includes/controllers/class-public-controller.php`, `views/frontend/fields/calc.php`, `extend/formulas.php` | Field type `calc` stores `calc_type` (`default` informational or `cost` pricing), `formula`, `result_format`, and `result_text`. Cost calculations enter normal formula pricing. Changelog v3.1 allows calculations in other fields' conditionals. |
| `WAPF-FIELD-IMAGE-QUANTITIES`, `WAPF-FIELD-IMAGE-QUANTITY-ZOOM`, `WAPF-FIELD-SWATCH-IMAGE-ZOOM`, `WAPF-FIELD-CHILD-PRODUCTS-IMAGE-ZOOM` | `includes/classes/class-config.php`, `includes/classes/class-html.php`, `includes/classes/class-fields.php`, `includes/controllers/class-linked-products-controller.php`, `assets/js/frontend.min.js` | `image-swatch-qty` is a single-choice image field with quantity input and quantity-based pricing. `large_image` enables an enlarged choice image; markup exposes `data-zoom-url` for frontend behavior. Linked-product cards have independent image and display settings. |
| `WAPF-PRICE-FORMULA-WEIGHT`, `WAPF-COMMERCE-WEIGHT` | `includes/controllers/class-extended-controller.php`, `includes/classes/class-fields.php`, `includes/classes/class-cart.php`, `includes/controllers/class-linked-products-controller.php` | Field and choice settings store `weight`; quantity fields can store weight per quantity. Before Woo totals, selected values are resolved against the source field group and weight expressions accept `[qty]` and `[x]`. Values use WooCommerce's configured weight unit; linked products retain their own product weight. |
| `WAPF-PRICE-FORMULA-ADVANCED`, `WAPF-PRICE-FORMULA-TEXT-COMPARE`, `WAPF-PRICE-FORMULA-CHECKED`, `WAPF-PRICE-FORMULA-FIELD-STATE`, `WAPF-PRICE-FORMULA-SUM-QTY`, `WAPF-PRICE-FORMULA-TRIG`, `WAPF-PRICE-FORMULA-DATE`, `WAPF-PRICE-FORMULA-DOW`, `WAPF-PRICE-FORMULA-MONTH`, `WAPF-PRICE-FORMULA-CUSTOM-VARIABLE` | `includes/controllers/class-public-controller.php`, `includes/classes/class-config.php`, `includes/classes/class-helper.php`, `includes/classes/class-fields.php`, `includes/classes/class-field-groups.php`, `extend/formulas.php`, `extend/date.php`, `views/admin/variable-builder.php` | Core supplies `min`, `max`, `len`, and `lookuptable`; Extended registers `round`, `abs`, `floor`, `ceil`, `sqrt`, `cos`, `sin`, `tan`, `pow`, `sumQty`, `checked`, `files`, `if`, `or`, `and`, plus `today`, `datediff`, `dow`, and `month`. Formula arguments are semicolon-delimited; formula text comparisons are unquoted; `checked`, `files`, and `sumQty` take field IDs. Saved groups carry formula definitions and custom variables. PHP runtime and public formula-definition metadata are both in scope. |

These findings seed the Extended entries in the [capability
ledger](WAPF-CAPABILITY-LEDGER.md). This source audit does not claim OPF
parity; implementation, import, and commerce evidence must be established
separately for each capability. Pro is included because the Extended edition
bundles it. Six separately sold add-ons and compatibility entries remain
tracked separately and are outside this edition's 1.0 parity gate.

Official formula inventory: [formula function reference](https://www.studiowombat.com/knowledge-base/formula-functions-reference/).

## Bundled Pro-core source map (installed package 3.1.5)

Extended is built on the full Pro codebase. This map records the installed
package's Pro subsystems so the Extended-only inventory above is not mistaken
for the complete edition scope. Paths are relative to
`advanced-product-fields-for-woocommerce-extended/`.

| Pro subsystem | Installed source files | Source behavior inspected |
| --- | --- | --- |
| Bootstrap, models, and public PHP API | `class-wapf.php`; `includes/api/api-helpers.php`; `includes/models/class-field.php`; `includes/models/class-fieldgroup.php`; `includes/models/class-fieldpricing.php`; `includes/models/class-conditionrule.php`; `includes/models/class-conditionrulegroup.php` | Autoloading and plugin bootstrap load the admin, product, public, integrations, and Extended controllers. Field/group/pricing/condition models normalize the internal objects consumed by the remaining controllers; public helper functions expose field/group lookups and formula helpers. |
| Field schema and option settings | `includes/classes/class-config.php`; `includes/classes/class-fields.php`; `includes/models/class-field.php`; `views/admin/settings/*.php` | The registry defines scalar and choice field types, single versus multiple selection, accessible-label metadata, per-type options, required/default/min/max behavior, choice data, and extended registration through filters. The option model carries labels, descriptions, defaults, design controls, pricing, conditional rules, repeaters, and upload settings. |
| Pricing modes and calculation inputs | `includes/classes/class-config.php::get_pricing_options()`; `includes/classes/class-fields.php::do_pricing()`; `includes/classes/class-helper.php`; `includes/models/class-fieldpricing.php`; `extend/formulas.php` | Base pricing modes are `fixed` (flat), `qt` (quantity flat), `p` (percentage), `percent` (quantity percentage), and `fx` (formula); text-like fields add `char`/`charq`, and number/image-quantity fields add `nr`/`nrq`. Formula inputs include product price, product quantity, field values/prices, Extended options total, and configured variables. Pricing results are later applied through cart calculation rather than trusting browser-submitted totals. |
| Product/group and field conditional logic | `includes/classes/class-config.php`; `includes/classes/class-conditions.php`; `includes/classes/class-fields.php`; `includes/classes/class-field-groups.php`; `includes/models/class-condition*.php`; `includes/models/class-conditional*.php`; `views/admin/conditions.php` | Group placement conditions and field visibility conditions are distinct rule systems. The source resolves product, variation, category/tag/attribute, language, user/role and field-value rules; it builds browser condition data and re-evaluates server-side rules for submitted cart values. WAPF 3.1 adds calculation fields as dependencies. |
| Global/local authoring and migration | `includes/controllers/class-admin-controller.php`; `includes/classes/class-field-groups.php`; `includes/classes/class-wapf-list-table.php`; `views/admin/field.php`; `views/admin/field-list.php`; `views/admin/layout.php`; `views/admin/conditions.php`; `views/admin/variable-builder.php`; `views/admin/tools.php`; `views/admin/modal.php`; `views/admin/cpt-list-table.php`; admin JavaScript controllers | Product-local field groups and the global Field Groups post type have separate persistence/assignment paths. Global-group duplication is a nonce/capability-protected list action; product duplication is hooked to WooCommerce product duplication. Both generate new field IDs. The shared remapper updates field-condition references, formula pricing expressions, calculation formulas, variable defaults/rules, and gallery-image rule references. Static source review of `make_unique()` shows seven-character random IDs with no visible collision check in that method; formula and variable expressions are remapped using raw `str_replace()`, with no token-boundary handling visible there. Treat collision and partial-reference behavior as unverified edge cases, not proven runtime defects. Each field exposes a builder duplicate action. The Tools UI exports a code payload, imports by replace or append, and warns that images are not copied and must be relinked across sites. The inspected `field_group_to_raw_fields_json()` projection removes variation-specific and pattern condition rules plus generated rules, normalizes legacy group-rule condition names, defaults missing image-swatch grid layout, and flattens field options into the exported field object. `raw_json_to_field_group()` requires group ID/type, skips fields without ID/type, sanitizes known values, permits additional non-reserved option keys, and invokes `wapf/admin/sanitize_field` for extension handling. These method observations do not establish the entire Tools payload or round-trip equivalence. Current 3.2.1 behavior and runtime/round-trip proof remain open. |
| Storefront markup, design, and browser behavior | `includes/classes/class-html.php`; `includes/classes/class-design-helper.php`; `includes/controllers/class-product-controller.php`; `views/frontend/field-group.php`; `views/frontend/fields/*.php`; `assets/js/frontend.min.js`; `assets/js/datepicker.min.js`; `assets/css/frontend-*.min.css`; `assets/css/datepicker.min.css` | The renderer emits type-specific controls, labels, descriptions, price hints, conditional state, group layout, design classes, variation data, and pricing summary. Frontend JS handles choice state, condition updates, pricing previews, image switching, repeater controls, uploader interactions, and product quantity changes. Date picker behavior is in its dedicated script. |
| Cart validation, price calculation, order, and restore | `includes/classes/class-cart.php`; `includes/controllers/class-product-controller.php`; `includes/controllers/class-linked-products-controller.php`; `includes/controllers/class-public-controller.php` | The add-to-cart path reads raw field values, applies field/group/conditional and type-specific validation, recalculates price on the server, adds structured WAPF data to Woo cart lines, and renders configured values on cart/checkout/order surfaces. Source hooks also cover Store API data, cart edits, order metadata, order-again restoration, checkout validation, stock, and linked-child line handling. |
| File upload lifecycle | `includes/classes/class-file-upload.php`; `includes/controllers/class-public-controller.php`; `views/frontend/fields/file.php`; `assets/js/dropzone.min.js`; `assets/js/frontend.min.js` | Uploads have a dedicated Ajax and native-input path, allowed-type handling, size/count checks, storage-path protection files, cart-token validation, removal, order download/cleanup, and optional zip creation. The installed 3.1.5 implementation is the baseline; later security hardening/default changes are listed in the current-release delta above and remain to verify in the current package. |
| WooCommerce and theme/plugin adapters | `includes/controllers/class-integrations-controller.php`; 12 files under `includes/classes/integrations/` | The controller registers eight plugin adapters and three theme adapters. A WooCommerce Bookings adapter class also exists, but is absent from the registry and has no other package reference. The 3.1.5 bootstrap therefore does not load it; its file alone is not active runtime support. This conflicts with Wombat's older [WooCommerce Bookings compatibility claim](https://www.studiowombat.com/blog/best-woocommerce-product-add-ons-plugins/). Exact current-package status remains open. |
| Localization and translation integration | `languages/sw-wapf.pot`; `languages/sw-wapf-*.mo`; `wpml-config.xml`; `includes/controllers/class-admin-controller.php`; `class-wapf.php` | Strings use the `sw-wapf` text domain with a POT and bundled locale catalogs. WAPF registers its global group post type for Polylang; WPML config marks selected admin text options, while the WPML guide describes translation of group CPTs and product/variation fields. |
| Vendor settings, licensing, and update boundary | `includes/classes/class-licensing.php`; `includes/controllers/class-admin-controller.php`; `includes/controllers/class-extended-controller.php`; `includes/classes/class-config.php` | The admin exposes global labels, upload/date behavior and date format, summary/design settings, product price display and plugin license/update UI. Extended controllers add fields/date/formula/weight/linked-product options on top of the shared Pro settings framework. Licensed distribution/update behavior is separate from OPF's source behavior and does not enter the FOSS compatibility license decision. |
| Developer extension API | PHP `apply_filters()`, `do_action()`, and `do_action_ref_array()` calls across plugin PHP files; `includes/api/api-helpers.php` | A PHP-token scan of every installed 3.1.5 PHP file found 89 unique literal `wapf/...` filter names across 110 call sites, 8 direct `wapf/...` action names, and 1 `wapf/...` `do_action_ref_array` name. It also found one legacy `wapf_...` filter, three legacy `wapf_...` actions, and a dynamic `wapf/setting/{name}` filter family. The package has 12 global helper functions. Hook names cover field registration/rendering, validation, formula/pricing, cart/order, upload, linked products, admin screens, and integrations. Helper API is explicitly labeled beta in source and spans settings, custom formula functions, field-group display/lookups, cart/order reads, and field-group serialization. Counts do not define a stable documented API. Current-package signatures/arguments and OPF compatibility remain open. |

### Installed 3.1.5 product-gallery image switching

Gallery switching is configured at field-group layout level, separately from
an individual card or image choice changing the product image. The admin view
offers two modes: `rules` applies an image when a combination of option values
matches, with the **last matching rule winning**; `last` selects an image
according to the last option changed by the shopper. The same view says
true/false, select, swatch, checkbox, and radio fields can drive rules.

`raw_json_to_field_group()` defaults `swap_type` to `rules` when gallery
switching is enabled and normalizes each image row to `source`, `url`, `id`,
and `values` entries (`field` plus selected `value`). The product field-group
view emits the selected `swap_type` to the frontend. The 3.1.5 frontend asset
reverses each group's rules, then selects the first matching rule, so the last
configured matching rule wins. In `last` mode the change handler also passes
the changed field's ID; each non-wildcard condition must refer to that field as
well as match its current value. A field change with no matching image restores
the product/variation image. On initial render WAPF evaluates the first visible
input in the group; visibility dependency refreshes reuse that group's last
changed input. This is an interaction-history rule, distinct from the
value-only `rules` mode.

OPF now imports both 3.1.5 modes, remaps legacy field IDs, supports `*` and
true/false `0`/`1`, and preserves the mode in its frontend registry. Its
`last`-mode resolver matches non-wildcard rule conditions only against the
most recently changed field and restores the base image when none match.
Focused mapper/frontend tests pass; real-browser verification of the imported
`last` mode remains open. These are 3.1.5 source facts only; current Extended
3.2.1 remains unaudited.

Sources: `views/admin/settings/gallery-image.php` (mode descriptions and
supported field types), `includes/classes/class-field-groups.php:124-156`
(normalized stored keys), `views/frontend/field-group.php:24` (frontend mode
data attribute), and OPF's mapped behavior in `includes/Engine/WapfMapper.php`,
`includes/Engine/FieldGroup.php`, `includes/Service/Renderer.php`, and
`assets/js/opf-frontend.js`.

This source map covers the installed package's subsystem boundaries. It does
not assert that OPF matches each behavior: capability equivalence, migration
semantics, runtime integrations, and current 3.2.1/3.2.2 release differences
remain tracked as separate acceptance work.

The 3.1.5 literal-hook inventory seeds `WAPF-DEVELOPER-HOOKS` in the ledger;
the prior 92-filter/8-action count was incomplete and is superseded by the
token-scan count above. It does not prove every hook is public, supported, or
unchanged in 3.2.1. OPF's separately namespaced filters need an explicit
compatibility contract and documentation before this row can count as parity.
The exact installed hook names, call argument counts, and source locations are
listed in the [3.1.5 hook manifest](WAPF-EXTENDED-3.1.5-HOOK-MANIFEST.md).

The beta helper API is a separate capability from action/filter extension
points. OPF's current global functions are lifecycle bootstrap/activation
entrypoints only; static search found no corresponding public field-group,
cart/order, settings, serialization, or custom-formula-function helpers.
The ledger records this as a separate gap. WAPF signatures and behavior need
rechecking against current Extended source before treating the 3.1.5 API as a
fixed compatibility target.

### Installed 3.1.5 beta PHP helper signatures

The declarations and behavior below come from
`includes/api/api-helpers.php`. The file labels this API “BETA - PLEASE USE AT
OWN RISK AS API CAN CHANGE IN FUTURE UPDATES.” These are historical 3.1.5
facts, not a promise that Extended 3.2.1 preserves them.

| Function | Installed signature | Observed return/side effect |
| --- | --- | --- |
| `wapf_has_setting` | `wapf_has_setting( $name = '' )` | Forwards to `wapf_pro()->has_setting( $name )`. |
| `wapf_get_setting` | `wapf_get_setting( $name, $value = null )` | Reads the setting; uses `$value` only when the stored result is `null`; then applies `wapf/setting/{$name}` to the result. |
| `wapf_add_formula_function` | `wapf_add_formula_function( $func, $callback )` | Registers the callback through `Helper::add_formula_function`; no explicit return. |
| `wapf_display_field_groups_for_product` | `wapf_display_field_groups_for_product( $product )` | Returns `''` when no groups are found; otherwise returns rendered markup from `Html::display_field_groups`. |
| `wapf_product_has_options` | `wapf_product_has_options( $product )` | Forwards to `Field_Groups::product_has_field_group`. |
| `wapf_get_field_groups_of_product` | `wapf_get_field_groups_of_product( $product )` | Forwards to `Field_Groups::get_field_groups_of_product`. |
| `wapf_get_field_groups_by_ids` | `wapf_get_field_groups_by_ids( $ids = [] )` | Forwards to `Field_Groups::get_by_ids`. |
| `wapf_get_field_group_by_id` | `wapf_get_field_group_by_id( $id )` | Forwards to `Field_Groups::get_by_id`. |
| `wapf_get_options_from_order` | `wapf_get_options_from_order($order): array` | Accepts an order object or passes the argument to `wc_get_order`; returns line-item records (`product_id`, `item_id`, `quantity`, and option records with field ID, label, value, optional type). It skips linked-product fields when those are separate cart lines. No failed-order guard appears before `get_items()`. |
| `wapf_get_custom_fields_in_cart` | `wapf_get_custom_fields_in_cart()` | Returns `[]` when WooCommerce/cart is unavailable; otherwise cart-item records with cart key, product ID, and matched field ID/label/value records. |
| `wapf_fieldgroup_to_array` | `wapf_fieldgroup_to_array( FieldGroup $fg )` | Returns `$fg->to_array()`. |
| `wapf_array_to_fieldgroup` | `wapf_array_to_fieldgroup( array $a )` | Creates a `FieldGroup`, calls `from_array( $a )`, and returns it. |

Only the last two declarations type their inputs, and only the order helper
declares a return type. The order/cart helpers expose WAPF's `_wapf_meta` and
`wapf` storage conventions, so matching function names alone would not provide
API compatibility. `WAPF-DEVELOPER-PHP-API` remains a known OPF gap; current
3.2.1 signatures and migration guarantees await the licensed package.

### Installed 3.1.5 field-group deserialization contract

`Field_Groups::raw_json_to_field_group()` is called by the admin save path on
posted field-group JSON. It returns `null` when group `id` or `type` is empty;
sanitizes both as text; skips fields missing an `id` or `type`; and rebuilds
the group, layout, variables, fields, choices, conditionals, and group rules
from recognized structures. It sanitizes labels/descriptions as allowed HTML,
choice slugs and labels, default values by field type, numeric limits, clone
settings, pricing and formula strings, and gallery references. A choice without
a slug or label is dropped. Field conditionals retain only field/value/
condition rules; group rules retain condition/subject and sanitize scalar or
product-reference values. Section conditions generate child-field conditions
that are flagged `generated` and removed again by the export projection.

The parser also invokes `wapf/admin/sanitize_field` by reference before
processing remaining field keys. Non-reserved keys not already populated in
the normalized options are retained: `formula` gets text-without-tags
sanitization and other values get textarea sanitization. This is an
extension-preserving but type-generic path; it is not proof that arbitrary
third-party values round-trip unchanged. This method is on the ordinary
admin-save path; the minified/obfuscated admin JavaScript mediates the Tools
modal, so the exact Tools payload transformation and full import/export
round-trip remain unproven from this helper alone. These are installed 3.1.5
source facts, not current 3.2.1 confirmation.

### Installed 3.1.5 Tools import/export behavior

The Tools view (`views/admin/tools.php`) presents a code payload, offers
replace (default) and append import modes, and warns that cross-site images are
not copied. Static inspection of the installed `assets/js/admin.min.js`
controller shows the payload contains `fields`, `conditions`, `layout`, and
`variables`; it does not carry the source group ID or title. Import creates new
field IDs and updates recognized field-condition, formula, calculation,
choice-formula, variable, and gallery references. The linked-product remapping
loop is empty, so linked-product references are not rewritten by this path.

The append label does not mean every section appends. Nonempty fields append or
replace according to the selected mode, while an empty fields list is a no-op.
Variables append or replace. Nonempty conditions replace existing conditions
even in append mode; an empty conditions list is a no-op, and the product editor
does not apply the group-level conditions import branch. Layout is always
rebuilt from defaults and then copied from the import, including in append
mode; an empty layout therefore resets layout settings. The import handler only
applies a parsed nonempty payload, so an empty top-level import is a no-op.
These asymmetries can produce a successful import whose conditions or layout
differ from what the user expects from “append”.

The export projection operates on field objects and mutates the in-memory group
while removing unsupported/generated rules and flattening field options. It is
not a full-group archive: group metadata, group conditions, and group rules are
not part of the four-value Tools payload. These findings describe the installed
3.1.5 admin asset; its obfuscated controller has not been executed in an
authenticated browser, and current Extended 3.2.1 behavior remains unaudited.

### Installed 3.1.5 integration adapter inventory

The controller conditionally registers eight plugin adapters and three theme
adapters from explicit class-name maps. The plugin bootstrap instantiates this
controller, and `add_integrations()` loops only those maps. The package
autoloads a class file only when a class is requested; it does not scan the
integrations directory. `WooCommerce_Bookings` appears only in
`class-woocommerce-bookings.php`, not in either registry or another package
call site. Therefore WAPF Extended 3.1.5 does not load this adapter through
its own bootstrap, despite the adapter implementation and Wombat's published
Bookings compatibility claim. This is a proven 3.1.5 source/marketing conflict,
not proof about Extended 3.2.1. The current licensed package must resolve it
before the `WAPF-PRODUCT-BOOKINGS` row can leave `needs audit`. Wombat's
current premium compatibility table lists YITH Booking & Appointment as a
code integration, but does not list WooCommerce Bookings. The page says it
does not guarantee third-party compatibility beyond its listed integrations
and that many unlisted plugins may still work, so this omission narrows the
published evidence but does not prove incompatibility. The current table and
the older generic compatibility claim remain in tension with the unregistered
3.1.5 adapter; exact 3.2.1 runtime source is still required.
This is separate from Wombat's YITH Booking & Appointment entry: its current
compatibility table marks that integration as `Code`, and the linked recipe
requires a valid license key to reveal a custom snippet for the site's
`functions.php`. It is not a bundled WAPF adapter in the 3.1.5 registry, and
the gated snippet's behavior cannot be audited from the public instructions.

| Source adapter | Detected behavior | Ledger mapping |
| --- | --- | --- |
| `class-aelia.php` | Converts product/variation bases, formula bases, linked-product choice prices, pricing hints, option totals, and browser totals to the active Aelia currency. | `WAPF-CURRENCY-AELIA` |
| `class-astra.php` | Reinitializes WAPF frontend behavior in Astra quick-view modal. | `WAPF-COMPAT-ASTRA` |
| `class-flatsome.php` | Reinitializes fields and pricing in Flatsome quick view. | `WAPF-COMPAT-FLATSOME` |
| `class-product-table.php` | Initializes fields for Barn2 product-table rows and synthesizes variation data for individually listed variations. | `WAPF-COMPAT-PRODUCT-TABLE` |
| `class-quickview.php` | Initializes Barn2 Quick View Pro fields, serializes field inputs during Ajax add-to-cart, and hides duplicate totals in modal. | `WAPF-COMPAT-QUICK-VIEW-PRO` |
| `class-tiered-pricing-table.php` | Uses `TierPricingTable\PriceManager` / `CartPriceManager`, the `tier_pricing_table_*` settings and hooks, and active tier-rule prices in browser/cart; honors variation and summarized-quantity rules; adds option prices to tiered cart display. Wombat's [current product page](https://www.studiowombat.com/plugin/woocommerce-quantity-discounts-rules-swatches/) describes WooCommerce Quantity Discounts, Rules & Swatches as its tier-pricing and quantity-rules product. This supports mapping the source adapter to that current public product name; exact licensed adapter/product versions and runtime behavior are not verified. | `WAPF-COMPAT-QUANTITY-RULES` |
| `class-woo-discount-rules.php` | Uses discount-plugin prices for product, variation, and cart bases; includes WAPF option prices in discount calculations; suppresses WAPF validation for generated free cart items. | `WAPF-COMPAT-WC-DISCOUNTS` |
| `class-woocommerce-bookings.php` | Class implementation adds booking product groups; per-field repeat-by-person-type settings; frontend clone/removal by person count; person-cost-multiplier pricing; and restrictions on pricing/repeater options. But `Integrations_Controller::$available_integrations` does not list this class and package search found no other instantiation. Effective runtime support is unverified. | `WAPF-PRODUCT-BOOKINGS` (new `needs audit` row) |
| `class-woocommerce-subscriptions.php` | Supports subscription product types and prices; updates variable-subscription browser base; skips field validation during early and regular renewal cart setup. | `WAPF-PRODUCT-SUBSCRIPTION` |
| `class-woocs.php` | Converts simple/variable bases, formulas, linked products, hints, and cart totals through WOOCS rates/back-conversion; updates browser currency formatting. | `WAPF-CURRENCY-WOOCS`, `WAPF-CURRENCY-FOX` (FOX equivalence still unverified) |
| `class-woodmart.php` | Reinitializes fields in Woodmart quick view; adjusts edit-cart redirect and product-gallery image classes/events. | `WAPF-COMPAT-WOODMART` |
| `class-yith-raq.php` | Carries validated fields/files/pricing into YITH quote requests, quote display/email/orders, quote-to-cart restoration, quantity behavior, and acceptance flow. | `WAPF-COMPAT-YITH-QUOTE` |

This pass found a distinct WooCommerce Bookings implementation absent from the
edition ledger, then found that installed 3.1.5 does not register it. OPF has
no Bookings-specific builder or person-type repeat and price bridge. Ledger
keeps this as `needs audit` until the supported WAPF runtime behavior is
established. Other adapter behaviors map to existing currency/product/
integration rows or the separate compatibility matrix; exact current-package
reconciliation remains part of G1.

### Installed 3.1.5 field-definition registry

`SW_WAPF_Config::get_field_definitions()` in
`includes/classes/class-config.php` contains 31 unique definition keys across
its frontend/admin definitions. Repeated keys are shared by those modes, not
additional field types. This is the exact source-level mapping to the current
ledger families:

| Registry keys | Ledger family |
| --- | --- |
| `text`, `textarea`, `number`, `email`, `url`, `select`, `checkboxes`, `radio`, `true-false` | `WAPF-FIELD-TEXT`, `WAPF-FIELD-TEXTAREA`, `WAPF-FIELD-NUMBER`, `WAPF-FIELD-EMAIL`, `WAPF-FIELD-URL`, `WAPF-FIELD-SELECT`, `WAPF-FIELD-CHECKBOX`, `WAPF-FIELD-RADIO`, `WAPF-FIELD-TOGGLE` |
| `image-swatch`, `multi-image-swatch`, `image-swatch-qty`, `color-swatch`, `multi-color-swatch`, `text-swatch`, `multi-text-swatch` | `WAPF-FIELD-SWATCH-IMAGE`, `WAPF-FIELD-SWATCH-MULTI`, `WAPF-FIELD-IMAGE-QUANTITIES`, `WAPF-FIELD-SWATCH-COLOUR` |
| `card`, `vcard` | `WAPF-FIELD-CARDS` |
| `products-checkbox`, `products-radio`, `products-dropdown`, `products-image`, `products-card`, `products-vcard`, `products-vcard-qty`, `products-card-qty` | `WAPF-FIELD-CHILD-PRODUCTS` and its category-pricing and image-zoom rows |
| `file` | `WAPF-FIELD-UPLOAD`, `WAPF-UPLOAD-AJAX-UI` |
| `p`, `img`, `section`, `sectionend` | `WAPF-FIELD-CONTENT-TEXT`, `WAPF-FIELD-CONTENT-IMAGE`, `WAPF-FIELD-SECTION` |

The 3.1.5 field-type registry and the linked-products controller's field
visibility registrations identify the best baseline match for the later
“cards with quantity inputs” changelog item as quantity-enabled child-product
cards (`products-card-qty` / `products-vcard-qty`, tracked by
`WAPF-FIELD-CHILD-PRODUCTS`). Those subtypes have only no/any-quantity rules in
3.1.5. Ordinary `card`/`vcard` and `image-swatch-qty` are different field
families; the latter has its own quantity-contains operators. The target
version's exact additions, stored representation, and evaluator behavior
remain unverified without 3.2.1 source.

Two related registrations are conditional or owned by other controllers and
must not be omitted from the full inventory: date is added only when the
`wapf_datepicker` setting is enabled; Extended registers `calc` through
`wapf/field_types` in `class-extended-controller.php`.

HTML and shortcode support are not separate WAPF field types. Free 1.7.1
registers `content` (with legacy `paragraph`) and stores plain text in
`p_content`; `class-field-groups.php` sanitizes the value as text and the
renderer escapes it. The Free field-options description explicitly presents
HTML and shortcodes as Pro upgrade behavior. Pro/Extended registers the `p`
“Text & HTML” field using the same `p_content` option, applies a minimal HTML
allowlist, then calls `do_shortcode()`. The `paragraph.php` view explicitly
exists to take over the Free paragraph view. The official field-types guide
also describes content fields and shortcodes as capabilities, not distinct
field types. These are three separately trackable edition behaviors—Free plain
text, Pro limited HTML, and Pro shortcode execution—but all map to the same
content-field data path. They are represented by `WAPF-FIELD-CONTENT-TEXT`,
`WAPF-FIELD-CONTENT-HTML`, and `WAPF-FIELD-SHORTCODE`; none should be
described or imported as a standalone WAPF `html` or `shortcode` field type.

OPF's ledger previously mislabeled the content rows as separate WAPF admin
field types and assumed a WAPF `shortcode` type during import. The row mapping
is corrected in the ledger: Free `content`/legacy `paragraph` is plain
text, while Pro HTML and shortcode behaviors use the `p` content type and
`p_content` option. Migration parity remains open until that payload is mapped
and verified.

`SW_WAPF_Config::get_pricing_options()` separately registers the general
`fixed`, `qt`, `p`, `percent`, and `fx` price types; text-like fields add
`char`/`charq`, while number and image-quantity fields add `nr`/`nrq`. These
map to the flat, quantity-flat, percentage, quantity-percentage, formula,
character-count and numeric-value pricing rows in the ledger. This closes the
installed 3.1.5 registry inventory only; equivalence and current 3.2.1 changes
remain release- and behavior-level audit work.

### Installed 3.1.5 field-group targeting conditions

`SW_WAPF_Config::get_fieldgroup_visibility_conditions()` registers these
placement families in `includes/classes/class-config.php`; the server
evaluator is `SW_WAPF_PRO\Includes\Classes\Conditions::check()` in
`includes/classes/class-conditions.php`:

| Source keys and behavior | Source implementation | Ledger row |
| --- | --- | --- |
| `auth` / `!auth`: logged in / logged out | `is_user_logged_in()` | `WAPF-RULE-AUTH` (new row) |
| `role` / `!role`: user has / does not have a selected role | `Helper::get_all_roles()` wraps `get_editable_roles()` for the builder; `Conditions::user_has_role()` checks `wp_get_current_user()->roles` server-side | `WAPF-RULE-ROLE` (new row) |
| `products` / `!products`: selected products | These are the builder keys; evaluator also accepts `product` / `!product` and checks Woo product IDs | `WAPF-RULE-PRODUCT` |
| `product_var` / `!product_var`: selected variations | Builder exposes both; evaluator's switch routes both keys to the same positive `is_product_variation(...) === true` result, so the visible `!product_var` path does not appear to invert in 3.1.5 source | `WAPF-RULE-VARIATION` |
| `product_cats` / `!product_cats`: product categories | Product/category membership; legacy `product_cat` values are normalized | `WAPF-RULE-CATEGORY` |
| `patts` / `!patts`: attribute terms, optionally any term via `*` | Product attribute resolver | `WAPF-RULE-ATTRIBUTE` |
| `p_tags` / `!p_tags`: product tags | Product-tag resolver | `WAPF-RULE-TAG` |
| `product_type` / `!product_type`: simple, variable, or grouped | Product-type resolver; 3.2 later filters admin choices to active/allowed types | `WAPF-RULE-TYPE` |
| `lang`: current language | Builder exposes positive equality only when WPML or Polylang languages are available; evaluator also accepts `!lang` for not-equal, though the current builder does not offer it. Resolver compares Polylang locale or WPML language code | `WAPF-RULE-LANGUAGE` (new row) |

The Free and installed OPF group-assignment rows already cover product,
variation, category, attribute, tag, type, and exclusion targeting. The Pro
source adds the three user/context conditions above. A repository-wide search
of OPF's `includes` and `assets/js` finds Polylang post-language assignment,
but no login-state, role, or group-language condition in its builder/evaluator;
the ledger therefore records those three as gaps. The exact latest-package
source is still required to confirm that these 3.1.5 keys and semantics remain
unchanged in Extended 3.2.1.

The field-group placement registry contains no date/time or scheduling rule;
scheduled groups use WordPress's native `future` post status and publish
event. Quantity-based rules are handled separately by `Fields::is_valid_rule()`
when the rule subject is `qty`; no quantity condition is registered in this
group-placement builder.

### Installed 3.1.5 rule-combination semantics

Both placement and field visibility use OR across rule groups and AND among
rules within one group. `Conditions::is_field_group_valid_for_product()`
returns true on the first valid `rules_groups` entry; its
`is_rule_group_valid()` returns false on the first failed rule. For field
visibility, `Fields::should_field_be_filled_out()` returns true on the first
`conditionals` entry whose `validate_rules()` passes; `validate_rules()` also
fails on the first failed rule. No field conditionals means the field is
visible, no field-group placement rules means the group is global, and an
empty rule group passes. The admin field UI explicitly separates groups with
“or”.

### Installed 3.1.5 field visibility conditions

`Config::get_field_visibility_conditions()` registers built-in field
condition operators in `includes/classes/class-config.php`. The linked
Products controller extends that registry in
`includes/controllers/class-linked-products-controller.php::add_field_visibility_conditions()`.
`Fields::is_valid_rule()` reads current request or validated cart values and
evaluates saved rules in `includes/classes/class-fields.php`.

| Field family | 3.1.5 condition keys and semantics |
| --- | --- |
| `text`, `email`, `url`, `textarea` | `==`, `!=`, `empty`, `!empty`, `==contains` |
| `calc` | `==`, `!=`, `gt`, `lt` |
| `number` | `==`, `!=`, `gt`, `lt`, `empty`, `!empty`, `==contains` |
| `true-false` | `check`, `!check` |
| `select`, `radio`, `image-swatch`, `color-swatch`, `color-swatches`, `text-swatch` | `==`, `!=`, `empty`, `!empty` against one option |
| `checkboxes`, `card`, `vcard`, `multi-image-swatch`, `multi-color-swatch`, `multi-text-swatch` | `==`/`!=` mean selection contains/does not contain the chosen option; also `empty`, `!empty` |
| `date` | `==`, `!=`, `gtd` (later), `ltd` (older), `empty`, `!empty`; display input uses `wapf_date_format`, rule values parse as `m-d-Y` |
| `file` | `empty`, `!empty` |
| `image-swatch-qty` | `empty`, `!empty`, `==contains`, `!=contains`; quantity comparison uses a numeric rule value |
| Linked product `products-dropdown`, `products-radio` | `==`, `!=` against a product; also `empty`, `!empty` |
| Linked product `products-checkbox`, `products-image`, `products-card`, `products-vcard` | `==`/`!=` mean selected-product contains/does not contain; also `empty`, `!empty` |
| Linked product `products-card-qty`, `products-vcard-qty` | Only `empty` (no quantity) and `!empty` (any quantity) in the installed 3.1.5 registry |

This establishes the child-product-card baseline behind the 3.1.6 changelog:
in 3.1.5 its quantity-card subtypes expose only no/any-quantity rules, while
non-quantity child-product cards expose selected-product contains/not-contains
rules. The changelog's “more conditional logic options” therefore means 3.1.6
added options beyond the existing quantity-card pair. Exact new operators,
saved keys, and evaluation behavior remain unknown until the current licensed
package is available.

## WAPF Free 1.7.1 source and edition boundary

The current WordPress.org source page lists Free 1.7.1; its plugin header and
readme agree. The inspected WordPress.org archive contains 79 files (53 PHP,
20 translation catalogs, 2 JS, and 2 CSS files). This is a public, GPL-licensed
source baseline separate from the production server, where the Extended
package is installed and the standalone Free plugin is absent. The official
[WordPress.org 1.7.1 archive](https://downloads.wordpress.org/plugin/advanced-product-fields-for-woocommerce.1.7.1.zip)
retrieved 2026-09-30 has SHA-256
`c51ad76eb88f0095704bcf6b0a7a9b12d3d18263028121d276bff96ea70f9171`.

| Free subsystem | 1.7.1 source | Audited behavior and edition boundary |
| --- | --- | --- |
| Field registry | `includes/classes/class-fields.php::get_field_types()`; `views/frontend/fields/*.php` | Ten types are marked Free in the registry: text, textarea, number, email, URL, select, true/false, checkboxes, radio, and paragraph/content. The same registry marks file, date, image/color/text swatches, cards, image quantities, calculation, child products, HTML/shortcodes, image, and section as Pro. Extended fields register through the paid package's `wapf/field_types` filter. |
| Free pricing boundary | `includes/classes/class-fields.php::get_pricing_options()`; `pricing_value()`; `do_pricing()` | The Free package exposes only `fixed` flat-fee pricing. The same registry marks quantity-flat, formula, percentage, numeric-value, and character-count pricing as Pro-only. Choice pricing is resolved by submitted choice slug; the server computes its add-on from validated values. |
| Core field validation and conditions | `includes/classes/class-fields.php`; `includes/classes/class-conditions.php`; `includes/classes/class-field-groups.php` | Values are type-sanitized (textarea, number, email, true/false and choices have dedicated paths); required state depends on field conditions; selection values are matched to configured choice slugs. Free conditions include the documented value/empty/checked tests; Pro-only rule operators and product/user targets are marked separately in source configuration. |
| Product groups and persistence | `includes/classes/class-field-groups.php`; `includes/controllers/class-admin-controller.php`; `includes/controllers/class-product-controller.php` | Global field-group CPT records and product-local `_wapf_fieldgroup` data have separate load/save paths. Product and variation rendering, product assignment, conditions, and field values are resolved before storefront output. Free supports simple/variable products and Ajax variation selection; targeting exact variations is Pro-only. |
| Cart/order lifecycle | `includes/controllers/class-product-controller.php`; `includes/classes/class-fields.php`; `includes/classes/class-woocommerce-service.php` | The controller renders on product pages, validates required fields at add-to-cart, stores structured `_wapf_meta` data, applies server-side add-on prices before totals, displays values on cart/checkout, persists order-item metadata, and restores saved values for order-again. The Free package intentionally disables WooCommerce Ajax add-to-cart when fields are present; the premium package adds integrations for supported Ajax product-page implementations. |
| Admin, options, and localization | `includes/controllers/class-admin-controller.php`; `includes/classes/class-wapf-list-table.php`; `views/admin/field.php`; `assets/js/admin.min.js`; `includes/classes/class-l10n.php`; `languages/`; `wpml-config.xml` | Admin supports product-local and global group editing, assignment rules, global add-to-cart text settings, per-field duplication, and nonce/capability-checked global-group duplication with new field IDs and conditional-reference remapping. The package ships its own POT/locale catalogs and WPML configuration. The WordPress.org feature page describes Free UI translations and marks subscriptions/multicurrency, variation-specific fields, advanced pricing, and richer targeting as paid boundaries. |

Official Free source and boundary references: [WordPress.org plugin page and
changelog](https://wordpress.org/plugins/advanced-product-fields-for-woocommerce/)
and [official Pro/Extended tier comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/).
### Free 1.6.22 Gift Card changelog source check

The official WordPress.org [Free 1.6.21 archive](https://downloads.wordpress.org/plugin/advanced-product-fields-for-woocommerce.1.6.21.zip)
and [Free 1.6.22 archive](https://downloads.wordpress.org/plugin/advanced-product-fields-for-woocommerce.1.6.22.zip)
have SHA-256
`9741270796d61583df9a66ab2b154434b7f2d6237569372489e3efd00e197db6` and
`a059ec283c187d614cf3041cebdc65ff014d954b5a4986f1ae336c23f02a636a`,
respectively. Excluding version/readme/changelog/catalog changes, the only PHP
behavior delta is in `includes/controllers/class-product-controller.php::validate_cart_data()`:
1.6.21 looked up field groups by the selected variation ID when present;
1.6.22 looks them up by the parent `$product_id`. The 1.6.22 minified admin
JavaScript has the same 17 WAPF module bodies as 1.6.21 (same module hashes,
different concatenation order), with no other PHP adapter or vendor identifier
added. Free 1.7.1 and installed Extended 3.1.5 retain parent-product-ID lookup
in this validation path. The changelog names no Gift Card plugin, and the
source contains no dedicated Gift Card adapter. The concrete behavior is
therefore mapped to `WAPF-PRODUCT-VARIABLE`; this audit makes no claim about
arbitrary third-party Gift Card plugins. The ledger's Gift Card row stays
`partial` to retain that scope limit rather than treating the vague changelog
phrase as an independent adapter contract.

This Free source audit plus the installed Extended 3.1.5/Pro map documents the
available code baselines; it does not replace the still-open source audit of
current Extended 3.2.1 and Pro 3.2.2 packages.

## Published runtime compatibility floors

The Free 1.7.1 readme/plugin header declares WordPress 4.5+, PHP 7.0+, and
WooCommerce 6.0+. The current paid product page declares WordPress 6.0+, PHP
7.1+, and WooCommerce 7.0+. The installed Extended 3.1.5 header still says
WooCommerce 4.9+, while its current changelog says 3.1.7 raised the minimum to
7.0; the licensed 3.2.1 archive is needed to confirm its final metadata. These
declared support ranges are part of the compatibility baseline, alongside
capability parity.

## Current target version gap

This document audits the installed 3.1.5 package only. The current official
Extended changelog now lists 3.2.1, released 27 June 2026. The live site's
inactive 3.1.5 copy is a useful source baseline, but it cannot establish current
target behavior by itself. The 3.2.1 distribution source is not present in the
audited installation, so source-level review of the intervening releases is
still open. Do not use the 3.1.5 audit as the source-audit exit gate.

The official changelog delta that must be reconciled into the ledger is:

| Version | Published capability or behavior changes | Audit implications |
| --- | --- | --- |
| 3.1.6 | Cards with quantity inputs gained additional conditional-logic options; select tax handling was fixed | Inspect card quantity condition controls and saved settings, then compare selected-option tax behavior |
| 3.1.7 | Upload and order-admin deletion security hardening; output hardening; text-swatch corner-radius persistence fix; informational calculation result-format fix; modern uploader enabled by default; minimum WooCommerce version raised to 7.0 | `WAPF-FIELD-UPLOAD`: inspect upload and order-admin authorization/output paths. `WAPF-FIELD-CARDS`/style rows: inspect saved corner radius. Calculation row: check informational result format. Uploader row: reconcile default. G4: confirm WC 7.0 floor and security behavior. |
| 3.2 | True/false and checkbox switch presentation; checkbox columns; field-group title search; number step and whole/decimal validation; formula weight; styled-control accessibility; negative options-total formatting; active product-type filtering; skip validation for unsupported product types; iOS upload validation scroll fix | Reconcile setting keys/defaults and server validation for switch/columns/number rows; title-search row; formula-weight row; styled-control keyboard/screen-reader behavior; negative-total row; product-type query behavior; upload error focus on iOS. |
| Extended 3.2.1 | Image zoom for image+quantity and linked-product image fields; date-picker accessibility; auto-update fix; disabled-days save fix; option-discount tax fix; WordPress 7.0 admin CSS fixes | Inspect zoom data/settings; date control semantics, accessibility and disabled-day persistence; updater/package behavior; admin CSS; option-discount tax calculations. Map to image-quantity/child-image zoom, date, commerce tax, and release-readiness rows. |
| Pro 3.2.2 | Fixed a blank Product Fields admin page | Extended includes Pro features, but the versioned Extended package source must establish whether this Pro patch is bundled. Verify admin page load independently in the exact archive. |

Sources: [official Extended changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/),
[official Pro changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/changelog/),
and [official edition comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/).
The edition comparison confirms Extended includes Pro; the audit gate therefore
covers both Pro-core and Extended-only behavior. Pro's latest changelog is
3.2.2 while Extended's is 3.2.1, so exact package inclusion is an open
source-audit question. Six separately sold add-ons remain out of this scope.
The current official changelog pages were reread on 2026-09-30: Extended lists
3.2.1, Pro lists 3.2.2. This is a changelog-based release-delta review, not
source-level verification of either package. The Pro 3.2.2 blank-admin fix is
also a separate package-inclusion question even though Extended bundles Pro
features.

### Current package recovery check — 2026-09-30

Read-only filename search across the server found no Extended 3.2.1 archive
(excluding virtual `/proc`, `/sys`, `/dev`, `/run`, and Docker storage).
The three inspected Ploi site backups
dated 2026-04-12, 2026-08-18, and 2026-08-31 each contain the Extended plugin
header for 3.1.5. The three ZIPs in the WordPress uploads root contain no
WAPF plugin paths. A fresh
WP-CLI read at 2026-09-30 12:18 UTC reports installed version 3.1.5, inactive,
and no cached update entry. The official paid-plugin install guide directs
license holders to sign into their Studio Wombat account and download the
latest version available to their license; no unauthenticated package source
was found. A second read-only search at 2026-09-30 12:53 UTC across the
`/home/followersya-5hqi7` tree found no 3.2.1 source directory or named
archive; all three hashed ZIPs in the FollowersYa uploads root were inspected
and none contained WAPF paths. Thus the 3.2.1 source remains unavailable here.
These checks do not authorize or attempt account access, license changes, or
activation.

### Current changelog-to-ledger crosswalk

This crosswalk is limited to published changelog evidence. It maps every
listed 3.1.6–3.2.2 change to its current acceptance row or release gate; it
does not establish the 3.2.1 package's implementation, stored keys, defaults,
or bundled Pro contents.

| Release change | Existing ledger row(s) or release gate | Audit disposition |
| --- | --- | --- |
| Extended 3.1.6: card quantity fields gain conditional options | `WAPF-FIELD-CARDS-QUANTITY-CONDITIONALS`, `WAPF-FIELD-CHILD-PRODUCTS`, `WAPF-RULE-CONDITIONAL` | Interpreted as quantity-enabled child-product cards (`products-card-qty`, `products-vcard-qty`), distinct from `card`/`vcard` and `image-swatch-qty` in 3.1.5. Dedicated ledger row remains `needs audit`; 3.2.1 source must establish exact controls, stored keys, and evaluation behavior. |
| Extended 3.1.6: select tax calculation fix | `WAPF-COMMERCE-TAX` | Covered as a tax behavior; exact current-package path and regression remain unverified. |
| Extended 3.1.7: upload/order-admin deletion and output hardening | `WAPF-FIELD-UPLOAD`; G4 security | Audit authorization, file ownership, path handling, and escaped output in current source; verify independently before release. |
| Extended 3.1.7: text-swatch corner-radius persistence | `WAPF-FIELD-SWATCH-TEXT` | Covered; compare setting round-trip against the current package. |
| Extended 3.1.7: informational calculation result format | `WAPF-FIELD-CALCULATION` | Covered; preserve the distinction between informational formatting and price calculation. |
| Extended 3.1.7: modern uploader enabled by default | `WAPF-UPLOAD-AJAX-UI` | Covered; current package default and explicit native fallback remain unverified. |
| Extended 3.1.7: minimum WooCommerce 7.0 | `WAPF-COMPAT-MINIMUM-PLATFORM` | Covered as a release compatibility floor; exact current header still needs package inspection. |
| Extended 3.2: switch style | `WAPF-FIELD-TRUE-FALSE-SWITCH` | Covered; current package setting/default and rendering remain to compare. |
| Extended 3.2: checkbox columns | `WAPF-FIELD-CHECKBOX-COLUMNS` | Covered; current package setting, accepted range, responsive behavior and accessibility remain to compare. |
| Extended 3.2: field-group title search | `WAPF-GROUP-ADMIN-TITLE-SEARCH` | Covered; compare list query behavior and search controls. |
| Extended 3.2: number step and whole/decimal validation | `WAPF-FIELD-NUMBER-STEP-VALIDATION` | Covered; compare stored mode/default, browser constraints and server validation. |
| Extended 3.2: formula-based weight | `WAPF-PRICE-FORMULA-WEIGHT`, `WAPF-COMMERCE-WEIGHT` | Covered; formula grammar, references, units and cart/shipping lifecycle remain source-audit items. |
| Extended 3.2: active product-type filtering | `WAPF-RULE-TYPE` | Covered as product-type targeting; compare which registered/active types the current admin permits. |
| Extended 3.2: styled-control accessibility | `WAPF-FIELD-STYLED-CHECKBOX-RADIO` | Covered; current markup, keyboard behavior and accessibility semantics remain to compare. |
| Extended 3.2: negative options-total sign formatting | `WAPF-PRICE-OPTIONS-TOTAL-NEGATIVE-FORMAT` | Covered as display behavior; verify negative-value formatting and currency placement. |
| Extended 3.2: skip validation for unsupported product types | G3 server validation and product-type lifecycle | Performance/validation behavior, not a standalone customer capability row; verify unsupported types fail closed without imposing WAPF's skipped validation path on supported types. |
| Extended 3.2: iOS upload validation scroll | `WAPF-UPLOAD-AJAX-UI`; G3 browser proof | Covered as an upload error-focus behavior; verify on a real iOS browser before claiming parity. |
| Extended 3.2.1: zoom for image+quantity and linked-product images | `WAPF-FIELD-IMAGE-QUANTITY-ZOOM`, `WAPF-FIELD-CHILD-PRODUCTS-IMAGE-ZOOM` | Both rows exist; compare current setting keys, defaults, hover and keyboard-focus behavior. |
| Extended 3.2.1: date-picker accessibility improvement | `WAPF-DATE-ACCESSIBILITY` | Dedicated ledger row exists and remains `needs audit`; compare names, focus order, keyboard controls, and announcements against the exact package. |
| Extended 3.2.1: auto-update fix | G4 packaging/update reliability | Release reliability rather than a storefront feature row; inspect updater code and verify package update behavior without exposing license data. |
| Extended 3.2.1: disabled-days persistence fix | `WAPF-DATE-WEEKDAYS` | Covered; compare saved defaults and reload behavior in the current package. |
| Extended 3.2.1: option-discount tax fix | `WAPF-COMMERCE-TAX` | Covered; current tax/coupon interaction needs source and commerce-path proof. |
| Extended 3.2.1: WordPress 7.0 admin CSS fixes | G4 supported-platform/admin compatibility | Release compatibility behavior; verify current admin screens at the claimed WordPress floor and WordPress 7.0. |
| Pro 3.2.2: blank Product Fields admin-page fix | G1 package-inclusion check; G4 admin reliability | No separate customer capability row. Inspect the exact Extended archive to determine whether it includes the fix, then verify the Product Fields screen loads. |

This review found two release-delta rows missing from the prior edition
inventory: card quantity conditional settings and date-picker accessibility.
Both are explicit `needs audit` rows. The installed-source audit also found the
WooCommerce Bookings adapter, now represented as a separate Pro capability
row. Together with three separately verified Pro group-target conditions
(login state, user role, and current language), plus the unregistered Bookings
class requiring runtime classification and the beta PHP helper API, the edition
ledger now has 132 rows. The official descriptions do not disclose the release rows'
exact setting
keys or complete behavior, so only the licensed current package can close
those source questions.
