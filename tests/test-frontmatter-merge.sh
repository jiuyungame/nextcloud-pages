#!/usr/bin/env bash
#
# The structured article editor: `GET/PUT /api/article/content`.
#
#   sudo ./tests/test-frontmatter-merge.sh
#
# The endpoint exists so an author edits the *body* and a handful of fields while the server keeps the
# rest of the front matter — keys this app does not own (`layout`, `permalink`, `categories`, and
# whatever an author's own tooling put there) must survive a save untouched, in place and in order.
# Everything here is asserted on real files, read back through the API.
#
# Three sessions: an author, an outsider (both throwaway) and the admin as the editor, who reaches an
# author's area *and* the site tree. The probe files, the users and the bridge configuration are
# cleaned up by the trap; the index rows the probes wrote are invisible afterwards because the scan
# lists the files that are there, never a row whose file is gone, and a deleted account is no longer an
# author source.
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
USER_A="pages-fm-author"
USER_C="pages-fm-outsider"
PW_A="Pages-Fm-A-$(date +%s)-pw"
PW_C="Pages-Fm-C-$(date +%s)-pw"
STAMP="$(date +%Y%m%d%H%M%S)"
FILE_1="2026-02-01-probe-fm-${STAMP}.md"
FILE_2="2026-02-02-probe-fm-nodate-${STAMP}.md"
NOTES_1="2026-02-04-probe-fm-notes-${STAMP}.txt"
SITE_FILE="2026-02-03-probe-fm-site-${STAMP}.md"
TARGET_1="tmp/${USER_A}/${FILE_1}"
TARGET_2="tmp/${USER_A}/${FILE_2}"
TARGET_NOTES="tmp/${USER_A}/${NOTES_1}"
SITE_TARGET="_posts/${SITE_FILE}"

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

OUT=/tmp/tfm.json
ADMIN_JAR=/tmp/tfm-admin.jar
A_JAR=/tmp/tfm-a.jar
C_JAR=/tmp/tfm-c.jar
LOGIN=/tmp/tfm-login.html
RAW=/tmp/tfm-raw.md
# files of an author area (removed as the author) and of the site tree (removed as the editor)
PROBES_A=""
PROBES_SITE=""
NC_USER=""
PW=""
TOKEN=""

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

cleanup() {
	# this script writes into a real site repository as well, so both lists are cleared even when the
	# run failed half way through
	for target in $PROBES_A; do
		[[ -n "$TOKEN" ]] && asreq a DELETE /api/site/file "?path=/$target" > /dev/null 2>&1 || true
	done
	for target in $PROBES_SITE; do
		[[ -n "$TOKEN" ]] && req DELETE /api/site/file "?path=/$target" > /dev/null 2>&1 || true
	done
	# the posts live in the authors' own files, so deleting the users deletes them too
	for u in "$USER_A" "$USER_C"; do occ user:delete "$u" >/dev/null 2>&1 || true; done
	# every save pushed the author sources, so the bridge is told once more without the probe author
	[[ -n "$TOKEN" ]] && req POST /api/bridge/push '' '{}' > /dev/null 2>&1 || true
	rm -f "$OUT" "$ADMIN_JAR" "$A_JAR" "$C_JAR" "$LOGIN" "$RAW" "${RAW}.before"
}
trap cleanup EXIT

# ---------------------------------------------------------------- request layer
req() { # req <METHOD> <app-path> [query] [json]  → HTTP code, body in $OUT (admin session)
	local method="$1" path="$2" query="${3:-}" body="${4:-}"
	local args=(-X "$method" -b "$ADMIN_JAR" -c "$ADMIN_JAR" -u "${NC_USER}:${PW}"
		-H 'OCS-APIREQUEST: true' -H "requesttoken: $TOKEN" -o "$OUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(-H 'Content-Type: application/json' --data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path$query"
}
get()  { req GET  "$1" "${2:-}"; }
post() { req POST "$1" '' "${2:-}"; }
put()  { req PUT  "$1" '' "${2:-}"; }

asreq() { # asreq <a|c> <METHOD> <app-path> [query] [json] → HTTP code, body in $OUT
	local who="$1" method="$2" path="$3" query="${4:-}" body="${5:-}" jar pw uid
	case "$who" in
		a) jar="$A_JAR"; pw="$PW_A"; uid="$USER_A" ;;
		c) jar="$C_JAR"; pw="$PW_C"; uid="$USER_C" ;;
		*) bad "unknown actor $who"; return 1 ;;
	esac
	local args=(-X "$method" -b "$jar" -c "$jar" -u "${uid}:${pw}" -H 'OCS-APIREQUEST: true'
		-o "$OUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(-H 'Content-Type: application/json' --data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path$query"
}
aread() { asreq "$1" GET "$2" "${3:-}"; }
awrite() { asreq "$1" PUT "$2" '' "${4:-}"; }   # awrite <a|c> <path> '' <json>
acreate() { asreq "$1" POST "$2" '' "${3:-}"; }

py() { python3 -c "$1" < "$OUT" 2>/dev/null; }
pyarg() { python3 -c "$1" "$2" < "$OUT" 2>/dev/null; }

dav() { # dav <user> <pass> <METHOD> <dav-path> [data] → HTTP code
	local user="$1" pass="$2" method="$3" path="$4" data="${5:-}"
	local args=(-X "$method" -u "$user:$pass" -o /dev/null -w '%{http_code}')
	[[ -n "$data" ]] && args+=(--data-binary "$data")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/remote.php/dav/files/$user$path"
}

