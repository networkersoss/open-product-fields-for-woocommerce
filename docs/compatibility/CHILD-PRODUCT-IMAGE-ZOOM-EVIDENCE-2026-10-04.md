# Linked-product image zoom evidence — 2026-10-04

## Reference contract

The [official Extended changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/)
lists 3.2.1 as adding a setting to enlarge images on hover for the Products
field when it is displayed as images. It does not publish the setting key,
default, markup contract, zoom positioning, or keyboard behavior.

The installed Extended 3.1.5 renderer checks
`$field->options['large_image']` and, when enabled, obtains the attachment's
full image URL and emits it as `data-zoom-url` in
`includes/classes/class-html.php` around lines 613–639. Its
`includes/classes/class-config.php` `products-image` registry does not expose
that setting to the builder. This is useful historical source evidence; it
does not prove the licensed Extended 3.2.1 implementation. The 3.2.1 archive
is unavailable in the audited installation.

## OPF implementation

OPF stores the author setting as `large_image` on `products` fields with the
`image` subtype. Missing values normalize to `false`. WAPF Tools import reads
`options.large_image`; export writes the flattened `large_image` option. The
existing OPF `image_zoom` setting remains the separate main-gallery swap
behavior and does not turn on the child-image preview by itself.

When enabled, the image choice wrapper carries the full attachment's
`data-zoom-url` and an inline preview image. CSS shows the preview on pointer
hover or when a keyboard-focused child input is within the wrapper. The preview
is decorative (`alt=""`, `aria-hidden="true"`); the native checkbox remains
labelled by the product name.

## Verification

- Focused PHPUnit: `LinkedProductsSchemaTest` 18 tests / 51 assertions;
  `WapfMapperTest` 67 / 323; `WapfExporterTest` 42 / 218. These cover default
  off, boolean validation, separation from gallery swapping, WAPF import, and
  WAPF Tools export.
- Full PHPUnit suite: 761 tests / 3,376 assertions passed; one existing
  PHPUnit metadata deprecation remains.
- JavaScript suite: 74 tests passed. PHP and JavaScript syntax checks plus
  `git diff --check` passed.
- A fixture field was saved through `FieldGroups::save()` and reloaded through
  `group_from_post()`. The stored result retained `large_image=true` and
  `image_zoom=false` independently.
- Disposable WordPress/WooCommerce clone: loopback `127.0.0.1:8471`, copied
  from `/tmp/opf-child-builder-save-reload-wp`; its OPF plugin path pointed to
  the isolated candidate worktree. Chromium passed 30/30 checks. For linked
  images specifically it verified: zoom URLs render without gallery-swap
  attributes; the preview starts hidden; hover reveals it; keyboard focus
  keeps it visible; the preview URL uses the full image rather than the
  thumbnail; decorative markup and the checkbox accessible name are present.
  The surrounding cards/zoom scenario also completed classic add-to-cart and
  checkout with no page errors.
- Fixture verification found the expected parent and child order lines, saved
  values, and $44 total. Fixture cleanup removed its products, attachments,
  field group, checkout page, and order; post counts, user count, checkout and
  payment settings returned to the pre-fixture snapshot. Eleven pre-existing
  published field groups were temporarily drafted only in the disposable copy
  to isolate the product-page/cart scenario, then restored before fixture
  verification and cleanup.

## Parity boundary

Keep the capability row `partial`. The published release note supports the
hover-enlarge behavior, and OPF now authors, imports, exports, persists, and
renders it. Exact 3.2.1 option shape/default and visual/keyboard behavior remain
unverified without that package. OPF's preview placement and implementation
also have not been compared against a 3.2.1 runtime.
