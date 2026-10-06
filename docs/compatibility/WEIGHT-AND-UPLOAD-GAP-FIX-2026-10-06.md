# Weight-formula and modern-uploader gap fix — evidence (2026-10-06)

Repo: `open-product-fields-for-woocommerce`, branch `master`.
Scope: the two real gaps found by the independent WAPF 3.1.6→3.2.2 changelog
crosswalk (`docs/compatibility/WAPF-CHANGELOG-3.1.6-3.2.2-CROSSWALK-2026-10-06.md`,
GAP 1 and GAP 2). Every number below is the real output of the command named
beside it; probes were executed against the pre-fix `HEAD` copy and against this
worktree with the same scripts.

## GAP 1 — weight formulas stored but never evaluated

### Contract (reconciled)

The field-level weight expression now has one evaluation rule:

- `weight` is the canonical key (WAPF `options.weight`), which since WAPF 3.2
  may hold a plain number or a simple formula.
- `weight_formula` is the spelling OPF's builder writes (it has no separate
  `weight` input) and is accepted as an alias; `weight` wins when both keys are
  present.
- Both are evaluated by `Calculator::weight_expression()` through the existing
  sandboxed pricing parser (`Calculator::evaluate_formula()` — no second engine,
  no `eval()`), resolving `[x]`, `[qty]` and numeric `[field.{id}]` references.
- Plain numbers and bare `[x]`/`[qty]` keep their WAPF 3.1.5 `floatval`
  results; an expression the parser rejects fails closed to `0`.

The builder input is therefore no longer inert, and a formula authored in the
`weight` setting evaluates exactly like WAPF 3.2 describes.

### Implementation

| File | Change |
|---|---|
| `includes/Engine/Calculator.php` | `field_weight()` gained an optional `array $field_values = []` parameter and reads the field expression via the new `field_weight_expression()` (`weight` → `weight_formula` alias); `weight_expression()` substitutes `[qty]`/`[x]`, keeps the `opf_field_weight` filter, then evaluates the result with `evaluate_formula()` instead of `floatval()`. |
| `includes/Service/CartIntegration.php` | `addon_weight()` passes the submitted group values (and clone-scoped values for repeated rows) so `[field.{id}]` resolves. |
| `includes/Engine/FieldGroup.php` | Documented `weight_formula` as the alias of `weight`; `normalize_weight()` docblock no longer claims `floatval` semantics. |

### Before / after probe

Script: `$HOME/ops/scratchpad/20261006-weight-upload-gap/weight-probe.php`
(loads the plugin's real `Calculator`; pre-fix side is a copy of the plugin with
`git show HEAD:` versions of `Calculator.php`, `FieldGroup.php` and
`CartIntegration.php` restored).

`php weight-probe.php <plugin-dir>`

| Case | Before (HEAD) | After |
|---|---|---|
| plain numeric `0.5` | `0.5` | `0.5` |
| plain numeric `-10` (choice) | `-10.0` | `-10.0` |
| bare `[x]` (x=4) | `4.0` | `4.0` |
| bare `[qty]` (qty=3) | `3.0` | `3.0` |
| `[x] * 0.5` (x=4) | `4.0` | **`2.0`** |
| `[qty] * 0.25` (qty=4) | `4.0` | **`1.0`** |
| `[field.extra] * 2` (extra=3) | `0.0` | **`6.0`** |
| `weight_formula` = `[field.extra] * 2` | `0.0` | **`6.0`** |
| `weight_formula` = `[x] * 1` (x=1.75) | `0.0` | **`1.75`** |
| `weight` wins over `weight_formula` | `0.5` | `0.5` |
| invalid `[x] *` | `4.0` | **`0.0`** (fail closed) |
| empty `""` | `0.0` | `0.0` |
| `[x]` non-numeric (`abc`) | `0.0` | `0.0` |
| per-choice label + `[x]` | `4.0` | `4.0` |
| per-choice `[x] * 0.5` (label `4`) | `4.0` | **`2.0`** |
| `image_quantity` `0.5` × count 3 | `1.5` | `1.5` |

The arithmetic result is real: `[x] * 0.5` at x=4 moves from 4 (floatval) to 2
(arithmetic), and a stored builder `weight_formula` moves from 0 (never read) to
its evaluated value.

## GAP 2 — modern uploader toggle inert / default off

### Contract

`Uploads::modern()` reads, in order:

1. `opf_modern_uploader` (the admin **Modern file uploader** checkbox) when it
   has been saved — the toggle always controls the renderer;
2. else `opf_upload_ajax` (migrated OPF value);
3. else `wapf_upload_ajax` (migrated WAPF value);
4. else WAPF 3.1.7's on-by-default (`true`).

`Uploads::modern_default()` exposes step 2–4, and the settings field's `default`
is derived from it, so the rendered checkbox cannot claim "on" while a migrated
legacy value keeps the storefront on the native input.

### Before / after probe

