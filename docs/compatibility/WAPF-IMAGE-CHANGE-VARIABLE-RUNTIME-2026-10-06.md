# Variable-product image-change runtime: OPF vs WAPF Extended 3.1.5 — 2026-10-06

## Scope

Closes the main residual of ledger row `WAPF-INTERACTION-IMAGE-CHANGE`: the
**variable-product lifecycle** (a) and the **non-default gallery plugin** run
(b), both left open by the 2026-10-05 comparative re-grade. It also fixes a real
OPF defect that only becomes reachable on a variable product.

Result: on the stock WooCommerce flexslider gallery, OPF and WAPF Extended
3.1.5 are **byte-for-byte equivalent** on every image-change transition
(56/56 Chromium checks, both swap modes). With the free wp.org gallery plugin
`woo-product-gallery-slider` 2.3.25 active, OPF reaches 49/56 and the residual
failures are the plugin's own variation-image handler, which WAPF 3.1.5 fails
too (OPF passes one case where WAPF fails).

## Environment

- Disposable clone **`/tmp/opf-image-change-var-wp`** (copied from
  `/tmp/opf-variation-e2e-wp`, fresh `wp core install`, SQLite drop-in),
  site URL `http://127.0.0.1:8410`, WordPress 7.1.2, WooCommerce 11.1.0,
  PHP 8.5.11, theme `twentytwentyfive`.
- Active: Open Product Fields `0.1.0` (this checkout, `rsync`ed), **Advanced
  Product Fields for WooCommerce Extended 3.1.5** (copied read-only from
  `/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`),
  WooCommerce, SQLite Database Integration. Later `woo-product-gallery-slider`
  2.3.25 (wp.org) is installed and activated for run (b).
- Production WordPress was **read only**: the WAPF source directory was only
  copied out of; nothing in `/home/followersya-5hqi7/followersya.com/` was
  activated, deactivated, modified or written.
- Serving: `php -S 127.0.0.1:8410` with the clone's `router.php` (the clone's
  `wp-config.php` pins `WP_HOME`/`WP_SITEURL` to the same origin, so authored and
  served URLs match — no URL-context juggling).

## Fixture

`bin/e2e-image-change-variable-fixture.php` (`prepare` | `cleanup`) builds **four
variable products**, one per engine × swap mode, with identical shape:

| key | engine | mode | slug |
| --- | --- | --- | --- |
| `opf-rules` | OPF (native `image_rules`) | `rules` | `opf-icv-rules` |
| `opf-last` | OPF (native `image_rules`) | `last` | `opf-icv-last` |
| `wapf-rules` | WAPF Extended 3.1.5 (`layout.gallery_images`) | `rules` | `wapf-icv-rules` |
| `wapf-last` | WAPF Extended 3.1.5 | `last` | `wapf-icv-last` |

Each product:

- attribute `size` = { `small`, `large` }; **every variation carries its own
  gallery image** (`size=small` → `v-small.png`, `size=large` → `v-large.png`);
- featured image `main.png` (the "original" the engines must restore);
- gallery `[ v-small, v-large, rule-a, rule-b ]`, so a matching rule and a
  variation image are both real, navigable flexslider slides;
- fields `finish` { none, gold, silver } and `edge` { none, xl };
- rule 1 `finish=gold` → `rule-a`; rule 2 `edge=xl` → `rule-b` (authored order).

The fixture also forces `woocommerce_coming_soon=no` (a fresh WooCommerce install
hides the add-to-cart form — and every field group hooked on
`woocommerce_before_add_to_cart_button` — behind the "Coming soon" screen) and
restores the previous value on `cleanup`.

State (product/group/attachment ids, every URL) is written to
`docs/compatibility/image-change-variable-20261006/fixture-state.json`.

## Real OPF defect found and fixed

### Symptom (live, before the fix)

On a variable product, OPF's OPF-native image rules resolved the **original**
image where WAPF resolves the **selected variation's** image, and dropped a
still-matching rule when the variation changed. Same sequence, same clone,
`finish`/`edge` fields, `size` variation selected:

