# Metamanager — Architecture Reference

This document describes the internal design of the Metamanager plugin: how its components fit together, why each decision was made, and where to look when adding or changing behaviour.

---

## Design Principles

| Principle | In practice |
|-----------|-------------|
| **PHP coordinates, OS executes** | PHP never calls `shell_exec()`, `exec()`, or any other subprocess API. All media work is delegated to OS-level daemons via JSON job files. |
| **No blocking in the request path** | Upload hooks and save hooks only write a small JSON file. They never wait for a daemon result — that happens asynchronously via WP-Cron. |
| **One source of truth per concern** | `MM_DB` owns the schema and all SQL. `MM_Metadata` owns all field names and sync logic. `MM_Job_Queue` owns all filesystem I/O for jobs. Nothing else writes job files or query the jobs table directly. |
| **Safe defaults** | Data is never deleted on uninstall unless explicitly opted in. Attribution fields (Creator, Copyright, Owner) are never set by bulk actions. Existing user-set values are never overwritten during import. |
| **Minimal WordPress coupling** | Classes are plain PHP. They use WordPress APIs (wpdb, Options, Post Meta, WP-Cron, REST, WP-CLI) but have no hard dependency on plugin bootstrap order. |

---

## Repository Layout

```
metamanager/
├── metamanager.php           Plugin bootstrap, constants, activation/deactivation hooks
├── uninstall.php             Opt-in data removal on plugin deletion
├── metamanager-install.sh    Server installer: installs OS deps, systemd daemons, activates plugin
│
├── includes/
│   ├── class-mm-db.php           Database table schema, query layer, deduplication migration
│   ├── class-mm-job-queue.php    Job file write/read/cleanup (filesystem only — no DB)
│   ├── class-mm-metadata.php     Field constants, WP field sync, daemon job payload builder
│   ├── class-mm-admin.php        All wp-admin integration: columns, panes, bulk, dashboard, help tabs
│   ├── class-mm-frontend.php     wp_head output: Schema.org JSON-LD, Open Graph, license link
│   ├── class-mm-settings.php     Settings page registration and rendering (Media → MM Settings)
│   ├── class-mm-sitemap.php      XML sitemap endpoints (/sitemap-media.xml, /sitemap-video.xml)
│   ├── class-mm-status.php       Dependency detection (ExifTool, jpegtran, optipng, cwebp, ffmpeg, daemons)
│   ├── class-mm-upload-notify.php  Upload receipt emails with 60-second batching and retry
│   ├── class-mm-updater.php      Native WordPress update pipeline integration (GitHub releases)
│   └── class-mm-cli.php          WP-CLI command group: compress, embed, import, queue, scan, stats
│
├── daemons/
│   ├── metamanager-compress-daemon.sh    Bash: inotifywait loop — jpegtran/optipng/cwebp/ffmpeg
│   ├── metamanager-meta-daemon.sh        Bash: inotifywait loop — ExifTool read/write
│   ├── metamanager-compress-daemon.service   systemd unit template
│   └── metamanager-meta-daemon.service       systemd unit template
│
├── assets/
│   └── js/mm-status.js           Frontend JS for live Media Library column polling
│
├── docs/                         GitHub Pages documentation website
└── languages/                    .pot translation template
```

---

## Plugin Bootstrap (`metamanager.php`)

### Constants

| Constant | Value | Purpose |
|----------|-------|---------|
| `MM_VERSION` | `'2.1.4'` | Displayed in help sidebars; used by the updater |
| `MM_PLUGIN_FILE` | `__FILE__` | Passed to activation/deactivation hooks |
| `MM_PLUGIN_DIR` | `plugin_dir_path(__FILE__)` | Absolute filesystem path |
| `MM_PLUGIN_URL` | `plugin_dir_url(__FILE__)` | URL for enqueuing assets |
| `MM_JOB_ROOT` | `WP_CONTENT_DIR . '/metamanager-jobs'` | Parent of all queue directories |
| `MM_JOB_COMPRESS` | `MM_JOB_ROOT . '/compress/'` | Incoming compression job files |
| `MM_JOB_META` | `MM_JOB_ROOT . '/meta/'` | Incoming metadata job files |
| `MM_JOB_DONE` | `MM_JOB_ROOT . '/completed/'` | Daemon-written result files |
| `MM_JOB_FAILED` | `MM_JOB_ROOT . '/failed/'` | Daemon-written failure files |
| `MM_JOB_TABLE` | `'metamanager_jobs'` | DB table name without prefix |
| `MM_PID_COMPRESS` | `MM_JOB_ROOT . '/compress-daemon.pid'` | Written by compress daemon on start |
| `MM_PID_META` | `MM_JOB_ROOT . '/meta-daemon.pid'` | Written by meta daemon on start |

