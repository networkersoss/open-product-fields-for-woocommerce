# Subscription adapter restoration and real plugin boot

Public base: `0a5d385`. Fresh disposable clone: `/tmp/opf-category-price-wp`.
Original `opf-test` and production were not modified. Runtime: PHP 8.5.11,
WordPress 7.1.2, WooCommerce 11.1.0. The clone has a new SQLite database,
outbound HTTP/email blocked, and OPF linked to the isolated worktree.

## Failure and repair

`ec935f2` added `use OPF\Service\SubscriptionIntegration` and the unconditional
`SubscriptionIntegration::init()` call without committing its class. Loading
WordPress with OPF and Woo active failed at plugin entrypoint line 148:
`Class "OPF\Service\SubscriptionIntegration" not found`.

The restored adapter implements the behavior explicitly described in the
repository's subscription evidence and fixture, rather than a placeholder:

- Consume `WC_Subscriptions_Product::get_price()` for `subscription`,
  `variable-subscription` and `subscription_variation` before currency filters.
  Preserve the supplied base for ordinary products, an absent API, or a
  nonnumeric/nonfinite result.
- Scope required-field validation bypass to the four WCS early/normal renewal
  setup hooks. Nested setup remains exempt until the outer setup finishes;
  teardown cannot underflow or leave an exemption enabled.
- Preserve exemptions already granted by other integrations.

Reference source: installed Extended 3.1.5
`includes/classes/integrations/class-woocommerce-subscriptions.php` lines
14–24 (registered hooks), 27–38 (renewal flag), 41–45 (recurring base).
No commercial source code is included in OPF.

## Executed regression

```sh
OPF_BOOT_E2E_ALLOW=1 wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-plugin-boot.php

OPF_SUB_E2E_ALLOW=1 OPF_SUB_E2E_PHASE=setup OPF_SUB_ARTIFACT_DIR=/tmp/opf-subscription-restore-evidence wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-subscription-stub.php
OPF_SUB_E2E_ALLOW=1 OPF_SUB_E2E_PHASE=assert OPF_SUB_ARTIFACT_DIR=/tmp/opf-subscription-restore-evidence wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-subscription-stub.php
OPF_SUB_E2E_ALLOW=1 OPF_SUB_E2E_PHASE=cleanup OPF_SUB_ARTIFACT_DIR=/tmp/opf-subscription-restore-evidence wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-subscription-stub.php
```

Actual full WordPress/plugin boot: **13/13 checks**. The fixture can execute
only after `plugins_loaded` completes, so the previous unresolved boot call
fails before this regression can report success. It checks registered hooks,
ordinary price/validation preservation, absent-API fallback, early and nested
renewal setup, teardown, and extra teardown.

Scoped subscription API contract in real Woo cart: **16/16 checks**. Required
fields reject a normal fieldless add, renewal setup allows it, teardown clears
the exemption, and recurring base plus addon is 47.50 for simple subscriptions
and 105.00 for a subscription variation. Repeated totals preserve both prices.
The previous unconditional "source-equivalent" passing assertion was removed;
source comparison is evidence above, not an executed runtime assertion.

Cleanup restored baseline counts for products, variations, groups, orders and
the scoped mu-plugin. After cleanup, boot regression passed with the commercial
Subscriptions API absent. Raw contract/cleanup results remain in
`/tmp/opf-subscription-restore-evidence`.

## Remaining capability gap

The commercial WooCommerce Subscriptions plugin is not installed in this
clone. Its actual renewal internals, checkout/order-again/refund lifecycle,
sign-up-fee behavior and browser variation/period presentation are unproved.
`WAPF-PRODUCT-SUBSCRIPTION` stays **partial**. This fix proves published OPF
can boot and its restored adapter contract works; it does not prove full
licensed integration parity.
