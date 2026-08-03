# Metamanager Server Roadmap

Last updated 2026-08-03.

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
| Daemon version | v2.4.27 |
| Apt server | 34.136.87.92 (apt.richardkentgates.com) |
| Production | 104.197.172.183 |
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
| S-1 | Background job failure crashes daemon (`set -e` + `wait`) | compress:322, meta:397 | HIGH | OPEN |
| S-2 | Malformed JSON kills daemon (`set -e` + `jq`) | compress:77-83, meta:77-80 | HIGH | OPEN |
| S-3 | No timeout on external tools — hung process starves worker slots | compress:114+, meta:320 | HIGH | OPEN |

### MEDIUM

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| S-4 | No log rotation — unbounded log growth | MEDIUM | OPEN |
| S-5 | `apt-metamanager.conf` sets global APT timeout for all packages | MEDIUM | OPEN |
| S-6 | `PrivateTmp=false` weakens sandboxing | MEDIUM | OPEN |
| S-7 | Missing systemd hardening directives | MEDIUM | OPEN |
| S-8 | No `StartLimitBurst`/`StartLimitIntervalSec` — crash loop disables daemon silently | MEDIUM | OPEN |
| S-9 | `jq` and `inotifywait` not checked at startup | MEDIUM | OPEN |

### LOW

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| S-10 | PID file overwriting without stale check | LOW | OPEN |
| S-11 | `mail` command not in package dependencies | LOW | OPEN |
| S-12 | No symlink validation on `file_path` from JSON | LOW | OPEN |
| S-13 | `.mm_tmp` files not cleaned up on crash | LOW | OPEN |

---

## What's Left

### Priority 1 — Daemon Crashes (S-1, S-2, S-3)

- [x] Fix background job failure crash (`wait` → `wait || true`)
- [x] Fix malformed JSON crash (wrap `jq` calls in error handling)
- [x] Add timeouts to all external tool invocations (`timeout` command)

### Priority 2 — Hardening (S-4 through S-9)

- [ ] Ship logrotate config (`/etc/logrotate.d/metamanager`)
- [ ] Remove or scope `apt-metamanager.conf` global timeout
- [ ] Enable `PrivateTmp=true`
- [ ] Add systemd hardening directives
- [ ] Configure `StartLimitBurst`/`StartLimitIntervalSec`
- [ ] Add `jq` and `inotifywait` startup checks

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

## Conventions

- All work on `dev` only. Never checkout/edit/push `test` or `main`.
- Promote via `workflow_dispatch` triggers only.
- CI auto-bumps both `debian/changelog` and `VERSION` on every dev push — never edit manually.
- `VERSION` file format: plain semver (e.g. `2.4.27`), no Debian revision suffix.
- SSH user: `richardkentgates` (not root); default SSH key.
- Plugin triggers daemon updates automatically — no manual SSH on success.
- No system reboot required — `systemctl restart` in-place.
