#!/usr/bin/env bash
#
# One-shot app password management for the smoke tests.
#
#   sudo ./tests/live/test-auth.sh grant     create an app password → /root/.pages-test-pw (0600)
#   sudo ./tests/live/test-auth.sh revoke    delete the token created by grant
#   sudo ./tests/live/test-auth.sh status    show whether one is currently issued
set -euo pipefail

NC_USER="${NC_USER:-}"
PW_FILE="${PW_FILE:-/root/.pages-test-pw}"
ID_FILE="${ID_FILE:-/root/.pages-test-id}"

# non-login shells (wsl -e bash, cron) have no /snap/bin in PATH, so resolve occ explicitly
if [[ -z "${OCC:-}" ]]; then
	for candidate in "$(command -v nextcloud.occ 2>/dev/null)" /snap/bin/nextcloud.occ; do
		[[ -n "$candidate" && -x "$candidate" ]] && { OCC="$candidate"; break; }
	done
fi
OCC="${OCC:-snap run nextcloud.occ}"
occ() { $OCC "$@"; }

say() { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ ${EUID} -eq 0 ]] || die "run as root (sudo $0 $*)"

# default to the only user on the instance (this app is single-user)
if [[ -z "$NC_USER" ]]; then
	NC_USER=$(occ user:list 2>/dev/null | sed -n 's/^ *- \([^:]*\):.*/\1/p' | head -1)
fi
[[ -n "$NC_USER" ]] || die "cannot determine the Nextcloud user; pass NC_USER=<name>"

token_ids() {
	# plain (lexicographic) sort, not sort -n: the comm below needs identical ordering
	occ user:auth-tokens:list "$NC_USER" 2>/dev/null \
		| sed -n 's/^| *\([0-9]\+\) *|.*/\1/p' | sort
}

case "${1:-}" in
grant)
	if [[ -f "$PW_FILE" ]]; then
		say "$PW_FILE exists, revoking the old one first"
		"$0" revoke || true
	fi

	# occ does not name new app passwords, so diff the token list instead of guessing by name
	before=$(token_ids)
	out=$(occ user:add-app-password "$NC_USER" 2>/dev/null)
	pw=$(printf '%s\n' "$out" | sed -n '/^app password:/{n;p;}' | tr -d '\r')
	[[ -n "$pw" ]] || die "could not parse the app password out of the occ output:
$out"
	after=$(token_ids)
	newid=$(comm -13 <(printf '%s\n' "$before") <(printf '%s\n' "$after") | tail -1)
	[[ -n "$newid" ]] || die "could not determine the new token id (list: #$after)"

	umask 077
	printf '%s\n' "$pw" > "$PW_FILE"
	printf '%s\n' "$newid" > "$ID_FILE"
	say "issued: user $NC_USER, token #$newid → $PW_FILE"
	;;

revoke)
	[[ -f "$ID_FILE" ]] || { say "no $ID_FILE, nothing to revoke"; rm -f "$PW_FILE"; exit 0; }
	id=$(cat "$ID_FILE")
	tokens=$(token_ids || true)
	if [[ -n "$id" ]] && grep -qx "$id" <<<"$tokens"; then
		occ user:auth-tokens:delete "$NC_USER" "$id"
		say "revoked token #$id"
	else
		say "token #$id does not exist (already deleted?)"
	fi
	rm -f "$PW_FILE" "$ID_FILE"
	;;

status)
	if [[ -f "$PW_FILE" ]]; then
		say "issued: user $NC_USER, token #$(cat "$ID_FILE" 2>/dev/null || echo '?'), file $PW_FILE"
	else
		say "nothing issued right now"
	fi
	;;

*)
	sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//'
	exit 2
	;;
esac
