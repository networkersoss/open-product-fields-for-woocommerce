# WAPF-FIELD-IMAGE-QUANTITY-ZOOM — live coexistence reproduction (2026-10-05)

## Scope

The row's residual read: *"with WAPF 3.1.5 active beside OPF, add-to-cart raises
a `TypeError` when OPF invokes `wapf/validate` with its normalized array field
(repro and trace in the runtime evidence); that coexistence defect needs
independent compatibility work."*

This lane reproduces the exact defect against a live WAPF Extended 3.1.5 beside
OPF, captures the precise `TypeError`, stack trace and triggering payload, and
then checks whether the shipped OPF build still hits it. **No product code was
changed in this lane** (the task asked for a reproduced, characterised defect).

## Environment

- WAPF Extended **3.1.5** active beside OPF 0.1.0.
- Disposable clone `/tmp/opf-wapfref-compare-wp`, `http://127.0.0.1:8251`, WP 7.1.2 / WC 11.1.0 / PHP 8.5.11.
- Harness: `bin/e2e-wapfref-image-quantity-zoom-coexistence.php` plus
  `bin/e2e-wapfref-image-quantity-zoom-coexistence-browser.mjs`.
- Artifacts: `docs/compatibility/wapf-reference-proof-20261005/row5-image-quantity-zoom/`.

## Commands

```sh
cd /tmp/opf-lane-wapfref
WP="wp --path=/tmp/opf-wapfref-compare-wp --allow-root"
OUT=docs/compatibility/wapf-reference-proof-20261005
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-image-quantity-zoom-coexistence.php setup
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-image-quantity-zoom-coexistence.php reproduce
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules OPF_WAPFREF_OUT=$OUT \
  node bin/e2e-wapfref-image-quantity-zoom-coexistence-browser.mjs
```

## Reproduced defect (`typeerror-reproduction.json`)

WAPF's native linked-products validator is registered on `wapf/validate`:

```text
Linked_Products_Controller::validate_cart  (priority 10, class-linked-products-controller.php)
```

Dispatching that hook with OPF's normalized image-quantity **array** field (the
pre-bridge call shape) raises exactly:

```text
TypeError:
SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller::validate_cart():
Argument #3 ($field) must be of type SW_WAPF_PRO\Includes\Models\Field, array given,
called in /tmp/opf-wapfref-compare-wp/wp-includes/class-wp-hook.php on line 353
```

- Thrown from
  `…/advanced-product-fields-for-woocommerce-extended/includes/controllers/class-linked-products-controller.php:455`
  (the typed parameter `Field $field`).
- Stack: `WP_Hook->apply_filters()` (`class-wp-hook.php:353`) → the typed
  callback.
- Triggering payload: filter `wapf/validate`; arg 1 `['error' => false]`; arg 3
  is OPF's normalized field, `gettype = array`, `id = prints`, keys
  `id,label,description,type,required,width,css_class,placeholder,choices,pricing,conditionals,description_presentation,hide_cart,hide_checkout,hide_order,multiple,image_zoom`.

## Current build does not hit it

`OPF\Compat\WapfHooks::validate_field()` — the shipped pre-dispatch bridge —
returns `[]` with no exception for the same field, and the native callback is
restored afterwards at its original priority (`native_callback_after_bridge: 10`).

Run live in real Chromium with WAPF **and** OPF both active
(`browser-add-to-cart.json`, **6/6 pass**):

1. WAPF 3.1.5 is enqueued on the product page;
2. the both-active classic add-to-cart POST is not a server error (HTTP 200);
3. the Store API cart contains the fixture product;
4. no 5xx responses during the request;
5. no failed browser requests;
6. the isolated browser cart is emptied afterwards.

This matches the fix already recorded in
[WAPF validation coexistence](WAPF-VALIDATION-COEXISTENCE-2026-10-04.md): during
an OPF field dispatch the bridge temporarily removes only WAPF's typed native
validator (and the date validator) and restores it in `finally`.

## Result

- **Closed / withdrawn as stale:** the residual. The exact `TypeError` the row
  describes is real for the pre-bridge call shape, but the shipped build I
  tested no longer triggers it and the real both-active storefront add-to-cart
  succeeds. The ledger row was updated to withdraw this residual.
- **Remaining (unchanged):** the 3.1.5 builder-surface difference — 3.1.5
  exposes `large_image` for image swatches but not for image+quantity fields, so
  OPF's toggle is a superset, and the WAPF 3.2.1 builder serialization contract
  was not independently verified.

## Cleanup

`cleanup` deleted the fixture product and group and removed the
`opf_wapfref_*` options; cache flushed; the browser emptied its own cart. No
`wapfref-*` objects remain. Production was read-only.
