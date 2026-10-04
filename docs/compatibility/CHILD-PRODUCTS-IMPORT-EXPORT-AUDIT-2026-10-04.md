# Child-products import/export audit — 2026-10-04

Scope: compare WAPF Extended 3.1.5 linked `products` Tools JSON, stored/WXR
representations, and OPF archive serialization/import. This is a bounded data
fidelity check; it does not close the broad child-products parity row.

## Source contract

Installed WAPF 3.1.5 source in the disposable clone:

- `includes/classes/class-field-groups.php` flattens each field's `options`
  into top-level keys in `field_group_to_raw_fields_json()` and parses those
  keys back into the native model in `raw_json_to_field_group()`.
- `includes/controllers/class-linked-products-controller.php` handles
  `product_selection` and `product_query` in
  `sanitize_field_data()`. It accepts `manual`/`category`; category query IDs,
  labels, sort and `fixed`/`none` pricing are normalized, and query limits are
  clamped to 1–50. Child `products` choices use the product ID in `id`;
  quantity subtype defaults/min/max live in `choices[].options`.
- WAPF's WXR parser uses `Field_Groups::process_data()` to recover the
  serialized `FieldGroup` model.

OPF maps these formats through `Engine/WapfMapper.php`,
`Service/WapfExporter.php`, `Service/WapfWxrExporter.php`, and
`Service/ArchiveImporter.php`. OPF archive export warns that placement and
linked product/category target IDs are site-local. Import preserves these
references and creates a review draft instead of silently remapping IDs.

## Verification

Disposable clone: `/tmp/opf-child-import-export-wp`, WordPress 7.1.2,
WooCommerce 11.1.0, PHP 8.5.11, OPF 0.1.0 from the audited worktree, and WAPF
Extended 3.1.5. WAPF and OPF were active only in this cloned SQLite database.

Native WAPF Tools import/export plus WXR parse:

```sh
OPF_WAPF_PRODUCTS_E2E_ALLOW=1 \
OPF_WAPF_PRODUCTS_E2E_ARTIFACTS=/tmp/opf-child-import-export-evidence \
wp --path=/tmp/opf-child-import-export-wp \
  eval-file /tmp/opf-child-import-export-wp-products-roundtrip.php
```

Result: **44/44 assertions passed**. OPF Tools JSON was consumed by WAPF's
actual `raw_json_to_field_group()`, flattened again with
`field_group_to_raw_fields_json()`, remapped to OPF, and reached a stable
second-generation fixed point. The fixture covered manual child product IDs,
`fixed`/`none` pricing, selected/disabled flags, card layout, category query
IDs/settings, quantity choice defaults/min/max, and group/field metadata. The
WXR output was parsed by WAPF's actual `Field_Groups::process_data()` and
remapped. The only review notes were pre-existing formula `lookuptable(...)`
runtime references in the shared fixture.

OPF archive portability with child-product references:

```sh
OPF_ARCHIVE_E2E_ALLOW=1 \
wp --path=/tmp/opf-child-import-export-wp \
  eval-file /tmp/opf-linked-child-import-export/bin/e2e-opf-archive-portability.php
```

Result: **pass**. The archive warns about site-local targets, its dry run writes
nothing, manual child product ID `765432104` and category ID `765432105` survive
encode/decode and actual archive import, the imported group is held as a review
draft, and repeating the import is idempotent. The script compares the complete
source/destination group data and removes only its named fixture posts. The
clone snapshot reports `equal: true` after the native Tools/WXR proof.

Focused regression tests:

```sh
/tmp/opf-archive-import-current/vendor/bin/phpunit \
  --configuration phpunit.xml.dist \
  --filter 'WapfMapperTest|WapfExporterTest|WapfWxrExporterTest'
```

Result: **119 tests, 621 assertions passed**; one existing PHPUnit deprecation.
`php -l bin/e2e-opf-archive-portability.php` and `git diff --check` also pass.

## Limits

- Product and category IDs remain site-local. No cross-site mapping is
  attempted; the warning and review draft are the safety behavior.
- This proves the 3.1.5 parser/export contracts for representative manual,
  category, and quantity fixtures; it is not an exhaustive matrix of every
  field property or extension hook.
- WAPF 3.2.1 and the full child-product cart, tax/currency, edit, variation,
  and order lifecycle remain outside this proof. Keep
  `WAPF-FIELD-CHILD-PRODUCTS` partial.
