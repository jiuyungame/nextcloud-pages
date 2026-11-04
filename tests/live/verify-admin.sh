#!/usr/bin/env bash
#
# Admin settings page (/settings/admin/pages) acceptance check.
#
#   sudo ./tests/live/verify-admin.sh
#
# The settings framework renders this page, so its own endpoints are covered by this suite and the live route probes in tests/live/.
set -uo pipefail

BASE="${BASE:-https://localhost}"
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
NC_USER="${NC_USER:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# the app source lives at the repo root, one level above tests/: never address it relative to the
# caller's cwd (run-all starts the suites in the repo root, a standalone run may be anywhere)
ROOT="$(cd "$HERE/../.." && pwd)"

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

if [[ -z "$NC_USER" ]]; then
	NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
fi
if [[ ! -f "$PW_FILE" ]]; then
	bash "$HERE/test-auth.sh" grant >/dev/null || { echo "failed to issue an app password" >&2; exit 1; }
fi
PW=$(cat "$PW_FILE")

PAGE=/tmp/va-admin.html
HDR=/tmp/va-admin.hdr
JAR=/tmp/va-admin.jar
BODY=/tmp/va-admin.json

say "0. log in and grab a requesttoken (write requests get 412 without it)"
rm -f "$JAR"
curl --noproxy '*' -sk -c "$JAR" -u "${NC_USER}:${PW}" -o "$PAGE" "$BASE/index.php/settings/admin/pages"
TOKEN=$(sed -n 's/.*data-requesttoken="\([^"]*\)".*/\1/p' "$PAGE" | head -1)
if [[ -n "$TOKEN" ]]; then
	ok "requesttoken ready"
else
	bad "cannot get a requesttoken - checks with write requests will be skipped"
fi

say "1. fetching $BASE/index.php/settings/admin/pages"
CODE=$(curl --noproxy '*' -sk -u "${NC_USER}:${PW}" -o "$PAGE" -D "$HDR" -w '%{http_code}' \
	"$BASE/index.php/settings/admin/pages")
if [[ "$CODE" == "200" ]]; then
	ok "HTTP 200 ($(wc -c < "$PAGE") bytes)"
else
	bad "HTTP $CODE - the admin page did not render"
	echo "     CSP / location headers:"
	grep -iE '^(HTTP/|location|content-security-policy)' "$HDR" | head -3 | sed 's/^/       /'
fi

say "1b. the one app page"
# There is one page now: the caller's own author area, the requests and the reading view. The shell no
# longer names a page; what the assertions check is that it renders, and that the removed page is gone.
code=$(curl --noproxy '*' -sk -b "$JAR" -u "${NC_USER}:${PW}" -H 'OCS-APIRequest: true' \
	-H "requesttoken: $TOKEN" -o /tmp/va-page.html -w '%{http_code}' "$BASE/index.php/apps/pages/")
state=$(python3 -c '
import json, re
html = open("/tmp/va-page.html", encoding="utf-8", errors="replace").read()
block = re.search(r"<script type=\"application/json\" id=\"pages-initial-state\">(.*?)</script>", html, re.S)
if block is None:
    print("no-state")
else:
    data = json.loads(block.group(1)) or {}
    print("author=%s review=%s page=%s" % (data.get("canAuthor"), data.get("canReview"), data.get("page", "-")))
' 2>/dev/null)
if [[ "$code" == "200" && "$state" == *"page=-"* ]]; then
	ok "GET / renders the shell (HTTP 200, the initial state names no page: $state)"
else
	bad "GET / did not render the shell (HTTP $code, state '$state')"
fi
anon=$(curl --noproxy '*' -sk -o /dev/null -w '%{http_code}' "$BASE/index.php/apps/pages/source")
if [[ "$anon" == "404" ]]; then
	ok "GET /source is gone (HTTP $anon: the site-source page was removed)"
else
	bad "GET /source answered HTTP $anon; the route should be removed"
fi

say "2. the page really is the Pages panel, with data rendered"
if grep -q 'pages-admin' "$PAGE"; then
	ok "pages-admin marker found (template rendered)"
else
	bad "no pages-admin marker - something else or an error page was rendered"
fi
# the whole Status section is gone: connection facts are the status line of block ①, and the two
# numbers that were in the table either moved (approved → Requests view) or were dropped
for id in pages-status pages-external-count pages-unsynced-count pages-sync-state pages-config-revision; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		bad "the removed Status row $id is still rendered"
	else
		ok "the removed Status row $id is gone"
	fi
done
# the shell alone is not enough: the site data and the secret fingerprint must render too
if grep -q 'id="pages-site-nc-path"' "$PAGE"; then
	ok "site form rendered (the site folder field is there)"
else
	bad "the site form did not render - site data did not reach the template"
fi
if grep -qE 'SHA256:[0-9a-f]{8,}' "$PAGE"; then
	ok "shared secret fingerprint rendered"
else
	bad "shared secret fingerprint not rendered"
fi

say "3. frontend assets and form controls are present"
if grep -q 'pages-admin\.js' "$PAGE"; then
	ok "js/pages-admin.js loaded"
else
	bad "js/pages-admin.js not loaded - the save button will do nothing"
fi
if grep -q 'pages-admin\.css' "$PAGE"; then
	ok "css/pages-admin.css loaded"
else
	bad "css/pages-admin.css not loaded"
fi
# the authoring block has to render too: a template error there would blank the whole settings page,
# and the author content directory and the orphan owner directory are its two controls. Who may author
# is a column of the Permissions matrix now, and the per-author CSS include was retired - so its button
# and feedback span must not come back, and the author-sources table was retired before that. The old
# "Author folder" input was replaced by the site directory the whole author tree is mirrored into.
for id in pages-authors pages-author-root pages-orphan-owner; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		ok "author form control $id present"
	else
		bad "author form control $id missing - the Authors section did not render"
	fi
done
if grep -q 'id="pages-author-area"' "$PAGE"; then
	bad "the retired author-folder input is still rendered"
else
	ok "the retired author-folder input is gone"
fi
for id in pages-community-include pages-community-feedback; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		bad "the retired per-author CSS control $id is still rendered"
	else
		ok "the retired per-author CSS control $id is gone"
	fi
done
if grep -q 'id="pages-author-sources"' "$PAGE"; then
	bad "the retired author-sources table is still rendered"
else
	ok "the retired author-sources table is gone (the endpoint stays, the UI does not)"
fi
if grep -q 'id="pages-author-sources-hint"' "$PAGE"; then
	bad "the retired author-sources hint is still rendered"
else
	ok "the retired author-sources hint is gone"
fi
# the bridge block is pairing-first: URL + one-time code while unpaired, the three paired actions
# afterwards. The two mandatory secret inputs are gone on purpose (pairing generates both secrets and
# rotation is a button), so no plaintext secret is ever typed on this page.
for id in pages-bridge-panel pages-bridge-url; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		ok "bridge form control $id present"
	else
		bad "missing bridge form control $id"
	fi
done
for id in pages-shared-secret pages-signing-secret; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		bad "the retired secret input $id is still rendered (pairing generates the secrets; the manual fallback is occ)"
	else
		ok "the retired secret input $id is gone"
	fi
done
BRIDGE_PAIRED=$(grep -oE 'id="pages-bridge-panel" data-paired="[01]"' "$PAGE" | grep -oE '[01]"$' | tr -d '"')
case "$BRIDGE_PAIRED" in
	1)
		ok "the panel rendered the paired state (data-paired=1)"
		for id in pages-rotate pages-pair-again; do
			if grep -q "id=\"$id\"" "$PAGE"; then
				ok "paired action $id present"
			else
				bad "missing paired action $id"
			fi
		done
		for id in pages-save pages-push pages-site-save; do
			if grep -q "id=\"$id\"" "$PAGE"; then
				bad "the retired save button $id is still rendered"
			else
				ok "the retired save button $id is gone (write-through saves)"
			fi
		done
		for id in pages-pair-code pages-pair; do
			if grep -q "id=\"$id\"" "$PAGE"; then
				bad "unpaired-only control $id is rendered although the bridge is paired"
			else
				ok "unpaired-only control $id is gone while paired"
			fi
		done
		;;
	0)
		ok "the panel rendered the unpaired state (data-paired=0)"
		for id in pages-pair-code pages-pair; do
			if grep -q "id=\"$id\"" "$PAGE"; then
				ok "pairing control $id present"
			else
				bad "missing pairing control $id"
			fi
		done
		;;
	*)
		bad "the bridge panel does not carry a readable data-paired state"
		;;
