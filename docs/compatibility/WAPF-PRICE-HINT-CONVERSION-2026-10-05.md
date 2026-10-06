# WAPF-PRICE-HINT-CONVERSION — the cart/checkout pricing hint now shows the converted amount (2026-10-05)

Lane: `hintconv`. Closes the residual recorded as finding 2 in
[WAPF-PRICE-FORMULA-ADVANCED-COMMERCE-2026-10-05](WAPF-PRICE-FORMULA-ADVANCED-COMMERCE-2026-10-05.md):
*"Cart/checkout pricing hint conversion differs (OPF base, WAPF converted)."*
Executed against WAPF Extended **3.1.5** only, in a disposable SQLite clone.
Not a public parity claim.

## Verdict

- **Fixed.** With a real currency plugin active, OPF's cart **and** checkout
  field display now renders the **converted** per-value hint, the same number
  WAPF 3.1.5 renders, and the same conversion the line total is charged with.
- The storefront's static per-choice hint had the **same** defect and is fixed
  with the same helper. The storefront *JS preview totals* are genuine engine
  parity and were deliberately left alone (see
  [Storefront](#storefront-preview-and-the-static-hint)).
- **No regression:** with no currency plugin the hint text and amount are
  **byte-identical** to the pre-fix build — every surface compared
  (`no-currency-regression.json`, `identical: true`).
- **Stored order metadata is converted in both engines** (WAPF stores the
  converted `pricing_hint`; OPF now matches). Nothing that was base before is
  base now on the order surface — this is a deliberate, measured change,
  recorded in [Order metadata](#order-metadata-converted-in-both-engines).
- Line totals, line tax and order totals are **unchanged** by the fix.

## The WAPF 3.1.5 conversion contract (file:line)

Paths are relative to
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended/`.

1. WAPF computes the hint **once per cart value** at cart time and stores it on
   the cart field values:
   `includes/classes/class-cart.php:62` (and `:66`) —
   `Helper::format_pricing_hint( $value['price_type'], $price, $product, 'cart' )`.
   The same stored string feeds the cart item-data display and the raw order
   meta (`includes/classes/class-helper.php:876-877`, `:892-893`).
2. `includes/classes/class-helper.php:491` — `Helper::format_pricing_hint()`:
   - `:494` `apply_filters( 'wapf/html/pricing_hint/amount', $amount, $product, $type, $for_page )`
     — the **amount** filter, applied **before** tax;
   - `:497-498` the `shop` + percent branch returns the percent verbatim;
   - `:500` money output = `format_price( adjust_addon_price( ... ) )`;
   - `:503` the `fx` (formula) branch reuses that money output.
3. `includes/classes/class-helper.php:437-451` — `Helper::adjust_addon_price()`:
   - `:443` percent early-returns → **percent amounts are never tax-adjusted
     and never converted** (on any page);
   - `:447-449` `maybe_add_tax()` first;
   - `:451` `return apply_filters( 'wapf/pricing/addon', $amount, $product, $type, $for );`
     — the **addon** filter, applied **after** tax. **This is the hook CURCY
     converts WAPF's hint on.**
4. Storefront static pills call the same formatter in `shop` context:
   `includes/classes/class-html.php:823-829` (per choice) and `:834-840`
   (field level).
5. WAPF's own WOOCS and Aelia integrations convert on the **amount** filter
   (`includes/classes/integrations/class-woocs.php:12`,
   `includes/classes/integrations/class-aelia.php:16`) — i.e. **before** tax.

### Which WooCommerce function/filter CURCY actually converts

CURCY 2.4.3 (`.../woocommerce-multi-currency/`):

- `plugins/advanced_product_fields_for_woocommerce_pro.php:13-14` — the WAPF
  integration is gated on
  `is_plugin_active( 'advanced-product-fields-for-woocommerce-pro/…' ) || class_exists( '\SW_WAPF_PRO\WAPF' )`.
  **WAPF Extended 3.1.5 is `SW_WAPF_PRO\WAPF`**, so this gate is satisfied only
  while WAPF itself is active.
- `:31` `add_filter( 'wapf/pricing/addon', [ $this, 'convert_product_price' ], 10, 2 );`
- `:124-128` `convert_product_price()` → `wmc_get_price( $price )` when the
  current currency is not the default. (`:33` shows CURCY's *commented-out*
  amount-filter variant — not registered.)
- The general Woo path CURCY converts is **`woocommerce_product_get_price`**:
  registered at `frontend/price.php:163` / `:213` (variations `:169` / `:217`),
  callback at `frontend/price.php:1515`, converting via `wmc_get_price()` at
  `:1589/:1613/:1617/:1627/:1634/:1647/:1652/:1655`, defined at
  `includes/functions.php:103`.

**So WAPF gets its converted hint because CURCY hooks a WAPF-specific filter
(`wapf/pricing/addon`) that CURCY only registers while WAPF is active.** With
OPF active and WAPF inactive, that listener does not exist, so nothing converted
OPF's hint. OPF's line total was still converted, because OPF normalises the
line to the shop-currency target and lets CURCY's general
`woocommerce_product_get_price` conversion run — the same path now used for the
hint.

### OPF before the fix: displayed amount vs line total

- **Displayed amount** — `includes/Service/PricingHints::format()` →
  `adjust_addon_price()` → `maybe_add_tax()` (`wc_get_price_excluding_tax` /
  `wc_get_price_including_tax`) → `format_price()`. The per-value `calc_price`
  is base-currency, and `opf_pricing_addon` had no currency listener, so the
  hint stayed base.
- **Line total** — `CartIntegration::apply_prices()` sets
  `$product->set_price( base + addon )` in shop currency; WooCommerce's
  `woocommerce_product_get_price` (CURCY) converts that single value once.

## What OPF changed, and why

`includes/Service/PricingHints.php`:

- New `PricingHints::convert_via_product_price( $amount, $product, $already_converted = false )`
  runs the amount through `apply_filters( 'woocommerce_product_get_price', $amount, $product )`
  — literally the filter the converted line total passes through, on the same
  product object, so the conversion branch and its guards are identical.
- `adjust_addon_price()` now calls it **after** `opf_pricing_addon` (the alias
  of `wapf/pricing/addon`, WAPF's own conversion position: after tax).
  Percent amounts still return earlier, so percent hints stay unconverted —
  exactly like WAPF (`class-helper.php:443`).
- **Double-conversion guard:** a listener that rewrote the amount on
  `opf_pricing_hint_amount` (WOOCS/Aelia convert there, before tax) marks the
  amount as already converted, so the store path is skipped. Without the guard,
  WOOCS/Aelia hints would be multiplied twice.

`includes/Service/Renderer.php`:

- `Renderer::pricing_hint_html()` calls the same helper after its shop tax
  adjustment, for non-percent amounts only. The storefront static pill had the
  identical defect and this makes it match WAPF (measured below).

No other file changed. `assets/`, the WAPF hook bridge, and the WOOCS/Aelia
adapters are untouched.

## Live proof

Clone: `/tmp/opf-lane-hintconv-wp` (SQLite drop-in, loopback
`http://127.0.0.1:8292`, PHP built-in server + `router.php`), now deleted.
WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, OPF 0.1.0, WAPF Extended
3.1.5, CURCY 2.4.3 (copied into the clone only). Base **USD**, EUR at **rate
1.5**, product base 10.00, quantity 1, **taxes off** for the main comparison
(the conversion is display-only; the tax-enabled leg is separate below).

Fixture (`bin/e2e-hintconv-fixture.php`), identical for both engines:

| case | priced field | pricing | base addon | expected unit EUR |
| --- | --- | --- | --- | --- |
| fixed | radio choice `a` + text field `engrave` | fixed 2 + fixed 2 | 4.00 | 21.00 |
| percent | radio choice `a` | percent 10 % | 1.00 | 16.50 |
| minmax | select choice `a` | formula `max(3;min([field.x];7))*[qty]` (x=5) | 5.00 | 22.50 |
| date | select choice `a` | formula `dow([field.d])*7*[qty]` (2026-10-03) | 42.00 | 78.00 |

Both engines rendered the product page, submitted the values, added all four
lines to one cart, and placed a real classic `?wc-ajax=checkout` order through a
built-in gateway (OPF: COD, WAPF: cheque).

### Cart and checkout field display — OPF vs WAPF (EUR, converted)

Cart page and checkout order-review rows are byte-identical between the engines
for all four cases (`opf-vs-wapf-hints.json`: `all_cart_display_match`,
`all_checkout_display_match` = `true`).

| case | OPF before | OPF after | WAPF 3.1.5 | match |
| --- | --- | --- | --- | --- |
| fixed — choice | `fee: a (+€2.00)` | `fee: a (+€3.00)` | `fee: a (+€3.00)` | yes |
| fixed — scalar | `engrave: Monogram (+€2.00)` | `engrave: Monogram (+€3.00)` | `engrave: Monogram (+€3.00)` | yes |
| percent | `fee: a (+€1.00)` | `fee: a (+€1.00)` | `fee: a (+€1.00)` | yes |
| minmax | `x: 5 fee: a (+€5.00)` | `x: 5 fee: a (+€7.50)` | `x: 5 fee: a (+€7.50)` | yes |
| date | `d: 10-03-2026 fee: a (+€42.00)` | `d: 10-03-2026 fee: a (+€63.00)` | `d: 10-03-2026 fee: a (+€63.00)` | yes |

The percent row is **unchanged and correct**: WAPF's `adjust_addon_price`
early-returns percent, so neither engine converts a percent-derived hint. This
matches the recorded defect numbers exactly (`a +2.00 → +3.00`, min/max
`+5.00 → +7.50`, date `+42.00 → +63.00`).

Line units (EUR) and totals are unchanged by the fix and identical across
engines: units `2100 / 1650 / 2250 / 7800`, cart total `13800` minor units,
order subtotal `138.00`, tax `0.00`, total `138.00` for both OPF (order 29) and
WAPF (order 30).

### Tax-enabled leg (conversion ordering)

Same fixture with a 10 % standard rate, `woocommerce_tax_display_cart=incl`
(`tax-leg/`). Both engines produce identical hints, which proves the conversion
runs **after** tax exactly like WAPF:

| case | arithmetic | OPF | WAPF |
| --- | --- | --- | --- |
| fixed | 2 → +10 % = 2.20 → ×1.5 | `(+€3.30)` | `(+€3.30)` |
| percent | percent is never tax-adjusted or converted | `(+€1.00)` | `(+€1.00)` |
| minmax | 5 → 5.50 → ×1.5 | `(+€8.25)` | `(+€8.25)` |
| date | 42 → 46.20 → ×1.5 | `(+€69.30)` | `(+€69.30)` |

Order totals `151.80` EUR in both engines.

### Order metadata: converted in both engines

WAPF stores the converted string: `_wapf_meta` carries
`"calc_price":2` (**base**, the calculation input) together with
`"pricing_hint":"(+&euro;3.00)"` (**converted**, the display value). OPF's
`_opf_fields_snapshot` now carries the converted `pricing_hint` too, and the
visible order-item meta is identical in both engines:

| case | OPF order meta | WAPF order meta |
| --- | --- | --- |
| fixed | `a (+&euro;3.00)`, `Monogram (+&euro;3.00)` | same |
| percent | `a (+&euro;1.00)` | same |
| minmax | `5`, `a (+&euro;7.50)` | same |
| date | `10-03-2026`, `a (+&euro;63.00)` | same |

This is a **behaviour change on the persisted surface** and is called out
deliberately: before the fix OPF persisted the base amount (`a (+&euro;2.00)`)
while WAPF persisted the converted one. Matching WAPF was the instruction, and
the measurement above is the evidence for it. Note WAPF's stored `calc_price`
stays base; OPF stores no `calc_price`, so nothing base was converted in place.

## No-currency-plugin regression: byte-identical

Same fixture, CURCY **inactive**, USD (`no-currency-regression.json`). Every
surface is compared between the pre-fix build (the pristine copy of
`includes/Service/PricingHints.php` and `includes/Service/Renderer.php`, taken
from a sibling export and verified by `diff` to differ from this lane's pre-fix
content by nothing) and the fixed build: storefront hint pills and
option labels, cart item-data markup, cart page rows, checkout rows, order
visible meta and order totals.

| case | cart / checkout (both builds) | order meta (both builds) |
| --- | --- | --- |
| fixed | `fee: a (+$2.00) engrave: Monogram (+$2.00)` | `a (+&#36;2.00)`, `Monogram (+&#36;2.00)` |
| percent | `fee: a (+$1.00)` | `a (+&#36;1.00)` |
| minmax | `x: 5 fee: a (+$5.00)` | `5`, `a (+&#36;5.00)` |
| date | `d: 10-03-2026 fee: a (+$42.00)` | `10-03-2026`, `a (+&#36;42.00)` |

`identical: true` for all four cases on all surfaces; order totals `92.00` USD
in both builds. **The WAPF-DISPLAY-PRICE-HINTS promotion therefore still
holds** — with no currency plugin the hint text and amount are unchanged.

## Storefront preview and the static hint

Two different things were conflated in the recorded finding; they are separated
here (`storefront-preview-probe.json`, screenshots
`storefront-{opf,wapf}-fixed-eur.png`).

1. **JS preview totals — genuine engine parity, left alone.** Under CURCY/EUR
   both engines render `Product total €15.00 / Options total €2.00 / Grand total
   €17.00` for the `fixed` case (converted product base + base addon), and
   `Options total €1.50 / €3.00 / €0.00` for percent / minmax / date —
   identical strings in both engines. The product base is converted because both
   read the converted product price from the DOM; the options total stays in
   shop currency because CURCY's footer script that would convert it is
   commented out in the plugin. This is engine parity and was **not** changed.
2. **Static per-choice hint — was *not* parity; fixed.** WAPF renders the
   converted static pill (`(+€3.00)` for a fixed 2 at rate 1.5) because CURCY's
   `wapf/pricing/addon` listener converts it while WAPF is active. OPF rendered
   `+ €2.00`. This is the same defect as the cart surface, so
   `Renderer::pricing_hint_html` now converts too and renders `+ €3.00`.

### Open item (needs `assets/`, out of this lane's ownership)

OPF also renders a JS-populated live choice hint (`span.opf-choice__hint`) next
to each priced choice. The frontend module
(`assets/js/opf-frontend.js:2411-2451`) formats it from the base-currency
`data-opf-price` attribute, so under CURCY/EUR the product page shows
`fee * a + €3.00 (+ €2)` — converted static pill plus base live hint. WAPF has
no equivalent element for fixed/percent choices, so there is no parity gap to
match, but it is an OPF-internal inconsistency. Converting `data-opf-price`
would be wrong (the same attribute drives the preview *totals*, which are parity
and must stay in shop currency), so this needs a JS-side rate or a decision to
drop the duplicate element. Not fixed here: `assets/` is outside this lane's
file ownership.

### Pre-existing, out of scope

On the product page OPF renders a percent hint as money (`+ €1.00`) where WAPF
renders the percent itself (`(+10%)`, `class-helper.php:497-498`). That is a
format divergence, unrelated to conversion; this lane leaves the percent path
byte-identical to before (proved by the percent rows above).

## Tests added and executed

`tests/Unit/PricingHintsTest.php` (5 new tests; no existing test weakened or
deleted):

| test | proves |
| --- | --- |
| `test_cart_hint_follows_the_store_product_price_conversion` | fixed `2 → (+$3.00)` and formula `42 → (+$63.00)` convert; percent does not |
| `test_no_currency_plugin_leaves_the_hint_byte_identical` | no listener → `(+$2.00)` / `(+$42.00)` / `(+$1.00)` unchanged |
| `test_amount_adapter_conversion_is_not_repeated_by_the_store_path` | WOOCS/Aelia-style amount-filter conversion is applied exactly once (not `×2.25`) |
| `test_cart_display_and_order_meta_carry_the_converted_hint` | cart item-data markup, order visible meta and `_opf_fields_snapshot` all carry `(+$3.00)` |
| `test_storefront_hint_uses_the_same_conversion` | `Renderer::pricing_hint_html` renders `+ $3.00` |

Results:

```text
$ cd /tmp/opf-lane-hintconv && vendor/bin/phpunit --filter 'PricingHintsTest' 2>&1 | tail -6
.................                                             31 / 31 (100%)
Tests: 31, Assertions: 72, PHPUnit Deprecations: 2.
```

## Validation (verbatim)

```text
$ cd /tmp/opf-lane-hintconv && vendor/bin/phpunit 2>&1 | tail -6
.........                                                     1046 / 1046 (100%)

Time: 00:14.532, Memory: 30.00 MB

OK, but there were issues!
Tests: 1046, Assertions: 4416, PHPUnit Deprecations: 2.

$ cd /tmp/opf-lane-hintconv && node --test tests/js/*.test.cjs 2>&1 | tail -8
ℹ tests 129
ℹ suites 0
ℹ pass 129
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 285.157279
```

Baseline was 1041 tests / 4405 assertions, 0 failures; now 1046 / 4416, 0
failures (the 5 new tests / 11 new assertions). The two PHPUnit deprecations are
the pre-existing baseline deprecations. Cart/API regression filter
(`--filter 'CartIntegration|CartEdit|…|Woocs|Aelia|FoxCurrency|ProductPreview'`):
**160 tests / 562 assertions, 0 failures**.

`php -l` on every changed PHP file:

```text
$ php -l includes/Service/PricingHints.php
No syntax errors detected in includes/Service/PricingHints.php
$ php -l includes/Service/Renderer.php
No syntax errors detected in includes/Service/Renderer.php
$ php -l tests/Unit/PricingHintsTest.php
No syntax errors detected in tests/Unit/PricingHintsTest.php
$ php -l bin/e2e-hintconv-fixture.php
No syntax errors detected in bin/e2e-hintconv-fixture.php
```

## Analyzer note (recorded, deliberately not "fixed")

`includes/Service/Renderer.php` reports 8 analyzer diagnostics
(`WC_Tax::get_rates`, `WC_Tax::calc_tax`, `current_user_can`, `OPF_E2E_TOKEN`,
`sanitize_html_class`, `WC_Product::get_price_html` ×2, `WC_Product::get_image`).
They are **pre-existing false positives**, not introduced here: the constructs
are byte-identical to this lane's pre-fix content (the sibling export's copies
of those two files differ from it by nothing; only this lane's inserted lines
shift the flagged constructs), and every flagged symbol is declared in
the `php-stubs/wordpress-stubs` and `php-stubs/woocommerce-stubs` packages this
project already ships in `vendor/`. They are deliberately **not** fixed because
doing so means editing unrelated production call sites. Full evidence:
`hint-conversion-20261005/renderer-analyzer-diagnostics-proof.txt`.

## Scope note: CURCY is not the WOOCS/Aelia adapter

CURCY ships a WAPF-specific integration and **no OPF integration**, and its
WAPF gate is only satisfied while WAPF is active. This run therefore proves the
**general WooCommerce product-price path**
(`woocommerce_product_get_price` → `wmc_get_price()`), not OPF's `WoocsIntegration`
or `AeliaIntegration` adapters (`class_exists('WOOCS')` and
`class_exists('WC_Aelia_CurrencySwitcher')` are both false here). Those rows keep
their own evidence; this result must not be used to claim WOOCS/Aelia coverage.
The double-conversion guard for those adapters is covered by a unit test, not by
this live run.

## Artifacts (all under `docs/compatibility/hint-conversion-20261005/`)

- `state.json` — fixture ids, cases, runtime versions, CURCY config summary.
- `wapf-315-conversion-contract.json` — machine-readable file:line contract.
- `browser-results.json` — fixed build, all four legs (OPF/WAPF × CURCY
  on/off): storefront pills, cart item-data, cart page rows, checkout rows,
  order meta, order/cart totals.
- `baseline/browser-results.json` — pre-fix build (pristine copies of the two
  edited files, verified by `diff`), same fixture.
- `opf-vs-wapf-hints.json` — side-by-side, all match flags `true`.
- `before-after-opf.json` — pristine vs fixed OPF under CURCY.
- `no-currency-regression.json` — pristine vs fixed OPF with CURCY inactive,
  `identical: true`.
- `tax-leg/browser-results.json` — tax-enabled leg (conversion after tax).
- `storefront-preview-probe.json` — static pills, JS live hint, preview totals.
- `storefront-opf-fixed-eur.png`, `storefront-wapf-fixed-eur.png` — screenshots.
- `cleanup.json` — post-cleanup record.
- `renderer-analyzer-diagnostics-proof.txt` — analyzer false-positive proof.
- `bin/e2e-hintconv-fixture.php`, `bin/e2e-hintconv-browser.mjs`,
  `bin/e2e-hintconv-compare.py` — new, additive harnesses.

## Cleanup performed

The fixture's `cleanup` mode ran and `cleanup.json` is the verified record:

- 4 fixture products and 4 OPF field groups deleted; `remaining_products: 0`,
  clone-wide products 0, orders 0.
- All fixture orders (EUR and USD legs, orders 21–34) deleted;
  `remaining_wc_orders: 0`.
- The fixture customer deleted (1 user left = the clone admin).
- The tax rate inserted by the tax leg deleted (`woocommerce_tax_rates` rows 0).
- Classic cart/checkout page contents restored (pages 7, 8).
- Every snapshotted option restored: `active_plugins`, `opf_admin_only`,
  `opf_show_totals`, `opf_show_price_hints`, `opf_hint_format`,
  `woocommerce_calc_taxes`, `woocommerce_enable_guest_checkout`,
  `woocommerce_default_country`, `woocommerce_currency`,
  `woocommerce_cod_settings`, `woocommerce_cheque_settings`,
  `woocommerce_prices_include_tax`, `woocommerce_tax_display_cart`,
  `woocommerce_tax_display_shop`, `wapf_datepicker`, `wapf_show_pricing_hints`,
  `wapf_hint_format`, `woo_multi_currency_params`, `woocommerce_currency_pos`,
  `woocommerce_coming_soon`, `woocommerce_store_pages_only`,
  `permalink_structure`. Post-cleanup spot check: `woocommerce_calc_taxes=no`,
  `woocommerce_tax_display_cart=excl`, `woocommerce_tax_display_shop=excl`,
  `woocommerce_currency=USD`, `woocommerce_prices_include_tax=no`, CURCY option
  absent, fixture option removed.
- CURCY clone copy deactivated and its folder deleted (`curcy_removed: true`).
- PHP built-in server on `127.0.0.1:8292` killed (port closed).
- Disposable clone `/tmp/opf-lane-hintconv-wp`, the pristine baseline plugin copy
  `/tmp/opf-hintconv-baseline`, and all probe scratch files deleted. Nothing was
  left in `/tmp` outside this repository directory.
- Production WordPress and its plugins were never touched.

## Could not do / limits

- The live run proves the general Woo price path only (see the scope note).
- The storefront JS live choice hint stays base-currency (open item above;
  `assets/` is out of ownership for this lane).
- OPF's product-page percent hint format differs from WAPF's (`+ €1.00` vs
  `(+10%)`) — pre-existing, unrelated to conversion, not fixed here.
- The 8 `Renderer.php` analyzer diagnostics are pre-existing false positives,
  deliberately not fixed.
