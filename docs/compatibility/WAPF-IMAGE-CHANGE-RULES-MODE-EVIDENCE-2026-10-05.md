# OPF-native image-change rules: storefront emission — evidence 2026-10-05

## Scope

Repairs the source gap named in
[image-change rules-mode finding](WAPF-IMAGE-CHANGE-RULES-MODE-FINDING-2026-10-05.md):
the OPF-native `image_rules` / `image_rule_mode` model that the builder edits
(`assets/js/opf-builder.js`) and the frontend reads
(`assets/js/opf-frontend.js`) had no storefront producer, so
`window.OPF_IMAGE_RULES` was always `undefined` and rules-mode image swapping
never happened. This lane emits both globals and proves the swap in a real
browser. Row `WAPF-INTERACTION-IMAGE-CHANGE` moves closer to complete; the
residuals at the end of this document still keep it **partial**.

## Root cause (unchanged from the finding)

`grep -rn OPF_IMAGE_RULES` returned only the reader
(`assets/js/opf-frontend.js:3148`), its minified copy and the harness — no PHP
emitter. `includes/Service/Assets.php:71` published `window.OPF_FIELDS` and
nothing else, so `productImageEvaluations[].rules` was always `[]` and
`updateProductImage()` fell back to the legacy per-field `change_product_image`
choice path.

## Fix: emitted shape and where the group id comes from

`includes/Service/Assets.php` (`Assets::enqueue_frontend()`), next to the
existing `OPF_FIELDS` inline tag and only when the registry is non-empty:

```text
window.OPF_IMAGE_RULES = {"15104":[{"target_url":"http://host/wp-content/uploads/x.png","conditions":[{"field":"color","value":"red"},{"field":"size","value":"*"}]}, ...]};
window.OPF_IMAGE_RULE_MODES = {"15104":"rules"};
```

- **Value shape** is exactly what the reader consumes: per rule
  `{target_url, conditions:[{field, value}]}` in authored order, and per group a
  swap mode `rules` | `last`. That is the stored, already-normalized
  `FieldGroup` payload (`FieldGroup::normalize_image_rules()`, `\OPF\Engine\
  FieldGroup.php:247`) — no re-normalization, no reordering
  (`resolveProductImageRule()` scans **backwards**, so order carries the
  "last matching rule wins" priority).
- **Key** is the same id the frontend derives as `gid`:
  `groupEl.getAttribute('data-opf-group')` (`assets/js/opf-frontend.js:3148`,
  `:2689`, `:3001`). `Renderer::render_group()` writes
  `data-opf-group="(string) $gid"` (`includes/Service/Renderer.php:435`) and the
  registry passed to `enqueue_frontend()` is keyed by that same
  `(string) $entry['id']` (`includes/Service/Renderer.php:308`), so the emitter
  keys off the registry it already receives instead of assuming an id format.
- **Only rule-carrying groups, only rendered groups**: the new
  `Assets::frontend_image_rules()` intersects `FieldGroups::all()` (request
  cached — `FieldGroups::$all`, already populated by the render pass, so no
  extra query) with the registry keys and drops groups with no rules.
  Rule-less pages emit no extra byte.
- **Same mechanism as `OPF_FIELDS`**: `wp_print_inline_script_tag()` (classic
  inline script, executes before the deferred frontend module; script modules
  bypass `wp_scripts`, so `wp_add_inline_script()` stays unusable — see the
  comment at `Assets.php:64`).
- The WAPF-shaped path is untouched: `data-opf-gi` /
  `data-wapf-gi` emission (`Renderer::gallery_image_rules()`) and the
  `[data-opf-gi]` consumer (`matchGalleryRule`) were not modified.

## Files changed

| File | Change |
|---|---|
| `includes/Service/Assets.php` | +38: `frontend_image_rules()` helper and the second inline script tag |
| `tests/Unit/ProductImageRulesPayloadTest.php` | new: 4 tests / 8 assertions pinning payload, key, omission and mode |
| `bin/e2e-image-change-test.php` | +5/-1: fixture rule order (harness-data defect, see below) |
| `docs/compatibility/WAPF-IMAGE-CHANGE-RULES-MODE-EVIDENCE-2026-10-05.md` | this document |