### Bootstrap sequence

1. Define constants.
2. `require_once` all class files (no autoloader — simpler, no Composer dependency at runtime).
3. Register `plugins_loaded` hooks for `MM_Admin::init()`, `MM_Settings::init()`, `MM_Upload_Notify::init()`.
4. Register `rest_api_init` hook for REST routes unconditionally (REST requests are not `is_admin()`).
5. Register `admin_init` hook for `MM_DB::create_or_update_table()` (safe to run on every request — `dbDelta` is a no-op when no schema changes are needed).
6. Register WP-Cron intervals and the `mm_import_completed_jobs` event handler.
7. Register `delete_attachment` hook for `MM_DB::delete_jobs_for_attachment()`.
8. `MM_Frontend::init()` only when `!is_admin()`.
9. `MM_Sitemap::init()` unconditionally (rewrites must register on every request).
10. Register activation/deactivation hooks.

---

## Data Flow

### Upload path

```
add_attachment (WP hook)
        │
        ├─ MM_Upload_Notify::on_attachment_added()  ── appends to 60-second batch transient
        │
        └─ MM_Metadata::on_attachment_upload()
                │
                ├─ MM_Job_Queue::enqueue_import_job()
                │       └─ writes  meta/{uuid}.json  { job_type: "import", ... }
                │
                └─ MM_Job_Queue::enqueue_compress_job()
                        └─ writes  compress/{uuid}.json  { job_type: "compression", ... }
```

### Daemon processing (compress path)

```
compress/{uuid}.json  written
        │
        inotifywait (IN_CLOSE_WRITE)
        │
        mv → compress/{uuid}.json.processing
        │
        jpegtran / optipng / cwebp / ffmpeg
        │
        write  completed/{uuid}.json  { status, bytes_before, bytes_after, ... }
   OR   write  failed/{uuid}.json
        │
        rm compress/{uuid}.json.processing
```

### Daemon processing (meta path)

```
meta/{uuid}.json  written
        │
        inotifywait (IN_CLOSE_WRITE)
        │
        mv → meta/{uuid}.json.processing
        │
        job_type == "import":
            ExifTool reads embedded tags → JSON stdout → write to completed/
        job_type == "metadata":
            ExifTool writes WP field values back to the file → write to completed/
        │
        rm meta/{uuid}.json.processing
```

### WP-Cron result import (runs every 60 seconds)

```
mm_import_completed_jobs  (WP-Cron, every 60s)
        │
        MM_Job_Queue::import_completed_jobs()
        │
        for each file in completed/ and failed/:
          │
          ├─ job_type == "import":
          │     MM_Metadata::apply_imported_tags($attachment_id, $tags)
          │       → populate empty WP post meta + native fields
          │       → queue a "metadata" write-back job for the daemon
          │
          ├─ job_type == "compression":
          │     MM_DB::log_job($result)
          │
          └─ job_type == "metadata":
                MM_DB::log_job($result)
        │
        delete processed result file
```

---

## Database

### Table: `{prefix}metamanager_jobs`

