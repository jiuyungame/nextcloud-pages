"""Git wrapper: argv arrays with ``shell=False`` and no interactive prompts."""

from __future__ import annotations

import os
import subprocess
from dataclasses import dataclass, field
from pathlib import Path
from typing import Mapping, Sequence

from .util import GitError, info, warn

DEFAULT_TIMEOUT = 180


def _git_env(extra: Mapping[str, str] | None = None) -> dict[str, str]:
    env = dict(os.environ)
    env.setdefault("HOME", "/root")
    env["GIT_TERMINAL_PROMPT"] = "0"      # never wait for input
    env["GIT_ASKPASS"] = "/bin/true"
    env["LC_ALL"] = "C"                   # stable, parseable output
    if extra:
        env.update({k: str(v) for k, v in extra.items()})
    return env


@dataclass
class GitOutput:
    """One finished git call: exit code, both output streams and the argv that ran."""

    code: int
    stdout: str
    stderr: str
    argv: list[str] = field(default_factory=list)

    @property
    def ok(self) -> bool:
        return self.code == 0

    @property
    def text(self) -> str:
        return (self.stdout + ("\n" + self.stderr if self.stderr else "")).strip()


def run(
    work: Path,
    args: Sequence[str],
    *,
    check: bool = True,
    timeout: int = DEFAULT_TIMEOUT,
    env: Mapping[str, str] | None = None,
    stdin_text: str | None = None,
    encoding: str | None = None,
) -> GitOutput:
    argv = ["git", *args]
    try:
        proc = subprocess.run(
            argv,
            cwd=str(work),
            capture_output=True,
            text=True,
            # ``None`` keeps the locale default every other call relies on. ``status()`` names UTF-8
            # explicitly: it reads the raw path bytes of ``-z`` records, which must not depend on the
            # locale the bridge happens to run under (``LC_ALL=C`` is only git's environment).
            encoding=encoding,
            errors="replace",
            timeout=timeout,
            env=_git_env(env),
            shell=False,
            input=stdin_text,
        )
    except subprocess.TimeoutExpired:
        raise GitError("git command timed out", argv=argv, timeout=timeout) from None
    except FileNotFoundError:
        # `subprocess.run` raises this for a missing executable *and* for a missing working directory.
        # Reporting both as "git executable not found" sent people to inspect a perfectly good git.
        if not work.is_dir():
            raise GitError(
                "the work copy directory does not exist",
                argv=argv,
                stderr=f"{work} — initialise the site before running git; init creates the repository and fetches its refs, it does not clone",
            ) from None
        raise GitError(
            "git executable not found",
            argv=argv,
            stderr=f"PATH={os.environ.get('PATH', '')} — install git or set PAGES_BRIDGE_GIT to its path",
        ) from None

    out = GitOutput(code=proc.returncode, stdout=proc.stdout, stderr=proc.stderr, argv=argv)
    if check and not out.ok:
        raise GitError("git command failed", argv=argv, code=out.code, stderr=out.stderr.strip()[:2000])
    return out


def is_repo(work: Path) -> bool:
    return (work / ".git").exists()


@dataclass
class GitStatus:
    branch: str = ""
    upstream: str = ""
    ahead: int = 0
    behind: int = 0
    dirty: bool = False
    changed: list[str] = field(default_factory=list)
    staged: list[str] = field(default_factory=list)
    untracked: list[str] = field(default_factory=list)
    head: str = ""

    def to_dict(self) -> dict:
        return {
            "branch": self.branch,
            "upstream": self.upstream,
            "ahead": self.ahead,
            "behind": self.behind,
            "dirty": self.dirty,
            "changed": self.changed[:100],
            "changed_total": len(self.changed),
            "staged": self.staged[:100],
            "untracked": self.untracked[:100],
            "untracked_total": len(self.untracked),
            "head": self.head,
        }


