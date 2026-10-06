# Plugin Check triage — 2026-10-06 (OPF 0.1.1)

Status: **triage complete**. 1 class fixed (3 findings), everything else either
verified safe (false positive) or real-but-intentional with the reason and the
compensating control written down. No code was rewritten to silence a sniff.

Scope: `open-product-fields-for-woocommerce` 0.1.1 (the built release archive,
installed in the disposable clone), plugin-authored code only for the fix pass.
`includes/ThirdParty/Url/` is vendored third-party and is out of scope (§7).
Edits were limited to three files under `includes/` plus this document;
`open-product-fields-for-woocommerce.php`, `readme.txt`, `composer.json`,
`languages/` and the release provenance artifacts are owned by other lanes in
this pass and were not touched (their findings are recorded, not edited).

This document is the per-class decision that
`docs/RELEASE-CHECKLIST-1.0-2026-10-06.md` §3 step 7 and §6 item 4 required
before a `wp plugin check` run can be treated as a release gate.

---

## 1. Environment and how to reproduce

| Item | Value |
| --- | --- |
| WordPress under test | `/home/followersya-5hqi7/opf-test/wordpress` (disposable clone) |
| WP-CLI | `/usr/local/bin/wp` |
| Plugin Check | 2.1.0 (active) |
| Plugin under test | `open-product-fields-for-woocommerce` 0.1.1 (built archive, active) |
| WP / WooCommerce | 7.1 / 11.1.0 |

The clone's plugin directory is the **built archive**, not the source tree:
it has no `bin/`, `docs/`, `tests/`, `vendor/`, `composer.*`, `.git`. Verified
before the run:

```sh
diff -rq /path/to/clone/wp-content/plugins/open-product-fields-for-woocommerce \
          /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
# → only "Only in <source>:" lines (bin, docs, tests, vendor, composer.*, .git, …)
#   i.e. every file present in both trees was byte-identical
```

Exact commands (raw output kept outside the repo, `/tmp`):

```sh
# before
/usr/local/bin/wp --path=/home/followersya-5hqi7/opf-test/wordpress \
  plugin check open-product-fields-for-woocommerce --format=json \
  > /tmp/opf-plugin-check-20261006-before.json
/usr/local/bin/wp --path=/home/followersya-5hqi7/opf-test/wordpress \
  plugin check open-product-fields-for-woocommerce --format=csv --fields=file,line,column,type,code \
  > /tmp/opf-plugin-check-20261006-before.csv

# after applying the fixes in the source tree, copy the 3 changed files into the
# clone (it is the built archive, so a rebuild is not required for this check)
for f in includes/Service/LayeredImages.php includes/Service/LivePreview.php \
         includes/Service/Renderer.php; do
  cp "$SRC/$f" "$CLONE/$f"
done
/usr/local/bin/wp --path=/home/followersya-5hqi7/opf-test/wordpress \
  plugin check open-product-fields-for-woocommerce --format=json \
  > /tmp/opf-plugin-check-20261006-after.json
```

`--format=json` output is **not** one JSON document: it is `FILE: <path>`
header lines followed by one JSON array per file, without a trailing newline.
Parsing recipe used for every count in this document:

```python
import json, re
rows = []
for path, arr in re.findall(r'^FILE: (.+)$\n(\[.*\])$', open('out.json').read(), re.M):
    for item in json.loads(arr):
        rows.append(dict(file=path, **item))
```

Files:

- `/tmp/opf-plugin-check-20261006-before.{json,csv,txt}` (239 findings)
- `/tmp/opf-plugin-check-20261006-after.{json,csv,txt}` (236 findings)

## 2. Totals before → after

| | Before | After |
| --- | ---: | ---: |
| **Total findings** | **239** | **236** |
| Errors | 195 | 192 |
| Warnings | 44 | 44 |
| Plugin-authored (own code) | 187 (152 E / 35 W) | 184 (149 E / 35 W) |
| Vendored `includes/ThirdParty/Url/` | 52 (43 E / 9 W) | 52 (43 E / 9 W) |

Delta: **−3 errors**, all `WordPress.WP.I18n.MissingTranslatorsComment`. The two
`WordPress.Security.EscapeOutput.OutputNotEscaped` findings on
`Renderer.php:870` moved columns (291→327, 446→482) because the translator
comment added 36 characters to that line; they are the same two findings.

Nothing else changed: the `after` run has exactly the same finding set minus
those three (verified by comparing the `(file, line, column, code)` sets).

## 3. Bucket table (one row per result code)

Buckets: **(a)** real and fixed, **(b)** real but intentional (reason +
compensating control in §5), **(c)** false positive (reason in §6),
**(V)** vendored third-party, out of scope.

