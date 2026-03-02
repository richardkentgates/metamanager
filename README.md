# Metamanager

**Metamanager** is a WordPress plugin that provides lossless image compression, bidirectional metadata sync between WordPress fields and embedded EXIF/IPTC/XMP tags, and automatic front-end Schema.org and Open Graph output — all powered by OS-level daemons and native WordPress APIs.

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
- **Bidirectional metadata sync**: on upload, embedded EXIF/IPTC/XMP is read from the file and populates WordPress fields automatically — existing user values are never overwritten
- **Expanded metadata fields**: Creator, Copyright, Owner, Headline, Credit, Keywords, Date Created, Rating (0–5 stars), City, State/Province, Country — stored as registered post meta, REST-exposed, and embedded by the daemon
- **GPS coordinates**: latitude, longitude, and altitude read from camera-embedded GPS tags and stored automatically — no manual entry required
- **Schema.org JSON-LD**: `ImageObject` block emitted on attachment pages and posts with a featured image — includes all fields plus `GeoCoordinates` when GPS data is present
- **Open Graph tags**: `og:image`, `og:image:alt`, `og:image:width`, `og:image:height`, `og:image:type`, `og:image:secure_url`
- **License link**: `<link rel="license">` for URL-format copyright values, `<meta name="copyright">` for plain-text notices
- **Native WordPress integration**: metadata fields on every image edit screen; compression status column in Media Library
- **Grouped edit UI**: Attribution & Rights, Editorial, Classification, Location — clearly separated in the attachment editor
- **Bulk operations**: compress all uncompressed images, or inject site provenance (Publisher + Website URL) into metadata
- **No false attribution**: bulk actions never set Creator, Copyright, Owner, or any per-image field
- **Real-time job dashboard**: live queue view and searchable/paginated history under Media → Metamanager
- **Re-queue on failure**: one-click retry for any failed job from the history table
- **Daemon health indicator**: status banner shows whether each daemon is running (via PID file — no `systemctl` privilege required)
- **Auto-updates**: native WordPress update pipeline integration — updates appear in Dashboard → Updates; includes a manual "Check for Updates" link on the Plugins page

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

### Attribution & Rights *(per-image only — never bulk)*

| Field | EXIF | IPTC | XMP |
|-------|------|------|-----|
| Creator | Artist | By-line | Creator |
| Copyright | Copyright | CopyrightNotice | Rights |
| Owner | OwnerName | — | Owner |

### Editorial

| Field | EXIF | IPTC | XMP |
|-------|------|------|-----|
| Headline | — | Headline | Headline |
| Credit | — | Credit | Credit |

### Classification

| Field | EXIF | IPTC | XMP |
|-------|------|------|-----|
| Keywords *(semicolon-separated)* | — | Keywords | Subject |
| Date Created | DateTimeOriginal | DateCreated | DateCreated |
| Rating *(0–5)* | — | — | Rating |

### Location *(IPTC Photo Metadata Standard)*

| Field | EXIF | IPTC | XMP |
|-------|------|------|-----|
| City | — | City | City |
| State / Province | — | Province-State | State |
| Country | — | Country-PrimaryLocationName | Country |

### GPS *(read-only — imported from camera, never editable)*

| Field | ExifTool source | Schema.org property |
|-------|----------------|---------------------|
| Latitude | `Composite:GPSLatitude` | `GeoCoordinates.latitude` |
| Longitude | `Composite:GPSLongitude` | `GeoCoordinates.longitude` |
| Altitude (m) | `Composite:GPSAltitude` | `GeoCoordinates.elevation` |

### WordPress Native *(bidirectional sync)*

| Field | WP source | EXIF | IPTC | XMP |
|-------|-----------|------|------|-----|
| Title | Post title | Title | ObjectName | Title |
| Description | Post content | ImageDescription | Caption-Abstract | Description |
| Caption | Post excerpt | — | Caption-Abstract | Caption |
| Alt Text | Alt field | — | — | AltTextAccessibility |

### Site Provenance *(bulk-safe — neutral, no ownership claim)*

| Field | Source | IPTC | XMP |
|-------|--------|------|-----|
| Publisher | Site name | Source | Publisher |
| Website | Site URL | Source | WebStatement |

**Creator, Copyright, Owner, and all per-image fields are never set by bulk actions.** Bulk operations only ever inject Publisher and Website.

---

## Front-End Schema & Open Graph

On every attachment page and single post/page with a featured image, Metamanager emits:

**Schema.org `ImageObject` JSON-LD** — all stored fields map to standard properties:

```json
{
  "@context": "https://schema.org",
  "@type": "ImageObject",
  "url": "https://example.com/wp-content/uploads/photo.jpg",
  "name": "Sunrise over the ridge",
  "creator": { "@type": "Person", "name": "Jane Doe" },
  "copyrightNotice": "© 2026 Jane Doe",
  "keywords": ["landscape", "sunrise", "nature"],
  "dateCreated": "2026-01-15",
  "locationCreated": {
    "@type": "Place",
    "name": "Boulder, CO, USA",
    "geo": {
      "@type": "GeoCoordinates",
      "latitude": 40.014984,
      "longitude": -105.270546,
      "elevation": 1655.0
    }
  }
}
```

