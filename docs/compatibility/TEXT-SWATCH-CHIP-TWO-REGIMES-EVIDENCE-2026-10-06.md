# Text-swatch chip: both WAPF regimes, moved from the CSS fallbacks into the emitted variables (2026-10-06)

Fourth item of the text-swatch chip series, and the closure of the open item
recorded by the 2026-10-06 lane
(`TEXT-SWATCH-CHIP-WAPF-DEFAULT-EVIDENCE-2026-10-06.md`, "What is still open" →
*Design settings saved without the state keys*).

WAPF Extended 3.1.5 ships **two** chip regimes, and the previous lane could only
express one of them with static CSS fallbacks: it put the *default stylesheet*
values (`1px solid #ccc` / `#353c4e` / `#fff`) into the fallbacks, which is right
while no design settings exist but wrong as soon as design settings are saved
with a chip state key left empty — WAPF then serves the *themed* stylesheet and
resolves that state through `none` / `transparent` / `inherit`.

This lane moves the regime decision out of the CSS and into the emitted
variables: while the design option is empty OPF emits the default chip as
`--apf-ts-*` variables, the CSS fallbacks revert to WAPF's themed ones, and both
regimes therefore resolve exactly as WAPF resolves them.

## How WAPF distinguishes the two regimes (installed 3.1.5 source)

Installed baseline: `/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`,
header `Version: 3.1.5` (`advanced-product-fields-for-woocommerce-extended.php:7`).

| Question | Installed Extended 3.1.5 source |
| --- | --- |
| The decision itself | `includes/classes/class-design-helper.php:1137-1146` `generate_css()`: `if( ! $raw_settings ) { $raw_settings = get_option( 'wapf_design_settings', false ); if( empty( $raw_settings ) ) { self::set_default_css(); return true; } }` |
| Empty option → default stylesheet | `:1308-1311` `set_default_css()` byte-copies `assets/css/frontend-default.min.css` over `frontend.min.css` |
| Which file the storefront gets | `includes/controllers/class-public-controller.php:176-186` — `register_assets()` regenerates when `assets/css/frontend.min.css` is missing, then enqueues `css/frontend.min.css` |
| Non-empty option → generated stylesheet | `includes/controllers/class-admin-controller.php:640-648` saves the sanitized settings (see below) and `write_css()` (`:1446`) splits `assets/css/frontend-themed.min.css` at `/*! CSS_VAR_DIVIDER */` (char 0) and prepends the variable block |
| A variable is emitted only when its setting is non-empty | `:1507` `design_settings_to_variables_css()`, `:1527` `if( ! empty( $design_settings[ $var ] ) )` |
| A cleared setting can be stored as an empty value | `:1676-1678` `sanitize_css_setting()` returns `$setting_config['start_with'] ?? null` for a missing/blank posted value, and the text-swatch state config sets `'start_with' => [ null, null, '#fff' ]` for the text colour (`:552`) — so `apf-ts-color` / `apf-ts-color-hov` are stored as `null` and skipped at `:1527` |
| The border shorthand's own fallback | `:1652-1666` `create_border()` returns `'none'` when either part is empty, and the variable is emitted unconditionally (`:1535-1536`) |

So the two regimes are:

- **Regime A — no design settings.** `empty( get_option( 'wapf_design_settings',
  false ) )` is true, WAPF serves `frontend-default.min.css`, whose chip is stated
  as plain declarations (single line, char offsets, sha256
  `8e6c730b36af8163960907f8652577ae65dba6581673c9f150a82f7ea0900182`):

  | char | declaration |
  | --- | --- |
  | 4976 | `.wapf-swatch--text{margin:0 15px 15px 0;border-radius:4px;border:1px solid #ccc}` |
  | 5098 | `.wapf-swatch--text:hover{border-color:#353c4e}` |
  | 5144 | `.wapf-swatch--text.wapf-checked{border-color:#353c4e;background:#353c4e;color:#fff}` |

