"""Host-side executor for the Nextcloud Pages app."""

from __future__ import annotations

__version__ = "0.2.0"

#: Protocol version shared with the Nextcloud app; a mismatch must fail loudly.
#: 3 = single site: no site id in routes, state file, work copy, pushed payload or tokens.
#: 1.2.0 adds the hosted configuration: the app pairs once (POST /pair) and then owns every
#: parameter through POST /admin/config, which the bridge stores in the TOML's state_dir/effective.json.
#: 1.3.0 splits the single work copy into two directories: the commit tree (the on-site set, the
#: git working tree a publish commits from) and the preview tree (every author file, built and
#: served as the preview). New site field: preview_dir; new optional author list: on_site.
#: 1.4.0 adds POST /site/reset, the bridge half of "reset Pages": the stored configuration is dropped
#: and the bridge becomes UNPAIRED, ready to pair again.
#: 1.4.1 heals pull against an unborn HEAD or a missing work copy, and the reset deletes both derived
#: trees outright instead of renaming them aside.
#: 1.4.2+ init never checks anything out: it creates the repository and fetches the refs, and the work
#: tree's files come exclusively from the mirror. Pull is the one path that lands files (fetch -> land
#: -> archive -> the app writes the Nextcloud folder). 1.4.4/1.4.5 give the author content directory
#: its own channel: shape-only validation, and an existing directory in the site needs an explicit
#: ``confirm: true`` (409 CONFIRM_REQUIRED) before it is used.
#: 1.5.0 is the architecture change: the site folder in Nextcloud now carries ``source/``, ``online/``
#: and ``pending/``, and the three host trees are derived assemblies of them. The bridge-written
#: target sets (``author_external.json``, ``onlinepreview_candidates.json``) and the ``STATE_LOST``
#: refusal / exit 78 are gone; a stale commit tree is rebuilt from ``source/`` + ``online/``.
#: 0.2.0 follows the app version and ships this data chain: the site folder's three directories, the
#: version-4 manifest, and a publish that only validates and commits.
PROTOCOL = 3

__all__ = ["__version__", "PROTOCOL"]
