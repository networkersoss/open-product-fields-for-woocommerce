# WAPF-STOREFRONT-HINT-CONVERSION — the JS-populated choice hint now converts (2026-10-05)

Lane: `jshint`. Closes the residual recorded in
[WAPF-PRICE-HINT-CONVERSION-2026-10-05](WAPF-PRICE-HINT-CONVERSION-2026-10-05.md)
(the "Open item" in [Storefront preview and the static hint]): the storefront
`span.opf-choice__hint` that frontend JS populates from the base-currency
choice amount. Executed against WAPF Extended **3.1.5** only, in a disposable
SQLite clone. Not a public parity claim.

## Verdict

- **Fixed.** With a real currency plugin active, the storefront choice hint
  (`span.opf-choice__hint`) now shows the **converted** per-choice amount for
  fixed and formula choices — the same value WAPF 3.1.5 renders and the same
  conversion the cart line is charged with. Measured live: fixed `+2 → +3`,
  min/max `+5 → +7.5`, date `+42 → +63` (base USD, EUR rate 1.5).
- **Percent hints stay unconverted**, matching WAPF `adjust_addon_price`'s
  early return. Measured live the percent hint moved `1.5 → 1` — i.e. from the
  (converted) display-base percent to the **shop-base** percent-derived figure
  WAPF's cart hint carries (`+€1.00`). This is the same figure the static pill
  already rendered.
- **Preview totals unchanged and engine parity.** Product / Options / Grand
  totals are byte-identical before and after this change and identical across
  OPF and WAPF for all four cases (`all_preview_totals_match: true`).
- **No regression without a currency plugin:** with CURCY inactive the
  storefront hint markup and the three totals are **byte-identical** to the
  pre-fix build (`no-currency-regression.json`, `identical: true`), and no
  conversion attribute is emitted.

## The seam: a server-published conversion factor, read by the module

### How the hint is produced today (file:line)

- The JS-populated choice hint is built in
  `assets/js/opf-frontend.js` — `updateChoicePriceHints()` (was 2416; now
  ~2416–2488). It recomputes `amountPerUnit` on every edit from
  `window.OPF_FIELDS[gid][fid].choices[].pricing` (base shop-currency amounts,
  `Renderer::registry`, `includes/Service/Renderer.php:337-379`) plus the
  product base price and quantity, then formats it with `formatPriceHint`.
- The product base price the module uses is the **display** price: `base =
  config.product_base_price ?? baseNode.getAttribute('data-product-price')`
  (`opf-frontend.js:2995`), and `data-product-price` is the converted
  `$product->get_price()`
  (`includes/Service/Renderer.php:1914`). The preview totals are computed from
  this same display base (`parseFloat(...) * qty`), which is why they are
  engine parity and must not change.
- The server-rendered **static** per-choice pill is
  `Renderer::pricing_hint_html()` (`Renderer.php:149`) → `hint_price_with_tax`
  → `PricingHints::convert_via_product_price()` (`PricingHints.php:236`), the
  previous lane's fix. Under CURCY that renders `(+€3.00)`.

### How WAPF's own storefront hint gets its converted value (file:line)

WAPF Extended 3.1.5 (`…/advanced-product-fields-for-woocommerce-extended/`):

- static per-choice/field pills call `Helper::format_pricing_hint` in `shop`
  context — `includes/classes/class-html.php:823-829` (per choice) and
  `:834-840` (field);
- `Helper::format_pricing_hint()` — `includes/classes/class-helper.php:491` —
  routes non-percent money through `adjust_addon_price()`
  (`:437-451`), which fires `wapf/pricing/addon` (`:451`);
- CURCY 2.4.3 hooks exactly that filter while WAPF is active
  (`plugins/advanced_product_fields_for_woocommerce_pro.php:31`,
  convert at `:122` via `wmc_get_price()`).

So WAPF's hint is converted **server-side on a WAPF-specific filter**, and
WAPF has **no JS-populated per-choice hint** to keep in sync.

### Why not convert `data-opf-price`

`data-opf-price` / the registry `pricing.amount` feed the preview *totals*
(fixed/flat math at `choiceUnitAddon`, consumed by `optionsTotal` in
`writeTotals`). Those totals are genuine engine parity (measured identical to
WAPF below). Rewriting the attribute would shift Product/Options/Grand totals
away from WAPF. It is left untouched.

### Why not emit a single already-converted display value

The hint is **live**: a formula choice (`max(3;min([field.x];7))*[qty]`,
`dow([field.d])*7*[qty]`) re-evaluates as `[field.x]`, `[field.d]` and the
quantity change. A single server-rendered converted figure cannot track that,
and emitting a *separate* live endpoint for hints would be a second pricing
engine. The chosen seam publishes the **scalar conversion factor** instead, so
the module keeps its live evaluator and only scales the result.

