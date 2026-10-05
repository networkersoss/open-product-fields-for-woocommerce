# sumQty browser + commerce lifecycle proof — 2026-10-02

> **Artifact status (2026-10-05):** both JSON result files are tracked and
> survive (`docs/compatibility/sumqty-browser-results.json`,
> `docs/compatibility/sumqty-commerce-results.json`); the two screenshots
> `vendor/sumqty-artifacts/sumqty-{desktop,mobile}.png` are no longer
> retrievable and were never tracked. The visual claim rests on the surviving
> JSON files and `bin/e2e-sumqty-browser.cjs`; the quoted totals are the values
> recorded at the time.

## Scope

This lane covers `sumQty(field)` formula pricing on the `image_quantity` field
type: live Chromium grand totals, Store API cart pricing, classic order-line
persistence, quantity-limit validation, and a source-level comparison against
the actually installed WAPF Extended 3.1.5. Runtime is this worktree's private
disposable install `vendor/sumqty-wordpress` (WordPress 7.1.2, WooCommerce
11.1.0, SQLite, PHP 8.5.11) served by `wp server` on loopback
`http://127.0.0.1:8182`. No production site or database was touched.

## Reported failure and diagnosis

Reported symptom: a select choice priced by `sumQty(prints)` previewed `5`
in the browser grand total but the server charged `10` for product
quantity 2 (oak=2, ash=3 → sumQty = 5).

Root cause, verified against both engines:

- The browser totals writer evaluates `pricing.formula_raw || pricing.formula`
  once per line — a formula result is a **line-level** add-on, matching WAPF's
  theme math (`assets/js/opf-frontend.js` `choiceOrFieldAddon`).
- The server evaluates `pricing.formula` as a **per-unit** add-on
  (`Calculator::choice_addon`, formula case returns the raw result; the line
  total is unit × qty).
- WAPF 3.1.5 `Fields::do_pricing` treats `fx` results as line-level for normal
  fields: `return $is_qty_based_field ? $x : $x / $qty;`
  (`includes/classes/class-fields.php:298-311`). `qty_based` is only set for
  qty-driven clone fields (`includes/classes/class-cart.php:56`), so a plain
  `fx` formula adds its result once per line; authors append `* [qty]` when
  they want per-unit behavior.

So a `formula`-only choice (no `formula_raw`) diverges at qty > 1: the browser
shows `sumQty(prints)` = 5 flat, the server charges 5 per unit = 10. The
fixture models the real **imported** WAPF shape — `formula` holds the
qty-compensation-stripped expression (`sumQty(prints)`) and `formula_raw`
keeps the authored expression (`sumQty(prints)*[qty]`). For that pair the
browser computes `5 * 2 = 10` flat and the server computes `5` per unit;
both line contributions are 10 and every total matches.

## WAPF 3.1.5 source comparison (executed, not assumed)

Against the installed Extended 3.1.5 tree at
`/tmp/opf-sumqty-woo-wordpress/wp-content/plugins/advanced-product-fields-for-woocommerce-extended`:

```sh
OPF_SUMQTY_E2E_ALLOW=1 OPF_SUMQTY_E2E_MODE=oracle \
  OPF_WAPF_SUMQTY_SOURCE=<installed extended dir> \
  wp --path=vendor/sumqty-wordpress eval-file bin/e2e-sumqty-browser-fixture.php
# {"source_sha256":"cc8edbe3b429ffb923c3ff23240a6043c0857542a45f0ae9845e4742960e3ccc",
#  "observed":{"sumQty(prints)":5,"sumQty(missing)":0,"sumQty(empty)":0,"sumQty(prints)*2":10}}
```

The oracle loads the installed `includes/classes/class-helper.php` +
`extend/formulas.php` and runs the real `sumQty` callback through
`Helper::parse_math_string`. WAPF's callback (`extend/formulas.php:130-147`)
sums `intval()` over `array_column($cf['values'], 'label')`; OPF's normalized
model stores `{_opf_type:'image_quantity', quantities:{slug:int}}` and sums
integer-matching quantities only. On server-sanitized values (always
non-negative ints) the results are identical.

`Fields::do_pricing` was also executed on the installed source via `wp eval`
(manual requires; empty `$field_group_ids` so `Field_Groups::get_by_ids([])`
short-circuits):

```text
fx "sumQty(prints)"      qty=2  non-qty_based → 2.5/unit  (line +5 flat)
fx "sumQty(prints)*[qty]" qty=2 non-qty_based → 5/unit    (line +10)
fx "5"                   qty=2  non-qty_based → 2.5/unit  (line +5 flat)
fx "5"                   qty=2  qty_based     → 5/unit    (line +10)
```

OPF `Calculator::choice_addon` for the same inputs returns `5` per unit for
both `sumQty(prints)` and `5` (line +10). See the documented gap below.

## Parity fix in this commit

`sumQty` quantity filtering is now identical in PHP and JS:
`is_int || is_float || is_string` + `/^\d+$/` (PHP) and
`typeof number || string` + `/^\d+$/` (JS). Previously PHP `is_scalar()`
admitted `true` (`(string) true === '1'`, counted 1) while JS admitted
single-element arrays (`String([2]) === '2'`, counted 2). Both engines now
reject booleans, arrays, objects, negatives, fractions, and padded text.
Real cart values are unaffected — `CartIntegration::sanitize_submitted`
already clamps image quantities to non-negative integers.

Note: native WAPF `intval()` is more permissive (`intval('2foo')===2`,
`intval(-1)===-1`, `intval(' 2 ')===2`). The stricter filter only ever sees
server-sanitized values, so results on real input are unchanged; it is a
hardening difference, not a behavioral divergence.

