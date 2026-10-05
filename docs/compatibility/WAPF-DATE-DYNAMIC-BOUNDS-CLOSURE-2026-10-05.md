# WAPF-DATE-DYNAMIC-BOUNDS — closed (full y/m/d grammar + field-relative bounds)

Date: 2026-10-05. Baseline: installed WAPF Extended 3.1.5 (parity reference only).

## 3.1.5 source contract

- `extend/date.php:55-92` (`wapfe_period_to_date`) — space-separated tokens;
  each token carrying `y`/`m`/`d` contributes to years/months/days
  (`intval(str_replace(unit,'',$token))`), and the intervals are applied
  **days → months → years**:
  `$date->add($dInterval)->add($mInterval)->add($yInterval)`.
- `extend/date.php:110-158` (`wapfe_get_minmax_day`) — a `min_date`/`max_date`
  value that starts with `[field.` is resolved as
  `wapfe_period_to_date( preg_replace('/\[(.+)\]/','',value), string_to_date(
  referenced_field_value ) )`; a plain `mm-dd-yyyy` literal is parsed directly;
  otherwise the period is applied to `now`.
- `extend/date.php:186-217` (`wapfe_validate_cart_data`) — the resolved bounds
  are enforced server-side against the submitted value.
- `assets/js/extended.min.js` — the picker resolves the same expressions in the
  browser (period applied to the referenced field's current value).

## Before / after

OPF resolved plain offsets in years→months→days order and marked any
field-relative expression review-required.

Now:

- `FieldValue::is_date_boundary` accepts ISO dates, relative periods, and
  `[field.<id>]<period>` expressions; `FieldValue::date_boundary_reference`
  extracts the referenced field id.
- `FieldValue::resolve_date_boundary` applies the period **days → months →
  years** (WAPF order; month-end arithmetic differs from the old
  years-first order) and, for field-relative bounds, resolves against an
  optional referenced value. A missing/empty/non-ISO referenced value yields no
  bound, matching WAPF's `if( $target_value )` gate.
- `FieldValue::validate` takes an optional trailing `$siblings` map and
  resolves field-relative bounds against it; `CartIntegration::validate_values`
  passes the group's submitted `$given` map through, so the bound is enforced on
  the real add-to-cart path.
- `Renderer` emits `data-opf-date-min-expression` / `data-opf-date-max-expression`
  for field-relative bounds and the site clock; `opf-frontend.js` re-resolves the
  native `min`/`max` from the referenced date input on every change
  (`resolveRelativePeriodDate` uses the same days → months → years order).
- `WapfMapper::wapf_date_boundary` remaps a WAPF `[field.<wapfId>]` reference
  onto the generated OPF field id (fail-closed if the reference is unknown or
  maps to a dropped field); `WapfExporter::date_bound_to_wapf` re-emits it.
  `FieldGroup::normalize_field` validates the expression grammar.

## Tests

- `tests/Unit/DateDynamicBoundsTest.php` — D→M→Y order documented via a
  month-end case, field-relative resolution, empty-reference parity, malformed
  grammar rejection, and sibling-driven validation.
- `tests/Unit/CartIntegrationDateBoundsTest.php` — the real
  `CartIntegration::validate_values` path rejects a violating
  `[field.start]+1d` submission with the customer message
  `"End date" must be on or after 2026-06-16.`, accepts a valid value, and
  leaves the bound unset when the referenced field is missing/empty.
- `tests/Unit/WapfExporterTest.php` — `disable_today` + field-relative
  `min_date`/`max_date` round-trip through the Tools payload.
- `tests/Unit/WapfMapperTest.php` — field-relative references remap to OPF ids;
  unresolved references stay review-required.
- `tests/js/opf-date-bounds.test.cjs` — browser period order, sibling
  resolution, `min` set/clear on sync, and `disable_today`.

## Residual

- A field-relative bound on a **repeated** date field resolves against the
  top-level submission only (`RepeaterField::validate` does not thread clone
  sibling values); top-level fields are complete. WAPF clone-context parity is
  tracked with the repeater rows.
- `mm-dd`-only (year-less) boundaries remain review-required: OPF stores ISO
  dates and a year-less bound is not stable across time.
