# WooCommerce Quick View Pro (Barn2) — OPF vs WAPF Extended 3.1.5 inside the modal

**Ledger row:** `WAPF-COMPAT-QUICK-VIEW-PRO`
**Date:** 2026-10-06
**Method:** disposable WP + WooCommerce clone, live Chromium, both engines, same
fixture data, side by side.
**Artifacts:** `docs/compatibility/quick-view-pro-20261006/`

---

## 1. Versions and sources

| Component | Version | Source |
| --- | --- | --- |
| Open Product Fields | `0.1.1`, repo `master` @ `7691a6b` | `/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce` |
| Advanced Product Fields (WAPF) Extended | `3.1.5` | `…/plugins/advanced-product-fields-for-woocommerce-extended` (copied into the clone, original untouched) |
| WooCommerce Quick View Pro (Barn2) | `1.7.17` | `…/ops/scratchpad/20261006-opf-gpl-sources/gallery/barn2-quick-view-pro_1.7.17.zip` → folder `woocommerce-quick-view-pro` |
| WordPress | `7.1.3` | clone core |
| WooCommerce | `11.1.0` | clone |
| PHP | `8.5.11` (CLI + `php -S` built-in server) | host |
| Chromium (Playwright) | playwright `1.59.1`, `chromium-1228` | `/home/followersya-5hqi7/followersya.com/node_modules` |

Clone root: `/tmp/opf-qv-barn2-wp` (SQLite drop-in, `php -S 127.0.0.1:8425`,
`router.php`). No production WordPress was touched.

Headline versions side by side: **OPF 0.1.1** and **WAPF Extended 3.1.5** were
each activated *alone* (never together) against the same
Barn2 Quick View Pro 1.7.17 runtime and the same field data.

---

## 2. Exact commands

```sh
# 1. clone (disposable; copies core + WC from an existing test clone, fresh DB)
CLONE=/tmp/opf-qv-barn2-wp
cp -a /tmp/opf-image-change-var-wp "$CLONE" && rm -f "$CLONE/wp-content/database/.ht.sqlite"
rsync -a --exclude='.git' --exclude='node_modules' \
  /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce/ \
  "$CLONE/wp-content/plugins/open-product-fields-for-woocommerce/"
rsync -a --exclude='.git' \
  /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended/ \
  "$CLONE/wp-content/plugins/advanced-product-fields-for-woocommerce-extended/"
cp -a /tmp/opf-qv-barn2-src/woocommerce-quick-view-pro "$CLONE/wp-content/plugins/woocommerce-quick-view-pro"
# wp-config.php → http://127.0.0.1:8425, SQLite drop-in
cd "$CLONE" && wp core install --url=http://127.0.0.1:8425 --title="OPF Quick View Pro Test" \
  --admin_user=admin --admin_password=admin-pass-8425 --admin_email=admin@example.test --skip-email
wp rewrite structure '/%postname%/' --hard
php -S 127.0.0.1:8425 -t "$CLONE" "$CLONE/router.php" &   # log: /tmp/opf-qv-barn2-wp-server.log

# 2. fixture (both engines active so it can author both group formats)
cd /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce
wp --path="$CLONE" eval-file bin/e2e-quick-view-pro-fixture.php prepare

# 3. OPF lane (WAPF deactivated)
wp --path="$CLONE" plugin deactivate advanced-product-fields-for-woocommerce-extended
wp --path="$CLONE" plugin activate  open-product-fields-for-woocommerce
OPF_QV_ENGINE=opf  OPF_QV_SCENARIO=a NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
  node bin/e2e-quick-view-pro-browser.mjs      # → 7/15
OPF_QV_ENGINE=opf  OPF_QV_SCENARIO=b NODE_PATH=… node bin/e2e-quick-view-pro-browser.mjs   # → 8/15

# 4. WAPF lane (OPF deactivated)
wp --path="$CLONE" plugin deactivate open-product-fields-for-woocommerce
wp --path="$CLONE" plugin activate  advanced-product-fields-for-woocommerce-extended
OPF_QV_ENGINE=wapf OPF_QV_SCENARIO=a NODE_PATH=… node bin/e2e-quick-view-pro-browser.mjs   # → 14/15
```

Cleanup (not yet run — the clone is kept as the reproducer; the loopback server
is still running as `php -S 127.0.0.1:8425` pid 3719556, log
`/tmp/opf-qv-barn2-wp-server.log`):

```sh
wp --path=/tmp/opf-qv-barn2-wp eval-file bin/e2e-quick-view-pro-fixture.php cleanup
```

Harness files added by this run: `bin/e2e-quick-view-pro-fixture.php`,
`bin/e2e-quick-view-pro-browser.mjs`. **No shared plugin source file was
modified** (`git status` shows no tracked-file changes).

