# WAPF-PRODUCT-SUBSCRIPTION — real WooCommerce Subscriptions runtime, 2026-10-06

Ledger row: `WAPF-PRODUCT-SUBSCRIPTION` (Subscription/variable subscription, Pro).
Adapter under test: `includes/Service/SubscriptionIntegration.php`
(sha256 `60c1dfb043fc9335923fb8840c640cc686356dc9b76e8a33b7f904c190e8e76f`,
git blob `3746ca6258088b66ccdc55e65d99e0cc7578118b`) — **unmodified by this run**.

## Why this run exists

The row was `partial` for exactly one reason: every previous proof ran against a
*scoped stub* of the Subscriptions product-type surface, because no licensed
WooCommerce Subscriptions runtime was on this host. `SUBSCRIPTION-BOOT-RESTORE-EVIDENCE-2026-10-04.md`
closed the "OPF fatals on WooCommerce load" regression and
`bin/e2e-subscription-stub.php` closed the source-level contract, but neither
could drive a real subscription checkout → renewal order → order-again → refund.

WooCommerce Subscriptions **9.2.0 (GPL-3.0)** is now staged at
`/home/followersya-5hqi7/ops/scratchpad/20261006-opf-gpl-sources/woocommerce-subscriptions`.
This run installs that untouched source into a disposable clone and drives the
real lifecycle.

## Environment (from `setup.json`)

| Component | Version / value |
|---|---|
| WordPress | 7.1.2 |
| WooCommerce | 11.1.0 |
| WooCommerce Subscriptions | **9.2.0** (staged GPL-3.0 source, unmodified) |
| PHP | 8.5.11 |
| Store URL | `http://127.0.0.1:8322` |
| Clone | `/tmp/opf-subscriptions-wp` (disposable, SQLite drop-in) |
| HPOS | off (Orders CPT storage) |
| Active plugins | `woocommerce`, `woocommerce-subscriptions`, `open-product-fields-for-woocommerce` |
| Store settings | manual renewals `yes`, `cod` gateway, guest checkout `no`, taxes off, USD |

The clone links this plugin worktree in read-only, so the proof executes the
exact code under review. Production WordPress was never read or written.

## Reproduction

```sh
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
OPF_SUB_RT_ALLOW=1 node bin/e2e-subscription-runtime.mjs --rebuild   # provision /tmp clone + run all phases
OPF_SUB_RT_ALLOW=1 node bin/e2e-subscription-runtime.mjs             # reuse the clone, re-run all phases
```

The harness provisions the clone (WP core + WooCommerce + SQLite drop-in from the
`opf-test` pattern), copies the staged Subscriptions source, activates OPF, runs
`bin/e2e-subscription-runtime.php` through `wp eval-file` for each phase, and
copies the raw JSON artifacts into
`docs/compatibility/subscription-runtime-20261006/`.

Raw artifacts: `setup.json`, `cart.json`, `checkout.json`, `renewal.json`,
`order-again.json`, `refund.json`, `state.json`, `summary.json`, `command-log.txt`.

## Result: 85 / 85 runtime assertions pass, 0 failed

| Phase | Passed | What it drove |
|---|---|---|
| `setup` | 11 | Real `subscription` + `variable-subscription` products with `_subscription_price`, OPF group, customer, store settings |
| `cart` | 22 | Classic add-to-cart + repeated totals + renewal validation gate + variation |
| `checkout` | 17 | Real `WC_Checkout::process_checkout()` through the `cod` gateway |
| `renewal` | 14 | `woocommerce_scheduled_subscription_payment` → WCS-created renewal order → paid |
| `order-again` | 13 | Real `$_GET['order_again']` cart rebuild → re-checkout |
| `refund` | 8 | Real `wc_create_refund()` partial refund |

### 1. Recurring base never compounds the field addon (`cart.json`)

Product: recurring base `_subscription_price = 30.00`; OPF field priced
`fixed +5.00` (`per_unit`), required, targeted at
`product_type in [subscription, variable-subscription]`.

| Measurement | Observed | Contract |
|---|---|---|
| `WC_Subscriptions_Product::get_price( $product )` | 30 | recurring base |
| OPF stored `opf_base_price` on the cart line | 30 | anchored, not mutated |
| Cart line price | **35** | base 30 + addon 5 |
| Cart total | **35** | no double-count |
| WCS recurring cart `2026_11_06_monthly` total | **35** | recurring period total |
| Totals passes 1–3 after the first calculation | 35 / 35 / 35 | **no compounding** (would be 40 / 45 / …) |
| `opf_cart_item_base_price` re-run with the product object pre-mutated to 35 | returns **30** | adapter re-anchors on the WCS API |
| Catalog `get_price('edit')` after carting | 30 | catalog untouched by cart pricing |
| `_subscription_price` postmeta after carting | 30 | recurring meta untouched |
| Recurring base for a real `subscription_variation` (yearly/monthly term) | 20 → cart line **25**, recurring total **25** | same contract for variations |

This is the anti-compounding contract WAPF 3.1.5 implements in
`includes/classes/integrations/class-woocommerce-subscriptions.php`:

```php
public function cart_item_base_price($price,$product, $quantity, $cart_item) {
    if(in_array($product->get_type(),['subscription','variable-subscription','subscription_variation']))
        return floatval(\WC_Subscriptions_Product::get_price($product));
    return $price;
}
```

