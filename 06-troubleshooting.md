# Troubleshooting

## Daemons Not Running

```bash
# Check status
systemctl status metamanager-compress-daemon
systemctl status metamanager-meta-daemon

# If inactive, check logs
journalctl -u metamanager-compress-daemon --since "1 hour ago"
journalctl -u metamanager-meta-daemon --since "1 hour ago"

# Restart
sudo systemctl restart metamanager-compress-daemon metamanager-meta-daemon
```

## Jobs Stuck in Queue

```bash
# Check for .processing files (stuck jobs)
ls /srv/www/wordpress/wp-content/metamanager-jobs/compress/*.processing
ls /srv/www/wordpress/wp-content/metamanager-jobs/meta/*.processing

# If stuck, remove the .processing extension
sudo mv /srv/www/wordpress/wp-content/metamanager-jobs/compress/{uuid}.json.processing \
        /srv/www/wordpress/wp-content/metamanager-jobs/compress/{uuid}.json
```

## Permission Denied Errors

```bash
# Check ownership of job queue directory
ls -la /srv/www/wordpress/wp-content/metamanager-jobs/

# Fix ownership
sudo chown -R www-data:www-data /srv/www/wordpress/wp-content/metamanager-jobs/
```

## PID File Exists But Daemon Not Running

```bash
# Check if PID is alive
ps -p $(cat /srv/www/wordpress/wp-content/metamanager-jobs/compress-daemon.pid)

# If not running, remove stale PID file
sudo rm /srv/www/wordpress/wp-content/metamanager-jobs/compress-daemon.pid
sudo systemctl restart metamanager-compress-daemon
```

## Missing Dependencies

```bash
# Check if all tools are installed
which jpegtran optipng cwebp ffmpeg exiftool jq inotifywait

# Install missing dependencies
sudo apt install libjpeg-turbo-progs optipng webp ffmpeg libimage-exiftool-perl jq inotify-tools
```

## Daemon Not Processing New Jobs

1. Check if daemon is running: `systemctl status metamanager-compress-daemon`
2. Check if job directory exists: `ls -la /srv/www/wordpress/wp-content/metamanager-jobs/compress/`
3. Check if daemon is watching: `journalctl -u metamanager-compress-daemon -f`
4. Restart daemon: `sudo systemctl restart metamanager-compress-daemon`

## Plugin Can't Detect Daemon Version

```bash
# Check VERSION file
cat /usr/local/lib/metamanager/VERSION

# Check permissions
ls -la /usr/local/lib/metamanager/VERSION

# Fix permissions
sudo chmod 644 /usr/local/lib/metamanager/VERSION
```