| Result code | Before (own / vendored) | After (own / vendored) | Example | Bucket | Action |
| --- | --- | --- | --- | --- | --- |
| `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | 76 (39 / 37) | 76 (39 / 37) | `includes/Service/WapfExporter.php:204` | b (own) + V | Kept — internal validation messages reach WP-CLI/JSON sinks, never HTML (§5.1) |
| `WordPress.Security.EscapeOutput.OutputNotEscaped` | 57 (51 / 6) | 57 (51 / 6) | `includes/Service/LayeredImages.php:705` | c (50) + b (1) + V | Kept — escaped by construction; one WAPF filter-contract echo (§5.12, §6.1) |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | 15 (15 / 0) | 15 (15 / 0) | `includes/Service/Uploads.php:55` | c (13) + b (2) | Kept — sanitized downstream or gated by the caller; two rely on WooCommerce's save gate (§5.5, §6.2) |
| `WordPress.WP.AlternativeFunctions.strip_tags_strip_tags` | 10 (10 / 0) | 10 (10 / 0) | `includes/Engine/FieldGroup.php:564` | b | Kept — deliberate stored-value sanitization (§5.3) |
| `WordPress.Security.ValidatedSanitizedInput.MissingUnslash` | 9 (9 / 0) | 9 (9 / 0) | `includes/Service/Uploads.php:61` | c | Kept — `$_SERVER`/`$_FILES`/nonce reads where unslash is a no-op or not applicable (§6.3) |
| `WordPress.PHP.DevelopmentFunctions.error_log_trigger_error` | 9 (0 / 9) | 9 (0 / 9) | `…/ThirdParty/Url/symfony/polyfill-mbstring/Mbstring.php:132` | V | Vendored `symfony/polyfill-mbstring` 1.31.0 (§7) |
| `WordPress.WP.AlternativeFunctions.file_system_operations_fclose` | 8 (8 / 0) | 8 (8 / 0) | `includes/Service/Uploads.php:325` | b | Kept — `flock` lock release / magic-byte probes (§5.2) |
| `WordPress.WP.AlternativeFunctions.file_system_operations_fopen` | 7 (7 / 0) | 7 (7 / 0) | `includes/Service/Uploads.php:296` | b | Kept — same |
| `WordPress.WP.AlternativeFunctions.unlink_unlink` | 7 (7 / 0) | 7 (7 / 0) | `includes/Service/Uploads.php:320` | b | Kept — rollback of a half-written upload / private-file deletion (§5.2) |
| `WordPress.WP.AlternativeFunctions.file_system_operations_chmod` | 6 (6 / 0) | 6 (6 / 0) | `includes/Service/Uploads.php:224` | b | Kept — 0600/0700 private-storage permissions (§5.2) |
| `WordPress.WP.AlternativeFunctions.rename_rename` | 5 (5 / 0) | 5 (5 / 0) | `includes/Service/LayeredImages.php:133` | b | Kept — atomic temp+rename publish (§5.2) |
| `WordPress.WP.AlternativeFunctions.file_system_operations_is_writable` | 4 (4 / 0) | 4 (4 / 0) | `includes/Service/Uploads.php:223` | b | Kept — fail-closed storage/export-path checks (§5.2) |
| `WordPress.WP.I18n.TextDomainMismatch` | 3 (3 / 0) | 3 (3 / 0) | `includes/Service/LinkedProducts.php:282` | b | Kept — strings are WooCommerce's own labels and are absent from the OPF catalogs (§5.8) |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | 3 (3 / 0) | 3 (3 / 0) | `includes/Service/Importer.php:127` | b | Kept — one-shot migration scan + option-prefix quota scan (§5.7) |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | 3 (3 / 0) | 3 (3 / 0) | `includes/Service/Uploads.php:553` | b | Kept — same; caching would defeat the lock-scoped snapshot (§5.7) |
| `WordPress.WP.AlternativeFunctions.file_system_operations_fread` | 3 (3 / 0) | 3 (3 / 0) | `includes/Service/LayeredImages.php:598` | b | Kept — WOFF/WOFF2 and PNG magic-byte probes (§5.2) |
| `WordPress.WP.I18n.MissingTranslatorsComment` | 3 (3 / 0) | **0 (0 / 0)** | `includes/Service/Renderer.php:870` | **a** | **Fixed** — translator comments added (§4) |
| `Generic.PHP.ForbiddenFunctions.Found` | 2 (2 / 0) | 2 (2 / 0) | `includes/Service/Uploads.php:317` | b | Kept — validated upload handlers, `move_uploaded_file` required (§5.4) |
| `WordPress.Security.NonceVerification.Missing` | 2 (2 / 0) | 2 (2 / 0) | `includes/Service/Admin/CouponSettings.php:36` | b | Kept — WooCommerce verifies nonce + capability before the hook fires (§5.5) |
| `WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude` | 1 (1 / 0) | 1 (1 / 0) | `includes/Service/LinkedProducts.php:182` | b | Kept — one-product exclusion, advisory only (§5.11) |
| `WordPress.WP.AlternativeFunctions.file_system_operations_mkdir` | 1 (1 / 0) | 1 (1 / 0) | `includes/Service/Uploads.php:222` | b | Kept — 0700 private root outside the webroot (§5.2) |
| `WordPress.WP.AlternativeFunctions.file_system_operations_readfile` | 1 (1 / 0) | 1 (1 / 0) | `includes/Service/Uploads.php:547` | b | Kept — owner-checked private download stream (§5.2) |
| `WordPress.WP.I18n.NonSingularStringLiteralText` | 1 (1 / 0) | 1 (1 / 0) | `includes/Service/Uploads.php:595` | b | Kept — translation wrapper for plugin-authored literals; extraction gap tracked (§5.9, §8) |
| `WordPress.WP.AlternativeFunctions.file_system_operations_fwrite` | 1 (1 / 0) | 1 (1 / 0) | `includes/Service/LookupTableCsvImporter.php:33` | b | Kept — in-memory `php://temp` CSV stream (§5.2) |
| `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` | 1 (1 / 0) | 1 (1 / 0) | `open-product-fields-for-woocommerce.php:121` | b | Kept — self-hosted catalogs in `languages/` need it (§5.10) |
| `WordPress.Security.NonceVerification.Recommended` | 1 (1 / 0) | 1 (1 / 0) | `includes/Service/FieldGroups.php:159` | b | Kept — read-only display flag for a static admin notice (§5.6) |

