#!/usr/bin/env bash
#
# Permission-matrix acceptance (v3 plan §3.4 / §3.5-1 / §3.7 / §7) for the app's **two** capabilities:
#
#   author          write articles in one's own author folder and submit them for review
#                   (`author_groups`; empty = every signed-in user, which is the default)
#   review+publish  the review queue, approve/reject, the offline/deletion decisions, comment
#                   moderation, AND publishing: status, log, build and push (`review_groups`). The
#                   site's own tree is not served any more: `/source` and the diff answer 404, the
#                   tree is always the caller's own author area, and its skeleton is never listed.
#                   One permission since the publish grant was merged into review
#                   ("把发布合并到审核权里"); `publish_groups` is retired and grants nothing
#                   (section 4 proves it).
#
#   sudo ./tests/live/test-permissions.sh
#
# Creates a throwaway group + user, walks the whole matrix through the real admin API (none / author /
# review, and the administrator who holds everything), and checks what each identity gets. Everything
# it creates is removed again and the previous grants are restored (trap on EXIT).
#
# Three traps this script has already fallen into, all handled below:
#   * A non-browser client must send Basic credentials + `OCS-APIREQUEST: true` on EVERY request.
#     Nextcloud does not persist a session cookie for Basic-authenticated requests, so a cookie
#     jar alone yields 401 "Current user is not logged in" on every call.
#   * "publish" is probed with an oversized commit message: the app's permission check runs first and
#     the bridge then refuses the message, so the answer is 400 for a caller who MAY publish and 403
#     for one who may not - without pushing anything to the real repository. Asserting 200 here would
#     mean really committing to it.
#   * The decision endpoint needs a path the manifest knows to answer 409 ("nothing is waiting"); this
#     matrix names a path it does not know instead, so 404 is the "the gate let this caller through"
#     answer there and 403 is "the gate refused". The administrator's approval in section 1 proves the
#     endpoint works on a real request.
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
SITE_API="/api/site"
GROUP="pages-test-group"
OTHER_GROUP="pages-test-other"
TEST_USER="pages-test-user"
TEST_PW="Pages-Test-$(date +%s)-pw"
STAMP="$(date +%Y%m%d%H%M%S)"
# The probe article lives in the test user's own author area, addressed the way every article
# endpoint addresses it (site-relative, no leading slash). The directory is *site configuration*, not
# a constant: `author_root` is read from the API below and the targets are built from it.
TARGET=""
# a post of the site itself is not needed: the matrix's decision probe names a path the manifest does
# not know, so it answers 404 ("the gate let the caller through") for a reviewer and 403 for everybody
# else - and no ownership rule can interfere: the only condition on a decision is the review grant
# (a reviewer may decide about any entry, their own included)
MISSING=""
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEST_AUTH="${TEST_AUTH:-$HERE/test-auth.sh}"

if [[ -z "${OCC:-}" ]]; then
	for candidate in "$(command -v nextcloud.occ 2>/dev/null)" /snap/bin/nextcloud.occ; do
		[[ -n "$candidate" && -x "$candidate" ]] && { OCC="$candidate"; break; }
	done
fi
OCC="${OCC:-snap run nextcloud.occ}"
occ() { $OCC "$@"; }

# the data directory, for the throwaway user's home (a deleted account can leave its folder behind)
DATA_ROOT="${DATA_ROOT:-$(occ config:system:get datadirectory 2>/dev/null)}"
DATA_ROOT="${DATA_ROOT:-/var/snap/nextcloud/common/nextcloud/data}"

dropuser() { # dropuser <uid>
	occ user:delete "$1" >/dev/null 2>&1 || true
	# a deleted account can leave its home behind, and user:add then refuses the same uid
	if [[ -n "${DATA_ROOT:-}" && -d "$DATA_ROOT/$1" ]]; then
		rm -rf "$DATA_ROOT/$1"
	fi
}

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()  { printf '  \033[32m✓\033[0m %s\n' "$*"; }
bad() { printf '  \033[31m✗\033[0m %s\n' "$*"; FAIL=$((FAIL + 1)); }
FAIL=0

