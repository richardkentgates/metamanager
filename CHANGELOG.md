# Changelog

All notable changes to Metamanager are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.3.0] — 2026-03-02

### Added
- **Video metadata import** (MP4, MOV, AVI, MKV, WMV, WebM, OGV, 3GP): ExifTool is now invoked for video attachments on upload; QuickTime, ID3, Vorbis, ASF, and Matroska container-native tags are preferred before falling back to XMP equivalents. All existing post-meta fields (creator, copyright, headline, credit, keywords, date, city, country, GPS) are populated
- **Audio metadata import** (MP3, M4A, OGG, WAV, FLAC, WMA, AIFF): same pipeline as video using ID3/iTunes/Vorbis/Vorbis comment tag candidates
- **Write-capability system** (`MM_Metadata::WRITE_CAPABILITY`, `write_capability()`, `can_write_meta()`): each MIME type is classified as `full`, `xmp_only`, `vorbis_only`, or `read_only`. Write-back is skipped for read-only formats (MKV, WebM, OGV). A coloured notice is shown in the attachment edit screen and field registration for limited-write formats
- **Video container remux** via `ffmpeg -c copy -map_metadata 0 -movflags +faststart`: queued for all video uploads and via the "Re-remux This Video" button; embedded thumbnails are explicitly preserved (`-map_metadata 0`). The daemon skips remux gracefully when ffmpeg is not installed rather than logging a failure
- **ffmpeg tool detection** (`MM_Status::ffmpeg_available()`, `ffmpeg_path()`): shown in the system health status banner alongside existing tools
- **`MM_Metadata::is_video_mime()`, `is_audio_mime()`, `is_av_mime()`** MIME type helper methods used throughout all classes
- **Video/audio support in Library Sync** (batched scan): `ajax_scan_library()` now queries `post_mime_type` including all supported video and audio MIME types alongside images
- **Video/audio support in bulk actions**: "Import Metadata from Files" and "Inject Site Info" now process video/audio attachments as well as images; "Compress Lossless" bulk action queues video remux jobs for video attachments
- **Schema.org VideoObject / AudioObject** (`MM_Frontend::output_video_audio_json_ld()`): video and audio attachment pages now emit structured data using the appropriate `@type` with name, description, creator, copyright, keywords, date, location, and GPS fields
- **Open Graph og:video / og:audio** (`MM_Frontend::output_video_audio_open_graph()`): video and audio attachment pages emit `og:video` or `og:audio` tags (with secure_url and type) instead of `og:image`
- **`mm_ffmpeg` package** added to `install.sh` for apt, dnf, and yum package managers; `ffmpeg` added to post-install tool verification loop

### Changed
- `MM_Job_Queue::on_upload()` now handles video and audio MIME types: imports metadata, queues metadata write-back (if format supports it), and queues video remux (video only)
- `MM_Metadata::register_fields()` and `on_fields_save()` now accept video/audio MIME types; `on_fields_save()` returns early without queueing jobs for `read_only` formats
- `MM_Admin::render_attachment_meta_pane()` now renders for video and audio attachments with the re-optimise button disabled or labelled appropriately for unsupported formats
- Plugin version bumped to `1.3.0`

---

## [1.2.0] — 2026-03-09

