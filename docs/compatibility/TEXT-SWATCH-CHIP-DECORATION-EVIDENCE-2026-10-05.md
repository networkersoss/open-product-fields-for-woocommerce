# Text-swatch chip decoration — fix + real-Chromium proof (2026-10-05)

## Scope

OPF re-emits WAPF Extended 3.1.5's text-swatch design variables
(`--apf-ts-border`, `--apf-ts-bg`, `--apf-ts-color`, `--apf-ts-radius`) from the
migrated `wapf_design_settings` option (`includes/Engine/WapfDesign.php:31,71`),
but only the radius had a consumer: `assets/css/opf-frontend.css` applied
`border-radius` on `.opf-text-swatch-wrapper .opf-swatch--text` and nothing else.
A migrated WAPF text swatch therefore lost its border, background and text
colour — the last item on the `WAPF-FIELD-SWATCH-TEXT` row recorded as
"emitted but never applied". This run adds the missing declarations, scoped to
real text swatches, and proves configured, migrated, unconfigured and
plain-checkbox-control values in a real browser.

## The 3.1.5 contract

| Contract | Installed Extended 3.1.5 source |
| --- | --- |
| Setting: corner radius | `includes/classes/class-design-helper.php:521` — `apf-ts-radius`, `unit`, min 0, max 50, `start_with` `4px` |
| Setting: border thickness | `includes/classes/class-design-helper.php:529` — `apf-ts-border-width`, `border-unit`, min 0, max 10, `start_with` `2px` |
| Settings: text / background / border colours (+ hover/selected states) | `includes/classes/class-design-helper.php:540-587` — `apf-ts-color`, `apf-ts-bg`, `apf-ts-border-color` with `hov`/`sel` variants; defaults `inherit`, `transparent`, `#ccc` |
| Sanitizer type map | `includes/classes/class-design-helper.php:1176-1179` — `apf-ts-border-width` → `css_unit`, `apf-ts-color`/`apf-ts-bg`/`apf-ts-border-color` → `color` |
| Emitted variables | `includes/classes/class-design-helper.php:1514-1515` (the `:root` key list: `apf-ts-radius`, `apf-ts-color`, `apf-ts-bg`, …) and `:1535-1536` (`--apf-ts-border:` built by `create_border()`; `none` when width or colour is empty, `:1652-1666`) |
| Applied by the themed stylesheet | `assets/css/frontend-themed.min.css`, rule at char 6500 — `.wapf-swatch--text{margin:0 15px 15px 0;border-radius:var(--apf-ts-radius,4px);border:var(--apf-ts-border,none);color:var(--apf-ts-color,inherit);background:var(--apf-ts-bg,transparent)}` |
| Hover / selected | same file — `.wapf-swatch--text:hover{color:var(--apf-ts-color-hov,inherit);border-color:var(--apf-ts-border-color-hov,transparent);background:var(--apf-ts-bg-hov,transparent)}` and `.wapf-swatch--text.wapf-checked{border-color:var(--apf-ts-border-color-sel,transparent);background:var(--apf-ts-bg-sel,transparent);color:var(--apf-ts-color-sel,inherit)}` |
| Rendered element | `views/frontend/fields/text-swatch.php:17` — `<div class="wapf-swatch wapf-swatch--text wapf-single-select …">`; `views/frontend/fields/multi-text-swatch.php:18` — `<div class="wapf-swatch wapf-swatch--text …">` |
| Plain choices are **not** text swatches | `views/frontend/fields/checkboxes.php:13` and `views/frontend/fields/radio.php:14` put `wapf-checkbox` / `wapf-radio` on the option, never `wapf-swatch--text` |

Both WAPF stylesheets are minified to a single line, so the anchors above are
character offsets of the rule inside the file.

### Which WAPF stylesheet is in play (and what it means for the defaults)

- **Design settings saved** (`wapf_design_settings` non-empty): WAPF writes the
  generated `frontend.min.css` from `frontend-themed.min.css`
  (`class-design-helper.php:1448-1449`), so `.wapf-swatch--text` reads
  `var(--apf-ts-border, none)` / `var(--apf-ts-bg, transparent)` /
  `var(--apf-ts-color, inherit)` and WAPF **always** emits `--apf-ts-border`
  (possibly `none`). This is the state a migration reproduces.
- **No design settings**: `generate_css()` falls back to
  `set_default_css()` (`class-design-helper.php:1137-1146`, `:1309-1312`), which
  copies `frontend-default.min.css` — `.wapf-swatch--text{…;border-radius:4px;border:1px solid #ccc}`
  (char 4976), checked → `border-color:#353c4e;background:#353c4e;color:#fff`.
- OPF mirrors the first state: `WapfDesign::css()` returns `''` when the option
  is empty, so OPF emits no `--apf-ts-*` variables at all in the second state.

