# AGENTS.md — Metamanager Server

## Deployment Rules

**NEVER SCP or copy files directly to production servers unless explicitly testing a fix.**

All software must move to production through native update systems:

- **Daemon updates**: Push to `main` → CI/CD builds `.deb` → deploys to apt server repo → `apt upgrade` on production server (typically triggered automatically by plugin update via `MM_Daemon_Updater`)
- **Plugin updates**: Push to `main` → CI/CD builds zip → deploys to apt server `metadata.json` → WordPress detects update via `MM_Updater` → plugin updates → plugin automatically triggers daemon update

The only exception is temporary testing during active development sessions, where files may be SCP'd for immediate verification. After testing, the fix must go through the proper pipeline before being considered deployed.

## How Daemon Updates Work

The plugin triggers daemon updates automatically. The server repo provides:

- **`VERSION`** — daemon version file at `/usr/local/lib/metamanager/VERSION`, readable by www-data, read by plugin to detect version mismatch
- **`sudoers-metamanager`** — installed to `/etc/sudoers.d/`, grants www-data passwordless sudo for specific apt/systemctl commands
- **`debian/postinst`** — sets VERSION to 0644 and sudoers to 0440 during package install

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
- Production: `104.197.172.183` (Ubuntu 20.04, WordPress at `/srv/www/wordpress/`)

## Conventions

- Branch protection on `test` and `main`: PRs required, no direct pushes
- Promotion = open PR from `dev` → `test` or `test` → `main`, CI runs, merge
- VERSION file must stay in sync with debian/changelog (CI handles this automatically)
- CI auto-bumps both `debian/changelog` and `VERSION` on every dev push — do not manually edit either
- PHP 8.2 for WP-CLI (`php8.2 /usr/local/bin/wp --path=/srv/www/wordpress`)
