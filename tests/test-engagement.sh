#!/usr/bin/env bash
#
# Community engagement acceptance (plan P4): votes and comments.
#
#   sudo ./tests/test-engagement.sh
#
# This is community data and nothing else: no build, no commit, no bridge. It works on three throwaway
# articles, removes them together with the three users it used and every comment it wrote (trap on
# EXIT), and uses no configuration: the moderation checks are the administrator's own review-and-
# publish grant (审核与发布: comment moderation and publishing are the same permission).
#
# Who writes an article decides which of the two kinds it is, and both are needed here:
#
#  - two **site posts** (`/_posts/…`, written by the administrator into the site tree): the site really
#    has them, so every signed-in reader may see, rate and discuss them - which is what the community
#    counters, the score sort and the comment thread are checked on;
#  - one **draft of $USER_A** (`/_posts/<user>/…`, written by its author into their own area): a
#    foreign reader is refused it, which is the visibility rule sections 0 and 1 pin down.
#
# The last check of section 7 needs more than one page load's worth of comments: everything a reader
# hides is still counted for the publisher, which is the whole point of the `deleted` flag.
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

USER_A="pages-engage-a"      # the author of the draft probe, and a plain reader of the two site posts
USER_B="pages-engage-b"      # the other reader: votes, comments, and the failed moderation attempt
USER_C="pages-engage-c"      # the second voter the score sort needs
PW_A="Pages-Engage-A-$(date +%s)-pw"
PW_B="Pages-Engage-B-$(date +%s)-pw"
PW_C="Pages-Engage-C-$(date +%s)-pw"
STAMP="$(date +%Y%m%d%H%M%S)"
NAME_1="2026-01-01-engage-one-${STAMP}.md"    # the two site posts, dated so that `new` orders them the
NAME_2="2026-01-02-engage-two-${STAMP}.md"    # other way round than `score` (the sort check needs that)
NAME_D="2026-01-03-engage-draft-${STAMP}.md"  # and the draft: an article of $USER_A nobody reviewed yet
TARGET_1="/_posts/${NAME_1}"
TARGET_2="/_posts/${NAME_2}"
TARGET_D="/tmp/${USER_A}/${NAME_D}"
MISSING="/_posts/2099-01-01-engage-does-not-exist-${STAMP}.md"

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

OUT=/tmp/te.json            # the body of the last admin/plain request
AOUT=/tmp/te-as.json        # the body of the last request made as an actor
COUT=/tmp/te-comments.json  # the body of the last GET of a comment thread
ADMIN_JAR=/tmp/te-admin.jar
A_JAR=/tmp/te-a.jar
B_JAR=/tmp/te-b.jar
C_JAR=/tmp/te-c.jar
LOGIN=/tmp/te-login.html
NC_USER=""
PW=""
TOKEN=""

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

pyc() { python3 -c "$1" < "$COUT" 2>/dev/null; }
pya() { python3 -c "$1" < "$AOUT" 2>/dev/null; }
# every argument is forwarded: the snippets below take two paths, and dropping the second one made the
# comparison read '' (python raised IndexError, which the redirect to /dev/null swallowed)
pyarg() { python3 -c "$1" "${@:2}" < "$AOUT" 2>/dev/null; }
json() { python3 -c "$1" "${@:2}"; }
q() { python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1], safe=""))' "$1"; }
votebody() { json 'import json,sys;print(json.dumps({"path": sys.argv[1], "vote": sys.argv[2]}))' "$1" "$2"; }
commentbody() { json 'import json,sys;print(json.dumps({"path": sys.argv[1], "body": sys.argv[2]}))' "$1" "$2"; }
idbody() { json 'import json,sys;print(json.dumps({"id": int(sys.argv[1])}))' "$1"; }