OPF's `SubscriptionIntegration::cart_base_price()` reproduces the same three-type
list and the same `WC_Subscriptions_Product::get_price()` source, with a numeric
guard the reference lacks. Because that source reads persisted `_subscription_price`
meta rather than the in-memory product price, repeated `calculate_totals()` passes
cannot fold the addon into the base.

### 2. Checkout persists `_opf_fields` on the order **and** the subscription (`checkout.json`)

Real `WC_Checkout::process_checkout()`, `payment_method = cod`, manual renewals
enabled, nonce valid, no WooCommerce error notices, payment gateway reached.

- Order `#15`: status `completed`, total **35.00**
- Order line `_opf_fields` = `{"14":{"rt_note":"runtime monthly plan"}}`, snapshot stored
- WCS created subscription `#16` (`WC_Subscription` object): status `active`,
  recurring total **35.00**, manual renewal (`is_manual()` true), next payment scheduled
- Subscription line `_opf_fields` = `{"14":{"rt_note":"runtime monthly plan"}}`
- COD parks the order in `processing`; the store confirming the offline payment is
  what completes the order and activates the subscription

### 3. Renewal order: 35, not 40 (`renewal.json`)

- `do_action( 'woocommerce_scheduled_subscription_payment', $subscription_id )` —
  the Action Scheduler hook — ran
  `WC_Subscriptions_Manager::prepare_renewal()`, which created the renewal order.
  `renewal_created_by = "woocommerce_scheduled_subscription_payment"`; no cron wait.
- Renewal order `#17`: `wcs_order_contains_renewal()` true, total **35.00**,
  `_opf_fields` = `{"14":{"rt_note":"runtime monthly plan"}}`
- Subscription status path: `active` → `on-hold` (manual renewal due) → `active`
  (renewal paid). Renewal order status `pending` → `completed`.
- `next_payment` stays `2026-11-06 16:16:36`: WCS only recalculates on
  reactivation when the stored date is inside its 2-hour activation threshold, so
  a date a full period out is correctly left alone.
- Renewal cart rebuild: `woocommerce_order_again_cart_item_data` restores the OPF
  values, and `opf_skip_validation` is true inside
  `wcs_before_renewal_setup_cart_subscriptions` / false after it — the same gate
  WAPF implements via `skip_cart_validation`/`unset_skip_cart_validation`.

### 4. Order-again restores values and re-persists them (`order-again.json`)

Real storefront path: `$_GET['order_again']` + nonce via
`WC_Cart_Session::get_cart_from_session()` (which ends by redirecting to the cart,
observed as `http://127.0.0.1:8322/?page_id=6`).

- Rebuilt cart: 1 line, OPF values restored exactly, line price **35**,
  stored base **30**, recurring cart total **35**
- Re-checkout produced order `#18`, total **35.00**, whose line carries the
  restored `_opf_fields` with no fresh form submission

### 5. Partial refund leaves the priced line and the subscription intact (`refund.json`)

`wc_create_refund( amount = 10, refund_payment = false )` → refund `#20`.

- Order total stays **35.00**; refunded total **10.00**
- Line `_opf_fields` byte-identical before/after; line total still **35.00**
- Subscription still `active`

## Defects found

**None in OPF.** `includes/Service/SubscriptionIntegration.php` was not modified:
every assertion above passes against the unmodified adapter, including the
re-anchor check that would fail if the base came from the mutated product price.

Two fixture-side accommodations were required; neither is an OPF behaviour and
neither touches plugin code:

1. `WC_Checkout::process_checkout()` calls `wp_redirect()` + `exit` on success.
   The harness intercepts `woocommerce_payment_successful_result` with a sentinel
   exception so the CLI process survives; the real gateway path already ran.
2. `WC_Cart_Session::get_cart_from_session()` calls `wp_safe_redirect()` + `exit`
   when `$_GET['order_again']` is present. The harness converts `wp_redirect` into
   an exception **after** the cart and its totals are built.

## Bounds (honest limits)

- **HPOS is off** in this clone (legacy Orders CPT storage). Order/line meta write
  through the same `WC_Order`/`WC_Order_Item` APIs either way, but an HPOS-mode
  run was not performed.
- **No browser run.** The storefront presentation of a variable subscription
  (period labels, sign-up fee line, and WAPF's `wapf/pricing/base` JS filter that
  substitutes `v.display_price` for `variable-subscription`) is not exercised
  here; it remains covered only by the source-level comparison in
  `WAPF-EXTENDED-3.1.5-SOURCE-AUDIT.md`.
- **Sign-up fees** were not modelled (the fixture products have
  `_subscription_sign_up_fee = ''`).
- A **single payment gateway** (`cod`, manual renewal) was used, so automatic
  gateway-driven renewal charging (`woocommerce_scheduled_subscription_payment_<gateway>`)
  is out of scope; WCS deliberately does not charge a manual subscription there.
- The variable-subscription path is proven at the **cart/price** level, not
  through a variable-subscription checkout.

## Verification commands

```sh
vendor/bin/phpunit --no-coverage     # Tests: 1080, Assertions: 4568, 0 failures
node --test tests/js/*.cjs           # tests 130, pass 130, fail 0
```

## Relationship to the ledger row

The row's blocker was "no licensed WooCommerce Subscriptions runtime on this
host, so a subscription checkout → renewal order → order-again → refund
lifecycle, sign-up fees and the browser variation/period presentation cannot be
driven". The lifecycle is now driven end-to-end against real Subscriptions 9.2.0
with zero OPF defects. What remains unexercised is sign-up fees and the browser
variation/period presentation (see Bounds), which are product-level Subscriptions
concerns rather than OPF adapter contract.
