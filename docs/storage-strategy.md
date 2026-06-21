# Ixora — Storage & assets strategy

Official strategy for **where** assets live, **who** may write, and **how** URLs flow to clients.  
This file is intentionally duplicated under `docs/storage-strategy.md` in **back_vibes**, **ixora-admin**, and **front_vibes**; **keep all copies in sync** when you change the strategy.

---

## Context

Ixora persists references to user-facing assets for:

| Domain | Purpose |
| --- | --- |
| **Sounds** | Catalog audio + thumbnails |
| **Cover bundles** | Reusable visual packs (thumbnail, artwork, player background) |
| **Vibes** | Per-user (or imported) visuals layered on sounds |
| **Users** | Profile avatars |

We are **moving object storage from Firebase Storage to DigitalOcean Spaces**.  
**This document does not migrate data**, change runtime code, or describe implementation details beyond policy and layout.

---

## Core rules

1. **Laravel API is the only component** with permission to **read / write / delete** objects in DigitalOcean Spaces (via server-side credentials).
2. **Nuxt Admin** and **Mobile** must **not** hold Spaces **write** or **delete** credentials.
3. **Nuxt Admin** uploads files by **POSTing (or equivalent) to Laravel**, which validates auth and writes to Spaces.
4. **Mobile** and **Admin UIs** consume assets as **public HTTPS URLs** (typically via CDN).
5. **Public URLs must use the CDN hostname** (better caching and egress economics):

   `https://ixora-buckets.tor1.cdn.digitaloceanspaces.com`

6. **Spaces origin** (S3-compatible endpoint; useful for server SDK configuration):

   `https://ixora-buckets.tor1.digitaloceanspaces.com`

7. **Bucket:** `ixora-buckets`  
8. **Region:** `tor1`

9. **Never commit secrets.** No API keys, secrets, or `.env` contents belong in git.

10. **All credentials live only in environment variables** on the Laravel host (and any future worker), for example:

    ```env
    DO_SPACES_KEY=
    DO_SPACES_SECRET=
    ```

    (Exact variable names may evolve in implementation; placeholders above illustrate **never** documenting real values.)

---

## Recommended object key layout

Keys are **logical paths inside the bucket** (no leading slash). Extensions are examples; prefer **WebP** for bitmaps where the pipeline supports it.

```
sounds/{sound_id}/audio/original.{ext}
sounds/{sound_id}/thumbnail/thumbnail.webp

covers/{cover_bundle_id}/thumbnail/thumbnail.webp
covers/{cover_bundle_id}/artwork/artwork.webp
covers/{cover_bundle_id}/player-background/background.webp

vibes/{vibe_id}/thumbnail/thumbnail.webp
vibes/{vibe_id}/artwork/artwork.webp
vibes/{vibe_id}/player-background/background.webp

users/{user_id}/avatar/avatar.webp
```

New uploads should follow this layout; legacy rows may still point at older URLs until migration completes.

---

## Field ownership by entity

Database columns store **full public CDN URLs** (or nullable when unset).

| Entity | Stored URL fields | Role |
| --- | --- | --- |
| **Sound** | `file_url`, `thumbnail_url` | Canonical audio + square/list thumb |
| **CoverBundle** | `thumbnail_url`, `artwork_url`, `player_background_url` | Shared visual kit |
| **Vibe** | `thumbnail_url`, `artwork_url`, `player_background_url` | User-facing visuals for that vibe |
| **User** | `avatar_url` | Profile image |

---

## Client behaviour (policy)

| Client | Read | Write | Delete |
| --- | --- | --- | --- |
| **Laravel API** | Yes | Yes | Yes (guarded; see deletion rules) |
| **Nuxt Admin** | CDN URLs | Via Laravel only | Via Laravel only (future) |
| **Mobile** | CDN URLs | No direct Spaces | No direct Spaces |

---

## Deletion rules

Object deletion in Spaces is **irreversible** and **must stay consistent** with the relational model.

1. **Never delete an object** without confirming **no remaining references** in the database to that URL (or to that logical asset).
2. **An asset may be deleted only when no entity still references that stored URL.**  
   Prefer checks against **canonical URL strings** stored on rows (not fuzzy path guesses alone).
