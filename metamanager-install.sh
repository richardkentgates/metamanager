#!/usr/bin/env bash
# =============================================================================
# Metamanager — Server Installation Script
#
# Usage:
#   wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/metamanager-install.sh | sudo bash
# OR after cloning:
#   sudo bash metamanager-install.sh [--wp-path /path/to/wordpress]
#
# To update only the plugin files (skip daemons and dependencies):
#   sudo bash metamanager-install.sh --update [--wp-path /path/to/wordpress]
#   wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/metamanager-install.sh | sudo bash -s -- --update
#
# Both forms always fetch the latest code from GitHub. When run directly from
# the installed plugin directory, the script detects this and fetches from
# GitHub rather than copying from itself.
#
# What this script does:
#   1. Detects or accepts the WordPress installation path
#   2. Installs system dependencies (jpegtran, optipng, exiftool, inotify-tools, jq)
#   3. Copies the plugin into wp-content/plugins/metamanager/
#   4. Patches the daemon scripts with the correct WP_CONTENT_DIR path
#   5. Copies daemon scripts to /usr/local/bin/ and makes them executable
#   6. Patches and installs systemd service files
#   7. Enables and starts both daemons
#   8. Optionally activates the plugin via WP-CLI if available
#
# With --update, only steps 1, 3, and 8 run. Daemons are left untouched.
#
# Requires: systemd, bash 5+, apt or dnf (for dependency install)
# Tested on: Ubuntu 22.04+, Debian 12+, RHEL/Rocky 9+
# =============================================================================

set -euo pipefail
IFS=$'\n\t'

# --- Require bash 5+ ---
if (( BASH_VERSINFO[0] < 5 )); then
    echo "ERROR: bash 5.0 or higher is required (found ${BASH_VERSION})." >&2
    exit 1
fi

# --- Colours ---
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# --- Must be root ---
if [[ "${EUID}" -ne 0 ]]; then
    error "This script must be run as root (sudo bash metamanager-install.sh)"
fi

# =============================================================================
# Parse arguments
# =============================================================================

WP_PATH=""
UPDATE_ONLY=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --wp-path)
            WP_PATH="$2"
            shift 2
            ;;
        --update)
            UPDATE_ONLY=true
            shift
            ;;
        --help|-h)
            echo "Usage: sudo bash metamanager-install.sh [--update] [--wp-path /path/to/wordpress]"
            echo ""
            echo "  --update    Update plugin files only; skip daemons and dependencies."
            exit 0
            ;;
        *)
            warn "Unknown argument: $1"
            shift
            ;;
    esac
done

if [[ "${UPDATE_ONLY}" == true ]]; then
    info "Running in UPDATE mode — daemons and dependencies will not be touched."
fi

# =============================================================================
# Detect WordPress path
# =============================================================================

find_wp() {
    # Common locations to probe — require wp-content/ AND wp-includes/ so a
    # bare Apache default dir with an empty wp-content folder doesn't match.
    local candidates=(
        "/var/www/html"
        "/srv/www/wordpress"
        "/var/www/wordpress"
        "/opt/bitnami/wordpress"
    )
    for c in "${candidates[@]}"; do
        if [[ -d "${c}/wp-content" && -d "${c}/wp-includes" ]]; then
            echo "${c}"
            return
        fi
    done
    # Deeper search: find proper WordPress roots (must have both wp-content and
    # wp-includes so we don't mistake a default Apache docroot for WordPress).
    # Also search /home for cPanel/DirectAdmin/shared-hosting layouts.
    # Use process substitution (not a pipe) so the while loop runs in the
    # current shell and `return` works correctly — piping to `while` runs it
    # in a subshell where `return` raises an error with set -e active.
    local inc root
    while IFS= read -r inc; do
        root="$(dirname "${inc}")"
        if [[ -d "${root}/wp-content" ]]; then
            echo "${root}"
            return
        fi
    done < <(find /var/www /srv/www /opt /home -type d -name "wp-includes" -maxdepth 7 2>/dev/null)
}

