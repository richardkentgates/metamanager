# Changelog

All notable changes to Metamanager are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [2.0.2] — 2026-03-14

### Fixed

- **Library Scan button never created jobs** — `ajax_scan_library()` called `MM_Metadata::import_from_file()` which invokes ExifTool via `shell_exec()`. On hosts where `shell_exec` is disabled this threw a fatal error that aborted the entire AJAX request before any job files were written. Removed the `import_from_file()` call entirely — the metadata daemon already reads embedded tags from the file when it processes the job, so the synchronous pre-read served no purpose.
- **Job writes silently failed on hosts with conflicting filesystem plugins** — `MM_Job_Queue::get_filesystem()` returned whatever the global `$wp_filesystem` object was, which third-party plugins (e.g. InMotion Hosting central-connect) can replace with a broken FTP object during the `init` hook. All `put_contents()` calls against an FTP stub silently returned false and `log_pending_job()` was never reached, leaving `wp_metamanager_jobs` permanently empty. `get_filesystem()` now always instantiates `WP_Filesystem_Direct` directly, ignoring the global.

---

## [2.0.1] — 2026-03-14

### Fixed

- **PHPStan static analysis** — added precise object shape types to `render_job_row()` and `get_jobs()` docblocks to resolve level-6 type errors. No runtime behaviour change.

### Documentation

- **`wp metamanager embed` CLI command** added to the WP-CLI reference on the project site.
- **`POST /attachment/{id}/embed` REST endpoint** added to the REST API reference table.

---

## [2.0.0] — 2026-03-14

### Added

- **`wp metamanager embed` CLI command** — queues metadata-embedding daemon jobs for one attachment or all supported media (`image`, `audio`, `video`, `PDF`). Accepts `--force` to bypass the already-completed guard.
- **`POST /metamanager/v1/attachment/{id}/embed` REST endpoint** — queues metadata-embedding jobs for a single attachment. Requires `edit_others_posts` (editor role). Returns `{ "queued": true, "id": <n> }` on success, 404 for unknown attachment, 422 for unsupported MIME type.
- **`job_trigger` field in all job rows** — every `write_job()` call now records how the job was created. Possible values: `upload`, `scan`, `cli`, `rest_api`, `thumbnail_regen`, `requeue`. Exposed in the `GET /jobs` and `GET /jobs/{id}` REST responses and visible in the Job Results admin table.
- **Integration test suite** — `Test_MM_CLI.php` (25 tests) and `Test_MM_REST.php` (21 tests) added to `phpunit.xml.dist`. Covers all CLI commands and all 7 REST endpoints including auth tiers, 404 / 422 handling, and the `job_trigger` field. 101 tests, 170 assertions, 0 failures.

### Fixed

- **`wp metamanager scan` never queued daemon jobs** — the CLI scan called `import_from_file()` but wrote no job files. `scan()` now mirrors the upload path exactly: images queue compression + metadata via `enqueue_all_sizes('both')`; video queues metadata + compression; audio/PDF queue metadata when `can_write_meta()` returns true. `job_trigger` is set to `'scan'`.

### Changed

- **Job Queue UI renamed** — "Job Queue" tab → **"Pending Jobs"**, "Job History" tab → **"Job Results"** throughout the admin interface and inline help. "Bulk Apply Metadata" → **"Batch Apply Metadata"** in the help panel.
- **BETA badge removed** — plugin description no longer carries the `[BETA — untested in production]` prefix.

---

## [1.6.2] — 2026-07-13

### Fixed

- **`ajax_scan_library()` never queued daemon jobs** — the Scan Existing Library tool called `import_from_file()` to bootstrap WordPress fields from embedded tags but did not write any job files to the daemon queue. Compression and metadata embedding were therefore never performed on scanned attachments. `ajax_scan_library()` now mirrors the upload path exactly: after `import_from_file()`, images queue both compression and metadata jobs via `enqueue_all_sizes('both')`; video queues a metadata job plus a compression job; audio and PDF queue a metadata job when `can_write_meta()` returns true. The `trigger` field in each job JSON is set to `'scan'` for log traceability.

---

## [1.6.1] — 2026-03-14

