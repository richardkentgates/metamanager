# Metamanager Server Roadmap — Closed History (archive)

Archived 2026-11-16 from `ROADMAP.md` when it was converted to a lean forward
plan. Kept as a record — not maintained.

> **Correction note:** several entries below claim the plugin "manages/triggers
> daemon updates" via `daemon-compatibility.json` / `MM_Daemon_Updater`. That
> model was removed in 2026-09. The settled model (verified 2026-11-16):
> daemon updates happen via OS apt inside the GCM maintenance window; the
> server's apt channel decides the version; `MM_Daemon_Updater` is display-only
> (reads `/usr/local/lib/metamanager/VERSION`); `daemon-compatibility.json`
> does not exist. Entries are kept verbatim as historical record.

---

## Self-Updater Removal (2026-09-01)

The shell self-updater has been removed from the daemon repo. Daemon updates are handled by OS apt (root context), not the plugin.

- [x] Deleted `metamanager-self-updater.sh`, `.service`, `.timer` from `daemons/`
- [x] Removed from `debian/metamanager.install`
- [x] Updated `debian/postinst` to clean up legacy self-updater on upgrade (stops timer, removes files)
- [x] Updated `debian/postrm` to clean up on remove/purge
- [x] Updated `AGENTS.md`, `README.md`, `ARCHITECTURE.md` to reflect plugin-controlled updates *(superseded — see correction note)*
- [x] All four servers updated and self-updater removed

## Plugin Daemon Updater Removal (2026-09-09)

The plugin's `MM_Daemon_Updater::trigger_update()` was removed because it ran `sudo apt-get` as www-data, which fails without sudoers grant. Daemon updates are now handled entirely by OS apt (root context via the gcm-maintenance timer/window).

- [x] Removed `trigger_update()`, `handle_plugin_update()`, `init()`, `admin_notice()` from plugin
- [x] Kept `get_daemon_version()` for status reporting
- [x] All five servers updated to plugin v2.3.173 via adopt

## Original Audit Items — All Fixed

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

## Infrastructure

- CI/CD promotion chains verified (dev→test→main)
- HTTPS on apt server (Let's Encrypt, auto-renewal)
- Branch protection removed (was blocking workflow automation)
- Promotion workflows rewritten (direct git merge, not PRs)
- Server wiki populated (3 pages: Home, Installation, Daemon Management)
- GitHub Pages rewritten for daemon layer
- AGENTS.md mandatory workflow rules
- ~~Plugin manages daemon updates~~ *(superseded — see correction note)*

## Audit #3 — 2026-08-03

Full audit covering security, orphans, missing error handling, and concurrency.

### HIGH

| # | Finding | File | Severity | Status |
|---|---------|------|----------|--------|
| S-1 | Background job failure crashes daemon (`set -e` + `wait`) | compress:322, meta:397 | HIGH | FIXED |
| S-2 | Malformed JSON kills daemon (`set -e` + `jq`) | compress:77-83, meta:77-80 | HIGH | FIXED |
| S-3 | No timeout on external tools — hung process starves worker slots | compress:114+, meta:320 | HIGH | FIXED |

### MEDIUM

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| S-4 | No log rotation — unbounded log growth | MEDIUM | FIXED |
| S-5 | `apt-metamanager.conf` sets global APT timeout for all packages | MEDIUM | FIXED |
| S-6 | `PrivateTmp=false` weakens sandboxing | MEDIUM | FIXED |
| S-7 | Missing systemd hardening directives | MEDIUM | FIXED |
| S-8 | No `StartLimitBurst`/`StartLimitIntervalSec` — crash loop disables daemon silently | MEDIUM | FIXED |
| S-9 | `jq` and `inotifywait` not checked at startup | MEDIUM | FIXED |

### LOW

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| S-10 | PID file overwriting without stale check | LOW | FIXED — `kill -0` probe, stale removal, EXIT trap cleanup |
| S-11 | `mail` command not in package dependencies | LOW | FIXED — removed daemon email, WordPress handles notifications via wp_mail() |
| S-12 | No symlink validation on `file_path` from JSON | LOW | FIXED — `[[ -L ]]` rejection in both daemons |
| S-13 | `.mm_tmp` files not cleaned up on crash | LOW | FIXED — startup scan in both daemons |

## What's Left (2026-08 snapshot) — all closed

- [x] Priority 1 — daemon crashes S-1/S-2/S-3
- [x] Priority 2 — hardening S-4…S-9 (logrotate, PrivateTmp, systemd hardening, StartLimit*, jq/inotifywait checks)
- [x] Priority 3 — dependencies (php-imagick moved here from GCM; full Depends verified)
- [x] Priority 4 — LOW items S-10…S-13

## Restoration & Expansion Plan (2026-08-09)

Research confirmed all audit items were already fixed. No restoration needed.

| # | Item | Notes |
|---|------|-------|
| S-6 | `PrivateTmp=true` | Both service files have `PrivateTmp=true` |
| S-7 | Systemd hardening | Full hardening applied (ProtectHome, Restrict*, LockPersonality, etc.) |
| S-11 | Email notifications removed | Daemon sends no emails; plugin handles via `wp_mail()` |
| E-1 | AVIF compression support | Done — avifenc lossless branch implemented (skips with warning when binary absent) |

## Audit #4 — 2026-08-24 (Cross-Repo Audit)

### Forked / Duplicate Logic — RESOLVED

| # | Files | Issue | Severity | Status |
|---|-------|-------|----------|--------|
| F-1 | `compress-daemon.sh` vs `meta-daemon.sh` | ~100 lines of boilerplate duplicated | MEDIUM | Fixed — extracted to `daemon-common.sh` |
| F-2 | `write_result()` | Duplicated with minor differences | MEDIUM | Fixed — unified in `daemon-common.sh` with optional bytes args |

### Self-Updater Issues — RESOLVED (self-updater removed)

The shell self-updater has been removed from the daemon repo. ~~The plugin's `MM_Daemon_Updater` is now the single authority for daemon updates.~~ *(superseded — see correction note.)* The `postinst` script actively cleans up legacy self-updater files on upgrade.

| # | File | Issue | Status |
|---|------|-------|--------|
| U-1 | `metamanager-self-updater.sh` | Service passes `--check` flag but script never parses arguments | Removed |
| U-2 | `metamanager-self-updater.sh` | No early exit when WordPress installation not found | Removed |
| U-3 | `metamanager-self-updater.sh` | Daemon restart failures silently swallowed | Removed |
| U-4 | `metamanager-self-updater.sh` | `write_status()` uses heredoc instead of atomic write | Removed |

### Documentation Stale — closed 2026-11-16

| # | File | Issue | Status |
|---|------|-------|--------|
| D-1 | `ARCHITECTURE.md` | Repo layout didn't mention self-updater script | Closed — self-updater long gone; layout rewritten 2026-11-16 |
| D-2 | `README.md` | Listed `daemon-compatibility.json` and `debian/preinst` (neither exists) | Closed — removed 2026-11-16 |