No JS, no `includes/Engine/`, no `Renderer.php`, no `WapfHooks.php` change, and
`assets/js/opf-frontend.min.js` needs no rebuild (no source JS changed).

## Unit test

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
vendor/bin/phpunit --filter ProductImageRulesPayloadTest
# ....  4 / 4 (100%)
# Tests: 4, Assertions: 8, PHPUnit Deprecations: 2  (the 2 are pre-existing, see below)
```

The four tests assert, from the printed inline script:

1. a group with rules is emitted with the literal reader shape
   (`{target_url,conditions:[{field,value}]}`, authored order preserved) under
   the id its own `data-opf-group` markup carries
   (`Renderer::render_group( '15086', … )` → `data-opf-group="15086"`), with
   `image_rule_mode = last` surfacing as `"last"`;
2. a group without rules emits **no** `OPF_IMAGE_RULES` / `OPF_IMAGE_RULE_MODES`
   tag at all while `OPF_FIELDS` still prints;
3. a group with rules that is **not** in the rendered registry is not emitted;
4. a group with rules and no `image_rule_mode` reports `"rules"`.

## Browser harness

Disposable WordPress `/home/followersya-5hqi7/opf-test/wordpress`
(WordPress 7.1.2 / WooCommerce 11.1.0 / PHP 8.5.11 / SQLite), plugin synced from
this checkout and verified identical (`diff -rq --exclude=.git
--exclude=node_modules` → clean). Server: `wp server --host=127.0.0.1
--port=8090`, killed after the run. Chromium via Playwright (harness-owned).

### Before (pre-change plugin, as reproduced by this lane)

```sh
node bin/e2e-image-change-browser-test.mjs
# page.waitForFunction: Timeout 30000ms exceeded.
#     at .../bin/e2e-image-change-browser-test.mjs:26:12   (exit 1)
```

Identical to the finding: the harness cannot get past waiting for
`window.OPF_IMAGE_RULES`.

### After

```sh
# 1. sync this checkout into the disposable site
rsync -a --delete --exclude='.git' --exclude='node_modules' \
  /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce/ \
  /home/followersya-5hqi7/opf-test/wordpress/wp-content/plugins/open-product-fields-for-woocommerce/

# 2. serve the site (wp server rewrites home/siteurl to the request host)
wp --path=/home/followersya-5hqi7/opf-test/wordpress server --host=127.0.0.1 --port=8090

# 3. prepare the fixture in the SAME url context the harness serves from
wp --path=/home/followersya-5hqi7/opf-test/wordpress eval-file bin/e2e-image-change-test.php prepare
# IMAGE_CHANGE_PRODUCT=15100 / IMAGE_CHANGE_GROUP=15104
# IMAGE_CHANGE_URL=http://127.0.0.1:8090/product/opf-e2e-conditional-image-product/
# IMAGE_CHANGE_EXTERNAL=http://127.0.0.1:8090/wp-content/uploads/opf-image-change-external.png

# 4. run the harness
OPF_BASE_URL=http://127.0.0.1:8090 \
OPF_IMAGE_CHANGE_URL="http://127.0.0.1:8090/product/opf-e2e-conditional-image-product/" \
OPF_IMAGE_CHANGE_EXTERNAL="http://127.0.0.1:8090/wp-content/uploads/opf-image-change-external.png" \
  node bin/e2e-image-change-browser-test.mjs