mkuser() { # mkuser <uid> <password>
	occ user:delete "$1" >/dev/null 2>&1 || true
	OC_PASS="$2" occ user:add --password-from-env --display-name "Pages fm $1" "$1" >/dev/null 2>&1 \
		|| { bad "cannot create $1"; exit 1; }
	# a real write, so the account's files folder exists before the app touches it
	dav "$1" "$2" PUT "/pages-fm-probe.txt" 'x' > /dev/null
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

# ---------------------------------------------------------------- article and file helpers
article_get() { # article_get <a|c|admin> <target> → HTTP code, answer in $OUT
	if [[ "$1" == "admin" ]]; then get /api/article/content "?path=/$2"; else aread "$1" /api/article/content "?path=/$2"; fi
}
article_put() { # article_put <a|admin> <json> → HTTP code, answer in $OUT
	if [[ "$1" == "admin" ]]; then put /api/article/content "$2"; else awrite "$1" /api/article/content '' "$2"; fi
}
article_json() { # article_json <target> <body> [meta-json] [baseEtag] → the structured PUT body
	python3 -c '
import json, sys
payload = {"path": "/" + sys.argv[1], "body": sys.argv[2]}
if len(sys.argv) > 3 and sys.argv[3] != "":
    payload["meta"] = json.loads(sys.argv[3])
if len(sys.argv) > 4 and sys.argv[4] != "":
    payload["baseEtag"] = sys.argv[4]
print(json.dumps(payload))
' "$1" "$2" "${3:-}" "${4:-}"
}
file_json() { # file_json <target> <content> → the whole-file body of /api/site/file
	python3 -c 'import json,sys;print(json.dumps({"path": sys.argv[1], "content": sys.argv[2]}))' "$1" "$2"
}
file_json_from() { # file_json_from <target> <file> → the whole-file body, content byte for byte
	python3 -c '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": open(sys.argv[2], encoding="utf-8").read()}))
' "$1" "$2"
}

answer() { pyarg 'import json,sys;print(json.load(sys.stdin).get(sys.argv[1], ""))' "$1"; }
answer_body_is() { # answer_body_is <expected> → ok when the answer's body is exactly that text
	# compared in python: a body ends with a newline, and $(…) would eat it before bash could compare
	python3 -c 'import json,sys;print("ok" if json.load(sys.stdin).get("body") == sys.argv[1] else "diff")' "$1" < "$OUT" 2>/dev/null
}
meta_field() { # meta_field <name> → the field as plain text (a list comma-joined)
	pyarg '
import json, sys
meta = json.load(sys.stdin).get("meta") or {}
value = meta.get(sys.argv[1])
print(",".join(str(v) for v in value) if isinstance(value, list) else ("" if value is None else str(value)))
' "$1"
}
stamped() { # stamped <code> → the server stamped modified_date on this answer
	local value
	value=$(meta_field modified_date)
	if [[ "$1" == "200" && "$value" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}:[0-9]{2}\ [+-][0-9]{4}$ ]]; then
		ok "the save carries the server's own stamp ($value)"
	else
		bad "modified_date missing or malformed after a save (HTTP $1, '$value')"
	fi
}

rawfile() { # rawfile <a|admin> <target> → HTTP code; the whole file lands in $RAW, byte for byte
	local code
	if [[ "$1" == "admin" ]]; then code=$(get /api/site/file "?path=/$2"); else code=$(aread "$1" /api/site/file "?path=/$2"); fi
	py 'import json,sys;print(json.load(sys.stdin).get("content",""), end="")' > "$RAW"
	printf '%s' "$code"
}
fm_keys() { # fm_keys → the front-matter keys of $RAW, in file order
	python3 -c '
import sys
lines = open(sys.argv[1], encoding="utf-8").read().split("\n")
keys = []
if lines and lines[0].strip() == "---":
    for line in lines[1:]:
        if line.rstrip() in ("---", "..."):
            break
        if line[:1].isspace() or not line.strip() or line.lstrip().startswith("#"):
            continue
        keys.append(line.split(":", 1)[0].strip())
print(",".join(keys))
' "$RAW"
}
fm_line() { # fm_line <key> → the exact line that assigns the key in $RAW, or ''
	python3 -c '
import sys
lines = open(sys.argv[1], encoding="utf-8").read().split("\n")
found = ""
if lines and lines[0].strip() == "---":
    for line in lines[1:]:
        if line.rstrip() in ("---", "..."):
            break
        if not line[:1].isspace() and line.split(":", 1)[0].strip() == sys.argv[2]:
            found = line
            break
print(found)
' "$RAW" "$1"
}
fm_body() { # fm_body → everything after the closing fence of $RAW, exactly as stored
	python3 -c '
import sys
text = open(sys.argv[1], encoding="utf-8").read()
lines = text.split("\n")
if lines and lines[0].strip() == "---":
    for i in range(1, len(lines)):
        if lines[i].rstrip() in ("---", "..."):
            print("\n".join(lines[i + 1:]), end="")
            raise SystemExit
print(text, end="")
' "$RAW"
}
body_is() { # body_is <expected> → ok when the stored body in $RAW is exactly that text
	# compared in python for the same reason as answer_body_is: bash would strip the trailing newline
	python3 -c '
import sys
text = open(sys.argv[1], encoding="utf-8").read()
lines = text.split("\n")
body = text
if lines and lines[0].strip() == "---":
    for i in range(1, len(lines)):
        if lines[i].rstrip() in ("---", "..."):
            body = "\n".join(lines[i + 1:])
            break
print("ok" if body == sys.argv[2] else "diff")
' "$RAW" "$1"
}
unchanged() { # unchanged <what> → the file in $RAW is byte-identical to ${RAW}.before
	if cmp -s "$RAW" "${RAW}.before"; then
		ok "$1 left the file untouched"
	else
		bad "$1 changed the file"
	fi
}

