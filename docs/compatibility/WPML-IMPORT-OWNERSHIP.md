# WPML ownership for new WAPF imports

Checked 2026-10-01 against the official guides and the installed WAPF Extended
3.1.5 source. This is a source contract and unit-test result, not verification
against a running WPML installation.

## Source contract

[Wombat's WPML guide](https://www.studiowombat.com/knowledge-base/how-to-translate-fields-with-wpml/)
requires global field-group CPTs to be translatable, duplicated per language,
and edited independently. Product-local fields belong to translatable products
and variations. The installed Extended source independently confirms that
global-group queries enable WPML filters (`class-field-groups.php:560–561`)
and product-local group data is saved on the product (`class-admin-controller.php:1067`).
The importer currently reads product-local groups from products only.

[WPML's element language hook](https://wpml.org/wpml-hook/wpml_element_language_code/)
reads the language from WPML's translation table using the element ID and post
type. New WAPF imports therefore read `wapf_product` for global groups and
`product` for `meta:<product-id>` sources. A validated result is stored as
`_opf_wpml_source_language` on the new OPF group. Missing or malformed results
remain unresolved; the administrator's current language and site default are
never substituted.

## Runtime and save behavior

Owned WAPF imports retain their existing labels, choice identifiers, pricing,
and placement IDs. In a concrete WPML language, OPF renders only imports owned
by that language. It does not map an independently translated import into other
languages, which would duplicate fields when both source and translated groups
were imported. With WPML absent or its current language set to `all`, entries
remain unchanged.

Native OPF groups continue to use the existing package translation and target
mapping path. Imported groups do not register packages on save: the
[documented package registration hook](https://wpml.org/wpml-hook/wpml_register_string/)
does not expose an explicit source-language argument. Existing packages are
not removed or migrated.

Import markers are persisted with WordPress's
[`meta_input`](https://developer.wordpress.org/reference/functions/wp_insert_post/)
before `save_post` callbacks. This applies to WAPF provenance and archive
provenance/checksums, so the registration guard can identify newly inserted
imports during their first save.

Historical imports are never backfilled or reimported. Archive version 1
exports `language` from Polylang; it has no WPML provider or ownership contract.
Archive IDs do not identify source elements on the receiving site, so archive
imports retain unresolved WPML ownership and their existing runtime behavior.
Removing only `_opf_wpml_source_language` from an owned group reverses language
filtering to the historical import path; stored group JSON and source WAPF data
are untouched.

## Verification

The focused tests cover native package lifecycle, native translated text and
targets, global/local source language lookup, metadata before save hooks,
missing ownership, dry runs, idempotent historical imports, archive provenance,
language switching, absence of duplicate imported options, source-object
preservation, and product cache isolation. On PHP 8.5.11, the 15 focused tests
pass with 83 assertions, and the full 210-test suite passes with 794 assertions.
One pre-existing PHPUnit annotation deprecation remains. PHP syntax checks and
`git diff --check` also pass.

Remaining proof requires a running WPML/WCML installation: language ownership
lookup against real records, multilingual global/local product rendering,
translation editor behavior for native packages, and multilingual cart/order
behavior. No production import, metadata migration, or deployment was run.

## Reproducibility update (2026-10-05)

This doc cites no deleted artifacts, so it needs no correction. The hook-surface
behavior above is now re-provable without a running WPML install:
`bin/e2e-wpml-proof.php` (contract stub `bin/e2e-wpml-stub.php`) reproduces the
package, translation and ownership checks on a disposable `/tmp` clone and stages
`docs/compatibility/locale-proof-20261005/wpml-proof.json` — **24 PASS / 0 FAIL /
3 SKIP**. The three skips need real WPML element records and stay unprovable on
this host. See `LOCALE-POLYLANG-WPML-EVIDENCE-2026-10-05.md` for the full status.