### Fixed
- **`metamanager-install.sh --update` was a no-op when run from the installed plugin directory** — the script resolved its own directory and detected `metamanager.php` beside it, so it copied files from itself rather than fetching from GitHub. Added a `realpath` comparison: if `SCRIPT_DIR` equals `PLUGIN_DEST` the local-copy shortcut is skipped and the GitHub fetch path runs instead. Both `sudo bash .../metamanager-install.sh --update` and the `wget` pipe form now always fetch the latest release from GitHub.
- **Automated job history management** — `MM_DB::log_job()` now upserts: any existing row for the same `(attachment_id, job_type, size)` is deleted before the new result is inserted, keeping exactly one history entry per image-per-operation-type. Added `MM_DB::delete_jobs_for_attachment()` hooked to WordPress `delete_attachment` so all history rows for a deleted media file are removed automatically.
- **Actions column and Clear History button removed** — manual row deletion and full-history clearing are superseded by automated history management above. The Actions column now only shows **Re-queue** for failed jobs.

---

## [1.6.0] — 2026-03-14

### Added
- **Media sitemaps** — two new XML sitemap endpoints served directly by WordPress rewrite rules:
  - `/sitemap-media.xml` — all Media Library images with `<image:image>` extension nodes; video attachments additionally include `<video:video>` nodes with title, description, duration, rating, and publication date.
  - `/sitemap-video.xml` — scans all published post content for embedded video. Covers self-hosted `<video>` tags, YouTube `<iframe>` embeds, and Vimeo `<iframe>` embeds. YouTube and Vimeo metadata is resolved via oEmbed and cached as a 24-hour transient.
- **Sitemap settings section** added to `Media → MM Settings` — six independent toggles (media sitemap, image nodes, video sitemap, YouTube, Vimeo, self-hosted) with live URLs linking directly to each sitemap endpoint.
- **Contextual help tab** on the MM Settings screen explaining both sitemap URLs, what each one contains, and step-by-step Google Search Console submission instructions.
- **`MM_Metadata::META_DURATION`** constant (`mm_duration`) — integer post meta populated by the metadata daemon via `ffprobe`; consumed by the video sitemap `<video:duration>` node.
- **Sitemap cleanup on uninstall** — all six sitemap options and all `mm_oembed_*` transients are removed when the plugin is deleted with data removal opted in.

---

## [1.5.5] — 2026-03-04

### Fixed
- **Vorbis Headline writes to XMP:Headline** — for OGG/FLAC files the Headline field was mapped to a Vorbis `TITLE` comment, silently overwriting the file's title. It now writes `XMP:Headline` instead, which ExifTool embeds in the XMP block within the container. No Vorbis standard exists for a HEADLINE field.
- **IPTC:Source written twice for images** — the image branch of the metadata daemon wrote `IPTC:Source` inline for the Publisher field and then the reconciliation block at the bottom wrote it again. The inline write is removed; the reconciliation block is now the sole writer. No behavioural change on clean files; eliminates a redundant ExifTool argument.
- **Website field incorrectly documented as writing IPTC:Source** — README.md and docs/index.html both showed `Source` in the IPTC column for the Website row of the Site Provenance mapping table. Website maps only to `XMP:WebStatement`; only Publisher maps to `IPTC:Source`. Documentation corrected.
- **`is_remux` dead flag removed** — `'is_remux' => true` was written into job JSON by five PHP call sites but was never read by the compression daemon (routing is by file extension). The key is removed from all `write_job()` calls.
- **"Import Metadata from Files" bulk action removed** — this bulk action ran `shell_exec exiftool` per selected file synchronously inside an HTTP request, making it vulnerable to PHP timeout on large selections. Replaced by directing users to the existing batched Library Scan tool (Media → Metamanager). The admin column tooltip for un-synced files is updated accordingly.
- **Library Scan re-queried full attachment count on every batch** — `ajax_scan_library()` ran `get_posts(numberposts=-1)` on every 50-file batch rather than once. The batch response now includes the total and the JS passes it back on subsequent calls, eliminating redundant database queries.
- **Daemon concurrency unbounded** — both daemons could spawn an unlimited number of background subshells when many job files arrived simultaneously. Both now respect `MAX_CONCURRENT=4` using a `wait -n` throttle loop before each `process_job &`.
- **Cron importer: silent data loss on DB insert failure** — `mm_import_completed_jobs()` deleted the result JSON file regardless of whether `MM_DB::log_job()` succeeded. `log_job()` now returns `bool` and the result file is only deleted after a confirmed insert; on failure an error is logged and the file is left in place for the next cron run.
- **`uninstall.php` incomplete cleanup** — the `mm_upload_batch` transient (used by the upload receipt batching system) and the `mm_send_upload_receipt` WP-Cron event were not cleared on uninstall. Both are now removed. The redundant explicit `_mm_compressed_full` entry in the meta key list is also removed (it was already covered by the `LIKE '_mm_compressed_%'` wildcard query directly below).

