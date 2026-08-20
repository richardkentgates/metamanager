#!/usr/bin/env bash
#
# metamanager-self-updater.sh
#
# Polls required-version.json written by the WordPress plugin.
# If required version differs from installed VERSION, runs apt upgrade.
# Writes comprehensive status JSON for dashboard widget and REST API.
#
# Runs via systemd timer every 60 seconds.
#

set -euo pipefail

readonly REQUIRED_VERSION_FILE="/usr/local/lib/metamanager/required-version.json"
readonly INSTALLED_VERSION_FILE="/usr/local/lib/metamanager/VERSION"
readonly STATUS_FILE="/var/run/metamanager-status.json"
readonly LOG_FILE="/var/log/metamanager-self-updater.log"
readonly LOCK_FILE="/var/run/metamanager-self-updater.lock"

# Detect WordPress path.
readonly WP_CANDIDATES=(
    "/srv/www/wordpress"
    "/var/www/wordpress"
    "/var/www/html"
    "/opt/bitnami/wordpress"
)
WP_CONTENT_DIR=""
for p in "${WP_CANDIDATES[@]}"; do
    if [[ -f "$p/wp-includes/version.php" ]]; then
        WP_CONTENT_DIR="$p/wp-content"
        break
    fi
done

log() {
    local level="$1"; shift
    local ts
    ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    echo "[$ts] [$level] $*" >> "$LOG_FILE" 2>/dev/null || true
}

json_escape() {
    local v="$1"
    v="${v//\\/\\\\}"
    v="${v//\"/\\\"}"
    echo "$v"
}

get_installed() {
    [[ -f "$INSTALLED_VERSION_FILE" ]] && cat "$INSTALLED_VERSION_FILE" 2>/dev/null | tr -d '[:space:]'
}

get_required() {
    [[ -f "$REQUIRED_VERSION_FILE" ]] || { echo ""; return; }
    jq -r '.required_version // empty' "$REQUIRED_VERSION_FILE" 2>/dev/null || { echo ""; return; }
}

version_gt() {
    [[ "$1" != "$2" ]] && [[ "$(printf '%s\n' "$1" "$2" | sort -V | tail -1)" == "$1" ]]
}

count_jobs() {
    local dir="$1"
    if [[ -d "$dir" ]]; then
        find "$dir" -maxdepth 1 -name '*.json' -type f 2>/dev/null | wc -l | tr -d '[:space:]'
    else
        echo "0"
    fi
}

tool_exists() {
    command -v "$1" >/dev/null 2>&1 && echo "true" || echo "false"
}

daemon_running() {
    local name="$1"
    systemctl is-active "$name" >/dev/null 2>&1 && echo "true" || echo "false"
}

daemon_pid() {
    local name="$1"
    local pid
    pid=$(pgrep -f "$name" 2>/dev/null | head -1)
    echo "${pid:-}"
}

