<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vibe_sounds', function (Blueprint $table) {
            $table->string('play_mode')->default('loop')->after('loop')
                ->comment('How the sound plays: loop | once | interval');
            $table->unsignedInteger('repeat_interval_seconds')->nullable()->after('play_mode')
                ->comment('Seconds between repetitions when play_mode = interval');
        });
    }

    public function down(): void
    {
        Schema::table('vibe_sounds', function (Blueprint $table) {
            $table->dropColumn(['play_mode', 'repeat_interval_seconds']);
        });
    }
};