OUT=/tmp/pm-out.json
ADMIN_JAR=/tmp/pm-admin.jar
USER_JAR=/tmp/pm-user.jar
PAGE=/tmp/pm-page.html
SHELL_HTML_FILE=/tmp/pm-shell.html
ADMIN_USER=""
ADMIN_PW=""
ADMIN_TOKEN=""
USER_TOKEN=""
GRANT_BEFORE='{"authorGroups":[],"reviewGroups":[]}'

# request bodies, built once: every probe names the same article the same way. Built after the admin
# session below, because the target depends on `author_root` (read from the API, never assumed).

cleanup() {
	rm -f "$ADMIN_JAR" "$USER_JAR" "$OUT" "$PAGE" "$SHELL_HTML_FILE" /tmp/pm-add.log
	# the grants first, then the probe article, then the identities: the delete endpoint is a normal
	# author call, so restoring the grant must not depend on where the test died
	[[ -n "$ADMIN_TOKEN" ]] && api_abs admin PUT /api/admin/config "$GRANT_BEFORE" >/dev/null 2>&1 || true
	[[ -n "$USER_TOKEN" ]] && api_abs user POST /api/article/cancel "$PATH_BODY" >/dev/null 2>&1 || true
	occ group:removeuser "$GROUP" "$TEST_USER" >/dev/null 2>&1 || true
	occ group:removeuser "$OTHER_GROUP" "$TEST_USER" >/dev/null 2>&1 || true
	dropuser "$TEST_USER"
	occ group:delete "$GROUP" >/dev/null 2>&1 || true
	occ group:delete "$OTHER_GROUP" >/dev/null 2>&1 || true
}
trap cleanup EXIT

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

# ---------------------------------------------------------------- request layer
_creds() { case "$1" in admin) printf '%s:%s' "$ADMIN_USER" "$ADMIN_PW" ;; user) printf '%s:%s' "$TEST_USER" "$TEST_PW" ;; esac; }
_jar()   { case "$1" in admin) printf '%s' "$ADMIN_JAR" ;; user) printf '%s' "$USER_JAR" ;; esac; }
_token() { case "$1" in admin) printf '%s' "$ADMIN_TOKEN" ;; user) printf '%s' "$USER_TOKEN" ;; esac; }

token_for() { # token_for <jar> <user> <password> → requesttoken, or an error text (non-zero exit)
	# The login page carries a requesttoken too, so verify the credentials really authenticated.
	local jar="$1" user="$2" pass="$3" code token
	rm -f "$jar"
	code=$(curl --noproxy '*' -sk -c "$jar" -u "$user:$pass" -H 'OCS-APIREQUEST: true' \
		-o "$PAGE" -w '%{http_code}' "$BASE/index.php/settings/user")
	[[ "$code" == "200" ]] || { printf 'login returned HTTP %s' "$code"; return 1; }
	grep -q 'id="body-login"\|login-form' "$PAGE" && { printf 'bounced back to the login page (bad credentials)'; return 1; }
	token=$(sed -n 's/.*data-requesttoken="\([^"]*\)".*/\1/p' "$PAGE" | head -1)
	[[ -n "$token" ]] || { printf 'logged in but the page carries no requesttoken'; return 1; }
	printf '%s' "$token"
}

