# WAPF developer hooks — regenerated 3.1.5 → OPF crosswalk

Regenerated against the installed reference plugin **Advanced Product Fields for
WooCommerce Extended 3.1.5**
(`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`)
and the OPF tree under test, by re-running a PHP tokenizer scan (`php_scan` over
every `*.php` file, matching the literal first argument of `apply_filters()`,
`do_action()` and `do_action_ref_array()` beginning `wapf/` or `wapf_`).

This replaces the earlier 101-hook crosswalk, which was stale: it predated the
`wapf/pricing/addon`, `wapf/html/pricing_hint*`, `wapf/pricing/price_with_tax` and
`wapf/pricing/cart_item_base_for_formulas` dispatches now present in
`includes/Compat/WapfHooks.php`, and its old scan missed three multi-line
`apply_filters()` calls (`wapf/admin_setting_template_path`, `wapf/field_template_path`,
`wapf/field_weight`) whose hook name is not the first token after `(`.

## Counts (derived from the scan)

- Literal WAPF hook names in the 3.1.5 package: **104**
- Plus one dynamic name recorded separately (`wapf/setting/{$name}`): **1**
- Total crosswalk entries: **105**
- Bridged for real in OPF (`apply_filters`/`do_action` whose return value OPF consumes): **40**
- Not bridged: **65** — of which **39** have no OPF surface at all and **26** have a related OPF surface but no filter point.
- Bridged share: **40/105** = 38%

Not-bridged classification counts:

- render: 19
- admin-internal: 17
- string-only: 15
- public extension registry: 8
- cart: 3
- pricing: 3

All-entry classification counts:

- render: 29
- admin-internal: 17
- string-only: 15
- pricing: 15
- cart: 14
- public extension registry: 13
- store-api: 2

> The previous artifact (`101 canonical + 1 dynamic`) understated the surface because
> its scan pattern only matched single-line calls. The three extra literal names are
> real 3.1.5 dispatches and are listed below.

## Delta vs the stale artifact

**Newly dispatched by this lane (7):** `wapf/cart_edit_text`,
`wapf/disable_cart_edit_when_invisible`, `wapf/add_to_cart_redirect_when_editing`,
`wapf/store_api/cart/data_callback`, `wapf/store_api/cart/schema_callback`,
`wapf/pricing/display_options`, `wapf/features/change_price_html`.

**Already dispatched in the tree but listed as "mappable, not auto-bridged" by the
stale artifact (6):** `wapf/pricing/addon`, `wapf/html/pricing_hint`,
`wapf/html/pricing_hint/amount`, `wapf/html/pricing_hint/format`,
`wapf/pricing/cart_item_base_for_formulas`, `wapf/pricing/price_with_tax`.

**Wrongly categorised by the stale artifact, now aliased (3):**
`wapf/disable_cart_edit_when_invisible` and `wapf/add_to_cart_redirect_when_editing`
were listed *absent* although OPF already exposes exact `opf_disable_cart_edit_when_invisible`
and `opf_add_to_cart_redirect_when_editing` booleans; `wapf/cart_edit_text` was listed
*mappable* although OPF already exposes `opf_cart_edit_text`.

## Bridged hooks (return value consumed by OPF)

Every row below is a genuine filter point: a `wapf/…` listener that returns a changed
value changes OPF's behaviour. Direct shims re-dispatch inside OPF's own `opf_…`
filter; the OPF call site that consumes the value is named in the *producer* column.

