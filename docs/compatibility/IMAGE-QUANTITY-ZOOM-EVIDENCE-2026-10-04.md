# Image+quantity zoom evidence — 2026-10-04

## WAPF Extended 3.1.5 source

Checked installed plugin header: version `3.1.5`. In `includes/classes/class-config.php`, the `image-swatch-qty` field's appearance settings include `large_image` (“Show large image”). In `includes/classes/class-html.php`:

- Lines 613–617 read `field->options['large_image']`, add `wapf-tt-wrap` to the choice wrapper, and use the tooltip presentation.
- Lines 635–639 look up the attachment's `full` image and set `data-zoom-url` on the wrapper.

Thus the canonical WAPF import path is nested `options.large_image`; `image` is the choice thumbnail and the full attachment is the zoom target.

## OPF behavior

- `FieldGroup` normalizes `image_zoom`, `large_image`, and `options.large_image` to canonical `image_zoom` for image-quantity fields.
- The builder exposes “Enlarge image on hover and keyboard focus”.
- The renderer uses the choice attachment's full-size URL for `data-zoom-url` and an `aria-hidden` preview while retaining the choice's alt text and number input.
- CSS opens the preview on hover or `:focus-within`; the preview is capped at `min(220px, 70vw)` and `80vw` max width.
- WAPF Tools export writes `large_image`. This patch also maps imported `options.large_image` and removes the incorrect migration warning that claimed zoom was lost.
- Zoom is presentation-only. The quantity pricing regression compares the same choice and cart value with zoom disabled and enabled.

## Verification run

All commands ran from the isolated `fix/image-quantity-zoom` worktree at public base `0a5d385`:

```text
vendor/bin/phpunit tests/Unit/WapfMapperTest.php --filter maps_wapf_extended_image_quantity_swatches_and_bounds_with_review_notes
OK (1 test, 15 assertions)

vendor/bin/phpunit tests/Unit/CardsZoomExportTest.php
OK (5 tests, 14 assertions)

vendor/bin/phpunit tests/Unit/CardsZoomMarkupTest.php
OK (5 tests, 18 assertions)

vendor/bin/phpunit tests/Unit/CardsConditionalsSchemaTest.php
OK (13 tests, 29 assertions)

vendor/bin/phpunit tests/Unit/CalculatorTest.php --filter image_quantity_pricing_and_sumqty_use_tagged_choice_quantities
OK (1 test, 8 assertions)

NODE_PATH=<installed Playwright module path> node bin/e2e-image-quantity-zoom-builder-test.mjs
ok — builder toggle saves image_zoom=true and leaves fixed price + quantity bounds intact

NODE_PATH=<installed Playwright module path> node bin/e2e-image-swatch-browser-test.mjs
ok — image-quantity preview opens on hover/focus; full-image decorative alt behavior,
responsive swatch columns, small-screen preview sizing, reduced motion, and no page errors
```

The browser checks exercise builder JavaScript with a mocked REST save and the production CSS against DOM fixtures. PHP markup tests exercise `Renderer::render_group`; importer/exporter tests exercise WAPF Tools data. No production site was changed.

## Remaining proof before marking supported

The dedicated WordPress admin save/reload and served product-page browser + cart/checkout/order lifecycle fixture was not run. Keep this row **partial** until that isolated runtime proof passes. Existing fixture source is in `bin/e2e-cards-zoom-fixture.php` and `bin/e2e-cards-zoom-browser-test.mjs`; its persistence assertion verifies `image_zoom` after `FieldGroups::save()` followed by `group_from_post()`, and its cart/checkout flow is available for the follow-up runtime proof.
