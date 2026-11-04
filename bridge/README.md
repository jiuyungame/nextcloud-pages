# pages-bridge

Host-side daemon for the Nextcloud **Pages** app. The app is the control plane; this daemon is a
**stateless executor**: the site lives in one Nextcloud folder with three fixed subdirectories —
`source/` (the Jekyll skeleton, administrator-edited), `online/` (published content) and `pending/`
(frozen review candidates) — and the host holds three **derived** trees assembled from them
(commit/preview/review), plus the git commit and push. Standard library only (Python 3.11+) — no
Docker, no virtualenv.

One bridge serves exactly one site (protocol 3): no site id appears in the routes, in the state file
(`state_dir/state.json`), in the working copy (`work_root` itself), in the pushed payload
(`{revision, global, site, secrets?}`) or in preview tokens.

## Three Nextcloud inputs, three derived host trees

```
<nc_data_root>/<nc_user>/files/<nc_path>/     ← the site folder
├── source/    the Jekyll skeleton            (required; only ever read by the bridge)
├── online/    the published content          (bridge-written: publish / take-down)
└── pending/   the frozen candidate snapshots (bridge-written: candidate / publish / discard)
```

| host tree | assembly (last wins) | what runs there |
|---|---|---|
| **commit** (`site.work`, default `work_root` itself, `/var/lib/pages/work`) | `source/` → the tree root, `online/` → the tree root (**merged flat**, online wins) | `git add` → commit → push. **Never built.** `.git` is kept by the assembly. |
| **preview** (`site.preview_dir`, default `<work>.preview`) | `source/` → `online/` → each author folder (merged flat) | build (`cwd = preview`, output inside it) → served by `/preview/...` |
| **review** (`site.onlinepreview_dir`, default `<work>.onlinepreview`) | `source/` → `online/` → `pending/` (merged flat) | build (`cwd = onlinepreview`, own output/log/state record) → `/preview/...?tree=onlinepreview` |

All three trees are **derived**: deleting them loses no site data, and **Apply source** (`mirror`)
rebuilds all of them from the Nextcloud folders. The only non-regenerable piece is the git history
inside `work/.git` (restored from the remote, or continued from the next commit).

**Every tree is flat: the tree root is the buildable Jekyll site** (`_config.yml`, `_layouts/`,
`authors-src/`…). The commit tree, the preview tree and the review tree are all assembled
`source/` → `online/` at the root (same-name wins), and the remote repository is exactly that flat
commit tree. A Pull archives the flat `HEAD` and leaves out the paths the instance knows as published
(`site.online_dir`), so online content never travels back into Nextcloud's `source/` folder: it is
excluded by path, not by a `source/` subtree.

The core invariants:

1. **A save never moves published content.** `add`/`update`/`remove` touch the preview tree only; a
   page enters `online/` only through `publish` (an approval).
2. **The review object is frozen.** `candidate` writes the source bytes into `pending/` and the
   review tree; a later save changes neither until the next candidate. `publish` moves
   `pending/` → `online/`, `discard` deletes the pending copy and restores the review file from
   `online/`.
3. **With no pending file, the review tree equals what the commit tree holds** — both are the flat
   `source/` → `online/` overlay, and `pending/` is the only extra layer.
4. **A take-down is the only deletion of published content**: it deletes `online/<target>` in
   Nextcloud plus the published bytes in `work/<target>` and `onlinepreview/<target>`. A source-side
   deletion is an explicit `remove` and leaves `online/` alone. When `source/` holds the same path the
   overlay only hid the skeleton file, so both trees get the source bytes back instead of losing the
   file (the skeleton exclusion applies, and a symlink is never content): the commit tree stays the
   `source/` + `online/` assembly, and the debounced review round is scheduled when the review tree
   really changed.
5. **The host state is self-healing.** No bridge-written target set exists; if `work` misses a file
   `online/` holds (a lost or partial tree), the bridge reassembles it instead of refusing to start.

- **`source_dir` / `online_dir` / `pending_dir`** are derived from `nc_path`
  (`<nc_data_root>/<nc_user>/files/<nc_path>/{source,online,pending}`), never configured. `source/`
  must exist (config error otherwise); `online/` and `pending/` are created empty on demand. They are
  reported by `GET /health`, `GET /site/status`, `GET /admin/config` and `Site.to_dict()`, and all
  three live inside `nc_data_root` and are pairwise disjoint by construction.
- **Writing into Nextcloud is plain file I/O** (the bridge runs as the file owner, root by default):
  atomic temporary-file + rename, no Nextcloud file API, no `occ`. `source/` is only ever read.
- **The preview route finds its build in the tree it names**: `site.output_dir` is
  `norm_abs(site.preview / recipe.output_dir)`, `site.onlinepreview_output_dir` is the same rule inside
  the review tree, and `/preview/{token}/{path}` resolves paths inside the selected output. A tree
  cannot see another tree's build.
