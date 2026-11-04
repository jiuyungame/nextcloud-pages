"""Mirror engine: one-way sync from the Nextcloud source into the commit and preview trees."""

from __future__ import annotations

import os
import shutil
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable, Iterable, Sequence

from .config import AUTHOR_ROOT_DEFAULT, AuthorEntry, AuthorSource
from .util import MirrorDeleteGuard, human_bytes, info, safe_join, sha256_file, warn


@dataclass(frozen=True)
class Exclusion:
    """Exclude by path-component name or by relative path prefix."""

    names: frozenset[str] = frozenset()
    prefixes: tuple[tuple[str, ...], ...] = ()

    def matches(self, parts: Sequence[str]) -> bool:
        for part in parts:
            if part in self.names:
                return True
        for prefix in self.prefixes:
            if len(parts) >= len(prefix) and tuple(parts[: len(prefix)]) == prefix:
                return True
        return False

    @classmethod
    def for_site(
        cls,
        names: Iterable[str],
        output_dir: str,
        extra_prefixes: Iterable[str] = (),
    ) -> "Exclusion":
        """Build the policy for a site: names, plus the output dir and .gitignore prefixes."""
        prefixes: list[tuple[str, ...]] = []

        def add(entry: str) -> None:
            parts = tuple(p for p in Path(entry).parts if p not in ("", "."))
            if parts and parts not in prefixes:
                prefixes.append(parts)

        add(output_dir)
        for entry in extra_prefixes:
            add(entry)
        return cls(names=frozenset(names), prefixes=tuple(prefixes))


@dataclass
class MirrorResult:
    ok: bool = True
    direction: str = "forward"
    copied: int = 0
    deleted: int = 0
    unchanged: int = 0
    retimed: int = 0          # same content, mtime only: sync the timestamp instead of copying
    scanned_src: int = 0
    scanned_dst: int = 0
    bytes_copied: int = 0
    drift: list[str] = field(default_factory=list)
    in_sync: bool = True
    dry_run: bool = False
    guard_tripped: bool = False
    guard_message: str | None = None
    ms: int = 0
    #: One entry per author source, in config order; empty for a single-source site, and then this
    #: result is byte for byte the one the bridge reported before multi-source existed.
    authors: list[dict] = field(default_factory=list)
    #: False when an author round failed (a tripped delete guard): the work copy is not complete.
    authors_ok: bool = True
    #: The round of the other tree. One directory is not enough any more: the commit tree holds the
    #: on-site set, the preview tree holds every file and is the one that is built. The preview round
    #: reports itself in the same shape, nested here, so the top-level counters keep meaning "the
    #: commit tree" - what a publish sees.
    preview: "MirrorResult | None" = None
    #: The preflight's structured refusal (``error``/``message``/``tree``/``old``/``new``) when the
    #: round was refused before it cleared anything. Deliberately **not** part of :meth:`to_dict`:
    #: the answer shape of a round is frozen, and only a caller that must report *why* it was refused
    #: (init) reads this field; the serialised answer keeps ``guard_tripped``/``guard_message``.
    refusal: dict | None = None

    def to_dict(self) -> dict:
        out = {
            "ok": self.ok,
            "direction": self.direction,
            "copied": self.copied,
            "deleted": self.deleted,
            "unchanged": self.unchanged,
            "retimed": self.retimed,
            "scanned_src": self.scanned_src,
            "scanned_dst": self.scanned_dst,
            "bytes_copied_human": human_bytes(self.bytes_copied),
            "in_sync": self.in_sync,
            "dry_run": self.dry_run,
            "ms": self.ms,
        }
        if self.drift:
            out["drift"] = self.drift[:50]
            out["drift_total"] = len(self.drift)
        if self.guard_tripped:
            out["guard_tripped"] = True
            out["guard_message"] = self.guard_message
        if self.authors:
            out["authors"] = self.authors
            out["authors_ok"] = self.authors_ok
        if self.preview is not None:
            out["preview"] = self.preview.to_dict()
        return out


FileMeta = tuple[int, int]  # (size, mtime_ns)


