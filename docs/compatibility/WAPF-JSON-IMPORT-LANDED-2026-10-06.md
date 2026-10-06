# WAPF Tools JSON file import — landed 2026-10-06

Landing record for the one unique piece of stale OPF work on
`feat/wapf-json-import`: the file-based import of a WAPF Tools JSON payload.
This path is separate from the site-database migration (`wp opf import_wapf`)
and from the OPF archive import (`wp opf import_archive`).

- Repository: `open-product-fields-for-woocommerce` (branch `master`)
- Source branch: `feat/wapf-json-import` @ `2766539` (`fix(import): retain
  supported WAPF conditions`, also `origin/feat/wapf-json-import`)
- Prepared against `master` @ `74321e0`
- Changes are left **uncommitted in the working tree** (no branch, no commit).
- Runtime: PHP `8.5.11` (the plugin declares `Requires PHP: 7.4`); the code
  uses no syntax newer than 7.4.

`WapfJsonFileImporter` and the `import-wapf-json` command do not exist on
`master` before this change. The file-based import is the missing third leg of
the WAPF Tools surface: `wp opf export --format=wapf-json` already writes a
four-part payload (`fields`, `conditions`, `layout`, `variables`), and until now
nothing on `master` could read one back in.

## 1. Files

| File | State | Origin |
| --- | --- | --- |
| `includes/Service/WapfJsonFileImporter.php` | new (213 lines) | taken from `2766539`, adapted (§3) |
| `includes/Service/Cli.php` | modified (+83 lines) | new `import_wapf_json` command written against master's style |
| `tests/Unit/WapfJsonFileImporterTest.php` | new (13 tests, 54 assertions) | branch tests re-based on master's mapper behaviour (§3) |

Not touched, on purpose: `docs/compatibility/WAPF-CAPABILITY-LEDGER.md`,
`docs/compatibility/OPF-1.0-ROADMAP.md` (frozen), `docs/MIGRATION.md` and
`readme.txt` (the branch edited both; see §6).

## 2. Command

```bash
wp opf import-wapf-json <file> [--title=<title>] [--commit] [--product-id=<id>] [--format=<format>]
```

- Dry run by default: without `--commit` nothing is written at all.
- `--commit` creates a **draft** (`post_status = draft`) plus
  `_opf_needs_review`; the file path carries no source group ID, title, or
  placement guarantee, so import never publishes.
- `--product-id=<id>` attaches the draft to one existing WooCommerce product
  (validated with `wc_get_product`); that explicit choice replaces the imported
  placement, exactly as `attach_product_ids` does in `Importer`.
- One file, 2 bytes…5 MiB, at most 500 fields. Idempotent: re-running the same
  payload + title + product is reported as `already-imported` and writes
  nothing (fingerprint `wapf-json:<sha256(payload,title,product_id)>` stored in
  `_opf_imported_from`).
- `--title` is optional here; when absent the draft title is the payload file
  name (`/tmp/wapf-fields.json` → `wapf-fields`) and the resolved title is
  printed before anything is written.
- `wp opf import_wapf_json` (the spelling WP-CLI derives from the method name)
  is the same command; see §3.9.

## 3. Taken vs adapted

Taken verbatim from `2766539`: `inspect_file` / `prepare_payload` /
`run( prepared, title, commit, product_id )`, the 5 MiB and 500-field limits,
the review notes for the missing source identity, the fingerprint + idempotency
lookup, `_opf_needs_review` capped at 20 entries (the same cap `Importer` and
`ArchiveImporter` use), and the rule that malformed fields, conditions, or
variables are reported instead of dropped. `conditions` → `rule_groups` is part
of that payload normalization; the actual placement mapping is `WapfMapper`'s,
which already covers product/variation/user placement on `master` (`c190e0b`),
so no placement code was re-imported.

Adapted for `master`:

