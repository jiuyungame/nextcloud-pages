"""HTTP layer: routing, auth, single-site serialization and debounce scheduling."""

from __future__ import annotations

import base64
import hashlib
import json
import mimetypes
import os
import re
import shutil
import threading
import time
from contextlib import contextmanager
from dataclasses import dataclass, field, replace
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Callable, Iterable, Mapping
from urllib.parse import parse_qs, unquote, urlparse

from . import PROTOCOL, __version__, gitops, pairing
from .builder import env_report, run_build
from .config import (
    ARTIFACT_STEM,
    FALLBACK_EXCLUDE,
    SECRET_KEYS,
    UNPAIRED_FLAG,
    Config,
    Site,
    assemble_config,
    author_target_prefixes,
    check_nc_data_root,
    effective_globals,
    global_sources,
    note_bootstrap_split,
    parse_listen,
    validate_globals,
    validate_secret,
)
from .mirror import Exclusion, MirrorResult, author_drift, drift_report, sync, sync_author
from .session import issue, verify, verify_bearer
from .state import SiteState
from .util import (
    AlreadyPaired,
    AuthorRootMoveFailed,
    BadRequest,
    BridgeError,
    ConfigError,
    Conflict,
    ConfirmRequired,
    DigestMismatch,
    Forbidden,
    GitError,
    PathTraversal,
    Unpaired,
    WorkDrift,
    atomic_write_bytes,
    atomic_write_text,
    debug,
    info,
    is_within,
    read_json,
    safe_join,
    tail_lines,
    warn,
    write_json,
)

SERVER_VERSION = f"pages-bridge/{__version__}"

#: Upper bound of one file ``GET /site/published-content`` hands back: the bytes travel
#: base64-encoded inside JSON, so a larger one would be a larger allocation on both ends than the one
#: page the app needs. A module constant (not a config key) because it is a transport limit, and a
#: test may lower it to check the refusal without writing a huge file.
PUBLISHED_CONTENT_MAX_BYTES = 5 << 20

#: The name :func:`~pages_bridge.util.atomic_write_bytes` writes before it renames a file into
#: place: ``.<name>.tmp<pid>``. A process killed between the write and the rename leaves it behind
#: (the ``finally`` that removes it does not run), and it is not site content: it must never be read
#: into a tree, and a publish must never commit it. Only the **name** is matched, so nothing else is
#: ever picked up.
_ATOMIC_TMP_RE = re.compile(r"\..*\.tmp[0-9]+\Z")

#: How long a caller should wait before it retries the one-shot author overwrite after an attempt
#: failed. The app calls the overwrite from an ``injectFn`` on every request and only marks it done
#: after the bridge accepted it, so a failed round is retried on the next request - the bridge cannot
#: stop that (and an unreachable bridge answers nothing at all). It can say, in the answer that just
#: failed, when a retry makes sense; see :meth:`App._overwrite`.
OVERWRITE_RETRY_AFTER_MS = 60_000


class _LayerUnreadable(Exception):
    """A layer directory that exists but could not be walked (a lost mount, a permission change).

    :meth:`App._layer_files` answers ``{}`` both for "the folder is not there" and for "the folder
    could not be read". An assembly that reads a broken ``source/`` as an empty one clears the
    commit tree and the next ``git add -A`` deletes the whole site from the repository. The
    assembly preflight walks through :meth:`App._scan_layer`, which raises this instead.
    """

    def __init__(self, root: Path, error: OSError) -> None:
        super().__init__(f"{root}: {error}")
        self.root = root
        self.error = error

#: ``sysexits.h`` ``EX_CONFIG`` used to be the exit of a refused start with a lost approved set.
#: That model is gone with the set files: ``work`` is now assembled from ``source/`` + ``online/``
#: and a missing or drifted work tree is **rebuilt**, never a reason to refuse to start.
#: Kept as documentation only; nothing in this module exits with a status any more.


@dataclass
class SiteRuntime:
    #: ``None`` while no site is configured yet (an UNPAIRED bridge starts without one)
    site: Site | None
    lock: threading.Lock = field(default_factory=threading.Lock)
    dirty_at: float | None = None
    #: The review tree's own debounce clock. Two clocks, not one "which tree" flag: a save may mark
    #: the preview while a candidate marks the review tree, and one mark must never swallow the other.
    onlinepreview_dirty_at: float | None = None
    #: Whether the derived review tree has been assembled since this site was adopted. The first
    #: candidate pays for the assembly; the scheduled review round keeps it fresh after.
    onlinepreview_mirrored: bool = False
    generation: int = 0
    last_mirror: dict | None = None
    #: The last assembly of the review tree, reported next to ``last_mirror`` in the status.
    last_onlinepreview_mirror: dict | None = None


def validate_stage_paths(work: Path, raw: Any) -> list[str] | None:
    """Validate the ``stage_paths`` of a publish request: relative addresses inside the work copy.

    ``None`` means the key was not sent at all, which is "stage everything", what publish has always
    done. An empty array is taken literally: nothing is staged.
    """
    if raw is None:
        return None
    if isinstance(raw, str) or not isinstance(raw, (list, tuple)):
        raise BadRequest("stage_paths must be an array of strings", got=repr(raw)[:200])
    paths: list[str] = []
    for value in raw:
        if not isinstance(value, str) or not value.strip():
            raise BadRequest("stage_paths must be an array of strings", got=repr(value)[:200])
        rel = value.strip()
        try:
            target = safe_join(work, rel)
        except PathTraversal as exc:
            raise BadRequest("stage_paths must be relative paths inside the work copy", path=rel) from exc
        # safe_join strips a leading slash, git would not: refuse it instead of staging something else
        if rel.startswith("/") or not is_within(target, work):
            raise BadRequest("stage_paths must be relative paths inside the work copy", path=rel)
        if rel not in paths:
            paths.append(rel)
    return paths


