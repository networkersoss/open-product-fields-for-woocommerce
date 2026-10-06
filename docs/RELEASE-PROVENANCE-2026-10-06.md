# OPF release provenance and package audit — 2026-10-06

Release-readiness (G4) provenance record for the Open Product Fields for
WooCommerce plugin. It documents the reproducible build, the exact shipped-file
inventory, bundled third-party notices, runtime dependency posture, license
declarations, version metadata, the archive checksum manifest, and the
internal-document exclusion fix.

All facts below were produced by running the commands shown against this
repository on 2026-10-06. Absolute artifact paths under `/tmp` are build
scratch space only; no source file lives there.

- Repository: `open-product-fields-for-woocommerce` (branch `master`)
- Audited commit: `521ff0c` (`fix(export): round-trip WAPF switch_control for
  toggle and checkbox fields`)
- Build host PHP: `8.5.11` (the shipped code declares `Requires PHP: 7.4`)
- Archive version inspected: **0.1.0** (this is the current baseline, not a 1.0
  release candidate)

## 1. Reproducible build command

Run from the plugin root:

```sh
bash bin/build.sh /tmp/opf-dist/open-product-fields-for-woocommerce
```

`bin/build.sh` emits a clean plugin **directory tree** (there is no zip/tarball
step). The command is idempotent: it removes the destination, recreates it, and
rsyncs the plugin with development artifacts excluded.

## 2. Shipped-file inventory (0.1.0 baseline archive)

| Metric | Value |
| --- | --- |
| Total files | **200** |
| Total bytes (`du -sb`) | **2,587,988** |
| Top-level entries | 10 (`assets/`, `includes/`, `languages/` + 6 files) |

Files by top-level location:

| Location | Files |
| --- | --- |
| `includes/` | 177 (of which 124 are `includes/ThirdParty/Url/`; 53 are plugin-authored) |
| `assets/` | 12 (3 CSS, 7 JS, 2 `index.php` guards) |
| `languages/` | 5 (`index.php`, `.pot`, `.po`, `.mo`, `es_ES` JSON) |
| root files | 6 (`LICENSE`, `CHANGELOG.md`, `readme.txt`, `uninstall.php`, `wpml-config.xml`, `open-product-fields-for-woocommerce.php`) |

Files by extension: `.php` 176, `.js` 7, `.css` 3, `LICENSE` 7 (root + 6
third-party), `.md` 1, `.json` 1, `.xml` 1, `.txt` 1, `.pot` 1, `.po` 1, `.mo` 1.

Excluded from the archive (verified absent): `.git/`, `.gitignore`,
`.phpunit.result.cache`, `vendor/`, `tests/`, `bin/`, `docs/`, `tasks/`,
`composer.json`, `composer.lock`, `phpunit.xml.dist`. No file matching
`*phpunit*` ships.

`LICENSE` (GNU GPL v2, 17,984 bytes) is present at the archive root.

## 3. Bundled third-party components (`includes/ThirdParty/Url/`)

The WHATWG URL parser stack is vendored (scoped with php-scoper, autoloaded by
`includes/ThirdParty/Url/loader.php`; no Composer at runtime). Versions are the
ones pinned by `bin/vendor-url-parser.sh`. Every component ships its own license
file inside the archive.

| Component | Pinned version | Shipped LICENSE path | Declared license |
| --- | --- | --- | --- |
| brick/math | 0.9.3 | `includes/ThirdParty/Url/brick/math/LICENSE` | MIT |
| rowbot/idna | 0.1.5 | `includes/ThirdParty/Url/rowbot/idna/LICENSE` | MIT |
| rowbot/punycode | 1.0.4 | `includes/ThirdParty/Url/rowbot/punycode/LICENSE` | MIT |
| rowbot/url | 3.1.7 | `includes/ThirdParty/Url/rowbot/url/LICENSE` | MIT |
| symfony/polyfill-intl-normalizer | 1.31.0 | `includes/ThirdParty/Url/symfony/polyfill-intl-normalizer/LICENSE` | MIT |
| symfony/polyfill-mbstring | 1.31.0 | `includes/ThirdParty/Url/symfony/polyfill-mbstring/LICENSE` | MIT |

Notes:

