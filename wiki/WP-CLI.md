# WP-CLI

Metamanager registers a `wp metamanager` command group. **WP-CLI 2.0+ is required.**

---

## `compress`

Queue lossless compression for one or all compressible attachments. Images are processed by `jpegtran` (JPEG), `optipng` (PNG), or `cwebp -lossless` (WebP). Video is remuxed losslessly via `ffmpeg -c copy`. Audio and PDF have no compression step.

```bash
wp metamanager compress           # all compressible files
wp metamanager compress all       # explicit — same as above
wp metamanager compress 42        # single attachment by ID
wp metamanager compress all --force  # re-queue already-compressed files
```

| Flag | Description |
|------|-------------|
| `--force` | Re-queue files that have already been compressed. Without this flag, already-compressed files are skipped. |

---

## `import`

Read embedded tags from each file and populate **empty** WordPress fields. Existing user-set values are **never overwritten**.

Tag sources by format:
- Images: EXIF / IPTC / XMP
- MP4 / MOV / M4A: QuickTime atoms
- MP3: ID3 tags
- OGG / FLAC: Vorbis comments
- AVI / WAV / WMV / WMA / PDF: XMP

```bash
wp metamanager import             # all supported files
wp metamanager import all         # explicit — same as above
wp metamanager import 42          # single attachment by ID
```

---

## `scan`

Import metadata for every library file **not yet synced** by Metamanager. Faster than `import all` on large existing libraries — already-synced files are skipped automatically based on the `mm_meta_synced` post meta flag.

```bash
wp metamanager scan
```

---

## `queue status`

Print pending job counts by type and current daemon health.

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

---

## `stats`

Show aggregate compression savings from the full job history.

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
