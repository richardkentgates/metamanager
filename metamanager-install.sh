#!/usr/bin/env bash
# =============================================================================
# Metamanager — Server Installation Script
#
# Installs daemons, systemd units, and job queue directories.
# The WordPress plugin is managed separately via the apt server.
#
# Usage:
#   wget -qO- http://apt.richardkentgates.com/metamanager-install.sh | sudo bash
# OR:
#   sudo bash metamanager-install.sh [--wp-path /path/to/wordpress] [--no-deps]
#
# What this script does:
#   1. Detects or accepts the WordPress installation path
#   2. Installs system dependencies (jpegtran, optipng, exiftool, inotify-tools, jq)
#   3. Patches the daemon scripts with the correct WP_CONTENT_DIR path
#   4. Copies daemon scripts to /usr/local/bin/ and makes them executable
#   5. Patches and installs systemd service files
#   6. Enables and starts both daemons
#   7. Creates job queue directories
#
# Requires: systemd, bash 5+, apt or dnf (for dependency install)
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
NO_DEPS=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --wp-path)
            WP_PATH="$2"
            shift 2
            ;;
        --no-deps)
            NO_DEPS=true
            shift
            ;;
        --help|-h)
            echo "Usage: sudo bash metamanager-install.sh [--no-deps] [--wp-path /path/to/wordpress]"
            echo ""
            echo "  --no-deps   Skip apt/dnf dependency install (use when deps are pre-provisioned)."
            exit 0
            ;;
        *)
            warn "Unknown argument: $1"
            shift
            ;;
    esac
done

if [[ "${NO_DEPS}" == true ]]; then
    info "Skipping dependency install (--no-deps); dependencies must be pre-installed."
fi

# =============================================================================
# Detect WordPress path
# =============================================================================

find_wp() {
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
    local inc root
    while IFS= read -r inc; do
        root="$(dirname "${inc}")"
        if [[ -d "${root}/wp-content" ]]; then
            echo "${root}"
            return
        fi
    done < <(find /var/www /srv/www /opt /home -type d -name "wp-includes" -maxdepth 7 2>/dev/null)
}