### Added
- **WebP lossless compression** via `cwebp -lossless -mt`: if an uploaded file is already a `.webp`, it is re-encoded losslessly; files are only replaced when the result is smaller than the original
- **cwebp tool detection** in `MM_Status::cwebp_available()` and `cwebp_path()`; cwebp status shown in the system health banner alongside jpegtran and optipng
- **Compression savings stats**: the compression daemon now records `bytes_before` and `bytes_after` for every JPEG, PNG, and WebP job; values are stored in two new DB columns and displayed in a "Savings" column on the Job History table (e.g. `−42.3 KB (18%)`)
- **`MM_DB::get_stats()`**: aggregate query returning total jobs, completed/failed counts, bytes saved, bytes original, and unique attachment count
- **Thumbnail regeneration detection** (`MM_Job_Queue::on_upload()`): the `wp_generate_attachment_metadata` hook now distinguishes between a fresh upload (no `mm_meta_synced` flag) and a thumbnail regeneration (flag already set). On regen, stale compression meta is cleared and only compression jobs are re-queued; metadata import is never re-run to protect user edits
- **Configurable compression optimisation level** (`mm_compress_level`, default 2): applied to optipng and cwebp; written into every job JSON as `optimize_level` for the daemon to consume
- **Settings page** (`Media → MM Settings`): configure compression level (1–7), enable/disable failure email notifications, and set the notification recipient email
- **Failure email notification** (`MM_Settings::get_notify_enabled()`): when any job fails, `mm_import_completed_jobs()` sends a single batched summary email (via `wp_mail`) listing all failures with image name, size slug, and daemon error reason; uses the configured recipient or falls back to the WordPress admin email
- **Individual re-compress button** on every image edit screen: "Re-compress This Image" clears existing compression flags for all sizes and queues fresh compression jobs; uses AJAX with inline status feedback
- **WP-CLI commands** (`class-mm-cli.php`):
  - `wp metamanager compress [<id>|all] [--force]` — queue lossless compression with a progress bar
  - `wp metamanager import [<id>|all]` — import embedded EXIF/IPTC/XMP metadata
  - `wp metamanager queue status` — show pending job counts and daemon liveness
  - `wp metamanager scan` — import metadata for every un-synced library image
  - `wp metamanager stats` — print compression savings summary table
- **Bulk Metadata Edit page** (`Media → Bulk Edit Metadata`): paginated table of all images with inline-editable fields for Headline, Credit, Keywords, Date Created, City, State, and Country; individual row Save buttons and a "Save All on This Page" button; each save queues a metadata embedding job
- **REST API** — five new authenticated endpoints under `metamanager/v1`:
  - `GET /jobs` — paginated, searchable, orderable job history (returns `X-WP-Total` / `X-WP-TotalPages` headers)
  - `GET /jobs/{id}` — single job row by DB ID
  - `GET /attachment/{id}/status` — compression + meta-sync status for one attachment
  - `POST /attachment/{id}/compress` — queue compression for one attachment (`force` param)
  - `GET /stats` — aggregate compression statistics
- **Batched Library Scan**: `ajax_scan_library()` now processes 50 images per HTTP request (`batch_size` param); JS chains calls automatically and renders a live progress bar; replaces the previous single unbounded `get_posts(-1)` call
- `install.sh` now installs the `webp` (apt) / `libwebp-tools` (dnf/yum) package and verifies `cwebp` availability after install

### Changed
- DB schema: `bytes_before BIGINT UNSIGNED` and `bytes_after BIGINT UNSIGNED` columns added via `dbDelta` (safe zero-downtime migration)
- `MM_DB::log_job()` now accepts and stores `bytes_before` / `bytes_after` from the result JSON
- `MM_Status::system_status()` now includes `cwebp` in the returned array
- PNG compression: optimisation level now reads `optimize_level` from the job JSON (was hardcoded `-o2`); bytes before/after are now captured for PNG jobs as they already were for JPEG
- JPEG compression: bytes_before/bytes_after now always written to the result JSON (was only logged in the message string); `new_size` equals `orig_size` when the file was already optimal
- `write_result()` in the compress daemon now accepts `bytes_before` and `bytes_after` positional arguments and embeds them as top-level JSON fields using `jq --argjson`

---

## [1.1.0] — 2026-03-02

