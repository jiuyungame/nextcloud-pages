"""Shared helpers: structured logging, path safety, atomic writes, hashes and error types."""

from __future__ import annotations

import hashlib
import json
import os
import sys
import time
from pathlib import Path
from typing import Any, Iterable


class BridgeError(Exception):
    """Base class for all bridge errors."""

    code = "BRIDGE_ERROR"
    #: HTTP status the HTTP layer answers with.
    http = 500

    def __init__(self, message: str, **details: Any) -> None:
        super().__init__(message)
        self.message = message
        self.details = details

    def to_dict(self) -> dict[str, Any]:
        payload: dict[str, Any] = {"error": self.code, "message": self.message}
        if self.details:
            payload["details"] = self.details
        return payload


class ConfigError(BridgeError):
    code = "CONFIG_ERROR"
    http = 500


class AuthError(BridgeError):
    code = "UNAUTHORIZED"
    http = 401


class Forbidden(BridgeError):
    """Authenticated, but the requested resource is off limits."""

    code = "FORBIDDEN"
    http = 403


class NotFound(BridgeError):
    code = "NOT_FOUND"
    http = 404


class BadRequest(BridgeError):
    code = "BAD_REQUEST"
    http = 400


class PathTraversal(BadRequest):
    code = "PATH_TRAVERSAL"


class MirrorDeleteGuard(BridgeError):
    """The run would delete more than the guard allows, so it was aborted."""

    code = "MIRROR_DELETE_GUARD"
    http = 409


class GitError(BridgeError):
    code = "GIT_ERROR"
    http = 500


class Conflict(BridgeError):
    code = "CONFLICT"
    http = 409


class Unpaired(BridgeError):
    """The bridge has no usable secrets yet: only ``GET /health`` and ``POST /pair`` answer.

    An unpaired bridge is a normal state, not a failure: the app pushes both secrets (and the site)
    in one ``POST /pair``. Every other route answers 503 with this code so the settings form can
    tell "not paired yet" apart from "broken".
    """

    code = "UNPAIRED"
    http = 503

    def __init__(self) -> None:
        super().__init__("not paired yet - pair the bridge from the Nextcloud Pages settings")


class AlreadyPaired(BridgeError):
    """``POST /pair`` on a bridge that already holds secrets; the app pushes config instead."""

    code = "ALREADY_PAIRED"
    http = 409

    def __init__(self) -> None:
        super().__init__("this bridge is already paired - push the configuration instead of pairing again")


class BadPairingCode(BridgeError):
    """A wrong one-time pairing code; the attempt is charged before this is raised."""

    code = "BAD_CODE"
    http = 401

    def __init__(self, attempts_left: int) -> None:
        super().__init__("the pairing code is wrong")
        self.attempts_left = attempts_left

    def to_dict(self) -> dict[str, Any]:
        # the app reads the count out of ``details``, the settings form keeps it at the top level
        return {**super().to_dict(), "attempts_left": self.attempts_left, "details": {"attempts_left": self.attempts_left}}


class PairingCodeExpired(BridgeError):
    """No usable pairing code: expired, exhausted, or never issued."""

    code = "CODE_EXPIRED"
    http = 410

    def __init__(self) -> None:
        super().__init__("the pairing code expired - run bridge/install.sh again")


_LEVELS = {"debug": 10, "info": 20, "warn": 30, "error": 40}
_MIN_LEVEL = _LEVELS.get(os.environ.get("PAGES_BRIDGE_LOG", "info").lower(), 20)


def log(level: str, msg: str, **fields: Any) -> None:
    if _LEVELS.get(level, 20) < _MIN_LEVEL:
        return
    record: dict[str, Any] = {"ts": round(time.time(), 3), "level": level, "msg": msg}
    if fields:
        record.update(fields)
    stream = sys.stderr if level in ("warn", "error") else sys.stdout
    print(json.dumps(record, ensure_ascii=False, default=str), file=stream, flush=True)