esac
# the old single "push" button is gone: pushing is part of saving now
if grep -q 'id="pages-push"' "$PAGE"; then
	bad "the retired pages-push button is still rendered"
else
	ok "the retired pages-push button is gone (saving pushes)"
fi
# single site: the form is the site storage user + folder + remote + branch. The build recipe and the
# site-level exclude list moved to the bridge TOML / `occ pages:config` (they duplicate what the bridge
# owns), so their inputs are gone and the save path keeps whatever is stored.
for id in pages-site-nc-user pages-site-nc-path pages-site-repo pages-site-branch pages-site-apply pages-site-sync pages-site-import pages-site-import-file; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		ok "site form control $id present"
	else
		bad "missing site form control $id"
	fi
done
# ONE Create/Pull button: no separate Initialise control any more, and the single control is in the
# template (its label switches on the remote field; the Pull label only renders with a remote)
if grep -q 'id="pages-site-init"' "$PAGE"; then
	bad "the retired Initialise button is still rendered (Create/Pull share one button)"
else
	ok "the separate Initialise button is gone (one Create/Pull button)"
fi
if grep -q 'id="pages-site-sync"' "$ROOT/templates/settings/admin.php" 2>/dev/null; then
	ok "the Create/Pull control exists in the template"
else
	bad "the Create/Pull control is missing from the settings template"
fi
for id in pages-site-exclude pages-site-command pages-site-install pages-site-output; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		bad "the removed site control $id is still rendered (the TOML / occ pages:config own it)"
	else
		ok "the removed site control $id is gone"
	fi
done
# the removed multi-site controls must really be gone
for id in pages-site-id pages-site-name pages-site-work; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		bad "removed form control $id is still rendered"
	else
		ok "removed form control $id is gone"
	fi
done
# the advanced block is gone entirely: the 16 global parameters live in the bridge TOML and in
# `occ pages:config get|set|list`, and `pages:doctor --json` reports their effective values
for marker in pages-site-advanced pages-bridge-global pages-cmd; do
	if grep -q "$marker" "$PAGE"; then
		bad "the retired advanced block marker $marker is still rendered"
	else
		ok "the retired advanced block marker $marker is gone"
	fi
done
for key in nc-data-root work-root state-dir override-dir log-dir build-timeout-s build-debounce-ms \
	log-tail-lines mirror-verify mirror-delete-guard-ratio mirror-delete-guard-min mirror-preserve-exec \
	mirror-use-gitignore exclude unexclude listen; do
	if grep -q "pages-bridge-$key" "$PAGE"; then
		bad "the global parameter input pages-bridge-$key is still on the page (it belongs to occ pages:config)"
	fi
done
ok "none of the 16 global parameter inputs is on the page (they are in the TOML / occ pages:config)"
# Two capabilities remain, one control each (authoring, and the merged 审核与发布 grant). The ids are
# the stable anchors: they are hidden inputs now (the Vue pickers write into them), the retired edit
# and publish grants must not come back.
for id in pages-permissions pages-author-groups pages-review-groups; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		ok "permission control $id present"
	else
		bad "missing permission control $id"
	fi
done
# one permission means 审核与发布: the picker's label has to say that it covers publishing, and the
# separate publish picker must be gone (publishing is part of this grant, not a separate one).
# The page renders in the administrator's language, so the label is compared against the source string
# AND its translation in the app's own catalog — that still asserts that the label *is* this string.
REVIEW_LABEL=$(python3 - "$PAGE" "$ROOT/l10n" <<'PY'
import json, os, re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
block = re.search(r'<script type="application/json" id="pages-grants-state">(.*?)</script>', html, re.S)
state = json.loads(block.group(1)) if block else {}
grant = next((g for g in (state.get('grants') or []) if g.get('id') == 'pages-review-groups'), None)
label = str((grant or {}).get('label') or '')
source = 'Review and publish'
candidates = {source}
catalog_dir = sys.argv[2]
for name in os.listdir(catalog_dir) if os.path.isdir(catalog_dir) else []:
    if not name.endswith('.json'):
        continue
    try:
        catalog = json.load(open(os.path.join(catalog_dir, name), encoding='utf-8')).get('translations') or {}
    except Exception:
        continue
    if source in catalog:
        candidates.add(str(catalog[source]))
print('%s|%s' % (label, '|'.join(sorted(candidates))))
PY
)
REVIEW_LABEL_TEXT="${REVIEW_LABEL%%|*}"
REVIEW_CANDIDATES="${REVIEW_LABEL#*|}"
if [[ -n "$REVIEW_LABEL_TEXT" ]] && [[ "|$REVIEW_CANDIDATES|" == *"|$REVIEW_LABEL_TEXT|"* ]]; then
	ok "the review picker is labelled “$REVIEW_LABEL_TEXT” — that is “Review and publish”, so it covers publishing"
else
	bad "the review picker's label ('$REVIEW_LABEL_TEXT') is not “Review and publish” (candidates: $REVIEW_CANDIDATES)"
fi
if grep -q 'id="pages-publish-groups"' "$PAGE"; then
	bad "a separate publish picker is still rendered (its grant is part of review now)"
else
	ok "the retired publish picker is gone"
fi
if grep -qE '<select[^>]*multiple' "$PAGE"; then
	bad "a native multi-select is still rendered (the pickers are supposed to replace them)"
else
	ok "the native multi-selects are gone (NcSelect renders client-side)"
fi
if grep -q 'id="pages-grants-state"' "$PAGE"; then
	ok "the picker state block is in the server HTML"
else
	bad "no pages-grants-state block - the pickers would start without options"
fi
if grep -q 'id="pages-edit-groups"' "$PAGE"; then
	bad "the retired edit column is still rendered"
else
	ok "the retired edit column is gone (its grant is part of review, which carries publishing)"
fi

say "3a. the page carries the four blocks, a facts-only summary and the danger zone"
# The page owns nine fields: the URL, the pairing code, the four site fields, the author content
# directory, the orphan owner directory and the archive picker. A "field control" is what a browser
# renders as an editable input - an `<input>` that is not type=hidden, or a `<select>`; the grant
# pickers write into hidden inputs and the settings shell around this page belongs to Nextcloud.

