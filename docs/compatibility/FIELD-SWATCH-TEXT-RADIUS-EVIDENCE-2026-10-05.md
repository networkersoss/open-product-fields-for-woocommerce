# Text-swatch corner radius — 2026-10-05

## Scope

`WAPF-FIELD-SWATCH-TEXT` was demoted from `baseline supported` to `partial` on
2026-10-05 (LEDGER-RECONCILIATION) because the row's acceptance criterion — a
configurable corner radius for the text swatch — was absent from OPF. This run
implements that control against the installed Extended 3.1.5 source and proves
it on a disposable WordPress storefront.

It does not claim the rest of the row: accessible choices, validation and the
mapper/exporter paths are unchanged by this change and are not re-proved here.
The visible text-swatch **box** (WAPF's `--apf-ts-border` / `--apf-ts-bg` /
`--apf-ts-color` chip decoration) is deliberately out of scope; see
"What is still open".

## The 3.1.5 contract

The corner radius is a **global design setting**, not a per-field option.

| Contract | Installed Extended 3.1.5 source |
| --- | --- |
| Setting definition | `includes/classes/class-design-helper.php:516-528` — Design → Text Swatches → `apf-ts-radius`, `type` `unit`, `min` 0, `max` 50, `start_with` `4px`, `fallback` `0` |
| Sanitizer type map | `includes/classes/class-design-helper.php:1175` — `'apf-ts-radius' => 'css_unit'` |
| Emitted CSS variable | `includes/classes/class-design-helper.php:1514` — the key is in the `:root` variable list, so the stored value becomes `--apf-ts-radius` |
| Applied by the themed stylesheet | `assets/css/frontend-themed.min.css` — `.wapf-swatch--text{margin:0 15px 15px 0;border-radius:var(--apf-ts-radius,4px);border:var(--apf-ts-border,none);…}` |
| Default stylesheet (no design settings) | `assets/css/frontend-default.min.css` — `.wapf-swatch--text{…;border-radius:4px;border:1px solid #ccc}` |
| Rendered element | `views/frontend/fields/text-swatch.php:17` — `<div class="wapf-swatch wapf-swatch--text wapf-single-select …">`; `views/frontend/fields/multi-text-swatch.php:18` — `<div class="wapf-swatch wapf-swatch--text …">` |
| Per-field options | `includes/classes/class-config.php:1437-1446` — the `text-swatch` field options contain **only** the choice list (`textswatch-options`). There is no per-field radius key. |

Two consequences follow from the source and shaped the implementation:

1. The radius is one value for the whole site, so OPF exposes it as a
   WooCommerce product-fields setting rather than a per-field schema key.
   Adding a per-field `radius` key would have been an invention: 3.1.5 has no
   such option to round-trip.
2. WAPF's stylesheet reads the variable with a `4px` fallback, so an
   unconfigured site renders 4px. OPF reproduces that default and additionally
   lets a migrated `--apf-ts-radius` value keep applying (see below).

The source audit already maps the related changelog entry to this row:
"Extended 3.1.7: text-swatch corner-radius persistence | `WAPF-FIELD-SWATCH-TEXT`"
(`WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md:623`), i.e. the setting's persistence is
the thing being compared — which is why the runtime proof below drives the real
WooCommerce settings save.

## What changed

| File | Change |
| --- | --- |
| `includes/Service/Admin/Settings.php` | New `opf_text_swatch_radius` number setting (min 0, max 50, default 4), `TEXT_SWATCH_RADIUS_DEFAULT` / `TEXT_SWATCH_RADIUS_MAX`, `text_swatch_radius()` accessor and `sanitize_text_swatch_radius()` registered on `woocommerce_admin_settings_sanitize_option_opf_text_swatch_radius`. |
| `includes/Service/Renderer.php` | `render_choices()` marks a real text swatch with `opf-text-swatch-wrapper` and emits `--opf-text-swatch-radius:<n>px` on that wrapper **only when the option is configured**. |
| `assets/css/opf-frontend.css` | `.opf-text-swatch-wrapper .opf-swatch--text { border-radius: var(--opf-text-swatch-radius, var(--apf-ts-radius, 4px)); }` |
| `tests/Unit/TextSwatchRadiusTest.php` | New: settings exposure, radius normalization, renderer markup, migration fallback, non-swatch regression. |

