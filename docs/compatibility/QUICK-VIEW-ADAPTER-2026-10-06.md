# Quick-view adapter lane — shipping the runtime, a public re-init and inline per-group settings (2026-10-06)

## Scope

Follow-up to `GALLERY-COMPAT-CORE-FIXES-2026-10-06.md`, which fixed the five
shared-core defects and named this lane as out of scope. The three remaining
gaps were the same in all four compatibility evidence docs:

| Source | Gap |
| --- | --- |
| `WAPF-COMPAT-QUICK-VIEW-PRO-EVIDENCE-2026-10-06.md` §5, `WAPF-COMPAT-ASTRA-EVIDENCE-2026-10-06.md` ("the engine can be re-initialized on an injected root"), `WAPF-COMPAT-FLATSOME-WOODMART-EVIDENCE-2026-10-06.md` Gap 2 | **No public re-init entry point.** `init()` / `initTotals()` are module-private and bound to the document; nothing calls them for an injected modal root. |
| same, "engine JS is present on the page that opens the modal" | **No asset shipping.** `Assets` only *registers* the bundle; `Renderer::render()` enqueues it, so a shop/archive page that opens a quick view ships no script/CSS at all. |
| same, "per-product field metadata is reachable for the injected root" | **No inline per-group settings.** The registry is the page-level `window.OPF_FIELDS`; the AJAX fragment's own inline `<script>` is destroyed by themes that sanitize the fragment before inserting it (Astra Pro: `DOMPurify.sanitize()` + `innerHTML`). |

**Untouched by design:** the WAPF comparison rows that already pass, the Store
API/cart pricing, the WPML integration (no string registration, no new surface),
and the multi-value companion-name fix from the core-fixes lane.

## Files changed

| File | Change |
| --- | --- |
| `includes/Service/QuickView.php` | **new** — detects the quick-view surface of the request and enqueues the bundle + modal adapter (`surface()`, `maybe_enqueue()`, `enqueue()`) |
| `assets/js/opf-quick-view.js` | **new** — the four modal listeners, plus a queue that holds a root until the (deferred) frontend module publishes its API |
| `includes/Service/Assets.php` | registers the adapter (`:49`); `enqueue_frontend()` gains `bool $product_config = true` so a quick-view page ships currency/formatting without a product base price (`:62`, `:108`) |
| `includes/Service/Renderer.php` | registry entry extracted to `group_registry()` (`:328`); `inline_group_data()` (`:419`) emitted as `data-opf-registry` on the group element (`:521`); `render()` computes the registry once and hands the entry down (`:279`) |
| `assets/js/opf-frontend.js` | `readGroupInlineData()`/`groupFields()`/`groupImageRules()` (`:630`,`:641`,`:650`); root-scoped `syncCalcFields(root)` (`:2835`), `writeTotals(root)` (`:3137`), `initTotals(root)` (`:3606`); public `window.OPF_FRONTEND` + `opf:reinit` + `opf:frontend-ready` (`:3677`-`:3694`); modal gallery resolution in `updateProductImage()` (`:3000`) |
| `open-product-fields-for-woocommerce.php` | `QuickView::init();` after `Assets::init();` |
| `tests/Unit/QuickViewAssetsTest.php` | **new** — 12 tests: surface detection matrix (including "no loop, no bytes"), the handles a detected surface ships, idempotence, and the config a quick-view page publishes |
| `tests/Unit/RendererInlineRegistryTest.php` | **new** — 4 tests: the emitted `data-opf-registry` payload (fields, rules, swap mode, precomputed registry) |
| `tests/js/opf-reinit.test.cjs` | **new** — 6 tests: public entry point, `init(root)` on a detached subtree reading its inline payload, idempotent re-runs, `writeTotals(root)` pricing from the inline payload, `opf:reinit`, `groupImageRules()` |
| `tests/js/opf-quick-view.test.cjs` | **new** — 4 tests: the four listeners, root resolution per theme, the pre-API queue, and the no-jQuery path |

