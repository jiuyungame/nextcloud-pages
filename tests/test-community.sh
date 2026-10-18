#!/usr/bin/env bash
#
# Community features acceptance (v4 plan: multi-author blog).
#
#   sudo ./tests/test-community.sh
#
# Phase 1 covers the reading side: the article index, its visibility rules, tags and the single
# article endpoint. Later phases extend this script with submitting, reviewing, voting and
# commenting, which is why it is separate from test-permissions.sh (capabilities) and
# test-authors.sh (authoring and publishing).
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLAIN_USER="pages-read-user"
PLAIN_PW="Pages-Read-$(date +%s)-pw"

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

OUT=/tmp/tc.json
JAR=/tmp/tc.jar
PLAIN_JAR=/tmp/tc-plain.jar
LOGIN=/tmp/tc-login.html
NC_USER=""
PW=""
TOKEN=""

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

cleanup() {
	rm -f "$JAR" "$PLAIN_JAR" "$LOGIN" "$OUT" /tmp/tc-plain.json /tmp/tc-plain.html
	occ user:delete "$PLAIN_USER" >/dev/null 2>&1 || true
}
trap cleanup EXIT

api() { # api <app-path> [query] → HTTP code, body in $OUT
	local path="$1" query="${2:-}"
	curl --noproxy '*' -sk -b "$JAR" -c "$JAR" -u "${NC_USER}:${PW}" -H 'OCS-APIREQUEST: true' \
		-H "requesttoken: $TOKEN" -o "$OUT" -w '%{http_code}' "$BASE/index.php/apps/$APP_ID$path$query"
}
py() { python3 -c "$1" < "$OUT" 2>/dev/null; }
pyarg() { python3 -c "$1" "$2" < "$OUT" 2>/dev/null; }

# A post of the site's own (no review involved): the reading list is "what the site has", and an
# instance whose site carries no posts of its own has an empty list for a reason that is not a bug.
# The fixture is created at the start and removed again at the end of this run.
STAMP=$(date +%s)
FIXTURE="/_posts/2026-01-01-community-probe-$STAMP.md"
create_post() { # create_post <path> <content> → HTTP code, body in $OUT
	local path="$1" content="$2"
	python3 - "$path" "$content" > /tmp/tc-new.json <<'PY'
import json, sys
print(json.dumps({"path": sys.argv[1], "content": sys.argv[2]}))
PY
	curl --noproxy '*' -sk -b "$JAR" -c "$JAR" -u "${NC_USER}:${PW}" -H 'OCS-APIREQUEST: true' \
		-H "requesttoken: $TOKEN" -H 'Content-Type: application/json' --data @/tmp/tc-new.json \
		-o "$OUT" -w '%{http_code}' "$BASE/index.php/apps/$APP_ID/api/site/file"
}

say "0. admin session"
NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
[[ -n "$NC_USER" ]] || { bad "no Nextcloud user found"; exit 1; }
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { bad "cannot issue an app password"; exit 1; }
PW=$(cat "$PW_FILE")
rm -f "$JAR"
code=$(curl --noproxy '*' -sk -c "$JAR" -u "${NC_USER}:${PW}" -H 'OCS-APIREQUEST: true' -o "$LOGIN" -w '%{http_code}' "$BASE/index.php/settings/user")
TOKEN=$(sed -n 's/.*data-requesttoken="\([^"]*\)".*/\1/p' "$LOGIN" | head -1)
if [[ "$code" == "200" && -n "$TOKEN" ]]; then
	ok "admin session ready ($NC_USER)"
else
	bad "admin login failed (HTTP $code)"; exit 1
fi

say "1. GET /api/articles — the reading list"
# a post of the site's own, so the list has a subject to describe
CODE=$(create_post "$FIXTURE" "$(printf -- '---\nlayout: post\ntitle: Community probe\ncategories: community\ntags: community probe\n---\n\nA post of the site itself.\n')")
if [[ "$CODE" == "200" || "$CODE" == "201" ]]; then
	ok "the fixture post is in the site's own tree ($FIXTURE)"
else
	bad "cannot create the fixture post (HTTP $CODE): $(head -c 200 "$OUT")"
fi
CODE=$(api /api/articles)
COUNT=$(py 'import json,sys;print(len(json.load(sys.stdin).get("articles") or []))')
if [[ "$CODE" == "200" && "$COUNT" -gt 0 ]]; then
	ok "returns $COUNT articles"
else
	bad "expected a non-empty list (HTTP $CODE, count=$COUNT)"