# The comment ids of one article's thread, as a line of numbers. Read as the administrator, who sees the
# deleted ones too. An answer that is not a thread (403, 404, an error) contributes nothing.
thread_ids() { # thread_ids <target>
	local body
	body=$(curl --noproxy '*' -sk -b "$ADMIN_JAR" -c "$ADMIN_JAR" -u "${NC_USER}:${PW}" \
		-H 'OCS-APIREQUEST: true' -H "requesttoken: $TOKEN" \
		"$BASE/index.php/apps/$APP_ID/api/article/comments?path=$(q "$1")" 2>/dev/null)
	printf '%s' "$body" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    raise SystemExit(0)
print(" ".join(str(c.get("id")) for c in (d.get("comments") or [])))
' 2>/dev/null
}

cleanup() {
	if [[ -n "$TOKEN" ]]; then
		# The comment rows go first: the probe articles are the only things that make them reachable. The
		# thread is read as the administrator, who sees the deleted ones too, so this also removes the
		# comments a check deleted - a soft-deleted row is still a row this script put there.
		local id target
		for target in "$TARGET_1" "$TARGET_2" "$TARGET_D"; do
			for id in $(thread_ids "$target"); do
				curl --noproxy '*' -sk -b "$ADMIN_JAR" -c "$ADMIN_JAR" -u "${NC_USER}:${PW}" \
					-H 'OCS-APIREQUEST: true' -H "requesttoken: $TOKEN" -H 'Content-Type: application/json' \
					-X DELETE --data "{\"id\": $id}" -o /dev/null \
					"$BASE/index.php/apps/$APP_ID/api/article/comments" || true
			done
		done
		# then the articles: their files, their rows and - with them - every vote anyone cast on them.
		# The two site posts are the administrator's to remove; the draft is a file of $USER_A's own
		# files, which only its author's session reaches.
		for target in "$TARGET_1" "$TARGET_2"; do
			curl --noproxy '*' -sk -b "$ADMIN_JAR" -c "$ADMIN_JAR" -u "${NC_USER}:${PW}" \
				-H 'OCS-APIREQUEST: true' -H "requesttoken: $TOKEN" -X DELETE -o /dev/null \
				"$BASE/index.php/apps/$APP_ID/api/site/file?path=$(q "$target")" || true
		done
		curl --noproxy '*' -sk -b "$A_JAR" -c "$A_JAR" -u "${USER_A}:${PW_A}" \
			-H 'OCS-APIREQUEST: true' -X DELETE -o /dev/null \
			"$BASE/index.php/apps/$APP_ID/api/site/file?path=$(q "$TARGET_D")" || true
	fi
	# the users go last, with whatever rows of theirs are left: no path leads to a vote once its article
	# is gone, and a comment of a deleted author is invisible in a thread of a deleted article
	for id in "$USER_A" "$USER_B" "$USER_C"; do occ user:delete "$id" >/dev/null 2>&1 || true; done
	rm -f "$OUT" "$AOUT" "$COUT" "$ADMIN_JAR" "$A_JAR" "$B_JAR" "$C_JAR" "$LOGIN"
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

asreq() { # asreq <a|b|c> <METHOD> <app-path> [query] [json] → HTTP code, body in $AOUT
	local who="$1" method="$2" path="$3" query="${4:-}" body="${5:-}" jar pw uid
	case "$who" in
		a) jar="$A_JAR"; pw="$PW_A"; uid="$USER_A" ;;
		b) jar="$B_JAR"; pw="$PW_B"; uid="$USER_B" ;;
		c) jar="$C_JAR"; pw="$PW_C"; uid="$USER_C" ;;
		*) bad "unknown actor $who"; return 1 ;;
	esac
	local args=(-X "$method" -b "$jar" -c "$jar" -u "${uid}:${pw}" -H 'OCS-APIREQUEST: true'
		-o "$AOUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(-H 'Content-Type: application/json' --data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path$query"
}
aread() { asreq "$1" GET "$2" "${3:-}"; }
apost() { asreq "$1" POST "$2" '' "${3:-}"; }
adel()  { asreq "$1" DELETE "$2" '' "${3:-}"; }

