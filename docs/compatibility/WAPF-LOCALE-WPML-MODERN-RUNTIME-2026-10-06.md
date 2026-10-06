# WAPF-LOCALE-WPML-MODERN — OPF on the WPML 5.1.0 stack (2026-10-06)

Runtime proof for OPF's WPML integration (`includes/Service/WpmlIntegration.php`,
`wpml-config.xml`) on the **current, coherent WPML stack**: WPML core 5.1.0 +
String Translation 5.1.0 + WooCommerce Multilingual 5.6.1, i.e. the companion
versions WPML core 5.1.0 itself declares in `wpml-dependencies.json`.

**This supersedes the blocked 5.1.0 attempt recorded in
[WAPF-LOCALE-WPML-RUNTIME-2026-10-06.md](./WAPF-LOCALE-WPML-RUNTIME-2026-10-06.md)
(attempt 1, "requested trio").** That attempt paired core 5.1.0 with String
Translation 3.5.2 / WCML 5.5.8, which core disables as outdated, so nothing could
be registered and no OPF translation ever reached the storefront. With String
Translation **5.1.0** and WCML **5.6.1** the same core accepts both companions,
survives `WPML_Plugins_Check::disable_outdated()`, and the whole OPF path works.
The conclusions of the earlier document's *attempt 1* (WPML version-dependency
wall, `WPML_ST_Outdated_Stand_In`, zero `icl_strings` rows) apply only to that
invalid pairing and are obsolete as a statement about core 5.1.0.

Everything ran in a disposable clone under `/tmp`; **no production WordPress
file, database or option was read or written**. Artifacts:
[docs/compatibility/wpml-modern-runtime-20261006/](./wpml-modern-runtime-20261006/).

---

## 1. Stack (exact)

| Component | Version | Origin |
|---|---|---|
| WordPress | 6.5.7 | `wp core download --version=6.5.7` (the container image currently ships WP 7.1.2; pinned to the version used by the sibling 4.2.8 proof) |
| PHP | 8.2.34 | `wordpress:php8.2-apache` |
| WooCommerce | 9.0.2 | copied from the 4.2.8 clone (same version) |
| WPML core | **5.1.0** | `/home/followersya-5hqi7/ops/scratchpad/20261006-opf-gpl-sources/sitepress-5.1.0/sitepress-multilingual-cms` (`sitepress.php` md5 `7c0d7fa24effb565048ec165aa2a57ce`) |
| WPML String Translation | **5.1.0** | `.../wpml-string-translation_v5.1.0-x/wpml-string-translation` (`plugin.php` md5 `508b81f2cb2c921f9555daab3b0095ba`) |
| WooCommerce Multilingual | **5.6.1** | `.../woocommerce-multilingual_v5.6.1-x/woocommerce-multilingual` (`wpml-woocommerce.php` md5 `b3b4a4a46844b5794dec64afe192ecf3`) |
| WPML Translation Management | 2.11.0 (**bundled in core 5.1.0**) | `sitepress-multilingual-cms/tm.php`; no TM plugin installed — `WPML_TM_VERSION` is defined by core, which is what ST's `passed_dependencies()` checks |
| MariaDB | 10.11.19 | `mariadb:10.11` |
| Plugin under test | `open-product-fields-for-woocommerce` **0.1.1**, commit **`85ede306da1ae4a3046ef10b16be1140b0f0e6f4`** | `git archive HEAD` (verified byte-identical to the exported commit for `WpmlIntegration.php` and the bootstrap) |

Companion requirements, copied verbatim to `dependency-files/`:

* core 5.1.0 → `{"wpml-string-translation": "5.0.0", "woocommerce-multilingual": "5.6.0", ...}`
* ST 5.1.0 → `{"sitepress-multilingual-cms": "5.0.0"}`
* WCML 5.6.1 → `{"sitepress-multilingual-cms": "5.0.0"}`

5.1.0 ≥ 5.0.0 and 5.6.1 ≥ 5.6.0, so `WPML_Plugins_Check::disable_outdated()` takes
no branch at all: no `remove_outdated_st_hooks()`, no stand-in, no WCML stand-in.

---

## 2. Requirement 1 — WPML accepts the companions

`out/03-stack-probe-after-setup.log` (real `wp eval-file` output; the values below
are the unedited lines):

