# WAPF-LOCALE-WPML — WPML runtime proof (2026-10-06)

Runtime evidence for OPF's WPML integration (`includes/Service/WpmlIntegration.php`,
`wpml-config.xml`) on real WordPress + WooCommerce + WPML installs. Everything ran in
disposable clones under `/tmp`; **no production WordPress file, database or option was
read or written**. All artifacts: [docs/compatibility/wpml-runtime-20261006/](./wpml-runtime-20261006/).

Plugin under test: `open-product-fields-for-woocommerce` 0.1.0 at commit `521ff0c`
(exported with `git archive 521ff0c`, so the tested tree is exactly that commit).

---

## 1. What was requested and what actually happened

The requested stack was **WPML core 5.1.0 + String Translation 3.5.2 + WCML 5.5.8** on a
WP 6.5+/Woo 9+/PHP 8 clone. That stack **cannot work**: WPML core 5.1.0 declares both
companions outdated and switches them off before OPF ever runs.

`sitepress-multilingual-cms/wpml-dependencies.json` (copied verbatim to
`dependency-files/core-5.1.0-required-versions.json`):

```json
{ "wpml-string-translation": "5.0.0", "woocommerce-multilingual": "5.6.0",
  "acfml": "5.0.0", "wpml-media-translation": "5.0.0", "wpml-sticky-links": "5.0.0",
  "wpml-cms-nav": "5.0.0", "wpml-import": "5.0.0" }
```

Available ST is 3.5.2 (< 5.0.0) and WCML 5.5.8 (< 5.6.0), so
`WPML_Plugins_Check::disable_outdated()` runs: `remove_outdated_st_hooks()`,
`remove_outdated_st_boot()`, `install_outdated_st_stand_in()` and the WCML equivalents.

### Attempt 1 — requested trio (`/tmp/opf-wpml-modern-wp`, verified, FAILS)

Observed (`phase1-modern-trio/09-phase1-verdict.log`, WP 6.5.7 / Woo 9.0.2 / PHP 8.2.34):

```
WPML_ST_VERSION=3.5.2   WCML_VERSION=5.5.8   ICL_SITEPRESS_VERSION=5.1.0
StringTranslation_object=WPML_ST_Outdated_Stand_In
woocommerce_wpml_object=woocommerce_wpml   did_action(wcml_loaded)=0
isStOutdated=true
core_requires={"wpml-string-translation":"5.0.0","woocommerce-multilingual":"5.6.0", ...}
icl_string_packages_rows=0  icl_strings_rows=0  icl_string_translations_rows=0
```

OPF *did* emit its whole registration contract — `04b-opf-wpml-probe.log` shows
`wpml_start_string_package_registration` plus **12 `wpml_register_string` calls**
(`field:gift:label` … `field:extra:repeat:label`) and `wpml_delete_unused_package_strings`
for `package=open-product-fields/13` — but the only handler attached to
`wpml_register_string` was the probe itself; WPML's
`WPML_Package_Translation::register_string_action` was never added
(`phase1-modern-trio/05-reg-probe.log`). A manual `WPML_Package_Translation_Schema::run_update()`
creates the table and OPF's group still produces **zero** `icl_strings` rows.

Consequence on the requested trio: nothing appears in the String Translation package
editor, no label can be translated, no multilingual cart/order exists. This is a WPML
version-dependency wall, **not an OPF bug** — but OPF reports nothing (see D1).

### Attempt 2 — coherent set (primary proof, `/tmp/opf-wpml-modern-wp/coherent-wp`)

Same WP/Woo/PHP runtime, WPML core **4.2.8** (`core-wpgpl`) + String Translation **2.10.6**
(`st-wpgpl`) + Translation Management **2.8.7** (`tm-wpgpl`), plus WCML **4.10.3**
(`woocommerce-multilingual`, installed and activated but self-disabling — see R1).

One clone-only shim was needed: `drivers/zz-php8-legacy-polyfills.php` restores
`get_magic_quotes_gpc()` (removed in PHP 8), which ST 2.10.6 calls on boot. It is a
polyfill, not a behaviour change. Nothing else was patched.

---

## 2. Evidence (attempt 2)

### 2.1 OPF strings land in the String Translation package

`phase2-coherent-set/05-records.log` + `05-records-strings.log`:

```
-- icl_string_packages --
ID=1 kind_slug=open-product-fields name=12 title=Gift options (#12)

-- icl_strings of the OPF package --
id=3965 name=field:gift:label      type=LINE   context=open-product-fields-12 value=Gift wrap
id=3966 name=field:gift:description type=AREA  ... value=Choose wrapping
id=3967 name=field:gift:placeholder type=LINE  ... value=Select an option
id=3968 name=field:gift:choice:red  type=LINE  ... value=Red
id=3969 name=field:gift:choice:blue             ... value=Blue
id=3970 name=field:note:label                   ... value=Note
id=3971 name=field:note:placeholder             ... value=Add a note
id=3972 name=field:info:content    type=VISUAL  ... value=<b>Information</b>
id=3973 name=field:extra:label                  ... value=Extras
id=3974 name=field:extra:choice:card            ... value=Card
id=3975 name=field:extra:repeat:add             ... value=Add another
id=3976 name=field:extra:repeat:del             ... value=Remove
id=3977 name=field:extra:repeat:label           ... value=Copy {n}
opf_string_count=13
```

The real handler set on this stack (`04b-opf-wpml-probe.log`) is
`WPML_Package_Translation::register_string_action`, `::translate_string`,
`::start_string_package_registration_action`, `::delete_unused_package_strings_action`,
`WPML_TM_Word_Count_Refresh_Hooks::register_string_action`.

### 2.2 The package editor shows them

`phase2-coherent-set/10-st-domain-package-list.html` (real `wp-admin` HTML,
`admin.php?page=wpml-string-translation/menu/string-translation.php`, admin session):

```html
<option value="open-product-fields-12"
        data-langs="[{&quot;language&quot;:&quot;en&quot;,&quot;count&quot;:&quot;13&quot;,&quot;display_name&quot;:&quot;English&quot;}]">
  open-product-fields-12
```

and `11-st-package-editor.html`
(`…&context=open-product-fields-12&show_results=all&lang=es`) is the package editor for
that context, containing both the source `Gift wrap` and the Spanish
`Envoltorio de regalo` (`12-st-package-context-option.txt`).

### 2.3 Spanish labels render on the storefront

`phase2-coherent-set/07-runtime.log` — every assertion passes (FAILURES=0):

```
en=200 http://127.0.0.1:8097/product/gift-card/            (119222 bytes)
es=200 http://127.0.0.1:8097/es/product/tarjeta-regalo/    (118051 bytes)
PASS R1  EN product page renders the OPF group        PASS R9  ES description translated
PASS R2  EN label is the source string                PASS R9b select empty option follows the language
PASS R3  EN choice label is the source string         PASS R10 ES VISUAL content translated
PASS R4  ES product page is served                    PASS R11 ES repeat labels translated
PASS R5  ES page is the Spanish product               PASS R12 ES page leaks no English label
PASS R6  ES page renders the OPF group                PASS R13 EN page leaks no Spanish label
PASS R7  ES label from the WPML package translation   PASS R14 field ids/choice slugs survive
PASS R8  ES choice labels from the package            PASS R15 EN page keeps the EN product context
```

Rendered ES markup (`es-product.html`):

```html
<label for="opf-12-gift"><span>Envoltorio de regalo</span></label>
<div class="opf-field-description">Elige el envoltorio</div>
<select name="opf[12][gift]" id="opf-12-gift">
  <option value="red" data-opf-choice-hint="red" data-opf-base-label="Rojo"
          data-opf-pricetype="fixed" data-opf-price="5">Rojo + &#36;5.00</option>
```

Field ids, choice slugs, `data-opf-*` attributes and the fixed `+5` pricing are
unchanged by translation; only display text moved.

### 2.4 Translations round-trip through OPF's own filter

`phase2-coherent-set/06-translate.log` — `icl_add_string_translation()` wrote 13 rows
(ids 1515–1527) and reading back through `apply_filters( 'wpml_translate_string', … )`
with OPF's package array returns `Envoltorio de regalo`, `Rojo`, `Azul`,
`Elige el envoltorio`, `Selecciona una opción`, `<b>Información</b>`, `Añadir otro`,
`Quitar`, `Copia {n}` in `es` and the source values in `en`.

### 2.5 Multilingual cart and order

`phase2-coherent-set/08-cart-order.log` (request language `es`, ES product 11, classic
`$_POST['opf']` payload through `woocommerce_add_cart_item_data`):

