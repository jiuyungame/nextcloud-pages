"""Configuration loading and validation (TOML)."""

from __future__ import annotations

import re
import tomllib
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Mapping

from .util import BadRequest, BridgeError, ConfigError, info, is_within, norm_abs, read_json, warn

#: The only generator. The bridge builds Jekyll and nothing else: another generator is a custom
#: ``command``, not a preset, and no marker file selects one.
PRESET = "jekyll"

#: The defaults of :data:`PRESET`; a recipe field left out is taken from here.
PRESET_RECIPE: dict[str, Any] = {
    "command": ["bundle", "exec", "jekyll", "build"],
    "install": ["bundle", "install"],
    "output_dir": "_site",
    "base_config": "_config.yml",
    # deps install into the work copy itself: /usr is read-only under ProtectSystem=full
    "env": {"BUNDLE_PATH": "vendor/bundle", "BUNDLE_BIN": "vendor/bundle/bin"},
    "env_note": "BUNDLE_PATH must match the one used by bundle check, otherwise the dependency check is wrong",
}

#: Non-negotiable exclusions; git never reports its own repository as ignored.
MINIMAL_EXCLUDE = (".git",)

#: Fallback exclusions, used only while the repository's ``.gitignore`` is unavailable.
FALLBACK_EXCLUDE = (
    "_site",
    ".jekyll-cache",
    ".sass-cache",
    ".jekyll-metadata",
    "vendor",
    "node_modules",
    ".bundle",
    "public",
    "dist",
    "Gemfile.lock",
)

_PLACEHOLDERS = {"", "change-me", "changeme", "<应用生成>", "REPLACE_ME"}

#: File stem of the single site's generated overrides and build log. It is an artifact name, not an
#: identity: the bridge is single-site, so nothing is routed, stored or pushed under a site id.
ARTIFACT_STEM = "site"

# ── hosted configuration (§15) ──────────────────────────────────────────────────────────────────

#: The top-level keys the Nextcloud app may push in the ``global`` block of ``POST /pair`` and
#: ``POST /admin/config``. These are exactly the TOML keys this bridge owns, so a pushed value and a
#: hand-written one go through one validation path; anything else is refused with ``400``.
GLOBAL_KEYS: tuple[str, ...] = (
    "listen",
    "nc_data_root",
    "work_root",
    "state_dir",
    "override_dir",
    "log_dir",
    "build_timeout_s",
    "build_debounce_ms",
    "log_tail_lines",
    "mirror_verify",
    "mirror_delete_guard_ratio",
    "mirror_delete_guard_min",
    "mirror_preserve_exec",
    "mirror_use_gitignore",
    "exclude",
    "unexclude",
)

#: The two secret keys, in the order the app sends them.
SECRET_KEYS: tuple[str, ...] = ("shared_secret", "session_signing_secret")

#: Addresses an UNPAIRED bridge may bind: it has no credential to check yet, so it must not be
#: reachable from anywhere but this machine.
LOOPBACK_HOSTS = ("127.0.0.1", "localhost", "::1")

DEFAULT_LISTEN = "127.0.0.1:8787"
DEFAULT_NC_DATA_ROOT = "/var/snap/nextcloud/common/nextcloud/data"
#: The root of the site's three host trees. All of them are **derived** from the site folder in
#: Nextcloud (``source/`` + ``online/`` + ``pending/``), so deleting them loses no site data; they
#: are kept in the same persistent root as the state/override/log directories by default (the
#: commit tree is a git working tree, so history/backups still matter there), and only ``.git``
#: inside ``work`` cannot be regenerated from Nextcloud.
DEFAULT_WORK_ROOT = "/var/lib/pages/work"
DEFAULT_STATE_DIR = "/var/lib/pages/state"
DEFAULT_OVERRIDE_DIR = "/var/lib/pages/overrides"
DEFAULT_LOG_DIR = "/var/lib/pages/logs"

#: The file the app's configuration is written to; always under the TOML's ``state_dir``.
EFFECTIVE_FILE = "effective.json"

#: Left behind by ``POST /site/reset``: while it exists the TOML's secrets and site table are ignored.
#: The example install.sh copies carries neither, but a TOML can be filled in by hand, so deleting
#: only ``effective.json`` would let the next start pair the bridge with its old site again - a reset
#: has to survive a restart. Only a successful
#: ``POST /pair`` clears the marker. It lives in the TOML's ``state_dir`` (the *bootstrap* one, like
#: ``effective.json``): it is read before any pushed configuration is trusted.
UNPAIRED_FLAG = "reset-unpaired.flag"


@dataclass(frozen=True)
class Recipe:
    preset: str
    command: tuple[str, ...] | None
    output_dir: str
    install_cmd: tuple[str, ...] | None = None
    env: Mapping[str, str] = field(default_factory=dict)
    overrides: Mapping[str, Any] = field(default_factory=dict)
    base_config: str | None = None

    def to_dict(self) -> dict[str, Any]:
        return {
            "preset": self.preset,
            "command": list(self.command) if self.command else None,
            "output_dir": self.output_dir,
            "install_cmd": list(self.install_cmd) if self.install_cmd else None,
            "env_keys": sorted(self.env),
            "overrides": dict(self.overrides),
            "base_config": self.base_config,
        }


#: The site directory that receives every author's own tree: author A's files arrive at
#: ``<author_root>/<A>/…``. It is a single path segment, pushed by the app as ``site.author_root``;
#: an older client that omits it keeps working with this default.
AUTHOR_ROOT_DEFAULT = "tmp"

#: Shape of an author root: one path segment that starts with a letter or a digit. The dot and
#: underscore names are refused on purpose - ``..``/``.git`` would be a path or repository the path
#: layer must not accept, ``_site``/``_posts``/``_data`` are the site's own Jekyll directories, and
#: a leading dot would hide the whole author tree. ``a/b`` is two segments, not a root.
_AUTHOR_ROOT = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*$")


def author_target_prefixes(uid: str, author_root: str = AUTHOR_ROOT_DEFAULT) -> tuple[str, ...]:
    """The work-copy prefixes one author owns; the skeleton mirror excludes exactly these.

    One function, because two lists have to agree: the exclusion the skeleton mirror applies (so it
    never copies over or deletes an author's target) and the validation of the targets the app
    pushes. An author owns exactly ``<author_root>/<uid>``.
    """
    return (f"{author_root}/{uid}",)


def author_target_allowed(uid: str, target: str, author_root: str = AUTHOR_ROOT_DEFAULT) -> bool:
    """Whether ``target`` (site-relative) is inside the area that belongs to ``uid``.

    Allowed: ``<author_root>/<uid>/…`` (the whole tree, at any depth and with any file type - an
    author's images are entries too). Nothing else: the site's own files and another author's tree
    are out of reach.
    """
    parts = tuple(target.split("/"))
    if len(parts) < 3 or any(part in ("", ".", "..") for part in parts):
        return False
    return parts[0] == author_root and parts[1] == uid


def _clean_relative(value: str) -> tuple[str, ...] | None:
    """Path components of a clean relative path, or ``None`` when the value is not one.

    Catches what a plain ``..`` test misses: an absolute path, a drive-relative path, a backslash, a
    NUL byte, an empty component (``a//b``) and a ``.`` component (``./a``). Each of those would
    otherwise be normalised by the path layer into something the payload did not say.
    """
    if not value or value.startswith("/") or "\\" in value or "\x00" in value:
        return None
    parts = tuple(value.split("/"))
    if len(parts) != len(Path(value).parts):
        return None                                  # a "." or an empty component collapsed away
    if any(part in ("", ".", "..") for part in parts):
        return None
    return parts


