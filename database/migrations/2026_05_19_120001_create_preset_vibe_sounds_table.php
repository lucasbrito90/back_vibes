<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preset_vibe_sounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preset_vibe_id')->constrained('preset_vibes')->cascadeOnDelete();
            $table->foreignId('sound_id')->constrained('sounds')->cascadeOnDelete();

            $table->unsignedTinyInteger('volume')->default(100)->comment('0–100');
            $table->boolean('loop')->default(true);
            $table->string('play_mode')->default('loop')->comment('loop | once | interval');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('repeat_interval_seconds')->nullable()->comment('interval mode');
            $table->unsignedInteger('start_offset_seconds')->nullable()->comment('delay before play');
            $table->unsignedInteger('play_duration_seconds')->nullable()->comment('optional cap');
            $table->timestamps();

            $table->unique(['preset_vibe_id', 'sound_id'], 'uq_preset_vibe_sounds_preset_sound');
            $table->index('preset_vibe_id', 'idx_preset_vibe_sounds_preset');
            $table->index('sound_id', 'idx_preset_vibe_sounds_sound');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preset_vibe_sounds');
    }
};
