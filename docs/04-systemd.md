# systemd Services

## Service Units

Both services run as `www-data` (the WordPress user) with security hardening.

### Compression Daemon

```ini
[Unit]
Description=Metamanager Compress Daemon
After=network.target

[Service]
Type=simple
ExecStart=/bin/bash /usr/local/bin/metamanager-compress-daemon.sh
Restart=on-failure
RestartSec=5

# Security hardening
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/srv/www/wordpress/wp-content/metamanager-jobs
User=www-data

[Install]
WantedBy=multi-user.target
```

### Metadata Daemon

```ini
[Unit]
Description=Metamanager Meta Daemon
After=network.target

[Service]
Type=simple
ExecStart=/bin/bash /usr/local/bin/metamanager-meta-daemon.sh
Restart=on-failure
RestartSec=5

# Security hardening
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/srv/www/wordpress/wp-content/metamanager-jobs
User=www-data

[Install]
WantedBy=multi-user.target
```

## Security Hardening

| Directive | Purpose |
|-----------|---------|
| `NoNewPrivileges=true` | Prevents privilege escalation |
| `ProtectSystem=strict` | Read-only filesystem except `ReadWritePaths` |
| `ReadWritePaths` | Only the job queue directory is writable |
| `User` | Runs as the web server user (patched at install time) |

## Managing Daemons

```bash
# Status
systemctl status metamanager-compress-daemon
systemctl status metamanager-meta-daemon

# Logs
journalctl -u metamanager-compress-daemon -f
journalctl -u metamanager-meta-daemon -f

# Restart
sudo systemctl restart metamanager-compress-daemon
sudo systemctl restart metamanager-meta-daemon

# Stop
sudo systemctl stop metamanager-compress-daemon metamanager-meta-daemon
```

## Troubleshooting

```bash
# Check if daemons are running
systemctl is-active metamanager-compress-daemon
systemctl is-active metamanager-meta-daemon

# Check PID files
cat /srv/www/wordpress/wp-content/metamanager-jobs/compress-daemon.pid
cat /srv/www/wordpress/wp-content/metamanager-jobs/meta-daemon.pid

# Check if PID is alive
ps -p $(cat /srv/www/wordpress/wp-content/metamanager-jobs/compress-daemon.pid)

# View recent logs
journalctl -u metamanager-compress-daemon --since "1 hour ago"
```