- **Builds never touch the commit tree.** `/site/build` assembles the commit and preview trees and
  runs the generator with `cwd = preview_dir` and `--destination = preview_dir/output_dir`; the
  standalone `work` consistency repair is gone from this path. `/site/publish` does not build at all:
  it verifies the commit tree, then stages → commits → pushes.

## Two states: UNPAIRED and PAIRED

A bridge without secrets used to refuse to start, which left the administrator copying a secret out of
the TOML into Nextcloud by hand. That state is now an explicit one:

| state | meaning | behaviour |
|---|---|---|
| **UNPAIRED** | no usable secrets, neither in the TOML nor in the TOML's `<state_dir>/effective.json` | binds **loopback only** (a non-loopback `listen` in the TOML is a `ConfigError` and the service refuses to start); only `GET /health` and `POST /pair` answer — every other route is `503 {"error":"UNPAIRED"}`; `/health` reports `"paired": false, "pairing_required": true` |
| **PAIRED** | both secrets are present (TOML and/or `effective.json`) | the full API, no route carries an id; `/health` keeps its fields and adds `"paired": true` |

`install.sh` prints a **one-time pairing code** (8 characters from `ABCDEFGHIJKLMNOPQRSTUVWXYZ234567`,
valid **600 s**, dead after **3** wrong attempts, stored as `pairing.json` in the TOML's `state_dir`
— the *bootstrap* directory, see [effective.json](#effectivejson--the-configuration-the-app-owns) —
with mode `0600`). Enter it in *Nextcloud → Administration settings → Pages*: the app generates both
secrets, discovers `nc_data_root` from Nextcloud's own configuration and sends them in one
`POST /pair`. There is no half-paired state — the request carries everything.

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
`/var/lib/pages/{work,state,overrides,logs}` (`work` is the default data root of the commit tree),
copies `pages-bridge.toml.example` to
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
| `work_root` | root of the site's directories; with no `work_path` the commit tree itself | `/var/lib/pages/work` |
| `state_dir` / `override_dir` / `log_dir` | site state, generated overrides, build log | `/var/lib/pages/{state,overrides,logs}` |
| `exclude` / `unexclude` | names added to / removed from the exclusion set | `[]` / `[]` |
| `mirror_use_gitignore` | let the repository's `.gitignore` define what is not source | `true` |
| `mirror_verify` / `mirror_preserve_exec` | change detection (`size+mtime` or `sha256`) / keep the source exec bit on new files | `size+mtime` / `false` |
| `mirror_delete_guard_ratio` / `_min` | abort a mirror deleting more than `max(min, target_files * ratio)` | `0.2` / `20` |
| `build_timeout_s` / `build_debounce_ms` / `log_tail_lines` | build timeout / debounce after `/touch` / log tail kept | `180` / `700` / `200` |

`state_dir` is special among the pushable keys: a pushed `state_dir` moves the **business state**
(`state.json` and the build log), but the **bootstrap state** — `effective.json`, `pairing.json` and
`reset-unpaired.flag` — always stays in the TOML's `state_dir`, because the bridge reads those before
(or without) a pushed configuration. The two directories are reported once in the log when they
differ. There is no bridge-written target-set file and no `state-lost.json` any more.

A `[site]` table (`nc_user`, `nc_path` and `repo_url` mandatory; `work_path` defaults to `work_root`
itself, `preview_dir` to `<work_path or work_root>.preview`, `onlinepreview_dir` to
`<work_path or work_root>.onlinepreview`, `branch` to `main`, `remote_name` to `origin`) is required
**only** when the secrets are set in the file: that is the hand-written path. Leave secrets and
`[site]` out and the app pushes both while pairing. `source_dir`, `online_dir` and `pending_dir` are
derived from `nc_path` and are **not** configurable:
`<nc_data_root>/<nc_user>/files/<nc_path>/{source,online,pending}`. `source/` must already exist
(config error otherwise); the bridge creates `online/` and `pending/` empty. The three host trees are
all derived and may be deleted and rebuilt. The multi-site `[[sites]]` array of protocol 2 is still
read (first entry only) with a warning, so the daemon still starts after an app upgrade that has not
touched the TOML.

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

Everything the app pushes is stored in **`effective.json` in the TOML's `state_dir`** (the
*bootstrap* directory), mode **0600**:

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
the pushed file replaces the TOML's). The file is read from the TOML's `state_dir` only — a pushed
`state_dir` may not move the very file that carries the push. That split is the rule for every
**bootstrap** file: `effective.json`, `pairing.json` and `reset-unpaired.flag` stay in the TOML's
directory, while the **business** state (`state.json`, the build log) follows the pushed
`state_dir`. Without it, a reset
would write its marker into the pushed directory, the next start would not see it and would pair the
bridge from the TOML again; and a freshly printed pairing code would answer `CODE_EXPIRED`, because
`pair-code` writes next to the TOML while the daemon checked the pushed directory. An `effective.json`
that cannot be used — unreadable, wrong shape, unknown global key, or a value that does not validate —
is logged (`pushed site config is unusable, falling back to TOML`) and ignored, so a truncated file can
never keep the daemon from starting. A pushed file with usable secrets and a missing `[site]` table
starts the bridge anyway and waits for the app to push the site.

