# OPF 1.0 release checklist — 2026-10-06

Ordered, reproducible steps to cut the 1.0 tag for Open Product Fields for
WooCommerce, with the state already reached and the external blockers named.
Every number below was produced by running the listed command on this host on
2026-10-06; nothing here is projected.

Environment used for the checks:

| Component | Version |
| --- | --- |
| PHP (build/test host) | 8.5.11 |
| WordPress (disposable clone `/home/followersya-5hqi7/opf-test/wordpress`) | 7.1.2 |
| WooCommerce (clone) | 11.1.0 |
| WordPress "Plugin Check" (clone) | 2.1.0 |
| WP-CLI | 2.11.0 |
| Current release under test | **0.1.1** |

---

## 1. What is already done

| Item | State | Evidence |
| --- | --- | --- |
| G2 capability parity | met | `docs/compatibility/OPF-1.0-ROADMAP.md` → 124 `supported`, 7 owner-accepted differences, 0 partial/baseline-only/gap/needs-audit edition rows |
| G3 commerce proof | met for every applicable edition row | the four 2026-10-06 closures plus `docs/compatibility/` evidence docs |
| Version surfaces aligned at `0.1.1` | done | plugin header, `OPF_VERSION`, `readme.txt` stable tag + description |
| Reproducible build | done | `bash bin/build.sh /tmp/opf-dist/open-product-fields-for-woocommerce` |
| Shipped-file inventory + SHA-256 manifest | done, refreshed this pass | `docs/RELEASE-PROVENANCE-2026-10-06.md` + `docs/RELEASE-PROVENANCE-2026-10-06-manifest.sha256` (`sha256sum -c` → all 200 files OK) |
| Bundled third-party notices, runtime dependency audit, GPL-2.0-or-later on all surfaces | done | `docs/RELEASE-PROVENANCE-2026-10-06.md` §3–§5 |
| Internal-doc (`tasks/`) archive leak | fixed and verified | `docs/RELEASE-PROVENANCE-2026-10-06.md` §8 |
| Dead shipped asset `assets/js/opf-frontend.min.js` | removed this pass | CHANGELOG 0.1.1 `Removed`; nothing referenced it (`includes/Service/Assets.php` enqueues `assets/js/opf-frontend.js`) |
| POT regeneration | done this pass | `languages/open-product-fields-for-woocommerce.pot`: `Project-Id-Version: … 0.1.1`, 596 msgids |
| PHPUnit gate | green | `Tests: 1086, Assertions: 4590`, 0 failures/errors |
| JS gate | green | `node --test tests/js/*.cjs` → 130 tests, 130 pass, 0 fail |
| Plugin Check run | executed this pass against the built 0.1.1 archive | 239 findings: **195 errors, 44 warnings** (see §3 step 7 and §6) |

---

## 2. Version surfaces to bump for a 1.0 tag

All five source surfaces plus the generated catalog must move together:

| # | Surface | Where |
| --- | --- | --- |
| 1 | Plugin header `Version:` | `open-product-fields-for-woocommerce.php` |
| 2 | `OPF_VERSION` constant | `open-product-fields-for-woocommerce.php` |
| 3 | `Stable tag:` | `readme.txt` |
| 4 | Description prose ("Version X ships …") | `readme.txt` |
| 5 | Test-runtime `OPF_VERSION` | `tests/bootstrap.php` |
| 6 | POT `Project-Id-Version` | `languages/open-product-fields-for-woocommerce.pot` (regenerate with `wp i18n make-pot`, never by hand) |

`docs/` records and `CHANGELOG.md` also carry the version in prose; update them
in the same commit.

---

## 3. Ordered steps to cut 1.0

Run everything from the plugin root unless stated otherwise. Do not run
`git add`/commit while the tree is dirty; commit only after step 10's gate list
passes.

### Step 1 — freeze the tree and bump every version surface

```sh
VERSION=1.0.0
# 1. plugin header + OPF_VERSION + readme stable tag + readme prose + tests/bootstrap.php
#    -> all five must say $VERSION
grep -rn "0\.1\.1" open-product-fields-for-woocommerce.php readme.txt tests/bootstrap.php
```

Then verify no stale surface remains:

```sh
grep -rn "0\.1\.1" --include='*.php' --include='*.txt' --include='*.md' . \
  | grep -v '^./vendor/' | grep -v '^./docs/'   # -> only the CHANGELOG history line(s)
```

