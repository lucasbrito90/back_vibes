<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add devices.state + devices.state_read_at (DEV-01, ADR-037 §6).
 *
 * ADR-037 §6 fixes the SHAPE of DeviceState but explicitly declines to mandate
 * persistence, leaving live/cache/persist to the implementing task. DEV-01
 * chooses persistence, and the deciding constraint is not performance:
 * google_home declares `state_read` but NOT `server_side_execution`, so the
 * backend has no adapter it can poll and never will (ADR-036 §1-3). Device-side
 * state can only arrive as an inbound client report, and an inbound report has
 * nowhere to live except durable storage — a request-scoped live read is
 * impossible for that provider class by construction, and a TTL cache would be
 * the same store with weaker guarantees than ADR-036 Decision 8's retention
 * statement allows.
 *
 * `state` holds ONLY the canonical `values` map (capability id => value).
 * Connectivity is NOT repeated here: `devices.status` remains the sole
 * authority on reachability, exactly as ADR-037 §6 requires.
 *
 * `state_read_at` is a THIRD, distinct timestamp. It is not interchangeable
 * with either existing one and deliberately does not reuse a column:
 * - `devices.last_seen_at`      — when the device last appeared in a catalog sync
 * - `provider_connections.last_tested_at`  — when the CONNECTION was last tested
 * - `provider_connections.last_synced_at`  — when the catalog was last synced
 * - `devices.state_read_at`     — when this device's FUNCTIONAL state was observed
 *
 * Existing rows are left at null, which the read path reports as `unknown`
 * rather than inventing a value — no backfill is possible or honest here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->json('state')->nullable()->after('capabilities');
            $table->timestamp('state_read_at')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['state', 'state_read_at']);
        });
    }
};
