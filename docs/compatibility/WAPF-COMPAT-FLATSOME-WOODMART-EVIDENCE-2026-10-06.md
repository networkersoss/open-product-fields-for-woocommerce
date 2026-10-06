# Flatsome + Woodmart: OPF vs WAPF Extended 3.1.5 — live theme-integration evidence (2026-10-06)

## Scope

Covers the two ledger rows that name a theme adapter:

- `WAPF-COMPAT-FLATSOME` — Flatsome 3.20.11
- `WAPF-COMPAT-WOODMART` — Woodmart 8.6.4

For each theme and each engine (Open Product Fields 0.1.0, WAPF Extended 3.1.5),
on a disposable WordPress + WooCommerce clone, live in Chromium:

1. fields render on the theme's product page,
2. pricing/total updates on selection,
3. image-change rules swap the theme gallery image,
4. add-to-cart carries the field values into the cart line,
5. JS console errors, attributed.

The same four behaviours are then exercised **inside each theme's AJAX quick
view**, because that is exactly what WAPF's `class-flatsome.php` and
`class-woodmart.php` adapters exist for.

Verdict in one line: **OPF matches WAPF on field rendering, priced totals, cart
lines and console cleanliness on both themes, and is behind on two theme-facing
behaviours — gallery-slide navigation (both themes) and quick-view
initialization (both themes).** WAPF itself does not swap images in the Flatsome
quick view, so there OPF is at parity (both fail). One extra, cosmetic OPF
defect surfaced on both themes: the priced-choice hint prints the raw `&#36;`
currency entity (below).

## Environment