def status(work: Path) -> GitStatus:
    """Parse ``git status --porcelain=v2 --branch -z``; every path is the one git really holds.

    ``-z`` is not cosmetic here, it is what makes the paths usable:

    * **rename lines.** Without ``-z`` a rename record is
      ``2 <XY> … <X><score>\t<path>\t<origPath>``: the target comes first and the *source* second,
      so a ``split("\t", 1)[-1]`` returns the **source** - the path that no longer exists, and the
      one ``git add -A -- <path>`` fails on. With ``-z`` the record ends after the target and the
      source is the **next** NUL-separated record, which this parser consumes and drops: the target
      is what a caller stages and reports.
    * **non-ASCII names.** Without ``-z`` (and with the default ``core.quotePath``) a name outside
      ASCII arrives C-quoted - ``"tmp/\\344\\270\\255.md"`` - which is not a path either. ``-z``
      never quotes, and ``-c core.quotePath=false`` is passed as well so even a caller that reads a
      non-``-z`` form later cannot be surprised.

    The record shapes read here: ``1 <XY> <sub> <mH> <mI> <mW> <hH> <hI> <path>``,
    ``u <XY> <sub> <m1> <m2> <m3> <mW> <h1> <h2> <h3> <path>`` and
    ``2 <XY> <sub> <mH> <mI> <mW> <hH> <hI> <X><score> <path>`` (source in the next record), plus
    ``? <path>`` (untracked) and ``! <path>`` (ignored). The path is always the remainder of the
    record, so a space - or a tab, or a leading dot - in a name is just part of it.
    """
    out = run(
        work,
        [
            # ``-c`` before the subcommand: the setting has to be in force when status runs
            "-c",
            "core.quotePath=false",
            "status",
            "--porcelain=v2",
            "-z",
            "--branch",
            "--untracked-files=all",
        ],
        check=False,
        # the records carry the raw names; decoding them with the locale would turn a UTF-8 name
        # into mojibake under a non-UTF-8 locale and make the path unusable for ``git add``
        encoding="utf-8",
    )
    if not out.ok:
        raise GitError("git status failed", stderr=out.stderr.strip()[:1000])

    st = GitStatus()
    records = out.stdout.split("\0")
    position = 0
    while position < len(records):
        record = records[position]
        position += 1
        if not record:
            continue
        if record.startswith("# branch.oid "):
            st.head = record[len("# branch.oid "):].strip()
        elif record.startswith("# branch.head "):
            st.branch = record[len("# branch.head "):].strip()
        elif record.startswith("# branch.upstream "):
            st.upstream = record[len("# branch.upstream "):].strip()
        elif record.startswith("# branch.ab "):
            for token in record[len("# branch.ab "):].split():
                if token.startswith("+"):
                    st.ahead = int(token[1:] or 0)
                elif token.startswith("-"):
                    st.behind = int(token[1:] or 0)
        elif record.startswith("2 "):
            # a rename/copy: the record holds the target, the next record holds the source
            fields = record.split(" ", 9)
            position += 1  # the source path is not a change of its own - drop it
            path = fields[9] if len(fields) > 9 else ""
            if path:
                st.changed.append(path)
                if len(fields) > 1 and fields[1][:1] not in (".", "?"):
                    st.staged.append(path)
        elif record.startswith("1 ") or record.startswith("u "):
            # `1` has 8 fields before the path, `u` has 10 (the three stage hashes)
            width = 8 if record[0] == "1" else 10
            fields = record.split(" ", width)
            path = fields[width] if len(fields) > width else ""
            if path:
                st.changed.append(path)
                if len(fields) > 1 and fields[1][:1] not in (".", "?"):
                    st.staged.append(path)
        elif record.startswith("? "):
            # literal, never stripped: a name may legitimately end with a space
            st.untracked.append(record[2:])
        elif record.startswith("! "):
            continue

    st.dirty = bool(st.changed or st.untracked)
    return st