```
cart_item_data={"12":{"gift":"red","note":"Hola","extra":["card"]}}
cart_totals={"total":"25.00","subtotal":"25"}          # 20 base + 5 fixed choice, per unit
order_id=16 status=pending total=25.00
order_item product=11 name=Tarjeta Regalo
order_item _opf_fields={"12":{"gift":"red","note":"Hola","extra":["card"]}}
order_item visible_meta={"Envoltorio de regalo":"Rojo (+&#36;5.00)","Nota":"Hola","Extras":"Tarjeta"}
order_language=NULL   order_tr_meta=""   order_lang_meta_rows=[]
```

So the cart line, the server-side price and the persisted order item all carry the ES
selections, and the **order-item display labels are the WPML package translations**.
The order's own language record is empty — see R1.

---

## 3. Findings

### D1 — OPF's WPML integration fails silently when WPML switches String Translation off (actionable)

On the requested trio OPF emitted 12 `wpml_register_string` calls and
`wpml_start_string_package_registration`, no handler consumed them, and OPF surfaced
**no notice, no log line, no fallback**. An administrator sees an empty package editor
and untranslated fields with no explanation. OPF has no WPML version guard: readme.txt /
the plugin header declare WP/WC/PHP minimums only, and `WpmlIntegration::init()` registers
its hooks unconditionally. A cheap fix is to compare `WPML_ST_VERSION` (and, for WCML
surfaces, `WCML_VERSION`) against the running core's `wpml-dependencies.json`, then show an
admin notice and/or fall back to plain domain-string registration (`icl_register_string`)
so labels remain translatable when packages are unavailable.

### D2 — rule remapping depends on modern `wpml_object_id` semantics (compat gap)

`WpmlIntegration::translate_groups()` remaps rule terms with
`apply_filters( 'wpml_object_id', $id, 'product'|'product_cat'|'product_tag', true, $lang )`.
On WPML **4.2.8** that call returns the source id unchanged even though the translation
pair exists (`trid 51`, `en`+`es`; `icl_object_id(10,'product',true,'es')=10`,
`icl_object_id(10,'post_product',true,'es')=10`), so a group whose product rule targets
the source-language product **does not render on the translated page**:
`\OPF\Service\FieldGroups::for_product( wc_get_product(11) )` returned **0** groups.
Adding the ES product id to the same rule terms makes the ES page render the group with
the package translations (`07-runtime.log` R6–R11). On WPML **5.1.0** the identical remap
resolves correctly (`phase1-modern-trio/04-fixture.log`: `"es_product_remap": 12`), so this
is an incompatibility with older WPML, not a logic error in OPF — worth documenting as a
minimum WPML version rather than silently relying on the filter.

### Residual R1 — WCML (multilingual products, prices, order language)

Not loadable from the available sources in any consistent combination:

* WCML 5.5.8 + core 5.1.0 → core disables it (`did_action('wcml_loaded')=0`).
* WCML 4.10.3 + core 4.2.8 → its own `wpml-dependencies.json`
  (`dependency-files/wcml-4.10.3-required-versions.json`) requires core ≥ 4.3.16 and
  ST ≥ 3.0.7; the coherent set has core 4.2.8 / ST 2.10.6, so the loader never runs
  (`woocommerce_wpml` object exists, WCML features inert).

Therefore no WCML behaviour is claimed here: no multilingual product sync/pricing, no
`wpml_language` order meta (`order_language=NULL`, no `*lang*` row in `wc_orders_meta`),
and product translation in attempt 2 was linked with WPML core's own
`wpml_set_element_language_details`. The multilingual cart/order proof in §2.5 covers
WPML core + OPF only.

### Observation — the `placeholder` display string is registered but never rendered for selects

The fixture set `placeholder` on the `select` field; `field:gift:placeholder` exists in
the package and is translated, yet neither page prints it: EN prints WooCommerce's own
`Choose an option`, ES prints `Elige una opción`. Assertion R9 was split accordingly —
the test records the real behaviour instead of asserting a placeholder that the renderer
does not use.

---

## 4. Exact commands (disposable clones)