Script: `$HOME/ops/scratchpad/20261006-weight-upload-gap/upload-probe.php`

`php upload-probe.php <plugin-dir>`

| Option set | Before (HEAD) | After |
|---|---|---|
| no options | `false` | **`true`** |
| `opf_modern_uploader=yes` | `false` | **`true`** |
| `opf_modern_uploader=no` | `false` | `false` |
| `opf_modern_uploader=no` + `opf_upload_ajax=yes` | `true` | **`false`** (toggle wins) |
| `opf_upload_ajax=yes` | `true` | `true` |
| `opf_upload_ajax=no` | `false` | `false` |
| `wapf_upload_ajax=yes` | `true` | `true` |
| `wapf_upload_ajax=no` | `false` | `false` |
| `opf_upload_ajax=yes` + `wapf_upload_ajax=no` | `true` | `true` |

The exposed toggle now controls the uploader and the no-option default matches
WAPF 3.1.7; every legacy value keeps its previous result.

### Production note (behaviour-safe)

Live option state read on this host
(`wp --path=.../bedrock/web option get ...`): `opf_modern_uploader` absent,
`opf_upload_ajax` absent, `wapf_upload_ajax=no`. The explicit legacy `no`
therefore still wins over the new default, so **the production storefront keeps
serving the native uploader until an admin saves the toggle** — the default
change does not alter the live upload UI by itself. The settings checkbox will
now render unchecked (matching the effective state) instead of the previous
misleading checked state.

## Tests

| Command | Result |
|---|---|
| `php -l` on every changed PHP file | no syntax errors (5/5) |
| `vendor/bin/phpunit --no-coverage` | `Tests: 1105, Assertions: 4716, PHPUnit Deprecations: 2` — 0 failures (pre-fix `HEAD` baseline: `Tests: 1086, Assertions: 4590`) |
| `vendor/bin/phpunit --no-coverage --filter 'ModernUploaderSettingTest\|CommerceWeightTest\|SettingsTest'` | `45 tests, 157 assertions`, 0 failures |
| `node --test tests/js/*.cjs` | `tests 132 / pass 132 / fail 0` (pre-fix `HEAD` baseline: `132 / 132`) |

New / updated PHPUnit coverage:

- `tests/Unit/CommerceWeightTest.php` — arithmetic `[x]*0.5` and `[x]+1`,
  `[qty] * 0.25` plus bare `[qty]`, numeric `[field.{id}]` resolution and its
  non-numeric retry, the builder `weight_formula` key, `weight` precedence over
  `weight_formula`, invalid/empty formulas failing closed to 0, and the retained
  per-choice / image_quantity / signed-delta / plain-number paths.
- `tests/Unit/ModernUploaderSettingTest.php` (new) — default on, saved toggle
  controls `modern()`, saved toggle beats legacy values, legacy
  `opf_upload_ajax` / `wapf_upload_ajax` honoured with OPF-over-WAPF precedence,
  and the settings checkbox default matching the effective legacy state.

The JS suite is unchanged because the builder input was already labelled and
saved as a formula; only its server-side evaluation was missing.

## Residuals

- No WAPF 3.2.x package is available on this host (owner-confirmed), so the
  weight-formula grammar is matched to OPF's own pricing-formula parser and the
  public 3.2 changelog rather than byte-compared against a 3.2 runtime.
- `weight_formula` remains a distinct stored key (builder/back-compat). It is
  evaluated, but the builder still writes the alias rather than the canonical
  `weight`; changing the builder key would alter already-saved payloads and was
  left out of this fix.
- The disposable `bin/e2e-formula-weight-test.php` WP-CLI fixture was not
  re-run here because this plugin is live in production and the fixture creates
  a product, field group and order; the probe plus the unit suite cover the same
  code path. The fixture's expectations now match the fixed behaviour
  (`weight_formula [field.weight] * 1` with 1.75 → per-item 2.0 on a 0.25 base).
- `docs/compatibility/WAPF-CHANGELOG-3.1.6-3.2.2-CROSSWALK-2026-10-06.md`
  GAP sections were annotated as resolved so the audit does not contradict the
  code.

## Files changed

- `includes/Engine/Calculator.php`
- `includes/Engine/FieldGroup.php`
- `includes/Service/CartIntegration.php`
- `includes/Service/Uploads.php`
- `includes/Service/Admin/Settings.php`
- `tests/Unit/CommerceWeightTest.php`
- `tests/Unit/ModernUploaderSettingTest.php` (new)
- `docs/compatibility/WAPF-CAPABILITY-LEDGER.md` (three rows only:
  `WAPF-PRICE-FORMULA-WEIGHT`, `WAPF-COMMERCE-WEIGHT`, `WAPF-UPLOAD-AJAX-UI`)
- `docs/compatibility/WAPF-CHANGELOG-3.1.6-3.2.2-CROSSWALK-2026-10-06.md`
  (GAP annotations)