Refuse to proceed if `open-product-fields-for-woocommerce.php`, `readme.txt` and
`tests/bootstrap.php` disagree.

### Step 2 — write the 1.0 release notes

Move the `## Unreleased` block in `CHANGELOG.md` under a new
`## 1.0.0 — <date>` heading (keep the existing `### Added` / `### Fixed` /
`### Removed` / `### Verified` ordering), and add the matching `= 1.0.0 =`
section to `readme.txt`'s changelog (today the readme changelog ends at 0.1.0
while `Stable tag` is 0.1.1 — fix that gap in the same commit).

### Step 3 — translation metadata

```sh
wp i18n make-pot . languages/open-product-fields-for-woocommerce.pot \
  --domain=open-product-fields-for-woocommerce
```

Known limits to handle explicitly, not silently:

- `wp i18n make-pot` prints one warning per placeholder string without a
  `translators:` comment. As of this pass there are **2**: `Image %d`
  (`includes/Service/LayeredImages.php:715`) and `Instructions for %s`
  (`includes/Service/Renderer.php:870`). Add the comments (or accept the
  warning in writing) before submission — Plugin Check raises the same finding
  as an `ERROR` (`WordPress.WP.I18n.MissingTranslatorsComment`, 3 occurrences).
- `languages/open-product-fields-for-woocommerce-es_ES.po` / `.mo` still declare
  `Project-Id-Version: … 0.1.0`. **No WP-CLI command can change a PO header
  value**: `wp i18n update-po` and GNU `msgmerge` both keep the destination
  PO's own `Project-Id-Version` and only copy keys the PO header does not
  define. Refreshing it therefore requires either a header rewrite in the
  translation workflow or a full re-extraction that would drop the shipped
  translations. Decide this explicitly for 1.0 (see §6) — do not hand-edit the
  file as an afterthought.
- If the .po is updated from the new POT, regenerate the JS catalog too
  (`wp i18n make-json`), otherwise the module-loaded JS strings keep only the
  old subset.

### Step 4 — build the release archive

```sh
bash bin/build.sh /tmp/opf-dist/open-product-fields-for-woocommerce
cd /tmp/opf-dist/open-product-fields-for-woocommerce
find . -type f | wc -l      # 200 at 0.1.1
du -sb .                    # 2,574,595 at 0.1.1
```

The archive must contain **no** `.git/`, `.gitignore`, `.phpunit.result.cache`,
`vendor/`, `tests/`, `bin/`, `docs/`, `tasks/`, `composer.json`, `composer.lock`
or `phpunit.xml.dist`.

### Step 5 — refresh provenance and the manifest

```sh
cd /tmp/opf-dist/open-product-fields-for-woocommerce
LC_ALL=C find . -type f -printf '%P\0' | LC_ALL=C sort -z | xargs -0 sha256sum \
  > <repo>/docs/RELEASE-PROVENANCE-2026-10-06-manifest.sha256
sha256sum -c <repo>/docs/RELEASE-PROVENANCE-2026-10-06-manifest.sha256 | grep -v ': OK$'
```

Then update `docs/RELEASE-PROVENANCE-2026-10-06.md`: audited commit, file count,
`du -sb` bytes, per-location and per-extension inventory, the manifest line
count and the manifest's own SHA-256. Record the delta against the previous
archive the same way §2.1 does for 0.1.1.

### Step 6 — test gate

```sh
vendor/bin/phpunit --no-coverage          # expect: Tests 1086, Assertions 4590, 0 failures
node --test tests/js/*.cjs                # expect: tests 130, pass 130, fail 0
```

Both were green on this pass. Two PHPUnit **runner** deprecations
(doc-comment metadata in `CapabilityFixtureRegistryTest` and
`LookupTableCsvImporterTest`) are pre-existing and do not fail the run; clear
them before PHPUnit 12 if a 1.0 tag slips past that release.

### Step 7 — Plugin Check gate

```sh
# in the disposable clone; Plugin Check must be ACTIVE or `wp plugin check` is unregistered
wp plugin activate plugin-check --path=/home/followersya-5hqi7/opf-test/wordpress
wp plugin check open-product-fields-for-woocommerce --path=/home/followersya-5hqi7/opf-test/wordpress
```

Result on the built 0.1.1 archive: **195 errors / 44 warnings**. Triage before
submission — the WP.org review reads the errors first. Split as of this pass:

| Class | Own code | Vendored `includes/ThirdParty/Url/` |
| --- | --- | --- |
| Errors | 152 | 43 |
| Warnings | 35 | 9 |

Largest error codes: `WordPress.Security.EscapeOutput.ExceptionNotEscaped` (76),
`WordPress.Security.EscapeOutput.OutputNotEscaped` (57),
`WordPress.WP.AlternativeFunctions.*` (file-system helpers: `fclose` 8, `unlink`
7, `fopen` 7, `chmod` 6, `rename` 5, `is_writable` 4, `fread` 3, `mkdir`/`fwrite`/
`readfile` 1 each), `strip_tags` (10), `WordPress.WP.I18n.TextDomainMismatch` (3),
`WordPress.WP.I18n.MissingTranslatorsComment` (3),
`Generic.PHP.ForbiddenFunctions.Found` (`move_uploaded_file`, 2),
`WordPress.WP.I18n.NonSingularStringLiteralText` (1).

Notable warning codes: `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized`
(15), `…MissingUnslash` (9), `WordPress.PHP.DevelopmentFunctions.error_log_trigger_error`
(9), `WordPress.DB.DirectDatabaseQuery.{NoCaching,DirectQuery}` (3 + 3),
`NonceVerification.{Missing,Recommended}` (3), and
`PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` (1).

The `includes/ThirdParty/Url/` findings are inherited from the vendored MIT
packages shipped verbatim and were expected by the source audit; scope the
decision (fix, exclude with a documented reason, or ship as-is) per class, not
per finding. No `wp plugin check` run is a release gate until that triage is
written down.

### Step 8 — migration and rollback rehearsal on the clone

1. Copy the new archive over the clone's plugin directory and activate it.
2. Re-run the WAPF import dry run: `wp opf import-wapf <file>` (no `--commit`).
3. Re-run the archive import dry run: `wp opf import-archive <file>`.
4. Verify one product page, one classic checkout, one Store API/block checkout,
   one order, and one order-again on the clone.
5. Exercise the rollback in §4 and confirm the pre-release state returns.

### Step 9 — submission

**WordPress.org (SVN), if the slug is approved** (external blocker, §5):

```sh
svn co https://plugins.svn.wordpress.org/<approved-slug> /tmp/opf-svn
rsync -a --delete --exclude='.svn' \
  /tmp/opf-dist/open-product-fields-for-woocommerce/ /tmp/opf-svn/trunk/
svn add --force /tmp/opf-svn/trunk
svn cp /tmp/opf-svn/trunk /tmp/opf-svn/tags/1.0.0
svn ci /tmp/opf-svn -m "Release 1.0.0" --username <account>
```

`readme.txt` `Stable tag:` must equal the tag just committed; the directory
`assets/` (banners/icons/screenshots) is a separate WP.org-only path and never
comes from `bin/build.sh`.

**Commercial marketplace**, if that is the chosen channel: upload the same
`/tmp/opf-dist/…` directory, attach the manifest from step 5, and paste the
`CHANGELOG.md` 1.0.0 block as the release notes.

### Step 10 — tag and post-release verification

1. Commit the release commit, then create the git tag (`1.0.0`) on it.
2. Re-download/-install the published artifact and re-run `sha256sum -c` against
   the committed manifest.
3. Confirm the served version: plugin header on the installed copy, `?ver=1.0.0`
   on `assets/js/opf-frontend.js`, and the WP.org listing's stable tag.
4. Run the site smoke test on whatever host installs the release.

---

## 4. Rollback plan

Nothing in the OPF data model is rewritten destructively by this release: OPF
never writes WAPF storage (`wapf_product` posts, `_wapf_fieldgroup` meta — the
import is copy-only), and its own state is `opf_field_group` posts plus
`_opf_*` postmeta with the `opf_version` option as the only version sentinel.

1. **Before publishing:** keep the last good archive (the directory `bin/build.sh`
   produced for the previous version) and the previous git tag. Never overwrite
   the only copy.
2. **Site-level rollback:** deactivate OPF, reinstall the previous version
   (`wp plugin install <slug> --version=<prev> --force` once the plugin is on
   WP.org, or restore the previous dist directory), reactivate, then verify one
   product page and one checkout.
3. **WP.org-level rollback:** set `readme.txt` `Stable tag:` back to the last
   good version, commit to `/trunk/`, and confirm the directory serves the older
   tag. Do not delete the released tag.
