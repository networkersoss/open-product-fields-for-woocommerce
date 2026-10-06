#!/usr/bin/env bash
# Builds a clean, deployable plugin directory (no dev artifacts) into
# /tmp/opf-dist/ — scan and deploy THIS, not the working copy.
set -eu
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${1:-/tmp/opf-dist/open-product-fields-for-woocommerce}"
rm -rf "$DIST"
mkdir -p "$(dirname "$DIST")"
rsync -a --delete \
	--exclude='.git/' \
	--exclude='.gitignore' \
	--exclude='.phpunit.result.cache' \
	--exclude='vendor/' \
	--exclude='tests/' \
	--exclude='bin/' \
	--exclude='docs/' \
	--exclude='tasks/' \
	--exclude='composer.json' \
	--exclude='phpunit.xml.dist' \
	--exclude='composer.lock' \
	--exclude='bin/build.sh' \
	"$PLUGIN_DIR"/ "$DIST"/
echo "dist built: $DIST"
