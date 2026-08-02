# Contributing to MetaManager

## Development Setup

This is a WordPress plugin with bash daemons — no Composer dependencies.

### Requirements
- PHP 8.1+
- WordPress 6.4+
- ExifTool (`sudo apt install libimage-exiftool-perl`)

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

The `VERSION` file at `/usr/local/lib/metamanager/VERSION` must stay in sync with `debian/changelog`. The CI handles this automatically — it reads the version from `dpkg-parsechangelog`, strips the Debian revision suffix (e.g. `2.4.10-1` → `2.4.10`), and writes it to both files.

### If you change daemon code

1. Push to `dev` — CI bumps the version automatically
2. Open `daemon-compatibility.json` in the **plugin repo** and add an entry mapping the next plugin version to your new daemon version
3. Then release the plugin through the normal pipeline

### What happens if you forget

If the plugin's `daemon-compatibility.json` doesn't have an entry for the current plugin version, the auto-updater cannot determine what daemon version to expect. It will show a persistent error in wp-admin until the mapping is fixed.