say "0. sessions"
NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
[[ -n "$NC_USER" ]] || { bad "no Nextcloud user found"; exit 1; }
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { bad "cannot issue an app password"; exit 1; }
PW=$(cat "$PW_FILE")
TOKEN=$(login "$ADMIN_JAR" "$NC_USER" "$PW") || { bad "admin login failed"; exit 1; }
mkuser "$USER_A" "$PW_A"
mkuser "$USER_C" "$PW_C"
[[ -n "$(login "$A_JAR" "$USER_A" "$PW_A")" ]] || { bad "$USER_A cannot log in"; exit 1; }
[[ -n "$(login "$C_JAR" "$USER_C" "$PW_C")" ]] || { bad "$USER_C cannot log in"; exit 1; }
ok "admin ($NC_USER) as editor, $USER_A as the author, $USER_C as the outsider"

say "1. the author's article, read as a body plus fields"
BODY_1=$'First paragraph of the probe.\n\nSecond paragraph.\n'
CONTENT_1=$'---\nlayout: post\nauthor: Probe Author\ntitle: Probe one\ntags: alpha beta\ndate: 2026-05-01\nexcerpt: The first excerpt.\nmy_custom_key: keep me\npermalink: /probe-one/\ncategories: news\n---\n\n'"$BODY_1"
PROBES_A="$TARGET_1"
CODE=$(acreate a /api/site/file "$(file_json "$TARGET_1" "$CONTENT_1")")
if [[ "$CODE" == "200" || "$CODE" == "201" ]]; then
	ok "the probe article is in the author's own area ($TARGET_1)"
else
	bad "cannot create the probe article (HTTP $CODE): $(head -c 200 "$OUT")"
fi

CODE=$(article_get a "$TARGET_1")
SHAPE=$(py '
import json, sys
d = json.load(sys.stdin)
need = ["ok", "path", "etag", "body", "meta"]
print(",".join(k for k in need if k not in d) or "ok")
')
METASHAPE=$(py '
import json, sys
meta = json.load(sys.stdin).get("meta") or {}
need = ["title", "tags", "date", "excerpt", "modified_date", "author", "permalink", "keys"]
print(",".join(k for k in need if k not in meta) or "ok")
')
if [[ "$CODE" == "200" && "$SHAPE" == "ok" && "$METASHAPE" == "ok" ]]; then
	ok "GET /api/article/content answers ok/path/etag/body/meta, meta with all eight fields"
else
	bad "answer shape wrong (HTTP $CODE, missing $SHAPE / $METASHAPE): $(head -c 200 "$OUT")"
fi
PATH_1=$(answer path)
BODY_OK=$(answer_body_is "$BODY_1")
OWNER_1=$(answer owner)
FIELDS=$(py '
import json, sys
meta = json.load(sys.stdin).get("meta") or {}
print("%s|%s|%s|%s|%s|%s" % (
    meta.get("title"), ",".join(meta.get("tags") or []), meta.get("date"),
    meta.get("excerpt"), meta.get("author"), meta.get("permalink")))
')
if [[ "$PATH_1" == "/$TARGET_1" && "$OWNER_1" == "$USER_A" && "$BODY_OK" == "ok" && "$FIELDS" == "Probe one|alpha,beta|2026-05-01|The first excerpt.|Probe Author|/probe-one/" ]]; then
	ok "the body is the body (no fence, no leading blank line) and the fields are the file's own"
else
	bad "read back wrong: path='$PATH_1' owner='$OWNER_1' body_ok='$BODY_OK' fields='$FIELDS' body='$(answer body)'"
fi
if [[ "$(meta_field keys)" == "layout,author,title,tags,date,excerpt,my_custom_key,permalink,categories" ]]; then
	ok "meta.keys lists the block in file order, the keys this app does not own included"
else
	bad "meta.keys wrong: '$(meta_field keys)'"
fi

say "2. only the named fields change, everything else survives in place"
CODE=$(rawfile a "$TARGET_1")
KEYS_BEFORE=$(fm_keys)
LINES_BEFORE=$(for key in tags date excerpt layout my_custom_key permalink categories; do fm_line "$key"; done)
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"title": "New title"}')")
stamped "$CODE"
CODE=$(rawfile a "$TARGET_1")
KEYS_AFTER=$(fm_keys)
LINES_AFTER=$(for key in tags date excerpt layout my_custom_key permalink categories; do fm_line "$key"; done)
if [[ "$KEYS_AFTER" == "${KEYS_BEFORE},modified_date" ]]; then
	ok "every key kept its place ($KEYS_AFTER) and the server's own key was appended"
else
	bad "the key order changed: before '$KEYS_BEFORE', after '$KEYS_AFTER'"
fi
if [[ "$LINES_AFTER" == "$LINES_BEFORE" ]]; then
	ok "tags/date/excerpt/layout/my_custom_key/permalink/categories are byte-identical after a title-only save"
else
	bad "a field nobody named was rewritten: $(diff <(printf '%s\n' "$LINES_BEFORE") <(printf '%s\n' "$LINES_AFTER") | head -6 | tr '\n' ' ')"
fi
if [[ "$(fm_line my_custom_key)" == "my_custom_key: keep me" && "$(fm_line layout)" == "layout: post" ]]; then
	ok "the made-up key and the layout this app does not own are still there"
else
	bad "the made-up key or the layout was lost (my_custom_key='$(fm_line my_custom_key)')"
fi
if [[ "$(fm_line title)" == "title: New title" ]]; then
	ok "the one field that was named is the one that changed (title: New title)"
else
	bad "the title was not written: '$(fm_line title)'"
fi

say "3. tags round-trip, and [] removes the key"
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"tags": ["one", "two"]}')")
stamped "$CODE"
SAVED=$(meta_field tags)
CODE=$(rawfile a "$TARGET_1")
if [[ "$SAVED" == "one,two" && "$(fm_line tags)" == "tags: one two" ]]; then
	ok "two tags are written as a space-separated inline list and read back as a list"
else
	bad "tags round-trip wrong (meta '$SAVED', line '$(fm_line tags)')"
fi
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"tags": []}')")
stamped "$CODE"
EMPTY=$(meta_field tags)
CODE=$(rawfile a "$TARGET_1")
if [[ "$EMPTY" == "" && "$(fm_line tags)" == "" && "$(fm_line my_custom_key)" == "my_custom_key: keep me" ]]; then
	ok "[] removes the tags key and leaves the made-up key alone"
