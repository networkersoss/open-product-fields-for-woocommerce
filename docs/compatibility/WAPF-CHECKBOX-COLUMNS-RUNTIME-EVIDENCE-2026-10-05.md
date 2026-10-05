# Checkbox columns runtime evidence — 2026-10-05

## Scope

This run closes the cart/order lifecycle half of `WAPF-FIELD-CHECKBOX-COLUMNS`.
The 2026-10-04 entry only proved schema, mapper/exporter, renderer and isolated
Chromium grid behavior, and stated explicitly that "Admin REST save/reload, full
Woo cart/order lifecycle, and WAPF Pro 3.2 Tools/WXR parser/runtime comparison
remain unverified". This run executes the cart/order half on a live
WooCommerce storefront plugin load. It does not add a WAPF comparison: the
disposable runtime has no WAPF/WAPF Extended installed, so the WAPF Pro 3.2
value range, responsive behavior, saved shape and markup remain unverified.

## Environment

- Date: 2026-10-05.
- Source: `/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce` at commit `a3c6177` (branch `master`, `feat(export): round-trip WAPF number options`).
- Runtime: disposable WordPress at `/home/followersya-5hqi7/opf-test/wordpress` — WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite driver, HPOS off, theme `twentytwentyfive`.
- The runtime plugin copy was stale (2026-09-19). It was synced from the source checkout with `rsync -a --delete --exclude='.git' --exclude='node_modules'` and then verified byte-identical:
  `diff -rq --exclude='.git' --exclude='node_modules' <src> <runtime>` printed nothing (exit 0).
  `wp plugin list` reports `open-product-fields-for-woocommerce active 0.1.0`.
- WP-CLI: `/usr/local/bin/wp --path=/home/followersya-5hqi7/opf-test/wordpress`. All commands below were run from the source repository root.

## Fixture correction required to run phase `verify`

The shipped fixture was internally inconsistent: `prepare` wrote `'columns' => 2`
while `verify` asserted `3`, both introduced by the same commit. The first
`verify` run therefore failed at its first assertion:

```text
Error: Saved checkbox column count did not persist through resolver/reload.
EXIT=1
```

A read-only probe of the prepared fixture showed the plugin itself behaved
consistently (it stored and re-resolved exactly the configured value):

```text
resolver_columns=2 stored_columns=2
```

`bin/e2e-checkbox-columns-test.php` was corrected in `prepare` from
`'columns' => 2` to `'columns' => 3` (one token) so the shipped `verify`
assertion stays as strong as written and matches the 3-column markup of
`bin/e2e-checkbox-columns-browser-test.mjs`. No plugin source was changed.

### Fixture defect, and what it does not invalidate

The defect is in the fixture, which was added by `09a78ad` and never modified
until this one-token change: both the `prepare` value and the `verify`
assertion landed in that commit, so this fixture could never have reached exit 0.

The defect invalidates **no existing evidence claim**:

- no document in the repository cites this fixture
  (`grep -rn "e2e-checkbox-columns-test" docs/` matches only this new doc);
- the 2026-10-04 checkbox-columns evidence explicitly records
  "Admin REST save/reload, full Woo cart/order lifecycle, and WAPF Pro 3.2
  Tools/WXR parser/runtime comparison remain unverified", i.e. the row never
  claimed the lifecycle this fixture covers;
- the plugin behavior itself was correct: the stored model and the group
  resolver both returned the configured `2` before the correction
  (`resolver_columns=2 stored_columns=2`).

Consequently the cart/order/order-again proof below is the first executed
PHP-side lifecycle proof for this row, and it is reported here for the first
time.

## Verification

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
W="wp --path=/home/followersya-5hqi7/opf-test/wordpress"
$W eval-file bin/e2e-checkbox-columns-test.php prepare
# CHECKBOX_COLUMNS_URL=http://opf.test/product/opf-e2e-checkbox-columns-product/
# CHECKBOX_COLUMNS_IDS={"product_id":15071,"group_ids":[15072]}
# Success: Checkbox-column fixture prepared.            (exit 0)

$W eval-file bin/e2e-checkbox-columns-test.php verify
# Success: Three-column configuration survived save/resolution; selected choices and $3.75 add-on total survived cart, order metadata, and order-again.   (exit 0)

$W eval 'echo "resolver_columns=... stored_columns=..."'   # read-only capture of the stored model
# resolver_columns=3 stored_columns=3

OPF_PLAYWRIGHT_PACKAGE=/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json \
  node bin/e2e-checkbox-columns-browser-test.mjs
# ok OPF checkbox grid: configured columns retained across viewports; labels and keyboard toggling work (not a WAPF comparison)   (exit 0)

$W eval-file bin/e2e-checkbox-columns-test.php cleanup
# Success: Checkbox-column fixtures removed.             (exit 0)
```

Checks that passed (one `Success` line per phase; the fixture fails closed on the
first violated assertion, so an exit 0 means all of them held):

1. `prepare` — product + group created, fixture state saved, permalink returned.
2. `verify` — configured `columns: 3` survived save through the group resolver
   and through the stored post model (`resolver_columns=3 stored_columns=3`).
3. `verify` — selected checkbox choices `['gift','rush']` survived
   `woocommerce_add_to_cart_validation` and became the cart item's OPF payload.
4. `verify` — their fixed prices summed correctly: line total `13.75`
   (10.00 base + 2.50 gift wrap + 1.25 rush packing).
5. `verify` — the same values persisted in the order item `_opf_fields` meta.
6. `verify` — `CartIntegration::restore_order_again()` restored both choices.
7. Browser (1 grouped check) — grid computed to exactly 3 columns at viewport
   widths 1280 / 600 / 400, `getByLabel('Gift wrap')` resolved one checkbox, and
   Space toggled it.

Checks that failed:

- The as-shipped `verify` assertion mismatch documented above (fixture defect).
- Nothing else failed; there were no plugin-behavior failures in this run.

## Cleanup

`cleanup` deleted the fixture group, product, the state option and flushed the
field-group cache (exit 0). A post-run audit confirmed:

```text
attachment markers _opf_image_change_fixture=0 / _opf_child_products_fixture=0
recent_orders=0   fixture_orders_leaked=0   upload_fixture_files=0
```

The disposable site URL (`http://opf.test`), theme, plugin list and active
plugins were unchanged from the start of the run.

## Status

`WAPF-FIELD-CHECKBOX-COLUMNS` gains real cart, order and order-again runtime
proof on WooCommerce 11.1.0 for a 3-column configuration. It stays **partial**:
admin REST save/reload remains unverified, and the WAPF Pro 3.2 setting range,
saved shape, responsive behavior and markup comparison remain unavailable
because no WAPF package exists on this runtime.
