# Linked child products: query and Woo lifecycle audit (2026-10-04)

Baseline OPF commit: `9184c6a499884600cc9f587cab8e01e9a2d2c290` on
`feat/opf-archive-import`. Reference package: installed WAPF Extended 3.1.5.
This is a bounded audit of category result queries and the child cart/order
path; it does not close the broad `WAPF-FIELD-CHILD-PRODUCTS` contract.

## Source comparison

- WAPF `Woocommerce_Service::get_products_by_query()` requires an integer
  `query_id`, defaults to 10 results, clamps requested results to 1–50,
  accepts the configured sort, selects published simple products in the
  category, excludes the parent, and adds `stock_status=instock` when Woo's
  hide-out-of-stock setting is on: installed source lines 323–353.
- WAPF manual lookup uses published simple, variable, and variation products,
  excludes the parent and applies Woo's out-of-stock visibility setting:
  lines 357–370. The linked-products controller builds the child cart lines
  and persists their parent relationship at lines 642–690 and 698–719;
  cancellation/refund stock handling is at lines 723–840; Order again rebuilds
  and remaps the child parent key at lines 90–134.
- OPF category lookup implements the same defaults, 1–50 cap, sort mapping,
  product type, parent exclusion, and out-of-stock filter in
  `includes/Service/LinkedProducts.php` lines 168–187. Manual resolution uses
  the authored product IDs and the same published product types (lines
  141–159). OPF adds native child cart lines, validates stock, persists child
  order metadata, and restores/remaps child lines on Order again (lines
  634–695, 570–584, and 898–923).

## Disposable WooCommerce proof

A fresh SQLite clone was created from the nonproduction `opf-test/wordpress`
source at `/tmp/opf-image-childaudit-20261004` (`http://127.0.0.1:8462`), with
the installed Extended 3.1.5 source copied into the clone and OPF mounted from
the baseline worktree. A Woo category with 55 published simple children and
one parent product was created only in that clone. Calls to the installed
WAPF query method and OPF `products_by_query()` were compared against real
`wc_get_products()` results.

All 23 query checks passed:

- Default query: 10 results; same result IDs and order; parent excluded.
- Limit 1: one result, same ID/order, parent excluded.
- Limits 50 and 51: 50 results, same ID/order, parent excluded.
- Limit 500: clamped to 50, same ID/order, parent excluded.
- With `woocommerce_hide_out_of_stock_items=yes`: the out-of-stock product
  was excluded and the same 50 in-stock products were returned.

The existing linked-products browser lifecycle was then run in that clone
with OPF active and WAPF deactivated. All 26 browser checks passed: card and
quantity-card rendering, gallery swap/restore, quantity stepper, classic
add-to-cart, parent plus three child Store API lines and totals, child row
visibility/controls, parent quantity propagation, checkout and persisted
order, orphan removal, and no uncaught browser errors. Order persistence and
the Order again replay passed their PHP assertions: child metadata was
preserved, four order lines totaled $156, and the three children were remapped
to the restored parent key without duplication.

An isolated native Woo stock run passed 4 checks on that actual order: managed
stock decreased from 100 to 97 for the alpha child quantity 3; beta's two
child lines (quantities 3 and 6) decreased stock from 100 to 91; Woo's stock
restore path returned both products to 100 after cancellation.

## Harness finding and limits

The first add-to-cart attempt had WAPF and OPF both active in the comparison
clone. It exposed a separate coexistence failure: WAPF's
`Linked_Products_Controller::validate_cart()` requires its `Field` object, but
the OPF-to-WAPF validation bridge passed OPF's normalized array field. The PHP
fatal originated at WAPF controller line 455 through OPF `WapfHooks` and
`CartIntegration`; the browser timed out waiting for Woo's success notice.
After deactivating WAPF in the clone, the OPF-only browser run passed. No fix
was made here because coexistence repair is a separate integration slice.

The query and lifecycle comparison found no narrow OPF defect to patch.
`WAPF-FIELD-CHILD-PRODUCTS` remains **partial**: broader tax/currency/cart-edit,
variation and relative-quantity edges, Store API coverage, importer/exporter
behavior, and supported-version coverage still require proof. Existing
WAPF-vs-OPF field markup evidence is indexed in
`CHILD-PRODUCTS-EVIDENCE-2026-10-03.md`.

The clone's owned products, category, field group, attachments, order,
checkout page, and state option were removed; the checkout option was
restored to page 7 and the active plugin list restored to OPF, SQLite, and
WooCommerce. The clone's PHP server was stopped. Browser screenshots and
JSON results were retained locally under `/tmp/opf-child-products-audit-evidence`
for review; no production files or records were touched.

Source SHA-256 (installed WAPF): `class-woocommerce-service.php`
`d48ac7051e8a216b6022829773dbcd32de2eae3c5b46038fc82ebe92c456d9b3`;
`class-linked-products-controller.php`
`38293fc4c39eb58002945488454a0233a88e65c1abe5cfa09c010db9a3de2e2d`.
