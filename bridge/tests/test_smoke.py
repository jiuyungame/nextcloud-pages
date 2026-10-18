"""pages-bridge smoke tests (zero dependencies; run with ``python3 tests/test_smoke.py``)."""

from __future__ import annotations

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
from urllib.parse import unquote
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
    check("reverse fill mirrored exactly the source set", set(r.drift) == expected, f"got {sorted(r.drift)}")

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
    check("missing repo_url rejected", not ok, msg)

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
    nc_root = root / "ncdata"
    (nc_root / "alice" / "files").mkdir(parents=True, exist_ok=True)
    src = nc_root / "alice" / "files" / "Sites" / "t"
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
    print("\n[9] first initialization (three bootstrap scenarios)")
    if shutil.which("git") is None:
        print("  SKIP  no git in this environment")
        return
    if not _capture_available():
        print("  SKIP  this environment forbids piped subprocesses (restricted sandbox); run it under WSL/Linux")
        return

    from pages_bridge.server import App

    base = tmp / "init"
    base.mkdir(parents=True, exist_ok=True)

    # A: work copy is a repository with content, the source is empty
    cfg, src, wk = _scenario(
        base / "a", source={}, work={"_config.yml": "x\n", "a.md": "1\n"}, git_repo=True
    )
    res = App(cfg).do_init(cfg.site)
    check("A takes the reverse fill", res.get("bootstrap") == "reverse_fill", str(res)[:200])
    check("A source filled", (src / "_config.yml").is_file() and (src / "a.md").is_file())
    check("🔒 A work copy not emptied", (wk / "_config.yml").is_file() and (wk / "a.md").is_file())
    check("🔒 A work copy .git still there", (wk / ".git").is_dir())

    # B: the source has content and the work copy is empty
    cfg, src, wk = _scenario(
        base / "b", source={"_config.yml": "x\n", "b.md": "2\n"}, work={}, git_repo=False
    )
    res = App(cfg).do_init(cfg.site)
    check("B forward mirror copied 2", res["mirror"]["copied"] == 2, str(res["mirror"]))
    check("B work copy got the files", (wk / "b.md").is_file())
    check("B git adopted", res["git"]["action"] in ("in_place", "clone", "adopt"), str(res["git"]))

    # C: both sides have content, the source wins (.git stays protected)
    cfg, src, wk = _scenario(
        base / "c", source={"s.md": "s\n"}, work={"w.md": "w\n"}, git_repo=True
    )
    res = App(cfg).do_init(cfg.site)
    check("C takes the forward mirror", res["mirror"]["direction"] == "forward", str(res["mirror"]))
    check("C source file in place", (wk / "s.md").is_file())
    check("C file absent from the source removed", not (wk / "w.md").exists())
    check("🔒 C .git still there", (wk / ".git").is_dir())


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
    """The work copy lives in /tmp and must recover after the system clears it."""
    print("\n[15] work copy recovery (/tmp cleared, first clone)")
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

    # A: empty work copy with a reachable remote must clone, not adopt
    cfg, src, wk = _scenario(
        base / "a", source={"from-nc.md": "nc\n"}, work={}, git_repo=False,
        repo_url=str(remote),
    )
    res = App(cfg).do_init(cfg.site)
    check("A takes the clone path", res["git"]["action"] == "clone", str(res.get("git"))[:200])
    check("A got a full .git", (wk / ".git").is_dir())
    st = gitops.status(wk)
    check("A has a real local branch", st.branch == "main", f"branch={st.branch!r}")
    check("A has upstream tracking", st.upstream == "origin/main", f"upstream={st.upstream!r}")
    check("A HEAD points at a real commit", len(st.head) == 40, st.head)
    # the source is authoritative: files cloned but absent from it are removed
    check("A file absent from the source removed", not (wk / "index.md").exists())
    check("A source file in place", (wk / "from-nc.md").is_file())

    # B: the whole work directory is gone, the next mirror rebuilds the repository
    shutil.rmtree(wk)
    cfg2, src2, wk2 = _scenario(
        base / "b", source={"_config.yml": "x\n", "keep.md": "k\n"}, work={}, git_repo=False,
        repo_url=str(remote),
    )
    app = App(cfg2)
    result = app.do_mirror(cfg2.site)
    check("B mirror itself succeeds", result.ok, str(result.to_dict())[:200])
    check("B work copy cloned again", gitops.is_repo(wk2), f"work={wk2}")
    check("B files synced", (wk2 / "keep.md").is_file())
    check("B git status still reportable after the rebuild", gitops.status(wk2).branch == "main")

    # C: an unreachable remote must not fail the mirror
    cfg3, src3, wk3 = _scenario(
        base / "c", source={"a.md": "1\n"}, work={}, git_repo=False,
        repo_url=str(base / "does-not-exist.git"),
    )
    app3 = App(cfg3)
    result3 = app3.do_mirror(cfg3.site)
    check("C mirror still succeeds with an unreachable remote", result3.ok, str(result3.to_dict())[:200])
    check("C files still synced", (wk3 / "a.md").is_file())
    check("C not mistaken for a repository", not gitops.is_repo(wk3))


