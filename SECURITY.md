# Security Policy

## Supported Versions

| Release | Supported |
|---------|-----------|
| Current | ✅       |

---

## Reporting a Vulnerability

**Do not open a public GitHub Issue for security vulnerabilities.**

Please report security issues privately by emailing:

**contact@richardkentgates.com**

Include in your report:
- A description of the vulnerability
- Steps to reproduce or a proof-of-concept
- The potential impact
- Any suggested remediation if you have one

### What to expect

- Acknowledgement within **72 hours**
- A status update within **7 days**
- A patch released as soon as practicable, typically within **14 days** for critical issues
- Credit in the changelog if you would like it

---

## Security Model

Metamanager's attack surface to be aware of:

### Job queue directories
The `wp-content/metamanager-jobs/` directories contain JSON files with image file paths and metadata. Each directory has an `.htaccess` with `Deny from all` to prevent direct HTTP access. Ensure your web server honours `.htaccess` files.

### Shell daemon execution
The daemons run under systemd as the web-server user detected at install time by `metamanager-install.sh` (e.g. `www-data` on Debian/Ubuntu, `wordpress` or `apache` on RHEL/AlmaLinux/Rocky). They execute `jpegtran`, `optipng`, `cwebp`, `ffmpeg`, and `exiftool` with arguments derived from the job JSON. File paths in job files are written by PHP (which sanitises input) and are not user-controllable from the public web. The service files include `NoNewPrivileges=true`.

### WordPress plugin surface

The plugin's own attack surface (REST endpoints under
`/wp-json/metamanager/v1/`, AJAX handlers, database layer, and the
Metamanager → Preferences REST API access controls) is documented in the
plugin repository's `SECURITY.md` (`metamanager-plugin/SECURITY.md`). This
repo covers only the server-side daemons and packaging.

---

## Scope

The following are **in scope** for security reports:
- Remote code execution
- Privilege escalation (e.g. non-admin user triggering admin actions)
- Unauthenticated access to job queue data
- SQL injection
- Stored or reflected XSS in admin pages
- Path traversal in daemon scripts

The following are **out of scope**:
- WordPress core vulnerabilities
- Server misconfiguration (e.g. `AllowOverride None` ignoring `.htaccess`)
- Issues requiring physical server access