```sql
CREATE TABLE wp_metamanager_jobs (
    id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    attachment_id  BIGINT(20) UNSIGNED NOT NULL,
    image_name     VARCHAR(255) NOT NULL DEFAULT '',
    job_type       VARCHAR(32)  NOT NULL DEFAULT '',       -- 'import', 'compression', 'metadata'
    job_trigger    VARCHAR(64)  NOT NULL DEFAULT '',       -- 'upload', 'edit', 'scan', 'batch', 'cli'
    file_path      TEXT         NOT NULL,
    size           VARCHAR(64)  NOT NULL DEFAULT '',       -- WP size slug ('full', 'thumbnail', …)
    dimensions     VARCHAR(32)  NOT NULL DEFAULT '',
    bytes_before   BIGINT(20) UNSIGNED DEFAULT NULL,
    bytes_after    BIGINT(20) UNSIGNED DEFAULT NULL,
    status         VARCHAR(32)  NOT NULL DEFAULT 'pending', -- 'pending', 'completed', 'failed'
    submitted_at   DATETIME     NOT NULL,
    completed_at   DATETIME     DEFAULT NULL,
    details        LONGTEXT     DEFAULT NULL,               -- JSON blob: error messages, tag values, etc.
    PRIMARY KEY (id),
    UNIQUE KEY uniq_job (attachment_id, job_type, size),
    KEY idx_attachment (attachment_id),
    KEY idx_job_type   (job_type),
    KEY idx_status     (status)
);
```

**Key constraints:**
- `UNIQUE KEY uniq_job` — one row per `(attachment_id, job_type, size)` triple. `REPLACE INTO` is used for upserts, so a re-queue or re-compress atomically replaces the prior result.
- `status = 'pending'` rows are written by `MM_DB::log_pending_job()` when a job file is queued. They transition to `'completed'` or `'failed'` when the cron result import runs.

### Core query methods (`MM_DB`)

| Method | Purpose |
|--------|---------|
| `create_or_update_table()` | `dbDelta` schema migration — safe on every `admin_init` |
| `log_job(array)` | `REPLACE INTO` — insert or update a completed/failed row |
| `log_pending_job(array)` | Insert a `status='pending'` row when a job file is written |
| `get_jobs(array)` | Paginated, searchable job history with status filter |
| `get_job(int)` | Fetch single row by primary key (for re-queue) |
| `get_pending_job_types(int)` | `SELECT DISTINCT job_type WHERE status='pending'` for one attachment |
| `has_any_completed_job(int)` | Whether this attachment has ever had a completed job |
| `has_any_completed_metadata(int)` | Whether a completed metadata job exists (shows edit fields) |
| `has_completed_compression(int)` | Whether a completed compression job for the full-size exists |
| `delete_jobs_for_attachment(int)` | Remove all rows for an attachment on `delete_attachment` |

---

## Job Queue (`MM_Job_Queue`)

Job files are plain JSON written to subdirectories of `wp-content/metamanager-jobs/`. PHP uses `WP_Filesystem_Direct` exclusively for job writes — bypassing the global `WP_Filesystem` instance — so FTP/SSH FS configurations set by other plugins cannot silently break job delivery.

### Job file format

**Compression job** (`compress/{uuid}.json`):
```json
{
  "attachment_id": 42,
  "image_name": "photo.jpg",
  "job_type": "compression",
  "job_trigger": "upload",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full",
  "dimensions": "3000x2000"
}
```

**Metadata import job** (`meta/{uuid}.json`):
```json
{
  "attachment_id": 42,
  "image_name": "photo.jpg",
  "job_type": "import",
  "job_trigger": "upload",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full"
}
```

**Metadata write-back job** (`meta/{uuid}.json`):
```json
{
  "attachment_id": 42,
  "image_name": "photo.jpg",
  "job_type": "metadata",
  "job_trigger": "edit",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full",
  "fields": {
    "title": "Sunrise over the ridge",
    "creator": "Jane Doe",
    "copyright": "© 2026 Jane Doe",
    "keywords": "landscape; sunrise; nature"
  }
}
```

### Deduplication

| Job type | Behaviour |
|----------|-----------|
| `compression` | If an unclaimed `.json` already exists for the same `(attachment_id, size)`, the new write is suppressed and a "duplicate compression" admin notice is queued. |
| `metadata` | If an unclaimed `.json` already exists, the new job is written anyway (they run in sequence). A "queued behind existing job" admin notice is shown. |

