#!/usr/bin/env bash
#
# Authoring acceptance (plan P2/P3): each author works in their own Nextcloud folder, the app is the
# ownership authority, and the bridge mirrors those folders into the one site.
#
#   sudo ./tests/live/test-authors.sh
#
# Two throwaway users plus the admin. Everything created here is removed again and the site
# configuration is restored (trap on EXIT). The review/publish flow is asserted in the same script
# once it exists, so this file is the acceptance for authoring end to end.
#
# An author's files are **pages** of the site template, never posts: the suite therefore asserts the
# two addresses the template's author mirror produces (with and without a usable category), that the
# whole tree is browsable and editable through the tree endpoints, and that deleting a file the site
# serves is the reviewed request it has to be.
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AREA="${AUTHOR_AREA:-Pages}"
USER_A="pages-author-a"
USER_B="pages-author-b"
PW_A="Pages-Author-A-$(date +%s)-pw"
PW_B="Pages-Author-B-$(date +%s)-pw"
STAMP="$(date +%Y%m%d%H%M%S)"
NAME_A="2026-01-01-probe-a-${STAMP}.md"
NAME_B="2026-01-02-probe-b-${STAMP}.md"
# The site target of an author file is `<author_root>/<uid>/<file>`. `author_root` is site
# configuration (it was `tmp` once, later `authors-src`, and a move is possible), so the targets are
# built after the admin session reads the real value below - never from a hardcoded `tmp/`.
TARGET_A=""
TARGET_B=""
PAGE_DIR="authors"
# The bridge keeps two directories per site. The work copy is the **commit** tree (on-site files
# only, what a publish commits); the **preview** tree holds every author file and is the one that is
# built. Both are configuration the app pushes, so both are read from the bridge's effective
# configuration instead of being guessed.
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
BUILT="$PREVIEW/_site"

if [[ -z "${OCC:-}" ]]; then
	for candidate in "$(command -v nextcloud.occ 2>/dev/null)" /snap/bin/nextcloud.occ; do
		[[ -n "$candidate" && -x "$candidate" ]] && { OCC="$candidate"; break; }
	done
fi
OCC="${OCC:-snap run nextcloud.occ}"
occ() { $OCC "$@"; }

# the data directory, for the throwaway users' homes (a deleted account can leave its folder behind)
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

OUT=/tmp/ta.json
ADMIN_JAR=/tmp/ta-admin.jar
A_JAR=/tmp/ta-a.jar
B_JAR=/tmp/ta-b.jar
LOGIN=/tmp/ta-login.html
NC_USER=""
PW=""
TOKEN=""
SITE_BEFORE="{}"
ROOT_BEFORE=""
RESTORED=0

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

cleanup() {
	if [[ "$RESTORED" -eq 0 && -n "$TOKEN" ]]; then
		req PUT /api/site '' "$SITE_BEFORE" > /dev/null 2>&1 || true
		req POST /api/bridge/push '' '{}' > /dev/null 2>&1 || true
	fi
	# The author content directory is the one setting this suite may move (section 8). Restore it on
	# every exit, through the channel that accepts a move even while author content exists, so a
	# failed lock check cannot leave the instance on a directory no later suite knows about.
	if [[ -n "$ROOT_BEFORE" && -n "$TOKEN" ]]; then
		req POST /api/site/author-root '' "$(python3 -c 'import json,sys;print(json.dumps({"authorRoot":sys.argv[1],"confirm":True}))' "$ROOT_BEFORE")" > /dev/null 2>&1 || true
	fi
	# section 9 edits the site's `source/_config.yml` to provoke the doctor's mismatch branch; put it
	# back even when the suite dies in the middle of that section
	if [[ -n "${CONFIG_BACKUP:-}" && -f "${CONFIG_BACKUP:-}" && -n "${CONFIG_FILE:-}" ]]; then
		cp "$CONFIG_BACKUP" "$CONFIG_FILE" 2>/dev/null || true
	fi
	# the posts live in the authors' own files, so deleting the users deletes them too
	for u in "$USER_A" "$USER_B"; do dropuser "$u"; done
	rm -f "$OUT" "$ADMIN_JAR" "$A_JAR" "$B_JAR" "$LOGIN" \
		"${DOCTOR:-/tmp/ta-doctor.txt}" "${CONFIG_BACKUP:-/tmp/ta-config.yml}"
}
trap cleanup EXIT

# ---------------------------------------------------------------- request layer
req() { # req <METHOD> <app-path> [query] [json]  → HTTP code, body in $OUT
	local method="$1" path="$2" query="${3:-}" body="${4:-}"
	local args=(-X "$method" -b "$ADMIN_JAR" -c "$ADMIN_JAR" -u "${NC_USER}:${PW}"
		-H 'OCS-APIREQUEST: true' -H "requesttoken: $TOKEN" -o "$OUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(-H 'Content-Type: application/json' --data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path$query"
}
get()  { req GET  "$1" "${2:-}"; }
post() { req POST "$1" '' "${2:-}"; }
put()  { req PUT  "$1" '' "${2:-}"; }

asreq() { # asreq <a|b> <METHOD> <app-path> [query] [json] → HTTP code, body in $OUT
	local who="$1" method="$2" path="$3" query="${4:-}" body="${5:-}" jar pw uid
	case "$who" in
		a) jar="$A_JAR"; pw="$PW_A"; uid="$USER_A" ;;
		b) jar="$B_JAR"; pw="$PW_B"; uid="$USER_B" ;;
		*) bad "unknown actor $who"; return 1 ;;
	esac
	local args=(-X "$method" -b "$jar" -c "$jar" -u "${uid}:$pw" -H 'OCS-APIREQUEST: true'
		-o "$OUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(-H 'Content-Type: application/json' --data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path$query"
}
aread() { asreq "$1" GET "$2" "${3:-}"; }
awrite() { asreq "$1" PUT "$2" '' "${4:-}"; }   # awrite <a|b> <path> '' <json>
acreate() { asreq "$1" POST "$2" '' "${3:-}"; }

py() { python3 -c "$1" < "$OUT" 2>/dev/null; }
pyarg() { python3 -c "$1" "$2" < "$OUT" 2>/dev/null; }
q() { python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1], safe=""))' "$1"; }

dav() { # dav <user> <pass> <METHOD> <dav-path> [data] → HTTP code
	local user="$1" pass="$2" method="$3" path="$4" data="${5:-}"
	local args=(-X "$method" -u "$user:$pass" -o /dev/null -w '%{http_code}')
	[[ -n "$data" ]] && args+=(--data-binary "$data")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/remote.php/dav/files/$user$path"
}

davfile() { # davfile <user> <pass> <dav-path> <local file> → HTTP code (binary-safe upload)
	local user="$1" pass="$2" path="$3" file="$4"
	curl --noproxy '*' -sk -X PUT -u "$user:$pass" -o /dev/null -w '%{http_code}' \
		--data-binary "@$file" "$BASE/remote.php/dav/files/$user$path"
}

