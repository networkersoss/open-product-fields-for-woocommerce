# WAPF 3.1.6 → 3.2.2 changelog crosswalk

Date: 2026-10-06 · Repo: `open-product-fields-for-woocommerce`, branch `master`.

This records every WAPF entry published after 3.1.5 and checks it against OPF's
real code and tests. The installed WAPF package on this host is **Extended 3.1.5**
only; no 3.2.x package is available (owner-confirmed), so entries are graded
against OPF's implementation and its tests, not against a WAPF 3.2 runtime.

## Sources

| Edition | Fetched file | Latest entry it carries |
|---|---|---|
| Extended | `/tmp/cl-ext.html` | 3.2.1 (2026-06-27) |
| Pro | `/tmp/cl-pro.html` | 3.2.2 (2026-06-29) |

3.2.2 ("blank Product Fields admin page") exists only in the Pro changelog; the
Extended changelog stops at 3.2.1. Both pages carry the same 3.1.6/3.1.7/3.2/3.2.1
entries.

## Crosswalk

Test results are the real output of the focused run named in the Test column.
`phpunit` = `vendor/bin/phpunit --no-coverage --filter <class> tests/Unit`;
`node` = `node --test <file>` (or the aggregate `node --test tests/js/*.cjs`).

| Version | Changelog item | OPF code (file:line) | Test | Real result | Verdict |
|---|---|---|---|---|---|
| 3.1.6 | Cards with quantity inputs get more conditional logic options | `includes/Engine/Evaluator.php:147-226` (qty-selector rule semantics over a `{slug: qty}` map), `includes/Engine/ChildProductSelection.php:16-30`, `assets/js/opf-frontend.js:126-165` (`qtyMapOf`/`qtyRulePasses`) | `tests/Unit/CardsConditionalsSchemaTest.php`; `tests/js/opf-qty-card-conditionals.test.cjs` | phpunit OK 13 tests/29 assertions; node 1 test pass/0 fail | covered |
| 3.1.6 | Fix double tax pricing on some Select elements | `includes/Service/PricingHints.php:122-146` (`maybe_add_tax` applies `wc_get_price_including_tax`/`_excluding_tax` once per hint; percent stays percent-derived) | `tests/Unit/PricingHintsTest.php` (`test_cart_tax_paths_use_woocommerce_tax_display_cart`, line 372; asserts exactly 1 incl call, 0 excl) | phpunit OK 33 tests/79 assertions | covered |
| 3.1.7 | Harden security around file upload and deletion (incl. order-admin delete) | `includes/Service/Uploads.php:24` (`admin_post_opf_delete_order_uploads`), `:68` (same-origin + nonce gate on native upload), `:114-132` (`manage_woocommerce` + nonce on order delete), `:187-194` (session nonce permission callback), `:215-237` (`realpath` containment, symlink rejection) | `tests/Unit/UploadServiceTest.php`, `tests/Unit/UploadOrderClaimTest.php`, `tests/Unit/UploadReissueTest.php` | phpunit OK 8/38, 5/15, 15/44 | covered |
| 3.1.7 | Fix text-swatch corner radius not saving | `includes/Service/Admin/Settings.php:29,138-141,222-227` (sanitize callback keeps the last valid radius), `includes/Service/Renderer.php:1180-1186` (emits `--opf-text-swatch-radius` only when configured) | `tests/Unit/TextSwatchRadiusTest.php` | phpunit OK 6 tests/28 assertions | covered |
| 3.1.7 | Fix informational-calculation "result format" | `includes/Engine/FieldGroup.php:621-640,784-785` (normalizes `result_format` number/none + `result_text`), `includes/Service/CartIntegration.php:976-1004` (WAPF `formatNumber` parity, verbatim for `none`), `includes/Service/Renderer.php:1713-1717` | `tests/Unit/CalcFieldTest.php`, `tests/Unit/CalculationFieldTest.php`; `tests/js/opf-calc-field.test.cjs`, `tests/js/opf-calc-builder.test.cjs` | phpunit OK 10/49 and 9/31; node 8 pass/0 fail and 1 pass/0 fail | covered |
| 3.1.7 | Modern file uploader turned on by default (unless unchecked) | `includes/Service/Admin/Settings.php:155-162` (checkbox id `opf_modern_uploader`, default `yes`), `includes/Service/Uploads.php:36-38` (`modern()` reads `opf_upload_ajax`/`wapf_upload_ajax`, default `no`), `includes/Service/Renderer.php:1722` | no focused test reads the settings option; executed probe `/tmp/opf-modern-probe.php` | **GAP** — probe: no options → `modern=false`; `opf_modern_uploader=yes` → `false`; `opf_upload_ajax=yes` → `true`. The exposed toggle is not read and the runtime default is off | **GAP** |
| 3.1.7 | Harden output escaping | Escaped at every sink by construction, e.g. `includes/Service/Renderer.php:1855` (input attrs), `:744,1125-1134,1265,1859`; full inventory in `docs/PLUGIN-CHECK-TRIAGE-2026-10-06.md` | `tests/Unit/RendererParagraphTest.php:10` (raw static content escaped); `tests/Unit/CardsZoomMarkupTest.php:88` (`<script>` stripped); `docs/PLUGIN-CHECK-TRIAGE-2026-10-06.md` | phpunit OK 5 tests/26 assertions (RendererParagraphTest); 5/18 (CardsZoomMarkupTest) | covered |
| 3.1.7 | Increase minimum WooCommerce to 7.0 | `open-product-fields-for-woocommerce.php:48` (`OPF_MIN_WC = '9.0'`) + gate at `:111,135` | `tests/Unit/MinimumPlatformTest.php` | phpunit OK 5 tests/18 assertions; OPF floor (9.0) is stricter than WAPF's 7.0 | covered |
| 3.2 | Display true-false/checkbox as switches | `includes/Service/Renderer.php:744,1125-1134,1265,1859`; `includes/Engine/FieldGroup.php:697-698`; `includes/Service/WapfExporter.php:55,296-299`; `includes/Engine/WapfMapper.php:265-268`; `includes/Service/WapfWxrExporter.php:109` | `tests/Unit/RendererSwitchControlTest.php`, `tests/Unit/WapfExporterSwitchControlTest.php`; `tests/js/opf-builder.test.cjs` | phpunit OK 2/6 and 11/33; node 13 tests pass/0 fail | covered |
| 3.2 | Checkbox `columns` setting | `includes/Service/Renderer.php:1130-1165`; `includes/Engine/FieldGroup.php:1090-1093`; `includes/Engine/WapfMapper.php:1437-1467`; `includes/Service/WapfExporter.php:468-469` | `tests/Unit/RendererCheckboxColumnsTest.php`, `tests/Unit/FrontendChoiceGridCssTest.php`; `tests/js/opf-builder.test.cjs` | phpunit OK 1/4 and 4/19; node 13/13 pass | covered |
| 3.2 | Search by title on the Field groups admin screen | `includes/Service/FieldGroups.php:172-186` (`register_post_type('opf_field_group')`, `show_ui`, `supports => ['title']`, `search_items`) — WordPress core list search is the whole implementation | `bin/e2e-admin-title-search-browser-test.mjs` (no unit test; core WordPress owns the behavior) | 8/8 browser checks in `docs/compatibility/WAPF-GROUP-ADMIN-TITLE-SEARCH-EVIDENCE-2026-10-02.md`; 0 page errors | covered |
| 3.2 | `step` setting for number fields | `includes/Service/Renderer.php:1766-1778` (`step` attr from explicit step or integer/decimal mode), `includes/Engine/FieldGroup.php:1254-1255` | `tests/Unit/RendererNumberModeTest.php`; `tests/js/opf-builder.test.cjs` | phpunit OK 1/3; node 13/13 pass | covered |
| 3.2 | Weight setting can hold simple formulas (Extended) | Builder stores it: `assets/js/opf-builder.js:1220-1223`; `includes/Engine/FieldGroup.php:686-689` normalizes `weight_formula`. **The cart-weight engine does not read it:** `includes/Engine/Calculator.php:414-545` (`field_weight()`/`weight_expression()`) reads only `weight` and `floatval()`s the substituted string | `tests/Unit/FieldGroupSchemaTest.php:242-247` (schema only), `tests/js/opf-builder.test.cjs:163` (save round-trip only) | **GAP** — executed probe `/tmp/opf-weight-probe.php`/`-probe2.php`: `normalize_field`/`new FieldGroup` keep `weight_formula` and produce no `weight` key; `Calculator::field_weight([... 'weight_formula' => '[field.extra] * 2'], 3, 1) === 0`. The formula is authorable and round-trips but never affects cart/shipping weight | **GAP** |
| 3.2 | Product-types condition shows only allowed active types | `includes/Service/Admin/Builder.php:84` (`wc_get_product_types()`), `:246-256` (include/exclude selects iterate that list) | `tests/Unit/EvaluatorTest.php` (product_type subject semantics; no focused builder-list test) | phpunit OK 20 tests/59 assertions | covered |
| 3.2 | Number field validates whole-number vs decimals and step | `includes/Engine/FieldValue.php:342-366` (integer-mode fraction rejection, min-anchored step), `includes/Service/Renderer.php:1766-1778` | `tests/Unit/FieldConstraintsTest.php` (`"Size" must use increments of 2.`), `tests/Unit/RendererNumberModeTest.php` | phpunit OK 6/14 and 1/3 | covered |
| 3.2 | Accessibility for styled checkboxes and radio buttons | `includes/Service/Renderer.php:1329-1335` (native input keeps name/type, `.wapf-custom` span is `aria-hidden`), `includes/Service/Admin/Settings.php:116-131` (opt-in accent/border) | `tests/Unit/RendererStyledChoiceTest.php` | phpunit OK 5 tests/25 assertions | covered |
| 3.2 | Skip add-to-cart validation when a product type is not supported (perf) | OPF short-circuits for linked-child lines, the `opf_skip_validation` filter (bridged to `wapf/skip_cart_validation` in `includes/Compat/WapfHooks.php:126-128`) and the non-visible transition gate: `includes/Service/CartIntegration.php:122-136`. There is no "unsupported product type" branch because OPF validates only products that carry OPF data | `tests/Unit/WapfHooksBridgeTest.php` (filter bridge) | phpunit OK (bridge suite included in full run); no observable customer contract to compare | not applicable (WAPF internal dispatch optimization; no OPF branch to mirror) |
| 3.2 | Position the minus sign on negative options totals | `assets/js/opf-frontend.js:2668-2678` (sign before the fully grouped amount), `includes/Service/Renderer.php:187` (`wc_price( abs(...) )`) | `tests/js/opf-pricing.test.cjs:32-42` | node 34 tests pass/0 fail (`-$1,234.50`, suffix currency `-1.234,50 €`, zero-decimal `-¥1,234`) | covered |
| 3.2 | iOS file-upload validation scrolls to the field | No OPF code suppresses or re-implements native validation scrolling; OPF uses the browser's own file input and its own status live region | none | not applicable — WAPF's custom uploader JS bug; OPF has no equivalent scroll-suppressing path | not applicable |
| 3.2.1 | Enlarge image+quantity choices on hover | `includes/Service/Renderer.php:1037` (`image_quantity_zoom` wrapper class), `:1493,1543` (`opf-swatch-zoom-preview`) | `tests/Unit/RendererImageSwatchZoomTest.php:29`; `tests/js/opf-builder.test.cjs:528-537` | phpunit OK 2/6; node 13/13 pass | covered |
| 3.2.1 | Enlarge products-as-images on hover | `includes/Service/Renderer.php:982-983` (`opf-child-product-image-zoom` when `field['image_zoom']`), `includes/Engine/FieldGroup.php` normalization via `LinkedProductsSchemaTest` | `tests/Unit/LinkedProductsSchemaTest.php:135-163`; `tests/Unit/CardsZoomMarkupTest.php`; `tests/Unit/CardsZoomExportTest.php` | phpunit OK 18/51 (LinkedProductsSchemaTest), 5/18 (CardsZoomMarkupTest), 5/14 (CardsZoomExportTest) | covered |
| 3.2.1 | Improve date-picker accessibility | `assets/js/opf-frontend.js:395-421` (dialog role, `aria-controls`, labelled grid) | `tests/Unit/RendererDateTest.php`; `tests/js/opf-date-bounds.test.cjs`; `bin/e2e-date-blackout-browser-test.mjs` | phpunit OK 1/1; node 4 tests pass/0 fail; 26/26 browser checks (`docs/compatibility/DATE-ACCESSIBILITY-EVIDENCE-2026-10-01.md`) | covered |
| 3.2.1 | Fix "disabled days" not always saved | `includes/Engine/FieldGroup.php:1166-1177` (normalizes `disabled_weekdays`), `includes/Engine/WapfMapper.php:531-559`, `includes/Service/Renderer.php:1837-1841`, `includes/Engine/FieldValue.php:416-420` | `tests/Unit/DateBlackoutTest.php`, `tests/Unit/DateFieldTest.php` | phpunit OK 3/13 and 10/52 | covered |
| 3.2.1 | Option discounts comply with configured tax settings | `includes/Service/CartIntegration.php:559-573` (`base_only_percent_discount` keeps the discount on the eligible base share; tax-inclusive unit prices are handled in the same math), wiring at `:538-557` | `tests/Unit/CouponDiscountTest.php` (`test_tax_inclusive_unit_prices_keep_coupon_discount_on_the_base_share`, line 21), `tests/Unit/CouponSettingsTest.php` | phpunit OK 5/10 and 3/6 | covered |
| 3.2.1 | Auto-update fix | — | — | not applicable — WAPF's own updater/licensing path; OPF bundles no self-updater and ships through the normal plugin update channel | not applicable |
| 3.2.1 | Minor admin CSS fixes for WordPress 7.0 | — | — | not applicable — cosmetic WAPF admin styling fix; OPF's admin CSS (`assets/css/opf-builder.css`) is its own surface and makes no WP-7.0-specific claim | not applicable |
| 3.2.2 (Pro) | Fix blank Product Fields admin page | — | — | not applicable — a bug in WAPF's own "Product Fields" admin screen. OPF's Field Groups screen is a separate implementation (`includes/Service/FieldGroups.php`, `includes/Service/Admin/Builder.php`) and the name overlap is coincidental | not applicable |

