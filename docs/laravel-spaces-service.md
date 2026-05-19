# Laravel — DigitalOcean Spaces layer

Backend-only integration for **DigitalOcean Spaces** (S3-compatible). This matches the policy and key layout in [`storage-strategy.md`](storage-strategy.md).

## Scope (this phase)

- Configuration (`filesystems.disks.spaces`) and environment variables.
- **DigitalOceanSpacesService** — read/write/delete/exists against the `spaces` disk, CDN `publicUrl`, and safe `keyFromUrl` parsing.
- **StoragePathBuilder** — canonical object keys for sounds, cover bundles, vibes, and user avatars.
- **StorageAssetReferenceService** — count database rows that reference a given URL string (foundation for future safe deletes).

**Not included yet:** upload HTTP endpoints, automatic deletes, reset/reconcile commands, image/audio processing, Nuxt Admin or mobile changes, or migrating real objects.

## Environment variables

Set these on the Laravel host (and workers). **Never commit real keys** — only placeholders in `.env.example`.

| Variable | Purpose |
| --- | --- |
| `DO_SPACES_KEY` | Spaces access key |
| `DO_SPACES_SECRET` | Spaces secret |
| `DO_SPACES_BUCKET` | Bucket name (default `ixora-buckets`) |
| `DO_SPACES_REGION` | Region slug (default `tor1`) |
| `DO_SPACES_ENDPOINT` | S3 API endpoint (default `https://tor1.digitaloceanspaces.com`) |
| `DO_SPACES_CDN_URL` | **Public** asset base URL for clients (CDN hostname), e.g. `https://ixora-buckets.tor1.cdn.digitaloceanspaces.com` |

Credentials are read **only** from the environment. Laravel remains the **only** component with Spaces read/write/delete credentials.

## Filesystem disk `spaces`

Defined in `config/filesystems.php`:

- `driver` => `s3`
- `url` => `DO_SPACES_CDN_URL` — used by the Flysystem adapter’s URL generation helpers and aligned with **`publicUrl()`** in code.
- `endpoint` / `bucket` / `region` — SDK access to the origin API.
- `throw` => `true` — failed storage operations surface exceptions where the adapter throws.

Use `Storage::disk('spaces')` directly only when appropriate; prefer **DigitalOceanSpacesService** for app-level behaviour (normalised keys, CDN URLs).

## Path builder

`App\Services\Storage\StoragePathBuilder` returns logical keys **without** a leading slash, following [`storage-strategy.md`](storage-strategy.md):

| Method | Key pattern |
| --- | --- |
| `soundAudio($id, $ext)` | `sounds/{id}/audio/original.{ext}` |
| `soundThumbnail($id)` | `sounds/{id}/thumbnail/thumbnail.webp` |
| `coverThumbnail($id)` | `covers/{id}/thumbnail/thumbnail.webp` |
| `coverArtwork($id)` | `covers/{id}/artwork/artwork.webp` |
| `coverPlayerBackground($id)` | `covers/{id}/player-background/background.webp` |
| `vibeThumbnail($id)` | `vibes/{id}/thumbnail/thumbnail.webp` |
| `vibeArtwork($id)` | `vibes/{id}/artwork/artwork.webp` |
| `vibePlayerBackground($id)` | `vibes/{id}/player-background/background.webp` |
| `userAvatar($id)` | `users/{id}/avatar/avatar.webp` |

## `publicUrl` and `keyFromUrl`

- **`publicUrl(string $key)`** — joins `DO_SPACES_CDN_URL` with a normalised key (no leading `/`). Clients should only see CDN URLs.
- **`keyFromUrl(string $url)`** — extracts the object key from:
  - CDN URLs (`DO_SPACES_CDN_URL` host),
  - Virtual-hosted origin URLs (`{bucket}.{region}.digitaloceanspaces.com`),
  - Path-style URLs on the configured endpoint host (`/{bucket}/{key}`).

Returns **`null`** if the host is not recognised or the path is empty — e.g. foreign domains must not parse as bucket keys.

## Reference counting

`StorageAssetReferenceService::countReferencesToUrl(string $url)` counts rows whose stored URL **exactly equals** `$url` across:

`sounds.file_url`, `sounds.thumbnail_url`, `cover_bundles.thumbnail_url`, `cover_bundles.artwork_url`, `cover_bundles.player_background_url`, `vibes.thumbnail_url`, `vibes.artwork_url`, `vibes.player_background_url`, `users.avatar_url`.

Tables/columns are skipped if they do not exist (`Schema::hasTable` / `Schema::hasColumn`). This is a **string equality** check only; it does not resolve redirects or alternate URL forms.

## Next steps

1. Authenticated upload endpoints (multipart / validation) returning CDN URLs.
2. Admin UI uploads via Laravel only (no client Spaces keys).
3. Safe deletion workflow using reference counts + [deletion rules](storage-strategy.md#deletion-rules).
4. Optional maintenance commands (dry-run first).
