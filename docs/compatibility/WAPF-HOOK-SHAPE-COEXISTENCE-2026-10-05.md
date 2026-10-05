# WAPF/OPF hook-shape coexistence — 2026-10-05

## Scope

`WAPF-FIELD-IMAGE-QUANTITY-ZOOM` was held `partial` on a coexistence residual: "with
WAPF 3.1.5 active beside OPF, add-to-cart raises a `TypeError` when OPF invokes
`wapf/validate` with its normalized array field". This run reproduces that
failure, records the state of the bridge at `ff58b68`, closes the **live**
instance of the same defect that remained, and replaces the bridge's
class/method denylist with a structural rule.

## The 3.1.5 contract

WAPF's field argument for the bridged hooks is an object of
`SW_WAPF_PRO\Includes\Models\Field`, and every listener WAPF registers on those
hooks dereferences it as an object:

| Hook | WAPF 3.1.5 registration | WAPF 3.1.5 listener | Field usage |
| --- | --- | --- | --- |
| `wapf/validate` | `includes/controllers/class-linked-products-controller.php:37` | `Linked_Products_Controller::validate_cart` | **typed parameter** `Field $field` at `class-linked-products-controller.php:455` |
| `wapf/validate` | `extend/date.php:152` | `wapfe_validate_cart_data` | `$field->type` at `extend/date.php:157` |
| `wapf/html/field_container_classes` | `includes/controllers/class-linked-products-controller.php:35` | `Linked_Products_Controller::maybe_add_pricing_class` | `$field->type` at `class-linked-products-controller.php:1021` |
| `wapf/order_again/before_cart_item_field` | `includes/controllers/class-linked-products-controller.php:76` | `Linked_Products_Controller::prepare_order_again_cart_item` | `$field->type` at `class-linked-products-controller.php:92` |
| `wapf/order/order_item_field` | `includes/controllers/class-linked-products-controller.php:59` | `Linked_Products_Controller::expand_order_item_field` | arg 3 is a **cart-item field array**, not a `Field` (`class-linked-products-controller.php:713-719`) — left alone deliberately |

The producer side is `Cart::validate_cart_data()`: it passes
`$field_group->fields` entries (`includes/classes/class-cart.php:180`, fields
built at `class-cart.php:106-108`). OPF has no such object — it works on
normalized arrays (`Engine\FieldGroup::normalize_field`) — so a WAPF-native
listener cannot accept OPF's field argument.

## Reproduction (both plugins active)

Disposable WordPress `/home/followersya-5hqi7/opf-test/wordpress`
(WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite) with WAPF Extended
3.1.5 installed and **active** beside OPF 0.1.0, and the fixture product from
`bin/e2e-wapf-coexistence-test.php prepare`.

`repro` dispatches both hooks with OPF's normalized array — the exact shape
OPF's bridge used before a native-listener guard existed:

```text
ok WAPF rejects the OPF array on wapf/validate
ok the rejection is WAPF's typed Field parameter
REPRO TypeError: SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller::validate_cart(): Argument #3 ($field) must be of type SW_WAPF_PRO\Includes\Models\Field, array given, called in /home/followersya-5hqi7/opf-test/wordpress/wp-includes/class-wp-hook.php on line 353
ok WAPF's date validator also reads the OPF array as an object
REPRO warnings: Attempt to read property "type" on array
ok WAPF reads $field->type on wapf/html/field_container_classes
REPRO container warnings: Attempt to read property "type" on array
```

Stack trace of the `TypeError` (captured with
`wp eval-file` on a scratch probe, same call):

```text
#0 .../wp-includes/class-wp-hook.php(353): SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller->validate_cart()
#1 .../wp-includes/plugin.php(206): WP_Hook->apply_filters()
#2 ... apply_filters()
```

The registered `wapf/validate` listeners in that runtime are exactly the two the
source predicts:

```text
prio=10 args=8 cb=wapfe_validate_cart_data
prio=10 args=8 cb=SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller::validate_cart
```

## State at `ff58b68` before this change

