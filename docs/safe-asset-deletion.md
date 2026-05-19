# Safe asset deletion (Laravel)

This document describes how **`DELETE /api/sounds/{sound}`** removes catalog rows and optionally deletes backing objects in **DigitalOcean Spaces**.

---

## Sound delete is blocked when attached to a vibe

Before any database or storage cleanup, the API checks:

| Table | Meaning |
| --- | --- |
| `vibe_sounds` | Sound layer on a **user** vibe |
| `preset_vibe_sounds` | Sound layer on an **admin preset** vibe |

If any row references `sound_id`, the API responds with **`409 Conflict`** and JSON:

```json
{
  "message": "This sound is currently used by one or more vibes and cannot be deleted."
}
```

The **`Sound` row is not deleted**. No Spaces cleanup runs.

---

## When Spaces objects are deleted

After the checks above succeed:

1. **`file_url`** and **`thumbnail_url`** are collected (non-empty strings only; duplicates are deduplicated).
2. The **`Sound` row is deleted** inside a transaction.
3. For each URL, **`SafeAssetDeletionService`** decides whether to call **`DigitalOceanSpacesService::delete()`**.

Deletion runs **only after** the sound row is gone, so reference counting does **not** need special casing for “this sound’s own row.”

### Reference counting

For each URL, we call **`StorageAssetReferenceService::countReferencesToUrl($url)`**, which counts every row (across configured tables/columns) that stores **exactly** that URL string.

- **`count === 0`** — safe to delete the object from Spaces (nothing in the DB points at that URL anymore).
- **`count > 0`** — **shared** asset; the object is **not** deleted.

### Spaces vs external URLs

We derive an object key with **`DigitalOceanSpacesService::keyFromUrl($url)`**.

- If **`keyFromUrl` returns `null`**, the URL is treated as **external** (e.g. legacy Firebase or another host). We **do not** delete; no error is raised for the HTTP client.
- If a key is resolved, we attempt delete only when **`countReferencesToUrl === 0`** as above.

---

## Status outcomes (per URL)

Internal logging and the deletion helper distinguish:

| Status | Meaning |
| --- | --- |
| `deleted` | Spaces **`delete`** succeeded for the resolved key |
| `skipped_external_url` | URL does not map to our Spaces CDN/origin |
| `skipped_still_referenced` | Another DB row still stores this URL |
| `empty` | Null/blank URL (ignored in batch helpers) |
| `failed` | **`delete`** returned false (e.g. SDK/network); see logs |

Batch helper **`deleteUrlsIfUnreferenced`** returns a map **`url => status`** with deduplicated URLs.

---

## Failures after DB delete

If the **`Sound` row was deleted** but removing an object from Spaces **fails**, we **do not** roll back the database change. A **warning** is logged so operators can reconcile the orphan object manually.

---

## Logging

| Situation | Level |
| --- | --- |
| Delete blocked (sound in use) | `warning` |
| Object deleted from Spaces | `info` |
| Skipped — still referenced | `info` |
| Skipped — external URL | `info` |
| Skipped — empty URL | `info` |
| Spaces delete failed | `warning` |
| DB deleted but cleanup failed (controller summary) | `warning` |

---

## Limitations

- Only **canonical URL equality** is considered; two different URLs that point at the same bytes are **not** merged.
- Tables/columns surveyed by **`StorageAssetReferenceService`** define “shared”; new URL columns require updating that service to stay safe.
- **`DELETE /api/sounds/{sound}`** is the scope documented here; other entities are unchanged.

---

## Related code

- `App\Services\Storage\SafeAssetDeletionService`
- `App\Services\Storage\DigitalOceanSpacesService`
- `App\Services\Storage\StorageAssetReferenceService`
- `App\Http\Controllers\Api\SoundController::destroy`
