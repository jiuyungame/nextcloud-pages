#!/usr/bin/env bash
#
# Visibility acceptance: the folder-derived model (`internal` / `requested` / `external`), end to end.
#
# The rule under test is the approved one: a page is `internal` until a reviewer approves it for the
# site; an approval moves its frozen candidate into `online/`, and a publish is what commits that flat
# content. The site folder's three subdirectories are the record - `source/` (the skeleton),
# `online/` (the published content) and `pending/` (the frozen candidates) - and the manifest no
# longer carries a `candidate` snapshot, a `published` snapshot or a `published` list. Everything
# below is asserted against what really happens - the built site, the git commit, the two content
# directories, the manifest and the API - not against the app's own bookkeeping alone:
#
#   1. internal           → on the preview build, not in the commit, not in /api/articles?external=true
#   2. request(online)    → the bytes are frozen into `pending/<target>` and the entry records only
#                            `owner` and `candidateDigest`; the derived state **does not move**: it
#                            stays `internal` for a first publication and `external` for an update
#                            (`requested` means "a take-off request is waiting"). The pending request
#                            is read from the row's `candidate: true` or from `?awaiting=1`. A second
#                            request while one waits is a 409, not an idempotent 200.
#   3. approve + publish  → `pending/<target>` moves to `online/<target>`, the commit carries the
#                            target at its flat site-relative path, and /api/articles?external=true
#                            lists it. The approval writes no manifest fact: `online/` is the record.
#                            The reviewer who approved it publishes it: reviewing and publishing are
#                            one grant (审核与发布), so there is no "reviewer but publisher" case.
#   3b. a reviewer deciding about their own request → allowed (the old 403 was dropped on purpose:
#                            on an instance whose only reviewer is also the author, every request
#                            would be undecidable); a second decision is a 409
#   4. reject (online)    → the candidate is discarded and the page stays internal. The refusal is a
#                            recorded decision: the entry keeps the reviewer (`decidedBy`) and the
#                            note the author reads, and nothing reaches the commit.
#   5. external → 下线     → POST /api/article/take-down: the reviewer's own action, *not* a decision
#                            (a `decision/reject` about an entry with nothing pending is a 409). The
#                            bridge removes `online/<target>` and both host copies; whether the entry
#                            is forgotten is read from the author's file at decision time (it stays
#                            with its owner while the file is there), and the next publish commits the
#                            removal (the preview build keeps showing it: an internal page stays
#                            previewable).
#   5b. request(offline) → reject → external: a take-off request is one `downRequest` in the manifest
#                            (state `requested`, intent `offline`); refusing it answers the request and
#                            is not a take-down, so `online/<target>` and the commit tree stay as they
#                            were and the reviewer's note is recorded.
#   6. cancel             → back to what `online/` says (external) or internal; the `downRequest` goes
#   7. a direct delete    → the author's own file leaves the author area, the entry stays as a
#                            take-off request (`downRequest` with `sourceDeleted: true`) so a reviewer
#                            decides, and the **site keeps serving the published copy**: deleting a
#                            source file is not a take-down (the file event's `remove` drops only the
#                            author's copy in the preview tree; the online/ layer puts the page back
#                            on the next assembly), so `online/<target>`, the commit tree, the
#                            preview tree and the built page all stay, and a publish finds nothing to
#                            commit. Only the take-down clears `online/` - and the preview copy with it.
#   7b. the same delete through WebDAV (the Files app, the sync clients) → the same record, filed by
#                            the file event: the entry survives with its `downRequest`, so a reviewer
#                            can still take the page down, and the next publish carries the removal
#                            out of the commit.
#   9. pull               → the remote replaces the site's `source/` folder (on a throwaway site
#                            folder and remote)
#  10. publish (no remote) → a publish commits without a remote, and a push the remote rejects is a
#                            structured 409 that keeps the commit
#  11. drifted work tree  → a publish refuses a work tree that is not source/ + online/ (409
#                            WORK_DRIFT, nothing committed or written); Apply source is the repair
#  12. bridge config      → the pushed bridge configuration is byte for byte unchanged
#
# The bridge keeps two directories per site: the commit tree (the work copy a publish commits from,
# holding the on-site files only) and the preview tree (source/ + online/ + the author's files, built
# and served as the preview). The preview tree therefore carries the online layer as well, so a page
# the site still serves stays in it even after its author deletes the source file.
# Every assertion below names which one it means; the build output lives in the preview tree.
#
#   sudo ./tests/live/test-external.sh
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
# commits); the **preview** tree is source/ + online/ + the author's files, and is what the build runs
# in. Both are configuration: the app pushes the work root and the preview directory, so both are read
# from the bridge's effective configuration instead of being guessed.
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
NAME_6="2026-02-06-ext-webdav-deleted-${STAMP}.md"
# The site targets of the probe pages are `<author_root>/<uid>/<file>`: `author_root` is site
# configuration (it was `tmp` once, later `authors-src`, and this suite moves it on purpose), so the
# targets are built after the admin session reads the real value below.
TARGET_1=""
TARGET_2=""
TARGET_3=""
TARGET_4=""
TARGET_5=""
TARGET_6=""
# unique body markers: the built page is found by its own text, not by a URL this suite would have to
# compute a second time
MARK_1="MARKER-EXT-ONE-${STAMP}"
MARK_2="MARKER-EXT-TWO-${STAMP}"
MARK_3="MARKER-EXT-THREE-${STAMP}"
MARK_4="MARKER-EXT-FOUR-${STAMP}"
MARK_5="MARKER-EXT-FIVE-${STAMP}"
MARK_6="MARKER-EXT-SIX-${STAMP}"
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
# the data directory, for the probe users' homes (a deleted account can leave its folder behind)
DATA_ROOT="${DATA_ROOT:-$(occ config:system:get datadirectory 2>/dev/null)}"
DATA_ROOT="${DATA_ROOT:-/var/snap/nextcloud/common/nextcloud/data}"
GRANTS_BEFORE="{}"
GB_BEFORE=""
HEAD_BEFORE=""
ROOT_BEFORE=""
RESTORED=0
# set while section 11's hand-edits are in the commit tree: the EXIT trap rebuilds the tree if the run
# dies before Apply source/ the final publish recovered it
DRIFT_ACTIVE=0
# the app-data folder of *this* instance, resolved on first use (see manifest_file)
INSTANCE_ID=""

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

cleanup() {
	if [[ "$RESTORED" -eq 0 ]]; then
		# the site configuration goes back through appconfig: this test repoints the repository, and a
		# restore must not depend on the app accepting the current shape
		[[ -n "$SITE_JSON" ]] && occ config:app:set pages site --value "$SITE_JSON" > /dev/null 2>&1
		# section 11 edits `work` by hand. If the run died before Apply source recovered it, rebuild the
		# commit tree here: the next suite must not start on a drifted one.
		if [[ "${DRIFT_ACTIVE:-0}" == "1" && -n "$TOKEN" ]]; then
			req POST /api/site/build '' '{"force":true}' > /dev/null 2>&1
			DRIFT_ACTIVE=0
		fi
		[[ -n "$TOKEN" && -n "$GRANTS_BEFORE" ]] && req PUT /api/admin/config '' "$GRANTS_BEFORE" > /dev/null 2>&1
		[[ -n "$TOKEN" ]] && req POST /api/bridge/push '' '{}' > /dev/null 2>&1
		# the commits this script made are thrown away again: the branch goes back to where it started
		if [[ -n "$HEAD_BEFORE" && -d "$WORK/.git" ]]; then
			git -C "$WORK" reset --hard "$HEAD_BEFORE" > /dev/null 2>&1 || true
		fi
		# the authors go away first, so their entries and the files they published leave together:
		# without this the manifest would keep decisions about files the reset just removed from the
		# commit (online/ is the record, and the reset does not touch it)
		for u in "$USER_A" "$USER_B"; do
		occ user:delete "$u" >/dev/null 2>&1 || true
		[[ -n "${DATA_ROOT:-}" && -d "$DATA_ROOT/$u" ]] && rm -rf "$DATA_ROOT/$u"
	done
		[[ -n "$TOKEN" ]] && req POST /api/site/publish '' '{"message":"visibility acceptance cleanup"}' > /dev/null 2>&1
		[[ -n "$TOKEN" ]] && req POST /api/site/build '' '{}' > /dev/null 2>&1
		# the author content directory is moved in section 1: restore it through the channel that
		# accepts a move even with registered content, so a failure cannot strand the instance there
		if [[ -n "$TOKEN" && -n "$ROOT_BEFORE" ]]; then
			req POST /api/site/author-root '' "$(python3 -c 'import json,sys;print(json.dumps({"authorRoot":sys.argv[1],"confirm":True}))' "$ROOT_BEFORE")" > /dev/null 2>&1 || true
		fi
	fi
	for u in "$USER_A" "$USER_B"; do
		occ user:delete "$u" >/dev/null 2>&1 || true
		[[ -n "${DATA_ROOT:-}" && -d "$DATA_ROOT/$u" ]] && rm -rf "$DATA_ROOT/$u"
	done
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
put()  { req PUT  "$1" '' "${2:-}"; }

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
	# a deleted account can leave its home behind, and user:add then refuses the same uid
	if [[ -n "${DATA_ROOT:-}" && -d "$DATA_ROOT/$1" ]]; then
		rm -rf "$DATA_ROOT/$1"
	fi
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
	# `state` and `intent` are **derived** values in the API: `downRequest` makes it `requested` /
	# `offline`, a candidate in `pending/` makes the intent `online` without moving the state, and
	# `online/` makes it `external`. None of that is stored, which is why the direction is read here
	# and not out of the manifest
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
	# The instance id is the authority: `appdata_<instanceid>` is *this* install's app-data folder, and a
	# stale `appdata_*` left behind by an earlier instance must not be read instead. Resolved once
	# (every manifest read asks for the path) and used only while that folder really exists.
	if [[ -z "$INSTANCE_ID" ]]; then
		INSTANCE_ID=$(occ config:system:get instanceid 2>/dev/null || true)
	fi
	if [[ -n "$INSTANCE_ID" && -d "/var/snap/nextcloud/common/nextcloud/data/appdata_${INSTANCE_ID}/pages" ]]; then
		printf '%s' "/var/snap/nextcloud/common/nextcloud/data/appdata_${INSTANCE_ID}/pages/review.json"
		return
	fi
	local base
	base=$(ls -d /var/snap/nextcloud/common/nextcloud/data/appdata_*/pages 2>/dev/null | head -1)
	if [[ -z "$base" ]]; then
		# a fresh install: the folder appears with the first visibility action, so derive the path instead
		base="/var/snap/nextcloud/common/nextcloud/data/appdata_${INSTANCE_ID}/pages"
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
	local value
	value=$(manifest 'print(((d.get("entries") or {}).get(sys.argv[2].lstrip("/")) or {}).get(sys.argv[3], ""))' "$1" "$2")
	# A manifest that is not there yet decides nothing (the first review action creates it), so a missing
	# file reads as "no entry" here. A manifest that exists but cannot be read is a different thing, and
	# section 0b reports it; answering "" as well keeps a single missing value from failing every check
	# that follows it, and every check that expects a *value* still fails on its own comparison.
	case "$value" in
		UNREADABLE:*) printf '' ;;
		*) printf '%s' "$value" ;;
	esac
}

