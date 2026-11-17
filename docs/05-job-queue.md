# Job Queue

The plugin and daemons communicate exclusively through JSON job files in
`wp-content/metamanager-jobs/`.

**The interface contract is `JOB_QUEUE_SPEC.md` (repo root)** — exact key
names, filenames, job types, result format, trigger vocabulary, and tool
invocations. An identical copy lives in the plugin repo; the two must stay in
sync. Do not duplicate payload examples here.

## Directory Layout

```
wp-content/metamanager-jobs/
├── compress/              Incoming compression jobs
│   └── *.json.processing  Claimed (daemon-locked)
├── meta/                  Incoming metadata + import jobs
├── completed/             Daemon-written result files (<job-name>.json-result.json)
├── failed/                Daemon-written failure files (same naming)
├── compress-daemon.pid    PID file for compress daemon
├── meta-daemon.pid        PID file for meta daemon
├── compress-status.json   Daemon status (queue depth, last completed)
└── meta-status.json       Daemon status (queue depth, last completed)
```

## Lifecycle

1. Plugin writes job JSON to `compress/` or `meta/`
   (`<attachment_id>-<size>-<time>-<uid>.json`)
2. Daemon detects new file via `inotifywait`
3. Daemon renames file to `*.json.processing` (atomic ownership claim) and
   takes a per-file `flock` on the target
4. Daemon processes the file (gated pauses while GCM backup/maintenance or
   MXRoute mail is active)
5. Daemon writes result JSON atomically to `completed/` or `failed/`
   (`<job-name>.json-result.json`)
6. Daemon removes the `.processing` file
7. Plugin WP-Cron (`mm_import_completed_jobs`, every minute) reads result
   files, records them in the history table, applies import/verify results,
   then deletes the result file

Startup recovery: `*.json.processing` orphans are renamed back to `*.json`;
leftover `*.mm_tmp` tool temporaries are removed. See `JOB_QUEUE_SPEC.md` for
the full protocol.