The bridge already removed the two `wapf/validate` listeners and WAPF's
order-again handler for the duration of an OPF dispatch, so the **`wapf/validate`
TypeError no longer reproduces through OPF's real path**: a live instrumented
listener on `wapf/validate` shows the native callbacks absent from the registry
during the dispatch

```text
PROBE field_arg_type=array(id,label,…,image_zoom) native_registered_at_dispatch=NO callbacks=
```

and a real storefront add-to-cart with the image-quantity field returned HTTP
200 with the product in the cart. The ledger's repro is therefore stale **for
that specific path** (it cites the 2026-10-04 image-quantity-zoom runtime, which
predates the guard recorded in `WAPF-VALIDATION-COEXISTENCE-2026-10-04.md`).

What was **not** covered was `wapf/html/field_container_classes`, which OPF
dispatches on every rendered field (`Service/Renderer.php:736-737`) with its
normalized array. WAPF's `maybe_add_pricing_class` read `$field->type` off that
array, producing one PHP 8 warning per rendered field — measured on the fixture
group (4 fields):

```text
[2] Attempt to read property "type" on array @ .../advanced-product-fields-for-woocommerce-extended/includes/controllers/class-linked-products-controller.php:1021   (x4)
```

## What changed

`includes/Compat/WapfHooks.php`:

- The two near-duplicate private helpers
  (`apply_validation_filter_for_opf_field`, `do_order_again_action_for_opf_field`)
  are replaced by one `dispatch_without_native_wapf( $hook_name, $is_action, $args )`
  that suspends native WAPF listeners for a synchronous dispatch and restores
  them in their original same-priority order in a `finally` block.
