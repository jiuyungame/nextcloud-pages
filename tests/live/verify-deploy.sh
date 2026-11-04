#!/usr/bin/env bash
#
# Post-deploy acceptance with a logged-in session: readiness, routes, preview proxy, assets.
#
#   sudo ./tests/live/verify-deploy.sh
#
# Needs a one-shot app password (tests/live/test-auth.sh grant; issued automatically if missing).
# Single-site design: the script takes no site id any more.
set -uo pipefail

APP_ID=pages
BASE="${BASE:-https://localhost}"
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
NC_USER="${NC_USER:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# test-auth.sh explains this: non-login shells have no /snap/bin in PATH
if [[ -z "${OCC:-}" ]]; then
	for candidate in "$(command -v nextcloud.occ 2>/dev/null)" /snap/bin/nextcloud.occ; do
		[[ -n "$candidate" && -x "$candidate" ]] && { OCC="$candidate"; break; }
	done
fi
OCC="${OCC:-snap run nextcloud.occ}"
occ() { $OCC "$@"; }

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$*"; FAIL=$((FAIL + 1)); }
FAIL=0

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

if [[ -z "$NC_USER" ]]; then
	NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
fi

# ---------------------------------------------------------------- 0 readiness
# status.php returns 200 while in maintenance mode, so parse the body; the snap fixer runs on
# every boot and opens maintenance mode, so require it idle plus 3 route probes in a row
say "0. waiting until the app really serves (status.php + route probe + fixer idle, 3 times in a row, max 4 minutes)"
APP_URL="$BASE/index.php/apps/$APP_ID/"
fixer_busy() {
	local state
	state=$(systemctl is-active snap.nextcloud.nextcloud-fixer.service 2>/dev/null || true)
	[[ "$state" == "active" || "$state" == "activating" || "$state" == "reloading" ]]
}
ready_once() {
	fixer_busy && return 1
	curl -sk --noproxy '*' "$BASE/status.php" 2>/dev/null | python3 -c '
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
		200|401|403) return 0 ;;
		*) return 1 ;;
	esac
}
ready=0
streak=0
warned=0
for i in $(seq 1 80); do
	if ready_once; then
		streak=$((streak + 1))
		if [[ "$streak" -ge 3 ]]; then
			ok "ready ($streak passes in a row)"
			ready=1
			break
		fi
	else
		streak=0
	fi
	if [[ "$i" -eq 30 && "$warned" -eq 0 ]]; then
		warned=1
		echo "  [!] not stable after 90 s - most likely a snap app upgrade (about 2 minutes), still waiting"
	fi
	sleep 3
