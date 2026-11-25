#!/usr/bin/env bash
# =============================================================================
# Shared daemon functions for MetaManager daemons.
#
# Sourced by metamanager-compress-daemon.sh and metamanager-meta-daemon.sh.
# Requires the following variables to be set BEFORE sourcing:
#   JOB_DIR, JOB_DONE, JOB_FAILED, LOG_FILE, PID_FILE, STATUS_FILE,
#   WP_ROOT (derived from JOB_DIR if not already set).
#   MAX_CONCURRENT, DAEMON_TAG
# =============================================================================

# --- Logging ---
# Derive WP_ROOT from JOB_DIR (compress/meta/webp -> metamanager-jobs -> wp-content -> root).
if [[ -z "${WP_ROOT:-}" ]] && [[ -n "${JOB_DIR:-}" ]]; then
    WP_ROOT="$(dirname "$(dirname "$(dirname "${JOB_DIR}")")")"
fi

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
    # DB-only mode: no job directories needed
    return 0
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
    local job_type="$1"
    local job_id="$2"
    local status="$3"
    local message="${4:-}"
    finish_job_db "${job_type}" "${job_id}" "${status}" "${message}"
}

process_result() {
    # Result is already written to DB by finish_job. Nothing more to do.
    return 0
}

mxroute_pending_count() {
    local wp_root count
    wp_root="${WP_ROOT}"
    if [[ ! -f "${wp_root}/wp-load.php" && ! -f "${wp_root}/wp-config.php" ]]; then
        echo 0
        return 0
    fi
    command -v wp >/dev/null 2>&1 || { echo 0; return 0; }
    count=$(sudo -u www-data wp --path="${wp_root}" mxroute queue count 2>/dev/null | tr -d '[:space:]')
    if [[ "${count}" =~ ^[0-9]+$ ]]; then
        echo "${count}"
        return 0
    fi
    [[ -n "${count}" ]] && log "MXRoute queue count unreadable (got: ${count:0:40}) — treating as idle"
    echo 0
}

# --- Cross-process gating (GCM busy state + MXRoute queue) ---
# Receiving jobs is NEVER prevented. Processing pauses while another gated
# process is working — the GCM busy scopes: backup, the OS maintenance
# window (maint), a standalone update (update), a standalone security scan
# (scan), or restore — or MXRoute Mailer has queued mail. Uninstalled
# software = idle — move on without it. Queued job files stay in place and
# are processed when the gate clears.
gate_allows_processing() {
    local f
    for f in /var/lib/gcm/busy/backup /var/lib/gcm/busy/maint /var/lib/gcm/busy/update /var/lib/gcm/busy/scan /var/lib/gcm/busy/restore; do
        if [[ -f "${f}" ]]; then
            log "Gate: $(basename "${f}") running — processing paused"
            return 1
        fi
    done
    # MXRoute Mailer pending count — read the queue DB via WP-CLI
    local mxr_pending
    mxr_pending=$(mxroute_pending_count)
    if [[ "${mxr_pending}" =~ ^[0-9]+$ ]] && (( mxr_pending > 0 )); then
        log "Gate: MXRoute Mailer has ${mxr_pending} pending — processing paused"
        return 1
    fi
    return 0
}

# Block until the gate opens. Jobs keep arriving while we wait.
gate_wait_for_turn() {
    local waited=false
    until gate_allows_processing && gate_memory_ok; do
        waited=true
        sleep 30
    done
    if "${waited}"; then
        log "Gate clear — resuming processing"
    fi
    return 0
}

# Memory check: the daemon decides how many jobs to process based on
# available system memory. Reads /proc/meminfo directly — no PHP round-trip.
# Returns 0 (proceed) if sufficient memory, 1 (defer) if memory is low.
gate_memory_ok() {
    local mem_available_kb
    mem_available_kb=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo 2>/dev/null)
    if [[ -z "${mem_available_kb}" ]]; then
        return 0  # Cannot determine — proceed (rule 14: absence is not evidence)
    fi
    local mem_available_mb=$(( mem_available_kb / 1024 ))
    # Defer if less than 256MB available.
    if (( mem_available_mb < 256 )); then
        log "Memory low (${mem_available_mb}MB available) — deferring job"
        return 1
    fi
    return 0
}