### Why the wrapper class is required

OPF reuses the `opf-swatch--text` class for **plain checkbox and radio
choices** (`Renderer::render_choices()` adds it whenever the field is neither an
image nor a colour swatch, so `type=checkbox` renders
`opf-swatch opf-swatch--text wapf-checkbox`). WAPF never puts
`.wapf-swatch--text` on those — it uses `.wapf-checkbox` / `.wapf-radio`
(`views/frontend/fields/checkboxes.php`, `radio.php`). A bare
`.opf-swatch--text` rule would therefore round checkbox and radio rows, which
WAPF does not. The `opf-text-swatch-wrapper` class scopes the rule to real text
swatches, mirroring the existing `opf-color-swatch-wrapper` /
`opf-image-swatch-wrapper` convention.

### Precedence (WAPF's value is not silently lost)

`--opf-text-swatch-radius` is emitted only when the OPF option was saved. The
CSS then falls back to `--apf-ts-radius` (already produced by
`Engine\WapfDesign::css()` from the migrated `wapf_design_settings` option,
`WapfDesign.php:27,31`), and finally to `4px`, which is WAPF's own stylesheet
fallback. A migrated site keeps the corner radius it configured in WAPF; an
explicitly saved OPF value wins.

### CSS convention

`assets/css/opf-frontend.css` is a plain stylesheet loaded with
`wp_register_style` (`Service/Assets.php:46`); this plugin has no Tailwind or
PostCSS build step, so the project's `@apply` rule does not apply and the rule
follows the file's existing convention (tabs, one declaration per line).

## Tests added

`tests/Unit/TextSwatchRadiusTest.php` — 6 tests, 28 assertions:

1. `test_product_fields_settings_expose_the_text_swatch_corner_radius` —
   settings exposure: `number`, default 4, `min` 0, `max` 50.
2. `test_radius_normalization_clamps_to_the_wapf_bounds` — `18` → 18,
   `18.6` → 19, `-4` → 0, `80` → 50, non-numeric → last valid value, then the
   default.
3. `test_configured_radius_is_read_back_and_clamped` — accessor returns `null`
   when unset, 24 for `24`, 50 for `900`, `null` for `broken`.
4. `test_text_swatch_field_renders_the_configured_corner_radius` — renderer
   emits `class="opf-swatch-wrapper opf-text-swatch-wrapper"` and
   `style="--opf-text-swatch-radius:18px"` with the `opf-swatch--text` chips.
5. `test_unconfigured_radius_leaves_the_imported_wapf_variable_in_charge` —
   no inline variable when the option is unset.
6. `test_other_choice_styles_keep_the_plain_wrapper` — image swatch, colour
   swatch and plain checkbox render neither the wrapper class nor the variable.

## Runtime proof

Environment: disposable WordPress `/home/followersya-5hqi7/opf-test/wordpress`
— WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite, theme
`twentytwentyfive`, OPF 0.1.0 synced from this checkout
(`rsync -a --delete --exclude='.git' --exclude='node_modules' --exclude='vendor'`
then `diff -rq` clean). Storefront server:
`wp --path=/home/followersya-5hqi7/opf-test/wordpress server --host=127.0.0.1 --port=8090`,
killed after the run.

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
W="wp --path=/home/followersya-5hqi7/opf-test/wordpress"
P=/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json

