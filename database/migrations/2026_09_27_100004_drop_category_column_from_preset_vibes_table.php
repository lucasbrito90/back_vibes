<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('preset_vibes', 'category')) {
            return;
        }

        Schema::table('preset_vibes', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('preset_vibes', 'category')) {
            return;
        }

        // Rollback recreates the column only — legacy text is not restored (data lives in vibe_categories).
        Schema::table('preset_vibes', function (Blueprint $table): void {
            $table->string('category', 100)->nullable();
        });
    }
};