```
wp=6.5.7 php=8.2.34
ICL_SITEPRESS_VERSION=5.1.0
WPML_ST_VERSION=5.1.0
WCML_VERSION=5.6.1
WPML_TM_VERSION=2.11.0
StringTranslation_object=WPML_String_Translation
woocommerce_wpml_object=woocommerce_wpml
class WPML_ST_Outdated_Stand_In defined=no
did_action(wpml_loaded)=1
did_action(wcml_loaded)=1
isStOutdated=false
class_exists WPML_Package_Translation=yes WPML_ST_Package_Factory=yes WPML_Package=yes
setup_complete=1
active_languages=en,es
action(wpml_register_string)=true
action(wpml_start_string_package_registration)=true
action(wpml_delete_unused_package_strings)=true
action(wpml_delete_package)=true
filter(wpml_translate_string)=true
filter(wpml_st_get_string_package)=true
filter(wpml_active_string_package_kinds)=true
filter(wpml_object_id)=true
```

The real registration handler set on this stack (mu-plugin probe,
`out/opf-wpml-probe.log`, `[admin]` request):

```
HOOK-STATE WPML_TM_VERSION=2.11.0 setup_complete=1
  action(wpml_register_string)=1:closure,10:WPML_Package_Translation::register_string_action
  filter(wpml_translate_string)=1:closure,10:WPML_Package_Translation::translate_string
  action(wpml_start_string_package_registration)=1:closure,10:WPML_Package_Translation::start_string_package_registration_action
  action(wpml_delete_unused_package_strings)=1:closure,10:WPML_Package_Translation::delete_unused_package_strings_action
  filter(wpml_st_get_string_package)=10:WPML_Package_Translation::get_string_package
  filter(wpml_active_string_package_kinds)=10:WPML_Package_Translation::get_active_string_package_kinds
```

* String Translation is the real bootstrap (`WPML_String_Translation`), **not**
  `WPML_ST_Outdated_Stand_In` (class not even defined), `WPML_Plugins_Check::isStOutdated() === false`.
* `did_action('wcml_loaded') = 1` → WCML 5.6.1 actually loaded (in the 4.2.8 proof
  it self-disabled; in the blocked attempt core switched it off).
* Whole registration contract for one OPF group (mu-plugin probe, CLI save):

```
EMIT wpml_start_string_package_registration package=open-product-fields/14 title=Gift options (#14)
EMIT wpml_register_string name=field:gift:label type=LINE value=Gift wrap package=open-product-fields/14
... 13 in total (gift label/description/placeholder, 2 choices, note label/placeholder,
    info content (VISUAL), extra label/choice, 3 repeat labels) ...
EMIT wpml_delete_unused_package_strings package=open-product-fields/14
```

---

## 3. Requirement 2 — real DB rows and the String Translation package editor

Fixture: terms EN `Gifts` / `handmade`, their ES translations, product A EN `gift-card`
(`Gift Card`, id 10) + ES `tarjeta-regalo` (`Tarjeta Regalo`, id 11), an unrelated
control product B EN `plain-mug` (12) + ES `taza-lisa` (13), and OPF group 14 whose
placement rules target **only the EN elements of product A**:
`product in [10]`, `product_cat in [17]`, `product_tag in [19]`.
Full fixture output: `out/04-fixture.log`.

`out/05-records.log` (before any translation is written):

```
-- icl_string_packages --
ID=1 kind_slug=open-product-fields kind=Open Product Fields name=14 title=Gift options (#14)
package_rows=1
opf_package_id=1 opf_package_context=open-product-fields-14

-- icl_strings of the OPF package (string_package_id=1) --
id=27 name=field:gift:label      type=LINE   status=0 context=open-product-fields-14 package=1 value=Gift wrap
id=28 name=field:gift:description type=AREA  ... value=Choose wrapping
id=29 name=field:gift:placeholder type=LINE  ... value=Select an option
id=30 name=field:gift:choice:red  type=LINE  ... value=Red
id=31 name=field:gift:choice:blue type=LINE  ... value=Blue
id=32 name=field:note:label       type=LINE  ... value=Note
id=33 name=field:note:placeholder type=LINE  ... value=Add a note
id=34 name=field:info:content     type=VISUAL ... value=<b>Information</b>
id=35 name=field:extra:label      type=LINE  ... value=Extras
id=36 name=field:extra:choice:card type=LINE ... value=Card
id=37 name=field:extra:repeat:add type=LINE  ... value=Add another
id=38 name=field:extra:repeat:del type=LINE  ... value=Remove
id=39 name=field:extra:repeat:label type=LINE ... value=Copy {n}
opf_string_count=13
strings_with_opf_package_context=13
```

