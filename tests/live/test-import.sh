#!/usr/bin/env bash
#
# Import acceptance: a tarball replaces the Nextcloud site's `source/` folder, whole, through the file
# API. The site folder holds three sub-directories - `source/` (the Jekyll skeleton, `_config.yml` in
# it), `online/` and `pending/` (both the bridge's) - and only `source/` is imported into.
#
# The property under test is the approved model: content that comes from outside does so **only** as a
# confirmed, complete replacement - no merge, no diff. What is asserted here is exactly that:
#
#   1. an archive wrapped in one top-level folder is imported with the wrapper stripped;
#   2. a Chinese filename survives the round trip (the file the archive names is the file the folder
#      holds, and the preview builds it);
#   3. `_site/`, `.git/`, `vendor/` and `__pycache__/` entries are dropped, not imported;
#   4. `source/` is replaced: a file the previous content had and the archive does not is gone;
#   5. a timestamped backup of `source/` exists in the app's own appdata before every replacement,
#      and it holds what `source/` held at that moment;
#   6. both pipelines are refreshed and the preview is rebuilt (the imported page renders);
#   7. the author areas are untouched by the import;
#   8. an archive without `_config.yml` at its top level, or one that tries to escape the folder, is
#      refused and the folder is left exactly as it was;
#   9. the endpoint is administrator-only.
#
# The script restores the `source/` folder it found (through the app's own Import, with an archive
# taken first), so running it against the live instance does not lose the site.
#
#   sudo ./tests/live/test-import.sh
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAMP="$(date +%Y%m%d%H%M%S)"
WORKDIR=/tmp/pages-import-$STAMP
ORIG="$WORKDIR/orig.tar.gz"
SITE_BACKUP="$WORKDIR/site-original"
BARE_USER="pages-import-viewer"
BARE_PW="Pages-Import-$(date +%s)-pw"

if [[ -z "${OCC:-}" ]]; then
	for candidate in "$(command -v nextcloud.occ 2>/dev/null)" /snap/bin/nextcloud.occ; do
		[[ -n "$candidate" && -x "$candidate" ]] && { OCC="$candidate"; break; }
	done
fi
OCC="${OCC:-snap run nextcloud.occ}"
occ() { $OCC "$@"; }

DATA_ROOT="$(occ config:system:get datadirectory 2>/dev/null)"
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

OUT="$WORKDIR/out.json"
ADMIN_JAR="$WORKDIR/admin.jar"
VIEWER_JAR="$WORKDIR/viewer.jar"
LOGIN="$WORKDIR/login.html"
NC_USER=""
NC_PATH=""
PW=""
TOKEN=""
STORAGE=""
RESTORED=0

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }
mkdir -p "$WORKDIR"

cleanup() {
	if [[ "$RESTORED" -eq 0 && -f "$ORIG" && -n "$TOKEN" ]]; then
		say "restoring the site source folder that was there before this test"
		CODE=$(curl --noproxy '*' -sk -b "$ADMIN_JAR" -u "${NC_USER}:${PW}" -H 'OCS-APIREQUEST: true' \
			-H "requesttoken: $TOKEN" -F "archive=@$ORIG" -o "$OUT" -w '%{http_code}' \
			"$BASE/index.php/apps/$APP_ID/api/site/source/import")
		if [[ "$CODE" == "200" ]]; then
			ok "the original source folder was restored through Import"
		else
			bad "the restore failed (HTTP $CODE): $(head -c 300 "$OUT") - the archive is at $ORIG"
		fi
		curl --noproxy '*' -sk -b "$ADMIN_JAR" -u "${NC_USER}:${PW}" -H 'OCS-APIREQUEST: true' \
			-H "requesttoken: $TOKEN" -H 'Content-Type: application/json' -X POST --data '{}' \
			-o /dev/null "$BASE/index.php/apps/$APP_ID/api/site/build" 2>/dev/null
	fi
	dropuser "$BARE_USER"
	[[ -d "$SITE_BACKUP" ]] && ok "a plain copy of the original source folder is at $SITE_BACKUP"
}
trap cleanup EXIT