def last_commit(work: Path) -> dict:
    out = run(work, ["log", "-1", "--pretty=format:%H%x1f%h%x1f%an%x1f%ae%x1f%cI%x1f%s"], check=False)
    if not out.ok or not out.stdout.strip():
        return {}
    parts = out.stdout.strip().split("\x1f")
    keys = ("sha", "short", "author", "email", "date", "subject")
    return dict(zip(keys, parts))


def diff(work: Path, *, staged: bool = False, path: str | None = None) -> str:
    args = ["diff", "--no-color", "--find-renames"]
    if staged:
        args.append("--cached")
    if path:
        args += ["--", path]
    return run(work, args, check=False).stdout


def diff_stat(work: Path) -> str:
    return run(work, ["diff", "--stat", "--no-color"], check=False).stdout.strip()


def ignored_entries(work: Path) -> list[str] | None:
    """List git-ignored entries in the work copy; ``None`` when git cannot answer."""
    # the repository's .gitignore is the authoritative answer for what is not source
    if not is_repo(work):
        return None
    out = run(
        work,
        ["ls-files", "--others", "--ignored", "--exclude-standard", "--directory", "-z"],
        check=False,
        timeout=120,
    )
    if not out.ok:
        warn("failed to read .gitignore, falling back to the built-in exclusion list", error=out.stderr.strip()[:200])
        return None
    return [entry for entry in out.stdout.split("\0") if entry]


def ignored_source_entries(repo: Path, tree: Path) -> list[str] | None:
    """The entries of ``tree`` the repository's ignore rules cover; ``None`` when git cannot answer.

    ``tree`` is a folder that is **not** the repository's work copy. ``ignored_entries`` cannot
    answer for one: ``git ls-files --others --ignored`` lists files of the work copy, so a tree the
    assembly has not written into yet always answers "nothing". That is the wrong question for the
    skeleton: ``source/`` is the repository content *before* it is assembled, and the entries its
    ``.gitignore`` ignores are exactly the ones that never exist in the commit tree - asking the
    commit tree would report them only on the round after they leaked in, and then not on the next
    one (the list would flip on every round).

    So git is asked with ``tree`` as the working tree (its own ``.gitignore`` files are the rules)
    and the repository supplies the git configuration, ``info/exclude`` and the global excludes
    file. ``--no-index`` is not needed - the index is the repository's, and every path of ``tree``
    is "other" there anyway; ``GIT_OPTIONAL_LOCKS=0`` keeps the call read-only, so it never takes
    the index lock or rewrites the work copy's index.
    """
    if not is_repo(repo) or not Path(tree).is_dir():
        return None
    out = run(
        Path(tree),
        [
            f"--git-dir={Path(repo) / '.git'}",
            f"--work-tree={Path(tree)}",
            "ls-files",
            "--others",
            "--ignored",
            "--exclude-standard",
            "--directory",
            "-z",
        ],
        check=False,
        timeout=120,
        env={"GIT_OPTIONAL_LOCKS": "0"},
    )
    if not out.ok:
        warn(
            "failed to read the .gitignore of the skeleton, using the configured exclusions only",
            error=out.stderr.strip()[:200],
        )
        return None
    return [entry for entry in out.stdout.split("\0") if entry]


def ignored_matches(work: Path, paths: Sequence[str], timeout: int = 120) -> set[str]:
    """The subset of site-relative ``paths`` the repository's ignore rules cover.

    ``git ls-files --others --ignored`` can only answer for files that exist in the work copy, but
    the preview tree holds build artifacts (``vendor/bundle``, ``.jekyll-cache``) that never exist
    there. Deleting them because no exclusion names them would wipe a dependency tree or trip the
    delete guard, so the target tree asks the same ignore rules by path, in one git call
    (``check-ignore --no-index`` does not need the file to exist).
    """
    if not paths or not is_repo(work):
        return set()
    payload = "\0".join(paths) + "\0"
    out = run(
        work,
        ["check-ignore", "--no-index", "-z", "--stdin"],
        check=False,
        timeout=timeout,
        stdin_text=payload,
    )
    # exit 0 = at least one path is ignored, 1 = none is; anything else is a git failure
    if out.code not in (0, 1):
        warn("failed to ask git about ignored paths", error=out.stderr.strip()[:200])
        return set()
    return {entry for entry in out.stdout.split("\0") if entry}


