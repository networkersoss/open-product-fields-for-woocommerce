# Text-swatch chip hover + selected states — fix + real-Chromium proof (2026-10-05, follow-up)

Follow-up to `TEXT-SWATCH-CHIP-DECORATION-EVIDENCE-2026-10-05.md`, which applied
the base decoration (`--apf-ts-border`, `--apf-ts-bg`, `--apf-ts-color`,
`--apf-ts-radius`) to OPF's text-swatch chip and left the hover/selected states
as an open item. This run closes that item: the `-hov` and `-sel` variables
(`--apf-ts-color-hov/-sel`, `--apf-ts-bg-hov/-sel`,
`--apf-ts-border-color-hov/-sel`) were already emitted by
`Engine\WapfDesign::css()` and had no consumer at all.

## The 3.1.5 contract (hover and selected)

Installed baseline:
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`
(header `Version: 3.1.5`, `advanced-product-fields-for-woocommerce-extended.php:7`).

Both stylesheets are minified to a **single line**, so the anchors are character
offsets inside the file.

| Contract | Installed Extended 3.1.5 source |
| --- | --- |
| Settings: text swatch states | `includes/classes/class-design-helper.php:538-587` — `apf-ts` is a `states` setting with `default`/`hov`/`sel` and inner settings `color`, `bg`, `border-color`; `start_with` `[null,null,'#fff']`, `['transparent','transparent','#121212']`, `['#ccc','#a4a4a4','#121212']` and `fallback` `inherit`, `transparent`, `transparent` |
| Sanitizer type map | `includes/classes/class-design-helper.php:1175-1185` — every `apf-ts-*` state key is `color` |
| Emitted variables (`:root`) | `includes/classes/class-design-helper.php:1514-1516` — the key list literally contains `apf-ts-color-hov`, `apf-ts-color-sel`, `apf-ts-bg-hov`, `apf-ts-bg-sel`, `apf-ts-border-color-hov`, `apf-ts-border-color-sel` |
| Base rule (themed) | `assets/css/frontend-themed.min.css` char 6500 — `.wapf-swatch--text{margin:0 15px 15px 0;border-radius:var(--apf-ts-radius,4px);border:var(--apf-ts-border,none);color:var(--apf-ts-color,inherit);background:var(--apf-ts-bg,transparent)}` |
| **Hover rule** | same file, char 6728 — `.wapf-swatch--text:hover{color:var(--apf-ts-color-hov,inherit);border-color:var(--apf-ts-border-color-hov,transparent);background:var(--apf-ts-bg-hov,transparent)}` |
| **Selected rule** | same file, char 6891 — `.wapf-swatch--text.wapf-checked{border-color:var(--apf-ts-border-color-sel,transparent);background:var(--apf-ts-bg-sel,transparent);color:var(--apf-ts-color-sel,inherit)}` |
| Selected selector is a class, not `:checked` | `includes/classes/class-html.php:597` adds `wapf-checked` to an initially selected choice; `assets/js/frontend.min.js` (`O="wapf-"`, char 15) handles `d.on("change",".wapf-swatch input", …)` at char 19924 and calls `e[t.is(":checked")?"addClass":"removeClass"]("wapf-checked")` at char 20113 — so both the single-select and the multi-choice text swatch carry the same class |
| Multi-choice text swatch | `views/frontend/fields/multi-text-swatch.php:18` — same `.wapf-swatch--text` chip with a checkbox input and **no** `wapf-single-select` (the single-select variant is `views/frontend/fields/text-swatch.php:17`) |

Order matters: `:hover` and `.wapf-checked` have equal specificity, so WAPF's
declaration order (hover at char 6728, selected at 6891) makes the *selected*
values win on a chip that is both selected and hovered. OPF mirrors that order.

The hover and selected rules only re-colour the chip: they set `color`,
`background`/`background-color` and `border-color`, never `border-width`,
`border-style` or `border-radius`, so the base `--apf-ts-border` width and the
radius keep applying in every state (verified below).

## Which WAPF stylesheet is served, and the no-design-settings default border (verified, not changed)

- Design settings exist: `Design_Helper::write_css()` reads
  `assets/css/frontend-themed.min.css`, splits it at `/*! CSS_VAR_DIVIDER */` and
  writes `assets/css/frontend.min.css` with the generated `:root` block
  (`includes/classes/class-design-helper.php:1446-1478`).
- **No design settings:** `Design_Helper::generate_css()` with an empty option
  calls `set_default_css()` (`class-design-helper.php:1137-1146`), which byte-copies
  `frontend-default.min.css` → `frontend.min.css`
  (`class-design-helper.php:1308-1311`). The public controller enqueues
  `assets/css/frontend.min.css` and regenerates it via `generate_css()` when the
  file is missing (`includes/controllers/class-public-controller.php:182-186`).
  The shipped 3.1.5 package contains no `frontend.min.css` — this host's installed
  copy has none — so a fresh install with no design settings is served the
  **default** stylesheet.
- What `frontend-default.min.css` declares for the chip (char offsets):
  - char 4976 — `.wapf-swatch--text{margin:0 15px 15px 0;border-radius:4px;border:1px solid #ccc}`
  - char 5098 — `.wapf-swatch--text:hover{border-color:#353c4e}`
  - char 5144 — `.wapf-swatch--text.wapf-checked{border-color:#353c4e;background:#353c4e;color:#fff}`
- Confirmed: with no design settings, WAPF's chip is **bordered**
  (`1px solid #ccc`), and its hover/selected colours are hard-coded `#353c4e`
  (selected: white text on the dark background) rather than variable-driven.
  OPF is borderless with an unset design layer and now falls back to
  `none`/`transparent`/`inherit` for every state, matching WAPF's *themed*
  fallbacks (what this row implements).
- **Not adopted.** Adding `1px solid #ccc` (and the hard-coded `#353c4e`
  states) to every OPF text swatch on a site that never configured the design
  layer is a user-visible change and a product decision, so OPF still does not
  reproduce `frontend-default.min.css`. It stays open for the parent.

## What changed

| File | Change |
| --- | --- |
| `assets/css/opf-frontend.css` | Two new rules after the existing base rule: `.opf-text-swatch-wrapper .opf-swatch--text:hover` (`color`/`border-color`/`background` from the `-hov` variables) and `.opf-text-swatch-wrapper .opf-swatch--text.opf-checked` (`border-color`/`background`/`color` from the `-sel` variables), in WAPF's declaration order and with WAPF's fallbacks. Base decoration and radius are untouched. |
| `tests/Unit/TextSwatchChipCssTest.php` | +4 tests (8 total): hover rule, selected rule, source order (selected overrides hover; base before both), and one scoped consumer per state variable including the no-bare-selector guard; the emission test now covers all six state keys, the scope test covers the multi-choice chip, and a new test pins that the renderer emits `opf-checked` for a selected chip. |
| `bin/e2e-text-swatch-chip-fixture.php` | Adds a multi-choice text swatch (`finish_multi`, `multiple: true`) so the shared `.wapf-swatch--text` contract is proved for `multi-text-swatch.php:18` too. |
| `bin/e2e-text-swatch-chip-browser.mjs` | Extends the harness to the hover and selected states: exact values when `OPF_TSCHIP_EXPECT.hover`/`.selected` are given, "no visible change" when a state is unconfigured, plus pixel-clip comparisons and the same state matrix on the plain checkbox/radio choices. |

## Why the wrapper scope is unchanged

OPF reuses `.opf-swatch--text` for plain checkbox and radio choices
(`includes/Service/Renderer.php:1200`), so all three rules stay on
`.opf-text-swatch-wrapper .opf-swatch--text` — the class the renderer adds only
to a real text swatch (`includes/Service/Renderer.php:1126`). The state rules
add the `:hover` / `.opf-checked` part to that same scope, exactly as WAPF adds
it to `.wapf-swatch--text`.

`.opf-checked` is OPF's equivalent of WAPF's `wapf-checked`: the renderer emits
it on an initially selected choice (`includes/Service/Renderer.php:1221`) and the
frontend script toggles it on every `.opf-swatch` when a radio/checkbox changes.

## Before / after — real Chromium, computed values

`before` = `93e33a8` CSS (`git show HEAD:assets/css/opf-frontend.css`), sha256
`080b1410f7f3d031d908b6aa7f4f6d41c5e080832ea6141eeec8d675f3d40c61`, copied into
the clone and hash-verified there.
`after` = this lane's CSS, sha256
`fcc928b3e75edeadacdd04320c0e2d6ad8f085907bdb34c051f9a42fbeeef129`, rsynced and
hash-verified in the clone before the after-runs.
All runs were made with WAPF Extended absent from the clone, so the only
stylesheet under test is OPF's.

`migrated` = `wapf_design_settings` with
`apf-ts-border-width 2px`, `apf-ts-border-color #cccccc`, `apf-ts-bg #121212`,
`apf-ts-color #ffffff`, `apf-ts-radius 22px`, `apf-ts-bg-hov #00ff00`,
`apf-ts-color-hov #0000ff`, `apf-ts-border-color-hov #a4a4a4`,
`apf-ts-bg-sel #353c4e`, `apf-ts-color-sel #ffff00`,
`apf-ts-border-color-sel #ff00ff`.
`migrated-b` = the same shape with different values (`3px solid #ff0000`,
`#ffffff`/`#000000`, hover `#ffff00`/`#ff0000`/`#0000ff`, selected
`#000000`/`#00ff00`/`#00ffff`, no radius key), to prove the values come from the
variables and not from a hard-coded chip style.

| State | Setup | computed `border` / `background` / `color` before | after |
| --- | --- | --- | --- |
| base (`migrated`) | not hovered, not selected | `2px solid rgb(204,204,204)` / `rgb(18,18,18)` / `rgb(255,255,255)` | identical |
| **hover, single-select** (`migrated`) | hovered chip, unselected | `2px solid rgb(204,204,204)` / `rgb(18,18,18)` / `rgb(255,255,255)` (hover ignored) | **`2px solid rgb(164,164,164)` / `rgb(0,255,0)` / `rgb(0,0,255)`** |
| **hover, multi-choice** (`migrated`) | hovered chip, unselected | same as single-select (hover ignored) | same `rgb(164,164,164)` / `rgb(0,255,0)` / `rgb(0,0,255)` |
| **selected, single-select** (`migrated`) | chip checked, mouse parked | `2px solid rgb(204,204,204)` / `rgb(18,18,18)` / `rgb(255,255,255)` (selected ignored) | **`2px solid rgb(255,0,255)` / `rgb(53,60,78)` / `rgb(255,255,0)`** |
| **selected, multi-choice** (`migrated`) | chip checked, mouse parked | same as single-select | same `rgb(255,0,255)` / `rgb(53,60,78)` / `rgb(255,255,0)` |
| selected + hovered (`migrated`) | checked then hovered | base values | keeps the **selected** values (`rgb(53,60,78)` / `rgb(255,255,0)` / `rgb(255,0,255)`) — WAPF's cascade |
| radius in every state (`migrated`) | — | `22px` | `22px` (state rules never re-declare it) |
| **`migrated-b` hover / selected** | different values, no radius | base `3px solid rgb(255,0,0)` / `rgb(255,255,255)` / `rgb(0,0,0)`, radius `4px` | hover `rgb(0,0,255)` / `rgb(255,255,0)` / `rgb(255,0,0)`; selected `rgb(0,255,255)` / `rgb(0,0,0)` / `rgb(0,255,0)`; radius `4px` |
| **`unconfigured` base** | no `wapf_design_settings`, no OPF radius | `0px none rgb(17,17,17)` / `rgba(0,0,0,0)` / inherited `rgb(17,17,17)` / `4px` | **identical, and the rendered clip is byte-identical** |
| **`unconfigured` hover / selected** | as above, hovered / checked | `0px none rgb(17,17,17)` / transparent / inherited / `4px`, clip identical to base | same box (`0px none`), same `rgba(0,0,0,0)` background, same inherited `rgb(17,17,17)` colour, **clip byte-identical to base**. The only resolved change is `border-color` → `rgba(0,0,0,0)` (WAPF's `transparent` fallback) on a box whose border width is `0px`/`none`, so nothing paints |
| plain checkbox control, every state | `[data-opf-field="extras"] .opf-swatch--text` base / hovered / checked | `0px none rgb(17,17,17)` / `rgba(0,0,0,0)` / `0px` radius, clip stable | **identical in every state**, class list after checking is `opf-swatch opf-swatch--text wapf-checkbox opf-checked` — selected, surrounded by the unscoped wrapper, undecorated |
| plain radio control, every state | `[data-opf-field="size"] .opf-swatch--text` base / hovered / checked | `0px none rgb(17,17,17)` / `rgba(0,0,0,0)` / `0px` radius, clip stable | **identical in every state**, class list after checking is `opf-swatch opf-swatch--text wapf-radio opf-single-select opf-checked` |

Pixel evidence: each chip/control is captured as a clipped full-page screenshot
of its own box with the native radio/checkbox hidden symmetrically in every
capture (OPF does not hide the unstyled native control, so it paints its own
check mark — the comparison isolates the chip's decoration). Identical PNG bytes
are required for the "unchanged" rows and different bytes for the configured
rows; all rows matched their expectation.

Harness results (72 checks in the configured states, 66 in the unconfigured
state): `before-hover-selected-migrated` **16 failures** (hover and selected
border-colour/background/colour on both chip variants, plus the four
"pixels must change" checks), `before-hover-selected-unconfigured` 0 failures,
`hover-selected-unconfigured` 0 failures, `hover-selected-migrated` 0 failures,
`hover-selected-migrated-b` 0 failures.

Artifacts (`docs/compatibility/textswatchchip-proof-20261005/`):
`before-hover-selected-migrated.{json,txt}`,
`before-hover-selected-unconfigured.{json,txt}`,
`hover-selected-unconfigured.{json,txt}`, `hover-selected-migrated.{json,txt}`,
`hover-selected-migrated-b.{json,txt}` (the `.txt` files are the verbatim
harness output).

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

# after (this lane's CSS)
$W eval-file bin/e2e-text-swatch-chip-fixture.php design unset
$W eval-file bin/e2e-text-swatch-chip-fixture.php radius unset
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=hover-selected-unconfigured \
  OPF_TSCHIP_EXPECT='{"border":"none","background":"rgba(0, 0, 0, 0)","color":"inherit","radius":"4px"}' \
  OPF_TSCHIP_OUT=docs/compatibility/textswatchchip-proof-20261005/hover-selected-unconfigured.json \
  node bin/e2e-text-swatch-chip-browser.mjs | tee docs/compatibility/textswatchchip-proof-20261005/hover-selected-unconfigured.txt

$W eval-file bin/e2e-text-swatch-chip-fixture.php design \
  '{"apf-ts-border-width":"2px","apf-ts-border-color":"#cccccc","apf-ts-bg":"#121212","apf-ts-color":"#ffffff","apf-ts-radius":"22px","apf-ts-bg-hov":"#00ff00","apf-ts-color-hov":"#0000ff","apf-ts-border-color-hov":"#a4a4a4","apf-ts-bg-sel":"#353c4e","apf-ts-color-sel":"#ffff00","apf-ts-border-color-sel":"#ff00ff"}'
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=hover-selected-migrated \
  OPF_TSCHIP_EXPECT='{"border":"2px solid rgb(204, 204, 204)","background":"rgb(18, 18, 18)","color":"rgb(255, 255, 255)","radius":"22px","hover":{"borderColor":"rgb(164, 164, 164)","background":"rgb(0, 255, 0)","color":"rgb(0, 0, 255)"},"selected":{"borderColor":"rgb(255, 0, 255)","background":"rgb(53, 60, 78)","color":"rgb(255, 255, 0)"}}' \
  OPF_TSCHIP_OUT=docs/compatibility/textswatchchip-proof-20261005/hover-selected-migrated.json \
  node bin/e2e-text-swatch-chip-browser.mjs | tee docs/compatibility/textswatchchip-proof-20261005/hover-selected-migrated.txt
# … then migrated-b (3px solid #ff0000, #ffff00/#ff0000/#0000ff, #000000/#00ff00/#00ffff)

# before: the HEAD asset, hash-verified against the bytes served by the clone
git show HEAD:assets/css/opf-frontend.css > /tmp/opf-frontend.before.css
cp /tmp/opf-frontend.before.css "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/assets/css/opf-frontend.css"
$W eval-file bin/e2e-text-swatch-chip-fixture.php design unset
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=before-hover-selected-unconfigured … node bin/e2e-text-swatch-chip-browser.mjs   # 66/66 ok
$W eval-file bin/e2e-text-swatch-chip-fixture.php design '{…migrated…}'
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=before-hover-selected-migrated … node bin/e2e-text-swatch-chip-browser.mjs       # 16 failures

# cleanup
rsync -a --delete --exclude='.git' --exclude='node_modules' --exclude='vendor' \
  ./ "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/"
$W eval-file bin/e2e-text-swatch-chip-fixture.php cleanup
pkill -f "127.0.0.1:8090"
```

## Cleanup performed

- `cleanup` deleted the fixture product and field group, flushed the field-group
  cache, restored `wapf_design_settings` and `opf_text_swatch_radius` to their
  pre-run state (both were absent before and are absent after — re-verified with
  `wp option get`, both error "Does it exist?") and removed the fixture state
  file. `wp post list` confirms no `opf-text-swatch-chip` product remains; the
  pre-existing `E2E *` field groups from other lanes were not touched.
- The clone's plugin directory was re-rsynced from the working tree after the
  before-runs, so it no longer holds the HEAD CSS (verified by sha256).
- The `wp server` on `127.0.0.1:8090` was killed; `ss -ltn` shows the port
  closed. Production was never touched: the only writes went to
  `/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce`
  and to the disposable clone.
- Temporary probe scripts and the copied `before` CSS lived in `/tmp` and were
  deleted; the fixture's empty `/tmp/opf-text-swatch-chip-evidence/` directory
  was removed. No screenshots were written to the repository — the pixel
  comparisons are inside the harness and only the JSON/TXT artifacts are shipped.

## Tests

```text
vendor/bin/phpunit --filter TextSwatch     → 14 tests, 82 assertions, 0 failures   (8 chip + 6 radius)
vendor/bin/phpunit                         → 1045 tests, 4438 assertions, 0 failures   (baseline 1041/4405)
node --test tests/js/*.test.cjs            → 129 pass, 0 fail
```

The 2 `PHPUnit Deprecations` are pre-existing and environment-level: the
untouched `--filter TextSwatchRadiusTest` run reports the same 2.

No existing test was weakened or removed; `TextSwatchRadiusTest.php` (6 tests)
still passes unchanged.

## What is still open

- **The no-design-settings chip** (`frontend-default.min.css`: `1px solid #ccc`,
  hover/selected `#353c4e`): verified above, deliberately not adopted. Folding it
  in would add a grey border and hard-coded state colours to every existing OPF
  text swatch on a site that never configured the design layer.
- **A configured state colour without a configured border**: because WAPF's state
  rules only set `border-color`, a selected/hover border colour is invisible when
  `apf-ts-border-width`/`-border-color` are empty (`border-style: none`). OPF now
  reproduces that behaviour exactly; it is WAPF fidelity, not an OPF regression.
- Per-field authoring of the chip and the cart/order lifecycle of text swatches
  are unchanged and not re-proved by this run.