---

## [1.5.4] — 2026-03-03

### Fixed
- **`REST POST /attachment/{id}/compress` — inverted `force` flag** — `if ( ! $force )` cleared compression meta on every default call and did nothing when `force=true`. Corrected to `if ( $force )`, consistent with CLI's `--force` semantics.
- **`on_delete_attachment()` — incomplete meta cleanup** — only 3 of 15 custom meta keys were removed when an attachment was deleted. All 15 `MM_Metadata::META_*` constants are now removed.
- **Audio pane garbled em dash** — double UTF-8 encoding in `render_attachment_meta_pane()` displayed `â` instead of `—`.
- **`mm_deactivate()` — upload receipt cron not cleared** — `mm_send_upload_receipt` WP-Cron event was never cleaned up on plugin deactivation, leaving a dangling event if deactivated mid-batch.
- **`uninstall.php` — missing new options** — `mm_api_disabled`, `mm_api_allowed_ips`, `mm_upload_notify_enabled`, `mm_upload_notify_extra_email`, and `mm_failed_upload_notices` were not removed on clean uninstall.

---

## [1.5.3] — 2026-03-03

### Added
- **REST API access control** — new settings section in Media → MM Settings. The entire REST API (`/wp-json/metamanager/v1/*`) can be disabled outright, or restricted to a comma-separated list of allowed IPs. Requests from disallowed IPs receive a `403 Forbidden` response before any capability check is performed.
- **Upload receipt emails** — optional email notifications when media is uploaded. When enabled, the uploading user and the admin email address receive a batched digest once per 60-second window (rather than one email per file). An extra CC address can be specified in Settings. Failed send attempts are stored and surfaced in a dismissible admin notice with a one-click retry.

### Fixed
- **WordPress permission model alignment** — all capability checks have been updated to match what each operation actually does, rather than using a single broad `upload_files` gate throughout.
  - **Menu pages & admin pages** (`Metamanager` job dashboard, `Bulk Edit Metadata`): `upload_files` → `edit_others_posts`. Both pages display and operate on all media site-wide, which is an Editor-level concern.
  - **Bulk actions** (`handle_bulk_actions()`): `upload_files` → `edit_others_posts`. Compress Lossless, Inject Site Info, and Import Metadata act on the full media library, not just the current user's uploads.
  - **REST API split into two tiers:**
    - Read-only status endpoints (`POST /compression-status`, `GET /attachment/{id}/status`) keep `upload_files` — informational checks appropriate for any uploader.
    - Dashboard and write endpoints (`GET /jobs`, `GET /jobs/{id}`, `POST /attachment/{id}/compress`, `GET /stats`) now require `edit_others_posts` — they expose site-wide data or trigger write operations.
  - **AJAX handlers** (`ajax_jobs_refresh`, `ajax_requeue_job`, `ajax_scan_library`): `upload_files` → `edit_others_posts` for the same reason as the admin pages above.
  - **Per-attachment AJAX handlers** (`ajax_recompress`, `ajax_save_bulk_meta_row`): `upload_files` → `current_user_can('edit_post', $id)`. This is the correct WordPress ownership gate — Authors can act on their own files without being granted site-wide access.

---

## [1.5.2] — 2026-03-03

### Fixed
- **`MM_Status::mark_compressed()` never called** — `mm_import_completed_jobs()` logged completed compression jobs to the database but never called `mark_compressed()`, so the Media Library column always showed "Not Compressed" regardless of daemon output, and bulk-compress re-queued every file on every run. The cron handler now calls `MM_Status::mark_compressed()` for every successfully completed compression job.
- **Video compression status always `na`** — `MM_Status::compression_status()` used `wp_attachment_is_image()` to gate all logic, causing every non-image type (including ffmpeg-remuxed video) to return `'na'` / `—`. Videos now show ✔ Compressed or ✘ Not Compressed based on the `_mm_compressed_full` post-meta flag set by `mark_compressed()`.
- **Video re-queue guard missing in `do_bulk_compress()`** — the video branch of the bulk compress handler was unconditionally writing a new remux job on every bulk action run. It now checks `MM_Status::is_compressed()` before queueing, matching the guard already present for image sizes.
- **`requeue_source_id` key typo** — `MM_Job_Queue::requeue()` was writing the extra-data key `'re queue_source_id'` (space in the middle). Corrected to `'requeue_source_id'`.
- **Admin copy: "image(s)" → "media file(s)"** — bulk-action success notices (Compress Lossless, Inject Site Info, Import Metadata), Library Scan JS progress text, and the Job History table column header all referred to "image(s)" despite working on all supported media types.
- **Admin help tab: "filter by image name"** — Job History help tab search description now says "file name" to match the renamed "File" column.

