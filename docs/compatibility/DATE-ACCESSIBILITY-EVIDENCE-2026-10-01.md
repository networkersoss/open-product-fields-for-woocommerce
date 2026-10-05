# Date-picker accessibility evidence

OPF's date picker is a native date input enhanced with a custom calendar.
Installed WAPF Extended 3.1.5 source uses non-focusable day spans, removes the
month/year controls from tab order, and has no keyboard day-navigation handler
or calendar roles. The published Extended 3.2.1 changelog says only that
date-picker accessibility was improved; exact new behavior is not documented
and the 3.2.1 package is not available locally.

## Executed browser checks

On a real Chromium page with the actual plugin frontend JS and CSS, **26/26
checks passed**. Coverage includes the calendar dialog, grid row/cell and day
button semantics, selected state, live month/year announcement, WordPress
configured Monday week start, display formatting, canonical ISO submission,
selection close/focus behavior, arrow movement over disabled days, Page Up/Down,
Shift+Page Up/Down year movement, Home/End by configured week, Escape focus
return, typed invalid-date custom validity, and clearing validity for an
allowed date. No browser page errors occurred.

```sh
node bin/e2e-date-blackout-browser-test.mjs
```

The test uses direct fixture markup and loads the actual `assets/js/opf-frontend.js`
and `assets/css/opf-frontend.css`; it does not claim a complete WordPress/Woo
product checkout flow. `RendererDateTest` verifies the configured week start
reaches rendered markup. Main-branch verification also passed `composer test`
(241 tests/954 assertions before the formula-import lane), PHP/JS syntax checks,
and `git diff --check`.

## Grading against the installed 3.1.5 baseline (2026-10-05)

Owner decision D1 makes the installed Extended 3.1.5 the parity baseline, so
the missing 3.2.1 package is a version bound, not a residual. Against 3.1.5 the
reference datepicker has non-focusable day cells, no calendar roles,
`tabindex=-1` month/year controls and no keyboard day navigation; OPF provides
the ARIA dialog/grid, focusable day buttons, live month/year announcements,
PageUp/Down (with Shift for years), Home/End by configured week and Escape
focus return verified above. OPF is therefore a strict accessibility superset
of the reference, and the ledger row is `supported`.
