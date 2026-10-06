#!/usr/bin/env bash
# Real wp-admin HTML from the disposable clone: ST package list + package editor
# (EN source + ES translation) and the D1 guard notice check.
set -uo pipefail
D=/tmp/opf-wpml-modern-wp/stack510
BASE=http://127.0.0.1:8096
J=$D/out/admin-cookies.txt
GID=$($D/tools/w.sh option get opf_proof_ids --format=json | python3 -c 'import json,sys; print(json.load(sys.stdin)["group_id"])')
CTX="open-product-fields-${GID}"
echo "group_id=$GID context=$CTX"
rm -f "$J"
curl -s -c "$J" -o /dev/null "$BASE/wp-login.php" -w 'login_form=%{http_code}\n'
curl -s -c "$J" -b "$J" -o /dev/null -w 'login_post=%{http_code}\n' \
  --data-urlencode 'log=admin' --data-urlencode 'pwd=admin' \
  --data-urlencode 'wp-submit=Log In' --data-urlencode "redirect_to=$BASE/wp-admin/" \
  --data-urlencode 'testcookie=1' "$BASE/wp-login.php"
# WCML's activation redirect fires once on the first admin request; warm it up so
# the captured pages are the real WPML/OPF screens.
curl -sL -b "$J" -c "$J" -o /dev/null -w 'admin_warmup=%{http_code} %{url_effective}\n' "$BASE/wp-admin/"
curl -sL -b "$J" -c "$J" -o "$D/out/10-st-domain-list.html" -w 'st_domains=%{http_code} %{url_effective}\n' \
  "$BASE/wp-admin/admin.php?page=wpml-string-translation/menu/string-translation.php"
curl -sL -b "$J" -c "$J" -o "$D/out/11-st-package-editor.html" -w 'st_package_editor=%{http_code} %{url_effective}\n' \
  "$BASE/wp-admin/admin.php?page=wpml-string-translation/menu/string-translation.php&context=${CTX}&show_results=all&lang=es"
curl -sL -b "$J" -c "$J" -o "$D/out/12-admin-dashboard.html" -w 'admin_dashboard=%{http_code} %{url_effective}\n' \
  "$BASE/wp-admin/"
curl -sL -b "$J" -c "$J" -o "$D/out/13-opf-group-editor.html" -w 'opf_group_editor=%{http_code} %{url_effective}\n' \
  "$BASE/wp-admin/post.php?post=${GID}&action=edit"
echo "--- package editor evidence ---"
grep -o 'open-product-fields-14 ([0-9]*)' "$D/out/10-st-domain-list.html" | head -2 || echo 'domain selector row NOT FOUND'
python3 - "$D/out/11-st-package-editor.html" "$CTX" <<'PY'
import re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
ctx = sys.argv[2]
print('html_bytes=%d' % len(html))
for needle in [ctx, 'Gift wrap', 'Envoltorio de regalo', 'Red', 'Rojo', 'Choose wrapping', 'Elige el envoltorio']:
    print('contains %-24s %s' % (needle, needle in html))
print('has_spanish_input=%s' % bool(re.search(r'name="[^"]*"[^>]*>\s*Envoltorio', html) or 'Envoltorio de regalo' in html))
PY
echo "--- D1 guard notice check (must be absent) ---"
for f in "$D/out/12-admin-dashboard.html" "$D/out/11-st-package-editor.html" "$D/out/10-st-domain-list.html"; do
  if grep -q 'WPML String Translation is unavailable' "$f"; then
    echo "GUARD NOTICE PRESENT in $f"
  else
    echo "guard notice absent in $(basename "$f")"
  fi
done
