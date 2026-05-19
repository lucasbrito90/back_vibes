# Laravel — Admin upload API (Spaces)

Authenticated **admin** uploads to DigitalOcean Spaces via a single multipart endpoint. Policy and layout align with [`storage-strategy.md`](storage-strategy.md). Client apps (**Nuxt Admin**, mobile) still receive **only CDN URLs** and never hold Spaces credentials.

## Endpoint

| Method | Path | Middleware |
| --- | --- | --- |
| `POST` | `/api/admin/uploads` | `firebase.auth`, `admin.approved` |

The caller must be a synced Laravel user with **`role === admin`** and **`admin_access_status === approved`** (same gate as other admin write routes).

## Request (`multipart/form-data`)

| Field | Type | Description |
| --- | --- | --- |
| `entity_type` | string | `sound` \| `cover` \| `vibe` \| `user` |
| `entity_id` | integer | Primary key of the target row (`sounds`, `cover_bundles`, `vibes`, `users`). Must exist. |
| `asset_type` | string | Allowed values depend on `entity_type` (see below). |
| `file` | file | Upload body. |

The server **never** accepts raw object paths from the client. Laravel derives the Spaces key from `entity_type`, `entity_id`, `asset_type`, and the **validated** MIME type.

### `asset_type` by `entity_type`

| `entity_type` | Allowed `asset_type` |
| --- | --- |
| `sound` | `audio`, `thumbnail` |
| `cover` | `thumbnail`, `artwork`, `player_background` |
| `vibe` | `thumbnail`, `artwork`, `player_background` |
| `user` | `avatar` |

**Note:** `user` + `avatar` is intended for **admin** maintenance (e.g. setting a user’s avatar from the admin UI). End-user mobile avatar upload may be added later as a separate route.

## Validation

### Audio (`sound` + `audio` only)

- Max size: **25 MB**
- Allowed MIME types (mapped to extensions): **MP3, OGG, WAV, M4A, AAC**  
  (detected via uploaded content / reported MIME; see `UploadAssetValidator`.)

Object key: `sounds/{id}/audio/original.{ext}` — **extension matches the validated audio type** (e.g. `.mp3`).

### Images (all other combinations: thumbnails, artwork, backgrounds, avatar)

- Max size: **5 MB**
- Allowed MIME types: **JPEG, PNG, WebP**  
  Canonical extensions in paths: `jpg`, `png`, `webp` (JPEG → `jpg`).

Object keys follow the layout in [`storage-strategy.md`](storage-strategy.md), with a **dynamic extension** so filename matches bytes (no fake `.webp` while content is still PNG/JPEG). Examples:

- `sounds/{id}/thumbnail/thumbnail.{ext}`
- `covers/{id}/thumbnail/thumbnail.{ext}`
- `covers/{id}/artwork/artwork.{ext}`
- `covers/{id}/player-background/background.{ext}`
- `vibes/{id}/thumbnail/thumbnail.{ext}`
- `vibes/{id}/artwork/artwork.{ext}`
- `vibes/{id}/player-background/background.{ext}`
- `users/{id}/avatar/avatar.{ext}`

**This phase does not** resize, transcode, or normalize images to WebP; a future pipeline may rewrite keys and URLs.

## Successful response

**HTTP 201**

```json
{
  "data": {
    "key": "covers/12/artwork/artwork.webp",
    "url": "https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/covers/12/artwork/artwork.webp",
    "entity_type": "cover",
    "asset_type": "artwork",
    "mime_type": "image/webp",
    "size": 12345
  }
}
```

`url` is always built from **`DO_SPACES_CDN_URL`** (public CDN). The API **does not** update `sounds`, `cover_bundles`, `vibes`, or `users` columns automatically; **Nuxt Admin** (next step) should persist the returned URL in the relevant CRUD form.

## Errors

Validation failures return **422** with Laravel validation JSON (`errors` keyed by field). Typical cases:

- Unknown `entity_type` or `asset_type` for that entity
- `entity_id` not found
- File missing / invalid upload
- MIME not allowed for audio vs image
- File over size limit

Authorization failures: **401** (no/invalid Firebase token or unknown user), **403** (authenticated but not admin-approved).

## Implementation references

- `App\Http\Controllers\Api\Admin\UploadAssetController`
- `App\Http\Requests\Admin\UploadAssetRequest`
- `App\Services\Storage\UploadAssetValidator`
- `App\Services\Storage\StoragePathBuilder`
- `App\Services\Storage\DigitalOceanSpacesService`

## Next steps

1. **Nuxt Admin** — call `POST /api/admin/uploads`, then save `data.url` into the entity’s URL field on create/update.
2. Optional **mobile** avatar endpoint (non-admin) with different policy.
3. **Image/audio processing** and optional migration to uniform `.webp` assets.
4. **Safe delete** using `StorageAssetReferenceService` and deletion rules.