mkuser() { # mkuser <uid> <password>
	dropuser "$1"
	OC_PASS="$2" occ user:add --password-from-env --display-name "Pages author $1" "$1" >/dev/null 2>&1 \
		|| { bad "cannot create $1"; exit 1; }
	# a real write, so the account's files folder exists before the app touches it
	dav "$1" "$2" PUT "/pages-author-probe.txt" 'x' > /dev/null
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

post_json() { python3 -c "$1" "${@:2}"; }

say "0. sessions"
NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
[[ -n "$NC_USER" ]] || { bad "no Nextcloud user found"; exit 1; }
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { bad "cannot issue an app password"; exit 1; }
PW=$(cat "$PW_FILE")
TOKEN=$(login "$ADMIN_JAR" "$NC_USER" "$PW") || { bad "admin login failed"; exit 1; }
get /api/site > /dev/null
SITE_BEFORE=$(python3 -c '
import json
s = (json.load(open("/tmp/ta.json")).get("site") or {})
print(json.dumps({k: s.get(k) for k in ("nc_user", "nc_path", "repo_url", "branch", "recipe", "exclude") if s.get(k) is not None}))
')
# The author content directory is site configuration: read the real value from the API and build every
# target from it, so the suite works whatever the directory is called (it was `tmp`, later
# `authors-src`, and section 8 moves it on purpose). `<author_root>/<uid>/<file>` is the form the
# article endpoints store; a path without the root is read as relative to the caller's own area.
get /api/admin/config > /dev/null
ROOT_BEFORE=$(python3 -c 'import json;print((json.load(open("/tmp/ta.json")).get("config") or {}).get("author_root",""))')
[[ -n "$ROOT_BEFORE" ]] || { bad "cannot read author_root from GET /api/admin/config"; exit 1; }
AUTHOR_ROOT="$ROOT_BEFORE"
TARGET_A="$AUTHOR_ROOT/${USER_A}/${NAME_A}"
TARGET_B="$AUTHOR_ROOT/${USER_B}/${NAME_B}"
A_TARGET_ROOT="$AUTHOR_ROOT/$USER_A"
ok "author content directory is '$AUTHOR_ROOT' (read from the API)"
mkuser "$USER_A" "$PW_A"
mkuser "$USER_B" "$PW_B"
# the review endpoints require the authoring grant; this suite's author joins an existing author group
# (deleting the user at the end removes the membership with it)
occ group:adduser test2 "$USER_A" >/dev/null 2>&1 || true
[[ -n "$(login "$A_JAR" "$USER_A" "$PW_A")" ]] || { bad "$USER_A cannot log in"; exit 1; }
[[ -n "$(login "$B_JAR" "$USER_B" "$PW_B")" ]] || { bad "$USER_B cannot log in"; exit 1; }
ok "admin ($NC_USER), $USER_A and $USER_B are ready (author area '$AREA')"

# The site's own template configuration decides the built address of every author page (see
# PageUrlService), so it is read here rather than assumed: a site without `guides.md` at its skeleton
# root cannot route a page to /guides/. The skeleton is the site folder's `source/` directory (the
# three fixed directories are `source/`, `online/` and `pending/`), so the category page is read
# there - `guides.md` directly in the site folder is content no build reads any more.
SITE_NCUSER=$(occ config:app:get pages site 2>/dev/null | python3 -c 'import json,sys;print((json.load(sys.stdin).get("nc_user") or ""))' 2>/dev/null || echo '')
SITE_NCPATH=$(occ config:app:get pages site 2>/dev/null | python3 -c 'import json,sys;print((json.load(sys.stdin).get("nc_path") or ""))' 2>/dev/null || echo '')
SITE_ROOT="/var/snap/nextcloud/common/nextcloud/data/${SITE_NCUSER}/files${SITE_NCPATH}"
# The two content layers (`online/`, `pending/`) live next to the skeleton, under the site's storage
# account. `nc_user` is left empty by a configuration that stores the administrator implicitly, and
# the app falls back to them - so the layer reads below use the same fallback (and do not touch the
# path section 9 uses for the skeleton).
SITE_LAYERS="/var/snap/nextcloud/common/nextcloud/data/${SITE_NCUSER:-$NC_USER}/files${SITE_NCPATH}"
SITE_SOURCE="$SITE_ROOT/source"
HAS_GUIDES=0
[[ -f "$SITE_SOURCE/guides.md" ]] && HAS_GUIDES=1

say "1. an author writes into their own files, addressed as a site path"
BODY_A=$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\ntitle: Author A probe\ntags: probea shared\n---\n\nWritten by A.\n"}))
' "$TARGET_A")
CODE=$(acreate a /api/site/file "$BODY_A")
OWNED=$(dav "$USER_A" "$PW_A" PROPFIND "/${AREA}/${NAME_A}")
if [[ "$CODE" == "200" || "$CODE" == "201" ]]; then
	ok "POST /api/site/file accepted the target ($TARGET_A)"
else
	bad "the author could not create their file (HTTP $CODE): $(head -c 200 "$OUT")"
fi
if [[ "$OWNED" == "207" ]]; then
	ok "the file really lives in the author's own files ($AREA/$NAME_A)"
else
	bad "the file is not in the author's own folder (WebDAV $OWNED)"
fi

say "2. ownership is enforced"
CODE=$(acreate b /api/site/file "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "stolen\n"}))
' "${TARGET_A}")")
if [[ "$CODE" == "403" ]]; then
	ok "another author cannot create a file in someone else's area (HTTP 403)"
else
	bad "writing into someone else's area answered HTTP $CODE (expected 403)"
fi
CODE=$(awrite b /api/site/file '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "tampered\n"}))
' "$TARGET_A")")
if [[ "$CODE" == "403" ]]; then
	ok "and cannot overwrite another author's file (HTTP 403)"
else
	bad "overwriting another author's file answered HTTP $CODE (expected 403)"
fi
CODE=$(aread b /api/site/file "?path=/${TARGET_A}")
if [[ "$CODE" == "403" || "$CODE" == "404" ]]; then
	ok "and cannot read it either (HTTP $CODE)"
else
	bad "reading another author's file answered HTTP $CODE"
fi

say "3. the author sees their own page, a reader does not"
CODE=$(aread a /api/articles/mine)
OWN=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if a["path"] == sys.argv[1]), None)
print("missing" if hit is None else "%s|%s|%s|%s|%s" % (hit["owner"], hit["state"], hit["title"], ",".join(hit["tags"]), hit["url"]))
' "/${TARGET_A}")
if [[ "$CODE" == "200" && "$OWN" == "$USER_A|internal|Author A probe|probea,shared|/$PAGE_DIR/$USER_A/${NAME_A%.md}.html" ]]; then
	ok "the author's own list shows it as internal with owner, title, tags and its built address ($OWN)"
else
	bad "own list wrong (HTTP $CODE, '$OWN'; expected $USER_A|internal|Author A probe|probea,shared|/$PAGE_DIR/$USER_A/${NAME_A%.md}.html)"
fi
CODE=$(aread b /api/articles)
LEAK=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if a["path"] == sys.argv[1]), None)
print("missing" if hit is None else hit["state"])
' "/${TARGET_A}")
if [[ "$LEAK" == "internal" ]]; then
	ok "the full list shows it to every reader as internal (reading has no visibility filter)"
