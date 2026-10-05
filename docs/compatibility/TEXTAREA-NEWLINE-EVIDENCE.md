# Native textarea newline lifecycle evidence

Executed 2026-10-01 against baseline `4d9c6e20458933df09a1aef33964c3125aae8cfa` in isolated checkout `/tmp/opf-textarea-parity` (`parity/textarea-20261001`). The disposable SQLite site was `/tmp/opf-textarea-woo` at `http://127.0.0.1:8127`; it used WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, Twenty Twenty-Five, and real Chromium/Playwright. Only OPF, WooCommerce, and SQLite integration were active. WAPF was not active in the behavior run. Outgoing email was intercepted; generated HTML/plain bodies were inspected without delivery. No production site or protected tab was used.

## WAPF Free 1.7.1 source behavior

The installed Free 1.7.1 source registers `default` and `placeholder` controls for `textarea`. Its frontend view prints the field's `field_value` inside the `<textarea>`; `class-html.php` converts a nonempty default using `esc_attr()`, so markup is encoded before the browser parses the value. Both `sanitize_raw_value()` and `sanitize_value()` use `sanitize_textarea_field(trim($value))` for textarea values. The cart controller sets `value_cart` with that sanitizer and passes it unmodified as the value for Woo's `woocommerce_get_item_data` filter. OPF follows the same textarea sanitation contract while rejecting non-scalar payloads before sanitation. Exact installed-source locations: sanitation (`/tmp/wapf-free-1.7.1/advanced-product-fields-for-woocommerce/includes/classes/class-fields.php:461`), escaped default (`/tmp/wapf-free-1.7.1/advanced-product-fields-for-woocommerce/includes/classes/class-html.php:298`), frontend textarea (`/tmp/wapf-free-1.7.1/advanced-product-fields-for-woocommerce/views/frontend/fields/textarea.php:4`), cart item-data mapping (`/tmp/wapf-free-1.7.1/advanced-product-fields-for-woocommerce/includes/controllers/class-product-controller.php:394`), and `value_cart` sanitation (`/tmp/wapf-free-1.7.1/advanced-product-fields-for-woocommerce/includes/controllers/class-product-controller.php:429`).

Inspected source hashes:

```text
includes/classes/class-fields.php                    92e2096f35e01e1c7036a780b4363a6285279b0d76e4cfcacca4224b7e05128b
includes/classes/class-html.php                      88a0950f56bb73fa28ba110895e054812a2fab322c570da5d57dedbcec18aa38
includes/controllers/class-product-controller.php   e5f6d95de4374543c98f4fc02818cc6311168f43ab0078b122a4da44b0caea15
views/frontend/fields/textarea.php                   ed9172a4421d8ce202d682f9ac67ea6a9c33439bab22731d17723aa81b741193
```

## Executed proof

The authenticated browser used the actual WordPress field-group admin page and REST save/reload. All 12 checks passed with no uncaught browser errors: textarea type and edited label persisted; storefront required/optional `<textarea>` controls rendered; browser constraint validation blocked an empty required value; a forged empty classic POST was rejected with an empty cart; and a valid multiline browser POST retained its CRLF in the live Store API cart response. The submitted `<b>` tags were removed and ampersands were escaped in display data.

The real WooCommerce commerce proof passed 17 checks. A forged classic array payload and a forged Store API array payload were rejected. Classic form serialization preserved `\r\n`; Store API input preserved `\n`. Both stripped HTML tags. Woo's actual `wc_get_formatted_cart_item_data()` output escaped `&` and converted the retained newline to `<br />`. Store API checkout created a real order; structured order metadata and public display metadata retained the submitted newline. Generated HTML and plain email bodies retained the multiline content safely. Woo order-again data and a real cart add restored the exact structured textarea value.

This proof closes the row's declared safe-newline behavior; it does not accept textarea `default`/`placeholder` builder parity, import/export mapping, repeat-field semantics, or other themes/platform floors. The broader `WAPF-ADMIN-IMPORT-EXPORT` capability remains partial and includes unproven setting round-trips.

## Reproduction

The disposable site was cloned from the existing local test site into `/tmp/opf-textarea-woo`, linked to this checkout, and served only on loopback. Run the browser between setup and commerce; cleanup deletes the fixture product/group/user and test orders from that clone.

```sh
OPF_TEXTAREA_E2E_ALLOW=1 OPF_TEXTAREA_E2E_PHASE=setup wp --path=/tmp/opf-textarea-woo eval-file bin/e2e-textarea-lifecycle.php
OPF_BASE_URL=http://127.0.0.1:8127 OPF_TEXTAREA_ARTIFACT_DIR=/tmp/opf-textarea-artifacts node bin/e2e-textarea-browser-test.mjs
OPF_TEXTAREA_E2E_ALLOW=1 OPF_TEXTAREA_E2E_PHASE=commerce wp --path=/tmp/opf-textarea-woo eval-file bin/e2e-textarea-lifecycle.php
OPF_TEXTAREA_E2E_ALLOW=1 OPF_TEXTAREA_E2E_PHASE=cleanup wp --path=/tmp/opf-textarea-woo eval-file bin/e2e-textarea-lifecycle.php
```

Browser screenshots and raw JSON results are retained in `/tmp/opf-textarea-artifacts`; the executable proof scripts are `bin/e2e-textarea-browser-test.mjs` and `bin/e2e-textarea-lifecycle.php`.