def test_default_work_root(tmp: Path) -> None:
    """The default work root must be under /tmp, and it is the work copy itself."""
    print("\n[16] default work_root is /tmp/pages and is the work copy")
    base = tmp / "defroot"
    base.mkdir(parents=True, exist_ok=True)
    data = _base_config(base)
    data["work_root"] = "/tmp/pages"
    cfg_path = _write_config(base, data)
    loaded = load_config(cfg_path)
    check("Config.work_root is /tmp/pages", str(loaded.work_root) == "/tmp/pages")
    check(
        "the default work copy is the work root itself (no <work_root>/<site>)",
        str(loaded.site.work) == "/tmp/pages",
        str(loaded.site.work),
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
    check("the route table has 16 entries", len(ROUTES) == 16, str(len(ROUTES)))
    check("GET /sites is gone", "sites" not in [name for _m, _p, name in ROUTES])
    check("no route path starts with /sites/", not any(p.startswith("^/sites") for p in paths), str(paths))
    check(
        "the site routes are id-free",
        sorted(p for p in paths if p.startswith("^/site/")) == [
            "^/site/build$", "^/site/diff$", "^/site/init$", "^/site/log$", "^/site/mirror$",
            "^/site/publish$", "^/site/pull$", "^/site/session$", "^/site/status$", "^/site/touch$",
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
    (nc_root / "alice" / "files" / "Sites" / "t").mkdir(parents=True, exist_ok=True)
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

        status, _ = call("GET", "/site/status", auth=None)
        check("missing Authorization -> 401", status == 401, f"{status}")
        status, _ = call("GET", "/site/status", auth="wrong")
        check("wrong secret -> 401", status == 401, f"{status}")
        status, body = call("GET", "/site/status")
        check("correct secret -> 200", status == 200 and "work" in body, f"{status}")

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
                "nc_user": "alice", "nc_path": "/Sites/t", "repo_url": "",
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
            "site": {**app_site, "repo_url": ""},
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
                "site": {**pair_body["site"], "repo_url": ""},
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
    check("the frozen route table still has 16 routes", len(ROUTES) == 16, str(len(ROUTES)))
    check("POST /pair is not part of it", PAIR_ROUTE[0] == "POST" and PAIR_ROUTE[1].pattern == "^/pair$", str(PAIR_ROUTE))
    check("only /health and /pair answer while UNPAIRED", UNPAIRED_ROUTES == {"health", "pair"}, str(UNPAIRED_ROUTES))

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
        json.dumps({**effective, "site": {"nc_user": "alice", "nc_path": "/Sites/x", "repo_url": "", "recipe": {"preset": "jekyll", "output_dir": "."}}}),
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


# ── multi-source sites: one site, one folder per author (P2, bridge half) ────────────────────────

def _author_entry(rel: str, target: str) -> dict:
    return {"path": rel, "target": target}


def _author_payload(uid: str = "alice", path: str = "/Pages/posts", entries=None, removed=None) -> dict:
    """One element of the ``site.authors`` array exactly as the app pushes it."""
    return {
        "uid": uid,
        "path": path,
        "entries": [
            _author_entry("2026-05-01-hello.md", f"_posts/{uid}/2026-05-01-hello.md"),
            _author_entry("theme.css", f"assets/authors/{uid}.css"),
        ] if entries is None else entries,
        "removed": list(removed or []),
    }


def _author_site_config(root: Path, nc_root: Path, repo_url: str, authors, recipe=None) -> Path:
    """A TOML config for the multi-source tests; ``authors=None`` writes no authors key at all."""
    root.mkdir(parents=True, exist_ok=True)
    site = {
        "nc_user": "alice", "nc_path": "/Sites/t",
        "repo_url": repo_url, "branch": "main",
        "recipe": recipe or {"preset": "jekyll", "output_dir": "."},
    }
    if authors is not None:
        site["authors"] = authors
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


def _jekyll_posts(root: Path) -> list[str]:
    """Jekyll's own document rule: any file under ``_posts``, at any depth, named DATE-slug.ext.

    Jekyll walks the whole ``_posts`` tree, so a per-author subfolder is an ordinary document and
    not a special case. This is the check that the author files really are build input.
    """
    posts = root / "_posts"
    if not posts.is_dir():
        return []
    return [
        str(path.relative_to(root)).replace(os.sep, "/")
        for path in sorted(posts.rglob("*"))
        if path.is_file() and re.match(r"^\d{4}-\d{2}-\d{2}-.+\.(md|markdown|html)$", path.name)
    ]


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
        == ["_posts/alice/2026-05-01-hello.md", "assets/authors/alice.css"],
        str(site.authors[0].entries),
    )
    check("the author path is kept absolute", site.authors[0].path == "/Pages/posts", site.authors[0].path)
    dumped = site.to_dict()
    check(
        "Site.to_dict() carries the authors",
        dumped["authors"][0]["uid"] == "alice"
        and dumped["authors"][0]["entries"][1]["target"] == "assets/authors/alice.css",
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
        _author_payload("carol", entries=[], removed=["_posts/carol/2026-04-01-old.md"]),
    ]
    cfg2 = load_config(_write_config(base / "two", two))
    check("several authors are kept in order", [a.uid for a in cfg2.site.authors] == ["bob", "carol"], str(cfg2.site.authors))
    check(
        "an author with removals only is accepted",
        cfg2.site.authors[1].entries == () and cfg2.site.authors[1].removed == ("_posts/carol/2026-04-01-old.md",),
        str(cfg2.site.authors[1]),
    )
    ok, msg = _load(base / "empty", lambda d: d["site"].update({"authors": []}))
    check("an empty authors list is still the single-source site", ok, msg)

    styles = _load(base / "style", lambda d: d["site"].update({"authors": [_author_payload("bob", entries=[
        {"path": "theme.css", "target": "assets/authors/bob.css"},
        {"path": "2026-05-01-hello.css", "target": "assets/authors/bob/2026-05-01-hello.css"},
    ])]}))
    check("both documented stylesheet targets are accepted", styles[0], styles[1])

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
        ("an entry without a source path is refused", [_author_payload("bob", entries=[{"target": "_posts/bob/x.md"}])], "path"),
        ("an absolute entry path is refused", [_author_payload("bob", entries=[{"path": "/x.md", "target": "_posts/bob/x.md"}])], "path"),
        ("an entry path with .. is refused", [_author_payload("bob", entries=[{"path": "../x.md", "target": "_posts/bob/x.md"}])], "path"),
        ("an entry path with a ./ component is refused", [_author_payload("bob", entries=[{"path": "./x.md", "target": "_posts/bob/x.md"}])], "path"),
        ("a target with a doubled slash is refused", [_author_payload("bob", entries=[{"path": "x.md", "target": "_posts//bob/x.md"}])], "target"),
        ("a removal with a ./ component is refused", [_author_payload("bob", removed=["_posts/./bob/x.md"])], "target"),
        ("an entry without a target is refused", [_author_payload("bob", entries=[{"path": "x.md"}])], "target"),
        ("a target inside another author's posts is refused", [_author_payload("bob", entries=[{"path": "x.md", "target": "_posts/carol/x.md"}])], "target"),
        ("a target outside the two areas is refused", [_author_payload("bob", entries=[{"path": "index.html", "target": "index.html"}])], "target"),
        ("a target climbing out of the site is refused", [_author_payload("bob", entries=[{"path": "x", "target": "../escape"}])], "target"),
        ("a target on the site's own files is refused", [_author_payload("bob", entries=[{"path": "x", "target": "_config.yml"}])], "target"),
        ("another author's stylesheet is refused", [_author_payload("bob", entries=[{"path": "theme.css", "target": "assets/authors/carol.css"}])], "target"),
        ("a non-stylesheet under assets/authors is refused", [_author_payload("bob", entries=[{"path": "notes.txt", "target": "assets/authors/bob/notes.txt"}])], "target"),
        ("a duplicate target is refused", [_author_payload("bob", entries=[
            {"path": "a.md", "target": "_posts/bob/x.md"}, {"path": "b.md", "target": "_posts/bob/x.md"}])], "duplicate"),
        ("a removed value that is not a string is refused", [_author_payload("bob", removed=[7])], "removed"),
        ("a removal outside the author's area is refused", [_author_payload("bob", removed=["_config.yml"])], "target"),
        ("a removal climbing out of the site is refused", [_author_payload("bob", removed=["../escape"])], "target"),
        ("a duplicate removal is refused", [_author_payload("bob", removed=["_posts/bob/x.md", "_posts/bob/x.md"])], "duplicate"),
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
    (nc_toml / "alice" / "files").mkdir(parents=True, exist_ok=True)
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
            'removed = ["_posts/bob/2026-04-01-old.md"]',
            "",
            "[[site.authors.entries]]",
            'path = "2026-05-01-hello.md"',
            'target = "_posts/bob/2026-05-01-hello.md"',
            "",
            "[[site.authors.entries]]",
            'path = "theme.css"',
            'target = "assets/authors/bob.css"',
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
        and cfg_toml_loaded.site.authors[0].removed == ("_posts/bob/2026-04-01-old.md",),
        str(cfg_toml_loaded.site.authors[0]),
    )

    # ── the configurable author directory: `site.author_root` ────────────────────────────────────
    #
    # Every author's whole tree is mirrored into one site directory (`<author_root>/<uid>/…`). The
    # default is `tmp` so a client that does not send the field keeps working, and the legacy
    # `_posts`/`assets/authors` targets stay accepted for an instance that has not migrated yet.
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
            {"path": "theme.css", "target": "assets/authors/bob.css"},
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
        "the legacy stylesheet target is still accepted next to a custom root",
        custom_loaded.site.authors[0].entries[2].target == "assets/authors/bob.css",
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
          "removed": ["tmp/bob/old.png", "_posts/bob/legacy.md"]}],
        nc_data_root=base / "ncdata",
        site_source=base / "ncdata" / "alice" / "files" / "Sites" / "t",
        work=base / "work",
        error=BadRequest,
        author_root="people",
        previous_author_root="tmp",
    )[0]
    check(
        "a removal under the previous author directory is accepted",
        moved.removed == ("tmp/bob/old.png", "_posts/bob/legacy.md"),
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
        "author_target_prefixes carries the new tree and both legacy areas",
        author_target_prefixes("bob", "people") == ("people/bob", "_posts/bob", "assets/authors"),
        str(author_target_prefixes("bob", "people")),
    )
    check(
        "every prefix the exclusion hides is a target the validation accepts",
        author_target_allowed("bob", "people/bob/img/x.png", "people")
        and author_target_allowed("bob", "people/bob/x/y/z.png", "people")
        and author_target_allowed("bob", "_posts/bob/x.md", "people")
        and author_target_allowed("bob", "assets/authors/bob.css", "people")
        and not author_target_allowed("bob", "people/carol/x.png", "people"),
        "people/bob tree, legacy areas, and another author's tree",
    )


