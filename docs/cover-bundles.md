# Cover Bundles API

## Concept

A **Cover Bundle** is a **reusable visual identity package** for vibes: thumbnail, full artwork, and player background URLs plus catalog metadata (name, description, category, tags, active flag).

**Sounds** hold **audio assets only** (`file_url`, duration, etc.). **Cover Bundles** hold **presentation**: what the user sees around playback. A vibe will eventually reference both catalog sounds and an optional cover bundle (assignment is not part of this API yet).

## Recommended asset sizes

These are guidelines for designers and admins; the API stores HTTPS URLs only.

| Field | Suggested size |
|--------|----------------|
| `thumbnail_url` | 512×512 or 1024×1024 |
| `artwork_url` | 1024×1024 |
| `player_background_url` | 1440×3200 (portrait hero / player shell) |

## Endpoints

Base path: `/api/cover-bundles`

| Method | Path | Auth |
|--------|------|------|
| GET | `/api/cover-bundles` | Firebase Bearer (`firebase.auth`) |
| GET | `/api/cover-bundles/{id}` | Firebase Bearer |
| POST | `/api/cover-bundles` | Firebase Bearer + approved admin (`admin.approved`) |
| PATCH / PUT | `/api/cover-bundles/{id}` | Firebase Bearer + approved admin |
| DELETE | `/api/cover-bundles/{id}` | Firebase Bearer + approved admin |

Writes return **403** `{ "message": "Admin access is not approved." }` when the user is not an approved admin (same pattern as catalog sounds).

## Listing rules

- Default **GET** list returns only **`is_active = true`** bundles, ordered by **`name`** ascending.
- **`?include_inactive=1`** (truthy query flag): includes inactive bundles **only** if the caller is an **approved admin**. Non-admins passing this flag still receive **active-only** results (inactive IDs are not leaked via the list).
- **GET** single bundle: inactive bundles return **404** for non–approved-admin users; approved admins can load inactive rows by id.

## Response shape

JSON uses Laravel API resources (typically wrapped under `data`). Fields:

`id`, `name`, `description`, `thumbnail_url`, `artwork_url`, `player_background_url`, `category`, `tags` (array), `is_active`, `created_at`, `updated_at`.

## Mobile consumption

1. Sync/login so Laravel knows the Firebase user (`POST /api/auth/sync` as today).
2. **GET `/api/cover-bundles`** with `Authorization: Bearer <Firebase ID token>` → pick an active bundle for UI theming when vibe assignment exists.

## Admin creation (future UI)

Approved admins call **POST `/api/cover-bundles`** with validated JSON (URLs must pass Laravel `url` rule). The Nuxt admin panel can be wired later without changing these contracts.

## Policy

`App\Policies\CoverBundlePolicy` mirrors route intent (`view*` for authenticated users; mutations require `User::isAdminApproved()`). Routes are enforced with **`admin.approved`** middleware on writes.
