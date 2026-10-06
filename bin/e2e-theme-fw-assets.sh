#!/usr/bin/env bash
#
# Static integration evidence for the OPF vs WAPF-Extended theme runs
# (ledger rows WAPF-COMPAT-FLATSOME / WAPF-COMPAT-WOODMART).
#
# Records, for one disposable clone with exactly one engine active:
#   - whether that engine's frontend script is served on the shop archive and on
#     the single product page (the quick view is AJAX-injected into the archive,
#     so an engine with no script there cannot initialize modal fields)
#   - the theme adapter / gallery hooks the active engine prints in the footer
#   - the gallery markup the theme renders
#
# Usage:
#   bin/e2e-theme-fw-assets.sh <base-url> <theme> <engine> <product-url>
#
# Example:
#   bin/e2e-theme-fw-assets.sh http://127.0.0.1:8420 flatsome wapf \
#     http://127.0.0.1:8420/product/wapf-theme-fields/
set -euo pipefail

base_url="${1:?base url required}"
theme="${2:?theme required}"
engine="${3:?engine required}"
product_url="${4:?product url required}"

art_dir="docs/compatibility/themes-fw-20261006"
mkdir -p "$art_dir"
out="$art_dir/assets-${theme}-${engine}.txt"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

curl -sS "$base_url/shop/" -o "$tmp/shop.html"
curl -sS "$product_url" -o "$tmp/product.html"

{
	echo "# Static integration evidence — theme=${theme} engine=${engine}"
	echo "# captured: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
	echo "# base_url: ${base_url}"
	echo "# product_url: ${product_url}"
	echo
	echo "## engine frontend script served"
	echo "opf-frontend on /shop/          : $(grep -c 'opf-frontend' "$tmp/shop.html" || true)"
	echo "opf-frontend on product page    : $(grep -c 'opf-frontend' "$tmp/product.html" || true)"
	echo "wapf-frontend on /shop/         : $(grep -c 'wapf-frontend' "$tmp/shop.html" || true)"
	echo "wapf-frontend on product page   : $(grep -c 'wapf-frontend' "$tmp/product.html" || true)"
	echo "OPF_IMAGE_RULES inline global   : $(grep -c 'OPF_IMAGE_RULES' "$tmp/product.html" || true)"
	echo "data-opf-gi payload on product  : $(grep -c 'data-opf-gi' "$tmp/product.html" || true)"
	echo
	echo "## theme adapter / gallery hooks printed in the footer"
	echo "-- WAPF theme adapter (class-flatsome.php / class-woodmart.php) --"
	( grep -o "new WAPF.Frontend([^;]*)" "$tmp/shop.html" || echo "(none)" ) | head -2
	( grep -o "mfpOpen" "$tmp/shop.html" || true ) | head -1
	( grep -o "woodmart-quick-view-displayed" "$tmp/shop.html" || true ) | head -1
	( grep -o "wapf/image_changed" "$tmp/shop.html" || true ) | head -1
	( grep -o "lcp/auto_scroll_to" "$tmp/shop.html" || true ) | head -1
	( grep -o "wapf/layers/product_image_classes" "$tmp/shop.html" || true ) | head -1
	echo "-- OPF equivalent hooks --"
	( grep -o "opf:variation-changed" "$tmp/product.html" || echo "(none printed; dispatched by the module)" ) | head -1
	( grep -o "mfpOpen" "$tmp/product.html" || true ) | head -1
	echo
	echo "## theme gallery markup (single product page)"
	( grep -o 'class="[^"]*woocommerce-product-gallery[^"]*"' "$tmp/product.html" || true ) | sort -u | head -5
	( grep -o 'class="[^"]*\(flex-control-nav\|wd-carousel\|flickity\|swiper\)[^"]*"' "$tmp/product.html" || true ) | sort -u | head -5
	echo
	echo "## quick view markup markers on the archive"
	( grep -o "quick-view[^\"]*" "$tmp/shop.html" || true ) | sort -u | head -5
} > "$out"

echo "wrote $out"