else
	bad "an empty tag list did not remove the key (meta '$EMPTY', line '$(fm_line tags)')"
fi

say "3b. categories: validated as a field, written as one inline list, removed when empty"
# A category becomes a folder at the site root (one copy per usable category), so the field is a list
# of single-level names, not free text: the server validates it and the panel only sends a delta.
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"categories": ["guides", "notes"]}')")
stamped "$CODE"
SAVED=$(meta_field categories)
CODE=$(rawfile a "$TARGET_1")
if [[ "$SAVED" == "guides,notes" && "$(fm_line categories)" == "categories: [guides, notes]" ]]; then
	ok "two categories are written as one inline list and read back as a list"
else
	bad "categories round-trip wrong (meta '$SAVED', line '$(fm_line categories)')"
fi
# the one line a human types is accepted as well: the panel sends a list, a form may send text
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"categories": "guides, notes"}')")
if [[ "$CODE" == "200" && "$(meta_field categories)" == "guides,notes" ]]; then
	ok "a comma-separated line means the same as the list"
else
	bad "a string of categories was not accepted (HTTP $CODE, '$(meta_field categories)')"
fi
# the same name twice is one category: the site would make the same copy twice
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"categories": ["guides", "guides"]}')")
if [[ "$CODE" == "200" && "$(meta_field categories)" == "guides" ]]; then
	ok "a duplicated category collapses into one"
else
	bad "a duplicate category survived (HTTP $CODE, '$(meta_field categories)')"
fi
# a non-ASCII category is a valid folder name, like a non-ASCII slug
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"categories": ["中文"]}')")
if [[ "$CODE" == "200" && "$(meta_field categories)" == "中文" ]]; then
	ok "a Chinese category is accepted (a non-ASCII folder name is a real address)"
else
	bad "a Chinese category was refused (HTTP $CODE, '$(meta_field categories)')"
fi
# nothing slips in that could not be a single folder name
for value in '{"categories": ["Not Allowed"]}' '{"categories": ["a/b"]}' '{"categories": ["a\\b"]}' \
	'{"categories": [1]}' '{"categories": {"a": "b"}}' \
	'{"categories": ["c1","c2","c3","c4","c5","c6"]}'; do
	CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" "$value")")
	if [[ "$CODE" == "400" ]]; then
		ok "categories $value is refused (HTTP 400)"
	else
		bad "categories $value answered HTTP $CODE (expected 400)"
	fi
done
# a refused value must leave the file exactly as it is (the file is read back for the comparison)
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"categories": ["中文"]}')")
rawfile a "$TARGET_1" > /dev/null
cp "$RAW" "${RAW}.before"
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"categories": ["Not Allowed"]}')")
rawfile a "$TARGET_1" > /dev/null
unchanged "the refused category"
# a title-only save must leave the key and its line alone (the value is still the Chinese one)
LINES_CAT_BEFORE=$(fm_line categories)
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"title": "Probe one again"}')")
CODE=$(rawfile a "$TARGET_1")
if [[ "$(fm_line categories)" == "$LINES_CAT_BEFORE" && "$(fm_line categories)" == "categories: [中文]" ]]; then
	ok "a title-only save keeps the categories line byte-identical"
else
	bad "a title-only save rewrote the categories line ('$(fm_line categories)')"
fi
# the empty list removes the key - proven on a second file, because re-adding a removed key appends it
# at the end of the block and this suite elsewhere asserts the order of the first probe's keys
TARGET_CAT="tmp/${USER_A}/categories-${STAMP}.md"
CODE=$(acreate a /api/site/file "$(file_json "$TARGET_CAT" "$(printf -- '---\ntitle: Category removal\ncategories: [guides]\nkeep_me: yes\n---\n\nBody.\n')")")
if [[ "$CODE" == "200" || "$CODE" == "201" ]]; then
	ok "a second probe with a category was created for the removal check"
else
	bad "cannot create the removal probe (HTTP $CODE)"
fi
CODE=$(article_put a "$(article_json "$TARGET_CAT" $'Body.\n' '{"categories": []}')")
rawfile a "$TARGET_CAT" > /dev/null
if [[ "$CODE" == "200" && -z "$(fm_line categories)" && "$(fm_line keep_me)" == "keep_me: yes" ]]; then
	ok "[] removes the categories key and leaves the made-up key alone"
else
	bad "an empty category list did not remove the key (line '$(fm_line categories)')"
fi
CODE=$(asreq a DELETE /api/site/file "?path=/$TARGET_CAT")

say "4. excerpt is set, and an empty one removes it"
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"excerpt": "A fresh excerpt."}')")
stamped "$CODE"
SAVED=$(meta_field excerpt)
CODE=$(rawfile a "$TARGET_1")
if [[ "$SAVED" == "A fresh excerpt." && "$(fm_line excerpt)" == "excerpt: A fresh excerpt." ]]; then
	ok "the excerpt is stored as the client wrote it"
else
	bad "excerpt not written (meta '$SAVED', line '$(fm_line excerpt)')"
fi
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_1" '{"excerpt": ""}')")
stamped "$CODE"
EMPTY=$(meta_field excerpt)
CODE=$(rawfile a "$TARGET_1")
if [[ "$EMPTY" == "" && "$(fm_line excerpt)" == "" ]]; then
	ok "an empty excerpt removes the key, so Jekyll generates one from the body"
else
	bad "an empty excerpt left the key behind (meta '$EMPTY', line '$(fm_line excerpt)')"
fi