_request() { # _request <admin|user> <METHOD> <app-path> [JSON] → HTTP code, body in $OUT
	local who="$1" method="$2" path="$3" body="${4:-}" jar token creds
	jar=$(_jar "$who"); token=$(_token "$who"); creds=$(_creds "$who")
	local args=(-X "$method" -H "requesttoken: $token" -H 'OCS-APIREQUEST: true'
		-H 'Content-Type: application/json' -u "$creds" -b "$jar" -c "$jar" -o "$OUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(--data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path"
}
api()     { _request "$1" "$2" "$SITE_API$3" "${4:-}"; }   # site endpoints: path relative to /api/site
api_abs() { _request "$1" "$2" "$3" "${4:-}"; }            # absolute app path (e.g. /api/article/decision)

expect() { # expect <label> <want> <got>
	if [[ "$3" == "$2" ]]; then ok "$1 → $3"
	else bad "$1 → $3 (expected $2) $(head -c 120 "$OUT" 2>/dev/null | tr -d '\n')"; fi
}

leaks() { grep -qE 'repo_url|nc_path|work_path|"recipe"|nc_user|"exclude"' "$1" 2>/dev/null; }

caps() { # caps <admin|user> → "<authoring>,<review>,<publish>" as 0/1, or "missing"
	# The capabilities endpoint is what a client reads before it offers a control, so the three
	# booleans are part of the contract - and they are per caller, not per instance. `review` and
	# `publish` are the same grant since the merge, so they must always agree.
	curl --noproxy '*' -sk -b "$(_jar "$1")" -c "$(_jar "$1")" -u "$(_creds "$1")" \
		-H 'OCS-APIREQUEST: true' -H 'Accept: application/json' \
		"$BASE/ocs/v2.php/cloud/capabilities?format=json" 2>/dev/null | python3 -c '
import json, sys
try:
    caps = (((json.load(sys.stdin).get("ocs") or {}).get("data") or {}).get("capabilities") or {})
except Exception:
    print("unreadable")
    raise SystemExit(0)
pages = caps.get("pages")
if not isinstance(pages, dict):
    print("missing")
else:
    print("%d,%d,%d" % (int(pages.get("authoring") is True), int(pages.get("review") is True), int(pages.get("publish") is True)))
'
}

caps_expect() { # caps_expect <label> <who> <authoring,review,publish>
	local got
	got=$(caps "$2")
	if [[ "$got" == "$3" ]]; then ok "$1 → authoring,review,publish = $got"
	else bad "$1 → authoring,review,publish = $got (expected $3)"; fi
}

tree_scope() { # tree_scope <admin|user> <label> <site|author>
	local who="$1" label="$2" want="$3" code scope owner body
	code=$(api "$who" GET /tree)
	body=$(cat "$OUT")
	scope=$(python3 -c 'import json;print(json.load(open("/tmp/pm-out.json")).get("scope",""))' 2>/dev/null || echo '')
	owner=$(python3 -c 'import json;print(json.load(open("/tmp/pm-out.json")).get("owner",""))' 2>/dev/null || echo '')
	if [[ "$code" != "200" ]]; then
		bad "$label → HTTP $code (a signed-in user with the authoring grant always reaches their own area)"
		return
	fi
	# One page, one tree: the caller's own author area, whatever grant they hold. The site's own source
	# is not served by any endpoint any more, so a reviewer must not get it here either.
	if [[ "$scope" != "author" ]]; then
		bad "$label → the tree answered scope '$scope' instead of the caller's own area"
		return
	fi
	if printf '%s' "$body" | grep -qE '_config\.yml|_layouts|_includes'; then
		bad "$label → the answer exposes the site skeleton"
		return
	fi
	ok "$label → own author area only (scope=author, owner=$owner)"
	expect "GET  a skeleton file (never reachable)" 403 "$(api "$who" GET "/file?path=_config.yml")"
	# a client that still sends the retired scope parameter gets the same author tree
	code=$(api "$who" GET "/tree?scope=author")
	body=$(cat "$OUT")
	scope=$(python3 -c 'import json;print(json.load(open("/tmp/pm-out.json")).get("scope",""))' 2>/dev/null || echo '')
	if [[ "$code" != "200" || "$scope" != "author" ]]; then
		bad "$label → tree?scope=author answered HTTP $code with scope '$scope'"
	elif printf '%s' "$body" | grep -qE '_config\.yml|_layouts|_includes'; then
		bad "$label → tree?scope=author fell back to the site skeleton"
	else
		ok "$label → tree?scope=author is still the caller's own area"
	fi
}

matrix() { # matrix <label> <admin|user> <none|author|review>
	local label="$1" who="$2" mode="$3" w_publish w_tree w_source w_decision w_caps w_admin
	# the capabilities, as the columns of the settings matrix and the endpoints see them. Reviewing
	# and publishing are ONE grant now, so a reviewer gets the review endpoints - and the same author
	# area as everybody else, because the site's own tree is not served by any endpoint any more.
	w_source=404   # GET /source was removed with the page it rendered
	case "$mode" in
		none)   w_tree=author; w_publish=403; w_decision=403 ;;
		author) w_tree=author; w_publish=403; w_decision=403 ;;
		review) w_tree=author; w_publish=200; w_decision=404 ;;
		*) bad "matrix: unknown mode $mode"; return ;;
	esac
	[[ "$who" == "admin" ]] && w_admin=200 || w_admin=403
	# the administrator holds every capability by identity, whatever the group lists say
	if [[ "$who" == "admin" ]]; then
		w_tree=author; w_publish=200; w_decision=404
	fi
	# authoring is implicit while author_groups is empty, so every identity here may author
	case "$mode" in
		review) w_caps="1,1,1" ;;
		*)      w_caps="1,0,0" ;;
	esac
	[[ "$who" == "admin" ]] && w_caps="1,1,1"

	say "$label"
	# status, log, the build and the publish are all the one review-and-publish grant
	expect "GET  status (review+publish)" "$w_publish" "$(api "$who" GET /status)"
	expect "GET  log (review+publish)"    "$w_publish" "$(api "$who" GET /log)"
	# the diff endpoint left with the source page's review pane: there is nothing to diff any more
	expect "GET  diff (removed)" 404 "$(api "$who" GET /diff)"
	expect "POST build (review+publish)"  "$w_publish" "$(api "$who" POST /build '{}')"
	tree_scope "$who" "GET  tree (own author area)" "$w_tree"
	expect "GET  /source (removed)" "$w_source" "$(api_abs "$who" GET /source)"

	# the decision endpoint is review-gated: a reviewer gets 404 (the manifest does not know the probe
	# path), a caller without the grant gets 403
	expect "POST article/decision (review)" "$w_decision" "$(api_abs "$who" POST /api/article/decision "$APPROVE_BODY")"

	caps_expect "capabilities ($label)" "$who" "$w_caps"

	# the publish probe: the gate answers 400 (the committed message is refused by the app itself),
	# a refusal answers 403. Never assert 200 here - that would really push to the repository.
	local code
	code=$(api "$who" POST /publish "$(python3 -c 'import json;print(json.dumps({"message":"x"*5000}))')")
	if [[ "$w_publish" == "200" ]]; then
		if [[ "$code" == "403" || "$code" == "401" ]]; then bad "POST publish → $code (a reviewer must not be refused)"
		else ok "POST publish → $code (allowed; the oversized message is refused, nothing is pushed)"; fi
	else
		expect "POST publish" 403 "$code"
	fi

	expect "POST preview-token (open to everyone)" 200 "$(api "$who" POST /preview-token)"
	expect "GET  /api/admin/config (admin only)" "$w_admin" "$(api_abs "$who" GET /api/admin/config)"

	if [[ "$who" == "admin" ]]; then
		# the administrator's answer is the whole configuration on purpose: the trimming rule is about
		# non-administrators, and those are asserted below
		return
	fi

	api_abs "$who" GET "$SITE_API" >/dev/null
	if leaks "$OUT"; then
		bad "site response leaks fields a non-admin must not see (§3.5-1)"
	else
		ok "site response trimmed (no repo_url/nc_path/work_path/recipe/nc_user/exclude)"
	fi

	if [[ "$w_publish" == "200" ]]; then
		# assert only on a real 200 - a 403 error page contains none of these strings either
		api "$who" GET /status >/dev/null
		if grep -qE '"source"|"work"|"output_dir"' "$OUT" 2>/dev/null; then
			bad "status leaks host paths (source/work/output_dir)"
		else
			ok "status carries no host paths (trimmed for non-admins)"
		fi
	fi
}

