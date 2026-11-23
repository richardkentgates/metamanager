# Metamanager Job Queue Specification

This document is THE contract between the WordPress plugin (PHP, producer) and
the OS daemons (Bash, consumers). An identical copy lives in both repos
(`metamanager/JOB_QUEUE_SPEC.md` and `metamanager-plugin/JOB_QUEUE_SPEC.md`) —
it is duplicated by design and must stay in sync. Changes require updates to
both sides.

Verified against code 2026-11-16. Producer: `MM_Job_Queue::write_job()`
(`metamanager-plugin/includes/class-mm-job-queue.php`). Consumers:
`metamanager/daemons/metamanager-compress-daemon.sh`,
`metamanager/daemons/metamanager-meta-daemon.sh`, and
`metamanager/daemons/daemon-common.sh` (shared protocol functions).

---

## Directory Structure

`MM_JOB_ROOT` = `WP_CONTENT_DIR/metamanager-jobs/` (plugin constant,
`metamanager.php`; the daemons carry the same path patched in by
`metamanager-install.sh` at install time).

```
WP_CONTENT_DIR/metamanager-jobs/
├── compress/              # Compression job queue (plugin writes, compress daemon reads)
│   ├── *.json             # Pending jobs
│   └── *.json.processing  # Claimed jobs (daemon-locked)
├── meta/                  # Metadata job queue (plugin writes, meta daemon reads)
│   ├── *.json             # Pending jobs (job_type "metadata" or "import")
│   └── *.json.processing  # Claimed jobs (daemon-locked)
├── completed/             # Completed results (daemons write, plugin reads)
│   └── <job-name>.json-result.json
├── failed/                # Failed results (daemons write, plugin reads)
│   └── <job-name>.json-result.json
├── compress-daemon.pid    # PID file, compress daemon
├── meta-daemon.pid        # PID file, meta daemon
├── compress-status.json   # Daemon-written status (see Daemon Status Files)
└── meta-status.json       # Daemon-written status (see Daemon Status Files)
```

Each queue directory also contains an `.htaccess` with `Deny from all`, written
by the plugin on creation.

The plugin additionally writes its own `metamanager-status.json` into
`MM_JOB_ROOT` (read by the plugin/daemon status checks). It is not part of the
job/result protocol described here.

---

## Job Files

Written by the plugin as one JSON object per file. Filename pattern
(`MM_Job_Queue::write_job()`):

```
<attachment_id>-<size>-<unix_time>-<6_char_uid>.json
```

into `compress/` for `job_type: "compression"` and into `meta/` for
`job_type: "metadata"` and `job_type: "import"`.

### Envelope — exact keys written by the producer

| Key | Type | Written | Description |
|-----|------|---------|-------------|
| `attachment_id` | int | always | WordPress attachment ID |
| `image_name` | string | always | File basename WITHOUT extension (`pathinfo(..., PATHINFO_FILENAME)`) |
| `job_type` | string | always | `"compression"`, `"metadata"`, or `"import"` |
| `file_path` | string | always | Absolute path to the target file |
| `size` | string | always | WordPress size slug (`"full"`, `"thumbnail"`, …) |
| `dimensions` | string | always | `"WxH"` from `getimagesize()`; `""` for non-images |
| `submitted_at` | string | always | `YYYY-MM-DD HH:MM:SS` (site-local, `current_time('mysql')`) |
| `optimize_level` | int | always | optipng level 0–7 (option `mm_compress_level`, default 2) |
| `metadata` | object | always | Metadata payload (PascalCase keys, see below) |
| `trigger` | string | always (via `$extra`) | Provenance vocabulary (see below) |
| `requeue_source_id` | int | on `trigger: "requeue"` only | DB row id of the job being retried |

There is no `priority` field (B4, 2026-11-16: the drain once sorted by one
nothing wrote — jobs process FIFO in filename order).

There is no separate `fields` or `job_trigger` key — the payload key is
`metadata` and the trigger key is `trigger` (`job_trigger` is only the history
DB column name for `trigger`; see Result Import below). All envelope keys are
written onto every job type; the daemons read only what they need.

