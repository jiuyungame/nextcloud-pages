#!/usr/bin/env bash
#
# Authoring acceptance (plan P2/P3): each author works in their own Nextcloud folder, the app is the
# ownership authority, and the bridge mirrors those folders into the one site.
#
#   sudo ./tests/test-authors.sh
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
TARGET_A="tmp/${USER_A}/${NAME_A}"
TARGET_B="tmp/${USER_B}/${NAME_B}"
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
RESTORED=0

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

cleanup() {
	if [[ "$RESTORED" -eq 0 && -n "$TOKEN" ]]; then
		req PUT /api/site '' "$SITE_BEFORE" > /dev/null 2>&1 || true
		req POST /api/bridge/push '' '{}' > /dev/null 2>&1 || true
	fi
	# the posts live in the authors' own files, so deleting the users deletes them too
	for u in "$USER_A" "$USER_B"; do occ user:delete "$u" >/dev/null 2>&1 || true; done
	rm -f "$OUT" "$ADMIN_JAR" "$A_JAR" "$B_JAR" "$LOGIN"
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
	occ user:delete "$1" >/dev/null 2>&1 || true
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
mkuser "$USER_A" "$PW_A"
mkuser "$USER_B" "$PW_B"
# the review endpoints require the authoring grant; this suite's author joins an existing author group
# (deleting the user at the end removes the membership with it)
occ group:adduser test2 "$USER_A" >/dev/null 2>&1 || true
[[ -n "$(login "$A_JAR" "$USER_A" "$PW_A")" ]] || { bad "$USER_A cannot log in"; exit 1; }
[[ -n "$(login "$B_JAR" "$USER_B" "$PW_B")" ]] || { bad "$USER_B cannot log in"; exit 1; }
ok "admin ($NC_USER), $USER_A and $USER_B are ready (author area '$AREA')"

# The site's own template configuration decides the built address of every author page (see
# PageUrlService), so it is read here rather than assumed: a site without `guides.md` at its root
# cannot route a page to /guides/.
SITE_NCUSER=$(occ config:app:get pages site 2>/dev/null | python3 -c 'import json,sys;print((json.load(sys.stdin).get("nc_user") or ""))' 2>/dev/null || echo '')
SITE_NCPATH=$(occ config:app:get pages site 2>/dev/null | python3 -c 'import json,sys;print((json.load(sys.stdin).get("nc_path") or ""))' 2>/dev/null || echo '')
SITE_ROOT="/var/snap/nextcloud/common/nextcloud/data/${SITE_NCUSER}/files${SITE_NCPATH}"
HAS_GUIDES=0
[[ -f "$SITE_ROOT/guides.md" ]] && HAS_GUIDES=1

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
' "/tmp/${USER_A}/${DAV_NAME}")
if [[ "$DAV_STATE" == "internal" ]]; then
	ok "the app registered it as internal, not as external"
else
	bad "a file created outside the app is registered as '$DAV_STATE' (must be internal)"
fi
CODE=$(aread a /api/articles "?external=true")
DAV_EXT=$(pyarg 'import json,sys;items=json.load(sys.stdin).get("articles") or [];print(sum(1 for a in items if a["path"]==sys.argv[1]))' "/tmp/${USER_A}/${DAV_NAME}")
if [[ "$DAV_EXT" == "0" ]]; then
	ok "and it is not in the external list"
else
	bad "a file nobody approved is listed as external"
fi
dav "$USER_A" "$PW_A" DELETE "/${AREA}/${DAV_NAME}" > /dev/null

say "4. only approved files are mirrored, and the build serves them at the page address"
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
if git -C "$WORK" ls-tree -r --name-only HEAD 2>/dev/null | grep -qx "$TARGET_A"; then
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
IMG_TARGET="tmp/${USER_A}/${IMG_REL}"
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
if git -C "$WORK" ls-tree -r --name-only HEAD 2>/dev/null | grep -qx "$IMG_TARGET"; then
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
if [[ -f "$WORK/$TARGET_A" ]]; then
	ok "the approved page reached the commit tree ($WORK/$TARGET_A)"
else
	bad "the approved page did not reach the commit tree"
fi
if cmp -s "$IMG_FILE" "$WORK/$IMG_TARGET"; then
	ok "the approved image reached the commit tree byte for byte ($WORK/$IMG_TARGET)"
else
	bad "the approved image did not reach the commit tree unchanged"
fi
# The template's author mirror moves every file of `tmp/<uid>/…` to `<page_dir>/<uid>/…` before Jekyll
# reads the site, so that - not the mirror target - is the built address of the page.
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
get /api/site/status > /dev/null
AUTHORS=$(py 'import json,sys;d=json.load(sys.stdin);a=d.get("authors") or [];print(",".join(str(x.get("uid")) for x in a))')
if printf '%s' "$AUTHORS" | grep -q "$USER_A"; then
	ok "the status reports the author source ($AUTHORS)"
else
	bad "the status does not report the author source (got '$AUTHORS')"
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
CODE=$(aread a /api/site/tree)
TREE=$(py "
import json, sys
d = json.load(sys.stdin)
nodes = {n['path']: n for n in (d.get('nodes') or [])}
page = nodes.get('$(basename "$NAME_A")') or {}
print('%s|%s|%s|%s|%s|%s' % (d.get('scope'), d.get('target_prefix'), page.get('kind'), page.get('state'), page.get('live'), bool(page.get('target'))))
")
if [[ "$CODE" == "200" && "$TREE" == "author|/tmp/$USER_A|page|external|False|True" ]]; then
	ok "GET /api/site/tree answers the author scope with the whole tree, targets and the visibility state ($TREE)"
else
	bad "the author tree is wrong (HTTP $CODE, '$TREE')"
fi
CODE=$(acreate a /api/site/folder "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1]}))
' "notes")")
DIR_OK=0
[[ "$CODE" == "200" ]] && DIR_OK=1
CODE=$(acreate a /api/site/file "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\ntitle: A page in a folder\n---\n\nBody of the page.\n"}))
' "notes/a.md")")
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
CODE=$(aread a /api/article/content "?path=$(q "/tmp/$USER_A/notes/a.md")")
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
' "/tmp/$USER_A/notes/a.md" "/tmp/$USER_A/notes/b.md")")
RENAMED_STATE=$(py 'import json,sys;d=json.load(sys.stdin);print("%s|%s" % (d.get("path"), d.get("from")))')
if [[ "$CODE" == "200" && "$RENAMED_STATE" == "tmp/$USER_A/notes/b.md|tmp/$USER_A/notes/a.md" ]]; then
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
# deleting is direct now: the file goes into the trash and the next publish takes the page off the site
CODE=$(asreq a DELETE /api/site/file "?path=$(q "/tmp/$USER_A/notes/b.md")")
STILL=$(dav "$USER_A" "$PW_A" PROPFIND "/${AREA}/notes/b.md")
if [[ "$CODE" == "200" && "$STILL" == "404" ]]; then
	ok "deleting a page is a direct delete (HTTP 200, the file is gone)"
else
	bad "the delete answered HTTP $CODE, file state $STILL"
fi
CODE=$(acreate a /api/site/move "$(post_json '
import json, sys
print(json.dumps({"from": sys.argv[1], "to": sys.argv[2]}))
' "/tmp/$USER_A/notes" "/tmp/$USER_A/scratch")")
CODE=$(asreq a DELETE /api/site/file "?path=$(q "/tmp/$USER_A/scratch")")
if [[ "$CODE" == "200" ]]; then
	ok "an empty folder is deleted through the tree endpoint (HTTP 200)"
else
	bad "deleting the folder answered HTTP $CODE: $(head -c 200 "$OUT")"
fi

say "7. categories: one copy per usable category at the site root"
if [[ "$HAS_GUIDES" != "1" ]]; then
	bad "the site has no guides.md at its root: the category branch cannot be proven"
else
	# no title: the plugin falls back to the file name, so the built copy keeps it
	CAT_NAME="notes/cat-${STAMP}.md"
	CODE=$(acreate a /api/site/file "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\ncategories: [guides]\n---\n\nIn the guides category.\n"}))
