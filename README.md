# Metamanager

**Metamanager** is a WordPress plugin that provides lossless image compression and standards-compliant metadata embedding (EXIF, IPTC, XMP) through OS-level daemons, while expanding the WordPress Media Library admin with native metadata editing and a real-time job dashboard.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://php.net)

---

## Why Metamanager

WordPress's built-in image handling is PHP-only. PHP cannot do lossless JPEG or PNG compression, and its metadata tools are limited to basic EXIF with no IPTC or XMP support. Metamanager offloads all image work to the OS where purpose-built tools — `jpegtran`, `optipng`, and `ExifTool` — do the job properly.

PHP's role is coordinator only: write the instruction, let the daemon execute it.

---

## Features

- **Lossless compression**: JPEG via `jpegtran` (no re-encoding), PNG via `optipng`
- **Standards-compliant metadata**: EXIF, IPTC, and XMP written simultaneously via ExifTool
- **Native WordPress integration**: metadata fields on every image edit screen; compression status column in Media Library
- **Bulk operations**: compress all uncompressed images, or inject site provenance (Publisher + Website URL) into metadata
- **No false attribution**: bulk actions never set Creator, Copyright, or Owner — those are individual image fields
- **Real-time job dashboard**: live queue view and searchable/paginated history under Media → Metamanager
- **Re-queue on failure**: one-click retry for any failed job from the history table
- **Daemon health indicator**: status banner shows whether each daemon is running (via PID file — no `systemctl` privilege required)
- **Auto-updates**: native WordPress update pipeline integration — updates appear in Dashboard → Updates like any hosted plugin; includes a manual "Check for Updates" link on the Plugins page

---

## Requirements

| Component | Minimum | Notes |
|-----------|---------|-------|
| WordPress | 6.0 | |
| PHP | 8.0 | |
| ExifTool | any | `perl-Image-ExifTool` or `libimage-exiftool-perl` |
| jpegtran | any | `libjpeg-turbo-progs` (apt) or `libjpeg-turbo-utils` (dnf) |
| optipng | any | `optipng` |
| inotify-tools | any | For daemon file watching |
| jq | any | JSON parsing in daemon scripts |
| systemd | v232+ | Service management |

---

## Quick Install (one command)

```bash
wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/install.sh | sudo bash
```

If WordPress is not in a standard location:

```bash
sudo bash install.sh --wp-path /path/to/your/wordpress
```

The install script:
1. Detects your WordPress path
2. Installs all system dependencies via `apt`/`dnf`
3. Copies the plugin into `wp-content/plugins/metamanager/`
4. Patches daemon scripts with your actual `WP_CONTENT_DIR`
5. Installs, enables, and starts both systemd daemons
6. Activates the plugin via WP-CLI if available

---

## Updating

**Via WordPress admin (recommended):**
Metamanager integrates with the native WordPress update system. When a new GitHub release is tagged, it appears automatically in **Dashboard → Updates** within 12 hours. Click **Update now** exactly as you would for any plugin.

A **Check for Updates** action link on **Plugins → Installed Plugins** forces an immediate check without waiting.

**Via server script (plugin files only — daemons untouched):**

```bash
wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/install.sh | sudo bash -s -- --update
```

Or from a cloned directory:

```bash
sudo bash install.sh --update
```

The `--update` flag skips dependency installation, daemon patching, and systemd service management. It only syncs plugin PHP, JS, and asset files, fixes permissions, and flushes the WordPress object cache.

---

## Manual Install

```bash
# 1. Clone
git clone https://github.com/richardkentgates/metamanager.git

# 2. Copy plugin
cp -r metamanager /path/to/wordpress/wp-content/plugins/

# 3. Run installer (handles daemons + dependencies)
sudo bash /path/to/wordpress/wp-content/plugins/metamanager/install.sh \
  --wp-path /path/to/wordpress
```

---

## How It Works

```
WordPress (PHP)                     OS (Bash daemons)
─────────────────                   ──────────────────────────────────────
Upload / edit image
       │
       ▼
Write job JSON to                   inotifywait detects new file
  wp-content/metamanager-jobs/             │
  compress/  or  meta/             ◄───────┘
                                          │
                                          ▼
                                   Process image:
                                     jpegtran / optipng  (compression)
                                     ExifTool            (metadata)
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

## Metadata Fields

| Field | Where set | EXIF | IPTC | XMP |
|-------|-----------|------|------|-----|
| Title | WP post title | Title | ObjectName | Title |
| Description | WP post content | ImageDescription | Caption-Abstract | Description |
| Caption | WP excerpt | — | Caption-Abstract | Caption |
| Alt Text | WP alt field | — | — | AltTextAccessibility |
| Creator | Per-image field | Artist | By-line | Creator |
| Copyright | Per-image field | Copyright | CopyrightNotice | Rights |
| Owner | Per-image field | OwnerName | — | Owner |
| Publisher | Site name (auto) | — | Source | Publisher |
| Website | Site URL (auto) | — | Source | WebStatement |

**Creator, Copyright, and Owner are never set by bulk actions.** These fields carry attribution and rights meaning — they should be set deliberately per image. Bulk operations only ever inject Publisher and Website (neutral provenance).

---

## Daemon Management

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

## Uninstall

```bash
# Stop and remove daemons
sudo systemctl stop metamanager-compress-daemon metamanager-meta-daemon
sudo systemctl disable metamanager-compress-daemon metamanager-meta-daemon
sudo rm /etc/systemd/system/metamanager-*.service
sudo rm /usr/local/bin/metamanager-*-daemon.sh
sudo systemctl daemon-reload

# Remove plugin (or deactivate via WordPress admin first)
rm -rf /path/to/wordpress/wp-content/plugins/metamanager

# Remove job queue (optional — contains no permanent data)
rm -rf /path/to/wordpress/wp-content/metamanager-jobs
```

The plugin database table (`wp_metamanager_jobs`) is left in place when you deactivate. Delete it manually if you want a clean removal:
```sql
DROP TABLE IF EXISTS wp_metamanager_jobs;
```

---

## License

GPLv3 or later. See [LICENSE](LICENSE).

Copyright © Richard Kent Gates