4. **WAPF-migration rollback** (for a merchant mid-migration): deactivate OPF and
   re-activate WAPF; per `docs/MIGRATION.md` §Rollback this is safe at any point
   before WAPF deletion.
5. **Only if a destructive forward migration is ever added:** take a database
   dump before the upgrade and roll back by restoring it — the option sentinel
   alone will not reverse data changes.

---

## 5. External blockers (not closeable from this host)

1. **WordPress.org account and plugin slug.** The plugin has never been
   submitted: there is no approved slug, no SVN account, and no `.org` listing
   to publish to. Step 9 cannot execute until the account/slug exist. The
   `Report-Msgid-Bugs-To` value generated by `wp i18n make-pot` is derived from
   the working directory name, not from an approved slug — re-run step 3 after
   the slug is fixed.
2. **Commercial marketplace listing.** Same class of blocker: no vendor account
   or listing in place, so the alternative submission channel is also
   unexecuted.
3. **WPML core 5.1.0 could not be obtained/run.** Core 5.1.0 requires String
   Translation ≥ 5.0.0 and WCML ≥ 5.6.0 and no matching companions were
   obtainable; WCML itself was not loadable with either core. The WPML row is
   proven only on the coherent set WPML core 4.2.8 + String Translation 2.10.6 +
   Translation Management 2.8.7. This is an evidence ceiling, not a code defect.
4. **WAPF-matrix gallery plugin absent.** The variable-product image-change
   proof (56/56 checks, both swap modes) ran against the free WP.org
   `woo-product-gallery-slider`, not a named WAPF-matrix premium integration;
   the 7 residual failures reproduced identically on WAPF Extended 3.1.5.
5. **WAPF Pro 3.2.x archive absent.** The current paid package could not be
   obtained, so Pro-only internals stay bounded to published changelog claims —
   e.g. the `switch_control` export key shape rests on the Pro 3.2 changelog
   plus OPF's own stored key, because installed Extended 3.1.5 has no switch key.
6. **105 `docs/compatibility/*.md` files cite `/tmp/...` artifacts** that do not
   exist on a clean checkout (list in `docs/RELEASE-PROVENANCE-2026-10-06.md`
   §9). Their row claims are re-derivable from committed source and harnesses,
   but the raw artifacts are not. G4 keeps this open until the affected docs are
   either re-staged under `docs/compatibility/<lane>/` or their claims
   re-derived.

---

## 6. Open items found in this pass

1. **es_ES catalog header.** The regenerated POT declares `0.1.1`; the tracked
   `open-product-fields-for-woocommerce-es_ES.po`/`.mo` still declare `0.1.0`
   and were deliberately left untouched, because no available tooling
   (`wp i18n update-po`, GNU `msgmerge`) rewrites a PO's `Project-Id-Version`
   and hand-editing generated catalogs is not acceptable. The `es_ES` catalog is
   also stale relative to the new POT (596 msgids vs 314 in the tracked PO).
   Resolve explicitly for 1.0 — recommended: re-run the translation workflow
   end to end (new POT → new PO header → re-apply the shipped translations) and
   regenerate the JS catalog with `wp i18n make-json`.
2. **`readme.txt` changelog ends at 0.1.0** while `Stable tag` is `0.1.1`; add
   the 0.1.1 entry (step 2).
3. **Missing `translators:` comments** for `Image %d` and `Instructions for %s`
   — 2 `make-pot` warnings, 3 Plugin Check errors.
4. **Plugin Check triage** (§3 step 7) is unrecorded: 195 errors / 44 warnings
   need a per-class decision before submission.
5. **The ledger's locale note is now stale** (`docs/compatibility/WAPF-CAPABILITY-LEDGER.md`
   still reads "313-msgid POT shipped (0 make-pot warnings)"; the regenerated POT
   has 596 msgids and 2 warnings). That file is frozen for this pass.
6. **`docs/CAPABILITIES.md` contradicts the shipped registry.** Its "Not
   implemented in 0.1.0" paragraph still lists "repeatable fields" and
   "child/linked products" as absent, while
   `OPF\Engine\FieldGroup::FIELD_TYPES` ships `child_products` / `products` and
   the `repeat` setting on fields and sections, with 35 repeat-named tests plus
   `RepeaterFieldTest` / `RendererRepeaterTest` and the linked-products suites.
   The 0.1.1 `readme.txt` description states the registry-backed capability set,
   so this document — which `readme.txt` links to as the canonical scope
   statement — must be reconciled before 1.0.