### Directory protection

`.htaccess` files with `Deny from all` are written to all four queue directories on activation (and checked defensively before each job write) to prevent direct HTTP access to job files.

---

## Metadata (`MM_Metadata`)

### Custom post meta keys

| Constant | Key | Type | Description |
|----------|-----|------|-------------|
| `META_CREATOR` | `mm_creator` | string | EXIF Artist / IPTC By-line / XMP Creator |
| `META_COPYRIGHT` | `mm_copyright` | string | EXIF Copyright / IPTC CopyrightNotice / XMP Rights |
| `META_OWNER` | `mm_owner` | string | EXIF OwnerName / XMP Owner |
| `META_HEADLINE` | `mm_headline` | string | IPTC Headline / XMP Headline |
| `META_CREDIT` | `mm_credit` | string | IPTC Credit / XMP Credit |
| `META_KEYWORDS` | `mm_keywords` | string | IPTC Keywords / XMP Subject (semicolon-separated) |
| `META_DATE` | `mm_date_created` | string | EXIF DateTimeOriginal / IPTC DateCreated (YYYY-MM-DD) |
| `META_CITY` | `mm_location_city` | string | IPTC City / XMP City |
| `META_STATE` | `mm_location_state` | string | IPTC Province-State / XMP State |
| `META_COUNTRY` | `mm_location_country` | string | IPTC Country-PrimaryLocationName / XMP Country |
| `META_RATING` | `mm_rating` | integer | XMP Rating (0–5) |
| `META_GPS_LAT` | `mm_gps_lat` | number | Composite:GPSLatitude — read-only |
| `META_GPS_LON` | `mm_gps_lon` | number | Composite:GPSLongitude — read-only |
| `META_GPS_ALT` | `mm_gps_alt` | number | Composite:GPSAltitude (metres) — read-only |
| `META_DURATION` | `mm_duration` | integer | Duration in seconds (video/audio) — read-only |
| `META_SYNCED` | `mm_meta_synced` | string | `'1'` once ExifTool has run — used to skip scan |

All keys are registered via `register_post_meta()` with sanitise callbacks and `show_in_rest: true`.

### Import rule

`apply_imported_tags()` only populates **empty** fields. If a WordPress field already has a value (set by a prior user action or a previous import), it is never overwritten. This invariant is enforced at the PHP level before any DB write.

### Attribution rule

`Creator`, `Copyright`, and `Owner` are **never** set by any bulk action. The "Inject Site Info" bulk action only writes `Publisher` (site name) and `Website` (site URL) to the neutral IPTC `Source` / XMP `WebStatement` fields.

---

## Admin (`MM_Admin`)

### Registered hooks

| Hook | Method | Purpose |
|------|--------|---------|
| `admin_menu` | `add_menu()` | Registers "Metamanager" and "Batch Metadata" sub-menus under Media |
| `admin_notices` | `status_banner()` | Daemon health + missing-tool warnings at top of relevant screens |
| `manage_upload_columns` | `add_media_column()` | Adds `mm_meta_sync` column to Media Library |
| `manage_media_custom_column` | `render_media_column()` | Outputs compression/metadata status cell |
| `edit_form_after_title` | `render_attachment_meta_pane()` | Metadata fields + pending/compression notice on attachment edit |
| `bulk_actions-upload` | `register_bulk_actions()` | Adds "Compress Lossless" and "Inject Site Info" |
| `handle_bulk_actions-upload` | `handle_bulk_actions()` | Processes selected attachments |
| `admin_enqueue_scripts` | `enqueue_assets()` | Enqueues `mm-status.js` and inline REST nonce |
| `wp_ajax_mm_jobs_refresh` | `ajax_jobs_refresh()` | Returns live queue JSON for the dashboard |
| `wp_ajax_mm_requeue_job` | `ajax_requeue_job()` | Re-writes the job file for a failed history row |
| `wp_ajax_mm_scan_library` | `ajax_scan_library()` | Queues import jobs for all un-synced attachments |
| `wp_ajax_mm_recompress` | `ajax_recompress()` | Re-queues compression for a single attachment |
| `wp_ajax_mm_apply_bulk_meta` | `ajax_apply_bulk_meta()` | Applies batch metadata fields to multiple attachments |
| `current_screen` | `add_help_tabs()` | Contextual help on Jobs, Media Library, and Settings screens |