Appendix A lists every plugin-authored finding by code + file + line, so nothing
is unclassified. Appendix B lists the vendored findings.

## 4. Bucket (a) — real and FIXED (3 findings)

`WordPress.WP.I18n.MissingTranslatorsComment` (3, all `ERROR`): a `sprintf()`
placeholder translated without a `translators:` comment. `wp i18n make-pot`
emits the same warning, the strings are already in the shipped POT
(`msgid "Image %d"` at line 792, `msgid "Instructions for %s"` at line 1105),
and the release checklist listed them as an open item (§6 item 3). Fixed by
adding the inline comment in the style already used elsewhere in the codebase
(e.g. `includes/Service/Renderer.php:974`,
`includes/Service/LinkedProducts.php:446`):

| File:line | Before | After |
| --- | --- | --- |
| `includes/Service/LayeredImages.php:715` | `sprintf( __( 'Image %d', … ), $id )` | `sprintf( /* translators: %d: attachment ID. */ __( 'Image %d', … ), $id )` |
| `includes/Service/LivePreview.php:76` | `sprintf( __( 'Image %d', … ), $index + 1 )` | `sprintf( /* translators: %d: image position in the product gallery. */ __( 'Image %d', … ), $index + 1 )` |
| `includes/Service/Renderer.php:870` | `sprintf( __( 'Instructions for %s', … ), $field['label'] )` | `sprintf( /* translators: %s: field label. */ __( 'Instructions for %s', … ), $field['label'] )` |

Behaviour change: none (comments only). Diff: 3 files, 3 lines.

## 5. Bucket (b) — real but intentional

Each entry: what the tool objects to, why the code is correct as written, and
the compensating control.

### 5.1 `EscapeOutput.ExceptionNotEscaped` — 39 own-code findings

`throw new \InvalidArgumentException( '…' . $var )` / `sprintf(…)` in
`Engine/FieldGroup.php` (14), `Service/WapfExporter.php` (9),
`Service/ArchiveImporter.php` (9), `Engine/RepeaterField.php` (2),
`Service/WapfJsonFileImporter.php` (2), `Service/CartIntegration.php` (2),
`Engine/CapabilityFixtureRegistry.php` (1). WPCS 3.1 flags any exception whose
message is not escaped at construction time.

Why it is correct here — every sink was traced:

| Sink | Where | Consequence of `esc_html()` at throw time |
| --- | --- | --- |
| WP-CLI terminal | `Service/Cli.php:117,176,178,338,367,403` (`WP_CLI::error( $exception->getMessage() )`) | messages would print `&quot;`, `&amp;` in the console |
| REST / Store API JSON | `Service/Rest.php:105,141` → `WP_Error`; `CartIntegration.php:216` → `RouteException` | JSON payloads must not be HTML-escaped |
| Admin notice | `Service/LookupTableCsvImporter.php:183` builds a transient, rendered at `:151` through `esc_html()` | already escaped at the only HTML sink |

The messages are built from plugin-authored schema labels and validated field
keys (`sprintf( 'Products %s must be boolean.', $key )`), never from request
payloads. Store-API upload errors embed `$field['label']`, which WooCommerce
renders through `wc_kses_notice()` (`woocommerce/templates/notices/notice.php:30`)
and which the builder JS shows with `textContent`
(`assets/js/opf-builder.js:2022`), not `innerHTML`. Compensating control:
escaped at the sink, verified above; exception text stays byte-exact for CLI and
API consumers and for the unit tests that assert message text.

### 5.2 `WP.AlternativeFunctions.*` filesystem — 43 own-code findings

`fclose` 8, `fopen` 7, `unlink` 7, `chmod` 6, `rename` 5, `is_writable` 4,
`fread` 3, `mkdir`/`readfile`/`fwrite` 1 each — in `Service/Uploads.php` (18),
`Service/LayeredImages.php` (8), `Service/Cli.php` (9),
`Service/LivePreviewFonts.php` (4), `Service/LookupTableCsvImporter.php` (4).

The recommendation (`WP_Filesystem`) cannot express what these paths need:

| Purpose | Example | Why not `WP_Filesystem` |
| --- | --- | --- |
| Site-wide exclusive lock around quota check + bytes + metadata | `Uploads.php:296,458,573` (`fopen` + `flock(LOCK_EX)`), `:325,520,590` (`fclose`) | `WP_Filesystem` has no locking primitive; the quota race is the whole point |
| Atomic publish | `Uploads.php:317` then `Cli.php:348,380,412`, `LayeredImages.php:133,356` (`tempnam`/`file_put_contents` + `rename`) | alternate-data-stream/direct upload cannot be made atomic |
| Private permissions | `Uploads.php:222,224,298,318,462,496`, `LivePreviewFonts.php:160` (`mkdir`/`chmod` 0700/0600/0644) | `WP_Filesystem::chmod()` cannot be relied on (FTP/SSH transports) |
| Content sniffing | `LayeredImages.php:594–637`, `LivePreviewFonts.php:93–98` (`fopen`/`fread`/`fclose` on magic bytes) | no filesystem-API equivalent for partial reads |
| Streaming a private download | `Uploads.php:547` (`readfile`) | `WP_Filesystem::get_contents()` would buffer a whole upload in memory |
| CSV parsing | `LookupTableCsvImporter.php:29,33,44,48` (`php://temp`) | in-memory stream, nothing touches disk |
| Rollback / GC of private files | `Uploads.php:320,511,564,585`, `Cli.php:350,382,414` (`unlink`) | `wp_delete_file()` exists for uploads only; these are private artifacts |

