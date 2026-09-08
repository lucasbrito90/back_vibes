<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add devices.capabilities (ADR-033) — json, nullable.
 *
 * Existing rows are backfilled to null (unknown / fail-open per ADR-033 decision 5).
 * Derivation from provider metadata is T16/T18; this migration only prepares storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('metadata');
        });

        // Explicit neutral backfill: null = unknown (ADR-033 §2, §5). Do not infer from type/metadata.
        DB::table('devices')->update(['capabilities' => null]);
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }
};
