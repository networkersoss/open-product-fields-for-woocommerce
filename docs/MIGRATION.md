# WAPF → OPF Migration Runbook

Field groups live in the **site's own database**, not in either plugin. The
importer copies them into OPF's own storage; the originals are untouched
until the separate deletion step (see `WAPF-DELETION.md`).

## Historical verification record (Sept 2026)

The following results record the followersya cutover test phase. They are not
a general compatibility claim or a substitute for validating a new OPF
release. The implemented feature scope is [CAPABILITIES.md](CAPABILITIES.md).

Evidence from this repository's test phase (disposable WP 7.1 + WooCommerce
11.1 environment):

| Check | Result |
| --- | --- |
| Unit tests (pricing, conditionals, parser, mapper) | 32/32 |
| E2E lifecycle (classic + Store API + orders + compat) | 28/28 |
| Real-data import (production corpus, commit mode, disposable DB) | 747 groups, all fidelity checks green |
| Real-data import (production, read-only dry run) | 657 import-ready, 0 unparseable, 21 repaired, 0 needs-review |

"21 repaired": 21 per-product payloads on production fail PHP's
`unserialize()` because multibyte characters broke the byte-length prefixes
(charset damage). They were already **silently dead inside WAPF** — the OPF
importer's recovering decoder resurrects them.

## OPF-native group archive

On a site already running OPF, create a versioned JSON archive with WP-CLI:

```bash
wp opf export --group=123 --output=/tmp/opf-group.json
wp opf export --product=456 --output=/tmp/opf-product.json
wp opf export --all --output=/tmp/opf-all.json
```

The package contains the OPF schema data and group title, status, order, and
language metadata. Product scope includes the published groups that currently
match that product; all scope includes drafts and other non-trashed groups.
Each group and the package report portability warning codes when image files,
product-target IDs, or referenced site-level formula resources are external to
the archive. The export preserves image URLs but does not copy image bytes.

This OPF-native archive is distinct from WAPF's export format. OPF package
import is not implemented yet, so retain the archive as a recovery/interchange
file; it cannot currently be imported through an OPF command.

## WAPF-compatible single-group JSON

WAPF also accepts one field group's JSON through its product/group editor.
Export that format only when one OPF group maps cleanly to WAPF:

```bash
wp opf export --group=123 --format=wapf-json --output=/tmp/wapf-group.json
wp opf export --product=456 --format=wapf-json --output=/tmp/wapf-product.json
```

WAPF's own JSON parser accepts this file for import into a product or an
existing global group. OPF rejects unsupported values instead of silently
dropping them. The currently verified subset covers text, textarea, email,
URL, number, true/false, select, radio, checkbox, and plain paragraph fields;
choice/field flat or per-unit fixed and per-unit percent pricing; supported
show/all field conditions; and product/category/tag placement rules.
Swatches/cards, choice images and descriptions, disabled choices, formulas,
lookup tables, advanced constraints, flat percentage pricing, quantity-only
add-ons, HTML paragraph content, and other unverified values are refused.

This JSON contains fields and placement only. It does not transfer the OPF
group title, status, order, language, or media files. Product/category/tag IDs
remain source-site IDs and may need remapping on the destination. The CLI
prints these caveats; `--all` is not offered in WAPF JSON because WAPF's
documented all-groups transfer is WordPress WXR, not a JSON field-group file.
The OPF-native `--all` archive remains available for OPF recovery.

## WAPF all-groups WXR export

WAPF's documented all-groups transfer uses WordPress's built-in WXR export.
OPF can emit the same importable global-group post records after strict mapping:

```bash
wp opf export --all --format=wapf-wxr --output=/tmp/wapf-groups.xml
```

Import the XML with **Tools → Import → WordPress** on a site where WAPF is
active. The file stores global groups as WAPF's `wapf_product` posts with its
serialized field-group data. Each group passes the same loss checks as
`wapf-json`; one unsupported group stops the whole export before writing.
Product/category/tag placement IDs remain site-local. Image bytes, language
assignments, and OPF-only metadata are not included. The OPF-native `--all`
JSON archive remains available for lossless OPF recovery.

## Phase 0 — Snapshot (read-only, any time)

```bash
cd bedrock
wp eval-file web/app/plugins/open-product-fields-for-woocommerce/bin/wapf-export.php /tmp/wapf-export.json
```

Keep this file; it is both the safety net and the input for staging
rehearsal.