class App:
    """Stateless data-plane executor: the control plane is the PHP app, the lifecycle is systemd.

    The site folder in Nextcloud holds the three inputs; the host holds three derived trees:

    * ``<nc_path>/source/`` - the Jekyll skeleton (administrator-edited, **required**);
    * ``<nc_path>/online/`` - the published content (the bridge writes it on ``publish``/``take-down``);
    * ``<nc_path>/pending/`` - the frozen candidates (the bridge writes it on ``candidate``).

    The three trees are pure functions of those inputs:

    * ``work`` = ``source/`` → the site root, then ``online/`` → the site root (merged flat: the git
      working tree a publish commits from, and the flat repository it pushes);
    * ``preview`` = source → online → the author source files (merged flat: it is what the build reads);
    * ``onlinepreview`` = source → online → pending (merged flat too).

    Every tree may be deleted and rebuilt by **Apply source** (the ``mirror`` op): nothing on the
    host is the only copy of anything. There are no bridge-written target-set files any more; the
    pending/online directories themselves are the record.
    """

    def __init__(self, cfg: Config) -> None:
        self.cfg = cfg
        self.started_at = time.time()
        #: False while the bridge holds no usable secrets: only /health and /pair answer
        self.paired = cfg.paired
        #: The address this process really bound; a pushed listen only takes effect at the next
        #: start, so "does this push need a restart" is answered against this and not against cfg
        self.running_listen = (cfg.listen_host, cfg.listen_port)
        # single site: one runtime, one lock and one state file, nothing is looked up by id
        self.runtime = SiteRuntime(site=cfg.site)
        self.state = SiteState.load(cfg.state_dir)
        self.config_source = cfg.config_source
        self.config_revision = cfg.config_revision
        self._stop = threading.Event()
        self._scheduler: threading.Thread | None = None
        self._load_pushed_site()
        # A push may move state_dir at runtime; the bootstrap files keep using the TOML's directory.
        # Say so once, so an operator does not look for effective.json under the pushed path.
        note_bootstrap_split(cfg)

    @property
    def pushed_config_path(self) -> Path:
        """The app-owned config. It always sits where the TOML points, never where a push points."""
        return self.cfg.bootstrap_state_dir / "effective.json"

    @property
    def legacy_pushed_path(self) -> Path:
        """The pushed config of the previous app version: site only, no global block, no secrets."""
        return self.cfg.bootstrap_state_dir / "sites.json"

    def _bootstrap_state_dirs(self) -> tuple[Path, ...]:
        """Where a bootstrap-state file may live, newest location first.

        The first entry is the TOML's ``state_dir`` and the only one ever **written**. The second is
        legacy: an older bridge wrote the reset marker and the pairing code into the ``state_dir`` in
        force, which a push could have moved. Reads accept both (bootstrap file wins), a clear removes
        both, so no stale marker can survive a fix and no code printed before it becomes unusable.
        """
        bootstrap = Path(self.cfg.bootstrap_state_dir)
        pushed = Path(self.cfg.state_dir)
        return (bootstrap,) if pushed == bootstrap else (bootstrap, pushed)

    def _pair_code_dir(self) -> Path:
        """The directory holding the one-time code: bootstrap first, the pushed one only as legacy."""
        for directory in self._bootstrap_state_dirs():
            if pairing.read(directory) is not None:
                return directory
        # no code anywhere: verify() then answers "expired" exactly as it always did
        return Path(self.cfg.bootstrap_state_dir)

    def use_listen(self, host: str, port: int) -> None:
        """Adopt the address the socket really got (a pushed listen that could not be bound)."""
        self.cfg = replace(self.cfg, listen_host=host, listen_port=port)
        self.running_listen = (host, port)
        info("listening on the address from the config file", host=host, port=port)

    def _load_pushed_site(self) -> None:
        # pushed config overrides the TOML site as soon as it exists. Only the legacy file is read
        # here: effective.json was already merged by load_config, and reading sites.json on top of it
        # would let a stale file from the previous app version win.
        if self.cfg.config_source == "app":
            return
        data = read_json(self.legacy_pushed_path, default=None)
        if not isinstance(data, dict):
            return
        spec: Any = data.get("site")
        if not isinstance(spec, Mapping):
            # a config pushed by a protocol 2 app still carries the sites array: take its first entry
            legacy = data.get("sites")
            first = legacy[0] if isinstance(legacy, list) and legacy else None
            if not isinstance(first, Mapping):
                return
            warn("the pushed config is the old format, push it again from Nextcloud", key="sites")
            spec = first
        try:
            self.apply_pushed_site(
                spec, revision=data.get("revision"), persist=False, source="pushed(disk)"
            )
            info("loaded the site config pushed by the app", revision=data.get("revision"))
        except BridgeError as exc:
            warn("pushed site config is unusable, falling back to TOML", error=exc.code, message=exc.message)

    def _assemble(
        self,
        *,
        site_raw: Any,
        pushed_globals: Mapping[str, Any],
        secrets: Mapping[str, str],
        source: str,
        revision: int | None,
        previous_author_root: str | None = None,
    ) -> Config:
        """Build the candidate configuration for a push; nothing is adopted here."""
        raw: dict[str, Any] = {**self.cfg.toml_raw, **pushed_globals}
        if isinstance(site_raw, Mapping):
            raw["site"] = dict(site_raw)
        raw.update(secrets)
        return assemble_config(
            raw,
            toml_raw=self.cfg.toml_raw,
            app_globals=pushed_globals,
            config_source=source,
            config_revision=revision,
            # the app may push the globals first and the site with the next request
            allow_missing_site=True,
            # the directory in force before this push: a removal may name it, so moving the author
            # directory cleans the old copies up instead of leaving them to be served next to the new
            previous_author_root=previous_author_root,
        )

    def _adopt(self, candidate: Config) -> Config:
        """Install a validated configuration: from here on it is the one in force."""
        self.cfg = candidate
        self.paired = candidate.paired
        self.runtime.site = candidate.site
        # a newly adopted site (or a moved tree) has not been assembled into the review tree yet:
        # the next candidate pays for the assembly instead of writing into a stale tree
        self.runtime.onlinepreview_mirrored = False
        self.config_source = candidate.config_source
        self.config_revision = candidate.config_revision
        # a push may carry a new state_dir: the business state follows it, the bootstrap files stay.
        note_bootstrap_split(candidate)
        return candidate

    def restart_required(self, pushed_globals: Mapping[str, Any]) -> bool:
        """Whether the pushed ``listen`` differs from the address this process is bound to."""
        listen = pushed_globals.get("listen")
        candidate = (self.cfg.listen_host, self.cfg.listen_port) if listen is None else parse_listen(listen)
        return candidate != self.running_listen

    def _store_effective(
        self,
        pushed_globals: Mapping[str, Any],
        site_raw: Mapping[str, Any] | None,
        secrets: Mapping[str, str],
        revision: int | None,
    ) -> None:
        """Write the app-owned config atomically, 0600: the secrets live in this file."""
        payload = {
            "revision": revision,
            "global": dict(pushed_globals),
            # null, not {}: an empty table would read back as "a site with no nc_path" and refuse to
            # start, while "no site pushed yet" is a state an UNPAIRED-then-paired bridge may be in
            "site": dict(site_raw) if site_raw else None,
            "shared_secret": secrets.get("shared_secret", ""),
            "session_signing_secret": secrets.get("session_signing_secret", ""),
        }
        atomic_write_text(
            self.pushed_config_path,
            json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
            mode=0o600,
        )

    # ── the three Nextcloud inputs and the host-side assembly ────────────────────────────────────
    def _nc_dir(self, site: Site, kind: str) -> Path:
        """One of the site folder's three fixed subdirectories: ``source``/``online``/``pending``."""
        if kind not in ("source", "online", "pending"):
            raise BadRequest("unknown site directory", got=repr(kind)[:40], known=["source", "online", "pending"])
        return getattr(site, f"{kind}_dir")

    def _write_nc_file(self, path: Path, content: bytes) -> None:
        """Write one file into the Nextcloud site folder **atomically**.

        The bridge runs as the Nextcloud file owner (root by default) and writes the published and
        pending bytes as ordinary files: no Nextcloud file API, no occ command, no HTTP call. The
        write goes through a temporary sibling and a rename, so a reader (or the Nextcloud scanner)
        never sees half a page.
        """
        self._write_tree_file(path.parent, path.name, content)

    def _delete_nc_file(self, path: Path) -> bool:
        """Delete one file from the Nextcloud site folder; ``True`` when it was there."""
        if path.is_file() or path.is_symlink():
            path.unlink()
            return True
        return False

    def _read_author_file(self, site: Site, target: str, area: str = "Pages") -> bytes:
        """The current bytes of one author source file, read from that author's Nextcloud folder.

        ``target`` is the site-relative target, ``<author_root>/<uid>/<rel>``; ``area`` is the
        author's folder inside their own ``files/`` (``Pages`` by default, or ``Pages/posts`` for
        the older per-folder layout), exactly like the ``area`` field of an author delta. The source
        folder is derived from the target's uid, never from a caller-provided path: one author can
        only ever read their own files.
        """
        prefix = f"{site.author_root}/"
        if not target.startswith(prefix):
            raise BadRequest("target must be under the author directory", target=target[:200])
        rest = target[len(prefix):]
        uid, _, relative = rest.partition("/")
        if not uid or not relative or ".." in relative.split("/"):
            raise BadRequest("target must name a file below the author directory", target=target[:200])
        area = (area or "Pages").strip("/")
        if not area or ".." in area.split("/") or area.startswith("/"):
            raise BadRequest("area must be a folder name", got=repr(area)[:80])
        root = self.cfg.nc_data_root / uid / "files" / area
        try:
            path = safe_join(root, relative)
        except PathTraversal as exc:
            raise BadRequest("target must stay inside the author's folder", target=target[:200]) from exc
        if not path.is_file():
            raise BadRequest(
                "the source file does not exist",
                target=target[:200],
                source=str(root / relative),
            )
        return path.read_bytes()

    def _layer_files(self, root: Path, skip: Exclusion | None = None) -> dict[str, Path]:
        """Every file a layer contributes, keyed by its path relative to the layer root."""
        if not root.is_dir():
            return {}
        found: dict[str, Path] = {}
        for path in sorted(root.rglob("*")):
            # A symlink is not site content: `is_file()` is True for a link to a regular file, and
            # `rglob` does not descend into a linked directory, so without this check one link in
            # `source/` (or in an author folder) would have `_copy_layer` copy the host file it
            # points at into all three trees - and a publish could commit it. A broken link is
            # already skipped by `is_file()`; this refuses the readable ones too.
            if path.is_symlink():
                continue
            if not path.is_file():
                continue
            # The residue of a write that was killed between the temporary file and the rename
            # (:func:`~pages_bridge.util.atomic_write_bytes`) is not site content: copying it would
            # put a half-written page into a tree, and the next publish would commit it.
            if _ATOMIC_TMP_RE.match(path.name):
                continue
            relative = path.relative_to(root)
            if skip is not None and skip.matches(relative.parts):
                continue
            found[relative.as_posix()] = path
        return found

    def _scan_layer(self, root: Path, skip: Exclusion | None = None) -> dict[str, Path]:
        """Like :meth:`_layer_files`, but a directory that cannot be walked is an **error**.

        A missing folder stays an empty layer (``online/`` is empty on a new site). A folder that
        exists and then fails to enumerate - a lost mount, a permission change - must never be read
        as "empty": that is how an assembly emptied a tree and the next commit deleted the site.
        """
        try:
            if not root.is_dir():
                return {}
            return self._layer_files(root, skip)
        except OSError as exc:
            raise _LayerUnreadable(root, exc) from exc

    def _ignore_names(self, site: Site) -> tuple[set[str], list[str]]:
        """The skeleton's ignore policy: excluded path-component names, and relative prefixes.

        This is the **one** record of what the skeleton does not contribute, and both views of it
        read from here: :meth:`_skeleton_exclusion` copies with it, :meth:`_exclusion` reports with
        it. The two used to answer differently - the status panel merged the built-in fallback list
        while the assembly added nothing - so a bridge without a ``.gitignore`` said ``_site/`` was
        ignored and then copied it into all three trees (and a publish could commit it).

        ``names`` are matched against every path component, ``prefixes`` against the leading parts of
        a site-relative path. Two inputs feed them:

        * the configured ``exclude`` names, minus ``unexclude`` (always);
        * the repository's own ignore rules (see :meth:`_gitignored_source_rules`). Only when git
          **cannot answer** - ``mirror_use_gitignore = false``, no repository yet (``git init`` has
          not run), or a failed call - does the built-in :data:`FALLBACK_EXCLUDE` list take their
          place. A rule set that answers "nothing is ignored" is a real answer and does not.

        The recipe's ``BUNDLE_PATH`` is a prefix: it is the dependencies the bridge itself installs,
        which no layer provides and which a missing ``.gitignore`` entry must not turn into a mass
        deletion. Nothing here excludes the output directory: :meth:`Exclusion.for_site` always does.
        """
        unexcluded = {u.strip("/").lower() for u in self.cfg.unexclude}

        def allowed(entry: str) -> bool:
            return entry.strip("/").lower() not in unexcluded

        names = {n.strip("/").lower() for n in site.exclude if allowed(n)}
        prefixes: list[str] = []
        rules = self._gitignored_source_rules(site)
        if rules is None:
            names |= {n for n in FALLBACK_EXCLUDE if allowed(n)}
            # debug, not info: this is the normal state before the first ``git init`` and the
            # configured state with ``mirror_use_gitignore = false``, and the lookup now runs on
            # every assembly round and every status poll - a line each time would drown the log.
            debug("no .gitignore available, using the built-in fallback exclusions", work=str(site.work))
        else:
            prefixes.extend(rules)
        bundle_path = str(site.recipe.env.get("BUNDLE_PATH", "")).strip().strip("/")
        if bundle_path and ".." not in Path(bundle_path).parts and bundle_path not in prefixes:
            prefixes.append(bundle_path)
        return names, prefixes

    def _skeleton_exclusion(self, site: Site) -> Exclusion:
        """What a **skeleton** layer never copies: the repository and the build artifacts.

        The overlay layers (``online/``, ``pending/``, an author folder) are copied verbatim: their
        bytes are data the bridge wrote itself. The skeleton is administrator-edited source, so the
        site's ignore policy applies to it: the configured ``exclude`` names, the recipe's output
        directory and the dependency directory (``BUNDLE_PATH``), plus - with
        ``mirror_use_gitignore``, the default - everything the repository's own ``.gitignore``
        ignores. The answer comes from :meth:`_ignore_names`, the same record the status and drift
        views use (:meth:`_exclusion`), so the tree that is built and the tree that is reported in
        sync cannot disagree.

        The ignore rules are read from ``source/`` itself, never from the assembled commit tree:
        the skeleton **is** the repository content, and an entry the assembly keeps out does not
        exist in ``work``, so asking there would report nothing and let the entry in on the next
        round (the two rounds would flip). A bridge without a repository yet - ``git init`` has not
        run - falls back on the built-in exclusions instead of excluding nothing.
        """
        names, prefixes = self._ignore_names(site)
        return Exclusion.for_site(names, site.recipe.output_dir, prefixes)

    def _gitignored_source_rules(self, site: Site) -> list[str] | None:
        """The ``source/`` entries the repository's own ignore rules keep out of every tree.

        ``None`` means the rules are **unavailable** and the caller has to fall back on the built-in
        list: ``mirror_use_gitignore = false`` disables the lookup (exactly like the status/drift
        view), a bridge whose commit tree is not a repository yet has no ``.gitignore`` to ask about
        before ``git init``, and a failed git call cannot answer either. An empty list is a real
        answer - "nothing is ignored". ``unexclude`` removes an entry from the set, because the
        escape hatch has to mean the same thing everywhere.
        """
        if not self.cfg.use_gitignore:
            return None
        entries = gitops.ignored_source_entries(site.work, site.source_dir)
        if entries is None:
            return None
        unexcluded = {u.strip("/").lower() for u in self.cfg.unexclude}
        kept: list[str] = []
        for entry in entries:
            relative = entry.strip().strip("/")
            if relative and relative.lower() not in unexcluded:
                kept.append(relative)
        return kept

    def _sweep_atomic_residue(self, site: Site) -> int:
        """Delete the temporary files a killed write left in ``online/`` and ``pending/``.

        :func:`~pages_bridge.util.atomic_write_bytes` writes ``.<name>.tmp<pid>`` and renames it
        into place; a ``SIGTERM`` between the two leaves that file behind, because the ``finally``
        that removes it never runs. ``_layer_files`` already refuses to read such a name, and this
        sweep is what actually removes it, so a killed publish cannot leave junk in the site folder
        (and in the tree the next round copies it into) forever.

        Only a **regular file** whose name matches is removed, and only under the two data
        directories: no directory, no symlink (not even one matching the pattern) and nothing in
        ``source/`` - that folder belongs to the administrator. A failure to remove one is logged
        and never raised: the round it is part of has real work to do.
        """
        removed = 0
        for directory in (site.online_dir, site.pending_dir):
            if not directory.is_dir():
                continue
            try:
                candidates = sorted(directory.rglob("*"))
            except OSError as exc:
                warn(
                    "a site data directory could not be read while clearing interrupted writes",
                    dir=str(directory),
                    error=repr(exc),
                )
                continue
            for path in candidates:
                if not _ATOMIC_TMP_RE.match(path.name):
                    continue
                if path.is_symlink() or not path.is_file():
                    continue
                try:
                    path.unlink()
                    removed += 1
                except OSError as exc:
                    warn("failed to remove a temporary file of an interrupted write", path=str(path), error=repr(exc))
        if removed:
            info("removed temporary files left by an interrupted write", count=removed)
        return removed

    def _author_areas(
        self, site: Site, raw: Any = None
    ) -> list[tuple[str, str, Path]]:
        """The author source folders Apply source lays over the skeleton, in order.

        Four records feed it: the ``authors`` array of the request (the app knows its Pages
        authors), the site configuration's ``site.authors`` (a hand-written TOML or an older push),
        the uids already present under ``online/<author_root>/`` or
        ``pending/<author_root>/``, and - last - the directories already present under
        ``preview/<author_root>/``. A uid without a matching ``<uid>/files/<area>`` folder is
        skipped: it contributes nothing, and it must never make Apply source fail.

        The preview fallback exists because a save writes ``preview/<target>`` and no assembly
        follows it any more (the debounce round only builds). An Apply source that read the three
        records alone would drop the draft of an author none of them names yet - a brand new uid,
        which is exactly who just saved. A preview directory is only taken as an author, though,
        when it matches a Nextcloud folder (``<uid>/files/<area>``): ``<author_root>/<orphan>/`` is
        the site source the app writes a handed-over page into, the orphan owner owns no Nextcloud
        account, and so it stays skeleton content - the same line :meth:`author_uids` draws.
        """
        areas: list[tuple[str, str, Path]] = []
        seen: set[tuple[str, str]] = set()
        #: uids an explicit record already named: discovery must not guess a second, wrong area
        named: set[str] = set()
        #: the areas to try for a uid only the preview tree names: the app's default first, then
        #: every area another record named (a site that keeps its authors in a custom folder)
        probes: list[str] = ["Pages"]

        def add(uid: Any, area: Any, *, explicit: bool = False) -> bool:
            if not isinstance(uid, str) or re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", uid) is None:
                return False
            if explicit:
                named.add(uid)
            text = str(area or "Pages").strip().strip("/")
            if not text or ".." in text.split("/"):
                return False
            if text not in probes:
                probes.append(text)
            if (uid, text) in seen:
                return False
            root = self.cfg.nc_data_root / uid / "files" / text
            if not root.is_dir():
                return False
            seen.add((uid, text))
            areas.append((uid, text, root))
            return True

        if isinstance(raw, (list, tuple)):
            for entry in raw:
                if isinstance(entry, Mapping):
                    add(entry.get("uid"), entry.get("area") or entry.get("path"), explicit=True)
        for author in site.authors:
            if author.uid in named:
                # the request named this author already: its area is the more recent statement
                continue
            add(author.uid, author.path.strip("/") if author.path else "Pages", explicit=True)
        for directory in (site.online_dir, site.pending_dir):
            base = directory / site.author_root
            if not base.is_dir():
                continue
            for child in sorted(base.iterdir(), key=lambda path: path.name):
                if child.is_dir() and child.name not in named:
                    add(child.name, "Pages")
        # Fallback: the authors the live preview tree already holds. The uid is read off the
        # directory name (the layer writes ``<author_root>/<uid>/``), and it only becomes an author
        # when a Nextcloud folder of that name exists - see the docstring for why the orphan owner
        # must fail that test.
        preview_base = site.preview / site.author_root
        if preview_base.is_dir():
            found = {uid for uid, _area, _root in areas}
            for child in sorted(preview_base.iterdir(), key=lambda path: path.name):
                if not child.is_dir() or child.name in named or child.name in found:
                    continue
                for probe in probes:
                    if add(child.name, probe):
                        found.add(child.name)
                        break
        return areas

    def _layers(
        self, site: Site, kind: str, areas: list[tuple[str, str, Path]] | None = None
    ) -> list[tuple[Path, str, Exclusion | None]]:
        """The ordered layers one tree is assembled from.

        All three are **merged flat at the tree root**: the commit tree (``work``) is ``source/`` then
        ``online/``, the preview adds each author folder under ``<author_root>/<uid>/``, and the
        review tree adds ``pending/`` last. ``dest_rel`` is where a layer lands, so an author folder
        is the only layer with a non-empty one. Same-name files are overwritten in order (last wins).

        A flat ``work`` is what the remote repository must be: its root is a buildable Jekyll site
        (``_config.yml``, ``_layouts/``, ``authors-src/``…), exactly like the preview tree.
        """
        skeleton = self._skeleton_exclusion(site)
        layers: list[tuple[Path, str, Exclusion | None]] = [
            (site.source_dir, "", skeleton),
            (site.online_dir, "", None),
        ]
        if kind == "work":
            return layers
        if kind == "preview":
            for uid, _area, root in areas or []:
                layers.append((root, f"{site.author_root}/{uid}", None))
        elif kind == "onlinepreview":
            layers.append((site.pending_dir, "", None))
        else:
            raise BadRequest("unknown tree", got=repr(kind)[:40], known=["work", "preview", "onlinepreview"])
        return layers

    @staticmethod
    def _clear_tree(
        tree: Path,
        *,
        keep_git: bool = False,
        keep: Iterable[str] = (),
    ) -> None:
        """Empty one derived tree, keeping ``.git`` when the tree is the commit tree.

        ``keep`` names top-level entries the clear must not touch. The one entry always named is the
        top directory of the recipe's ``BUNDLE_PATH`` (``vendor`` for ``vendor/bundle``): it holds
        the **installed dependencies**, written by ``bundle install`` and provided by no layer of
        the assembly. Emptying it would throw away a completed install and force a networked
        reinstall - and a gem source that is unreachable at that moment fails the next build - so
        the directory survives exactly like the commit tree's repository.
        """
        kept = set(keep)
        if not tree.exists():
            return
        for child in tree.iterdir():
            if keep_git and child.name == ".git":
                continue
            if child.name in kept:
                continue
            if child.is_dir() and not child.is_symlink():
                shutil.rmtree(child)
            else:
                child.unlink()

    def _copy_layer(self, root: Path, destination: Path, skip: Exclusion | None = None) -> int:
        """Copy one layer over a tree; an existing file of the same path is overwritten."""
        copied = 0
        for relative, source in self._layer_files(root, skip).items():
            target = destination / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
            copied += 1
        return copied

    def _bundle_keep(self, site: Site) -> set[str]:
        """The top-level entries a tree clear keeps: ``BUNDLE_PATH``'s first segment.

        One definition for the two places that have to agree on it: :meth:`_assemble_tree` clears
        with it, and :meth:`_preflight` counts the old tree without it. The directory is the
        **installed dependencies** (``bundle install`` wrote it, no layer provides it), so the clear
        steps around it - and a file counted as "removed" although the clear never touches it would
        inflate the delete guard until an ordinary round over a populated ``vendor/`` looked like a
        mass deletion.
        """
        bundle_path = str(site.recipe.env.get("BUNDLE_PATH", "")).strip().strip("/")
        return {Path(bundle_path).parts[0]} if bundle_path else set()

    def _assemble_tree(
        self,
        site: Site,
        tree: Path,
        layers: list[tuple[Path, str, Exclusion | None]],
        *,
        dry_run: bool = False,
    ) -> int:
        """Rebuild ``tree`` from ``layers``: clear it, then copy every layer in order.

        The clear is safe by construction: every input of the assembly lives in the Nextcloud site
        folder (or, for an author folder, in that author's own folder), never in the tree. ``work``
        keeps its ``.git`` directory: it is the git working tree a publish commits from, and the
        repository is not part of the site content. Every tree also keeps the top directory of the
        recipe's ``BUNDLE_PATH``: it is the **installed dependencies** (written by ``bundle install``,
        provided by no layer), so clearing it would force a networked reinstall before the next
        build - see :meth:`_clear_tree` and :meth:`_bundle_keep`. Every layer lands at its
        ``dest_rel`` (the tree root for source/online/pending, ``<author_root>/<uid>`` for an
        author), so ``work``, ``preview`` and ``onlinepreview`` all end up flat.
        """
        keep_git = tree == site.work
        # the dependency directory the recipe installs into (``vendor/bundle`` -> ``vendor``), and
        # nothing at all when BUNDLE_PATH is empty
        keep = self._bundle_keep(site)
        if dry_run:
            return sum(len(self._layer_files(root, skip)) for root, _dest, skip in layers)
        self._clear_tree(tree, keep_git=keep_git, keep=keep)
        copied = 0
        for root, dest_rel, skip in layers:
            destination = tree / dest_rel if dest_rel else tree
            copied += self._copy_layer(root, destination, skip)
        return copied

    @staticmethod
    def _tree_file_count(tree: Path, skip: Exclusion | None = None) -> int:
        """How many files a derived tree currently holds, ignoring ``.git`` and build artifacts.

        ``skip`` is the same exclusion the assembly copies with, so the count is "the files this
        tree is supposed to carry": a built preview tree holds its output directory and the
        ignored junk a build left behind, and neither is a file a rebuild could lose. ``.git`` is
        kept by the clear of the commit tree, so it is never a deletion either.
        """
        if not tree.is_dir():
            return 0
        count = 0
        try:
            for path in tree.rglob("*"):
                if not path.is_file():
                    continue
                parts = path.relative_to(tree).parts
                if parts and parts[0] == ".git":
                    continue
                if skip is not None and skip.matches(parts):
                    continue
                count += 1
        except OSError:  # an unreadable old tree cannot be counted; the clear would fail anyway
            return 0
        return count

    def _assembly_targets(self, layers: list[tuple[Path, str, Exclusion | None]]) -> set[str]:
        """Every tree-relative path the assembly of ``layers`` would produce, without copying."""
        targets: set[str] = set()
        for root, dest_rel, skip in layers:
            prefix = f"{dest_rel}/" if dest_rel else ""
            for relative in self._scan_layer(root, skip):
                targets.add(prefix + relative)
        return targets

    def _preflight(
        self,
        site: Site,
        layers_by_tree: Mapping[str, list[tuple[Path, str, Exclusion | None]]],
        *,
        force: bool = False,
        allow_empty_source: bool = False,
        dry_run: bool = False,
    ) -> dict[str, Any] | None:
        """Refuse an assembly that would empty the site or delete most of a tree, before any clear.

        Two checks, both on the **inputs** and both before a single file is deleted:

        1. the ``source/`` layer must contribute at least one file. An empty, missing or unreadable
           source folder used to assemble into an empty commit tree, and ``git add -A`` then deleted
           the whole site from the repository. A folder that exists but cannot be walked is
           ``SOURCE_UNREADABLE`` - never read as "empty".
        2. the delete guard: a tree that would lose most of its files - ``old > 0``,
           ``new < old * mirror_delete_guard_ratio`` and ``old - new > mirror_delete_guard_min`` -
           is refused with ``MIRROR_DELETE_GUARD`` and ``{old, new, removed}``. Three trees are
           checked separately; one tripped guard refuses the whole round. ``force: true`` in the
           request skips only this check, never the source check. ``old`` is counted in the unit the
           assembly produces: what the skeleton policy skips and what the clear keeps
           (:meth:`_bundle_keep`) is not a file a rebuild could lose, so neither side of the
           subtraction counts it.

        ``dry_run`` only reports, so the guard is not applied (nothing would be deleted); the
        source check still runs, because it is the verdict the real round would give.
        ``allow_empty_source`` is the bootstrap exception: ``do_init`` may assemble a site whose
        skeleton is still empty.
        """
        skeleton = self._skeleton_exclusion(site)
        # The old tree is counted in the unit the assembly works in: the skeleton policy the layers
        # are copied with, plus the top-level entries the clear keeps (``_bundle_keep``). Both
        # sides of ``old - new`` then describe the same tree, so a populated ``vendor/`` is not a
        # deletion and an ignored build artifact is not one either.
        keep_prefixes = tuple((name,) for name in sorted(self._bundle_keep(site)))
        old_skip = (
            Exclusion(names=skeleton.names, prefixes=skeleton.prefixes + keep_prefixes)
            if keep_prefixes
            else skeleton
        )
        trees = {"work": site.work, "preview": site.preview, "onlinepreview": site.onlinepreview}
        counts: dict[str, int] = {}
        try:
            source_files = self._scan_layer(site.source_dir, skeleton)
            for kind, layers in layers_by_tree.items():
                counts[kind] = len(self._assembly_targets(layers))
        except _LayerUnreadable as exc:
            return {
                "ok": False,
                "error": "SOURCE_UNREADABLE",
                "message": (
                    "a site directory exists but could not be read, so nothing was cleared or "
                    f"rebuilt: {exc.root} ({exc.error})"
                ),
                "dir": str(exc.root),
                "detail": repr(exc.error),
            }
        if not source_files and not allow_empty_source:
            return {
                "ok": False,
                "error": "SOURCE_EMPTY",
                "message": (
                    "the site's source directory contributes no file, so nothing was cleared or "
                    "rebuilt: a lost mount or an emptied folder must not empty the published "
                    "repository"
                ),
                "dir": str(site.source_dir),
            }
        if dry_run or force:
            return None
        ratio = float(self.cfg.mirror_delete_guard_ratio)
        minimum = int(self.cfg.mirror_delete_guard_min)
        for kind, new in counts.items():
            old = self._tree_file_count(trees[kind], old_skip)
            removed = old - new
            if old > 0 and new < old * ratio and removed > minimum:
                return {
                    "ok": False,
                    "error": "MIRROR_DELETE_GUARD",
                    "message": (
                        f"the assembly would remove {removed} of the {old} files in the {kind} tree "
                        f"(guard: more than {minimum} files and more than {ratio:.0%} of the tree), "
                        "so nothing was cleared or rebuilt"
                    ),
                    "tree": kind,
                    "old": old,
                    "new": new,
                    "removed": removed,
                    "guard": {"tree": kind, "ratio": ratio, "min": minimum},
                    "hint": "send force:true to run the assembly anyway",
                }
        return None

    def _mirror_preflight(self, site: Site, *, force: bool = False) -> dict[str, Any] | None:
        """The preflight of the two trees :meth:`do_mirror` assembles (work and preview)."""
        areas = self._author_areas(site)
        return self._preflight(
            site,
            {
                "work": self._layers(site, "work"),
                "preview": self._layers(site, "preview", areas),
            },
            force=force,
        )

    def _author_area_files(self, tree: Path, site: Site) -> dict[str, Path]:
        """Every author file one tree holds, keyed by its site-relative target.

        Every author target lives under ``<author_root>/<uid>/...``, so one walk of the author root
        keys them all - there is no second area to reconcile between the two data trees.
        """
        base = tree / site.author_root
        if not base.is_dir():
            return {}
        return {
            path.relative_to(tree).as_posix(): path
            for path in sorted(base.rglob("*"))
            if path.is_file()
        }

    # ── work consistency: publish checks the commit tree, init/startup rebuild it ───────────────
    def assembly_drift(self, site: Site) -> dict[str, Any] | None:
        """The difference between the commit tree and the current ``source/`` + ``online/`` assembly.

        Read-only: nothing is written, cleared or repaired. ``expected`` is the flat merge the
        assembly would produce (``source/`` first, ``online/`` overwriting the same paths), and
        ``actual`` is every file the commit tree holds, with the top-level ``.git`` directory pruned
        instead of walked. Three differences are reported:

        * ``missing``   - the assembly expects the path, the commit tree does not hold it;
        * ``different`` - both hold it and the bytes differ (size first, then the bytes; a read that
          fails counts as different);
        * ``extra``     - the commit tree holds a path the assembly does not produce (a skeleton file
          deleted from ``source/``, a build artifact, a file written by hand).

        ``None`` when all three lists are empty. A layer that exists but cannot be walked raises
        :class:`_LayerUnreadable` through :meth:`_scan_layer`: an unreadable ``source/`` must never
        be compared as an empty one, and the caller refuses the operation.
        """
        expected: dict[str, Path] = self._scan_layer(site.source_dir, self._skeleton_exclusion(site))
        expected.update(self._scan_layer(site.online_dir))
        actual: dict[str, Path] = {}
        if site.work.is_dir():
            for dirpath, dirnames, filenames in os.walk(site.work):
                if Path(dirpath) == site.work:
                    # never descend into the repository: its objects are not site content
                    dirnames[:] = [name for name in dirnames if name != ".git"]
                for name in filenames:
                    path = Path(dirpath) / name
                    if path.is_file():
                        actual[path.relative_to(site.work).as_posix()] = path
        missing = sorted(set(expected) - set(actual))
        extra = sorted(set(actual) - set(expected))
        different: list[str] = []
        for relative in sorted(set(expected) & set(actual)):
            try:
                if expected[relative].stat().st_size != actual[relative].stat().st_size:
                    different.append(relative)
                    continue
                if expected[relative].read_bytes() != actual[relative].read_bytes():
                    different.append(relative)
            except OSError:
                # a file that cannot be read cannot be proven equal: that is a difference
                different.append(relative)
        if not missing and not different and not extra:
            return None
        return {
            "expected_files": len(expected),
            "actual_files": len(actual),
            "missing": len(missing),
            "different": len(different),
            "extra": len(extra),
            # a bounded sample: the report is for a refusal and a log line, not a full diff
            "targets": {
                "missing": missing[:50],
                "different": different[:50],
                "extra": extra[:50],
            },
        }

    def work_drift(self, site: Site, *, deep: bool = True) -> dict[str, Any] | None:
        """The published files the commit tree does not hold, or ``None`` when it is consistent.

        ``online/`` in Nextcloud is the record of what is published; ``work`` is only its assembly.
        The old model kept a bridge-written ``author_external.json`` approved set and refused to
        start when it was gone. That file is gone too: a work tree missing a published file is now
        simply **stale**, and :meth:`rebuild_work` recreates it from ``source/`` + ``online/``.

        The check is deliberately one-directional. Extra files in ``work`` are not a drift: the
        skeleton may have been edited since the last assembly, and Apply source (or init) rebuilds
        the whole tree anyway. Only a published file that is missing from - or differs in - the
        commit tree means the tree lost content it must carry.

        ``deep`` compares bytes; the status panel passes ``deep=False`` and only compares sizes, so
        a polling status route does not read every published file. A repair decision always uses
        the default (byte comparison).

        The comparison is against ``work <relative>``: the commit tree is the flat source + online
        assembly, so a published file has exactly its site-relative path there.
        """
        online = self._layer_files(site.online_dir)
        if not online:
            # nothing published yet: an empty work tree is a fresh install, not a loss
            return None
        missing: list[str] = []
        different: list[str] = []
        unreadable: list[str] = []
        for relative, source in online.items():
            target = site.work / relative
            try:
                if not target.is_file():
                    missing.append(relative)
                    continue
                target_size = target.stat().st_size
                source_size = source.stat().st_size
                if target_size != source_size:
                    different.append(relative)
                elif deep and target.read_bytes() != source.read_bytes():
                    different.append(relative)
            except OSError:
                unreadable.append(relative)
                missing.append(relative)
        if not missing and not different:
            return None
        return {
            "state": "drifted",
            "work": str(site.work),
            "online_dir": str(site.online_dir),
            "online_files": len(online),
            "missing": len(missing),
            "different": len(different),
            "unreadable": len(unreadable),
            # a bounded sample: the report is for a log line and a status panel, not a full diff
            "targets": (missing + different)[:50],
        }

    def rebuild_work(self, site: Site, *, allow_empty_source: bool = False) -> dict[str, Any]:
        """Recreate the commit tree from ``source/`` + ``online/``; ``.git`` is kept.

        The same preflight as Apply source runs first, with ``force`` so the delete guard never
        blocks a rebuild: a repair is meant to replace the tree. The source check is **not** skipped
        - an empty or unreadable ``source/`` is refused (``{"ok": False, "rebuilt": False, ...}``),
        because assembling from it would empty the tree and the next ``git add -A`` would delete the
        site from the repository. ``allow_empty_source`` is the bootstrap exception: only
        :meth:`do_init` may assemble a brand new site whose skeleton is still empty.
        """
        layers = self._layers(site, "work")
        problem = self._preflight(
            site, {"work": layers}, force=True, allow_empty_source=allow_empty_source
        )
        if problem is not None:
            return {"ok": False, "rebuilt": False, **problem}
        files = self._assemble_tree(site, site.work, layers)
        self.ensure_work_repo(site)
        self.runtime.last_mirror = {
            "ok": True,
            "direction": "forward",
            "copied": files,
            "deleted": 0,
            "unchanged": 0,
            "retimed": 0,
            "in_sync": True,
            "rebuilt": True,
        }
        return {"ok": True, "rebuilt": True, "tree": "work", "files": files, "work": str(site.work)}

    def verify_state_files(self, site: Site | None = None) -> dict[str, Any]:
        """Make the commit tree consistent with ``online/``; rebuild it when it is stale.

        This replaces the old "refuse to start on a lost approved set" verdict: nothing on the host
        is load-bearing any more, so a work tree that does not hold a published file means the tree
        was lost or partially written. The bridge then **rebuilds** it (source + online) instead of
        exiting 78, and the answer says so:

        ``{"ok": true, "checked": true, "rebuilt": false}`` - already consistent;
        ``{"ok": true, "checked": true, "rebuilt": true, "files": N, "drift": {...}}`` - repaired;
        ``{"ok": false, "rebuilt": false, "error": "SOURCE_EMPTY"|"SOURCE_UNREADABLE", ...}`` - the
        rebuild was refused and the tree was left exactly as it was (a lost or unreadable
        ``source/`` must not empty the commit tree; Apply source is the repair);
        ``{"ok": true, "checked": false, ...}`` - no site yet (an UNPAIRED bridge).
        """
        current = site if site is not None else self.cfg.site
        if current is None:
            return {"ok": True, "checked": False, "reason": "no site configured yet"}
        drift = self.work_drift(current)
        if drift is None:
            return {"ok": True, "checked": True, "rebuilt": False}
        info(
            "the commit tree is out of date with online/, rebuilding it",
            work=str(current.work),
            missing=drift.get("missing"),
            different=drift.get("different"),
        )
        rebuilt = self.rebuild_work(current)
        if rebuilt.get("ok") is False:
            # The rebuild was refused before a single file was touched. Report the verdict as it is
            # instead of claiming a repair: the old tree is still there, and the next Apply source
            # (with a readable, non-empty source/) is what fixes it.
            warn(
                "the commit tree is stale but was not rebuilt: the assembly was refused",
                work=str(current.work),
                error=rebuilt.get("error"),
                dir=rebuilt.get("dir"),
                message=rebuilt.get("message"),
            )
            return rebuilt
        return {"ok": True, "checked": True, "rebuilt": True, "files": rebuilt["files"], "drift": drift}

    def ensure_work_consistent(
        self,
        site: Site | None = None,
        *,
        request: str | None = None,
        allow_empty_source: bool = False,
    ) -> dict[str, Any] | None:
        """Rebuild a stale commit tree before a write operation; a no-op when it is consistent.

        Called by the explicit operations that still own a repair - init and the author-root change.
        Build and publish no longer call it: a publish checks the tree (:meth:`assembly_drift`) and
        refuses a drift, and the build path no longer runs a separate work repair (Apply source owns
        the assembly). ``request`` only names the entry point in the log line, so an operator can
        tell which operation paid for the repair. The answer is :meth:`rebuild_work`'s, passed
        through unchanged: it is ``None`` when the tree was consistent, ``{"ok": True, ...}`` when it
        was rebuilt, and ``{"ok": False, "rebuilt": False, ...}`` when the rebuild was refused (an
        empty or unreadable ``source/``) and the caller must not pretend it was repaired.
        """
        current = site if site is not None else self.cfg.site
        if current is None:
            return None
        drift = self.work_drift(current)
        if drift is None:
            return None
        info(
            "the commit tree is out of date with online/, rebuilding it before the operation",
            request=request,
            work=str(current.work),
            missing=drift.get("missing"),
            different=drift.get("different"),
        )
        return self.rebuild_work(current, allow_empty_source=allow_empty_source)

    @staticmethod
    def _author_target_ok(target: str, site: Any, uid: str) -> bool:
        if not target or target.startswith("/") or ".." in target.split("/"):
            return False
        prefix = f"{site.author_root}/{uid}/"
        return target.startswith(prefix) and len(target) > len(prefix)

    @classmethod
    def _author_file_target_ok(cls, target: str, site: Any) -> bool:
        """Whether ``target`` names one file of *some* author area: ``<author_root>/<uid>/<file>``.

        :meth:`_author_target_ok` asks the same question for a **known** uid; this one reads the uid
        out of the target, because the caller may name the **orphan owner** - the virtual name a
        deleted-but-published file is transferred to, which owns no Nextcloud account and is therefore
        unknown here. The shape rules stay the same: site-relative, no traversal, one directory
        segment for the uid and at least one file segment below it.
        """
        if not target or target.startswith("/") or ".." in target.split("/"):
            return False
        parts = target.split("/")
        if len(parts) < 3 or any(part in ("", ".") for part in parts):
            return False
        if parts[0] != site.author_root:
            return False
        uid = parts[1]
        if re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", uid) is None:
            return False
        return cls._author_target_ok(target, site, uid)

    def _write_tree_file(self, tree: Path, target: str, content: bytes) -> None:
        """Write one file into a data tree **atomically**; create the parents it needs.

        One primitive for every writer of the preview and review trees (the author deltas, the
        review-tree overlay, the commit-tree recovery), because the build reads those trees from
        another thread: the bytes go to a temporary file in the same directory and are renamed into
        place, so a save that lands inside the debounce window - or next to a running build - can
        never leave a half-written page behind for the build or a ``git add`` to pick up. The
        temporary name is dot-prefixed and shares the parent, so the rename is one filesystem
        operation (see :func:`~pages_bridge.util.atomic_write_bytes`).
        """
        atomic_write_bytes(tree / target, content)

    def _delete_tree_file(self, tree: Path, target: str) -> bool:
        path = tree / target
        if path.is_file():
            path.unlink()
            return True
        return False

    @staticmethod
    def _prune_tree_parents(tree: Path, target: str) -> None:
        """Remove the directories a deleted target leaves empty, never the tree root.

        A candidate that is discarded or taken down is gone from the review tree, and an empty
        ``<author_root>/<uid>/`` directory left behind would look like an author with no files in a
        tree that is only meant to hold the published content and what is under review.
        """
        parent = (tree / target).parent
        while parent != tree and is_within(parent, tree):
            try:
                if any(parent.iterdir()):
                    return
                parent.rmdir()
            except OSError:
                return
            parent = parent.parent

    @staticmethod
    def _prune_empty_dirs(root: Path, stop: Path) -> int:
        """Remove the empty directories of ``root``, deepest first; ``stop`` and above stay.

        Used after an author-root move: the files changed address, but ``_delete_nc_file`` only
        unlinks them, so the old prefix's folder skeleton stayed behind as empty folders in
        ``online/`` and ``pending/``. Only an **empty** directory goes - ``rmdir`` refuses one that
        still holds anything - so a file the round did not move (a symlink, the temporary file of an
        interrupted write) keeps its folder, and a directory that cannot be removed is skipped: this
        is tidying, never a reason to fail an operation that already succeeded.
        """
        if not root.is_dir() or root.is_symlink() or root == stop or not is_within(root, stop):
            return 0
        try:
            directories = [path for path in root.rglob("*") if path.is_dir() and not path.is_symlink()]
        except OSError as exc:
            warn("failed to read the directories of a moved author root", dir=str(root), error=repr(exc))
            return 0
        directories.append(root)
        directories.sort(key=lambda path: len(path.parts), reverse=True)
        pruned = 0
        for directory in directories:
            try:
                directory.rmdir()
                pruned += 1
            except OSError:
                continue
        return pruned

    def _refresh_onlinepreview(
        self, site: Site, *, force: bool = False, dry_run: bool = False
    ) -> MirrorResult:
        """Reassemble the review tree from ``source/`` → ``online/`` → ``pending/``.

        No skeleton round and no set file: the review tree is a pure overlay of the three
        Nextcloud directories, so "no pending candidate ⇒ the review tree equals the commit tree"
        holds by construction (both start with the same two layers). ``force`` is kept for the
        callers that want to repeat the round; the assembly is always complete.
        """
        layers = self._layers(site, "onlinepreview")
        copied = self._assemble_tree(site, site.onlinepreview, layers, dry_run=dry_run)
        result = MirrorResult(
            ok=True,
            direction="forward",
            copied=0 if dry_run else copied,
            scanned_src=sum(len(self._layer_files(root, skip)) for root, _dest, skip in layers),
            in_sync=True,
            dry_run=dry_run,
        )
        if not dry_run:
            self.runtime.last_onlinepreview_mirror = result.to_dict()
        return result

    def _ensure_onlinepreview(self, site: Site, *, force: bool = False) -> None:
        """Bring the review tree up to date before a candidate is frozen into it.

        The tree is derived, so a missing tree (a fresh site, a reset, a lost work tree) is simply
        assembled again; ``force`` repeats it. After that the pending file this op just wrote is
        already part of the next assembly - the caller also writes the single target immediately so
        the reviewer sees it without waiting for the debounced round.
        """
        if force or not self.runtime.onlinepreview_mirrored or not site.onlinepreview.is_dir():
            self._refresh_onlinepreview(site, force=force)
            self.runtime.onlinepreview_mirrored = True

    def apply_source(self, site: Any, body: Mapping[str, Any] | None = None) -> dict:
        """**Apply source**: delete and rebuild the three trees from the Nextcloud directories.

        This is the one whole-site operation the app triggers after the administrator edits
        ``source/`` (or when a tree must be repaired). Every tree is derived, so a complete rebuild
        is always safe:

        1. ``work``       = ``source/`` → the tree root, ``online/`` → the tree root (``.git``
                            is kept; the tree is the flat repository a publish pushes)
        2. ``preview``    = ``source/`` → ``online/`` → each author folder (merged flat)
        3. ``onlinepreview`` = ``source/`` → ``online/`` → ``pending/`` (merged flat)

        The **authors** are named by the request (``authors: [{uid, area}]``), then by the site
        configuration (``site.authors``), then by the uids already present under
        ``online/<author_root>/`` and ``pending/<author_root>/``, and finally by the author folders
        the preview tree already holds (a uid no other record names yet, whose
        ``<uid>/files/<area>`` folder exists). A uid whose ``<uid>/files/<area>`` folder does not
        exist contributes nothing - which is what keeps the site's own ``<author_root>/<orphan>/``
        content out of the author layers.

        Body: ``{authors?, dry_run?, force?, build? (default true), build_onlinepreview?}``.
        ``dry_run`` counts the layers without deleting or writing anything. ``force`` skips the
        delete guard, never the source check. **The caller holds the site lock** for a real run (the
        author-delta route does; the HTTP mirror route wraps it).
        """
        body = body or {}
        dry_run = bool(body.get("dry_run"))
        force = body.get("force") is True
        build = body.get("build", True) is not False
        build_review = bool(body.get("build_onlinepreview"))
        areas = self._author_areas(site, body.get("authors"))
        layers = {kind: self._layers(site, kind, areas) for kind in ("work", "preview", "onlinepreview")}

        # Everything is validated **before** the first tree is cleared: an empty or unreadable
        # source layer, or a round that would delete most of a tree, refuses the whole run and
        # leaves all three trees exactly as they were.
        problem = self._preflight(site, layers, force=force, dry_run=dry_run)
        if problem is not None:
            warn(
                "apply source refused: nothing was cleared or rebuilt",
                error=problem.get("error"),
                tree=problem.get("tree"),
                old=problem.get("old"),
                new=problem.get("new"),
            )
            return {"ok": False, "op": "mirror", "rebuilt": False, "dry_run": dry_run, **problem}

        if dry_run:
            # a pure count: walk the same layers, copy nothing
            files = {
                kind: sum(len(self._layer_files(root, skip)) for root, _dest, skip in tree_layers)
                for kind, tree_layers in layers.items()
            }
            result: dict[str, Any] = {
                "ok": True,
                "op": "mirror",
                "rebuilt": False,
                "dry_run": True,
                "files": files,
                "build": None,
            }
            if areas:
                result["authors"] = [f"{uid}:{area}" for uid, area, _root in areas]
            return result

        # The cleanup phase: a write killed between its temporary file and the rename left that
        # file in the site folder. It must not be read as a published/queued page (and the next
        # publish must not commit it), so it goes before any layer is read. A dry run returns above,
        # on purpose: the assessment writes nothing.
        self._sweep_atomic_residue(site)

        # every layer is already in Nextcloud; deleting the trees loses no input
        work_files = self._assemble_tree(site, site.work, layers["work"])
        # the repository is part of no layer: init it after the assembly (it is kept by the clear)
        self.ensure_work_repo(site)
        preview_files = self._assemble_tree(site, site.preview, layers["preview"])
        onlinepreview_files = self._assemble_tree(site, site.onlinepreview, layers["onlinepreview"])
        self.runtime.onlinepreview_mirrored = True
        result: dict[str, Any] = {
            "ok": True,
            "op": "mirror",
            "rebuilt": True,
            "dry_run": False,
            "files": {
                "work": work_files,
                "preview": preview_files,
                "onlinepreview": onlinepreview_files,
            },
            "build": None,
        }
        if areas:
            result["authors"] = [f"{uid}:{area}" for uid, area, _root in areas]
        if build:
            result["build"] = run_build(site, self.cfg, self.state).to_dict()
        if build_review:
            result["onlinepreview_build"] = run_build(
                site, self.cfg, self.state, tree="onlinepreview"
            ).to_dict()
        info(
            "apply source: the three trees were rebuilt",
            files=result["files"],
            authors=len(areas),
            build=build,
        )
        return result

    def do_author_delta(self, site: Any, body: Mapping[str, Any]) -> dict:
        """Apply one author-content event, the one-shot overwrite or Apply source.

        The three trees are pure functions of the Nextcloud folder, and every op below moves exactly
        the bytes it names:

        * ``add``/``update`` read one author source file and write ``preview/<target>``. A save is
          **not** a publication: neither ``online/`` nor the commit tree moves.
        * ``remove`` deletes ``preview/<target>`` only. The published copy (and a pending candidate
          of that target) stay: only a take-down removes published content.
        * ``candidate`` reads the author source bytes, writes ``pending/<target>`` in Nextcloud
          (the frozen copy the reviewer sees), schedules the review-tree build and answers the
          sha256 of those bytes as ``digest``.
        * ``publish`` (approval) moves ``pending/<target>`` → ``online/<target>``, deletes the
          pending file and writes the same bytes into ``work/<target>`` and
          ``onlinepreview/<target>``. An optional ``digest`` binds the approval to the bytes the
          candidate answered: a mismatch is a 400 ``DIGEST_MISMATCH`` and writes nothing, and an
          idempotent retry (no pending file) checks the digest against ``online/`` instead.
        * ``discard`` deletes ``pending/<target>`` and restores ``onlinepreview/<target>`` from
          ``online/<target>`` (or removes it when the target was never published).
        * ``take-down`` deletes ``online/<target>`` in Nextcloud, discards a waiting
          ``pending/<target>`` and removes the published bytes from ``work/<target>`` and
          ``onlinepreview/<target>``. When ``source/`` holds the same path, the lower layer
          surfaces again: both trees get the source bytes instead of losing the skeleton file, so
          the commit tree stays the ``source/`` + ``online/`` assembly - and the debounced review
          round is scheduled when the review tree really changed. The answer's ``discarded`` says
          whether a candidate went.
        * ``mirror`` is **Apply source** (:meth:`apply_source`): every tree is deleted and rebuilt.
        * ``overwrite`` is the boot repair of the preview tree's author area (the one-shot manifest).

        The caller holds ``runtime.lock`` (the author-delta route does). Every write into a host
        tree is atomic (:meth:`_write_tree_file`), and every write into Nextcloud goes through
        :meth:`_write_nc_file` / :meth:`_delete_nc_file`.
        """
        op = str(body.get("op") or "")
        if op == "overwrite":
            return self._overwrite(site, body)
        if op == "mirror":
            return self.apply_source(site, body)
        if op not in (
            "add", "update", "remove",
            "candidate", "publish", "discard", "take-down",
        ):
            raise BadRequest("unknown author delta op", got=repr(op)[:40])
        uid = body.get("uid")
        if not isinstance(uid, str) or re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", uid) is None:
            raise BadRequest("uid must be one account name", got=repr(uid)[:80])
        area = str(body.get("area") or "Pages")
        if re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._/-]*", area) is None or ".." in area.split("/"):
            raise BadRequest("area must be a folder name", got=repr(area)[:80])
        prefix = f"{site.author_root}/{uid}/"
        applied: list[str] = []
        idempotent = False
        discarded: bool | None = None
        trigger: str | None = "preview"
        #: the sha256 of the bytes a ``candidate`` just froze, answered to the app
        candidate_digest: str | None = None

        if op in ("add", "update"):
            target = str(body.get("target") or "")
            if not self._author_target_ok(target, site, uid):
                raise BadRequest("target must be under the author directory", target=target[:200])
            content = self._read_author_file(site, target, area)
            self._write_tree_file(site.preview, target, content)
            applied.append(target)
        elif op == "remove":
            target = str(body.get("target") or "")
            if not self._author_target_ok(target, site, uid):
                raise BadRequest("target must be under the author directory", target=target[:200])
            if self._delete_tree_file(site.preview, target):
                applied.append(target)
        elif op == "candidate":
            # Freeze the bytes of this moment into pending/ and the review tree. The pending file
            # in Nextcloud is the review object; a later save of the source changes nothing here
            # until the app applies a new candidate.
            target = str(body.get("target") or "")
            if not self._author_target_ok(target, site, uid):
                raise BadRequest("target must be under the author directory", target=target[:200])
            content = self._read_author_file(site, target, area)
            # The digest is the identity of the bytes under review. The app records it with the
            # request and sends it back on the approval; ``publish`` refuses when ``pending/`` holds
            # anything else, so a candidate the author replaced after the request can never be
            # published by a retried approval.
            candidate_digest = hashlib.sha256(content).hexdigest()
            self._ensure_onlinepreview(site, force=bool(body.get("force")))
            self._write_nc_file(site.pending_dir / target, content)
            self._write_tree_file(site.onlinepreview, target, content)
            applied.append(target)
            trigger = "onlinepreview"
        elif op == "publish":
            # The reviewed copy becomes the published content: pending/ -> online/ (Nextcloud),
            # then the same bytes into the commit tree and the review tree. Idempotent on purpose:
            # the app writes its manifest after this call, so a retry may find no pending file left
            # and only the published copy - that retry succeeds without moving bytes.
            #
            # ``digest`` binds the approval to the bytes the reviewer saw: it is the sha256 a
            # ``candidate`` answered for this target. A mismatch means the author replaced the
            # candidate after the request (or something else did), so these are **not** the bytes
            # that were approved and nothing is written. Without a digest the old behaviour is kept
            # (an older app does not send one), with a warning because the binding is missing.
            target = str(body.get("target") or "")
            if not self._author_target_ok(target, site, uid):
                raise BadRequest("target must be under the author directory", target=target[:200])
            expected = body.get("digest")
            if expected is not None:
                if not isinstance(expected, str) or not expected.strip():
                    raise BadRequest(
                        "digest must be the sha256 hex string the candidate answered",
                        got=repr(expected)[:80],
                    )
                expected = expected.strip().lower()
            pending = site.pending_dir / target
            online = site.online_dir / target
            if pending.is_file():
                content = pending.read_bytes()
                actual = hashlib.sha256(content).hexdigest()
                if expected is not None and expected != actual:
                    # checked before anything is written: the unreviewed replacement stays pending
                    raise DigestMismatch(expected, actual)
                if expected is None:
                    warn(
                        "publish without a digest: the approved bytes are not bound to the reviewed candidate",
                        target=target[:200],
                        uid=uid,
                    )
                self._write_nc_file(online, content)
                self._delete_nc_file(pending)
            elif online.is_file():
                content = online.read_bytes()
                idempotent = True
                if expected is not None:
                    # a retry may have no pending copy left; the digest then has to match what is
                    # already published, or the "already done" answer would confirm bytes the
                    # reviewer never approved
                    actual = hashlib.sha256(content).hexdigest()
                    if expected != actual:
                        raise DigestMismatch(expected, actual)
                info("publish is a no-op: the approved bytes already landed", target=target[:200])
            else:
                raise BadRequest(
                    "no candidate to publish",
                    target=target[:200],
                    hint="apply the candidate first: only a frozen copy can be published",
                )
            self._write_tree_file(site.work, target, content)
            self._write_tree_file(site.onlinepreview, target, content)
            applied.append(target)
            trigger = None
        elif op == "discard":
            # Reject the candidate: pending/<target> goes, the review copy returns to the online
            # revision (or disappears when the target was never published).
            target = str(body.get("target") or "")
            if not self._author_target_ok(target, site, uid):
                raise BadRequest("target must be under the author directory", target=target[:200])
            was_pending = self._delete_nc_file(site.pending_dir / target)
            changed = False
            online = site.online_dir / target
            if online.is_file():
                content = online.read_bytes()
                review = site.onlinepreview / target
                if not review.is_file() or review.read_bytes() != content:
                    self._write_tree_file(site.onlinepreview, target, content)
                    changed = True
            elif self._delete_tree_file(site.onlinepreview, target):
                self._prune_tree_parents(site.onlinepreview, target)
                changed = True
            if changed or was_pending:
                applied.append(target)
            trigger = "onlinepreview"  # the page disappears from the reviewer's build
        else:  # take-down
            # Removing an approved page from the site: online/ and a waiting candidate in
            # Nextcloud, and both data trees. The candidate has to go with the page: the review
            # tree is assembled as source/ -> online/ -> pending/, so a leftover pending/<target>
            # would put the taken-down page back in front of the reviewer, and the next approve
            # would publish it again without a second review.
            #
            # Then the lower layer falls through. When `source/` holds the same path, the online
            # overlay only *hid* it: deleting the tree copy would drop the skeleton file too, the
            # commit tree would stop being the source/ + online/ assembly, and the very next
            # publish would refuse it with WORK_DRIFT. So the source bytes take the published ones'
            # place in both trees. The skeleton exclusion applies to this fallback exactly as it
            # applies to the assembly: a path the policy keeps out of the trees never enters one,
            # and a symlink is not site content either (see `_layer_files`).
            target = str(body.get("target") or "")
            if not self._author_target_ok(target, site, uid):
                raise BadRequest("target must be under the author directory", target=target[:200])
            removed = self._delete_nc_file(site.online_dir / target)
            discarded = self._delete_nc_file(site.pending_dir / target)
            source_file = site.source_dir / target
            fallback = (
                source_file.is_file()
                and not source_file.is_symlink()
                and not self._skeleton_exclusion(site).matches(Path(target).parts)
            )
            if fallback:
                content = source_file.read_bytes()
                review = site.onlinepreview / target
                try:
                    changed = not review.is_file() or review.read_bytes() != content
                except OSError:
                    # a review copy that cannot be read cannot be proven equal: repair it
                    changed = True
                self._write_tree_file(site.work, target, content)
                if changed:
                    self._write_tree_file(site.onlinepreview, target, content)
            else:
                self._delete_tree_file(site.work, target)
                changed = self._delete_tree_file(site.onlinepreview, target)
                if changed:
                    self._prune_tree_parents(site.onlinepreview, target)
            if removed:
                applied.append(target)
            # the review tree really changed: let the debounced review round rebuild it
            trigger = "onlinepreview" if changed else None

        if trigger == "onlinepreview":
            self.touch_onlinepreview(site)
        elif trigger == "preview":
            self.touch(site)
        info("author delta applied", op=op, uid=uid, applied=len(applied), tree=trigger)
        answer: dict[str, Any] = {"ok": True, "op": op, "applied": applied, "tree": trigger}
        if candidate_digest is not None:
            # the identity of the frozen bytes: the app stores it and hands it back on the approval
            answer["digest"] = candidate_digest
        if discarded is not None:
            answer["discarded"] = discarded
        if idempotent:
            answer["idempotent"] = True
        return answer

    def _overwrite(self, site: Any, body: Mapping[str, Any]) -> dict:
        """Run the one-shot author overwrite and say how soon a retry may come after a failure.

        The app calls this op from an ``injectFn`` on **every** request and marks it done only after
        the bridge accepted it, so a failed round is retried on the next request. The bridge cannot
        stop that: the retry decision is the app's, and a bridge that cannot be reached answers
        nothing at all. It can say, in the answer that just failed, when a retry makes sense -
        ``retry_after_ms`` (a minute for a one-shot repair nobody is waiting on) rides the error
        body and under ``details``, which is where the app reads its fields. Nothing is remembered
        here: the refusal *is* the signal, and the next request is free to try again.
        """
        try:
            return self._author_overwrite(site, body)
        except BridgeError as exc:
            exc.details.setdefault("retry_after_ms", OVERWRITE_RETRY_AFTER_MS)
            raise
        except OSError as exc:
            # a filesystem failure is the transient case the hint exists for; report it as a bridge
            # error so the hint can travel, instead of the handler's bare INTERNAL
            raise BridgeError(
                "the one-shot author overwrite failed",
                reason="OVERWRITE_FAILED",
                retry_after_ms=OVERWRITE_RETRY_AFTER_MS,
                detail=repr(exc)[:300],
            ) from exc

    def _author_overwrite(self, site: Any, body: Mapping[str, Any]) -> dict:
        """Repair the derived preview tree's author area from the complete pushed manifest.

        The preview tree gets the current source bytes of everything listed, stray files under the
        author prefixes are removed and the ``purge`` roots (the previous directory) are emptied.

        The commit tree is **not written here at all**, not even back-filled: its author area is the
        published content, and a boot repair must not be able to put an unreviewed source file (or
        a stale manifest entry) live. The ``on_site`` key is accepted and ignored: the published
        set now lives in ``online/`` itself, so there is no set file to rebuild from it.

        There is no delete-count guard here: this tree is derived from the author's Nextcloud files,
        so a stray is the leftover of a deleted account and a wrong removal is rebuilt by the next
        overwrite. The delete budget belongs to the mirror / Apply source trees, which become git
        content. ``touch`` runs on the success path only.
        """
        authors = body.get("authors")
        if not isinstance(authors, list):
            raise BadRequest("authors must be an array")
        purge = [str(r) for r in (body.get("purge") or []) if re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", str(r))]
        per_author: list[tuple[str, str, set[str]]] = []
        for entry in authors:
            if not isinstance(entry, Mapping):
                raise BadRequest("each author must be an object")
            uid = str(entry.get("uid") or "")
            if re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", uid) is None:
                raise BadRequest("uid must be one account name", got=repr(uid)[:80])
            area = str(entry.get("area") or (str(entry.get("path") or "Pages").strip("/") or "Pages"))
            # ``area`` becomes a path component of the author's own Nextcloud folder, so it is held
            # to the rule the author-delta route applies: a relative folder name, no traversal, no
            # absolute path. Without it `../../victim/files/Pages` reads another account's file,
            # and the containment check cannot notice because it compares the path with itself.
            if (
                re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._/-]*", area) is None
                or ".." in area.split("/")
                or area.startswith("/")
            ):
                raise BadRequest("area must be a folder name", got=repr(area)[:80])
            preview_targets: set[str] = set()
            for target in entry.get("entries") or []:
                target = str(target)
                if not self._author_target_ok(target, site, uid):
                    raise BadRequest("target must be under the author directory", target=target[:200])
                preview_targets.add(target)
            per_author.append((uid, area, preview_targets))

        # ── collect the strays, then remove them: this tree is derived, so no delete budget ───────
        roots = [
            root for root in dict.fromkeys([site.author_root, *purge])
            if re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", root)
        ]
        plan: list[tuple[Path, list[Path]]] = []
        for root in roots:
            for uid, _area, preview_targets in per_author:
                base = site.preview / root / uid
                if not base.is_dir():
                    continue
                keep = set() if root in purge else preview_targets
                strays = [
                    path
                    for path in sorted(base.rglob("*"))
                    if path.is_file() and path.relative_to(site.preview).as_posix() not in keep
                ]
                if strays:
                    plan.append((base, strays))

        # ── refresh the listed targets, then remove the strays ──────────────────────────────────
        for uid, area, preview_targets in per_author:
            author_files = self.cfg.nc_data_root / uid / "files"
            for target in preview_targets:
                relative = target[len(f"{site.author_root}/{uid}/"):]
                source = author_files / area / relative
                if source.is_file() and is_within(source, author_files):
                    self._write_tree_file(site.preview, target, source.read_bytes())
        removed = 0
        for base, strays in plan:
            for path in strays:
                path.unlink()
                removed += 1
            # prune empty directories, never the tree root
            for directory in sorted(
                (p for p in base.rglob("*") if p.is_dir()), key=lambda p: len(p.parts), reverse=True
            ):
                try:
                    directory.rmdir()
                except OSError:
                    pass
        total = sum(len(targets) for _uid, _area, targets in per_author)
        self.touch(site)
        info("author overwrite applied", authors=len(per_author), entries=total, removed=removed)
        return {"ok": True, "op": "overwrite", "authors": len(per_author), "entries": total, "removed": removed}

    def set_author_root(self, body: Mapping[str, Any]) -> dict:
        """Change the author content directory, moving the content it already holds.

        Shape only: one path segment (letters, digits, dot, underscore, hyphen; no ``/``, no ``.`` or
        ``..``). If the directory already exists inside the site, the caller has to say
        ``confirm: true`` - a 409 ``CONFIRM_REQUIRED`` is answered otherwise, so an accidental typo
        that happens to name an existing folder cannot silently take it over. Registered author
        content never blocks the move: a live installation has published and waiting pages, and
        refusing the change because of them would make the setting unusable there.

        What already lives under the old name is **moved**, not orphaned: every target under
        ``<old_root>/`` in ``online/`` and ``pending/`` is written under ``<new_root>/``, into the
        same directory and with the same bytes, and only then removed from under the old name. A
        page that was published (or waits for review) stays published (or pending) under the new
        name instead of becoming an old-prefix address :meth:`_author_target_ok` refuses forever.
        The emptied folder skeleton under the old name is pruned (only empty directories, see
        :meth:`_prune_empty_dirs`), so ``online/``/``pending/`` do not keep an old author area
        around after its content left.

        The move is all or nothing, and it runs **before** the configuration is adopted: if one file
        cannot be moved, the files this call already moved are put back under the old name (best
        effort - ``rollback_failed`` names the ones that could not be), the configuration stays at
        the old root, and :class:`~pages_bridge.util.AuthorRootMoveFailed` is raised. A half-moved
        directory under a new ``author_root`` would be worse than a refused change: a retry would
        hit the ``new_root == site.author_root`` early return ("changed: false"), and the rest of the
        old prefix would stay behind as site content nobody names.

        ``site.authors`` names the old root and is dropped here; the app republishes its author
        sources under the new one (Apply source lays them over the skeleton). The derived trees are
        rebuilt after the move, so ``onlinepreview`` and the commit tree name the new prefix
        immediately. The general config push keeps treating ``author_root`` as information only.
        """
        site = self.cfg.site
        if site is None:
            raise BadRequest("no site is configured yet", hint="push the site configuration first")
        raw = body.get("author_root")
        if not isinstance(raw, str):
            raise BadRequest("author_root must be a single folder name", got=repr(raw)[:200])
        new_root = raw.strip()
        if re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", new_root) is None:
            raise BadRequest(
                "site.author_root must be a single path segment",
                got=repr(raw)[:200],
            )
        exists = (site.source / new_root).is_dir()
        if new_root == site.author_root:
            # idempotent, and answered before the lock on purpose: a retry after a lost answer must
            # not queue behind a build round that can hold the site lock for minutes
            return {"ok": True, "author_root": site.author_root, "changed": False, "exists": exists}
        # The move rewrites the site configuration and drops the stored author blocks, so it runs
        # under the site lock: a delta racing it must not act on the half-moved configuration.
        with self._site_lock():
            # re-read under the lock: a reset or a config push may have replaced the site while this
            # request was being validated
            site = self.cfg.site
            if site is None:
                raise BadRequest("no site is configured yet", hint="push the site configuration first")
            exists = (site.source / new_root).is_dir()
            if new_root == site.author_root:
                return {"ok": True, "author_root": site.author_root, "changed": False, "exists": exists}
            if exists and body.get("confirm") is not True:
                raise ConfirmRequired(
                    f"the directory {new_root!r} already exists in the site",
                    exists=True,
                    hint="send confirm:true to use the existing directory.",
                )
            old_root = site.author_root
            old_prefix = f"{old_root}/"
            # the old-prefix addresses are read before anything moves: they are exactly the ones
            # that are about to stop matching the author layer
            orphans = {
                kind: [
                    target
                    for target in self._layer_files(
                        site.online_dir if kind == "online" else site.pending_dir
                    )
                    if target.startswith(old_prefix)
                ]
                for kind in ("online", "pending")
            }
            # The candidate configuration is validated before a file moves (validating stores
            # nothing), but it is only *adopted* after every file made it. A failure therefore
            # leaves the old root in force, so the app can retry the same request - answering
            # "already changed" to a half-moved directory would strand the rest of the old prefix
            # as site content nobody names.
            site_raw = dict(self.cfg.site_raw)
            site_raw["author_root"] = new_root
            # the old targets name the old root; the app republishes them under the new one right after
            site_raw["authors"] = []
            candidate = self._assemble(
                site_raw=site_raw,
                pushed_globals=dict(self.cfg.app_globals),
                secrets={
                    "shared_secret": self.cfg.shared_secret,
                    "session_signing_secret": self.cfg.session_signing_secret,
                },
                source=self.config_source,
                revision=self.config_revision,
                previous_author_root=old_root,
            )

            def directory_of(kind: str) -> Path:
                return site.online_dir if kind == "online" else site.pending_dir

            moved = {"online": 0, "pending": 0}
            moved_targets: list[tuple[str, str, str]] = []
            failed: list[str] = []
            rollback_failed: list[str] = []
            # carry the content first: one target at a time, new copy written, old copy removed
            for kind in ("online", "pending"):
                directory = directory_of(kind)
                for target in orphans[kind]:
                    relocated = new_root + target[len(old_root):]
                    wrote_new = False
                    try:
                        content = (directory / target).read_bytes()
                        self._write_nc_file(directory / relocated, content)
                        wrote_new = True
                        self._delete_nc_file(directory / target)
                    except OSError as exc:
                        # one file, not the round: the rest still moves; a failed round is rolled
                        # back together below
                        failed.append(target)
                        if wrote_new:
                            # the new copy landed but the old one did not go: undo the former, so
                            # the old root still holds exactly what it held before
                            try:
                                self._delete_nc_file(directory / relocated)
                            except OSError:
                                rollback_failed.append(relocated)
                        warn(
                            "author root move: one file could not be moved",
                            target=target[:200],
                            error=str(exc),
                        )
                        continue
                    moved[kind] += 1
                    moved_targets.append((kind, target, relocated))
            if failed:
                # all or nothing: put back what this call moved, newest first, and leave the
                # configuration at the old root
                for kind, target, relocated in reversed(moved_targets):
                    directory = directory_of(kind)
                    try:
                        content = (directory / relocated).read_bytes()
                        self._write_nc_file(directory / target, content)
                        self._delete_nc_file(directory / relocated)
                    except OSError as exc:
                        rollback_failed.append(relocated)
                        warn(
                            "author root move: one file could not be rolled back",
                            target=relocated[:200],
                            error=str(exc),
                        )
                remaining = sorted(
                    target
                    for kind in ("online", "pending")
                    for target in self._layer_files(directory_of(kind))
                    if target.startswith(old_prefix)
                )
                raise AuthorRootMoveFailed(
                    "the author content directory was not changed: some content under the old name "
                    "could not be moved, the files that did move were put back, and no configuration "
                    "was written - retry the change",
                    moved=moved,
                    failed=failed[:50],
                    remaining=remaining[:50],
                    rollback_failed=rollback_failed[:50],
                )
            # Every file made it, so only now is the old prefix's folder skeleton tidied: the files
            # changed address but `_delete_nc_file` only unlinks them, and empty
            # ``<old_root>/<uid>/`` folders in ``online/``/``pending/`` would keep showing the old
            # author area in Nextcloud. Depth-first ``rmdir`` cannot remove anything that still
            # holds a file (a symlink, an interrupted write's temporary file), and ``online/`` and
            # ``pending/`` themselves are never touched. A failure is logged, never raised: the move
            # itself already succeeded.
            pruned = 0
            for kind in ("online", "pending"):
                directory = directory_of(kind)
                pruned += self._prune_empty_dirs(directory / old_root, directory)
            # every file made it: now the configuration follows
            self._store_effective(self.cfg.app_globals, site_raw, {
                "shared_secret": self.cfg.shared_secret,
                "session_signing_secret": self.cfg.session_signing_secret,
            }, self.config_revision)
            self._adopt(candidate)
            new_site = candidate.site
            work_rebuild: dict[str, Any]
            if new_site is None:
                # unreachable with a valid site_raw; answer instead of pretending trees were rebuilt
                work_rebuild = {"ok": False, "rebuilt": False, "reason": "no site configured"}
            else:
                # the derived trees must not keep old-prefix content: rebuild the review tree and
                # the commit tree under the new name. A refused work rebuild (an empty or
                # unreadable source/) is reported, not raised: publish's WORK_DRIFT covers it.
                self._refresh_onlinepreview(new_site)
                self.runtime.onlinepreview_mirrored = True
                self.touch_onlinepreview(new_site)
                work_rebuild = self.rebuild_work(new_site)
                if work_rebuild.get("ok") is False:
                    warn(
                        "the commit tree could not be rebuilt after the author root change",
                        error=work_rebuild.get("error"),
                        dir=work_rebuild.get("dir"),
                    )
            info(
                "author root changed",
                previous=old_root,
                author_root=new_site.author_root if new_site else new_root,
                moved=moved,
                pruned=pruned,
            )
            out: dict[str, Any] = {
                "ok": True,
                "author_root": new_site.author_root if new_site else new_root,
                "changed": True,
                "exists": exists,
                "moved": moved,
                "work_rebuild": work_rebuild,
            }
            return out

    def apply_pushed_site(
        self,
        spec: Any,
        *,
        revision: Any = None,
        persist: bool = True,
        source: str = "pushed",
    ) -> dict:
        """Replace the site with the pushed one (the payload of the previous app version).

        The validation is the TOML one, and the app-owned global block stays as it is: this payload
        cannot speak about anything but the site.
        """
        if not isinstance(spec, Mapping):
            raise BadRequest("site must be an object")
        # author_root in a config push is informational only: POST /site/author-root is the one
        # writer of the running root, so a push carrying another value keeps the stored one
        if self.cfg.site is not None:
            spec = {**spec, "author_root": self.cfg.site.author_root}
        revision = revision if isinstance(revision, int) and not isinstance(revision, bool) else self.config_revision
        candidate = self._assemble(
            site_raw=spec,
            pushed_globals=dict(self.cfg.app_globals),
            secrets={
                "shared_secret": self.cfg.shared_secret,
                "session_signing_secret": self.cfg.session_signing_secret,
            },
            source=source,
            revision=revision,
            previous_author_root=self.cfg.site.author_root if self.cfg.site is not None else None,
        )
        self._adopt(candidate)

        if persist:
            write_json(
                self.legacy_pushed_path,
                {
                    "protocol": PROTOCOL,
                    "revision": self.config_revision,
                    "pushed_at": time.time(),
                    "site": dict(spec),
                },
            )
        info("site config updated", source=source, revision=self.config_revision, nc_path=candidate.site.nc_path if candidate.site else None)
        return {
            "ok": True,
            "config_source": self.config_source,
            "revision": self.config_revision,
            "site": candidate.site.nc_path if candidate.site else None,
            "work": str(candidate.site.work) if candidate.site else None,
            "restart_required": self.restart_required({}),
        }

    def apply_pushed_config(self, body: Mapping[str, Any]) -> dict:
        """Validate and store one push from this app version.

        The payload is ``{revision, global, site, secrets?}``. It replaces the app-owned global block
        as a whole - an omitted key falls back to the TOML or to the built-in default - while the
        secrets are kept unless the request carries a new pair (that is secret rotation). Nothing is
        written or adopted before every value validated, so a rejected push leaves the running
        configuration, the stored file and the working secrets exactly as they were.
        """
        has_global = isinstance(body.get("global"), Mapping)
        has_secrets = body.get("secrets") is not None
        if not has_global and not has_secrets:
            # the payload of the previous app version (site only) keeps its own file and wording
            return self.apply_pushed_site(
                body.get("site"), revision=body.get("revision"), persist=True, source="pushed"
            )

        pushed_globals = validate_globals(body.get("global") or {})
        new_secrets: dict[str, str] = {}
        if has_secrets:
            raw_secrets = body.get("secrets")
            if not isinstance(raw_secrets, Mapping):
                raise BadRequest("secrets must be an object")
            for key, value in raw_secrets.items():
                if str(key) not in SECRET_KEYS:
                    raise BadRequest(f"unknown secrets key: {key}", known=list(SECRET_KEYS))
                new_secrets[str(key)] = validate_secret(str(key), value)

        site_raw = body.get("site")
        if site_raw is not None and not isinstance(site_raw, Mapping):
            raise BadRequest("site must be an object")
        if site_raw is None:
            # a globals-only or rotation-only push keeps the site that is already in force
            site_raw = self.cfg.site_raw
        if not isinstance(site_raw, Mapping):
            raise BadRequest("site must be an object")
        # author_root in a config push is informational only: it neither moves the running root nor
        # rejects the push. POST /site/author-root is the one writer of the bridge's author_root, so
        # a push is validated (and stored) against the root already in force.
        if self.cfg.site is not None:
            site_raw = {**site_raw, "author_root": self.cfg.site.author_root}

        revision = body.get("revision")
        revision = revision if isinstance(revision, int) and not isinstance(revision, bool) else self.config_revision
        secrets = {
            "shared_secret": self.cfg.shared_secret,
            "session_signing_secret": self.cfg.session_signing_secret,
        }
        secrets.update(new_secrets)

        candidate = self._assemble(
            site_raw=site_raw,
            pushed_globals=pushed_globals,
            secrets=secrets,
            source="app",
            revision=revision,
            previous_author_root=self.cfg.site.author_root if self.cfg.site is not None else None,
        )
        if candidate.site is not None:
            # the data directory has to serve the site's own user, not just any user
            check_nc_data_root(candidate.nc_data_root, user=candidate.site.nc_user)
        # the file first, the running config second: a write that fails leaves both untouched
        self._store_effective(pushed_globals, dict(site_raw), secrets, revision)
        self._adopt(candidate)
        # the legacy file would win at the next start, and this push supersedes it
        if self.legacy_pushed_path.exists():
            self.legacy_pushed_path.unlink()
        info(
            "config pushed by the app",
            revision=revision,
            keys=sorted(pushed_globals),
            secrets_rotated=bool(new_secrets),
        )
        return {
            "ok": True,
            "config_source": self.config_source,
            "revision": self.config_revision,
            "site": candidate.site.nc_path if candidate.site else None,
            "work": str(candidate.site.work) if candidate.site else None,
            "restart_required": self.restart_required(pushed_globals),
        }

    def pair(self, body: Mapping[str, Any]) -> dict:
        """Validate one pairing request and adopt it.

        Order matters: the payload and the one-time code are validated first, then the
        configuration is written and adopted. A wrong code therefore never changes anything, and a
        bad payload never burns a correct code (the code is only cleared after adoption).

        The code is verified **before** :meth:`_assemble` runs: assembling calls ``build_site``,
        which creates the site's ``online/`` and ``pending/`` directories in the Nextcloud folder,
        and a wrong code must not leave those two directories in somebody's Files. Verifying first
        costs nothing - :meth:`_pair_code_dir` reads the bootstrap state file, never the candidate -
        so the payload is still validated in full before anything is adopted.
        """
        if self.paired:
            raise AlreadyPaired()

        code = body.get("code")
        if not isinstance(code, str) or not code.strip():
            raise BadRequest("code is required", hint="run bridge/install.sh on the bridge host")

        # The code lives in the **bootstrap** state_dir - the directory `pair-code` / install.sh
        # write it to from the TOML, before anything is pushed - so the running bridge reads
        # exactly the file the command wrote. A code an older bridge left in a pushed state_dir is
        # still accepted (legacy fallback) and spent where it was found.
        code_dir = self._pair_code_dir()
        pairing.verify(code_dir, str(code))

        shared_secret = validate_secret("shared_secret", body.get("shared_secret"))
        session_signing_secret = validate_secret("session_signing_secret", body.get("session_signing_secret"))

        globals_raw = body.get("global")
        if globals_raw is not None and not isinstance(globals_raw, Mapping):
            raise BadRequest("global must be an object")
        pushed_globals = validate_globals(globals_raw or {})
        if body.get("nc_data_root") is not None:
            # the request carries the data directory twice (the dedicated field, which the app fills
            # from Nextcloud, and possibly the same key in `global`, which the administrator can set
            # for a bridge that sees the data directory under a different mount): an explicit value
            # in `global` wins, the discovered field fills the gap.
            discovered = validate_globals({"nc_data_root": body.get("nc_data_root")})["nc_data_root"]
            pushed_globals.setdefault("nc_data_root", discovered)
        if "nc_data_root" not in pushed_globals:
            raise BadRequest(
                "nc_data_root is required",
                hint="the app discovers it with occ config:system:get datadirectory",
            )

        site_raw = body.get("site")
        if site_raw is not None and not isinstance(site_raw, Mapping):
            raise BadRequest("site must be an object")
        revision = body.get("revision")
        revision = revision if isinstance(revision, int) and not isinstance(revision, bool) else 0
        secrets = {
            "shared_secret": shared_secret,
            "session_signing_secret": session_signing_secret,
        }

        candidate = self._assemble(
            site_raw=site_raw,
            pushed_globals=pushed_globals,
            secrets=secrets,
            source="app",
            revision=revision,
            previous_author_root=self.cfg.site.author_root if self.cfg.site is not None else None,
        )
        check_nc_data_root(candidate.nc_data_root, user=candidate.site.nc_user if candidate.site else None)

        self._store_effective(
            pushed_globals,
            dict(site_raw) if isinstance(site_raw, Mapping) else {},
            secrets,
            revision,
        )
        self._adopt(candidate)
        pairing.clear(code_dir)
        # a reset marker would keep the bridge UNPAIRED across the next restart, so pairing clears it
        # wherever a bridge version may have written it
        for directory in self._bootstrap_state_dirs():
            (directory / UNPAIRED_FLAG).unlink(missing_ok=True)
        info(
            "bridge paired from the Nextcloud app",
            revision=revision,
            nc_data_root=str(candidate.nc_data_root),
            site=candidate.site.nc_path if candidate.site else None,
        )
        return {
            "ok": True,
            "paired": True,
            "protocol": PROTOCOL,
            "version": SERVER_VERSION,
            "config_source": "app",
            "revision": revision,
            "restart_required": self.restart_required(pushed_globals),
        }

    def author_uids(self, site: Site) -> tuple[str, ...]:
        """The **real** authors of this site: every uid whose ``<author_root>/<uid>/`` is theirs.

        Two records, because either can be the only one available:

        * ``site.authors`` - the author blocks a pushed configuration (or the TOML) carries;
        * the uids already present under ``online/<author_root>/`` or ``pending/<author_root>/`` -
          the data directories themselves are the record now that no target-set file exists.

        The **orphan owner** is in neither record: an author may hand a deleted-but-published file
        over to a virtual name (``Admin``, ``common``, …) which owns no Nextcloud account, and the
        app writes that content straight into the site source. It is a site-owned file, not an
        author target, so its directory must never become an exclusion - which is exactly why this
        method answers uids instead of "every directory under the author root".
        """
        uids: list[str] = [author.uid for author in site.authors]
        for directory in (site.online_dir, site.pending_dir):
            base = directory / site.author_root
            if not base.is_dir():
                continue
            for child in sorted(base.iterdir(), key=lambda path: path.name):
                if child.is_dir() and child.name:
                    uids.append(child.name)
        return tuple(dict.fromkeys(uids))

    def exclusion(self, site: Site) -> Exclusion:
        """The ignore policy of the commit tree: the site's own exclusions plus every real author.

        The assembly copies the skeleton with this policy (so ``.gitignore``, the output directory
        and the bundle path never enter a tree), and it is what ``drift_report`` in the status/pull
        views compares against ``work``. The whole author root is **not** excluded: an
        ``<author_root>/<orphan>/…`` directory belongs to the site (a handed-over page), so it is
        ordinary skeleton content. Real authors are excluded per uid, so their published/overlaid
        files are not read as "source that disappeared".
        """
        author_prefixes = tuple(
            prefix
            for uid in self.author_uids(site)
            for prefix in author_target_prefixes(uid, site.author_root)
        )
        return self._exclusion(site, author_prefixes=author_prefixes)

    def preview_exclusion(self, site: Site) -> Exclusion:
        """The whole-author-root view of the ignore policy (the preview tree, for diagnostics).

        The preview assembly does not use a delete-diff: it clears and refills the tree from the
        ordered layers, and the author layer is applied last. This policy is kept for the status
        and drift views, where a file under ``<author_root>/`` is author content and not skeleton.
        """
        prefixes: list[str] = [site.author_root]
        for uid in self.author_uids(site):
            for prefix in author_target_prefixes(uid, site.author_root):
                if prefix == f"{site.author_root}/{uid}":
                    continue  # the whole author root is excluded already
                if prefix not in prefixes:
                    prefixes.append(prefix)
        return self._exclusion(site, author_prefixes=tuple(prefixes))

    def author_exclusion(self, site: Site) -> Exclusion:
        """The policy an author target is checked against: the site's own, without the author areas."""
        return self._exclusion(site, author_prefixes=())

    def _exclusion(self, site: Site, *, author_prefixes: tuple[str, ...]) -> Exclusion:
        """The skeleton policy for a view, plus the author areas the caller names.

        The skeleton half is :meth:`_ignore_names` - the same record the assembly copies with, read
        from ``source/`` and falling back on the built-in list exactly when the rules are
        unavailable. Nothing about ``_site/``, ``vendor/`` or an ignored entry can therefore be
        reported excluded here and still be copied into a tree.
        """
        names, prefixes = self._ignore_names(site)
        return Exclusion.for_site(names, site.recipe.output_dir, (*prefixes, *author_prefixes))

    def _restore_work_from_online(self, site: Site) -> int:
        """Copy the published files of ``online/`` into a commit tree rebuilt without a remote.

        ``online/`` is the published record; the rest of the commit tree (the skeleton) comes from
        ``source/`` through the assembly. Used only when the repository had to be recreated without
        a remote, so a fresh ``git init`` gets the published bytes before the next commit.
        """
        restored = 0
        for target, source in self._layer_files(site.online_dir).items():
            try:
                self._write_tree_file(site.work, target, source.read_bytes())
                restored += 1
            except OSError as exc:  # noqa: BLE001
                warn("failed to restore a published file from online/", target=target, error=repr(exc))
        return restored

    def ensure_work_repo(self, site: Site) -> dict | None:
        """Re-create the publish repository when the directory or its ``.git`` is gone.

        The commit tree is derived, so a recovery never reads a set file: with a **remote** the
        history and refs are restored from it (``init_or_clone``, no checkout - the tree's files are
        materialised by the assembly), and **without** a remote the published files are copied back
        out of ``online/``. A tree that is empty after that stays empty: the first ``publish`` fills
        it. Failure is reported, never fatal.
        """
        if gitops.is_repo(site.work):
            return None
        try:
            if site.has_remote:
                info("work copy is not a git repository, restoring it from the remote", work=str(site.work))
                detail = gitops.init_or_clone(site.work, site.repo_url, site.branch, site.remote_name)
                detail["recovered_from"] = "remote"
                return detail
            info("work copy is not a git repository, restoring it from online/", work=str(site.work))
            detail = gitops.init_or_clone(site.work, site.repo_url, site.branch, site.remote_name)
            detail["recovered_from"] = "online"
            detail["restored"] = self._restore_work_from_online(site)
            return detail
        except Exception as exc:  # noqa: BLE001
            warn("failed to re-create the work copy repository (assembling anyway)", error=repr(exc))
            return {"action": "failed", "error": repr(exc)}

    def do_mirror(
        self,
        site: Site,
        *,
        force: bool = False,
        dry_run: bool = False,
        allow_empty_source: bool = False,
    ) -> MirrorResult:
        """Reassemble the commit and preview trees; no build runs here.

        Kept as the shared primitive of init, Apply source and build - the two explicit assembly
        entry points (:meth:`do_build`, :meth:`apply_source`) and the diagnostic route. A save and a
        publish never call it: they move the one file they name and leave the trees to the round that
        owns them, so no implicit mirror can clear content an author just wrote. Work and
        preview are pure functions of ``source/`` + ``online/`` (+ the author folders), so a round
        clears and refills both, merged flat at their roots. ``dry_run`` only counts. The
        author-delta ``mirror`` op is the full Apply source (:meth:`apply_source`), which also
        rebuilds ``onlinepreview`` and builds.

        The round runs the same preflight as Apply source (:meth:`_preflight`) and returns
        ``ok: False`` without clearing anything when the source layer is empty or unreadable, or
        when the delete guard trips (``force`` skips the guard). A caller that would build on the
        result must check ``ok``: a refused assembly leaves the trees alone, and building them would
        report a site that does not match its inputs (:meth:`do_build` is the caller that does).
        ``allow_empty_source`` is only for :meth:`do_init`, which may assemble a brand new site
        whose skeleton is still empty.
        """
        started = time.monotonic()
        areas = self._author_areas(site)
        work_layers = self._layers(site, "work")
        preview_layers = self._layers(site, "preview", areas)
        problem = self._preflight(
            site,
            {"work": work_layers, "preview": preview_layers},
            force=force,
            dry_run=dry_run,
            allow_empty_source=allow_empty_source,
        )
        if problem is not None:
            warn(
                "mirror refused: the trees were left untouched",
                error=problem.get("error"),
                tree=problem.get("tree"),
                old=problem.get("old"),
                new=problem.get("new"),
            )
            return MirrorResult(
                ok=False,
                direction="forward",
                in_sync=False,
                dry_run=dry_run,
                guard_tripped=True,
                guard_message=f"{problem.get('error')}: {problem.get('message')}",
                refusal=problem,
                ms=int((time.monotonic() - started) * 1000),
            )
        if dry_run:
            work_count = sum(len(self._layer_files(root, skip)) for root, _dest, skip in work_layers)
            preview_count = sum(len(self._layer_files(root, skip)) for root, _dest, skip in preview_layers)
        else:
            work_count = self._assemble_tree(site, site.work, work_layers)
            self.ensure_work_repo(site)
            preview_count = self._assemble_tree(site, site.preview, preview_layers)
        authors = [
            {
                "uid": uid,
                "area": area,
                "source": str(root),
                "copied": len(self._layer_files(root)),
                "updated": 0,
                "deleted": 0,
                "unchanged": 0,
                "bytes": 0,
                "missing": [],
                "removed": [],
                "in_sync": True,
                "ok": True,
            }
            for uid, area, root in areas
        ]
        preview = MirrorResult(
            ok=True,
            direction="forward",
            copied=preview_count,
            scanned_src=preview_count,
            in_sync=True,
            dry_run=dry_run,
            authors=authors,
            authors_ok=True,
            ms=int((time.monotonic() - started) * 1000),
        )
        result = MirrorResult(
            ok=True,
            direction="forward",
            copied=work_count,
            scanned_src=work_count,
            in_sync=True,
            dry_run=dry_run,
            authors=authors,
            authors_ok=True,
            preview=preview,
            ms=int((time.monotonic() - started) * 1000),
        )
        if not dry_run:
            self.runtime.last_mirror = result.to_dict()
        return result

    def _site_in_force(self, expected: Site | None = None) -> Site:
        """The site a writer must use **now**: every caller of this runs inside the site lock.

        A request validates the site *before* it waits for the lock (:meth:`Handler._site`), and a
        ``POST /site/reset`` or a configuration push can replace it while it waits. Writing the
        snapshot it took would write the old site's directories. So every writer resolves the site
        again inside the critical section, and the one in force is the one the write belongs to.

        ``expected`` is for the operations that take their own lock (a build, a publish, a pull, an
        init): their handler cannot re-read for them, so a site that is no longer the one it
        validated answers ``409`` with ``reason: "SITE_CHANGED"`` instead of quietly acting on
        another site.
        """
        site = self.cfg.site
        if site is None:
            raise Conflict(
                "the site was reset while this request was waiting; nothing was written",
                reason="SITE_CHANGED",
            )
        if expected is not None and site is not expected:
            raise Conflict(
                "the site configuration changed while this request was waiting; retry the request",
                reason="SITE_CHANGED",
            )
        return site

    @contextmanager
    def _site_lock(self):
        """Hold the one site lock: the serialisation every tree writer shares.

        ``do_build``, ``do_publish``, ``do_pull``, ``do_init``, ``do_reset`` and the debounce
        scheduler all take ``runtime.lock``, and so do an author delta and a real (non ``dry_run``)
        Apply source. That is what keeps a save out of a tree a build is reading and keeps two
        deltas from writing the same pending/online path at the same time.

        None of the methods this wraps takes the lock itself (``do_mirror``, ``apply_source`` and
        ``do_author_delta`` document that the caller holds it), so a plain
        :class:`threading.Lock` is enough: there is no nested acquisition anywhere and therefore no
        self-deadlock. A caller that cannot get the lock in time answers ``409 CONFLICT`` exactly
        like the operations that already did this by hand.
        """
        rt = self.runtime
        if not rt.lock.acquire(timeout=self.cfg.build_timeout_s + 30):
            raise Conflict("another task is already running for this site, retry later")
        try:
            yield rt
        finally:
            rt.lock.release()

    def do_build(self, site: Site, *, force: bool = False, skip_mirror: bool = False) -> dict:
        """Reassemble the trees (unless skipped) and build the preview under the one site lock.

        The build runs in the preview tree, which the round assembles from ``source/`` + ``online/``
        + every author folder when ``skip_mirror`` is not set - the same explicit round as Apply
        source. A stale commit tree is no longer repaired by a separate step here (the old
        consistency repair ran even with ``skip_mirror``): Apply source and init own that repair, and
        a publish refuses a drifted commit tree with ``WORK_DRIFT``. ``skip_mirror`` builds the trees
        exactly as they are.
        """
        rt = self.runtime
        if not rt.lock.acquire(timeout=self.cfg.build_timeout_s + 30):
            raise Conflict("another task is already running for this site, retry later")
        try:
            # the site may have been reset or replaced while this request waited for the lock
            site = self._site_in_force(site)
            if not skip_mirror:
                # Refuse before the assembly below can clear anything: it rebuilds the commit tree,
                # and it must not do so from a source layer that is empty or unreadable.
                problem = self._mirror_preflight(site, force=force)
                if problem is not None:
                    warn(
                        "build refused: the assembly would have emptied a tree",
                        error=problem.get("error"),
                        tree=problem.get("tree"),
                    )
                    return {
                        "ok": False,
                        "error": problem.get("error"),
                        "message": problem.get("message"),
                        "mirror": problem,
                        "build": None,
                    }
            mirror_result: dict | None = None
            if not skip_mirror:
                m = self.do_mirror(site, force=force)
                mirror_result = m.to_dict()
                if not m.ok:
                    # The assembly was refused (see _preflight): nothing was cleared, so there is
                    # nothing to build either - building the stale tree would report a site that
                    # does not match its inputs.
                    return {"ok": False, "mirror": mirror_result, "build": None, **self._author_block(mirror_result)}
            result = run_build(site, self.cfg, self.state)
            rt.dirty_at = None
            return {
                "ok": result.ok,
                "mirror": mirror_result,
                "build": result.to_dict(),
                **self._author_block(mirror_result),
            }
        finally:
            rt.lock.release()

    @staticmethod
    def _author_block(mirror_result: Mapping[str, Any] | None) -> dict:
        """Lift the author results of a round to the top level, for a caller that reads them there.

        Empty - no key at all - for a single-source site, so those responses are unchanged.
        """
        authors = (mirror_result or {}).get("authors") or []
        return {"authors": authors} if authors else {}

    def ensure_remote(self, site: Site) -> None:
        """Make the publish repository's remote match the config, which is authoritative.

        An empty ``repo_url`` removes the remote: "no remote" is a configured state, not a missing
        value, and a stale remote left behind would make the next publish push somewhere nobody asked
        for.
        """
        if not gitops.is_repo(site.work):
            return
        before = gitops.run(site.work, ["remote", "get-url", site.remote_name], check=False)
        gitops.set_remote(site.work, site.remote_name, site.repo_url)
        if site.repo_url and (not before.ok or before.stdout.strip() != site.repo_url):
            info("remote set from config", remote=site.remote_name, url=site.repo_url)

    def do_publish(
        self,
        site: Site,
        message: str,
        *,
        force: bool = False,
        stage_paths: Any = None,
    ) -> dict:
        """Check the commit tree, then stage, commit and - only with a remote - push.

        A publish is the **check and the commit**, nothing else: no mirror and no build runs here.
        The content of the commit tree is maintained by the operations that own it - Apply source
        assembles the skeleton, an approval writes the published page - so publishing submits what
        the tree already holds instead of re-deriving it, and a save that the tree legitimately does
        not carry yet (a draft) is never swept in by an implicit round.

        The check is :meth:`assembly_drift`: the commit tree must be exactly the current
        ``source/`` + ``online/`` assembly. A missing, different or extra file raises
        :class:`~pages_bridge.util.WorkDrift` (409 ``WORK_DRIFT``) **before the first git command
        runs**, and nothing is committed. A publish never repairs the tree - Apply source
        (``POST /site/mirror``) is the repair - so a stale tree can never be cleared in the middle of
        a commit. A ``source/`` that exists but cannot be read is refused the same way
        (``reason: "SOURCE_UNREADABLE"``) instead of being read as empty. ``force`` is accepted and
        ignored: there is no delete guard to skip here.

        The push is a second step that exists only when a remote is configured, and its failure
        never undoes the commit: the answer reports it under ``push_error`` with the number of
        commits the remote is ahead, so the app can say what happened instead of guessing from
        stderr.

        ``stage_paths`` limits what is committed to the paths the app names (its own author area,
        typically): with several sources in one work copy, an author's round must not sweep another
        author's unfinished files into its commit. Without it everything is staged, as before. The
        names are **site-relative**, which is exactly the flat commit tree's own layout.
        """
        paths = validate_stage_paths(site.work, stage_paths)
        rt = self.runtime
        if not rt.lock.acquire(timeout=self.cfg.build_timeout_s + 30):
            raise Conflict("another task is already running for this site, retry later")
        try:
            # the site may have been reset or replaced while this request waited for the lock
            site = self._site_in_force(site)
            # The commit tree must be the current assembly. The check is read-only: nothing is
            # rebuilt, staged or committed when it fails, and it runs before the first git command
            # (even the remote update below) so a refused publish changes nothing at all.
            try:
                drift = self.assembly_drift(site)
            except _LayerUnreadable as exc:
                raise WorkDrift(
                    "the site's source directory could not be read, so nothing was committed",
                    reason="SOURCE_UNREADABLE",
                    dir=str(exc.root),
                ) from exc
            if drift is not None:
                raise WorkDrift(
                    "the commit tree is not the current source/ + online/ assembly, so nothing was committed",
                    **drift,
                    hint=(
                        "run Apply source (POST /site/mirror) to reassemble the commit tree, then "
                        "publish again"
                    ),
                )
            self.ensure_remote(site)
            stage_errors: list[str] = []
            if paths is None:
                gitops.stage_all(site.work)
            else:
                stage_errors = gitops.stage_paths(site.work, paths)
            sha = gitops.commit(site.work, message)
            out: dict[str, Any] = {
                "ok": True,
                "commit": sha,
                "remote_configured": site.has_remote,
                "branch": site.branch,
                "pushed": False,
                "nothing_to_commit": sha is None,
                "git": gitops.status(site.work).to_dict(),
            }
            if sha and site.has_remote:
                push = gitops.push(site.work, site.remote_name, site.branch)
                if push["status"] == "ok":
                    out["pushed"] = True
                else:
                    ahead: int | None = None
                    if push["status"] == "rejected":
                        # the rejected push proves the remote moved, but the count has to be the
                        # remote's own: fetch first, then ask the tracking ref
                        gitops.fetch(site.work, site.remote_name, site.branch)
                        ahead = gitops.remote_ahead(site.work, site.remote_name, site.branch)
                    out["push_error"] = {
                        "code": "PUSH_REJECTED" if push["status"] == "rejected" else "PUSH_FAILED",
                        "ahead": ahead,
                        "stderr": push.get("stderr", ""),
                        "hint": push.get("hint", ""),
                    }
            if paths is not None:
                out["stage_paths"] = paths
                # Always present when the caller limited the staging, empty included: a path ``git
                # add`` refused is a **partial** commit, not a failed one (the other paths and the
                # publication's own changes really are in it), so ``ok`` stays true - but a caller
                # must be able to tell the two apart. Leaving the key out made the answer
                # indistinguishable from a complete publish, and a page that never entered the
                # commit was reported as published. The app decides how to phrase it; the bridge
                # only reports what happened.
                out["stage_errors"] = stage_errors
                if stage_errors:
                    warn(
                        "the publish committed, but some of the named paths were not staged",
                        failed=len(stage_errors),
                        requested=len(paths),
                        hint="git refused these paths; see /site/diff and /site/status",
                    )
            return out
        finally:
            rt.lock.release()

    def pull_preview(self, site: Site) -> dict:
        """The facts the Pull confirmation needs, before anything is replaced.

        ``remote_ahead`` is refreshed with a fetch, because the dialog names a commit count and a
        stale tracking ref would name a number nobody can reproduce. ``local_unpublished`` counts the
        files in the Nextcloud folder that differ from the publish tree: exactly what a complete
        replacement will back up and overwrite.
        """
        out: dict[str, Any] = {
            "ok": True,
            "remote_configured": site.has_remote,
            "remote_ahead": None,
            "repo": gitops.is_repo(site.work),
            "head": "",
            "local_unpublished": 0,
        }
        try:
            drift = drift_report(site.source_dir, site.work, self.exclusion(site), self.cfg.mirror_verify)
            out["local_unpublished"] = int(drift.get("pending_copy", 0)) + int(drift.get("pending_delete", 0))
        except Exception as exc:  # noqa: BLE001 - the count is a hint, never a reason to fail
            out["local_unpublished_error"] = repr(exc)
        if not site.has_remote or not out["repo"]:
            return out
        gitops.set_remote(site.work, site.remote_name, site.repo_url)
        out["head"] = gitops.head_sha(site.work)
        # A preflight is interactive: a hanging network must not hold the confirmation dialog (or the
        # doctor) for the full publish timeout, so this fetch gives up early. A failed fetch is not a
        # failed preflight - the local facts are still worth reporting, and the answer says the remote
        # could not be reached instead of inventing an ahead count from a stale tracking ref.
        try:
            fetched = gitops.fetch(site.work, site.remote_name, site.branch, timeout=15)
        except GitError as exc:
            out["reachable"] = False
            out["fetch_error"] = exc.message
            return out
        out["reachable"] = fetched.ok
        if not fetched.ok:
            out["fetch_error"] = fetched.stderr.strip()[:300]
            return out
        out["remote_ahead"] = gitops.remote_ahead(site.work, site.remote_name, site.branch)
        return out

    def do_pull(self, site: Site) -> dict:
        """Replace the publish tree with the remote branch, then package its **source** for the app.

        The move is a complete replacement, not a merge or a fast-forward: ``land_remote`` resets a
        repository with history and creates the branch on an unborn HEAD, so a rejected push (the
        remote moved, the local commit is not an ancestor) is repaired by the same call.

        Nothing is written into the Nextcloud folder here: the app replaces that folder through the
        Nextcloud file API (the only writer the file cache sees), using the archive this returns.

        **The archive is the flat remote tree minus the paths this instance knows as published.**
        The repository's root is the site itself (``_config.yml``, ``_layouts/``, ``authors-src/``…),
        so there is no ``source/`` subtree to archive. ``online/`` in Nextcloud is the published
        record, and its file list is passed to :func:`~pages_bridge.gitops.archive_source` as an
        exclude pathspec: those pages are online content, and the next Apply source must not commit
        them as the administrator's skeleton. The answer says ``source_only: true`` and how many
        paths were excluded.

        A pull repairs a missing publish repository itself: after a reset (or on a fresh install) the
        work copy may not exist at all, and asking for a separate Init click made the one-click flow
        fragile. The existing init path is used - create the repository and fetch the refs, without
        writing files - and the pull then lands the remote branch in the tree (the one path allowed to
        materialise files, because they are only packaged for the app).
        """
        if not site.has_remote:
            raise BadRequest(
                "no git remote is configured",
                hint="set the git remote in the site settings first; without one a pull has nothing to pull from.",
            )
        rt = self.runtime
        if not rt.lock.acquire(timeout=self.cfg.build_timeout_s + 30):
            raise Conflict("another task is already running for this site, retry later")
        try:
            # the site may have been reset or replaced while this request waited for the lock
            site = self._site_in_force(site)
            if not gitops.is_repo(site.work):
                healed = gitops.init_or_clone(site.work, site.repo_url, site.branch, site.remote_name)
                info("pull created the missing publish repository", action=healed.get("action"))
            self.ensure_remote(site)
            pull = gitops.pull(site.work, site.remote_name, site.branch)
            # The published paths of *this* site: online content the archive must not present as
            # source. Only known targets can be left out - the flat repository carries no marker.
            published = sorted(self._layer_files(site.online_dir))
            archive = gitops.archive_source(
                site.work,
                self.pull_archive_path,
                "HEAD",
                drop=published,
            )
            return {
                "ok": True,
                "pull": pull,
                "archive": archive,
                "head": pull.get("after", ""),
                "changed": pull.get("changed", []),
            }
        finally:
            rt.lock.release()

    @property
    def pull_archive_path(self) -> Path:
        """Where the last pull left the tar.gz the app downloads; one file, overwritten on purpose."""
        return self.cfg.state_dir / "pull" / "source.tar.gz"

    def do_init(self, site: Site) -> dict:
        """Create the publish repository and assemble the commit/preview trees; no checkout.

        The site folder is the source of truth, so init only establishes *where* a commit would go:
        ``git init`` (plus remote and fetch when a remote is configured). It never clones or checks
        anything out; the work tree's files come from the assembly (``source/`` + ``online/``, plus
        the author folders in the preview tree). A remote that does not answer is reported as
        ``remote_unreachable``, not raised - a site without a reachable remote is still editable and
        publishable. Bringing remote content into Nextcloud is Pull's job, and Pull replaces the
        folder completely (fetch -> land -> archive -> app writes the folder).

        The answer is ``ok: False`` when the **assembly** was refused (the delete guard, an
        unreadable source): the repository is established, but the trees were left untouched and
        the round is not a bootstrap, so the refusal sits at the top level (``error``/``message``)
        and nothing is marked bootstrapped. A caller can therefore tell "init is done" from "init
        established the repository and the assembly must be revisited" instead of reading the git
        part as the whole answer.
        """
        rt = self.runtime
        if not rt.lock.acquire(timeout=600):
            raise Conflict("another task is already running for this site, retry later")
        try:
            # the site may have been reset or replaced while this request waited for the lock
            site = self._site_in_force(site)
            # A write killed between its temporary file and the rename left that file in the site
            # folder; remove it before anything reads the layers.
            self._sweep_atomic_residue(site)
            # A stale commit tree is a repair, not a refusal: online/ is the record. Init is the
            # bootstrap exception, so the repair may assemble a site whose skeleton is still empty
            # (allow_empty_source); any other caller keeps the source check.
            self.ensure_work_consistent(
                site, request="POST /site/init", allow_empty_source=True
            )
            if not site.source_dir.is_dir():
                raise ConfigError(
                    "the site source directory does not exist",
                    source_dir=str(site.source_dir),
                    hint="create source/ in the site folder; it holds the Jekyll skeleton",
                )
            detail: dict[str, Any] = {
                "source_had_content": any(site.source_dir.iterdir()),
                "work_was_repo": gitops.is_repo(site.work),
                "remote_configured": site.has_remote,
                "head": gitops.head_sha(site.work) if gitops.is_repo(site.work) else "",
            }
            detail["git"] = gitops.init_or_clone(
                site.work, site.repo_url, site.branch, site.remote_name
            )
            if detail["git"].get("remote_unreachable"):
                detail["note"] = "remote_unreachable"
            mirror = self.do_mirror(site, allow_empty_source=True)
            detail["mirror"] = mirror.to_dict()
            detail["head"] = gitops.head_sha(site.work)
            if not mirror.ok:
                # The repository was established, but the assembly was refused before it cleared
                # anything: the trees are exactly as they were, and this round is not a bootstrap.
                # The refusal travels at the top level so a caller cannot read "ok" out of the git
                # half; the mirror's own block stays under ``mirror``.
                refusal = mirror.refusal or {}
                error = refusal.get("error") or "MIRROR_REFUSED"
                message = refusal.get("message") or mirror.guard_message or "the assembly was refused"
                warn("init established the repository but the assembly was refused", error=error)
                return {
                    "ok": False,
                    "error": error,
                    "message": message,
                    **detail,
                    **self._author_block(detail["mirror"]),
                }
            self.state.mark_bootstrapped(detail)
            return {"ok": True, **detail, **self._author_block(detail.get("mirror"))}
        finally:
            rt.lock.release()

    def touch(self, site: Site) -> dict:
        """Mark the site dirty; a background thread **builds** the preview after the debounce window.

        Only a build: the delta that called this already wrote the one file it named into the preview
        tree, and the skeleton is maintained by Apply source, so a round that assembled the trees
        here would clear exactly that file for a uid no record names yet.
        """
        rt = self.runtime
        rt.dirty_at = time.monotonic()
        rt.generation += 1
        return {
            "ok": True,
            "debounce_ms": self.cfg.build_debounce_ms,
            "generation": rt.generation,
        }

    def touch_onlinepreview(self, site: Site) -> dict:
        """Mark the review tree dirty; the scheduler rebuilds it after the debounce window.

        Its own clock next to ``touch()``: the two trees are built independently (the review round
        runs ``_refresh_onlinepreview + run_build(tree="onlinepreview")``), so a candidate must not
        consume the author preview's pending change, nor the other way round.
        """
        rt = self.runtime
        rt.onlinepreview_dirty_at = time.monotonic()
        rt.generation += 1
        return {
            "ok": True,
            "tree": "onlinepreview",
            "debounce_ms": self.cfg.build_debounce_ms,
            "generation": rt.generation,
        }

    def do_reset(self) -> dict:
        """Drop the site: delete every tree, forget every stored setting, become UNPAIRED.

        This is the bridge half of "reset Pages", the app's danger zone. All three host trees are
        derived in the new model, but the reset still deletes them **on purpose** (with the site
        configuration gone they would be orphaned). The app-owned configuration (the pushed site,
        the pushed globals and both secrets) is dropped too, which is what makes the bridge
        UNPAIRED again and lets the app pair from a clean state. Nothing under the Nextcloud data
        directory is touched: ``source/``, ``online/`` and ``pending/`` live there and are the
        app's to manage, not the bridge's.

        The call is idempotent: once the bridge is unpaired there is no site left to drop and no
        credential to check it with, so a second call answers ``already_clean`` and changes nothing.
        """
        if not self.paired:
            return {
                "ok": True,
                "already_clean": True,
                "paired": False,
                "removed": [],
                "cleared": [],
            }
        rt = self.runtime
        if not rt.lock.acquire(timeout=self.cfg.build_timeout_s + 30):
            raise Conflict("another task is already running for this site, retry later")
        try:
            # The marker goes first, into the **bootstrap** state_dir: the TOML may still carry both
            # secrets and a [site] table - install.sh copies an example with neither, but a
            # hand-written config (the cross-host path) has them - so deleting only effective.json
            # would let the next start pair the bridge again - and a pushed state_dir must not move
            # the very marker that says "was reset" (load_config reads it next to effective.json).
            # From this write on, a restart comes up UNPAIRED; a successful POST /pair clears it.
            flag = self.cfg.bootstrap_state_dir / UNPAIRED_FLAG
            atomic_write_text(
                flag,
                json.dumps({"at": time.time(), "reason": "POST /site/reset"}) + "\n",
            )

            removed: list[str] = []
            site = self.cfg.site
            if site is not None:
                # every tree, each once (a hand-written TOML may point two of them at the same
                # directory); a directory that is not there is already the state this reset wants
                for path in dict.fromkeys((site.work, site.preview, site.onlinepreview)):
                    if not path.exists():
                        continue
                    shutil.rmtree(path)
                    removed.append(str(path))

            # a debounced build must not run against a tree that is no longer the site's
            rt.dirty_at = None
            rt.onlinepreview_dirty_at = None
            rt.onlinepreview_mirrored = False
            rt.generation += 1

            cleared: list[str] = []
            for path in (
                self.pushed_config_path,
                self.legacy_pushed_path,
                self.pull_archive_path,
            ):
                if path.exists():
                    path.unlink()
                    cleared.append(str(path))
            # a pairing code issued before the reset must not survive it; clear the bootstrap file and
            # one an older bridge may have left in a pushed state_dir
            for directory in self._bootstrap_state_dirs():
                pairing.clear(directory)
            # the remembered build report describes a site that no longer exists
            if self.state.path.exists():
                self.state.path.unlink()
                self.state.data = {}
                cleared.append(str(self.state.path))

            rt.site = None
            rt.last_mirror = None
            rt.last_onlinepreview_mirror = None
            self.paired = False
            self.config_revision = None
            self.config_source = "toml"
            # the TOML is the administrator's own file and is left untouched; only what the app pushed
            # is forgotten, so a restart of an app-paired bridge stays UNPAIRED as well
            self.cfg = replace(
                self.cfg,
                paired=False,
                site=None,
                shared_secret="",
                session_signing_secret="",
                app_globals={},
                site_raw=None,
                config_revision=None,
                config_source="toml",
            )
            info("bridge reset from the Nextcloud app", removed=removed, cleared=cleared)
            return {
                "ok": True,
                "already_clean": False,
                "paired": False,
                "protocol": PROTOCOL,
                "version": SERVER_VERSION,
                "removed": removed,
                "cleared": cleared,
                "flag": str(flag),
            }
        finally:
            rt.lock.release()

    def start_scheduler(self) -> None:
        self._scheduler = threading.Thread(target=self._scheduler_loop, name="debounce", daemon=True)
        self._scheduler.start()

    def stop_scheduler(self) -> None:
        self._stop.set()

    def _scheduler_loop(self) -> None:
        window = max(0.1, self.cfg.build_debounce_ms / 1000.0)
        while not self._stop.wait(0.2):
            rt = self.runtime
            if rt.site is None:
                # nothing to build yet: a paired bridge waits for the app to push the site
                rt.dirty_at = None
                rt.onlinepreview_dirty_at = None
                continue
            now = time.monotonic()
            # The two trees have their own dirty marks and are built one after the other when both
            # are due (preview first, the review tree second). A stamp is only cleared if it is
            # still the one this tick read: a save that arrives during the preview build stays due.
            preview_stamp = rt.dirty_at if rt.dirty_at is not None and now - rt.dirty_at >= window else None
            review_stamp = (
                rt.onlinepreview_dirty_at
                if rt.onlinepreview_dirty_at is not None and now - rt.onlinepreview_dirty_at >= window
                else None
            )
            if preview_stamp is None and review_stamp is None:
                continue
            if not rt.lock.acquire(blocking=False):
                continue  # a task is running, retry on the next tick
            try:
                if rt.site is None:
                    # a reset may have dropped the site between the top check and the lock
                    continue
                # A save names one file, and the delta already wrote it into the preview tree; the
                # skeleton is maintained by Apply source. So this round has nothing to assemble and
                # only builds. (Mirroring here cleared the preview and laid the author layers down
                # again, and a uid no record names yet - a brand new author - lost the file that was
                # just saved; the next save was wiped the same way.)
                if preview_stamp is not None:
                    if rt.dirty_at == preview_stamp:
                        rt.dirty_at = None
                    try:
                        run_build(rt.site, self.cfg, self.state)
                    except BridgeError as exc:
                        warn("debounced build failed", error=exc.code, message=exc.message)
                    except Exception as exc:  # noqa: BLE001 - background threads must not die
                        warn("debounced build raised", error=repr(exc))
                if review_stamp is not None:
                    if rt.onlinepreview_dirty_at == review_stamp:
                        rt.onlinepreview_dirty_at = None
                    try:
                        self._refresh_onlinepreview(rt.site)
                        run_build(rt.site, self.cfg, self.state, tree="onlinepreview")
                    except BridgeError as exc:
                        warn("debounced onlinepreview build failed", error=exc.code, message=exc.message)
                    except Exception as exc:  # noqa: BLE001 - background threads must not die
                        warn("debounced onlinepreview build raised", error=repr(exc))
            finally:
                rt.lock.release()

    def author_status(self, site: Site) -> list[dict]:
        """One entry per author source: the documented keys, plus what explains a bad one.

        ``entries`` is the preview list (everything the author's area holds); ``on_site`` is the
        legacy commit list the app used to push. ``missing`` and ``drift`` are computed live against
        the **preview** tree, which is the one a mirror writes now (the commit tree holds published
        content, not the source), and the counters come from the last author round.
        ``bytes`` is what that round copied, in bytes.
        """
        previous = {
            entry.get("uid"): entry
            for entry in ((self.runtime.last_mirror or {}).get("authors") or [])
        }
        excl = self.author_exclusion(site)
        items: list[dict] = []
        for author in site.authors:
            last = previous.get(author.uid) or {}
            try:
                drift = author_drift(
                    author.source,
                    site.preview,
                    excl,
                    author,
                    self.cfg.mirror_verify,
                    entries=author.entries,
                )
            except Exception as exc:  # noqa: BLE001 - the status panel must always answer
                drift = {"in_sync": False, "error": repr(exc)}
            item: dict[str, Any] = {
                "uid": author.uid,
                "path": author.path,
                "source": str(author.source),
                "source_missing": not author.source.is_dir(),
                # the preview list vs the on-site subset: the two numbers explain the split
                "entries": len(author.entries),
                "on_site": len(author.commit_entries),
                "copied": last.get("copied", 0),
                "updated": last.get("updated", 0),
                "deleted": last.get("deleted", 0),
                "unchanged": last.get("unchanged", 0),
                "bytes": last.get("bytes", 0),
                "missing": drift.get("missing", last.get("missing", [])),
                "removed": last.get("removed", []),
                "drift": drift,
                "ok": last.get("ok", True),
            }
            if author.onsite_pushed:
                item["on_site_pushed"] = True
            if last.get("withheld"):
                item["withheld"] = last["withheld"]
            if last.get("guard_message"):
                item["guard_message"] = last["guard_message"]
            items.append(item)
        return items

    def site_paths(self, site: Site) -> dict[str, Any]:
        """The two Nextcloud content directories, as the app has to read them.

        ``online/`` and ``pending/`` are written by this bridge, never by Nextcloud: a file lands
        through a temporary file and a rename (:meth:`_write_tree_file`), so on an instance with the
        default ``filesystem_check_changes=0`` the filecache does not know it until something scans
        the folder. An app that asks ``Folder::getDirectoryListing()`` for "is it live / is it
        waiting" therefore misses the file the bridge just wrote - a publish would look like it never
        happened, and the next decision would answer "nothing is pending". This is the same walk, on
        the bridge's own host and off the filesystem, for the app to consume in one request:

            {"online": [target, ...], "pending": [target, ...],
             "online_at": {target: mtime}, "pending_at": {target: mtime}}

        ``mtime`` is whole seconds since the epoch (0 for a file that vanished between the walk and
        the stat), and a target is exactly the key the manifest uses: site-relative, POSIX
        separators, ``<author_root>/<uid>/<rel>``. A directory that is not there is an empty layer
        (a site that was never published has no ``online/``), never an error.

        Deliberately lock-free and read-only: a reader may see the state one operation behind, which
        is a stale answer for a moment, while taking the site lock would make every page view wait
        for a running build.
        """
        online = self._layer_files(site.online_dir)
        pending = self._layer_files(site.pending_dir)
        return {
            "online": sorted(online),
            "pending": sorted(pending),
            "online_at": self._layer_mtimes(online),
            "pending_at": self._layer_mtimes(pending),
        }

    @staticmethod
    def _layer_mtimes(files: dict[str, Path]) -> dict[str, int]:
        """``target => mtime`` for one layer, in whole seconds (0 when the file is gone already)."""
        stamps: dict[str, int] = {}
        for target, path in files.items():
            try:
                stamps[target] = int(path.stat().st_mtime)
            except OSError:
                stamps[target] = 0
        return stamps

    def status(self, site: Site) -> dict:
        """The status panel's read-only view: git facts, drift, last build and the author table.

        Everything here is cheap (no fetch, no mirror); the numbers a dialog needs freshly are asked
        for by the route that shows the dialog.
        """
        rt = self.runtime
        state = self.state
        paths = self.site_paths(site)
        out: dict[str, Any] = {
            "source": str(site.source),
            # the three Nextcloud inputs; source/ is admin-edited, online/ and pending/ are the
            # bridge's write targets. A non-admin view drops them like preview/work.
            "source_dir": str(site.source_dir),
            "online_dir": str(site.online_dir),
            "pending_dir": str(site.pending_dir),
            "work": str(site.work),
            # the tree the preview is built and served from; output_dir is inside it
            "preview": str(site.preview),
            "output_dir": str(site.output_dir),
            # the review tree: the published content plus the frozen candidates, built and served on
            # its own. An app that must not show host paths to non-admins drops these keys like it
            # already drops preview/work (and the review build's own records further down).
            "onlinepreview": str(site.onlinepreview),
            "onlinepreview_output": str(site.onlinepreview_output_dir),
            # the targets under review, read straight off pending/ (no set file any more); with
            # none pending the review tree equals the commit tree. The published half and the two
            # mtime maps travel too: they are what the app needs to answer "is it live" without the
            # Nextcloud filecache, and a status panel that shows them shows what the app sees.
            "pending": paths["pending"],
            "online": paths["online"],
            "online_at": paths["online_at"],
            "pending_at": paths["pending_at"],
            "bootstrapped": state.bootstrapped,
            "pending_debounce": rt.dirty_at is not None,
            # the review tree has its own debounce clock: a candidate does not mark the preview
            "pending_onlinepreview_debounce": rt.onlinepreview_dirty_at is not None,
            # the publish repository exists = the site has been initialised. The app locks the remote
            # and the branch on this fact (a change would silently repoint an existing history); it is
            # derived from the working copy, so it needs no stored state of its own.
            "initialized": gitops.is_repo(site.work),
            # the work-vs-online consistency, computed live: a non-null value is the repair Apply
            # source or init performs (or that the startup check already performed), and a publish
            # refuses the tree instead. Size-only: the status route must not read every published
            # file on each poll.
            "work_drift": self.work_drift(site, deep=False),
        }
        try:
            out["git"] = gitops.status(site.work).to_dict() if gitops.is_repo(site.work) else None
            out["last_commit"] = gitops.last_commit(site.work) if gitops.is_repo(site.work) else None
        except BridgeError as exc:
            out["git_error"] = exc.message
        # the remote is optional, and the ahead count here is what the last fetch saw: the fresh one
        # is asked for by /site/pull/preview, because only a dialog has to pay for a fetch
        out["remote_configured"] = site.has_remote
        out["head"] = gitops.head_sha(site.work) if gitops.is_repo(site.work) else ""
        out["remote_ahead"] = (
            gitops.remote_ahead(site.work, site.remote_name, site.branch)
            if site.has_remote and gitops.is_repo(site.work)
            else None
        )
        try:
            out["drift"] = drift_report(site.source_dir, site.work, self.exclusion(site), self.cfg.mirror_verify)
        except Exception as exc:  # noqa: BLE001
            out["drift_error"] = repr(exc)
        out["last_build"] = state.last_build
        out["last_mirror"] = rt.last_mirror
        # the review tree's own records: a candidate build and a preview build are separate results
        out["last_onlinepreview_build"] = state.last_onlinepreview_build
        out["last_onlinepreview_mirror"] = rt.last_onlinepreview_mirror
        out["output_files"] = (
            sum(1 for _ in site.output_dir.rglob("*") if _.is_file()) if site.output_dir.is_dir() else 0
        )
        # only a site that has authors reports them, so a single-source site answers exactly as before
        if site.authors:
            out["authors"] = self.author_status(site)
        return out


