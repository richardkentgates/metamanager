#!/usr/bin/env bash
# =============================================================================
# Shared daemon functions for MetaManager daemons.
#
# Sourced by metamanager-compress-daemon.sh and metamanager-meta-daemon.sh.
# Requires the following variables to be set BEFORE sourcing:
#   JOB_DIR, JOB_DONE, JOB_FAILED, LOG_FILE, PID_FILE, STATUS_FILE,
#   MAX_CONCURRENT, DAEMON_TAG
# =============================================================================

# --- Logging ---
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [${DAEMON_TAG}] $*" >> "${LOG_FILE}"
}

# --- Write daemon status to status.json for WordPress to read ---
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

# --- PID file management ---
# Write PID file so WordPress can check daemon health without systemctl.
# Checks for and removes stale PID files from previous instances.
setup_pid_file() {
    mkdir -p "$(dirname "${PID_FILE}")"

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
}

# --- Write a result JSON file for WP-Cron to pick up ---
# Writes to a .tmp file first, then atomically renames to .json so the
# PHP cron handler never reads a partially-written result file.
# Usage: write_result <tmpfile> <status> <message> [bytes_before] [bytes_after]
write_result() {
    local tmpfile="$1"
    local status="$2"
    local message="$3"
    local bytes_before="${4:-}"
    local bytes_after="${5:-}"
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

    if [[ -n "${bytes_before}" && -n "${bytes_after}" ]]; then
        jq --arg  status        "${status}" \
           --arg  msg           "${message}" \
           --arg  ts            "$(date '+%Y-%m-%d %H:%M:%S')" \
           --argjson bytes_before "${bytes_before}" \
           --argjson bytes_after  "${bytes_after}" \
           '. + {status: $status, completed_at: $ts, bytes_before: $bytes_before, bytes_after: $bytes_after, details: {message: $msg}}' \
           "${tmpfile}" > "${result_tmp}" 2>/dev/null || true
    else
        jq --arg status "${status}" \
           --arg msg    "${message}" \
           --arg ts     "$(date '+%Y-%m-%d %H:%M:%S')" \
           '. + {status: $status, completed_at: $ts, details: {message: $msg}}' \
           "${tmpfile}" > "${result_tmp}" 2>/dev/null || true
    fi

    # Atomic rename — only replaces .json once write is complete.
    mv "${result_tmp}" "${result_file}" 2>/dev/null || true
    rm -f "${tmpfile}"
}

# --- Drain jobs queued while daemon was offline ---
# Cleans up leftover .mm_tmp files, recovers orphaned .json.processing files,
# then loops until the queue is empty. Concurrent jobs may get LOCKED by
# another daemon and re-queued as *.json, so multiple passes are needed.
drain_jobs_loop() {
    log "Startup scan: cleaning up leftover .mm_tmp files"
    for tmpfile in "${JOB_DIR}"/*.mm_tmp; do
        [[ -e "${tmpfile}" ]] || continue
        log "Removing leftover .mm_tmp: $(basename "${tmpfile}")"
        rm -f "${tmpfile}"
    done

    log "Startup scan: processing any pre-existing jobs in ${JOB_DIR}"
    for orphan in "${JOB_DIR}"/*.json.processing; do
        [[ -e "${orphan}" ]] || continue
        recovered="${orphan%.processing}"
        mv "${orphan}" "${recovered}" 2>/dev/null || true
        log "Recovered orphaned job: $(basename "${recovered}")"
    done

    _pass=0
    _max_passes=30
    while (( _pass < _max_passes )); do
        _pending=()
        for _f in "${JOB_DIR}"/*.json; do
            [[ -e "${_f}" ]] && _pending+=( "${_f}" )
        done
        [[ ${#_pending[@]} -eq 0 ]] && break
        (( ++_pass ))

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
        sleep 2
    done
    unset _pass _max_passes _pending _f _sorted _pri
    write_status 0 ""
    log "Startup scan complete."
}

# --- Main loop: inotifywait for new JSON files ---
# close_write: new job written by PHP
# moved_to:    LOCKED re-queue — daemon renames .processing back to .json via mv
run_main_loop() {
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
                _qdepth=$(find "${JOB_DIR}" -maxdepth 1 -name '*.json' 2>/dev/null | wc -l)
                write_status "${_qdepth}" "$(date '+%Y-%m-%d %H:%M:%S')"
            fi
        done
        log "inotifywait exited — retrying in 5s..."
        sleep 5
    done
}
