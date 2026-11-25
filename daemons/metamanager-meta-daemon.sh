#!/usr/bin/env bash
# =============================================================================
# Metamanager — Metadata Embedding Daemon
#
# Watches the meta job queue using inotifywait and embeds metadata into image
# files using ExifTool. Supports EXIF, IPTC, and XMP standards simultaneously.
#
# Logical field map (matches MM_Metadata::field_map() in PHP exactly):
#   Title       → EXIF:Title,        IPTC:ObjectName,        XMP:Title
#   Description → EXIF:ImageDescription, IPTC:Caption-Abstract, XMP:Description
#   Caption     → IPTC:Caption-Abstract, XMP:Caption
#   AltText     → XMP:AltTextAccessibility
#   Creator     → EXIF:Artist,       IPTC:By-line,           XMP:Creator
#   Copyright   → EXIF:Copyright,    IPTC:CopyrightNotice,   XMP:Rights
#   Owner       → XMP:Owner,         EXIF:OwnerName
#   Publisher   → IPTC:Source,       XMP:Publisher
#   Website     → XMP:WebStatement
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
JOB_DIR="${JOB_ROOT}/meta"
JOB_DONE="${JOB_ROOT}/completed"
JOB_FAILED="${JOB_ROOT}/failed"
LOG_FILE="/var/log/metamanager-meta.log"
PID_FILE="${JOB_ROOT}/meta-daemon.pid"
STATUS_FILE="${JOB_ROOT}/meta-status.json"

# Maximum simultaneous job subshells. Tune to available CPU cores.
# Raising this too high on a loaded server will saturate disk I/O.
MAX_CONCURRENT=4

EXIFTOOL="/usr/bin/exiftool"

DAEMON_TAG="meta"

# --- Source shared daemon functions ---
META_DAEMON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=daemon-common.sh
source "${META_DAEMON_DIR}/daemon-common.sh"

wait_for_job_dir
setup_pid_file

log "Daemon started (PID $$). Watching ${JOB_DIR}"
write_status 0 ""

# --- Job processor ---
process_meta_job_json() {
    local job_json="$1"

    local file_path attachment_id size job_id job_type
    file_path=$(echo "${job_json}" | jq -r '.file_path // empty')
    attachment_id=$(echo "${job_json}" | jq -r '.attachment_id // empty')
    size=$(echo "${job_json}" | jq -r '.size // empty')
    job_id=$(echo "${job_json}" | jq -r '.id // empty')
    job_type=$(echo "${job_json}" | jq -r '.job_type // "metadata"')

    if [[ ! -f "${file_path}" ]]; then
        log "ERROR: file not found: ${file_path}"
        finish_job_db metadata "${job_id}" failed "File not found: ${file_path}"
        return 1
    fi

    # Import job: read embedded tags from file, apply to WP meta (file→WP).
    if [[ "${job_type}" == "import" ]]; then
        local embedded_json trigger
        trigger=$(echo "${job_json}" | jq -r '.job_trigger // .trigger // "import"')
        embedded_json=$("${EXIFTOOL}" -a -G1 -s -j "${file_path}" 2>/dev/null | jq -c '.[0] // {}' 2>/dev/null)
        if [[ -z "${embedded_json}" ]]; then
            embedded_json='{}'
        fi

        local import_script="${WP_ROOT}/wp-content/plugins/metamanager/includes/import-metadata.php"
        if [[ -f "${import_script}" ]]; then
            if wp --path="${WP_ROOT}" eval-file "${import_script}" "${attachment_id}" "${trigger}" 2>>"${LOG_FILE}" <<HEREDOC_INPUT
${embedded_json}
HEREDOC_INPUT
            then
                finish_job_db metadata "${job_id}" completed "Imported tags from ${file_path}"
            else
                finish_job_db metadata "${job_id}" failed "import-metadata.php failed for ${file_path}"
            fi
        else
            # Fallback: use process-result.php with inline JSON
            local result_tmp="/tmp/mm-import-${job_id}.json"
            echo "{"attachment_id":${attachment_id},"job_type":"import","trigger":"${trigger}","embedded_tags":${embedded_json}}" > "${result_tmp}"
            local result_script="${WP_ROOT}/wp-content/plugins/metamanager/includes/process-result.php"
            if [[ -f "${result_script}" ]]; then
                if wp --path="${WP_ROOT}" eval-file "${result_script}" "${result_tmp}" 2>>"${LOG_FILE}"; then
                    finish_job_db metadata "${job_id}" completed "Imported tags from ${file_path}"
                else
                    finish_job_db metadata "${job_id}" failed "process-result.php failed for ${file_path}"
                fi
            else
                finish_job_db metadata "${job_id}" failed "No import processor found"
            fi
            rm -f "${result_tmp}"
        fi
        return 0
    fi

    # Metadata write job: WP→file embedding.
    local ext="${file_path##*.}"
    ext="${ext,,}"
    case "${ext}" in
        jpg|jpeg|png|webp|heic|mp4|mov|pdf|mp3|ogg|oga|flac|avi|wav|wmv|wma|tif|tiff|gif|avif|m4v|m4a|mov|3gp|3gpp|3g2)
            local wp_root="${WP_ROOT}"
            local meta_script="${wp_root}/wp-content/plugins/metamanager/includes/write-metadata.php"
            if [[ -f "${meta_script}" ]]; then
                if wp --path="${wp_root}" eval-file "${meta_script}" "${attachment_id}" "${file_path}" 2>>"${LOG_FILE}"; then
                    finish_job_db metadata "${job_id}" completed "Metadata written to ${file_path}"
                else
                    finish_job_db metadata "${job_id}" failed "write-metadata.php failed for ${file_path}"
                fi
            else
                finish_job_db metadata "${job_id}" failed "write-metadata.php not found"
            fi
            ;;
        *)
            log "SKIP: metadata not applicable for .${ext}"
            finish_job_db metadata "${job_id}" completed "Skipped — unsupported type"
            ;;
    esac
}

# --- Drain queued jobs and enter main loop ---
DAEMON_JOB_TYPE="metadata"
drain_jobs_loop
run_main_loop