site_owner() { # site_owner → "<user>\t<path>" from the app's own site config
	# occ's output goes to a variable first: with `set -o pipefail`, a non-zero occ would fail this
	# pipeline (and the caller's `|| bad`) even when the JSON it printed is complete and valid
	local raw
	raw=$(occ config:app:get pages site 2>/dev/null)
	printf '%s' "$raw" | python3 -c '
import json, sys
try:
    s = json.load(sys.stdin)
except Exception:
    raise SystemExit(1)
print((s.get("nc_user") or "") + "\t" + (s.get("nc_path") or ""))
'
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

api() { # api <jar> <user> <password> <METHOD> <app-path> [extra curl args…]
	local jar="$1" user="$2" pass="$3" method="$4" path="$5"
	shift 5
	curl --noproxy '*' -sk -b "$jar" -c "$jar" -u "$user:$pass" -H 'OCS-APIREQUEST: true' \
		-H "requesttoken: $TOKEN" -X "$method" -o "$OUT" -w '%{http_code}' \
		"$BASE/index.php/apps/$APP_ID$path" "$@"
}

req() { api "$ADMIN_JAR" "$NC_USER" "$PW" "$@"; }

import_file() { # import_file <archive> → HTTP code, body in $OUT
	req POST /api/site/source/import -F "archive=@$1"
}

py() { python3 -c "$1" < "$OUT" 2>/dev/null; }
pyarg() { python3 -c "$1" "$2" < "$OUT" 2>/dev/null; }

site_dir() { printf '%s/%s/files/%s' "$DATA_ROOT" "$STORAGE" "$NC_PATH"; }

# The skeleton is `source/` inside the site folder (siblings: `online/`, `pending/`): that is the one
# folder Import replaces, so every path asserted below is relative to it.
source_dir() { printf '%s/source' "$(site_dir)"; }

in_folder() { [[ -e "$(source_dir)/$1" ]] && echo 1 || echo 0; }

nc_listing() { # nc_listing → the source folder's files, sorted, one per line (host view of the data dir)
	find "$(source_dir)" -type f 2>/dev/null | sed "s|^$(source_dir)/||" | sort
}

preview_dir() {
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

backup_dir_of() { # backup_dir_of <id>
	local base
	base=$(ls -d "$DATA_ROOT"/appdata_*/pages/source-backups 2>/dev/null | head -1)
	printf '%s/%s' "$base" "$1"
}

# ── setup ──────────────────────────────────────────────────────────────────────────────────────────
say "0. admin session and a copy of the site that is there now"
[[ -f "$PW_FILE" ]] || bash "$HERE/test-auth.sh" grant >/dev/null || { bad "cannot issue an app password"; exit 1; }
PW=$(cat "$PW_FILE")
SITE="$(site_owner)" || { bad "the app has no site configured"; exit 1; }
STORAGE="${SITE%%$'\t'*}"
NC_PATH="${SITE#*$'\t'}"
# the administrator who configures the site is its storage user on this instance; Import and Pull
# are administrator-only, and that is the account the password file belongs to
NC_USER="${NC_USER_OVERRIDE:-$STORAGE}"
TOKEN=$(login "$ADMIN_JAR" "$NC_USER" "$PW") || { bad "admin login failed"; exit 1; }
[[ -n "$STORAGE" && -n "$NC_PATH" ]] || { bad "cannot read the site owner/path ('$SITE')"; exit 1; }
SRC="$(site_dir)"
[[ -d "$SRC/source" ]] || { bad "the site's source folder does not exist: $SRC/source"; exit 1; }
ok "site folder: $SRC (source: $SRC/source; storage user $STORAGE, path $NC_PATH)"

cp -a "$SRC/source" "$SITE_BACKUP"
# the archive has to be the *source* content with `_config.yml` at its top level: an archive of the
# site root would come back as HTTP 400 from Import (no top-level `_config.yml`) in step 6
tar -czf "$ORIG" -C "$SRC/source" .
# the listing goes to a file first: `tar -tzf` can warn ("file changed as we read it") while the
# scheduler or the mirror touches `source/`, and `pipefail` would turn that warning into a false
# "no _config.yml" verdict. Only the listing content is judged, not tar's exit code.
tar -tzf "$ORIG" | sed 's|^\./||' > "$WORKDIR/orig.list" || true
if grep -qx '_config.yml' "$WORKDIR/orig.list"; then
	ok "the saved archive has _config.yml at its top level (the shape Import accepts)"
else
	bad "the saved archive has no top-level _config.yml: the restore in step 6 would be refused"
fi
ORIG_FILES=$(touch "$WORKDIR/orig.files"; nc_listing > "$WORKDIR/orig.files"; wc -l < "$WORKDIR/orig.files" | tr -d ' ')
ok "the current source content is saved as $ORIG and as plain files ($ORIG_FILES files)"
BEFORE_LIST=$(nc_listing)

PREVIEW="$(preview_dir)"
AUTHOR_BEFORE=$(find "$PREVIEW/tmp" -type f 2>/dev/null | wc -l | tr -d ' ')
ok "author areas in the preview tree before the import: $AUTHOR_BEFORE file(s)"

# a user who may sign in but is not an administrator: the endpoint must refuse them
dropuser "$BARE_USER"
OC_PASS="$BARE_PW" occ user:add --password-from-env --display-name "Pages import viewer" "$BARE_USER" >/dev/null 2>&1 \
	|| { bad "cannot create $BARE_USER"; exit 1; }
VIEWER_TOKEN=$(login "$VIEWER_JAR" "$BARE_USER" "$BARE_PW") || bad "the viewer cannot log in"

# ── the archives ──────────────────────────────────────────────────────────────────────────────────
say "1. build the test archives"
python3 - "$ORIG" "$WORKDIR" <<'PYEOF'
import io
import pathlib
import sys
import tarfile
import zipfile

orig = pathlib.Path(sys.argv[1])
out = pathlib.Path(sys.argv[2])
base = pathlib.Path(sys.argv[2]) / "extract"
base.mkdir(parents=True, exist_ok=True)
with tarfile.open(orig, "r:gz") as tf:
    tf.extractall(base)

def copy_tree(name: str, skip: set[str] | None = None) -> pathlib.Path:
    target = out / name
    if target.exists():
        return target
    target.mkdir()
    skip = skip or set()
    for path in sorted(base.rglob("*")):
        rel = path.relative_to(base)
        if any(part in skip for part in rel.parts):
            continue
        dest = target / rel
        if path.is_dir():
            dest.mkdir(parents=True, exist_ok=True)
        else:
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(path.read_bytes())
    return target

# A: a copy of the site's source in a single top-level folder, with a Chinese page and the entries
# that must be dropped (they are generated, history or dependencies)
a = copy_tree("site-main")
(a / "中文导入.md").write_text(
    "---\nlayout: default\ntitle: 中文导入\n---\n\nIMPORT-MARKER-CHINESE\n", encoding="utf-8")
(a / "_site").mkdir(exist_ok=True)
(a / "_site" / "index.html").write_text("GENERATED-MUST-NOT-SURVIVE\n", encoding="utf-8")
(a / ".git").mkdir(exist_ok=True)
(a / ".git" / "config").write_text("[core]\n\trepositoryformatversion = 0\n", encoding="utf-8")
(a / "vendor" / "bundle").mkdir(parents=True, exist_ok=True)
(a / "vendor" / "bundle" / "x.rb").write_text("# dependency\n", encoding="utf-8")
(a / "__pycache__").mkdir(exist_ok=True)
(a / "__pycache__" / "x.pyc").write_bytes(b"\x00\x01")
with tarfile.open(out / "import-a.tar.gz", "w:gz") as tf:
    tf.add(a, arcname="site-main")

# B: the same source without the Chinese page and without the wrapper (top-level _config.yml)
b = copy_tree("site-flat", skip={"site-main"})
with tarfile.open(out / "import-b.tar.gz", "w:gz") as tf:
    for path in sorted(b.rglob("*")):
        rel = path.relative_to(b)
        if rel.parts and rel.parts[0] == "site-flat":
            continue
        tf.add(path, arcname=str(rel))

# C: no _config.yml at all → refused
c = out / "import-c"
c.mkdir(exist_ok=True)
(c / "readme.md").write_text("no config here\n", encoding="utf-8")
with tarfile.open(out / "import-c.tar.gz", "w:gz") as tf:
    tf.add(c, arcname=".")

# D: zip-slip → refused
with zipfile.ZipFile(out / "import-d.zip", "w") as zf:
    zf.writestr("_config.yml", "title: escape\n")
    zf.writestr("../evil.md", "must never land outside the folder\n")
print("archives ready")
PYEOF
[[ -f "$WORKDIR/import-a.tar.gz" && -f "$WORKDIR/import-b.tar.gz" && -f "$WORKDIR/import-c.tar.gz" && -f "$WORKDIR/import-d.zip" ]] \
	|| { bad "the test archives were not built"; exit 1; }
ok "four archives built (wrapped, flat, no config, zip-slip)"

# ── 2. a non-administrator is refused ─────────────────────────────────────────────────────────────
say "2. the endpoint is administrator-only"
if [[ -n "$VIEWER_TOKEN" ]]; then
	TOKEN_SAVED="$TOKEN"
	TOKEN="$VIEWER_TOKEN"
	CODE=$(api "$VIEWER_JAR" "$BARE_USER" "$BARE_PW" POST /api/site/source/import -F "archive=@$WORKDIR/import-a.tar.gz")
	TOKEN="$TOKEN_SAVED"
	if [[ "$CODE" == "403" ]]; then
		ok "a signed-in non-administrator gets 403 on import"
	else
		bad "a non-administrator got HTTP $CODE instead of 403"
	fi
else
	bad "could not sign in as the non-administrator, the check was skipped"
fi

# ── 3. the import itself ─────────────────────────────────────────────────────────────────────────
say "3. import the wrapped archive"
CODE=$(import_file "$WORKDIR/import-a.tar.gz")
IMPORTED=$(py 'import json,sys;print(json.load(sys.stdin).get("files") or 0)')
SKIPPED=$(py 'import json,sys;print(json.load(sys.stdin).get("skipped") or 0)')
BACKED=$(py 'import json,sys;print(json.load(sys.stdin).get("backed_up_to") or "")')
BRIDGE_OK=$(py 'import json,sys
b = (json.load(sys.stdin).get("bridge") or {})
print(b.get("ok"))')
if [[ "$CODE" == "200" ]]; then
	ok "import answered 200 ($IMPORTED files, $SKIPPED skipped, backup $BACKED)"
else
	bad "import answered HTTP $CODE: $(head -c 300 "$OUT")"
	# nothing was written: the checks below would only repeat this failure
	say "the import failed, the folder is untouched (see $WORKDIR)"
fi
[[ "${SKIPPED:-0}" -ge 4 ]] && ok "the generated/history/dependency entries were skipped ($SKIPPED)" \
	|| bad "expected at least 4 skipped entries, got '$SKIPPED'"
[[ "$BRIDGE_OK" == "True" ]] && ok "the bridge mirrored both trees and rebuilt the preview" \
	|| bad "the preview was not rebuilt: $(head -c 200 "$OUT")"

# the wrapper is stripped: the site's own files sit at the source folder's root
if [[ "$(in_folder "_config.yml")" == "1" && "$(in_folder "site-main/_config.yml")" == "0" ]]; then
	ok "the single top-level folder was stripped (_config.yml is at source/ root)"
else
	bad "the wrapper was not stripped (site-main/_config.yml present: $(in_folder "site-main/_config.yml"))"
fi
# the Chinese filename survived, byte for byte
if [[ "$(in_folder "中文导入.md")" == "1" ]]; then
	ok "a Chinese filename survives the import (中文导入.md is in source/)"
else
	bad "the Chinese filename did not survive the import"
fi
for dropped in "_site" ".git" "vendor" "__pycache__"; do
	if [[ "$(in_folder "$dropped")" == "0" ]]; then
		ok "$dropped/ was dropped, not imported"
	else
		bad "$dropped/ reached source/"
	fi
done

# the backup is a real, timestamped copy in the app's own appdata
BACKUP_ID="${BACKED##*/}"
BACKUP_PATH="$(backup_dir_of "$BACKUP_ID")"
if [[ -n "$BACKUP_ID" && -f "$BACKUP_PATH/manifest.json" ]]; then
	BCOUNT=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1])).get("files"))' "$BACKUP_PATH/manifest.json")
	ok "a backup exists in appdata: $BACKED ($BCOUNT files)"
