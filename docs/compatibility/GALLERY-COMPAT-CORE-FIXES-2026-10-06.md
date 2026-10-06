# Gallery + choice-hint core fixes — the five defects the four live compatibility runs found (2026-10-06)

## Scope

The four OPF-vs-WAPF-Extended compatibility lanes (Flatsome/Woodmart, Astra/Astra
Pro, Barn2 Quick View Pro) each shipped an evidence doc with a defect list. This
lane fixes **only the five shared-core defects** those docs root-caused to
`assets/js/opf-frontend.js` and `includes/Service/Renderer.php`:

| # | Defect | Source | Evidence doc |
| --- | --- | --- | --- |
| 1 | Gallery-slide navigation assumes FlexSlider; a matched rule (and a selected variation) is silently dropped on Flickity/Swiper galleries | `assets/js/opf-frontend.js` `updateProductImage()` | `WAPF-COMPAT-FLATSOME-WOODMART-EVIDENCE-2026-10-06.md` Gap 1 |
| 2 | `.flex-active-slide` is read unscoped, so Astra Pro's thumbnail strip is mistaken for the main viewport slide and the original image is never restored | `assets/js/opf-frontend.js` `updateProductImage()` | `WAPF-COMPAT-ASTRA-EVIDENCE-2026-10-06.md` ASTRA-2 |
| 3 | `restoreImages()` rewrites gallery image attributes on every pass, even when nothing changed — Astra Pro's `src` MutationObserver then clicks a thumbnail and WooCommerce's FlexSlider throws `TypeError … 'animating'` | `assets/js/opf-frontend.js` `restoreImages()` | `WAPF-COMPAT-ASTRA-EVIDENCE-2026-10-06.md` ASTRA-3 |
| 4 | Choice price hints print the HTML-escaped symbol (`Gold (+ &#36;10)`) because the entity goes into `textContent` | `assets/js/opf-frontend.js` `formatPriceHint()` | `WAPF-COMPAT-FLATSOME-WOODMART-…` minor finding, `WAPF-COMPAT-ASTRA-…` minor finding |
| 5 | The multi-value "nothing selected" companion posts an unbracketed name beside its `[]` siblings, so Barn2 Quick View Pro's `serializeArray()` reducer calls `.push()` on a string (`TypeError: t[o].push is not a function`) | `includes/Service/Renderer.php` | `WAPF-COMPAT-QUICK-VIEW-PRO-EVIDENCE-2026-10-06.md` §4.2 / §5.1 A |

**Out of scope (deliberately untouched):** the quick-view adapter/enqueue lane —
loading the frontend bundle on pages that render no fields and giving the module
a re-init entry point (`mfpOpen`, `woodmart-quick-view-displayed`,
`ast_quick_view_loader_stop`, `quick_view_pro:load`). That is the follow-up lane
named in the task; every remaining quick-view check failure below is that gap,
not a regression.

## Files changed

| File | Change |
| --- | --- |
| `assets/js/opf-frontend.js` | defects 1-4 (see hunks below) |
| `includes/Service/Renderer.php` | defect 5: the companion name mirrors its siblings (`[]` for multi-value fields) at the checkbox path and all three linked-products paths |
| `tests/js/opf-product-image.test.cjs` | +5 tests (gallery fallback, variation fallback, FlexSlider regression guard, Astra restore, no-op rewrite); injects `URL` into the vm sandbox because `imageUrlMatches()` uses `new URL()` |
| `tests/js/opf-pricing.test.cjs` | +1 test (hint symbol decoding); exports `formatPriceHint` to the sandbox |
| `tests/Unit/RendererMultiValueCompanionTest.php` | new: 7 tests (bracketed companion markup for checkbox + products checkbox/image/card, unbracketed single-value controls, and the parsed-payload/sanitizer round trip) |

### The hunks

1. **Gallery fallback** — `navigate()` returning `false` no longer ends the pass;
   the image is painted directly (the branch WAPF itself uses):

```js
// variation branch
if ( variationIndex >= 0 && navigate( variationIndex ) ) return;
applyMainImage( activeImageState(), variation.url, variation );
// rule branch
if ( targetIndex >= 0 ) {
    restoreImages();
    if ( navigate( targetIndex ) ) return;
}
restoreImages();
applyMainImage( activeImageState(), target.url, { alt: target.alt } );
```

2. **Active-slide scope** — WooCommerce's slider viewport wins over the theme's
   thumbnail strip, and a non-slide match falls back to slide 0:

```js
const activeSlide = gallery && typeof gallery.querySelector === 'function'
    ? ( gallery.querySelector( '.woocommerce-product-gallery__wrapper .flex-active-slide' ) || gallery.querySelector( '.flex-active-slide' ) )
    : null;
const activeSlideIndex = activeSlide ? slides.indexOf( activeSlide ) : -1;
const activeIndex = activeSlideIndex >= 0 ? activeSlideIndex : 0;
```