def debug(msg: str, **f: Any) -> None:
    log("debug", msg, **f)


def info(msg: str, **f: Any) -> None:
    log("info", msg, **f)


def warn(msg: str, **f: Any) -> None:
    log("warn", msg, **f)


def error(msg: str, **f: Any) -> None:
    log("error", msg, **f)


def norm_abs(path: str | os.PathLike[str]) -> Path:
    """Expand ~ and make absolute, without resolving symlinks."""
    return Path(os.path.abspath(os.path.expanduser(str(path))))


def is_within(child: Path | str, parent: Path | str) -> bool:
    """True when ``child`` is ``parent`` itself or below it, by real path."""
    try:
        Path(child).resolve(strict=False).relative_to(Path(parent).resolve(strict=False))
        return True
    except ValueError:
        return False


def safe_join(root: Path | str, rel: str) -> Path:
    """Join a relative path under ``root``, refusing any traversal."""
    cleaned = (rel or "").lstrip("/")
    if "\x00" in cleaned:
        raise PathTraversal("path contains a NUL byte", path=rel)
    candidate = norm_abs(Path(root) / cleaned)
    if not is_within(candidate, root):
        raise PathTraversal("path escapes the root directory", path=rel, root=str(root))
    return candidate


def excluded(rel_parts: Iterable[str], exclude: frozenset[str]) -> bool:
    """True when any path component is in the exclusion set."""
    return any(part in exclude for part in rel_parts)


def atomic_write_bytes(path: Path, data: bytes, mode: int = 0o644) -> None:
    """Write through a temp file and rename, so readers never see a partial file."""
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(f".{path.name}.tmp{os.getpid()}")
    try:
        with open(tmp, "wb") as fh:
            fh.write(data)
            fh.flush()
            os.fsync(fh.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, path)
    finally:
        if tmp.exists():
            try:
                tmp.unlink()
            except OSError:
                pass


def atomic_write_text(path: Path, text: str, mode: int = 0o644) -> None:
    atomic_write_bytes(path, text.encode("utf-8"), mode)


def read_json(path: Path, default: Any = None) -> Any:
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, json.JSONDecodeError):
        return default


def write_json(path: Path, data: Any) -> None:
    atomic_write_text(path, json.dumps(data, ensure_ascii=False, indent=2) + "\n")


def sha256_file(path: Path, chunk: int = 1 << 20) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as fh:
        while True:
            block = fh.read(chunk)
            if not block:
                break
            digest.update(block)
    return digest.hexdigest()


def secret_fingerprint(value: str) -> str | None:
    """First 16 hex characters of a secret's SHA-256: enough to compare, never enough to use.

    The full digest is deliberately not reported either: a secret with enough entropy cannot be
    recovered from 16 hex characters, and the app only needs to answer "is this the same value".
    """
    if not value:
        return None
    return hashlib.sha256(value.encode("utf-8")).hexdigest()[:16]


def dir_file_count(root: Path, exclude: frozenset[str] = frozenset()) -> int:
    total = 0
    for dirpath, dirnames, filenames in os.walk(root):
        rel = Path(dirpath).relative_to(root)
        dirnames[:] = [d for d in dirnames if not excluded((*rel.parts, d), exclude)]
        if excluded(rel.parts, exclude):
            continue
        total += len(filenames)
    return total


def tail_lines(text: str, limit: int = 200) -> str:
    if limit <= 0:
        return ""
    lines = text.splitlines()
    if len(lines) <= limit:
        return text
    return "\n".join(lines[-limit:])


def human_bytes(n: int) -> str:
    step = 1024.0
    value = float(n)
    for unit in ("B", "KiB", "MiB", "GiB"):
        if value < step:
            return f"{value:.1f}{unit}" if unit != "B" else f"{int(value)}B"
        value /= step
    return f"{value:.1f}TiB"
