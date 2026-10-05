# Locale evidence — Polylang + WPML, reproducible (2026-10-05)

Re-proves the `WAPF-LOCALE-WPML` and `WAPF-LOCALE-POLYLANG` rows of the locale
lane after `LOCALE-EVIDENCE-2026-10-03.md` was found to cite five artifacts that
never existed in the repository (see that doc's 2026-10-05 correction). Everything
below is reproducible from tracked files; the proof artifacts are staged under
`docs/compatibility/locale-proof-20261005/`.

Baseline: WAPF Extended **3.1.5** installed inactive at
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`.
3.1.5 uses Polylang directly — `pll_get_post_types`, `pll_current_language('locale')`,
`ICL_LANGUAGE_CODE`, `'default'` (`includes/classes/class-helper.php:556-564`) — and
has **no WPML hook integration** (only a `wpml-config.xml`). OPF's Polylang path is
WAPF parity; OPF's WPML path is OPF's own extension and has no 3.1.5 counterpart.

## In-tree artifacts

| Artifact | What it is |
|---|---|
| `tests/Unit/PolylangIntegrationTest.php` | 8 Polylang parity tests; self-contained (no WordPress, no sibling test file) |
| `tests/Unit/LocaleFallbackTest.php` + `tests/fixtures/locale-fallback-contract.php` | 4 tests for the `ICL_LANGUAGE_CODE` → `'default'` fallback legs in isolated processes |
| `tests/fixtures/polylang-integration-contract.php` | guarded in-process shims required by `PolylangIntegrationTest` |
| `bin/e2e-wpml-proof.php` + `bin/e2e-wpml-stub.php` | 27-check WPML hook-surface proof against a documented contract stub (WPML is commercial/absent) |
| `bin/e2e-polylang-proof.php` + `bin/e2e-polylang-proof.sh` | 12-check runtime proof against **real Polylang Pro 3.7.3** on a disposable clone |
| `docs/compatibility/locale-proof-20261005/command-log.txt` | Polylang wrapper run log |
| `docs/compatibility/locale-proof-20261005/polylang-proof.json` | Polylang result (12 PASS / 0 FAIL) |
| `docs/compatibility/locale-proof-20261005/wpml-command-log.txt` | WPML proof run log |
| `docs/compatibility/locale-proof-20261005/wpml-proof.json` | WPML result (24 PASS / 0 FAIL / 3 SKIP) |

## Polylang — proven with the real plugin

`bin/e2e-polylang-proof.sh` copies the production `polylang-pro` 3.7.3 and
`polylang-wc` 2.1.4 **read-only** into a disposable `/tmp` clone, activates them
there, and runs the fixture. The production install is never modified. It
reconstructs the 11 checks claimed by the 2026-10-03 doc (and adds a 12th for the
importer):

| Check | Result |
|---|---|
| P1 `pll_get_post_types` adds `opf_field_group` | PASS |
| P2 settings-screen list excludes the CPT | PASS |
| P3 stored group languages are readable | PASS |
| P4 en group renders only under `en` | PASS |
| P5 es group renders only under `es` with its own label | PASS |
| P6 untranslated-group handling (old "renders everywhere" claim corrected) | PASS |
| P7 resolution cache key is scoped per language slug | PASS |
| P8 a re-save is visible immediately in the es view | PASS |
| P9 `lang` (equality) matches only the current locale | PASS |
| P10 `!lang` (not_in) matches only the other locale | PASS |
| P11 Polylang locale is the language source when Polylang is active | PASS |
| P12 imported group keeps the source Polylang language | PASS |

Result: **12 passed, 0 failed** (`polylang-proof.json`). The in-tree
`PolylangIntegrationTest` covers P1/P2/P3-P5/P7-P11 without WordPress, and
`LocaleFallbackTest` covers the `ICL_LANGUAGE_CODE`/`'default'` fallbacks.

## WPML — provable only from hook shapes

WPML is commercial and absent from this host (no `sitepress-multilingual-cms`, no
`wpml-string-translation`). `bin/e2e-wpml-proof.php` therefore exercises
`OPF\Service\WpmlIntegration` against `bin/e2e-wpml-stub.php`, a contract stub of
WPML's public hooks. It reproduces the 23 checks claimed by the 2026-10-03 doc
(the harness enumerates 27, splitting out three real-WPML-only cases as SKIPs).

**24 PASS / 0 FAIL / 3 SKIP** (`wpml-proof.json`). The provable checks cover:
package-kind registration; package creation with the native group id/title; the
eight display strings and their stable names; `LINE`/`AREA`/`VISUAL` type mapping;
delete-unused ordering; `before_delete_post` package deletion; auto-draft and
imported-group no-ops; runtime translation of display strings only (source group,
field ids, slugs, pricing, conditionals untouched); `wpml_object_id` call shapes
and term replacement; empty/`all` current-language passthrough; `wpml_switch_language`
cache wiring and freshness; element-record source-language lookup; owned-import
filtering; unowned-import passthrough; reorder-stable string names.

## Three previously-failing WPML checks — all harness bugs, no code change

The prior run reported W18, W22 and W25 as FAIL. Each was a fixture bug in the
harness, not a defect in `includes/Service/`. No file under `includes/` was changed.

| Check | Verdict | Evidence | Fix |
|---|---|---|---|
| **W18** "object-id results replace the placement terms" | Harness bug | The stub returned non-numeric fixture ids `cat_es`/`tag_es`; real `wpml_object_id` returns integer element ids, and `WpmlIntegration.php:129-134` only replaces numeric results (W17 already proved the calls fire). In-tree `WpmlIntegrationTest` also fixtures numeric returns (`112`, `42`). | Stub `object_map` now returns numeric ids (`316`, `41`); assertion unchanged in intent. |
| **W22** "a language switch yields fresh translated labels" | Harness bug | `for_product()` resolves *published posts*; the synthetic gift group was never inserted, and its `product in [12]` rule excludes a bare `WC_Product` (id 0). A diagnostic in the clone showed `for_product()` returned 1 unrelated entry (`E2E Types Group` / `Extras`), not the gift group. | Harness inserts a placement-neutral real `opf_field_group` row and looks it up by id. |
| **W25** "imports without ownership are never guessed at" | Harness bug | The fixture deleted ownership meta with a raw `$wpdb->delete`, which leaves WordPress's post-meta object cache populated; `WpmlIntegration::is_imported()` kept reading stale `_opf_imported_from`/`_opf_wpml_source_language`. A diagnostic showed `get_post_meta()` still returned `'meta:12'` after the raw delete. | Fixture uses `delete_post_meta()` (clears the cache); a direct run confirms it returns `''`. |

No check was deleted, weakened or marked passing: after the fixture fixes the
harness reports 24 PASS / 0 FAIL / 3 SKIP.

## Permanently unprovable on this host

- **W8** real WPML string/element ids.
- **W19** real `wpml_object_id` translation-group mapping.
- **W26** a real WPML element record for imported-group ownership.
- WPML String Translation editor UI, and WCML multilingual cart/order round-trip
  (no WPML/WCML package exists on this host).

These require a running commercial WPML/WCML installation. The `WAPF-LOCALE-WPML`
row stays **partial** for exactly these items; everything else in the row is now
reproducible.

## Test isolation (P1) — PolylangIntegrationTest is now self-sufficient

`vendor/bin/phpunit tests/Unit/PolylangIntegrationTest.php` used to error 7/8 with
`Class "WP_Post" not found` and `Call to undefined function OPF\Service\get_posts()`
because it borrowed shims declared by sibling test files. It now requires
`tests/fixtures/polylang-integration-contract.php` from `setUp()`.

The fixture is loaded at **runtime**, not at file load, on purpose: sibling files
declare some of the same shims unguarded at load time (`OPF\Service\apply_filters`
in `WpmlIntegrationTest`, `OPF\Service\add_filter` in `WoocsRuntimeTest`), so a
load-time guarded declaration would fatally redeclare them. PHPUnit loads every
test file before running any test, so a runtime `require_once` with
`function_exists`/`class_exists` guards skips anything a sibling already declared —
and defines the shims when the file runs alone.

## Validation (PHP 8.5.11, PHPUnit 11.5.56, Node v23.11.1)

| Command | Result |
|---|---|
| `vendor/bin/phpunit` | OK — Tests: 987, Assertions: 4184, 0 failures (2 pre-existing PHPUnit deprecations) |
| `vendor/bin/phpunit tests/Unit/PolylangIntegrationTest.php` | OK — 8 tests, 23 assertions |
| `vendor/bin/phpunit tests/Unit/PolylangIntegrationTest.php tests/Unit/LocaleFallbackTest.php` | OK — 12 tests, 28 assertions |
| `vendor/bin/phpunit tests/Unit/LocaleFallbackTest.php` | OK — 4 tests, 5 assertions |
| `node --test tests/js/*.test.cjs` | 125 tests, 125 pass, 0 fail |
| `bin/e2e-polylang-proof.sh` (disposable clone, real Polylang Pro 3.7.3) | 12 passed, 0 failed |
| `bin/e2e-wpml-proof.php` (contract stub) | 24 passed, 0 failed, 3 skipped |
| `php -l` on every changed/new PHP file | no syntax errors |

## How to reproduce

```bash
# WPML hook-surface proof (WPML absent; contract stub)
python3 bin/clone-content-image-proof.py \
  --wordpress /home/followersya-5hqi7/opf-test/wordpress \
  --extended  /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended \
  --opf "$PWD" --destination /tmp/opf-image-wpml-proof --port 8322
wp --path=/tmp/opf-image-wpml-proof plugin activate advanced-product-fields-for-woocommerce-extended
env OPF_WPML_PROOF_ALLOW=1 \
  OPF_WPML_PROOF_ARTIFACT_DIR="$PWD/docs/compatibility/locale-proof-20261005" \
  wp --path=/tmp/opf-image-wpml-proof eval-file "$PWD/bin/e2e-wpml-proof.php"

# Polylang runtime proof (real Polylang copied read-only into a disposable clone)
OPF_LOCALE_PROOF_ALLOW=1 bash bin/e2e-polylang-proof.sh
```
