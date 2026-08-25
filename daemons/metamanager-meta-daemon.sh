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
process_job() {
    local jobfile="$1"
    local tmpfile="${jobfile}.processing"

    mv "${jobfile}" "${tmpfile}" 2>/dev/null || return 0

    local file_path attachment_id size metadata_json
    file_path=$(jq -r '.file_path // empty'   "${tmpfile}") || {
        log "ERROR: malformed JSON in ${tmpfile}"
        write_result "${tmpfile}" "failed" "Malformed job JSON"
        return 1
    }
    attachment_id=$(jq -r '.attachment_id // empty' "${tmpfile}") || {
        log "ERROR: malformed JSON in ${tmpfile}"
        write_result "${tmpfile}" "failed" "Malformed job JSON"
        return 1
    }
    size=$(jq -r '.size // empty'             "${tmpfile}") || {
        log "ERROR: malformed JSON in ${tmpfile}"
        write_result "${tmpfile}" "failed" "Malformed job JSON"
        return 1
    }
    metadata_json=$(jq -c '.metadata // {}'  "${tmpfile}") || {
        log "ERROR: malformed JSON in ${tmpfile}"
        write_result "${tmpfile}" "failed" "Malformed job JSON"
        return 1
    }

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

    # ---- Import job: read embedded tags and report back ----
    # The PHP side (WP-Cron result handler) applies the returned tags to WP post meta.
    local job_type
    job_type=$(jq -r '.job_type // "metadata"' "${tmpfile}")

    if [[ "${job_type}" == "import" ]]; then
        local embedded_json
        # -a  : allow duplicate tags (e.g. multiple Keywords entries)
        # -G1 : include group name prefix (Group:Tag) for disambiguation
        # -s  : tag names, not descriptions
        # -j  : JSON output (array with one element per file)
        embedded_json=$( "${EXIFTOOL}" -a -G1 -s -j "${file_path}" 2>/dev/null | jq -c '.[0] // {}' )
        local et_exit=$?
        exec 9>&-; rm -f "${lockfile}"
        if [[ ${et_exit} -ne 0 ]] || [[ -z "${embedded_json}" ]]; then
            write_result "${tmpfile}" "failed" "ExifTool read failed for: ${file_path}"
            return 1
        fi
        # Merge the embedded tag map into the result JSON under "embedded_tags".
        # Write to .tmp then atomically rename to prevent partial reads by cron.
        local _import_result
        _import_result="${JOB_DONE}/$(basename "${tmpfile}" .processing)-result.json"
        jq --arg status "completed" \
           --arg msg    "Read embedded tags from ${file_path}" \
           --arg ts     "$(date '+%Y-%m-%d %H:%M:%S')" \
           --argjson et  "${embedded_json}" \
           '. + {status: $status, completed_at: $ts, details: {message: $msg}, embedded_tags: $et}' \
           "${tmpfile}" > "${_import_result}.tmp" 2>/dev/null || true
        mv "${_import_result}.tmp" "${_import_result}" 2>/dev/null || true
        rm -f "${tmpfile}"
        log "OK: import read $(echo "${embedded_json}" | jq 'keys | length') tag(s) from ${file_path}"
        return 0
    fi

    # Determine file category from extension — drives the tag writing strategy.
    # ExifTool routes generic tags (e.g. -Title) to the correct namespace per format,
    # but explicitly-namespaced IPTC/EXIF image tags fail on video/audio containers.
    local ext="${file_path##*.}"
    ext="${ext,,}"
    local file_cat
    case "${ext}" in
        jpg|jpeg|png|webp|avif|gif|tiff|tif) file_cat="image"     ;;
        mp3)                             file_cat="mp3"       ;;
        mp4|m4v|m4a|mov|3gp|3gpp|3g2)  file_cat="quicktime" ;;
        ogg|oga|flac)                   file_cat="vorbis"    ;;
        avi|wav|wmv|wma|pdf)             file_cat="xmp_only"  ;;
        mkv|webm|ogv)
            exec 9>&-; rm -f "${lockfile}"
            write_result "${tmpfile}" "completed" "Read-only format — metadata embedding skipped: ${file_path}"
            return 0
            ;;
        *)                              file_cat="image"     ;;
    esac

    # Build ExifTool argument list from the logical field map.
    # Each logical field maps to one or more ExifTool tag assignments.
    # Tags are written with -TAG=VALUE syntax; empty/null values are skipped.
    local -a exif_args

    get_val() { echo "${metadata_json}" | jq -r --arg k "$1" '.[$k] // empty'; }

    append_tag() {
        local tag="$1" value="$2"
        if [[ -n "${value}" ]]; then
            exif_args+=( "-${tag}=${value}" )
        fi
    }

    local v

    # ---- Format-aware tag building ----

    if [[ "${file_cat}" == "image" ]]; then
        # Full EXIF + IPTC + XMP for images.
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

        v=$(get_val "Publisher");   append_tag "XMP:Publisher"           "${v}"

        v=$(get_val "Website");     append_tag "XMP:WebStatement"        "${v}"
        v=$(get_val "Headline");    append_tag "IPTC:Headline"            "${v}"
                                     append_tag "XMP:Headline"            "${v}"
        v=$(get_val "Credit");      append_tag "IPTC:Credit"              "${v}"
                                     append_tag "XMP:Credit"              "${v}"

        IFS='; ' read -ra _kw_arr <<< "$(get_val 'Keywords')"
        for _kw in "${_kw_arr[@]}"; do
            [[ -n "${_kw}" ]] && exif_args+=( "-IPTC:Keywords+=${_kw}" "-XMP:Subject+=${_kw}" )
        done
        unset _kw_arr _kw

        v=$(get_val "DateCreated"); append_tag "EXIF:DateTimeOriginal"   "${v}"
                                     append_tag "IPTC:DateCreated"        "${v}"
                                     append_tag "XMP:DateCreated"         "${v}"

        v=$(get_val "Rating");      append_tag "XMP:Rating"              "${v}"

        v=$(get_val "City");        append_tag "IPTC:City"               "${v}"
                                     append_tag "XMP:City"               "${v}"
        v=$(get_val "State");       append_tag "IPTC:Province-State"     "${v}"
                                     append_tag "XMP:State"              "${v}"
        v=$(get_val "Country");     append_tag "IPTC:Country-PrimaryLocationName" "${v}"
                                     append_tag "XMP:Country"            "${v}"

        # IPTC:Source shared between Publisher and Website.
        v_pub=$(get_val "Publisher"); v_web=$(get_val "Website")
        if [[ -n "${v_web}" ]]; then append_tag "IPTC:Source" "${v_web}"
        elif [[ -n "${v_pub}" ]]; then append_tag "IPTC:Source" "${v_pub}"; fi

    elif [[ "${file_cat}" == "mp3" ]]; then
        # ID3v2 tags (ExifTool maps generic names to ID3 for MP3) + XMP.
        v=$(get_val "Title");       append_tag "ID3:Title"               "${v}"; append_tag "XMP:Title"       "${v}"
        v=$(get_val "Creator");     append_tag "ID3:Artist"              "${v}"; append_tag "XMP:Creator"     "${v}"
        v=$(get_val "Copyright");   append_tag "ID3:Copyright"           "${v}"; append_tag "XMP:Rights"      "${v}"
        v=$(get_val "Description"); append_tag "ID3:Comment"             "${v}"; append_tag "XMP:Description" "${v}"
        v=$(get_val "Publisher");   append_tag "ID3:Band"                "${v}"; append_tag "XMP:Publisher"   "${v}"
        v=$(get_val "Headline");    append_tag "XMP:Headline"            "${v}"
        v=$(get_val "Credit");      append_tag "XMP:Credit"              "${v}"
        v=$(get_val "DateCreated"); append_tag "ID3:Year"                "${v}"; append_tag "XMP:DateCreated" "${v}"
        v=$(get_val "Website");     append_tag "XMP:WebStatement"        "${v}"
        v=$(get_val "Rating");      append_tag "XMP:Rating"              "${v}"
        IFS='; ' read -ra _kw_arr <<< "$(get_val 'Keywords')"
        for _kw in "${_kw_arr[@]}"; do
            [[ -n "${_kw}" ]] && exif_args+=( "-ID3:Genre+=${_kw}" "-XMP:Subject+=${_kw}" )
        done
        unset _kw_arr _kw

    elif [[ "${file_cat}" == "quicktime" ]]; then
        # QuickTime/iTunes atom tags for MP4, MOV, M4A, etc. + XMP.
        v=$(get_val "Title");       append_tag "QuickTime:Title"         "${v}"; append_tag "XMP:Title"       "${v}"
        v=$(get_val "Creator");     append_tag "QuickTime:Author"        "${v}"; append_tag "XMP:Creator"     "${v}"
        v=$(get_val "Copyright");   append_tag "QuickTime:Copyright"     "${v}"; append_tag "XMP:Rights"      "${v}"
        v=$(get_val "Description"); append_tag "QuickTime:Description"   "${v}"; append_tag "XMP:Description" "${v}"
        v=$(get_val "Publisher");   append_tag "XMP:Publisher"           "${v}"
        v=$(get_val "Website");     append_tag "XMP:WebStatement"        "${v}"
        v=$(get_val "Headline");    append_tag "XMP:Headline"            "${v}"
        v=$(get_val "Credit");      append_tag "XMP:Credit"              "${v}"
        v=$(get_val "DateCreated"); append_tag "QuickTime:CreateDate"    "${v}"; append_tag "XMP:DateCreated" "${v}"
        v=$(get_val "Rating");      append_tag "XMP:Rating"              "${v}"
        v=$(get_val "City");        append_tag "XMP:City"                "${v}"
        v=$(get_val "State");       append_tag "XMP:State"               "${v}"
        v=$(get_val "Country");     append_tag "XMP:Country"             "${v}"
        IFS='; ' read -ra _kw_arr <<< "$(get_val 'Keywords')"
        for _kw in "${_kw_arr[@]}"; do
            [[ -n "${_kw}" ]] && exif_args+=( "-QuickTime:Keywords+=${_kw}" "-XMP:Subject+=${_kw}" )
        done
        unset _kw_arr _kw

    elif [[ "${file_cat}" == "vorbis" ]]; then
        # Vorbis comment tags for OGG/FLAC. ExifTool writes these natively via
        # the Ogg: or FLAC: namespace; generic names are routed correctly.
        v=$(get_val "Title");       append_tag "Title"                   "${v}"
        v=$(get_val "Creator");     append_tag "Artist"                  "${v}"
        v=$(get_val "Copyright");   append_tag "Copyright"               "${v}"
        v=$(get_val "Description"); append_tag "Description"             "${v}"
        v=$(get_val "Publisher");   append_tag "Organization"            "${v}"
        v=$(get_val "DateCreated"); append_tag "Date"                    "${v}"
        v=$(get_val "Headline");    append_tag "XMP:Headline"            "${v}"
        IFS='; ' read -ra _kw_arr <<< "$(get_val 'Keywords')"
        for _kw in "${_kw_arr[@]}"; do
            [[ -n "${_kw}" ]] && exif_args+=( "-Genre+=${_kw}" )
        done
        unset _kw_arr _kw

    else
        # xmp_only (AVI, WAV, WMV, WMA, PDF) — XMP is the only reliable namespace.
        v=$(get_val "Title");       append_tag "XMP:Title"               "${v}"
        v=$(get_val "Creator");     append_tag "XMP:Creator"             "${v}"
        v=$(get_val "Copyright");   append_tag "XMP:Rights"              "${v}"
        v=$(get_val "Description"); append_tag "XMP:Description"         "${v}"
        v=$(get_val "Publisher");   append_tag "XMP:Publisher"           "${v}"
        v=$(get_val "Website");     append_tag "XMP:WebStatement"        "${v}"
        v=$(get_val "Headline");    append_tag "XMP:Headline"            "${v}"
        v=$(get_val "Credit");      append_tag "XMP:Credit"              "${v}"
        v=$(get_val "DateCreated"); append_tag "XMP:DateCreated"         "${v}"
        v=$(get_val "Rating");      append_tag "XMP:Rating"              "${v}"
        v=$(get_val "City");        append_tag "XMP:City"                "${v}"
        v=$(get_val "State");       append_tag "XMP:State"               "${v}"
        v=$(get_val "Country");     append_tag "XMP:Country"             "${v}"
        IFS='; ' read -ra _kw_arr <<< "$(get_val 'Keywords')"
        for _kw in "${_kw_arr[@]}"; do
            [[ -n "${_kw}" ]] && exif_args+=( "-XMP:Subject+=${_kw}" )
        done
        unset _kw_arr _kw
    fi
    local success=false message=""

    if [[ ${#exif_args[@]} -eq 0 ]]; then
        message="No metadata fields to embed — skipped"
        log "${message}: ${file_path}"
        success=true
    else
        # -overwrite_original: modify file in-place without creating _original backup
        # -charset iptc=UTF8 : only relevant for image formats (IPTC namespace)
        local -a et_base=( "${EXIFTOOL}" -overwrite_original )
        [[ "${file_cat}" == "image" ]] && et_base+=( -charset iptc=UTF8 )
        if timeout "${TOOL_TIMEOUT}" "${et_base[@]}" "${exif_args[@]}" "${file_path}" >>"${LOG_FILE}" 2>&1; then
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

# --- Drain queued jobs and enter main loop ---
drain_jobs_loop
run_main_loop
