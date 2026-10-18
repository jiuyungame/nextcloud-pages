#!/usr/bin/env bash
#
# Changing the "site storage user" acceptance (v3 plan §14 / §7).
#
#   sudo ./tests/test-migration.sh
#
# Points the app at a throwaway site inside two throwaway users, then exercises preflight and the
# ordered migration (copy -> verify -> config -> push -> delete old) and the quota failure path.
# Everything it creates is removed again and the previous site config is restored (trap on EXIT).
#
# The site is a real Nextcloud folder on purpose: the copy must go through the Node API, which is
# exactly what this checks (a folder copied outside the filecache would not show up in Files).
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_USER="pages-migrate-src"
DST_USER="pages-migrate-dst"
TINY_USER="pages-migrate-tiny"
TEST_USER_PW="Pages-Migrate-$(date +%s)-pw"
SITE_PATH="/Sites/pages-migrate-test"

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

OUT=/tmp/tm.json
JAR=/tmp/tm.jar
LOGIN=/tmp/tm-login.html
NC_USER=""
PW=""
TOKEN=""
SITE_BEFORE="{}"
RESTORED=0

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

cleanup() {
	if [[ "$RESTORED" -eq 0 && -n "$TOKEN" ]]; then
		api PUT /api/site "$SITE_BEFORE" > /dev/null 2>&1 || true
		api POST /api/bridge/push '{}' > /dev/null 2>&1 || true
	fi
	for u in "$SRC_USER" "$DST_USER" "$TINY_USER"; do
		occ user:delete "$u" >/dev/null 2>&1 || true
	done
	rm -f "$JAR" "$OUT" "$LOGIN"
}
trap cleanup EXIT

