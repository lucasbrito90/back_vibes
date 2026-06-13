<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Schema hardening (Scheduler MVP).
 *
 * Adds: occurrence_key, scheduled_for.
 * Adds unique index: (schedule_id, occurrence_key) — idempotency guard per ADR-010.
 * Aligns status default to 'dispatched' (MVP default per spec).
 *
 * Safe two-step for NOT NULL columns:
 *   1. Add nullable → 2. Backfill → 3. Alter to NOT NULL.
 *
 * For existing rows that have no meaningful occurrence_key:
 *   synthetic key '{id}:{id}' is generated to satisfy NOT NULL;
 *   these legacy rows are audit artifacts only and will not collide
 *   with real dispatcher keys (format '{schedule_id}:{unix_ts}').
 *
 * References: ADR-010 (idempotency), spec.md § schedule_executions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: Add new columns (nullable for safe backfill) ──────────────
        Schema::table('schedule_executions', function (Blueprint $table) {
            $table->string('occurrence_key', 64)->nullable()->after('schedule_id');
            $table->timestamp('scheduled_for')->nullable()->after('occurrence_key');
        });

        // ── Step 2: Backfill existing rows ────────────────────────────────────
        // Legacy rows predate the dispatcher — synthesize a unique occurrence_key
        // from the row's own id so the NOT NULL + unique constraints are satisfiable.
        DB::table('schedule_executions')
            ->whereNull('occurrence_key')
            ->get(['id'])
            ->each(function (object $row) {
                DB::table('schedule_executions')
                    ->where('id', $row->id)
                    ->update([
                        'occurrence_key' => "legacy:{$row->id}",
                        'scheduled_for' => DB::raw('executed_at'),
                    ]);
            });

        // ── Step 3: Harden to NOT NULL now all rows have values ───────────────
        Schema::table('schedule_executions', function (Blueprint $table) {
            $table->string('occurrence_key', 64)->nullable(false)->change();
            $table->timestamp('scheduled_for')->nullable(false)->change();
        });

        // ── Step 4: Unique idempotency index (ADR-010) ────────────────────────
        Schema::table('schedule_executions', function (Blueprint $table) {
            $table->unique(['schedule_id', 'occurrence_key'], 'uq_sch_exec_schedule_occurrence');
        });

        // ── Step 5: Align status column comment to MVP values ─────────────────
        // The original comment was 'success, failed'; MVP adds 'dispatched' as default.
        // We update the column definition to reflect the new comment; existing values
        // remain valid as status is an open string in the schema (no DB-level enum).
        Schema::table('schedule_executions', function (Blueprint $table) {
            $table->string('status')
                ->default('dispatched')
                ->comment('dispatched | acknowledged | failed | skipped')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_executions', function (Blueprint $table) {
            $table->dropUnique('uq_sch_exec_schedule_occurrence');
            $table->dropColumn(['occurrence_key', 'scheduled_for']);
        });

        Schema::table('schedule_executions', function (Blueprint $table) {
            $table->string('status')
                ->default(null)
                ->comment('success, failed')
                ->change();
        });
    }
};
