# Image-quantity zoom builder toggle — 2026-10-05

## Scope

A dated re-run of the builder half of `WAPF-FIELD-IMAGE-QUANTITY-ZOOM` at the
current commit. It closes no residual: the row already carries admin
save/reload, storefront hover/focus, 390px layout and cart/checkout price
evidence ([runtime evidence](IMAGE-QUANTITY-ZOOM-RUNTIME-2026-10-04.md)). The
row's open items stay open, and the WAPF-3.1.5-coactive add-to-cart TypeError
recorded there could not be exercised here because this runtime has no WAPF
package installed.

## Environment

- Date: 2026-10-05. Source commit `a3c6177` (`master`).
- Runtime for the fixture-backed rows: disposable WordPress
  `/home/followersya-5hqi7/opf-test/wordpress` — WordPress 7.1.2, WooCommerce
  11.1.0, PHP 8.5.11, SQLite, plugin copy synced from the source checkout and
  verified identical with `diff -rq --exclude=.git --exclude=node_modules`
  (exit 0).
- This particular harness is self-contained: it loads
  `assets/js/opf-builder.js` from the source checkout into Chromium with a
  fixture field model and stubs the REST layer, so it needs no WordPress.

## Verification

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
node bin/e2e-image-quantity-zoom-builder-test.mjs
# ok image quantity zoom builder toggle persists without changing price or quantity settings   (exit 0)
```

Checks that passed (one line, exit 0 means all of them held):

1. the `image_zoom` toggle renders visible for an `image_quantity` field;
2. checking it and saving produces `image_zoom: true` in the REST payload;
3. the field type is unchanged (`image_quantity`);
4. the choice pricing is untouched (`pricing.type: fixed`, `amount: 2`);
5. the choice quantity bounds are untouched (`quantity.max: 4`);
6. no uncaught page errors.

Checks that failed: none.

## Cleanup

No WordPress state was created by this harness (fixture markup is set in-memory,
the save call is stubbed). The disposable WordPress was left with its original
site URL, theme, plugin list and active plugins; the fixture-backed fixtures
from this same lane were removed by their own cleanup phases (see the sibling
2026-10-05 docs).

## Status

`WAPF-FIELD-IMAGE-QUANTITY-ZOOM` stays **partial**, unchanged. Remaining:
WAPF Extended 3.2.1 admin contract, and the separate recorded defect where
WAPF 3.1.5 active beside OPF throws an add-to-cart TypeError from
`wapf/validate` (not reproducible in this runtime — no WAPF installed).
