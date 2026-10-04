# Child products: Store API coexistence and Order Again

## Result

With OPF and WAPF Extended 3.1.5 active together, a real WooCommerce Store API
cart and checkout created the expected parent plus four native child lines.
The order persisted the OPF child-to-parent mapping, child price modes, and
USD 92 total. WooCommerce's actual account **Order Again** link restored those
five lines, including both zero-price children. A refund of all four child
lines restored their inventory.

The lane found one bridge defect. OPF's `wapf/order_again/before_cart_item_field`
alias sends OPF's normalized field array to WAPF's native
`Linked_Products_Controller::prepare_order_again_cart_item()`, which reads
`$field->type`. WAPF therefore logged “Attempt to read property type on array”
during OPF order restoration. The bridge now skips only that native WAPF
callback during OPF array dispatch, restores it at the same priority/order,
and leaves third-party listeners receiving the OPF array. WAPF-native
Field-object dispatch still reaches the callback. OPF already restores its
own linked children from `_opf_child_full` and remaps them to the new parent
cart key.

## Source comparison

Read-only reference was the installed Extended 3.1.5 source in the isolated
clone:

- WAPF registers its linked-product order-item, refund, stock-restore, and
  order-again hooks in `includes/controllers/class-linked-products-controller.php`
  (lines 57–76). Its order-again field handler reads `$field->type` at line 92,
  then builds child cache data from the WAPF Field object. The parent-key remap
  runs on `woocommerce_ordered_again` at lines 124–134.
- WAPF stores a reduced `_wapf_child` value on child order items in
  `includes/controllers/class-product-controller.php` (lines 777–783), and
  restores the marker and old parent cart key in its order-again mapper
  (lines 915–928). Refund restock reads WAPF parent `_wapf_meta.fields[]`
  child-product data in `class-linked-products-controller.php` (lines 723–755).
- OPF stores `_opf_child` and the complete `_opf_child_full` relation on native
  child order lines in `includes/Service/LinkedProducts.php` (lines 898–908).
  It restores those fields and remaps the parent key on
  `woocommerce_ordered_again` (lines 914–944). WooCommerce's own order lines
  carry the child product IDs and quantities, so native stock/refund handling
  applies to these lines.

The tested child quantities were alpha 2 + 4 and beta 2 + 3. The priced lines
were USD 16 and USD 36; the other two child lines were USD 0. Parent line was
USD 40; checkout and Order Again both totaled USD 92.

## Verification

Disposable clone: WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite,
loopback `http://127.0.0.1:8423`. OPF and Advanced Product Fields Extended
**3.1.5** were both active for Store API add, checkout, order verification,
refund/restock, and the Order Again browser run.

- Store API: **7/7** checks — empty cart, add-item HTTP 201, five cart lines,
  four OPF child markers, USD 92, successful Store API checkout, order ID and
  order-received URL.
- Persisted order: **6/6** checks — five lines, USD 92, parent OPF fields and
  old cart key, all four full child mappings, and exact paid/free totals.
- Woo account setup: **3/3** checks — fixture order assigned to the test admin
  and completed so Woo exposes its real Order Again action.
- Refund/restock: **4/4** checks — alpha 50→44 and beta 50→45 under Woo stock
  reduction; refund created for all four child lines (USD 52); alpha and beta
  restored to 50. A separate WP-CLI process confirmed 50/50 persisted.
- Authenticated browser Order Again: **4/4** checks — real action link,
  restored parent plus four children, USD 92 and two free children, parent
  removal also clears dependent children. A fresh WP-CLI process afterward
  still read 50/50.
- The focused bridge suite passes **6 tests / 89 assertions**. Full PHPUnit
  passes **757 tests / 3,370 assertions**, with one existing PHPUnit metadata
  deprecation. PHP/JS syntax checks and `git diff --check` pass.

The WAPF Extended date extension still emits its separate known warning when
OPF's normalized array reaches `wapfe_validate_cart_data()` at
`extend/date.php:157`. It did not fail the Store API lifecycle and is outside
this linked-product Order Again adapter change. This proof covers only the
installed WAPF Extended 3.1.5 package.

## WooCommerce stock-state control

The products in these fixtures start with stock management disabled. The test
enables stock at 50 after checkout, then calls WooCommerce's stock reduction
function so it can exercise native child line refund/restock. The order must
already be in its final status before that artificial stock phase.

