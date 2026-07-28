# Metamanager Roadmap — Server

Prioritized work items from the May 2026 code audit, updated 2026-07-27.

## Branch strategy

```
dev  ──  all development, direct push; CI runs checks + auto-version bump
    PR  ──  test  ──  build + deploy to apt server
        PR  ──  main  ──  tag + GitHub release
```

**Promotion = PR + merge.** Direct pushes to `test` and `main` are blocked by branch protection.

### CI/CD Pipeline

| Branch | Workflow | Trigger |
|---|---|---|
| `dev` | ShellCheck + auto-increment version in debian/changelog | Push to `dev` |
| `test` | Build .deb + deploy to apt repo | PR merge from `dev` |
| `main` | Create git tag + GitHub release with .deb + deploy to apt repo | PR merge from `test` |

### Branch Protection

| Branch | Requires PR | Required Status Checks | Restrict Pushes |
|--------|------------|----------------------|-----------------|
| `test` | Yes (from `dev`) | ShellCheck passes | No direct push; merge only |
| `main` | Yes (from `test`) | ShellCheck + .deb build passes | No direct push; merge only |

---

## Critical

### #1 — Unparseable JSON results silently deleted
**Status:** FIXED 2026-05-24

### #2 — Race window: cron reads result files while daemon writes them
**Status:** OPEN (daemon should write to .tmp then atomic rename — already implemented in both daemons)

---

## High

### #3 — CI/CD: branch strategy normalization
**Status:** FIXED 2026-05-24

---

## Medium

### #4 — PHPStan excludes ~40% of plugin code
**Status:** FIXED 2026-05-24

### #5 — Add AVIF MIME type support
**Status:** FIXED 2026-05-24

### #6 — Dead code: `MM_Status::mark_compressed()`
**Status:** FIXED 2026-05-24

### #7 — Help tab HTML formatting
**Status:** FIXED 2026-05-24

---

## Low

### #8 — Hardcoded tool paths miss common install locations
**Status:** FIXED 2026-05-24

### #9 — `glob()` without limit on potentially large directories
**Status:** FIXED 2026-05-24

---

## Pipeline

```
dev ──push──> ci.yml (ShellCheck + version bump)
    │
    │  open PR: dev → test
    ▼
test <──PR merge── build-deb.yml (ShellCheck + .deb build + apt repo deploy)
    │
    │  open PR: test → main
    ▼
main <──PR merge── main-release.yml (tag + GitHub release + apt repo deploy)
```

**Promotion = PR + merge.** Direct pushes to `test` and `main` are blocked by branch protection.