- brick/math and the rowbot packages carry the literal "MIT License" title;
  the two Symfony polyfills carry the MIT body ("Permission is hereby granted,
  free of charge…") under a Fabien Potencier copyright line.
- All six MIT licenses are permissive and compatible with the plugin's
  GPL-2.0-or-later declaration.
- No separate third-party JS/CSS payloads are bundled: the 12 `assets/` files
  are plugin-authored, none carries a third-party license banner, and the only
  `jQuery` references are use of the host page's WordPress/WooCommerce jQuery
  rather than a bundled copy.

## 4. Dependency audit — `composer.json` runtime vs development

`composer.json` declares:

- `require`: `{ "php": ">=7.4" }` — **no runtime library dependencies**.
- `require-dev`: `phpunit/phpunit ^11.0`, `php-stubs/wordpress-stubs ^7.1.0`,
  `php-stubs/woocommerce-stubs ^11.1.2`.

`composer.lock` confirms this: `packages` (runtime) is **empty**; `packages-dev`
holds PHPUnit, `php-stubs/*`, and the Sebastian/phpunit transitive set. All dev
packages are MIT/BSD-licensed and are excluded from the archive (`vendor/` is
not shipped). The only code a production install runs beyond the plugin itself
is the vendored MIT URL stack in section 3.

## 5. License declaration — GPL-2.0-or-later

| Surface | Exact declaration |
| --- | --- |
| Archive `LICENSE` | GNU General Public License, Version 2, June 1991 |
| Plugin header `open-product-fields-for-woocommerce.php` | `License: GPL-2.0-or-later` + `License URI: https://www.gnu.org/licenses/gpl-2.0.html`, and the body grant "either version 2 of the License, or (at your option) any later version" |
| `readme.txt` | `License: GPLv2 or later` + `License URI: https://www.gnu.org/licenses/gpl-2.0.html` |
| `composer.json` | `"license": "GPL-2.0-or-later"` |

All four surfaces agree on GPL-2.0-or-later.

## 6. Version metadata alignment

| Surface | Value |
| --- | --- |
| Plugin header `Version:` | `0.1.0` |
| `OPF_VERSION` constant | `0.1.0` |
| `readme.txt` `Stable tag:` | `0.1.0` |

The three version surfaces are aligned at `0.1.0` on the audited commit. (The
roadmap noted a transient local edit to `0.1.1`; that edit is not present in the
audited working tree.)

## 7. SHA-256 archive manifest

Because the build emits a directory rather than a zip, the manifest is a
per-file SHA-256 list in `sha256sum` format with paths relative to the archive
root.

- Manifest: [`RELEASE-PROVENANCE-2026-10-06-manifest.sha256`](RELEASE-PROVENANCE-2026-10-06-manifest.sha256)
  (200 lines, one per shipped file)
- Manifest file SHA-256:
  `7b987a7645ea12c69c730214dd204deeda37368f596801b9196aee29c18a019d`
- `bin/build.sh` (fixed) SHA-256:
  `28e7b2cc4bd819b951d219a10339519c009a7a650ecfa96bb5170be290718b18`

Verification from a clean checkout:

```sh
bash bin/build.sh /tmp/opf-dist/open-product-fields-for-woocommerce
cd /tmp/opf-dist/open-product-fields-for-woocommerce
sha256sum -c /path/to/repo/docs/RELEASE-PROVENANCE-2026-10-06-manifest.sha256
# -> all 200 files OK
```

## 8. Internal-document exclusion fix

`bin/build.sh` excluded `docs/` and `bin/` but not `tasks/`, so the internal
planning notes `tasks/plan.md` and `tasks/todo.md` were being shipped inside the
release archive. Fixed with a single scoped rsync exclude in `bin/build.sh`:

```sh
--exclude='tasks/' \
```

Observed effect (before → after), with nothing else changing:

- Archive: 202 files → **200 files**
- File-list diff: only `./tasks/plan.md` and `./tasks/todo.md` removed
- Reproduced by rebuilding with the pre-fix `HEAD:bin/build.sh` (202 files,
  `tasks/` present) and with the fixed script (200 files, `tasks/` absent).

## 9. Evidence docs that cite `/tmp/...` artifact paths (not reproducible from a clean checkout)

The G4 ledger records that evidence docs whose artifacts were staged under
`/tmp` are unreproducible by construction. The list below was produced by
grepping the repository, not by hand:

```sh
grep -l '/tmp/' docs/compatibility/*.md
```

**105** top-level `docs/compatibility/*.md` files cite `/tmp/...` paths (one more
nested file, `docs/compatibility/wapf-reference-proof-20261005/README.md`, also
matches, but that directory is the committed-artifact set that fixed
reproducibility for its rows):

```text
docs/compatibility/ADMIN-CHOICE-EVIDENCE-2026-10-03.md
docs/compatibility/AELIA-REAL-PLUGIN-EVIDENCE-2026-10-03.md
docs/compatibility/CALCFIELD-EVIDENCE-2026-10-03.md
docs/compatibility/CART-EDIT-EVIDENCE-2026-10-03.md
docs/compatibility/CART-ORDER-LIFECYCLE-EVIDENCE-2026-10-02.md
docs/compatibility/CATEGORY-PRODUCT-PRICE-EVIDENCE-2026-10-04.md
docs/compatibility/CHILD-PRODUCT-IMAGE-ZOOM-EVIDENCE-2026-10-04.md
docs/compatibility/CHILD-PRODUCTS-BUILDER-ROUNDTRIP-2026-10-04.md
docs/compatibility/CHILD-PRODUCTS-COMMERCE-EDGES-2026-10-04.md
docs/compatibility/CHILD-PRODUCTS-EVIDENCE-2026-10-03.md
docs/compatibility/CHILD-PRODUCTS-IMPORT-EXPORT-AUDIT-2026-10-04.md
docs/compatibility/CHILD-PRODUCTS-QUERY-AUDIT-2026-10-04.md
docs/compatibility/CHILD-STORE-API-WAPF-COACTIVE-EVIDENCE-2026-10-04.md
docs/compatibility/CHILD-VARIATION-RELATIVE-EVIDENCE-2026-10-04.md
docs/compatibility/CHOICE-BULK-IMPORT-EVIDENCE-2026-10-01.md
docs/compatibility/CHOICE-COLUMNS-CASCADE-EVIDENCE-2026-10-05.md
docs/compatibility/COMMERCE-TAX-CURRENCY-EVIDENCE-2026-10-03.md
docs/compatibility/COMMERCE-TAX-REPROOF-2026-10-03.md
docs/compatibility/CONTENT-HTML-STOREFRONT-EVIDENCE-2026-10-02.md
docs/compatibility/CONTENT-IMAGE-STOREFRONT-PROOF.md
docs/compatibility/CONTENT-TEXT-STOREFRONT-EVIDENCE-2026-10-03.md
docs/compatibility/CURRENCY-REAL-RUNTIME-EVIDENCE-2026-10-03.md
docs/compatibility/DATE-FIELDS-EVIDENCE-2026-10-03.md
docs/compatibility/DATE-IMPORT-EXPORT-EVIDENCE-2026-10-03.md
docs/compatibility/DISABLED-CHOICE-PROGRESS.md
docs/compatibility/DISABLED-COMMERCE-ROUNDTRIP-EVIDENCE.md
docs/compatibility/DISPLAY-REPROOF-2026-10-03.md
docs/compatibility/DISPLAY-SETTINGS-EVIDENCE-2026-10-03.md
docs/compatibility/DISPLAYFIX-EVIDENCE-2026-10-03.md
docs/compatibility/EMAIL-FIELD-LIFECYCLE-EVIDENCE.md
docs/compatibility/FIELD-RESIDUAL-EVIDENCE-2026-10-03.md
docs/compatibility/FIELD-SWATCH-TEXT-RADIUS-EVIDENCE-2026-10-05.md
docs/compatibility/FORMULA-IMPORT-ROUNDTRIP-EVIDENCE-2026-10-02.md
docs/compatibility/FORMULA-LEN-LIFECYCLE-EVIDENCE-2026-10-03.md
docs/compatibility/FORMULA-MATH-IMPORT-EVIDENCE-2026-10-01.md
docs/compatibility/FORMULA-QUANTITY-SEMANTICS-EVIDENCE-2026-10-02.md
docs/compatibility/FOX-CURRENCY-CONTRACT.md
docs/compatibility/IMAGE-QUANTITY-ZOOM-RUNTIME-2026-10-04.md
docs/compatibility/IMPEXP-LANE-EVIDENCE-2026-10-03.md
docs/compatibility/INTERACTION-PRODUCT-EVIDENCE-2026-10-03.md
docs/compatibility/LOCALE-EVIDENCE-2026-10-03.md
docs/compatibility/LOCALE-POLYLANG-WPML-EVIDENCE-2026-10-05.md
docs/compatibility/MIGPROOF-CROSSSITE-EVIDENCE-2026-10-03.md
docs/compatibility/MIGRATION-CLUSTER-EVIDENCE-2026-10-03.md
docs/compatibility/ORDER-AGAIN-REQUIRED-CHOICES-PROOF.md
docs/compatibility/PRICE-FORMULA-LIFECYCLE-EVIDENCE-2026-10-03.md
docs/compatibility/PRICE-MODE-LIFECYCLE-PRICEB-EVIDENCE-2026-10-03.md
docs/compatibility/PRODUCT-TARGETING-LIFECYCLE-EVIDENCE.md
docs/compatibility/PRODUCT-VARIABLE-EVIDENCE-2026-10-03.md
docs/compatibility/QTY-SEMANTICS-PARITY-EVIDENCE-2026-10-03.md
docs/compatibility/RULES-LIFECYCLE-EVIDENCE-2026-10-03.md
docs/compatibility/SELECT-RADIO-REQUIRED-CHOICE-EVIDENCE-2026-10-02.md
docs/compatibility/SUBSCRIPTION-BOOT-RESTORE-EVIDENCE-2026-10-04.md
docs/compatibility/SUBSCRIPTION-EVIDENCE-2026-10-03.md
docs/compatibility/SUMQTY-BROWSER-LIFECYCLE-EVIDENCE-2026-10-02.md
docs/compatibility/TEXT-FIELD-LIFECYCLE-EVIDENCE.md
docs/compatibility/TEXT-SWATCH-CHIP-DECORATION-EVIDENCE-2026-10-05.md
docs/compatibility/TEXT-SWATCH-CHIP-HOVER-SELECTED-EVIDENCE-2026-10-05.md
docs/compatibility/TEXT-SWATCH-CHIP-TWO-REGIMES-EVIDENCE-2026-10-06.md
docs/compatibility/TEXT-SWATCH-CHIP-WAPF-DEFAULT-EVIDENCE-2026-10-06.md
docs/compatibility/TEXTAREA-NEWLINE-EVIDENCE.md
docs/compatibility/TOGGLE-FIELD-LIFECYCLE-EVIDENCE.md
docs/compatibility/UPLOAD-AJAX-UI-EVIDENCE-2026-10-03.md
docs/compatibility/UPLOAD-CHECKOUT-PREREQUISITE-FINDING-2026-10-01.md
docs/compatibility/UPLOAD-FOUNDATION-EVIDENCE-2026-10-01.md
docs/compatibility/UPLOAD-ORDER-AGAIN-EVIDENCE-2026-10-03.md
docs/compatibility/UPLOAD-REISSUE-EVIDENCE-2026-10-03.md
docs/compatibility/UPLOAD-SECURITY-AUDIT-2026-10-02.md
docs/compatibility/URL-CART-VISUAL-EVIDENCE-2026-10-03.md
docs/compatibility/URL-FIELD-COMPATIBILITY-EVIDENCE.md
docs/compatibility/URL-FIELD-LIFECYCLE-EVIDENCE.md
docs/compatibility/URL-FIELD-NATIVE-PARITY.md
docs/compatibility/VALFIX-EVIDENCE-2026-10-03.md
docs/compatibility/VARZ-EVIDENCE.md
docs/compatibility/WAPF-CARDS-ZOOM-EVIDENCE-2026-10-03.md
docs/compatibility/WAPF-CHECKBOX-COLUMNS-EVIDENCE-2026-10-04.md
docs/compatibility/WAPF-CHILD-PRODUCTS-CURRENCY-TAX-2026-10-05.md
docs/compatibility/WAPF-CHILD-PRODUCTS-RUNTIME-FIXTURE-2026-10-05.md
docs/compatibility/WAPF-CHOICE-CAPACITY-BROWSER-EVIDENCE-2026-10-01.md
docs/compatibility/WAPF-COEXISTENCE-CHOICE-GRID-FIX-2026-10-05.md
docs/compatibility/WAPF-DATE-FORMAT-FALLBACK-EVIDENCE-2026-10-02.md
docs/compatibility/WAPF-DATE-FORMAT-JS-PRECEDENCE-EVIDENCE-2026-10-02.md
docs/compatibility/WAPF-DATE-FORMAT-MIGRATION-EVIDENCE-2026-10-02.md
docs/compatibility/WAPF-EXTENDED-DATE-COEXISTENCE-2026-10-04.md
docs/compatibility/WAPF-GROUP-ADMIN-TITLE-SEARCH-EVIDENCE-2026-10-02.md
docs/compatibility/WAPF-IMAGE-CHANGE-RULES-MODE-EVIDENCE-2026-10-05.md
docs/compatibility/WAPF-MINIMUM-PLATFORM-EVIDENCE.md
docs/compatibility/WAPF-PRICE-FORMULA-ADVANCED-COMMERCE-2026-10-05.md
docs/compatibility/WAPF-PRICE-HINT-CONVERSION-2026-10-05.md
docs/compatibility/WAPF-PRICE-QUANTITY-FLAT-EVIDENCE-2026-10-02.md
docs/compatibility/WAPF-REFERENCE-CHECKBOX-COLUMNS-2026-10-05.md
docs/compatibility/WAPF-REFERENCE-CHILD-PRODUCTS-IMAGE-ZOOM-2026-10-05.md
docs/compatibility/WAPF-REFERENCE-DISPLAY-PRICE-HINTS-2026-10-05.md
docs/compatibility/WAPF-REFERENCE-IMAGE-CHANGE-2026-10-05.md
docs/compatibility/WAPF-REFERENCE-IMAGE-QUANTITY-ZOOM-COEXISTENCE-2026-10-05.md
docs/compatibility/WAPF-STORE-API-CAPTURE-EVIDENCE-2026-10-02.md
docs/compatibility/WAPF-STOREFRONT-HINT-CONVERSION-2026-10-05.md
docs/compatibility/WAPF-SUMQTY-IMPORT-REVIEW-EVIDENCE-2026-10-02.md
docs/compatibility/WAPF-SUMQTY-TOOLS-PARSER-EVIDENCE-2026-10-02.md
docs/compatibility/WAPF-SWITCH-CONTROL-EXPORT-ROUNDTRIP-2026-10-06.md
docs/compatibility/WAPF-VALIDATION-COEXISTENCE-2026-10-04.md
docs/compatibility/WEIGHTFIX-EVIDENCE.md
docs/compatibility/WOOCS-CURRENCY-EVIDENCE.md
docs/compatibility/coupon-scope-proof.md
docs/compatibility/price-id-hidden-first-regression.md
```

These docs name `/tmp/opf-*` worktrees, `/tmp/opf-*-wp` WordPress roots, and
staged `/tmp/opf-*` result files that do not exist on a clean checkout, so their
raw artifacts cannot be re-inspected from the repository alone. Their claims
still have to be re-derived from source plus the plugin's committed harnesses.

**Policy for future evidence:** commit artifacts under `docs/` (for example
`docs/compatibility/<lane>/`), never under `/tmp`. A doc may reference a
`/tmp` path only as a transient scratch location; any file a claim depends on
must be committed so the proof is reproducible from a clean checkout. The
`docs/compatibility/wapf-reference-proof-20261005/` directory is the reference
pattern for that.

## 10. Remaining G4 blockers after this record

This record closes the packaging/provenance checks for the **0.1.0** baseline:

- Reproducible build command: recorded.
- Shipped-file inventory and checksum manifest: recorded.
- Bundled third-party components and their licenses: recorded.
- Runtime dependency audit (no runtime libraries): recorded.
- GPL-2.0-or-later declaration on all surfaces: recorded.
- Version metadata aligned (`0.1.0`): verified.
- Internal-doc (`tasks/`) leak into the archive: fixed and verified.

G4 as defined in the roadmap is still **not met**:

1. **No 1.0 release archive exists to inspect.** The archive above is the 0.1.0
   baseline; a 1.0 package cannot be produced until the version metadata is
   bumped to 1.0 and G2/G3 close, so the "inspected 1.0 archive" gate item
   remains open.
2. **105 compatibility evidence docs remain `/tmp`-staged** (section 9). Their
   raw artifacts are unreproducible from a clean checkout even though the
   affected row claims are re-derivable from committed source/harnesses.
3. **WordPress.org / commercial marketplace submission checklists are
   unexecuted.** Plugin Check results, readme/asset validation, and submission
   review have not been run for a 1.0 candidate.

G2 and G3 are out of scope for this record; see
`docs/compatibility/OPF-1.0-ROADMAP.md` and
`docs/compatibility/WAPF-CAPABILITY-LEDGER.md`.