# The manifest records only what the folders cannot: `owner`, `candidateDigest`, `downRequest`,
# `decidedBy` and `note`. Whether the article is on the site (`online/<target>`) and whether a
# candidate waits (`pending/<target>`) are the folders' answers, so the only snapshot left to read a
# moment from is the take-off request. 0 means there is none (or the entry is gone, which reads the
# same way).
manifest_at() { # manifest_at <target> <downRequest> → the moment, 0 when absent
	local at
	at=$(manifest '
import json, sys
entry = (d.get("entries") or {}).get(sys.argv[2].lstrip("/")) or {}
snap = entry.get(sys.argv[3])
moment = snap.get("at", 0) if isinstance(snap, dict) else 0
print(moment if isinstance(moment, int) and moment > 0 else 0)
' "$1" "$2")
	# A manifest that cannot be read answers 0 here: every check that depends on it fails anyway, and a
	# non-number would turn the `-gt` comparisons into an arithmetic error, which `set -u` makes fatal.
	[[ "$at" =~ ^[0-9]+$ ]] || at=0
	printf '%s' "$at"
}

manifest_field() { # manifest_field <target> <field> → that value as compact JSON, `null` when absent
	local value
	value=$(manifest '
import json, sys
entry = (d.get("entries") or {}).get(sys.argv[2].lstrip("/")) or {}
print(json.dumps(entry.get(sys.argv[3]), sort_keys=True))
' "$1" "$2")
	# nothing recorded - the entry is not there, or it does not carry that field - is `null`, which is
	# how ReviewStore::normaliseEntry() reads it as well. An unreadable manifest records nothing either;
	# section 0b is where that is reported
	case "$value" in
		UNREADABLE:*) printf 'null' ;;
		*) printf '%s' "$value" ;;
	esac
}

manifest_source_deleted() { # manifest_source_deleted <target> → True when the down request says the file is gone
	local value
	value=$(manifest '
import json, sys
entry = (d.get("entries") or {}).get(sys.argv[2].lstrip("/")) or {}
down = entry.get("downRequest")
print(str(isinstance(down, dict) and down.get("sourceDeleted") is True))
' "$1")
	# the same reading as ReviewStore::downRequestOf(): only a JSON `true` is a deleted source, so a
	# junk value - or no down request at all - answers False, and a check that expects a deleted source
	# fails instead of passing on any truthy spelling
	case "$value" in
		True) printf 'True' ;;
		*) printf 'False' ;;
	esac
}

manifest_has_entry() { # manifest_has_entry <target> → 1 when the manifest has an entry for it, 0 when not
	local value
	value=$(manifest '
import json, sys
print("1" if sys.argv[2].lstrip("/") in (d.get("entries") or {}) else "0")
' "$1")
	# `manifest_article()` answers '' both for an entry that is not there and for a field the entry does
	# not carry, and a forgotten entry (a reviewer's take-down of a page whose file is gone) reads the
	# same way. This is the check that tells them apart; an unreadable manifest answers neither 1 nor 0,
	# so it cannot pass for "the entry is gone".
	case "$value" in
		1) printf '1' ;;
		0) printf '0' ;;
		*) printf '?' ;;
	esac
}

manifest_digest() { # manifest_digest <target> → the sha256 the request bound to the frozen candidate
	local value
	value=$(manifest '
import json, sys
entry = (d.get("entries") or {}).get(sys.argv[2].lstrip("/")) or {}
print(entry.get("candidateDigest", ""))
' "$1")
	# same reading as manifest_article(): nothing recorded is '', and an unreadable manifest records
	# nothing either - section 0b is where a broken manifest is reported
	case "$value" in
		UNREADABLE:*) printf '' ;;
		*) printf '%s' "$value" ;;
	esac
}

manifest_keys() { # manifest_keys <target> → the entry's field names, sorted, comma separated
	local value
	value=$(manifest '
import json, sys
entry = (d.get("entries") or {}).get(sys.argv[2].lstrip("/")) or {}
print(",".join(sorted(entry)))
' "$1")
	# an entry the manifest does not carry has no keys, so a caller that requires the shape can tell
	# "absent" from "the wrong fields" by an empty answer as well
	case "$value" in
		UNREADABLE:*) printf '' ;;
		*) printf '%s' "$value" ;;
	esac
}

on_build() { # on_build <marker> → 1 when a built page carries that text, 0 when not
	grep -rFl -- "$1" "$BUILT" > /dev/null 2>&1 && echo 1 || echo 0
}

in_commit() { # in_commit <target> → 1 when the commit carries the file, 0 when not
	local tree
	tree=$(git -C "$WORK" ls-tree -r --name-only HEAD 2>/dev/null)
	grep -qx "$1" <<<"$tree" && echo 1 || echo 0
}

in_work_copy() { # in_work_copy <target> → 1 when the commit tree (the work copy) holds the file
	[[ -f "$WORK/$1" ]] && echo 1 || echo 0
}

in_preview_copy() { # in_preview_copy <target> → 1 when the preview tree holds the file
	[[ -f "$PREVIEW/$1" ]] && echo 1 || echo 0
}

# Since the manifest stopped carrying a candidate snapshot and a published list, the site folder's
# `online/` and `pending/` are the record: `online/<target>` exists exactly while the site serves the
# page, `pending/<target>` exactly while a candidate waits. The bridge writes both itself (a rename on
# the host, no Nextcloud file cache), so the suite reads them off the disk - that is what the bridge
# wrote. SITE_DIR is set once nc_path is known, below.
in_site_online() { # in_site_online <target> → 1 when online/<target> exists
	[[ -f "$SITE_DIR/online/$1" ]] && echo 1 || echo 0
}

in_site_pending() { # in_site_pending <target> → 1 when pending/<target> exists
	[[ -f "$SITE_DIR/pending/$1" ]] && echo 1 || echo 0
}

in_site_layer() { # in_site_layer <online|pending> <target> → 1 when that layer holds the target
	[[ -f "$SITE_DIR/$1/$2" ]] && echo 1 || echo 0
}

# The targets one layer holds under a prefix, one per line: what a move of the author content
# directory has to carry with it (the move rewrites the files, so they are read before it happens).
layer_targets() { # layer_targets <online|pending> <prefix> → the targets under that prefix
	[[ -d "$SITE_DIR/$1" ]] || return 0
	(cd "$SITE_DIR/$1" 2>/dev/null && find "${2%/}" -type f 2>/dev/null | LC_ALL=C sort)
}

layer_reroot() { # layer_reroot <from-prefix> <to-prefix> <targets> → the same targets under the new name
	local from="${1%/}" to="${2%/}" target
	while IFS= read -r target; do
		[[ -n "$target" ]] || continue
		printf '%s/%s\n' "$to" "${target#"$from"/}"
	done <<< "${3:-}"
}