# Resolve the actual WordPress root from a path that may be the wp-config.php
# parent (one directory above the real root — a common hardening technique).
# Accepts: any directory; returns: the dir that contains wp-content/.
resolve_wp_root() {
    local p="$1"
    # Given path already has wp-content — use it directly
    if [[ -d "${p}/wp-content" ]]; then
        echo "${p}"
        return
    fi
    # wp-config.php is one level above; look for a subdir with wp-content
    for sub in "${p}"/*/; do
        if [[ -d "${sub}wp-content" ]]; then
            echo "${sub%/}"
            return
        fi
    done
    # Fall back — caller will catch the missing wp-content error
    echo "${p}"
}

if [[ -z "${WP_PATH}" ]]; then
    info "Searching for WordPress installation..."
    WP_PATH=$(find_wp)
fi

# If the user supplied (or detection returned) a dir whose wp-config.php lives
# one level above the actual WordPress files, resolve to the real root.
WP_PATH=$(resolve_wp_root "${WP_PATH}")

if [[ -z "${WP_PATH}" || ! -d "${WP_PATH}/wp-content" ]]; then
    error "Could not find WordPress. Use --wp-path /path/to/wordpress"
fi

WP_CONTENT_DIR="${WP_PATH}/wp-content"
PLUGIN_DEST="${WP_CONTENT_DIR}/plugins/metamanager"

# Detect the user that owns wp-content — this is the web server / PHP-FPM user
# on this system. Falls back to www-data for Debian/Ubuntu.
WP_OWNER=$(stat -c '%U' "${WP_CONTENT_DIR}" 2>/dev/null || echo 'www-data')
if ! id "${WP_OWNER}" &>/dev/null; then
    WP_OWNER='www-data'
fi

success "WordPress found at: ${WP_PATH}"
info "WP content dir: ${WP_CONTENT_DIR}"

# Determine the script's own directory (works when piped or run directly)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-metamanager-install.sh}")" 2>/dev/null && pwd || echo ".")"

# =============================================================================
# Detect package manager and install dependencies
# =============================================================================

install_deps() {
    local pkgs=("jq" "inotify-tools" "libimage-exiftool-perl" "libjpeg-turbo-progs" "optipng" "webp" "ffmpeg")

    if command -v apt-get &>/dev/null; then
        info "Detected apt. Installing dependencies..."
        apt-get update -qq
        apt-get install -y "${pkgs[@]}" 2>&1 | grep -E '(Installing|already|ERROR)' || true

    elif command -v dnf &>/dev/null; then
        info "Detected dnf. Installing dependencies..."
        # EPEL is required for most packages on RHEL-based systems.
        dnf install -y epel-release 2>/dev/null || true
        # CRB (CodeReady Builder / PowerTools) provides dependencies for some
        # EPEL packages (e.g. perl-Image-ExifTool, optipng) on RHEL 9+.
        dnf config-manager --set-enabled crb 2>/dev/null || \
            dnf config-manager --set-enabled powertools 2>/dev/null || true
        # ffmpeg is NOT in EPEL 9 — it requires RPM Fusion or manual install.
        # Install it separately so a missing ffmpeg does not block the others.
        dnf install -y jq inotify-tools perl-Image-ExifTool libjpeg-turbo-utils optipng libwebp-tools || true
        dnf install -y ffmpeg 2>/dev/null || true

    elif command -v yum &>/dev/null; then
        info "Detected yum. Installing dependencies..."
        yum install -y epel-release 2>/dev/null || true
        yum-config-manager --enable epel 2>/dev/null || true
        yum install -y jq inotify-tools perl-Image-ExifTool libjpeg-turbo-utils optipng libwebp-tools || true
        yum install -y ffmpeg 2>/dev/null || true

    else
        warn "No known package manager found. Install these manually:"
        warn "  jq, inotify-tools, exiftool, jpegtran, optipng"
    fi
}

if [[ "${UPDATE_ONLY}" == false ]]; then
    install_deps

    # Verify critical tools
    for tool in jq inotifywait exiftool jpegtran optipng cwebp ffmpeg; do
        if command -v "${tool}" &>/dev/null; then
            success "${tool}: $(command -v "${tool}")"
        else
            warn "${tool} not found after install. Some features may be limited."
        fi
    done
fi

# =============================================================================
# Install the WordPress plugin
# =============================================================================

if [[ "${UPDATE_ONLY}" == true ]]; then
    info "Updating Metamanager plugin files in ${PLUGIN_DEST}..."

    if [[ ! -d "${PLUGIN_DEST}" ]]; then
        error "Plugin not installed at ${PLUGIN_DEST}. Run without --update to do a full install."
    fi

    # Pull fresh copy to a temp dir then overlay only PHP/JS/asset files.
    TMP_UPDATE=$(mktemp -d)
    trap 'rm -rf "${TMP_UPDATE}"' EXIT

    if [[ "${SCRIPT_DIR}" != "." && -f "${SCRIPT_DIR}/metamanager.php" \
          && "$(realpath "${SCRIPT_DIR}")" != "$(realpath "${PLUGIN_DEST}")" ]]; then
        cp -r "${SCRIPT_DIR}/." "${TMP_UPDATE}/"
        success "Update source: ${SCRIPT_DIR}"
    else
        info "Fetching latest from GitHub..."
        if command -v git &>/dev/null; then
            git clone --depth=1 https://github.com/richardkentgates/metamanager.git "${TMP_UPDATE}"
        else
            TMP_ZIP=$(mktemp --suffix=.zip)
            wget -qO "${TMP_ZIP}" https://github.com/richardkentgates/metamanager/archive/refs/heads/main.zip
            unzip -q "${TMP_ZIP}" -d "${TMP_UPDATE}"
            # GitHub zip extracts to a subdirectory — flatten it
            shopt -s nullglob
            inner=("${TMP_UPDATE}"/metamanager-*/)
            if [[ ${#inner[@]} -gt 0 ]]; then
                mv "${inner[0]}"* "${TMP_UPDATE}/" 2>/dev/null || true
                rmdir "${inner[0]}" 2>/dev/null || true
            fi
            shopt -u nullglob
            rm -f "${TMP_ZIP}"
        fi
        success "Latest code fetched."
    fi

    # Sync plugin files only — leave daemons/ untouched in the plugin dir.
    rsync -a --exclude='daemons/' --exclude='.git/' --exclude='metamanager-install.sh' \
        --exclude='docs/' --exclude='*.md' --exclude='*.gitignore' \
        "${TMP_UPDATE}/" "${PLUGIN_DEST}/"
    success "Plugin files updated."
else
    info "Installing Metamanager plugin to ${PLUGIN_DEST}..."

    if [[ -d "${PLUGIN_DEST}" ]]; then
        warn "Existing plugin directory found — backing up to ${PLUGIN_DEST}.bak.$(date +%s)"
        mv "${PLUGIN_DEST}" "${PLUGIN_DEST}.bak.$(date +%s)"
    fi

    mkdir -p "${PLUGIN_DEST}"

    # If running from a cloned repo, copy from there; otherwise clone.
    if [[ "${SCRIPT_DIR}" != "." && -f "${SCRIPT_DIR}/metamanager.php" ]]; then
        cp -r "${SCRIPT_DIR}/." "${PLUGIN_DEST}/"
        success "Plugin files copied from ${SCRIPT_DIR}"
    else
        info "Cloning from GitHub..."
        if command -v git &>/dev/null; then
            git clone --depth=1 https://github.com/richardkentgates/metamanager.git "${PLUGIN_DEST}"
        else
            TMP_ZIP=$(mktemp --suffix=.zip)
            wget -qO "${TMP_ZIP}" https://github.com/richardkentgates/metamanager/archive/refs/heads/main.zip
            unzip -q "${TMP_ZIP}" -d "${PLUGIN_DEST}"
            # GitHub zip extracts to a subdirectory — flatten into PLUGIN_DEST
            shopt -s nullglob
            inner=("${PLUGIN_DEST}"/metamanager-*/)
            if [[ ${#inner[@]} -gt 0 ]]; then
                mv "${inner[0]}"* "${PLUGIN_DEST}/" 2>/dev/null || true
                rmdir "${inner[0]}" 2>/dev/null || true
            fi
            shopt -u nullglob
            rm -f "${TMP_ZIP}"
        fi
        success "Plugin installed."
    fi
fi

# Fix permissions.
chown -R "${WP_OWNER}:${WP_OWNER}" "${PLUGIN_DEST}"
find "${PLUGIN_DEST}" -type f -name "*.php" -exec chmod 644 {} \;
find "${PLUGIN_DEST}" -type f -name "*.sh"  -exec chmod 755 {} \;

if [[ "${UPDATE_ONLY}" == false ]]; then
    # =============================================================================
    # Create job queue directories
    # =============================================================================

    mkdir -p "${WP_CONTENT_DIR}/metamanager-jobs"
    chown "${WP_OWNER}:${WP_OWNER}" "${WP_CONTENT_DIR}/metamanager-jobs"
    chmod 750 "${WP_CONTENT_DIR}/metamanager-jobs"

    for subdir in compress meta completed failed; do
        dir="${WP_CONTENT_DIR}/metamanager-jobs/${subdir}"
        mkdir -p "${dir}"
        chown "${WP_OWNER}:${WP_OWNER}" "${dir}"
        chmod 750 "${dir}"
        # Prevent direct HTTP access.
        echo "Deny from all" > "${dir}/.htaccess"
    done
    success "Job queue directories created."
fi

if [[ "${UPDATE_ONLY}" == false ]]; then

# =============================================================================
# Patch and install daemon scripts
# =============================================================================

DAEMON_SRC="${PLUGIN_DEST}/daemons"

for daemon in metamanager-compress-daemon metamanager-meta-daemon; do
    src="${DAEMON_SRC}/${daemon}.sh"
    dest="/usr/local/bin/${daemon}.sh"

    if [[ ! -f "${src}" ]]; then
        error "Daemon script not found: ${src}"
    fi

    # Patch the __WP_CONTENT_DIR__ placeholder.
    sed "s|__WP_CONTENT_DIR__|${WP_CONTENT_DIR}|g" "${src}" > "${dest}"
    chmod 755 "${dest}"
    chown root:root "${dest}"
    success "Daemon installed: ${dest}"
done

# =============================================================================
# Patch and install systemd service files
# =============================================================================

SYSTEMD_DIR="/etc/systemd/system"

for svc in metamanager-compress-daemon metamanager-meta-daemon; do
    src="${DAEMON_SRC}/${svc}.service"
    dest="${SYSTEMD_DIR}/${svc}.service"

    if [[ ! -f "${src}" ]]; then
        error "Service file not found: ${src}"
    fi

    sed "s|__WP_CONTENT_DIR__|${WP_CONTENT_DIR}|g; s|User=www-data|User=${WP_OWNER}|g; s|Group=www-data|Group=${WP_OWNER}|g" "${src}" > "${dest}"
    chmod 644 "${dest}"
    success "Service file installed: ${dest}"
done

# =============================================================================
# Enable and start daemons
# =============================================================================

info "Reloading systemd and starting daemons..."
systemctl daemon-reload

# Pre-create log files owned by ${WP_OWNER} so the daemons can write to them.
# /var/log is root-owned; the daemon user cannot create new files there without this.
for log_file in /var/log/metamanager-compress.log /var/log/metamanager-meta.log; do
    touch "${log_file}"
    chown "${WP_OWNER}:${WP_OWNER}" "${log_file}"
    chmod 644 "${log_file}"
done

for svc in metamanager-compress-daemon metamanager-meta-daemon; do
    systemctl enable "${svc}.service"
    systemctl restart "${svc}.service"
    sleep 1
    if systemctl is-active --quiet "${svc}.service"; then
        success "${svc} is running."
    else
        warn "${svc} failed to start. Check: journalctl -u ${svc} -n 20"
    fi
done

fi # end UPDATE_ONLY == false

# =============================================================================
# Activate plugin via WP-CLI (optional)
# =============================================================================

if command -v wp &>/dev/null; then
    # WP_OWNER was detected early in the script from the wp-content directory owner.
    # If we're already that user, run wp directly; otherwise sudo.
    if [[ "$(id -un)" == "${WP_OWNER}" ]] || ! id "${WP_OWNER}" &>/dev/null; then
        WP_CMD=(wp)
    else
        WP_CMD=(sudo -u "${WP_OWNER}" wp)
    fi

    if [[ "${UPDATE_ONLY}" == true ]]; then
        info "WP-CLI found. Flushing cache..."
        if "${WP_CMD[@]}" cache flush --path="${WP_PATH}" 2>/dev/null; then
            success "WordPress object cache flushed."
        fi
    else
        info "WP-CLI found. Activating plugin..."
        if "${WP_CMD[@]}" plugin activate metamanager --path="${WP_PATH}" --skip-plugins 2>&1; then
            success "Plugin activated via WP-CLI."
        else
            warn "WP-CLI activation failed. Activate the plugin manually in WordPress Admin → Plugins."
        fi
    fi
else
    if [[ "${UPDATE_ONLY}" == false ]]; then
        warn "WP-CLI not found. Activate the plugin manually in WordPress Admin → Plugins."
    fi
fi

# =============================================================================
# Summary
# =============================================================================

echo ""
echo -e "${GREEN}============================================================${NC}"
if [[ "${UPDATE_ONLY}" == true ]]; then
    _mode="update"
else
    _mode="installation"
fi
echo -e "${GREEN}  Metamanager ${_mode} complete!${NC}"
echo -e "${GREEN}============================================================${NC}"
echo ""
echo "  WordPress path:  ${WP_PATH}"
echo "  Plugin path:     ${PLUGIN_DEST}"
echo "  Job queue:       ${WP_CONTENT_DIR}/metamanager-jobs/"
echo ""

if [[ "${UPDATE_ONLY}" == false ]]; then
    echo "  Compress daemon: $(systemctl is-active metamanager-compress-daemon.service 2>/dev/null || echo 'check manually')"
    echo "  Metadata daemon: $(systemctl is-active metamanager-meta-daemon.service 2>/dev/null || echo 'check manually')"
    echo ""
    echo "  View logs:"
    echo "    journalctl -u metamanager-compress-daemon -f"
    echo "    journalctl -u metamanager-meta-daemon -f"
else
    echo "  Daemons:         unchanged (update mode)"
fi
echo ""
