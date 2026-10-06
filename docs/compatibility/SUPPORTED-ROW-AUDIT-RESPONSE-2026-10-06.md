# Supported-row audit response — 2026-10-06

Response to the independent supported-row audit of the `supported` edition rows
in [`WAPF-CAPABILITY-LEDGER.md`](WAPF-CAPABILITY-LEDGER.md). The audit report
itself is not committed in this repository; its finding table is reproduced
below and every entry was re-verified against this tree before acting on it.

The audit checked each `supported` row for two independent things: working code
in this tree, and a fast, repeatable test in the default suite. A row with code
but only browser/E2E artifacts does not fail on behavior — it fails on
regression protection, because nothing in `vendor/bin/phpunit --no-coverage` or
`node --test tests/js/*.cjs` would catch a later break.

## 1. Audit findings

At audit time the ledger recorded 124 `supported` + 7 `supported with documented
difference` = 131 edition rows.

| Audit finding | Rows | Row IDs |
| --- | ---: | --- |
| Code present and covered by a fast test | 119 | the remaining `supported` rows |
| Code present, browser/E2E proof only — no fast test | 5 | `WAPF-GROUP-SCHEDULING`, `WAPF-GROUP-ADMIN-TITLE-SEARCH`, `WAPF-ADMIN-DUPLICATE-FIELD`, `WAPF-LOCALE-BUNDLED-STRINGS`, `WAPF-LOCALE-ADMIN-STRINGS` |
| Not found — no code at all | 2 | `WAPF-PRODUCT-BOOKINGS`, `WAPF-INTEGRATION-GIFT-CARDS` |

Re-verification in this tree:

- The five code-only rows do have code at the audited locations
  (`includes/Service/FieldGroups.php:168-241`, `assets/js/opf-builder.js:1164`,
  `includes/Service/Assets.php:74,149,236`) and no fast test referenced them
  before this response; only the browser scripts named in their ledger cells
  existed.
- The two not-found rows have no implementation to find: no booking code and no
  gift-card code exist in this plugin, and the installed WAPF Extended 3.1.5
  does not register its dormant bookings class either (§3).

## 2. Fast test coverage added for the five code-only rows

| Row | Test file | What it asserts |
| --- | --- | --- |
| `WAPF-GROUP-SCHEDULING` | `tests/Unit/FieldGroupsRegistrationTest.php` | The `opf_field_group` CPT is registered; `FieldGroups::all()` and `FieldGroups::for_product()` return only the published group while a `future` fixture is invisible, and the same fixture becomes visible after WordPress's future-publish step flips it to `publish`. The shared `get_posts()` stub now honours `post_status` (and the test asserts that filtering directly) so the published-only claim is not vacuous. |
| `WAPF-GROUP-ADMIN-TITLE-SEARCH` | `tests/Unit/FieldGroupsRegistrationTest.php` | The CPT registers `supports: ['title']` with the `Search Field Groups` label and `show_ui`, i.e. the core admin list-table search surface the browser run exercised. |
| `WAPF-ADMIN-DUPLICATE-FIELD` | `tests/js/opf-builder-duplicate.test.cjs` | Clicks the real `Duplicate field` control and asserts each copy is spliced immediately after its source, gets a collision-free id (`prints-copy-2`, then `prints-copy-3` with `prints-copy` already taken), preserves choices/pricing/conditionals/min/max, and stays independent of later edits to the source (deep copy, not a shared reference). |
| `WAPF-LOCALE-BUNDLED-STRINGS` | `tests/Unit/AssetsI18nRegistryTest.php` | Parses the emitted `window.OPF_I18N` inline script and asserts the registry carries exactly the 27 predefined keys, the exact English strings for a sample of them, the 7 weekday abbreviations, and no empty translatable value. |
| `WAPF-LOCALE-ADMIN-STRINGS` | `tests/Unit/AssetsScriptTranslationsTest.php` | Asserts `wp_set_script_translations( 'opf-builder', 'open-product-fields-for-woocommerce', OPF_DIR . 'languages' )` is called exactly once on the builder screen and never off it; the POT `X-Domain` and the shipped builder JED (`{domain}-es_ES-{md5('assets/js/opf-builder.js')}.json`) match that domain and handle, the JED `source` is `assets/js/opf-builder.js`, and it carries ≥190 msgids including `Duplicate field`. |

