# Price display setting: cart/order non-interference — 2026-10-05

## Scope

This is the narrow runtime result of `bin/e2e-simple-price-display-test.php` on a
live WooCommerce install. It proves that OPF's per-product price display setting
does not alter WooCommerce's own catalog/cart price HTML or order totals. It is
**not** a price-hint proof: the fixture never renders or asserts a
`+$N` hint, so it closes none of the named residuals of
`WAPF-DISPLAY-PRICE-HINTS` (currency-plugin/tax hint formatting, WAPF's editable
order-item pricing-hint metadata, legacy hint metadata on import, the
`opf:pricing` CustomEvent + preview-display cases). It also re-proves part of
`WAPF-DISPLAY-PRODUCT-PRICE` (the `hide` mode leaves the charged price intact).

## Environment

- Date: 2026-10-05. Source commit `a3c6177` (`master`).
- Runtime: disposable WordPress `/home/followersya-5hqi7/opf-test/wordpress` —
  WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite, theme
  `twentytwentyfive`, plugin copy synced from the source checkout and verified
  identical with `diff -rq --exclude=.git --exclude=node_modules` (exit 0),
  plugin active (`0.1.0`). No WAPF/WAPF Extended install.

## Verification

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
W="wp --path=/home/followersya-5hqi7/opf-test/wordpress"

$W eval-file bin/e2e-simple-price-display-test.php prepare
# SIMPLE_PRICE_URL=http://opf.test/product/opf-e2e-simple-price-display-product/
# HIDDEN_PRICE_URL=http://opf.test/product/opf-e2e-hidden-price-product/
# VARIABLE_PRICE_URL=http://opf.test/product/opf-e2e-variable-price-display-product/
# SIMPLE_PRICE_IDS=15077,15078,15079
# Success: Simple-price fixture prepared.              (exit 0)

$W eval-file bin/e2e-simple-price-display-test.php verify
# Success: Simple-product base price remains $10 through cart and order.   (exit 0)

$W eval-file bin/e2e-simple-price-display-test.php cleanup
# Success: Simple-price fixtures removed.              (exit 0)
```

Checks that passed (`verify` exits 0 only if every assertion held):

1. the fixture product's catalog `get_price('edit')` is still `10.00`;
2. with `_opf_price_display` forced to `hide`, the add-to-cart cart item's
   `get_price()` is still `10.0` (display setting did not re-price the line);
3. the cart item's `get_price_html()` still contains `10.00`;
4. the created order item total is still `10.0`;
5. the product's previous `_opf_price_display` / `_opf_price_label` meta was
   restored in the fixture's `finally` block.

Checks that failed: none (the fixture also fails closed on setup, so an exit 0
proves the fixture itself was created as documented).

## Cleanup

`cleanup` removed the three fixture products, the fixture deletion of the
variable product's variations, and the state option (exit 0). Post-run audit:
no fixture products remain by name, `recent_orders=0`,
`fixture_orders_leaked=0`, `upload_fixture_files=0`; disposable site URL,
theme, plugin list and active plugins unchanged.

## Status

`WAPF-DISPLAY-PRICE-HINTS` was recorded as **partial** at the time of this run;
this run adds only a dated non-interference check on this commit for the
price-display setting's effect on cart/order price. It stays a valid evidence
artifact. Ledger outcome (2026-10-05, later the same day): the row was promoted
to `supported` on the WAPF 3.1.5 cart/order comparison and the storage/scope
analysis recorded in its ledger cell.
