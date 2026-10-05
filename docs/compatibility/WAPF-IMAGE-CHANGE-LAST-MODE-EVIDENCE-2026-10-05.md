# OPF-native `last` image-rule mode + merchant copy: evidence 2026-10-05

## Scope

Three follow-ups to
[OPF-native image-change rules: storefront emission](WAPF-IMAGE-CHANGE-RULES-MODE-EVIDENCE-2026-10-05.md):

1. the builder's merchant copy claimed "First matching rule wins" while the
   runtime resolves **last**-match-wins;
2. the OPF-native `image_rule_mode: last` path was unproven (only `rules` mode
   had a WordPress browser fixture);
3. hook-injected groups (`wapf/product_field_groups` → `opf_groups_for_product`)
   were rendered but their rules were never emitted.

All three are closed below. Row `WAPF-INTERACTION-IMAGE-CHANGE` stays **partial**
for the pre-existing reasons at the end.

## 1. Copy fix: every location that described the wrong precedence

`assets/js/opf-builder.js` (the group builder's image-rules editor):

| Line | Was | Now |
|---|---|---|
| 1771-1773 (source comment above `imageRulesEditor()`) | "the first matching rule wins" | "the last matching rule wins (the frontend scans the list in reverse, WAPF 3.1.5 parity)" |
| 1777 (merchant help text printed under "Conditional product images") | "Use “Any” to ignore a field. First matching rule wins." | "Use “Any” to ignore a field. When several rules match, the last one in the list wins." |

Both describe the runtime as it is: `resolveProductImageRule()`
(`assets/js/opf-frontend.js:2771`) walks `for (let index = rules.length - 1; index >= 0; index--)`
and returns the first hit it meets, i.e. the **last** rule in the authored
list; WAPF Extended 3.1.5 reverses its rule list before matching
(`docs/compatibility/WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md:102`), and
`tests/js/opf-pricing.test.cjs` ("preserve last-match priority") plus the
WAPF-shaped `[data-opf-gi]` consumer pin the same priority.
The rest of the tree was checked with
`grep -rni "first match|first matching|first rule" assets/ includes/ docs/ bin/ tests/`
(plus `"matching rule"`, `"rule wins"`, `"rules are checked"`, `"in order and"`).
Every other hit describes a **different** runtime and was left as found:

| Location | Why it stays |
|---|---|
| `assets/js/opf-builder.js:1023` (custom variables help) | Custom variables are first-match: `expandVariables()` breaks at the first passing rule (`assets/js/opf-frontend.js:1841`) and the reader's variable rule loop stops there too. |
| `assets/js/opf-builder.js:2131` and `includes/Service/Admin/FormulaVariables.php:45` (formula variables help) | Formula variables are first-match in both runtimes: `resolve_formula_variables()` breaks on the first passing change (`includes/Engine/FieldGroup.php:338`) and `resolveFormulaVariables()` does the same in JS (`assets/js/opf-frontend.js:2549`). |
| `assets/js/opf-frontend.js:1824`, `:2525`, `includes/Engine/Calculator.php:624`, `includes/Engine/FieldGroup.php:338` | Same first-match subjects (variable/change rules), described correctly. |
| `assets/js/opf-frontend.js:3186` | "WAPF resolves `[price.ID]` from the first matching source" — the price-source lookup, not rule precedence. |
| `docs/compatibility/WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md:102`, `WAPF-IMAGE-CHANGE-LAST-EVIDENCE-2026-10-04.md:23` | Both describe **WAPF's own** reverse-then-first source behaviour (the reason OPF's last-match-wins is parity), not OPF copy. |
| `docs/compatibility/WAPF-IMAGE-CHANGE-RULES-MODE-EVIDENCE-2026-10-05.md:246` | The prior lane's residual entry that *reports* the wrong copy; kept as the historical record. |
| `docs/compatibility/WAPF-IMAGE-CHANGE-RULES-MODE-EVIDENCE-2026-10-05.md:40` | Already states last-match-wins correctly. |

No other builder/help string mentions image-rule precedence, and
`languages/*.pot` is stale for all builder JS strings (it does not contain the
old sentence), so no translation catalog carries the wrong claim.

## 2. What `image_rule_mode: last` actually does

Read from the shipped reader before writing any assertion:

- `resolveProductImageRule( rules, values, mode, lastChangedField )`
  (`assets/js/opf-frontend.js:2771-2785`) still scans the list backwards, but in
  `last` mode it additionally rejects every non-wildcard condition whose
  `field` differs from `lastChangedField` (`:2776`). Wildcard-only conditions
  keep passing.
- `lastChangedField` comes from `lastImageFieldByGroup`
  (`:2786`), which the delegated `change` listener fills with the closest
  `[data-opf-field]` of the group on every change (`:3394`). Before the first
  change it falls back to `initialImageField()` (`:2788-2798`): the first
  visible field in DOM order that any non-wildcard condition names.

So `last` is "the rule list is evaluated in reverse **and** only the field the
shopper just changed can select a rule" — a rule naming two fields can never
win once one of them was not the last change.

### Fixture (`bin/e2e-image-change-test.php`)

The `prepare` phase now builds a second product + group next to the rules-mode
one. Fields `color` (default `green`) and `size` (default `large`), mode
`last`, two single-condition rules in this order:

```php
'image_rule_mode' => 'last',
'image_rules' => [
	[ 'target_url' => <gallery image 1 (back)>,  'conditions' => [ [ 'field' => 'color', 'value' => 'red' ] ] ],
	[ 'target_url' => <gallery image 2 (side)>,  'conditions' => [ [ 'field' => 'size', 'value' => 'small' ] ] ],
],
```

Expected slide per step (0 = base, 1 = back, 2 = side):