### Media Library column (`mm_meta_sync`)

The column renders one of four states in priority order:

1. **Pending** (amber `dashicons-clock`) — `MM_DB::get_pending_job_types()` returns non-empty array. Tooltip lists queued job types.
2. **Metadata synced** (green `dashicons-yes-alt`) — a completed metadata job exists.
3. **Compression complete** (green `dashicons-yes-alt`) — a completed compression job exists but no metadata job.
4. **Never processed** (amber `dashicons-warning`) — no completed jobs.

The column cell also carries `data-attachment-id` so `mm-status.js` can poll the REST endpoint and update the cell live every 10 seconds without a page reload.

---

## Frontend (`MM_Frontend`)

Fires on `wp_head` for:
- Attachment pages (`is_attachment()`)
- Single posts/pages that have a featured image (`is_singular() && has_post_thumbnail()`)

### Output per media type

| MIME family | Schema.org type | Open Graph |
|-------------|-----------------|------------|
| Image (JPEG/PNG/WebP/GIF/TIFF) | `ImageObject` with optional `GeoCoordinates` | `og:image` + width/height/type/alt |
| Video | `VideoObject` | `og:video` + type |
| Audio | `AudioObject` | `og:audio` + type |
| PDF | `DigitalDocument` | `og:type=article` |

All types also emit one of:
- `<link rel="license" href="…">` when the copyright field is a URL
- `<meta name="copyright" content="…">` for plain-text notices

---

## Settings (`MM_Settings`)

All options are registered with `register_setting()` and sanitize callbacks. The settings page is at **Media → MM Settings**.

| Option key | Type | Default | Description |
|------------|------|---------|-------------|
| `mm_compress_level` | int | 2 | PNG/WebP optimisation effort (1–7). JPEG is always maximum lossless. |
| `mm_notify_enabled` | bool | false | Legacy job-failure notification (not upload receipts). |
| `mm_notify_email` | string | admin email | Recipient for job-failure notifications. |
| `mm_delete_data_on_uninstall` | bool | false | Wipe all data when plugin is deleted. |
| `mm_api_disabled` | bool | false | Return `403` for all unauthenticated REST requests. |
| `mm_api_allowed_ips` | string | `''` | Newline/comma IP allowlist for unauthenticated REST access. |
| `mm_upload_notify_extra_email` | string | `''` | Extra CC address for upload receipt emails. |
| `mm_upload_receipt` *(user meta)* | bool | true | Per-user upload receipt preference (stored in user meta, not options). |

Sitemap options (`mm_sitemap_*`) are registered by `MM_Sitemap::register_settings()`.

---

## Upload Receipts (`MM_Upload_Notify`)

### Batching model

1. `add_attachment` hook fires → `on_attachment_added()` appends `[user_id, attachment_id]` to `mm_upload_batch` transient and schedules a one-time `mm_send_upload_receipt` cron event 60 seconds in the future (only if not already scheduled).
2. The cron event fires → `send_batch()` reads and clears the transient, groups attachments by uploader, then sends one email per group.
3. The admin always receives a receipt regardless of any setting.
4. Non-admin uploaders receive a receipt only if their `mm_upload_receipt` user meta is truthy (default `true`).
5. If `wp_mail()` fails, the batch is persisted to `mm_failed_upload_notices` and a dismissible admin notice with a one-click **Retry** button is shown.

---

## Sitemaps (`MM_Sitemap`)

Two rewrite rules are registered on activation:
- `sitemap-media\.xml$` → `index.php?mm_sitemap=media`
- `sitemap-video\.xml$` → `index.php?mm_sitemap=video`

`template_redirect` intercepts these query vars and outputs XML directly, suppressing the theme template. Both sitemaps are generated fresh on every request (no static caching).

---

## REST API

