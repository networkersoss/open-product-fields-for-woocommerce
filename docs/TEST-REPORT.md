# OPF Historical Test Report — September 12, 2026

This is a record of the followersya cutover test campaign, not a current
release compatibility guarantee. Refer to [CAPABILITIES.md](CAPABILITIES.md)
for the implemented OPF 0.1.1 feature set and its exclusions.

Full-suite stability: **multiple consecutive all-green iterations** via the
single runner `bin/run-all-tests.sh <site> <export.json> [iterations]`
(exits non-zero on any failure — CI-ready). Latest run: **10/10 suites,
zero flakes.**

## Suites (all green)

| # | Suite | What it proves | Checks |
| --- | --- | --- | --- |
| 1 | Lint (PHP + JS) | No syntax regressions | all files |
| 2 | PHPUnit (`tests/Unit`) | Pricing math + formula safety, flat-vs-per-unit fees, conditionals, placement rules, WAPF parser repair, mapper fidelity | 33 tests / 97 assertions |
| 3 | CLI E2E (`bin/e2e-test.php`) | Full lifecycle: placement, capture, hidden-field stripping, required validation, percent/fixed/formula/multi-checkbox pricing, display, Store API add-item, Store API **checkout → real order** (meta + totals), order-again, compat markup, **flat-fee qty invariance** | 44 |
| 4 | Real HTTP (`curl`) | Product page serves fields + compat classes + `data-wapf-price` + registry + `wapf_config` + module tag; real form POST add-to-cart; Store API cart JSON priced and labeled | page + cart |
| 5 | jsdom behavior (`bin/e2e-jsdom-test.mjs`) | Real frontend module on real served HTML: conditional reveal/hide, `wapf-checked` toggling, hidden-field handling | 10 |
| 6 | Theme compat (`bin/e2e-theme-compat-test.mjs`) | The **production theme's verbatim `quantity.js` addon-total logic** (verbatim `isAddonActive` + `addonsTotalAtQty`) over OPF markup: finds `.wapf-field-input [data-wapf-price]`, honors `wapf-hide`, fixed = flat fee, percent scales with qty | 10 |
| 7 | **Real-browser UI** (`bin/e2e-browser-test.mjs`, Playwright Chromium) | Full customer journey in actual Chromium: product page → click Boost (conditional reveals, screenshot) → real form add-to-cart → client-rendered block cart shows "Delivery speed: Boost / Boost instructions: rush order please" at **$107.00** server-priced → block checkout shows the selection → Place Order → **order confirmation displays the selections** — zero JS page errors. Screenshots at every step. | 16 |
| 8 | Import verification (`bin/e2e-import-test.php`) | Clean-state import of the **real production WAPF corpus**: 747 groups, 21 charset-corrupted payloads resurrected, pricing/placement fidelity, formula qty-compensation stripped, idempotent re-import | 9 |
| 9 | Activation cycle + uninstall (`bin/e2e-uninstall-test.php`) | Deactivate/reactivate preserves data; uninstall removes plugin data; **historical order meta survives** | 9 |
| 10 | Production dry-run (read-only, live DB) | `imported=657 skipped=0 repaired=21 unparseable=0 needs_review=0` | — |

## Migration-correctness findings from this test campaign

1. **Fixed-fee semantics** (caught by running the production theme's own
   addon math over OPF markup): WAPF "fixed" is a **flat fee per line**
   (`qty_based` is an opt-in; theme math `default: d = t` confirms). OPF
   initially modeled fixed as per-unit — fixed on a 1000-qty order would
   have charged 1000×. Fixed now: **flat per line, `per_unit` opt-in**;
   percent and formula scale with quantity (WAPF parity). Covered by unit
   tests + E2E `flat-fee: fixed addon does not multiply by qty`.
2. `plugins_loaded` boot order, script-module inline scripts, dropped
   `data-opf-field`, Store API static payload leak, raw-SQL meta reads,
   pre-unserialized meta arrays — all found by earlier test phases, all
   fixed with regression coverage.

## What this test campaign did not establish

This campaign did not establish support for capabilities outside
`CAPABILITIES.md`, including unimplemented field types and third-party
integrations. The remaining followersya rollout work also required:

- Look at the pages (visual/polish sign-off — screenshots from the browser
  run are in `/tmp/ui-*.png` during the test run).
- Decide the staging → production cutover moment (runbook Phase 2).
