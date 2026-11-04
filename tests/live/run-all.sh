#!/usr/bin/env bash
#
# One-command runner for the live acceptance suites in tests/live/.
#
#   sudo bash tests/live/run-all.sh                     all 12 suites, in order
#   bash tests/live/run-all.sh --list                   the suite list (no root, no instance)
#   sudo bash tests/live/run-all.sh --only test-authors.sh
#   sudo bash tests/live/run-all.sh --skip test-external.sh,test-import.sh
#   sudo bash tests/live/run-all.sh --from test-community.sh
#
# Each suite runs in its own subshell with its output in tests/live/logs/<stamp>-<suite>.log, so a
# failure does not stop the suites after it. The summary prints PASS/FAIL/SKIP, exit code, duration
# and log path, plus the single-suite command to rerun every failure.
#
# The suites are started from the repository root, not from tests/live: a few of them read the app
# source next to them (src/, templates/, lib/, l10n/), and that is the working directory they are
# documented for. Standalone runs are from the repo root too: `sudo bash tests/live/<suite>`.
#
# Environment: NC_HOST (default https://localhost) and NC_INSECURE=1 (curl -k, for a self-signed
# certificate on another host) retarget the pre-flight probe; NC_HOST is also handed to the suites
# as BASE unless BASE is set. Everything else the suites read (NC_USER, PW_FILE, …) is inherited.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# the repo root, one level above tests/: the suites run from there (see the note above), because a
# suite that inspects the app source uses paths relative to it
ROOT="$(cd "$HERE/../.." && pwd)"
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
LOG_DIR="$HERE/logs"

# Fixed order: broadest deploy/admin checks first, then the feature suites, cheapest last.
SUITES=(
	verify-deploy.sh
	verify-admin.sh
	test-site-config.sh
	test-pairing.sh
	test-permissions.sh
	test-authors.sh
	test-community.sh
	test-engagement.sh
	test-external.sh
	test-frontmatter-merge.sh
	test-import.sh
	test-preview-unicode.sh
)

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
err()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; }

