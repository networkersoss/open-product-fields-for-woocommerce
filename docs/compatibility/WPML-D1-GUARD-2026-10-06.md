# WPML D1 guard — OPF stops emitting dead registration hooks (2026-10-06)

Closure evidence for finding **D1** in
[WAPF-LOCALE-WPML-RUNTIME-2026-10-06.md](./WAPF-LOCALE-WPML-RUNTIME-2026-10-06.md).

- Plugin: `open-product-fields-for-woocommerce` 0.1.0, `master` at `638ed17` (unedited base).
- Scope: `includes/Service/WpmlIntegration.php` plus new unit coverage. No commit, no push,
  no branch, no staged files.
- No production WordPress file, database or option was read or written.

---

## 1. Finding D1 (before)

On the requested stack **WPML core 5.1.0 + String Translation 3.5.2** (companion below the
`5.0.0` the core requires) WPML core's `WPML_Plugins_Check::disable_outdated()` runs
`remove_outdated_st_hooks()`, `remove_outdated_st_boot()` and `install_outdated_st_stand_in()`:
it sets `$GLOBALS['WPML_String_Translation']` to `WPML_ST_Outdated_Stand_In` and registers **no**
handler for `wpml_start_string_package_registration` / `wpml_register_string`.

OPF ignored that. `WpmlIntegration::register_post()` unconditionally emitted its whole
registration contract — `wpml_start_string_package_registration`, one `wpml_register_string` per
display string, `wpml_delete_unused_package_strings` — and every call hit zero handlers.
Result: no notice, no log, no fallback and **zero `icl_strings` rows**; the administrator sees an
empty package editor with no explanation.

`WpmlIntegration::init()` registered its hooks unconditionally and the plugin declares only
WP/WC/PHP minimums, so nothing guarded the WPML dependency.

## 2. Guard (after)

`WpmlIntegration` now decides whether the String Translation **package API is actually live**
before it emits registration hooks.

### 2.1 Detection (never a version constant alone)

`strings_api_available()` returns `false` when either signal proves the API is dead:

1. **Outdated stand-in marker** — `$GLOBALS['WPML_String_Translation']` is an instance of
   `WPML_ST_Outdated_Stand_In` (WPML core's own marker for a companion it switched off). This is
   authoritative even if some other code subscribed a handler.
2. **No registration handler** — WPML is loaded (`$GLOBALS['WPML_String_Translation']` set, or
   `ICL_SITEPRESS_VERSION` / `WPML_ST_VERSION` defined, or `SitePress` loaded) but nothing is
   subscribed to `wpml_register_string` **or** `wpml_start_string_package_registration`
   (`has_action()`), i.e. the emitted action would be a silent no-op.

When no WPML surface is loaded at all, the guard returns `true`: the hooks stay inert exactly as
before and nothing is skipped or announced (a site without WPML must not be warned).

Both signals are read through the WordPress hook API, so a stand-in that still leaves a stray
handler wired is still caught, and a healthy companion is detected by its real
`WPML_Package_Translation::register_string_action` subscription rather than by its version string.

### 2.2 Emission is skipped

`register_post()` checks the guard after validating the post/JSON and before building the
package, so a dead API produces **no** `wpml_start_string_package_registration`,
`wpml_register_string` or `wpml_delete_unused_package_strings` action. `delete_post()` and
`translate_groups()` are unchanged: deletion has no handler to miss, and translation degrades to
the source text through the same no-op filters, which is harmless.

### 2.3 One-time, capability-checked, escaped admin notice

- `init()` checks the guard at boot and, when the API is dead, queues the notice via
  `queue_unavailable_notice()`; `register_post()` does the same if a later request reaches the
  dead API first.
- `queue_unavailable_notice()` is idempotent (private static flag): the `admin_notices` hook is
  added at most **once per request**, never once per field group or per string.
- `render_unavailable_notice()` is silent unless all hold: `is_admin()`, the API is still
  unavailable, and `current_user_can( 'manage_woocommerce' )`. The message is emitted through
  `esc_html__()` and wrapped in `<div class="notice notice-warning">`.
- The callback is registered on `admin_notices` only and re-checks availability when it renders,
  so an early check at `plugins_loaded` (before WPML has fully booted) cannot produce a false
  warning, and the front end stays silent.

Interpretation of "one-time": idempotent per request (queued once, never once per registration),
verified by driving the private queue directly and asserting the second call refuses. Persistent
per-site dismissal is **not** implemented — it is listed as residual R1 below.

## 3. Tests

`tests/Unit/WpmlStringTranslationGuardTest.php` (new) with
`tests/fixtures/wpml-st-guard-contract.php` (new). The fixture follows the
`tests/fixtures/polylang-integration-contract.php` pattern: guarded, `require_once` from
`setUp()`, so the file passes both in the full suite and alone.

| Test | Asserts |
|---|---|
| `test_available_package_api_emits_the_registration_contract_unchanged` | Handler subscribed → `init()` queues no notice; `register_post()` emits start + **7** `wpml_register_string` (names checked) + delete-unused, exactly as before |
| `test_missing_registration_handler_skips_emission_and_queues_one_notice` | WPML present, no handler → no registration action at all; `admin_notices` callback queued once; a second queue call returns `false` |
| `test_outdated_string_translation_stand_in_disables_registration_even_with_a_subscribed_handler` | Stand-in present **and** both handlers subscribed → still no emission, notice queued |
| `test_absent_wpml_keeps_emitting_and_never_queues_a_notice` | No WPML → 9 actions emitted (7 strings + 2 lifecycle) and no notice |
| `test_notice_is_capability_checked_escaped_and_front_end_silent` | `is_admin()===false` → `''`; missing `manage_woocommerce` → `''`; capable admin in admin → escaped warning HTML |
| `test_available_api_renders_no_notice_even_when_the_hook_is_queued` | Healthy API → notice renders `''` even if the hook was queued |

## 4. Commands and real results

```text
$ vendor/bin/phpunit --no-coverage tests/Unit/WpmlStringTranslationGuardTest.php
OK (6 tests, 22 assertions)

$ vendor/bin/phpunit --no-coverage
Tests: 1086, Assertions: 4590, PHPUnit Deprecations: 2.
OK, but there were issues!   # the 2 PHPUnit deprecations pre-date this lane (baseline was also 2)

$ node --test tests/js/*.cjs
ℹ tests 130   ℹ pass 130   ℹ fail 0   ℹ cancelled 0   ℹ skipped 0

$ php -l includes/Service/WpmlIntegration.php
No syntax errors detected in includes/Service/WpmlIntegration.php
$ php -l tests/fixtures/wpml-st-guard-contract.php
No syntax errors detected in tests/fixtures/wpml-st-guard-contract.php
$ php -l tests/Unit/WpmlStringTranslationGuardTest.php
No syntax errors detected in tests/Unit/WpmlStringTranslationGuardTest.php
```

Baseline before this lane: `1080` tests / `4568` assertions (PHPUnit), `130` node tests. The lane
adds 6 tests / 22 assertions and changes nothing else; node is unchanged.

## 5. Files changed

- `includes/Service/WpmlIntegration.php` — added the availability guard, skip-on-dead-API in
  `register_post()`, one-time `admin_notices` warning.
- `tests/Unit/WpmlStringTranslationGuardTest.php` (new).
- `tests/fixtures/wpml-st-guard-contract.php` (new).
- `docs/compatibility/WPML-D1-GUARD-2026-10-06.md` (this file).

Untouched (other lanes): `assets/js/opf-frontend.js`, `docs/compatibility/WAPF-CAPABILITY-LEDGER.md`,
`docs/compatibility/OPF-1.0-ROADMAP.md`.

## 6. Residual risks / open questions

- **R1 — one-time is per request, not per site.** The notice can reappear on later admin page
  loads while String Translation stays disabled. A persisted dismissal (option or user meta) was
  not requested and would need a product decision; the finding only demanded that the failure stop
  being silent.
- **R2 — detection is heuristic, not a version comparison.** A third-party plugin that subscribes
  to `wpml_register_string` while WPML's own String Translation is switched off could make the
  guard consider the API live (the stand-in check still overrides unless the global is also
  replaced). This is strictly better than today's unconditional emission.
- **R3 — real WPML stack not available on host.** Coverage is in-process simulation of the
  documented stand-in/handler states, mirroring the runtime proof in the finding. The three
  signals read (`$GLOBALS['WPML_String_Translation']`, the outdated stand-in class, the
  registration handlers) are the exact ones observed in
  `WAPF-LOCALE-WPML-RUNTIME-2026-10-06.md`, but no licensed WPML/WCML runtime was driven here.
- **R4 — capability choice.** The notice uses `manage_woocommerce` to match OPF's other admin
  surfaces rather than WPML's `wpml_manage_string_translation`, so shop managers who edit field
  groups also see it.
