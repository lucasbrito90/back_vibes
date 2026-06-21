<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A — vibe_device_actions schema hardening (Smart Home MVP).
 *
 * Changes applied:
 *   - Add sort_order unsignedInteger default 0 (execution ordering per ADR-015)
 *   - Add updated_at timestamp
 *   - Add non-unique index (vibe_id, sort_order) for ordered fetch
 *
 * No unique constraint is added — multiple actions per device/vibe are valid
 * (e.g. turn_on then turn_off with different delays on the same device).
 *
 * References: ADR-015, schema-review.md §3.2, plan.md §Phase 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: Add columns ────────────────────────────────────────────────
        Schema::table('vibe_device_actions', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('delay_seconds');
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        // ── Step 2: Backfill updated_at from created_at ───────────────────────
        DB::table('vibe_device_actions')
            ->whereNull('updated_at')
            ->update(['updated_at' => DB::raw('created_at')]);

        // ── Step 3: Optional ordered-fetch index ──────────────────────────────
        Schema::table('vibe_device_actions', function (Blueprint $table) {
            $table->index(['vibe_id', 'sort_order'], 'idx_vda_vibe_sort');
        });
    }

    public function down(): void
    {
        Schema::table('vibe_device_actions', function (Blueprint $table) {
            $table->dropIndex('idx_vda_vibe_sort');
            $table->dropColumn(['sort_order', 'updated_at']);
        });
    }
};
