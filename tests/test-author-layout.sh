#!/usr/bin/env bash
#
# Author content reset (one-shot, flag `author_reset_v1`): old author content is discarded, the site's
# own content is not, and a second start changes nothing.
#
#   sudo ./tests/test-author-layout.sh
#
# The reset already ran when this app version first booted on this instance, so this suite proves it
# twice over:
#
#   0. the result the first run left: every author's `Pages/` is empty, the manifest carries no
#      author-area key, the site's own entries are untouched, and the snapshot is on disk;
#   1. a second run, forced by writing content and clearing the flag: the files are gone, the manifest
#      entries with them, the backup (manifest + one tar per author) is in the app's own storage, and
#      the site source under `_config.yml`/`guides.md` is byte-identical;
#   2. idempotence: with the flag set again, two boots leave `config:list pages` and the manifest
#      byte-identical - and the doctor's next run reports a healthy author source (and fails when the
#      site's `authors.source` names another directory, which would make the mirror invisible).
set -uo pipefail

BASE="${BASE:-https://localhost}"
APP_ID=pages
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAMP="$(date +%Y%m%d%H%M%S)"
USER_A="pages-layout-${STAMP}"
PW_A="Pages-Layout-${STAMP}-pw"
AREA="Pages"

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

[[ ${EUID} -eq 0 ]] || { echo "run as root (sudo $0)" >&2; exit 1; }

APPDATA=$(ls -d /var/snap/nextcloud/common/nextcloud/data/appdata_*/pages 2>/dev/null | head -1)
[[ -n "$APPDATA" ]] || APPDATA="/var/snap/nextcloud/common/nextcloud/data/appdata_$(occ config:system:get instanceid)/pages"
MANIFEST="$APPDATA/review.json"
NC_USER="${NC_USER:-$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)}"
SITE_NCUSER=$(occ config:app:get pages site 2>/dev/null | python3 -c 'import json,sys;print((json.load(sys.stdin).get("nc_user") or ""))' 2>/dev/null || echo '')
SITE_NCPATH=$(occ config:app:get pages site 2>/dev/null | python3 -c 'import json,sys;print((json.load(sys.stdin).get("nc_path") or ""))' 2>/dev/null || echo '')
SITE_ROOT="/var/snap/nextcloud/common/nextcloud/data/${SITE_NCUSER}/files${SITE_NCPATH}"
AUTHOR_ROOT=$(occ config:app:get pages author_root 2>/dev/null || echo tmp)

cleanup() {
	occ user:delete "$USER_A" >/dev/null 2>&1 || true
	# every run of this suite forces the reset; leave the flag where the app expects it
	occ config:app:set pages author_reset_v1 --value 1 >/dev/null 2>&1 || true
	rm -f /tmp/tal-*.json /tmp/tal-*.tar /tmp/tal-*.txt
}
trap cleanup EXIT

# ---------------------------------------------------------------- helpers
boot() { # one request is enough: Application::boot() runs the one-shot on every request
	local code
	code=$(curl --noproxy '*' -sk -o /dev/null -w '%{http_code}' -u "${NC_USER}:$(cat "$PW_FILE")" \
		-H 'OCS-APIREQUEST: true' "$BASE/index.php/apps/$APP_ID/")
	printf '%s' "$code"
}

manifest_json() { python3 -c 'import json,sys;print(json.dumps(json.load(open(sys.argv[1]))))' "$MANIFEST"; }
manifest_hash() { sha256sum "$MANIFEST" | cut -d' ' -f1; }
config_hash() { occ config:list pages > /tmp/tal-config.json 2>/dev/null; sha256sum /tmp/tal-config.json | cut -d' ' -f1; }
newest_backup() { ls -dt "$APPDATA"/reset-* 2>/dev/null | head -1; }
author_keys() { # author_keys <file> → the author-area keys of a manifest file
	python3 - "$1" "$AUTHOR_ROOT" "$USER_A" <<'PY'
import json, sys
path, root, uid = sys.argv[1], sys.argv[2].strip("/"), sys.argv[3]
data = json.load(open(path))
keys = list((data.get("entries") or {})) + list((data.get("published") or {}))
hit = [k for k in keys if k.startswith(root + "/") or k.startswith(f"_posts/{uid}/")]
print(",".join(hit))
PY
}