| # | WAPF hook | 3.1.5 kind | OPF dispatch | OPF producer (consumer) |
|---|---|---|---|---|
| 1 | `wapf/add_to_cart_redirect_when_editing` | apply_filters | `Compat/WapfHooks.php:206` | opf_add_to_cart_redirect_when_editing @ Service/CartEdit.php:326 |
| 2 | `wapf/cart/item_data` | apply_filters | `Compat/WapfHooks.php:371` | WapfHooks::cart_item_data @ Service/CartIntegration.php:753 |
| 3 | `wapf/cart_edit_text` | apply_filters | `Compat/WapfHooks.php:188` | opf_cart_edit_text @ Service/CartEdit.php:185 |
| 4 | `wapf/disable_cart_edit_when_invisible` | apply_filters | `Compat/WapfHooks.php:197` | opf_disable_cart_edit_when_invisible @ Service/CartEdit.php:169 |
| 5 | `wapf/features/change_price_html` | apply_filters | `Compat/WapfHooks.php:306` | woocommerce_get_price_html @ Service/ProductPriceDisplay.php:32 (re-registered behind the gate; see WapfHooks.php:70-79) |
| 6 | `wapf/features/linked_products` | apply_filters | `Compat/WapfHooks.php:88` | opf_features_linked_products @ Service/LinkedProducts.php:45 |
| 7 | `wapf/html/field_container_classes` | apply_filters | `Compat/WapfHooks.php:607` | WapfHooks::field_container_classes @ Service/Renderer.php:709 |
| 8 | `wapf/html/field_description` | apply_filters | `Compat/WapfHooks.php:634` | WapfHooks::field_description @ Service/Renderer.php:810 |
| 9 | `wapf/html/field_label` | apply_filters | `Compat/WapfHooks.php:621` | WapfHooks::field_label @ Service/Renderer.php:789 |
| 10 | `wapf/html/image_swatch_size` | apply_filters | `Compat/WapfHooks.php:664` | WapfHooks::image_swatch_size @ Service/Renderer.php:1204 |
| 11 | `wapf/html/option_wrapper_classes` | apply_filters | `Compat/WapfHooks.php:649` | WapfHooks::option_wrapper_classes @ Service/Renderer.php:1173 |
| 12 | `wapf/html/pricing_hint` | apply_filters | `Compat/WapfHooks.php:161` | opf_pricing_hint @ Service/PricingHints.php:211 |
| 13 | `wapf/html/pricing_hint/amount` | apply_filters | `Compat/WapfHooks.php:153` | opf_pricing_hint_amount @ Service/PricingHints.php:80, Service/Renderer.php:172 |
| 14 | `wapf/html/pricing_hint/format` | apply_filters | `Compat/WapfHooks.php:143` | opf_pricing_hint_format @ Service/PricingHints.php:77 |
| 15 | `wapf/html/section_container_classes` | apply_filters | `Compat/WapfHooks.php:677` | WapfHooks::section_container_classes @ Service/Renderer.php:612 |
| 16 | `wapf/linked_products/cart_choice` | apply_filters | `Compat/WapfHooks.php:709` | WapfHooks::linked_products_cart_choice @ Service/LinkedProducts.php:668 |
| 17 | `wapf/linked_products/choice` | apply_filters | `Compat/WapfHooks.php:97` | opf/linked_products/choice @ Service/LinkedProducts.php:291 |
| 18 | `wapf/lookup_tables` | apply_filters | `Compat/WapfHooks.php:122` | opf_lookup_tables @ Engine/Calculator.php:932, Engine/FieldGroup.php:1484, Service/Assets.php:82; dispatched directly at Engine/Calculator.php:935, Service/Assets.php:84 |
| 19 | `wapf/order/order_item_field` | apply_filters | `Compat/WapfHooks.php:570` | WapfHooks::order_item_field @ Service/CartIntegration.php:1549 |
| 20 | `wapf/order_again/before_cart_item_field` | do_action | `Compat/WapfHooks.php:507 (helper, after removing WAPF's native Field-object handler)` | WapfHooks::order_again_field @ Service/CartIntegration.php:1623 |
| 21 | `wapf/order_item/meta_display_value` | apply_filters | `Compat/WapfHooks.php:580` | WapfHooks::meta_display_value @ Service/CartIntegration.php:1559 |
| 22 | `wapf/pricing/addon` | apply_filters | `Compat/WapfHooks.php:169` | opf_pricing_addon @ Service/PricingHints.php:165 |
| 23 | `wapf/pricing/base` | apply_filters | `Compat/WapfHooks.php:338` | WapfHooks::cart_base_price @ Service/CartIntegration.php:427 |
| 24 | `wapf/pricing/cart_item_base` | apply_filters | `Compat/WapfHooks.php:339` | WapfHooks::cart_base_price @ Service/CartIntegration.php:427 |
| 25 | `wapf/pricing/cart_item_base_for_formulas` | apply_filters | `Compat/WapfHooks.php:321` | opf_formula_base_price @ Engine/Calculator.php:639 |
| 26 | `wapf/pricing/cart_item_options` | apply_filters | `Compat/WapfHooks.php:351` | WapfHooks::cart_item_options @ Service/CartIntegration.php:430 |
| 27 | `wapf/pricing/display_options` | apply_filters | `Compat/WapfHooks.php:268` | opf_frontend_config @ Service/Assets.php:168 |
| 28 | `wapf/pricing/price_with_tax` | apply_filters | `Compat/WapfHooks.php:177` | opf_pricing_price_with_tax @ Service/PricingHints.php:114,135, Service/Renderer.php:193,198 |
| 29 | `wapf/pricing/product` | apply_filters | `Compat/WapfHooks.php:361` | WapfHooks::pricing_product @ Service/Renderer.php:272 |
| 30 | `wapf/pricing_summary` | apply_filters | `Compat/WapfHooks.php:133` | opf_show_totals @ Service/Renderer.php:76 |
| 31 | `wapf/product_field_groups` | apply_filters | `Compat/WapfHooks.php:114` | opf_groups_for_product @ Service/FieldGroups.php:293 |
| 32 | `wapf/products/query` | apply_filters | `Compat/WapfHooks.php:106` | opf/linked_products/query_args @ Service/LinkedProducts.php:187 |
| 33 | `wapf/skip_cart_validation` | apply_filters | `Compat/WapfHooks.php:127` | opf_skip_validation @ Service/CartIntegration.php:136 |
| 34 | `wapf/skip_fieldgroup_validation` | apply_filters | `Compat/WapfHooks.php:128` | opf_skip_validation @ Service/CartIntegration.php:136 |
| 35 | `wapf/store_api/cart/data_callback` | apply_filters | `Compat/WapfHooks.php:216` | opf_store_api_cart_data @ Service/CartEdit.php:222 |
| 36 | `wapf/store_api/cart/schema_callback` | apply_filters | `Compat/WapfHooks.php:225` | opf_store_api_cart_schema @ Service/CartEdit.php:225 |
| 37 | `wapf/validate` | apply_filters | `Compat/WapfHooks.php:451 (helper, after removing WAPF's native Field-object validators)` | WapfHooks::validate_field @ Service/CartIntegration.php:1925 |
| 38 | `wapf_after_product_totals` | do_action | `Compat/WapfHooks.php:683` | WapfHooks::after_product_totals @ Service/Renderer.php:1820 |
| 39 | `wapf_before_product_totals` | do_action | `Compat/WapfHooks.php:697` | WapfHooks::before_product_totals @ Service/Renderer.php:1795 |
| 40 | `wapf_before_wrapper` | do_action | `Compat/WapfHooks.php:690` | WapfHooks::before_wrapper @ Service/Renderer.php:276 |

