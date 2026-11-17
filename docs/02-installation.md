# Installation

Support follows the repo README (tested environments):

- **Debian / Ubuntu** — primary path: install the `.deb` from the apt
  repository (`apt.richardkentgates.com`, suite `stable`). See the README's
  Quick Install.
- **AlmaLinux / Rocky / RHEL 9+** — supported via the installer from a source
  checkout (`metamanager-install.sh` supports `apt`, `dnf`, and `yum`).
  Tested full install on AlmaLinux 9.7 (dnf + EPEL + CRB). The `.deb` packages
  are not used on RHEL-family systems.

Both paths install the daemons to `/usr/local/bin/` with systemd units, plus
the OS dependencies (ExifTool, jpegtran, optipng, cwebp, ffmpeg, jq,
inotify-tools). The WordPress plugin is installed separately.

Full steps, requirements, and verification: see [`README.md`](../README.md)
(Quick Install, Requirements, Manual Install).
