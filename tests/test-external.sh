#!/usr/bin/env bash
#
# Visibility acceptance: the three-state model (`internal` / `requested` / `external`), end to end.
#
# The rule under test is the approved one: a page is `internal` until a reviewer approves it for the
# site; the approval only decides *whether it goes live*, and the next publish is what puts it there
# (or takes it off). Everything below is asserted against what really happens - the built site, the
# git commit, the manifest file and the API - not against the app's own bookkeeping alone:
#
#   1. internal           → on the preview build, not in the commit, not in /api/articles?external=true
#   2. request(online)    → requested (intent online); a repeated request is idempotent
#   3. approve + publish  → external, in the commit, on the build, in /api/articles?external=true.
#                            The reviewer who approved it publishes it: reviewing and publishing are
#                            one grant (审核与发布), so there is no "reviewer but not publisher" case.
#   4. reject             → internal + the reviewer's note (and nothing reaches the commit)
#   5. external → 下线     → internal; the next publish drops the page from the commit (the preview
#                            build keeps showing it: an internal page stays previewable)
#   6. cancel             → back to the state the intent started from (external / internal)
#   7. a direct delete    → file gone, entry forgotten, the next publish removes it from both trees
#   8. a reviewer deciding about their own request → allowed (the old 403 was dropped on purpose:
#                            on an instance whose only reviewer is also the author, every request
#                            would be undecidable); a second decision is a 409
#
# The bridge keeps two directories per site: the commit tree (the work copy a publish commits from,
# holding the on-site files only) and the preview tree (every file, built and served as the preview).
# Every assertion below names which one it means; the build output lives in the preview tree.
#
#   sudo ./tests/test-external.sh
#
# This script really commits, because that is the property under test. It therefore repoints the site
# at a throwaway local repository and restores everything afterwards (trap on EXIT): the site config,
# the work copy's branch, the grants, and the users.
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# The bridge keeps two directories per site. The **commit** tree is the work copy (what a publish
# commits); the **preview** tree holds every author file and is what the build runs in. Both are
# configuration: the app pushes the work root and the preview directory, so both are read from the
# bridge's effective configuration instead of being guessed.
work_root() {
	python3 -c '
import json
try:
    d = json.load(open("/var/lib/pages/state/effective.json"))
except Exception:
    print("/var/lib/pages/work")
else:
    print((d.get("global") or {}).get("work_root") or "/var/lib/pages/work")' 2>/dev/null
}
preview_root() {
	python3 -c '
import json
try:
    d = json.load(open("/var/lib/pages/state/effective.json"))
except Exception:
    print("/var/lib/pages/work.preview")
else:
    site = d.get("site") or {}
    work = (d.get("global") or {}).get("work_root") or "/var/lib/pages/work"
    print(site.get("preview_dir") or (work.rstrip("/") + ".preview"))' 2>/dev/null
}
WORK="${BRIDGE_WORK:-$(work_root)}"
PREVIEW="${BRIDGE_PREVIEW:-$(preview_root)}"
AREA="${AUTHOR_AREA:-Pages}"
# the built site: what a reader would really get. It lives in the preview tree, never in the commit
# tree - the commit tree is not built at all.
BUILT="$PREVIEW/_site"

USER_A="pages-ext-a"         # author and reviewer: files their own requests, decides others'
USER_B="pages-ext-b"         # author and reviewer, like A: the two need each other's decisions
GROUP="pages-ext-review"
AUTHOR_GROUP="pages-ext-authors"
PW_A="Pages-Ext-A-$(date +%s)-pw"
PW_B="Pages-Ext-B-$(date +%s)-pw"
STAMP="$(date +%Y%m%d%H%M%S)"
NAME_1="2026-02-01-ext-internal-${STAMP}.md"
NAME_2="2026-02-02-ext-online-${STAMP}.md"
NAME_3="2026-02-03-ext-rejected-${STAMP}.md"
NAME_4="2026-02-04-ext-takedown-${STAMP}.md"
NAME_5="2026-02-05-ext-deleted-${STAMP}.md"
TARGET_1="tmp/${USER_A}/${NAME_1}"
TARGET_2="tmp/${USER_B}/${NAME_2}"
TARGET_3="tmp/${USER_A}/${NAME_3}"
TARGET_4="tmp/${USER_B}/${NAME_4}"
TARGET_5="tmp/${USER_A}/${NAME_5}"
# unique body markers: the built page is found by its own text, not by a URL this suite would have to
# compute a second time
MARK_1="MARKER-EXT-ONE-${STAMP}"
MARK_2="MARKER-EXT-TWO-${STAMP}"
MARK_3="MARKER-EXT-THREE-${STAMP}"
MARK_4="MARKER-EXT-FOUR-${STAMP}"
MARK_5="MARKER-EXT-FIVE-${STAMP}"
BARE="/tmp/pages-ext-remote.git"

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