## GAPs

> **Both GAPs below were fixed on 2026-10-06.** The analysis is kept verbatim as
> the audit record; see [weight/upload gap-fix evidence](WEIGHT-AND-UPLOAD-GAP-FIX-2026-10-06.md)
> for the before/after probes, the implemented contract and the test results.
> The ledger rows `WAPF-PRICE-FORMULA-WEIGHT`, `WAPF-COMMERCE-WEIGHT` and
> `WAPF-UPLOAD-AJAX-UI` were updated in the same pass.

Two changelog items are **not** covered by OPF. Both are real in this tree and
were reproduced by executed probes, not read-only inference.

### GAP 1 — Extended 3.2 weight formulas are stored but never evaluated

**Resolved 2026-10-06** — `weight_formula` is evaluated as an alias of the
canonical `weight` expression and both are evaluated arithmetically by the
shared pricing parser. The findings below are the pre-fix state.

- Authoring/storage: `assets/js/opf-builder.js:1220-1223` (input titled
  "Extra product weight formula") and `includes/Engine/FieldGroup.php:686-689`
  (bounded `weight_formula`, max 4096 chars).
- Cart engine: `includes/Engine/Calculator.php:414-545`
  (`field_weight()` / `weight_expression()`) reads only `$field['weight']` and
  `floatval()`s the `[x]`/`[qty]`-substituted string. It never reads
  `weight_formula`. `CartIntegration::addon_weight()` (`includes/Service/CartIntegration.php:472-520`)
  is the only caller.
