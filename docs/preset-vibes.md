# Preset Vibes API

## Concept

A **Preset Vibe** is an admin-authored template: a named composition (metadata + ordered sound layers + optional cover bundle) that lives in `preset_vibes` / `preset_vibe_sounds`. It is **not** owned by an end user.

A **user vibe** (`vibes`, `vibe_sounds`) belongs to `users.id` and is what the mobile app edits today.

Preset vibes are the backend foundation for **importing** templates into a user library:

- **`POST /api/preset-vibes/{preset_vibe}/import`** — authenticated users copy an **active** preset into a new **`vibes`** row they own (see [Import](#import-preset-into-user-library)).

Mobile can list presets (`GET /api/preset-vibes`), show details, then call **import** when the user confirms; no admin approval is required for import.

## Data model

| Piece | Role |
| --- | --- |
| `preset_vibes` | Template row: name, description, optional `cover_bundle_id`, category, tags JSON, `is_active`. |
| `preset_vibe_sounds` | Layers: `sound_id`, volume, `play_mode`, `loop`, ordering, interval/repeat and timing fields aligned with `vibe_sounds` naming (`start_offset_seconds`, `play_duration_seconds`). |
| `CoverBundle` | Optional visual pack linked by `cover_bundle_id` (nullable FK, null on delete). |
| `Sound` | Catalog asset referenced by each layer (`sound_id`, FK with cascade on delete). |

API responses expose **`start_delay_seconds`** and **`duration_seconds`** as aliases for **`start_offset_seconds`** and **`play_duration_seconds`** so the sync payload and JSON stay symmetrical.

## Endpoints

Base path: `/api/preset-vibes`

| Method | Path | Middleware |
| --- | --- | --- |
| `GET` | `/api/preset-vibes` | `firebase.auth` |
| `GET` | `/api/preset-vibes/{preset_vibe}` | `firebase.auth` |
| `POST` | `/api/preset-vibes/{preset_vibe}/import` | `firebase.auth` |
| `POST` | `/api/preset-vibes` | `firebase.auth` + `admin.approved` |
| `PATCH` / `PUT` | `/api/preset-vibes/{preset_vibe}` | `firebase.auth` + `admin.approved` |
| `DELETE` | `/api/preset-vibes/{preset_vibe}` | `firebase.auth` + `admin.approved` |
| `PUT` | `/api/preset-vibes/{preset_vibe}/sounds` | `firebase.auth` + `admin.approved` |

`admin.approved` means `User::isAdminApproved()` (`role === admin` and `admin_access_status === approved`).

### Listing rules

- Default list: **`is_active = true` only**, ordered by **`name` ascending**.
- Query **`include_inactive=1`**: inactive presets appear **only** if the caller is an approved admin; otherwise the flag is ignored and inactive rows stay hidden.

### Show rules

- If the preset is **inactive** and the user is **not** an approved admin → **404**.
- Approved admins can load inactive presets by id.

### Import preset into user library

`POST /api/preset-vibes/{preset_vibe}/import`

- **Auth:** `firebase.auth` only (no `admin.approved`).
- **Rule:** the preset must be **`is_active === true`**. Inactive presets return **404** (same strict rule for every caller — import is a catalog action, not an admin preview).
- **Transaction:** creates one **`vibes`** row for **`auth()->id()`** and attaches **`vibe_sounds`** from **`preset_vibe_sounds`** inside **`DB::transaction()`**.
- **Independence:** the new vibe has **no FK** to the preset; the user may edit or delete it like any other vibe.

**Copied onto `vibes`**

| Source | Target fields |
| --- | --- |
| Preset | `name`, `description`, `is_active` → always **`true`** on the new vibe |
| Cover bundle (if `cover_bundle_id` set) | `thumbnail_url`, `artwork_url`, `player_background_url` copied from the bundle row |

**Not copied:** preset `category` / `tags` — the **`vibes`** table has no matching columns today.

**Cover bundle `is_active`:** URLs are still copied if the preset references a bundle, even when that bundle is inactive (simple behaviour: the preset already points at those assets).

**Copied onto each `vibe_sounds` pivot** (from `preset_vibe_sound`):

- `sound_id` (same catalog sound)
- `volume`, `sort_order`, `play_mode`, `loop` (derived consistently with `play_mode`)
- `repeat_interval_seconds` (only when `play_mode === interval`, otherwise `null`)
- `start_offset_seconds`, `play_duration_seconds`
- `fade_in_seconds` / `fade_out_seconds` → **`null`** (not stored on presets today)

Presets with **no layers** still import as an empty vibe.

**Response:** **201** with **`VibeResource`** (`data` wrapper). The relationship **`sounds`** is eager-loaded for this response so clients receive **`sounds`** + **`sounds_count`** immediately. Other vibe endpoints omit **`sounds`** unless the relation is loaded.

## Sync sounds payload

`PUT /api/preset-vibes/{preset_vibe}/sounds` **replaces** all layers for that preset inside a database transaction.

```json
{
  "sounds": [
    {
      "sound_id": 1,
      "play_mode": "loop",
      "volume": 80,
      "sort_order": 0,
      "start_delay_seconds": 0,
      "repeat_interval_seconds": null,
      "duration_seconds": null
    }
  ]
}
```

- **`play_mode`**: `loop` \| `once` \| `interval` (default `loop`).
- **`volume`**: `0`–`100` (default `100`).
- **`repeat_interval_seconds`**: required when `play_mode` is `interval`, integer ≥ `1` when present.
- **`start_delay_seconds`** / **`start_offset_seconds`**: optional non-negative delay before play (aliases).
- **`duration_seconds`** / **`play_duration_seconds`**: optional cap, integer ≥ `1` when set.
- Each **`sound_id`** must exist in `sounds` and must be **unique** within the array.

## Demo data

`PresetVibeSeeder` creates a few demo presets **only when** matching `Sound` rows exist (e.g. after `SoundSeeder`). It skips work when the catalog is empty or names do not match, so fresh installs do not break.

## Related docs

- Cover bundles: `docs/cover-bundles.md`
- Sound catalog auth: `AGENTS.md` (authorization overview)
