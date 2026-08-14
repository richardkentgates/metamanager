# Installation

## Quick Install (via apt — Debian/Ubuntu)

```bash
# Import the GPG key
wget -q -O /tmp/metamanager.key https://apt.richardkentgates.com/key.gpg
sudo gpg --batch --yes --dearmor -o /usr/share/keyrings/metamanager.gpg /tmp/metamanager.key

# Add the apt repository
echo "deb [signed-by=/usr/share/keyrings/metamanager.gpg] https://apt.richardkentgates.com stable main" | sudo tee /etc/apt/sources.list.d/metamanager.list

# Install
sudo apt update && sudo apt install metamanager
```

## Quick Install (via dnf — RHEL/AlmaLinux/Rocky)

```bash
# Import the GPG key
sudo wget -q -O /etc/pki/rpm-gpg/RPM-GPG-KEY-metamanager https://apt.richardkentgates.com/key.gpg

# Add the repository
sudo tee /etc/yum.repos.d/metamanager.repo <<EOF
[metamanager]
name=Metamanager Repository
baseurl=https://apt.richardkentgates.com/rpm
enabled=1
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-metamanager
EOF

# Install
sudo dnf install metamanager
```

## Quick Install (via yum — Older RHEL/CentOS)

```bash
# Same as dnf, but replace 'dnf' with 'yum'
sudo yum install metamanager
```

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

## Verifying Installation

After installing, verify everything is working:

```bash
# 1. Check daemons are installed
which metamanager-compress-daemon.sh metamanager-meta-daemon.sh

# 2. Check VERSION file exists and is readable
cat /usr/local/lib/metamanager/VERSION

# 3. Check systemd services exist
systemctl list-unit-files | grep metamanager

# 4. Check daemons are running
systemctl status metamanager-compress-daemon
systemctl status metamanager-meta-daemon

# 5. Check job queue directory exists
ls -la /srv/www/wordpress/wp-content/metamanager-jobs/

# 6. Verify dependency tools are installed
which jpegtran optipng cwebp ffmpeg exiftool jq inotifywait
```

If all commands succeed, the daemon installation is complete. Install and activate the [WordPress plugin](https://github.com/richardkentgates/metamanager-plugin) to start processing media.

## Updating

**Via WordPress admin (recommended):**
The daemon updates automatically via apt whenever the plugin triggers an update.

**Via apt directly:**

```bash
sudo apt update && sudo apt upgrade metamanager
```

**Via dnf directly:**

```bash
sudo dnf update metamanager
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
