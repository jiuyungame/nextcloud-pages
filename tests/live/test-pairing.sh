#!/usr/bin/env bash
#
# Pairing and config-hosting acceptance against a live instance (v3 plan §7 / §15).
#
#   sudo ./tests/live/test-pairing.sh
#
# Covers what only a real Nextcloud can show: that the app can read a paired bridge's state, that
# the global parameters stored in the app really become the bridge's effective config, and that a
# bad value is refused before it can reach the bridge. The UNPAIRED state machine itself (503 on
# business routes, the one-time code, 3 tries, rotation) is asserted in bridge/tests/test_smoke.py,
# because it needs a throwaway bridge rather than this paired one.
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ -z "${OCC:-}" ]]; then
	for candidate in "$(command -v nextcloud.occ 2>/dev/null)" /snap/bin/nextcloud.occ; do
		[[ -n "$candidate" && -x "$candidate" ]] && { OCC="$candidate"; break; }
	done
fi
OCC="${OCC:-snap run nextcloud.occ}"
occ() { $OCC "$@"; }

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()  { printf '  \033[32m✓\033[0m %s\n' "$*"; }
bad() { printf '  \033[31m✗\033[0m %s\n' "$*"; FAIL=$((FAIL + 1)); }
FAIL=0

OUT=/tmp/tp.json
JAR=/tmp/tp.jar
LOGIN=/tmp/tp-login.html
NC_USER=""
PW=""
TOKEN=""

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

