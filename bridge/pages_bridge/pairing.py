"""One-time pairing codes (``state_dir/pairing.json``) for the UNPAIRED state.

A bridge without secrets is useless but reachable: it answers only ``GET /health`` and
``POST /pair`` on a loopback address. ``bridge/install.sh`` prints one code, the administrator types
it into the Nextcloud Pages settings, and the app sends both secrets plus the data directory in a
single request. The code is a bearer credential for exactly one write, so it is short-lived,
single-use and dies after three wrong attempts.
"""

from __future__ import annotations

import hmac
import json
import secrets
import time
from pathlib import Path
from typing import Any

from .util import BadPairingCode, PairingCodeExpired, atomic_write_text, read_json

#: base32 without 0/1/8/9-style lookalikes: every character is unambiguous when typed by hand.
ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"
CODE_LEN = 8
#: Ten minutes is enough to walk to the Nextcloud settings, short enough to be useless afterwards.
TTL_S = 600
#: The third wrong attempt kills the code, so a brute force has three tries out of 32^8.
MAX_ATTEMPTS = 3
#: The file is a live credential for one pairing: same mode as effective.json.
MODE = 0o600


def pairing_path(state_dir: str | Path) -> Path:
    """The code file of this bridge; ``state_dir`` is the effective one from the configuration."""
    return Path(state_dir) / "pairing.json"


def new_code() -> str:
    return "".join(secrets.choice(ALPHABET) for _ in range(CODE_LEN))


def issue(state_dir: str | Path, *, now: int | None = None, ttl: int = TTL_S) -> str:
    """Write a fresh one-time code and return it; an older code is replaced, not extended."""
    code = new_code()
    stamp = int(time.time() if now is None else now)
    payload = {"code": code, "exp": stamp + int(ttl), "attempts": 0}
    atomic_write_text(
        pairing_path(state_dir),
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
        mode=MODE,
    )
    return code


def read(state_dir: str | Path) -> dict[str, Any] | None:
    """The stored code record, or ``None`` when there is none (or it is unreadable)."""
    data = read_json(pairing_path(state_dir), default=None)
    return data if isinstance(data, dict) else None


def clear(state_dir: str | Path) -> None:
    """Drop the code file; a missing file is not an error."""
    try:
        pairing_path(state_dir).unlink()
    except OSError:
        pass


def verify(state_dir: str | Path, submitted: str) -> None:
    """Check one submitted code and charge a wrong attempt.

    Raises :class:`BadPairingCode` (401, with the attempts left) or :class:`PairingCodeExpired`
    (410) when the code is gone, expired or exhausted. The caller clears the file after the write it
    guards succeeded, so a correct code that fails validation can be retried.
    """
    record = read(state_dir)
    if not record:
        raise PairingCodeExpired()

    code = str(record.get("code") or "")
    exp = record.get("exp")
    attempts = record.get("attempts")
    attempts = attempts if isinstance(attempts, int) and not isinstance(attempts, bool) else 0
    now = int(time.time())
    if not code or not isinstance(exp, (int, float)) or exp <= now or attempts >= MAX_ATTEMPTS:
        clear(state_dir)
        raise PairingCodeExpired()

    # a code printed by install.sh may be typed in either case
    candidate = str(submitted or "").strip().upper()
    if hmac.compare_digest(code, candidate):
        return

    attempts += 1
    if attempts >= MAX_ATTEMPTS:
        clear(state_dir)
        raise PairingCodeExpired()
    record["attempts"] = attempts
    atomic_write_text(
        pairing_path(state_dir),
        json.dumps(record, ensure_ascii=False, indent=2) + "\n",
        mode=MODE,
    )
    raise BadPairingCode(MAX_ATTEMPTS - attempts)