OUT=/tmp/te.json
ADMIN_JAR=/tmp/te-admin.jar
A_JAR=/tmp/te-a.jar
B_JAR=/tmp/te-b.jar
LOGIN=/tmp/te-login.html
NC_USER=""
PW=""
TOKEN=""
SITE_JSON=""
GRANTS_BEFORE="{}"
GB_BEFORE=""
HEAD_BEFORE=""
RESTORED=0

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

cleanup() {
	if [[ "$RESTORED" -eq 0 ]]; then
		# the site configuration goes back through appconfig: this test repoints the repository, and a
		# restore must not depend on the app accepting the current shape
		[[ -n "$SITE_JSON" ]] && occ config:app:set pages site --value "$SITE_JSON" > /dev/null 2>&1
		[[ -n "$TOKEN" && -n "$GRANTS_BEFORE" ]] && req PUT /api/admin/config '' "$GRANTS_BEFORE" > /dev/null 2>&1
		[[ -n "$TOKEN" ]] && req POST /api/bridge/push '' '{}' > /dev/null 2>&1
		# the commits this script made are thrown away again: the branch goes back to where it started
		if [[ -n "$HEAD_BEFORE" && -d "$WORK/.git" ]]; then
			git -C "$WORK" reset --hard "$HEAD_BEFORE" > /dev/null 2>&1 || true
		fi
		# the authors go away first, so the published list can forget their files: without this the
		# entries would claim the site has files that the reset just removed from the commit
		for u in "$USER_A" "$USER_B"; do occ user:delete "$u" >/dev/null 2>&1 || true; done
		[[ -n "$TOKEN" ]] && req POST /api/site/publish '' '{"message":"visibility acceptance cleanup"}' > /dev/null 2>&1
		[[ -n "$TOKEN" ]] && req POST /api/site/build '' '{}' > /dev/null 2>&1
	fi
	for u in "$USER_A" "$USER_B"; do occ user:delete "$u" >/dev/null 2>&1 || true; done
	occ group:delete "$GROUP" >/dev/null 2>&1 || true
	occ group:delete "$AUTHOR_GROUP" >/dev/null 2>&1 || true
	rm -rf "$BARE" "$OUT" "$ADMIN_JAR" "$A_JAR" "$B_JAR" "$LOGIN"
}
trap cleanup EXIT

# ---------------------------------------------------------------- request layer
req() { # req <METHOD> <app-path> [query] [json] → HTTP code, body in $OUT
	local method="$1" path="$2" query="${3:-}" body="${4:-}"
	local args=(-X "$method" -b "$ADMIN_JAR" -c "$ADMIN_JAR" -u "${NC_USER}:${PW}"
		-H 'OCS-APIREQUEST: true' -H "requesttoken: $TOKEN" -o "$OUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(-H 'Content-Type: application/json' --data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path$query"
}
get()  { req GET  "$1" "${2:-}"; }
post() { req POST "$1" '' "${2:-}"; }

asreq() { # asreq <a|b> <METHOD> <app-path> [query] [json]
	local who="$1" method="$2" path="$3" query="${4:-}" body="${5:-}" jar pw uid
	case "$who" in
		a) jar="$A_JAR"; pw="$PW_A"; uid="$USER_A" ;;
		b) jar="$B_JAR"; pw="$PW_B"; uid="$USER_B" ;;
		*) bad "unknown actor $who"; return 1 ;;
	esac
	local args=(-X "$method" -b "$jar" -c "$jar" -u "${uid}:${pw}" -H 'OCS-APIREQUEST: true'
		-o "$OUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(-H 'Content-Type: application/json' --data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path$query"
}
aread() { asreq "$1" GET "$2" "${3:-}"; }
apost() { asreq "$1" POST "$2" '' "${3:-}"; }

py() { python3 -c "$1" < "$OUT" 2>/dev/null; }
pyarg() { python3 -c "$1" "$2" < "$OUT" 2>/dev/null; }
pyargs() { python3 -c "$1" "${@:2}" < "$OUT" 2>/dev/null; }
json() { python3 -c "$1" "${@:2}"; }

dav() { # dav <user> <pass> <METHOD> <path> [data]
	local user="$1" pass="$2" method="$3" path="$4" data="${5:-}"
	local args=(-X "$method" -u "$user:$pass" -o /dev/null -w '%{http_code}')
	[[ -n "$data" ]] && args+=(--data-binary "$data")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/remote.php/dav/files/$user$path"
}

mkuser() {
	occ user:delete "$1" >/dev/null 2>&1 || true
	OC_PASS="$2" occ user:add --password-from-env --display-name "Pages ext $1" "$1" >/dev/null 2>&1 \
		|| { bad "cannot create $1"; exit 1; }
	dav "$1" "$2" PUT "/pages-probe.txt" 'x' > /dev/null
}