write_status() {
    local installed="$1" required="$2" updater_status="$3" message="$4"
    local ts
    ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)

    # Preserve last_update across checks.
    local prev_update=""
    [[ -f "$STATUS_FILE" ]] && prev_update=$(jq -r '.updater.last_update // empty' "$STATUS_FILE" 2>/dev/null || true)
    local last_update="${prev_update:-}"
    [[ "$updater_status" == "updated" ]] && last_update="$ts"

    # Queue counts.
    local jobs_dir=""
    [[ -n "$WP_CONTENT_DIR" ]] && jobs_dir="$WP_CONTENT_DIR/metamanager-jobs"
    local compress_queue="0" meta_queue="0" completed="0" failed="0"
    if [[ -n "$jobs_dir" && -d "$jobs_dir" ]]; then
        compress_queue=$(count_jobs "$jobs_dir/compress")
        meta_queue=$(count_jobs "$jobs_dir/meta")
        completed=$(count_jobs "$jobs_dir/completed")
        failed=$(count_jobs "$jobs_dir/failed")
    fi

    # Uptime of daemons.
    local compress_uptime="" meta_uptime=""
    if [[ "$(daemon_running metamanager-compress-daemon)" == "true" ]]; then
        compress_uptime=$(systemctl show metamanager-compress-daemon --property=ActiveEnterTimestamp --value 2>/dev/null || echo "")
    fi
    if [[ "$(daemon_running metamanager-meta-daemon)" == "true" ]]; then
        meta_uptime=$(systemctl show metamanager-meta-daemon --property=ActiveEnterTimestamp --value 2>/dev/null || echo "")
    fi

    cat > "$STATUS_FILE" << EOJSON
{
  "ts": "$ts",
  "updater": {
    "installed_version": "$(json_escape "${installed:-unknown}")",
    "required_version": "$(json_escape "${required:-unknown}")",
    "last_check": "$ts",
    "last_update": "${last_update}",
    "status": "$(json_escape "${updater_status}")",
    "message": "$(json_escape "${message}")"
  },
  "daemons": {
    "compress": {
      "running": $(daemon_running metamanager-compress-daemon),
      "pid": "$(daemon_pid metamanager-compress-daemon)",
      "started": "$(json_escape "${compress_uptime}")"
    },
    "meta": {
      "running": $(daemon_running metamanager-meta-daemon),
      "pid": "$(daemon_pid metamanager-meta-daemon)",
      "started": "$(json_escape "${meta_uptime}")"
    }
  },
  "queues": {
    "compress": $compress_queue,
    "meta": $meta_queue,
    "completed": $completed,
    "failed": $failed
  },
  "tools": {
    "exiftool": $(tool_exists /usr/bin/exiftool),
    "jpegtran": $(tool_exists /usr/bin/jpegtran),
    "optipng": $(tool_exists /usr/bin/optipng),
    "cwebp": $(tool_exists /usr/bin/cwebp),
    "ffmpeg": $(tool_exists /usr/bin/ffmpeg),
    "avifenc": $(tool_exists /usr/bin/avifenc)
  },
  "config": {
    "wp_content_dir": "$(json_escape "${WP_CONTENT_DIR:-unknown}")"
  }
}
EOJSON
    chmod 0644 "$STATUS_FILE" 2>/dev/null || true
    chown root:www-data "$STATUS_FILE" 2>/dev/null || true
}

main() {
    # Single instance lock.
    exec 200>"$LOCK_FILE"
    flock -n 200 || { log "WARN" "Another instance running, skipping"; exit 0; }

    local installed required
    installed=$(get_installed)
    required=$(get_required)

    # No required version file — plugin hasn't written one yet.
    if [[ -z "$required" ]]; then
        write_status "${installed:-unknown}" "unknown" "waiting" "Plugin has not written required version yet"
        exit 0
    fi

    # No installed VERSION file — daemon package not installed.
    if [[ -z "$installed" ]]; then
        write_status "unknown" "$required" "error" "VERSION file missing"
        exit 1
    fi

    # Versions match — all good.
    if [[ "$installed" == "$required" ]]; then
        write_status "$installed" "$required" "ok" "Daemon v${installed} is current"
        exit 0
    fi

    # Required is newer — update needed.
    if ! version_gt "$required" "$installed"; then
        write_status "$installed" "$required" "ahead" "Installed v${installed} is ahead of required v${required}"
        exit 0
    fi

    log "INFO" "Update needed: v${installed} → v${required}"
    write_status "$installed" "$required" "updating" "Updating from v${installed} to v${required}..."

    # apt-get update
    if ! sudo -n apt-get update -qq >> "$LOG_FILE" 2>&1; then
        log "ERROR" "apt-get update failed"
        write_status "$installed" "$required" "failed" "apt-get update failed"
        exit 1
    fi

    # apt-get install
    if ! sudo -n apt-get install -y -qq metamanager >> "$LOG_FILE" 2>&1; then
        log "ERROR" "apt-get install metamanager failed"
        write_status "$installed" "$required" "failed" "apt-get install failed"
        exit 1
    fi

    # Verify new version.
    local new_ver
    new_ver=$(get_installed)

    # Restart daemons.
    sudo -n systemctl restart metamanager-compress-daemon 2>> "$LOG_FILE" || true
    sudo -n systemctl restart metamanager-meta-daemon 2>> "$LOG_FILE" || true

    if [[ "$new_ver" == "$required" ]]; then
        log "INFO" "Update complete: v${new_ver}"
        write_status "$new_ver" "$required" "updated" "Daemon updated to v${new_ver}"
    else
        log "ERROR" "Version mismatch after update: got v${new_ver}, expected v${required}"
        write_status "${new_ver:-unknown}" "$required" "failed" "Expected v${required}, got v${new_ver:-unknown}"
    fi
}

main
