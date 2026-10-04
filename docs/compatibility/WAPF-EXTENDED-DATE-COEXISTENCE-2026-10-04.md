# WAPF Extended date validation coexistence

## Finding

WAPF Extended 3.1.5 registers the global `wapfe_validate_cart_data` function on
`wapf/validate` (`extend/date.php:152`). The callback dereferences
`$field->type` and reads `$field->options`, because WAPF passes its Field
object. OPF's alias bridge sends its normalized field array on the same hook.
The date extension therefore emitted PHP warnings for OPF date fields.

OPF already performs date validation in `CartIntegration::validate_values()`
through `FieldValue::validate()`, including canonical calendar-date checks,
date bounds, disabled weekdays/dates, and cutoff rules supported by the OPF
schema. During an OPF field dispatch, `WapfHooks` now temporarily removes only
the exact global WAPF Extended date callback, then restores it in `finally` at
its original priority and position. Other `wapf/validate` listeners remain
active and WAPF's own object-based dispatch still reaches the date callback.

## Verification

- Regression first failed with two `Attempt to read property "type" on array`
  warnings, one each for a valid and impossible submitted date.
- Focused unit regression: **1 test, 9 assertions passed**. OPF's full cart
  validation accepted `2027-06-15`, rejected `2027-02-30`, captured no PHP
  warning, preserved third-party callback dispatch, and restored the WAPF
  callback in its same-priority position. A subsequent object-based hook
  dispatch invoked the WAPF callback.
- All `WapfHooksBridgeTest` cases: **7 tests, 98 assertions passed**.
- Runtime proof used the disposable `/tmp/opf-wapf-validation-wp` clone with
  WAPF Extended 3.1.5 active and its real `wapfe_validate_cart_data` callback
  registered. Calling OPF's cart validation accepted `2027-06-15`, rejected
  `2027-02-30`, produced `warnings: []`, and left the callback registered.
- Runtime stack: WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11.
- Temporary date product/group/state were removed; WAPF was restored to its
  original inactive state. Production was not touched.

Runtime command:

```sh
wp --path=/tmp/opf-wapf-validation-wp eval-file bin/e2e-wapfe-date-validation-fixture.php setup
wp --path=/tmp/opf-wapf-validation-wp plugin activate advanced-product-fields-for-woocommerce-extended
wp --path=/tmp/opf-wapf-validation-wp eval-file bin/e2e-wapfe-date-validation-fixture.php verify
wp --path=/tmp/opf-wapf-validation-wp eval-file bin/e2e-wapfe-date-validation-fixture.php cleanup
wp --path=/tmp/opf-wapf-validation-wp plugin deactivate advanced-product-fields-for-woocommerce-extended
```

The fixture refuses to run outside that disposable clone. The runtime check
exercises the production validation method directly; it does not claim a
browser or full storefront add-to-cart date-flow proof.