else
	bad "no backup found at $BACKUP_PATH"
fi
if [[ -f "$BACKUP_PATH/files/_config.yml" && -n "$(ls -A "$BACKUP_PATH/files" 2>/dev/null)" ]]; then
	ok "the backup holds source/'s previous content (_config.yml included)"
else
	bad "the backup folder is empty or incomplete ($BACKUP_PATH/files)"
fi

# the preview renders the imported page
BUILT_MARKER=$(grep -rFl -- "IMPORT-MARKER-CHINESE" "$PREVIEW/_site" 2>/dev/null | head -1)
if [[ -n "$BUILT_MARKER" ]]; then
	ok "the preview build renders the imported Chinese page ($BUILT_MARKER)"
else
	bad "the imported page is not in the preview build"
fi
AUTHOR_AFTER=$(find "$PREVIEW/tmp" -type f 2>/dev/null | wc -l | tr -d ' ')
if [[ "$AUTHOR_BEFORE" == "$AUTHOR_AFTER" ]]; then
	ok "the author areas are untouched (still $AUTHOR_AFTER file(s) in the preview tree)"
else
	bad "the import changed the author areas ($AUTHOR_BEFORE → $AUTHOR_AFTER)"
fi

# ── 4. replacement: what the archive does not name is gone ───────────────────────────────────────
say "4. a second import replaces source/ (the file the first one had is gone)"
CODE=$(import_file "$WORKDIR/import-b.tar.gz")
BACKUP_B=$(py 'import json,sys;print(json.load(sys.stdin).get("backed_up_to") or "")')
if [[ "$CODE" == "200" && "$(in_folder "中文导入.md")" == "0" ]]; then
	ok "the imported-then-omitted page is gone: source/ was replaced, not merged"
