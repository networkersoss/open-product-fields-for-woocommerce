# WAPF developer hooks — OPF hooks that are not bridged

Generated from the regenerated crosswalk (`WAPF-HOOKS-BRIDGE-EVIDENCE.md`).
Reference: Advanced Product Fields for WooCommerce Extended **3.1.5**.

Of the 105 crosswalk entries, **40 are bridged** and **65 are not**.
The 39 entries marked *absent* have no OPF surface to alias at all; the
remaining 26 have a related OPF surface that simply has no filter point.

A missing hook is a silent no-op (`apply_filters` returns its input unchanged), so this
list is the honest boundary of OPF's `wapf/…` compatibility.

## admin-internal

| wapf_hook | surface | rationale |
|---|---|---|
| `wapf/admin/after_additional_field_settings` | absent | OPF field editing is a React builder; there are no PHP field-settings templates to hang actions on. |
| `wapf/admin/after_field_settings` | absent | React builder; no PHP field-settings template. |
| `wapf/admin/after_product_duplication` | absent | OPF has no per-product field-group duplication hook (global groups are duplicated by FieldGroups). |
| `wapf/admin/allowed_product_types` | absent | OPF placement supports product/category/tag/attribute/user rules; there is no allowed-product-types registry. |
| `wapf/admin/before_additional_field_settings` | absent | React builder; no PHP field-settings template. |
| `wapf/admin/before_field_settings` | absent | React builder; no PHP field-settings template. |
| `wapf/admin/pricing_options` | absent | OPF pricing types are a fixed schema (Engine/FieldGroup::PRICING_TYPES: none/fixed/percent/formula); no admin pricing-option filter. |
| `wapf/admin/product_tab_content_end` | absent | OPF does not render fields in the WooCommerce product-data tab; groups are global with placement rules. |
| `wapf/admin/product_tab_content_start` | absent | OPF renders no product-data tab; groups are global with placement rules. |
| `wapf/admin/sanitize_field` | absent | OPF normalizes saved fields through Engine/FieldGroup schema normalization; no admin sanitize hook. |
| `wapf/admin/settings_` | absent | Dynamic WAPF admin settings-section filter; OPF settings screens are service-owned and WordPress filters cannot wildcard a suffix. |
| `wapf/admin/settings_sections` | absent | OPF settings screens are service-owned; no settings-section registry. |
| `wapf/admin/tab_classes` | absent | OPF renders no product-data tab. |
| `wapf/admin_setting_template_path` | absent | OPF admin has no PHP view templates (React builder). |
| `wapf/htaccess_content` | absent | OPF stores uploads outside the web root and serves them through a REST route; no .htaccess protection file. |
| `wapf/licensing/timeout` | absent | OPF is GPL and has no licensing/update-check subsystem. |
| `wapf/yith_raq_disable_quantity_edits` | absent | OPF has no YITH Request-a-Quote integration. |

## cart

| wapf_hook | surface | rationale |
|---|---|---|
| `wapf/add_to_cart_url` | absent | OPF never rewrites the catalog add-to-cart URL; it leaves WooCommerce's native loop link in place (no `woocommerce_product_add_to_cart_url` filter is registered anywhere in includes/). |
| `wapf/cart/cart_item_field` | exists, no filter point | OPF stores canonical `gid => fid => value` maps (CartIntegration::ITEM_KEY) and derives display records in CartIntegration::visible_selections(); there is no stored per-field cart-item structure to filter, and the hook's `$field` argument is a WAPF `Field` object OPF cannot supply. |
| `wapf/sanitize_value` | exists, no filter point | OPF sanitizes per type in Service/CartIntegration::sanitize_value; there is no single filter point across the return branches. |

## pricing

| wapf_hook | surface | rationale |
|---|---|---|
| `wapf/field_weight` | exists, no filter point | OPF adds addon weight in Engine/CartIntegration::addon_weight; there is no per-field weight filter. |
| `wapf/fx/solve` | exists, no filter point | OPF evaluates formulas in Engine/Calculator; no per-call solve filter. |
| `wapf/replace_in_formula` | exists, no filter point | OPF performs token replacement inside Engine/Calculator; no replacement-step filter. |

## public extension registry

