# WAPF-FIELD-STYLED-CHECKBOX-RADIO — closed (WAPF design layer consumed)

Date: 2026-10-05. Baseline: installed WAPF Extended 3.1.5 (parity reference only).

## 3.1.5 source contract

- `includes/classes/class-design-helper.php:1213-1290` — the flat, sanitized
  `wapf_design_settings` key map (`apf-cb-*` checkbox skin, `apf-radio-*` radio
  skin, generic `apf-*` colors/units).
- `includes/classes/class-design-helper.php:1588-1640`
  (`design_settings_to_variables_css`) — emits `:root{--apf-…}` plus the
  styled checkbox/radio CSS when `apf-cb-display` / `apf-radio-display` is
  `styled`.
- `includes/classes/class-design-helper.php:1700-1840`
  (`get_checkbox_style_css`, `get_radio_style_css`) — hides the native input
  (`position:absolute;opacity:0;width:1px;height:1px`) and draws the skin in
  `.wapf-custom`, using `input:checked + .wapf-custom`.
- `views/frontend/fields/checkboxes.php:17` and
  `views/frontend/fields/radio.php:17` — each choice renders
  `<input … /><span class="wapf-custom"></span><span class="wapf-label-text">…`
  inside a `.wapf-input-label`, wrapped by a `.wapf-checkbox` / `.wapf-radio`
  row inside a `.wapf-checkboxes` / `.wapf-radios` group.
- `includes/classes/class-config.php` — the design admin exposes these keys
  (`views/admin/design.php`).

## Before / after

OPF coloured native inputs through its own opt-in `opf_styled_choice_controls`
setting and consumed none of WAPF's design keys, so any theme/add-on CSS written
for `.wapf-custom` or `--apf-*` did nothing after migration.

Now:

- New `OPF\Engine\WapfDesign` reads `wapf_design_settings`, sanitizes each value
  (rejects any value that could break out of the declaration), and generates the
  `:root` `--apf-*` variables plus the `.wapf-custom` checkbox/radio skin,
  mirroring `design_settings_to_variables_css` (including the default/wired
  border composites and the checkbox/radio tick colours).
- `Renderer::render_group` prints the generated stylesheet once per settings
  revision (`<style id="opf-wapf-design-css">`).
- Plain checkbox/radio choices and their groups now carry the WAPF markup
  contract: `wapf-checkboxes`/`wapf-radios` on the group, `wapf-checkbox`/
  `wapf-radio` on each row, `wapf-input-label` on the label, `wapf-label-text`
  on the caption, and `<span class="wapf-custom" aria-hidden="true"></span>`
  immediately after the native input.
- Native semantics are preserved: the real `<input type="checkbox|radio">`
  keeps `checked`, `name`, focus order and keyboard handling; only WAPF's skin
  hides it visually (WAPF's own contract). Switch controls (`role="switch"`)
  and card radios keep their own markup and are not skinned.

## Tests

- `tests/Unit/WapfDesignTest.php` — `:root` variables + `.wapf-custom` skins for
  `styled` controls; unstyled controls emit variables only; CSS-injection values
  are dropped; `setting:` references resolve to `var(--…)`.
- `tests/Unit/RendererStyledChoiceTest.php` — markup carries the WAPF classes
  and `.wapf-custom` span; switch/card choices are untouched; a migrated
  `wapf_design_settings` option emits the stylesheet.

## Residual

- WAPF's datepicker-icon / select-arrow / card-icon design fragments target
  WAPF-specific selectors (`.wapf-dp-my`, `.wapf select`, `.wapf-card`) OPF does
  not emit, so they are not reproduced; the checkbox/radio row this ledger entry
  covers and the generic `--apf-*` variables are complete. OPF's native styled
  controls opt-in remains as an additional option.