Compensating controls: the storage root is outside every public root
(`Uploads::root()`, which also `realpath`s and refuses symlinks), permissions are
0600/0700, downloads require the session-owner HMAC or an order capability check
(`Uploads::download()`), and every path is token-validated
(`/^[a-f0-9]{64}$/`). `Cli.php` only writes to a path the operator passed
explicitly (`--output`), after checking the parent directory is writable.

### 5.3 `strip_tags` — 10 own-code findings

`Engine/FieldGroup.php:564,723,746,754,761,984`, `Engine/RepeaterField.php:51`,
`Engine/WapfMapper.php:209,766`, `Service/Renderer.php:1103`. All ten sanitize
admin-authored configuration (choice descriptions, toggle messages, defaults,
repeater labels, image alt text) that is later stored and re-exported.

Why not `wp_strip_all_tags()`: it is not equivalent — it additionally deletes the
*content* of `<script>`/`<style>` blocks, so for a stored default such as
`<script>x</script>Hi` the stored and re-exported bytes would change (`xHi` vs
`Hi`). OPF's contract for imported WAPF data is byte-preserving round-trips, and
the author already showed that this difference is understood: `FieldGroup.php:563`
explicitly pre-strips `<script|style>` blocks with
`preg_replace( '#<(script|style)\b[^>]*>.*?(?:</\1\s*>|$)#is' … )` before its
`strip_tags()` call. The unit bootstrap also defines `wp_strip_all_tags()` as a
`strip_tags()` shim (`tests/bootstrap.php:71`), so a swap would be invisible to
the test suite.

Compensating control: these are **inputs to storage**, not output sinks; every
consumer escapes at render time (`esc_html`/`esc_attr`), and
`FieldGroup::normalize()` also removes control characters and enforces length
bounds after stripping.

### 5.4 `Generic.PHP.ForbiddenFunctions.Found` (`move_uploaded_file`) — 2

`Service/Uploads.php:317` (private order uploads) and
`Service/LivePreviewFonts.php:156` (preview font upload).

Why the WP helper is not used: `wp_handle_upload()`/`media_handle_upload()`
require the `test_form` nonce/`action` contract, write into the **public**
uploads tree, register an attachment, and apply default permissions — the OPF
upload design is explicitly the opposite (private root outside the webroot,
0600, no media-library entry, no public URL), and the font path needs a fixed
`opf-preview-fonts` directory plus `wp_unique_filename()`.

Compensating controls (all verified in code):

- capability + nonce: `Uploads::permission()` (same-origin + WC session nonce via
  `hash_equals`) and `Uploads::native()` (`wp_verify_nonce`); for fonts
  `manage_woocommerce` + `wp_verify_nonce( 'woocommerce-settings' )`.
- `is_uploaded_file()` before the move (`Uploads.php:288`).
- `sanitize_file_name()`, extension allowlist, refusal of
  `php|phtml|phar|html|htm|svg|js|exe|sh` even when an admin allows them, and a
  `finfo` MIME check that must equal `wp_check_filetype_and_ext()`'s verdict
  (`Uploads.php:241-267`, `validate_file()`); fonts are checked for WOFF/WOFF2
  magic bytes.
- `wp_unique_filename()` + `wp_mkdir_p()` + `chmod 0600`/`0644` after the move.

### 5.5 `NonceVerification.Missing` — 2 (`Admin/CouponSettings.php:36,37`)

`save_field()` reads `$_POST['opf_excl_addons']` on
`woocommerce_coupon_options_save`. That action is fired by WooCommerce **after**
its own gate: `WC_Admin_Meta_Boxes::save_meta_boxes()`
(`woocommerce/includes/admin/class-wc-admin-meta-boxes.php:228` verifies
`wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' )`,
`:238` requires `current_user_can( 'edit_post', $post_id )`) →
`WC_Meta_Box_Coupon_Data::save()` (`class-wc-meta-box-coupon-data.php:399`).
Verifying a second, plugin-owned nonce there would duplicate WooCommerce's
contract and could break coupon saving if the core nonce name changes.

Compensating control: the caller's nonce + capability check, plus
`sanitize_text_field( wp_unslash( … ) )` and `'yes' === $value` on the value.
Same reasoning covers `Service/LayeredImages.php:475` and
`Service/LivePreview.php:133` (`woocommerce_admin_process_product_object`,
fired at `class-wc-meta-box-product-data.php:445` inside the same gate) and
`Admin/FormulaVariables.php:32` (`check_admin_referer()` on the line above).

### 5.6 `NonceVerification.Recommended` — 1 (`Service/FieldGroups.php:159`)

`empty( $_GET['opf_duplicated'] )` only decides whether to print a static
"Field group duplicated." success notice; the value is never stored, queried,
echoed, or used for authorization. The redirect that sets it is already
capability-gated (`duplicate_notice()` runs on the `edit-opf_field_group` list
screen, which requires the CPT capability).

### 5.7 `DB.DirectDatabaseQuery.{DirectQuery,NoCaching}` — 6

- `Service/Importer.php:127,165` — `wp opf import-wapf` migration: one pass that
  scans **all** `wapf_product` posts ordered by `post_date DESC, ID DESC`, then
  all `postmeta` rows with `meta_key = '_wapf_fieldgroup'`. No core API exposes a
  full-table scan with that ordering; the payload is then read through
  `get_post_meta()` (the code comments call this out) so storage transforms are
  honoured. Caching a one-shot migration scan is meaningless.