usage() {
	cat >&2 <<'EOF'
usage: sudo bash tests/live/run-all.sh [--list | --only NAME | --skip a,b | --from NAME]

With no option, runs every live acceptance suite in order; that needs root and a deployed
instance, and issues the one-shot app password if /root/.pages-test-pw is missing or empty.

  --list         print the suite list and exit (needs neither root nor an instance)
  --only NAME    run only NAME (with or without the .sh)
  --skip a,b     skip the comma-separated suites
  --from NAME    start at NAME (inclusive); everything before it is skipped

environment:
  NC_HOST        base URL of the instance to probe (default https://localhost)
  NC_INSECURE=1  probe with curl -k (self-signed certificate on a non-default host)

See tests/README.md.
EOF
}

# Name with or without .sh -> the matching entry of SUITES on stdout (nothing + rc 1 if unknown).
resolve() {
	local want="${1%.sh}" s
	for s in "${SUITES[@]}"; do
		[[ "${s%.sh}" == "$want" ]] && { printf '%s' "$s"; return 0; }
	done
	return 1
}

list_suites() {
	local i=0 s
	printf 'live acceptance suites (tests/live), run with: sudo bash tests/live/run-all.sh\n'
	for s in "${SUITES[@]}"; do
		i=$((i + 1))
		printf '  %2d. %s\n' "$i" "$s"
	done
}

# ---------------------------------------------------------------- pre-flight
# Returns 0 when the instance is ready to be tested, 1 otherwise (already reported).
check_instance() {
	local host="${NC_HOST:-https://localhost}"
	host="${host%/}"
	local -a opts=(-sS --max-time 10 --noproxy '*')
	# the snap install serves a self-signed certificate on localhost; NC_INSECURE=1 says the same
	# about a custom NC_HOST
	if [[ "${NC_INSECURE:-0}" == 1 || -z "${NC_HOST:-}" ]]; then
		opts+=(-k)
	fi

	if ! command -v curl >/dev/null 2>&1; then
		err "curl is not installed: it is needed to probe the instance at $host"
		instance_hint "$host"
		return 1
	fi

	local body rc
	body=$(curl "${opts[@]}" "$host/status.php" 2>/dev/null)
	rc=$?
	if [[ $rc -ne 0 ]]; then
		err "cannot reach $host/status.php (curl exit $rc)"
		instance_hint "$host"
		return 1
	fi
	if ! grep -Eq '"installed"[[:space:]]*:[[:space:]]*true' <<<"$body"; then
		err "no Nextcloud is installed at $host (status.php reports installed != true)"
		instance_hint "$host"
		return 1
	fi
	if grep -Eq '"maintenance"[[:space:]]*:[[:space:]]*true' <<<"$body"; then
		err "the Nextcloud at $host is in maintenance mode; wait until it is idle"
		instance_hint "$host"
		return 1
	fi
	return 0
}

instance_hint() {
	cat >&2 <<EOF
    these suites need a running, installed Nextcloud. To use another instance:
      NC_HOST=https://cloud.example.com NC_INSECURE=1 sudo -E bash tests/live/run-all.sh
    checked host: $1
EOF
}

# occ is optional here: when it cannot be resolved or sudo cannot run it, warn and move on.
check_app_enabled() {
	local occ_cmd="" candidate
	for candidate in "$(command -v nextcloud.occ 2>/dev/null)" /snap/bin/nextcloud.occ; do
		[[ -n "$candidate" && -x "$candidate" ]] && { occ_cmd="$candidate"; break; }
	done
	if [[ -z "$occ_cmd" ]]; then
		warn "nextcloud.occ not found on PATH: skipping the 'pages app enabled' check"
		return 0
	fi

	local applist rc
	applist=$(sudo -n "$occ_cmd" app:list 2>/dev/null)
	rc=$?
	if [[ $rc -ne 0 || -z "$applist" ]]; then
		warn "sudo -n $occ_cmd app:list failed (exit $rc): skipping the 'pages app enabled' check"
		return 0
	fi

	# `occ app:list` prints an "Enabled:" header and then "- <app>: <version>" lines, so the word
	# "enabled" and the app name are never on the same line: track the section instead of grepping
	# one line for both. Header case differs between occ versions, hence the alternatives.
	if ! awk '
		/^[[:space:]]*[Ee]nabled[[:space:]]*:[[:space:]]*$/ { in_enabled = 1; next }
		/^[[:space:]]*[Dd]isabled[[:space:]]*:[[:space:]]*$/ { in_enabled = 0; next }
		in_enabled && /^[[:space:]]*-[[:space:]]+pages:/ { found = 1 }
		END { exit(found ? 0 : 1) }
	' <<<"$applist"; then
		err "the pages app is not enabled on this instance"
		printf '    enable it with: sudo nextcloud.occ app:enable pages\n' >&2
		return 1
	fi
	return 0
}

preflight() {
	if [[ ${EUID} -ne 0 ]]; then
		err "must run as root: the suites use occ and read $PW_FILE"
		printf '    run with: sudo bash tests/live/run-all.sh\n' >&2
		return 1
	fi
	check_instance || return 1
	check_app_enabled || return 1

	if [[ ! -s "$PW_FILE" ]]; then
		say "no app password at $PW_FILE: issuing one with test-auth.sh grant"
		if ! bash "$HERE/test-auth.sh" grant; then
			err "test-auth.sh grant failed"
			printf '    run it by hand: sudo bash tests/live/test-auth.sh grant\n' >&2
			return 1
		fi
	fi
	return 0
}

# ---------------------------------------------------------------- selection
list_only=0
only=""
skip_raw=""
from=""

while [[ $# -gt 0 ]]; do
	case "$1" in
	--list) list_only=1; shift ;;
	--only|--skip|--from)
		if [[ $# -lt 2 ]]; then
			err "$1 needs a value"
			usage
			exit 2
		fi
		case "$1" in
		--only) only="$2" ;;
		--skip) skip_raw="$2" ;;
		--from) from="$2" ;;
		esac
		shift 2
		;;
	-*)
		err "unknown option: $1"
		usage
		exit 2
		;;
	*)
		err "unexpected argument: $1"
		usage
		exit 2
		;;
	esac
done

# --list is deliberately before every environment check: it must work on any machine.
if [[ $list_only -eq 1 ]]; then
	list_suites
	exit 0
fi

selected=()
skipped=() # "<suite>|<reason>"

if [[ -n "$only" ]]; then
	s=$(resolve "$only") || {
		err "unknown suite: $only"
		printf '    --list shows the available suites\n' >&2
		exit 2
	}
	selected=("$s")
	if [[ -n "$skip_raw" || -n "$from" ]]; then
		warn "--only wins: --skip/--from are ignored"
	fi
