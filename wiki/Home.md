> ⚠ **Beta software.** Functionality may be incomplete or subject to breaking change without notice. Use at your own risk. [Report issues →](https://github.com/richardkentgates/metamanager/issues)

# Metamanager

**Metamanager** is a WordPress plugin that provides lossless compression for images, video, and audio; bidirectional metadata sync between WordPress fields and embedded file tags (EXIF/IPTC/XMP, ID3, QuickTime atoms, Vorbis comments, and XMP); PDF metadata import; and automatic front-end Schema.org JSON-LD and Open Graph output for all media types — all powered by OS-level daemons and native WordPress APIs.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://php.net)
[![Status](https://img.shields.io/badge/status-beta-yellow)](https://github.com/richardkentgates/metamanager/releases)

---

## Why Metamanager

WordPress's built-in media handling is PHP-only. PHP cannot do lossless JPEG, PNG, or WebP compression, has no native video remux, and its metadata tools are limited to basic EXIF with no IPTC or XMP support. Metamanager offloads all media work to the OS where purpose-built tools — `jpegtran`, `optipng`, `cwebp`, `ffmpeg`, and `ExifTool` — do the job properly.

PHP's role is coordinator only: write the instruction, let the daemon execute it.

---

## How It Works

```
WordPress (PHP)                     OS (Bash daemons)
─────────────────                   ──────────────────────────────────────
Upload / scan / edit media file
       │
       ├── On upload or scan: import_from_file() reads embedded
       │   tags into empty WordPress fields — never overwrites.
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

## Features

**Compression**
- Lossless JPEG via `jpegtran` (no re-encoding, no pixel changes)
- Lossless PNG via `optipng`
- Lossless WebP via `cwebp -lossless`
- Video container remux via `ffmpeg -c copy` (no transcoding)

**Metadata**
- Bidirectional sync: import on upload or library scan, write-back on save
- 11 custom fields per file: Creator, Copyright, Owner, Headline, Credit, Keywords, Date Created, Rating, City, State/Province, Country
- GPS coordinates imported from camera EXIF automatically
- Images: EXIF + IPTC + XMP written simultaneously
- MP3: ID3 tags · MP4/MOV/M4A: QuickTime atoms · OGG/FLAC: Vorbis · AVI/WAV/WMV/WMA/PDF: XMP

**Front-end**
- Schema.org JSON-LD: `ImageObject`, `VideoObject`, `AudioObject`, `DigitalDocument`
- Open Graph tags per media type
- `<link rel="license">` for URL-format copyright values
- XML media sitemap (`/sitemap-media.xml`) — images and video with extension nodes
- XML video sitemap (`/sitemap-video.xml`) — self-hosted, YouTube, and Vimeo embeds

**WordPress Integration**
- Native dashboard fields, job dashboard, Media Library column
- WP-CLI command group: `wp metamanager compress|import|scan|queue|stats`
- Full REST API under `metamanager/v1`
- REST API access control: disable or restrict to an IP allowlist
- Upload receipt emails (batched, 60-second window)
- Native auto-updates via GitHub releases
- Multisite compatible
- Clean uninstall with opt-in data removal

---

## Wiki Pages

| Page | Contents |
|------|----------|
| [[Installation]] | Requirements, quick install, manual install, updating |
| [[Configuration]] | Settings: compression, REST API, upload notifications, uninstall |
| [[Metadata Fields]] | Full tag mapping tables for every supported file type |
| [[Schema and Open Graph]] | JSON-LD output, Open Graph tags, license link |
| [[Media Sitemaps]] | `/sitemap-media.xml` and `/sitemap-video.xml` — settings, field mapping, Google Search Console |
| [[WP-CLI]] | All five commands with flags and examples |
| [[REST API]] | All endpoints, parameters, authentication, access control |
| [[Daemon Management]] | Status, logs, restart, service unit details |
| [[Uninstall]] | Admin route, daemon removal, manual SQL cleanup |
| [[Credits]] | Open source tools and licenses |
| [[Contributing]] | Local dev setup, running PHPStan, CI jobs, submitting changes |
