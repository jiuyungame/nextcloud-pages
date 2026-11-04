# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-11-04

### Added

- **A `Manage` view for reviewers:** every page the site serves, whoever wrote it, with **Take off the
  site** on each row; a pending take-off request is still decided in the request panel.
- **Import:** a zip or tar.gz replaces `source/` after validation, a backup and size limits.
- **Pull confirms before it replaces:** it names how many commits the remote is ahead by and how many
  local files will be backed up; the pulled HEAD is written into `source/` only, never as online content.
- **Reset Pages:** a password-confirmed return to a fresh install after a backup; the manifest,
  article cache, settings, grants and pairing are cleared, and the bridge deletes its trees.
- **Content locks:** the site storage user and folder lock once content exists, the git remote and
  branch once the repository is initialised; only Reset unlocks them.

### Changed

- **One site, four views:** Edit and Read for every signed-in user, Requests and Manage for reviewers;
  the site's own source is no longer edited in the app (the Files app, Import and Pull write it).
- **Decisions act on frozen bytes:** a request to publish freezes the file in `pending/`; approval
  moves exactly those bytes to `online/`, and rejection discards it without changing what the site serves.
- **The site folder carries three directories:** the administrator's `source/`, the served `online/`
  and the frozen `pending/`; the bridge writes the last two, the app only ensures `source/`.
- **Three derived host trees:** `work` is `source/` + `online/` (the flat git commit tree), `preview`
  is `source/` + `online/` + author files, and `onlinepreview` is `source/` + `online/` + `pending/`.
- **Manifest v4** records only `owner`, `candidateDigest`, `downRequest`, `decidedBy` and `note`;
  whether a page is live or waiting is read from `online/` and `pending/`.
- **Publish only verifies and commits:** `work` must equal the `source/` + `online/` merge, or it
  answers `409 WORK_DRIFT` and writes nothing; Apply source or init rebuilds the tree.
- **Moving the author content directory carries it:** every `online/` and `pending/` target moves
  before the setting takes effect, and a failure rolls back with `409 AUTHOR_ROOT_MOVE_FAILED`.
- **Deleting or renaming a served page records a take-off request** for its old address; the served
  copy stays until a reviewer decides, and the next publish removes it.
- **The badge and the list are simpler:** `external` reads **Online** in every list (no more
  pending-publish/live/push-failed split), and the article table dropped its "On the site" column.
- **One version number:** app and bundled bridge are both 0.2.0 (the settings header shows the bridge).

### Fixed

- A take-down whose path also exists in `source/` removes only the `online/` overlay, so the skeleton
  bytes return to `work` and `onlinepreview` and the next publish is not refused with `WORK_DRIFT`.
- **Refusing a take-off no longer takes the page down:** a page whose source still exists stays
  online, and one whose source was deleted is transferred to the orphan owner directory.
- A reviewer can approve or reject entries they own; `decidedBy` records who answered.
- Approving a candidate whose source file is gone answers `409 SOURCE_GONE` and leaves it pending
  (it can only be rejected), instead of publishing bytes nobody can maintain.
- When the bridge cannot confirm what is live or waiting, an action answers `409 BRIDGE_UNKNOWN`
  instead of acting on a stale listing; `SITE_CHANGED` means the site changed while waiting.
- An Import or Pull that fails while writing the site folder restores the backup and answers
  `409 SITE_REPLACEMENT_FAILED` (`backed_up_to`, `rollback`); a lock answers `409 SITE_LOCKED`.

## [0.1.0] - 2026-10-18

First public release: edit Jekyll pages inside Nextcloud, decide page by page what the world may
see, and publish the approved set to an ordinary git repository.

### Added

- **A three-state visibility model.** A page is `internal` (the site does not have it), `requested`
  (waiting for review, in either direction) or `external` (approved for the site), and the review
  decides *only whether a page goes live* — never what it says. The state lives in a manifest, not in
  the page, so the same Markdown builds the same way whether it is internal or live. A pending request
  never changes what the site serves: an approved take-offline request is still on the site until it is
  decided.
- **A preview tree and a commit tree.** The bridge mirrors the site into two directories: the preview
  tree carries **every** file and is the only thing that is ever built, the commit tree carries the
  site's own files plus the **external** pages only and is never built. The preview therefore shows
  even internal pages, and a commit can never contain one.
- **A request/decision trail per page**: when the request was filed (`submittedAt`), who decided it
  (`decidedBy`) and when the page went live (`publishedAt`), all read from the manifest and shown next
  to the page.
- **Retryable bridge failures.** A push that cannot reach the bridge is reported separately from a
  push the bridge refused, and a file whose push failed keeps a per-entry **“not pushed · retry”**
  mark in the file tree and in *My articles*: clicking it repeats the same push and clears it.
- `occ pages:config` to read, set and list the 16 global bridge parameters (with their effective value
  and origin) from Nextcloud, and `occ pages:doctor --json` to carry the same information
  machine-readably.
- `occ pages:manifest` to inspect and back up the visibility manifest.
- `GET /api/bridge/ping`: an unauthenticated, side-effect-free liveness probe for monitors.
- Preview pages are served through the app behind short-lived tokens; the resolved address decides
  what is served, so nothing outside the preview build output — a symlink included — can leak the
  source.

### Changed

- **Publishing is part of the review grant.** Two capabilities remain (`author_groups` and
  `review_groups`); the retired `publish_groups` and `edit_groups` grants nothing. A reviewer may
  decide about a page they own — on an instance whose only reviewer is the author, refusing that
  would make every request undecidable — and the manifest records `decidedBy` for every decision.
- **The settings page was slimmed to the 9 controls an administrator has to decide for this
  instance** — bridge URL, pairing, site storage user, folder, git remote, branch, author content
  directory and the two group grants. The global bridge parameters moved to `occ pages:config`; the
  build recipe and the site-level excludes stayed with the bridge's own TOML.
- **`Pages/…` paths everywhere.** An author's folder is `Pages` in their own files, and every path the
  app shows, stores and sends to the bridge is expressed in that layout (the site receives the files
  under `<author_root>/<uid>/…`). The pre-0.3 layout (`_posts/<uid>/…`) is not read.
- The visibility manifest moved into the app's own private appdata
  (`appdata_<instanceid>/pages/review.json`), where neither the author whose work it records nor the
  site storage user can edit it. It is written through one locked read-modify-write guarded by the
  file's ETag, and every decision about a state is read from it — the database tables are a query
  cache.
- A publish no longer builds: it mirrors, stages, commits and pushes. The build is paid for by
  editing and previewing, where it belongs.

### Fixed

- A page taken off the site (rejected, or deleted directly) leaves the commit tree at the next mirror
  and stays previewable; a page that was only turned internal is never committed again.
- Deleting a published page stages its removal with the next publish and drops it from the manifest's
  published list, instead of leaving the file on the site forever.
- An author's file that disappeared from Nextcloud is reported as `missing` and is never deleted from
  the site; a vanished author folder withholds deletions entirely.
- A mirror that would delete more files than the delete guard allows aborts the round with a reason,
  instead of silently emptying a working copy.
- `listen` changes only take effect at the next start, a pushed address that cannot be bound falls back
  to the TOML address, and an unpaired bridge listens on loopback only — a wrong push cannot lock an
  administrator out of the settings page that fixes it.

[Unreleased]: https://github.com/jiuyungame/nextcloud-pages/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/jiuyungame/nextcloud-pages/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/jiuyungame/nextcloud-pages/releases/tag/v0.1.0