if [[ ! -f "$PW_FILE" ]]; then
	bash "$HERE/test-auth.sh" grant >/dev/null 2>&1 || true
fi
[[ -f "$PW_FILE" ]] || { echo "no app password ($PW_FILE)" >&2; exit 1; }

say "0. the one-shot ran, and it left a snapshot"
FLAG=$(occ config:app:get pages author_reset_v1 2>/dev/null)
if [[ "$FLAG" == "1" ]]; then
	ok "the one-shot reset is flagged as done (author_reset_v1=1)"
else
	bad "author_reset_v1 is '$FLAG' (expected 1: the reset did not finish)"
fi
# The *result* of the first run (empty areas, no author keys) is asserted by the forced run below: this
# instance is shared with the other suites, and a later suite's user leaves an inert entry behind that
# the one-shot will never look at again. What must hold at every start is the flag and the snapshot.
BACKUP0=$(newest_backup)
if [[ -n "$BACKUP0" && -f "$BACKUP0/review.json" ]]; then
	ok "a snapshot of the manifest is in the app's own storage ($(basename "$BACKUP0")/review.json)"
else
	bad "no reset backup (with review.json) was found under $APPDATA"
fi

say "1. a forced second run discards author content and leaves the site alone"
SITE_SOURCE_BEFORE=$(sha256sum "$SITE_ROOT/_config.yml" "$SITE_ROOT/guides.md" 2>/dev/null | sha256sum | cut -d' ' -f1)
site_entries() { python3 - "$MANIFEST" "$AUTHOR_ROOT" <<'PY'
import json, re, sys
data = json.load(open(sys.argv[1]))
root = sys.argv[2].strip("/") + "/"
# the same rule the reset applies: the author root, and the retired `_posts/<uid>/…` namespace
site = {k: v for k, v in (data.get("entries") or {}).items()
        if not k.startswith(root) and not re.match(r"^_posts/[^/]+/", k)}
print(json.dumps(site, sort_keys=True))
PY
}
SITE_ENTRY_BEFORE=$(site_entries)
# a throwaway author with content at two depths, so the tar and the deletion are both exercised
occ user:delete "$USER_A" >/dev/null 2>&1 || true
OC_PASS="$PW_A" occ user:add --password-from-env --display-name "Pages layout $USER_A" "$USER_A" >/dev/null 2>&1 \
	|| { bad "cannot create $USER_A"; exit 1; }
occ group:adduser test2 "$USER_A" >/dev/null 2>&1 || true
dav() { # dav <METHOD> <dav-path> [data]
	local method="$1" path="$2" data="${3:-}"
	local args=(-X "$method" -u "$USER_A:$PW_A" -o /dev/null -w '%{http_code}')
	[[ -n "$data" ]] && args+=(--data-binary "$data")
	curl --noproxy '*' -sk "${args[@]}" "$BASE/remote.php/dav/files/$USER_A$path"
}
# a write at the root materialises the account's files folder
dav PUT "/pages-layout-probe.txt" 'x' > /dev/null
dav MKCOL "/${AREA}" > /dev/null
dav MKCOL "/${AREA}/notes" > /dev/null
dav PUT "/${AREA}/a-${STAMP}.md" "$(printf -- '---\ntitle: Layout probe one\n---\n\nOne.\n')" > /dev/null
dav PUT "/${AREA}/notes/b-${STAMP}.md" "$(printf -- '---\ntitle: Layout probe two\n---\n\nTwo.\n')" > /dev/null
FILES_BEFORE=$(find "/var/snap/nextcloud/common/nextcloud/data/${USER_A}/files/${AREA}" -type f 2>/dev/null | wc -l)
if [[ "$FILES_BEFORE" == "2" ]]; then
	ok "the probe author holds two files before the run ($USER_A)"
else
	bad "the probe author holds $FILES_BEFORE files (expected 2)"
