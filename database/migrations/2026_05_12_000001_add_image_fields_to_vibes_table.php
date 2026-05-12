<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vibes', function (Blueprint $table) {
            // Context-specific artwork fields.
            // Each field is nullable so existing vibes continue to use the
            // thumbnail_url fallback while new uploads populate these separately.
            //
            // Fallback chain in the API (VibeResource):
            //   card_image_url        -> thumbnail_url
            //   player_background_url -> thumbnail_url
            //   artwork_url           -> thumbnail_url
            $table->string('card_image_url')->nullable()->after('thumbnail_url');
            $table->string('player_background_url')->nullable()->after('card_image_url');
            $table->string('artwork_url')->nullable()->after('player_background_url');
        });
    }

    public function down(): void
    {
        Schema::table('vibes', function (Blueprint $table) {
            $table->dropColumn(['card_image_url', 'player_background_url', 'artwork_url']);
        });
    }
};