login() { # login <jar> <user> <password> → requesttoken on stdout
	local jar="$1" user="$2" pass="$3" code token
	rm -f "$jar"
	code=$(curl --noproxy '*' -sk -c "$jar" -u "$user:$pass" -H 'OCS-APIREQUEST: true' -o "$LOGIN" \
		-w '%{http_code}' "$BASE/index.php/settings/user")
	[[ "$code" == "200" ]] || return 1
	token=$(sed -n 's/.*data-requesttoken="\([^"]*\)".*/\1/p' "$LOGIN" | head -1)
	[[ -n "$token" ]] || return 1
	printf '%s' "$token"
}

create_article() { # create_article <actor> <target> <title> <marker>
	apost "$1" /api/site/file "$(json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\nlayout: post\ntitle: %s\ntags: ext\n---\n\nBody of %s, %s.\n" % (sys.argv[2], sys.argv[2], sys.argv[3])}))
' "$2" "$3" "$4")"
}

state_of() { # state_of <actor> <target> → "<state>|<intent>|<note>"
	aread "$1" /api/articles/mine > /dev/null
	pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
want = "/" + sys.argv[1].lstrip("/")
hit = next((a for a in items if a["path"] == want), None)
print("missing" if hit is None else "%s|%s|%s" % (hit["state"], hit.get("intent", ""), hit.get("reviewNote", "")))
' "$2"
}

mine_field() { # mine_field <actor> <target> <field>
	aread "$1" /api/articles/mine > /dev/null
	pyargs '
import json, sys
items = json.load(sys.stdin).get("articles") or []
want = "/" + sys.argv[1].lstrip("/")
hit = next((a for a in items if a["path"] == want), None)
value = None if hit is None else hit.get(sys.argv[2])
print("missing" if hit is None else ("" if value is None else value))
' "$2" "$3"
}

listed() { # listed <actor> <target> [query] → how many rows of that list name the target
	aread "$1" /api/articles "${3:-}" > /dev/null
	pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
want = "/" + sys.argv[1].lstrip("/")
print(sum(1 for a in items if a["path"] == want))
' "$2"
}

# The bookkeeping is a file in the app's **own private storage** (`appdata_<instanceid>/pages/review.json`):
# not in anybody's files, so no author can edit the record of their own visibility decisions.
manifest_file() {
	local base
	base=$(ls -d /var/snap/nextcloud/common/nextcloud/data/appdata_*/pages 2>/dev/null | head -1)
	if [[ -z "$base" ]]; then
		# a fresh install: the folder appears with the first visibility action, so derive the path instead
		local id
		id=$(occ config:system:get instanceid 2>/dev/null)
		base="/var/snap/nextcloud/common/nextcloud/data/appdata_${id}/pages"
	fi
	printf '%s' "${base}/review.json"
}

manifest() { # manifest <python code with `d` bound, extra argv from $2…>
	local file
	file=$(manifest_file)
	python3 -c "
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except Exception as exc:
    print('UNREADABLE:' + type(exc).__name__)
    raise SystemExit(0)
$1
" "$file" "${@:2}"
}

manifest_article() { # manifest_article <target> <field>
	manifest 'print(((d.get("entries") or {}).get(sys.argv[2].lstrip("/")) or {}).get(sys.argv[3], ""))' "$1" "$2"
}

manifest_published() { # manifest_published <target> → the entry, or '' when it is not on the site
	manifest 'print(json.dumps((d.get("published") or {}).get(sys.argv[2].lstrip("/")) or ""))' "$1"
}

on_build() { # on_build <marker> → 1 when a built page carries that text, 0 when not
	grep -rFl -- "$1" "$BUILT" > /dev/null 2>&1 && echo 1 || echo 0
}

in_commit() { # in_commit <target> → 1 when the commit carries the file, 0 when not
	git -C "$WORK" ls-tree -r --name-only HEAD 2>/dev/null | grep -qx "$1" && echo 1 || echo 0
}

in_work_copy() { # in_work_copy <target> → 1 when the commit tree (the work copy) holds the file
	[[ -f "$WORK/$1" ]] && echo 1 || echo 0
}

in_preview_copy() { # in_preview_copy <target> → 1 when the preview tree holds the file
	[[ -f "$PREVIEW/$1" ]] && echo 1 || echo 0
}

file_code() { # file_code <actor> <target> → the HTTP code of reading that article's file
	asreq "$1" GET /api/site/file "?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote("/"+sys.argv[1], safe=""))' "$2")"
}

