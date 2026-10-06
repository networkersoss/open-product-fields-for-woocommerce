# Text-swatch chip: adopt WAPF's default appearance — fix + real-Chromium proof (2026-10-06)

Third and last item of the text-swatch chip series. The two 2026-10-05 lanes
(`TEXT-SWATCH-CHIP-DECORATION-EVIDENCE-2026-10-05.md`,
`TEXT-SWATCH-CHIP-HOVER-SELECTED-EVIDENCE-2026-10-05.md`) verified WAPF's
no-design-settings chip precisely and **deliberately did not adopt it**. The owner
has now decided that OPF matches WAPF's default appearance, so the variable
fallbacks of the three existing scoped rules change from the *themed* values
(`none` / `transparent` / `inherit`) to the *default stylesheet* values
(`1px solid #ccc` / `#353c4e` / `#fff`). Configured design settings and the OPF
radius override keep winning exactly as before.

## The 3.1.5 contract (re-verified in the installed baseline)

Installed baseline: `/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`,
header `Version: 3.1.5` (`advanced-product-fields-for-woocommerce-extended.php:7`).

| Contract | Installed Extended 3.1.5 source |
| --- | --- |
| No design settings → default stylesheet | `includes/classes/class-design-helper.php:1137` `generate_css()`, `:1144` `self::set_default_css()` when `wapf_design_settings` is empty; the fallback is also taken from the write path at `:1303` |
| `set_default_css()` byte-copies the default chip | `class-design-helper.php:1308-1311` — `copy( $path . 'frontend-default.min.css', $path . 'frontend.min.css' )` |
| Which file the storefront gets | `includes/controllers/class-public-controller.php:182-186` — regenerates via `generate_css()` when `assets/css/frontend.min.css` is missing, then enqueues `css/frontend.min.css`. The shipped 3.1.5 package contains no `frontend.min.css` (only `frontend-default.min.css` and `frontend-themed.min.css`), so a fresh install with no design settings serves the default chip |
| **Base chip** | `assets/css/frontend-default.min.css` (1 line, 9069 bytes, sha256 `8e6c730b36af8163960907f8652577ae65dba6581673c9f150a82f7ea0900182`), char 4976 — `.wapf-swatch--text{margin:0 15px 15px 0;border-radius:4px;border:1px solid #ccc}` |
| **Hover** | same file, char 5098 — `.wapf-swatch--text:hover{border-color:#353c4e}` (border-colour only: colour and background are untouched) |
| **Selected** | same file, char 5144 — `.wapf-swatch--text.wapf-checked{border-color:#353c4e;background:#353c4e;color:#fff}` |
| Design settings → themed chip | `class-design-helper.php:1446` `write_css()` splits `assets/css/frontend-themed.min.css`; char 6500 `.wapf-swatch--text{…;border-radius:var(--apf-ts-radius,4px);border:var(--apf-ts-border,none);color:var(--apf-ts-color,inherit);background:var(--apf-ts-bg,transparent)}`, char 6728 `:hover{…}`, char 6891 `.wapf-checked{…}` |
| A variable is emitted only when non-empty | `class-design-helper.php:1507` `design_settings_to_variables_css()`, `:1527` `if( ! empty( $design_settings[ $var ] ) )` — same shape as OPF's `WapfDesign::css()` (`includes/Engine/WapfDesign.php:65-69`) |

OPF emits no `--apf-ts-*` at all when `wapf_design_settings` is empty
(`WapfDesign::css()` returns `''`), so in the unconfigured state the fallbacks in
`assets/css/opf-frontend.css` carry the entire appearance of the chip — which is
why they are the contract surface this lane changes.

## What changed