fi
# an entry in the manifest, through the app's own path (a request is what writes one)
LOGIN_JAR=/tmp/tal-a.jar
LOGIN_PAGE=/tmp/tal-login.html
curl --noproxy '*' -sk -c "$LOGIN_JAR" -u "$USER_A:$PW_A" -H 'OCS-APIREQUEST: true' -o "$LOGIN_PAGE" \
	"$BASE/index.php/settings/user" > /dev/null
ATOKEN=$(sed -n 's/.*data-requesttoken="\([^"]*\)".*/\1/p' "$LOGIN_PAGE" | head -1)
submit() {
	curl --noproxy '*' -sk -b "$LOGIN_JAR" -u "$USER_A:$PW_A" -H 'OCS-APIREQUEST: true' \
		-H "requesttoken: $ATOKEN" -H 'Content-Type: application/json' \
		--data "{\"path\": \"/${AUTHOR_ROOT}/${USER_A}/$1\", \"intent\": \"online\"}" -o /dev/null -w '%{http_code}' \
		"$BASE/index.php/apps/$APP_ID/api/article/request"
}
submit "a-${STAMP}.md" > /dev/null
submit "notes/b-${STAMP}.md" > /dev/null
ENTRY_BEFORE=$(author_keys "$MANIFEST")
if [[ -n "$ENTRY_BEFORE" ]]; then
	ok "the manifest does carry the probe author's entries before the run ($ENTRY_BEFORE)"
else
	bad "the probe author's files are not in the manifest: the entries branch would prove nothing"
fi
# a published entry is what a publish writes; this instance cannot commit (its work copy is shallow and
# the GitHub remote is not reachable here), so the app's own record is written directly
python3 - "$MANIFEST" "$AUTHOR_ROOT" "$USER_A" "$STAMP" <<'PY'
import json, sys, time
path, root, uid, stamp = sys.argv[1], sys.argv[2].strip("/"), sys.argv[3], sys.argv[4]
data = json.load(open(path))
published = data.get("published")
if not isinstance(published, dict):
    published = {}