---

## 3. Fixture

Two variable products, one per engine, authored from identical data
(`bin/e2e-quick-view-pro-fixture.php`):

| | |
| --- | --- |
| attribute | `size = { small, large }`, both variations `100.00` |
| variation images | `small` → `opf-qv-v-small.png`, `large` → `opf-qv-v-large.png` |
| featured / gallery | `opf-qv-main.png` / `[v-small, v-large, rule-a, rule-b]` |
| OPF field group (id 18) | `finish`: select `none` / `gold` **+15** / `silver` **+5**; `edge`: select `none` / `xl` **+3**; `extras`: checkbox `Gift` **+2** / `Wrap` **+1** |
| WAPF group (`_wapf_fieldgroup`, `p_19`) | same fields/prices in WAPF's own storage format |
| image rules | `finish=gold → rule-a`, `edge=xl → rule-b` (rules mode) |
| quick-view trigger page | `/qv-shop/` — `[products]` shortcode, no engine fields in the initial HTML |
| scenario-B page | `/qv-shop-inline/` — `[product_page id=15]` + `[products]`, so OPF's bundle *is* loaded |

The quick-view modal is opened through the plugin's own shop-loop button
(`a.wc-quick-view-button[data-product_id="…"]`), i.e. the real
`quick_view_pro:load` path. Cart verification reads the real Cart block after
the modal add, in the same browser session.

---

## 4. Results — side by side

| # | Check | OPF 0.1.1 (scenario A) | OPF 0.1.1 (scenario B) | WAPF Extended 3.1.5 |
| --- | --- | --- | --- | --- |
| 1 | engine bundle present on the trigger page | **no** (`opf-frontend.js`/`.css` absent) | yes | yes (`wapf-frontend` loaded site-wide) |
| 2 | field group renders inside the modal | yes (1 group, both selects, totals node) | yes | yes |
| 3 | totals node holds a value | **no** — `text=""`, node **visible** | **no** — `text=""` | yes — `$100.00` |
| 4 | `finish=gold` raises the modal total by +15 | **no** (empty) | **no** | yes `100 → 115` |
| 5 | `finish=silver` prices +5 | **no** | **no** | yes `100 → 105` |
| 6 | clearing the choice restores the base total | **no** | **no** | yes `115 → 100` |
| 7 | rule `finish=gold` swaps the modal image to `rule-a` | **no** (stays `v-small`) | **no** | yes |
| 8 | rule `edge=xl` swaps to `rule-b` | **no** | **no** | yes |
| 9 | variation image resolves when no rule matches | yes (WooCommerce itself) | yes | yes |
| 10 | modal add-to-cart carries the field payload over the QV Ajax route | **no** — QV handler throws, falls back to a native POST | **no** | yes (`wapf[field_finish]=gold&…`) |
| 11 | cart line carries the submitted values | yes — `Finish: Gold (+$15.00) / Edge: None / Extras: Gift (+$2.00)` | yes | yes — but `Extras: Gift (+$2.00), Gift (+$2.00)` |
| 12 | cart line price | `$117.00` (correct) | `$117.00` (correct) | `$119.00` (**double-charged**) |
| 13 | JS console errors | 1 `pageerror` from **Barn2** `wc-quick-view-pro.js` | same | none |
| | **totals** | **7 / 15** | **8 / 15** | **14 / 15** |

Raw runs: `quick-view-opf-scenario-a-results.json`,
`quick-view-opf-scenario-b-results.json`, `quick-view-wapf-scenario-a-results.json`
(screenshots `quick-view-*.png`, modal payloads `quick-view-modal-{opf,wapf}.json`,
page HTML `qv-shop-page-{opf,wapf}-active.html`).

### 4.1 What the screenshots show

* `quick-view-opf-scenario-a.png` — the OPF modal renders the fields **and a
  visible, empty totals block** (`Product total`, `Options total`, `Grand total`
  with no numbers), with the raw browser controls (no `opf-frontend.css`).
* `quick-view-wapf-scenario-a.png` — same modal with `$100.00 / $0.00 / $100.00`
  and the `(+$2.00)` price hints.

### 4.2 Console errors (check 5 of the task)

Exactly one JS error was observed in the whole comparison, and it comes from
**Barn2 Quick View Pro**, triggered by OPF's markup (WAPF produced none):

```text
pageerror: TypeError: t[o].push is not a function
  at http://127.0.0.1:8425/wp-content/plugins/woocommerce-quick-view-pro/assets/js/wc-quick-view-pro.js?ver=1.7.17:1:8706
```

Cause chain (verified against the rendered markup, artifact
`quick-view-modal-opf.json`): OPF renders a hidden companion next to every
multi-value choice control —

