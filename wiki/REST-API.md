# REST API

All endpoints are under the `metamanager/v1` namespace.

**Base URL:** `https://yoursite.com/wp-json/metamanager/v1`

---

## Authentication

Include a `X-WP-Nonce` header or use cookie authentication:

```http
X-WP-Nonce: <nonce from wp_create_nonce('wp_rest')>
```

---

## Access Control

The API can be restricted from **Media → MM Settings → REST API**:

- **Disable REST API** — all endpoints return `403 Forbidden` regardless of the caller's WordPress role.
- **Allowed IPs** — comma-separated list of permitted IPv4/IPv6 addresses. Leave blank to allow any IP.

See [[Configuration]] for details.

---

## Capability tiers

| Capability | Held by | Applies to |
|---|---|---|
| `upload_files` | Author+ | Read-only status checks |
| `edit_others_posts` | Editor+ | Site-wide data and write operations |

---

## `GET /stats`

**Requires:** `edit_others_posts`

Aggregate job statistics across the full history.

```json
{
  "total_jobs": 1240,
  "completed": 1198,
  "failed": 12,
  "unique_attachments": 403,
  "bytes_saved": 18540621,
  "bytes_original": 204800000
}
```

---

## `GET /jobs`

**Requires:** `edit_others_posts`

Paginated, filterable job history. Pagination totals in `X-WP-Total` and `X-WP-TotalPages` response headers.

| Parameter | Type | Default | Notes |
|-----------|------|---------|-------|
| `search` | string | `""` | Filter by file name or job type |
| `orderby` | string | `id` | Sort column |
| `order` | string | `DESC` | `ASC` or `DESC` |
| `per_page` | integer | `20` | 1–100 |
| `page` | integer | `1` | |

---

## `GET /jobs/{id}`

**Requires:** `edit_others_posts`

Single job record by database ID. Returns `404` if not found.

---

## `GET /attachment/{id}/status`

**Requires:** `upload_files`

Compression and metadata sync status for one attachment. Read-only; available to any uploader.

```json
{
  "id": 42,
  "compression": "compressed",
  "meta_synced": true
}
```

`compression` values: `compressed` · `pending` · `failed` · `not_compressed` · `na`

---

## `POST /attachment/{id}/compress`

**Requires:** `edit_others_posts`

Queue lossless compression for one attachment.

- **Image** → recompresses all registered sizes via `jpegtran` / `optipng` / `cwebp`
- **Video** → enqueues a lossless container remux via `ffmpeg`
- **Audio / PDF** → `422 Unprocessable Entity` (no compression step for these types)

| Parameter | Type | Default | Notes |
|-----------|------|---------|-------|
| `force` | boolean | `false` | Re-queue even if already compressed |

```json
{ "id": 42, "queued": true, "message": "Compression jobs queued." }
```

---

## `POST /compression-status`

**Requires:** `upload_files`

Batch compression status query used by the Media Library column. Read-only; available to any uploader.

**Request body:**
```json
{ "ids": [1, 2, 3] }
```

**Response:** map of attachment ID → status string
```json
{ "1": "compressed", "2": "not_compressed", "3": "pending" }
```
