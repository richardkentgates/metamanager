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

The plugin triggers daemon updates automatically. The server repo provides:

- **`VERSION`** — daemon version file at `/usr/local/lib/metamanager/VERSION`, readable by www-data, read by plugin to detect version mismatch
- **`sudoers-metamanager`** — installed to `/etc/sudoers.d/`, grants www-data passwordless sudo for specific apt/systemctl commands
- **`debian/postinst`** — sets VERSION to 0644 and sudoers to 0440 during package install

### Self-Updater (Daemon-Side)

The daemon includes a self-updater that checks a central manifest for new versions and updates independently of the WordPress plugin.

**Components:**
- **`/usr/local/bin/metamanager-self-updater.sh`** — Bash script that checks manifest, runs apt upgrade, restarts daemons
- **`/etc/systemd/system/metamanager-self-updater.service`** — Systemd service unit
- **`/etc/systemd/system/metamanager-self-updater.timer`** — Systemd timer (every 6 hours)
- **`/var/run/metamanager-self-updater.json`** — Status file read by WordPress dashboard widget
- **`/var/log/metamanager-self-updater.log`** — Log file

**Manifest URL:** `https://apt.richardkentgates.com/metamanager/daemon-manifest.json`

**Status file format:**
```json
{
  "installed_version": "2.4.53",
  "available_version": "2.4.53",
  "last_check": "2026-08-20T18:04:09Z",
  "last_update": "",
  "last_action": "up_to_date",
  "last_message": "Daemon v2.4.53 is current",
  "timer_enabled": true
}
```

**Actions:**
- `--check` — Check for updates (default)
- `--update` — Check and apply updates
- `--status` — Show current status as JSON

**Dashboard widget** reads the status file and displays: timer status, last check time, available version, last action, and details.

## VERSION File

The `VERSION` file is the single source of truth for the installed daemon version. The plugin reads it via `MM_Daemon_Updater::get_daemon_version()` to compare against `daemon-compatibility.json`.

**Format**: Plain semver string, e.g. `2.4.10` (no Debian revision suffix).

**Installation**: The `.deb` package installs it to `/usr/local/lib/metamanager/VERSION` via `debian/metamanager.install`. The `postinst` script sets permissions to `0644` so www-data can read it.

**Sync with debian/changelog**: The CI workflow (`ci.yml`) auto-bumps both `debian/changelog` and `VERSION` on every push to dev. They must stay in sync — the patch version in `debian/changelog` (e.g. `2.4.10-1`) must match the `VERSION` file (e.g. `2.4.10`). CI handles this automatically; never edit either file manually.

**Format difference**: `debian/changelog` uses Debian epoch format `2.4.10-1` (upstream-revision). The `VERSION` file uses plain semver `2.4.10` (no `-1` suffix). The CI strips the `-1` when writing `VERSION`.

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