- `Service/Uploads.php:553` — prefix scan of `wp_options` for `opf_upload_*`
  rows to compute per-session and site-wide quota. There is no WP API for
  enumerating options by prefix, and this runs **inside** the site-wide upload
  lock, so a cached result would be exactly the staleness the lock prevents.

### 5.8 `WP.I18n.TextDomainMismatch` — 3

`LinkedProducts.php:282` (`__( 'In stock', 'woocommerce' )`),
`CartEdit.php:275` (`__( 'Update cart', 'woocommerce' )`),
`CartEdit.php:335` (`esc_html__( 'Cart updated.', 'woocommerce' )`).

These are byte-identical copies of WooCommerce's own storefront labels, kept on
WooCommerce's textdomain on purpose: neither string exists in the plugin's own
catalog, so switching to `'open-product-fields-for-woocommerce'` would print
English inside an otherwise Spanish (`es_ES`) storefront until the plugin
catalog is re-translated. Verified:

```sh
grep -n '^msgid "In stock"'   languages/open-product-fields-for-woocommerce*.{po,pot}   # no match
grep -n '^msgid "Update cart"' languages/open-product-fields-for-woocommerce*.{po,pot} # no match
grep -n '^msgid "Cart updated."' languages/open-product-fields-for-woocommerce*.{po,pot} # no match
```

Compensating control: the translation is supplied by the WooCommerce language
pack that is already installed for the active locale, and the strings match the
strings WooCommerce renders next to them, so the cart UI stays consistent.

### 5.9 `NonSingularStringLiteralText` — 1 (`Service/Uploads.php:595`)

`error()` wraps the message in `__( $message, 'open-product-fields-for-woocommerce' )`.
All **24** call sites pass plugin-authored literals
(`'Choose a valid file.'`, `'The file exceeds the allowed size.'`, …), so there
is no injection or user-controlled-string risk. Real, but bounded, consequence:
the strings are not extractable into the POT by `wp i18n make-pot`, so upload
error messages stay untranslated. Left unchanged — see §8 (needs owner decision)
for the trade-off.

### 5.10 `load_plugin_textdomainFound` — 1 (`open-product-fields-for-woocommerce.php:121`)

Discouraged since WP 4.6 because wordpress.org-hosted plugins receive language
packs in `WP_LANG_DIR/plugins`. OPF is not distributed that way: it ships its
catalogs inside the plugin (`languages/*.mo`, `Domain Path: /languages`), and
WordPress will not look there without this call — the inline comment at
`:119-120` in the bootstrap says exactly that. Removing it would make the
`es_ES` catalog stop loading. (The bootstrap file is owned by another lane in
this pass; the finding is recorded here, no edit made.)

### 5.11 `PostNotIn_exclude` — 1 (`Service/LinkedProducts.php:182`)

VIP performance advisory against `'exclude' => [ $main_product_id ]` in a
`get_posts()` call that lists the selectable linked products for a builder
preview. A single-post exclusion cannot cause the scan problem the advisory
targets, and the code path is admin/builder-only, not a hot frontend query.

### 5.12 `OutputNotEscaped` on the WAPF label filter — 1 (`Service/Renderer.php:842`)

`$label_content = WapfHooks::field_label( esc_html( $field['label'] ), … )` is
echoed as HTML. That is the documented WAPF contract: upstream applies
`apply_filters( 'wapf/html/field_label', $label_content, $field, $product )` and
concatenates the result into the label
(`advanced-product-fields-for-woocommerce-extended/includes/classes/class-html.php:540-542`).
The default (no listener) path is `esc_html()`; only PHP code — a plugin or
theme — can inject HTML. Compensating control: the sibling description path does
run `wp_kses_post()` after its filter (`Renderer.php:856`), so untrusted stored
content is filtered; the label path keeps WAPF parity deliberately.

## 6. Bucket (c) — false positives

### 6.1 `OutputNotEscaped` — 50 findings in `Service/Renderer.php` + 1 in `Service/LayeredImages.php:705`

Every one is escaped by construction; PHPCS cannot follow the escaping through a
helper, a closure or a variable assembled earlier in the method. The four
mechanisms, with the full line inventory (Appendix A repeats these lines):

| Mechanism | Lines | Why it is escaped |
| --- | --- | --- |
| Attribute strings assembled earlier with `esc_attr()`/`esc_url()` | 478 (`$group_attrs`, built at 443–476 incl. `attr_json()` which `esc_attr`s the JSON), 506 (`$edit_rows_attr`), 657 (`$field_attribute`), 755 (`$repeat_attr`), 863/870 (`$tid`, `$instruction_id`), 939 (`$container_attrs`), 1015 (`$limit_attrs`), 1101 (`$hint_attrs`, `selected()`, `pricing_attrs()` → `esc_attr` at 1667), 1207/1275 (`$wrapper_attrs`, `$choice_label_attr`, 1153–1205), 1487/1529/1591, 1742 (`$editor_attrs`) | `esc_attr`/`esc_url` is applied to every interpolated value at assembly time; the literal markup in between carries no data |
| Escaped helper/closure returns | 1498/1532/1593 (`$input_attrs( … )` closure @1390, `sprintf` with `esc_attr` per placeholder), 1487/1529/1591 (`$wrapper_attrs( … )` closure @1436), 1549/1571 (`self::product_slot_info()` @1605 → `esc_attr`/`esc_url`/`wp_kses_post`), 842/1325 (+ 1081, 1497, 1594) `self::pricing_hint_html()` @149 → `wc_price()` or `esc_html()` at 186–188, and `$hint()` @1444 → `wc_price()` | the sniff does not cross method/closure boundaries |
| WordPress-generated HTML | 766 (`wp_get_attachment_image()`, already an allow-listed `<img>`), 1081 (`$label` is `esc_html( $choice['label'] )` from line 1048) | core output / already-escaped local variable |
| Already-sanitized HTML | 882, 864 (`$description_html` is `esc_html()` on the default path or `wp_kses_post()` after a filter), 1103 (`strip_tags()` of the escaped pricing hint), 705 in `LayeredImages.php` (`$attributes` built from `esc_attr`/`esc_url` at 704) | escaping/allow-list sanitization has already happened |