grant() { # grant <reviewGroups-json> — keeps the authoring grant as it was
	local payload
	payload=$(json '
import json, sys
before = json.loads(sys.argv[1] or "{}")
author = before.get("authorGroups") or []
for gid in [sys.argv[3]]:
    if gid not in author:
        author = author + [gid]
print(json.dumps({
    "authorGroups": author,
    "reviewGroups": json.loads(sys.argv[2]),
}))
' "$GRANTS_BEFORE" "$1" "$AUTHOR_GROUP")
	if [[ -z "$payload" ]]; then
		bad "cannot build the grant payload (GRANTS_BEFORE='$GRANTS_BEFORE')"
		return 1
	fi
	req PUT /api/admin/config '' "$payload" > /dev/null
}

rebuild() { # rebuild → the bridge mirrors both trees and builds the preview; prints its `ok`
	post /api/site/build '{"force":true}' > /dev/null
	python3 -c '
import json, sys
try:
    d = json.load(open("/tmp/te.json"))
except Exception:
    print("UNREADABLE")
    raise SystemExit(0)
b = d.get("build") if isinstance(d.get("build"), dict) else d
print(b.get("ok"))' 2>/dev/null
}

# ---------------------------------------------------------------- setup
say "0. sessions and a throwaway repository"
[[ ${EUID} -eq 0 && -d "$WORK/.git" ]] || { echo "no work copy at $WORK (the bridge must have built once)" >&2; exit 1; }
NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { bad "cannot issue an app password"; exit 1; }
PW=$(cat "$PW_FILE")
TOKEN=$(login "$ADMIN_JAR" "$NC_USER" "$PW") || { bad "admin login failed"; exit 1; }
HEAD_BEFORE=$(git -C "$WORK" rev-parse HEAD 2>/dev/null || echo '')

get /api/site > /dev/null
# the exact stored blob, so the restore cannot lose a field the API does not show
SITE_JSON=$(occ config:app:get pages site 2>/dev/null)
# the pushed global block is what reaches the bridge, and this suite must not change it
GB_BEFORE=$(occ config:app:get pages bridge_global 2>/dev/null)
get /api/admin/config > /dev/null
# the snapshot is snake_case, the update takes camelCase: both ends have to match or the restore is a
# silent no-op and a dead group stays in the grant (which the doctor then reports)
GRANTS_BEFORE=$(json '
import json
c = (json.load(open("/tmp/te.json")).get("config") or {})
print(json.dumps({
    "authorGroups": c.get("author_groups") or [],
    "reviewGroups": c.get("review_groups") or [],
}))
')

# a repository nobody reads: publishing must not touch the real remote
git init --bare -q "$BARE" 2>/dev/null
# the instance's working copy is a shallow clone, and git refuses to push a shallow history into a
# repository that does not accept one. This throwaway repository is ours, so it accepts the push.
git -C "$BARE" config receive.shallowupdate true 2>/dev/null || true
mkuser "$USER_A" "$PW_A"
mkuser "$USER_B" "$PW_B"
occ group:add "$GROUP" >/dev/null 2>&1 || true
occ group:adduser "$GROUP" "$USER_A" >/dev/null 2>&1 || true
occ group:adduser "$GROUP" "$USER_B" >/dev/null 2>&1 || true
occ group:add "$AUTHOR_GROUP" >/dev/null 2>&1 || true
occ group:adduser "$AUTHOR_GROUP" "$USER_A" >/dev/null 2>&1 || true
occ group:adduser "$AUTHOR_GROUP" "$USER_B" >/dev/null 2>&1 || true
grant "[\"$GROUP\"]"

REPOINTED=$(json '
import json, sys
site = json.loads(sys.argv[1])
site["repo_url"] = sys.argv[2]
print(json.dumps(site))' "$SITE_JSON" "$BARE")
occ config:app:set pages site --value "$REPOINTED" >/dev/null 2>&1
CODE=$(post /api/bridge/push '{}')
if [[ "$CODE" == "200" ]]; then
	ok "site repointed at a throwaway repository ($BARE) for the duration of this test"
else
	bad "cannot push the test configuration (HTTP $CODE): $(head -c 200 "$OUT")"
fi
[[ -n "$(login "$A_JAR" "$USER_A" "$PW_A")" ]] || { bad "$USER_A cannot log in"; exit 1; }
[[ -n "$(login "$B_JAR" "$USER_B" "$PW_B")" ]] || { bad "$USER_B cannot log in"; exit 1; }
ok "two authors who are also reviewers ($USER_A, $USER_B, groups $GROUP / $AUTHOR_GROUP) ready"

say "1. a new page is internal: the preview shows it, the site does not have it"
CODE=$(create_article a "$TARGET_1" "Ext one" "$MARK_1")
STATE=$(state_of a "$TARGET_1")
if [[ "$CODE" == "200" && "$STATE" == "internal||" ]]; then
	ok "a new page starts as internal (state '$STATE')"
else
	bad "the new page is not internal (HTTP $CODE, '$STATE')"
fi
FILE_STATE=$(manifest_article "$TARGET_1" state)
if [[ -z "$FILE_STATE" || "$FILE_STATE" == "internal" ]]; then
	# the manifest only records what was asked for or decided; an entry it does not mention is internal
	ok "the manifest has no entry for it, which reads as internal ($(manifest_file))"
else
	bad "the manifest says state='$FILE_STATE'"
fi
post /api/site/push '{}' > /dev/null
BUILD_OK=$(rebuild)
PREVIEWED=$(in_preview_copy "$TARGET_1")
WORKED=$(in_work_copy "$TARGET_1")
ONBUILD=$(on_build "$MARK_1")
COMMITTED=$(in_commit "$TARGET_1")
# The preview tree is the filesystem's truth for the preview: an internal page is mirrored there and
# built, so the preview can show it. The commit tree holds the on-site set only, so the same page must
# not be there - and the commit must not carry it.
if [[ "$BUILD_OK" == "True" && "$PREVIEWED" != "0" && "$ONBUILD" != "0" ]]; then
	ok "internal is mirrored and built (the preview shows every file; preview tree $PREVIEWED, built page $ONBUILD)"
else
	bad "internal is missing from the preview tree or the build (build '$BUILD_OK', preview tree $PREVIEWED, built $ONBUILD)"
fi
if [[ "$COMMITTED" == "0" && "$WORKED" == "0" ]]; then
	ok "and it is in neither the commit nor the commit tree"
else
	bad "an internal page reached the commit or the commit tree (in commit $COMMITTED, in commit tree $WORKED)"
fi
EXT=$(listed a "$TARGET_1" "?external=true")
ALL=$(listed a "$TARGET_1")
if [[ "$EXT" == "0" && "$ALL" == "1" ]]; then
	ok "the external filter does not list it, the full list does (reading has no visibility filter)"
else
	bad "external list shows $EXT, the full list $ALL"
fi

say "2. the author asks for the site: requested, and a repeated request is idempotent"
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_1")")
STATE=$(state_of a "$TARGET_1")
if [[ "$CODE" == "200" && "$STATE" == "requested|online|" ]]; then
	ok "requesting online puts it in requested (intent online)"
else
	bad "the online request answered HTTP $CODE / '$STATE'"
fi
FILE_STATE=$(manifest_article "$TARGET_1" state)
FILE_INTENT=$(manifest_article "$TARGET_1" intent)
if [[ "$FILE_STATE" == "requested" && "$FILE_INTENT" == "online" ]]; then
	ok "the manifest records state requested and intent online"
else
	bad "the manifest says state='$FILE_STATE', intent='$FILE_INTENT'"
fi
REQ_AT=$(manifest_article "$TARGET_1" submittedAt)
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_1")")
CREATED=$(py 'import json,sys;print(json.load(sys.stdin).get("created"))')
REQ_AT2=$(manifest_article "$TARGET_1" submittedAt)
if [[ "$CODE" == "200" && "$CREATED" == "False" && "$REQ_AT2" == "$REQ_AT" ]]; then
	ok "a second identical request is a no-op (created=false, the moment did not move)"
else
	bad "a repeated request answered HTTP $CODE / created '$CREATED', moment '$REQ_AT2' (was '$REQ_AT')"
fi
BUILD_OK=$(rebuild)
ONBUILD=$(on_build "$MARK_1")
COMMITTED=$(in_commit "$TARGET_1")
# A pending request changes the entry's state, not either tree: the page is still in the author
# area, so the preview tree keeps it and the preview build still shows it. The commit must not
# carry it - "requested" is not "approved".
if [[ "$BUILD_OK" == "True" && "$ONBUILD" == "1" && "$COMMITTED" == "0" ]]; then
	ok "a pending request puts nothing in the commit, and the preview keeps showing the page"
else
	bad "a request reached the commit ($COMMITTED) or vanished from the preview build ($ONBUILD)"
fi
aread b /api/articles "?state=requested" > /dev/null
IN_REQUESTS=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
want = "/" + sys.argv[1].lstrip("/")
print(sum(1 for a in items if a["path"] == want and a["state"] == "requested" and a.get("intent") == "online"))
' "$TARGET_1")
if [[ "$IN_REQUESTS" == "1" ]]; then
	ok "the reviewer's list (state=requested) shows it with its intent"
else
	bad "the request list does not show it with intent online (count $IN_REQUESTS)"
fi

say "3. the reviewer approves, the publish puts it on the site"
CODE=$(apost b /api/article/decision "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"approve"}))' "$TARGET_1")")
STATE=$(state_of a "$TARGET_1")
if [[ "$CODE" == "200" && "$STATE" == "external||" ]]; then
	ok "approving the online request makes it external"
else
	bad "the approval answered HTTP $CODE / '$STATE'"
fi
FILE_STATE=$(manifest_article "$TARGET_1" state)
FILE_DECIDED=$(manifest_article "$TARGET_1" decidedBy)
if [[ "$FILE_STATE" == "external" && "$FILE_DECIDED" == "$USER_B" ]]; then
	ok "the manifest says external and records the reviewer ($FILE_DECIDED)"
else
	bad "the manifest says state='$FILE_STATE', decidedBy='$FILE_DECIDED'"
fi
ONBUILD=$(on_build "$MARK_1")
# Approval changes the state, not the build: the page was built while internal too. The commit is
# what waits for a publish, so that is what this checks.
COMMITTED_PRE=$(in_commit "$TARGET_1")
if [[ "$COMMITTED_PRE" == "0" ]]; then
	ok "not in the commit before the publish (approval is not publication)"
else
	bad "the page is in the commit before a publish"
fi
# The reviewer publishes, not an administrator: reviewing and publishing are ONE grant (审核与发布)
# since the publish grant was merged into review, so the caller who approved the page is also the one
# who commits it. A "reviewer but not publisher" case does not exist any more - that is the point.
CODE=$(apost b /api/site/publish "$(json 'import json;print(json.dumps({"message":"visibility acceptance publish one"}))')")
COMMITTED=$(in_commit "$TARGET_1")
WORKED=$(in_work_copy "$TARGET_1")
PREVIEWED=$(in_preview_copy "$TARGET_1")
if [[ "$CODE" == "200" && "$COMMITTED" == "1" && "$WORKED" == "1" ]]; then
	ok "the reviewer's publish commits the external page (commit tree $WORKED, preview tree $PREVIEWED)"
else
	bad "the reviewer could not publish the external page (HTTP $CODE, in commit $COMMITTED, commit tree $WORKED)"
fi
BUILD_OK=$(rebuild)
ONBUILD=$(on_build "$MARK_1")
if [[ "$BUILD_OK" == "True" && "$ONBUILD" == "1" ]]; then
	ok "and the built site carries the page"
else
	bad "the built site does not carry the page (build '$BUILD_OK', found $ONBUILD)"
fi
EXT=$(listed a "$TARGET_1" "?external=true")
ALL=$(listed a "$TARGET_1")
LIVE=$(mine_field a "$TARGET_1" live)
if [[ "$EXT" == "1" && "$ALL" == "1" && "$LIVE" == "True" ]]; then
	ok "it is in /api/articles?external=true, in the full list, and reads live"
else
	bad "external list $EXT, full list $ALL, live '$LIVE'"
fi
FILE_LIVE=$(manifest_published "$TARGET_1")
if [[ "$FILE_LIVE" != '""' ]]; then
	ok "the manifest's published list names it with the commit"
else
	bad "the published list does not name the page"
fi

say "3b. a reviewer may decide about their own request, and a decision is not repeatable"
# This used to be a 403 ("nobody decides about their own request"). The rule was removed on purpose:
# on an instance whose only reviewer is also the author, refusing self-decisions would make every
# request undecidable, and `decidedBy` records who decided - that is the accountability that matters.
# (The UI does not offer the decision buttons on one's own row, but the endpoint allows it, and this is
# the endpoint's test.) The assertion is therefore inverted, not deleted: it pins the rule that holds.
CODE=$(create_article b "$TARGET_2" "Ext two" "$MARK_2")
CODE=$(apost b /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_2")")
CODE=$(apost b /api/article/decision "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"approve"}))' "$TARGET_2")")
STATE=$(state_of b "$TARGET_2")
DECIDED=$(manifest_article "$TARGET_2" decidedBy)
if [[ "$CODE" == "200" && "$STATE" == "external||" && "$DECIDED" == "$USER_B" ]]; then
	ok "a reviewer approves their own request (HTTP 200, external, decidedBy $DECIDED)"
else
	bad "a self-approval answered HTTP $CODE / '$STATE' (decidedBy '$DECIDED'; expected 200 and external)"
fi
CODE=$(apost a /api/article/decision "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"approve"}))' "$TARGET_2")")
STATE=$(state_of b "$TARGET_2")
if [[ "$CODE" == "409" && "$STATE" == "external||" ]]; then
	ok "a second decision about the same entry is refused (409: nothing is waiting)"
else
	bad "a second approval answered HTTP $CODE / '$STATE' (expected 409 and external)"
fi

say "4. a rejection returns it to internal with the note"
CODE=$(create_article a "$TARGET_3" "Ext three" "$MARK_3")
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_3")")
CODE=$(apost b /api/article/decision "$(json '
import json, sys
print(json.dumps({"path": sys.argv[1], "decision": "reject", "note": "Please add a real introduction."}))
' "$TARGET_3")")
STATE=$(state_of a "$TARGET_3")
if [[ "$CODE" == "200" && "$STATE" == "internal||Please add a real introduction." ]]; then
	ok "the rejected request is internal again, and the author sees the note"
else
	bad "reject answered HTTP $CODE / '$STATE'"
fi
FILE_STATE=$(manifest_article "$TARGET_3" state)
FILE_NOTE=$(manifest_article "$TARGET_3" note)
FILE_DECIDED=$(manifest_article "$TARGET_3" decidedBy)
if [[ "$FILE_STATE" == "internal" && "$FILE_NOTE" == "Please add a real introduction." && "$FILE_DECIDED" == "$USER_B" ]]; then
	ok "the manifest keeps the note and who decided"
else
	bad "manifest state='$FILE_STATE', note='$FILE_NOTE', decidedBy='$FILE_DECIDED'"
fi
EXT=$(listed a "$TARGET_3" "?external=true")
if [[ "$EXT" == "0" ]]; then
	ok "a rejected page is not in the external list"
else
	bad "a rejected page is in the external list"
fi
CODE=$(post /api/site/publish '{"message":"visibility acceptance after reject"}')
COMMITTED=$(in_commit "$TARGET_3")
if [[ "$COMMITTED" == "0" ]]; then
	ok "and it never reached the commit"
else
	bad "a rejected page was committed"
fi

say "5. the direct take-down: external -> internal -> gone from the commit, still previewable"
CODE=$(post /api/site/publish '{"message":"visibility acceptance publish two"}')
COMMITTED=$(in_commit "$TARGET_2")
if [[ "$COMMITTED" == "1" ]]; then
	ok "the second page is published (it was approved in 3b)"
else
	bad "the second page is not in the commit (HTTP $CODE)"
fi
BUILD_OK=$(rebuild)
ONBUILD=$(on_build "$MARK_2")
if [[ "$ONBUILD" == "1" ]]; then
	ok "and it is on the build"
else
	bad "the published page is not on the build"
fi
CODE=$(apost a /api/article/decision "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"reject"}))' "$TARGET_2")")
STATE=$(state_of b "$TARGET_2")
if [[ "$CODE" == "200" && "$STATE" == "internal||" ]]; then
	ok "another reviewer takes an external page down directly (state internal, no request needed)"
else
	bad "the direct take-down answered HTTP $CODE / '$STATE'"
fi
FILE_STATE=$(manifest_article "$TARGET_2" state)
if [[ "$FILE_STATE" == "internal" ]]; then
	ok "the manifest records it as internal"
else
	bad "the manifest says state='$FILE_STATE'"
fi
CODE=$(post /api/site/publish '{"message":"visibility acceptance take down"}')
STALE=$(py 'import json,sys;d=json.load(sys.stdin);print(",".join(d.get("stale") or []))')
FORGOT=$(py 'import json,sys;d=json.load(sys.stdin);print(",".join(d.get("forgotten") or []))')
if [[ "$CODE" == "200" ]] && printf '%s' ",$STALE," | grep -qF ",$TARGET_2,"; then
	ok "the publish stages the removal of the taken-down page"
else
	bad "the removal did not travel with the publish (HTTP $CODE, stale '$STALE')"
fi
if printf '%s' ",$FORGOT," | grep -qF ",$TARGET_2,"; then
	ok "and the published list forgets it"
else
	bad "the published list still names it (forgotten '$FORGOT')"
fi
COMMITTED=$(in_commit "$TARGET_2")
WORKED=$(in_work_copy "$TARGET_2")
PREVIEWED=$(in_preview_copy "$TARGET_2")
if [[ "$COMMITTED" == "0" && "$WORKED" == "0" && "$PREVIEWED" == "1" ]]; then
	ok "the commit and the commit tree no longer carry it, the preview tree still does"
else
	bad "wrong tree state after the take-down (in commit $COMMITTED, commit tree $WORKED, preview tree $PREVIEWED)"
fi
BUILD_OK=$(rebuild)
ONBUILD=$(on_build "$MARK_2")
if [[ "$ONBUILD" == "1" ]]; then
	# an internal page stays previewable: only the commit lost it
	ok "the preview still shows it - an internal page must stay previewable"
else
	bad "the taken-down page disappeared from the preview build (internal pages must stay previewable)"
fi
EXT=$(listed b "$TARGET_2" "?external=true")
ALL=$(listed b "$TARGET_2")
if [[ "$EXT" == "0" && "$ALL" == "1" ]]; then
	ok "the external filter drops it, the full list keeps it as internal"
else
	bad "external list $EXT, full list $ALL"
fi
CODE=$(post /api/site/publish '{"message":"visibility acceptance take down again"}')
STALE2=$(py 'import json,sys;d=json.load(sys.stdin);print(",".join(d.get("stale") or []))')
if ! printf '%s' "$STALE2" | grep -qF "$TARGET_2"; then
	ok "a second publish does not stage the same removal again"
else
	bad "the taken-down page comes back into every publish ('$STALE2')"
fi

say "6. a request can be withdrawn: the entry returns to the state it started from"
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"offline"}))' "$TARGET_1")")
STATE=$(state_of a "$TARGET_1")
if [[ "$CODE" == "200" && "$STATE" == "requested|offline|" ]]; then
	ok "the author asks for the external page to come off the site (requested, intent offline)"
