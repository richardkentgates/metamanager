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

# --- Logging ---
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [compress] $*" >> "${LOG_FILE}"
}

# --- Wait for job queue directory ---
# The plugin creates this on activation. If not present yet, wait for it.
wait_for_job_dir() {
    while [[ ! -d "${JOB_DIR}" ]]; do
        log "Job directory ${JOB_DIR} not found — waiting 10s..."
        sleep 10
    done
    # Ensure subdirectories exist
    mkdir -p "${JOB_DONE}" "${JOB_FAILED}"
}

wait_for_job_dir

# --- Write PID file so WordPress can check daemon health without systemctl ---
mkdir -p "$(dirname "${PID_FILE}")"

# S-10: Check for stale PID file from previous daemon instance
if [[ -f "${PID_FILE}" ]]; then
    old_pid=$(cat "${PID_FILE}" 2>/dev/null)
    if [[ -n "${old_pid}" ]] && kill -0 "${old_pid}" 2>/dev/null; then
        echo "ERROR: Another instance is already running (PID ${old_pid}). Exiting." >&2
        exit 1
    fi
    log "Removing stale PID file (PID ${old_pid} no longer exists)"
    rm -f "${PID_FILE}"
fi

echo $$ > "${PID_FILE}"
trap 'rm -f "${PID_FILE}" "${STATUS_FILE}"' EXIT

log "Daemon started (PID $$). Watching ${JOB_DIR}"
write_status 0 ""