grant() { # grant <authorGroups-json> <reviewGroups-json>
	local payload code
	payload=$(python3 -c 'import json,sys;print(json.dumps({"authorGroups":json.loads(sys.argv[1]),"reviewGroups":json.loads(sys.argv[2])}))' "$1" "$2")
	code=$(api_abs admin PUT /api/admin/config "$payload")
	[[ "$code" == "200" ]] || bad "cannot set the grants (HTTP $code): $(head -c 160 "$OUT")"
}

# ---------------------------------------------------------------- setup
say "0. setup"
ADMIN_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
[[ -n "$ADMIN_USER" ]] || { bad "no Nextcloud user found"; exit 1; }
if [[ ! -f "$PW_FILE" ]]; then
	bash "$TEST_AUTH" grant >/dev/null || { bad "cannot issue an admin app password ($TEST_AUTH grant)"; exit 1; }
fi
ADMIN_PW=$(cat "$PW_FILE")
if ! ADMIN_TOKEN=$(token_for "$ADMIN_JAR" "$ADMIN_USER" "$ADMIN_PW"); then
	bad "admin login failed: $ADMIN_TOKEN"; exit 1
fi
ok "admin session ready ($ADMIN_USER)"

# The author content directory is site configuration (it was `tmp` once, later `authors-src`, and can be
# moved again): read the real value from the admin API instead of hardcoding one, and build every target
# from it. `<author_root>/<uid>/<file>` is the only form every article endpoint agrees on.
api_abs admin GET /api/site >/dev/null 2>&1 || true
AUTHOR_ROOT=$(python3 -c 'import json;print(json.load(open("/tmp/pm-out.json")).get("author_root",""))' 2>/dev/null || echo '')
[[ -n "$AUTHOR_ROOT" ]] || { bad "cannot read author_root from GET /api/site"; exit 1; }
TARGET="${AUTHOR_ROOT}/${TEST_USER}/2026-01-01-permissions-${STAMP}.md"
MISSING="${AUTHOR_ROOT}/${TEST_USER}/2026-01-01-permissions-missing-${STAMP}.md"
ok "author content directory is '$AUTHOR_ROOT' (read from the API)"

