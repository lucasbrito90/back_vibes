<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4B — make devices.type nullable.
 *
 * The original stub defined type as NOT NULL, but the Smart Home MVP spec
 * requires type to be nullable (device type label is optional on manual create;
 * sync populates it from provider metadata when available).
 *
 * References: spec.md §2.5, StoreDeviceRequest validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('type')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('type')->nullable(false)->change();
        });
    }
};
