# Switch control runtime re-proof — 2026-10-05

## Scope

This is a re-run of the `WAPF-FIELD-TRUE-FALSE-SWITCH` storefront and
cart/order proofs at the current source commit on a fresh disposable WordPress.
It closes no residual: the 2026-10-03/04 evidence already claimed the same
browser and WP-CLI lifecycle results. What it adds is a dated re-proof at
`a3c6177` with no WAPF package present at all (the earlier run used a clone
carrying WAPF Free 1.7.1), and a reproducible environment block.

The WAPF Pro serialized `switch_control` setting and a visual runtime
comparison remain unavailable: the disposable runtime has no WAPF/WAPF
Extended install, so importer mapping is still not claimed.

## Environment

- Date: 2026-10-05. Source commit `a3c6177` (`master`).
- Runtime: disposable WordPress `/home/followersya-5hqi7/opf-test/wordpress` —
  WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite, theme
  `twentytwentyfive`, plugin copy synced from the source checkout and verified
  identical with `diff -rq --exclude=.git --exclude=node_modules` (exit 0),
  plugin active (`0.1.0`).
- Storefront server: `wp --path=/home/followersya-5hqi7/opf-test/wordpress server --host=127.0.0.1 --port=8090` (PHP 8.5.11 development server), killed after the run.

## Verification

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
W="wp --path=/home/followersya-5hqi7/opf-test/wordpress"

$W eval-file bin/e2e-switch-control-test.php prepare
# SWITCH_CONTROL_URL=http://opf.test/product/opf-e2e-switch-control-product/
# SWITCH_CONTROL_IDS={"product_id":15074,"group_ids":[15075]}
# Success: Switch-control fixture prepared.        (exit 0)

node bin/e2e-switch-control-browser-test.mjs \
  "http://127.0.0.1:8090/product/opf-e2e-switch-control-product/"
# {"passed":16,"url":"http://127.0.0.1:8090/product/opf-e2e-switch-control-product/",
#  "switchStyle":{"appearance":"none","background":"rgb(104, 113, 124)","focusOutline":"solid"},
#  "pageErrors":[]}                                (exit 0)

$W eval-file bin/e2e-switch-control-test.php verify
# Success: Switch true/false states and independent checkbox choices survived cart, order, and order-again; hidden conditional data was excluded.   (exit 0)

$W eval-file bin/e2e-switch-control-test.php cleanup
# Success: Switch-control fixtures removed.        (exit 0)
```

Checks that passed:

- Browser: the harness asserts 16 named checks and reported `passed: 16` with
  `pageErrors: []` — one named switch each for the true/false field and both
  checkbox choices, native `type="checkbox"` semantics, initial unchecked
  state, hidden conditional control, no submitted value from the hidden
  conditional field, Space toggling of the true/false switch and of one
  independent checkbox switch, visible switch styling (`appearance: none`,
  non-transparent track `rgb(104, 113, 124)`, `solid` focus outline), and
  submitted form values (`[enabled]=1`, `[extras][]=gift` only).
- WP-CLI `verify`: false and true toggle values, independently selected checkbox
  choices, hidden conditional-value filtering, order-time `_opf_fields`
  persistence and order-again restoration for both cases (single `Success`
  line; the fixture errors out on the first violated assertion).

Checks that failed: none in this run.

## Cleanup

`cleanup` removed the fixture group, product, state option and flushed the
field-group cache (exit 0). Post-run audit: `recent_orders=0`,
`fixture_orders_leaked=0`, no fixture attachments, disposable site URL, theme,
plugin list and active plugins unchanged.

## Status

`WAPF-FIELD-TRUE-FALSE-SWITCH` stays **partial**, unchanged: the proof above
matches the already-recorded evidence. The open residuals are the current WAPF
Pro serialized setting and a WAPF-runtime visual comparison (no WAPF package on
this runtime) and the resulting absence of an importer-mapping claim.
