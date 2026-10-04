# Linked child tax, currency and cart-edit evidence

Scope: `WAPF-FIELD-CHILD-PRODUCTS` tax/currency interactions and parent cart
quantity edits/removal. The broad child-products ledger row remains partial.

## WAPF Extended 3.1.5 source comparison

Read the installed, licensed reference at
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`
on 2026-10-04. Source was inspected read-only.

| WAPF source | Contract | OPF counterpart |
| --- | --- | --- |
| `includes/controllers/class-linked-products-controller.php:45-47,596-609` | Before totals, WAPF sets only `price_type=none` linked lines to zero; ordinary child products remain native WooCommerce product lines. | `LinkedProducts::apply_child_prices()` only sets `none` child lines to zero. Fixed child lines keep their native product price/tax behavior. |
| `includes/controllers/class-linked-products-controller.php:45,611-628` | Orphan linked lines are removed before totals when their parent cart key is absent. | `LinkedProducts::remove_orphaned_children()` performs the same parent-key check before totals. |
| `includes/controllers/class-linked-products-controller.php:73,308-350` | Parent quantity edits synchronize linked child quantities according to the stored child quantity mode. | `LinkedProducts::sync_child_quantities()` runs at `woocommerce_after_cart_item_quantity_update` and mirrors the parent mode. |
| `includes/classes/integrations/class-woocs.php:30-34,138-147,205-209` | The WOOCS integration prices linked choices from original shop-context product prices. Back-conversion occurs only for foreign currency when `woocs_is_multiple_allowed=1`. | OPF's `WoocsIntegration::original_product_price()` and `cart_base_price()` preserve original/base prices and the same back-conversion gate. Child lines then use WooCommerce product prices, which an active currency adapter converts. |

The installed WAPF source registers the same WooCommerce totals and quantity
hooks for its child-product lifecycle. This lane compares those source
contracts with OPF's disposable runtime; it did not activate WAPF alongside
OPF or claim a side-by-side storefront comparison.

## Disposable runtime

Environment: WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite clone
`/tmp/opf-image-child-wp`. OPF came from this worktree at public base
`2c054c852b64427166fc5f884f7c318660af5e11`. OPF was active; WAPF was not
active during this OPF behavior run. The clone was derived from an existing
disposable WooCommerce clone and already contained unrelated old fixtures;
this fixture uses the IDs it created and a distinct parent slug. Production
and the protected tabs were not used.

The test added a disposable US/CA standard tax rate of 10%, marked the parent
and selected child taxable, and used the existing fixture group to add parent
quantity 2 with one `qty_method=parent` child. For foreign currency it supplied
a small WOOCS-shaped API object and a `woocommerce_product_get_price` rate-2
filter. **This is an adapter-shape test double, not the commercial WOOCS/FOX
plugin.** The test sets `woocs_is_multiple_allowed=1`, matching the installed
WAPF back-conversion gate. All test-created tax rows are removed and touched
options/product tax statuses are restored in `finally`.

Executed from the repository root:

```sh
OPF_CHILD_LIFECYCLE_ALLOW=1 OPF_CHILD_LIFECYCLE_PHASE=setup \
  OPF_CHILD_ARTIFACT_DIR=/tmp/opf-child-commerce-evidence \
  wp --path=/tmp/opf-image-child-wp eval-file bin/e2e-child-products-lifecycle.php
wp --path=/tmp/opf-image-child-wp eval-file bin/e2e-child-commerce-edges.php
```

The cart proof passed:

| Currency | Parent line | Native child line | Subtotal | Tax | Total |
| --- | ---: | ---: | ---: | ---: | ---: |
| USD | 2 × $20 = $40 | 2 × $8 = $16 | $56.00 | $5.60 | $61.60 |
| EUR at rate 2 | 2 × €40 = €80 | 2 × €16 = €32 | €112.00 | €11.20 | €123.20 |

For each currency, editing parent quantity from 2 to 3 also changed the child
line quantity from 2 to 3; removing the parent then left zero cart lines after
totals recalculation. The measured line tax split was USD parent/child
`$4.00 / $1.60` and EUR parent/child `€8.00 / €3.20`. Thus the tested child
price was converted once by the currency-shaped filter and taxed once by
WooCommerce.

The isolated clone included an older colliding product slug, so the new
fixture's parent was assigned the unique slug `opf-linked-parent-edgeproof`
after setup. That URL detail is only needed if running the separate browser
fixture; the cart-edge PHP proof addresses created fixtures by stored IDs.

## Limits

This accepts only the tested standard-tax, tax-exclusive, USD/rate-2 child
line path and WooCommerce cart quantity/removal hooks. It does not establish
real WOOCS/FOX behavior, fixed foreign-currency prices, inclusive tax, reduced
or zero-rate child tax classes, variations, currency changes with a persisted
cart session, Store API checkout/order persistence, or a live WAPF-vs-OPF
browser comparison. Those broader child-products edges remain open; no ledger
promotion follows from this evidence.
