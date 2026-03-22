# Installation

> ⚠ **Beta software.** Functionality may be incomplete or subject to breaking change without notice.

---

## Requirements

| Component | Minimum | Notes |
|-----------|---------|-------|
| OS | Linux | Tested on **Ubuntu 22.04+**, **Debian 12+**, **RHEL / Rocky 9+**. Install script supports `apt`, `dnf`, and `yum`. Other distros require manual dependency installation. |
| bash | 5.0+ | Required by daemon scripts. Ubuntu 18.04 (bash 4.4) is **not supported**. |
| WordPress | 6.0+ | |
| PHP | 8.0+ | |
| ExifTool | any | `perl-Image-ExifTool` or `libimage-exiftool-perl` |
| jpegtran | any | `libjpeg-turbo-progs` (apt) or `libjpeg-turbo-utils` (dnf) |
| optipng | any | `optipng` |
| cwebp | any | `webp` package |
| ffmpeg | any | `ffmpeg` |
| inotify-tools | any | Linux kernel inotify (present since 2.6.13) |
| jq | any | JSON parsing in daemon scripts |
| systemd | v232+ | Required for `ProtectSystem=strict` and `ReadWritePaths=` in service units |

---

## Quick Install (one command)

```bash
wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/metamanager-install.sh | sudo bash
```

If WordPress is not in a standard location:

```bash
sudo bash metamanager-install.sh --wp-path /path/to/your/wordpress
```

**What the installer does:**
1. Detects your WordPress installation path
2. Installs all system dependencies via `apt`, `dnf`, or `yum`
3. Copies the plugin into `wp-content/plugins/metamanager/`
4. Patches daemon scripts with your actual `WP_CONTENT_DIR` — no hardcoded paths
5. Installs, enables, and starts both systemd daemons as the detected web-server user (e.g. `www-data` on Debian/Ubuntu, `wordpress` or `apache` on RHEL/AlmaLinux)
6. Activates the plugin via WP-CLI if available

> **Note:** `cwebp` (`webp` package) and `ffmpeg` are installed automatically when available in the default repositories. If your distribution does not include them, install them manually before running the installer.

---

## Manual Install

```bash
# 1. Clone
git clone https://github.com/richardkentgates/metamanager.git

# 2. Copy plugin
cp -r metamanager /path/to/wordpress/wp-content/plugins/

# 3. Run installer (handles daemons and dependencies)
sudo bash /path/to/wordpress/wp-content/plugins/metamanager/metamanager-install.sh \
  --wp-path /path/to/wordpress
```

---

## Updating

### Via WordPress admin (recommended)

Metamanager integrates with the native WordPress update system. When a new GitHub release is tagged, it appears automatically in **Dashboard → Updates** within 12 hours. Click **Update now** exactly as you would for any plugin.

A **Check for Updates** action link on **Plugins → Installed Plugins** forces an immediate check without waiting.

### Via server script (plugin files only — daemons untouched)

```bash
sudo bash /path/to/wordpress/wp-content/plugins/metamanager/metamanager-install.sh --update
```

Or via wget (useful before the plugin is installed, or from CI):

```bash
wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/metamanager-install.sh | sudo bash -s -- --update
```

Both forms always fetch the latest code from GitHub. When run directly from the installed plugin directory the script detects this automatically and falls back to the GitHub fetch. The `--update` flag skips dependency installation, daemon patching, and systemd service management — it only syncs plugin PHP, JS, and asset files, fixes permissions, and flushes the WordPress object cache.