else
	bad "the internal page is not listed with its state ('$LEAK')"
fi
# the structured editor reports the same address, which is what its preview pane loads
CODE=$(aread a /api/article/content "?path=$(q "/${TARGET_A}")")
META_URL=$(py 'import json,sys
d=json.load(sys.stdin)
meta=d.get("meta") or {}
print("%s|%s" % (meta.get("url",""), ",".join(meta.get("categories") or [])))')
if [[ "$CODE" == "200" && "$META_URL" == "/$PAGE_DIR/$USER_A/${NAME_A%.md}.html|" ]]; then
	ok "GET /api/article/content reports the built address of the page and no categories ($META_URL)"
else
	bad "the structured load reported '$META_URL' (HTTP $CODE)"
fi

say "3b. a file created outside the app is internal too"
# The Files app, WebDAV and the desktop client all write into the author's folder without the app
# noticing. Such a file is first seen by the index scan, and it must not arrive as approved content:
# nothing reaches the site before a reviewer approved it.
DAV_NAME="2026-01-05-probe-dav-${STAMP}.md"
CODE=$(dav "$USER_A" "$PW_A" PUT "/${AREA}/${DAV_NAME}" "$(printf -- '---\ntitle: DAV probe\ntags: davtag\n---\n\nWritten through WebDAV.\n')")
if [[ "$CODE" == "201" || "$CODE" == "204" ]]; then
	ok "the file was written straight into the author's folder (WebDAV $CODE)"
else
	bad "WebDAV write failed (HTTP $CODE)"
fi
CODE=$(aread a /api/articles/mine)
DAV_STATE=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if a["path"] == sys.argv[1]), None)
print("missing" if hit is None else hit["state"])
' "/${A_TARGET_ROOT}/${DAV_NAME}")
if [[ "$DAV_STATE" == "internal" ]]; then
	ok "the app registered it as internal, not as external"
else
	bad "a file created outside the app is registered as '$DAV_STATE' (must be internal)"
fi
CODE=$(aread a /api/articles "?external=true")
DAV_EXT=$(pyarg 'import json,sys;items=json.load(sys.stdin).get("articles") or [];print(sum(1 for a in items if a["path"]==sys.argv[1]))' "/${A_TARGET_ROOT}/${DAV_NAME}")
if [[ "$DAV_EXT" == "0" ]]; then
	ok "and it is not in the external list"
else
	bad "a file nobody approved is listed as external"
fi
dav "$USER_A" "$PW_A" DELETE "/${AREA}/${DAV_NAME}" > /dev/null

say "4. only approved content enters the commit tree, and the build serves it at the page address"
CODE=$(post /api/bridge/push '{}')
if [[ "$CODE" == "200" ]]; then
	ok "POST /api/bridge/push accepted the configuration with the author sources"
else
	bad "push failed (HTTP $CODE): $(head -c 200 "$OUT")"
fi
# the push only stores the configuration; the working copy is refreshed by a build (which is what the
# editor triggers when an author saves: notify -> mirror -> build)
CODE=$(post /api/site/build '{}')
if [[ "$CODE" == "200" ]]; then
	ok "POST /api/site/build ran the mirror for every source"
else
	bad "build failed (HTTP $CODE): $(head -c 200 "$OUT")"
fi
# The preview tree holds every author file, so an internal page is mirrored there and built; the
# commit tree holds the on-site set only, so the same page must not be there - and never in the
# commit. What reaches the site is decided by the reviewer, never by a save.
if [[ -f "$PREVIEW/$TARGET_A" ]]; then
	ok "the internal page is mirrored into the preview tree (the preview shows every file)"
else
	bad "an internal page is missing from the preview tree ($PREVIEW/$TARGET_A)"
fi
if [[ -e "$WORK/$TARGET_A" ]]; then
	bad "an internal page reached the commit tree ($WORK/$TARGET_A)"
else
	ok "and it is not in the commit tree (only the on-site set is)"
fi
COMMIT_TREE=$(git -C "$WORK" ls-tree -r --name-only HEAD 2>/dev/null)
if grep -qx "$TARGET_A" <<<"$COMMIT_TREE"; then
	bad "the internal page is in the commit - an unapproved file is public"
else
	ok "and it is not in the commit (publishing carries only external files)"
fi
# an image is an entry of its own, reviewed like a page
IMG_REL="images/logo-${STAMP}.png"
CODE=$(dav "$USER_A" "$PW_A" MKCOL "/${AREA}/images")
[[ "$CODE" == "201" || "$CODE" == "405" ]] || bad "cannot create the images folder (WebDAV $CODE)"
# a 1x1 PNG: a real binary file, so the mirror has something it must copy byte for byte
IMG_FILE="/tmp/pages-author-img-${STAMP}.png"
printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82' > "$IMG_FILE"
CODE=$(davfile "$USER_A" "$PW_A" "/${AREA}/${IMG_REL}" "$IMG_FILE")
if [[ "$CODE" == "201" || "$CODE" == "204" ]]; then
	ok "the author uploaded an image into their own folder ($AREA/$IMG_REL)"
else
	bad "the image upload failed (WebDAV $CODE)"
