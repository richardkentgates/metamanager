# Media Sitemaps

Metamanager generates two XML sitemap endpoints served directly by WordPress — no static files, no caching plugins required.

| Endpoint | Content |
|----------|---------|
| `/sitemap-media.xml` | All images and (optionally) videos uploaded to the Media Library |
| `/sitemap-video.xml` | Videos sourced from self-hosted files, YouTube embeds, and Vimeo embeds in post content |

---

## Media Sitemap (`/sitemap-media.xml`)

Each `<url>` entry contains an `<image:image>` extension node (when Image Sitemap is enabled) and a `<video:video>` extension node for video attachments (when Video Sitemap is enabled). Fields are populated from metadata stored by Metamanager:

| Sitemap field | Source |
|---------------|--------|
| `<loc>` | Attachment permalink |
| `<image:loc>` | File URL |
| `<image:title>` | Post title |
| `<image:caption>` | Post excerpt |
| `<image:license>` | Copyright field (when a URL) |
| `<video:title>` | Post title |
| `<video:description>` | Post excerpt |
| `<video:content_loc>` | File URL |
| `<video:duration>` | Duration in seconds (from `ffprobe` via daemon) |
| `<video:publication_date>` | Post date |
| `<video:rating>` | Rating field (0–5, normalised to 0–1 for Google) |
| `<video:family_friendly>` | `yes` when rating ≤ 4 |

---

## Video Sitemap (`/sitemap-video.xml`)

Scans all published posts for embedded video and emits one `<url>` per video found. Sources:

- **Self-hosted** — `<video src="…">` tags in post content
- **YouTube** — `<iframe>` embeds and WordPress oEmbed blocks (`youtu.be`, `youtube.com`)
- **Vimeo** — `<iframe>` embeds and WordPress oEmbed blocks (`vimeo.com`)

YouTube and Vimeo metadata (title, description, duration) is resolved via the oEmbed API and cached as transients for 24 hours.

---

## Settings

Sitemap settings are under **Media → MM Settings → Sitemaps**. Each source can be toggled independently:

| Setting | Default | Effect |
|---------|---------|--------|
| Serve media sitemap | On | Enable / disable `/sitemap-media.xml` |
| Include image nodes | On | Toggle `<image:image>` nodes |
| Serve video sitemap | On | Enable / disable `/sitemap-video.xml` |
| YouTube embeds | On | Include YouTube sources in the video sitemap |
| Vimeo embeds | On | Include Vimeo sources in the video sitemap |
| Self-hosted video | On | Include `<video>` tag sources |

---

## Submitting to Google Search Console

1. Open [Google Search Console](https://search.google.com/search-console) and select your property.
2. Go to **Sitemaps** in the left sidebar.
3. Enter `sitemap-media.xml` in the "Add a new sitemap" box and click **Submit**.
4. Repeat for `sitemap-video.xml`.

Both sitemaps refresh on every request — no manual resubmission is needed after new uploads.

---

## Planned Enhancements

The following tags are not yet emitted but the underlying metadata is already stored and could be added in a future release:

| Tag | Source |
|-----|--------|
| `<image:geo_location>` | `mm_gps_latitude` / `mm_gps_longitude` — as `"{lat},{lng}"` or reverse-geocoded city/country |
| `<video:tag>` | `mm_keywords` — one element per keyword (Google supports up to 32 tags) |
| `<video:uploader>` | `mm_creator` — with `info` attribute pointing to the author profile URL |

Additional attachment types not yet included in `sitemap-media.xml`:

- **PDFs** — Google indexes PDFs directly by file URL; including them as plain `<url>` entries (no extension nodes required) improves discoverability.
- **Audio pages** — No official sitemap extension exists for audio, but attachment pages with Schema.org `AudioObject` JSON-LD can be submitted as standard `<url>` entries.
