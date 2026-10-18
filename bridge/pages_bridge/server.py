"""HTTP layer: routing, auth, single-site serialization and debounce scheduling."""

from __future__ import annotations

import json
import mimetypes
import os
import re
import threading
import time
from dataclasses import dataclass, field, replace
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Callable, Mapping
from urllib.parse import parse_qs, unquote, urlparse

from . import PROTOCOL, __version__, gitops, pairing
from .builder import env_report, run_build
from .config import (
    ARTIFACT_STEM,
    FALLBACK_EXCLUDE,
    SECRET_KEYS,
    Config,
    Site,
    assemble_config,
    author_target_prefixes,
    check_nc_data_root,
    effective_globals,
    global_sources,
    parse_listen,
    validate_globals,
    validate_secret,
)
from .mirror import Exclusion, MirrorResult, author_drift, drift_report, sync, sync_author
from .session import issue, verify, verify_bearer
from .state import SiteState
from .util import (
    AlreadyPaired,
    BadRequest,
    BridgeError,
    ConfigError,
    Conflict,
    Forbidden,
    GitError,
    PathTraversal,
    Unpaired,
    atomic_write_text,
    info,
    is_within,
    read_json,
    safe_join,
    tail_lines,
    warn,
    write_json,
)

SERVER_VERSION = f"pages-bridge/{__version__}"


