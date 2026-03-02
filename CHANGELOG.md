# Changelog

All notable changes to Metamanager are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-03-02

### Added
- Initial public release
- Lossless JPEG compression via `jpegtran -copy all -optimize -progressive`
- Lossless PNG compression via `optipng -o2 -preserve`
- Files are only replaced when the compressed result is smaller than the original
- Full EXIF, IPTC, and XMP metadata embedding via ExifTool in a single pass
- Custom metadata fields (Creator, Copyright, Owner) on every image edit screen
- Metadata fields are intentionally never set in bulk — per-image attribution only
- Bulk action: Compress Lossless — queues compression for all uncompressed sizes
- Bulk action: Inject Site Info — adds Publisher (site name) and Website (site URL) only; neutral provenance, not an ownership claim
- Real-time compression status column in the WordPress Media Library
- Embedded metadata read-out pane on the single image edit screen (live ExifTool readout)
- Metamanager admin page under Media → Metamanager with live job queue and searchable/paginated history
- Re-queue button on any failed job in the history table
- Clear History button (admin-only)
- Daemon health indicator in status banner using PID files — no `systemctl` privilege required
- Two systemd daemons watching job queue directories with `inotifywait`
- Result JSON written to `completed/` or `failed/` directories by daemons
- WP-Cron imports daemon results into the database every 60 seconds
- One-command `install.sh` supporting apt (Debian/Ubuntu) and dnf (RHEL/Rocky)
- `install.sh` patches daemon scripts at deploy time with the actual `WP_CONTENT_DIR` — no hardcoded paths
- WP-CLI activation support in `install.sh` when WP-CLI is available
- GitHub Pages documentation website at `metamanager.richardkentgates.com`
- GPLv3 license
- PHP 8.0+ minimum requirement
- WordPress 6.0+ minimum requirement

---

## Upcoming

### Planned for 1.1.0
- WebP lossless compression support via `cwebp -lossless`
- WP-CLI command: `wp metamanager compress <attachment-id>`
- WP-CLI command: `wp metamanager queue status`
- Settings page: configurable compression optimisation level
- Individual image re-compress button on the edit screen
- Notification email on daemon failure

### Planned for 1.2.0
- Support for bulk metadata editing via a dedicated media grid view
- Import existing embedded metadata from file back into WordPress fields
- Export job history as CSV
