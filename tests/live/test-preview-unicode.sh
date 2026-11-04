#!/usr/bin/env bash
# Live proof: a page whose filename is Chinese must preview, and the address must be percent-encoded.
set -uo pipefail

BASE="${BASE:-https://localhost}"
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JAR=/tmp/uni.jar
OUT=/tmp/uni.out
LOGIN=/tmp/uni-login.html
NAME='中文预览'

if [[ -z "${OCC:-}" ]]; then
	for candidate in "$(command -v nextcloud.occ 2>/dev/null)" /snap/bin/nextcloud.occ; do
		[[ -n "$candidate" && -x "$candidate" ]] && { OCC="$candidate"; break; }
	done
fi
OCC="${OCC:-snap run nextcloud.occ}"
occ() { $OCC "$@"; }

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

if [[ -z "${NC_USER:-}" ]]; then
	NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
fi
[[ -n "$NC_USER" ]] || { echo "cannot determine the Nextcloud user; pass NC_USER=<name>" >&2; exit 1; }
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { echo "cannot issue an app password" >&2; exit 1; }
PW=$(cat "$PW_FILE")

# The page is an author file: its target is `<author_root>/<uid>/<file>`, and `author_root` is site
# configuration. Reading it from the API keeps the fixture working after a move (it was `tmp`, later
# `authors-src`); the preview address below stays the template's `/authors/<uid>/…` mapping.
AUTHOR_ROOT=""
TARGET=""
fail=0
ok() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
bad() { printf '  \033[31m✗\033[0m %s\n' "$1"; fail=1; }

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
TOKEN=$(login "$JAR" "$NC_USER" "$PW") || { echo "cannot log in as $NC_USER" >&2; exit 1; }
api() { curl --noproxy '*' -sk -b "$JAR" -u "$NC_USER:$PW" -H 'OCS-APIREQUEST: true' -H "requesttoken: $TOKEN" "$@"; }

# the one public fact of the author layout: where an author's own tree lands in the site
AUTHOR_ROOT=$(api "$BASE/index.php/apps/pages/api/site" 2>/dev/null \
	| python3 -c 'import json,sys;print(json.load(sys.stdin).get("author_root",""))' 2>/dev/null || echo '')
[ -n "$AUTHOR_ROOT" ] || { echo "cannot read author_root from GET /api/site" >&2; exit 1; }
TARGET="/${AUTHOR_ROOT}/${NC_USER}/${NAME}.md"
ASCII_TARGET="/${AUTHOR_ROOT}/${NC_USER}/ascii-probe.md"
echo "  author content directory: $AUTHOR_ROOT"

echo "==> create a page with a Chinese filename"
api -H 'Content-Type: application/json' -X POST \
	--data "{\"path\":\"$TARGET\",\"content\":\"---\ntitle: $NAME\nlayout: page\n---\n\nUNICODE-PREVIEW-MARKER\n\"}" \
	-o /tmp/uni-create.json -w '  create HTTP %{http_code}\n' "$BASE/index.php/apps/pages/api/site/file"

echo "==> push and build (the save already notified; build explicitly)"
api -H 'Content-Type: application/json' -X POST --data '{}' -o /dev/null -w '  push  HTTP %{http_code}\n' "$BASE/index.php/apps/pages/api/site/push"
api -H 'Content-Type: application/json' -X POST --data '{}' -o /dev/null -w '  build HTTP %{http_code}\n' "$BASE/index.php/apps/pages/api/site/build"
sleep 2

echo "==> ephemeral preview token"
TOKENJSON=$(api -H 'Content-Type: application/json' -X POST --data '{}' "$BASE/index.php/apps/pages/api/site/preview-token")
PTOKEN=$(printf '%s' "$TOKENJSON" | python3 -c 'import json,sys;d=json.load(sys.stdin);print(d.get("data",{}).get("token") or d.get("token",""))')
[ -n "$PTOKEN" ] || { bad "no preview token: $TOKENJSON"; exit 1; }
ok "preview token issued"

ENC=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "/authors/$NC_USER/$NAME.html")
RAW="/authors/$NC_USER/$NAME.html"
echo "  encoded path: $ENC"

for spec in "encoded:$ENC" "raw:$RAW"; do
	kind=${spec%%:*}
	path=${spec#*:}
	code=$(api -o /tmp/uni-page.html -w '%{http_code}' "$BASE/index.php/apps/pages/preview/$PTOKEN$path")
	case "$kind" in
	encoded)
		if [ "$code" = 200 ] && grep -q UNICODE-PREVIEW-MARKER /tmp/uni-page.html; then
			ok "the percent-encoded address renders the page (HTTP $code, marker found)"
		else
			bad "the percent-encoded address did not render (HTTP $code): $(head -c 200 /tmp/uni-page.html)"
		fi
		;;
	raw)
		if [ "$code" = 404 ]; then
			ok "the raw (unencoded) address is refused with 404 — the contract is 'addresses are encoded'"
		else
			printf '  ! raw address answered %s (informational)\n' "$code"
		fi
		;;
	esac
done

echo "==> an ASCII page still previews (regression)"
api -H 'Content-Type: application/json' -X POST \
	--data "{\"path\":\"$ASCII_TARGET\",\"content\":\"---\ntitle: ascii-probe\nlayout: page\n---\n\nASCII-MARKER\n\"}" \
	-o /dev/null -w '  create HTTP %{http_code}\n' "$BASE/index.php/apps/pages/api/site/file"
api -H 'Content-Type: application/json' -X POST --data '{}' -o /dev/null "$BASE/index.php/apps/pages/api/site/build"
sleep 2
code=$(api -o /tmp/uni-ascii.html -w '%{http_code}' "$BASE/index.php/apps/pages/preview/$PTOKEN/authors/$NC_USER/ascii-probe.html")
if [ "$code" = 200 ] && grep -q ASCII-MARKER /tmp/uni-ascii.html; then
	ok "an ASCII page is unaffected (HTTP $code)"
else
	bad "an ASCII page broke (HTTP $code)"
fi

echo "==> cleanup"
# both files are author files, so the author file API removes them (URL-encoded: the name is Chinese)
enc() { python3 -c 'import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1], safe=""))' "$1"; }
api -X DELETE -o /dev/null -w '  delete unicode  HTTP %{http_code}\n' "$BASE/index.php/apps/pages/api/site/file?path=$(enc "$TARGET")"
api -X DELETE -o /dev/null -w '  delete ascii    HTTP %{http_code}\n' "$BASE/index.php/apps/pages/api/site/file?path=$(enc "$ASCII_TARGET")"
api -H 'Content-Type: application/json' -X POST --data '{}' -o /dev/null -w '  rebuild        HTTP %{http_code}\n' "$BASE/index.php/apps/pages/api/site/build"

echo
[ "$fail" = 0 ] && echo "  all checks passed" || echo "  FAILURES above"
exit "$fail"