- **Regime B — design settings saved.** The generated stylesheet is served from
  `frontend-themed.min.css` (sha256
  `e67463bdf8255f201e2c5f21feeda0e1d51250f6e3318b3a5e2653751043390b`) and the
  chip resolves through variables with the *themed* fallbacks:

  | char | declaration |
  | --- | --- |
  | 6500 | `.wapf-swatch--text{margin:0 15px 15px 0;border-radius:var(--apf-ts-radius,4px);border:var(--apf-ts-border,none);color:var(--apf-ts-color,inherit);background:var(--apf-ts-bg,transparent)}` |
  | 6728 | `.wapf-swatch--text:hover{color:var(--apf-ts-color-hov,inherit);border-color:var(--apf-ts-border-color-hov,transparent);background:var(--apf-ts-bg-hov,transparent)}` |
  | 6891 | `.wapf-swatch--text.wapf-checked{border-color:var(--apf-ts-border-color-sel,transparent);background:var(--apf-ts-bg-sel,transparent);color:var(--apf-ts-color-sel,inherit)}` |

The predicate is therefore `empty()` on the **raw option**, which has three
observable outcomes — mirrored exactly, not approximated:

| Option state | WAPF regime | OPF decision (`Engine/WapfDesign.php`) |
| --- | --- | --- |
| option missing | default stylesheet | `settings()` → `[]`, `serves_default_stylesheet()` → true |
| option saved as an **empty array** | default stylesheet (`empty( [] )` is true) | same — `serves_default_stylesheet()` → true |
| option saved with **any** key (state or not, e.g. only `apf-radius`) | generated stylesheet | `serves_default_stylesheet()` → false |

## What changed

| File | Change |
| --- | --- |
| `includes/Engine/WapfDesign.php` | New `DEFAULT_CHIP_VARIABLES` const carrying the default stylesheet's chip as the six `--apf-ts-*` variables OPF's rules read (`border`, `border-color-hov`, `border-color-sel`, `bg-sel`, `color-sel`, `radius`), plus `serves_default_stylesheet()` documenting the WAPF `empty()` predicate (`:1139-1144`). `css()` now returns that block while the option is empty instead of `''`; the configured branch is untouched. |
| `assets/css/opf-frontend.css` | The three scoped rules' fallbacks revert to the themed ones: base `border: var(--apf-ts-border, none)`, hover `border-color: var(--apf-ts-border-color-hov, transparent)`, selected `border-color/background` → `transparent` and `color` → `inherit`. Selector scope, declaration order (selected after hover), the OPF radius chain and the wrapper scoping are unchanged. |
| `includes/Service/Renderer.php` | `emit_wapf_design_css()` no longer early-returns on `'' === $css` (impossible now) and its docblock states the new postcondition. **Unrelated one-token fix carried here:** `:253` `(string) OPF_E2E_TOKEN` → `(string) \OPF_E2E_TOKEN` (see "Unrelated fix" below). |
| `tests/Unit/TextSwatchChipCssTest.php` | Three fallback assertions re-pinned to the themed values (rewritten in place, not deleted) and four new tests pinning the regime decision: missing option emits the default chip, saved-but-empty option emits the default chip, a saved non-state key switches to the themed regime, empty state keys emit no variable. 12 tests / 79 assertions (was 8 tests). |
| `tests/Unit/WapfDesignTest.php` | `test_empty_option_produces_no_css` re-pinned (not deleted) to `test_empty_option_emits_only_the_wapf_default_chip_variables`, asserting the exact `:root` block. |
| `tests/Unit/WapfHooksBridgeTest.php` | `test_bridge_is_a_no_op_without_wapf_listeners` primes `Renderer::$design_css_hash` before capturing, because the design block is printed once per settings revision; the byte-identity assertion between bridged and unbridged output is unchanged. |
| `bin/e2e-text-swatch-chip-fixture.php` | `design empty` phase: `update_option( 'wapf_design_settings', [] )`, the saved-but-empty option. |
| `bin/e2e-text-swatch-chip-browser.mjs` | Records the emitted `#opf-wapf-design-css` text in the artifact, checks it is printed exactly once and always defines `--apf-ts-border`, and derives the "pixels must change / stay identical" expectation from the decoration instead of hard-coding `true` (a themed-empty state paints nothing). |

