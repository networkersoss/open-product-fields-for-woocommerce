# WAPF ↔ OPF coexistence: shared choice-grid wrapper collapse — fix + computed-style proof (2026-10-05)

## Scope

WAPF Extended **3.1.5** ships
`assets/css/frontend.min.css` → `.wapf-checkboxes,.wapf-radios{display:inline-grid;grid-template-columns:auto;gap:5px 1rem}`.
OPF reuses both wrapper classes (`includes/Service/Renderer.php`, choice wrapper) so
WAPF-authored theme CSS applies to OPF fields. Because both rules are a single class
(specificity 0,1,0), the stylesheet that loads **later** wins. OPF's
`opf-frontend.css` is printed before `wapf-frontend-css`, so WAPF's rule won and every
OPF checkbox group collapsed to one column — even with `columns:4` saved. This run
reproduces that with real Chromium, fixes it with a scoped/specificity change (no
`!important`, no class rename, no WAPF file touched) and re-proves all three
coexistence cases.

## Environment

- Baseline reference: WAPF Extended **3.1.5**, installed at
  `/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`
  (never modified; production never touched).
- Disposable clone: `/tmp/opf-lane-coexistcss-wp` (copy of `/tmp/opf-wapfref-compare-wp`),
  docroot served by `php -S 127.0.0.1:8261 -t . router.php`, loopback only.
  WP 7.1.2 / WC 11.1.0 / PHP 8.5.11 / SQLite drop-in.