### Added
- **Bidirectional metadata sync** (`MM_Metadata::import_from_file()`): on upload, embedded EXIF/IPTC/XMP tags are read from the image file via ExifTool and used to pre-populate WordPress fields; existing user-set values are never overwritten
- **Expanded metadata fields**: Headline, Credit, Keywords (semicolon-separated, multi-value IPTC/XMP), Date Created, Rating (0–5 stars / XMP:Rating), City, State/Province, Country — all stored via `register_post_meta()` for type safety, REST API exposure, and sanitisation
- **Class constants** `MM_Metadata::META_*` for all meta key names — replaces hardcoded strings throughout the codebase
- **GPS coordinates** (`mm_gps_lat`, `mm_gps_lon`, `mm_gps_alt`): automatically imported from `Composite:GPSLatitude/Longitude/Altitude` (ExifTool pre-computed signed decimal); validated against plausible coordinate and elevation ranges; read-only — not shown in the edit UI, not sent to the daemon
- **Schema.org `ImageObject` JSON-LD** (`MM_Frontend`): emitted in `wp_head` on attachment pages and single posts/pages with a featured image — includes name, description, caption, alternativeHeadline, headline, creditText, creator (Person), copyrightNotice, copyrightHolder (Organization), keywords[], dateCreated, locationCreated/contentLocation (Place), GeoCoordinates with latitude, longitude, and elevation when GPS data is present
- **Open Graph tags**: `og:image`, `og:image:secure_url`, `og:image:width`, `og:image:height`, `og:image:type`, `og:image:alt`
- **License link**: `<link rel="license">` when the copyright field contains a URL; `<meta name="copyright">` for plain-text copyright notices
- **Grouped attachment edit UI**: four labelled sections (Attribution & Rights, Editorial, Classification, Location); date picker (`<input type="date">`); 0–5 star rating `<select>`; inline tag hint on every field
- **`register_post_meta()` declarations** for all 14 custom attachment fields — provides type, sanitise callback, auth callback, and REST API visibility at `/wp/v2/media/<id>`
- `MM_Job_Queue::on_upload()` calls `import_from_file()` before enqueueing jobs so the job payload already contains imported values
- `add_action('init', ['MM_Metadata', 'register_meta'])` — meta registration now happens before any WP request
- Help tab metadata table in the admin screen expanded with all new field groups

### Changed
- Meta daemon (`metamanager-meta-daemon.sh`) updated with ExifTool tag mappings for Headline, Credit, Keywords (multi-value loop), DateCreated, Rating, City, State/Province, Country
- `get_fields_for_job()` now uses class constants and includes all new fields in the job payload

---

## [1.0.1] — 2026-03-02

### Added
- Native WordPress auto-updater (`MM_Updater`) — hooks into the core plugin-update pipeline so Metamanager appears in Dashboard → Updates automatically when a new GitHub release is published
- "Check for Updates" action link on the Plugins page for immediate on-demand update checks
- `install.sh --update` flag — updates plugin PHP/JS/asset files only without re-installing daemons, dependencies, or systemd services; flushes WordPress object cache via WP-CLI when available
- Contextual help tabs on the Media → Metamanager admin screen (via WordPress Screen API)
- Inline help section on the job dashboard with explanations of the queue, history, bulk actions, daemon statuses, and metadata field behaviour

### Changed
- README.md: added Updating section, auto-updates feature, and `--update` usage

---

## [1.0.0] — 2026-03-02

### Added
- Initial public release
- Lossless JPEG compression via `jpegtran -copy all -optimize -progressive`
- Lossless PNG compression via `optipng -o2 -preserve`
- Files are only replaced when the compressed result is smaller than the original
- Full EXIF, IPTC, and XMP metadata embedding via ExifTool in a single pass
- Custom metadata fields (Creator, Copyright, Owner) on every image edit screen
- Metadata fields are intentionally never set in bulk — per-image attribution only
- Bulk action: Compress Lossless — queues compression for all uncompressed sizes
- Bulk action: Inject Site Info — adds Publisher (site name) and Website (site URL) only; neutral provenance, not an ownership claim
- Real-time compression status column in the WordPress Media Library
- Embedded metadata read-out pane on the single image edit screen (live ExifTool readout)
- Metamanager admin page under Media → Metamanager with live job queue and searchable/paginated history
- Re-queue button on any failed job in the history table
- Clear History button (admin-only)
- Daemon health indicator in status banner using PID files — no `systemctl` privilege required
- Two systemd daemons watching job queue directories with `inotifywait`
- Result JSON written to `completed/` or `failed/` directories by daemons
- WP-Cron imports daemon results into the database every 60 seconds
- One-command `install.sh` supporting apt (Debian/Ubuntu) and dnf (RHEL/Rocky)
- `install.sh` patches daemon scripts at deploy time with the actual `WP_CONTENT_DIR` — no hardcoded paths
- WP-CLI activation support in `install.sh` when WP-CLI is available
- GitHub Pages documentation website at `metamanager.richardkentgates.com`
- GPLv3 license
- PHP 8.0+ minimum requirement
- WordPress 6.0+ minimum requirement

---

## Upcoming

### Possible future improvements
- Lossy compression option with configurable quality target (separate from lossless jobs)
- AVIF output support (`avifenc`)
- Per-attachment compression stats widget in the Media Library list view
- WP-CLI `wp metamanager export` command to download job history as CSV