3. **No-op rewrite** — `restoreImages()` only writes an attribute whose value
   actually differs (image attributes and the wrapping link `href`), so a pass
   that changes nothing produces no DOM mutation:

```js
if ( saved.attrs[ name ] === saved.image.getAttribute( name ) ) return;
```

4. **Hint symbol** — `decodePriceSymbol()` decodes the configured symbol before
   it reaches `textContent` (numeric entities plus the named entities
   `get_woocommerce_currency_symbol()` ships: `&euro; &pound; &yen; &fnof;`).

5. **Companion name** — `Renderer.php` now spells the sentinel companion exactly
   like the sibling control: `$multi ? $name . '[]' : $name` (checkbox choices),
   `$multi ? $name . '[]' : $name` (products image/card/vcard) and
   `'checkbox' === $subtype ? $name . '[]' : $name` (products checkbox). Single
   value controls (radio, single swatch, products radio) keep the unbracketed
   name.

One mechanical, behaviour-neutral cleanup rode along in the same file: six
`catch ( _error )` / `catch ( _e )` bindings whose parameter was unused were
converted to optional catch bindings (`catch {`). `node --check` and the full JS
suite confirm no change in behaviour; the repository's own JS target is already
ES2020 (`??`, optional chaining, `String.fromCodePoint`).

## Test counts (real runs)

| Suite | Before | After |
| --- | --- | --- |
| `vendor/bin/phpunit --no-coverage` | 1105 tests / 4716 assertions / 0 failures | **1112 tests / 4739 assertions / 0 failures** |
| `node --test tests/js/*.cjs` | 132 pass / 0 fail | **138 pass / 0 fail** |
| `bin/e2e-image-change-last-browser-test.mjs` | (n/a) | all checks pass, 0 page errors |

### New tests fail before the fix

Verified by pointing the two extended JS test files and a copy of the plugin at
the pre-fix source (`git show HEAD:assets/js/opf-frontend.js` →
`/tmp/opf-head-frontend.js`, and a `/tmp` copy of the plugin with
`git show HEAD:includes/Service/Renderer.php` restored):

| Test | Pre-fix | Post-fix |
| --- | --- | --- |
| `a rule targeting a gallery slide paints it when the gallery has no FlexSlider navigation` | ✖ | ✔ |
| `a selected variation image that is a gallery slide is painted when navigation fails` | ✖ | ✔ |
| `clearing a rule restores the original slide when the theme thumbnail also carries flex-active-slide` | ✖ | ✔ |
| `a pass that changes nothing leaves the gallery image attributes untouched` | ✖ | ✔ |
| `a FlexSlider gallery still navigates to the matching slide instead of painting it` | ✔ (guard) | ✔ |
| `choice hints decode the WooCommerce currency symbol entity published by the server` | ✖ `actual: '(+ &#36;10)'` | ✔ `'(+ $10)'` |
| 4 of 7 `RendererMultiValueCompanionTest` cases | ✖ (`name="opf[18][extras]"` beside `name="opf[18][extras][]"`) | ✔ |

## Live before/after (real clones, real browsers)

All runs use the disposable clones the original lanes built
(`/tmp/opf-astra-wp`, `/tmp/opf-theme-flatsome-wp`, `/tmp/opf-theme-woodmart-wp`,
`/tmp/opf-qv-barn2-wp`), each `rsync`ed with this checkout's fixed plugin.
Production (`$HOME/followersya.com`) was not touched. New artifacts:
`docs/compatibility/gallery-compat-core-20261006/`.

### Defect 1 — Flatsome (Flickity) and Woodmart (Swiper) product pages

`bin/e2e-theme-fw-browser.mjs` (engine `opf`), product-phase checks:

| Check | Flatsome before | Flatsome after | Woodmart before | Woodmart after |
| --- | --- | --- | --- | --- |
| `finish=gold swaps the visible gallery image to rule-a` | ❌ `opf-tfw-main.png` | ✅ `opf-tfw-rule-a.png` | ❌ `opf-tfw-main.png` | ✅ `opf-tfw-rule-a.png` |
| product-phase total | 6/7 | **7/7** | 6/7 | **7/7** |
| harness total | 10/14 | **11/14** | 10/14 | **11/14** |

`bin/e2e-theme-fw-opf-image-drop.mjs` isolates both dropped branches:

| Step | Flatsome before | Flatsome after | Woodmart before | Woodmart after |
| --- | --- | --- | --- | --- |
| variation selected | `opf-tfw-main.png` ❌ | `opf-tfw-v-small.png` ✅ | `opf-tfw-main.png` ❌ | `opf-tfw-v-small.png` ✅ |
| `finish=gold`, rule target still a gallery slide | `opf-tfw-main.png` ❌ | `opf-tfw-rule-a.png` ✅ | `opf-tfw-main.png` ❌ | `opf-tfw-rule-a.png` ✅ |

(The third probe step of that script — "remove every slide whose image is the
rule target" — is the *falsification* step for the pre-fix bug and is no longer
meaningful: the paint fallback stamps `rule-a` onto the active slide as well, so
the probe now removes 2 slides, and Flatsome's re-layout leaves the probe with
`null`. The decisive line is step 2.)

### Defect 2 — Astra product page restore

`bin/e2e-astra-compat-browser.mjs` (engine `opf`), single-product checks:

| Check | Before | After |
| --- | --- | --- |
| `image rule finish=gold swaps the main image to rule-a` | ✅ `opf-astra-rule-a.png` | ✅ `opf-astra-rule-a.png` |
| `image rule edge=xl swaps the main image to rule-b` | ✅ `opf-astra-rule-b.png` | ✅ `opf-astra-rule-b.png` |
| `clearing the rules restores the original image` | ❌ `opf-astra-rule-b.png` | ✅ `opf-astra-main.png` |
| single-product checks | 7/9 | **9/9** |

Exactly one check flipped in the whole 17-check cell (`astra-opf-browser.json`,
before vs after): the restore. The four quick-view failures
(`modal engine global present`, `group initialized`, `modal totals`, `modal
image`) are unchanged and belong to the out-of-scope adapter/enqueue lane.

### Defect 3 — Astra Pro observer / FlexSlider `TypeError`

`bin/e2e-astra-compat-clicktrace.mjs` (instrumented `MutationObserver` +
thumbnail-click recorder), label `opf-fixed`:

| Probe | Before (`clicktrace-astra-opf.json`) | After |
| --- | --- | --- |
| `page_errors` | `["Cannot read properties of undefined (reading 'animating')"]` | `[]` |
| thumbnail clicks on `.ast-woocommerce-product-gallery__image` | 2 (both from the Astra Pro observer callback) | **0** |
| observed attribute mutations | `src, alt, data-large_image, data-large_image_width, data-large_image_height` (+ `childList, class, draggable, style`) | `childList, class, draggable, style` only — identical to the no-engine and WAPF baselines |
| console rows (product page) | 5 (3 × `pageerror` + 2 × `media` abort) | 3 (`media` aborts only) |

Astra Pro's actual chain (verified in the served minified bundle,
`astra-addon-<hash>.js`): its gallery observer fires on **`src`** mutations,
disconnects, restores the image, and then `t?.click()`s the matching
`.ast-woocommerce-product-gallery__image` thumbnail — which reaches
WooCommerce's `asNavFor` click handler and dereferences
`$(asNavFor).data('flexslider').animating`. With the no-op guard the `src`
mutation never happens, so neither the click nor the error does.

The three remaining `net::ERR_ABORTED …/opf-astra-main.png` rows are **not**
OPF-specific: the archived WAPF lane records exactly the same three
(`astra-wapf-browser.json`, 2 single + 1 modal), and the fixed OPF cell now has
the identical console profile.

### Defect 4 — choice price hint

Live, same runs as above:

| Surface | Before | After |
| --- | --- | --- |
| Astra product page (`astra-opf-browser.json` → `single.initial.choiceTexts`) | `["Choose an option","None","Gold (+ &#36;10)","Silver (+ &#36;5)"]` | `["Choose an option","None","Gold (+ $10)","Silver (+ $5)"]` |
| Flatsome product page | `Gold (+ &#36;10) Silver (+ &#36;5)` | `Gold (+ $10) Silver (+ $5)` |
| Woodmart product page | `Gold (+ &#36;10) Silver (+ &#36;5)` | `Gold (+ $10) Silver (+ $5)` |

The totals block was already correct (`innerHTML`) and is unchanged
(`$100.00 / $10.00 / $110.00`). The trailing-zero trim (`+ $10` vs WAPF's
`+$10.00`) is the recorded cosmetic difference, not a defect.

### Defect 5 — Barn2 Quick View Pro modal add-to-cart

`bin/e2e-quick-view-pro-browser.mjs` (engine `opf`, scenario `a`):