### `trigger` vocabulary (complete, from code call sites)

| Value | Origin |
|-------|--------|
| `upload` | Fresh upload / re-upload handling (`on_upload`) |
| `thumbnail_regen` | Thumbnail regeneration (compression only) |
| `edit` | Attachment edit-screen save |
| `bulk` | Bulk-compress admin action |
| `bulk_site_info` | Bulk site-info (Publisher/Website) embed |
| `batch_apply` | Batch-apply admin action |
| `scan` | Library scan (admin / CLI) |
| `rest_api` | REST endpoints |
| `cli` | WP-CLI commands |
| `manual` | Per-item "compress now" from the job dashboard |
| `requeue` | Retry from job history (carries `requeue_source_id`) |
| `import` | Import job enqueue, and metadata write-back queued after an import result |
| `verify` | Post-write-back verification import job |

### Metadata payload (`metadata` object)

Logical field names are PascalCase. Producer: `MM_Metadata::get_fields_for_job()`
(values sourced from WP post/attachment meta; empty values are omitted).
`Publisher` and `Website` are included only when the auto-provenance setting is
enabled (default on). GPS fields are never in the payload — they are
import-only.

`Title`, `Description`, `Caption`, `AltText`, `Creator`, `Copyright`, `Owner`,
`Publisher`, `Website`, `Headline`, `Credit`, `Keywords` (semicolon-separated
string), `DateCreated`, `Rating`, `City`, `State`, `Country`.

---

## Job Types

### 1. Compression Job (`compress/`, `job_type: "compression"`)

```json
{
  "attachment_id": 42,
  "image_name": "photo",
  "job_type": "compression",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full",
  "dimensions": "3000x2000",
  "submitted_at": "2026-11-16 12:00:00",
  "optimize_level": 2,
  "metadata": { "Title": "Sunrise over the ridge", "Creator": "Jane Doe" },
  "trigger": "upload"
}
```

Consumed keys (`metamanager-compress-daemon.sh`): `file_path`, `attachment_id`,
`size`, `dimensions`, `submitted_at`, `image_name`, `optimize_level`
(validated `^[0-7]$`, else 2 — PNG only). Missing keys are tolerated;
unparseable JSON fails with `Malformed job JSON`.

Tool invocation (as run; all under `timeout 120`):

| Input | Command | Replace policy |
|-------|---------|----------------|
| jpg/jpeg | `jpegtran -copy all -optimize -progressive -outfile <out> <file>` | only if smaller |
| png | `optipng -o<optimize_level> -preserve -quiet <file>` (in place) | n/a |
| webp | `cwebp -lossless -mt -quiet -o <out> -- <file>` | only if smaller |
| avif | `avifenc --min 0 --max 0 --speed 6 --lossless -o <out> <file>` | only if smaller |
| mp4 m4v mov avi mkv wmv webm ogv 3gp 3gpp 3g2 3gpp2 ts mts m2ts flv | `ffmpeg -y -v quiet -i <file> -c copy -map_metadata 0 -movflags +faststart <out>` (remux; if ffmpeg absent → skipped, completed) | only if smaller |
| anything else | unsupported — skipped, completed | n/a |

### 2. Metadata Embedding Job (`meta/`, `job_type: "metadata"`)

```json
{
  "attachment_id": 42,
  "image_name": "photo",
  "job_type": "metadata",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full",
  "dimensions": "3000x2000",
  "submitted_at": "2026-11-16 12:00:00",
  "optimize_level": 2,
  "metadata": {
    "Title": "Sunrise over the ridge",
    "Creator": "Jane Doe",
    "Copyright": "© 2026 Jane Doe",
    "Keywords": "landscape; sunrise; nature"
  },
  "trigger": "edit"
}
```

Consumed keys (`metamanager-meta-daemon.sh`): `file_path`, `attachment_id`,
`size`, `metadata` (default `{}`), `job_type` (default `"metadata"`).