- OPF under test: a copy of this lane (`/tmp/opf-lane-coexistcss`) placed at
  `wp-content/plugins/open-product-fields-for-woocommerce` (a real copy, **not** a symlink —
  a symlink makes `plugin_dir_url()` emit a broken CSS URL via PHP's realpath cache, so the
  clone would not load OPF's CSS at all).
- Harnesses:
  - `bin/e2e-coexistcss-checkbox-columns.php` — fixture (WP-CLI `eval-file`, args `prepare|verify|cleanup`).
  - `bin/e2e-coexistcss-checkbox-columns-browser.mjs` — real Chromium computed styles + cascade dump.
- Artifacts: `docs/compatibility/coexistcss-proof-20261005/*.json`.

The fixture product renders, on the **same storefront page**, an OPF checkbox group with
`columns:4`, an OPF radio group, a WAPF 3.1.5 native checkbox field and a WAPF 3.1.5 native
radio field. The stylesheet order on that page is:

```
1. .../open-product-fields-for-woocommerce/assets/css/opf-frontend.css?ver=0.1.0
2. .../advanced-product-fields-for-woocommerce-extended/assets/css/frontend.min.css?ver=3.1.5-1791237340
```

“Before” = this lane's CSS with only the four `.opf-checkboxes--columns` selectors reverted to
the pre-fix bare spelling (`sha256 af6e55ec…`); “after” = the committed lane CSS
(`sha256 8c1b88ba…`). Only selector spelling changed, so the cascade comparison is exact.

## Reproduction — before state (both plugins active)

`bin/e2e-coexistcss-checkbox-columns-browser.mjs` → `before-both-active.json`.

OPF wrapper classes: `opf-swatch-wrapper opf-checkboxes--columns wapf-checkboxes`, inline style
`--opf-checkbox-columns:4;--opf-checkbox-columns-tablet:2;--opf-checkbox-columns-mobile:1`.

| Element | viewport | `display` | computed `grid-template-columns` | columns |
|---|---|---|---|---|
| OPF checkbox wrapper | 1280 | `inline-grid` | `162.812px` | **1** |
| OPF checkbox wrapper | 600 | `inline-grid` | `144.812px` | **1** |
| OPF checkbox wrapper | 400 | `inline-grid` | `142.812px` | **1** |
| OPF radio wrapper | 1280 | `inline-grid` | `110.406px` | 1 |
| WAPF checkbox wrapper | 1280 | `inline-grid` | `133.203px` | 1 |
| WAPF radio wrapper | 1280 | `inline-grid` | `134.203px` | 1 |

The cascade dump confirms the cause — OPF's own four matching rules are followed by WAPF's:

```
opf-frontend.css   .opf-checkboxes--columns                                  display:grid
opf-frontend.css   .opf-checkboxes--columns        @(max-width:720px)
opf-frontend.css   .opf-checkboxes--columns        @(max-width:420px)
opf-frontend.css   .opf-checkboxes--columns                                  display:grid
frontend.min.css   .wapf-checkboxes, .wapf-radios                            display:inline-grid   ← wins
                                                                              grid-template-columns:auto
```

`grid-template-columns:auto` from WAPF's later rule defeats `display:grid`-side column tracks,
so the configured 4 columns render as one.

## Fix

All four `.opf-checkboxes--columns` selectors in `assets/css/opf-frontend.css` (base, the two
`@media` overrides, and the later legacy duplicate) were scoped to
**`.opf-swatch-wrapper.opf-checkboxes--columns`** — specificity **(0,2,0)** vs WAPF's (0,1,0).

```css
.opf-swatch-wrapper.opf-checkboxes--columns {
	display: grid;
	grid-template-columns: repeat(var(--opf-checkbox-columns, 1), minmax(0, 1fr));
}
```

Why this selector, and why it cannot regress WAPF-only:

- It requires **both** `opf-swatch-wrapper` and `opf-checkboxes--columns`. The OPF renderer
  always emits `.opf-swatch-wrapper` first on the choice wrapper
  (`includes/Service/Renderer.php:1115`), so the rule matches every OPF-rendered multi-column
  checkbox group.
- WAPF's own markup (`.wapf-checkboxes`/`.wapf-radios`) carries **no** `opf-*` class, so the
  new selector cannot match a WAPF field; WAPF's `.wapf-checkboxes,.wapf-radios` rule is left
  to style WAPF fields exactly as shipped. No `!important`, no rename of the shared `wapf-*`
  aliases (themes depend on them), no WAPF file touched.
- Compared with the alternative `.opf-checkboxes--columns.wapf-checkboxes`, the
  `.opf-swatch-wrapper` scope also covers a checkbox group that combines `columns>1` with the
  switch control, which the renderer renders without the `wapf-checkboxes` alias.
- OPF-only is unaffected: in a page with no WAPF stylesheet, no rule competes, and the same
  selector still matches the same element.

## After state

`after-both-active.json`, `after-opf-only.json`, `after-wapf-only.json`.

| Case | Element | viewport | before | after |
|---|---|---|---|---|
| both active | OPF checkbox wrapper | 1280 | `inline-grid` / 1 col | **`grid` / 4 cols** (`145.5px ×4`) |
| both active | OPF checkbox wrapper | 600 | `inline-grid` / 1 col | **`grid` / 4 cols** (`126px ×4`) |
| both active | OPF checkbox wrapper | 400 | `inline-grid` / 1 col | **`grid` / 4 cols** (`76px ×4`) |
| both active | WAPF checkbox wrapper | 1280 | `inline-grid` / `133.203px` | `inline-grid` / `133.203px` (unchanged) |
| both active | WAPF radio wrapper | 1280 | `inline-grid` / `134.203px` | `inline-grid` / `134.203px` (unchanged) |
| both active | OPF radio wrapper | 1280 | `inline-grid` / `110.406px` | `inline-grid` / `110.406px` (unchanged) |
| OPF only (WAPF inactive) | OPF checkbox wrapper | 1280 | `grid` / 4 cols | `grid` / 4 cols (unchanged) |
| OPF only (WAPF inactive) | OPF radio wrapper | 1280 | `block` | `block` (unchanged) |
| WAPF only (OPF inactive) | WAPF checkbox wrapper | 1280 | `inline-grid` / `133.203px` | `inline-grid` / `133.203px` (unchanged) |
| WAPF only (OPF inactive) | WAPF radio wrapper | 1280 | `inline-grid` / `134.203px` | `inline-grid` / `134.203px` (unchanged) |

In `after-both-active.json` the cascade dump now shows OPF's scoped rule winning over WAPF's
later one:

```
opf-frontend.css   .opf-swatch-wrapper.opf-checkboxes--columns                     display:grid
opf-frontend.css   .opf-swatch-wrapper.opf-checkboxes--columns  @(max-width:720px)
opf-frontend.css   .opf-swatch-wrapper.opf-checkboxes--columns  @(max-width:420px)
opf-frontend.css   .opf-swatch-wrapper.opf-checkboxes--columns                     display:grid
frontend.min.css   .wapf-checkboxes, .wapf-radios                                  (still present, no longer applicable)
```

WAPF-only runs with OPF's plugin inactive, so `opf-frontend.css` is not even enqueued; its
values are byte-identical before/after, which is the strongest form of “unchanged”.

## Other shared-class layouts

- **Radios (OPF `opf-swatch-wrapper wapf-radios`).** WAPF's `.wapf-radios` still applies when
  both plugins are active: OPF-only computes `display:block`, both-active computes
  `display:inline-grid` (single column). OPF declares **no** radio grid layout, so nothing is
  overridden — this is the intended WAPF-theme parity alias, not the column-collapse bug.
  No fix required; not worth an `!important`/specificity war against WAPF's shipped rule.
- **Products wrapper (OPF `opf-checkboxes opf-products opf-products--* wapf-checkboxes`).**
  WAPF's `.wapf-checkboxes` gives it `display:inline-grid`/single column when both are active.
  OPF declares no `display` for `.opf-products`, so again no OPF layout is overridden; the
  shared alias is deliberate WAPF parity.
- **Sections (OPF `opf-section wapf-section`).** WAPF's `.wapf-field-group,.wapf-section
  {display:flex;flex-wrap:wrap}` applies to OPF's section marker when both are active
  (`display:flex`), while OPF-only is `block`. OPF declares no `.opf-section` display, so no
  OPF rule is overridden, but this is the one other shared class that changes visible layout.
  Recorded here as an observation; it is outside this row's choice-grid scope.
- **Benign overlaps** (single-class WAPF rules on OPF markup, no layout loss):
  `.wapf-checkbox`/`.wapf-radio{clear:both}`, `.wapf-input-label{font-weight:400;cursor:pointer}`,
  `.wapf-label-text{padding-left:10px}`, `.wapf-custom` (WAPF design skin, intentional).
  OPF does **not** emit `.wapf-swatch-wrapper`, `.wapf-image-swatch-wrapper`, `.wapf-card-wrap`
  or `.wapf-card` (it uses `opf-*` spellings), so those WAPF layout rules cannot collide.

## Regression tests added

`tests/Unit/FrontendChoiceGridCssTest.php` (3 tests, 7 assertions):

1. `test_checkbox_column_rule_outranks_the_shared_wapf_wrapper_class` — the asset emits
   `.opf-swatch-wrapper.opf-checkboxes--columns`, the bare `.opf-checkboxes--columns` selector is
   gone (a bare one would tie with WAPF's `.wapf-checkboxes`), and the tablet/mobile `@media`
   overrides carry the same scope.
2. `test_rendered_checkbox_wrapper_carries_the_scoping_class_alongside_the_wapf_aliases` — the
   renderer emits `class="opf-swatch-wrapper opf-checkboxes--columns wapf-checkboxes"`, so the
   scoped selector actually matches.
3. `test_checkbox_and_radio_wrappers_keep_the_shared_wapf_classes` — the shared `wapf-checkboxes`
   / `wapf-radios` aliases are not renamed (themes depend on them).

Existing `tests/Unit/RendererCheckboxColumnsTest.php` continues to cover the column count and
choice values.

## Commands

```sh
# clone (disposable) — real copy of the lane, never a symlink
cp -a /tmp/opf-wapfref-compare-wp /tmp/opf-lane-coexistcss-wp
rm -rf /tmp/opf-lane-coexistcss-wp/wp-content/plugins/open-product-fields-for-woocommerce
cp -a /tmp/opf-lane-coexistcss /tmp/opf-lane-coexistcss-wp/wp-content/plugins/open-product-fields-for-woocommerce
sed -i 's#127.0.0.1:8251#127.0.0.1:8261#g' /tmp/opf-lane-coexistcss-wp/wp-config.php
cp /tmp/opf-variation-e2e-wp/router.php /tmp/opf-lane-coexistcss-wp/router.php
setsid php -S 127.0.0.1:8261 -t /tmp/opf-lane-coexistcss-wp /tmp/opf-lane-coexistcss-wp/router.php &

WP="wp --path=/tmp/opf-lane-coexistcss-wp --allow-root"
$WP plugin activate advanced-product-fields-for-woocommerce-extended
$WP eval-file /tmp/opf-lane-coexistcss/bin/e2e-coexistcss-checkbox-columns.php prepare

# before (both active) — clone CSS temporarily reverted to the bare selector
OPF_COEXISTCSS_OUT=docs/compatibility/coexistcss-proof-20261005/before-both-active.json \
  OPF_COEXISTCSS_URL=http://127.0.0.1:8261/product/opf-wapf-coexistcss-product/ \
  node bin/e2e-coexistcss-checkbox-columns-browser.mjs

# after (both active) — lane CSS
OPF_COEXISTCSS_OUT=docs/compatibility/coexistcss-proof-20261005/after-both-active.json \
  OPF_COEXISTCSS_URL=http://127.0.0.1:8261/product/opf-wapf-coexistcss-product/ \
  node bin/e2e-coexistcss-checkbox-columns-browser.mjs

# WAPF only
$WP plugin deactivate open-product-fields-for-woocommerce
OPF_COEXISTCSS_OUT=docs/compatibility/coexistcss-proof-20261005/after-wapf-only.json \
  OPF_COEXISTCSS_URL=http://127.0.0.1:8261/product/opf-wapf-coexistcss-product/ \
  node bin/e2e-coexistcss-checkbox-columns-browser.mjs
$WP plugin activate open-product-fields-for-woocommerce

# OPF only
$WP plugin deactivate advanced-product-fields-for-woocommerce-extended
OPF_COEXISTCSS_OUT=docs/compatibility/coexistcss-proof-20261005/after-opf-only.json \
  OPF_COEXISTCSS_URL=http://127.0.0.1:8261/product/opf-wapf-coexistcss-product/ \
  node bin/e2e-coexistcss-checkbox-columns-browser.mjs
$WP plugin activate advanced-product-fields-for-woocommerce-extended

# cleanup
$WP eval-file /tmp/opf-lane-coexistcss/bin/e2e-coexistcss-checkbox-columns.php cleanup
```

## Cleanup performed

- Fixture deleted: `cleanup` removed the OPF fixture product + group and the WAPF
  `wapf_product` group (option `opf_coexistcss_state` and all `post_ids` removed).
- Both plugins restored to **active** (the clone's original state) — verified with
  `wp plugin list`.
- The `php -S 127.0.0.1:8261` server was stopped; the whole disposable clone
  `/tmp/opf-lane-coexistcss-wp` can be deleted without touching production.
- Production plugin directory was never written to.

## Residual risks / notes

- **Pre-existing responsive ordering defect (not introduced by this fix).** The later legacy
  duplicate base rule (`.opf-checkboxes--columns` at the bottom of the file, before this fix;
  `.opf-swatch-wrapper.opf-checkboxes--columns` after) appears *after* the tablet/mobile
  `@media` overrides, so it already defeated those overrides before the coexistence change:
  the unmodified baseline renders 4/4/4 at 1280/600/400 even with WAPF inactive
  (`/tmp/probe-unmodified-css.mjs`). The fix preserves that behaviour (4/4/4 in both-active and
  OPF-only). Restoring the intended 4/2/1 cascade is a separate, WAPF-independent change and is
  left out of this row's scope.
- `.wapf-section` (sections) and `.opf-products` (products wrapper) are influenced by WAPF's
  shared classes when both plugins are active, but neither overrides an OPF-declared layout;
  recorded above rather than “fixed”, because OPF deliberately emits the aliases for WAPF theme
  parity.
