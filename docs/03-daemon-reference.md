# Daemon Reference

## Compression Daemon (`metamanager-compress-daemon.sh`)

Watches `wp-content/metamanager-jobs/compress/` via `inotifywait`.

### Tool Delegation

| Media type | Tool | Action |
|-----------|------|--------|
| JPEG | `jpegtran` | Lossless Huffman table reordering + progressive scan |
| PNG | `optipng` | Lossless DEFLATE optimization |
| WebP | `cwebp -lossless` | Lossless WebP compression |
| Video | `ffmpeg -c copy` | Lossless container remux (strips padding) |

### Job File Format

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

### Result File Format

```json
{
  "attachment_id": 42,
  "job_type": "compression",
  "status": "completed",
  "bytes_before": 524288,
  "bytes_after": 491520,
  "details": {}
}
```

---

## Metadata Daemon (`metamanager-meta-daemon.sh`)

Watches `wp-content/metamanager-jobs/meta/`. Import jobs are import-type jobs inside `meta/` — no separate directory exists.

### Job Types

| Job type | Action |
|----------|--------|
| `import` | Read embedded EXIF/IPTC/XMP/ID3/QuickTime/Vorbis tags via ExifTool → write JSON result |
| `metadata` | Write WordPress field values back to file tags via ExifTool → write JSON result |

### Import Job File

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

### Metadata Write-Back Job

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

---

## Common Patterns

| Pattern | Detail |
|---------|--------|
| Event-driven | `inotifywait` fires on file creation — no polling, no sleep loops |
| Atomic ownership | First daemon to rename a job file claims it (`mv ... .processing`) |
| PID files | Written to `wp-content/metamanager-jobs/` — used by plugin for health checks |
| Error handling | Failed jobs written to `failed/` with error message in result JSON |
| Tool delegation | Each tool handles one format family — no monolithic binary |
| Directory waiting | If job directory doesn't exist, daemon retries every 10s instead of failing |
| Trap cleanup | `trap 'rm -f "${PID_FILE}"' EXIT` ensures PID file is removed on any exit |
