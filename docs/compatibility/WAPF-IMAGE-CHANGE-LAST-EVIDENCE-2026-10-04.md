# WAPF image-change `last` evidence — 2026-10-04

## Scope

This closes the missing imported-`last` browser proof for the partial
`WAPF-INTERACTION-IMAGE-CHANGE` row. The reference source is the locally
installed WAPF Extended 3.1.5 package at
`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`.
The test serves a minimal product/gallery storefront over loopback HTTP and
loads this checkout's real `assets/js/opf-frontend.js` in Chromium. It does not
boot WordPress/WooCommerce, compare against WAPF's live runtime, or exercise a
full product variation lifecycle.

## WAPF Extended 3.1.5 source behavior

In `assets/js/frontend.min.js`, `function A(e,t,a)` delegates matching to an
ordered loop over the passed rules. The group initialization reverses each
group's rule list before saving it (`n.rules.reverse()`); on every `.wapf
input` change the callback records the changed input and calls `A(mode,
rules, changedInput)`. It also seeds evaluation from the first visible input.
The matcher ignores `*` values, rejects hidden field containers, compares the
live field value, and in `last` mode rejects a non-wildcard condition whose
field ID differs from `changedInput.data("fieldId")`. The first matching rule
switches to its image. If no rule matches, WAPF resolves the selected
variation image or its captured original image. This is the 3.1.5 source
behavior, not a claim about 3.2.1.

OPF's migration schema preserves the WAPF `layout.enable_gallery_images`,
`layout.swap_type`, and `layout.gallery_images` shape, including each
`{field,value}` condition (`includes/Engine/FieldGroup.php:202-215,273-315`).
Existing renderer/exporter unit tests assert `last` is retained and the
gallery payload is emitted as WAPF-shaped data attributes.

## Chromium proof

Command, from the repository/worktree root (with Playwright available through
`NODE_PATH` where it is not installed in the worktree):

```sh
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules node bin/e2e-image-change-last-browser-test.mjs
```

Result: **7/7 checks passed**, no uncaught browser errors. The fixture confirmed:

1. imported WAPF-shaped `last` mode and two image rules are present;
2. initial first-field match displays the material image;
3. changing the finish field makes its match take precedence;
4. changing the latest field to an unmatched value restores the original main image;
5. restoration returns the gallery link and thumbnail URLs too;
6. a subsequent material-field change switches back to that field's match;
7. no uncaught browser errors.

The existing `rules`-mode disposable-clone browser coverage is separate. This
fixture is a real Chromium run over local HTTP using OPF's production frontend
module, but its WooCommerce gallery DOM is a controlled fixture; it is not
evidence of WAPF-vs-OPF browser equivalence or a full WooCommerce lifecycle.

## Status

The missing imported-`last` interaction proof is now covered for the
source-backed 3.1.5 semantics. Keep the capability row **partial**: current
WAPF Extended 3.2.1 behavior remains unaudited, and the test does not compare
the WAPF runtime in a browser or cover variation/gallery plugin lifecycle.