def scan(root: Path, excl: Exclusion) -> dict[str, FileMeta]:
    """List non-excluded files under ``root`` as ``{relative path: (size, mtime_ns)}``."""
    # the mirror unit is a file, so empty directories never appear (git ignores them too)
    result: dict[str, FileMeta] = {}
    if not root.is_dir():
        return result
    root_str = str(root)
    for dirpath, dirnames, filenames in os.walk(root_str):
        rel_dir = os.path.relpath(dirpath, root_str)
        dir_parts = () if rel_dir == "." else Path(rel_dir).parts
        if dir_parts and excl.matches(dir_parts):
            dirnames[:] = []
            continue
        dirnames[:] = sorted(
            d for d in dirnames if not excl.matches((*dir_parts, d))
        )
        for name in filenames:
            if excl.matches((*dir_parts, name)):
                continue
            rel = os.path.join(rel_dir, name) if rel_dir != "." else name
            try:
                st = os.stat(os.path.join(dirpath, name))
            except OSError:
                continue
            result[rel] = (st.st_size, st.st_mtime_ns)
    return result


def _target_mode(path: Path, preserve_exec: bool, dst: Path | None = None) -> int:
    """Mode to use at the target: keep an existing file's mode, else 644 (755 when preserving exec)."""
    # keeping the target mode avoids spurious 100755 => 100644 churn in git status
    if dst is not None and dst.exists():
        try:
            return os.stat(dst).st_mode & 0o777
        except OSError:
            pass
    if not preserve_exec:
        return 0o644
    try:
        src_mode = os.stat(path).st_mode
    except OSError:
        return 0o644
    return 0o755 if src_mode & 0o100 else 0o644


def _copy_file(src: Path, dst: Path, preserve_exec: bool = False) -> int:
    dst.parent.mkdir(parents=True, exist_ok=True)
    mode = _target_mode(src, preserve_exec, dst)
    # copy to a temp file and replace, so a reader never sees a half-written file
    tmp = dst.with_name(f".{dst.name}.mirror{os.getpid()}")
    try:
        shutil.copy2(src, tmp)          # copy2 keeps mtime, so the next round compares stable
        os.chmod(tmp, mode)
        os.replace(tmp, dst)
    finally:
        if tmp.exists():
            try:
                tmp.unlink()
            except OSError:
                pass
    return dst.stat().st_size


@dataclass
class MirrorPlan:
    to_copy: list[str] = field(default_factory=list)
    to_retime: list[str] = field(default_factory=list)
    to_delete: list[str] = field(default_factory=list)
    changed: list[str] = field(default_factory=list)
    unchanged: int = 0
    src_count: int = 0
    dst_count: int = 0


def plan(
    src_root: Path,
    dst_root: Path,
    excl: Exclusion,
    verify: str = "size+mtime",
) -> MirrorPlan:
    """Compare the two trees and return what to copy, retime and delete; nothing is written yet."""
    # excluded entries are invisible on both sides, so .git and build output are never deleted
    src = scan(src_root, excl)
    dst = scan(dst_root, excl)
    result = MirrorPlan(src_count=len(src), dst_count=len(dst))

    for rel, meta in src.items():
        other = dst.get(rel)
        if other is None:
            result.to_copy.append(rel)
            result.changed.append(rel)
            continue
        if other == meta:
            result.unchanged += 1
            continue
        # same size, different mtime: sync the timestamp only when the content matches
        if other[0] == meta[0]:
            try:
                if sha256_file(src_root / rel) == sha256_file(dst_root / rel):
                    result.to_retime.append(rel)
                    result.unchanged += 1
                    continue
            except OSError:
                pass
        result.to_copy.append(rel)
        result.changed.append(rel)

    result.to_delete = sorted(set(dst) - set(src))
    return result


def _prune_empty_dirs(root: Path, excl: Exclusion) -> int:
    """Remove directories left empty by the mirror, deepest first."""
    removed = 0
    for dirpath, _dirnames, _filenames in os.walk(root, topdown=False):
        current = Path(dirpath)
        if current == root:
            continue
        rel_parts = Path(os.path.relpath(dirpath, root)).parts
        if excl.matches(rel_parts):
            continue
        try:
            # os.walk's dirnames is a stale snapshot, so re-read the disk instead
            if any(current.iterdir()):
                continue
            current.rmdir()
            removed += 1
        except OSError:
            pass
    return removed