def init_or_clone(work: Path, repo_url: str, branch: str, remote_name: str) -> dict:
    """Establish the repository and fetch the remote's refs; never write files into the tree.

    The working tree's files come from the mirror of the Nextcloud source folder - git supplies refs
    and history here, nothing else. That is why init never clones or checks anything out: materialising
    the remote's files next to an empty source folder would make the next forward mirror (correctly)
    want to delete all of them to match the source, which is exactly the "delete budget exceeded"
    deadlock a clone used to cause. So init only:

    * ``git init`` when there is no repository yet (files that survived without .git are kept and the
      mirror reconciles them afterwards);
    * keeps the remote in step with the configuration;
    * fetches the branch, so its remote-tracking ref exists and ``remote_ahead``/publish/pull can use
      it. A remote that does not answer is reported as ``remote_unreachable`` and never raised: the
      site still works locally.

    Pull is the one path that lands files in this tree - only to package them for the app, which writes
    them into Nextcloud.

    The name is kept for the callers: there is no clone path left in here.
    """
    work.mkdir(parents=True, exist_ok=True)
    detail: dict = {"work": str(work)}
    if is_repo(work):
        detail["action"] = "adopt"
    else:
        detail["action"] = "in_place" if any(p.name != ".git" for p in work.iterdir()) else "init"
        init_local(work, branch)
    set_remote(work, remote_name, repo_url)
    if repo_url:
        fetch_out = fetch(work, remote_name, branch)
        detail["fetched"] = fetch_out.ok
        detail["fetch_error"] = None if fetch_out.ok else fetch_out.stderr.strip()[:300]
        if not fetch_out.ok:
            detail["remote_unreachable"] = True
            warn("the remote is unreachable; the repository was initialised locally", repo=repo_url, branch=branch)
    return detail


def init_local(work: Path, branch: str) -> dict:
    """Make ``work`` a repository with no remote, without touching its files.

    The site folder in Nextcloud is the source of truth, so nothing is copied out of git here: the
    caller mirrors the source into this repository afterwards and commits.
    """
    work.mkdir(parents=True, exist_ok=True)
    run(work, ["init", "-b", branch])
    info("repository initialised", work=str(work), branch=branch)
    return {"action": "init", "work": str(work), "branch": branch}


def set_remote(work: Path, remote_name: str, repo_url: str) -> None:
    """Keep the repository's remote in step with the configuration; empty means "no remote"."""
    existing = run(work, ["remote", "get-url", remote_name], check=False)
    if not repo_url:
        if existing.ok:
            run(work, ["remote", "remove", remote_name])
            info("remote removed", remote=remote_name)
        return
    if existing.ok:
        if existing.stdout.strip() != repo_url:
            # set-url, not remove+add: removing a remote drops its tracking refs and branch config
            run(work, ["remote", "set-url", remote_name, repo_url])
            info("remote updated", remote=remote_name, url=repo_url)
        return
    run(work, ["remote", "add", remote_name, repo_url])
    info("remote added", remote=remote_name, url=repo_url)