say "5. date is written only when the client sends it"
BODY_2=$'A second probe, without a date in its front matter.\n'
CONTENT_2=$'---\nlayout: post\ntitle: No date probe\nmy_custom_key: also kept\n---\n\n'"$BODY_2"
PROBES_A="$PROBES_A $TARGET_2"
CODE=$(acreate a /api/site/file "$(file_json "$TARGET_2" "$CONTENT_2")")
CODE=$(article_put a "$(article_json "$TARGET_2" "$BODY_2" '{"title": "No date probe (edited)"}')")
stamped "$CODE"
DERIVED=$(meta_field date)
CODE=$(rawfile a "$TARGET_2")
if [[ "$(fm_line date)" == "" && "$DERIVED" == "2026-02-02" ]]; then
	ok "a save that does not name the date writes no date key (the file name carries 2026-02-02)"
else
	bad "a date key appeared without being sent (line '$(fm_line date)', meta '$DERIVED')"
fi
CODE=$(article_put a "$(article_json "$TARGET_2" "$BODY_2" '{"date": "2026-05-02"}')")
stamped "$CODE"
SENT=$(meta_field date)
CODE=$(rawfile a "$TARGET_2")
if [[ "$SENT" == "2026-05-02" && "$(fm_line date)" == "date: 2026-05-02" ]]; then
	ok "a date the client names is written, and wins over the file name"
else
	bad "a sent date was not written (meta '$SENT', line '$(fm_line date)')"
fi

say "6. the date may not be in the future"
# The rule is about the instance's own day, so the reference day comes from the server and not from
# this shell: `modified_date` is stamped by the server (date('Y-m-d H:i:s O')), and the saves above
# just wrote it.
CODE=$(article_get a "$TARGET_2")
INSTANCE_TODAY=$(meta_field modified_date | cut -c1-10)
if [[ ! "$INSTANCE_TODAY" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
	INSTANCE_TODAY=$(date -u +%F)
fi
YESTERDAY=$(date -u -d "$INSTANCE_TODAY -1 day" +%F)
TOMORROW=$(date -u -d "$INSTANCE_TODAY +1 day" +%F)
date_meta() { printf '{"date": "%s"}' "$1"; }

CODE=$(article_put a "$(article_json "$TARGET_2" "$BODY_2" "$(date_meta "$YESTERDAY")")")
stamped "$CODE"
SENT=$(meta_field date)
CODE=$(rawfile a "$TARGET_2")
if [[ "$CODE" == "200" && "$SENT" == "$YESTERDAY" && "$(fm_line date)" == "date: $YESTERDAY" ]]; then
	ok "a past date ($YESTERDAY) is accepted and written"
else
	bad "a past date was refused or not written (HTTP $CODE, meta '$SENT', line '$(fm_line date)')"
fi

CODE=$(article_put a "$(article_json "$TARGET_2" "$BODY_2" "$(date_meta "$INSTANCE_TODAY")")")
stamped "$CODE"
SENT=$(meta_field date)
CODE=$(rawfile a "$TARGET_2")
if [[ "$CODE" == "200" && "$SENT" == "$INSTANCE_TODAY" && "$(fm_line date)" == "date: $INSTANCE_TODAY" ]]; then
	ok "today ($INSTANCE_TODAY, the instance's own day) is accepted and written"
else
	bad "today was refused or not written (HTTP $CODE, meta '$SENT', line '$(fm_line date)')"
fi

CODE=$(rawfile a "$TARGET_2")
cp "$RAW" "${RAW}.before"
CODE=$(article_put a "$(article_json "$TARGET_2" "$BODY_2" "$(date_meta "$TOMORROW")")")
ERR=$(py 'import json,sys;print(json.load(sys.stdin).get("error", ""))')
MSG=$(py 'import json,sys;print(json.load(sys.stdin).get("message", ""))')
if [[ "$CODE" == "400" && "$ERR" == "BAD_REQUEST" && -n "$MSG" ]]; then
	ok "tomorrow ($TOMORROW) is refused with 400 and a message ($MSG)"
else
	bad "a future date answered HTTP $CODE error='$ERR' message='$MSG' (expected 400 BAD_REQUEST with a message)"
fi
CODE=$(rawfile a "$TARGET_2")
unchanged "the refused future date"
if [[ "$(fm_line date)" == "date: $INSTANCE_TODAY" ]]; then
	ok "the file keeps the last accepted day ($INSTANCE_TODAY)"
else
	bad "the refused save touched the date line ('$(fm_line date)')"
fi

CODE=$(article_put a "$(article_json "$TARGET_2" "$BODY_2" '{"date": ""}')")
stamped "$CODE"
SENT=$(meta_field date)
CODE=$(rawfile a "$TARGET_2")
if [[ "$CODE" == "200" && "$(fm_line date)" == "" && "$SENT" == "2026-02-02" ]]; then
	ok "an empty date still removes the key (the file name carries 2026-02-02 again)"
else
	bad "an empty date did not remove the key (HTTP $CODE, line '$(fm_line date)', meta '$SENT')"
fi

say "7. modified_date is stamped on every save and moves between two of them"
CODE=$(article_get a "$TARGET_2")
FIRST_STAMP=$(meta_field modified_date)
sleep 1
CODE=$(article_put a "$(article_json "$TARGET_2" "$BODY_2" '{"title": "No date probe (again)"}')")
stamped "$CODE"
SECOND_STAMP=$(meta_field modified_date)
CODE=$(rawfile a "$TARGET_2")
if [[ -n "$FIRST_STAMP" && -n "$SECOND_STAMP" && "$FIRST_STAMP" != "$SECOND_STAMP" && "$(fm_line modified_date)" == "modified_date: $SECOND_STAMP" ]]; then
	ok "the stamp changed between two saves ($FIRST_STAMP → $SECOND_STAMP) and is the file's own line"
else
	bad "the stamp did not move or is not in the file ('$FIRST_STAMP' → '$SECOND_STAMP', line '$(fm_line modified_date)')"
fi

say "8. the body is replaced exactly, front matter look-alikes and all"
BODY_3=$'Second version of the body.\n\n---\n\nlayout: fake\ntitle: not a real title\n\nA line with trailing spaces:   \nEnd of the body.\n'
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_3" '{"title": "Body probe"}')")
stamped "$CODE"
ANSWER_OK=$(answer_body_is "$BODY_3")
CODE=$(rawfile a "$TARGET_1")
STORED_OK=$(body_is $'\n'"$BODY_3")
if [[ "$ANSWER_OK" == "ok" && "$STORED_OK" == "ok" ]]; then
	ok "the stored body is exactly what was sent (fence, one blank line, body, one trailing newline)"
else
	bad "the body was not stored as sent (answer '$ANSWER_OK', file '$STORED_OK'): got '$(fm_body)'"
fi
if [[ "$(fm_line layout)" == "layout: post" && "$(fm_line title)" == "title: Body probe" && "$(fm_keys)" == "layout,author,title,date,my_custom_key,permalink,categories,modified_date" ]]; then
	ok "a '---' line and a 'layout: fake' line inside the body did not become front matter"
else
	bad "the body leaked into the block (keys '$(fm_keys)', layout '$(fm_line layout)')"
fi
CODE=$(article_get a "$TARGET_1")
if [[ "$(answer_body_is "$BODY_3")" == "ok" ]]; then
	ok "and reading it back agrees with what was written"
else
	bad "the read-back body differs from the sent one"
fi

say "9. a stale baseEtag is a 409 and writes nothing"
CODE=$(article_get a "$TARGET_1")
ETAG_1=$(answer etag)
CODE=$(rawfile a "$TARGET_1")
cp "$RAW" "${RAW}.before"
CODE=$(article_put a "$(article_json "$TARGET_1" $'This body must never reach the file.\n' '{"title": "Stale"}' 'etag-that-is-not-current')")
if [[ "$CODE" == "409" ]]; then
	ok "a baseEtag that does not match answers 409"
else
	bad "a stale baseEtag answered HTTP $CODE (expected 409): $(head -c 200 "$OUT")"
fi
CODE=$(rawfile a "$TARGET_1")
unchanged "the refused save"
CODE=$(article_get a "$TARGET_1")
if [[ "$(answer_body_is "$BODY_3")" == "ok" && "$(meta_field title)" == "Body probe" ]]; then
	ok "and the article still reads as it did before the conflict"
else
	bad "the conflict changed what the article reads as (title '$(meta_field title)')"
fi
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_3" '{"excerpt": "Kept."}' "$ETAG_1")")
stamped "$CODE"
ETAG_2=$(answer etag)
if [[ "$CODE" == "200" && -n "$ETAG_2" && "$(meta_field excerpt)" == "Kept." ]]; then
	ok "the etag from the read is accepted, and the answer carries one to use next ($ETAG_2)"