- Probe: `Calculator::field_weight( FieldGroup::normalize_field([... 'weight_formula' => '[field.extra] * 2']), 3, 1 ) === 0`.
- Consequence: an OPF-native field authored through the builder's weight-formula
  box contributes zero weight; only WAPF-imported scalar `weight` expressions
  work, and those are `floatval`-based (3.1.5 semantics), so arithmetic in a
  scalar `weight` is also not evaluated (`'[x] * 2'` at `x=3` yields `3`, not `6`).
- Note for the reviewer: ledger row `WAPF-PRICE-FORMULA-WEIGHT` currently claims
  the server "evaluates arithmetic" for weight. That row was **not** edited in
  this lane (out of scope), but the claim conflicts with the probe above and
  should be reconciled.

### GAP 2 — "Modern file uploader default-on" is not wired

**Resolved 2026-10-06** — `Uploads::modern()` now reads the saved
`opf_modern_uploader` toggle first, then the legacy values, then WAPF 3.1.7's
on-by-default. The findings below are the pre-fix state.

- The settings screen registers `opf_modern_uploader` with `default => 'yes'`
  (`includes/Service/Admin/Settings.php:155-162`).
- The render path reads a different option: `Uploads::modern()` uses
  `get_option( 'opf_upload_ajax', get_option( 'wapf_upload_ajax', 'no' ) )`
  (`includes/Service/Uploads.php:36-38`), consumed at
  `includes/Service/Renderer.php:1722`.