def remote_ahead(work: Path, remote_name: str, branch: str) -> int | None:
    """How many commits ``<remote>/<branch>`` has that ``HEAD`` does not, or ``None`` if unknown.

    Asked of the remote-tracking ref, so the caller decides when to pay for a fetch. A count that
    cannot be computed is reported as unknown rather than as zero: "0 new commits" would be a lie
    told to somebody about to replace their source.

    An **unborn HEAD** (init never checks anything out, so the first look at a fresh site has no
    commits at all) has every commit of the remote branch ahead of it: counting ``HEAD..ref`` would
    fail there, so the branch's own length is the answer.
    """
    if not is_repo(work):
        return None
    unborn = head_sha(work) == ""
    spec = f"{remote_name}/{branch}" if unborn else f"HEAD..{remote_name}/{branch}"
    out = run(work, ["rev-list", "--count", spec], check=False, timeout=60)
    if not out.ok:
        return None
    try:
        return max(0, int(out.stdout.strip()))
    except ValueError:
        return None


def fetch(work: Path, remote_name: str, branch: str, timeout: int = 300) -> GitOutput:
    """Update the remote-tracking ref; failures are reported, never raised."""
    return run(work, ["fetch", remote_name, branch, "--prune"], check=False, timeout=timeout)


def archive_ref(work: Path, target: Path, ref: str, *, pathspecs: Sequence[str] = ()) -> dict:
    """Package one commit-ish (``HEAD``, ``origin/main``…) as a tar.gz at ``target``.

    The bridge hands the site content to the Nextcloud side this way, which may not read the bridge's
    filesystem: the replacement travels over the API like every other write. Archiving a *ref* is what
    lets Init deliver the remote's content without ever checking it out into the work tree.

    ``pathspecs`` limits the archive to (or, with an exclude magic, removes from) a set of paths;
    :func:`archive_source` uses it for the published targets a Pull must not present as source.
    """
    target.parent.mkdir(parents=True, exist_ok=True)
    args = ["archive", "--format=tar.gz", f"--output={target}", ref]
    if pathspecs:
        args += ["--", *pathspecs]
    run(work, args, timeout=600)
    size = target.stat().st_size if target.is_file() else 0
    info("archived ref", ref=ref, path=str(target), bytes=size)
    return {"path": str(target), "bytes": size, "sha256": _sha256(target), "ref": ref}


def archive_source(
    work: Path,
    target: Path,
    ref: str = "HEAD",
    *,
    drop: Sequence[str] = (),
    drop_limit: int = 5000,
) -> dict:
    """Package the **source** part of one commit as a tar.gz: the Pull pipeline's archive.

    The repository is **flat**: its tree root is the Jekyll site (``_config.yml``, ``_layouts/``,
    ``authors-src/``…), the same content the Nextcloud ``source/`` folder holds plus the published
    pages. A Pull replaces that Nextcloud folder with the remote's content, so the archive is the
    whole ``HEAD`` **minus the paths this instance knows as published** (``drop``, read off
    ``site.online_dir`` before the pull): those are online content, not source, and putting them into
    ``source/`` would let the next Apply source commit them as the administrator's skeleton.

    Only *known* published paths can be excluded - the repository itself carries no layer marker any
    more - so a page published elsewhere but unknown to this instance travels in the archive. The
    answer says ``source_only: true`` (the archive is source by intent, never a checkout) and
    ``excluded: N`` (how many known online paths were left out). ``drop_limit`` bounds the argv: an
    absurdly long list is not worth a command line git would refuse, so it is skipped entirely (and
    the answer reports 0 excluded).
    """
    paths = [path.strip("/") for path in drop if path and path.strip("/")]
    dropped = 0
    pathspecs: list[str] = []
    if paths and len(paths) <= drop_limit:
        # `:(literal,exclude)` so a name with a glob character is a name, and an exclude-only pathspec
        # list means "everything but these" (git does not need a positive path next to it)
        pathspecs = [f":(literal,exclude){path}" for path in paths]
        dropped = len(paths)
    elif paths:
        warn(
            "too many published paths to leave out of the archive; it is packaged whole",
            paths=len(paths),
            limit=drop_limit,
        )
    if dropped:
        try:
            result = archive_ref(work, target, ref, pathspecs=pathspecs)
        except GitError as exc:
            # an old git may refuse an exclude-only pathspec list; the whole tree is still a usable
            # archive (the app gets the published pages too), so this is a warning, not a failed pull
            warn(
                "git refused to leave the published paths out of the archive; packaging the whole tree",
                error=str(exc)[:300],
                excluded=dropped,
            )
            result = archive_ref(work, target, ref)
            dropped = 0
    else:
        result = archive_ref(work, target, ref)
    info("archived the site source", ref=ref, path=str(target), excluded=dropped)
    return {**result, "source_only": True, "excluded": dropped}


