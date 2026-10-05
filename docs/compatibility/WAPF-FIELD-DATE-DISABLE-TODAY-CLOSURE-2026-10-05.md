# WAPF-FIELD-DATE `disable_today` — closed

Date: 2026-10-05. Baseline: installed WAPF Extended 3.1.5 (parity reference only).

## 3.1.5 source contract

- `includes/classes/class-config.php:735-744` — the `date` field's
  `true-falses` "Selection options" group carries `disable_past`,
  `disable_future`, and `disable_today` ("Today's date can't be selected").
- `includes/classes/class-cart.php:271,278` (`validate_date_field`) — server:
  `if ( $disable_today && $interval->days == 0 )` rejects the submitted date.
- `views/frontend/fields/date.php:8,53` — the picker's `filter` returns `false`
  for `isToday` when `disable_today` is set.
- `includes/controllers/class-extended-controller.php:185-196` — the related
  `disable_today_after` time cutoff is a separate key (already mapped to OPF
  `cutoff_time`); `disable_today` is the boolean day ban.

## Before / after

OPF had no equivalent: the mapper flagged `disable_today` for review, so a
migrated date field configured to ban today allowed today.

Now:

- `FieldGroup::normalize_field` persists boolean `disable_today` (only when
  authored, so existing date fields keep their canonical shape).
- `FieldValue::validate` rejects the submitted value when it equals the site's
  current date and `disable_today` is set.
- `Renderer` emits `data-opf-disable-today="1"` and the site clock
  (`data-opf-date-site-epoch` / `data-opf-date-timezone`); the frontend
  `dateSelectionAllowed` and `validateDateRestrictions` disable/flag today and
  re-evaluate on the cutoff ticker.
- `WapfMapper::map_date_settings` maps `disable_today`;
  `WapfExporter::map_date_settings` re-emits it. Fail-closed flagging removed.

## Tests

- `tests/Unit/DateDisableTodayTest.php` — normalization, today rejected,
  adjacent days allowed, invalid value rejected.
- `tests/Unit/WapfMapperTest.php` — WAPF `disable_today` maps without review.
- `tests/Unit/WapfExporterTest.php` — `disable_today` round-trips through the
  Tools payload.
- `tests/js/opf-date-bounds.test.cjs` — `dateSelectionAllowed` blocks today and
  allows tomorrow when the flag and a site clock are present.

## Residual

- None for the boolean day ban. `disable_today_after` (time cutoff) was already
  mapped and remains covered by `DateCutoffTest`.