- The denylist (`is_a( …, 'SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller' )`
  + `'validate_cart'`, and the literal `wapfe_validate_cart_data`) is replaced by
  `is_native_wapf_listener()`: a callback is WAPF's own code when its receiver
  class is in `SW_WAPF_PRO\` or `SW_WAPF\`, or when it is one of the known WAPF
  global functions (`wapfe_validate_cart_data`). A WAPF validator under a
  different class or method name is now suspended too.
- `field_container_classes()` now dispatches through the same helper, so WAPF's
  native container-class listener no longer receives an OPF array.

Third-party WAPF-named listeners are untouched: they still receive the same
WAPF argument shapes, including the normalized OPF field at argument 3.

### Why the `wapf/validate` dispatch is kept (source evidence)

Not dispatching the hook at all was considered. It is rejected because the
bridge's whole purpose is to keep migrated third-party `wapf/…` integrations
working, and OPF's own extension surface (`opf/…`) is a different name — a
migrated site's listener would silently stop firing. The hook is therefore kept
with the WAPF argument shape, and only WAPF's own code (which needs a `Field`
object OPF cannot produce) is held back. Passing a real
`SW_WAPF_PRO\Includes\Models\Field` instead would re-enable WAPF's native
validators on OPF values, which is worse: `wapfe_validate_cart_data()` parses the
value with WAPF's configured date format (`extend/date.php:159-161`) while OPF
submits ISO dates, so it would fail valid input.

### Known limit

A **third-party** callback that wraps WAPF's typed method (for example a closure
that calls `$controller->validate_cart()` itself) is not WAPF-namespaced and is
therefore not suspended. No WAPF 3.1.5 code does this — the runtime dump of every
registered `wapf/*` listener shows WAPF's bridged-hook listeners are all
class-method or named-function callbacks — but the rule cannot reach a wrapper
someone else writes.

## Tests added

`tests/Unit/WapfHooksBridgeTest.php` — 2 new tests (14 → 16 in the file):

1. `test_opf_dispatch_suspends_any_wapf_namespaced_validation_listener` — a
   `SW_WAPF_PRO\Includes\Controllers\Future_Validator::check_field()` listener
   (unknown class and method name, non-typed) is suspended; a third-party
   listener still vetoes; the 8-argument shape, the submitted value at arg 1, the
   normalized OPF array at arg 2 and the product id at arg 3 are pinned; no
   warnings are emitted; the callback is restored in its original same-priority
   position; WAPF's own `Field`-object dispatch still reaches it.
2. `test_field_container_classes_dispatch_keeps_the_wapf_listener_off_the_opf_field`
   — `Linked_Products_Controller::maybe_add_pricing_class` receives no OPF array
   (no warning, no invocation), OPF's own classes survive, a third-party
   listener still gets the OPF field, the callback is restored in order, and
   WAPF's own `Field`-object dispatch still applies `has-pricing`.

The existing five bridge tests (including the two original coexistence tests and
the exception-restoration test) are unchanged and still pass.

## Runtime proof after the change

```sh
W="wp --path=/home/followersya-5hqi7/opf-test/wordpress"

# WAPF Extended 3.1.5 must be installed and active beside OPF in the clone
# (production WAPF is only read, never modified or activated):
rsync -a /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended/ \
  /home/followersya-5hqi7/opf-test/wordpress/wp-content/plugins/advanced-product-fields-for-woocommerce-extended/
$W plugin activate advanced-product-fields-for-woocommerce-extended
$W eval-file bin/e2e-wapf-coexistence-test.php prepare

$W eval-file bin/e2e-wapf-coexistence-test.php repro   # exit 0 — TypeError + warnings reproduced
$W eval-file bin/e2e-wapf-coexistence-test.php guard   # exit 0
$W eval-file bin/e2e-wapf-coexistence-test.php verify  # exit 0
OPF_PLAYWRIGHT_PACKAGE=/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json \
  node bin/e2e-wapf-coexistence-browser.mjs            # exit 0
$W eval-file bin/e2e-wapf-coexistence-test.php cleanup
$W plugin deactivate advanced-product-fields-for-woocommerce-extended
```

`guard` (validation + container classes + a full `Renderer::render_group()` of
the fixture group, with warnings converted to a collector):

```text
ok OPF validation completes with WAPF active
ok WAPF raises no warning for the OPF field on validation, render, or container classes
ok a third-party listener still receives the OPF field on wapf/validate
ok a third-party listener still receives the OPF field on wapf/html/field_container_classes
SUCCESS guard
```

`verify` (WAPF's own listeners are back and still work):

```text
ok WAPF's typed validator is registered after an OPF dispatch
ok WAPF's date validator is registered after an OPF dispatch
ok WAPF's container-class listener is registered after an OPF render
ok WAPF still applies its own pricing class for its own Field object
SUCCESS verify
```

`bin/e2e-wapf-coexistence-browser.mjs` — real Chromium, real classic add-to-cart
with both plugins active, 12 checks, 0 failures:

```text
ok product page returns HTTP 200 — 200
ok the fixture OPF fields render with WAPF active
ok add-to-cart form POST returns HTTP 200 — 200
ok the response contains no PHP fatal
ok the response contains no PHP warning
ok the storefront confirms the add-to-cart
ok Store API cart is readable — 200
ok the cart holds the fixture product
ok the cart quantity is 1 — 1
ok no page errors
ok no failed requests
ok isolated browser cart emptied — 200
{"url":"http://127.0.0.1:8090/product/opf-wapf-coexistence/","checks":12,"pageErrors":[],"failedRequests":[]}
```

## Cleanup

`bin/e2e-wapf-coexistence-test.php cleanup` deleted the fixture product and field
group and the fixture state, emptied the cart and flushed the field-group cache.
WAPF Extended 3.1.5 was deactivated and removed from the disposable clone (it was
never activated in production), the temporary probe mu-plugin was deleted, the
scratchpad probe products were deleted, and the `wp server` on port 8090 was
killed.

## Status

`WAPF-FIELD-IMAGE-QUANTITY-ZOOM` should be re-graded: the ledger's stated
residual — an add-to-cart `TypeError` from OPF's `wapf/validate` dispatch — does
not reproduce at `ff58b68`, and the live instance of the same defect
(`wapf/html/field_container_classes`) is now closed with a structural rule and
runtime proof. What remains genuinely unproven for the row is unchanged and
independent of this work: the row's own zoom lifecycle evidence is one-sided
against 3.1.5's authoring surface.
