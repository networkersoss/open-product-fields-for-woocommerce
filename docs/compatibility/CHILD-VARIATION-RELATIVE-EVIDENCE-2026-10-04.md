# Linked child variations and relative quantity evidence (2026-10-04)

## Scope and result

WAPF Extended 3.1.5 permits a manually selected variable-product variation as a
linked child: its admin variation search returns `product_variation` posts
(`class-admin-controller.php:959-967`, `class-woocommerce-service.php:181-220`),
and the storefront choice lookup includes `simple`, `variable`, and `variation`
(`class-woocommerce-service.php:357-385`). Its cart handler passes the
variation's parent product ID and the
variation ID separately to `WC()->cart->add_to_cart()`;
`includes/controllers/class-linked-products-controller.php:661-680`. OPF uses
the same split in `includes/Service/LinkedProducts.php:671-686`. A real
Chromium/WooCommerce Store API run confirmed the child line keeps the selected
variation ID, `attribute_color=red`, and parent-scaled quantity with both OPF
and WAPF Extended active.

For quantity-selector child fields with a minimum or maximum selection,
WAPF assigns quantity type `relative` at
`class-linked-products-controller.php:352-365`. On parent quantity increases,
WAPF adds `child_quantity * parent_quantity_difference`; on decreases, it
multiplies by `new_parent_quantity / old_parent_quantity`
(`:336-340`). OPF's `sync_child_quantities()` follows those same calculations
at `LinkedProducts.php:763-773`. Runtime matched the source over successive
changes: parent `2→3→4→2` produced child `2→4→8→4`.

The increase rule compounds when the parent starts above quantity 1: `3→4`
doubles child quantity `4→8`, rather than scaling it by `4/3`. This is also
WAPF 3.1.5 behavior. This audit records parity and does not change it; whether
that behavior is intentional remains an open product question. The broader
child-products capability remains **partial**.

## Runtime evidence

Disposable clone only: WooCommerce 11.1.0, WAPF Extended 3.1.5, OPF from the
isolated feature branch. Both plugins were active during the browser run. The
fixture created a simple parent product, a variable child with a `red`
variation, and a quantity-selector child. Chromium added the variation child,
checked its Store API variation attribute and quantity, then changed the parent
quantity `2→3→4→2` and checked the relative child's quantity after each update.
All 9/9 checks passed; no browser page errors. The local PHP log also contained
WAPF `unserialize()` warnings at `class-field-groups.php:784` for pre-existing
clone data; they did not prevent these cart requests. Production was not used.

Reproduce with a disposable clone whose WordPress path starts with
`/tmp/opf-image-child-wp` and whose public site port is 8463:

```sh
OPF_CHILD_VARIATION_ALLOW=1 OPF_CHILD_VARIATION_PHASE=setup \
  OPF_CHILD_VARIATION_ARTIFACT_DIR=/tmp/opf-child-variation-relative-evidence \
  wp --path=/tmp/opf-image-child-wp-relative eval-file bin/e2e-child-variation-relative-fixture.php
OPF_CHILD_VARIATION_BASE_URL=http://127.0.0.1:8463 \
  OPF_CHILD_VARIATION_ARTIFACT_DIR=/tmp/opf-child-variation-relative-evidence \
  node bin/e2e-child-variation-relative-browser-test.mjs
OPF_CHILD_VARIATION_ALLOW=1 OPF_CHILD_VARIATION_PHASE=cleanup \
  OPF_CHILD_VARIATION_ARTIFACT_DIR=/tmp/opf-child-variation-relative-evidence \
  wp --path=/tmp/opf-image-child-wp-relative eval-file bin/e2e-child-variation-relative-fixture.php
```

Fixture setup and browser-result JSON are disposable artifacts under `/tmp`.
The fixture cleanup deletes its four products and field group and clears its
state option. It does not alter site settings.