1. **CLI command re-written instead of merged.** The branch's `Cli.php` is ~356
   commits back and only knows `import_wapf`/`report`; its diff cannot be
   applied. `import_wapf_json` was written into master's `Cli.php` in master's
   existing command style (positional arg count, `table|json` format guard,
   `positive_id()`, `\WP_CLI::error()` on the importer's exceptions).
2. **`--product-id` (not the branch's `--product`)** — the flag named in the
   landing request; `positive_id()` rejects non-positive values.
3. **`_opf_imported_from` written through `meta_input`** instead of a post-save
   `update_post_meta`, so import provenance exists before `save_post` callbacks
   run (the reason `Importer` and `ArchiveImporter` do the same).
4. **Existing-id extraction tolerates `WP_Post` objects**, matching the
   fixture/`get_posts` handling documented in `Importer::import_group`.
5. **Variable note corrected.** The branch flagged every payload with variables
   as "not mapped by the current importer". Master's mapper *does* map WAPF's
   `{name, default, rules}` variables, so that note was a false claim on every
   import with variables; it now fires only when the definitions are not in
   that shape (e.g. an object map), which is the case the mapper silently skips.
6. **Unknown field-option note reworded** from "is not mapped by the current
   importer" (untrue for the keys master's mapper reads out of `options`) to
   "is retained verbatim; confirm it maps to the intended OPF setting". The
   flag itself is unchanged: unknown field keys are still merged into `options`
   and reported.
7. **Unknown top-level payload keys are now flagged.** The branch read
   `fields`/`conditions`/`layout`/`variables` and ignored anything else, which
   silently dropped data; an extra key such as WAPF Tools' `replace` now yields
   a review note.
8. **`MAX_FILE_BYTES` is a public const** (mirrors `ArchiveImporter::MAX_BYTES`)
   so the CLI/tests can reference the limit instead of repeating the literal.
9. **Dashed command alias registered.** WP-CLI 2.11 names a class-method
   subcommand exactly as spelled, so `wp opf import_wapf_json` is the derived
   name; `Cli::init()` additionally registers `opf import-wapf-json`, which is
   the spelling used in this request, the branch's `docs/MIGRATION.md`, and
   master's own `Cli.php` docblocks. Both spellings run the same method.

## 4. Verification (all commands run in the plugin root, 2026-10-06)

Full PHPUnit suite, without my new test file (baseline):

```sh
vendor/bin/phpunit --no-coverage --filter '/^(?!.*WapfJsonFileImporterTest).*$/'
# Tests: 1067, Assertions: 4514, PHPUnit Deprecations: 2  (OK)
```

Full PHPUnit suite as required:

```sh
vendor/bin/phpunit --no-coverage
# Tests: 1080, Assertions: 4568, PHPUnit Deprecations: 2  (OK, 0 failures/errors)
```

The 2 deprecations are pre-existing metadata-in-doc-comment notices from
`CapabilityFixtureRegistryTest` and `LookupTableCsvImporterTest`; they appear
identically when running the suite without this change.

New test file alone:

```sh
vendor/bin/phpunit --no-coverage --filter WapfJsonFileImporterTest
# Tests: 13, Assertions: 54, PHPUnit Deprecations: 2  (OK)
```

JavaScript suite:

```sh
node --test tests/js/*.cjs
# tests 130 / pass 130 / fail 0
```

Live WP-CLI check against the production WordPress + WooCommerce install
(`/usr/local/bin/wp --path=.../bedrock/web/wp`), read-only, dry run only:

```sh
wp opf import-wapf-json /tmp/wapf-json-landed-dryrun.json
# Result: dry-run / Title: wapf-json-landed-dryrun / Fields: 2
# Status: dry run; no draft was written. Re-run with --commit to create the review draft.
#   Review: WAPF Tools JSON omits the source group ID and title; ...
#   Review: WAPF field option "unknown_option" is retained verbatim; ...

wp opf import-wapf-json /tmp/wapf-json-landed-dryrun.json --format=json
# {"result":"dry-run","opf_id":0,"title":"wapf-json-landed-dryrun","fields":2,"needs_review":true,"notes":[...]}

wp opf import-wapf-json /tmp/wapf-json-landed-dryrun.json --product-id=0
# Error: The selected ID must be a positive integer.   (exit 1)

wp opf import-wapf-json /tmp/wapf-json-landed-dryrun.json --product-id=999999
# Error: The requested WooCommerce product does not exist.   (exit 1)

wp opf import-wapf-json /tmp/wapf-json-landed-bad.json      # content: {"fields": [
# Error: WAPF JSON file is malformed.   (exit 1)

wp db query "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_opf_imported_from' AND meta_value LIKE 'wapf-json:%'"
# 0   → the dry run wrote nothing

wp help opf            # subcommands now list import-wapf-json and import_wapf_json
wp help opf import-wapf-json   # synopsis: <file> [--title=<title>] [--commit] [--product-id=<id>] [--format=<format>]
wp opf report          # 678 field groups, unchanged
wp opf import_wapf_json /tmp/wapf-json-landed-dryrun.json   # same dry-run output (underscore spelling)
```

The `--commit` write path is covered by the unit tests (draft status,
provenance meta, `_opf_needs_review`, no writes on dry run) and was deliberately
not exercised against production, which would create a `opf_field_group` draft.

## 5. Test coverage added

`tests/Unit/WapfJsonFileImporterTest.php` (13 tests):

- valid file: inspected, `products` placement retained, `mark_required` mapped
- flat field options normalized; unknown option retained and flagged;
  `placeholder` not flagged
- malformed JSON file, oversize (5 MiB + 1), 1-byte file, missing file
- empty field list, 501 fields, malformed condition group
- placement conditions master's mapper supports (`products` + `auth`) map to
  `product` / `user_auth` rules without a review flag
- mappable vs unmappable variable definitions
- unknown top-level payload key flagged, not dropped
- dry run reports the mapping and writes nothing (`posts`/`meta` empty)
- `--commit` writes one draft with `wapf-json:` provenance and review meta
- an existing fingerprint is reported `already-imported` and not written again
- unknown product id and blank title fail closed

## 6. Residual

- `--commit` was not run against production (would create real draft data).
- `docs/MIGRATION.md` and `readme.txt` still describe no file-based import; the
  branch's versions of both name the branch's `--product` flag and were left
  untouched because they were outside this landing.
- `docs/compatibility/WAPF-CAPABILITY-LEDGER.md` row `WAPF-ADMIN-IMPORT-EXPORT`
  still reads "OPF contains WAPF JSON import/export paths" without naming the
  command; the ledger is frozen for this task.
- Pre-existing, unrelated: `Cli.php` docblocks document
  `wp opf import-wapf` / `wp opf import-archive`, but WP-CLI 2.11 registers the
  underscore spellings only, so those two examples do not run as written.
- `import-wapf-json` is one-way (payload in). Round-trip equivalence with WAPF
  Tools remains open tracking in the ledger, not claimed here.