def test_author_mirror(tmp: Path) -> None:
    print("\n[22] author mirror: additive, deletion only when it is named")
    root = tmp / "amir"
    nc_root = root / "ncdata"
    work = root / "work"
    site_src = nc_root / "alice" / "files" / "Sites" / "t"
    site_src.mkdir(parents=True, exist_ok=True)
    author_root = nc_root / "bob" / "files" / "Pages" / "posts"
    author_root.mkdir(parents=True, exist_ok=True)
    excl = Exclusion.for_site({".git"}, "_site")

    def author(entries, removed=(), uid="bob", path="/Pages/posts"):
        return parse_authors(
            [{"uid": uid, "path": path, "entries": list(entries), "removed": list(removed)}],
            nc_data_root=nc_root, site_source=site_src, work=work,
        )[0]

    post = {"path": "2026-05-01-hello.md", "target": "_posts/bob/2026-05-01-hello.md"}
    write(author_root, "2026-05-01-hello.md", "hello\n")
    first = author([post])
    r = sync_author(first.source, work, excl, first)
    check(
        "an author entry lands at its target",
        (work / "_posts/bob/2026-05-01-hello.md").read_text(encoding="utf-8") == "hello\n",
    )
    check("its parent directory was created", (work / "_posts/bob").is_dir())
    check("a new target counts as copied", r.copied == 1 and r.updated == 0, str(r.to_dict()))
    check("the copied bytes are counted", r.bytes_copied == 6, str(r.to_dict()))
    check("the round is ok", r.ok, str(r.to_dict()))

    r = sync_author(first.source, work, excl, first)
    check("a second round writes nothing", r.copied == 0 and r.updated == 0 and r.unchanged == 1, str(r.to_dict()))
    check("a round with nothing to do is in sync", r.in_sync and r.missing == [], str(r.to_dict()))

    write(author_root, "2026-05-01-hello.md", "hello again\n")
    r = sync_author(first.source, work, excl, first)
    check("a changed source updates the target", r.updated == 1 and r.copied == 0, str(r.to_dict()))
    check(
        "the target holds the new text",
        (work / "_posts/bob/2026-05-01-hello.md").read_text(encoding="utf-8") == "hello again\n",
    )
    check(
        "a Jekyll document scan sees the author's post",
        _jekyll_posts(work) == ["_posts/bob/2026-05-01-hello.md"],
        str(_jekyll_posts(work)),
    )

    (author_root / "2026-05-01-hello.md").unlink()
    r = sync_author(first.source, work, excl, first)
    check("a vanished source is reported missing", r.missing == ["_posts/bob/2026-05-01-hello.md"], str(r.to_dict()))
    check("🔒 the missing target is not deleted", (work / "_posts/bob/2026-05-01-hello.md").is_file())
    check("the missing round deleted nothing", r.deleted == 0, str(r.to_dict()))
    drift = author_drift(first.source, work, excl, first)
    check("a missing entry leaves the author out of sync", drift["in_sync"] is False, str(drift))

    write(author_root, "theme.css", "body{color:red}\n")
    write(author_root, "2026-05-01-hello.css", ".post{}\n")
    styles = author([
        {"path": "theme.css", "target": "assets/authors/bob.css"},
        {"path": "2026-05-01-hello.css", "target": "assets/authors/bob/2026-05-01-hello.css"},
    ])
    sync_author(styles.source, work, excl, styles)
    check(
        "theme.css lands on the per-author stylesheet",
        (work / "assets/authors/bob.css").read_text(encoding="utf-8") == "body{color:red}\n",
    )
    check(
        "a post's sibling stylesheet lands under the author's folder",
        (work / "assets/authors/bob/2026-05-01-hello.css").is_file(),
    )

    write(author_root, ".git/config", "[core]\n")
    skipped = author([{"path": ".git/config", "target": "_posts/bob/git-config.md"}])
    r = sync_author(skipped.source, work, excl, skipped)
    check(
        "an entry the site excludes is skipped, not copied",
        r.skipped == ["_posts/bob/git-config.md"] and not (work / "_posts/bob/git-config.md").exists(),
        str(r.to_dict()),
    )

    # deletion: only a target the app named
    write(work, "_posts/bob/2026-04-01-old.md", "old\n")
    write(work, "_posts/bob/2026-04-03-kept.md", "kept\n")
    removal = author([], removed=["_posts/bob/2026-04-01-old.md"])
    r = sync_author(removal.source, work, excl, removal)
    check(
        "a target named in removed is deleted",
        r.deleted == 1 and not (work / "_posts/bob/2026-04-01-old.md").exists(),
        str(r.to_dict()),
    )
    check("the deletion is reported in removed", r.removed == ["_posts/bob/2026-04-01-old.md"], str(r.to_dict()))
    check("🔒 a target nobody named survives", (work / "_posts/bob/2026-04-03-kept.md").is_file())
    check("the author's post directory survives while it holds posts", (work / "_posts/bob").is_dir())
    r = sync_author(removal.source, work, excl, removal)
    check("removing a target that is not there is harmless", r.ok and r.deleted == 0, str(r.to_dict()))

    # the whole author folder vanished: nothing of theirs may be deleted
    write(work, "_posts/bob/2026-04-02-gone.md", "published\n")
    gone = author([post], removed=["_posts/bob/2026-04-02-gone.md"])
    shutil.rmtree(author_root)
    r = sync_author(gone.source, work, excl, gone)
    check("a vanished author folder reports its entries missing", r.missing == ["_posts/bob/2026-05-01-hello.md"], str(r.to_dict()))
    check("source_missing is reported", r.source_missing is True, str(r.to_dict()))
    check(
        "🔒 a vanished author folder deletes nothing",
        r.deleted == 0 and (work / "_posts/bob/2026-04-02-gone.md").is_file(),
        str(r.to_dict()),
    )
    check("the withheld removal is reported", r.withheld == ["_posts/bob/2026-04-02-gone.md"], str(r.to_dict()))
    check("a withheld removal keeps the author out of sync", r.in_sync is False, str(r.to_dict()))
    r = sync_author(gone.source, work, excl, gone, force=True)
    check(
        "force=true carries the withheld removal out",
        r.deleted == 1 and not (work / "_posts/bob/2026-04-02-gone.md").exists(),
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
    doomed = [f"_posts/carol/2026-03-0{index}-old.md" for index in range(5)]
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
    check("the emptied author directory is pruned", not (guard_work / "_posts/carol").exists())
    check("the shared _posts directory stays", (guard_work / "_posts").is_dir())

    # the skeleton mirror never touches an author area
    skel_src = root / "skel" / "src"
    skel_work = root / "skel" / "work"
    write(skel_src, "_config.yml", "title: t\n")
    write(skel_src, "_posts/bob/from-owner.md", "the owner has a folder of that name too\n")
    write(skel_work, "_posts/bob/2026-05-01-hello.md", "published\n")
    write(skel_work, "assets/authors/bob.css", "body{}\n")
    write(skel_work, "stale.md", "gone\n")
    skel_excl = Exclusion.for_site({".git"}, "_site", ["_posts/bob", "assets/authors"])
    r = sync(skel_src, skel_work, skel_excl, direction="forward")
    check("🔒 the skeleton mirror does not delete an author's post", (skel_work / "_posts/bob/2026-05-01-hello.md").is_file())
    check("🔒 the skeleton mirror does not delete an author's stylesheet", (skel_work / "assets/authors/bob.css").is_file())
    check(
        "🔒 the skeleton mirror does not echo its own _posts/<uid> over the author's",
        not (skel_work / "_posts/bob/from-owner.md").exists(),
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
    check("the skeleton policy hides the author's posts", policy_app.exclusion(policy_cfg.site).matches(("_posts", "bob", "x.md")))
    check(
        "the skeleton policy hides the author's stylesheet",
        policy_app.exclusion(policy_cfg.site).matches(("assets", "authors", "bob.css")),
    )
    check(
        "the author policy does not hide the author's own targets",
        not policy_app.author_exclusion(policy_cfg.site).matches(("_posts", "bob", "x.md"))
        and not policy_app.author_exclusion(policy_cfg.site).matches(("assets", "authors", "bob.css")),
    )
    plain_cfg = load_config(
        _author_site_config(root / "policy-plain", nc_root, "git@example.invalid:x/y.git", None)
    )
    plain_app = App(plain_cfg)
    check(
        "a site without authors adds no author exclusion at all",
        not plain_app.exclusion(plain_cfg.site).matches(("_posts", "bob", "x.md"))
        and not plain_app.exclusion(plain_cfg.site).matches(("assets", "authors", "bob.css")),
    )

    # the bootstrap of a completely empty source: the reverse fill never writes an author's own
    # files back into that author's folder, and the author round still contributes
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
                {"path": "2026-05-01-hello.md", "target": "_posts/bob/2026-05-01-hello.md"}])],
        ))
        write(init_cfg.site.work, "_config.yml", "title: t\n")
        res = App(init_cfg).do_init(init_cfg.site)
        check("init takes the reverse fill for an empty source", res.get("bootstrap") == "reverse_fill", str(res)[:200])
        check("init still mirrored the author's post", (init_cfg.site.work / "_posts/bob/2026-05-01-hello.md").is_file())
        check(
            "🔒 the reverse fill wrote nothing into the author's own folder",
            sorted(path.name for path in init_author.iterdir()) == ["2026-05-01-hello.md"],
            str(sorted(path.name for path in init_author.iterdir())),
        )