# --- Job processor ---
process_job() {
    local jobfile="$1"
    local tmpfile="${jobfile}.processing"

    # Atomically claim the job file to prevent double-processing.
    mv "${jobfile}" "${tmpfile}" 2>/dev/null || return 0

    local file_path attachment_id size dimensions submitted_at image_name optimize_level
    file_path=$(jq -r '.file_path // empty'         "${tmpfile}") || {
        log "ERROR: malformed JSON in ${tmpfile}"
        write_result "${tmpfile}" "failed" "Malformed job JSON"
        return 1
    }
    attachment_id=$(jq -r '.attachment_id // empty' "${tmpfile}") || {
        log "ERROR: malformed JSON in ${tmpfile}"
        write_result "${tmpfile}" "failed" "Malformed job JSON"
        return 1
    }
    size=$(jq -r '.size // empty'                   "${tmpfile}") || {
        log "ERROR: malformed JSON in ${tmpfile}"
        write_result "${tmpfile}" "failed" "Malformed job JSON"
        return 1
    }
    dimensions=$(jq -r '.dimensions // empty'       "${tmpfile}")
    submitted_at=$(jq -r '.submitted_at // empty'   "${tmpfile}")
    image_name=$(jq -r '.image_name // empty'       "${tmpfile}")
    optimize_level=$(jq -r '.optimize_level // 2'   "${tmpfile}")
    [[ "${optimize_level}" =~ ^[0-7]$ ]] || optimize_level=2

    log "Processing job: ${image_name:-${file_path##*/}} (id: ${attachment_id}, size: ${size}, dimensions: ${dimensions}, submitted: ${submitted_at:-n/a})"

    if [[ ! -f "${file_path}" ]]; then
        log "ERROR: file not found: ${file_path}"
        write_result "${tmpfile}" "failed" "File not found: ${file_path}"
        return 1
    fi

    # S-12: Reject symlinks — only process regular files
    if [[ -L "${file_path}" ]]; then
        log "ERROR: symlink not allowed: ${file_path}"
        write_result "${tmpfile}" "failed" "Symlink not allowed: ${file_path}"
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
    local orig_size=0 new_size=0

    case "${ext}" in
        jpg|jpeg)
            if [[ -x "${JPEGTRAN}" ]]; then
                local outfile="${file_path}.mm_tmp"
                # -copy all  : preserve all existing metadata (EXIF, IPTC, XMP, comments)
                # -optimize  : Huffman table optimisation (lossless)
                # -progressive: progressive encoding (lossless reorder)
                if timeout "${TOOL_TIMEOUT}" "${JPEGTRAN}" -copy all -optimize -progressive -outfile "${outfile}" "${file_path}" 2>>"${LOG_FILE}"; then
                    # Only replace if the result is smaller (never make files larger).
                    orig_size=$(stat -c%s "${file_path}")
                    new_size=$(stat -c%s "${outfile}")
                    if (( new_size < orig_size )); then
                        mv "${outfile}" "${file_path}"
                        message="JPEG lossless compressed: ${orig_size} → ${new_size} bytes"
                    else
                        new_size=${orig_size}
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
                orig_size=$(stat -c%s "${file_path}")
                # -o(n)       : optimisation level (1–7; default 2 — fast but effective)
                # -preserve   : preserve file timestamps
                # -quiet      : suppress stdout
                if timeout "${TOOL_TIMEOUT}" "${OPTIPNG}" -o"${optimize_level}" -preserve -quiet "${file_path}" 2>>"${LOG_FILE}"; then
                    new_size=$(stat -c%s "${file_path}")
                    if (( new_size < orig_size )); then
                        message="PNG lossless compressed: ${orig_size} → ${new_size} bytes"
                    else
                        new_size=${orig_size}
                        message="PNG already optimal (${orig_size} bytes)"
                    fi
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
        webp)
            if [[ -x "${CWEBP}" ]]; then
                local outfile="${file_path}.mm_tmp"
                orig_size=$(stat -c%s "${file_path}")
                # -lossless  : lossless WebP (no quality degradation)
                # -mt        : multi-threading
                # -quiet     : suppress progress output
                if timeout "${TOOL_TIMEOUT}" "${CWEBP}" -lossless -mt -quiet -o "${outfile}" -- "${file_path}" 2>>"${LOG_FILE}"; then
                    new_size=$(stat -c%s "${outfile}")
                    if (( new_size < orig_size )); then
                        mv "${outfile}" "${file_path}"
                        message="WebP lossless compressed: ${orig_size} → ${new_size} bytes"
                    else
                        new_size=${orig_size}
                        rm -f "${outfile}"
                        message="WebP already optimal (${orig_size} bytes)"
                    fi
                    success=true
                else
                    rm -f "${outfile}"
                    message="cwebp failed for: ${file_path}"
                fi
            else
                message="cwebp not found at ${CWEBP}"
                log "WARNING: ${message}"
                success=false
            fi
            ;;
        mp4|m4v|mov|avi|mkv|wmv|webm|ogv|3gp|3gpp|3g2|3gpp2|ts|mts|m2ts|flv)
            # Video remux: repack the container without re-encoding any streams.
            # -c copy           copy ALL streams (video, audio, subtitles, attachments)
            # -map_metadata 0   preserve ALL metadata, including embedded thumbnails
            # -movflags +faststart  move moov atom to front for MP4/MOV (HTTP streaming)
            # -v quiet          suppress informational output
            if command -v ffmpeg &>/dev/null; then
                local outfile="${file_path}.mm_remux_$$.${ext}"
                orig_size=$(stat -c%s "${file_path}")
                if timeout "${TOOL_TIMEOUT}" ffmpeg -y -v quiet -i "${file_path}" -c copy -map_metadata 0 -movflags +faststart "${outfile}" 2>>"${LOG_FILE}"; then
                    new_size=$(stat -c%s "${outfile}")
                    if (( new_size < orig_size )); then
                        mv "${outfile}" "${file_path}"
                        message="Video remuxed: ${orig_size} → ${new_size} bytes"
                    else
                        new_size=${orig_size}
                        rm -f "${outfile}"
                        message="Video already optimal (${orig_size} bytes)"
                    fi
                    success=true
                else
                    rm -f "${outfile}" 2>/dev/null || true
                    message="ffmpeg remux failed for: ${file_path}"
                fi
            else
                # No ffmpeg — mark as already optimal so the job doesn't sit as failed.
                orig_size=$(stat -c%s "${file_path}") || orig_size=0
                new_size=${orig_size}
                message="ffmpeg not found — video remux skipped: ${file_path}"
                log "WARNING: ${message}"
                success=true
            fi
            ;;
        avif)
            if [[ -x "${AVIFENC}" ]]; then
                local outfile="${file_path}.mm_tmp"
                orig_size=$(stat -c%s "${file_path}")
                # --min 0 --max 0  : lossless quantizer range
                # --speed 6        : balanced speed (0=slowest/best, 10=fastest)
                # --lossless       : explicit lossless mode
                if timeout "${TOOL_TIMEOUT}" "${AVIFENC}" --min 0 --max 0 --speed 6 --lossless -o "${outfile}" "${file_path}" 2>>"${LOG_FILE}"; then
                    new_size=$(stat -c%s "${outfile}")
                    if (( new_size < orig_size )); then
                        mv "${outfile}" "${file_path}"
                        message="AVIF lossless compressed: ${orig_size} → ${new_size} bytes"
                    else
                        new_size=${orig_size}
                        rm -f "${outfile}"
                        message="AVIF already optimal (${orig_size} bytes)"
                    fi
                    success=true
                else
                    rm -f "${outfile}"
                    message="avifenc failed for: ${file_path}"
                fi
            else
                orig_size=$(stat -c%s "${file_path}") || orig_size=0
                new_size=${orig_size}
                message="avifenc not found — AVIF compression skipped: ${file_path}"
                log "WARNING: ${message}"
                success=true
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
        write_result "${tmpfile}" "completed" "${message}" "${orig_size}" "${new_size}"
    else
        log "FAIL: ${message}"
        write_result "${tmpfile}" "failed" "${message}" "0" "0"
    fi
}