* package row: **1**, `icl_strings` rows in the package context: **13** (> 0).
* `string_package_id` holds the `icl_string_packages.ID` (1) and the string
  context is `open-product-fields-<group post id>` (14) — same layout as the 4.2.8
  proof (package 1 / `open-product-fields-12`).

`out/06-translate.log`: all 13 ES translations written through ST's own API
(`icl_add_string_translation()`, status `ICL_TM_COMPLETE`), rows 4–16 of
`icl_string_translations`.

The **package editor** (real `wp-admin` HTML, admin session, ES language):
`out/11-st-package-editor.html`, captured from
`/wp-admin/admin.php?page=tm/menu/main.php&tab=strings&context=open-product-fields-14&show_results=all&lang=es`
(ST 5.1.0 serves the string-translation screen from the TM menu; the legacy
`page=wpml-string-translation/menu/string-translation.php` URL redirects there —
see `out/10-admin-capture.log`).

```html
<tr ... data-...="{&quot;string_id&quot;:&quot;27&quot;,&quot;string_language&quot;:&quot;en&quot;,
  &quot;string_package_id&quot;:&quot;1&quot;,&quot;context&quot;:&quot;open-product-fields-14&quot;,
  &quot;name&quot;:&quot;field:gift:label&quot;,&quot;value&quot;:&quot;Gift wrap&quot;,
  &quot;status&quot;:&quot;10&quot;,&quot;translation_priority&quot;:&quot;optional&quot;,
  &quot;translations&quot;:{&quot;es&quot;:{&quot;id&quot;:&quot;4&quot;,&quot;language&quot;:&quot;es&quot;,
  &quot;status&quot;:&quot;10&quot;,&quot;value&quot;:&quot;Envoltorio de regalo&quot;,&quot;mo_string&quot;:null,
  &quot;translator_id&quot;:null,&quot;translation_date&quot;:&quot;2026-10-06 21:30:48&quot;}},&quot;hasFrontendKind&quot;:0}">
  <td ...><input class="wpml-checkbox-native icl_st_row_cb icl_st_row_package js-icl-st-row-cb" type="checkbox" value="27" data-language="en" /></td>
  <td class="wpml-st-col-domain">open-product-fields-14</td>
  <td class="wpml-st-col-name">field:gift:label</td>
```

`out/10b-st-html-snippets.txt` summarises it: 13 rows with
`context = open-product-fields-14` and 13 with `language = es`; source values
(`Gift wrap`, `Choose wrapping`, `Red`) and ES translations
(`Envoltorio de regalo`, `Elige el envoltorio`, `Rojo`) are all present in the
same page. The ST domain selector on `out/10-st-domain-list.html` lists the OPF
package with its string count:

```html
<option value="open-product-fields-14" data-unfiltered-count="13">open-product-fields-14 (13)</option>
```

---

## 4. Requirement 3 — storefront EN and ES

`out/07-runtime.log` (real HTTP, curl, no browser substitution), **21/21 PASS,
FAILURES=0** (EN/ES product A, plus the unrelated control product B in both
languages):

```
en=200 http://127.0.0.1:8096/product/gift-card/
es=200 http://127.0.0.1:8096/es/producto/tarjeta-regalo/     (→ requested /es/product/tarjeta-regalo/ redirects here)
en_other=200 http://127.0.0.1:8096/product/plain-mug/
es_other=200 http://127.0.0.1:8096/es/producto/taza-lisa/
bytes en=118662 es=142794 en_other=113073 es_other=135550
PASS R1  EN product page renders the OPF group            PASS R11 ES repeat labels translated
PASS R2  EN field label is the source string              PASS R12 ES page does not leak the English field label
PASS R3  EN choice labels are the source strings          PASS R13 EN page does not leak the Spanish field label
PASS R4  ES product page is served                        PASS R14 field ids and choice slugs survive translation
PASS R5  ES page is the Spanish product                   PASS R15 ES choice pricing unchanged (5.00 fixed on slug red)
PASS R6  ES page renders the OPF group                    PASS R16 EN page keeps the EN product context
PASS R7  ES field label is the WPML package translation   PASS R17 unrelated product EN page has no OPF group
PASS R8  ES choice labels are the package translations    PASS R18 unrelated product ES page has no OPF group
PASS R9  ES description translated                        PASS R19 unrelated product ES page is the ES product
PASS R9b select empty option follows the language         PASS R20 ES page shows the translated option text with the fixed price
PASS R10 ES VISUAL content translated
FAILURES=0
```

Server-rendered markup, EN vs ES (`out/en-product.html`, `out/es-product.html`):