else
	bad "the offline request answered HTTP $CODE / '$STATE'"
fi
BUILD_OK=$(rebuild)
ONBUILD=$(on_build "$MARK_1")
if [[ "$ONBUILD" == "1" ]]; then
	ok "a pending take-offline request leaves the site alone (still on the build)"
else
	bad "a pending take-offline request already took the page off the site"
fi
CODE=$(apost a /api/article/cancel "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1]}))' "$TARGET_1")")
STATE=$(state_of a "$TARGET_1")
FILE_INTENT=$(manifest_article "$TARGET_1" intent)
if [[ "$CODE" == "200" && "$STATE" == "external||" && -z "$FILE_INTENT" ]]; then
	ok "withdrawing it returns the page to external (the request is gone)"
else
	bad "the withdrawal answered HTTP $CODE / '$STATE', manifest intent '$FILE_INTENT'"
fi
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_3")")
CODE=$(apost a /api/article/cancel "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1]}))' "$TARGET_3")")
STATE=$(state_of a "$TARGET_3")
if [[ "$CODE" == "200" && "$STATE" == "internal||" ]]; then
	ok "an online request withdrawn leaves the page internal"
else
	bad "withdrawing an online request answered HTTP $CODE / '$STATE'"
fi

say "7. deleting a file is direct, and the next publish takes the page off the site"
CODE=$(create_article a "$TARGET_5" "Ext five" "$MARK_5")
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_5")")
CODE=$(apost b /api/article/decision "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"approve"}))' "$TARGET_5")")
post /api/site/publish '{"message":"visibility acceptance publish five"}' > /dev/null
BUILD_OK=$(rebuild)
COMMITTED=$(in_commit "$TARGET_5")
ONBUILD=$(on_build "$MARK_5")
if [[ "$COMMITTED" == "1" && "$ONBUILD" == "1" ]]; then
	ok "a fifth page is published and on the build"
