# Daemon Management

Metamanager uses two systemd services to process jobs:

| Service | Tool | Purpose |
|---------|------|---------|
| `metamanager-compress-daemon` | `jpegtran` / `optipng` / `cwebp` / `ffmpeg` | Lossless compression and video remux |
| `metamanager-meta-daemon` | ExifTool | Metadata write-back to all file types |

Both daemons run as the web-server user detected at install time by `metamanager-install.sh` (e.g. `www-data` on Debian/Ubuntu, `wordpress` or `apache` on RHEL/AlmaLinux). They watch the job queue directory with `inotifywait`, and process jobs immediately on file creation — no polling delay.

---

## Status

```bash
systemctl status metamanager-compress-daemon
systemctl status metamanager-meta-daemon
```

Daemon status is also visible in the **banner at the top of Media → Metamanager** and the Media Library. Detection reads a PID file from `/tmp/` — no `systemctl` privileges are required for the web process.

---

## Logs

```bash
# Follow live
journalctl -u metamanager-compress-daemon -f
journalctl -u metamanager-meta-daemon -f

# Last 100 lines
journalctl -u metamanager-compress-daemon -n 100 --no-pager
journalctl -u metamanager-meta-daemon -n 100 --no-pager
```

---

## Restart

```bash
sudo systemctl restart metamanager-compress-daemon
sudo systemctl restart metamanager-meta-daemon
```

---

## Stop / Start

```bash
sudo systemctl stop metamanager-compress-daemon
sudo systemctl start metamanager-compress-daemon
```

---

## Enable / Disable (boot-time auto-start)

```bash
sudo systemctl enable metamanager-compress-daemon metamanager-meta-daemon
sudo systemctl disable metamanager-compress-daemon metamanager-meta-daemon
```

---

## Service unit details

Both service files are installed to `/etc/systemd/system/`. Key configuration:

```ini
[Service]
Type=simple
User=<detected-web-user>      # patched by metamanager-install.sh
Group=<detected-web-user>
Restart=on-failure
RestartSec=5

# Security hardening
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=<WP_CONTENT_DIR>/metamanager-jobs /tmp /var/log

StandardOutput=journal
StandardError=journal
```

`User` and `Group` are set to the web-server user detected at install time — they are never hardcoded to `www-data` in the distributed service template. `ProtectSystem=strict` and `ReadWritePaths=` require **systemd v232+**. The `WP_CONTENT_DIR` path is patched by the installer — it is not hardcoded in the distributed service file.

---

## Job queue directory

```
wp-content/metamanager-jobs/
  compress/      ← incoming compression jobs (JSON files)
  meta/          ← incoming metadata jobs (JSON files)
  completed/     ← daemon writes result JSON here
  failed/        ← daemon writes failed result JSON here
```

WP-Cron reads and imports from `completed/` and `failed/` every 60 seconds, then deletes the processed files.
