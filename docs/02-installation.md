# Installation

## Quick Install (via apt)

```bash
# Import the GPG key
wget -q -O /tmp/metamanager.key https://apt.richardkentgates.com/key.gpg
sudo gpg --batch --yes --dearmor -o /usr/share/keyrings/metamanager.gpg /tmp/metamanager.key

# Add the apt repository
echo "deb [signed-by=/usr/share/keyrings/metamanager.gpg] https://apt.richardkentgates.com stable main" | sudo tee /etc/apt/sources.list.d/metamanager.list

# Install
sudo apt update && sudo apt install metamanager
```

This installs:
- Compression and metadata daemons
- Systemd services (auto-enabled)
- All OS dependencies (ExifTool, jpegtran, optipng, cwebp, ffmpeg, jq, inotify-tools)

## Manual Install

```bash
# 1. Clone
git clone https://github.com/richardkentgates/metamanager.git

# 2. Run installer
sudo bash metamanager/metamanager-install.sh --wp-path /srv/www/wordpress
```

## Installer Script

`metamanager-install.sh` handles fresh installs and updates.

### What it does

1. Detects package manager (`apt`, `dnf`, `yum`)
2. Installs all OS dependencies
3. Copies daemon scripts to `/usr/local/bin/`
4. Creates and enables systemd service units
5. Sets correct ownership (`www-data:www-data`) on the job queue directory

### What it does NOT do

- Does not install the WordPress plugin
- Does not configure WordPress or database settings

### Usage

```bash
# Fresh install
sudo bash metamanager-install.sh --wp-path /srv/www/wordpress

# Update (daemon files only)
sudo bash metamanager-install.sh --update --wp-path /srv/www/wordpress

# Uninstall
sudo bash metamanager-install.sh --uninstall --wp-path /srv/www/wordpress
```

## Updating

**Via WordPress admin (recommended):**
The daemon updates automatically via apt whenever the plugin triggers an update.

**Via apt directly:**

```bash
sudo apt update && sudo apt upgrade metamanager
```

## Uninstall

### Via installer (recommended)

```bash
sudo bash metamanager-install.sh --uninstall --wp-path /srv/www/wordpress
```

### Manual uninstall

```bash
# Stop and remove daemons
sudo systemctl stop metamanager-compress-daemon metamanager-meta-daemon
sudo systemctl disable metamanager-compress-daemon metamanager-meta-daemon
sudo rm /etc/systemd/system/metamanager-*.service
sudo rm /usr/local/bin/metamanager-*-daemon.sh
sudo systemctl daemon-reload
```