ExifTool invocation: `exiftool -overwrite_original [-charset iptc=UTF8 for
images] <tag=value…> <file>`. Keywords are split on `; `/space and appended
with `+=`. Empty values are skipped. If no tags result, the job completes with
`No metadata fields to embed — skipped`.

Format categories (by file extension) and the tags actually written:

| Category | Extensions | Tags written per logical field |
|----------|-----------|-------------------------------|
| image | jpg jpeg png webp avif gif tiff tif (+ default) | `Title`→`-Title`, `-IPTC:ObjectName`, `-XMP:Title`; `Description`→`-EXIF:ImageDescription`, `-IPTC:Caption-Abstract`, `-XMP:Description`; `Caption`→`-IPTC:Caption-Abstract`, `-XMP:Caption`; `AltText`→`-XMP:AltTextAccessibility`; `Creator`→`-EXIF:Artist`, `-IPTC:By-line`, `-XMP:Creator`; `Copyright`→`-EXIF:Copyright`, `-IPTC:CopyrightNotice`, `-XMP:Rights`; `Owner`→`-EXIF:OwnerName`, `-XMP:Owner`; `Publisher`→`-XMP:Publisher`; `Website`→`-XMP:WebStatement`; `Headline`→`-IPTC:Headline`, `-XMP:Headline`; `Credit`→`-IPTC:Credit`, `-XMP:Credit`; `Keywords`→`-IPTC:Keywords+=`, `-XMP:Subject+=`; `DateCreated`→`-EXIF:DateTimeOriginal`, `-IPTC:DateCreated`, `-XMP:DateCreated`; `Rating`→`-XMP:Rating`; `City`→`-IPTC:City`, `-XMP:City`; `State`→`-IPTC:Province-State`, `-XMP:State`; `Country`→`-IPTC:Country-PrimaryLocationName`, `-XMP:Country`. `-IPTC:Source` gets `Website` if present, else `Publisher`. |
| mp3 | mp3 | `Title`→`-ID3:Title`, `-XMP:Title`; `Creator`→`-ID3:Artist`, `-XMP:Creator`; `Copyright`→`-ID3:Copyright`, `-XMP:Rights`; `Description`→`-ID3:Comment`, `-XMP:Description`; `Publisher`→`-ID3:Band`, `-XMP:Publisher`; `Headline`/`Credit`→XMP only; `DateCreated`→`-ID3:Year`, `-XMP:DateCreated`; `Website`→`-XMP:WebStatement`; `Rating`→`-XMP:Rating`; `Keywords`→`-ID3:Genre+=`, `-XMP:Subject+=` |
| quicktime | mp4 m4v m4a mov 3gp 3gpp 3g2 | `Title`→`-QuickTime:Title`, `-XMP:Title`; `Creator`→`-QuickTime:Author`, `-XMP:Creator`; `Copyright`→`-QuickTime:Copyright`, `-XMP:Rights`; `Description`→`-QuickTime:Description`, `-XMP:Description`; `Publisher`→`-XMP:Publisher`; `Website`→`-XMP:WebStatement`; `Headline`/`Credit`→XMP only; `DateCreated`→`-QuickTime:CreateDate`, `-XMP:DateCreated`; `Rating`→`-XMP:Rating`; `City`/`State`/`Country`→XMP only; `Keywords`→`-QuickTime:Keywords+=`, `-XMP:Subject+=` |
| vorbis | ogg oga flac | `Title`→`-Title`; `Creator`→`-Artist`; `Copyright`→`-Copyright`; `Description`→`-Description`; `Publisher`→`-Organization`; `DateCreated`→`-Date`; `Headline`→`-XMP:Headline`; `Keywords`→`-Genre+=` |
| xmp_only | avi wav wmv wma pdf | All fields → XMP namespace only (`-XMP:Title`, `-XMP:Creator`, `-XMP:Rights`, `-XMP:Description`, `-XMP:Publisher`, `-XMP:WebStatement`, `-XMP:Headline`, `-XMP:Credit`, `-XMP:DateCreated`, `-XMP:Rating`, `-XMP:City`, `-XMP:State`, `-XMP:Country`); `Keywords`→`-XMP:Subject+=` |
| read-only | mkv webm ogv | Nothing written — completes with `Read-only format — metadata embedding skipped` |

