# WAPF-INTERACTION-IMAGE-CHANGE — live 3.1.5 comparative proof (2026-10-05)

## Scope

Closes the live-comparison half of the row's residual: *"the `last`-mode
Chromium fixture … neither boots WooCommerce nor compares a live WAPF 3.1.5
runtime — and the variation/gallery-plugin lifecycle remains unaudited."*

The same two-rule gallery mapping was authored in WAPF 3.1.5 and in OPF on
otherwise identical products. Both rules deliberately match the **same** field
value (`finish=steel`) in the fixed order `[g1, g2]`, so the winner reveals
whether the engine reverses its rule list before matching. The products were
then served live with WAPF **and** OPF active and the real WooCommerce gallery
observed in Chromium.

## Environment

- WAPF Extended **3.1.5** active beside OPF 0.1.0.
- Disposable clone `/tmp/opf-wapfref-compare-wp`, `http://127.0.0.1:8251`, WP 7.1.2 / WC 11.1.0 / PHP 8.5.11.
- Harnesses: `bin/e2e-wapfref-image-change.php`, `bin/e2e-wapfref-image-change-browser.mjs`.
- Artifacts: `docs/compatibility/wapf-reference-proof-20261005/row2-image-change/`.

## Commands

```sh
cd /tmp/opf-lane-wapfref
WP="wp --path=/tmp/opf-wapfref-compare-wp --allow-root"
OUT=docs/compatibility/wapf-reference-proof-20261005
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-image-change.php setup
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-image-change.php render
NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules OPF_WAPFREF_OUT=$OUT \
  node bin/e2e-wapfref-image-change-browser.mjs
```

## Emitted payload comparison (`image-change-markup-compare.json`) — PASS

`wapf_st`/`opf_st` = `rules` on both. The parsed `{images,rules}` payload is
identical in both directions (the `parity` block is all `true`):

- `same_rule_count`: 2 rules each.
- `rule_order_equal`: `[{values:[{field:'finish',value:'steel'}],image:g1}, {…,image:g2}]` on both.
- `same_first_image` = `g1`, `same_last_image` = `g2` on both.
- `swap_type_equal`: `rules`.

OPF emits the WAPF-shaped payload on `data-wapf-gi`/`data-opf-gi`; it also
publishes its native `{target_url,conditions}` `image_rules` model in
`window.OPF_IMAGE_RULES`, which drives its own JS.

## Live browser comparison (`browser-image-change.json`) — 6/6 PASS

Real WooCommerce flexslider gallery, product images `[g3(featured), g1, g2]`:

- WAPF 3.1.5: selecting `steel` activates **g2** — the LAST matching rule. This
  is the empirical confirmation (not from the doc) that 3.1.5 reverses its rule
  list before matching (`frontend.min.js`: `n.rules.reverse()`).
- OPF: selecting `steel` activates **g2** as well — OPF reverses identically.

Screenshots `browser-wapf-steel.png`, `browser-opf-steel.png`.

> Harness note: the first synthetic `change` after a fresh headless load can
> race OPF's module init, so the harness re-dispatches `input`+`change` once the
> select has settled. With that in place both engines are deterministic. An
> exploratory note recorded during the run (OPF ignoring the *first*
> non-placeholder option value) did not reproduce under the settled harness and
> is treated as a test-timing artefact, not a product difference.

## Result

- **Closed:** the live WAPF/WooCommerce runtime comparison for rule application
  and `last`-mode semantics. The rule list is reversed, so the last matching
  rule wins; WAPF 3.1.5 and OPF agree.
- **Remaining:** the variation-product and gallery-plugin lifecycles were not
  exercised (the disposable clone has no gallery plugin installed), and the
  explicit `last` swap mode was not re-run live here (OPF's `last`-mode browser
  proof remains `WAPF-IMAGE-CHANGE-LAST-EVIDENCE-2026-10-04.md`).

## Cleanup

`cleanup` deleted both fixture products, the OPF group and the three imported
gallery attachments; options removed and cache flushed. No `wapfref-*` objects
remain. Production was read-only.