else
	bad "a save under the current etag failed (HTTP $CODE, '$ETAG_1' → '$ETAG_2')"
fi
# The etag itself is Nextcloud's (filecache), so it can legitimately stay the same when two writes
# happen inside the same second on this storage. What a client needs is the one that is accepted next.
CODE=$(article_put a "$(article_json "$TARGET_1" "$BODY_3" '{"excerpt": "Kept twice."}' "$ETAG_2")")
if [[ "$CODE" == "200" && "$(meta_field excerpt)" == "Kept twice." ]]; then
	ok "and the etag the save returned is the current one (the next save with it is not a 409)"
else
	bad "the etag a save returned was rejected by the next save (HTTP $CODE): $(head -c 200 "$OUT")"
fi

say "10. ownership, and paths that are not articles"
CODE=$(article_get c "$TARGET_1")
if [[ "$CODE" == "403" ]]; then
	ok "the outsider cannot read another author's article (HTTP 403)"
else
	bad "an outsider's read answered HTTP $CODE (expected 403): $(head -c 200 "$OUT")"
fi
CODE=$(rawfile a "$TARGET_1")
cp "$RAW" "${RAW}.before"
CODE=$(article_put c "$(article_json "$TARGET_1" $'Hijacked.\n' '{"title": "Hijacked"}')")
if [[ "$CODE" == "403" ]]; then
	ok "and cannot save it either (HTTP 403)"
else
	bad "an outsider's save answered HTTP $CODE (expected 403): $(head -c 200 "$OUT")"
fi
CODE=$(rawfile a "$TARGET_1")
unchanged "the outsider's attempt"
CODE=$(article_get a "_config.yml")
if [[ "$CODE" == "403" || "$CODE" == "400" ]]; then
	ok "the author cannot reach the site skeleton through the article route (HTTP $CODE)"
else
	bad "a skeleton path answered HTTP $CODE for an author"
fi
CODE=$(article_get a "_posts/${USER_A}/2099-01-01-does-not-exist.md")
if [[ "$CODE" == "404" ]]; then
	ok "an article that is not there answers 404"
else
	bad "a missing article answered HTTP $CODE (expected 404)"
fi
# a file that is really in the author's area but is not a post: read whole through /api/site/file,
# never as fields. It has to exist for this answer — a missing file is a 404 first, which is the same
# order the file endpoints use.
CODE=$(acreate a /api/site/file "$(file_json "$TARGET_NOTES" $'not an article\n')")
PROBES_A="$PROBES_A $TARGET_NOTES"
CODE=$(article_get a "$TARGET_NOTES")
if [[ "$CODE" == "400" ]]; then
	ok "a file of the author's own area that is not an article answers 400 (HTTP $CODE)"
else
	bad "a non-article path answered HTTP $CODE (expected 400): $(head -c 200 "$OUT")"
fi
CODE=$(article_put a "$(article_json "$TARGET_NOTES" $'x\n')")
if [[ "$CODE" == "400" ]]; then
	ok "and it cannot be saved through the fields route either (HTTP 400)"
