# Branching Strategy

This repo follows the workspace standard three-branch pipeline. There is no
GitFlow here — no `hotfix/*`, `release/*`, or PR-into-`main` flow.

## Branch map

| Branch | Purpose | How it moves |
|---|---|---|
| `dev` | All development. Default branch for work. | Direct push (CI runs ShellCheck + auto-version bump) |
| `test` | Pre-release channel (apt suite `test`) | `gh workflow run "Promote to Test" --ref dev` |
| `main` | Stable releases (apt suite `stable`), tagged | `gh workflow run "Promote to Main" --ref test` |

## Rules

- All work happens on `dev`. Never checkout, edit, or push `test`/`main`
  locally — never `git merge`/`rebase`/`reset` them.
- Promotion is `gh workflow run` only (`promote-to-test.yml`,
  `promote-to-main.yml`). Never bypass a broken workflow — fix it.
- Pull before push: CI auto-bumps the version on every `dev` push and commits
  the bump back — `git pull origin dev` first or you will conflict.
- Never amend or force-push after CI has run on a commit; make a new commit.
- CI auto-bumps `debian/changelog` and `VERSION` — never edit them manually.
- ShellCheck must pass on all shell (`daemons/*.sh`, `metamanager-install.sh`).
- Promotion workflow files must exist on the branch passed to `--ref`; YAML
  must parse before pushing (GitHub takes 2–5 min to register workflow changes).

Full pipeline rules for all repos: workspace `GITHUB-ACTIONS-RULES.md`.