```

```text
ok product renders a three-image WooCommerce gallery with a known initial slide
ok AND-combination rule navigates to matching existing gallery image
ok wildcard rule shows its external target when size condition stops matching
ok a later rule navigates to another existing gallery slide
ok mismatch restores the original main gallery slide
ok no uncaught browser errors during gallery transitions
(exit 0, 6/6)
```

Live page evidence for the same run:

```text
data-opf-group="15057"          # other lane's fixture group, no image rules
data-opf-group="15104"          # this fixture group
window.OPF_IMAGE_RULES = {"15104":[…3 rules…]};
window.OPF_IMAGE_RULE_MODES = {"15104":"rules"};
```

i.e. only the group that actually authored rules is published — the rule-less
group on the same page emits nothing.

WAPF-shaped path regression: `bin/e2e-image-change-last-browser-test.mjs` (the
isolated `data-opf-gi` / `data-opf-st="last"` proof, real frontend module) still
passes 7/7, exit 0.

### Two harness defects found while getting this to run (no assertion weakened)

**1. Fixture rule order contradicted the runtime's last-match-wins semantics.**
`bin/e2e-image-change-test.php` authored the broad `red + Any` rule *after* the
`red + large` rule. `resolveProductImageRule()` scans backwards
(`assets/js/opf-frontend.js:2771-2784`) — last matching rule wins, WAPF-faithful
per the ledger's 3.1.5 source audit ("installed WAPF Extended 3.1.5 source
reverses rule order"), pinned by `tests/js/opf-pricing.test.cjs` ("preserve
last-match priority", `rules[1]` wins for `red+large`) and mirrored by the
WAPF-shaped consumer `matchGalleryRule` (`[…rules].reverse()`). With that order
the broad rule shadowed the AND rule for *every* value combination, so harness
check 2 ("AND-combination rule navigates to matching existing gallery image")
was unreachable no matter what the emitter did. Fixed by swapping the two `red`
rules (broad first, AND second) — every assertion is byte-identical, and check 2
now exercises the AND rule exactly as its label claims. Observed step by step
with matched origins, before that reorder: 5/6 with only check 2 failing.

**2. The `prepare` → `serve` recipe could not match slide URLs.**
`wp server` installs a router that rewrites `option_home` and `option_siteurl`
to `http://$_SERVER['HTTP_HOST']`
(`/tmp/wp-cli-extract-from-phar-*-router.php`), so a page served on
`127.0.0.1:8090` renders every asset URL as `http://127.0.0.1:8090/...`, while a
CLI `prepare` run stores media URLs from `WP_HOME` (`http://opf.test`, hardcoded
in the disposable `wp-config.php`). `imageUrlMatches()` compares
origin+pathname, so a stored rule URL could never match a rendered gallery slide
and checks 2/4/… degrades to a direct `src` swap instead of slide navigation.
Confirmed on the page (`canonical`/gallery URLs all `127.0.0.1:8090`, the
`OPF_IMAGE_RULES` payload still `opf.test`) and fixed by preparing the fixture in
the harness's URL context for the run:

```sh
# WP_HOME/WP_SITEURL set to http://127.0.0.1:8090 for the run only
wp config set WP_HOME http://127.0.0.1:8090 --type=constant      # + WP_SITEURL
...
# restored afterwards; wp-config.php verified byte-identical to the pre-run backup
diff <backup> /home/followersya-5hqi7/opf-test/wordpress/wp-config.php   # exit 0
```

`wp --url=http://127.0.0.1:8090` does **not** work for this: the `WP_HOME`
constant wins over the option, so `wp eval-file --url=…` still resolves
`http://opf.test` (verified). With that context in place the fixture's own
logged `IMAGE_CHANGE_EXTERNAL` is the value the harness needs — the finding
doc's `127.0.0.1` external value could never have satisfied check 3 against a
rule URL stored as `opf.test`.

## Suites

```sh
vendor/bin/phpunit 2>&1 | tail -6
# OK, but there were issues!
# Tests: 975, Assertions: 4156, PHPUnit Deprecations: 2.
#   (the 2 are pre-existing, in CapabilityFixtureRegistryTest and
#    LookupTableCsvImporterTest, untouched by this lane)

node --test tests/js/*.test.cjs 2>&1 | tail -8
# tests 125 / pass 125 / fail 0 / cancelled 0 / skipped 0 / todo 0
```

