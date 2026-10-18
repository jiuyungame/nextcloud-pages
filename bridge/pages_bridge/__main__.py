"""Command-line entry point."""

from __future__ import annotations

import argparse
import contextlib
import json
import secrets
import sys

from . import __version__, pairing
from .builder import env_report
from .config import EFFECTIVE_FILE, Config, load_config
from .server import serve
from .util import BridgeError, error

DEFAULT_CONFIG = "/etc/pages-bridge/pages-bridge.toml"

#: What the settings form has to be told about hosted pairing; the bridge cannot discover it.
SAME_MACHINE_NOTE = (
    "hosted pairing is same-machine only: an unpaired bridge listens on loopback, so a Nextcloud "
    "on another host cannot reach it. There, keep the hand-written path - both secrets and the "
    "[site] table in the config file - which works exactly as before."
)


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="pages-bridge",
        description="Host-side executor for the Nextcloud Pages app (mirror + build + git)",
    )
    parser.add_argument("-c", "--config", default=DEFAULT_CONFIG, help=f"config file path (default {DEFAULT_CONFIG})")
    parser.add_argument("-V", "--version", action="version", version=f"pages-bridge {__version__}")
    sub = parser.add_subparsers(dest="command")
    sub.add_parser("serve", help="start the HTTP service (default)")
    sub.add_parser("check", help="validate the config and exit")
    sub.add_parser("env", help="print the environment report (JSON)")
    sub.add_parser("listen", help="print the effective listen address (host:port)")
    sub.add_parser("pair-code", help="issue and print a one-time pairing code")
    sub.add_parser("gen-secret", help="generate a pair of secrets")
    return parser


def _bridge_url(cfg: Config) -> str:
    """The address the settings form should be given; a wildcard bind is reached over loopback."""
    host = cfg.listen_host if cfg.listen_host not in ("0.0.0.0", "::", "") else "127.0.0.1"
    if ":" in host:
        host = f"[{host}]"
    return f"http://{host}:{cfg.listen_port}"


def _print_pair_code(cfg: Config) -> None:
    """Issue a one-time code and print everything the administrator has to type or paste."""
    code = pairing.issue(cfg.state_dir)
    print(f"pairing code : {code}")
    print(f"bridge URL   : {_bridge_url(cfg)}")
    print(f"valid        : {pairing.TTL_S} s, one-time use, {pairing.MAX_ATTEMPTS} wrong attempts kill it")
    print(f"note         : {SAME_MACHINE_NOTE}")


def main(argv: list[str] | None = None) -> int:
    args = _build_parser().parse_args(argv)

    if args.command == "gen-secret":
        print("shared_secret          = " + secrets.token_hex(32))
        print("session_signing_secret = " + secrets.token_hex(32))
        return 0

    try:
        if args.command in ("listen", "env"):
            # These two exist to be parsed by a script, and loading the config logs to stdout
            # (util.log writes info records there). Send that to stderr so stdout carries the value
            # alone - install.sh used to read a log line as the port.
            with contextlib.redirect_stdout(sys.stderr):
                cfg = load_config(args.config)
        else:
            cfg = load_config(args.config)
    except BridgeError as exc:
        error("failed to load config", code=exc.code, message=exc.message, **exc.details)
        return 2

    if args.command == "check":
        # single site: there is no site list to report any more
        state = "paired" if cfg.paired else "UNPAIRED (pair from the Nextcloud Pages settings)"
        print(f"config OK: listening on {cfg.listen_host}:{cfg.listen_port} [{state}]")
        if cfg.site is None:
            print("  site: none configured yet - the app pushes it while pairing")
        else:
            print(f"  source={cfg.site.source}")
            print(f"  work={cfg.site.work} (commit tree)")
            print(f"  preview={cfg.site.preview} (built, served)")
            print(f"  recipe={cfg.site.recipe.preset} output={cfg.site.output_dir}")
        return 0

    if args.command == "listen":
        print(f"{cfg.listen_host}:{cfg.listen_port}")
        return 0

    if args.command == "env":
        print(json.dumps(env_report(cfg), ensure_ascii=False, indent=2))
        return 0

    if args.command == "pair-code":
        if cfg.paired:
            # /pair answers 409 on a paired bridge, so a code would be a lie
            stored = cfg.bootstrap_state_dir / EFFECTIVE_FILE
            print("this bridge is already paired: the Nextcloud app owns the configuration.")
            print(f"to pair it again, remove {stored} and restart pages-bridge.service.")
            print("note         : " + SAME_MACHINE_NOTE)
            return 0
        _print_pair_code(cfg)
        return 0

    try:
        serve(cfg)
    except OSError as exc:
        error("failed to start the service", error=str(exc), listen=f"{cfg.listen_host}:{cfg.listen_port}")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