@dataclass(frozen=True)
class AuthorEntry:
    """One file an author contributes: ``path`` is relative to their folder, ``target`` to the site."""

    path: str
    target: str

    def to_dict(self) -> dict[str, str]:
        return {"path": self.path, "target": self.target}


@dataclass(frozen=True)
class AuthorSource:
    """One author's own Nextcloud folder as a second, third, … source of the one site.

    Two lists, one per directory (the split of the old single work copy):

    * ``entries`` is everything the author's area holds. It is mirrored into the **preview** tree,
      which is built and served as the preview, so an internal page stays previewable.
    * ``onsite`` is the subset that is approved for the site (``ReviewStore::onSite()``). It is
      mirrored into the **commit** tree, the git working tree a publish commits from. A client that
      predates the split omits it, and then it means "the same as ``entries``": that is the old
      single-tree behaviour, unchanged.

    Only the targets named in ``removed`` (which the app computes, because only the app knows which
    target belonged to which source file) are ever deleted from the preview tree; the commit tree
    additionally drops a target that is still in ``entries`` but no longer in ``onsite`` (a page that
    was turned internal), because the commit tree holds the on-site set and nothing else.
    """

    uid: str
    path: str
    entries: tuple[AuthorEntry, ...]
    removed: tuple[str, ...]
    #: ``<nc_data_root>/<uid>/files/<path>``: this author's own files, never the site owner's.
    source: Path
    #: The subset of ``entries`` that is on the site; meaningful only when ``onsite_pushed`` is true.
    onsite: tuple[AuthorEntry, ...] = ()
    #: Whether the app pushed an ``on_site`` list at all. An **empty** pushed list means "nothing of
    #: this author is on the site" - the commit tree must be emptied - while an absent one is a
    #: pre-split client and means "the same as ``entries``". The two must not collapse into one.
    onsite_pushed: bool = False

    @property
    def commit_entries(self) -> tuple[AuthorEntry, ...]:
        """What the commit tree mirrors: the pushed on-site subset, or everything from an old client."""
        return self.onsite if self.onsite_pushed else self.entries

    def to_dict(self) -> dict[str, Any]:
        return {
            "uid": self.uid,
            "path": self.path,
            "source": str(self.source),
            "entries": [entry.to_dict() for entry in self.entries],
            "on_site": [entry.to_dict() for entry in self.commit_entries],
            "on_site_pushed": self.onsite_pushed,
            "removed": list(self.removed),
        }


@dataclass(frozen=True)
class Site:
    #: The **site folder** in Nextcloud (``<nc_data_root>/<nc_user>/files/<nc_path>``). It is the
    #: parent of the three fixed subdirectories; the skeleton itself is :attr:`source_dir`.
    source: Path
    #: ``source/``: the administrator-edited Jekyll skeleton and the one required input of the
    #: assembly (a site whose ``source/`` is absent is refused at config time).
    source_dir: Path
    #: ``online/``: the **published** content, maintained by ``publish`` and ``take-down``. It is
    #: data (the bridge writes it into Nextcloud), and it is the overlay that wins over ``source/``.
    online_dir: Path
    #: ``pending/``: the **frozen candidate** snapshots, maintained by ``candidate`` and cleared by
    #: ``publish``/``discard``. It is data too, and the overlay that wins over ``online/`` in the
    #: review tree.
    pending_dir: Path
    #: The commit tree: the assembled skeleton plus the **published** content, merged flat at the
    #: tree root (``source/`` then ``online/``, same-name wins). It is derived and may be rebuilt; a
    #: publish commits from here, and the repository it pushes is this flat tree.
    work: Path
    #: The tree the preview is built from and served from: ``source/`` → ``online/`` → every author
    #: folder. It is derived and may be deleted and rebuilt at any time: it lives outside the commit
    #: tree, so a build or a served file can never leak into what a publish commits.
    preview: Path
    #: The review tree: ``source/`` → ``online/`` → ``pending/`` (byte for byte equal to the commit
    #: tree when nothing is pending). It is derived too, and a third tree on purpose: freezing a
    #: review object into the author's own preview would let a later save move what the reviewer sees.
    onlinepreview: Path
    #: ``""`` means "no remote configured": publish still commits, it just never pushes.
    repo_url: str
    branch: str
    remote_name: str
    recipe: Recipe
    exclude: frozenset[str]
    nc_user: str
    nc_path: str
    #: Site directory that receives every author's tree: ``<author_root>/<uid>/…``.
    author_root: str = AUTHOR_ROOT_DEFAULT
    #: Further sources of the same site, one per author; empty is today's single-source site.
    authors: tuple[AuthorSource, ...] = ()

    @property
    def has_remote(self) -> bool:
        """Whether a push is possible at all. One answer for every caller, so no client guesses."""
        return self.repo_url.strip() != ""

    @property
    def output_dir(self) -> Path:
        """The build output the preview serves: always inside the preview tree.

        The commit tree is never built, so there is no second output directory to keep in step; the
        name ``output_dir`` keeps meaning "the thing a reader or an iframe ends up seeing".
        """
        return norm_abs(self.preview / self.recipe.output_dir)

    @property
    def onlinepreview_output_dir(self) -> Path:
        """Where the review tree is built: its own ``output_dir``, always inside ``onlinepreview``.

        Same rule as :attr:`output_dir`, one tree further: the build command runs with
        ``cwd = onlinepreview`` and writes here, so the reviewer's copy is served from a directory
        that neither the author preview nor the commit tree can reach.
        """
        return norm_abs(self.onlinepreview / self.recipe.output_dir)

    @property
    def work_output_dir(self) -> Path:
        """Where an old single-tree build put its output; kept only for reports and migrations."""
        return norm_abs(self.work / self.recipe.output_dir)

    def to_dict(self) -> dict[str, Any]:
        return {
            # the site folder plus the three fixed subdirectories it contains: the bridge never
            # guesses them, an app can read them straight off /admin/config and /health
            "source": str(self.source),
            "source_dir": str(self.source_dir),
            "online_dir": str(self.online_dir),
            "pending_dir": str(self.pending_dir),
            "work": str(self.work),
            # the config field's name, so a reader of /admin/config can match it against the TOML;
            # "preview" is the same path under the name the rest of the API uses for the tree
            "preview_dir": str(self.preview),
            "preview": str(self.preview),
            # the third tree: the review copy of the published content plus the frozen candidates.
            # The field name, the key the API uses, and its build output, exactly like the
            # preview triple.
            "onlinepreview_dir": str(self.onlinepreview),
            "onlinepreview": str(self.onlinepreview),
            "onlinepreview_output": str(self.onlinepreview_output_dir),
            "output": str(self.output_dir),
            "repo_url": self.repo_url,
            "remote_configured": self.has_remote,
            "branch": self.branch,
            "remote_name": self.remote_name,
            "recipe": self.recipe.to_dict(),
            "exclude": sorted(self.exclude),
            "nc_user": self.nc_user,
            "nc_path": self.nc_path,
            "author_root": self.author_root,
            "authors": [author.to_dict() for author in self.authors],
        }


