# Preset Vibes API

## Concept

A **Preset Vibe** is an admin-authored template: a named composition (metadata + ordered sound layers + optional cover bundle) that lives in `preset_vibes` / `preset_vibe_sounds`. It is **not** owned by an end user.

A **user vibe** (`vibes`, `vibe_sounds`) belongs to `users.id` and is what the mobile app edits today.

Preset vibes are the backend foundation for a **future** flow where the app imports or copies a preset into the user’s library. That import/copy step is **not** implemented yet.

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
