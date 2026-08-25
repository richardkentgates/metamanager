# Metamanager Server Roadmap

Last updated 2026-08-09.

---

## What This Is

The server layer for Metamanager — OS-level daemons that handle metadata embedding (ExifTool), lossless compression (jpegtran/optipng/cwebp), and video remux (ffmpeg). Packaged as a `.deb` for Debian/Ubuntu, deployed via apt.

---

## Branch Strategy

```
dev  ──  all development, direct push; CI runs checks + auto-version bump
    │  workflow_dispatch: promote-to-test.yml
    ▼
test  ──  build .deb + deploy to apt repo
    │  workflow_dispatch: promote-to-main.yml
    ▼
main  ──  tag + GitHub release + deploy to apt repo
```

---

## Current Status

| Item | Value |
|------|-------|
| Daemon version | Auto-bumped by CI on every dev push |
| Apt server | 34.136.87.92 (apt.richardkentgates.com) |
| Production | 34.10.253.160 |
| OS | Debian 13 (trixie) |

---

## What's Done

### Original Audit Items — All Fixed

| # | Issue | Fix | Date |
|---|-------|-----|------|
| 1 | Unparseable JSON results silently deleted | Moved `wp_delete_file()` inside guard, rename to `.unparseable` | 2026-05-24 |
| 2 | Race window: cron reads while daemon writes | Daemon writes to `.tmp` then atomic rename | 2026-05-24 |
| 3 | CI/CD branch strategy normalization | Workflows restructured, promotions via `workflow_dispatch` | 2026-05-24 |
| 4 | PHPStan excludes ~40% of code | WordPress stubs added, level 5 passes on all files | 2026-05-24 |
| 5 | AVIF MIME type support | Added to all MIME lists | 2026-05-24 |
| 6 | Dead code `MM_Status::mark_compressed()` | Removed | 2026-05-24 |
| 7 | Help tab HTML formatting | Fixed concatenation | 2026-05-24 |
| 8 | Hardcoded tool paths | Added `/opt/homebrew/bin/` | 2026-05-24 |
| 9 | `glob()` without limit | Replaced with `GlobIterator` | 2026-05-24 |

### Infrastructure