```html
<input type="hidden" class="opf-tf-h" data-fid="extras" value="0" name="opf[18][extras]" />   <!-- no brackets -->
<input type="checkbox" id="opf-18-extras-gift" name="opf[18][extras][]" value="gift" … />
```

— and Barn2's serializer
(`assets/js/wc-quick-view-pro.js`, the `serializeArray()` reducer inside the
`submit` handler) stores the first name as a **string** and then calls
`.push()` on it when it meets the `[]` sibling. WAPF emits the same companion
with the **bracketed** name (`wapf[field_extras][]`, `class="wapf-tf-h"`), so it
never triggers the bug.

Consequence: the modal's Ajax add-to-cart aborts and the form submits
**natively** (`POST /product/qv-opf-product/`, `multipart/form-data`), the
browser navigates away from `/qv-shop/` and the modal is destroyed
(`url_after_add = …/product/qv-opf-product/`, `modal_open_after_add = false`).
The cart still ends up correct (`$117.00` with both values) because OPF reads
`$_POST['opf']` server-side — the failure is purely the modal UX.

---

## 5. The precise OPF gap

WAPF's adapter is four behaviours in `includes/classes/integrations/class-quickview.php`:

```js
jQuery(document).on('quick_view_pro:load', function(e, el){ el.find('.wapf-product-totals').hide(); new WAPF.Frontend(el); });
jQuery("body").on("adding_to_cart", function(a,b,c){ … serialize .wapf-wrapper :input into c … });
```

OPF matches WAPF on **one** of the two halves and lacks the other:

| Adapter half | OPF status |
| --- | --- |
| Re-initialise the frontend for the injected modal (`new Frontend(el)`) | **missing** |
| Ship the runtime to a page that has no fields yet | **missing** |
| Serialize field inputs during the Ajax add-to-cart (`adding_to_cart`) | **not needed** — Barn2's own `serializeArray()` reducer already collects OPF's `opf[gid][fid][...]` names into the Ajax payload (proved by the crash happening *inside* that reducer), and OPF's server side reads `$_POST['opf']`. (WAPF's extra handler is in fact harmful here — see §6.) |
| Hide duplicate totals in the modal | **not needed** — OPF renders one totals block; nothing is duplicated |

Evidence for "missing re-init":

1. `assets/js/opf-frontend.js` initialises **once**:
   `initAll()` (`init()` + `initTotals()`) is called from
   `DOMContentLoaded`/one `setTimeout` (end of file) and nowhere else; the
   module has **no exports and no public re-init hook**.
