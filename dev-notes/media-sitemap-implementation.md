# Media Sitemap Implementation Notes

> Ported from `gcm-seo-core` (`commit ed2021e` — removed 2026-03-14).
> Intended for implementation in the Metamanager plugin.

---

## Background

`gcm-seo-core` contained a full XML sitemap engine with two media-specific layers:

1. **Image extension nodes** — inline `<image:image>` blocks embedded in per-post-type sitemaps
2. **Video sitemap** — a dedicated `/sitemap-video.xml` covering embedded YouTube, Vimeo, and self-hosted HTML5 video

Both were removed from the SEO plugin because media is Metamanager's domain. Metamanager has direct access to attachment metadata (EXIF/IPTC/XMP, GPS, duration, rating, keywords, copyright URL) that a generic SEO plugin can never have, which makes the sitemap output significantly richer here.

---

## 1. What Was Removed from gcm-seo-core

### 1a. XML namespaces

```php
const NS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';
const NS_VIDEO = 'http://www.google.com/schemas/sitemap-video/0.9';
```

### 1b. Image extension — `render_image_nodes( WP_Post $post ): string`

Inlined into post-type sitemaps. Did two things:

1. **Featured image** — `get_post_thumbnail_id()` → `wp_get_attachment_image_src()` → captured `url`, `title` (attachment title), `caption` (`wp_get_attachment_caption()`).
2. **Content images** — `preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', ...)` on `post_content`, filtered to local URLs only, deduplicated, capped at 50 per post.

Output per image:

```xml
<image:image>
  <image:loc>https://example.com/wp-content/uploads/photo.jpg</image:loc>
  <image:title>Alt text or attachment title</image:title>
  <image:caption>Caption text</image:caption>
</image:image>
```

**What was missing** (Metamanager can add these):
- `<image:license>` — Metamanager stores copyright URL; use that if it is a URL
- `<image:geo_location>` — Metamanager reads GPS lat/lng/altitude from EXIF; reverse-geocode to city/region or emit coordinates as a label

The urlset declaration with image namespace:

```php
$xml .= '<urlset xmlns="' . NS_SITEMAP . '" xmlns:image="' . NS_IMAGE . "\">\n";
```

Setting that guarded it: `sitemap.images` (bool, default `true`).

---

### 1c. Video sitemap — `render_video_sitemap(): string` → `/sitemap-video.xml`

Routed via rewrite rule:

```
sitemap-video\.xml$  →  index.php?gcm_seo_sitemap=video
```

Added as an index entry when `sitemap.video` setting was `true`.

Three extraction strategies, each toggled independently:

| Setting key | Default | Source |
|---|---|---|
| `sitemap.video_youtube` | `true` | `extract_embed_videos()` — YouTube regex |
| `sitemap.video_vimeo` | `true` | `extract_embed_videos()` — Vimeo regex |
| `sitemap.video_selfhosted` | `true` | `extract_selfhosted_videos()` — `<video>` / `<source>` tags |

#### `extract_embed_videos( WP_Post $post ): array`

YouTube regex:

```
/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/
```

Vimeo regex:

```
/vimeo\.com\/(?:video\/)?(\d+)/
```

For each video ID, fetched oEmbed data (cached 1 week in a transient keyed by `md5($url)`):

```php
private function get_cached_oembed( string $url ): array {
    $cache_key = 'gcm_oembed_' . md5( $url );
    $cached    = get_transient( $cache_key );
    if ( $cached ) { return (array) $cached; }
    $oembed = _wp_oembed_get_object();
    $data   = $oembed ? $oembed->get_data( $url, [] ) : false;
    $result = $data ? (array) $data : [];
    if ( $result ) { set_transient( $cache_key, $result, WEEK_IN_SECONDS ); }
    return $result;
}
```

Assembled video record:

```php
[
    'thumbnail'   => $oembed['thumbnail_url'] ?? 'https://img.youtube.com/vi/{id}/hqdefault.jpg',
    'title'       => $oembed['title'] ?? get_the_title( $post ),
    'description' => strip_tags( $post->post_excerpt ?: wp_trim_words( $post->post_content, 20, '' ) ),
    'player_loc'  => 'https://www.youtube.com/embed/{id}',   // or Vimeo player URL
    'duration'    => $oembed['duration'] ?? null,
]
```

#### `extract_selfhosted_videos( WP_Post $post ): array`

Regex on `post_content`:

```
/<video[^>]*>.*?<source[^>]+src=["']([^"']+\.(?:mp4|webm|ogg))["'][^>]*/is
```

Local URLs only (same `is_local_url()` helper). Record:

```php
[
    'thumbnail'   => '',
    'title'       => get_the_title( $post ),
    'description' => strip_tags( $post->post_excerpt ?: '' ),
    'content_loc' => $src,    // direct file URL, not a player
    'duration'    => null,
]
```

#### `render_video_node( array $v ): string`

```xml
<video:video>
  <video:thumbnail_loc>…</video:thumbnail_loc>
  <video:title>…</video:title>
  <video:description>…</video:description>
  <video:player_loc>…</video:player_loc>    <!-- OR content_loc, not both -->
  <video:content_loc>…</video:content_loc>
  <video:duration>123</video:duration>
</video:video>
```