## 1. Asset shipping — `OPF\Service\QuickView`

`wp_enqueue_scripts` priority 20 plus the Barn2 runtime action
(`includes/Service/QuickView.php:46`):

```php
add_action( 'wc_quick_view_pro_load_scripts', [ __CLASS__, 'enqueue' ] );
add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ], 20 );
```

`surface()` (`:67`) decides, in this order:

| # | Signal | Surface |
| --- | --- | --- |
| 1 | `wp_script_is( 'wc-quick-view-pro', 'enqueued' )` | `barn2` (exact: Quick View Pro only loads its runtime on a request where it will render a button — archive or `[products]`/`[quick_view]` shortcode page) |
| 2 | `is_woocommerce()` or `is_cart()` (`:117`) | loop context for Astra Pro / Flatsome / Woodmart (quick-view buttons render from `woocommerce_after_shop_loop_item` and the themes' own loop hooks; related/upsell lists and cart cross-sells included) |
| 3 | `astra_get_option( 'shop-quick-view-enable' )` not empty/`disabled` | `astra` |
| 4 | `get_template() === 'flatsome'` + `disable_quick_view` theme mod off | `flatsome` |
| 5 | `woodmart_get_opt( 'quick_view' )` | `woodmart` |
| — | a plain page/post embedding `[products]`, `[product_category]`, … or a `woocommerce/product-collection`-family block | counts as a loop context for 3-5 |

`enqueue()` (`:94`) is idempotent and ships `opf-frontend` + `opf-frontend.css`
(through `Assets::enqueue_frontend()`) and the new `opf-quick-view` adapter.

**Zero bytes where no fields render still holds** — measured on the four clones
(`curl | grep -c`, 2026-10-06):

| document | `opf-frontend` refs | `opf-quick-view.js` | `data-opf-registry` | `window.opf_config` |
| --- | --- | --- | --- | --- |
| Barn2 clone `/` | 0 | 0 | 0 | 0 |
| Barn2 clone `/qv-shop/` (`[products]`) | 2 | 1 | 0 | 1 |
| Barn2 clone `/product/qv-opf-product/` | 2 | 1 | 1 | 2 |
| Astra clone `/`, Flatsome clone `/`, Woodmart clone `/` | 0 | 0 | 0 | 0 |
| Astra clone `/shop/`, Flatsome clone `/shop/`, Woodmart clone `/shop/` | 2 | 1 | 0 | 1 |
| Astra clone `/cart/` (cross-sells) | 2 | 1 | 0 | 1 |
| Astra / Flatsome / Woodmart product pages | 2 | 1 | 1 | 2 |

Before this lane the archive/quick-view documents served **0** `opf-frontend` refs
(the Astra evidence doc's `assets-astra.txt` and the theme docs' `assets-*.txt`
recorded "OPF 0/2" on `/shop/`), and only a page that rendered fields served the
bundle at all.

The `2` `window.opf_config` tags on a quick-view product page are deliberate: the
quick-view pass publishes currency/formatting **without**
`product_base_price`/`formula_base_price` (a price captured from whatever the
loop was rendering must not outrank the injected group's own
`data-opf-product-price`), and the renderer still publishes the full,
product-scoped config when it renders fields on the same request — the later
assignment wins, exactly as before this lane.

## 2. Public re-init entry point

`assets/js/opf-frontend.js:3685`:

```js
window.OPF_FRONTEND = { init, initTotals, reinit };
```

| Entry | Behaviour |
| --- | --- |
| `OPF_FRONTEND.init( root )` | scans `root` (element or document) for `[data-opf-group]`; a group already carrying `data-opf-initialized` is skipped |
| `OPF_FRONTEND.initTotals( root )` | binds that root's `[data-opf-fields]` container, its `form.cart` quantity inputs and its variations form; a container already carrying `data-opf-totals-initialized` is skipped |
| `OPF_FRONTEND.reinit( root )` | `init( root )` + `initTotals( root )` — the documented re-initialise entry |
| `document` event `opf:reinit` | `reinit( event.detail.root \|\| document )` for integrations that cannot reach the global |
| `document` event `opf:frontend-ready` | dispatched once the global exists, so the adapter can flush roots seen earlier |

Every write of the totals pass is now resolved **inside the given root**
(`writeTotals( root )` → totals node, groups, quantity input, image evaluation),
so a modal and an inline product on the same page never share a totals writer.
The variation lifecycle listeners are delegated from `document` once
(`found_variation`/`hide_variation`/`reset_data`), so an injected variations form
is covered without re-binding.

`assets/js/opf-quick-view.js` wires the surfaces to the events each integration
actually fires (verified against the clones' sources):

| Surface | Event | Root |
| --- | --- | --- |
| Barn2 Quick View Pro (shop button and `[quick_view]` shortcode) | jQuery `quick_view_pro:load` (`assets/js/wc-quick-view-pro.js`: `l.$elm.trigger('quick_view_pro:load', [l.$elm])`) | the modal element handed as the second handler argument, else `.jquery-modal #quick-view` |
| Astra + Astra Pro | native `ast_quick_view_loader_stop` (`astra-addon …/unminified/quick-view.js:287`: `document.dispatchEvent( new Event( … ) )`) | `#ast-quick-view-modal .product` |
| Flatsome | jQuery `mfpOpen` (Magnific Popup's `triggerHandler('mfp' + name)` on `document`) | `.product-lightbox`, else `.product-quick-view-container` |
| Woodmart | jQuery `woodmart-quick-view-displayed` (`woodmart/js/scripts/wc/quickView.js:83`, triggered on `body`, bubbles to `document`) | `.product-quick-view` |

A root whose selector does not match falls back to `document`, so a theme that
renames its wrapper degrades to a document-wide re-init instead of a no-op.

## 3. Per-group settings inline (`data-opf-registry`)

`Renderer::render_group()` now emits the group's own client payload
(`includes/Service/Renderer.php:521`), with the registry computed once per
request and shared with the `window.OPF_FIELDS` print (`Renderer.php:279`):

```html
<div class="opf-field-group label-below" data-group="18" … data-opf-registry="{…}" data-opf-group="18">
```

The payload is exactly what `window.OPF_FIELDS[gid]` carries for that group plus
the OPF-native rules (live sample from the Barn2 clone's product page):

```json
{
 "fields": {
  "finish": { "type": "select",   "choices": ["none", "gold", "silver"] },
  "edge":   { "type": "select",   "choices": ["none", "xl"] },
  "extras": { "type": "checkbox", "choices": ["gift", "wrap"] }
 },
 "image_rules": [
  { "target_url": "…/opf-qv-rule-a.png", "conditions": [ { "field": "finish", "value": "gold" } ] },
  { "target_url": "…/opf-qv-rule-b.png", "conditions": [ { "field": "edge", "value": "xl" } ] }
 ],
 "image_rule_mode": "rules"
}
```

`readGroupInlineData()` parses it defensively (a non-object attribute is
ignored), and the frontend reads it wherever it needs the group's definitions:
`groupFields()` (init, pricing, calc sync, per-row formula scopes) and
`groupImageRules()` (the pricing pass). The page-level globals keep priority, so
a normally rendered page behaves exactly as before; the inline payload is only
the fallback for an injected group whose page had no registry.

`updateProductImage()` also resolves modal galleries that WooCommerce's
`.woocommerce-product-gallery` wrapper does not cover (Astra Pro
`.ast-qv-image-slider.images`, Woodmart `.quick-view-gallery.images`,
`:3000`) — scoped to the re-init root, and only when no
`.woocommerce-product-gallery` exists inside that root.

## Verification

```sh
cd .../open-product-fields-for-woocommerce
php -l includes/Service/{Assets,QuickView,Renderer}.php
node --check assets/js/opf-frontend.js && node --check assets/js/opf-quick-view.js
vendor/bin/phpunit --no-coverage           # 1128 tests / 4785 assertions / 0 failures (baseline 1112 / 4739)
node --test tests/js/*.cjs                 # 148 / 148 (baseline 138 / 138)

# integration harnesses, after rsyncing this checkout into each clone
OPF_QV_ENGINE=opf OPF_QV_SCENARIO=a  node bin/e2e-quick-view-pro-browser.mjs
OPF_QV_ENGINE=opf OPF_QV_SCENARIO=b  node bin/e2e-quick-view-pro-browser.mjs
OPF_ASTRA_ENGINE=opf OPF_ASTRA_STATE=docs/compatibility/astra-20261006/fixture-state.json \
  OPF_ASTRA_ARTIFACTS=docs/compatibility/astra-20261006 node bin/e2e-astra-compat-browser.mjs
bash bin/e2e-theme-fw-run.sh /tmp/opf-theme-flatsome-wp http://127.0.0.1:8420 flatsome
bash bin/e2e-theme-fw-run.sh /tmp/opf-theme-woodmart-wp http://127.0.0.1:8421 woodmart
```

### Scores, before → after

| Lane | Harness | Before (2026-10-06 evidence doc) | After (this run) | WAPF 3.1.5 reference |
| --- | --- | --- | --- | --- |
| Barn2 Quick View Pro, scenario A (`/qv-shop/`) | `bin/e2e-quick-view-pro-browser.mjs` | **7 / 15** | **15 / 15** | 14 / 15 |
| Barn2 Quick View Pro, scenario B (`/qv-shop-inline/`) | `bin/e2e-quick-view-pro-browser.mjs` | **8 / 15** | **15 / 15** | (not reachable: WAPF loads site-wide) |
| Astra + Astra Pro | `bin/e2e-astra-compat-browser.mjs` | **12 / 17** (product page 7/9, modal 5/8) | **16 / 17** (product page 9/9, modal 7/8) | 14 / 16 |
| Flatsome 3.20.11 | `bin/e2e-theme-fw-run.sh` | **10 / 14** | **13 / 14** | 13 / 14 |
| Woodmart 8.6.4 | `bin/e2e-theme-fw-run.sh` | **10 / 14** | **14 / 14** | 14 / 14 |

Every check that changed is a quick-view check:

* Barn2 A/B: bundle present on the trigger page (was "no"), modal totals value +
  both priced choices + clear-the-choice (were empty), both rule image swaps (were
  `v-small`), and the Ajax add-to-cart route (was a native POST after Barn2's
  `TypeError` — that half came from the core-fixes lane's companion-name fix).
* Astra: `data-opf-initialized` present, modal totals `$10.00`/`$110.00` (were
  empty), modal rule image `rule-a` (was `v-small`); product-page console rows
  dropped from 5 to 2 (the FlexSlider `TypeError`s are gone; the two remaining
  rows are `net::ERR_ABORTED …opf-astra-main.png`, the same media rows the WAPF
  reference records).
* Flatsome: modal options/grand totals live (were empty); Woodmart additionally
  swaps the modal rule image (was `v-small`).

### Failures that remain, and why

| Lane | Failing check | Reason |
| --- | --- | --- |
| Astra, modal | `modal engine global present in the page` (`window.OPF_FIELDS` undefined) | By design: OPF loads no page-level registry on a page that renders no fields. WAPF ships `wapf_config` site-wide; OPF ships the modal's metadata inline (`data-opf-registry`) and the adapter reads it back from the DOM. The check's other half (`data-opf-initialized` on the injected group) passes. |
| Flatsome, quick view | `finish=gold swaps the quick-view gallery image to rule-a` | Parity, not an OPF gap: Flatsome's lightbox gallery (`.product-gallery .slider.product-gallery-slider.main-images`) carries neither `.images` nor `.woocommerce-product-gallery`, so WAPF fails the same check in the same run (`browser-flatsome-wapf.json`). |

## Live production impact (measured)

This checkout *is* the served plugin directory, so the change is live on the next
uncached render. `QuickView::surface()` is dormant on production — no Barn2 Quick
View Pro plugin is installed and the active theme is `framework`, not Astra /
Flatsome / Woodmart (`wp plugin list` + `get_template()`), so no request there
enqueues the new adapter or the bundle (verified: `opf-quick-view.js` absent from
the served product page).

The only production byte change is the inline `data-opf-registry` payload on a
page that renders fields. Measured on the live product page
`/es/comprar-seguidores-instagram/` (cache-bypassed render, 3 groups):

| | bytes |
| --- | --- |
| page HTML | 250,904 |
| `window.OPF_FIELDS` (unchanged) | 2,710 |
| `data-opf-registry` × 3 (new) | 5,222 (HTML-escaped; the JSON is ~2.6 KB) |

≈2% of that page, and it compresses with the rest of the document. Browsers
holding the previous `opf-frontend.js?ver=0.1.1` keep working: on a page that
renders fields the module reads the existing globals, and the extra attribute is
ignored by the older runtime. Purging the FastCGI cache (`reload-php.sh`, rule
0.5 of `AGENTS.md`) is an operational call for the orchestrator, not a
correctness requirement of this change.

## Residuals / not covered

* **Upload fields in a modal.** The quick-view page has no registry, so
  `opf-uploads` is not shipped with it; a modal upload field falls back to the
  native multipart file input instead of the Ajax uploader. Shipping the uploader
  for a modal that may never contain one was left out deliberately.
* **Cheap heuristic, not a guarantee.** `is_woocommerce()`/`is_cart()` +
  shortcodes/blocks cover the loop surfaces; a quick-view trigger emitted by a
  third-party widget outside those contexts (or another quick-view plugin) would
  still open a modal without the runtime. Barn2 is exact because its own runtime
  signal is used; the three theme detectors are settings-based.
* **Flatsome/Woodmart product-page image swap** depends on the core-fixes lane's
  fall-through painting, not on theme carousel APIs (per-slide Flickity/Swiper
  navigation is still not implemented, as recorded there).
* **Not exercised:** the Barn2 `[quick_view]` shortcode page in isolation (the
  event/root path is unit-tested and the runtime signal is the same as the
  `[products]` page), Editor/block-theme product collections, WooCommerce
  Quick View X and other QV plugins, and a store with a currency plugin
  (Aelia/WOOCS) inside a modal — the unit test pins only that the quick-view
  config carries no product-scoped base price.
* **No per-store gating of the inline payload.** `data-opf-registry` ships on
every rendered group, including stores that have no quick-view surface (see the
measured production cost above). Gating it on `QuickView::surface()` was
considered and left out to keep the change to what the evidence docs asked for:
it would trade bytes for a store-level probe inside the render path.
* The Astra fixture was re-prepared for this lane (the earlier evidence run had
  cleaned it up), so `astra-20261006/fixture-state.json` now carries new post ids
  (product 117, group 120) and the Astra Pro quick-view addon settings are left
  enabled in that disposable clone. The WAPF reference JSONs
  (`astra-wapf-browser.json`, `browser-*-wapf.json`) are from the same day and
  were not re-run for Astra; Flatsome/Woodmart were re-run for both engines by
  `bin/e2e-theme-fw-run.sh`.
* Clones stay disposable under `/tmp`; no production file, option or plugin was
  touched.

## Artifacts

| File | Content |
| --- | --- |
| `quick-view-pro-20261006/quick-view-opf-scenario-{a,b}-results.json` + `.png` | Barn2 lane, this run |
| `astra-20261006/astra-opf-browser.json`, `astra-{single,modal}-opf-*.png` | Astra lane, this run |
| `themes-fw-20261006/browser-{flatsome,woodmart}-{opf,wapf}.json`, `assets-{theme}-{engine}.txt` | theme lanes, this run (both engines) |