Routes are registered under `metamanager/v1`:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/status` | Live compression/metadata status for one or more attachment IDs |
| `GET` | `/jobs` | Paginated, filterable job history |
| `POST` | `/requeue/{id}` | Re-queue a failed job by DB row ID |
| `POST` | `/compress/{id}` | Queue compression for a single attachment |

Access control runs before capability checks:
1. If `mm_api_disabled` is set → `403` immediately.
2. If `mm_api_allowed_ips` is non-empty and the request IP is not in the list → `403`.
3. Standard `edit_posts` capability check for write endpoints.

Logged-in requests (valid nonce or cookie) bypass the IP allowlist entirely.

---

## Auto-Updater (`MM_Updater`)

Queries `https://api.github.com/repos/richardkentgates/metamanager/releases/latest` and caches the result as a transient for 12 hours. The result is injected into the WordPress transients that power the `Dashboard → Updates` screen using the `pre_set_site_transient_update_plugins` filter. Download URL points to the release ZIP asset on GitHub.

A "Check for Updates" inline action on the Plugins page forces an immediate cache flush.

---

## OS Daemons

### Common structure

Both shell scripts follow the same pattern:

```bash
# 1. Write PID to ${JOB_ROOT}/<name>.pid
echo $$ > "${PID_FILE}"
trap 'rm -f "${PID_FILE}"' EXIT

# 2. inotifywait loop
inotifywait -m -e close_write "${JOB_DIR}" | while read ...; do
    # rename to .processing (atomic ownership claim)
    mv "${file}" "${file}.processing"
    # do the work
    # write result to completed/ or failed/
    rm "${file}.processing"
done
```

The `.processing` extension rename is an atomic ownership claim: if two daemon instances are ever started simultaneously, only one will succeed in renaming the file.

### PID file

Both daemons write a PID file to `wp-content/metamanager-jobs/` on startup and remove it on exit (via `trap EXIT`). PHP reads the PID file and checks `/proc/<pid>` to confirm the process is alive — no `systemctl` privileges required.

### systemd hardening

Both service units include:
- `NoNewPrivileges=true`
- `ProtectSystem=strict`
- `ReadWritePaths=<WP_CONTENT_DIR>/metamanager-jobs`
- `User=<web-server-user>` (patched at install time, never hardcoded)

---

## WP-CLI (`MM_CLI`)

The `wp metamanager` command group provides:

| Subcommand | What it does |
|------------|-------------|
| `compress [id\|all] [--force]` | Queue lossless compression jobs |
| `embed [id\|all] [--force]` | Queue metadata write-back jobs (WP fields → file) |
| `import [id\|all]` | Queue metadata import jobs (file → WP fields) |
| `scan` | Import+embed for all un-synced library files |
| `queue status` | Print live pending queue statistics |
| `stats` | Print compression savings statistics |

---

## Multisite

- `register_activation_hook` with `$network_wide = true` iterates all sites and calls `mm_activate_single_site()` on each.
- `wp_initialize_site` hook creates table and cron on new blog creation.
- Each site has its own `{prefix}metamanager_jobs` table.
- Settings are per-site (`get_option` / `update_option` — not network-wide).
- The job queue directory (`wp-content/metamanager-jobs/`) is shared across all sites on the same WordPress installation (single `WP_CONTENT_DIR`).

---

## Security

| Surface | Control |
|---------|---------|
| Job file HTTP access | `.htaccess Deny from all` in every queue directory |
| REST endpoints | IP allowlist + `mm_api_disabled` toggle before WordPress capability checks |
| SQL queries | All user input passed through `$wpdb->prepare()` or `sanitize_*` helpers |
| File writes | `WP_Filesystem_Direct` — no shell invocation; paths validated with `realpath()` before use |
| Nonces | All AJAX handlers verify `check_ajax_referer()` before any state change |
| Capability checks | Write operations require `edit_others_posts` (Editor+); per-attachment actions require `edit_post` (owner or Editor+) |
| Upload receipt preference | Saved via `update_user_meta()` with `current_user_can('edit_user')` check |
| Uninstall | Opt-in only; `uninstall.php` performs the wipe, not a deactivation hook |