### 3. Import Job (`meta/`, `job_type: "import"`)

Queued by the plugin to read embedded tags from a file (fresh-upload import and
post-write-back verification). Same envelope; `metadata` rides along but is not
consumed.

```json
{
  "attachment_id": 42,
  "image_name": "photo",
  "job_type": "import",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full",
  "dimensions": "3000x2000",
  "submitted_at": "2026-11-16 12:00:00",
  "optimize_level": 2,
  "metadata": { "Title": "Sunrise over the ridge" },
  "trigger": "import"
}
```

`trigger` for import jobs is `"import"` (initial read) or `"verify"`
(verification read-back). The daemon runs
`exiftool -a -G1 -s -j <file>` and returns element `[0]` as `embedded_tags`.

---

## Result Files

Filename (written by `write_result()` in `daemon-common.sh`, and the import
path in `metamanager-meta-daemon.sh`): the claimed job filename (still
including its `.json`) with `-result.json` appended:

```
<attachment_id>-<size>-<unix_time>-<6_char_uid>.json-result.json
```

Written to `completed/` or `failed/` atomically (`.tmp` then `mv`). A result is
the original job JSON with these keys merged in:

| Key | Type | Present | Description |
|-----|------|---------|-------------|
| `status` | string | always | `"completed"` or `"failed"` (also implied by the directory) |
| `completed_at` | string | always | `YYYY-MM-DD HH:MM:SS` (daemon clock; fleet runs UTC) |
| `bytes_before` | int | compression only | Original size in bytes (`0` on failure) |
| `bytes_after` | int | compression only | Result size in bytes (`0` on failure) |
| `details` | object | always | `{"message": "<human-readable outcome>"}` |
| `webp_path` | string | compression only, when WebP generated | Absolute path to the generated `.webp` alongside the JPEG/PNG original |
| `embedded_tags` | object | import jobs only | Flat `"Group:Tag"` → value map from `exiftool -a -G1 -s -j`; values may be string or array |

### Completed compression example

```json
{
  "attachment_id": 42,
  "image_name": "photo",
  "job_type": "compression",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full",
  "dimensions": "3000x2000",
  "submitted_at": "2026-11-16 12:00:00",
  "optimize_level": 2,
  "metadata": {},
  "trigger": "upload",
  "status": "completed",
  "completed_at": "2026-11-16 12:00:05",
  "bytes_before": 1048576,
  "bytes_after": 923456,
  "webp_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.webp",
  "details": { "message": "JPEG lossless compressed: 1048576 → 923456 bytes; WebP generated: 345678 bytes" }
}
```

### Failed example

Same envelope with `"status": "failed"`, `bytes_before`/`bytes_after` `0` for
compression (absent for metadata/import), and a `details.message` such as
`File not found: <path>`, `Symlink not allowed: <path>`, `Malformed job JSON`,
`ExifTool failed for: <path>`, `jpegtran failed for: <path>`.

### Import result example

```json
{
  "job_type": "import",
  "attachment_id": 42,
  "trigger": "import",
  "status": "completed",
  "completed_at": "2026-11-16 12:00:05",
  "details": { "message": "Read embedded tags from /srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg" },
  "embedded_tags": {
    "EXIF:Artist": "John Doe",
    "EXIF:Copyright": "© 2026 John Doe",
    "XMP:Title": "My Image",
    "IPTC:ObjectName": "My Image",
    "XMP:Subject": [ "landscape", "sunrise" ]
  }
}
```

Keys of `embedded_tags` are ExifTool `Group:Tag` names (e.g. `EXIF:Artist`,
`XMP:Title`, `IPTC:ObjectName`, `Composite:GPSLatitude`) — never bare tag
names. The plugin resolves them through `MM_Metadata::field_map()` candidate
priority lists.

