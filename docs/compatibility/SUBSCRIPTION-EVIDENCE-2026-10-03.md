# WAPF-PRODUCT-SUBSCRIPTION — evidence

Historical clone evidence. Public commit `ec935f2` added the adapter boot call
but omitted `SubscriptionIntegration.php`; its reported clone results did not
prove the published plugin could load. The class and fresh public-code proof
are restored in [2026-10-04 evidence](SUBSCRIPTION-BOOT-RESTORE-EVIDENCE-2026-10-04.md).
Licensed subscription parity remains partial.

Clone `http://127.0.0.1:8323` (`/tmp/opf-image-uploadui-wp`). Real
WooCommerce Subscriptions is commercial and **not installed**; proof runs
against a scoped mu-plugin stub that mirrors the product-type API WAPF's own
adapter touches.

## What the row note said was missing

Ledger line 283 ends with:

> Gap: fieldless renewal-style add blocked — no renewal-skip equivalent.

WAPF Extended 3.1.5
`includes/classes/integrations/class-woocommerce-subscriptions.php`:

- `wapf/pricing/cart_item_base` → `WC_Subscriptions_Product::get_price()` for
  `subscription`, `variable-subscription`, `subscription_variation`;
- `wapf/admin/allowed_product_types` adds the two subscription types;
- `wcs_before_early_renewal_setup_cart_subscription` /
  `wcs_before_renewal_setup_cart_subscriptions` set `skip_cart_validation`,
  the matching `wcs_after_*` hooks clear it, and
  `wapf/skip_cart_validation` returns that flag.

OPF had generic product/cart coverage but no adapter and no renewal-skip.

## Implementation

`includes/Service/SubscriptionIntegration.php` (booted from the plugin file):

- registers the four `wcs_*_renewal_setup_*` hooks to toggle a renewal flag;
- filters `opf_skip_validation` (the escape hatch already honored at
  `CartIntegration::validate_add_to_cart()` line 136) during renewal setup;
- filters `opf_cart_item_base_price` (applied at
  `CartIntegration::apply_prices()` line 421) to use
  `WC_Subscriptions_Product::get_price()` for the three subscription types,
  degrading to the stored base when the class is absent.

## Fixture / proof

`bin/e2e-subscription-stub.php` installs
`mu-plugins/zz-opf-sub-stub.php` defining:

- `subscription` / `variable-subscription` / `subscription_variation` product
  classes via `woocommerce_product_class` (with `is_type()` mirrors and
  `woocommerce_data_stores` routing so WooCommerce's variation store is used);
- `WC_Subscriptions_Product::get_price()` returning 42.5 for `subscription`
  and 100.0 for the variable/variation types (distinguishable from the
  product's own 30/20/200 catalog prices);
- the renewal setup/teardown hooks.

Server results (`server-results.json`, 15 checks, 0 failures):

- `subscription` and `variable-subscription` resolve through the WC factory;
- `product_type` placement matches the subscription, not a simple product;
- a fieldless add is **blocked** outside renewal (control);
- firing `wcs_before_renewal_setup_cart_subscriptions` flips
  `opf_skip_validation` to true and the fieldless renewal add **passes**;
  `wcs_after_*` clears it;
- a validated add lands in the cart priced **47.50** = recurring base `42.5`
  + fixed addon `5`;
- a `subscription_variation` lands priced **105.00** = variation recurring
  base `100` + `5`.

`cleanup.json` confirms products/variations/groups/orders and the mu-plugin
returned to baseline.

## Bounds (honest)

- The stub mirrors the *public product-type/renewal API surface*; it does not
  reproduce real Subscriptions renewal internals, period labels, or the
  product-level **sign-up fee** line (WCS adds that itself; WAPF's adapter
  only consumes the recurring price). Those remain unverified without a
  licensed Subscriptions install.
- Active-WAPF comparison was not run: the installed 3.1.5 fatals on this
  clone's pre-existing `wapf_product` rows. Parity is source-level
  (same hook names, same base filter, same skip semantics).
