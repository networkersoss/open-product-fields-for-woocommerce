#!/usr/bin/env bash
#
# Drives the full OPF vs WAPF-Extended 3.1.5 theme comparison for one disposable
# clone: prepares the fixture with both engines available, then runs the live
# Chromium harness once per engine with the other engine deactivated, and
# captures the static integration evidence for each engine.
#
# Usage:
#   bin/e2e-theme-fw-run.sh <clone-path> <base-url> <theme-key>
#
# Example:
#   bin/e2e-theme-fw-run.sh /tmp/opf-theme-flatsome-wp http://127.0.0.1:8420 flatsome
#
# Prerequisites: the clone serves on <base-url> (PHP_CLI_SERVER_WORKERS=10 php -S
# <host:port> router.php) and the theme is active.
set -euo pipefail

clone="${1:?clone path required}"
base_url="${2:?base url required}"
theme="${3:?theme key required}"

plugin_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
art_dir="$plugin_dir/docs/compatibility/themes-fw-20261006"
export NODE_PATH="${NODE_PATH:-/home/followersya-5hqi7/followersya.com/node_modules}"

wp() { command wp --path="$clone" "$@"; }

echo "== prepare fixture (both engines available) =="
wp plugin activate advanced-product-fields-for-woocommerce-extended >/dev/null
wp plugin activate open-product-fields-for-woocommerce >/dev/null
( cd "$plugin_dir" && wp eval-file bin/e2e-theme-fw-fixture.php prepare >/dev/null )

for engine in wapf opf; do
	echo "== ${theme} / ${engine} =="
	if [ "$engine" = "wapf" ]; then
		wp plugin deactivate open-product-fields-for-woocommerce >/dev/null
		wp plugin activate advanced-product-fields-for-woocommerce-extended >/dev/null
		product_url="${base_url}/product/wapf-theme-fields/"
	else
		wp plugin deactivate advanced-product-fields-for-woocommerce-extended >/dev/null
		wp plugin activate open-product-fields-for-woocommerce >/dev/null
		product_url="${base_url}/product/opf-theme-fields/"
	fi
	wp plugin list --fields=name,status | grep -E "open-product-fields|advanced-product-fields"
	( cd "$plugin_dir" && OPF_TFW_STATE="docs/compatibility/themes-fw-20261006/fixture-state-${theme}.json" \
		OPF_TFW_ENGINE="$engine" node bin/e2e-theme-fw-browser.mjs )
	( cd "$plugin_dir" && bash bin/e2e-theme-fw-assets.sh "$base_url" "$theme" "$engine" "$product_url" )
done

echo "== restore both engines active =="
wp plugin activate advanced-product-fields-for-woocommerce-extended >/dev/null
wp plugin activate open-product-fields-for-woocommerce >/dev/null
echo "artifacts in $art_dir"
