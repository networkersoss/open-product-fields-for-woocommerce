# Image-change rules mode: runtime finding — 2026-10-05

## Scope

The `WAPF-INTERACTION-IMAGE-CHANGE` row claims that OPF imports/exports WAPF
group `layout` gallery settings, that "OPF source and focused tests cover
rules/last matching", and that an imported-`last` browser proof exists
([last evidence](WAPF-IMAGE-CHANGE-LAST-EVIDENCE-2026-10-04.md)). This run
re-executes the last-mode proof, executes the WordPress-side fixture that
prepares a rules configuration, and tries the matching rules-mode browser
harness. The harness cannot pass, and the cause is a source gap, not a fixture
or environment problem. The row therefore stays **partial**, with the rules-mode
runtime path now named explicitly.

## Environment

- Date: 2026-10-05. Source commit `a3c6177` (`master`).
- Runtime: disposable WordPress `/home/followersya-5hqi7/opf-test/wordpress` —
  WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite, theme
  `twentytwentyfive`, plugin copy synced from the source checkout and verified
  identical with `diff -rq --exclude=.git --exclude=node_modules` (exit 0),
  plugin active (`0.1.0`). No WAPF/WAPF Extended install.
- Storefront server: `wp server --host=127.0.0.1 --port=8090`, killed after the run.

## What passed

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
W="wp --path=/home/followersya-5hqi7/opf-test/wordpress"

node bin/e2e-image-change-last-browser-test.mjs
# ok fixture carries imported WAPF last mode and two source-shaped rules
# ok initial first-field match displays the material image
# ok latest changed finish field takes precedence over prior material match
# ok unmatched latest field restores original main image
# ok restore also returns image link and thumbnail to original URLs
# ok later material change replaces prior finish image
# ok no uncaught browser errors                       (exit 0, 7/7 checks)

$W eval-file bin/e2e-image-change-test.php prepare
# IMAGE_CHANGE_PRODUCT=15082 / IMAGE_CHANGE_GROUP=15086
# IMAGE_CHANGE_URL=http://opf.test/product/opf-e2e-conditional-image-product/
# IMAGE_CHANGE_INITIAL=.../opf-image-change-front.png
# IMAGE_CHANGE_GALLERY=.../opf-image-change-back.png,.../opf-image-change-side.png
# IMAGE_CHANGE_EXTERNAL=.../opf-image-change-external.png
# Success: Conditional image fixture prepared.        (exit 0)
```

`prepare` fails closed: it exits 0 only after asserting that the group
round-tripped with exactly 3 image rules and that the product kept 2 gallery
images, on a live WooCommerce product. Last mode stays proven (7/7, isolated
loopback fixture that loads this checkout's real frontend module but does not
boot WordPress).

## What failed

```sh
OPF_BASE_URL=http://127.0.0.1:8090 \
OPF_IMAGE_CHANGE_URL="http://127.0.0.1:8090/product/opf-e2e-conditional-image-product/" \
OPF_IMAGE_CHANGE_EXTERNAL="http://127.0.0.1:8090/wp-content/uploads/opf-image-change-external.png" \
  node bin/e2e-image-change-browser-test.mjs
# page.waitForFunction: Timeout 30000ms exceeded
#   at .../bin/e2e-image-change-browser-test.mjs:26:12   (exit 1)
```

(One earlier run of the same harness failed instead at line 23 with
`page.goto: Timeout 30000ms exceeded`; that was not reproducible — the same URL
reached `domcontentloaded` in 308 ms immediately afterwards, so it was
first-hit latency of the single-threaded PHP development server, not a page
defect.)

## Root cause (source finding, not fixed here)

`bin/e2e-image-change-browser-test.mjs:26` waits for
`window.OPF_IMAGE_RULES` to hold a group with 3 rules. Nothing assigns that
global:

- `grep -rn "OPF_IMAGE_RULES"` over the repository returns exactly two hits:
  the read at `assets/js/opf-frontend.js:3148` (and its duplicate in the
  minified bundle) and the harness itself. There is no PHP emitter.
- `git log -S "OPF_IMAGE_RULES"` shows the read was introduced by `b363989`
  ("feat(presentation): port old-branch field presentation options"), whose
  diff contains only `+` lines for the read and no assignment anywhere.
- The renderer emits gallery payloads only for the WAPF-shaped
  `layout.enable_gallery_images` / `layout.gallery_images` model
  (`includes/Service/Renderer.php:536-574` →
  `data-opf-gi`/`data-wapf-gi`), consumed by the DOM path
  (`assets/js/opf-frontend.js:~850-1000`).

A read-only browser probe of the prepared fixture page confirms the
consequence:

```text
window.OPF_IMAGE_RULES typeof: undefined
window.OPF_IMAGE_RULE_MODES typeof: undefined
groups ([data-opf-gi] on the page): []
gallery slides: 3, active: 0
after color=red + size=large: active 0
after red + size=small: src .../opf-image-change-front.png (unchanged)
after color=blue: active 0 ; after color=green: active 0
page errors: []
```

So the OPF-native group `image_rules` model that `bin/e2e-image-change-test.php`
writes and the builder edits (`assets/js/opf-builder.js`) has no storefront
consumer: the only frontend reader of `image_rules` builds
`productImageEvaluations[].rules` from `window.OPF_IMAGE_RULES`, which is never
published, so `updateProductImage()` always sees `rules: []` and falls back to
the legacy per-field `change_product_image` choice path. The gallery swapping
that *is* wired and proven runs on the WAPF-shaped `layout.gallery_images`
payload. This is reported, not repaired: emitting the global (or re-pointing
the fixture/harness at the layout shape) is a source decision outside this
lane.

## Cleanup

```sh
$W eval-file bin/e2e-image-change-test.php cleanup
# Success: Conditional image E2E fixtures removed.    (exit 0)
```

Post-run audit: fixture attachments (`_opf_image_change_fixture`) = 0, fixture
upload files (`uploads/opf-*`) = 0, `recent_orders=0`,
`fixture_orders_leaked=0`; disposable site URL, theme, plugin list and active
plugins unchanged.

## Status

`WAPF-INTERACTION-IMAGE-CHANGE` remains **partial**. Unchanged residuals:
WAPF Extended 3.2.1 behavior, variation/gallery-plugin lifecycle, and the fact
that the last-mode fixture serves controlled markup instead of booting
WooCommerce. Newly named residual: the OPF-native rule editor's rules never
reach the storefront (missing `window.OPF_IMAGE_RULES` emitter), so rules-mode
gallery swapping is unproven and the paired rules-mode fixture/harness cannot
pass.
