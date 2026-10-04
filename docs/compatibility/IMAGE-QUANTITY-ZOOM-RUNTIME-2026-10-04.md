# Image quantity zoom: isolated runtime evidence

**Status: partial.** OPF's zoom configuration persists in the actual WordPress admin, and the served page shows a full-size preview on hover and keyboard focus. The feature does not alter the cart/order data or price when compared with an otherwise identical zoom-disabled product. WAPF's installed version boundary leaves full parity unproven, and enabling WAPF beside OPF exposes a separate cart-validation fatal.

## Tested source and runtime

- OPF source: public HEAD `bab69b59c2b27792342de911fabea0ce7243ec69`, including the image quantity import fix from `eee0d75`.
- Disposable WordPress clone: `/tmp/opf-image-quantity-zoom-wp-bab69b5`, SQLite database, loopback port 8246. It was created independently from the category lane clone.
- Runtime versions: WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, OPF 0.1.0, WAPF Extended 3.1.5.
- Admin save/reload and storefront hover/focus/mobile checks ran with OPF and WAPF active. WAPF was disabled only for the completed cart/checkout/order comparison because the both-active cart request fatals (details below).
- No production files, database, runtime, or caches were changed. WAPF production source was read only.

## Results

- WordPress admin login succeeded. The image quantity zoom checkbox started off, was enabled and saved through OPF's REST endpoint, and remained enabled after reloading the actual field-group editor. A second product's setting stayed disabled.
- Served product page emitted the full attachment URL in `data-zoom-url`; the preview started hidden, appeared on hover and when the quantity input had keyboard focus, and included the larger image with `alt=""` and `aria-hidden="true"`. The image choice itself retained `alt="Oak"`.
- At 390px, the image quantity control remained visible and the page had no horizontal overflow.
- With WAPF disabled, two products identical except for zoom setting each rendered the same quantity choice (submitted quantity 2), entered the cart, and went through classic checkout. Observed line totals were $22.50 each and cart/order totals $45.00. Zoom on/off prices were equal.
- The persisted order retained `Prints: Oak: 2`; `_opf_fields` and `_opf_fields_snapshot` also retained image quantity `oak: 2` for both lines. Order `15083` was deleted during cleanup.
- Cleanup removed both test products, both field groups, checkout page, temporary administrator and order; restored the prior checkout and BACS settings; and removed fixture state. The clone was left with only OPF, WooCommerce and SQLite active; WAPF Extended remained installed but inactive.

## WAPF 3.1.5 boundary

The installed plugin header declares 3.1.5. In its `includes/classes/class-config.php`, the `large_image` appearance control is present under `image-swatch` (lines 1035–1041), but absent from the `image-swatch-qty` appearance settings (beginning at line 1045). Its `includes/classes/class-html.php` does honor a manually serialized `field->options['large_image']` value and emits a full-size `data-zoom-url` (lines 613–639). Thus 3.1.5 can render the setting if data is present, but its field-group builder does not expose it for image quantity fields. The import path preserves such serialized source data.

The WAPF 3.2.1 image-quantity builder serialization contract was not independently verified against a 3.2.1 package. Keep the parity ledger row **partial** until that version's admin contract is checked. OPF's runtime/UI proof does not establish that WAPF 3.1.5 merchants can enable the feature in its builder.

## Separate coexistence failure

In the disposable clone only, with both OPF and WAPF Extended 3.1.5 active, filling the image quantity with `2` and submitting Add to cart returned HTTP 500. WAPF's `SW_WAPF_PRO\\Includes\\Controllers\\Linked_Products_Controller::validate_cart()` at `includes/controllers/class-linked-products-controller.php:455` declares argument 3 as `SW_WAPF_PRO\\Includes\\Models\\Field`; OPF's `CartIntegration::validate_values()` passes its normalized array field to `WapfHooks::validate_field()` (`includes/Service/CartIntegration.php:1348`, then `includes/Compat/WapfHooks.php:179`). The observed fatal is `TypeError: Argument #3 ($field) must be ... Field, array given`.

Reproduce on a fresh disposable clone with WAPF 3.1.5 and OPF active: open `/product/opf-iqz-on/`, set `[data-opf-field="prints"] input[type="number"]` to `2`, and click `form.cart button.single_add_to_cart_button`. This requires its own compatibility fix; no such fix was made in this zoom evidence commit.

## Repeatable test artifacts

- `bin/e2e-image-quantity-zoom-runtime-fixture.php`: clone-path-guarded setup, order verification, and cleanup phases.
- `bin/e2e-image-quantity-zoom-runtime-browser.mjs`: authenticated admin save/reload, served zoom, responsive/focus, cart, and checkout proof.

Successful full browser lifecycle command (with WAPF disabled after recording the coexistence failure):

```sh
OPF_IQZ_SKIP_ADMIN=1 OPF_IQZ_ADMIN_PASSWORD=x \
  NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
  node bin/e2e-image-quantity-zoom-runtime-browser.mjs
```

The real admin save/reload was run separately with WAPF active and passed before its subsequent add-to-cart request hit the documented fatal. Server-side order and cleanup verification used the fixture's `verify` and `cleanup` phases.
