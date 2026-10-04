# Cards + image-zoom cluster — evidence (draft)

Lane `lane/cards`, worktree `/tmp/opf-lane-cards`, disposable clone
`/tmp/opf-image-cards-wp` (`http://127.0.0.1:8308`). WAPF Extended 3.1.5 is the
inactive reference. No commits were made; the integrator merges.

## Rows

| Row | Outcome |
| --- | --- |
| `WAPF-FIELD-CARDS-QUANTITY-CONDITIONALS` | **fixed+proven** — evaluator + browser treat quantity-selector subjects as positive-quantity maps; `empty`/`!empty` match WAPF 3.1.5's `products-card-qty`/`products-vcard-qty` registry (only those two operators). Builder now offers exactly "No quantity"/"Any quantity" for qty-card subjects. 3.1.6's unpublished extra options documented as a gap. |
| `WAPF-FIELD-CARDS-MAIN-IMAGE` | **fixed+proven** — group-level `layout.enable_gallery_images`/`swap_type`/`gallery_images` normalized, rendered as `data-wapf-st`/`data-wapf-gi` (+ `data-opf-*` aliases), and driven by a WAPF-faithful frontend engine (rules reversed, `*` wildcard, hidden subject fails, `last` mode, variation/original fallback, attribute-set restore). Field-level `data-opf-swap-image` fallback retained. Browser proof: rule image, unmatched field swap, restore. |
| `WAPF-FIELD-CARDS` | **partial → stronger partial** — card/card-qty render + zoom attributes + qty inputs + conditionals proven end-to-end (cart + real order). Residual: no builder UI for group gallery rules; WAPF import of `layout.gallery_images` still needs `WapfMapper`. |
| `WAPF-FIELD-IMAGE-QUANTITY-ZOOM` | **partial** — public branch includes `image_zoom`, builder toggle, renderer preview, and `large_image` export. As of 2026-10-04, `WapfMapper` also imports nested `options.large_image`; real WordPress admin reload and served cart/checkout/order lifecycle proof remain pending. See [current evidence](IMAGE-QUANTITY-ZOOM-EVIDENCE-2026-10-04.md). |
| `WAPF-FIELD-SWATCH-IMAGE-ZOOM` | **proven** — `data-zoom-url` on the image-swatch wrapper, `wapf-tt-wrap`, hover/focus enlargement verified. |

## Commands + numbers

```
# Unit
vendor/bin/phpunit
=> OK, Tests: 559, Assertions: 2286 (537 baseline + 13 CardsConditionalsSchemaTest + 5 CardsZoomMarkupTest + 4 CardsZoomExportTest)

# JS unit
node tests/js/opf-qty-card-conditionals.test.cjs  => passed (15)
node tests/js/opf-conditional.test.cjs            => passed (2)
node --check assets/js/opf-builder.js assets/js/opf-frontend.js => OK

# Guarded fixture (clone only)
OPF_CARDS_ZOOM_ALLOW=1 OPF_CARDS_ZOOM_PHASE=setup   wp ... eval-file bin/e2e-cards-zoom-fixture.php  => 13 ok
OPF_CARDS_ZOOM_ALLOW=1 OPF_CARDS_ZOOM_PHASE=verify  ... => 8 ok (order 44.0 with 3 lines)
OPF_CARDS_ZOOM_ALLOW=1 OPF_CARDS_ZOOM_PHASE=cleanup ... => post+user counts restored to baseline

# Browser (Playwright/Chromium, cwd /tmp/opf-url-native-parity)
OPF_CARDS_BASE_URL=http://127.0.0.1:8308 node bin/e2e-cards-zoom-browser-test.mjs => 24/24 checks, 0 page errors
```

## Evidence artifacts (`/tmp/opf-lane-cards-evidence`)

- `cards-browser-results.json` — 24 checks, 0 storefront errors
- `cards-conditionals.png`, `cards-zoom-hover.png`, `cards-swap-rule.png`, `cards-restore.png`
- `cards-cart.json` — parent + two child lines, total 44
- `cards-order-verify.json` — persisted order line/total assertions
- `cards-baseline-check.json` — pre/post post-count + user-count equality
- `cards-wapf-markup-comparison.json`, `cards-opf-markup.json` — WAPF source-fact ↔ OPF rendered markup
- `cards-zoom-state.json`, `page.html` — fixture state / rendered page snapshot

## WAPF markup parity (source facts)

- `class-html.php:635-639` — `data-zoom-url` from `large_image`; OPF emits it on
  image-quantity and image-swatch wrappers.
- `class-html.php:613-617` — `wapf-tt-wrap` for large image/tooltip; OPF emits it.
- `class-html.php:547-590` — qty input `input-<id>` / `input-<id>_<slug>`; OPF
  emits `opf-input opf-qty is-qty input-units input-units_child-alpha`.
- `field-group.php:19-27` + `class-fieldgroup.php:121-158` — `data-wapf-st` /
  `data-wapf-gi` `{images,rules}`; OPF emits the same payload.
- `class-linked-products-controller.php:1112-1124` — card-qty conditions are
  only `empty`/`!empty`; OPF matches.

## Documented differences / gaps

1. Zoom presentation: WAPF uses a tooltip surface; OPF uses an inline
   absolutely-positioned `.opf-swatch-zoom-preview`. Same `data-zoom-url`
   contract and hover/focus behavior.
2. Gallery matching for a qty-selector subject: WAPF's gallery matcher reads the
   first `.input-<field>` value; OPF treats it as a positive-quantity list (its
   visibility engine already does). OPF superset.
3. Not implemented: group-level gallery **builder UI** and WAPF
   `layout.gallery_images` import mapping.
4. Shared/integrator files:
   - `includes/Service/WapfExporter.php` was edited by this lane (not in the
     do-not-edit list): allowed the normalized `layout` key on a group, exported
     `enable_gallery_images`/`swap_type`/`gallery_images` in the WAPF layout
     block, and emitted `large_image` for `image-swatch-qty` (image-quantity
     zoom). Without the `layout` allowance, exporting a gallery-enabled group
     threw.
   - `includes/Engine/WapfMapper.php` imports image-quantity
     `options.large_image` as `image_zoom`; imported image attachments still
     need destination-site remapping when IDs differ.
5. 3.1.6 quantity-card conditional options remain unverified (keys unpublished).

## Cleanup

Clone restored: 7 pre-existing `opf_field_group` posts, product/page counts at
baseline, 1 attachment, WAPF plugin inactive, OPF active, options restored
(`woocommerce_checkout_page_id`, `woocommerce_bacs_settings`), fixture option
`opf_cards_zoom_state` deleted. `cards-baseline-check.json` records the equality.
