# WAPF-FIELD-CHILD-PRODUCTS — real currency plugin, non-default tax class, Blocks checkout, cross-site child ID

Date: 2026-10-05. Lane: `childprod`. Scope: close the four named residuals on
ledger row `WAPF-FIELD-CHILD-PRODUCTS` (read from
`docs/compatibility/WAPF-CAPABILITY-LEDGER.md`, line 239):
(a) a live currency-switching runtime converting linked child-product prices and
child cart/order lines, (b) one non-default tax class on a child line, (c) a
Blocks checkout carrying child lines, and (d) the cross-site child-product-ID
remap policy.

## Honesty constraint — CURCY is not the WOOCS/Aelia/FOX adapter

The real currency switcher used here is **CURCY – WooCommerce Multi Currency
2.4.3 (VillaTheme)**, the plugin installed on this host. CURCY exercises
WooCommerce's **general product-price conversion path**
(`woocommerce_product_get_price` + a real currency cookie/session) and settles
the order through WooCommerce's normal cart/checkout pipeline. It is **not** the
WOOCS/Aelia/FOX adapter that WAPF's `includes/classes/integrations/class-woocs.php`
targets. Therefore this evidence:

- **closes the "real currency plugin, real session, child lines" half** of
  residual (a) — a shipping third-party currency plugin, not a rate-2 stub,
  converting linked child-product prices and child cart/order lines end to end;
- **does not** prove any WAPF-specific WOOCS/Aelia/FOX adapter row. Those rows
  keep their own evidence (`docs/compatibility/FOX-CURRENCY-CONTRACT.md`,
  `CURRENCY-REAL-RUNTIME-EVIDENCE-2026-10-03.md`,
  `AELIA-REAL-PLUGIN-EVIDENCE-2026-10-03.md`) and are not touched by this run.

No CURCY number in this document should be read as a WOOCS/Aelia/FOX claim.

## Environment

`docs/compatibility/child-products-currency-20261005/runtime-facts.json` records
the exact runtime. Summary:

| | value |
| --- | --- |
| Clone (source) | `/tmp/opf-child-currency-wp` (copy of `/tmp/opf-image-child-wp`) |
| Clone (remap destination) | `/tmp/opf-child-remap-wp` |
| URL | http://127.0.0.1:8431 |
| PHP | 8.5.11 |
| WordPress | 7.1.2 |
| WooCommerce | 11.1.0 |
| OPF | 0.1.0 (this worktree's `includes/`, rsynced into the clone) |
| CURCY | **2.4.3** (`woocommerce-multi-currency`, VillaTheme) |
| WAPF Extended | 3.1.5 (reference, active only in the remap destination clone) |
| Base currency | USD; EUR at CURCY rate **1.25** |
| Tax | calc yes, prices exclude tax, tax based on **base**, base class 10% |

`runtime-facts.json` also records the CURCY parameter snapshot used for the
primary proof:

```json
"curcy_config": { "enable": 1, "enable_multi_payment": 1,
  "currency": ["USD","EUR"], "currency_rate": [1, 1.25],
  "checkout_currency": "USD", "checkout_currency_args": ["USD","EUR"],
  "equivalent_currency": "", "enable_cart_page": 0 }
```

### Why `enable_multi_payment=1`

CURCY's default configuration is **display-only conversion**: it converts
storefront prices but settles orders in the base currency. With
`enable_multi_payment=0`, `WOOMULTI_CURRENCY_Frontend_Price::init()`
(`includes/frontend/price.php:874-881`) force-resets the current currency to the
store base on the checkout page. To charge the shopper in the currency they
selected — the scenario residual (a) asks about — CURCY must run with
multi-payment enabled and the selected currency allowed at checkout
(`checkout_currency_args`). The last row of the task-1 table records the exact
default-configuration alternative so the behaviour difference is on the record.

## Clone build

```sh
cp -a /tmp/opf-image-child-wp /tmp/opf-child-currency-wp
rsync -a --delete --exclude='.pi-lens-probe-home' --exclude='.git' \
  /tmp/opf-lane-childprod/ \
  /tmp/opf-child-currency-wp/wp-content/plugins/open-product-fields-for-woocommerce/
cp -a /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/woocommerce-multi-currency \
  /tmp/opf-child-currency-wp/wp-content/plugins/
sed -i 's|127.0.0.1:8301|127.0.0.1:8431|g' /tmp/opf-child-currency-wp/wp-config.php
wp --path=/tmp/opf-child-currency-wp plugin activate woocommerce-multi-currency
php -S 127.0.0.1:8431 -t /tmp/opf-child-currency-wp
```

CURCY was configured (real `woo_multi_currency_params` option):

```sh
wp --path=/tmp/opf-child-currency-wp eval '
$p = get_option("woo_multi_currency_params", []);
$p["enable"]=1; $p["enable_multi_payment"]=1; $p["checkout_currency"]="USD";
$p["checkout_currency_args"]=["USD","EUR"]; $p["equivalent_currency"]="";
$p["currency"]=["USD","EUR"]; $p["currency_rate"]=[1,1.25];
$p["currency_decimals"]=[2,2]; $p["currency_hidden"]=[0,0];
update_option("woo_multi_currency_params", $p);'
```

## Fixture

`bin/e2e-child-currency-tax-fixture.php` (new; clone-guarded) creates the parent
+ three linked child products, one OPF `products` card field
(`qty_method=parent`), the checkout/account pages, and a base-class 10% tax
rate. `bin/e2e-child-currency-tax.mjs` drives the real HTTP storefront;
`bin/e2e-child-currency-tax-order.php` inspects the persisted order and runs
Order Again.

```sh
ART=/tmp/opf-lane-childprod/docs/compatibility/child-products-currency-20261005
OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_PHASE=setup OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  wp --path=/tmp/opf-child-currency-wp \
  eval-file bin/e2e-child-currency-tax-fixture.php
```

Fixture: parent `OPF Currency Parent` USD 20; children `alpha` 8 (`fixed`),
`beta` 12 (`fixed`), `gamma` 5 (`none`); one `products` card field with all
three choices, `qty_method=parent`, parent quantity 2.

## Task 1 — real currency plugin, real session, child lines

```sh
OPF_CHILD_CUR_ARTIFACT_DIR=$ART OPF_CHILD_CUR_SCENARIO=usd-base-tax \
  node bin/e2e-child-currency-tax.mjs
OPF_CHILD_CUR_ARTIFACT_DIR=$ART OPF_CHILD_CUR_SCENARIO=eur-base-tax \
  node bin/e2e-child-currency-tax.mjs

OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  OPF_CHILD_CUR_ORDER_ID=15102 OPF_CHILD_CUR_ORDER_CURRENCY=USD \
  OPF_CHILD_CUR_OUT=order-usd-base-tax.json \
  wp --path=/tmp/opf-child-currency-wp \
  eval-file bin/e2e-child-currency-tax-order.php
OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  OPF_CHILD_CUR_ORDER_ID=15103 OPF_CHILD_CUR_ORDER_CURRENCY=EUR \
  OPF_CHILD_CUR_OUT=order-eur-base-tax.json \
  wp --path=/tmp/opf-child-currency-wp \
  eval-file bin/e2e-child-currency-tax-order.php
```

The currency switch is CURCY's own runtime path: `GET /?wmc-currency=EUR` makes
CURCY write its `wmc_current_currency` cookie; every later request in the same
browser context carries it, and CURCY registers its price filter on `init`
(`frontend/price.php`) because the cookie differs from the base currency.

Product page (classic, real HTML) linked-child card prices:

| scenario | child card prices rendered |
| --- | --- |
| USD (base) | `$8.00`, `$12.00`, `$5.00` |
| EUR (rate 1.25) | `€10.00`, `€15.00`, `€6.25` |

`gamma` is `pricing_type=none`; its card still shows the catalog price (WAPF's
own `expand_product_choice` does the same), but its **cart line is zero** —
the display/charge split is inherent to `none` pricing, not a conversion bug.

Cart and persisted order (parent qty 2; one native child line per selection):

| Currency | Parent | alpha child | beta child | gamma child | Cart subtotal | Cart tax | Cart total | Order total | Order tax |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| USD | 40.00 | 16.00 | 24.00 | 0.00 | 80.00 | 8.00 | 88.00 | 88.00 | 8.00 |
| EUR ×1.25 | 50.00 | 20.00 | 30.00 | 0.00 | 100.00 | 10.00 | 110.00 | 110.00 | 10.00 |

The products field's own add-on (the sum of its child line totals) is
**USD 40.00 / EUR 50.00**. Per-line cart tax split: USD parent 4.00 / alpha 1.60
/ beta 2.40; EUR parent 5.00 / alpha 2.00 / beta 3.00. Every child amount is
exactly the base amount × 1.25.

Persisted order metadata (`order-eur-base-tax.json`): order currency `EUR`, four
order lines (parent + three children), each child carrying `_opf_child_full`
with `parent`, `field`, `price_type` (`qt` for the fixed card children, `none`
for gamma) and `qty_type`. So the field's child selections and parent linkage
survive order persistence in the switched currency.

Order Again (mirrors `WC_Cart_Session::order_again()`; CURCY's own price hooks
are re-registered because they early-return under WP-CLI):

| Order | Rebuilt lines | Children | Remapped to new parent key | Subtotal | Tax | Total | Matches order |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| USD 15102 | 4 | 3 | 3 | 80.00 | 8.00 | 88.00 | yes |
| EUR 15103 | 4 | 3 | 3 | 100.00 | 10.00 | 110.00 | yes |

**Residual (a), "real currency plugin, real session" half — CLOSED.**
**Residual (a), the WAPF-specific WOOCS/Aelia/FOX adapter rows — NOT closed by
this evidence** (CURCY is not that adapter).

### Default-CURCY alternative (on the record)

With CURCY's shipping default (`enable_multi_payment=0`, empty
`checkout_currency_args`): the cart still shows EUR 100.00 / 10.00 / 110.00, but
the order is placed in the **base currency**: order 15108 persisted as
`USD`, subtotal 80.00, tax 8.00, total 88.00, while the cart showed EUR. This is
CURCY's documented display-only mode (see the `Frontend_Price::init()` source
line above), not an OPF defect — it applies to every cart line, parent included.
Artifacts: `scenario-eur-default-config.json`, `order-eur-default-config.json`.