Route = tuple[str, re.Pattern[str], str]

# single site: no route carries an id. The preview route carries the signed token in its address,
# because the caller is an iframe: a browser cannot put an Authorization header on an iframe. The
# bearer secret and a ?t=<token> query stay supported for a proxy that rewrites the address.
ROUTES: list[Route] = [
    ("GET", re.compile(r"^/health$"), "health"),
    ("GET", re.compile(r"^/env$"), "env"),
    ("GET", re.compile(r"^/admin/config$"), "admin_config_get"),
    ("POST", re.compile(r"^/admin/config$"), "admin_config_post"),
    ("DELETE", re.compile(r"^/admin/config$"), "admin_config_delete"),
    ("POST", re.compile(r"^/site/init$"), "site_init"),
    ("POST", re.compile(r"^/site/author-root$"), "site_author_root"),
    ("POST", re.compile(r"^/site/author-delta$"), "site_author_delta"),
    # /site/mirror is Apply source: the app normally sends the same op through
    # /site/author-delta. It stays as the diagnostic/repair endpoint too: a dry run is the layer
    # count an operator needs and a real run rebuilds all three trees.
    ("POST", re.compile(r"^/site/mirror$"), "site_mirror"),
    ("POST", re.compile(r"^/site/touch$"), "site_touch"),
    ("POST", re.compile(r"^/site/build$"), "site_build"),
    ("GET", re.compile(r"^/site/status$"), "site_status"),
    # the two content directories alone, for the app's own reads: cheap, lock-free, no git
    ("GET", re.compile(r"^/site/paths$"), "site_paths"),
    ("GET", re.compile(r"^/site/diff$"), "site_diff"),
    ("GET", re.compile(r"^/site/log$"), "site_log"),
    ("GET", re.compile(r"^/site/published-content$"), "site_published_content"),
    ("GET", re.compile(r"^/site/pull/preview$"), "site_pull_preview"),
    ("GET", re.compile(r"^/site/archive$"), "site_archive"),
    ("POST", re.compile(r"^/site/pull$"), "site_pull"),
    ("POST", re.compile(r"^/site/publish$"), "site_publish"),
    ("POST", re.compile(r"^/site/reset$"), "site_reset"),
    ("GET", re.compile(r"^/site/session$"), "site_session"),
    ("GET", re.compile(r"^/preview/(?P<token>[^/]+)/(?P<path>.+)$"), "preview"),
]

