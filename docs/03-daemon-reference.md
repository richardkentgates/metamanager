# Daemon Reference

Job/result payload formats live in `JOB_QUEUE_SPEC.md` (repo root) — the
cross-repo contract. This page covers what each daemon does.

## Compression Daemon (`metamanager-compress-daemon.sh`)

Watches `wp-content/metamanager-jobs/compress/` via `inotifywait`.
Consumes `job_type: "compression"` jobs.

### Tool Delegation

All tools run under `timeout 120`. Results replace the original only when
smaller — files are never made larger.

| Media type | Tool | Invocation |
|-----------|------|------------|
| JPEG | `jpegtran` | `-copy all -optimize -progressive` — lossless Huffman table reordering + progressive scan |
| PNG | `optipng` | `-o<optimize_level> -preserve -quiet` — lossless DEFLATE optimization (level 0–7 from the job, default 2) |
| WebP | `cwebp` | `-lossless -mt -quiet` — lossless WebP recompression |
| AVIF | `avifenc` | `--min 0 --max 0 --speed 6 --lossless` — lossless AVIF recompression (skipped with a warning if avifenc is absent) |
| Video (mp4 m4v mov avi mkv wmv webm ogv 3gp 3gpp 3g2 3gpp2 ts mts m2ts flv) | `ffmpeg` | `-c copy -map_metadata 0 -movflags +faststart` — lossless container remux (skipped if ffmpeg is absent) |

Other extensions: skipped, marked completed (nothing to do).

---

## Metadata Daemon (`metamanager-meta-daemon.sh`)

Watches `wp-content/metamanager-jobs/meta/`. Import jobs are import-type jobs
inside `meta/` — no separate directory exists.

### Job Types

| Job type | Action |
|----------|--------|
| `import` | Read embedded tags via `exiftool -a -G1 -s -j` → result `embedded_tags` map (`"Group:Tag"` keys) |
| `metadata` | Write WordPress field values back to file tags via ExifTool → result JSON |

### Write-back format handling

The logical `metadata` payload (PascalCase keys) is mapped to ExifTool tags per
format category: image (EXIF+IPTC+XMP), mp3 (ID3), quicktime (MP4/MOV/M4A),
vorbis (OGG/FLAC), xmp_only (AVI/WAV/WMV/WMA/PDF). MKV/WebM/OGV are
read-only — embedding is skipped, job completes. The exact per-format tag
mapping is in `JOB_QUEUE_SPEC.md`.

---

## Common Patterns

| Pattern | Detail |
|---------|--------|
| Event-driven | `inotifywait` (`close_write`, `moved_to`) — no polling, no sleep loops |
| Atomic ownership | First daemon to rename a job file claims it (`mv ... .processing`); per-file `flock` on the target; lock contention re-queues the job |
| Gating | Processing pauses while GCM busy state (backup/maint/update/scan/restore) or MXRoute pending mail is present — receiving jobs is never blocked |
| Startup recovery | Orphaned `*.json.processing` renamed back to `*.json`; leftover `*.mm_tmp` removed; queued jobs drained sorted by `priority` (desc) |
| PID files | Written to `wp-content/metamanager-jobs/` — used by plugin for health checks |
| Error handling | Failed jobs written to `failed/` with a human-readable `details.message` in the result JSON |
| Tool delegation | Each tool handles one format family — no monolithic binary |
| Directory waiting | If job directory doesn't exist, daemon retries every 10s instead of failing |
| Trap cleanup | `trap 'rm -f "${PID_FILE}" "${STATUS_FILE}"' EXIT` — PID and status files removed on any exit |