@dataclass(frozen=True)
class Config:
    listen_host: str
    listen_port: int
    shared_secret: str
    session_signing_secret: str
    nc_data_root: Path
    work_root: Path
    state_dir: Path
    override_dir: Path
    log_dir: Path
    exclude: frozenset[str]
    unexclude: frozenset[str]
    use_gitignore: bool
    build_timeout_s: int
    build_debounce_ms: int
    mirror_verify: str
    mirror_preserve_exec: bool
    mirror_delete_guard_ratio: float
    mirror_delete_guard_min: int
    log_tail_lines: int
    #: The one site of this bridge; single-site, so there is no id and no lookup.
    #: ``None`` while nothing is configured yet: an UNPAIRED bridge starts without a site, because
    #: pairing is what hands the site over.
    site: Site | None

    # ── hosting by the Nextcloud app (§15) ───────────────────────────────────────────────────────
    #: False while no usable secrets exist, neither in the TOML nor in ``effective.json``.
    #: The bridge then answers only ``GET /health`` and ``POST /pair``, on a loopback address.
    paired: bool = True
    #: Where the configuration in force came from at load time: ``"toml"`` or ``"app"``.
    config_source: str = "toml"
    #: The revision the app pushed together with the configuration, if it pushed one.
    config_revision: int | None = None
    #: The TOML table itself, unmerged: a push rebuilds the config from it plus the new values.
    toml_raw: Mapping[str, Any] = field(default_factory=dict)
    #: The validated ``global`` block the app pushed; only these keys are labelled "from app".
    app_globals: Mapping[str, Any] = field(default_factory=dict)
    #: Which :data:`GLOBAL_KEYS` the TOML sets itself, for the per-key source report.
    toml_keys: frozenset[str] = frozenset()
    #: The site table in force, as pushed or written; reused when only globals are pushed.
    site_raw: Mapping[str, Any] | None = None
    #: Where the **bootstrap state** is read from and written to: the TOML's ``state_dir``, never a
    #: pushed one - a pushed ``state_dir`` may not move the very files that carry the push, and the
    #: reset marker / pairing code / ``effective.json`` are all read before (or without) a pushed
    #: configuration. The **business state** (``state.json`` and the build log) follows
    #: :attr:`state_dir` and therefore moves with a push, which is the intended effect.
    bootstrap_state_dir: Path = Path("/var/lib/pages/state")
    #: The listen address from the config file itself, used when a pushed one cannot be bound.
    fallback_listen_host: str = "127.0.0.1"
    fallback_listen_port: int = 8787


def _as_str_tuple(value: Any, what: str) -> tuple[str, ...] | None:
    """One config value that may be a string or an array of strings; ``None`` when it is absent."""
    if value is None:
        return None
    if isinstance(value, str):
        return (value,)
    if isinstance(value, (list, tuple)) and all(isinstance(v, str) for v in value):
        return tuple(value)
    raise ConfigError(f"{what} must be a string or an array of strings", got=repr(value))


#: Legacy preset values already reported as resolved; the log line must not repeat on every load.
_LEGACY_REPORTED: set[str] = set()


def resolve_recipe(raw: Mapping[str, Any]) -> Recipe:
    """Resolve the ``recipe`` table; jekyll is the only generator and nothing is probed.

    ``preset`` may be absent or empty (both mean jekyll), or name jekyll. The ``auto`` of an install
    whose pushed config predates the Jekyll-only bridge resolves to jekyll as well: refusing it would
    lock that install out, and jekyll is what the old probe picked for the sites built here.
    """
    if not isinstance(raw, Mapping):
        raise ConfigError("recipe must be a table")

    preset_raw = raw.get("preset")
    preset = "" if preset_raw is None else str(preset_raw).lower()
    if preset == "auto":
        # logged once: the legacy value stays in the pushed config until the app is updated
        if "auto" not in _LEGACY_REPORTED:
            _LEGACY_REPORTED.add("auto")
            info("preset auto resolves to jekyll, the only generator", preset=PRESET)
        preset = ""
    if not preset:
        preset = PRESET
    elif preset != PRESET:
        raise ConfigError(
            "unknown preset, jekyll is the only supported generator",
            preset=preset,
            supported=[PRESET],
        )

    base = PRESET_RECIPE
    command = _as_str_tuple(raw.get("command"), "recipe.command")
    if command is None:
        command = _as_str_tuple(base.get("command"), "preset.command")
    install_cmd = _as_str_tuple(raw.get("install_cmd", base.get("install")), "recipe.install_cmd")

    output_dir = raw.get("output_dir", base.get("output_dir", "."))
    if not isinstance(output_dir, str) or not output_dir:
        raise ConfigError("recipe.output_dir must be a non-empty string")
    if Path(output_dir).is_absolute() or ".." in Path(output_dir).parts:
        raise ConfigError("recipe.output_dir must be a relative path inside the site", got=output_dir)

    env = raw.get("env", {}) or {}
    if not isinstance(env, Mapping) or not all(
        isinstance(k, str) and isinstance(v, (str, int, float, bool)) for k, v in env.items()
    ):
        raise ConfigError("recipe.env must map strings to scalars")

    overrides = raw.get("overrides", {}) or {}
    if not isinstance(overrides, Mapping) or not all(
        isinstance(k, str) and isinstance(v, (str, int, float, bool)) for k, v in overrides.items()
    ):
        raise ConfigError("recipe.overrides must be a flat scalar mapping")

    env = {k: str(v) for k, v in env.items()}
    for key, value in (base.get("env") or {}).items():
        env.setdefault(key, str(value))

    return Recipe(
        preset=preset,
        command=command,
        output_dir=output_dir,
        install_cmd=install_cmd,
        env=env,
        overrides=dict(overrides),
        base_config=base.get("base_config"),
    )


def read_secret(raw: Mapping[str, Any], key: str) -> str:
    """Read one secret out of a raw table.

    Absent, empty or a placeholder means "no secret", which is the UNPAIRED state and not an error:
    that is the whole point of pairing. A value that was really meant as a secret but is too short is
    still refused, because that is a typo rather than a decision.
    """
    value = str(raw.get(key, "")).strip()
    if value in _PLACEHOLDERS:
        return ""
    if len(value) < 16:
        raise ConfigError(
            f"{key} is missing or too short (at least 16 characters, no placeholders)",
            key=key,
            hint="generate one with python3 -c \"import secrets;print(secrets.token_hex(32))\"",
        )
    return value


def validate_secret(key: str, value: Any) -> str:
    """Validate one secret that the app sent; unlike the TOML, an empty one is a request error."""
    text = str(value if value is not None else "").strip()
    if text in _PLACEHOLDERS or len(text) < 16:
        raise BadRequest(
            f"{key} is missing or too short (at least 16 characters, no placeholders)",
            key=key,
        )
    return text


def parse_listen(value: Any) -> tuple[str, int]:
    """Parse one ``host:port`` value, whether it came from the TOML or from the app."""
    listen = str(value if value is not None else DEFAULT_LISTEN)
    host, _, port_text = listen.rpartition(":")
    if not host:
        host = "127.0.0.1"
    try:
        port = int(port_text)
    except ValueError:
        raise ConfigError("listen must be in host:port form", got=listen) from None
    if not 1 <= port <= 65535:
        raise ConfigError("listen port out of range", got=port)
    return host, port


