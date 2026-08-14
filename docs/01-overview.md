# Overview

Metamanager Server provides OS-level daemons, systemd services, and Debian packaging for the [Metamanager WordPress plugin](https://github.com/richardkentgates/metamanager-plugin).

## Architecture at a Glance

```
WordPress (PHP)                     OS (Bash daemons)
────────────────                    ─────────────────────────────────────
Upload / scan / edit media file
(image, video, audio, PDF)
       │
       ├── On upload or scan: enqueue_import_job() writes an
       │   'import' job — daemon reads embedded tags (EXIF/IPTC/
       │   XMP, ID3, QuickTime, Vorbis, GPS) and returns them
       │   as JSON. WP-Cron applies to WP fields, never overwrites.
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

## Key Concepts

- **Job queue is the contract** — Plugin writes JSON job files, daemons read/process/write results
- **Event-driven** — `inotifywait` fires on file creation, no polling
- **Atomic ownership** — `.processing` extension rename prevents double-processing
- **No plugin coupling** — Daemons only care about the filesystem

## Components

| Component | Purpose |
|-----------|---------|
| `metamanager-compress-daemon.sh` | Lossless image/video compression |
| `metamanager-meta-daemon.sh` | Metadata read/write via ExifTool |
| `metamanager-install.sh` | Server installer (deps, systemd, setup) |
| `debian/` | `.deb` packaging for apt distribution |

## Further Reading

- [Installation](02-installation.md)
- [Daemon Reference](03-daemon-reference.md)
- [systemd Services](04-systemd.md)
- [Job Queue Contract](05-job-queue.md)
- [Troubleshooting](06-troubleshooting.md)
