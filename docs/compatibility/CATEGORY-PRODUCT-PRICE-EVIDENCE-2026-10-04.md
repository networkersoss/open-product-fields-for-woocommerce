# Category product pricing: source, imports and checkout evidence

## Result

The category `fixed`/`none` implementation already preserved builder settings,
imports and native WooCommerce child line prices. A separate storefront defect
hid paid child selections from the live total: a $40 parent line displayed $40
while the same submitted selection charged $92 at checkout.

This patch resolves live child catalog prices into the client registry and reads
product choices and quantity selectors in the frontend pricing pass. The same
selection now displays $92 and charges $92 through both classic checkout and the
Store API. Free children remain native zero-price lines.

**The category ledger row remains partial.** This evidence establishes the tested
category price modes and import formats; it does not promote the broader child
products row or establish every child product integration, tax class, currency,
cart-edit, variation or quantity-relative edge case.

## Exact reference

Installed licensed Extended 3.1.5, read only:

`/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`

`includes/controllers/class-linked-products-controller.php`:

- Lines 350–450: cache construction; ordinary `one` adds one child,
  `parent` adds the parent quantity, selectors use entered child quantity.
- Lines 411–440: category product IDs are checked against the live query;
  category pricing is derived from the query configuration.
- Lines 596 onward: `none` child prices become zero in the native cart.
- Lines 1438–1473: sanitization accepts category `fixed`/`none`, default `fixed`.
- Lines 1556–1566: expanded choice amount is catalog price or zero; a configured
  card price slot still displays the catalog price for a free child.
- Lines 1597–1609: `none` takes precedence; quantity selectors use `nr`;
  ordinary `one` uses `fixed`, other ordinary choices use `qt`.

The runtime fixture executes the native reference parser, sanitizer, hydrated
field model, price type, choice expansion and cache construction against the
same disposable WooCommerce products. It loads reference classes without
bootstrapping WAPF or registering its global WooCommerce hooks. No licensed
implementation is copied into OPF.

## Environment and isolation

Fresh WordPress 7.1.2, WooCommerce 11.1.0, PHP 8.5.11, SQLite with prefix `cpt_`:
`/tmp/opf-category-price-wp`, loopback `http://127.0.0.1:8423`.
Plugin symlink points at this isolated worktree. It uses a newly installed
database and admin. Outbound HTTP and mail are blocked in the clone.

The original `opf-test` database and production were not used for fixture writes.
Fresh fixture records are deleted by ID, including the imported source/group
pairs and owned checkout orders/refunds. Clone store/page options are fixture
configuration and are not claimed to be restored to a pre-install baseline.
Taxes are disabled and currency is USD for the checkout proof.

## Red and green

- New browser pre-submit assertion failed before the fix: expected `$92`, actual
  `$40`; error `classic selected category preview equals checkout total 92`.
- New pure JS suite failed **25/25** before the fix, including exact `40 !== 92`.
- After the fix the paid parent-quantity child contributes `$16`, selected paid
  quantity child contributes `$36`, and the two free selections contribute `$0`.
  Parent `$20 × 2` plus these children is `$92`.
- Registry exports only scalar catalog price/type/slug/label/disabled metadata;
  it does not serialize WooCommerce product objects. Backend parent pricing
  remains separate from the native child lines.

## Verification

Focused PHPUnit: **147 tests, 813 assertions**. Adds 32 classic/Store API
fixed/none × eight subtype cases covering validation, capture, child insertion,
prices, order markers and order-again remapping, plus 16 Tools/WXR/archive
round trips.

Full PHPUnit: **747 tests, 3325 assertions; no test failures**, one existing test
runner deprecation for doc-comment metadata in `CapabilityFixtureRegistryTest`.
Run with this worktree's own copied, ignored `vendor` directory so Composer's
class map resolves this worktree's implementation. A manually selected mixed
renderer subset collides with existing global WordPress helper stubs; standalone
renderer files also require helpers from the full suite. Neither subset result
is reported as a passing renderer gate.