def validate_global(name: str, value: Any) -> Any:
    """Validate one pushed global value and normalise it to exactly what the TOML would give."""
    if name == "listen":
        try:
            host, port = parse_listen(value)
        except ConfigError as exc:
            # the same message as the TOML path, but a request body is a client error
            raise BadRequest(exc.message, **exc.details) from exc
        return f"{host}:{port}"
    if name in ("nc_data_root", "work_root", "state_dir", "override_dir", "log_dir"):
        if not isinstance(value, str) or not value.strip():
            raise BadRequest(f"global config key {name} must be a non-empty path", got=repr(value))
        return value.strip()
    if name in ("build_timeout_s", "build_debounce_ms", "log_tail_lines", "mirror_delete_guard_min"):
        if isinstance(value, bool) or not isinstance(value, int):
            raise BadRequest(f"global config key {name} must be a whole number", got=repr(value))
        if value <= 0:
            raise BadRequest(f"global config key {name} must be positive", got=value)
        return value
    if name == "mirror_verify":
        verify = str(value if value is not None else "").strip().lower()
        if verify not in ("size+mtime", "sha256"):
            raise BadRequest("mirror_verify must be size+mtime or sha256", got=verify)
        return verify
    if name == "mirror_delete_guard_ratio":
        if isinstance(value, bool) or not isinstance(value, (int, float)):
            raise BadRequest(
                "global config key mirror_delete_guard_ratio must be a number", got=repr(value)
            )
        ratio = float(value)
        if not 0.0 <= ratio <= 1.0:
            raise BadRequest("mirror_delete_guard_ratio must be between 0 and 1", got=ratio)
        return ratio
    if name in ("mirror_preserve_exec", "mirror_use_gitignore"):
        if not isinstance(value, bool):
            raise BadRequest(f"global config key {name} must be true or false", got=repr(value))
        return value
    if name in ("exclude", "unexclude"):
        if isinstance(value, str) or not isinstance(value, (list, tuple)):
            raise BadRequest(f"global config key {name} must be an array of strings", got=repr(value))
        if not all(isinstance(item, str) for item in value):
            raise BadRequest(f"global config key {name} must be an array of strings", got=repr(value))
        names = [item for item in value]
        if name == "unexclude" and "git" in {item.lower() for item in names}:
            raise BadRequest(".git cannot be unexcluded: the mirror must never touch the repository")
        return names
    raise BadRequest(f"unknown global config key: {name}", known=sorted(GLOBAL_KEYS))


def validate_globals(pushed: Any) -> dict[str, Any]:
    """Validate one app-pushed ``global`` block; an unknown key is a 400 naming it."""
    if not isinstance(pushed, Mapping):
        raise BadRequest("global must be an object")
    out: dict[str, Any] = {}
    for key, value in pushed.items():
        name = str(key)
        if name not in GLOBAL_KEYS:
            raise BadRequest(f"unknown global config key: {name}", known=sorted(GLOBAL_KEYS))
        out[name] = validate_global(name, value)
    return out


def parse_effective(data: Any) -> dict[str, Any]:
    """Read one ``effective.json`` (the app-pushed config).

    Every failure raises a :class:`BridgeError`; the caller logs it and falls back to the TOML, so a
    truncated file or a value from a newer app can never keep the daemon from starting.
    """
    if not isinstance(data, Mapping):
        raise BridgeError("the pushed config must be a JSON object")
    revision = data.get("revision")
    revision = revision if isinstance(revision, int) and not isinstance(revision, bool) else None
    site = data.get("site")
    if site is not None and not isinstance(site, Mapping):
        raise BadRequest("the pushed site must be an object")
    globals_valid = validate_globals(data.get("global") or {})
    site_valid = dict(site) if isinstance(site, Mapping) and site else None
    secrets: dict[str, str] = {}
    for key in SECRET_KEYS:
        if data.get(key) is not None:
            secrets[key] = validate_secret(key, data.get(key))
    if not globals_valid and site_valid is None and not secrets:
        # an empty file carries no configuration at all: treat it like a broken one instead of
        # reporting "the app owns the config" and silently skipping the legacy pushed file
        raise BridgeError("the pushed config is empty")
    return {
        "revision": revision,
        "global": globals_valid,
        # an empty table reads as "no site pushed yet", not as "a site with no nc_path"
        "site": site_valid,
        "secrets": secrets,
    }


def check_nc_data_root(root: Path, user: str | None = None) -> None:
    """Structural check only: a real data directory holds ``<user>/files`` for some user.

    With ``user`` the check is the strict one the pairing request and every push get: the site's own
    directory has to exist, or the bridge would mirror an empty path into the work copy.
    """
    if not root.is_dir():
        raise ConfigError(
            "nc_data_root does not exist or is not a directory",
            path=str(root),
            hint="common locations: /var/snap/nextcloud/common/nextcloud/data (snap); "
                 "deb/package installs usually use /var/www/nextcloud/data or "
                 "/var/lib/nextcloud/data. Ask Nextcloud with: occ config:system:get datadirectory",
        )
    if user:
        if not (root / user / "files").is_dir():
            raise ConfigError(
                f"nc_data_root has no {user}/files directory",
                path=str(root),
                user=user,
                hint="the source path is <nc_data_root>/<user>/files/<nc_path>; in Nextcloud that is "
                     "the user name layer under the data directory (occ user:list)",
            )
        return
    if not any(
        child.is_dir() and (child / "files").is_dir()
        for child in root.iterdir()
        if child.name not in ("files_external", "nextcloud.log", "updater.log", "appdata_")
    ):
        raise ConfigError(
            "no <user>/files directory under nc_data_root; this does not look like a Nextcloud data directory",
            path=str(root),
            hint="get the exact path with: occ config:system:get datadirectory",
        )


def global_sources(cfg: "Config") -> dict[str, str]:
    """Per key: where the value in force comes from - the app, the TOML, or a built-in default."""
    out: dict[str, str] = {}
    for key in GLOBAL_KEYS:
        if key in cfg.app_globals:
            out[key] = "app"
        elif key in cfg.toml_keys:
            out[key] = "toml"
        else:
            out[key] = "default"
    return out


def effective_globals(cfg: "Config") -> dict[str, Any]:
    """The value of every :data:`GLOBAL_KEYS` entry as it is in force right now.

    A key the app pushed is reported exactly as the app pushed it (normalised), because that is what
    is in force and what the settings form compares its own stored value against. Everything else is
    read back out of the running configuration.
    """
    values: dict[str, Any] = {
        "listen": f"{cfg.listen_host}:{cfg.listen_port}",
        "nc_data_root": str(cfg.nc_data_root),
        "work_root": str(cfg.work_root),
        "state_dir": str(cfg.state_dir),
        "override_dir": str(cfg.override_dir),
        "log_dir": str(cfg.log_dir),
        "build_timeout_s": cfg.build_timeout_s,
        "build_debounce_ms": cfg.build_debounce_ms,
        "log_tail_lines": cfg.log_tail_lines,
        "mirror_verify": cfg.mirror_verify,
        "mirror_delete_guard_ratio": cfg.mirror_delete_guard_ratio,
        "mirror_delete_guard_min": cfg.mirror_delete_guard_min,
        "mirror_preserve_exec": cfg.mirror_preserve_exec,
        "mirror_use_gitignore": cfg.use_gitignore,
        "exclude": sorted(cfg.exclude),
        "unexclude": sorted(cfg.unexclude),
    }
    for key, value in cfg.app_globals.items():
        if key in values:
            values[key] = value
    return values


#: One-shot report of a pushed ``state_dir``: the settings page pushes the same configuration over
#: and over, so the operator gets the explanation once per process and directory pair.
_STATE_DIR_SPLIT_REPORTED: set[tuple[str, str]] = set()


def note_bootstrap_split(cfg: "Config") -> bool:
    """Log once that a push moved ``state_dir`` away from the TOML's; return whether it did.

    A pushed ``state_dir`` moves the *business* state (``state.json`` and the build log) - that is
    what the push is for. The *bootstrap* state stays in the TOML's directory, because the bridge
    reads it before it knows about any push (or, after a reset, without the pushed file at all).
    Explaining the split once keeps an operator from looking for ``effective.json`` under the pushed
    path.
    """
    if cfg.bootstrap_state_dir == cfg.state_dir:
        return False
    key = (str(cfg.bootstrap_state_dir), str(cfg.state_dir))
    if key in _STATE_DIR_SPLIT_REPORTED:
        return True
    _STATE_DIR_SPLIT_REPORTED.add(key)
    info(
        "the app pushed another state_dir; the bootstrap files stay where the TOML points",
        bootstrap_state_dir=str(cfg.bootstrap_state_dir),
        state_dir=str(cfg.state_dir),
        bootstrap_files=[EFFECTIVE_FILE, UNPAIRED_FLAG, "pairing.json"],
    )
    return True


