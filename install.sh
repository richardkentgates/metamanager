#!/usr/bin/env bash
# =============================================================================
# Metamanager — Server Installation Script
#
# Usage:
#   wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/install.sh | sudo bash
# OR after cloning:
#   sudo bash install.sh [--wp-path /path/to/wordpress]
#
# To update only the plugin files (skip daemons and dependencies):
#   sudo bash install.sh --update [--wp-path /path/to/wordpress]
# OR:
#   wget -qO- https://raw.githubusercontent.com/richardkentgates/metamanager/main/install.sh | sudo bash -s -- --update
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

# --- Colours ---
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; exit 1; }

# --- Must be root ---
if [[ "${EUID}" -ne 0 ]]; then
    error "This script must be run as root (sudo bash install.sh)"
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
            echo "Usage: sudo bash install.sh [--update] [--wp-path /path/to/wordpress]"
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
    # Common locations to probe
    local candidates=(
        "/var/www/html"
        "/srv/www/wordpress"
        "/var/www/wordpress"
        "/opt/bitnami/wordpress"
    )
    for c in "${candidates[@]}"; do
        if [[ -f "${c}/wp-config.php" ]]; then
            echo "${c}"
            return
        fi
    done
    # Deeper search (slower but thorough)
    find /var/www /srv/www /opt -name "wp-config.php" -maxdepth 6 2>/dev/null \
        | head -1 \
        | xargs -I{} dirname {}
}

if [[ -z "${WP_PATH}" ]]; then
    info "Searching for WordPress installation..."
    WP_PATH=$(find_wp)
fi

if [[ -z "${WP_PATH}" || ! -f "${WP_PATH}/wp-config.php" ]]; then
    error "Could not find WordPress. Use --wp-path /path/to/wordpress"
fi

WP_CONTENT_DIR="${WP_PATH}/wp-content"
PLUGIN_DEST="${WP_CONTENT_DIR}/plugins/metamanager"

success "WordPress found at: ${WP_PATH}"
info "WP content dir: ${WP_CONTENT_DIR}"

# Determine the script's own directory (works when piped or run directly)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-install.sh}")" 2>/dev/null && pwd || echo ".")"

# =============================================================================
# Detect package manager and install dependencies
# =============================================================================

install_deps() {
    local pkgs=("jq" "inotify-tools" "libimage-exiftool-perl" "libjpeg-turbo-progs" "optipng")

    if command -v apt-get &>/dev/null; then
        info "Detected apt. Installing dependencies..."
        apt-get update -qq
        apt-get install -y "${pkgs[@]}" 2>&1 | grep -E '(Installing|already|ERROR)' || true

    elif command -v dnf &>/dev/null; then
        info "Detected dnf. Installing dependencies..."
        # EPEL for exiftool and optipng on RHEL-based systems
        dnf install -y epel-release 2>/dev/null || true
        dnf install -y jq inotify-tools perl-Image-ExifTool libjpeg-turbo-utils optipng || true

    elif command -v yum &>/dev/null; then
        info "Detected yum. Installing dependencies..."
        yum install -y epel-release 2>/dev/null || true
        yum install -y jq inotify-tools perl-Image-ExifTool libjpeg-turbo-utils optipng || true

    else
        warn "No known package manager found. Install these manually:"
        warn "  jq, inotify-tools, exiftool, jpegtran, optipng"
    fi
}

if [[ "${UPDATE_ONLY}" == false ]]; then
    install_deps

    # Verify critical tools
    for tool in jq inotifywait exiftool jpegtran optipng; do
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

    if [[ "${SCRIPT_DIR}" != "." && -f "${SCRIPT_DIR}/metamanager.php" ]]; then
        cp -r "${SCRIPT_DIR}/." "${TMP_UPDATE}/"
        success "Update source: ${SCRIPT_DIR}"
    else
        if ! command -v git &>/dev/null; then
            error "git not found. Install git or run from a cloned directory."
        fi
        info "Fetching latest from GitHub..."
        git clone --depth=1 https://github.com/richardkentgates/metamanager.git "${TMP_UPDATE}"
        success "Latest code fetched."
    fi

    # Sync plugin files only — leave daemons/ untouched in the plugin dir.
    rsync -a --exclude='daemons/' --exclude='.git/' --exclude='install.sh' \
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
        if ! command -v git &>/dev/null; then
            error "git not found. Install git or clone the repository manually first."
        fi
        info "Cloning from GitHub..."
        git clone --depth=1 https://github.com/richardkentgates/metamanager.git "${PLUGIN_DEST}"
        success "Plugin cloned."
    fi
fi

# Fix permissions.
chown -R www-data:www-data "${PLUGIN_DEST}"
find "${PLUGIN_DEST}" -type f -name "*.php" -exec chmod 644 {} \;
find "${PLUGIN_DEST}" -type f -name "*.sh"  -exec chmod 755 {} \;

if [[ "${UPDATE_ONLY}" == false ]]; then
    # =============================================================================
    # Create job queue directories
    # =============================================================================

    for subdir in compress meta completed failed; do
        dir="${WP_CONTENT_DIR}/metamanager-jobs/${subdir}"
        mkdir -p "${dir}"
        chown www-data:www-data "${dir}"
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

    sed "s|__WP_CONTENT_DIR__|${WP_CONTENT_DIR}|g" "${src}" > "${dest}"
    chmod 644 "${dest}"
    success "Service file installed: ${dest}"
done

# =============================================================================
# Enable and start daemons
# =============================================================================

info "Reloading systemd and starting daemons..."
systemctl daemon-reload

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
    if [[ "${UPDATE_ONLY}" == true ]]; then
        info "WP-CLI found. Flushing cache..."
        sudo -u www-data wp cache flush --path="${WP_PATH}" 2>/dev/null && \
            success "WordPress object cache flushed." || true
    else
        info "WP-CLI found. Activating plugin..."
        sudo -u www-data wp plugin activate metamanager --path="${WP_PATH}" 2>&1 && \
            success "Plugin activated via WP-CLI." || \
            warn "WP-CLI activation failed. Activate the plugin manually in WordPress Admin → Plugins."
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
echo -e "${GREEN}  Metamanager ${UPDATE_ONLY:+update }${UPDATE_ONLY:-installation }complete!${NC}"
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