New tests: **8 PHPUnit tests / 93 assertions** plus **2 Node tests**.

Supporting change: `tests/Unit/FieldGroupsAuthTest.php` and the guarded twin in
`tests/Unit/ProductImageRulesPayloadTest.php` now make `get_posts()` emulate
WordPress's `post_status` filter through a side registry
(`$GLOBALS['opf_auth_test_post_statuses']`, absent ids count as `publish`). No
existing fixture sets a status, so every existing test keeps its previous
behaviour.

## 3. Honest re-grades for the two not-found rows

Both rows claimed `supported` on the strength of a documented capability plus
"no difference" reasoning. Neither has an implementation, and neither capability
exists in the 3.1.5 baseline either, so the honest status is a
**parity-by-absence documented difference**, not parity.

### `WAPF-PRODUCT-BOOKINGS` — `supported` → `supported with documented difference`

Difference, stated plainly: **neither engine provides booking/person-type
support at the 3.1.5 baseline; OPF claims no booking capability.**

Verified against the installed source at
`bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`:

- `includes/classes/integrations/class-woocommerce-bookings.php:6` defines
  `SW_WAPF_PRO\Includes\Classes\Integrations\WooCommerce_Bookings`, but
  `Integrations_Controller::$available_integrations`
  (`includes/controllers/class-integrations-controller.php:14-23`) lists only
  Quickview, Product_Table, Tiered_Pricing_Table, WOOCS, Yith_RAQ,
  WooCommerce_Subscriptions, Woo_Discount_Rules and Aelia.
- `add_integrations()` (`:82-88`) is the only code that instantiates an
  integration class, and it iterates that list under `class_exists()`; WooCommerce
  Bookings is never a key, so the class is never loaded or registered.
- The autoloader (`advanced-product-fields-for-woocommerce-extended.php:19-45`)
  maps class names to files but does not scan the integrations directory, so
  nothing loads the class implicitly either.
- `grep -rin booking` over the package returns nothing outside the class file
  itself except one dormant POT msgid.
- OPF has no booking builder or bridge (`grep -rin "booking"` over
  `includes/` and `assets/js/` returns one unrelated comment about shortcodes).

### `WAPF-INTEGRATION-GIFT-CARDS` — `supported` → `supported with documented difference`

Difference, stated plainly: **there is no dedicated gift-card adapter; the
underlying parent-product field-group lookup is proven under
`WAPF-PRODUCT-VARIABLE`.**

- No gift-card code exists in OPF (`grep -rin gift` over `includes/` and
  `assets/` returns only unrelated fixture labels) and none in the inspected
  WAPF source.
- The row's concrete behavior is the parent-resolved `for_product()` lookup
  during variable-product cart validation, which is `WAPF-PRODUCT-VARIABLE`'s
  proven lifecycle (variation switch, parent-targeted group, cart/order/order-again).
- No third-party Gift Card plugin compatibility is claimed.

## 4. Recount

Recounted by parsing the ledger's edition rows (rows whose `|`-split cell count
is ≥ 10, excluding `WAPF-ADDON-*`; the three rows with a `|` inside their note
count as 11 cells):

| Status | Before | After |
| --- | ---: | ---: |
| Supported | 124 | 122 |
| Supported with documented difference | 7 | 9 |
| Partial | 0 | 0 |
| Baseline supported | 0 | 0 |
| Gap | 0 | 0 |
| Needs audit | 0 | 0 |
| **Edition total** | **131** | **131** |
| Strict progress | 124/131 (94.7%) | **122/131 (93.1%)** |

