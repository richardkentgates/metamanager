#!/usr/bin/env bash
# =============================================================================
# Metamanager — WebP Generation Daemon
#
# Its own data pipeline, separate from compress and meta.
# PHP populates the webp queue, this daemon processes everything.
# Cron is status reporting only — never operational.
#
# Flow: upload → webp queue → daemon generates + registers (cascade) →
#       falls back into normal upload processing (compression + metadata)
#
# On completion, calls process-result.php via wp eval-file to log to DB
# and run post-processing. Also calls register-webp.php to register the
# generated .webp as a WP attachment (triggers the cascade).
#
# metamanager-install.sh patches JOB_ROOT to match the actual WP_CONTENT_DIR.
# =============================================================================

set -euo pipefail

# --- Configuration ---
readonly TOOL_TIMEOUT=120  # Seconds before an external tool is killed.

# --- Require bash 5+ ---
if (( BASH_VERSINFO[0] < 5 )); then
    echo "ERROR: bash 5.0 or higher is required (found ${BASH_VERSION})." >&2
    exit 1
fi

# --- Require runtime dependencies ---
for tool in jq inotifywait; do
    if ! command -v "${tool}" &>/dev/null; then
        echo "ERROR: required tool '${tool}' not found in PATH. Install it and restart." >&2
        exit 1
    fi
done

# --- Configuration (patched by metamanager-install.sh) ---
JOB_ROOT="__WP_CONTENT_DIR__/metamanager-jobs"
JOB_DIR="${JOB_ROOT}/webp"
JOB_DONE="${JOB_ROOT}/completed"
JOB_FAILED="${JOB_ROOT}/failed"
LOG_FILE="/var/log/metamanager-webp.log"
PID_FILE="${JOB_ROOT}/webp-daemon.pid"
STATUS_FILE="${JOB_ROOT}/webp-status.json"

# Maximum simultaneous job subshells.
MAX_CONCURRENT=2

CWEBP="/usr/bin/cwebp"

DAEMON_TAG="webp"

# --- Source shared daemon functions ---
WEBP_DAEMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=daemon-common.sh
source "${WEBP_DAEMON_DIR}/daemon-common.sh"

wait_for_job_dir
setup_pid_file

log "Daemon started (PID $$). Watching ${JOB_DIR}"
write_status 0 ""

