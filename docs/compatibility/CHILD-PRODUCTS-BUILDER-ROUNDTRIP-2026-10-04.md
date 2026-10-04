# Child-products builder search and save/reload evidence

## Result

Fixed a builder defect in manual linked-product selection. WooCommerce SelectWoo
updated the picker value through jQuery, while OPF listened only with a native
`addEventListener('change', ...)` callback. The selected product was visible in
the picker but did not create a linked-product choice row, so its settings could
not be edited or saved.

The builder now subscribes through jQuery when available, with the native event
fallback for screens without jQuery. A real WooCommerce AJAX search selection
now creates an editable row. Authenticated Chromium then created a field group,
edited its quantity mode, saved through OPF's REST endpoint, reopened it, and
verified persisted controls and data after a fresh reload.

This is a bounded builder/configuration result. **The broad child-products
parity row remains partial**; this proof does not cover storefront/cart/order
lifecycles or all WAPF subtypes and integration edges.

## Red and green

Before the code change, a real SelectWoo search returned `Builder child alpha
(#15203)` with HTTP 200 and inserted an `<option>` into the picker, but the
builder rendered zero `.opf-b-product-choice` rows. After the fix, the same
selection rendered the editable row. The full browser harness now performs four
real WooCommerce search selections and passes **18/18 checks** with no uncaught
browser errors.

## Saved settings compared with WAPF Extended 3.1.5

The browser-created group contains:

- Manual `card`: two searched products with fixed/free child pricing. The test
  edits child quantity mode from `parent` to `one` and verifies `one` after a
  fresh edit-screen reload.
- Manual `card-qty`: two searched products with fixed/free pricing, `plus_min`
  controls, aggregate minimum 2 and maximum 8, and per-child defaults/bounds
  `1 / 0 / 4` and `2 / 1 / 6`.
- Category `vcard`: the test category, maximum 2 products, `name_asc` order,
  free child pricing, and `parent` child quantity mode.

After reload, the server-rendered builder model equals the saved REST response
and each tested setting is visible in its control. The saved database model has
the same JSON values as the REST response. Seven zero-valued pricing amounts
are represented as PHP `double 0.0` in the normalized database model and
`integer 0` in the decoded response; this does not change their JSON value.

The scoped Node unit regression passes **2/2 checks** for the SelectWoo jQuery
subscription and native fallback. The authenticated browser run exercises the
actual selection behavior end to end.

OPF exports this saved model to a WAPF Tools payload, then loads the installed
WAPF Extended 3.1.5 `Field_Groups::raw_json_to_field_group()` parser with its
native linked-products sanitizer. **12/12 model checks pass** for subtypes,
manual/category selection, quantity modes, category query/pricing settings,
fixed/free child prices, aggregate limits, and per-child limits/defaults. WAPF's
native parser represents per-child quantity values as strings; the source
sanitizer uses its text path, and the compared values match numerically.

Reference files, read-only:

- `includes/controllers/class-linked-products-controller.php` — linked-product
  subtype, selection, quantity-mode and category-query sanitizer
  (`1438–1475`).
- `includes/classes/class-field-groups.php` — native Tools field parser and
  field model construction (`82`, `199–244`, `348–357`).

SHA-256 at run time: plugin header `4ff8860193e739b86688dc2ca9414c614135350512dba5aa86d24005378f0f3c`; linked-products controller `38293fc4c39eb58002945488454a0233a88e65c1abe5cfa09c010db9a3de2e2d`; field-groups parser `a93eec77639cf7ba4bc23908927421dacf26490de141fda7734ed7560720a9f8`.

## Environment, cleanup and limits

Run on the marked disposable clone `/tmp/opf-child-builder-save-reload-wp`,
loopback `127.0.0.1:8465`: WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11,
Chromium 147.0.7727.15. WAPF Extended 3.1.5 source was read from
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`;
the plugin was not activated or bootstrapped in this lane.

The clone is a copied SQLite database. OPF is installed there as a real copied
plugin directory so its admin assets and WooCommerce SelectWoo assets are served
from valid paths. No production database, plugin files, website, or protected
tabs markup were changed. The proof used authenticated Chromium and the clone's
actual `POST /wp-json/opf/v1/groups` builder save endpoint. Setup/cleanup only
touch this clone and exact uniquely named test records.

The builder product search ran through WooCommerce `admin-ajax.php` and returned
HTTP 200 for all four child selections. The native WAPF comparison tests the
plugin's Tools JSON parser and in-memory field model; it does not write a WAPF
group to the database or activate WAPF.

Raw run JSON and screenshot: `/tmp/opf-child-builder-save-reload-evidence/`.
Final cleanup removed the created group, three virtual products, category, and
state option; `cleanup-results.json` records deleted IDs and confirms the state
option was removed.

## Reproduction

With the marked clone running and its disposable admin password in the
mode-0600 file `/tmp/opf-child-builder-save-reload-admin-password`:

```sh
node --test tests/js/opf-builder-products-picker.test.cjs
node --check bin/e2e-child-products-builder-save-reload.mjs
php -l bin/e2e-child-products-builder-save-reload.php
php -l bin/e2e-child-products-builder-save-reload-setup.php
php -l bin/e2e-child-products-builder-save-reload-cleanup.php
OPF_CHILD_BUILDER_ALLOW=1 wp --path=/tmp/opf-child-builder-save-reload-wp eval-file bin/e2e-child-products-builder-save-reload-setup.php
OPF_PLAYWRIGHT_ROOT=/home/followersya-5hqi7/followersya.com node bin/e2e-child-products-builder-save-reload.mjs
OPF_WAPF_SOURCE_PATH=/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended wp --path=/tmp/opf-child-builder-save-reload-wp eval-file bin/e2e-child-products-builder-save-reload.php
OPF_CHILD_BUILDER_ALLOW=1 wp --path=/tmp/opf-child-builder-save-reload-wp eval-file bin/e2e-child-products-builder-save-reload-cleanup.php
```