fi
IMG_TARGET="${A_TARGET_ROOT}/${IMG_REL}"
# every file of the area is an entry: the author's own list shows the image next to the page, and the
# server marks which one Jekyll renders as a document and which one it only copies
CODE=$(aread a /api/articles/mine)
MINE_TYPES=$(python3 -c '
import json, sys
items = json.load(sys.stdin).get("articles") or []
want = {"/" + sys.argv[1].lstrip("/"): "page", "/" + sys.argv[2].lstrip("/"): "image"}
found = {}
for item in items:
    if item.get("path") in want:
        found[want[item["path"]]] = item.get("renderable")
print("%s|%s" % (found.get("page"), found.get("image")))
' "/${TARGET_A}" "/${IMG_TARGET}" < "$OUT" 2>/dev/null)
if [[ "$CODE" == "200" && "$MINE_TYPES" == "True|False" ]]; then
	ok "the author's list carries both entries, with renderable true for the page and false for the image"
else
	bad "the author's list is wrong (HTTP $CODE, '$MINE_TYPES'; expected True|False): $(head -c 400 "$OUT")"
fi
CODE=$(post /api/bridge/push '{}'); CODE=$(post /api/site/build '{}')
if [[ -f "$PREVIEW/$IMG_TARGET" && ! -e "$WORK/$IMG_TARGET" ]]; then
	ok "the internal image is in the preview tree only (per file, the commit does not have it)"
else
	bad "the internal image is in the wrong tree (preview $([[ -f "$PREVIEW/$IMG_TARGET" ]] && echo yes || echo no), commit tree $([[ -e "$WORK/$IMG_TARGET" ]] && echo yes || echo no))"
fi
COMMIT_TREE=$(git -C "$WORK" ls-tree -r --name-only HEAD 2>/dev/null)
if grep -qx "$IMG_TARGET" <<<"$COMMIT_TREE"; then
	bad "the internal image is in the commit"
else
	ok "and the internal image is not in the commit"
fi
# request the page and the image, then approve both as the administrator (the review grant decides here;
# publishing is part of it, so the administrator approves and would publish with the same permission)
for target in "/${TARGET_A}" "/${IMG_TARGET}"; do
	CODE=$(asreq a POST /api/article/request '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "intent": "online"}))
' "$target")")
	if [[ "$CODE" == "200" ]]; then
		ok "the author requested online for $target"
	else
		bad "the request for $target answered HTTP $CODE: $(head -c 200 "$OUT")"
	fi
done
for target in "/${TARGET_A}" "/${IMG_TARGET}"; do
	CODE=$(post /api/article/decision "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "decision": "approve"}))
' "$target")")
	if [[ "$CODE" == "200" ]]; then
		ok "the reviewer approved $target"
	else
		bad "approving $target answered HTTP $CODE: $(head -c 200 "$OUT")"
	fi
done
CODE=$(post /api/bridge/push '{}')
CODE=$(post /api/site/build '{}')
if [[ "$CODE" == "200" ]]; then
	ok "the external file set was pushed and built"
else
	bad "push/build after the approval failed (HTTP $CODE): $(head -c 200 "$OUT")"
fi
# Approval is not publication: the approval wrote the published bytes into the commit tree, and the
# publish is the separate step that stages and commits it (and pushes when a remote is configured).
# The commit tree is **flat** - `source/` assembled at its root, `online/` laid over the same paths -
# so a target sits at `$WORK/<target>` and never below a `source/` prefix. A publish whose push the
# remote refused still commits, which is why 409 is an acceptable answer here (the commit is what
# this section checks).
CODE=$(post /api/site/publish "$(post_json '
import json
print(json.dumps({"message": "tests/live/test-authors.sh: publish the approved author probe"}))
')")
if [[ "$CODE" == "200" || "$CODE" == "409" ]]; then
	ok "POST /api/site/publish committed the approved content (HTTP $CODE)"
else
	bad "the publish after the approval answered HTTP $CODE: $(head -c 200 "$OUT")"
fi
if [[ -f "$WORK/$TARGET_A" ]]; then
	ok "the approved page reached the commit tree ($WORK/$TARGET_A)"
else
	bad "the approved page did not reach the commit tree ($WORK/$TARGET_A)"
fi
COMMIT_TREE=$(git -C "$WORK" ls-tree -r --name-only HEAD 2>/dev/null)
if grep -qx "$TARGET_A" <<<"$COMMIT_TREE"; then
	ok "and the commit carries the page at its flat site path ($TARGET_A)"
else
	bad "the commit does not carry the approved page at $TARGET_A (the commit tree is flat, so there is no 'source/' prefix)"
fi
if cmp -s "$IMG_FILE" "$WORK/$IMG_TARGET"; then
	ok "the approved image reached the commit tree byte for byte ($WORK/$IMG_TARGET)"
else
	bad "the approved image did not reach the commit tree unchanged ($WORK/$IMG_TARGET)"
fi
COMMIT_TREE=$(git -C "$WORK" ls-tree -r --name-only HEAD 2>/dev/null)
if grep -qx "$IMG_TARGET" <<<"$COMMIT_TREE"; then
	ok "and the commit carries the image at its flat site path ($IMG_TARGET)"
else
	bad "the commit does not carry the approved image at $IMG_TARGET"
fi
# The template's author mirror moves every file of `<author_root>/<uid>/…` to `<page_dir>/<uid>/…`
# before Jekyll reads the site, so that - not the mirror target - is the built address of the page.
if [[ -f "$BUILT/$PAGE_DIR/$USER_A/${NAME_A%.md}.html" ]]; then
	ok "the build renders the approved page at /$PAGE_DIR/$USER_A/${NAME_A%.md}.html"
else
	bad "the build did not render the approved page (looked for /_site/$PAGE_DIR/$USER_A/${NAME_A%.md}.html)"
fi
if cmp -s "$IMG_FILE" "$BUILT/$PAGE_DIR/$USER_A/$IMG_REL"; then
	ok "the build carries the approved image under /_site/$PAGE_DIR/$USER_A/$IMG_REL"
else
	bad "the build does not carry the approved image"
fi
# The author source table is what reports an author now: the new design has no author block in the
# bridge's status any more (content is event-driven, not a pushed target list), so the endpoint the
# admin panel reads is the one asserted here. Section 5 repeats the row with its exact counts; this
# check is the "the author is in the table at all" half.
CODE=$(get /api/author/sources)
ROW=$(pyarg '
import json, sys
rows = json.load(sys.stdin).get("authors") or []
hit = next((r for r in rows if r.get("uid") == sys.argv[1]), None)
print("missing" if hit is None else "%s|%s|%s" % (hit.get("uid"), hit.get("posts"), hit.get("files")))
' "$USER_A")
if [[ "$CODE" == "200" && "$ROW" =~ ^${USER_A}\|[0-9]+\|[0-9]+$ ]]; then
	ok "GET /api/author/sources reports the author source ($ROW)"
else
	bad "the author source table does not report the author (HTTP $CODE, got '$ROW')"
fi

say "5. the author source table"
CODE=$(get /api/author/sources)
ROW=$(pyarg '
import json, sys
rows = json.load(sys.stdin).get("authors") or []
hit = next((r for r in rows if r.get("uid") == sys.argv[1]), None)
print("missing" if hit is None else "%s|%s|%s|%s" % (hit.get("posts"), hit.get("files"), len(hit.get("missing") or []), hit.get("styles") is None))
' "$USER_A")
if [[ "$CODE" == "200" && "$ROW" == "1|2|0|True" ]]; then
	ok "GET /api/author/sources reports pages, files and missing files per author, with no stylesheet count"
else
	bad "author sources wrong (HTTP $CODE, '$ROW'; expected 1|2|0|True)"
fi
rm -f "$IMG_FILE"

say "6. the tree is the author's working surface (folders, new files, rename, delete)"
# The node's field order is the one BrowseController::withTarget() returns and the one this line
# prints: `scope`, `target_prefix`, then - per node - `kind` (`dir`/`page`/`resource`), `state`
# (`internal`/`requested`/`external`, derived from `online/` and the manifest's down request), `live`
# (`online/<target>` exists, i.e. the site serves the version) and `target` (the tree is addressed by
# targets, folders included, so it must be there). The page was approved in section 4, so the site now
# serves it: `state` is `external` and `live` is true.
CODE=$(aread a /api/site/tree)
TREE=$(py "
import json, sys
d = json.load(sys.stdin)
nodes = {n['path']: n for n in (d.get('nodes') or [])}
page = nodes.get('$(basename "$NAME_A")') or {}
print('%s|%s|%s|%s|%s|%s' % (d.get('scope'), d.get('target_prefix'), page.get('kind'), page.get('state'), page.get('live'), bool(page.get('target'))))
")
if [[ "$CODE" == "200" && "$TREE" == "author|/$A_TARGET_ROOT|page|external|True|True" ]]; then
	ok "GET /api/site/tree answers the author scope with the whole tree, targets and the visibility state ($TREE)"
else
	bad "the author tree is wrong (HTTP $CODE, '$TREE'; expected author|/$A_TARGET_ROOT|page|external|True|True)"
fi
CODE=$(acreate a /api/site/folder "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1]}))
' "/${A_TARGET_ROOT}/notes")")
DIR_OK=0
[[ "$CODE" == "200" ]] && DIR_OK=1
CODE=$(acreate a /api/site/file "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\ntitle: A page in a folder\n---\n\nBody of the page.\n"}))
' "/${A_TARGET_ROOT}/notes/a.md")")
if [[ "$DIR_OK" == "1" && ( "$CODE" == "200" || "$CODE" == "201" ) ]]; then
	ok "the author created a folder and a page inside it through the tree endpoints (notes/a.md)"
else
	bad "creating a folder and a page in it failed (folder, then file: HTTP $CODE): $(head -c 300 "$OUT")"
fi
CODE=$(aread a /api/site/tree)
TREE2=$(py "
import json, sys
d = json.load(sys.stdin)
nodes = {n['path']: n for n in (d.get('nodes') or [])}
print('%s|%s' % (nodes.get('notes', {}).get('kind'), bool(nodes.get('notes', {}).get('target'))))
")
if [[ "$TREE2" == "dir|True" ]]; then
	ok "the new folder is a node of the tree and carries a target ($TREE2)"
else
	bad "the new folder is not in the tree with a target ('$TREE2')"
fi
# the page's built address: no usable category, so the template mirrors it under <page_dir>/<uid>/
CODE=$(aread a /api/article/content "?path=$(q "/${A_TARGET_ROOT}/notes/a.md")")
PAGE_URL=$(py 'import json,sys;print((json.load(sys.stdin).get("meta") or {}).get("url",""))')
if [[ "$CODE" == "200" && "$PAGE_URL" == "/$PAGE_DIR/$USER_A/notes/a.html" ]]; then
	ok "a page in a folder is served at /$PAGE_DIR/$USER_A/notes/a.html ($PAGE_URL)"
else
	bad "the page's address is '$PAGE_URL' (HTTP $CODE), expected /$PAGE_DIR/$USER_A/notes/a.html"
fi
# rename through the tree: the new target is what the editor opens afterwards
CODE=$(acreate a /api/site/move "$(post_json '
import json, sys
print(json.dumps({"from": sys.argv[1], "to": sys.argv[2]}))
' "/${A_TARGET_ROOT}/notes/a.md" "/${A_TARGET_ROOT}/notes/b.md")")
RENAMED_STATE=$(py 'import json,sys;d=json.load(sys.stdin);print("%s|%s" % (d.get("path"), d.get("from")))')
if [[ "$CODE" == "200" && "$RENAMED_STATE" == "$A_TARGET_ROOT/notes/b.md|$A_TARGET_ROOT/notes/a.md" ]]; then
	ok "renaming a page answers with both ends ($RENAMED_STATE)"
else
	bad "the rename answered HTTP $CODE, '$RENAMED_STATE'"
fi
GONE=$(dav "$USER_A" "$PW_A" PROPFIND "/${AREA}/notes/a.md")
if [[ "$GONE" == "404" ]]; then
	ok "the old name is gone from the author's folder"
else
	bad "the old name still answers (WebDAV $GONE)"
fi
# deleting is direct: the author's copy goes, but it is not a take-down - the page leaves the site only
# when a reviewer takes it down (the delete itself files the take-off request)
CODE=$(asreq a DELETE /api/site/file "?path=$(q "/${A_TARGET_ROOT}/notes/b.md")")
STILL=$(dav "$USER_A" "$PW_A" PROPFIND "/${AREA}/notes/b.md")
if [[ "$CODE" == "200" && "$STILL" == "404" ]]; then
	ok "deleting a page is a direct delete (HTTP 200, the file is gone)"
else
	bad "the delete answered HTTP $CODE, file state $STILL"
fi
CODE=$(acreate a /api/site/move "$(post_json '
import json, sys
print(json.dumps({"from": sys.argv[1], "to": sys.argv[2]}))
' "/${A_TARGET_ROOT}/notes" "/${A_TARGET_ROOT}/scratch")")
CODE=$(asreq a DELETE /api/site/file "?path=$(q "/${A_TARGET_ROOT}/scratch")")
if [[ "$CODE" == "200" ]]; then
	ok "an empty folder is deleted through the tree endpoint (HTTP 200)"
else
	bad "deleting the folder answered HTTP $CODE: $(head -c 200 "$OUT")"
fi

say "7. categories: one copy per usable category at the site root"
if [[ "$HAS_GUIDES" != "1" ]]; then
	# Not a failure: the branch needs the site's own template to have a category page at its root, and
	# a site that ships without `guides.md` simply cannot prove it. SKIP is what the suite reports.
	printf '  \033[33m-\033[0m SKIP: the site has no guides.md in its source/ folder, so the category branch cannot be proven\n'
else
	# no title: the plugin falls back to the file name, so the built copy keeps it
	CAT_NAME="notes/cat-${STAMP}.md"
	CODE=$(acreate a /api/site/file "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\ncategories: [guides]\n---\n\nIn the guides category.\n"}))
' "/${A_TARGET_ROOT}/$CAT_NAME")")
	if [[ "$CODE" == "200" || "$CODE" == "201" ]]; then
		ok "a page with categories was created ($CAT_NAME)"
	else
		bad "creating the category page failed (HTTP $CODE): $(head -c 200 "$OUT")"
	fi
	CODE=$(aread a /api/article/content "?path=$(q "/${A_TARGET_ROOT}/$CAT_NAME")")
	CAT_ANSWER=$(py 'import json,sys;m=json.load(sys.stdin).get("meta") or {};print("%s|%s" % (",".join(m.get("categories") or []), m.get("url","")))')
	if [[ "$CODE" == "200" && "$CAT_ANSWER" == "guides|/guides/cat-${STAMP}.html" ]]; then
		ok "the panel reports the category and the category address ($CAT_ANSWER)"
	else
		bad "the category page reports '$CAT_ANSWER' (HTTP $CODE), expected guides|/guides/cat-${STAMP}.html"
	fi
	# the same page with a title: the copy is named after the slugified title, not after the file
	TITLED_NAME="notes/titled-${STAMP}.md"
	CODE=$(acreate a /api/site/file "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\ntitle: Hello World\ncategories: [guides]\n---\n\nA titled page.\n"}))
' "/${A_TARGET_ROOT}/$TITLED_NAME")")
	CODE=$(aread a /api/article/content "?path=$(q "/${A_TARGET_ROOT}/$TITLED_NAME")")
	TITLED_URL=$(py 'import json,sys;print((json.load(sys.stdin).get("meta") or {}).get("url",""))')
	if [[ "$TITLED_URL" == "/guides/hello-world.html" ]]; then
		ok "a category copy of a titled page is named after the title slug ($TITLED_URL)"
	else
		bad "the titled category page reports '$TITLED_URL', expected /guides/hello-world.html"
	fi
	# a category the site has no page for is not usable: the page keeps the fallback address
	UNUSABLE_NAME="notes/unusable-${STAMP}.md"
	CODE=$(acreate a /api/site/file "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\ncategories: [no-such-category]\n---\n\nNowhere.\n"}))
' "/${A_TARGET_ROOT}/$UNUSABLE_NAME")")
	CODE=$(aread a /api/article/content "?path=$(q "/${A_TARGET_ROOT}/$UNUSABLE_NAME")")
	UNUSABLE_URL=$(py 'import json,sys;print((json.load(sys.stdin).get("meta") or {}).get("url",""))')
	if [[ "$UNUSABLE_URL" == "/$PAGE_DIR/$USER_A/notes/unusable-${STAMP}.html" ]]; then
		ok "a category without a site page leaves the page at its fallback address ($UNUSABLE_URL)"
	else
		bad "an unusable category produced '$UNUSABLE_URL' (expected the fallback address)"
	fi
	CODE=$(asreq a DELETE /api/site/file "?path=$(q "/${A_TARGET_ROOT}/$UNUSABLE_NAME")")
	# validation: an invalid name, a name with a separator and too many
	CODE=$(aread a /api/article/content "?path=$(q "/${A_TARGET_ROOT}/$CAT_NAME")")
	ETAG=$(py 'import json,sys;print(json.load(sys.stdin).get("etag",""))')
	BAD_CAT=$(asreq a PUT /api/article/content '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "body": "x", "baseEtag": sys.argv[2], "meta": {"categories": ["Not Allowed"]}}))
' "/${A_TARGET_ROOT}/$CAT_NAME" "$ETAG")")
	SLASH_CAT=$(asreq a PUT /api/article/content '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "body": "x", "baseEtag": sys.argv[2], "meta": {"categories": ["a/b"]}}))
' "/${A_TARGET_ROOT}/$CAT_NAME" "$ETAG")")
	MANY_CAT=$(asreq a PUT /api/article/content '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "body": "x", "baseEtag": sys.argv[2], "meta": {"categories": sys.argv[3].split(",")}}))
' "/${A_TARGET_ROOT}/$CAT_NAME" "$ETAG" "c1,c2,c3,c4,c5,c6")")
	if [[ "$BAD_CAT" == "400" && "$SLASH_CAT" == "400" && "$MANY_CAT" == "400" ]]; then
		ok "an uppercase name, a name with a separator and six categories are all refused (HTTP 400)"
	else
		bad "category validation is wrong (uppercase $BAD_CAT, separator $SLASH_CAT, six $MANY_CAT; expected 400 each)"
	fi
	# dedupe and round trip: the same name twice is one category
	CODE=$(aread a /api/article/content "?path=$(q "/${A_TARGET_ROOT}/$CAT_NAME")")
	ETAG=$(py 'import json,sys;print(json.load(sys.stdin).get("etag",""))')
	CODE=$(asreq a PUT /api/article/content '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "body": "Body after the category edit.", "baseEtag": sys.argv[2], "meta": {"categories": ["guides", "guides"]}}))
' "/${A_TARGET_ROOT}/$CAT_NAME" "$ETAG")")
	DUP=$(py 'import json,sys;m=json.load(sys.stdin).get("meta") or {};print(",".join(m.get("categories") or []))')
	if [[ "$CODE" == "200" && "$DUP" == "guides" ]]; then
		ok "a duplicated category is written once and read back as one ($DUP)"
	else
		bad "the duplicate category answered HTTP $CODE, read back '$DUP'"
	fi
	# approve + build: the category copy is what the build produces
	for target in "/${A_TARGET_ROOT}/$CAT_NAME" "/${A_TARGET_ROOT}/$TITLED_NAME"; do
		CODE=$(asreq a POST /api/article/request '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "intent": "online"}))
' "$target")")
	done
	for target in "/${A_TARGET_ROOT}/$CAT_NAME" "/${A_TARGET_ROOT}/$TITLED_NAME"; do
		CODE=$(post /api/article/decision "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "decision": "approve"}))
' "$target")")
	done
	CODE=$(post /api/bridge/push '{}'); CODE=$(post /api/site/build '{}')
	if [[ "$CODE" == "200" && -f "$BUILT/guides/cat-${STAMP}.html" ]]; then
		ok "the approved page is built at /guides/cat-${STAMP}.html (one copy per usable category)"
	else
		bad "the category copy is not in the build (HTTP $CODE; looked for /_site/guides/cat-${STAMP}.html)"
	fi
	if [[ -f "$BUILT/guides/hello-world.html" ]]; then
		ok "the titled page is built at its slug address (/guides/hello-world.html)"
	else
		bad "the titled page is not at /_site/guides/hello-world.html"
	fi
	if [[ ! -e "$BUILT/$PAGE_DIR/$USER_A/$CAT_NAME" ]]; then
		ok "and it is not also served under the fallback address"
	else
		bad "the category page is served twice (fallback and category copy)"
	fi
	CODE=$(aread a /api/site/tree)
	CAT_LIVE=$(py "
import json, sys
d = json.load(sys.stdin)
nodes = {n['path']: n for n in (d.get('nodes') or [])}
node = nodes.get('$CAT_NAME') or {}
print('%s|%s' % (node.get('state'), node.get('live')))
")
	if [[ "$CAT_LIVE" == "external|True" ]]; then
		ok "the tree reports the approved page as live, which is what online/ decides ($CAT_LIVE)"
	else
		bad "the tree reports the approved page as '$CAT_LIVE', expected external|True"
	fi
fi
say "7b. deleting a page the site serves is a take-off request, and the site keeps serving it"
# A delete is not a take-down. The site keeps serving the version a reviewer approved, so the entry
# has to stay - it is the only place the take-off can be decided from - and it waits as `requested`
# with the take-off request the author expressed by deleting the file. `live` stays true because
# `online/<target>` still holds the published bytes: removing them is the reviewer's decision, and
# the next publish carries that removal out of the commit.
CODE=$(asreq a DELETE /api/site/file "?path=$(q "/${TARGET_A}")")
GONE=$(dav "$USER_A" "$PW_A" PROPFIND "/${AREA}/${NAME_A}")
if [[ "$CODE" == "200" && "$GONE" == "404" ]]; then
	ok "the page is deleted directly - the trash keeps the copy ($GONE)"
else
	bad "the delete answered HTTP $CODE, file state $GONE"
fi
# The row is read out of the reading list: the file is gone, so the tree has no node for it any
# more, while a source-deleted take-off request stays listed - that is what lets a reviewer answer
# it. `state` is derived (a down request means `requested`), `live` is `online/`, and the two
# booleans are the request the delete filed (`downRequest`, `sourceDeleted`).
CODE=$(aread a /api/articles)
PAGE_AFTER=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if a["path"] == sys.argv[1]), None)
print("missing" if hit is None else "%s|%s|%s|%s" % (hit["state"], hit["live"], hit["downRequest"], hit["sourceDeleted"]))
' "/${TARGET_A}")
if [[ "$CODE" == "200" && "$PAGE_AFTER" == "requested|True|True|True" ]]; then
	ok "the entry stays as the take-off request it became, and the published version stays on the site ($PAGE_AFTER)"
else
	bad "the deleted page reads '$PAGE_AFTER' (HTTP $CODE), expected requested|True|True|True (state, live, downRequest, sourceDeleted)"
fi

# The site folder's `online/` and `pending/` are the published and waiting record (the manifest only
# carries what the folders cannot). A move of the author content directory has to carry them with it,
# so the suite reads them off the disk - `SITE_LAYERS` is the site folder section 0 resolved from the
# configuration, never a guessed path.
in_site_layer() { # in_site_layer <online|pending> <target> → 1 when that layer holds the target
	[[ -f "$SITE_LAYERS/$1/$2" ]] && echo 1 || echo 0
}

layer_targets() { # layer_targets <online|pending> <prefix> → the targets under that prefix, one per line
	[[ -d "$SITE_LAYERS/$1" ]] || return 0
	(cd "$SITE_LAYERS/$1" 2>/dev/null && find "${2%/}" -type f 2>/dev/null | LC_ALL=C sort)
}

layer_reroot() { # layer_reroot <from-prefix> <to-prefix> <targets> → the same targets under the new name
	local from="${1%/}" to="${2%/}" target
	while IFS= read -r target; do
		[[ -n "$target" ]] || continue
		printf '%s/%s\n' "$to" "${target#"$from"/}"
	done <<< "${3:-}"
}

# `<checked>|<wrong>`: the targets the move had to carry, and the failed checks (a target still under
# the old name or missing under the new one).
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

say "8. the author content directory is validated, movable, and restored"
# The author content directory is *not* locked by content: moving it is a supported operation, and the
# move **carries the content with it** (every `online/` and `pending/` target under the old prefix is
# rewritten under the new one; then the app re-keys the manifest, drops the bridge's old approved set,
# and the one-shot overwrite rebuilds the trees under the new directory). Only the shape is validated
# here; the move itself is exercised below. The current value is echoed on GET (the form needs it, and
# a rejected save must not lose it), and the suite compares against the value it read at the start,
# not against a literal.
get /api/admin/config > /dev/null
if [[ "$(py 'import json,sys;print((json.load(sys.stdin).get("config") or {}).get("author_root",""))')" == "$AUTHOR_ROOT" ]]; then
	ok "GET /api/admin/config echoes the current author content directory ($AUTHOR_ROOT)"
else
	bad "the current author content directory is not echoed (expected '$AUTHOR_ROOT')"
fi
for value in "" "a/b" "_posts" ".."; do
	CODE=$(put /api/admin/config "$(post_json '
import json, sys
print(json.dumps({"authorRoot": sys.argv[1]}))
' "$value")")
	if [[ "$CODE" == "400" ]]; then
		ok "author content directory '${value}' is refused (HTTP 400)"
	else
		bad "author content directory '${value}' answered HTTP $CODE (expected 400)"
	fi
done
# A real move is accepted even while the manifest carries entries. If the target directory already
# exists inside the site, the bridge answers 409 CONFIRM_REQUIRED and the client retries with
# confirm:true - the same flow src/admin.js implements.
move_root() { # move_root <directory> → HTTP code; a 409 CONFIRM_REQUIRED is retried with confirm:true
	local dir="$1" code err
	code=$(req POST /api/site/author-root '' "$(post_json '
import json, sys
print(json.dumps({"authorRoot": sys.argv[1]}))
' "$dir")")
	if [[ "$code" == "409" ]]; then
		err=$(py 'import json,sys;print(json.load(sys.stdin).get("error",""))')
		if [[ "$err" == "CONFIRM_REQUIRED" ]]; then
			code=$(req POST /api/site/author-root '' "$(post_json '
import json, sys
print(json.dumps({"authorRoot": sys.argv[1], "confirm": True}))
' "$dir")")
		fi
	fi
	printf '%s' "$code"
}
ALT_ROOT="${AUTHOR_ROOT}-moved"
# The move carries the content it already holds: the pages sections 4 and 7 published, and any
# candidate a run left waiting. They are read before the move, because the move is what rewrites them.
ONLINE_MOVING=$(layer_targets online "$AUTHOR_ROOT")
PENDING_MOVING=$(layer_targets pending "$AUTHOR_ROOT")
CODE=$(move_root "$ALT_ROOT")
# the answer's own account of the move (the body of the accepted call is still in $OUT)
MOVED_COUNTS=$(py 'import json,sys
d = json.load(sys.stdin)
m = d.get("moved") or {}
print("%s|%s" % (m.get("online", "?"), m.get("pending", "?")))')
get /api/admin/config > /dev/null
MOVED=$(py 'import json,sys;print((json.load(sys.stdin).get("config") or {}).get("author_root",""))')
if [[ "$CODE" == "200" && "$MOVED" == "$ALT_ROOT" ]]; then
	ok "a move to '$ALT_ROOT' is accepted while content exists (HTTP 200)"
else
	bad "the author content directory move failed (HTTP $CODE, root '$MOVED', expected '$ALT_ROOT')"
fi
MOVED_ONLINE=$(layer_moved online "$AUTHOR_ROOT" "$ALT_ROOT" "$ONLINE_MOVING")
MOVED_PENDING=$(layer_moved pending "$AUTHOR_ROOT" "$ALT_ROOT" "$PENDING_MOVING")
if [[ "$MOVED_COUNTS" == "${MOVED_ONLINE%%|*}|${MOVED_PENDING%%|*}" ]]; then
	ok "and the answer reports the same counts the layers held (moved '$MOVED_COUNTS', online|pending)"
else
	bad "the move answer reports '$MOVED_COUNTS', the layers held '${MOVED_ONLINE%%|*}|${MOVED_PENDING%%|*}' (online|pending)"
fi
# A move that only renamed the setting would leave every published page on an address no review
# action accepts any more: the files have to be rewritten under the new directory, and nothing may
# stay under the old one. At least one published page is expected (sections 4 and 7 published some).
if [[ "${MOVED_ONLINE#*|}" == "0" && "${MOVED_PENDING#*|}" == "0" && "${MOVED_ONLINE%%|*}" != "0" ]]; then
	ok "and the published content moved with it (${MOVED_ONLINE%%|*} published and ${MOVED_PENDING%%|*} waiting file(s) under '$ALT_ROOT', none left under '$AUTHOR_ROOT')"
else
	bad "the move did not carry the published content (online '<checked>|<wrong>' $MOVED_ONLINE, pending $MOVED_PENDING; expected at least one checked file and no failed check)"
fi
# the same list, re-rooted, is what the restore has to carry back
ONLINE_AT_ALT=$(layer_reroot "$AUTHOR_ROOT" "$ALT_ROOT" "$ONLINE_MOVING")
PENDING_AT_ALT=$(layer_reroot "$AUTHOR_ROOT" "$ALT_ROOT" "$PENDING_MOVING")
# Restore the original directory no matter how the move went: the same channel and the same 409→confirm
# handling. The final read is the assertion, so a refused restore is reported instead of hidden.
CODE_BACK=$(move_root "$ROOT_BEFORE")
BACK_COUNTS=$(py 'import json,sys
d = json.load(sys.stdin)
m = d.get("moved") or {}
print("%s|%s" % (m.get("online", "?"), m.get("pending", "?")))')
get /api/admin/config > /dev/null
ROOT_FINAL=$(py 'import json,sys;print((json.load(sys.stdin).get("config") or {}).get("author_root",""))')
if [[ "$ROOT_FINAL" == "$ROOT_BEFORE" ]]; then
	ok "the author content directory is restored to '$ROOT_BEFORE' (restore HTTP $CODE_BACK)"
else
	bad "the author content directory could not be restored: now '$ROOT_FINAL', expected '$ROOT_BEFORE' (restore HTTP $CODE_BACK)"
fi
BACK_ONLINE=$(layer_moved online "$ALT_ROOT" "$ROOT_BEFORE" "$ONLINE_AT_ALT")
BACK_PENDING=$(layer_moved pending "$ALT_ROOT" "$ROOT_BEFORE" "$PENDING_AT_ALT")
if [[ "$BACK_COUNTS" == "${BACK_ONLINE%%|*}|${BACK_PENDING%%|*}" ]]; then
	ok "and the restore answer reports the counts it carried back (moved '$BACK_COUNTS', online|pending)"
else
	bad "the restore answer reports '$BACK_COUNTS', the layers held '${BACK_ONLINE%%|*}|${BACK_PENDING%%|*}' (online|pending)"
fi
if [[ "${BACK_ONLINE#*|}" == "0" && "${BACK_PENDING#*|}" == "0" ]]; then
	ok "and the restore carried it back (${BACK_ONLINE%%|*} published and ${BACK_PENDING%%|*} waiting file(s) under '$ROOT_BEFORE' again)"
else
	bad "the restore did not carry the content back (online '<checked>|<wrong>' $BACK_ONLINE, pending $BACK_PENDING; expected '<n>|0' for both)"
fi
# the same rule on the site folder: a locked PUT /api/site keeps the stored value, while the branch
# (which does not name content) still goes through
get /api/site > /dev/null
SITE_PATH=$(py 'import json,sys;print((json.load(sys.stdin).get("site") or {}).get("nc_path",""))')
CHANGED=$(py '
import json, sys
site = json.load(sys.stdin).get("site") or {}
site["nc_path"] = "/Sites/does-not-matter"
print(json.dumps(site))')
if [[ -n "$SITE_PATH" ]]; then
	CODE=$(put /api/site "$CHANGED")
	get /api/site > /dev/null
	AFTER=$(py 'import json,sys;print((json.load(sys.stdin).get("site") or {}).get("nc_path",""))')
	if [[ "$CODE" == "400" && "$AFTER" == "$SITE_PATH" ]]; then
		ok "the site folder is locked against a change while content exists (400, stored value kept)"
		# nothing of the site configuration was changed, so the exit trap need not restore it
		RESTORED=1
	else
		bad "the locked site folder answered HTTP $CODE and now reads '$AFTER' (expected $SITE_PATH)"
		# leave RESTORED at 0: the trap puts the site configuration read at the start back
	fi
else
	bad "cannot read the stored site folder for the lock check"
fi

say "9. the doctor checks that the site reads the mirror from where the app writes it"
# The site's Jekyll config declares the directory its own author mirror reads (`authors.source`), while
# the app writes every author tree under the author content directory. When the two disagree no author
# page is ever built and nothing reports it, so the doctor has to name both and fail on a mismatch. The
# healthy branch is asserted first; the mismatch branch is provoked by editing the site's `_config.yml`
# (backed up and restored byte for byte below, whatever happens).
DOCTOR=/tmp/ta-doctor.txt
CONFIG_FILE="$SITE_SOURCE/_config.yml"
CONFIG_BACKUP=/tmp/ta-config.yml
if [[ ! -f "$CONFIG_FILE" ]]; then
	printf '  \033[33m-\033[0m SKIP: the site has no source/_config.yml at %s, so the author source cannot be compared\n' "$CONFIG_FILE"
else
	occ pages:doctor > "$DOCTOR" 2>&1 || true
	if grep -q 'site author source' "$DOCTOR" && grep -qF "authors.source: ${AUTHOR_ROOT}" "$DOCTOR"; then
		ok "the doctor reports the site author source and it is the author content directory (${AUTHOR_ROOT})"
	else
		bad "the doctor does not report a matching site author source: $(grep -i 'author source' "$DOCTOR" | head -2)"
	fi
	cp "$CONFIG_FILE" "$CONFIG_BACKUP"
	# Point authors.source somewhere else, so the doctor's mismatch branch is exercised. The edit is
	# asserted: a `sed` that matches nothing would leave the site healthy and the checks below would
	# report a false failure.
	MISMATCH="authors-mismatch-${STAMP}"
	MISMATCH_SET=0
	if grep -qF "  source: ${AUTHOR_ROOT}" "$CONFIG_FILE"; then
		sed -i "s|^  source: .*$|  source: ${MISMATCH}|" "$CONFIG_FILE"
		if grep -qF "  source: ${MISMATCH}" "$CONFIG_FILE"; then
			MISMATCH_SET=1
		else
			bad "the source/_config.yml edit did not take effect: authors.source still reads '$(grep -m1 '^  source:' "$CONFIG_FILE")'"
		fi
	else
		printf '  \033[33m-\033[0m SKIP: source/_config.yml has no "  source: %s" line, so the mismatch cannot be provoked\n' "$AUTHOR_ROOT"
	fi
	if [[ "$MISMATCH_SET" == "1" ]]; then
		occ pages:doctor > "$DOCTOR" 2>&1 || true
		if grep -q 'site author source' "$DOCTOR" && grep -q 'invisible to the site' "$DOCTOR"; then
			ok "a site whose authors.source is another directory is reported as ✗ (the mirror is invisible)"
		else
			bad "the doctor did not report the mismatched author source"
		fi
		if grep -q 'Some checks failed' "$DOCTOR"; then
			ok "the mismatch is a failing check, not a warning"
		else
			bad "the mismatch did not fail the doctor"
		fi
	fi
	cp "$CONFIG_BACKUP" "$CONFIG_FILE"
	occ pages:doctor > "$DOCTOR" 2>&1 || true
	if [[ "$MISMATCH_SET" == "1" ]]; then
		if grep -q 'site author source' "$DOCTOR" && ! grep -q 'invisible to the site' "$DOCTOR"; then
			ok "the restored authors.source checks out again (✓)"
		else
			bad "the doctor still reports the mismatched author source after the restore"
		fi
	fi
	if cmp -s "$CONFIG_BACKUP" "$CONFIG_FILE"; then
		ok "the site's source/_config.yml is restored byte for byte"
	else
		bad "the site's source/_config.yml was not restored ($CONFIG_FILE)"
	fi
	if grep -q 'author content directory' "$DOCTOR"; then
		ok "the doctor still reports the author content directory"
	else
		bad "the doctor no longer reports the author content directory"
	fi
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
