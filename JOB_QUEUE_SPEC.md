# Metamanager Job Queue Specification

**Version:** 1.0
**Date:** 2026-07-22

This document defines the contract between the WordPress plugin (PHP) and the OS daemons (Bash). Both repos reference this spec — changes require updates to both sides.

---

## Directory Structure

```
WP_CONTENT_DIR/metamanager-jobs/
├── compress/          # Compression job queue (PHP writes, daemon reads)
│   ├── *.json         # Pending jobs
│   └── *.json.processing  # Jobs being processed (daemon-locked)
├── meta/              # Metadata embedding job queue (PHP writes, daemon reads)
│   ├── *.json         # Pending jobs
│   └── *.json.processing  # Jobs being processed (daemon-locked)
├── completed/         # Completed jobs (daemon writes, PHP reads)
│   └── *-result.json  # Completed job results
├── failed/            # Failed jobs (daemon writes, PHP reads)
│   └── *-result.json  # Failed job results
├── compress-daemon.pid    # PID file for compression daemon
└── meta-daemon.pid        # PID file for metadata daemon
```

---

## Job Types

### 1. Compression Job (`compress/`)

Queued by PHP when an image is uploaded or regenerated.

```json
{
  "job_type": "compress",
  "attachment_id": 1234,
  "file_path": "/var/www/html/wp-content/uploads/2026/07/image.jpg",
  "size": "full",
  "image_name": "image.jpg",
  "submitted_at": "2026-07-22T12:00:00",
  "optimize_level": 2
}
```

**Fields:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `job_type` | string | Yes | Must be `"compress"` |
| `attachment_id` | int | Yes | WordPress attachment ID |
| `file_path` | string | Yes | Absolute path to the file |
| `size` | string | Yes | Image size slug (`"full"`, `"thumbnail"`, etc.) |
| `image_name` | string | Yes | Original filename for logging |
| `submitted_at` | string | Yes | ISO 8601 timestamp |
| `optimize_level` | int | No | Compression level (1-3, default 2) |

**Daemon Processing:**
- JPEG: `jpegtran -copy all -optimize -progressive`
- PNG: `optipng -o2`
- WebP: `cwebp -m 6 -q 100` (lossless)
- Video: `ffmpeg -i input -c copy output` (container remux)
- AVIF: Skipped (already optimal)

### 2. Metadata Embedding Job (`meta/`)

Queued by PHP when metadata is saved or after import.

```json
{
  "job_type": "metadata",
  "attachment_id": 1234,
  "file_path": "/var/www/html/wp-content/uploads/2026/07/image.jpg",
  "size": "full",
  "metadata": {
    "title": "My Image",
    "description": "A beautiful photo",
    "creator": "John Doe",
    "copyright": "© 2026 John Doe"
  },
  "submitted_at": "2026-07-22T12:00:00",
  "trigger": "save"
}
```

**Fields:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `job_type` | string | Yes | Must be `"metadata"` |
| `attachment_id` | int | Yes | WordPress attachment ID |
| `file_path` | string | Yes | Absolute path to the file |
| `size` | string | Yes | Image size slug |
| `metadata` | object | Yes | Key-value pairs to embed |
| `submitted_at` | string | Yes | ISO 8601 timestamp |
| `trigger` | string | No | `"save"` (default) or `"import"` |

**Metadata Fields:**
| Key | EXIF | IPTC | XMP |
|-----|------|------|-----|
| `title` | Title | ObjectName | Title |
| `description` | ImageDescription | Caption-Abstract | Description |
| `caption` | — | Caption-Abstract | Caption |
| `alt_text` | — | — | AltTextAccessibility |
| `creator` | Artist | By-line | Creator |
| `copyright` | Copyright | CopyrightNotice | Rights |
| `owner` | OwnerName | — | Owner |
| `publisher` | — | Source | Publisher |
| `website` | — | — | WebStatement |

### 3. Import Job (`meta/` with `job_type: "import"`)

Queued by PHP to read embedded metadata from a file.

