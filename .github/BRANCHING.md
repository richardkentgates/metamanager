# Branching Strategy

Metamanager uses a simplified GitFlow model.

## Branch map

| Branch | Purpose | Branches from | Merges into |
|---|---|---|---|
| `main` | Stable, tagged releases. **Protected.** | — | — |
| `develop` | Integration branch. Default PR target. Always deployable to staging. | `main` (one-time) | — |
| `feature/*` | New features | `develop` | `develop` |
| `fix/*` | Non-urgent bug fixes | `develop` | `develop` |
| `hotfix/*` | Urgent production fixes | `main` | `main` AND `develop` |
| `release/*` | Release prep: version bump, changelog | `develop` | `main` AND `develop` |
| `docs/*` | Documentation-only changes | `develop` | `develop` |
| `experiment/*` | Exploratory work — may never merge | `develop` | — |

## Rules

- `main` receives merges **only** from `release/*` and `hotfix/*`.
- Every merge into `main` gets a semver tag (`v2.x.x`).
- `develop` is the **default branch** for contributor PRs.
- Feature branches are deleted after merge.
- PHPStan level 5 must pass before any PR is merged into `develop`.

## Naming conventions

```
feature/metadata-integration
feature/schema-media-enrichment
fix/sitemap-cache-flush
hotfix/broken-link-crash
release/v2.2.0
docs/update-rest-api-reference
experiment/gutenberg-sidebar-panel
```

## Typical flow: new feature

```bash
git checkout develop
git pull origin develop
git checkout -b feature/my-feature

# ... work ...

git push origin feature/my-feature
# Open PR → target: develop
```

## Typical flow: hotfix

```bash
git checkout main
git pull origin main
git checkout -b hotfix/critical-bug-description

# ... fix ...

git push origin hotfix/critical-bug-description
# Open PR → target: main
# After merge, also cherry-pick or merge into develop:
git checkout develop
git merge --no-ff hotfix/critical-bug-description
```

## Typical flow: release

```bash
git checkout develop
git pull origin develop
git checkout -b release/v2.2.0

# Bump version in metamanager.php and CHANGELOG.md
# Update docs if needed

git push origin release/v2.2.0
# Open PR → target: main
# After merge: git tag v2.2.0 on main, then merge release/* back into develop
```
