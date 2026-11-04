#!/usr/bin/env bash
#
# Site config end-to-end regression: save → push → init (repository + refs) → build → status.
#
#   sudo ./tests/live/test-site-config.sh
#
# Single-site design: no site id is passed in and no URL carries one any more.
# Idempotent: a site that is already initialised is only adopted + fetched.
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
NC_USER="${NC_USER:-}"
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

JAR=/tmp/sc.jar
BODY=/tmp/sc.json

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

if [[ -z "$NC_USER" ]]; then
	NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
fi
if [[ ! -f "$PW_FILE" ]]; then
	bash "$HERE/test-auth.sh" grant >/dev/null || { echo "failed to issue an app password" >&2; exit 1; }
fi
PW=$(cat "$PW_FILE")

say "0. log in and grab a requesttoken"
rm -f "$JAR"
curl --noproxy '*' -sk -c "$JAR" -u "${NC_USER}:${PW}" -o /tmp/sc-page.html "$BASE/index.php/apps/$APP_ID/"
TOKEN=$(sed -n 's/.*data-requesttoken="\([^"]*\)".*/\1/p' /tmp/sc-page.html | head -1)
[[ -n "$TOKEN" ]] && ok "requesttoken ready" || { bad "cannot get a requesttoken"; exit 1; }

