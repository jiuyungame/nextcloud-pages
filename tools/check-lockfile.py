#!/usr/bin/env python3
"""Check that package.json and package-lock.json are in sync (so `npm ci` works after a fresh clone).

    python3 tools/check-lockfile.py
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

# Windows consoles default to GBK where print("✓ …") raises UnicodeEncodeError, so force UTF-8
for stream in (sys.stdout, sys.stderr):
    try:
        stream.reconfigure(encoding="utf-8", errors="replace")
    except (AttributeError, ValueError):
        pass

# this script lives in tools/, so the app root is one level up
ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    """Return 0 when the lock covers the manifest exactly, 1 when it does not (details on stdout)."""
    manifest = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
    lock_path = ROOT / "package-lock.json"
    if not lock_path.is_file():
        print("✗ no package-lock.json - dependencies are not locked, others may install different versions")
        return 1
    lock = json.loads(lock_path.read_text(encoding="utf-8"))

    problems: list[str] = []

    if lock.get("lockfileVersion", 0) < 2:
        problems.append(f"lockfileVersion={lock.get('lockfileVersion')}, too low")

    root_pkg = (lock.get("packages") or {}).get("")
    if root_pkg is None:
        problems.append("no root package entry in the lock (packages[\"\"])")
    else:
        for field in ("dependencies", "devDependencies"):
            declared = manifest.get(field) or {}
            locked = root_pkg.get(field) or {}
            for name, spec in sorted(declared.items()):
                if name not in locked:
                    problems.append(f"{name} from {field} is not in the lock root dependencies")
                elif locked[name] != spec:
                    problems.append(f"{name} version range mismatch: package.json={spec} lock={locked[name]}")
            for name in sorted(set(locked) - set(declared)):
                problems.append(f"{name} in {field} exists only in the lock (removed from the manifest?)")

    # every declared dependency needs a resolved entry
    packages = lock.get("packages") or {}
    for field in ("dependencies", "devDependencies"):
        for name in sorted((manifest.get(field) or {})):
            if f"node_modules/{name}" not in packages:
                problems.append(f"{name} has no resolved entry in the lock (node_modules/{name})")

    total = len(manifest.get("dependencies") or {}) + len(manifest.get("devDependencies") or {})
    print(f"package.json: {len(manifest.get('dependencies') or {})} dependencies + "
          f"{len(manifest.get('devDependencies') or {})} devDependencies")
    print(f"package-lock.json: lockfileVersion={lock.get('lockfileVersion')}, "
          f"{len(packages)} entries")

    if problems:
        print(f"\n✗ {len(problems)} mismatches - `npm ci` will fail on other machines:")
        for item in problems[:20]:
            print(f"    {item}")
        return 1

    print(f"\n✓ in sync ({total} declared dependencies all have entries in the lock)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
