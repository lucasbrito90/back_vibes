<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table) {
            // Separated from last_tested_at (PRV-02): sync operations set this column;
            // explicit /test calls set last_tested_at. The two timestamps are independent.
            $table->timestamp('last_synced_at')->nullable()->after('last_tested_at');
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table) {
            $table->dropColumn('last_synced_at');
        });
    }
};
