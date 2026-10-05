# Child products runtime fixture — 2026-10-05

## Scope

A dated re-run of the child-product fixture's `prepare`/`cleanup` phases on a
live WooCommerce install, plus a read-only HTTP render sanity check of the
prepared product page. It closes no residual: `WAPF-FIELD-CHILD-PRODUCTS`
already carries import/export, query, cart/order, stock/refund, Store API,
variation and builder evidence. The named residuals (real currency-plugin
session behavior, other tax classes, broader Blocks/checkout edges, cross-site
ID mapping, supported-version proof, and the relative-increase compounding
semantics) are untouched by this run. The browser lifecycle harness for this
row (`bin/e2e-child-products-browser-test.mjs`) was not run: it requires the
`/tmp/opf-url-native-parity` clone's `state.json` and the guarded loopback clone
that this runtime does not have.

## Environment

- Date: 2026-10-05. Source commit `a3c6177` (`master`).
- Runtime: disposable WordPress `/home/followersya-5hqi7/opf-test/wordpress` —
  WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite, theme
  `twentytwentyfive`, plugin copy synced from the source checkout and verified
  identical with `diff -rq --exclude=.git --exclude=node_modules` (exit 0),
  plugin active (`0.1.0`). No WAPF/WAPF Extended install.
- The fixture temporarily enables taxes (`woocommerce_calc_taxes=yes`,
  `US:CA`, `tax_based_on=base`) and inserts a 10% US-CA rate, and restores all
  three options plus the cart page content in `cleanup`.

## Verification

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
W="wp --path=/home/followersya-5hqi7/opf-test/wordpress"

$W eval-file bin/e2e-child-products-test.php prepare
# CHILD_PRODUCTS_URL=http://opf.test/product/opf-e2e-child-products-parent/
# CHILD_PRODUCTS_PARENT=15092
# CHILD_PRODUCTS_IDS=15089,15090,15091
# CHILD_PRODUCTS_CATEGORY=20
# CHILD_PRODUCTS_TAX_RATE=1
# Success: Child-product E2E fixture prepared.        (exit 0)

curl -s -o "$SP/child-products-page.html" -w "http_code=%{http_code} bytes=%{size_download}\n" \
  "http://127.0.0.1:8090/product/opf-e2e-child-products-parent/"
# http_code=200 bytes=167610
# rendered storefront contained data-opf-field="bundle_items" and data-opf-field="category_items"
# ($SP is the transient scratchpad dir; that HTML dump was deleted after the run — the counts above are the evidence)

$W eval-file bin/e2e-child-products-test.php cleanup
# Success: Child-product E2E fixtures and temporary tax configuration removed.   (exit 0)
```

Checks that passed:

1. `prepare` exits 0 only after asserting the saved group round-trips with
   exactly 2 fields through `FieldGroups::group_from_post()`, i.e. the
   `child_products` field with `product_source: specific` (3 child IDs),
   `product_display: images`, `quantity_mode: multiply_parent`,
   `image_zoom: true`, `min_selections: 1` and the second field with
   `product_source: categories` survives on a live WooCommerce install;
2. the fixture created the parent + 3 children (one with stock 1), the product
   category, the media attachment and the temporary tax rate, printing their IDs;
3. read-only HTTP render check (not a parity proof): the prepared parent page
   returned HTTP 200 (167,610 bytes) and contained the rendered containers for
   both fields.

Checks that failed: none. The curls above are supplementary render checks, not a
harness result; no browser interaction, add-to-cart or checkout was performed in
this run.

## Cleanup

`cleanup` removed the group, the parent and children, the fixture attachment,
the product category, the temporary tax rate, the temporarily modified cart page
content and all three temporary options (exit 0). Post-run audit:

```text
woocommerce_calc_taxes=no  woocommerce_default_country=US:CA  woocommerce_tax_based_on=shipping
cart_page_content_sha256=c620a62abe3d4851d369ff8dfc742484c3c9419e91cca35eae3e3c8b36221696   (identical to the pre-run capture)
tax_rate_count=0
option_opf_e2e_child_products_* = ABSENT (all three state options deleted)
attachment markers _opf_child_products_fixture=0
recent_orders=0   fixture_orders_leaked=0   upload_fixture_files=0
```

Pre-existing fixture groups on the disposable WordPress (E2E Upload Group, E2E
Group, E2E Types Group, …) were not touched. Disposable site URL, theme, plugin
list and active plugins unchanged.

## Status

`WAPF-FIELD-CHILD-PRODUCTS` stays **partial**; every previously named residual
remains open. This run only re-confirms that the fixture prepares and cleans up
correctly at `a3c6177` and that both fields render on the live storefront.