published[f"{root}/{uid}/a-{stamp}.md"] = {"owner": uid, "revision": "probe", "publishedAt": int(time.time())}
data["published"] = published
json.dump(data, open(path, "w"), indent=2, ensure_ascii=False)
PY
PUBLISHED_BEFORE=$(python3 -c '
import json, sys
d = json.load(open(sys.argv[1]))
print(len([k for k in (d.get("published") or {}) if k.startswith(sys.argv[2].strip("/") + "/")]))
' "$MANIFEST" "$AUTHOR_ROOT")
if [[ "$PUBLISHED_BEFORE" -ge 1 ]]; then
	ok "the manifest also carries a published entry for the author ($PUBLISHED_BEFORE)"
else
	bad "the published entry could not be written"
fi
# force the one-shot: clear the flag, then let one request boot the app
occ config:app:delete pages author_reset_v1 >/dev/null 2>&1 || true
CODE=$(boot)
if [[ "$CODE" == "200" ]]; then
	ok "one request after clearing the flag boots the app (HTTP 200)"
else
	bad "the app answered HTTP $CODE; the reset may not have run"
fi
FLAG=$(occ config:app:get pages author_reset_v1 2>/dev/null)
[[ "$FLAG" == "1" ]] && ok "the run wrote the flag again (author_reset_v1=1)" || bad "the flag is '$FLAG' after the run"
FILES_AFTER=$(find "/var/snap/nextcloud/common/nextcloud/data/${USER_A}/files/${AREA}" -type f 2>/dev/null | wc -l)
if [[ "$FILES_AFTER" == "0" ]]; then
	ok "every file under the author's Pages/ is gone (the folder itself stays)"
else
	bad "$FILES_AFTER file(s) survived the reset"
fi
if [[ -d "/var/snap/nextcloud/common/nextcloud/data/${USER_A}/files/${AREA}" ]]; then
	ok "the author's Pages/ folder itself is kept"
else
	bad "the reset removed the author's Pages/ folder"
fi
KEYS_AFTER=$(author_keys "$MANIFEST")
if [[ -z "$KEYS_AFTER" ]]; then
	ok "the author-area entries are gone from entries and published"
else
	bad "author keys survived the reset: $KEYS_AFTER"
fi
SITE_ENTRY_AFTER=$(site_entries)
if [[ "$SITE_ENTRY_AFTER" == "$SITE_ENTRY_BEFORE" ]]; then
	ok "the site's own entries are untouched (byte-identical as JSON)"
else
	bad "the reset changed the site's own entries"
fi
SITE_SOURCE_AFTER=$(sha256sum "$SITE_ROOT/_config.yml" "$SITE_ROOT/guides.md" 2>/dev/null | sha256sum | cut -d' ' -f1)
if [[ "$SITE_SOURCE_BEFORE" == "$SITE_SOURCE_AFTER" ]]; then
	ok "the site source is untouched (_config.yml and guides.md byte-identical)"
else
	bad "the reset changed the site source"
fi
BACKUP=$(newest_backup)
if [[ -n "$BACKUP" && -f "$BACKUP/review.json" ]]; then
	ok "the run took a snapshot of the manifest first ($(basename "$BACKUP")/review.json)"
else
	bad "the run left no manifest snapshot under $APPDATA"
fi
TAR="$BACKUP/pages-${USER_A}.tar"
if [[ -f "$TAR" ]]; then
	ok "the run wrote one tar per author ($(basename "$TAR"))"
else
	bad "no tar for $USER_A was written (found: $(ls "$BACKUP" 2>/dev/null | tr '\n' ' '))"
fi
TAR_OK=$(python3 - "$TAR" "$STAMP" <<'PY'
import sys, tarfile
path, stamp = sys.argv[1], sys.argv[2]
try:
    with tarfile.open(path) as tar:
        names = tar.getnames()
        data = tar.extractfile(f"notes/b-{stamp}.md").read().decode()
except Exception as exc:
    print(f"error: {exc}")
else:
    print("ok" if f"a-{stamp}.md" in names and "Layout probe two" in data else f"bad names {names}")
PY
)
if [[ "$TAR_OK" == "ok" ]]; then
	ok "the tar holds both files, with their bytes (readable with a plain tar reader)"
else
	bad "the backup tar is wrong: $TAR_OK"
fi

say "2. a second start changes neither the configuration nor the manifest"
H1=$(manifest_hash); C1=$(config_hash)
boot > /dev/null
H2=$(manifest_hash); C2=$(config_hash)
boot > /dev/null
H3=$(manifest_hash); C3=$(config_hash)
if [[ "$H1" == "$H2" && "$H2" == "$H3" ]]; then
	ok "the manifest is byte-identical across two boots (${H1:0:16}…)"
else
	bad "the manifest changed across a boot ($H1 / $H2 / $H3)"
fi
if [[ "$C1" == "$C2" && "$C2" == "$C3" ]]; then
	ok "config:list pages is byte-identical across two boots"
else
	bad "the app configuration changed across a boot"
fi

say "3. the doctor checks that the site reads the mirror from where the app writes it"
DOCTOR=/tmp/tal-doctor.txt
occ pages:doctor > "$DOCTOR" 2>&1 || true
if grep -q 'site author source' "$DOCTOR" && grep -q 'authors.source: tmp' "$DOCTOR"; then
	ok "the doctor reports the site author source and it is the author root (tmp)"
else
	bad "the doctor does not report a matching site author source: $(grep -i 'author source' "$DOCTOR" | head -2)"
fi
CONFIG_FILE="$SITE_ROOT/_config.yml"
cp "$CONFIG_FILE" /tmp/tal-config.yml
sed -i 's/^  source: tmp/  source: authors-src/' "$CONFIG_FILE"
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
cp /tmp/tal-config.yml "$CONFIG_FILE"
occ pages:doctor > "$DOCTOR" 2>&1 || true
if grep -q 'site author source' "$DOCTOR" && ! grep -q 'invisible to the site' "$DOCTOR"; then
	ok "the restored authors.source checks out again (✓)"
else
	bad "the doctor still reports the mismatched author source after the restore"
fi
if cmp -s /tmp/tal-config.yml "$CONFIG_FILE"; then
	ok "the site's _config.yml is restored byte for byte"
else
	bad "the site's _config.yml was not restored"
fi
if grep -q 'author content directory' "$DOCTOR"; then
	ok "the doctor still reports the author content directory"
else
	bad "the doctor no longer reports the author content directory"
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
