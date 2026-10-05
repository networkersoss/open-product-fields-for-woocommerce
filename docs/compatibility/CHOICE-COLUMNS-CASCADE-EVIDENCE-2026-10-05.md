# Checkbox-columns responsive cascade — fix + real-Chromium proof (2026-10-05)

## Scope

`assets/css/opf-frontend.css` declared the choice-grid wrapper **twice**:

- the canonical block with the responsive overrides —
  `.opf-swatch-wrapper.opf-checkboxes--columns` plus
  `@media (max-width: 720px)` → `--opf-checkbox-columns-tablet` and
  `@media (max-width: 420px)` → `--opf-checkbox-columns-mobile`;
- a legacy duplicate base rule further down the file (~line 990) that carried
  only `display` / `grid-template-columns` / `gap`.

Both selectors have the same specificity (0,2,0), so the later duplicate won and
the two media overrides never applied: a `columns:4` field — which the renderer
serialises as **4 / 2 / 1** (`includes/Service/Renderer.php:1148`) — rendered
4 columns at every viewport, with WAPF active *and* with WAPF inactive. The
defect predates the coexistence fix and commit `32a549e` deliberately preserved
it ("the cascade is queued separately"). This run fixes it and proves the
cascade in a real browser in both plugin states.

## The 3.1.5 contract behind the wrapper

| Contract | Installed Extended 3.1.5 source |
| --- | --- |
| WAPF checkbox wrapper | `views/frontend/fields/checkboxes.php:9` — `<div class="wapf-checkboxes">` |
| WAPF radio wrapper | `views/frontend/fields/radio.php:9` — `<div class="wapf-radios">` |
| WAPF wrapper layout | `assets/css/frontend-default.min.css` (char 5227) and `assets/css/frontend-themed.min.css` (char 7061) — `.wapf-checkboxes,.wapf-radios{display:inline-grid;grid-template-columns:auto;gap:5px 1rem}`. Both files are minified to a single line, so the anchor is the character offset of the rule. |
| Which one WAPF enqueues | `includes/controllers/class-public-controller.php:182-186` — enqueues `assets/css/frontend.min.css`, which is either the copy of `frontend-default.min.css` (`class-design-helper.php:1309-1312`) or the generated themed stylesheet (`class-design-helper.php:1448-1449`). |
| OPF wrapper classes and variables | `includes/Service/Renderer.php:1126` (`opf-text-swatch-wrapper`), `:1141-1152` (wrapper classes + `--opf-checkbox-columns`, `--opf-checkbox-columns-tablet`, `--opf-checkbox-columns-mobile`). |

3.1.5 itself has **no** responsive column variables — the tablet/mobile
variables and the 4/2/1 rule are OPF's own contract, emitted by OPF's renderer.
This fix is therefore purely OPF's cascade; WAPF's files are untouched.

## Decision: the legacy duplicate is redundant — deleted, with its gap merged

The two base rules were identical except for the gap:

| Rule | `display` | `grid-template-columns` | `gap` |
| --- | --- | --- | --- |
| canonical (earlier, owns the media queries) | `grid` | `repeat(var(--opf-checkbox-columns, 1), minmax(0, 1fr))` | `.5em 1em` |
| legacy duplicate (later) | `grid` | same | `0.25rem 0.75rem` |