```json
{
  "job_type": "import",
  "attachment_id": 1234,
  "file_path": "/var/www/html/wp-content/uploads/2026/07/image.jpg",
  "size": "full",
  "submitted_at": "2026-07-22T12:00:00",
  "trigger": "upload"
}
```

**Trigger Values:**
- `"upload"` — Initial import after image upload
- `"verify"` — Post-write-back verification (compares embedded vs WP meta)

---

## Result Format

### Completed Job

Written to `completed/` directory.

```json
{
  "job_type": "compress",
  "attachment_id": 1234,
  "file_path": "/var/www/html/wp-content/uploads/2026/07/image.jpg",
  "size": "full",
  "image_name": "image.jpg",
  "status": "completed",
  "completed_at": "2026-07-22 12:00:05",
  "bytes_before": 1048576,
  "bytes_after": 923456,
  "details": {
    "message": "Compressed: 1048576 → 923456 bytes (12% reduction)"
  }
}
```

### Failed Job

Written to `failed/` directory.

```json
{
  "job_type": "compress",
  "attachment_id": 1234,
  "file_path": "/var/www/html/wp-content/uploads/2026/07/image.jpg",
  "size": "full",
  "status": "failed",
  "completed_at": "2026-07-22 12:00:05",
  "bytes_before": 0,
  "bytes_after": 0,
  "details": {
    "message": "File not found: /var/www/html/wp-content/uploads/2026/07/image.jpg"
  }
}
```

### Import Result

For import jobs, the result includes embedded tags.

```json
{
  "job_type": "import",
  "attachment_id": 1234,
  "file_path": "/var/www/html/wp-content/uploads/2026/07/image.jpg",
  "size": "full",
  "status": "completed",
  "completed_at": "2026-07-22 12:00:05",
  "embedded_tags": {
    "Title": "My Image",
    "Artist": "John Doe",
    "Copyright": "© 2026 John Doe"
  }
}
```

---

## Atomic Ownership Protocol

To prevent double-processing by concurrent daemons:

1. **Claim:** Daemon renames `job.json` → `job.json.processing`
2. **Process:** Daemon processes the job
3. **Complete:** Daemon writes result to `completed/` or `failed/`
4. **Cleanup:** Daemon removes `job.json.processing`

```bash
# Claim (atomic mv)
mv "${jobfile}" "${tmpfile}" 2>/dev/null || return 0

# ... process ...

# Complete (atomic write)
mv "${result_tmp}" "${result_file}"

# Cleanup
rm -f "${tmpfile}"
```

**Race Condition Handling:**
- If `mv` fails, another daemon already claimed the job → skip
- If daemon crashes, `.processing` file remains → daemon recovers on startup
- Per-file lock (`flock`) prevents concurrent processing of the same image

---

## Daemon Recovery

On startup, daemons scan for orphaned jobs:

```bash
# Recover .processing orphans from previous crash
for orphan in "${JOB_DIR}"/*.json.processing; do
    [[ -e "${orphan}" ]] || continue
    recovered="${orphan%.processing}"
    mv "${orphan}" "${recovered}" 2>/dev/null || true
done

# Process any pending jobs before starting inotifywait
for _f in "${JOB_DIR}"/*.json; do
    [[ -e "${_f}" ]] || continue
    process_job "${_f}"
done
```

---

## PID Files

Daemons write PID files so PHP can check health without systemctl:

```
WP_CONTENT_DIR/metamanager-jobs/compress-daemon.pid
WP_CONTENT_DIR/metamanager-jobs/meta-daemon.pid
```

**PHP Health Check:**
```php
// Read PID file
$pid = (int) trim(file_get_contents($pid_file));

// Check /proc/<pid> exists (Linux only)
$is_alive = is_dir('/proc/' . $pid);
```

---

## Notes

- All timestamps are ISO 8601 (job submission) or `YYYY-MM-DD HH:MM:SS` (completion)
- File paths must be absolute
- The `size` field is the WordPress image size slug, not dimensions
- Daemons are stateless — all state is in the filesystem
- PHP never touches image files — daemons do all heavy lifting