def sync(
    src_root: Path,
    dst_root: Path,
    excl: Exclusion,
    *,
    verify: str = "size+mtime",
    delete_guard_ratio: float = 0.2,
    delete_guard_min: int = 20,
    force: bool = False,
    dry_run: bool = False,
    direction: str = "forward",
    preserve_exec: bool = False,
    keep: Callable[[Sequence[str]], set[str]] | None = None,
) -> MirrorResult:
    """Mirror ``src_root`` into ``dst_root``; ``force=True`` skips the delete guard.

    ``keep`` is the last word on deletion: it is called with the planned deletions and returns the
    ones the caller protects (the preview tree protects whatever the repository's ignore rules cover,
    so a dependency tree the build created there is never mistaken for a stale source file). It only
    narrows the plan, so a delete guard sees the deletions that will really happen.
    """
    started = time.monotonic()
    result = MirrorResult(direction=direction, dry_run=dry_run)

    # a missing source raises; a tripped guard returns ok=False so the caller can force it
    if not src_root.is_dir():
        raise MirrorDeleteGuard(
            "source directory does not exist; refusing to mirror (it would empty the work copy)", source=str(src_root)
        )

    p = plan(src_root, dst_root, excl, verify)
    if keep is not None and p.to_delete:
        protected = keep(p.to_delete)
        if protected:
            p.to_delete = [rel for rel in p.to_delete if rel not in protected]
    result.scanned_src = p.src_count
    result.scanned_dst = p.dst_count
    result.unchanged = p.unchanged
    result.drift = p.changed

    budget = max(delete_guard_min, int(p.dst_count * delete_guard_ratio))
    if p.to_delete and len(p.to_delete) > budget and not force:
        result.ok = False
        result.guard_tripped = True
        result.guard_message = (
            f"{len(p.to_delete)} entries to delete exceed the budget of {budget} "
            f"(target holds {p.dst_count} files, ratio threshold {delete_guard_ratio:.0%}). "
            "Aborted; check the exclusion set and site paths, then retry with force=true."
        )
        warn("mirror aborted: delete budget exceeded", direction=direction, to_delete=len(p.to_delete), budget=budget)
        result.ms = int((time.monotonic() - started) * 1000)
        return result

    if dry_run:
        result.copied = len(p.to_copy)
        result.deleted = len(p.to_delete)
        result.retimed = len(p.to_retime)
        result.in_sync = not p.to_copy and not p.to_delete
        result.ms = int((time.monotonic() - started) * 1000)
        return result

    dst_root.mkdir(parents=True, exist_ok=True)

    for rel in p.to_retime:
        # same content: align the timestamp so the next round sees no difference
        try:
            st = os.stat(src_root / rel)
            os.utime(dst_root / rel, ns=(st.st_atime_ns, st.st_mtime_ns))
            result.retimed += 1
        except OSError as exc:
            warn("failed to align timestamp, skipping", path=rel, error=str(exc))

    for rel in p.to_copy:
        try:
            result.bytes_copied += _copy_file(src_root / rel, dst_root / rel, preserve_exec)
            result.copied += 1
        except OSError as exc:
            warn("copy failed, skipping", path=rel, error=str(exc))

    for rel in p.to_delete:
        target = dst_root / rel
        try:
            target.unlink()
            result.deleted += 1
        except OSError as exc:
            warn("delete failed, skipping", path=rel, error=str(exc))
    if result.deleted:
        _prune_empty_dirs(dst_root, excl)

    result.in_sync = not p.to_copy and not p.to_delete
    result.ms = int((time.monotonic() - started) * 1000)
    info(
        "mirror finished",
        direction=direction,
        copied=result.copied,
        deleted=result.deleted,
        retimed=result.retimed,
        unchanged=result.unchanged,
        ms=result.ms,
    )
    return result


def drift_report(src_root: Path, dst_root: Path, excl: Exclusion, verify: str = "size+mtime") -> dict:
    """Compare without writing anything, for the status panel."""
    p = plan(src_root, dst_root, excl, verify)
    return {
        "in_sync": not p.to_copy and not p.to_delete,
        "changed": p.changed[:50],
        "changed_total": len(p.changed),
        "pending_copy": len(p.to_copy),
        "pending_delete": len(p.to_delete),
        "pending_retime": len(p.to_retime),
        "src_count": p.src_count,
        "dst_count": p.dst_count,
    }