$W eval-file bin/e2e-text-swatch-radius-test.php prepare
# TEXT_SWATCH_URL=http://opf.test/product/opf-text-swatch-radius/  → SUCCESS prepare
```

Each state below ran the fixture phase(s) and then
`OPF_PLAYWRIGHT_PACKAGE=$P OPF_TEXT_SWATCH_EXPECT=<px> OPF_TEXT_SWATCH_LABEL=<state>
node bin/e2e-text-swatch-radius-browser.mjs` (9 checks per state, all `ok`):

| State | Setup | Computed `border-radius` on `.opf-text-swatch-wrapper .opf-swatch--text` | Checkbox regression control |
| --- | --- | --- | --- |
| `default-4px` | option unset, no migrated value | `4px` | `0px` |
| `configured-18px` | option `18` | `18px` | `0px` |
| `migrated-22px` | option unset, `wapf_design_settings['apf-ts-radius']='22px'` | `22px` | `0px` |
| `override-18px` | option `18` **and** migrated `22px` | `18px` (OPF wins) | `0px` |
| `wcsave-18-6-19px` | real WooCommerce save posting `18.6` | `19px` | `0px` |
| `wcsave-80-50px` | real WooCommerce save posting `80` | `50px` | `0px` |
| `wcsave-0-0px` | real WooCommerce save posting `0` | `0px` (square) | `0px` |

Per state the harness asserts: HTTP 200, the text-swatch wrapper is present,
both choices are `.opf-swatch--text` chips, the computed radius equals the
expected value, the wrapper publishes `--opf-text-swatch-radius` only when
configured, the plain checkbox chip stays `0px`, the chip is still selectable
and keeps the radius while selected, and the page raises no errors. 63 browser
checks in total, 0 failures.

The harness writes one full-page screenshot of the field per state to
`$OPF_TEXT_SWATCH_ARTIFACTS` (default `/tmp/opf-text-swatch-radius-evidence`).
That directory is regenerated by re-running the command above; it is not shipped
with this document.

The `save` phase drives WooCommerce's own save handler —
`WC_Admin_Settings::save_fields( Settings::product_fields_settings( [], 'opf_product_fields' ), $post )`
with the settings form's full field set — so the registered sanitizer and the
option write are the production code paths, not a direct `update_option()`.

`verify` additionally renders the real WooCommerce settings form with
`WC_Admin_Settings::output_fields()` and asserts the control exists with
`type="number"`, `min="0"`, `max="50"` and the stored value:

```text
ok settings form renders the text swatch corner radius control
ok settings control is a bounded number input
ok settings control shows the stored radius
```

### Honest limit of the visual proof

`.opf-swatch--text` in OPF is a borderless, background-less block, so a corner
radius changes the computed `border-radius` but not the painted pixels. The
proof is therefore a computed-style + markup + settings-persistence proof, not a
screenshot-diff proof. This is the exact property WAPF's stylesheet sets, and it
is verified in a real browser, but the *visible* chip decoration is a separate
open item (below).

## Cleanup

`bin/e2e-text-swatch-radius-test.php cleanup` deleted the fixture group, the
fixture product and the fixture state, restored the `opf_text_swatch_radius` and
`wapf_design_settings` options to their pre-run values, and flushed the
field-group cache. The scratchpad probe product used during reconnaissance was
deleted; the storefront server was killed.

## What is still open

- **Visible text-swatch chip decoration.** WAPF's `.wapf-swatch--text` also
  carries `border: var(--apf-ts-border, none)`, `background: var(--apf-ts-bg,
  transparent)` and `color: var(--apf-ts-color, inherit)`, fed by the
  `apf-ts-border-width` / `apf-ts-border-color` / `apf-ts-bg` / `apf-ts-color`
  design settings (`class-design-helper.php:528-587`). OPF's text swatch renders
  as bare text today, so those are not implemented and were not touched. That is
  a presentation gap of the same row and needs its own decision.
- **Builder/admin authoring surface** for the swatch style itself is unchanged
  by this run.
- **`WAPF-FIELD-SWATCH-TEXT` lifecycle** (cart, order, order-again, email) was
  not re-proved here; the row's other evidence is untouched.

## Commands run

```sh
vendor/bin/phpunit 2>&1 | tail -6
# Tests: 1033, Assertions: 4365, PHPUnit Deprecations: 2.   (0 failures)
node --test tests/js/*.test.cjs 2>&1 | tail -8
# tests 129 / pass 129 / fail 0
php -l includes/Service/Admin/Settings.php
php -l includes/Service/Renderer.php
php -l bin/e2e-text-swatch-radius-test.php
node --check bin/e2e-text-swatch-radius-browser.mjs
```