# Write a result JSON file for WP-Cron to pick up.
# Writes to a .tmp file first, then atomically renames to .json so the
# PHP cron handler never reads a partially-written result file.
write_result() {
    local tmpfile="$1"
    local status="$2"
    local message="$3"
    local bytes_before="${4:-0}"
    local bytes_after="${5:-0}"
    local out_dir

    if [[ "${status}" == "completed" ]]; then
        out_dir="${JOB_DONE}"
    else
        out_dir="${JOB_FAILED}"
    fi

    local result_file
    result_file="${out_dir}/$(basename "${tmpfile}" .processing)-result.json"
    local result_tmp
    result_tmp="${result_file}.tmp"

    # Merge the original job JSON with result fields including compression savings.
    jq --arg  status        "${status}" \
       --arg  msg           "${message}" \
       --arg  ts            "$(date '+%Y-%m-%d %H:%M:%S')" \
       --argjson bytes_before "${bytes_before}" \
       --argjson bytes_after  "${bytes_after}" \
       '. + {status: $status, completed_at: $ts, bytes_before: $bytes_before, bytes_after: $bytes_after, details: {message: $msg}}' \
       "${tmpfile}" > "${result_tmp}" 2>/dev/null || true

    # Atomic rename — only replaces .json once write is complete.
    mv "${result_tmp}" "${result_file}" 2>/dev/null || true
    rm -f "${tmpfile}"
}

# Write daemon status to status.json for WordPress to read.
# Atomic write: .tmp then mv so PHP never reads a partial file.
write_status() {
    local queue_depth="${1:-0}"
    local last_completed="${2:-}"
    local status_tmp="${STATUS_FILE}.tmp"

    jq -n \
        --arg pid            "$$" \
        --argjson queue_depth "${queue_depth}" \
        --arg last_completed  "${last_completed}" \
        --arg updated_at     "$(date '+%Y-%m-%d %H:%M:%S')" \
        '{pid: ($pid | tonumber), queue_depth: $queue_depth, last_completed: $last_completed, updated_at: $updated_at}' \
        > "${status_tmp}" 2>/dev/null || true

    mv "${status_tmp}" "${STATUS_FILE}" 2>/dev/null || true
}