| Probe | Before | After |
| --- | --- | --- |
| `page_errors` | `TypeError: t[o].push is not a function` (`wc-quick-view-pro.js`) | `[]` |
| submitted names | `opf[18][extras]=0` beside `opf[18][extras][]=gift` | `opf[18][extras][]=0` beside `opf[18][extras][]=gift` |
| `add_to_cart_request` | `null` — the reducer threw, the form submitted natively | `POST /wp-json/wc-quick-view-pro/v1/cart` with `…opf[18][extras][]=0&opf[18][extras][]=gift…` |
| `url_after_add` | `/product/qv-opf-product/` (modal destroyed) | `/qv-shop/` (stays on the archive) |
| cart line | `Finish: Gold (+$15.00) / Edge: None / Extras: Gift (+$2.00)` — `$117.00` | identical, `$117.00` |
| checks | 7/15 | **8/15** |

Server side, the bracketed sentinel is tolerated exactly as the evidence doc
predicted, and the unit test pins it: `parse_str('opf[18][extras][]=0&opf[18][extras][]=gift')`
→ `['0','gift']` → `CartIntegration::sanitize_value()` → `['gift']` (the `0` is
not a valid choice slug, `array_unique()` collapses repeats), an
all-sentinel payload still sanitizes to `null` (so required-choice validation is
unchanged), and `LinkedProducts::sanitize_value()` drops `'0'` explicitly.

## Commands run

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce

php -l includes/Service/Renderer.php
php -l tests/Unit/RendererMultiValueCompanionTest.php
node --check assets/js/opf-frontend.js
node --check tests/js/opf-product-image.test.cjs && node --check tests/js/opf-pricing.test.cjs
vendor/bin/phpunit --no-coverage
node --test tests/js/*.cjs
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules node bin/e2e-image-change-last-browser-test.mjs

# live lanes (disposable clones; fixed plugin rsynced in first)
OPF_ASTRA_ARTIFACTS=$PWD/docs/compatibility/gallery-compat-core-20261006 \
  wp --path=/tmp/opf-astra-wp eval-file bin/e2e-astra-compat-fixture.php prepare
OPF_ASTRA_STATE=docs/compatibility/gallery-compat-core-20261006/fixture-state.json \
OPF_ASTRA_ARTIFACTS=docs/compatibility/gallery-compat-core-20261006 \
OPF_ASTRA_TRACE_LABEL=opf-fixed NODE_PATH=… node bin/e2e-astra-compat-clicktrace.mjs
OPF_ASTRA_ENGINE=opf OPF_ASTRA_STATE=… OPF_ASTRA_ARTIFACTS=… NODE_PATH=… node bin/e2e-astra-compat-browser.mjs
OPF_ASTRA_ARTIFACTS=$PWD/docs/compatibility/gallery-compat-core-20261006 \
  wp --path=/tmp/opf-astra-wp eval-file bin/e2e-astra-compat-fixture.php cleanup

for t in flatsome woodmart; do
  wp --path=/tmp/opf-theme-$t-wp plugin deactivate advanced-product-fields-for-woocommerce-extended
  OPF_TFW_STATE=docs/compatibility/themes-fw-20261006/fixture-state-$t.json \
  OPF_TFW_ARTIFACTS=docs/compatibility/gallery-compat-core-20261006 NODE_PATH=… \
    node bin/e2e-theme-fw-opf-image-drop.mjs
  OPF_TFW_ENGINE=opf OPF_TFW_STATE=… OPF_TFW_ARTIFACTS=… NODE_PATH=… node bin/e2e-theme-fw-browser.mjs
  wp --path=/tmp/opf-theme-$t-wp plugin activate advanced-product-fields-for-woocommerce-extended
done

OPF_QV_ENGINE=opf OPF_QV_SCENARIO=a OPF_QV_STATE=docs/compatibility/quick-view-pro-20261006/fixture-state.json \
OPF_QV_ARTIFACTS=docs/compatibility/gallery-compat-core-20261006 NODE_PATH=… \
  node bin/e2e-quick-view-pro-browser.mjs
```

## Residuals

- **Quick-view adapter/enqueue lane** (out of scope, unchanged): Astra modal
  4/9, Flatsome quick view 4/7, Woodmart quick view 4/7, Barn2 scenario A 8/15.
  Every failure is the missing runtime/re-init hook, never a rule or totals
  regression.
- The Astra Pro `net::ERR_ABORTED` media rows (2 single + 1 modal) survive and
  match the WAPF baseline exactly; the OPF-caused `pageerror` rows are gone.
- Not re-run here: WAPF lanes (unchanged source), the Astra `wapf`/`control`
  cells, scenario B of the Barn2 harness, and the theme lanes' WAPF cells.
- The theme clones were returned to their prior state (both engines active); the
  Astra clone had its fixture cleaned up again and its flipped options restored
  by the harness; the Barn2 clone was left as found.
- Lint: the plugin's `assets/js/opf-frontend.js` carries six pre-existing
  unused-catch-parameter patterns; they were normalised to optional catch
  bindings in this change (mechanical, no behaviour change).
