<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A — devices schema hardening (Smart Home MVP).
 *
 * Changes applied:
 *   - Add provider_connection_id FK → provider_connections.id (cascadeOnDelete)
 *   - Rename external_id → provider_device_id (domain language per ADR-014)
 *   - Add status string(32) default 'unknown'
 *   - Add last_seen_at timestamp nullable
 *   - Add updated_at timestamp
 *   - Drop old non-unique index (user_id, provider, external_id)
 *   - Add UNIQUE (provider_connection_id, provider_device_id) — dedup key per schema-review §4.1
 *   - Add non-unique index (user_id, provider) — for Devices tab list filtering
 *
 * Safe strategy:
 *   1. Add nullable columns
 *   2. Drop old index
 *   3. Rename column
 *   4. Backfill status, updated_at, provider slugs
 *   5. Safety check: abort if any device row has no provider_connection_id
 *   6. Harden columns to NOT NULL
 *   7. Add unique constraint + index
 *
 * References: ADR-014, schema-review.md §3.1/§4.1, plan.md §Phase 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: Add new columns (all nullable for safe backfill) ──────────
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_connection_id')->nullable()->after('user_id');
            $table->foreign('provider_connection_id', 'fk_devices_provider_connection_id')
                ->references('id')->on('provider_connections')
                ->cascadeOnDelete();
            $table->string('status', 32)->nullable()->after('metadata');
            $table->timestamp('last_seen_at')->nullable()->after('status');
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        // ── Step 2: Drop the old non-unique composite index ───────────────────
        // Index was created by: $table->index(['user_id', 'provider', 'external_id'])
        // Auto-generated name: devices_user_id_provider_external_id_index
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex('devices_user_id_provider_external_id_index');
        });

        // ── Step 3: Rename external_id → provider_device_id ──────────────────
        Schema::table('devices', function (Blueprint $table) {
            $table->renameColumn('external_id', 'provider_device_id');
        });

        // ── Step 4: Backfill existing rows ────────────────────────────────────
        // Normalize provider display name to slug (e.g. "Home Assistant" → "home_assistant").
        DB::table('devices')
            ->where('provider', 'Home Assistant')
            ->update(['provider' => 'home_assistant']);

        // All existing devices without a status get the safe default.
        DB::table('devices')
            ->whereNull('status')
            ->update(['status' => 'unknown']);

        // Backfill updated_at from created_at for any pre-existing rows.
        DB::table('devices')
            ->whereNull('updated_at')
            ->update(['updated_at' => DB::raw('created_at')]);

        // ── Step 5: Safety check ──────────────────────────────────────────────
        // If any devices remain without a provider_connection_id, the unique
        // constraint and NOT NULL cannot be applied safely. Fail loudly so the
        // operator knows these rows must be resolved manually before deploying.
        $orphanCount = DB::table('devices')->whereNull('provider_connection_id')->count();
        if ($orphanCount > 0) {
            throw new RuntimeException(
                "devices hardening migration: {$orphanCount} device row(s) have no "
                .'provider_connection_id. Delete or assign these rows to a provider '
                .'connection before running this migration.'
            );
        }

        // ── Step 6: Harden nullable columns to NOT NULL ───────────────────────
        Schema::table('devices', function (Blueprint $table) {
            $table->string('status', 32)->nullable(false)->default('unknown')->change();
            $table->unsignedBigInteger('provider_connection_id')->nullable(false)->change();
        });

        // ── Step 7: Add unique constraint and list-filter index ───────────────
        Schema::table('devices', function (Blueprint $table) {
            $table->unique(
                ['provider_connection_id', 'provider_device_id'],
                'uq_devices_connection_provider_device_id'
            );
            $table->index(['user_id', 'provider'], 'idx_devices_user_provider');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique('uq_devices_connection_provider_device_id');
            $table->dropIndex('idx_devices_user_provider');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->renameColumn('provider_device_id', 'external_id');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign('fk_devices_provider_connection_id');
            $table->dropColumn(['provider_connection_id', 'status', 'last_seen_at', 'updated_at']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->index(['user_id', 'provider', 'external_id']);
        });
    }
};
