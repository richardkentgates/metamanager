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
| **Safe defaults** | Daemons write PID files for health checks. systemd provides process supervision and restart-on-failure; concurrency is throttled by MAX_CONCURRENT. |
| **No plugin coupling** | Daemons only care about the filesystem. They never load WordPress or query the database. |

---

## Repository Layout

```
metamanager/
├── daemons/
│   ├── daemon-common.sh                       Shared library: logging, status, PID/lock,
│   │                                          job-dir wait, result writing, drain loop
│   ├── metamanager-compress-daemon.sh         Lossless compression: jpegtran/optipng/cwebp/avifenc/ffmpeg
│   ├── metamanager-meta-daemon.sh             Metadata read/write/import: ExifTool
│   ├── metamanager-compress-daemon.service    systemd unit
│   └── metamanager-meta-daemon.service        systemd unit
│
├── logrotate/metamanager     Log rotation config for both daemons
│
├── VERSION                   Current version (kept in sync with debian/changelog)
├── JOB_QUEUE_SPEC.md         Cross-repo contract: job/result JSON formats
├── CHANGELOG.md              Historical pre-split plugin record (kept for reference);
│                             daemon history lives in debian/changelog
├── ARCHITECTURE.md           This file
│
└── .github/workflows/
    ├── ci.yml                     "Dev CI — Lint & Version Bump" (ShellCheck + auto-bump)
    ├── promote-to-test.yml        Manual (workflow_dispatch): merge dev→test, build .deb, deploy dists/test channel
    └── promote-to-main.yml        Manual (workflow_dispatch): merge test→main, tag, release, deploy stable channel
```

---

## Job Queue Contract

The plugin and daemons communicate exclusively through JSON job files in
`wp-content/metamanager-jobs/`. **The contract is `JOB_QUEUE_SPEC.md`** (repo
root; an identical copy lives in the plugin repo) — exact key names
(`metadata`, `trigger`, …), filenames, result format, trigger vocabulary, and
tool invocations. This file is the design reference and does not duplicate
payload examples.

### Directory Layout

```
wp-content/metamanager-jobs/
├── compress/          Incoming compression jobs (*.json, *.json.processing)
├── meta/              Incoming metadata + import jobs
├── completed/         Daemon-written result files (<job-name>.json-result.json)
├── failed/            Daemon-written failure files (same naming)
├── compress-daemon.pid    PID file for compress daemon
├── meta-daemon.pid        PID file for meta daemon
├── compress-status.json   Daemon-written status
└── meta-status.json       Daemon-written status
```

---

## Daemon Structure

Both shell scripts follow the same pattern:

```bash
# 1. Write PID to ${JOB_ROOT}/<name>.pid
echo $$ > "${PID_FILE}"
trap 'rm -f "${PID_FILE}" "${STATUS_FILE}"' EXIT

# 2. Wait for job directory (retry every 10s if missing)
while [ ! -d "${JOB_DIR}" ]; do
    log "Waiting for ${JOB_DIR}..."
    sleep 10
done

# 3. inotifywait loop
inotifywait -m -e close_write,moved_to "${JOB_DIR}" | while read ...; do
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
- **Trap cleanup**: `trap 'rm -f "${PID_FILE}" "${STATUS_FILE}"' EXIT` ensures PID and status files are removed on any exit.

### Compress Daemon (`metamanager-compress-daemon.sh`)

Processes compression jobs. Detects media type by extension and delegates to:

| Tool | Media type |
|------|-----------|
| `jpegtran` | JPEG |
| `optipng` | PNG |
| `cwebp` | WebP |
| `avifenc` | AVIF |
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

### What it does NOT install

- WordPress plugin files
- Job queue directories (created by the plugin on activation via `MM_Job_Queue::ensure_dirs()`)
- Database tables (created by the plugin on activation via `MM_DB::create_or_update_table()`)

---

## CI/CD Pipeline

Three-stage promotion flow (all repos follow the workspace standard — see
`GITHUB-ACTIONS-RULES.md` in the workspace):

```
dev ──push──► CI (lint + version bump) ──gh workflow run──► test (build .deb + deploy dists/test) ──gh workflow run──► main (tag + GitHub release + deploy stable)
```

### Workflow Stages

| Stage | Trigger | What happens |
|-------|---------|--------------|
| **Dev CI** (`ci.yml`) | Push to `dev` | ShellCheck lint + auto-bump patch version |
| **Promote to Test** (`promote-to-test.yml`) | `workflow_dispatch` from `dev` | Merge dev→test, build .deb, deploy to apt `test` suite |
| **Promote to Main** (`promote-to-main.yml`) | `workflow_dispatch` from `test` | Merge test→main, tag + GitHub release, deploy to apt `stable` suite |

Never merge `test`/`main` manually and never push to them locally — promotion
is `gh workflow run` only.

### Deployment

- `.deb` packages are served from `https://apt.richardkentgates.com/pool/m/metamanager/`
- APT repository metadata at `https://apt.richardkentgates.com/dists/stable/`
- Production installs via: `apt-get install metamanager`
- Production updates via: `apt-get upgrade metamanager`

### Test vs Release Packages

| Channel | Version format | Source |
|---------|---------------|--------|
| **stable** (release) | `X.Y.Z-N` (from `debian/changelog`) | `main` branch (tagged releases) |
| **test** | `X.Y.Z-N` (same format) | `test` branch (pre-release builds) |

Both channels carry the same version format; the channel separates them.
Clients select the channel via the sources.list suite and `-t test` pinning.

```bash
# Install latest stable
sudo apt update && sudo apt install metamanager

# See all available versions
apt-cache policy metamanager

# Install a specific test build
sudo apt-get install -t test metamanager

# Return to stable
sudo apt update && sudo apt install metamanager
```

---

## APT Repository

The apt server (`apt.richardkentgates.com`) serves both daemon `.deb` packages and plugin zip files.

### Structure

```
/var/www/html/
├── dists/stable/
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

- GPG key fingerprint: `17890A496B5EA6A16DB5707663EAEB3B32D83CA6`
- Canonical public keyring URL: `https://apt.richardkentgates.com/gcm-repo-key.gpg`
  — this is a **binary keyring; do NOT pipe it through `gpg --dearmor`**
- APT source: `deb [signed-by=/usr/share/keyrings/gcm-repo-key.gpg] https://apt.richardkentgates.com stable main`

### User Installation

```bash
# Install the signing keyring (binary — download as-is, no dearmor)
sudo curl -fsSL https://apt.richardkentgates.com/gcm-repo-key.gpg \
    -o /usr/share/keyrings/gcm-repo-key.gpg

# Add repository
echo "deb [signed-by=/usr/share/keyrings/gcm-repo-key.gpg] https://apt.richardkentgates.com stable main" | sudo tee /etc/apt/sources.list.d/metamanager.list

# Install
sudo apt update && sudo apt install metamanager

# Verify
systemctl status metamanager-compress-daemon
systemctl status metamanager-meta-daemon
```

---

## Version Numbers

| Component | Where defined | Format |
|-----------|--------------|--------|
| Daemon .deb | `debian/changelog` | `X.Y.Z-N` (Debian upstream-revision, auto-bumped by CI) |
| Plugin | `metamanager.php` (`MM_VERSION`) | `X.Y.Z` (semver, auto-bumped by CI) |

Versions are bumped automatically by the CI pipeline:
- Dev push → patch bump in `debian/changelog` + `VERSION` (auto-increment commit by CI)
- Promotions build and deploy whatever the branch carries — no separate version rewrite
