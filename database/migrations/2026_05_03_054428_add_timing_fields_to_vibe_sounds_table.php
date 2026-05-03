<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vibe_sounds', function (Blueprint $table) {
            $table->unsignedInteger('start_offset_seconds')->nullable()->after('sort_order')
                ->comment('Seconds from vibe start before this sound begins');
            $table->unsignedInteger('play_duration_seconds')->nullable()->after('start_offset_seconds')
                ->comment('How long to play in seconds (null = until end / loop handles it)');
            $table->unsignedInteger('fade_in_seconds')->nullable()->after('play_duration_seconds')
                ->comment('Fade-in ramp duration in seconds');
            $table->unsignedInteger('fade_out_seconds')->nullable()->after('fade_in_seconds')
                ->comment('Fade-out ramp duration in seconds');
        });
    }

    public function down(): void
    {
        Schema::table('vibe_sounds', function (Blueprint $table) {
            $table->dropColumn([
                'start_offset_seconds',
                'play_duration_seconds',
                'fade_in_seconds',
                'fade_out_seconds',
            ]);
        });
    }
};
