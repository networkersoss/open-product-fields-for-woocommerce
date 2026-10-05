# OPF 1.0 parity roadmap

**Target:** match or exceed **WAPF Extended 3.1.5**, the installed reference
package at
`bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended`,
which includes WAPF Pro core plus Extended features. Owner decision D1
(2026-10-05): 3.1.5 is the parity baseline for every contract claim. A row is
not parked as `partial` because a newer licensed package is unavailable; it is
re-graded against 3.1.5 and either promoted on that evidence or kept `partial`
with a residual that is real against 3.1.5. The official Extended changelog
currently lists 3.2.1 and the separate Pro changelog 3.2.2; those are used as
published forward-contract context only, and behaviour they add that 3.1.5 does
not implement is recorded on the affected row. The available-source audit and
published changelog crosswalk are complete.
Separately sold add-ons are outside this 1.0 target. Production has WAPF
Extended 3.1.5 installed but inactive; that installed source is both the
production baseline and the parity reference.

**Source of truth:**
[capability ledger](WAPF-CAPABILITY-LEDGER.md) has one stable row per
capability and records current OPF state, evidence, and remaining gap.
[3.1.5 installed-source audit](WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md) records
the local source baseline. [Official tier comparison](https://www.studiowombat.com/knowledge-base/whats-the-difference-between-each-version/),
[product marketing page](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/),
and the separate [Extended](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce-extended/changelog/)
and [Pro](https://www.studiowombat.com/plugin/advanced-product-fields-for-woocommerce/changelog/)
changelogs establish the edition boundary, advertised capability inventory,
and version skew. Marketing claims are mapped to ledger rows; they do not
replace source or lifecycle proof. Local WP-CLI reports the installed
Extended plugin as inactive 3.1.5 with no update currently exposed in its
update registry. The available-source audit is complete; this live-site check
does not change the audited version boundary.

## Progress now — 2026-10-05 (`master` @ `0f47368`)

The 131 Free/Pro/Extended ledger rows are the 1.0 edition scope, including three
“All versions” rows. Current recorded status, recounted from the ledger rows on
this commit:

| Status | Rows | Meaning for the gate |
| --- | ---: | --- |
| Baseline supported | 0 | — |
| Supported | 113 | Strict supported rows; documented differences are not counted as supported |
| Supported with documented difference — owner-accepted | 7 | Each carries an explicit owner decision (D1/D2/D3/D4, 2026-10-05) |
| Partial | 11 | Material parity or proof remains; see each ledger row for its exact gap and evidence |
| Gap | 0 | — |
| Needs audit | 0 | — |
| **Total** | **131** | **G1 complete for available evidence; G2 not met — strict supported: 113/131 (86.3%); accepted parity: 120/131 (91.6%)** |

The six `WAPF-ADDON-*` rows are **outside** this 131-row denominator (all six
`partial`) and are excluded from every count and gate verdict on this page.

### Current per-row state — 2026-10-05

Owner decisions D1–D5 (2026-10-05, recorded in
`ops/scratchpad/20261005-opf-pickup/OWNER-DECISIONS.md`) closed the first-pass
accounting:

- **Closed with reasoning (D5):** `WAPF-PRICE-MATRIX`,
  `WAPF-DATE-DISABLED-DATES`, `WAPF-PRICE-QUANTITY-FLAT`,
  `WAPF-PRICE-QUANTITY` and `WAPF-FIELD-SWATCH-IMAGE-ZOOM` are `supported` —
  no customer-visible divergence exists (representation-only, or OPF is the
  stricter/superior side). Each row records that reasoning.
- **Promoted on landed implementation:** `WAPF-FIELD-NUMBER`,
  `WAPF-FIELD-NUMBER-STEP-VALIDATION`, `WAPF-DATE-DYNAMIC-BOUNDS`,
  `WAPF-FIELD-DATE` (`disable_today`), `WAPF-FIELD-NUMBER-STEPPER`,
  `WAPF-FIELD-STYLED-CHECKBOX-RADIO`, and `WAPF-DATE-ACCESSIBILITY` (re-graded
  against 3.1.5, whose calendar has no ARIA grid, roles or keyboard day
  navigation).
- **Explicitly owner-accepted differences (7):** `WAPF-FIELD-TOGGLE`,
  `WAPF-DATE-FORMAT`, `WAPF-FIELD-SECTION` and
  `WAPF-PRICE-OPTIONS-TOTAL-NEGATIVE-FORMAT` (D1); `WAPF-RULE-PRODUCT` (D4, the
  deliberate parent-id-or-explicit-variation-id union);
  `WAPF-COMPAT-MINIMUM-PLATFORM` (D2, the accepted higher platform floor);
  `WAPF-DEVELOPER-HOOKS` (D3, 40/105 bridged for real, with the five absent
  public registries named in the row).
- **Demoted:** `WAPF-FIELD-SWATCH-TEXT` from `baseline supported` to `partial` —
  no corner-radius control and no lifecycle proof exist in this tree.
- **Still `partial` (11):** `WAPF-FIELD-CHILD-PRODUCTS`,
  `WAPF-FIELD-IMAGE-QUANTITY-ZOOM`, `WAPF-FIELD-CHILD-PRODUCTS-IMAGE-ZOOM`,
  `WAPF-FIELD-CHECKBOX-COLUMNS`, `WAPF-FIELD-TRUE-FALSE-SWITCH`,
  `WAPF-INTERACTION-IMAGE-CHANGE`, `WAPF-PRICE-FORMULA-ADVANCED`,
  `WAPF-DISPLAY-PRICE-HINTS`, `WAPF-PRODUCT-SUBSCRIPTION`,
  `WAPF-LOCALE-WPML`, `WAPF-FIELD-SWATCH-TEXT`. Each ledger row names its own
  residual.

The narrative below is the 2026-10-03/04 record of how the rows reached this
point. Where a sentence in it states an older status, the table above and the
ledger rows are authoritative.

Fresh acceptance review downgraded nine previously `supported` rows to
`partial`: advanced formulas, tax behavior, product-price display, price hints,
hide-values/PDF behavior, WPML, and WOOCS/Aelia/FOX integrations. Their row
notes cited unresolved behavior or only fake/API-contract coverage; these do
not satisfy the release objective's real lifecycle requirement. The earlier
67/131 figure was therefore overstated. Tax behaviour, product-price display,
hide-values/PDF, Aelia, FOX and WOOCS were re-promoted later on licensed or
real-runtime proof; the current table above is authoritative.

Progress notes — 2026-10-03 (parallel lanes, reflected in public commit
`94992d5`): linked-products field shipped
(`LinkedProducts` service, qty sync, Store API `opf.childItem`, order-again
remap, image zoom); secure upload order-again token reissue (strictly stronger
than WAPF's .htaccess re-link); custom formula variables/`lookuptable`/`files`
in engine+browser with cart-time wiring; 9 rule-targeting rows and 6 date rows
promoted on real-browser/WAPF-reference evidence; 12 formula rows proven through
preview→cart→checkout→order→refund→order-again. Former divergence resolved 2026-10-03
(qty-semantics lane): verbatim `[qty]` fx formulas now price via WAPF's own
`result/qty`/`result` rows instead of re-multiplying `per_unit`; choice/image-quantity
`$val` plumbing restored; `split_quantity_repeat_cart_item` foreign-item guard fixed a real
WAPF-coexistence cart corruption. 23 cases × qty {1,3} = 46/46 legs identical to WAPF
(unit, line, order totals, persisted meta); proof in the qty-semantics parity matrix.

The previously listed `WAPF-MIGRATION-REPORT-REPEATABILITY` item is an OPF-only
release-safety check, not a WAPF capability. It is excluded from the parity
denominator and remains required under G4: guarded archive E2E covers dry-run,
review-draft handling, and idempotent repeat import in
`bin/e2e-opf-archive-portability.php`.

After the WPML and Aelia implementation commits reached the public branch on
2026-10-01, each row moved from `gap` to `partial`. WPML native field-group
string translation and language-specific product targeting are implemented.
Newly imported WAPF global/local groups with a valid source CPT/product language
now preserve ownership through `_opf_wpml_source_language` and render only in that language,
retaining localized labels and placement IDs to avoid duplicate options.
The ownership lane passes 15 focused tests with 83 assertions for native/imported
groups, language switching, duplicate suppression, and cache isolation; see
[source-language ownership evidence](WPML-IMPORT-OWNERSHIP.md). Historical
imports are not backfilled, archive WPML ownership remains unresolved, and
real WPML/WCML records, global/local product rendering, native package translation
editor, and multilingual cart/order proof remain open. WPML stays `partial`.
Aelia base/formula/cart/browser
conversion is implemented and passed a disposable Woo test against a fake API
contract, but the commercial plugin, linked-product conversion, and a wired
pricing-hint path remain unverified. WPML stays `partial` because real WPML/WCML
records are not available on this host; Aelia is now `supported` on the
real-plugin proof recorded in its row.

A fresh evidence review removed 15 stale `supported` claims at that point in
time; later implementation commits advanced rows based on additional evidence.
See [the supported-row audit](SUPPORTED-ROW-AUDIT-2026-10-01.md).
At the current `master` tip (`0f47368`), strict progress is **113/131 supported rows (86.3%)**.
The 2026-10-04 child-products query audit and WAPF validation-hook repair add
evidence and fix a real coactive-plugin fatal, but do not promote the broad
child-products row; strict progress was unchanged by that audit. The integrated branch now
passes PHPUnit **763 tests / 3,391 assertions** and JavaScript **76/76 tests**
(one existing PHPUnit deprecation). See the [query audit](CHILD-PRODUCTS-QUERY-AUDIT-2026-10-04.md)
and [coexistence evidence](WAPF-VALIDATION-COEXISTENCE-2026-10-04.md). The
additional WAPF-active Store API checkout/Order Again/refund proof is recorded
in [coactive child-order evidence](CHILD-STORE-API-WAPF-COACTIVE-EVIDENCE-2026-10-04.md).
The date-extension warning found during this work is fixed: OPF skips only the
object-only WAPF validator during OPF array dispatch, then restores it in place.
Valid/invalid dates and zero warnings pass in a WAPF-active disposable clone;
see [date coexistence evidence](WAPF-EXTENDED-DATE-COEXISTENCE-2026-10-04.md).
OPF's declared platform floor also has a full lifecycle proof on PHP 7.4.33 /
WordPress 6.5 / WooCommerce 9.0.0, including both cart transports and Order
Again (29/29 browser checks). The row is now an owner-accepted documented
difference (D2, 2026-10-05): the higher floor is deliberate and no PHP 7.1
backports, WooCommerce 7.0 Store API adapter or WordPress 6.0 cache shims are
queued; see
[platform-floor lifecycle evidence](DECLARED-PLATFORM-FLOOR-LIFECYCLE-2026-10-04.md).
The linked-products admin SelectWoo fix and save/reload proof is in
[builder roundtrip evidence](CHILD-PRODUCTS-BUILDER-ROUNDTRIP-2026-10-04.md).
The previous 58/131 (44.3%) snapshot predates the 2026-10-03 implementation
lanes. The shortcode row advanced after real WAPF 3.1.5/OPF render, import/export, and
browser evidence confirmed imported behavior is preserved; OPF's native opt-out
is additive. The select
and radio rows advanced from baseline-supported after the source-matched
required-choice lifecycle passed in Chromium, WAPF Free, classic and Store API
commerce, persisted orders, and order-again. See
[select/radio lifecycle evidence](SELECT-RADIO-REQUIRED-CHOICE-EVIDENCE-2026-10-02.md).

The generic Classic/Blocks cart-to-order row advanced from baseline-supported
after fresh browser and durable-order evidence passed all three Classic/Store
API add and checkout combinations. See
[cart/order lifecycle evidence](CART-ORDER-LIFECYCLE-EVIDENCE-2026-10-02.md).

On 2026-10-02, the date-format fallback inconsistency was fixed across every
settings/runtime consumer, including invalid settings submissions. Regression
and disposable browser/WooCommerce evidence covers the shared valid
OPF → valid WAPF → default precedence, cart/checkout labels, formula prices,
and saved order metadata with canonical ISO values. See
[date-format fallback evidence](WAPF-DATE-FORMAT-FALLBACK-EVIDENCE-2026-10-02.md).
`WAPF-DATE-FORMAT` carries an owner-accepted documented difference (D1) for
native-input locale display, browser checkout/payment, block checkout, customer
order-page rendering, and remaining migration proof. Status counts and accepted progress remain unchanged.

`WAPF-FIELD-EMAIL` advanced from `partial` to `supported` on 2026-10-02.
Chromium and disposable WooCommerce/WAPF Free evidence covers admin
save/reload, native and forged input validation, WAPF import/export fidelity,
classic and Store API checkouts, exact order/email persistence, and order-again.
See [email lifecycle evidence](EMAIL-FIELD-LIFECYCLE-EVIDENCE.md).

The upload field and Ajax UI advanced from `gap` to `partial` on the public
branch after a reviewed private-storage foundation landed; both rows are now
`supported` on the evidence cited in them. Disposable
Chromium/WooCommerce evidence covers upload and removal UX, ownership and file
validation, classic and Store API carts, checkout/order persistence, protected
downloads, and cleanup. A 2026-10-03 authenticated real Order again run passed
37/37 checks and proved that a completed-order upload token is rejected in the
new session; private bytes remain bound to the original order. WAPF Extended
3.1.5 source comparison indicates its public file reference restores, but the
WAPF route was not runtime-tested. A safe authenticated OPF copy/reissue flow
is still needed before that behavior can match. Builder controls and
import/export fidelity, thumbnail previews, guest session-loss recovery, and
broader platform/storage compatibility also remain open; see [upload
foundation evidence](UPLOAD-FOUNDATION-EVIDENCE-2026-10-01.md) and [Order again
evidence](UPLOAD-ORDER-AGAIN-EVIDENCE-2026-10-03.md).
The 2026-10-02 upload security audit also fixed checkout retries after a
draft/pending order had claimed the upload. Disposable WooCommerce checks prove
same-owner retry and rebind, preserved order-item download metadata, live-order
claim protection, and download ACLs. Hard-deleted-order files can still remain
until their TTL expires and scheduled cleanup runs; the
[audit](UPLOAD-SECURITY-AUDIT-2026-10-02.md) records this residual risk.

`WAPF-FIELD-TEXT` advanced from baseline-supported to `supported`: native text
defaults and builder controls now pass 24 real-Chromium checks and 23 disposable
WooCommerce lifecycle checks, including actual admin REST save/reload, classic
and Store API validation/cart/checkout, order/email persistence, and order-again.
The full PHPUnit suite passes 225 tests/883 assertions. WAPF default import/export
and text length/regex remain separate open rows. See
[native text lifecycle evidence](TEXT-FIELD-LIFECYCLE-EVIDENCE.md).

`WAPF-FIELD-TEXTAREA` advanced from baseline-supported to `supported` for its
declared safe-newline behavior. Authenticated Chromium proves admin REST
save/reload, required and optional rendering, and browser/forged-request
validation. Disposable WooCommerce proves CRLF/LF preservation through classic
and Store API carts, checkout/order/email display, escaping, and order-again.
WAPF Free 1.7.1 source confirms the same textarea sanitation and cart item-data
contract. Defaults/placeholders and import/export remain open in their separate
scopes. See [textarea lifecycle evidence](TEXTAREA-NEWLINE-EVIDENCE.md).

`WAPF-FIELD-URL` is `supported` after its native parser and lifecycle update.
Real WAPF Free/Extended templates and Chromium/WooCommerce tests passed 185/185
commerce checks and 35/35 actual Order again checks across 19 URL values,
including admin REST save/reload, browser validity, classic and Store API,
checkout, persisted orders, and native defaults. The private vendored WHATWG
parser now accepts tested IDN/path/query, path-space, opaque mailto/tel/urn,
shortened HTTP, and WordPress-allowed protocols while rejecting unsafe schemes
and malformed hosts. The earlier caveat — cart block visually empty after a
direct Store API add — is resolved: the 2026-10-03 visual proof passed 20/20
checks for direct `add-item` with `opf_fields` across Unicode IDN, query,
mailto, and scheme-relative values, plus 16/16 each for OPF and WAPF Free
form submission on classic and block carts. Broader protocol and
supported-version coverage remains open. See [native URL proof](URL-FIELD-NATIVE-PARITY.md),
[URL cart visual proof](URL-CART-VISUAL-EVIDENCE-2026-10-03.md),
and the earlier [compatibility audit](URL-FIELD-COMPATIBILITY-EVIDENCE.md).

`WAPF-FIELD-CONTENT-IMAGE` is `supported`. The 2026-10-03 real Chromium
storefront comparison passed 39/39 desktop/mobile checks for direct URLs,
Media Library attachments, responsive source selection, loaded bytes,
accessibility names and no submitted controls. OPF uses the field label as an
attachment alt while WAPF uses the Media Library alt. A same-day admin proof
passed 43/43 checks covering authenticated wp-admin login, real Media Library
modal selection, OPF REST save/reload persistence, show-when conditional
visibility at 1280/390px, and byte-identical images; WAPF's license-gated
admin exception was attributed and recorded. Broader version coverage is not
yet proved. See [image storefront proof](CONTENT-IMAGE-STOREFRONT-PROOF.md)
and [image admin proof](CONTENT-IMAGE-ADMIN-EVIDENCE-2026-10-03.md).

`WAPF-PRICE-FORMULA-LEN` is `supported` with much stronger evidence. The
2026-10-03 lifecycle run fixed five real `len()`/`[field.X]` divergences and
proved bit-identical behavior against installed Extended 3.1.5: 30/30 PHP
probes match `Helper::parse_math_string`, and 24/24 real Chromium cases match
preview totals, Store API carts, classic checkout, and durable order line
totals (ASCII whitespace-only stripping, case-sensitive `;true`, `empty()`
zero handling, `mb_strlen` vs UTF-16 preview counting, and choice-label
resolution). Order-again, refund, admin save/reload, and tax/currency
boundaries remain open. See [len lifecycle evidence](FORMULA-LEN-LIFECYCLE-EVIDENCE-2026-10-03.md).

`WAPF-PRICE-QUANTITY-FLAT` moved from `partial` to `supported with documented
difference`: OPF expresses native WAPF Pro `qt` as a fixed per-unit choice and
lets WooCommerce line quantity provide the scaling. Disposable WooCommerce
evidence closes the row's stated cart, checkout persistence, tax, and rounding
gaps for q=1/q=3, and authenticated Chromium proves the actual My Account Order
again action restores the q=3 choice, line total, and tax for both WAPF and OPF.
The evidence compares native WAPF Extended 3.1.5 with WooCommerce 11.1.0 and
does not claim other versions. See [quantity-flat lifecycle evidence](WAPF-PRICE-QUANTITY-FLAT-EVIDENCE-2026-10-02.md),
[browser results](qfl-order-again-browser-results.json), and proof commits
`ccd63b7` + `6d39b24`. D5 (2026-10-05) then closed the row outright: the
representation-only difference has no customer-visible divergence.

FOX is `supported`: the existing WOOCS adapter passes an official
FOX API contract test and real FOX 1.5.4 (the renamed WOOCS package, same
adapter contract) matched OPF on real `WC_Cart`/orders. WAPF's registry and
FOX's own compatibility instructions identify that shared adapter. See
[FOX contract evidence](FOX-CURRENCY-CONTRACT.md).

WOOCS is `supported`: its adapter, cart/formula/variation/frontend hooks and the
real FOX runtime match WAPF on real cart/order lifecycles. See
[WOOCS audit and executed evidence](WOOCS-CURRENCY-EVIDENCE.md).

`WAPF-COMPAT-MINIMUM-PLATFORM` is an owner-accepted scope decision (D2,
2026-10-05): the accepted floor is **WordPress 6.5 / PHP 7.4 / WooCommerce
9.0**, against WAPF's WordPress 6.0 / PHP 7.1 / WooCommerce 7.0. No PHP 7.1
backports, no WooCommerce 7.0 Store API capture adapter and no WordPress 6.0
cache shims are queued; dropping those legacy platforms is deliberate, so the
earlier “gap” reading of this row is withdrawn. Proved: the declared floor
passes a full lifecycle on PHP 7.4.33 / WordPress 6.5 / WooCommerce 9.0.0
(29/29 browser checks), all 36 shipped PHP files parse on PHP 7.1.33, the
archive JSON decoder passes 12/12 cases on PHP 7.1/7.4/8.5, and the WordPress
6.0/6.5 cache-contract probes pass. Not proved and not queued: populated
archive import on PHP 7.1, whole-plugin boot on the WAPF paid minima,
WordPress 6.0 cache invalidation, and WooCommerce 7.0 Store API capture — all
version-bounded in
[platform-floor audit](WAPF-MINIMUM-PLATFORM-EVIDENCE.md).

Extended-only rows: 28 total; 24 supported, 0 supported with a documented
difference, 4 partial, and 0 known gaps, matching the capability ledger. All 131
edition rows have a ledger status. The
available-source audit covers installed Extended 3.1.5, public Free 1.7.1,
current tier/marketing claims, and every published Extended 3.1.6–3.2.1 and
Pro 3.2.2 changelog change. Exact current-package details not specified in
public sources stay on the affected partial rows and do not block unrelated
implementation. These are row counts, not a feature-weighted percent.

The changelog crosswalk found two missing capability rows: conditional settings
for card quantity inputs (`WAPF-FIELD-CARDS-QUANTITY-CONDITIONALS`) and
date-picker accessibility (`WAPF-DATE-ACCESSIBILITY`). Both are explicit ledger
rows with the known behavior recorded, and both are now `supported` on the
evidence cited in their rows. See the release-by-release crosswalk in
`WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md`.

## G1 audit tracker

The available-source and public-claim audit is complete. Changelog coverage
defines scope; it does not count as implementation or commerce proof.

| Step | Audit task | State | Acceptance evidence |
| --- | --- | --- | --- |
| A | Freeze edition boundary and versions: installed Extended 3.1.5 parity baseline, published 3.2.1/Pro 3.2.2 changelog delta as forward-contract context, six separate add-ons excluded | Done | Official tier comparison and current changelog versions recorded; baseline fixed by owner decision D1 (2026-10-05) |
| B | Audit production-installed Extended 3.1.5 package as historical baseline | Done | Version and file inventory plus source behavior map in `WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md` |
| C | Audit public Free 1.7.1 source and its edition boundary | Done | Archive hash, source file inventory, Free/Pro field and pricing boundary recorded |
| D | Map official changes from Extended 3.1.6–3.2.1 and Pro 3.2.2 to ledger rows | Done | Every published change is mapped in the dated crosswalk; changelog claims remain separate from runtime proof |
| E | Look for the licensed current Extended 3.2.1 archive and verify its version/hash | Done for this audit | Server and source searches found installed Extended 3.1.5 only; package absence is recorded and is not a stop condition |
| F | Audit available core source, Free source, tier/marketing claims, and release deltas | Done | Installed 3.1.5 + public Free 1.7.1 source maps and official 3.1.6–3.2.2 changelog crosswalk; undisclosed current-package details are noted on affected rows |
| G | Reconcile all 131 edition rows to available source/docs and bound unknowns | Done | Every row has evidence, known behavior or uncertainty, OPF status, and proof gap; no unbounded source-audit item remains |
| H | Fresh review of audit and ledger, then freeze available-source baseline | Done | Audit and ledger reviewed on 2026-09-30; later source may refine individual rows |

G1 is complete for the available-source baseline. Continue using installed
3.1.5 source, public Free 1.7.1 source, official changelogs, and current
documentation. Keep version-specific evidence attached to each row; do not
present a 3.1.5-only behavior as verified for 3.2.1. Implement from the
published contract where sufficient and keep undisclosed settings or runtime
behavior explicitly unverified on the affected row. No global archive hold
remains.

## FOSS and package audit (G4, partial)

Current source evidence:

- `LICENSE`, the committed plugin header, and committed `readme.txt` declare
  GPL-2.0-or-later.
- `composer.json` has no runtime library dependencies; PHPUnit and its
  transitive packages are development-only and declare MIT/BSD licenses.
- No separate third-party JS/CSS payloads were found under `assets/`.
- `bin/build.sh` at public commit `c2153f8` was run from a clean archive. It
  produced 31 files / 245,569 bytes, including the GPL license, PHP runtime,
  CSS, and JS; `bin/`, `docs/`, `vendor/`, `tests/`, and Composer manifests
  were excluded. This inspected directory is baseline 0.1.0, not a 1.0
  candidate; final install/runtime behavior and marketplace archive remain
  unverified.
- Public `HEAD` plugin version and readme stable tag both say 0.1.0. The current
  dirty `open-product-fields-for-woocommerce.php` changes its header and
  `OPF_VERSION` to 0.1.1 while `readme.txt` remains at 0.1.0. Preserve that
  work; align metadata before making a release package.

This is not a completed provenance review: no file-by-file authorship/license
audit or reproducible release archive has been accepted. G4 stays open until
source provenance, bundled notices, build output, version metadata, and
marketplace requirements are reviewed against the exact release commit.

## Ordered work packages

| Order | Work package | Current state | Scope and required output | Exit condition |
| --- | --- | --- | --- | --- |
| 0 | Freeze current source and edition scope | **G1 complete for available evidence; row-level implementation continues** | Audit installed Extended 3.1.5, bundled Pro source, public Free 1.7.1, official tier/marketing claims, and all published changelog changes through Extended 3.2.1 / Pro 3.2.2. The current paid archive is absent, so package-only internals stay explicit per row; do not block unrelated implementation. Continue closing import/export, integration, PHP helper, targeting, platform-floor, and developer API parity from available evidence. Keep the six add-ons excluded. | **G1:** available-source scope mapped, public changes crosswalked, and version-specific unknowns bounded to affected rows. |
| 1 | Migration and data fidelity | **Active; representative WAPF import and OPF archive portability lifecycles proven** | Close every row whose gap includes WAPF import/export, legacy IDs, conditions, field/choice settings, global variables, formulas, or review-required mappings. Use real anonymized exports where available plus source-derived fixtures for absent field types. Unknown semantics must remain visibly review-required, never silently dropped. | Every in-scope importable row round-trips or maps equivalently; unsupported legacy data is reported for review; migration proof is attached to its ledger row. |
| 2 | Shared field/value and rule engines | **Active; button labels/clone numbering and field-level quantity repeats now work through migration, builder, storefront, server validation, pricing, and classic/Store API per-unit cart splitting, including identical-clone merge; sections now support builder repeat authoring, browser add/remove and quantity rendering/sync, child sanitation/validation, clone-local conditional validation, date-based formula pricing, Store API cart splitting/merge, and order metadata/restore** | Close core field input, defaults, required/constraints, conditional logic, repeaters, dates, calculations, formula functions/variables, and price/weight evaluation. Remaining repeater work includes checkout-generated order/browser proof, cart quantity edits, broader formula clone context, Store API browser/Blocks proof, and remaining lifecycle proof. Implement shared semantics once where possible, and keep browser previews aligned with server-authoritative validation and totals. | Each affected ledger row has passing focused coverage for normalization, valid/invalid submitted values, conditional visibility, and pricing/weight where relevant; no client-only behavior is counted as parity. |
| 3 | Extended field experiences | **Active for documented/source-confirmed behavior** | Close cards and main-image switching, child/linked products (specific and category sources, fixed/none category price type), image choices with quantity limits/zoom, date policies/cutoffs, calculation display and price modes, and formula-driven weight. | Admin save/reload and keyboard-accessible product-page behavior match the audited source contract; each field's stored/imported state and invalid-input behavior are covered. |
| 4 | WooCommerce lifecycle and integrations | **Active** | Close pricing/tax/coupons/currency, classic and Store API carts, cart editing, stock and parent-child quantity/removal, checkout/order metadata, order-again, refunds/restocks, and each claimed theme/plugin integration. | Every applicable row has end-to-end evidence through the relevant storefront, server validation, cart, checkout/order, and restore/refund paths. No integration is claimed from static markup alone. |
| 5 | Admin, display, and accessibility parity | **Active against available source/docs; current-package-only details remain gated** | Close global/product settings, builder usability, field-group listing/search/scheduling, visual design, price summaries/hints, translations, screen-reader/keyboard behavior, and responsive layouts against current docs/source. | Every UI row has admin save/reload and browser evidence; accessibility and responsive acceptance criteria are recorded in the ledger. |
| 6 | Ledger closure and release candidate | **Not started; depends on WP1–5** | Review all 131 edition rows with a fresh reviewer; resolve or explicitly document every difference; security/privacy, supported WordPress/WooCommerce/PHP versions, upgrade/uninstall, packaging, docs, changelog, rollback, and marketplace requirements. Add a release-candidate manifest and reproducible verification record. | **G2:** no baseline-supported/partial/gap/needs-audit rows and no unaccepted difference. **G3:** applicable commerce proofs pass. **G4:** release checklist passes before 1.0 tag/publication. |

Packages 1–5 proceed in the listed order using the best available evidence.
Features whose semantics are established by available source/docs move
through implementation and proof; an undisclosed package-specific detail stays
scoped to its ledger row and does not gate unrelated work. A package can split into small
public commits, but its ledger row and proof must close before moving to the
next package. Cross-cutting fixes can be included with the active package when
needed, and must update every affected row. Avoid publishing any customer-
visible or production-only diagnostic UI as part of this work.

## Progress update rule

After each completed package or coherent commit, update the affected ledger
rows and this table's status. Count only `supported` rows as complete;
`supported with documented difference` stays separate until that difference
is reviewed against current WAPF and accepted. `baseline supported` remains
unproven. Report counts by status and completed gate, never an unweighted
percentage. Estimate a delivery date only after the remaining rows are
decomposed into sized implementation and verification tasks.

## Non-negotiable release goalposts

1. **G1 source freeze:** available installed source, public Free source, current
   marketing/tier claims, published changelogs, and all 131 edition rows are
   mapped. Unpublished current-package details remain bounded to affected rows;
   G1 is complete for the available evidence and does not block implementation.
2. **G2 capability parity:** every edition row supported or has a reviewed,
   explicitly accepted difference; zero baseline-only, partial, gap, or
   needs-audit rows. Current: **not met** — 113/131 supported, 7 owner-accepted
   differences, 11 partial, 0 baseline-only/gap/needs-audit. The 6 add-on rows
   are outside the denominator.
3. **G3 commerce proof:** relevant browser, server, cart/Store API,
   checkout/order, stock, restore, tax, and pricing lifecycles verified. Current:
   **partial** — each of the 11 partial rows names its own open lifecycle path;
   no aggregate pass claim.
4. **G4 release readiness:** security, accessibility, compatibility,
   migration/rollback, FOSS source/dependency/asset provenance, aligned version
   metadata, inspected package contents, docs, and publication gates passed.
   Current: **not met** — provenance/licence review, aligned version metadata
   (header vs `readme.txt`) and an inspected 1.0 archive remain open, and
   evidence docs whose artifacts were staged under `/tmp` are unreproducible by
   construction.

No single goalpost can be waived by a passing aggregate test or a marketing
feature list. A change in WAPF target version or inclusion of separate add-ons
requires updating the scope baseline and ledger before implementation resumes.