def _site_table(raw: Mapping[str, Any]) -> Mapping[str, Any] | None:
    """The single ``[site]`` table.

    Single site: the file carries one ``[site]`` table. The multi-site ``[[sites]]`` array of
    protocol 2 is still read (first entry only) with a warning, so that an app upgrade without
    touching the TOML keeps the daemon starting.
    """
    site_raw: Any = raw.get("site")
    if isinstance(site_raw, Mapping):
        return site_raw
    legacy = raw.get("sites") or []
    first = legacy[0] if isinstance(legacy, (list, tuple)) and legacy else None
    if not isinstance(first, Mapping):
        return None
    warn("the config is the old format, re-run bridge/install.sh", table="[[sites]]", used="sites[0]")
    return first


def assemble_config(
    raw: Mapping[str, Any],
    *,
    toml_raw: Mapping[str, Any] | None = None,
    app_globals: Mapping[str, Any] | None = None,
    config_source: str = "toml",
    config_revision: int | None = None,
    allow_missing_site: bool = False,
    previous_author_root: str | None = None,
) -> Config:
    """Validate one raw table: the TOML, already overlaid with whatever the app pushed.

    Both halves of the hosting story go through this one path - the config file, and
    ``effective.json`` (or a live push) laid over it - so a pushed value can never pass a weaker
    validation than the same value written in the TOML by hand. ``raw`` carries the effective
    values, ``toml_raw`` the file as written (it is what the per-key source report is computed from).
    """
    file_raw: Mapping[str, Any] = toml_raw if toml_raw is not None else raw
    pushed = dict(app_globals or {})

    host, port = parse_listen(raw.get("listen", DEFAULT_LISTEN))
    fallback_host, fallback_port = parse_listen(file_raw.get("listen", DEFAULT_LISTEN))

    shared_secret = read_secret(raw, "shared_secret")
    session_signing_secret = read_secret(raw, "session_signing_secret")
    paired = bool(shared_secret) and bool(session_signing_secret)

    if not paired and host not in LOOPBACK_HOSTS:
        # an unpaired bridge has no credential to check: off-loopback it would be an open API
        raise ConfigError(
            "an unpaired bridge may only listen on loopback",
            listen=f"{host}:{port}",
            hint="pair the bridge from the Nextcloud Pages settings over 127.0.0.1 first, or put "
                 "usable secrets in the config file to run the hand-written path",
        )
    if paired and host not in LOOPBACK_HOSTS:
        # not forbidden, but it widens the exposure a lot, so make it explicit
        info("bridge is listening on a non-loopback address", host=host, hint="put HTTPS and authentication in front")

    nc_data_root = norm_abs(raw.get("nc_data_root", DEFAULT_NC_DATA_ROOT))
    # The three host trees are derived from the Nextcloud site folder; the commit tree is the git
    # working tree a publish commits from, so it defaults into the same persistent root as
    # state/overrides/logs, and its siblings (``.preview``, ``.onlinepreview``) inherit that root.
    work_root = norm_abs(raw.get("work_root", DEFAULT_WORK_ROOT))
    state_dir = norm_abs(raw.get("state_dir", DEFAULT_STATE_DIR))
    override_dir = norm_abs(raw.get("override_dir", DEFAULT_OVERRIDE_DIR))
    log_dir = norm_abs(raw.get("log_dir", DEFAULT_LOG_DIR))

    exclude_raw = raw.get("exclude", []) or []
    if not isinstance(exclude_raw, (list, tuple)) or not all(isinstance(v, str) for v in exclude_raw):
        raise ConfigError("exclude must be an array of strings")
    unexclude_raw = raw.get("unexclude", []) or []
    if not isinstance(unexclude_raw, (list, tuple)) or not all(
        isinstance(v, str) for v in unexclude_raw
    ):
        raise ConfigError("unexclude must be an array of strings")
    if "git" in {str(v).lower() for v in unexclude_raw}:
        raise ConfigError(".git cannot be unexcluded: the mirror must never touch the repository")
    global_exclude = (set(MINIMAL_EXCLUDE) | set(exclude_raw)) - set(unexclude_raw)

    site_raw = _site_table(raw)
    if site_raw is None:
        if paired and not allow_missing_site:
            raise ConfigError(
                "the [site] table is required",
                hint="the app pushes the site config on its own; add a [site] block to the TOML by hand, then restart",
            )
        # unpaired, or paired by the app which pushes the site as well: nothing to build yet
        info("no site configured yet, the Nextcloud app pushes one when it pairs")
        site = None
    else:
        # a site is what makes the data directory load-bearing, so only then is it inspected
        check_nc_data_root(nc_data_root)
        site = build_site(
            site_raw,
            nc_data_root=nc_data_root,
            work_root=work_root,
            global_exclude=global_exclude,
            # the app's request answers 400 naming the offending field; the TOML answers
            # CONFIG_ERROR, exactly as the pushed `global` block already does
            error=ConfigError if config_source == "toml" else BadRequest,
            previous_author_root=previous_author_root,
        )

    verify = str(raw.get("mirror_verify", "size+mtime")).lower()
    if verify not in ("size+mtime", "sha256"):
        raise ConfigError("mirror_verify must be size+mtime or sha256", got=verify)

    return Config(
        listen_host=host,
        listen_port=port,
        shared_secret=shared_secret,
        session_signing_secret=session_signing_secret,
        nc_data_root=nc_data_root,
        work_root=work_root,
        state_dir=state_dir,
        override_dir=override_dir,
        log_dir=log_dir,
        exclude=frozenset(global_exclude),
        unexclude=frozenset(unexclude_raw),
        use_gitignore=bool(raw.get("mirror_use_gitignore", True)),
        build_timeout_s=int(raw.get("build_timeout_s", 180)),
        build_debounce_ms=int(raw.get("build_debounce_ms", 700)),
        mirror_verify=verify,
        mirror_preserve_exec=bool(raw.get("mirror_preserve_exec", False)),
        mirror_delete_guard_ratio=float(raw.get("mirror_delete_guard_ratio", 0.2)),
        mirror_delete_guard_min=int(raw.get("mirror_delete_guard_min", 20)),
        log_tail_lines=int(raw.get("log_tail_lines", 200)),
        site=site,
        paired=paired,
        config_source=config_source,
        config_revision=config_revision,
        toml_raw=dict(file_raw),
        app_globals=pushed,
        toml_keys=frozenset(k for k in GLOBAL_KEYS if k in file_raw),
        site_raw=dict(site_raw) if site_raw is not None else None,
        bootstrap_state_dir=norm_abs(file_raw.get("state_dir", DEFAULT_STATE_DIR)),
        fallback_listen_host=fallback_host,
        fallback_listen_port=fallback_port,
    )


def _author_target_error(
    error: type[BridgeError], where: str, uid: str, target: str,
    author_root: str = AUTHOR_ROOT_DEFAULT,
    previous_author_root: str | None = None,
) -> BridgeError:
    """One message for "not an allowed target", listing every directory an entry may live under."""
    allowed = [f"{author_root}/{uid}/..."]
    if previous_author_root is not None and previous_author_root != author_root:
        allowed.append(f"{previous_author_root}/{uid}/...")

    return error(
        f"{where} is not an allowed target",
        target=target,
        uid=uid,
        allowed=allowed,
        hint="an author source may only write its own directory in the site",
    )


