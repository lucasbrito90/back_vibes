<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-036 Decision 4 (P02) — a provider without server_side_execution (e.g.
 * google_home) has no credential for the server to hold. A placeholder
 * value must not be invented, so the column becomes nullable instead.
 *
 * Providers that do use server-side credentials (Home Assistant) are
 * unaffected: nothing here removes or weakens the requirement — it is
 * still enforced where it always was, at the request-validation layer
 * (ProviderConnectionValidationRulesBuilder, driven by each provider's own
 * descriptor), not at the database column level.
 *
 * Mirrors the existing nullable-column pattern in this codebase (see
 * 2026_06_17_000001_make_devices_type_nullable.php) — no doctrine/dbal
 * dependency required for ->change() in this Laravel version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table) {
            $table->text('encrypted_credentials')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table) {
            $table->text('encrypted_credentials')->nullable(false)->change();
        });
    }
};
