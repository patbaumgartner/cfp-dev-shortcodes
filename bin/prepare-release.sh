#!/usr/bin/env bash
#
# Prepares a release: bumps every place the version lives, verifies the
# gates the release workflow will verify, and prints the steps that remain.
#
# Usage: bin/prepare-release.sh 4.8.0   (or: composer prepare-release 4.8.0)
#
# The version lives in four files; the release workflow checks the first
# three against the tag, and the test suite ties the badge and the .pot
# header to the constant:
#
#   1. cfp-dev-wordpress-shortcodes.php   * Version: header
#   2. cfp-dev-wordpress-shortcodes.php   CFP_DEV_VERSION constant
#   3. CHANGELOG.md                       ## [X.Y.Z] entry (written by hand)
#   4. README.md                          stable-X.Y.Z badge
#   5. languages/*.pot                    Project-Id-Version header

set -euo pipefail

fail() {
	echo "ERROR: $1" >&2
	exit 1
}

VERSION="${1:-}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "usage: bin/prepare-release.sh X.Y.Z (got '${VERSION}')"

cd "$(git rev-parse --show-toplevel)"

PLUGIN_FILE="cfp-dev-wordpress-shortcodes.php"
POT_FILE="languages/cfp-dev-shortcodes.pot"

# ── Preflight ────────────────────────────────────────────────────────────
[[ "$(git symbolic-ref --short HEAD)" == "main" ]] || fail "releases are tagged from main; you are on $(git symbolic-ref --short HEAD)"
[[ -z "$(git status --porcelain)" ]] || fail "working tree is not clean; commit or stash first"

git fetch origin main --quiet
[[ -z "$(git rev-list HEAD..origin/main)" ]] || fail "main is behind origin/main; pull first"

CURRENT=$(grep -oP "define\(\s*'CFP_DEV_VERSION',\s*'\K[^']+" "$PLUGIN_FILE")
[[ "$CURRENT" != "$VERSION" ]] || fail "version is already $VERSION"

# The changelog entry is prose only its author can write, and the release
# workflow refuses a tag without it — so refuse here, before touching files.
# It must also be the newest entry (headings are newest-first) and dated.
grep -q "^## \[$VERSION\]" CHANGELOG.md \
	|| fail "CHANGELOG.md has no '## [$VERSION]' entry — write it first, then rerun"

TOP_ENTRY=$(grep -m1 "^## \[" CHANGELOG.md)
[[ "$TOP_ENTRY" == "## [$VERSION]"* ]] \
	|| fail "CHANGELOG.md's newest entry is '$TOP_ENTRY' — the $VERSION entry must be first"

[[ "$TOP_ENTRY" =~ ^"## [$VERSION] — "[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] \
	|| fail "CHANGELOG.md entry must be dated: '## [$VERSION] — YYYY-MM-DD' (got '$TOP_ENTRY')"

TODAY=$(date +%F)
[[ "$TOP_ENTRY" == *"$TODAY" ]] \
	|| echo "WARNING: CHANGELOG.md entry is dated '${TOP_ENTRY##*— }', today is $TODAY"

echo "Preparing release: $CURRENT -> $VERSION"

# ── Version bumps ────────────────────────────────────────────────────────
sed -i "s/^\( \* Version:\s*\)$CURRENT\$/\1$VERSION/" "$PLUGIN_FILE"
sed -i "s/\(define( 'CFP_DEV_VERSION', '\)$CURRENT\(' )\)/\1$VERSION\2/" "$PLUGIN_FILE"
sed -i "s/badge\/stable-[0-9.]*-/badge\/stable-$VERSION-/" README.md
sed -i "s/\(Project-Id-Version: CFP.DEV shortcodes \)[0-9.]*/\1$VERSION/" "$POT_FILE"

for f in "$PLUGIN_FILE" README.md "$POT_FILE"; do
	git diff --quiet -- "$f" && fail "no change was made to $f — pattern drift, fix this script"
done
echo "Updated: $PLUGIN_FILE (header + constant), README.md (badge), $POT_FILE (Project-Id-Version)"

# Translatable strings may have changed since the .pot was last generated;
# the test suite fails on drift, so regenerate when WP-CLI is available.
if command -v wp > /dev/null 2>&1; then
	composer i18n --quiet
	echo "Regenerated: $POT_FILE (composer i18n)"
else
	echo "NOTE: WP-CLI not found — skipped 'composer i18n'; the test suite will fail if strings drifted"
fi

# ── Verify: the same gates CI and the release workflow run ───────────────
composer check

echo
echo "Release $VERSION prepared. Remaining steps:"
echo "  1. Review:  git diff"
echo "  2. Commit:  git commit -am 'release: $VERSION'"
echo "  3. Push:    git push origin main"
echo "  4. Wait for the Tests and PHP Lint workflows to pass on that commit"
echo "  5. Tag:     git tag -m '$VERSION' v$VERSION && git push origin v$VERSION"
echo "     -> the Release workflow verifies the tag and publishes the ZIP"
