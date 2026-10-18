# pages-bridge

Host-side daemon for the Nextcloud **Pages** app. The app is the control plane; this daemon mirrors a
site's source directory out of the Nextcloud data directory into **two** throwaway directories — the
**commit tree** (what a publish commits) and the **preview tree** (every file, built and served as the
preview) — and does the git commit and push. Standard library only (Python 3.11+) — no Docker, no
virtualenv.

One bridge serves exactly one site (protocol 3): no site id appears in the routes, in the state file
(`state_dir/state.json`), in the working copy (`work_root` itself), in the pushed payload
(`{revision, global, site, secrets?}`) or in preview tokens.

## Two directories: preview and commit

| directory | holds | what runs there |
|---|---|---|
| **commit tree** (`site.work`, default `work_root` itself) | the site's own files plus **only the author files that are on the site** (`on_site`, i.e. `ReviewStore::onSite()`) | mirror → `git add` → commit → push. **Never built.** |
| **preview tree** (`site.preview_dir`, default a sibling: `<work>.preview`) | the site's own files plus **every** author file, internal and external alike | mirror → build (`output_dir` inside this tree) → served by `/preview/...` |

The split is what makes both statements true at once: "the preview shows every file" (the preview tree
has them all, so the build does too) and "the public site has only what was approved" (the commit tree
never receives an internal author file, so a commit cannot contain one).

- **`site.preview_dir`** is an absolute path, pushed by the app like the other site fields (it derives
  it from the work root it pushes: `<work_root>.preview`). It is validated: absolute, and neither it nor
  the work copy may contain the other (`site.preview_dir must not be inside the work copy`), it may not
  contain or sit inside the site source, and it may not live inside `nc_data_root`. `GET /health`
  reports it as `preview_dir` (with `work_dir`), `GET /admin/config` and `/env` report both trees.
- **The preview route finds its build in the preview tree**: `site.output_dir` is
  `norm_abs(site.preview / recipe.output_dir)`, and `/preview/{token}/{path}` resolves paths inside
  that directory. It cannot see the commit tree and the commit tree cannot see it.
- **Builds never touch the commit tree.** `/site/build` mirrors both trees and runs the generator with
  `cwd = preview_dir` and `--destination = preview_dir/output_dir`. `/site/publish` does not build at
  all: mirror → stage → commit → push. Publishing no longer pays for a build.
- **Deletes**: a file the app names in `removed` (it left the author area) disappears from **both**
  trees at the next mirror. A file that is only turned internal (still in the author area, no longer
  approved) disappears from the **commit** tree at the next mirror and stays in the **preview** tree:
  an internal page must stay previewable.