## Proof results

```sh
# from /tmp (playwright resolves from /tmp/node_modules)
node /tmp/opf-sumqty-lifecycle-20261002/bin/e2e-sumqty-browser.cjs
```

Chromium (`docs/compatibility/sumqty-browser-results.json`): the served
`assets/js/opf-frontend.js` matched the worktree bytes exactly; the disabled
choice input rendered `disabled`; four real quantity/line-qty combinations
produced grand totals 28, 43, 32, 55; aggregate `min_choices=3` and
`max_choices=8` and fractional quantities produced native validation
messages; `page_errors: []`. Desktop + mobile screenshots:
`vendor/sumqty-artifacts/sumqty-{desktop,mobile}.png`.

Commerce (`docs/compatibility/sumqty-commerce-results.json`): the same four
browser observations were replayed through `POST /wc/store/v1/cart/add-item`
with the `opf_fields` payload; every Store API `line_total` equaled the
browser grand total (28/43/32/55). Forged choice slugs and unknown field ids
were stripped by server validation (`forged`/`absent` absent from stored
values). A classic `wc_create_order` + `woocommerce_checkout_create_order_line_item`
order persisted quantity 2, `$43.00` line total, `Prints → "Oak: 2, Ash: 3"`,
the `fee`/`unrelated`/`missing` selections, `_opf_fields` structure, and
`_opf_fields_snapshot`; cart, order, product, and group cleanup verified.

Unit coverage: `node --test tests/js/opf-sumqty.test.cjs` (2 tests) checks
JS↔PHP parity for canonical/nested/empty/missing `sumQty` and the malformed
quantity matrix. `vendor/bin/phpunit`: **283 tests, 1,112 assertions, all
pass** (one pre-existing metadata deprecation). `node --test tests/js/*.test.cjs`:
**14/14 pass**. `php -l` and `node --check` clean on changed files.

After integrating this slice with the toggle and date-format changes, the
browser/cart/order scenario was rerun against the combined source at commit
`8696c00c3a3edde67848a93085e10abfafb43cd0` (before docs-only ledger updates).
It again passed all four browser/cart total comparisons, validation cases,
order persistence, source-byte comparison, and cleanup with no page errors.
Combined-source hashes were `includes/Engine/Calculator.php`
`009f747a4f22e76f02f30b01dd2633f2b4cc9457a07aec9aaec8115991ad901a` and
`assets/js/opf-frontend.js`
`f2a84f1efbf8404f8283626f103bb1af1472b2d67f1b70b910c90e15d6881d55`.

## Documented gap — general formula quantity multiplication (separate issue)

This is recorded explicitly, not hidden: OPF's `formula` pricing is always
evaluated **per-unit** server-side (`normalize_pricing` forces
`per_unit=true` for formula; `Calculator::choice_addon` returns the raw
result). WAPF `fx` is **line-level** by default — the same formula result is
added once per line unless the field is qty-clone based.

Consequences at product quantity > 1:

- Imported WAPF formula **without** a trailing `*[qty]` (e.g. `5` or
  `sumQty(prints)`): WAPF charges the result once per line; OPF charges it
  per unit (× qty). Overcharge relative to WAPF.
- Natively authored OPF `formula` (the builder writes `formula` only, no
  `formula_raw`): the browser preview shows the result flat (matching WAPF),
  the server charges per unit — an internal preview↔charge divergence. This
  is exactly the reported "previews 5 but server charges 10" symptom.
- Related: the client registry (`Renderer::registry`) does not emit
  `per_unit`, so `fixed` choices imported from WAPF `qt` (per-unit fixed)
  also preview flat but charge per unit on choice fields.

Only the imported `expr*[qty]` pair is consistent today: `formula_raw`
drives the browser line-level math while the stripped `formula` drives the
server per-unit math, and both land on `expr × qty` at line level — which is
what this lane proves for `sumQty`. A general fix would need a pricing-model
decision (e.g. honoring `per_unit`/`qty_based` for formula pricing plus a
registry field); it is intentionally out of scope here and stays a ledger
item.

## Commands

```sh
# lint + unit
php -l includes/Engine/Calculator.php
node --check assets/js/opf-frontend.js
vendor/bin/phpunit                       # 283 tests / 1112 assertions — pass
node --test tests/js/opf-sumqty.test.cjs # 2 tests — pass
node --test tests/js/*.test.cjs          # 14 tests — pass

# WAPF oracle (installed 3.1.5 source)
OPF_SUMQTY_E2E_ALLOW=1 OPF_SUMQTY_E2E_MODE=oracle \
  OPF_WAPF_SUMQTY_SOURCE=/tmp/opf-sumqty-woo-wordpress/wp-content/plugins/advanced-product-fields-for-woocommerce-extended \
  wp --path=vendor/sumqty-wordpress eval-file bin/e2e-sumqty-browser-fixture.php

# serve + full browser/cart/order proof (from /tmp for playwright)
wp --path=vendor/sumqty-wordpress server --host=127.0.0.1 --port=8182 &
node /tmp/opf-sumqty-lifecycle-20261002/bin/e2e-sumqty-browser.cjs
```

## Remaining limits

The general formula per-unit/line-level gap above remains open. The proof
covers select-choice formula pricing and `image_quantity` sources for
`sumQty`; `sumQty` over repeated/section-cloned fields, `files()`-style
quantity sources, variable-product formula bases, and licensed WAPF UI paths
are not covered by this lane. The capability row remains partial until the
ledger owner accepts this slice.
