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
) -> GitOutput:
    argv = ["git", *args]
    try:
        proc = subprocess.run(
            argv,
            cwd=str(work),
            capture_output=True,
            text=True,
            errors="replace",
            timeout=timeout,
            env=_git_env(env),
            shell=False,
            input=stdin_text,
        )
    except subprocess.TimeoutExpired:
        raise GitError("git command timed out", argv=argv, timeout=timeout) from None
    except FileNotFoundError:
        raise GitError("git executable not found", argv=argv) from None

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
    """Parse ``git status --porcelain=v2 --branch``."""
    out = run(work, ["status", "--porcelain=v2", "--branch", "--untracked-files=all"], check=False)
    if not out.ok:
        raise GitError("git status failed", stderr=out.stderr.strip()[:1000])

    st = GitStatus()
    for line in out.stdout.splitlines():
        if line.startswith("# branch.oid "):
            st.head = line.split(" ", 2)[2].strip()
        elif line.startswith("# branch.head "):
            st.branch = line.split(" ", 2)[2].strip()
        elif line.startswith("# branch.upstream "):
            st.upstream = line.split(" ", 2)[2].strip()
        elif line.startswith("# branch.ab "):
            parts = line.split()
            for token in parts[2:]:
                if token.startswith("+"):
                    st.ahead = int(token[1:] or 0)
                elif token.startswith("-"):
                    st.behind = int(token[1:] or 0)
        elif line.startswith(("1 ", "2 ", "u ")):
            path = line.split("\t", 1)[-1] if "\t" in line else line.split(" ", 8)[-1]
            st.changed.append(path)
            xy = line.split(" ", 2)[1] if len(line.split(" ", 2)) > 1 else ".."
            if xy[0] not in (".", "?"):
                st.staged.append(path)
        elif line.startswith("? "):
            st.untracked.append(line[2:].strip())
        elif line.startswith("! "):
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


def init_or_adopt(work: Path, repo_url: str, branch: str, remote_name: str) -> dict:
    """Make ``work`` track ``repo_url/branch``: clone, adopt, or init in place."""
    # an unreachable remote is not fatal: the local content stays usable
    work.mkdir(parents=True, exist_ok=True)
    has_content = any(p.name != ".git" for p in work.iterdir())

    if is_repo(work):
        # set-url, not remove+add: removing a remote drops its tracking refs and branch config
        existing = run(work, ["remote", "get-url", remote_name], check=False)
        if existing.ok:
            run(work, ["remote", "set-url", remote_name, repo_url])
        else:
            run(work, ["remote", "add", remote_name, repo_url])
        fetch = run(work, ["fetch", remote_name, branch, "--prune"], check=False, timeout=300)
        return {
            "action": "adopt",
            "work": str(work),
            "fetched": fetch.ok,
            "fetch_error": None if fetch.ok else fetch.stderr.strip()[:300],
        }

    if not has_content:
        run(work, ["clone", "--branch", branch, repo_url, "."], timeout=600)
        info("repository cloned", work=str(work), repo=repo_url, branch=branch)
        return {"action": "clone", "work": str(work), "fetched": True}

    # the directory already holds files, so adopt it in place
    run(work, ["init", "-b", branch])
    run(work, ["remote", "add", remote_name, repo_url])
    fetch = run(work, ["fetch", remote_name, branch, "--prune"], check=False, timeout=300)
    reset = run(work, ["reset", "--mixed", f"{remote_name}/{branch}"], check=False)
    info("adopted existing directory in place", work=str(work), repo=repo_url, fetched=fetch.ok)
    return {
        "action": "in_place",
        "work": str(work),
        "fetched": fetch.ok,
        "fetch_error": None if fetch.ok else fetch.stderr.strip()[:300],
        "reset_ok": reset.ok,
    }


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
    args = ["push"]
    if set_upstream:
        args.append("--set-upstream")
    args += [remote, branch]
    out = run(work, args, check=False, timeout=300)
    if not out.ok:
        stderr = out.stderr.strip()
        hint = ""
        if "Permission denied" in stderr or "publickey" in stderr:
            hint = (
                "SSH did not use the right key: ssh only tries the default file names "
                "(id_rsa / id_ecdsa / id_ed25519), so a custom-named key needs an IdentityFile "
                "entry in ~/.ssh/config and a matching Host alias in the remote. Also check that "
                "the public key is a Deploy key with Allow write access enabled."
            )
        elif "non-fast-forward" in stderr or "rejected" in stderr:
            hint = "The remote has new commits; pull before pushing."
        raise GitError("git push failed", stderr=stderr[:2000], hint=hint)
    info("pushed", remote=remote, branch=branch)
    return {"remote": remote, "branch": branch}


def pull(work: Path, remote: str, branch: str) -> dict:
    """Fast-forward pull; returns the list of changed files."""
    before = run(work, ["rev-parse", "HEAD"], check=False).stdout.strip()
    out = run(work, ["pull", "--ff-only", remote, branch], check=False, timeout=300)
    if not out.ok:
        raise GitError(
            "git pull failed (fast-forward only)",
            stderr=out.stderr.strip()[:2000],
            hint="Local commits have diverged from the remote; resolve this by hand.",
        )
    after = run(work, ["rev-parse", "HEAD"], check=False).stdout.strip()
    changed: list[str] = []
    if before and after and before != after:
        names = run(work, ["diff", "--name-only", before, after], check=False).stdout
        changed = [line for line in names.splitlines() if line.strip()]
    info("pulled", before=before[:10], after=after[:10], changed=len(changed))
    return {"before": before, "after": after, "changed": changed}
