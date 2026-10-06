#!/usr/bin/env bash
# End-to-end reproduction of the WPML 5.1.0 runtime proof inside the disposable
# clone /tmp/opf-wpml-modern-wp/stack510 (container opf-wpml-stack510-web).
#
# Resets the clone DB, installs WordPress + WooCommerce + the WPML 5.1.0 trio +
# OPF at the exported commit, configures en/es, then drives the whole proof and
# writes every artifact to $D/out.
set -euo pipefail
D=/tmp/opf-wpml-modern-wp/stack510
WP="sudo docker exec opf-wpml-stack510-web"
RUN() { $D/tools/w.sh "$@"; }

echo "== clean state =="
$WP sh -c 'rm -rf /var/www/html/wp-content/languages/wpml'
sudo rm -f "$D"/out/*.log "$D"/out/*.html "$D"/out/*.txt "$D"/out/admin-cookies.txt

echo "== fresh WordPress install =="
sudo docker exec opf-wpml-modern-db mariadb -uroot -proot -e \
  "DROP DATABASE IF EXISTS wpml_stack510; CREATE DATABASE wpml_stack510 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON wpml_stack510.* TO 'wp'@'%'; FLUSH PRIVILEGES;"
RUN core install --url=http://127.0.0.1:8096 --title="OPF WPML 5.1.0 Runtime" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
for p in woocommerce sitepress-multilingual-cms wpml-string-translation woocommerce-multilingual open-product-fields-for-woocommerce; do
  RUN plugin activate "$p" >/dev/null
done
RUN plugin list --fields=name,status,version 2>/dev/null | tee "$D/out/02-plugins.log"
RUN eval-file /tools/setup-languages.php 2>&1 | tee "$D/out/02-languages.log"
RUN rewrite flush --hard 2>&1 | tail -1
RUN eval '$s = get_option("wcml_settings", array()); $s["set_up_wizard_run"]=1; $s["set_up_wizard_splash"]=1; update_option("wcml_settings", $s); delete_transient("_wcml_activation_redirect");' >/dev/null

echo "== stack probe =="
RUN eval-file /tools/probe-state.php 2>&1 | tee "$D/out/03-stack-probe-after-setup.log"

echo "== fixture (products, translations, OPF group) =="
RUN eval-file /tools/fixture.php 2>&1 | tee "$D/out/04-fixture.log"

echo "== DB rows before translation =="
RUN eval-file /tools/records.php 2>&1 | tee "$D/out/05-records.log"

echo "== write ES translations through ST =="
RUN eval-file /tools/translate.php 2>&1 | tee "$D/out/06-translate.log"
$WP sh -c 'ls -la /var/www/html/wp-content/languages/wpml/' | tee "$D/out/06c-st-translation-files.log"

echo "== read back in a fresh process =="
RUN eval-file /tools/readback.php 2>&1 | tee "$D/out/06b-readback-fresh.log"

echo "== storefront =="
bash "$D/tools/runtime.sh" 2>&1 | tee "$D/out/07-runtime.log"

echo "== multilingual cart + order =="
RUN eval-file /tools/cart-order.php 2>&1 | tee "$D/out/09-cart-order.log"
RUN eval-file /tools/order-lang.php 2>&1 | tee "$D/out/09b-order-language.log"

echo "== wp-admin capture =="
bash "$D/tools/admin-capture.sh" 2>&1 | tee "$D/out/10-admin-capture.log"

echo "== ST editor snippets =="
python3 - > "$D/out/10b-st-html-snippets.txt" <<'PY'
import re, html
def read(p):
    return open(p, encoding='utf-8', errors='replace').read()
d = '/tmp/opf-wpml-modern-wp/stack510/out/'
lst = read(d + '10-st-domain-list.html')
ed  = read(d + '11-st-package-editor.html')
def around(text, needle, before=200, after=200):
    i = text.find(needle)
    return 'NOT FOUND: ' + needle if i < 0 else text[max(0, i-before):i+after].strip()
print('=== ST string-translation page: OPF package context in the domain selector ===')
for line in around(lst, 'open-product-fields-14', 60, 120).splitlines():
    if line.strip():
        print(line.strip())
print()
print('=== ST package editor row for field:gift:label (EN source + ES translation), decoded ===')
i = ed.find('Gift wrap')
start = ed.rfind('{', max(0, ed.rfind('&quot;id&quot;', 0, i) - 200), i + 1)
print(html.unescape(ed[start:i + 700])[:1400])
print()
print('rows_with_context=%d' % ed.count('&quot;context&quot;:&quot;open-product-fields-14&quot;'))
print('es_translation_rows=%d' % ed.count('&quot;language&quot;:&quot;es&quot;'))
PY

echo "== done =="
