# `ixora:reset-content` — safe catalog wipe

Artisan command to **delete relational catalog data** for Ixora (sounds, user vibes, preset vibes, cover bundles, schedule-related rows, pivots) while **keeping accounts and platform tables**.

## Purpose

- Prepare a clean slate after migrating asset hosting (e.g. Firebase Storage → DigitalOcean Spaces) **without** touching Spaces objects from this command.
- Drop stale **database rows** so new CDN URLs and catalog entries can be recreated manually or via admin flows.

## When to use

- **Development / staging**: reset content between migration experiments.
- **Production**: only when you explicitly intend to wipe catalog data — the command requires `--confirm` and, in **production**, an **additional interactive confirmation**.

This command is **destructive**. Always backup the database first in production.

## Execution

```bash
php artisan ixora:reset-content           # aborts — shows warning only
php artisan ixora:reset-content --confirm
```

In **`production`**, after `--confirm`, Laravel will ask:

`You are running this in production. Are you absolutely sure?`

Unit tests (`APP_ENV=testing`) skip this second prompt so CI stays non-interactive.

## What gets deleted (in order)

For each table, if `Schema::hasTable()` is false, the step is skipped.

1. `vibe_sounds`
2. `preset_vibe_sounds`
3. `schedule_executions`
4. `schedules`
5. `vibe_device_actions`
6. `vibes`
7. `preset_vibes`
8. `cover_bundles`
9. `sounds`

All deletes run inside a single **`DB::transaction()`** so a failure rolls back.

## What is preserved

- **`users`**
- **`admin_access_requests`**
- **`devices`**, **`user_settings`** (and other user-owned infra not listed above)
- **`migrations`** (and migration history)
- **`password_reset_tokens`**, **`sessions`**, **`cache`**, **`jobs`**, **`failed_jobs`**, **`personal_access_tokens`** (if present)
- **Objects in DigitalOcean Spaces** — **not** deleted by this command

## What this command does **not** do

- **No Storage / Spaces deletes** — orphaned objects, if any, must be cleaned separately (future tooling).
- **No Firebase or auth changes**
- **Does not** modify Nuxt Admin or mobile apps

## Logging

Each step logs counts via **`Log::info`** (`Ixora content reset …`). The console prints a summary table and total rows deleted.

## Related docs

- [`storage-strategy.md`](storage-strategy.md) — asset ownership and migration policy  
- [`laravel-upload-endpoints.md`](laravel-upload-endpoints.md) — uploads after reset