- Probe results: no options → `false`; `opf_modern_uploader=yes` → `false`;
  `opf_upload_ajax=yes` → `true`.
- Consequence: the modern uploader is off by default (WAPF 3.1.7 turned it on)
  and the checkbox that claims to control it is inert. The existing browser/UI
  proofs enabled it by writing `opf_upload_ajax=yes` directly through
  `bin/e2e-upload-ui-fixture.php`, which is why the mismatch was not caught.

## Verification run (this lane)

| Command | Result |
|---|---|
| `vendor/bin/phpunit --no-coverage` | `Tests: 1086, Assertions: 4590, PHPUnit Deprecations: 2` — 0 failures |
| `node --test tests/js/*.cjs` | `tests 130 / pass 130 / fail 0` |
| Focused `phpunit --filter <class> tests/Unit` | all named classes OK (per-row results above) |
| Focused `node --test <file>` | all named `.cjs` files pass (per-row results above) |

The 2 PHPUnit deprecations are pre-existing and appear in every focused run; they
are unrelated to the items in this crosswalk.

## No 3.2.x package available

The host has WAPF Extended 3.1.5 installed and no 3.2.x package (owner-confirmed).
That bounds what can be byte-compared: serialized key names/shapes introduced
after 3.1.5 (for example the switch option) cannot be diffed against a WAPF 3.2
payload. The switch row keeps its `supported` grade on the public Pro 3.2
changelog plus OPF's own stored key and the passing export→reimport round trip;
see `WAPF-SWITCH-CONTROL-EXPORT-ROUNDTRIP-2026-10-06.md`.