The earlier 44/45 observation came from changing an **on-hold** order to
**completed after its refund**. Reproduction on a disposable Store API order:

1. Set alpha/beta stock to 50 and enable stock management; call
   `wc_reduce_stock_levels( $order_id )`: 44/45.
2. Refund the child lines with `restock_items => true`: immediate and fresh
   process stock is 50/50, order remains on-hold, Woo's `stock_reduced` flag is
   false.
3. Change that order to completed: WooCommerce reduces the stock again to
   44/45 and sets `stock_reduced=true`.

That sequence is a fixture status transition after refund, not a regression in
child Order Again. In the accepted lifecycle above, the order was completed
before the stock/refund phase; actual Order Again left stock at 50/50.

## Reproduce

The commands below run only against the copied clone. The PHP fixture refuses
to run unless both plugins are active and the exact clone path matches.

```sh
OPF_CHILD_STORE_ORDER_ALLOW=1 OPF_CHILD_STORE_ORDER_PHASE=setup OPF_CHILD_STORE_ORDER_ARTIFACTS=/tmp/opf-child-store-api-order-evidence wp --path=/tmp/opf-child-store-api-order-wp eval-file bin/e2e-child-store-api-coactive-fixture.php
wp --path=/tmp/opf-child-store-api-order-wp server --host=127.0.0.1 --port=8423
OPF_CHILD_STORE_API_BASE_URL=http://127.0.0.1:8423 OPF_CHILD_STORE_API_ARTIFACTS=/tmp/opf-child-store-api-order-evidence OPF_PLAYWRIGHT_ROOT=/home/followersya-5hqi7/followersya.com node bin/e2e-child-store-api-coactive.mjs
OPF_CHILD_STORE_ORDER_ALLOW=1 OPF_CHILD_STORE_ORDER_PHASE=verify OPF_CHILD_STORE_ORDER_ARTIFACTS=/tmp/opf-child-store-api-order-evidence wp --path=/tmp/opf-child-store-api-order-wp eval-file bin/e2e-child-store-api-coactive-fixture.php
OPF_CHILD_STORE_ORDER_ALLOW=1 OPF_CHILD_STORE_ORDER_PHASE=prepare-order-again OPF_CHILD_STORE_ORDER_ARTIFACTS=/tmp/opf-child-store-api-order-evidence wp --path=/tmp/opf-child-store-api-order-wp eval-file bin/e2e-child-store-api-coactive-fixture.php
OPF_CHILD_STORE_ORDER_ALLOW=1 OPF_CHILD_STORE_ORDER_PHASE=stock-refund OPF_CHILD_STORE_ORDER_ARTIFACTS=/tmp/opf-child-store-api-order-evidence wp --path=/tmp/opf-child-store-api-order-wp eval-file bin/e2e-child-store-api-coactive-fixture.php
OPF_CHILD_STORE_ORDER_ALLOW=1 OPF_CHILD_STORE_ORDER_PHASE=stock-state OPF_CHILD_STORE_ORDER_ARTIFACTS=/tmp/opf-child-store-api-order-evidence wp --path=/tmp/opf-child-store-api-order-wp eval-file bin/e2e-child-store-api-coactive-fixture.php
OPF_CHILD_STORE_API_BASE_URL=http://127.0.0.1:8423 OPF_CHILD_STORE_API_ARTIFACTS=/tmp/opf-child-store-api-order-evidence OPF_CHILD_STORE_API_PASSWORD_FILE=/tmp/opf-child-store-api-order-wp-admin-password OPF_PLAYWRIGHT_ROOT=/home/followersya-5hqi7/followersya.com node bin/e2e-child-store-api-order-again.mjs
OPF_CHILD_STORE_ORDER_ALLOW=1 OPF_CHILD_STORE_ORDER_PHASE=stock-state OPF_CHILD_STORE_ORDER_ARTIFACTS=/tmp/opf-child-store-api-order-evidence wp --path=/tmp/opf-child-store-api-order-wp eval-file bin/e2e-child-store-api-coactive-fixture.php
OPF_CHILD_STORE_ORDER_ALLOW=1 OPF_CHILD_STORE_ORDER_PHASE=cleanup OPF_CHILD_STORE_ORDER_ARTIFACTS=/tmp/opf-child-store-api-order-evidence wp --path=/tmp/opf-child-store-api-order-wp eval-file bin/e2e-child-store-api-coactive-fixture.php
```

No production files/database, public push, or protected tab markup were used.
