# Checkbox columns: OPF implementation and evidence

## WAPF source boundary

The official WAPF Pro changelog says version 3.2 added a checkbox `columns` setting for
multi-column presentation. It does not publish the setting range, saved field shape, responsive
rules, or markup details. The installed Extended 3.1.5 `includes/classes/class-config.php`
defines `checkboxes` with options and selection limits only; it has no checkbox `columns` control.
Its `class-field-groups.php` imports unreserved flat Tools keys into `Field::$options`, and stored
field-group models serialize per-field settings under `options`. Therefore OPF maps flat Tools
`columns` and WXR model `options.columns`, but this exact Pro 3.2 mapping is not runtime verified.

Source: [official WAPF Pro changelog](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/changelog/).

## OPF behavior

OPF checkbox fields now normalize a positive integer `columns` setting, defaulting to one. The
builder control writes that property into its existing field model. The renderer places choice
labels in a CSS grid with the configured fixed column count. Each input remains a native checkbox
nested in its associated label. The renderer does not claim WAPF's undocumented responsive behavior.

WAPF Tools export emits the flat `columns` field key. WAPF WXR serialization writes it under
`options.columns`. Import accepts the flat Tools form and nested serialized form. Values outside a
positive platform integer are review-required on import or rejected by native schema validation.

## Verification

- `FieldGroupSchemaTest`: default and positive integer preservation; zero rejected.
- `WapfMapperTest`: flat Tools key and nested WXR option mapping.
- `WapfExporterTest`: WAPF Tools key serialization.
- `WapfWxrExporterTest`: WXR options serialization followed by mapper reimport.
- `RendererImageSwatchTest`: rendered CSS property and intact native label/input association.
- `bin/e2e-checkbox-columns-browser-test.mjs`: isolated Chromium checks computed configured grid
  at widths 1280/600/400, accessible label lookup, and Space-key toggling. This uses OPF's CSS and
  fixture markup; it does not compare with WAPF.

Commands run on 2026-10-04:

```sh
/tmp/opf-checkbox-columns-vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/FieldGroupSchemaTest.php tests/Unit/WapfMapperTest.php tests/Unit/WapfExporterTest.php tests/Unit/WapfWxrExporterTest.php tests/Unit/RendererImageSwatchTest.php
# OK (159 tests, 739 assertions)
node bin/e2e-checkbox-columns-browser-test.mjs
# configured grid count retained across viewports; label and keyboard checks pass
```

Admin REST save/reload, full Woo cart/order lifecycle, and WAPF Pro 3.2 Tools/WXR parser/runtime
comparison remain unverified. Keep this capability partial until those gaps are proven.
