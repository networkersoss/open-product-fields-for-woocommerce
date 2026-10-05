# Native WAPF URL compatibility audit — acceptance withheld

> **Artifact status (2026-10-05):** of the three artifacts this document cites,
> the two JSON files are tracked and survive
> ([browser/HTTP](url-field-compatibility-browser-results.json),
> [installed-source](url-field-compatibility-source-results.json)); the
> screenshot `/tmp/opf-url-compatibility-artifacts/served-wapf-opf-inputs.png`
> (and its SHA-256) is no longer retrievable and was never tracked. Its visual
> claim rests on the two surviving JSON files and the guarded scripts named
> below.

**Follow-up, 2026-10-03:** the implementation and measured cases in
[native URL parity follow-up](URL-FIELD-NATIVE-PARITY.md) supersede the
recommendation below for the eight valid URL mismatches and invalid IPv4 case.
That follow-up records current implementation and real browser/commerce tests;
this document retains the 2026-10-01 baseline observations for history.

Executed on 2026-10-01, completed at 18:37 UTC, against URL commit
`0932610f1529a8c30a86f6814e8db481c5ce68e6` in `/tmp/opf-url-parity`.
The isolated SQLite WordPress/WooCommerce site was `/tmp/opf-url-woo`,
`http://127.0.0.1:8126`; versions and isolation match the
[original lifecycle proof](URL-FIELD-LIFECYCLE-EVIDENCE.md).
Production and protected browser tabs were unused. No runtime code was changed
in this follow-up; the ledger row is corrected to **partial/unaccepted**.

## Authoritative comparison and executed checks

The disposable MU helper `url-compatibility-probe.php` includes the installed
WAPF Free 1.7.1 `views/frontend/fields/url.php` in the real fixture product's
`woocommerce_before_add_to_cart_button` action. It supplies an empty value and
fixed ID/name attributes; WAPF's original template emits its native URL input.
OPF's actual product URL input is alongside it. This is a served template
contract comparison, not full active-WAPF product/Store API integration; WAPF
was inactive and its known Store API issue was not exercised.

The helper also adds `customsafe` through WordPress's
`kses_allowed_protocols` filter. The source artifact records that
`wp_allowed_protocols()` includes it before evaluating OPF.

[Browser/HTTP audit](../../bin/e2e-url-compatibility-browser-test.mjs):
**66 passing audit checks across 19 measured inputs**, including native validity
of both served inputs, actual classic form HTTP requests, actual Store API
add-item HTTP requests, and cart removal between cases. These are expected
observations of mismatches, not passing parity acceptance checks. Both inputs
always agreed on native validity. Classic and Store API always agreed on OPF
acceptance. No uncaught browser errors occurred.

[Installed-source audit](../../bin/e2e-url-compatibility-source.php) executes
WAPF 1.7.1's `Fields::sanitize_value()` and `is_field_value_valid()` using a
required URL `Field`; it also tests OPF schema default normalization.
For all eight compatibility gaps below, WAPF preserves the exact submitted
string and considers it valid. OPF rejects both the submitted value and that
same value as an admin/schema default.

| Submitted value | Native WAPF/OPF input | WAPF source server | OPF classic/Store API |
| --- | --- | --- | --- |
| `https://例え.テスト/こんにちは?q=✓` | valid | valid, unchanged | rejected |
| `https://example.invalid/café?q=é` | valid | valid, unchanged | rejected |
| `https://example.invalid/a b` | valid | valid, unchanged | rejected |
| `customsafe://example.invalid/path` | valid | valid, unchanged | rejected despite WordPress-safe filter |
| `mailto:é@example.invalid` | valid | valid, unchanged | rejected |
| `tel:+123` | valid | valid, unchanged | rejected |
| `urn:isbn:978123` | valid | valid, unchanged | rejected |
| `http:example.invalid` | valid | valid, unchanged | rejected |
| `http://256.256.256.256` | invalid | valid, unchanged | accepted |

The audit also verifies an ASCII/punycode/percent-encoded equivalent is accepted,
along with ordinary HTTPS and `mailto:`. Malformed IPv6, an out-of-range port,
an empty HTTPS host, and a missing scheme are rejected by both native input and
OPF. JavaScript/data schemes and raw `<script>` in a path are native-valid but
remain deliberately rejected by OPF. There are 12 native-versus-OPF divergences:
eight valid compatibility gaps, the invalid IPv4 acceptance gap, and three
intentional executable-scheme/markup rejections.

## Recommendation and remaining work

Keep `WAPF-FIELD-URL` partial. The current RFC2396/ASCII parser is not an adequate
replacement for the browser's URL parser, and a fixed safe-protocol list does
not honor verified WordPress-safe protocol extensions. Define and implement
browser-compatible parsing/canonicalization for Unicode domains/path/query,
spaces, opaque URLs, shortened special-scheme forms, and IPv4/IPv6/port rules.
Retain explicit rejection of executable schemes and raw markup, then verify
normalized selections/defaults through save/reload, cart, order, email, and
order-again. A superficial replacement with `parse_url()` alone would not prove
those parser semantics. The original ASCII commerce proof remains valid for
its tested inputs and does not establish the broader URL contract.

## Artifacts and reproduction

Raw [browser/HTTP observations](url-field-compatibility-browser-results.json)
and [installed-source observations](url-field-compatibility-source-results.json)
are committed. The screenshot was visually inspected and remains at
`/tmp/opf-url-compatibility-artifacts/served-wapf-opf-inputs.png`, SHA-256:
`2b0cc6703476b2b0bd3ce0504df30b9dc879465f9f691a735b0a0317a7b19465`.

Use the original setup/cleanup phases and a disposable MU helper with:

```php
add_filter('kses_allowed_protocols', static function ($protocols) {
    $protocols[] = 'customsafe';
    return $protocols;
});
add_action('woocommerce_before_add_to_cart_button', static function () {
    $state = get_option('opf_url_e2e_state');
    if (!$state || (int) get_the_ID() !== (int) $state['product']) return;
    $model = ['field_value' => '', 'field_attributes' => 'id="wapf-url-contract" name="wapf_url_contract"'];
    include WP_PLUGIN_DIR . '/advanced-product-fields-for-woocommerce/views/frontend/fields/url.php';
});
```

```sh
OPF_URL_E2E_ALLOW=1 OPF_URL_E2E_PHASE=setup wp --path=/tmp/opf-url-woo eval-file bin/e2e-url-lifecycle.php
OPF_BASE_URL=http://127.0.0.1:8126 node bin/e2e-url-compatibility-browser-test.mjs
OPF_URL_E2E_ALLOW=1 wp --path=/tmp/opf-url-woo eval-file bin/e2e-url-compatibility-source.php
OPF_URL_E2E_ALLOW=1 OPF_URL_E2E_PHASE=cleanup wp --path=/tmp/opf-url-woo eval-file bin/e2e-url-lifecycle.php
```

PHP/Node syntax and `git diff --check` pass. Fixture cleanup and server shutdown
follow the audit; no mail was delivered.