- **What the split costs**: two mirrors per round instead of one (a copy pass over the site source and
  the authors, each with its own delete guard and counters) and one build instead of one — the same one,
  now in the preview tree. A publish got *faster*: it does no build any more, and the preview build is
  only paid for by build/touch/init. `_site` is still excluded from both mirrors (harmless, and the
  repository's `.gitignore` usually lists it anyway), but nothing ever creates it in the commit tree.

## Two states: UNPAIRED and PAIRED

A bridge without secrets used to refuse to start, which left the administrator copying a secret out of
the TOML into Nextcloud by hand. That state is now an explicit one:

| state | meaning | behaviour |
|---|---|---|
| **UNPAIRED** | no usable secrets, neither in the TOML nor in `<state_dir>/effective.json` | binds **loopback only** (a non-loopback `listen` in the TOML is a `ConfigError` and the service refuses to start); only `GET /health` and `POST /pair` answer — every other route is `503 {"error":"UNPAIRED"}`; `/health` reports `"paired": false, "pairing_required": true` |
| **PAIRED** | both secrets are present (TOML and/or `effective.json`) | the full API, no route carries an id; `/health` keeps its fields and adds `"paired": true` |

`install.sh` prints a **one-time pairing code** (8 characters from `ABCDEFGHIJKLMNOPQRSTUVWXYZ234567`,
valid **600 s**, dead after **3** wrong attempts, stored as `<state_dir>/pairing.json` with mode
`0600`). Enter it in *Nextcloud → Administration settings → Pages*: the app generates both secrets,
discovers `nc_data_root` from Nextcloud's own configuration and sends them in one `POST /pair`. There
is no half-paired state — the request carries everything.

**Hosted pairing is same-machine only.** An UNPAIRED bridge listens on `127.0.0.1`, so a Nextcloud on
another host cannot reach it. Across hosts, keep the hand-written path: put both secrets *and* the
`[site]` table into `/etc/pages-bridge/pages-bridge.toml`. That path behaves exactly as before, and a
bridge configured that way never enters the UNPAIRED state.

## Install

```bash
sudo bash install.sh              # install/upgrade and (re)start the service
sudo bash install.sh --uninstall  # stop and remove (config and /var/lib/pages are kept)
```

`install.sh` checks for Python >= 3.11, copies `pages_bridge/` into `/opt/pages-bridge`, creates
`/var/lib/pages/{state,overrides,logs}`, copies `pages-bridge.toml.example` to
`/etc/pages-bridge/pages-bridge.toml` **without generating any secret** (never overwriting an existing
config), validates it, installs and restarts `pages-bridge.service`, polls `/health` and then prints
the pairing code with the bridge URL. Re-running only updates the code; it never touches an existing
config and issues a fresh code.

## Configuration

`/etc/pages-bridge/pages-bridge.toml` (see `pages-bridge.toml.example`). Top-level keys:

| key | meaning | default |
|---|---|---|
| `listen` | `host:port` the HTTP API binds to | `127.0.0.1:8787` |
| `shared_secret` | bearer secret the Nextcloud app sends | unset = UNPAIRED, else >= 16 chars |
| `session_signing_secret` | signing key for short-lived preview tokens | unset = UNPAIRED, else >= 16 chars |
| `nc_data_root` | Nextcloud data directory | `/var/snap/nextcloud/common/nextcloud/data` |
| `work_root` | root of the site's directories; with no `work_path` the commit tree itself | `/tmp/pages` |
| `state_dir` / `override_dir` / `log_dir` | site state, generated overrides, build log | `/var/lib/pages/{state,overrides,logs}` |
| `exclude` / `unexclude` | names added to / removed from the exclusion set | `[]` / `[]` |
| `mirror_use_gitignore` | let the repository's `.gitignore` define what is not source | `true` |
| `mirror_verify` / `mirror_preserve_exec` | change detection (`size+mtime` or `sha256`) / keep the source exec bit on new files | `size+mtime` / `false` |
| `mirror_delete_guard_ratio` / `_min` | abort a mirror deleting more than `max(min, target_files * ratio)` | `0.2` / `20` |
| `build_timeout_s` / `build_debounce_ms` / `log_tail_lines` | build timeout / debounce after `/touch` / log tail kept | `180` / `700` / `200` |

A `[site]` table (`nc_user`, `nc_path` and `repo_url` mandatory; `work_path` defaults to `work_root`
itself, `preview_dir` to `<work_path or work_root>.preview`, `branch` to `main`, `remote_name` to
`origin`) is required **only** when the secrets are set in the file: that is the hand-written path.
Leave secrets and `[site]` out and the app pushes both while pairing. The multi-site `[[sites]]` array
of protocol 2 is still read (first entry only) with a warning, so the daemon still starts after an app
upgrade that has not touched the TOML.

### `[site.recipe]` — Jekyll is the only generator

The bridge builds Jekyll and nothing else. `preset` may be left out (absent and empty both mean
Jekyll) or say `jekyll`. A config pushed by an older app version may still carry `auto`, the old
probing mode: it is accepted as Jekyll too — the bridge logs that once — because refusing it would
lock an upgraded install out. Any other value is refused with `CONFIG_ERROR` naming `jekyll`: another
generator is not a preset here, it is a `command`. Nothing is probed, so no marker file
(`hugo.toml`, `package.json`, …) selects anything.

The escape hatches are unchanged: `command` (the build command, default `bundle exec jekyll build`;
an empty list means the site has no build step and the preview tree itself is the artifact),
`install_cmd` (the dependency install, default `bundle install`), `output_dir` (relative to the
**preview tree**, default `_site`), `env` (variables for both steps) and `overrides` (written as YAML
under `override_dir` and passed as the second entry of Jekyll's `--config` chain, after the preset's
own `base_config`, `_config.yml`).

### effective.json — the configuration the app owns

Everything the app pushes is stored in **`<state_dir>/effective.json`**, mode **0600**:

```json
{
  "revision": 12,
  "global": { "build_timeout_s": 240, "mirror_verify": "sha256" },
  "site": { "nc_user": "alice", "nc_path": "/Sites/my-site", "repo_url": "git@…", "recipe": { … } },
  "shared_secret": "…",
  "session_signing_secret": "…"
}
```

**The secrets live in this file — watch permissions and backups.** It is written atomically, it is
never world-readable, and it is the same file the app may overwrite with the next push; the TOML is
never rewritten, so the comments and the hand-written path survive.

Startup order is fixed: **the TOML first, then `effective.json` overrides it** (key by key; a site in
the pushed file replaces the TOML's). `state_dir` itself is read from the TOML only — a pushed
`state_dir` may not move the very file that carries the push. An `effective.json` that cannot be used —
unreadable, wrong shape, unknown global key, or a value that does not validate — is logged
(`pushed site config is unusable, falling back to TOML`) and ignored, so a truncated file can never
keep the daemon from starting. A pushed file with usable secrets and a missing `[site]` table starts
the bridge anyway and waits for the app to push the site.

The pushed config of the previous app version (`<state_dir>/sites.json`, site only, no `global` block,
no secrets) is still read when `effective.json` is absent, with the same warnings as before, so an
upgraded bridge keeps working with a config pushed by the older app.

### The `global` keys the app may push

Exactly the top-level keys this bridge owns, with the same names and the same defaults as the TOML:
`listen`, `nc_data_root`, `work_root`, `state_dir`, `override_dir`, `log_dir`, `build_timeout_s`,
`build_debounce_ms`, `log_tail_lines`, `mirror_verify`, `mirror_delete_guard_ratio`,
`mirror_delete_guard_min`, `mirror_preserve_exec`, `mirror_use_gitignore`, `exclude`, `unexclude`.
An unknown key is refused with `400 {"error":"BAD_REQUEST"}` naming it. A push replaces the app-owned
set as a whole: an omitted key falls back to the TOML value, or to the built-in default.

`listen` is the one key with a threefold guard, because it is also the way to make the bridge
unreachable:

1. it only takes effect at the **next start** — the response carries `"restart_required": true` when
   the pushed address differs from the one this process is bound to;
2. at startup, a pushed `listen` that **cannot be bound** falls back to the TOML address with a
   warning and keeps serving, so a wrong push can always be corrected from the app;
3. while UNPAIRED the only allowed address is loopback (see the state table).

`GET /env` reports the effective value of every one of those keys, **per key** where it came from
(`sources: "app" | "toml" | "default"` — this is what the admin form labels), and the two secret
fingerprints (`secrets.shared_secret.sha256_16`, first 16 hex characters of the SHA-256; never the
plaintext, never the full digest).

## One site, several sources (authors)

Every author keeps writing in **their own** Nextcloud folder, and the bridge mirrors all of those
folders into the site's two directories. The app pushes the author list inside the `site` object it
already owns:

```json
"site": {
  "nc_user": "sites", "nc_path": "/Sites/blog", "author_root": "tmp",
  "preview_dir": "/var/lib/pages/work.preview", "...": "unchanged",
  "authors": [
    {
      "uid": "alice",
      "path": "/Pages",
      "entries": [
        {"path": "2026-05-01-hello.md", "target": "tmp/alice/2026-05-01-hello.md"},
        {"path": "2026-05-01-draft.md", "target": "tmp/alice/2026-05-01-draft.md"},
        {"path": "images/pic.png", "target": "tmp/alice/images/pic.png"},
        {"path": "theme.css", "target": "assets/authors/alice.css"}
      ],
      "on_site": [
        {"path": "2026-05-01-hello.md", "target": "tmp/alice/2026-05-01-hello.md"}
      ],
      "removed": ["tmp/alice/old/gone.png"]
    }
  ]
}
```

- **`site.author_root`** is the one site directory every author's tree is mirrored into: author A's
  files arrive at `<author_root>/<A>/…`. It is a single path segment starting with a letter or a
  digit (no `/`, no `..`, no leading `.` or `_`), defaults to `tmp` when absent, and the validation
  and the skeleton mirror's exclusion list are both derived from it (one helper, so they cannot
  disagree). An older app that does not send it keeps working.
- **`entries[].path`** is relative to that author's own folder:
  `<nc_data_root>/<uid>/files/<path>/<entries[].path>` — a second source, not a subdirectory of the
  site source. **`entries[].target`** is site-relative.
- **`entries` is the preview list and `on_site` the commit list.** `entries` holds every file of the
  author's area (an internal page included); the preview tree mirrors it, so the build can show it.
  `on_site` is the approved subset (`ReviewStore::onSite()`), with the same entry shape; the commit
  tree mirrors exactly that. An **absent** `on_site` is the pre-split client and means "the same as
  `entries`" (the old single-tree behaviour); an **empty** `on_site` means "nothing of this author is
  on the site" and empties the commit tree of that author's files.
- **Allowed targets, nothing else**: `<author_root>/<uid>/…` (that author's whole tree, any depth
  and any file type — images included), and the legacy `_posts/<uid>/…` and the author's own
  stylesheet, `assets/authors/<uid>.css` (their `theme.css`) or `assets/authors/<uid>/<slug>.css`
  (one post's sibling stylesheet). A payload that names `index.html`, `_config.yml`, a directory
  outside those three areas, another author's tree or another author's stylesheet is refused as a
  whole — in both lists.
- **`removed` holds targets** (already site-relative), never source paths: the bridge never guesses
  which target belonged to which source file. The app computes that mapping because it is the only
  side that knows it. It applies to both trees (the file left the author area).
- **Missing `authors`, or an empty list, is exactly the single-source bridge**: one source, the
  skeleton mirror, and the same responses as before. No `authors` block appears in the mirror,
  build, publish or status response then (`GET /admin/config` reports the site as it is in force,
  with `"authors": []`).

### The mirror is additive, and deletion is explicit

Both trees are assembled **skeleton first, then each author**:

1. the site's own source is mirrored into each tree, with `<author_root>/<uid>/…`,
   `_posts/<uid>/…` and `assets/authors/…` added to the exclusion set, so it neither copies nor
   deletes anything an author owns;
2. each author round copies exactly the files of its list for that tree — `on_site` into the commit
   tree, `entries` into the preview tree — creating parent directories, and updates a target whose
   source changed;
3. only the preview tree is then built. The commit tree is only ever staged and committed.

**An author's file that disappeared from Nextcloud is never deleted.** It is reported as `missing`
(and the author's `drift.in_sync` is false), because a missing source is a statement about
Nextcloud, not about the site. The only targets ever deleted are the ones the app named in
`removed` — plus, in the commit tree only, a target that is still in `entries` but no longer in
`on_site` (a page turned internal: the app-named diff of the two lists, never a filesystem guess).
All of it goes through the same delete guard
(`max(mirror_delete_guard_min, author_files × mirror_delete_guard_ratio)`, counted over that
author's own files in the tree being mirrored). A guard that trips aborts that author's round:
nothing is copied and nothing is deleted, and `/site/mirror` answers `409` with the reason. A target
named in both an entry list and `removed` in the same payload is copied, not deleted: the app's
list of files is the more recent statement about it, and copying over deleting loses nothing.

**A vanished author folder never wipes published posts.** If `<nc_data_root>/<uid>/files/<path>`
does not exist at all — an unmounted data directory, a renamed user — every entry of that author is
reported `missing`, and the removals of that round are *withheld* (reported as `withheld`) even if
the app listed them. Deletion still happens with `{"force": true}` on `/site/mirror`, which is the
administrator saying "yes, really".

`POST /site/publish` **does not build**: it mirrors both trees, then stages **only the paths the app
names** in `stage_paths` (an author area, typically) in the commit tree, commits and pushes. Without
`stage_paths` the whole commit tree is staged, as before. `/site/init` mirrors the skeleton and every
author; the reverse direction (work copy → Nextcloud) stays with the site's own source only — an
author's files are never written back into their folder, and never into the site owner's.

`GET /site/status` gains one entry per author:

```json
"authors": [
  {
    "uid": "alice", "path": "/Pages/posts", "source": "/var/…/alice/files/Pages/posts",
    "entries": 3, "on_site": 2, "copied": 2, "updated": 0, "deleted": 1, "unchanged": 0,
    "bytes": 4096,
    "missing": ["_posts/alice/2026-04-02-draft.md"],
    "removed": ["_posts/alice/2026-04-01-old.md"],
    "drift": {"in_sync": false, "pending_copy": [], "pending_update": [], "pending_delete": [],
              "missing": ["_posts/alice/2026-04-02-draft.md"]}
  }
]
```

`entries` is the preview list (everything the area holds), `on_site` the commit list. The counters
(`copied`/`updated`/`deleted`/`bytes`) are those of the last **commit** round of that author (`bytes`
is what that round copied), `missing` is computed live from the commit list, `removed` is what the
last round really deleted, and `drift.in_sync` is false while work is pending or an entry has no
source file. A single-source site has no `authors` key at all.

In the TOML the same list is written as `[[site.authors]]` (see `pages-bridge.toml.example`). A
config-level mistake — a relative `path`, a target outside the two allowed areas, a duplicate
target, a `uid` that repeats — is refused when the file is loaded, and as `400 BAD_REQUEST` naming
the offending field when it arrives in a push.

## HTTP API

Every route except `/health`, `/pair` and `/preview/...` needs `Authorization: Bearer <shared_secret>`.
There is exactly one site, so no route carries a site id (protocol 3). While UNPAIRED, only `/health`
and `/pair` answer; everything else is `503 UNPAIRED`.

| method | path | purpose |
|---|---|---|
| GET | `/health` | liveness; version, protocol, paired state, config source and revision, and `work_dir`/`preview_dir` (the two site directories; no auth) |
| POST | `/pair` | one-time pairing, open while UNPAIRED only: `{code, shared_secret, session_signing_secret, nc_data_root, revision?, global?, site?}` → `200 {ok, paired, protocol, version, config_source, revision, restart_required}`; `409 ALREADY_PAIRED` once paired, `401 BAD_CODE` with `attempts_left` on a wrong code, `410 CODE_EXPIRED` when the code is expired, used up or gone |
| GET | `/env` | capability report: uid/gid, tool versions, path checks, plus `effective`, `sources` and the secret fingerprints |
| GET | `/admin/config` | current site config, config source and revision, plus `global` and `sources` |
| POST | `/admin/config` | the app pushes `{revision, global, site, secrets?}`; it replaces and persists the config (and rotates the secrets when `secrets` is present) |
| DELETE | `/admin/config` | drop the pushed config (`effective.json` and the legacy `sites.json`); TOML applies again after a restart |
| POST | `/site/init` | bootstrap: clone or adopt the repository, then align both sides (the skeleton mirror and every author, in both trees) |
| POST | `/site/mirror` | run the mirror of **both** trees; body `{force, dry_run}`; the response carries the authors of the commit round at the top level and the preview round under `preview`, and `409` when a delete guard tripped |
| POST | `/site/touch` | mark dirty; mirror and build after the debounce window |
| POST | `/site/build` | mirror both trees and build the **preview** tree now; body `{force, skip_mirror}` (the author results are in `mirror.authors`, and at the top level) |
| GET | `/site/status` | git status, mirror drift, last build, and `authors` (one entry per author source) |
| GET | `/site/diff` | `git diff` plus diffstat (of the commit tree) |
| GET | `/site/log?tail=N` | build log |
| POST | `/site/pull` | fast-forward pull, then write the result back to Nextcloud (the site's own source only) |
| POST | `/site/publish` | body `{message, stage_paths?}`; mirror both trees (**no build**), stage (only `stage_paths` when it is given), commit, push |
| GET | `/site/session?ttl=&scope=` | issue a short-lived preview token |
| GET | `/preview/{token}/{path}` | the preview tree's build output; the token in the address is the credential (the bearer secret or `?t=<token>` also work). The resolved address decides what is served: inside the preview output is servable, outside is 403 |

### Secret rotation

Rotation is a normal push authenticated with the **old** secret:
`POST /admin/config {"revision": N, "global": {…}, "site": {…}, "secrets": {"shared_secret": "…",
"session_signing_secret": "…"}}`. The new pair is written only if the whole configuration validates;
a rejected push answers with its validation error and leaves the old secrets in place. A wrong old
secret still gives `401`.

## Command line

```bash
python3 -m pages_bridge -c /etc/pages-bridge/pages-bridge.toml check      # validate and exit
python3 -m pages_bridge -c /etc/pages-bridge/pages-bridge.toml listen     # effective host:port
python3 -m pages_bridge -c /etc/pages-bridge/pages-bridge.toml pair-code  # one-time pairing code
python3 -m pages_bridge -c /etc/pages-bridge/pages-bridge.toml env        # JSON environment report
```

## Service, logs and health checks

```bash
sudo systemctl restart pages-bridge          # apply code or config changes
journalctl -u pages-bridge -n 100 --no-pager # logs: one JSON object per line
curl -fsS --noproxy '*' http://127.0.0.1:8787/health
```

## Troubleshooting

- `/health` answers `"paired": false`: nothing is configured yet. Run
  `python3 -m pages_bridge -c … pair-code` and pair from the Pages settings; the code dies after
  600 s or 3 wrong attempts, so just issue a new one.
- `POST /pair` answers `409 ALREADY_PAIRED`: the bridge already holds secrets. Push the configuration
  instead. To pair it again (for example because the app was reinstalled), remove
  `<state_dir>/effective.json` and restart the service — the TOML then applies again.
- The service refuses to start with `an unpaired bridge may only listen on loopback`: pair first, or
  set both secrets in the TOML to run the hand-written path.
- The app cannot reach the bridge after changing `listen`: the pushed address only takes effect after
  a restart, and a pushed address that cannot be bound falls back to the TOML one at startup — check
  `journalctl -u pages-bridge` for `falling back to the address from the config file`.
- Mirror answers 409 `MIRROR_DELETE_GUARD`: repeat the call with `{"dry_run": true}` to see the plan, then `{"force": true}` if it is correct. With authors, the tripped guard is the one of `authors[i].guard_message` — check that author's `entries` and source folder first.
- An author's posts are reported `missing` and nothing was deleted: `<nc_data_root>/<uid>/files/<path>` does not hold that file (yet). The published post stays where it is by design; the round that also listed it in `removed` withheld the deletion (`withheld`) — `{"force": true}` on `/site/mirror` carries it out.
- `git push` fails with `Permission denied (publickey)`: ssh only tries the default key file names, so point `IdentityFile` at your key in `~/.ssh/config`, use that Host alias in `repo_url`, and give the deploy key write access.
- `/health` returns 404 with a foreign `Alt-Svc` header: an `http_proxy` is hijacking loopback requests — use `curl --noproxy '*'`, and add `127.0.0.1,localhost` to `no_proxy`.
- `/env` reports the source as not readable: the service user cannot read the Nextcloud data directory; change `User=` / `Group=` in `pages-bridge.service`.
- Builds report `timed_out: true`: raise `build_timeout_s`; on expiry the whole process group is killed.