def _sha256(path: Path) -> str:
    import hashlib

    digest = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def head_sha(work: Path) -> str:
    out = run(work, ["rev-parse", "HEAD"], check=False)
    return out.stdout.strip() if out.ok else ""


def stage_all(work: Path) -> None:
    run(work, ["add", "-A"])


def stage_paths(work: Path, paths: Sequence[str]) -> list[str]:
    """Stage exactly the given paths; returns the ones git refused.

    One call per path on purpose: git fails a whole ``add`` when a single pathspec matches nothing,
    and a caller that names a path which was already deleted must not lose the others.
    """
    # `-A` so a deletion inside a named directory is staged too, `--` so a path can never be read
    # as an option
    failed: list[str] = []
    for rel in paths:
        out = run(work, ["add", "-A", "--", rel], check=False)
        if not out.ok:
            failed.append(rel)
            warn("staging failed", path=rel, error=out.stderr.strip()[:200])
    if failed:
        warn("some paths were not staged", failed=len(failed), requested=len(paths))
    return failed


def commit(work: Path, message: str, *, author: tuple[str, str] | None = None) -> str | None:
    """Commit the index; returns ``None`` when there is nothing to commit."""
    staged = run(work, ["diff", "--cached", "--name-only"], check=False).stdout.strip()
    if not staged:
        return None
    args = ["commit", "-m", message]
    if author:
        name, email = author
        args += ["-c", f"user.name={name}", "-c", f"user.email={email}"]
    out = run(work, args, check=False)
    if not out.ok:
        raise GitError("git commit failed", stderr=out.stderr.strip()[:2000])
    sha = run(work, ["rev-parse", "HEAD"], check=False).stdout.strip()
    info("committed", sha=sha[:10], files=len(staged.splitlines()))
    return sha


def push(work: Path, remote: str, branch: str, *, set_upstream: bool = True) -> dict:
    """Push one branch; the outcome is reported, never raised, because the commit already happened.

    ``status`` is ``"ok"``, ``"rejected"`` (the remote has commits this branch does not: a
    fast-forward is impossible, which is what the app answers with the ahead count) or ``"failed"``
    (anything else: credentials, network). The caller decides what to tell the user.
    """
    args = ["push"]
    if set_upstream:
        args.append("--set-upstream")
    args += [remote, branch]
    out = run(work, args, check=False, timeout=300)
    if out.ok:
        info("pushed", remote=remote, branch=branch)
        return {"status": "ok", "remote": remote, "branch": branch, "stderr": ""}

    stderr = out.stderr.strip()
    hint = ""
    rejected = "non-fast-forward" in stderr or "fetch first" in stderr or "[rejected]" in stderr
    if "Permission denied" in stderr or "publickey" in stderr:
        hint = (
            "SSH did not use the right key: ssh only tries the default file names "
            "(id_rsa / id_ecdsa / id_ed25519), so a custom-named key needs an IdentityFile "
            "entry in ~/.ssh/config and a matching Host alias in the remote. Also check that "
            "the public key is a Deploy key with Allow write access enabled."
        )
    elif rejected:
        hint = "The remote has commits this branch does not; use Pull to bring them in."
    warn("push failed", rejected=rejected, error=stderr[:300])
    return {
        "status": "rejected" if rejected else "failed",
        "remote": remote,
        "branch": branch,
        "stderr": stderr[:2000],
        "hint": hint,
    }


