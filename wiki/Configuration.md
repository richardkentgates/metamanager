# Configuration

Settings are at **Media → MM Settings**. The page is divided into four sections.

---

## Compression

| Setting | Description |
|---------|-------------|
| **Compression level** | JPEG optimization effort passed to `jpegtran`. Higher values trade CPU time for marginally smaller files. The default is a good balance for most servers. |

---

## REST API

Controls access to all `metamanager/v1` endpoints at the plugin level, before any WordPress capability check runs.

| Setting | Description |
|---------|-------------|
| **Disable REST API** | When checked, every request to `/wp-json/metamanager/v1/*` receives `403 Forbidden` regardless of the caller's WordPress role. |
| **Allowed IP addresses** | Comma-separated list of IPv4 or IPv6 addresses permitted to use the API. Leave blank to allow any IP. Has no effect when "Disable REST API" is checked. |

Standard WordPress authentication (`X-WP-Nonce` header or cookie) and capability checks still apply to all permitted requests. See [[REST API]] for the full endpoint reference.

---

## Upload Receipts

Optional email notifications when media is uploaded.

| Setting | Description |
|---------|-------------|
| **Enable upload receipt emails** | When checked, sends a digest email after each batch of uploads. Uploads within a 60-second window are grouped into one email per uploader, plus one to the site admin. |
| **Extra CC address** | An additional address to CC on every upload receipt. Leave blank to send only to the uploader and the site admin. |

If an email fails to send, a dismissible admin notice appears at the top of the dashboard with a one-click **Retry** button. Dismiss it to discard the failed batch without retrying.

---

## Sitemaps

Sitemap settings control which XML sitemap endpoints are active. See [[Media Sitemaps]] for the full field-mapping reference and Google Search Console setup.

| Setting | Default | Description |
|---------|---------|-------------|
| **Serve media sitemap** | On | Enable `/sitemap-media.xml` — images and video attachments from the Media Library |
| **Include image nodes** | On | Toggle `<image:image>` extension nodes within the media sitemap |
| **Serve video sitemap** | On | Enable `/sitemap-video.xml` — video embeds from post content |
| **YouTube embeds** | On | Include YouTube iframe / oEmbed blocks in the video sitemap |
| **Vimeo embeds** | On | Include Vimeo iframe / oEmbed blocks in the video sitemap |
| **Self-hosted video** | On | Include `<video src="…">` tags in the video sitemap |

---

## Data & Uninstall

| Setting | Description |
|---------|-------------|
| **Remove all data on uninstall** | When enabled, deleting the plugin from the Plugins screen permanently removes all options, attachment metadata (16 post meta keys), the `wp_metamanager_jobs` table, and the `metamanager-jobs/` queue directory. **Disabled by default — data is preserved unless you explicitly opt in.** |

On multisite, each site's setting is checked independently — only sites where the option is enabled are cleaned up.

See [[Uninstall]] for the daemon removal steps and manual SQL cleanup.