The pushed config of the previous app version (`sites.json` in the TOML's `state_dir`, site only, no
`global` block, no secrets) is still read when `effective.json` is absent, with the same warnings as
before, so an upgraded bridge keeps working with a config pushed by the older app.

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

## The assembly in detail

### The site folder in Nextcloud

A site is one folder under the site owner's files, with exactly three fixed subdirectories:

```
<nc_data_root>/<nc_user>/files/<nc_path>/
├── source/     ← the Jekyll skeleton, edited by the administrator  (required)
├── online/     ← the published content                              (bridge-written)
└── pending/    ← the frozen candidate snapshots                     (bridge-written)
```

- **`source/` is required.** A site whose `source/` is missing is refused at config time
  (`the site source directory does not exist`), because the assembly would otherwise rebuild three
  empty trees without saying anything. Nothing else in the folder is read as source.
- **`online/` and `pending/` are created empty when absent.** The bridge runs as the Nextcloud file
  owner (root by default) and writes them with ordinary file operations — **not** through the
  Nextcloud file API, no `occ`, no HTTP. `source/` itself is only ever read. A file written by
  `publish`/`take-down` is written atomically (temporary sibling + rename), so a reader or the
  Nextcloud scanner never sees half a page.
- The three paths are derived from `nc_path`, never configured: `source_dir`, `online_dir` and
  `pending_dir` appear in `GET /health`, `GET /site/status`, `GET /admin/config` and `Site.to_dict()`.
- An app that must not show host paths to non-admins hides the whole block (`source_dir`,
  `online_dir`, `pending_dir`, `work`, `preview`, `onlinepreview`) together.

### The host trees are all derived

Nothing on the host is the only copy of anything: every tree is a pure assembly of the Nextcloud
folders, and **Apply source** (the `mirror` op) deletes and rebuilds all three at any time.

| tree | assembly order (last layer wins) | what runs there |
|---|---|---|
| **commit** (`site.work`, default `work_root` itself, `/var/lib/pages/work`) | `source/` → the root, `online/` → the root (**merged flat**) | git working tree: `git add` → commit → push. **Never built.** `.git` is kept by the assembly. |
| **preview** (`site.preview_dir`, default `<work>.preview`) | `source/` → `online/` → **each author folder** (merged) | build (`cwd = preview`, destination inside it) → served by `/preview/...` |
| **review** (`site.onlinepreview_dir`, default `<work>.onlinepreview`) | `source/` → `online/` → `pending/` (merged) | build (`cwd = onlinepreview`, its own output/log/state record) → `/preview/...?tree=onlinepreview` |

- The **skeleton layer** is filtered like the old mirror: `.git`, `site.exclude`,
  `mirror_use_gitignore`'s results, the recipe's `output_dir` and `BUNDLE_PATH` are never copied. The
  ignore rules are read from **`source/` itself** (the skeleton is the repository content, and the
  ignored entries do not exist in `work` to be listed there), so an entry the repository ignores
  never enters a tree, not even on the round that first assembles it. The overlay layers (`online/`,
  `pending/`, an author folder) are copied verbatim — those bytes are data the bridge or the author
  wrote.
- An **author folder** is `<nc_data_root>/<uid>/files/<area>` and lands at
  `<author_root>/<uid>/…`; `area` is `Pages` by default (the app may name a deeper folder, e.g.
  `Pages/posts`). The author layer is last in the preview tree, so a live draft wins over both the
  skeleton and `online/`; the commit tree is only `source/` + `online/`, so a save never publishes
  itself.
- **`<author_root>/<orphan>/…` in `source/` is ordinary site content** (a deleted-but-published file
  handed over to a virtual name). It is copied as part of the skeleton into all three trees; the
  author layers only overlay the real authors' folders.
- The default roots keep the commit tree in the same persistent root as `state/override/log`, and the
  other two as siblings. All three may be deleted and rebuilt; only the `.git` repository inside
  `work` carries history that cannot be regenerated from Nextcloud (a remote or the next commit does).

The core invariants:

1. **A save never moves published content.** `add`/`update`/`remove` touch the preview tree only; a
   page enters `online/` only through `publish` (an approval).
2. **The review object is frozen.** `candidate` copies the source bytes into `pending/` and the
   review tree; a later save changes neither. `publish` moves `pending/` → `online/`, `discard`
   deletes the pending copy and restores the review file from `online/`.
3. **With no pending file, the review tree holds exactly what the commit tree holds** — both start
   from `source/` → `online/`, and `pending/` is the only difference.
4. **A take-down is the only source of deletion of published content**: it deletes `online/<target>`
   in Nextcloud and removes the published bytes from `work/<target>` / `onlinepreview/<target>`. When
   `source/` holds the same path, only the overlay goes: both trees get the source bytes instead, so
   the commit tree stays the `source/` + `online/` assembly. A source-side deletion is an explicit
   `remove` and leaves `online/` alone.

