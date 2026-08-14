# Job Queue Contract

The plugin and daemons communicate exclusively through JSON job files in `wp-content/metamanager-jobs/`. This is the interface contract.

## Directory Layout

```
wp-content/metamanager-jobs/
├── compress/          Incoming compression jobs
├── meta/              Incoming metadata jobs
├── completed/         Daemon-written result files
├── failed/            Daemon-written failure files
├── compress-daemon.pid    PID file for compress daemon
└── meta-daemon.pid        PID file for meta daemon
```

## Job File Format

### Compression Job (`compress/{uuid}.json`)

```json
{
  "attachment_id": 42,
  "image_name": "photo.jpg",
  "job_type": "compression",
  "job_trigger": "upload",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full",
  "dimensions": "3000x2000"
}
```

### Metadata Import Job (`meta/{uuid}.json`)

```json
{
  "attachment_id": 42,
  "image_name": "photo.jpg",
  "job_type": "import",
  "job_trigger": "upload",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full"
}
```

### Metadata Write-Back Job (`meta/{uuid}.json`)

```json
{
  "attachment_id": 42,
  "image_name": "photo.jpg",
  "job_type": "metadata",
  "job_trigger": "edit",
  "file_path": "/srv/www/wordpress/wp-content/uploads/2026/03/photo.jpg",
  "size": "full",
  "fields": {
    "title": "Sunrise over the ridge",
    "creator": "Jane Doe",
    "copyright": "© 2026 Jane Doe",
    "keywords": "landscape; sunrise; nature"
  }
}
```

## Result File Format

### Success (`completed/{uuid}.json`)

```json
{
  "attachment_id": 42,
  "job_type": "compression",
  "status": "completed",
  "bytes_before": 524288,
  "bytes_after": 491520,
  "details": {}
}
```

### Failure (`failed/{uuid}.json`)

```json
{
  "attachment_id": 42,
  "job_type": "compression",
  "status": "failed",
  "error": "jpegtran: invalid JPEG marker"
}
```

## Lifecycle

1. Plugin writes job JSON to `compress/` or `meta/`
2. Daemon detects new file via `inotifywait`
3. Daemon renames file to `{uuid}.json.processing` (atomic ownership claim)
4. Daemon processes the file
5. Daemon writes result to `completed/` or `failed/`
6. Daemon removes `.processing` file
7. Plugin WP-Cron reads result files, inserts into DB, deletes result file

## Naming Convention

Job files use UUID v4 filenames: `{uuid}.json`

Result files use the same UUID: `completed/{uuid}.json` or `failed/{uuid}.json`