3. **Sound assets** (`sounds/...`): remove only if the **Sound** row can be safely removed **and**:
   - the sound is **not** attached to any **user vibe** (`vibe_sounds`), **and**
   - the sound is **not** attached to any **preset vibe** (`preset_vibe_sounds`).
4. **Cover bundle assets** (`covers/...`): remove only if:
   - no **PresetVibe** references the bundle via `cover_bundle_id`, **and**
   - no **user Vibe** still stores URLs that **point at** those bundle objects (copied URLs count as separate references).  
   **Implementation:** Laravel blocks `DELETE /api/cover-bundles/{id}` when either condition fails, then removes Spaces keys only when no row still stores the same URL (see [`safe-asset-deletion.md`](safe-asset-deletion.md)).
5. **Vibe assets** (`vibes/...`): delete **only** objects that are **exclusive** to that vibe — i.e. URLs written under `vibes/{vibe_id}/...` that are **not** shared objects.
6. **Copy semantics:** When a vibe **copies** URLs from a cover bundle (e.g. preset import or “apply bundle”), those vibe rows **reference the same URLs** as the bundle until replaced. **Deleting “the vibe’s assets” must not remove bundle-owned keys** unless policy explicitly forks files into `vibes/{id}/...` first.

Operational guideline: implement **reference counting or orphan scans** before delete; default to **no delete** when uncertain.

**Implementation:** Laravel blocks catalog deletes when relational rules fail, then removes Spaces objects only when no row still stores the same URL. **Sounds:** [`safe-asset-deletion.md`](safe-asset-deletion.md). **Cover bundles:** same document (`DELETE /api/cover-bundles/{cover_bundle}`).

---

## Migration notes

- **This phase does not migrate bytes** from Firebase Storage to DigitalOcean Spaces.
- **Manual or scripted migration** of existing objects will be a **separate project**, with backups and validation.
- **Legacy rows** (older Firebase URLs) may coexist until cleaned up.
- A future **Laravel artisan command** may normalize URLs, re-upload, or **detach stale paths** — design TBD.
- **Firebase Storage will be phased out** for **new** uploads as Laravel + Spaces becomes authoritative.

---

## Future implementation phases

1. **Laravel Spaces service** — configured client, URL builders (CDN vs internal), env-driven credentials. **Implementation notes:** [`laravel-spaces-service.md`](laravel-spaces-service.md) (disk `spaces`, path builder, `publicUrl` / `keyFromUrl`, reference counting).
2. **Laravel upload endpoints** — authenticated routes that accept uploads (multipart / signed strategy TBD), validate MIME/size, write keys under the layout above, return public CDN URLs. **Shipped:** generic admin multipart endpoint documented in [`laravel-upload-endpoints.md`](laravel-upload-endpoints.md) (`POST /api/admin/uploads`). Nuxt integration is still pending.
3. **Nuxt Admin** — replace direct-to-third-party uploads with **proxied uploads via Laravel**; display returned CDN URLs only.
4. **Reset / cleanup command** — optional maintenance to reconcile DB URLs vs bucket inventory (dry-run first).
5. **Safe asset deletion** — **partially shipped** for **catalog sounds** (`DELETE /api/sounds/{sound}`) and **cover bundles** (`DELETE /api/cover-bundles/{cover_bundle}`): reference-checked Spaces cleanup after DB delete (see [`safe-asset-deletion.md`](safe-asset-deletion.md)). Other entities / soft-delete TBD.
6. **Mobile** — remain **read-only** against CDN; no Spaces credentials in the app binary or env shipped to devices.
7. **Avatar upload** — future Laravel endpoint + mobile picker posting to API (still **no** client Spaces keys).

---

## Related documentation

- Safe deletion (sounds & cover bundles): [`safe-asset-deletion.md`](safe-asset-deletion.md).
- Laravel admin uploads: [`laravel-upload-endpoints.md`](laravel-upload-endpoints.md).
- Admin uploads (current behaviour): `ixora-admin/docs/upload-validation.md`.
- Mobile artwork fallbacks: `front_vibes/docs/artwork-background-strategy.md`.