## Phase 1 — Staging rehearsal

1. Copy production to staging (DB + uploads).
2. `wp plugin activate open-product-fields-for-woocommerce`
3. `wp opf import-wapf --commit`
4. Verify one product per field-type family (text-swatch, url, textarea):
   fields render, price updates with each choice, add-to-cart carries the
   chosen values into cart and order.
5. Re-run `wp opf import-wapf` — must report 0 imported (idempotent).

## Phase 2 — Production rollout

1. `wp plugin activate open-product-fields-for-woocommerce`
2. `wp opf import-wapf --commit`
3. `wp opf report` — confirm count matches the staging run.
4. Place a live test order on one product per type; verify cart line shows
   the choices and the order item stores per-field meta.

Groups whose import report has `needs_review: true` are saved as drafts. Review
the reported notes and complete the missing behavior before publishing them;
the importer will not expose a partial form on the storefront.

**Theme compatibility mode is ON by default** (`opf_theme_compat` option):
OPF renders the legacy `wapf-*` class skeleton, `data-wapf-price` attributes,
`wapf-checked` toggling and the `window.wapf_config` formatting global, so
the current theme integration keeps working untouched. Rollback is trivial at
this point: deactivate OPF, re-activate WAPF — no shared data was modified.

## Phase 3 — Theme & integration port (separate workstream)

De-WAPF the codebase (inventory in `WAPF-DELETION.md`):

- `themes/framework/modules/wapf.php` → add and test an OPF extension point
  for the required Polylang locale-targeting behaviour before retiring the
  WAPF-specific parts. OPF 0.1.0 does not provide an
  `opf/product_field_groups` filter.
- Theme CSS (`field-accordion.css`, `product.css`, `pro.css`) → retarget
  `.wapf-*` selectors to `.opf-*`, or keep compat mode.
- Theme JS (`quantity.js`, `form-shell.js`, `field-accordion.js`,
  `add-to-cart.js`, `poll-preview.js`) → retarget `.wapf-*` / `wapf_config`
  reads to OPF equivalents.
- `mu-plugins/nova-youtube-startcount.php` + `nova-spotify-start-count.php`
  → read BOTH `_opf_fields` (new orders) and `_wapf_meta` (historical orders).
- `mu-plugins/wapf-ajax-fix.php`, `nova-wapf-multilingual-fix.php` → delete
  (they patch WAPF internals OPF does not have).
- `filters.php` → drop the WAPF pricing-guard reset (OPF does not have that
  bug; it prices through `woocommerce_before_calculate_totals` with an
  idempotency guard).

## Phase 4 — Compat off, then deletion

1. `wp option update opf_theme_compat off` and re-verify a product page.
2. Follow `WAPF-DELETION.md` for the removal order and per-item verification.

## Rollback

At any point before Phase 4 item 2: deactivate OPF, re-activate WAPF. WAPF's
own storage (`wapf_product` posts, `_wapf_fieldgroup` meta) is never written
by OPF — the import is copy-only.

## OPF archive transfer

To move OPF groups between sites, create an archive with `wp opf export
--all --output=/path/to/opf-groups.json`, then validate it on the destination
with `wp opf import-archive /path/to/opf-groups.json`. The command is a dry run
unless `--commit` is supplied. Imports are size- and group-count-limited,
reject settings the destination version would drop, and skip repeated source
groups. Product/category/tag IDs, missing media files, language assignments
without Polylang, and other export warnings force the imported group to draft
and attach review notes. Verify placement and media on the destination before
publishing those drafts.

To export one supported OPF group as the four-section WAPF Tools JSON payload,
run `wp opf export --group=<id> --format=wapf-json` (or select a product that
resolves to exactly one group). Unsupported or lossy settings stop export.
The payload omits OPF post title/status metadata; product/category/tag IDs are
site-local and need review on the destination.

To transfer WAPF-compatible global groups through WordPress's importer, run
`wp opf export --all --format=wapf-wxr --output=/path/to/wapf-groups.xml`
(or use `--group=<id>` to transfer one group).
Import the XML on the destination from **Tools → Import → WordPress**. The
export includes group titles, statuses, dates, order, fields, field conditions,
pricing, and layout. It stops if any selected group's settings cannot be
represented by WAPF. WXR does not carry OPF product/category/tag placement,
media files, Polylang assignments, or OPF-only settings; review imported groups
and configure their placement on the destination before publishing.