### The chosen seam

1. `PricingHints::hint_conversion_factor( $amount, $product )`
   (`includes/Service/PricingHints.php`) derives the factor from the **same
   helper** the line total uses:
   `convert_via_product_price( $amount, $product ) / $amount`. It returns
   `1.0` when nothing converts (no currency plugin, no product, zero/negative
   reference). No second conversion path.
2. `Renderer::render_group()` (`includes/Service/Renderer.php:472-479`) emits
   `data-opf-hint-conversion="<factor>"` on the field group **only when the
   factor differs from 1**, so a store with no conversion renders
   byte-identical markup.
3. `opf-frontend.js` reads the attribute in `writeTotals` (`:3084-3091`) and
   passes it to `updateChoicePriceHints` (`:3257`). The module evaluates
   against the **shop-currency base** (`base / factor` — so `[price]`
   formulas and percent get the base figure) and scales the result by the
   factor, **except percent**, exactly mirroring
   `PricingHints::adjust_addon_price`. `data-opf-price` and the totals math are
   untouched.

This is consistent with the existing adapter contract: WOOCS/Aelia publish a
`currency_rate` in `opf_config` for the same purpose; this seam publishes the
factor per group so it works for the general WooCommerce product-price path
(CURCY) that owns the conversion but ships no OPF integration.

Served markup (OPF, product 69, `served-markup.txt`):

```text
EUR: <div class="opf-field-group label-above" data-group="70" data-variables="[]" data-opf-product-price="10" data-opf-hint-conversion="1.5" data-opf-group="70">
USD: <div class="opf-field-group label-above" data-group="70" data-variables="[]" data-opf-product-price="10" data-opf-group="70">
EUR: <option value="a" data-opf-choice-hint="a" data-opf-base-label="a" data-opf-pricetype="fx" data-opf-price="(max(3;min([field.x];7))*[qty])">a + &euro;4.50</option>
USD: (no data-opf-hint-conversion attribute)
```

`data-opf-product-price="10"` (the preview base the totals depend on) and
`data-opf-price` are unchanged in both currencies.

## Live proof

