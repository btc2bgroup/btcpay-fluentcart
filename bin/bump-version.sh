#!/usr/bin/env bash
#
# Bump the plugin version in all three places the release workflow checks:
#   - btcpay-for-fluent-cart.php  plugin header "Version:"
#   - btcpay-for-fluent-cart.php  BTCPAY_FCT_VERSION constant
#   - readme.txt                  "Stable tag:"
#
# Usage: bin/bump-version.sh 1.0.1
#
set -euo pipefail

cd "$(dirname "$0")/.."

NEW_VERSION="${1:-}"

if [ -z "$NEW_VERSION" ]; then
    echo "Usage: bin/bump-version.sh <version>   e.g. bin/bump-version.sh 1.0.1" >&2
    exit 1
fi

if ! printf '%s' "$NEW_VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "Error: version must look like 1.0.1 (got '$NEW_VERSION')" >&2
    exit 1
fi

PLUGIN_FILE="btcpay-for-fluent-cart.php"
README_FILE="readme.txt"

OLD_VERSION=$(sed -n "s/^define('BTCPAY_FCT_VERSION', '\([0-9.]*\)').*/\1/p" "$PLUGIN_FILE")

# BSD (macOS) and GNU sed disagree about -i, so write via a temp file instead.
replace() {
    local pattern="$1" file="$2" tmp
    tmp=$(mktemp)
    sed "$pattern" "$file" > "$tmp"
    mv "$tmp" "$file"
}

replace "s/^\( \* Version: *\)[0-9.]*$/\1${NEW_VERSION}/" "$PLUGIN_FILE"
replace "s/^\(define('BTCPAY_FCT_VERSION', '\)[0-9.]*\(');\)/\1${NEW_VERSION}\2/" "$PLUGIN_FILE"
replace "s/^\(Stable tag: *\)[0-9.]*$/\1${NEW_VERSION}/" "$README_FILE"

# Verify with the same greps the release workflow uses, so a silently failed
# substitution fails here instead of in CI after the tag is pushed.
HEADER_VERSION=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9.]*\)/\1/p' "$PLUGIN_FILE")
CONST_VERSION=$(sed -n "s/^define('BTCPAY_FCT_VERSION', '\([0-9.]*\)').*/\1/p" "$PLUGIN_FILE")
STABLE_TAG=$(sed -n 's/^Stable tag:[[:space:]]*\([0-9.]*\)/\1/p' "$README_FILE")

if [ "$HEADER_VERSION" != "$NEW_VERSION" ] || [ "$CONST_VERSION" != "$NEW_VERSION" ] || [ "$STABLE_TAG" != "$NEW_VERSION" ]; then
    echo "Error: bump failed - header='$HEADER_VERSION' const='$CONST_VERSION' stable tag='$STABLE_TAG'" >&2
    exit 1
fi

echo "Bumped ${OLD_VERSION:-?} -> ${NEW_VERSION} in:"
echo "  $PLUGIN_FILE  (header + BTCPAY_FCT_VERSION)"
echo "  $README_FILE  (Stable tag)"
echo
echo "Next: review with 'git diff', commit, then:"
echo "  git tag v${NEW_VERSION} && git push origin v${NEW_VERSION}"