### Apply source (the `mirror` op)

The one whole-site operation, used after the administrator edits `source/`, after moving author
folders, or as the repair path:

1. take the site lock;
2. delete all three host trees (`.git` inside `work` is kept);
3. fill `work`, `preview` and `onlinepreview` from `source/` (at the root of each tree);
4. overlay `online/` onto **all three** at the root (same-name wins);
5. overlay each author folder onto `preview` only;
6. overlay `pending/` onto `onlinepreview` only;
7. build `preview` (and, when asked, `onlinepreview`);
8. answer:

```json
{
  "ok": true, "op": "mirror", "rebuilt": true, "dry_run": false,
  "files": {"work": 12, "preview": 15, "onlinepreview": 13},
  "build": {"ok": true, "build_id": 7, "tree": "preview", "...": "…"},
  "authors": ["alice:Pages", "bob:Pages/posts"]
}
```

Body: `{authors?: [{uid, area}], dry_run?: bool, build?: bool (default true),
build_onlinepreview?: bool}`.

**The `authors` list is the app's contract.** The bridge also reads `site.authors` (a hand-written
TOML or an older push) and, as a fallback, the uids already visible under
`online/<author_root>/` or `pending/<author_root>/`. A uid whose `<uid>/files/<area>` folder does not
exist contributes nothing. For the full preview — including a never-published draft — the app has to
name every author area; the fallback only knows the published ones. `dry_run: true` counts the layers
and writes nothing, so it stays usable while a build holds the lock.

### The steady state: `/site/author-delta`

| op | reads | writes | build trigger |
|---|---|---|---|
| `add` / `update` | the author source bytes | `preview/<target>` only | preview (debounced) |
| `remove` | — | deletes `preview/<target>` only | preview (debounced) |
| `candidate` | the author source bytes (frozen) | `pending/<target>` in Nextcloud **and** `onlinepreview/<target>` | review (debounced) |
| `publish` | `pending/<target>` | `pending/` → `online/` (Nextcloud), the same bytes into `work/<target>` and `onlinepreview/<target>`; deletes `pending/<target>` | none (no tree is built) |
| `discard` | `online/<target>` | deletes `pending/<target>`; restores `onlinepreview/<target>` from `online/` (or deletes it when never published) | review (debounced) |
| `take-down` | `source/<target>` (only to decide the fallback) | deletes `online/<target>` (Nextcloud) and the published bytes in `work/<target>` and `onlinepreview/<target>`; when `source/` holds the same path the published copy only hid it, so both trees get the source bytes back instead (the skeleton exclusion applies, a symlink is never content) | review (debounced) when the review tree changed |
| `mirror` | the three folders | **Apply source**: all three trees rebuilt | preview (unless `build:false`) |
| `overwrite` | the pushed manifest | rebuilds the preview tree's author area from `authors[].entries`, removes strays and `purge` roots. **No delete-count limit here**: it only rewrites the derived preview tree, whose author bytes live in Nextcloud and are rebuilt by the next overwrite, so the strays (the leftovers of deleted accounts) are removed whether there are one or a thousand. The delete guard (`max(mirror_delete_guard_min, ratio × files)`) covers only the mirror / Apply source trees, which become git content. `area` is a relative folder name (no `..`, no leading `/`); anything else is `400 BAD_REQUEST` | preview (debounced), success path only |

- **`publish` is idempotent.** The app writes its manifest after the bridge call, so a failed
  manifest write leaves the entry waiting and the reviewer retries. The first call already moved
  `pending/` to `online/`; the retry finds no pending file, only the published copy, and answers
  `200 {"idempotent": true}` without moving bytes. A target that is neither pending nor published is
  still `400 BAD_REQUEST` (`no candidate to publish`).
- **No target-set files exist any more.** The old `author_external.json` and
  `onlinepreview_candidates.json` are gone. The record of "published" is `online/` itself, the record
  of "pending" is `pending/`; the ops above are the only writers. `online/` and `pending/` survive a
  restart and are backed up with the Nextcloud data.
- `add`/`update`/`candidate` accept the optional `area` (default `Pages`) because an older layout may
  keep an author's files under `Pages/posts`. The target stays `<author_root>/<uid>/<rel>`, where
  `<rel>` is relative to that area.
- Every host-tree write goes through a temporary file in the same directory and is renamed into
  place, so an interrupted save leaves the previous bytes, never half a page. Every Nextcloud write
  uses the same atomic pattern. A process killed between the temporary file and the rename leaves
  `.<name>.tmp<pid>` behind: that name is **never** read as site content, and `init` / Apply source
  sweep such files out of `online/` and `pending/` (only regular files matching the pattern, nothing
  else — `source/` is left alone).