# ── author sources: an additive whitelist per author, deletion only when it is named ─────────────
#
# The skeleton mirror diffs two directory trees; an author source cannot be diffed like that: the
# author's folder holds their whole Nextcloud space, only a few files of it belong to the site, and
# the work copy holds the other authors' files too. So an author round is driven by the explicit
# entry list the app pushed. Two consequences, both deliberate:
#
#   * a file that disappeared from the author's folder is reported ``missing`` and its target is
#     left alone - the bridge never infers a deletion from a diff;
#   * the only targets ever deleted are the ones the app named in ``removed``, and even those go
#     through the delete guard the skeleton mirror uses.
#
# A vanished author folder (an unmounted data directory, a renamed user) therefore cannot empty the
# site: every entry becomes ``missing`` and the removals of that round are withheld.


@dataclass
class AuthorMirrorResult:
    """What one author round did; the counters the status panel reports.

    ``in_sync`` follows :class:`MirrorResult`: it says whether the round *had* anything to do, so a
    round that copied files reports false even though it finished ok. The live answer to "is the work
    copy what the app pushed" is ``author_drift()["in_sync"]``, which the status panel uses.
    """

    uid: str = ""
    entries: int = 0
    #: The on-site subset the app pushed (0 when it pushed none: the commit list is ``entries``).
    onsite: int = 0
    ok: bool = True
    copied: int = 0                                     # new targets written
    updated: int = 0                                    # existing targets rewritten
    deleted: int = 0                                    # targets actually removed
    unchanged: int = 0
    retimed: int = 0
    bytes_copied: int = 0
    missing: list[str] = field(default_factory=list)    # entry targets whose source is gone
    removed: list[str] = field(default_factory=list)    # targets this round deleted
    withheld: list[str] = field(default_factory=list)   # removals refused (source folder gone)
    skipped: list[str] = field(default_factory=list)    # excluded by the site policy, never copied
    source_missing: bool = False
    in_sync: bool = True
    dry_run: bool = False
    guard_tripped: bool = False
    guard_message: str | None = None
    ms: int = 0

    def to_dict(self) -> dict:
        out = {
            "uid": self.uid,
            "ok": self.ok,
            "entries": self.entries,
            "on_site": self.onsite,
            "copied": self.copied,
            "updated": self.updated,
            "deleted": self.deleted,
            "unchanged": self.unchanged,
            "retimed": self.retimed,
            "bytes": self.bytes_copied,
            "bytes_copied_human": human_bytes(self.bytes_copied),
            "missing": self.missing[:50],
            "missing_total": len(self.missing),
            "removed": self.removed[:50],
            "removed_total": len(self.removed),
            "in_sync": self.in_sync,
            "dry_run": self.dry_run,
            "ms": self.ms,
        }
        if self.source_missing:
            out["source_missing"] = True
        if self.withheld:
            out["withheld"] = self.withheld[:50]
        if self.skipped:
            out["skipped"] = self.skipped[:50]
        if self.guard_tripped:
            out["guard_tripped"] = True
            out["guard_message"] = self.guard_message
        return out


@dataclass
class AuthorPlan:
    """What one author round would do; ``sources`` maps a target back to its source-relative path."""

    to_copy: list[str] = field(default_factory=list)
    to_update: list[str] = field(default_factory=list)
    to_retime: list[str] = field(default_factory=list)
    to_delete: list[str] = field(default_factory=list)
    missing: list[str] = field(default_factory=list)
    skipped: list[str] = field(default_factory=list)
    unchanged: int = 0
    entries: int = 0
    source_missing: bool = False
    sources: dict[str, str] = field(default_factory=dict)


def _stat_meta(path: Path) -> FileMeta | None:
    try:
        st = os.stat(path)
    except OSError:
        return None
    return (st.st_size, st.st_mtime_ns)


def _same_content(src: Path, dst: Path, verify: str) -> bool:
    """Whether two files hold the same bytes, by hash only when hashing is asked for or sizes match."""
    try:
        if verify != "sha256" and src.stat().st_size != dst.stat().st_size:
            return False
        return sha256_file(src) == sha256_file(dst)
    except OSError:
        return False


def author_footprint(work_root: Path, uid: str, author_root: str = AUTHOR_ROOT_DEFAULT) -> int:
    """How many files one author has in the work copy: the denominator of that author's guard.

    The only area an author may write into is the current ``<author_root>/<uid>`` tree.
    """
    base = work_root / author_root / uid
    if not base.is_dir():
        return 0
    return sum(1 for path in base.rglob("*") if path.is_file())


