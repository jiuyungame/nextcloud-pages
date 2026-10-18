#!/usr/bin/env bash
#
# End-to-end regression for create/delete/rename of files and folders (creates and deletes a real
# file), including the rule that a post's file name may not carry a date in the future.
#
#   sudo ./tests/test-newfile.sh
#
# Single-site design: no site id is passed in and no URL carries one any more.
# Write requests need a requesttoken (Nextcloud answers 412 without it) and Basic auth does not
# send one, so scrape it from the app page and reuse that cookie jar.
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
NC_USER="${NC_USER:-}"
BRIDGE_CONF="${BRIDGE_CONF:-/etc/pages-bridge/pages-bridge.toml}"
# the work copy is the bridge's global work root itself (single site: no <work_root>/<id> any more)
WORK_DIR="${WORK_DIR:-}"
JAR=$(mktemp)
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

# cleanup must run even on failure: this script writes into a real site repository
CREATED=0
CREATED_FOLDER=0
CREATED_INNER=0
# extra files the date-prefix checks create (they are deleted again on exit), and the folder the
# successful move into _posts/alice creates: removed only when this run is the one that made it
EXTRA=()
CREATED_DATE_DIR=0
REL=""
FOLDER=""
INNER=""
cleanup() {
	# single site: the route needs no id, only a requesttoken to authenticate the write. The cookie
	# jar is what carries that session, so it is removed only after the deletes below.
	if [[ -n "${TOKEN:-}" ]]; then
		if [[ "$CREATED" -eq 1 && -n "$REL" ]]; then
			printf '\n\033[1;33m[!]\033[0m cleaning up leftover test file: %s\n' "$REL"
			api DELETE "/api/site/file?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$REL")" >/dev/null 2>&1 || true
		fi
		if [[ "${#EXTRA[@]}" -gt 0 ]]; then
			for extra in "${EXTRA[@]}"; do
				printf '\n\033[1;33m[!]\033[0m cleaning up leftover test file: %s\n' "$extra"
				api DELETE "/api/site/file?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$extra")" >/dev/null 2>&1 || true
			done
		fi
		# deleting a directory is recursive, so only the directory needs handling
		if [[ "$CREATED_FOLDER" -eq 1 && -n "$FOLDER" ]]; then
			printf '\n\033[1;33m[!]\033[0m cleaning up leftover test directory: %s\n' "${FOLDER%/*}"
			api DELETE "/api/site/file?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "${FOLDER%/*}")" >/dev/null 2>&1 || true
		elif [[ "$CREATED_INNER" -eq 1 && -n "$INNER" ]]; then
			api DELETE "/api/site/file?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$INNER")" >/dev/null 2>&1 || true
		fi
		# the date checks moved a file into _posts/alice: if that folder was not there before this
		# run, it goes away with its content (the file delete above already emptied it)
		if [[ "$CREATED_DATE_DIR" -eq 1 ]]; then
			printf '\n\033[1;33m[!]\033[0m cleaning up the test folder: _posts/alice\n'
			api DELETE "/api/site/file?path=_posts%2Falice" >/dev/null 2>&1 || true
		fi
	fi
	rm -f "$JAR"
}
trap cleanup EXIT

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

if [[ -z "$NC_USER" ]]; then
	NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
fi
if [[ ! -f "$PW_FILE" ]]; then
	bash "$HERE/test-auth.sh" grant >/dev/null || { echo "failed to issue an app password" >&2; exit 1; }
fi
PW=$(cat "$PW_FILE")

# ask the bridge for the work copy path instead of guessing it (single site: it is the work root)
SECRET=$(sed -n 's/^\s*shared_secret\s*=\s*"\([^"]*\)".*/\1/p' "$BRIDGE_CONF" | head -1)
if [[ -z "$WORK_DIR" && -n "$SECRET" ]]; then
	WORK_DIR=$(curl --noproxy '*' -s -H "Authorization: Bearer $SECRET" http://127.0.0.1:8787/admin/config \
		| python3 -c 'import sys,json;print((json.load(sys.stdin).get("site") or {}).get("work",""))' 2>/dev/null || echo '')
fi

# fixed prefix on the test file name, so leftovers are easy to spot and clean; the day is in the
# past, because a post name may no longer carry a future one (that rule has its own section below)
STAMP=$(date +%Y%m%d-%H%M%S)
REL="_posts/2025-12-31-pages-selftest-${STAMP}.md"
say "0. grab a requesttoken (write requests get 412 without it)"
curl --noproxy '*' -sk -c "$JAR" -u "${NC_USER}:${PW}" -o /tmp/nf-page.html \
	"$BASE/index.php/apps/$APP_ID/"
TOKEN=$(sed -n 's/.*data-requesttoken="\([^"]*\)".*/\1/p' /tmp/nf-page.html | head -1)
if [[ -z "$TOKEN" ]]; then
	bad "no data-requesttoken in the page - the app page may not have rendered (run verify-deploy.sh first)"
	exit 1
fi
ok "requesttoken = ${TOKEN:0:12}…"
if [[ -n "$WORK_DIR" ]]; then
	ok "work copy (asked the bridge) = $WORK_DIR"
else
	bad "cannot get the work copy path - the later 'does the file appear' assertions become meaningless"
fi

# do not use VAR=$(api ...) to also return the status code: command substitution is a subshell,
# so the body goes to a file and the status code goes to stdout
api_to() { # api_to <body-file> <METHOD> <PATH> [JSON] → stdout = HTTP status code
	local outfile="$1" method="$2" path="$3" body="${4:-}"
	local args=(-X "$method" -H "requesttoken: $TOKEN" -H 'OCS-APIREQUEST: true'
		-H 'Content-Type: application/json' -b "$JAR" -c "$JAR" -o "$outfile" -w '%{http_code}')
	[[ -n "$body" ]] && args+=(--data "$body")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/$APP_ID$path"
}
BODY_FILE=/tmp/nf-body.json
api() { # body-only shortcut
	api_to "$BODY_FILE" "$@" >/dev/null
	cat "$BODY_FILE"
}

# A refusal is asserted by its reason, and the reason is translated by the server: this instance runs
# with a zh_CN interface, so the English string alone is not a stable expectation. Accept the English
# text the app uses, or the app's own translation of it (l10n/*.json), and require the error code - a
# check that only grepped for English would fail on every translated instance.
message_is() { # message_is <body-file> <English string> → 1/0
	python3 -c '
import glob, json, os, sys
wanted = sys.argv[1]
allowed = {wanted}
for path in glob.glob(os.path.join(sys.argv[2], "l10n", "*.json")):
    try:
        table = json.load(open(path, encoding="utf-8"))
    except Exception:
        continue
    # Nextcloud ships translations as {"translations": {…}, "pluralForm": …}
    if isinstance(table.get("translations"), dict):
        table = table["translations"]
    value = table.get(wanted)
    if isinstance(value, str):
        allowed.add(value)
try:
    body = json.load(open(sys.argv[3], encoding="utf-8"))
except Exception:
    body = {}
print(1 if body.get("error") == "BAD_REQUEST" and (body.get("message") or "") in allowed else 0)
' "$2" "$HERE/.." "$1"
}

say "1. does the site config carry new_file.templates"
SITE_JSON=$(api GET /api/site)
printf '%s' "$SITE_JSON" | python3 -c '
import sys, json
d = json.load(sys.stdin)
s = d.get("site") or {}
if not s:
    print("NO_SITE"); raise SystemExit
tpls = ((s.get("new_file") or {}).get("templates")) or []
# no id and no name any more: the site is identified by its folder
print("site:", s.get("nc_path"))
for t in tpls:
    print("  template:", t.get("id"), "|", t.get("label"), "|", t.get("path"),
          "| gate:", t.get("when_present") or "-")
'
if printf '%s' "$SITE_JSON" | grep -q '"new_file"'; then
	ok "new_file is delivered with the site config (older sites get defaults filled in on read)"
else
	bad "no new_file in the site config"
fi

say "2. create $REL"
CREATE_BODY=$(python3 -c '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\nlayout: post\ntitle: \"selftest\"\n---\n\nhello\n"}, ensure_ascii=False))
' "$REL")
CODE=$(api_to "$BODY_FILE" POST "/api/site/file" "$CREATE_BODY")
OUT=$(cat "$BODY_FILE")
printf '%s\n' "$OUT" | head -c 400; echo
if [[ "$CODE" == "200" && "$OUT" == *'"created":true'* ]]; then
	CREATED=1
	ok "created (created:true, HTTP $CODE)"
else
	bad "create failed (HTTP $CODE)"
fi

say "3. create the same path again (must be rejected, never overwritten)"
# the second create uses different content, so "was it overwritten" is verifiable, not guessed
# Nextcloud JSON escapes non-ASCII as \uXXXX, so never grep for literal non-ASCII text
OVERWRITE_BODY=$(python3 -c '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "OVERWRITTEN-BY-SECOND-CREATE\n"}, ensure_ascii=False))
' "$REL")
CODE2=$(api_to "$BODY_FILE" POST "/api/site/file" "$OVERWRITE_BODY")
OUT2=$(cat "$BODY_FILE")
printf '%s\n' "$OUT2" | head -c 300; echo
if [[ "$CODE2" == "409" && "$OUT2" == *'"error":"CONFLICT"'* ]]; then
	ok "duplicate name rejected (HTTP 409 / CONFLICT)"
else
	bad "duplicate name was not rejected (HTTP $CODE2) - that would lose content"
fi

READ_BACK=$(api GET "/api/site/file?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$REL")")
if [[ "$READ_BACK" == *'OVERWRITTEN-BY-SECOND-CREATE'* ]]; then
	bad "the original file was overwritten by the second create!"
else
	ok "the original content was not changed by the second create"
fi

say "4. wait for mirror + build (max 30 seconds)"
WORK="$WORK_DIR/$REL"
found=0
for i in $(seq 1 15); do
	if [[ -f "$WORK" ]]; then found=1; break; fi
	sleep 2
done
if [[ "$found" -eq 1 ]]; then
	ok "file mirrored into the work copy: $WORK"
	printf '     first content line: %s\n' "$(head -1 "$WORK")"
else
	bad "did not see $WORK in the work copy within 30 s - the mirror step did not run"
fi

say "5. visible in the tree"
if api GET "/api/site/tree" | grep -q "$(basename "$REL")"; then
	ok "file tree contains $(basename "$REL")"
else
	bad "the new file is not in the file tree"
fi

say "5b. create a folder (multi-level)"
FOLDER="_selftest-dir-${STAMP}/nested"
CODE_F=$(api_to "$BODY_FILE" POST "/api/site/folder" \
	"$(python3 -c 'import json,sys;print(json.dumps({"path":sys.argv[1]}))' "$FOLDER")")
OUT_F=$(cat "$BODY_FILE")
printf '%s\n' "$OUT_F" | head -c 300; echo
if [[ "$CODE_F" == "200" && "$OUT_F" == *'"created":true'* ]]; then
	CREATED_FOLDER=1
	ok "directory created (including intermediate parents)"
else
	bad "directory create failed (HTTP $CODE_F)"
fi

CODE_F2=$(api_to "$BODY_FILE" POST "/api/site/folder" \
	"$(python3 -c 'import json,sys;print(json.dumps({"path":sys.argv[1]}))' "$FOLDER")")
if [[ "$CODE_F2" == "409" ]]; then
	ok "duplicate directory rejected (HTTP 409)"
else
	bad "duplicate directory did not return 409 (HTTP $CODE_F2)"
fi

WORK_FOLDER="$WORK_DIR/$FOLDER"
# empty directories are never mirrored (git does not track them), so assert absence while empty
if [[ -d "$WORK_FOLDER" ]]; then
	bad "empty directory was mirrored (contradicts the docs): $WORK_FOLDER"
else
	ok "empty directory was not mirrored (expected: git does not track empty directories)"
fi

say "5c. put a file into the new directory (only now should the directory appear)"
INNER="$FOLDER/hello.txt"
CODE_I=$(api_to "$BODY_FILE" POST "/api/site/file" \
	"$(python3 -c 'import json,sys;print(json.dumps({"path":sys.argv[1],"content":"hi\n"}))' "$INNER")")
if [[ "$CODE_I" == "200" ]]; then
	CREATED_INNER=1
	ok "file created: $INNER"
else
	bad "creating a file inside the new directory failed (HTTP $CODE_I)"
fi

found_f=0
for i in $(seq 1 15); do
	if [[ -f "$WORK_FOLDER/hello.txt" ]]; then found_f=1; break; fi
	sleep 2
done
if [[ "$found_f" -eq 1 ]]; then
	ok "directory and its file mirrored into the work copy"
else
	bad "did not see $WORK_FOLDER/hello.txt in the work copy within 30 s - the mirror step did not run"
fi

say "5d. rename a file"
RENAMED_REL="_posts/2025-12-31-pages-renamed-${STAMP}.md"
CODE_R=$(api_to "$BODY_FILE" POST "/api/site/move" \
	"$(python3 -c 'import json,sys;print(json.dumps({"from":sys.argv[1],"to":sys.argv[2]}))' "$REL" "$RENAMED_REL")")
OUT_R=$(cat "$BODY_FILE")
printf '%s\n' "$OUT_R" | head -c 300; echo
# assert only on segments without a slash: Nextcloud JSON escapes `/` as `\/`
if [[ "$CODE_R" == "200" && "$OUT_R" == *'"unchanged":false'* && "$OUT_R" == *"$(basename "$RENAMED_REL")"* ]]; then
	REL="$RENAMED_REL"
	WORK="$WORK_DIR/$REL"
	ok "rename succeeded (HTTP 200)"
else
	bad "rename failed (HTTP $CODE_R)"
fi

TREE_NOW=$(api GET "/api/site/tree")
if [[ "$TREE_NOW" == *"$(basename "$REL")"* ]]; then
	ok "the tree shows the new name"
else
	bad "the tree does not show the new name $(basename "$REL")"
fi

moved=0
for i in $(seq 1 15); do
	if [[ -f "$WORK" ]]; then moved=1; break; fi
	sleep 2
done
if [[ "$moved" -eq 1 ]]; then
	ok "the new name appeared in the work copy (renames propagate)"
else
	bad "did not see $WORK in the work copy within 30 s"
fi
if [[ -e "$WORK_DIR/_posts/2025-12-31-pages-selftest-${STAMP}.md" ]]; then
	bad "the old name is still in the work copy"
else
	ok "the old name is gone from the work copy"
fi

say "5e. rename a directory (with the files inside it)"
OLD_FOLDER="${FOLDER%/*}"
NEW_FOLDER="_selftest-dir-renamed-${STAMP}"
CODE_RD=$(api_to "$BODY_FILE" POST "/api/site/move" \
	"$(python3 -c 'import json,sys;print(json.dumps({"from":sys.argv[1],"to":sys.argv[2]}))' "$OLD_FOLDER" "$NEW_FOLDER")")
OUT_RD=$(cat "$BODY_FILE")
printf '%s\n' "$OUT_RD" | head -c 300; echo
if [[ "$CODE_RD" == "200" && "$OUT_RD" == *'"type":"dir"'* && "$OUT_RD" == *"$NEW_FOLDER"* ]]; then
	FOLDER="$NEW_FOLDER/nested"
	WORK_FOLDER="$WORK_DIR/$NEW_FOLDER/nested"
	INNER="$FOLDER/hello.txt"
	ok "directory rename succeeded"
else
	bad "directory rename failed (HTTP $CODE_RD)"
fi

moved_d=0
for i in $(seq 1 15); do
	if [[ -f "$WORK_FOLDER/hello.txt" ]]; then moved_d=1; break; fi
	sleep 2
done
if [[ "$moved_d" -eq 1 ]]; then
	ok "the directory and its files were renamed"
else
	bad "did not see $WORK_FOLDER/hello.txt in the work copy within 30 s"
fi

say "5f. rename onto an existing name (must be 409 and must not touch the target)"
CODE_C=$(api_to "$BODY_FILE" POST "/api/site/move" \
	"$(python3 -c 'import json,sys;print(json.dumps({"from":sys.argv[1],"to":"index.html"}))' "$REL")")
OUT_C=$(cat "$BODY_FILE")
printf '%s\n' "$OUT_C" | head -c 240; echo
if [[ "$CODE_C" == "409" && "$OUT_C" == *'"error":"CONFLICT"'* ]]; then
	ok "existing target rejected (HTTP 409)"
else
	bad "renaming onto an existing path did not return 409 (HTTP $CODE_C) - that would overwrite content"
fi
if [[ "$(api GET "/api/site/tree")" == *"$(basename "$REL")"* ]]; then
	ok "the source file is still in place after the rejection"
else
	bad "the source file disappeared after the rejection"
fi

say "5g. a post name may not carry a date in the future"
# The day in `_posts/**`/YYYY-MM-DD-slug.md is a post's date as much as the `date` field is, and a
# move is how a rename changes it: a target under a later day has to be refused with 400, and nothing
# may move. The date checks live in `_posts/alice`, which only this run may create and remove.
DATE_DIR_EXISTED=$(api GET "/api/site/tree" | python3 -c '
import json, sys
nodes = json.load(sys.stdin).get("nodes") or []
print(1 if any((n.get("path") == "_posts/alice" and n.get("type") == "dir") for n in nodes) else 0)
' 2>/dev/null || echo 1)
if [[ "$DATE_DIR_EXISTED" != "1" ]]; then
	CREATED_DATE_DIR=1
	ok "_posts/alice does not exist yet (this run creates it, and removes it again on exit)"
fi
FUTURE_REL="_posts/alice/2999-01-01-x-${STAMP}.md"
CODE_FU=$(api_to "$BODY_FILE" POST "/api/site/move" \
	"$(python3 -c 'import json,sys;print(json.dumps({"from":sys.argv[1],"to":sys.argv[2]}))' "$REL" "$FUTURE_REL")")
OUT_FU=$(cat "$BODY_FILE")
printf '%s\n' "$OUT_FU" | head -c 300; echo
if [[ "$CODE_FU" == "400" && "$(message_is "$BODY_FILE" 'The file name may not carry a future date.')" == "1" ]]; then
	ok "moving a file to a future-dated post name is refused (HTTP 400, BAD_REQUEST, the name message)"
else
	bad "a move to $FUTURE_REL was not refused as 400/BAD_REQUEST with the name message (HTTP $CODE_FU)"
fi
if [[ "$(api GET "/api/site/tree")" == *"$(basename "$REL")"* ]]; then
	ok "the refused move left the source file where it was"
else
	bad "the source file moved even though the move was refused"
fi
if [[ "$(api GET "/api/site/tree")" == *"2999-01-01-x-${STAMP}"* ]]; then
	bad "the refused move created the future-dated target"
else
	ok "the refused move created nothing"
fi

say "5h. today and the past are accepted, and a name without a date is left alone"
PAST_REL="_posts/alice/2020-01-01-x-${STAMP}.md"
CODE_PA=$(api_to "$BODY_FILE" POST "/api/site/move" \
	"$(python3 -c 'import json,sys;print(json.dumps({"from":sys.argv[1],"to":sys.argv[2]}))' "$REL" "$PAST_REL")")
OUT_PA=$(cat "$BODY_FILE")
printf '%s\n' "$OUT_PA" | head -c 240; echo
if [[ "$CODE_PA" == "200" ]]; then
	REL="$PAST_REL"
	WORK="$WORK_DIR/$REL"
	ok "moving to an earlier date prefix is accepted (HTTP 200)"
else
	bad "moving to an earlier date prefix failed (HTTP $CODE_PA)"
fi
# the ordinary "fix the slug" case: the rename keeps the existing (past) prefix
SAME_DAY_REL="_posts/alice/2020-01-01-y-${STAMP}.md"
CODE_SD=$(api_to "$BODY_FILE" POST "/api/site/move" \
	"$(python3 -c 'import json,sys;print(json.dumps({"from":sys.argv[1],"to":sys.argv[2]}))' "$PAST_REL" "$SAME_DAY_REL")")
if [[ "$CODE_SD" == "200" ]]; then
	REL="$SAME_DAY_REL"
	WORK="$WORK_DIR/$REL"
	ok "a rename that keeps an existing date prefix still works (HTTP 200)"
else
	bad "renaming a post while keeping its date prefix failed (HTTP $CODE_SD)"
fi
# no date prefix at all: not a post by this rule, so the existing rules decide (here: it moves)
PLAIN_REL="_posts/alice/pages-nodate-${STAMP}.md"
CODE_PL=$(api_to "$BODY_FILE" POST "/api/site/move" \
	"$(python3 -c 'import json,sys;print(json.dumps({"from":sys.argv[1],"to":sys.argv[2]}))' "$REL" "$PLAIN_REL")")
OUT_PL=$(cat "$BODY_FILE")
printf '%s\n' "$OUT_PL" | head -c 240; echo
if [[ "$CODE_PL" == "200" ]]; then
	REL="$PLAIN_REL"
	WORK="$WORK_DIR/$REL"
	ok "moving to a name with no date prefix is accepted (HTTP 200, existing rule unchanged)"
else
	bad "moving to a name with no date prefix failed (HTTP $CODE_PL)"
fi

say "5i. creation is held to the same rule (there is a separate path for it)"
# POST /api/site/file is how a post is created, so a future name must be refused there too - the
# two entry points cannot disagree about a name the save path then refuses.
FUTURE_NEW="_posts/alice/2999-01-01-created-${STAMP}.md"
CODE_FN=$(api_to "$BODY_FILE" POST "/api/site/file" "$(python3 -c '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\nlayout: post\ntitle: \"future\"\n---\n\nfuture\n"}))
' "$FUTURE_NEW")")
OUT_FN=$(cat "$BODY_FILE")
printf '%s\n' "$OUT_FN" | head -c 300; echo
if [[ "$CODE_FN" == "400" && "$(message_is "$BODY_FILE" 'The file name may not carry a future date.')" == "1" ]]; then
	ok "creating a post with a future date prefix is refused (HTTP 400, BAD_REQUEST, the name message)"
else
	bad "creating $FUTURE_NEW was not refused as 400/BAD_REQUEST with the name message (HTTP $CODE_FN)"
fi
CODE_GONE=$(api_to "$BODY_FILE" GET "/api/site/file?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$FUTURE_NEW")")
if [[ "$CODE_GONE" == "404" ]]; then
	ok "the refused creation wrote nothing (GET answers 404)"
else
	bad "the refused creation left a file behind (GET answered $CODE_GONE)"
fi
# the boundary: the instance's own day is accepted (Nextcloud runs PHP in UTC, so use UTC here)
TODAY_NEW="_posts/alice/$(date -u +%Y-%m-%d)-created-${STAMP}.md"
CODE_TN=$(api_to "$BODY_FILE" POST "/api/site/file" "$(python3 -c '
import json, sys
print(json.dumps({"path": sys.argv[1], "content": "---\nlayout: post\ntitle: \"today\"\n---\n\ntoday\n"}))
' "$TODAY_NEW")")
if [[ "$CODE_TN" == "200" ]]; then
	EXTRA=("${EXTRA[@]}" "$TODAY_NEW")
	ok "creating a post dated today is accepted (HTTP 200, the boundary is inclusive)"
else
	bad "creating a post dated today was refused (HTTP $CODE_TN) - the boundary must be inclusive"
fi

say "6. delete the file"
OUT3=$(api DELETE "/api/site/file?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "$REL")")
printf '%s\n' "$OUT3" | head -c 300; echo
if [[ "$OUT3" == *'"deleted":true'* ]]; then
	CREATED=0
	ok "delete endpoint returned deleted:true"
else
	bad "delete failed"
fi

gone=0
for i in $(seq 1 15); do
	if [[ ! -f "$WORK" ]]; then gone=1; break; fi
	sleep 2
done
if [[ "$gone" -eq 1 ]]; then
	ok "the file disappeared from the work copy (deletes propagate)"
else
	bad "$WORK is still in the work copy"
fi

say "6b. delete the folder (must take subdirectories recursively)"
OUT_F3=$(api DELETE "/api/site/file?path=$(python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))' "${FOLDER%/*}")")
printf '%s\n' "$OUT_F3" | head -c 200; echo
gone_f=0
for i in $(seq 1 15); do
	if [[ ! -d "$WORK_FOLDER" ]]; then gone_f=1; break; fi
	sleep 2
done
if [[ "$gone_f" -eq 1 ]]; then
	CREATED_FOLDER=0
	CREATED_INNER=0
	ok "the directory (with the files inside) is gone recursively from the work copy"
else
	bad "$WORK_FOLDER is still in the work copy"
fi

# a delete must not leave an empty shell directory behind (git hides those from `status`).
# The site template's Authors.rb plugin mirrors `authors.source` (tmp) into `authors/`, and an author
# whose folder became empty leaves an empty target directory there - generated by the site, invisible
# to git, and not the mirror's bookkeeping, so it is not counted here.
LEFT=$(find "$WORK_DIR" -mindepth 1 -type d -empty \
	-not -path '*/.git/*' -not -path '*/_site/*' -not -path '*/vendor/*' \
	-not -path '*/authors/*' \
	-not -path '*/.jekyll-cache/*' -not -path '*/.bundle/*' 2>/dev/null | head -5)
if [[ -z "$LEFT" ]]; then
	ok "no leftover empty directories in the work copy"
else
	bad "leftover empty directories in the work copy: $LEFT"
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