CONTROLS=$(python3 - "$PAGE" <<'PY'
import re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
live = re.sub(r'<!--[\s\S]*?-->', '', html)
ids = []
for tag in re.findall(r'<input\b[^>]*>', live):
    if 'type="hidden"' in tag:
        continue
    found = re.search(r'id="([^"]+)"', tag)
    if found and found.group(1).startswith('pages-'):
        ids.append(found.group(1))
selects = [tag for tag in re.findall(r'<select\b[^>]*>', live) if 'id="pages-' in tag]
print('%d|%s' % (len(ids) + len(selects), ','.join(ids)))
PY
)
CONTROL_COUNT="${CONTROLS%%|*}"
CONTROL_IDS="${CONTROLS#*|}"
# the pairing code only exists while the bridge is unpaired, so the rendered count is 8 or 9
if [[ "$BRIDGE_PAIRED" == "1" ]]; then
	EXPECTED_CONTROLS=8
else
	EXPECTED_CONTROLS=9
fi
if [[ "$CONTROL_COUNT" == "$EXPECTED_CONTROLS" ]]; then
	ok "the page renders $CONTROL_COUNT field control(s), one per setting: $CONTROL_IDS"
else
	bad "the page renders '$CONTROL_COUNT' field control(s), expected $EXPECTED_CONTROLS: $CONTROL_IDS"
fi
# the facts-only summary under the data operations
for id in pages-site-summary pages-site-head pages-site-ahead; do
	if grep -q "id=\"$id\"" "$PAGE"; then
		ok "summary marker $id present"
	else
		bad "missing summary marker $id"
	fi
done
# the §14 migration is gone: moving the source while content exists is locked, not migrated
for marker in pages-site-migration-template pages-site-migration; do
	if grep -q "id=\"$marker\"" "$PAGE"; then
		bad "the retired §14 migration marker $marker is still rendered"
	else
		ok "the retired §14 migration marker $marker is gone"
	fi
done
LIVE_RADIOS=$(python3 - "$PAGE" <<'PY'
import re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
print(len(re.findall(r'<input[^>]*type="radio"', html)))
PY
)
if [[ "$LIVE_RADIOS" == "0" ]]; then
	ok "no radio group on the page (the migration choices are gone)"
else
	bad "$LIVE_RADIOS radio input(s) rendered although the migration flow was removed"
fi
# the danger zone: the reset button and the explanation that the user's own files are never touched
# (the bridge's two derived trees are deleted outright, not set aside)
if grep -q 'id="pages-reset"' "$PAGE"; then
	ok "the danger zone renders its reset button"
else
	bad "the danger zone reset button is missing"
fi
if grep -q 'never touched' "$PAGE"; then
	ok "the reset explanation says user files are never touched"
else
	bad "the reset explanation does not say user files are never touched"
fi
# lock rendering: the three layout fields are disabled exactly when the server says content exists.
# Both directions are asserted, so the page cannot silently stop locking (or lock on an empty site).
LOCK=$(python3 - "$PAGE" <<'PY'
import re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
def tag(i):
    m = re.search(r'<input\b[^>]*id="%s"[^>]*>' % i, html)
    return m.group(0) if m else ''
inputs = {i: tag(i) for i in ('pages-site-nc-user', 'pages-site-nc-path')}
disabled = {i: 'disabled' in t for i, t in inputs.items()}
hints = 'id="pages-site-lock-hint"' in html and 'id="pages-author-lock-hint"' not in html
sentence = 'Locked: content exists. Reset Pages to change this.' in html
print('%s|%s|%s|%s' % (all(disabled.values()), any(disabled.values()), hints, sentence))
PY
)
LOCKED="${LOCK%%|*}"
REST="${LOCK#*|}"
MIXED="${REST%%|*}"
REST="${REST#*|}"
HINTS="${REST%%|*}"
REST="${REST#*|}"
SENTENCE="$REST"
case "$LOCKED/$MIXED/$HINTS" in
	True/True/True)
		ok "content exists: both layout fields render disabled, with the lock hint"
		;;
	False/False/False)
		ok "no content: the two layout fields are editable and no lock hint is rendered"
		;;
	*)
		bad "the lock rendering is inconsistent (all-disabled=$LOCKED, any-disabled=$MIXED, hints=$HINTS)"
		;;
esac
# the author content directory is outside the content lock in every state: enabled, no hint, and its
# own channel is what enforces the bridge's rule
AUTHOR_FIELD=$(python3 - "$PAGE" <<'PY'
import re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
m = re.search(r'<input\b[^>]*id="pages-author-root"[^>]*>', html)
print('%s|%s' % ('True' if m and 'disabled' not in m.group(0) else 'False',
                 'True' if 'id="pages-author-lock-hint"' in html else 'False'))
PY
)
IFS='|' read -r AUTHOR_ENABLED AUTHOR_HINT <<< "$AUTHOR_FIELD"
if [[ "$AUTHOR_ENABLED" == "True" && "$AUTHOR_HINT" == "False" ]]; then
	ok "the author content directory is always editable and carries no lock hint"
else
	bad "the author content directory is locked or hinted (enabled=$AUTHOR_ENABLED, hint=$AUTHOR_HINT)"
fi
# the orphan owner directory sits in the same block and follows the same rule: it is a plain,
# editable input and its stored value reaches the page (the shipped default is `Admin`, and a
# deployment that changed it is still non-empty because the backend never stores an empty name)
ORPHAN_FIELD=$(python3 - "$PAGE" <<'PY'
import re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
m = re.search(r'<input\b[^>]*id="pages-orphan-owner"[^>]*>', html)
value = ''
if m:
    found = re.search(r'value="([^"]*)"', m.group(0))
    value = found.group(1) if found else ''
print('%s|%s' % ('True' if m and 'disabled' not in m.group(0) else 'False', value))
PY
)
IFS='|' read -r ORPHAN_ENABLED ORPHAN_VALUE <<< "$ORPHAN_FIELD"
if [[ "$ORPHAN_ENABLED" == "True" && -n "$ORPHAN_VALUE" ]]; then
	ok "the orphan owner directory renders its value and is editable ($ORPHAN_VALUE; default Admin)"
else
	bad "the orphan owner directory is locked or empty (enabled=$ORPHAN_ENABLED, value='$ORPHAN_VALUE')"
fi
if [[ "$HINTS" == "True" && "$SENTENCE" != "True" ]]; then
	# the page renders in the administrator's language, so a translated hint is the correct state once
	# the catalog has the string; the source of the sentence itself is asserted below instead
	HINT_TEXT=$(python3 - "$PAGE" <<'PY'
import re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
m = re.search(r'id="pages-site-lock-hint"[^>]*>(.*?)</p>', html, re.S)
print(' '.join((m.group(1) if m else '').split())[:120])
PY
)
	if [[ -n "$HINT_TEXT" ]]; then
		ok "the lock hint renders its sentence: “${HINT_TEXT}…”"
	else
		bad "the lock hint is rendered but empty"
	fi
elif [[ "$HINTS" == "True" ]]; then
	ok "the lock hint carries the one sentence about resetting Pages"
fi
if grep -q "Locked: content exists. Reset Pages to change this." "$ROOT/lib/Service/ContentLock.php" 2>/dev/null; then
	ok "the one lock sentence lives in ContentLock (the settings page and the API share it)"
else
	bad "the lock sentence is not the ContentLock constant"
