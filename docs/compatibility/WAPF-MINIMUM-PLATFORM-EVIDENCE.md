# Minimum platform evidence

Original audit: 2026-10-01 UTC against OPF commit
`9171b5c9ea271602d06c5e00c02b42742fc40627` in an isolated worktree.
Runtime files and version headers were not changed by that audit. The cache
implementation follow-up below records later changes from base `363fd67`.
`WAPF-COMPAT-MINIMUM-PLATFORM` is an owner-accepted documented difference
(owner decision D2, 2026-10-05): the accepted floor is **WordPress 6.5 /
PHP 7.4 / WooCommerce 9.0**, and the older “gap” reading recorded in the
sections below is withdrawn. Nothing below about WAPF's lower paid floor is a
queued target — it is version-bounded evidence kept for the record.

## OPF's declared floor: PHP 7.4 / WordPress 6.5 / WooCommerce 9.0

**The original audited commit did not satisfy its PHP 7.4 declaration.** Native PHP
7.4.33 lint checked all 31 shipped PHP files: the plugin entry point,
`uninstall.php`, and 29 files under `includes/`. Exactly one file failed:

| File and lines | Confirmed failure | Smallest correction to investigate |
| --- | --- | --- |
| `includes/Service/Rest.php:90,121` | `save_group()` and `preview()` use `WP_REST_Response\|WP_Error` union return types. PHP 7.4 reports `unexpected '\|'` at line 90; union types require PHP 8.0. `opf_boot()` calls `Rest::init()`, so this prevents normal boot with WooCommerce present. | Remove both PHP union annotations and retain their contract in PHPDoc; verify successful and error REST responses. |

The remaining PHP 7.4 investigation supports a narrow repair, but does not
certify a working store. A read-only Docker probe removed those **two** return
annotations in memory, then loaded all 28 include files besides the autoloader.
It accepted a valid empty archive, rejected malformed JSON with the intended
message, and exercised WordPress 6.5's `str_contains()` polyfill. No source file
was rewritten. Named global-call inventory against PHP 7.4 built-ins and the
downloaded WordPress 6.5/WooCommerce 9.0 definitions found no further missing
unguarded global function. Optional Polylang/WPML function calls are guarded.
This inventory checks symbol availability, not every signature or execution path.

The exact declared-floor sources contain the other APIs checked:

| OPF use | WordPress 6.5 / WooCommerce 9.0 source |
| --- | --- |
| `FieldGroups.php:309`, `wp_cache_flush_group()` | Present in WordPress `wp-includes/cache.php:298`. Cache implementations still require a `wp_cache_supports('flush_group')` capability check before use. |
| `Assets.php:29,54`, script module registration/enqueue | Present in WordPress 6.5; OPF guards both calls and already has classic-script branches. |
| `Assets.php:103`, `str_contains()` | WordPress 6.5 `wp-includes/compat.php:468` supplies the PHP 8 function on PHP 7.4. |
| `CartIntegration.php:49`, `woocommerce_store_api_add_to_cart_data` | Present in WooCommerce 9.0 `src/StoreApi/Routes/V1/CartAddItem.php:119`. |
| Guarded `FeaturesUtil::declare_compatibility()` | `src/Utilities/FeaturesUtil.php` exists in WooCommerce 9.0. |

WooCommerce 9.0's own header requires PHP 7.4 and WordPress 6.4, so OPF's
declared PHP 7.4/WordPress 6.5/WooCommerce 9.0 combination is consistent with
those dependency declarations. Full activation, REST requests, browser output,
classic/Store API cart, checkout, order storage, and order-again on that exact
stack were **not run**. `Rest.php` is the sole observed PHP 7.4 **parse** failure;
it is not evidence that all runtime behavior will pass after the repair.

## WAPF edition-floor parity

The paid [product page](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/)
currently lists WordPress 6.0+, WooCommerce 7.0+, and PHP 7.1+. OPF excludes
these environments in both its entry-point and readme headers; Composer also
requires PHP 7.4. No declaration was lowered during this audit.

Native PHP 7.1.33 lint checked the same 31 files and rejected six:

| File | All observed incompatible syntax locations |
| --- | --- |
| `includes/API.php` | Arrow function at line 203. |
| `includes/Engine/Calculator.php` | Typed property at line 22; arrow functions at 335,338,341,364,371–378. |
| `includes/Engine/FieldGroup.php` | Typed property at line 35. |
| `includes/Service/CartIntegration.php` | Arrow function at line 745. |
| `includes/Service/Rest.php` | Arrow functions at 38,43,60; union return types at 90,121. |
| `includes/Service/WapfExporter.php` | Arrow functions at 250,320. |

Arrow functions and typed properties require PHP 7.4. Native lint stops at
each file's first failure; the table adds the remaining source locations.
[PHP 7.4 features](https://www.php.net/manual/en/migration74.new-features.php)
and [PHP 8.0 features](https://www.php.net/manual/en/migration80.new-features.php)
document these language boundaries.

Additional paid-floor incompatibilities survive a syntax-only backport:

| Runtime file/API | Evidence and consequence |
| --- | --- |
| `ArchiveImporter.php:25–27`, `JSON_THROW_ON_ERROR` / `JsonException` | Both require PHP 7.3. On PHP 7.1 a valid empty OPF archive produces `json_decode() expects parameter 4 to be integer, string given`, then incorrectly raises `OPF archive format or version is unsupported.` |
| `FieldGroups.php:309`, `wp_cache_flush_group()` | Absent from WordPress 6.0; introduced in 6.1. Loading the actual WordPress 6.0 cache source and calling `FieldGroups::flush_cache()` reproduced `Call to undefined function OPF\Service\wp_cache_flush_group()`. This method runs after group saves and duplicates. |
| `CartIntegration.php:49–90`, `woocommerce_store_api_add_to_cart_data` | Entire WooCommerce 7.0 package contains no such hook. Its bundled Blocks `StoreApi/Utilities/CartController.php:46–59` consumes an array; `filter_request_data():1111–1116` invokes the classic `woocommerce_add_cart_item_data` filter but supplies no `WP_REST_Request`. OPF's request capture hook therefore cannot run. JSON `opf_fields` needs an older-route adapter to reach validation, persistence, and pricing. This is source evidence, not an executed checkout result. |

Two suspected incompatibilities are already covered: WordPress 6.0's
`compat.php:433` polyfills `str_contains()` (since 5.9), and OPF guards its
WordPress 6.5 script module APIs. WooCommerce 7.0 lacks `FeaturesUtil`, but OPF's
class-existence guard covers that optional declaration. Its classic order-line,
order-again, and `woocommerce_get_item_data` hooks are present.

**Dependency floor conflict:** WooCommerce 7.0.0 itself declares PHP **7.2**.
Consequently the paid marketing PHP 7.1/WooCommerce 7.0 minima cannot form one
dependency-compliant test stack. Preserve this distinction: test OPF's PHP 7.1
language/API behavior separately, and test WordPress 6.0/WooCommerce 7.0 on at
least PHP 7.2. This does not authorize changing the requested parity target.

WAPF Free 1.7.1's downloaded package declares WordPress 4.5 and PHP 7.0 in
`readme.txt`; its plugin header declares WooCommerce 6.0. Its FAQ separately
says WordPress 6.0. These conflicting WordPress claims need explicit treatment.
OPF's entry point already uses PHP 7.1 `void` return types, and runtime files
also use nullable types and visible class constants, so removing only the six
PHP 7.4 syntax blockers would still not establish Free's PHP 7.0 parity.
WordPress 4.5, PHP 7.0, and WooCommerce 6.0 integration were not executed here.

## Reproduction and isolation

Docker 29.8.2 was reachable after sandbox escalation. Both official CLI images
pulled and executed successfully. Every audit container used `--rm`, disabled
networking, a read-only root filesystem, dropped capabilities, and mounted
only `/tmp` audit/source directories read-only. No production filesystem,
database, existing container, or public port was used. The two downloaded
images remain cached; all audit containers were disposable.

Run from the isolated checkout; repeat with `php:7.4-cli`. The script counts
all files, prints failures, and exits nonzero on a failed syntax gate:

```sh
sudo -n docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges \
  --mount "type=bind,src=$PWD,dst=/audit,readonly" -w /audit php:7.1-cli \
  php -r '
  echo "PHP ",PHP_VERSION,"\n";
  $files = array("open-product-fields-for-woocommerce.php", "uninstall.php");
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("includes")) as $file) {
    if ($file->isFile() && $file->getExtension() === "php") $files[] = $file->getPathname();
  }
  sort($files); $failed = 0;
  foreach ($files as $file) {
    $lines = array(); exec("php -l ".escapeshellarg($file)." 2>&1", $lines, $code);
    if ($code) { echo implode("\n",$lines),"\n"; $failed++; }
  }
  echo "TOTAL ",count($files)," FAILED ",$failed,"\n"; exit($failed ? 1 : 0);'
```

Source archives downloaded from the following authoritative URLs; SHA-256
identifies the exact bytes inspected:

| Source | SHA-256 |
| --- | --- |
| [WordPress 6.0](https://wordpress.org/wordpress-6.0.tar.gz) | `1887f3223db01d6d888df77ea0b3b8dde4c6243961a6b486d1bee1be9324bf08` |
| [WordPress 6.5](https://wordpress.org/wordpress-6.5.tar.gz) | `342456288ae148c0ba8adc36cbf58951dd8b491b6755692654efdbda71092b9f` |
| [WooCommerce 7.0.0](https://downloads.wordpress.org/plugin/woocommerce.7.0.0.zip) | `b746903d8ee4e5290ccd89b577230abef46a10376909c6829f2e4836860402cd` |
| [WooCommerce 9.0.0](https://downloads.wordpress.org/plugin/woocommerce.9.0.0.zip) | `e36632495c76a883040881262501033d99271ed032ff8bb7027c086af174d8ac` |
| [WAPF Free 1.7.1](https://downloads.wordpress.org/plugin/advanced-product-fields-for-woocommerce.1.7.1.zip) | `c51ad76eb88f0095704bcf6b0a7a9b12d3d18263028121d276bff96ea70f9171` |

PHP image manifest digests were
`php:7.1-cli@sha256:e0e6ced092bbf073e4f3e48545a8e1a2592217b3346ef0cb8b18ecacf8ae1522`
and
`php:7.4-cli@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5`.
The [WordPress cache reference](https://developer.wordpress.org/reference/functions/wp_cache_flush_group/)
and [PHP JSON constants reference](https://www.php.net/manual/en/json.constants.php)
confirm the missing API introduction versions.

## WordPress 6.0 cache implementation follow-up — 2026-10-01

`FieldGroups::flush_cache()` now clears request-local source groups and invokes
`wp_cache_flush_group('opf_groups_for_product')` only when both that function
and `wp_cache_supports()` exist and the drop-in reports `flush_group` support.
The same capability check gates persistent product-result reads and writes.
WordPress 6.0 and unsupported/legacy drop-ins therefore ignore old persistent
OPF entries instead of trying to clear unrelated caches. Saves and the
duplicate/save path rebuild current groups; no global cache flush is used.

The tradeoff is deliberate: unsupported caches no longer persist OPF's matched
product results. Request-local source groups remain cached, but placement
matching repeats on subsequent calls. Supported caches retain their existing
persistent reads/writes and scoped group flush. Old entries are left untouched
and ignored while group flushing is unavailable.

Regression tests first reproduced the missing-function fatal and two unsafe
drop-in calls. Four isolated cases now pass: absent WordPress 6.0 APIs, a
drop-in reporting no group support, a legacy drop-in lacking the capability
API, and supported group flushing. They seed stale cached results, perform a
save and duplicate/save, verify fresh results and request-local reuse, and
preserve a foreign cache group. The unsupported cases prove zero persistent
OPF result reads/writes; a global cache flush fails the probe immediately.
These are cache API contract fakes, not executed commercial cache backends.

The same standalone probe loaded the actual downloaded WordPress 6.0 cache
source inside PHP 7.4.33 Docker. Both APIs were absent; the save returned
`Saved group`, the duplicate returned `Saved group`/`Copied group`, the stale
entry remained ignored, and the foreign entry stayed `untouched`. With actual
WordPress 6.5 cache source, supported group flushing removed the stale entry
and preserved the foreign one. WordPress post reads/writes are fixture
implementations in these probes; this is not a database-backed floor install.

```sh
php tests/fixtures/group-cache-floor.php missing /path/to/wordpress-6.0
php tests/fixtures/group-cache-floor.php supported /path/to/wordpress-6.5
vendor/bin/phpunit --filter 'FieldGroupsCacheInvalidationTest|FieldGroupsAuthTest|FieldGroupsCacheKeyTest|WpmlIntegrationTest'
composer test
```

Focused checks passed: 17 tests / 130 assertions. The complete Composer suite
passed: 222 tests / 879 assertions on PHP 8.5.11, with one existing PHPUnit
doc-comment metadata deprecation in `CapabilityFixtureRegistryTest`. Native
PHP 7.4.33 also parsed all 31 shipped PHP files; base `363fd67` already removed
the original REST union annotations. No platform declaration changed. Full
WP/Woo floor installation, external cache backends, browser/commerce lifecycle,
and the other PHP 7.1/Free/WooCommerce 7.0 gaps remain unverified.

## Current-branch PHP 7.4 syntax recheck — 2026-10-01

At OPF `4d9c6e20458933df09a1aef33964c3125aae8cfa`, the native PHP 7.4.33
CLI parsed all 34 shipped PHP files with zero syntax errors. This run includes
runtime PHP under `includes/`, the plugin entry point, `uninstall.php`, and
the shipped `assets/` and `languages/` PHP guards; it excludes development
scripts, tests, and `vendor/`. PHP 7.4.33 CLI/common packages were downloaded
from the configured Ubuntu 22.04 package source and extracted under
`/tmp/opf-php74/`; no system PHP packages or production files were changed.
The recheck confirms syntax only. It does not replace the exact PHP 7.4 / WP
6.5 / WooCommerce 9.0 activation, REST, browser, cart, checkout, order, and
order-again lifecycle matrix in closure step 1.

After URL lifecycle code was integrated, native PHP 7.4.33 lint was repeated
at OPF `a45fcfcaaffede599f8a2226aa86f5122c8dc8ef`: the same 34 shipped PHP
files again parsed with zero syntax errors. This covers the URL implementation
and its compatibility-audit integration, but remains a syntax-only result.

Reproduction from the plugin checkout:

```sh
find . -path './vendor' -prune -o -path './tests' -prune -o -path './bin' -prune -o -type f -name '*.php' -print0 \
  | xargs -0 -n1 /tmp/opf-php74/root/usr/bin/php7.4 -l
```

## PHP 7.1 archive JSON follow-up — 2026-10-01

This slice started from public branch `feat/opf-archive-import` at
`8df76b44e28c72023d833138b22b6617ac4285a1`, verified with:

```sh
git ls-remote https://github.com/networkersoss/open-product-fields-for-woocommerce.git refs/heads/feat/opf-archive-import
```

`ArchiveImporter::decode()` now uses `json_decode()` plus immediate
`json_last_error()` checking when `JSON_THROW_ON_ERROR` is unavailable. PHP
7.3+ retains its existing throwing decode and chained `JsonException`. The
fallback reports the same public `InvalidArgumentException` message for invalid
JSON. Neither path changes the depth limit, archive schema checks, size limit,
group normalization, or writes. Headers and Composer still declare PHP 7.4,
WordPress 6.5, and WooCommerce 9.0.

The standalone `tests/fixtures/archive-json-floor.php` probe loads the real
importer and converts PHP warnings to exceptions. Before the repair, native PHP
7.1.33 passed only the oversized-input case; the other 11 cases failed with
`Use of undefined constant JSON_THROW_ON_ERROR`. After the repair, all 12 cases
pass on PHP 7.1.33, PHP 7.4.33, and PHP 8.5.11: valid empty archive with Unicode
scope metadata, truncated/trailing/empty JSON, invalid UTF-8 and UTF-16,
excessive depth, JSON null/scalar, unsupported format version, oversized input,
and valid decode after invalid input. Modern runs also preserve the chained
`JsonException` and successful decoding after an unrelated stale JSON error.

```sh
sudo -n docker run --rm --network none --read-only --cap-drop ALL \
  --security-opt no-new-privileges \
  --mount "type=bind,src=$PWD,dst=/audit,readonly" -w /audit php:7.1-cli \
  php tests/fixtures/archive-json-floor.php
# Repeat with php:7.4-cli; both: TOTAL 12 PASSED 12 FAILED 0.
php tests/fixtures/archive-json-floor.php
/tmp/opf-archive-import/vendor/bin/phpunit -c phpunit.xml.dist --filter ArchiveImporterTest
/tmp/opf-archive-import/vendor/bin/phpunit -c phpunit.xml.dist
```

Focused PHPUnit proof: 6 tests / 16 assertions. Full suite: 246 tests / 1001
assertions on PHP 8.5.11, with one pre-existing PHPUnit metadata deprecation.
PHP 7.4.33 parsed all 34 shipped PHP files. PHP 7.1.33 still rejects six:
`API.php:203`, `Calculator.php:22`, `FieldGroup.php:35`,
`CartIntegration.php:752`, `Rest.php:38`, and `WapfExporter.php:248`.
The official PHP image digests remain those recorded above.

The probe deliberately uses empty groups, so it does not load `FieldGroup`'s
incompatible typed property. This proves the archive JSON API correction on
PHP 7.1, not populated-group import, plugin boot, or a WP/Woo lifecycle. The
six-file syntax backport and all exact-stack commerce proof remain open.
Those gaps are version-bounded and unqueued: see the accepted-floor note at the
top of this document.

Published requirements were rechecked against the official pages on
2026-10-01 UTC. The shared
[Pro/Extended product page](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/)
displays version 3.2.2 and still specifies PHP 7.1, WordPress 6.0, and
WooCommerce 7.0. This published requirement check does not replace the separate
Extended 3.1.5 source audit or imply that 3.2.2 source was acquired.
[Free's official listing](https://wordpress.org/plugins/advanced-product-fields-for-woocommerce/)
shows version 1.7.1, WordPress 4.5, and PHP 7.0; its FAQ still says WordPress
6.0 and WooCommerce 6.0. The previously downloaded Free 1.7.1 and WooCommerce
7.0.0 archive hashes were reverified against the values above. WooCommerce
7.0.0's actual header still requires PHP 7.2, so the paid published minima
continue to need separate PHP 7.1 language/API and PHP 7.2+/WP 6.0/Woo 7.0
integration proof.

## Remaining closure steps

1. The PHP 7.4 parse gate now passes after base `363fd67` removed the two REST
   return annotations. Run actual activation, REST save/preview success/error, storefront,
   classic/Store API cart, checkout, order metadata, and order-again on
   PHP 7.4/WordPress 6.5/WooCommerce 9.0. This establishes OPF's own floor first.
2. PHP 7.1.33 now parses all 36 shipped PHP files. The archive JSON dependency
   has empty-archive runtime proof; extend it to populated archives. Formula
   callbacks and repeater/cart sanitization have isolated PHP 7.1 equivalence
   probes, but prove populated archives, full plugin boot, and a supported
   WordPress/WooCommerce lifecycle with a floor-compatible harness. PHPUnit 11
   development dependencies require PHP 8.2, so the existing development
   lockfile is not a PHP 7.1/7.4 test harness.
3. Cache invalidation now passes the WordPress 6.0/6.5 source and scoped
   cache-contract probes above. Extend proof to actual persistent cache
   backends and a full floor-stack install, retaining viewer/language isolation.
4. Add a request-scoped WooCommerce 7.0 Store API capture adapter using the
   actual old route lifecycle. Prove JSON input, validation, server pricing,
   cart display, checkout storage, and cleanup between requests; retain the
   newer hook path for WooCommerce 9.0.
5. Build an isolated database-backed floor matrix with disposable containers,
   an internal network, and fresh test-only data. No database-backed WP/Woo
   Docker stack was provisioned in this audit. Resolve Free's older declaration
   conflict and audit its additional syntax/APIs before claiming edition parity.
   Change headers/Composer and ledger status only after the selected floors and
   required lifecycle behavior are demonstrated.

## PHP 7.1 syntax follow-up — 2026-10-02

Public branch `c75fa2e` replaced the order snapshot callback in `API.php` and
three permission callbacks in `Service/Rest.php` with equivalent static
closures. Actual PHP 7.1.33 lint now accepts both files. The REST lane compared
registered routes and allowed/denied permissions before and after the change;
all serialized outputs match byte-for-byte on PHP 8.5 and PHP 7.1. The focused
and full PHPUnit suites pass (276 tests / 1,070 assertions; one existing
metadata deprecation).

A fresh PHP 7.1.33 lint of all 36 shipped PHP files confirms four remaining
syntax failures: `Engine/Calculator.php:22`, `Engine/FieldGroup.php:35`,
`Service/CartIntegration.php:848`, and `Service/WapfExporter.php:248`. These
contain typed properties or arrow functions. PHP 7.1 platform parity remains
open; this syntax-only result does not prove PHP 7.1 WordPress/WooCommerce
activation or a commerce lifecycle.

## WAPF exporter PHP 7.1 syntax follow-up — 2026-10-02

Public commit `892d7e8` replaced the two static arrow callbacks in
`Service/WapfExporter.php` with static closures. The changed file passes actual
PHP 7.1.33 lint. Focused exporter/mapper tests pass (53 tests / 231 assertions)
and the full suite passes (276 tests / 1,070 assertions, with one existing
metadata deprecation). A 24-case full export/import probe and an 18-case direct
mapper probe were byte-identical before and after, including matching failures
and zero warnings.

A fresh lint of all 36 shipped PHP files now leaves three syntax failures:
`Engine/Calculator.php:22`, `Engine/FieldGroup.php:35`, and
`Service/CartIntegration.php:848`. This remains syntax-only evidence; the
PHP 7.1 runtime and WAPF platform row are not yet proven.

## FieldGroup PHP 7.1 syntax follow-up — 2026-10-02

Public commit `c4600d4` removes the typed property declaration from
`Engine/FieldGroup.php`; its array PHPDoc, normalizing constructor, and internal
array assignments remain. Actual PHP 7.1.33 lint passes. Focused schema/upload
tests pass (34 tests / 83 assertions), and the full suite passes (276 tests /
1,070 assertions, with one existing metadata deprecation). A five-fixture
normalization/serialization probe, including upload defaults and settings, is
byte-identical on PHP 8.5 before/after and PHP 7.1 after.

A fresh lint of all 36 shipped PHP files now leaves two syntax failures:
`Engine/Calculator.php:22` and `Service/CartIntegration.php:848`. The removed
runtime property type enforcement is a small internal type-safety loss; current
constructor and in-repository assignment paths pass arrays. PHP 7.1 platform
parity remains open pending these failures and full runtime/commerce-floor proof.

## PHP 7.1 full syntax gate — 2026-10-02

Public commits `ce0dd53` and `b838d86` backport the calculator and repeater
cart callbacks. Actual PHP 7.1.33 now parses all **36/36 shipped PHP files**.
The calculator's 720 finite formula/price outputs and nine explicit edge
oracles match byte-for-byte on PHP 8.5 before/after and PHP 7.1 after. The
CartIntegration adapter's 12 checks also match byte-for-byte on PHP 8.5
before/after and PHP 7.1 after. The complete PHP 8.5 PHPUnit suite passes
(278 tests / 1,095 assertions; one existing metadata deprecation).

This closes the parser gate only. PHP 7.1 has not booted the whole plugin in a
supported WordPress/WooCommerce stack, populated archive import has not been
proved there, and WooCommerce 7.0 itself requires PHP 7.2. The platform parity
ledger row is an owner-accepted documented difference (D2, 2026-10-05), not a
gap: the declared floor has executable activation, REST, browser,
cart/checkout, order, and order-again evidence, while the marketed lower floors
stay version-bounded and unqueued.