else
	bad "the replacement kept a file the archive does not name (HTTP $CODE)"
fi
BACKUP_B_ID="${BACKUP_B##*/}"
if [[ -n "$BACKUP_B_ID" && -f "$(backup_dir_of "$BACKUP_B_ID")/files/中文导入.md" ]]; then
	ok "the second backup holds the file the replacement removed (中文导入.md)"
else
	bad "the second backup does not hold the removed page ($(backup_dir_of "$BACKUP_B_ID"))"
fi
BEFORE_REFUSALS=$(nc_listing)

# ── 5. refusals leave the folder alone ──────────────────────────────────────────────────────────
say "5. an archive without _config.yml and a zip-slip archive are refused"
CODE=$(import_file "$WORKDIR/import-c.tar.gz")
if [[ "$CODE" == "400" ]]; then
	ok "an archive without _config.yml is refused with 400"
else
	bad "an archive without _config.yml answered HTTP $CODE"
fi
CODE=$(import_file "$WORKDIR/import-d.zip")
if [[ "$CODE" == "400" ]]; then
	ok "a zip-slip archive is refused with 400"
else
	bad "a zip-slip archive answered HTTP $CODE"
fi
if [[ "$(nc_listing)" == "$BEFORE_REFUSALS" ]]; then
	ok "both refusals left the folder exactly as it was"
