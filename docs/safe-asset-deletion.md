# Safe asset deletion (Laravel)

Laravel removes catalog rows first, then optionally deletes objects in **DigitalOcean Spaces** using **`SafeAssetDeletionService`**, **`DigitalOceanSpacesService`**, and **`StorageAssetReferenceService`**.

Shared behaviour:

- **Post-delete reference counting:** cleanup runs **after** the owning row is gone, so `countReferencesToUrl` does not count that row.
- **External URLs:** if `keyFromUrl` returns `null`, the URL is skipped (no HTTP error).
- **Shared URLs:** if any tracked column still stores the same URL string, the object is **not** deleted.
- **Storage failures:** a failed Spaces delete does **not** roll back the database row; warnings are logged.

---

## Status outcomes (per URL)

| Status | Meaning |
| --- | --- |
| `deleted` | Spaces `delete` succeeded |
| `skipped_external_url` | Not our CDN/origin URL |
| `skipped_still_referenced` | Another DB row still stores this URL |
| `empty` | Null/blank (single-URL helper only; batch ignores empties) |
| `failed` | `delete` returned false |

Batch helper **`deleteUrlsIfUnreferenced`** returns **`url => status`** with deduplicated URLs.

---

## Logging (storage cleanup)

| Situation | Level |
| --- | --- |
| Object removed from Spaces | `info` |
| Skipped — still referenced | `info` |
| Skipped — external URL | `info` |
| Skipped — empty URL | `info` |
| Spaces delete failed | `warning` |
| DB row deleted but cleanup failed (controller) | `warning` |

---

## `DELETE /api/sounds/{sound}`

### When delete is blocked (`409 Conflict`)

| Check | Table / meaning |
| --- | --- |
| Sound layer on user vibe | `vibe_sounds.sound_id` |
| Sound layer on preset | `preset_vibe_sounds.sound_id` |

Response body:

```json
{
  "message": "This sound is currently used by one or more vibes and cannot be deleted."
}
```

### Flow

1. Collect **`file_url`** and **`thumbnail_url`** (non-empty).
2. Delete **`sounds`** row in a transaction.
3. Call **`deleteUrlsIfUnreferenced`** for those URLs.

### Controller logging

- **`warning`** — delete blocked (sound in use).

---

## `DELETE /api/cover-bundles/{cover_bundle}`

### When delete is blocked (`409 Conflict`)

1. **Preset vibes:** any **`preset_vibes`** row with **`cover_bundle_id`** equal to this bundle.
2. **User vibes / copied URLs:** any **`vibes`** row where **`thumbnail_url`**, **`artwork_url`**, or **`player_background_url`** equals the bundle’s value **for that same column** (non-empty URLs only). Copied preset URLs count as use.

Same response for both cases:

```json
{
  "message": "This cover bundle is currently used by one or more vibes and cannot be deleted."
}
```

### Controller logging

- **`warning`** — blocked because referenced by **preset vibes** (`cover_bundle_id`).
- **`warning`** — blocked because **bundle URLs appear on user vibes**.

### Flow

1. Collect **`thumbnail_url`**, **`artwork_url`**, **`player_background_url`** (non-empty).
2. Delete **`cover_bundles`** row in a transaction.
3. Call **`deleteUrlsIfUnreferenced`** for those URLs.

---

## Limitations

- Only **exact URL string** equality; aliases or different URLs for the same bytes are not merged.
- Reference surfaces are defined by **`StorageAssetReferenceService`** URL columns; new fields require updating that service.
- Scoped to **`DELETE`** on catalog **Sound** and **CoverBundle** as implemented in their controllers.

---

## Related code

- `App\Services\Storage\SafeAssetDeletionService`
- `App\Services\Storage\DigitalOceanSpacesService`
- `App\Services\Storage\StorageAssetReferenceService`
- `App\Http\Controllers\Api\SoundController::destroy`
- `App\Http\Controllers\Api\CoverBundleController::destroy`