**One writer at a time.** An author delta and a real Apply source take the same site lock as
`POST /site/build`, `/site/publish`, `/site/pull`, `/site/init`, `/site/reset` and the debounce
scheduler; a request that cannot get it in time answers `409 CONFLICT`. A `dry_run` Apply source is
deliberately **lock-free**: it writes nothing, so it stays the damage assessment an operator needs.
The lock also serialises two `candidate`/`publish` requests that write the same `pending/` or
`online/` path. Every writer resolves the site **again inside the lock**: a `POST /site/reset` or a
configuration push that replaces it while a request waits is either the site the request then uses
(an author delta, Apply source) or a `409 CONFLICT` with `details.reason = "SITE_CHANGED"` (build,
publish, pull, init) — never a write into the old site's directories.

### Author content: where it lives, where it lands

- **`site.author_root`** is the one site directory every author's tree lands in: author A's files
  arrive at `<author_root>/<A>/…`. It is a single path segment (no `/`, no `..`, no leading `.`/`_`),
  defaults to `tmp`, and is changed through `POST /site/author-root`. A change carries the content
  with it: the `online/` and `pending/` targets under the old name are rewritten under the new one,
  and only then are the configuration and the three host trees moved over.
- **The author's own folder** is `<nc_data_root>/<uid>/files/<area>` (`area` from the delta, from
  `site.authors[].path`, or `Pages`). The site owner's `<nc_user>/files/<nc_path>/source/` is never one
  of those folders.
- **Targets are `<author_root>/<uid>/<rel>`.** That is the only author shape the config validation
  accepts, and the only one the review ops (`candidate`/`publish`/`discard`/`take-down`) and the
  assembly use. A target that is absolute, contains `..`, names another author or another root, or
  points at the site's own directories is refused with `400 BAD_REQUEST`.