else
	bad "saving a non-article path answered HTTP $CODE (expected 400)"
fi
CODE=$(article_get a "assets/theme.css")
if [[ "$CODE" == "403" ]]; then
	ok "a site asset target is not an article for an author: the mapper refuses the skeleton (HTTP $CODE)"
else
	bad "a site asset target answered HTTP $CODE (expected 403)"
fi
CODE=$(article_get admin "index.html")
if [[ "$CODE" == "400" ]]; then
	ok "an editor gets 400 for a site file that is not an article (HTTP $CODE)"
else
	bad "an editor's non-article path answered HTTP $CODE (expected 400)"
fi
CODE=$(article_get admin "_posts/ghost-${STAMP}/x.md")
if [[ "$CODE" == "404" ]]; then
	ok "an article target naming an author area that does not exist answers 404"
else
	bad "an unknown author area answered HTTP $CODE (expected 404)"
fi

say "11. the editor: an author's article, and a post of the site tree"
CODE=$(article_get admin "$TARGET_1")
EDITOR_OK=$(answer_body_is "$BODY_3")
if [[ "$CODE" == "200" && "$EDITOR_OK" == "ok" && "$(answer owner)" == "$USER_A" ]]; then
	ok "the administrator reads the author's article as body plus fields (owner $USER_A)"
else
	bad "the editor's read of an author's article failed (HTTP $CODE, body_ok '$EDITOR_OK'): $(head -c 200 "$OUT")"
fi
CODE=$(article_put admin "$(article_json "$TARGET_1" "$BODY_3" '{"title": "Title from the editor"}')")
stamped "$CODE"
EDITOR_TITLE=$(meta_field title)
CODE=$(rawfile a "$TARGET_1")
if [[ "$EDITOR_TITLE" == "Title from the editor" && "$(fm_line layout)" == "layout: post" && "$(fm_line my_custom_key)" == "my_custom_key: keep me" ]]; then
	ok "and can save it through the same merge, with the untouched keys still in place"
else
	bad "the editor's save is wrong (title '$EDITOR_TITLE', layout '$(fm_line layout)')"
fi
CODE=$(article_get a "$TARGET_1")
if [[ "$CODE" == "200" && "$(meta_field title)" == "Title from the editor" ]]; then
	ok "the author sees the editor's change in their own article"
else
	bad "the author does not see the editor's change (HTTP $CODE, title '$(meta_field title)')"
fi

SITE_CONTENT=$'---\nlayout: post\npublished: false\nmy_custom_key: site kept\ntitle: Site probe\n---\n\nA post of the site tree itself.\n'
CODE=$(post /api/site/file "$(file_json "$SITE_TARGET" "$SITE_CONTENT")")
if [[ "$CODE" == "200" || "$CODE" == "201" ]]; then
	ok "a post of the site tree was created with the whole-file route ($SITE_TARGET)"
else
	bad "cannot create the site probe (HTTP $CODE): $(head -c 200 "$OUT")"
fi
PROBES_SITE="$SITE_TARGET"
CODE=$(article_get admin "$SITE_TARGET")
SITE_OK=$(answer_body_is $'A post of the site tree itself.\n')
SITE_KEYS=$(meta_field keys)
if [[ "$CODE" == "200" && "$SITE_OK" == "ok" && "$SITE_KEYS" == "layout,published,my_custom_key,title" ]]; then
	ok "an editor reads a skeleton post through the article route (its own keys, no owner)"
else
	bad "the editor's read of a site post failed (HTTP $CODE, keys '$SITE_KEYS', body_ok '$SITE_OK')"
fi
CODE=$(rawfile admin "$SITE_TARGET")
cp "$RAW" "${RAW}.before"
CODE=$(article_put admin "$(article_json "$SITE_TARGET" $'A post of the site tree itself.\n' '{"tags": ["sitetag"]}')")
stamped "$CODE"
CODE=$(rawfile admin "$SITE_TARGET")
if [[ "$(fm_line tags)" == "tags: sitetag" && "$(fm_line published)" == "published: false" && "$(fm_line my_custom_key)" == "my_custom_key: site kept" ]]; then
	ok "a structured save of a site post adds its tags and keeps every other key"
else
	bad "the site post's block is wrong (tags '$(fm_line tags)', published '$(fm_line published)')"
fi
# the whole-file route must keep working for an editor next to the structured one
CODE=$(put /api/site/file "$(file_json_from "$SITE_TARGET" "$RAW")")
if [[ "$CODE" == "200" ]]; then
	ok "PUT /api/site/file still writes the same file whole (HTTP 200)"
else
	bad "the whole-file route broke (HTTP $CODE): $(head -c 200 "$OUT")"
fi
CODE=$(req DELETE /api/site/file "?path=/$SITE_TARGET")
if [[ "$CODE" == "200" ]]; then
	PROBES_SITE=""
	ok "and the site probe is deleted again with the same route"
else
	bad "cannot delete the site probe (HTTP $CODE)"
fi

say "12. additional metadata: unknown keys round-trip, keep their place, and a bad key is refused"
FILE_META="2026-02-05-probe-meta-${STAMP}.md"
TARGET_META="tmp/${USER_A}/${FILE_META}"
BODY_M="Body of the metadata probe."
CODE=$(acreate a /api/site/file "$(file_json "/$TARGET_META" "---
layout: post
title: Meta probe
tags: meta
series: one
author: ${USER_A}
---

${BODY_M}
")")
PROBES_A="$PROBES_A $TARGET_META"
if [[ "$CODE" == "200" || "$CODE" == "201" ]]; then
	ok "a probe article with two unknown keys was created"
else
	bad "cannot create the metadata probe (HTTP $CODE): $(head -c 200 "$OUT")"