Live rules after the change (identical to WAPF's themed declarations except for
the scope, which WAPF does not need because it never puts `.wapf-swatch--text` on
plain checkbox/radio choices):

```css
.opf-text-swatch-wrapper .opf-swatch--text {
	border: var(--apf-ts-border, none);
	background: var(--apf-ts-bg, transparent);
	color: var(--apf-ts-color, inherit);
	border-radius: var(--opf-text-swatch-radius, var(--apf-ts-radius, 4px));
}
.opf-text-swatch-wrapper .opf-swatch--text:hover {
	color: var(--apf-ts-color-hov, inherit);
	border-color: var(--apf-ts-border-color-hov, transparent);
	background: var(--apf-ts-bg-hov, transparent);
}
.opf-text-swatch-wrapper .opf-swatch--text.opf-checked {
	border-color: var(--apf-ts-border-color-sel, transparent);
	background: var(--apf-ts-bg-sel, transparent);
	color: var(--apf-ts-color-sel, inherit);
}
```

Emitted variables in regime A (measured text content of the inline block, byte
identical for a missing option and for a saved-but-empty option):

```text
:root{--apf-ts-border:1px solid #ccc;--apf-ts-border-color-hov:#353c4e;--apf-ts-border-color-sel:#353c4e;--apf-ts-bg-sel:#353c4e;--apf-ts-color-sel:#fff;--apf-ts-radius:4px}
```

Deliberately omitted: `--apf-ts-color`, `--apf-ts-bg`, `--apf-ts-color-hov`,
`--apf-ts-bg-hov`. The default stylesheet does not declare the base colour or
background at all and its hover rule re-colours the border only, so those four
must stay unset and resolve to `inherit`/`transparent` — exactly the default
chip's resolution.

## Unrelated fix carried in this lane

`includes/Service/Renderer.php:253` (`viewer_bypasses_gate()`) read the global
constant unqualified inside `namespace OPF\Service`, which the repository's
static-analysis lens reports as an undefined constant
(`Undefined constant 'OPF\Service\OPF_E2E_TOKEN'`). The line was byte-identical
to `HEAD` before this change (same byte sequence at `HEAD:253`; the identical
construct is already recorded as pre-existing for another lane in
`docs/compatibility/hint-conversion-20261005/renderer-analyzer-diagnostics-proof.txt`).
Runtime semantics are unchanged — PHP falls back to the global constant when the
namespaced one does not exist — so the one-token qualification `\OPF_E2E_TOKEN`
is a no-op at runtime that clears the analyzer blocker in a file this lane owns.

## Before / after — real Chromium on the disposable clone

Clone: `/home/followersya-5hqi7/opf-test/wordpress`, served by
`wp server --host=127.0.0.1 --port=8090`. WAPF Extended is **absent** from the
clone, so the only `--apf-ts-*` source on the page is OPF's own emitted block —
which is exactly what this lane has to prove.

Fixture (`bin/e2e-text-swatch-chip-fixture.php`): one single-select text swatch,
one multi-choice text swatch, one plain checkbox group and one plain radio group.
The plugin directory in the clone was hash-verified against the working tree
immediately before the after-runs (CSS `5f3afad5…`, `WapfDesign.php`
`f91dbb32…`, `Renderer.php` `c4ccf1d0…`, fixture `d18f252a…`, harness
`671c28fa…`); the before-runs used the `HEAD` blobs copied in and hash-verified
there (CSS `3b37849d…`, `WapfDesign.php` `c7cf89ae…`, `Renderer.php`
`b181e53a…`).

Values are `border (width style colour) / background / colour / radius`; the
`color` column is Chrome's resolved `rgb()` (`inherit` is checked against the
wrapper's colour, which is `rgb(17, 17, 17)` in this clone).

### Regime A — the default chip (base, hover, selected)

