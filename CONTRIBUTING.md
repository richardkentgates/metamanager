# Contributing to MetaManager Server

## Development Setup

This is the **daemon/server repository** — bash daemons, systemd units, and
Debian packaging. The WordPress plugin lives in the
[metamanager-plugin](https://github.com/richardkentgates/metamanager-plugin)
repository.

### Requirements
- bash 5.0+
- ShellCheck (CI lints all shell with it)
- `dpkg-buildpackage` tooling (to verify packaging)
- ExifTool (`sudo apt install libimage-exiftool-perl`) for live testing

### Local Testing

Run ShellCheck on all daemon scripts:
```bash
shellcheck -S error daemons/*.sh metamanager-install.sh
```

Build the Debian package to verify packaging:
```bash
dpkg-buildpackage -us -uc -b
```

## Pull Requests
- Keep changes focused on a single issue.
- Test on a real LAMP server before submitting.
- No Composer dependencies — this is server software.

## Version Management

**Do not manually edit version numbers.** The CI pipeline auto-bumps `debian/changelog` and `VERSION` on every push to `dev`.

The `VERSION` file (installed to `/usr/local/lib/metamanager/VERSION`) must stay in sync with `debian/changelog`. CI handles this automatically — it reads the version from `dpkg-parsechangelog`, strips the Debian revision suffix (e.g. `X.Y.Z-1` → `X.Y.Z`), and writes it to both files.

### If you change daemon code

1. Push to `dev` — CI bumps the version automatically
2. Promote through the normal pipeline (`gh workflow run "Promote to Test"`,
   then `"Promote to Main"`)

Daemon updates reach servers via OS apt inside the GCM maintenance window; the
server's apt channel (test/stable) decides which version installs. The plugin's
`MM_Daemon_Updater` only displays the installed version — it never triggers
updates. See `JOB_QUEUE_SPEC.md` for the plugin↔daemon contract.
