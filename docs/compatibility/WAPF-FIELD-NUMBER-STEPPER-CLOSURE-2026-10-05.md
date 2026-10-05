# WAPF-FIELD-NUMBER-STEPPER — closed (per-field `display: plus_min`)

Date: 2026-10-05. Baseline: installed WAPF Extended 3.1.5 (parity reference only).

## 3.1.5 source contract

- `includes/classes/class-config.php:613-621` — the `number` field carries a
  `display` select with options `default` and `plus_min`, default `default`,
  tab `appearance`.
- `views/frontend/fields/number.php:3-20` — when
  `options['display'] === 'plus_min'` the renderer wraps the input in
  `<div class="apf-plusmin">` and emits `<button class="button apf-minus">` /
  `<button class="button apf-plus">` with `tabindex="-1"` and aria-labels
  `Reduce` / `Increase`.
- `includes/classes/class-html.php:869-885` — the shared quantity renderer uses
  the same `wapf-qty apf-plusmin` wrapper and button classes.
- `assets/js/frontend.min.js` — delegated handler on `.apf-minus, .apf-plus`
  reads the sibling input's `min`/`max`/`step`, skips disabled inputs, and
  clamps the incremented value.
- `includes/classes/class-field-groups.php:166` — `display` is a reserved raw
  key persisted on the field options.

## Before / after

OPF shipped a single global toggle (`opf_number_buttons`) and the mapper flagged
`display: plus_min` for review. A merchant who wanted steppers on two of six
number fields got them on all or none, and `.apf-plusmin` / `.apf-plus` /
`.apf-minus` theme CSS was dead.

Now:

- `FieldGroup::normalize_field` persists a per-field `display`
  (`default`|`plus_min`; anything else normalizes to `default`) — absent means
  "use the global default".
- `Renderer::render_input` renders the stepper when the field sets `plus_min`
  **or** when no per-field value is set and the global
  `opf_number_buttons` setting is on. The wrapper carries `opf-number-stepper
  apf-plusmin` and the buttons carry `opf-number-stepper__button button
  apf-minus` / `apf-plus` so WAPF theme CSS applies; OPF's own hook classes and
  descriptive labels stay.
- `WapfMapper::map_number_settings` maps `display` and no longer flags it;
  `WapfExporter::map_number_settings` re-emits it.
- The builder exposes a "Number controls" select bound to `field.display`.

## Tests

- `tests/Unit/NumberFieldDisplayTest.php` — schema normalization, invalid
  fallback, absent override, non-number ignore.
- `tests/Unit/RendererNumberStepperTest.php` — per-field `plus_min` wins over
  the global setting; per-field `default` suppresses the global stepper; absent
  value follows the global setting.
- `tests/Unit/WapfMapperTest.php` — WAPF `display: plus_min` maps (no review);
  absent display leaves no override.
- `tests/Unit/WapfExporterTest.php` — `display` round-trips through the Tools
  payload.
- `tests/js/opf-builder.test.cjs` — the builder saves `field.display`.

## Residual

- OPF keeps its descriptive aria-labels ("Decrease {field}") instead of WAPF's
  plain "Reduce"/"Increase"; the WAPF class contract and clamping behaviour are
  matched. `hide_zero` remains review-required (no OPF equivalent), tracked
  under `WAPF-FIELD-NUMBER-STEP-VALIDATION`.
