# Uninstall

> **Default behaviour:** Metamanager leaves all data in place when deleted. Nothing is removed unless you explicitly opt in.

---

## Recommended: via WordPress admin

1. Go to **Media → MM Settings → Data & Uninstall**
2. Enable **Remove all data on uninstall**
3. Go to **Plugins → Installed Plugins** and delete Metamanager

When the option is enabled, deleting the plugin automatically removes:
- All plugin options from `wp_options`
- All custom post meta (16 keys + `_mm_compressed_*` keys)
- The `wp_metamanager_jobs` job-log table
- The `wp-content/metamanager-jobs/` queue directory
- The updater transient

On **multisite**, each site's setting is checked independently — only sites where the option is enabled are cleaned up.

---

## Remove the system daemons

The WordPress uninstall routine does not touch the server-level daemons. Remove them manually:

```bash
# Stop and disable services
sudo systemctl stop metamanager-compress-daemon metamanager-meta-daemon
sudo systemctl disable metamanager-compress-daemon metamanager-meta-daemon

# Remove service files and scripts
sudo rm /etc/systemd/system/metamanager-*.service
sudo rm /usr/local/bin/metamanager-*-daemon.sh
sudo systemctl daemon-reload

# Remove plugin directory (if not already deleted via WP admin)
rm -rf /path/to/wordpress/wp-content/plugins/metamanager
```

---

## Manual database cleanup

If you deleted the plugin without enabling the data-removal setting, clean up manually:

```sql
-- Job log table
DROP TABLE IF EXISTS wp_metamanager_jobs;

-- Plugin settings
DELETE FROM wp_options WHERE option_name IN
  ('mm_compress_level', 'mm_notify_enabled', 'mm_notify_email',
   'mm_delete_data_on_uninstall',
   'mm_api_disabled', 'mm_api_allowed_ips',
   'mm_upload_notify_enabled', 'mm_upload_notify_extra_email',
   'mm_failed_upload_notices');

-- Attachment metadata
DELETE FROM wp_postmeta WHERE meta_key IN
  ('mm_creator', 'mm_copyright', 'mm_owner', 'mm_headline', 'mm_credit',
   'mm_keywords', 'mm_date_created', 'mm_location_city', 'mm_location_state',
   'mm_location_country', 'mm_rating', 'mm_gps_lat', 'mm_gps_lon',
   'mm_gps_alt', 'mm_meta_synced', '_mm_compressed_full');
DELETE FROM wp_postmeta WHERE meta_key LIKE '_mm_compressed_%';
```

> On multisite, repeat the `DELETE FROM` statements for each site's `wp_X_options` and `wp_X_postmeta` tables (where `X` is the blog ID).