| Setup | State | before (`HEAD`) | after |
| --- | --- | --- | --- |
| option missing | base | `1px solid rgb(204,204,204)` / transparent / `rgb(17,17,17)` / `4px` | identical |
| option missing | hover | `1px solid rgb(53,60,78)` / transparent / `rgb(17,17,17)` / `4px` | identical |
| option missing | selected | `1px solid rgb(53,60,78)` / `rgb(53,60,78)` / `rgb(255,255,255)` / `4px` | identical |
| option saved as `[]` | base / hover / selected | not measured (`HEAD` has no distinct behaviour) | **identical to the missing-option rows**, same emitted block |
| option missing | selected + hovered | keeps the selected values | keeps the selected values (`rgb(53,60,78)` / `rgb(53,60,78)` / `rgb(255,255,255)`) |
| both, multi-choice chip | base / hover / selected | as single-select | as single-select |

The before-run `before-two-regimes-regime-a-missing-option` reports **74 checks,
2 failures**, and both failures are the new harness checks that the design
variables are printed (`0` blocks found, `--apf-ts-border` absent). Every
*computed-value* check, including the plain-choice regression, passed at `HEAD` —
regime A's appearance is unchanged by this lane; what changes is where it comes
from.

After: `two-regimes-regime-a-missing-option` and
`two-regimes-regime-a-saved-empty-option` both report **74 checks, 0 failures**,
with the byte-identical emitted block quoted above.

### Regime B with the state keys deliberately empty (the previous lane's open item)

Design option saved (non-empty, so WAPF serves the generated stylesheet) but the
chip state keys blank:

```json
{"apf-radius":"6px","apf-ts-color-hov":"","apf-ts-bg-hov":"","apf-ts-border-color-hov":"",
 "apf-ts-color-sel":"","apf-ts-bg-sel":"","apf-ts-border-color-sel":""}
```