def land_remote(work: Path, remote: str, branch: str) -> dict:
    """Make the working tree hold exactly ``<remote>/<branch>``; returns which path was used.

    Two paths, same complete-replacement semantics:

    * a repository that already has commits is moved with ``reset --hard`` (a fast-forward when the
      local history is an ancestor of the remote's, a forced move otherwise - the state a rejected
      push leaves behind);
    * an **unborn HEAD** - an empty repository, which is exactly what an init against an unreachable
      remote leaves behind - cannot be checked out by that reset in every situation (and untracked
      files a previous mirror left in the way make a plain checkout abort). The branch is created at
      the remote commit with ``checkout -f -B`` instead: ``-B`` creates/resets it, ``-f`` overwrites
      whatever the working tree held, so the replacement is as complete as the reset path.

    Raises :class:`GitError` when the remote branch is missing or the move fails.
    """
    target = f"{remote}/{branch}"
    exists = run(work, ["rev-parse", "--verify", f"refs/remotes/{target}"], check=False)
    if not exists.ok:
        raise GitError(
            "the remote branch does not exist",
            stderr=f"{target} was not found after the fetch",
            hint="Check the branch name in the site settings; it has to exist on the remote.",
        )
    unborn = head_sha(work) == ""
    if unborn:
        out = run(work, ["checkout", "-f", "-B", branch, target], check=False, timeout=300)
        path = "checkout"
    else:
        out = run(work, ["reset", "--hard", target], check=False, timeout=300)
        path = "reset"
    if not out.ok:
        raise GitError(
            f"git {path} failed while landing {target}",
            stderr=out.stderr.strip()[:2000],
            hint="The publish repository could not be moved to the remote branch; inspect it by hand.",
        )
    after = head_sha(work)
    info("remote branch landed", path=path, target=target, head=after[:10])
    return {"path": path, "target": target, "after": after}


def pull(work: Path, remote: str, branch: str) -> dict:
    """Make the publish tree hold exactly the remote branch; returns the list of changed files.

    Pull is a **complete replacement** on this side too: fetch, then land ``<remote>/<branch>`` with
    {@see land_remote()}. A repository with history is moved with ``reset --hard`` (fast-forward when
    possible, a forced move when it is not - which is exactly the state a rejected push leaves behind);
    a repository with an **unborn HEAD** - an empty shell created while the remote was unreachable - is
    created at the remote commit with ``checkout -f -B`` instead, because that is the one path that
    works when there is no branch to reset yet. There is no merge, no rebase and no force flag beyond
    the replacement: the content that would otherwise be combined is about to be replaced in Nextcloud
    anyway (and backed up first).

    Uncommitted files in the publish tree are discarded by either path on purpose: that tree is a
    mirror of the Nextcloud folder, never a place where work lives.
    """
    before = head_sha(work)
    fetch = run(work, ["fetch", remote, branch, "--prune"], check=False, timeout=300)
    if not fetch.ok:
        raise GitError(
            "git fetch failed",
            stderr=fetch.stderr.strip()[:2000],
            hint="The remote is unreachable or the branch does not exist; check the URL, the credentials and the branch name.",
        )
    landed = land_remote(work, remote, branch)
    after = landed["after"]
    changed: list[str] = []
    if before and after and before != after:
        names = run(work, ["diff", "--name-only", before, after], check=False).stdout
        changed = [line for line in names.splitlines() if line.strip()]
    info("pulled", before=before[:10], after=after[:10], changed=len(changed), path=landed["path"])
    return {
        "before": before,
        "after": after,
        "changed": changed,
        "target": landed["target"],
        # "reset" for a normal repository, "checkout" when an unborn HEAD had to be created: the
        # caller and the smoke tests use it to prove which case ran
        "path": landed["path"],
    }
