#!/usr/bin/env bash
# Front-end runtime check on the WPML 5.1.0 stack: EN vs ES, translated package labels.
set -uo pipefail
D=/tmp/opf-wpml-modern-wp/stack510
BASE=http://127.0.0.1:8096
IDS=$($D/tools/w.sh option get opf_proof_ids --format=json)
GID=$(printf '%s' "$IDS" | python3 -c 'import json,sys; print(json.load(sys.stdin)["group_id"])')
echo "ids=$IDS"
echo "group_id=$GID"
EN=$(curl -sL -o "$D/out/en-product.html" -w '%{http_code} %{url_effective}' "$BASE/product/gift-card/")
ES=$(curl -sL -o "$D/out/es-product.html" -w '%{http_code} %{url_effective}' "$BASE/es/product/tarjeta-regalo/")
ENB=$(curl -sL -o "$D/out/en-other.html" -w '%{http_code} %{url_effective}' "$BASE/product/plain-mug/")
ESB=$(curl -sL -o "$D/out/es-other.html" -w '%{http_code} %{url_effective}' "$BASE/es/product/taza-lisa/")
echo "en=$EN"
echo "es=$ES"
echo "en_other=$ENB"
echo "es_other=$ESB"
python3 - "$GID" "$D/out/en-product.html" "$D/out/es-product.html" "$D/out/en-other.html" "$D/out/es-other.html" <<'PY'
import sys
gid = sys.argv[1]
en = open(sys.argv[2], encoding='utf-8', errors='replace').read()
es = open(sys.argv[3], encoding='utf-8', errors='replace').read()
enb = open(sys.argv[4], encoding='utf-8', errors='replace').read()
esb = open(sys.argv[5], encoding='utf-8', errors='replace').read()
fails = []
def check(name, cond, note=''):
    print(('PASS ' if cond else 'FAIL ') + name + ((' -- ' + note) if note else ''))
    if not cond:
        fails.append(name)
print('bytes en=%d es=%d en_other=%d es_other=%d' % (len(en), len(es), len(enb), len(esb)))
check('R1  EN product page renders the OPF group', 'data-opf-group' in en)
check('R2  EN field label is the source string', '<span>Gift wrap</span>' in en)
check('R3  EN choice labels are the source strings', 'data-opf-base-label="Red"' in en and 'data-opf-base-label="Blue"' in en)
check('R4  ES product page is served', len(es) > 5000)
check('R5  ES page is the Spanish product', 'Tarjeta Regalo' in es)
check('R6  ES page renders the OPF group (language-specific targeting resolved)', 'data-opf-group' in es)
check('R7  ES field label is the WPML package translation', '<span>Envoltorio de regalo</span>' in es)
check('R8  ES choice labels are the package translations', 'data-opf-base-label="Rojo"' in es and 'data-opf-base-label="Azul"' in es)
check('R9  ES description translated', 'Elige el envoltorio' in es)
check('R9b select empty option follows the request language', 'Choose an option' in en and 'Elige una opci\u00f3n' in es)
check('R10 ES VISUAL content translated', '<b>Informaci\u00f3n</b>' in es)
check('R11 ES repeat labels translated', 'A\u00f1adir otro' in es and 'Quitar' in es)
check('R12 ES page does not leak the English field label', 'Gift wrap' not in es)
check('R13 EN page does not leak the Spanish field label', 'Envoltorio de regalo' not in en)
check('R14 field ids and choice slugs survive translation',
      ('id="opf-%s-gift"' % gid) in es and 'value="red"' in es and 'value="blue"' in es and ('name="opf[%s][gift]"' % gid) in es)
check('R15 ES choice pricing unchanged (5.00 fixed on slug red)',
      'data-opf-choice-hint="red" data-opf-base-label="Rojo" data-opf-pricetype="fixed" data-opf-price="5"' in es)
check('R16 EN page keeps the EN product context', 'Gift Card' in en)
check('R17 unrelated product EN page has no OPF group', 'data-opf-group' not in enb)
check('R18 unrelated product ES page has no OPF group', 'data-opf-group' not in esb)
check('R19 unrelated product ES page is the ES product', 'Taza Lisa' in esb)
check('R20 ES page shows the translated option text with the fixed price', '>Rojo + &#36;5.00<' in es)
print('FAILURES=' + str(len(fails)))
PY