---

## Result Import (plugin side)

`mm_import_completed_jobs()` (WP-Cron, every minute) globs `*.json` in
`completed/` and `failed/` (skipping `.tmp`, `.processing`, `.unparseable`),
persists each result via `MM_DB::log_job()` into the `{prefix}metamanager_jobs`
history table (REPLACE on the unique key `attachment_id + job_type + size`),
then deletes the result file. Unparseable results are renamed `.unparseable`
for inspection. Failed jobs trigger one combined notification email when
notifications are enabled.

DB mapping note: the JSON key `trigger` is stored in the `job_trigger` column.
`details` is stored JSON-encoded.

Post-processing after a successful DB write:

- Import result with `trigger: "verify"` → `MM_Metadata::apply_verify_result()`
  (records discrepancies to attachment meta).
- Any other completed import → `MM_Metadata::apply_import_result()` (existing
  user-set values are never overwritten), then queues metadata write-back jobs
  (`trigger: "import"`) for the full size and, for images, all registered sizes.
- Completed `metadata` job on `full` → queues an import job (`trigger: "verify"`)
  to confirm the values stuck.

Enqueue dedup (`write_job()`): compression jobs for an attachment+size already
pending (plain `.json`) are suppressed (return `skipped`); metadata/import jobs
are written anyway (return `queued`) and run in sequence.

---

## Atomic Ownership Protocol

1. **Claim:** daemon renames `job.json` → `job.json.processing` (atomic `mv`;
   failure means another worker claimed it — skip).
2. **Lock:** per-file `flock -n` on `<file_path>.mm.lock`; on contention the
   job is renamed back to `.json` (re-queued) and left for later.
3. **Process:** daemon runs the tool commands above (`timeout 120` per tool).
4. **Complete:** result written `.tmp` → `mv` into `completed/` or `failed/`.
5. **Cleanup:** `.processing` file removed.

If a daemon crashes, `*.json.processing` files remain and are recovered on the
next startup (`*.json.processing` → `*.json`); leftover `*.mm_tmp` tool
temporaries are deleted at startup.

Startup drain (`drain_jobs_loop()`) processes pre-existing jobs FIFO (filename
order) before entering the main loop. The main
loop uses `inotifywait` (`close_write`, `moved_to`) on the queue directory.

### Cross-process gating

Processing pauses (never receiving) while another gated process is working:
any of `/var/lib/gcm/busy/{backup,maint,update,scan,restore}` present, or
MXRoute Mailer reporting queued mail (the gate reads the MXRoute queue DB
through WP-CLI — `wp mxroute queue count`; the status JSON is admin/monitor
data only). Missing
state files or uninstalled software = idle. Queued jobs stay in place and run
when the gate clears.

---

## Daemon Status & PID Files

Each daemon atomically writes a status file (`.tmp` then `mv`):

```json
{ "pid": 1234, "queue_depth": 0, "last_completed": "2026-11-16 12:00:05", "updated_at": "2026-11-16 12:00:05" }
```

- `compress-status.json` / `meta-status.json` in `MM_JOB_ROOT`
- PID files `compress-daemon.pid` / `meta-daemon.pid` in `MM_JOB_ROOT`
  (stale-PID detection at startup; removed on exit)

---

## Notes

- All timestamps are `YYYY-MM-DD HH:MM:SS` — `submitted_at` is site-local
  (`current_time('mysql')`), `completed_at` is the daemon clock (fleet runs
  Etc/UTC). Neither is ISO-8601 `T` format.
- File paths must be absolute. Symlinks are rejected (result: failed).
- The `size` field is the WordPress size slug, not dimensions.
- Daemons are stateless — all state is in the filesystem.
- PHP never touches image files — the daemons do all heavy lifting.
- Both daemons require bash 5+, `jq`, and `inotifywait`; the meta daemon
  additionally requires `/usr/bin/exiftool`.
