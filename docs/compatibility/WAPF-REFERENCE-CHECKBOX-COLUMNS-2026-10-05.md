# WAPF-FIELD-CHECKBOX-COLUMNS — what is actually open + admin REST proof (2026-10-05)

## Scope

The row's residual claimed *"Admin REST save/reload and cart/order lifecycle
remain unverified."* A 2026-10-05 run already proved the cart + order +
order-again half
([runtime evidence](WAPF-CHECKBOX-COLUMNS-RUNTIME-EVIDENCE-2026-10-05.md)). This
run verifies what was **actually** still open, proves the admin REST
save/reload path, and narrows the residual.

It also empirically establishes whether a WAPF-side comparison is possible on
the installed baseline at all.

## Environment

- WAPF Extended **3.1.5** active for the capability probe; **deactivated** for
  the storefront grid measurement (see the coexistence note below).
- Disposable clone `/tmp/opf-wapfref-compare-wp`, `http://127.0.0.1:8251`, WP 7.1.2 / WC 11.1.0 / PHP 8.5.11.
- Harnesses: `bin/e2e-wapfref-checkbox-columns-admin.php`, `bin/e2e-wapfref-checkbox-columns-admin-browser.mjs`.
- Artifacts: `docs/compatibility/wapf-reference-proof-20261005/row3-checkbox-columns/`.

## Commands

```sh
cd /tmp/opf-lane-wapfref
WP="wp --path=/tmp/opf-wapfref-compare-wp --allow-root"
OUT=docs/compatibility/wapf-reference-proof-20261005
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-checkbox-columns-admin.php wapf-options
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT OPF_WAPFREF_ADMIN_PASSWORD=<24+ chars> \
  $WP eval-file bin/e2e-wapfref-checkbox-columns-admin.php setup
OPF_WAPFREF_OUT=$OUT OPF_WAPFREF_ADMIN_PASSWORD=<24+ chars> \
  NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
  node bin/e2e-wapfref-checkbox-columns-admin-browser.mjs
OPF_WAPFREF_ALLOW=1 OPF_WAPFREF_OUT=$OUT $WP eval-file bin/e2e-wapfref-checkbox-columns-admin.php verify
```

## WAPF 3.1.5 capability probe (`wapf-3.1.5-checkbox-options.json`) — no control

`SW_WAPF_PRO\Includes\Classes\Config::get_field_options()` was enumerated
recursively for the `checkboxes` and `true-false` field types:
`column_related_tokens: []`, `has_column_control: false`. Installed Extended
3.1.5 has **no checkbox column setting**, so a 3.1.5 runtime comparison is
impossible; the WAPF-side unknown is bounded to the unavailable Pro 3.2 range.

## Admin REST save/reload (`browser-admin-rest.json`) — 7/7 PASS

Authenticated real Chromium session against the actual builder screen
(`/wp-admin/post.php?post=<group>&action=edit`), using the nonce and endpoint
the builder itself reads from `#opf-builder-app`:

1. temporary administrator login succeeds;
2. builder exposes a live REST nonce + `/wp-json/opf/v1/groups` endpoint;
3. fixture group starts at `columns:2`;
4. `POST /wp-json/opf/v1/groups` with the modified model returns HTTP 200 for the same id;
5. `GET /wp-json/opf/v1/groups` returns `columns:4`;
6. a fresh builder load reads back `columns:4`;
7. the storefront renders the saved 4-column CSS grid.

`verify` then reads the stored model directly: `stored_columns: 4`,
`resolved_columns: 4`.

## Coexistence note

With WAPF **active**, its shipped CSS
(`.wapf-checkboxes,.wapf-radios{display:inline-grid;grid-template-columns:auto}`)
overrides OPF's `.opf-checkboxes--columns{grid-template-columns:repeat(…)…}` on
the shared wrapper class, so the storefront grid measured 1 column. The grid
check therefore runs with WAPF inactive (this row is OPF-only; WAPF 3.1.5 has no
such control). This CSS interaction is recorded for the coexistence surface, and the ledger
now names it as the single blocking defect on
`WAPF-FIELD-CHECKBOX-COLUMNS` until the fix is verified with WAPF active.

## Result

- **Closed:** admin REST save/reload — a real authenticated POST through the
  builder's own endpoint persists `columns`, survives reload and drives the
  storefront grid. Combined with the 2026-10-05 cart/order/order-again proof,
  both named lifecycle gaps are closed.
- **Remaining / not closed:** the WAPF Pro 3.2 value range, saved shape,
  responsive behaviour and markup cannot be compared because no 3.2 package
  exists on this host and 3.1.5 has no column control; that version-bounded
  unknown is the only thing left in the residual.

## Cleanup

`cleanup` deleted the fixture product, group, temporary administrator and
options; cache flushed. A post-run audit found no `wapfref-*` objects. WAPF was
reactivated after the run for the other rows, then deactivated again at the end
of the lane. Production was read-only.