```sh
# containers (disposable): mariadb:10.11 + wordpress:php8.2-apache (PHP 8.2.34)
sudo docker network create opf-wpml-modern-net
sudo docker run -d --name opf-wpml-modern-db --network opf-wpml-modern-net \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wpml_modern \
  -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:10.11
# attempt 1 (requested trio)
sudo docker run -d --name opf-wpml-modern-web --network opf-wpml-modern-net -p 8098:80 \
  -v /tmp/opf-wpml-modern-wp/wp:/var/www/html -v /tmp/opf-wpml-modern-wp/tools:/tools \
  -v /tmp/opf-wpml-modern-wp/out:/out wordpress:php8.2-apache
/tmp/opf-wpml-modern-wp/tools/w.sh core install --url=http://127.0.0.1:8098 --title="OPF WPML Modern Runtime" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
for p in woocommerce sitepress-multilingual-cms wpml-string-translation \
         woocommerce-multilingual open-product-fields-for-woocommerce; do
  /tmp/opf-wpml-modern-wp/tools/w.sh plugin activate $p; done
/tmp/opf-wpml-modern-wp/tools/w.sh eval-file /tools/setup-languages.php
/tmp/opf-wpml-modern-wp/tools/w.sh eval-file /tools/fixture.php     # OPF group 13, emits 12 registrations
/tmp/opf-wpml-modern-wp/tools/w.sh eval-file /tools/reg-probe.php
/tmp/opf-wpml-modern-wp/tools/w.sh eval-file /tools/phase1-verdict.php

# attempt 2 (coherent set) — directory /tmp/opf-wpml-modern-wp/coherent-wp, port 8097
cp -a <sources>/core-wpgpl            wp-content/plugins/sitepress-multilingual-cms
cp -a <sources>/st-wpgpl              wp-content/plugins/wpml-string-translation
cp -a <sources>/tm-wpgpl              wp-content/plugins/wpml-translation-management
cp -a <sources>/woocommerce-multilingual wp-content/plugins/woocommerce-multilingual
sudo docker run -d --name opf-wpml-coherent-web --network opf-wpml-modern-net -p 8097:80 \
  -v /tmp/opf-wpml-modern-wp/coherent-wp/wp:/var/www/html -v /tmp/opf-wpml-modern-wp/coherent-wp/tools:/tools \
  -v /tmp/opf-wpml-modern-wp/coherent-wp/out:/out wordpress:php8.2-apache
tools/w.sh core install --url=http://127.0.0.1:8097 --title="OPF WPML Coherent Runtime" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
for p in woocommerce sitepress-multilingual-cms wpml-string-translation \
         wpml-translation-management woocommerce-multilingual \
         open-product-fields-for-woocommerce; do tools/w.sh plugin activate $p; done
tools/w.sh eval-file /tools/setup-languages2.php     # en (default) + es, directory URLs
tools/w.sh eval-file /tools/fixture.php              # products 10/11, group 12
tools/w.sh eval-file /tools/records.php              # 13 icl_strings in package 1
tools/w.sh eval-file /tools/translate.php            # 13 ES translations + filter read-back
tools/w.sh eval-file /tools/targeting.php            # adds ES product id to the product rule
bash tools/runtime.sh                                # storefront EN vs ES, FAILURES=0
tools/w.sh eval-file /tools/cart-order.php           # ES cart + order 16
curl -sL -b /tmp/opf-admin-c2.txt -o out/11-st-package-editor.html \
  'http://127.0.0.1:8097/wp-admin/admin.php?page=wpml-string-translation/menu/string-translation.php&context=open-product-fields-12&show_results=all&lang=es'
```

Driver scripts are archived unmodified in `wpml-runtime-20261006/drivers/`; the
clone-only mu-plugin probe is `drivers/zz-opf-wpml-probe.php`.

## 5. Artifact map

| Path | Content |
|---|---|
| `phase1-modern-trio/` | requested-trio install, plugin list, languages, OPF hook emissions, `reg-probe`, `09-phase1-verdict.log` (stand-in + zero row counts) |
| `phase2-coherent-set/` | coherent-set plugin list, languages, OPF fixture, `05-records*.log`, `06-translate.log`, `07-runtime.log`, `08-cart-order.log`, EN/ES product HTML, ST package list + package editor HTML |
| `dependency-files/` | the `wpml-dependencies.json` of every plugin used, verbatim |
| `drivers/` | fixture, language setup, records, translate, targeting, cart-order, runtime, probe/polyfill mu-plugins, wp-cli wrappers |

Both clone containers (`opf-wpml-modern-web`, `opf-wpml-coherent-web`,
`opf-wpml-modern-db`) are still up for review; they are disposable and can be removed
with `sudo docker rm -f`.