---

## [1.5.1] — 2026-03-02

### Fixed
- **`uninstall.php` multisite logic** — the v1.5.0 release checked `mm_delete_data_on_uninstall` against the primary site's option before iterating blogs, meaning sites other than site 1 were cleaned up unconditionally. The option is now checked inside the `switch_to_blog()` loop so each site is only cleaned up when that site's admin opted in.
- **Settings section title double-encoding** — `'Data &amp; Uninstall'` passed to `esc_html__()` produced `Data &amp;amp; Uninstall` in the browser. Fixed to `'Data & Uninstall'`.
- **`is_plugin_active_for_network()` availability** — `mm_on_new_site()` and `mm_on_new_blog()` now guard the call with `function_exists()` + `require_once ABSPATH . 'wp-admin/includes/plugin.php'` so the function is always available regardless of the hook's execution context.
- **WP_Filesystem cron fallback** — if `WP_Filesystem()` cannot initialise (e.g. on FTP-only servers that require credentials), the cron handler now falls back to native `file_get_contents()` instead of silently skipping all result files.

### Changed
- **README.md** — Uninstall section rewritten with clear instructions for the admin UI route, the daemon removal steps, and a manual SQL cleanup block. Features list updated with multisite and clean-uninstall bullets.
- **Documentation website** — `softwareVersion` updated to 1.5.1; two new feature cards added: "Multisite Ready" and "Clean Uninstall".

---

## [1.5.0] — 2026-03-02

### Added
- **`uninstall.php`** — full cleanup routine triggered when the plugin is deleted from the Plugins screen. Removes all options, post meta (including wildcard `_mm_compressed_*` keys), the `metamanager_jobs` DB table, the `metamanager-jobs/` queue directory tree, and the updater transient. Data is only removed when the **Remove all data on uninstall** setting is enabled; by default nothing is deleted on uninstall.
- **"Data & Uninstall" settings section** — new `mm_delete_data_on_uninstall` option with a clearly worded warning rendered on the Settings page. Defaults to `false` (keep data).
- **Multisite support** — network-wide activation now iterates every site and creates the DB table + schedules cron per-blog. Deactivation clears the cron event on every site. New-site hooks (`wp_initialize_site` / `wpmu_new_blog`) create the table and schedule automatically when a new blog is added to a network where the plugin is network-activated.
- **`index.php` silence files** — added to all plugin directories (`/`, `includes/`, `assets/`, `assets/js/`, `daemons/`, `languages/`) to prevent directory listing.
- **`languages/` directory** — created to satisfy the declared `Domain Path` in the plugin header (required by Plugin Checker).

### Changed
- **`mm_activate()` / `mm_deactivate()`** — refactored to accept a `$network_wide` parameter and delegate per-site work to a new `mm_activate_single_site()` helper.
- **`MM_Job_Queue::ensure_dirs()` and `write_job()`** — replaced `file_put_contents()` with `WP_Filesystem::put_contents()` via a new private `get_filesystem()` helper. Eliminates the `file_system_operations_file_put_contents` PHPCS warning.
- **`mm_import_completed_jobs()`** — replaced `file_get_contents()` with `WP_Filesystem::get_contents()` and replaced `@unlink()` with `wp_delete_file()`. Both PHPCS violations resolved.
- **`MM_DB::drop_table()`** — new method used by `uninstall.php` to drop the jobs table.

---

## [1.4.1] — 2026-03-02