Emitted block measured on the page (no `-hov`/`-sel` variable, border falls back
to `create_border()`'s `none`, radius to the CSS fallback):

```text
:root{--apf-radius:6px;--apf-input-border:none;--apf-ts-border:none;--apf-is-border:none;--apf-cs-border:none;--apf-card-border:none;--apf-date-border-color:#dddddd;--apf-date-color-muted:rgba(33,33,33,0.45)}
```

| State | before (`HEAD`, the old fallbacks) | after (themed fallbacks) | expected by WAPF's themed CSS |
| --- | --- | --- | --- |
| base | `0px none rgb(17,17,17)` / transparent / `rgb(17,17,17)` / `4px` | identical | `none` / `transparent` / `inherit` ✓ |
| hover | `0px none rgb(53,60,78)` / transparent / `rgb(17,17,17)` / `4px` | **`0px none rgba(0,0,0,0)` / transparent / `rgb(17,17,17)` / `4px`** | `border-color: transparent`, colour/background untouched ✓ |
| selected | `0px none rgb(53,60,78)` / **`rgb(53,60,78)`** / **`rgb(255,255,255)`** / `4px` | **`0px none rgba(0,0,0,0)` / transparent / `rgb(17,17,17)` / `4px`** | `transparent` / `transparent` / `inherit` ✓ |
| selected + hovered | keeps the selected (wrong) values | keeps the themed values | ✓ |
| multi-choice chip | as single-select | as single-select | ✓ |
| rendered pixels | hover/selected still repaint (grey border colour and a dark selected fill) | hover and selected are pixel-identical to the base — a themed-empty state paints nothing | ✓ |

`before-two-regimes-regime-b-empty-state`: **74 checks, 10 failures** —
hover border colour on both chip variants (the old `#353c4e` fallback leaking
into the themed regime), selected border colour/background/colour on both
variants, plus the two "selected pixels must stay unchanged" clips (the old
fallback painted a dark chip where WAPF paints nothing).
`two-regimes-regime-b-empty-state`: **74 checks, 0 failures**.

### Regime B with the keys configured (configured values win)

Design option saved with the full text-swatch set (`apf-ts-border-width 2px`,
`apf-ts-border-color #cccccc`, `apf-ts-bg #121212`, `apf-ts-color #ffffff`,
`apf-ts-radius 22px`, hover `#00ff00`/`#0000ff`/`#a4a4a4`, selected
`#353c4e`/`#ffff00`/`#ff00ff`).

| State | before (`HEAD`) | after |
| --- | --- | --- |
| base | `2px solid rgb(204,204,204)` / `rgb(18,18,18)` / `rgb(255,255,255)` / `22px` | identical |
| hover | `2px solid rgb(164,164,164)` / `rgb(0,255,0)` / `rgb(0,0,255)` / `22px` | identical |
| selected | `2px solid rgb(255,0,255)` / `rgb(53,60,78)` / `rgb(255,255,0)` / `22px` | identical |
| selected + hovered | keeps the selected values | keeps the selected values |
| multi-choice chip | as single-select | as single-select |

`two-regimes-regime-b-configured`: **74 checks, 0 failures** (the before-value is
the `HEAD`-CSS run of the previous lane, `configured-override.json` /
`hover-selected-migrated.json`, and is reproduced byte-for-byte here).

### OPF radius override in both regimes

| Setup | State | Radius | Decoration |
| --- | --- | --- | --- |
| `opf_text_swatch_radius = 18`, no design settings | base / hover / selected | **`18px`** | default chip (`1px solid rgb(204,204,204)`, selected `rgb(53,60,78)` / `rgb(53,60,78)` / `rgb(255,255,255)`) |
| `opf_text_swatch_radius = 18`, configured design (`apf-ts-radius 22px`) | base / hover / selected | **`18px`** | configured values |

Both runs pass **74 checks, 0 failures**
(`two-regimes-radius-regime-a`, `two-regimes-radius-regime-b-configured`): the
OPF override keeps winning over both the emitted `4px` default and the configured
`22px`, in every state.

### Plain checkbox and radio controls (regression control)

Unchanged in all six runs, in every state (base / hovered / checked), with
byte-identical clipped screenshots:

| Control | Wrapper | Class list when checked | base / hover / checked |
| --- | --- | --- | --- |
| checkbox | `opf-swatch-wrapper wapf-checkboxes` | `opf-swatch opf-swatch--text wapf-checkbox opf-checked` | `0px none rgb(17,17,17)` / transparent / `rgb(17,17,17)` / radius `0px` in every state |
| radio | `opf-swatch-wrapper wapf-radios` | `opf-swatch opf-swatch--text wapf-radio opf-single-select opf-checked` | same |

### Harness results

| Run (artifact under `docs/compatibility/textswatchchip-proof-20261005/`) | Result |
| --- | --- |
| `before-two-regimes-regime-a-missing-option` | 74 checks, **2 failures** (the design block is absent at `HEAD`) |
| `before-two-regimes-regime-b-empty-state` | 74 checks, **10 failures** (the default-chip fallbacks leak into the themed regime) |
| `two-regimes-regime-a-missing-option` | 74 checks, 0 failures |
| `two-regimes-regime-a-saved-empty-option` | 74 checks, 0 failures |
| `two-regimes-regime-b-empty-state` | 74 checks, 0 failures |
| `two-regimes-regime-b-configured` | 74 checks, 0 failures |
| `two-regimes-radius-regime-a` | 74 checks, 0 failures |
| `two-regimes-radius-regime-b-configured` | 74 checks, 0 failures |

Total for the after-runs: 444 checks, 0 failures.

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
$W eval-file bin/e2e-text-swatch-chip-fixture.php prepare     # TEXT_SWATCH_CHIP_URL=http://opf.test/product/opf-text-swatch-chip/
$W eval-file bin/e2e-text-swatch-chip-fixture.php verify

# regime A: missing option, then the SAVED-but-empty option
$W eval-file bin/e2e-text-swatch-chip-fixture.php radius unset
$W eval-file bin/e2e-text-swatch-chip-fixture.php design unset
OPF_PLAYWRIGHT_PACKAGE=$P OPF_TSCHIP_LABEL=two-regimes-regime-a-missing-option \
  OPF_TSCHIP_EXPECT='{"border":"1px solid rgb(204, 204, 204)","background":"rgba(0, 0, 0, 0)","color":"inherit","radius":"4px","hover":{"borderColor":"rgb(53, 60, 78)","background":"rgba(0, 0, 0, 0)","color":"inherit"},"selected":{"borderColor":"rgb(53, 60, 78)","background":"rgb(53, 60, 78)","color":"rgb(255, 255, 255)"}}' \
  OPF_TSCHIP_OUT=$D/two-regimes-regime-a-missing-option.json \
  node bin/e2e-text-swatch-chip-browser.mjs
$W eval-file bin/e2e-text-swatch-chip-fixture.php design empty   # get_option() returns [], empty([]) is true
#   … label=two-regimes-regime-a-saved-empty-option, same expectations

# regime B with the state keys deliberately empty (themed fallbacks)
$W eval-file bin/e2e-text-swatch-chip-fixture.php design \
  '{"apf-radius":"6px","apf-ts-color-hov":"","apf-ts-bg-hov":"","apf-ts-border-color-hov":"","apf-ts-color-sel":"","apf-ts-bg-sel":"","apf-ts-border-color-sel":""}'
#   … label=two-regimes-regime-b-empty-state,
#      border "0px none rgb(17, 17, 17)", background transparent, color "inherit", radius "4px",
#      hover/selected borderColor "rgba(0, 0, 0, 0)", background transparent, color "inherit"

# regime B configured (values win), and both radius-override cases
$W eval-file bin/e2e-text-swatch-chip-fixture.php design \
  '{"apf-ts-border-width":"2px","apf-ts-border-color":"#cccccc","apf-ts-bg":"#121212","apf-ts-color":"#ffffff","apf-ts-radius":"22px","apf-ts-bg-hov":"#00ff00","apf-ts-color-hov":"#0000ff","apf-ts-border-color-hov":"#a4a4a4","apf-ts-bg-sel":"#353c4e","apf-ts-color-sel":"#ffff00","apf-ts-border-color-sel":"#ff00ff"}'
#   … label=two-regimes-regime-b-configured
$W eval-file bin/e2e-text-swatch-chip-fixture.php radius 18
$W eval-file bin/e2e-text-swatch-chip-fixture.php design unset
#   … label=two-regimes-radius-regime-a, radius "18px", otherwise the default chip
$W eval-file bin/e2e-text-swatch-chip-fixture.php design '<configured payload>'
#   … label=two-regimes-radius-regime-b-configured, radius "18px", otherwise configured

# before-runs: the HEAD blobs, hash-verified inside the clone
git show HEAD:assets/css/opf-frontend.css > /tmp/opf-two-regimes-before/opf-frontend.css   # sha256 3b37849d…
git show HEAD:includes/Engine/WapfDesign.php   > …/WapfDesign.php                          # sha256 c7cf89ae…
git show HEAD:includes/Service/Renderer.php    > …/Renderer.php                            # sha256 b181e53a…
cp …/opf-frontend.css $CLONE/…/assets/css/ ; cp …/WapfDesign.php $CLONE/…/includes/Engine/ ; cp …/Renderer.php $CLONE/…/includes/Service/
#   … label=before-two-regimes-regime-a-missing-option   → 2 failures (no design block)
#   … label=before-two-regimes-regime-b-empty-state      → 10 failures (default-chip fallbacks)
rsync -a --delete --exclude='.git' --exclude='node_modules' --exclude='vendor' ./ "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/"

# cleanup
$W eval-file bin/e2e-text-swatch-chip-fixture.php cleanup
kill "$(ss -ltnp | grep ':8090' | grep -o 'pid=[0-9]*' | cut -d= -f2)"
rm -rf /tmp/opf-two-regimes-before /tmp/opf-wpserver-8090.log /tmp/opf-text-swatch-chip-evidence /tmp/opf-regime-b-try.json
```

## Cleanup performed

- `cleanup` deleted the fixture product and field group, flushed the field-group
  cache, restored `wapf_design_settings` and `opf_text_swatch_radius` to the
  pre-run state and removed the fixture state file. Both options were **absent
  before and after** (`wp option get` reports "Could not get ... Does it exist?"
  for both); the fixture product is gone from `wp post list --post_type=product`.
- The clone's plugin directory was re-rsynced from the working tree after the
  before-runs, so it no longer holds the `HEAD` blobs, and all five moved files
  hash-match the working tree.
- The `wp server` on `127.0.0.1:8090` was killed by PID taken from `ss -ltnp`;
  `ss -ltn` shows the port closed. Two unrelated dev servers (`:8080`, `:8058`)
  were left alone.
- `/tmp/opf-two-regimes-before`, `/tmp/opf-wpserver-8090.log`,
  `/tmp/opf-text-swatch-chip-evidence` and the throwaway
  `/tmp/opf-regime-b-try.json` were deleted. No screenshots were written to the
  repository — pixel comparisons live inside the harness and only JSON/TXT
  artifacts ship under `docs/compatibility/textswatchchip-proof-20261005/`.
  Production was never touched: the only writes were the repo working tree
  (`assets/css/`, `includes/`, `tests/Unit/`, `bin/`, `docs/compatibility/`) and
  the disposable clone.

## Tests

```text
vendor/bin/phpunit
Tests: 1054, Assertions: 4474, PHPUnit Deprecations: 2.    (0 failures; baseline before this lane: 1050/4449)

vendor/bin/phpunit --filter 'TextSwatch|WapfDesign'
Tests: 23, Assertions: 123, PHPUnit Deprecations: 2.       (0 failures)

node --test tests/js/*.test.cjs
tests 129, pass 129, fail 0

php -l includes/Engine/WapfDesign.php            → No syntax errors detected
php -l includes/Service/Renderer.php             → No syntax errors detected
php -l tests/Unit/TextSwatchChipCssTest.php      → No syntax errors detected
php -l tests/Unit/WapfDesignTest.php             → No syntax errors detected
php -l tests/Unit/WapfHooksBridgeTest.php        → No syntax errors detected
php -l bin/e2e-text-swatch-chip-fixture.php      → No syntax errors detected
node --check bin/e2e-text-swatch-chip-browser.mjs → OK
```

`assets/css/opf-frontend.css` is plain CSS registered with `wp_register_style`
(`includes/Service/Assets.php:46`) and the plugin has no Tailwind build, so the
`@apply` rule for new CSS does not apply here.

## What is still open

- **Regime B with a state key present but empty in the option, versus missing.**
  WAPF cannot distinguish the two: `sanitize_css_setting()`
  (`class-design-helper.php:1676-1678`) stores `start_with` (or `null`) for a
  blank value, and `design_settings_to_variables_css()` (`:1527`) skips only on
  `empty()`, so a blank and a missing key emit nothing in both engines. OPF's
  `safe_value()` skips `''`, `null` and non-strings; one divergence remains for a
  literal `'0'` (WAPF's `empty('0')` skips it, OPF's `safe_value()` would emit
  `--…:0`). No WAPF design key can hold `'0'` from its own admin UI (numeric
  fields are `css_unit`/`border-unit` and sanitize to `false`), so this is
  unreachable in practice and was left untouched.
- **A non-array `wapf_design_settings`.** WAPF would take the themed branch and
  then read string offsets on a string; `WapfDesign::settings()` normalizes a
  non-array option to `[]`, i.e. OPF takes the default-chip regime instead of
  fatalling. Deliberate hardening, not a parity gap for any option shape WAPF
  writes (`class-admin-controller.php:647` always stores the sanitized array).
- **`--apf-ts-*` in regime A is now emitted on every OPF page with fields** (one
  once-per-revision inline block). The names are WAPF's own, and while WAPF
  serves its default stylesheet they are inert for WAPF markup, so no
  cross-plugin effect is expected; only a storefront that hard-codes
  `--apf-ts-*` for WAPF markup without saving any design setting would see them.
- The OPF base chip still declares `background: transparent` where WAPF's default
  stylesheet declares no background at all; a theme that sets a chip background
  would therefore be overridden in regime A. That is pre-existing (the rule is
  unchanged by this lane) and applying the base declarations was already
  owner-decided on 2026-10-06.
- Per-field authoring of the chip and the cart/order lifecycle of text swatches
  are unchanged and not re-proved by this run.
