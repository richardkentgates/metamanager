# Metamanager Server

**Metamanager Server** provides the OS-level daemons, systemd services, and Debian packaging for the [Metamanager WordPress plugin](https://github.com/richardkentgates/metamanager-plugin).

The server installs compression and metadata embedding daemons that watch a filesystem job queue and process media files using ExifTool, jpegtran, optipng, cwebp, and ffmpeg — all outside of PHP so WordPress never blocks on the hot path.

[![License: GPL 3.0+](https://img.shields.io/badge/License-GPL--3.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Platform](https://img.shields.io/badge/Platform-Linux-FCC624?logo=linux&logoColor=black)](https://github.com/richardkentgates/metamanager#requirements)
[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-ea4aaa?logo=github-sponsors)](https://github.com/sponsors/richardkentgates)
![Status](https://img.shields.io/badge/status-stable-brightgreen)

**[Plugin docs → mm-plugin.richardkentgates.com](https://mm-plugin.richardkentgates.com)** · **[Server docs → metamanager.richardkentgates.com](https://metamanager.richardkentgates.com)**

> **Looking for the WordPress plugin?** Features, metadata fields, Schema.org, sitemaps, WP-CLI, and REST API documentation live in the [plugin repository](https://github.com/richardkentgates/metamanager-plugin).

---

## Quick Install

```bash
# Import the GPG key
wget -q -O /tmp/metamanager.key https://apt.richardkentgates.com/key.gpg
sudo gpg --batch --yes --dearmor -o /usr/share/keyrings/metamanager.gpg /tmp/metamanager.key

# Add the apt repository
echo "deb [signed-by=/usr/share/keyrings/metamanager.gpg] https://apt.richardkentgates.com stable main" | sudo tee /etc/apt/sources.list.d/metamanager.list

# Install
sudo apt update && sudo apt install metamanager
```

This installs:
- Compression and metadata daemons
- Systemd services (auto-enabled)
- All OS dependencies (ExifTool, jpegtran, optipng, cwebp, ffmpeg, jq, inotify-tools)

After installing, install the [WordPress plugin](https://github.com/richardkentgates/metamanager-plugin) and activate it.

---

## Requirements

### Tested environments

| OS | PHP | WordPress | Package manager | Result |
|---|---|---|---|---|
| Ubuntu 22.04 LTS | 8.1 – 8.3 | 6.0+ | apt | ✅ Full install |
| AlmaLinux 9.7 (RHEL 9-compat) | 8.3.30 | 6.9.4 | dnf + EPEL + CRB | ✅ Full install |
| GitHub Actions (ubuntu-latest) | 8.2 | Latest | apt | ✅ CI (every push) |

### Component requirements

| Component | Minimum | Notes |
|-----------|---------|-------|
| OS | Linux | systemd required; tested on **Ubuntu 22.04+**, **Debian 12+**, **AlmaLinux / Rocky / RHEL 9+**. The install script supports `apt`, `dnf`, and `yum`. |
| bash | 5.0+ | Required by the daemon scripts. Ubuntu 18.04 (bash 4.4) is **not supported**. |
| ExifTool | any | `libimage-exiftool-perl` (apt) or `perl-Image-ExifTool` (dnf via EPEL) |
| jpegtran | any | `libjpeg-turbo-progs` (apt) or `libjpeg-turbo-utils` (dnf) |
| optipng | any | `optipng` — in EPEL 9 for RHEL-based systems |
| cwebp | any | `webp` (apt) or `libwebp-tools` (dnf via CRB) |
| ffmpeg | any | `ffmpeg` (apt) or via RPM Fusion (dnf) — for video remux |
| inotify-tools | any | `inotify-tools` — in EPEL 9 for RHEL-based systems |
| jq | any | JSON parsing in daemon scripts |
| systemd | v232+ | Minimum for `ProtectSystem=strict` and `ReadWritePaths=` used in service units |

---

## How It Works

```
WordPress (PHP)                     OS (Bash daemons)
────────────────                    ─────────────────────────────────────
Upload / scan / edit media file
(image, video, audio, PDF)
       │
       ├── On upload or scan: enqueue_import_job() writes an
       │   'import' job — daemon reads embedded tags (EXIF/IPTC/
       │   XMP, ID3, QuickTime, Vorbis, GPS) and returns them
       │   as JSON. WP-Cron applies to WP fields, never overwrites.
       │
       ▼
Write job JSON to                   inotifywait detects new file
  wp-content/metamanager-jobs/             │
  compress/  or  meta/             ◄───────┘
                                          │
                                          ▼
                                   Process file:
                                     jpegtran / optipng / cwebp  (image compression)
                                     ffmpeg                       (video remux)
                                     ExifTool                     (metadata — all types)
                                          │
                                          ▼
                                   Write result JSON to
                                    completed/  or  failed/
                                          │
WP-Cron (every 60s)        ◄─────────────┘
reads result files,
inserts into DB,
deletes result file
       │
       ▼
History table updated
(Media → Metamanager)
```

---

## Daemon Architecture

Metamanager runs three systemd units: two worker daemons and a self-updater (with 60-second timer):

### Compression daemon (`metamanager-compress-daemon.sh`)

- Watches `wp-content/metamanager-jobs/compress/` via `inotifywait`
- Delegates to tool by media type:
  - **Images**: `jpegtran` (lossless JPEG), `optipng` (lossless PNG), `cwebp -lossless` (lossless WebP)
  - **Video**: `ffmpeg -c copy` (lossless container remux — strips padding, no re-encoding)
- Writes result JSON to `completed/` or `failed/`

### Metadata daemon (`metamanager-meta-daemon.sh`)

- Watches `wp-content/metamanager-jobs/meta/` (import jobs arrive as `job_type: "import"` inside `meta/`)
- **Import jobs**: reads embedded tags (EXIF/IPTC/XMP, ID3, QuickTime, Vorbis) via ExifTool, returns JSON for WP-Cron to apply
- **Embed jobs**: writes current WordPress field values back into files via ExifTool
- Handles all media types including PDF (XMP fields)

### Common daemon patterns

| Pattern | Detail |
|---------|--------|
| Event-driven | `inotifywait` fires on file creation — no polling, no sleep loops |
| Atomic ownership | First daemon to rename a job file claims it (`mv ... .processing`) |
| PID files | Written to `wp-content/metamanager-jobs/` — used by plugin for health checks |
| Error handling | Failed jobs written to `failed/` with error message in result JSON |
| Tool delegation | Each tool handles one format family — no monolithic binary |

---

## Systemd Services

Both daemons run as the web-server user (patched at install time) (the WordPress user) with security hardening:

```ini
[Service]
Type=simple
User=www-data
Group=www-data
ExecStart=/usr/local/bin/metamanager-compress-daemon.sh
Restart=on-failure
RestartSec=5

# Security hardening
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/path/to/wordpress/wp-content/metamanager-jobs /path/to/wordpress/wp-content/uploads /var/log
NoNewPrivileges=true
PrivateTmp=true
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
```

### Managing daemons

```bash
# Status
systemctl status metamanager-compress-daemon
systemctl status metamanager-meta-daemon

# Logs
journalctl -u metamanager-compress-daemon -f
journalctl -u metamanager-meta-daemon -f

# Restart
sudo systemctl restart metamanager-compress-daemon
sudo systemctl restart metamanager-meta-daemon
```

---

## Installer Script

`metamanager-install.sh` handles fresh installs and updates:

### What it does

1. Detects package manager (`apt`, `dnf`, `yum`)
2. Installs all OS dependencies (ExifTool, jpegtran, optipng, cwebp, ffmpeg, jq, inotify-tools)
3. Copies daemon scripts to `/usr/local/bin/`
4. Creates and enables systemd service units
5. Sets correct ownership (`www-data:www-data`) on the job queue directory

### What it does NOT do

- Does not install the WordPress plugin (that's a separate step)
- Does not configure WordPress or database settings
- Does not require any WordPress path when run from the server repo directory

### Usage

```bash
# Fresh install
sudo bash metamanager-install.sh --wp-path /srv/www/wordpress

# Update: daemon updates are automatic via the self-updater (apt).
# To re-run the installer manually (e.g. after a WordPress path change):
sudo bash metamanager-install.sh --wp-path /srv/www/wordpress

# Uninstall
sudo apt-get remove metamanager      # stops daemons, removes units/scripts
sudo apt-get purge metamanager       # also removes logs, state, config
```

---

## CI/CD Pipeline

### Dev branch

Pushes to `dev` trigger the CI workflow:

1. **ShellCheck** — lints all bash scripts (daemon scripts, installer)
2. **Auto-increment version** — bumps `debian/changelog` and `VERSION` file

### Promote to test

Triggered manually via GitHub Actions `workflow_dispatch`:

1. Merges `dev` into `test`
2. Builds `.deb` package
3. Deploys to apt repo (pre-release channel)

### Promote to main

Triggered manually via GitHub Actions `workflow_dispatch`:

1. Merges `test` into `main` (with `-X theirs` for any doc conflicts)
2. Builds `.deb` package
3. Creates git tag (`v2.x.x`)
4. Creates GitHub release with `.deb` attached
5. Deploys to apt repo (stable channel)

### APT repository

The apt repo at `apt.richardkentgates.com` serves two channels:

| Channel | Branch | Package version | Use case |
|---------|--------|----------------|----------|
| Stable | `main` | `2.4.x` | Production |
| Test | `dists/test` | `2.4.x-1` (same format; pin with `-t test`) | Pre-release testing |

The plugin reads the `VERSION` file for dashboard display only — it never triggers updates.

---

## Version Numbers

| File | Purpose | Format |
|------|---------|--------|
| `debian/changelog` | Debian package version | `2.4.17-1` (upstream-revision) |
| `VERSION` | Installed daemon version (read by plugin) | `2.4.17` (plain semver, no `-1`) |
| `daemon-compatibility.json` | Plugin-to-daemon version mapping | `{ "2.3.58": "2.4.8" }` |

CI auto-bumps `debian/changelog` and `VERSION` on every push to `dev`. They must stay in sync — CI handles this automatically; never edit either file manually.

---

## Updating

**Via WordPress admin (recommended):**
The self-updater (systemd timer, every 60 seconds) compares the installed daemon version against the required version declared by the plugin's `daemon-compatibility.json` and runs `apt-get upgrade` automatically. No manual intervention is needed.

**Via apt directly:**

```bash
sudo apt update && sudo apt upgrade metamanager
```

---

## Manual Install

```bash
# 1. Clone
git clone https://github.com/richardkentgates/metamanager.git

# 2. Copy to WordPress plugins directory
cp -r metamanager /path/to/wordpress/wp-content/plugins/

# 3. Run installer (handles daemons + dependencies)
sudo bash /path/to/wordpress/wp-content/plugins/metamanager/metamanager-install.sh \
  --wp-path /path/to/wordpress
```

---

## Uninstall

### Removing the system daemons

```bash
# Stop and remove daemons
sudo systemctl stop metamanager-compress-daemon metamanager-meta-daemon
sudo systemctl disable metamanager-compress-daemon metamanager-meta-daemon
sudo rm /etc/systemd/system/metamanager-*.service
sudo rm /usr/local/bin/metamanager-*-daemon.sh
sudo systemctl daemon-reload

# Remove the plugin if not already deleted via WP admin
rm -rf /path/to/wordpress/wp-content/plugins/metamanager
```

Or via the installer:

```bash
sudo apt-get purge metamanager
```

### Removing plugin data via WordPress admin

See the [plugin README](https://github.com/richardkentgates/metamanager-plugin#uninstall) for the WordPress admin uninstall flow.

---

## Repository Structure

```
metamanager/
├── daemons/
│   ├── metamanager-compress-daemon.sh    # Compression daemon
│   └── metamanager-meta-daemon.sh        # Metadata daemon
├── metamanager-install.sh                # Installer script
├── VERSION                               # Installed daemon version
├── daemon-compatibility.json             # Plugin ↔ daemon version map
├── debian/
│   ├── changelog                         # Debian package version
│   ├── control                           # Package metadata
│   ├── postinst                          # Post-install script
│   ├── postrm                            # Post-remove/purge cleanup
│   └── metamanager.install               # Files to install
├── .github/
│   ├── workflows/
│   │   ├── ci.yml                        # Dev CI (ShellCheck + version bump)
│   │   ├── promote-to-test.yml           # workflow_dispatch: dev→test, build, deploy
│   │   └── promote-to-main.yml           # workflow_dispatch: test→main, tag, release, deploy
│   └── BRANCHING.md                      # Branch strategy
├── docs/                                 # Documentation
│   ├── 01-overview.md                    # Architecture overview
│   ├── 02-installation.md                # Installation guide
│   ├── 03-daemon-reference.md            # Daemon scripts reference
│   ├── 04-systemd.md                     # systemd services and hardening
│   ├── 05-job-queue.md                   # Job queue contract
│   └── 06-troubleshooting.md             # Common issues and fixes
├── ARCHITECTURE.md                       # Server architecture reference
├── CONTRIBUTING.md                       # Development guide
├── CHANGELOG.md                          # Server release history
├── SECURITY.md                           # Security policy
└── AGENTS.md                             # AI agent workflow rules
```

---

## Open Source Credits

Metamanager would not exist without the following open source tools and projects. Full credit, respect, and gratitude to their authors and maintainers.

### ExifTool
**Author:** Phil Harvey
**License:** [Perl Artistic License / GPL v1+](https://exiftool.org/#license)
**Website:** <https://exiftool.org>

The backbone of all metadata work. ExifTool reads and writes EXIF, IPTC, and XMP tags across virtually every image format.

### libjpeg-turbo / jpegtran
**License:** [BSD 3-Clause / IJG License / zlib](https://github.com/libjpeg-turbo/libjpeg-turbo/blob/main/LICENSE.md)
**Website:** <https://libjpeg-turbo.org>

Lossless JPEG optimisation — reordering Huffman tables and enabling progressive scan without re-encoding a single pixel.

### optipng
**Author:** Cosmin Truța
**License:** [zlib/libpng License](https://optipng.sourceforge.net/pngtech/optipng.html)
**Website:** <https://optipng.sourceforge.net>

Lossless PNG compression by trying multiple DEFLATE parameters and filter combinations.

### libwebp / cwebp
**Author:** Google
**License:** [BSD 3-Clause](https://chromium.googlesource.com/webm/libwebp/+/refs/heads/main/COPYING)
**Website:** <https://developers.google.com/speed/webp>

Lossless WebP compression via `cwebp -lossless`.

### FFmpeg
**License:** [LGPL v2.1+ / GPL v2+](https://ffmpeg.org/legal.html)
**Website:** <https://ffmpeg.org>

Lossless video container remux via `ffmpeg -c copy` — strips padding and redundant data without re-encoding.

### inotify-tools
**License:** [GPL v2](https://github.com/inotify-tools/inotify-tools/blob/master/COPYING)
**Repository:** <https://github.com/inotify-tools/inotify-tools>

Event-driven file watching — `inotifywait` fires immediately on job file creation.

### jq
**License:** [MIT License](https://github.com/jqlang/jq/blob/master/COPYING)
**Website:** <https://jqlang.github.io/jq/>

JSON parsing inside the bash daemon scripts.

### systemd
**License:** [LGPL v2.1+](https://github.com/systemd/systemd/blob/main/LICENSE.LGPL2.1)
**Website:** <https://systemd.io>

Process lifecycle, automatic restart, boot-time start, and journal-based logging for both daemons.

---

## Sponsorship

Metamanager is free and open source. If it saves you time or adds value to your work, consider supporting its continued development:

**[❤ Sponsor on GitHub →](https://github.com/sponsors/richardkentgates)**

---

## License

GPLv3 or later. See [LICENSE](LICENSE).

Copyright © Richard Kent Gates