# Casts one vote and prints "<HTTP> <up>|<down>|<score>|<mine>|<comments>" from the answer.
vote_as() { # vote_as <actor> <target> <up|down|none>
	local code
	code=$(apost "$1" /api/article/vote "$(votebody "$2" "$3")")
	printf '%s %s' "$code" "$(pya '
import json, sys
a = json.load(sys.stdin).get("article") or {}
print("%s|%s|%s|%s|%s" % (a.get("up"), a.get("down"), a.get("score"), a.get("mine"), a.get("comments")))
')"
}

# Writes one comment, prints the HTTP code. The comment itself is left to the trap, which reads every
# thread of the probe articles as the administrator and removes what it finds there.
comment_as() { # comment_as <actor> <target> <body>
	apost "$1" /api/article/comments "$(commentbody "$2" "$3")"
}

# Reads a thread as an actor: prints "<HTTP> <count>|<id,id,…>", keeps the body in $COUT. The ids are
# what the visibility checks compare, and they are a field of their own: a comment can be 2000
# characters long, which is not something an assertion should have to print (or to search ids in).
my_comments() { # my_comments <actor> <target>
	local code
	code=$(asreq "$1" GET /api/article/comments "?path=$(q "$2")")
	cp "$AOUT" "$COUT"
	printf '%s %s' "$code" "$(pyc '
import json, sys
d = json.load(sys.stdin)
items = d.get("comments") or []
print("%s|%s" % (d.get("count"), ",".join(str(c.get("id")) for c in items)))
')"
}

# Whether a "<HTTP> <count>|<ids>" answer carries one id. Ids are compared as fields and never as
# substrings, so comment 2 is not "found" in comment 12.
thread_has() { # thread_has <id> <thread answer>
	python3 -c '
import sys
ids = sys.argv[2].split("|", 1)[1].split(",") if "|" in sys.argv[2] else []
print("yes" if sys.argv[1] in ids else "no")
' "$1" "$2" 2>/dev/null
}

