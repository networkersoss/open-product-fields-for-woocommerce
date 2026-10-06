#!/usr/bin/env bash
# Front-end runtime check: same OPF group, EN vs ES, ES labels coming from WPML packages.
set -uo pipefail
D=/tmp/opf-wpml-modern-wp/coherent-wp
BASE=http://127.0.0.1:8097
$D/tools/w.sh option get opf_proof_ids --format=json > /dev/null 2>&1
EN=$(curl -sL -o "$D/out/en-product.html" -w '%{http_code} %{url_effective}' "$BASE/product/gift-card/")
ES=$(curl -sL -o "$D/out/es-product.html" -w '%{http_code} %{url_effective}' "$BASE/es/product/tarjeta-regalo/")
echo "en=$EN"
echo "es=$ES"
python3 - "$D/out/en-product.html" "$D/out/es-product.html" <<'PY'
import sys
en = open(sys.argv[1], encoding='utf-8', errors='replace').read()
es = open(sys.argv[2], encoding='utf-8', errors='replace').read()
fails = []
def check(name, cond, note=''):
    print(('PASS ' if cond else 'FAIL ') + name + ((' -- ' + note) if note else ''))
    if not cond:
        fails.append(name)
print('en_bytes=%d es_bytes=%d' % (len(en), len(es)))
check('R1 EN product page renders the OPF group', 'data-opf-group' in en)
check('R2 EN label is the source string', 'Gift wrap' in en)
check('R3 EN choice label is the source string', '>Red<' in en or 'Red' in en)
check('R4 ES product page is served', len(es) > 5000)
check('R5 ES page is the Spanish product', 'Tarjeta Regalo' in es)
check('R6 ES page renders the OPF group (language-specific targeting resolved)', 'data-opf-group' in es)
check('R7 ES field label comes from the WPML package translation', 'Envoltorio de regalo' in es)
check('R8 ES choice label comes from the WPML package translation', 'Rojo' in es and 'Azul' in es)
check('R9 ES description translated', 'Elige el envoltorio' in es)
# The field placeholder is not rendered for selects in either language: both
# pages print WooCommerce's own empty option (EN "Choose an option").
check('R9b select empty option follows the request language', 'Choose an option' in en and 'Elige una opción' in es)
check('R10 ES VISUAL content translated', '<b>Informaci\u00f3n</b>' in es)
check('R11 ES repeat labels translated', 'A\u00f1adir otro' in es and 'Quitar' in es)
check('R12 ES page does not leak the English label', 'Gift wrap' not in es)
check('R13 EN page does not leak the Spanish label', 'Envoltorio de regalo' not in en)
check('R14 field ids and choice slugs survive translation', 'data-opf-field="gift"' in es and 'value="red"' in es and 'name="opf[12][gift]"' in es)
check('R15 EN page keeps the EN product context', 'Gift Card' in en)
print('FAILURES=' + str(len(fails)))
PY