fi
# the remote/branch lock is a second, independent lock (the publish repository exists): when the page
# says it is initialised, both inputs are disabled, the hint is rendered, and the single button reads
# Pull (a remote is configured). Without a repository the button reads Create and nothing is locked.
REMOTE_LOCK=$(python3 - "$PAGE" "$ROOT/templates/settings/admin.php" <<'PY'
import re, sys
html = open(sys.argv[1], encoding='utf-8', errors='replace').read()
tpl = open(sys.argv[2], encoding='utf-8', errors='replace').read()
def tag(i):
    m = re.search(r'<input\b[^>]*id="%s"[^>]*>' % i, html)
    return m.group(0) if m else ''
initialized = 'data-initialized="1"' in html
disabled = all('disabled' in tag(i) for i in ('pages-site-repo', 'pages-site-branch'))
hint = 'id="pages-remote-lock-hint"' in html
m = re.search(r'id="pages-site-sync"[^>]*>([^<]*)<', html)
label = (m.group(1).strip() if m else '')
tpl_hint = 'pages-remote-lock-hint' in tpl
hidden_without_remote = '!$initialized || $hasRemote' in tpl
print('%s|%s|%s|%s|%s|%s' % (initialized, disabled, hint, label, tpl_hint, hidden_without_remote))
PY
)
IFS='|' read -r RL_INIT RL_DISABLED RL_HINT RL_LABEL RL_TPLHINT RL_HIDDEN <<< "$REMOTE_LOCK"
if [[ "$RL_INIT" == "True" ]]; then
	if [[ "$RL_DISABLED" == "True" && "$RL_HINT" == "True" && "$RL_LABEL" == "Pull" && "$RL_TPLHINT" == "True" ]]; then
		ok "initialised: the remote and the branch render disabled with their own lock hint, and the single button reads Pull"
	else
		bad "the initialised rendering is wrong (disabled=$RL_DISABLED, hint=$RL_HINT, label='$RL_LABEL')"
	fi
else
	if [[ "$RL_DISABLED" == "False" && "$RL_HINT" == "False" && "$RL_LABEL" == "Create" && "$RL_HIDDEN" == "True" ]]; then
		ok "not initialised: the remote/branch are editable, the hint is absent and the button reads Create"
	else
		bad "the uninitialised rendering is wrong (disabled=$RL_DISABLED, hint=$RL_HINT, label='$RL_LABEL')"
	fi
fi