```html
<!-- EN -->
<label for="opf-14-gift"><span>Gift wrap</span></label>
<div class="opf-field-description">Choose wrapping</div>
<option value="red" data-opf-choice-hint="red" data-opf-base-label="Red"
        data-opf-pricetype="fixed" data-opf-price="5">Red + &#36;5.00</option>

<!-- ES -->
<label for="opf-14-gift"><span>Envoltorio de regalo</span></label>
<div class="opf-field-description">Elige el envoltorio</div>
<option value="red" data-opf-choice-hint="red" data-opf-base-label="Rojo"
        data-opf-pricetype="fixed" data-opf-price="5">Rojo + &#36;5.00</option>
<b>Información</b> ... Añadir otro ... Quitar ... Copia {n}
```

Field ids (`opf-14-gift`, `name="opf[14][gift]"`), choice slugs (`red`, `blue`),
`data-opf-*` attributes and the fixed `+5.00` pricing are identical in both
languages: only display text is translated.

Same value through the runtime filter OPF uses when no page is involved
(`out/06b-readback-fresh.log`, fresh process):

```
[en] field:gift:label => Gift wrap          [es] field:gift:label => Envoltorio de regalo
[en] field:gift:choice:red => Red           [es] field:gift:choice:red => Rojo
[en] field:extra:repeat:add => Add another  [es] field:extra:repeat:add => Añadir otro
[en] label=Gift wrap choices=["Red","Blue"] [es] label=Envoltorio de regalo choices=["Rojo","Azul"]
```

---

## 5. Requirement 4 — language-specific product targeting resolves

The group targets **only EN ids** (`product=10`, `product_cat=17`, `product_tag=19`)
and is stored untranslated (`out/04-fixture.log`, `stored_rule_terms`). Placement
resolved on this stack (`out/05-records.log`, before translations; plus
`out/07-runtime.log` R6/R17/R18 after them):

```
-- wpml_object_id remaps (the rule terms OPF must remap) --
product 10 -> es = 11        product 11 -> en = 10
cat 17 -> es = 18            tag 19 -> es = 20
unrelated product 12 -> es = 13

-- OPF runtime placement (FieldGroups::for_product) --
[en] product=10 groups=1 14:Gift options:Gift wrap
[es] product=11 groups=1 14:Gift options:<ES label>
[en] product=12 groups=0
[es] product=13 groups=0
```

So `WpmlIntegration::translate_groups()`'s `wpml_object_id` remap works on 5.1.0:
the EN-targeted rule follows the translation to product 11, and **no group is
placed on the unrelated product** in either language (R17/R18 also assert this on
the served HTML). The ES-side rule workaround that the 4.2.8 proof needed
(adding the ES product id to the terms) is **not** needed here — see §8 (D2).

---

## 6. Requirement 5 — multilingual cart and order (ES)

`out/09-cart-order.log` — request language `es`, ES product 11, classic
`$_POST['opf']` payload through OPF's real capture path:

```
current_language=es es_product=11 cart_item_key='dec56d20a901ed5cd23013551bbf2964'
cart_item_data={"14":{"gift":"red","note":"Hola","extra":["card"]}}
cart_totals={"total":"25.00","subtotal":"25"}
cart_line_name="Tarjeta Regalo" line_total=25
cart_line_display="Envoltorio de regalo: Rojo <span class=\"opf-pricing-hint\">(+&#036;5.00)</span>
                   Nota: Hola
                   Extras: Tarjeta"
cart_line_display_html="<dl class=\"variation\">
                   <dt class=\"variation-Envoltorioderegalo\">Envoltorio de regalo:</dt>
                   <dd class=\"variation-Envoltorioderegalo\"><p>Rojo <span class=\"opf-pricing-hint\">(+&#036;5.00)</span></p></dd>
                   <dt class=\"variation-Nota\">Nota:</dt><dd class=\"variation-Nota\"><p>Hola</p></dd>
                   <dt class=\"variation-Extras\">Extras:</dt><dd class=\"variation-Extras\"><p>Tarjeta</p></dd></dl>"
order_id=17 status=pending total=25.00
order_item product=11 name=Tarjeta Regalo opf_fields="{\"14\":{\"gift\":\"red\",\"note\":\"Hola\",\"extra\":[\"card\"]}}"
order_item_visible_meta={"Envoltorio de regalo":"Rojo (+&#36;5.00)","Nota":"Hola","Extras":"Tarjeta"}
order_wpml_language_meta="es"
```

`out/09b-order-language.log` — WCML's own view of the same order:

```
order_id=17
wcml_get_order_language='es'
wpml_language_meta="es"
postmeta_rows=[{"meta_key":"wpml_language","meta_value":"es"}]
item_language_by_item_id='es'
order_total=25.00
```

