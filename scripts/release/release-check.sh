#!/usr/bin/env bash
#
# Pre-release check: run every check that is reachable from this machine, then package.
#
#   bash scripts/release/release-check.sh
#
# Checks that need a live Nextcloud are skipped and listed explicitly, never faked as passing.
set -uo pipefail

# this script lives in scripts/release/, so the app root is two levels up
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SRC"

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$*"; FAIL=$((FAIL + 1)); }
skip() { printf '  \033[33m—\033[0m %s\n' "$*"; }
FAIL=0

VERSION=$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' appinfo/info.xml | head -1)
say "pre-release check (pages $VERSION)"

# ---------------------------------------------------------------- 1 frontend build
say "1. frontend build"
if command -v npm >/dev/null 2>&1; then
	if npm run build > /tmp/rc-build.log 2>&1; then
		ok "$(grep -E 'pages-main\.js|pages-admin\.js' /tmp/rc-build.log | tr -s ' ' | paste -sd' ' -)"
	else
		bad "build failed, see /tmp/rc-build.log"
		tail -20 /tmp/rc-build.log | sed 's/^/      /'
	fi
else
	# no npm here (WSL has no node): the build runs on the Windows side, so check artifact freshness
	skip "no npm in this environment - skipping the build"
	newest_src=$(find src -type f \( -name '*.js' -o -name '*.vue' -o -name '*.css' \) -newer js/pages-main.js 2>/dev/null | head -5)
	if [[ -n "$newest_src" ]]; then
		bad "artifacts may be stale: these sources are newer than js/pages-main.js -"
		printf '%s\n' "$newest_src" | sed 's/^/      /'
		printf '      run npm run build on the Windows side before releasing.\n'
	else
		ok "artifacts are not older than any source (nothing in src/ is newer than js/pages-main.js)"
	fi
fi

# ---------------------------------------------------------------- 2 dependency lock
say "2. dependency lock (package.json vs lock in sync)"
# the one failure class npm ci catches, and it needs neither node nor installed dependencies
if command -v python3 >/dev/null 2>&1; then
	if python3 "$HERE/check-lockfile.py" > /tmp/rc-lock.log 2>&1; then
		tail -1 /tmp/rc-lock.log | sed 's/^/  /'
	else
		bad "lock file out of sync with package.json - npm ci will fail for anyone who clones"
		sed 's/^/      /' /tmp/rc-lock.log | tail -12
	fi
else
	skip "no python3 - skipping the lockfile check"
fi

# ---------------------------------------------------------------- 3 bridge tests
say "3. bridge tests (mirror / delete guard / git / HTTP layer)"
if command -v python3 >/dev/null 2>&1 && command -v git >/dev/null 2>&1; then
	# run on the native filesystem: chmod/mtime do not work on drvfs
	TMPRUN=$(mktemp -d)
	cp -r bridge "$TMPRUN/bridge"
	if ( cd "$TMPRUN/bridge" && python3 tests/test_smoke.py ) > /tmp/rc-bridge.log 2>&1; then
		ok "$(grep -E 'all [0-9]+ checks passed' /tmp/rc-bridge.log | tail -1)"
	else
		bad "bridge tests failed, see /tmp/rc-bridge.log"
		grep -E '❌|failed' /tmp/rc-bridge.log | tail -10 | sed 's/^/      /'
	fi
	rm -rf "$TMPRUN"
else
	skip "python3 or git missing - skipping the bridge tests"
fi

# ---------------------------------------------------------------- 4 packaging (with pre-release checks inside)
say "4. packaging (checks artifact completeness / PHP syntax / info.xml shape / personal traces inside)"
if bash "$HERE/package-app.sh" > /tmp/rc-package.log 2>&1; then
	PKG=$(ls -1 dist/*.tar.gz 2>/dev/null | head -1)
	ok "package: ${PKG:-?} ($(du -h "$PKG" 2>/dev/null | cut -f1))"
	grep -E '✓' /tmp/rc-package.log | sed 's/^/      /'
else
	bad "packaging failed, see /tmp/rc-package.log"
	tail -20 /tmp/rc-package.log | sed 's/^/      /'
fi

# ---------------------------------------------------------------- 5 checks that need a real instance
say "5. checks that need a live Nextcloud (cannot run here - listed so they are not forgotten)"
# the acceptance scripts live in the app-root tests/, not under scripts/, hence the full paths
for s in scripts/deploy/deploy-app.sh \
	tests/verify-deploy.sh tests/verify-admin.sh tests/test-permissions.sh tests/test-pairing.sh \
	tests/test-authors.sh tests/test-community.sh tests/test-external.sh tests/test-engagement.sh tests/test-frontmatter-merge.sh \
	tests/test-migration.sh tests/test-site-config.sh tests/test-newfile.sh; do
	if [[ -f "$s" ]]; then
		printf '      \033[33m—\033[0m %s\n' "$s"
	fi
done

# ---------------------------------------------------------------- result
say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mready to release: dist/pages-%s.tar.gz\033[0m\n' "$VERSION"
	printf '  remember to run the scripts listed above against a real instance first.\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
