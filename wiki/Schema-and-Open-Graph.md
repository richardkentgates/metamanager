# Schema and Open Graph

On every **attachment page** and every **single post or page with a featured image**, Metamanager emits structured data and Open Graph tags appropriate for the file type.

---

## Output by file type

| File type | Schema.org type | `og:type` |
|-----------|-----------------|-----------|
| JPEG / PNG / WebP / GIF / TIFF | `ImageObject` | `og:image` + width / height / alt / MIME type |
| MP4 / MOV / AVI / MKV / WebM / WMV / OGV / 3GP | `VideoObject` | `og:video` + MIME type |
| MP3 / M4A / OGG / WAV / FLAC / WMA / AIFF | `AudioObject` | `og:audio` + MIME type |
| PDF | `DigitalDocument` | `og:type=article` + title / description / URL |

---

## Schema.org `ImageObject` example

Emitted as a `<script type="application/ld+json">` block. Includes `GeoCoordinates` when GPS data is present.

```json
{
  "@context": "https://schema.org",
  "@type": "ImageObject",
  "url": "https://example.com/wp-content/uploads/photo.jpg",
  "name": "Sunrise over the ridge",
  "description": "A long-exposure shot taken at dawn from the eastern trailhead.",
  "creator": { "@type": "Person", "name": "Jane Doe" },
  "copyrightNotice": "© 2026 Jane Doe",
  "keywords": ["landscape", "sunrise", "nature"],
  "dateCreated": "2026-01-15",
  "locationCreated": {
    "@type": "Place",
    "name": "Boulder, CO, USA",
    "geo": {
      "@type": "GeoCoordinates",
      "latitude": 40.014984,
      "longitude": -105.270546,
      "elevation": 1655.0
    }
  },
  "thumbnail": {
    "@type": "ImageObject",
    "url": "https://example.com/wp-content/uploads/photo-150x150.jpg"
  }
}
```

---

## Open Graph tags

Type-appropriate properties are emitted per media family:

**Images**
```html
<meta property="og:type" content="og:image" />
<meta property="og:image" content="https://example.com/wp-content/uploads/photo.jpg" />
<meta property="og:image:width" content="2400" />
<meta property="og:image:height" content="1600" />
<meta property="og:image:type" content="image/jpeg" />
<meta property="og:image:alt" content="Sunrise over the ridge" />
```

**Video**
```html
<meta property="og:type" content="og:video" />
<meta property="og:video" content="https://example.com/wp-content/uploads/clip.mp4" />
<meta property="og:video:type" content="video/mp4" />
```

**Audio**
```html
<meta property="og:type" content="og:audio" />
<meta property="og:audio" content="https://example.com/wp-content/uploads/track.mp3" />
<meta property="og:audio:type" content="audio/mpeg" />
```

---

## License tag

When the **Copyright** field contains a URL (e.g. a Creative Commons URI):

```html
<link rel="license" href="https://creativecommons.org/licenses/by/4.0/" />
```

When the Copyright field contains plain text:

```html
<meta name="copyright" content="© 2026 Jane Doe" />
```

Both give crawlers and aggregators a machine-readable rights signal.