| wapf_hook | surface | rationale |
|---|---|---|
| `wapf/field_group/condition_options` | absent | OPF placement rules ship a fixed schema (Service/Admin/Builder.php + FieldGroups); no condition-option registry. |
| `wapf/field_options` | absent | OPF admin is a React builder backed by the FieldGroup schema; there is no PHP field-option registry. |
| `wapf/field_types` | absent | OPF field types are a closed constant (Engine/FieldGroup::FIELD_TYPES) driving validation plus a compiled React builder and the Renderer switch; no runtime type registry exists and a new type could not render. |
| `wapf/field_visibility_conditions` | absent | OPF conditionals are a fixed schema evaluated by Engine/Evaluator; no condition registry. |
| `wapf/function_definitions` | exists, no filter point | OPF registers formula functions through the public API::add_formula_function; there is no bulk function-definition filter. |
| `wapf/fx/functions` | exists, no filter point | OPF exposes registered formula functions only through Calculator internals; no listing filter. |
| `wapf/setting/{$name}` | exists, no filter point | Dynamic WAPF filter mirroring OPF's own `opf/setting/{name}`. WordPress filters cannot wildcard a dynamic suffix, so this cannot be auto-bridged. |
| `wapf/settings` | exists, no filter point | OPF reads/writes settings through API::get_setting/has_setting and `opf/setting/{name}`; the WAPF whole-array admin filter has no counterpart. |

## render

| wapf_hook | surface | rationale |
|---|---|---|
| `wapf/ajax_file_upload_config` | exists, no filter point | OPF publishes upload settings as markup/data attributes plus `window.opf_config`/`OPF_I18N`; it ships its own uploader (opf-uploads.js), not Dropzone, so there is no Dropzone-config filter. |
| `wapf/field_group/is_condition_valid` | exists, no filter point | OPF evaluates conditionals in Engine/Evaluator with a fixed schema; no per-condition result filter. |
| `wapf/field_template_model` | exists, no filter point | OPF renders server-side PHP directly in Renderer::render_field; there is no PHP view-model handoff. |
| `wapf/field_template_path` | absent | OPF renders fields server-side PHP with no view-template path registry. |
| `wapf/file/ajax_upload_success_file_result` | exists, no filter point | OPF Uploads REST returns its own token result shape; no per-file success filter. |
| `wapf/file/check_nonce` | exists, no filter point | OPF performs nonce/origin checks in Service/Uploads::same_origin; no filter toggle. |
| `wapf/file/test_file_type` | exists, no filter point | OPF validates types through `accepted_types` + wp_check_filetype; no filter toggle. |
| `wapf/file/upload_result` | absent | OPF's private-upload pipeline returns session tokens, not a wp_handle_upload result; there is no equivalent result shape. |
| `wapf/html/field_attributes` | exists, no filter point | OPF emits input attributes as preformatted strings in Renderer::render_input/render_choices; no array-attribute filter point. |
| `wapf/html/field_classes` | exists, no filter point | OPF builds input class strings inline rather than as an array; the container-class hook is bridged, the inner input-class filter is not. |
| `wapf/html/field_container_attributes` | exists, no filter point | OPF container attributes are fixed markup; no array filter point. |
| `wapf/html/file_entry` | exists, no filter point | OPF's upload file rows are built by assets/js/opf-uploads.js for new uploads and by Renderer for existing tokens; there is no Dropzone previewTemplate surface. |
| `wapf/html/option_attributes` | exists, no filter point | OPF option attributes are a preformatted string; WAPF passes/returns an array, so bridging would change the type contract. |
| `wapf/html/product_totals` | exists, no filter point | OPF Renderer::render_totals echoes its block directly; only the before/after actions are bridged. |
| `wapf/html/product_totals/data` | exists, no filter point | OPF totals data is computed inline as data-* attributes rather than a mutable array. |
| `wapf/section_classes/` | exists, no filter point | OPF builds fixed section classes; only the generic wapf/html/section_container_classes is bridged. |
| `wapf/skip_add_to_cart` | exists, no filter point | OPF controls rendering through placement rules and Renderer::visible_to_viewer(); no per-request skip filter. |
| `wapf/validate/file` | absent | OPF validates upload tokens in Service/Uploads::validate_tokens; no per-file validation filter. |
| `wapf_upload_ajax` | exists, no filter point | OPF reads the migrated `opf_upload_ajax`/`wapf_upload_ajax` option in Service/Uploads::modern; no filter. |

## string-only

| wapf_hook | surface | rationale |
|---|---|---|
| `wapf/cart/item_values_label` | exists, no filter point | OPF derives cart/order labels from field definitions in CartIntegration::visible_selections; there is no per-label filter. |
| `wapf/message/file_not_valid` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/file_upload_error` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/file_upload_error_general` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/file_upload_logged_in` | absent | OPF private uploads do not require login; no equivalent message. |
| `wapf/message/file_upload_nofield` | absent | OPF rejects orphan uploads at the REST route; no message filter. |
| `wapf/message/upload_err_cant_write` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/upload_err_ini_size` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/upload_err_partial` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/upload_err_too_big` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/upload_err_too_many` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/upload_err_type_unsupported` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/upload_err_uploads_exceeded` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/message/upload_error_code` | absent | OPF uses its own localized upload error strings; no message filters. |
| `wapf/shorten_text_limit` | absent | OPF has no shortened-text admin helper. |