Per tier after the re-grade: Free 35 supported + 3 documented differences,
Pro 57 + 5, Extended 28 + 0, All versions 2 + 1. The six `WAPF-ADDON-*` rows
(all `partial`) and the compatibility-matrix tables stay outside the 131-row
denominator.

Accepted parity remains 131/131 only because every documented difference records
how it was accepted: seven carry the D1/scope/residual decisions of 2026-10-05,
and the two re-graded rows record that their difference is an absence of the
capability in both engines. Nothing here turns an absence into an implementation.

Suite state after the change: `vendor/bin/phpunit --no-coverage` →
**1105 tests / 4716 assertions / 0 failures** (2 pre-existing PHPUnit
deprecations); `node --test tests/js/*.cjs` → **132 tests / 132 pass**. The
8 tests / 93 assertions are this response's; the remainder of the delta from the
1086 / 4590 baseline measured at the start of this session comes from
uncommitted parallel work in the same worktree (`ModernUploaderSettingTest.php`
6 tests / 21 assertions and growth in `CommerceWeightTest.php`), not from this
response.

## 5. Remaining honesty caveats

These rows pass the audit but rest on weaker evidence than their status
suggests. They are recorded here rather than silently promoted:

- **Translation loading (`WAPF-LOCALE-BUNDLED-STRINGS`, `WAPF-LOCALE-ADMIN-STRINGS`).**
  The new tests prove the registry shape and that the builder handle is
  registered against the plugin's languages directory with a matching POT/JED
  domain. They do not boot WordPress, switch locale, or assert that a translated
  string is actually rendered; that half stays the browser/real-runtime evidence
  already cited in the rows. Catalog counts also drift: the ledger's recorded
  `191` JED msgids are now `324` and its `313` POT msgids are now `613`, so the
  cells were corrected to the measured values.
- **Duplicate group (`WAPF-ADMIN-DUPLICATE-GROUP`).** The data-level copy
  (`FieldGroup::duplicate()`) is covered by `tests/Unit/FieldGroupSchemaTest.php`,
  but the admin action (`FieldGroups::handle_duplicate()`: nonce, capability
  check, save, Polylang language copy) still has **no fast test**; only the
  field-level builder duplicate added here is covered. Its evidence remains the
  admin/browser artifacts cited in its row.
- **Simple products (`WAPF-PRODUCT-SIMPLE`).** Evidence is a disposable
  WooCommerce + Chromium lifecycle run. The unit suite exercises the Woo bridge
  in fragments (cart integration, resolver, renderer) but no row-specific fast
  test drives a real `WC_Product_Simple` through capture → order → order-again.
- **Date accessibility (`WAPF-DATE-ACCESSIBILITY`).** The 26-check real-Chromium
  run is the proof. There is no PHPUnit assertion on the ARIA contract
  (`role="dialog"`/`role="grid"`, `aria-live` announcements, focusable day
  buttons), so a renderer regression in those attributes would not fail the
  default suite.
- **Parity-by-absence is not parity.** `WAPF-PRODUCT-BOOKINGS` and
  `WAPF-INTEGRATION-GIFT-CARDS` are now `supported with documented difference`
  and contribute nothing to the strict `supported` count. If either capability is
  ever implemented (or if a future WAPF package registers its bookings class),
  the row must be re-graded on real behavior, not on this absence.

## 6. Verification performed

- `php -l` on every changed PHP file.
- `vendor/bin/phpunit --no-coverage` — 1105 tests / 4716 assertions / 0 failures.
- `node --test tests/js/*.cjs` — 132/132.
- Ledger recount re-run by parsing the file after editing (131 edition rows,
  122 supported, 9 documented differences).
- Installed WAPF Extended source inspected for the bookings claim
  (`$available_integrations`, `add_integrations()`, autoloader, package-wide
  `grep`).