* cart line: selections persisted as slugs (`gift=red`), rendered by WooCommerce's
  own cart formatter with the **translated** labels and the correct per-unit
  surcharge (20 + 5 = **25.00**).
* order item: `_opf_fields` keeps the slugs; the visible order-item meta uses the
  translated label keys and translated choice values.
* order language: `wpml_language=es` (HPOS is off in this clone, so the meta lives
  in `wp_postmeta`; `wp_wc_orders_meta` has no rows) and WCML resolves it to `es`.

---

## 7. Requirement 6 — the D1 guard does **not** fire on this stack

The D1 guard (`WpmlIntegration::strings_api_available()`, added by
[WPML-D1-GUARD-2026-10-06.md](./WPML-D1-GUARD-2026-10-06.md)) must take its
*enabled* branch here, because the WPML String Translation package API is live.

`out/opf-wpml-probe.log`, real HTTP requests (admin and front end):

```
[admin] /wp-admin/  GUARD-BOOT icl_version_defined=true handler=true
        st_global=WPML_String_Translation strings_api_available=true
[admin] /wp-admin/  GUARD strings_api_available=true outdated_class=absent
        isStOutdated=false admin_notice_hooked=false register_handler=true
[admin] …tab=strings&context=open-product-fields-14…  GUARD strings_api_available=true
        outdated_class=absent isStOutdated=false admin_notice_hooked=false register_handler=true
[front] /es/producto/tarjeta-regalo/  GUARD strings_api_available=true
        outdated_class=absent isStOutdated=false admin_notice_hooked=false register_handler=true
```

and in `wp eval-file` (`out/03-stack-probe-after-setup.log`):

```
OPF strings_api_available=true
action wpml_register_string handlers=1:closure,10:WPML_Package_Translation::register_string_action
```

Corroborating evidence that the disabled branch was not taken:

1. `WPML_ST_Outdated_Stand_In` is never defined and `WPML_Plugins_Check::isStOutdated() === false`.
2. `admin_notice_hooked=false` on real admin requests — the guard did not even
   queue the warning; no OPF warning markup exists in
   `out/12-admin-dashboard.html`, `out/11-st-package-editor.html`,
   `out/10-st-domain-list.html` (`out/10-admin-capture.log`: *"guard notice absent"* ×3).
3. OPF emitted the full registration contract (13 `wpml_register_string` + start +
   delete-unused, `out/opf-wpml-probe.log`) and the DB rows in §3 exist — with the
   disabled branch `register_post()` returns before emitting anything.