## Task 2 — non-default tax class on the child line

```sh
OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_PHASE=tax-on OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  wp --path=/tmp/opf-child-currency-wp eval-file bin/e2e-child-currency-tax-fixture.php
OPF_CHILD_CUR_ARTIFACT_DIR=$ART OPF_CHILD_CUR_SCENARIO=eur-child-taxclass \
  node bin/e2e-child-currency-tax.mjs
OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  OPF_CHILD_CUR_ORDER_ID=15105 OPF_CHILD_CUR_ORDER_CURRENCY=EUR \
  OPF_CHILD_CUR_OUT=order-eur-child-taxclass.json \
  wp --path=/tmp/opf-child-currency-wp eval-file bin/e2e-child-currency-tax-order.php
OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_PHASE=tax-off OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  wp --path=/tmp/opf-child-currency-wp eval-file bin/e2e-child-currency-tax-fixture.php
```

The `tax-on` phase creates a **non-default** WooCommerce tax class
`opf-currency-child-5` ("OPF currency child 5") with a 5% rate and assigns it to
`alpha`, `beta`, `gamma`; the parent stays on the default 10% class. All child
lines are taxable.

| Currency | Parent (10%) | alpha (5%) | beta (5%) | gamma (0%) | Subtotal | Tax | Total |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| EUR ×1.25 | 50.00 (tax 5.00) | 20.00 (tax 1.00) | 30.00 (tax 1.50) | 0.00 (tax 0.00) | 100.00 | **7.50** | **107.50** |

The persisted order (15105) carries the same per-line tax (parent 5.00, alpha
1.00, beta 1.50, gamma 0.00), EUR currency, `_opf_child_full` metadata, and Order
Again rebuilds all three children with `subtotal 100.00 / tax 7.50 / total
107.50` matching the order. `tax-off` restored the base-class assignment and
deleted the class + rate.

**Residual (b) — CLOSED.**

## Task 3 — Blocks (Store API) checkout with child lines

```sh
OPF_CHILD_CUR_ARTIFACT_DIR=$ART OPF_CHILD_CUR_SCENARIO=eur-blocks \
  node bin/e2e-child-currency-tax.mjs
OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  OPF_CHILD_CUR_ORDER_ID=15107 OPF_CHILD_CUR_ORDER_CURRENCY=EUR \
  OPF_CHILD_CUR_OUT=order-eur-blocks.json \
  wp --path=/tmp/opf-child-currency-wp eval-file bin/e2e-child-currency-tax-order.php
```