# Calculate optimal concurrency based on available memory.
# Each job costs roughly 50MB (image processing). Adjusts MAX_CONCURRENT
# dynamically — no hardcoded batch size. Fluid every pass.
gate_calculate_concurrency() {
    local mem_available_kb
    mem_available_kb=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo 2>/dev/null)
    if [[ -z "${mem_available_kb}" ]]; then
        echo "${MAX_CONCURRENT:-4}"  # Cannot determine — use default
        return
    fi
    local mem_available_mb=$(( mem_available_kb / 1024 ))
    # Each job ~50MB. Leave 256MB headroom for OS/PHP.
    local safe_mb=$(( mem_available_mb - 256 ))
    if (( safe_mb < 50 )); then
        echo 1
        return
    fi
    local concurrency=$(( safe_mb / 50 ))
    # Cap at MAX_CONCURRENT if set, otherwise no cap.
    if [[ -n "${MAX_CONCURRENT:-}" ]] && (( concurrency > MAX_CONCURRENT )); then
        concurrency=${MAX_CONCURRENT}
    fi
    echo "${concurrency}"
}

# --- Job router: dispatch by job_type ---
# Reads the job_type from the JSON and routes to the right processor.
# --- DB-based job processing ---
# DB is the single source of truth. No file queues.

# Claim and process a single job from the DB for the given job type.
# Returns 0 if a job was processed, 1 if queue is empty.
claim_and_process() {
    local job_type="$1"
    local job_json
    job_json=$(wp --path="${WP_ROOT}" eval "global \$wpdb; \$t = MM_DB::table_for('${job_type}'); \$r = \$wpdb->get_row(\$wpdb->prepare('SELECT * FROM %i WHERE status = \\'pending\\' ORDER BY submitted_at ASC, id ASC LIMIT 1', \$t), ARRAY_A); if (\$r) { \$wpdb->query(\$wpdb->prepare('UPDATE %i SET status = \\'processing\\' WHERE id = %d AND status = \\'pending\\'', \$t, \$r['id'])); echo json_encode(\$r); }" 2>/dev/null)

    if [[ -z "${job_json}" || "${job_json}" == "null" ]]; then
        return 1
    fi

    dispatch_job_json "${job_type}" "${job_json}"
    return 0
}

# Process a job from JSON (DB row).
dispatch_job_json() {
    local job_type="$1"
    local job_json="$2"

    case "${job_type}" in
        webp)
            if declare -F process_webp_job_json >/dev/null 2>&1; then
                process_webp_job_json "${job_json}"
            else
                log "ERROR: webp processor not defined"
            fi
            ;;
        compression)
            if declare -F process_job_json >/dev/null 2>&1; then
                process_job_json "${job_json}"
            else
                log "ERROR: compression processor not defined"
            fi
            ;;
        metadata)
            if declare -F process_meta_job_json >/dev/null 2>&1; then
                process_meta_job_json "${job_json}"
            else
                log "ERROR: metadata processor not defined"
            fi
            ;;
        *)
            log "ERROR: unknown job type: ${job_type}"
            ;;
    esac
}

# Finish a job in the DB (completed or failed).
finish_job_db() {
    local job_type="$1"
    local job_id="$2"
    local status="$3"
    local details="${4:-}"
    wp --path="${WP_ROOT}" eval "MM_DB::finish_job('${job_type}', ${job_id}, '${status}', addslashes('${details}'));" 2>/dev/null || true
}

# --- Drain jobs queued while daemon was offline ---
# DB queue mode: startup scan not needed. Jobs persist in DB.
drain_jobs_loop() {
    log "DB queue mode: startup scan not needed"
    write_status 0 ""
}

# --- Main loop: DB polling ---
run_main_loop() {
    local job_type="${DAEMON_JOB_TYPE:-webp}"
    local poll_interval=2
    local idle_count=0

    log "DB polling loop started for ${job_type} (interval: ${poll_interval}s)"

    while true; do
        gate_wait_for_turn

        if claim_and_process "${job_type}"; then
            idle_count=0
            local qdepth
            qdepth=$(wp --path="${WP_ROOT}" eval "echo MM_DB::count_by_status('${job_type}', 'pending');" 2>/dev/null || echo 0)
            write_status "${qdepth}" "$(date '+%Y-%m-%d %H:%M:%S')"
        else
            (( idle_count++ ))
            # Back off when idle: 2s → 5s → 10s max
            if (( idle_count > 50 )); then
                sleep 10
            elif (( idle_count > 10 )); then
                sleep 5
            else
                sleep ${poll_interval}
            fi
        fi
    done
}