@dataclass
class SiteRuntime:
    #: ``None`` while no site is configured yet (an UNPAIRED bridge starts without one)
    site: Site | None
    lock: threading.Lock = field(default_factory=threading.Lock)
    dirty_at: float | None = None
    generation: int = 0
    last_mirror: dict | None = None


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
    """Data plane only: the control plane is the PHP app, the lifecycle is systemd."""

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

    @property
    def pushed_config_path(self) -> Path:
        """The app-owned config. It always sits where the TOML points, never where a push points."""
        return self.cfg.bootstrap_state_dir / "effective.json"

    @property
    def legacy_pushed_path(self) -> Path:
        """The pushed config of the previous app version: site only, no global block, no secrets."""
        return self.cfg.bootstrap_state_dir / "sites.json"

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
        self.config_source = candidate.config_source
        self.config_revision = candidate.config_revision
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

        Order matters: everything is validated first, then the one-time code is spent, then the
        configuration is written and adopted. A wrong code therefore never changes anything, and a
        bad payload never burns a code (the administrator would have to re-run install.sh for that).
        """
        if self.paired:
            raise AlreadyPaired()

        code = body.get("code")
        if not isinstance(code, str) or not code.strip():
            raise BadRequest("code is required", hint="run bridge/install.sh on the bridge host")

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

        # the code is checked last: only a request that would really work spends it. It is read and
        # cleared where it was issued (a push may move state_dir, and a code must not survive that).
        code_dir = self.cfg.state_dir
        pairing.verify(code_dir, str(code))
        self._store_effective(
            pushed_globals,
            dict(site_raw) if isinstance(site_raw, Mapping) else {},
            secrets,
            revision,
        )
        self._adopt(candidate)
        pairing.clear(code_dir)
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

    def exclusion(self, site: Site) -> Exclusion:
        """Build the exclusion policy for a site.

        With authors the policy also covers their two areas: ``_posts/<uid>/…`` and
        With authors the policy also covers their areas: ``<author_root>/<uid>/…`` and the legacy
        ``_posts/<uid>/…`` / ``assets/authors/…``. Excluded entries are invisible on both sides of
        the skeleton mirror, so it neither copies the site owner's files over an author's target
        nor deletes them - the author mirrors own those paths. Without authors nothing is added,
        and the policy is exactly the one a single-source site has always used.
        """
        author_prefixes = tuple(
            prefix
            for author in site.authors
            for prefix in author_target_prefixes(author.uid, site.author_root)
        )
        return self._exclusion(site, author_prefixes=author_prefixes)

    def author_exclusion(self, site: Site) -> Exclusion:
        """The policy an author target is checked against: the site's own, without the author areas."""
        return self._exclusion(site, author_prefixes=())

    def _exclusion(self, site: Site, *, author_prefixes: tuple[str, ...]) -> Exclusion:
        # the repository's .gitignore decides what is not source; the fallback list is a last resort
        cfg = self.cfg
        unexcluded = {u.strip("/").lower() for u in cfg.unexclude}
        names = {n for n in site.exclude if n.lower() not in unexcluded}

        entries = gitops.ignored_entries(site.work) if cfg.use_gitignore else None
        if entries is None:
            names |= {n for n in FALLBACK_EXCLUDE if n.lower() not in unexcluded}
            prefixes: list[str] = []
            info("no .gitignore available, using the built-in fallback exclusions", work=str(site.work))
        else:
            prefixes = [e for e in entries if e.rstrip("/").lower() not in unexcluded]

        # the output directory is always excluded
        output = site.recipe.output_dir
        if output and output != "." and output.rstrip("/") not in prefixes:
            prefixes.append(output)
        # The build's own dependency directory: the recipe sets BUNDLE_PATH there, so the bridge
        # itself creates it in the preview tree. It is never source, whether or not the repository's
        # .gitignore mentions it (a missing entry would otherwise be a mass deletion of the gems).
        bundle_path = str(site.recipe.env.get("BUNDLE_PATH", "")).strip().strip("/")
        if bundle_path and ".." not in Path(bundle_path).parts and bundle_path not in prefixes:
            prefixes.append(bundle_path)
        prefixes.extend(author_prefixes)

        return Exclusion.for_site(names, output, prefixes)

    def ensure_work_repo(self, site: Site) -> dict | None:
        """Re-create the work copy repository when ``.git`` is gone; failure is not fatal."""
        # /tmp gets cleared, so the work copy must be able to rebuild itself from the source
        if gitops.is_repo(site.work):
            return None
        try:
            info("work copy is not a git repository, re-creating it", work=str(site.work))
            return gitops.init_or_adopt(site.work, site.repo_url, site.branch, site.remote_name)
        except Exception as exc:  # noqa: BLE001
            warn("failed to re-create the work copy repository (mirroring anyway)", error=repr(exc))
            return {"action": "failed", "error": repr(exc)}

    def author_mirrors(
        self,
        site: Site,
        *,
        tree: str = "commit",
        force: bool = False,
        dry_run: bool = False,
    ) -> list:
        """Run every author round into one tree, in config order; callers hold the lock.

        ``tree="commit"`` mirrors the on-site list into the work copy and also drops a target that is
        still in ``entries`` but no longer on the site (a page that was turned internal): that tree
        must hold the on-site set and nothing else. ``tree="preview"`` mirrors every entry into the
        preview directory; a target is deleted there only when the app named it removed, so an
        internal page stays previewable.
        """
        root = site.preview if tree == "preview" else site.work
        excl = self.author_exclusion(site)
        results = []
        for author in site.authors:
            if not author.source.is_dir():
                # never fatal: a user whose folder is not there yet contributes nothing this round,
                # and the round must not delete anything of theirs either
                warn(
                    "author source directory does not exist, reporting its entries missing",
                    uid=author.uid,
                    source=str(author.source),
                    tree=tree,
                )
            if tree == "preview":
                entries: tuple = author.entries
                stale: tuple[str, ...] = ()
            else:
                entries = author.commit_entries
                stale = self.turned_internal(author)
            results.append(
                sync_author(
                    author.source,
                    root,
                    excl,
                    author,
                    entries=entries,
                    stale=stale,
                    verify=self.cfg.mirror_verify,
                    delete_guard_ratio=self.cfg.mirror_delete_guard_ratio,
                    delete_guard_min=self.cfg.mirror_delete_guard_min,
                    force=force,
                    dry_run=dry_run,
                    preserve_exec=self.cfg.mirror_preserve_exec,
                    author_root=site.author_root,
                )
            )
        return results

    @staticmethod
    def turned_internal(author) -> list[str]:
        """Targets the commit tree must drop because the page is not on the site any more.

        Only app-named data is used: a target of ``entries`` (the author area still holds the file)
        that is not in the pushed on-site list. Nothing is inferred from a filesystem diff, and an
        old client that pushes no ``on_site`` list at all keeps the single-tree behaviour.
        """
        if not author.onsite_pushed:
            return []
        approved = {entry.target for entry in author.onsite}
        return [entry.target for entry in author.entries if entry.target not in approved]

    @staticmethod
    def _keep_ignored(site: Site):
        """Deletion filter for one tree: whatever the repository's ignore rules cover is kept.

        ``git ls-files --others --ignored`` can only report ignored files that exist in the commit
        tree, while the preview tree is where the build creates ``vendor/bundle`` and
        ``.jekyll-cache``. Asking ``git check-ignore`` about the planned deletions keeps those out
        of the delete plan (and out of the delete guard) without a second ignore parser.
        """
        return lambda paths: gitops.ignored_matches(site.work, paths)

    def _mirror_commit(self, site: Site, *, force: bool = False, dry_run: bool = False) -> MirrorResult:
        """Mirror the site source plus the on-site author files into the commit tree."""
        excl = self.exclusion(site)
        result = sync(
            site.source,
            site.work,
            excl,
            verify=self.cfg.mirror_verify,
            delete_guard_ratio=self.cfg.mirror_delete_guard_ratio,
            delete_guard_min=self.cfg.mirror_delete_guard_min,
            force=force,
            dry_run=dry_run,
            direction="forward",
            preserve_exec=self.cfg.mirror_preserve_exec,
            keep=self._keep_ignored(site),
        )
        # skeleton first, then each author: the commit tree is assembled from every source and only
        # then committed. An aborted skeleton round (its own delete guard) ends the round here.
        if result.ok and site.authors:
            authors = self.author_mirrors(site, tree="commit", force=force, dry_run=dry_run)
            result.authors = [author.to_dict() for author in authors]
            result.authors_ok = all(author.ok for author in authors)
            if not result.authors_ok:
                result.ok = False
        return result

    def _mirror_preview(self, site: Site, *, force: bool = False, dry_run: bool = False) -> MirrorResult:
        """Mirror the site source plus every author file into the preview tree."""
        excl = self.exclusion(site)
        result = sync(
            site.source,
            site.preview,
            excl,
            verify=self.cfg.mirror_verify,
            delete_guard_ratio=self.cfg.mirror_delete_guard_ratio,
            delete_guard_min=self.cfg.mirror_delete_guard_min,
            force=force,
            dry_run=dry_run,
            direction="forward",
            preserve_exec=self.cfg.mirror_preserve_exec,
            keep=self._keep_ignored(site),
        )
        if result.ok and site.authors:
            authors = self.author_mirrors(site, tree="preview", force=force, dry_run=dry_run)
            result.authors = [author.to_dict() for author in authors]
            result.authors_ok = all(author.ok for author in authors)
            if not result.authors_ok:
                result.ok = False
        return result

    def do_mirror(self, site: Site, *, force: bool = False, dry_run: bool = False) -> MirrorResult:
        """Refresh both directories; the top-level result is the commit tree's round.

        The preview tree's round is nested under ``result.preview``. The two are mirrored in one
        pass because both are views of the same pushed state: the preview must not lag behind a
        publish, or an iframe would serve a page the commit already dropped (or the reverse).
        """
        if not dry_run:
            self.ensure_work_repo(site)
        commit = self._mirror_commit(site, force=force, dry_run=dry_run)
        preview = self._mirror_preview(site, force=force, dry_run=dry_run)
        commit.preview = preview
        # one tree failing means the pushed state is not in force anywhere: report the round as failed
        commit.ok = commit.ok and preview.ok
        self.runtime.last_mirror = commit.to_dict()
        return commit

    def do_build(self, site: Site, *, force: bool = False, skip_mirror: bool = False) -> dict:
        rt = self.runtime
        if not rt.lock.acquire(timeout=self.cfg.build_timeout_s + 30):
            raise Conflict("another task is already running for this site, retry later")
        try:
            mirror_result: dict | None = None
            if not skip_mirror:
                m = self.do_mirror(site, force=force)
                mirror_result = m.to_dict()
                if not m.ok:
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
        """Make the work copy remote match the config, which is authoritative."""
        if not gitops.is_repo(site.work):
            return
        current = gitops.run(site.work, ["remote", "get-url", site.remote_name], check=False)
        if not current.ok:
            gitops.run(site.work, ["remote", "add", site.remote_name, site.repo_url])
            info("remote added", remote=site.remote_name)
        elif current.stdout.strip() != site.repo_url:
            gitops.run(site.work, ["remote", "set-url", site.remote_name, site.repo_url])
            info("remote updated from config", remote=site.remote_name, url=site.repo_url)

    def do_publish(
        self,
        site: Site,
        message: str,
        *,
        force: bool = False,
        stage_paths: Any = None,
    ) -> dict:
        """Mirror, stage, commit and push. **No build runs here.**

        The commit tree is only ever a git working tree: its files are mirrored (site source plus
        the on-site author files), staged, committed and pushed. The site generator runs in the
        preview tree only, so a publish never pays for a build and never depends on one.

        ``stage_paths`` limits what is committed to the paths the app names (its own author area,
        typically): with several sources in one work copy, an author's round must not sweep another
        author's unfinished files into its commit. Without it everything is staged, as before.
        """
        paths = validate_stage_paths(site.work, stage_paths)
        rt = self.runtime
        if not rt.lock.acquire(timeout=self.cfg.build_timeout_s + 30):
            raise Conflict("another task is already running for this site, retry later")
        try:
            mirror_result = self.do_mirror(site, force=force).to_dict()
            self.ensure_remote(site)
            stage_errors: list[str] = []
            if paths is None:
                gitops.stage_all(site.work)
            else:
                stage_errors = gitops.stage_paths(site.work, paths)
            sha = gitops.commit(site.work, message)
            pushed = False
            if sha:
                gitops.push(site.work, site.remote_name, site.branch)
                pushed = True
            out = {
                "ok": True,
                "mirror": mirror_result,
                "commit": sha,
                "pushed": pushed,
                "nothing_to_commit": sha is None,
                "git": gitops.status(site.work).to_dict(),
                **self._author_block(mirror_result),
            }
            if paths is not None:
                out["stage_paths"] = paths
                if stage_errors:
                    out["stage_errors"] = stage_errors
            return out
        finally:
            rt.lock.release()

    def do_pull(self, site: Site) -> dict:
        rt = self.runtime
        if not rt.lock.acquire(timeout=self.cfg.build_timeout_s + 30):
            raise Conflict("another task is already running for this site, retry later")
        try:
            self.ensure_remote(site)
            pull = gitops.pull(site.work, site.remote_name, site.branch)
            changed = pull.get("changed") or []
            reverse: dict | None = None
            if changed:
                # reverse flow: write the work tree back to the Nextcloud source
                excl = self.exclusion(site)
                result = sync(
                    site.work,
                    site.source,
                    excl,
                    verify="size+mtime",
                    delete_guard_ratio=self.cfg.mirror_delete_guard_ratio,
                    delete_guard_min=self.cfg.mirror_delete_guard_min,
                    direction="reverse",
                )
                reverse = result.to_dict()
            return {"ok": True, "pull": pull, "reverse_mirror": reverse, "scan_paths": changed}
        finally:
            rt.lock.release()

    def reverse_fill(self, site: Site, *, force: bool = False) -> MirrorResult:
        """Work copy to source; only for bootstrapping an empty source."""
        excl = self.exclusion(site)
        result = sync(
            site.work,
            site.source,
            excl,
            verify=self.cfg.mirror_verify,
            delete_guard_ratio=self.cfg.mirror_delete_guard_ratio,
            delete_guard_min=self.cfg.mirror_delete_guard_min,
            force=force,
            direction="reverse",
            preserve_exec=self.cfg.mirror_preserve_exec,
        )
        self.runtime.last_mirror = result.to_dict()
        return result

    def do_init(self, site: Site) -> dict:
        """Bootstrap the work copy into a usable git work tree and align both sides."""
        rt = self.runtime
        if not rt.lock.acquire(timeout=600):
            raise Conflict("another task is already running for this site, retry later")
        try:
            state = self.state
            source_exists = site.source.is_dir() and any(site.source.iterdir())
            work_is_repo = gitops.is_repo(site.work)
            work_has_content = site.work.is_dir() and any(
                p.name != ".git" for p in site.work.iterdir()
            )

            detail: dict[str, Any] = {
                "source_had_content": source_exists,
                "work_was_repo": work_is_repo,
            }

            if not work_is_repo and not work_has_content and not source_exists:
                raise BadRequest(
                    "source and work copy are both empty, nothing to initialize",
                    hint="check that the git remote in the site config is reachable, or add site files to Nextcloud first.",
                )

            # the source directory may not exist yet, but sync() refuses to run without it
            site.source.mkdir(parents=True, exist_ok=True)

            clone_error: str | None = None
            if not work_is_repo and not work_has_content:
                # clone before mirroring, or the empty directory is adopted with an unborn HEAD
                try:
                    detail["git"] = gitops.init_or_adopt(
                        site.work, site.repo_url, site.branch, site.remote_name
                    )
                    info("first fetch: cloned", repo=site.repo_url)
                except GitError as exc:
                    clone_error = exc.message
                    warn("clone failed, filling from the source and adopting in place", error=clone_error)

            if source_exists:
                detail["mirror"] = self.do_mirror(site).to_dict()
            else:
                # an empty source would trip the delete guard, so fill it from the work copy instead
                detail["bootstrap"] = "reverse_fill"
                detail["reverse_mirror"] = self.reverse_fill(site).to_dict()
                # the source has content now, so both trees are assembled from it like any other
                # round - the reverse fill itself never writes an author's file into their own folder
                detail["mirror"] = self.do_mirror(site).to_dict()

            # the clone failed or was skipped: the directory has content now, so adopt it in place
            if not gitops.is_repo(site.work):
                detail["git"] = gitops.init_or_adopt(
                    site.work, site.repo_url, site.branch, site.remote_name
                )
            if clone_error is not None:
                detail["clone_error"] = clone_error

            state.mark_bootstrapped(detail)
            return {"ok": True, **detail, **self._author_block(detail.get("mirror"))}
        finally:
            rt.lock.release()

    def touch(self, site: Site) -> dict:
        """Mark the site dirty; a background thread mirrors and builds after the debounce window."""
        rt = self.runtime
        rt.dirty_at = time.monotonic()
        rt.generation += 1
        return {
            "ok": True,
            "debounce_ms": self.cfg.build_debounce_ms,
            "generation": rt.generation,
        }

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
                continue
            if rt.dirty_at is None or time.monotonic() - rt.dirty_at < window:
                continue
            if not rt.lock.acquire(blocking=False):
                continue  # a task is running, retry on the next tick
            try:
                rt.dirty_at = None
                try:
                    self.do_mirror(rt.site)
                    run_build(rt.site, self.cfg, self.state)
                except BridgeError as exc:
                    warn("debounced build failed", error=exc.code, message=exc.message)
                except Exception as exc:  # noqa: BLE001 - background threads must not die
                    warn("debounced build raised", error=repr(exc))
            finally:
                rt.lock.release()

    def author_status(self, site: Site) -> list[dict]:
        """One entry per author source: the documented keys, plus what explains a bad one.

        ``entries`` is the preview list (everything the author's area holds); ``on_site`` is the
        commit list. ``missing`` and ``drift`` are computed live against the **commit** tree - that
        is what a publish sees - the counters come from the last commit round of that author.
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
                    site.work,
                    excl,
                    author,
                    self.cfg.mirror_verify,
                    entries=author.commit_entries,
                    stale=self.turned_internal(author),
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

    def status(self, site: Site) -> dict:
        rt = self.runtime
        state = self.state
        out: dict[str, Any] = {
            "source": str(site.source),
            "work": str(site.work),
            # the tree the preview is built and served from; output_dir is inside it
            "preview": str(site.preview),
            "output_dir": str(site.output_dir),
            "bootstrapped": state.bootstrapped,
            "pending_debounce": rt.dirty_at is not None,
        }
        try:
            out["git"] = gitops.status(site.work).to_dict() if gitops.is_repo(site.work) else None
            out["last_commit"] = gitops.last_commit(site.work) if gitops.is_repo(site.work) else None
        except BridgeError as exc:
            out["git_error"] = exc.message
        try:
            out["drift"] = drift_report(site.source, site.work, self.exclusion(site), self.cfg.mirror_verify)
        except Exception as exc:  # noqa: BLE001
            out["drift_error"] = repr(exc)
        out["last_build"] = state.last_build
        out["last_mirror"] = rt.last_mirror
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
    ("POST", re.compile(r"^/site/mirror$"), "site_mirror"),
    ("POST", re.compile(r"^/site/touch$"), "site_touch"),
    ("POST", re.compile(r"^/site/build$"), "site_build"),
    ("GET", re.compile(r"^/site/status$"), "site_status"),
    ("GET", re.compile(r"^/site/diff$"), "site_diff"),
    ("GET", re.compile(r"^/site/log$"), "site_log"),
    ("POST", re.compile(r"^/site/pull$"), "site_pull"),
    ("POST", re.compile(r"^/site/publish$"), "site_publish"),
    ("GET", re.compile(r"^/site/session$"), "site_session"),
    ("GET", re.compile(r"^/preview/(?P<token>[^/]+)/(?P<path>.+)$"), "preview"),
]

#: Routes that do not require the Bearer shared secret. ``pair`` is open on purpose: it is the one
#: route that exists before any secret does, and it carries its own one-time code instead. On a
#: paired bridge it answers 409 before it looks at the body.
OPEN_ROUTES = {"health", "preview", "pair"}

#: The bootstrap route. It deliberately stays out of ROUTES, which is the frozen table of the 16
#: data and control routes this bridge serves: pairing is not part of the normal API surface.
PAIR_ROUTE: Route = ("POST", re.compile(r"^/pair$"), "pair")

#: The only routes that answer while the bridge is UNPAIRED. Everything else is 503 until the app
#: has pushed its secrets - a bridge with no credential must not serve anything but its own pairing.
UNPAIRED_ROUTES = {"health", "pair"}


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

        # the pairing route is appended for its own path only, so ROUTES keeps exactly its 16 routes
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
                if name not in OPEN_ROUTES:
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
            "work_dir": str(site.work) if site is not None else None,
            "preview_dir": str(site.preview) if site is not None else None,
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

    def _h_site_mirror(self, params: dict, query: dict) -> tuple[int, Any, str]:
        body = self._read_body()
        result = self.app.do_mirror(
            self._site(),
            force=bool(body.get("force")),
            dry_run=bool(body.get("dry_run")),
        )
        status = 200 if result.ok else 409
        return status, result.to_dict(), "application/json"

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

        The output is inside the preview tree (``site.output_dir``), never the commit tree: the
        preview and the commit cannot see each other's files, which is the point of the split.
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

        output = site.output_dir
        target = self._preview_target(output, rel)
        if not target.is_file():
            return 404, {"error": "NOT_FOUND", "message": "no such file in the build output", "path": rel}, "application/json"

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
