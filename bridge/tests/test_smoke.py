"""pages-bridge smoke tests (zero dependencies; run with ``python3 tests/test_smoke.py``)."""

from __future__ import annotations

import base64
import contextlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import tomllib
from pathlib import Path
from urllib.parse import quote, unquote
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from pages_bridge import __version__ as BRIDGE_VERSION  # noqa: E402
from pages_bridge import session  # noqa: E402
from pages_bridge.builder import extract_errors  # noqa: E402
from pages_bridge.config import (  # noqa: E402
    author_target_allowed,
    author_target_prefixes,
    build_site,
    load_config,
    parse_authors,
    resolve_recipe,
)
from pages_bridge.mirror import (  # noqa: E402
    Exclusion,
    author_drift,
    author_footprint,
    drift_report,
    scan,
    sync,
    sync_author,
)
from pages_bridge.util import (  # noqa: E402
    AuthError,
    BadRequest,
    ConfigError,
    MirrorDeleteGuard,
    PathTraversal,
    is_within,
    safe_join,
)

FAILURES: list[str] = []
PASSES = 0


def check(name: str, condition: bool, detail: str = "") -> None:
    global PASSES
    if condition:
        PASSES += 1
        print(f"  PASS  {name}")
    else:
        FAILURES.append(f"{name} {detail}".strip())
        print(f"  FAIL  {name}  {detail}")


def write(root: Path, rel: str, text: str, mode: int | None = None) -> Path:
    path = root / rel
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8")
    if mode is not None and os.name != "nt":
        os.chmod(path, mode)
    return path


def wfile(work: Path, rel: str = "") -> Path:
    """A site-relative path in the flat commit tree.

    The commit tree is assembled flat (``source/`` at the root, then ``online/`` overwriting the same
    names), so the skeleton path and the published path of one target are the same path - exactly
    like the remote repository, the preview and the review tree.
    """
    base = Path(work)
    return base / rel if rel else base


def wtree(work: Path) -> list[str]:
    """Every file of the commit tree, as tree-relative POSIX paths (``.git`` left out)."""
    root = Path(work)
    return sorted(
        path.relative_to(root).as_posix()
        for path in root.rglob("*")
        if path.is_file() and path.relative_to(root).parts[0] != ".git"
    )


def _rmtree(path: Path) -> None:
    """Delete a tree even when git left read-only objects in it (msys git on Windows makes
    ``.git/objects`` files read-only, and a plain ``shutil.rmtree`` cannot unlink those)."""
    def fix(_func, name, _exc):  # noqa: ANN001 - signature fixed by shutil
        try:
            os.chmod(name, 0o700)
            Path(name).unlink()
        except OSError:
            pass

    try:
        shutil.rmtree(path, onexc=fix)  # py3.12+
    except TypeError:
        shutil.rmtree(path, onerror=fix)


def test_mirror(tmp: Path) -> None:
    print("\n[1] mirror engine")
    excl = Exclusion.for_site(
        {".git", "_site", ".jekyll-cache", "vendor", "node_modules", ".bundle"}, "_site"
    )
    src = tmp / "src"
    dst = tmp / "dst"

    # source: plain files plus a 777 file, as drvfs hands them over
    write(src, "_config.yml", "title: t\n")
    write(src, "_posts/a.md", "hello\n")
    write(src, "assets/main.css", "body{}\n", mode=0o777)
    write(src, "assets/exec.sh", "#!/bin/sh\n", mode=0o777)

    # work copy: entries that exist only here and must never be deleted
    write(dst, ".git/config", "[core]\n")
    write(dst, ".git/objects/ab/cdef", "x")
    write(dst, "_site/index.html", "<html>built</html>")
    write(dst, "vendor/bundle/gem.rb", "gem")
    write(dst, "node_modules/pkg/index.js", "js")
    write(dst, "_config.yml", "title: OLD\n")          # different content: overwritten
    write(dst, "stale.md", "should be deleted\n")       # absent from the source: deleted

    r1 = sync(src, dst, excl, direction="forward")
    check("first round ok", r1.ok)
    check("first round copies 4", r1.copied == 4, f"got {r1.copied}")
    check("first round deletes 1", r1.deleted == 1, f"got {r1.deleted}")

    check("🔒 .git still there", (dst / ".git/config").is_file())
    check("🔒 .git objects untouched", (dst / ".git/objects/ab/cdef").is_file())
    check("🔒 _site still there", (dst / "_site/index.html").is_file())
    check("🔒 vendor still there", (dst / "vendor/bundle/gem.rb").is_file())
    check("🔒 node_modules still there", (dst / "node_modules/pkg/index.js").is_file())
    check("stale file deleted", not (dst / "stale.md").exists())
    check("content updated", (dst / "_config.yml").read_text(encoding="utf-8") == "title: t\n")
    check("new file copied", (dst / "_posts/a.md").is_file())
    check("_site content not polluted", (dst / "_site/index.html").read_text() == "<html>built</html>")

    if os.name != "nt":
        mode_css = (dst / "assets/main.css").stat().st_mode & 0o777
        mode_sh = (dst / "assets/exec.sh").stat().st_mode & 0o777
        check("exec bit not preserved by default (no drvfs 777 leak)", mode_css == 0o644, f"got {oct(mode_css)}")
        check("script gets no +x either", mode_sh == 0o644, f"got {oct(mode_sh)}")

        # separate source set: a 777 file already carries +x, so it cannot prove the negative case
        psrc = tmp / "psrc"
        write(psrc, "script.sh", "#!/bin/sh\n", mode=0o755)
        write(psrc, "doc.md", "# hi\n", mode=0o644)
        sync(psrc, tmp / "pdst", excl, direction="forward", preserve_exec=True)
        check(
            "preserve_exec: an executable file stays 755",
            (tmp / "pdst/script.sh").stat().st_mode & 0o777 == 0o755,
        )
        check(
            "preserve_exec: a plain file is still 644",
            (tmp / "pdst/doc.md").stat().st_mode & 0o777 == 0o644,
        )
        sync(psrc, tmp / "ndst", excl, direction="forward", preserve_exec=False)
        check(
            "default: an executable file is flattened to 644",
            (tmp / "ndst/script.sh").stat().st_mode & 0o777 == 0o644,
        )

        # an existing file keeps its mode, so the repository's 755 entries cause no mode-change churn
        msrc = tmp / "msrc"
        mdst = tmp / "mdst"
        write(msrc, "index.html", "v2\n")
        write(mdst, "index.html", "v1\n", mode=0o755)
        sync(msrc, mdst, excl, direction="forward")
        check("existing file content updated", (mdst / "index.html").read_text() == "v2\n")
        check(
            "existing file keeps its mode (755, from the repository index)",
            (mdst / "index.html").stat().st_mode & 0o777 == 0o755,
            f"got {oct((mdst / 'index.html').stat().st_mode & 0o777)}",
        )
        write(msrc, "brand-new.md", "n\n")
        sync(msrc, mdst, excl, direction="forward")
        check(
            "a new file still gets 644",
            (mdst / "brand-new.md").stat().st_mode & 0o777 == 0o644,
        )

    # idempotent: no changes means zero copies (the mtime was preserved)
    r2 = sync(src, dst, excl, direction="forward")
    check("second round idempotent (0 copies, 0 deletes)", r2.copied == 0 and r2.deleted == 0, f"{r2.to_dict()}")
    check("second round in_sync", r2.in_sync)

    # deletion propagation
    (src / "_posts/a.md").unlink()
    r3 = sync(src, dst, excl, direction="forward")
    check("deletion propagated", not (dst / "_posts/a.md").exists())
    check("empty directory cleaned up", not (dst / "_posts").exists())

    # nested empty directories: removing the innermost file must clear the whole chain
    write(src, "outer/inner/deep.md", "x")
    sync(src, dst, excl, direction="forward")
    check("nested directory mirrored", (dst / "outer/inner/deep.md").exists())
    (src / "outer/inner/deep.md").unlink()
    # the directories must go from the source too, or the mirror sees them as still present
    (src / "outer/inner").rmdir()
    (src / "outer").rmdir()
    r4 = sync(src, dst, excl, direction="forward")
    check("nested empty chain fully removed (inner)", not (dst / "outer/inner").exists())
    check("nested empty chain fully removed (outer)", not (dst / "outer").exists(), f"{r4.to_dict()}")


def test_delete_guard(tmp: Path) -> None:
    print("\n[2] delete guard")
    excl = Exclusion.for_site({".git", "_site"}, "_site")
    src = tmp / "gsrc"
    dst = tmp / "gdst"
    write(src, "keep.md", "a")
    write(dst, "keep.md", "a")
    for i in range(50):
        write(dst, f"extra/{i}.md", "x")

    # the guard returns ok=False (the caller maps it to HTTP 409), it does not raise
    r0 = sync(src, dst, excl, delete_guard_ratio=0.2, delete_guard_min=20, direction="forward")
    check("guard aborted the run", not r0.ok and r0.guard_tripped, f"{r0.to_dict()}")
    check(
        "guard gives a readable reason",
        bool(r0.guard_message) and "50" in (r0.guard_message or ""),
        str(r0.guard_message),
    )
    check("🔒 target untouched after the abort", len(list((dst / "extra").iterdir())) == 50)
    check("🔒 keep.md still there", (dst / "keep.md").is_file())

    r = sync(
        src, dst, excl, delete_guard_ratio=0.2, delete_guard_min=20, force=True, direction="forward"
    )
    check("force overrides the guard", r.ok and r.deleted == 50, f"{r.to_dict()}")

    # a missing source must never empty the target
    try:
        sync(tmp / "nope", dst, excl, direction="forward")
        check("missing source must raise", False, "no exception")
    except MirrorDeleteGuard:
        check("mirror refused when the source is missing", True)


def test_exclusion() -> None:
    print("\n[3] exclusion policy")
    excl = Exclusion.for_site({".git", "node_modules"}, ".vitepress/dist")
    check(".git matches at any depth", excl.matches(("a", ".git", "b")))
    check("node_modules matches", excl.matches(("node_modules",)))
    check("output prefix matches", excl.matches((".vitepress", "dist", "index.html")))
    check("prefix does not hit a sibling", not excl.matches((".vitepress", "config.mjs")))
    check("ordinary file does not match", not excl.matches(("_posts", "a.md")))

    from pages_bridge.config import FALLBACK_EXCLUDE

    default = Exclusion.for_site(FALLBACK_EXCLUDE, "_site")
    check("fallback list contains Gemfile.lock", "Gemfile.lock" in FALLBACK_EXCLUDE)
    check("fallback: Gemfile.lock is excluded", default.matches(("Gemfile.lock",)))
    check("fallback: a nested Gemfile.lock is excluded too", default.matches(("sub", "Gemfile.lock")))
    check("fallback: Gemfile itself is source", not default.matches(("Gemfile",)))
    check("fallback: package.json is not excluded", not default.matches(("package.json",)))
    check("fallback: the output directory is always excluded", default.matches(("_site", "index.html")))


def test_gemfile_lock_not_mirrored(tmp: Path) -> None:
    print("\n[11] Gemfile.lock never reaches Nextcloud (fallback list path)")
    from pages_bridge.config import FALLBACK_EXCLUDE

    excl = Exclusion.for_site(FALLBACK_EXCLUDE, "_site")
    work = tmp / "gl-work"
    source = tmp / "gl-source"
    write(work, "Gemfile", "source 'https://rubygems.org'\n")
    write(work, "Gemfile.lock", "GEM\n  specs:\n")
    write(work, "_config.yml", "title: t\n")
    write(work, "_site/index.html", "<html>")

    r = sync(work, source, excl, direction="reverse")
    check("reverse fill skips Gemfile.lock", not (source / "Gemfile.lock").exists())
    check("but source files are filled as usual", (source / "Gemfile").is_file() and (source / "_config.yml").is_file())
    check("the output directory does not reach the source either", not (source / "_site").exists())
    check("fill count is 2", r.copied == 2, str(r.to_dict()))

    # an existing copy stays: exclusions protect entries on the target side
    write(source, "Gemfile.lock", "OLD\n")
    r2 = sync(work, source, excl, direction="reverse")
    check("🔒 an existing copy is not deleted by the mirror", (source / "Gemfile.lock").is_file())
    check("🔒 and is not overwritten", (source / "Gemfile.lock").read_text(encoding="utf-8") == "OLD\n")
    check("the other files stay put", r2.copied == 0 and r2.deleted == 0, str(r2.to_dict()))


def test_gitignore_driven(tmp: Path) -> None:
    print("\n[12] exclusions come from the repository's own .gitignore")
    if shutil.which("git") is None or not _capture_available():
        print("  SKIP  git and subprocess pipes are required")
        return

    from pages_bridge.gitops import ignored_entries

    work = tmp / "gi-work"
    work.mkdir(parents=True, exist_ok=True)
    subprocess.run(
        ["git", "init", "-b", "main"],
        cwd=work,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=True,
    )
    (work / ".gitignore").write_text(
        "_site/\n"
        "vendor/\n"
        "node_modules/\n"
        "sub/build/\n"
        "Gemfile.lock\n"
        "*.log\n"
        "!keep.log\n",
        encoding="utf-8",
    )
    write(work, "_config.yml", "title: t\n")
    write(work, "_posts/a.md", "hello\n")
    write(work, "_site/index.html", "<html>")
    write(work, "vendor/bundle/gem.rb", "x")
    write(work, "node_modules/pkg/index.js", "js")
    write(work, "sub/build/out.html", "o")
    write(work, "sub/src/real.md", "real")
    write(work, "Gemfile", "src\n")
    write(work, "Gemfile.lock", "GEM\n")
    write(work, "debug.log", "log")
    write(work, "keep.log", "keep")
    write(work, "新文件.md", "new")          # untracked and not ignored

    entries = ignored_entries(work)
    check("ignore list readable", entries is not None)
    entries = entries or []

    excl = Exclusion.for_site({".git"}, "_site", entries)
    for rel, parts in [
        ("_site/index.html", ("_site", "index.html")),
        ("vendor/bundle/gem.rb", ("vendor", "bundle", "gem.rb")),
        ("node_modules/pkg/index.js", ("node_modules", "pkg", "index.js")),
        ("sub/build/out.html", ("sub", "build", "out.html")),
        ("Gemfile.lock", ("Gemfile.lock",)),
        ("debug.log", ("debug.log",)),
        (".git", (".git", "config")),
    ]:
        check(f"exclude {rel}", excl.matches(parts))
    for rel, parts in [
        ("_config.yml", ("_config.yml",)),
        ("_posts/a.md", ("_posts", "a.md")),
        ("Gemfile", ("Gemfile",)),
        ("sub/src/real.md", ("sub", "src", "real.md")),
        ("keep.log", ("keep.log",)),          # the !keep.log negation applies
        ("新文件.md", ("新文件.md",)),          # an untracked new file counts as source
    ]:
        check(f"keep {rel}", not excl.matches(parts))

    # end to end: no ignored entry may leak into the source during a reverse fill
    source = tmp / "gi-source"
    r = sync(work, source, excl, direction="reverse")
    leaked = [
        p for p in ("_site", "vendor", "node_modules", "Gemfile.lock", "debug.log", "sub/build")
        if (source / p).exists()
    ]
    check("no ignored entry leaked into the source", not leaked, f"leaked: {leaked}")
    check("source files were filled", (source / "_config.yml").is_file() and (source / "Gemfile").is_file())
    expected = {
        ".gitignore",            # .gitignore itself is source and must be mirrored
        "Gemfile",
        "_config.yml",
        "_posts/a.md",
        "keep.log",              # negation rule
        "新文件.md",
        "sub/src/real.md",
    }
    # the drift keys use the host separator; normalise so the same expectation works on Windows
    got = {str(p).replace("\\", "/") for p in r.drift}
    check("reverse fill mirrored exactly the source set", got == expected, f"got {sorted(got)}")

    # outside a git repository the fallback list must apply, not "exclude nothing"
    plain = tmp / "gi-plain"
    write(plain, "x.md", "x")
    check("non-git directory returns None (fallback triggered)", ignored_entries(plain) is None)


def test_dry_run(tmp: Path) -> None:
    print("\n[4] dry run")
    excl = Exclusion.for_site({".git"}, "_site")
    src = tmp / "dsrc"
    dst = tmp / "ddst"
    write(src, "a.md", "1")
    write(src, "b.md", "2")
    r = sync(src, dst, excl, dry_run=True, direction="forward")
    check("dry_run reports 2 pending copies", r.copied == 2, f"got {r.copied}")
    check("dry_run writes nothing", not (dst / "a.md").exists())
    check("dry_run flag set", r.dry_run)


def test_session() -> None:
    print("\n[5] short-lived signed URLs")
    secret = "s" * 32
    token, exp = session.issue(secret, scopes=["preview"], ttl=3600)
    check("expiry looks sane", exp > int(time.time()) + 3000)

    payload = session.verify(token, secret, scope="preview")
    check("normal verification passes", payload["scope"] == ["preview"])
    check("the payload carries no site claim", "site" not in payload, str(payload))
    check("the token version is bumped", payload["v"] == session.VERSION and session.VERSION == 2, str(payload.get("v")))

    for label, kwargs, expect in [
        ("wrong secret rejected", {"secret": "x" * 32}, AuthError),
        ("missing scope rejected", {"secret": secret, "scope": "publish"}, AuthError),
        ("expired token rejected", {"secret": secret, "now": exp + 1}, AuthError),
    ]:
        try:
            session.verify(token, kwargs.pop("secret"), **kwargs)
            check(label, False, "no exception")
        except expect:
            check(label, True)

    # a version 1 token (the multi-site payload, which carried a site claim) must be refused cleanly
    old_payload = session._b64u_encode(
        json.dumps(
            {
                "v": 1,
                "site": "site",
                "scope": ["preview"],
                "iat": int(time.time()),
                "exp": int(time.time()) + 600,
                "jti": "deadbeef",
            },
            separators=(",", ":"),
            sort_keys=True,
        ).encode("utf-8")
    )
    old_token = f"{old_payload}.{session._digest(old_payload, secret)}"
    try:
        session.verify(old_token, secret, scope="preview")
        check("a pre-upgrade (v1) token is refused", False, "no exception")
    except AuthError as exc:
        check("a pre-upgrade (v1) token is refused", exc.message == "unsupported token version", exc.message)

    try:
        session.verify(token[:-2] + "aa", secret)
        check("tampered signature rejected", False, "no exception")
    except AuthError:
        check("tampered signature rejected", True)

    try:
        session.verify_bearer("Bearer wrong", secret)
        check("wrong shared secret rejected", False)
    except AuthError:
        check("wrong shared secret rejected", True)
    try:
        session.verify_bearer(f"Bearer {secret}", secret)
        check("correct shared secret accepted", True)
    except AuthError:
        check("correct shared secret accepted", False)


def _base_config(tmp: Path) -> dict:
    root = tmp / "ncdata"
    (root / "alice" / "files").mkdir(parents=True, exist_ok=True)
    # a site folder always carries the three fixed subdirectories; source/ is the required input
    (root / "alice" / "files" / "Sites" / "t" / "source").mkdir(parents=True, exist_ok=True)
    return {
        "listen": "127.0.0.1:8787",
        "shared_secret": "a" * 32,
        "session_signing_secret": "b" * 32,
        "nc_data_root": str(root),
        "work_root": str(tmp / "work"),
        "state_dir": str(tmp / "state"),
        "override_dir": str(tmp / "overrides"),
        "log_dir": str(tmp / "logs"),
        # single site: one [site] table, no id, no name and no per-site work copy
        "site": {
            "nc_user": "alice",
            "nc_path": "/Sites/t",
            "repo_url": "git@example.com:x/y.git",
            "branch": "main",
            "recipe": {"preset": "jekyll", "output_dir": "."},
        },
    }


def _write_config(tmp: Path, data: dict, *, legacy: bool = False) -> Path:
    """Write a temporary TOML instead of converting the dict by hand."""
    tmp.mkdir(parents=True, exist_ok=True)
    path = tmp / "cfg.toml"
    path.write_text(_to_toml(data, legacy=legacy), encoding="utf-8")
    return path


def _load(tmp: Path, mutate) -> tuple[bool, str]:
    data = _base_config(tmp)
    mutate(data)
    path = _write_config(tmp, data)
    try:
        load_config(path)
        return True, ""
    except ConfigError as exc:
        return False, exc.message


def _to_toml(data: dict, *, legacy: bool = False) -> str:
    """Render the config dict as TOML; ``legacy`` writes the protocol 2 ``[[sites]]`` array."""
    lines: list[str] = []
    for key, value in data.items():
        if key in ("site", "sites"):
            continue
        lines.append(f"{key} = {_toml_value(value)}")
    site = dict(data.get("site") or (data.get("sites") or [{}])[0])
    recipe = dict(site.pop("recipe", {}) or {})
    if legacy:
        # an old-shape entry still carries the fields the single-site shape dropped
        site = {"id": site.pop("id", "t"), "name": site.pop("name", "t"), **site}
        lines.append("\n[[sites]]")
    else:
        lines.append("\n[site]")
    for key, value in site.items():
        lines.append(f"{key} = {_toml_value(value)}")
    lines.append("\n[sites.recipe]" if legacy else "\n[site.recipe]")
    for key, value in recipe.items():
        lines.append(f"{key} = {_toml_value(value)}")
    return "\n".join(lines) + "\n"


def _toml_value(value) -> str:
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        return repr(value)
    if isinstance(value, list):
        return "[" + ", ".join(_toml_value(v) for v in value) + "]"
    if isinstance(value, dict):
        return "{ " + ", ".join(f"{k} = {_toml_value(v)}" for k, v in value.items()) + " }"
    return '"' + str(value).replace("\\", "\\\\").replace('"', '\\"') + '"'


def test_config(tmp: Path) -> None:
    print("\n[6] config validation")

    ok, msg = _load(tmp / "c1", lambda d: None)
    check("baseline config accepted", ok, msg)

    def overlap(d):
        d["site"]["work_path"] = str(Path(d["nc_data_root"]) / "alice" / "files" / "Sites" / "t" / "work")

    ok, msg = _load(tmp / "c2", overlap)
    check("🔒 work copy inside the source rejected", not ok, msg)
    check("🔒 reason mentions containment or the data directory", "contain" in msg or "data directory" in msg, msg)

    ok, msg = _load(tmp / "c4", lambda d: d["site"]["recipe"].update({"output_dir": "../out"}))
    check("output dir containing .. rejected", not ok, msg)

    ok, msg = _load(tmp / "c5", lambda d: d.update({"shared_secret": "short"}))
    check("short secret rejected", not ok, msg)

    ok, msg = _load(tmp / "c6", lambda d: d["site"].update({"repo_url": ""}))
    # the remote is optional: an empty value is "no remote", and publish commits without pushing
    check("an empty repo_url is accepted (no remote)", ok, msg)

    ok, msg = _load(tmp / "c7", lambda d: d.pop("site"))
    check("a config without any site table is rejected", not ok, msg)

    # preset: jekyll is the only generator, and no marker file selects one any more
    probe = tmp / "probe"
    write(probe, "_config.yml", "a")
    write(probe, "Gemfile", "b")
    write(probe, "hugo.toml", "")
    default_recipe = resolve_recipe({})
    check(
        "a preset left out is jekyll, whatever the marker files say",
        default_recipe.preset == "jekyll",
        default_recipe.preset,
    )
    check("an empty preset is jekyll too", resolve_recipe({"preset": ""}).preset == "jekyll")
    check(
        "the jekyll default build command is used",
        list(default_recipe.command or []) == ["bundle", "exec", "jekyll", "build"],
        str(default_recipe.command),
    )
    check(
        "an explicit jekyll preset is accepted",
        resolve_recipe({"preset": "jekyll"}).preset == "jekyll",
    )
    check(
        "a legacy preset=auto (pushed by an upgraded app) still resolves to jekyll",
        resolve_recipe({"preset": "auto"}).preset == "jekyll",
    )
    try:
        resolve_recipe({"preset": "hugo"})
        check("any other preset is refused", False, "no exception")
    except ConfigError as exc:
        check("any other preset is refused and names jekyll", "jekyll" in exc.message, exc.message)
    custom_recipe = resolve_recipe({"preset": "jekyll", "command": ["make", "site"]})
    check(
        "a custom command is still honoured with the jekyll preset",
        list(custom_recipe.command or []) == ["make", "site"],
        str(custom_recipe.command),
    )


def test_paths(tmp: Path) -> None:
    print("\n[7] path safety")
    root = tmp / "safe"
    root.mkdir(parents=True, exist_ok=True)
    (root / "a.txt").write_text("x")
    check("normal join", safe_join(root, "a.txt").name == "a.txt")
    check("leading slash stripped", safe_join(root, "/a.txt").name == "a.txt")
    for evil in ("../escape", "a/../../escape", "./../x"):
        try:
            safe_join(root, evil)
            check(f"reject traversal {evil!r}", False, "no exception")
        except PathTraversal:
            check(f"reject traversal {evil!r}", True)

    # percent-encoded traversal: the preview route unquotes before calling safe_join
    for encoded in ("..%2f..%2fescape", "%2e%2e%2fescape", "a%2f..%2f..%2fescape"):
        try:
            safe_join(root, unquote(encoded))
            check(f"reject encoded traversal {encoded!r}", False, "no exception")
        except PathTraversal:
            check(f"reject encoded traversal {encoded!r}", True)
    # double encoding: either rejected outright or still inside the root
    try:
        check(
            "double encoding stays inside the root",
            is_within(safe_join(root, unquote(unquote("..%252f..%252fetc"))), root),
        )
    except PathTraversal:
        check("double encoding rejected outright", True)

    check("is_within itself", is_within(root, root))
    check("is_within outside", not is_within(tmp / "other", root))


def test_errors() -> None:
    print("\n[8] build error extraction")
    log = (
        "Configuration file: /x/_config.yml\n"
        "  Liquid Exception: undefined variable in _layouts/post.html:12: error here\n"
        "jekyll 4.4.1 | Error:  build failed\n"
        "everything is fine\n"
    )
    found = extract_errors(log)
    files = {e.get("file") for e in found}
    check("location extracted", "_layouts/post.html" in files, str(found))
    check("plain error line extracted", any("Error" in e.get("text", "") for e in found))
    check("harmless line not reported", not any("everything is fine" in e.get("text", "") for e in found))
    check("empty log does not raise", extract_errors("") == [])


def _scenario(root: Path, *, source: dict, work: dict, git_repo: bool, repo_url: str = "git@example.invalid:x/y.git"):
    """One site fixture under ``root``: a Nextcloud source folder, a work copy and a TOML to load."""
    nc_root = root / "ncdata"
    (nc_root / "alice" / "files").mkdir(parents=True, exist_ok=True)
    src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    src.mkdir(parents=True, exist_ok=True)
    # single site: the work root itself is the work copy, no <work_root>/<id>
    wk = root / "work"
    for rel, text in (source or {}).items():
        write(src, rel, text)
    for rel, text in (work or {}).items():
        write(wk, rel, text)
    if git_repo:
        wk.mkdir(parents=True, exist_ok=True)
        subprocess.run(
            ["git", "init", "-b", "main"],
            cwd=wk,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=True,
        )

    cfg_path = root / "cfg.toml"
    cfg_path.parent.mkdir(parents=True, exist_ok=True)
    cfg_path.write_text(
        _to_toml(
            {
                "listen": "127.0.0.1:8799",
                "shared_secret": "a" * 32,
                "session_signing_secret": "b" * 32,
                "nc_data_root": str(nc_root),
                "work_root": str(root / "work"),
                "state_dir": str(root / "state"),
                "override_dir": str(root / "overrides"),
                "log_dir": str(root / "logs"),
                "site": {
                    "nc_user": "alice",
                    "nc_path": "/Sites/t",
                    "repo_url": repo_url,
                    "branch": "main",
                    "recipe": {"preset": "jekyll", "output_dir": "."},
                },
            }
        ),
        encoding="utf-8",
    )
    return load_config(cfg_path), src, wk


def _capture_available() -> bool:
    """Probe whether subprocess output can be captured through a pipe."""
    try:
        subprocess.run([sys.executable, "-c", "pass"], capture_output=True, timeout=30)
        return True
    except (PermissionError, OSError):
        return False


def test_init_bootstrap(tmp: Path) -> None:
    print("\n[9] first initialization (the Nextcloud folder is never written)")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox); run it under WSL/Linux")
        return

    from pages_bridge.server import App

    base = tmp / "init"
    base.mkdir(parents=True, exist_ok=True)

    # A: the source is empty and the work copy holds a history. The source is the truth, so init
    # must NOT back-fill it from git any more: the preview would otherwise serve content that is
    # not in Nextcloud, and a Pull/Import is the only way content enters the folder.
    cfg, src, wk = _scenario(
        base / "a", source={}, work={"_config.yml": "x\n", "a.md": "1\n"}, git_repo=True
    )
    res = App(cfg).do_init(cfg.site)
    check("A reports no reverse fill", res.get("bootstrap") is None, str(res)[:200])
    check("🔒 A the source stays empty (git never writes Nextcloud)", not any(src.iterdir()), str(list(src.iterdir())))
    check("A the mirror emptied the commit tree from the empty source", not wfile(wk, "a.md").exists())
    check("🔒 A work copy .git still there", (wk / ".git").is_dir())

    # B: the source has content and the work copy is empty
    cfg, src, wk = _scenario(
        base / "b", source={"_config.yml": "x\n", "b.md": "2\n"}, work={}, git_repo=False
    )
    res = App(cfg).do_init(cfg.site)
    check("B forward mirror copied 2", res["mirror"]["copied"] == 2, str(res["mirror"]))
    check("B work copy got the files at the tree root", wfile(wk, "b.md").is_file(), wtree(wk))
    check("B git adopted", res["git"]["action"] in ("in_place", "adopt", "init"), str(res["git"]))

    # C: both sides have content, the source wins (.git stays protected)
    cfg, src, wk = _scenario(
        base / "c", source={"s.md": "s\n"}, work={"w.md": "w\n"}, git_repo=True
    )
    res = App(cfg).do_init(cfg.site)
    check("C takes the forward mirror", res["mirror"]["direction"] == "forward", str(res["mirror"]))
    check("C source file in place", wfile(wk, "s.md").is_file())
    check("C file absent from the source removed", not wfile(wk, "w.md").exists(), wtree(wk))
    check("🔒 C .git still there", (wk / ".git").is_dir())

    # D: no remote at all -> git init, and a publish is a commit
    cfg, src, wk = _scenario(
        base / "d", source={"_config.yml": "x\n", "d.md": "4\n"}, work={}, git_repo=False, repo_url=""
    )
    app = App(cfg)
    res = app.do_init(cfg.site)
    check("D init without a remote -> a local git repository", res["git"]["action"] == "init", str(res["git"]))
    check("D it is a repository", (wk / ".git").is_dir())
    check("D no remote configured", res["remote_configured"] is False, str(res)[:200])
    check("D files mirrored", wfile(wk, "d.md").is_file(), wtree(wk))

    # E: remote configured but unreachable -> init + remote add, no failure
    cfg, src, wk = _scenario(
        base / "e", source={"_config.yml": "x\n"}, work={}, git_repo=False,
        repo_url=str(base / "nowhere" / "missing.git"),
    )
    res = App(cfg).do_init(cfg.site)
    check("E unreachable remote does not fail", res.get("ok") is True, str(res)[:200])
    check("E reported as unreachable", res.get("note") == "remote_unreachable", str(res.get("git")))
    check("E the repository exists anyway", (wk / ".git").is_dir())
    remote = subprocess.run(["git", "remote", "get-url", "origin"], cwd=str(wk), capture_output=True, text=True)
    check("E the remote was recorded", remote.stdout.strip().endswith("missing.git"), remote.stdout.strip())


def _make_bare_remote(root: Path, files: dict[str, str]) -> Path:
    """Create a reachable local bare repository as the remote (offline)."""
    seed = root / "seed"
    for rel, text in files.items():
        write(seed, rel, text)
    env = {
        **os.environ,
        "GIT_AUTHOR_NAME": "t",
        "GIT_AUTHOR_EMAIL": "t@example.invalid",
        "GIT_COMMITTER_NAME": "t",
        "GIT_COMMITTER_EMAIL": "t@example.invalid",
    }
    quiet = {"stdout": subprocess.DEVNULL, "stderr": subprocess.DEVNULL, "env": env}

    def git(*args: str, cwd: Path) -> None:
        subprocess.run(["git", *args], cwd=str(cwd), check=True, **quiet)

    git("init", "-b", "main", cwd=seed)
    git("add", "-A", cwd=seed)
    git("commit", "-m", "seed", cwd=seed)
    bare = root / "origin.git"
    subprocess.run(
        ["git", "clone", "--bare", str(seed), str(bare)], check=True, **quiet
    )
    return bare


def test_work_copy_recovery(tmp: Path) -> None:
    """The work copy is data, and must be rebuilt when the directory or its .git is gone."""
    print("\n[15] work copy recovery (repository lost, first init)")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    from pages_bridge import gitops
    from pages_bridge.server import App

    base = tmp / "recover"
    base.mkdir(parents=True, exist_ok=True)
    remote = _make_bare_remote(base, {"_config.yml": "title: t\n", "index.md": "hi\n"})

    # A: an empty work copy with a reachable remote creates the repository and fetches the refs; it
    # must never clone, because the remote's files would then sit in front of the next forward mirror
    cfg, src, wk = _scenario(
        base / "a", source={"from-nc.md": "nc\n"}, work={}, git_repo=False,
        repo_url=str(remote),
    )
    res = App(cfg).do_init(cfg.site)
    check("A init creates the repository without cloning", res["git"]["action"] == "init", str(res.get("git"))[:200])
    check("A got a full .git", (wk / ".git").is_dir())
    check("A fetched the remote refs", res["git"].get("fetched") is True, str(res.get("git"))[:200])
    check(
        "A a non-empty source gets no archive",
        "archive" not in res,
        str(sorted(res))[:200],
    )
    check(
        "A the remote-tracking ref is present",
        gitops.run(wk, ["rev-parse", "--verify", "refs/remotes/origin/main"], check=False).ok,
        "",
    )
    st = gitops.status(wk)
    check("A has a local branch", st.branch == "main", f"branch={st.branch!r}")
    check("A has no upstream before a pull (nothing was checked out)", not st.upstream, f"upstream={st.upstream!r}")
    check("A HEAD is still unborn", st.head in ("", "(initial)"), st.head)
    # the work tree's files come only from the mirror: the remote's own files were never materialised
    check("A no remote file was materialised", not (wk / "index.md").exists() and not wfile(wk, "index.md").exists())
    check("A source file in place", wfile(wk, "from-nc.md").is_file(), wtree(wk))

    # B: the whole work directory is gone, the next mirror rebuilds the repository
    _rmtree(wk)
    cfg2, src2, wk2 = _scenario(
        base / "b", source={"_config.yml": "x\n", "keep.md": "k\n"}, work={}, git_repo=False,
        repo_url=str(remote),
    )
    app = App(cfg2)
    result = app.do_mirror(cfg2.site)
    check("B mirror itself succeeds", result.ok, str(result.to_dict())[:200])
    check("B work copy rebuilt by the mirror (init + fetch)", gitops.is_repo(wk2), f"work={wk2}")
    check("B files synced", wfile(wk2, "keep.md").is_file(), wtree(wk2))
    check("B the remote's file was not materialised", not (wk2 / "index.md").exists() and not wfile(wk2, "index.md").exists())
    check("B git status still reportable after the rebuild", gitops.status(wk2).branch == "main")

    # C: an unreachable remote must not fail the mirror; the repository is created locally and the
    # remote is kept for the moment it becomes reachable again
    cfg3, src3, wk3 = _scenario(
        base / "c", source={"a.md": "1\n"}, work={}, git_repo=False,
        repo_url=str(base / "does-not-exist.git"),
    )
    app3 = App(cfg3)
    result3 = app3.do_mirror(cfg3.site)
    check("C mirror still succeeds with an unreachable remote", result3.ok, str(result3.to_dict())[:200])
    check("C files still synced", wfile(wk3, "a.md").is_file(), wtree(wk3))
    check("C a local repository was created anyway", gitops.is_repo(wk3))
    check("C no commit was created by mirroring", gitops.status(wk3).head in ("", "(initial)"))


def _archive_names_and_index(path: Path) -> tuple[list[str], str]:
    """The names in a pull archive and the content of its index.md, whatever prefix git used."""
    import tarfile

    with tarfile.open(path, "r:gz") as tar:
        names = [member.name.lstrip("./") for member in tar.getmembers()]
        for member in tar.getmembers():
            if member.name.lstrip("./") == "index.md":
                handle = tar.extractfile(member)
                return names, (handle.read().decode("utf-8") if handle is not None else "")
    return names, ""


def test_pull_healing(tmp: Path) -> None:
    """[15b] Pull/Init heal an unborn HEAD and a missing work copy; the archive is the flat tree
    minus the paths this instance knows as published."""
    print("\n[15b] pull/init healing; the pull archive is the flat HEAD minus the known online paths")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    from pages_bridge import gitops
    from pages_bridge.server import App
    from pages_bridge.util import GitError

    base = tmp / "pullheal"
    base.mkdir(parents=True, exist_ok=True)
    # the repository is flat, like every tree this bridge publishes: the root IS the site
    remote = _make_bare_remote(
        base,
        {
            "_config.yml": "title: t\n",
            "index.md": "remote\n",
            "_layouts/page.html": "<html/>\n",
            "tmp/bob/published.md": "a published page\n",
        },
    )
    remote_head = gitops.head_sha(base / "seed")
    check("the throwaway remote has a commit", len(remote_head) == 40, remote_head)

    # (a) an unborn HEAD (the empty repository an init against an unreachable remote leaves behind)
    cfg_a, src_a, wk_a = _scenario(base / "a", source={}, work={}, git_repo=True, repo_url=str(remote))
    # what this instance has published: tmp/bob/published.md is online content, not source
    write(src_a.parent / "online", "tmp/bob/published.md", "a published page\n")
    check("A the fixture is an unborn repository", gitops.head_sha(wk_a) == "", repr(gitops.head_sha(wk_a)))
    result_a = App(cfg_a).do_pull(cfg_a.site)
    check("A pull on an unborn repository succeeds", result_a.get("ok") is True, str(result_a)[:300])
    check(
        "A the unborn path is used (checkout -f -B, not reset)",
        result_a["pull"].get("path") == "checkout",
        str(result_a["pull"]),
    )
    check(
        "A HEAD now points at the remote commit",
        gitops.head_sha(wk_a) == remote_head,
        f"{gitops.head_sha(wk_a)} vs {remote_head}",
    )
    names_a, index_a = _archive_names_and_index(Path(result_a["archive"]["path"]))
    check(
        "A the archive carries the remote's source",
        "index.md" in names_a and "_config.yml" in names_a and index_a.strip() == "remote",
        f"{names_a} · {index_a!r}",
    )
    check(
        "A the archive is the flat HEAD with the known online paths excluded",
        result_a["archive"].get("source_only") is True
        and result_a["archive"].get("ref") == "HEAD"
        and result_a["archive"].get("excluded") == 1
        and "remote_layout" not in result_a,
        str(result_a["archive"]),
    )
    check(
        "🔒 A the known published path is NOT in the archive (it would land in Nextcloud source/)",
        "tmp/bob/published.md" not in names_a and "tmp/bob/published.md" not in index_a,
        str(names_a),
    )
    check(
        "A the source archive has _config.yml at its top level (the app requires it)",
        names_a[0] == "_config.yml" or "_config.yml" in [n.lstrip("./").split("/")[0] for n in names_a],
        str(names_a),
    )
    check(
        "A the landed tree is flat: _config.yml, _layouts/ and the page at the work root",
        wfile(wk_a, "index.md").is_file()
        and wfile(wk_a, "_layouts/page.html").is_file()
        and wfile(wk_a, "tmp/bob/published.md").is_file()
        and not (wk_a / "source").exists()
        and not (wk_a / "online").exists(),
        wtree(wk_a),
    )
    check(
        "A the archive's names are root-relative (no source/ or online/ prefix)",
        not any(name.startswith(("source/", "online/")) for name in names_a),
        str(names_a),
    )

    # (b) no publish repository at all (a reset, or a fresh install): pull creates it itself
    cfg_b, src_b, wk_b = _scenario(base / "b", source={}, work={}, git_repo=False, repo_url=str(remote))
    write(src_b.parent / "online", "tmp/bob/published.md", "a published page\n")
    check("B there is no publish repository", not gitops.is_repo(wk_b), str(wk_b))
    result_b = App(cfg_b).do_pull(cfg_b.site)
    check("B pull created the repository itself", gitops.is_repo(wk_b), str(wk_b))
    check("B pull lands the files after creating the repository (checkout path)", result_b["pull"].get("path") == "checkout", str(result_b["pull"]))
    check("B pull succeeds", result_b.get("ok") is True, str(result_b)[:300])
    check("B HEAD is the remote commit", gitops.head_sha(wk_b) == remote_head, f"{gitops.head_sha(wk_b)} vs {remote_head}")
    names_b, index_b = _archive_names_and_index(Path(result_b["archive"]["path"]))
    check("B the archive carries the remote's source", "index.md" in names_b and index_b.strip() == "remote", f"{names_b} · {index_b!r}")
    check(
        "🔒 B the known published page is left out and counted",
        "tmp/bob/published.md" not in names_b and result_b["archive"].get("excluded") == 1,
        f"{names_b} · {result_b['archive']}",
    )

    # (b2) no repository and an unreachable remote: a clear error, not a silent no-op
    cfg_c, src_c, wk_c = _scenario(base / "c", source={}, work={}, git_repo=False, repo_url=str(base / "nope.git"))
    try:
        App(cfg_c).do_pull(cfg_c.site)
        check("C pull without a repository and with an unreachable remote fails clearly", False, "no exception")
    except GitError as exc:
        check(
            "C pull without a repository and with an unreachable remote fails clearly",
            "fetch failed" in exc.message,
            exc.message,
        )

    # (c) init on an empty source with a reachable remote: refs only, the working tree stays empty
    # (the old "fill the empty repo from the remote" behaviour is gone on purpose: files come from
    # the mirror, and a pull is the one path that lands the remote's content)
    cfg_d, src_d, wk_d = _scenario(base / "d", source={}, work={}, git_repo=True, repo_url=str(remote))
    check("D the fixture is an unborn repository", gitops.head_sha(wk_d) == "", repr(gitops.head_sha(wk_d)))
    result_d = App(cfg_d).do_init(cfg_d.site)
    check("D init adopts the existing repository", result_d["git"].get("action") == "adopt", str(result_d["git"])[:300])
    check("D init fetched the remote refs", result_d["git"].get("fetched") is True, str(result_d["git"])[:300])
    check(
        "D the remote-tracking ref is present",
        gitops.run(wk_d, ["rev-parse", "--verify", "refs/remotes/origin/main"], check=False).ok,
        "",
    )
    check("D init never materialises files (only .git in the tree)", sorted(p.name for p in wk_d.iterdir() if p.name != ".git") == [], str(sorted(p.name for p in wk_d.iterdir())))
    check("D HEAD stays unborn", gitops.head_sha(wk_d) == "", repr(gitops.head_sha(wk_d)))
    check("D the remote's files are in no working tree", not (wk_d / "index.md").exists(), "")
    check("D init does not archive anything", "archive" not in result_d, str(sorted(result_d))[:200])
    check("D remote_ahead works off the fetched refs on an unborn HEAD", gitops.remote_ahead(wk_d, "origin", "main") == 1, str(gitops.remote_ahead(wk_d, "origin", "main")))

    # ... and pull is the one path that lands them (and packages them for the app)
    write(src_d.parent / "online", "tmp/bob/published.md", "a published page\n")
    result_d2 = App(cfg_d).do_pull(cfg_d.site)
    check("D pull after init uses the checkout path", result_d2["pull"].get("path") == "checkout", str(result_d2["pull"]))
    check(
        "D pull lands the flat remote files at the work root",
        wfile(wk_d, "index.md").is_file()
        and wfile(wk_d, "tmp/bob/published.md").is_file()
        and not (wk_d / "source").exists()
        and not (wk_d / "online").exists(),
        wtree(wk_d),
    )
    names_d, index_d = _archive_names_and_index(Path(result_d2["archive"]["path"]))
    check("D the archive carries the remote's source", "index.md" in names_d and index_d.strip() == "remote", f"{names_d} · {index_d!r}")
    check(
        "🔒 D the known online path is excluded",
        "tmp/bob/published.md" not in names_d and result_d2["archive"].get("excluded") == 1,
        str(names_d),
    )

    # (e) init never archives, whatever the source holds: the work copy is mirrored from it
    cfg_e, src_e, wk_e = _scenario(base / "e", source={"_config.yml": "title: t\n"}, work={}, git_repo=False, repo_url=str(remote))
    check("E the source has content", (src_e / "_config.yml").is_file(), str(src_e))
    result_e = App(cfg_e).do_init(cfg_e.site)
    check("E init does not archive anything", "archive" not in result_e, str(sorted(result_e))[:200])
    check("E the work copy is mirrored from the source", wfile(wk_e, "_config.yml").is_file(), wtree(wk_e))


def test_default_work_root(tmp: Path) -> None:
    """The three trees share one persistent data root by default; only the preview is derived."""
    print("\n[16] default work_root is /var/lib/pages/work, next to state/overrides/logs")
    from pages_bridge.config import (
        DEFAULT_LOG_DIR,
        DEFAULT_OVERRIDE_DIR,
        DEFAULT_STATE_DIR,
        DEFAULT_WORK_ROOT,
    )

    base = tmp / "defroot"
    base.mkdir(parents=True, exist_ok=True)
    data = _base_config(base)
    data.pop("work_root")  # the built-in default is what this test is about
    cfg_path = _write_config(base, data)
    loaded = load_config(cfg_path)
    # abspath resolves the drive on Windows; the config stores the same absolute form
    expected_root = str(Path(os.path.abspath(DEFAULT_WORK_ROOT)))
    check(
        "DEFAULT_WORK_ROOT is /var/lib/pages/work",
        DEFAULT_WORK_ROOT == "/var/lib/pages/work",
        DEFAULT_WORK_ROOT,
    )
    check("Config.work_root is the default", str(loaded.work_root) == expected_root, str(loaded.work_root))
    check(
        "the default work copy is the work root itself (no <work_root>/<site>)",
        str(loaded.site.work) == expected_root,
        str(loaded.site.work),
    )
    check(
        "work, state, overrides and logs share one persistent root and are backed up together",
        Path(DEFAULT_WORK_ROOT).parent
        == Path(DEFAULT_STATE_DIR).parent
        == Path(DEFAULT_OVERRIDE_DIR).parent
        == Path(DEFAULT_LOG_DIR).parent,
        f"{DEFAULT_WORK_ROOT} / {DEFAULT_STATE_DIR} / {DEFAULT_OVERRIDE_DIR} / {DEFAULT_LOG_DIR}",
    )
    check(
        "the default work root is not under /tmp any more",
        Path(os.path.abspath("/tmp")) not in Path(expected_root).parents,
        expected_root,
    )
    check(
        "the preview tree (derived) is a sibling of the work copy",
        loaded.site.preview == loaded.work_root.parent / "work.preview",
        str(loaded.site.preview),
    )
    check(
        "the review tree (data: work + candidates) is a sibling of the work copy too",
        loaded.site.onlinepreview == loaded.work_root.parent / "work.onlinepreview",
        str(loaded.site.onlinepreview),
    )


def test_nc_user_required(tmp: Path) -> None:
    """nc_user is required: never guess a default user name."""
    print("\n[17] nc_user is required (no default user guessed)")

    def drop_user(data: dict) -> None:
        data["site"].pop("nc_user", None)

    ok, message = _load(tmp / "nouser", drop_user)
    check("config load fails without nc_user", not ok, message)
    check("the error names nc_user", "nc_user" in message, message)
    check("the error explains what it is", "Nextcloud user" in message, message)

    def blank_user(data: dict) -> None:
        data["site"]["nc_user"] = ""

    ok2, message2 = _load(tmp / "blankuser", blank_user)
    check("an empty nc_user fails too", not ok2, message2)

    def bad_user(data: dict) -> None:
        data["site"]["nc_user"] = "a/b"

    ok3, _ = _load(tmp / "baduser", bad_user)
    check("nc_user with a slash fails", not ok3)


def test_retime(tmp: Path) -> None:
    print("\n[10] mtime drift alignment (identical content is not copied again)")
    excl = Exclusion.for_site({".git"}, "_site")
    src = tmp / "rsrc"
    dst = tmp / "rdst"
    write(src, "Gemfile.lock", "GEM\n  specs:\n")

    sync(src, dst, excl, direction="forward")
    # a build touching the mtime without changing the content (typical of bundler)
    future = time.time() + 100
    os.utime(dst / "Gemfile.lock", (future, future))

    r = sync(src, dst, excl, direction="forward")
    check("timestamp aligned, no copy", r.copied == 0 and r.retimed == 1, str(r.to_dict()))
    check(
        "both sides share the mtime afterwards",
        (src / "Gemfile.lock").stat().st_mtime_ns == (dst / "Gemfile.lock").stat().st_mtime_ns,
    )
    r2 = sync(src, dst, excl, direction="forward")
    check("next round is completely still (no drift)", r2.copied == 0 and r2.retimed == 0, str(r2.to_dict()))
    check("it no longer shows up in drift", r2.drift == [], str(r2.drift))

    # counter-example: same size, different content, must be copied
    write(src, "same-size.txt", "AAAA")
    write(dst, "same-size.txt", "BBBB")
    os.utime(src / "same-size.txt", (1000, 1000))
    os.utime(dst / "same-size.txt", (2000, 2000))
    r3 = sync(src, dst, excl, direction="forward")
    check("same size but different content is copied", r3.copied == 1 and r3.retimed == 0, str(r3.to_dict()))
    check("content updated", (dst / "same-size.txt").read_text(encoding="utf-8") == "AAAA")


def test_site_dirs_config(tmp: Path) -> None:
    """The site folder's three fixed subdirectories: derivation, requirement and auto-creation."""
    print("\n[18b] site directories: source/ is required, online/ and pending/ are created")

    base = tmp / "sitedirs"
    cfg = load_config(_author_site_config(base, base / "ncdata", "", None))
    site = cfg.site
    site_root = base / "ncdata" / "alice" / "files" / "Sites" / "t"
    check(
        "source_dir is <nc_data_root>/<nc_user>/files/<nc_path>/source",
        site.source_dir == site_root / "source",
        str(site.source_dir),
    )
    check("online_dir is the online/ sibling", site.online_dir == site_root / "online", str(site.online_dir))
    check("pending_dir is the pending/ sibling", site.pending_dir == site_root / "pending", str(site.pending_dir))
    check(
        "online/ and pending/ are created on demand, empty",
        site.online_dir.is_dir() and site.pending_dir.is_dir()
        and not any(site.online_dir.iterdir()) and not any(site.pending_dir.iterdir()),
        f"{site.online_dir} {site.pending_dir}",
    )
    check(
        "the site folder is still the parent of the three directories",
        site.source == site_root and site.source_dir.parent == site.online_dir.parent == site.pending_dir.parent,
        str(site.source),
    )
    dumped = site.to_dict()
    check(
        "Site.to_dict() reports the three directories",
        dumped.get("source_dir") == str(site.source_dir)
        and dumped.get("online_dir") == str(site.online_dir)
        and dumped.get("pending_dir") == str(site.pending_dir),
        str(sorted(dumped)),
    )

    # a site whose source/ is absent is a configuration error, not an empty site
    missing_root = tmp / "sitedirs-missing"
    data = _base_config(missing_root)
    data["site"]["nc_path"] = "/Sites/missing"
    nc = Path(data["nc_data_root"])
    (nc / "alice" / "files" / "Sites" / "missing").mkdir(parents=True, exist_ok=True)
    missing_cfg = _write_config(missing_root, data)
    try:
        load_config(missing_cfg)
        check("a missing source/ is refused", False, "no exception")
    except ConfigError as exc:
        check(
            "a missing source/ is refused and names source_dir",
            "source" in exc.message and "does not exist" in exc.message,
            exc.message,
        )
        check(
            "the refusal names the derived path",
            str(Path(data["nc_data_root"]) / "alice" / "files" / "Sites" / "missing" / "source")
            == (exc.details or {}).get("source_dir"),
            str(exc.details)[:300],
        )


def test_three_tree_assembly(tmp: Path) -> None:
    """Apply source is the one assembly: source → online → (pending | authors) per tree."""
    print("\n[18c] three-tree assembly: source → online → pending/authors")
    from pages_bridge.server import App

    root = tmp / "assembly"
    nc_root = root / "ncdata"
    src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(src, "_config.yml", "skeleton\n")
    write(src, "s-only.md", "source only\n")
    write(src, "shared.md", "from source\n")
    bob_src = nc_root / "bob" / "files" / "Pages"
    write(bob_src, "a.md", "author draft\n")

    site_root = nc_root / "alice" / "files" / "Sites" / "t"
    write(site_root / "online", "o-only.md", "online only\n")
    write(site_root / "online", "shared.md", "from online\n")
    write(site_root / "pending", "p-only.md", "pending only\n")
    write(site_root / "pending", "shared.md", "from pending\n")

    cfg = load_config(_author_site_config(
        root, nc_root, "", [{"uid": "bob", "path": "/Pages", "entries": [], "removed": []}]
    ))
    site = cfg.site
    app = App(cfg)
    result = app.apply_source(site, {"build": False})
    check("Apply source rebuilt every tree", result.get("rebuilt") is True, str(result)[:300])
    check(
        "the reported file counts cover the three trees",
        sorted((result.get("files") or {})) == ["onlinepreview", "preview", "work"],
        str(result.get("files")),
    )

    def read(tree: Path, rel: str) -> str:
        path = tree / rel
        return path.read_text(encoding="utf-8") if path.is_file() else "<missing>"

    check("work: the skeleton is the base", read(site.work, "s-only.md") == "source only\n")
    check("work: online wins over source", read(site.work, "shared.md") == "from online\n")
    check("work: online adds its own files", read(site.work, "o-only.md") == "online only\n")
    check("work: pending never reaches the commit tree", read(site.work, "p-only.md") == "<missing>")
    check("work: an author draft never reaches the commit tree", read(site.work, "tmp/bob/a.md") == "<missing>")
    check(
        "🔒 work is flat: no source/ or online/ directory under the tree root",
        not (site.work / "source").exists() and not (site.work / "online").exists(),
        wtree(site.work),
    )

    check("preview: the skeleton base is there", read(site.preview, "_config.yml") == "skeleton\n")
    check("preview: online wins over source", read(site.preview, "shared.md") == "from online\n")
    check("preview: the author layer is the last one", read(site.preview, "tmp/bob/a.md") == "author draft\n")
    check("preview: pending never reaches the author preview", read(site.preview, "p-only.md") == "<missing>")

    check("onlinepreview: the skeleton base is there", read(site.onlinepreview, "s-only.md") == "source only\n")
    check("onlinepreview: pending wins over online", read(site.onlinepreview, "shared.md") == "from pending\n")
    check("onlinepreview: the pending file is present", read(site.onlinepreview, "p-only.md") == "pending only\n")
    check("onlinepreview: online's own file is still there", read(site.onlinepreview, "o-only.md") == "online only\n")
    check("onlinepreview: an author draft never enters the review tree", read(site.onlinepreview, "tmp/bob/a.md") == "<missing>")

    # the trees are derived: deleting all three and running Apply source again recreates them
    for tree in (site.work, site.preview, site.onlinepreview):
        _rmtree(tree)
    check("all three trees are gone", not site.work.exists() and not site.preview.exists() and not site.onlinepreview.exists())
    again = app.apply_source(site, {"build": False})
    check("Apply source recreates every tree from the Nextcloud folders", again.get("rebuilt") is True, str(again)[:300])
    check(
        "the recreated trees hold the same layered bytes",
        read(site.work, "shared.md") == "from online\n"
        and read(site.work, "s-only.md") == "source only\n"
        and read(site.preview, "tmp/bob/a.md") == "author draft\n"
        and read(site.onlinepreview, "shared.md") == "from pending\n",
        f"{read(site.work, 'shared.md')!r} / {read(site.work, 's-only.md')!r} / "
        f"{read(site.preview, 'tmp/bob/a.md')!r} / {read(site.onlinepreview, 'shared.md')!r}",
    )

    # no bridge-written target set exists: the whole op sequence writes only pending/ and online/
    target = "tmp/bob/a.md"
    for op in ("candidate", "publish"):
        app.do_author_delta(site, {"op": op, "uid": "bob", "area": "Pages", "target": target})
    check(
        "publish wrote online/ and cleared pending/",
        (site.online_dir / target).read_text(encoding="utf-8") == "author draft\n"
        and not (site.pending_dir / target).exists(),
        str(sorted(p.relative_to(site.online_dir).as_posix() for p in site.online_dir.rglob("*") if p.is_file())),
    )
    app.do_author_delta(site, {"op": "take-down", "uid": "bob", "area": "Pages", "target": target})
    check("take-down deleted online/", not (site.online_dir / target).exists())
    check(
        "🔒 no author_external.json / onlinepreview_candidates.json is ever written",
        not (cfg.state_dir / "author_external.json").exists()
        and not (cfg.state_dir / "onlinepreview_candidates.json").exists(),
        str(sorted(p.name for p in cfg.state_dir.rglob("*"))) if cfg.state_dir.is_dir() else "missing",
    )


def test_single_site(tmp: Path) -> None:
    """Protocol 3: the routes carry no id, the work copy is the work root, the old TOML still starts."""
    print("\n[18] single-site bridge (protocol 3)")
    from pages_bridge import PROTOCOL
    from pages_bridge.server import ROUTES

    check("the protocol constant is 3", PROTOCOL == 3, str(PROTOCOL))

    # (a) every route is id-free and the site list route is gone
    groups = {name: sorted(pattern.groupindex) for _method, pattern, name in ROUTES}
    paths = [pattern.pattern for _method, pattern, _name in ROUTES]
    check("no route has a site path parameter", all("site" not in g for g in groups.values()), str(groups))
    # 23 since GET /site/paths: the two content directories alone, the read the app makes on every
    # request that asks "is it live / is it waiting" (the 22nd was GET /site/published-content, the
    # read-back route the orphan handover needs when the source file is already gone)
    check("the route table has 23 entries", len(ROUTES) == 23, str(len(ROUTES)))
    check("GET /sites is gone", "sites" not in [name for _m, _p, name in ROUTES])
    check("no route path starts with /sites/", not any(p.startswith("^/sites") for p in paths), str(paths))
    check(
        "the site routes are id-free",
        sorted(p for p in paths if p.startswith("^/site/")) == [
            "^/site/archive$", "^/site/author-delta$", "^/site/author-root$", "^/site/build$", "^/site/diff$", "^/site/init$", "^/site/log$",
            "^/site/mirror$", "^/site/paths$", "^/site/publish$", "^/site/published-content$", "^/site/pull$", "^/site/pull/preview$",
            "^/site/reset$", "^/site/session$", "^/site/status$", "^/site/touch$",
        ],
        str(sorted(p for p in paths if p.startswith("^/site/"))),
    )

    # (b) without a work_path the work root itself is the work copy
    single = tmp / "single"
    data = _base_config(single)
    loaded = load_config(_write_config(single, data))
    check(
        "the work copy is the work root itself",
        str(loaded.site.work) == str(single / "work"),
        str(loaded.site.work),
    )
    check("work_root is unchanged", str(loaded.work_root) == str(single / "work"), str(loaded.work_root))

    explicit = tmp / "explicit"
    explicit_data = _base_config(explicit)
    explicit_data["site"]["work_path"] = str(explicit / "custom")
    explicit_cfg = load_config(_write_config(explicit, explicit_data))
    check(
        "an explicit work_path is still honoured",
        str(explicit_cfg.site.work) == str(explicit / "custom"),
        str(explicit_cfg.site.work),
    )

    # (c) the protocol 2 TOML shape still starts, with one warning line
    legacy_dir = tmp / "legacy"
    legacy_data = _base_config(legacy_dir)
    legacy_data["site"]["id"] = "my-site"
    legacy_data["site"]["name"] = "My site"
    legacy_path = _write_config(legacy_dir, legacy_data, legacy=True)
    check("the legacy TOML really holds a [[sites]] array", "[[sites]]" in legacy_path.read_text(encoding="utf-8"))
    captured = io.StringIO()
    with contextlib.redirect_stderr(captured):
        legacy_cfg = load_config(legacy_path)
    check("the old [[sites]] TOML still loads", legacy_cfg.site.nc_path == "/Sites/t", legacy_cfg.site.nc_path)
    check("the removed id/name fields are ignored", not hasattr(legacy_cfg.site, "id") and not hasattr(legacy_cfg.site, "name"))
    check(
        "it warns that the config is the old format",
        "old format" in captured.getvalue() and "install.sh" in captured.getvalue(),
        captured.getvalue()[:200],
    )

    # (d) the preview route lost its site segment; the served policy itself is exercised over HTTP
    preview = [pattern for _method, pattern, name in ROUTES if name == "preview"]
    check("there is exactly one preview route", len(preview) == 1, str(len(preview)))
    check(
        "the preview route is /preview/{token}/{path}",
        preview[0].pattern == r"^/preview/(?P<token>[^/]+)/(?P<path>.+)$",
        preview[0].pattern,
    )
    check("the preview route has no site segment", "site" not in preview[0].groupindex, str(preview[0].groupindex))
    check(
        "it matches a token plus a path",
        preview[0].match("/preview/abc.def-123/assets/main.css") is not None,
    )
    check(
        "it still needs a non-empty token segment",
        preview[0].match("/preview//index.html") is None,
    )


def test_http_layer(tmp: Path) -> None:
    """Start a real server and send real requests."""
    print("\n[13] HTTP layer (real requests)")
    import http.client
    import threading

    from pages_bridge import PROTOCOL
    from pages_bridge.server import build_server

    root = tmp / "http"
    nc_root = root / "ncdata"
    (nc_root / "alice" / "files").mkdir(parents=True, exist_ok=True)
    for nc_path in ("t", "pushed", "app", "paired"):
        (nc_root / "alice" / "files" / "Sites" / nc_path / "source").mkdir(parents=True, exist_ok=True)
    cfg_path = root / "cfg.toml"
    cfg_path.write_text(
        _to_toml({
            "listen": "127.0.0.1:8787",
            "shared_secret": "a" * 32,
            "session_signing_secret": "b" * 32,
            "nc_data_root": str(nc_root),
            "work_root": str(root / "work"),
            "state_dir": str(root / "state"),
            "override_dir": str(root / "overrides"),
            "log_dir": str(root / "logs"),
            "site": {
                "nc_user": "alice", "nc_path": "/Sites/t",
                "repo_url": "git@example.invalid:x/y.git", "branch": "main",
                "recipe": {"preset": "jekyll", "output_dir": "."},
            },
        }),
        encoding="utf-8",
    )
    cfg = load_config(cfg_path)

    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return

    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-http", daemon=True)
    thread.start()
    secret = cfg.shared_secret

    def call(method: str, path: str, body: dict | None = None, auth: str | None = secret):
        conn = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
        headers = {"Accept": "application/json"}
        if auth is not None:
            headers["Authorization"] = "Bearer " + auth
        payload = None
        if body is not None:
            payload = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"
        conn.request(method, path, body=payload, headers=headers)
        response = conn.getresponse()
        raw = response.read().decode("utf-8", "replace")
        conn.close()
        try:
            parsed = json.loads(raw)
        except json.JSONDecodeError:
            parsed = {"__raw": raw}
        return response.status, parsed

    try:
        status, body = call("GET", "/health", auth=None)
        check("GET /health needs no auth", status == 200, f"{status}")
        check("health reports the protocol version", body.get("protocol") == PROTOCOL, str(body))
        check("health reports the service version", str(body.get("version", "")).startswith("pages-bridge/"), str(body.get("version")))
        check(
            "health reports the review tree next to the two older directories",
            body.get("onlinepreview_dir") == str(app.cfg.site.onlinepreview),
            str(body.get("onlinepreview_dir")),
        )

        status, _ = call("GET", "/site/status", auth=None)
        check("missing Authorization -> 401", status == 401, f"{status}")
        status, _ = call("GET", "/site/status", auth="wrong")
        check("wrong secret -> 401", status == 401, f"{status}")
        status, body = call("GET", "/site/status")
        check("correct secret -> 200", status == 200 and "work" in body, f"{status}")
        check(
            "GET /site/status reports the review tree and its output",
            body.get("onlinepreview") == str(app.cfg.site.onlinepreview)
            and body.get("onlinepreview_output") == str(app.cfg.site.onlinepreview_output_dir),
            str({k: body.get(k) for k in ("onlinepreview", "onlinepreview_output")}),
        )

        # single site: the site list route and every id-carrying route are gone
        status, body = call("GET", "/sites")
        check("GET /sites is gone -> 404", status == 404, f"{status} {body}")
        status, body = call("GET", "/sites/t/status")
        check("the old /sites/{id}/status route is gone -> 404", status == 404, f"{status} {body}")
        status, body = call("POST", "/sites/t/build", body={})
        check("the old /sites/{id}/build route is gone -> 404", status == 404, f"{status} {body}")
        status, body = call("GET", "/site/status")
        check("the id-free /site/status works", status == 200, f"{status} {body}")

        # a preview token carries no site claim any more (protocol 3 signs scope only)
        status, body = call("GET", "/site/session?ttl=600&scope=preview")
        check("GET /site/session issues a token", status == 200 and bool(body.get("token")), f"{status} {body}")
        token_payload = session.peek(str(body.get("token", "")))
        check("the issued token has no site claim", "site" not in token_payload, str(token_payload))
        check("the issued token is version 2", token_payload.get("v") == 2, str(token_payload))
        check(
            "preview_base is the token-addressed preview route",
            body.get("preview_base") == f"/preview/{body.get('token')}/",
            str(body.get("preview_base")),
        )

        # preview policy: the resolved address inside the build output decides, nothing else.
        # No extension list, no dot-file rule - a product file is a product file.
        preview_token = str(body.get("token", ""))
        out = app.cfg.site.output_dir
        out.mkdir(parents=True, exist_ok=True)
        write(out, "notes.md", "# notes\n")
        write(out, "CNAME", "example.invalid\n")
        write(out, "robots.txt", "User-agent: *\n")
        write(out, ".well-known/security.txt", "Contact: mailto:x@example.invalid\n")
        write(out, ".htaccess", "Options -Indexes\n")
        write(out, "docs/index.html", "<html>docs</html>")
        outside = root / "outside.txt"
        outside.write_text("secret\n", encoding="utf-8")
        escape_link = out / "escape.txt"
        try:
            escape_link.symlink_to(outside)
        except OSError:
            escape_link = None

        for rel in ("notes.md", "CNAME", "robots.txt", ".well-known/security.txt", ".htaccess"):
            status, body2 = call("GET", f"/preview/{preview_token}/{rel}", auth=None)
            check(f"preview serves {rel}", status == 200, f"{status} {str(body2)[:120]}")

        status, body2 = call("GET", f"/preview/{preview_token}/docs/", auth=None)
        check("preview resolves a directory to its index.html", status == 200 and "docs" in str(body2), f"{status}")

        status, body2 = call("GET", f"/preview/{preview_token}/../outside.txt", auth=None)
        check("preview refuses lexical traversal -> 403", status == 403, f"{status} {body2}")
        status, body2 = call("GET", f"/preview/{preview_token}/%2e%2e%2foutside.txt", auth=None)
        check("preview refuses encoded traversal -> 403", status == 403, f"{status} {body2}")

        if escape_link is None:
            print("  SKIP  symlink escape (this filesystem has no symlinks)")
        else:
            status, body2 = call("GET", f"/preview/{preview_token}/escape.txt", auth=None)
            check("preview refuses a symlink that leaves the build output -> 403", status == 403, f"{status} {body2}")

        status, body2 = call("GET", f"/preview/{preview_token}/nope.txt", auth=None)
        check("preview of a missing file -> 404", status == 404, f"{status} {body2}")
        status, body2 = call("GET", "/preview/not-a-token/notes.md?t=not-a-token", auth=None)
        check("preview with a bad token -> 401", status == 401, f"{status} {body2}")
        status, body2 = call("GET", f"/preview/{preview_token}/notes.md")
        check("preview also accepts the bearer secret", status == 200, f"{status} {str(body2)[:120]}")

        # the review tree is the same route with ?tree=onlinepreview: another build output, the same
        # token and the same resolution rules. The two outputs never see each other's files.
        review_out = app.cfg.site.onlinepreview_output_dir
        write(review_out, "review.html", "<html>review</html>")
        status, body2 = call("GET", f"/preview/{preview_token}/review.html?tree=onlinepreview", auth=None)
        check(
            "preview serves the review output with ?tree=onlinepreview",
            status == 200 and "review" in str(body2),
            f"{status} {str(body2)[:120]}",
        )
        status, body2 = call("GET", f"/preview/{preview_token}/notes.md?tree=onlinepreview", auth=None)
        check(
            "🔒 ?tree=onlinepreview does not serve the author preview's output",
            status == 404,
            f"{status} {str(body2)[:120]}",
        )
        status, body2 = call("GET", f"/preview/{preview_token}/notes.md?tree=bogus", auth=None)
        check(
            "an unknown preview tree -> 400",
            status == 400 and body2.get("error") == "BAD_REQUEST",
            f"{status} {str(body2)[:200]}",
        )

        # GET/POST/DELETE on one path: these three are the regression test
        status, body = call("GET", "/admin/config")
        check("GET /admin/config → 200", status == 200, f"{status} {body}")
        check("GET /admin/config returns one site object", isinstance(body.get("site"), dict), str(body)[:200])
        status, body = call("POST", "/admin/config", body={
            "revision": 42,
            "site": {
                "nc_user": "alice", "nc_path": "/Sites/pushed",
                "repo_url": "git@example.invalid:x/y.git", "branch": "main",
                "recipe": {"preset": "jekyll", "output_dir": "."},
            },
        })
        check("POST /admin/config -> 200 (was 405 once due to routing)", status == 200, f"{status} {body}")
        check("the pushed site replaces the previous one", body.get("site") == "/Sites/pushed", str(body))
        check("pushed revision takes effect", body.get("revision") == 42, str(body))
        status, body = call("GET", "/health", auth=None)
        check("health reflects the config source", body.get("config_source") == "pushed", str(body.get("config_source")))
        status, body = call("POST", "/admin/config", body={"site": []})
        check("a non-object site is rejected", status == 400, f"{status} {body}")
        status, body = call("POST", "/admin/config", body={"sites": []})
        check("the old sites array is rejected", status == 400, f"{status} {body}")
        status, body = call("DELETE", "/admin/config")
        check("DELETE /admin/config → 200", status == 200, f"{status} {body}")

        # the same validation applies to pushed config
        status, body = call("POST", "/admin/config", body={
            "site": {
                "nc_user": "alice", "repo_url": "",
                "recipe": {"preset": "jekyll", "output_dir": "."},
            },
        })
        check("pushed invalid config rejected (same validation as TOML)", status == 500 and body.get("error") == "CONFIG_ERROR", f"{status} {body}")

        status, body = call("GET", "/nonexistent")
        check("unknown path -> 404", status == 404, f"{status}")
        status, body = call("PUT", "/health", body={})
        check("method not allowed -> 405", status == 405, f"{status}")
        check("405 lists the allowed methods", body.get("allowed") == ["GET"], str(body.get("allowed")))

        # ── hosted configuration (§15): pushes, rotation, and the UNPAIRED state ────────────────
        import hashlib

        status, body = call("GET", "/health", auth=None)
        check("a paired bridge reports paired: true", body.get("paired") is True, str(body))
        status, body = call("POST", "/pair", body={"code": "ABCDEFGH", "shared_secret": "x" * 32})
        check(
            "POST /pair on a paired bridge -> 409 ALREADY_PAIRED",
            status == 409 and body.get("error") == "ALREADY_PAIRED",
            f"{status} {body}",
        )

        app_site = {
            "nc_user": "alice", "nc_path": "/Sites/app",
            "repo_url": "git@example.invalid:x/y.git", "branch": "main",
            "recipe": {"preset": "jekyll", "output_dir": "."},
        }
        status, body = call("POST", "/admin/config", body={"revision": 50, "global": {"bogus_key": 1}, "site": app_site})
        check(
            "an unknown global key -> 400 BAD_REQUEST",
            status == 400 and body.get("error") == "BAD_REQUEST",
            f"{status} {body}",
        )
        check("the 400 names the unknown key", "bogus_key" in json.dumps(body), str(body))

        status, body = call("POST", "/admin/config", body={
            "revision": 51,
            "global": {"build_timeout_s": 240, "mirror_verify": "sha256"},
            "site": app_site,
        })
        check("a global push -> 200", status == 200, f"{status} {body}")
        check("the push reports the new revision", body.get("revision") == 51, str(body))
        check("the push names the app as the config source", body.get("config_source") == "app", str(body))
        check("a push without a listen needs no restart", body.get("restart_required") is False, str(body))
        status, body = call("GET", "/health", auth=None)
        check("health follows the app-owned config", body.get("config_source") == "app", str(body.get("config_source")))

        status, env = call("GET", "/env")
        check("GET /env carries the effective values", status == 200 and isinstance(env.get("effective"), dict), f"{status} {str(env)[:200]}")
        check("effective reflects the pushed key", env.get("effective", {}).get("build_timeout_s") == 240, str(env.get("effective")))
        check("effective reflects the pushed mirror_verify", env.get("effective", {}).get("mirror_verify") == "sha256", str(env.get("effective")))
        check("sources marks a pushed key as app", env.get("sources", {}).get("build_timeout_s") == "app", str(env.get("sources")))
        check("sources marks a TOML key as toml", env.get("sources", {}).get("log_dir") == "toml", str(env.get("sources")))
        check("sources marks an untouched key as default", env.get("sources", {}).get("log_tail_lines") == "default", str(env.get("sources")))
        check("effective keeps the TOML value of an untouched key", env.get("effective", {}).get("log_dir") == str(root / "logs"), str(env.get("effective")))
        check("the environment report carries the revision", env.get("revision") == 51, str(env.get("revision")))
        check(
            "secrets are reported as a block of two fingerprints",
            sorted(env.get("secrets", {})) == ["session_signing_secret", "shared_secret"],
            str(env.get("secrets")),
        )
        check(
            "the fingerprint is the first 16 hex of the secret's SHA-256",
            env.get("secrets", {}).get("shared_secret", {}).get("sha256_16")
            == hashlib.sha256(secret.encode("utf-8")).hexdigest()[:16],
            str(env.get("secrets")),
        )
        check("the report never carries the plaintext secret", secret not in json.dumps(env), "")
        status, body = call("GET", "/admin/config")
        check(
            "GET /admin/config also reports global and sources",
            isinstance(body.get("global"), dict) and body.get("sources", {}).get("build_timeout_s") == "app",
            str(body)[:200],
        )

        status, body = call("POST", "/admin/config", body={
            "revision": 52,
            "global": {"build_timeout_s": 240, "mirror_verify": "sha256", "listen": "127.0.0.1:8799"},
            "site": app_site,
        })
        check("a pushed listen answers restart_required", status == 200 and body.get("restart_required") is True, f"{status} {body}")
        status, body = call("GET", "/site/status")
        check("the running socket did not move", status == 200, f"{status} {str(body)[:120]}")
        status, env = call("GET", "/env")
        check("effective reports the pushed listen", env.get("effective", {}).get("listen") == "127.0.0.1:8799", str(env.get("effective", {}).get("listen")))

        # secret rotation: the old secret authenticates, the new pair is written in the same request
        rotated = {"shared_secret": "c" * 32, "session_signing_secret": "d" * 32}
        status, body = call("POST", "/admin/config", body={
            "revision": 53,
            "global": {"build_timeout_s": 240, "mirror_verify": "sha256", "listen": "127.0.0.1:8799"},
            "site": app_site,
            "secrets": rotated,
        })
        check("rotation authenticates with the old secret -> 200", status == 200, f"{status} {body}")
        status, body = call("GET", "/env", auth=rotated["shared_secret"])
        check("the rotated secret takes effect immediately", status == 200, f"{status} {str(body)[:120]}")
        status, body = call("GET", "/env", auth=secret)
        check("the old secret is refused after rotation -> 401", status == 401, f"{status} {body}")
        status, body = call("POST", "/admin/config", body={"revision": 54, "global": {}, "site": app_site}, auth=rotated["shared_secret"])
        check("an empty global block is accepted", status == 200, f"{status} {body}")
        status, body = call("GET", "/admin/config", auth=rotated["shared_secret"])
        check("the site survives a globals-only push", isinstance(body.get("site"), dict) and body["site"].get("nc_path") == "/Sites/app", str(body)[:200])
        status, env = call("GET", "/env", auth=rotated["shared_secret"])
        check(
            "a key that is no longer pushed falls back to its default",
            env.get("sources", {}).get("build_timeout_s") == "default" and env.get("effective", {}).get("build_timeout_s") == 180,
            f"{env.get('sources')} {env.get('effective')}",
        )

        status, env_before = call("GET", "/env", auth=rotated["shared_secret"])
        status, body = call("POST", "/admin/config", body={
            "revision": 55,
            "global": {"build_timeout_s": 300},
            # an empty repo_url is legal now, so the invalid value has to be a real one
            "site": {"nc_user": "alice", "repo_url": ""},
        }, auth=rotated["shared_secret"])
        check("an invalid push -> 500 CONFIG_ERROR", status == 500 and body.get("error") == "CONFIG_ERROR", f"{status} {body}")
        status, env_after = call("GET", "/env", auth=rotated["shared_secret"])
        check("a rejected push keeps the running configuration", status == 200 and env_after.get("effective") == env_before.get("effective"), str(env_after.get("effective"))[:200])
        check("a rejected push keeps the revision", env_after.get("revision") == env_before.get("revision"), str(env_after.get("revision")))

        # ── the UNPAIRED state: only /health and /pair, then the pairing flow ───────────────────
        unpaired_root = root / "unpaired"
        unpaired_state = unpaired_root / "state"
        unpaired_cfg_path = unpaired_root / "cfg.toml"

        def write_unpaired_toml(address: str) -> None:
            """A fresh install: paths only, no secrets and no [site] table."""
            unpaired_root.mkdir(parents=True, exist_ok=True)
            keys = {
                "listen": address,
                "nc_data_root": str(nc_root),
                "work_root": str(unpaired_root / "work"),
                "state_dir": str(unpaired_state),
                "override_dir": str(unpaired_root / "overrides"),
                "log_dir": str(unpaired_root / "logs"),
            }
            unpaired_cfg_path.write_text(
                "\n".join(f"{k} = {_toml_value(v)}" for k, v in keys.items()) + "\n",
                encoding="utf-8",
            )

        write_unpaired_toml("127.0.0.1:8787")
        unpaired_cfg = load_config(unpaired_cfg_path)
        check("a TOML without secrets starts UNPAIRED", unpaired_cfg.paired is False, str(unpaired_cfg.paired))
        check("an UNPAIRED bridge has no site yet", unpaired_cfg.site is None, str(unpaired_cfg.site))

        write_unpaired_toml("0.0.0.0:8787")
        try:
            load_config(unpaired_cfg_path)
            check("an UNPAIRED non-loopback listen refuses to start", False, "no exception")
        except ConfigError as exc:
            check("an UNPAIRED non-loopback listen refuses to start", "loopback" in exc.message, exc.message)
        write_unpaired_toml("127.0.0.1:8787")
        unpaired_cfg = load_config(unpaired_cfg_path)

        httpd2, app2 = build_server(unpaired_cfg, port=0)
        check("the second bridge starts UNPAIRED too", app2.paired is False, str(app2.paired))
        port2 = httpd2.server_address[1]
        thread2 = threading.Thread(target=httpd2.serve_forever, name="test-http-unpaired", daemon=True)
        thread2.start()

        def call2(method: str, path: str, body: dict | None = None, auth: str | None = None):
            conn = http.client.HTTPConnection("127.0.0.1", port2, timeout=10)
            headers = {"Accept": "application/json"}
            if auth is not None:
                headers["Authorization"] = "Bearer " + auth
            payload = None
            if body is not None:
                payload = json.dumps(body).encode("utf-8")
                headers["Content-Type"] = "application/json"
            conn.request(method, path, body=payload, headers=headers)
            response = conn.getresponse()
            raw = response.read().decode("utf-8", "replace")
            conn.close()
            try:
                parsed = json.loads(raw)
            except json.JSONDecodeError:
                parsed = {"__raw": raw}
            return response.status, parsed

        try:
            status, body = call2("GET", "/health")
            check("UNPAIRED GET /health -> 200", status == 200, f"{status} {body}")
            check("UNPAIRED health says paired: false", body.get("paired") is False, str(body))
            check("UNPAIRED health says pairing is required", body.get("pairing_required") is True, str(body))
            check(
                f"UNPAIRED health reports pages-bridge/{BRIDGE_VERSION}",
                body.get("version") == f"pages-bridge/{BRIDGE_VERSION}",
                str(body.get("version")),
            )
            check("UNPAIRED health reports protocol 3", body.get("protocol") == PROTOCOL, str(body))

            blocked = [
                ("GET", "/env"),
                ("GET", "/admin/config"),
                ("POST", "/admin/config"),
                ("DELETE", "/admin/config"),
                ("POST", "/site/init"),
                ("POST", "/site/mirror"),
                ("POST", "/site/touch"),
                ("POST", "/site/build"),
                ("GET", "/site/status"),
                ("GET", "/site/diff"),
                ("GET", "/site/log"),
                ("POST", "/site/pull"),
                ("POST", "/site/publish"),
                ("GET", "/site/session"),
                ("GET", "/preview/not-a-token/index.html"),
            ]
            for blocked_method, blocked_path in blocked:
                status, body = call2(blocked_method, blocked_path, body={} if blocked_method == "POST" else None)
                check(
                    f"UNPAIRED {blocked_method} {blocked_path} -> 503 UNPAIRED",
                    status == 503 and body.get("error") == "UNPAIRED",
                    f"{status} {str(body)[:120]}",
                )

            unpaired_state.mkdir(parents=True, exist_ok=True)
            pairing_file = unpaired_state / "pairing.json"

            def store_code(code: str, exp_offset: int = 600) -> None:
                pairing_file.write_text(
                    json.dumps({"code": code, "exp": int(time.time()) + exp_offset, "attempts": 0}),
                    encoding="utf-8",
                )
                if os.name != "nt":
                    os.chmod(pairing_file, 0o600)

            pair_body = {
                "code": "ABCD2345",
                "shared_secret": "s" * 32,
                "session_signing_secret": "t" * 32,
                "nc_data_root": str(nc_root),
                "revision": 7,
                "global": {"build_timeout_s": 300, "mirror_verify": "sha256"},
                "site": {
                    "nc_user": "alice", "nc_path": "/Sites/paired",
                    "repo_url": "git@example.invalid:x/y.git", "branch": "main",
                    "recipe": {"preset": "jekyll", "output_dir": "."},
                },
            }

            store_code("ABCD2345")
            status, body = call2("POST", "/pair", body={**pair_body, "code": "ZZZZ2345"})
            check("a wrong pairing code -> 401 BAD_CODE", status == 401 and body.get("error") == "BAD_CODE", f"{status} {body}")
            check("the 401 reports the attempts left", body.get("attempts_left") == 2, str(body))
            check(
                "the wrong attempt is charged in pairing.json",
                json.loads(pairing_file.read_text(encoding="utf-8")).get("attempts") == 1,
                pairing_file.read_text(encoding="utf-8"),
            )
            status, body = call2("POST", "/pair", body={**pair_body, "code": "ZZZZ2345"})
            check("a second wrong code -> 401 with one attempt left", status == 401 and body.get("attempts_left") == 1, f"{status} {body}")
            status, body = call2("POST", "/pair", body={**pair_body, "code": "ZZZZ2345"})
            check("the third wrong code -> 410 CODE_EXPIRED", status == 410 and body.get("error") == "CODE_EXPIRED", f"{status} {body}")
            check("the code file is gone after three failures", not pairing_file.exists())
            status, body = call2("POST", "/pair", body={**pair_body, "code": "ZZZZ2345"})
            check("a fourth attempt -> 410 with no code on disk", status == 410 and body.get("error") == "CODE_EXPIRED", f"{status} {body}")
            check("the bridge is still UNPAIRED after the failed attempts", call2("GET", "/site/status")[0] == 503)

            store_code("EFGH2345", exp_offset=-5)
            status, body = call2("POST", "/pair", body={**pair_body, "code": "EFGH2345"})
            check("an expired code -> 410 CODE_EXPIRED", status == 410 and body.get("error") == "CODE_EXPIRED", f"{status} {body}")

            store_code("IJKL2345")
            status, body = call2("POST", "/pair", body={**pair_body, "code": "ijkl2345"})
            check("a correct code (typed in lower case) pairs the bridge", status == 200 and body.get("paired") is True, f"{status} {body}")
            check("the pair response carries protocol 3", body.get("protocol") == PROTOCOL, str(body))
            check(
                f"the pair response carries pages-bridge/{BRIDGE_VERSION}",
                body.get("version") == f"pages-bridge/{BRIDGE_VERSION}",
                str(body),
            )
            check("the pair response names the app as the config source", body.get("config_source") == "app", str(body))
            check("the pair response echoes the revision", body.get("revision") == 7, str(body))
            check("pairing without a pushed listen needs no restart", body.get("restart_required") is False, str(body))
            check("pairing consumes the one-time code", not pairing_file.exists())

            effective_file = unpaired_state / "effective.json"
            check("pairing writes effective.json", effective_file.is_file())
            stored = json.loads(effective_file.read_text(encoding="utf-8")) if effective_file.is_file() else {}
            check(
                "effective.json holds revision/global/site/secrets",
                sorted(stored) == ["global", "revision", "session_signing_secret", "shared_secret", "site"],
                str(sorted(stored)),
            )
            check("effective.json keeps the pushed revision", stored.get("revision") == 7, str(stored)[:200])
            check(
                "effective.json holds the pushed secrets",
                stored.get("shared_secret") == "s" * 32 and stored.get("session_signing_secret") == "t" * 32,
                str(stored)[:200],
            )
            check("effective.json holds the pushed site", (stored.get("site") or {}).get("nc_path") == "/Sites/paired", str(stored.get("site"))[:200])
            check("effective.json holds the pushed global block", (stored.get("global") or {}).get("build_timeout_s") == 300, str(stored.get("global")))
            if os.name != "nt":
                stored_mode = effective_file.stat().st_mode & 0o777
                check("effective.json is 0600", stored_mode == 0o600, oct(stored_mode))

            status, body = call2("POST", "/pair", body=pair_body)
            check("pairing again -> 409 ALREADY_PAIRED", status == 409 and body.get("error") == "ALREADY_PAIRED", f"{status} {body}")
            status, body = call2("GET", "/health")
            check("health turns paired without a restart", body.get("paired") is True, str(body))

            status, env = call2("GET", "/env", auth="s" * 32)
            check("GET /env answers right after pairing", status == 200, f"{status} {str(env)[:120]}")
            check(
                "sources labels the pushed keys as app",
                env.get("sources", {}).get("build_timeout_s") == "app" and env.get("sources", {}).get("mirror_verify") == "app",
                str(env.get("sources")),
            )
            check("a key only the TOML sets reports toml", env.get("sources", {}).get("log_dir") == "toml", str(env.get("sources")))
            check("a key nobody set reports default", env.get("sources", {}).get("log_tail_lines") == "default", str(env.get("sources")))
            check("effective reflects the pairing push", env.get("effective", {}).get("build_timeout_s") == 300, str(env.get("effective")))
            check(
                "the fingerprints are of the pushed secrets",
                env.get("secrets", {}).get("shared_secret") == {"sha256_16": hashlib.sha256(("s" * 32).encode("utf-8")).hexdigest()[:16]},
                str(env.get("secrets")),
            )
            check(
                "the report carries the config source and the revision",
                env.get("config_source") == "app" and env.get("revision") == 7,
                f"{env.get('config_source')} {env.get('revision')}",
            )

            status, body = call2("POST", "/admin/config", body={"revision": 8, "global": {"build_timeout_s": 240}, "site": pair_body["site"]}, auth="s" * 32)
            check("a push after pairing -> 200", status == 200, f"{status} {body}")
            status, env = call2("GET", "/env", auth="s" * 32)
            check("the push is effective", env.get("effective", {}).get("build_timeout_s") == 240, str(env.get("effective")))
            check("sources follow the push", env.get("sources", {}).get("build_timeout_s") == "app", str(env.get("sources")))

            rotated_secrets = {"shared_secret": "u" * 32, "session_signing_secret": "v" * 32}
            status, body = call2("POST", "/admin/config", body={
                "revision": 9,
                "global": {"build_timeout_s": 240},
                "site": pair_body["site"],
                "secrets": rotated_secrets,
            }, auth="s" * 32)
            check("rotation through /admin/config -> 200", status == 200, f"{status} {body}")
            status, body = call2("GET", "/env", auth="u" * 32)
            check("the rotated secret takes effect", status == 200, f"{status} {str(body)[:120]}")
            status, body = call2("GET", "/env", auth="s" * 32)
            check("the old secret is refused after rotation -> 401", status == 401, f"{status} {body}")
            check(
                "effective.json holds the rotated secrets",
                json.loads(effective_file.read_text(encoding="utf-8")).get("shared_secret") == "u" * 32,
            )

            status, body = call2("POST", "/admin/config", body={
                "revision": 10,
                "global": {"build_timeout_s": 240, "listen": "127.0.0.1:8799"},
                "site": pair_body["site"],
            }, auth="u" * 32)
            check("a pushed listen -> restart_required: true", status == 200 and body.get("restart_required") is True, f"{status} {body}")
            status, body = call2("GET", "/health")
            check("the pushed listen did not move the running socket", status == 200, f"{status} {body}")
            status, env = call2("GET", "/env", auth="u" * 32)
            check("effective reports the pushed listen", env.get("effective", {}).get("listen") == "127.0.0.1:8799", str(env.get("effective", {}).get("listen")))

            status, body = call2("POST", "/admin/config", body={
                "revision": 11,
                "global": {"listen": "127.0.0.1:8799"},
                "site": {"nc_user": "alice", "repo_url": ""},
            }, auth="u" * 32)
            check("an invalid push -> 500 CONFIG_ERROR", status == 500 and body.get("error") == "CONFIG_ERROR", f"{status} {body}")
            check(
                "the rejected push kept the stored revision",
                json.loads(effective_file.read_text(encoding="utf-8")).get("revision") == 10,
                effective_file.read_text(encoding="utf-8")[:200],
            )
            status, env = call2("GET", "/env", auth="u" * 32)
            check("the rejected push kept the running configuration", env.get("effective", {}).get("build_timeout_s") == 240, str(env.get("effective")))
        finally:
            httpd2.shutdown()
            httpd2.server_close()
            thread2.join(timeout=5)
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_paired_state(tmp: Path) -> None:
    """[19] hosted config: the UNPAIRED/PAIRED decision, effective.json over the TOML, listen rules."""
    print("\n[19] unpaired/paired state and effective.json")
    from pages_bridge.config import GLOBAL_KEYS, effective_globals, global_sources
    from pages_bridge.server import PAIR_ROUTE, ROUTES, UNPAIRED_ROUTES, App
    from pages_bridge.util import Unpaired

    check("UNPAIRED is HTTP 503", Unpaired().http == 503 and Unpaired().code == "UNPAIRED", str(Unpaired().to_dict()))
    check(
        "the UNPAIRED message points at the Pages settings",
        Unpaired().to_dict().get("message")
        == "not paired yet - pair the bridge from the Nextcloud Pages settings",
        str(Unpaired().to_dict()),
    )
    # 23 = the 21 routes of the frozen table plus the two read-only ones: GET /site/published-content
    # and GET /site/paths (the app's own read of the two content directories)
    check("the frozen route table still has 23 routes", len(ROUTES) == 23, str(len(ROUTES)))
    check("POST /pair is not part of it", PAIR_ROUTE[0] == "POST" and PAIR_ROUTE[1].pattern == "^/pair$", str(PAIR_ROUTE))
    check(
        "only /health, /pair and the reset answer work while UNPAIRED",
        UNPAIRED_ROUTES == {"health", "pair", "site_reset"},
        str(UNPAIRED_ROUTES),
    )

    # install.sh copies this file verbatim, so it has to be loadable and carry neither secrets nor
    # a site seed: those two are exactly what pairing is supposed to remove from the admin's desk
    example = Path(__file__).resolve().parents[1] / "pages-bridge.toml.example"
    check("the example file exists", example.is_file(), str(example))
    example_text = example.read_text(encoding="utf-8") if example.is_file() else ""
    example_lines = [line.strip() for line in example_text.splitlines()]
    example_active = [line for line in example_lines if line and not line.startswith("#")]
    try:
        example_data: object = tomllib.loads(example_text)
    except tomllib.TOMLDecodeError as exc:
        example_data = exc
    check("the example is valid TOML", isinstance(example_data, dict) and bool(example_data), str(example_data)[:120])
    check(
        "the example carries no active secret",
        not any(line.startswith(("shared_secret", "session_signing_secret")) for line in example_active),
        str(example_active),
    )
    check(
        "the example carries no active site seed",
        not any(line.startswith(("[site]", "[site.", "[[sites]]")) for line in example_active),
        str(example_active),
    )
    commented_assignments = [
        line
        for line in example_lines
        if (line.startswith("# shared_secret") or line.startswith("# session_signing_secret"))
        and '= "' in line
    ]
    check("both secrets are shown as commented-out assignments", len(commented_assignments) == 2, str(commented_assignments))

    base = tmp / "hosted"
    base.mkdir(parents=True, exist_ok=True)
    nc_root = base / "ncdata"
    (nc_root / "alice" / "files").mkdir(parents=True, exist_ok=True)
    (nc_root / "alice" / "files" / "Sites" / "pushed" / "source").mkdir(parents=True, exist_ok=True)
    (nc_root / "alice" / "files" / "Sites" / "legacy" / "source").mkdir(parents=True, exist_ok=True)
    (nc_root / "alice" / "files" / "Sites" / "toml" / "source").mkdir(parents=True, exist_ok=True)
    state_dir = base / "state"
    cfg_path = base / "cfg.toml"

    def write_plain(**extra) -> None:
        """A fresh install: paths only, no secrets and no [site] table."""
        keys = {
            "listen": "127.0.0.1:8787",
            "nc_data_root": str(nc_root),
            "work_root": str(base / "work"),
            "state_dir": str(state_dir),
            "override_dir": str(base / "overrides"),
            "log_dir": str(base / "logs"),
        }
        keys.update(extra)
        cfg_path.write_text(
            "\n".join(f"{k} = {_toml_value(v)}" for k, v in keys.items()) + "\n", encoding="utf-8"
        )

    write_plain()
    cfg = load_config(cfg_path)
    check("no secrets and no effective.json -> UNPAIRED", cfg.paired is False, str(cfg.paired))
    check("an UNPAIRED config needs no [site] table", cfg.site is None, str(cfg.site))
    check("an UNPAIRED config reports no secrets", cfg.shared_secret == "" and cfg.session_signing_secret == "", repr(cfg.shared_secret))
    check("the paths still come from the TOML", str(cfg.state_dir) == str(state_dir), str(cfg.state_dir))
    check("the sources report covers every global key", sorted(global_sources(cfg)) == sorted(GLOBAL_KEYS), str(sorted(global_sources(cfg))))
    check("sources: a TOML key reports toml", global_sources(cfg)["work_root"] == "toml", str(global_sources(cfg)))
    check("sources: an unset key reports default", global_sources(cfg)["mirror_verify"] == "default", str(global_sources(cfg)))
    check(
        "effective lists the values in force",
        effective_globals(cfg)["listen"] == "127.0.0.1:8787" and effective_globals(cfg)["build_timeout_s"] == 180,
        str(effective_globals(cfg)),
    )

    # an unpaired bridge may not be reachable off this machine, however the address got there
    write_plain(listen="0.0.0.0:8787")
    try:
        load_config(cfg_path)
        check("an UNPAIRED non-loopback listen refuses to start", False, "no exception")
    except ConfigError as exc:
        check("an UNPAIRED non-loopback listen refuses to start", "loopback" in exc.message, exc.message)
        check("the refusal names the address", exc.details.get("listen") == "0.0.0.0:8787", str(exc.details))

    # one secret is not a pairing: both roles need their own credential
    write_plain(shared_secret="a" * 32)
    check("one secret alone is still UNPAIRED", load_config(cfg_path).paired is False, "")

    # the hand-written path: both secrets in the file, unchanged from before
    hand = _base_config(base)
    hand["listen"] = "0.0.0.0:8787"
    hand_cfg = load_config(_write_config(base, hand))
    check("both secrets in the TOML -> PAIRED", hand_cfg.paired is True, str(hand_cfg.paired))
    check("the hand-written site is used", hand_cfg.site is not None and hand_cfg.site.nc_path == "/Sites/t", str(hand_cfg.site))
    check("PAIRED may listen off-loopback", (hand_cfg.listen_host, hand_cfg.listen_port) == ("0.0.0.0", 8787), f"{hand_cfg.listen_host}:{hand_cfg.listen_port}")
    check("the hand-written config keeps its source label", hand_cfg.config_source == "toml", hand_cfg.config_source)

    # a paired config still needs a site: that is the error such a config gave before this step
    no_site = _base_config(base)
    no_site.pop("site")
    no_site_path = base / "nosrc.toml"
    no_site_path.write_text(
        "\n".join(f"{k} = {_toml_value(v)}" for k, v in no_site.items()) + "\n", encoding="utf-8"
    )
    try:
        load_config(no_site_path)
        check("secrets without a [site] table are refused (as before)", False, "no exception")
    except ConfigError as exc:
        check("secrets without a [site] table are refused (as before)", "[site]" in exc.message, exc.message)

    # effective.json over the TOML: the app owns the configuration once it has paired
    write_plain()
    state_dir.mkdir(parents=True, exist_ok=True)
    effective_file = state_dir / "effective.json"
    effective = {
        "revision": 12,
        "global": {"build_timeout_s": 240, "mirror_use_gitignore": False},
        "site": {
            "nc_user": "alice", "nc_path": "/Sites/pushed",
            "repo_url": "git@example.invalid:x/y.git", "branch": "main",
            "recipe": {"preset": "jekyll", "output_dir": "."},
        },
        "shared_secret": "c" * 32,
        "session_signing_secret": "d" * 32,
    }
    effective_file.write_text(json.dumps(effective), encoding="utf-8")
    cfg = load_config(cfg_path)
    check("effective.json pairs a bridge whose TOML has no secrets", cfg.paired is True, str(cfg.paired))
    check("the pushed site is adopted", cfg.site is not None and cfg.site.nc_path == "/Sites/pushed", str(cfg.site))
    check("the pushed global overrides the TOML", cfg.build_timeout_s == 240 and cfg.use_gitignore is False, f"{cfg.build_timeout_s} {cfg.use_gitignore}")
    check("the TOML still supplies untouched keys", str(cfg.log_dir) == str(base / "logs"), str(cfg.log_dir))
    check("sources: a pushed key reports app", global_sources(cfg)["build_timeout_s"] == "app", str(global_sources(cfg)))
    check("sources: a TOML key still reports toml", global_sources(cfg)["log_dir"] == "toml", str(global_sources(cfg)))
    check("sources: a key nobody set still reports default", global_sources(cfg)["log_tail_lines"] == "default", str(global_sources(cfg)))
    check("effective reports the pushed value", effective_globals(cfg)["build_timeout_s"] == 240, str(effective_globals(cfg)))
    check("the pushed revision and source are loaded", cfg.config_revision == 12 and cfg.config_source == "app", f"{cfg.config_revision} {cfg.config_source}")
    check("the pushed secrets are in force", cfg.shared_secret == "c" * 32 and cfg.session_signing_secret == "d" * 32, "")
    check("effective.json is read where the TOML points", cfg.bootstrap_state_dir == state_dir, str(cfg.bootstrap_state_dir))

    # an unusable pushed config falls back to the TOML instead of keeping the daemon down
    effective_file.write_text(json.dumps({**effective, "global": {"bogus": 1}}), encoding="utf-8")
    captured = io.StringIO()
    with contextlib.redirect_stderr(captured):
        cfg = load_config(cfg_path)
    check("an unknown key in effective.json falls back to the TOML", cfg.paired is False and cfg.site is None, f"{cfg.paired} {cfg.site}")
    check("the fallback is logged", "unusable" in captured.getvalue(), captured.getvalue()[:200])

    effective_file.write_text("{ not json", encoding="utf-8")
    captured = io.StringIO()
    with contextlib.redirect_stderr(captured):
        cfg = load_config(cfg_path)
    check("a corrupt effective.json falls back to the TOML", cfg.paired is False, str(cfg.paired))
    check("an unreadable pushed file is logged too", "unusable" in captured.getvalue(), captured.getvalue()[:200])

    effective_file.write_text(
        # a site table that is really invalid: the remote is optional now, an unusable site is not
        json.dumps({**effective, "site": {"nc_user": "alice", "repo_url": "", "recipe": {"preset": "jekyll", "output_dir": "."}}}),
        encoding="utf-8",
    )
    captured = io.StringIO()
    with contextlib.redirect_stderr(captured):
        cfg = load_config(cfg_path)
    check(
        "a pushed config that does not validate falls back to the TOML",
        cfg.paired is False and "unusable" in captured.getvalue(),
        captured.getvalue()[:200],
    )

    # the pushed file of the previous app version is still read: site only, with a warning
    effective_file.unlink()
    legacy_file = base / "state" / "sites.json"
    legacy_file.write_text(
        json.dumps({
            "revision": 5,
            "sites": [{
                "id": "site", "name": "t", "nc_user": "alice", "nc_path": "/Sites/legacy",
                "repo_url": "git@example.invalid:x/y.git", "branch": "main",
                "recipe": {"preset": "jekyll", "output_dir": "."},
            }],
        }),
        encoding="utf-8",
    )
    write_plain(
        shared_secret="a" * 32,
        session_signing_secret="b" * 32,
        site={
            "nc_user": "alice", "nc_path": "/Sites/toml",
            "repo_url": "git@example.invalid:x/y.git", "branch": "main",
            "recipe": {"preset": "jekyll", "output_dir": "."},
        },
    )
    captured = io.StringIO()
    with contextlib.redirect_stderr(captured):
        app = App(load_config(cfg_path))
    check("an upgraded bridge still reads the old pushed config", app.cfg.site is not None and app.cfg.site.nc_path == "/Sites/legacy", str(app.cfg.site))
    check("it warns that the pushed config is the old format", "old format" in captured.getvalue(), captured.getvalue()[:200])
    check("the old pushed revision is reported", app.config_revision == 5, str(app.config_revision))

    # effective.json wins over that legacy file, so a stale file cannot undo a pairing
    effective_file.write_text(json.dumps(effective), encoding="utf-8")
    app = App(load_config(cfg_path))
    check("effective.json wins over the legacy pushed file", app.cfg.site is not None and app.cfg.site.nc_path == "/Sites/pushed", str(app.cfg.site))
    effective_file.unlink()
    legacy_file.unlink()


def test_pairing_code(tmp: Path) -> None:
    """[20] the one-time pairing code: file format, TTL, attempt accounting."""
    print("\n[20] the one-time pairing code")
    from pages_bridge import pairing
    from pages_bridge.util import AlreadyPaired, BadPairingCode, PairingCodeExpired

    check("the alphabet is base32 without lookalikes", pairing.ALPHABET == "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567", pairing.ALPHABET)
    check("a code is 8 characters long", pairing.CODE_LEN == 8, str(pairing.CODE_LEN))
    check("the TTL is 600 s", pairing.TTL_S == 600, str(pairing.TTL_S))
    check("three wrong attempts kill the code", pairing.MAX_ATTEMPTS == 3, str(pairing.MAX_ATTEMPTS))
    check(
        "a wrong code is HTTP 401 BAD_CODE",
        BadPairingCode(2).http == 401 and BadPairingCode(2).code == "BAD_CODE",
        str(BadPairingCode(2).to_dict()),
    )
    check(
        "a wrong code carries attempts_left on top and in details",
        BadPairingCode(2).to_dict().get("attempts_left") == 2 and BadPairingCode(2).to_dict().get("details") == {"attempts_left": 2},
        str(BadPairingCode(2).to_dict()),
    )
    check(
        "an expired code is HTTP 410 CODE_EXPIRED",
        PairingCodeExpired().http == 410 and PairingCodeExpired().code == "CODE_EXPIRED",
        str(PairingCodeExpired().to_dict()),
    )
    check(
        "pairing twice is HTTP 409 ALREADY_PAIRED",
        AlreadyPaired().http == 409 and AlreadyPaired().code == "ALREADY_PAIRED",
        str(AlreadyPaired().to_dict()),
    )

    state = tmp / "pairing-code"
    state.mkdir(parents=True, exist_ok=True)
    path = state / "pairing.json"
    code = pairing.issue(state)
    check("the code uses the base32 alphabet only", all(c in pairing.ALPHABET for c in code), code)
    data = json.loads(path.read_text(encoding="utf-8"))
    check("pairing.json holds exactly code/exp/attempts", sorted(data) == ["attempts", "code", "exp"], str(data))
    check("the printed code is the stored one", data.get("code") == code, str(data))
    check("attempts start at zero", data.get("attempts") == 0, str(data))
    check("the code expires 600 s from now", 0 < int(data.get("exp", 0)) - int(time.time()) <= pairing.TTL_S, str(data.get("exp")))
    if os.name != "nt":
        mode = path.stat().st_mode & 0o777
        check("pairing.json is 0600", mode == 0o600, oct(mode))
    check("two codes are not the same", pairing.issue(state) != pairing.issue(state), "")

    pairing.issue(state)
    try:
        pairing.verify(state, "ZZZZZZZZ")
        check("a wrong code raises BadPairingCode", False, "no exception")
    except BadPairingCode as exc:
        check("a wrong code raises BadPairingCode with the attempts left", exc.attempts_left == 2, str(exc.attempts_left))
    check("the attempt is persisted", json.loads(path.read_text(encoding="utf-8")).get("attempts") == 1, path.read_text(encoding="utf-8"))
    try:
        pairing.verify(state, "ZZZZZZZZ")
        check("the second wrong code leaves one attempt", False, "no exception")
    except BadPairingCode as exc:
        check("the second wrong code leaves one attempt", exc.attempts_left == 1, str(exc.attempts_left))
    try:
        pairing.verify(state, "ZZZZZZZZ")
        check("the third wrong code kills the code", False, "no exception")
    except PairingCodeExpired:
        check("the third wrong code kills the code", True)
    check("the code file is gone after three failures", not path.exists())
    try:
        pairing.verify(state, "ZZZZZZZZ")
        check("a fourth attempt finds no code (410)", False, "no exception")
    except PairingCodeExpired:
        check("a fourth attempt finds no code (410)", True)

    stale = pairing.issue(state, ttl=-1)
    try:
        pairing.verify(state, stale)
        check("an expired code is refused", False, "no exception")
    except PairingCodeExpired:
        check("an expired code is refused", True)
    check("an expired code is cleared", not path.exists())

    fresh = pairing.issue(state)
    pairing.verify(state, fresh.lower())
    check("a code typed in lower case still verifies", True)
    pairing.verify(state, f" {fresh} ")
    check("surrounding spaces are ignored", True)
    check("verifying does not consume the code by itself", path.exists())
    pairing.clear(state)
    check("clearing removes the code file", not path.exists())
    pairing.clear(state)
    check("clearing twice is harmless", not path.exists())


def _modes_supported(path: Path) -> bool:
    """False on a checkout that lives on a Windows drive (drvfs reports 0o777 for everything)."""
    probe = path / ".mode-probe"
    try:
        probe.write_text("x", encoding="utf-8")
        os.chmod(probe, 0o600)
        return (probe.stat().st_mode & 0o777) == 0o600
    except OSError:
        return False
    finally:
        probe.unlink(missing_ok=True)


def test_site_reset(tmp: Path) -> None:
    """[20b] POST /site/reset: both trees deleted, config dropped, UNPAIRED, idempotent."""
    print("\n[20b] reset: delete both trees, drop the stored config, become unpaired")
    import threading

    from pages_bridge import pairing
    from pages_bridge.server import App, build_server

    base = tmp / "reset"
    nc_root = base / "ncdata"
    (nc_root / "alice" / "files").mkdir(parents=True, exist_ok=True)
    (nc_root / "alice" / "files" / "Sites" / "doomed" / "source").mkdir(parents=True, exist_ok=True)
    state_dir = base / "state"
    state_dir.mkdir(parents=True, exist_ok=True)
    cfg_path = base / "cfg.toml"

    def write_toml() -> None:
        """A hand-written TOML carrying both secrets and a [site] table (install.sh copies an example
        with neither)."""
        data = {
            "listen": "127.0.0.1:8787",
            "shared_secret": "c" * 32,
            "session_signing_secret": "d" * 32,
            "nc_data_root": str(nc_root),
            "work_root": str(base / "work"),
            "state_dir": str(state_dir),
            "override_dir": str(base / "overrides"),
            "log_dir": str(base / "logs"),
            "site": {
                "nc_user": "alice", "nc_path": "/Sites/doomed",
                "repo_url": "git@example.invalid:x/y.git", "branch": "main",
                "recipe": {"preset": "jekyll", "output_dir": "."},
            },
        }
        lines = [f"{key} = {_toml_value(value)}" for key, value in data.items() if key != "site"]
        lines.append("[site]")
        lines.extend(f"{key} = {_toml_value(value)}" for key, value in data["site"].items())
        cfg_path.write_text("\n".join(lines) + "\n", encoding="utf-8")

    write_toml()
    (state_dir / "state.json").write_text(json.dumps({"last_build": {"ok": True}}), encoding="utf-8")

    cfg = load_config(cfg_path)
    check("the reset fixture starts PAIRED", cfg.paired is True and cfg.site is not None, str(cfg.paired))
    work = cfg.site.work
    preview = cfg.site.preview
    write(work, "_config.yml", "title: doomed\n")
    write(work, "index.md", "# doomed\n")
    write(preview, "index.html", "<html>doomed</html>")
    write(preview, ".jekyll-cache/keep", "cache\n")

    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        app = App(cfg)
        httpd = None
    if httpd is not None:
        thread = threading.Thread(target=httpd.serve_forever, name="test-reset", daemon=True)
        thread.start()
        port = httpd.server_address[1]

    try:
        status, body = _http_call(port, "POST", "/site/reset", {}, auth="wrong") if httpd else (None, {})
        if httpd:
            check("a paired bridge refuses an unauthenticated reset", status == 401, f"{status} {body}")
        result = app.do_reset()
        check("the reset answers ok", result.get("ok") is True, str(result))
        check("the reset did not report already_clean", result.get("already_clean") is False, str(result))
        check("the bridge is UNPAIRED afterwards", app.paired is False, str(app.paired))
        check("no site is left in force", app.cfg.site is None, str(app.cfg.site))
        check("both secrets are gone", app.cfg.shared_secret == "" and app.cfg.session_signing_secret == "", repr(app.cfg.shared_secret))
        flag = state_dir / "reset-unpaired.flag"
        check("the reset marker is written (the TOML still names secrets and a site)", flag.is_file(), str(flag))
        check("the build report is gone", not (state_dir / "state.json").exists(), "")

        removed = result.get("removed") or []
        check("both trees were deleted", len(removed) == 2, str(removed))
        check("the reported paths are the two trees", {str(work), str(preview)} == set(removed), str(removed))
        check("the work tree is gone", not work.exists(), str(work))
        check("the preview tree is gone", not preview.exists(), str(preview))
        # the old behaviour left a copy behind under work.reset-* / preview.reset-*: the files must
        # not survive anywhere, and no sibling archive may be created either
        siblings = sorted(p.name for p in Path(work).parent.iterdir() if p.name.startswith(("work.reset-", "preview.reset-")))
        check("no work.reset-*/preview.reset-* sibling was created", siblings == [], str(siblings))

        second = app.do_reset()
        check("a second reset reports already_clean", second.get("already_clean") is True, str(second))
        check("a second reset removes nothing", second.get("removed") == [], str(second))
        if httpd:
            status, body = _http_call(port, "POST", "/site/reset", {})
            check("POST /site/reset on an unpaired bridge answers 200 already_clean without a secret", status == 200 and body.get("already_clean") is True, f"{status} {body}")
            status, body = _http_call(port, "GET", "/site/status", auth="c" * 32)
            check("every other route is 503 after the reset", status == 503 and body.get("error") == "UNPAIRED", f"{status} {body}")

        # a restart must not pair itself from the TOML again: that is what the marker is for
        restarted = App(load_config(cfg_path))
        check("a restart stays UNPAIRED although the TOML still holds secrets and a site", restarted.paired is False and restarted.cfg.site is None, f"{restarted.paired} {restarted.cfg.site}")

        # ... and pairing again clears the marker, so the bridge is a normal paired one afterwards
        code = pairing.issue(state_dir)
        restarted.pair({
            "code": code,
            "shared_secret": "e" * 32,
            "session_signing_secret": "f" * 32,
            "nc_data_root": str(nc_root),
        })
        check("pairing after a reset succeeds", restarted.paired is True, str(restarted.paired))
        check("pairing clears the reset marker", not flag.exists(), str(flag))
        again = App(load_config(cfg_path))
        check("a restart after the new pairing is PAIRED", again.paired is True, str(again.paired))
        check("the new secrets are in force", again.cfg.shared_secret == "e" * 32, repr(again.cfg.shared_secret))
    finally:
        if httpd is not None:
            httpd.shutdown()
            httpd.server_close()


def test_bootstrap_state_dir(tmp: Path) -> None:
    """[20c] bootstrap state stays in the TOML's state_dir even when the app pushed another one.

    The split is deliberate: ``effective.json``, the reset marker and the pairing code are read
    before (or without) a pushed configuration, so they follow the TOML. The business state
    (``state.json`` and the build log) follows the pushed value. Before this test, a push could move
    the reset marker and the pairing code while the loader still looked at the TOML - a reset
    "forgot itself" after a restart and a freshly printed code answered CODE_EXPIRED.
    """
    print("\n[20c] bootstrap state_dir: reset flag, pairing code, work repair")
    from pages_bridge import pairing
    from pages_bridge.server import App

    site = {
        "nc_user": "alice", "nc_path": "/Sites/split",
        "repo_url": "git@example.invalid:x/y.git", "branch": "main",
        "recipe": {"preset": "jekyll", "output_dir": "."},
    }

    # ── the reported shape: the app pushed another state_dir than the TOML names ─────────────────
    base = tmp / "bootstrap-split"
    nc_root = base / "ncdata"
    (nc_root / "alice" / "files").mkdir(parents=True, exist_ok=True)
    # the startup repair now refuses an empty source/ (it must not assemble an empty commit tree),
    # so the fixture carries the one skeleton file a real site always has
    split_src = nc_root / "alice" / "files" / "Sites" / "split" / "source"
    split_src.mkdir(parents=True, exist_ok=True)
    write(split_src, "_config.yml", "title: t\n")
    bootstrap = base / "state"           # the TOML's state_dir: bootstrap state lives here
    pushed = base / "pushed-state"       # what the app pushed: the business state moves here
    bootstrap.mkdir(parents=True, exist_ok=True)
    cfg_path = _write_config(base, {
        "listen": "127.0.0.1:8787",
        "shared_secret": "a" * 32,
        "session_signing_secret": "b" * 32,
        "nc_data_root": str(nc_root),
        "work_root": str(base / "work"),
        "state_dir": str(bootstrap),
        "override_dir": str(base / "overrides"),
        "log_dir": str(base / "logs"),
        "site": site,
    })
    (bootstrap / "effective.json").write_text(json.dumps({
        "revision": 3,
        "global": {"state_dir": str(pushed)},
        "site": site,
        "shared_secret": "c" * 32,
        "session_signing_secret": "d" * 32,
    }), encoding="utf-8")

    cfg = load_config(cfg_path)
    check(
        "a pushed state_dir is in force for the business state",
        cfg.state_dir == pushed and cfg.bootstrap_state_dir == bootstrap,
        f"{cfg.state_dir} {cfg.bootstrap_state_dir}",
    )
    check("the pushed secrets are in force", cfg.paired is True, str(cfg.paired))
    check(
        "effective.json was read where the TOML points",
        cfg.config_source == "app" and (bootstrap / "effective.json").is_file(),
        cfg.config_source,
    )

    captured = io.StringIO()
    with contextlib.redirect_stdout(captured):
        app = App(cfg)
    check(
        "the bootstrap/effective split is explained once",
        "bootstrap files stay where the TOML points" in captured.getvalue(),
        captured.getvalue()[:300],
    )
    captured = io.StringIO()
    with contextlib.redirect_stdout(captured):
        App(cfg)
    check("the explanation is not repeated", "bootstrap files stay" not in captured.getvalue(), captured.getvalue()[:200])
    check(
        "the business state follows the pushed directory",
        app.state.path == pushed / "state.json",
        f"{app.state.path}",
    )

    # no bridge-written target set exists any more: the work consistency check is the repair path,
    # and it reads online/ from the site folder, not from the pushed state_dir
    write(cfg.site.online_dir, "tmp/alice/hello.md", "hello\n")
    check("a published file missing from work/ is a drift", app.work_drift(cfg.site) is not None, str(app.work_drift(cfg.site)))
    verdict = app.verify_state_files()
    check(
        "the startup check rebuilds the commit tree from online/ instead of refusing",
        verdict.get("ok") is True and verdict.get("rebuilt") is True
        and wfile(cfg.site.work, "tmp/alice/hello.md").is_file(),
        str(verdict)[:300],
    )
    check("the rebuilt tree is consistent again", app.work_drift(cfg.site) is None, str(app.work_drift(cfg.site)))
    check("no state-lost.json is written anywhere", not list(bootstrap.glob("state-lost.json")) and not list(pushed.glob("state-lost.json")))

    result = app.do_reset()
    flag = bootstrap / "reset-unpaired.flag"
    check("the reset marker is written to the TOML's state_dir", flag.is_file(), str(flag))
    check("the pushed state_dir gets no reset marker", not (pushed / "reset-unpaired.flag").exists(), str(pushed))
    check("the reset reports the bootstrap marker", result.get("flag") == str(flag), str(result.get("flag")))
    check("the pushed config is gone", not (bootstrap / "effective.json").exists(), "")

    restarted = App(load_config(cfg_path))
    check(
        "a restart sees the reset although the pushed state_dir carried the config",
        restarted.paired is False and restarted.cfg.site is None,
        f"{restarted.paired} {restarted.cfg.site}",
    )
    check(
        "the restarted bridge falls back to the TOML's state_dir",
        restarted.cfg.state_dir == bootstrap and restarted.cfg.bootstrap_state_dir == bootstrap,
        str(restarted.cfg.state_dir),
    )

    # pair-code writes the code into the bootstrap directory; pair() must read it there
    code = pairing.issue(restarted.cfg.bootstrap_state_dir)
    check("pair-code writes into the TOML's state_dir", (bootstrap / "pairing.json").is_file(), str(bootstrap))
    restarted.pair({
        "code": code,
        "shared_secret": "e" * 32,
        "session_signing_secret": "f" * 32,
        "nc_data_root": str(nc_root),
    })
    check("pairing spends the code pair-code just wrote", restarted.paired is True, str(restarted.paired))
    check("the code file is gone after pairing", not (bootstrap / "pairing.json").exists(), "")
    check("pairing clears the reset marker", not flag.exists(), "")
    check(
        "pairing writes effective.json where load_config reads it",
        (bootstrap / "effective.json").is_file() and not (pushed / "effective.json").exists(),
        str(bootstrap / "effective.json"),
    )

    # ── backward compatibility with a bridge that wrote into the state_dir in force ──────────────
    legacy_base = tmp / "bootstrap-legacy"
    l_nc = legacy_base / "ncdata"
    (l_nc / "alice" / "files" / "Sites" / "split" / "source").mkdir(parents=True, exist_ok=True)
    l_boot = legacy_base / "state"
    l_pushed = legacy_base / "pushed"
    l_boot.mkdir(parents=True, exist_ok=True)
    l_cfg_path = _write_config(legacy_base, {
        "listen": "127.0.0.1:8787",
        "nc_data_root": str(l_nc),
        "work_root": str(legacy_base / "work"),
        "state_dir": str(l_boot),
        "override_dir": str(legacy_base / "overrides"),
        "log_dir": str(legacy_base / "logs"),
        "site": site,
    })
    (l_boot / "effective.json").write_text(json.dumps({
        "revision": 4, "global": {"state_dir": str(l_pushed)}, "site": site,
    }), encoding="utf-8")

    # an older bridge wrote the reset marker into the pushed state_dir: it is still honoured
    l_pushed.mkdir(parents=True, exist_ok=True)
    (l_pushed / "reset-unpaired.flag").write_text("{}", encoding="utf-8")
    legacy_cfg = load_config(l_cfg_path)
    check(
        "a reset marker in the pushed state_dir is still honoured",
        legacy_cfg.paired is False and legacy_cfg.site is None,
        f"{legacy_cfg.paired} {legacy_cfg.site}",
    )

    # ... and a code written there by an older pair-code is still accepted, then spent where found
    (l_pushed / "reset-unpaired.flag").unlink()
    legacy_cfg = load_config(l_cfg_path)
    check(
        "without the marker the pushed state_dir is in force again",
        legacy_cfg.state_dir == l_pushed and legacy_cfg.bootstrap_state_dir == l_boot,
        f"{legacy_cfg.state_dir} {legacy_cfg.bootstrap_state_dir}",
    )
    legacy_app = App(legacy_cfg)
    legacy_code = pairing.issue(l_pushed)
    legacy_app.pair({
        "code": legacy_code,
        "shared_secret": "1" * 32,
        "session_signing_secret": "2" * 32,
        "nc_data_root": str(l_nc),
    })
    check("a code written into the pushed state_dir is still accepted", legacy_app.paired is True, str(legacy_app.paired))
    check("the legacy code is cleared where it was found", not (l_pushed / "pairing.json").exists(), str(l_pushed / "pairing.json"))
    check("no stray code is left in the bootstrap directory", not (l_boot / "pairing.json").exists(), str(l_boot / "pairing.json"))


# ── multi-source sites: one site, one folder per author (P2, bridge half) ────────────────────────

def _author_entry(rel: str, target: str) -> dict:
    return {"path": rel, "target": target}


def _author_payload(uid: str = "alice", path: str = "/Pages/posts", entries=None, removed=None) -> dict:
    """One element of the ``site.authors`` array exactly as the app pushes it."""
    return {
        "uid": uid,
        "path": path,
        "entries": [
            _author_entry("2026-05-01-hello.md", f"tmp/{uid}/2026-05-01-hello.md"),
            _author_entry("theme.css", f"tmp/{uid}/theme.css"),
        ] if entries is None else entries,
        "removed": list(removed or []),
    }


def _author_site_config(
    root: Path, nc_root: Path, repo_url: str, authors, recipe=None, author_root: str | None = None
) -> Path:
    """A TOML config for the multi-source tests; ``authors=None`` writes no authors key at all."""
    root.mkdir(parents=True, exist_ok=True)
    # the site folder's required skeleton; a test that writes site files writes into source/
    site_root = nc_root / "alice" / "files" / "Sites" / "t"
    (site_root / "source").mkdir(parents=True, exist_ok=True)
    site = {
        "nc_user": "alice", "nc_path": "/Sites/t",
        "repo_url": repo_url, "branch": "main",
        "recipe": recipe or {"preset": "jekyll", "output_dir": "."},
    }
    if authors is not None:
        site["authors"] = authors
    if author_root is not None:
        site["author_root"] = author_root
    cfg_path = root / "cfg.toml"
    cfg_path.write_text(
        _to_toml({
            "listen": "127.0.0.1:8787",
            "shared_secret": "a" * 32,
            "session_signing_secret": "b" * 32,
            "nc_data_root": str(nc_root),
            "work_root": str(root / "work"),
            "state_dir": str(root / "state"),
            "override_dir": str(root / "overrides"),
            "log_dir": str(root / "logs"),
            "mirror_delete_guard_min": 2,
            "site": site,
        }),
        encoding="utf-8",
    )
    return cfg_path


def _http_call(port: int, method: str, path: str, body: dict | None = None, auth: str | None = None):
    """Send one real request, with the same shape the inline callers of the older tests return."""
    import http.client

    conn = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
    headers = {"Accept": "application/json"}
    if auth is not None:
        headers["Authorization"] = "Bearer " + auth
    payload = None
    if body is not None:
        payload = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    conn.request(method, path, body=payload, headers=headers)
    response = conn.getresponse()
    raw = response.read().decode("utf-8", "replace")
    conn.close()
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError:
        parsed = {"__raw": raw}
    return response.status, parsed


def test_author_config(tmp: Path) -> None:
    print("\n[21] author sources: config and payload validation")
    base = tmp / "acfg"

    data = _base_config(base)
    data["site"]["authors"] = [_author_payload()]
    cfg = load_config(_write_config(base, data))
    site = cfg.site
    check("a config with one author loads", [author.uid for author in site.authors] == ["alice"], str(site.authors))
    check(
        "an author's source is inside that user's own files folder",
        str(site.authors[0].source) == str(base / "ncdata" / "alice" / "files" / "Pages" / "posts"),
        str(site.authors[0].source),
    )
    check(
        "the entries are parsed in order",
        [entry.target for entry in site.authors[0].entries]
        == ["tmp/alice/2026-05-01-hello.md", "tmp/alice/theme.css"],
        str(site.authors[0].entries),
    )
    check("the author path is kept absolute", site.authors[0].path == "/Pages/posts", site.authors[0].path)
    dumped = site.to_dict()
    check(
        "Site.to_dict() carries the authors",
        dumped["authors"][0]["uid"] == "alice"
        and dumped["authors"][0]["entries"][1]["target"] == "tmp/alice/theme.css",
        str(dumped.get("authors"))[:200],
    )

    plain_dir = tmp / "acfg-plain"
    plain = load_config(_write_config(plain_dir, _base_config(plain_dir)))
    check("a config without authors has none", plain.site.authors == (), str(plain.site.authors))
    check(
        "and Site.to_dict() reports an empty list",
        plain.site.to_dict()["authors"] == [],
        str(plain.site.to_dict().get("authors")),
    )

    two = _base_config(base / "two")
    two["site"]["authors"] = [
        _author_payload("bob"),
        _author_payload("carol", entries=[], removed=["tmp/carol/2026-04-01-old.md"]),
    ]
    cfg2 = load_config(_write_config(base / "two", two))
    check("several authors are kept in order", [a.uid for a in cfg2.site.authors] == ["bob", "carol"], str(cfg2.site.authors))
    check(
        "an author with removals only is accepted",
        cfg2.site.authors[1].entries == () and cfg2.site.authors[1].removed == ("tmp/carol/2026-04-01-old.md",),
        str(cfg2.site.authors[1]),
    )
    ok, msg = _load(base / "empty", lambda d: d["site"].update({"authors": []}))
    check("an empty authors list is still the single-source site", ok, msg)

    # the retired author areas: neither shape may reach a target any more
    for target in ("_posts/bob/2026-05-01-hello.md", "assets/authors/bob.css",
                   "assets/authors/bob/2026-05-01-hello.css"):
        ok, msg = _load(base / "legacy", lambda d, t=target: d["site"].update(
            {"authors": [_author_payload("bob", entries=[{"path": "x.css", "target": t}])]}))
        check(f"the legacy target {target!r} is refused", (not ok) and "target" in msg, msg)

    cases = [
        ("a relative author path is refused", [_author_payload(path="Pages/posts")], "path"),
        ("a missing author path is refused", [_author_payload(path="")], "path"),
        ("an author path with .. is refused", [_author_payload(path="/Pages/../etc")], "path"),
        ("a missing uid is refused", [_author_payload(uid="")], "uid"),
        ("a uid with a slash is refused", [_author_payload(uid="a/b")], "uid"),
        ("a duplicate uid is refused", [_author_payload("bob"), _author_payload("bob")], "duplicate"),
        ("a non-array authors value is refused", "alice", "array"),
        ("a non-object author is refused", ["alice"], "object"),
        ("entries that are not an array are refused", [_author_payload("bob", entries={"path": "x"})], "entries"),
        ("an entry without a source path is refused", [_author_payload("bob", entries=[{"target": "tmp/bob/x.md"}])], "path"),
        ("an absolute entry path is refused", [_author_payload("bob", entries=[{"path": "/x.md", "target": "tmp/bob/x.md"}])], "path"),
        ("an entry path with .. is refused", [_author_payload("bob", entries=[{"path": "../x.md", "target": "tmp/bob/x.md"}])], "path"),
        ("an entry path with a ./ component is refused", [_author_payload("bob", entries=[{"path": "./x.md", "target": "tmp/bob/x.md"}])], "path"),
        ("a target with a doubled slash is refused", [_author_payload("bob", entries=[{"path": "x.md", "target": "tmp//bob/x.md"}])], "target"),
        ("a removal with a ./ component is refused", [_author_payload("bob", removed=["tmp/./bob/x.md"])], "target"),
        ("an entry without a target is refused", [_author_payload("bob", entries=[{"path": "x.md"}])], "target"),
        ("a target inside another author's tree is refused", [_author_payload("bob", entries=[{"path": "x.md", "target": "tmp/carol/x.md"}])], "target"),
        ("a target outside the author directory is refused", [_author_payload("bob", entries=[{"path": "index.html", "target": "index.html"}])], "target"),
        ("a target climbing out of the site is refused", [_author_payload("bob", entries=[{"path": "x", "target": "../escape"}])], "target"),
        ("a target on the site's own files is refused", [_author_payload("bob", entries=[{"path": "x", "target": "_config.yml"}])], "target"),
        ("a duplicate target is refused", [_author_payload("bob", entries=[
            {"path": "a.md", "target": "tmp/bob/x.md"}, {"path": "b.md", "target": "tmp/bob/x.md"}])], "duplicate"),
        ("a removed value that is not a string is refused", [_author_payload("bob", removed=[7])], "removed"),
        ("a removal outside the author's area is refused", [_author_payload("bob", removed=["_config.yml"])], "target"),
        ("a removal climbing out of the site is refused", [_author_payload("bob", removed=["../escape"])], "target"),
        ("a duplicate removal is refused", [_author_payload("bob", removed=["tmp/bob/x.md", "tmp/bob/x.md"])], "duplicate"),
    ]
    for name, authors, field in cases:
        ok, msg = _load(base / "case", lambda d, a=authors: d["site"].update({"authors": a}))
        check(name, (not ok) and field in msg, msg)

    # the same payload arriving in a request is a 400 naming the field, not a config error
    def payload_case(authors) -> tuple[bool, str]:
        try:
            parse_authors(
                authors,
                nc_data_root=base / "ncdata",
                site_source=base / "ncdata" / "alice" / "files" / "Sites" / "t",
                work=base / "work",
                error=BadRequest,
            )
            return True, ""
        except BadRequest as exc:
            return False, exc.message

    ok, msg = payload_case([_author_payload("bob", entries=[{"path": "x", "target": "index.html"}])])
    check("a pushed invalid target raises BAD_REQUEST naming target", (not ok) and "target" in msg, msg)
    ok, msg = payload_case([_author_payload("bob", path="Pages/posts")])
    check("a pushed relative path raises BAD_REQUEST naming path", (not ok) and "path" in msg, msg)

    ok, msg = _load(base / "over1", lambda d: d["site"].update({"authors": [_author_payload("alice", path="/Sites/t")]}))
    check("an author source equal to the site source is refused", (not ok) and "overlap" in msg, msg)
    ok, msg = _load(base / "over2", lambda d: d["site"].update({"authors": [_author_payload("alice", path="/Sites")]}))
    check("an author source containing the site source is refused", (not ok) and "overlap" in msg, msg)

    # the documented TOML form: an array of tables, not an inline list
    toml_root = base / "atoml"
    nc_toml = toml_root / "ncdata"
    (nc_toml / "alice" / "files" / "Sites" / "t" / "source").mkdir(parents=True, exist_ok=True)
    (toml_root / "work").mkdir(parents=True, exist_ok=True)
    cfg_toml = toml_root / "cfg.toml"
    cfg_toml.write_text(
        "\n".join([
            'listen = "127.0.0.1:8787"',
            f'shared_secret = "{"a" * 32}"',
            f'session_signing_secret = "{"b" * 32}"',
            f'nc_data_root = "{nc_toml.as_posix()}"',
            f'work_root = "{(toml_root / "work").as_posix()}"',
            f'state_dir = "{(toml_root / "state").as_posix()}"',
            f'override_dir = "{(toml_root / "overrides").as_posix()}"',
            f'log_dir = "{(toml_root / "logs").as_posix()}"',
            "",
            "[site]",
            'nc_user = "alice"',
            'nc_path = "/Sites/t"',
            'repo_url = "git@example.invalid:x/y.git"',
            "",
            "[site.recipe]",
            'preset = "jekyll"',
            'output_dir = "."',
            "",
            "[[site.authors]]",
            'uid = "bob"',
            'path = "/Pages/posts"',
            'removed = ["tmp/bob/2026-04-01-old.md"]',
            "",
            "[[site.authors.entries]]",
            'path = "2026-05-01-hello.md"',
            'target = "tmp/bob/2026-05-01-hello.md"',
            "",
            "[[site.authors.entries]]",
            'path = "theme.css"',
            'target = "tmp/bob/theme.css"',
            "",
        ]),
        encoding="utf-8",
    )
    cfg_toml_loaded = load_config(cfg_toml)
    check(
        "the [[site.authors]] array-of-tables form is accepted",
        [author.uid for author in cfg_toml_loaded.site.authors] == ["bob"],
        str(cfg_toml_loaded.site.authors),
    )
    check(
        "its entries and removals are parsed",
        len(cfg_toml_loaded.site.authors[0].entries) == 2
        and cfg_toml_loaded.site.authors[0].removed == ("tmp/bob/2026-04-01-old.md",),
        str(cfg_toml_loaded.site.authors[0]),
    )

    # ── the configurable author directory: `site.author_root` ────────────────────────────────────
    #
    # Every author's whole tree is mirrored into one site directory (`<author_root>/<uid>/…`). The
    # default is `tmp` so a client that does not send the field keeps working.
    default_root_dir = base / "aroot-default"
    default_cfg = _base_config(default_root_dir)
    default_cfg["site"]["authors"] = [_author_payload("bob", entries=[
        {"path": "notes/deep/page.md", "target": "tmp/bob/notes/deep/page.md"},
        {"path": "images/pic.png", "target": "tmp/bob/images/pic.png"},
    ])]
    default_loaded = load_config(_write_config(default_root_dir, default_cfg))
    check("the author root defaults to tmp", default_loaded.site.author_root == "tmp", default_loaded.site.author_root)
    check(
        "a nested target and an image are accepted under the default root",
        [entry.target for entry in default_loaded.site.authors[0].entries]
        == ["tmp/bob/notes/deep/page.md", "tmp/bob/images/pic.png"],
        str(default_loaded.site.authors[0].entries),
    )

    custom_dir = base / "aroot-custom"
    custom_cfg = _base_config(custom_dir)
    custom_cfg["site"]["author_root"] = "people"
    custom_cfg["site"]["authors"] = [{
        "uid": "bob", "path": "/Pages",
        "entries": [
            {"path": "notes/deep/page.md", "target": "people/bob/notes/deep/page.md"},
            {"path": "images/pic.png", "target": "people/bob/images/pic.png"},
            {"path": "theme.css", "target": "people/bob/theme.css"},
        ],
        "removed": ["people/bob/old/gone.png"],
    }]
    custom_loaded = load_config(_write_config(custom_dir, custom_cfg))
    check(
        "a custom author root is read from the config",
        custom_loaded.site.author_root == "people",
        custom_loaded.site.author_root,
    )
    check(
        "a stylesheet inside the author's tree is accepted next to a custom root",
        custom_loaded.site.authors[0].entries[2].target == "people/bob/theme.css",
        str(custom_loaded.site.authors[0].entries),
    )
    check(
        "Site.to_dict() carries the author root",
        custom_loaded.site.to_dict()["author_root"] == "people",
        str(custom_loaded.site.to_dict())[:200],
    )

    ok, msg = _load(base / "aroot-neg", lambda d: d["site"].update({"authors": [_author_payload(
        "bob", entries=[{"path": "x.png", "target": "people/bob/x.png"}])]}))
    check("a target under an undeclared root is refused", (not ok) and "target" in msg, msg)
    ok, msg = _load(base / "aroot-neg2", lambda d: d["site"].update({"authors": [_author_payload(
        "bob", entries=[{"path": "x.png", "target": "pgp/bob/x.png"}])]}))
    check("a target under a foreign directory is refused", (not ok) and "target" in msg, msg)
    ok, msg = _load(base / "aroot-neg3", lambda d: d["site"].update({"authors": [_author_payload(
        "bob", entries=[{"path": "x.html", "target": "_includes/x.html"}])]}))
    check("a target on a Jekyll-private directory is refused", (not ok) and "target" in msg, msg)

    # the pushed path (400, not a config error) validates against the payload's own author_root
    def payload_root(target: str, *, author_root: str | None = None, previous_author_root: str | None = None) -> tuple[bool, str]:
        kwargs = {} if author_root is None else {"author_root": author_root}
        if previous_author_root is not None:
            kwargs["previous_author_root"] = previous_author_root
        try:
            parse_authors(
                [{"uid": "bob", "path": "/Pages", "removed": [],
                  "entries": [{"path": "x.png", "target": target}]}],
                nc_data_root=base / "ncdata",
                site_source=base / "ncdata" / "alice" / "files" / "Sites" / "t",
                work=base / "work",
                error=BadRequest,
                **kwargs,
            )
            return True, ""
        except BadRequest as exc:
            return False, exc.message

    ok, msg = payload_root("people/bob/x.png", author_root="people")
    check("a pushed payload validates against its own author_root", ok, msg)
    ok, msg = payload_root("people/bob/x.png")
    check("the same target is refused when the root is the default", (not ok) and "target" in msg, msg)
    ok, msg = payload_root("tmp/bob/x.png")
    check("a target under the default root is accepted by a pushed payload", ok, msg)

    # moving the directory: the old copies have to be nameable as removals, or the mirror would serve
    # them next to the new ones forever
    ok, msg = payload_root("tmp/bob/x.png", author_root="people", previous_author_root="tmp")
    check("an entry under the previous root is still refused", (not ok) and "target" in msg, msg)
    moved = parse_authors(
        [{"uid": "bob", "path": "/Pages", "entries": [
            {"path": "x.png", "target": "people/bob/x.png"}],
          "removed": ["tmp/bob/old.png"]}],
        nc_data_root=base / "ncdata",
        site_source=base / "ncdata" / "alice" / "files" / "Sites" / "t",
        work=base / "work",
        error=BadRequest,
        author_root="people",
        previous_author_root="tmp",
    )[0]
    check(
        "a removal under the previous author directory is accepted",
        moved.removed == ("tmp/bob/old.png",),
        str(moved.removed),
    )
    ok, msg = payload_root("people/bob/x.png", author_root="people", previous_author_root="tmp")
    check("an entry under the current root is accepted", ok, msg)
    try:
        parse_authors(
            [{"uid": "bob", "path": "/Pages", "entries": [],
              "removed": ["tmp/carol/old.png"]}],
            nc_data_root=base / "ncdata",
            site_source=base / "ncdata" / "alice" / "files" / "Sites" / "t",
            work=base / "work",
            error=BadRequest,
            author_root="people",
            previous_author_root="tmp",
        )
        check("a removal under the previous root is still this author's only", False, "accepted")
    except BadRequest as exc:
        check("a removal under the previous root is still this author's only", "target" in exc.message, exc.message)

    def pushed_root(value: str) -> tuple[bool, str]:
        raw = {
            "nc_user": "alice", "nc_path": "/Sites/t", "repo_url": "git@example.invalid:x/y.git",
            "recipe": {"preset": "jekyll", "output_dir": "."}, "author_root": value,
        }
        try:
            build_site(raw, nc_data_root=base / "ncdata", work_root=base / "work",
                       global_exclude=frozenset(), error=BadRequest)
            return True, ""
        except BadRequest as exc:
            return False, exc.message

    for value in ("", "a/b", "..", ".git", "_site", "two words"):
        ok, msg = pushed_root(value)
        check(f"a pushed author_root {value!r} is refused with a message naming it",
              (not ok) and "author_root" in msg, msg)
    ok, msg = pushed_root("people")
    check("a pushed author_root 'people' is accepted", ok, msg)

    # one derivation feeds both the validation and the skeleton-mirror exclusion
    check(
        "author_target_prefixes returns only the author's own tree",
        author_target_prefixes("bob", "people") == ("people/bob",),
        str(author_target_prefixes("bob", "people")),
    )
    check(
        "every prefix the exclusion hides is a target the validation accepts",
        author_target_allowed("bob", "people/bob/img/x.png", "people")
        and author_target_allowed("bob", "people/bob/x/y/z.png", "people")
        and not author_target_allowed("bob", "people/carol/x.png", "people"),
        "people/bob tree and another author's tree",
    )
    check(
        "the retired _posts/<uid> and assets/authors areas are refused outright",
        not author_target_allowed("bob", "_posts/bob/x.md", "people")
        and not author_target_allowed("bob", "assets/authors/bob.css", "people")
        and not author_target_allowed("bob", "assets/authors/bob/2026-05-01-hello.css", "people"),
        "legacy areas",
    )


def test_author_mirror(tmp: Path) -> None:
    print("\n[22] author mirror: additive, deletion only when it is named")
    root = tmp / "amir"
    nc_root = root / "ncdata"
    work = root / "work"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    site_src.mkdir(parents=True, exist_ok=True)
    author_root = nc_root / "bob" / "files" / "Pages" / "posts"
    author_root.mkdir(parents=True, exist_ok=True)
    excl = Exclusion.for_site({".git"}, "_site")

    def author(entries, removed=(), uid="bob", path="/Pages/posts"):
        return parse_authors(
            [{"uid": uid, "path": path, "entries": list(entries), "removed": list(removed)}],
            nc_data_root=nc_root, site_source=site_src, work=work,
        )[0]

    post = {"path": "2026-05-01-hello.md", "target": "tmp/bob/2026-05-01-hello.md"}
    write(author_root, "2026-05-01-hello.md", "hello\n")
    first = author([post])
    r = sync_author(first.source, work, excl, first)
    check(
        "an author entry lands at its target",
        (work / "tmp/bob/2026-05-01-hello.md").read_text(encoding="utf-8") == "hello\n",
    )
    check("its parent directory was created", (work / "tmp/bob").is_dir())
    check("a new target counts as copied", r.copied == 1 and r.updated == 0, str(r.to_dict()))
    # the byte count is the source file's own size: on Windows write_text stores \r\n, so a
    # hardcoded 6 would fail there for the wrong reason
    check("the copied bytes are counted", r.bytes_copied == (author_root / "2026-05-01-hello.md").stat().st_size, str(r.to_dict()))
    check("the round is ok", r.ok, str(r.to_dict()))

    r = sync_author(first.source, work, excl, first)
    check("a second round writes nothing", r.copied == 0 and r.updated == 0 and r.unchanged == 1, str(r.to_dict()))
    check("a round with nothing to do is in sync", r.in_sync and r.missing == [], str(r.to_dict()))

    write(author_root, "2026-05-01-hello.md", "hello again\n")
    r = sync_author(first.source, work, excl, first)
    check("a changed source updates the target", r.updated == 1 and r.copied == 0, str(r.to_dict()))
    check(
        "the target holds the new text",
        (work / "tmp/bob/2026-05-01-hello.md").read_text(encoding="utf-8") == "hello again\n",
    )

    (author_root / "2026-05-01-hello.md").unlink()
    r = sync_author(first.source, work, excl, first)
    check("a vanished source is reported missing", r.missing == ["tmp/bob/2026-05-01-hello.md"], str(r.to_dict()))
    check("🔒 the missing target is not deleted", (work / "tmp/bob/2026-05-01-hello.md").is_file())
    check("the missing round deleted nothing", r.deleted == 0, str(r.to_dict()))
    drift = author_drift(first.source, work, excl, first)
    check("a missing entry leaves the author out of sync", drift["in_sync"] is False, str(drift))

    write(author_root, "theme.css", "body{color:red}\n")
    write(author_root, "2026-05-01-hello.css", ".post{}\n")
    styles = author([
        {"path": "theme.css", "target": "tmp/bob/theme.css"},
        {"path": "2026-05-01-hello.css", "target": "tmp/bob/2026-05-01-hello.css"},
    ])
    sync_author(styles.source, work, excl, styles)
    check(
        "theme.css lands at its target in the author's tree",
        (work / "tmp/bob/theme.css").read_text(encoding="utf-8") == "body{color:red}\n",
    )
    check(
        "a post's sibling stylesheet lands next to it",
        (work / "tmp/bob/2026-05-01-hello.css").is_file(),
    )

    write(author_root, ".git/config", "[core]\n")
    skipped = author([{"path": ".git/config", "target": "tmp/bob/git-config.md"}])
    r = sync_author(skipped.source, work, excl, skipped)
    check(
        "an entry the site excludes is skipped, not copied",
        r.skipped == ["tmp/bob/git-config.md"] and not (work / "tmp/bob/git-config.md").exists(),
        str(r.to_dict()),
    )

    # deletion: only a target the app named
    write(work, "tmp/bob/2026-04-01-old.md", "old\n")
    write(work, "tmp/bob/2026-04-03-kept.md", "kept\n")
    removal = author([], removed=["tmp/bob/2026-04-01-old.md"])
    r = sync_author(removal.source, work, excl, removal)
    check(
        "a target named in removed is deleted",
        r.deleted == 1 and not (work / "tmp/bob/2026-04-01-old.md").exists(),
        str(r.to_dict()),
    )
    check("the deletion is reported in removed", r.removed == ["tmp/bob/2026-04-01-old.md"], str(r.to_dict()))
    check("🔒 a target nobody named survives", (work / "tmp/bob/2026-04-03-kept.md").is_file())
    check("the author's directory survives while it holds files", (work / "tmp/bob").is_dir())
    r = sync_author(removal.source, work, excl, removal)
    check("removing a target that is not there is harmless", r.ok and r.deleted == 0, str(r.to_dict()))

    # the whole author folder vanished: nothing of theirs may be deleted
    write(work, "tmp/bob/2026-04-02-gone.md", "published\n")
    gone = author([post], removed=["tmp/bob/2026-04-02-gone.md"])
    _rmtree(author_root)
    r = sync_author(gone.source, work, excl, gone)
    check("a vanished author folder reports its entries missing", r.missing == ["tmp/bob/2026-05-01-hello.md"], str(r.to_dict()))
    check("source_missing is reported", r.source_missing is True, str(r.to_dict()))
    check(
        "🔒 a vanished author folder deletes nothing",
        r.deleted == 0 and (work / "tmp/bob/2026-04-02-gone.md").is_file(),
        str(r.to_dict()),
    )
    check("the withheld removal is reported", r.withheld == ["tmp/bob/2026-04-02-gone.md"], str(r.to_dict()))
    check("a withheld removal keeps the author out of sync", r.in_sync is False, str(r.to_dict()))
    r = sync_author(gone.source, work, excl, gone, force=True)
    check(
        "force=true carries the withheld removal out",
        r.deleted == 1 and not (work / "tmp/bob/2026-04-02-gone.md").exists(),
        str(r.to_dict()),
    )

    # ── the configurable root: the author's whole tree, nested and with images ───────────────────
    people_nc = root / "pncdata"
    people_src = people_nc / "bob" / "files" / "Pages"
    write(people_src, "notes/2026-05-01-img/pic.png", "PNG\n")
    people_author = parse_authors(
        [{
            "uid": "bob", "path": "/Pages",
            "entries": [
                {"path": "notes/2026-05-01-img/pic.png",
                 "target": "people/bob/notes/2026-05-01-img/pic.png"},
            ],
            "removed": ["people/bob/notes/old/gone.png"],
        }],
        nc_data_root=people_nc, site_source=site_src, work=work, author_root="people",
    )[0]
    write(work, "people/bob/notes/old/gone.png", "old\n")
    r = sync_author(people_author.source, work, excl, people_author, author_root="people")
    check(
        "a nested image under the configured root is mirrored",
        (work / "people/bob/notes/2026-05-01-img/pic.png").read_text(encoding="utf-8") == "PNG\n",
        str(r.to_dict()),
    )
    check(
        "a removal under the configured root is carried out",
        r.deleted == 1 and not (work / "people/bob/notes/old/gone.png").exists(),
        str(r.to_dict()),
    )
    check(
        "the author's own directory survives the prune",
        (work / "people/bob").is_dir(),
        str(sorted(path.name for path in (work / "people/bob").iterdir())),
    )
    check(
        "the delete-guard footprint counts the configured root",
        author_footprint(work, "bob", "people") >= 1,
        str(author_footprint(work, "bob", "people")),
    )

    # a mass removal goes through the delete guard
    guard_nc = root / "guard" / "ncdata"
    guard_work = root / "guard" / "work"
    guard_src = guard_nc / "carol" / "files" / "Pages" / "posts"
    guard_src.mkdir(parents=True, exist_ok=True)
    guard_site = guard_nc / "sites" / "files" / "Sites" / "t"
    guard_site.mkdir(parents=True, exist_ok=True)
    doomed = [f"tmp/carol/2026-03-0{index}-old.md" for index in range(5)]
    for rel in doomed:
        write(guard_work, rel, "old\n")
    carol = parse_authors(
        [{"uid": "carol", "path": "/Pages/posts", "entries": [], "removed": doomed}],
        nc_data_root=guard_nc, site_source=guard_site, work=guard_work,
    )[0]
    r = sync_author(carol.source, guard_work, excl, carol, delete_guard_min=2)
    check("a mass removal trips the delete guard", r.ok is False and r.guard_tripped, str(r.to_dict()))
    check(
        "🔒 the guarded round deleted nothing",
        r.deleted == 0 and all((guard_work / rel).is_file() for rel in doomed),
        str(r.to_dict()),
    )
    check("the guard message names the budget", "budget" in (r.guard_message or ""), str(r.guard_message or ""))
    r = sync_author(carol.source, guard_work, excl, carol, force=True)
    check("force=true carries the mass removal out", r.ok and r.deleted == 5, str(r.to_dict()))
    check("the emptied author directory is pruned", not (guard_work / "tmp/carol").exists())
    check("the shared author root stays", (guard_work / "tmp").is_dir())

    # the skeleton mirror never touches an author area
    skel_src = root / "skel" / "src"
    skel_work = root / "skel" / "work"
    write(skel_src, "_config.yml", "title: t\n")
    write(skel_src, "tmp/bob/from-owner.md", "the owner has a folder of that name too\n")
    write(skel_work, "tmp/bob/2026-05-01-hello.md", "published\n")
    write(skel_work, "stale.md", "gone\n")
    skel_excl = Exclusion.for_site({".git"}, "_site", ["tmp/bob"])
    r = sync(skel_src, skel_work, skel_excl, direction="forward")
    check("🔒 the skeleton mirror does not delete an author's file", (skel_work / "tmp/bob/2026-05-01-hello.md").is_file())
    check(
        "🔒 the skeleton mirror does not echo its own tmp/<uid> over the author's",
        not (skel_work / "tmp/bob/from-owner.md").exists(),
    )
    check("the skeleton mirror still deletes its own stale file", not (skel_work / "stale.md").exists())
    check("the skeleton round deleted exactly its own file", r.deleted == 1, str(r.to_dict()))
    skel_drift = drift_report(skel_src, skel_work, skel_excl)
    check("the skeleton round is in sync with the author areas present", skel_drift["in_sync"] is True, str(skel_drift))

    # the app builds exactly that policy out of the site's authors
    from pages_bridge.server import App

    policy_cfg = load_config(
        _author_site_config(root / "policy", nc_root, "git@example.invalid:x/y.git", [_author_payload("bob")])
    )
    policy_app = App(policy_cfg)
    check("the skeleton policy hides the author's tree", policy_app.exclusion(policy_cfg.site).matches(("tmp", "bob", "x.md")))
    check(
        "the author policy does not hide the author's own target",
        not policy_app.author_exclusion(policy_cfg.site).matches(("tmp", "bob", "x.md")),
    )
    plain_cfg = load_config(
        _author_site_config(root / "policy-plain", nc_root, "git@example.invalid:x/y.git", None)
    )
    plain_app = App(plain_cfg)
    check(
        "a site without authors adds no author exclusion at all",
        not plain_app.exclusion(plain_cfg.site).matches(("tmp", "bob", "x.md")),
    )

    # the bootstrap of a completely empty site folder: init never writes that folder, and the
    # author round still contributes to the commit tree from the author's own area
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment (the init checks)")
    else:
        init_root = root / "init"
        init_nc = init_root / "ncdata"
        init_site = init_nc / "alice" / "files" / "Sites" / "t"
        init_site.mkdir(parents=True, exist_ok=True)
        init_author = init_nc / "bob" / "files" / "Pages" / "posts"
        write(init_author, "2026-05-01-hello.md", "bob's post\n")
        init_cfg = load_config(_author_site_config(
            init_root, init_nc, "git@example.invalid:x/y.git",
            [_author_payload("bob", entries=[
                {"path": "2026-05-01-hello.md", "target": "tmp/bob/2026-05-01-hello.md"}])],
        ))
        write(wfile(init_cfg.site.work), "_config.yml", "title: t\n")
        res = App(init_cfg).do_init(init_cfg.site)
        check("init performs no reverse fill (git never writes Nextcloud)", res.get("bootstrap") is None, str(res)[:200])
        check("init emptied the commit tree of the file that is not in the source", not wfile(init_cfg.site.work, "_config.yml").exists(), wtree(init_cfg.site.work))
        check(
            "init mirrors the author's post into the preview tree at <author_root>/<uid>/<rel>",
            (init_cfg.site.preview / "tmp/bob/2026-05-01-hello.md").is_file(),
            str(sorted(p.relative_to(init_cfg.site.preview).as_posix() for p in init_cfg.site.preview.rglob("*") if p.is_file())),
        )
        check(
            "🔒 init writes no author file into the commit tree (only publish may)",
            not wfile(init_cfg.site.work, "tmp/bob/2026-05-01-hello.md").exists(),
            wtree(init_cfg.site.work),
        )
        check(
            "🔒 init wrote nothing into the author's own folder",
            sorted(path.name for path in init_author.iterdir()) == ["2026-05-01-hello.md"],
            str(sorted(path.name for path in init_author.iterdir())),
        )


def test_preview_separation(tmp: Path) -> None:
    print("\n[25] preview vs commit: preview holds every file, the commit tree only the on-site set")
    import threading

    from pages_bridge.server import App, build_server

    root = tmp / "split"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(site_src, "_config.yml", "title: t\n")
    write(site_src, "index.md", "# home\n")
    bob_src = nc_root / "bob" / "files" / "Pages"
    write(bob_src, "internal.md", "an internal page\n")
    write(bob_src, "public.md", "a public page\n")
    write(bob_src, "notes/pic.png", "PNG\n")

    internal = {"path": "internal.md", "target": "tmp/bob/internal.md"}
    public = {"path": "public.md", "target": "tmp/bob/public.md"}
    image = {"path": "notes/pic.png", "target": "tmp/bob/notes/pic.png"}

    def author(entries, onsite, removed=()) -> dict:
        block = {"uid": "bob", "path": "/Pages", "entries": list(entries), "removed": list(removed)}
        if onsite is not None:
            block["on_site"] = list(onsite)
        return block

    cfg = load_config(_author_site_config(
        root, nc_root, "git@example.invalid:x/y.git", [author([internal, public, image], [public])]
    ))
    site = cfg.site
    check(
        "the preview directory defaults to a sibling of the work copy",
        site.preview == site.work.parent / f"{site.work.name}.preview",
        str(site.preview),
    )
    explicit_root = tmp / "split-explicit"
    explicit_data = _base_config(explicit_root)
    explicit_data["site"]["work_path"] = str(explicit_root / "custom")
    explicit_cfg = load_config(_write_config(explicit_root, explicit_data))
    check(
        "with an explicit work_path the default preview is its sibling too",
        explicit_cfg.site.preview == explicit_root / "custom.preview",
        str(explicit_cfg.site.preview),
    )
    check("the two directories are different", site.preview != site.work, str(site.preview))
    check(
        "the build output is inside the preview tree, not the commit tree",
        is_within(site.output_dir, site.preview) and not is_within(site.output_dir, site.work),
        str(site.output_dir),
    )
    check(
        "the site reports both directories",
        site.to_dict()["work"] == str(site.work) and site.to_dict()["preview"] == str(site.preview),
        str(sorted(site.to_dict())),
    )
    policy = App(cfg).exclusion(site)
    check(
        "the build's dependency directory is excluded even when .gitignore forgets it",
        policy.matches(("vendor", "bundle", "gems", "demo.rb")),
        str(site.recipe.env.get("BUNDLE_PATH")),
    )
    check(
        "the author block reports the preview list and the on-site subset separately",
        len(site.authors[0].entries) == 3 and len(site.authors[0].commit_entries) == 1
        and site.authors[0].onsite_pushed,
        str(site.authors[0].to_dict()),
    )

    app = App(cfg)
    result = app.do_mirror(site)
    check("the two-tree mirror round is ok", result.ok, str(result.to_dict())[:400])
    check(
        "the preview tree holds every author file",
        (site.preview / "tmp/bob/internal.md").is_file()
        and (site.preview / "tmp/bob/public.md").is_file()
        and (site.preview / "tmp/bob/notes/pic.png").is_file(),
        str(sorted(p.name for p in (site.preview / "tmp/bob").rglob("*") if p.is_file())),
    )
    check(
        "🔒 a mirror writes no author file into the commit tree (publish alone may)",
        not wfile(site.work, "tmp/bob").exists(),
        wtree(site.work),
    )
    check(
        "both trees hold the site's own files",
        (site.preview / "index.md").is_file() and wfile(site.work, "index.md").is_file(),
        wtree(site.work),
    )
    check(
        "the commit tree is flat: the skeleton lands at its root",
        wfile(site.work, "_config.yml").is_file() and not (site.work / "source").exists(),
        wtree(site.work),
    )
    reported = result.to_dict()
    check(
        "the reported round carries the preview tree separately",
        isinstance(reported.get("preview"), dict)
        and [item["uid"] for item in reported["preview"].get("authors", [])] == ["bob"],
        str(reported.get("preview"))[:300],
    )
    check(
        "the author round of the mirror is the preview round (reported at the top level too)",
        reported["preview"]["authors"][0]["copied"] == 3
        and reported["authors"][0]["copied"] == 3,
        str(reported["preview"]["authors"]) + " / " + str(reported["authors"]),
    )

    # only an approval reaches the commit tree: candidate + publish writes exactly that target
    app.do_author_delta(site, {"op": "candidate", "uid": "bob", "area": "Pages", "target": "tmp/bob/public.md"})
    app.do_author_delta(site, {"op": "publish", "uid": "bob", "area": "Pages", "target": "tmp/bob/public.md"})
    check(
        "🔒 after publishing one page the commit tree holds that page and no other author file",
        wfile(site.work, "tmp/bob/public.md").is_file()
        and not wfile(site.work, "tmp/bob/internal.md").exists()
        and not wfile(site.work, "tmp/bob/notes/pic.png").exists(),
        wtree(site.work),
    )
    check(
        "the published page is byte-identical in the review tree (no pending candidate)",
        (site.onlinepreview / "tmp/bob/public.md").read_bytes() == wfile(site.work, "tmp/bob/public.md").read_bytes(),
        str(sorted(p.name for p in (site.onlinepreview / "tmp/bob").rglob("*") if p.is_file())),
    )

    # a page that is turned internal after it was published: the author mirror owns the preview tree
    # only, so neither the mirror nor a vanished on_site entry deletes the published copy
    cfg4 = load_config(_author_site_config(
        root / "turned3", nc_root, "git@example.invalid:x/y.git", [author([internal, public], [])]
    ))
    site4 = cfg4.site
    # the published copy lives in that root's commit tree already (as if a publish wrote it)
    write(wfile(site4.work), "tmp/bob/public.md", "a public page\n")
    write(site4.preview, "tmp/bob/public.md", "a public page\n")
    result = App(cfg4).do_mirror(site4)
    check(
        "🔒 a mirror never deletes published content because the on-site list changed",
        wfile(site4.work, "tmp/bob/public.md").is_file(),
        str(result.to_dict()["authors"])[:300],
    )
    check(
        "🔒 and it stays in the preview tree (an internal page must stay previewable)",
        (site4.preview / "tmp/bob/public.md").is_file(),
    )

    # a source-side deletion is the explicit `remove` op: it empties the preview tree only, the
    # published copy (online/ and the assembled commit tree) is only removed by a take-down
    cfg5 = load_config(_author_site_config(
        root / "deleted", nc_root, "git@example.invalid:x/y.git", [author([public], [public])],
    ))
    site5 = cfg5.site
    write(site5.online_dir, "tmp/bob/internal.md", "an internal page\n")
    write(wfile(site5.work), "tmp/bob/internal.md", "an internal page\n")
    write(site5.preview, "tmp/bob/internal.md", "an internal page\n")
    App(cfg5).do_author_delta(site5, {
        "op": "remove", "uid": "bob", "area": "Pages", "target": "tmp/bob/internal.md",
    })
    check(
        "the remove op leaves the preview tree",
        not (site5.preview / "tmp/bob/internal.md").exists(),
    )
    check(
        "🔒 a removed source file leaves the published copy in the commit tree",
        wfile(site5.work, "tmp/bob/internal.md").is_file(),
    )
    check(
        "🔒 and in online/ (only a take-down removes published content)",
        (site5.online_dir / "tmp/bob/internal.md").is_file(),
    )

    # ── the build runs in the preview tree, and the commit tree is never built ─────────────────
    build_root = tmp / "split-build"
    build_nc = build_root / "ncdata"
    build_src = build_nc / "alice" / "files" / "Sites" / "t" / "source"
    write(build_src, "_config.yml", "title: t\n")
    write(build_src, "index.md", "# home\n")
    build_author_src = build_nc / "bob" / "files" / "Pages"
    write(build_author_src, "internal.md", "internal build input\n")
    write(build_author_src, "public.md", "public build input\n")
    script = build_root / "fake-jekyll.py"
    script.parent.mkdir(parents=True, exist_ok=True)
    script.write_text(
        "import os, sys\n"
        "args = sys.argv[1:]\n"
        "dest = args[args.index('--destination') + 1] if '--destination' in args else '_site'\n"
        "os.makedirs(dest, exist_ok=True)\n"
        "here = os.getcwd()\n"
        "open(os.path.join(dest, 'where.txt'), 'w').write(here + '\\n')\n"
        "for root, _dirs, files in os.walk('.'):\n"
        "    for name in files:\n"
        "        if not name.endswith('.md'):\n"
        "            continue\n"
        "        rel = os.path.relpath(os.path.join(root, name), '.')\n"
        "        out = os.path.join(dest, rel[:-3] + '.html')\n"
        "        os.makedirs(os.path.dirname(out) or dest, exist_ok=True)\n"
        "        open(out, 'w').write(rel + '\\n')\n",
        encoding="utf-8",
    )
    build_cfg = load_config(_author_site_config(
        build_root, build_nc, "git@example.invalid:x/y.git",
        [author([internal, public], [public])],
        recipe={
            "preset": "jekyll", "output_dir": "_site",
            "command": [sys.executable, str(script)], "install_cmd": [],
        },
    ))
    build_site_cfg = build_cfg.site
    build_app = App(build_cfg)
    built = build_app.do_build(build_site_cfg)
    check("the preview build runs and succeeds", built["build"]["ok"] is True, str(built.get("build"))[:300])
    output = build_site_cfg.output_dir
    check(
        "the build ran in the preview tree",
        (output / "where.txt").is_file()
        and (output / "where.txt").read_text(encoding="utf-8").strip() == str(build_site_cfg.preview.resolve()),
        (output / "where.txt").read_text(encoding="utf-8").strip() if (output / "where.txt").is_file() else "no where.txt",
    )
    check(
        "the internal page was a build input (the preview shows every file)",
        (output / "tmp/bob/internal.html").is_file(),
        str(sorted(str(p.relative_to(output)) for p in output.rglob('*.html'))),
    )
    check(
        "🔒 the commit tree was not built",
        not wfile(build_site_cfg.work, "_site").exists(),
        wtree(build_site_cfg.work),
    )

    # ── ignored artifacts that exist only in the preview tree ──────────────────────────────────
    # `git ls-files --others --ignored` can only report files that exist in the commit tree, but
    # the build creates vendor/bundle and .jekyll-cache in the preview tree. Those must never be
    # mistaken for stale source (deleted) or counted against the delete guard.
    if shutil.which("git") is not None and _capture_available():
        ignore_root = tmp / "split-ignored"
        ignore_nc = ignore_root / "ncdata"
        ignore_src = ignore_nc / "alice" / "files" / "Sites" / "t" / "source"
        write(ignore_src, ".gitignore", ".jekyll-cache/\nvendor/\n_site/\n")
        write(ignore_src, "_config.yml", "title: t\n")
        ignore_cfg = load_config(_author_site_config(
            ignore_root, ignore_nc, "git@example.invalid:x/y.git", None
        ))
        ignore_site = ignore_cfg.site
        first = App(ignore_cfg).apply_source(ignore_site, {"build": False})
        check(
            "a site whose repository ignores its build artifacts assembles once",
            first.get("rebuilt") is True and (ignore_site.preview / "_config.yml").is_file(),
            str(first)[:300],
        )
        write(ignore_site.preview, "vendor/bundle/gems/demo.rb", "# gem\n")
        write(ignore_site.preview, ".jekyll-cache/jekyll/cache", "cache\n")
        write(ignore_site.source_dir, "vendor/bundle/gems/source.rb", "# source\n")
        second = App(ignore_cfg).apply_source(ignore_site, {"build": False})
        check(
            "Apply source clears the stale cache and keeps the installed dependency directory",
            second.get("rebuilt") is True
            and not (ignore_site.preview / ".jekyll-cache").exists()
            and (ignore_site.preview / "vendor/bundle/gems/demo.rb").is_file(),
            str(sorted(p.relative_to(ignore_site.preview).as_posix() for p in ignore_site.preview.rglob("*") if p.is_file())),
        )
        check(
            "an ignored directory in the source folder is never assembled into a tree",
            not wfile(ignore_site.work, "vendor").exists()
            and not (ignore_site.preview / "vendor/bundle/gems/source.rb").exists(),
            wtree(ignore_site.work),
        )
    else:
        print("  SKIP  no git in this environment (the ignored-target check)")

    # ── preview_dir validation: absolute, separate from the work copy ──────────────────────────
    def refused(mutate, expect: str) -> tuple[bool, str]:
        ok, msg = _load(tmp / "pv", mutate)
        return (not ok and expect in msg), msg

    good, msg = refused(lambda d: d["site"].update({"preview_dir": d["work_root"]}), "preview_dir")
    check("🔒 a preview_dir equal to the work copy is refused", good, msg)
    good, msg = refused(lambda d: d["site"].update({"preview_dir": str(Path(d["work_root"]) / "preview")}), "preview_dir")
    check("🔒 a preview_dir inside the work copy is refused", good, msg)
    good, msg = refused(lambda d: d["site"].update({"preview_dir": "relative/preview"}), "absolute")
    check("🔒 a relative preview_dir is refused", good, msg)
    ok, msg = _load(tmp / "pv-ok", lambda d: d["site"].update({"preview_dir": str(tmp / "elsewhere" / "preview")}))
    check("an absolute preview_dir outside the work copy is accepted", ok, msg)

    # the same rules over a real push: a 400 naming the field, and the running config untouched
    try:
        httpd, http_app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-preview-split", daemon=True)
    thread.start()
    try:
        status, body = _http_call(port, "GET", "/health")
        check(
            "GET /health reports the preview directory",
            status == 200 and body.get("preview_dir") == str(site.preview),
            f"{status} {str(body)[:200]}",
        )
        status, body = _http_call(port, "GET", "/admin/config", auth=cfg.shared_secret)
        check(
            "GET /admin/config reports the preview directory",
            status == 200 and (body.get("site") or {}).get("preview") == str(site.preview)
            and (body.get("site") or {}).get("preview_dir") == str(site.preview),
            f"{status} {str(body.get('site'))[:200]}",
        )
        pushed_site = {**site.to_dict(), "preview_dir": str(site.work)}
        status, body = _http_call(port, "POST", "/admin/config", body={
            "revision": 5, "global": {}, "site": pushed_site,
        }, auth=cfg.shared_secret)
        check(
            "🔒 a pushed preview_dir inside the work copy -> 400 naming preview_dir",
            status == 400 and body.get("error") == "BAD_REQUEST" and "preview_dir" in json.dumps(body),
            f"{status} {str(body)[:300]}",
        )
        status, body = _http_call(port, "GET", "/admin/config", auth=cfg.shared_secret)
        check(
            "the rejected push left the running preview directory alone",
            (body.get("site") or {}).get("preview") == str(site.preview),
            str((body.get("site") or {}).get("preview")),
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_author_root_channel(tmp: Path) -> None:
    """POST /site/author-root: shape only, an existing site directory needs confirm:true."""
    print("\n[23b] author content directory: shape + confirm-on-existing")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return

    from pages_bridge.server import App
    from pages_bridge.util import BadRequest, ConfirmRequired

    base = tmp / "authorroot"
    base.mkdir(parents=True, exist_ok=True)
    nc_root = base / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t"
    (site_src / "people").mkdir(parents=True, exist_ok=True)

    from pages_bridge.config import load_config

    cfg = load_config(_author_site_config(base, nc_root, "", None))
    app = App(cfg)
    check("the author root starts at tmp", app.cfg.site.author_root == "tmp", app.cfg.site.author_root)

    try:
        app.set_author_root({"author_root": "people"})
        check("an existing directory without confirm -> CONFIRM_REQUIRED", False, "no error")
    except ConfirmRequired as exc:
        payload = exc.to_dict()
        check(
            "an existing directory without confirm -> 409 CONFIRM_REQUIRED with exists",
            payload.get("error") == "CONFIRM_REQUIRED" and payload.get("exists") is True,
            str(payload)[:200],
        )
    check("the refused change did not move the root", app.cfg.site.author_root == "tmp", app.cfg.site.author_root)

    # there is no target-set file any more: the move only drops the stored author blocks (the app
    # re-sends them under the new root, and Apply source lays them over the skeleton)
    result = app.set_author_root({"author_root": "people", "confirm": True})
    check(
        "with confirm the existing directory is accepted",
        result.get("ok") is True and result.get("changed") is True and app.cfg.site.author_root == "people",
        str(result)[:200],
    )
    check("moving the root drops the stored authors", app.cfg.site.authors == (), str(app.cfg.site.authors))
    check(
        "no target-set file is written in the state_dir",
        not (app.cfg.state_dir / "author_external.json").exists()
        and not (app.cfg.state_dir / "onlinepreview_candidates.json").exists(),
        str(app.cfg.state_dir),
    )
    result = app.set_author_root({"author_root": "people"})
    check("the same value answers changed:false", result.get("changed") is False, str(result)[:200])

    result = app.set_author_root({"author_root": "fresh"})
    check(
        "a nonexistent directory is accepted without confirm",
        result.get("changed") is True and result.get("exists") is False and app.cfg.site.author_root == "fresh",
        str(result)[:200],
    )
    for value in ("a/b", "..", "", "a b"):
        try:
            app.set_author_root({"author_root": value})
            check(f"the invalid value {value!r} is a 400 naming author_root", False, "no error")
        except BadRequest as exc:
            check(
                f"the invalid value {value!r} is a 400 naming author_root",
                "author_root" in exc.message,
                exc.message,
            )
    check("the invalid values changed nothing", app.cfg.site.author_root == "fresh", app.cfg.site.author_root)

    # ── the rebuild verdict travels with the answer: a refused rebuild is not a silent success ──
    root2 = tmp / "authorroot-repair"
    nc2 = root2 / "ncdata"
    cfg2 = load_config(_author_site_config(root2, nc2, "", None))
    site2 = cfg2.site
    app2 = App(cfg2)
    (nc2 / "alice" / "files" / "Sites" / "t" / "people").mkdir(parents=True, exist_ok=True)
    # a published file the commit tree does not hold = a stale tree; the content moves to the new
    # prefix, and with source/ empty the post-move rebuild is refused by the source guard - the
    # verdict must be reported instead of faked
    write(site2.online_dir, "tmp/bob/hello.md", "hello\n")
    check("the move fixture starts with a stale commit tree", app2.work_drift(site2) is not None)
    moved = app2.set_author_root({"author_root": "people", "confirm": True})
    repair = moved.get("work_rebuild") or {}
    check(
        "a refused rebuild is passed through instead of reported as a repair",
        moved.get("ok") is True
        and moved.get("moved") == {"online": 1, "pending": 0}
        and repair.get("ok") is False
        and repair.get("rebuilt") is False
        and repair.get("error") == "SOURCE_EMPTY",
        str(moved)[:300],
    )
    check(
        "🔒 the refused rebuild wrote no file into the commit tree",
        not wfile(site2.work, "people/bob/hello.md").exists()
        and not wfile(site2.work, "tmp/bob/hello.md").exists(),
        str(wtree(site2.work)) if site2.work.is_dir() else "missing",
    )


def test_author_http(tmp: Path) -> None:
    print("\n[23] author sources over HTTP (Apply source, status, push validation)")
    import threading

    from pages_bridge.server import build_server

    root = tmp / "ahttp"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    site_src.mkdir(parents=True, exist_ok=True)
    write(site_src, "_config.yml", "title: t\n")
    write(site_src, "_posts/bob/from-owner.md", "the owner has a folder of that name too\n")
    bob_src = nc_root / "bob" / "files" / "Pages" / "posts"
    write(bob_src, "2026-05-01-hello.md", "hello from bob\n")
    carol_src = nc_root / "carol" / "files" / "Pages" / "posts"
    write(carol_src, "2026-05-02-carol.md", "carol was here\n")

    bob_author = {
        "uid": "bob", "path": "/Pages/posts",
        "entries": [{"path": "2026-05-01-hello.md", "target": "tmp/bob/2026-05-01-hello.md"}],
        "removed": [],
    }
    carol_author = {
        "uid": "carol", "path": "/Pages/posts",
        "entries": [{"path": "2026-05-02-carol.md", "target": "tmp/carol/2026-05-02-carol.md"}],
        "removed": [],
    }

    def site_obj(authors) -> dict:
        return {
            "nc_user": "alice", "nc_path": "/Sites/t", "repo_url": "git@example.invalid:x/y.git",
            "branch": "main", "recipe": {"preset": "jekyll", "output_dir": "."},
            "authors": authors,
        }

    cfg = load_config(_author_site_config(root, nc_root, "git@example.invalid:x/y.git", [bob_author, carol_author]))
    work = cfg.site.work
    preview = cfg.site.preview
    secret = cfg.shared_secret
    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return

    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-author-http", daemon=True)
    thread.start()

    def call(method: str, path: str, body: dict | None = None, auth: str | None = secret):
        return _http_call(port, method, path, body, auth)

    try:
        status, body = call("POST", "/site/mirror", body={
            "authors": [
                {"uid": "bob", "area": "Pages/posts"},
                {"uid": "carol", "area": "Pages/posts"},
            ],
            "build": False,
        })
        check("POST /site/mirror (Apply source) -> 200", status == 200 and body.get("rebuilt") is True, f"{status} {str(body)[:300]}")
        check(
            "Apply source reports one file count per tree",
            sorted(body.get("files") or {}) == ["onlinepreview", "preview", "work"],
            str(body.get("files")),
        )
        check(
            "bob's source file lands in the preview tree at <author_root>/<uid>/<rel>",
            (preview / "tmp/bob/2026-05-01-hello.md").read_text(encoding="utf-8") == "hello from bob\n",
            str(sorted(p.relative_to(preview).as_posix() for p in preview.rglob("*") if p.is_file())),
        )
        check(
            "carol's source file lands in her own folder",
            (preview / "tmp/carol/2026-05-02-carol.md").read_text(encoding="utf-8") == "carol was here\n",
        )
        check(
            "🔒 Apply source writes no author file into the commit tree (only publish may)",
            not wfile(work, "tmp/bob").exists() and not wfile(work, "tmp/carol").exists(),
            wtree(work) if work.is_dir() else "missing",
        )
        check(
            "the site owner's own _posts file is skeleton content and reaches both trees",
            (preview / "_posts/bob/from-owner.md").is_file() and wfile(work, "_posts/bob/from-owner.md").is_file(),
            wtree(work),
        )
        check(
            "the review tree holds the same skeleton and online layers",
            (cfg.site.onlinepreview / "_config.yml").is_file()
            and not (cfg.site.onlinepreview / "tmp/bob/2026-05-01-hello.md").exists(),
            str(sorted(p.relative_to(cfg.site.onlinepreview).as_posix() for p in cfg.site.onlinepreview.rglob("*") if p.is_file())),
        )

        # a second Apply source is idempotent and leaves the same trees
        status, body = call("POST", "/site/mirror", body={"build": False})
        check("a second Apply source (authors from site.authors) -> 200", status == 200, f"{status} {str(body)[:200]}")
        check(
            "the site.authors list is enough when the request omits authors",
            (preview / "tmp/bob/2026-05-01-hello.md").is_file()
            and (preview / "tmp/carol/2026-05-02-carol.md").is_file(),
        )

        # a dry run counts the layers and changes nothing
        before = (preview / "_config.yml").read_bytes()
        (preview / "local-only.md").write_text("keep me\n", encoding="utf-8")
        status, body = call("POST", "/site/mirror", body={"dry_run": True})
        check(
            "a dry run reports rebuilt:false but the layer counts",
            status == 200 and body.get("rebuilt") is False and body.get("dry_run") is True
            and all(isinstance(v, int) for v in (body.get("files") or {}).values()),
            str(body)[:300],
        )
        check("a dry run deleted nothing", (preview / "local-only.md").is_file() and (preview / "_config.yml").read_bytes() == before)
        status, body = call("POST", "/site/mirror", body={"build": False})
        check("a real Apply source rebuilds the tree (the stray file is gone)", status == 200 and not (preview / "local-only.md").exists())

        status, body = call("GET", "/site/status")
        check("GET /site/status carries the three Nextcloud directories", status == 200
              and body.get("source_dir") == str(cfg.site.source_dir)
              and body.get("online_dir") == str(cfg.site.online_dir)
              and body.get("pending_dir") == str(cfg.site.pending_dir),
              str({k: body.get(k) for k in ("source_dir", "online_dir", "pending_dir")}))
        authors = body.get("authors") or []
        check("GET /site/status carries the configured authors", [item["uid"] for item in authors] == ["bob", "carol"], str(authors)[:200])
        check("the status lists no pending candidates before a candidate", body.get("pending") == [], str(body.get("pending")))
        check(
            "the status reports the work as consistent",
            body.get("work_drift") is None,
            str(body.get("work_drift")),
        )

        # ── the config push keeps validating the author payload exactly as before ────────────────
        bad_cases = [
            ("a target outside the allowed areas -> 400 naming target",
             [{**bob_author, "entries": [{"path": "x.md", "target": "index.html"}]}], "target"),
            ("a duplicate target -> 400 naming the duplicate",
             [{**bob_author, "entries": [{"path": "a.md", "target": "tmp/bob/x.md"},
                                         {"path": "b.md", "target": "tmp/bob/x.md"}]}], "duplicate"),
            ("a relative author path -> 400 naming path", [{**bob_author, "path": "Pages/posts"}], "path"),
            ("another author's target -> 400",
             [{**bob_author, "entries": [{"path": "x.md", "target": "tmp/carol/x.md"}]}], "target"),
            ("a removal outside the author's area -> 400", [{**bob_author, "removed": ["_config.yml"]}], "target"),
            ("a target that escapes the site -> 400",
             [{**bob_author, "entries": [{"path": "x", "target": "../escape"}]}], "target"),
        ]
        for index, (name, bad_authors, field) in enumerate(bad_cases):
            status, body = call("POST", "/admin/config", body={
                "revision": 70 + index,
                "global": {"mirror_delete_guard_min": 2},
                "site": site_obj(bad_authors),
            })
            check(name, status == 400 and body.get("error") == "BAD_REQUEST" and field in json.dumps(body), f"{status} {str(body)[:200]}")
        status, body = call("GET", "/admin/config")
        check(
            "a rejected push left the running config alone",
            [item["uid"] for item in (body.get("site") or {}).get("authors", [])] == ["bob", "carol"],
            str(body.get("site"))[:200],
        )

        # ── the configurable author directory: its own channel, shape-only ─────────────────────
        # the directory check looks at the site folder (source/ holds the skeleton, not the root)
        (nc_root / "alice" / "files" / "Sites" / "t" / "people").mkdir(parents=True, exist_ok=True)
        status, body = call("POST", "/site/author-root", body={"author_root": "people"})
        check(
            "an existing site directory without confirm -> 409 CONFIRM_REQUIRED",
            status == 409 and body.get("error") == "CONFIRM_REQUIRED",
            f"{status} {str(body)[:200]}",
        )
        status, body = call("POST", "/site/author-root", body={"author_root": "people", "confirm": True})
        check(
            "with confirm the move is accepted",
            status == 200 and body.get("author_root") == "people" and body.get("changed") is True,
            f"{status} {str(body)[:200]}",
        )
        status, body = call("POST", "/site/author-root", body={"author_root": "people"})
        check("a same-value move is idempotent", status == 200 and body.get("changed") is False, f"{status} {str(body)[:200]}")
        status, body = call("GET", "/admin/config")
        check(
            "the move dropped the targets of the old root",
            (body.get("site") or {}).get("author_root") == "people"
            and (body.get("site") or {}).get("authors") == [],
            str(body.get("site"))[:200],
        )
        status, body = call("POST", "/admin/config", body={
            "revision": 90,
            "global": {"mirror_delete_guard_min": 2},
            "site": {**site_obj([]), "author_root": "tmp"},
        })
        check(
            "a config push carrying another author_root is accepted as a no-op",
            status == 200 and body.get("ok") is True,
            f"{status} {str(body)[:200]}",
        )
        status, body = call("GET", "/admin/config")
        check(
            "that no-op push did not move the stored root",
            (body.get("site") or {}).get("author_root") == "people",
            str(body.get("site"))[:200],
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)

    # a config without authors is the single-source bridge: the skeleton layer is the whole site
    plain_root = root / "plain"
    plain_cfg = load_config(_author_site_config(plain_root, nc_root, "git@example.invalid:x/y.git", None))
    check("a config with no authors key has no authors", plain_cfg.site.authors == (), str(plain_cfg.site.authors))
    try:
        httpd2, _app2 = build_server(plain_cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  the second bridge cannot listen: {exc}")
        return
    port2 = httpd2.server_address[1]
    thread2 = threading.Thread(target=httpd2.serve_forever, name="test-author-http-plain", daemon=True)
    thread2.start()

    def call2(method: str, path: str, body: dict | None = None, auth: str | None = secret):
        return _http_call(port2, method, path, body, auth)

    try:
        status, body = call2("POST", "/site/mirror", body={"build": False})
        check(
            "the single-source Apply source has no author block",
            status == 200 and "authors" not in body,
            f"{status} {str(sorted(body))[:200]}",
        )
        check("the single-source work copy holds the skeleton", wfile(plain_cfg.site.work, "_config.yml").is_file(), wtree(plain_cfg.site.work))
        check(
            "without authors the owner's _posts/<uid> folder is ordinary skeleton content",
            wfile(plain_cfg.site.work, "_posts/bob/from-owner.md").is_file(),
            wtree(plain_cfg.site.work),
        )
        status, body = call2("GET", "/site/status")
        check(
            "the single-source status has no authors key",
            status == 200 and "authors" not in body,
            f"{status} {str(sorted(body))[:200]}",
        )
        check(
            "the single-source status still carries its drift",
            isinstance(body.get("drift"), dict) and body["drift"].get("in_sync") is True,
            str(body.get("drift")),
        )
    finally:
        httpd2.shutdown()
        httpd2.server_close()
        thread2.join(timeout=5)


def test_site_paths(tmp: Path) -> None:
    """``GET /site/paths``: the two content directories as the app has to read them.

    The bridge writes ``online/`` and ``pending/`` itself - a temporary file and a rename, never the
    Nextcloud Files API - so on an instance with the default ``filesystem_check_changes=0`` the
    filecache does not know a file until something scans the folder. An app that answered "is it
    live / is it waiting" from a folder listing would therefore miss the page that was just
    published and the candidate that was just frozen. This route is the answer it reads instead:
    two directory walks on the bridge's own host, the target lists plus their mtimes, keyed exactly
    like the manifest keys a target. The same four keys travel in ``/site/status``.
    """
    print("\n[23c] the two content directories over HTTP (/site/paths)")
    import threading

    from pages_bridge.server import build_server

    root = tmp / "paths"
    nc_root = root / "ncdata"
    cfg = load_config(_author_site_config(root, nc_root, "", None))
    site = cfg.site
    secret = cfg.shared_secret
    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-site-paths", daemon=True)
    thread.start()

    def call(method: str, path: str, body: dict | None = None, auth: str | None = secret):
        return _http_call(port, method, path, body, auth)

    try:
        status, body = call("GET", "/site/paths", auth=None)
        check("GET /site/paths without the secret -> 401", status == 401, f"{status} {body}")

        # a site that was never published and has nothing under review: two empty layers
        status, body = call("GET", "/site/paths")
        check(
            "an empty site answers two empty layers, not an error",
            status == 200 and body.get("ok") is True
            and body.get("online") == [] and body.get("pending") == []
            and body.get("online_at") == {} and body.get("pending_at") == {},
            f"{status} {body}",
        )

        # what the bridge's own ops leave behind, written straight into the two folders
        online = {
            "index.md": 1_700_000_000,
            "tmp/bob/2026-05-01-hello.md": 1_700_000_111,
            "tmp/bob/数据.md": 1_700_000_222,
        }
        pending = {
            "tmp/bob/2026-05-02-draft.md": 1_700_000_333,
            "tmp/carol/2026-05-03-carol.md": 1_700_000_444,
        }
        for rel, at in online.items():
            os.utime(write(site.online_dir, rel, f"# {rel}\n"), (at, at))
        for rel, at in pending.items():
            os.utime(write(site.pending_dir, rel, f"# {rel}\n"), (at, at))

        def folder_state() -> list[tuple[str, int, int]]:
            """Every file of the two content directories: path, size, mtime."""
            return [
                (path.as_posix(), path.stat().st_size, int(path.stat().st_mtime))
                for folder in (site.online_dir, site.pending_dir)
                for path in sorted(folder.rglob("*"))
                if path.is_file()
            ]

        before = folder_state()
        status, body = call("GET", "/site/paths")
        check("GET /site/paths -> 200", status == 200, f"{status} {str(body)[:200]}")
        check("the route changed nothing on disk (read-only)", folder_state() == before)
        check("online is the sorted target list read off online/", body.get("online") == sorted(online), str(body.get("online")))
        check("pending is the sorted target list read off pending/", body.get("pending") == sorted(pending), str(body.get("pending")))
        check(
            "online_at/pending_at carry each file's own mtime",
            body.get("online_at") == dict(sorted(online.items()))
            and body.get("pending_at") == dict(sorted(pending.items())),
            f"{body.get('online_at')} {body.get('pending_at')}",
        )
        check(
            "the mtime maps have exactly the targets of the two lists",
            sorted(body.get("online_at") or {}) == body.get("online")
            and sorted(body.get("pending_at") or {}) == body.get("pending"),
        )
        check(
            "the targets are site-relative POSIX paths with no leading slash",
            all(
                not target.startswith("/") and "\\" not in target
                for target in [*(body.get("online") or []), *(body.get("pending") or [])]
            ),
            str(body.get("online")),
        )

        # the status panel carries the same four keys, and `pending` keeps its old meaning
        status, status_body = call("GET", "/site/status")
        check(
            "GET /site/status carries the same online/pending lists and mtime maps",
            status == 200
            and status_body.get("online") == body.get("online")
            and status_body.get("pending") == body.get("pending")
            and status_body.get("online_at") == body.get("online_at")
            and status_body.get("pending_at") == body.get("pending_at"),
            str({key: status_body.get(key) for key in ("online", "pending")}),
        )

        # the route takes no site lock: a page view must not wait for a running build
        locked = {"entered": False}
        original_lock = app._site_lock

        @contextlib.contextmanager
        def lock_spy():
            locked["entered"] = True
            with original_lock():
                yield

        app._site_lock = lock_spy  # type: ignore[method-assign]
        try:
            status, _body = call("GET", "/site/paths")
        finally:
            app._site_lock = original_lock  # type: ignore[method-assign]
        check("GET /site/paths answers without taking the site lock", status == 200 and locked["entered"] is False, str(locked))

        # read-only and uncached: a candidate written afterwards is in the next answer
        late = write(site.pending_dir, "tmp/dave/2026-05-04-late.md", "# late\n")
        os.utime(late, (1_700_000_555, 1_700_000_555))
        status, body = call("GET", "/site/paths")
        check(
            "a candidate written after the first read is in the next answer (no cache)",
            "tmp/dave/2026-05-04-late.md" in (body.get("pending") or [])
            and (body.get("pending_at") or {}).get("tmp/dave/2026-05-04-late.md") == 1_700_000_555,
            str(body.get("pending")),
        )
        (site.online_dir / "index.md").unlink()
        status, body = call("GET", "/site/paths")
        check("a taken-down target leaves the online list", "index.md" not in (body.get("online") or []), str(body.get("online")))
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_author_publish(tmp: Path) -> None:
    print("\n[24] publish stages only the paths the app names")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    import threading

    from pages_bridge import gitops
    from pages_bridge.server import build_server

    root = tmp / "apub"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    site_src.mkdir(parents=True, exist_ok=True)
    write(site_src, "_config.yml", "title: t\n")
    bob_src = nc_root / "bob" / "files" / "Pages" / "posts"
    write(bob_src, "2026-05-01-hello.md", "hello from bob\n")
    bob_author = {
        "uid": "bob", "path": "/Pages/posts",
        "entries": [{"path": "2026-05-01-hello.md", "target": "tmp/bob/2026-05-01-hello.md"}],
        "removed": [],
    }

    remote = _make_bare_remote(root / "seed", {"_config.yml": "title: t\n"})
    cfg = load_config(_author_site_config(root, nc_root, str(remote), [bob_author]))
    work = cfg.site.work
    preview = cfg.site.preview
    secret = cfg.shared_secret
    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-author-publish", daemon=True)
    thread.start()

    def call(method: str, path: str, body: dict | None = None, auth: str | None = secret):
        return _http_call(port, method, path, body, auth)

    try:
        status, body = call("POST", "/site/init", body={})
        check(
            "init prepared the repository without cloning",
            status == 200 and (body.get("git") or {}).get("action") == "init",
            f"{status} {str(body)[:200]}",
        )
        check(
            "init mirrored the author's post into the preview tree",
            (preview / "tmp/bob/2026-05-01-hello.md").is_file(),
        )
        check(
            "🔒 init wrote no author file into the commit tree (only publish may)",
            not wfile(work, "tmp/bob/2026-05-01-hello.md").exists(),
            wtree(work),
        )
        check(
            "init reports the author block",
            [item["uid"] for item in body.get("authors", [])] == ["bob"],
            str(body.get("authors"))[:200],
        )
        # a CI sandbox may have no git identity at all; the commit needs one
        for key, value in (("user.email", "t@example.invalid"), ("user.name", "t")):
            subprocess.run(
                ["git", "config", key, value], cwd=str(work),
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False,
            )

        # an approval is the only way into the commit tree: candidate freezes, publish writes
        for op in ("candidate", "publish"):
            status, body = call("POST", "/site/author-delta", body={
                "op": op, "uid": "bob", "area": "Pages/posts", "target": "tmp/bob/2026-05-01-hello.md",
            })
            check(f"the {op} delta of bob's post -> 200", status == 200, f"{status} {str(body)[:200]}")
        check(
            "the published post is in the commit tree (flat, at its site-relative path)",
            wfile(work, "tmp/bob/2026-05-01-hello.md").read_text(encoding="utf-8") == "hello from bob\n",
            wfile(work, "tmp/bob/2026-05-01-hello.md").read_text(encoding="utf-8")
            if wfile(work, "tmp/bob/2026-05-01-hello.md").is_file() else "missing",
        )

        # a site file the app does not name in this publish
        write(site_src, "owner-note.md", "the owner's own change\n")
        status, body = call("POST", "/site/mirror", body={"build": False})
        check("the site owner's new file reached the work copy", wfile(work, "owner-note.md").is_file(), wtree(work))
        check(
            "🔒 the mirror kept the published author file in the commit tree",
            wfile(work, "tmp/bob/2026-05-01-hello.md").is_file(),
            wtree(work),
        )

        status, body = call("POST", "/site/publish", body={"message": "bob only", "stage_paths": ["tmp/bob"]})
        check("publish with stage_paths -> 200", status == 200 and bool(body.get("commit")), f"{status} {str(body)[:200]}")
        check(
            "the response echoes the staged paths as site-relative commit-tree paths",
            body.get("stage_paths") == ["tmp/bob"],
            str(body.get("stage_paths")),
        )
        committed = gitops.run(work, ["show", "--name-only", "--pretty=format:", "HEAD"], check=False).stdout.split()
        check("only the named path is in the commit", committed == ["tmp/bob/2026-05-01-hello.md"], str(committed))
        check(
            "the unnamed site file is still uncommitted",
            wfile(work, "owner-note.md").is_file() and "owner-note.md" in json.dumps(body.get("git")),
            str(body.get("git"))[:200],
        )

        status, body = call("POST", "/site/publish", body={"message": "the rest"})
        check("publish without stage_paths still stages everything", status == 200 and bool(body.get("commit")), f"{status} {str(body)[:200]}")
        check("that response has no stage_paths key", "stage_paths" not in body, str(sorted(body)))
        committed = gitops.run(work, ["show", "--name-only", "--pretty=format:", "HEAD"], check=False).stdout.split()
        check("the site file is committed by that publish", "owner-note.md" in committed, str(committed))

        # the commit tree is never built: publish is stage -> commit -> push, nothing else
        from pages_bridge import server as server_module

        def _forbidden_build(*_args, **_kwargs):
            raise AssertionError("publish ran a build")

        saved_run_build = server_module.run_build
        server_module.run_build = _forbidden_build
        try:
            status, body = call("POST", "/site/publish", body={"message": "nothing new"})
        finally:
            server_module.run_build = saved_run_build
        check("🔒 publish does not run a build", status == 200, f"{status} {str(body)[:200]}")
        check(
            "🔒 no build output appears in the commit tree",
            not wfile(work, "_site").exists(),
            wtree(work),
        )

        status, body = call("POST", "/site/publish", body={"message": "x", "stage_paths": ["../escape"]})
        check("a stage_path outside the work copy -> 400", status == 400 and body.get("error") == "BAD_REQUEST", f"{status} {body}")
        status, body = call("POST", "/site/publish", body={"message": "x", "stage_paths": "not-a-list"})
        check("a stage_paths that is not an array -> 400", status == 400 and body.get("error") == "BAD_REQUEST", f"{status} {body}")
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_author_publish_take_down(tmp: Path) -> None:
    """The event-driven steady state: saves stay in the preview, approvals write the commit tree.

    The retired ``external`` delta used to be the **second** writer of the commit tree, and its
    removal half never validated the path. It is gone: ``candidate`` + ``publish`` is the one
    approval path and ``take-down`` the one removal, and the old op is now refused outright.
    """
    print("\n[24b] author deltas: saves stay in the preview, candidate+publish enters the commit tree, take-down leaves it")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    import threading

    from pages_bridge import gitops
    from pages_bridge.server import build_server

    root = tmp / "aext"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    site_src.mkdir(parents=True, exist_ok=True)
    write(site_src, "_config.yml", "title: t\n")
    bob_src = nc_root / "bob" / "files" / "Pages"
    write(bob_src, "2026-05-01-hello.md", "hello from bob\n")
    target = "tmp/bob/2026-05-01-hello.md"

    # no pushed authors block: exactly the shape the current app sends (content is event-driven)
    remote = _make_bare_remote(root / "seed", {"_config.yml": "title: t\n"})
    cfg = load_config(_author_site_config(root, nc_root, str(remote), None))
    work = cfg.site.work
    preview = cfg.site.preview
    secret = cfg.shared_secret
    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-author-external", daemon=True)
    thread.start()

    def call(method: str, path: str, body: dict | None = None, auth: str | None = secret):
        return _http_call(port, method, path, body, auth)

    try:
        status, body = call("POST", "/site/init", body={})
        check(
            "init prepared the repository",
            status == 200 and (body.get("git") or {}).get("action") == "init",
            f"{status} {str(body)[:200]}",
        )
        for key, value in (("user.email", "t@example.invalid"), ("user.name", "t")):
            subprocess.run(
                ["git", "config", key, value], cwd=str(work),
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False,
            )

        # 1. the save delta: the preview tree only - an internal page stays out of the commit tree
        status, body = call("POST", "/site/author-delta", body={"op": "add", "uid": "bob", "area": "Pages", "target": target})
        check("the save delta reaches the preview tree", status == 200 and (preview / target).is_file(), f"{status} {str(body)[:200]}")
        check("an internal page stays out of the commit tree", not wfile(work, target).exists(), str(target))

        # 2. the approval: `candidate` freezes the bytes under review, `publish` copies them into
        #    the commit tree - the only writer of the commit tree in the whole protocol
        for op in ("candidate", "publish"):
            status, body = call("POST", "/site/author-delta", body={"op": op, "uid": "bob", "area": "Pages", "target": target})
            check(f"the {op} delta answers 200", status == 200, f"{status} {str(body)[:200]}")
        check("the approval puts the file into the commit tree", wfile(work, target).is_file(), str(target))
        check(
            "the approval moved the candidate out of pending/ and into online/",
            not (cfg.site.pending_dir / target).exists()
            and (cfg.site.online_dir / target).read_text(encoding="utf-8") == "hello from bob\n",
            str(sorted(p.relative_to(cfg.site.pending_dir).as_posix() for p in cfg.site.pending_dir.rglob("*") if p.is_file())),
        )

        # 3. a publish that stages the approved path really commits it (nothing_to_commit would
        #    silently leave the page off the site)
        status, body = call("POST", "/site/publish", body={"message": "bob approved", "stage_paths": ["tmp/bob"]})
        check("publish commits the approved author file", status == 200 and bool(body.get("commit")), f"{status} {str(body)[:200]}")
        committed = gitops.run(work, ["show", "--name-only", "--pretty=format:", "HEAD"], check=False).stdout.split()
        check(
            "the commit carried the approved target at its flat site-relative path",
            committed == [target],
            str(committed),
        )

        # 4. a save is not an approval: the source file changed, but the commit tree keeps the bytes
        #    the app approved. Copying a save of an approved page straight into the commit tree (the
        #    old semantics) published unreviewed edits on the next push.
        write(bob_src, "2026-05-01-hello.md", "changed\n")
        status, body = call("POST", "/site/author-delta", body={"op": "add", "uid": "bob", "area": "Pages", "target": target})
        check(
            "a save of an approved page updates the preview tree",
            status == 200 and (preview / target).read_text(encoding="utf-8") == "changed\n",
            f"{status} {str(body)[:200]}",
        )
        check(
            "🔒 a save never rewrites the approved content in the commit tree",
            wfile(work, target).read_text(encoding="utf-8") == "hello from bob\n",
            wfile(work, target).read_text(encoding="utf-8") if wfile(work, target).is_file() else "missing from the commit tree",
        )

        # 5. the boot repair back-fills a missing commit file, it never overwrites an existing one:
        #    the commit tree only holds approved content, and an existing file *is* that content
        status, body = call("POST", "/site/author-delta", body={
            "op": "overwrite", "authors": [
                {"uid": "bob", "area": "Pages", "entries": [target], "on_site": [target]},
            ],
        })
        check("the overwrite answers 200", status == 200, f"{status} {str(body)[:200]}")
        check(
            "🔒 overwrite keeps the approved bytes in the commit tree",
            wfile(work, target).read_text(encoding="utf-8") == "hello from bob\n",
            wfile(work, target).read_text(encoding="utf-8") if wfile(work, target).is_file() else "missing from the commit tree",
        )
        check(
            "overwrite still gives the preview tree the current source bytes",
            (preview / target).read_text(encoding="utf-8") == "changed\n",
            (preview / target).read_text(encoding="utf-8") if (preview / target).is_file() else "missing from the preview tree",
        )

        # 6. the approval is the one event that moves the new bytes into the commit tree
        for op in ("candidate", "publish"):
            status, body = call("POST", "/site/author-delta", body={"op": op, "uid": "bob", "area": "Pages", "target": target})
            check(f"the re-approval {op} answers 200", status == 200, f"{status} {str(body)[:200]}")
        check(
            "an approval updates the commit tree to the current source",
            wfile(work, target).read_text(encoding="utf-8") == "changed\n",
            wfile(work, target).read_text(encoding="utf-8") if wfile(work, target).is_file() else "missing from the commit tree",
        )

        # 7. a mirror re-syncs the preview folder; in the commit tree it may only prune, never write
        write(bob_src, "2026-05-01-hello.md", "changed again\n")
        status, body = call("POST", "/site/author-delta", body={"op": "mirror", "build": False})
        check(
            "Apply source rebuilds the preview tree from every author folder",
            status == 200 and (preview / target).read_text(encoding="utf-8") == "changed again\n",
            f"{status} {str(body)[:200]}",
        )
        check(
            "🔒 Apply source writes the published (online/) bytes, never the live source draft",
            wfile(work, target).read_text(encoding="utf-8") == "changed\n",
            wfile(work, target).read_text(encoding="utf-8") if wfile(work, target).is_file() else "missing from the commit tree",
        )

        # 8. the take-down: the reviewer's own removal, the only op that empties the commit tree
        status, body = call("POST", "/site/author-delta", body={"op": "take-down", "uid": "bob", "area": "Pages", "target": target})
        check("the take-down removes the file from the commit tree", status == 200 and not wfile(work, target).exists(), f"{status} {str(body)[:200]}")
        check("the preview tree keeps an internal page", (preview / target).is_file(), str(target))
        check(
            "the take-down removed the target from online/ (the published record)",
            not (cfg.site.online_dir / target).exists(),
            str(cfg.site.online_dir / target),
        )

        # 9. the removal travels with the next publish (the file is gone, git stages the deletion)
        status, body = call("POST", "/site/publish", body={"message": "bob offline", "stage_paths": ["tmp/bob"]})
        check("publish commits the removal", status == 200 and bool(body.get("commit")), f"{status} {str(body)[:200]}")
        committed = gitops.run(work, ["show", "--name-only", "--pretty=format:", "HEAD"], check=False).stdout.split()
        check("the removal was the commit's content", committed == [target], str(committed))

        # 10. no tolerance without an object under review: an approval has to name a frozen
        #     candidate, and a candidate has to name a source file that is there
        status, body = call("POST", "/site/author-delta", body={"op": "publish", "uid": "bob", "area": "Pages", "target": "tmp/bob/gone.md"})
        check("a publish of a target with no candidate -> 400", status == 400 and body.get("error") == "BAD_REQUEST", f"{status} {str(body)[:200]}")
        status, body = call("POST", "/site/author-delta", body={"op": "candidate", "uid": "bob", "area": "Pages", "target": "tmp/bob/gone.md"})
        check("a candidate of a missing source -> 400", status == 400 and body.get("error") == "BAD_REQUEST", f"{status} {str(body)[:200]}")
        check("neither wrote the commit tree", not wfile(work, "tmp/bob/gone.md").exists())

        # 11. shape checks: the retired op is refused outright, and a target outside the author
        #     directory, a legacy prefix or a leading slash stays a refused shape
        status, body = call("POST", "/site/author-delta", body={"op": "external", "uid": "bob", "target": target, "on": True})
        check("the retired external op -> 400", status == 400 and body.get("error") == "BAD_REQUEST", f"{status} {str(body)[:200]}")
        status, body = call("POST", "/site/author-delta", body={"op": "candidate", "uid": "bob", "area": "Pages", "target": "index.html"})
        check("a candidate outside the author directory -> 400", status == 400, f"{status} {str(body)[:200]}")
        status, body = call("POST", "/site/author-delta", body={"op": "candidate", "uid": "bob", "area": "Pages", "target": "_posts/bob/legacy.md"})
        check("a candidate of a legacy prefix -> 400", status == 400, f"{status} {str(body)[:200]}")
        # the protocol's target is the site-relative form: the app stores `/authors-src/uid/file.md`
        # and trims the slash at the boundary, so a leading slash must stay a refused shape
        status, body = call("POST", "/site/author-delta", body={"op": "candidate", "uid": "bob", "area": "Pages", "target": "/tmp/bob/2026-05-01-hello.md"})
        check("a leading-slash target -> 400 (the protocol wants the site-relative form)", status == 400 and body.get("error") == "BAD_REQUEST", f"{status} {str(body)[:200]}")

        # 12. the boot repair never writes the commit tree: the take-down removed the published
        #     file, and a manifest entry listing it as on_site must not resurrect it from the source.
        #     Only an approval (candidate + publish) may publish a page again.
        status, body = call("POST", "/site/author-delta", body={
            "op": "overwrite", "authors": [
                {"uid": "bob", "area": "Pages", "entries": [target], "on_site": [target]},
            ],
        })
        check(
            "🔒 an overwrite never back-fills the commit tree from the source",
            status == 200 and not wfile(work, target).exists(),
            f"{status} {str(body)[:200]}",
        )
        check(
            "the preview tree carries the current source bytes after that overwrite",
            (preview / target).read_text(encoding="utf-8") == "changed again\n",
            str(target),
        )
        for op in ("candidate", "publish"):
            status, body = call("POST", "/site/author-delta", body={
                "op": op, "uid": "bob", "area": "Pages", "target": target,
            })
            check(f"the {op} delta restores the page -> 200", status == 200, f"{status} {str(body)[:200]}")
        check(
            "candidate + publish is how a page is published again",
            wfile(work, target).read_text(encoding="utf-8") == "changed again\n",
            wfile(work, target).read_text(encoding="utf-8") if wfile(work, target).is_file() else "missing from the commit tree",
        )

        # 13. a take-down also discards the candidate already waiting for that page. `pending/` is
        #     the review tree's last overlay, so a leftover candidate would put the taken-down page
        #     back in front of the reviewer - and the next approve would publish it again without
        #     a new review. The answer says whether a candidate went with the page.
        waiting = "a candidate waiting when the page is taken down\n"
        write(bob_src, "2026-05-01-hello.md", waiting)
        status, body = call("POST", "/site/author-delta", body={"op": "candidate", "uid": "bob", "area": "Pages", "target": target})
        check(
            "the candidate waiting when the page is taken down is frozen into pending/",
            status == 200 and (cfg.site.pending_dir / target).is_file(),
            f"{status} {str(body)[:200]}",
        )
        check(
            "that candidate is the review tree's copy",
            (cfg.site.onlinepreview / target).is_file()
            and (cfg.site.onlinepreview / target).read_text(encoding="utf-8") == waiting,
            str(target),
        )
        status, body = call("POST", "/site/author-delta", body={"op": "take-down", "uid": "bob", "area": "Pages", "target": target})
        check(
            "the take-down answers 200 and reports the discarded candidate",
            status == 200 and body.get("discarded") is True,
            f"{status} {str(body)[:200]}",
        )
        check(
            "🔒 the take-down removed the waiting candidate from pending/",
            not (cfg.site.pending_dir / target).exists(),
            str(cfg.site.pending_dir / target),
        )
        check(
            "the take-down removed the page from online/ (the published record)",
            not (cfg.site.online_dir / target).exists(),
            str(cfg.site.online_dir / target),
        )
        check(
            "🔒 the take-down removed the page from the review tree too",
            not (cfg.site.onlinepreview / target).exists(),
            str(cfg.site.onlinepreview / target),
        )
        check(
            "the take-down removed the page from the commit tree",
            not wfile(work, target).exists(),
            str(target),
        )

        # 14. with nothing waiting, the take-down is still a no-op-shaped success and says so:
        #     `discarded: false` means "no candidate was there", not "the page was not removed".
        status, body = call("POST", "/site/author-delta", body={"op": "take-down", "uid": "bob", "area": "Pages", "target": target})
        check(
            "a take-down with no candidate waiting reports discarded: false",
            status == 200 and body.get("discarded") is False,
            f"{status} {str(body)[:200]}",
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_onlinepreview_tree(tmp: Path) -> None:
    """The review tree: a frozen candidate, published on approval, discarded or taken down.

    The review object is the bytes of the moment the app applied a candidate. A later save of the
    source file must not move it - that is what makes "approve what you saw" true - and only a new
    ``candidate`` changes it. The snapshot ops never write the author preview, the review build has
    its own output directory, log and state record, and the two older trees keep their meaning.
    """
    print("\n[26] onlinepreview: the frozen candidate is the review object (the third tree)")
    from pages_bridge import gitops
    from pages_bridge.builder import run_build
    from pages_bridge.server import App

    root = tmp / "onp"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(site_src, "_config.yml", "title: t\n")
    write(site_src, "index.md", "# home\n")
    bob_src = nc_root / "bob" / "files" / "Pages"
    write(bob_src, "hello.md", "candidate one\n")
    write(bob_src, "other.md", "another candidate\n")
    # a second author whose file is never applied: the review tree must not grow a live copy of it
    write(nc_root / "carol" / "files" / "Pages", "carol.md", "carol's live file\n")
    target = "tmp/bob/hello.md"
    other = "tmp/bob/other.md"

    script = root / "fake-jekyll.py"
    script.parent.mkdir(parents=True, exist_ok=True)
    script.write_text(
        "import os, sys\n"
        "args = sys.argv[1:]\n"
        "dest = args[args.index('--destination') + 1] if '--destination' in args else '_site'\n"
        "os.makedirs(dest, exist_ok=True)\n"
        "here = os.getcwd()\n"
        "open(os.path.join(dest, 'where.txt'), 'w').write(here + '\\n')\n"
        "for root, _dirs, files in os.walk('.'):\n"
        "    for name in files:\n"
        "        if not name.endswith('.md'):\n"
        "            continue\n"
        "        rel = os.path.relpath(os.path.join(root, name), '.')\n"
        "        out = os.path.join(dest, rel[:-3] + '.html')\n"
        "        os.makedirs(os.path.dirname(out) or dest, exist_ok=True)\n"
        "        open(out, 'w').write(rel + '\\n')\n",
        encoding="utf-8",
    )
    cfg = load_config(_author_site_config(
        root, nc_root, "",
        [{
            "uid": "bob", "path": "/Pages",
            "entries": [
                {"path": "hello.md", "target": target},
                {"path": "other.md", "target": other},
                # created only before the do_mirror check below: the source is the preview's input
                {"path": "new.md", "target": "tmp/bob/new.md"},
            ],
            "removed": [],
        }],
        recipe={
            "preset": "jekyll", "output_dir": "_site",
            "command": [sys.executable, str(script)], "install_cmd": [],
        },
    ))
    site = cfg.site
    app = App(cfg)
    def delta(body: dict) -> dict:
        return app.do_author_delta(site, {"uid": "bob", **body})

    def candidates() -> list:
        """The pending targets, read straight off pending/ (there is no set file any more)."""
        if not site.pending_dir.is_dir():
            return []
        return sorted(
            p.relative_to(site.pending_dir).as_posix()
            for p in site.pending_dir.rglob("*")
            if p.is_file()
        )

    def review_files() -> str:
        if not site.onlinepreview.is_dir():
            return "missing"
        return str(sorted(
            p.relative_to(site.onlinepreview).as_posix()
            for p in site.onlinepreview.rglob("*")
            if p.is_file()
        ))

    def author_bytes(tree: Path) -> dict:
        """The author area of one tree, byte for byte - the invariant is about exactly this."""
        return {
            target: path.read_bytes()
            for target, path in app._author_area_files(tree, site).items()
        }

    # ── the third tree: derived like the preview, with its own build output ────────────────────────
    check(
        "the onlinepreview directory defaults to a sibling of the work copy",
        site.onlinepreview == site.work.parent / f"{site.work.name}.onlinepreview",
        str(site.onlinepreview),
    )
    check(
        "the three trees are three different directories",
        len({site.work, site.preview, site.onlinepreview}) == 3,
        f"{site.work} / {site.preview} / {site.onlinepreview}",
    )
    check(
        "the review build output is inside the review tree, not the preview or the commit tree",
        is_within(site.onlinepreview_output_dir, site.onlinepreview)
        and not is_within(site.onlinepreview_output_dir, site.preview)
        and not is_within(site.onlinepreview_output_dir, site.work),
        str(site.onlinepreview_output_dir),
    )
    reported = site.to_dict()
    check(
        "the site reports the review tree and its output",
        reported.get("onlinepreview") == str(site.onlinepreview)
        and reported.get("onlinepreview_output") == str(site.onlinepreview_output_dir),
        str(sorted(reported)),
    )

    # ── 0. the invariant with nothing pending: the review tree equals the commit tree ──────────────
    app._refresh_onlinepreview(site)
    check(
        "🔒 no candidate and nothing published: the author areas of both data trees are equal",
        author_bytes(site.onlinepreview) == author_bytes(site.work) == {},
        f"{review_files()} / {sorted(author_bytes(site.work))}",
    )

    # ── 1. candidate: the frozen bytes land in the review tree, and nowhere else ───────────────────
    first = delta({"op": "candidate", "target": target})
    check(
        "candidate answers ok, names the target and schedules the review build",
        first.get("ok") is True and first.get("applied") == [target] and first.get("tree") == "onlinepreview",
        str(first),
    )
    check(
        "the candidate reaches the review tree with the current source bytes",
        (site.onlinepreview / target).is_file()
        and (site.onlinepreview / target).read_text(encoding="utf-8") == "candidate one\n",
        review_files(),
    )
    check(
        "the candidate set records the target under review",
        candidates() == [target],
        str(candidates()),
    )
    check(
        "the first candidate mirrored the site skeleton into the review tree",
        (site.onlinepreview / "_config.yml").is_file() and (site.onlinepreview / "index.md").is_file(),
        review_files(),
    )
    check(
        "🔒 the review tree holds no author file that was not applied",
        not (site.onlinepreview / "tmp/carol/carol.md").exists()
        and not (site.onlinepreview / other).exists(),
        review_files(),
    )
    check("the candidate did not enter the commit tree", not wfile(site.work, target).exists())
    check(
        "🔒 the candidate did not enter the author preview either",
        not (site.preview / target).exists(),
        str(site.preview / target),
    )
    check(
        "candidate marks the review tree only, not the author preview",
        app.runtime.onlinepreview_dirty_at is not None and app.runtime.dirty_at is None,
        f"{app.runtime.dirty_at} / {app.runtime.onlinepreview_dirty_at}",
    )

    # ── 2. a new application replaces the frozen copy; the commit tree does not move ───────────────
    write(bob_src, "hello.md", "candidate two\n")
    second = delta({"op": "candidate", "target": target})
    check(
        "a second candidate overwrites the frozen copy",
        second.get("ok") is True
        and (site.onlinepreview / target).read_text(encoding="utf-8") == "candidate two\n",
        (site.onlinepreview / target).read_text(encoding="utf-8") if (site.onlinepreview / target).is_file() else "missing",
    )
    check(
        "🔒 the new candidate bytes left the commit tree untouched (still unpublished)",
        not wfile(site.work, target).exists(),
        str(sorted(author_bytes(site.work))),
    )
    check(
        "a second application keeps exactly one entry in the candidate set",
        candidates() == [target],
        str(candidates()),
    )

    # ── 3. the review object is frozen: a save alone does not move it ──────────────────────────────
    write(bob_src, "hello.md", "a save the reviewer has not seen\n")
    check(
        "🔒 a later save alone leaves the review copy at the applied bytes",
        (site.onlinepreview / target).read_text(encoding="utf-8") == "candidate two\n",
        (site.onlinepreview / target).read_text(encoding="utf-8") if (site.onlinepreview / target).is_file() else "missing",
    )
    check("the unsent save did not enter the commit tree either", not wfile(site.work, target).exists())

    # ── 4. publish: the frozen copy becomes the published content; both data trees agree ───────────
    frozen = (site.onlinepreview / target).read_text(encoding="utf-8")
    preview_clock = app.runtime.dirty_at
    review_clock = app.runtime.onlinepreview_dirty_at
    published = delta({"op": "publish", "target": target})
    check(
        "publish answers ok and names the target",
        published.get("ok") is True and published.get("applied") == [target],
        str(published),
    )
    check(
        "the published bytes are the frozen candidate's, not the author's current save",
        wfile(site.work, target).is_file()
        and wfile(site.work, target).read_text(encoding="utf-8") == frozen,
        wfile(site.work, target).read_text(encoding="utf-8") if wfile(site.work, target).is_file() else "missing from the commit tree",
    )
    check(
        "🔒 the published target stays in the review tree, with the published bytes",
        (site.onlinepreview / target).is_file()
        and (site.onlinepreview / target).read_text(encoding="utf-8") == frozen,
        review_files(),
    )
    check("the candidate set is empty after publish", candidates() == [], str(candidates()))
    check(
        "a published target entered online/ (the published record)",
        (site.online_dir / target).is_file()
        and (site.online_dir / target).read_text(encoding="utf-8") == frozen,
        str(sorted(p.relative_to(site.online_dir).as_posix() for p in site.online_dir.rglob("*") if p.is_file())),
    )
    check(
        "🔒 publish scheduled no build at all",
        app.runtime.dirty_at == preview_clock
        and app.runtime.onlinepreview_dirty_at == review_clock,
        f"{app.runtime.dirty_at} / {app.runtime.onlinepreview_dirty_at}",
    )
    # the invariant, byte for byte: with no pending candidate the review tree is the commit tree
    app._refresh_onlinepreview(site)
    published_bytes = wfile(site.work, target).read_bytes()
    check(
        "🔒 with no pending candidate the review tree is byte-identical to the commit tree",
        author_bytes(site.onlinepreview) == author_bytes(site.work) == {target: published_bytes},
        f"{review_files()} / {sorted(author_bytes(site.work))}",
    )

    # ── 5. discard: the review tree goes back to the published revision ───────────────────────────
    delta({"op": "candidate", "target": target})
    check(
        "the re-applied candidate carries the current source",
        (site.onlinepreview / target).is_file()
        and (site.onlinepreview / target).read_text(encoding="utf-8") == "a save the reviewer has not seen\n",
        review_files(),
    )
    check("the candidate set holds the re-applied target", candidates() == [target], str(candidates()))
    discarded = delta({"op": "discard", "target": target})
    check(
        "discard restores the review copy from the published content, byte for byte",
        discarded.get("ok") is True
        and (site.onlinepreview / target).read_text(encoding="utf-8") == frozen,
        str(discarded),
    )
    check("the candidate set is empty after discard", candidates() == [], str(candidates()))
    check(
        "🔒 discard leaves the published content in the commit tree alone",
        wfile(site.work, target).is_file()
        and wfile(site.work, target).read_text(encoding="utf-8") == frozen,
        wfile(site.work, target).read_text(encoding="utf-8") if wfile(site.work, target).is_file() else "missing from the commit tree",
    )
    check(
        "🔒 discard leaves both data trees equal again",
        author_bytes(site.onlinepreview) == author_bytes(site.work),
        f"{review_files()} / {sorted(author_bytes(site.work))}",
    )
    check(
        "discard schedules the review build, never the author preview's",
        app.runtime.onlinepreview_dirty_at is not None
        and app.runtime.dirty_at == preview_clock,
        f"{app.runtime.dirty_at} / {app.runtime.onlinepreview_dirty_at}",
    )

    # ── 6. do_mirror never feeds a source file into the commit tree ────────────────────────────────
    write(bob_src, "new.md", "a brand new source file\n")
    mirror_round = app.do_mirror(site)
    check("the mirror round is ok", mirror_round.ok, str(mirror_round.to_dict())[:300])
    check(
        "the new source file reaches the preview tree",
        (site.preview / "tmp/bob/new.md").read_text(encoding="utf-8") == "a brand new source file\n",
        str(sorted(author_bytes(site.preview))),
    )
    check(
        "🔒 the new source file never reaches the commit tree",
        not wfile(site.work, "tmp/bob/new.md").exists(),
        str(sorted(author_bytes(site.work))),
    )
    check(
        "🔒 the mirror keeps the published page in the commit tree",
        wfile(site.work, target).read_text(encoding="utf-8") == frozen,
    )

    # ── 7. a lost work tree, no remote: the published content comes back from the review tree ──────
    delta({"op": "candidate", "target": other})
    check("the second candidate is in the review tree", (site.onlinepreview / other).is_file(), review_files())
    check("the candidate set holds the pending target", candidates() == [other], str(candidates()))
    _rmtree(site.work)
    check("the commit tree is gone", not site.work.exists(), str(site.work))
    healed = app.ensure_work_repo(site)
    check(
        "ensure_work_repo restores a lost work tree from online/ when no remote is set",
        isinstance(healed, dict) and healed.get("recovered_from") == "online",
        str(healed),
    )
    check("the repository is back", gitops.is_repo(site.work), str(site.work))
    check(
        "the published page is back in the commit tree, byte for byte",
        wfile(site.work, target).read_text(encoding="utf-8") == frozen,
        str(sorted(author_bytes(site.work))),
    )
    check(
        "🔒 a pending candidate is not restored as published content",
        not wfile(site.work, other).exists(),
        str(sorted(author_bytes(site.work))),
    )
    check("the review tree still holds the pending candidate", (site.onlinepreview / other).is_file(), review_files())
    check("the candidate set survives the recovery", candidates() == [other], str(candidates()))

    # ── 8. take-down: the page leaves both data trees ──────────────────────────────────────────────
    # The review clock is parked first: a take-down that removes the page from the review tree must
    # schedule the debounced review round (the build the reviewer looks at has to follow), while the
    # author preview's own clock stays untouched.
    app.runtime.onlinepreview_dirty_at = None
    taken = delta({"op": "take-down", "target": target})
    check(
        "take-down removes the page from the commit tree",
        taken.get("ok") is True and not wfile(site.work, target).exists(),
        str(taken),
    )
    check(
        "take-down removes the page from the review tree too",
        not (site.onlinepreview / target).exists(),
        review_files(),
    )
    check(
        "the taken-down target left the candidate set",
        target not in candidates(),
        str(candidates()),
    )
    check(
        "a taken-down page left online/ (the published record)",
        not (site.online_dir / target).exists(),
        str(sorted(p.relative_to(site.online_dir).as_posix() for p in site.online_dir.rglob("*") if p.is_file())),
    )
    check(
        "🔒 take-down leaves the pending candidate alone",
        (site.onlinepreview / other).is_file(),
        review_files(),
    )
    check(
        "🔒 take-down scheduled the review build only, never the author preview",
        app.runtime.onlinepreview_dirty_at is not None
        and app.runtime.dirty_at == preview_clock,
        f"{app.runtime.dirty_at} / {app.runtime.onlinepreview_dirty_at}",
    )

    # ── 9. the whole review round: skeleton excluded from the author areas, candidates kept ────────
    preview_bytes = (site.preview / target).read_bytes()
    review_round = app._refresh_onlinepreview(site)
    check("the whole review-tree round is ok", review_round.ok, str(review_round.to_dict())[:300])
    check(
        "the review tree has the site skeleton",
        (site.onlinepreview / "_config.yml").is_file() and (site.onlinepreview / "index.md").is_file(),
        review_files(),
    )
    check(
        "🔒 the skeleton round runs no author mirror: another author's live file stays out",
        not (site.onlinepreview / "tmp/carol/carol.md").exists(),
        review_files(),
    )
    check(
        "🔒 a second review round keeps the pending candidate",
        (site.onlinepreview / other).is_file(),
        review_files(),
    )
    check(
        "🔒 the review round never writes into the author preview",
        (site.preview / target).read_bytes() == preview_bytes,
        str(site.preview / target),
    )

    # ── the scheduler: two dirty clocks, each tree built by its own trigger ────────────────────────
    # The review clock is parked first so this block observes the scheduler, not the earlier deltas.
    app.runtime.onlinepreview_dirty_at = None
    app.start_scheduler()
    try:
        app.touch_onlinepreview(site)
        deadline = time.time() + 15
        while time.time() < deadline and app.state.last_onlinepreview_build is None:
            time.sleep(0.05)
        scheduled = app.state.last_onlinepreview_build or {}
        check(
            "the scheduler builds the review tree from its own dirty mark",
            scheduled.get("tree") == "onlinepreview" and not (cfg.log_dir / "site.log").exists(),
            str(scheduled)[:300],
        )
        check(
            "the scheduled review build used the review output directory",
            scheduled.get("output_dir") == str(site.onlinepreview_output_dir),
            str(scheduled.get("output_dir")),
        )
        check(
            "🔒 the scheduled review build left the preview build record untouched",
            app.state.last_build is None,
            str(app.state.last_build),
        )
        check(
            "the scheduled review build wrote its own log",
            (cfg.log_dir / "site.onlinepreview.log").is_file(),
            str(sorted(p.name for p in cfg.log_dir.iterdir())) if cfg.log_dir.is_dir() else "missing",
        )
    finally:
        app.stop_scheduler()
        if app._scheduler is not None:
            app._scheduler.join(timeout=5)

    # ── 8. the review build: its own cwd, output dir, log file and state record ────────────────────
    built = run_build(site, cfg, app.state, tree="onlinepreview")
    check("the review build runs and succeeds", built.ok is True, str(built.to_dict())[:300])
    check(
        "the review build reports the review output directory",
        built.output_dir == str(site.onlinepreview_output_dir),
        built.output_dir,
    )
    check("the review build is tagged with its tree", built.tree == "onlinepreview", built.tree)
    where = site.onlinepreview_output_dir / "where.txt"
    check(
        "the review build ran with cwd = the review tree",
        where.is_file() and where.read_text(encoding="utf-8").strip() == str(site.onlinepreview.resolve()),
        where.read_text(encoding="utf-8").strip() if where.is_file() else "no where.txt",
    )
    check(
        "the frozen candidate was build input of the review build",
        (site.onlinepreview_output_dir / "tmp/bob/other.html").is_file(),
        str(sorted(p.relative_to(site.onlinepreview_output_dir).as_posix() for p in site.onlinepreview_output_dir.rglob('*.html')))
        if site.onlinepreview_output_dir.is_dir() else "missing",
    )
    check(
        "🔒 the review build wrote its own log and left the preview's log alone",
        (cfg.log_dir / "site.onlinepreview.log").is_file() and not (cfg.log_dir / "site.log").exists(),
        str(sorted(p.name for p in cfg.log_dir.iterdir())) if cfg.log_dir.is_dir() else "missing",
    )
    check(
        "🔒 the review build did not overwrite the preview build record",
        app.state.last_build is None
        and (app.state.last_onlinepreview_build or {}).get("output_dir") == str(site.onlinepreview_output_dir),
        f"last_build={app.state.last_build} / last_onlinepreview_build={app.state.last_onlinepreview_build}",
    )
    check(
        "🔒 neither the author preview nor the commit tree was built",
        not (site.preview / "_site").exists() and not wfile(site.work, "_site").exists(),
    )

    # ── onlinepreview_dir validation: absolute, and separate from all three other directories ──────
    def refused(mutate, expect: str) -> tuple[bool, str]:
        ok, msg = _load(tmp / "onp-cfg", mutate)
        return (not ok and expect in msg), msg

    good, msg = refused(
        lambda d: d["site"].update({"onlinepreview_dir": d["work_root"]}), "onlinepreview_dir",
    )
    check("🔒 an onlinepreview_dir equal to the work copy is refused", good, msg)
    good, msg = refused(
        lambda d: d["site"].update({
            "onlinepreview_dir": str(Path(d["work_root"]).parent / "work.preview" / "onp"),
        }),
        "onlinepreview_dir",
    )
    check("🔒 an onlinepreview_dir inside the preview directory is refused", good, msg)
    good, msg = refused(
        lambda d: d["site"].update({
            "onlinepreview_dir": str(Path(d["nc_data_root"]) / "alice" / "files" / "Sites" / "t" / "onp"),
        }),
        "onlinepreview",
    )
    check("🔒 an onlinepreview_dir inside the source is refused", good, msg)
    good, msg = refused(
        lambda d: d["site"].update({"onlinepreview_dir": str(Path(d["nc_data_root"]) / "onp")}),
        "onlinepreview",
    )
    check("🔒 an onlinepreview_dir inside the Nextcloud data directory is refused", good, msg)
    good, msg = refused(lambda d: d["site"].update({"onlinepreview_dir": "relative/onp"}), "absolute")
    check("🔒 a relative onlinepreview_dir is refused", good, msg)
    ok, msg = _load(
        tmp / "onp-cfg-ok",
        lambda d: d["site"].update({"onlinepreview_dir": str(tmp / "elsewhere" / "onp")}),
    )
    check("an absolute onlinepreview_dir outside every other tree is accepted", ok, msg)

    # ── shape checks: a snapshot op cannot leave the author directory or invent content ────────────
    def refused_op(body: dict) -> tuple[bool, str]:
        try:
            delta(body)
            return False, "no error raised"
        except BadRequest as exc:
            return exc.code == "BAD_REQUEST", str(exc.to_dict())

    good, msg = refused_op({"op": "candidate", "target": "index.md"})
    check("🔒 a candidate outside the author directory -> 400", good, msg)
    good, msg = refused_op({"op": "candidate", "target": "tmp/carol/carol.md"})
    check("🔒 a candidate for another author's target -> 400", good, msg)
    good, msg = refused_op({"op": "candidate", "target": "tmp/bob/not-there.md"})
    check("🔒 a candidate whose source file is missing -> 400", good, msg)
    good, msg = refused_op({"op": "publish", "target": "tmp/bob/never-applied.md"})
    check("🔒 publishing a target with no candidate -> 400 (only a frozen copy is publishable)", good, msg)


def test_orphan_site_files(tmp: Path) -> None:
    """A handed-over file becomes site source: ``<author_root>/<orphan>/`` is skeleton content.

    The orphan owner is a virtual name a deleted-but-published file is transferred to; the app
    writes it into ``source/``, so Apply source copies it like any other site file. A real author's
    copy under the same root is *not* source: the author layer wins in the preview tree, and the
    published copy in ``online/`` wins in the commit tree.
    """
    print("\n[27] orphan owner: <author_root>/<orphan>/ is skeleton, <author_root>/<uid>/ is overlaid")
    from pages_bridge.server import App

    root = tmp / "orphan"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(site_src, "_config.yml", "title: t\n")
    write(site_src, "index.md", "# home\n")
    bob_src = nc_root / "bob" / "files" / "Pages"
    write(bob_src, "same.md", "bob's page\n")

    bob = {
        "uid": "bob", "path": "/Pages",
        "entries": [{"path": "same.md", "target": "authors-src/bob/same.md"}],
        "removed": [],
    }
    cfg = load_config(_author_site_config(root, nc_root, "", [bob], author_root="authors-src"))
    site = cfg.site
    app = App(cfg)
    check("the author root is the configured one", site.author_root == "authors-src", site.author_root)

    # the handover: the app writes the published bytes into the orphan owner's site source
    write(site_src, "authors-src/common/x.md", "handed over to the orphan owner\n")
    # a real author's uid in the site source, plus the published copy the skeleton must not win over
    write(site_src, "authors-src/bob/same.md", "the site owner's copy\n")
    write(site.online_dir, "authors-src/bob/same.md", "the published copy\n")
    write(site.online_dir, "authors-src/bob/kept.md", "published before the handover\n")

    check("only the registered author is an author uid", app.author_uids(site) == ("bob",), str(app.author_uids(site)))

    result = app.apply_source(site, {"build": False})
    check("Apply source rebuilds the three trees", result.get("rebuilt") is True, str(result)[:300])

    # work = source/ + online/ merged flat: the orphan is skeleton content, and the published copy
    # wins over a source copy at the same real-author path (online is the later layer)
    check(
        "the orphan's file is assembled into the flat commit tree",
        wfile(site.work, "authors-src/common/x.md").is_file()
        and wfile(site.work, "authors-src/common/x.md").read_text(encoding="utf-8") == "handed over to the orphan owner\n",
        wtree(site.work),
    )
    check(
        "🔒 online/ wins in the flat commit tree: the published copy shadows the source copy",
        wfile(site.work, "authors-src/bob/same.md").read_text(encoding="utf-8") == "the published copy\n",
        wtree(site.work),
    )
    check(
        "🔒 a published-only author file is in the commit tree",
        wfile(site.work, "authors-src/bob/kept.md").is_file(),
        wtree(site.work),
    )
    # preview = source → online → the author folder: the live author file is the preview's input
    check(
        "the orphan's file reaches the preview tree as skeleton content",
        (site.preview / "authors-src/common/x.md").is_file(),
        str(sorted(p.relative_to(site.preview).as_posix() for p in (site.preview / "authors-src").rglob("*") if p.is_file())),
    )
    check(
        "the author layer wins over the site source's copy in the preview tree",
        (site.preview / "authors-src/bob/same.md").read_text(encoding="utf-8") == "bob's page\n",
        (site.preview / "authors-src/bob/same.md").read_text(encoding="utf-8")
        if (site.preview / "authors-src/bob/same.md").is_file() else "missing from the preview tree",
    )


def test_never_published_draft(tmp: Path) -> None:
    """A never-published author's draft is a first-class input of Apply source.

    The current app is event-driven and pushes no ``authors`` block, so the bridge discovers the
    author folders from the ``authors`` array of the Apply source request (or from the uids already
    present under ``online/``/``pending/``). A draft of an unknown author is only lost if the app
    omits that author: the interface contract is "Apply source carries every author area".
    """
    print("\n[27b] a never-published author's draft is part of Apply source")
    import threading

    from pages_bridge.server import build_server

    root = tmp / "draft"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(site_src, "_config.yml", "title: t\n")
    write(site_src, "index.md", "# home\n")
    # the orphan owner's directory: site source under the author root, not a real author
    write(site_src, "authors-src/common/x.md", "handed over to the orphan owner\n")
    # the author's own folder: reachable through the delta op and the Apply source authors list
    write(nc_root / "dana" / "files" / "Pages", "draft.md", "dana's first draft\n")

    target = "authors-src/dana/draft.md"
    orphan = "authors-src/common/x.md"
    cfg = load_config(_author_site_config(root, nc_root, "", None, author_root="authors-src"))
    site = cfg.site
    secret = cfg.shared_secret
    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-draft", daemon=True)
    thread.start()
    try:
        check("no authors block is pushed (the event-driven steady state)", site.authors == (), str(site.authors))
        check(
            "dana is not discoverable yet: nothing in online/ or pending/ names her",
            "dana" not in app.author_uids(site),
            str(app.author_uids(site)),
        )

        status, body = _http_call(port, "POST", "/site/author-delta", {
            "op": "add", "uid": "dana", "area": "Pages", "target": target,
        }, auth=secret)
        check(
            "the save delta writes the draft into the preview tree",
            status == 200 and (site.preview / target).is_file(),
            f"{status} {str(body)[:200]}",
        )
        check("the draft never entered the commit tree", not wfile(site.work, target).exists(), target)

        # Apply source names the authors: the whole folder is laid over the skeleton
        status, body = _http_call(port, "POST", "/site/mirror", {
            "authors": [{"uid": "dana", "area": "Pages"}],
            "build": False,
        }, auth=secret)
        check("Apply source with the author list -> 200", status == 200 and body.get("rebuilt") is True, f"{status} {str(body)[:300]}")
        check(
            "🔒 the never-published draft is in the preview tree after Apply source",
            (site.preview / target).is_file()
            and (site.preview / target).read_text(encoding="utf-8") == "dana's first draft\n",
            str(sorted(p.relative_to(site.preview).as_posix() for p in site.preview.rglob("*") if p.is_file())),
        )
        check(
            "🔒 the draft is not in the commit tree (only an approval publishes a page)",
            not wfile(site.work, target).exists(),
            wtree(site.work),
        )
        check(
            "the orphan owner's file is assembled into the preview tree",
            (site.preview / orphan).is_file()
            and (site.preview / orphan).read_text(encoding="utf-8") == "handed over to the orphan owner\n",
            str(sorted(p.relative_to(site.preview).as_posix() for p in site.preview.rglob("*") if p.is_file())),
        )
        check(
            "and into the flat commit tree",
            wfile(site.work, orphan).is_file()
            and wfile(site.work, orphan).read_text(encoding="utf-8") == "handed over to the orphan owner\n",
            wtree(site.work),
        )

        # after an approval dana is discoverable from online/ without the app repeating the list
        for op in ("candidate", "publish"):
            status, body = _http_call(port, "POST", "/site/author-delta", {
                "op": op, "uid": "dana", "area": "Pages", "target": target,
            }, auth=secret)
            check(f"the {op} of the draft -> 200", status == 200, f"{status} {str(body)[:200]}")
        status, body = _http_call(port, "POST", "/site/mirror", {"build": False}, auth=secret)
        check(
            "online/ makes dana discoverable for a later Apply source",
            status == 200 and "dana" in app.author_uids(site)
            and (site.preview / target).read_text(encoding="utf-8") == "dana's first draft\n",
            str(app.author_uids(site)),
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_publish_idempotent(tmp: Path) -> None:
    """A publish retried after a failed manifest write is a no-op, not a 400.

    The app writes its manifest *after* the bridge call. A failed manifest write leaves the entry
    waiting and the reviewer retries: the candidate mark is already gone, only the published bytes
    remain. That second call must succeed without touching the bytes and keep the target approved;
    only a target that is neither pending nor published is refused.
    """
    print("\n[24c] publish is idempotent: a retried approval does not need the candidate")
    import threading

    from pages_bridge.server import build_server

    root = tmp / "pubidem"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(site_src, "_config.yml", "title: t\n")
    bob_src = nc_root / "bob" / "files" / "Pages"
    write(bob_src, "page.md", "approved once\n")
    target = "authors-src/bob/page.md"
    cfg = load_config(_author_site_config(root, nc_root, "", None, author_root="authors-src"))
    site = cfg.site
    secret = cfg.shared_secret
    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-publish-idem", daemon=True)
    thread.start()

    def delta(op: str, which: str = target) -> tuple[int, dict]:
        return _http_call(port, "POST", "/site/author-delta", {
            "op": op, "uid": "bob", "area": "Pages", "target": which,
        }, auth=secret)

    try:
        status, body = delta("candidate")
        check(
            "the candidate freezes the review copy",
            status == 200 and (site.onlinepreview / target).is_file(),
            f"{status} {str(body)[:200]}",
        )
        status, body = delta("publish")
        check("the first publish -> 200", status == 200, f"{status} {str(body)[:200]}")
        check("the first publish is not flagged idempotent", not body.get("idempotent"), str(body))
        check(
            "the approved bytes are in the commit tree",
            wfile(site.work, target).read_text(encoding="utf-8") == "approved once\n",
            wfile(site.work, target).read_text(encoding="utf-8") if wfile(site.work, target).is_file() else "missing",
        )
        check(
            "the pending copy is gone after the publish",
            not (site.pending_dir / target).exists(),
            str(sorted(p.relative_to(site.pending_dir).as_posix() for p in site.pending_dir.rglob("*") if p.is_file())),
        )
        check(
            "the published record (online/) holds the target",
            (site.online_dir / target).is_file(),
            str(sorted(p.relative_to(site.online_dir).as_posix() for p in site.online_dir.rglob("*") if p.is_file())),
        )
        published = wfile(site.work, target).read_bytes()

        # the retry the app sends after its manifest write failed: no pending candidate any more
        status, body = delta("publish")
        check("the retried publish -> 200", status == 200, f"{status} {str(body)[:200]}")
        check("the retried publish reports idempotent: true", body.get("idempotent") is True, str(body))
        check(
            "the retry leaves the published bytes exactly as approved",
            wfile(site.work, target).read_bytes() == published,
            f"{published!r} -> {wfile(site.work, target).read_bytes()!r}",
        )
        check(
            "the retry keeps the target in online/",
            (site.online_dir / target).is_file(),
            str(site.online_dir / target),
        )

        # the other shape of the same retry: the review copy is gone as well (a refreshed review tree)
        (site.onlinepreview / target).unlink()
        status, body = delta("publish")
        check(
            "a retry with no review copy at all is still 200 idempotent",
            status == 200 and body.get("idempotent") is True,
            f"{status} {str(body)[:200]}",
        )
        check("that retry did not move the bytes either", wfile(site.work, target).read_bytes() == published)

        # a target that was never a candidate and was never published is still refused
        status, body = delta("publish", "authors-src/bob/never-applied.md")
        check(
            "publish of a target with no candidate and no published copy -> 400",
            status == 400 and body.get("error") == "BAD_REQUEST",
            f"{status} {str(body)[:200]}",
        )
        check(
            "and it published nothing",
            not wfile(site.work, "authors-src/bob/never-applied.md").exists(),
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_publish_digest(tmp: Path) -> None:
    """The approval is bound to the bytes the reviewer saw: publish carries the candidate's digest.

    A candidate answers the sha256 of the bytes it froze. The app records it with the request and
    hands it back on the approval, so a candidate the author replaced (or overwrote) between the
    request and the retry cannot be published: the bridge refuses with 400 ``DIGEST_MISMATCH`` and
    writes nothing. A publish without a digest keeps the old behaviour for an older app, with a
    warning; the idempotent retry checks the digest against ``online/`` when it is sent.
    """
    print("\n[24d] publish is bound to the approved bytes: the candidate's digest must match")
    import hashlib
    import threading

    from pages_bridge import server as server_module
    from pages_bridge.server import build_server

    root = tmp / "pubdigest"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(site_src, "_config.yml", "title: t\n")
    bob_src = nc_root / "bob" / "files" / "Pages"
    write(bob_src, "page.md", "approved once\n")
    target = "authors-src/bob/page.md"
    cfg = load_config(_author_site_config(root, nc_root, "", None, author_root="authors-src"))
    site = cfg.site
    secret = cfg.shared_secret
    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-publish-digest", daemon=True)
    thread.start()

    def delta(op: str, which: str = target, extra: dict | None = None) -> tuple[int, dict]:
        payload = {"op": op, "uid": "bob", "area": "Pages", "target": which}
        if extra:
            payload.update(extra)
        return _http_call(port, "POST", "/site/author-delta", payload, auth=secret)

    try:
        # 1. the candidate answers the digest of the bytes it froze
        status, body = delta("candidate")
        pending = site.pending_dir / target
        frozen = pending.read_bytes()
        first = hashlib.sha256(frozen).hexdigest()
        check(
            "candidate answers the sha256 of the pending bytes",
            status == 200 and body.get("digest") == first,
            f"{status} {str(body)[:200]}",
        )
        check(
            "the digest is a 64-character lowercase hex string",
            isinstance(body.get("digest"), str) and re.fullmatch(r"[0-9a-f]{64}", body["digest"]) is not None,
            str(body.get("digest")),
        )

        # the author replaces the candidate after the request: pending/ now holds different bytes
        write(bob_src, "page.md", "a later draft\n")
        status, body = delta("candidate")
        replacement = pending.read_bytes()
        second = hashlib.sha256(replacement).hexdigest()
        check(
            "the replacement candidate answers a different digest",
            status == 200 and body.get("digest") == second and second != first,
            f"{status} {str(body)[:200]}",
        )

        # 3. the stale digest (what the reviewer approved) must not publish the replacement
        status, body = delta("publish", extra={"digest": first})
        check(
            "publish with the approved digest but replaced bytes -> 400 DIGEST_MISMATCH",
            status == 400 and body.get("error") == "DIGEST_MISMATCH",
            f"{status} {str(body)[:300]}",
        )
        check(
            "the refusal names the digest that was approved and the one the tree holds",
            body.get("expected") == first and body.get("actual") == second,
            str(body)[:300],
        )
        check(
            "🔒 nothing was written into online/",
            not (site.online_dir / target).exists(),
            str(sorted(p.relative_to(site.online_dir).as_posix() for p in site.online_dir.rglob("*") if p.is_file()))
            if site.online_dir.is_dir() else "online/ does not exist",
        )
        check(
            "the replacement candidate is left pending for the reviewer",
            pending.is_file() and pending.read_bytes() == replacement,
        )

        # 2. the matching digest publishes the frozen bytes
        status, body = delta("publish", extra={"digest": second})
        check(
            "publish with the matching digest -> 200",
            status == 200 and not body.get("idempotent"),
            f"{status} {str(body)[:200]}",
        )
        check(
            "the approved bytes are the ones online/ now serves",
            (site.online_dir / target).read_bytes() == replacement,
        )

        # 5. the idempotent branch (no pending file): the digest is checked against online/
        status, body = delta("publish", extra={"digest": first})
        check(
            "the idempotent retry with a stale digest -> 400 DIGEST_MISMATCH",
            status == 400 and body.get("error") == "DIGEST_MISMATCH",
            f"{status} {str(body)[:300]}",
        )
        check(
            "🔒 the idempotent refusal leaves the published bytes alone",
            (site.online_dir / target).read_bytes() == replacement,
        )
        status, body = delta("publish", extra={"digest": second})
        check(
            "the idempotent retry with the published digest -> 200 idempotent",
            status == 200 and body.get("idempotent") is True,
            f"{status} {str(body)[:200]}",
        )

        # 4. no digest: the old behaviour is kept, and the missing binding is warned about once
        compat = "authors-src/bob/compat.md"
        write(bob_src, "compat.md", "no digest\n")
        status, _body = delta("candidate", which=compat)
        compat_bytes = (site.pending_dir / compat).read_bytes()
        check("the compatibility candidate is frozen", status == 200 and (site.pending_dir / compat).is_file())
        warnings: list[tuple[str, dict]] = []
        real_warn = server_module.warn
        server_module.warn = lambda message, **fields: warnings.append((message, fields))
        try:
            status, body = delta("publish", which=compat)
        finally:
            server_module.warn = real_warn
        check(
            "publish without a digest still succeeds (compatibility)",
            status == 200 and not body.get("idempotent"),
            f"{status} {str(body)[:200]}",
        )
        check(
            "the warning says the publish carried no digest",
            len(warnings) == 1 and "publish without a digest" in warnings[0][0],
            str(warnings),
        )
        check(
            "the bytes were published anyway",
            (site.online_dir / compat).read_bytes() == compat_bytes,
            repr((site.online_dir / compat).read_bytes()) if (site.online_dir / compat).is_file() else "missing",
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_published_content(tmp: Path) -> None:
    """``GET /site/published-content``: the published bytes of one target, base64, read-only."""
    print("\n[28] GET /site/published-content (the copy a refused handover reads back)")
    import threading

    from pages_bridge import server as server_module
    from pages_bridge.server import build_server

    root = tmp / "pubcontent"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    site_src.mkdir(parents=True, exist_ok=True)
    cfg = load_config(_author_site_config(root, nc_root, "", None, author_root="authors-src"))
    site = cfg.site

    target = "authors-src/alice/notes/a.md"
    page_bytes = b"# a published page\n"
    page = wfile(site.work, target)
    page.parent.mkdir(parents=True, exist_ok=True)
    page.write_bytes(page_bytes)
    big = wfile(site.work, "authors-src/alice/big.bin")
    big.write_bytes(bytes(range(64)))

    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-published-content", daemon=True)
    thread.start()

    def call(path: str, auth: str | None = cfg.shared_secret):
        return _http_call(port, "GET", path, None, auth)

    try:
        status, body = call(f"/site/published-content?target={target}")
        check(
            "a published target -> 200 with the exact bytes",
            status == 200
            and body.get("ok") is True
            and body.get("target") == target
            and body.get("bytes") == len(page_bytes)
            and base64.b64decode(body.get("content_base64") or "") == page_bytes,
            f"{status} {str(body)[:200]}",
        )
        check(
            "the answer is exactly the documented four keys",
            set(body) == {"ok", "target", "bytes", "content_base64"},
            str(sorted(body)),
        )

        status, body = call("/site/published-content?target=authors-src/alice/notes/missing.md")
        check(
            "an unpublished target -> 404 NOT_FOUND",
            status == 404 and body.get("error") == "NOT_FOUND",
            f"{status} {str(body)[:200]}",
        )
        status, body = call("/site/published-content?target=authors-src/alice/notes")
        check(
            "a directory target -> 404 too (no file to read)",
            status == 404 and body.get("error") == "NOT_FOUND",
            f"{status} {str(body)[:200]}",
        )
        status, _ = call(f"/site/published-content?target={target}", auth=None)
        check("the route needs the bearer secret -> 401", status == 401, f"{status}")

        status, body = call("/site/published-content")
        check(
            "a missing target -> 400",
            status == 400 and body.get("error") == "BAD_REQUEST",
            f"{status} {str(body)[:200]}",
        )
        bad_targets = {
            "outside any author area": "index.md",
            "an absolute path": f"/{target}",
            "a traversal": "authors-src/alice/../alice/a.md",
            "an empty segment": "authors-src//notes/a.md",
            "a dot segment": "authors-src/alice/./a.md",
            "no file below the uid": "authors-src/alice",
            "another author root": "tmp/alice/notes/a.md",
            "an invalid uid": "authors-src/al ice/notes/a.md",
        }
        for label, bad in bad_targets.items():
            status, body = call(f"/site/published-content?target={quote(bad, safe='/')}")
            check(
                f"{label} -> 400 BAD_REQUEST",
                status == 400 and body.get("error") == "BAD_REQUEST",
                f"{status} {str(body)[:200]}",
            )

        # the size cap is a module constant, so a small value needs no huge fixture
        original_limit = server_module.PUBLISHED_CONTENT_MAX_BYTES
        server_module.PUBLISHED_CONTENT_MAX_BYTES = 32
        try:
            status, body = call("/site/published-content?target=authors-src/alice/big.bin")
            check(
                "a file above the cap -> 413 TOO_LARGE",
                status == 413 and body.get("error") == "TOO_LARGE",
                f"{status} {str(body)[:200]}",
            )
            status, body = call(f"/site/published-content?target={target}")
            check(
                "a file under the cap still reads",
                status == 200 and base64.b64decode(body.get("content_base64") or "") == page_bytes,
                f"{status} {str(body)[:120]}",
            )
        finally:
            server_module.PUBLISHED_CONTENT_MAX_BYTES = original_limit

        # read-only: asking for a page must not mirror, build or commit anything
        check(
            "🔒 reading a published file mirrors and builds nothing",
            app.runtime.last_mirror is None
            and app.state.last_build is None
            and not site.preview.exists()
            and wfile(site.work, target).read_bytes() == page_bytes,
            f"last_mirror={app.runtime.last_mirror} last_build={app.state.last_build} preview={site.preview.exists()}",
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def _commit_all(work: Path, message: str) -> str:
    """Commit everything in a throwaway repository, with an identity this test owns."""
    env = {
        **os.environ,
        "GIT_AUTHOR_NAME": "t",
        "GIT_AUTHOR_EMAIL": "t@example.invalid",
        "GIT_COMMITTER_NAME": "t",
        "GIT_COMMITTER_EMAIL": "t@example.invalid",
    }
    quiet = {"stdout": subprocess.DEVNULL, "stderr": subprocess.DEVNULL, "env": env}
    subprocess.run(["git", "add", "-A"], cwd=str(work), check=True, **quiet)
    subprocess.run(["git", "commit", "-m", message], cwd=str(work), check=True, **quiet)
    return subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=str(work), capture_output=True, text=True, check=True
    ).stdout.strip()


def _advance_remote(remote: Path, scratch: Path, files: dict[str, str], message: str) -> str:
    """Push a new commit to the bare remote from a separate clone (the "somebody else pushed" case)."""
    env = {
        **os.environ,
        "GIT_AUTHOR_NAME": "t",
        "GIT_AUTHOR_EMAIL": "t@example.invalid",
        "GIT_COMMITTER_NAME": "t",
        "GIT_COMMITTER_EMAIL": "t@example.invalid",
    }
    quiet = {"stdout": subprocess.DEVNULL, "stderr": subprocess.DEVNULL, "env": env}
    subprocess.run(["git", "clone", str(remote), str(scratch)], check=True, **quiet)
    for rel, text in files.items():
        write(scratch, rel, text)
    sha = _commit_all(scratch, message)
    subprocess.run(["git", "push", "origin", "HEAD:main"], cwd=str(scratch), check=True, **quiet)
    return sha


def test_state_files_lost(tmp: Path) -> None:
    """The old "lost approved set" is a **stale commit tree** now: it is rebuilt, never fatal.

    No bridge-written target set exists any more, so the old check (``author_external.json`` gone
    while work holds author content → refuse the start) has no input. The equivalent invariant is:
    every file of ``online/`` is in ``work``. A missing or different one means the derived work tree
    is stale, and :meth:`App.verify_state_files` recreates it from ``source/`` + ``online/`` - at
    startup, and before any write operation.
    """
    print("\n[34] work consistency: a stale commit tree is rebuilt from online/, never fatal")
    import threading

    from pages_bridge import __main__ as cli
    from pages_bridge import server as server_module
    from pages_bridge.server import App, build_server

    base = tmp / "statelost"
    base.mkdir(parents=True, exist_ok=True)
    nc_root = base / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    site_src.mkdir(parents=True, exist_ok=True)
    write(site_src, "_config.yml", "title: t\n")
    target = "tmp/alice/hello.md"

    def fixture(name: str):
        """Own work/state dirs per scenario: one case must never see another's leftovers."""
        root = base / name
        root.mkdir(parents=True, exist_ok=True)
        cfg_path = _author_site_config(root, nc_root, "", None)
        cfg = load_config(cfg_path)
        return cfg_path, cfg, App(cfg)

    # A: the normal shape - online/ and work/ agree
    _cfg_path, cfg, app = fixture("normal")
    write(cfg.site.online_dir, target, "hello\n")
    write(wfile(cfg.site.work), target, "hello\n")
    check("A a consistent work tree is not a drift", app.work_drift(cfg.site) is None, str(app.work_drift(cfg.site))[:300])
    verdict = app.verify_state_files()
    check(
        "A verify passes on the consistent state without rebuilding",
        verdict.get("ok") is True and verdict.get("checked") is True and verdict.get("rebuilt") is False,
        str(verdict),
    )

    # B: the published file disappeared from the derived tree
    wfile(cfg.site.work, target).unlink()
    drift = app.work_drift(cfg.site)
    check(
        "B a published file missing from work/ is a drift",
        isinstance(drift, dict) and drift.get("state") == "drifted" and drift.get("missing") == 1,
        str(drift)[:300],
    )
    check(
        "B the drift names the work tree and the online directory",
        (drift or {}).get("work") == str(cfg.site.work) and (drift or {}).get("online_dir") == str(cfg.site.online_dir),
        str(drift)[:300],
    )
    verdict = app.verify_state_files()
    check(
        "B verify rebuilds the tree from online/ instead of refusing",
        verdict.get("ok") is True and verdict.get("rebuilt") is True and wfile(cfg.site.work, target).is_file()
        and wfile(cfg.site.work, target).read_text(encoding="utf-8") == "hello\n",
        str(verdict)[:300],
    )
    check("B the rebuilt tree is consistent again", app.work_drift(cfg.site) is None, str(app.work_drift(cfg.site)))

    # C: same path, different bytes - also a stale assembly
    write(wfile(cfg.site.work), target, "an older revision\n")
    drift = app.work_drift(cfg.site)
    check(
        "C a different revision in work/ is a drift",
        isinstance(drift, dict) and drift.get("different") == 1,
        str(drift)[:300],
    )
    verdict = app.verify_state_files()
    check(
        "C verify restores the online/ bytes",
        verdict.get("rebuilt") is True and wfile(cfg.site.work, target).read_text(encoding="utf-8") == "hello\n",
        str(verdict)[:300],
    )

    # D: nothing published yet - an empty or arbitrary work tree is not a drift
    _cfg_path, cfg, app = fixture("empty-online")
    # the fixture shares the site folder with the cases above: empty online/ first
    _rmtree(cfg.site.online_dir)
    cfg.site.online_dir.mkdir(parents=True, exist_ok=True)
    write(wfile(cfg.site.work), "scratch.md", "not published\n")
    check("D with online/ empty there is no drift", app.work_drift(cfg.site) is None, str(app.work_drift(cfg.site))[:300])
    check("D verify passes without touching the tree", app.verify_state_files().get("rebuilt") is False)

    # E: the whole work directory is gone: the check recreates the files and the repository
    _cfg_path, cfg, app = fixture("missing-tree")
    write(cfg.site.online_dir, target, "hello\n")
    _rmtree(cfg.site.work)
    check("E the commit tree is gone", not cfg.site.work.exists(), str(cfg.site.work))
    drift = app.work_drift(cfg.site)
    check("E a missing work directory is a drift", isinstance(drift, dict) and drift.get("missing") == 1, str(drift)[:300])
    verdict = app.verify_state_files()
    check("E verify rebuilds the missing tree", verdict.get("rebuilt") is True, str(verdict)[:300])
    check(
        "E the published file is back and the source skeleton is assembled too",
        wfile(cfg.site.work, target).read_text(encoding="utf-8") == "hello\n"
        and wfile(cfg.site.work, "_config.yml").is_file(),
        str(sorted(p.relative_to(cfg.site.work).as_posix() for p in cfg.site.work.rglob("*") if p.is_file())),
    )
    if shutil.which("git") is not None:
        check("E the repository was re-created around the assembled files", (cfg.site.work / ".git").is_dir(), str(cfg.site.work))

    # F: the old refusal is gone, not merely unused
    check("F the bridge no longer defines EXIT_STATE_LOST", not hasattr(server_module, "EXIT_STATE_LOST"))
    check("F the CLI no longer imports a StateLost path", not hasattr(cli, "EXIT_STATE_LOST"))
    check("F no state-lost marker function survives", not hasattr(server_module, "record_state_lost"))
    _cfg_path, cfg, app = fixture("no-marker")
    write(cfg.site.online_dir, target, "hello\n")
    app.verify_state_files()
    check(
        "F a rebuilt start writes no state-lost.json anywhere",
        not list(cfg.bootstrap_state_dir.glob("state-lost.json")) and not list(cfg.state_dir.glob("state-lost.json")),
    )

    # G: a stale tree is repaired before a write operation, and the operation continues
    _cfg_path, cfg, app = fixture("before-write")
    write(cfg.site.online_dir, target, "hello\n")
    check("G the operation starts with a drift", app.work_drift(cfg.site) is not None)
    repaired = app.ensure_work_consistent(cfg.site, request="POST /site/build")
    check(
        "G ensure_work_consistent repairs it and returns the facts",
        isinstance(repaired, dict) and repaired.get("files", 0) >= 2 and wfile(cfg.site.work, target).is_file(),
        str(repaired)[:300],
    )
    check("G a second call is a no-op", app.ensure_work_consistent(cfg.site, request="POST /site/build") is None)

    # H: the runtime binding path - a site pushed to a running bridge is repaired by the next write
    j_root = base / "runtime-bind"
    j_nc = j_root / "ncdata"
    j_src = j_nc / "alice" / "files" / "Sites" / "t" / "source"
    (j_src).mkdir(parents=True, exist_ok=True)
    write(j_src, "_config.yml", "title: t\n")
    j_work = j_root / "work"
    j_state = j_root / "state"
    j_cfg_path = j_root / "cfg.toml"
    j_cfg_path.write_text(
        "\n".join(
            f"{key} = {_toml_value(value)}"
            for key, value in {
                "listen": "127.0.0.1:8787",
                "nc_data_root": str(j_nc),
                "work_root": str(j_work),
                "state_dir": str(j_state),
                "override_dir": str(j_root / "overrides"),
                "log_dir": str(j_root / "logs"),
            }.items()
        )
        + "\n",
        encoding="utf-8",
    )
    try:
        httpd, j_app = build_server(load_config(j_cfg_path), port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-runtime-heal", daemon=True)
    thread.start()
    try:
        check("H an UNPAIRED start has no site yet", j_app.cfg.site is None, str(j_app.cfg.site))
        early = j_app.verify_state_files()
        check(
            "H the startup check reports 'not checked' instead of a verdict",
            early.get("ok") is True and early.get("checked") is False,
            str(early),
        )
        j_secret = "r" * 32
        j_state.mkdir(parents=True, exist_ok=True)
        (j_state / "pairing.json").write_text(
            json.dumps({"code": "RUNT2345", "exp": int(time.time()) + 600, "attempts": 0}),
            encoding="utf-8",
        )
        status, body = _http_call(
            port,
            "POST",
            "/pair",
            {
                "code": "RUNT2345",
                "shared_secret": j_secret,
                "session_signing_secret": "s" * 32,
                "nc_data_root": str(j_nc),
                "revision": 3,
                "site": {
                    "nc_user": "alice", "nc_path": "/Sites/t", "repo_url": "", "branch": "main",
                    "recipe": {"preset": "jekyll", "output_dir": "."},
                },
            },
        )
        check("H a running bridge can bind a site it did not start with", status == 200 and j_app.cfg.site is not None, f"{status} {str(body)[:200]}")
        j_target = "tmp/alice/hello.md"
        write(j_app.cfg.site.online_dir, j_target, "hello\n")
        check("H the bound site starts with a stale work tree", j_app.work_drift(j_app.cfg.site) is not None)
        status, body = _http_call(port, "POST", "/site/init", {}, auth=j_secret)
        check(
            "H /site/init repairs the stale tree and answers 200 (the old 503 is gone)",
            status == 200 and body.get("ok") is True,
            f"{status} {str(body)[:300]}",
        )
        check(
            "H the published page survived the repair",
            wfile(j_work, j_target).is_file() and wfile(j_work, j_target).read_text(encoding="utf-8") == "hello\n",
            str(sorted(p.relative_to(j_work).as_posix() for p in j_work.rglob("*") if p.is_file())),
        )
        if shutil.which("git") is not None:
            check("H init created the repository", (j_work / ".git").is_dir(), str(j_work / ".git"))
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_publish_remote_states(tmp: Path) -> None:
    print("\n[25] publish: commit always, push only with a remote, structured rejection, pull")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    import threading

    from pages_bridge import gitops
    from pages_bridge.server import App, build_server

    base = tmp / "pubstate"
    base.mkdir(parents=True, exist_ok=True)

    # ── A: no remote. Publish is the commit, and the answer says so without any "not pushed" wording
    cfg, src, wk = _scenario(
        base / "a", source={"_config.yml": "title: t\n", "index.md": "hi\n"}, work={}, git_repo=False,
        repo_url="",
    )
    app = App(cfg)
    init = app.do_init(cfg.site)
    check("A init without a remote succeeds", init["ok"] is True and init["git"]["action"] == "init", str(init)[:200])
    subprocess.run(["git", "config", "user.email", "t@example.invalid"], cwd=str(wk), check=False,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(["git", "config", "user.name", "t"], cwd=str(wk), check=False,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    write(src, "new.md", "new\n")
    # The skeleton enters the commit tree through Apply source; a publish only commits what the
    # commit tree already holds, so the explicit round is what makes the file part of the commit.
    app.apply_source(cfg.site, {"build": False})
    out = app.do_publish(cfg.site, "publish without a remote")
    check("A publish without a remote -> 200-shaped answer", out.get("ok") is True, str(out)[:200])
    check("A a commit was made", bool(out.get("commit")), str(out.get("commit")))
    check("A remote_configured is false", out.get("remote_configured") is False, str(sorted(out)))
    check("A nothing was pushed", out.get("pushed") is False and "push_error" not in out, str(sorted(out)))
    check("A a real commit exists", len(gitops.head_sha(wk)) == 40, gitops.head_sha(wk))
    blob = json.dumps(out).lower()
    check(
        "A no not-pushed wording anywhere in the answer",
        not any(word in blob for word in ("not pushed", "not_pushed", "unpushed", "not-pushed")),
        blob[:300],
    )

    # ── B: push rejected because the remote moved; Pull then replaces the publish tree
    # The remote starts EMPTY on purpose: init no longer clones its history, so the first publish is
    # the repository's first commit and can fast-forward an empty remote. The rejected push is still
    # produced by the second publish after somebody else advanced the remote.
    remote = base / "b" / "origin.git"
    remote.parent.mkdir(parents=True, exist_ok=True)
    subprocess.run(
        ["git", "init", "--bare", "-q", "--initial-branch=main", str(remote)],
        check=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    cfg, src, wk = _scenario(
        base / "b", source={"_config.yml": "title: t\n", "index.md": "two\n"}, work={}, git_repo=False,
        repo_url=str(remote),
    )
    app = App(cfg)
    init = app.do_init(cfg.site)
    check("B init creates the repository without cloning", init["git"]["action"] == "init", str(init["git"]))
    check("B HEAD is unborn after init", gitops.head_sha(wk) == "", gitops.head_sha(wk))
    for key, value in (("user.email", "t@example.invalid"), ("user.name", "t")):
        subprocess.run(["git", "config", key, value], cwd=str(wk), check=False,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    write(src, "mine.md", "mine\n")
    app.apply_source(cfg.site, {"build": False})
    first = app.do_publish(cfg.site, "first")
    check("B the first publish pushed", first.get("pushed") is True and bool(first.get("commit")), str(first)[:200])
    committed = gitops.run(wk, ["ls-tree", "-r", "--name-only", "HEAD"], check=False).stdout.split()
    check(
        "B the commit is flat: _config.yml and the pages at the repository root",
        "_config.yml" in committed
        and "index.md" in committed
        and not any(path.startswith(("source/", "online/")) for path in committed),
        str(committed),
    )

    remote_head = _advance_remote(
        remote, base / "other",
        {"theirs.md": "theirs\n", "tmp/theirs/page.md": "a published page\n"},
        "somebody else",
    )
    write(src, "mine2.md", "mine2\n")
    app.apply_source(cfg.site, {"build": False})
    second = app.do_publish(cfg.site, "second")
    check("B the commit happened even though the push failed", bool(second.get("commit")), str(second)[:200])
    check("B the answer is not an error", second.get("ok") is True, str(second)[:200])
    err = second.get("push_error") or {}
    check("B push_error is structured", err.get("code") == "PUSH_REJECTED", str(err)[:300])
    check("B the ahead count is computed, not guessed", err.get("ahead") == 1, str(err)[:300])
    check("B nothing is reported as pushed", second.get("pushed") is False, str(sorted(second)))
    check(
        "B no not-pushed wording in the rejection",
        not any(word in json.dumps(second).lower() for word in ("not pushed", "not_pushed", "unpushed")),
        json.dumps(second)[:300],
    )

    preview = app.pull_preview(cfg.site)
    check("B pull preview reports the ahead count", preview.get("remote_ahead") == 1, str(preview)[:300])
    check("B pull preview reports the repository head", len(str(preview.get("head") or "")) == 40, str(preview)[:200])
    write(src, "local-draft.md", "unpublished\n")
    preview = app.pull_preview(cfg.site)
    check("B pull preview counts the unpublished local files", preview.get("local_unpublished") == 1, str(preview)[:300])

    # the remote carries a page this instance already publishes: Pull must leave it out of source/
    write(cfg.site.online_dir, "tmp/theirs/page.md", "a published page\n")
    pulled = app.do_pull(cfg.site)
    check("B pull moved the publish tree to the remote head", pulled.get("head") == remote_head, str(pulled)[:300])
    check("B the remote's file is in the flat commit tree", wfile(wk, "theirs.md").is_file(), wtree(wk))
    check("B the local commit was replaced, not merged", not wfile(wk, "mine2.md").exists(), wtree(wk))
    archive = pulled.get("archive") or {}
    check("B an archive of the remote's source was produced", Path(str(archive.get("path"))).is_file(), str(archive))
    check("B the archive carries a sha256", len(str(archive.get("sha256") or "")) == 64, str(archive))
    names_b, _ = _archive_names_and_index(Path(str(archive.get("path"))))
    check(
        "🔒 B the archive holds the flat HEAD minus the known online path",
        "_config.yml" in names_b
        and "theirs.md" in names_b
        and "tmp/theirs/page.md" not in names_b
        and archive.get("source_only") is True
        and archive.get("excluded") == 1,
        f"{names_b} · {archive}",
    )
    check("B the pull answer carries no layout name any more", "remote_layout" not in pulled, str(sorted(pulled)))

    # ── B2: the committed round trip - publish a site AND a published page, pull again, and the
    # archive the app writes into source/ must leave the known online pages out: otherwise the next
    # Apply source would commit them as the administrator's skeleton.
    write(src.parents[4] / "alice" / "files" / "Pages", "page.md", "a published page\n")
    for op in ("candidate", "publish"):
        app.do_author_delta(cfg.site, {"op": op, "uid": "alice", "area": "Pages", "target": "tmp/alice/page.md"})
    # Pull replaced the commit tree with the remote HEAD, so it no longer matches source/ +
    # online/; a publish refuses that tree now (WORK_DRIFT). Apply source is the repair, exactly as
    # the app does after it writes a pulled archive into the folder.
    app.apply_source(cfg.site, {"build": False})
    again = app.do_publish(cfg.site, "with a published page")
    check("B2 the publish with a published page reached the remote", again.get("pushed") is True, str(again.get("push_error") or again)[:300])
    commit_tree = gitops.run(wk, ["ls-tree", "-r", "--name-only", "HEAD"], check=False).stdout.split()
    check(
        "B2 the commit is flat: the skeleton and the published pages at the root",
        "tmp/alice/page.md" in commit_tree
        and "_config.yml" in commit_tree
        and "index.md" in commit_tree
        and not any(path.startswith(("source/", "online/")) for path in commit_tree),
        str(commit_tree),
    )
    round_trip = app.do_pull(cfg.site)
    names_r, _ = _archive_names_and_index(Path(round_trip["archive"]["path"]))
    check(
        "🔒 B2 the pull archive leaves both known online pages out",
        round_trip["archive"].get("source_only") is True
        and "_config.yml" in names_r
        and not any(name.startswith("tmp/") for name in names_r)
        and round_trip["archive"].get("excluded") == 2,
        f"{names_r} · {round_trip['archive']}",
    )

    # pull requires a remote
    cfg_nr, _src_nr, _wk_nr = _scenario(
        base / "c", source={"_config.yml": "x\n"}, work={}, git_repo=False, repo_url=""
    )
    try:
        App(cfg_nr).do_pull(cfg_nr.site)
        check("C pull without a remote is refused", False, "no error raised")
    except BadRequest as exc:
        check("C pull without a remote is refused", exc.code == "BAD_REQUEST", str(exc.to_dict()))


def test_status_paths(tmp: Path) -> None:
    """``gitops.status()`` returns paths that exist: the rename target, never the source, never quoted.

    The two ways the old parser produced a value that is not a path - and that ``git add -A --``
    then refused, so the page silently stayed out of the commit while publish answered ``ok``:

    * a rename record (``2``) was split on its last tab field, which is the **source** path - the
      file that is gone after the rename;
    * without ``-z`` git C-quotes every name outside ASCII, so ``tmp/posts/中文.md`` arrived as
      ``"tmp/posts/\\344\\270\\255.md"``.

    With ``-z`` the records are NUL-separated, the rename record ends after its target and the
    source is the next record (consumed and dropped), and the names come back literal. ``GET
    /site/diff`` feeds these values to ``BuildController::changedPaths()``, which hands them back
    as ``stage_paths``, so this is the path the app really stages.
    """
    print("\n[26] git status paths: the rename target, literal non-ASCII names, never the source")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    from pages_bridge import gitops

    repo = tmp / "statuspaths"
    repo.mkdir(parents=True, exist_ok=True)
    env = {
        **os.environ,
        "GIT_AUTHOR_NAME": "t",
        "GIT_AUTHOR_EMAIL": "t@example.invalid",
        "GIT_COMMITTER_NAME": "t",
        "GIT_COMMITTER_EMAIL": "t@example.invalid",
    }
    quiet = {"cwd": str(repo), "stdout": subprocess.DEVNULL, "stderr": subprocess.DEVNULL, "env": env}

    def git(*args: str) -> None:
        subprocess.run(["git", *args], check=True, **quiet)

    git("init", "-b", "main")
    git("config", "user.email", "t@example.invalid")
    git("config", "user.name", "t")
    write(repo, "_config.yml", "title: t\n")
    write(repo, "tmp/posts/old-name.md", "the same body, renamed later\n")
    write(repo, "tmp/posts/数据.md", "v1\n")
    write(repo, "tmp/posts/space name.md", "v1\n")
    git("add", "-A")
    git("commit", "-q", "-m", "seed")

    # a staged rename onto a non-ASCII target, modified non-ASCII/space names, an untracked one
    git("mv", "tmp/posts/old-name.md", "tmp/posts/中文-新.md")
    write(repo, "tmp/posts/数据.md", "v2\n")
    write(repo, "tmp/posts/space name.md", "v2\n")
    write(repo, "tmp/posts/未跟踪.md", "new\n")

    target = "tmp/posts/中文-新.md"
    source = "tmp/posts/old-name.md"
    st = gitops.status(repo)
    check("the rename is reported by its target path", target in st.changed, str(sorted(st.changed)))
    check(
        "the rename's source (the path that is gone) is not reported",
        source not in st.changed,
        str(sorted(st.changed)),
    )
    check(
        "the modified non-ASCII file is reported literally",
        "tmp/posts/数据.md" in st.changed,
        str(sorted(st.changed)),
    )
    check(
        "a name containing a space is reported whole",
        "tmp/posts/space name.md" in st.changed,
        str(sorted(st.changed)),
    )
    check(
        "the untracked non-ASCII file is reported literally",
        "tmp/posts/未跟踪.md" in st.untracked,
        str(sorted(st.untracked)),
    )
    check(
        "no returned path is C-quoted or backslash-escaped",
        not any("\\3" in path or path.startswith('"') for path in (*st.changed, *st.staged, *st.untracked)),
        str([*st.changed, *st.staged, *st.untracked]),
    )
    check(
        "every reported changed path really exists in the work copy",
        all((repo / path).is_file() for path in st.changed),
        str([path for path in st.changed if not (repo / path).is_file()]),
    )
    check("the rename target is in the staged set", target in st.staged, str(sorted(st.staged)))
    check(
        "the status survives JSON (the app reads it over HTTP)",
        json.loads(json.dumps(st.to_dict(), ensure_ascii=False))["changed"] == st.changed[:100],
        json.dumps(st.to_dict(), ensure_ascii=False)[:200],
    )
    # what the app does with these values: stage them one by one, exactly as reported
    check(
        "staging the reported paths is accepted by git",
        gitops.stage_paths(repo, st.changed) == [],
        str(gitops.stage_paths(repo, st.changed)),
    )
    check(
        "the old value (the rename source) is what git refuses to stage",
        gitops.stage_paths(repo, [source]) == [source],
        str(gitops.stage_paths(repo, [source])),
    )

    # the witness for the bug: the non -z line's *last* tab field is the source, and the target is
    # quoted - both values were unusable for `git add -A -- <path>`
    plain = subprocess.run(
        ["git", "status", "--porcelain=v2", "--branch", "--untracked-files=all"],
        cwd=str(repo),
        env=env,
        capture_output=True,
        text=True,
        encoding="utf-8",
    ).stdout
    rename_line = next((line for line in plain.splitlines() if line.startswith("2 ")), "")
    check("the output really carried a rename record", bool(rename_line), str(plain.splitlines())[:200])
    check(
        "the naive non -z parse returns the source path (the bug being fixed)",
        rename_line.split("\t", 1)[-1] == source,
        rename_line,
    )


def test_author_delta_locking(tmp: Path) -> None:
    """Author deltas/Apply source run under the site lock, and tree writes are atomic.

    The 700 ms debounce means a save regularly lands while the scheduled round is reading the same
    tree, and two deltas can interleave a write of the same ``pending/`` or ``online/`` path. The
    checks below are the facts that close that window:

    * every tree file is written through a temporary file and renamed (:meth:`App._write_tree_file`),
      so an interrupted save leaves the previous bytes - never half a page;
    * the delta and a real Apply source hold ``runtime.lock``, the one the build/publish/scheduler
      take (a dry run deliberately does not, it is the damage assessment);
    * two concurrent candidates, then two concurrent publishes, both survive: no approval is lost.
    """
    print("\n[27] author deltas under the site lock: atomic tree writes, no lost target-set update")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    import threading

    from pages_bridge.server import build_server

    root = tmp / "deltalock"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    site_src.mkdir(parents=True, exist_ok=True)
    write(site_src, "_config.yml", "title: t\n")
    bob_src = nc_root / "bob" / "files" / "Pages"
    write(bob_src, "a.md", "first a\n")
    write(bob_src, "b.md", "first b\n")

    # no remote: nothing below may reach the network
    cfg = load_config(_author_site_config(root, nc_root, "", None))
    site = cfg.site
    work = site.work
    preview = site.preview
    secret = cfg.shared_secret
    try:
        httpd, app = build_server(cfg, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-delta-locking", daemon=True)
    thread.start()

    def call(method: str, path: str, body: dict | None = None, auth: str | None = secret):
        return _http_call(port, method, path, body, auth)

    a_target = "tmp/bob/a.md"
    b_target = "tmp/bob/b.md"

    try:
        status, body = call("POST", "/site/init", body={})
        check("init prepared the site", status == 200, f"{status} {str(body)[:200]}")

        # ── 1. the lock really is held by the request path ────────────────────────────────────────
        seen: dict[str, bool] = {}
        original_delta = app.do_author_delta
        original_apply = app.apply_source

        def delta_spy(site_arg, body_arg):
            seen["delta_locked"] = app.runtime.lock.locked()
            return original_delta(site_arg, body_arg)

        def apply_spy(site_arg, body_arg=None):
            seen["mirror_locked"] = app.runtime.lock.locked()
            return original_apply(site_arg, body_arg)

        app.do_author_delta = delta_spy  # type: ignore[method-assign]
        try:
            status, body = call("POST", "/site/author-delta", body={"op": "add", "uid": "bob", "area": "Pages", "target": a_target})
        finally:
            app.do_author_delta = original_delta  # type: ignore[method-assign]
        check("the save delta answers 200", status == 200, f"{status} {str(body)[:200]}")
        check("the author delta runs under the site lock", seen.get("delta_locked") is True, str(seen))
        check("the delta left the file in the preview tree", (preview / a_target).is_file(), str(preview / a_target))

        app.apply_source = apply_spy  # type: ignore[method-assign]
        try:
            status, body = call("POST", "/site/mirror", body={"build": False})
            check("a real Apply source answers 200", status == 200, f"{status} {str(body)[:200]}")
            check("a real Apply source runs under the site lock", seen.get("mirror_locked") is True, str(seen))
            seen["mirror_locked"] = True  # a dry run must not enter the lock; the spy would flip it
            status, body = call("POST", "/site/mirror", body={"dry_run": True})
            check("the dry-run Apply source answers 200", status == 200, f"{status} {str(body)[:200]}")
            check(
                "a dry run takes no lock (it stays available while a real round runs)",
                seen.get("mirror_locked") is False,
                str(seen),
            )
        finally:
            app.apply_source = original_apply  # type: ignore[method-assign]

        # ── 2. an interrupted write leaves the old bytes, never half a page ──────────────────────
        # Apply source cleared the derived preview; restore the save it observed before the test
        app.do_author_delta(site, {"op": "add", "uid": "bob", "area": "Pages", "target": a_target})
        write(bob_src, "a.md", "second a\n")
        saved_replace = os.replace

        def interrupted(*_args, **_kwargs):
            raise OSError("simulated interruption while saving")

        os.replace = interrupted  # type: ignore[assignment]
        raised = False
        try:
            app.do_author_delta(site, {"op": "add", "uid": "bob", "area": "Pages", "target": a_target})
        except OSError:
            raised = True
        finally:
            os.replace = saved_replace  # type: ignore[assignment]
        check("an interrupted save raises instead of reporting success", raised, "")
        check(
            "the interrupted save left the previous bytes in the preview tree",
            (preview / a_target).read_text(encoding="utf-8") == "first a\n",
            (preview / a_target).read_text(encoding="utf-8"),
        )
        leftovers = sorted(p.name for p in (preview / "tmp" / "bob").iterdir() if ".tmp" in p.name)
        check("no temporary file is left behind", leftovers == [], str(leftovers))
        app.do_author_delta(site, {"op": "add", "uid": "bob", "area": "Pages", "target": a_target})
        check(
            "the same save completes once the write can land",
            (preview / a_target).read_text(encoding="utf-8") == "second a\n",
            (preview / a_target).read_text(encoding="utf-8"),
        )

        # ── 3. two concurrent candidates, then two concurrent publishes: nothing is lost ─────────
        results: dict[str, tuple[int, dict]] = {}

        def delta(op: str, target: str) -> None:
            results[target] = call(
                "POST", "/site/author-delta",
                body={"op": op, "uid": "bob", "area": "Pages", "target": target},
            )

        def run_both(op: str) -> None:
            results.clear()
            workers = [threading.Thread(target=delta, args=(op, t), name=f"{op}-{i}") for i, t in enumerate((a_target, b_target))]
            for worker in workers:
                worker.start()
            for worker in workers:
                worker.join(timeout=60)

        run_both("candidate")
        check(
            "both concurrent candidates answered 200",
            sorted(code for code, _ in results.values()) == [200, 200],
            str({k: v[0] for k, v in results.items()}),
        )
        pending_files = sorted(
            p.relative_to(site.pending_dir).as_posix()
            for p in site.pending_dir.rglob("*") if p.is_file()
        )
        check(
            "no candidate was lost by the concurrent update",
            pending_files == [a_target, b_target],
            str(pending_files),
        )

        run_both("publish")
        check(
            "both concurrent publishes answered 200",
            sorted(code for code, _ in results.values()) == [200, 200],
            str({k: v[0] for k, v in results.items()}),
        )
        online_files = sorted(
            p.relative_to(site.online_dir).as_posix()
            for p in site.online_dir.rglob("*") if p.is_file()
        )
        check(
            "no approval was lost by the concurrent update: online/ holds both targets",
            online_files == [a_target, b_target],
            str(online_files),
        )
        check(
            "the pending copies are gone after both publishes",
            not (site.pending_dir / a_target).exists() and not (site.pending_dir / b_target).exists(),
        )
        check(
            "both published files are in the commit tree",
            wfile(work, a_target).is_file() and wfile(work, b_target).is_file(),
            str(sorted(p.as_posix() for p in work.rglob("*.md"))),
        )

        # ── 4. a save issued while a mirror round is running waits, then lands complete ──────────
        apply_round = app.apply_source

        def slow_apply(site_arg, body_arg=None):
            time.sleep(0.3)  # the round holds the lock while it clears and copies
            return apply_round(site_arg, body_arg)

        app.apply_source = slow_apply  # type: ignore[method-assign]
        concurrent: dict[str, tuple[int, dict]] = {}
        try:
            def mirror_call() -> None:
                concurrent["mirror"] = call("POST", "/site/mirror", body={"build": False})

            worker = threading.Thread(target=mirror_call, name="slow-mirror", daemon=True)
            worker.start()
            time.sleep(0.05)  # the mirror is inside its round and cannot release until it returns
            write(bob_src, "a.md", "third a\n")
            concurrent["delta"] = call(
                "POST", "/site/author-delta",
                body={"op": "add", "uid": "bob", "area": "Pages", "target": a_target},
            )
            worker.join(timeout=60)
        finally:
            app.apply_source = apply_round  # type: ignore[method-assign]
        check(
            "a save during a mirror round waits for it instead of failing",
            concurrent.get("delta", (0, {}))[0] == 200,
            str(concurrent.get("delta"))[:300],
        )
        check(
            "the mirror round still answered 200",
            concurrent.get("mirror", (0, {}))[0] == 200,
            str(concurrent.get("mirror"))[:300],
        )
        check(
            "the save's bytes are complete after the round",
            (preview / a_target).read_text(encoding="utf-8") == "third a\n",
            (preview / a_target).read_text(encoding="utf-8"),
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_assembly_guard(tmp: Path) -> None:
    """Apply source (and the build that assembles) must never empty or gut a tree.

    The defect these checks pin down: the three trees are "clear, then copy layer by layer", and a
    ``source/`` that is empty, missing or unreadable used to be read as an empty layer - the commit
    tree was cleared, rebuilt empty and the next ``git add -A`` deleted the whole site. The guard
    lives in ``App._preflight`` and runs before anything is cleared.

    A publish is the other half of the same rule: it does not assemble at all. It commits the tree
    it is given, so a refused round can never clear the tree in the middle of a commit - and a save
    that arrived after the last explicit round is not swept in by an implicit one.
    """
    print("\n[41] assembly preflight: empty/unreadable source and the delete guard")
    from pages_bridge.server import App

    # ── 1. an empty source/ refuses the round and leaves the old trees alone ────────────────────
    root1 = tmp / "assembly-guard-empty"
    nc1 = root1 / "ncdata"
    cfg1 = load_config(_author_site_config(root1, nc1, "", None))
    site1 = cfg1.site
    app1 = App(cfg1)
    write(wfile(site1.work), "old-skeleton.md", "old\n")
    write(site1.preview, "old-skeleton.md", "old\n")
    empty = app1.apply_source(site1, {"build": False})
    check(
        "an empty source/ refuses Apply source",
        empty.get("ok") is False and empty.get("error") == "SOURCE_EMPTY",
        str(empty)[:300],
    )
    check(
        "the refusal says nothing was cleared or rebuilt",
        empty.get("rebuilt") is False and "nothing was cleared" in str(empty.get("message")),
        str(empty.get("message"))[:200],
    )
    check(
        "🔒 the old work tree was not cleared",
        wfile(site1.work, "old-skeleton.md").is_file(),
        str(wtree(site1.work)),
    )
    check("🔒 the old preview tree was not cleared", (site1.preview / "old-skeleton.md").is_file())
    forced = app1.apply_source(site1, {"build": False, "force": True})
    check(
        "force does not skip the source check",
        forced.get("error") == "SOURCE_EMPTY" and wfile(site1.work, "old-skeleton.md").is_file(),
        str(forced)[:300],
    )
    dry = app1.apply_source(site1, {"dry_run": True})
    check(
        "a dry run reports the same verdict",
        dry.get("ok") is False and dry.get("error") == "SOURCE_EMPTY",
        str(dry)[:300],
    )

    # ── 2. source/ that exists but cannot be walked is SOURCE_UNREADABLE, not "empty" ───────────
    root2 = tmp / "assembly-guard-unreadable"
    nc2 = root2 / "ncdata"
    cfg2 = load_config(_author_site_config(root2, nc2, "", None))
    site2 = cfg2.site
    app2 = App(cfg2)
    write(site2.source_dir, "a.md", "a\n")
    write(wfile(site2.work), "keep.md", "keep\n")
    write(site2.preview, "keep.md", "keep\n")
    original_layer_files = app2._layer_files

    def unreadable(root_arg: Path, skip=None):  # noqa: ANN001 - mirrors _layer_files
        if Path(root_arg) == site2.source_dir:
            raise PermissionError(13, "Permission denied", str(root_arg))
        return original_layer_files(root_arg, skip)

    app2._layer_files = unreadable  # type: ignore[method-assign]
    try:
        broken = app2.apply_source(site2, {"build": False})
    finally:
        app2._layer_files = original_layer_files  # type: ignore[method-assign]
    check(
        "an unreadable source/ refuses Apply source",
        broken.get("ok") is False and broken.get("error") == "SOURCE_UNREADABLE",
        str(broken)[:300],
    )
    check(
        "the refusal names the unreadable directory",
        broken.get("dir") == str(site2.source_dir),
        str(broken.get("dir")),
    )
    check(
        "🔒 the old trees were left alone by the unreadable round",
        wfile(site2.work, "keep.md").is_file() and (site2.preview / "keep.md").is_file(),
    )

    # the same rule covers the overlay layers: an unreadable online/ must not be read as "empty"
    write(site2.online_dir, "published.md", "published\n")
    write(wfile(site2.work), "published.md", "published\n")

    def unreadable_online(root_arg: Path, skip=None):  # noqa: ANN001 - mirrors _layer_files
        if Path(root_arg) == site2.online_dir:
            raise PermissionError(13, "Permission denied", str(root_arg))
        return original_layer_files(root_arg, skip)

    app2._layer_files = unreadable_online  # type: ignore[method-assign]
    try:
        broken_online = app2.apply_source(site2, {"build": False})
    finally:
        app2._layer_files = original_layer_files  # type: ignore[method-assign]
    check(
        "an unreadable online/ also refuses the round",
        broken_online.get("error") == "SOURCE_UNREADABLE",
        str(broken_online)[:300],
    )
    check(
        "🔒 the published layer was not cleared by the unreadable online/",
        wfile(site2.work, "published.md").is_file(),
        str(wtree(site2.work)),
    )

    # ── 3. the delete guard: a round that would delete most of a tree is refused ────────────────
    root3 = tmp / "assembly-guard-delete"
    nc3 = root3 / "ncdata"
    cfg3 = load_config(_author_site_config(root3, nc3, "", None))
    site3 = cfg3.site
    app3 = App(cfg3)
    write(site3.source_dir, "only.md", "only\n")
    for i in range(50):
        write(wfile(site3.work), f"old/{i}.md", "x")
    guarded = app3.apply_source(site3, {"build": False})
    check(
        "a round that would delete 49 of 50 work files is refused",
        guarded.get("ok") is False and guarded.get("error") == "MIRROR_DELETE_GUARD",
        str(guarded)[:400],
    )
    check(
        "the refusal carries old/new/removed and names the tree",
        (guarded.get("old"), guarded.get("new"), guarded.get("removed"), guarded.get("tree"))
        == (50, 1, 49, "work"),
        str({key: guarded.get(key) for key in ("tree", "old", "new", "removed")}),
    )
    check(
        "🔒 the 50 old files are still there after the refusal",
        len(list(wfile(site3.work).rglob("*.md"))) == 50,
        str(sorted(p.as_posix() for p in wfile(site3.work).rglob("*.md"))[:3]),
    )
    force_round = app3.apply_source(site3, {"build": False, "force": True})
    check(
        "force:true runs the refused round anyway",
        force_round.get("ok") is True and force_round.get("rebuilt") is True,
        str(force_round)[:300],
    )
    check(
        "the forced round assembled the new tree",
        (force_round.get("files") or {}).get("work") == 1 and not wfile(site3.work, "old/0.md").exists(),
        str(force_round.get("files")),
    )
    again = app3.apply_source(site3, {"build": False})
    check(
        "an ordinary round of the same site is not refused by the guard",
        again.get("ok") is True and again.get("rebuilt") is True,
        str(again)[:300],
    )

    # ── 4. Apply source refuses an empty source; a publish checks the tree, never repairs it ─────
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment (the publish checks)")
        return
    from pages_bridge import gitops
    from pages_bridge.mirror import MirrorResult
    from pages_bridge.util import WorkDrift

    root4 = tmp / "assembly-guard-publish"
    nc4 = root4 / "ncdata"
    cfg4 = load_config(_author_site_config(root4, nc4, "", None))
    site4 = cfg4.site
    app4 = App(cfg4)
    write(site4.source_dir, "a.md", "a\n")
    assembled = app4.apply_source(site4, {"build": False})
    check(
        "the site assembles once for the publish checks",
        assembled.get("ok") is True and gitops.is_repo(site4.work),
        str(assembled)[:300],
    )
    published = app4.do_publish(site4, "the first publish")
    head = published.get("commit")
    check(
        "the first publish committed",
        published.get("ok") is True and isinstance(head, str) and len(head) >= 7,
        str(published)[:300],
    )

    # The source folder is emptied. Apply source and build refuse; a publish refuses too - not
    # because of the folder itself, but because the commit tree is no longer the (now empty)
    # assembly: every skeleton file is an `extra` the assembly does not produce. It is never
    # repaired by a publish, so a folder emptied by mistake cannot clear the commit tree.
    for path in sorted(site4.source_dir.rglob("*")):
        if path.is_file():
            path.unlink()
    mirror_calls = {"n": 0}
    original_mirror = app4.do_mirror

    def counted_mirror(*args, **kwargs):  # noqa: ANN002, ANN003 - a transparent spy
        mirror_calls["n"] += 1
        return original_mirror(*args, **kwargs)

    app4.do_mirror = counted_mirror  # type: ignore[method-assign]
    try:
        refused: WorkDrift | None = None
        try:
            app4.do_publish(site4, "the tree is what it is")
        except WorkDrift as exc:
            refused = exc
        payload = refused.to_dict() if refused is not None else {}
        check(
            "a publish with an emptied source/ is refused as a drift, not repaired",
            refused is not None
            and payload.get("error") == "WORK_DRIFT"
            and payload.get("extra") == 1
            and (payload.get("targets") or {}).get("extra") == ["a.md"],
            str(payload)[:300],
        )
        check(
            "the refusal says nothing was committed and names Apply source",
            "nothing was committed" in str(payload.get("message")) and "Apply source" in str(payload.get("hint")),
            str(payload.get("message"))[:200],
        )
        check(
            "🔒 a publish runs no assembly",
            mirror_calls["n"] == 0,
            f"do_mirror was called {mirror_calls['n']} times",
        )
        check(
            "🔒 nothing was committed by that publish (the tree did not change)",
            gitops.head_sha(site4.work) == head,
            f"{gitops.head_sha(site4.work)} != {head}",
        )
        check(
            "🔒 the commit tree was not cleared by the publish",
            wfile(site4.work, "a.md").is_file(),
            str(wtree(site4.work)),
        )
        build = app4.do_build(site4)
        check(
            "build still refuses when source/ is empty",
            build.get("ok") is False and build.get("error") == "SOURCE_EMPTY" and build.get("build") is None,
            str(build)[:300],
        )
        check(
            "the build refusal came from the assembly preflight, not from a mirror round",
            mirror_calls["n"] == 0 and (build.get("mirror") or {}).get("error") == "SOURCE_EMPTY",
            str(build.get("mirror"))[:300],
        )

        # A publish does not consult the mirror at all, and a mirror that refuses cannot make it
        # assemble: the drift is reported before any mirror would run. A skeleton file that only
        # source/ carries is missing from the commit tree -> WORK_DRIFT, not a repair.
        write(site4.source_dir, "b.md", "b\n")
        mocked: WorkDrift | None = None
        app4.do_mirror = lambda *args, **kwargs: MirrorResult(  # type: ignore[method-assign]
            ok=False,
            guard_tripped=True,
            guard_message="MIRROR_DELETE_GUARD: mocked refusal",
        )
        try:
            try:
                app4.do_publish(site4, "the mirror says no, the publish does not care")
            except WorkDrift as exc:
                mocked = exc
        finally:
            app4.do_mirror = original_mirror  # type: ignore[method-assign]
        mocked_payload = mocked.to_dict() if mocked is not None else {}
        check(
            "a drifted tree is WORK_DRIFT even when a mirror round would refuse too",
            mocked is not None
            and mocked_payload.get("missing") == 1
            and (mocked_payload.get("targets") or {}).get("missing") == ["b.md"],
            str(mocked_payload)[:300],
        )
        check(
            "🔒 the mocked mirror was never consulted and nothing was committed",
            mirror_calls["n"] == 0 and gitops.head_sha(site4.work) == head,
            f"mirror={mirror_calls['n']} head={gitops.head_sha(site4.work)}",
        )

        # Apply source is the one repair; the publish after it commits normally, with no mirror.
        repaired = app4.apply_source(site4, {"build": False})
        resumed = app4.do_publish(site4, "Apply source made the tree the assembly")
        check(
            "after Apply source the same publish commits, with no mirror round",
            repaired.get("ok") is True
            and resumed.get("ok") is True
            and bool(resumed.get("commit"))
            and resumed.get("commit") != head
            and mirror_calls["n"] == 0,
            str(resumed)[:300],
        )
    finally:
        app4.do_mirror = original_mirror  # type: ignore[method-assign]


def test_publish_drift(tmp: Path) -> None:
    """A publish checks the commit tree against ``source/`` + ``online/``; it never repairs it.

    ``App.assembly_drift`` compares the commit tree (``.git`` pruned at the top) with the flat
    assembly the two Nextcloud layers would produce. A missing, different or extra file - or a
    ``source/`` that exists but cannot be read - refuses the publish with ``WORK_DRIFT`` before any
    git command, and nothing is written. ``do_build`` no longer repairs the commit tree either, and
    ``rebuild_work`` keeps the source guard unless init is the caller.
    """
    print("\n[43] publish checks the assembly (WORK_DRIFT); build and rebuild_work never repair it")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    from pages_bridge import gitops
    from pages_bridge import server as server_module
    from pages_bridge.server import App
    from pages_bridge.util import WorkDrift

    root = tmp / "publish-drift"
    cfg, src, work = _scenario(
        root,
        source={"_config.yml": "title: t\n", "index.md": "hi\n"},
        work={},
        git_repo=False,
        repo_url="",
    )
    site = cfg.site
    # online/ wins over the skeleton on the same path: index.md is the published revision
    write(site.online_dir, "index.md", "published hi\n")
    write(site.online_dir, "tmp/alice/hello.md", "hello\n")
    app = App(cfg)
    init = app.do_init(site)
    check(
        "the drift fixture initialises a consistent flat commit tree",
        init.get("ok") is True
        and wfile(work, "index.md").read_text(encoding="utf-8") == "published hi\n"
        and wfile(work, "tmp/alice/hello.md").is_file(),
        str(init)[:300],
    )
    for key, value in (("user.email", "t@example.invalid"), ("user.name", "t")):
        subprocess.run(
            ["git", "config", key, value], cwd=str(work),
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False,
        )
    first = app.do_publish(site, "the consistent tree")
    check(
        "5. a consistent tree still publishes",
        first.get("ok") is True and bool(first.get("commit")),
        str(first)[:300],
    )
    head = gitops.head_sha(work)

    def snapshot() -> dict[tuple[str, str], bytes]:
        """Every file of every tree (``.git`` left out) -> its bytes."""
        saved: dict[tuple[str, str], bytes] = {}
        for name, tree in (
            ("work", site.work),
            ("source", site.source_dir),
            ("online", site.online_dir),
            ("preview", site.preview),
            ("onlinepreview", site.onlinepreview),
        ):
            if not tree.is_dir():
                continue
            for path in tree.rglob("*"):
                if not path.is_file():
                    continue
                parts = path.relative_to(tree).parts
                if parts and parts[0] == ".git":
                    continue
                saved[(name, path.relative_to(tree).as_posix())] = path.read_bytes()
        return saved

    def refusal(name: str) -> WorkDrift | None:
        try:
            app.do_publish(site, name)
        except WorkDrift as exc:
            return exc
        return None

    # ── 1. a published file is missing from the commit tree ─────────────────────────────────────
    wfile(work, "tmp/alice/hello.md").unlink()
    before = snapshot()
    exc = refusal("a missing online file")
    payload = exc.to_dict() if exc is not None else {}
    check(
        "1. a file the assembly expects but work does not hold -> missing",
        exc is not None
        and payload.get("error") == "WORK_DRIFT"
        and payload.get("missing") == 1
        and (payload.get("targets") or {}).get("missing") == ["tmp/alice/hello.md"],
        str(payload)[:300],
    )
    check(
        "1. the refusal is a 409 WORK_DRIFT that names no commit",
        exc is not None
        and exc.code == "WORK_DRIFT"
        and exc.http == 409
        and "nothing was committed" in str(payload.get("message"))
        and "Apply source" in str(payload.get("hint")),
        str(payload.get("message"))[:200],
    )
    check("1. 🔒 no commit was made", gitops.head_sha(work) == head, f"{gitops.head_sha(work)} != {head}")
    check("1. 🔒 every tree is byte-identical after the refusal", snapshot() == before)

    # ── 2. both hold the path, the bytes differ ─────────────────────────────────────────────────
    write(wfile(work), "tmp/alice/hello.md", "hello\n")
    write(wfile(work), "index.md", "an older revision\n")
    before = snapshot()
    exc = refusal("a different revision")
    payload = exc.to_dict() if exc is not None else {}
    check(
        "2. different bytes in work -> different, not missing",
        exc is not None
        and payload.get("different") == 1
        and (payload.get("targets") or {}).get("different") == ["index.md"]
        and payload.get("missing") == 0,
        str(payload)[:300],
    )
    check("2. 🔒 no commit was made", gitops.head_sha(work) == head, f"{gitops.head_sha(work)} != {head}")
    check("2. 🔒 every tree is byte-identical after the refusal", snapshot() == before)

    # ── 3. a skeleton file deleted from source/ while work still holds it ──────────────────────
    write(src, "skeleton-only.md", "skeleton\n")
    applied = app.apply_source(site, {"build": False})
    check("3. Apply source repairs the tree after a drift", applied.get("ok") is True, str(applied)[:300])
    committed = app.do_publish(site, "the skeleton file")
    head = committed.get("commit") or gitops.head_sha(work)
    check("3. the repaired tree publishes", bool(committed.get("commit")), str(committed)[:300])
    (src / "skeleton-only.md").unlink()
    before = snapshot()
    exc = refusal("a skeleton file deleted from source/")
    payload = exc.to_dict() if exc is not None else {}
    check(
        "3. a file work holds that the assembly no longer produces -> extra",
        exc is not None
        and payload.get("extra") == 1
        and (payload.get("targets") or {}).get("extra") == ["skeleton-only.md"],
        str(payload)[:300],
    )
    check("3. 🔒 no commit was made", gitops.head_sha(work) == head, f"{gitops.head_sha(work)} != {head}")
    check("3. 🔒 every tree is byte-identical after the refusal", snapshot() == before)

    # ── 4. an emptied source/ lists the whole skeleton as extra; work is untouched ─────────────
    for path in sorted(src.rglob("*")):
        if path.is_file():
            path.unlink()
    before = snapshot()
    exc = refusal("the source folder is empty")
    payload = exc.to_dict() if exc is not None else {}
    check(
        "4. an empty source/ makes the skeleton files extra",
        exc is not None
        and payload.get("extra") == 2
        and set((payload.get("targets") or {}).get("extra") or []) == {"_config.yml", "skeleton-only.md"},
        str(payload)[:400],
    )
    check(
        "4. 🔒 the commit tree still holds exactly its bytes",
        snapshot() == before and wfile(work, "_config.yml").is_file() and wfile(work, "skeleton-only.md").is_file(),
        str(wtree(work)),
    )
    check("4. 🔒 no commit was made", gitops.head_sha(work) == head, f"{gitops.head_sha(work)} != {head}")

    # ── 4b. a source layer that exists but cannot be read is refused, never read as empty ─────
    write(src, "_config.yml", "title: t\n")
    original_layer_files = app._layer_files

    def unreadable(root_arg: Path, skip=None):  # noqa: ANN001 - mirrors _layer_files
        if Path(root_arg) == site.source_dir:
            raise PermissionError(13, "Permission denied", str(root_arg))
        return original_layer_files(root_arg, skip)

    app._layer_files = unreadable  # type: ignore[method-assign]
    try:
        exc = refusal("an unreadable source layer")
    finally:
        app._layer_files = original_layer_files  # type: ignore[method-assign]
    payload = exc.to_dict() if exc is not None else {}
    check(
        "4b. an unreadable source/ is WORK_DRIFT with the SOURCE_UNREADABLE reason",
        exc is not None
        and payload.get("error") == "WORK_DRIFT"
        and payload.get("reason") == "SOURCE_UNREADABLE"
        and payload.get("dir") == str(site.source_dir),
        str(payload)[:300],
    )
    check("4b. 🔒 no commit was made", gitops.head_sha(work) == head, f"{gitops.head_sha(work)} != {head}")

    # ── 6. do_build does not repair the commit tree any more ───────────────────────────────────
    write(src, "_config.yml", "title: t\n")
    write(src, "index.md", "hi\n")
    applied = app.apply_source(site, {"build": False})
    check(
        "6. the build fixture starts consistent",
        applied.get("ok") is True and app.assembly_drift(site) is None,
        str(applied)[:300],
    )
    write(wfile(work), "index.md", "hand edited\n")
    check("6. the build fixture starts drifted", app.assembly_drift(site) is not None)
    before = snapshot()
    before_mtimes = {
        path.relative_to(work).as_posix(): path.stat().st_mtime_ns
        for path in work.rglob("*")
        if path.is_file() and path.relative_to(work).parts[0] != ".git"
    }

    class FakeBuild:
        ok = True

        def to_dict(self) -> dict:
            return {"ok": True, "skipped": True}

    saved_run_build = server_module.run_build
    server_module.run_build = lambda *args, **kwargs: FakeBuild()
    try:
        built = app.do_build(site, skip_mirror=True)
    finally:
        server_module.run_build = saved_run_build
    after_mtimes = {
        path.relative_to(work).as_posix(): path.stat().st_mtime_ns
        for path in work.rglob("*")
        if path.is_file() and path.relative_to(work).parts[0] != ".git"
    }
    check(
        "6. a skip_mirror build succeeds without assembling",
        built.get("ok") is True and built.get("mirror") is None,
        str(built)[:300],
    )
    check(
        "🔒 6. do_build no longer repairs the drifted commit tree (bytes and mtimes unchanged)",
        snapshot() == before and after_mtimes == before_mtimes,
        f"{sorted(after_mtimes)}",
    )

    # ── 7. rebuild_work keeps the source guard; init is the only bootstrap exception ──────────
    for path in sorted(src.rglob("*")):
        if path.is_file():
            path.unlink()
    before = snapshot()
    rebuilt = app.rebuild_work(site)
    check(
        "7. rebuild_work(allow_empty_source=False) refuses an empty source/",
        rebuilt.get("ok") is False
        and rebuilt.get("rebuilt") is False
        and rebuilt.get("error") == "SOURCE_EMPTY",
        str(rebuilt)[:300],
    )
    check("7. 🔒 rebuild_work did not clear the commit tree", snapshot() == before, str(wtree(work)))
    verdict = app.verify_state_files()
    check(
        "7. the startup check returns the refused rebuild instead of a repair",
        verdict.get("ok") is False and verdict.get("rebuilt") is False and verdict.get("error") == "SOURCE_EMPTY",
        str(verdict)[:300],
    )
    check("7. 🔒 the startup check wrote nothing either", snapshot() == before, str(wtree(work)))


def test_no_implicit_mirror(tmp: Path) -> None:
    """Mirroring is explicit: a save and a publish leave the trees alone.

    Two implicit assemblies used to run on their own. The debounce scheduler cleared and rebuilt
    both trees after every save, so a brand new author - a uid that ``online/``, ``pending/``,
    ``site.authors`` and the request all fail to name - had the file it had just written deleted by
    the round that was meant to publish it, and every later save was wiped the same way. A publish
    assembled as well, right before the commit. The only explicit entry points are Apply source
    (``do_build`` / ``apply_source``), which maintains the skeleton, and init.
    """
    print("\n[42] mirroring is explicit: a save and a publish never assemble the trees")
    from pages_bridge import server as server_module
    from pages_bridge.server import App

    class OneRound:
        """The scheduler's stop event: one loop body runs, the next ``wait`` ends the loop."""

        def __init__(self) -> None:
            self.waits = 0

        def wait(self, timeout: float) -> bool:  # noqa: ANN001 - the threading.Event shape
            self.waits += 1
            return self.waits > 1

    class FakeBuild:
        ok = True

        def to_dict(self) -> dict:
            return {"ok": True, "skipped": True}

    root = tmp / "explicit-mirror"
    nc_root = root / "ncdata"
    cfg = load_config(_author_site_config(root, nc_root, "", None, author_root="authors-src"))
    site = cfg.site
    write(site.source_dir, "_config.yml", "title: t\n")
    app = App(cfg)
    calls = {"mirror": 0, "build": 0}
    real_mirror = app.do_mirror
    real_run_build = server_module.run_build

    def spy_mirror(*args, **kwargs):  # noqa: ANN002, ANN003 - a transparent spy
        calls["mirror"] += 1
        return real_mirror(*args, **kwargs)

    def fake_run_build(site_arg, cfg_arg, state_arg, *, tree="preview"):  # noqa: ANN001 - the real shape
        calls["build"] += 1
        return FakeBuild()

    def run_one_round(rounds: int = 1) -> None:
        for _ in range(rounds):
            app._stop = OneRound()  # type: ignore[assignment]
            app.runtime.onlinepreview_dirty_at = None
            app.runtime.dirty_at = time.monotonic() - 3600
            app._scheduler_loop()

    app.do_mirror = spy_mirror  # type: ignore[method-assign]
    server_module.run_build = fake_run_build
    try:
        # ── 1. one scheduler round: no do_mirror, exactly one run_build ──────────────────────────
        run_one_round()
        check(
            "🔒 the debounced round mirrors nothing",
            calls["mirror"] == 0,
            f"do_mirror was called {calls['mirror']} times",
        )
        check(
            "the debounced round still builds the preview exactly once",
            calls["build"] == 1,
            f"run_build was called {calls['build']} times",
        )
        check("the round consumed the dirty mark", app.runtime.dirty_at is None, str(app.runtime.dirty_at))

        # ── 2. a brand new author: the save survives the round it triggers ──────────────────────
        target = "authors-src/dana/draft.md"
        write(nc_root / "dana" / "files" / "Pages", "draft.md", "dana's first draft\n")
        check(
            "dana is in no record before her first save",
            "dana" not in app.author_uids(site) and site.authors == (),
            f"{app.author_uids(site)} / {site.authors}",
        )
        saved = app.do_author_delta(
            site, {"op": "add", "uid": "dana", "area": "Pages", "target": target}
        )
        check(
            "the save writes the draft into the preview tree and schedules a build",
            saved.get("ok") is True
            and saved.get("tree") == "preview"
            and (site.preview / target).is_file(),
            str(saved)[:300],
        )
        builds_before = calls["build"]
        run_one_round()
        check(
            "🔒 the draft is still in the preview tree after the debounced round",
            (site.preview / target).is_file()
            and (site.preview / target).read_text(encoding="utf-8") == "dana's first draft\n",
            str(sorted(p.relative_to(site.preview).as_posix() for p in site.preview.rglob("*") if p.is_file())),
        )
        check(
            "the save's round only built - one run_build, no mirror",
            calls["mirror"] == 0 and calls["build"] == builds_before + 1,
            f"mirror={calls['mirror']} build={calls['build']}",
        )

        # ── 5. Apply source keeps an author folder no record names (the preview fallback) ───────
        # A separate fixture: the point is a preview tree that holds the folder while nothing else
        # names the uid - the state a save leaves behind, before the first candidate.
        root5 = tmp / "explicit-mirror-fallback"
        nc5 = root5 / "ncdata"
        site_root5 = nc5 / "alice" / "files" / "Sites" / "t" / "source"
        write(site_root5, "_config.yml", "title: t\n")
        write(site_root5, "authors-src/common/x.md", "handed over to the orphan owner\n")
        write(nc5 / "dana" / "files" / "Pages", "draft.md", "dana's first draft\n")
        cfg5 = load_config(_author_site_config(root5, nc5, "", None, author_root="authors-src"))
        site5 = cfg5.site
        app5 = App(cfg5)
        write(site5.preview, "authors-src/dana/draft.md", "dana's first draft\n")
        check(
            "the fallback fixture names dana nowhere",
            "dana" not in app5.author_uids(site5) and site5.authors == ()
            and not (site5.online_dir / "authors-src").exists()
            and not (site5.pending_dir / "authors-src").exists(),
            f"{app5.author_uids(site5)}",
        )
        applied = app5.apply_source(site5, {"build": False})
        check(
            "Apply source discovers the author folder the preview tree already holds",
            applied.get("ok") is True and applied.get("authors") == ["dana:Pages"],
            str(applied.get("authors")),
        )
        check(
            "🔒 the never-registered author's draft survives Apply source",
            (site5.preview / "authors-src/dana/draft.md").is_file()
            and (site5.preview / "authors-src/dana/draft.md").read_text(encoding="utf-8")
            == "dana's first draft\n",
            str(sorted(p.relative_to(site5.preview).as_posix() for p in site5.preview.rglob("*") if p.is_file())),
        )
        check(
            "🔒 the orphan owner's directory stays skeleton content, not an author layer",
            "common:Pages" not in (applied.get("authors") or [])
            and (site5.preview / "authors-src/common/x.md").read_text(encoding="utf-8")
            == "handed over to the orphan owner\n",
            str(applied.get("authors")),
        )

        # ── 3./4. publish commits without assembling; Apply source's build still assembles ──────
        if shutil.which("git") is None or not _capture_available():
            print("  SKIP  no usable git in this environment (the publish/build checks)")
            return
        from pages_bridge import gitops

        init = app.do_init(site)
        check(
            "init assembles the trees (the explicit entry point)",
            init.get("ok") is True and gitops.is_repo(site.work),
            str(init)[:300],
        )
        for key, value in (("user.email", "t@example.invalid"), ("user.name", "t")):
            subprocess.run(
                ["git", "config", key, value], cwd=str(site.work),
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False,
            )
        write(site.source_dir, "owner.md", "the owner's change\n")
        app.apply_source(site, {"build": False})
        baseline_mirror = calls["mirror"]

        published = app.do_publish(site, "publish without a mirror")
        check(
            "the publish committed",
            published.get("ok") is True and bool(published.get("commit")),
            str(published)[:300],
        )
        check(
            "🔒 a publish runs no assembly",
            calls["mirror"] == baseline_mirror,
            f"do_mirror calls went {baseline_mirror} -> {calls['mirror']}",
        )
        check(
            "the publish answer carries no mirror round",
            "mirror" not in published and "authors" not in published,
            str(sorted(published)),
        )
        committed = gitops.run(site.work, ["show", "--name-only", "--pretty=format:", "HEAD"], check=False).stdout.split()
        check(
            "the commit is the flat tree Apply source assembled",
            "owner.md" in committed,
            str(committed),
        )

        baseline_build = calls["build"]
        built = app.do_build(site)
        check(
            "🔒 Apply source's build still assembles: do_build calls do_mirror",
            calls["mirror"] == baseline_mirror + 1,
            f"do_mirror calls went {baseline_mirror} -> {calls['mirror']}",
        )
        check(
            "do_build reports the assembly it ran",
            isinstance(built.get("mirror"), dict) and built["mirror"].get("ok") is True,
            str(built.get("mirror"))[:300],
        )
        check(
            "do_build built the preview once",
            calls["build"] == baseline_build + 1,
            f"run_build calls went {baseline_build} -> {calls['build']}",
        )
        check(
            "🔒 the assembly kept the author folder the preview tree holds",
            (site.preview / target).is_file(),
            str(sorted(p.relative_to(site.preview).as_posix() for p in site.preview.rglob("*") if p.is_file())),
        )
    finally:
        server_module.run_build = real_run_build
        app.do_mirror = real_mirror  # type: ignore[method-assign]


def test_take_down_source_fallback(tmp: Path) -> None:
    """A take-down removes the published overlay, never the skeleton file it merely hid.

    The commit tree is the ``source/`` + ``online/`` assembly, and ``online/`` wins over ``source/``
    for the same path. The take-down used to delete the tree copy unconditionally, so a page that
    existed in both layers took the skeleton file with it: ``assembly_drift`` immediately reported
    ``missing=1`` and the next publish refused the tree with ``WORK_DRIFT``. The lower layer has to
    surface again - and it has to obey the skeleton exclusion while doing so.
    """
    print("\n[44] take-down falls back to source/: the overlay only hid the skeleton file")
    from pages_bridge.server import App

    root = tmp / "take-down-fallback"
    nc_root = root / "ncdata"
    cfg = load_config(_author_site_config(root, nc_root, "", None))
    site = cfg.site
    app = App(cfg)
    target = "tmp/bob/hello.md"
    # the same path exists in source/ (the skeleton) and in online/ (the published overlay)
    write(site.source_dir, "_config.yml", "title: t\n")
    write(site.source_dir, target, "from the skeleton\n")
    write(site.online_dir, target, "published from bob\n")
    write(site.online_dir, "tmp/bob/gone.md", "published, with no skeleton file below\n")

    rebuilt = app.rebuild_work(site)
    check("the fixture assembled the commit tree", rebuilt.get("ok") is True, str(rebuilt)[:200])
    app._refresh_onlinepreview(site)
    check(
        "the published overlay wins in both trees before the take-down",
        wfile(site.work, target).read_text(encoding="utf-8") == "published from bob\n"
        and (site.onlinepreview / target).read_text(encoding="utf-8") == "published from bob\n",
        f"{wfile(site.work, target).read_text(encoding='utf-8')!r} / "
        f"{(site.onlinepreview / target).read_text(encoding='utf-8')!r}",
    )
    check("the fixture starts without drift", app.assembly_drift(site) is None, str(app.assembly_drift(site))[:300])

    taken = app.do_author_delta(
        site, {"op": "take-down", "uid": "bob", "area": "Pages", "target": target}
    )
    check(
        "the take-down answers ok and names the target",
        taken.get("ok") is True and taken.get("applied") == [target],
        str(taken)[:300],
    )
    check("the published copy left online/", not (site.online_dir / target).exists(), str(site.online_dir / target))
    check(
        "the commit tree keeps the source/ bytes (the overlay was hiding them)",
        wfile(site.work, target).is_file()
        and wfile(site.work, target).read_text(encoding="utf-8") == "from the skeleton\n",
        wfile(site.work, target).read_text(encoding="utf-8")
        if wfile(site.work, target).is_file()
        else "missing from the commit tree",
    )
    check(
        "the review tree keeps the source/ bytes too",
        (site.onlinepreview / target).is_file()
        and (site.onlinepreview / target).read_text(encoding="utf-8") == "from the skeleton\n",
        str(target),
    )
    check(
        "🔒 the commit tree is still exactly the source/ + online/ assembly",
        app.assembly_drift(site) is None,
        str(app.assembly_drift(site))[:400],
    )

    # no skeleton file below the published copy: both trees lose the page
    gone = "tmp/bob/gone.md"
    taken2 = app.do_author_delta(
        site, {"op": "take-down", "uid": "bob", "area": "Pages", "target": gone}
    )
    check(
        "a page with no skeleton file is removed from both trees",
        taken2.get("applied") == [gone]
        and not wfile(site.work, gone).exists()
        and not (site.onlinepreview / gone).exists(),
        str(taken2)[:300],
    )
    check("🔒 and the commit tree is still the assembly", app.assembly_drift(site) is None, str(app.assembly_drift(site))[:400])

    # a source path the skeleton exclusion keeps out of the assembly is **not** a fallback: the
    # path was only in the trees through online/, and `.git` is always excluded (MINIMAL_EXCLUDE)
    excluded = "tmp/bob/.git/notes.md"
    write(site.source_dir, excluded, "skeleton bytes the assembly never carries\n")
    write(site.online_dir, excluded, "published bytes\n")
    app.rebuild_work(site)
    app._refresh_onlinepreview(site)
    check(
        "the excluded path is in the trees only through online/",
        wfile(site.work, excluded).is_file()
        and app._skeleton_exclusion(site).matches(Path(excluded).parts),
        f"in work={wfile(site.work, excluded).is_file()} "
        f"excluded={app._skeleton_exclusion(site).matches(Path(excluded).parts)}",
    )
    taken3 = app.do_author_delta(
        site, {"op": "take-down", "uid": "bob", "area": "Pages", "target": excluded}
    )
    check(
        "🔒 an excluded source path is not resurrected by the take-down",
        taken3.get("ok") is True
        and not wfile(site.work, excluded).exists()
        and not (site.onlinepreview / excluded).exists(),
        f"{str(taken3)[:200]} / work={wfile(site.work, excluded).exists()}",
    )
    check(
        "🔒 still no drift after the excluded take-down",
        app.assembly_drift(site) is None,
        str(app.assembly_drift(site))[:400],
    )


def test_author_overwrite_removes_strays(tmp: Path) -> None:
    """The overwrite removes strays without a delete budget: the tree it repairs is derived.

    The defect this pins down: the overwrite reused the mirror's delete guard, so an empty manifest
    over 21 strays raised MIRROR_DELETE_GUARD and deleted nothing. The strays are exactly the
    leftovers of deleted accounts, and the preview tree is derived from the authors' Nextcloud
    files (a wrong removal is rebuilt by the next overwrite), so the guard only stopped the
    cleanup: the app never recorded the round as done, every request ran it again (110 times in
    the incident log), and one DAV delete request ended in a 500. The guard stays on the
    mirror / Apply source trees, which become git content.
    """
    print("\n[45] author overwrite: strays are removed without a delete budget")
    from pages_bridge.server import App

    root = tmp / "overwrite-guard"
    nc_root = root / "ncdata"
    cfg = load_config(_author_site_config(root, nc_root, "", None))
    site = cfg.site
    app = App(cfg)
    prefix = f"{site.author_root}/bob/"
    # 21 strays, two of them nested: the emptied subdirectories must be pruned as well
    for index in range(21):
        sub = "" if index < 19 else ("nested/" if index == 19 else "nested/deeper/")
        write(site.preview, f"{prefix}{sub}page-{index:02d}.md", f"page {index}\n")

    emptied = app.do_author_delta(
        site, {"op": "overwrite", "authors": [{"uid": "bob", "area": "Pages", "entries": []}]}
    )
    check(
        "an empty manifest over 21 strays raises nothing and answers removed=21",
        emptied.get("ok") is True and emptied.get("removed") == 21,
        str(emptied)[:300],
    )
    remaining = sorted(
        path.name for path in (site.preview / site.author_root / "bob").rglob("*") if path.is_file()
    )
    check("🔒 every stray is gone", not remaining, str(remaining)[:300])
    check(
        "the emptied subdirectories are pruned, the author directory stays",
        not (site.preview / site.author_root / "bob" / "nested").exists()
        and (site.preview / site.author_root / "bob").is_dir(),
        str(sorted(path.name for path in (site.preview / site.author_root / "bob").iterdir())),
    )
    check(
        "a successful round schedules the preview build",
        app.runtime.dirty_at is not None,
        str(app.runtime.dirty_at),
    )

    again = app.do_author_delta(
        site, {"op": "overwrite", "authors": [{"uid": "bob", "area": "Pages", "entries": []}]}
    )
    check(
        "a repeat round has nothing to remove and still succeeds",
        again.get("ok") is True and again.get("removed") == 0,
        str(again)[:300],
    )

    # the manifest is still the last word on what stays: listed targets survive, the rest is removed
    for index in range(3):
        write(site.preview, f"{prefix}page-{index:02d}.md", f"page {index}\n")
    keep = [f"{prefix}page-{index:02d}.md" for index in range(2)]
    overwritten = app.do_author_delta(
        site,
        {"op": "overwrite", "authors": [{"uid": "bob", "area": "Pages", "entries": keep}]},
    )
    check(
        "a round with entries removes only the stray and answers removed=1",
        overwritten.get("ok") is True
        and overwritten.get("removed") == 1
        and not (site.preview / f"{prefix}page-02.md").exists(),
        str(overwritten)[:300],
    )
    kept = [path for path in (site.preview / site.author_root / "bob").rglob("*") if path.is_file()]
    check(
        "every kept target is still there",
        len(kept) == 2,
        str(sorted(path.name for path in kept)),
    )


def test_author_overwrite_area(tmp: Path) -> None:
    """The overwrite's ``area`` is a path component of the author's own folder: no traversal.

    The defect this pins down: ``area`` was pasted into a path and the containment check compared
    that path with itself, so ``area="../../victim/files/Pages"`` read another account's file. The
    author-delta route already refused exactly that shape with a 400.
    """
    print("\n[46] author overwrite: area is validated before it builds a path")
    from pages_bridge.server import App

    root = tmp / "overwrite-area"
    nc_root = root / "ncdata"
    cfg = load_config(_author_site_config(root, nc_root, "", None))
    site = cfg.site
    app = App(cfg)
    target = f"{site.author_root}/bob/secret.md"
    # bob's own file, and the same relative name under another account's folder
    write(nc_root / "bob" / "files" / "Pages", "secret.md", "bob's own\n")
    write(nc_root / "victim" / "files" / "Pages", "secret.md", "another account's file\n")

    def overwrite(area: str) -> dict:
        return app.do_author_delta(
            site,
            {"op": "overwrite", "authors": [{"uid": "bob", "area": area, "entries": [target]}]},
        )

    for area in ("../../victim/files/Pages", "/Pages", "../Pages", "Pages/../../victim"):
        try:
            overwrite(area)
            check(f"the overwrite refuses area={area!r}", False, "no exception")
        except BadRequest as exc:
            check(
                f"the overwrite refuses area={area!r} with 'area must be a folder name'",
                "area must be a folder name" in exc.message,
                exc.message,
            )
    check(
        "🔒 no other account's bytes reached the preview tree",
        not (site.preview / target).exists(),
        str(site.preview / target),
    )
    ok = overwrite("Pages")
    check(
        "a normal area still works and reads bob's own file",
        ok.get("ok") is True
        and (site.preview / target).read_text(encoding="utf-8") == "bob's own\n",
        (site.preview / target).read_text(encoding="utf-8")
        if (site.preview / target).is_file()
        else "missing from the preview tree",
    )


def test_layer_files_symlink(tmp: Path) -> None:
    """A symlink in a layer is not site content: copying it would pull a host file into the trees.

    The defect this pins down: ``is_file()`` follows a link, so ``source/link.md -> /etc/hostname``
    was a layer file and ``_copy_layer`` copied the host file into all three trees (and a publish
    could commit it). A linked directory was already safe because ``rglob`` does not descend into
    it; the readable file link was the hole.
    """
    print("\n[47] layer files: a symlink is never site content")
    from pages_bridge.server import App

    root = tmp / "layer-symlink"
    nc_root = root / "ncdata"
    cfg = load_config(_author_site_config(root, nc_root, "", None))
    app = App(cfg)
    layer = root / "layer"
    write(layer, "keep.md", "ordinary\n")
    # the host file a link would leak; /etc/hostname on POSIX, a local stand-in elsewhere
    link_target = Path("/etc/hostname")
    if os.name == "nt" or not link_target.is_file():
        write(root, "host-secret.txt", "host secret\n")
        link_target = root / "host-secret.txt"
    link = layer / "link.md"
    try:
        link.symlink_to(link_target)
    except (OSError, NotImplementedError):
        print("  SKIP  symlinks are unavailable in this environment")
        return
    found = app._layer_files(layer)
    check("_layer_files: the symlink is not a layer file", "link.md" not in found, str(sorted(found)))
    check("_layer_files: the ordinary file is still there", "keep.md" in found, str(sorted(found)))
    destination = root / "copied"
    app._copy_layer(layer, destination)
    check(
        "🔒 the host file behind the link never reaches the tree",
        not (destination / "link.md").exists(),
        str(sorted(p.name for p in destination.rglob("*"))) if destination.is_dir() else "missing",
    )
    check("and the ordinary file was copied", (destination / "keep.md").is_file(), str(destination / "keep.md"))


def test_author_root_move(tmp: Path) -> None:
    """Changing the author directory carries the published and pending content with it.

    The defect this pins down: the configuration was swapped while ``online/`` and ``pending/`` kept
    the old targets. Those pages stayed served from an address no review action accepts any more
    (``_author_target_ok`` refuses them), the app only re-keyed its own entries, and the old name was
    permanently online. The move rewrites the targets under the new prefix and rebuilds the derived
    trees, so ``work`` and ``onlinepreview`` follow immediately.
    """
    print("\n[48] author content directory: the move carries online/ and pending/ with it")
    from pages_bridge.server import App
    from pages_bridge.util import AuthorRootMoveFailed

    root = tmp / "author-root-move"
    nc_root = root / "ncdata"
    cfg = load_config(_author_site_config(root, nc_root, "", None))
    site = cfg.site
    app = App(cfg)
    write(site.source_dir, "_config.yml", "title: t\n")
    write(site.online_dir, "tmp/bob/one.md", "published one\n")
    write(site.online_dir, "tmp/bob/two.md", "published two\n")
    write(site.pending_dir, "tmp/carol/one.md", "waiting for review\n")
    # stale trees from before the change: the old prefix must not survive the move
    write(wfile(site.work), "tmp/bob/stale.md", "old commit tree\n")
    write(site.onlinepreview, "tmp/bob/stale.md", "old review tree\n")

    moved = app.set_author_root({"author_root": "people"})
    new_site = app.cfg.site
    check(
        "the move answers with the new root and the per-directory counts",
        moved.get("ok") is True
        and moved.get("changed") is True
        and moved.get("exists") is False
        and moved.get("moved") == {"online": 2, "pending": 1}
        and new_site is not None
        and new_site.author_root == "people",
        str(moved)[:300],
    )
    check(
        "the published files are under the new prefix, byte for byte",
        (new_site.online_dir / "people/bob/one.md").read_text(encoding="utf-8") == "published one\n"
        and (new_site.online_dir / "people/bob/two.md").read_text(encoding="utf-8") == "published two\n",
        str(sorted(p.relative_to(new_site.online_dir).as_posix() for p in new_site.online_dir.rglob("*") if p.is_file())),
    )
    check(
        "🔒 no published file stayed under the old name",
        not (new_site.online_dir / "tmp/bob/one.md").exists()
        and not (new_site.online_dir / "tmp/bob/two.md").exists(),
        str(sorted(p.relative_to(new_site.online_dir).as_posix() for p in new_site.online_dir.rglob("*") if p.is_file())),
    )
    check(
        "the waiting candidate moved too",
        (new_site.pending_dir / "people/carol/one.md").read_text(encoding="utf-8")
        == "waiting for review\n"
        and not (new_site.pending_dir / "tmp/carol/one.md").exists(),
        str(sorted(p.relative_to(new_site.pending_dir).as_posix() for p in new_site.pending_dir.rglob("*") if p.is_file())),
    )
    rebuild = moved.get("work_rebuild") or {}
    check(
        "the commit tree was rebuilt after the move",
        rebuild.get("ok") is True and rebuild.get("rebuilt") is True,
        str(rebuild)[:300],
    )
    check(
        "the commit tree holds the new prefix and lost the old one",
        wfile(new_site.work, "people/bob/one.md").is_file()
        and wfile(new_site.work, "people/bob/two.md").is_file()
        and not wfile(new_site.work, "tmp/bob/one.md").exists(),
        str(wtree(new_site.work)),
    )
    check(
        "the review tree holds the new prefix and lost the old one",
        (new_site.onlinepreview / "people/bob/one.md").is_file()
        and (new_site.onlinepreview / "people/carol/one.md").is_file()
        and not (new_site.onlinepreview / "tmp/bob/one.md").exists(),
        str(sorted(p.relative_to(new_site.onlinepreview).as_posix() for p in new_site.onlinepreview.rglob("*") if p.is_file())),
    )
    check(
        "🔒 the commit tree is the source/ + online/ assembly again",
        app.assembly_drift(new_site) is None,
        str(app.assembly_drift(new_site))[:400],
    )
    check(
        "the move scheduled the review round",
        app.runtime.onlinepreview_dirty_at is not None,
        str(app.runtime.onlinepreview_dirty_at),
    )
    check(
        "the stored author blocks are dropped (the app republishes them under the new root)",
        new_site.authors == (),
        str(new_site.authors),
    )

    back = app.set_author_root({"author_root": "tmp"})
    check(
        "moving back carries the content the other way",
        back.get("moved") == {"online": 2, "pending": 1}
        and app.cfg.site.author_root == "tmp"
        and (site.online_dir / "tmp/bob/one.md").is_file()
        and not (site.online_dir / "people/bob/one.md").exists(),
        str(back)[:300],
    )

    # nothing to carry: the change is a plain configuration update with zero counters
    empty_root = tmp / "author-root-empty"
    empty_nc = empty_root / "ncdata"
    empty_cfg = load_config(_author_site_config(empty_root, empty_nc, "", None))
    empty_app = App(empty_cfg)
    write(empty_cfg.site.source_dir, "_config.yml", "title: t\n")
    plain = empty_app.set_author_root({"author_root": "people"})
    check(
        "an empty author area moves with zero counters",
        plain.get("ok") is True
        and plain.get("moved") == {"online": 0, "pending": 0}
        and empty_app.cfg.site is not None
        and empty_app.cfg.site.author_root == "people",
        str(plain)[:300],
    )

    # a move that fails must roll back: the configuration is not adopted, so the app can retry the
    # same request instead of hitting the "already changed" early return with old-prefix files left
    # behind as site content nobody names
    fail_root = tmp / "author-root-move-failed"
    fail_nc = fail_root / "ncdata"
    fail_cfg = load_config(_author_site_config(fail_root, fail_nc, "", None))
    fail_app = App(fail_cfg)
    write(fail_cfg.site.source_dir, "_config.yml", "title: t\n")
    for name in ("a.md", "b.md", "c.md"):
        write(fail_cfg.site.online_dir, f"tmp/bob/{name}", f"published {name}\n")
    real_write_nc = fail_app._write_nc_file

    def flaky_write_nc(path, content):
        if path.name == "c.md":
            raise OSError(5, "simulated write failure")
        return real_write_nc(path, content)

    fail_app._write_nc_file = flaky_write_nc  # type: ignore[method-assign]
    try:
        fail_app.set_author_root({"author_root": "people"})
        check("a file that cannot be moved -> AUTHOR_ROOT_MOVE_FAILED", False, "no exception")
    except AuthorRootMoveFailed as exc:
        payload = exc.to_dict()
        check(
            "a file that cannot be moved -> 409 AUTHOR_ROOT_MOVE_FAILED",
            exc.code == "AUTHOR_ROOT_MOVE_FAILED" and exc.http == 409,
            str(payload)[:300],
        )
        check(
            "the failure details carry the counts, the failed file and the leftovers",
            payload.get("moved") == {"online": 2, "pending": 0}
            and payload.get("failed") == ["tmp/bob/c.md"]
            and payload.get("remaining") == ["tmp/bob/a.md", "tmp/bob/b.md", "tmp/bob/c.md"]
            and payload.get("rollback_failed") == [],
            str(payload)[:500],
        )
    check(
        "🔒 the failed move did not adopt the new root",
        fail_app.cfg.site is not None and fail_app.cfg.site.author_root == "tmp",
        str(fail_app.cfg.site.author_root if fail_app.cfg.site is not None else None),
    )
    check(
        "🔒 and wrote no configuration at all",
        not fail_app.pushed_config_path.exists(),
        str(fail_app.pushed_config_path),
    )
    check(
        "🔒 the two files that had moved are back under the old name",
        (fail_cfg.site.online_dir / "tmp/bob/a.md").is_file()
        and (fail_cfg.site.online_dir / "tmp/bob/a.md").read_text(encoding="utf-8") == "published a.md\n"
        and (fail_cfg.site.online_dir / "tmp/bob/b.md").is_file(),
        str(sorted(p.relative_to(fail_cfg.site.online_dir).as_posix() for p in fail_cfg.site.online_dir.rglob("*") if p.is_file())),
    )
    leftovers = (
        sorted(
            path.relative_to(fail_cfg.site.online_dir).as_posix()
            for path in (fail_cfg.site.online_dir / "people").rglob("*")
            if path.is_file()
        )
        if (fail_cfg.site.online_dir / "people").is_dir()
        else []
    )
    check("🔒 no new-prefix file stayed in online/", leftovers == [], str(leftovers))
    fail_app._write_nc_file = real_write_nc  # type: ignore[method-assign]
    retried = fail_app.set_author_root({"author_root": "people"})
    check(
        "a retry after the rollback moves the whole set and adopts the new root",
        retried.get("moved") == {"online": 3, "pending": 0}
        and fail_app.cfg.site is not None
        and fail_app.cfg.site.author_root == "people"
        and (fail_cfg.site.online_dir / "people/bob/a.md").is_file(),
        str(retried)[:300],
    )


def test_author_root_move_prune(tmp: Path) -> None:
    """[60] an author-root move leaves no empty folder skeleton behind under the old name.

    ``_delete_nc_file`` only unlinks the file, so the move used to empty ``online/<old_root>/`` and
    ``pending/<old_root>/`` without removing a single directory: the old author area kept showing up
    in Nextcloud as an empty ``<uid>/<area>/…`` chain (and the old root itself) after all of its
    content had moved. The success path now prunes the emptied chain bottom-up. Only **empty**
    directories go - ``rmdir`` refuses anything that still holds a file, so a stray this round did
    not move keeps its folder - and ``online/``/``pending/`` themselves are never touched.
    """
    print("\n[60] an author-root move prunes the old prefix's empty directories")
    from pages_bridge.server import App

    root = tmp / "author-root-prune"
    nc_root = root / "ncdata"
    cfg = load_config(_author_site_config(root, nc_root, "", None))
    site = cfg.site
    app = App(cfg)
    write(site.source_dir, "_config.yml", "title: t\n")
    write(site.online_dir, "index.md", "site content, not author content\n")
    write(site.online_dir, "tmp/bob/posts/deep/one.md", "deep one\n")
    write(site.online_dir, "tmp/bob/two.md", "two\n")
    write(site.online_dir, "tmp/carol/three.md", "three\n")
    write(site.pending_dir, "tmp/bob/waiting.md", "waiting\n")
    (site.online_dir / "tmp/ghost").mkdir(parents=True, exist_ok=True)
    (site.pending_dir / "tmp/ghost").mkdir(parents=True, exist_ok=True)

    moved = app.set_author_root({"author_root": "people"})
    new_site = app.cfg.site
    check(
        "the move carried every file under the new prefix",
        moved.get("ok") is True and moved.get("moved") == {"online": 3, "pending": 1},
        str(moved)[:300],
    )
    check(
        "🔒 the new prefix holds the files byte for byte",
        (site.online_dir / "people/bob/posts/deep/one.md").read_text(encoding="utf-8") == "deep one\n"
        and (site.online_dir / "people/bob/two.md").read_text(encoding="utf-8") == "two\n"
        and (site.online_dir / "people/carol/three.md").read_text(encoding="utf-8") == "three\n"
        and (site.pending_dir / "people/bob/waiting.md").read_text(encoding="utf-8") == "waiting\n",
        str(sorted(p.relative_to(site.online_dir).as_posix() for p in site.online_dir.rglob("*") if p.is_file())),
    )
    check(
        "🔒 no empty directory of the old prefix is left in online/",
        not (site.online_dir / "tmp").exists(),
        str(sorted(p.relative_to(site.online_dir).as_posix() for p in site.online_dir.rglob("*") if p.is_dir())),
    )
    check(
        "🔒 nor in pending/ (the old root itself included)",
        not (site.pending_dir / "tmp").exists(),
        str(sorted(p.relative_to(site.pending_dir).as_posix() for p in site.pending_dir.rglob("*") if p.is_dir())),
    )
    check(
        "🔒 online/ and pending/ themselves survive the prune",
        site.online_dir.is_dir() and site.pending_dir.is_dir(),
        f"{site.online_dir.is_dir()} / {site.pending_dir.is_dir()}",
    )
    check(
        "🔒 content outside the old author root was not touched",
        (site.online_dir / "index.md").read_text(encoding="utf-8") == "site content, not author content\n",
        str(sorted(p.relative_to(site.online_dir).as_posix() for p in site.online_dir.rglob("*") if p.is_file())),
    )
    check(
        "the new site is in force and its trees were rebuilt",
        new_site is not None and new_site.author_root == "people"
        and (new_site.work / "people/bob/two.md").is_file()
        and not (new_site.work / "tmp/bob/two.md").exists(),
        str(sorted(p.relative_to(new_site.work).as_posix() for p in new_site.work.rglob("*") if p.is_file())[:20])
        if new_site is not None else "no site",
    )

    # a directory a file still lives in is not empty and must survive: an interrupted write's
    # temporary file is skipped by `_layer_files`, so the move leaves it where it was
    keep_root = tmp / "author-root-prune-keep"
    keep_nc = keep_root / "ncdata"
    keep_cfg = load_config(_author_site_config(keep_root, keep_nc, "", None))
    keep_site = keep_cfg.site
    keep_app = App(keep_cfg)
    write(keep_site.source_dir, "_config.yml", "title: t\n")
    write(keep_site.online_dir, "tmp/bob/one.md", "published one\n")
    residue = write(keep_site.online_dir, "tmp/bob/notes/.page.md.tmp12345", "half-written\n")
    keep_app.set_author_root({"author_root": "people"})
    check(
        "🔒 a file the round did not move keeps its directory (only empty directories are pruned)",
        residue.is_file()
        and (keep_site.online_dir / "tmp/bob/notes").is_dir()
        and (keep_site.online_dir / "people/bob/one.md").is_file(),
        str(sorted(p.relative_to(keep_site.online_dir).as_posix() for p in keep_site.online_dir.rglob("*"))),
    )


def test_site_change_under_lock(tmp: Path) -> None:
    """[53] a queued writer uses the site in force, never the snapshot it validated before waiting.

    The delta/mirror handlers used to read the site before taking ``_site_lock()``, so a
    ``POST /site/reset`` or a configuration push that replaced the site while the request waited made
    it keep writing the **old** site's trees. The operations that take their own lock could not
    re-read at all, so they now refuse a replaced site with ``409 SITE_CHANGED``.
    """
    print("\n[53] the site is resolved inside the lock, not before the wait")
    import threading
    from dataclasses import replace

    from pages_bridge.server import build_server
    from pages_bridge.util import Conflict

    root = tmp / "sitechange"
    nc_root = root / "ncdata"
    write(nc_root / "bob" / "files" / "Pages", "a.md", "bob's page\n")
    write(nc_root / "bob" / "files" / "Pages", "b.md", "bob's second page\n")

    def make(name: str):
        """Two independent sites: their own Nextcloud folder **and** their own trees."""
        base = root / name
        (nc_root / "alice" / "files" / "Sites" / name / "source").mkdir(parents=True, exist_ok=True)
        write(nc_root / "alice" / "files" / "Sites" / name / "source", "_config.yml", "title: t\n")
        cfg_path = base / "cfg.toml"
        cfg_path.parent.mkdir(parents=True, exist_ok=True)
        cfg_path.write_text(
            _to_toml({
                "listen": "127.0.0.1:8787",
                "shared_secret": "a" * 32,
                "session_signing_secret": "b" * 32,
                "nc_data_root": str(nc_root),
                "work_root": str(base / "work"),
                "state_dir": str(base / "state"),
                "override_dir": str(base / "overrides"),
                "log_dir": str(base / "logs"),
                "site": {
                    "nc_user": "alice", "nc_path": f"/Sites/{name}",
                    "repo_url": "", "branch": "main",
                    "recipe": {"preset": "jekyll", "output_dir": "."},
                },
            }),
            encoding="utf-8",
        )
        return load_config(cfg_path)

    first = make("first")
    second = make("second")
    target = "tmp/bob/a.md"
    try:
        httpd, app = build_server(first, port=0)
    except OSError as exc:
        print(f"  SKIP  this environment forbids listening on a socket: {exc}")
        return
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, name="test-site-change", daemon=True)
    thread.start()
    secret = first.shared_secret
    try:
        # ── 1. the site is replaced while the request waits: the write lands in the new site ─────
        # `_site_lock` is wrapped so the test knows the handler has already read its snapshot and
        # is waiting for the lock; only then is the site replaced (deterministic, no timing hope).
        entered = threading.Event()
        original_lock = app._site_lock

        @contextlib.contextmanager
        def spy_lock():
            entered.set()
            with original_lock():
                yield

        reply: dict = {}
        app._site_lock = spy_lock  # type: ignore[method-assign]
        app.runtime.lock.acquire()
        worker = threading.Thread(
            target=lambda: reply.update(
                call=_http_call(
                    port, "POST", "/site/author-delta",
                    {"op": "add", "uid": "bob", "area": "Pages", "target": target},
                    secret,
                )
            ),
            name="queued-delta",
            daemon=True,
        )
        try:
            worker.start()
            check("the queued request reached the lock", entered.wait(10), "the handler never took the lock")
            app.cfg = replace(app.cfg, site=second.site)
            app.runtime.site = second.site
        finally:
            app.runtime.lock.release()
        worker.join(timeout=60)
        app._site_lock = original_lock  # type: ignore[method-assign]
        status, body = reply.get("call", (0, {}))
        check("the queued delta answers 200 against the new site", status == 200, f"{status} {str(body)[:200]}")
        check(
            "the delta wrote the site in force's preview tree",
            (second.site.preview / target).is_file(),
            str(second.site.preview / target),
        )
        check(
            "🔒 the delta did not write the old site's preview tree",
            not (first.site.preview / target).exists(),
            str(first.site.preview / target),
        )

        # ── 2. the site is gone while the request waits: 409 SITE_CHANGED ─────────────────────────
        entered.clear()
        reply.clear()
        second_target = "tmp/bob/b.md"
        app._site_lock = spy_lock  # type: ignore[method-assign]
        app.runtime.lock.acquire()
        worker = threading.Thread(
            target=lambda: reply.update(
                call=_http_call(
                    port, "POST", "/site/author-delta",
                    {"op": "add", "uid": "bob", "area": "Pages", "target": second_target},
                    secret,
                )
            ),
            name="queued-delta-reset",
            daemon=True,
        )
        try:
            worker.start()
            check("the second queued request reached the lock", entered.wait(10), "the handler never took the lock")
            app.cfg = replace(app.cfg, site=None)
            app.runtime.site = None
        finally:
            app.runtime.lock.release()
        worker.join(timeout=60)
        app._site_lock = original_lock  # type: ignore[method-assign]
        status, body = reply.get("call", (0, {}))
        check("a queued delta whose site was reset answers 409", status == 409, f"{status} {str(body)[:200]}")
        check(
            "the 409 names SITE_CHANGED in details",
            (body.get("details") or {}).get("reason") == "SITE_CHANGED",
            str(body)[:200],
        )
        check(
            "🔒 the reset-time delta wrote nothing",
            not (second.site.preview / second_target).exists(),
            str(second.site.preview / second_target),
        )

        # ── 3. the operations that take their own lock refuse the site they validated ────────────
        app.cfg = replace(app.cfg, site=second.site)
        app.runtime.site = second.site
        for name, call in (
            ("build", lambda: app.do_build(first.site)),
            ("publish", lambda: app.do_publish(first.site, "a publish of the old site")),
        ):
            try:
                call()
                check(f"a {name} refuses the site it validated once the site changed", False, "no exception")
            except Conflict as exc:
                check(
                    f"a {name} refuses the site it validated with 409 SITE_CHANGED",
                    exc.http == 409 and exc.details.get("reason") == "SITE_CHANGED",
                    str(exc.to_dict()),
                )

        # ── 4. a failed one-shot overwrite carries the retry hint (item 6) ───────────────────────
        status, body = _http_call(
            port, "POST", "/site/author-delta",
            {"op": "overwrite", "authors": "not-an-array"},
            secret,
        )
        check(
            "a refused overwrite answers 400",
            status == 400 and body.get("error") == "BAD_REQUEST",
            f"{status} {str(body)[:200]}",
        )
        check(
            "the refused overwrite carries retry_after_ms",
            (body.get("details") or {}).get("retry_after_ms") == 60_000,
            str(body)[:300],
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


def test_atomic_residue(tmp: Path) -> None:
    """[54] an interrupted write's temporary file is not site content, and the cleanup removes it.

    ``atomic_write_bytes`` writes ``.<name>.tmp<pid>`` and renames it; a SIGTERM between the two
    leaves it behind, because the ``finally`` that removes it never runs. It used to be read as a
    layer file (and committed), so ``_layer_files`` now refuses the name and the cleanup pass of init
    and Apply source deletes the residue - only that pattern, only regular files, only under
    ``online/`` and ``pending/``.
    """
    print("\n[54] an interrupted write's temporary file is never site content")
    from pages_bridge.server import App

    root = tmp / "residue"
    nc_root = root / "ncdata"
    cfg = load_config(_author_site_config(root, nc_root, "", None))
    site = cfg.site
    app = App(cfg)
    write(site.source_dir, "_config.yml", "title: t\n")
    write(site.source_dir, ".skeleton.md.tmp3", "the administrator's own file\n")
    write(site.online_dir, "page.md", "published\n")
    write(site.online_dir, ".page.md.tmp1234", "half a write\n")
    write(site.online_dir, "sub/.deep.md.tmp7", "half a write\n")
    write(site.online_dir, "keep.tmp", "not a residue: no leading dot\n")
    write(site.online_dir, ".notes.tmpX", "not a residue: no pid digits\n")
    write(site.pending_dir, ".candidate.md.tmp9", "half a write\n")
    residue_dir = site.online_dir / ".dir.tmp5"
    residue_dir.mkdir()

    files = app._layer_files(site.online_dir)
    check(
        "_layer_files never reads a .<name>.tmp<pid> file",
        ".page.md.tmp1234" not in files and "sub/.deep.md.tmp7" not in files,
        str(sorted(files)),
    )
    check(
        "_layer_files keeps the ordinary files, look-alike names included",
        {"page.md", "keep.tmp", ".notes.tmpX"} <= set(files),
        str(sorted(files)),
    )

    removed = app._sweep_atomic_residue(site)
    check("the sweep removed the three residue files", removed == 3, str(removed))
    check(
        "the residue files are gone",
        not (site.online_dir / ".page.md.tmp1234").exists()
        and not (site.online_dir / "sub/.deep.md.tmp7").exists()
        and not (site.pending_dir / ".candidate.md.tmp9").exists(),
        str(sorted(p.name for p in site.online_dir.rglob("*"))),
    )
    check(
        "🔒 the sweep left the published page and the look-alike names alone",
        (site.online_dir / "page.md").is_file()
        and (site.online_dir / "keep.tmp").is_file()
        and (site.online_dir / ".notes.tmpX").is_file(),
        str(sorted(p.name for p in site.online_dir.rglob("*"))),
    )
    check("🔒 the sweep left source/ alone", (site.source_dir / ".skeleton.md.tmp3").is_file())
    check("🔒 the sweep never removed a directory that matches the name", residue_dir.is_dir())
    check("a clean tree has nothing to sweep", app._sweep_atomic_residue(site) == 0)

    # end to end: what is written after the sweep must not reach a tree either
    write(site.online_dir, ".late.md.tmp42", "half a write\n")
    result = app.apply_source(site, {"build": False})
    check("Apply source rebuilt the trees", result.get("ok") is True and result.get("rebuilt") is True, str(result)[:200])
    residue_in_trees = sorted(
        path.relative_to(tree).as_posix()
        for tree in (site.work, site.preview, site.onlinepreview)
        for path in tree.rglob("*")
        if path.is_file() and re.match(r"\..*\.tmp[0-9]+$", path.name)
    )
    check("no temporary file entered any of the three trees", residue_in_trees == [], str(residue_in_trees))
    check(
        "the ordinary content is in the tree",
        (site.work / "_config.yml").is_file() and (site.work / "page.md").is_file(),
        str(sorted(p.name for p in site.work.rglob("*"))),
    )


def test_init_refused_mirror(tmp: Path) -> None:
    """[55] init tells a refused assembly apart from a successful bootstrap.

    The inner mirror used to be stored under ``detail["mirror"]`` while the answer was always
    ``{"ok": True}`` and the site was marked bootstrapped - so a refused assembly (the delete guard,
    an unreadable source) read as a completed init.
    """
    print("\n[55] init: a refused assembly is not a successful bootstrap")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    from pages_bridge.server import App

    root = tmp / "init-refused"
    nc_root = root / "ncdata"

    def make(name: str, work_files: dict[str, str]):
        base = root / name
        src = nc_root / "alice" / "files" / "Sites" / name / "source"
        src.mkdir(parents=True, exist_ok=True)
        write(src, "_config.yml", "title: t\n")
        for rel, text in work_files.items():
            write(base / "work", rel, text)
        cfg_path = base / "cfg.toml"
        cfg_path.parent.mkdir(parents=True, exist_ok=True)
        cfg_path.write_text(
            _to_toml({
                "listen": "127.0.0.1:8787",
                "shared_secret": "a" * 32,
                "session_signing_secret": "b" * 32,
                "nc_data_root": str(nc_root),
                "work_root": str(base / "work"),
                "state_dir": str(base / "state"),
                "override_dir": str(base / "overrides"),
                "log_dir": str(base / "logs"),
                # a tree that would lose nine of its ten files trips the guard
                "mirror_delete_guard_min": 1,
                "site": {
                    "nc_user": "alice", "nc_path": f"/Sites/{name}",
                    "repo_url": "", "branch": "main",
                    "recipe": {"preset": "jekyll", "output_dir": "."},
                },
            }),
            encoding="utf-8",
        )
        return load_config(cfg_path), src, base / "work"

    # ── the assembly is refused: ok is false, the refusal is at the top level, no bootstrap ─────
    cfg, src, work = make("refused", {f"page{i}.md": f"{i}\n" for i in range(10)})
    app = App(cfg)
    result = app.do_init(cfg.site)
    check("init answers ok:false when the assembly is refused", result.get("ok") is False, str(result)[:300])
    check(
        "the mirror's refusal is at the top level",
        result.get("error") == "MIRROR_DELETE_GUARD" and bool(result.get("message")),
        f"{result.get('error')} {result.get('message')}",
    )
    check(
        "the mirror block is still reported (as the round result it is)",
        (result.get("mirror") or {}).get("ok") is False
        and (result.get("mirror") or {}).get("guard_tripped") is True,
        str(result.get("mirror"))[:200],
    )
    check("🔒 the refused init left the work copy alone", (work / "page0.md").is_file(), str(work))
    check("🔒 a refused init does not mark the site bootstrapped", app.state.bootstrapped is False, str(app.state.data)[:200])

    # ── a normal init keeps its shape and marks the bootstrap ───────────────────────────────────
    cfg2, _src2, work2 = make("clean", {})
    app2 = App(cfg2)
    ok_result = app2.do_init(cfg2.site)
    check("a clean init is still ok:true", ok_result.get("ok") is True, str(ok_result)[:300])
    check("a clean init populates the commit tree", (work2 / "_config.yml").is_file(), str(work2))
    check("a clean init marks the site bootstrapped", app2.state.bootstrapped is True, str(app2.state.data)[:200])


def test_pair_code_before_dirs(tmp: Path) -> None:
    """[56] POST /pair verifies the one-time code before it assembles the site configuration.

    Assembling goes through ``build_site``, which creates the site's ``online/`` and ``pending/``
    directories in the Nextcloud folder. A wrong code used to leave those two empty directories in
    that user's Files before it was refused.
    """
    print("\n[56] pairing: a wrong code creates no directory in the site folder")
    from pages_bridge import pairing
    from pages_bridge.server import App
    from pages_bridge.util import BadPairingCode

    root = tmp / "pairorder"
    nc_root = root / "ncdata"
    site_folder = nc_root / "alice" / "files" / "Sites" / "fresh"
    (site_folder / "source").mkdir(parents=True, exist_ok=True)
    write(site_folder / "source", "_config.yml", "title: t\n")
    state = root / "state"
    state.mkdir(parents=True, exist_ok=True)
    cfg_path = root / "cfg.toml"
    # a fresh install: paths only, no secrets and **no [site] table** (``_to_toml`` always writes
    # one, and an empty table would be refused with "nc_path is required" before pairing can run)
    cfg_path.write_text(
        "\n".join(
            f"{key} = {_toml_value(value)}"
            for key, value in {
                "listen": "127.0.0.1:8787",
                "nc_data_root": str(nc_root),
                "work_root": str(root / "work"),
                "state_dir": str(state),
                "override_dir": str(root / "overrides"),
                "log_dir": str(root / "logs"),
            }.items()
        )
        + "\n",
        encoding="utf-8",
    )
    cfg = load_config(cfg_path)
    check("the fixture starts UNPAIRED without a site", cfg.paired is False and cfg.site is None, f"{cfg.paired} {cfg.site}")
    online = site_folder / "online"
    pending = site_folder / "pending"
    check("the fixture has no online/ or pending/ yet", not online.exists() and not pending.exists())

    expected = pairing.issue(state)
    body = {
        "shared_secret": "s" * 32,
        "session_signing_secret": "t" * 32,
        "nc_data_root": str(nc_root),
        "site": {
            "nc_user": "alice", "nc_path": "/Sites/fresh",
            "repo_url": "", "branch": "main",
            "recipe": {"preset": "jekyll", "output_dir": "."},
        },
    }
    app = App(cfg)
    try:
        app.pair({**body, "code": "ZZZZZZZZ"})
        check("a wrong pairing code is refused", False, "no exception")
    except BadPairingCode:
        check("a wrong pairing code is refused", True)
    check("🔒 a wrong code created no online/", not online.exists(), str(online))
    check("🔒 a wrong code created no pending/", not pending.exists(), str(pending))
    check(
        "🔒 the site folder still holds source/ alone",
        sorted(path.name for path in site_folder.iterdir()) == ["source"],
        str(sorted(path.name for path in site_folder.iterdir())),
    )
    check("the bridge is still UNPAIRED", app.paired is False and app.cfg.site is None, f"{app.paired} {app.cfg.site}")

    result = app.pair({**body, "code": expected.lower()})
    check("the correct code pairs the bridge", result.get("ok") is True and app.paired is True, str(result)[:200])
    check(
        "the correct code creates online/ and pending/",
        online.is_dir() and pending.is_dir(),
        f"{online} {pending}",
    )


def test_skeleton_gitignore(tmp: Path) -> None:
    """[57] the repository's .gitignore decides what the skeleton contributes to the trees.

    ``_skeleton_exclusion`` used to read only ``site.exclude`` and the build artifacts while the
    status/drift view merged the ``.gitignore``, and the TOML example promised the repository decides.
    The rules are read from ``source/`` itself - the skeleton *is* the repository content, and the
    ignored entries do not exist in the commit tree to be listed there.
    """
    print("\n[57] the skeleton honours the repository's .gitignore")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox)")
        return

    from pages_bridge.server import App

    root = tmp / "skeleton-gitignore"
    nc_root = root / "ncdata"

    def make(name: str, **globals_: object):
        """One site fixture; ``globals_`` are extra top-level TOML keys (they must precede [site])."""
        base = root / name
        src = nc_root / "alice" / "files" / "Sites" / name / "source"
        src.mkdir(parents=True, exist_ok=True)
        cfg_path = base / "cfg.toml"
        cfg_path.parent.mkdir(parents=True, exist_ok=True)
        cfg_path.write_text(
            _to_toml({
                "listen": "127.0.0.1:8787",
                "shared_secret": "a" * 32,
                "session_signing_secret": "b" * 32,
                "nc_data_root": str(nc_root),
                "work_root": str(base / "work"),
                "state_dir": str(base / "state"),
                "override_dir": str(base / "overrides"),
                "log_dir": str(base / "logs"),
                **globals_,
                "site": {
                    "nc_user": "alice", "nc_path": f"/Sites/{name}",
                    "repo_url": "", "branch": "main",
                    "recipe": {"preset": "jekyll", "output_dir": "."},
                },
            }),
            encoding="utf-8",
        )
        return load_config(cfg_path)

    cfg = make("on")
    site = cfg.site
    write(site.source_dir, ".gitignore", "_site/\n*.log\n!keep.log\n")
    write(site.source_dir, "_config.yml", "title: t\n")
    write(site.source_dir, "index.md", "hello\n")
    write(site.source_dir, "_site/index.html", "<html>built</html>")
    write(site.source_dir, "debug.log", "log\n")
    write(site.source_dir, "keep.log", "keep\n")
    app = App(cfg)
    result = app.do_init(site)
    check("init succeeded", result.get("ok") is True, str(result)[:300])

    check(
        "🔒 a directory the .gitignore ignores never enters the commit tree",
        not (site.work / "_site").exists(),
        str(sorted(path.name for path in site.work.rglob("*"))[:20]),
    )
    check(
        "🔒 it never enters the preview tree either",
        not (site.preview / "_site").exists(),
        str(sorted(path.name for path in site.preview.rglob("*"))[:20]),
    )
    check("an ignored file never enters the tree", not (site.work / "debug.log").exists(), str(site.work))
    check("the negation rule still keeps the file", (site.work / "keep.log").is_file(), str(site.work))
    check(
        "the ordinary skeleton files are all there",
        (site.work / "_config.yml").is_file()
        and (site.work / "index.md").is_file()
        and (site.work / ".gitignore").is_file(),
        str(sorted(path.name for path in site.work.iterdir())),
    )
    check(
        "the policy reports the ignored entry",
        app._skeleton_exclusion(site).matches(("_site", "index.html")) is True,
        str(app._skeleton_exclusion(site)),
    )

    # mirror_use_gitignore = false: no rule is read - but the built-in fallback list still decides,
    # and it is the same record the status panel reports. Before that, the switch really disabled
    # every rule *and* the assembly added nothing, so `_site/` and `node_modules/` were copied into
    # the three trees while `_exclusion` said they were excluded.
    off_cfg = make("off", mirror_use_gitignore=False)
    off_site = off_cfg.site
    write(off_site.source_dir, ".gitignore", "_site/\nnode_modules/\n")
    write(off_site.source_dir, "_config.yml", "title: t\n")
    write(off_site.source_dir, "_site/index.html", "<html>built</html>")
    write(off_site.source_dir, "node_modules/pkg/index.js", "js\n")
    write(off_site.source_dir, "package.json", '{"name": "site"}\n')
    off_app = App(off_cfg)
    check(
        "with mirror_use_gitignore = false no rule is read (the rules are unavailable)",
        off_app._gitignored_source_rules(off_site) is None,
        str(off_app._gitignored_source_rules(off_site)),
    )
    check(
        "🔒 the assembly and the status view resolve one and the same policy",
        off_app._skeleton_exclusion(off_site) == off_app.exclusion(off_site),
        f"{off_app._skeleton_exclusion(off_site)} != {off_app.exclusion(off_site)}",
    )
    off_names, _off_prefixes = off_app._ignore_names(off_site)
    check(
        "the fallback names are in that policy",
        {"_site", "node_modules", "vendor", "public", "dist"} <= off_names,
        str(sorted(off_names)),
    )
    off_app.do_init(off_site)
    check(
        "🔒 so the fallback keeps _site/ out of the commit tree",
        not (off_site.work / "_site").exists(),
        str(sorted(path.as_posix() for path in off_site.work.rglob("*"))[:20]),
    )
    check(
        "🔒 and node_modules/ out of it too",
        not (off_site.work / "node_modules").exists(),
        str(sorted(path.as_posix() for path in off_site.work.rglob("*"))[:20]),
    )
    check(
        "while ordinary source is assembled as usual",
        (off_site.work / "_config.yml").is_file() and (off_site.work / "package.json").is_file(),
        str(sorted(path.as_posix() for path in off_site.work.rglob("*"))[:20]),
    )
    check(
        "🔒 the preview tree honours the same fallback",
        not (off_site.preview / "_site").exists() and not (off_site.preview / "node_modules").exists(),
        str(sorted(path.as_posix() for path in off_site.preview.rglob("*"))[:20]),
    )

    # unexclude is the escape hatch, and it has to mean the same thing here
    un_cfg = make("unexclude", unexclude=["debug.log"])
    un_site = un_cfg.site
    write(un_site.source_dir, ".gitignore", "_site/\n*.log\n")
    write(un_site.source_dir, "_config.yml", "title: t\n")
    write(un_site.source_dir, "debug.log", "log\n")
    write(un_site.source_dir, "_site/index.html", "<html>built</html>")
    un_site.work.mkdir(parents=True, exist_ok=True)
    subprocess.run(
        ["git", "init", "-q"],
        cwd=str(un_site.work),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=True,
    )
    entries = App(un_cfg)._gitignored_source_rules(un_site) or []
    check(
        "unexclude removes an ignored entry from the skeleton policy",
        "_site" in entries and "debug.log" not in entries,
        str(entries),
    )


def test_fallback_exclusion_shared(tmp: Path) -> None:
    """[58] the fallback exclusions apply to the assembly, not only to the status panel.

    The defect this pins down: ``_exclusion`` (status/drift) merged :data:`FALLBACK_EXCLUDE` when no
    ``.gitignore`` could be read, while ``_skeleton_exclusion`` (the assembly) added nothing. Before
    ``git init`` - or with ``mirror_use_gitignore = false`` - ``_site/``, ``.jekyll-cache/``,
    ``vendor/``, ``node_modules/`` and friends were copied into all three trees (and a publish could
    commit them) although the panel said they were excluded. Both now read ``_ignore_names``.
    """
    print("\n[58] one ignore policy: the fallback list applies to the assembly too")
    from pages_bridge.server import App

    def make(name: str, **globals_: object):
        root = tmp / "fallback-shared" / name
        nc_root = root / "ncdata"
        (nc_root / "alice" / "files" / "Sites" / name / "source").mkdir(parents=True, exist_ok=True)
        cfg_path = root / "cfg.toml"
        cfg_path.write_text(
            _to_toml({
                "listen": "127.0.0.1:8787",
                "shared_secret": "a" * 32,
                "session_signing_secret": "b" * 32,
                "nc_data_root": str(nc_root),
                "work_root": str(root / "work"),
                "state_dir": str(root / "state"),
                "override_dir": str(root / "overrides"),
                "log_dir": str(root / "logs"),
                **globals_,
                "site": {
                    "nc_user": "alice", "nc_path": f"/Sites/{name}",
                    "repo_url": "", "branch": "main",
                    "recipe": {"preset": "jekyll", "output_dir": "."},
                },
            }),
            encoding="utf-8",
        )
        return load_config(cfg_path)

    # ── 1. no .gitignore at all, and no repository yet: the fallback list decides ───────────────
    cfg = make("nogitignore")
    site = cfg.site
    write(site.source_dir, "_config.yml", "title: t\n")
    for ignored in ("_site/index.html", ".jekyll-cache/jekyll/cache", ".sass-cache/x",
                    ".jekyll-metadata", "node_modules/pkg/index.js", "public/x.html",
                    "dist/bundle.js", "Gemfile.lock"):
        write(site.source_dir, ignored, "artifact\n")
    write(site.source_dir, "index.md", "hello\n")
    app = App(cfg)
    check(
        "the site is not a repository yet, so no rule can be read",
        app._gitignored_source_rules(site) is None,
        str(app._gitignored_source_rules(site)),
    )
    names, prefixes = app._ignore_names(site)
    check(
        "the built-in fallback list is part of the names",
        "node_modules" in names and "public" in names and "dist" in names,
        str(sorted(names)),
    )
    check(
        "the policy is byte for byte what the status view reports",
        app._skeleton_exclusion(site) == app.exclusion(site),
        f"{app._skeleton_exclusion(site)} != {app.exclusion(site)}",
    )
    initialized = app.apply_source(site, {"build": False})
    check(
        "Apply source assembled all three trees (the pre-init state: no repository yet)",
        initialized.get("ok") is True and initialized.get("rebuilt") is True,
        str(initialized)[:300],
    )
    for tree in (site.work, site.preview, site.onlinepreview):
        check(
            f"🔒 no fallback artifact reached the {tree.name} tree",
            not any((tree / name).exists() for name in
                    ("_site", ".jekyll-cache", ".sass-cache", ".jekyll-metadata",
                     "node_modules", "public", "dist", "Gemfile.lock")),
            str(sorted(path.relative_to(tree).as_posix() for path in tree.rglob("*")
                       if path.is_file() and ".git" not in path.relative_to(tree).parts[:1])[:30]),
        )
    check(
        "the ordinary skeleton files are in all three trees",
        all((tree / "_config.yml").is_file() and (tree / "index.md").is_file()
            for tree in (site.work, site.preview, site.onlinepreview)),
        str(sorted(path.name for path in site.preview.iterdir())),
    )

    # ── 2. unexclude wins over the fallback list, exactly like a configured name ────────────────
    un_cfg = make("fallback-unexclude", unexclude=["node_modules"])
    un_site = un_cfg.site
    write(un_site.source_dir, "_config.yml", "title: t\n")
    write(un_site.source_dir, "node_modules/pkg/index.js", "vendored by hand\n")
    write(un_site.source_dir, "_site/index.html", "<html>built</html>")
    un_app = App(un_cfg)
    un_names, _ = un_app._ignore_names(un_site)
    check(
        "unexclude removes a name from the fallback set",
        "node_modules" not in un_names and "_site" in un_names,
        str(sorted(un_names)),
    )
    un_app.apply_source(un_site, {"build": False})
    check(
        "the unexcluded directory is assembled (the escape hatch still wins)",
        (un_site.work / "node_modules/pkg/index.js").is_file()
        and (un_site.work / "node_modules/pkg/index.js").read_text(encoding="utf-8") == "vendored by hand\n",
        str(sorted(path.as_posix() for path in un_site.work.rglob("*"))[:20]),
    )
    check(
        "the names that were not unexcluded stay excluded",
        not (un_site.work / "_site").exists(),
        str(sorted(path.as_posix() for path in un_site.work.rglob("*"))[:20]),
    )

    # ── 3. with a repository and a .gitignore the rules decide, on both views alike ─────────────
    if shutil.which("git") is None or not _capture_available():
        print("  SKIP  no git / no piped subprocesses (the rules-available half)")
        return
    rules_cfg = make("rules")
    rules_site = rules_cfg.site
    write(rules_site.source_dir, ".gitignore", "notes/\n")
    write(rules_site.source_dir, "_config.yml", "title: t\n")
    write(rules_site.source_dir, "notes/draft.md", "a draft the repository ignores\n")
    write(rules_site.source_dir, "kept.md", "kept\n")
    # the repository exists before the first round, so the rules are readable right away (the
    # bridge normally establishes it with git init; the assembly must not depend on that order)
    rules_site.work.mkdir(parents=True, exist_ok=True)
    subprocess.run(
        ["git", "init", "-q"],
        cwd=str(rules_site.work),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=True,
    )
    rules_app = App(rules_cfg)
    rules_app.do_init(rules_site)
    check(
        "the rules are available now (a repository exists, the source has the .gitignore)",
        rules_app._gitignored_source_rules(rules_site) is not None,
        str(rules_app._gitignored_source_rules(rules_site)),
    )
    rules_names, rules_prefixes = rules_app._ignore_names(rules_site)
    check(
        "an ignored entry becomes a prefix and the fallback is not merged",
        "notes" in rules_prefixes and "node_modules" not in rules_names,
        f"names={sorted(rules_names)} prefixes={rules_prefixes}",
    )
    check(
        "🔒 the two views still agree (now via the rules)",
        rules_app._skeleton_exclusion(rules_site) == rules_app.exclusion(rules_site),
        f"{rules_app._skeleton_exclusion(rules_site)} != {rules_app.exclusion(rules_site)}",
    )
    check(
        "🔒 the ignored entry never entered the commit tree, the other file did",
        not (rules_site.work / "notes").exists() and (rules_site.work / "kept.md").is_file(),
        str(sorted(path.as_posix() for path in rules_site.work.rglob("*"))[:20]),
    )


def test_needs_install(tmp: Path) -> None:
    """The dependency probe: ``bundle check`` alone decides, and nothing is probed around it.

    ``bundle exec true`` is deliberately not part of the probe: with an empty ``vendor/`` it still
    exits 0, so it used to skip an install the build needed and the build then died with
    ``Bundler::GemNotFound``. ``bundle check`` is the one question, and a probe that cannot answer
    (``OSError``, timeout) means "needs install" too.
    """
    print("\n[49] dependency probe: bundle check is the whole answer")
    from pages_bridge import builder

    root = tmp / "needsinstall"
    cfg = load_config(_author_site_config(root, root / "ncdata", "", None))
    site = cfg.site
    check(
        "the default recipe carries an install command, so the probe is reached",
        bool(site.recipe.install_cmd),
        str(site.recipe.install_cmd),
    )
    # the same site with the install step switched off: the probe must answer without any bundler
    no_install = load_config(_author_site_config(
        root / "noinstall", root / "ncdata", "", None,
        recipe={"preset": "jekyll", "output_dir": "_site", "install_cmd": []},
    )).site

    real = builder.subprocess

    class _Shim:
        """The two attributes ``_needs_install`` touches: ``run`` scripted, ``TimeoutExpired`` real."""

        TimeoutExpired = subprocess.TimeoutExpired

        def __init__(self, outcomes):
            self.outcomes = list(outcomes)
            self.calls: list[tuple[list[str], dict]] = []

        def run(self, argv, **kwargs):
            self.calls.append((list(argv), dict(kwargs)))
            outcome = self.outcomes.pop(0) if self.outcomes else 0
            if isinstance(outcome, BaseException):
                raise outcome
            return subprocess.CompletedProcess(argv, outcome, "", "")

    def probe(*outcomes, tree=None, target=None):
        """Run ``_needs_install`` against the scripted outcomes; returns ``(verdict, calls)``."""
        shim = _Shim(outcomes)
        builder.subprocess = shim
        try:
            subject = site if target is None else target
            verdict = builder._needs_install(subject) if tree is None else builder._needs_install(subject, tree)
        finally:
            builder.subprocess = real
        return verdict, shim.calls

    def argv_of(calls) -> list[list[str]]:
        return [argv for argv, _kwargs in calls]

    # 1. bundle check is happy: the gems are all there, so there is nothing to install
    verdict, calls = probe(0)
    check("bundle check exits 0 -> no install", verdict is False, str(verdict))
    check("bundle check alone answers the question", argv_of(calls) == [["bundle", "check"]], str(argv_of(calls)))

    # 2. the reported bug: an empty vendor/ makes check fail, and no other probe may paper over it
    verdict, calls = probe(17, tree=site.onlinepreview)
    check("bundle check non-zero -> install", verdict is True, str(verdict))
    check(
        "🔒 bundle check failing is final: bundle exec true is never run",
        argv_of(calls) == [["bundle", "check"]],
        str(argv_of(calls)),
    )
    check(
        "the check runs in the tree that was asked for (the review copy, not the preview)",
        calls[0][1].get("cwd") == str(site.onlinepreview),
        str(calls[0][1].get("cwd")),
    )
    check(
        "the check carries recipe.env plus HOME and LC_ALL",
        calls[0][1]["env"].get("BUNDLE_PATH") == site.recipe.env.get("BUNDLE_PATH")
        and calls[0][1]["env"].get("LC_ALL") == "C"
        and "HOME" in calls[0][1]["env"],
        str(calls[0][1].get("env")),
    )

    # 3. the probe itself cannot answer: no bundler, or one that hangs -> the install is needed
    verdict, calls = probe(OSError("no bundler"))
    check("bundle check raising OSError -> install", verdict is True, str(verdict))
    check("the failed check was the only call", argv_of(calls) == [["bundle", "check"]], str(argv_of(calls)))
    verdict, calls = probe(subprocess.TimeoutExpired(["bundle", "check"], 90))
    check("bundle check timing out -> install", verdict is True, str(verdict))
    check("no probe after a timed-out check", argv_of(calls) == [["bundle", "check"]], str(argv_of(calls)))

    # 4. a recipe without an install step never asks bundler anything
    verdict, calls = probe(0, 0, target=no_install)
    check("a recipe without install_cmd -> no install", verdict is False, str(verdict))
    check("and not a single bundler call", calls == [], str(argv_of(calls)))


def test_clear_tree_keeps_bundle(tmp: Path) -> None:
    """The clear keeps the dependency directory the recipe installs into (``BUNDLE_PATH``)."""
    print("\n[50] the tree clear keeps BUNDLE_PATH's top directory")
    from pages_bridge.server import App

    root = tmp / "keepbundle"
    nc_root = root / "ncdata"
    src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(src, "Gemfile", "source 'https://rubygems.org'\n")
    write(src, "_config.yml", "title: t\n")

    cfg = load_config(_author_site_config(
        root, nc_root, "", None,
        recipe={"preset": "jekyll", "output_dir": "_site", "env": {"BUNDLE_PATH": "vendor/bundle"}},
    ))
    site = cfg.site
    app = App(cfg)
    check(
        "the recipe really installs into vendor/bundle",
        site.recipe.env.get("BUNDLE_PATH") == "vendor/bundle",
        str(dict(site.recipe.env)),
    )

    # the tree before the assembly: an installed bundle, a stale build output, and a file no layer
    # provides any more
    gem = write(site.preview, "vendor/bundle/gems/x", "installed gem\n")
    write(site.preview, "_site/index.html", "old build\n")
    write(site.preview, "stale.md", "cleared with the tree\n")

    copied = app._assemble_tree(site, site.preview, app._layers(site, "work"))
    check("the assembly copied the layers", copied >= 2, str(copied))
    check(
        "🔒 vendor/bundle survived the clear: it is the installed dependency directory",
        gem.is_file() and gem.read_text(encoding="utf-8") == "installed gem\n",
        str(sorted(p.relative_to(site.preview).as_posix() for p in site.preview.rglob("*") if p.is_file())[:20]),
    )
    check(
        "the build output was cleared (no layer provides it)",
        not (site.preview / "_site").exists(),
        str((site.preview / "_site").exists()),
    )
    check(
        "a file no layer names was cleared",
        not (site.preview / "stale.md").exists(),
        str((site.preview / "stale.md").exists()),
    )
    check(
        "Gemfile came back from the source layer",
        (site.preview / "Gemfile").is_file()
        and (site.preview / "Gemfile").read_text(encoding="utf-8") == "source 'https://rubygems.org'\n",
        str((site.preview / "Gemfile").read_text(encoding="utf-8")) if (site.preview / "Gemfile").is_file() else "missing",
    )

    # an empty BUNDLE_PATH keeps nothing: the same directory is cleared like any other
    empty_cfg = load_config(_author_site_config(
        root / "empty", root / "ncdata2", "", None,
        recipe={"preset": "jekyll", "output_dir": "_site", "env": {"BUNDLE_PATH": ""}},
    ))
    empty_site = empty_cfg.site
    empty_app = App(empty_cfg)
    write(empty_site.source_dir, "Gemfile", "source 'https://rubygems.org'\n")
    write(empty_site.preview, "vendor/bundle/gems/x", "installed gem\n")
    empty_app._assemble_tree(empty_site, empty_site.preview, empty_app._layers(empty_site, "work"))
    check(
        "BUNDLE_PATH empty -> vendor is not kept: the clear removes it like any other directory",
        not (empty_site.preview / "vendor").exists(),
        str((empty_site.preview / "vendor").exists()),
    )
    check(
        "the empty-BUNDLE_PATH site still assembled its layer",
        (empty_site.preview / "Gemfile").is_file(),
        str(sorted(p.relative_to(empty_site.preview).as_posix() for p in empty_site.preview.rglob("*") if p.is_file())),
    )


def test_apply_source_keeps_bundle(tmp: Path) -> None:
    """Apply source clears and rebuilds all three trees, and the installed bundle survives each."""
    print("\n[51] Apply source keeps the installed dependencies in all three trees")
    from pages_bridge.server import App

    root = tmp / "applykeep"
    nc_root = root / "ncdata"
    src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(src, "_config.yml", "title: t\n")
    write(src, "index.md", "# home\n")
    online = nc_root / "alice" / "files" / "Sites" / "t" / "online"
    write(online, "published.md", "published\n")

    cfg = load_config(_author_site_config(
        root, nc_root, "", None,
        recipe={"preset": "jekyll", "output_dir": "_site", "env": {"BUNDLE_PATH": "vendor/bundle"}},
    ))
    site = cfg.site
    app = App(cfg)
    marker = "vendor/bundle/gems/marker"
    trees = (site.work, site.preview, site.onlinepreview)
    for tree in trees:
        write(tree, marker, "installed once\n")
        write(tree, "_site/index.html", "old build\n")

    result = app.apply_source(site, {"build": False})
    check("Apply source rebuilt every tree", result.get("rebuilt") is True, str(result)[:300])
    check(
        "🔒 the dependency marker survived in work, preview and onlinepreview",
        all((tree / marker).read_text(encoding="utf-8") == "installed once\n" for tree in trees),
        str({tree.name: (tree / marker).is_file() for tree in trees}),
    )
    check(
        "the trees were still cleared: the stale build output is gone from all three",
        not any((tree / "_site").exists() for tree in trees),
        str({tree.name: (tree / "_site").exists() for tree in trees}),
    )
    check(
        "the layers were copied into every tree",
        (site.work / "published.md").is_file()
        and (site.preview / "index.md").is_file()
        and (site.preview / "published.md").is_file()
        and (site.onlinepreview / "published.md").is_file(),
        str(sorted(p.relative_to(site.preview).as_posix() for p in site.preview.rglob("*") if p.is_file())[:20]),
    )
    check(
        "🔒 work kept its .git and its installed dependency directory",
        (site.work / ".git").is_dir() and (site.work / marker).is_file(),
        str(sorted(p.name for p in site.work.iterdir())),
    )


def test_delete_guard_bundle(tmp: Path) -> None:
    """[59] the delete guard counts a tree in the unit the assembly produces.

    ``_clear_tree`` keeps ``BUNDLE_PATH``'s top directory (``vendor``) - a rebuild never deletes a
    single installed gem - but ``_preflight`` counted the ``work`` tree with **no** exclusion while
    ``new`` (``_assembly_targets``) never contains a kept directory. Every file under ``vendor/``
    was therefore a "removed" file on the old side only: a site with a populated dependency
    directory tripped ``MIRROR_DELETE_GUARD`` on an ordinary Apply source, and the operator was
    told the round would delete the gems it was about to keep. Both sides now skip the same set
    (``_bundle_keep``), for all three trees.
    """
    print("\n[59] the delete guard skips what the tree clear keeps (BUNDLE_PATH)")
    from pages_bridge.server import App

    root = tmp / "guard-bundle"
    nc_root = root / "ncdata"
    src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(src, "_config.yml", "title: t\n")
    write(src, "index.md", "# home\n")

    cfg = load_config(_author_site_config(
        root, nc_root, "", None,
        recipe={"preset": "jekyll", "output_dir": "_site", "env": {"BUNDLE_PATH": "vendor/bundle"}},
    ))
    site = cfg.site
    app = App(cfg)
    check("the keep set is BUNDLE_PATH's top directory", app._bundle_keep(site) == {"vendor"}, str(app._bundle_keep(site)))
    check(
        "🔒 vendor/ is kept because it is the installed dependency directory, not the output",
        site.recipe.env.get("BUNDLE_PATH") == "vendor/bundle",
        str(dict(site.recipe.env)),
    )

    # a fully installed bundle: gems under BUNDLE_PATH and a cache beside it (both under the top
    # directory the clear keeps, but only the first one matches the skeleton's vendor/bundle prefix)
    gems = [f"vendor/bundle/gems/g{i}/lib.rb" for i in range(3)]
    cache = [f"vendor/cache/{i}.gem" for i in range(30)]
    for tree in (site.work, site.preview, site.onlinepreview):
        for rel in gems + cache:
            write(tree, rel, "installed\n")
    write(site.work, "stale.md", "no layer provides this any more\n")

    ordinary = app.apply_source(site, {"build": False})
    check(
        "🔒 an ordinary round over a populated vendor/ is not refused",
        ordinary.get("ok") is True and ordinary.get("rebuilt") is True,
        str(ordinary)[:400],
    )
    check(
        "🔒 every installed dependency survived the round",
        all((tree / rel).is_file() for tree in (site.work, site.preview, site.onlinepreview)
            for rel in gems + cache),
        str({tree.name: sorted(p.relative_to(tree).as_posix() for p in tree.rglob("*.gem"))[:2]
             for tree in (site.work, site.preview, site.onlinepreview)}),
    )
    check(
        "the round still cleared the file no layer provides",
        not (site.work / "stale.md").exists(),
        str(sorted(p.relative_to(site.work).as_posix() for p in site.work.rglob("*") if p.is_file())[:20]),
    )

    # the guard must still refuse a real mass deletion: 50 skeleton files nothing provides any more,
    # next to the same populated vendor/ (which must not be part of the count on either side)
    for i in range(50):
        write(site.work, f"old/{i}.md", "gone with the next round\n")
    guarded = app.apply_source(site, {"build": False})
    check(
        "🔒 the guard still refuses a real mass deletion",
        guarded.get("ok") is False and guarded.get("error") == "MIRROR_DELETE_GUARD",
        str(guarded)[:400],
    )
    check(
        "the refusal counts the skeleton files, never the kept dependency directory",
        (guarded.get("tree"), guarded.get("old"), guarded.get("new"), guarded.get("removed"))
        == ("work", 52, 2, 50),
        str({key: guarded.get(key) for key in ("tree", "old", "new", "removed")}),
    )
    check(
        "🔒 nothing was cleared by the refused round (neither the old files nor vendor/)",
        len(list((site.work / "old").rglob("*.md"))) == 50
        and all((site.work / rel).is_file() for rel in gems + cache),
        str(sorted(p.relative_to(site.work).as_posix() for p in site.work.rglob("*") if p.is_file())[:6]),
    )

    # an empty BUNDLE_PATH keeps nothing, so there is nothing to skip either
    empty_cfg = load_config(_author_site_config(
        root / "empty", root / "ncdata2", "", None,
        recipe={"preset": "jekyll", "output_dir": "_site", "env": {"BUNDLE_PATH": ""}},
    ))
    check(
        "an empty BUNDLE_PATH keeps nothing, so the count skips nothing",
        App(empty_cfg)._bundle_keep(empty_cfg.site) == set(),
        str(App(empty_cfg)._bundle_keep(empty_cfg.site)),
    )


def test_bundle_modes(tmp: Path) -> None:
    """The build picks the bundle that really runs: the vendored one, or the host's gems.

    An empty ``vendor/`` with ``BUNDLE_PATH`` set makes bundler look only there, so neither
    ``bundle check`` nor ``bundle install --local`` finds the system jekyll. The probe is therefore
    the real executable (``bundle exec <tool> --version``), run twice: once with the recipe's env,
    once with ``BUNDLE_PATH``/``BUNDLE_BIN`` removed. Only when both fail is an install attempted,
    and the build runs with the environment that install produced.
    """
    print("\n[52] bundle mode: vendored gems, system gems, and the install chain")
    from pages_bridge import builder
    from pages_bridge.state import SiteState

    root = tmp / "bundlemodes"
    nc_root = root / "ncdata"
    src = nc_root / "alice" / "files" / "Sites" / "t" / "source"
    write(src, "_config.yml", "title: t\n")
    cfg = load_config(_author_site_config(
        root, nc_root, "", None,
        recipe={"preset": "jekyll", "output_dir": "_site",
                "command": ["bundle", "exec", "jekyll", "build"]},
    ))
    site = cfg.site
    state = SiteState.load(cfg.state_dir)
    probe_argv = ["bundle", "exec", "jekyll", "--version"]
    build_argv_expected = [
        "bundle", "exec", "jekyll", "build", "--destination", str(site.output_dir)
    ]

    check("the probe tool is the command's bundle exec target",
          builder._exec_tool(site.recipe.command) == "jekyll",
          str(builder._exec_tool(site.recipe.command)))
    check("a build command without bundle exec has no probe tool",
          builder._exec_tool([sys.executable, "site.py"]) is None
          and builder._exec_tool(["bundle", "install"]) is None,
          f"{builder._exec_tool([sys.executable, 'site.py'])} / {builder._exec_tool(['bundle', 'install'])}")
    check("the system env removes both vendor keys",
          builder._bundle_env("system", site.recipe.env)[1] == ("BUNDLE_PATH", "BUNDLE_BIN"),
          str(builder._bundle_env("system", site.recipe.env)[1]))
    check("the vendored env keeps the recipe environment as it is",
          builder._bundle_env("vendored", site.recipe.env)[1] == ()
          and builder._bundle_env("vendored", site.recipe.env)[0].get("BUNDLE_PATH") == "vendor/bundle",
          str(builder._bundle_env("vendored", site.recipe.env)))

    if _capture_available():
        # the contract the system mode relies on: the key is removed, not emptied
        probe_code, probe_out, probe_timed_out = builder._run_capture(
            [sys.executable, "-c", "import os; print(os.environ.get('BUNDLE_PATH', '<unset>'))"],
            cwd=root,
            timeout=60,
            extra_env={"BUNDLE_PATH": "vendor/bundle", "BUNDLE_BIN": "vendor/bundle/bin"},
            drop_env=("BUNDLE_PATH", "BUNDLE_BIN"),
        )
        check("system mode removes the vendor keys from the env instead of emptying them",
              probe_code == 0 and not probe_timed_out and probe_out.strip() == "<unset>",
              f"{probe_code} / {probe_out.strip()!r}")

    real_capture = builder._run_capture
    real_needs = builder._needs_install
    outcomes: list[tuple[int, str, bool]] = []
    calls: list[tuple[list[str], Path, int, dict, tuple]] = []

    def fake_capture(argv, cwd, timeout, extra_env=None, drop_env=()):
        calls.append((list(argv), Path(cwd), timeout, dict(extra_env or {}), tuple(drop_env)))
        return outcomes.pop(0) if outcomes else (0, "ok\n", False)

    def run(*scripted):
        """Run one build with the scripted ``_run_capture`` answers (probes, installs, build)."""
        outcomes[:] = scripted
        calls.clear()
        return builder.run_build(site, cfg, state)

    def argv_list() -> list[list[str]]:
        return [argv for argv, *_rest in calls]

    def effective_env(record) -> dict:
        """The env a call really gets: ``extra_env`` layered, then ``drop_env`` removed."""
        return {key: value for key, value in record[3].items() if key not in record[4]}

    builder._run_capture = fake_capture  # type: ignore[assignment]
    builder._needs_install = lambda *_a, **_k: False  # type: ignore[assignment]
    try:
        # 1. the vendored bundle works: no install, and the build runs with the recipe's env
        built = run((0, "jekyll 4.4.1\n", False), (0, "built\n", False))
        check("a working vendored bundle builds without any install",
              built.ok is True and built.installed is False and built.bundle_mode == "vendored",
              str(built.to_dict())[:300])
        check("the two calls are the vendored probe and the build",
              argv_list() == [probe_argv, build_argv_expected], str(argv_list()))
        check("the build keeps the vendored environment",
              calls[-1][4] == () and effective_env(calls[-1]).get("BUNDLE_PATH") == "vendor/bundle",
              f"{calls[-1][3]} / {calls[-1][4]}")

        # 2. vendor/ is empty but the host has the gems: system mode, still no install
        built = run(
            (1, "Bundler::GemNotFound: jekyll\n", False),
            (0, "jekyll 4.4.1\n", False),
            (0, "built\n", False),
        )
        check("vendored probe failing and system probe working -> no install, system mode",
              built.ok is True and built.installed is False and built.bundle_mode == "system",
              str(built.to_dict())[:300])
        check("the system probe unsets the two vendor keys",
              calls[1][4] == ("BUNDLE_PATH", "BUNDLE_BIN"), str(calls[1][4]))
        check("the build runs with BUNDLE_PATH/BUNDLE_BIN removed from its env",
              calls[-1][4] == ("BUNDLE_PATH", "BUNDLE_BIN")
              and "BUNDLE_PATH" not in effective_env(calls[-1])
              and "BUNDLE_BIN" not in effective_env(calls[-1]),
              str(effective_env(calls[-1])))
        check("the system build command is unchanged", argv_list()[-1] == build_argv_expected,
              str(argv_list()))

        # 3a. both probes fail, `bundle install` is refused, `--local` on the vendored env works
        built = run(
            (1, "probe: jekyll missing\n", False),
            (1, "probe: jekyll missing\n", False),
            (1, "bundle install: 403 from the gem source\n", False),
            (0, "Bundle complete! 37 gems now installed.\n", False),
            (0, "built\n", False),
        )
        check("both probes failing starts the install chain",
              argv_list()[:2] == [probe_argv, probe_argv], str(argv_list()))
        check("the chain is bundle install, then the same command + --local",
              argv_list()[2:4] == [["bundle", "install"], ["bundle", "install", "--local"]],
              str(argv_list()))
        check("the local retry succeeding continues the build with the vendored env",
              built.ok is True and built.installed is True and built.bundle_mode == "vendored"
              and calls[-1][4] == () and effective_env(calls[-1]).get("BUNDLE_PATH") == "vendor/bundle",
              str(built.to_dict())[:300])
        check("the install attempts share the tree, the timeout and the vendored env",
              calls[2][1] == site.preview and calls[3][1] == site.preview
              and calls[2][2] == calls[3][2]
              and calls[2][4] == calls[3][4] == (),
              str(calls[2:4]))

        # 3b. even `--local` cannot see the gems through an empty BUNDLE_PATH: the system env can
        built = run(
            (1, "probe: jekyll missing\n", False),
            (1, "probe: jekyll missing\n", False),
            (1, "bundle install: 403\n", False),
            (1, "bundle install --local: Could not find jekyll in vendor/bundle\n", False),
            (0, "Bundle complete! 37 gems now installed (system).\n", False),
            (0, "built\n", False),
        )
        check("the third attempt is the same --local install on the system env",
              argv_list()[4] == ["bundle", "install", "--local"]
              and calls[4][4] == ("BUNDLE_PATH", "BUNDLE_BIN"),
              f"{argv_list()[4]} / {calls[4][4]}")
        check("a system install makes the build use the system mode",
              built.ok is True and built.installed is True and built.bundle_mode == "system",
              str(built.to_dict())[:300])
        check("the build after the system install keeps the vendor keys removed",
              calls[-1][4] == ("BUNDLE_PATH", "BUNDLE_BIN")
              and "BUNDLE_PATH" not in effective_env(calls[-1]),
              str(effective_env(calls[-1])))

        # 3c. every attempt fails: the build fails, on the last attempt's output
        failed = run(
            (1, "probe: jekyll missing\n", False),
            (1, "probe: jekyll missing\n", False),
            (1, "install first: 403\n", False),
            (1, "install local: empty vendor\n", False),
            (1, "install local system: Could not find jekyll\n", False),
        )
        check("only after every attempt fails does the build fail",
              failed.ok is False and failed.installed is True, str(failed.to_dict())[:300])
        check("the failure reports the last attempt's command, exit code and output",
              failed.command == ["bundle", "install", "--local"]
              and failed.exit_code == 1
              and "install local system" in failed.log_tail
              and "403" not in failed.log_tail,
              f"{failed.command} / {failed.exit_code} / {failed.log_tail}")
        check("the failed result carries the mode of the last attempt",
              failed.bundle_mode == "system", failed.bundle_mode)
        log_text = (cfg.log_dir / "site.log").read_text(encoding="utf-8")
        check("the persisted log keeps every attempt and names the bundle mode",
              "install first: 403" in log_text
              and "install local: empty vendor" in log_text
              and "install local system: Could not find jekyll" in log_text
              and "# bundle: system" in log_text,
              log_text[-800:])

        # 4. a timeout proves nothing: no retry, no mode switch
        hung = run(
            (1, "probe: jekyll missing\n", False),
            (1, "probe: jekyll missing\n", False),
            (-9, "install hung\n", True),
        )
        check("a timed-out install is never retried and the mode is not switched",
              argv_list() == [probe_argv, probe_argv, ["bundle", "install"]], str(argv_list()))
        check("the timed-out install fails the build",
              hung.ok is False and hung.timed_out is True, str(hung.to_dict())[:300])

        # 5. a recipe that does not use bundle exec has nothing to probe: the old check decides
        plain_cfg = load_config(_author_site_config(
            root / "plain", root / "ncdata2", "", None,
            recipe={"preset": "jekyll", "output_dir": "_site", "command": [sys.executable, "site.py"]},
        ))
        outcomes[:] = [(0, "built\n", False)]
        calls.clear()
        plain_built = builder.run_build(plain_cfg.site, plain_cfg, state)
        check("a recipe without bundle exec skips the probes and keeps the bundle check decision",
              plain_built.ok is True and plain_built.bundle_mode == "vendored"
              and argv_list() == [[sys.executable, "site.py", "--destination", str(plain_cfg.site.output_dir)]],
              str(argv_list()))
    finally:
        builder._run_capture = real_capture
        builder._needs_install = real_needs


def main() -> int:
    print("=" * 68)
    print("pages-bridge smoke tests")
    print("=" * 68)

    # deliberately not the system temp dir by default: a restricted environment may forbid writing
    # there. A checkout on a Windows drive is the opposite problem - it cannot hold file modes, so
    # the mirror-mode checks would fail for the wrong reason; use the system temp dir in that case.
    tmp_root = Path(os.environ.get("PAGES_TEST_TMP") or Path(__file__).resolve().parents[1] / ".test-tmp")
    if not os.environ.get("PAGES_TEST_TMP"):
        tmp_root.mkdir(parents=True, exist_ok=True)
        if not _modes_supported(tmp_root):
            tmp_root = Path(tempfile.mkdtemp(prefix="pages-smoke-"))
            print(f"note: this checkout cannot hold file modes, using {tmp_root}")
    if tmp_root.exists():
        _rmtree(tmp_root)
    tmp_root.mkdir(parents=True, exist_ok=True)
    try:
        test_mirror(tmp_root)
        test_delete_guard(tmp_root)
        test_exclusion()
        test_dry_run(tmp_root)
        test_session()
        test_config(tmp_root)
        test_paths(tmp_root)
        test_errors()
        test_init_bootstrap(tmp_root)
        test_work_copy_recovery(tmp_root)
        test_pull_healing(tmp_root)
        test_default_work_root(tmp_root)
        test_nc_user_required(tmp_root)
        test_site_dirs_config(tmp_root)
        test_three_tree_assembly(tmp_root)
        test_single_site(tmp_root)
        test_retime(tmp_root)
        test_gemfile_lock_not_mirrored(tmp_root)
        test_gitignore_driven(tmp_root)
        test_http_layer(tmp_root)
        test_paired_state(tmp_root)
        test_pairing_code(tmp_root)
        test_site_reset(tmp_root)
        test_bootstrap_state_dir(tmp_root)
        test_author_config(tmp_root)
        test_author_mirror(tmp_root)
        test_preview_separation(tmp_root)
        test_author_root_channel(tmp_root)
        test_author_http(tmp_root)
        test_site_paths(tmp_root)
        test_author_publish(tmp_root)
        test_author_publish_take_down(tmp_root)
        test_publish_idempotent(tmp_root)
        test_publish_digest(tmp_root)
        test_onlinepreview_tree(tmp_root)
        test_orphan_site_files(tmp_root)
        test_never_published_draft(tmp_root)
        test_published_content(tmp_root)
        test_state_files_lost(tmp_root)
        test_publish_remote_states(tmp_root)
        test_status_paths(tmp_root)
        test_author_delta_locking(tmp_root)
        test_assembly_guard(tmp_root)
        test_publish_drift(tmp_root)
        test_no_implicit_mirror(tmp_root)
        test_take_down_source_fallback(tmp_root)
        test_author_overwrite_removes_strays(tmp_root)
        test_author_overwrite_area(tmp_root)
        test_layer_files_symlink(tmp_root)
        test_author_root_move(tmp_root)
        test_author_root_move_prune(tmp_root)
        test_site_change_under_lock(tmp_root)
        test_atomic_residue(tmp_root)
        test_init_refused_mirror(tmp_root)
        test_pair_code_before_dirs(tmp_root)
        test_skeleton_gitignore(tmp_root)
        test_fallback_exclusion_shared(tmp_root)
        test_needs_install(tmp_root)
        test_clear_tree_keeps_bundle(tmp_root)
        test_apply_source_keeps_bundle(tmp_root)
        test_delete_guard_bundle(tmp_root)
        test_bundle_modes(tmp_root)
    finally:
        _rmtree(tmp_root)

    print("\n" + "=" * 68)
    if FAILURES:
        print(f"❌ {len(FAILURES)} of {PASSES + len(FAILURES)} checks failed:")
        for item in FAILURES:
            print(f"   - {item}")
        return 1
    print(f"✅ all {PASSES} checks passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
