# WAPF Extended 3.1.5 comparative reference proof — 2026-10-05

This directory holds the side-by-side evidence for five `partial` ledger rows
whose residual was a **one-sided proof**: OPF's behaviour was proven, but never
compared against a live WAPF Extended 3.1.5 runtime. This run installs the
installed 3.1.5 package into a disposable SQLite WordPress clone, activates it
beside OPF, and compares the served markup, the browser behaviour and the
cart/order lifecycle.

## Environment

- Date: 2026-10-05.
- OPF source: this lane repository (`/tmp/opf-lane-wapfref`), synced into the clone plugin directory with `rsync -a --delete --exclude='.git' --exclude='node_modules'`.
- WAPF reference: **Extended 3.1.5** (`advanced-product-fields-for-woocommerce-extended`), copied read-only from the production plugin directory; plugin header `Version: 3.1.5`. 3.2.x/Pro internals were never referenced.
- Disposable clone: `/tmp/opf-wapfref-compare-wp` (copy of `/tmp/opf-wapf-validation-wp`), site URL `http://127.0.0.1:8251`, SQLite drop-in, WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, OPF 0.1.0, theme `twentytwentyfive`.
- Production WordPress (`/home/followersya-5hqi7/followersya.com/bedrock/web`) and its plugins directory were **read only**; nothing there was activated, deactivated, modified or written.

### Clone contamination neutralised

The source clone carried pre-existing leftovers unrelated to this lane:

- 746 bogus `wapf_product` posts (translation artefacts) whose serialized
  `post_content` fails `unserialize()`; WAPF's `Field_Groups::get_all()` skips
  them (emitting warnings) and they never resolved as valid groups. The
  row-4 ODF wanted a local group only, so they were left in place but ignored.
- One `wapf_product` CPT (`15095`) and attachments (`15096`,`15097`) created by
  an exploratory probe were deleted.
- Seven pre-existing `opf_field_group` posts titled `E2E *` were deleted so the
  image-change product page rendered only the fixture group.

## Commands

All PHP commands run from the repo root and are clone-path guarded
(`OPF_WAPFREF_ALLOW=1`, `ABSPATH === /tmp/opf-wapfref-compare-wp`); WP-CLI is
`wp --path=/tmp/opf-wapfref-compare-wp --allow-root`.

```sh
# local server
wp --path=/tmp/opf-wapfref-compare-wp server --host=127.0.0.1 --port=8251 --allow-root

# row 1 — child-products image zoom (WAPF + OPF active)
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=docs/compatibility/wapf-reference-proof-20261005 \
  wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-child-products-image-zoom.php setup
# ... render
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
  node bin/e2e-wapfref-child-products-image-zoom-browser.mjs

# row 2 — image change (WAPF + OPF active)
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=... wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-image-change.php setup
# ... render
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules node bin/e2e-wapfref-image-change-browser.mjs

# row 3 — checkbox columns (WAPF active for the capability probe; deactivate WAPF for the grid measurement)
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=... wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-checkbox-columns-admin.php wapf-options
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=... OPF_WAPFREF_ADMIN_PASSWORD=<24+ chars> \
  wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-checkbox-columns-admin.php setup
OPF_WAPFREF_OUT=... OPF_WAPFREF_ADMIN_PASSWORD=<24+ chars> \
  NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules node bin/e2e-wapfref-checkbox-columns-admin-browser.mjs
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=... wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-checkbox-columns-admin.php verify

# row 4 — price hints (WAPF + OPF active)
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=... wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-display-price-hints.php setup|render|cart

# row 5 — image quantity zoom coexistence (WAPF + OPF active)
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=... wp --path=/tmp/opf-wapfref-compare-wp eval-file bin/e2e-wapfref-image-quantity-zoom-coexistence.php setup|reproduce
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules node bin/e2e-wapfref-image-quantity-zoom-coexistence-browser.mjs
```

Every harness ends with a `cleanup` phase that deletes its products, groups,
attachments, orders, temporary administrator and options.

## Per-row result

| Row | Comparison | Result | Residual closed |
| --- | --- | --- | --- |
| `WAPF-FIELD-CHILD-PRODUCTS-IMAGE-ZOOM` | [doc](../WAPF-REFERENCE-CHILD-PRODUCTS-IMAGE-ZOOM-2026-10-05.md) | 8/8 live + server markup parity | markup/zoom comparison closed |
| `WAPF-INTERACTION-IMAGE-CHANGE` | [doc](../WAPF-REFERENCE-IMAGE-CHANGE-2026-10-05.md) | 6/6 live + payload parity | live `last`/reversal comparison closed |
| `WAPF-FIELD-CHECKBOX-COLUMNS` | [doc](../WAPF-REFERENCE-CHECKBOX-COLUMNS-2026-10-05.md) | 7/7 admin REST + storefront; 3.1.5 has no control | admin REST save/reload closed; WAPF comparison impossible on 3.1.5 |
| `WAPF-DISPLAY-PRICE-HINTS` | [doc](../WAPF-REFERENCE-DISPLAY-PRICE-HINTS-2026-10-05.md) | identical cart strings + line totals | cart/order one-sided comparison closed |
| `WAPF-FIELD-IMAGE-QUANTITY-ZOOM` | [doc](../WAPF-REFERENCE-IMAGE-QUANTITY-ZOOM-COEXISTENCE-2026-10-05.md) | 6/6 both-active add-to-cart; TypeError does not reproduce at HEAD | coexistence `TypeError` residual withdrawn as stale |

## Artifacts

```text
fixtures/                         source PNGs imported by the row-1/row-2 harnesses (child-thumb, child-zoom, ic-g1..g3)
row1-child-products-image-zoom/  markup-compare.json, wapf/opf zoom on/off HTML, browser-zoom-results.json, *.png
row2-image-change/               image-change-markup-compare.json, field-group HTML, browser-image-change.json, *.png
row3-checkbox-columns/           wapf-3.1.5-checkbox-options.json, admin-rest-save-reload.json, browser-admin-rest.json, storefront-4-columns.png
row4-display-price-hints/        hint-format-compare.json, cart-order-hints.json
row5-image-quantity-zoom/        typeerror-reproduction.json, browser-add-to-cart.json
```

## Cleanup performed

- All fixture products, field groups, attachments, orders, the temporary
  `opf_wapfref_admin` administrator and the `opf_wapfref_*` options were
  deleted (each harness's `cleanup` phase, verified with a post-run audit).
- WAPF Extended was deactivated and the clone left with the same active plugins
  as the source clone (OPF, WooCommerce, SQLite integration). The clone's site
  URL remains `http://127.0.0.1:8251` (its own disposable value).
- The local `php -S`/`wp server` process on port 8251 was stopped.
- No production file, database, cache or option was changed.

## Reproducibility note

This run intentionally stages every artifact **inside the repository** (not in
`/tmp`), which is the G4 reproducibility requirement these rows were blocked on.
The harnesses are additive: `bin/e2e-wapfref-*.php` and `bin/e2e-wapfref-*.mjs`.
They are `wp eval-file` harnesses and each guards on `defined( 'ABSPATH' )`
before doing anything.

The repo's PHP language server reports WordPress/WooCommerce/WAPF symbols in
`bin/` harnesses as undefined. That is a pre-existing, systemic false positive:
pi-lens runs intelephense with its bundled WordPress stubs disabled, so even an
untouched shipped fixture reproduces the same diagnostics. It is an analyzer
configuration matter, not a code defect, and no stubs or suppressions are added
to the repository for it.
