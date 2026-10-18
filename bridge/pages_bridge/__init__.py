"""Host-side executor for the Nextcloud Pages app."""

from __future__ import annotations

__version__ = "1.3.0"

#: Protocol version shared with the Nextcloud app; a mismatch must fail loudly.
#: 3 = single site: no site id in routes, state file, work copy, pushed payload or tokens.
#: 1.2.0 adds the hosted configuration: the app pairs once (POST /pair) and then owns every
#: parameter through POST /admin/config, which the bridge stores in state_dir/effective.json.
#: 1.3.0 splits the single work copy into two directories: the commit tree (the on-site set, the
#: git working tree a publish commits from) and the preview tree (every author file, built and
#: served as the preview). New site field: preview_dir; new optional author list: on_site.
PROTOCOL = 3

__all__ = ["__version__", "PROTOCOL"]