# The administrator's own call, with the admin session: the same shape as asreq(), from $ADMIN_JAR.
adminreq() { # adminreq <METHOD> <app-path> [json]
	local method="$1" path="$2" body="${3:-}"
	local args=(-X "$method" -b "$ADMIN_JAR" -c "$ADMIN_JAR" -u "${NC_USER}:${PW}"
		-H 'OCS-APIREQUEST: true' -H "requesttoken: $TOKEN" -o "$AOUT" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(-H 'Content-Type: application/json' --data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path"
}

# Reads a thread as the administrator: prints "<HTTP> <count>|<deleted ids, comma-separated or ->". What
# a publisher sees is the moderation view: every row of the thread, with the ones that were taken down
# still there and flagged. The ids are sorted numerically, so the answer does not depend on the order in
# which comments were deleted.
admin_thread() { # admin_thread <target>
	local code
	code=$(adminreq GET "/api/article/comments?path=$(q "$1")")
	cp "$AOUT" "$COUT"
	printf '%s %s' "$code" "$(pyc '
import json, sys
items = json.load(sys.stdin).get("comments") or []
gone = sorted(int(c["id"]) for c in items if c.get("deleted") is True)
print("%s|%s" % (len(items), ",".join(str(i) for i in gone) if gone else "-"))
')"
}

dav() { # dav <user> <pass> <METHOD> <dav-path> [data] → HTTP code
	local user="$1" pass="$2" method="$3" path="$4" data="${5:-}"
	local args=(-X "$method" -u "$user:$pass" -o /dev/null -w '%{http_code}')
	[[ -n "$data" ]] && args+=(--data-binary "$data")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/remote.php/dav/files/$user$path"
}

mkuser() { # mkuser <uid> <password>
	occ user:delete "$1" >/dev/null 2>&1 || true
	OC_PASS="$2" occ user:add --password-from-env --display-name "Pages engage $1" "$1" >/dev/null 2>&1 \
		|| { bad "cannot create $1"; exit 1; }
	# a real write, so the account's files folder exists before the app touches it
	dav "$1" "$2" PUT "/pages-engage-probe.txt" 'x' > /dev/null
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

# The front matter both probe kinds share: a plain Jekyll post, so the site would build it.
article_body() { # article_body <target> <title>
	json '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\nlayout: post\ntitle: %s\ntags: engage\n---\n\nBody of %s.\n" % (sys.argv[2], sys.argv[2])}))
' "$1" "$2"
}

# A site post: the administrator may write the site tree, so this path is a file of the site itself and
# the site really has it - every signed-in reader may see, rate and discuss it.
create_post() { # create_post <target> <title>
	req POST /api/site/file '' "$(article_body "$1" "$2")"
}

# An author's article: only the author's own area answers to this path, so this is an internal page of
# $USER_A - reading has no visibility filter, so every signed-in reader may see, rate and discuss it,
# and the row says which state it is in.
create_draft() { # create_draft <target> <title>
	asreq a POST /api/site/file '' "$(article_body "$1" "$2")"
}

# ---------------------------------------------------------------- setup
say "0. sessions, two site posts and one draft"
NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
[[ -n "$NC_USER" ]] || { bad "no Nextcloud user found"; exit 1; }
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { bad "cannot issue an app password"; exit 1; }
PW=$(cat "$PW_FILE")
TOKEN=$(login "$ADMIN_JAR" "$NC_USER" "$PW") || { bad "admin login failed"; exit 1; }
mkuser "$USER_A" "$PW_A"
mkuser "$USER_B" "$PW_B"
mkuser "$USER_C" "$PW_C"
[[ -n "$(login "$A_JAR" "$USER_A" "$PW_A")" ]] || { bad "$USER_A cannot log in"; exit 1; }
[[ -n "$(login "$B_JAR" "$USER_B" "$PW_B")" ]] || { bad "$USER_B cannot log in"; exit 1; }
[[ -n "$(login "$C_JAR" "$USER_C" "$PW_C")" ]] || { bad "$USER_C cannot log in"; exit 1; }

CODE_1=$(create_post "$TARGET_1" "Engage one")
CODE_2=$(create_post "$TARGET_2" "Engage two")
CODE_D=$(create_draft "$TARGET_D" "Engage draft")
if [[ "$CODE_1" == "200" && "$CODE_2" == "200" && "$CODE_D" == "200" ]]; then
	ok "two site posts and one draft of $USER_A created ($TARGET_1, $TARGET_2, $TARGET_D)"
else
	bad "cannot create the probe articles (HTTP $CODE_1, $CODE_2, $CODE_D): $(head -c 200 "$OUT")"
fi
# The two site posts are what the community checks below run on, and this is the rule they build on: the
# site has them, so a reader who is neither the author nor a publisher may read them.
CODE=$(aread b /api/article "?path=$(q "$TARGET_1")")
if [[ "$CODE" == "200" ]]; then
	ok "another reader may read a site post (HTTP 200) - the rule the community checks build on"
else
	bad "reading a site post answered HTTP $CODE (expected 200)"
fi
# An author's page is the other rule, and the pair of answers is the check: its author reads it, and so
# does everybody else - reading an entry of the site needs no visibility decision. What the answer has
# to carry is the state, so a reader knows the site does not serve it yet.
CODE=$(aread a /api/article "?path=$(q "$TARGET_D")")
DRAFT=$(pya 'import json,sys;print((json.load(sys.stdin).get("article") or {}).get("path") or "")')
if [[ "$CODE" == "200" && "$DRAFT" == "$TARGET_D" ]]; then
	ok "the internal page's own author reads it (HTTP 200, $DRAFT)"
else
	bad "the author could not read their own page (HTTP $CODE, '$DRAFT')"
fi
CODE=$(aread b /api/article "?path=$(q "$TARGET_D")")
FOREIGN=$(pya 'import json,sys;d=json.load(sys.stdin);print("%s|%s" % ((d.get("article") or {}).get("path") or "", (d.get("article") or {}).get("state") or ""))')
if [[ "$CODE" == "200" && "$FOREIGN" == "$TARGET_D|internal" ]]; then
	ok "another reader reads it too, and the row says internal (no visibility filter)"
else
	bad "reading another author's internal page answered HTTP $CODE ('$FOREIGN')"
fi

say "1. engagement follows reading: every existing entry can be voted on"
CODE=$(apost b /api/article/vote "$(votebody "$MISSING" up)")
if [[ "$CODE" == "404" ]]; then
	ok "voting on a path that is no article answers 404"
else
	bad "an unknown article answered HTTP $CODE (expected 404)"
fi
CODE=$(apost b /api/article/vote "$(votebody "$TARGET_D" up)")
if [[ "$CODE" == "200" ]]; then
	ok "voting on another author's internal page is allowed (engagement has no visibility filter)"
else
	bad "a vote on an internal page answered HTTP $CODE (expected 200)"
fi

say "2. one vote per reader, changeable and withdrawable"
V=$(vote_as b "$TARGET_1" up)
if [[ "$V" == "200 1|0|1|1|0" ]]; then
	ok "b voted up: up=1 down=0 score=1, and b reads mine=1 ($V)"
else
	bad "b's upvote came back as '$V' (expected '200 1|0|1|1|0')"
fi
V=$(vote_as a "$TARGET_1" down)
if [[ "$V" == "200 1|1|0|-1|0" ]]; then
	ok "a voted down on the same article as well: up=1 down=1 score=0, mine=-1 ($V)"
else
	bad "a's downvote came back as '$V' (expected '200 1|1|0|-1|0')"
fi
V=$(vote_as b "$TARGET_1" down)
if [[ "$V" == "200 0|2|-2|-1|0" ]]; then
	ok "b changed sides: the up is gone (up=0) and the down kept a's (-1 for b's view) ($V)"
else
	bad "changing a vote came back as '$V' (expected '200 0|2|-2|-1|0')"
fi
V=$(vote_as a "$TARGET_1" none)
if [[ "$V" == "200 0|1|-1|0|0" ]]; then
	ok "withdrawing a's vote takes it out of the aggregate and out of mine ($V)"
else
	bad "withdrawing came back as '$V' (expected '200 0|1|-1|0|0')"
fi
V=$(vote_as b "$TARGET_1" none)
if [[ "$V" == "200 0|0|0|0|0" ]]; then
	ok "and with both withdrawn the article carries no votes at all ($V)"
else
	bad "the cleaned-up article reads '$V' (expected '200 0|0|0|0|0')"
fi
CODE=$(apost b /api/article/vote "$(votebody "$TARGET_1" sideways)")
if [[ "$CODE" == "400" ]]; then
	ok "a vote that is none of up/down/none answers 400"
else
	bad "an unknown vote value answered HTTP $CODE (expected 400)"
fi

say "3. sort=score really orders by the votes"
# Two upvotes against one, cast in the order two (first) then one (second), and the two site posts are
# dated so that `new` would order them the other way round. An implementation that returned the
# articles the way it found them, or that ignored the counters, cannot pass both reads below.
vote_as b "$TARGET_2" up > /dev/null
vote_as b "$TARGET_1" up > /dev/null
vote_as c "$TARGET_1" up > /dev/null
CODE=$(aread b /api/articles '?sort=new')
NEW_ORDER=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
pos = {a["path"]: i for i, a in enumerate(items)}
one, two = sys.argv[1], sys.argv[2]
if one not in pos or two not in pos:
    print("missing:%s%s" % ("" if one in pos else " one", "" if two in pos else " two"))
else:
    print("%s|%s|%s" % ("ok" if pos[two] < pos[one] else "wrong-order", pos[one], pos[two]))
' "$TARGET_1" "$TARGET_2")
if [[ "$CODE" == "200" && "$NEW_ORDER" == ok\|* ]]; then
	ok "sort=new puts the newer post first (positions ${NEW_ORDER#ok|}), so the score order below differs"
else
	bad "sort=new is wrong (HTTP $CODE, '$NEW_ORDER')"
fi
# Read as b, who voted on both posts: the score order and the caller's own vote are two answers of the
# same list, and `mine` is the caller's own row - the second half of this section reads the same list as
# somebody who did not vote.
CODE=$(aread b /api/articles '?sort=score')
SORTED=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
pos = {a["path"]: i for i, a in enumerate(items)}
one, two = sys.argv[1], sys.argv[2]
if one not in pos or two not in pos:
    print("missing:%s%s" % ("" if one in pos else " one", "" if two in pos else " two"))
else:
    print("%s|%s|%s" % ("ok" if pos[one] < pos[two] else "wrong-order", pos[one], pos[two]))
' "$TARGET_1" "$TARGET_2")
if [[ "$CODE" == "200" && "$SORTED" == ok\|* ]]; then
	ok "sort=score puts the two-vote post before the one-vote post (positions ${SORTED#ok|})"
else
	bad "sort=score is wrong (HTTP $CODE, '$SORTED')"
fi
LISTED=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if a["path"] == sys.argv[1]), None)
print("missing" if hit is None else "%s|%s|%s" % (hit["up"], hit["down"], hit["mine"]))
' "$TARGET_1")
if [[ "$LISTED" == "2|0|1" ]]; then
	ok "the list carries the real counters and the caller's own vote (up=2 down=0 mine=1 for $USER_B, who voted)"
else
	bad "the reading list reports '$LISTED' for the voted article as $USER_B (expected '2|0|1')"
fi
# the same article, read by somebody who did not vote: the counters do not move, `mine` does
CODE=$(aread a /api/articles '?sort=score')
MINE_0=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if a["path"] == sys.argv[1]), None)
print("missing" if hit is None else "%s|%s|%s" % (hit["up"], hit["down"], hit["mine"]))
' "$TARGET_1")
if [[ "$CODE" == "200" && "$MINE_0" == "2|0|0" ]]; then
	ok "and reports mine=0 for $USER_A, who withdrew their vote in section 2 and has not voted since"