# --- Job processor ---
process_webp_job() {
    local jobfile="$1"
    local tmpfile="${jobfile}.processing"

    mv "${jobfile}" "${tmpfile}" 2>/dev/null || return 0

    local file_path attachment_id size
    file_path=$(jq -r '.file_path // empty' "${tmpfile}") || {
        log "ERROR: malformed JSON in ${tmpfile}"
        write_result "${tmpfile}" "failed" "Malformed job JSON"
        return 1
    }
    attachment_id=$(jq -r '.attachment_id // empty' "${tmpfile}") || {
        log "ERROR: malformed JSON in ${tmpfile}"
        write_result "${tmpfile}" "failed" "Malformed job JSON"
        return 1
    }
    size=$(jq -r '.size // empty' "${tmpfile}")

    # Type guard: only JPEG/PNG need WebP generation.
    local ext="${file_path##*.}"
    ext="${ext,,}"
    case "${ext}" in
        jpg|jpeg|png) ;;
        *)
            log "SKIP: WebP generation not applicable for .${ext}: ${file_path}"
            write_result "${tmpfile}" "completed" "Skipped — not a JPEG/PNG source" "0" "0"
            return 0
            ;;
    esac

    if [[ ! -f "${file_path}" ]]; then
        log "ERROR: file not found: ${file_path}"
        write_result "${tmpfile}" "failed" "File not found: ${file_path}"
        return 1
    fi

    if [[ -L "${file_path}" ]]; then
        log "ERROR: symlink not allowed: ${file_path}"
        write_result "${tmpfile}" "failed" "Symlink not allowed: ${file_path}"
        return 1
    fi

    # Per-file lock.
    local lockfile="${file_path}.mm.webp.lock"
    exec 9>"${lockfile}"
    if ! flock -n 9; then
        log "LOCKED: ${file_path} — re-queuing"
        mv "${tmpfile}" "${jobfile}"
        exec 9>&-
        return 0
    fi

    local webp_path="${file_path%.*}.webp"
    local orig_size new_size message
    orig_size=$(stat -c%s "${file_path}" 2>/dev/null) || orig_size=0

    if [[ -x "${CWEBP}" ]]; then
        if timeout "${TOOL_TIMEOUT}" "${CWEBP}" -quiet -q 80 -o "${webp_path}" -- "${file_path}" 2>>"${LOG_FILE}"; then
            if [[ -f "${webp_path}" ]]; then
                # Copy metadata from original to WebP (EXIF, IPTC, XMP).
                if command -v exiftool &>/dev/null; then
                    timeout "${TOOL_TIMEOUT}" exiftool -overwrite_original -tagsFromFile "${file_path}" "${webp_path}" 2>>"${LOG_FILE}" || true
                fi
                # Match permissions with original.
                local orig_perms
                orig_perms=$(stat -c%a "${file_path}" 2>/dev/null) || orig_perms=""
                if [[ -n "${orig_perms}" ]]; then
                    chmod "${orig_perms}" "${webp_path}" 2>/dev/null || true
                fi
                new_size=$(stat -c%s "${webp_path}" 2>/dev/null) || new_size=0
                message="WebP generated: ${orig_size} → ${new_size} bytes"
                log "OK: ${message} (id: ${attachment_id}, size: ${size})"

                # Register the WebP as a WP attachment — this triggers the
                # cascade: wp_generate_attachment_metadata → on_upload →
                # compression + metadata queue for the new WebP.
                register_webp_attachment "${attachment_id}" "${webp_path}"
            else
                message="cwebp produced no output for: ${file_path}"
                log "FAIL: ${message}"
            fi
        else
            message="cwebp failed for: ${file_path}"
            log "FAIL: ${message}"
        fi
    else
        message="cwebp not found at ${CWEBP}"
        log "WARNING: ${message}"
    fi

    exec 9>&-
    rm -f "${lockfile}"

    if [[ -f "${webp_path}" ]]; then
        # Inject webp_path into the job JSON so it flows into the result file.
        jq --arg wp "${webp_path}" '. + {webp_path: $wp}' "${tmpfile}" > "${tmpfile}.wp" 2>/dev/null && mv "${tmpfile}.wp" "${tmpfile}" || true
        write_result "${tmpfile}" "completed" "${message}" "${orig_size}" "${new_size}"
    else
        write_result "${tmpfile}" "failed" "${message}" "0" "0"
    fi
}

# Register the generated .webp as a WP attachment via wp eval-file.
# This is the cascade point — registration triggers on_upload which
# queues compression + metadata for the new WebP.
register_webp_attachment() {
    local attachment_id="$1"
    local webp_path="$2"

    local wp_root register_script
    wp_root="$(dirname "$(dirname "$(dirname "${JOB_DIR}")")")"
    register_script="${wp_root}/wp-content/plugins/metamanager/includes/register-webp.php"

    if [[ ! -f "${register_script}" ]]; then
        log "WARNING: register-webp.php not found at ${register_script}"
        return 1
    fi

    sudo -u www-data wp --path="${wp_root}" eval-file "${register_script}" -- "${attachment_id}" "${webp_path}" 2>>"${LOG_FILE}" || {
        log "WARNING: register-webp.php failed for ${webp_path}"
        return 1
    }
    log "Registered WebP attachment: ${webp_path} (parent: ${attachment_id})"
}

# --- Drain queued jobs and enter main loop ---
drain_jobs_loop
run_main_loop