resolve_wp_root() {
    local p="$1"
    if [[ -d "${p}/wp-content" ]]; then
        echo "${p}"
        return
    fi
    for sub in "${p}"/*/; do
        if [[ -d "${sub}wp-content" ]]; then
            echo "${sub%/}"
            return
        fi
    done
    echo "${p}"
}

if [[ -z "${WP_PATH}" ]]; then
    info "Searching for WordPress installation..."
    WP_PATH=$(find_wp)
fi

WP_PATH=$(resolve_wp_root "${WP_PATH}")

if [[ -z "${WP_PATH}" || ! -d "${WP_PATH}/wp-content" ]]; then
    error "Could not find WordPress. Use --wp-path /path/to/wordpress"
fi

WP_CONTENT_DIR="${WP_PATH}/wp-content"

WP_OWNER=$(stat -c '%U' "${WP_CONTENT_DIR}" 2>/dev/null || echo 'www-data')
if ! id "${WP_OWNER}" &>/dev/null; then
    WP_OWNER='www-data'
fi

success "WordPress found at: ${WP_PATH}"
info "WP content dir: ${WP_CONTENT_DIR}"

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
        dnf install -y epel-release 2>/dev/null || true
        dnf config-manager --set-enabled crb 2>/dev/null || \
            dnf config-manager --set-enabled powertools 2>/dev/null || true
        _el_ver=$(rpm -E '%{rhel}' 2>/dev/null || echo '9')
        dnf install -y "https://mirrors.rpmfusion.org/free/el/rpmfusion-free-release-${_el_ver}.noarch.rpm" 2>/dev/null || true
        dnf install -y jq inotify-tools perl-Image-ExifTool libjpeg-turbo-utils optipng libwebp-tools ffmpeg || true

    elif command -v yum &>/dev/null; then
        info "Detected yum. Installing dependencies..."
        yum install -y epel-release 2>/dev/null || true
        yum-config-manager --enable epel 2>/dev/null || true
        _el_ver=$(rpm -E '%{rhel}' 2>/dev/null || echo '8')
        yum install -y "https://mirrors.rpmfusion.org/free/el/rpmfusion-free-release-${_el_ver}.noarch.rpm" 2>/dev/null || true
        yum install -y jq inotify-tools perl-Image-ExifTool libjpeg-turbo-utils optipng libwebp-tools ffmpeg || true

    else
        warn "No known package manager found. Install these manually:"
        warn "  jq, inotify-tools, exiftool, jpegtran, optipng, cwebp, ffmpeg"
    fi
}

preflight_checks() {
    info "Running pre-flight checks..."

    if [[ "$(uname -s)" != "Linux" ]]; then
        error "Metamanager requires Linux. Detected: $(uname -s)"
    fi

    if ! command -v systemctl &>/dev/null; then
        error "systemd is required for daemon management. Not found on this system."
    fi

    if ! command -v apt-get &>/dev/null && ! command -v dnf &>/dev/null && ! command -v yum &>/dev/null; then
        warn "No supported package manager found (apt, dnf, yum). Dependencies must be installed manually."
    fi

    local all_tools=("jq" "inotifywait" "exiftool" "jpegtran" "optipng" "cwebp" "ffmpeg")
    local missing=()

    for tool in "${all_tools[@]}"; do
        if command -v "${tool}" &>/dev/null; then
            success "Pre-flight: ${tool} OK"
        else
            missing+=("${tool}")
        fi
    done

    if [[ ${#missing[@]} -gt 0 ]]; then
        warn "Missing tools: ${missing[*]}"
        info "Auto-installing missing dependencies..."
        install_missing_tools "${missing[@]}"
    fi

    info "Pre-flight checks complete."
}

verify_deps() {
    info "Verifying all dependencies are installed..."

    local critical_tools=("jq" "inotifywait" "exiftool" "jpegtran" "optipng")
    local optional_tools=("cwebp" "ffmpeg")
    local missing_critical=()
    local missing_optional=()

    for tool in "${critical_tools[@]}"; do
        if command -v "${tool}" &>/dev/null; then
            success "${tool}: $(command -v "${tool}")"
        else
            missing_critical+=("${tool}")
        fi
    done

    for tool in "${optional_tools[@]}"; do
        if command -v "${tool}" &>/dev/null; then
            success "${tool}: $(command -v "${tool}")"
        else
            missing_optional+=("${tool}")
        fi
    done

    local all_missing=("${missing_critical[@]}" "${missing_optional[@]}")
    if [[ ${#all_missing[@]} -gt 0 ]]; then
        warn "Missing tools detected: ${all_missing[*]}"
        info "Attempting automatic installation..."
        install_missing_tools "${all_missing[@]}"
    fi

    local failed=()
    for tool in "${critical_tools[@]}"; do
        if command -v "${tool}" &>/dev/null; then
            success "${tool}: $(command -v "${tool}")"
        else
            failed+=("${tool}")
        fi
    done

    for tool in "${optional_tools[@]}"; do
        if command -v "${tool}" &>/dev/null; then
            success "${tool}: $(command -v "${tool}")"
        else
            warn "${tool} not available — some features limited (WebP, video remux)."
        fi
    done

    if [[ ${#failed[@]} -gt 0 ]]; then
        error "Could not install critical dependencies: ${failed[*]}"
        error "Install them manually and re-run this script."
    fi

    info "All critical dependencies verified."
}

install_missing_tools() {
    local tools=("$@")
    local apt_pkgs=()
    local dnf_pkgs=()
    local yum_pkgs=()

    for tool in "${tools[@]}"; do
        case "${tool}" in
            jq)         apt_pkgs+=("jq");              dnf_pkgs+=("jq");              yum_pkgs+=("jq") ;;
            inotifywait) apt_pkgs+=("inotify-tools");   dnf_pkgs+=("inotify-tools");   yum_pkgs+=("inotify-tools") ;;
            exiftool)   apt_pkgs+=("libimage-exiftool-perl"); dnf_pkgs+=("perl-Image-ExifTool"); yum_pkgs+=("perl-Image-ExifTool") ;;
            jpegtran)   apt_pkgs+=("libjpeg-turbo-progs");    dnf_pkgs+=("libjpeg-turbo-utils"); yum_pkgs+=("libjpeg-turbo-utils") ;;
            optipng)    apt_pkgs+=("optipng");          dnf_pkgs+=("optipng");          yum_pkgs+=("optipng") ;;
            cwebp)      apt_pkgs+=("webp");             dnf_pkgs+=("libwebp-tools");    yum_pkgs+=("libwebp-tools") ;;
            ffmpeg)     apt_pkgs+=("ffmpeg");            dnf_pkgs+=("ffmpeg");            yum_pkgs+=("ffmpeg") ;;
        esac
    done

    if command -v apt-get &>/dev/null && [[ ${#apt_pkgs[@]} -gt 0 ]]; then
        info "Installing via apt: ${apt_pkgs[*]}"
        apt-get update -qq
        apt-get install -y "${apt_pkgs[@]}" 2>&1 | grep -E '(Installing|already|ERROR)' || true
    elif command -v dnf &>/dev/null && [[ ${#dnf_pkgs[@]} -gt 0 ]]; then
        info "Installing via dnf: ${dnf_pkgs[*]}"
        dnf install -y epel-release 2>/dev/null || true
        dnf config-manager --set-enabled crb 2>/dev/null || dnf config-manager --set-enabled powertools 2>/dev/null || true
        _el_ver=$(rpm -E '%{rhel}' 2>/dev/null || echo '9')
        dnf install -y "https://mirrors.rpmfusion.org/free/el/rpmfusion-free-release-${_el_ver}.noarch.rpm" 2>/dev/null || true
        dnf install -y "${dnf_pkgs[@]}" || true
    elif command -v yum &>/dev/null && [[ ${#yum_pkgs[@]} -gt 0 ]]; then
        info "Installing via yum: ${yum_pkgs[*]}"
        yum install -y epel-release 2>/dev/null || true
        yum-config-manager --enable epel 2>/dev/null || true
        _el_ver=$(rpm -E '%{rhel}' 2>/dev/null || echo '8')
        yum install -y "https://mirrors.rpmfusion.org/free/el/rpmfusion-free-release-${_el_ver}.noarch.rpm" 2>/dev/null || true
        yum install -y "${yum_pkgs[@]}" || true
    fi
}

if [[ "${NO_DEPS}" == false ]]; then
    preflight_checks
    install_deps
    verify_deps
fi

# =============================================================================
# Patch and install daemon scripts
# =============================================================================

DAEMON_SRC="/usr/local/lib/metamanager/daemons"

for daemon in metamanager-compress-daemon metamanager-meta-daemon; do
    src="${DAEMON_SRC}/${daemon}.sh"
    dest="/usr/local/bin/${daemon}.sh"

    if [[ ! -f "${src}" ]]; then
        error "Daemon script not found: ${src}"
    fi

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

# =============================================================================
# Summary
# =============================================================================

echo ""
echo -e "${GREEN}============================================================${NC}"
echo -e "${GREEN}  Metamanager server installation complete!${NC}"
echo -e "${GREEN}============================================================${NC}"
echo ""
echo "  WordPress path:  ${WP_PATH}"
echo ""
echo "  Compress daemon: $(systemctl is-active metamanager-compress-daemon.service 2>/dev/null || echo 'check manually')"
echo "  Metadata daemon: $(systemctl is-active metamanager-meta-daemon.service 2>/dev/null || echo 'check manually')"
echo ""
echo "  View logs:"
echo "    journalctl -u metamanager-compress-daemon -f"
echo "    journalctl -u metamanager-meta-daemon -f"
echo ""
echo "  Install the plugin separately via the apt server."
echo ""