4. At `plugins_loaded` (immediately after OPF's boot at priority 20) WPML core
   5.1.0 is already fully initialised — `GUARD-BOOT … st_global=WPML_String_Translation`,
   so the boot-time check sees the live handler too.

---

## 8. Requirement 7 — behavioural differences vs the 4.2.8 proof

| Area | 4.2.8 / ST 2.10.6 proof | **5.1.0 / ST 5.1.0 (this proof)** |
|---|---|---|
| **D2 — `wpml_object_id` rule remap** | returned the source id (`product 10 → es = 10`), so an EN-targeted rule did **not** reach the ES page; the proof had to add the ES product id to the rule terms | **resolves** (`product 10 → es = 11`, `cat 17 → es = 18`, `tag 19 → es = 20`). OPF's remap alone places the group on the ES page; the ES-id workaround is unnecessary on 5.1.0 (`out/04-fixture.log`, `out/05-records.log`) |
| **How `wpml_translate_string` delivers package strings** | ST 2.10.6: `WPML_Package::translate_string()` → `icl_translate()` did a direct DB lookup, so the Spanish values came back as soon as the `icl_string_translations` rows existed | ST 5.1.0: `icl_translate()` routes non-md5 string names through **`WPML\ST\TranslateWpmlString`** (a MO/`__()` lookup). The values only appear after ST has built the package MO file under `wp-content/languages/wpml/` (see F1) |
| **MO/translation-file build** | n/a | `icl_add_string_translation()` fires `wpml_st_add_string_translation`; ST's `WPML\ST\TranslationFile\UpdateHooks` queues the domain+locale and, on `shutdown`, writes `open-product-fields-14-es_ES.mo` (+ `.l10n.php`, `.json`) — `out/06c-st-translation-files.log`. `WPML_ST_SYNC_TRANSLATION_FILES` stays undefined, i.e. this is ST's default path, not a switched-on extra |
| **Same-request read-back** | translated value readable immediately after writing it | still the **source** in the request that writes the translation (the MO is written at `shutdown`); a later request sees Spanish. `out/06-translate.log` (same process: all source) vs `out/06b-readback-fresh.log` (fresh process: Spanish) |
| **WCML** | self-disabled (R1 in the old doc): no order language, no product sync | **live** (`did_action('wcml_loaded')=1`); the order carries `wpml_language=es`, and WCML translates the product permalink base, so the ES URL is `/es/producto/tarjeta-regalo/` (a request to `/es/product/…` 301s there) |
| **WPML TM** | TM 2.8.7 installed as a separate plugin | `WPML_TM_VERSION=2.11.0` comes **bundled with core 5.1.0** (`tm.php`); nothing extra installed. Required, because ST's `WPML_Package_Translation::passed_dependencies()` needs `WPML_TM_VERSION` |
| **WPML admin screen** | `admin.php?page=wpml-string-translation/menu/string-translation.php` | that URL still exists but redirects; ST 5.1.0 renders strings at `admin.php?page=tm/menu/main.php&tab=strings` (`out/10-admin-capture.log`) |
| **Select `placeholder`** | registered + translated, but never rendered (WC prints its own empty option) | same behaviour on 5.1.0 — `field:gift:placeholder` is in the package and translated; both pages print WooCommerce's "Choose an option" / "Elige una opción" (R9b) |

Two WCML/ WooCommerce behaviours observed while building the fixture (not OPF
issues, recorded so the next person does not chase them):

* A translation's `set_sku()` must run **after** the language link. Written before
  it, WooCommerce 9.0.2 throws `WC_Data_Exception: Invalid or duplicated SKU`
  because WCML only exempts a translation from the uniqueness guard once the pair
  shares a trid (`inc/class-wcml-products.php: check_product_inventory_uid`).
  `out/04-fixture.log` shows the result after linking: `"sku_unique_after_link": true`.
* WCML re-syncs the product's taxonomies from the source, so the ES product keeps
  the **EN** term ids (`es_product_cats=[17]`, `es_product_tags=[19]`) even though
  the terms themselves are translated (`cat 17 → es = 18`). The category rule
  therefore matches on the ES page without a term remap; the *product* rule is the
  one that exercises OPF's remap.

---

## 9. Findings

### F1 — on ST 5.1.0 a package translation is only served once ST has built the package MO file (environment constraint, not an OPF defect)

Verified mechanism (`out/08-mo-build-without-langdir.log` vs
`out/08b-mo-build-with-langdir.log`):

```
handles(open-product-fields-14)=true          # the package context IS an MO domain
db_rows_for_mo(es, partial)=13                # the translations are in the DB
manager->add(open-product-fields-14, es_ES)=false        # ← wp-content/languages absent
mo_exists=false  wpml_translate_string(es)=Gift wrap     # storefront would show English
# after creating the standard wp-content/languages directory:
manager->add(open-product-fields-14, es_ES)='/var/www/html/wp-content/languages/wpml/open-product-fields-14-es_ES.mo'
mo_exists=true   wpml_translate_string(es)=Envoltorio de regalo
```

`WPML\ST\MO\File\makeDir::maybeCreateSubdir()` creates exactly one level
(`wp-content/languages/wpml`) and `WP_Filesystem_Direct::mkdir()` does not create
missing parents, so on an install where `wp-content/languages` does not exist the
MO build fails silently and every label stays in the source language **even though
the package, the strings and the translations all exist**. This clone had to create
the directory by hand because `wp core download --skip-content` produced a tree
without it; a stock WordPress install creates it (language packs/updates), and the
clone's front end then serves Spanish with no extra step.

For OPF this is a *delivery* constraint worth knowing, not a defect: OPF uses the
documented package API and reads back through `wpml_translate_string` exactly as
WPML intends. It is **not** covered by the D1 guard (the guard detects a disabled
String Translation, not an unbootstrapped MO directory), and the guard's premise
("the API is live") is true here. If this ever needs to be surfaced, the honest
signal is "ST is live but its MO file could not be written" — a new product
decision, deliberately **not** implemented in this lane.

### F2 — no OPF defect found on this stack

Registration, package/DB rows, the ST package editor, EN/ES storefront rendering,
language-specific targeting, multilingual cart and order, and the D1 guard all
behave as designed. The only OPF-visible nuance is the same one already recorded
for 4.2.8: a `select` field's `placeholder` display string is registered and
translated but never rendered by the field renderer.

---

## 10. Residual risks

* **R1 — WP 6.5.7 only.** The container image now ships WordPress 7.1.2; this proof
  pins 6.5.7 to stay comparable with the 4.2.8 run. WP 7.1.x was not exercised.
* **R2 — no multicurrency.** WCML's multicurrency was never enabled, so no currency
  conversion is claimed; the 25.00 total is a single-currency result.
* **R3 — no HPOS.** Orders are stored as posts in this clone (`wp_postmeta`
  `wpml_language`); the `wp_wc_orders_meta` table stayed empty.
* **R4 — single site, one translation pair.** Two languages, one field group, one
  simple product pair plus a control product. Variable products, price/formula
  fields, attributes and the importer were not re-driven on this stack.
* **R5 — MO-file timing.** A request that writes a translation does not see it
  (the MO is written at `shutdown`). Any integration that saves translations and
  renders labels in the same request must re-load or issue a second request.
* **R6 — `WPML_ST_SYNC_TRANSLATION_FILES` undefined.** The flag that makes ST
  regenerate MO files when a front-end `load_textdomain` finds a stale/missing file
  is off by default; this proof therefore relies on ST's `shutdown` queue, and on
  the directory existing.
* **R7 — source/provenance.** The three WPML folders come from the GPL source
  bundle in `ops/scratchpad/20261006-opf-gpl-sources` (md5s in
  `dependency-files/sources.txt`); they are not the marketplace packages. Only the
  version constants, `wpml-dependencies.json` and runtime behaviour were verified.
* **R8 — clone-only instrumentation.** The mu-plugin probe
  (`drivers/zz-opf-wpml-probe.php`) and the fixture scripts are disposable-clone
  instrumentation, archived for reproduction; none of it ships with OPF.

---

## 11. Exact commands

```sh
# --- disposable clone (all under /tmp/opf-wpml-modern-wp/stack510) ----------
sudo docker run -d --name opf-wpml-stack510-web --network opf-wpml-modern-net -p 8096:80 \
  -v /tmp/opf-wpml-modern-wp/stack510/wp:/var/www/html \
  -v /tmp/opf-wpml-modern-wp/stack510/tools:/tools \
  -v /tmp/opf-wpml-modern-wp/stack510/out:/out wordpress:php8.2-apache
# DB: the disposable mariadb container opf-wpml-modern-db, database wpml_stack510

S=/home/followersya-5hqi7/ops/scratchpad/20261006-opf-gpl-sources
P=/tmp/opf-wpml-modern-wp/stack510/wp/wp-content/plugins
sudo cp -a $S/sitepress-5.1.0/sitepress-multilingual-cms          $P/
sudo cp -a $S/wpml-string-translation_v5.1.0-x/wpml-string-translation $P/
sudo cp -a $S/woocommerce-multilingual_v5.6.1-x/woocommerce-multilingual $P/
sudo cp -a /tmp/opf-wpml-modern-wp/wp/wp-content/plugins/woocommerce $P/       # WC 9.0.2
git -C /home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/open-product-fields-for-woocommerce \
  archive 85ede306da1ae4a3046ef10b16be1140b0f0e6f4 | sudo tar -x -C $P/open-product-fields-for-woocommerce
sudo cp <repo>/docs/compatibility/wpml-modern-runtime-20261006/drivers/zz-opf-wpml-probe.php \
  /tmp/opf-wpml-modern-wp/stack510/wp/wp-content/mu-plugins/

# --- one-shot reproduction of every step below ----------------------------
bash /tmp/opf-wpml-modern-wp/stack510/tools/run-proof.sh
# (the script resets the DB, installs WP, activates woocommerce +
#  sitepress-multilingual-cms + wpml-string-translation + woocommerce-multilingual +
#  open-product-fields-for-woocommerce, sets en/es with directory URLs, flushes
#  rewrite rules, then runs every driver and writes out/*.log)

# the sequence it runs, one command at a time:
tools/w.sh eval-file /tools/probe-state.php        # versions, handler set, guard state
tools/w.sh eval-file /tools/fixture.php            # products/terms/translations + OPF group 14
tools/w.sh eval-file /tools/records.php            # icl_string_packages / icl_strings / remaps
tools/w.sh eval-file /tools/translate.php          # 13 ES translations through icl_add_string_translation
sudo docker exec opf-wpml-stack510-web sh -c 'ls -la /var/www/html/wp-content/languages/wpml/'
tools/w.sh eval-file /tools/readback.php           # fresh process: ES values through wpml_translate_string
bash tools/runtime.sh                              # storefront EN/ES + control product, FAILURES=0
tools/w.sh eval-file /tools/cart-order.php         # ES cart item + order 17
tools/w.sh eval-file /tools/order-lang.php         # WCML order language
bash tools/admin-capture.sh                        # wp-admin login + ST pages + guard check
tools/w.sh eval-file /tools/mo-build-requirement.php   # MO build with/without wp-content/languages

# --- acceptance gates in the plugin repo ----------------------------------
vendor/bin/phpunit --no-coverage
node --test tests/js/*.cjs
```

Clone-only environment notes: `tools/w.sh` is
`sudo docker exec opf-wpml-stack510-web php /tools/wp-cli.phar --path=/var/www/html --allow-root "$@"`;
the clone needed a hand-written WordPress-root `.htaccess` (standard WordPress rules,
because `wp rewrite flush --hard` refuses to write it under WP-CLI) and
`wp-content/languages/` (see F1).

---

## 12. Tests

```
$ vendor/bin/phpunit --no-coverage
Tests: 1105, Assertions: 4716, PHPUnit Deprecations: 2.
OK, but there were issues!   # the 2 PHPUnit deprecations pre-date this lane

$ node --test tests/js/*.cjs
ℹ tests 132   ℹ pass 132   ℹ fail 0   ℹ cancelled 0   ℹ skipped 0
```

Raw tails saved as `out/tests-phpunit.log` and `out/tests-node.log`. Nothing in
the plugin was changed by this lane; the counts are the current `master` counts
(the lane added documentation and evidence only).

---

## 13. Artifact map

| Path | Content |
|---|---|
| `out/02-plugins.log` | activated plugin list with versions |
| `out/02-languages.log` | WPML setup: en default + es, directory URLs, `setup_complete=1` |
| `out/03-stack-probe-after-setup.log` | versions, `did_action(wpml_loaded/wcml_loaded)`, stand-in/`isStOutdated`, hook handler set, OPF guard state |
| `out/04-fixture.log` | products/terms/translations, `wpml_object_id` remaps, OPF group 14 JSON + stored rule terms |
| `out/05-records.log` | `icl_string_packages` (1 row), 13 `icl_strings` in the package, `icl_translations`, remaps, `FieldGroups::for_product()` per language |
| `out/05c-icl-strings-all.log` | raw dump of every `icl_strings` row after the run: the 13 entries with `context=open-product-fields-14` / `string_package_id=1` (ids 27–39, status 10 once translated) alongside the site's other ST strings |
| `out/06-translate.log` | 13 ES translations written through ST, plus the same-process read-back (source, by design) |
| `out/06b-readback-fresh.log` | fresh-process `wpml_translate_string` + `for_product()` output in EN and ES |
| `out/06c-st-translation-files.log` | the MO/l10n/json files ST wrote on shutdown |
| `out/07-runtime.log` | storefront EN/ES + control product, 21 checks, `FAILURES=0` |
| `out/08-mo-build-without-langdir.log`, `out/08b-mo-build-with-langdir.log` | F1: `Manager::add()` false/true depending on `wp-content/languages` |
| `out/09-cart-order.log`, `out/09b-order-language.log` | ES cart line, order 17 (total 25.00), `_opf_fields`, translated visible meta, WCML order language |
| `out/10-admin-capture.log`, `out/10b-st-html-snippets.txt` | admin captures + the extracted ST evidence |
| `out/10-st-domain-list.html`, `out/11-st-package-editor.html`, `out/12-admin-dashboard.html`, `out/13-opf-group-editor.html` | real `wp-admin` HTML (domain selector, EN+ES package editor, dashboard guard check, OPF group editor) |
| `out/en-product.html`, `out/es-product.html`, `out/en-other.html`, `out/es-other.html` | served storefront HTML for both products in both languages |
| `out/opf-wpml-probe.log` | mu-plugin probe: every WPML hook emission OPF made, handler state, guard state per request |
| `dependency-files/` | the three `wpml-dependencies.json` files + `sources.txt` (paths + md5s) |
| `drivers/` | every script used above, unmodified (`probe-state.php`, `fixture.php`, `records.php`, `dump-strings-all.php`, `translate.php`, `readback.php`, `cart-order.php`, `order-lang.php`, `mo-build-requirement.php`, `setup-languages.php`, `runtime.sh`, `admin-capture.sh`, `run-proof.sh`, `w.sh`, `zz-opf-wpml-probe.php`) |

Both containers (`opf-wpml-stack510-web`, shared DB `opf-wpml-modern-db`) and the
clone directory are still up for review; they are disposable
(`sudo docker rm -f opf-wpml-stack510-web`).