### Fixed
- **`wp metamanager compress`** — was querying `post_mime_type => 'image'` only; now queries all compressible types (images + video). Video attachments now enqueue a lossless `ffmpeg` remux job. Audio files and PDFs correctly emit a descriptive error (no compression step) rather than silently being skipped.
- **`wp metamanager import`** — was filtering to images only; now imports metadata for all supported MIME types: video, audio, and PDF in addition to images.
- **`wp metamanager scan`** — same image-only filter fixed; now scans the full library across all supported types and skips already-synced files of any type.
- **`REST POST /attachment/{id}/compress`** — was rejecting non-images with `400 not_image`; now accepts video (queues remux job) and returns `422 Unprocessable Entity` with an explanatory message for audio and PDF.

### Added
- **WP-CLI and REST API documentation** in README.md and docs/index.html — dedicated sections with command reference, options, example output, and a full REST endpoint table.

---

## [1.4.0] — 2026-03-02

### Added
- **PDF support** (`application/pdf`): PDFs are now scanned, have metadata imported from embedded XMP via ExifTool, and support XMP write-back on field save. Classified as `xmp_only` in `WRITE_CAPABILITY`
- **`MM_Metadata::PDF_MIME_TYPES`** constant and **`is_pdf_mime()`** helper method used throughout all classes
- **Schema.org `DigitalDocument`** (`MM_Frontend::output_pdf_json_ld()`): PDF attachment pages now emit structured data with name, description, creator, copyright, keywords, date, location, and `encodingFormat: application/pdf`
- **Open Graph for PDFs** (`MM_Frontend::output_pdf_open_graph()`): PDFs emit `og:type=article`, `og:title`, `og:description`, and `og:url` on their attachment pages
- **PDF-specific ExifTool tag candidates** in `MM_Metadata::import_from_file()`: `PDF:Title`, `PDF:Author`, `PDF:Keywords`, `PDF:CreateDate` are checked before falling back to XMP equivalents so the document information dictionary is preferred when present
- **`pdf` extension** added to the `xmp_only` case in `metamanager-meta-daemon.sh` so XMP-only tag writing applies to PDFs

### Changed
- **`MM_Frontend`** refactored to extract `build_schema_base()` — a single shared method building the common attribution/keywords/location/date schema fields used by ImageObject, VideoObject, AudioObject, and DigitalDocument. Eliminates the previous duplication between image and AV JSON-LD builders
- **`MM_Admin::render_attachment_meta_pane()`** major UI overhaul:
  - Type icon and label in the postbox header (dashicons for image, video, audio, PDF)
  - Action row uses WordPress native notice classes (`.notice.notice-info`, `.notice.notice-warning`)
  - **Stored Fields** summary table shows all MM-managed post_meta values grouped into Identity / Attribution / Editorial / Location sections, displayed before the raw ExifTool dump rather than jumbled with it; GPS shown as decimal lat/lon with altitude; star rating rendered using `★/☆` glyphs
  - Raw ExifTool dump is now **collapsible** (`<details>`) and grouped by tag namespace (File, EXIF, IPTC, XMP, GPS, QuickTime, ID3, Vorbis, ASF, Matroska, Composite, Colour Profile) each as their own nested collapsible group with a dashicon badge
  - Group prefixes are stripped from tag names inside each group table for readability
  - PDF branch added: shows document info notice and renders the same fields/dump structure
- **`MM_Admin::render_media_column()`**, **`do_bulk_import_meta()`**, **`do_bulk_inject_site_info()`**, and **`ajax_scan_library()`** all updated to include `application/pdf` alongside images and AV types
- **`MM_Metadata::register_fields()`** and **`on_fields_save()`** now accept PDF MIME type
- **`MM_Job_Queue::on_upload()`** now handles PDF: imports metadata on first upload and queues an XMP write-back job
- Plugin version bumped to `1.4.0`

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
- **`mm_ffmpeg` package** added to `metamanager-install.sh` for apt, dnf, and yum package managers; `ffmpeg` added to post-install tool verification loop

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
- `metamanager-install.sh` now installs the `webp` (apt) / `libwebp-tools` (dnf/yum) package and verifies `cwebp` availability after install

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
- `metamanager-install.sh --update` flag — updates plugin PHP/JS/asset files only without re-installing daemons, dependencies, or systemd services; flushes WordPress object cache via WP-CLI when available
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
- One-command `metamanager-install.sh` supporting apt (Debian/Ubuntu) and dnf (RHEL/Rocky)
- `metamanager-install.sh` patches daemon scripts at deploy time with the actual `WP_CONTENT_DIR` — no hardcoded paths
- WP-CLI activation support in `metamanager-install.sh` when WP-CLI is available
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