The scenario uses the real Store API (the Blocks checkout transport):
`POST /?rest_route=/wc/store/v1/cart/add-item` with an `opf_fields` payload,
then `POST /?rest_route=/wc/store/v1/checkout` with a billing address and
`payment_method=bacs`, all in a fresh Store-API cart in the EUR session.

Result — it works end to end:

| step | result |
| --- | --- |
| `cart/add-item` | HTTP **201**, cart = parent + 3 native child lines |
| child markers | all three child lines expose `extensions.opf.childItem` |
| Store API cart totals | subtotal 100.00, tax 10.00, **total 110.00** (EUR) |
| `checkout` | HTTP **200**, `order_id` 15107, `payment_status: success` |
| persisted order | currency EUR, parent 50.00 + alpha 20.00 + beta 30.00 + gamma 0.00, tax 10.00, total 110.00, `_opf_child_full` present |
| Order Again | 4 lines / 3 children / 3 remapped, total 110.00, matches order |

Note: CURCY does **not** declare `cart_checkout_blocks` compatibility
(`woocommerce-multi-currency.php` leaves
`FeaturesUtil::declare_compatibility('cart_checkout_blocks', …)` commented out).
It nevertheless converted and settled the Store API cart correctly here. Even
so, Store API/Blocks coverage was proven with CURCY's multi-payment mode and
must not be read as a curated CURCY Blocks-support statement.

**Residual (c) — CLOSED.**

## Task 4 — cross-site child-product-ID remap

```sh
# source: export the fixture group (OPF archive + WAPF Tools payload)
OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  wp --path=/tmp/opf-child-currency-wp eval-file bin/e2e-child-remap-export.php

# destination: a clone WITHOUT the source products but with the same slugs at new IDs
cp -a /tmp/opf-child-import-export-wp /tmp/opf-child-remap-wp
rsync -a --delete --exclude='.pi-lens-probe-home' --exclude='.git' \
  /tmp/opf-lane-childprod/ \
  /tmp/opf-child-remap-wp/wp-content/plugins/open-product-fields-for-woocommerce/
OPF_REMAP_ALLOW=1 OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  OPF_REMAP_DEST_CLONE=/tmp/opf-child-remap-wp \
  wp --path=/tmp/opf-child-remap-wp eval-file bin/e2e-child-remap-import.php
```

The destination has products with the **same slugs** (`child-alpha`, `child-beta`,
`child-gamma`) at **new IDs**, plus a destination parent. The child products were
also tax-class-free and distinct from the source IDs.

Result (`remap-import.json`):

| | values |
| --- | --- |
| source choice IDs | `[15096, 15097, 15098]` |
| destination IDs (same slugs) | `[15082, 15083, 15084]` |
| imported choice IDs | `[15096, 15097, 15098]` |
| imported IDs == source | **true** |
| imported IDs == destination | false |
| remapped | **no** |
| imported group status | `draft` |
| `_opf_needs_review` | `["product_target_ids_may_not_match", "Site-local product, category, or tag targets may need remapping (variation targets included)."]` |
| children resolvable on destination | `[]` (none) |
| WAPF 3.1.5 parser choice IDs | `[15096, 15097, 15098]` |

So OPF **keeps the literal source product IDs**, flags the group for review, and
holds it as a draft; nothing resolves on the destination until an operator
manually re-points the choices. WAPF Extended 3.1.5's own parser
(`SW_WAPF_PRO\Includes\Classes\Field_Groups::raw_json_to_field_group()`) also
keeps the literal IDs. Source inspection of the installed 3.1.5 confirms the
only remapping it performs is of **field IDs** when cloning a group
(`includes/controllers/class-admin-controller.php:1322-1373`); it never remaps
product choice IDs or resolves them by slug.

**Residual (d) policy: OPF does not remap, 3.1.5 does not remap.** This matches
the existing `WAPF-MIGRATION-PRODUCT-ID-PORTABILITY` row and the broader
`MIGPROOF-CROSSSITE-EVIDENCE-2026-10-03.md` result (neither engine auto-remaps
site-local IDs; OPF warns + drafts, WAPF silently keeps literal IDs). It is a
**documented shared limitation, not an OPF gap** — OPF is the safer of the two.

## Residual summary