### 6.2 `ValidatedSanitizedInput.InputNotSanitized` — 13 findings

| Site | Reason it is not a gap |
| --- | --- |
| `Uploads.php:55,68,122,218,61,62,63` (also in §6.3) | `$_FILES` structures are not string input (validated by `validate_file()`: `is_uploaded_file`, size, extension allowlist, `finfo` MIME); `:68/:122` feed `wp_verify_nonce()`; `:61-63/:218` are `$_SERVER` values used only inside `same_origin()` and a `realpath` containment guard |
| `Renderer.php:258` | `$_GET['opf_e2e']` / `$_SERVER['HTTP_X_OPF_E2E']` are only compared with `hash_equals()` against `OPF_E2E_TOKEN`; the raw value is never stored or echoed (`phpcs:ignore` already documents the nonce-free read) |
| `LivePreviewFonts.php:124` | value passes through `normalize_font_name()`, a strict allowlist `^[A-Za-z0-9][A-Za-z0-9 _-]{0,63}$` |
| `Admin/FormulaVariables.php:32` | `check_admin_referer()` runs first; the string is `json_decode`d and then typed by `FieldGroup::normalize_formula_variables()` |
| `LayeredImages.php:475` | JSON blob whose every key is regex-validated and every id `absint`ed, then `LayeredImageConfig::normalize()` (and the WooCommerce save gate, §5.5) |
| `LivePreview.php:133` | length-capped (65536), `json_decode`d, then `PreviewSchema::normalize()` (and the WooCommerce save gate, §5.5) |
| `QuantityPrefill.php:38` | read-only display prefill; `apply()` requires `^[0-9]+(?:\.[0-9]+)?$D` and `is_finite()` before use |
| `LookupTableCsvImporter.php:170` | `current_user_can( 'manage_woocommerce' )` + `check_admin_referer()` first, then `is_uploaded_file`, size cap, and a parser that never persists the file |

### 6.3 `MissingUnslash` — 9 findings

`Uploads.php:61,62,63,218` (`$_SERVER` — WordPress's own recommendation is not to
unslash server variables; both are normalized before use),
`Renderer.php:258` (`$_SERVER` / constant-time comparison),
`LivePreviewFonts.php:112` (`$_GET['section']` is only compared with a literal;
never stored, queried or echoed),
`Uploads.php:68,122` (`$_POST`/`$_GET` nonce values passed straight into
`wp_verify_nonce()`, which hashes the value — no output, query or filesystem use).

## 7. Vendored third-party — `includes/ThirdParty/Url/` (52 findings, out of scope)

The scoped WHATWG URL parser stack, shipped verbatim and autoloaded by
`includes/ThirdParty/Url/loader.php` (no runtime Composer); provenance and
per-component licences are recorded in
`docs/RELEASE-PROVENANCE-2026-10-06.md` §3:

| Component | Version | Findings |
| --- | --- | --- |
| rowbot/url | 3.1.7 | 20 `ExceptionNotEscaped` |
| brick/math | 0.9.3 | 11 `ExceptionNotEscaped` |
| symfony/polyfill-mbstring | 1.31.0 | 6 `ExceptionNotEscaped`, 6 `OutputNotEscaped`, 9 `trigger_error` |

All 52 are the same two WPCS rules already triaged for own code (§5.1, §6.1)
plus `WordPress.PHP.DevelopmentFunctions.error_log_trigger_error`, which is how
the polyfill reports a missing `mbstring` extension. Editing vendored files would
diverge them from the pinned upstream revisions that the checksum manifest
tracks, so the decision is: **ship as-is**, and if a wordpress.org submission
requires a clean report, ship the upstream packages under a
`phpcs:ignore`-exempt path via the packaging/scoper step (owner decision, §8).

## 8. Needs owner decision

1. **`Uploads.php:595` `__( $message )` (`NonSingularStringLiteralText`).**
   Keeping the wrapper costs translation extraction for 24 customer-visible
   upload error messages; inlining the literals at the call sites fixes it but
   touches 24 lines of live code. Neither option is silently preferable.
   Recommendation: inline at the next i18n/translation pass — the i18n lane
   already owns `languages/` — or accept the wrapper and record it.
2. **How to handle the vendored 52** in a wordpress.org submission: ship as-is,
   or exclude the parser stack from the submission pass (build/packaging
   decision, §7).
3. **`TextDomainMismatch` (§5.8).** Keeping WooCommerce's textdomain is correct
   while those strings are absent from the OPF catalog. If the i18n lane adds
   `In stock` / `Update cart` / `Cart updated.` to the OPF catalog, switching the
   domain becomes the better choice; until then, switching is a UI regression for
   `es_ES`.
4. **Provenance manifest staleness.** The three edited files are hashed in
   `docs/RELEASE-PROVENANCE-2026-10-06-manifest.sha256`
   (`includes/Service/LayeredImages.php:49`, `LivePreview.php:51`,
   `Renderer.php:58`) and their hashes are now stale. Not regenerated here: the
   final build/manifest pass is owned by the orchestrator.

