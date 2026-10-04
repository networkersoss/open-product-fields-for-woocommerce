# WAPF validation hook coexistence

**Status: fixed and verified for the installed WAPF Extended 3.1.5 runtime.**

## Reproduction

The disposable WordPress clone used WordPress 7.1.2, WooCommerce 11.1.0,
PHP 8.5.11, OPF public branch feat/opf-archive-import at 119b1ba, and WAPF
Extended 3.1.5. Both plugins were active.

A real classic product form POST with an OPF required text field and image
quantity field returned HTTP 500. The fatal was:

    TypeError: SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller::validate_cart():
    Argument #3 ($field) must be of type SW_WAPF_PRO\Includes\Models\Field, array given

WAPF registers that typed method on wapf/validate. OPF's validation bridge
uses the same hook to preserve WAPF-named extension callbacks, but its field
argument is OPF's normalized array. The fatal occurred at
CartIntegration::validate_values() → WapfHooks::validate_field() →
apply_filters('wapf/validate', ...).

## Compatibility boundary

During an OPF field dispatch, the bridge temporarily removes only callbacks
whose receiver is WAPF's native
SW_WAPF_PRO\Includes\Controllers\Linked_Products_Controller and whose method
is validate_cart. OPF continues its own linked-product validation. All other
wapf/validate callbacks still receive the same eight WAPF-shaped arguments,
including the normalized OPF array at argument 3. The native callback is
restored in finally at its original priority and order, including when
another listener throws. WAPF's own validation path still sees the callback
with its native Field object.

## Verification

- Focused bridge tests: **5 tests, 83 assertions passed**. They check that a
  third-party listener sees the OPF array and can return its validation error,
  the typed WAPF callback does not receive that array, the callback is restored
  in its original order, WAPF-native Field dispatch still reaches it, and
  restoration occurs after an exception.
- Full PHP suite: **708 tests, 2,932 assertions passed**, with one existing
  PHPUnit deprecation.
- With WAPF 3.1.5 active, a real classic product form POST returned HTTP 200;
  the WooCommerce Store API cart contained the fixture product, then the
  browser emptied its isolated cart. There were zero failed browser requests.
- With WAPF deactivated, a real classic form POST with the OPF required field
  omitted was rejected by OPF and left the Store API cart empty.

Repeatable fixture commands, run only against the path-guarded disposable
clone:

    wp --path=/tmp/opf-wapf-validation-wp eval-file bin/e2e-wapf-validation-coexistence-fixture.php setup
    wp --path=/tmp/opf-wapf-validation-wp server --host=127.0.0.1 --port=8249
    NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules OPF_WAPF_COEX_BASE_URL=http://127.0.0.1:8249 OPF_WAPF_COEX_MODE=active node bin/e2e-wapf-validation-coexistence-browser.mjs
    NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules OPF_WAPF_COEX_BASE_URL=http://127.0.0.1:8249 OPF_WAPF_COEX_MODE=opf-only node bin/e2e-wapf-validation-coexistence-browser.mjs
    wp --path=/tmp/opf-wapf-validation-wp eval-file bin/e2e-wapf-validation-coexistence-fixture.php cleanup

Activate WAPF before the active browser run and deactivate it before the
opf-only run. Stop the local PHP server after both checks.

## Cleanup and limits

Fixture product and field group were deleted, the fixture state file and
temporary trace MU plugin were removed, the WAPF activation state was restored
to inactive, the local server was stopped, and the eight guest-session cart
entries from the first exploratory run were cleared of the fixture product.
No production files or database were touched.

This proves coexistence with the installed 3.1.5 package only. The clone also
logged warnings from WAPF's date extension reading an OPF array as an object
and from its field-group deserializer; neither prevented the verified cart
request. The date-extension warning is fixed and verified separately in
[WAPF Extended date validation coexistence](WAPF-EXTENDED-DATE-COEXISTENCE-2026-10-04.md).
The field-group deserializer warning remains a separate follow-up.
