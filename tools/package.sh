#!/usr/bin/env bash
#
# Package the App Store / release tarball: build/pages-<version>.tar.gz with a single top-level
# `pages/` directory, built from an explicit **whitelist** instead of "everything minus a few
# excludes". A file that is added to the repository therefore never ships by accident.
#
#   bash tools/package.sh          # package the current tree (run `npm run build` first)
#
# Whitelist: appinfo/ img/ js/ css/ l10n/ lib/ templates/ bridge/ LICENSE README.md CHANGELOG.md
#
# The archive is verified before the script reports success: the front-end bundles must be inside,
# and src/, tests/, tools/, screenshots/, .github/, Python byte-code and every node
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
    bridge
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

# ---------------------------------------------------------------- release gates
say "pre-release checks"

# PHP syntax: php -l when php exists, warn and skip otherwise
php_files=$(find lib templates appinfo -name '*.php')
if command -v php >/dev/null 2>&1; then
	for f in $php_files; do php -l "$f" >/dev/null || die "PHP syntax error: $f"; done
	echo "  ✓ PHP syntax OK ($(echo "$php_files" | wc -l) files)"
else
	warn "no php available, skipping php -l"
fi

# info.xml shape required by the App Store
for tag in '<id>pages</id>' '<namespace>Pages</namespace>' '<licence>agpl</licence>' '<version>'; do
	grep -q -- "$tag" appinfo/info.xml || die "appinfo/info.xml is missing $tag"
done
echo "  ✓ info.xml shape OK (id/namespace/licence/version)"

# release gate: no hardcoded user name or private path may ship in the package
# the scan is generic: the info.xml author name in code, retired personal accounts in every text
# file, plus a few private path shapes. bridge/ is scanned as a whole -- the .service unit ships
# to administrators and an account link in it leaks just as well as one in a .php file.
AUTHOR=$(sed -n 's:.*<author[^>]*>\([^<]*\)</author>.*:\1:p' appinfo/info.xml | head -1)
# accounts that belong to a person, not to the project: they must never reappear in a shipped file
RETIRED_ACCOUNTS=(hwshqtb)
SCAN_DIRS=(lib templates appinfo bridge)
PERSONAL_FILES=()

if [[ -n "$AUTHOR" ]]; then
	# the author name in code is a trace (in info.xml / README / the project's own URLs it is
	# legitimate attribution, which is why this scan stays on source files, not every text file)
	while IFS= read -r hit; do
		[[ -n "$hit" ]] && PERSONAL_FILES+=("author name '$AUTHOR': $hit")
	done < <(grep -rIl --include='*.php' --include='*.py' --include='*.js' --include='*.vue' \
		-F "$AUTHOR" "${SCAN_DIRS[@]}" 2>/dev/null || true)
fi

# retired personal accounts: checked in every text file whatever its extension, so the systemd
# unit and the .toml.example are covered too. -I skips binaries, so byte-code cannot match.
for account in "${RETIRED_ACCOUNTS[@]}"; do
	while IFS= read -r hit; do
		[[ -n "$hit" ]] && PERSONAL_FILES+=("retired account '$account': $hit")
	done < <(grep -rIl -F "$account" "${SCAN_DIRS[@]}" 2>/dev/null || true)
done

# private / local-machine paths
while IFS= read -r hit; do
	[[ -n "$hit" ]] && PERSONAL_FILES+=("local path: $hit")
done < <(grep -rIl -E '/mnt/[a-z]/|/root/site/|/home/[a-z]+/' "${SCAN_DIRS[@]}" 2>/dev/null || true)

if [[ ${#PERSONAL_FILES[@]} -gt 0 ]]; then
	die "package contains personal environment traces, clean them before releasing:
$(printf '    %s\n' "${PERSONAL_FILES[@]}")"
fi
echo "  ✓ no personal environment traces (author name '${AUTHOR:-undeclared}' absent from code; no retired accounts; no local paths)"

rm -rf "$STAGE"
mkdir -p "$STAGE/$APP_ID"

for entry in "${WHITELIST[@]}"; do
	[[ -e "$entry" ]] || die "whitelisted entry '$entry' does not exist — has it been renamed?"
	cp -a "$entry" "$STAGE/$APP_ID/"
done

echo "  staged: ${WHITELIST[*]}"

# the bridge ships, its build artefacts do not
find "$STAGE" -name __pycache__ -type d -prune -exec rm -rf {} + 2>/dev/null || true
find "$STAGE" -name "*.pyc" -delete 2>/dev/null || true
rm -rf "$STAGE/$APP_ID/bridge/.test-tmp"
tar -czf "$OUT" -C "$STAGE" "$APP_ID"
rm -rf "$STAGE"

# ------------------------------------------------------------------ verification
# Reading the archive back is the point: it proves what the *package* holds, not what the staging
# step intended to copy.
say "verifying $(basename "$OUT")"
LIST=$(tar -tzf "$OUT")
grep -qx "$APP_ID/js/pages-main.js" <<<"$LIST" || die "the archive does not contain $APP_ID/js/pages-main.js"

FORBIDDEN=(
    '*.pyc'
	'src/'
	'tests/'
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
if grep -qE '__pycache__|\.pyc$|\.map$' <<<"$LIST"; then
	die "the archive contains Python byte-code or a source map"
fi
if grep -Eq '(^|/)\.git/' <<<"$LIST"; then
	die "the archive contains git metadata"
fi

echo "  ✓ no src/, tests/, tools/, screenshots/, .github/, node_modules/; bridge ships without byte-code"
echo "  ✓ no Python byte-code or test artefacts, no source maps, no git metadata"
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
