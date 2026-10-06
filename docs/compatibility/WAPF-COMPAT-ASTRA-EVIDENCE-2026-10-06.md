# Astra + Astra Pro: OPF vs WAPF Extended 3.1.5 — live theme-integration evidence (2026-10-06)

## Scope

Covers the ledger row that names the Astra theme adapter:

- `WAPF-COMPAT-ASTRA` — Astra 4.13.11 + Astra Pro addon 4.13.10
  (`advanced-product-fields-for-woocommerce-extended/includes/classes/integrations/class-astra.php`)

For each engine (Open Product Fields 0.1.1, WAPF Extended 3.1.5), on a disposable
WordPress + WooCommerce clone, live in Chromium:

1. fields render on the Astra product page,
2. pricing/total updates on selection,
3. image-change rules swap the correct image,
4. fields render **and function** inside the Astra Pro quick-view modal
   (`#ast-quick-view-modal`, opened from the shop card trigger) — this is the
   surface `class-astra.php` exists for,
5. add-to-cart carries the field values into the cart line,
6. JS console errors, attributed to Astra, Astra Pro or the engine.

Verdict in one line: **WAPF's Astra adapter does its job — its fields, pricing,
rule image swap and add-to-cart all work inside the Astra quick view, and OPF's
do not, because OPF's frontend module is not even loaded on the page that opens
the modal and OPF has no re-init entry point for it.** On the Astra product page
the two engines trade blows: OPF swaps the rule image (WAPF does not, it needs
WooCommerce's `.flex-control-nav` thumbnails that Astra Pro replaces), but OPF
cannot restore the original image when the rule clears, and OPF is the only
engine that throws a console error on that page.

## Environment

| Component | Version / value |
| --- | --- |
| WordPress | 7.1.3 (SQLite Database Integration 3.0.2 drop-in) |
| WooCommerce | 11.1.0 |
| PHP | 8.5.11 (`php -S 127.0.0.1:8511 -t /tmp/opf-astra-wp /tmp/opf-astra-wp/router.php`) |
| Open Product Fields | 0.1.1 (`rsync`ed from this checkout into the clone) |
| WAPF Extended | 3.1.5 (copied read-only from `bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`) |
| Astra | 4.13.11 (theme dir `astra/`, from `astra_4.13.11.zip`) |
| Astra Pro | 4.13.10 (plugin dir `astra-addon/`, from `astra-addon_4.13.10.zip`) |
| Astra Pro WooCommerce addon | enabled via `_astra_ext_enabled_extensions` = `{"woocommerce":"woocommerce","all":"all"}` (every addon module ships disabled) |
| Astra quick view | `astra-settings.shop-quick-view-enable` = `on-image` (default `disabled`) |
| Astra effective layout defaults | `shop-style` = `shop-page-modern-style`, `single-product-gallery-layout` = `horizontal-slider` |
| Clone | `/tmp/opf-astra-wp` → `http://127.0.0.1:8511` (disposable) |
| Browsers | Chromium 1217 (`playwright` 1.59.1, `NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules`), 1400×1000 |
| Cart page | WooCommerce **Cart block** (`/wc-block-cart-items__row`; line data read back through `/wp-json/wc/store/v1/cart`) |
| Production | untouched: nothing under `$HOME/followersya.com` was activated, deactivated or modified; the WAPF source dir was only copied out of |

### Astra-side traps found while building the clone (both cost real time)

1. **Enabling the Astra Pro addon module is not enough.** With the module on and
   `shop-quick-view-enable=on-image`, the shop page still rendered no trigger and
   no modal. Astra Pro's frontend JS ships in an `Astra_Minify` bundle at
   `wp-content/uploads/astra-addon/astra-addon-<hash>.js`; that cached bundle was
   generated while the addon module was disabled and contains **no**
   `quick-view.js` (`grep -c AstraProQuickView` = 0), so `window.AstraProQuickView`
   stayed `undefined` and no click listener was ever bound. The fixture harness
   now deletes the generated bundle plus the `astra_theme_{js,css}_key-astra-addon*`
   options so the next request regenerates it (`AstraProQuickView` present,
   `typeof` = object).
2. **The modern shop card action is hover-revealed.** Playwright reports the
   `.ast-quick-view-trigger` span as *not visible*; the driver hovers the product
   card first and falls back to an in-page `element.click()` (Astra binds a native
   click listener, so `currentTarget` is still correct).

## Fixture

`bin/e2e-astra-compat-fixture.php` (`prepare` | `cleanup`, guarded to
`/tmp/opf-astra-wp`) builds two variable products, one per engine, identical in
shape:

| slug | engine | group storage |
| --- | --- | --- |
| `opf-astra-compat` | OPF | `OPF\Engine\FieldGroup` → `opf_field_group` post, native `image_rules` |
| `wapf-astra-compat` | WAPF 3.1.5 | `_wapf_fieldgroup` written through WAPF's own `Field_Groups::raw_json_to_field_group()` |

Each product:

- attribute `size` = { `small`, `large` }; each variation carries its own gallery
  image (`small` → `v-small.png`, `large` → `v-large.png`), price 100.00;
- featured image `main.png` (the "original" both engines must fall back to);
- gallery `[ v-small, v-large, rule-a, rule-b ]`, so rule targets and variation
  images are real gallery slides;
- **priced choices**: `finish` select { none(0), gold **+10.00**, silver **+5.00** },
  `edge` select { none(0), xl **+3.00** };
- **image-change rules**: `finish=gold` → `rule-a`, `edge=xl` → `rule-b`,
  swap mode `rules`.

The five images are solid-colour 320×320 PNGs generated with GD (blue main,
green v-small, red v-large, purple rule-a, amber rule-b) so the screenshots show
the swap, not just a URL change. Totals rows are switched on for the run
(`opf_price_summary_mode=three_line`, `wapf_pricing_summary=lines`); Astra's
quick-view + addon settings are flipped and their prior values recorded. State
(versions, Astra options, urls, ids, variation ids) lands in
`docs/compatibility/astra-20261006/fixture-state.json`.

## Run

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
CLONE=/tmp/opf-astra-wp

# terminal (once): CDN-free clone
php -S 127.0.0.1:8511 -t $CLONE $CLONE/router.php

# fixture (needs both engines present at least once; later single-engine
# re-runs carry the other engine's stored row forward)
OPF_ASTRA_ARTIFACTS=$PWD/docs/compatibility/astra-20261006 \
  wp --path=$CLONE eval-file bin/e2e-astra-compat-fixture.php prepare

run() { OPF_ASTRA_ENGINE=$1 OPF_ASTRA_STATE=docs/compatibility/astra-20261006/fixture-state.json \
        OPF_ASTRA_ARTIFACTS=docs/compatibility/astra-20261006 \
        NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
        node bin/e2e-astra-compat-browser.mjs; }

# 1) Astra/Astra Pro baseline: no engine active
wp --path=$CLONE plugin deactivate open-product-fields-for-woocommerce
wp --path=$CLONE plugin deactivate advanced-product-fields-for-woocommerce-extended
run control

# 2) WAPF Extended only
wp --path=$CLONE plugin activate advanced-product-fields-for-woocommerce-extended
run wapf

# 3) OPF only
wp --path=$CLONE plugin deactivate advanced-product-fields-for-woocommerce-extended
wp --path=$CLONE plugin activate open-product-fields-for-woocommerce
run opf

# 4) console-error root cause (per engine state)
OPF_ASTRA_TRACE_LABEL=opf node bin/e2e-astra-compat-clicktrace.mjs

# 5) static asset evidence (/shop/ vs product page)
curl -s http://127.0.0.1:8511/shop/ | grep -o 'opf-frontend' | wc -l      # → 0
curl -s http://127.0.0.1:8511/product/opf-astra-compat/ | grep -o 'OPF_FIELDS' | wc -l  # → 1
```

`cleanup` removes the products, groups, attachments and restores every flipped
option (`OPF_ASTRA_ARTIFACTS=<dir> wp --path=$CLONE eval-file
bin/e2e-astra-compat-fixture.php cleanup`).

## Side-by-side results (real runs, 2026-10-06)

### Astra product page — 9 checks per cell

| # | check | Astra / WAPF 3.1.5 | Astra / OPF 0.1.1 |
| --- | --- | --- | --- |
| 1 | field group renders (2 selects, labels `Finish`/`Edge`) | ✅ | ✅ |
| 2 | priced choices rendered | ✅ `Gold (+$10.00)` | ✅ `Gold (+ &#36;10)` (known cosmetic defect, see below) |
| 3 | `finish=gold` moves options total to `$10.00` | ✅ | ✅ |
| 4 | grand total = variation 100.00 + 10.00 = `$110.00` | ✅ | ✅ |
| 5 | `finish=gold` swaps the visible gallery image to `rule-a` | ❌ `main.png` (WAPF limitation, below) | ✅ `rule-a` |
| 6 | `edge=xl` swaps the visible gallery image to `rule-b` | ❌ `main.png` | ✅ `rule-b` |
| 7 | clearing the rules restores the original image | ✅ `main.png` (never moved) | ❌ stays `rule-b` |
| 8 | add-to-cart carries the field value (`Finish: Gold`, line $110.00) | ✅ | ✅ |
| 9 | no uncaught browser errors | ✅ none | ❌ 3 × `TypeError … 'animating'` (WC flexslider) + 2 aborted image requests |

### Astra Pro quick view modal — 9 checks per cell

| # | check | Astra / WAPF 3.1.5 | Astra / OPF 0.1.1 |
| --- | --- | --- | --- |
| 1 | shop card exposes a quick-view trigger for the fixture product | ✅ | ✅ |
| 2 | modal opens with the product form | ✅ | ✅ |
| 3 | fields render inside the modal | ✅ (2 selects) | ✅ server-rendered markup, 2 selects |
| 4 | engine global present on the shop document | ✅ `wapf_config`, `window.WAPF` | ❌ `window.OPF_FIELDS` undefined |
| 5 | engine marked the injected group as initialized | ✅ (behavioural proof) | ❌ `data-opf-initialized` absent |
| 6 | priced choice updates the modal totals | ✅ `$10.00` / `$110.00` | ❌ empty (`Product total Options total Grand total`) |
| 7 | `finish=gold` swaps the modal image to `rule-a` | ✅ `rule-a` | ❌ `v-small` |
| 8 | add-to-cart from the modal carries the field value | ✅ | ✅ |
| 9 | no uncaught browser errors | ✅ none | ✅ none |

Harness totals: product page **WAPF 7/9, OPF 7/9** (different rows: WAPF loses the
two rule-image swaps, OPF loses the restore and console cleanliness); quick view
**WAPF 9/9, OPF 5/9**. Raw JSON check counts: `astra-wapf-browser.json` 14/16,
`astra-opf-browser.json` 12/17 (the denominators differ because the OPF cell
carries two extra checks — the engine-global and init-flag probes).

Baseline (`astra-control-browser.json`, no engine active): 0 console rows, the
product-page image never moves, and the modal slider advances to `v-small` on its
own 6.0–7.5 s after opening (Astra Pro behaviour, reproduced with no engine — see
"ASTRA-4" below).

## What OPF lacks versus WAPF's Astra adapter

`class-astra.php` (18 lines) prints one listener on `wp_footer`
(`class-astra.php:7` → `add_javascript()`, lines 10-16):

```js
jQuery(document).on('ast_quick_view_loader_stop', function(){ new WAPF.Frontend(jQuery('#ast-quick-view-modal .product')); });
```

It works for exactly three reasons, and OPF has none of them:

| requirement | WAPF 3.1.5 | OPF 0.1.1 |
| --- | --- | --- |
| engine JS is present on the page **that opens** the modal (the archive) | ✅ `wp_enqueue_script('wapf-frontend', …)` runs unconditionally on `wp_enqueue_scripts` (`includes/controllers/class-public-controller.php:187`) — the shop document loads it | ❌ `Assets::register_frontend()` only *registers* on `wp_enqueue_scripts` (`includes/Service/Assets.php:20`); the module is enqueued from `Renderer::render()` (`includes/Service/Renderer.php:279`), which is hooked to `woocommerce_before_add_to_cart_button` — a page that renders no fields loads no module |
| the engine can be re-initialized on an injected root | ✅ `new WAPF.Frontend(jQuery(root))` is a public, root-scoped constructor (its `d` root is closed over by every handler) | ❌ `init(root)` exists and is idempotent (`assets/js/opf-frontend.js:610`, per-group `data-opf-initialized` guard) but is module-private: no `window.*` export, no `opf:reinit` event, and `initTotals()`/`writeTotals()` are bound to `document` |
| per-product field metadata is reachable for the injected root | ✅ the group carries it inline: `data-wapf-gi`, `data-wapf-st`, `data-variables`, and the localized `wapf_config` is page-level only | ❌ the registry is a page-level global `window.OPF_FIELDS[gid]` (`Assets.php:71`) with no DOM fallback; the AJAX fragment *does* carry an inline `<script>window.OPF_FIELDS…</script>` but Astra injects the fragment with `DOMPurify.sanitize()` + `innerHTML`, where it cannot execute |

Measured static proof (`assets-astra.txt`, `curl` + `grep -o` on the served HTML):

| served document | `opf-frontend` refs | `OPF_FIELDS` | `wapf-frontend` refs | `wapf_config` | `ast_quick_view_loader_stop` |
| --- | --- | --- | --- | --- | --- |
| `/shop/` with OPF active | **0** | **0** | 0 | 0 | 0 |
| `/product/opf-astra-compat/` with OPF active | 4 | 1 | 0 | 0 | 0 |
| `/shop/` with WAPF active | 0 | 0 | 4 | 1 | 1 |
| `/product/wapf-astra-compat/` with WAPF active | 0 | 0 | 4 | 1 | 1 |

And measured on the raw modal fragment (`astra-*-browser.json → quick_view_ajax`):

| engine | fragment contains field markup | fragment contains `<script>` | fragment contains the registry script | engine needs it? |
| --- | --- | --- | --- | --- |
| WAPF | ✅ `wapf-field-group` | ❌ none | n/a | no — JS + config are already on the page |
| OPF | ✅ `data-opf-group` | ✅ | ✅ `window.OPF_FIELDS` | yes — and it cannot run |

Consequence in the modal (measured): `data-opf-initialized` is absent, the totals
stay empty, the rule image never swaps, and only the plain form POST still works
(the inputs live inside `form.cart`, so add-to-cart keeps its values — check 8).

## Astra-side findings that are **not** OPF gaps

### ASTRA-1 — WAPF's rule image does not swap on the Astra product page

WAPF's image engine takes the FlexSlider branch whenever it finds an element
tagged `data-wapf-att-id` — WAPF stamps that attribute onto every WooCommerce
gallery slide server-side while the group has gallery-image rules
(`includes/controllers/class-product-controller.php:772`) — and then clicks
WooCommerce's thumbnail nav:
`t = d.find('.flex-control-nav li').eq(…).find('img'); … t.trigger('click')`
(`assets/js/frontend.min.js`, `C()` inside `WAPF.Frontend`). Astra Pro's default
`single-product-gallery-layout=horizontal-slider` replaces that nav with its own
thumb strip, so `.flex-control-nav` matches **0** elements on this page
(measured: `controlNav=0`, `thumbs=5`, `attIds=5`), the click goes to an empty
jQuery set, and the function returns without touching the image. Result:
`finish=gold` and `edge=xl` both leave `main.png` on screen. Inside the modal
WAPF swaps correctly (no `data-wapf-att-id` there → its direct
attribute-swap fallback runs). Parity note: this is a WAPF/theme interaction
defect, not an OPF regression, and it is recorded here because the row under test
is the *theme adapter*.

### ASTRA-2 — OPF cannot restore the original image when the rule clears

`updateProductImage()` reads the current slide with
`gallery.querySelector('.flex-active-slide')` (`opf-frontend.js:2958`) and maps it
with `slides.indexOf(...)` over `.woocommerce-product-gallery__image` slides
(`:2935`). Astra Pro's thumbnail strip sits **inside** `.woocommerce-product-gallery`
and its slides are `div.ast-woocommerce-product-gallery__image`, which also carry
`flex-active-slide` — so `querySelector` returns the *thumbnail*, `indexOf` returns
`-1`, and `snapshot.slideIndex` is captured as `-1`. The clear-the-rule path
(`:3033-3035`) then runs `restoreImages(); navigate( snapshot.slideIndex ); return;`
and `navigate(-1)` bails out immediately at `:2987` (`index < 0`) — the FlexSlider
stays where the last rule put it, i.e. on `rule-b`. Matched rules are unaffected
because they use the rule's own slide index (3 / 4), which is why OPF's swap
checks pass. Astra's own thumb strip does **not** follow programmatic FlexSlider
navigation either (measured: `flex-current-slide=4` while the strip still marks
thumb 0), which is what makes the stale position visible.

Smallest fix (one line, in `updateProductImage()` at `opf-frontend.js:2958`):

```js
// Resolve the current slide from WooCommerce's slider viewport first; Astra's
// thumbnail strip also carries `.flex-active-slide` inside the same gallery.
const activeSlide = gallery && gallery.querySelector( '.woocommerce-product-gallery__wrapper .flex-active-slide' )
    || ( gallery && gallery.querySelector( '.flex-active-slide' ) );
```

With a real index captured (`0`), the existing clear-the-rule path becomes
`restoreImages(); navigate( 0 );` and the FlexSlider returns to the original
slide. A second, gallery-agnostic safety net is the "navigation failed → paint
the captured target" fall-through already proposed for the same helper in
`WAPF-COMPAT-FLATSOME-WOODMART-EVIDENCE-2026-10-06.md` (Gap 1); either patch
alone fixes this finding on Astra.

### ASTRA-3 — OPF's load-time attribute rewrite trips Astra Pro's observer and throws a WooCommerce flexslider TypeError

Measured with `bin/e2e-astra-compat-clicktrace.mjs` (wraps `MutationObserver` and
records clicks on the Astra thumbnail divs with their stack):

| run | page errors | clicks on Astra thumbnail divs | click source | mutated gallery-image attributes |
| --- | --- | --- | --- | --- |
| no engine | none | 0 | — | — |
| WAPF active | none | 0 | — | — |
| OPF active | `TypeError: Cannot read properties of undefined (reading 'animating')` | 2 | `astra-addon-<hash>.js:1:22799` inside a `MutationObserver` callback | `src`, `alt`, `data-large_image`, `data-large_image_width`, `data-large_image_height` |

Chain: OPF's totals pass ends in `updateProductImage()`, whose `restoreImages()`
(`opf-frontend.js:2976`) rewrites the gallery image attributes on every run — even
when nothing is selected — → Astra Pro's
`addons/woocommerce/assets/js/unminified/horizontal-product-gallery-slider.js`
observes that image and calls `element.click()` on the matching thumbnail
`div` → WooCommerce's `jquery.flexslider` asNav click handler dereferences
`$( asNavFor ).data('flexslider').animating` (`jquery.flexslider.js:222`) while the
main gallery instance is not (yet) registered → `TypeError`, twice per page load.
The two aborted `opf-astra-main.png` requests are the same rewrite cancelling the
in-flight image load.

Smallest fix (skip no-op writes; one guard inside `restoreImages()` at `:2976`):

```js
if ( saved.attrs[ name ] !== saved.image.getAttribute( name ) ) {
    if ( saved.attrs[ name ] === null ) saved.image.removeAttribute( name );
    else saved.image.setAttribute( name, saved.attrs[ name ] );
}
```

### ASTRA-4 — Astra Pro's quick-view slider advances on its own

Control run (no engine active): the modal image is `main.png` at 0–6.0 s and
`v-small.png` from 7.5 s on, with no field interaction at all
(`astra-control-browser.json → control.modal_timeline`). The same advance
replaces an engine-swapped modal image after ~6 s (`image_after_idle_6s` in both
engine JSONs). It is why the modal image check polls for 3 s, and it is an Astra
Pro defect, not an engine one.

## Console errors (full list)

| run | rows recorded | attribution |
| --- | --- | --- |
| control (no engine) | 0 | — |
| WAPF — product page | 2 | `media`: `net::ERR_ABORTED …/opf-astra-main.png` (WooCommerce gallery srcset/lazy re-render) |
| WAPF — modal | 1 | `media`: same image abort |
| OPF — product page | 5 | 3 × `pageerror` attributed `woocommerce-flexslider` (`animating`, stack URL `woocommerce/assets/js/flexslider/jquery.flexslider.min.js`) + 2 × `media` abort |
| OPF — modal | 1 | `media`: same image abort |

No `console.error`/`console.warning` from Astra or Astra Pro code in any run; the
only Astra Pro involvement in an error is the observer click in ASTRA-3.

## Matches (no adapter needed)

- **Field markup, priced choices and priced totals**: parity on the product page
  and (render only) in the modal; the modal totals are the only place OPF's gap
  shows as empty numbers.
- **Cart line**: identical on both surfaces — `Finish: Gold` with the
  `(+$10.00)` hint and a $110.00 line, read back through the Store API
  (`item_data`), for both engines, from the product page *and* from the quick
  view. OPF's server-side cart integration needs no theme awareness.
- **Astra-specific hooks OPF already has** (but which cannot help here):
  `opf:variation-changed`, `opf:pricing`, the
  `woocommerce_gallery_init_zoom` re-trigger on image swap
  (`opf-frontend.js:1565`) and the `wapf/*` alias bridge
  (`includes/Compat/WapfHooks.php`).

## Minor finding — priced-choice hint prints the raw currency entity

Reproduced on Astra, OPF only: the choice hint renders `Gold (+ &#36;10)` instead
of `Gold (+$10.00)` (`astra-opf-browser.json → single.initial.choiceTexts`,
`opf_config.display_options.symbol = "&#36;"`). Already reported and root-caused
in `WAPF-COMPAT-FLATSOME-WOODMART-EVIDENCE-2026-10-06.md`
(`formatPriceHint()` + `textContent`); not Astra-specific, listed here only so the
Astra cell is not read as a new defect.

## Residuals / not covered

- The image checks read the **visible** slide (WooCommerce gallery viewport first,
  then Astra's single-slide modal slider). A shopper who has navigated Astra's
  thumb strip away from slide 0 is not covered for either engine.
- ASTRA-3's proposed guard and ASTRA-2's fix are **proposals only**: this task
  forbids editing `assets/js/opf-frontend.js` and `includes/**`, so shared plugin
  source is unchanged.
- The Astra Pro addon was exercised on its shipped defaults plus quick view; the
  other addon modules (cart drawer, sticky add-to-cart, infinite scroll) were not
  run, and Astra's `single-product-gallery-layout` was left at `horizontal-slider`.
- The modal was opened from the modern shop card on `/shop/` only (Astra's
  `.ast-qv-on-image-click` and `after-summary` quick-view modes were not run).
- Not exercised: Astra child themes, Astra's Spectra/`uagb-modal` quick view,
  CartFlows swatches (both Astra Pro workarounds in `quick-view.js`), and
  WAPF Extended 3.2.x (not on this host).
- The clone stays under `/tmp` and is disposable. The fixture `cleanup` was run
  and validated (products, group post and attachments removed; the Astra Pro
  bundle cache regenerates on the next request); the harness restores the exact
  option snapshot it recorded at `prepare` time, so the quick-view/addon flips
  stay enabled in this clone. `fixture-state.json` is kept as the metadata of the
  run that produced the artifacts, not as the clone's current content. Flip
  plugins with `wp --path=/tmp/opf-astra-wp plugin (de)activate …`; a
  `php -S 127.0.0.1:8511` server was left running for this lane.

## Artifacts

All under `docs/compatibility/astra-20261006/`:

| file | content |
| --- | --- |
| `fixture-state.json` | versions, Astra options, product/group/attachment ids, urls, variation ids |
| `astra-opf-browser.json`, `astra-wapf-browser.json` | every check with detail strings, initial/priced/variation image states, modal state, AJAX-fragment probe, Store API cart payload, attributed console rows |
| `astra-control-browser.json` | Astra/Astra Pro baseline: no engine, 0 console rows, product/quick-view image timelines (ASTRA-4) |
| `clicktrace-astra-{no-engine,opf,wapf}.json` | ASTRA-3 root cause: page errors, thumbnail-click stacks, mutated attributes |
| `assets-astra.txt` | static evidence: engine asset + adapter-listener presence on `/shop/` vs the product page |
| `astra-single-<engine>-{initial,rule-a,cart}.png` | product page before/after the rule and the cart |
| `astra-modal-<engine>-{open,rule-a,cart}.png` | quick view modal with fields, totals, swapped image and cart |
| `astra-shop-<engine>.png`, `astra-control-modal.png` | shop loop with the quick-view trigger; control-run modal |

Harness scripts added to `bin/` (not shared plugin source):
`e2e-astra-compat-fixture.php`, `e2e-astra-compat-browser.mjs`,
`e2e-astra-compat-clicktrace.mjs`.
