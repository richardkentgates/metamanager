# Branching Strategy

Metamanager uses a simple three-branch promotion model.

## Branch map

| Branch | Purpose | Branches from | Merges into |
|---|---|---|---|
| `dev` | All development. CI runs lint + version bump. | — | `test` (via PR) |
| `test` | Pre-release. Builds zip/.deb, deploys to apt server. | `dev` (via PR) | `main` (via PR) |
| `main` | Stable, tagged releases. GitHub release + production deploy. | `test` (via PR) | — |

## Rules

- **Branch protection** on `test` and `main`: PRs required, no direct pushes, no force pushes.
- **Promotion** = open PR, CI runs, review, merge.
- `dev` has no protection — direct pushes are allowed.
- Every merge into `main` gets a semver tag (`v2.x.x`).
- PHPStan level 5 must pass before any PR is merged into `test`.

## Naming conventions

```
feature/metadata-integration
fix/sitemap-cache-flush
hotfix/broken-link-crash
```

## Typical flow: new feature

```bash
git checkout dev
git pull origin dev
git checkout -b feature/my-feature

# ... work ...

git push origin feature/my-feature
# Open PR → target: dev (push directly or via PR)
# After merge to dev, open PR: dev → test
```

## Typical flow: hotfix

```bash
git checkout dev
git pull origin dev
git checkout -b hotfix/critical-bug-description

# ... fix ...

git push origin hotfix/critical-bug-description
# Open PR → target: dev
# After merge to dev, open PR: dev → test → main (fast-track)
```

## Typical flow: promotion

```bash
# After dev CI passes and work is ready for testing:
# Open PR: dev → test (CI runs, review, merge)

# After testing passes and ready for release:
# Open PR: test → main (CI runs, review, merge → tag + release created)
```