' "$CAT_NAME")")
	if [[ "$CODE" == "200" || "$CODE" == "201" ]]; then
		ok "a page with categories was created ($CAT_NAME)"
	else
		bad "creating the category page failed (HTTP $CODE): $(head -c 200 "$OUT")"
	fi
	CODE=$(aread a /api/article/content "?path=$(q "/tmp/$USER_A/$CAT_NAME")")
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
' "$TITLED_NAME")")
	CODE=$(aread a /api/article/content "?path=$(q "/tmp/$USER_A/$TITLED_NAME")")
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
' "$UNUSABLE_NAME")")
	CODE=$(aread a /api/article/content "?path=$(q "/tmp/$USER_A/$UNUSABLE_NAME")")
	UNUSABLE_URL=$(py 'import json,sys;print((json.load(sys.stdin).get("meta") or {}).get("url",""))')
	if [[ "$UNUSABLE_URL" == "/$PAGE_DIR/$USER_A/notes/unusable-${STAMP}.html" ]]; then
		ok "a category without a site page leaves the page at its fallback address ($UNUSABLE_URL)"
	else
		bad "an unusable category produced '$UNUSABLE_URL' (expected the fallback address)"
	fi
	CODE=$(asreq a DELETE /api/site/file "?path=$(q "/tmp/$USER_A/$UNUSABLE_NAME")")
	# validation: an invalid name, a name with a separator and too many
	CODE=$(aread a /api/article/content "?path=$(q "/tmp/$USER_A/$CAT_NAME")")
	ETAG=$(py 'import json,sys;print(json.load(sys.stdin).get("etag",""))')
	BAD_CAT=$(asreq a PUT /api/article/content '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "body": "x", "baseEtag": sys.argv[2], "meta": {"categories": ["Not Allowed"]}}))
' "/tmp/$USER_A/$CAT_NAME" "$ETAG")")
	SLASH_CAT=$(asreq a PUT /api/article/content '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "body": "x", "baseEtag": sys.argv[2], "meta": {"categories": ["a/b"]}}))
' "/tmp/$USER_A/$CAT_NAME" "$ETAG")")
	MANY_CAT=$(asreq a PUT /api/article/content '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "body": "x", "baseEtag": sys.argv[2], "meta": {"categories": sys.argv[3].split(",")}}))
' "/tmp/$USER_A/$CAT_NAME" "$ETAG" "c1,c2,c3,c4,c5,c6")")
	if [[ "$BAD_CAT" == "400" && "$SLASH_CAT" == "400" && "$MANY_CAT" == "400" ]]; then
		ok "an uppercase name, a name with a separator and six categories are all refused (HTTP 400)"
	else
		bad "category validation is wrong (uppercase $BAD_CAT, separator $SLASH_CAT, six $MANY_CAT; expected 400 each)"
	fi
	# dedupe and round trip: the same name twice is one category
	CODE=$(aread a /api/article/content "?path=$(q "/tmp/$USER_A/$CAT_NAME")")
	ETAG=$(py 'import json,sys;print(json.load(sys.stdin).get("etag",""))')
	CODE=$(asreq a PUT /api/article/content '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "body": "Body after the category edit.", "baseEtag": sys.argv[2], "meta": {"categories": ["guides", "guides"]}}))
' "/tmp/$USER_A/$CAT_NAME" "$ETAG")")
	DUP=$(py 'import json,sys;m=json.load(sys.stdin).get("meta") or {};print(",".join(m.get("categories") or []))')
	if [[ "$CODE" == "200" && "$DUP" == "guides" ]]; then
		ok "a duplicated category is written once and read back as one ($DUP)"
	else
		bad "the duplicate category answered HTTP $CODE, read back '$DUP'"
	fi
	# approve + build: the category copy is what the build produces
	for target in "/tmp/$USER_A/$CAT_NAME" "/tmp/$USER_A/$TITLED_NAME"; do
		CODE=$(asreq a POST /api/article/request '' "$(post_json '
import json, sys
print(json.dumps({"path": sys.argv[1], "intent": "online"}))
' "$target")")
	done
	for target in "/tmp/$USER_A/$CAT_NAME" "/tmp/$USER_A/$TITLED_NAME"; do
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
	if [[ "$CAT_LIVE" == "external|False" ]]; then
		ok "the tree reports the approved page with its state, not yet live ($CAT_LIVE)"
	else
		bad "the tree reports the approved page as '$CAT_LIVE', expected external|False"
	fi
	say "7b. deleting a page is direct, and the published list carries the removal"
	# This instance cannot commit to its remote (its work copy is a shallow clone and the GitHub remote
	# is not reachable from here), so the *published list* - the app's own record of what the site
	# serves - is written directly. That is the one fact the take-down rule reads, and it is the same
	# entry a publish would write.
	MANIFEST="$(ls -d /var/snap/nextcloud/common/nextcloud/data/appdata_*/pages 2>/dev/null | head -1)/review.json"
	python3 - "$MANIFEST" "/tmp/$USER_A/$CAT_NAME" "$USER_A" <<'PY'
import json, sys, time
path, target, owner = sys.argv[1], sys.argv[2], sys.argv[3]
data = json.load(open(path))
published = data.get("published")
if not isinstance(published, dict):
    published = {}
published[target.lstrip("/")] = {"owner": owner, "revision": "probe", "publishedAt": int(time.time())}
data["published"] = published
json.dump(data, open(path, "w"), indent=2, ensure_ascii=False)
PY
	CODE=$(aread a /api/site/tree)
	CAT_LIVE=$(py "
import json, sys
d = json.load(sys.stdin)
nodes = {n['path']: n for n in (d.get('nodes') or [])}
node = nodes.get('$CAT_NAME') or {}
print('%s|%s' % (node.get('state'), node.get('live')))
")
	if [[ "$CAT_LIVE" == "external|True" ]]; then
		ok "the tree now reports the page as live, which is what the published list decides ($CAT_LIVE)"
	else
		bad "the tree reports the page as '$CAT_LIVE' after it went live, expected external|True"
	fi
	CODE=$(asreq a DELETE /api/site/file "?path=$(q "/tmp/$USER_A/$CAT_NAME")")
	GONE=$(dav "$USER_A" "$PW_A" PROPFIND "/${AREA}/$CAT_NAME")
	if [[ "$CODE" == "200" && "$GONE" == "404" ]]; then
		ok "the page is deleted directly - the trash keeps the copy ($GONE)"
	else
		bad "the delete answered HTTP $CODE, file state $GONE"
	fi
	MANIFEST_STATE=$(python3 - "$MANIFEST" "/tmp/$USER_A/$CAT_NAME" <<'PY'
import json, sys
data = json.load(open(sys.argv[1]))
entry = (data.get("entries") or {}).get(sys.argv[2].lstrip("/")) or {}
print(entry.get("state", ""))
PY
)
	PUBLISHED_LEFT=$(python3 - "$MANIFEST" "/tmp/$USER_A/$CAT_NAME" <<'PY'
import json, sys
data = json.load(open(sys.argv[1]))
print("yes" if sys.argv[2].lstrip("/") in (data.get("published") or {}) else "no")
PY
)
	if [[ -z "$MANIFEST_STATE" && "$PUBLISHED_LEFT" == "yes" ]]; then
		ok "the manifest entry went with the file, the published list still names the path (the next publish removes it)"
	else
		bad "manifest entry '$MANIFEST_STATE', published list names it '$PUBLISHED_LEFT'"
	fi
fi

say "8. the author content directory is validated, and changing it moves the mirror"
# the current value is echoed on GET (the form needs it, and a rejected save must not lose it)
get /api/admin/config > /dev/null
if [[ "$(py 'import json,sys;print((json.load(sys.stdin).get("config") or {}).get("author_root",""))')" == "tmp" ]]; then
	ok "GET /api/admin/config echoes the current author content directory (tmp)"
else
	bad "the current author content directory is not echoed"
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
CODE=$(put /api/admin/config '{"authorRoot": "people"}')
if [[ "$CODE" == "200" ]]; then
	ok "author content directory 'people' is accepted (HTTP 200)"
else
	bad "setting the author content directory to 'people' failed (HTTP $CODE): $(head -c 200 "$OUT")"
fi
CODE=$(post /api/bridge/push '{}')
CODE=$(post /api/site/build '{}')
if [[ -f "$PREVIEW/people/$USER_A/$NAME_A" && ! -e "$PREVIEW/tmp/$USER_A/$NAME_A" \
	&& -f "$WORK/people/$USER_A/$NAME_A" && ! -e "$WORK/tmp/$USER_A/$NAME_A" ]]; then
	ok "the whole author tree moved to the new directory in both trees (people/, tmp/ gone)"
else
	bad "the mirror did not move with the author content directory (preview people: $([[ -f "$PREVIEW/people/$USER_A/$NAME_A" ]] && echo yes || echo no), commit tree people: $([[ -f "$WORK/people/$USER_A/$NAME_A" ]] && echo yes || echo no), tmp: $([[ -e "$WORK/tmp/$USER_A/$NAME_A" ]] && echo yes || echo no))"
fi
# The site's own template reads its author folders from `authors.source` (tmp), so a mirror under
# `people/` is invisible to it: nothing is built from there. That is exactly what the doctor's
# `site author source` check reports, and it is why the address of a page is a site setting, not an
# app setting.
rm -f "$BUILT/$PAGE_DIR/$USER_A/${NAME_A%.md}.html"
CODE=$(post /api/site/build '{}')
if [[ ! -e "$BUILT/$PAGE_DIR/$USER_A/${NAME_A%.md}.html" ]]; then
	ok "a mirror the site does not read produces no page (site author source tmp, author root people)"
else
	bad "a page was built from an author root the site does not read"
fi
# back to the documented default, so this suite leaves the instance as it found it
CODE=$(put /api/admin/config '{"authorRoot": "tmp"}')
CODE=$(post /api/bridge/push '{}')
CODE=$(post /api/site/build '{}')
if [[ -f "$PREVIEW/tmp/$USER_A/$NAME_A" && ! -e "$PREVIEW/people/$USER_A/$NAME_A" \
	&& -f "$WORK/tmp/$USER_A/$NAME_A" && ! -e "$WORK/people/$USER_A/$NAME_A" ]]; then
	ok "the default directory is restored, and both mirrors moved back with it"
else
	bad "restoring the author content directory did not move the mirror back (preview tmp: $([[ -f "$PREVIEW/tmp/$USER_A/$NAME_A" ]] && echo yes || echo no), commit tree tmp: $([[ -f "$WORK/tmp/$USER_A/$NAME_A" ]] && echo yes || echo no))"
fi
if [[ -f "$BUILT/$PAGE_DIR/$USER_A/${NAME_A%.md}.html" ]]; then
	ok "and the page is built again at its own address (/$PAGE_DIR/<user>/…), independent of the author root"
else
	bad "the page did not come back to /$PAGE_DIR/<user>/ after restoring the default"
fi
get /api/admin/config > /dev/null
CURRENT=$(py 'import json,sys;print((json.load(sys.stdin).get("config") or {}).get("author_root",""))')
if [[ "$CURRENT" == "tmp" ]]; then
	ok "the stored author content directory is the documented default again"
else
	bad "the author content directory is '$CURRENT' after the restore"
fi
RESTORED=1

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