# A move of the author content directory has to take the files with it, not only the setting: every
# target listed under <from-prefix> must be gone from there and present under <to-prefix>. Prints
# `<checked>|<wrong>`, where the second number is failed checks (a target still under the old name or
# missing under the new one), so the caller can report both numbers instead of a bare pass/fail.
layer_moved() { # layer_moved <online|pending> <from-prefix> <to-prefix> <targets> → "<checked>|<wrong>"
	local layer="$1" from="${2%/}" to="${3%/}" target relocated checked=0 wrong=0
	while IFS= read -r target; do
		[[ -n "$target" ]] || continue
		relocated="$to/${target#"$from"/}"
		checked=$((checked + 1))
		[[ "$(in_site_layer "$layer" "$target")" == "0" ]] || wrong=$((wrong + 1))
		[[ "$(in_site_layer "$layer" "$relocated")" == "1" ]] || wrong=$((wrong + 1))
	done <<< "${4:-}"
	printf '%s|%s' "$checked" "$wrong"
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

rebuild() { # rebuild → the bridge reassembles the trees and builds the preview; prints its `ok`
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

repoint_site() { # repoint_site <nc_path> <repo_url> → stored blob + bridge push
	# The HTTP route is locked once content exists: by design it refuses a folder change while the
	# manifest holds entries (see ContentLock), and this suite repoints the site after publishing. The
	# harness therefore writes the same appconfig value an administrator would set with `occ`, keeping
	# every field of the stored blob and replacing only the two the test changes. The lock itself is
	# asserted against the HTTP route in verify-admin.sh.
	local path="$1" repo="$2" body code
	body=$(json '
import json, sys
site = json.loads(sys.argv[3])
site["nc_path"] = sys.argv[1]
site["repo_url"] = sys.argv[2]
print(json.dumps(site, ensure_ascii=False))' "$path" "$repo" "$SITE_JSON")
	occ config:app:set pages site --value "$body" >/dev/null 2>&1 \
		|| { bad "could not store the repointed site (nc_path $path)"; return 1; }
	# Nextcloud's appconfig fast cache is per process: a CLI write becomes visible to the web process a
	# few seconds later, so wait until the app really reports the new value before pushing it (otherwise
	# the bridge would be handed the old configuration).
	local app_path app_repo
	for _ in $(seq 1 16); do
		get /api/site > /dev/null
		app_path=$(py 'import json,sys;print(((json.load(sys.stdin).get("site") or {}).get("nc_path")) or "")')
		app_repo=$(py 'import json,sys;print(((json.load(sys.stdin).get("site") or {}).get("repo_url")) or "")')
		[[ "$app_path" == "$path" && "$app_repo" == "$repo" ]] && break
		sleep 0.5
	done
	[[ "$app_path" == "$path" && "$app_repo" == "$repo" ]] \
		|| { bad "the app did not adopt the repointed site (nc_path '$app_path', repo '$app_repo')"; return 1; }
	code=$(post /api/bridge/push '{}')
	[[ "$code" == "200" ]] || { bad "the bridge push answered HTTP $code: $(head -c 200 "$OUT")"; return 1; }
	return 0
}

# ---------------------------------------------------------------- setup
say "0. sessions and a throwaway repository"
[[ ${EUID} -eq 0 && -d "$WORK/.git" ]] || { echo "no work copy at $WORK (the bridge must have built once)" >&2; exit 1; }
NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { bad "cannot issue an app password"; exit 1; }
PW=$(cat "$PW_FILE")
TOKEN=$(login "$ADMIN_JAR" "$NC_USER" "$PW") || { bad "admin login failed"; exit 1; }
# resolved once here: manifest_file() is asked for its path many times, and one `occ` boot per read
# would be pure overhead
INSTANCE_ID=$(occ config:system:get instanceid 2>/dev/null || true)
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

# The author content directory is the real, configured one: the probe targets are `<author_root>/<uid>/…`
# and the move/restore below is asserted against the value that was found, never against a literal.
AUTHOR_ROOT=$(json 'import json;print((json.load(open("/tmp/te.json")).get("config") or {}).get("author_root",""))')
[[ -n "$AUTHOR_ROOT" ]] || { bad "cannot read author_root from GET /api/admin/config"; exit 1; }
TARGET_1="$AUTHOR_ROOT/${USER_A}/${NAME_1}"
TARGET_2="$AUTHOR_ROOT/${USER_B}/${NAME_2}"
TARGET_3="$AUTHOR_ROOT/${USER_A}/${NAME_3}"
TARGET_4="$AUTHOR_ROOT/${USER_B}/${NAME_4}"
TARGET_5="$AUTHOR_ROOT/${USER_A}/${NAME_5}"
TARGET_6="$AUTHOR_ROOT/${USER_A}/${NAME_6}"
ok "author content directory is '$AUTHOR_ROOT' (read from the API)"

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

REAL_SITE_PATH=$(json 'import json,sys;print(json.loads(sys.argv[1]).get("nc_path") or "")' "$SITE_JSON")
# The site folder on disk: its `source/`, `online/` and `pending/` children are the layout, and the
# last two are what the assertions below read instead of a manifest list. The storage user is the
# site's own (`nc_user`), not "whichever account the suite logged in as".
SITE_USER=$(json 'import json,sys;print(json.loads(sys.argv[1]).get("nc_user") or "")' "$SITE_JSON")
SITE_USER="${SITE_USER:-$NC_USER}"
SITE_DIR="$DATA_ROOT/$SITE_USER/files$REAL_SITE_PATH"
# repoint_site() waits until the web process really reads the new appconfig value, then pushes it
if repoint_site "$REAL_SITE_PATH" "$BARE"; then
	ok "site repointed at a throwaway repository ($BARE) for the duration of this test"
else
	bad "cannot push the test configuration: $(head -c 200 "$OUT")"
fi

# the remote and the branch are locked once the publish repository exists: the API refuses a change
# (the page renders both disabled; this is the server-side half)
get /api/site/status > /dev/null
INIT_STATUS=$(py 'import json,sys;print(json.load(sys.stdin).get("initialized"))')
if [[ "$INIT_STATUS" == "True" ]]; then
	get /api/site > /dev/null
	LOCK_BODY=$(python3 -c '
import json, sys
s = json.loads(sys.argv[1]).get("site") or {}
s["repo_url"] = "git@example.invalid:te/other.git"
print(json.dumps(s, ensure_ascii=False))
' "$(cat "$OUT")")
	CODE=$(put /api/site "$LOCK_BODY")
	if [[ "$CODE" == "400" ]] && grep -q "initialised" "$OUT"; then
		ok "PUT /api/site rejects a remote change once the repository is initialised (400: $(head -c 80 "$OUT")…)"
	else
		bad "the initialised remote lock did not reject the change (HTTP $CODE: $(head -c 120 "$OUT"))"
	fi
else
	bad "the bridge did not report an initialised repository, so the remote lock cannot be checked"
fi
[[ -n "$(login "$A_JAR" "$USER_A" "$PW_A")" ]] || { bad "$USER_A cannot log in"; exit 1; }
[[ -n "$(login "$B_JAR" "$USER_B" "$PW_B")" ]] || { bad "$USER_B cannot log in"; exit 1; }
ok "two authors who are also reviewers ($USER_A, $USER_B, groups $GROUP / $AUTHOR_GROUP) ready"

# ---------------------------------------------------------------- the record is the folders
# There is no published list to settle any more: online/<target> is the record, and the commit tree is
# derived from source/ + online/, so a publish can only carry a removal the folders justify. Section 10
# writes its probe into source/, which never enters online/: an interrupted run can at most leave the
# source file (section 10 overwrites and deletes it) or a removal the next publish commits, neither of
# which pollutes a later run. What is checked here is the shape the rest of the suite relies on.
STALE_PROBE="publish-probe.md"
MANIFEST_HAS_PUBLISHED=$(manifest 'print("yes" if isinstance(d.get("published"), (dict, list)) else "no")')
PROBE_ONLINE=$(in_site_online "$STALE_PROBE")
if [[ -d "$SITE_DIR/source" ]]; then
	ok "the site folder has its source/ skeleton ($SITE_DIR/source)"
else
	bad "the site folder has no source/ directory ($SITE_DIR); the layout the suite is written against is not in place"
fi
if [[ ! -f "$(manifest_file)" ]]; then
	# a fresh install has no review.json until the first visibility action
	ok "there is no visibility manifest yet, so online/ is the whole published record"
elif [[ "$MANIFEST_HAS_PUBLISHED" == "no" && "$PROBE_ONLINE" == "0" ]]; then
	ok "the manifest carries no published list and the site does not serve '$STALE_PROBE' (online/ is the record)"
else
	bad "the manifest still carries a published list ('$MANIFEST_HAS_PUBLISHED') or online/ serves '$STALE_PROBE'"
fi

say "1. a new page is internal: the preview shows it, the site does not have it"
CODE=$(create_article a "$TARGET_1" "Ext one" "$MARK_1")
STATE=$(state_of a "$TARGET_1")
if [[ "$CODE" == "200" && "$STATE" == "internal||" ]]; then
	ok "a new page starts as internal (state '$STATE')"
else
	bad "the new page is not internal (HTTP $CODE, '$STATE')"
fi
FILE_ENTRY=$(manifest_has_entry "$TARGET_1")
if [[ "$FILE_ENTRY" == "0" ]]; then
	# the manifest only records what was asked for or decided; an article it does not mention is
	# internal, and the folders are the visibility record
	ok "the manifest has no entry for it, which reads as internal ($(manifest_file))"
else
	bad "the manifest already has an entry for a new page ('$FILE_ENTRY')"
fi
post /api/site/push '{}' > /dev/null
# the author content directory has its own channel: shape only; an existing directory in the site
# needs an explicit confirmation. The move is accepted even with registered author content, and it
# carries that content: every published and waiting file under the old directory is rewritten under
# the new one. The files are read before the move, because the move is what takes them away (a fresh
# instance has none yet; a repeated run has the pages an earlier run left in `online/`).
get /api/admin/config > /dev/null
ROOT_BEFORE=$(py 'import json,sys;print(json.load(sys.stdin)["config"].get("author_root",""))')
MOVED_ROOT="pages-ext-moved"
ONLINE_MOVING=$(layer_targets online "$ROOT_BEFORE")
PENDING_MOVING=$(layer_targets pending "$ROOT_BEFORE")
CODE=$(post /api/site/author-root "$(json 'import json,sys;print(json.dumps({"authorRoot":"pages-ext-moved"}))')")
# the answer's own account of the move: how many files followed the directory in each layer
MOVED_COUNTS=$(py 'import json,sys
d = json.load(sys.stdin)
m = d.get("moved") or {}
print("%s|%s" % (m.get("online", "?"), m.get("pending", "?")))')
get /api/admin/config > /dev/null
ROOT_MOVED=$(py 'import json,sys;print(json.load(sys.stdin)["config"].get("author_root",""))')
MOVED_ONLINE=$(layer_moved online "$ROOT_BEFORE" "$MOVED_ROOT" "$ONLINE_MOVING")
MOVED_PENDING=$(layer_moved pending "$ROOT_BEFORE" "$MOVED_ROOT" "$PENDING_MOVING")
if [[ "$CODE" == "200" && "$ROOT_MOVED" == "$MOVED_ROOT" ]]; then
	ok "a move to a nonexistent name is accepted even with registered author content (root now '$ROOT_MOVED')"
else
	bad "the author-directory move is wrong (HTTP $CODE, root '$ROOT_MOVED', expected '$MOVED_ROOT')"
fi
if [[ "$MOVED_COUNTS" == "${MOVED_ONLINE%%|*}|${MOVED_PENDING%%|*}" ]]; then
	ok "and the answer reports the same counts the layers held (moved '$MOVED_COUNTS', online|pending)"
else
	bad "the move answer reports '$MOVED_COUNTS', the layers held '${MOVED_ONLINE%%|*}|${MOVED_PENDING%%|*}' (online|pending)"
fi
# A move that only renamed the setting would leave the published and waiting files on the old
# address, where no review action accepts them any more. The two numbers are the files checked and
# the failed checks (a file still under the old name, or one that never arrived under the new one).
if [[ "${MOVED_ONLINE#*|}" == "0" && "${MOVED_PENDING#*|}" == "0" ]]; then
	ok "the move carried the content with it (${MOVED_ONLINE%%|*} published and ${MOVED_PENDING%%|*} waiting file(s) moved to '$MOVED_ROOT', none left under '$ROOT_BEFORE')"
else
	bad "the move did not carry the content (online '<checked>|<wrong>' $MOVED_ONLINE, pending $MOVED_PENDING; expected '<n>|0' for both)"
fi
ONLINE_AT_MOVED=$(layer_reroot "$ROOT_BEFORE" "$MOVED_ROOT" "$ONLINE_MOVING")
PENDING_AT_MOVED=$(layer_reroot "$ROOT_BEFORE" "$MOVED_ROOT" "$PENDING_MOVING")
CODE_BACK=$(post /api/site/author-root "$(json 'import json,sys;print(json.dumps({"authorRoot":sys.argv[1],"confirm":True}))' "$ROOT_BEFORE")")
BACK_COUNTS=$(py 'import json,sys
d = json.load(sys.stdin)
m = d.get("moved") or {}
print("%s|%s" % (m.get("online", "?"), m.get("pending", "?")))')
get /api/admin/config > /dev/null
ROOT_AFTER=$(py 'import json,sys;print(json.load(sys.stdin)["config"].get("author_root",""))')
BACK_ONLINE=$(layer_moved online "$MOVED_ROOT" "$ROOT_BEFORE" "$ONLINE_AT_MOVED")
BACK_PENDING=$(layer_moved pending "$MOVED_ROOT" "$ROOT_BEFORE" "$PENDING_AT_MOVED")
if [[ "$CODE_BACK" == "200" && "$ROOT_AFTER" == "$ROOT_BEFORE" ]]; then
	ok "moving it back is accepted too (root '$ROOT_AFTER')"
else
	bad "the restore move failed (HTTP $CODE_BACK, root '$ROOT_AFTER', expected '$ROOT_BEFORE')"
fi
if [[ "$BACK_COUNTS" == "${BACK_ONLINE%%|*}|${BACK_PENDING%%|*}" ]]; then
	ok "and that answer reports the counts the layers held on the way back (moved '$BACK_COUNTS', online|pending)"
else
	bad "the restore answer reports '$BACK_COUNTS', the layers held '${BACK_ONLINE%%|*}|${BACK_PENDING%%|*}' (online|pending)"
fi
if [[ "${BACK_ONLINE#*|}" == "0" && "${BACK_PENDING#*|}" == "0" ]]; then
	ok "and it carried the content back (${BACK_ONLINE%%|*} published and ${BACK_PENDING%%|*} waiting file(s) under '$ROOT_BEFORE' again)"
else
	bad "the content did not come back (online '<checked>|<wrong>' $BACK_ONLINE, pending $BACK_PENDING; expected '<n>|0' for both)"
fi
# the confirm-on-existing rule: the bridge answers 409 CONFIRM_REQUIRED when the directory a move
# names already sits inside the site. Whether the installation happens to ship an `authors-src` or a
# `documents` folder is an accident, and looking for one made this case pass for the wrong reason (the
# folder was usually absent, so the move was a plain 200). The suite creates the directory itself,
# through the same WebDAV the site's own writers use, and removes it again at the end.
CONFIRM_DIR="${AUTHOR_ROOT}-confirm-${STAMP}"
CODE=$(dav "$NC_USER" "$PW" MKCOL "$REAL_SITE_PATH/$CONFIRM_DIR")
if [[ "$CODE" == "201" || "$CODE" == "405" ]]; then
	CODE_CONFLICT=$(post /api/site/author-root "$(json 'import json,sys;print(json.dumps({"authorRoot":sys.argv[1]}))' "$CONFIRM_DIR")")
	CONFLICT_CODE=$(py 'import json,sys;print(json.load(sys.stdin).get("error",""))')
	CODE_CONFIRM=$(post /api/site/author-root "$(json 'import json,sys;print(json.dumps({"authorRoot":sys.argv[1],"confirm":True}))' "$CONFIRM_DIR")")
	CODE_RESTORE=$(post /api/site/author-root "$(json 'import json,sys;print(json.dumps({"authorRoot":sys.argv[1],"confirm":True}))' "$ROOT_BEFORE")")
	get /api/admin/config > /dev/null
	ROOT_FINAL=$(py 'import json,sys;print(json.load(sys.stdin)["config"].get("author_root",""))')
	if [[ "$CODE_CONFLICT" == "409" && "$CONFLICT_CODE" == "CONFIRM_REQUIRED" && "$CODE_CONFIRM" == "200" \
		&& "$CODE_RESTORE" == "200" && "$ROOT_FINAL" == "$ROOT_BEFORE" ]]; then
		ok "an existing site directory needs confirm:true (409 CONFIRM_REQUIRED, then accepted and restored)"
	else
		bad "the confirm-on-existing flow is wrong (409=$CODE_CONFLICT/$CONFLICT_CODE, confirm=$CODE_CONFIRM, restore=$CODE_RESTORE, root '$ROOT_FINAL')"
	fi
	# the probe directory must not survive: it lives inside the site folder, and leaving it would make
	# a later run see a directory nobody meant to have
	dav "$NC_USER" "$PW" DELETE "$REAL_SITE_PATH/$CONFIRM_DIR" > /dev/null
else
	bad "cannot create the site directory '$CONFIRM_DIR' for the confirm check (WebDAV $CODE)"
fi
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

say "2. the author asks for the site: a frozen candidate, and a second request is a conflict"
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_1")")
STATE=$(state_of a "$TARGET_1")
CANDIDATE=$(mine_field a "$TARGET_1" candidate)
PENDING=$(in_site_pending "$TARGET_1")
ONLINE=$(in_site_online "$TARGET_1")
# An online request is a candidate in `pending/`, not a state: the page keeps the state it had -
# internal for a first publication, external for an update - and the row says `candidate: true`.
# `requested` is reserved for a take-off request waiting for a decision.
if [[ "$CODE" == "200" && "$STATE" == "internal|online|" \
	&& "$CANDIDATE" == "True" && "$PENDING" == "1" && "$ONLINE" == "0" ]]; then
	ok "requesting online freezes pending/<target> without moving the state (state '$STATE', candidate '$CANDIDATE')"
else
	bad "the online request answered HTTP $CODE / '$STATE' / candidate '$CANDIDATE' / pending $PENDING / online $ONLINE"
fi
# The manifest records only what the folders cannot: the owner and the digest of the bytes the bridge
# froze. Neither a `state` nor a `candidate`/`published` snapshot is stored any more, so the entry's
# field set is asserted, not just one value.
ENTRY_KEYS=$(manifest_keys "$TARGET_1")
FILE_OWNER=$(manifest_article "$TARGET_1" owner)
FILE_DECIDED=$(manifest_article "$TARGET_1" decidedBy)
FILE_NOTE=$(manifest_article "$TARGET_1" note)
CAND_DIGEST=$(manifest_digest "$TARGET_1")
DOWN_AT=$(manifest_at "$TARGET_1" downRequest)
DIGEST_OK=0
[[ -z "$CAND_DIGEST" || "$CAND_DIGEST" =~ ^[0-9a-f]{64}$ ]] && DIGEST_OK=1
if [[ "$ENTRY_KEYS" == "candidateDigest,decidedBy,downRequest,note,owner" \
	&& "$FILE_OWNER" == "$USER_A" && "$DOWN_AT" == "0" && "$DIGEST_OK" == "1" \
	&& "$FILE_DECIDED" == "" && "$FILE_NOTE" == "" ]]; then
	ok "the entry carries only the owner and the frozen digest (owner '$FILE_OWNER', digest '${CAND_DIGEST:-not recorded}', no reviewer, no note, no down request)"
else
	bad "the manifest entry is wrong (keys '$ENTRY_KEYS', owner '$FILE_OWNER', candidateDigest '$CAND_DIGEST', decidedBy '$FILE_DECIDED', note '$FILE_NOTE', downRequest.at '$DOWN_AT')"
fi
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_1")")
DUP_ERROR=$(py 'import json,sys;print(json.load(sys.stdin).get("error",""))')
DUP_MESSAGE=$(py 'import json,sys;print(json.load(sys.stdin).get("message",""))')
CAND_DIGEST2=$(manifest_digest "$TARGET_1")
PENDING2=$(in_site_pending "$TARGET_1")
# A second request is not a no-op any more: two requests would say the author wants the page on and off
# at the same time, so while one waits a second one - either direction - is a conflict, and withdrawing
# the waiting one is the way to change one's mind.
if [[ "$CODE" == "409" && "$DUP_ERROR" == "CONFLICT" && "$CAND_DIGEST2" == "$CAND_DIGEST" && "$PENDING2" == "1" ]] \
	&& printf '%s' "$DUP_MESSAGE" | grep -qi 'already waiting for a decision'; then
	ok "a second request while one waits is refused (409: $DUP_MESSAGE) and the waiting candidate did not move"
else
	bad "a repeated request answered HTTP $CODE / error '$DUP_ERROR' / '$DUP_MESSAGE', digest '$CAND_DIGEST2' (was '$CAND_DIGEST', pending $PENDING2; expected 409 and a message naming the waiting request)"
fi
BUILD_OK=$(rebuild)
ONBUILD=$(on_build "$MARK_1")
COMMITTED=$(in_commit "$TARGET_1")
# A pending request moves neither tree: the page is still in the author area, so the preview tree keeps
# it and the preview build still shows it. The commit must not carry it - a candidate is not approved.
if [[ "$BUILD_OK" == "True" && "$ONBUILD" == "1" && "$COMMITTED" == "0" ]]; then
	ok "a pending request puts nothing in the commit, and the preview keeps showing the page"
else
	bad "a request reached the commit ($COMMITTED) or vanished from the preview build ($ONBUILD)"
fi
# The reviewer's queue is the API's own answer about a waiting candidate: `?awaiting=1` (a
# `candidate: true` row), never `?state=requested`, which now means a take-off request only.
aread b /api/articles "?awaiting=1" > /dev/null
IN_AWAITING=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
want = "/" + sys.argv[1].lstrip("/")
print(sum(1 for a in items if a["path"] == want and a.get("candidate") is True and a["state"] == "internal" and a.get("intent") == "online"))
' "$TARGET_1")
IN_REQUESTED=$(listed b "$TARGET_1" "?state=requested")
if [[ "$IN_AWAITING" == "1" && "$IN_REQUESTED" == "0" ]]; then
	ok "the reviewer's queue shows it under awaiting=1 with candidate true, and not under state=requested"
else
	bad "awaiting=1 count $IN_AWAITING, state=requested count $IN_REQUESTED (a pending online request must not read as requested)"
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
PENDING=$(in_site_pending "$TARGET_1")
ONLINE=$(in_site_online "$TARGET_1")
# The approval is not a manifest write: it moves the frozen bytes from pending/ to online/, and those
# two folders are what "a candidate waits" and "the site serves it" are read from. A stored `state`
# field must not come back either.
if [[ "$PENDING" == "0" && "$ONLINE" == "1" && -z "$FILE_STATE" ]]; then
	ok "the approval moved the candidate out of pending/ into online/ (no state is stored in the manifest)"
else
	bad "after the approval pending $PENDING, online $ONLINE, manifest state '$FILE_STATE'"
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
FILE_ONLINE=$(in_site_online "$TARGET_1")
if [[ "$FILE_ONLINE" == "1" ]]; then
	ok "online/<target> names the published page - the folder is the published record"
else
	bad "online/ does not carry the page, so nothing records that the site serves it"
fi

say "3b. a reviewer may decide about their own request, and a decision is not repeatable"
# This used to be a 403 ("nobody decides about their own request"). The rule was removed on purpose:
# on an instance whose only reviewer is also the author, refusing self-decisions would make every
# request undecidable - the reviewer who approved is named in the answer and told to the author, which
# is the accountability that matters. (The UI offers the decision buttons on one's own row too, so the
# two sides of the rule agree; this is the endpoint's test.) The assertion is therefore inverted, not
# deleted: it pins the rule that holds.
CODE=$(create_article b "$TARGET_2" "Ext two" "$MARK_2")
CODE=$(apost b /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_2")")
CODE=$(apost b /api/article/decision "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"approve"}))' "$TARGET_2")")
STATE=$(state_of b "$TARGET_2")
PENDING=$(in_site_pending "$TARGET_2")
ONLINE=$(in_site_online "$TARGET_2")
if [[ "$CODE" == "200" && "$STATE" == "external||" && "$PENDING" == "0" && "$ONLINE" == "1" ]]; then
	ok "a reviewer approves their own request (HTTP 200, external, pending/ cleared, online/ written)"
else
	bad "a self-approval answered HTTP $CODE / '$STATE' (pending $PENDING, online $ONLINE; expected 200 and external)"
fi
CODE=$(apost a /api/article/decision "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"approve"}))' "$TARGET_2")")
# the body is read first: the list call below overwrites $OUT
DUP_ERROR=$(py 'import json,sys;print(json.load(sys.stdin).get("error",""))')
DUP_MESSAGE=$(py 'import json,sys;print(json.load(sys.stdin).get("message",""))')
STATE=$(state_of b "$TARGET_2")
# A decision answers a *request*: an external entry with nothing pending has nothing to decide, and
# taking such a page down is the take-down endpoint's own action (section 5).
if [[ "$CODE" == "409" && "$DUP_ERROR" == "CONFLICT" && "$STATE" == "external||" ]] \
	&& printf '%s' "$DUP_MESSAGE" | grep -qi 'no request is waiting'; then
	ok "a second decision about the same entry is refused (409: $DUP_MESSAGE)"
else
	bad "a second approval answered HTTP $CODE / error '$DUP_ERROR' / '$DUP_MESSAGE' / '$STATE' (expected 409, CONFLICT and external)"
fi

say "4. a rejection discards the candidate: internal again, and the refusal is recorded with its reviewer and note"
CODE=$(create_article a "$TARGET_3" "Ext three" "$MARK_3")
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_3")")
CODE=$(apost b /api/article/decision "$(json '
import json, sys
print(json.dumps({"path": sys.argv[1], "decision": "reject", "note": "Please add a real introduction."}))
' "$TARGET_3")")
STATE=$(state_of a "$TARGET_3")
# An online refusal is answered by discarding the candidate, and the answer stays on the entry: the
# reviewer (`decidedBy`) and the note are recorded, so the author's row comes back internal carrying
# the reason they were told (the same note also travels with the notification).
if [[ "$CODE" == "200" && "$STATE" == "internal||Please add a real introduction." ]]; then
	ok "the rejected request is internal again, and the row carries the reviewer's note"
else
	bad "reject answered HTTP $CODE / '$STATE' (expected internal, no intent, and the review note)"
fi
FILE_NOTE=$(manifest_article "$TARGET_3" note)
FILE_DECIDED=$(manifest_article "$TARGET_3" decidedBy)
FILE_DIGEST=$(manifest_digest "$TARGET_3")
PENDING=$(in_site_pending "$TARGET_3")
ONLINE=$(in_site_online "$TARGET_3")
# A refused online request throws the frozen candidate away: pending/<target> is gone, no digest is
# bound to it any more, and the site serves nothing. A refused *update* would keep online/<target>
# as it was - that is the one thing the rejection must not touch. The refusal itself is recorded:
# who said no and why, and the cleared digest says the candidate it described is gone.
if [[ "$FILE_NOTE" == "Please add a real introduction." && "$FILE_DECIDED" == "$USER_B" \
	&& "$FILE_DIGEST" == "" && "$PENDING" == "0" && "$ONLINE" == "0" ]]; then
	ok "the refused candidate was discarded and the refusal is recorded with its reviewer and note (pending/ empty, no digest)"
else
	bad "note='$FILE_NOTE', decidedBy='$FILE_DECIDED', candidateDigest='$FILE_DIGEST', pending $PENDING, online $ONLINE (expected note 'Please add a real introduction.' by '$USER_B', no digest, nothing pending or online)"
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
# 下线 is its own endpoint, not a `decision`: a decision answers a request, and this entry has nothing
# pending (which is exactly why `decision/reject` answered 409 here). The reviewer acts on their own
# judgement, and the note is the reason the author is told about.
TD_NOTE="Taken off the site for the acceptance run."
CODE=$(apost a /api/article/take-down "$(json '
import json, sys
print(json.dumps({"path": sys.argv[1], "note": sys.argv[2]}))
' "$TARGET_2" "$TD_NOTE")")
DECISION=$(py 'import json,sys;print(json.load(sys.stdin).get("decision",""))')
WAS_ON_SITE=$(py 'import json,sys;print(json.load(sys.stdin).get("wasOnSite"))')
TD_DECIDED=$(py 'import json,sys;print(json.load(sys.stdin).get("decidedBy",""))')
TD_SOURCEDELETED=$(py 'import json,sys;print(json.load(sys.stdin).get("sourceDeleted"))')
TD_FORGOTTEN=$(py 'import json,sys;print(json.load(sys.stdin).get("forgotten"))')
STATE=$(state_of b "$TARGET_2")
# The take-down is the reviewer's own action. `forgotten` is not "the page is off the site": it is
# whether the entry had anything left to describe, and that is read from the author's file at decision
# time. This page's file is still there, so the entry stays as the owner's landing point (and is
# forgotten only when the source is gone as well - section 7b). `online/<target>` is what goes either
# way, which is why the page reads internal with no review note (the note is the notification).
if [[ "$CODE" == "200" && "$DECISION" == "take-down" && "$WAS_ON_SITE" == "True" \
	&& "$TD_DECIDED" == "$USER_A" && "$TD_SOURCEDELETED" == "False" && "$TD_FORGOTTEN" == "False" \
	&& "$STATE" == "internal||" ]]; then
	ok "another reviewer takes an external page down directly (200, decision take-down, source still there so the entry stays, internal)"
else
	bad "the direct take-down answered HTTP $CODE / decision '$DECISION' / wasOnSite '$WAS_ON_SITE' / decidedBy '$TD_DECIDED' / sourceDeleted '$TD_SOURCEDELETED' / forgotten '$TD_FORGOTTEN' / '$STATE'"
fi
# The folders are the record: the take-down removes online/<target> (and any candidate waiting next
# to it). The entry stays with its owner - the author still has the file, so the entry is where their
# next request lands - and it carries no down request any more (the take-down answered that queue).
FILE_PRESENT=$(manifest_has_entry "$TARGET_2")
FILE_OWNER=$(manifest_article "$TARGET_2" owner)
FILE_DOWN=$(manifest_at "$TARGET_2" downRequest)
PENDING=$(in_site_pending "$TARGET_2")
ONLINE=$(in_site_online "$TARGET_2")
if [[ "$FILE_PRESENT" == "1" && "$FILE_OWNER" == "$USER_B" && "$FILE_DOWN" == "0" \
	&& "$PENDING" == "0" && "$ONLINE" == "0" ]]; then
	ok "online/ no longer serves the page and the entry stays with its owner, with no down request left"
else
	bad "manifest entry '$FILE_PRESENT' (owner '$FILE_OWNER', downRequest.at '$FILE_DOWN'), pending $PENDING, online $ONLINE (the take-down must clear online/ and leave only the landing point)"
fi
CODE=$(post /api/site/publish '{"message":"visibility acceptance take down"}')
if [[ "$CODE" == "200" ]]; then
	ok "the publish after the take-down answered 200"
else
	bad "the publish after the take-down answered HTTP $CODE: $(head -c 200 "$OUT")"
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
SECOND_NOTHING=$(py 'import json,sys;print(json.load(sys.stdin).get("result",{}).get("nothing_to_commit"))')
if [[ "$CODE" == "200" && "$SECOND_NOTHING" == "True" ]]; then
	ok "a second publish finds nothing to commit (the removal is already in the history)"
else
	bad "the taken-down page keeps producing commits (HTTP $CODE, nothing_to_commit '$SECOND_NOTHING')"
fi

say "5b. a refused take-offline request is not a take-down: the page stays on the site"
# `reject` answers the pending request, it does not take a page down by itself. Refusing an *offline*
# request therefore leaves the entry external, where it was before the author asked: online/ keeps the
# page, the commit tree keeps the file, and the next publish finds nothing to remove. This used to
# answer internal and delete the file from the commit tree - the "reject = force offline" reading the
# endpoint no longer has.
ONLINE_BEFORE=$(in_site_online "$TARGET_1")
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"offline"}))' "$TARGET_1")")
# An offline request has no content of its own: it is one `downRequest` in the manifest and nothing in
# `pending/` (a take-off is not a candidate), which is what makes the state `requested` / `offline`.
REQ_STATE=$(state_of a "$TARGET_1")
REQ_DOWN=$(manifest_at "$TARGET_1" downRequest)
REQ_PENDING=$(in_site_pending "$TARGET_1")
if [[ "$CODE" == "200" && "$REQ_STATE" == "requested|offline|" && "${REQ_DOWN:-0}" -gt 0 && "$REQ_PENDING" == "0" ]]; then
	ok "the take-off request is a downRequest with no candidate (requested, intent offline)"
else
	bad "the offline request answered HTTP $CODE / '$REQ_STATE' / downRequest.at '$REQ_DOWN' / pending $REQ_PENDING"
fi
CODE=$(apost b /api/article/decision "$(json '
import json, sys
print(json.dumps({"path": sys.argv[1], "decision": "reject", "note": "Keep it up for now."}))
' "$TARGET_1")")
STATE=$(state_of a "$TARGET_1")
if [[ "$CODE" == "200" && "$STATE" == "external||Keep it up for now." ]]; then
	ok "refusing the offline request leaves the page external, with the author's reason"
else
	bad "the refused offline request answered HTTP $CODE / '$STATE' (expected external and the note)"
fi
# the app's own single-article answer, not only the bookkeeping: this is what every reader and the
# author's list are built from
asreq b GET /api/article "?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote("/"+sys.argv[1], safe=""))' "$TARGET_1")" > /dev/null
API_STATE=$(py 'import json,sys;print((json.load(sys.stdin).get("article") or {}).get("state",""))')
if [[ "$API_STATE" == "external" ]]; then
	ok "GET /api/article still reports it external"
else
	bad "GET /api/article reports state '$API_STATE' after the refused offline request"
fi
FILE_DECIDED=$(manifest_article "$TARGET_1" decidedBy)
FILE_NOTE=$(manifest_article "$TARGET_1" note)
FILE_DOWN=$(manifest_at "$TARGET_1" downRequest)
ONLINE=$(in_site_online "$TARGET_1")
# A refused offline request only clears the `downRequest`: the page never left the site, so
# online/<target> (what the site serves) and the commit tree must be exactly as they were, and the
# refusal is recorded with the reviewer's note.
if [[ "$FILE_DOWN" == "0" && "$ONLINE" == "1" && "$FILE_DECIDED" == "$USER_B" \
	&& "$FILE_NOTE" == "Keep it up for now." ]]; then
	ok "the down request is gone, online/ still serves the page, and the reviewer's note is recorded"
else
	bad "downRequest.at='$FILE_DOWN', online $ONLINE, decidedBy='$FILE_DECIDED', note='$FILE_NOTE'"
fi
ONLINE_AFTER=$(in_site_online "$TARGET_1")
if [[ "$ONLINE_BEFORE" == "1" && "$ONLINE_AFTER" == "$ONLINE_BEFORE" ]]; then
	ok "online/<target> still names it, unchanged"
else
	bad "the refused offline request changed online/ ('$ONLINE_BEFORE' → '$ONLINE_AFTER')"
fi
COMMITTED=$(in_commit "$TARGET_1")
WORKED=$(in_work_copy "$TARGET_1")
if [[ "$COMMITTED" == "1" && "$WORKED" == "1" ]]; then
	ok "the commit and the commit tree still carry it"
else
	bad "the page left the commit after the refused offline request (in commit $COMMITTED, commit tree $WORKED)"
fi
EXT=$(listed a "$TARGET_1" "?external=true")
if [[ "$EXT" == "1" ]]; then
	ok "the external filter still lists it"
else
	bad "the refused offline request took the page out of the external list"
fi
CODE=$(post /api/site/publish '{"message":"visibility acceptance refused take-offline"}')
NOTHING=$(py 'import json,sys;print(json.load(sys.stdin).get("result",{}).get("nothing_to_commit"))')
if [[ "$CODE" == "200" && "$NOTHING" == "True" ]]; then
	ok "the next publish finds nothing to remove (online/ still has the page)"
else
	bad "the publish still treats the page as off the site (HTTP $CODE, nothing_to_commit '$NOTHING')"
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
FILE_DOWN=$(manifest_at "$TARGET_1" downRequest)
ONLINE=$(in_site_online "$TARGET_1")
# The withdrawal drops the `downRequest` and leaves the entry with the one thing it had before the
# request: the version the site serves in online/<target>.
if [[ "$CODE" == "200" && "$STATE" == "external||" && "$FILE_DOWN" == "0" && "$ONLINE" == "1" ]]; then
	ok "withdrawing it returns the page to external (the down request is gone, online/ still serves it)"
else
	bad "the withdrawal answered HTTP $CODE / '$STATE', manifest downRequest '$FILE_DOWN', online $ONLINE"
fi
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_3")")
CODE=$(apost a /api/article/cancel "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1]}))' "$TARGET_3")")
STATE=$(state_of a "$TARGET_3")
if [[ "$CODE" == "200" && "$STATE" == "internal||" ]]; then
	ok "an online request withdrawn leaves the page internal"
else
	bad "withdrawing an online request answered HTTP $CODE / '$STATE'"
fi

say "7. deleting the source file is not a take-down: the site keeps serving the page and files a take-off request"
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
# The delete itself is direct, the bookkeeping is not: since online/<target> still serves the page, a
# plain delete degrades to `request-offline` - the entry stays with a `downRequest` that says the
# source is gone, otherwise the page would stay online with no entry left for a reviewer to act on.
# There is no candidate any more (nothing can be frozen from a file that is gone), and online/ is
# untouched: what comes off the site is the reviewer's decision, not the author's delete.
ENTRY_PRESENT=$(manifest_has_entry "$TARGET_5")
ENTRY_STATE=$(state_of a "$TARGET_5")
ENTRY_DOWN=$(manifest_at "$TARGET_5" downRequest)
ENTRY_SRCGONE=$(manifest_source_deleted "$TARGET_5")
ENTRY_PENDING=$(in_site_pending "$TARGET_5")
ONLINE=$(in_site_online "$TARGET_5")
if [[ "$CODE" == "200" && "$GONE" == "404" && "$ENTRY_PRESENT" == "1" \
	&& "$ENTRY_STATE" == "requested|offline|" && "${ENTRY_DOWN:-0}" -gt 0 \
	&& "$ENTRY_SRCGONE" == "True" && "$ENTRY_PENDING" == "0" && "$ONLINE" == "1" ]]; then
	ok "the delete files a take-off request: the file is gone, the entry waits for a reviewer, online/ still serves it"
else
	bad "the direct delete answered HTTP $CODE, file $GONE, entry '$ENTRY_PRESENT'/'$ENTRY_STATE', downRequest.at '$ENTRY_DOWN', sourceDeleted '$ENTRY_SRCGONE', pending $ENTRY_PENDING, online $ONLINE"
fi
if [[ "$ONLINE" == "1" ]]; then
	ok "online/<target> still names it - the site has a version this delete did not revoke"
else
	bad "online/ already forgot it, so nothing records that the site still has the page"
fi
# Deleting a source file is **not** taking the page off the site. The bridge's `remove` op drops only
# the author's copy in the preview tree, and deliberately leaves the publish tree alone: that tree is
# data, and only a take-down moves a target out of it. It also leaves the *online* layer alone - and
# the preview tree is reassembled from source/ + online/ + the author's files, so online/ brings the
# page straight back into `preview/<target>` and the build. That is the site still serving it: the
# author's file is gone, the published version is not. The pinned state below is therefore the
# *unchanged* site - the commit tree, the commit, the preview tree and the built page all keep the
# page - and never a removal the publish was expected to carry.
BUILD_OK=$(rebuild)
WORKED=$(in_work_copy "$TARGET_5")
COMMITTED=$(in_commit "$TARGET_5")
PREVIEWED=$(in_preview_copy "$TARGET_5")
ONBUILD=$(on_build "$MARK_5")
if [[ "$WORKED" == "1" && "$COMMITTED" == "1" && "$PREVIEWED" == "1" ]]; then
	ok "the commit tree and the commit keep the published copy, and so does the preview tree (reassembled from online/: the author's file is gone, the published version is not)"
else
	bad "wrong tree state after the delete (commit tree $WORKED, in commit $COMMITTED, preview tree $PREVIEWED)"
fi
if [[ "$BUILD_OK" == "True" && "$ONBUILD" == "1" ]]; then
	ok "and the build still carries it: the author deleted their file, but online/ still has the page the site serves"
else
	bad "the deleted page left the build (build '$BUILD_OK', found $ONBUILD) - only a take-down removes it from the site"
fi
CODE=$(post /api/site/publish '{"message":"visibility acceptance delete"}')
NOTHING=$(py 'import json,sys;print(json.load(sys.stdin).get("result",{}).get("nothing_to_commit"))')
WORKED=$(in_work_copy "$TARGET_5")
COMMITTED=$(in_commit "$TARGET_5")
if [[ "$CODE" == "200" && "$NOTHING" == "True" ]]; then
	ok "the publish finds nothing to commit: the author's delete did not touch the site"
else
	bad "the publish still had something to change (HTTP $CODE, nothing_to_commit '$NOTHING')"
fi
if [[ "$COMMITTED" == "1" && "$WORKED" == "1" ]]; then
	ok "and the page is still live: only a take-down clears online/ and the committed copy"
else
	bad "the deleted page left the site without a take-down (in commit $COMMITTED, commit tree $WORKED)"
fi

say "7b. the same delete through WebDAV files the same take-off request: the entry survives, so a reviewer can still take the page down"
# The Files app and the sync clients delete through WebDAV, so this is the file-event path
# (`NodeDeletedEvent` → AuthorDeltaService::deleted()), not the app's own delete route. Both have to
# leave the same record, because the rule is the folder's: online/<target> still serves the version,
# so the entry stays and gets a `downRequest` carrying `sourceDeleted: true` (a pending request the
# file may have had is withdrawn first). The reviewer can therefore still act on it - which is the
# whole difference between "the author removed the file" and "the reviewer took the page off the
# site". A delete that dropped the entry would leave the page online with nothing left to decide
# about, which is why the app's own route does not do that either (section 7).
CODE=$(create_article a "$TARGET_6" "Ext six" "$MARK_6")
CODE=$(apost a /api/article/request "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"intent":"online"}))' "$TARGET_6")")
CODE=$(apost b /api/article/decision "$(json 'import json,sys;print(json.dumps({"path":sys.argv[1],"decision":"approve"}))' "$TARGET_6")")
post /api/site/publish '{"message":"visibility acceptance publish six"}' > /dev/null
BUILD_OK=$(rebuild)
COMMITTED=$(in_commit "$TARGET_6")
ONBUILD=$(on_build "$MARK_6")
if [[ "$COMMITTED" == "1" && "$ONBUILD" == "1" ]]; then
	ok "a sixth page is published and on the build"
else
	bad "the sixth page is not on the site (in commit $COMMITTED, built $ONBUILD)"
fi
# the author's own files are `<uid>/files/<AREA>/…`, and the author deletes its own file there
CODE=$(dav "$USER_A" "$PW_A" DELETE "/$AREA/$NAME_6")
GONE=$(file_code a "$TARGET_6")
# the file event writes the same record the app's own delete does: since online/<target> still serves
# the page, the entry stays with a `downRequest` carrying `sourceDeleted: true` (a pending request the
# file may have had is withdrawn first, digest included). online/ is untouched, so the reviewer can
# still take the page down - which is the whole difference between "the author removed the file" and
# "the reviewer took the page off the site". A delete that dropped the entry would leave the page
# online with nothing left to decide about, which is why the app's own route does not do that either.
FILE_PRESENT=$(manifest_has_entry "$TARGET_6")
FILE_STATE=$(state_of a "$TARGET_6")
FILE_DOWN=$(manifest_at "$TARGET_6" downRequest)
FILE_SRCGONE=$(manifest_source_deleted "$TARGET_6")
FILE_PENDING=$(in_site_pending "$TARGET_6")
FILE_ONLINE=$(in_site_online "$TARGET_6")
# the mirror round of a build reassembles the preview tree from source/ + online/ + the author's
# folder: the author's file is gone, but the published version in online/ is not, so preview/<target>
# comes back from that layer and the site keeps serving the page
BUILD_OK=$(rebuild)
WORKED=$(in_work_copy "$TARGET_6")
COMMITTED=$(in_commit "$TARGET_6")
PREVIEWED=$(in_preview_copy "$TARGET_6")
if [[ ( "$CODE" == "204" || "$CODE" == "200" ) && "$GONE" == "404" && "$FILE_PRESENT" == "1" ]] \
	&& [[ "$FILE_STATE" == "requested|offline|" && "${FILE_DOWN:-0}" -gt 0 && "$FILE_SRCGONE" == "True" ]] \
	&& [[ "$FILE_PENDING" == "0" && "$FILE_ONLINE" == "1" ]]; then
	ok "the WebDAV delete files a take-off request and keeps online/<target> (HTTP $CODE)"
else
	bad "the WebDAV delete answered HTTP $CODE, file $GONE, entry '$FILE_PRESENT'/'$FILE_STATE', downRequest.at '$FILE_DOWN', sourceDeleted '$FILE_SRCGONE', pending $FILE_PENDING, online $FILE_ONLINE"
fi
if [[ "$WORKED" == "1" && "$COMMITTED" == "1" && "$PREVIEWED" == "1" ]]; then
	ok "and the site is unchanged: the commit tree, the commit and the preview tree (rebuilt from online/) all keep the published copy"
else
	bad "wrong tree state after the WebDAV delete (commit tree $WORKED, in commit $COMMITTED, preview tree $PREVIEWED)"
fi
CODE=$(apost b /api/article/take-down "$(json '
import json, sys
print(json.dumps({"path": sys.argv[1], "note": "The author removed the file."}))
' "$TARGET_6")")
# A take-down of an entry whose source is gone ends the entry as well: there is no author file left for
# it to describe, so it is forgotten rather than left internal (ArticleReviewService::takeDown() reads
# `sourceDeleted` and forgets it). online/<target> and the host copies go with it, so the entry's
# existence and the folder are what is asserted - there is no `state` to read.
FILE_PRESENT=$(manifest_has_entry "$TARGET_6")
FILE_ONLINE=$(in_site_online "$TARGET_6")
WORKED=$(in_work_copy "$TARGET_6")
if [[ "$CODE" == "200" && "$FILE_PRESENT" == "0" && "$FILE_ONLINE" == "0" && "$WORKED" == "0" ]]; then
	ok "the reviewer takes the deleted page down: the entry is gone, online/ too, and the commit tree drops the file"
else
	bad "the take-down of the deleted page answered HTTP $CODE, entry present '$FILE_PRESENT', online $FILE_ONLINE, commit tree $WORKED"
fi
CODE=$(post /api/site/publish '{"message":"visibility acceptance take down the deleted page"}')
COMMITTED=$(in_commit "$TARGET_6")
WORKED=$(in_work_copy "$TARGET_6")
if [[ "$CODE" == "200" && "$COMMITTED" == "0" && "$WORKED" == "0" ]]; then
	ok "and the publish carries the removal out of the commit"
else
	bad "the take-down of the deleted page did not travel with the publish (HTTP $CODE, in commit $COMMITTED, commit tree $WORKED)"
fi
BUILD_OK=$(rebuild)
ONBUILD=$(on_build "$MARK_6")
if [[ "$ONBUILD" == "0" ]]; then
	ok "and the preview build no longer carries it"
else
	bad "the taken-down page is still on the build"
fi
# Both layers are gone now - the author's file was deleted and the reviewer took the published copy
# out of online/ - so the mirror round has nothing left to reassemble the page from: the preview tree
# itself must not be rebuilt with it either (unlike the internal-page take-down in section 5).
PREVIEWED=$(in_preview_copy "$TARGET_6")
if [[ "$PREVIEWED" == "0" ]]; then
	ok "and the preview tree no longer holds it: the author file and online/ are both gone, so nothing rebuilds it"
else
	bad "the taken-down page is still in the preview tree ($PREVIEW/$TARGET_6)"
fi

say "9. Pull: the remote content replaces the Nextcloud source folder"
# This section uses its own site folder and its own bare remote, so neither the real source folder nor
# the repository the sections above pushed into is touched.
PULL_PATH="/Sites/pages-pull-${STAMP}"
BARE_PULL="/tmp/pages-pull-remote-${STAMP}.git"
PULL_DIR="$DATA_ROOT/$NC_USER/files$PULL_PATH"
SCRATCH="$BARE_PULL-scratch"
MARK_PULL="MARKER-PULL-REMOTE-${STAMP}"
MARK_DRAFT="MARKER-PULL-LOCAL-${STAMP}"
MARK_BASE="MARKER-PULL-BASE-${STAMP}"
DAVBASE="$BASE/remote.php/dav/files/$NC_USER"

# a folder in the storage user's files, through WebDAV (MKCOL first: PUT does not create the folder)
curl --noproxy '*' -sk -X MKCOL -u "$NC_USER:$PW" -o /dev/null "$DAVBASE$PULL_PATH"
REAL_PATH=$(json 'import json,sys;print(json.loads(sys.argv[1]).get("nc_path") or "")' "$SITE_JSON")
PULL_SEED="/tmp/pages-pull-seed-${STAMP}.tar.gz"
# the site's Jekyll skeleton lives in `source/` now: that is what Import/Pull replace and what the
# archive must carry at its top level (`_config.yml`), never the site folder around it
tar -czf "$PULL_SEED" -C "$DATA_ROOT/$NC_USER/files$REAL_PATH/source" . 2>/dev/null
if [[ -f "$DATA_ROOT/$NC_USER/files$REAL_PATH/source/_config.yml" ]]; then
	ok "the real site's source/ folder was packed as the seed of the pull test folder"
else
	bad "cannot pack the real site's source/ folder ($DATA_ROOT/$NC_USER/files$REAL_PATH/source)"
fi

# its own bare remote, seeded by a publish: a publish pushes when a remote is configured, so this is
# also the second live proof that "commit always, push with a remote" works
rm -rf "$BARE_PULL"
git init --bare -q --initial-branch=main "$BARE_PULL" 2>/dev/null
git -C "$BARE_PULL" symbolic-ref HEAD refs/heads/main 2>/dev/null || true
git -C "$BARE_PULL" config receive.shallowupdate true 2>/dev/null || true
if repoint_site "$PULL_PATH" "$BARE_PULL"; then
	ok "the site was repointed at $PULL_PATH / $BARE_PULL for this section"
else
	bad "cannot repoint the site for the pull section"
fi
# the app is the authority the import and pull read their folder from: prove it adopted the new path
# before anything writes, or the rest of this section would run against the real site folder
get /api/site > /dev/null
APP_PATH=$(py 'import json,sys;print(((json.load(sys.stdin).get("site") or {}).get("nc_path")) or "")')
if [[ "$APP_PATH" == "$PULL_PATH" ]]; then
	ok "the app reads the pull site folder from its configuration ($APP_PATH)"
else
	bad "the app still reports nc_path '$APP_PATH' (expected '$PULL_PATH'); skipping the rest of this section"
	dav "$NC_USER" "$PW" DELETE "$PULL_PATH" > /dev/null
	rm -rf "$BARE_PULL" "$PULL_SEED"
	occ config:app:set pages site --value "$SITE_JSON" >/dev/null 2>&1
	req POST /api/bridge/push '' '{}' > /dev/null
fi
if [[ "$APP_PATH" != "$PULL_PATH" ]]; then
	say "9. skipped: the configuration did not switch to the throwaway folder"
else
# the new folder starts as a copy of the real site: the publish below then differs by one file, and
# the mirror neither empties the commit tree nor trips its delete guard
CODE=$(curl --noproxy '*' -sk -b "$ADMIN_JAR" -u "${NC_USER}:${PW}" -H 'OCS-APIREQUEST: true' \
	-H "requesttoken: $TOKEN" -F "archive=@$PULL_SEED" -o "$OUT" -w '%{http_code}' \
	"$BASE/index.php/apps/$APP_ID/api/site/source/import")
if [[ "$CODE" == "200" && -f "$PULL_DIR/source/_config.yml" ]]; then
	ok "the throwaway folder's source/ was seeded with the real site content through Import"
else
	bad "cannot seed the pull site folder's source/ (HTTP $CODE): $(head -c 200 "$OUT")"
fi
dav "$NC_USER" "$PW" PUT "$PULL_PATH/source/baseline.md" "---
title: baseline
layout: default
---

$MARK_BASE
" > /dev/null
# the commit is the commit tree, which only the build's mirror assembles from source/ + online/:
# mirror first, then publish, so the new source file is part of the commit (a publish mirrors nothing)
post /api/site/build '{}' > /dev/null
CODE=$(post /api/site/publish '{"message":"pull test baseline"}')
BASELINE_PUSHED=$(py 'import json,sys;print(json.load(sys.stdin).get("pushed"))')
BASELINE_NOTHING=$(py 'import json,sys;print(json.load(sys.stdin).get("result",{}).get("nothing_to_commit"))')
if [[ "$CODE" == "200" && "$BASELINE_PUSHED" == "True" ]]; then
	ok "the baseline publish committed and pushed to the test remote"
else
	bad "the baseline publish answered HTTP $CODE, pushed '$BASELINE_PUSHED', nothing_to_commit '$BASELINE_NOTHING': $(head -c 300 "$OUT")"
fi

# somebody else pushes: a separate clone gains a page, and the remote is one commit ahead
rm -rf "$SCRATCH" "$BARE_PULL-clone.log" "$BARE_PULL-push.log"
git clone --no-local -b main "$BARE_PULL" "$SCRATCH" > "$BARE_PULL-clone.log" 2>&1
printf -- '---\ntitle: remote page\nlayout: default\n---\n\n%s\n' "$MARK_PULL" > "$SCRATCH/pull-remote.md"
git -C "$SCRATCH" -c user.email=t@example.invalid -c user.name=t add -A >/dev/null 2>&1
git -C "$SCRATCH" -c user.email=t@example.invalid -c user.name=t commit -qm "remote page" >/dev/null 2>&1
REMOTE_HEAD=$(git -C "$SCRATCH" rev-parse HEAD 2>/dev/null || echo '')
git -C "$SCRATCH" push origin HEAD:main > "$BARE_PULL-push.log" 2>&1
REMOTE_BRANCH_HEAD=$(git -C "$BARE_PULL" rev-parse main 2>/dev/null || echo '')
if [[ -n "$REMOTE_HEAD" && "$REMOTE_BRANCH_HEAD" == "$REMOTE_HEAD" ]]; then
	ok "a second writer pushed a page to the remote ($(printf '%s' "$REMOTE_HEAD" | cut -c1-10))"
else
	bad "the remote did not advance (scratch '$REMOTE_HEAD', remote '$REMOTE_BRANCH_HEAD')"
	printf '  debug: clone: %s\n' "$(head -c 300 "$BARE_PULL-clone.log")"
	printf '  debug: push: %s\n' "$(head -c 400 "$BARE_PULL-push.log")"
fi

# a local change the replacement will have to back up and overwrite
dav "$NC_USER" "$PW" PUT "$PULL_PATH/source/local-draft.md" "---
title: local draft
layout: default
---

$MARK_DRAFT
" > /dev/null

CODE=$(get /api/site/pull/preview)
AHEAD=$(py 'import json,sys;print(json.load(sys.stdin).get("remote_ahead"))')
LOCAL_UNPUB=$(py 'import json,sys;print(json.load(sys.stdin).get("local_unpublished"))')
REACHABLE=$(py 'import json,sys;print(json.load(sys.stdin).get("reachable"))')
if [[ "$AHEAD" != "1" ]]; then
	printf '  debug: preview reachable=%s ahead=%s local=%s\n' "$REACHABLE" "$AHEAD" "$LOCAL_UNPUB"
	printf '  debug: remote head %s / work head %s\n' "$REMOTE_BRANCH_HEAD" "$(git -C "$WORK" rev-parse HEAD 2>/dev/null)"
fi
if [[ "$CODE" == "200" && "$AHEAD" == "1" ]]; then
	ok "the pull preview reports the remote as 1 commit ahead"
else
	bad "the pull preview answered HTTP $CODE, ahead '$AHEAD' (expected 1)"
fi
if [[ "${LOCAL_UNPUB:-0}" -ge 1 ]]; then
	ok "the preview counts the unpublished local files ($LOCAL_UNPUB)"
else
	bad "the preview counted no local modifications ('$LOCAL_UNPUB')"
fi

CODE=$(post /api/site/pull '')
FILES=$(py 'import json,sys;print(json.load(sys.stdin).get("files") or 0)')
SKIPPED=$(py 'import json,sys;print(json.load(sys.stdin).get("skipped") or 0)')
BACKED=$(py 'import json,sys;print(json.load(sys.stdin).get("backed_up_to") or "")')
HEAD_AFTER=$(py 'import json,sys;print(json.load(sys.stdin).get("head") or "")')
BRIDGE_OK=$(py 'import json,sys;print((json.load(sys.stdin).get("bridge") or {}).get("ok"))')
if [[ "$CODE" == "200" && "${FILES:-0}" -gt 0 ]]; then
	ok "pull answered 200 and replaced the folder with $FILES files ($SKIPPED skipped)"
else
	bad "pull answered HTTP $CODE: $(head -c 300 "$OUT")"
fi
if [[ -n "$REMOTE_HEAD" && "$HEAD_AFTER" == "$REMOTE_HEAD" ]]; then
	ok "the publish tree is at the remote's head after the pull"
else
	bad "the publish tree is at '$HEAD_AFTER', the remote at '$REMOTE_HEAD'"
fi
if [[ "$BRIDGE_OK" == "True" ]]; then
	ok "both pipelines were refreshed and the preview rebuilt"
else
	bad "the preview was not rebuilt after the pull: $(head -c 300 "$OUT")"
fi

if [[ -f "$PULL_DIR/source/pull-remote.md" ]]; then
	ok "the remote's page is in the Nextcloud source folder now"
else
	bad "the remote's page did not reach $PULL_DIR/source"
fi
if [[ ! -f "$PULL_DIR/source/local-draft.md" ]]; then
	ok "the local unpublished draft was replaced (not merged)"
else
	bad "the local draft survived the replacement"
fi
BACKUP_ID="${BACKED##*/}"
BACKUP_DIR="$(ls -d "$DATA_ROOT"/appdata_*/pages/source-backups/"$BACKUP_ID" 2>/dev/null | head -1)"
if [[ -n "$BACKUP_ID" && -f "$BACKUP_DIR/files/local-draft.md" ]]; then
	ok "the local draft is in the backup ($BACKED)"
else
	bad "the backup does not hold the local draft ('$BACKED')"
fi
if [[ -f "$BACKUP_DIR/files/_config.yml" && -f "$BACKUP_DIR/files/baseline.md" ]]; then
	ok "the backup holds the folder as it was before the pull"
else
	bad "the backup is incomplete ($BACKUP_DIR/files)"
fi
# one file that the remote carries must not be an artefact of grep on a stale build
PULL_BUILT=$(grep -rFl -- "$MARK_PULL" "$BUILT" 2>/dev/null | head -1)
DRAFT_BUILT=$(grep -rFl -- "$MARK_DRAFT" "$BUILT" 2>/dev/null | head -1)
if [[ -n "$PULL_BUILT" && -z "$DRAFT_BUILT" ]]; then
	ok "the preview serves the remote's page and no longer the local draft"
else
	bad "the built site disagrees (remote page '$PULL_BUILT', draft '$DRAFT_BUILT')"
fi
# the confirmation's numbers must not be invented: after the pull the remote is 0 commits ahead
CODE=$(get /api/site/pull/preview)
AHEAD=$(py 'import json,sys;print(json.load(sys.stdin).get("remote_ahead"))')
if [[ "$CODE" == "200" && "$AHEAD" == "0" ]]; then
	ok "a second preview reports the remote as 0 commits ahead (the count is measured, not guessed)"
else
	bad "after the pull the preview reports ahead '$AHEAD'"
fi

fi
# put the real folder and the stored configuration back before the comparison below and the trap
REAL_REPO=$(json 'import json,sys;print(json.loads(sys.argv[1]).get("repo_url") or "")' "$SITE_JSON")
repoint_site "$REAL_PATH" "$REAL_REPO" >/dev/null 2>&1 || occ config:app:set pages site --value "$SITE_JSON" >/dev/null 2>&1
dav "$NC_USER" "$PW" DELETE "$PULL_PATH/source/_config.yml" > /dev/null
dav "$NC_USER" "$PW" DELETE "$PULL_PATH/source/baseline.md" > /dev/null
dav "$NC_USER" "$PW" DELETE "$PULL_PATH/source/pull-remote.md" > /dev/null
dav "$NC_USER" "$PW" DELETE "$PULL_PATH/source/local-draft.md" > /dev/null
dav "$NC_USER" "$PW" DELETE "$PULL_PATH" > /dev/null
rm -rf "$SCRATCH" "$BARE_PULL" "$PULL_SEED" "$BARE_PULL-clone.log" "$BARE_PULL-push.log"
post /api/site/build '{}' > /dev/null
ok "the real site folder and the stored configuration are back in place"

say "10. publish without a remote, and a push the remote rejects"
# (a) no remote: the publish is the commit, and the answer says no push happened
repoint_site "$REAL_PATH" ""
dav "$NC_USER" "$PW" PUT "$REAL_PATH/source/publish-probe.md" "---
title: publish probe
layout: default
---

MARKER-PUBLISH-PROBE-${STAMP}
" > /dev/null
post /api/site/build '{}' > /dev/null
CODE=$(post /api/site/publish '{"message":"no remote publish"}')
COMMIT=$(py 'import json,sys;print((json.load(sys.stdin).get("commit") or ""))')
PUSHED=$(py 'import json,sys;print(json.load(sys.stdin).get("pushed"))')
REMOTE_CFG=$(py 'import json,sys;print(json.load(sys.stdin).get("remote_configured"))')
if [[ "$CODE" == "200" && -n "$COMMIT" ]]; then
	ok "a publish without a remote answered 200 with a commit (${COMMIT:0:10})"
else
	bad "a publish without a remote answered HTTP $CODE (commit '$COMMIT'): $(head -c 300 "$OUT")"
fi
if [[ "$PUSHED" == "False" && "$REMOTE_CFG" == "False" ]]; then
	ok "the answer reports no push and no configured remote - the only two facts there are"
else
	bad "the no-remote answer is wrong (pushed '$PUSHED', remote_configured '$REMOTE_CFG')"
fi
if ! grep -qiE 'not pushed|unpushed|not_pushed' "$OUT"; then
	ok "and it carries no 'not pushed' wording"
else
	bad "the no-remote answer uses removed wording: $(head -c 300 "$OUT")"
fi

# (b) a push the remote rejects: the commit stays, the answer is a structured 409 with the ahead count
BARE_REJ="/tmp/pages-reject-remote-${STAMP}.git"
REJ_SCRATCH="$BARE_REJ-scratch"
rm -rf "$BARE_REJ" "$REJ_SCRATCH"
git init --bare -q --initial-branch=main "$BARE_REJ" 2>/dev/null
git -C "$BARE_REJ" symbolic-ref HEAD refs/heads/main 2>/dev/null || true
git -C "$BARE_REJ" config receive.shallowupdate true 2>/dev/null || true
repoint_site "$REAL_PATH" "$BARE_REJ"
# the no-remote publish above already committed the probe, so the baseline needs a change of its own
dav "$NC_USER" "$PW" PUT "$REAL_PATH/source/publish-probe.md" "---
title: publish probe
layout: default
---

MARKER-PUBLISH-PROBE-BASELINE-${STAMP}
" > /dev/null
post /api/site/build '{}' > /dev/null
CODE=$(post /api/site/publish '{"message":"reject baseline"}')
BASELINE=$(py 'import json,sys;print(json.load(sys.stdin).get("pushed"))')
if [[ "$CODE" == "200" && "$BASELINE" == "True" ]]; then
	ok "the baseline publish pushed to the rejection test remote"
else
	bad "the baseline publish answered HTTP $CODE, pushed '$BASELINE'"
fi
git clone --no-local -b main "$BARE_REJ" "$REJ_SCRATCH" > /dev/null 2>&1
printf -- '---\ntitle: theirs\n---\n\ntheir page\n' > "$REJ_SCRATCH/theirs.md"
git -C "$REJ_SCRATCH" -c user.email=t@example.invalid -c user.name=t add -A >/dev/null 2>&1
git -C "$REJ_SCRATCH" -c user.email=t@example.invalid -c user.name=t commit -qm "somebody else" >/dev/null 2>&1
git -C "$REJ_SCRATCH" push origin HEAD:main > /dev/null 2>&1 || bad "the second writer could not push"

dav "$NC_USER" "$PW" PUT "$REAL_PATH/source/publish-probe.md" "---
title: publish probe two
layout: default
---

MARKER-PUBLISH-PROBE-TWO-${STAMP}
" > /dev/null
post /api/site/build '{}' > /dev/null
CODE=$(post /api/site/publish '{"message":"push rejected"}')
ERROR_CODE=$(py 'import json,sys;print(json.load(sys.stdin).get("error") or "")')
AHEAD=$(py 'import json,sys;print(json.load(sys.stdin).get("ahead"))')
PUSH_FAILED=$(py 'import json,sys;print(json.load(sys.stdin).get("push_failed"))')
REJ_COMMIT=$(py 'import json,sys;print(json.load(sys.stdin).get("commit") or "")')
MESSAGE=$(py 'import json,sys;print(json.load(sys.stdin).get("message") or "")')
if [[ "$CODE" == "409" && "$ERROR_CODE" == "PUSH_REJECTED" ]]; then
	ok "a rejected push is a structured 409 (error=PUSH_REJECTED)"
else
	bad "a rejected push answered HTTP $CODE, error '$ERROR_CODE': $(head -c 300 "$OUT")"
fi
if [[ "$AHEAD" == "1" ]]; then
	ok "the ahead count is measured, not guessed (1 new commit on the remote)"
else
	bad "the ahead count is '$AHEAD' (expected 1)"
fi
if [[ -n "$REJ_COMMIT" && "$PUSH_FAILED" == "True" ]]; then
	ok "the commit was made anyway, and the answer says the push failed (${REJ_COMMIT:0:10})"
else
	bad "the rejected publish lost its commit (commit '$REJ_COMMIT', push_failed '$PUSH_FAILED')"
fi
if printf '%s' "$MESSAGE" | grep -q 'remote has 1 new commit(s)'; then
	ok "the message names the ahead count and points at Pull: $MESSAGE"
else
	bad "the rejection message does not carry the count: '$MESSAGE'"
fi

# put everything back: the probe file, the folder, the remote and the work copy
dav "$NC_USER" "$PW" DELETE "$REAL_PATH/source/publish-probe.md" > /dev/null
repoint_site "$REAL_PATH" "$(json 'import json,sys;print(json.loads(sys.argv[1]).get("repo_url") or "")' "$SITE_JSON")" >/dev/null 2>&1 \
	|| occ config:app:set pages site --value "$SITE_JSON" >/dev/null 2>&1
rm -rf "$BARE_REJ" "$REJ_SCRATCH"
post /api/site/build '{}' > /dev/null
ok "the probes are removed and the configuration is back"

say "11. a publish checks the commit tree: a drifted work tree is refused, never repaired"
# A publish neither assembles nor repairs a tree any more. It first proves that the commit tree is
# **exactly** the flat merge of source/ + online/ (online/ wins on a duplicate path, a top-level .git
# is skipped): expected_files/actual_files with the missing/different/extra buckets. A mismatch answers
# 409 WORK_DRIFT and the publish commits nothing and writes nothing. A publish never repairs a tree:
# Apply source is the explicit repair, so this section drives both directions a hand-edit can take - an
# extra file and an edited published file - and pins the refusal, its "no side effect" half, and the
# Apply source that recovers the tree. The repair must not invent a commit either: a consistent tree
# equals HEAD.
#
# The section runs with no remote: the real one is put back at the end, and nothing here may push to
# it.
if repoint_site "$REAL_PATH" ""; then
	DRIFT_PROBE="_drift-probe.md"
	DRIFT_TARGET="$TARGET_1"
	DRIFT_FILE="$WORK/$DRIFT_TARGET"

	# 1. precondition: start from a consistent tree (Apply source is the only repair) and publish it.
	#    This also carries the probe removal section 10 left uncommitted, so the tree starts clean.
	BUILD_OK=$(rebuild)
	CODE=$(post /api/site/publish '{"message":"work drift check baseline"}')
	if [[ "$CODE" == "200" ]]; then
		ok "a consistent commit tree publishes (HTTP 200)"
	else
		bad "the baseline publish answered HTTP $CODE (Apply source '$BUILD_OK'): $(head -c 300 "$OUT")"
	fi
	# Apply source above also drops a probe an aborted earlier run could have left behind
	ONLINE_SHA=$(sha256sum "$SITE_DIR/online/$DRIFT_TARGET" 2>/dev/null | cut -d' ' -f1)
	COMMITS_BEFORE=$(git -C "$WORK" log --oneline 2>/dev/null | wc -l | tr -d ' ')

	# 2. first drift: a file the merge of source/ + online/ does not hold, put straight into the commit
	#    tree. The publish has to report it under `extra`, and it must not commit or write anything.
	DRIFT_ACTIVE=1
	printf '<!-- work drift probe: a file the merge of source/ + online/ does not hold -->\n' > "$WORK/$DRIFT_PROBE"
	CODE=$(post /api/site/publish '{"message":"an extra file in the commit tree must be refused"}')
	# the bridge keeps the counters flat next to `error` *and* under `details`; read whichever is there
	WD_ERROR=$(py '
import json, sys
d = json.load(sys.stdin)
dd = d.get("details") if isinstance(d.get("details"), dict) else {}
print(d.get("error") or dd.get("error") or "")')
	WD_EXTRA=$(py '
import json, sys
d = json.load(sys.stdin)
dd = d.get("details") if isinstance(d.get("details"), dict) else {}
print(int(d.get("extra") or dd.get("extra") or 0))')
	if [[ "$CODE" == "409" && "$WD_ERROR" == "WORK_DRIFT" && "${WD_EXTRA:-0}" -ge 1 ]]; then
		ok "an extra file in work/ is refused with 409 WORK_DRIFT (extra '${WD_EXTRA:-0}')"
	else
		bad "the extra-file publish answered HTTP $CODE, error '$WD_ERROR', extra '${WD_EXTRA:-}': $(head -c 400 "$OUT")"
	fi
	COMMITS_AFTER=$(git -C "$WORK" log --oneline 2>/dev/null | wc -l | tr -d ' ')
	ONLINE_SHA_AFTER=$(sha256sum "$SITE_DIR/online/$DRIFT_TARGET" 2>/dev/null | cut -d' ' -f1)
	if [[ "$COMMITS_AFTER" == "$COMMITS_BEFORE" && "$ONLINE_SHA_AFTER" == "$ONLINE_SHA" && -f "$WORK/$DRIFT_PROBE" ]]; then
		ok "and the refusal changed nothing: no new commit ($COMMITS_AFTER), online/ untouched, the extra file is still in work/"
	else
		bad "the refused publish wrote something (commits '$COMMITS_BEFORE' → '$COMMITS_AFTER', online '$ONLINE_SHA' → '$ONLINE_SHA_AFTER', probe present $([[ -f "$WORK/$DRIFT_PROBE" ]] && echo yes || echo no))"
	fi

	# 3. second drift: a published file edited straight in the commit tree (the suite runs as root).
	#    The publish has to report it under `different`, again without committing or writing.
	printf '\n<!-- work drift probe: this file no longer matches source/ + online/ -->\n' >> "$DRIFT_FILE"
	CODE=$(post /api/site/publish '{"message":"an edited published file must be refused"}')
	WD_ERROR=$(py '
import json, sys
d = json.load(sys.stdin)
dd = d.get("details") if isinstance(d.get("details"), dict) else {}
print(d.get("error") or dd.get("error") or "")')
	WD_DIFFERENT=$(py '
import json, sys
d = json.load(sys.stdin)
dd = d.get("details") if isinstance(d.get("details"), dict) else {}
print(int(d.get("different") or dd.get("different") or 0))')
	if [[ "$CODE" == "409" && "$WD_ERROR" == "WORK_DRIFT" && "${WD_DIFFERENT:-0}" -ge 1 ]]; then
		ok "an edited published file in work/ is refused with 409 WORK_DRIFT (different '${WD_DIFFERENT:-0}')"
	else
		bad "the edited-file publish answered HTTP $CODE, error '$WD_ERROR', different '${WD_DIFFERENT:-}': $(head -c 400 "$OUT")"
	fi
	COMMITS_AFTER=$(git -C "$WORK" log --oneline 2>/dev/null | wc -l | tr -d ' ')
	ONLINE_SHA_AFTER=$(sha256sum "$SITE_DIR/online/$DRIFT_TARGET" 2>/dev/null | cut -d' ' -f1)
	STILL_DRIFTED=0
	grep -q 'work drift probe' "$DRIFT_FILE" 2>/dev/null && STILL_DRIFTED=1
	if [[ "$COMMITS_AFTER" == "$COMMITS_BEFORE" && "$ONLINE_SHA_AFTER" == "$ONLINE_SHA" && "$STILL_DRIFTED" == "1" ]]; then
		ok "and that refusal changed nothing either: no new commit, online/ untouched, the edit is still in work/"
	else
		bad "the second refused publish wrote something (commits '$COMMITS_BEFORE' → '$COMMITS_AFTER', online '$ONLINE_SHA' → '$ONLINE_SHA_AFTER', edit still there '$STILL_DRIFTED')"
	fi

	# 4. Apply source is the explicit recovery: it rebuilds work from source/ + online/
	CODE=$(post /api/site/build '{"force":true}')
	REBUILT=$(py 'import json,sys;print(json.load(sys.stdin).get("rebuilt"))')
	if [[ "$CODE" == "200" && ! -e "$WORK/$DRIFT_PROBE" ]]; then
		ok "Apply source answers 200 and drops the extra file from the commit tree (rebuilt '$REBUILT')"
	else
		bad "Apply source answered HTTP $CODE, rebuilt '$REBUILT', and the extra file $([[ -e "$WORK/$DRIFT_PROBE" ]] && echo 'is still there' || echo 'is gone')"
	fi
	DRIFT_FILE_RESTORED=0
	cmp -s "$DRIFT_FILE" "$SITE_DIR/online/$DRIFT_TARGET" && DRIFT_FILE_RESTORED=1
	if [[ "$DRIFT_FILE_RESTORED" == "1" ]]; then
		ok "and the edited file is back to the bytes online/ holds"
	else
		bad "the edited file was not restored from online/ ($DRIFT_FILE)"
	fi
	if [[ ! -e "$WORK/$DRIFT_PROBE" && "$DRIFT_FILE_RESTORED" == "1" ]]; then
		DRIFT_ACTIVE=0
	fi

	# 5. a consistent tree equals HEAD again, so the next publish has nothing to carry: the repair
	#    must not invent a commit of its own.
	CODE=$(post /api/site/publish '{"message":"a repaired commit tree has nothing to commit"}')
	NOTHING=$(py '
import json, sys
d = json.load(sys.stdin)
r = d.get("result") if isinstance(d.get("result"), dict) else d
print(r.get("nothing_to_commit"))')
	COMMITS_FINAL=$(git -C "$WORK" log --oneline 2>/dev/null | wc -l | tr -d ' ')
	if [[ "$CODE" == "200" && "$NOTHING" == "True" && "$COMMITS_FINAL" == "$COMMITS_BEFORE" ]]; then
		ok "the next publish answers 200 with nothing_to_commit: the repaired tree matches HEAD again"
	else
		bad "the publish after Apply source answered HTTP $CODE, nothing_to_commit '$NOTHING', commits $COMMITS_BEFORE → ${COMMITS_FINAL:-}"
	fi

	# the configured remote goes back before the sections below read the configuration
	repoint_site "$REAL_PATH" "$REAL_REPO" >/dev/null 2>&1 \
		|| occ config:app:set pages site --value "$SITE_JSON" >/dev/null 2>&1
	ok "the site is back on its configured remote"
else
	bad "cannot switch the site to a remote-less configuration; the drift section was skipped"
fi

say "12. the pushed bridge configuration was not touched"
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
