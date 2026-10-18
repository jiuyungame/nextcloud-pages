# scripts/

Developer tooling, grouped by purpose. Neither `scripts/` nor `tests/` ships in the app
package (both are excluded by `package-app.sh`): they are tied to this machine (WSL, snap,
the `/tmp/pages` work copy).

## `release/`

| Script | What it does | When to run |
|---|---|---|
| `release-check.sh` | One-command pre-release check: frontend artifact freshness → dependency lock → bridge tests → packaging | Before every release |
| `package-app.sh` | Builds `dist/pages-<version>.tar.gz` (top level `pages/`, includes `bridge/`); gates: artifacts present, PHP syntax, info.xml shape, no personal environment traces | Called by `release-check.sh`, also standalone (`--build` builds first) |
| `check-lockfile.py` | Checks that `package.json` and `package-lock.json` are in sync — the only failure class `npm ci` catches, and it needs no node | Called by `release-check.sh` |

## `deploy/`

| Script | What it does | When to run |
|---|---|---|
| `deploy-app.sh` | Sync → version bump (only when frontend artifacts changed) → snap restart → readiness wait → live version check → 21 route probes → `occ pages:doctor` | After every code change |

## `../tests/` — acceptance and regression (needs a live Nextcloud)

| Script | What it does | When to run |
|---|---|---|
| `verify-deploy.sh` | Authenticated acceptance: routes, frontend assets, full preview proxy chain, CSP nonce, injected script, logs | After deploying |
| `verify-admin.sh` | Admin settings: page 200, form controls, config read/write round-trip, empty secret does not clear the config, site config round-trip, init/pull endpoints | After changing the admin page or its endpoints |
| `test-site-config.sh` | Site config → push → first git pull (a real clone) → build → status; clones into `/tmp/pages` | After changing site config or init logic |
| `test-newfile.sh` | File and directory create/rename/delete: duplicate rejected, mirroring, rename and delete propagation, no empty shell directories left behind | After changing FileService or tree operations |
| `test-auth.sh` | Issues/revokes a one-shot app password (the four authenticated scripts call it automatically) | Rarely manual; `revoke` cleans up |

## Release flow

```bash
bash scripts/deploy/deploy-app.sh            # 1. deploy and smoke test
sudo bash tests/verify-deploy.sh             # 2. authenticated acceptance
sudo bash tests/verify-admin.sh              # 3. admin settings
sudo bash tests/test-site-config.sh          # 4. site config and first pull
bash scripts/release/release-check.sh        # 5. pre-release check and package
```

Step 5 cannot run steps 1-4 (it has no Nextcloud), so it lists them as still to run.