def _parse_entries(
    raw: Any,
    *,
    where: str,
    uid: str,
    author_root: str,
    error: type[BridgeError],
    seen: dict[str, str],
) -> list[AuthorEntry]:
    """Validate one entry array (the preview list or the on-site subset); both share every rule."""
    if isinstance(raw, Mapping) or not isinstance(raw, (list, tuple)):
        raise error(f"{where} must be an array", got=repr(raw)[:200])
    entries: list[AuthorEntry] = []
    for entry_index, entry in enumerate(raw):
        entry_where = f"{where}[{entry_index}]"
        if not isinstance(entry, Mapping):
            raise error(f"{entry_where} must be an object", got=repr(entry)[:200])

        entry_path_raw = entry.get("path")
        if not isinstance(entry_path_raw, str) or not entry_path_raw.strip():
            raise error(
                f"{entry_where}.path is required",
                hint="a path relative to the author's own folder",
            )
        entry_path = entry_path_raw.strip()
        if _clean_relative(entry_path) is None:
            raise error(
                f"{entry_where}.path must be a clean relative path inside the author's folder "
                "(no leading /, no . or .. component)",
                got=entry_path,
            )

        target_raw = entry.get("target")
        if not isinstance(target_raw, str) or not target_raw.strip():
            raise error(
                f"{entry_where}.target is required",
                hint=f"a site path, e.g. {author_root}/{uid}/2026-05-01-hello.md",
            )
        target = target_raw.strip()
        if _clean_relative(target) is None:
            raise error(f"{entry_where}.target must be a relative site path", got=target)
        if not author_target_allowed(uid, target, author_root):
            raise _author_target_error(error, f"{entry_where}.target", uid, target, author_root)
        if target in seen:
            raise error(
                "duplicate target in site.authors",
                target=target,
                first=seen[target],
            )
        seen[target] = entry_where
        entries.append(AuthorEntry(path=entry_path, target=target))
    return entries


def parse_authors(
    raw: Any,
    *,
    nc_data_root: Path,
    site_source: Path,
    work: Path,
    error: type[BridgeError] = ConfigError,
    author_root: str = AUTHOR_ROOT_DEFAULT,
    previous_author_root: str | None = None,
    preview: Path | None = None,
) -> tuple[AuthorSource, ...]:
    """Validate the ``site.authors`` array, from the pushed payload or from the TOML.

    ``error`` decides how a bad value is reported: the app's request is a ``400`` naming the
    offending field, the same value written in the TOML by hand is a :class:`ConfigError` the
    administrator has to fix. Every rule is about one field, and nothing is rewritten silently:
    a rejected payload is rejected as a whole.

    ``author_root`` is the site directory the entries have to live under (see
    :func:`author_target_allowed`); it comes from ``site.author_root``, never from here, so the
    check and the skeleton-mirror exclusion cannot disagree.

    ``previous_author_root`` is the directory in force before this push. It is accepted **for
    removals only**: when the administrator moves the author directory, the files left in the old
    one have to be named as removed, or the mirror keeps serving them next to the new copies. New
    entries under the old root are still refused - the point of the move is that content lives in
    one place.

    ``preview`` is the preview tree; an author folder that contains or sits inside it would be
    mirrored into itself, exactly like the work copy check below.

    Absent or empty means today's single-source site, so the single-source path never touches this.
    """
    if raw is None:
        return ()
    if isinstance(raw, Mapping) or not isinstance(raw, (list, tuple)):
        raise error("site.authors must be an array", got=repr(raw)[:200])

    authors: list[AuthorSource] = []
    seen_uids: dict[str, int] = {}

    for index, item in enumerate(raw):
        where = f"site.authors[{index}]"
        if not isinstance(item, Mapping):
            raise error(f"{where} must be an object", got=repr(item)[:200])

        # ── uid: the Nextcloud user whose own files contribute ──────────────────────────────────
        uid_raw = item.get("uid")
        if not isinstance(uid_raw, str) or not uid_raw.strip():
            raise error(
                f"{where}.uid is required",
                hint="the Nextcloud user whose folder contributes files to this site",
            )
        uid = uid_raw.strip()
        if "/" in uid or "\\" in uid or "\x00" in uid or uid in (".", ".."):
            raise error(f"{where}.uid is invalid", uid=uid)
        if uid in seen_uids:
            raise error(
                "duplicate uid in site.authors",
                uid=uid,
                first=f"site.authors[{seen_uids[uid]}]",
            )
        seen_uids[uid] = index

        # ── path: relative to that user's own files, so an absolute Nextcloud path ───────────────
        path_raw = item.get("path")
        if not isinstance(path_raw, str) or not path_raw.strip():
            raise error(
                f"{where}.path is required",
                hint="the folder inside that user's files, e.g. /Pages/posts",
            )
        path = "/" + path_raw.strip().strip("/")
        if not path_raw.strip().startswith("/"):
            raise error(
                f"{where}.path must be an absolute Nextcloud path (start with /)",
                got=path_raw,
                hint="it is read under <nc_data_root>/<uid>/files, like the site's own nc_path",
            )
        if "\x00" in path or ".." in Path(path).parts:
            raise error(f"{where}.path must not contain .. or a NUL byte", got=path_raw)

        source = norm_abs(nc_data_root / uid / "files" / path.strip("/"))
        if is_within(source, site_source) or is_within(site_source, source):
            raise error(
                "an author's source must not overlap the site source",
                uid=uid,
                source=str(source),
                site_source=str(site_source),
                hint="two sources that contain each other would mirror the same files twice",
            )
        if is_within(source, work) or is_within(work, source):
            raise error(
                "an author's source must not overlap the work copy (the mirror would recurse)",
                uid=uid,
                source=str(source),
                work=str(work),
            )
        if preview is not None and (is_within(source, preview) or is_within(preview, source)):
            raise error(
                "an author's source must not overlap the preview directory (the mirror would recurse)",
                uid=uid,
                source=str(source),
                preview=str(preview),
            )

        # ── entries: every file of the author's area (the preview list) ──────────────────────────
        seen_targets: dict[str, str] = {}
        entries = _parse_entries(
            item.get("entries") or [],
            where=f"{where}.entries",
            uid=uid,
            author_root=author_root,
            error=error,
            seen=seen_targets,
        )

        # ── on_site: the subset approved for the site (the commit list) ─────────────────────────
        # Absent is the pre-split client: the commit tree then mirrors ``entries``, which is the
        # single-tree behaviour this field was introduced to split.
        onsite_raw = item.get("on_site")
        onsite: list[AuthorEntry] = []
        if onsite_raw is not None:
            onsite = _parse_entries(
                onsite_raw,
                where=f"{where}.on_site",
                uid=uid,
                author_root=author_root,
                error=error,
                seen={},
            )

        # ── removed: targets the app has decided are gone, never source paths ────────────────────
        removed_raw = item.get("removed") or []
        if isinstance(removed_raw, Mapping) or not isinstance(removed_raw, (list, tuple)):
            raise error(f"{where}.removed must be an array of strings", got=repr(removed_raw)[:200])
        removed: list[str] = []
        for removed_index, value in enumerate(removed_raw):
            if not isinstance(value, str) or not value.strip():
                raise error(f"{where}.removed must be an array of strings", got=repr(value)[:200])
            target = value.strip()
            removed_where = f"{where}.removed[{removed_index}]"
            if _clean_relative(target) is None:
                raise error(f"{removed_where} must be a relative target path", got=target)
            # a removal may also name the author directory that was in force before this push:
            # moving the directory has to be able to clean up the old one
            allowed_removal = author_target_allowed(uid, target, author_root) or (
                previous_author_root is not None
                and previous_author_root != author_root
                and author_target_allowed(uid, target, previous_author_root)
            )
            if not allowed_removal:
                raise _author_target_error(
                    error, removed_where, uid, target, author_root, previous_author_root,
                )
            if target in removed:
                raise error(f"duplicate target in {where}.removed", target=target)
            removed.append(target)

        authors.append(
            AuthorSource(
                uid=uid,
                path=path,
                entries=tuple(entries),
                removed=tuple(removed),
                source=source,
                onsite=tuple(onsite),
                onsite_pushed=onsite_raw is not None,
            )
        )

    return tuple(authors)


