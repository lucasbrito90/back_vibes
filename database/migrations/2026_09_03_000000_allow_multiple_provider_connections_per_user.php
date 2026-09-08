<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.4.0 T12 — allow multiple provider_connections per user for the same provider slug.
 *
 * Replaces UNIQUE (user_id, provider) with UNIQUE (user_id, name) per ADR-032 decision C.
 * Device dedupe key (provider_connection_id, provider_device_id) is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('provider_connections')
            ->select('user_id', 'name')
            ->groupBy('user_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add uq_provider_connections_user_name: duplicate (user_id, name) rows exist. '
                .'Resolve manually before migrating.'
            );
        }

        Schema::table('provider_connections', function (Blueprint $table) {
            $table->dropUnique('uq_provider_connections_user_provider');
            $table->unique(['user_id', 'name'], 'uq_provider_connections_user_name');
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table) {
            $table->dropUnique('uq_provider_connections_user_name');
            $table->unique(['user_id', 'provider'], 'uq_provider_connections_user_provider');
        });
    }
};