else
	bad "the reading list reports '$MINE_0' for the voted article as $USER_A (expected '2|0|0')"
fi
# a clean slate: the comment checks below must not be confused by votes
vote_as b "$TARGET_2" none > /dev/null
vote_as b "$TARGET_1" none > /dev/null
vote_as c "$TARGET_1" none > /dev/null
# sort=comments shares the same answer shape and reads the same counter the list already carries for
# every article, so the path is checked here rather than in an acceptance of its own
CODE=$(aread a /api/articles '?sort=comments')
COUNTED=$(pyarg '
import json, sys
items = json.load(sys.stdin).get("articles") or []
hit = next((a for a in items if a["path"] == sys.argv[1]), None)
print("missing" if hit is None else hit["comments"])
' "$TARGET_1")
if [[ "$CODE" == "200" && "$COUNTED" == "0" ]]; then
	ok "sort=comments answers the same list and carries each article's comment count (0 so far)"
else
	bad "sort=comments answered HTTP $CODE with '$COUNTED' (expected 0 comments)"
fi

say "4. a comment is the caller's own, plain text, and checked before it is stored"
CODE=$(comment_as b "$TARGET_1" "   hello from b   ")
SHAPE=$(pya '
import json, sys
c = json.load(sys.stdin).get("comment") or {}
print("%s|%s|%s|%s" % (c.get("author"), c.get("body"), c.get("format"), c.get("mine")))
')
if [[ "$CODE" == "200" && "$SHAPE" == "$USER_B|hello from b|text|True" ]]; then
	ok "b's comment is stored trimmed, marked plain text, and marked as b's own ($SHAPE)"
else
	bad "writing a comment answered HTTP $CODE with '$SHAPE'"
fi
# the cooldown is waited out on purpose here: the next checks are about the body, and the limit is
# re-armed in the section after them
sleep 21
CODE=$(comment_as a "$TARGET_1" "$(python3 -c 'print("x" * 2001)')")
LONG=$(pya 'import json,sys;print((json.load(sys.stdin).get("message") or "")[:70])')
if [[ "$CODE" == "400" && -n "$LONG" ]]; then
	ok "a body over the limit answers 400 with a message ('$LONG…')"
else
	bad "an over-long comment answered HTTP $CODE ('$LONG')"
fi
CODE=$(comment_as a "$TARGET_1" '     ')
if [[ "$CODE" == "400" ]]; then
	ok "a body that is only whitespace answers 400 (the trimming is the server's)"
else
	bad "a blank comment answered HTTP $CODE (expected 400)"
fi
# the limit is characters, not bytes, and the normalisation happens on the server: 2000 characters
# come back exactly as they were sent. This is a's own comment, and its id is what section 7 asks b to
# delete (a foreign comment, refused) and then has the administrator moderate.
CODE=$(comment_as a "$TARGET_1" "$(python3 -c 'print("y" * 2000)')")
LEN=$(pya 'import json,sys;print(len((json.load(sys.stdin).get("comment") or {}).get("body") or ""))')
COMMENT_A=$(pya 'import json,sys;print((json.load(sys.stdin).get("comment") or {}).get("id") or "")')
if [[ "$CODE" == "200" && "$LEN" == "2000" ]]; then
	ok "exactly 2000 characters are accepted and stored unchanged (length $LEN, id $COMMENT_A)"
else
	bad "a 2000-character comment answered HTTP $CODE with length $LEN"
fi

say "5. the rate limit is part of the feature"
# the 2000-character comment was the last accepted one, so a further one right now is inside the
# cooldown: 20 seconds between two comments of the same author on the same article
CODE=$(comment_as a "$TARGET_1" "one more right away")
WAIT=$(pya '
import json, sys
d = json.load(sys.stdin)
print("%s|%s" % (d.get("retryAfter"), d.get("message")))
')
if [[ "$CODE" == "429" && "$WAIT" == *second* ]]; then
	ok "a second comment inside the cooldown answers 429 and names the wait ($WAIT)"
else
	bad "the cooldown answered HTTP $CODE with '$WAIT' (expected 429 naming the wait)"
fi
# the rejected comment must not have been stored: the thread still holds exactly the accepted ones
THREAD=$(my_comments a "$TARGET_1")
if [[ "$THREAD" == "200 2|"* ]]; then
	ok "the refused comment left nothing behind (the thread still counts 2)"
else
	bad "a refused comment changed the thread: '$THREAD'"
fi
# another article is another budget: the limit is per article, not per user
CODE=$(comment_as a "$TARGET_2" "a comment on the other article")
if [[ "$CODE" == "200" ]]; then
	ok "the same author may comment on another article straight away (the limit is per article)"
else
	bad "commenting on the other article answered HTTP $CODE (expected 200)"
fi

say "6. the thread is read oldest first"
THREAD=$(my_comments a "$TARGET_1")
ORDERED=$(pyc '
import json, sys
items = json.load(sys.stdin).get("comments") or []
bodies = [c.get("body") for c in items]
print("ok" if bodies and bodies[0].startswith("hello from b") else "wrong:%s" % bodies[:2])
')
if [[ "$THREAD" == "200 2|"* && "$ORDERED" == "ok" ]]; then
	ok "a sees two comments, the older one first"
else
	bad "the thread reads '$THREAD' ($ORDERED)"
fi

say "7. deleting a comment: one's own, somebody else's, and moderation"
# The thread as the publisher sees it before anything is deleted: the snapshot the last checks compare
# against. Nothing is deleted at this moment, so this count is also what a reader sees.
BEFORE=$(admin_thread "$TARGET_1")
BEFORE_COUNT="${BEFORE#* }"
BEFORE_COUNT="${BEFORE_COUNT%%|*}"
CODE=$(comment_as b "$TARGET_1" "b writes something to delete")
COMMENT_B=$(pya 'import json,sys;print((json.load(sys.stdin).get("comment") or {}).get("id") or "")')
CODE=$(adel b /api/article/comments "$(idbody "$COMMENT_B")")
COUNT_B=$(pya 'import json,sys;print(json.load(sys.stdin).get("count"))')
# the count in the answer is b's own view: the comments b still sees are the ones that were visible
# before b wrote this third one - the deleted row is not one of them, which is why the thread is back to
# BEFORE_COUNT rather than one shorter
if [[ "$CODE" == "200" && "$COUNT_B" == "$BEFORE_COUNT" ]]; then
	ok "b deleted b's own comment (HTTP 200, b's thread is back to $COUNT_B)"
else
	bad "deleting one's own comment answered HTTP $CODE (count $COUNT_B, expected $BEFORE_COUNT)"
fi
# the deleted row still counts towards the cooldown: deleting one's own comment is not a way to keep
# talking, which is what an implementation that filtered deleted rows out of the count would allow
CODE=$(comment_as b "$TARGET_1" "b tries again too soon")
if [[ "$CODE" == "429" ]]; then
	ok "the cooldown still holds after a deletion (the deleted row counts)"
else
	bad "commenting right after a deletion answered HTTP $CODE (expected 429)"
fi
# Somebody else's comment is a's 2000-character one, whose id section 4 kept: b is a plain reader and may
# not touch it, whatever its place in the thread. (Naming it explicitly matters - the thread a sees
# starts with b's own comment, so "the first comment of the thread" is not somebody else's.)
CODE=$(adel b /api/article/comments "$(idbody "$COMMENT_A")")
if [[ "$CODE" == "403" ]]; then
	ok "another reader cannot delete somebody else's comment (HTTP 403)"
else
	bad "deleting a foreign comment answered HTTP $CODE (expected 403)"
fi
CODE=$(adel b /api/article/comments "$(idbody 999999999)")
if [[ "$CODE" == "404" ]]; then
	ok "an id nobody wrote answers 404"
else
	bad "an unknown comment id answered HTTP $CODE (expected 404)"
fi
# The administrator holds the publish grant, so moderation is theirs: every row of the thread is still
# counted and the one that was taken down is still there, flagged. A plain reader - a, whose own comment
# is untouched by the refused attempt above - sees neither the row nor the flag. The thread holds one row
# more than the snapshot: b's extra comment, written and deleted above.
PUBLISHER=$(admin_thread "$TARGET_1")
READER=$(my_comments a "$TARGET_1")
READER_COUNT="${READER#* }"
READER_COUNT="${READER_COUNT%%|*}"
if [[ "$PUBLISHER" == "200 $((BEFORE_COUNT + 1))|$COMMENT_B" && "$READER" == "200 ${BEFORE_COUNT}|"* ]]; then
	ok "the publisher still counts every row of the thread ($PUBLISHER), the reader only the visible ones ($READER)"
else
	bad "deleted-comment visibility is wrong (publisher '$PUBLISHER', reader '$READER'; expected '200 $((BEFORE_COUNT + 1))|$COMMENT_B' and a reader count of $BEFORE_COUNT)"
fi
CODE=$(adminreq DELETE /api/article/comments "$(idbody "$COMMENT_A")")
if [[ "$CODE" == "200" ]]; then
	ok "a publisher deletes somebody else's comment (moderation, HTTP 200)"
else
	bad "moderation answered HTTP $CODE: $(head -c 200 "$AOUT")"
fi
# The moderated comment is a's own: a soft-deleted row is gone from every reader's view - its author's
# included - while it stays a row the publisher counts and still sees flagged. The reader's count alone
# cannot show which comment left, so the id is checked as well.
READER=$(my_comments a "$TARGET_1")
AFTER=$(admin_thread "$TARGET_1")
if [[ "$READER" == "200 $((BEFORE_COUNT - 1))|"* && "$(thread_has "$COMMENT_A" "$READER")" == "no" \
	&& "$AFTER" == "200 $((BEFORE_COUNT + 1))|"* && "$(thread_has "$COMMENT_A" "$AFTER")" == "yes" ]]; then
	ok "the reader no longer sees the moderated comment ($READER: only b's is left), the publisher still counts it ($AFTER)"
else
	bad "after moderation the reader reads '$READER' and the publisher '$AFTER' (expected $COMMENT_A gone for the reader and still counted for the publisher)"
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
