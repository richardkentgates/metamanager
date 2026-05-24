# Metamanager Roadmap

Prioritized work items from the May 2026 code audit.

## Branch strategy

```
dev  ──  all development, PRs merge here
    test  ──  pre-release validation (auto-deployed from dev)
        main  ──  promote-only; production releases tagged from main
```

Workflows run on `dev` and `test`. Only Pages/docs deploy from `main`.

### Branch protection (GitHub repo settings)

| Branch | Rule |
|---|---|
| `main` | Require PR with 1 approval. No direct pushes. Admin bypass disabled. |
| `test` | Require PR from `dev`. CI must pass. No direct pushes. |
| `dev` | Require PR from feature branches. CI must pass. |

---



## Critical

### #1 — Unparseable JSON results silently deleted

**File:** `metamanager.php` lines 293–369

`json_decode()` failure (daemon mid-write, corrupt file) causes the result file
to be deleted by the `wp_delete_file()` call at the end of the loop body — it is
outside the `if ( is_array( $job ) )` guard. The job is lost with no log entry.

**Fix:** Move `wp_delete_file()` inside the `if` block. On the error path, log
and rename the file with a `.unparseable` suffix so it can be inspected.

---

## High

### #2 — Race window: cron reads result files while daemon writes them

**File:** `metamanager.php` lines 275–394

`mm_import_completed_jobs()` uses `glob()` to find `*.json` files in the
completed/failed directories. If the daemon is mid-write, a partial file can be
read. The current code catches this (json_decode returns null), but then deletes
the file (see #1).

**Fix:** Daemon should write to a `.tmp` file then atomically rename to `.json`.
Add a `.tmp`-skip pattern on the read side.

### #3 — CI/CD: branch strategy normalization

Workflows (`ci.yml`, `codeql.yml`) currently trigger on `main` and `dev`.
`pages.yml` deploys from `main`.

**Goal:** All pushes go to `dev`. `main` is promote-only (tagged releases).
`test` branch runs pre-release validation. Only Pages deploys from `main`.

---

## Medium

### #4 — PHPStan excludes ~40% of plugin code

**File:** `phpstan.neon`

Core files `class-mm-admin.php`, `class-mm-job-queue.php`,
`class-mm-upload-notify.php`, `class-mm-cli.php` and several metadata modules
are excluded from static analysis, citing WordPress class type hints.

**Fix:** Add WordPress stubs (via `phpstan/wordpress` or manual stubs) and
re-enable analysis for these files.

### #5 — Add AVIF MIME type support

`image/avif` is absent from `VIDEO_MIME_TYPES`, `AUDIO_MIME_TYPES`,
`WRITE_CAPABILITY`, and CLI MIME lists. ExifTool and WordPress support it.

**Fix:** Add `'image/avif' => 'full'` to `WRITE_CAPABILITY` and include it in
all attachment queries.

### #6 — Dead code: `MM_Status::mark_compressed()`

**File:** `includes/class-mm-status.php:198-200`

Empty method — compression status is now tracked in the jobs DB table.

**Fix:** Deprecate with `_doing_it_wrong()` or remove. Also clean up the
`_mm_compressed_*` postmeta cleanup in `uninstall.php`.

### #7 — Help tab HTML formatting

**File:** `includes/class-mm-admin.php:153`

Missing newline between concatenated table row strings makes the source
hard to read. Render output is correct.

**Fix:** Insert proper line breaks.

---

## Low

### #8 — Hardcoded tool paths miss common install locations

**File:** `includes/class-mm-status.php:28-48`

Only `/usr/bin/` and `/usr/local/bin/` are checked. Misses
`/opt/homebrew/bin/` (Apple Silicon macOS) and custom prefixes.

**Fix:** Add `/opt/homebrew/bin/` and optionally use `command -v` / `which`
as a fallback.

### #9 — `glob()` without limit on potentially large directories

**File:** `metamanager.php:289`, `class-mm-job-queue.php:114,408-410`

`glob( '*.json' )` loads all filenames into memory. Replace with
`FilesystemIterator` + `RegexIterator` for memory safety on large queues.

---

## Completed

### #1 — Unparseable JSON results silently deleted (2026-05-24)
- `wp_delete_file()` moved inside `if ( is_array( $job ) )` guard
- Error path renames file to `.unparseable` suffix and logs it

### #5 — Add AVIF MIME type support (2026-05-24)
- Added `'image/avif' => 'full'` to `WRITE_CAPABILITY`
- Added `image/avif` to CLI compress and import MIME lists

### #6 — Dead code: `MM_Status::mark_compressed()` removed (2026-05-24)
- Removed empty method
- Removed stale `_mm_compressed_*` cleanup from `uninstall.php`

### #7 — Help tab HTML formatting (2026-05-24)
- Fixed concatenation line breaks for readability

### #8 — Hardcoded tool paths expanded (2026-05-24)
- Added `/opt/homebrew/bin/` (Apple Silicon macOS) to all tool path arrays

### #3 — CI/CD branch strategy normalization (2026-05-24)
- `ci.yml`: triggers on push to `dev`/`test`; PRs targeting `dev`/`test`/`main`
- `codeql.yml`: triggers on push to `dev`/`test`; PRs targeting `dev`/`test`/`main`
- `pages.yml`: unchanged (deploys from `main` only)

### #4 — PHPStan WordPress stubs + WP-CLI stubs (2026-05-24)
- Created `composer.json` with dev deps: phpstan, szepeviktor/phpstan-wordpress, php-stubs/wordpress-stubs, php-stubs/wp-cli-stubs
- Added `vendor/` to `.gitignore`
- Created `stubs/cli-progress-bar.php` for `cli\progress\Bar` (used by `make_progress_bar()`)
- Removed `excludePaths` from phpstan.neon (files now analyzed, not excluded)
- Removed broad catch-all `ignoreErrors` patterns
- Updated CI workflow to use `composer install` + `vendor/bin/phpstan`
- Cleaned up old `tools/phpstan-wordpress/` and `stubs/wp-cli/` dirs
- **PHPStan level 5 now passes on all 40 source files with zero errors**

### #9 — `glob()` replaced with `GlobIterator` for memory safety (2026-05-24)
- `metamanager.php:291` — main cron loop (`mm_import_completed_jobs`)
- `class-mm-job-queue.php:114` — pending job dedup check
- `class-mm-job-queue.php:362` — attachment deletion cleanup
- `class-mm-job-queue.php:408-410` — queue status read (used `AppendIterator`)

### Branch protection rules configured via GitHub API (2026-05-24)
- `main`: PR + 1 approval required, enforce admins (no bypass), no force pushes
- `test`: PR required, CI checks (PHP 8.1/8.2/8.3 matrix) must pass, strict mode, no force pushes
- `dev`: PR required, CI checks (PHP 8.1/8.2/8.3 matrix) must pass, strict mode, no force pushes
