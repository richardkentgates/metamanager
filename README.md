# Metamanager

**Metamanager** is a WordPress plugin that provides lossless compression for images, video, and audio; bidirectional metadata sync between WordPress fields and embedded file tags (EXIF/IPTC/XMP, ID3, QuickTime atoms, Vorbis comments, and XMP); PDF metadata import; and automatic front-end Schema.org JSON-LD and Open Graph output for all media types — all powered by OS-level daemons and native WordPress APIs.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://php.net)
[![Code Scanning](https://github.com/richardkentgates/metamanager/actions/workflows/codeql.yml/badge.svg)](https://github.com/richardkentgates/metamanager/actions/workflows/codeql.yml)
[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-ea4aaa?logo=github-sponsors)](https://github.com/sponsors/richardkentgates)
![Status](https://img.shields.io/badge/status-stable-brightgreen)

📖 **[Full documentation → GitHub Wiki](https://github.com/richardkentgates/metamanager/wiki)**

> [!NOTE]
> **Beta software.** Core features are complete and integration-tested. Breaking changes are possible before a stable 1.0 release. [Bug reports and feedback](https://github.com/richardkentgates/metamanager/issues) are welcome.

---

## Why Metamanager

WordPress's built-in media handling is PHP-only. PHP cannot do lossless JPEG, PNG, or WebP compression, has no native video remux, and its metadata tools are limited to basic EXIF with no IPTC or XMP support. Metamanager offloads all media work to the OS where purpose-built tools — `jpegtran`, `optipng`, `cwebp`, `ffmpeg`, and `ExifTool` — do the job properly.

PHP's role is coordinator only: write the instruction, let the daemon execute it.

---

## Features

- **Lossless image compression**: JPEG via `jpegtran` (no re-encoding), PNG via `optipng`, WebP via `cwebp -lossless`
- **Video support**: metadata (title, description, creator, copyright, keywords, date) written as QuickTime atoms for MP4/MOV/M4A, XMP-only for AVI/WMV/WMA; video container remux via `ffmpeg`; read-only for MKV/WebM/OGV
- **Audio support**: ID3 tags for MP3, QuickTime atoms for M4A, Vorbis comments for OGG/FLAC, XMP-only for WAV/WMA
- **PDF support**: title, author, keywords, and creation date imported on upload; XMP fields written back by daemon; Schema.org `DigitalDocument` output
- **Standards-compliant metadata**: EXIF, IPTC, and XMP written simultaneously via ExifTool for images; native tag formats used per file type
- **Bidirectional metadata sync**: on upload, embedded tags are read from the file and populate WordPress fields automatically — existing user values are never overwritten
- **Expanded metadata fields**: Creator, Copyright, Owner, Headline, Credit, Keywords, Date Created, Rating (0–5 stars), City, State/Province, Country — stored as registered post meta, REST-exposed, and embedded by the daemon
- **GPS coordinates**: latitude, longitude, and altitude read from camera-embedded GPS tags and stored automatically — no manual entry required
- **Schema.org JSON-LD**: `ImageObject` for images (with `GeoCoordinates` when GPS data is present), `VideoObject` for video, `AudioObject` for audio, `DigitalDocument` for PDF — emitted on attachment pages and posts with a featured image
- **Open Graph tags**: `og:image` / `og:video` / `og:audio` per media type; width, height, type, and alt where applicable
- **License link**: `<link rel="license">` for URL-format copyright values, `<meta name="copyright">` for plain-text notices
- **Native WordPress integration**: metadata fields on every image edit screen; compression status column in Media Library
- **Grouped edit UI**: Attribution & Rights, Editorial, Classification, Location — clearly separated in the attachment editor
- **Bulk operations**: compress all uncompressed images, or inject site provenance (Publisher + Website URL) into metadata
- **No false attribution**: bulk actions never set Creator, Copyright, Owner, or any per-image field
- **Real-time job dashboard**: live queue view and searchable/paginated history under Media → Metamanager
- **Re-queue on failure**: one-click retry for any failed job from the history table
- **Daemon health indicator**: status banner shows whether each daemon is running (via PID file — no `systemctl` privilege required)
- **REST API access control**: disable all Metamanager REST endpoints or restrict them to a comma-separated list of allowed IP addresses from Media → MM Settings — unauthorized requests receive a `403` before any capability check runs
- **Upload receipt emails**: optional batched digest email to the uploading user and admin when media is uploaded (one email per 60-second window); configurable extra CC address; failed sends are surfaced as a dismissible admin notice with one-click retry
- **Auto-updates**: native WordPress update pipeline integration — updates appear in Dashboard → Updates; includes a manual "Check for Updates" link on the Plugins page
- **Multisite compatible**: network activation creates the DB table and schedules cron on every existing site; new blog creation is handled automatically via `wp_initialize_site`
- **Clean uninstall**: an opt-in "Remove all data on uninstall" setting wipes all options, post meta, the job log table, the job queue directory, and the updater transient when the plugin is deleted — nothing is removed by default

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
| OS | Linux | systemd required; tested on **Ubuntu 22.04+**, **Debian 12+**, **AlmaLinux / Rocky / RHEL 9+**. The install script supports `apt`, `dnf`, and `yum`. Other distros require manual dependency installation. |
| bash | 5.0+ | Required by the daemon scripts. Ubuntu 18.04 (bash 4.4) is **not supported**. |
| WordPress | 6.0+ | |
| PHP | 8.0+ | |
| ExifTool | any | `libimage-exiftool-perl` (apt) or `perl-Image-ExifTool` (dnf via EPEL) |
| jpegtran | any | `libjpeg-turbo-progs` (apt) or `libjpeg-turbo-utils` (dnf) |
| optipng | any | `optipng` — in EPEL 9 for RHEL-based systems |
| cwebp | any | `webp` (apt) or `libwebp-tools` (dnf via CRB) — for lossless WebP compression |
| ffmpeg | any | `ffmpeg` (apt/Ubuntu) or via RPM Fusion (dnf/RHEL/AlmaLinux) — installed automatically by the installer on supported distros. Required for video container remux; all other features work without it. |
| inotify-tools | any | `inotify-tools` — in EPEL 9 for RHEL-based systems |
| jq | any | JSON parsing in daemon scripts — in base repos on all tested distros |
| systemd | v232+ | Minimum for `ProtectSystem=strict` and `ReadWritePaths=` used in service units |

---

## Quick Install (one command)

```bash
wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/metamanager-install.sh | sudo bash
```

If WordPress is not in a standard location:

```bash
sudo bash metamanager-install.sh --wp-path /path/to/your/wordpress
```

The install script:
1. Detects your WordPress path
2. Installs all system dependencies via `apt`/`dnf`
3. Copies the plugin into `wp-content/plugins/metamanager/`
4. Patches daemon scripts with your actual `WP_CONTENT_DIR`
5. Installs, enables, and starts both systemd daemons
6. Activates the plugin via WP-CLI if available

> **Note:** `cwebp` (`webp` package) and `ffmpeg` are installed automatically by the installer when available. If your distribution does not include them in the default repositories, install them manually before running the installer.

---

## Updating

**Via WordPress admin (recommended):**
Metamanager integrates with the native WordPress update system. When a new GitHub release is tagged, it appears automatically in **Dashboard → Updates** within 12 hours. Click **Update now** exactly as you would for any plugin.

A **Check for Updates** action link on **Plugins → Installed Plugins** forces an immediate check without waiting.

**Via server script (plugin files only — daemons untouched):**

```bash
sudo bash /path/to/wordpress/wp-content/plugins/metamanager/metamanager-install.sh --update
```

Or via wget:

```bash
wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/metamanager-install.sh | sudo bash -s -- --update
```

The `--update` flag skips dependency installation, daemon patching, and systemd service management. It only syncs plugin PHP, JS, and asset files, fixes permissions, and flushes the WordPress object cache. Both forms always fetch the latest code from GitHub — when run directly from the installed plugin directory the script detects this automatically.

---

## Manual Install

```bash
# 1. Clone
git clone https://github.com/richardkentgates/metamanager.git

# 2. Copy plugin
cp -r metamanager /path/to/wordpress/wp-content/plugins/

# 3. Run installer (handles daemons + dependencies)
sudo bash /path/to/wordpress/wp-content/plugins/metamanager/metamanager-install.sh \
  --wp-path /path/to/wordpress
```

---

## How It Works

```
WordPress (PHP)                     OS (Bash daemons)
─────────────────                   ──────────────────────────────────────
Upload / scan / edit media file
(image, video, audio, PDF)
       │
       ├── On upload or scan: import_from_file() reads embedded
       │   tags into empty WordPress fields (EXIF/IPTC/XMP,
       │   ID3, QuickTime, Vorbis, GPS) — never overwrites.
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

## Metadata Fields

> **Format-aware tag writing:** Images write EXIF, IPTC, and XMP simultaneously. MP3 uses ID3 tags; MP4/MOV/M4A use QuickTime atoms; OGG/FLAC use Vorbis comments (Headline is written as `XMP:Headline` since there is no standard Vorbis HEADLINE field); AVI/WAV/WMV/WMA and PDF use XMP-only. MKV/WebM/OGV are read-only at this time. All fields below apply to images; video, audio, and PDF share the same WordPress field names but write to the appropriate native tag system.

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
| Website | Site URL | — | WebStatement |

**Creator, Copyright, Owner, and all per-image fields are never set by bulk actions.** Bulk operations only ever inject Publisher and Website.

---

## Front-End Schema & Open Graph

On every attachment page and single post/page with a featured image, Metamanager emits structured data and Open Graph tags appropriate for the file type:

| File type | Schema.org type | Open Graph |
|-----------|-----------------|------------|
| Image (JPEG/PNG/WebP/GIF/TIFF) | `ImageObject` | `og:image` + width/height/alt/type |
| Video (MP4/MOV/AVI/MKV/WebM/WMV/OGV/3GP) | `VideoObject` | `og:video` + type |
| Audio (MP3/M4A/OGG/WAV/FLAC/WMA/AIFF) | `AudioObject` | `og:audio` + type |
| PDF (`application/pdf`) | `DigitalDocument` | `og:type=article` + title/description/url |

**Schema.org `ImageObject` JSON-LD example** (images — includes GPS when present):

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

**Open Graph tags** — type-appropriate properties per media family; `og:image`, `og:video`, or `og:audio` with dimensions, MIME type, and alt text where applicable

**License tag** — `<link rel="license">` when the copyright field is a URL; `<meta name="copyright">` for plain-text notices

---

## Media Sitemaps

Metamanager generates two XML sitemap endpoints served directly by WordPress without any static files:

| Endpoint | Content |
|----------|---------|
| `/sitemap-media.xml` | All images and (optionally) videos uploaded to the Media Library |
| `/sitemap-video.xml` | Videos sourced from self-hosted files, YouTube embeds, and Vimeo embeds in post content |

### Media sitemap (`/sitemap-media.xml`)

Each `<url>` entry contains an `<image:image>` extension node (when the Image Sitemap setting is enabled) and an `<video:video>` extension node for video attachments (when Video Sitemap is enabled). Fields populated from stored metadata:

| Sitemap field | Source |
|---------------|--------|
| `<loc>` | Attachment permalink |
| `<image:loc>` | File URL |
| `<image:title>` | Post title |
| `<image:caption>` | Post excerpt |
| `<image:license>` | Copyright field (when a URL) |
| `<video:title>` | Post title |
| `<video:description>` | Post excerpt |
| `<video:content_loc>` | File URL |
| `<video:duration>` | Duration (seconds, from `ffprobe` via daemon) |
| `<video:publication_date>` | Post date |
| `<video:rating>` | Rating field (0–5, normalised to 0–1) |
| `<video:family_friendly>` | Rating field (`yes` when rating ≤ 4) |

### Video sitemap (`/sitemap-video.xml`)

Scans all published posts for embedded video and emits one `<url>` per video found. Sources:

- **Self-hosted** — `<video src="…">` tags in post content
- **YouTube** — `<iframe>` embeds and WordPress oEmbed blocks (`youtu.be`, `youtube.com`)
- **Vimeo** — `<iframe>` embeds and WordPress oEmbed blocks (`vimeo.com`)

YouTube and Vimeo metadata is resolved via the oEmbed API and cached as transients for 24 hours.

### Settings

Sitemap settings are under **Media → MM Settings → Sitemaps**. Each source can be toggled independently:

| Setting | Default | Effect |
|---------|---------|--------|
| Serve media sitemap | On | Enable/disable `/sitemap-media.xml` |
| Include image nodes | On | Toggle `<image:image>` nodes |
| Serve video sitemap | On | Enable/disable `/sitemap-video.xml` |
| YouTube embeds | On | Include YouTube sources in video sitemap |
| Vimeo embeds | On | Include Vimeo sources in video sitemap |
| Self-hosted video | On | Include `<video>` tag sources |

### Submitting to Google Search Console

1. Open [Google Search Console](https://search.google.com/search-console) and select your property.
2. Go to **Sitemaps** in the left sidebar.
3. Enter `sitemap-media.xml` in the "Add a new sitemap" box and click **Submit**.
4. Repeat for `sitemap-video.xml`.

Both sitemaps refresh on every request — no manual resubmission is needed after new uploads.

### Planned sitemap enhancements

The following tags are not yet emitted but the underlying metadata is already stored by Metamanager and could be added in a future release:

| Tag | Source |
|-----|--------|
| `<image:geo_location>` | `mm_gps_latitude` / `mm_gps_longitude` — emit as `"{lat},{lng}"` or reverse-geocoded city/country |
| `<video:tag>` | `mm_keywords` — one element per keyword (Google supports up to 32 tags) |
| `<video:uploader>` | `mm_creator` — with `info` attribute pointing to the author profile URL |

Additional attachment types not yet included in `sitemap-media.xml`:

- **PDFs** — Google indexes PDFs directly by file URL; including them as plain `<url>` entries (no extension nodes required) improves discoverability.
- **Audio pages** — No official sitemap extension exists for audio, but attachment pages with Schema.org `AudioObject` JSON-LD can be submitted as standard `<url>` entries.

---

## WP-CLI

Metamanager registers a `wp metamanager` command group. WP-CLI 2.0+ required.

### `compress`

Queue lossless compression for one or all compressible attachments. Images are recompressed with `jpegtran`/`optipng`/`cwebp`; video is remuxed losslessly with `ffmpeg`. Audio and PDF have no compression step.

```bash
wp metamanager compress           # all compressible files
wp metamanager compress all       # explicit
wp metamanager compress 42        # single attachment ID
wp metamanager compress all --force  # re-queue already-compressed files
```

### `import`

Read embedded tags from the file (EXIF/IPTC/XMP for images; QuickTime atoms for MP4/M4A; ID3 for MP3; Vorbis comments for OGG/FLAC; XMP for AVI/WAV/WMV/WMA/PDF) and populate empty WordPress fields. Existing user-set values are never overwritten.

```bash
wp metamanager import             # all supported files
wp metamanager import all         # explicit
wp metamanager import 42          # single attachment ID
```

### `scan`

Import metadata for every library file not yet synced by Metamanager. Faster than `import all` on large existing libraries — already-synced files are skipped automatically. After importing metadata into WordPress fields, also queues daemon jobs to embed those fields back into the files (same as an upload).

```bash
wp metamanager scan
```

---

### `embed`

Queue metadata embedding for one or all supported attachments. Writes the current WordPress field values back into the media files via ExifTool. Use this after editing fields in WordPress to push changes into files.

```bash
wp metamanager embed           # all supported files
wp metamanager embed all       # explicit
wp metamanager embed 42        # single attachment ID
```

---

### `queue status`

Print pending job counts by type and daemon health.

```bash
wp metamanager queue status
```

```
+-------------+---------+
| Type        | Pending |
+-------------+---------+
| Compression | 4       |
| Metadata    | 12      |
| Total       | 16      |
+-------------+---------+
Compress daemon: running
Metadata daemon: running
```

### `stats`

Show aggregate compression savings from the job history.

```bash
wp metamanager stats
```

```
+----------------------+-------------------+
| Metric               | Value             |
+----------------------+-------------------+
| Total jobs           | 1,240             |
| Completed            | 1,198             |
| Failed               | 12                |
| Unique attachments   | 403               |
| Bytes saved          | 17.69 MB (9.1%)   |
+----------------------+-------------------+
```

---

## REST API

All endpoints are under the `metamanager/v1` namespace. Include a `X-WP-Nonce` header or use cookie authentication. Endpoints follow standard WordPress capability rules — the required capability matches what each operation actually does:

| Required capability | Held by | Applies to |
|---|---|---|
| `upload_files` | Author+ | Read-only status checks |
| `edit_others_posts` | Editor+ | Site-wide data and write operations |

**Base URL:** `https://yoursite.com/wp-json/metamanager/v1`

---

### `GET /stats` — requires `edit_others_posts`

Aggregate job statistics across the full history.

```json
{
  "total_jobs": 1240,
  "completed": 1198,
  "failed": 12,
  "unique_attachments": 403,
  "bytes_saved": 18540621,
  "bytes_original": 204800000
}
```

---

### `GET /jobs` — requires `edit_others_posts`

Paginated, filterable job history. Pagination totals in `X-WP-Total` and `X-WP-TotalPages` response headers.

| Parameter | Type | Default | Notes |
|-----------|------|---------|-------|
| `search` | string | `""` | Filter by file name or job type |
| `orderby` | string | `id` | Sort column |
| `order` | string | `DESC` | `ASC` or `DESC` |
| `per_page` | integer | `20` | 1–100 |
| `page` | integer | `1` | |

Each job object includes a `job_trigger` field indicating what created it:

| `job_trigger` value | Meaning |
|---------------------|----------|
| `upload` | Triggered on file upload |
| `edit` | Triggered by saving the attachment edit screen |
| `scan` | Triggered by Scan Existing Library / `wp metamanager scan` |
| `manual` | Triggered by re-compressing a single image from its edit screen |
| `requeue` | Triggered by clicking Re-queue on a failed job |
| `thumbnail_regen` | Triggered by thumbnail regeneration (e.g. Regenerate Thumbnails plugin) |
| `bulk` | Triggered by a WordPress Bulk Actions operation (Compress Lossless, Inject Site Info) |
| `batch_apply` | Triggered by the Batch Apply Metadata page |
| `cli` | Triggered via WP-CLI (`wp metamanager compress`, `embed`, etc.) |
| `rest_api` | Triggered via REST API (`POST /compress`, `POST /embed`) |

---

### `GET /jobs/{id}` — requires `edit_others_posts`

Single job record by database ID. Returns `404` if not found.

---

### `GET /attachment/{id}/status` — requires `upload_files`

Compression and metadata sync status for one attachment. Read-only; available to any uploader.

```json
{
  "id": 42,
  "compression": "compressed",
  "meta_synced": true
}
```

---

### `POST /attachment/{id}/compress` — requires `edit_others_posts`

Queue lossless compression for one attachment.

- **Image** → recompresses all registered sizes via `jpegtran`/`optipng`/`cwebp`
- **Video** → enqueues a lossless container remux via `ffmpeg`
- **Audio / PDF** → `422 Unprocessable Entity` (no compression step for these types)

| Parameter | Type | Default | Notes |
|-----------|------|---------|-------|
| `force` | boolean | `false` | Re-queue even if already compressed |

```json
{ "id": 42, "queued": true, "message": "Compression jobs queued." }
```

---

### `POST /attachment/{id}/embed` — requires `edit_others_posts`

Queue metadata embedding for one attachment. Writes the current WordPress field values back into the file via ExifTool.

- **Image** → queues embedding jobs for all registered sizes
- **Video** → queues a single metadata-writing job via `ffmpeg`/ExifTool
- **Audio / PDF** → queues a metadata-writing job (when the format supports writing)
- **Unsupported type** → `422 Unprocessable Entity`

```json
{ "id": 42, "queued": true, "message": "Metadata embedding jobs queued for all image sizes." }
```

---

### `POST /compression-status` — requires `upload_files`

Batch compression status query used by the Media Library column. Read-only; available to any uploader. Request body: `{ "ids": [1, 2, 3] }`. Returns a map of attachment ID → status string.

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

### Removing plugin data via the WordPress admin (recommended)

Before deleting the plugin, go to **Media → MM Settings → Data & Uninstall** and enable **Remove all data on uninstall**. Once that box is checked and you delete the plugin from the Plugins screen, Metamanager will automatically:

- Delete all plugin settings
- Remove all metadata it stored on attachments (16 post meta keys + `_mm_compressed_*` keys)
- Drop the `wp_metamanager_jobs` job-log table
- Remove the `wp-content/metamanager-jobs/` queue directory
- Delete the updater transient

On **multisite**, each site's setting is checked individually — only sites where the option is enabled are cleaned up.

If the option is **not** enabled (the default), deleting the plugin leaves all data intact — it can be recovered by reinstalling.

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

### Manual database cleanup (if needed)

If you deleted the plugin without enabling the data-removal setting, you can clean up manually:

```sql
-- Job log table
DROP TABLE IF EXISTS wp_metamanager_jobs;

-- Plugin settings
DELETE FROM wp_options WHERE option_name IN
  ('mm_compress_level','mm_notify_enabled','mm_notify_email','mm_delete_data_on_uninstall',
   'mm_api_disabled','mm_api_allowed_ips',
   'mm_upload_notify_enabled','mm_upload_notify_extra_email','mm_failed_upload_notices');

-- Attachment metadata
DELETE FROM wp_postmeta WHERE meta_key IN
  ('mm_creator','mm_copyright','mm_owner','mm_headline','mm_credit','mm_keywords',
   'mm_date_created','mm_location_city','mm_location_state','mm_location_country',
   'mm_rating','mm_gps_lat','mm_gps_lon','mm_gps_alt','mm_meta_synced');
DELETE FROM wp_postmeta WHERE meta_key LIKE '_mm_compressed_%';
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

> This software is based in part on the work of the Independent JPEG Group.

---

### optipng
**Author:** Cosmin Truța
**License:** [zlib/libpng License](https://optipng.sourceforge.net/pngtech/optipng.html)
**Website:** <https://optipng.sourceforge.net>

`optipng` performs lossless PNG compression by trying multiple DEFLATE parameters and filter combinations to find the smallest lossless representation. No pixels are changed. We use it with `-o2 -preserve` to balance compression efficiency against processing time while preserving all file metadata.

---

### libwebp / cwebp
**Author:** Google
**License:** [BSD 3-Clause](https://chromium.googlesource.com/webm/libwebp/+/refs/heads/main/COPYING)
**Website:** <https://developers.google.com/speed/webp>
**Repository:** <https://chromium.googlesource.com/webm/libwebp>

`cwebp`, part of the libwebp package, compresses WebP images losslessly with `cwebp -lossless`. Files are only replaced if the result is smaller. libwebp is Google's reference implementation of the WebP format.

---

### FFmpeg
**Maintainers:** FFmpeg contributors
**License:** [LGPL v2.1+ / GPL v2+ depending on build configuration](https://ffmpeg.org/legal.html)
**Website:** <https://ffmpeg.org>
**Repository:** <https://git.ffmpeg.org/ffmpeg.git>

`ffmpeg` is used by the compression daemon to remux video containers, stripping padding and redundant data without re-encoding. The output is bitstream-identical to the input — no quality change, no transcoding. We invoke it with `-c copy` to ensure a strict copy-only remux.

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

## Sponsorship

Metamanager is free and open source. If it saves you time or adds value to your work, consider supporting its continued development:

**[❤ Sponsor on GitHub →](https://github.com/sponsors/richardkentgates)**

---

## License

GPLv3 or later. See [LICENSE](LICENSE).

Copyright © Richard Kent Gates