| residual | status | evidence |
| --- | --- | --- |
| (a) real currency plugin + real session on child lines | **closed** for the general WooCommerce conversion path with CURCY 2.4.3 (real cookie/session, product page → cart → checkout → order → order-again) | task-1 tables, `scenario-eur-base-tax.json`, `order-eur-base-tax.json` |
| (a) WOOCS/Aelia/FOX adapter-specific rows | **not closed here** — CURCY is not that adapter | honesty constraint above |
| (b) non-default tax class on a child line | **closed** | task-2 table, `order-eur-child-taxclass.json` |
| (c) Blocks checkout carrying child lines | **closed** | task-3 table, `scenario-eur-blocks.json`, `order-eur-blocks.json` |
| (d) cross-site child-ID remap | **documented shared limitation** (neither OPF nor 3.1.5 remaps) | `remap-import.json`, MIGPROOF + `WAPF-MIGRATION-PRODUCT-ID-PORTABILITY` |

## Validation

Run from the lane repo (`/tmp/opf-lane-childprod`), verbatim:

```sh
vendor/bin/phpunit 2>&1 | tail -6
node --test tests/js/*.test.cjs 2>&1 | tail -8
```

- PHPUnit: `Tests: 1033, Assertions: 4365, PHPUnit Deprecations: 2` — 1033/1033
  pass, 0 failures (the two deprecations are pre-existing).
- Node: `tests 129 / pass 129 / fail 0`.

This lane touched only docs + `bin/` harnesses; both suites stayed green.

## Cleanup performed

- Fixture products (parent + alpha/beta/gamma), field-group post, checkout and
  account pages deleted; base 10% tax rate deleted; non-default tax class +
  5% rate deleted; all touched options restored to their snapshot values
  (`woocommerce_checkout_page_id`, `myaccount_page_id`, `bacs_settings`,
  `currency`, `calc_taxes`, `prices_include_tax`, `tax_display_shop/cart`,
  `tax_based_on`, `enable_guest_checkout`). `owned-orders.json` orders deleted.
- Destination remap clone: the import harness deleted the imported draft group
  and the four created product posts it had made.
- Temporary probe must-use plugin `wp-content/mu-plugins/zz-curprobe.php`
  removed.
- CURCY deactivated and its directory removed from the source clone.
- The `php -S 127.0.0.1:8431` server killed.

Commands:

```sh
OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_PHASE=tax-off OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  wp --path=/tmp/opf-child-currency-wp \
  eval-file bin/e2e-child-currency-tax-fixture.php
OPF_CHILD_CUR_ALLOW=1 OPF_CHILD_CUR_PHASE=cleanup OPF_CHILD_CUR_ARTIFACT_DIR=$ART \
  wp --path=/tmp/opf-child-currency-wp \
  eval-file bin/e2e-child-currency-tax-fixture.php
rm /tmp/opf-child-currency-wp/wp-content/mu-plugins/zz-curprobe.php
wp --path=/tmp/opf-child-currency-wp plugin deactivate woocommerce-multi-currency
rm -rf /tmp/opf-child-currency-wp/wp-content/plugins/woocommerce-multi-currency
pkill -f 'php -S 127.0.0.1:8431'
rm -rf /tmp/opf-child-remap-wp
```

Verification after cleanup:

```sh
wp --path=/tmp/opf-child-currency-wp plugin list
wp --path=/tmp/opf-child-currency-wp eval 'echo get_option("opf_child_currency_state") ? "fixture present" : "fixture gone";'
wp --path=/tmp/opf-child-currency-wp eval 'echo in_array("opf-currency-child-5", WC_Tax::get_tax_class_slugs(), true) ? "class present" : "class gone";'
```

## Limits

- CURCY only; the WAPF-specific WOOCS/Aelia/FOX adapter rows remain separate.
- One OPF card field, one manual choice set, a single parent quantity (2), one
  tax-exclusive default-country configuration — not an exhaustive matrix.
- Order Again is exercised at the WooCommerce cart-data level (the CLI rebuild
  re-registers CURCY's own price hooks). The end-to-end currency conversion is
  proven over real HTTP for add-to-cart, cart, checkout and order.
- CURCY does not declare Blocks compatibility; Store API success here shows the
  transport carries child lines, not that CURCY officially supports Blocks.
- Cross-site remapping stays a shared limitation; no auto-remap policy is added.