fi
CODE=$(article_put a "$(article_json "$TARGET_META" "$BODY_M" '{"custom_key": "hello", "weight": 3, "featured": true, "topics": ["a", "b"]}')")
if [[ "$CODE" == "200" ]]; then
	ok "a save may add keys the app does not own (HTTP 200)"
else
	bad "adding additional metadata failed (HTTP $CODE): $(head -c 200 "$OUT")"
fi
VALUES=$(meta_field custom_key)/$(meta_field weight)/$(meta_field featured)/$(meta_field topics)
if [[ "$VALUES" == "hello/3/True/a,b" ]]; then
	ok "the answer returns the new keys with their types ($VALUES)"
else
	bad "the answer does not carry the new keys as sent ('$VALUES')"
fi
rawfile a "$TARGET_META" > /dev/null
ORDER=$(fm_keys)
if [[ "$ORDER" == "layout,title,tags,series,author,custom_key,weight,featured,topics,modified_date" ]]; then
	ok "new keys are appended at the end and the existing ones keep their order ($ORDER)"
else
	bad "the front matter order is wrong: $ORDER"
fi
if [[ "$(fm_line series)" == "series: one" && "$(fm_line custom_key)" == "custom_key: hello" \
	&& "$(fm_line weight)" == "weight: 3" && "$(fm_line featured)" == "featured: true" \
	&& "$(fm_line topics)" == "topics: [a, b]" ]]; then
	ok "the values are written as YAML that reads back as the same types"
else
	bad "the values were not written as expected: $(fm_line custom_key) / $(fm_line weight) / $(fm_line topics)"
fi
# change one, delete one, and change a key that was already there: nothing may move
CODE=$(article_put a "$(article_json "$TARGET_META" "$BODY_M" '{"custom_key": "changed", "weight": "", "series": "two"}')")
rawfile a "$TARGET_META" > /dev/null
ORDER2=$(fm_keys)
if [[ "$CODE" == "200" && "$ORDER2" == "layout,title,tags,series,author,custom_key,featured,topics,modified_date" ]]; then
	ok "an empty value deletes the key, and a changed key keeps its place ($ORDER2)"
else
	bad "delete-in-place failed (HTTP $CODE, order '$ORDER2')"
fi
if [[ "$(fm_line custom_key)" == "custom_key: changed" && "$(fm_line series)" == "series: two" && -z "$(fm_line weight)" ]]; then
	ok "the changed values are in the file and the removed one is gone"
else
	bad "the edit/delete result is wrong: $(fm_line custom_key) / $(fm_line series) / $(fm_line weight)"
fi
# a save that only names a field the app owns must not touch the additional keys
CODE=$(article_put a "$(article_json "$TARGET_META" "$BODY_M" '{"title": "Meta probe edited"}')")
rawfile a "$TARGET_META" > /dev/null
ORDER3=$(fm_keys)
if [[ "$CODE" == "200" && "$(fm_line custom_key)" == "custom_key: changed" && "$(fm_line featured)" == "featured: true" \
	&& "$ORDER3" == "layout,title,tags,series,author,custom_key,featured,topics,modified_date" ]]; then
	ok "a title-only save keeps every additional key where it was"
else
	bad "a title-only save disturbed the additional keys (HTTP $CODE, order '$ORDER3')"
fi
# a key that is not a safe front-matter name, and a value that is not a scalar or a list: both a 400
cp "$RAW" "${RAW}.before"
CODE=$(article_put a "$(article_json "$TARGET_META" "$BODY_M" '{"Bad-Key": "x"}')")
if [[ "$CODE" == "400" ]]; then
	ok "an invalid metadata key is a 400"
else
	bad "an invalid metadata key answered HTTP $CODE (expected 400)"
fi
rawfile a "$TARGET_META" > /dev/null
unchanged "the refused invalid key"
CODE=$(article_put a "$(article_json "$TARGET_META" "$BODY_M" '{"deep": {"nested": "no"}}')")
if [[ "$CODE" == "400" ]]; then
	ok "a non-scalar metadata value is a 400"
else
	bad "a nested metadata value answered HTTP $CODE (expected 400)"
fi
LONGKEY=$(python3 -c 'print("k" * 65)')
CODE=$(article_put a "$(article_json "$TARGET_META" "$BODY_M" "{\"$LONGKEY\": \"x\"}")")
if [[ "$CODE" == "400" ]]; then
	ok "a key longer than 64 characters is a 400"
else
	bad "an over-long metadata key answered HTTP $CODE (expected 400)"
fi
rawfile a "$TARGET_META" > /dev/null
unchanged "the refused over-long key"
CODE=$(aread a /api/site/file "?path=/$TARGET_META")
# the read-only keys are never written, even when the client echoes the whole meta object back
BEFORE_RAW=$(sha256sum "$RAW" | cut -d' ' -f1)
CODE=$(article_put a "$(article_json "$TARGET_META" "$BODY_M" '{"permalink": "/nope", "keys": ["x"], "modified_date": "2000-01-01 00:00:00 +0000", "author": "nobody", "title": "Meta probe edited"}')")
rawfile a "$TARGET_META" > /dev/null
if [[ "$CODE" == "200" && -z "$(fm_line permalink)" ]] \
	&& printf '%s' "$(fm_line modified_date)" | grep -qE '^modified_date: 20[0-9]{2}-' \
	&& [[ "$(fm_line author)" != "author: nobody" ]]; then
	ok "the read-only keys (permalink, keys, modified_date, author) are not written by the panel"
else
	bad "a read-only key reached the file: $(fm_line permalink) / $(fm_line modified_date) / $(fm_line author)"
fi
CODE=$(asreq a DELETE /api/site/file "?path=/$TARGET_META")
if [[ "$CODE" == "200" ]]; then
	PROBES_A=""
	ok "the metadata probe is deleted again"
else
	bad "cannot delete the metadata probe (HTTP $CODE)"
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
