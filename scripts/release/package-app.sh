#!/usr/bin/env bash
#
# Package the installable / App Store tarball: dist/pages-<version>.tar.gz with top-level `pages/`.
#
#   ./scripts/release/package-app.sh              # use the existing artifacts
#   ./scripts/release/package-app.sh --build      # run the frontend build first (the release path)
#
# Fails unless PHP syntax, artifacts, the info.xml id/version and "no personal traces" all hold.
set -euo pipefail

APP_ID=pages
# this script lives in scripts/release/, so the app root is two levels up
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DIST="$SRC/dist"
BUILD=0
for arg in "$@"; do
	case "$arg" in
		--build) BUILD=1 ;;
	esac
done

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

VERSION=$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$SRC/appinfo/info.xml" | head -1)
[[ -n "$VERSION" ]] || die "cannot read <version> from appinfo/info.xml"

if [[ "$BUILD" -eq 1 ]]; then
	say "building frontend artifacts"
	( cd "$SRC" && npm run build )
fi

say "pre-release checks"

# frontend artifacts must be present (the store does not build for us)
for artifact in js/pages-main.js css/pages-main.css js/pages-admin.js css/pages-admin.css; do
	[[ -f "$SRC/$artifact" ]] || die "missing build artifact $artifact - run npm run build first (or pass --build)"
done
echo "  ✓ frontend artifacts present"

# PHP syntax: php -l when php exists, warn and skip otherwise
php_files=$(find "$SRC/lib" "$SRC/templates" "$SRC/appinfo" -name '*.php')
if command -v php >/dev/null 2>&1; then
	for f in $php_files; do php -l "$f" >/dev/null || die "PHP syntax error: $f"; done
	echo "  ✓ PHP syntax OK ($(echo "$php_files" | wc -l) files)"
else
	warn "no php available, skipping php -l"
fi

# info.xml shape required by the App Store
for tag in '<id>pages</id>' '<namespace>Pages</namespace>' '<licence>agpl</licence>' '<version>'; do
	grep -q -- "$tag" "$SRC/appinfo/info.xml" || die "appinfo/info.xml is missing $tag"
done
echo "  ✓ info.xml shape OK (id/namespace/licence/version)"

# release gate: no hardcoded user name or private path may ship in the package
# the scan is generic: the info.xml author name, plus a few private path shapes
AUTHOR=$(sed -n 's:.*<author[^>]*>\([^<]*\)</author>.*:\1:p' "$SRC/appinfo/info.xml" | head -1)
SCAN_DIRS=("$SRC/lib" "$SRC/templates" "$SRC/appinfo" "$SRC/bridge/pages_bridge")
PERSONAL_FILES=()

if [[ -n "$AUTHOR" ]]; then
	# the author name in code is a trace (in info.xml / README it is legitimate attribution)
	while IFS= read -r hit; do
		[[ -n "$hit" ]] && PERSONAL_FILES+=("author name '$AUTHOR': $hit")
	done < <(grep -rIl --include='*.php' --include='*.py' --include='*.js' --include='*.vue' \
		-F "$AUTHOR" "${SCAN_DIRS[@]}" 2>/dev/null || true)
fi

# private / local-machine paths
while IFS= read -r hit; do
	[[ -n "$hit" ]] && PERSONAL_FILES+=("local path: $hit")
done < <(grep -rIl -E '/mnt/[a-z]/|/root/site/|/home/[a-z]+/' "${SCAN_DIRS[@]}" 2>/dev/null || true)

if [[ ${#PERSONAL_FILES[@]} -gt 0 ]]; then
	die "package contains personal environment traces, clean them before releasing:
$(printf '    %s\n' "${PERSONAL_FILES[@]}")"
fi
echo "  ✓ no personal environment traces (author name '${AUTHOR:-undeclared}' absent from code; no local paths)"

say "packaging pages-$VERSION.tar.gz"
rm -rf "$DIST"
mkdir -p "$DIST/stage/$APP_ID"

# rsync --exclude states what stays out, easier to read and maintain than tar --exclude
rsync -a \
	--exclude 'node_modules/' \
	--exclude 'src/' \
	--exclude 'scripts/' \
	--exclude 'tests/' \
	--exclude 'dist/' \
	--exclude '.git/' \
	--exclude '.github/' \
	--exclude '.gitignore' \
	--exclude '.deploy-assets.sha' \
	--exclude 'package.json' \
	--exclude 'package-lock.json' \
	--exclude 'vite.config.js' \
	--exclude '.test-tmp/' \
	--exclude '*.tgz' \
	--exclude '__pycache__/' \
	--exclude '*.pyc' \
	"$SRC/" "$DIST/stage/$APP_ID/"

# the store requires a LICENSE: fall back to a pointer notice while the full AGPL text is missing
if [[ ! -f "$SRC/LICENSE" ]]; then
	warn "no LICENSE in the app directory - shipping a pointer notice instead. Add the full AGPL-3.0 text before a real release:"
	warn "  curl -sSL -o LICENSE https://www.gnu.org/licenses/agpl-3.0.txt"
	cat > "$DIST/stage/$APP_ID/LICENSE" <<'EOF'
Pages — edit, build and publish static sites from Nextcloud
Copyright (C) 2026 the Pages authors

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU Affero General Public License as published by the Free
Software Foundation, either version 3 of the License, or (at your option) any
later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE.  See the GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License along
with this program.  If not, see <https://www.gnu.org/licenses/>.

The full license text is available at <https://www.gnu.org/licenses/agpl-3.0.txt>.
EOF
fi

tar -czf "$DIST/$APP_ID-$VERSION.tar.gz" -C "$DIST/stage" "$APP_ID"
rm -rf "$DIST/stage"

SIZE=$(du -h "$DIST/$APP_ID-$VERSION.tar.gz" | cut -f1)
say "done: dist/$APP_ID-$VERSION.tar.gz ($SIZE)"
echo
echo "  top-level structure:"
tar -tzf "$DIST/$APP_ID-$VERSION.tar.gz" | awk -F/ '{print $1"/"$2}' | sort -u | head -20 | sed 's/^/    /'
echo
echo "  installing into Nextcloud:"
echo "    occ app:install --allow-unstable $APP_ID   # from the App Store"
echo "    or unpack into <nextcloud>/apps/ then: occ app:enable $APP_ID"
