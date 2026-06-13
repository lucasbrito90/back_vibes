<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Schema hardening (Scheduler MVP).
 *
 * Adds: timezone, next_run_at, last_run_at, updated_at.
 * Adds indexes: (next_run_at, is_enabled), (user_id, vibe_id).
 * Data migration: recurrence_type 'none' → 'once'.
 *
 * Safe two-step for NOT NULL columns:
 *   1. Add nullable → 2. Backfill → 3. Alter to NOT NULL.
 *
 * References: ADR-009 (UTC storage), spec.md § schedules.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: Add new columns (nullable for safe backfill) ──────────────
        Schema::table('schedules', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('name');
            $table->timestamp('next_run_at')->nullable()->after('is_enabled');
            $table->timestamp('last_run_at')->nullable()->after('next_run_at');
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        // ── Step 2: Backfill existing rows ────────────────────────────────────
        // Migrate legacy 'none' recurrence_type to 'once' (spec § RecurrenceType).
        DB::table('schedules')
            ->where('recurrence_type', 'none')
            ->update(['recurrence_type' => 'once']);

        // Backfill timezone with 'UTC' — safe default per ADR-009.
        DB::table('schedules')
            ->whereNull('timezone')
            ->update(['timezone' => 'UTC']);

        // Backfill updated_at from created_at for any pre-existing rows.
        DB::table('schedules')
            ->whereNull('updated_at')
            ->update(['updated_at' => DB::raw('created_at')]);

        // ── Step 3: Harden timezone to NOT NULL now all rows have a value ─────
        Schema::table('schedules', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable(false)->change();
        });

        // ── Step 4: Add dispatcher + owner indexes ────────────────────────────
        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['next_run_at', 'is_enabled'], 'idx_schedules_next_run_enabled');
            $table->index(['user_id', 'vibe_id'], 'idx_schedules_user_vibe');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('idx_schedules_next_run_enabled');
            $table->dropIndex('idx_schedules_user_vibe');
            $table->dropColumn(['timezone', 'next_run_at', 'last_run_at', 'updated_at']);
        });
    }
};