api() { # api <METHOD> <PATH> [JSON] → stdout=body; status code goes to /tmp/sc.code
	local method="$1" path="$2" body="${3:-}"
	local args=(-X "$method" -H "requesttoken: $TOKEN" -H 'OCS-APIREQUEST: true'
		-H 'Content-Type: application/json' -b "$JAR" -c "$JAR" -o "$BODY" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(--data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path" > /tmp/sc.code
	cat "$BODY"
}
code() { cat /tmp/sc.code; }

say "1. read the current site config"
api GET /api/site > /dev/null
if [[ "$(code)" == "200" ]]; then
	ok "GET /api/site → 200"
else
	bad "GET /api/site → $(code)"
fi
# single site: one object, no id and no name any more
SITE_LABEL=$(python3 -c 'import json;d=json.load(open("/tmp/sc.json"));print((d.get("site") or {}).get("nc_path","") or "(no site configured)")')
ok "site: $SITE_LABEL"

# keep the folder / user / remote / recipe, and send no id, no name, no work_path:
# the single-site shape has none of those any more (the work copy is the bridge's work root)
PAYLOAD=$(python3 - "$NC_USER" <<'PY'
import json
d = json.load(open('/tmp/sc.json'))
s = d.get('site') or {}
recipe = s.get('recipe') or {}
out = {
    'nc_path': s.get('nc_path'),
    'nc_user': s.get('nc_user') or __import__('sys').argv[1],
    'repo_url': s.get('repo_url'),
    'branch': s.get('branch') or 'main',
    'remote_name': s.get('remote_name') or 'origin',
    'recipe': {
        'preset': recipe.get('preset') or 'auto',
        'output_dir': recipe.get('output_dir') or '',
        'overrides': recipe.get('overrides') or {},
    },
}
print(json.dumps(out, ensure_ascii=False))
PY
)

say "2. save the site config (PUT /api/site, no id, no name, no work_path)"
api PUT "/api/site" "$PAYLOAD" > /dev/null
if [[ "$(code)" == "200" ]]; then
	ok "PUT /api/site → 200"
else
	bad "PUT /api/site → $(code): $(head -c 200 /tmp/sc.json)"
fi

say "3. push the config to the bridge"
# Saving only stores the configuration; handing it to the bridge stays an explicit action
# (POST /api/bridge/push - the same route the admin page's button uses and doctor points at).
# Without this push the bridge would never hear about the site, and init/build below would test
# a bridge that knows nothing.
api POST /api/bridge/push > /dev/null
if [[ "$(code)" == "200" ]]; then
	ok "POST /api/bridge/push → 200"
else
	bad "POST /api/bridge/push → $(code): $(head -c 200 /tmp/sc.json)"
fi
api GET /api/site > /dev/null
UNPUSHED=$(python3 -c 'import json;print((json.load(open("/tmp/sc.json")) or {}).get("has_unpushed_changes"))' 2>/dev/null || echo '?')
if [[ "$UNPUSHED" == "False" ]]; then
	ok "no unpushed changes after pushing (the bridge has the config)"
else
	bad "the app still reports unpushed changes ($UNPUSHED) after pushing"
fi
# single site: the work copy is configuration the app itself reports. It is read from the admin's own
# `GET /api/site/status` (which proxies the bridge), never from a direct bridge call: after pairing the
# bridge secret lives in effective.json, so a TOML secret answers 401 and the check would prove
# nothing. The path is read, not hardcoded.
BRIDGE_WORK=""
api GET /api/site/status > /dev/null
if [[ "$(code)" == "200" ]]; then
	BRIDGE_WORK=$(python3 -c 'import json;print((json.load(open("/tmp/sc.json")).get("work") or ""))')
	INITIALIZED=$(python3 -c 'import json;print(json.load(open("/tmp/sc.json")).get("initialized"))')
	if [[ -n "$BRIDGE_WORK" && "$BRIDGE_WORK" == /* ]]; then
		ok "work copy as seen by the app = $BRIDGE_WORK"
	else
		bad "the status reports no absolute work copy ('$BRIDGE_WORK')"
	fi
else
	bad "GET /api/site/status → $(code); cannot judge the work copy"
fi

say "4. first git init (repository created, refs fetched, nothing checked out)"
T0=$(date +%s)
api POST "/api/site/init" > /dev/null
T1=$(date +%s)
INIT_CODE=$(code)
if [[ "$INIT_CODE" == "200" ]]; then
	ok "POST /api/site/init → 200 (took $((T1 - T0))s)"
	python3 -c '
import json
d = json.load(open("/tmp/sc.json")).get("result") or {}
git = d.get("git") or {}
mirror = d.get("mirror") or {}
print("     git:", git.get("action"), "fetched:", git.get("fetched"))
print("     mirror:", mirror.get("direction"), "copied:", mirror.get("copied"), "deleted:", mirror.get("deleted"))
' 2>/dev/null || true
else
	bad "init → $INIT_CODE: $(head -c 300 /tmp/sc.json)"
fi

# "is the work copy a git repository" is answered by the app's own status: `initialized` is what
# Create/Pull sets, and `git` carries the branch. That works for a fresh init and for an idempotent
# re-run that adopts an existing repository, and it needs no access to a host path (a remote instance
# would not expose one). When this machine can see the directory, the branch/HEAD detail is checked
# locally as well.
api GET /api/site/status > /dev/null
INITIALIZED=$(python3 -c 'import json;print(json.load(open("/tmp/sc.json")).get("initialized"))' 2>/dev/null || echo '?')
GIT_BRANCH=$(python3 -c 'import json;print((json.load(open("/tmp/sc.json")).get("git") or {}).get("branch") or "")' 2>/dev/null || echo '')
if [[ "$INITIALIZED" == "True" ]]; then
	ok "the app reports the work copy initialised after Create/Pull (initialized=True, branch '${GIT_BRANCH:-?}')"
else
	bad "the app does not report an initialised work copy after init (initialized=$INITIALIZED)"
fi
if [[ -n "$BRIDGE_WORK" && -d "$BRIDGE_WORK/.git" ]]; then
	ok "work copy is a git repository on this host: $BRIDGE_WORK/.git"
	# init creates the repository and fetches refs; it never checks anything out, so a fresh site's
	# HEAD stays unborn (rev-parse cannot name it, symbolic-ref still can). An idempotent rerun adopts
	# whatever history the tree already had, upstream included.
	BRANCH=$(git -C "$BRIDGE_WORK" symbolic-ref --quiet --short HEAD 2>/dev/null || echo '?')
	UPSTREAM=$(git -C "$BRIDGE_WORK" rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || echo '(none)')
	HEAD_SHA=$(git -C "$BRIDGE_WORK" rev-parse HEAD 2>/dev/null || echo '')
	ok "branch=$BRANCH upstream=$UPSTREAM HEAD=${HEAD_SHA:0:10}"
	if [[ "$BRANCH" != "main" ]]; then
		bad "branch is '$BRANCH', expected main"
	elif [[ -z "$HEAD_SHA" ]]; then
		ok "fresh init: HEAD is unborn and nothing was checked out"
		[[ "$UPSTREAM" == "(none)" ]] && ok "fresh init sets no upstream" || bad "fresh init set an upstream: $UPSTREAM"
	elif [[ ${#HEAD_SHA} -eq 40 ]]; then
		ok "adopted repository keeps its commit ${HEAD_SHA:0:10} (init checked nothing out)"
	else
		bad "HEAD is neither unborn nor a commit: '$HEAD_SHA'"
	fi
elif [[ -n "$GIT_BRANCH" ]]; then
	ok "the status reports a git repository on branch '$GIT_BRANCH' (host path '$BRIDGE_WORK' is not readable here)"
else
	bad "no git repository is reported after init (status initialized=$INITIALIZED, work '$BRIDGE_WORK')"
fi

say "5. build in the preview tree (the commit tree is never built)"
api POST "/api/site/build" > /dev/null
if [[ "$(code)" == "200" ]]; then
	python3 -c '
import json
d = json.load(open("/tmp/sc.json"))
b = (d.get("build") or {})
print("     ok=%s exit=%s ms=%s files=%s installed=%s" % (b.get("ok"), b.get("exit_code"), b.get("ms"), b.get("output_files"), b.get("installed")))
print("     cmd:", " ".join(b.get("command") or []))
' 2>/dev/null || true
	BUILD_OK=$(python3 -c 'import json;print((json.load(open("/tmp/sc.json")).get("build") or {}).get("ok"))' 2>/dev/null)
	[[ "$BUILD_OK" == "True" ]] && ok "build succeeded" || bad "build failed"
else
	bad "build → $(code): $(head -c 300 /tmp/sc.json)"
fi

say "6. status (are both sides in sync)"
api GET "/api/site/status" > /dev/null
python3 -c '
import json
d = json.load(open("/tmp/sc.json"))
drift = d.get("drift") or {}
git = d.get("git") or {}
print("     work:", d.get("work"))
print("     drift: in_sync=%s src=%s dst=%s" % (drift.get("in_sync"), drift.get("src_count"), drift.get("dst_count")))
print("     git: branch=%s dirty=%s ahead=%s behind=%s" % (git.get("branch"), git.get("dirty"), git.get("ahead"), git.get("behind")))
' 2>/dev/null || true

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