def plan_author(
    source_root: Path,
    work_root: Path,
    excl: Exclusion,
    author: AuthorSource,
    verify: str = "size+mtime",
    *,
    entries: Sequence[AuthorEntry] | None = None,
    stale: Sequence[str] = (),
) -> AuthorPlan:
    """Plan one author round from one pushed entry list; nothing is written, nothing is deleted.

    ``entries`` selects the list to mirror: the preview tree mirrors every entry, the commit tree
    only the on-site subset. ``stale`` names targets to drop from this tree although the app did not
    call them removed - the commit tree uses it for a page that was turned internal (still in the
    author area, no longer on the site). Both kinds of deletion keep the same guard.
    """
    # a whole-tree scan is useless here (the author's folder is their whole Nextcloud space), but
    # the per-file metadata comes from the same walk as everywhere else; only names can exclude a
    # source file, because the prefixes of an exclusion are addresses in the work copy
    found = scan(source_root, Exclusion(names=excl.names)) if source_root.is_dir() else {}
    sources = {rel.replace(os.sep, "/"): meta for rel, meta in found.items()}
    wanted = author.entries if entries is None else entries

    plan = AuthorPlan(entries=len(wanted), source_missing=not source_root.is_dir())
    planned: set[str] = set()

    for entry in wanted:
        if excl.matches(Path(entry.target).parts) or any(
            part in excl.names for part in Path(entry.path).parts
        ):
            # what the site excludes is not source, on either side
            plan.skipped.append(entry.target)
            continue
        meta = sources.get(entry.path)
        if meta is None:
            plan.missing.append(entry.target)
            continue
        planned.add(entry.target)
        plan.sources[entry.target] = entry.path
        current = _stat_meta(work_root / entry.target)
        if current is None:
            plan.to_copy.append(entry.target)
        elif current == meta:
            plan.unchanged += 1
        elif _same_content(source_root / entry.path, work_root / entry.target, verify):
            plan.to_retime.append(entry.target)
            plan.unchanged += 1
        else:
            plan.to_update.append(entry.target)

    # an explicit removal, and an entry of the same round wins over it: the app's own list of
    # files is more recent than its list of removals, and copying over deleting loses nothing
    for target in (*author.removed, *stale):
        if target in planned or target in plan.skipped or excl.matches(Path(target).parts):
            continue
        if target in plan.to_delete:
            continue
        if _stat_meta(work_root / target) is not None:
            plan.to_delete.append(target)
    return plan


def author_drift(
    source_root: Path,
    work_root: Path,
    excl: Exclusion,
    author: AuthorSource,
    verify: str = "size+mtime",
    *,
    entries: Sequence[AuthorEntry] | None = None,
    stale: Sequence[str] = (),
) -> dict:
    """Compare without writing anything, for the status panel."""
    p = plan_author(source_root, work_root, excl, author, verify, entries=entries, stale=stale)
    return {
        "in_sync": not (p.to_copy or p.to_update or p.to_delete) and not p.missing,
        "pending_copy": p.to_copy[:50],
        "pending_update": p.to_update[:50],
        "pending_delete": p.to_delete[:50],
        "pending_retime": p.to_retime[:50],
        "missing": p.missing[:50],
        "skipped": p.skipped[:50],
        "entries": p.entries,
        "source_missing": p.source_missing,
    }


def _prune_empty_parents(work_root: Path, target: Path, keep_parts: int) -> None:
    """Remove the directories a deleted target leaves empty, never the shared parent itself."""
    parent = target.parent
    while parent != work_root:
        try:
            if len(parent.relative_to(work_root).parts) < keep_parts or any(parent.iterdir()):
                return
            parent.rmdir()
        except (OSError, ValueError):
            return
        parent = parent.parent


