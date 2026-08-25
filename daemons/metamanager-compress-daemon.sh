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

# --- Drain queued jobs and enter main loop ---
drain_jobs_loop
run_main_loop