| step | WAPF 3.1.5 | OPF (before) |
| --- | --- | --- |
| variation=small, then `finish=gold` | `rule-a` | `rule-a` |
| … then `finish=none` | **`v-small`** (variation image) | **`main`** ❌ |
| variation=small + `edge=xl` (→`rule-b`), switch variation=large | **`rule-b`** (rule survives) | **`v-large`** ❌ |
| variation=large + `finish=gold` (→`rule-a`), then `finish=none` | **`v-large`** | **`main`** ❌ |

### Root cause

`assets/js/opf-frontend.js`, `updateProductImage()` — the OPF-native
`image_rules` consumer (`window.OPF_IMAGE_RULES`) had no selected-variation
fallback: with no matching rule it always ran
`restoreImages(); navigate( snapshot.slideIndex );`, i.e. back to the *original*
image, clobbering WooCommerce's own variation-image swap. It was also never
re-evaluated on a variation change (the native path's only triggers were field /
quantity changes and, when a totals node existed, `found_variation`/`reset_data`).

WAPF 3.1.5 resolves this in `A()` (`assets/js/frontend.min.js`): a matching rule
wins, otherwise `C( getVariation() ? variation.image_id : original )` — the
selected variation image, then the original — and WAPF rebinds the decision to
the `.variation_id` change (`u.on("change", function(){ A("rules", g, u) })`), so
a still-matching rule survives a variation switch. OPF's WAPF-shaped
`data-opf-gi` path already mirrored this (`variationImageProps()`); only the
OPF-native model the builder edits was missing it.

### Fix (`assets/js/opf-frontend.js`, +73/−15)

1. New `selectedVariationImage( doc )` (line 2904) reads the selected
   `variation_id` plus the `data-product_variations` payload and returns the
   variation's image props (`full_src || src`, `srcset`, `sizes`, `alt`, `title`),
   or `null`. It is fully guarded so the existing `updateProductImage` unit
   fixtures (whose `doc` mock has no `form.querySelector`) keep working.
2. `updateProductImage()` — the no-target branch (line 3022) now resolves the
   selected variation image first: navigate to its gallery slide when the
   variation image is part of the gallery, else paint it onto the active main
   image; only with no selected variation (or a variation without an image) does
   it restore the original slide. The existing rule-target mutation was factored
   into `applyMainImage()` (line 3000) so both branches share one attribute
   writer.
3. `initTotals()` (line 3536) now re-runs the totals/image pass on the
   **deferred** `opf:variation-changed` signal `init()` already emits *after*
   WooCommerce has written `.variation_id` and its variant gallery image. The
   previous handlers only re-ran when a totals node existed, and the synchronous
   `found_variation.opf` handler ran before WooCommerce's own image update.

`assets/js/opf-frontend.min.js` is **not** rebuilt: `Assets::register_frontend()`
serves the unminified `assets/js/opf-frontend.js` (`?ver=0.1.0`), verified on the
live page; the `.min` file is unused.

### After the fix

Every cell of the table above now matches WAPF exactly, for **both** `rules` and
`last` modes (probe and harness logs below).

## Run (a): stock WooCommerce flexslider gallery

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce

wp --path=/tmp/opf-image-change-var-wp eval-file bin/e2e-image-change-variable-fixture.php prepare

OPF_ICV_STATE=docs/compatibility/image-change-variable-20261006/fixture-state.json \
OPF_ICV_VARIANT=plain \
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
  node bin/e2e-image-change-variable-browser.mjs
```

```text
56/56 checks passed (variant=plain)
```

One Chromium run covers all four products, 14 checks each: initial original
image; matching rule swaps the main image; selecting a variation shows its own
image; a rule wins over the variation image and clearing it restores the
variation image; no variation + no rule shows the original; a still-matching rule
survives a variation switch and still wins after the next one; the two matching
rules resolve per mode (`rules` → later rule `rule-b`, `last` → last-changed
field `rule-a`); deselect-all restores the original image, link and thumbnail;
no uncaught browser errors.

## Run (b): free wp.org gallery plugin

Installed from wp.org and activated in the clone:

**`woo-product-gallery-slider` 2.3.25** — *"Product Gallery Slider, Additional
Variation Images for WooCommerce"* (a Slick-based replacement for
`woocommerce_show_product_images`; the clone's gallery root becomes
`woocommerce-product-gallery images wpgs-wrapper`, `.wpgs-for` main slider, no
flexslider).

```sh
wp --path=/tmp/opf-image-change-var-wp plugin activate woo-product-gallery-slider

