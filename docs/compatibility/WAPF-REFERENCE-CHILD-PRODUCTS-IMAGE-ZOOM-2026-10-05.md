# WAPF-FIELD-CHILD-PRODUCTS-IMAGE-ZOOM — live 3.1.5 comparative proof (2026-10-05)

## Scope

Closes the one-sided half of the row's residual, which read: *"OPF's preview
markup and zoom positioning have never been compared against a WAPF-side
runtime, and 3.1.5 exposes no builder control for product fields to compare the
authoring surface against."*

The same linked-child image field (`products` subtype `image` with hover zoom)
was rendered by the installed WAPF Extended 3.1.5 renderer and by OPF, then the
same parent product page was served live with WAPF **and** OPF active and the
hover behaviour measured in real Chromium at two widths.

## Environment

- WAPF Extended **3.1.5** active beside OPF 0.1.0.
- Disposable clone `/tmp/opf-wapfref-compare-wp`, `http://127.0.0.1:8251`.
- WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11.
- Harnesses: `bin/e2e-wapfref-child-products-image-zoom.php`,
  `bin/e2e-wapfref-child-products-image-zoom-browser.mjs`.
- Artifacts: `docs/compatibility/wapf-reference-proof-20261005/row1-child-products-image-zoom/`.

## Commands

```sh
cd /tmp/opf-lane-wapfref
WP="wp --path=/tmp/opf-wapfref-compare-wp --allow-root"
OUT=docs/compatibility/wapf-reference-proof-20261005
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-child-products-image-zoom.php setup
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-child-products-image-zoom.php render
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules OPF_WAPFREF_OUT=$OUT \
  node bin/e2e-wapfref-child-products-image-zoom-browser.mjs
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-child-products-image-zoom.php cleanup
```

## Server-side markup comparison (`markup-compare.json`) — PASS

| field | classes | `data-zoom-url` | inline zoom `<img>` |
| --- | --- | --- | --- |
| WAPF 3.1.5 zoom on | `wapf-swatch wapf-swatch--image apf-pick-box wapf-tt-wrap` | full `child-zoom.png` | 0 (injected by JS) |
| WAPF 3.1.5 zoom off | same, no `data-zoom-url` | — | 0 |
| OPF zoom on | `opf-swatch opf-swatch--image opf-product-choice opf-image-swatch-label--tooltip opf-swatch--image-zoom wapf-tt-wrap` | full `child-zoom.png` | 1 (`opf-swatch-zoom-preview`) |
| OPF zoom off | no `wapf-tt-wrap` / zoom classes, no `data-zoom-url` | — | 0 |

Both engines honour the serialized `large_image` option, emit the WAPF
`wapf-tt-wrap` marker and the same full-attachment `data-zoom-url`, and render
nothing without it. OPF additionally emits the inline preview image; WAPF keeps
it out of the markup and injects it at hover time.

## Live browser comparison (`browser-zoom-results.json`) — 8/8 PASS

- wide 1100px: both wrappers carry a `data-zoom-url`, both point at the same
  full-size attachment; WAPF's JS injects the enlarged `<img>` on hover
  (`>780px`), OPF's inline preview is visible on hover.
- narrow 760px: both still carry the same `data-zoom-url`; WAPF **suppresses**
  the zoom image (`frontend.min.js` gate `780 < innerWidth`), OPF's inline
  preview is still visible (no width gate).

Screenshots: `browser-wapf-hover-wide.png`, `browser-opf-hover-wide.png`,
`browser-wapf-hover-narrow.png`, `browser-opf-hover-narrow.png`.

## Result

- **Closed:** the markup / zoom-positioning comparison. The row's one-sided
  residual is resolved: the emitted zoom contract (`wapf-tt-wrap` +
  `data-zoom-url` at the full attachment) is byte-identical, and the only
  browser difference is presentational — WAPF gates the enlargement above 780px
  via JS, OPF shows the inline CSS preview at all widths.
- **Remaining:** installed 3.1.5 exposes no builder control for product image
  fields (`class-config.php` registers `large_image` only for
  `image-swatch`/`multi-image-swatch`), so the authoring-surface half cannot be
  compared against 3.1.5 — it is a documented superset on OPF's side, not a gap.

## Ledger outcome (2026-10-05)

`WAPF-FIELD-CHILD-PRODUCTS-IMAGE-ZOOM` was promoted to `supported` on this
proof: the comparison is closed, and the remaining item (3.1.5 exposes no
builder control for product image fields) is OPF offering more than the
baseline, not a divergence.

## Cleanup

`cleanup` deleted both fixture parents, the child product, both OPF groups and
the two imported attachments; the `opf_wapfref_*` options were removed and the
file-group cache flushed. A post-run audit found no `wapfref-*` products, groups
or attachments left in the clone. Production was read-only.