**Open Graph tags** — `og:image`, `og:image:alt`, `og:image:width`, `og:image:height`, `og:image:type`, `og:image:secure_url`

**License tag** — `<link rel="license">` when the copyright field is a URL; `<meta name="copyright">` for plain-text notices

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

## Open Source Credits

Metamanager would not exist without the following open source tools and projects. Full credit, respect, and gratitude to their authors and maintainers.

---

### ExifTool
**Author:** Phil Harvey
**License:** [Perl Artistic License / GPL v1+](https://exiftool.org/#license)
**Website:** <https://exiftool.org>
**Repository:** <https://github.com/exiftool/exiftool>

The backbone of all metadata work in Metamanager. ExifTool reads and writes EXIF, IPTC, and XMP tags across virtually every image format in existence. Nothing else comes close to its breadth of format support and tag coverage. We use it both to import embedded metadata on upload and to write all metadata fields back to the file.

---

### libjpeg-turbo / jpegtran
**Maintainer:** libjpeg-turbo Project
**Original author:** Independent JPEG Group (IJG)
**License:** [BSD 3-Clause / IJG License / zlib](https://github.com/libjpeg-turbo/libjpeg-turbo/blob/main/LICENSE.md)
**Website:** <https://libjpeg-turbo.org>
**Repository:** <https://github.com/libjpeg-turbo/libjpeg-turbo>

`jpegtran`, part of the libjpeg-turbo package, performs lossless JPEG optimisation — reordering Huffman tables and enabling progressive scan without decoding or re-encoding a single pixel. The Independent JPEG Group created the original implementation; libjpeg-turbo maintains and significantly accelerates it.

---

### optipng
**Author:** Cosmin Truța
**License:** [zlib/libpng License](https://optipng.sourceforge.net/pngtech/optipng.html)
**Website:** <https://optipng.sourceforge.net>

`optipng` performs lossless PNG compression by trying multiple DEFLATE parameters and filter combinations to find the smallest lossless representation. No pixels are changed. We use it with `-o2 -preserve` to balance compression efficiency against processing time while preserving all file metadata.

---

### inotify-tools
**Maintainer:** inotify-tools contributors
**License:** [GPL v2](https://github.com/inotify-tools/inotify-tools/blob/master/COPYING)
**Repository:** <https://github.com/inotify-tools/inotify-tools>

`inotifywait` is the event source that makes Metamanager's daemons instant-response rather than polling. When a job JSON file is written to the queue directory, `inotifywait` fires immediately — no sleep loops, no delay. The Linux kernel's inotify subsystem does the actual watching; inotify-tools provides the userspace interface.

---

### jq
**Original author:** Stephen Dolan
**Maintainer:** [jqlang organisation](https://github.com/jqlang)
**License:** [MIT License](https://github.com/jqlang/jq/blob/master/COPYING)
**Website:** <https://jqlang.github.io/jq/>
**Repository:** <https://github.com/jqlang/jq>

`jq` is used inside the Bash daemons to parse the job JSON files written by PHP — extracting field values by key without requiring any additional runtime. It is lightweight, dependency-free in execution, and universally available across the Linux distributions we support.

---

### systemd
**Maintainers:** systemd contributors
**License:** [LGPL v2.1+](https://github.com/systemd/systemd/blob/main/LICENSE.LGPL2.1)
**Website:** <https://systemd.io>
**Repository:** <https://github.com/systemd/systemd>

Metamanager's compression and metadata daemons run as systemd services — `metamanager-compress-daemon.service` and `metamanager-meta-daemon.service`. systemd manages process lifecycle, automatic restart on failure, boot-time start, and journal-based logging for both daemons. We use a PID-file pattern for health checks so that the WordPress plugin does not require `systemctl` privileges.

---

### WordPress
**Maintainer:** [WordPress Foundation](https://wordpressfoundation.org)
**License:** [GPL v2+](https://wordpress.org/about/license/)
**Website:** <https://wordpress.org>
**Repository:** <https://github.com/WordPress/WordPress>

Metamanager is a WordPress plugin and relies entirely on the WordPress API — hooks, post meta, REST API, WP-Cron, the admin UI framework, media handling, and the plugin update pipeline. WordPress is itself GPL v2+, which is why Metamanager is licensed under GPL v3 (a compatible and later version).

---

### Website fonts

**Inter** — designed by Rasmus Andersson. [SIL Open Font License 1.1](https://github.com/rsms/inter/blob/master/LICENSE.txt). <https://rsms.me/inter/>

**JetBrains Mono** — designed by JetBrains. [SIL Open Font License 1.1](https://github.com/JetBrains/JetBrainsMono/blob/main/OFL.txt). <https://www.jetbrains.com/lp/mono/>

Both fonts are served via [Google Fonts](https://fonts.google.com) on the documentation website only — they are not bundled with the plugin.

---

## License

GPLv3 or later. See [LICENSE](LICENSE).

Copyright © Richard Kent Gates
