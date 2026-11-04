# tools/

Release tooling. Neither `tools/` nor `tests/` ships in the app package (`tools/package.sh`
builds the package from an explicit whitelist): they are tied to this machine.

| Script | What it does | When to run |
|---|---|---|
| `package.sh` | Builds `build/pages-<version>.tar.gz` (top level `pages/`, includes `bridge/`); gates: artifacts present, PHP syntax, info.xml shape, no personal environment traces | After `npm run build`; called by `release-check.sh`, also standalone |
| `release-check.sh` | One-command pre-release check: frontend artifact freshness → front-end code checks (when the local node can run them) → dependency lock → bridge tests → packaging | Before every release |
| `check-lockfile.py` | Checks that `package.json` and `package-lock.json` are in sync — the only failure class `npm ci` catches, and it needs no node | Called by `release-check.sh` |

## Release flow

```bash
npm run build
bash tools/release-check.sh
sudo bash tests/live/run-all.sh
```

`release-check.sh` runs everything that is reachable without a Nextcloud instance (build freshness,
the front-end proof and the four smoke proofs when the local node can load `node_modules`, dependency
lock, bridge tests, then packaging with the PHP / info.xml / personal-trace gates), and then lists the
acceptance suites it cannot run. Those live in `tests/live/`, need a real deployed instance, and
`run-all.sh` runs all of them in order (issuing the one-shot app password itself if needed). On a
failure, rerun the one suite it names — see `tests/README.md`.