#: Routes that do not require the Bearer shared secret. ``pair`` is open on purpose: it is the one
#: route that exists before any secret does, and it carries its own one-time code instead. On a
#: paired bridge it answers 409 before it looks at the body.
OPEN_ROUTES = {"health", "preview", "pair"}

#: The bootstrap route. It deliberately stays out of ROUTES, which is the frozen table of the
#: data and control routes this bridge serves: pairing is not part of the normal API surface.
PAIR_ROUTE: Route = ("POST", re.compile(r"^/pair$"), "pair")

#: The only routes that answer while the bridge is UNPAIRED. Everything else is 503 until the app
#: has pushed its secrets - a bridge with no credential must not serve anything but its own pairing,
#: and the reset answer that says "there is nothing left to drop".
UNPAIRED_ROUTES = {"health", "pair", "site_reset"}


class Handler(BaseHTTPRequestHandler):
    server_version = SERVER_VERSION
    protocol_version = "HTTP/1.1"

    @property
    def app(self) -> App:
        return self.server.app  # type: ignore[attr-defined]

    def log_message(self, fmt: str, *args: Any) -> None:  # noqa: A003
        return  # structured logging only, no default stderr line

    def _read_body(self) -> dict:
        length = int(self.headers.get("Content-Length") or 0)
        if length <= 0:
            return {}
        if length > 4 << 20:
            raise BadRequest("request body too large", limit_bytes=4 << 20)
        raw = self.rfile.read(length)
        if not raw:
            return {}
        try:
            data = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise BadRequest("request body is not valid JSON", detail=str(exc)) from exc
        if not isinstance(data, dict):
            raise BadRequest("request body must be a JSON object")
        return data

    def _send(self, status: int, payload: Any, content_type: str = "application/json") -> None:
        if isinstance(payload, (dict, list)):
            body = json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")
        elif isinstance(payload, str):
            body = payload.encode("utf-8")
        else:
            body = payload
        self.send_response(status)
        self.send_header("Content-Type", f"{content_type}; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        # HEAD sends headers only (RFC 9110); Content-Length still tells the truth
        if self.command != "HEAD":
            self.wfile.write(body)

    def _send_bytes(self, status: int, body: bytes, content_type: str) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    # every HTTP method needs a do_XXX, or BaseHTTPRequestHandler answers a bare 501
    def do_GET(self) -> None:  # noqa: N802
        self._dispatch("GET")

    def do_POST(self) -> None:  # noqa: N802
        self._dispatch("POST")

    def do_PUT(self) -> None:  # noqa: N802
        self._dispatch("PUT")

    def do_PATCH(self) -> None:  # noqa: N802
        self._dispatch("PATCH")

    def do_DELETE(self) -> None:  # noqa: N802
        self._dispatch("DELETE")

    def do_HEAD(self) -> None:  # noqa: N802
        self._dispatch("HEAD")

    def do_OPTIONS(self) -> None:  # noqa: N802
        self._dispatch("OPTIONS")

    def _dispatch(self, method: str) -> None:
        parsed = urlparse(self.path)
        path = parsed.path
        query = parse_qs(parsed.query)

        # the pairing route is appended for its own path only, so ROUTES keeps exactly its own table
        routes = [*ROUTES, PAIR_ROUTE] if PAIR_ROUTE[1].match(path) else ROUTES

        path_matched = False
        for route_method, pattern, name in routes:
            match = pattern.match(path)
            if not match:
                continue
            path_matched = True
            # a path may serve several methods, so keep looking instead of answering 405
            if route_method != method:
                continue
            try:
                if not self.app.paired and name not in UNPAIRED_ROUTES:
                    # no unpaired bridge serves anything but /health and /pair
                    raise Unpaired()
                # /site/reset on an UNPAIRED bridge is the idempotent "already clean" answer and there
                # is no secret left to check it against; every other route is authenticated as before
                if name not in OPEN_ROUTES and not (name == "site_reset" and not self.app.paired):
                    verify_bearer(self.headers.get("Authorization"), self.app.cfg.shared_secret)
                handler: Callable[..., Any] = getattr(self, f"_h_{name}")
                status, payload, ctype = handler(match.groupdict(), query)
                self._send(status, payload, ctype)
            except BridgeError as exc:
                self._send(exc.http, exc.to_dict())
            except Exception as exc:  # noqa: BLE001 - last resort, never leave a hanging connection
                warn("unhandled exception", path=path, error=repr(exc))
                self._send(500, {"error": "INTERNAL", "message": repr(exc)})
            return

        if path_matched:
            allowed = sorted({m for m, p, _ in routes if p.match(path)})
            self._send(
                405,
                {"error": "METHOD_NOT_ALLOWED", "message": f"{path} does not support {method}", "allowed": allowed},
            )
            return
        self._send(404, {"error": "NOT_FOUND", "message": f"no route matches {method} {path}"})

    def _h_health(self, params: dict, query: dict) -> tuple[int, Any, str]:
        app = self.app
        # the two site directories, so the split is visible without /admin/config (null until the
        # app pushes a site); the preview is served from <preview_dir>/_site
        site = app.cfg.site
        if not app.paired:
            # the version and the protocol are what the settings form needs to say "not paired yet"
            return 200, {
                "ok": True,
                "service": "pages-bridge",
                "version": SERVER_VERSION,
                "protocol": PROTOCOL,
                "paired": False,
                "pairing_required": True,
                "uptime_s": int(time.time() - app.started_at),
            }, "application/json"
        return 200, {
            "ok": True,
            "service": "pages-bridge",
            "version": SERVER_VERSION,
            # the app holds the same constant; a mismatch must fail loudly
            "protocol": PROTOCOL,
            "paired": True,
            "config_source": app.config_source,
            "config_revision": app.config_revision,
            # the three Nextcloud inputs, then the three derived host trees: one block of facts an
            # app can hide from non-admins as a whole
            "source_dir": str(site.source_dir) if site is not None else None,
            "online_dir": str(site.online_dir) if site is not None else None,
            "pending_dir": str(site.pending_dir) if site is not None else None,
            "work_dir": str(site.work) if site is not None else None,
            "preview_dir": str(site.preview) if site is not None else None,
            # the third tree: the reviewer's preview (published content + frozen candidates); null
            # until the app pushes a site, exactly like the two older directories
            "onlinepreview_dir": str(site.onlinepreview) if site is not None else None,
            "uptime_s": int(time.time() - app.started_at),
        }, "application/json"

    def _h_pair(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """One-time pairing: the only way into a bridge that has no secrets yet."""
        if self.app.paired:
            raise AlreadyPaired()
        body = self._read_body()
        return 200, self.app.pair(body), "application/json"

    def _h_admin_config_get(self, params: dict, query: dict) -> tuple[int, Any, str]:
        site = self.app.cfg.site
        return 200, {
            "ok": True,
            "protocol": PROTOCOL,
            "config_source": self.app.config_source,
            "config_revision": self.app.config_revision,
            "site": site.to_dict() if site is not None else None,
            "nc_data_root": str(self.app.cfg.nc_data_root),
            "work_root": str(self.app.cfg.work_root),
            # the effective values and where each one comes from, for the "advanced" panel
            "global": effective_globals(self.app.cfg),
            "sources": global_sources(self.app.cfg),
        }, "application/json"

    def _h_admin_config_post(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """The app pushes the configuration; this end only validates and persists it."""
        body = self._read_body()
        return 200, self.app.apply_pushed_config(body), "application/json"

    def _h_admin_config_delete(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """Drop the pushed config so the service falls back to TOML."""
        cleared: list[str] = []
        for path in (self.app.pushed_config_path, self.app.legacy_pushed_path):
            if path.exists():
                path.unlink()
                cleared.append(path.name)
        return 200, {
            "ok": True,
            "note": "pushed config cleared; the service falls back to TOML after a restart",
            "cleared": cleared,
            "config_source": self.app.config_source,
        }, "application/json"

    def _h_env(self, params: dict, query: dict) -> tuple[int, Any, str]:
        # the capability report plus what is in force, where it came from and the secret fingerprints
        report = env_report(self.app.cfg)
        report["config_source"] = self.app.config_source
        report["revision"] = self.app.config_revision
        return 200, report, "application/json"

    def _site(self) -> Site:
        # the one site of this bridge; nothing is selected by a request parameter
        site = self.app.cfg.site
        if site is None:
            raise ConfigError(
                "no site is configured yet",
                hint="push the site configuration from the Nextcloud Pages settings",
            )
        return site

    def _h_site_init(self, params: dict, query: dict) -> tuple[int, Any, str]:
        return 200, self.app.do_init(self._site()), "application/json"

    def _h_site_author_root(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """The one writer of the author content directory (the config push treats it as information)."""
        body = self._read_body()
        return 200, self.app.set_author_root(body), "application/json"

    def _h_site_author_delta(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """One author-content event, the boot overwrite, or Apply source.

        The op writes pending/ or online/ in Nextcloud and/or a host tree, so it runs under the site
        lock every build and publish holds (see :meth:`App._site_lock`). Without it a save landing
        inside the debounce window could write while the scheduled round was reading the same tree.
        The lock covers the whole op, which is one indivisible write: the helpers it calls never
        take the lock themselves, so this cannot deadlock.

        The site is resolved again **inside** the lock: a queued request validated the site before
        it waited, and a reset or a configuration push may have replaced it in the meantime. Writing
        the snapshot would write the old site's directories, so the site in force is the one the
        delta belongs to (and a site that is gone answers ``409 SITE_CHANGED``).
        """
        body = self._read_body()
        self._site()  # no site at all: answer before waiting for the lock
        with self.app._site_lock():
            site = self.app._site_in_force()
            result = self.app.do_author_delta(site, body)
        return 200, result, "application/json"

    def _h_site_reset(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """Drop the site: delete the three trees, forget the pushed config, become UNPAIRED."""
        return 200, self.app.do_reset(), "application/json"

    def _h_site_mirror(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """**Apply source**: delete all three trees and rebuild them from the Nextcloud folders.

        Body: ``{authors?, dry_run?, build? (default true), build_onlinepreview?}``. A real run holds
        the site lock (it is a writer); a dry run only counts and stays lock-free, so an operator can
        assess the damage while a build is running. A real run resolves the site again inside the
        lock - a queued request must rebuild the site in force, never the one it validated before
        waiting (see :meth:`App._site_in_force`); a dry run keeps its lock-free snapshot on purpose.
        """
        body = self._read_body()
        site = self._site()
        dry_run = bool(body.get("dry_run"))
        if dry_run:
            result = self.app.apply_source(site, {**body, "dry_run": True})
        else:
            with self.app._site_lock():
                site = self.app._site_in_force()
                result = self.app.apply_source(site, body)
        return 200, result, "application/json"

    def _h_site_touch(self, params: dict, query: dict) -> tuple[int, Any, str]:
        return 200, self.app.touch(self._site()), "application/json"

    def _h_site_build(self, params: dict, query: dict) -> tuple[int, Any, str]:
        body = self._read_body()
        return 200, self.app.do_build(
            self._site(),
            force=bool(body.get("force")),
            skip_mirror=bool(body.get("skip_mirror")),
        ), "application/json"

    def _h_site_status(self, params: dict, query: dict) -> tuple[int, Any, str]:
        return 200, self.app.status(self._site()), "application/json"

    def _h_site_paths(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """``online/`` and ``pending/`` as target lists plus their mtimes, for the app's own reads.

        The app answers "is this page live / is it waiting" on every list it renders, and it cannot
        use the Nextcloud filecache for it: this bridge writes those folders itself, so with the
        default ``filesystem_check_changes=0`` the filecache lags behind a publish or a candidate
        (:meth:`App.site_paths`). This route is that answer - two directory walks, no git, no build,
        no lock - and the app asks for it once per request. Read-only: nothing here may move the
        site, and a stale answer for a moment is allowed.
        """
        return 200, {"ok": True, **self.app.site_paths(self._site())}, "application/json"

    def _h_site_diff(self, params: dict, query: dict) -> tuple[int, Any, str]:
        site = self._site()
        if not gitops.is_repo(site.work):
            return 200, {"stat": "", "patch": "", "note": "not a git repository yet"}, "application/json"
        return 200, {
            "stat": gitops.diff_stat(site.work),
            "patch": tail_lines(gitops.diff(site.work), 2000),
            "status": gitops.status(site.work).to_dict(),
        }, "application/json"

    def _h_site_log(self, params: dict, query: dict) -> tuple[int, Any, str]:
        tail = int((query.get("tail") or ["0"])[0] or 0)
        last = self.app.state.last_build or {}
        log_path = self.app.cfg.log_dir / f"{ARTIFACT_STEM}.log"
        full = ""
        if log_path.is_file():
            try:
                full = log_path.read_text(encoding="utf-8", errors="replace")
            except OSError:
                full = ""
        return 200, {
            "build_id": last.get("build_id"),
            "ok": last.get("ok"),
            "log": tail_lines(full, tail) if tail else full,
        }, "application/json"

    def _h_site_published_content(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """Read one **published** file back out of ``online/`` (or the assembled commit tree), base64.

        The app needs this when an offline request is refused: the source file is already gone, so the
        only copy of the page left is the published one in ``online/`` (``work/`` is the fallback for a
        tree that has not been repaired yet; the commit tree is flat, so the target path is the same).
        The app writes those bytes into the orphan owner's site source itself; this route only reads -
        it runs no mirror, no build and no commit, so asking for a page can never move the site.

        Contract: ``target`` is site-relative and must name ``<author_root>/<uid>/<file>`` (the uid
        may be the orphan owner, which owns no Nextcloud account); ``400 BAD_REQUEST`` otherwise. A
        target that is not published answers ``404 {"error":"NOT_FOUND"}``, one above
        :data:`PUBLISHED_CONTENT_MAX_BYTES` answers ``413 {"error":"TOO_LARGE"}``, and a readable one
        answers ``200 {"ok":true,"target","bytes","content_base64"}`` - base64, so a binary file
        travels unharmed through JSON.
        """
        site = self._site()
        target = (query.get("target") or [""])[0]
        if not self.app._author_file_target_ok(target, site):
            raise BadRequest(
                "target must name a file in an author directory",
                target=target[:200],
                shape=f"{site.author_root}/<uid>/<file>",
            )
        # The published record is online/ (the bridge writes it atomically); the commit tree is the
        # fallback for a tree that has not been rebuilt yet. The shape check above already refuses
        # traversal; safe_join plus the real-path compare are belt and braces.
        path: Path | None = None
        for root in (site.online_dir, site.work):
            try:
                candidate = safe_join(root, target)
            except PathTraversal as exc:
                raise BadRequest("target must be a site-relative path", target=target[:200]) from exc
            if is_within(candidate, root) and candidate.is_file():
                path = candidate
                break
        if path is None:
            return 404, {"error": "NOT_FOUND"}, "application/json"
        try:
            size = path.stat().st_size
        except OSError:
            return 404, {"error": "NOT_FOUND"}, "application/json"
        if size > PUBLISHED_CONTENT_MAX_BYTES:
            return 413, {"error": "TOO_LARGE"}, "application/json"
        try:
            content = path.read_bytes()
        except OSError as exc:
            return 500, {"error": "READ_FAILED", "message": str(exc)}, "application/json"
        return 200, {
            "ok": True,
            "target": target,
            "bytes": len(content),
            "content_base64": base64.b64encode(content).decode("ascii"),
        }, "application/json"

    def _h_site_pull_preview(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """Read-only facts for the Pull confirmation: what would be replaced, and the ahead count."""
        return 200, self.app.pull_preview(self._site()), "application/json"

    def _h_site_archive(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """The tar.gz of the pulled **source**, for the app's complete source replacement.

        The Nextcloud side may not read this host's filesystem (and under snap it cannot), so the
        pulled content travels over the same signed channel as everything else, once, as one file.
        The archive is the flat remote tree (``HEAD``) minus the paths this instance knows as
        published, so the app can write it into the Nextcloud ``source/`` folder without turning
        online pages into the administrator's skeleton.
        """
        path = self.app.pull_archive_path
        if not path.is_file():
            return 404, {"error": "NOT_FOUND", "message": "no pull archive exists; run pull first"}, "application/json"
        try:
            body = path.read_bytes()
        except OSError as exc:
            return 500, {"error": "READ_FAILED", "message": str(exc)}, "application/json"
        return 200, body, "application/gzip"

    def _h_site_pull(self, params: dict, query: dict) -> tuple[int, Any, str]:
        return 200, self.app.do_pull(self._site()), "application/json"

    def _h_site_publish(self, params: dict, query: dict) -> tuple[int, Any, str]:
        body = self._read_body()
        site = self._site()
        message = str(body.get("message") or "").strip()
        if not message:
            message = f"Update via Nextcloud Pages ({time.strftime('%Y-%m-%d %H:%M')})"
        if len(message) > 500:
            raise BadRequest("commit message too long", limit=500)
        return 200, self.app.do_publish(
            site,
            message,
            force=bool(body.get("force")),
            # the app names the paths of one author; without it the whole work copy is staged
            stage_paths=body.get("stage_paths"),
        ), "application/json"

    def _h_site_session(self, params: dict, query: dict) -> tuple[int, Any, str]:
        ttl = int((query.get("ttl") or ["3600"])[0] or 3600)
        scopes = (query.get("scope") or ["preview"])[0].split(",")
        # the server caps the TTL, clients cannot extend it
        ttl = max(60, min(ttl, 12 * 3600))
        token, exp = issue(
            self.app.cfg.session_signing_secret, scopes=scopes, ttl=ttl
        )
        return 200, {
            "token": token,
            "exp": exp,
            "ttl": ttl,
            "scope": scopes,
            "preview_base": f"/preview/{token}/",
            "note": "an iframe URL equals a bearer credential: do not keep it in a page for long, re-issue before it expires",
        }, "application/json"

    @staticmethod
    def _preview_target(output: Path, rel: str) -> Path:
        """Resolve a preview address inside the build output.

        The policy is the resolved address, nothing else: whatever really sits inside the build
        directory is servable (no extension list, no dot-file rule), anything outside is refused.
        ``safe_join`` rejects lexical traversal, the real-path compare rejects a symlink pointing
        out of the tree.
        """
        try:
            target = safe_join(output, rel)
            if target.is_dir():
                target = safe_join(output, rel.rstrip("/") + "/index.html")
        except PathTraversal as exc:
            raise Forbidden(f"path escapes the build output: {rel}") from exc
        if not is_within(target, output):
            raise Forbidden(f"path escapes the build output: {rel}")
        return target

    def _h_preview(self, params: dict, query: dict) -> tuple[int, Any, str]:
        """Serve build artifacts, for the Nextcloud PHP proxy or an edge proxy.

        The output is inside the author preview tree (``site.output_dir``) by default, never the
        commit tree: the preview and the commit cannot see each other's files, which is the point of
        the split. ``?tree=onlinepreview`` selects the review tree instead
        (``site.onlinepreview_output_dir``): the same token, the same resolution rules, a different
        build output - the reviewer must see the frozen candidate, not the author's live file.
        """
        site = self._site()
        rel = unquote(params["path"])

        # auth: the bearer secret, or the signed token the address itself carries
        header = self.headers.get("Authorization")
        if header:
            verify_bearer(header, self.app.cfg.shared_secret)
        else:
            token = params.get("token") or (query.get("t") or [""])[0]
            verify(token, self.app.cfg.session_signing_secret, scope="preview")

        tree = (query.get("tree") or ["preview"])[0]
        if tree not in ("preview", "onlinepreview"):
            raise BadRequest(
                "unknown preview tree",
                got=tree[:40],
                known=["preview", "onlinepreview"],
            )

        output = site.onlinepreview_output_dir if tree == "onlinepreview" else site.output_dir
        target = self._preview_target(output, rel)
        if not target.is_file():
            return 404, {
                "error": "NOT_FOUND",
                "message": "no such file in the build output",
                "path": rel,
                "tree": tree,
            }, "application/json"

        ctype, _ = mimetypes.guess_type(str(target))
        try:
            body = target.read_bytes()
        except OSError as exc:
            return 500, {"error": "READ_FAILED", "message": str(exc)}, "application/json"
        return 200, body, ctype or "application/octet-stream"


def build_server(cfg: Config, port: int | None = None) -> tuple[ThreadingHTTPServer, App]:
    """Build but do not start the server; split out so tests can use a random port."""
    app = App(cfg)
    try:
        httpd = ThreadingHTTPServer((cfg.listen_host, cfg.listen_port if port is None else port), Handler)
    except OSError as exc:
        # A pushed listen that cannot be bound must never lock the administrator out of the very UI
        # that fixes it, so the address from the config file is used instead. A listen that came from
        # the config file itself is a real error and stays one.
        if port is not None or "listen" not in cfg.app_globals:
            raise
        warn(
            "the pushed listen cannot be bound, falling back to the address from the config file",
            pushed=f"{cfg.listen_host}:{cfg.listen_port}",
            fallback=f"{cfg.fallback_listen_host}:{cfg.fallback_listen_port}",
            error=str(exc),
        )
        app.use_listen(cfg.fallback_listen_host, cfg.fallback_listen_port)
        httpd = ThreadingHTTPServer((cfg.fallback_listen_host, cfg.fallback_listen_port), Handler)
    httpd.daemon_threads = True
    httpd.app = app  # type: ignore[attr-defined]
    return httpd, app


def serve(cfg: Config) -> None:
    httpd, app = build_server(cfg)
    # The startup consistency check is a **repair**, not a refusal: nothing on the host is
    # load-bearing any more, so a commit tree that does not hold the published files is rebuilt from
    # source/ + online/ before the first request is served. A site that has never been assembled
    # simply stays empty until Apply source fills it. When the rebuild itself is refused (an empty or
    # unreadable source/), verify_state_files writes nothing, logs the refusal and answers ok: false;
    # the bridge still starts, and Apply source is the repair.
    verdict = app.verify_state_files()
    if verdict.get("rebuilt"):
        info("the commit tree was rebuilt at startup", files=verdict.get("files"))
    app.start_scheduler()
    info(
        "pages-bridge started",
        # the effective address: a pushed listen that could not be bound was replaced already
        listen=f"{app.cfg.listen_host}:{app.cfg.listen_port}",
        work_root=str(app.cfg.work_root),
        protocol=PROTOCOL,
        paired=app.paired,
        pid=os.getpid(),
    )
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        info("interrupt received, shutting down")
    finally:
        app.stop_scheduler()
        httpd.server_close()