| Component | Version / value |
| --- | --- |
| WordPress | 7.1.2 (SQLite Database Integration 3.0.2 drop-in) |
| WooCommerce | 11.1.0 |
| PHP | 8.5.11 (`php -S` with `PHP_CLI_SERVER_WORKERS=10`) |
| Open Product Fields | 0.1.0 (this checkout, `rsync`ed into both clones) |
| WAPF Extended | 3.1.5 (copied read-only from `bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`) |
| Flatsome | 3.20.11 (theme dir `flatsome/` extracted from the package's `Theme Files/flatsome.zip`) |
| Woodmart | 8.6.4 + bundled `woodmart-core` 1.1.9 (theme dir `woodmart/`, plugin from `woodmart/inc/plugins/woodmart-core.zip`) |
| Flatsome quick view | built in, `disable_quick_view` = 0 (theme default); `.quick-view` button, `.product-quick-view-container`, `mfpOpen` |
| Woodmart quick view | `quick_view` = 1 and `quick_view_variable` = 1 (theme defaults from `inc/admin/settings/shop.php`); `.open-quick-view` button, `.product-quick-view`, `woodmart-quick-view-displayed` |
| Clones | `/tmp/opf-theme-flatsome-wp` → `http://127.0.0.1:8420`, `/tmp/opf-theme-woodmart-wp` → `http://127.0.0.1:8421` |
| Theme options | Woodmart runs on its shipped option defaults (no `xts-woodmart-options` row in the clone). With a partial option row saved by hand, `Options::load_options()` overwrites the defaults merged by `load_defaults()`, the loop loses `products_hover` and the quick-view button disappears — recorded here because it is the one environment trap in this lane. |
| Cart page | the fixture switches `wc_get_page_id('cart')` to the classic `[woocommerce_cart]` shortcode (the stock block cart renders line items client-side only) and restores the previous content on cleanup |
| Production | untouched: nothing in `$HOME/followersya.com` was activated, deactivated, modified or written; the WAPF source dir was only copied out of |

## Fixture

`bin/e2e-theme-fw-fixture.php` (`prepare` | `cleanup`, guarded to the two clone
paths) builds two variable products per clone, one per engine, identical in
shape:

| slug | engine | group storage |
| --- | --- | --- |
| `opf-theme-fields` | OPF | `OPF\Engine\FieldGroup` → `opf_field_group` post, native `image_rules` |
| `wapf-theme-fields` | WAPF 3.1.5 | `_wapf_fieldgroup` written through WAPF's own `Field_Groups::raw_json_to_field_group()` |

Each product:

- attribute `size` = { `small`, `large` }; every variation carries its own gallery image
  (`small` → `v-small.png`, `large` → `v-large.png`);
- featured image `main.png` (the "original" both engines must fall back to);
- gallery `[ v-small, v-large, rule-a, rule-b ]`, so a matching rule and a
  variation image are both real gallery slides;
- `finish` select { none, gold **+10.00**, silver **+5.00** }, `edge` select { none, xl **+7.00** };
- rule 1 `finish=gold` → `rule-a`, rule 2 `edge=xl` → `rule-b`.

Totals rows are switched on for the run (`opf_price_summary_mode=three_line`,
`wapf_pricing_summary=lines`) and restored on cleanup. State (theme, versions,
product/group/attachment ids, URLs, selectors, variation ids) lands in
`fixture-state-flatsome.json` / `fixture-state-woodmart.json`.

## Run

One driver performs the whole lane per clone (fixture prepare with both engines
available → per-engine browser run with the other engine deactivated → static
evidence capture → both engines restored):

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce

# terminals (one per clone)
cd /tmp/opf-theme-flatsome-wp && PHP_CLI_SERVER_WORKERS=10 php -S 127.0.0.1:8420 router.php
cd /tmp/opf-theme-woodmart-wp && PHP_CLI_SERVER_WORKERS=10 php -S 127.0.0.1:8421 router.php

bash bin/e2e-theme-fw-run.sh /tmp/opf-theme-flatsome-wp http://127.0.0.1:8420 flatsome
bash bin/e2e-theme-fw-run.sh /tmp/opf-theme-woodmart-wp http://127.0.0.1:8421 woodmart
```

Individual pieces, if needed:

```sh
wp --path=/tmp/opf-theme-flatsome-wp eval-file bin/e2e-theme-fw-fixture.php prepare|cleanup
OPF_TFW_STATE=docs/compatibility/themes-fw-20261006/fixture-state-flatsome.json \
OPF_TFW_ENGINE=opf NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
  node bin/e2e-theme-fw-browser.mjs
bash bin/e2e-theme-fw-assets.sh http://127.0.0.1:8420 flatsome opf http://127.0.0.1:8420/product/opf-theme-fields/
OPF_TFW_STATE=docs/compatibility/themes-fw-20261006/fixture-state-flatsome.json \
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
  node bin/e2e-theme-fw-opf-image-drop.mjs
```

## Side-by-side results (real runs, 2026-10-06)

### Product page — 7 checks per cell

| # | check | Flatsome / WAPF | Flatsome / OPF | Woodmart / WAPF | Woodmart / OPF |
| --- | --- | --- | --- | --- | --- |
| 1 | field group renders | ✅ 1 select | ✅ 1 select | ✅ 1 select | ✅ 1 select |
| 2 | priced choices rendered (`Gold (+$10.00)`) | ✅ | ✅ (`Gold (+ $10)`) | ✅ | ✅ (`Gold (+ $10)`) |
| 3 | options total = 10 after selecting `finish=gold` | ✅ `$10.00` | ✅ `$10.00` | ✅ `$10.00` | ✅ `$10.00` |
| 4 | grand total = variation 100 + option 10 | ✅ `$110.00` | ✅ `$110.00` | ✅ `$110.00` | ✅ `$110.00` |
| 5 | `finish=gold` swaps the visible gallery image to `rule-a` | ✅ `rule-a` | ❌ `main.png` | ✅ `rule-a` | ❌ `main.png` |
| 6 | cart line carries `Finish: Gold (+$10.00)` | ✅ | ✅ | ✅ | ✅ |
| 7 | no uncaught browser errors | ✅ none | ✅ none | ✅ none | ✅ none |

Totals: Flatsome **WAPF 7/7, OPF 6/7**; Woodmart **WAPF 7/7, OPF 6/7**.

### Theme quick view — 7 checks per cell

| # | check | Flatsome / WAPF | Flatsome / OPF | Woodmart / WAPF | Woodmart / OPF |
| --- | --- | --- | --- | --- | --- |
| 1 | loop renders a quick-view button for the fixture product | ✅ | ✅ | ✅ | ✅ |
| 2 | fields render inside the modal | ✅ | ✅ (server-rendered markup) | ✅ | ✅ (server-rendered markup) |
| 3 | priced choice moves the modal options total to 10 | ✅ `$10.00` | ❌ empty | ✅ `$10.00` | ❌ empty |
| 4 | modal grand total = 100 + 10 | ✅ `$110.00` | ❌ empty | ✅ `$110.00` | ❌ empty |
| 5 | `finish=gold` swaps the modal gallery image to `rule-a` | ❌ `v-small` (WAPF limitation, see below) | ❌ `v-small` | ✅ `rule-a` | ❌ `v-small` |
| 6 | add-to-cart from the modal carries the field value | ✅ | ✅ | ✅ | ✅ |
| 7 | no uncaught browser errors | ✅ none | ✅ none | ✅ none | ✅ none |

Totals: Flatsome **WAPF 6/7, OPF 4/7**; Woodmart **WAPF 7/7, OPF 4/7**.
Overall harness totals: Flatsome WAPF **13/14**, Flatsome OPF **10/14**,
Woodmart WAPF **14/14**, Woodmart OPF **10/14**
(`browser-<theme>-<engine>.json`).

### Why OPF's quick-view cells fail while its markup renders

Measured in the same run (`phases.quickview.gallery`):

| theme | engine | `opf-frontend`/`wapf` script element in the archive document | engine global present | modal totals |
| --- | --- | --- | --- | --- |
| Flatsome | WAPF | WAPF's `frontend.min.js` is loaded on `/shop/` | `wapf_config` object, `WAPF` object | live |
| Flatsome | OPF | **none** | `OPF_FIELDS`/`OPF_IMAGE_RULES` object, but injected by the AJAX fragment's inline script | empty |
| Woodmart | WAPF | WAPF's `frontend.min.js` is loaded on `/shop/` | `wapf_config` object, `WAPF` object | live |
| Woodmart | OPF | **none** | same as above | empty |

`bin/e2e-theme-fw-assets.sh` confirms it statically (engine frontend script
references on `/shop/` vs the product page): WAPF 4/4, OPF **0**/2
(`assets-<theme>-<engine>.txt`). OPF's module is registered on
`wp_enqueue_scripts` (`includes/Service/Assets.php:20`) but only enqueued from
`Renderer::render()` (`includes/Service/Renderer.php:279`), i.e. only on a page
that renders fields — never on the archive page that opens the modal. WAPF
enqueues its frontend unconditionally
(`includes/controllers/class-public-controller.php:22` + `:187`).

## What WAPF's theme adapters actually do (3.1.5 source)

`class-flatsome.php` (22 lines, loaded when the active theme's name is
`Flatsome`, `class-integrations-controller.php:25-28`):

```js
jQuery(document).ajaxSuccess(function(e,xhr,d){
    jQuery(document).on('mfpOpen', function(){ new WAPF.Frontend(jQuery('.product-quick-view-container')); });
});
```

`class-woodmart.php` (35 lines):

- `woodmart-quick-view-displayed` → `new WAPF.Frontend(jQuery('.product-quick-view'))`
- `wapf/add_to_cart_redirect_when_editing` → `false` (no cart redirect on Woodmart)
- `wapf/layers/product_image_classes` → appends `wd-carousel-item`
- `wapf/image_changed` → repaints `.zoomImg` and `.product-image-thumbnail img`
- `lcp/auto_scroll_to` → `wrapper.swiper.slideTo(scrollIndex)`

Live in 3.1.5 on these two themes:

| adapter hook | live effect observed |
| --- | --- |
| Flatsome `mfpOpen` reinit | ✅ fields, conditionals, pricing and totals work inside the Flatsome modal (checks 2–4 of the table) |
| Woodmart `woodmart-quick-view-displayed` reinit | ✅ fields and pricing work inside the Woodmart modal; rule image swap also works there |
| Woodmart `wapf/image_changed` → `.zoomImg` / `.product-image-thumbnail img` | partially inert: `.zoomImg` exists on the Woodmart product page (created by the theme's `$.fn.zoom` in `woodmartThemeManager.initZoom`), but `initZoom()` excludes quick-view galleries (`.woocommerce-product-gallery__wrapper:not(.quick-view-gallery)`), and **`.product-image-thumbnail` does not exist anywhere in Woodmart 8.6.4** (grep of the whole theme: 0 hits) |
| Woodmart `wapf/layers/product_image_classes` | inert in 3.1.5: the filter is registered by this adapter and consumed nowhere in the installed plugin (0 other references) |
| Woodmart `lcp/auto_scroll_to` | inert in 3.1.5: `auto_scroll_to` appears only in this adapter file — nothing dispatches it (Woodmart's own `lcp` code is the Largest-Contentful-Paint tracker, unrelated) |
| Woodmart edit-cart redirect filter | source-level only here (the fixture has no cart-edit flow); WAPF sets it to `false`, OPF defaults `opf_add_to_cart_redirect_when_editing` to `true` (`includes/Service/CartEdit.php:326`) |

### Flatsome quick view: neither engine swaps the rule image

The Flatsome lightbox gallery is `.product-gallery .slider.product-gallery-slider.main-images`
with `.slide` items (Flickity), no `.images` class and no
`.woocommerce-product-gallery` class (measured: `has_images_class=false`,
`imagesDescendant=false`). WAPF's image engine arms only on
`d.find('.images .wp-post-image')` (`A()`/`C()` in `frontend.min.js`), so inside
the Flatsome modal it never arms — its Flatsome adapter only reinitializes
fields/pricing, and the theme's own variation image stays on screen. OPF fails
there too. **Parity, not an OPF-specific gap.**

For contrast, the Woodmart modal wrapper *does* carry `images`
(`woocommerce-product-gallery__wrapper images quick-view-gallery wd-carousel …`),
which is why WAPF's rule swap works inside the Woodmart modal but not inside
Flatsome's.

## OPF gaps, with the smallest proposed fixes

### Gap 1 — gallery-slide navigation assumes FlexSlider; a matched rule is silently dropped

Evidence: product page, both themes, OPF only. `window.OPF_IMAGE_RULES` is
published (2 rules for group 99), the fields work, the totals work, and yet the
visible image stays `main.png`. `bin/e2e-theme-fw-opf-image-drop.mjs`
falsifies the cause in one run per theme:

| step | Flatsome | Woodmart |
| --- | --- | --- |
| variation selected | `main.png` | `main.png` |
| `finish=gold`, rule target is a gallery slide | `main.png` ❌ | `main.png` ❌ |
| rule target's slide removed from the DOM | `rule-a` ✅ | `rule-a` ✅ |

Source (`assets/js/opf-frontend.js`): `updateProductImage()` resolves the rule
target, finds it among the `.woocommerce-product-gallery__image` slides, calls
`navigate( targetIndex )` and returns — but `navigate()` (line 2986) only knows
`jQuery(gallery).data('flexslider')` and `.flex-control-nav a`, and returns
`false` on Flatsome (Flickity) and Woodmart (Swiper). Both branches drop the
image:

- matched rule, lines 3037–3041 (`const targetIndex = …; if ( targetIndex >= 0 ) { restoreImages(); navigate( targetIndex ); return; }`)
- selected variation, lines 3025–3029 (`const variationIndex = …; if ( variationIndex >= 0 ) { navigate( variationIndex ); return; }` — the `applyMainImage()` fallback right below is only reached when the target is *not* a gallery slide)

This affects the native `image_rules` model and the WAPF-shaped `data-opf-gi`
model alike: both converge on the single `updateProductImage()` call inside
`writeTotals()` (line 3402).

Smallest fix (in `updateProductImage()`, ~4 lines, no new abstraction):

```js
if ( targetIndex >= 0 ) {
    restoreImages();
    if ( navigate( targetIndex ) ) return;   // FlexSlider path unchanged
}
restoreImages();
applyMainImage( activeImageState(), target.url, { alt: target.alt } );
```

and the same guard in the variation branch (there the fall-through already exists,
so the patch is a single line):

```js
if ( variationIndex >= 0 && navigate( variationIndex ) ) return;
applyMainImage( activeImageState(), variation.url, variation );
```

Optional follow-up (only if theme-native slide animation is wanted
rather than the paint fallback WAPF itself uses): teach `navigate()` the two
theme galleries — Flatsome `jQuery(gallery).data('flickity')` (the instance is
reachable there; `window.Flickity` is lazy-loaded and undefined at runtime) and
Woodmart `wrapper.swiper.slideTo(index)`.

### Gap 2 — no quick-view adapter: fields render but never initialize

Evidence: modal checks 3–4 fail for OPF on both themes while WAPF passes; the
archive document loads no `opf-frontend` script element (see the table above).
Source: OPF's frontend is a single module that initializes once
(`initAll()` at line 3557, `DOMContentLoaded`/`setTimeout` at line 3582), binds
its totals/image pass to the `[data-opf-fields]` element that exists at load
(`initTotals()`, line 3512) and exposes **no** global entry point, no
`MutationObserver` and no theme event listener (`mfpOpen`,
`woodmart-quick-view-displayed`, `woodmart-quick-view` — 0 references in
`assets/js/opf-frontend.js` and `includes/**`).

Smallest fix, two parts:

1. Enqueue the frontend module where a quick view can open — e.g. additionally
   register it when the request is a product archive/shop page and the theme is
   one with an AJAX quick view (`Assets::register_frontend()`,
   `includes/Service/Assets.php:20`). Keep it a no-op when no fields are on the
   page; the module already exits when it finds no `[data-opf-group]`.
2. Give the module a re-init entry point and call it from the theme events —
   expose `window.OPF_FRONTEND = { init, initTotals }` (or dispatch/observe a
   small `opf:reinit` event) and add the two listeners WAPF ships:
   `jQuery(document).on('mfpOpen', …)` for Flatsome and
   `jQuery(document).on('woodmart-quick-view-displayed', …)` for Woodmart, each
   calling `init(root)` for the modal root (the existing per-group
   `dataset.opfInitialized` guard already makes re-runs idempotent, and
   `initTotals()` needs the same scoping so the modal's totals node is bound).

### Minor finding — priced-choice hint prints the raw currency entity

Live on both themes (OPF only): the choice hint renders as `Gold (+ &#36;10)`
instead of `Gold (+$10.00)`. Measured in Chromium on the OPF product page:

```json
{
  "optionText": [ "Choose an option", "None", "Gold (+ &#36;10)", "Silver (+ &#36;5)" ],
  "opf_config.display_options.symbol": "&#36;"
}
```

Mechanism (`assets/js/opf-frontend.js`): `formatPriceHint()` (line 2396) builds
the hint from `configured.symbol` — the HTML-escaped `&#36;` the server
publishes — and the choice writer assigns it through `node.textContent`
(line 2455), where the entity is **not** decoded. The totals block is unaffected
because it uses `el.innerHTML = fmtMoney(...)` (line 3474–3477), so the parser
decodes it (`$100.00 / $10.00 / $110.00` render correctly). WAPF prints
`+$10.00`.

Smallest fix (one line in `formatPriceHint()`, same place the module already
normalises `&nbsp;`):

```js
const symbol = String( configured.symbol || '$' ).replace( /&#(\d+);/g, ( _, code ) => String.fromCodePoint( Number( code ) ) );
```

Cosmetic difference, not a defect: OPF trims trailing zero decimals
(`+ $10`) where WAPF keeps `+$10.00` (`fraction.replace( /0+$/, '' )` in the same
function). Recorded so a reviewer does not read it as a bug.

### Matches (no adapter needed)

- **Cart line**: identical on both themes — `Finish: Gold (+$10.00)` and
  `$110.00` in the cart for OPF and WAPF, from the product page *and* from the
  quick view. OPF's server-side integration needs no theme awareness.
- **Product-page fields, conditionals, priced totals, hints**: parity.
- **Console cleanliness**: 0 uncaught errors / `console.error` for all four
  cells, on the product page and in the quick view.
- **Theme-agnostic hooks OPF already has**: `opf:variation-changed`,
  `opf/pricing`, `woocommerce_gallery_init_zoom` re-trigger on image swap
  (`assets/js/opf-frontend.js:1565`), plus the `wapf/*` alias bridge in
  `includes/Compat/WapfHooks.php` (so `wapf/add_to_cart_redirect_when_editing`
  and friends keep working for migrated third-party code). None of these are a
  substitute for a theme event listener, because the module is not loaded on the
  page where the theme injects the modal.

## Console errors

Every run recorded `pageerror` + `console.error` with attribution
(`engine_scripts`, `engine_globals` in the JSON). Result: **no errors at all** in
the eight phase runs. The only recurring console noise is
`JQMIGRATE: Migrate is installed, version 3.4.1` (WooCommerce/jQuery, `log`
level, not an error).

## Residuals / not covered

- The image-swap checks read the **visible** image (largest intersection area
  with the gallery root). Both themes paint the first gallery image; a shopper
  who has navigated the theme carousel to another slide would not see it. That
  "navigated-away slide" case is not covered for either engine on either theme.
- Flatsome Flickity and Woodmart Swiper are both driven from the first slide by
  the engines; per-slide navigation APIs are only *proposed* in Gap 1, not
  implemented (this task forbids touching shared plugin source).
- The Woodmart edit-cart redirect filter is a source-level difference only; no
  cart-edit flow was run in these clones.
- Not exercised: Flatsome's `additional_variation_images` extension with
  per-variation extra images, Woodmart's `wdReplaceMainGallery` events, AJAX
  variation sets, and WAPF Extended 3.2.x (not on this host).
- The two clones stay under `/tmp` with both engines active; they are disposable.

## Artifacts

All under `docs/compatibility/themes-fw-20261006/`:

| file | content |
| --- | --- |
| `fixture-state-flatsome.json`, `fixture-state-woodmart.json` | fixture ids, URLs, selectors, variation ids, theme + engine versions |
| `browser-<theme>-<engine>.json` | all 14 checks per cell with detail strings, cart text, notices, console errors, gallery/engine probe |
| `product-<theme>-<engine>[-rule].png` | product page before/after the rule |
| `quickview-<theme>-<engine>.png` | quick view modal with fields and totals |
| `opf-image-drop-<theme>.json` / `.png` | Gap 1 falsification (rule target present vs removed) |
| `assets-<theme>-<engine>.txt` | static evidence: engine script presence on `/shop/` vs product page, adapter hooks, gallery markup |

Harness scripts added to `bin/` (not shared plugin source):
`e2e-theme-fw-fixture.php`, `e2e-theme-fw-browser.mjs`,
`e2e-theme-fw-assets.sh`, `e2e-theme-fw-run.sh`,
`e2e-theme-fw-opf-image-drop.mjs`.