# --- Drain any jobs that were queued while the daemon was offline ---
# S-13: Clean up leftover .mm_tmp files from previous crash
log "Startup scan: cleaning up leftover .mm_tmp files"
for tmpfile in "${JOB_DIR}"/*.mm_tmp; do
    [[ -e "${tmpfile}" ]] || continue
    log "Removing leftover .mm_tmp: $(basename "${tmpfile}")"
    rm -f "${tmpfile}"
done

# Also recover any .json.processing orphans left behind by a previous crash.
log "Startup scan: processing any pre-existing jobs in ${JOB_DIR}"
for orphan in "${JOB_DIR}"/*.json.processing; do
    [[ -e "${orphan}" ]] || continue
    recovered="${orphan%.processing}"
    mv "${orphan}" "${recovered}" 2>/dev/null || true
    log "Recovered orphaned job: $(basename "${recovered}")"
done
# Loop until the queue is empty.  Concurrent jobs may get LOCKED (e.g. by the
# metadata daemon) and re-queue themselves back as *.json.  Those files land
# before inotifywait starts, so they would never be picked up without this loop.
_pass=0
_max_passes=30
while (( _pass < _max_passes )); do
    _pending=()
    for _f in "${JOB_DIR}"/*.json; do
        [[ -e "${_f}" ]] && _pending+=( "${_f}" )
    done
    [[ ${#_pending[@]} -eq 0 ]] && break
    (( ++_pass ))

    # Sort by priority (descending) — higher priority jobs process first.
    _sorted=()
    while IFS= read -r line; do
        _sorted+=( "$line" )
    done < <(
        for _f in "${_pending[@]}"; do
            _pri=$(jq -r '.priority // 0' "${_f}" 2>/dev/null) || _pri=0
            printf '%s\t%s\n' "${_pri}" "${_f}"
        done | sort -t$'\t' -k1,1nr -k2,2 | cut -f2
    )

    log "Startup scan pass ${_pass}: ${#_sorted[@]} job(s)"
    for jobfile in "${_sorted[@]}"; do
        while (( $(jobs -rp | wc -l) >= MAX_CONCURRENT )); do
            wait -n 2>/dev/null || true
        done
        process_job "${jobfile}" &
    done
    wait || true
    write_status "${#_sorted[@]}" ""
    # Brief pause between passes so lock-contention with other daemons can clear.
    sleep 2
done
unset _pass _max_passes _pending _f _sorted _pri
write_status 0 ""
log "Startup scan complete."

# --- Main loop: inotifywait for new JSON files ---
# close_write: new job written by PHP
# moved_to:    LOCKED re-queue — daemon renames .processing back to .json via mv
while true; do
    if [[ ! -d "${JOB_DIR}" ]]; then
        log "Job directory ${JOB_DIR} disappeared — waiting..."
        wait_for_job_dir
        log "Job directory reappeared — resuming."
    fi
    inotifywait -m -e close_write,moved_to --format '%w%f' "${JOB_DIR}" 2>/dev/null \
    | while IFS= read -r jobfile; do
        if [[ "${jobfile}" == *.json ]]; then
            while (( $(jobs -rp | wc -l) >= MAX_CONCURRENT )); do
                wait -n 2>/dev/null || true
            done
            process_job "${jobfile}" &
            # Update status with current queue depth (count remaining .json files).
            _qdepth=$(find "${JOB_DIR}" -maxdepth 1 -name '*.json' 2>/dev/null | wc -l)
            write_status "${_qdepth}" "$(date '+%Y-%m-%d %H:%M:%S')"
        fi
    done
    # If inotifywait exits (e.g. directory deleted), wait and retry
    log "inotifywait exited — retrying in 5s..."
    sleep 5
done