def build_site(
    raw: Mapping[str, Any],
    *,
    nc_data_root: Path,
    work_root: Path,
    global_exclude: frozenset[str],
    error: type[BridgeError] = ConfigError,
    previous_author_root: str | None = None,
) -> Site:
    """Build the one :class:`Site` from the ``[site]`` table (or from the pushed JSON).

    ``previous_author_root`` is the author directory in force before this push; only removals may
    name it (see :func:`parse_authors`), which is what makes moving the directory a supported change
    instead of an orphaned tree.
    """
    # nc_user is required: a guessed default silently points the site at another user's files
    nc_user = str(raw.get("nc_user", "")).strip()
    nc_path = str(raw.get("nc_path", "")).strip()
    if not nc_path:
        raise ConfigError("nc_path is required")
    if not nc_user:
        raise ConfigError(
            "nc_user is required (which Nextcloud user owns the site files)",
            hint="the source path is <nc_data_root>/<nc_user>/files/<nc_path>; "
                 "in Nextcloud that is the user name layer under the data directory.",
        )
    if "/" in nc_user or nc_user in (".", ".."):
        raise ConfigError("invalid nc_user", nc_user=nc_user)

    # the author directory: one path segment, and never a path the path layer would normalise into
    # something else. Absent is the default, so a client that predates the layout change keeps
    # working; a present but unusable value is refused whole, like every other field here.
    author_root_raw = raw.get("author_root")
    if author_root_raw is None:
        author_root = AUTHOR_ROOT_DEFAULT
    else:
        if not isinstance(author_root_raw, str) or _AUTHOR_ROOT.fullmatch(author_root_raw.strip()) is None:
            raise error(
                "site.author_root must be a single path segment",
                got=repr(author_root_raw)[:200],
                hint="one directory name starting with a letter or a digit, e.g. 'tmp'; no slash, "
                     "no '..', no leading dot or underscore",
            )
        author_root = author_root_raw.strip()

    source = norm_abs(nc_data_root / nc_user / "files" / nc_path.strip("/"))

    # The three fixed subdirectories of a site folder. They are derived, never configured: the
    # administrator edits ``source/`` in Nextcloud, the bridge maintains ``online/`` (published
    # content) and ``pending/`` (frozen candidates) through the ordinary file operations it is
    # allowed to do as root. ``source/`` is the one required input - without it the assembly would
    # silently rebuild three empty trees - while the two data directories are created on demand.
    source_dir = norm_abs(source / "source")
    online_dir = norm_abs(source / "online")
    pending_dir = norm_abs(source / "pending")
    if not source_dir.is_dir():
        raise error(
            "the site source directory does not exist",
            source_dir=str(source_dir),
            source=str(source),
            hint=f"create {source_dir.name}/ inside the site folder; it holds the Jekyll skeleton the "
                 "bridge assembles the three trees from",
        )
    for directory in (online_dir, pending_dir):
        try:
            directory.mkdir(parents=True, exist_ok=True)
        except OSError as exc:
            raise error(
                "the site directory could not be created",
                path=str(directory),
                detail=str(exc),
                hint="the bridge runs as the Nextcloud file owner (root by default) and writes "
                     "online/ and pending/ itself",
            ) from exc
    for directory in (source_dir, online_dir, pending_dir):
        if not is_within(directory, nc_data_root):
            raise error(
                "the site directories must live inside the Nextcloud data directory",
                path=str(directory),
                nc_data_root=str(nc_data_root),
            )
    for left, right in ((source_dir, online_dir), (source_dir, pending_dir), (online_dir, pending_dir)):
        if is_within(left, right) or is_within(right, left):
            raise error(
                "the three site directories must not contain each other",
                left=str(left),
                right=str(right),
            )

    # single site: without an explicit work_path the work root itself is the work copy
    work_raw = str(raw.get("work_path", "")).strip()
    work = norm_abs(work_raw) if work_raw else norm_abs(work_root)
    if not work.is_absolute():
        raise ConfigError("work_path must be an absolute path", work=str(work))

    # The preview tree: a **derived** directory holding every author file (internal and external
    # alike), which is built and served as the preview and may be deleted/rebuilt from the sources
    # alone. Without a pushed value it is a sibling of the work copy - ``<work>.preview``, i.e.
    # ``<work_root>.preview`` for the single-site default - so it can never land inside the work
    # copy by accident and always sits in the same root as the data it is derived from.
    preview_raw = raw.get("preview_dir")
    if preview_raw is None:
        preview = norm_abs(work.parent / f"{work.name}.preview")
    else:
        if not isinstance(preview_raw, str) or not preview_raw.strip():
            raise error(
                "site.preview_dir must be a non-empty absolute path",
                got=repr(preview_raw)[:200],
            )
        text = preview_raw.strip()
        if not Path(text).expanduser().is_absolute():
            raise error(
                "site.preview_dir must be an absolute path",
                got=text,
                hint=f"the default is {work.parent / (work.name + '.preview')}",
            )
        preview = norm_abs(text)

    # The review tree: the **published** content plus the pending candidate snapshots, built and
    # served as the reviewer's preview. It is data like the work copy (a pending candidate is the
    # only copy of what the reviewer is looking at), so it is a sibling of the work copy by default
    # - ``<work>.onlinepreview`` - in the same root as the data it mirrors, and an explicit value
    # must be a usable absolute path.
    onlinepreview_raw = raw.get("onlinepreview_dir")
    if onlinepreview_raw is None:
        onlinepreview = norm_abs(work.parent / f"{work.name}.onlinepreview")
    else:
        if not isinstance(onlinepreview_raw, str) or not onlinepreview_raw.strip():
            raise error(
                "site.onlinepreview_dir must be a non-empty absolute path",
                got=repr(onlinepreview_raw)[:200],
            )
        text = onlinepreview_raw.strip()
        if not Path(text).expanduser().is_absolute():
            raise error(
                "site.onlinepreview_dir must be an absolute path",
                got=text,
                hint=f"the default is {work.parent / (work.name + '.onlinepreview')}",
            )
        onlinepreview = norm_abs(text)

    # The two trees are separate on purpose: the preview holds everything, the commit tree only what
    # is on the site. Anything that makes one contain the other turns the split back into one tree.
    if is_within(preview, work) or is_within(work, preview):
        raise error(
            "site.preview_dir must not be inside the work copy (the two directories must be separate)",
            preview=str(preview),
            work=str(work),
        )
    if is_within(preview, source) or is_within(source, preview):
        raise error(
            "source and preview directory must not contain each other (the mirror would recurse or delete itself)",
            source=str(source),
            preview=str(preview),
        )
    if is_within(preview, nc_data_root):
        raise error(
            "preview directory must not live inside the Nextcloud data directory",
            preview=str(preview),
            nc_data_root=str(nc_data_root),
        )

    # The review tree is the third copy and has to stay separate from every other one: it is
    # mirrored from the source, written by the candidate deltas and built on its own, so containing
    # (or being contained by) the work copy, the preview or the source would make the three trees
    # one directory again - or make a skeleton round delete the candidates.
    if is_within(onlinepreview, work) or is_within(work, onlinepreview):
        raise error(
            "site.onlinepreview_dir must not be inside the work copy (the two directories must be separate)",
            onlinepreview=str(onlinepreview),
            work=str(work),
        )
    if is_within(onlinepreview, preview) or is_within(preview, onlinepreview):
        raise error(
            "site.onlinepreview_dir must not be inside the preview directory (the two directories must be separate)",
            onlinepreview=str(onlinepreview),
            preview=str(preview),
        )
    if is_within(onlinepreview, source) or is_within(source, onlinepreview):
        raise error(
            "source and onlinepreview directory must not contain each other (the mirror would recurse or delete itself)",
            source=str(source),
            onlinepreview=str(onlinepreview),
        )
    if is_within(onlinepreview, nc_data_root):
        # the same rule the preview has: a skeleton round must never write into Nextcloud's data
        # directory (and no author's folder may receive the site's own files)
        raise error(
            "onlinepreview directory must not live inside the Nextcloud data directory",
            onlinepreview=str(onlinepreview),
            nc_data_root=str(nc_data_root),
        )

    # the source directory and the work copy must never contain each other
    if is_within(work, source) or is_within(source, work):
        raise ConfigError(
            "source and work copy must not contain each other (the mirror would recurse or delete itself)",
            source=str(source),
            work=str(work),
        )
    if is_within(work, nc_data_root):
        raise ConfigError(
            "work copy must not live inside the Nextcloud data directory (.git would be scanned and synced)",
            work=str(work),
            nc_data_root=str(nc_data_root),
        )

    # The remote is optional: a publish is the commit itself, so a site without a remote is a
    # supported state ("commit only"), not a misconfiguration.
    repo_url = str(raw.get("repo_url", "")).strip()

    recipe = resolve_recipe(raw.get("recipe", {}) or {})

    exclude = set(global_exclude)
    extra = raw.get("exclude", []) or []
    if not isinstance(extra, (list, tuple)) or not all(isinstance(v, str) for v in extra):
        raise ConfigError("site.exclude must be an array of strings")
    exclude.update(extra)

    return Site(
        source=source,
        source_dir=source_dir,
        online_dir=online_dir,
        pending_dir=pending_dir,
        work=work,
        preview=preview,
        onlinepreview=onlinepreview,
        repo_url=repo_url,
        branch=str(raw.get("branch", "main")),
        remote_name=str(raw.get("remote_name", "origin")),
        recipe=recipe,
        exclude=frozenset(exclude),
        nc_user=nc_user,
        nc_path="/" + nc_path.strip("/"),
        author_root=author_root,
        authors=parse_authors(
            raw.get("authors"),
            nc_data_root=nc_data_root,
            site_source=source,
            work=work,
            error=error,
            author_root=author_root,
            previous_author_root=previous_author_root,
            preview=preview,
        ),
    )


