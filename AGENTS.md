# AGENTS.md — Metamanager Server

## MANDATORY WORKFLOW — ZERO EXCEPTIONS

**You are FORBIDDEN from doing ANY of the following:**
- Checking out `test` or `main` locally
- Running `git checkout test`, `git checkout main`, or `git switch test/main`
- Running `git merge`, `git rebase`, `git push`, or `git reset` on `test` or `main`
- Running `git branch -d`, `git branch -D`, `git push --delete` on `test` or `main`
- Creating files, editing files, or running any `git` command while on `test` or `main`
- Running `gh pr merge` to resolve conflicts (close the PR instead)

**You MUST follow this exact process for ALL changes:**

1. **ALL work happens on `dev`**: `git checkout dev`, make changes, commit, push to dev
2. **Promote via workflow_dispatch only**: Trigger `promote-to-test.yml` — merges dev→test, builds .deb, deploys to apt
3. **Then promote test→main**: Trigger `promote-to-main.yml` — merges test→main, tags, releases, deploys to apt
4. **If a merge has conflicts**: CLOSE the workflow, do NOT try to resolve them by checking out test/main
5. **If you need to update test with main's changes**: Create a new empty commit on dev that triggers CI, or ask the user to resolve

**The ONLY branch you are allowed to checkout, edit, commit, or push is `dev`.**

## CI Flow

```
dev  ──  all development, direct push; CI runs checks + auto-version bump
    │  workflow_dispatch: promote-to-test.yml
    ▼
test  ──  build .deb + deploy to test apt repo (dists/test)
    │  workflow_dispatch: promote-to-main.yml
    ▼
main  ──  tag + GitHub release + deploy to production apt repo (dists/stable)
```

- On every dev push: CI first auto-bumps `debian/changelog` and `VERSION`, then runs ShellCheck on all shell scripts
- The actor check (`github.actor != 'github-actions[bot]'`) prevents infinite loops — version bump commits don't re-trigger CI
- Promotion workflows merge directly via git (no PRs), build, and deploy to apt repo
- Test channel: `dists/test` (used by test site, installed via `apt-get install -t test`)
- Production channel: `dists/stable` (used by production site, installed via `apt-get install -t stable` or `apt-get upgrade`)

## Deployment Rules

**NEVER SCP or copy files directly to production servers unless explicitly testing a fix.**

All software must move to production through native update systems:

- **Daemon test channel**: Push to `dev` → trigger `promote-to-test.yml` → merges dev→test, builds `.deb` → deploys to `dists/test` on apt server → install on test site via `apt-get install -t test`
- **Daemon production channel**: Push to `dev` → trigger `promote-to-main.yml` → merges test→main, tags, releases → deploys to `dists/stable` on apt server → install on production site via `apt-get upgrade` or `apt-get install -t stable`
- **Plugin test channel**: Push to `dev` → trigger `promote-to-test.yml` → merges dev→test, builds zip → deploys to `metamanager-test/` on apt server → WordPress detects update via `MM_Updater`
- **Plugin production channel**: Push to `dev` → trigger `promote-to-main.yml` → merges test→main, tags, releases → deploys to `metamanager/` on apt server → WordPress detects update via `MM_Updater`

The only exception is temporary testing during active development sessions, where files may be SCP'd for immediate verification. After testing, the fix must go through the proper pipeline before being considered deployed.

## How Daemon Updates Work

The daemon self-updater is the single authority for daemon version management. The plugin PHP does NOT trigger daemon updates — it only reads version info for display.

### Self-Updater

**`/usr/local/bin/metamanager-self-updater.sh`** — Bash script that:
1. Detects the WordPress installation path
2. Reads `daemon-compatibility.json` from the plugin directory (`wp-content/plugins/metamanager/`)
3. Extracts the installed plugin version from the plugin header (`metamanager.php`)
4. Looks up the required daemon version from the compatibility map
5. Compares to the installed `VERSION` file at `/usr/local/lib/metamanager/VERSION`
6. If version mismatch → runs `sudo apt-get update && apt-get install -y metamanager` + restarts daemons
7. Writes comprehensive status JSON to `/var/run/metamanager-status.json`

**Runs every 60 seconds** via systemd timer.

**Components:**
- **`/usr/local/bin/metamanager-self-updater.sh`** — Main script
- **`/etc/systemd/system/metamanager-self-updater.service`** — Systemd service unit
- **`/etc/systemd/system/metamanager-self-updater.timer`** — Systemd timer (every 60s)

**Status JSON** (`/var/run/metamanager-status.json`) — read by WordPress dashboard widget and REST API:
```json
{
  "ts": "2026-08-20T19:22:16Z",
  "updater": {
    "installed_version": "2.4.56",
    "required_version": "2.4.56",
    "last_check": "2026-08-20T19:22:16Z",
    "last_update": "",
    "status": "ok",
    "message": "Daemon v2.4.56 is current"
  },
  "daemons": {
    "compress": { "running": true, "pid": "3812755", "started": "..." },
    "meta": { "running": true, "pid": "3812830", "started": "..." }
  },
  "queues": { "compress": 0, "meta": 0, "completed": 0, "failed": 0 },
  "tools": { "exiftool": true, "jpegtran": true, "optipng": true, "cwebp": true, "ffmpeg": true, "avifenc": true },
  "config": { "wp_content_dir": "/srv/www/wordpress/wp-content" }
}
```

