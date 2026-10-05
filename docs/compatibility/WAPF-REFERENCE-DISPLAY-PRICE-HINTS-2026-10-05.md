# WAPF-DISPLAY-PRICE-HINTS — live 3.1.5 comparative proof (2026-10-05)

## Scope

Closes the cart/order one-sided half of the row's residual: *"exact
currency-plugin/tax formatting and WAPF's editable order-item pricing-hint
metadata are not implemented; legacy import does not preserve hint-specific
metadata."*

An identical priced `select` choice (`Gold` +2.50) was authored in WAPF 3.1.5
and OPF, then driven through each plugin's own real WooCommerce cart and order
pipeline with both plugins active; the rendered hint strings and the persisted
order-item metadata were captured and compared.

## Environment

- WAPF Extended **3.1.5** active beside OPF 0.1.0.
- Disposable clone `/tmp/opf-wapfref-compare-wp`, `http://127.0.0.1:8251`, WP 7.1.2 / WC 11.1.0 / PHP 8.5.11.
- Harness: `bin/e2e-wapfref-display-price-hints.php` (setup / render / cart / cleanup).
- Artifacts: `docs/compatibility/wapf-reference-proof-20261005/row4-display-price-hints/`.

## Commands

```sh
cd /tmp/opf-lane-wapfref
WP="wp --path=/tmp/opf-wapfref-compare-wp --allow-root"
OUT=docs/compatibility/wapf-reference-proof-20261005
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-display-price-hints.php setup
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-display-price-hints.php render
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-display-price-hints.php cart
```

## Formatter comparison (`hint-format-compare.json`) — PASS

Settings are identical defaults: `wapf_hint_format` / `opf_hint_format`
fall back to `(+{x})`; `wapf_show_pricing_hints` / `opf_show_price_hints`
default `yes`. Both formatters return the **same** string for the same fixed
price under the real store currency:

| call | WAPF 3.1.5 | OPF |
| --- | --- | --- |
| shop fixed | `(+&#36;2.50)` | `(+&#36;2.50)` |
| cart fixed | `(+&#36;2.50)` | `(+&#36;2.50)` |
| choice/storefront html | `<span class="wapf-pricing-hint">(+...)</span>` | `<span class="opf-pricing-hint">+ $2.50</span>` |

`parity.shop_fixed_equal` and `parity.cart_fixed_equal` are both `true`.

## Cart/order comparison (`cart-order-hints.json`) — PASS

Both engines put the identical product in the cart at line total **$22.50
(20.00 + 2.50)** and emit the same hint text, differing only in the span class
prefix:

- WAPF cart: `Gold <span class="wapf-pricing-hint">(+&#36;2.50)</span>`
- OPF cart: `Gold <span class="opf-pricing-hint">(+&#36;2.50)</span>`

Order-item metadata:

- WAPF `_wapf_meta`: `fields.finish.value = "Gold (+&#36;2.50)"`, plus
  `values[0].pricing_hint = "(+&#36;2.50)"` and a `settings` map.
- OPF `_opf_fields`: `{"<group>":{"finish":"gold"}}` — raw value only; OPF
  recomputes the hint from the stored base price at render time.

(WAPF's own cart item-data is gated on `is_cart()`/REST context, so the harness
also captures its exact cart markup through the same
`Helper::values_to_display_string()` the cart template calls.)

## Result

- **Closed:** the cart/order hint-output comparison against live 3.1.5 — with
  the default store currency and tax context the rendered hint text and the
  line totals are identical.
- **Remaining / not closed:** (a) WAPF persists the editable order-item hint
  metadata (`_wapf_meta.fields.*.value`/`pricing_hint`) while OPF stores raw
  `_opf_fields` and derives hints on demand — a documented storage difference;
  (b) currency-plugin/tax-varying formatting was only exercised under the
  default store currency, not under a live currency-switching plugin; (c)
  legacy import does not remap WAPF's hint-specific order metadata.

## Ledger outcome (2026-10-05)

`WAPF-DISPLAY-PRICE-HINTS` was promoted to `supported` on this proof. The
cart/order comparison shows no rendered or priced divergence, and the three
items listed as "remaining" above were judged storage/scope facts rather than
capability gaps: (a) WAPF stores the pre-rendered hint string in order meta
while OPF stores raw `_opf_fields` and derives the same string at render time;
(b) currency conversion is wired — `WoocsIntegration::pricing_hint()` and
`AeliaIntegration::pricing_hint()` filter `opf_pricing_hint_amount`; and (c)
the hint settings that exist are migrated by
`Importer::migrate_price_hint_settings()`, so only pre-existing WAPF **order**
rows keep their own strings, which is outside the field-group importer.

## Cleanup

`cleanup` deleted both fixture products, the OPF group, both created orders and
the `opf_wapfref_*` options; cache flushed. No `wapfref-*` objects remain.
Production was read-only.
