# Metamanager Server — Architecture Reference

This document describes the server-side components of the Metamanager system: the OS daemons, systemd services, and installer that process media files on a WordPress server.

> The WordPress plugin lives in a separate repository (`metamanager-plugin`). This repo contains only the server-side daemons, installer, and packaging.

---

## Design Principles

| Principle | In practice |
|-----------|-------------|
| **OS executes, PHP coordinates** | Daemons are bash scripts using `inotifywait` to watch job directories. They never call back into WordPress. |
| **Job queue is the contract** | Both the plugin and daemons agree on directory layout and JSON job file format. The plugin writes job files; daemons read, process, and write results. |
| **Atomic ownership** | `.processing` extension rename prevents two daemon instances from processing the same file simultaneously. |
| **Safe defaults** | Daemons write PID files for health checks. systemd provides process supervision, restart on failure, and resource limits. |
| **No plugin coupling** | Daemons only care about the filesystem. They never load WordPress or query the database. |

---

## Repository Layout

```
metamanager/
├── daemons/
│   ├── metamanager-compress-daemon.sh    Lossless compression: jpegtran/optipng/cwebp/ffmpeg
│   ├── metamanager-meta-daemon.sh        Metadata read/write: ExifTool
│   ├── metamanager-compress-daemon.service   systemd unit template
│   └── metamanager-meta-daemon.service       systemd unit template
│
├── metamanager-install.sh    Server installer: OS deps, systemd, job queue setup
│
├── debian/                   .deb packaging
│   ├── control               Package metadata
│   ├── rules                 Build rules
│   ├── postinst              Post-install: systemd daemon-reload
│   ├── prerm                 Pre-remove: stop daemons
│   ├── postrm                Post-remove: cleanup apt config
│   ├── metamanager.install   File list for dpkg-deb
│   └── apt-metamanager.conf  APT timeout config (installed to /etc/apt/apt.conf.d/)
│
├── apt-metamanager.conf      Source file for the apt config
├── VERSION                   Current version (e.g. 2.4.4)
├── CHANGELOG.md              Release notes
├── ARCHITECTURE.md           This file
│
├── .github/workflows/
│   ├── ci.yml                "Dev CI — Lint & Version Bump" (ShellCheck + auto-bump)
│   ├── build-deb.yml         "Promote to Test — Build & Deploy" (build .deb + apt repo)
│   └── main-release.yml      "Promote to Release — Tag & GitHub Release"
│
└── .shellcheckrc             ShellCheck configuration
```

---

## Job Queue Contract

The plugin and daemons communicate exclusively through JSON job files in `wp-content/metamanager-jobs/`. This is the interface contract.

### Directory Layout

```
wp-content/metamanager-jobs/
├── compress/          Incoming compression jobs
├── meta/              Incoming metadata jobs
├── completed/         Daemon-written result files
├── failed/            Daemon-written failure files
├── compress-daemon.pid    PID file for compress daemon
└── meta-daemon.pid        PID file for meta daemon
```

### Job File Format

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

**Result file** (`completed/{uuid}.json` or `failed/{uuid}.json`):
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

## Daemon Structure

Both shell scripts follow the same pattern:

```bash
# 1. Write PID to ${JOB_ROOT}/<name>.pid
echo $$ > "${PID_FILE}"
trap 'rm -f "${PID_FILE}"' EXIT

# 2. Wait for job directory (retry every 10s if missing)
while [ ! -d "${JOB_DIR}" ]; do
    logger -t "${LOGGER_TAG}" "Waiting for ${JOB_DIR}..."
    sleep 10
done

# 3. inotifywait loop
inotifywait -m -e close_write "${JOB_DIR}" | while read ...; do
    # rename to .processing (atomic ownership claim)
    mv "${file}" "${file}.processing"
    # do the work
    # write result to completed/ or failed/
    rm "${file}.processing"
done
```

### Key Behaviours

- **Directory waiting**: If the job queue directory doesn't exist (e.g., before plugin activation), daemons log and retry every 10 seconds instead of failing.
- **Atomic ownership**: `.processing` extension rename prevents concurrent processing of the same file.
- **PID files**: Written to `wp-content/metamanager-jobs/` for PHP health checks. PHP reads the PID file and checks `/proc/<pid>` to confirm the process is alive.
- **Trap cleanup**: `trap 'rm -f "${PID_FILE}"' EXIT` ensures PID file is removed on any exit.

### Compress Daemon (`metamanager-compress-daemon.sh`)

Processes compression jobs. Detects media type by extension and delegates to:

| Tool | Media type |
|------|-----------|
| `jpegtran` | JPEG |
| `optipng` | PNG |
| `cwebp` | WebP |
| `ffmpeg` | Video |

### Meta Daemon (`metamanager-meta-daemon.sh`)

Processes metadata jobs. Uses ExifTool for all tag operations:

| Job type | Action |
|----------|--------|
| `import` | Read embedded EXIF/IPTC/XMP tags → write JSON result |
| `metadata` | Write WordPress field values back to file tags → write JSON result |

---

## systemd Services

### Unit Template

Both `.service` files are identical in structure:

```ini
[Unit]
Description=Metamanager <daemon> Daemon
After=network.target

[Service]
Type=simple
ExecStart=/bin/bash /usr/local/bin/metamanager-<daemon>-daemon.sh
Restart=on-failure
RestartSec=5

# Hardening
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=<WP_CONTENT_DIR>/metamanager-jobs
User=<web-server-user>

[Install]
WantedBy=multi-user.target
```

### systemd Hardening

| Directive | Purpose |
|-----------|---------|
| `NoNewPrivileges=true` | Prevents privilege escalation |
| `ProtectSystem=strict` | Read-only filesystem except `ReadWritePaths` |
| `ReadWritePaths` | Only the job queue directory is writable |
| `User` | Runs as the web server user (patched at install time) |

---

## Installer (`metamanager-install.sh`)

The installer handles server-side setup only. It does **not** manage the WordPress plugin — that is installed separately via the WordPress admin or WP-CLI.

### What it installs

1. **OS dependencies**: `inotify-tools`, `libimage-exiftool-perl`, `libjpeg-turbo-progs`, `optipng`, `libwebp-tools`, `ffmpeg`
2. **Daemon scripts**: `/usr/local/bin/metamanager-compress-daemon.sh`, `/usr/local/bin/metamanager-meta-daemon.sh`
3. **systemd services**: `/etc/systemd/system/metamanager-*.service`
4. **APT timeout config**: `/etc/apt/apt.conf.d/apt-metamanager.conf` (prevents apt hanging on slow connections)

### What it does NOT install

- WordPress plugin files
- Job queue directories (created by the plugin on activation via `MM_Job_Queue::ensure_dirs()`)
- Database tables (created by the plugin on activation via `MM_DB::create_or_update_table()`)

---

## CI/CD Pipeline

Three-stage promotion flow:

```
dev  ──push──►  test (build .deb + deploy to apt)  ──promote──►  main (tag + GitHub release)
```

### Workflow Stages

| Stage | Trigger | What happens |
|-------|---------|--------------|
| **Dev CI** | Push to `dev` | ShellCheck lint + auto-bump patch version |
| **Promote to Test** | Merge to `test` | Build .deb + deploy to apt repo (`http://apt.richardkentgates.com`) |
| **Promote to Release** | Merge to `main` | Tag + GitHub release + deploy to apt repo |

### Deployment

- `.deb` packages are served from `http://apt.richardkentgates.com/pool/m/metamanager/`
- APT repository metadata at `http://apt.richardkentgates.com/dists/bookworm/`
- Production installs via: `apt-get install metamanager`
- Production updates via: `apt-get upgrade metamanager`

---

## APT Repository

The apt server (`apt.richardkentgates.com`) serves both daemon `.deb` packages and plugin zip files.

### Structure

```
/var/www/html/
├── dists/bookworm/
│   ├── Release              Signed release metadata
│   ├── Release.gpg          Detached GPG signature
│   ├── InRelease            Clearsigned release
│   └── main/binary-amd64/
│       └── Packages         Package index
├── pool/m/metamanager/
│   └── metamanager_*.deb    Versioned .deb packages
└── metamanager/
    ├── metadata.json        Plugin version + download URL
    └── metamanager-latest.zip   Latest plugin zip
```

### Signing

- GPG key fingerprint: `E0395903AE72DD661AD11DF76C0D53C3F9B96454`
- Public key installed on production: `/usr/share/keyrings/metamanager.gpg`
- APT source: `deb [signed-by=/usr/share/keyrings/metamanager.gpg] http://apt.richardkentgates.com bookworm main`

---

## Version Numbers

| Component | Where defined | Format |
|-----------|--------------|--------|
| Daemon .deb | `debian/changelog` | `2.4.4-1` (Debian upstream-revision) |
| Plugin | `metamanager.php` (`MM_VERSION`) | `2.3.2` (semver) |

Versions are bumped automatically by the CI pipeline:
- Dev push → patch bump in version file + changelog entry
- Test build → snapshot entry with commit hash
- Release → tagged release, no further changes
