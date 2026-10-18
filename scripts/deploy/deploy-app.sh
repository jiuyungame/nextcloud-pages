#!/usr/bin/env bash
#
# Deploy the app into a snap Nextcloud, then restart and verify it.
#
#   ./scripts/deploy/deploy-app.sh                sync + restart + verify (version bumps only if js/css changed)
#   ./scripts/deploy/deploy-app.sh --no-restart   sync files only, no restart
#   ./scripts/deploy/deploy-app.sh --no-bump      never touch the version in info.xml
#
# Restart takes about 25 seconds.
set -euo pipefail

APP_ID=pages
# this script lives in scripts/deploy/, so the app root is two levels up
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DST="/var/snap/nextcloud/current/nextcloud/extra-apps/${APP_ID}"
RESTART=1
BUMP=1
for arg in "$@"; do
	case "$arg" in
		--no-restart) RESTART=0 ;;
		--no-bump)    BUMP=0 ;;
	esac
done

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }

# ---------------------------------------------------------------- version bump
#
# asset URLs are keyed on the app version (?v=md5(version)[0:8]), not on file contents
# a bump costs a full app upgrade on the next restart, so only bump when js/css really changed
INFO="$SRC/appinfo/info.xml"
STAMP="$SRC/.deploy-assets.sha"
BUMPED=0
if [[ "$BUMP" -eq 1 ]]; then
	ASSET_HASH=$(cat "$SRC"/js/*.js "$SRC"/css/*.css 2>/dev/null | md5sum | cut -d' ' -f1)
	PREV_HASH=$(cat "$STAMP" 2>/dev/null || echo '')
	if [[ "$ASSET_HASH" == "$PREV_HASH" ]]; then
		say "frontend assets unchanged (${ASSET_HASH:0:8}), skipping the version bump - saves an upgrade cycle"
	else
		CUR=$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$INFO" | head -1)
		if [[ "$CUR" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
			NEW="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.$((BASH_REMATCH[3] + 1))"
			sed -i "s:<version>${CUR}</version>:<version>${NEW}</version>:" "$INFO"
			printf '%s\n' "$ASSET_HASH" > "$STAMP"
			BUMPED=1
			say "frontend assets changed (${PREV_HASH:0:8} → ${ASSET_HASH:0:8}): app version ${CUR} → ${NEW}"
			say "  ⚠️ this restart includes an app upgrade (maintenance mode, about 2 minutes)"
		else
			warn "version in info.xml is not x.y.z (read '${CUR}'), skipping the bump - frontend changes may need a hard refresh."
		fi
	fi
fi

say "syncing to $DST"
rsync -a --delete \
	--exclude 'bridge/' \
	--exclude 'node_modules/' \
	--exclude 'src/' \
	--exclude 'tests/' \
	--exclude 'scripts/' \
	--exclude '.deploy-assets.sha' \
	--exclude '.git/' \
	--exclude 'package.json' \
	--exclude 'package-lock.json' \
	--exclude 'vite.config.js' \
	"$SRC/" "$DST/"

say "PHP syntax check"
snap run --shell nextcloud.occ -c "
  cd $DST || exit 1
  fail=0
  for f in \$(find . -name '*.php'); do
    php -l \"\$f\" >/dev/null 2>&1 || { echo \"SYNTAX FAIL: \$f\"; php -l \"\$f\"; fail=1; }
  done
  [ \$fail -eq 0 ] && echo 'all PHP files syntax OK'
"

if [[ "$RESTART" -eq 1 ]]; then
	# restarting during a running app upgrade aborts it and leaves maintenance mode on, so wait first
	# status.php is the reliable source for the maintenance flag (no config.php parsing, no permissions)
	maintenance_on() {
		curl -sk --noproxy '*' https://localhost/status.php 2>/dev/null | python3 -c '
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    raise SystemExit(0)      # unreadable: assume maintenance may be on and let the caller wait
raise SystemExit(0 if d.get("maintenance") else 1)
'
	}
	if maintenance_on; then
		warn "maintenance mode detected - a previous app upgrade may still be running; waiting up to 4 minutes."
		waited=0
		while maintenance_on && [[ "$waited" -lt 240 ]]; do
			sleep 5
			waited=$((waited + 5))
		done
		if maintenance_on; then
			warn "still in maintenance mode after ${waited}s. If no upgrade is running, do it manually:"
			warn "    sudo nextcloud.occ maintenance:mode --off && sudo nextcloud.occ upgrade"
		else
			printf '  upgrade finished (waited %s s), continuing.\n' "$waited"
		fi
	fi

	# a restart is required for appinfo/routes.php changes to take effect (new routes 404 otherwise)
	say "restarting snap (flushes caches, about 25 seconds)"
	sudo snap restart nextcloud >/dev/null
	# do not replace this with a fixed sleep: Apache accepts connections before PHP/Nextcloud is ready
	# status.php returns 200 while in maintenance mode, so parse the body instead of trusting the code
	# the snap fixer runs on every boot and opens maintenance mode, so require it idle (3 passes)
	say "waiting until the app really serves (status.php + route probe + fixer idle, 3 times in a row, max 4 minutes)"
	ready=0
	streak=0
	warned=0
	APP_URL="https://localhost/index.php/apps/${APP_ID}/"
	fixer_busy() {
		local state
		state=$(systemctl is-active snap.nextcloud.nextcloud-fixer.service 2>/dev/null || true)
		[[ "$state" == "active" || "$state" == "activating" || "$state" == "reloading" ]]
	}
	ready_once() {
		fixer_busy && return 1
		curl -sk --noproxy '*' https://localhost/status.php 2>/dev/null | python3 -c '
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    raise SystemExit(1)
raise SystemExit(0 if d.get("installed") and not d.get("maintenance") else 1)
' || return 1
		local code
		code=$(curl -sk --noproxy '*' -o /dev/null -w '%{http_code}' "$APP_URL")
		case "$code" in
			200|401|403) return 0 ;;   # all mean the app is serving; 503/500 do not
			*) return 1 ;;
		esac
	}
	for i in $(seq 1 80); do
		if ready_once; then
			streak=$((streak + 1))
			if [[ "$streak" -ge 3 ]]; then
				printf '  ready (%s passes in a row, about %s s)\n' "$streak" "$((i * 3))"
				ready=1
				break
			fi
		else
			streak=0
		fi
		if [[ "$i" -eq 30 && "$warned" -eq 0 ]]; then
			warned=1
			warn "not stable after 90 s - the snap is probably still running the fixer / app upgrade (about 2 minutes), still waiting."
		fi
		sleep 3
	done
	if [[ "$ready" -eq 0 ]]; then
		warn "the app is still not serving reliably after 4 minutes. Check manually: sudo nextcloud.occ status"
	fi
else
	warn "restart skipped - if you changed appinfo/routes.php, the new routes will not take effect."
fi

say "route probe (401 = matched, login required; 404 = not matched)"
# "ready" is not "new code loaded": confirm the live app version matches the version just written
if [[ -n "${NEW:-}" ]]; then
	live=''
	for _ in $(seq 1 10); do
		live=$(sudo nextcloud.occ app:list 2>/dev/null | sed -n 's/^ *- pages: *//p' | head -1)
		[[ "$live" == "$NEW" ]] && break
		sleep 2
	done
	if [[ "$live" == "$NEW" ]]; then
		printf '  \033[32m✓\033[0m live app version = %s\n' "$live"
	else
		warn "live app version is '${live}', expected '${NEW}' - this sync may not have taken effect yet."
	fi
fi

BASE=https://localhost/index.php/apps/${APP_ID}
FAILED=0
probe() {
	local code
	code=$(curl -sk --noproxy '*' -o /dev/null -w '%{http_code}' -X "$1" "$BASE$2")
	if [[ "$code" == "401" || "$code" == "403" ]]; then
		printf '  \033[32m✓\033[0m %-7s %s\n' "$1" "$2"
	else
		printf '  \033[31m✗\033[0m %-7s %s → %s\n' "$1" "$2" "$code"
		FAILED=$((FAILED + 1))
	fi
}
probe GET    "/"
# single site: no site id in any path, and DELETE /api/sites/{id} is gone with it
probe GET    "/api/site"
probe PUT    "/api/site"
# pairing and pushing are what the bridge panel depends on, so they belong in the probe list
probe GET    "/api/bridge/health"
probe POST   "/api/bridge/push"
probe POST   "/api/bridge/pair"
probe POST   "/api/site/init"
probe POST   "/api/site/pull"
probe GET    "/api/site/tree"
probe GET    "/api/site/file"
probe PUT    "/api/site/file"
probe POST   "/api/site/file"
probe DELETE "/api/site/file"
probe POST   "/api/site/folder"
probe POST   "/api/site/move"
probe GET    "/api/site/status"
probe POST   "/api/site/build"
probe POST   "/api/site/notify"
probe GET    "/api/site/diff"
probe POST   "/api/site/publish"
probe GET    "/api/site/log"
probe POST   "/api/site/preview-token"
probe GET    "/api/admin/config"
# the two pages of the app: the content shell for everybody, the source page for editors/publishers
probe GET    "/"
probe GET    "/source"
# the community and authoring endpoints: reading articles and the author source table
probe GET    "/api/articles"
probe GET    "/api/articles/mine"
probe GET    "/api/article"
# the structured article editor (body + front-matter fields, merged on the server)
probe GET    "/api/article/content"
probe PUT    "/api/article/content"
probe GET    "/api/tags"
probe GET    "/api/author/sources"
# the visibility workflow: ask, withdraw, the reviewer's answer - and the publish that stages what is
# external (the pending requests are read as the `requested` entries of /api/articles)
probe POST   "/api/article/request"
probe POST   "/api/article/cancel"
probe POST   "/api/article/decision"
probe GET    "/preview/x/y"

say "occ pages:doctor"
sudo nextcloud.occ pages:doctor || warn "doctor reported failing checks"

if [[ "$FAILED" -gt 0 ]]; then
	warn "${FAILED} routes did not match - see the lines marked ✗ above."
	exit 1
fi

say "done."
