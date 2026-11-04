"""Persistent state of the one site (``state_dir/state.json``)."""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from .util import read_json, write_json

# state files live outside the work tree: inside it, git add -A and the mirror would pick them up


@dataclass
class SiteState:
    """The one site's persisted bookkeeping: last build, build sequence and the bootstrap marker."""

    path: Path
    data: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def load(cls, state_dir: Path) -> "SiteState":
        # single site: one state file, no <site id>.json any more
        path = Path(state_dir) / "state.json"
        data = read_json(path, default={}) or {}
        if not isinstance(data, dict):
            data = {}
        return cls(path=path, data=data)

    def save(self) -> None:
        self.data["updated_at"] = time.time()
        write_json(self.path, self.data)

    def get(self, key: str, default: Any = None) -> Any:
        return self.data.get(key, default)

    def set(self, key: str, value: Any) -> None:
        self.data[key] = value
        self.save()

    def update(self, **values: Any) -> None:
        self.data.update(values)
        self.save()

    @property
    def last_build(self) -> dict[str, Any] | None:
        return self.data.get("last_build")

    def record_build(self, record: dict[str, Any]) -> None:
        self.data["last_build"] = record
        history = self.data.setdefault("build_history", [])
        history.append(
            {
                "build_id": record.get("build_id"),
                "ok": record.get("ok"),
                "ts": record.get("ts"),
                "ms": record.get("ms"),
            }
        )
        del history[:-30]  # keep the most recent 30 entries only
        self.save()

    @property
    def next_build_id(self) -> int:
        value = int(self.data.get("build_seq", 0)) + 1
        self.data["build_seq"] = value
        self.save()
        return value

    @property
    def last_onlinepreview_build(self) -> dict[str, Any] | None:
        """The last build of the review tree (``onlinepreview``), kept apart from ``last_build``."""
        return self.data.get("last_onlinepreview_build")

    def record_onlinepreview_build(self, record: dict[str, Any]) -> None:
        """Record a build of the review tree in its own key.

        The two trees are built independently, so their records are independent: ``last_build`` is
        the author preview the app reads (the ``/site/log`` route and the status panel), and a review
        build must neither replace it nor appear in its build history.
        """
        self.data["last_onlinepreview_build"] = record
        self.save()

    @property
    def bootstrapped(self) -> bool:
        return bool(self.data.get("bootstrapped"))

    def mark_bootstrapped(self, detail: dict[str, Any]) -> None:
        self.data["bootstrapped"] = True
        self.data["bootstrap_detail"] = detail
        self.data["bootstrapped_at"] = time.time()
        self.save()
