#!/usr/bin/env bash
#
# Bump the plugin version in all three places the release workflow checks:
#   - bitcoin-payments-for-fluentcart.php  plugin header "Version:"
#   - bitcoin-payments-for-fluentcart.php  BTCPAY_FCT_VERSION constant
#   - readme.txt                           "Stable tag:"
#   - SECURITY.md                          "Supported versions" table rows
#
# Usage: bin/bump-version.sh 1.0.1 [--tag]
#
#   --tag   also commit the bump and create the v<version> tag. Nothing is
#           pushed - the script prints the push command for you to run.
#
set -euo pipefail

cd "$(dirname "$0")/.."

NEW_VERSION=""
DO_TAG=0

for arg in "$@"; do
    case "$arg" in
        --tag) DO_TAG=1 ;;
        -*)
            echo "Error: unknown option '$arg'" >&2
            exit 1
            ;;
        *)
            if [ -n "$NEW_VERSION" ]; then
                echo "Error: unexpected argument '$arg'" >&2
                exit 1
            fi
            NEW_VERSION="$arg"
            ;;
    esac
done

usage() {
    echo "Usage: bin/bump-version.sh <version> [--tag]   e.g. bin/bump-version.sh 1.0.1 --tag" >&2
}

if [ -z "$NEW_VERSION" ]; then
    usage
    exit 1
fi

if ! printf '%s' "$NEW_VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "Error: version must look like 1.0.1 (got '$NEW_VERSION')" >&2
    exit 1
fi

PLUGIN_FILE="bitcoin-payments-for-fluentcart.php"
README_FILE="readme.txt"
SECURITY_FILE="SECURITY.md"
TAG="v${NEW_VERSION}"

# Check everything that would make --tag fail *before* touching any file, so a
# rejected run leaves the working tree exactly as it was.
if [ "$DO_TAG" -eq 1 ]; then
    if ! git rev-parse --git-dir >/dev/null 2>&1; then
        echo "Error: --tag needs a git repository" >&2
        exit 1
    fi

    if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
        echo "Error: tag ${TAG} already exists - pick a new version" >&2
        exit 1
    fi

    # Only the version files may be dirty; anything else would silently ride
    # along in the release commit.
    DIRTY=$(git status --porcelain -- . ":(exclude)${PLUGIN_FILE}" ":(exclude)${README_FILE}" ":(exclude)${SECURITY_FILE}")
    if [ -n "$DIRTY" ]; then
        echo "Error: working tree has other changes - commit or stash them first:" >&2
        echo "$DIRTY" >&2
        exit 1
    fi
fi

OLD_VERSION=$(sed -n "s/^define('BTCPAY_FCT_VERSION', '\([0-9.]*\)').*/\1/p" "$PLUGIN_FILE")

# BSD (macOS) and GNU sed disagree about -i, so write via a temp file instead.
# Write *into* the original file rather than mv'ing the temp over it - mktemp
# creates 0600 files, and a mv would leave the plugin files unreadable to the
# web server.
replace() {
    local pattern="$1" file="$2" tmp
    tmp=$(mktemp)
    sed "$pattern" "$file" > "$tmp"
    cat "$tmp" > "$file"
    rm -f "$tmp"
}

replace "s/^\( \* Version: *\)[0-9.]*$/\1${NEW_VERSION}/" "$PLUGIN_FILE"
replace "s/^\(define('BTCPAY_FCT_VERSION', '\)[0-9.]*\(');\)/\1${NEW_VERSION}\2/" "$PLUGIN_FILE"
replace "s/^\(Stable tag: *\)[0-9.]*$/\1${NEW_VERSION}/" "$README_FILE"

# SECURITY.md's supported-versions table: the new version is the only supported
# one, everything below it is not. Pad the version column so the table stays
# aligned in the source (7 = width of the "Version" header).
pad_to() {
    local text="$1" n=$(( $2 - ${#1} ))
    printf '%s' "$text"
    while [ "$n" -gt 0 ]; do printf ' '; n=$(( n - 1 )); done
}
SUPPORTED_COL=$(pad_to "$NEW_VERSION" 7)
UNSUPPORTED_COL=$(pad_to "< $NEW_VERSION" 7)

# '#' as the sed delimiter - these patterns are full of '|' table pipes.
replace "s#^| [0-9][0-9.]* *|\( *✅.*\)#| ${SUPPORTED_COL} |\1#" "$SECURITY_FILE"
replace "s#^| < [0-9][0-9.]* *|\( *❌.*\)#| ${UNSUPPORTED_COL} |\1#" "$SECURITY_FILE"

# Verify with the same greps the release workflow uses, so a silently failed
# substitution fails here instead of in CI after the tag is pushed.
HEADER_VERSION=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9.]*\)/\1/p' "$PLUGIN_FILE")
CONST_VERSION=$(sed -n "s/^define('BTCPAY_FCT_VERSION', '\([0-9.]*\)').*/\1/p" "$PLUGIN_FILE")
STABLE_TAG=$(sed -n 's/^Stable tag:[[:space:]]*\([0-9.]*\)/\1/p' "$README_FILE")
SUPPORTED=$(sed -n 's#^| \([0-9][0-9.]*\)[[:space:]]*|[[:space:]]*✅.*#\1#p' "$SECURITY_FILE")
UNSUPPORTED=$(sed -n 's#^| < \([0-9][0-9.]*\)[[:space:]]*|[[:space:]]*❌.*#\1#p' "$SECURITY_FILE")

if [ "$HEADER_VERSION" != "$NEW_VERSION" ] || [ "$CONST_VERSION" != "$NEW_VERSION" ] || [ "$STABLE_TAG" != "$NEW_VERSION" ]; then
    echo "Error: bump failed - header='$HEADER_VERSION' const='$CONST_VERSION' stable tag='$STABLE_TAG'" >&2
    exit 1
fi

if [ "$SUPPORTED" != "$NEW_VERSION" ] || [ "$UNSUPPORTED" != "$NEW_VERSION" ]; then
    echo "Error: bump failed - $SECURITY_FILE supported='$SUPPORTED' unsupported='$UNSUPPORTED'" >&2
    exit 1
fi

echo "Bumped ${OLD_VERSION:-?} -> ${NEW_VERSION} in:"
echo "  $PLUGIN_FILE  (header + BTCPAY_FCT_VERSION)"
echo "  $README_FILE  (Stable tag)"
echo "  $SECURITY_FILE  (supported versions table)"
echo

if [ "$DO_TAG" -eq 0 ]; then
    echo "Next: review with 'git diff', commit, then:"
    echo "  git tag ${TAG} && git push origin ${TAG}"
    exit 0
fi

git add -- "$PLUGIN_FILE" "$README_FILE" "$SECURITY_FILE"

if git diff --cached --quiet; then
    echo "Error: nothing to commit - is the version already ${NEW_VERSION}?" >&2
    exit 1
fi

git commit -q -m "Bump version to ${NEW_VERSION}"
git tag -a "${TAG}" -m "BTCPay Server for FluentCart ${NEW_VERSION}"

echo "Committed the bump and created tag ${TAG} on $(git rev-parse --abbrev-ref HEAD)."
echo
echo "Nothing has been pushed. To publish the release:"
echo "  git push origin $(git rev-parse --abbrev-ref HEAD) && git push origin ${TAG}"
echo
echo "To undo:"
echo "  git tag -d ${TAG} && git reset --soft HEAD~1"
