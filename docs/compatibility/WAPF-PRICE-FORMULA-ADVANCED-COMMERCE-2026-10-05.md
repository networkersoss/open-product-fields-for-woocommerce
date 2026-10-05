# WAPF-PRICE-FORMULA-ADVANCED — live-currency + gateway commerce evidence (2026-10-05)

Lane: `WAPF-PRICE-FORMULA-ADVANCED` residual closure. This is an execution
proof run in a disposable SQLite clone. It is not a public parity claim.

## Verdict

The row's two named residuals are **closed** on the evidence below:

- **(a) formula-priced line under a live currency plugin across tax classes** —
  a CURCY 2.4.3 store with base USD and EUR at rate 1.5, three tax classes
  (standard 10 %, reduced-rate 5 %, zero-rate 0 %), six formula families, run
  product page → add to cart → cart → checkout → order. OPF line totals,
  converted amounts and per-line tax match WAPF Extended 3.1.5 exactly
  (6/6 families, 6/6 units, 6/6 line taxes, 0.00 difference).
- **(b) payment-gateway checkout through to order persistence** — real classic
  `?wc-ajax=checkout` through built-in gateways (OPF: COD; WAPF: cheque), order
  persisted in EUR with field metadata, partial refund, and real order-again.

Two findings that are **not** part of the named residuals are recorded under
[Findings outside the two residuals](#findings-outside-the-two-residuals); they
did not block the two closures and were not fixed (this lane edits only
`docs/` and `bin/`).

## Clone and versions

- Clone path: `/tmp/opf-lane-formulaadv-wp` (SQLite drop-in, loopback
  `http://127.0.0.1:8291`, PHP built-in server + `router.php`). Disposable; the
  parent repo checkout is copied in as the OPF plugin.
- WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11.
- OPF 0.1.0 — `open-product-fields-for-woocommerce.php` sha256
  `81a6c87fa238eb38a13a6c1f17b59937909ae355a4affef9118cf8bc376dc799` (identical
  to the lane repo checkout).
- WAPF Extended 3.1.5 (the installed reference) —
  `advanced-product-fields-for-woocommerce-extended.php` sha256
  `4ff8860193e739b86688dc2ca9414c614135350512dba5aa86d24005378f0f3c`.
- CURCY (WooCommerce Multi Currency, VillaTheme) 2.4.3 —
  `woocommerce-multi-currency.php` sha256
  `1788243d4135b9fec55b17d0ca0a9c81c12445314e63c74b44624560988aad61`, copied
  from the host plugin directory into the clone only.

## Configuration

CURCY (`woo_multi_currency_params`, full snapshot in `curcy-settings.json`):
`enable=1`, `enable_multi_payment=1`, `currency=["USD","EUR"]`,
`currency_rate=["1","1.5"]`, `currency_default="USD"`,
`checkout_currency="USD"`, `checkout_currency_args=["USD","EUR"]`,
`currency_decimals=[2,2]`. EUR was selected with the real switcher URL
`?wmc-currency=EUR` (sets the `wmc_current_currency` cookie).

`checkout_currency_args` must include EUR: CURCY's default
`checkout_currency="USD"` + empty args forces checkout back to the base
currency (`frontend/checkout.php::woocommerce_checkout_process` sends a failure
response), which is the plugin's intended "check out in store currency"
behaviour, not a formula issue. Allowing EUR keeps the whole checkout in EUR.

Tax (`woocommerce_calc_taxes=yes`, `woocommerce_prices_include_tax=no`,
display excl.): rate id 3 `VAT 10` class `standard` (10 %), rate id 4 `VAT 5`
class `reduced-rate` (5 %), class `zero-rate` (0 %, no rate). Classes
`Reduced rate` / `Zero rate` are the WooCommerce defaults.

Six disposable simple products, base price USD 10.00, qty 1, one formula family
each, with the formula on a required `fee` select choice (`pricing_type=fx`,
`per_unit`), matching the ledger's proven families:

| id | formula family | formula | tax class |
| --- | --- | --- | --- |
| arith | arithmetic | `round(sqrt(pow([field.x];2)))*[qty]` (x=2) | standard 10 % |
| minmax | min/max | `max(3;min([field.x];7))*[qty]` (x=5) | reduced-rate 5 % |
| len | len | `len([field.t];true)*[qty]` (t=`"  a b "`) | zero-rate 0 % |
| date | date | `dow([field.d])*7*[qty]` (d=2026-10-03, Saturday) | standard 10 % |
| sumqty | sumQty | `sumQty(prints)*[qty]` (oak=2, ash=3) | reduced-rate 5 % |
| customvar | custom variables | `[var_rate]*[qty]` (`rate` default 4) | zero-rate 0 % |

Identical definitions were written for both engines: OPF `opf_field_group` posts
with a product rule, and WAPF `_wapf_fieldgroup` meta (WAPF type
`image-swatch-qty` for `sumqty`, `variables:[{name:rate,default:"4",rules:[]}]`
for `customvar`). Quantity 1 keeps the known `[qty]`-semantics divergence out of
this comparison.

## Commands

Clone build (parent repo is copied in; CURCY is copied into the clone only):

```sh
cp -a /tmp/opf-lane-coexistcss-wp /tmp/opf-lane-formulaadv-wp
rm -f /tmp/opf-lane-formulaadv-wp/wp-content/database/.ht.sqlite
rm -rf /tmp/opf-lane-formulaadv-wp/wp-content/plugins/open-product-fields-for-woocommerce
cp -a /tmp/opf-lane-formulaadv /tmp/opf-lane-formulaadv-wp/wp-content/plugins/open-product-fields-for-woocommerce
cp -a /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/woocommerce-multi-currency /tmp/opf-lane-formulaadv-wp/wp-content/plugins/
cd /tmp/opf-lane-formulaadv-wp
wp core install --url=http://127.0.0.1:8291 --title="FormulaAdv Lane" --admin_user=laneadmin --admin_password=laneadmin123 --admin_email=lane@example.invalid --skip-email
wp plugin activate woocommerce woocommerce-multi-currency open-product-fields-for-woocommerce advanced-product-fields-for-woocommerce-extended
wp rewrite structure '/%postname%/'
(php -S 127.0.0.1:8291 router.php > server.log 2>&1 &)
```

Fixture setup (products, groups, WAPF reference meta, tax rates, CURCY config,
customer, classic cart/checkout pages):

```sh
cd /tmp/opf-lane-formulaadv-wp
OPF_FORMULAADV_ALLOW=1 OPF_FORMULAADV_MODE=setup \
  OPF_FORMULAADV_OUT=/tmp/opf-lane-formulaadv/docs/compatibility/formula-advanced-commerce-20261005 \
  wp --allow-root eval-file /tmp/opf-lane-formulaadv/bin/e2e-formulaadv-fixture.php
```

Lifecycle (real Chromium; OPF and WAPF on the same products, one provider
active at a time via `active_plugins`):

```sh
cd /tmp/opf-lane-formulaadv
OPF_FORMULAADV_ALLOW=1 \
  OPF_FORMULAADV_OUT=/tmp/opf-lane-formulaadv/docs/compatibility/formula-advanced-commerce-20261005 \
  node bin/e2e-formulaadv-browser.mjs
```

General-price-path probe (class/filter presence with OPF + CURCY active). The
run used:

```sh
cd /tmp/opf-lane-formulaadv-wp
wp --allow-root eval '
$p = WP_PLUGIN_DIR . "/woocommerce-multi-currency/";
echo wp_json_encode([
 "active_plugins" => get_option("active_plugins"),
 "opf_active" => class_exists("OPF\\Service\\CartIntegration"),
 "curcy_active" => class_exists("WOOMULTI_CURRENCY_Frontend_Price"),
 "woocs_class_present" => class_exists("WOOCS"),
 "aelia_class_present" => class_exists("WC_Aelia_CurrencySwitcher"),
 "curcy_wapf_pro_integration_file" => file_exists($p . "plugins/advanced_product_fields_for_woocommerce_pro.php"),
 "curcy_opf_integration_file" => file_exists($p . "plugins/open-product-fields-for-woocommerce.php"),
 "curcy_registers_product_get_price" => (bool) preg_match("/woocommerce_product_get_price/", (string) file_get_contents($p . "frontend/price.php")),
 "curcy_converter" => function_exists("wmc_get_price"),
 "curcy_reverter" => function_exists("wmc_revert_price"),
], JSON_PRETTY_PRINT);' \
  > /tmp/opf-lane-formulaadv/docs/compatibility/formula-advanced-commerce-20261005/curcy-general-price-path-probe.json
```

Cleanup, validation:

```sh
cd /tmp/opf-lane-formulaadv-wp
OPF_FORMULAADV_ALLOW=1 OPF_FORMULAADV_MODE=cleanup \
  OPF_FORMULAADV_OUT=/tmp/opf-lane-formulaadv/docs/compatibility/formula-advanced-commerce-20261005 \
  wp --allow-root eval-file /tmp/opf-lane-formulaadv/bin/e2e-formulaadv-fixture.php
pkill -f 'php -S 127.0.0.1:8291'
cd /tmp/opf-lane-formulaadv && vendor/bin/phpunit 2>&1 | tail -6
cd /tmp/opf-lane-formulaadv && node --test tests/js/*.test.cjs 2>&1 | tail -8
```

## Results — OPF vs WAPF Extended 3.1.5 (same formulas, same products, same EUR)

Each engine rendered the storefront, submitted the field values, added all six
lines to one cart, checked out through a built-in gateway, and the order was
persisted. `unit EUR` is the converted line unit price (`(10 + addon) × 1.5`).
`addon` is the formula result recovered as `unit / 1.5 − 10`.

| family | formula | tax class | addon OPF/WAPF | unit EUR OPF/WAPF | line tax OPF/WAPF | match |
| --- | --- | --- | --- | --- | --- | --- |
| arith | `round(sqrt(pow([field.x];2)))*[qty]` | standard 10 % | 2 / 2 | 18.00 / 18.00 | 1.80 / 1.80 | yes |
| minmax | `max(3;min([field.x];7))*[qty]` | reduced-rate 5 % | 5 / 5 | 22.50 / 22.50 | 1.13 / 1.13 | yes |
| len | `len([field.t];true)*[qty]` | zero-rate 0 % | 2 / 2 | 18.00 / 18.00 | 0 / 0 | yes |
| date | `dow([field.d])*7*[qty]` | standard 10 % | 42 / 42 | 78.00 / 78.00 | 7.80 / 7.80 | yes |
| sumqty | `sumQty(prints)*[qty]` | reduced-rate 5 % | 5 / 5 | 22.50 / 22.50 | 1.13 / 1.13 | yes |
| customvar | `[var_rate]*[qty]` | zero-rate 0 % | 4 / 4 | 21.00 / 21.00 | 0 / 0 | yes |

Machine-readable side-by-side: `opf-vs-wapf-numbers.json` (`all_match: true`).

Order totals (both engines):

| engine | order | currency | payment | status | subtotal | tax | total |
| --- | --- | --- | --- | --- | --- | --- | --- |
| OPF | 61 | EUR | `cod` | processing | 180.00 | 11.86 | 191.86 |
| WAPF 3.1.5 | 63 | EUR | `cheque` | on-hold | 180.00 | 11.86 | 191.86 |

Expected order total is `(180.00 base) × 1.5 + tax`. Tax is computed by
WooCommerce on the converted line price: 10 % of 96.00 = 9.60 and 5 % of 45.00
= 2.25 → 11.85 at full precision, 11.86 after WooCommerce per-line rounding
(22.50 × 5 % = 1.125 rounds to 1.13). Both engines round identically.

## Gateway checkout, order persistence, refund, order-again

All checks below are `true` for both providers in
`browser-lifecycle-results.json` (`all_units_ok`, `all_tax_ok`, `all_meta_ok`,
`all_addons_ok`, `order_total_ok`, `order_currency_ok`, `cart_total_ok`,
`refund_ok`, `order_again_ok`):

- Checkout ran the real classic AJAX endpoint (`?wc-ajax=checkout`,
  `checkout_result: "success"`) and reached order creation. Gateway status is
  the gateway's own: COD → `processing`, cheque → `on-hold`. Order currency
  persisted as EUR.
- Per-line persisted metadata: OPF order 61 line items carry `_opf_fields`
  (e.g. `{"30":{"x":"2","fee":"a"}}`); WAPF order 63 line items carry
  `_wapf_meta` (v3.1.5 payload with `calc_price`). Full payloads are in
  `browser-lifecycle-results.json` (`field_meta_payload`).
- Partial refund via `wc_create_refund(amount=2.00)`: OPF refund 62, WAPF
  refund 64; both orders keep total 191.86 and `refunded=2.00`.
- Order-again through the real my-account "Order again" link (COD/cheque leaves
  the order non-completed, so the merchant completed it first, which is the
  WooCommerce condition for offering order-again). Both providers restored all
  six lines to the same EUR units (1800, 2250, 1800, 7800, 2250, 2100) and cart
  total 191.86 — identical to the original order.

## CURCY exercises OPF's general Woo price path (not WOOCS/Aelia)

`curcy-general-price-path-probe.json` (OPF + CURCY active, WAPF inactive):
`opf_active=true`, `curcy_active=true`, `woocs_class_present=false`,
`aelia_class_present=false`, `curcy_opf_integration_file=false`,
`curcy_wapf_pro_integration_file=true`, `curcy_registers_product_get_price=true`.

- CURCY ships a dedicated WAPF Pro integration
  (`plugins/advanced_product_fields_for_woocommerce_pro.php`, keyed on
  `class_exists('\SW_WAPF_PRO\WAPF')`) but **no OPF integration**. OPF is
  therefore converted only through CURCY's general Woo path:
  `woocommerce_product_get_price` → `wmc_get_price()` in `frontend/price.php`.
- OPF's `CartIntegration` captures the catalog base with
  `$product->get_price('edit')` (edit context bypasses CURCY's `view` filter, so
  the base stays USD 10), adds the base-currency addon, and calls
  `$product->set_price(base + addon)`. CURCY's `view` filter then converts that
  single value once. Runtime confirmation: the Store API product price for the
  USD-10 product is `1500` minor units / `EUR` (`curcy_price_path` in the
  results), and the cart unit is `(10 + addon) × 1.5` with no double conversion.
- **Caveat:** this proves OPF's general Woo price path against a real third-party
  currency plugin. It does **not** exercise OPF's `WoocsIntegration` or
  `AeliaIntegration` adapters (`class_exists('WOOCS')` and
  `class_exists('WC_Aelia_CurrencySwitcher')` are both false here). CURCY results
  must not be used to claim WOOCS/Aelia coverage; those rows keep their own
  fake-API evidence.

## Findings outside the two residuals

1. **Storefront preview does not convert the addon (both engines, parity).**
   Under CURCY the preview shows the converted product total but the addon in
   base currency: OPF `Product total €15.00 / Options total €2.00 / Grand total
   €17.00` and WAPF the same numbers. The cart/order are correct at €18.00. Both
   engines behave identically, so this is a CURCY preview-integration limitation,
   not an OPF-vs-WAPF gap. It is display-only and outside the two named
   residuals.
2. **Cart/checkout pricing hint conversion differs (OPF base, WAPF converted).**
   In the order-review field display, OPF shows the base amount (`a (+€2.00)`)
   while WAPF shows the converted amount (`a (+€3.00)`; minmax +€5.00 vs +€7.50;
   date +€42.00 vs +€63.00; etc.). Line totals and tax still match exactly.
   Recorded for the owner; not fixed here because this lane edits only `docs/`
   and `bin/`.

## Cleanup performed

`cleanup.json` is the verified post-cleanup record (the fixture cleanup mode ran
and removed everything below; the record was then captured from the clone). It
shows: all six fixture products and six OPF groups deleted (`remaining_products`
and `remaining_groups` empty; clone-wide product/group counts 0), every fixture
order deleted, the fixture customer deleted, tax rates 3 and 4 deleted
(`remaining_tax_rates` empty), the classic cart/checkout page contents restored
(pages 6 and 7), the fixture option removed, and every snapshotted option
restored (`active_plugins`, `opf_admin_only`, `opf_show_totals`,
`woocommerce_calc_taxes`, `woocommerce_prices_include_tax`,
`woocommerce_tax_display_cart`, `woocommerce_tax_display_shop`,
`woocommerce_enable_guest_checkout`, `woocommerce_default_country`,
`woocommerce_currency`, `woocommerce_cod_settings`, `woocommerce_cheque_settings`,
`woocommerce_coming_soon`, `woocommerce_store_pages_only`, `wapf_datepicker`,
`woocommerce_tax_classes`, `woocommerce_currency_pos`,
`woo_multi_currency_params`), the CURCY clone copy deactivated and its folder
deleted, and the PHP built-in server killed. The clone itself
(`/tmp/opf-lane-formulaadv-wp`) is disposable and left behind; all evidence is
staged in this repository directory.

## Validation (verbatim)

```text
$ cd /tmp/opf-lane-formulaadv && vendor/bin/phpunit 2>&1 | tail -6
.........................................................     1033 / 1033 (100%)

Time: 00:14.202, Memory: 30.00 MB

OK, but there were issues!
Tests: 1033, Assertions: 4365, PHPUnit Deprecations: 2.

$ cd /tmp/opf-lane-formulaadv && node --test tests/js/*.test.cjs 2>&1 | tail -8
ℹ tests 129
ℹ suites 0
ℹ pass 129
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 287.894181
```

1033 tests / 4365 assertions, 0 failures (baseline); 129/129 JS tests pass.
The two PHPUnit deprecations are the pre-existing baseline deprecations.

## Artifacts (all under `docs/compatibility/formula-advanced-commerce-20261005/`)

- `state.json` — fixture ids, products, groups, cases, runtime versions.
- `browser-lifecycle-results.json` — per-provider cart, order, refund,
  order-again, field metadata, previews, and all pass flags.
- `opf-vs-wapf-numbers.json` — OPF vs WAPF side-by-side (`all_match: true`).
- `curcy-general-price-path-probe.json` — general-path vs WOOCS/Aelia probe.
- `curcy-settings.json` — CURCY options snapshot used for the run.
- `cleanup.json` — what was removed/restored.
- `bin/e2e-formulaadv-fixture.php`, `bin/e2e-formulaadv-browser.mjs` — new,
  additive harnesses.

## Could not do / limits

- The CURCY run is not a WOOCS/Aelia proof (see the caveat above).
- The pricing-hint and preview display differences above were observed but not
  fixed (outside this lane's edit scope).
- Order-again required completing the COD/cheque order first (WooCommerce only
  offers order-again for completed orders); this is a WooCommerce rule, not an
  engine difference.