else
	bad "the fifth page is not on the site (in commit $COMMITTED, built $ONBUILD)"
fi
CODE=$(asreq a DELETE /api/site/file "?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote("/"+sys.argv[1], safe=""))' "$TARGET_5")")
GONE=$(file_code a "$TARGET_5")
ENTRY=$(manifest_article "$TARGET_5" state)
PUBLISHED_ENTRY=$(manifest_published "$TARGET_5")
if [[ "$CODE" == "200" && "$GONE" == "404" && -z "$ENTRY" ]]; then
	ok "the delete is direct: the file is gone and the manifest entry with it"
else
	bad "the direct delete answered HTTP $CODE, file $GONE, manifest entry '$ENTRY'"
fi
if [[ "$PUBLISHED_ENTRY" != '""' ]]; then
	ok "the published list still names it - the next publish has to carry the removal"
else
	bad "the published list already forgot it, so the publish would not know to remove the page"
fi
CODE=$(post /api/site/publish '{"message":"visibility acceptance delete"}')
STALE=$(py 'import json,sys;d=json.load(sys.stdin);print(",".join(d.get("stale") or []))')
FORGOT=$(py 'import json,sys;d=json.load(sys.stdin);print(",".join(d.get("forgotten") or []))')
COMMITTED=$(in_commit "$TARGET_5")
WORKED=$(in_work_copy "$TARGET_5")
PREVIEWED=$(in_preview_copy "$TARGET_5")
if [[ "$CODE" == "200" ]] && printf '%s' ",$STALE," | grep -qF ",$TARGET_5," && [[ "$COMMITTED" == "0" ]]; then
	ok "the publish carried the removal of the deleted page out of the commit"
else
	bad "the removal did not travel with the publish (HTTP $CODE, stale '$STALE', in commit $COMMITTED)"
fi
if [[ "$WORKED" == "0" && "$PREVIEWED" == "0" ]]; then
	ok "the deleted page left both trees (the file is gone from the author area)"
else
	bad "the deleted page is still mirrored (commit tree $WORKED, preview tree $PREVIEWED)"
fi
BUILD_OK=$(rebuild)
ONBUILD=$(on_build "$MARK_5")
if [[ "$ONBUILD" == "0" ]]; then
	ok "and the rebuilt preview no longer carries it"
else
	bad "the deleted page is still on the build"
fi

say "8. the pushed bridge configuration was not touched"
GB_AFTER=$(occ config:app:get pages bridge_global 2>/dev/null)
if [[ "$GB_AFTER" == "$GB_BEFORE" ]]; then
	ok "bridge_global is byte for byte what it was (no test path leaked into exclude)"
else
	bad "the suite changed the pushed bridge configuration: '$GB_BEFORE' → '$GB_AFTER'"
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