def load_config(path: str | Path) -> Config:
    """Read the config file, then let the app-pushed ``effective.json`` override it.

    The order is fixed: the TOML first (it is the only source that exists before any pairing, and it
    names the ``state_dir`` the pushed file lives in), then the pushed values over it. The TOML is
    never rewritten - it carries the comments and the hand-written path stays usable exactly as
    before. A pushed file that cannot be used is logged and ignored, so a truncated or foreign
    ``effective.json`` can never keep the daemon from starting.
    """
    cfg_path = norm_abs(path)
    if not cfg_path.is_file():
        raise ConfigError("config file does not exist", path=str(cfg_path))
    try:
        with open(cfg_path, "rb") as fh:
            toml_raw = tomllib.load(fh)
    except tomllib.TOMLDecodeError as exc:
        raise ConfigError("config file is not valid TOML", path=str(cfg_path), detail=str(exc)) from exc
    toml_raw = dict(toml_raw)

    # Bootstrap state is looked up where the TOML says, never where a pushed value says: otherwise a
    # pushed state_dir would move the very files that tell the bridge whether it is paired or reset,
    # and the config itself would be lost. `bootstrap_state_dir` is the same value assemble_config
    # records on the Config, so the running server finds these files where this function reads them.
    bootstrap_state_dir = norm_abs(toml_raw.get("state_dir", DEFAULT_STATE_DIR))
    effective_path = bootstrap_state_dir / EFFECTIVE_FILE

    merged: dict[str, Any] = dict(toml_raw)
    app_globals: dict[str, Any] = {}
    config_source = "toml"
    revision: int | None = None
    allow_missing_site = False

    if effective_path.is_file():
        pushed: dict[str, Any] | None = None
        data = read_json(effective_path, default=None)
        if data is None:
            warn(
                "pushed site config is unusable, falling back to TOML",
                error="UNREADABLE",
                path=str(effective_path),
            )
        else:
            try:
                pushed = parse_effective(data)
            except BridgeError as exc:
                warn(
                    "pushed site config is unusable, falling back to TOML",
                    error=exc.code,
                    message=exc.message,
                )
        if pushed is not None:
            app_globals = pushed["global"]
            merged.update(app_globals)
            if pushed["site"] is not None:
                merged["site"] = pushed["site"]
                merged.pop("sites", None)
            if pushed["secrets"]:
                merged.update(pushed["secrets"])
            revision = pushed["revision"]
            config_source = "app"
            # the app owns the config: a site may still be on its way, and the app pushes it
            allow_missing_site = bool(pushed["secrets"])
            info("loaded the config pushed by the app", revision=revision, keys=sorted(app_globals))

    # The reset marker is bootstrap state too: POST /site/reset writes it before any push can move
    # state_dir, and this check runs before the pushed site is adopted. It therefore lives in the
    # TOML's directory. For backward compatibility a marker an older bridge left in the pushed
    # state_dir is still honoured (the effective value is only known once effective.json parsed);
    # the TOML's own location is checked first, so the bootstrap file always wins.
    effective_state_dir = norm_abs(merged.get("state_dir", DEFAULT_STATE_DIR))
    reset_flag: Path | None = None
    for directory in dict.fromkeys((bootstrap_state_dir, effective_state_dir)):
        if (directory / UNPAIRED_FLAG).is_file():
            reset_flag = directory / UNPAIRED_FLAG
            break
    if reset_flag is not None:
        # POST /site/reset wrote this marker: the TOML may still carry secrets and a site table
        # (the example install.sh copies has neither, but a hand-written config can have both), and
        # without the marker the next start would quietly pair the bridge with them again. A
        # successful POST /pair clears it, so the reset survives a restart but pairing can
        # re-establish the bridge.
        info(
            "the bridge was reset; ignoring the TOML secrets and site until it is paired again",
            marker=str(reset_flag),
        )
        merged = dict(toml_raw)
        for key in ("site", "sites", *SECRET_KEYS):
            merged.pop(key, None)
        return assemble_config(merged, toml_raw=toml_raw)

    if config_source == "app":
        try:
            return assemble_config(
                merged,
                toml_raw=toml_raw,
                app_globals=app_globals,
                config_source="app",
                config_revision=revision,
                allow_missing_site=allow_missing_site,
            )
        except BridgeError as exc:
            # a pushed value that does not validate (an nc_data_root that moved, a site that was
            # deleted) must not keep the daemon from starting: the TOML is still the fallback
            warn(
                "pushed site config is unusable, falling back to TOML",
                error=exc.code,
                message=exc.message,
            )
            merged = dict(toml_raw)
            app_globals = {}
            revision = None

    return assemble_config(merged, toml_raw=toml_raw, app_globals=app_globals)