api() { # api <METHOD> <app-path> [JSON] → HTTP code, body in $OUT
	local method="$1" path="$2" body="${3:-}"
	local args=(-X "$method" -H "requesttoken: $TOKEN" -H 'OCS-APIREQUEST: true'
		-H 'Content-Type: application/json' -u "$NC_USER:$PW" -b "$JAR" -c "$JAR"
		-o "$OUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(--data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path"
}
py() { python3 -c "$1" < "$OUT" 2>/dev/null; }

dav() { # dav <user> <pass> <METHOD> <path> [data] → HTTP code
	local user="$1" pass="$2" method="$3" path="$4" data="${5:-}"
	local args=(-X "$method" -u "$user:$pass" -o /dev/null -w '%{http_code}')
	[[ -n "$data" ]] && args+=(--data-binary "$data")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/remote.php/dav/files/$user$path"
}
dav_folder() { dav "$1" "$2" MKCOL "$3" > /dev/null; }

mkuser() { # mkuser <uid> [quota]
	occ user:delete "$1" >/dev/null 2>&1 || true
	OC_PASS="$TEST_USER_PW" occ user:add --password-from-env --display-name "$1" "$1" >/dev/null 2>&1 \
		|| { bad "cannot create the test user $1"; exit 1; }
	[[ -n "${2:-}" ]] && occ user:setting "$1" files quota "$2" >/dev/null 2>&1
	# a real write, not just a PROPFIND: the first write is what creates the account's files/ folder,
	# and a migration target that was never used has to be reachable too
	dav "$1" "$TEST_USER_PW" PUT "/pages-setup-probe.txt" 'ok' > /dev/null
}

say "0. admin session"
NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
[[ -n "$NC_USER" ]] || { bad "no Nextcloud user found"; exit 1; }
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { bad "cannot issue an app password"; exit 1; }
PW=$(cat "$PW_FILE")
rm -f "$JAR"
code=$(curl --noproxy '*' -sk -c "$JAR" -u "$NC_USER:$PW" -H 'OCS-APIREQUEST: true' -o "$LOGIN" -w '%{http_code}' "$BASE/index.php/settings/user")
TOKEN=$(sed -n 's/.*data-requesttoken="\([^"]*\)".*/\1/p' "$LOGIN" | head -1)
[[ "$code" == "200" && -n "$TOKEN" ]] || { bad "admin login failed (HTTP $code)"; exit 1; }
api GET /api/site > /dev/null
SITE_BEFORE=$(python3 -c '
import json
s = (json.load(open("/tmp/tm.json")).get("site") or {})
print(json.dumps({k: s.get(k) for k in ("nc_user", "nc_path", "repo_url", "branch", "recipe", "exclude") if s.get(k) is not None}))
')
ok "admin session ready; current site saved for restore"

say "1. three throwaway users and a throwaway site"
mkuser "$SRC_USER"
mkuser "$DST_USER"
mkuser "$TINY_USER" "1 MB"
dav_folder "$SRC_USER" "$TEST_USER_PW" "/Sites"
dav_folder "$SRC_USER" "$TEST_USER_PW" "$SITE_PATH"
dav "$SRC_USER" "$TEST_USER_PW" PUT "$SITE_PATH/index.html" '<html>one</html>' > /dev/null
dav "$SRC_USER" "$TEST_USER_PW" PUT "$SITE_PATH/about.md" '# about' > /dev/null
dav_folder "$SRC_USER" "$TEST_USER_PW" "$SITE_PATH/assets"
dav "$SRC_USER" "$TEST_USER_PW" PUT "$SITE_PATH/assets/app.css" 'body{}' > /dev/null
ok "users ready ($SRC_USER / $DST_USER / $TINY_USER) and a 3-file site at $SITE_PATH"

SITE_JSON=$(python3 -c '
import json, sys
print(json.dumps({
    "nc_user": sys.argv[1], "nc_path": sys.argv[2],
    "repo_url": "git@example.invalid:x/y.git", "branch": "main",
    # Jekyll is the only preset; an empty command means "no build step, the source is the artifact",
    # which is all this fixture needs (the test moves files, it does not build them)
    "recipe": {"preset": "jekyll", "command": [], "output_dir": "."},
}))
' "$SRC_USER" "$SITE_PATH")
CODE=$(api PUT /api/site "$SITE_JSON")
if [[ "$CODE" == "200" ]]; then
	ok "PUT /api/site points the app at the throwaway site (HTTP 200)"
else
	bad "cannot point the app at the throwaway site (HTTP $CODE): $(head -c 160 "$OUT")"
fi
# the fixture must really be readable through the app: an empty tree would let the ordered
# migration pass vacuously (source and target would both be empty and compare equal)
api GET /api/site/tree > /dev/null
FIXTURE=$(py 'import json,sys;t=json.dumps(json.load(sys.stdin));print(all(p in t for p in ("index.html","about.md","assets/app.css")))')
if [[ "$FIXTURE" == "True" ]]; then
	ok "the app reads the fixture site (all three files in the tree)"
else
	bad "the app does not see the fixture files - the rest of this test would pass for the wrong reason: $(head -c 200 "$OUT")"
fi

say "2. preflight: target missing, then already holding a folder"
CODE=$(api POST /api/admin/site/preflight "{\"nc_user\":\"$DST_USER\"}")
DECISION=$(py 'import json,sys;print(json.load(sys.stdin).get("decision",""))')
TSTATE=$(py 'import json,sys;print((json.load(sys.stdin).get("target") or {}).get("state",""))')
SFILES=$(py 'import json,sys;print((json.load(sys.stdin).get("source") or {}).get("files",""))')
if [[ "$CODE" == "200" && "$DECISION" == "offer_migration" && "$TSTATE" == "missing" ]]; then
	ok "preflight on a missing target → offer_migration (source holds $SFILES files)"
else
	bad "preflight wrong for a missing target (HTTP $CODE decision='$DECISION' target='$TSTATE')"
fi
QOK=$(py 'import json,sys;print((json.load(sys.stdin).get("quota") or {}).get("ok"))')
if [[ "$QOK" == "True" || "$QOK" == "False" ]]; then
	ok "preflight reports the target quota (ok=$QOK)"
else
	bad "preflight did not report a quota verdict"
fi

dav_folder "$DST_USER" "$TEST_USER_PW" "/Sites"
dav_folder "$DST_USER" "$TEST_USER_PW" "$SITE_PATH"
dav "$DST_USER" "$TEST_USER_PW" PUT "$SITE_PATH/preexisting.txt" 'already here' > /dev/null
api POST /api/admin/site/preflight "{\"nc_user\":\"$DST_USER\"}" > /dev/null
DECISION=$(py 'import json,sys;print(json.load(sys.stdin).get("decision",""))')
TSTATE=$(py 'import json,sys;print((json.load(sys.stdin).get("target") or {}).get("state",""))')
if [[ "$DECISION" == "save_directly" && "$TSTATE" == "existing" ]]; then
	ok "preflight on an occupied target → save_directly (the existing folder wins)"
else
	bad "preflight wrong for an occupied target (decision='$DECISION' target='$TSTATE')"
fi
dav "$DST_USER" "$TEST_USER_PW" DELETE "$SITE_PATH/preexisting.txt" > /dev/null
dav "$DST_USER" "$TEST_USER_PW" DELETE "$SITE_PATH" > /dev/null

say "3. migrate: copy, verify, config, push, delete old"
CODE=$(api POST /api/admin/site/migrate "{\"nc_user\":\"$DST_USER\"}")
MOK=$(py 'import json,sys;print(json.load(sys.stdin).get("ok"))')
STEPS=$(py 'import json,sys;d=json.load(sys.stdin);print(" ".join("%s=%s" % (s.get("step"), s.get("ok")) for s in (d.get("steps") or [])))')
if [[ "$CODE" == "200" && "$MOK" == "True" ]]; then
	ok "migrate → ok ($STEPS)"
else
	bad "migrate failed (HTTP $CODE ok=$MOK steps: $STEPS)"
	py 'import json,sys;print("     ", json.load(sys.stdin).get("message",""))'
fi
ALLSTEPS=$(py 'import json,sys;d=json.load(sys.stdin);print(all((s.get("ok") for s in (d.get("steps") or []))) and len(d.get("steps") or []) == 5)')
if [[ "$ALLSTEPS" == "True" ]]; then
	ok "all five steps ran in order and succeeded"
else
	bad "the step report is incomplete or has a failure: $STEPS"
fi

api GET /api/site > /dev/null
NOW_USER=$(python3 -c 'import json;print((json.load(open("/tmp/tm.json")).get("site") or {}).get("nc_user",""))')
if [[ "$NOW_USER" == "$DST_USER" ]]; then
	ok "the configuration now points at $DST_USER"
else
	bad "the configuration still points at '$NOW_USER'"
fi
api GET /api/site/tree > /dev/null
NEW_COUNT=$(py 'import json,sys;t=json.dumps(json.load(sys.stdin));print(all(p in t for p in ("index.html","about.md","assets/app.css")))')
if [[ "$NEW_COUNT" == "True" ]]; then
	ok "the app reads the whole site at the new location (all three files present)"
else
	bad "the new location looks incomplete when read through the app: $(head -c 200 "$OUT")"
fi
OLD_CODE=$(dav "$SRC_USER" "$TEST_USER_PW" PROPFIND "$SITE_PATH")
if [[ "$OLD_CODE" == "404" ]]; then
	ok "the old location is gone (404)"
else
	bad "the old location still answers HTTP $OLD_CODE"
fi
STILL=$(dav "$SRC_USER" "$TEST_USER_PW" PROPFIND "/Sites")
if [[ "$STILL" == "207" ]]; then
	ok "the source user's other folders were left alone"
else
	bad "the source user's Sites folder answers HTTP $STILL"
fi

say "4. a target that cannot take the copy leaves everything alone"
# 2 MB into a user whose quota is 1 MB: the copy must fail as a quota problem, not as a crash
head -c 2000000 /dev/zero | tr '\0' 'x' > /tmp/tm-big.txt
dav "$DST_USER" "$TEST_USER_PW" PUT "$SITE_PATH/big.bin" "@/tmp/tm-big.txt" > /dev/null
api POST /api/admin/site/preflight "{\"nc_user\":\"$TINY_USER\"}" > /dev/null
QOK=$(py 'import json,sys;print((json.load(sys.stdin).get("quota") or {}).get("ok"))')
if [[ "$QOK" == "False" ]]; then
	ok "preflight refuses the 1 MB target (quota.ok=false)"
else
	bad "preflight claims the 1 MB target fits (quota.ok=$QOK)"
fi
CODE=$(api POST /api/admin/site/migrate "{\"nc_user\":\"$TINY_USER\"}")
MOK=$(py 'import json,sys;print(json.load(sys.stdin).get("ok"))')
ISQ=$(py 'import json,sys;print(json.load(sys.stdin).get("quota"))')
FAILED=$(py 'import json,sys;print(json.load(sys.stdin).get("failed_step",""))')
MSG=$(py 'import json,sys;print(json.load(sys.stdin).get("message",""))')
if [[ "$MOK" == "False" && "$ISQ" == "True" ]]; then
	ok "migrate reports a quota failure at step '$FAILED': $MSG"
else
	bad "migrate did not report a quota failure (ok=$MOK quota=$ISQ failed_step='$FAILED'): $MSG"
fi
api GET /api/site > /dev/null
NOW_USER=$(python3 -c 'import json;print((json.load(open("/tmp/tm.json")).get("site") or {}).get("nc_user",""))')
STILL_THERE=$(dav "$DST_USER" "$TEST_USER_PW" PROPFIND "$SITE_PATH")
if [[ "$NOW_USER" == "$DST_USER" && "$STILL_THERE" == "207" ]]; then
	ok "the configuration and the old location are unchanged after the failure"
else
	bad "the failure changed something (nc_user='$NOW_USER', old location HTTP $STILL_THERE)"
fi
rm -f /tmp/tm-big.txt

say "5. restore the real site"
CODE=$(api PUT /api/site "$SITE_BEFORE")
api POST /api/bridge/push '{}' > /dev/null
RESTORED=1
if [[ "$CODE" == "200" ]]; then
	ok "the previous site configuration is back and pushed"
else
	bad "could not restore the previous site configuration (HTTP $CODE)"
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
