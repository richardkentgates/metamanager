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
#   Website     → XMP:WebStatement,  IPTC:Source
#
# install.sh patches JOB_ROOT to match the actual WP_CONTENT_DIR on this server.
# =============================================================================

set -euo pipefail

# --- Configuration (patched by install.sh) ---
JOB_ROOT="__WP_CONTENT_DIR__/metamanager-jobs"
JOB_DIR="${JOB_ROOT}/meta"
JOB_DONE="${JOB_ROOT}/completed"
JOB_FAILED="${JOB_ROOT}/failed"
LOG_FILE="/var/log/metamanager-meta.log"
PID_FILE="/tmp/metamanager-meta-daemon.pid"

EXIFTOOL="/usr/bin/exiftool"

# --- Write PID file so WordPress can check daemon health without systemctl ---
echo $$ > "${PID_FILE}"
trap 'rm -f "${PID_FILE}"' EXIT

# --- Logging ---
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [meta] $*" >> "${LOG_FILE}"
}

log "Daemon started (PID $$). Watching ${JOB_DIR}"

# --- Job processor ---
process_job() {
    local jobfile="$1"
    local tmpfile="${jobfile}.processing"

    mv "${jobfile}" "${tmpfile}" 2>/dev/null || return 0

    local file_path attachment_id size metadata_json
    file_path=$(jq -r '.file_path'   "${tmpfile}")
    attachment_id=$(jq -r '.attachment_id' "${tmpfile}")
    size=$(jq -r '.size'             "${tmpfile}")
    metadata_json=$(jq -c '.metadata // {}' "${tmpfile}")

    if [[ ! -f "${file_path}" ]]; then
        log "ERROR: file not found: ${file_path}"
        write_result "${tmpfile}" "failed" "File not found: ${file_path}"
        return 1
    fi

    if [[ ! -x "${EXIFTOOL}" ]]; then
        log "ERROR: exiftool not found at ${EXIFTOOL}"
        write_result "${tmpfile}" "failed" "ExifTool not found"
        return 1
    fi

    # Per-file lock.
    local lockfile="${file_path}.mm.lock"
    exec 9>"${lockfile}"
    if ! flock -n 9; then
        log "LOCKED: ${file_path} — re-queuing"
        mv "${tmpfile}" "${jobfile}"
        exec 9>&-
        return 0
    fi

    # Build ExifTool argument list from the logical field map.
    # Each logical field maps to one or more ExifTool tag assignments.
    # Tags are written with -TAG=VALUE syntax; empty/null values are skipped.
    local -a exif_args

    get_val() { echo "${metadata_json}" | jq -r --arg k "$1" '.[$k] // empty'; }

    append_tag() {
        local tag="$1" value="$2"
        [[ -n "${value}" ]] && exif_args+=( "-${tag}=${value}" )
    }

    local v

    v=$(get_val "Title");       append_tag "Title"                   "${v}"
                                 append_tag "IPTC:ObjectName"         "${v}"
                                 append_tag "XMP:Title"               "${v}"

    v=$(get_val "Description"); append_tag "EXIF:ImageDescription"   "${v}"
                                 append_tag "IPTC:Caption-Abstract"   "${v}"
                                 append_tag "XMP:Description"         "${v}"

    v=$(get_val "Caption");     append_tag "IPTC:Caption-Abstract"   "${v}"
                                 append_tag "XMP:Caption"             "${v}"

    v=$(get_val "AltText");     append_tag "XMP:AltTextAccessibility" "${v}"

    v=$(get_val "Creator");     append_tag "EXIF:Artist"             "${v}"
                                 append_tag "IPTC:By-line"            "${v}"
                                 append_tag "XMP:Creator"             "${v}"

    v=$(get_val "Copyright");   append_tag "EXIF:Copyright"          "${v}"
                                 append_tag "IPTC:CopyrightNotice"    "${v}"
                                 append_tag "XMP:Rights"              "${v}"

    v=$(get_val "Owner");       append_tag "EXIF:OwnerName"          "${v}"
                                 append_tag "XMP:Owner"               "${v}"

    v=$(get_val "Publisher");   append_tag "IPTC:Source"             "${v}"
                                 append_tag "XMP:Publisher"           "${v}"

    v=$(get_val "Website");     append_tag "XMP:WebStatement"        "${v}"

    # --- Editorial ---
    v=$(get_val "Headline");    append_tag "IPTC:Headline"                      "${v}"
                                 append_tag "XMP:Headline"                       "${v}"

    v=$(get_val "Credit");     append_tag "IPTC:Credit"                        "${v}"
                                 append_tag "XMP:Credit"                         "${v}"

    # --- Classification ---
    # Keywords: stored semicolon-separated; write each as a separate multi-value tag.
    IFS='; ' read -ra _kw_arr <<< "$(get_val 'Keywords')"
    for _kw in "${_kw_arr[@]}"; do
        [[ -n "${_kw}" ]] && exif_args+=( "-IPTC:Keywords+=${_kw}" "-XMP:Subject+=${_kw}" )
    done
    unset _kw_arr _kw

    v=$(get_val "DateCreated"); append_tag "EXIF:DateTimeOriginal"             "${v}"
                                 append_tag "IPTC:DateCreated"                   "${v}"
                                 append_tag "XMP:DateCreated"                    "${v}"

    v=$(get_val "Rating");     append_tag "XMP:Rating"                          "${v}"

    # --- Location (IPTC Photo Metadata Standard) ---
    v=$(get_val "City");        append_tag "IPTC:City"                           "${v}"
                                 append_tag "XMP:City"                            "${v}"

    v=$(get_val "State");       append_tag "IPTC:Province-State"                "${v}"
                                 append_tag "XMP:State"                           "${v}"

    v=$(get_val "Country");     append_tag "IPTC:Country-PrimaryLocationName"   "${v}"
                                 append_tag "XMP:Country"                         "${v}"

    # Note: IPTC:Source is shared with Publisher — write whichever is present.
    # If both Publisher and Website are set, Website takes precedence for IPTC:Source.
    v_pub=$(get_val "Publisher")
    v_web=$(get_val "Website")
    if [[ -n "${v_web}" ]]; then
        append_tag "IPTC:Source" "${v_web}"
    elif [[ -n "${v_pub}" ]]; then
        append_tag "IPTC:Source" "${v_pub}"
    fi

    local success=false message=""

    if [[ ${#exif_args[@]} -eq 0 ]]; then
        message="No metadata fields to embed — skipped"
        log "${message}: ${file_path}"
        success=true
    else
        # -overwrite_original: modify file in-place without creating _original backup
        # -charset iptc=UTF8 : ensure IPTC strings are written as UTF-8
        if "${EXIFTOOL}" -overwrite_original -charset iptc=UTF8 \
                         "${exif_args[@]}" "${file_path}" >>"${LOG_FILE}" 2>&1; then
            message="Embedded ${#exif_args[@]} tag(s) in ${file_path}"
            log "OK: ${message} (size: ${size}, id: ${attachment_id})"
            success=true
        else
            message="ExifTool failed for: ${file_path}"
            log "FAIL: ${message}"
        fi
    fi

    exec 9>&-
    rm -f "${lockfile}"

    if "${success}"; then
        write_result "${tmpfile}" "completed" "${message}"
    else
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

    jq --arg status "${status}" \
       --arg msg    "${message}" \
       --arg ts     "$(date '+%Y-%m-%d %H:%M:%S')" \
       '. + {status: $status, completed_at: $ts, details: {message: $msg}}' \
       "${tmpfile}" > "${result_file}" 2>/dev/null || true

    rm -f "${tmpfile}"
}

# --- Main loop ---
inotifywait -m -e close_write --format '%w%f' "${JOB_DIR}" 2>/dev/null \
| while IFS= read -r jobfile; do
    if [[ "${jobfile}" == *.json ]]; then
        process_job "${jobfile}" &
    fi
done