done
[[ "$ready" -eq 1 ]] || { bad "the app is still not serving reliably after 4 minutes; later results are not trustworthy"; exit 1; }
# ---------------------------------------------------------------- 1 unauthenticated routes
say "1. unauthenticated route probe (401/403 = matched)"
probe() {
	local code
	code=$(curl -sk --noproxy '*' -o /dev/null -w '%{http_code}' -X "$1" "$BASE/index.php/apps/$APP_ID$2")
	if [[ "$code" == "401" || "$code" == "403" ]]; then ok "$(printf '%-7s %s' "$1" "$2")"
	else bad "$(printf '%-7s %s → %s' "$1" "$2" "$code")"; fi
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
# /api/site/diff was removed by design together with the source page's review pane: there is nothing
# left to diff. An unauthenticated *matched* route answers 401/403, so a missing route is the 404
# this probe asserts explicitly (the same check test-permissions makes).
DIFF_CODE=$(curl -sk --noproxy '*' -o /dev/null -w '%{http_code}' "$BASE/index.php/apps/$APP_ID/api/site/diff")
if [[ "$DIFF_CODE" == "404" ]]; then
	ok "$(printf '%-7s %s → 404 (route removed by design)' GET /api/site/diff)"
else
	bad "$(printf '%-7s %s → %s (expected 404: the route was removed by design)' GET /api/site/diff "$DIFF_CODE")"
fi
probe POST   "/api/site/publish"
probe GET    "/api/site/log"
probe POST   "/api/site/preview-token"
probe GET    "/api/admin/config"
probe GET    "/preview/x/y"

# ---------------------------------------------------------------- 2 authentication
say "2. preparing authentication"
if [[ ! -f "$PW_FILE" ]]; then
	bash "$HERE/test-auth.sh" grant >/dev/null || { bad "failed to issue an app password"; exit 1; }
fi
APP_PW=$(cat "$PW_FILE")
curl_auth=(curl --noproxy '*' -sk -u "${NC_USER}:${APP_PW}")
code=$("${curl_auth[@]}" -o /tmp/vd-app.html -w '%{http_code}' "$BASE/index.php/apps/$APP_ID/")
[[ "$code" == "200" ]] && ok "app page opens with a session (HTTP 200)" || bad "app page does not open with a session (HTTP $code)"

# ---------------------------------------------------------------- 3 frontend assets
say "3. frontend assets"
# the snap installs apps under extra-apps, so asset URLs are /extra-apps/<app>, not /apps/<app>
ASSET_BASE=$("${curl_auth[@]}" "$BASE/index.php/apps/$APP_ID/" \
	| grep -oE 'src="[^"]*pages-main\.js[^"]*"' | head -1 | sed 's/src="//;s/"$//')
if [[ -n "$ASSET_BASE" ]]; then
	ok "main bundle referenced by the page: $ASSET_BASE"
	code=$(curl --noproxy '*' -sk -o /dev/null -w '%{http_code} %{size_download}' "$BASE$ASSET_BASE")
	[[ "${code%% *}" == "200" ]] && ok "main bundle fetched ($code bytes)" || bad "main bundle not fetchable ($code)"
else
	bad "no script tag for pages-main.js in the page"
fi

# ---------------------------------------------------------------- 3b ensure a build output
# The preview serves the build output, and a preview tree that was just re-created (a fresh init, or
# test-site-config.sh) has none yet. Build once here: the assertions below are about the proxy, not
# about whether somebody happened to build recently.
say "3b. ensuring there is a build output for the preview"
BUILD_CODE=$("${curl_auth[@]}" -X POST -H 'OCS-APIREQUEST: true' -H 'Content-Type: application/json' \
	-d '{}' -o /tmp/vd-build.json -w '%{http_code}' \
	"$BASE/index.php/apps/$APP_ID/api/site/build" 2>/dev/null || echo '000')
if [[ "$BUILD_CODE" == "200" ]]; then
	ok "POST /api/site/build → 200"
else
	bad "POST /api/site/build → $BUILD_CODE ($(head -c 200 /tmp/vd-build.json 2>/dev/null))"
fi

# ---------------------------------------------------------------- 4 preview proxy
say "4. preview proxy (needs a token from the app)"

# Single site: there is no site id to look up any more. The path that matters is the one the UI
# itself uses — POST /api/site/preview-token with the session — so that is what gets tested.
PREVIEW_TOKEN=$("${curl_auth[@]}" -X POST -H 'OCS-APIREQUEST: true' \
	"$BASE/index.php/apps/$APP_ID/api/site/preview-token" 2>/dev/null \
	| python3 -c 'import sys,json;print(json.load(sys.stdin).get("token",""))' 2>/dev/null || echo '')
if [[ -z "$PREVIEW_TOKEN" ]]; then
	bad "the app did not issue a preview token via POST /api/site/preview-token"
else
	ok "app issued a preview token for the single site"
fi

if [[ -n "${PREVIEW_TOKEN:-}" ]]; then
	PRE="$BASE/index.php/apps/$APP_ID/preview/$PREVIEW_TOKEN"
	try() {
		local label="$1" url="$2" want="${3:-200}"
		local out code
		out=$("${curl_auth[@]}" -o /tmp/vd.out -D /tmp/vd.hdr -w '%{http_code} %{size_download} %{content_type}' "$url")
		code="${out%% *}"
		if [[ "$code" == "$want" ]]; then ok "$(printf '%-28s %s' "$label" "$out")"
		else bad "$(printf '%-28s %s (expected %s)' "$label" "$out" "$want")"; fi
	}
	# bare token (site root): in-site `<a href="/">` links rewrite to this form
	try "bare token / site root" "$PRE/"            200
	try "index.html"          "$PRE/index.html"  200
	try "assets/main.css"     "$PRE/assets/main.css" 200
	try "favicon.ico"         "$PRE/favicon.ico" 200
	try "missing path"        "$PRE/__nope__.html" 404
	try "bad token"           "$BASE/index.php/apps/$APP_ID/preview/bad.token/index.html" 403

	# CSP: the nonce must let inline scripts through, or the site's own MathJax config is blocked
	"${curl_auth[@]}" -o /tmp/vd-root.html -D /tmp/vd-root.hdr "$PRE/"
	if grep -qi '^content-security-policy:.*nonce-' /tmp/vd-root.hdr; then
		ok "preview response carries a nonce-based CSP"
	else
		bad "preview response lacks a nonce-based CSP (inline scripts will be blocked)"
	fi
	if grep -q 'data-pages-preview' /tmp/vd-root.html; then
		ok "preview page has the Pages script injected"
	else
		bad "preview page has no injected script (scroll keep / auto refresh will not work)"
	fi
fi

# ---------------------------------------------------------------- 5 log
say "5. recent app log entries (pages)"
LOG=$(find /var/snap/nextcloud -maxdepth 6 -name 'nextcloud.log' 2>/dev/null | head -1)
if [[ -n "$LOG" ]]; then
	tail -n 400 "$LOG" | python3 -c '
import sys, json
n = 0
for line in sys.stdin:
    try: d = json.loads(line)
    except Exception: continue
    if d.get("app") != "pages": continue
    n += 1
    print(" ", d.get("time",""), "L"+str(d.get("level")), "|", (d.get("message") or "")[:160])
if not n: print("  (no pages entries)")
'
else
	echo "  (nextcloud.log not found)"
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
