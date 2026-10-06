# OPF 1.0 Burn-down Tasks

## Current checkpoint — 2026-10-06, edition closure at `fd42ab5` (`master`)

- [x] Closed the last four `partial` edition rows, each on a committed evidence doc cited in its ledger row:
  - `521ff0c` fix(export): round-trip WAPF `switch_control` for toggle and checkbox fields → `WAPF-FIELD-TRUE-FALSE-SWITCH` (`supported`; Tools JSON + WXR round trip, 11 tests/33 assertions; caveat kept: installed WAPF 3.1.5 has no switch key, so the key rests on the published Pro 3.2 changelog plus OPF's own stored key).
  - `2a4c27a` test(subscriptions): real unmodified WooCommerce Subscriptions 9.2.0 → `WAPF-PRODUCT-SUBSCRIPTION` (`supported`; 85/85 assertions, no compounding, renewal + order-again + partial refund).
  - `638ed17` docs(wpml) + `d294539` fix(wpml): guard string-package registration → `WAPF-LOCALE-WPML` (`supported`; coherent set WPML core 4.2.8 + String Translation 2.10.6 + Translation Management 2.8.7, 13 `icl_strings` rows, ES storefront render, multilingual cart/order; D1 guard now prevents the silent no-op).
  - `fd42ab5` fix(frontend): resolve image-change fallback to the selected variation image → `WAPF-INTERACTION-IMAGE-CHANGE` (`supported`; variable product with per-variation galleries, 56/56 checks against live WAPF 3.1.5, `last` mode covered).
  - Supporting commits: `74321e0` chore(release) adds `docs/RELEASE-PROVENANCE-2026-10-06.md` (0.1.0 packaging/provenance record + `tasks/` archive-leak fix); `1cf4bd9` feat(import): file-based WAPF Tools JSON import with dry-run review.
- [x] Recount the 131 edition rows from the ledger: **124 supported, 7 supported with documented difference (the owner-accepted D1–D5 set), 0 partial, 0 baseline-supported, 0 gap, 0 needs-audit** — accepted parity 131/131. The six `WAPF-ADDON-*` rows stay `partial` and outside the denominator (137 ledger rows total).
- [x] Live trackers refreshed: `docs/compatibility/WAPF-CAPABILITY-LEDGER.md` (progress snapshot, G2/G3/G4 rows) and `docs/compatibility/OPF-1.0-ROADMAP.md` (progress table, per-row state, non-negotiable goalposts). G2 met; G3 met for every applicable edition row.
- [ ] G4: produce the 1.0 version/archive, execute the WordPress.org/commercial marketplace submission, and resolve the `/tmp`-staged evidence set (`docs/RELEASE-PROVENANCE-2026-10-06.md` §9).

Historical (2026-10-04 snapshot) — the counts and row states in this paragraph
are superseded by the ledger and the table above.

The exact public tip was verified with `git ls-remote` on 2026-10-04. The
category child-price lane found and fixed a real mismatch (selected preview
$40, checkout $92; preview and checkout now agree). Category fixed/none price
parity is accepted for the scoped row after source/import, all eight subtypes,
classic/Store API, order, refund, stock and Order again proof. The broader child
products row remains partial. Image-quantity zoom testing also reproduced a WAPF 3.1.5 + OPF
coexistence add-to-cart fatal in a disposable clone; no production state was
touched. Its exact trace and repro are recorded in the runtime evidence. The
prior 2026-10-01 workstream table below is historical and is not the live agent
roster.

## Parallel workstream ledger

| Task | Acceptance criteria | Owner / worktree / model (effort) | Verified | Blocked | Waivers |
| --- | --- | --- | --- | --- | --- |
| Date-option import parity | Map source-confirmed date constraints; mapper tests prove supported values and review unsafe ones | `/root/date_import`, `gpt-6-luna` (medium), `/tmp/opf-date-import`, 0 strikes | Implemented as `fdfffa4`; main `WapfMapperTest` passed 31 / 137 assertions; full suite passes | No | Unsupported settings retained for manual review |
| `sumQty(field ID)` parity | Match Extended source and actual OPF quantity model; server/browser and lifecycle evidence | `/root/sumqty`, `gpt-6-luna` (medium), `/tmp/opf-sumqty`, 0 strikes | Native model `4ef9bd5`, import/bounds `255f10a`, Woo lifecycle E2E `46ec85a`; main rerun passed; full suite 184 / 663, JS 7/7 | No | Browser interaction and import round-trip remain separate; parity row remains partial |
| `[price.ID]` browser parity | Chromium verifies prior-group price, hidden source, totals, and no browser errors | `/root/priceid_browser`, `gpt-6-luna` (medium), `/tmp/opf-priceid-browser`, 0 strikes | `264c72b` isolated proof and `ad202cc` WordPress-page proof; root reran 7 functional checks plus fixture/options restoration | No | Exact WAPF 3.2.1 source comparison and duplicate-ID behavior remain |
| WOOCS currency integration | Implement the source-confirmed adapter and focused coverage for the known OPF gap | `/root/opf_woocs`, `gpt-6-luna` (medium), isolated worktree from `264c72b`, 0 strikes | Adapter `db86587` plus runtime `8b3acf4`; main 184 / 663 PHP, JS 7/7, fake-WOOCS + real-Woo cart/order, jQuery/Chromium currency/variation/reset proof pass | No | Actual WOOCS hook ordering/fixed-price preview, linked-product and pricing-hint end-to-end paths remain open; row stays partial |
| Duplicate-group admin proof | Authenticated browser verifies list action, dispatch, and reference remapping in a disposable WP clone | `/root/opf_group_dup_browser`, `gpt-6-luna` (medium), isolated worktree from `799a411`, 0 strikes | Cherry-picked as `8b24765`; main rerun passed 8 Chromium assertions and cleanup; full suite 172 / 615 passes | No | Current Extended comparison, variable/gallery refs, and product-duplication hook remain separate; parity row stays partial |
| Image-quantity import + bounds parity | Map source-confirmed `image-swatch-qty` settings; represent WAPF's 999999 bound and aggregate max_choices natively | `/root/opf_qty_import`, `gpt-6-luna` (medium), isolated worktree from `4ef9bd5`, 0 strikes | `255f10a` mapped per-choice bounds; fresh WAPF source check found max_choices is an aggregate sum cap, so current mapping is incomplete and a failing acceptance case is assigned below | No | Must prove aggregate cap in server/client/import lifecycle; affected row stays partial |
| `sumQty` commerce lifecycle | Disposable Woo cart/order E2E proves quantity input sanitation, formula result, price, and order persistence | `/root/opf_sumqty_woo_e2e`, `gpt-6.1-sol` (high), fresh isolated clone/worktree, 0 strikes | Cherry-picked as `46ec85a`; main independently reran 9 invalid Store API/classic cases, cart 28.00, two-unit order 56.00, boundary prices 13.00/41.00, and fixture cleanup; full suite 176 / 633 passes | No | Browser input interaction/import round-trip remain separate; parity row remains partial |
| WOOCS runtime parity | Wire currency adapter and prove base/option/formula/cart/browser conversion paths against installed source | `/root/opf_woocs_runtime`, `gpt-6.1-sol` (high), fresh worktree from current branch, 0 strikes | Cherry-picked as `8b3acf4`; main 184 / 663, JS 7/7; fake-WOOCS cart/order and browser tests independently rerun on disposable clones | No | Third-party WOOCS itself unavailable; fixed-price differences, linked products, and price hints still unverified |
| Image-quantity import lifecycle + aggregate cap | Implement WAPF aggregate `array_sum(quantities) <= max_choices`, preserve per-choice limits/import, prove cart/order and sumQty | `/root/aggregate_cap_v2`, `gpt-5.6-sol` (medium), `/tmp/opf-sumqty-woo-e2e`, 0 strikes | Source audit found WAPF 3.1.5 `class-cart.php:300–309` enforces aggregate max; implementation and failing→passing acceptance proof underway | No | Row stays partial until aggregate server/browser/import lifecycle is verified |
| WPML localization integration | Close the known WPML gap for translated local/global fields and variations against WPML contract | `/root/opf_wpml`, `gpt-6-astra` (high), `/tmp/opf-wpml`, 0 strikes | Commits `f96d892` + `d0d8208` pushed; independent full suite 198 / 735 passes after Aelia integration | No | Row stays partial: no WPML runtime; imported-group ownership and translated commerce lifecycle open |
| Aelia currency integration | Close the known Aelia gap across currency bases, option/formula pricing, cart, and browser totals | `/root/finish_aelia_lane`, `gpt-5.6-sol` (high), `/tmp/opf-aelia`, 0 strikes | Commit `b4f259b` pushed; root reran full suite 198 / 735, real Woo fake-API cart/order lifecycle, and Chromium totals/variation/reset/rate-format proof; source review confirms WAPF 3.1.5 contract | No | Row stays partial: commercial plugin unavailable, linked-product and price-hint paths unverified |
| FOX currency compatibility | Verify WAPF FOX/WOOCS API equivalence; add specific contract coverage without changing shared WOOCS runtime | `/root/fox_currency`, `gpt-6.1-sol` (high), `/tmp/opf-fox-currency`, 0 strikes | Installed WAPF registry maps FOX/WOOCS to shared adapter; implementation/test evidence in progress | No | Real FOX plugin proof may remain open |

### Historical orchestration control — 2026-10-01 snapshot

The current wave uses three writers plus the root integrator (the four-agent
concurrency limit). This roster and its row counts describe the 2026-10-01
snapshot only. The current roster and current evidence are recorded above and
in the capability ledger. Only accepted ledger rows and all G2–G4 gates count
toward 1.0.