`is_local_url()` helper:

```php
private function is_local_url( string $url ): bool {
    return strpos( $url, home_url() ) === 0
        || ( strpos( $url, 'http' ) !== 0 && strpos( $url, '//' ) !== 0 );
}
```

---

## 2. Enhancements Metamanager Can Add

Metamanager has media metadata that gcm-seo-core never had. All fields below are already stored as registered post meta by Metamanager.

### 2a. Richer image nodes

| Sitemap tag | Metamanager source |
|---|---|
| `<image:caption>` | `mm_caption` / `post_excerpt` |
| `<image:title>` | `mm_headline` or attachment title |
| `<image:license>` | `mm_copyright` — use when it starts with `http` |
| `<image:geo_location>` | `mm_gps_latitude` / `mm_gps_longitude` — format as "City, Country" via reverse geocode, or fall back to `"{lat},{lng}"` |

### 2b. Richer video nodes

Google's Video Sitemap spec supports additional tags that the gcm-seo-core implementation left blank:

| Sitemap tag | Metamanager source |
|---|---|
| `<video:duration>` | ffprobe data stored during daemon processing — exact duration in seconds, not derived from oEmbed |
| `<video:tag>` | `mm_keywords` — one `<video:tag>` element per keyword (max 32 tags per Google's spec) |
| `<video:rating>` | `mm_rating` (0–5 stars) — normalize to 0.0–5.0 |
| `<video:publication_date>` | `mm_date_created` — ISO 8601, e.g. `2024-06-01T00:00:00+00:00` |
| `<video:uploader>` | `mm_creator` — with `info` attribute set to the author profile URL |

### 2c. Attachment page sitemap (`sitemap-media.xml`)

Metamanager writes full Schema.org JSON-LD (`ImageObject`, `VideoObject`, `AudioObject`, `DigitalDocument`) to attachment pages. Those pages deserve their own sitemap sub-file so Google can crawl them directly.

Suggested rewrite rule:

```
sitemap-media\.xml$  →  index.php?mm_sitemap=media
```

Scope: all `attachment` posts with `post_status = 'inherit'` and a MIME type supported by Metamanager (`image/*`, `video/*`, `audio/*`, `application/pdf`).

Standard `<urlset>` with `xmlns:image` and/or `xmlns:video` as needed. Each URL entry would be the attachment permalink (`get_attachment_link()`), with image or video extension nodes for media types that support them.

### 2d. PDF entries in the standard sitemap

PDFs are attachments with MIME `application/pdf`. Google indexes PDFs directly via their URL (`wp_get_attachment_url()`), not via an attachment page. Include them as plain `<url>` entries in `sitemap-media.xml` using the direct file URL — no image/video extension needed.

### 2e. Audio pages

No official Google sitemap extension for audio exists. Include audio attachment pages as plain `<url>` entries in `sitemap-media.xml`. The Schema.org `AudioObject` JSON-LD on those pages is the signal Google uses.

---

## 3. Settings Keys to Carry Over / Add

| Key | Type | Default | Description |
|---|---|---|---|
| `sitemap.images` | bool | `true` | Include `<image:image>` extension nodes in post-type sitemaps |
| `sitemap.video` | bool | `true` | Include `/sitemap-video.xml` in the index |
| `sitemap.video_youtube` | bool | `true` | Extract YouTube embed URLs from post content |
| `sitemap.video_vimeo` | bool | `true` | Extract Vimeo embed URLs from post content |
| `sitemap.video_selfhosted` | bool | `true` | Extract self-hosted `<video>/<source>` tags |
| `sitemap.media` | bool | `true` | *(new)* Include `/sitemap-media.xml` for attachment pages |
| `sitemap.image_license` | bool | `true` | *(new)* Add `<image:license>` when copyright value is a URL |
| `sitemap.image_geo` | bool | `true` | *(new)* Add `<image:geo_location>` when GPS data is present |

---

## 4. Integration Points in Metamanager

- **Where to hook**: `init` for rewrite rules; `template_redirect` to serve the XML response.
- **Index entry**: Add `sitemap-media.xml` to whatever generates Metamanager's sitemap index (or hook into the `wp_sitemaps_*` filter chain if gcm-seo-core is not active — Metamanager should check `$s->get('sitemap.enabled')` from gcm-seo-core and skip if that plugin owns the index).
- **Coordination with gcm-seo-core**: Both plugins can coexist. gcm-seo-core owns the page/post/taxonomy sitemap index. Metamanager adds `sitemap-media.xml` as a compatible sub-file. Image extension nodes within post-type sitemaps are the overlap — Metamanager should only inject them when gcm-seo-core has `sitemap.images = false` (or is not active).
- **Meta access**: All Metamanager metadata is stored as standard `post_meta` on the attachment post. Use `get_post_meta( $attachment_id, 'mm_gps_latitude', true )` etc.
- **oEmbed cache**: Reuse the same transient pattern (`'mm_oembed_' . md5( $url )`, `WEEK_IN_SECONDS`) — already proven to work.