**Status values:** `ok` (current), `ahead` (installed > required), `updating` (in progress), `failed` (error), `waiting` (can't determine required version)

## VERSION File

The `VERSION` file is the installed daemon version. The shell self-updater reads it to compare against `daemon-compatibility.json` from the plugin directory.

**Format**: Plain semver string, e.g. `2.4.10` (no Debian revision suffix).

**Installation**: The `.deb` package installs it to `/usr/local/lib/metamanager/VERSION` via `debian/metamanager.install`. The `postinst` script sets permissions to `0644` so www-data can read it.

**Sync with debian/changelog**: The CI workflow (`ci.yml`) auto-bumps both `debian/changelog` and `VERSION` on every push to dev. They must stay in sync — the patch version in `debian/changelog` (e.g. `2.4.10-1`) must match the `VERSION` file (e.g. `2.4.10`). CI handles this automatically; never edit either file manually.

**Format difference**: `debian/changelog` uses Debian epoch format `2.4.10-1` (upstream-revision). The `VERSION` file uses plain semver `2.4.10` (no `-1` suffix). The CI strips the `-1` when writing `VERSION`.

## Cross-Repo Automation: daemon-compatibility.json

**Problem**: The daemon gets promoted to apt BEFORE the plugin is released. The self-updater reads `daemon-compatibility.json` from the installed plugin to know which daemon version is required. If the map is not updated first, the self-updater shows "ahead" (daemon newer than map expects). This was a recurring process flaw — the daemon and plugin CI were blind to each other.

**Solution**: The daemon promotion workflows (`promote-to-test.yml`, `promote-to-main.yml`) automatically update `daemon-compatibility.json` in the plugin repo before deploying the daemon to apt.

**How it works**:
1. Workflow reads the current plugin version from the plugin repo (`MM_VERSION` in `metamanager.php`)
2. Reads `daemon-compatibility.json` from the plugin repo
3. If no entry exists for the new daemon version → clones plugin repo, adds entry, commits and pushes
4. Final validation ensures the entry exists before proceeding to build/deploy

**What gets mapped**: The current plugin version on the target branch (dev for test promotion, main for main promotion) → the new daemon version. This is correct because:
- The self-updater checks the *installed* plugin version against the map
- The entry covers the version that was on dev when the daemon was promoted
- Plugin CI will auto-bump `MM_VERSION` on the next push — future versions will need their own entries (added by the next daemon promotion or manually)

**Branch targeting**:
- `promote-to-test.yml` → updates plugin repo's `dev` branch
- `promote-to-main.yml` → updates plugin repo's `main` branch

**Secrets required**: `PLUGIN_REPO_PAT` — GitHub Personal Access Token with `contents: write` scope on `richardkentgates/metamanager-plugin`. Without this secret, the workflow fails with a clear error message.

**Idempotency**: If the map already has an entry for the daemon version, the step is a no-op (just validates).

**Failure handling**: If the auto-update fails (bad PAT, network error, etc.), the final validation step catches it and blocks the promotion with a clear error message.

**Historical context**: Before this automation, the release checklist required manually updating `daemon-compatibility.json` in the plugin repo before promoting the daemon. This was a frequent source of the "ahead" status because the steps were容易 forgotten or done in the wrong order. The automation eliminates the manual coordination between repos.

## Repos

- Server repo: `richardkentgates/metamanager`
- Plugin repo: `richardkentgates/metamanager-plugin`
- Apt server: `34.136.87.92` (DNS: `apt.richardkentgates.com`)
- Production: `34.10.253.160` (Debian 13 trixie, WordPress at `/srv/www/wordpress/`)

## Apt Server Channels

- **Test channel (daemons)**: `dists/test` — install via `apt-get install -t test`
- **Production channel (daemons)**: `dists/stable` — install via `apt-get install -t stable` or `apt-get upgrade`
- **Test channel (plugin)**: `metamanager-test/` — WordPress detects update via `MM_Updater`
- **Production channel (plugin)**: `metamanager/` — WordPress detects update via `MM_Updater`

## Conventions

- Branch protection on `test` and `main`: PRs required, no direct pushes
- Promotion = workflow_dispatch triggers direct git merge (no PRs)
- VERSION file must stay in sync with debian/changelog (CI handles this automatically)
- CI auto-bumps both `debian/changelog` and `VERSION` on every dev push — do not manually edit either
- PHP 8.4 for WP-CLI (`php8.4 /usr/local/bin/wp --path=/srv/www/wordpress`)
- **Test channel**: Used for development verification before production deployment
- **Production channel**: Used by production site, requires explicit workflow_dispatch promotion