else
	# skip set as "|a.sh|b.sh|" so membership needs no associative array
	skip_set=""
	if [[ -n "$skip_raw" ]]; then
		IFS=',' read -r -a parts <<<"$skip_raw"
		for p in "${parts[@]}"; do
			p="${p//[[:space:]]/}"
			[[ -z "$p" ]] && continue
			s=$(resolve "$p") || {
				err "unknown suite in --skip: $p"
				printf '    --list shows the available suites\n' >&2
				exit 2
			}
			skip_set+="|$s|"
		done
	fi

	from_idx=-1
	if [[ -n "$from" ]]; then
		s=$(resolve "$from") || {
			err "unknown suite in --from: $from"
			printf '    --list shows the available suites\n' >&2
			exit 2
		}
		for idx in "${!SUITES[@]}"; do
			[[ "${SUITES[$idx]}" == "$s" ]] && { from_idx=$idx; break; }
		done
	fi

	for idx in "${!SUITES[@]}"; do
		s="${SUITES[$idx]}"
		if [[ -n "$skip_set" && "$skip_set" == *"|$s|"* ]]; then
			skipped+=("$s|--skip")
		elif ((from_idx >= 0 && idx < from_idx)); then
			skipped+=("$s|before --from")
		else
			selected+=("$s")
		fi
	done
fi

if [[ ${#selected[@]} -eq 0 ]]; then
	say "nothing to run: every suite was skipped"
	for row in "${skipped[@]-}"; do
		[[ -n "$row" ]] && printf '  SKIP %s (%s)\n' "${row%%|*}" "${row#*|}"
	done
	exit 0
fi

# ---------------------------------------------------------------- run
preflight || exit 1

# the suites read BASE, not NC_HOST: keep them on the instance that was just probed
if [[ -n "${NC_HOST:-}" && -z "${BASE:-}" ]]; then
	export BASE="${NC_HOST%/}"
fi

mkdir -p "$LOG_DIR" || { err "cannot create $LOG_DIR"; exit 1; }
STAMP=$(date +%Y%m%d-%H%M%S)

printf '\n'
say "live acceptance: ${#selected[@]} suite(s), host ${BASE:-https://localhost}"
printf '    logs: %s\n\n' "$LOG_DIR"

results=() # "<suite>|<status>|<rc>|<seconds>|<log>"
passed=0
failed=0
i=0

for s in "${selected[@]}"; do
	i=$((i + 1))
	log="$LOG_DIR/$STAMP-${s%.sh}.log"

	printf '[%d/%d] %-28s ... ' "$i" "${#selected[@]}" "$s"
	start=$(date +%s)
	# run each suite from the repository root: that is the working directory its doc comment
	# documents, and the one its source-relative checks assume
	( cd "$ROOT" && bash "$HERE/$s" ) >"$log" 2>&1
	rc=$?
	end=$(date +%s)
	dur=$((end - start))

	if [[ $rc -eq 0 ]]; then
		status=PASS
		passed=$((passed + 1))
	else
		status=FAIL
		failed=$((failed + 1))
	fi
	printf '%s (exit %d, %ds)\n' "$status" "$rc" "$dur"
	results+=("$s|$status|$rc|$dur|$log")
done

# ---------------------------------------------------------------- summary
printf '\n===== summary =====\n'
printf '%-6s  %-28s  %-5s  %-6s  %s\n' STATUS SUITE EXIT TIME LOG
for row in "${results[@]}"; do
	IFS='|' read -r n st rc dur log <<<"$row"
	printf '%-6s  %-28s  %-5s  %-6s  %s\n' "$st" "$n" "$rc" "${dur}s" "tests/live/logs/$(basename "$log")"
done
for row in "${skipped[@]-}"; do
	[[ -n "$row" ]] || continue
	printf '%-6s  %-28s  %-5s  %-6s  %s\n' SKIP "${row%%|*}" '-' '-' "(${row#*|})"
done
printf '\n%d passed, %d failed, %d skipped\n' "$passed" "$failed" "${#skipped[@]}"

if ((failed > 0)); then
	printf '\n----- failures -----\n'
	for row in "${results[@]}"; do
		IFS='|' read -r n st rc dur log <<<"$row"
		[[ "$st" == FAIL ]] || continue
		printf 'FAIL  %s  (exit %d, %ds)\n' "$n" "$rc" "$dur"
		# the rerun command is the documented one, issued from the repository root
		printf '  rerun: sudo bash tests/live/%s\n' "$n"
		printf '  log:   tests/live/logs/%s\n\n' "$(basename "$log")"
	done
	printf 'logs: %s\n' "$LOG_DIR"
	exit 1
fi

printf '\nALL LIVE SUITES PASSED\n'
exit 0