## Full crosswalk

| # | WAPF hook | 3.1.5 kind | 3.1.5 site | bridged | dispatch | producer | classification | not bridged |
|---|---|---|---|---|---|---|---|---|
| 1 | `wapf/add_to_cart_redirect_when_editing` | apply_filters | `includes/controllers/class-product-controller.php:148` | yes | `Compat/WapfHooks.php:206` | opf_add_to_cart_redirect_when_editing @ Service/CartEdit.php:326 | cart | — |
| 2 | `wapf/add_to_cart_url` | apply_filters | `includes/controllers/class-product-controller.php:305` | no | — | — | cart | **absent** — OPF never rewrites the catalog add-to-cart URL; it leaves WooCommerce's native loop link in place (no `woocommerce_product_add_to_cart_url` filter is registered anywhere in includes/). |
| 3 | `wapf/admin/after_additional_field_settings` | do_action | `views/admin/field.php:180` | no | — | — | admin-internal | **absent** — OPF field editing is a React builder; there are no PHP field-settings templates to hang actions on. |
| 4 | `wapf/admin/after_field_settings` | do_action | `views/admin/field.php:181` | no | — | — | admin-internal | **absent** — React builder; no PHP field-settings template. |
| 5 | `wapf/admin/after_product_duplication` | do_action | `includes/controllers/class-admin-controller.php:488` | no | — | — | admin-internal | **absent** — OPF has no per-product field-group duplication hook (global groups are duplicated by FieldGroups). |
| 6 | `wapf/admin/allowed_product_types` | apply_filters | `includes/controllers/class-admin-controller.php:548` | no | — | — | admin-internal | **absent** — OPF placement supports product/category/tag/attribute/user rules; there is no allowed-product-types registry. |
| 7 | `wapf/admin/before_additional_field_settings` | do_action | `views/admin/field.php:152` | no | — | — | admin-internal | **absent** — React builder; no PHP field-settings template. |
| 8 | `wapf/admin/before_field_settings` | do_action | `views/admin/field.php:65` | no | — | — | admin-internal | **absent** — React builder; no PHP field-settings template. |
| 9 | `wapf/admin/pricing_options` | apply_filters | `includes/classes/class-config.php:467` | no | — | — | admin-internal | **absent** — OPF pricing types are a fixed schema (Engine/FieldGroup::PRICING_TYPES: none/fixed/percent/formula); no admin pricing-option filter. |
| 10 | `wapf/admin/product_tab_content_end` | do_action | `includes/controllers/class-admin-controller.php:536` | no | — | — | admin-internal | **absent** — OPF does not render fields in the WooCommerce product-data tab; groups are global with placement rules. |
| 11 | `wapf/admin/product_tab_content_start` | do_action | `includes/controllers/class-admin-controller.php:510` | no | — | — | admin-internal | **absent** — OPF renders no product-data tab; groups are global with placement rules. |
| 12 | `wapf/admin/sanitize_field` | do_action_ref_array | `includes/classes/class-field-groups.php:342` | no | — | — | admin-internal | **absent** — OPF normalizes saved fields through Engine/FieldGroup schema normalization; no admin sanitize hook. |
| 13 | `wapf/admin/settings_` | apply_filters | `includes/controllers/class-admin-controller.php:912` | no | — | — | admin-internal | **absent** — Dynamic WAPF admin settings-section filter; OPF settings screens are service-owned and WordPress filters cannot wildcard a suffix. |
| 14 | `wapf/admin/settings_sections` | apply_filters | `includes/controllers/class-admin-controller.php:593` | no | — | — | admin-internal | **absent** — OPF settings screens are service-owned; no settings-section registry. |
| 15 | `wapf/admin/tab_classes` | apply_filters | `includes/controllers/class-admin-controller.php:497` | no | — | — | admin-internal | **absent** — OPF renders no product-data tab. |
| 16 | `wapf/admin_setting_template_path` | apply_filters | `includes/classes/class-html.php:150` | no | — | — | admin-internal | **absent** — OPF admin has no PHP view templates (React builder). |
| 17 | `wapf/ajax_file_upload_config` | apply_filters | `views/frontend/fields/file.php:28` | no | — | — | render | **exists, no filter point** — OPF publishes upload settings as markup/data attributes plus `window.opf_config`/`OPF_I18N`; it ships its own uploader (opf-uploads.js), not Dropzone, so there is no Dropzone-config filter. |
| 18 | `wapf/cart/cart_item_field` | apply_filters | `includes/classes/class-cart.php:235` | no | — | — | cart | **exists, no filter point** — OPF stores canonical `gid => fid => value` maps (CartIntegration::ITEM_KEY) and derives display records in CartIntegration::visible_selections(); there is no stored per-field cart-item structure to filter, and the hook's `$field` argument is a WAPF `Field` object OPF cannot supply. |
| 19 | `wapf/cart/item_data` | apply_filters | `includes/controllers/class-product-controller.php:712` | yes | `Compat/WapfHooks.php:371` | WapfHooks::cart_item_data @ Service/CartIntegration.php:753 | cart | — |
| 20 | `wapf/cart/item_values_label` | apply_filters | `includes/classes/class-helper.php:869, includes/classes/class-helper.php:900` | no | — | — | string-only | **exists, no filter point** — OPF derives cart/order labels from field definitions in CartIntegration::visible_selections; there is no per-label filter. |
| 21 | `wapf/cart_edit_text` | apply_filters | `includes/controllers/class-product-controller.php:245` | yes | `Compat/WapfHooks.php:188` | opf_cart_edit_text @ Service/CartEdit.php:185 | cart | — |
| 22 | `wapf/disable_cart_edit_when_invisible` | apply_filters | `includes/controllers/class-product-controller.php:236` | yes | `Compat/WapfHooks.php:197` | opf_disable_cart_edit_when_invisible @ Service/CartEdit.php:169 | cart | — |
| 23 | `wapf/features/change_price_html` | apply_filters | `includes/controllers/class-admin-controller.php:198, includes/controllers/class-product-controller.php:85` | yes | `Compat/WapfHooks.php:306` | woocommerce_get_price_html @ Service/ProductPriceDisplay.php:32 (re-registered behind the gate; see WapfHooks.php:70-79) | render | — |
| 24 | `wapf/features/linked_products` | apply_filters | `includes/controllers/class-linked-products-controller.php:25` | yes | `Compat/WapfHooks.php:88` | opf_features_linked_products @ Service/LinkedProducts.php:45 | public extension registry | — |
| 25 | `wapf/field_group/condition_options` | apply_filters | `includes/classes/class-config.php:442` | no | — | — | public extension registry | **absent** — OPF placement rules ship a fixed schema (Service/Admin/Builder.php + FieldGroups); no condition-option registry. |
| 26 | `wapf/field_group/is_condition_valid` | apply_filters | `includes/classes/class-conditions.php:178` | no | — | — | render | **exists, no filter point** — OPF evaluates conditionals in Engine/Evaluator with a fixed schema; no per-condition result filter. |
| 27 | `wapf/field_options` | apply_filters | `includes/classes/class-config.php:1529` | no | — | — | public extension registry | **absent** — OPF admin is a React builder backed by the FieldGroup schema; there is no PHP field-option registry. |
| 28 | `wapf/field_template_model` | apply_filters | `includes/classes/class-html.php:357` | no | — | — | render | **exists, no filter point** — OPF renders server-side PHP directly in Renderer::render_field; there is no PHP view-model handoff. |
| 29 | `wapf/field_template_path` | apply_filters | `includes/classes/class-html.php:371` | no | — | — | render | **absent** — OPF renders fields server-side PHP with no view-template path registry. |
| 30 | `wapf/field_types` | apply_filters | `includes/classes/class-config.php:1892` | no | — | — | public extension registry | **absent** — OPF field types are a closed constant (Engine/FieldGroup::FIELD_TYPES) driving validation plus a compiled React builder and the Renderer switch; no runtime type registry exists and a new type could not render. |
| 31 | `wapf/field_visibility_conditions` | apply_filters | `includes/classes/class-config.php:9` | no | — | — | public extension registry | **absent** — OPF conditionals are a fixed schema evaluated by Engine/Evaluator; no condition registry. |
| 32 | `wapf/field_weight` | apply_filters | `includes/controllers/class-extended-controller.php:347` | no | — | — | pricing | **exists, no filter point** — OPF adds addon weight in Engine/CartIntegration::addon_weight; there is no per-field weight filter. |
| 33 | `wapf/file/ajax_upload_success_file_result` | apply_filters | `includes/controllers/class-public-controller.php:166` | no | — | — | render | **exists, no filter point** — OPF Uploads REST returns its own token result shape; no per-file success filter. |
| 34 | `wapf/file/check_nonce` | apply_filters | `includes/controllers/class-public-controller.php:122, includes/controllers/class-public-controller.php:137` | no | — | — | render | **exists, no filter point** — OPF performs nonce/origin checks in Service/Uploads::same_origin; no filter toggle. |
| 35 | `wapf/file/test_file_type` | apply_filters | `includes/classes/class-file-upload.php:137` | no | — | — | render | **exists, no filter point** — OPF validates types through `accepted_types` + wp_check_filetype; no filter toggle. |
| 36 | `wapf/file/upload_result` | apply_filters | `includes/classes/class-file-upload.php:110` | no | — | — | render | **absent** — OPF's private-upload pipeline returns session tokens, not a wp_handle_upload result; there is no equivalent result shape. |
| 37 | `wapf/function_definitions` | apply_filters | `includes/classes/class-config.php:1659` | no | — | — | public extension registry | **exists, no filter point** — OPF registers formula functions through the public API::add_formula_function; there is no bulk function-definition filter. |
| 38 | `wapf/fx/functions` | apply_filters | `includes/classes/class-helper.php:578` | no | — | — | public extension registry | **exists, no filter point** — OPF exposes registered formula functions only through Calculator internals; no listing filter. |
| 39 | `wapf/fx/solve` | apply_filters | `includes/classes/class-helper.php:733` | no | — | — | pricing | **exists, no filter point** — OPF evaluates formulas in Engine/Calculator; no per-call solve filter. |
| 40 | `wapf/htaccess_content` | apply_filters | `includes/classes/class-file-upload.php:41` | no | — | — | admin-internal | **absent** — OPF stores uploads outside the web root and serves them through a REST route; no .htaccess protection file. |
| 41 | `wapf/html/field_attributes` | apply_filters | `includes/classes/class-html.php:760` | no | — | — | render | **exists, no filter point** — OPF emits input attributes as preformatted strings in Renderer::render_input/render_choices; no array-attribute filter point. |
| 42 | `wapf/html/field_classes` | apply_filters | `includes/classes/class-html.php:693` | no | — | — | render | **exists, no filter point** — OPF builds input class strings inline rather than as an array; the container-class hook is bridged, the inner input-class filter is not. |
| 43 | `wapf/html/field_container_attributes` | apply_filters | `includes/classes/class-html.php:519` | no | — | — | render | **exists, no filter point** — OPF container attributes are fixed markup; no array filter point. |
| 44 | `wapf/html/field_container_classes` | apply_filters | `includes/classes/class-html.php:458` | yes | `Compat/WapfHooks.php:607` | WapfHooks::field_container_classes @ Service/Renderer.php:709 | render | — |
| 45 | `wapf/html/field_description` | apply_filters | `includes/classes/class-html.php:410, includes/classes/class-html.php:414; …` | yes | `Compat/WapfHooks.php:634` | WapfHooks::field_description @ Service/Renderer.php:810 | render | — |
| 46 | `wapf/html/field_label` | apply_filters | `includes/classes/class-html.php:540` | yes | `Compat/WapfHooks.php:621` | WapfHooks::field_label @ Service/Renderer.php:789 | render | — |
| 47 | `wapf/html/file_entry` | apply_filters | `views/frontend/fields/file.php:19` | no | — | — | render | **exists, no filter point** — OPF's upload file rows are built by assets/js/opf-uploads.js for new uploads and by Renderer for existing tokens; there is no Dropzone previewTemplate surface. |
| 48 | `wapf/html/image_swatch_size` | apply_filters | `includes/classes/class-html.php:847` | yes | `Compat/WapfHooks.php:664` | WapfHooks::image_swatch_size @ Service/Renderer.php:1204 | render | — |
| 49 | `wapf/html/option_attributes` | apply_filters | `includes/classes/class-html.php:620, includes/classes/class-html.php:676` | no | — | — | render | **exists, no filter point** — OPF option attributes are a preformatted string; WAPF passes/returns an array, so bridging would change the type contract. |
| 50 | `wapf/html/option_wrapper_classes` | apply_filters | `includes/classes/class-html.php:621` | yes | `Compat/WapfHooks.php:649` | WapfHooks::option_wrapper_classes @ Service/Renderer.php:1173 | render | — |
| 51 | `wapf/html/pricing_hint` | apply_filters | `includes/classes/class-helper.php:498, includes/classes/class-helper.php:503; …` | yes | `Compat/WapfHooks.php:161` | opf_pricing_hint @ Service/PricingHints.php:211 | pricing | — |
| 52 | `wapf/html/pricing_hint/amount` | apply_filters | `includes/classes/class-helper.php:494, includes/controllers/class-extended-controller.php:280` | yes | `Compat/WapfHooks.php:153` | opf_pricing_hint_amount @ Service/PricingHints.php:80, Service/Renderer.php:172 | pricing | — |
| 53 | `wapf/html/pricing_hint/format` | apply_filters | `includes/classes/class-helper.php:493` | yes | `Compat/WapfHooks.php:143` | opf_pricing_hint_format @ Service/PricingHints.php:77 | pricing | — |
| 54 | `wapf/html/product_totals` | apply_filters | `includes/classes/class-html.php:222` | no | — | — | render | **exists, no filter point** — OPF Renderer::render_totals echoes its block directly; only the before/after actions are bridged. |
| 55 | `wapf/html/product_totals/data` | apply_filters | `includes/classes/class-html.php:196` | no | — | — | render | **exists, no filter point** — OPF totals data is computed inline as data-* attributes rather than a mutable array. |
| 56 | `wapf/html/section_container_classes` | apply_filters | `includes/classes/class-html.php:430` | yes | `Compat/WapfHooks.php:677` | WapfHooks::section_container_classes @ Service/Renderer.php:612 | render | — |
| 57 | `wapf/licensing/timeout` | apply_filters | `includes/classes/class-licensing.php:210` | no | — | — | admin-internal | **absent** — OPF is GPL and has no licensing/update-check subsystem. |
| 58 | `wapf/linked_products/cart_choice` | apply_filters | `includes/controllers/class-linked-products-controller.php:393, includes/controllers/class-linked-products-controller.php:440` | yes | `Compat/WapfHooks.php:709` | WapfHooks::linked_products_cart_choice @ Service/LinkedProducts.php:668 | cart | — |
| 59 | `wapf/linked_products/choice` | apply_filters | `includes/controllers/class-linked-products-controller.php:1576` | yes | `Compat/WapfHooks.php:97` | opf/linked_products/choice @ Service/LinkedProducts.php:291 | public extension registry | — |
| 60 | `wapf/lookup_tables` | apply_filters | `includes/controllers/class-product-controller.php:991, includes/controllers/class-public-controller.php:44` | yes | `Compat/WapfHooks.php:122` | opf_lookup_tables @ Engine/Calculator.php:932, Engine/FieldGroup.php:1484, Service/Assets.php:82; dispatched directly at Engine/Calculator.php:935, Service/Assets.php:84 | public extension registry | — |
| 61 | `wapf/message/file_not_valid` | apply_filters | `includes/classes/class-file-upload.php:138` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 62 | `wapf/message/file_upload_error` | apply_filters | `includes/classes/class-file-upload.php:117, includes/classes/class-file-upload.php:302; …` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 63 | `wapf/message/file_upload_error_general` | apply_filters | `includes/classes/class-file-upload.php:257` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 64 | `wapf/message/file_upload_logged_in` | apply_filters | `includes/classes/class-file-upload.php:134, includes/classes/class-file-upload.php:277` | no | — | — | string-only | **absent** — OPF private uploads do not require login; no equivalent message. |
| 65 | `wapf/message/file_upload_nofield` | apply_filters | `includes/classes/class-file-upload.php:236` | no | — | — | string-only | **absent** — OPF rejects orphan uploads at the REST route; no message filter. |
| 66 | `wapf/message/upload_err_cant_write` | apply_filters | `includes/classes/class-file-upload.php:298` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 67 | `wapf/message/upload_err_ini_size` | apply_filters | `includes/classes/class-file-upload.php:297` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 68 | `wapf/message/upload_err_partial` | apply_filters | `includes/classes/class-file-upload.php:299` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 69 | `wapf/message/upload_err_too_big` | apply_filters | `includes/classes/class-file-upload.php:306` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 70 | `wapf/message/upload_err_too_many` | apply_filters | `includes/classes/class-file-upload.php:283` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 71 | `wapf/message/upload_err_type_unsupported` | apply_filters | `includes/classes/class-file-upload.php:311` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 72 | `wapf/message/upload_err_uploads_exceeded` | apply_filters | `includes/classes/class-file-upload.php:326` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 73 | `wapf/message/upload_error_code` | apply_filters | `includes/classes/class-file-upload.php:300` | no | — | — | string-only | **absent** — OPF uses its own localized upload error strings; no message filters. |
| 74 | `wapf/order/order_item_field` | apply_filters | `includes/controllers/class-product-controller.php:819` | yes | `Compat/WapfHooks.php:570` | WapfHooks::order_item_field @ Service/CartIntegration.php:1549 | cart | — |
| 75 | `wapf/order_again/before_cart_item_field` | do_action | `includes/controllers/class-product-controller.php:947` | yes | `Compat/WapfHooks.php:507 (helper, after removing WAPF's native Field-object handler)` | WapfHooks::order_again_field @ Service/CartIntegration.php:1623 | cart | — |
| 76 | `wapf/order_item/meta_display_value` | apply_filters | `includes/controllers/class-product-controller.php:892` | yes | `Compat/WapfHooks.php:580` | WapfHooks::meta_display_value @ Service/CartIntegration.php:1559 | cart | — |
| 77 | `wapf/pricing/addon` | apply_filters | `includes/classes/class-helper.php:451` | yes | `Compat/WapfHooks.php:169` | opf_pricing_addon @ Service/PricingHints.php:165 | pricing | — |
| 78 | `wapf/pricing/base` | apply_filters | `includes/classes/class-cart.php:14` | yes | `Compat/WapfHooks.php:338` | WapfHooks::cart_base_price @ Service/CartIntegration.php:427 | pricing | — |
| 79 | `wapf/pricing/cart_item_base` | apply_filters | `includes/classes/class-cart.php:15` | yes | `Compat/WapfHooks.php:339` | WapfHooks::cart_base_price @ Service/CartIntegration.php:427 | pricing | — |
| 80 | `wapf/pricing/cart_item_base_for_formulas` | apply_filters | `includes/classes/class-cart.php:37` | yes | `Compat/WapfHooks.php:321` | opf_formula_base_price @ Engine/Calculator.php:639 | pricing | — |
| 81 | `wapf/pricing/cart_item_options` | apply_filters | `includes/classes/class-cart.php:75` | yes | `Compat/WapfHooks.php:351` | WapfHooks::cart_item_options @ Service/CartIntegration.php:430 | pricing | — |
| 82 | `wapf/pricing/display_options` | apply_filters | `includes/classes/class-woocommerce-service.php:277` | yes | `Compat/WapfHooks.php:268` | opf_frontend_config @ Service/Assets.php:168 | pricing | — |
| 83 | `wapf/pricing/price_with_tax` | apply_filters | `includes/classes/class-helper.php:405, includes/classes/class-helper.php:423` | yes | `Compat/WapfHooks.php:177` | opf_pricing_price_with_tax @ Service/PricingHints.php:114,135, Service/Renderer.php:193,198 | pricing | — |
| 84 | `wapf/pricing/product` | apply_filters | `includes/classes/class-html.php:199, includes/controllers/class-product-controller.php:78` | yes | `Compat/WapfHooks.php:361` | WapfHooks::pricing_product @ Service/Renderer.php:272 | pricing | — |
| 85 | `wapf/pricing_summary` | apply_filters | `includes/classes/class-html.php:211` | yes | `Compat/WapfHooks.php:133` | opf_show_totals @ Service/Renderer.php:76 | pricing | — |
| 86 | `wapf/product_field_groups` | apply_filters | `includes/classes/class-field-groups.php:696, includes/classes/class-field-groups.php:732` | yes | `Compat/WapfHooks.php:114` | opf_groups_for_product @ Service/FieldGroups.php:293 | public extension registry | — |
| 87 | `wapf/products/query` | apply_filters | `includes/classes/class-woocommerce-service.php:351` | yes | `Compat/WapfHooks.php:106` | opf/linked_products/query_args @ Service/LinkedProducts.php:187 | public extension registry | — |
| 88 | `wapf/replace_in_formula` | apply_filters | `includes/classes/class-helper.php:678` | no | — | — | pricing | **exists, no filter point** — OPF performs token replacement inside Engine/Calculator; no replacement-step filter. |
| 89 | `wapf/sanitize_value` | apply_filters | `includes/classes/class-fields.php:51` | no | — | — | cart | **exists, no filter point** — OPF sanitizes per type in Service/CartIntegration::sanitize_value; there is no single filter point across the return branches. |
| 90 | `wapf/section_classes/` | apply_filters | `includes/classes/class-html.php:429` | no | — | — | render | **exists, no filter point** — OPF builds fixed section classes; only the generic wapf/html/section_container_classes is bridged. |
| 91 | `wapf/setting/{$name}` | apply_filters | `includes/classes/api/api-helpers.php (dynamic)` | no | — | — | public extension registry | **exists, no filter point** — Dynamic WAPF filter mirroring OPF's own `opf/setting/{name}`. WordPress filters cannot wildcard a dynamic suffix, so this cannot be auto-bridged. |
| 92 | `wapf/settings` | apply_filters | `includes/controllers/class-admin-controller.php:846` | no | — | — | public extension registry | **exists, no filter point** — OPF reads/writes settings through API::get_setting/has_setting and `opf/setting/{name}`; the WAPF whole-array admin filter has no counterpart. |
| 93 | `wapf/shorten_text_limit` | apply_filters | `includes/classes/class-helper.php:123` | no | — | — | string-only | **absent** — OPF has no shortened-text admin helper. |
| 94 | `wapf/skip_add_to_cart` | apply_filters | `includes/controllers/class-product-controller.php:418` | no | — | — | render | **exists, no filter point** — OPF controls rendering through placement rules and Renderer::visible_to_viewer(); no per-request skip filter. |
| 95 | `wapf/skip_cart_validation` | apply_filters | `includes/controllers/class-product-controller.php:314, includes/controllers/class-product-controller.php:343` | yes | `Compat/WapfHooks.php:127` | opf_skip_validation @ Service/CartIntegration.php:136 | cart | — |
| 96 | `wapf/skip_fieldgroup_validation` | apply_filters | `includes/controllers/class-product-controller.php:368` | yes | `Compat/WapfHooks.php:128` | opf_skip_validation @ Service/CartIntegration.php:136 | cart | — |
| 97 | `wapf/store_api/cart/data_callback` | apply_filters | `includes/controllers/class-product-controller.php:195` | yes | `Compat/WapfHooks.php:216` | opf_store_api_cart_data @ Service/CartEdit.php:222 | store-api | — |
| 98 | `wapf/store_api/cart/schema_callback` | apply_filters | `includes/controllers/class-product-controller.php:209` | yes | `Compat/WapfHooks.php:225` | opf_store_api_cart_schema @ Service/CartEdit.php:225 | store-api | — |
| 99 | `wapf/validate` | apply_filters | `includes/classes/class-cart.php:180` | yes | `Compat/WapfHooks.php:451 (helper, after removing WAPF's native Field-object validators)` | WapfHooks::validate_field @ Service/CartIntegration.php:1925 | cart | — |
| 100 | `wapf/validate/file` | apply_filters | `includes/classes/class-file-upload.php:315` | no | — | — | render | **absent** — OPF validates upload tokens in Service/Uploads::validate_tokens; no per-file validation filter. |
| 101 | `wapf/yith_raq_disable_quantity_edits` | apply_filters | `includes/classes/integrations/class-yith-raq.php:102` | no | — | — | admin-internal | **absent** — OPF has no YITH Request-a-Quote integration. |
| 102 | `wapf_after_product_totals` | do_action | `includes/classes/class-html.php:262` | yes | `Compat/WapfHooks.php:683` | WapfHooks::after_product_totals @ Service/Renderer.php:1820 | render | — |
| 103 | `wapf_before_product_totals` | do_action | `includes/classes/class-html.php:256` | yes | `Compat/WapfHooks.php:697` | WapfHooks::before_product_totals @ Service/Renderer.php:1795 | render | — |
| 104 | `wapf_before_wrapper` | do_action | `includes/classes/class-html.php:235` | yes | `Compat/WapfHooks.php:690` | WapfHooks::before_wrapper @ Service/Renderer.php:276 | render | — |
| 105 | `wapf_upload_ajax` | apply_filters | `includes/classes/class-file-upload.php:335` | no | — | — | render | **exists, no filter point** — OPF reads the migrated `opf_upload_ajax`/`wapf_upload_ajax` option in Service/Uploads::modern; no filter. |
