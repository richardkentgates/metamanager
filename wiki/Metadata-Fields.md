# Metadata Fields

Metamanager uses each file type's **native tag system**. All WordPress field names are the same across every supported type — only the underlying tags differ.

> **Format capabilities at a glance:**
>
> | Format | Write | Read-import | Compression |
> |--------|-------|-------------|-------------|
> | JPEG, PNG, WebP, GIF, TIFF | EXIF + IPTC + XMP | ✔ | ✔ |
> | MP4, MOV, M4A | QuickTime atoms | ✔ | ✔ (remux) |
> | AVI, WMV, WMA | XMP | ✔ | ✔ (remux) |
> | MKV, WebM, OGV | — | ✔ | ✔ (remux) |
> | MP3 | ID3 | ✔ | — |
> | OGG, FLAC | Vorbis comments | ✔ | — |
> | WAV | XMP | ✔ | — |
> | PDF | XMP | ✔ | — |

---

## Attribution & Rights

> ⚠ **Never set by bulk actions.** Creator, Copyright, and Owner carry rights and attribution meaning and must be set per file.

| WordPress field | EXIF | IPTC | XMP | ID3 | QuickTime | Vorbis |
|----------------|------|------|-----|-----|-----------|--------|
| Creator | `Artist` | `By-line` | `Creator` | `TPE1` | `©ART` | `ARTIST` |
| Copyright | `Copyright` | `CopyrightNotice` | `Rights` | `TCOP` | `cprt` | `COPYRIGHT` |
| Owner | `OwnerName` | — | `Owner` | — | — | — |

---

## Editorial

| WordPress field | EXIF | IPTC | XMP | ID3 | QuickTime | Vorbis |
|----------------|------|------|-----|-----|-----------|--------|
| Headline | — | `Headline` | `Headline` | `TIT2` | `©nam` | `TITLE` |
| Credit | — | `Credit` | `Credit` | — | — | — |

---

## Classification

| WordPress field | EXIF | IPTC | XMP | ID3 | QuickTime | Vorbis |
|----------------|------|------|-----|-----|-----------|--------|
| Keywords *(semicolon-separated)* | — | `Keywords` | `Subject` | `TCON` | `©gen` | `GENRE` |
| Date Created *(YYYY-MM-DD)* | `DateTimeOriginal` | `DateCreated` | `DateCreated` | `TDRC` | `©day` | `DATE` |
| Rating *(0–5 stars)* | — | — | `Rating` | — | — | — |

---

## Location *(IPTC Photo Metadata Standard)*

| WordPress field | EXIF | IPTC | XMP |
|----------------|------|------|-----|
| City | — | `City` | `City` |
| State / Province | — | `Province-State` | `State` |
| Country | — | `Country-PrimaryLocationName` | `Country` |

---

## GPS *(read-only — imported from camera EXIF, never manually editable)*

| WordPress field | ExifTool source | Schema.org property |
|----------------|----------------|---------------------|
| Latitude | `Composite:GPSLatitude` | `GeoCoordinates.latitude` |
| Longitude | `Composite:GPSLongitude` | `GeoCoordinates.longitude` |
| Altitude (m) | `Composite:GPSAltitude` | `GeoCoordinates.elevation` |

GPS data is stored automatically on upload when the file contains camera-embedded GPS tags. It is included in the `ImageObject` Schema.org JSON-LD as a `GeoCoordinates` node.

---

## WordPress Native Fields *(bidirectional sync)*

These map directly to native WordPress post fields and are synced in both directions — imported from the file on upload, and written back to the file by the daemon when changed.

| WordPress field | Source | EXIF | IPTC | XMP |
|----------------|--------|------|------|-----|
| Title | Post title | `Title` | `ObjectName` | `Title` |
| Description | Post content | `ImageDescription` | `Caption-Abstract` | `Description` |
| Caption | Post excerpt | — | `Caption-Abstract` | `Caption` |
| Alt Text | WP alt field | — | — | `AltTextAccessibility` |

---

## Site Provenance *(bulk-safe — neutral origin data only)*

These are the only fields set by bulk actions. They carry no ownership or attribution claim.

| Field | Source | IPTC | XMP |
|-------|--------|------|-----|
| Publisher | WordPress site name | `Source` | `Publisher` |
| Website | WordPress site URL | `Source` | `WebStatement` |

**Creator, Copyright, Owner, and all other per-image fields are never touched by bulk actions.**