| Step | `rules` mode (later rule wins) | `last` mode (changed field wins) |
|---|---|---|
| page load, `color=green`, `size=large` | 0 | 0 |
| `size=small` | 2 (only the size rule's value matches) | 2 |
| `color=red` (now **both** rules' values match) | **2** (later size rule) | **1** (colour is the changed field) |
| `size=large` | **1** (the colour rule still matches) | **0** (last-changed `size` matches no rule) |

Steps 3 and 4 are the discriminators; both are asserted, and both were observed
to differ from `rules` mode on the same served markup (negative control below).

### Commands and observed output

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce

# 1. sync this checkout into the disposable site (WordPress 7.1.2 / WooCommerce 11.1.0 / PHP 8.5.11 / SQLite)
rsync -a --delete --exclude='.git' --exclude='node_modules' ./ \
  /home/followersya-5hqi7/opf-test/wordpress/wp-content/plugins/open-product-fields-for-woocommerce/

# 2. serve it (wp-cli must be told the docroot; without --docroot it aborted with "Directory web does not exist.")
wp --path=/home/followersya-5hqi7/opf-test/wordpress --docroot=/home/followersya-5hqi7/opf-test/wordpress \
  server --host=127.0.0.1 --port=8090 &

# 3. fixtures, in the origin the harness will load (OPF_BASE_URL is enough — no wp-config.php edit)
OPF_BASE_URL=http://127.0.0.1:8090 wp --path=/home/followersya-5hqi7/opf-test/wordpress \
  eval-file bin/e2e-image-change-test.php cleanup
OPF_BASE_URL=http://127.0.0.1:8090 wp --path=/home/followersya-5hqi7/opf-test/wordpress \
  eval-file bin/e2e-image-change-test.php prepare
# IMAGE_CHANGE_PRODUCT=15119 / IMAGE_CHANGE_GROUP=15124
# IMAGE_CHANGE_URL=http://127.0.0.1:8090/product/opf-e2e-conditional-image-product/
# IMAGE_CHANGE_INITIAL=http://127.0.0.1:8090/wp-content/uploads/opf-image-change-front.png
# IMAGE_CHANGE_GALLERY=http://127.0.0.1:8090/wp-content/uploads/opf-image-change-back.png,http://127.0.0.1:8090/wp-content/uploads/opf-image-change-side.png
# IMAGE_CHANGE_EXTERNAL=http://127.0.0.1:8090/wp-content/uploads/opf-image-change-external.png
# IMAGE_CHANGE_LAST_PRODUCT=15120 / IMAGE_CHANGE_LAST_GROUP=15125
# IMAGE_CHANGE_LAST_URL=http://127.0.0.1:8090/product/opf-e2e-last-image-product/
# Success: Conditional image fixture prepared.

# 4. the run
OPF_BASE_URL=http://127.0.0.1:8090 \
OPF_IMAGE_CHANGE_URL='http://127.0.0.1:8090/product/opf-e2e-conditional-image-product/' \
OPF_IMAGE_CHANGE_EXTERNAL='http://127.0.0.1:8090/wp-content/uploads/opf-image-change-external.png' \
OPF_IMAGE_CHANGE_LAST_URL='http://127.0.0.1:8090/product/opf-e2e-last-image-product/' \
  node bin/e2e-image-change-browser-test.mjs
```

```text
ok product renders a three-image WooCommerce gallery with a known initial slide
ok AND-combination rule navigates to matching existing gallery image
ok wildcard rule shows its external target when size condition stops matching
ok a later rule navigates to another existing gallery slide
ok mismatch restores the original main gallery slide
ok no uncaught browser errors during gallery transitions
ok last-mode page publishes one group in last mode with two single-field rules
ok last mode renders the base gallery slide before any field change
ok last mode follows the size rule once size is the changed field
ok last mode keeps the colour rule although the later size rule also matches
ok last mode restores the base slide when the changed field matches no rule
ok no uncaught browser errors during the last-mode transitions
(exit 0, 12/12)
```

Live page evidence (same run, `curl` of the last-mode product page):

```text
data-opf-group="15057"                       # other lane's fixture group, no image rules → emits nothing
data-opf-group="15125"                       # this fixture group
window.OPF_IMAGE_RULES = {"15125":[{"target_url":"http://127.0.0.1:8090/wp-content/uploads/opf-image-change-back.png","conditions":[{"field":"color","value":"red"}]},{"target_url":"http://127.0.0.1:8090/wp-content/uploads/opf-image-change-side.png","conditions":[{"field":"size","value":"small"}]}]};
window.OPF_IMAGE_RULE_MODES = {"15125":"last"};
```

### Negative control (why these checks are falsifiers, not tautologies)

The same served page with `image_rule_mode` removed from the stored group
(`rules` mode) and the same interaction sequence, read by a throwaway probe
(`probe-image-rule-mode.mjs`, scratchpad):

```text
--- negative control: same page, image_rule_mode removed (= rules) ---
mode=rules
initial slide=0
after size=small slide=2
after color=red (both rules' values satisfied) slide=2
--- same probe, fixture restored with image_rule_mode=last ---
mode=last
initial slide=0
after size=small slide=2
after color=red (both rules' values satisfied) slide=1
```

`rules` mode lands on slide 2 where `last` mode must land on slide 1, so
harness check 10 fails on `rules` semantics (check 11 fails too: `rules` keeps
the colour rule when `size=large`, `last` restores the base slide; check 7 also
fails there by construction, because the page would no longer publish
`last`).

### Harness changes

`bin/e2e-image-change-browser-test.mjs`: both scenarios now run from one
command — the rules-mode scenario is unchanged (same six checks, same
labels), plus the last-mode scenario behind `OPF_IMAGE_CHANGE_LAST_URL`
(required, for the same reason `OPF_IMAGE_CHANGE_URL` is). Pages are opened
through a small `openPage()` helper so each scenario keeps its own
`pageerror` list, and `select`/`selectedGalleryIndex` take the page as an
argument. The last-mode scenario first pins the published payload
(`OPF_IMAGE_RULE_MODES[gid] === 'last'`, one group, two rules, no rule naming
two fields), then asserts the four slide transitions above.

### Fixture changes (`bin/e2e-image-change-test.php`)

- Second fixture (product + group) with `image_rule_mode => 'last'`, logged as
  `IMAGE_CHANGE_LAST_PRODUCT` / `IMAGE_CHANGE_LAST_GROUP` /
  `IMAGE_CHANGE_LAST_URL`; `cleanup` removes both fixtures.
- **`OPF_BASE_URL` is honoured**, so no `wp-config.php` edit and no
  `wp_config set WP_HOME`/`WP_SITEURL` dance is needed any more. Two details
  were needed:
  - `option_home`/`option_siteurl` filters at priority **20** win over
    `_config_wp_home`/`_config_wp_siteurl` (priority 10), which is what the
    disposable site's `WP_HOME`/`WP_SITEURL` constants ride on — that is enough
    for `get_permalink()`/`home_url()`;
  - upload URLs need the `upload_dir` filter as well: with an empty
    `upload_path`, `_wp_upload_dir()` builds `baseurl` from the
    **`WP_CONTENT_URL` constant** (bootstrap-time `site_url()`), which no
    option filter can reach. Observed before that filter: permalink
    `http://127.0.0.1:8090/product/…` but `IMAGE_CHANGE_EXTERNAL` still
    `http://opf.test/wp-content/uploads/…`.

## 3. Hook-injected groups

**Finding: injected groups can carry `image_rules`, and now emit them.**

The filter hands out the OPF entry shape, not a rule-less skeleton:
`FieldGroups::for_product()` (`includes/Service/FieldGroups.php:306`) passes
`apply_filters( 'opf_groups_for_product', self::all(), $product )` and then
dereferences `$entry['id']`, `$entry['lang']` and
`$entry['group']->data` for every entry. An injected entry therefore *must*
carry a real `OPF\Engine\FieldGroup`, and `FieldGroup::__construct()` runs
`normalize_image_rules()` + `image_rule_mode` like any stored group — so an
add-on can author rules, `last` mode included.

The emitter now reads the ids the page actually rendered:

| File | Change |
|---|---|
| `includes/Service/FieldGroups.php` | `private static $resolved` — entries a placement pass resolved, keyed by group id; `remember()` indexes them at both `for_product()` exits (fresh + cache-hit); public `entries_by_id( $ids )` returns stored entries overlaid by the resolved ones (a listener may hand back a modified copy of a stored group, and that copy is what rendered); `flush_cache()` clears the index. |
| `includes/Service/Assets.php` | `frontend_image_rules()` iterates `FieldGroups::entries_by_id( array_keys( $registry ) )` instead of `FieldGroups::all()`, so only rendered groups emit and injected ones are included. Rule-less groups still emit nothing. |

Live before/after on the disposable site (probe MU-plugin appending one entry
with `image_rule_mode => 'last'` and one rule through the public WAPF alias
`wapf/product_field_groups`; it is never stored in the CPT, so no post exists):

```text
--- pre-change emitter (HEAD copies of Assets.php + FieldGroups.php in the disposable site) ---
{"fields":["15057","15117","99001"],"rules":["15117"],"modes":{"15117":"rules"},"injectedMarkup":true}
--- with this lane's emitter ---
{"fields":["15057","15117","99001"],"rules":["15117","99001"],"modes":{"15117":"rules","99001":"last"},"injectedMarkup":true}
```

i.e. the injected group was always rendered (`data-opf-group="99001"` in the
markup, `99001` in `OPF_FIELDS`) but its rules only reach the page with this
change. `15057` is another lane's fixture group; `15117` is the stored
rules-mode fixture group.

Pinned by a new unit test file `tests/Unit/ProductImageRulesInjectedGroupTest.php`
(2 tests / 7 assertions, `#[RunTestsInSeparateProcesses]` so its own guarded
stub harness drives the filter injection, like `PricingHintsTest` does):
no stored group exists, the injected entry is the only one on the page,
`for_product()` returns it, `all()` is empty, and the emitted payload carries
its rules under its own id with mode `last`; the second test pins that an
injected group without rules still emits nothing.

## Files changed

| File | Change |
|---|---|
| `assets/js/opf-builder.js` | +4/-3: last-match-wins comment + merchant help text |
| `includes/Service/Assets.php` | `frontend_image_rules()` reads `FieldGroups::entries_by_id()`; docblock |
| `includes/Service/FieldGroups.php` | +65: resolved-entry index, `entries_by_id()`, `remember()`, `flush_cache()` clear |
| `bin/e2e-image-change-test.php` | `last`-mode fixture, `OPF_BASE_URL` support, two-fixture cleanup, `IMAGE_CHANGE_LAST_*` logs |
| `bin/e2e-image-change-browser-test.mjs` | last-mode scenario + required `OPF_IMAGE_CHANGE_LAST_URL`; per-page error lists |
| `tests/Unit/ProductImageRulesInjectedGroupTest.php` | new: 2 tests / 7 assertions for injected-group emission |
| `docs/compatibility/WAPF-IMAGE-CHANGE-LAST-MODE-EVIDENCE-2026-10-05.md` | this document |

No `includes/Engine/` change, no `includes/Compat/WapfHooks.php` change, no
`Renderer.php` change, and no rebuild of `assets/js/opf-frontend.min.js`
(untouched).

## Suites

```sh
vendor/bin/phpunit 2>&1 | tail -6
# OK, but there were issues!
# Tests: 985, Assertions: 4198, PHPUnit Deprecations: 2.

node --test tests/js/*.test.cjs 2>&1 | tail -8
# tests 125 / pass 125 / fail 0 / cancelled 0 / skipped 0 / todo 0

php -l includes/Service/FieldGroups.php          # No syntax errors detected
php -l includes/Service/Assets.php               # No syntax errors detected
php -l bin/e2e-image-change-test.php             # No syntax errors detected
php -l tests/Unit/ProductImageRulesInjectedGroupTest.php   # No syntax errors detected
node --check assets/js/opf-builder.js            # clean
node --check bin/e2e-image-change-browser-test.mjs # clean
```

Accounting: this lane started at 975/4156 (the task's stated baseline). A
concurrent lane committed `d49c5ad feat(rules): honour an explicit variation id
in product placement` mid-lane (+8 tests / +35 assertions), and this lane adds
2 tests / 7 assertions: 983/4191 + 2/7 = **985/4198**, 0 failures, 0 warnings.
The 2 PHPUnit deprecations are pre-existing
(`CapabilityFixtureRegistryTest`, `LookupTableCsvImporterTest`).

## Cleanup

```sh
wp --path=/home/followersya-5hqi7/opf-test/wordpress eval-file bin/e2e-image-change-test.php cleanup
# Success: Conditional image E2E fixtures removed.
# remaining_groups=7 remaining_products=5 fixture_attachments=0 fixture_upload_files=0
# (the 7 groups / 5 products left are other lanes' fixtures on the shared site;
#  both fixture titles and both fixture slugs query 0 rows)
# active_plugins = open-product-fields-for-woocommerce, sqlite-database-integration, woocommerce
```

The probe MU-plugin was deleted from the disposable site, the `wp server`
listener on 127.0.0.1:8090 was killed (the unrelated `php -S …:8301` server
belongs to another lane and was left alone), and the disposable
`wp-config.php` was never modified this time (no `WP_HOME`/`WP_SITEURL`
round-trip needed). Scratchpad:
`/home/followersya-5hqi7/ops/scratchpad/20261005-opf-image-rules-last-mode/`
(prepare/cleanup/harness logs, page dump, discriminativeness probes, injected
group probe + its MU-plugin) — kept because this document cites it; delete with
the task.

## Residuals / open items

- **Lens advisories on pre-existing lines (not introduced here, not fixed to
  keep the diff scoped):** `assets/js/opf-builder.js:69` and `:2028`
  (`innerHTML`: the `el()` helper's intentional `html` attribute branch, and
  the sanitized live-preview response), `includes/Service/FieldGroups.php:88`
  (`add_query_arg( $args, $url )`, valid 2-argument WP API) and `:259`
  (`defined( 'ICL_LANGUAGE_CODE' )` guard), and the `WP_CLI::*` calls in
  `bin/e2e-image-change-test.php` (provided by WP-CLI at runtime for
  `wp eval-file` scripts). All are byte-identical to HEAD lines 69/2028,
  75/246.
- **The last-mode harness is now stricter for callers:** `OPF_IMAGE_CHANGE_LAST_URL`
  is required, so an old invocation with only `OPF_IMAGE_CHANGE_URL` now fails
  fast instead of silently skipping the new scenario.
- **Placement-rule interaction for injected groups is only proven for the
  straightforward case** (one injected entry that passes placement); a listener
  that replaces a *stored* entry with a modified copy is handled by the
  overlay order but has no dedicated test.
- **Rule presence still suppresses the legacy page-wide fallback**
  (`updateProductImage()`'s `if ( ! hasRules && ! target )`) — unchanged,
  pre-existing, and now also reachable from an injected group's rules.
- **WAPF Extended 3.2.1 and the variation/gallery-plugin lifecycle remain
  unaudited**, and `docs/compatibility/WAPF-CAPABILITY-LEDGER.md` still carries
  the pre-lane wording for `WAPF-INTERACTION-IMAGE-CHANGE` (it does not cite
  this or the previous image-rules evidence document). Updating that row was
  left out of this lane's scope.

## Status

`WAPF-INTERACTION-IMAGE-CHANGE` stays **partial**. Merchant copy now matches the
runtime, the OPF-native `last` mode is proven end to end in a real browser on a
live WooCommerce product page (with a negative control showing the checks
discriminate `last` from `rules`), and hook-injected groups' rules reach the
storefront — while the residuals above remain open.