The rule implemented here uses **WAPF's themed fallbacks** (`none`,
`transparent`, `inherit`), which is what the task asked for ("WAPF-matching
defaults"). That choice makes the unconfigured state byte-identical to the
pre-fix computed values (see the table), i.e. no unrequested visual change to
existing OPF text swatches. The `frontend-default` chip (`1px solid #ccc`) is
therefore **not** folded in; it is listed as open below.

## Why the wrapper scope is required

OPF reuses `.opf-swatch--text` for plain checkbox and radio choices
(`includes/Service/Renderer.php:1200`), while WAPF keeps those on
`.wapf-checkbox` / `.wapf-radio`. A bare `.opf-swatch--text` rule would decorate
every checkbox/radio row, so the declarations go on
`.opf-text-swatch-wrapper .opf-swatch--text` — the class the renderer adds only
to a real text swatch (`includes/Service/Renderer.php:1126`), the same scope the
radius rule already used.

## What changed

| File | Change |
| --- | --- |
| `assets/css/opf-frontend.css` | `.opf-text-swatch-wrapper .opf-swatch--text` now also declares `border: var(--apf-ts-border, none)`, `background: var(--apf-ts-bg, transparent)` and `color: var(--apf-ts-color, inherit)`. The existing radius line (`var(--opf-text-swatch-radius, var(--apf-ts-radius, 4px))`) is untouched. |
| `tests/Unit/TextSwatchChipCssTest.php` | New (4 tests): the scoped rule consumes the three variables with WAPF's fallbacks and keeps the radius chain; each variable has exactly one consumer and no bare `.opf-swatch--text` selector exists; migrated `wapf_design_settings` emit the variables the rule reads (`WapfDesign::css()`); only real text swatches get the scoping wrapper while plain checkbox/radio choices keep `.opf-swatch--text` without it. |

## Before / after — real Chromium, computed values

Fixture: new `bin/e2e-text-swatch-chip-fixture.php` (product `opf-text-swatch-chip`
with a `swatch_style:text` field plus a checkbox group and a radio group as the
regression controls; phases `prepare | design | radius | verify | cleanup`).
Browser harness: new `bin/e2e-text-swatch-chip-browser.mjs` (reads
`border`/`background`/`color`/`border-radius` of the first chip, the inherited
colour, and both plain-choice controls).

`before` = `32a549e` CSS, sha256 `8c1b88ba7e6743c27693ab94573f8af4c9a9866a03397d12f6328b20bd652786`;
`after` = this lane's CSS, sha256 `080b1410f7f3d031d908b6aa7f4f6d41c5e080832ea6141eeec8d675f3d40c61`.
All runs were made with WAPF Extended absent from the clone, so the only
stylesheet under test is OPF's.

| State | Setup | computed `border` / `background` / `color` / `border-radius` before | after |
| --- | --- | --- | --- |
| `unconfigured` | no `wapf_design_settings`, no OPF radius | `0px none rgb(17,17,17)` / `rgba(0,0,0,0)` / inherited `rgb(17,17,17)` / `4px` | **identical** (`0px none … / transparent / inherit / 4px`) |
| `migrated` | `apf-ts-border-width 2px`, `apf-ts-border-color #cccccc`, `apf-ts-bg #121212`, `apf-ts-color #ffffff`, `apf-ts-radius 22px` | `0px none rgb(17,17,17)` / `rgba(0,0,0,0)` / `rgb(17,17,17)` / `22px` | **`2px solid rgb(204,204,204)` / `rgb(18,18,18)` / `rgb(255,255,255)` / `22px`** |
| `migrated-alt` | `1px solid #ff0000`, `#00ff00`, `#0000ff`, no radius key | `0px none …` / transparent / inherit / `4px` | `1px solid rgb(255,0,0)` / `rgb(0,255,0)` / `rgb(0,0,255)` / `4px` |
| `opf-radius-override` | migrated as `migrated` + OPF `opf_text_swatch_radius = 18` | (not run) | decoration from the variables, radius `18px` (OPF wins over the migrated `22px`) |
| plain checkbox control (every state) | `[data-opf-field="extras"] .opf-swatch--text` | `0px` / `rgba(0,0,0,0)` / `0px` radius | **unchanged** — the chip rule does not reach it |
| plain radio control (every state) | `[data-opf-field="size"] .opf-swatch--text` | `0px` / `rgba(0,0,0,0)` / `0px` radius | **unchanged** — the chip rule does not reach it |

Harness results (18 checks per state): `before-unconfigured` 0 failures,
`before-migrated` **5 failures** (border, background, colour and the same two
values on the selected chip), and `unconfigured`, `migrated`, `migrated-alt`,
`opf-radius-override` **0 failures** each (72 checks in the after-states).
Artifacts: `docs/compatibility/textswatchchip-proof-20261005/*.json`.

`migrated-alt` exists to prove the values come from the variables rather than
from a hard-coded chip style; `unconfigured` exists to prove the fallbacks are
the element's own initial values, i.e. no visual change for sites that never
configured the WAPF design layer.

## Commands

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
CLONE=/home/followersya-5hqi7/opf-test/wordpress
W="wp --path=$CLONE --allow-root"
P=/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json

rsync -a --delete --exclude='.git' --exclude='node_modules' --exclude='vendor' \
  ./ "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/"
cd $CLONE && setsid wp --path=$CLONE --allow-root server --host=127.0.0.1 --port=8090 &

$W eval-file bin/e2e-text-swatch-chip-fixture.php prepare
# TEXT_SWATCH_CHIP_URL=http://opf.test/product/opf-text-swatch-chip/

# after (this lane's CSS), four states
$W eval-file bin/e2e-text-swatch-chip-fixture.php design unset
$W eval-file bin/e2e-text-swatch-chip-fixture.php radius unset
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=unconfigured \
  OPF_TSCHIP_EXPECT='{"border":"none","background":"rgba(0, 0, 0, 0)","color":"inherit","radius":"4px"}' \
  OPF_TSCHIP_OUT=docs/compatibility/textswatchchip-proof-20261005/unconfigured.json \
  node bin/e2e-text-swatch-chip-browser.mjs
$W eval-file bin/e2e-text-swatch-chip-fixture.php design \
  '{"apf-ts-border-width":"2px","apf-ts-border-color":"#cccccc","apf-ts-bg":"#121212","apf-ts-color":"#ffffff","apf-ts-radius":"22px"}'
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=migrated \
  OPF_TSCHIP_EXPECT='{"border":"2px solid rgb(204, 204, 204)","background":"rgb(18, 18, 18)","color":"rgb(255, 255, 255)","radius":"22px"}' \
  OPF_TSCHIP_OUT=docs/compatibility/textswatchchip-proof-20261005/migrated.json \
  node bin/e2e-text-swatch-chip-browser.mjs
#   … migrated-alt, then migrated + `radius 18` → opf-radius-override

# before: the 32a549e asset, hash-verified against the served bytes
git show 32a549e:assets/css/opf-frontend.css > /tmp/opf-frontend.32a549e.css
cp /tmp/opf-frontend.32a549e.css "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/assets/css/opf-frontend.css"
$W eval-file bin/e2e-text-swatch-chip-fixture.php design unset
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=before-unconfigured … node bin/e2e-text-swatch-chip-browser.mjs   # 18/18 ok
$W eval-file bin/e2e-text-swatch-chip-fixture.php design '{"apf-ts-border-width":"2px", … }'
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=before-migrated … node bin/e2e-text-swatch-chip-browser.mjs       # 5 failures

# cleanup
$W eval-file bin/e2e-text-swatch-chip-fixture.php cleanup
pkill -f "127.0.0.1:8090"
```

## Cleanup performed

- `cleanup` deleted the fixture product and field group, flushed the field-group
  cache, restored `wapf_design_settings` and `opf_text_swatch_radius` to their
  pre-run state (both were absent, verified absent afterwards) and removed the
  fixture state file.
- WAPF Extended was never present in the clone during these runs (it was removed
  after the cascade proof), so no WAPF-generated `frontend.min.css` could supply
  `--apf-ts-*` and mask the result. This matters: while WAPF is active in the
  clone it serves a *generated* `frontend.min.css` whose `:root` block is frozen
  at the last design-settings save, which is exactly how a real WAPF site
  behaves.
- The `wp server` on `127.0.0.1:8090` was killed (`ss` confirms the port is
  closed). Production was never touched.
- Screenshots were written to
  `$HOME/ops/scratchpad/20261005-text-swatch-chip/` and deleted when the task
  closed; the JSON artifacts under
  `docs/compatibility/textswatchchip-proof-20261005/` are the shipped evidence.

## Tests

```text
vendor/bin/phpunit --filter TextSwatchChipCssTest
OK, but there were issues!  Tests: 4, Assertions: 21, PHPUnit Deprecations: 2.   (0 failures)
```

`tests/Unit/TextSwatchRadiusTest.php` (6 tests) still passes unchanged: the
radius precedence chain is untouched. No existing test was weakened or removed.

## What is still open

- **Hover / selected decoration.** WAPF also applies
  `--apf-ts-color-hov/-sel`, `--apf-ts-bg-hov/-sel` and
  `--apf-ts-border-color-hov/-sel` (themed stylesheet, `.wapf-swatch--text:hover`
  and `.wapf-swatch--text.wapf-checked`). OPF's equivalent states are `:hover`
  and `.opf-checked`, and the variables are already emitted by
  `WapfDesign::css()`. Out of this fix's scope (the task named the base
  border/background/colour only); it is the natural next increment on the same
  row.
- **The no-design-settings chip.** With `wapf_design_settings` empty, WAPF serves
  `frontend-default.min.css`, where `.wapf-swatch--text` has
  `border: 1px solid #ccc` and the checked state is `#353c4e` on `#fff`. OPF now
  falls back to the themed `none/transparent/inherit` values instead. Folding the
  default chip in would mean `border: var(--apf-ts-border, 1px solid #ccc)` and
  would add a grey border to every existing OPF text swatch on a site that never
  configured the design layer — a product decision, deliberately not taken here.
- **Per-field authoring** of the chip (WAPF has no per-field decoration keys
  either, `class-design-helper.php:516-587`) and the cart/order lifecycle of text
  swatches are unchanged and not re-proved by this run.