api() { # api <METHOD> <app-path> [JSON] → HTTP code, body in $OUT
	local method="$1" path="$2" body="${3:-}"
	local args=(-X "$method" -H "requesttoken: $TOKEN" -H 'OCS-APIREQUEST: true'
		-H 'Content-Type: application/json' -u "$NC_USER:$PW" -b "$JAR" -c "$JAR"
		-o "$OUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(--data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path"
}
py() { python3 -c "$1" < "$OUT" 2>/dev/null; }

say "0. admin session"
NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
[[ -n "$NC_USER" ]] || { bad "no Nextcloud user found"; exit 1; }
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { bad "cannot issue an app password"; exit 1; }
PW=$(cat "$PW_FILE")
rm -f "$JAR"
code=$(curl --noproxy '*' -sk -c "$JAR" -u "$NC_USER:$PW" -H 'OCS-APIREQUEST: true' -o "$LOGIN" -w '%{http_code}' "$BASE/index.php/settings/user")
TOKEN=$(sed -n 's/.*data-requesttoken="\([^"]*\)".*/\1/p' "$LOGIN" | head -1)
if [[ "$code" == "200" && -n "$TOKEN" ]]; then
	ok "admin session ready ($NC_USER)"
else
	bad "admin login failed (HTTP $code)"; exit 1
fi

say "1. the app can read the paired bridge's state"
CODE_HEALTH=$(api GET /api/bridge/health)
PAIRED=$(py 'import json,sys;print(json.load(sys.stdin).get("paired"))')
VERSION=$(py 'import json,sys;print(json.load(sys.stdin).get("version",""))')
WANT=$(py 'import json,sys;print(json.load(sys.stdin).get("protocol_expected",""))')
if [[ "$CODE_HEALTH" == "200" ]]; then
	ok "GET /api/bridge/health → 200"
else
	bad "GET /api/bridge/health → $CODE_HEALTH"
fi
if [[ "$PAIRED" == "True" ]]; then
	ok "the app reports the bridge as paired"
else
	bad "the app does not see the bridge as paired (transitional state twice during pairing: only one side can store the new URL first)"
fi
if [[ "$VERSION" == pages-bridge/* ]]; then
	ok "bridge version reported: $VERSION"
else
	bad "no bridge version in the health answer (got '$VERSION')"
fi
if [[ "$WANT" == "3" ]]; then
	ok "the app expects protocol 3 (protocol v3 plan)"
else
	bad "protocol_expected is '$WANT', expected 3"
fi

say "2. pairing is refused while the bridge is already paired"
CODE_PAIR=$(api POST /api/bridge/pair '{"url":"http://127.0.0.1:8787","code":"AAAAAAAA"}')
ERR=$(py 'import json,sys;print(json.load(sys.stdin).get("error_code",""))')
if [[ "$CODE_PAIR" == "409" && "$ERR" == "ALREADY_PAIRED" ]]; then
	ok "POST /api/bridge/pair → 409 ALREADY_PAIRED (the bridge's answer, passed through)"
elif [[ "$CODE_PAIR" == "401" && "$ERR" == "BAD_CODE" ]]; then
	ok "POST /api/bridge/pair → 401 BAD_CODE (bridge is unpaired, the code was wrong)"
else
	bad "unexpected pair answer: HTTP $CODE_PAIR error_code='$ERR' ($(head -c 160 "$OUT"))"
fi

say "3. a global parameter stored in the app becomes the bridge's effective config"
BEFORE_GLOBAL=$(occ config:app:get pages bridge_global 2>/dev/null || echo '')
CODE_SAVE=$(api PUT /api/admin/config '{"bridgeGlobal":{"build_debounce_ms":700}}')
if [[ "$CODE_SAVE" == "200" ]]; then
	ok "PUT /api/admin/config accepts bridgeGlobal → 200"
else
	bad "PUT /api/admin/config rejected bridgeGlobal → $CODE_SAVE ($(head -c 160 "$OUT"))"
fi
CODE_PUSH=$(api POST /api/bridge/push '{}')
if [[ "$CODE_PUSH" == "200" ]]; then
	ok "POST /api/bridge/push → 200"
else
	bad "POST /api/bridge/push → $CODE_PUSH ($(head -c 200 "$OUT"))"
fi
api GET /api/bridge/health > /dev/null
SRC=$(py 'import json,sys;e=(json.load(sys.stdin).get("environment") or {});print((e.get("sources") or {}).get("build_debounce_ms",""))')
EFF=$(py 'import json,sys;e=(json.load(sys.stdin).get("environment") or {});print((e.get("effective") or {}).get("build_debounce_ms",""))')
if [[ "$SRC" == "app" && "$EFF" == "700" ]]; then
	ok "the bridge reports build_debounce_ms=700 with source 'app'"
else
	bad "the pushed global did not take effect (source='$SRC' effective='$EFF')"
fi
# a key nobody pushed must still name its own origin, and never claim to come from the app
SRC_KEEP=$(py 'import json,sys;e=(json.load(sys.stdin).get("environment") or {});s=(e.get("sources") or {});print(s.get("mirror_verify",""))')
if [[ "$SRC_KEEP" == "toml" || "$SRC_KEEP" == "default" ]]; then
	ok "an untouched key still reports its own origin ($SRC_KEEP)"
else
	bad "mirror_verify claims origin '$SRC_KEEP' although the app never pushed it"
fi

say "4. a bad value never reaches the bridge"
CODE_TYPE=$(api PUT /api/admin/config '{"bridgeGlobal":{"build_debounce_ms":"soon"}}')
if [[ "$CODE_TYPE" == "400" ]]; then
	ok "a non-numeric integer is refused by the app (HTTP 400)"
else
	bad "a non-numeric integer was accepted (HTTP $CODE_TYPE)"
fi
CODE_KEY=$(api PUT /api/admin/config '{"bridgeGlobal":{"no_such_parameter":1}}')
if [[ "$CODE_KEY" == "400" ]]; then
	ok "an unknown parameter is refused by the app (HTTP 400)"
else
	bad "an unknown parameter was accepted (HTTP $CODE_KEY)"
fi

say "5. restore the previous state"
if [[ -n "$BEFORE_GLOBAL" ]]; then
	RESTORE=$(python3 -c 'import json,sys;print(json.dumps({"bridgeGlobal": json.loads(sys.argv[1])}))' "$BEFORE_GLOBAL")
else
	RESTORE='{"bridgeGlobal":{}}'
fi
api PUT /api/admin/config "$RESTORE" > /dev/null
api POST /api/bridge/push '{}' > /dev/null
AFTER_GLOBAL=$(occ config:app:get pages bridge_global 2>/dev/null || echo '')
# Compared as data, not as text: the stored document is JSON, and any tool that rewrites it (an occ
# set, a settings save) may reformat it without changing a single value. Comparing the bytes reported
# "not restored" for a configuration that was restored exactly.
if python3 -c '
import json, sys
try:
    before = json.loads(sys.argv[1] or "{}")
    after = json.loads(sys.argv[2] or "{}")
except Exception:
    raise SystemExit(1)
raise SystemExit(0 if before == after else 1)
' "$BEFORE_GLOBAL" "$AFTER_GLOBAL" 2>/dev/null; then
	ok "bridge_global restored (compared as JSON, not as text)"
else
	bad "bridge_global not restored ('$AFTER_GLOBAL' vs '$BEFORE_GLOBAL')"
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
