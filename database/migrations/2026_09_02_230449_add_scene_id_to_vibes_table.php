<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vibes', function (Blueprint $table) {
            $table->foreignId('scene_id')
                ->nullable()
                ->after('user_id')
                ->constrained('scenes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vibes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scene_id');
        });
    }
};