2. `init( root = document )` (`assets/js/opf-frontend.js:610`) already accepts a
   root and already guards re-runs with `groupEl.dataset.opfInitialized`, but no
   caller ever passes a root — the comment at line 630 ("WAPF attaches per group
   and re-initializes AJAX-injected group roots") describes an entry point that
   was never wired up.
3. `initTotals()` (`assets/js/opf-frontend.js:3512`) takes no root and binds to
   `document.querySelector('[data-opf-fields]')` — the *first* container in the
   document. Nothing re-runs it for modal content.
4. Assets are enqueued **only when the renderer ran on that request**
   (`Renderer::render()` → `Assets::enqueue_frontend()` at
   `includes/Service/Renderer.php:279`; `Assets` only *registers* otherwise).
   `/qv-shop/` therefore ships neither `opf-frontend.js` nor `opf-frontend.css`
   (check 1), while WAPF enqueues `wapf-frontend` + CSS unconditionally
   (`class-public-controller.php::register_assets()`), which is what makes its
   adapter able to construct `new WAPF.Frontend(el)` in the modal.
5. Scenario B isolates this: on `/qv-shop-inline/` OPF's bundle **is** loaded
   (`window.OPF_FIELDS`, `window.OPF_IMAGE_RULES`, `window.opf_config` all
   defined, `opf-frontend.js` present) and its inline group is initialised — the
   modal still stays inert. So the re-init hook is the blocker, not only the
   missing asset.

Note that the modal HTML *does* carry `window.OPF_FIELDS`,
`window.OPF_IMAGE_RULES` and `window.opf_config` inline (they are printed by
`Assets::enqueue_frontend()` while the REST template is buffered), so the modal
has its field metadata; only the runtime/initialisation is absent.

### 5.1 Smallest fix I would propose (not applied — shared source is off-limits here)

**A. The multi-value companion name** (fixes the Barn2 `TypeError` and the lost
Ajax add-to-cart) — one line, WAPF-spelling parity:

`includes/Service/Renderer.php:1209` (checkbox/swatch field) and `:1580`
(products checkbox/radio field) name the hidden companion with the *unbracketed*
name. Emit the bracketed name for multi-value fields, exactly as WAPF does:

```php
// :1209 — only checkbox fields reach this line with `[]` siblings (multiple swatch skips it)
$companion = 'checkbox' === $field['type'] ? $name . '[]' : $name;
// :1580 — `$multi` is already computed from the products subtype
$companion = $multi ? $name . '[]' : $name;
```

Server-side tolerance is already proven by the WAPF lane and by the sanitizer:
for `[ 'swatch','select','radio','checkbox' ]`
`CartIntegration::sanitize_value()` (`includes/Service/CartIntegration.php:1824`)
filters every slug that is not a valid choice — the `0` sentinel is dropped and
`array_unique()` removes the duplicates — and `collect_submitted()` only needs
the key to exist. `php -r 'parse_str(...)'` confirms `opf[18][extras][]=0&opf[18][extras][]=gift`
yields `['0','gift']` (today's unbracketed companion yields `['gift']`), and both
sanitize to `['gift']`.

**B. The re-init entry point** (fixes pricing totals + image rules in the modal):

1. `assets/js/opf-frontend.js` — make the scoped pass public without touching the
   module's load mode (the file has a classic-`wp_enqueue_script` fallback, so
   `export` is not an option): accept a root in `initTotals`
   (`const initTotals = ( root = document ) => { const container = root.querySelector( '[data-opf-fields]' ) || root; … }`)
   and, after `initAll`, publish
   `window.OPF.initScope = ( root ) => { init( root || document ); initTotals( root || document ); };`
2. `includes/Service/Assets.php` — print a few lines of bridge on front-end
   pages where the QV runtime can inject fields (e.g. when
   `wc_quick_view_pro_params` is enqueued / `class_exists( 'Barn2\Plugin\WC_Quick_View_Pro\Quick_View_Plugin' )`),
   loading the bundle lazily so OPF keeps its "zero bytes when no fields" rule:

   ```js
   jQuery(document).on('quick_view_pro:load', function(e, el){
     const root = (el && el[0]) || document;
     import(OPF_FRONTEND_URL).then(() => window.OPF.initScope(root));
   });
   ```

   A native `document.addEventListener('quick_view_pro:load', …)` will **not**
   work: jQuery `.trigger()` does not dispatch to native
   `addEventListener` listeners (verified on the clone:
   `{"documentNative":false,"elementNative":false,"jqueryOnTarget":true}`).
   If OPF wants to stay jQuery-free, the bridge must use a `MutationObserver`
   (and/or `added_to_cart`/`wc_fragments_refreshed`) instead of the QV event.

   `initTotals` also needs its writer to resolve the *scope's* totals node
   (`writeTotals()` currently uses a document-wide
   `document.querySelector('.opf-product-totals, .wapf-product-totals')`), which
   matters as soon as two groups can be on one page (scenario B).

---

## 6. Observed WAPF-side behaviour (for the record)

* WAPF's `quick_view_pro:load` handler calls
  `el.find('.wapf-product-totals').hide()`, but WAPF's own `Frontend`
  re-shows the node: after init the totals block is `display: block`,
  `inline_style: ""`, visible, and displays `$100.00 / $0.00 / $100.00`. The
  modal *does* display live totals.
* WAPF's `adding_to_cart` handler double-submits multi-value fields. Barn2's
  reducer has already built `wapf[field_extras] = ['0','gift','0']` from the
  form, then WAPF's handler pushes the same serialized values onto it again, and
  the POST body is
  `…&wapf[field_extras][]=0&…=gift&…=0&…=0&…=gift&…=0&…`
  (deterministic across two runs). The cart line therefore reads
  **`Extras: Gift (+$2.00), Gift (+$2.00)`** and the line total is **`$119.00`**
  instead of `$117.00` — the checkbox option is charged twice. This is a WAPF
  3.1.5 + Quick View Pro 1.7.17 defect; OPF avoids it by not having that handler
  (Barn2's reducer alone is sufficient for OPF's `opf[…]` names).
* That double submission is the only WAPF check that fails (14/15).

---

## 7. Residuals / not covered

* One engine was active at a time (as instructed). Co-active OPF + WAPF inside
  the same modal was not exercised.
* Scenario B was run for OPF only (WAPF's bundle loads site-wide, so "bundle
  absent" is not reachable for it).
* Only the shop-loop button trigger and the standard variable-product gallery
  (WooCommerce flexslider) were used; product-table rows, block-theme product
  grids and other QV triggers (`[quick_view]` shortcode, hover button) were not
  exercised.
* No fix was applied — the two defects above are documented, with file/line
  anchors, for the orchestrator.
* Cleanup of `/tmp/opf-qv-barn2-wp` (and the clone's fixture) is still pending;
  it is the reproducer for both defects.
