# WAPF-FIELD-CHILD-PRODUCTS lane — evidence index

> **Artifact status (2026-10-05):** the JSON/HTML/PNG artifacts named below
> (`browser-results.json`, `wapf-reference.json`/`-markup.html`/`.png`,
> `render.png`, `swap.png`, `cart.png`, `wapf-reference.png`,
> `cart-after-add.json`, `cart-after-sync.json`, `cart-rows.json`,
> `order-again-cart.json`, `wapf-page.html`, `wapf-reference-markup.html`,
> `state.json`, `page.html`) were staged under `/tmp` and are no longer
> retrievable; they were never tracked in this repository. The claims rest on
> the surviving scripts and tests: `bin/e2e-child-products-lifecycle.php`,
> `bin/e2e-child-products-browser-test.mjs`, and the PHPUnit products tests
> cited below. The recorded counts and totals are the values observed at the
> time, not freshly verified.

Clone: `/tmp/opf-image-child-wp` @ `http://127.0.0.1:8301` (OPF symlinked to `/tmp/opf-lane-child`).
Fixture: `bin/e2e-child-products-lifecycle.php` (env-gated, phases setup/verify/order-again/cleanup).
Browser: `bin/e2e-child-products-browser-test.mjs` (Playwright, loopback-gated).
Latest builder-selection fix and save/reload evidence: `CHILD-PRODUCTS-BUILDER-ROUNDTRIP-2026-10-04.md` (real SelectWoo product search, authenticated builder edit/save/reload, WAPF 3.1.5 Tools parser comparison). This bounded admin result does not promote the broad parity row.

## Scenario under test

Parent $20 + children Alpha $8 (card, `fixed`, qty_method `parent`) and Beta $12
(card `none` → free line; card-qty `nr` → own price). Fields: `linked_cards`
(products/card, image_zoom) + `linked_qty` (products/card-qty, plus_min,
aggregate min/max → `relative` qty_type).

## Results

- PHPUnit: 398 tests / 1699 assertions / 0 failures (43 new products tests:
  schema 16 + lifecycle 27). 1 pre-existing suite-wide PHPUnit deprecation
  (PHP 8.5 + PHPUnit 11.5.56; fires on untouched tests too).
- Browser (`browser-results.json`): 26/26 checks.
  render 2+2 cards → gallery swap on select + restore on deselect → plus
  stepper → classic add-to-cart → 4 cart lines (parent + 3 children) →
  child prices $8/$0/$24, total $52 → `extensions.opf.childItem` on all 3
  children → block-cart `opf-child-item` class + hidden remove links +
  "Included with" → parent qty 1→3 sync (parent-method → 3; relative 2→6;
  total $156) → real wc-ajax checkout (order persisted) → orphan removal
  (parent delete removes child) → 0 page errors.
- PHP verify: 4 order lines; children carry `_opf_child` + `_opf_child_full`
  (parent cart key + field id); parent carries `_opf_fields` +
  `_opf_cart_item_key`; totals 60+24+0+72 = $156.
- Order-again (faithful `WC_Cart_Session::order_again` replay — raw cart
  array, `woocommerce_add_order_again_cart_item`, `woocommerce_ordered_again`
  before commit): 4 lines rebuilt, all children remapped to the new parent
  key, total $156. See `order-again-cart.json`.
- Cleanup: all created posts/products/attachments/order/group/checkout
  deleted; `woocommerce_checkout_page_id` (→7) and bacs settings restored;
  state option removed.

## WAPF reference probe (3.1.5, `wapf-reference.json`/`-markup.html`/`.png`)

- `products-checkbox` group built via WAPF's own `raw_json_to_field_group` +
  `Field_Groups::save`, targeted at the same parent product.
- Rendered: `wapf[field_linked][]` naming, `data-wapf-label`,
  `data-wapf-pricetype`, `data-wapf-price`, `data-zoom-url` attrs,
  `(+$8.00)`/`(+$0.00)` pricing hints. OPF emits the same `data-wapf-*`
  mirror attributes.
- `pageErrors: []`, `consoleErrors: []` → **no referenceJsErrors**: WAPF
  rendered cleanly on this clone; no license-gated JS failures observed in
  this path. WAPF was deactivated again afterwards; probe group deleted.
- Note: WAPF's gallery swap did not fire on plain check without the
  `image_zoom`/`layout.enable_gallery_images` option — OPF swaps when the
  field's `image_zoom` is enabled (verified in-browser).

## Screenshots / dumps

`render.png`, `swap.png`, `cart.png`, `wapf-reference.png`,
`cart-after-add.json`, `cart-after-sync.json`, `cart-rows.json`,
`order-again-cart.json`, `wapf-page.html`, `wapf-reference-markup.html`,
`state.json`, `page.html`.