PATH_BODY=$(python3 -c 'import json,sys;print(json.dumps({"path":sys.argv[1]}))' "$TARGET")
REQUEST_ONLINE_BODY=$(python3 -c 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET")
REQUEST_OFFLINE_BODY=$(python3 -c 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"offline"}))' "$TARGET")
APPROVE_BODY=$(python3 -c 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"approve"}))' "$MISSING")
CREATE_BODY=$(python3 -c '
import json, sys
content = "---\nlayout: post\ntitle: Permissions probe\ntags: permissions\n---\n\nBody of the permissions probe.\n"
print(json.dumps({"path": sys.argv[1], "content": content}))
' "$TARGET")
# A second target that does not exist yet: the "outside author_groups" write assertion in section 7
# has to answer 403 because of the grant, not 409 because the probe file is already there. If the
# grant ever stopped gating the file endpoints, the create would succeed (200) and the extra file
# would disappear with the test user in the trap.
DENIED_BODY=$(python3 -c '
import json, sys
content = "---\nlayout: post\ntitle: Permissions probe (denied)\n---\n\nMust not be created.\n"
print(json.dumps({"path": sys.argv[1], "content": content}))
' "${TARGET%.md}-denied.md")

api_abs admin GET /api/admin/config >/dev/null 2>&1 || true
GRANT_BEFORE=$(python3 -c '
import json
try:
    c = (json.load(open("/tmp/pm-out.json")).get("config") or {})
    print(json.dumps({
        "authorGroups": c.get("author_groups") or [],
        "reviewGroups": c.get("review_groups") or [],
    }))
except Exception:
    print("{\"authorGroups\":[],\"reviewGroups\":[]}")
')
ok "grants before: $GRANT_BEFORE"

occ group:add "$GROUP" >/dev/null 2>&1 || true
occ group:add "$OTHER_GROUP" >/dev/null 2>&1 || true
dropuser "$TEST_USER"   # a leftover with a broken password poisons everything
if ! OC_PASS="$TEST_PW" occ user:add --password-from-env --display-name "Pages test user" "$TEST_USER" >/tmp/pm-add.log 2>&1; then
	OC_PASS="$TEST_PW" occ user:resetpassword --password-from-env "$TEST_USER" >/tmp/pm-add.log 2>&1 \
		|| { bad "cannot create the test user or set its password: $(tail -2 /tmp/pm-add.log)"; exit 1; }
fi
occ group:adduser "$GROUP" "$TEST_USER" >/dev/null 2>&1 || true
if ! USER_TOKEN=$(token_for "$USER_JAR" "$TEST_USER" "$TEST_PW"); then
	bad "test user login failed: $USER_TOKEN"; exit 1
fi
ok "test user session ready ($TEST_USER)"

# ---------------------------------------------------------------- the article the matrix decides about
say "1. an author writes a page and asks for the site (authoring is implicit)"
grant '[]' '[]'
expect "POST site/file in the caller's own author area" 200 "$(api_abs user POST /api/site/file "$CREATE_BODY")"
expect "POST article/request online (every signed-in user)" 200 "$(api_abs user POST /api/article/request "$REQUEST_ONLINE_BODY")"
# the administrator approves it, so the entry is external and has no request waiting
expect "POST article/decision (administrator approves)" 200 "$(api_abs admin POST /api/article/decision "$(python3 -c 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"approve"}))' "$TARGET")")"
caps_expect "capabilities (signed-in user, no grant)" user "1,0,0"

# ---------------------------------------------------------------- the matrix
# the identity that holds `author` is in the author group, so its column is checked as non-empty
# (an empty list is the "every signed-in user" default the section above already proved)
grant "[\"$GROUP\"]" '[]'
matrix "2. author only" user author

# ONE grant now: reviewing implies publishing, so this column has the build and the publish endpoint
# as well as the decision endpoint - but the site's own tree is not served by any endpoint, so its
# tree is still the caller's own author area, and /source (like the diff) answers 404. The old
# "review but not publish" column is gone on purpose - the merged permission has no such state.
grant "[\"$GROUP\"]" "[\"$GROUP\"]"
matrix "3. review (with publishing, and author)" user review

say "4. the retired publish grant grants nothing"
# A body that still names the retired key (a cached admin page, a stale script) must change no
# capability: the key is not a parameter of the update endpoint any more, so it is ignored. The
# review grant stays empty and the caller keeps the author-only column.
CODE=$(api_abs admin PUT /api/admin/config "{\"authorGroups\":[\"$GROUP\"],\"reviewGroups\":[],\"publishGroups\":[\"$GROUP\"]}")
ok "PUT with the retired publishGroups key answered HTTP $CODE (ignored)"
READ_REVIEW=$(python3 -c 'import json;print(",".join(json.load(open("/tmp/pm-out.json")).get("config",{}).get("review_groups") or []))')
READ_PUB=$(python3 -c 'import json;print(",".join(json.load(open("/tmp/pm-out.json")).get("config",{}).get("publish_groups") or []))')
if [[ -z "$READ_REVIEW" && -z "$READ_PUB" ]]; then
	ok "and it stored neither grant (review '', publish '')"
else
	bad "the retired key was stored anyway (review '$READ_REVIEW', publish '$READ_PUB')"
fi
matrix "4b. that caller still has no review or publish capability" user author
caps_expect "capabilities (retired publish key ignored)" user "1,0,0"

# the administrator holds every capability by identity, whatever the group lists say
grant '[]' '[]'
matrix "5. administrator (always both)" admin none

say "6. revoking takes effect immediately"
grant '[]' '[]'
tree_scope user "GET  tree (after revoking)" author
expect "GET  a skeleton file (after revoking)" 403 "$(api user GET "/file?path=_config.yml")"
expect "POST preview-token (still open)" 200 "$(api user POST /preview-token)"
expect "POST article/decision (after revoking)" 403 "$(api_abs user POST /api/article/decision "$APPROVE_BODY")"
expect "GET  /source (removed)" 404 "$(api_abs user GET /source)"
expect "POST article/request offline (authoring is still implicit)" 200 "$(api_abs user POST /api/article/request "$REQUEST_OFFLINE_BODY")"
expect "POST article/cancel (withdraw it again)" 200 "$(api_abs user POST /api/article/cancel "$PATH_BODY")"
caps_expect "capabilities (after revoking)" user "1,0,0"

say "7. a non-empty author_groups list restricts authoring"
# authoring gates the author's whole file API, not only the visibility request: the whole-file
# read/write/create/delete/move endpoints and the article editor sit behind the same grant and answer
# 403 outside the list, so a write (create) and a read are asserted here beside the request endpoint.
# The list is restored before anything is deleted again - a delete is an author call like every other
# file endpoint, and the delete in section 9 must not be refused by the grant this section changed.
grant "[\"$OTHER_GROUP\"]" '[]'
expect "POST article/request (outside author_groups)" 403 "$(api_abs user POST /api/article/request "$REQUEST_OFFLINE_BODY")"
expect "POST site/file create (outside author_groups)" 403 "$(api_abs user POST /api/site/file "$DENIED_BODY")"
expect "GET  site/file read (outside author_groups)" 403 "$(api user GET "/file?path=$TARGET")"
caps_expect "capabilities (outside author_groups)" user "0,0,0"
grant '[]' '[]'
expect "POST article/request (author_groups empty again)" 200 "$(api_abs user POST /api/article/request "$REQUEST_OFFLINE_BODY")"
expect "POST article/cancel (withdraw it again)" 200 "$(api_abs user POST /api/article/cancel "$PATH_BODY")"
caps_expect "capabilities (author_groups empty again)" user "1,0,0"

# ---------------------------------------------------------------- the app menu and the shell
say "8. the shell stays open to everybody, and stays trimmed"
SHELL_CODE=$(curl --noproxy '*' -sk -u "$TEST_USER:$TEST_PW" -H 'OCS-APIREQUEST: true' -o "$SHELL_HTML_FILE" -w '%{http_code}' "$BASE/index.php/apps/$APP_ID/")
SHELL_HTML=$(cat "$SHELL_HTML_FILE")
if [[ "$SHELL_CODE" == "200" ]] && printf '%s' "$SHELL_HTML" | grep -q 'pages-initial-state'; then
	ok "a user with no capability still gets the app shell (HTTP 200, initial state present)"
else
	bad "a user with no capability cannot open the app shell (HTTP $SHELL_CODE)"
fi
if [[ "$SHELL_CODE" != "200" ]]; then
	bad "the shell did not open, so 'page source is trimmed' cannot be judged (skipped)"
else
	if printf '%s' "$SHELL_HTML" | grep -qE 'repo_url|nc_path|work_path|"recipe"|nc_user'; then
		bad "the app shell's page source leaks fields (§3.5-1)"
	else
		ok "the app shell's page source is trimmed"
	fi
	if printf '%s' "$SHELL_HTML" | grep -q 'bridgeUrl'; then
		bad "the app shell still embeds bridgeUrl (config metadata must not reach non-admins)"
	else
		ok "the app shell carries no bridgeUrl/protocol"
	fi
	# the shell names the grants, so the queue, the publish button and the source link are decided
	# from real values (the JSON block is encoded with JSON_HEX_QUOT, so the keys are matched without
	# their quotes)
	for key in canAuthor canReview canEdit canPublish uid; do
		if printf '%s' "$SHELL_HTML" | grep -q "$key"; then
			ok "the shell state carries $key"
		else
			bad "the shell state does not carry $key"
		fi
	done
fi
# The app menu is a webroot map in NC 34 (`"pages":"/extra-apps/pages"`), not a list of links,
# so match the entry key instead of a URL.
NAV_HTML=$(curl --noproxy '*' -sk -u "$TEST_USER:$TEST_PW" -H 'OCS-APIREQUEST: true' "$BASE/index.php/apps/files/")
if printf '%s' "$NAV_HTML" | grep -q '"pages":"'; then
	ok "the app menu carries a Pages entry (visible to non-admins)"
else
	bad "the app menu has no Pages entry (§3.7 wants it for every signed-in user)"
fi

say "9. restore"
grant \
	"$(python3 -c 'import json,sys;print(json.dumps(json.loads(sys.argv[1])["authorGroups"]))' "$GRANT_BEFORE")" \
	"$(python3 -c 'import json,sys;print(json.dumps(json.loads(sys.argv[1])["reviewGroups"]))' "$GRANT_BEFORE")"
ok "grants restored: $GRANT_BEFORE"
# the probe article goes now (its author is deleted by the trap): deleting the file is direct, but the
# page it describes was approved and the site serves it, so the entry stays as the take-off request the
# delete expresses (`requested`, `downRequest.sourceDeleted: true`) - a delete is not a take-down
api_abs user DELETE /api/site/file "?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1], safe=""))' "$TARGET")" >/dev/null 2>&1 || true

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
