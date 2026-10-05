#!/usr/bin/env bash
# Disposable runtime proof for the OPF <-> Polylang row (WAPF-LOCALE-POLYLANG).
#
# Copies the real polylang-pro / polylang-wc plugins (read-only; the production
# install is never modified) into a throwaway /tmp WordPress clone, activates
# them THERE, and runs bin/e2e-polylang-proof.php through `wp eval-file`.
# All artifacts are staged under docs/compatibility/locale-proof-20261005/ so
# the evidence is reproducible from the repository instead of living in /tmp.
#
# Guarded: requires OPF_LOCALE_PROOF_ALLOW=1 and an OPF_LOCALE_PROOF_CLONE path
# under /tmp/opf-image-locale-proof*.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLONE="${OPF_LOCALE_PROOF_CLONE:-/tmp/opf-image-locale-proof}"
PORT="${OPF_LOCALE_PROOF_PORT:-8321}"
SOURCE_WP="${OPF_LOCALE_PROOF_SOURCE_WP:-/home/followersya-5hqi7/opf-test/wordpress}"
EXTENDED="${OPF_LOCALE_PROOF_EXTENDED:-/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins/advanced-product-fields-for-woocommerce-extended}"
PROD_PLUGINS="${OPF_LOCALE_PROOF_PRODUCTION_PLUGINS:-/home/followersya-5hqi7/followersya.com/bedrock/web/app/plugins}"
ARTIFACTS="$ROOT/docs/compatibility/locale-proof-20261005"
LOG="$ARTIFACTS/command-log.txt"

[[ "${OPF_LOCALE_PROOF_ALLOW:-}" == 1 ]] || { echo "Set OPF_LOCALE_PROOF_ALLOW=1 to run the disposable Polylang proof" >&2; exit 2; }
[[ "$CLONE" == /tmp/opf-image-locale-proof* ]] || { echo "Refusing clone path outside /tmp/opf-image-locale-proof*: $CLONE" >&2; exit 2; }
for dir in "$SOURCE_WP" "$EXTENDED" "$PROD_PLUGINS" "$PROD_PLUGINS/polylang-pro" "$PROD_PLUGINS/polylang-wc"; do
	[[ -d "$dir" ]] || { echo "Missing required directory: $dir" >&2; exit 2; }
done

mkdir -p "$ARTIFACTS"
exec > >(tee "$LOG") 2>&1

cleanup() {
	status=$?
	trap - EXIT INT TERM
	echo "--- cleanup ---"
	if [[ -d "$CLONE" ]]; then
		wp --path="$CLONE" plugin deactivate polylang-pro polylang-wc >/dev/null 2>&1 || true
		echo "deactivated polylang-pro / polylang-wc in clone"
	fi
	if [[ "$CLONE" == /tmp/opf-image-locale-proof* && -d "$CLONE" ]]; then
		rm -rf "$CLONE"
		echo "removed disposable clone $CLONE"
	fi
	echo "production plugins untouched: $PROD_PLUGINS (read-only copy source)"
	exit "$status"
}
trap cleanup EXIT INT TERM

echo "--- environment ---"
echo "date (UTC): $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "php: $(php -r 'echo PHP_VERSION;')"
echo "wp-cli: $(wp --version | head -1)"
echo "repository: $ROOT"
echo "clone: $CLONE (port $PORT)"
echo "source WordPress: $SOURCE_WP"
echo "Polylang source: $PROD_PLUGINS"

echo "--- build disposable clone ---"
rm -rf "$CLONE"
python3 "$ROOT/bin/clone-content-image-proof.py" \
	--wordpress "$SOURCE_WP" \
	--extended "$EXTENDED" \
	--opf "$ROOT" \
	--destination "$CLONE" \
	--port "$PORT"

echo "--- copy real Polylang (production install untouched) ---"
cp -a "$PROD_PLUGINS/polylang-pro" "$CLONE/wp-content/plugins/polylang-pro"
cp -a "$PROD_PLUGINS/polylang-wc" "$CLONE/wp-content/plugins/polylang-wc"

echo "--- activate Polylang inside the clone ---"
wp --path="$CLONE" plugin activate polylang-pro polylang-wc
echo "polylang-pro version: $(wp --path="$CLONE" plugin get polylang-pro --field=version)"
echo "polylang-wc version: $(wp --path="$CLONE" plugin get polylang-wc --field=version)"

echo "--- ensure Polylang languages exist before the recorded run ---"
# Polylang wires its translated-query filter only when languages exist at boot;
# this setup-only invocation establishes them so the proof run is deterministic.
# Single-line `env` form: an env-prefixed command split across a line
# continuation can be mis-parsed as one command word.
env OPF_LOCALE_PROOF_ALLOW=1 OPF_LOCALE_PROOF_SETUP_ONLY=1 wp --path="$CLONE" eval-file "$ROOT/bin/e2e-polylang-proof.php"

echo "--- run proof ---"
env OPF_LOCALE_PROOF_ALLOW=1 OPF_LOCALE_PROOF_ARTIFACT_DIR="$ARTIFACTS" wp --path="$CLONE" eval-file "$ROOT/bin/e2e-polylang-proof.php"