Baseline note: the task's stated baseline (964 tests / 4110 assertions) plus
this lane's 4 tests / 8 assertions is 968/4118 — which the first run of this
lane reproduced. The suite then grew to 975/4156 because a concurrent lane
committed `d429fd2 feat(hooks): bridge the WAPF public hook surface for real` on
`master` while this lane was running (+7 tests / +38 assertions, 0 failures).
No failure or warning was introduced by this lane.

## Cleanup

```sh
wp --path=/home/followersya-5hqi7/opf-test/wordpress eval-file bin/e2e-image-change-test.php cleanup
# Success: Conditional image E2E fixtures removed.
# fixture_attachments=0 fixture_products=0 fixture_groups=0 fixture_upload_files=0
# active plugins unchanged: open-product-fields-for-woocommerce, sqlite-database-integration, woocommerce
```

`wp server` on port 8090 killed; `wp-config.php` (WP_HOME/WP_SITEURL) restored
to `http://opf.test` and verified identical to the pre-run backup. Scratchpad
for this run: `/home/followersya-5hqi7/ops/scratchpad/20261005-opf-image-rules/`
(server log, prepare/cleanup logs, harness before/after logs, page dumps,
probe script) — kept because this document cites them; delete with the task.

## Residuals / open items

- **Builder copy contradicts the runtime.** `assets/js/opf-builder.js`
  (`imageRulesEditor()`) tells merchants "First matching rule wins", while the
  runtime — and WAPF 3.1.5, and `tests/js/opf-pricing.test.cjs` — resolve
  *last* matching rule wins. `opf-builder.js` is outside this lane's file
  ownership, so the help text is left as found; it should be corrected (or the
  runtime changed, which would break the pinned WAPF parity).
- **`rules` vs `last` for the OPF-native model is proven for `rules` only.**
  The browser run above is `image_rule_mode` absent (→ `rules`). `last` is
  proven for the WAPF-shaped `data-opf-gi` payload (7/7 isolated harness) and
  pinned for the reader in `tests/js/opf-pricing.test.cjs`/`opf-product-image
  .test.cjs`; no WordPress browser fixture sets `image_rule_mode: last` yet.
- **Filter-injected groups are not covered.** The emitter reads
  `FieldGroups::all()`; a group injected through `wapf/product_field_groups` →
  `opf_groups_for_product` (legacy WAPF add-ons) is in the registry but not in
  `all()`, so its OPF-native rules would not be emitted. WPML translation is
  safe: `WpmlIntegration::translate_groups()` keeps the entry id and
  `map_text()` does not touch `image_rules`.
- **Rule presence suppresses the legacy page-wide fallback.** With any group's
  rules now reaching the reader, `updateProductImage()` takes the rule branch and
  skips the legacy `change_product_image` per-field scan for the whole page
  (`if (!hasRules && !target)`). That is the reader's pre-existing designed
  precedence, not new logic, but it is now reachable — a page mixing an
  OPF-native ruled group with a legacy choice-image group should be re-checked
  if that combination is used.
- **Harness recipe is environment-sensitive.** Because `wp server` rewrites the
  site URL, the fixture must be prepared in the served origin. If a
  one-command recipe is wanted, the fixture could honour the harness's existing
  `OPF_BASE_URL` env var (`add_filter('option_home'/'option_siteurl')` before
  its first `wp_upload_dir()` call) instead of editing the disposable
  `wp-config.php`. Not done here to keep the harness diff at the data fix above.
- **WAPF Extended 3.2.1 and variation/gallery-plugin lifecycle remain
  unaudited** (pre-existing residual of this capability row).

## Status

`WAPF-INTERACTION-IMAGE-CHANGE` stays **partial**: the OPF-native rules-mode
path is now wired and proven end to end in a real browser on a live
WooCommerce product page (previously named as the blocking source gap), while
the residuals above remain open.