| File | Change |
| --- | --- |
| `assets/css/opf-frontend.css` | Base rule: `border: var(--apf-ts-border, none)` → `border: var(--apf-ts-border, 1px solid #ccc)`. Hover rule: `border-color: var(--apf-ts-border-color-hov, transparent)` → `…, #353c4e)` (its `color`/`background` fallbacks stay `inherit`/`transparent`, because WAPF's default hover rule re-colours the border only). Selected rule: `border-color`/`background` `transparent` → `#353c4e`, `color` `inherit` → `#fff`. Radius line, declaration order, selector scope and the `:hover`-before-`.opf-checked` ordering are unchanged. |
| `tests/Unit/TextSwatchChipCssTest.php` | The three existing fallback assertions are re-pinned to the owner-decided contract (not deleted); the class docblock now cites both stylesheets and the decision; the three test names say `…_with_the_wapf_default_chip`. |
| `bin/e2e-text-swatch-chip-browser.mjs` | `hover`/`selected` are now **required** in `OPF_TSCHIP_EXPECT` (WAPF's default chip defines all three states); the dead "state omitted → must not be visible" branch (which encoded the rejected contract) is gone; a state expectation may use `color: 'inherit'` to mean "resolves to the container's colour", mirroring the base check. |

Live rules after the change:

```css
.opf-text-swatch-wrapper .opf-swatch--text {
	border: var(--apf-ts-border, 1px solid #ccc);
	background: var(--apf-ts-bg, transparent);
	color: var(--apf-ts-color, inherit);
	border-radius: var(--opf-text-swatch-radius, var(--apf-ts-radius, 4px));
}
.opf-text-swatch-wrapper .opf-swatch--text:hover {
	color: var(--apf-ts-color-hov, inherit);
	border-color: var(--apf-ts-border-color-hov, #353c4e);
	background: var(--apf-ts-bg-hov, transparent);
}
.opf-text-swatch-wrapper .opf-swatch--text.opf-checked {
	border-color: var(--apf-ts-border-color-sel, #353c4e);
	background: var(--apf-ts-bg-sel, #353c4e);
	color: var(--apf-ts-color-sel, #fff);
}
```

`before` = HEAD `d2b5e38` asset, sha256
`fcc928b3e75edeadacdd04320c0e2d6ad8f085907bdb34c051f9a42fbeeef129`.
`after` = this lane's asset, sha256
`3b37849d395e7f048dd2abd4de7fb99c064926b0d8a544c51ab8f97ec3bb2c7d`.
Both were copied into the clone and hash-verified there before their runs.

## Before / after — real Chromium, computed values

Clone: `/home/followersya-5hqi7/opf-test/wordpress`, served by
`wp server --host=127.0.0.1 --port=8090`. WAPF Extended is absent from that clone,
so the only stylesheet under test is OPF's (`link[rel=stylesheet]` recorded in
every artifact).

Fixture (`bin/e2e-text-swatch-chip-fixture.php`): one single-select text swatch,
one multi-choice text swatch, one plain checkbox group and one plain radio group.
`unconfigured` = no `wapf_design_settings` and no `opf_text_swatch_radius` (both
options were absent before and are absent after). `configured` =
`apf-ts-border-width 2px`, `apf-ts-border-color #cccccc`, `apf-ts-bg #121212`,
`apf-ts-color #ffffff`, `apf-ts-radius 22px`, hover `#00ff00`/`#0000ff`/`#a4a4a4`,
selected `#353c4e`/`#ffff00`/`#ff00ff`.

`border` is `width style color`; `color` shows the resolved `rgb()` (the
unconfigured chip's colour is `inherit`, i.e. the wrapper's `rgb(17, 17, 17)`).

| State | Setup | before (HEAD `fcc928b3`) | after (`3b37849d`) |
| --- | --- | --- | --- |
| **base** | unconfigured | `0px none rgb(17,17,17)` / `rgba(0,0,0,0)` / `rgb(17,17,17)` / r `4px` | **`1px solid rgb(204,204,204)`** / `rgba(0,0,0,0)` / `rgb(17,17,17)` / r `4px` |
| **hover** | unconfigured, hovered | `0px none rgba(0,0,0,0)` / transparent / `rgb(17,17,17)` / `4px` | **`1px solid rgb(53,60,78)`** / transparent / `rgb(17,17,17)` / `4px` |
| **selected** | unconfigured, checked | `0px none rgba(0,0,0,0)` / transparent / `rgb(17,17,17)` / `4px` | **`1px solid rgb(53,60,78)`** / **`rgb(53,60,78)`** / **`rgb(255,255,255)`** / `4px` |
| selected + hovered | unconfigured | (no state at all) | keeps the **selected** values — `rgb(53,60,78)` / `rgb(53,60,78)` / `rgb(255,255,255)` |
| multi-choice chip | unconfigured, base/hover/selected | same as single-select | same as single-select (`1px solid rgb(204,204,204)` → hover/selected as above) |
| **base** | configured | `2px solid rgb(204,204,204)` / `rgb(18,18,18)` / `rgb(255,255,255)` / r `22px` | identical |
| **hover** | configured, hovered | `2px solid rgb(164,164,164)` / `rgb(0,255,0)` / `rgb(0,0,255)` / r `22px` | identical |
| **selected** | configured, checked | `2px solid rgb(255,0,255)` / `rgb(53,60,78)` / `rgb(255,255,0)` / r `22px` | identical |
| **radius override** | configured + `opf_text_swatch_radius = 18` | (base only at HEAD: radius `18px`) | `2px solid rgb(204,204,204)` / `rgb(18,18,18)` / `rgb(255,255,255)` / **r `18px`**; hover/selected as configured, radius `18px` |
| **radius override** | unconfigured + `opf_text_swatch_radius = 18` | — | `1px solid rgb(204,204,204)` / transparent / `rgb(17,17,17)` / **r `18px`**; hover `rgb(53,60,78)`, selected `rgb(53,60,78)` / `rgb(53,60,78)` / `rgb(255,255,255)` — radius `18px` in every state, i.e. the OPF override still wins over the 4px fallback |
| plain checkbox | unconfigured, base / hovered / checked | `0px none rgb(17,17,17)` / `rgba(0,0,0,0)` / r `0px`, clip stable | **identical in every state**, clip byte-identical; wrapper `opf-swatch-wrapper wapf-checkboxes`; checked class list `opf-swatch opf-swatch--text wapf-checkbox opf-checked` |
| plain radio | unconfigured, base / hovered / checked | `0px none rgb(17,17,17)` / `rgba(0,0,0,0)` / r `0px`, clip stable | **identical in every state**, clip byte-identical; wrapper `opf-swatch-wrapper wapf-radios`; checked class list `opf-swatch opf-swatch--text wapf-radio opf-single-select opf-checked` |

The configured rows are "identical" by construction (a defined `--apf-ts-*`
always wins over its fallback) and by measurement: the after-run
`configured-override.json` (2026-10-06) contains exactly the values of the
HEAD-CSS run `hover-selected-migrated.json` (2026-10-05, previous lane, CSS
sha256 `fcc928b3…`), which this lane did not modify.

`before`/`after` are separated by a 13-failure before-run against the new
contract: base border, hover border-colour, selected border-colour/background/
colour on both chip variants, plus the four "pixels must change" checks —
`before-unconfigured-default-chip.json` (72 checks, 13 failures). The same
expectations pass on the new CSS: `unconfigured-default-chip.json` (72 checks,
0 failures).

Pixel evidence: every chip/control is captured as a clipped full-page screenshot
of its own box with the native radio/checkbox hidden symmetrically, so the
comparison isolates the chip's decoration. The "unchanged" plain-choice rows
require byte-identical PNGs; the base→hover and base→selected rows require
different bytes. All rows matched their expectation.

Harness results:

| Run (artifact) | Result |
| --- | --- |
| `before-unconfigured-default-chip` | **72 checks, 13 failures** |
| `unconfigured-default-chip` | 72 checks, 0 failures |
| `configured-override` | 72 checks, 0 failures |
| `configured-base-only` | 72 checks, 0 failures |
| `opf-radius-override-hover-selected` | 72 checks, 0 failures |
| `opf-radius-override-unconfigured` | 72 checks, 0 failures |

## Commands

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
CLONE=/home/followersya-5hqi7/opf-test/wordpress
W="wp --path=$CLONE --allow-root"
P=/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json
D=docs/compatibility/textswatchchip-proof-20261005

rsync -a --delete --exclude='.git' --exclude='node_modules' --exclude='vendor' \
  ./ "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/"
cd $CLONE && setsid wp --path=$CLONE --allow-root server --host=127.0.0.1 --port=8090 &

$W eval-file bin/e2e-text-swatch-chip-fixture.php prepare
# TEXT_SWATCH_CHIP_URL=http://opf.test/product/opf-text-swatch-chip/

# unconfigured = WAPF's default chip
$W eval-file bin/e2e-text-swatch-chip-fixture.php design unset
$W eval-file bin/e2e-text-swatch-chip-fixture.php radius unset
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=unconfigured-default-chip \
  OPF_TSCHIP_EXPECT='{"border":"1px solid rgb(204, 204, 204)","background":"rgba(0, 0, 0, 0)","color":"inherit","radius":"4px","hover":{"borderColor":"rgb(53, 60, 78)","background":"rgba(0, 0, 0, 0)","color":"inherit"},"selected":{"borderColor":"rgb(53, 60, 78)","background":"rgb(53, 60, 78)","color":"rgb(255, 255, 255)"}}' \
  OPF_TSCHIP_OUT=$D/unconfigured-default-chip.json \
  node bin/e2e-text-swatch-chip-browser.mjs | tee $D/unconfigured-default-chip.txt

# before: the HEAD asset, hash-verified against the bytes served by the clone
git show HEAD:assets/css/opf-frontend.css > /tmp/opf-frontend.before.css   # sha256 fcc928b3…
cp /tmp/opf-frontend.before.css "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/assets/css/opf-frontend.css"
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=before-unconfigured-default-chip … node bin/e2e-text-swatch-chip-browser.mjs   # 13 failures

# configured design settings still override
$W eval-file bin/e2e-text-swatch-chip-fixture.php design \
  '{"apf-ts-border-width":"2px","apf-ts-border-color":"#cccccc","apf-ts-bg":"#121212","apf-ts-color":"#ffffff","apf-ts-radius":"22px","apf-ts-bg-hov":"#00ff00","apf-ts-color-hov":"#0000ff","apf-ts-border-color-hov":"#a4a4a4","apf-ts-bg-sel":"#353c4e","apf-ts-color-sel":"#ffff00","apf-ts-border-color-sel":"#ff00ff"}'
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=configured-override \
  OPF_TSCHIP_EXPECT='{"border":"2px solid rgb(204, 204, 204)","background":"rgb(18, 18, 18)","color":"rgb(255, 255, 255)","radius":"22px","hover":{"borderColor":"rgb(164, 164, 164)","background":"rgb(0, 255, 0)","color":"rgb(0, 0, 255)"},"selected":{"borderColor":"rgb(255, 0, 255)","background":"rgb(53, 60, 78)","color":"rgb(255, 255, 0)"}}' \
  OPF_TSCHIP_OUT=$D/configured-override.json node bin/e2e-text-swatch-chip-browser.mjs | tee $D/configured-override.txt

# OPF radius override still wins
$W eval-file bin/e2e-text-swatch-chip-fixture.php radius 18
#   … label=opf-radius-override-hover-selected, radius expectation "18px", otherwise as configured
$W eval-file bin/e2e-text-swatch-chip-fixture.php design unset
#   … label=opf-radius-override-unconfigured, radius "18px", otherwise as unconfigured

# documented edge: design settings saved without the state keys
$W eval-file bin/e2e-text-swatch-chip-fixture.php radius unset
$W eval-file bin/e2e-text-swatch-chip-fixture.php design \
  '{"apf-ts-border-width":"2px","apf-ts-border-color":"#383838","apf-ts-bg":"#ffffff","apf-ts-color":"#000000","apf-ts-radius":"6px"}'
#   … label=configured-base-only: hover borderColor rgb(53, 60, 78), selected rgb(53, 60, 78)/rgb(53, 60, 78)/rgb(255, 255, 255)

# cleanup
$W eval-file bin/e2e-text-swatch-chip-fixture.php cleanup
rsync -a --delete --exclude='.git' --exclude='node_modules' --exclude='vendor' \
  ./ "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/"
kill "$(ss -ltnp | grep ':8090' | grep -o 'pid=[0-9]*' | cut -d= -f2)"
rm -f /tmp/opf-frontend.before.css /tmp/opf-wpserver-8090.log
rmdir /tmp/opf-text-swatch-chip-evidence
```

## Cleanup performed

- `cleanup` deleted the fixture product and field group, flushed the field-group
  cache, restored `wapf_design_settings` and `opf_text_swatch_radius` to the
  pre-run state and removed the fixture state file. Both options were **absent
  before and after** (`wp option get` errors "Does it exist?" for both);
  `wp post list --post_type=product` shows no `opf-text-swatch-chip` product (the
  pre-existing `E2E *` products were not touched).
- The clone's plugin directory was re-rsynced from the working tree after the
  before-run, so it no longer holds the HEAD CSS (both copies hash
  `3b37849d…`). WAPF Extended was never installed in the clone during this lane.
- The `wp server` on `127.0.0.1:8090` was killed by PID taken from `ss -ltnp`;
  `ss -ltn` shows the port closed. Two unrelated dev servers (`:8301`, `:8293`)
  were left alone.
- `/tmp/opf-frontend.before.css`, `/tmp/opf-wpserver-8090.log` and the empty
  `/tmp/opf-text-swatch-chip-evidence/` were deleted. No screenshots were written
  to the repository — the pixel comparisons live inside the harness and only the
  JSON/TXT artifacts ship under
  `docs/compatibility/textswatchchip-proof-20261005/`. Production was never
  touched: the only writes were the repo working tree
  (`assets/css/`, `tests/Unit/`, `bin/`, `docs/compatibility/`) and the
  disposable clone.
- The pre-existing artifact `opf-radius-override.json` (previous lane,
  2026-10-05T23:24) was left byte-identical: the new radius run is staged as
  `opf-radius-override-hover-selected.json`.

## Tests

```text
vendor/bin/phpunit --filter TextSwatch
Tests: 14, Assertions: 82, PHPUnit Deprecations: 2.        (0 failures)

vendor/bin/phpunit
Tests: 1050, Assertions: 4449, PHPUnit Deprecations: 2.    (0 failures — baseline 1050/4449)

node --test tests/js/*.test.cjs
tests 129, pass 129, fail 0

php -l tests/Unit/TextSwatchChipCssTest.php      → No syntax errors detected
php -l bin/e2e-text-swatch-chip-fixture.php      → No syntax errors detected (unchanged)
node --check bin/e2e-text-swatch-chip-browser.mjs → OK
```

`assets/css/opf-frontend.css` is plain CSS registered with `wp_register_style`
(`includes/Service/Assets.php:46`) and the plugin has no Tailwind build, so the
`@apply` rule for new CSS does not apply here.

## What is still open

- **Design settings saved without the state keys.** WAPF and OPF both skip a
  `:root` variable whose setting is empty (`class-design-helper.php:1527` /
  `WapfDesign.php:65-69`), so a saved design that clears, say,
  `apf-ts-border-color-hov` now resolves that state through **this lane's
  fallback** (`#353c4e`, `#fff`) instead of WAPF's themed fallback
  (`transparent`, `inherit`). Measured in `configured-base-only.json`: base
  `2px solid rgb(56,56,56)` from the settings, hover border `rgb(53,60,78)`,
  selected `rgb(53,60,78)` / `rgb(53,60,78)` / `rgb(255,255,255)`. This is the
  direct consequence of the owner-decided fallbacks (they are WAPF's *default
  chip* values, which are exactly what the unconfigured state must show); the
  mixed case is the one place where the two WAPF stylesheets disagree. Folding in
  the themed fallbacks per-state would need two different fallback sources and is
  not implemented.
- **Existing unconfigured OPF text swatches gain a 1px grey border** (and a dark
  selected state) — the intended, owner-decided visual change. Any storefront
  snapshot taken before this commit will differ for those chips.
- `configured-base-only.json` also shows that the base border width still comes
  from `--apf-ts-border` even in the mixed case, so a hover/selected border
  colour is visible only when a border exists (WAPF behaviour, unchanged).
- Per-field authoring of the chip and the cart/order lifecycle of text swatches
  are unchanged and not re-proved by this run.