- CI/CD promotion chains verified (dev→test→main)
- HTTPS on apt server (Let's Encrypt, auto-renewal)
- Branch protection removed (was blocking workflow automation)
- Promotion workflows rewritten (direct git merge, not PRs)
- Server wiki populated (3 pages: Home, Installation, Daemon Management)
- GitHub Pages rewritten for daemon layer
- AGENTS.md mandatory workflow rules

---

## Audit #3 — 2026-08-03

Full audit covering security, orphans, missing error handling, and concurrency.

### HIGH

| # | Finding | File | Severity | Status |
|---|---------|------|----------|--------|
| S-1 | Background job failure crashes daemon (`set -e` + `wait`) | compress:322, meta:397 | HIGH | FIXED (v2.4.31) |
| S-2 | Malformed JSON kills daemon (`set -e` + `jq`) | compress:77-83, meta:77-80 | HIGH | FIXED (v2.4.31) |
| S-3 | No timeout on external tools — hung process starves worker slots | compress:114+, meta:320 | HIGH | FIXED (v2.4.31) |

### MEDIUM

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| S-4 | No log rotation — unbounded log growth | MEDIUM | FIXED (v2.4.31) |
| S-5 | `apt-metamanager.conf` sets global APT timeout for all packages | MEDIUM | FIXED (v2.4.31) |
| S-6 | `PrivateTmp=false` weakens sandboxing | MEDIUM | FIXED (v2.4.31) |
| S-7 | Missing systemd hardening directives | MEDIUM | FIXED (v2.4.31) |
| S-8 | No `StartLimitBurst`/`StartLimitIntervalSec` — crash loop disables daemon silently | MEDIUM | FIXED (v2.4.31) |
| S-9 | `jq` and `inotifywait` not checked at startup | MEDIUM | FIXED (v2.4.31) |

### LOW

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| S-10 | PID file overwriting without stale check | LOW | FIXED (v2.4.36) — `kill -0` probe, stale removal, EXIT trap cleanup |
| S-11 | `mail` command not in package dependencies | LOW | FIXED (v2.4.37) — removed daemon email, WordPress handles notifications via wp_mail() |
| S-12 | No symlink validation on `file_path` from JSON | LOW | FIXED (v2.4.36) — `[[ -L ]]` rejection in both daemons |
| S-13 | `.mm_tmp` files not cleaned up on crash | LOW | FIXED (v2.4.36) — startup scan in both daemons |

---

## What's Left

### Priority 1 — Daemon Crashes (S-1, S-2, S-3)

- [x] Fix background job failure crash (`wait` → `wait || true`)
- [x] Fix malformed JSON crash (wrap `jq` calls in error handling)
- [x] Add timeouts to all external tool invocations (`timeout` command)

### Priority 2 — Hardening (S-4 through S-9) — Complete

- [x] Ship logrotate config (`/etc/logrotate.d/metamanager`)
- [x] Remove `apt-metamanager.conf` global timeout (was too broad)
- [x] Enable `PrivateTmp=true` + remove `/tmp` from ReadWritePaths
- [x] Add systemd hardening directives (ProtectHome, ProtectKernel*, Restrict*, LockPersonality, etc.)
- [x] Configure `StartLimitBurst=5` / `StartLimitIntervalSec=300`
- [x] Add `jq` and `inotifywait` startup checks to both daemons

### Priority 3 — Dependencies

- [x] Add `php-imagick` to `debian/control` Depends — needed by MetaManager WordPress plugin for image processing
- [x] Verify full Depends: `jq, inotify-tools, libimage-exiftool-perl, libjpeg-turbo-progs, optipng, webp, ffmpeg, php-imagick`
- [x] Remove `php-imagick`, `imagemagick`, `libimage-exiftool-perl` from GCM debian/control (they belong here)

### Priority 4 — LOW Items

- [x] S-10: PID file overwriting without stale check
- [x] S-11: Remove `mail` command dependency — WordPress handles notifications via `wp_mail()`
- [x] S-12: No symlink validation on `file_path` from JSON
- [x] S-13: `.mm_tmp` files not cleaned up on crash

---

## CI Workflows

| Branch | Workflow | Trigger |
|--------|----------|---------|
| `dev` | `ci.yml` — ShellCheck + auto-version bump | Push to `dev` |
| `test` | `test-deploy.yml` — build .deb + deploy to apt | Push to `test` |
| `main` | `release.yml` — tag + GitHub release + deploy to apt | Push to `main` |
| any | `promote-to-test.yml` — merge dev→test, build, deploy | Manual (`workflow_dispatch`) |
| any | `promote-to-main.yml` — merge test→main, tag, release, deploy | Manual (`workflow_dispatch`) |

---

## Restoration & Expansion Plan (2026-08-09)

Research confirmed all audit items were already fixed. No restoration needed.

### Already Fixed — No Action Required

| # | Item | Version | Notes |
|---|------|---------|-------|
| S-6 | `PrivateTmp=true` | v2.4.31 | Both service files have `PrivateTmp=true`. |
| S-7 | Systemd hardening | v2.4.31 | Full hardening applied (ProtectHome, Restrict*, LockPersonality, etc.). |
| S-11 | Email notifications removed | v2.4.37 | Daemon sends no emails; plugin handles via `wp_mail()`. |

### Expansion — Platform Improvements

| # | Item | Priority | Description |
|---|------|----------|-------------|
| E-1 | AVIF compression support | LOW | Done — avifenc lossless branch implemented (skips with warning when binary absent). |
| E-2 | Job priority levels | LOW | Allow high-priority jobs to skip queue (currently FIFO only). |
| E-3 | Progress reporting | LOW | Expose compression progress via status file or API endpoint. |

---

## Audit #4 — 2026-08-24 (Cross-Repo Audit)

### Forked / Duplicate Logic — RESOLVED

| # | Files | Issue | Severity | Status |
|---|-------|-------|----------|--------|
| F-1 | `compress-daemon.sh` vs `meta-daemon.sh` | ~100 lines of boilerplate duplicated | MEDIUM | ✅ Fixed — extracted to `daemon-common.sh` |
| F-2 | `write_result()` | Duplicated with minor differences | MEDIUM | ✅ Fixed — unified in `daemon-common.sh` with optional bytes args |

### Self-Updater Issues

| # | File | Line | Issue | Severity | Status |
|---|------|------|-------|----------|--------|
| U-1 | `metamanager-self-updater.sh` | 9 | Service passes `--check` flag but script never parses arguments | MEDIUM | ✅ Fixed — script parses args; service no longer passes --check (was bricking auto-update) |
| U-2 | `metamanager-self-updater.sh` | 27-35 | No early exit when WordPress installation not found — proceeds with empty paths | MEDIUM | ✅ Fixed — early exit with error |
| U-3 | `metamanager-self-updater.sh` | 233-234 | Daemon restart failures silently swallowed (`|| true`) — status still reports "updated" | MEDIUM | ✅ Fixed — failures tracked, status reports partial; sudo prefixes dropped |
| U-4 | `metamanager-self-updater.sh` | 132-173 | `write_status()` uses heredoc instead of atomic `.tmp` + `mv` — partial reads possible | LOW | ✅ Fixed — atomic write |

### Documentation Stale

| # | File | Issue | Severity | Status |
|---|------|-------|----------|--------|
| D-1 | `ARCHITECTURE.md:26-29` | Repo layout doesn't mention self-updater script | MEDIUM | — |
| D-2 | `README.md:325-357` | Lists `daemon-compatibility.json` in wrong repo, lists `debian/preinst` which doesn't exist | MEDIUM | — |

---

## Conventions

- All work on `dev` only. Never checkout/edit/push `test` or `main`.
- Promote via `workflow_dispatch` triggers only.
- CI auto-bumps both `debian/changelog` and `VERSION` on every dev push — never edit manually.
- `VERSION` file format: plain semver (e.g. `2.4.27`), no Debian revision suffix.
- SSH user: `richardkentgates` (not root); default SSH key.
- Plugin triggers daemon updates automatically — no manual SSH on success.
- No system reboot required — `systemctl restart` in-place.
