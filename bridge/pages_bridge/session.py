"""Two secrets and short-lived signed tokens."""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import secrets
import time
from typing import Iterable

from .util import AuthError

#: 2 = single site: the payload carries no site claim any more, so version 1 tokens are refused.
VERSION = 2
MAX_TTL = 24 * 3600


def _b64u_encode(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


def _b64u_decode(text: str) -> bytes:
    padding = "=" * (-len(text) % 4)
    try:
        return base64.urlsafe_b64decode(text + padding)
    except (ValueError, TypeError) as exc:
        raise AuthError("invalid token encoding") from exc


def _digest(payload_b64: str, secret: str) -> str:
    mac = hmac.new(secret.encode("utf-8"), payload_b64.encode("ascii"), hashlib.sha256)
    return _b64u_encode(mac.digest())


def issue(
    secret: str,
    *,
    scopes: Iterable[str],
    ttl: int,
    extra: dict | None = None,
) -> tuple[str, int]:
    """Issue a short-lived token; returns ``(token, exp)``."""
    ttl = max(30, min(int(ttl), MAX_TTL))
    now = int(time.time())
    payload = {
        "v": VERSION,
        "scope": sorted({str(s) for s in scopes}),
        "iat": now,
        "exp": now + ttl,
        "jti": secrets.token_hex(8),
    }
    if extra:
        payload.update(extra)
    payload_b64 = _b64u_encode(
        json.dumps(payload, separators=(",", ":"), sort_keys=True).encode("utf-8")
    )
    return f"{payload_b64}.{_digest(payload_b64, secret)}", payload["exp"]


# verification is stateless: signature, expiry and scope only, nothing stored
# (single site: a token is not bound to a site, the bridge has exactly one)
def verify(
    token: str,
    secret: str,
    *,
    scope: str | None = None,
    now: int | None = None,
) -> dict:
    """Verify signature, expiry and scope; any failure raises :class:`AuthError`."""
    if not token or token.count(".") != 1:
        raise AuthError("malformed token")
    payload_b64, signature = token.split(".", 1)

    expected = _digest(payload_b64, secret)
    if not hmac.compare_digest(expected, signature):
        raise AuthError("token signature mismatch")

    try:
        payload = json.loads(_b64u_decode(payload_b64).decode("utf-8"))
    except (ValueError, UnicodeDecodeError) as exc:
        raise AuthError("token payload cannot be parsed") from exc
    if not isinstance(payload, dict) or payload.get("v") != VERSION:
        raise AuthError("unsupported token version")

    current = int(time.time()) if now is None else int(now)
    if int(payload.get("exp", 0)) <= current:
        raise AuthError("token expired", exp=payload.get("exp"), now=current)

    if scope is not None and scope not in (payload.get("scope") or []):
        raise AuthError("token lacks the required scope", need=scope, have=payload.get("scope"))

    return payload


def peek(token: str) -> dict:
    """Read the payload without verifying the signature; for logs and debugging."""
    try:
        payload_b64 = token.split(".", 1)[0]
        return json.loads(_b64u_decode(payload_b64).decode("utf-8"))
    except Exception:  # noqa: BLE001 - debug helper: return empty on failure
        return {}


def verify_bearer(header: str | None, secret: str) -> None:
    """Verify ``Authorization: Bearer <shared_secret>``."""
    if not header:
        raise AuthError("missing Authorization header")
    scheme, _, value = header.partition(" ")
    if scheme.lower() != "bearer" or not value:
        raise AuthError("Authorization header must be 'Bearer <token>'")
    if not hmac.compare_digest(value.strip(), secret):
        raise AuthError("shared secret mismatch")