fi
SHAPE=$(py '
import json, sys
need = {"id", "path", "title", "owner", "tags", "state", "intent", "date", "url", "summary", "up", "down", "score", "mine", "comments"}
items = json.load(sys.stdin).get("articles") or []
missing = sorted({k for item in items for k in need if k not in item})
print("ok" if not missing else "missing " + ",".join(missing))
')
if [[ "$SHAPE" == "ok" ]]; then
	ok "every item carries the documented keys (state and the request intent included)"
else
	bad "article items are incomplete: $SHAPE"
fi
# Reading has no visibility filter: every entry of the site is listed with the state it is in, so a
# reader can tell an internal page from an external one. What must hold is that every row carries one
# of the three states and answers `live` with a boolean.
LEAK=$(py '
import json, sys
states = {"internal", "requested", "external"}
items = json.load(sys.stdin).get("articles") or []
bad = [a["path"] for a in items if a.get("state") not in states]
print(",".join(bad) if bad else "ok")
')
if [[ "$LEAK" == "ok" ]]; then
	ok "every listed article carries one of the three visibility states"
else
	bad "the reading list has rows without a valid state: $LEAK"
fi
LIVE=$(py '
import json, sys
items = json.load(sys.stdin).get("articles") or []
print("ok" if all(isinstance(a.get("live"), bool) for a in items) else "bad")
')
if [[ "$LIVE" == "ok" ]]; then
	ok "every listed article says whether the site is serving it (live)"
else
	bad "the reading list has rows without a live flag"
fi
PATHS=$(py 'import json,sys;print("\n".join(a["path"] for a in (json.load(sys.stdin).get("articles") or [])))')
if printf '%s' "$PATHS" | grep -q '^/_posts/'; then
	ok "articles are the site's posts (/_posts/…)"
else
	bad "no /_posts/ article in the list: $PATHS"
fi
# a post whose front matter says published: false is not external: it is listed (reading has no
# visibility filter), and its state says so. The theme's demo post may not exist on every site, so the
# fixture created in section 6 is the one that always proves it; here the state is checked when it is there.
STATE_SUPER=$(py '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if "super-short-article" in a["path"]), None)
print("missing" if hit is None else (hit.get("state") or ""))
')
if [[ "$STATE_SUPER" == "internal" ]]; then
	ok "the theme's 'published: false' demo post is listed as internal"
elif [[ "$STATE_SUPER" == "missing" ]]; then
	ok "the theme's 'published: false' demo post is not on this site (section 6 covers the rule)"
else
	bad "a 'published: false' post reads '$STATE_SUPER' (expected internal)"
fi

say "2. GET /api/tags"
CODE=$(api /api/tags)
TAGS=$(py 'import json,sys;print(len(json.load(sys.stdin).get("tags") or []))')
SORTED=$(py '
import json, sys
tags = json.load(sys.stdin).get("tags") or []
counts = [t.get("count") for t in tags]
print("ok" if counts == sorted(counts, reverse=True) and all(isinstance(c, int) and c > 0 for c in counts) else "bad order/counts")
')
if [[ "$CODE" == "200" && "$SORTED" == "ok" ]]; then
	ok "returns $TAGS tags, most used first, every count positive"
else
	bad "tag list wrong (HTTP $CODE, $TAGS tags, $SORTED)"
fi

say "3. GET /api/article?path="
api /api/articles > /dev/null
FIRST=$(py 'import json,sys,urllib.parse;print(urllib.parse.quote((json.load(sys.stdin).get("articles") or [{}])[0].get("path",""), safe=""))')
CODE=$(api /api/article "?path=$FIRST")
SAME=$(pyarg 'import json,sys,urllib.parse;print(json.load(sys.stdin).get("article",{}).get("path") == urllib.parse.unquote(sys.argv[1]))' "$FIRST")
if [[ "$CODE" == "200" && "$SAME" == "True" ]]; then
	ok "returns the requested article ($(printf '%s' "$FIRST" | sed 's|%2F|/|g'))"
else
	bad "single article lookup failed for '$FIRST' (HTTP $CODE, match=$SAME)"
fi
CODE=$(api /api/article '?path=%2F_posts%2F2099-01-01-does-not-exist.md')
if [[ "$CODE" == "404" ]]; then
	ok "an unknown path answers 404"
else
	bad "an unknown path answered HTTP $CODE instead of 404"
fi

say "4. tag filter and sorting agree with the list"
# The state filter is answered by the server, so a truncated list cannot pretend that nothing matched.
# An unknown state is refused: answering a different question than the one asked is worse than an error.
CODE=$(api /api/articles "?state=external")
ONLY=$(py 'import json,sys;items=json.load(sys.stdin).get("articles") or [];print("ok" if all(a.get("state")=="external" for a in items) else "bad")')
if [[ "$CODE" == "200" && "$ONLY" == "ok" ]]; then
	ok "state=external returns only external rows"
else
	bad "state filter wrong (HTTP $CODE, $ONLY)"
fi
CODE=$(api /api/articles "?state=requested")
ONLY=$(py 'import json,sys;items=json.load(sys.stdin).get("articles") or [];print("ok" if all(a.get("state")=="requested" for a in items) else "bad")')
if [[ "$CODE" == "200" && "$ONLY" == "ok" ]]; then
	ok "state=requested returns only requested rows (and may be empty)"
else
	bad "state=requested wrong (HTTP $CODE, $ONLY)"
fi
CODE=$(api /api/articles "?external=true")
ONLY=$(py 'import json,sys;items=json.load(sys.stdin).get("articles") or [];print("ok" if all(a.get("state")=="external" for a in items) else "bad")')
if [[ "$CODE" == "200" && "$ONLY" == "ok" ]]; then
	ok "external=true returns only the entries approved for the site"
else
	bad "the external filter wrong (HTTP $CODE, $ONLY)"
fi
CODE=$(api /api/articles "?state=bogus")
if [[ "$CODE" == "400" ]]; then
	ok "an unknown state is refused (HTTP 400) instead of being ignored"
else
	bad "an unknown state answered HTTP $CODE - it must be refused, not silently ignored"
fi

TAG=$(api /api/tags > /dev/null; py 'import json,sys;t=json.load(sys.stdin).get("tags") or [];print(t[0].get("tag","") if t else "")')
if [[ -n "$TAG" ]]; then
	CODE=$(api /api/articles "?tag=$TAG")
	ONLY=$(pyarg 'import json,sys;items=json.load(sys.stdin).get("articles") or [];print("ok" if items and all(any(t.lower()==sys.argv[1].lower() for t in a.get("tags") or []) for a in items) else "bad")' "$TAG")
	if [[ "$CODE" == "200" && "$ONLY" == "ok" ]]; then
		ok "filtering by '$TAG' returns only articles carrying it"
	else
		bad "tag filter wrong (HTTP $CODE, $ONLY)"
	fi
else
	ok "no tags on this site yet — tag filter not exercised"
fi
TOTAL=$(api /api/articles > /dev/null; py 'import json,sys;print(len(json.load(sys.stdin).get("articles") or []))')
for mode in score comments; do
	CODE=$(api /api/articles "?sort=$mode")
	SIZE=$(py 'import json,sys;print(len(json.load(sys.stdin).get("articles") or []))')
	if [[ "$CODE" == "200" && "$SIZE" == "$TOTAL" ]]; then
		ok "sort=$mode returns the same articles"
	else
		bad "sort=$mode wrong (HTTP $CODE, $SIZE of $TOTAL)"
	fi
done

say "5. reading needs no capability, editing still does"
occ user:delete "$PLAIN_USER" >/dev/null 2>&1 || true
if OC_PASS="$PLAIN_PW" occ user:add --password-from-env --display-name "Pages reader" "$PLAIN_USER" >/dev/null 2>&1; then
	rm -f "$PLAIN_JAR"
	LOGIN_CODE=$(curl --noproxy '*' -sk -c "$PLAIN_JAR" -u "${PLAIN_USER}:${PLAIN_PW}" -H 'OCS-APIREQUEST: true' \
		-o /tmp/tc-plain.html -w '%{http_code}' "$BASE/index.php/settings/user")
	READ_CODE=$(curl --noproxy '*' -sk -b "$PLAIN_JAR" -u "${PLAIN_USER}:${PLAIN_PW}" -H 'OCS-APIREQUEST: true' \
		-o /tmp/tc-plain.json -w '%{http_code}' "$BASE/index.php/apps/$APP_ID/api/articles")
	TREE_CODE=$(curl --noproxy '*' -sk -b "$PLAIN_JAR" -u "${PLAIN_USER}:${PLAIN_PW}" -H 'OCS-APIREQUEST: true' \
		-o /tmp/tc-plain-tree.json -w '%{http_code}' "$BASE/index.php/apps/$APP_ID/api/site/tree")
	if [[ "$LOGIN_CODE" == "200" && "$READ_CODE" == "200" ]]; then
		ok "a user without any capability can read the article list (HTTP 200)"
	else
		bad "a plain user cannot read the list (login $LOGIN_CODE, list $READ_CODE)"
	fi
	# The tree is the author's own area when the caller may author; an instance whose authoring grant
	# is a group list refuses it to everybody else. What must never appear is the site skeleton or a
	# host path.
	PLAIN_SCOPE=$(python3 -c 'import json;print(json.load(open("/tmp/tc-plain-tree.json")).get("scope",""))' 2>/dev/null || echo '')
	if [[ "$TREE_CODE" == "403" ]]; then
		ok "the same user has no authoring grant, so the source tree is refused (HTTP 403)"
	elif [[ "$TREE_CODE" == "200" && "$PLAIN_SCOPE" == "author" ]] \
		&& ! grep -qE '_config\.yml|_layouts|_includes|"source":"/' /tmp/tc-plain-tree.json 2>/dev/null; then
		ok "the same user only gets their own author area (HTTP 200, scope=author, no skeleton)"
	else
		bad "the source tree answered HTTP $TREE_CODE with scope '$PLAIN_SCOPE' for a user without the edit capability"
	fi
else
	bad "cannot create the probe user"
fi

say "6. front matter variants (the parser behind the list)"
# Two throwaway posts, deleted again below: the real posts of this site use one simple shape, so the
# branches that matter (quoted scalars, inline and block lists, an explicit permalink, and Jekyll's
# own published flag) would otherwise never be exercised.
POST_A="/_posts/2026-01-01-probe-inline-$STAMP.md"
POST_B="/_posts/2026-01-02-probe-block-$STAMP.md"
post_a=$(printf -- '---\nlayout: post\ntitle: "Probe: quoted, with colon"\ncategories: catprobe\ntags: [alpha, beta]\npermalink: /probe/custom/\n---\n\nProbe body one.\n')
post_b=$(printf -- '---\nlayout: post\ntitle: Block list probe\ntags:\n  - gamma\n  - delta\npublished: false\n---\n\nProbe body two.\n')
CODE_A=$(create_post "$POST_A" "$post_a")
CODE_B=$(create_post "$POST_B" "$post_b")
if [[ "$CODE_A" == "200" && "$CODE_B" == "200" ]]; then
	ok "two fixture posts created"
else
	bad "cannot create the fixture posts (inline $CODE_A, block $CODE_B)"
fi

CODE=$(api /api/articles)
INLINE=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if a["path"] == sys.argv[1]), None)
print("missing" if hit is None else "%s|%s|%s" % (hit["title"], ",".join(hit["tags"]), hit["url"]))
' "$POST_A")
if [[ "$INLINE" == "Probe: quoted, with colon|alpha,beta|/probe/custom/" ]]; then
	ok "quoted title, inline tag list and an explicit permalink are read correctly"
else
	bad "inline fixture read as '$INLINE'"
fi
BLOCK_LISTED=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if a["path"] == sys.argv[1]), None)
print("missing" if hit is None else hit.get("state"))
' "$POST_B")
if [[ "$BLOCK_LISTED" == "internal" ]]; then
	ok "the 'published: false' fixture is listed as internal (reading has no visibility filter)"
else
	bad "the 'published: false' fixture reads '$BLOCK_LISTED' (expected internal)"
fi
CODE=$(api /api/article "?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1], safe=""))' "$POST_B")")
BLOCK=$(py 'import json,sys;a=json.load(sys.stdin).get("article") or {};print("%s|%s|%s" % (a.get("title",""), ",".join(a.get("tags") or []), a.get("state","")))')
if [[ "$CODE" == "200" && "$BLOCK" == "Block list probe|gamma,delta|internal" ]]; then
	ok "a block-list tag block is read, and the internal post can still be opened"
else
	bad "block fixture read as '$BLOCK' (HTTP $CODE)"
fi

for p in "$POST_A" "$POST_B" "$FIXTURE"; do
	curl --noproxy '*' -sk -b "$JAR" -c "$JAR" -u "${NC_USER}:${PW}" -H 'OCS-APIREQUEST: true' \
		-H "requesttoken: $TOKEN" -X DELETE -o /dev/null \
		"$BASE/index.php/apps/$APP_ID/api/site/file?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1], safe=""))' "$p")"
done
LEFT=$(api /api/articles > /dev/null; py 'import json,sys;print(len(json.load(sys.stdin).get("articles") or []))')
ok "fixture posts removed again (the list is back to $LEFT articles)"

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
