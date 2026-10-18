#!/usr/bin/env bash
#
# Package the App Store / release tarball: build/pages-<version>.tar.gz with a single top-level
# `pages/` directory, built from an explicit **whitelist** instead of "everything minus a few
# excludes". A file that is added to the repository therefore never ships by accident.
#
#   bash tools/package.sh          # package the current tree (run `npm run build` first)
#
# Whitelist: appinfo/ img/ js/ css/ l10n/ lib/ templates/ LICENSE README.md CHANGELOG.md
#
# The archive is verified before the script reports success: the front-end bundles must be inside,
# and src/, tests/, bridge/, scripts/, tools/, screenshots/, .github/ and every Python or node
# artifact must not be. The version comes from appinfo/info.xml, so the archive cannot disagree
# with what `occ app:list` reports.
set -euo pipefail

APP_ID=pages
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$SRC/build"
STAGE="$BUILD_DIR/stage"

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

WHITELIST=(
	appinfo
	img
	js
	css
	l10n
	lib
	templates
	LICENSE
	README.md
	CHANGELOG.md
)

# The compiled bundles are committed, but a package with a missing one is an app that shows a blank
# page, so this is checked before anything is packed rather than after.
ARTIFACTS=(js/pages-main.js js/pages-admin.js css/pages-main.css css/pages-admin.css)

cd "$SRC"

VERSION=$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' appinfo/info.xml | head -1)
[[ -n "$VERSION" ]] || die "cannot read <version> from appinfo/info.xml"
OUT="$BUILD_DIR/$APP_ID-$VERSION.tar.gz"

say "packaging $APP_ID $VERSION"

for artifact in "${ARTIFACTS[@]}"; do
	[[ -f "$artifact" ]] || die "missing build artifact $artifact — run 'npm run build' first"
done
[[ -f LICENSE ]] || die "missing LICENSE (the App Store requires one)"
[[ -f appinfo/info.xml ]] || die "missing appinfo/info.xml (not an app directory?)"

rm -rf "$STAGE"
mkdir -p "$STAGE/$APP_ID"

for entry in "${WHITELIST[@]}"; do
	[[ -e "$entry" ]] || die "whitelisted entry '$entry' does not exist — has it been renamed?"
	cp -a "$entry" "$STAGE/$APP_ID/"
done

echo "  staged: ${WHITELIST[*]}"

tar -czf "$OUT" -C "$STAGE" "$APP_ID"
rm -rf "$STAGE"

# ------------------------------------------------------------------ verification
# Reading the archive back is the point: it proves what the *package* holds, not what the staging
# step intended to copy.
say "verifying $(basename "$OUT")"
LIST=$(tar -tzf "$OUT")
grep -qx "$APP_ID/js/pages-main.js" <<<"$LIST" || die "the archive does not contain $APP_ID/js/pages-main.js"

FORBIDDEN=(
	'src/'
	'tests/'
	'bridge/'
	'scripts/'
	'tools/'
	'screenshots/'
	'.github/'
	'node_modules/'
	'__pycache__/'
)
for dir in "${FORBIDDEN[@]}"; do
	if grep -q "^$APP_ID/$dir" <<<"$LIST"; then
		die "the archive contains $APP_ID/$dir — it must not ship (check the whitelist)"
	fi
done
if grep -Eq "^$APP_ID/(package(-lock)?\.json|vite\.config\.js|\.gitignore|\.nextcloudignore)$" <<<"$LIST"; then
	die "the archive contains a development-only root file (package.json / vite.config.js / ignore file)"
fi
if grep -Eq '\.(py|pyc|pyo|map)$' <<<"$LIST"; then
	die "the archive contains Python sources, byte code or a source map"
fi
if grep -Eq '(^|/)\.git/' <<<"$LIST"; then
	die "the archive contains git metadata"
fi

echo "  ✓ no src/, tests/, bridge/, scripts/, tools/, screenshots/, .github/, node_modules/"
echo "  ✓ no Python sources or byte code, no source maps, no git metadata"
echo "  ✓ js/pages-main.js present"

if command -v du >/dev/null 2>&1; then
	say "done: build/$APP_ID-$VERSION.tar.gz ($(du -h "$OUT" | cut -f1))"
else
	say "done: build/$APP_ID-$VERSION.tar.gz"
fi
echo
echo "  top-level content:"
tar -tzf "$OUT" | awk -F/ 'NF > 1 {print "    " $1 "/" $2}' | sort -u | head -20
echo
echo "  installing:"
echo "    tar -xzf build/$APP_ID-$VERSION.tar.gz -C <nextcloud>/apps/"
echo "    occ app:enable $APP_ID"