The duplicate's **only** unique contribution is the tighter gap — and it is the
value that actually rendered (it won the cascade), measured at `row-gap: 4px` /
`column-gap: 12px` before the fix. So the duplicate was deleted and its gap
merged into the canonical block: the fix changes the responsive cascade and
nothing else. Keeping the canonical `.5em 1em` instead would have changed the
desktop gap of every existing multi-column group, which was not requested and is
not what any WAPF contract asks for (WAPF's own wrapper gap is `5px 1rem`).

## What changed

| File | Change |
| --- | --- |
| `assets/css/opf-frontend.css` | Deleted the legacy duplicate base rule (~line 990) and merged its effective gap into the canonical `.opf-swatch-wrapper.opf-checkboxes--columns` block (now `gap: 0.25rem 0.75rem`), with a comment stating that no base rule may follow the media queries. |
| `tests/Unit/FrontendChoiceGridCssTest.php` | New `test_each_viewport_resolves_to_its_own_column_variable`: extracts every choice-grid rule with its enclosing media query, simulates the cascade per viewport (equal specificity → last applicable rule wins) and asserts 1280→base, 720/600→tablet, 420/400→mobile, and that there are exactly three rules. |

The coexistence contract is unchanged and still enforced by the existing tests:
the selector keeps the `.opf-swatch-wrapper` scope (0,2,0) that out-specifies
WAPF's `.wapf-checkboxes,.wapf-radios` (0,1,0) regardless of stylesheet order.

## Before / after — real Chromium, computed values

Fixture: `bin/e2e-coexistcss-checkbox-columns.php` (existing harness) creates an
OPF checkbox group with `columns:4` (wrapper inline style
`--opf-checkbox-columns:4;--opf-checkbox-columns-tablet:2;--opf-checkbox-columns-mobile:1;`)
plus WAPF-native checkbox/radio fields on the same product page. Browser harness:
new `bin/e2e-choice-columns-cascade-browser.mjs` (records `display`,
`grid-template-columns`, gaps, the matching rules and the resolved winner).

`before` = `32a549e` CSS, sha256 `8c1b88ba7e6743c27693ab94573f8af4c9a9866a03397d12f6328b20bd652786`
(identical to the hash the coexistence lane recorded for the committed asset).
`after` = this lane's CSS, sha256 `080b1410f7f3d031d908b6aa7f4f6d41c5e080832ea6141eeec8d675f3d40c61`.
Both hashes were verified against the bytes served by the clone
(`curl …/opf-frontend.css?ver=0.1.0 | sha256sum`) before each run.

| Case | Viewport | before | after |
| --- | --- | --- | --- |
| both plugins active | 1280 | `grid` / 4 columns (`145.5px ×4`) | `grid` / **4** (`145.5px ×4`) |
| both plugins active | 720 | `grid` / **4** (`153px ×4`) | `grid` / **2** (`318px ×2`) |
| both plugins active | 600 | `grid` / **4** (`126px ×4`) | `grid` / **2** (`264px ×2`) |
| both plugins active | 420 | `grid` / **4** (`81px ×4`) | `grid` / **1** (`360px`) |
| both plugins active | 400 | `grid` / **4** (`76px ×4`) | `grid` / **1** (`340px`) |
| OPF only (WAPF inactive) | 1280 | 4 columns | 4 columns |
| OPF only (WAPF inactive) | 720 / 600 | **4** / **4** | **2** / **2** |
| OPF only (WAPF inactive) | 420 / 400 | **4** / **4** | **1** / **1** |
| both active, WAPF-native checkbox | 1280 | `inline-grid` / 1 column / winner `.wapf-checkboxes, .wapf-radios` | identical |
| both active, desktop gap | 1280 | `row-gap 4px`, `column-gap 12px` | identical |

The winning-rule dump shows the cause and the fix directly:

```text
before  .opf-swatch-wrapper.opf-checkboxes--columns (base)   → repeat(var(--opf-checkbox-columns, 1), minmax(0, 1fr))   ← wins at 720/600/420/400
        .opf-swatch-wrapper.opf-checkboxes--columns (max-width: 720px)   (never wins)
        .opf-swatch-wrapper.opf-checkboxes--columns (max-width: 420px)   (never wins)
        .opf-swatch-wrapper.opf-checkboxes--columns (base, legacy duplicate)
after   .opf-swatch-wrapper.opf-checkboxes--columns (base)
        .opf-swatch-wrapper.opf-checkboxes--columns (max-width: 720px)  ← wins at ≤720px
        .opf-swatch-wrapper.opf-checkboxes--columns (max-width: 420px)  ← wins at ≤420px
```

Harness results: `before-both-active` 18 checks / **8 failures**,
`before-opf-only` 15 checks / **8 failures**, `after-both-active` 18 checks /
**0 failures**, `after-opf-only` 15 checks / **0 failures** (the 8 failures are
the four wrong column counts plus the four wrong winners). Artifacts:
`docs/compatibility/choicecascade-proof-20261005/{before,after}-{both-active,opf-only}.json`.

## Commands

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
CLONE=/home/followersya-5hqi7/opf-test/wordpress
W="wp --path=$CLONE --allow-root"
P=/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json

# sync the lane into the disposable WordPress
rsync -a --delete --exclude='.git' --exclude='node_modules' --exclude='vendor' \
  ./ "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/"
diff -rq --exclude='.git' --exclude='node_modules' --exclude='vendor' \
  ./ "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/"

# both-active case: WAPF Extended 3.1.5 beside OPF
cp -a /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended \
  "$CLONE/wp-content/plugins/"
$W plugin activate advanced-product-fields-for-woocommerce-extended
$W eval-file bin/e2e-coexistcss-checkbox-columns.php prepare

# storefront server (loopback only)
cd $CLONE && setsid wp --path=$CLONE --allow-root server --host=127.0.0.1 --port=8090 &

# BEFORE: the 32a549e asset, verified by hash against the served bytes
git show 32a549e:assets/css/opf-frontend.css > /tmp/opf-frontend.32a549e.css
cp /tmp/opf-frontend.32a549e.css "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/assets/css/opf-frontend.css"
curl -s 'http://127.0.0.1:8090/wp-content/plugins/open-product-fields-for-woocommerce/assets/css/opf-frontend.css?ver=0.1.0' | sha256sum

OPF_PLAYWRIGHT_PACKAGE=$P OPF_CASCADE_URL=http://127.0.0.1:8090/product/opf-wapf-coexistcss-product/ \
  OPF_CASCADE_EXPECT=4,2,1 OPF_CASCADE_LABEL=before-both-active \
  OPF_CASCADE_OUT=docs/compatibility/choicecascade-proof-20261005/before-both-active.json \
  node bin/e2e-choice-columns-cascade-browser.mjs

$W plugin deactivate advanced-product-fields-for-woocommerce-extended
OPF_PLAYWRIGHT_PACKAGE=$P OPF_CASCADE_URL=http://127.0.0.1:8090/product/opf-wapf-coexistcss-product/ \
  OPF_CASCADE_EXPECT=4,2,1 OPF_CASCADE_LABEL=before-opf-only \
  OPF_CASCADE_OUT=docs/compatibility/choicecascade-proof-20261005/before-opf-only.json \
  node bin/e2e-choice-columns-cascade-browser.mjs
$W plugin activate advanced-product-fields-for-woocommerce-extended

# AFTER: this lane's asset (same two runs, labels after-*)
cp assets/css/opf-frontend.css "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/assets/css/opf-frontend.css"
#   … after-both-active, then deactivate WAPF → after-opf-only, then reactivate

# cleanup
$W eval-file bin/e2e-coexistcss-checkbox-columns.php cleanup
$W plugin deactivate advanced-product-fields-for-woocommerce-extended
rm -rf "$CLONE/wp-content/plugins/advanced-product-fields-for-woocommerce-extended"
pkill -f "127.0.0.1:8090"
```

## Cleanup performed

- Fixture removed by the harness (`opf_coexistcss_state` deleted with the OPF
  group, the WAPF group and the fixture product).
- WAPF Extended 3.1.5 deactivated and deleted from the clone; the clone is back
  to OPF + WooCommerce only (`wp plugin list` verified).
- The `wp server` on `127.0.0.1:8090` was killed (`ss` confirms the port is
  closed); only loopback was ever served.
- Production was never touched: WAPF lives in the production plugin directory but
  is inactive and was only ever **read/copied**; OPF's production copy was not
  modified by this run.
- Scratch artifacts (per-state screenshots) were written to
  `$HOME/ops/scratchpad/20261005-choice-grid-cascade/` and deleted when the task
  closed; the JSON artifacts under
  `docs/compatibility/choicecascade-proof-20261005/` are the shipped evidence.

## Tests

```text
vendor/bin/phpunit --filter FrontendChoiceGridCssTest
OK, but there were issues!  Tests: 4, Assertions: 19, PHPUnit Deprecations: 2.   (0 failures)
```

No existing test was weakened or removed; `tests/Unit/RendererCheckboxColumnsTest.php`
and the coexistence tests still pass unchanged.

## Residual risks / notes

- The desktop gap is unchanged (4px / 12px) because the deleted duplicate's gap
  was merged; a reviewer who prefers the canonical `.5em 1em` would change the
  rendered gap of every existing multi-column group — out of this fix's scope.
- `gap` is not part of any WAPF contract (WAPF's wrapper uses `5px 1rem`), so no
  parity claim is made about it.
- The cascade is proven at 1280 / 720 / 600 / 420 / 400 px. The two breakpoints
  are inclusive (`max-width`), which the harness covers with the 720 and 420
  edge runs.
