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

<<<<<<< Updated upstream
The `VERSION` file (installed to `/usr/local/lib/metamanager/VERSION`) must stay in sync with `debian/changelog`. CI handles this automatically — it reads the version from `dpkg-parsechangelog`, strips the Debian revision suffix (e.g. `X.Y.Z-1` → `X.Y.Z`), and writes it to both files.
=======
The `VERSION` file at `/usr/local/lib/metamanager/VERSION` must stay in sync with `debian/changelog`. The CI handles this automatically — it reads the version from `dpkg-parsechangelog`, strips the Debian revision suffix (e.g. `2.4.10-1` → `2.4.10`), and writes it to both files.
>>>>>>> Stashed changes

### If you change daemon code

1. Push to `dev` — CI bumps the version automatically
<<<<<<< Updated upstream
2. Promote through the normal pipeline (`gh workflow run "Promote to Test"`,
   then `"Promote to Main"`)

Daemon updates reach servers via OS apt inside the GCM maintenance window; the
server's apt channel (test/stable) decides which version installs. The plugin's
`MM_Daemon_Updater` only displays the installed version — it never triggers
updates. See `JOB_QUEUE_SPEC.md` for the plugin↔daemon contract.
=======
2. Open `daemon-compatibility.json` in the **plugin repo** and add an entry mapping the next plugin version to your new daemon version
3. Then release the plugin through the normal pipeline

### What happens if you forget

If the plugin's `daemon-compatibility.json` doesn't have an entry for the current plugin version, the auto-updater cannot determine what daemon version to expect. It will show a persistent error in wp-admin until the mapping is fixed.
>>>>>>> Stashed changes
