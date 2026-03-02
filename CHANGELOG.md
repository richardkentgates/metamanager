# Changelog

All notable changes to Metamanager are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.1.0] — 2026-03-02

### Added
- **Bidirectional metadata sync** (`MM_Metadata::import_from_file()`): on upload, embedded EXIF/IPTC/XMP tags are read from the image file via ExifTool and used to pre-populate WordPress fields; existing user-set values are never overwritten
- **Expanded metadata fields**: Headline, Credit, Keywords (semicolon-separated, multi-value IPTC/XMP), Date Created, Rating (0–5 stars / XMP:Rating), City, State/Province, Country — all stored via `register_post_meta()` for type safety, REST API exposure, and sanitisation
- **Class constants** `MM_Metadata::META_*` for all meta key names — replaces hardcoded strings throughout the codebase
- **GPS coordinates** (`mm_gps_lat`, `mm_gps_lon`, `mm_gps_alt`): automatically imported from `Composite:GPSLatitude/Longitude/Altitude` (ExifTool pre-computed signed decimal); validated against plausible coordinate and elevation ranges; read-only — not shown in the edit UI, not sent to the daemon
- **Schema.org `ImageObject` JSON-LD** (`MM_Frontend`): emitted in `wp_head` on attachment pages and single posts/pages with a featured image — includes name, description, caption, alternativeHeadline, headline, creditText, creator (Person), copyrightNotice, copyrightHolder (Organization), keywords[], dateCreated, locationCreated/contentLocation (Place), GeoCoordinates with latitude, longitude, and elevation when GPS data is present
- **Open Graph tags**: `og:image`, `og:image:secure_url`, `og:image:width`, `og:image:height`, `og:image:type`, `og:image:alt`
- **License link**: `<link rel="license">` when the copyright field contains a URL; `<meta name="copyright">` for plain-text copyright notices
- **Grouped attachment edit UI**: four labelled sections (Attribution & Rights, Editorial, Classification, Location); date picker (`<input type="date">`); 0–5 star rating `<select>`; inline tag hint on every field
- **`register_post_meta()` declarations** for all 14 custom attachment fields — provides type, sanitise callback, auth callback, and REST API visibility at `/wp/v2/media/<id>`
- `MM_Job_Queue::on_upload()` calls `import_from_file()` before enqueueing jobs so the job payload already contains imported values
- `add_action('init', ['MM_Metadata', 'register_meta'])` — meta registration now happens before any WP request
- Help tab metadata table in the admin screen expanded with all new field groups

### Changed
- Meta daemon (`metamanager-meta-daemon.sh`) updated with ExifTool tag mappings for Headline, Credit, Keywords (multi-value loop), DateCreated, Rating, City, State/Province, Country
- `get_fields_for_job()` now uses class constants and includes all new fields in the job payload

---

## [1.0.1] — 2026-03-02

### Added
- Native WordPress auto-updater (`MM_Updater`) — hooks into the core plugin-update pipeline so Metamanager appears in Dashboard → Updates automatically when a new GitHub release is published
- "Check for Updates" action link on the Plugins page for immediate on-demand update checks
- `install.sh --update` flag — updates plugin PHP/JS/asset files only without re-installing daemons, dependencies, or systemd services; flushes WordPress object cache via WP-CLI when available
- Contextual help tabs on the Media → Metamanager admin screen (via WordPress Screen API)
- Inline help section on the job dashboard with explanations of the queue, history, bulk actions, daemon statuses, and metadata field behaviour

### Changed
- README.md: added Updating section, auto-updates feature, and `--update` usage

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

### Planned for 1.2.0
- WebP lossless compression support via `cwebp -lossless`
- WP-CLI commands: `wp metamanager compress <id>`, `wp metamanager queue status`
- Settings page: configurable compression optimisation level
- Individual image re-compress button on the edit screen
- Notification email on daemon failure
- Bulk metadata editing via a dedicated media grid view
- Export job history as CSV