OPF_ICV_STATE=docs/compatibility/image-change-variable-20261006/fixture-state.json \
OPF_ICV_VARIANT=woo-product-gallery-slider \
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
  node bin/e2e-image-change-variable-browser.mjs
```

```text
49/56 checks passed (variant=woo-product-gallery-slider)
```

Every core behaviour still holds on the non-default gallery for both engines:
matching rule → `rule-a`; selecting a variation → its own image; clearing the
rule → back to the variation image; no variation + no rule → the original;
deselect-all → original image, link and thumbnail.

The 7 residual failures are **not** OPF image-resolution defects:

- `rule-b survives a variation switch` / `rule-b still wins after the next
  variation switch` — `opf/rules`, `wapf/rules` and `wapf/last` (6 checks). The
  plugin's own variation-image handler re-applies the variation image *after* the
  engines; **WAPF 3.1.5 fails the same checks**, so OPF is at parity, not behind.
- `two matching rules resolve per rules mode` — `wapf/rules` only. Here OPF is
  *better* than the reference: OPF keeps the later rule (`rule-b`) while WAPF
  shows the variation image. Once a variation image is on screen, WOOBE's handler
  competes with both engines.

An earlier WOOBE run also surfaced a page error
`Cannot read properties of null (reading 'add')` whose stack frame is
`e.initADA` in the plugin's own
`woo-product-gallery-slider/assets/js/slick.min.js` — i.e. WOOBE's Slick
re-initialisation on gallery mutation, not OPF. It is intermittent; it did not
reproduce in the final run.

## Suites

```sh
vendor/bin/phpunit --no-coverage
# OK, but there were issues!
# Tests: 1086, Assertions: 4590, PHPUnit Deprecations: 2.   (both pre-existing)

node --test tests/js/*.cjs
# tests 130 / pass 130 / fail 0 / cancelled 0 / skipped 0 / todo 0
```

`node --test` includes `tests/js/opf-product-image.test.cjs`, whose two cases pin
the original-image restoration path the fix extends; both still pass.

## Artifacts

All under `docs/compatibility/image-change-variable-20261006/`:

```text
fixture-state.json                        fixture ids + every authored URL
browser-plain.json                        56 checks, all pass (both engines)
browser-woo-product-gallery-slider.json   56 checks, 49 pass, 7 attributed to WOOBE
browser-plain-{opf,wapf}-{rules,last}.png            full-page screenshots, plain gallery
browser-woo-product-gallery-slider-{opf,wapf}-{rules,last}.png   same, WOOBE active
```

## Residuals / open items

- **AJAX-loaded variation sets.** `selectedVariationImage()` reads the standard
  `data-product_variations` attribute (what WooCommerce renders by default). The
  WAPF-shaped path additionally reads jQuery `form.cart` data for AJAX variation
  sets; the OPF-native fallback does not. Not exercised here.
- **Non-flexslider galleries that re-render asynchronously.** A plugin that
  rewrites the gallery DOM after the engines (as WOOBE does for variation
  changes) can discard the engine's image. Reproduced identically on WAPF 3.1.5,
  so it is a plugin-interaction limit, not an OPF gap; no adapter was added.
- **OPF is not compared against WAPF Extended 3.2.1** (not on this host) and the
  helper does not cover WooCommerce's `gallery_images_html` variation-gallery
  markup.

## Status

The variable-product lifecycle residual of `WAPF-INTERACTION-IMAGE-CHANGE` and
the non-default-gallery-plugin residual are both exercised, and a real OPF
defect on variable products is fixed and proven. The row itself is the
orchestrator's to re-grade.
