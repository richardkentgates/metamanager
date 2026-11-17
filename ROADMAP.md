# Metamanager Server Roadmap

Last updated 2026-11-16.

---

## What This Is

The server layer for Metamanager — OS-level daemons that handle metadata
embedding (ExifTool), lossless compression (jpegtran/optipng/cwebp/avifenc),
and video remux (ffmpeg). Packaged as a `.deb` for Debian/Ubuntu, deployed via
the private apt server. Public distribution is intentional.

Closed history (audits, completed fixes) lives in
[`_archive/ROADMAP-HISTORY.md`](_archive/ROADMAP-HISTORY.md) — record, not
maintained.

---

## Branch Strategy

```
dev  ──  all development, direct push; CI runs checks + auto-version bump
    │  workflow_dispatch: promote-to-test.yml
    ▼
test  ──  build .deb + deploy to apt repo (test suite)
    │  workflow_dispatch: promote-to-main.yml
    ▼
main  ──  tag + GitHub release + deploy to apt repo (stable suite)
```

---

## Current Status

| Item | Value |
|------|-------|
| Daemon version | Auto-bumped by CI on every dev push |
| Apt server | 34.136.87.92 (apt.richardkentgates.com) |
| Fleet | See the workspace `AGENTS.md` fleet table |
| OS | Debian 13 (trixie) |

Daemon updates are handled by OS apt inside the GCM maintenance window — the
server's apt channel (test/stable) decides the version. The plugin's
`MM_Daemon_Updater` is display-only (reads
`/usr/local/lib/metamanager/VERSION`); it never triggers updates. The job
queue contract is `JOB_QUEUE_SPEC.md` (kept identical to the plugin repo's
copy).

---

## CI Workflows

| Branch | Workflow | Trigger |
|--------|----------|---------|
| `dev` | `ci.yml` — ShellCheck + auto-version bump | Push to `dev` |
| `dev` → `test` | `promote-to-test.yml` — merge, build .deb, deploy apt `test` | Manual (`workflow_dispatch`) |
| `test` → `main` | `promote-to-main.yml` — merge, tag, release, deploy apt `stable` | Manual (`workflow_dispatch`) |

---

## Open Items

| # | Item | Priority | Description |
|---|------|----------|-------------|
| E-2 | ~~Job priority levels~~ | DONE 2026-11-16 | Dropped (user decision, fix-list B4): the drain sorted by a `priority` nothing wrote — jobs process FIFO (filename order) now. Spec updated in both repos. |
| E-3 | Progress reporting | LOW | Expose compression progress via the daemon status files or an API endpoint. |

---

## Conventions

- All work on `dev` only. Never checkout/edit/push `test` or `main`.
- Promote via `workflow_dispatch` triggers only.
- CI auto-bumps both `debian/changelog` and `VERSION` on every dev push — never edit manually.
- `VERSION` file format: plain semver (`X.Y.Z`), no Debian revision suffix.
- SSH user: `richardkentgates` (not root); default SSH key.
- Daemon updates happen via OS apt (GCM maintenance window) — no manual SSH on success.
- No system reboot required — `systemctl restart` in-place.
