<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vibes', function (Blueprint $table) {
            // Firebase Storage URL for the vibe artwork.
            // Used as the player full-screen background, card thumbnail,
            // and Android MediaSession / lock-screen notification artwork.
            $table->string('thumbnail_url')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('vibes', function (Blueprint $table) {
            $table->dropColumn('thumbnail_url');
        });
    }
};
