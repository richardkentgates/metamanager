#!/usr/bin/env bash
# =============================================================================
# Metamanager — Lossless Image Compression Daemon
#
# Watches the compress job queue using inotifywait and compresses images using:
#   - jpegtran  for JPEG  (lossless, no re-encoding)
#   - optipng   for PNG   (lossless, deflate re-compression only)
#
# On completion, writes a result JSON to JOB_DONE or JOB_FAILED so that
# WordPress (via WP-Cron) can record it in the database and update post meta.
#
# metamanager-install.sh patches JOB_ROOT to match the actual WP_CONTENT_DIR on this server.
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
JOB_DIR="${JOB_ROOT}/compress"
JOB_DONE="${JOB_ROOT}/completed"
JOB_FAILED="${JOB_ROOT}/failed"
LOG_FILE="/var/log/metamanager-compress.log"
PID_FILE="${JOB_ROOT}/compress-daemon.pid"
STATUS_FILE="${JOB_ROOT}/compress-status.json"

# Maximum simultaneous job subshells. Tune to available CPU cores.
# Raising this too high on a loaded server will saturate disk I/O.
MAX_CONCURRENT=4

JPEGTRAN="/usr/bin/jpegtran"
OPTIPNG="/usr/bin/optipng"
CWEBP="/usr/bin/cwebp"
AVIFENC="/usr/bin/avifenc"

DAEMON_TAG="compress"

# --- Source shared daemon functions ---
COMPRESSION_DAEMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=daemon-common.sh
source "${COMPRESSION_DAEMON_DIR}/daemon-common.sh"

wait_for_job_dir
setup_pid_file

log "Daemon started (PID $$). Watching ${JOB_DIR}"
write_status 0 ""

# --- Job processor ---
process_job_json() {
    local job_json="$1"

    local file_path attachment_id size job_id
    file_path=$(echo "${job_json}" | jq -r '.file_path // empty')
    attachment_id=$(echo "${job_json}" | jq -r '.attachment_id // empty')
    size=$(echo "${job_json}" | jq -r '.size // empty')
    job_id=$(echo "${job_json}" | jq -r '.id // empty')
    local optimize_level
    optimize_level=$(echo "${job_json}" | jq -r '.optimize_level // 2')

    if [[ ! -f "${file_path}" ]]; then
        log "ERROR: file not found: ${file_path}"
        finish_job_db compression "${job_id}" failed "File not found: ${file_path}"
        return 1
    fi

    local ext="${file_path##*.}"
    ext="${ext,,}"
    local outfile="${file_path}"
    local orig_size new_size message

    orig_size=$(stat -c%s "${file_path}" 2>/dev/null) || orig_size=0

    case "${ext}" in
        jpg|jpeg)
            if timeout "${TOOL_TIMEOUT}" "${JPEGTRAN}" -copy all -optimize -progressive -outfile "${outfile}.tmp" "${file_path}" 2>>"${LOG_FILE}"; then
                mv "${outfile}.tmp" "${outfile}" 2>/dev/null
                new_size=$(stat -c%s "${outfile}" 2>/dev/null) || new_size=0
                message="JPEG optimized: ${orig_size} → ${new_size} bytes"
                finish_job_db compression "${job_id}" completed "${message}"
            else
                rm -f "${outfile}.tmp"
                message="jpegtran failed for: ${file_path}"
                finish_job_db compression "${job_id}" failed "${message}"
            fi
            ;;
        png)
            if timeout "${TOOL_TIMEOUT}" "${OPTIPNG}" -o"${optimize_level}" -preserve -quiet "${file_path}" 2>>"${LOG_FILE}"; then
                new_size=$(stat -c%s "${file_path}" 2>/dev/null) || new_size=0
                message="PNG optimized: ${orig_size} → ${new_size} bytes"
                finish_job_db compression "${job_id}" completed "${message}"
            else
                message="optipng failed for: ${file_path}"
                finish_job_db compression "${job_id}" failed "${message}"
            fi
            ;;
        *)
            log "SKIP: compression not applicable for .${ext}"
            finish_job_db compression "${job_id}" completed "Skipped — not compressible"
            ;;
    esac
}

# --- WebP generation job processor (separate job type, same queue) ---

# --- Drain queued jobs and enter main loop ---
DAEMON_JOB_TYPE="compression"
drain_jobs_loop
run_main_loop