JS: **44 Node tests pass**, including the 25 new product-price cases, adjacent
pricing/per-unit/calc/event cases and the existing conditional script (which
reports 15 checks). Syntax checks and `git diff --check` pass.

Disposable runtime phases:

- `setup`: fresh products/category, four pricing configurations and checkout.
- `reference`: **322 checks** across `fixed`/`none` × eight subtypes. Native
  source comparison, actual Tools/WXR `Importer::run(true)` writes and stored
  reads, actual ArchiveImporter writes and stored reads, archive site-local
  category review/draft guard, registry metadata, deletion of owned imports.
- Main browser: **33 checks**. Authenticated builder saves/reloads all four price controls;
  live paid/free category rendering, pre-submit `$92`, native classic add and
  Store API add, five cart lines, four child markers, two free lines, real classic
  checkout and real Store API checkout, order confirmation and no page errors.
- `verify`: **43 checks** on actual checkout-created orders, totals, child line
  amounts/types, order metadata, validation and reconstruction/remapping.
- `stock-refund`: **13 checks**. These fixtures initially use unmanaged stock;
  the phase enables stock at 50, clears their stock-reduced marker and invokes
  native WooCommerce reduction. Paid and free children reduce stock by the same
  quantity. Native refunds include all four child lines (including zero-price
  lines); restocking returns both children to 50. This is a native stock lifecycle
  proof, not a claim that initial checkout reduced unmanaged stock.
- `reorder-setup` and actual authenticated browser Order again: completed owned
  checkout orders expose WooCommerce's real link; clicking the native endpoint
  rebuilds five lines and `$92`, preserving four children and two free lines.
  **9 browser checks** pass.
- `cleanup`: only owned fixture records are deleted; count depends on the saved
  owned-order journal, which also retains IDs from already-cleaned prior runs.

Raw JSON and screenshots: `/tmp/opf-category-price-evidence`.

## Reproduce

From the isolated plugin checkout, with the fresh disposable clone installed
and serving on 8423 and the fresh admin password in the mode-0600 fixture file:

```sh
php vendor/bin/phpunit
node --test tests/js/opf-products-pricing.test.cjs tests/js/opf-pricing-per-unit.test.cjs tests/js/opf-calc-field.test.cjs tests/js/opf-pricing-event.test.cjs tests/js/opf-qty-card-conditionals.test.cjs
OPF_CATEGORY_PRICE_ALLOW=1 OPF_CATEGORY_PRICE_PHASE=setup wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-category-product-price.php
OPF_CATEGORY_PRICE_ALLOW=1 OPF_CATEGORY_PRICE_PHASE=reference OPF_WAPF_SOURCE_PATH=/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-category-product-price.php
OPF_PLAYWRIGHT_ROOT=/home/followersya-5hqi7/followersya.com node bin/e2e-category-product-price-browser.mjs
OPF_CATEGORY_PRICE_ALLOW=1 OPF_CATEGORY_PRICE_PHASE=verify wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-category-product-price.php
OPF_CATEGORY_PRICE_ALLOW=1 OPF_CATEGORY_PRICE_PHASE=stock-refund wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-category-product-price.php
OPF_CATEGORY_PRICE_ALLOW=1 OPF_CATEGORY_PRICE_PHASE=reorder-setup wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-category-product-price.php
OPF_PLAYWRIGHT_ROOT=/home/followersya-5hqi7/followersya.com node bin/e2e-category-product-price-order-again.mjs
OPF_CATEGORY_PRICE_ALLOW=1 OPF_CATEGORY_PRICE_PHASE=cleanup wp --path=/tmp/opf-category-price-wp eval-file bin/e2e-category-product-price.php
```

The fixture refuses writes unless its exact disposable path and explicit opt-in
match. No public push or production deployment belongs to this package.
