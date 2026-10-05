# Locale lane evidence — OPF ↔ WAPF Extended 3.1.5 parity

Rows: `WAPF-LOCALE-TRANSLATION`, `WAPF-LOCALE-BUNDLED-STRINGS`, `WAPF-LOCALE-ADMIN-STRINGS`, `WAPF-LOCALE-WPML`, `WAPF-LOCALE-POLYLANG`

Worktree: `/tmp/opf-lane-locale` (branch `lane/locale`) · Clone: `/tmp/opf-image-locale-wp` (`http://127.0.0.1:8313`) · Reference: `wp-content/plugins/advanced-product-fields-for-woocommerce-extended` (sw-wapf domain, ships `languages/sw-wapf.pot` ~810 msgids + 23 .mo files)

> **Correction (2026-10-05):** five artifacts cited below never existed in the
> repository and are unrecoverable — see
> [Correction — missing artifacts (2026-10-05)](#correction--missing-artifacts-2026-10-05).
> The WPML and Polylang claims are now re-proven in-tree; see
> [LOCALE-POLYLANG-WPML-EVIDENCE-2026-10-05.md](LOCALE-POLYLANG-WPML-EVIDENCE-2026-10-05.md).

## Row outcomes

| Row | Outcome |
|---|---|
| LOCALE-TRANSLATION | **fixed+proven** — added `load_plugin_textdomain()`; PHP strings render translated on the clone |
| LOCALE-BUNDLED-STRINGS | **fixed+proven** — generated `languages/open-product-fields-for-woocommerce.pot` (313 msgids, 0 make-pot warnings); frontend JS localized via `window.OPF_I18N`; translator comments + ordered placeholders added |
| LOCALE-ADMIN-STRINGS | **fixed+proven** — ~190 builder strings wrapped in `wp.i18n.__()`; `wp_set_script_translations` emits `setLocaleData` block on the real `opf_field_group` edit screen |
| LOCALE-WPML | **proven** — existing `WpmlIntegration` exercised end-to-end on the real WP stack against a faithful WPML hook-surface stub (WPML is commercial, unavailable); 23/23 runtime assertions + 440/440 PHPUnit |
| LOCALE-POLYLANG | **fixed+proven** — added `pll_get_post_types` CPT registration (parity gap vs WAPF); per-language rendering + `lang`/`!lang` equivalence proven with real Polylang 3.x; 11/11 assertions |

## What was proven on the clone

### PHP + catalog (`es_ES`)

```
locale=es_ES, loaded=true
product_total=Total del producto   choose=Elige una opción   groups=Grupos de campos
js_date=Elige fecha   upload=Subida completada.   builder=Color de muestra
errors=El campo «Color» es obligatorio.
```

### Frontend JS (`window.OPF_I18N`) — `opf-i18n-frontend-es.json`

`site_locale: es-ES`, `choose_date: "Elige fecha"`, `previous_month/next_month`, `calendar_dates`, `weekday_abbreviations` (core pack: Dom…Sáb), upload strings (`upload_complete: "Subida completada."`), `remove_row`/`uploading %s`/`remove_file %s` placeholders.

### Admin builder JS — `builder-jed-emitted.json`

Real `opf_field_group` edit page emits `<script id="opf-builder-js-translations">` → `wp.i18n.setLocaleData(localeData, "open-product-fields-for-woocommerce")` with **191 msgids** (191 in the generated JED). Spot-checks: `Save→Guardar`, `+ Add field→+ Añadir campo`, `Swatch color→Color de muestra`, `Search for a product…→Buscar un producto…`, `Condition field→Campo de la condición`, `Is greater than→Es mayor que`, `Card slot %d→Hueco de tarjeta %d`, `Saving…→Guardando…`.

### POT audit (`wp i18n make-pot`)

- First run: 13 warnings (missing translator comments, unordered placeholders) → `makepot-warnings.txt`
- Final run: **313 msgids, 0 warnings** → `makepot-final-warnings.txt` (empty), catalog → `opf-final.pot`
- Deliberate foreign domain kept: `__('In stock','woocommerce')` in LinkedProducts (reuses Woo's own translation).

### WPML (`wpml-proof-output.txt` — 23/23 PASS)

Against `opf-wpml-stub.php` (mu-plugin implementing WPML's real hook surface): package kind registered (`open-product-fields`); saving a native group created a string package with 8 names (label/description/placeholder/choice/repeat add·del·label/html `VISUAL`); `wpml_translate_string` returned `es` translations at runtime while **slugs/pricing/field IDs stayed untouched**; `wpml_object_id` remapped `product:10→es:15867` and `product_cat:16→es:cat_es` inside `rule_groups` — so the translated group renders on the translated product only; imported groups with `_opf_wpml_source_language` are language-filtered and never re-registered under the admin language; `wpml_switch_language` invalidates freshness; `before_delete_post` fires `wpml_delete_package` (`15868/open-product-fields`).

Limitations (documented, no real WPML available): WPML String Translation editor UI untested; WCML order/item meta round-trip untested.

### Polylang (`polylang-proof-output.txt` — 11/11 PASS, real Polylang 3.x)

`pll_get_post_types` includes `opf_field_group` (excluded from settings screen — same shape as WAPF's `wapf_product` filter). Per-language posts: en-labeled group renders only under `pll_current_language()='en'`, `es` group only under `'es'` (its own label = the per-language translation), language-less group renders everywhere. Cache keyed per `pll_current_language('slug')` — `es` view saw the updated label immediately after re-save. `lang`/`!lang` WAPF semantics verified equivalent: `user_language in [es_ES]` matches only under `es`; `not_in` only under `en` — matching WAPF `class-conditions.php` (`lang`=equality, `!lang`=inequality, `pll_current_language('locale')` preferred, `ICL_LANGUAGE_CODE` fallback, `'default'` last) and the rules-lane fr_FR include/exclude proof.

## Worktree changes (uncommitted, as required)

| File | Change |
|---|---|
| `open-product-fields-for-woocommerce.php` | `load_plugin_textdomain()` in `opf_boot()` |
| `includes/Service/Assets.php` | `window.OPF_I18N` registry (28 frontend strings + `WP_Locale` weekday abbrevs) injected next to `OPF_FIELDS`; `wp_set_script_translations('opf-builder',…,'languages')` |
| `includes/Service/FieldGroups.php` | `pll_get_post_types` filter + `add_cpt_to_polylang()`; 2 translator comments |
| `includes/Service/LinkedProducts.php` | translator comments + `%1$s`/`%2$d` ordered placeholders |
| `assets/js/opf-frontend.js` | hardcoded strings → `OPF_I18N.*` with English fallbacks (dates, validation, remove labels) |
| `assets/js/opf-uploads.js` | upload status/progress/a11y strings → `OPF_I18N.*` |
| `assets/js/opf-builder.js` | guarded `__()` helper + ~190 UI strings wrapped `__('…','open-product-fields-for-woocommerce')`; non-translatable literals (formulas, numeric examples, placeholders) left raw |
| `languages/*.pot` (new) | 313-msgid catalog |
| `languages/*-es_ES.{po,mo,json}` (new) | neutral-Spanish proof catalogs used on the clone (test fixture for the runtime proof) |

No ledger/roadmap edits; no commits/pushes; no shared files touched beyond the lane-allowed set. `WpmlIntegration.php` needed no changes — it already implemented the full WPML surface (verified by runtime proof + existing unit tests).

## Commands / test counts

- `vendor/bin/phpunit` — **Tests: 440, Assertions: 1854, 0 failures** (1 PHPUnit deprecation notice, pre-existing)
- `tests/js/*.test.cjs` — all pass **except `opf-builder-auth.test.cjs`, which fails identically on HEAD** (`app.querySelector is not a function`, DOM-mock gap in the test harness; confirmed pre-existing by re-running against `git show HEAD:assets/js/opf-builder.js`; left untouched)
- `wp eval-file wpml-proof.php` — 23 PASS / 0 FAIL
- `wp eval-file polylang-proof.php` — 11 PASS / 0 FAIL
- `wp i18n make-pot` — 313 msgids, 0 warnings

## Clone cleanup vs baseline (`baseline-state.json` → `final-state.json`)

Removed: temp admin user 86, Polylang plugin (`wp plugin uninstall`) + its 4 leftover options, proof posts/terms/auto-draft, `opf_wpml_stub_store`, stub mu-plugin, `WPLANG` option (→ en_US default), `es_ES` core language pack. Remaining diff keys are zero-count post-status keys and a list-vs-dict `plugins` serialization artifact — same effective state. One note: two WC `fatal-errors` log lines (today's Polylang API TypeError) remain in `uploads/wc-logs/` alongside pre-existing entries from other lanes; left as diagnostic residue, not fixture data.

## Artifacts

- `baseline.php`, `baseline-state.json`, `final-state.json`, `current-state.json`
- `gettext-audit.md`, `makepot-warnings.txt`, `makepot-final-warnings.txt`, `opf-final.pot`
- `wpml-proof.php`, `wpml-proof-output.txt`, `opf-wpml-stub.php`
- `polylang-proof.php`, `polylang-proof-output.txt`
- `builder-jed-emitted.json`, `opf-i18n-frontend-es.json`
- `fill-es.py`, `wrap-builder-i18n.py`, `opf-es_ES.po`, `opf-es_ES.mo`, `opf-builder.js.orig`

## Correction — missing artifacts (2026-10-05)

This doc cites five artifacts that **do not exist in the repository and were never
committed**; they were staged only in a deleted `/tmp` clone and are unrecoverable:

| Cited artifact | Status |
|---|---|
| `wpml-proof.php` | **missing / unrecoverable** |
| `polylang-proof.php` | **missing / unrecoverable** |
| `wpml-proof-output.txt` | **missing / unrecoverable** |
| `polylang-proof-output.txt` | **missing / unrecoverable** |
| `opf-wpml-stub.php` | **missing / unrecoverable** |

The rest of the artifact list above (`baseline*.php/json`, `gettext-audit.md`,
`makepot-*.txt`, `opf-final.pot`, `builder-jed-emitted.json`, `opf-i18n-frontend-es.json`,
`fill-es.py`, `wrap-builder-i18n.py`, `opf-es_ES.*`, `opf-builder.js.orig`) is likewise
absent from the tree — only the shipped catalogs survive under `languages/`. Treat every
`/tmp`-staged artifact in this doc as unavailable.

### What the deleted artifacts claimed, and what proves it now

| Claim in this doc | Now covered by |
|---|---|
| WPML: package kind, string registration (8 names/types), lifecycle, runtime translation + target remap, ownership filtering, cache freshness | `bin/e2e-wpml-proof.php` + `bin/e2e-wpml-stub.php` (contract stub; WPML is commercial/absent) — **24 PASS / 0 FAIL / 3 SKIP**, staged at `docs/compatibility/locale-proof-20261005/wpml-proof.json` |
| WpmlIntegration unit contract (native package lifecycle, translated text/targets, import ownership, no admin-language fallback) | `tests/Unit/WpmlIntegrationTest.php`, `tests/Unit/ImporterWpmlOwnershipTest.php` (in-tree, no WordPress) |
| Polylang: `pll_get_post_types` CPT registration, per-language rendering, `lang`/`!lang` semantics, import language | `tests/Unit/PolylangIntegrationTest.php` (in-tree, self-contained) and `bin/e2e-polylang-proof.php` + `bin/e2e-polylang-proof.sh` on a disposable clone with **real Polylang Pro 3.7.3** — **12 PASS / 0 FAIL**, staged at `docs/compatibility/locale-proof-20261005/polylang-proof.json` |
| Locale fallback order (`pll_current_language('locale')` → `ICL_LANGUAGE_CODE` → `'default'`) | `tests/Unit/LocaleFallbackTest.php` + `tests/fixtures/locale-fallback-contract.php` |

Two of the reconstructed WPML checks need **real WPML element records** and stay
permanently unprovable on this host (see the 2026-10-05 evidence doc). Everything else in
the `WAPF-LOCALE-WPML` and `WAPF-LOCALE-POLYLANG` rows is now reproducible from tracked
files.