say "3b. the app menu entry is usable (a link and a loadable icon)"
# A runtime INavigationManager entry is stored exactly as handed over: Nextcloud only expands a
# `route` and a bare icon file name for entries declared in info.xml. Passing them unresolved gives
# a menu tile with no icon that cannot be clicked - and a check that only looks for the id sees
# nothing wrong.
ENTRY=$(curl --noproxy '*' -sk -b "$JAR" -u "${NC_USER}:${PW}" -H 'OCS-APIREQUEST: true' -H 'Accept: application/json' \
	"$BASE/ocs/v2.php/core/navigation/apps?format=json" | python3 -c '
import json, sys
data = json.load(sys.stdin).get("ocs", {}).get("data", [])
print(json.dumps(next((e for e in data if e.get("id") == "pages"), {})))
' 2>/dev/null || echo '{}')
HREF=$(printf '%s' "$ENTRY" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("href") or "")' 2>/dev/null || echo '')
ICON=$(printf '%s' "$ENTRY" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("icon") or "")' 2>/dev/null || echo '')
if [[ -n "$HREF" ]]; then
	ok "the Pages entry carries a link ($HREF)"
else
	bad "the Pages entry has no href - the menu tile cannot be clicked"
fi
if [[ "$ICON" == /* ]]; then
	ok "the Pages entry carries a resolved icon URL ($ICON)"
else
	bad "the Pages icon is not a resolved URL (got '$ICON') - the menu shows a blank tile"
fi
if [[ "$ICON" == /* ]]; then
	ICON_CODE=$(curl --noproxy '*' -sk -b "$JAR" -u "${NC_USER}:${PW}" -o /dev/null -w '%{http_code}' "$BASE$ICON")
	if [[ "$ICON_CODE" == "200" ]]; then
		ok "the icon really loads (HTTP 200)"
	else
		bad "the icon URL answers HTTP $ICON_CODE"
	fi
fi

say "4. save path (PUT /api/admin/config)"
if [[ -z "$TOKEN" ]]; then
	echo "  (no requesttoken, skipped)"
else
	api() { # api <METHOD> <PATH> [JSON] → stdout=body, status code via -w on stdout
		local method="$1" path="$2" body="${3:-}"
		local args=(-X "$method" -H "requesttoken: $TOKEN" -H 'OCS-APIREQUEST: true'
			-H 'Content-Type: application/json' -b "$JAR" -c "$JAR" -o "$BODY" -w '%{http_code}')
		[[ -n "$body" ]] && args+=(--data "$body")
		curl --noproxy '*' -sk "${args[@]}" "$BASE/index.php/apps/pages$path"
	}

	CODE_GET=$(api GET /api/admin/config)
	if [[ "$CODE_GET" == "200" ]] && grep -q '"shared_fingerprint"' "$BODY"; then
		ok "GET returns the config (with fingerprint)"
	else
		bad "GET /api/admin/config failed (HTTP $CODE_GET)"
	fi
	BEFORE_FP=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"]["shared_fingerprint"])' 2>/dev/null || echo '')
	BEFORE_URL=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"]["bridge_url"])' 2>/dev/null || echo '')

	# ① empty secret + same URL → must succeed and keep the fingerprint (empty = do not modify)
	CODE_PUT=$(api PUT /api/admin/config "$(python3 -c '
import json, sys
print(json.dumps({"bridgeUrl": sys.argv[1], "sharedSecret": "", "signingSecret": ""}))
' "$BEFORE_URL")")
	AFTER_FP=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"]["shared_fingerprint"])' 2>/dev/null || echo '')
	if [[ "$CODE_PUT" == "200" && "$AFTER_FP" == "$BEFORE_FP" && -n "$BEFORE_FP" ]]; then
		ok "empty secret keeps the key (fingerprint unchanged: ${BEFORE_FP:0:16}…)"
	else
		bad "wrong empty-secret semantics (HTTP $CODE_PUT, fingerprint $BEFORE_FP → $AFTER_FP) - one save must not destroy the config"
	fi

	# ② invalid URL → 400
	CODE_BAD=$(api PUT /api/admin/config '{"bridgeUrl":"ftp://nope"}')
	if [[ "$CODE_BAD" == "400" ]]; then
		ok "invalid bridge URL rejected (HTTP 400)"
	else
		bad "invalid URL was not rejected (HTTP $CODE_BAD)"
	fi

	# ③ change the URL and change it back → the config is writable
	CODE_TMP=$(api PUT /api/admin/config '{"bridgeUrl":"http://127.0.0.1:18787"}')
	TMP_URL=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"]["bridge_url"])' 2>/dev/null || echo '')
	api PUT /api/admin/config "$(python3 -c '
import json, sys
print(json.dumps({"bridgeUrl": sys.argv[1]}))
' "$BEFORE_URL")" > /dev/null
	BACK_URL=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"]["bridge_url"])' 2>/dev/null || echo '')
	if [[ "$CODE_TMP" == "200" && "$TMP_URL" == "http://127.0.0.1:18787" && "$BACK_URL" == "$BEFORE_URL" ]]; then
		ok "URL writable and restored ($BACK_URL)"
	else
		bad "URL write/restore wrong (HTTP $CODE_TMP, $TMP_URL → $BACK_URL, expected to restore $BEFORE_URL)"
	fi

	# ④ the two admin-only site actions exist and really run
	#
	# There is no site id any more, so "make up a site id and expect 404" cannot work: /api/site/init
	# and /api/site/pull are admin-gated routes with a fixed path, and a wrong path would answer 404.
	# So the probe asserts what the permission matrix (§3.4: init/pull = admin only) implies for an
	# admin caller: the route is registered and the controller answers — HTTP 200 when the bridge
	# does the work, or a 5xx when it cannot (no work copy / bridge down). Anything else means the
	# route is gone (404) or the guard is wrong (401/403 for an admin), and both must fail loudly.
	for action in init pull; do
		CODE_ACTION=$(api POST "/api/site/$action")
		case "$CODE_ACTION" in
			200|409|412|500|502|503)
				ok "POST /api/site/$action exists and is routed to the controller (HTTP $CODE_ACTION)" ;;
			*)
				bad "POST /api/site/$action answers HTTP $CODE_ACTION - expected the route to exist (404 = gone, 401/403 = wrong guard for an admin)" ;;
		esac
	done
	# ⑤ the site config round-trips (read it out, write it back unchanged)
	api GET /api/site > /dev/null
	SITE_PATH=$(python3 -c 'import json;print((json.load(open("/tmp/va-admin.json")).get("site") or {}).get("nc_path",""))')
	if [[ -n "$SITE_PATH" ]]; then
		ROUND=$(python3 -c '
import json
d = json.load(open("/tmp/va-admin.json"))
s = d.get("site") or {}
# Only the keys the settings form owns: no id, no name, no work_path any more (single site).
# `remote_name` and everything else the form does not edit stay whatever the backend keeps.
print(json.dumps({k: s.get(k) for k in ("nc_user","nc_path","repo_url","branch","recipe")}, ensure_ascii=False))
')
		CODE_SITE=$(api PUT /api/site "$ROUND")
		AFTER_REPO=$(python3 -c 'import json;print((json.load(open("/tmp/va-admin.json")).get("site") or {}).get("repo_url",""))' 2>/dev/null || echo '')
		if [[ "$CODE_SITE" == "200" && "$AFTER_REPO" == "$(python3 -c 'import json,sys;print(json.loads(sys.argv[1]).get("repo_url",""))' "$ROUND")" ]]; then
			ok "site config round-trips ($SITE_PATH → ${AFTER_REPO:0:40}…) without id/name/work_path"
		else
			bad "site config round-trip failed (HTTP $CODE_SITE)"
		fi
	else
		bad "cannot read the site folder, skipping the site config round-trip"
	fi

	# ⑤b lock: while content exists, the two fields that name it are rejected by the API as well as
	# disabled in the page (the page rendering is checked in 3a; this is the server-side half).
	if [[ "$MIXED" == "True" ]]; then
		CHANGED=$(python3 -c '
import json, sys
s = json.loads(sys.argv[1])
s["nc_path"] = "/Sites/pages-va-locked"
print(json.dumps(s, ensure_ascii=False))
' "$ROUND")
		CODE_LOCK=$(api PUT /api/site "$CHANGED")
		LOCK_MSG=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json")).get("message",""))' 2>/dev/null || echo '')
		api GET /api/site > /dev/null
		AFTER_LOCK=$(python3 -c 'import json;print((json.load(open("/tmp/va-admin.json")).get("site") or {}).get("nc_path",""))')
		if [[ "$CODE_LOCK" == "400" && "$AFTER_LOCK" == "$SITE_PATH" ]]; then
			ok "PUT /api/site rejects a folder change while content exists (400: ${LOCK_MSG:0:80}…)"
		else
			bad "a locked folder change was not rejected (HTTP $CODE_LOCK, path $AFTER_LOCK)"
		fi
	# the author content directory is NOT app-locked any more: its own channel accepts the current
	# value (idempotent), and a real move is accepted too - it carries every published and waiting
	# file under the old name to the new one, so the move is not what this check pins down.
	api GET /api/admin/config > /dev/null
	ROOT_NOW=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"].get("author_root",""))')
	CODE_ROOT=$(api POST /api/site/author-root "$(python3 -c '
import json, sys
print(json.dumps({"authorRoot": sys.argv[1]}))
' "$ROOT_NOW")")
	ROOT_MSG=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json")).get("message",""))' 2>/dev/null || echo '')
	api GET /api/admin/config > /dev/null
	AFTER_ROOT=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"].get("author_root",""))')
	if [[ "$CODE_ROOT" == "200" && "$AFTER_ROOT" == "$ROOT_NOW" ]]; then
		ok "POST /api/site/author-root accepts the current value (idempotent; the field is not app-locked)"
	else
		bad "the author-content channel refused its own current value (HTTP $CODE_ROOT: ${ROOT_MSG:0:80})"
	fi
	# the confirm-on-existing flow has to live in the client: 409 CONFIRM_REQUIRED -> dialog -> retry
	if grep -q "CONFIRM_REQUIRED" "$ROOT/src/admin.js" && grep -q "post(true)" "$ROOT/src/admin.js"; then
		ok "the author-directory client handles CONFIRM_REQUIRED and retries with confirm:true"
	else
		bad "the author-directory confirm flow is missing from src/admin.js"
	fi
		# the lock must not block the fields that do not name content
		CODE_BRANCH=$(api PUT /api/site "$ROUND")
		if [[ "$CODE_BRANCH" == "200" ]]; then
			ok "a locked site still accepts the fields that do not name content (branch/remote)"
		else
			bad "a locked site refused an unchanged round-trip (HTTP $CODE_BRANCH)"
		fi
	else
		ok "no content on this instance, so the lock checks are skipped (nothing to protect)"
	fi

	# ⑤b-bis the remote and the branch are locked once the repository is initialised (the bridge
	# derives that from its working copy). A change is rejected by the API, an unchanged round-trip is
	# accepted above, and the page renders both inputs disabled (checked in section 3a).
	api GET /api/site/status > /dev/null
	INITIALIZED=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json")).get("initialized"))' 2>/dev/null || echo 'False')
	if [[ "$INITIALIZED" == "True" ]]; then
		api GET /api/site > /dev/null
		SITE_ROUND=$(cat /tmp/va-admin.json)
		CHANGED_REMOTE=$(python3 -c '
import json, sys
s = json.loads(sys.argv[1]).get("site") or {}
s["repo_url"] = "git@example.invalid:va/other.git"
print(json.dumps(s, ensure_ascii=False))
' "$SITE_ROUND")
		CODE_REMOTE=$(api PUT /api/site "$CHANGED_REMOTE")
		REMOTE_MSG=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json")).get("message",""))' 2>/dev/null || echo '')
		api GET /api/site > /dev/null
		AFTER_REMOTE=$(python3 -c 'import json;print((json.load(open("/tmp/va-admin.json")).get("site") or {}).get("repo_url",""))')
		CHANGED_BRANCH=$(python3 -c '
import json, sys
s = json.loads(sys.argv[1]).get("site") or {}
s["branch"] = "va-locked-branch"
print(json.dumps(s, ensure_ascii=False))
' "$SITE_ROUND")
		CODE_BRANCH_LOCK=$(api PUT /api/site "$CHANGED_BRANCH")
		api GET /api/site > /dev/null
		AFTER_BRANCH=$(python3 -c 'import json;print((json.load(open("/tmp/va-admin.json")).get("site") or {}).get("branch",""))')
		if [[ "$CODE_REMOTE" == "400" && "$AFTER_REMOTE" != "git@example.invalid:va/other.git" \
			&& "$CODE_BRANCH_LOCK" == "400" && "$AFTER_BRANCH" != "va-locked-branch" \
			&& "$REMOTE_MSG" == *initialised* ]]; then
			ok "PUT /api/site rejects a remote/branch change once the repository is initialised (400: ${REMOTE_MSG:0:70}…)"
		else
			bad "the initialised lock is wrong (remote HTTP $CODE_REMOTE, branch HTTP $CODE_BRANCH_LOCK, msg '${REMOTE_MSG:0:70}', remote '$AFTER_REMOTE', branch '$AFTER_BRANCH')"
		fi
	else
		ok "no publish repository on this instance, so the remote/branch lock check is skipped"
	fi

	# ⑤c the reset route: admin only, password-confirmed, and a wrong password runs nothing
	CODE_NOPW=$(api POST /api/admin/reset '{}')
	CODE_BADPW=$(api POST /api/admin/reset '{"password":"definitely-not-the-password"}')
	MISSING_PW_MSG=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json")).get("message",""))' 2>/dev/null || echo '')
	api GET /api/site > /dev/null
	AFTER_RESET_TRY=$(python3 -c 'import json;print((json.load(open("/tmp/va-admin.json")).get("site") or {}).get("nc_path",""))')
	if [[ "$CODE_NOPW" == "401" && "$CODE_BADPW" == "401" && "$AFTER_RESET_TRY" == "$SITE_PATH" ]]; then
		ok "POST /api/admin/reset is refused without/with a wrong password (401, nothing ran: ${MISSING_PW_MSG:0:60})"
	else
		bad "the reset password gate is wrong (no-password HTTP $CODE_NOPW, bad-password HTTP $CODE_BADPW, site '$AFTER_RESET_TRY')"
	fi

	# ⑤d the orphan owner directory: one site-relative folder name, saved through the same admin config
	# endpoint and never through the bridge (the name only picks a directory inside the site source).
	# It round-trips, and an existing uid is refused: the mirror excludes real users' directories, so a
	# handover into one would never reach the site.
	api GET /api/admin/config > /dev/null
	ORPHAN_BEFORE=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"].get("orphan_owner",""))' 2>/dev/null || echo '')
	ORPHAN_PROBE="pages-orphan-$(date +%s)"
	CODE_ORPHAN_SET=$(api PUT /api/admin/config "$(python3 -c '
import json, sys
print(json.dumps({"orphanOwner": sys.argv[1]}))
' "$ORPHAN_PROBE")")
	ORPHAN_SET=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"].get("orphan_owner",""))' 2>/dev/null || echo '')
	api GET /api/admin/config > /dev/null
	ORPHAN_STORED=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"].get("orphan_owner",""))' 2>/dev/null || echo '')
	if [[ "$CODE_ORPHAN_SET" == "200" && "$ORPHAN_SET" == "$ORPHAN_PROBE" && "$ORPHAN_STORED" == "$ORPHAN_PROBE" ]]; then
		ok "the orphan owner directory round-trips ($ORPHAN_BEFORE → $ORPHAN_PROBE) and persists"
	else
		bad "the orphan owner directory did not round-trip (HTTP $CODE_ORPHAN_SET, PUT returned '$ORPHAN_SET', GET '$ORPHAN_STORED', expected '$ORPHAN_PROBE')"
	fi
	# restore before the negative case, so a refused write provably leaves the administrator's own value
	CODE_ORPHAN_BACK=$(api PUT /api/admin/config "$(python3 -c '
import json, sys
print(json.dumps({"orphanOwner": sys.argv[1]}))
' "$ORPHAN_BEFORE")")
	api GET /api/admin/config > /dev/null
	ORPHAN_BACK=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"].get("orphan_owner",""))' 2>/dev/null || echo '')
	if [[ "$CODE_ORPHAN_BACK" == "200" && "$ORPHAN_BACK" == "$ORPHAN_BEFORE" ]]; then
		ok "the orphan owner directory is restored ($ORPHAN_BACK)"
	else
		bad "the orphan owner directory was not restored (HTTP $CODE_ORPHAN_BACK, now '$ORPHAN_BACK', expected '$ORPHAN_BEFORE')"
	fi
	# negative: a real account is not a folder the site can own. The message is asserted in English,
	# which is what this suite assumes (an English instance; the strings come from the app's l10n); the
	# error code is asserted too, so the check still holds if the instance is translated.
	CODE_ORPHAN_USER=$(api PUT /api/admin/config "$(python3 -c '
import json, sys
print(json.dumps({"orphanOwner": sys.argv[1]}))
' "$NC_USER")")
	ORPHAN_MSG=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json")).get("message",""))' 2>/dev/null || echo '')
	ORPHAN_ERR=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json")).get("error",""))' 2>/dev/null || echo '')
	api GET /api/admin/config > /dev/null
	ORPHAN_AFTER_BAD=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json"))["config"].get("orphan_owner",""))' 2>/dev/null || echo '')
	if [[ "$CODE_ORPHAN_USER" == "400" && "$ORPHAN_ERR" == "BAD_REQUEST" \
		&& "$ORPHAN_MSG" == *"must not be an existing user"* && "$ORPHAN_AFTER_BAD" == "$ORPHAN_BEFORE" ]]; then
		ok "a real user as orphan owner is refused and changes nothing (400: ${ORPHAN_MSG:0:70}…)"
	else
		bad "a real user as orphan owner was not refused (HTTP $CODE_ORPHAN_USER, error '$ORPHAN_ERR', msg '${ORPHAN_MSG:0:80}', stored '$ORPHAN_AFTER_BAD')"
	fi
	# the last write is always the original value: the script runs without `set -e`, so this line runs
	# after every check above and corrects a probe or a restore that failed mid-way (the assertions
	# above have already counted that failure)
	if [[ -n "$ORPHAN_BEFORE" ]]; then
		api PUT /api/admin/config "$(python3 -c '
import json, sys
print(json.dumps({"orphanOwner": sys.argv[1]}))
' "$ORPHAN_BEFORE")" > /dev/null
	fi

	# ⑥ the two capability lists round-trip, and the retired publish grant changes nothing
	VA_GROUP="pages-va-group"
	api GET /api/admin/config > /dev/null
	GRANTS_BEFORE=$(python3 -c '
import json
c = json.load(open("/tmp/va-admin.json"))["config"]
print(json.dumps({
    "authorGroups": c.get("author_groups") or [],
    "reviewGroups": c.get("review_groups") or [],
}))
')
	occ group:add "$VA_GROUP" >/dev/null 2>&1 || true
	CODE_GRANT=$(api PUT /api/admin/config "$(python3 -c '
import json, sys
print(json.dumps({"reviewGroups": [sys.argv[1]]}))
' "$VA_GROUP")")
	READ_REVIEW=$(python3 -c 'import json;print(",".join(json.load(open("/tmp/va-admin.json"))["config"].get("review_groups") or []))')
	READ_PUB=$(python3 -c 'import json;print(",".join(json.load(open("/tmp/va-admin.json"))["config"].get("publish_groups") or []))')
	if [[ "$CODE_GRANT" == "200" && "$READ_REVIEW" == "$VA_GROUP" && -z "$READ_PUB" ]]; then
		ok "review grant round-trips and carries publishing ($VA_GROUP; publish_groups stays empty)"
	else
		bad "review grant round-trip wrong (HTTP $CODE_GRANT, review=$READ_REVIEW publish=$READ_PUB)"
	fi
	# a body that still posts the retired key (a cached admin page) must be ignored, not stored
	CODE_RETIRED=$(api PUT /api/admin/config "$(python3 -c '
import json, sys
print(json.dumps({"publishGroups": [sys.argv[1]]}))
' "$VA_GROUP")")
	api GET /api/admin/config > /dev/null
	READ_REVIEW2=$(python3 -c 'import json;print(",".join(json.load(open("/tmp/va-admin.json"))["config"].get("review_groups") or []))')
	READ_PUB2=$(python3 -c 'import json;print(",".join(json.load(open("/tmp/va-admin.json"))["config"].get("publish_groups") or []))')
	if [[ "$CODE_RETIRED" == "200" && "$READ_REVIEW2" == "$READ_REVIEW" && -z "$READ_PUB2" ]]; then
		ok "the retired publishGroups key is accepted and ignored (review unchanged, publish_groups empty)"
	else
		bad "the retired publishGroups key changed something (HTTP $CODE_RETIRED, review=$READ_REVIEW2 publish=$READ_PUB2)"
	fi
	# the authoring list is the third independent one; an empty list means "every signed-in user"
	CODE_AUTHOR=$(api PUT /api/admin/config "$(python3 -c '
import json, sys
print(json.dumps({"authorGroups": [sys.argv[1]]}))
' "$VA_GROUP")")
	READ_AUTHOR=$(python3 -c 'import json;print(",".join(json.load(open("/tmp/va-admin.json"))["config"].get("author_groups") or []))')
	if [[ "$CODE_AUTHOR" == "200" && "$READ_AUTHOR" == "$VA_GROUP" ]]; then
		ok "author grant round-trips independently ($VA_GROUP)"
	else
		bad "author grant round-trip wrong (HTTP $CODE_AUTHOR, author=$READ_AUTHOR)"
	fi
	# a group that does not exist is refused loudly (400 naming it) and changes nothing - the plan's
	# "no dead grant is stored" is enforced at the door rather than by silently dropping the entry
	api PUT /api/admin/config "$GRANTS_BEFORE" > /dev/null
	CODE_GHOST=$(api PUT /api/admin/config '{"reviewGroups":["pages-no-such-group"]}')
	GHOST_MSG=$(python3 -c 'import json;print(json.load(open("/tmp/va-admin.json")).get("message",""))' 2>/dev/null || echo '')
	api GET /api/admin/config > /dev/null
	READ_GHOST=$(python3 -c 'import json;print(",".join(json.load(open("/tmp/va-admin.json"))["config"].get("review_groups") or []))' 2>/dev/null || echo '?')
	if [[ "$CODE_GHOST" == "400" && -n "$GHOST_MSG" ]]; then
		ok "a group that does not exist is refused with 400 ($GHOST_MSG)"
	else
		bad "a group that does not exist was not refused (HTTP $CODE_GHOST)"
	fi
	if [[ "$READ_GHOST" == "$(python3 -c 'import json,sys;print(",".join(json.loads(sys.argv[1]).get("reviewGroups") or []))' "$GRANTS_BEFORE")" ]]; then
		ok "the refused request left the stored grants untouched"
	else
		bad "the refused request changed the stored grants ('$READ_GHOST')"
	fi
	# with a real group on the instance both grant controls must render in the server HTML, as the
	# hidden inputs the pickers write into — JavaScript never runs in this suite, so the markup is the
	# only thing it can check
	curl --noproxy '*' -sk -c "$JAR" -u "${NC_USER}:${PW}" -o "$PAGE" "$BASE/index.php/settings/admin/pages"
	for id in pages-author-groups pages-review-groups; do
		if grep -qE "<input[^>]*class=\"pages-grant-value\"[^>]*id=\"$id\"" "$PAGE"; then
			ok "grant control $id renders as a hidden input"
		else
			bad "grant control $id is missing from the server HTML although a group exists"
		fi
	done
	if grep -q 'id="pages-publish-groups"' "$PAGE"; then
		bad "the retired publish control is still rendered for a real group"
	else
		ok "the retired publish control is gone with the grant"
	fi
	# the pickers read their option list out of this block; without it they would render empty
	if grep -q 'id="pages-grants-state"' "$PAGE"; then
		ok "the picker state block renders for a real group"
	else
		bad "no pages-grants-state block although a group exists"
	fi
	if grep -q 'pages-edit-checkbox' "$PAGE"; then
		bad "the retired edit checkbox is still rendered"
	else
		ok "the retired edit checkbox is gone"
	fi
	api PUT /api/admin/config "$GRANTS_BEFORE" > /dev/null
	occ group:delete "$VA_GROUP" >/dev/null 2>&1 || true
	if [[ "$(occ group:list 2>/dev/null | grep -c "$VA_GROUP")" == "0" ]]; then
		ok "the throwaway group is gone again (no dead grant left behind)"
	else
		bad "the throwaway group survived - doctor will report a dead grant"
	fi
fi

say "5. the author space as the administrator sees it"
# The content page's column is the author's own file tree, and the grant that shows it travels in the
# shell state as `canAuthor` - an administrator holds it, so the state has to carry it as a boolean.
CAN_AUTHOR=$(python3 -c '
import json, re
html = open("/tmp/va-page.html", encoding="utf-8", errors="replace").read()
block = re.search(r"<script type=\"application/json\" id=\"pages-initial-state\">(.*?)</script>", html, re.S)
if block is None:
    print("no-state")
else:
    state = json.loads(block.group(1)) or {}
    print("%s|%s" % (state.get("canAuthor"), state.get("canReview")))
' 2>/dev/null)
if [[ "$CAN_AUTHOR" == "True|"* || "$CAN_AUTHOR" == "True|True" || "$CAN_AUTHOR" == "True|False" ]]; then
	ok "the shell state carries the authoring grant as a boolean ($CAN_AUTHOR)"
else
	bad "the shell state's canAuthor is '$CAN_AUTHOR' (expected True|…)"
fi
# The doctor has to report where the site reads the mirror from: the author space is only visible when
# the site's `authors.source` and the app's author content directory name the same folder.
# A manifest written by an older version may still carry a state of the retired model. The migration
# that mapped those states is gone, so a leftover would never be rewritten: assert there is none.
MANIFEST_FILE=$(ls -d /var/snap/nextcloud/common/nextcloud/data/appdata_*/pages 2>/dev/null | head -1)/review.json
if [[ -f "$MANIFEST_FILE" ]]; then
	OLD_STATES=$(python3 -c '
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except Exception:
    print("UNREADABLE")
    raise SystemExit(0)
old = {"draft", "submitted", "approved", "published", "rejected", "offline"}
left = sorted({e.get("state") for e in (d.get("entries") or {}).values() if isinstance(e, dict)} & old)
print(",".join(left) if left else "none")
' "$MANIFEST_FILE")
	if [[ "$OLD_STATES" == "none" ]]; then
		ok "the manifest carries no state of the retired model"
	else
		bad "the manifest still carries retired state(s): $OLD_STATES"
	fi
else
	ok "no manifest yet - there is nothing to check (every page is internal)"
fi
DOCTOR_OUT=/tmp/va-doctor.txt
occ pages:doctor > "$DOCTOR_OUT" 2>&1 || true
if grep -q 'site author source' "$DOCTOR_OUT" && grep -q 'authors.source' "$DOCTOR_OUT"; then
	ok "the doctor reports the site's author source next to the app's author content directory"
else
	bad "the doctor does not report the site's author source"
fi
if grep -q 'invisible to the site' "$DOCTOR_OUT"; then
	bad "the site's authors.source does not match the author content directory (the mirror is invisible)"
else
	ok "the site's authors.source matches the author content directory"
fi
# the parameters that left the page must stay visible: a summary row in the plain table, and every
# effective value with its origin in --json
if grep -q 'bridge parameters' "$DOCTOR_OUT"; then
	ok "the doctor reports the bridge parameters that left the settings page ($(grep -o '16 settable · [0-9]* set by this app' "$DOCTOR_OUT" | head -1))"
else
	bad "the doctor does not report the bridge parameters"
fi
if grep -q 'last push failure' "$DOCTOR_OUT"; then
	ok "the doctor reports the last push failure"
else
	bad "the doctor does not report the last push failure"
fi
DOCTOR_JSON=/tmp/va-doctor.json
occ pages:doctor --json > "$DOCTOR_JSON" 2>/dev/null || true
JSON_KEYS=$(python3 - "$DOCTOR_JSON" <<'PY'
import json, sys
try:
    report = json.load(open(sys.argv[1]))
except Exception:
    print('unreadable')
    raise SystemExit(0)
config = report.get('config') or {}
checks = [c.get('name') for c in (report.get('checks') or [])]
names = ['listen', 'work_root', 'exclude', 'mirror_use_gitignore']
missing = [n for n in names if n not in config]
print('missing=%s|config=%d|lastPush=%s|params=%s' % (
    ','.join(missing) or 'none',
    len(config),
    'yes' if 'last push failure' in checks else 'no',
    'yes' if 'bridge parameters' in checks else 'no',
))
PY
)
if [[ "$JSON_KEYS" == missing=none* && "$JSON_KEYS" == *config=16* && "$JSON_KEYS" == *lastPush=yes* && "$JSON_KEYS" == *params=yes* ]]; then
	ok "pages:doctor --json carries all 16 parameters with their source ($JSON_KEYS)"
else
	bad "pages:doctor --json does not carry the parameters as expected ($JSON_KEYS)"
fi
# occ pages:config: the whitelist is enforced, and every settable key is listed
CONFIG_LIST=/tmp/va-config.txt
occ pages:config list > "$CONFIG_LIST" 2>&1 || true
CONFIG_KEYS=$(python3 - "$CONFIG_LIST" <<'PY'
import re, sys
want = {
    'listen', 'nc_data_root', 'work_root', 'state_dir', 'override_dir', 'log_dir',
    'build_timeout_s', 'build_debounce_ms', 'log_tail_lines', 'mirror_verify',
    'mirror_delete_guard_ratio', 'mirror_delete_guard_min', 'mirror_preserve_exec',
    'mirror_use_gitignore', 'exclude', 'unexclude',
}
found = set()
try:
    lines = open(sys.argv[1], encoding='utf-8', errors='replace').read().splitlines()
except OSError:
    lines = []
for line in lines:
    match = re.match(r'^\|\s*([a-z_]+)\s*\|', line)
    if match and match.group(1) in want:
        found.add(match.group(1))
print('%d|%s' % (len(found), ','.join(sorted(want - found))))
PY
)
CONFIG_KEY_COUNT="${CONFIG_KEYS%%|*}"
CONFIG_MISSING="${CONFIG_KEYS#*|}"
if [[ "$CONFIG_KEY_COUNT" == "16" ]]; then
	ok "occ pages:config list prints all 16 settable keys"
else
	bad "occ pages:config list printed $CONFIG_KEY_COUNT of 16 keys (missing: $CONFIG_MISSING)"
fi
CONFIG_GET=$(occ pages:config get work_root 2>&1 | head -1)
if [[ -n "$CONFIG_GET" ]]; then
	ok "occ pages:config get work_root answers ($CONFIG_GET)"
else
	bad "occ pages:config get work_root printed nothing"
fi
UNKNOWN_OUT=$(occ pages:config set pages_no_such_key 1 2>&1)
UNKNOWN_CODE=$?
if [[ "$UNKNOWN_CODE" == "1" && "$UNKNOWN_OUT" == *"Settable keys:"* ]]; then
	ok "an unknown key is refused with the settable keys listed"
else
	bad "an unknown key was not refused with the list of settable keys (exit $UNKNOWN_CODE)"
fi
# the repair command exists and its wiring is the existing one (it is not run here: it rebuilds)
OCC_LIST=$(occ list 2>/dev/null || true)
if grep -q 'pages:repair' <<<"$OCC_LIST"; then
	ok "occ pages:repair is registered"
else
	bad "occ pages:repair is not registered"
fi
# the liveness probe answers without a session (the point of it) and names version + protocol
PING=$(curl --noproxy '*' -sk -o /tmp/va-ping.json -w '%{http_code}' "$BASE/index.php/apps/pages/api/bridge/ping")
if [[ "$PING" == "200" ]] && python3 -c '
import json, sys
d = json.load(open("/tmp/va-ping.json"))
raise SystemExit(0 if d.get("ok") is True and "version" in d and "protocol" in d else 1)
' 2>/dev/null; then
	ok "GET /api/bridge/ping answers unauthenticated with {ok, version, protocol} ($(cat /tmp/va-ping.json | head -c 120))"
else
	bad "GET /api/bridge/ping did not answer {ok, version, protocol} (HTTP $PING)"
fi

say "6. no PHP error/exception text in the page"
ERRS=$(grep -oE 'Fatal error[^<]{0,120}|Warning: [^<]{0,120}|Notice: [^<]{0,120}|Undefined (variable|array key)[^<]{0,80}|Uncaught [A-Za-z\\]+' "$PAGE" | head -5)
if [[ -z "$ERRS" ]]; then
	ok "clean"
else
	bad "error text in the page:"
	printf '%s\n' "$ERRS" | sed 's/^/       /'
fi

say "7. any pages errors in nextcloud.log (last 200 lines)"
LOG=$(find /var/snap/nextcloud -maxdepth 4 -name 'nextcloud.log' -newermt '-10 minutes' 2>/dev/null | head -1)
[[ -n "$LOG" ]] || LOG=$(find /var/snap/nextcloud -maxdepth 4 -name 'nextcloud.log' 2>/dev/null | xargs ls -t 2>/dev/null | head -1)
if [[ -z "$LOG" ]]; then
	echo "  (nextcloud.log not found, skipped)"
else
	# check python's own exit code: a broken check once printed nothing and reported "no errors" (false green)
	if ! tail -n 200 "$LOG" | python3 -c '
import sys, json
hits = []
for line in sys.stdin:
    try:
        d = json.loads(line)
    except Exception:
        continue
    msg = d.get("message") or ""
    if (d.get("app") == "pages" or "extra-apps/pages" in msg) and d.get("level", 0) >= 3:
        hits.append("%s L%s %s" % (d.get("time"), d.get("level"), msg[:160]))
print("\n".join(hits[:5]))
' > /tmp/va-log.txt 2> /tmp/va-log.err; then
		bad "the log-check script itself failed:"
		head -3 /tmp/va-log.err | sed 's/^/       /'
	else
		FOUND=$(cat /tmp/va-log.txt)
		if [[ -z "$FOUND" ]]; then
			ok "no pages errors in the log ($LOG)"
		else
			bad "pages errors in the log:"
			printf '%s\n' "$FOUND" | sed 's/^/       /'
		fi
	fi
fi

say "result"
if [[ "$FAIL" -eq 0 ]]; then
	printf '  \033[32mall checks passed\033[0m\n'
else
	printf '  \033[31m%d check(s) failed\033[0m\n' "$FAIL"
fi
exit "$((FAIL > 0 ? 1 : 0))"
