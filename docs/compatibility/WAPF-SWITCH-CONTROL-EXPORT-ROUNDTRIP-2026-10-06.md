# `switch_control` export→reimport round trip — 2026-10-06

## Scope

Closes the `WAPF-FIELD-TRUE-FALSE-SWITCH` residual recorded in
`docs/compatibility/WAPF-CAPABILITY-LEDGER.md`: the OPF field key
`switch_control` (the builder setting behind WAPF Pro's "display true/false
fields or checkboxes as switches") was not in the WAPF exporter's allow-list
and was not imported by the mapper.

Defect as found (`ff25f9e`, pre-change):

- `WapfExporter::allowed_field_keys()` did not contain `switch_control`, so
  `WapfExporter::build_payload()` threw
  `InvalidArgumentException: WAPF Tools export cannot preserve unknown field
  data: switch_control.` for **every** type carrying the key, including the two
  types that can legitimately store it. A group with a switch field could be
  authored and used in OPF but could not be exported to WAPF at all.
- `WapfMapper::map()` never read the key, so a stored WAPF switch field lost
  its switch presentation on import.

## Change

| File | Change |
|---|---|
| `includes/Service/WapfExporter.php` | `allowed_field_keys()` accepts `switch_control` for `toggle` and `checkbox` only (type-scoped extras, lines 51-56); `map_field()` emits `switch_control => true` for those types when the OPF field has it, and omits it when unset (lines 296-300). |
| `includes/Engine/WapfMapper.php` | `map()` imports `options.switch_control` (falling back to a flat `switch_control`) into the normalized toggle/checkbox field; every other mapped type ignores it (lines 264-269, merged in the field array at line 315). |
| `includes/Service/WapfWxrExporter.php` | `serialized_field_group()` carries `switch_control` into the stored `options` bucket (line 109), so the WXR migration document preserves it instead of dropping it. |
| `tests/Unit/WapfExporterSwitchControlTest.php` | New focused test (11 tests / 33 assertions). |

`switch_control` was deliberately **not** added to the shared
`WapfExporter::FIELD_KEYS` list. That base list is merged for every field type,
so a radio/text/select field carrying the key would have been accepted by
`assert_keys()` and then silently stripped by `FieldGroup::normalize_field()`
(which normalizes the key only for toggle/checkbox) — the opposite of the
exporter's fail-closed contract. The key lives in the same type-scoped extras
mechanism already used for `message` (toggle) and `large_image` (products
image), which keeps non-switch types rejecting the key.

## Executed probes (before → after)

Probe sources: `/tmp/opf-switch-probe-20261006/probe.php` (Tools JSON path) and
`/tmp/opf-switch-probe-20261006/probe2.php` (WXR path). "Before" runs against a
pristine `git archive HEAD` copy of the plugin
(`/tmp/opf-switch-probe-20261006/head-copy`); "after" runs against the working
tree.

### Tools JSON path (`WapfExporter::build_payload()` → `WapfMapper::map()`)

Before (`php probe.php head-copy`):

```text
EXPORT FAIL toggle  + switch_control: InvalidArgumentException: WAPF Tools export cannot preserve unknown field data: switch_control.
EXPORT FAIL checkbox+ switch_control: InvalidArgumentException: WAPF Tools export cannot preserve unknown field data: switch_control.
EXPORT FAIL radio   + switch_control: InvalidArgumentException: WAPF Tools export cannot preserve unknown field data: switch_control.
EXPORT FAIL text    + switch_control: InvalidArgumentException: WAPF Tools export cannot preserve unknown field data: switch_control.
EXPORT OK   toggle  (no switch)   : type=true-false switch_control=<absent>
REIMPORT    toggle  (no switch)   : type=toggle switch_control=<absent> needs_review=false
```

After (`php probe.php`):

```text
EXPORT OK   toggle  + switch_control: type=true-false switch_control=true
REIMPORT    toggle  + switch_control: type=toggle switch_control=true needs_review=false
EXPORT OK   checkbox+ switch_control: type=checkboxes switch_control=true
REIMPORT    checkbox+ switch_control: type=checkbox switch_control=true needs_review=false
EXPORT FAIL radio   + switch_control: InvalidArgumentException: WAPF Tools export cannot preserve unknown field data: switch_control.
EXPORT FAIL text    + switch_control: InvalidArgumentException: WAPF Tools export cannot preserve unknown field data: switch_control.
EXPORT OK   toggle  (no switch)   : type=true-false switch_control=<absent>
REIMPORT    toggle  (no switch)   : type=toggle switch_control=<absent> needs_review=false
```

### WXR migration document (`WapfWxrExporter` → `WapfParser::parse()` → `WapfMapper::map()`)

Before (`php probe2.php head-copy`) — the document could not be built at all,
because `build_document()` calls `build_payload()`:

```text
WXR EXPORT FAIL: InvalidArgumentException: WAPF Tools export cannot preserve unknown field data: switch_control.
```

Intermediate state found during this work (exporter fixed, WXR option list not
yet fixed — `php probe2.php`, captured in `wxr-finding.txt`): the WXR export
started succeeding while **silently dropping** the key.

```text
WXR EXPORT OK: stored options = []
WXR REIMPORT: switch_control = <absent>
```

After (`php probe2.php`):

```text
WXR EXPORT OK: stored options = {"switch_control":true}
WXR REIMPORT: switch_control = true
```

## Tests

Focused test (`vendor/bin/phpunit --no-coverage --filter WapfExporterSwitchControlTest`):
**11 tests / 33 assertions / 0 failures.** It covers:

- toggle switch: export emits `true-false` + `switch_control => true`, reimport
  returns `toggle` with `switch_control => true` and `needs_review === false`;
- checkbox switch: export emits `checkboxes` + `switch_control => true`,
  reimport returns `checkbox` with the key and its two choice slugs;
- both cases again through the WXR document (stored `options.switch_control`);
- an unset switch exports no `switch_control` key and reimports without one;
- `radio`, `select`, `text`, `textarea` and `number` still throw
  `unknown field data: switch_control` (fail-closed, never a silent drop);
- the mapper ignores `switch_control` on a non-switch type.

Suite counts, same machine and run of the day:

| Run | Tests | Assertions | Failures |
|---|---|---|---|
| Before (pristine `HEAD` copy, `ff25f9e`) | 1056 | 4481 | 0 |
| After (working tree) | 1067 | 4514 | 0 |

`node --test tests/js/*.cjs`: **130 tests / 130 pass / 0 fail**, unchanged
before and after. The two PHPUnit runner deprecations reported in both runs are
pre-existing (`CapabilityFixtureRegistryTest`, `LookupTableCsvImporterTest`
still use doc-comment data providers).

## Residuals

- The installed reference is WAPF Extended 3.1.5, whose serialization has no
  switch key at all (its `true-false`/`checkboxes` options are
  `message`/`default`/`label_true`/`label_false`/pricing/weight). No WAPF Pro
  3.2 package is available on this host, so the key name and shape come from
  the published Pro 3.2 changelog plus OPF's own stored key — the same
  limitation the ledger row already records. This change removes the
  export/import blocker; it does not add WAPF-side runtime comparison.
- Storefront rendering, cart/order values and order-again behavior are
  unchanged by this work; their evidence stays in the ledger row
  (`WAPF-SWITCH-CONTROL-RUNTIME-REPROOF-2026-10-05.md`).
- The ledger row and `OPF-1.0-ROADMAP.md` were intentionally not edited here.