- A **missing source file** is a `400` for `candidate` and `add`/`update` ("the source file does not
  exist"): the app should not send a save for a file it has not written. A `remove` never needs the
  source. A published page whose source vanished stays in `online/` until a take-down — the app reads
  its last bytes back with `GET /site/published-content`.
- **`site.authors`** in a pushed config (or `[[site.authors]]` in the TOML) is still accepted: it
  feeds Apply source and the status author table. The current app is event-driven and needs only the
  `authors` array on Apply source. A config-level mistake — relative `path`, target outside the
  allowed area, duplicate target, repeated `uid` — is refused at load time, and as
  `400 BAD_REQUEST` naming the field on a push.

### Git: init, publish, pull

- `POST /site/init` creates/adopts the repository (always `git init` + remote + fetch, **never a
  checkout**), then assembles `work` and `preview`. A remote that does not answer is reported as
  `remote_unreachable`, not raised.
- `POST /site/publish` **neither assembles nor builds**: it first verifies that the commit tree is
  exactly the flat merge of `source/` + `online/` and answers `409 WORK_DRIFT`, writing nothing, when
  it is not; then it stages (only `stage_paths` when given), commits and pushes the commit tree as it
  stands. The tree's content is maintained by the explicit operations - Apply source assembles the
  skeleton, an approval writes the published page - so a save or an emptied folder can never enter, or
  clear, a commit by accident. The answer carries
  `stage_paths` and `stage_errors` whenever paths were named; a partial commit is `ok: true` plus the
  error list. `stage_paths` are **site-relative** names (an author area, typically), which are exactly
  the flat commit tree's paths, so the answer echoes them unchanged.
- `POST /site/pull` fetches and lands `<remote>/<branch>` in `work` and packages the **source** for
  the app, which writes it into Nextcloud's `source/` folder. The archive is the flat `HEAD` minus the
  paths this instance knows as published (`site.online_dir`), so online content never becomes the
  administrator's skeleton; only known paths can be excluded, because the flat repository carries no
  layer marker. The archive answer is `{path, bytes, sha256, ref, source_only: true, excluded: N}`
  (`excluded` names how many online paths were left out). Pull replaces the folder completely; it is
  the only path that lands files from git.
- A **lost `work` tree** is not a fatal state: the next assembly recreates it from `source/` +
  `online/` (with a remote, refs/history come from the remote first; without one, `online/` is copied
  back by `ensure_work_repo`). The `.git` directory is the only thing that must survive a plain
  assembly.

`GET /site/status` reports the author table as before (`entries`, `missing`, `drift`, the last
round's counters), the three Nextcloud directories, the `pending` and `online` lists (read from
`pending/` and `online/`) with their `pending_at`/`online_at` mtime maps, and `work_drift` (see
below). A site with no `site.authors` has no `authors` key. `GET /site/paths` is the same two lists
without the rest of the report: the app reads it once per request to answer "is it live / is it
waiting" without going through the Nextcloud filecache, which a bridge-written file does not enter
on its own (`filesystem_check_changes=0`).

## Work consistency and failure handling (状态文件与故障处理)

There is no bridge-written load-bearing state file any more. The old approved-target and
candidate-target sets (`author_external.json`, `onlinepreview_candidates.json`), the `STATE_LOST`
503/exit-78 path and `state-lost.json` are gone: `online/` and `pending/` in Nextcloud are the
records.

`App.verify_state_files()` (run at startup by `serve()`, and by `init` through
`ensure_work_consistent()`) compares `online/` with the commit tree (`<work>/<target>`):

- **consistent** → `{"ok": true, "checked": true, "rebuilt": false}` and nothing happens;
- **a published file is missing from `work` or differs** → `work` is stale: the bridge **rebuilds**
  it from `source/` + `online/`, logs `the commit tree is out of date with online/, rebuilding it`,
  and answers `{"ok": true, "checked": true, "rebuilt": true, "files": N, "drift": {...}}`;
- **no site yet** → `{"ok": true, "checked": false}`.

Consequences:

- A lost or partially written commit tree is **self-healing**: the startup check, `init`, Apply
  source and a build's mirror round reassemble it. A **publish** never repairs it - a drifted tree
  answers `409 WORK_DRIFT` and nothing is written, so Apply source is the documented fix. There is no
  `78` exit and `RestartPreventExitStatus` is no longer set; systemd's ordinary restart policy is
  enough.
- The startup/`init` repair is one-directional: extra files in `work` are not a drift there (the
  skeleton may have been edited since the last assembly), only published content that is missing or
  differs matters. A **publish** is stricter: it compares the tree exactly and reports an extra file
  under `extra`, refusing the commit.
- A stale `online/` is the administrator's statement. If a published page must disappear, the app
  sends `take-down`; if `online/` itself is wrong, the app (or an operator) fixes it in Nextcloud and
  runs Apply source.
- **Reset** deletes the three host trees and the pushed config and unpairs the bridge; it
  deliberately leaves `source/`, `online/` and `pending/` in Nextcloud untouched (they are the app's,
  and the app's Reset Pages flow owns them).
- If a tree must be regenerated by hand: trigger Apply source (`POST /site/mirror`,
  `{"build": false}` for a fast rebuild), or simply restart the bridge — the startup check rebuilds
  `work` when `online/` is ahead of it.

## HTTP API

Every route except `/health`, `/pair` and `/preview/...` needs `Authorization: Bearer <shared_secret>`.
There is exactly one site, so no route carries a site id (protocol 3). While UNPAIRED, only `/health`
and `/pair` answer; everything else is `503 UNPAIRED`. There is no `STATE_LOST` refusal any more: a
stale or missing host tree is rebuilt by Apply source or `init` (a publish refuses it with
`409 WORK_DRIFT` instead), see *Work consistency and failure handling*.

| method | path | purpose |
|---|---|---|
| GET | `/health` | liveness; version, protocol, paired state, config source and revision, `source_dir`/`online_dir`/`pending_dir` (the three Nextcloud inputs) and `work_dir`/`preview_dir`/`onlinepreview_dir` (the three derived trees); no auth |
| POST | `/pair` | one-time pairing, open while UNPAIRED only: `{code, shared_secret, session_signing_secret, nc_data_root, revision?, global?, site?}` → `200 {ok, paired, protocol, version, config_source, revision, restart_required}`; `409 ALREADY_PAIRED` once paired, `401 BAD_CODE` with `attempts_left` on a wrong code, `410 CODE_EXPIRED` when the code is expired, used up or gone. The code is verified **before** the site configuration is assembled, so a wrong code never creates `online/` or `pending/` in the folder it names |
| GET | `/env` | capability report: uid/gid, tool versions, path checks, plus `effective`, `sources` and the secret fingerprints |
| GET | `/admin/config` | current site config (including `source_dir`/`online_dir`/`pending_dir`), config source and revision, plus `global` and `sources` |
| POST | `/admin/config` | the app pushes `{revision, global, site, secrets?}`; it replaces and persists the config (and rotates the secrets when `secrets` is present). A pushed site's `source/` must exist; `online/` and `pending/` are created as needed |
| DELETE | `/admin/config` | drop the pushed config (`effective.json` and the legacy `sites.json`); TOML applies again after a restart |
| POST | `/site/init` | bootstrap: `git init` plus remote-add and fetch (refs only, **never a checkout**), then assemble the flat commit tree (`source/` + `online/` at the root) and the preview tree (`source/` + `online/` + every author folder). A stale `work` is rebuilt first. A remote that does not answer is reported as `remote_unreachable`. When the assembly itself is refused (the delete guard, an unreadable source) the repository is still established, but the answer is `ok: false` with the mirror's `error`/`message` at the top level (its block stays under `mirror`) and the site is **not** marked bootstrapped |
| POST | `/site/author-root` | the one writer of the running author content directory: `{author_root, confirm?}`. Shape only; `409 CONFIRM_REQUIRED` when the directory already exists in the site folder and `confirm` is not `true`. A move **carries the content**: every `online/` and `pending/` target under the old prefix is rewritten under the new one (new bytes written, old copy removed), and only then are the configuration, the stored `site.authors` blocks (the app re-sends them under the new root), `onlinepreview` and the commit tree changed — a refused commit-tree rebuild is reported as `work_rebuild`, never fatal. All or nothing: when a file cannot be moved the files this call moved are put back, the configuration stays at the old root and the answer is `409 AUTHOR_ROOT_MOVE_FAILED` with `{moved, failed, remaining, rollback_failed}` — retrying the same request runs the move again. A successful move answers `{ok, changed, exists, author_root, moved: {online, pending}, work_rebuild}`. |
| POST | `/site/author-delta` | one author-content event, the one-shot overwrite, or Apply source. Ops: `add`/`update` (read the author source bytes → `preview/<target>`, **only** the preview: a save is not an approval), `remove` (delete `preview/<target>` only; `online/` stays), `candidate` (`{target, force?}` — read the source bytes → **write `pending/<target>`** in Nextcloud and the review tree), `publish` (approval: `pending/<target>` → `online/<target>`, delete the pending file, sync `work/<target>` and `onlinepreview/<target>`; **idempotent**: a retry whose bytes already landed answers `200` with `idempotent: true`), `discard` (delete `pending/<target>`; restore `onlinepreview/<target>` from `online/`, or delete it when never published), `take-down` (delete `online/<target>` in Nextcloud plus the published bytes in `work/<target>` and `onlinepreview/<target>`; when `source/` holds the same path the trees keep the source bytes instead, so the commit tree stays the `source/` + `online/` assembly), `mirror` (**Apply source**: rebuild all three trees; body `{authors?, dry_run?, build?, build_onlinepreview?}`), `overwrite` (the boot repair of the preview tree's author area: `authors[].entries` → preview tree, strays and `purge` roots removed; `online/` and `work` are never written; no delete-count guard here, because only the derived preview tree changes — the guard applies to the mirror / Apply source trees; a **failed** overwrite carries `details.retry_after_ms` (60000) in its error answer — the app retries the one-shot repair on every request, and that is the wait it should honour before trying again; the bridge keeps no failure state of its own). The retired `external` op is gone and answers `400 BAD_REQUEST` like any unknown op |
| POST | `/site/mirror` | **Apply source**: delete and rebuild the three trees from the Nextcloud folders; body `{authors?, dry_run?, build?, build_onlinepreview?}`; answers `{ok, op:"mirror", rebuilt, dry_run, files:{work,preview,onlinepreview}, build, authors?}`. A `dry_run` counts without writing and takes no lock. **Diagnostic/repair endpoint** — the app normally sends the `mirror` op on `/site/author-delta` |
| POST | `/site/touch` | mark the preview dirty; **build** it after the debounce window. Nothing is assembled: the delta already wrote the file, and the skeleton belongs to Apply source |
| POST | `/site/build` | assemble the commit and preview trees and build the **preview** tree now; body `{force, skip_mirror}`. The standalone `work` consistency repair (`ensure_work_consistent`) is gone from this path: a publish checks the tree and refuses a drift (`409 WORK_DRIFT`) instead of repairing it |
| GET | `/site/status` | git status, `drift`, last build, `initialized` (the publish repository exists — the app locks the remote and the branch on it), `source_dir`/`online_dir`/`pending_dir`, `pending`/`online` (the targets read off `pending/` and `online/`) with their `pending_at`/`online_at` mtime maps, `work_drift` (non-null means the commit tree is stale: Apply source or `init` rebuilds it, and a publish refuses it with `409 WORK_DRIFT`), and `authors` (one entry per configured author source) |
| GET | `/site/paths` | the two content directories alone, for the app's own reads: `{ok, online, pending, online_at, pending_at}` — sorted site-relative targets and their mtimes (whole seconds). Two directory walks, no git, no build, **no lock**; a missing folder is an empty layer. This is how the app answers "is it live / is it waiting": the bridge writes `online/` and `pending/` itself, so with the default `filesystem_check_changes=0` the Nextcloud filecache lags behind and a folder listing would miss the file that was just published |
| GET | `/site/diff` | `git diff` plus diffstat (of the commit tree) |
| GET | `/site/log?tail=N` | build log |
| GET | `/site/published-content?target=…` | the **published** bytes of one author target, read from `online/` (fallback: the flat commit tree's `<work>/<target>`): `200 {ok, target, bytes, content_base64}` (base64, so binary survives JSON), `400 BAD_REQUEST` unless `target` is `<author_root>/<uid>/<file>` (the uid may be the orphan owner), `404 {"error":"NOT_FOUND"}` when nothing is published there, `413 {"error":"TOO_LARGE"}` above 5 MB. Read-only: no mirror, no build, no commit. The app calls it when a take-down is refused — the source file is already gone, so `online/` holds the last copy, which the app writes into the orphan owner's site source |
| POST | `/site/pull` | fetch and land `<remote>/<branch>` in the flat publish tree (creating the repository first when it is missing: init + fetch, no clone), then archive the flat `HEAD` minus the paths this instance knows as published (the `online/` list) for the app, which writes it into Nextcloud's `source/` folder. The answer carries `archive: {path, bytes, sha256, ref, source_only: true, excluded: N}`; no layout name is reported (the repository is always flat) |
| POST | `/site/publish` | body `{message, stage_paths?}`; **checks first and writes nothing**: `work` must be exactly the flat merge of `source/` + `online/` (`online/` wins, a top-level `.git` is skipped), and a mismatch answers `409 WORK_DRIFT` with `expected_files`/`actual_files`, the `missing`/`different`/`extra` counts and a `targets` sample - nothing is committed and no file is changed; Apply source is the repair. When the tree is consistent: stage (only `stage_paths` when it is given), commit, push - **no assembly and no build**, the commit tree is submitted as it stands (Apply source assembles it, an approval writes the published page). `stage_paths` are site-relative and translated into the commit tree (`<target>` → `online/<target>`); the answer echoes the paths that were staged |
| POST | `/site/reset` | drop the site: **delete** the three host trees recursively (`work`, `onlinepreview`, `preview`), delete the pushed config and the pull archive, forget both secrets → `200 {ok, already_clean, removed, cleared, flag}`. `source/`, `online/` and `pending/` in Nextcloud are **not touched**. A missing tree is tolerated. Idempotent: on an UNPAIRED bridge it answers `already_clean` and changes nothing, and it needs no credential there (there is none left) |
| GET | `/site/session?ttl=&scope=` | issue a short-lived preview token |
| GET | `/preview/{token}/{path}` | the selected tree's build output (`?tree=onlinepreview` selects the review tree; the default is the preview tree); the token in the address is the credential (the bearer secret or `?t=<token>` also work). The resolved address decides what is served: inside the selected output is servable, outside is 403 |

### Secret rotation

Rotation is a normal push authenticated with the **old** secret:
`POST /admin/config {"revision": N, "global": {…}, "site": {…}, "secrets": {"shared_secret": "…",
"session_signing_secret": "…"}}`. The new pair is written only if the whole configuration validates;
a rejected push answers with its validation error and leaves the old secrets in place. A wrong old
secret still gives `401`.

### Reset

`POST /site/reset` is the bridge half of the app's **Reset Pages (danger zone)**. The three host trees
are **deleted** (recursively; a directory that is already gone is fine), together with the pushed
configuration (`effective.json`, the legacy `sites.json`), the pull archive and the stored build
report; the bridge becomes UNPAIRED. The three Nextcloud directories (`source/`, `online/`,
`pending/`) are deliberately **left alone**: they are the app's data and the app's Reset Pages flow
owns them. The TOML may still carry both secrets and a `[site]` table (the example `install.sh`
copies has neither, but a hand-written config can have both), so the endpoint also writes
`reset-unpaired.flag` in the TOML's `state_dir` (bootstrap state, like `effective.json`); while that
marker exists the TOML's secrets and site are ignored at startup, so the reset survives a restart.
Only a successful `POST /pair` clears the marker. The route answers `already_clean` on an unpaired
bridge (idempotent retry) and is not authenticated there, because no secret exists to authenticate
with.

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
  instead. To pair it again (for example because the app was reinstalled), remove the TOML
  `state_dir`'s `effective.json` and restart the service — the TOML then applies again.
- The service refuses to start with `an unpaired bridge may only listen on loopback`: pair first, or
  set both secrets in the TOML to run the hand-written path.
- The app cannot reach the bridge after changing `listen`: the pushed address only takes effect after
  a restart, and a pushed address that cannot be bound falls back to the TOML one at startup — check
  `journalctl -u pages-bridge` for `falling back to the address from the config file`.
- A tree looks stale or a published page is missing from `work/`: `GET /site/status` reports it under `work_drift`; Apply source (or `init`, or a restart) rebuilds the tree, and a publish answers `409 WORK_DRIFT` until it has. To force it: `POST /site/mirror {"dry_run": true}` shows the layer counts, the same call without `dry_run` rebuilds.
- `GET /site/status` reports `work_drift` after restoring a backup: run Apply source (`POST /site/mirror`), or restart the service — the startup check rebuilds `work` from `source/` + `online/`.
- A config push fails with `the site source directory does not exist`: create `source/` in the site folder (`<nc_data_root>/<nc_user>/files/<nc_path>/source`). The bridge creates `online/` and `pending/` itself but never `source/`.
- `candidate`/`add` answer `the source file does not exist`: the app sent a save for a file that is not under `<nc_data_root>/<uid>/files/<area>` yet. The published copy in `online/` is untouched; only a new candidate or a take-down moves it.
- `git push` fails with `Permission denied (publickey)`: ssh only tries the default key file names, so point `IdentityFile` at your key in `~/.ssh/config`, use that Host alias in `repo_url`, and give the deploy key write access.
- `/health` returns 404 with a foreign `Alt-Svc` header: an `http_proxy` is hijacking loopback requests — use `curl --noproxy '*'`, and add `127.0.0.1,localhost` to `no_proxy`.
- `/env` reports the source as not readable: the service user cannot read the Nextcloud data directory; change `User=` / `Group=` in `pages-bridge.service`.
- Builds report `timed_out: true`: raise `build_timeout_s`; on expiry the whole process group is killed.
