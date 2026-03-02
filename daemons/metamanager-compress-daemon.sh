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
# install.sh patches JOB_ROOT to match the actual WP_CONTENT_DIR on this server.
# =============================================================================

set -euo pipefail

# --- Configuration (patched by install.sh) ---
JOB_ROOT="__WP_CONTENT_DIR__/metamanager-jobs"
JOB_DIR="${JOB_ROOT}/compress"
JOB_DONE="${JOB_ROOT}/completed"
JOB_FAILED="${JOB_ROOT}/failed"
LOG_FILE="/var/log/metamanager-compress.log"
PID_FILE="/tmp/metamanager-compress-daemon.pid"

JPEGTRAN="/usr/bin/jpegtran"
OPTIPNG="/usr/bin/optipng"

# --- Write PID file so WordPress can check daemon health without systemctl ---
echo $$ > "${PID_FILE}"
trap 'rm -f "${PID_FILE}"' EXIT

# --- Logging ---
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [compress] $*" >> "${LOG_FILE}"
}

log "Daemon started (PID $$). Watching ${JOB_DIR}"

# --- Job processor ---
process_job() {
    local jobfile="$1"
    local tmpfile="${jobfile}.processing"

    # Atomically claim the job file to prevent double-processing.
    mv "${jobfile}" "${tmpfile}" 2>/dev/null || return 0

    local file_path attachment_id size dimensions submitted_at image_name
    file_path=$(jq -r '.file_path'     "${tmpfile}")
    attachment_id=$(jq -r '.attachment_id' "${tmpfile}")
    size=$(jq -r '.size'               "${tmpfile}")
    dimensions=$(jq -r '.dimensions'   "${tmpfile}")
    submitted_at=$(jq -r '.submitted_at' "${tmpfile}")
    image_name=$(jq -r '.image_name'   "${tmpfile}")

    if [[ ! -f "${file_path}" ]]; then
        log "ERROR: file not found: ${file_path}"
        write_result "${tmpfile}" "failed" "File not found: ${file_path}"
        return 1
    fi

    # Per-file lock to prevent concurrent processing of the same image.
    local lockfile="${file_path}.mm.lock"
    exec 9>"${lockfile}"
    if ! flock -n 9; then
        log "LOCKED: ${file_path} — re-queuing"
        mv "${tmpfile}" "${jobfile}"
        exec 9>&-
        return 0
    fi

    local ext="${file_path##*.}"
    ext="${ext,,}"  # lowercase
    local success=false
    local message=""

    case "${ext}" in
        jpg|jpeg)
            if [[ -x "${JPEGTRAN}" ]]; then
                local outfile="${file_path}.mm_tmp"
                # -copy all  : preserve all existing metadata (EXIF, IPTC, XMP, comments)
                # -optimize  : Huffman table optimisation (lossless)
                # -progressive: progressive encoding (lossless reorder)
                if "${JPEGTRAN}" -copy all -optimize -progressive -outfile "${outfile}" "${file_path}" 2>>"${LOG_FILE}"; then
                    # Only replace if the result is smaller (never make files larger).
                    local orig_size new_size
                    orig_size=$(stat -c%s "${file_path}")
                    new_size=$(stat -c%s "${outfile}")
                    if (( new_size < orig_size )); then
                        mv "${outfile}" "${file_path}"
                        message="JPEG lossless compressed: ${orig_size} → ${new_size} bytes"
                    else
                        rm -f "${outfile}"
                        message="JPEG already optimal (${orig_size} bytes)"
                    fi
                    success=true
                else
                    rm -f "${outfile}"
                    message="jpegtran failed for: ${file_path}"
                fi
            else
                message="jpegtran not found at ${JPEGTRAN}"
                log "WARNING: ${message}"
                success=false
            fi
            ;;
        png)
            if [[ -x "${OPTIPNG}" ]]; then
                # -o2         : optimisation level (fast but effective)
                # -preserve   : preserve file timestamps
                # -quiet      : suppress stdout
                if "${OPTIPNG}" -o2 -preserve -quiet "${file_path}" 2>>"${LOG_FILE}"; then
                    message="PNG lossless compressed: ${file_path}"
                    success=true
                else
                    message="optipng failed for: ${file_path}"
                fi
            else
                message="optipng not found at ${OPTIPNG}"
                log "WARNING: ${message}"
                success=false
            fi
            ;;
        *)
            message="Unsupported file type: .${ext} — skipped"
            log "${message}"
            # Treat unsupported as success (nothing to do, don't keep failing).
            success=true
            ;;
    esac

    exec 9>&-
    rm -f "${lockfile}"

    if "${success}"; then
        log "OK: ${message}"
        write_result "${tmpfile}" "completed" "${message}"
    else
        log "FAIL: ${message}"
        write_result "${tmpfile}" "failed" "${message}"
    fi
}

# Write a result JSON file for WP-Cron to pick up.
write_result() {
    local tmpfile="$1"
    local status="$2"
    local message="$3"
    local out_dir

    if [[ "${status}" == "completed" ]]; then
        out_dir="${JOB_DONE}"
    else
        out_dir="${JOB_FAILED}"
    fi

    local result_file="${out_dir}/$(basename "${tmpfile}" .processing)-result.json"

    # Merge the original job JSON with result fields.
    jq --arg status "${status}" \
       --arg msg    "${message}" \
       --arg ts     "$(date '+%Y-%m-%d %H:%M:%S')" \
       '. + {status: $status, completed_at: $ts, details: {message: $msg}}' \
       "${tmpfile}" > "${result_file}" 2>/dev/null || true

    rm -f "${tmpfile}"
}

# --- Main loop: inotifywait for new JSON files ---
inotifywait -m -e close_write --format '%w%f' "${JOB_DIR}" 2>/dev/null \
| while IFS= read -r jobfile; do
    if [[ "${jobfile}" == *.json ]]; then
        process_job "${jobfile}" &
    fi
done
