#!/usr/bin/env bash
#
# pages-bridge bare-metal installer (no Docker)
#
#   sudo ./install.sh              install/upgrade and start
#   sudo ./install.sh --uninstall  stop and remove (keeps /var/lib/pages and the config)
#
# Idempotent: re-running only updates the code and never overwrites an existing config.
set -euo pipefail

PREFIX="${PREFIX:-/opt/pages-bridge}"
CONF_DIR="${CONF_DIR:-/etc/pages-bridge}"
CONF="${CONF_DIR}/pages-bridge.toml"
LIB="${LIB:-/var/lib/pages}"
UNIT="/etc/systemd/system/pages-bridge.service"
PY="${PY:-/usr/bin/python3}"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say()  { printf '\033[1;36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ ${EUID} -eq 0 ]] || die "run as root (sudo $0)"

if [[ "${1:-}" == "--uninstall" ]]; then
  say "stopping and disabling the service"
  systemctl disable --now pages-bridge.service 2>/dev/null || true
  rm -f "$UNIT"
  systemctl daemon-reload
  rm -rf "$PREFIX"
  warn "uninstalled. The config $CONF and the state directory $LIB were kept; remove them by hand to clean up fully."
  exit 0
fi

[[ -x "$PY" ]] || die "python not found: $PY (override with PY=/path/to/python3)"
"$PY" - <<'EOF' || die "Python 3.11 or newer is required (tomllib)"
import sys
raise SystemExit(0 if sys.version_info >= (3, 11) else 1)
EOF
say "Python OK: $("$PY" -c 'import sys;print(".".join(map(str,sys.version_info[:3])))')"

say "installing the code into $PREFIX"
mkdir -p "$PREFIX"
rm -rf "$PREFIX/pages_bridge"
cp -r "$SRC_DIR/pages_bridge" "$PREFIX/pages_bridge"
find "$PREFIX/pages_bridge" -name '__pycache__' -type d -prune -exec rm -rf {} + 2>/dev/null || true

say "creating the data directory $LIB"
# work is the default work_root: the commit tree (a git history that cannot be regenerated) lives in
# the same root as state/overrides/logs and is backed up with them. It holds source/ and online/
# side by side; the two other trees next to it (work.preview, work.onlinepreview) are derived.
mkdir -p "$LIB/work" "$LIB/state" "$LIB/overrides" "$LIB/logs"
chmod 750 "$LIB"

mkdir -p "$CONF_DIR"
chmod 750 "$CONF_DIR"
if [[ -f "$CONF" ]]; then
  say "config already exists, skipping: $CONF"
else
  say "generating config: $CONF"
  # The example carries no secrets and no [site] table on purpose: the bridge starts UNPAIRED, only
  # answers on loopback, and the Nextcloud app hands over both secrets and the site while pairing.
  install -m 640 "$SRC_DIR/pages-bridge.toml.example" "$CONF"
  warn "no secrets and no [site] in $CONF yet: the bridge starts UNPAIRED and waits for pairing."
fi

say "validating the config"
if ! (cd "$PREFIX" && "$PY" -m pages_bridge -c "$CONF" check); then
  die "config validation failed. Fix $CONF and run this script again."
fi

say "installing the systemd unit"
install -m 644 "$SRC_DIR/pages-bridge.service" "$UNIT"
systemctl daemon-reload
systemctl enable pages-bridge.service
# restart, not enable --now: --now is a no-op for a service that already runs the old code
systemctl restart pages-bridge.service
sleep 1

say "service status"
systemctl --no-pager --lines=15 status pages-bridge.service || true

# the effective listen address, not the one in the file: after pairing the app owns it.
# Only a bare host:port line counts, so a stray diagnostic on stdout cannot become the port.
LISTEN="$(cd "$PREFIX" && "$PY" -m pages_bridge -c "$CONF" listen 2>/dev/null | grep -oE '^[^[:space:]]+:[0-9]+$' | tail -1 || true)"
[[ -n "$LISTEN" ]] || LISTEN="127.0.0.1:8787"
HOST="${LISTEN%:*}"
PORT="${LISTEN##*:}"
# a wildcard bind is reached over the loopback address, and ::1 needs brackets in a URL
case "$HOST" in
  ""|"0.0.0.0"|"::") HOST="127.0.0.1" ;;
  "::1") HOST="[::1]" ;;
esac
say "health check http://${HOST}:${PORT}/health"
# --noproxy is required: a host http_proxy without a loopback no_proxy sends 127.0.0.1 to the proxy
HEALTH_OK=0
for _ in 1 2 3 4 5; do
  if curl -fsS --noproxy '*' "http://${HOST}:${PORT}/health"; then
    HEALTH_OK=1
    break
  fi
  sleep 1
done
echo
if [[ "$HEALTH_OK" -eq 1 ]]; then
  # A fresh bridge is UNPAIRED and gets a one-time code here and nowhere else. A paired bridge keeps
  # the configuration the app owns, so there is nothing to hand over and no code to print.
  PAIRED=$(curl -fsS --noproxy '*' "http://127.0.0.1:${PORT}/health" 2>/dev/null \
    | "$PY" -c 'import json,sys;print("1" if json.load(sys.stdin).get("paired") else "0")' 2>/dev/null || echo 0)
  if [[ "$PAIRED" == "1" ]]; then
    say "installation complete: this bridge is already paired, the Nextcloud app owns its configuration"
    echo "    to pair it again: remove the pushed config and restart the service"
    echo "      rm <state_dir>/effective.json && systemctl restart pages-bridge.service"
  else
    say "pairing this bridge"
    (cd "$PREFIX" && "$PY" -m pages_bridge -c "$CONF" pair-code) || warn "could not issue a pairing code, see the message above"
    echo
    say "installation complete. Do not forget:"
    echo "    1) open Nextcloud -> Administration settings -> Pages and pair with the code above"
    echo "       (the app discovers the data directory, generates both secrets and pushes the config)"
  fi
  echo
  echo "    still to do:"
  echo "      1) add the SSH public key to the repository Deploy keys and enable Allow write access"
  echo "      2) hosted pairing is same-machine only: with Nextcloud on another host, put both secrets"
  echo "         and the [site] table into $CONF by hand - that path works exactly as before"
  echo "      3) after pairing, run the site initialization from the Pages settings (or POST /site/init)"
else
  warn "health check failed, see the logs: journalctl -u pages-bridge -n 50 --no-pager"
  warn "if the logs look fine, http_proxy is probably hijacking the loopback request: add 127.0.0.1,localhost to no_proxy"
  exit 1
fi