def sync_author(
    source_root: Path,
    work_root: Path,
    excl: Exclusion,
    author: AuthorSource,
    *,
    verify: str = "size+mtime",
    delete_guard_ratio: float = 0.2,
    delete_guard_min: int = 20,
    force: bool = False,
    dry_run: bool = False,
    preserve_exec: bool = False,
    author_root: str = AUTHOR_ROOT_DEFAULT,
    entries: Sequence[AuthorEntry] | None = None,
    stale: Sequence[str] = (),
) -> AuthorMirrorResult:
    """Mirror one list of one author's files into one tree.

    Additive: every entry is copied to its target (parents created), an entry whose source file is
    gone is reported ``missing`` and its target is left as it is, and only a target the app named in
    ``removed`` (or one the caller named in ``stale``: a page that was turned internal, for the
    commit tree) is deleted - guarded by the same ratio as the skeleton mirror, and withheld
    entirely while the author's source directory does not exist.
    """
    started = time.monotonic()
    wanted = author.entries if entries is None else entries
    result = AuthorMirrorResult(
        uid=author.uid,
        entries=len(wanted),
        onsite=len(author.commit_entries) if author.onsite_pushed else 0,
        dry_run=dry_run,
    )
    plan = plan_author(source_root, work_root, excl, author, verify, entries=wanted, stale=stale)
    result.missing = list(plan.missing)
    result.skipped = list(plan.skipped)
    result.unchanged = plan.unchanged
    result.source_missing = plan.source_missing

    to_delete = list(plan.to_delete)
    if to_delete and plan.source_missing and not force:
        # the folder holding this author's files is not there at all: an unmounted data directory, a
        # renamed or deleted user. Whatever the app derived from that must not reach the site.
        result.withheld = to_delete
        to_delete = []
        warn(
            "author source directory is gone: its removals are withheld",
            uid=author.uid,
            source=str(source_root),
            withheld=len(result.withheld),
            hint="restore the folder, or repeat with force=true if the removals are really meant",
        )

    footprint = author_footprint(work_root, author.uid, author_root) if to_delete else 0
    if to_delete and not force:
        budget = max(delete_guard_min, int(footprint * delete_guard_ratio))
        if len(to_delete) > budget:
            result.ok = False
            result.guard_tripped = True
            result.guard_message = (
                f"{len(to_delete)} targets to delete exceed the budget of {budget} "
                f"(author {author.uid} has {footprint} files in the work copy, ratio threshold "
                f"{delete_guard_ratio:.0%}). Aborted; check the author's entries and removed list, "
                "then retry with force=true."
            )
            warn(
                "author mirror aborted: delete budget exceeded",
                uid=author.uid,
                to_delete=len(to_delete),
                budget=budget,
            )
            result.in_sync = False
            result.ms = int((time.monotonic() - started) * 1000)
            return result

    if dry_run:
        result.copied = len(plan.to_copy)
        result.updated = len(plan.to_update)
        result.retimed = len(plan.to_retime)
        result.deleted = len(to_delete)
        result.in_sync = not (plan.to_copy or plan.to_update or to_delete or result.withheld) and not plan.missing
        result.ms = int((time.monotonic() - started) * 1000)
        return result

    work_root.mkdir(parents=True, exist_ok=True)

    for target in plan.to_retime:
        # same content: align the timestamp so the next round sees no difference
        try:
            st = os.stat(source_root / plan.sources[target])
            os.utime(work_root / target, ns=(st.st_atime_ns, st.st_mtime_ns))
            result.retimed += 1
        except OSError as exc:
            warn("failed to align timestamp, skipping", uid=author.uid, target=target, error=str(exc))

    for target in [*plan.to_copy, *plan.to_update]:
        # safe_join is belt and braces: the target was validated, and it still cannot leave the tree
        dst = safe_join(work_root, target)
        try:
            result.bytes_copied += _copy_file(source_root / plan.sources[target], dst, preserve_exec)
            if target in plan.to_copy:
                result.copied += 1
            else:
                result.updated += 1
        except OSError as exc:
            warn("copy failed, skipping", uid=author.uid, target=target, error=str(exc))

    for target in to_delete:
        dst = safe_join(work_root, target)
        try:
            dst.unlink()
            result.deleted += 1
            result.removed.append(target)
            # the author's own directory stays while it holds files; the shared author root is
            # never removed, so the next round finds it again
            _prune_empty_parents(work_root, dst, 2)
        except OSError as exc:
            warn("delete failed, skipping", uid=author.uid, target=target, error=str(exc))

    # a withheld removal is work this round refused to do, so it keeps the author out of sync
    result.in_sync = not (
        plan.to_copy or plan.to_update or to_delete or result.withheld
    ) and not plan.missing
    result.ms = int((time.monotonic() - started) * 1000)
    if result.bytes_copied or result.deleted or result.missing or result.withheld:
        info(
            "author mirror finished",
            uid=author.uid,
            copied=result.copied,
            updated=result.updated,
            deleted=result.deleted,
            missing=len(result.missing),
            withheld=len(result.withheld),
            ms=result.ms,
        )
    return result