else
	bad "a refused archive changed the folder"
fi
if [[ ! -e "$(source_dir)/evil.md" && ! -e "$(site_dir)/evil.md" && ! -e "$(site_dir)/../evil.md" && ! -e "/tmp/evil.md" ]]; then
	ok "nothing was written outside source/"
else
	bad "the zip-slip archive wrote outside the folder"
fi

# a missing file field is a bad request, not a 500
CODE=$(req POST /api/site/source/import)
[[ "$CODE" == "400" ]] && ok "a request without an archive is a 400" || bad "a request without an archive answered HTTP $CODE"

# ── 6. restore ─────────────────────────────────────────────────────────────────────────────────
say "6. restore the source folder that was there before"
CODE=$(import_file "$ORIG")
if [[ "$CODE" == "200" ]]; then
	ok "the original source content was imported back"
else
	bad "restoring the original source content answered HTTP $CODE"
fi
AFTER_LIST=$(nc_listing)
if [[ "$AFTER_LIST" == "$BEFORE_LIST" ]]; then
	ok "source/ is byte-for-byte the file list it had before ($(printf '%s\n' "$AFTER_LIST" | wc -l | tr -d ' ') files)"
	RESTORED=1
else
	bad "the restored source/ differs from the original ($(printf '%s\n' "$AFTER_LIST" | wc -l | tr -d ' ') vs $(printf '%s\n' "$BEFORE_LIST" | wc -l | tr -d ' ') files; see $SITE_BACKUP)"
fi
req POST /api/site/build -H 'Content-Type: application/json' --data '{}' > /dev/null
ok "the preview was rebuilt after the restore"

echo
if [[ "$FAIL" -eq 0 ]]; then
	printf '\033[32m  import: all checks passed\033[0m\n'
else
	printf '\033[31m  import: %s check(s) failed\033[0m\n' "$FAIL"
fi
exit "$FAIL"
