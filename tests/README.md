# tests/

Two kinds of test, split by what they need.

## `code/` — no Nextcloud instance needed

| Script | What it proves | Run |
|---|---|---|
| `proof-frontend.mjs` | every root identifier a template reads is a computed/data key/prop, every one it calls is a method, and each touched component renders (SSR) | `node tests/code/proof-frontend.mjs` |
| `smoke-app-tree.mjs` | `src/App.vue` renders the author's file tree, review badges and the resource pane | `node tests/code/smoke-app-tree.mjs` |
| `smoke-article-fields.mjs` | `src/ArticleFields.vue` renders the metadata table and its dialog; saving maps values to the right types | `node tests/code/smoke-article-fields.mjs` |
| `smoke-grant-picker.mjs` | `src/GrantPicker.vue` renders the granted groups and emits the id array | `node tests/code/smoke-grant-picker.mjs` |
| `smoke-pageState.mjs` | `reviewGrant` / `readShellState` in `src/pageState.js` (plain data, no browser) | `node tests/code/smoke-pageState.mjs` |
| `store-probe.php` | the review manifest's own logic (`ReviewStore`) against in-memory fakes: locking, ETag retries, import, JSON shape, cache mirroring; and the directory reader (`SiteTrees`) driven by a fake bridge - the two content directories come from `GET /site/paths`, the Files API scan is only the fallback | `php tests/code/store-probe.php` (from a Nextcloud checkout) |

The `.mjs` proofs need `npm ci` first (they import the app's own dev dependencies).

## `live/` — needs a deployed Nextcloud

Every `.sh` here drives a real instance over HTTPS with basic auth, creates throwaway users/groups,
and restores the configuration afterwards. They need root (for `occ`), a deployed app and the
one-shot app password issued by:

```bash
sudo bash tests/live/test-auth.sh grant     # → /root/.pages-test-pw (0600)
```

Run the whole set with one command, from the repository root (the suites inherit that working
directory; a few of them read the app source next to them):

```bash
sudo bash tests/live/run-all.sh
```

It runs the 12 suites in a fixed order. Before the first one it checks that it is root, that
`status.php` reports an installed instance that is not in maintenance mode, and that the `pages`
app is enabled (skipped with a warning when `occ` cannot be run), then issues the one-shot app
password if `/root/.pages-test-pw` is missing or empty. Each suite runs in its own subshell, so a
failure does not stop the suites after it. Exit code: 0 when every suite passed, 1 when one failed
or a pre-flight check did, 2 on a usage error.

| Option | What it does |
|---|---|
| `--list` | print the 12 suites in order and exit (no root, no instance, no password needed) |
| `--only <name>` | run a single suite (`test-authors` or `test-authors.sh`) |
| `--skip a,b` | skip the comma-separated suites |
| `--from <name>` | start at that suite, inclusive |

`NC_HOST` (default `https://localhost`) and `NC_INSECURE=1` (probe with `curl -k`, for a
self-signed certificate on another host) retarget the pre-flight probe; a set `NC_HOST` is also
handed to the suites as `BASE`. Everything else the suites read (`NC_USER`, `PW_FILE`, …) is
inherited.

Every suite's output goes to `tests/live/logs/<stamp>-<suite>.log` (gitignored). The run ends with
a summary — PASS/FAIL/SKIP, exit code, duration, log path — and, for each failure, the command to
rerun it alone:

```bash
sudo bash tests/live/test-authors.sh
```

The suites also run standalone, without the runner: the scripts self-locate via
`$(dirname "${BASH_SOURCE[0]}")` for their helpers and use the repo root for the app source they
inspect (`src/`, `templates/`, `lib/`, `l10n/`). `run-all.sh` starts every suite in the repository
root and adds the list, the pre-flight and the logs.

## Execution convention on this project

- **node / python tests run on Windows** (the build output is committed; `npm ci` was run there).
- **The `.sh` suites run under WSL** (Ubuntu-24.04, root), because they need `occ`, the snap
  installation and POSIX file modes — not from msys2/Git Bash.

`tools/release-check.sh` runs the `code/` checks when the local node can run them (the front-end proof
and the four smoke proofs), says so explicitly when it cannot, and lists the `live/` scripts that
still have to be run by hand — those all go through `tests/live/run-all.sh`.