Clone: `/tmp/opf-lane-jshint-wp` (SQLite drop-in, loopback
`http://127.0.0.1:8293`, PHP built-in server + `router.php`), now deleted.
WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, OPF 0.1.0, WAPF Extended
3.1.5, CURCY 2.4.3 (copied into the clone only). Base **USD**, EUR at **rate
1.5**, product base 10.00, quantity 1, taxes off. The fixture (identical to
the previous lane's) builds the same priced fields for OPF and WAPF on
identical disposable products.

| case | priced field | pricing | base addon (USD) | converted (EUR) |
| --- | --- | --- | --- | --- |
| fixed | radio `a` + text `engrave` | fixed 2 + fixed 2 | 2 (choice) | 3.00 |
| percent | radio `a` | percent 10 % | 1.00 | 1.00 (never converted) |
| minmax | select `a` (+ text `x=5`) | formula `max(3;min([field.x];7))*[qty]` | 5.00 | 7.50 |
| date | select `a` (+ date `d=2026-10-03`) | formula `dow([field.d])*7*[qty]` | 42.00 | 63.00 |

### Storefront choice hint — OPF before / after vs WAPF (EUR)

Measured with real Chromium on the served product page after filling the
field values (`storefront-results.json`; the module refreshes the hint on
edit).

| case | OPF before | OPF after | WAPF 3.1.5 (its own storefront hint) | match |
| --- | --- | --- | --- | --- |
| fixed (radio `a`) | `(+ €2)` | `(+ €3)` | static pill `(+€3.00)` | value ✅ |
| fixed (field `engrave`) | `(+ €2)` | `(+ €3)` | static pill `(+€3.00)` | value ✅ |
| percent | `(+ €1.5)` | `(+ €1)` | WAPF cart hint `(+€1.00)` / storefront glyph `(+10%)` | value ✅ |
| minmax | `a (+ €5)` | `a (+ €7.5)` | WAPF converted hint `(+€7.50)` | value ✅ |
| date | `a (+ €42)` | `a (+ €63)` | WAPF converted hint `(+€63.00)` | value ✅ |

The numeric values are extracted in `before-after-opf.json`. OPF's own money
format differs from WAPF's (`(+ €3)` vs `(+€3.00)`, trailing-zero trimming and
spacing), exactly as before; the **amount** is now the converted one. The
percent row is the WAPF `adjust_addon_price` early return: WAPF never converts
a percent-derived hint, and neither does this seam — the OPF storefront money
spelling of a percent (vs WAPF's `(+10%)` glyph) is the pre-existing format
divergence already recorded by the previous lane, unrelated to conversion.

### Preview totals — unchanged and parity

`storefront-results.json` / `opf-vs-wapf-hints.json`:

| case | OPF (before) | OPF (after) | WAPF 3.1.5 | match |
| --- | --- | --- | --- | --- |
| fixed | €15.00 / €4.00 / €19.00 | €15.00 / €4.00 / €19.00 | €15.00 / €4.00 / €19.00 | ✅ |
| percent | €15.00 / €1.50 / €16.50 | €15.00 / €1.50 / €16.50 | €15.00 / €1.50 / €16.50 | ✅ |
| minmax | €15.00 / €5.00 / €20.00 | €15.00 / €5.00 / €20.00 | €15.00 / €5.00 / €20.00 | ✅ |
| date | €15.00 / €42.00 / €57.00 | €15.00 / €42.00 / €57.00 | €15.00 / €42.00 / €57.00 | ✅ |

(Product / Options / Grand.) `all_preview_totals_match: true`; the attribute
delivered the conversion without touching the totals math.

### No-currency regression: byte-identical

Same fixture with CURCY **inactive** (USD), pre-fix vs fixed build
(`no-currency-regression.json`, `identical: true`; `opf_nocurcy` leg):

| case | hint (both builds) | preview totals (both builds) |
| --- | --- | --- |
| fixed | `(+ $2)` | $10.00 / $4.00 / $14.00 |
| percent | `(+ $1)` | $10.00 / $1.00 / $11.00 |
| minmax | `a (+ $5)` | $10.00 / $5.00 / $15.00 |
| date | `a (+ $42)` | $10.00 / $42.00 / $52.00 |

`served-markup.txt` confirms no `data-opf-hint-conversion` attribute is
emitted without a currency plugin, so the served HTML is unchanged too.

## Scope note: CURCY is not the WOOCS/Aelia adapter

CURCY ships a WAPF-specific integration and **no OPF integration**, and its
WAPF gate (`plugins/advanced_product_fields_for_woocommerce_pro.php:13-14`) is
only satisfied while WAPF itself is active. With OPF active and WAPF inactive
nothing converts OPF's hint through `wapf/pricing/addon`, so this run proves
the **general WooCommerce product-price path**
(`woocommerce_product_get_price` → `wmc_get_price()`), not OPF's
`WoocsIntegration`/`AeliaIntegration` adapters
(`class_exists('WOOCS')` and `class_exists('WC_Aelia_CurrencySwitcher')` are
both false here). It must not be used to claim WOOCS/Aelia coverage. Those
adapters have their own factor contract (`currency_rate` in `opf_config`) and
their own evidence.

## Tests added and executed

No existing test was weakened or deleted.

| test | level | proves |
| --- | --- | --- |
| `test_hint_conversion_factor_is_the_store_price_ratio` | `tests/Unit/PricingHintsTest.php` | identity with no plugin / zero / no product; `1.5` under `woocommerce_product_get_price` ×1.5 |
| `test_storefront_group_publishes_the_hint_conversion_factor` | `tests/Unit/PricingHintsTest.php` | `render_group` emits `data-opf-hint-conversion="1.5"` only under conversion, and keeps `data-opf-product-price="10"` |
| `storefront hint conversion scales live choices by the server-published factor` | `tests/js/opf-pricing.test.cjs` | fixed `2→3`, percent stays shop-base (`→1`), `[price]` formula evaluates on the shop base then converts (`→7.5`), qty formula `→7.5`; factor 1 / default argument are identical to pre-fix |

## Validation (verbatim)

```text
$ cd /tmp/opf-lane-jshint && vendor/bin/phpunit 2>&1 | tail -6
...............                                               1052 / 1052 (100%)

Time: 00:14.713, Memory: 30.00 MB

OK, but there were issues!
Tests: 1052, Assertions: 4456, PHPUnit Deprecations: 2.

$ cd /tmp/opf-lane-jshint && node --test tests/js/*.test.cjs 2>&1 | tail -8
ℹ tests 130
ℹ suites 0
ℹ pass 130
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 268.966455

$ php -l includes/Service/Renderer.php
No syntax errors detected in includes/Service/Renderer.php
$ php -l includes/Service/PricingHints.php
No syntax errors detected in includes/Service/PricingHints.php
$ php -l tests/Unit/PricingHintsTest.php
No syntax errors detected in tests/Unit/PricingHintsTest.php
$ php -l bin/e2e-shint-fixture.php
No syntax errors detected in bin/e2e-shint-fixture.php
$ node --check assets/js/opf-frontend.js && echo OK
OK
$ node --check tests/js/opf-pricing.test.cjs && echo OK
OK
$ node --check bin/e2e-shint-browser.mjs && echo OK
OK
```

Baseline was 1050 tests / 4449 assertions, 0 failures; now 1052 / 4456, 0
failures (the 3 new tests / 13 new assertions). The two PHPUnit deprecations
are the pre-existing baseline deprecations.

## Analyzer note (recorded, deliberately not "fixed")

`includes/Service/Renderer.php` reports 8 analyzer diagnostics
(`WC_Tax::get_rates`, `WC_Tax::calc_tax`, `current_user_can`, `OPF_E2E_TOKEN`,
`sanitize_html_class`, `WC_Product::get_price_html` ×2, `WC_Product::get_image`).
They are **pre-existing false positives**, not introduced here: the constructs
are byte-identical to the sibling HEAD+d2b5e38 build
(`renderer-analyzer-diagnostics-proof.txt` diffs the two files — this lane's
only Renderer change is the 8-line attribute insert at 471-479) and every
flagged symbol is declared in the `php-stubs/wordpress-stubs` and
`php-stubs/woocommerce-stubs` packages this project ships in `vendor/`. They
are deliberately not fixed because doing so means editing unrelated production
call sites, outside this lane's seam.

## Artifacts (all under `docs/compatibility/storefront-hint-20261005/`)

- `state.json` — fixture ids, cases, runtime versions, CURCY config summary.
- `storefront-results.json` — fixed build, all three legs (OPF/WAPF ×
  CURCY on/off): pill, `opf-choice__hint`, `option`, `data-opf-price`,
  `data-opf-hint-conversion`, preview totals.
- `baseline/storefront-results.json` — pre-fix build, same fixture/legs.
- `before-after-opf.json` — pre-fix vs fixed OPF under CURCY.
- `opf-vs-wapf-hints.json` — side-by-side, `all_preview_totals_match: true`.
- `no-currency-regression.json` — pre-fix vs fixed OPF with CURCY inactive,
  `identical: true`.
- `served-markup.txt` — raw served group/option tags (USD vs EUR).
- `storefront-{opf,wapf}-{fixed,percent,minmax,date}.png` — screenshots.
- `renderer-analyzer-diagnostics-proof.txt` — analyzer false-positive proof.
- `cleanup.json` — post-cleanup record.

New additive harnesses (repo `bin/`): `bin/e2e-shint-fixture.php`,
`bin/e2e-shint-browser.mjs`, `bin/e2e-shint-compare.py`.

## Cleanup performed

The fixture's `cleanup` mode ran and `cleanup.json` is the verified record:

- 4 fixture products and 4 OPF field groups deleted; `remaining_products: 0`.
  All fixture orders deleted; `remaining_wc_orders: 0`. The fixture customer
  deleted.
- Classic cart/checkout page contents restored.
- Every snapshotted option restored (the same 22 options the previous lane
  recorded, including `active_plugins`, `woocommerce_currency`,
  `woo_multi_currency_params`, `permalink_structure`).
- CURCY clone copy deactivated and its folder deleted (`curcy_removed: true`).
- PHP built-in server on `127.0.0.1:8293` killed (port closed).
- Disposable clone `/tmp/opf-lane-jshint-wp` and all `/tmp` scratch files
  deleted. The production WordPress install and its plugins were never touched.

## Anything still open

- **WOOCS/Aelia storefront hint factor.** Those adapters already convert the
  *static* pill on `opf_pricing_hint_amount`. Their `opf_config.currency_rate`
  is not currently fed into this new `data-opf-hint-conversion` attribute, so
  the JS hint under a WOOCS/Aelia runtime still uses the baseline (it was
  never covered by the previous lane either). This lane's fixture proves only
  the CURCY/general-product-price path. Follow-up evidence is required before
  claiming WOOCS/Aelia storefront-hint parity.
- **Percent glyph.** OPF renders a percent choice as money (`+ €1.00`) where
  WAPF renders `(+10%)`. Pre-existing format divergence, unrelated to
  conversion; left byte-identical in the no-currency build.
- **Non-linear conversion plugins.** The seam models the conversion as a
  scalar factor (true for rate-based CURCY/WOOCS/Aelia). A hypothetical
  non-linear `woocommerce_product_get_price` listener would preview
  approximately; the cart/order surfaces (which convert exactly) are
  unaffected.
- **Tax-on storefront hint.** The JS hint does not tax-adjust (as before);
  under taxes the static pill and the JS hint differ by the shop tax display,
  unchanged from the pre-fix build.