def test_preview_split(tmp: Path) -> None:
    print("\n[25] two directories: preview holds every file, the commit tree only the on-site set")
    import threading

    from pages_bridge.server import App, build_server

    root = tmp / "split"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t"
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
        "🔒 the commit tree holds only the on-site author file",
        (site.work / "tmp/bob/public.md").is_file()
        and not (site.work / "tmp/bob/internal.md").exists()
        and not (site.work / "tmp/bob/notes/pic.png").exists(),
        str(sorted(p.name for p in (site.work / "tmp/bob").rglob("*") if p.is_file())) if (site.work / "tmp/bob").exists() else "missing",
    )
    check(
        "both trees hold the site's own files",
        (site.preview / "index.md").is_file() and (site.work / "index.md").is_file(),
    )
    reported = result.to_dict()
    check(
        "the reported round carries the preview tree separately",
        isinstance(reported.get("preview"), dict)
        and [item["uid"] for item in reported["preview"].get("authors", [])] == ["bob"],
        str(reported.get("preview"))[:300],
    )
    check(
        "the preview round copied the internal file, the commit round did not",
        reported["preview"]["authors"][0]["copied"] == 3 and reported["authors"][0]["copied"] == 1,
        str(reported["preview"]["authors"]) + " / " + str(reported["authors"]),
    )

    # a page that is turned internal leaves the commit tree at the next mirror but stays previewable
    cfg2 = load_config(_author_site_config(
        root / "turned", nc_root, "git@example.invalid:x/y.git", [author([internal, public, image], [])]
    ))
    site2 = cfg2.site
    App(cfg2).do_mirror(site2)  # first round: nothing on site yet
    check(
        "🔒 an empty on-site list keeps the commit tree empty of author files",
        not (site2.work / "tmp/bob/public.md").exists()
        and (site2.preview / "tmp/bob/public.md").is_file(),
        str((site2.work / "tmp/bob").exists()),
    )
    # now publish it, then turn it internal: the commit copy has to go, the preview copy has to stay
    cfg3 = load_config(_author_site_config(
        root / "turned2", nc_root, "git@example.invalid:x/y.git", [author([internal, public], [public])]
    ))
    site3 = cfg3.site
    App(cfg3).do_mirror(site3)
    check("the approved file is in the commit tree", (site3.work / "tmp/bob/public.md").is_file())
    cfg4 = load_config(_author_site_config(
        root / "turned3", nc_root, "git@example.invalid:x/y.git", [author([internal, public], [])]
    ))
    site4 = cfg4.site
    # the second directory is a *different* path (a fresh root), so seed the commit copy by hand:
    # what matters is that the commit round deletes a target the new on-site list omits
    write(site4.work, "tmp/bob/public.md", "a public page\n")
    write(site4.preview, "tmp/bob/public.md", "a public page\n")
    result = App(cfg4).do_mirror(site4)
    check(
        "a page turned internal leaves the commit tree",
        not (site4.work / "tmp/bob/public.md").exists(),
        str(result.to_dict()["authors"])[:300],
    )
    check(
        "🔒 and it stays in the preview tree (an internal page must stay previewable)",
        (site4.preview / "tmp/bob/public.md").is_file(),
    )

    # a file deleted from the author area leaves both trees (the app names it in `removed`)
    cfg5 = load_config(_author_site_config(
        root / "deleted", nc_root, "git@example.invalid:x/y.git",
        [author([public], [public], removed=["tmp/bob/internal.md"])],
    ))
    site5 = cfg5.site
    write(site5.work, "tmp/bob/internal.md", "an internal page\n")
    write(site5.preview, "tmp/bob/internal.md", "an internal page\n")
    App(cfg5).do_mirror(site5)
    check(
        "a removed file leaves the commit tree and the preview tree",
        not (site5.work / "tmp/bob/internal.md").exists()
        and not (site5.preview / "tmp/bob/internal.md").exists(),
    )

    # ── the build runs in the preview tree, and the commit tree is never built ─────────────────
    build_root = tmp / "split-build"
    build_nc = build_root / "ncdata"
    build_src = build_nc / "alice" / "files" / "Sites" / "t"
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
        not (build_site_cfg.work / "_site").exists(),
        str(sorted(p.name for p in build_site_cfg.work.iterdir())) if build_site_cfg.work.is_dir() else "missing",
    )

    # ── ignored artifacts that exist only in the preview tree ──────────────────────────────────
    # `git ls-files --others --ignored` can only report files that exist in the commit tree, but
    # the build creates vendor/bundle and .jekyll-cache in the preview tree. Those must never be
    # mistaken for stale source (deleted) or counted against the delete guard.
    if shutil.which("git") is not None and _capture_available():
        ignore_root = tmp / "split-ignored"
        ignore_nc = ignore_root / "ncdata"
        ignore_src = ignore_nc / "alice" / "files" / "Sites" / "t"
        write(ignore_src, ".gitignore", ".jekyll-cache/\nvendor/\n_site/\n")
        write(ignore_src, "_config.yml", "title: t\n")
        ignore_cfg = load_config(_author_site_config(
            ignore_root, ignore_nc, "git@example.invalid:x/y.git", None
        ))
        ignore_site = ignore_cfg.site
        first = App(ignore_cfg).do_mirror(ignore_site)
        check(
            "a site whose repository ignores its build artifacts mirrors once",
            first.ok,
            str(first.to_dict())[:300],
        )
        write(ignore_site.preview, "vendor/bundle/gems/demo.rb", "# gem\n")
        write(ignore_site.preview, ".jekyll-cache/jekyll/cache", "cache\n")
        second = App(ignore_cfg).do_mirror(ignore_site)
        check(
            "a second mirror does not delete a dependency tree the commit tree never had",
            second.preview is not None and second.preview.ok is True
            and second.preview.deleted == 0
            and (ignore_site.preview / "vendor/bundle/gems/demo.rb").is_file()
            and (ignore_site.preview / ".jekyll-cache/jekyll/cache").is_file(),
            str((second.preview or second).to_dict())[:400],
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


def test_author_http(tmp: Path) -> None:
    print("\n[23] author sources over HTTP (mirror, status, push validation)")
    import threading

    from pages_bridge.server import build_server

    root = tmp / "ahttp"
    nc_root = root / "ncdata"
    site_src = nc_root / "alice" / "files" / "Sites" / "t"
    site_src.mkdir(parents=True, exist_ok=True)
    write(site_src, "_config.yml", "title: t\n")
    write(site_src, "_posts/bob/from-owner.md", "the owner has a folder of that name too\n")
    bob_src = nc_root / "bob" / "files" / "Pages" / "posts"
    write(bob_src, "2026-05-01-hello.md", "hello from bob\n")
    write(bob_src, "theme.css", "body{color:red}\n")
    carol_src = nc_root / "carol" / "files" / "Pages" / "posts"
    write(carol_src, "2026-05-02-carol.md", "carol was here\n")

    bob_author = {
        "uid": "bob", "path": "/Pages/posts",
        "entries": [
            {"path": "2026-05-01-hello.md", "target": "_posts/bob/2026-05-01-hello.md"},
            {"path": "theme.css", "target": "assets/authors/bob.css"},
        ],
        "removed": [],
    }
    carol_author = {
        "uid": "carol", "path": "/Pages/posts",
        "entries": [{"path": "2026-05-02-carol.md", "target": "_posts/carol/2026-05-02-carol.md"}],
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
        status, body = call("POST", "/site/mirror", body={})
        check("POST /site/mirror with authors -> 200", status == 200, f"{status} {str(body)[:200]}")
        check(
            "the round reports one entry per author, in config order",
            [item["uid"] for item in body.get("authors", [])] == ["bob", "carol"],
            str(body.get("authors"))[:200],
        )
        check("the round reports authors_ok", body.get("authors_ok") is True, str(body)[:200])
        check(
            "bob's post landed at its target",
            (work / "_posts/bob/2026-05-01-hello.md").read_text(encoding="utf-8") == "hello from bob\n",
        )
        check(
            "carol's post landed in her own folder",
            (work / "_posts/carol/2026-05-02-carol.md").read_text(encoding="utf-8") == "carol was here\n",
        )
        check(
            "the stylesheet mapping landed",
            (work / "assets/authors/bob.css").read_text(encoding="utf-8") == "body{color:red}\n",
        )
        check(
            "🔒 the site owner's _posts/bob is not echoed into the work copy",
            not (work / "_posts/bob/from-owner.md").exists(),
        )
        check(
            "both author posts are Jekyll documents",
            _jekyll_posts(work) == ["_posts/bob/2026-05-01-hello.md", "_posts/carol/2026-05-02-carol.md"],
            str(_jekyll_posts(work)),
        )
        bob = body["authors"][0]
        check(
            "the author block carries the documented counters",
            bob["copied"] == 2 and bob["updated"] == 0 and bob["bytes"] > 0,
            str(bob),
        )
        check("missing and removed start empty", bob["missing"] == [] and bob["removed"] == [], str(bob))

        status, body = call("POST", "/site/mirror", body={})
        check(
            "a second round writes nothing",
            all(item["copied"] == 0 and item["updated"] == 0 for item in body["authors"]),
            str(body["authors"])[:200],
        )
        check(
            "a round with nothing to do is in sync",
            all(item["in_sync"] for item in body["authors"]),
            str(body["authors"])[:200],
        )

        status, body = call("GET", "/site/status")
        authors = body.get("authors") or []
        check("GET /site/status carries the authors", [item["uid"] for item in authors] == ["bob", "carol"], str(authors)[:200])
        check(
            "a status author has every documented key",
            all(k in authors[0] for k in ("uid", "entries", "copied", "updated", "bytes", "missing", "removed", "drift")),
            str(sorted(authors[0])),
        )
        check("the status counts the pushed entries", [item["entries"] for item in authors] == [2, 1], str([item["entries"] for item in authors]))
        check(
            "the status drift is a dict with in_sync",
            isinstance(authors[0]["drift"], dict) and authors[0]["drift"]["in_sync"] is True,
            str(authors[0].get("drift")),
        )
        status, body = call("GET", "/admin/config")
        check(
            "GET /admin/config echoes the authors",
            [item["uid"] for item in (body.get("site") or {}).get("authors", [])] == ["bob", "carol"],
            str(body.get("site"))[:200],
        )

        # a source file that vanished: reported, never deleted
        (bob_src / "theme.css").unlink()
        status, body = call("POST", "/site/mirror", body={})
        bob = body["authors"][0]
        check("a vanished source is reported missing", bob["missing"] == ["assets/authors/bob.css"], str(bob))
        check("🔒 the vanished source's target is not deleted", (work / "assets/authors/bob.css").is_file())
        check("that round deleted nothing", bob["deleted"] == 0 and bob["removed"] == [], str(bob))
        status, body = call("GET", "/site/status")
        check("the status shows the missing target", body["authors"][0]["missing"] == ["assets/authors/bob.css"], str(body["authors"][0])[:200])
        check("the status marks that author out of sync", body["authors"][0]["drift"]["in_sync"] is False, str(body["authors"][0]["drift"]))
        check("the other author is still in sync", body["authors"][1]["drift"]["in_sync"] is True, str(body["authors"][1]["drift"]))

        # an explicit removal from the app deletes, a dry run only plans
        write(work, "_posts/bob/2026-02-01-dry.md", "still there\n")
        dry_author = {**bob_author, "entries": [bob_author["entries"][0]], "removed": ["_posts/bob/2026-02-01-dry.md"]}
        status, body = call("POST", "/admin/config", body={
            "revision": 60, "global": {"mirror_delete_guard_min": 2}, "site": site_obj([dry_author, carol_author]),
        })
        check("a push naming a removal -> 200", status == 200, f"{status} {str(body)[:200]}")
        status, body = call("POST", "/site/mirror", body={"dry_run": True})
        check(
            "a dry run plans the deletion without doing it",
            body["authors"][0]["deleted"] == 1 and (work / "_posts/bob/2026-02-01-dry.md").is_file(),
            str(body["authors"][0])[:200],
        )
        check("the dry run reports the author out of sync", body["authors"][0]["in_sync"] is False, str(body["authors"][0])[:200])
        status, body = call("GET", "/site/status")
        check(
            "the status shows the pending removal",
            body["authors"][0]["drift"]["pending_delete"] == ["_posts/bob/2026-02-01-dry.md"],
            str(body["authors"][0]["drift"]),
        )
        status, body = call("POST", "/site/mirror", body={})
        check(
            "the named target is deleted",
            body["authors"][0]["deleted"] == 1 and not (work / "_posts/bob/2026-02-01-dry.md").exists(),
            str(body["authors"][0])[:200],
        )
        check("the deletion is reported in removed", body["authors"][0]["removed"] == ["_posts/bob/2026-02-01-dry.md"], str(body["authors"][0])[:200])

        # a mass removal trips the guard of that author, and force still carries it out
        orphans = [f"_posts/bob/2026-03-0{index}-old.md" for index in range(3)]
        for rel in orphans:
            write(work, rel, "old post\n")
        mass_author = {**bob_author, "entries": [bob_author["entries"][0]], "removed": orphans}
        status, body = call("POST", "/admin/config", body={
            "revision": 61, "global": {"mirror_delete_guard_min": 2}, "site": site_obj([mass_author, carol_author]),
        })
        check("a push naming three removals -> 200", status == 200, f"{status} {str(body)[:200]}")
        status, body = call("POST", "/site/mirror", body={})
        check("a mass removal answers 409", status == 409, f"{status} {str(body)[:200]}")
        check("the author block reports the tripped guard", body["authors"][0]["guard_tripped"] is True, str(body["authors"][0])[:200])
        check(
            "🔒 the guarded round deleted none of them",
            all((work / rel).is_file() for rel in orphans),
            str([rel for rel in orphans if not (work / rel).is_file()]),
        )
        status, body = call("GET", "/site/status")
        check("the status reports the failed author round", body["authors"][0]["ok"] is False, str(body["authors"][0])[:200])
        check("and carries its guard message", "budget" in str(body["authors"][0].get("guard_message", "")), str(body["authors"][0].get("guard_message"))[:200])
        status, body = call("POST", "/site/mirror", body={"force": True})
        check("force=true carries the mass removal out", status == 200 and body["authors"][0]["deleted"] == 3, str(body.get("authors"))[:200])
        check("the orphans are gone", all(not (work / rel).exists() for rel in orphans))

        # every rejected push is a 400 naming the offending field, and changes nothing
        bad_cases = [
            ("a target outside the allowed areas -> 400 naming target",
             {**bob_author, "entries": [{"path": "x.md", "target": "index.html"}]}, "target"),
            ("a duplicate target -> 400 naming the duplicate",
             {**bob_author, "entries": [{"path": "a.md", "target": "_posts/bob/x.md"},
                                        {"path": "b.md", "target": "_posts/bob/x.md"}]}, "duplicate"),
            ("a relative author path -> 400 naming path", {**bob_author, "path": "Pages/posts"}, "path"),
            ("another author's post as target -> 400",
             {**bob_author, "entries": [{"path": "x.md", "target": "_posts/carol/x.md"}]}, "target"),
            ("a removal outside the author's area -> 400",
             {**bob_author, "removed": ["_config.yml"]}, "target"),
            ("a target that escapes the site -> 400",
             {**bob_author, "entries": [{"path": "x", "target": "../escape"}]}, "target"),
        ]
        for index, (name, bad_author, field) in enumerate(bad_cases):
            status, body = call("POST", "/admin/config", body={
                "revision": 70 + index,
                "global": {"mirror_delete_guard_min": 2},
                "site": site_obj([bad_author, carol_author]),
            })
            check(name, status == 400 and body.get("error") == "BAD_REQUEST" and field in json.dumps(body), f"{status} {str(body)[:200]}")
        status, body = call("GET", "/admin/config")
        check(
            "a rejected push left the running config alone",
            [item["uid"] for item in (body.get("site") or {}).get("authors", [])] == ["bob", "carol"]
            and body["site"]["authors"][0]["removed"] == orphans,
            str(body.get("site"))[:200],
        )

        # ── the configurable author directory, over a real push ─────────────────────────────────
        write(bob_src.parent, "notes/2026-05-01-img/pic.png", "PNG\n")
        write(site_src, "people/bob/from-owner.png", "the owner has a folder of that name too\n")
        people_bob = {
            "uid": "bob", "path": "/Pages",
            "entries": [
                {"path": "notes/2026-05-01-img/pic.png",
                 "target": "people/bob/notes/2026-05-01-img/pic.png"},
            ],
            "removed": [],
        }
        status, body = call("POST", "/admin/config", body={
            "revision": 90,
            "global": {"mirror_delete_guard_min": 2},
            "site": {**site_obj([people_bob, carol_author]), "author_root": "people"},
        })
        check(
            "a push with author_root and a nested image target -> 200",
            status == 200 and body.get("ok") is True,
            f"{status} {str(body)[:200]}",
        )
        status, body = call("POST", "/site/mirror", body={})
        check("that mirror round -> 200", status == 200, f"{status} {str(body)[:200]}")
        check(
            "the nested image under the pushed root is mirrored",
            (work / "people/bob/notes/2026-05-01-img/pic.png").read_text(encoding="utf-8") == "PNG\n",
            str((work / "people/bob/notes/2026-05-01-img/pic.png").exists()),
        )
        check(
            "🔒 the site owner's file under that root is not echoed into the work copy",
            not (work / "people/bob/from-owner.png").exists(),
        )
        status, body = call("GET", "/admin/config")
        check(
            "GET /admin/config echoes author_root",
            (body.get("site") or {}).get("author_root") == "people",
            str(body.get("site"))[:200],
        )
        status, body = call("POST", "/admin/config", body={
            "revision": 91,
            "global": {"mirror_delete_guard_min": 2},
            "site": {**site_obj([{**people_bob, "entries": [
                {"path": "x.png", "target": "tmp/bob/x.png"}]}]), "author_root": "people"},
        })
        check(
            "a target outside the pushed root -> 400 naming target",
            status == 400 and body.get("error") == "BAD_REQUEST" and "target" in json.dumps(body),
            f"{status} {str(body)[:200]}",
        )
        for index, value in enumerate(("a/b", "..")):
            status, body = call("POST", "/admin/config", body={
                "revision": 92 + index,
                "global": {"mirror_delete_guard_min": 2},
                "site": {**site_obj([people_bob, carol_author]), "author_root": value},
            })
            check(
                f"a pushed author_root {value!r} -> 400 naming author_root",
                status == 400 and body.get("error") == "BAD_REQUEST" and "author_root" in json.dumps(body),
                f"{status} {str(body)[:200]}",
            )
        status, body = call("GET", "/admin/config")
        check(
            "the rejected author_root pushes changed nothing",
            (body.get("site") or {}).get("author_root") == "people",
            str(body.get("site"))[:200],
        )
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)

    # a config without authors is the single-source bridge, unchanged
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
        status, body = call2("POST", "/site/mirror", body={})
        check(
            "the single-source mirror has no author block",
            status == 200 and "authors" not in body and "authors_ok" not in body,
            f"{status} {str(sorted(body))[:200]}",
        )
        check("the single-source work copy is mirrored as before", (plain_cfg.site.work / "_config.yml").is_file())
        check(
            "without authors the owner's _posts/<uid> folder is mirrored again",
            (plain_cfg.site.work / "_posts/bob/from-owner.md").is_file(),
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
    site_src = nc_root / "alice" / "files" / "Sites" / "t"
    site_src.mkdir(parents=True, exist_ok=True)
    write(site_src, "_config.yml", "title: t\n")
    bob_src = nc_root / "bob" / "files" / "Pages" / "posts"
    write(bob_src, "2026-05-01-hello.md", "hello from bob\n")
    bob_author = {
        "uid": "bob", "path": "/Pages/posts",
        "entries": [{"path": "2026-05-01-hello.md", "target": "_posts/bob/2026-05-01-hello.md"}],
        "removed": [],
    }

    remote = _make_bare_remote(root / "seed", {"_config.yml": "title: t\n"})
    cfg = load_config(_author_site_config(root, nc_root, str(remote), [bob_author]))
    work = cfg.site.work
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
            "init clones the repository",
            status == 200 and (body.get("git") or {}).get("action") == "clone",
            f"{status} {str(body)[:200]}",
        )
        check("init mirrored the author's post too", (work / "_posts/bob/2026-05-01-hello.md").is_file())
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

        # a site file the app does not name in this publish
        write(site_src, "owner-note.md", "the owner's own change\n")
        status, body = call("POST", "/site/mirror", body={})
        check("the site owner's new file reached the work copy", (work / "owner-note.md").is_file())

        status, body = call("POST", "/site/publish", body={"message": "bob only", "stage_paths": ["_posts/bob"]})
        check("publish with stage_paths -> 200", status == 200 and bool(body.get("commit")), f"{status} {str(body)[:200]}")
        check("the response echoes the staged paths", body.get("stage_paths") == ["_posts/bob"], str(body.get("stage_paths")))
        committed = gitops.run(work, ["show", "--name-only", "--pretty=format:", "HEAD"], check=False).stdout.split()
        check("only the named path is in the commit", committed == ["_posts/bob/2026-05-01-hello.md"], str(committed))
        check(
            "the unnamed site file is still uncommitted",
            (work / "owner-note.md").is_file() and "owner-note.md" in json.dumps(body.get("git")),
            str(body.get("git"))[:200],
        )

        status, body = call("POST", "/site/publish", body={"message": "the rest"})
        check("publish without stage_paths still stages everything", status == 200 and bool(body.get("commit")), f"{status} {str(body)[:200]}")
        check("that response has no stage_paths key", "stage_paths" not in body, str(sorted(body)))
        committed = gitops.run(work, ["show", "--name-only", "--pretty=format:", "HEAD"], check=False).stdout.split()
        check("the site file is committed by that publish", "owner-note.md" in committed, str(committed))

        # the commit tree is never built: publish is mirror -> stage -> commit -> push, nothing else
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
            not (work / "_site").exists(),
            str(sorted(p.name for p in work.iterdir())),
        )

        status, body = call("POST", "/site/publish", body={"message": "x", "stage_paths": ["../escape"]})
        check("a stage_path outside the work copy -> 400", status == 400 and body.get("error") == "BAD_REQUEST", f"{status} {body}")
        status, body = call("POST", "/site/publish", body={"message": "x", "stage_paths": "not-a-list"})
        check("a stage_paths that is not an array -> 400", status == 400 and body.get("error") == "BAD_REQUEST", f"{status} {body}")
    finally:
        httpd.shutdown()
        httpd.server_close()
        thread.join(timeout=5)


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
        shutil.rmtree(tmp_root, ignore_errors=True)
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
        test_default_work_root(tmp_root)
        test_nc_user_required(tmp_root)
        test_single_site(tmp_root)
        test_retime(tmp_root)
        test_gemfile_lock_not_mirrored(tmp_root)
        test_gitignore_driven(tmp_root)
        test_http_layer(tmp_root)
        test_paired_state(tmp_root)
        test_pairing_code(tmp_root)
        test_author_config(tmp_root)
        test_author_mirror(tmp_root)
        test_preview_split(tmp_root)
        test_author_http(tmp_root)
        test_author_publish(tmp_root)
    finally:
        shutil.rmtree(tmp_root, ignore_errors=True)

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