## 9. Verification log (after the fixes)

| Command | Result |
| --- | --- |
| `php -l includes/Service/LayeredImages.php` | `No syntax errors detected` |
| `php -l includes/Service/LivePreview.php` | `No syntax errors detected` |
| `php -l includes/Service/Renderer.php` | `No syntax errors detected` |
| `vendor/bin/phpunit --no-coverage` | `Tests: 1086, Assertions: 4590, PHPUnit Deprecations: 2` — 0 failures (matches the 1086/4590/0 baseline) |
| `node --test tests/js/*.cjs` | `tests 130, pass 130, fail 0` (matches the 130/130 baseline) |
| `wp plugin check open-product-fields-for-woocommerce --format=json` | 239 → **236** findings (195 E / 44 W → 192 E / 44 W) |
| finding-set diff before/after | only the 3 `MissingTranslatorsComment` findings removed; the two `Renderer.php:870` `OutputNotEscaped` findings are the same two at shifted columns |

## 10. Limits of this pass / residual risk

- The check ran against the **built 0.1.1 archive** in the clone with the three
  fixed files copied over it. The source tree and that archive were proven
  byte-identical for every shipped file before the run, so this is equivalent for
  `includes/`, but a full `bash bin/build.sh` + re-run is still required for a
  release-gate number.
- No security defect was found and fixed: every finding in the security families
  (`EscapeOutput`, `ValidatedSanitizedInput`, nonce, `ForbiddenFunctions`) is
  either escaped/sanitized by construction or gated by an existing
  capability/nonce check (§5, §6). The only edits were the three missing
  translator comments.
- Not re-checked: the browser-level E2E suites and production smoke test — the
  diff is three comment-only lines and cannot affect served bytes; the shipped
  blade/asset build is untouched.
- This triage covers the plugin-authored surface; conclusions about WooCommerce's
  own gate cite the versions installed in the clone (WooCommerce 11.1.0).

## Appendix A — every plugin-authored finding (code + file + lines)

Rows group by code and file; the line column is exhaustive, so every one of the
187 own-code findings appears exactly once. Bucket letters are from §3.

| Code | File | Count | Lines |
| --- | --- | ---: | --- |
| `Found` (`move_uploaded_file`) | `includes/Service/LivePreviewFonts.php` | 1 | 156 |
| `Found` (`move_uploaded_file`) | `includes/Service/Uploads.php` | 1 | 317 |
| `load_plugin_textdomainFound` | `open-product-fields-for-woocommerce.php` | 1 | 121 |
| `DirectQuery` | `includes/Service/Importer.php` | 2 | 127, 165 |
| `DirectQuery` | `includes/Service/Uploads.php` | 1 | 553 |
| `NoCaching` | `includes/Service/Importer.php` | 2 | 127, 165 |
| `NoCaching` | `includes/Service/Uploads.php` | 1 | 553 |
| `ExceptionNotEscaped` | `includes/Engine/CapabilityFixtureRegistry.php` | 1 | 907 |
| `ExceptionNotEscaped` | `includes/Engine/FieldGroup.php` | 14 | 105, 752, 955, 1131, 1150, 1157, 1329, 1375, 1379 ×3, 1387, 1393, 1397 |
| `ExceptionNotEscaped` | `includes/Engine/RepeaterField.php` | 2 | 49, 54 |
| `ExceptionNotEscaped` | `includes/Service/ArchiveImporter.php` | 9 | 34, 49, 53, 59 ×3, 62, 65, 68 |
| `ExceptionNotEscaped` | `includes/Service/CartIntegration.php` | 2 | 216, 218 |
| `ExceptionNotEscaped` | `includes/Service/WapfExporter.php` | 9 | 204 ×2, 208 ×2, 249, 782, 808, 933 ×2 |
| `ExceptionNotEscaped` | `includes/Service/WapfJsonFileImporter.php` | 2 | 42, 93 |
| `OutputNotEscaped` | `includes/Service/LayeredImages.php` | 1 | 705 |
| `OutputNotEscaped` | `includes/Service/Renderer.php` | 50 | 478, 506, 657, 755, 766, 842 ×4, 863, 864 ×2, 870 ×2, 882, 939, 1015, 1081 ×4, 1101 ×3, 1103, 1207, 1275, 1325 ×4, 1487, 1497, 1498 ×3, 1529, 1532 ×3, 1549 ×2, 1571 ×2, 1591, 1593 ×3, 1594, 1742 |
| `Missing` (nonce) | `includes/Service/Admin/CouponSettings.php` | 2 | 36, 37 |
| `Recommended` (nonce) | `includes/Service/FieldGroups.php` | 1 | 159 |
| `InputNotSanitized` | `includes/Service/Admin/FormulaVariables.php` | 1 | 32 |
| `InputNotSanitized` | `includes/Service/LayeredImages.php` | 1 | 475 |
| `InputNotSanitized` | `includes/Service/LivePreview.php` | 1 | 133 |
| `InputNotSanitized` | `includes/Service/LivePreviewFonts.php` | 1 | 124 |
| `InputNotSanitized` | `includes/Service/LookupTableCsvImporter.php` | 1 | 170 |
| `InputNotSanitized` | `includes/Service/QuantityPrefill.php` | 1 | 38 |
| `InputNotSanitized` | `includes/Service/Renderer.php` | 2 | 258 ×2 |
| `InputNotSanitized` | `includes/Service/Uploads.php` | 7 | 55, 61, 62, 63, 68, 122, 218 |
| `MissingUnslash` | `includes/Service/LivePreviewFonts.php` | 1 | 112 |
| `MissingUnslash` | `includes/Service/Renderer.php` | 2 | 258 ×2 |
| `MissingUnslash` | `includes/Service/Uploads.php` | 6 | 61, 62, 63, 68, 122, 218 |
| `file_system_operations_chmod` | `includes/Service/LivePreviewFonts.php` | 1 | 160 |
| `file_system_operations_chmod` | `includes/Service/Uploads.php` | 5 | 224, 298, 318, 462, 496 |
| `file_system_operations_fclose` | `includes/Service/LayeredImages.php` | 2 | 599, 637 |
| `file_system_operations_fclose` | `includes/Service/LivePreviewFonts.php` | 1 | 98 |
| `file_system_operations_fclose` | `includes/Service/LookupTableCsvImporter.php` | 2 | 44, 48 |
| `file_system_operations_fclose` | `includes/Service/Uploads.php` | 3 | 325, 520, 590 |
| `file_system_operations_fopen` | `includes/Service/LayeredImages.php` | 2 | 594, 610 |
| `file_system_operations_fopen` | `includes/Service/LivePreviewFonts.php` | 1 | 93 |
| `file_system_operations_fopen` | `includes/Service/LookupTableCsvImporter.php` | 1 | 29 |
| `file_system_operations_fopen` | `includes/Service/Uploads.php` | 3 | 296, 458, 573 |
| `file_system_operations_fread` | `includes/Service/LayeredImages.php` | 2 | 598, 618 |
| `file_system_operations_fread` | `includes/Service/LivePreviewFonts.php` | 1 | 97 |
| `file_system_operations_fwrite` | `includes/Service/LookupTableCsvImporter.php` | 1 | 33 |
| `file_system_operations_is_writable` | `includes/Service/Cli.php` | 3 | 344, 376, 408 |
| `file_system_operations_is_writable` | `includes/Service/Uploads.php` | 1 | 223 |
| `file_system_operations_mkdir` | `includes/Service/Uploads.php` | 1 | 222 |
| `file_system_operations_readfile` | `includes/Service/Uploads.php` | 1 | 547 |
| `rename_rename` | `includes/Service/Cli.php` | 3 | 348, 380, 412 |
| `rename_rename` | `includes/Service/LayeredImages.php` | 2 | 133, 356 |
| `strip_tags_strip_tags` | `includes/Engine/FieldGroup.php` | 6 | 564, 723, 746, 754, 761, 984 |
| `strip_tags_strip_tags` | `includes/Engine/RepeaterField.php` | 1 | 51 |
| `strip_tags_strip_tags` | `includes/Engine/WapfMapper.php` | 2 | 209, 766 |
| `strip_tags_strip_tags` | `includes/Service/Renderer.php` | 1 | 1103 |
| `unlink_unlink` | `includes/Service/Cli.php` | 3 | 350, 382, 414 |
| `unlink_unlink` | `includes/Service/Uploads.php` | 4 | 320, 511, 564, 585 |
| `MissingTranslatorsComment` | `includes/Service/LayeredImages.php` | 1 | 715 **(fixed)** |
| `MissingTranslatorsComment` | `includes/Service/LivePreview.php` | 1 | 76 **(fixed)** |
| `MissingTranslatorsComment` | `includes/Service/Renderer.php` | 1 | 870 **(fixed)** |
| `NonSingularStringLiteralText` | `includes/Service/Uploads.php` | 1 | 595 |
| `TextDomainMismatch` | `includes/Service/CartEdit.php` | 2 | 275, 335 |
| `TextDomainMismatch` | `includes/Service/LinkedProducts.php` | 1 | 282 |
| `PostNotIn_exclude` | `includes/Service/LinkedProducts.php` | 1 | 182 |

## Appendix B — vendored findings (`includes/ThirdParty/Url/`)

| Code | File | Count | Lines |
| --- | --- | ---: | --- |
| `ExceptionNotEscaped` | `brick/math/src/BigDecimal.php` | 2 | 295 ×2 |
| `ExceptionNotEscaped` | `brick/math/src/BigInteger.php` | 8 | 82, 107 ×2, 142, 412 ×2, 827, 855 |
| `ExceptionNotEscaped` | `brick/math/src/BigNumber.php` | 1 | 53 |
| `ExceptionNotEscaped` | `rowbot/url/src/String/AbstractUSVString.php` | 7 | 58 ×3, 66 ×2, 123 ×2 |
| `ExceptionNotEscaped` | `rowbot/url/src/String/IDLString.php` | 1 | 35 |
| `ExceptionNotEscaped` | `rowbot/url/src/String/Utf8StringIterator.php` | 1 | 25 |
| `ExceptionNotEscaped` | `rowbot/url/src/URL.php` | 7 | 66, 71, 155, 194, 243, 287 ×2 |
| `ExceptionNotEscaped` | `rowbot/url/src/URLSearchParams.php` | 4 | 188, 199, 202, 216 |
| `ExceptionNotEscaped` | `symfony/polyfill-mbstring/Mbstring.php` | 6 | 319, 335, 828 ×2, 832 ×2 |
| `OutputNotEscaped` | `symfony/polyfill-mbstring/Mbstring.php` | 6 | 137, 144, 184, 191, 196, 474 |
| `WordPress.PHP.DevelopmentFunctions.error_log_trigger_error` | `symfony/polyfill-mbstring/Mbstring.php` | 9 | 132, 137, 144, 184, 191, 196, 443, 474, 479 |
