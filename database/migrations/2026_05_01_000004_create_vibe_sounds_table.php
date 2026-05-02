<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vibe_sounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vibe_id')->constrained()->cascadeOnDelete()->index();
            $table->foreignId('sound_id')->constrained()->cascadeOnDelete()->index();
            $table->unsignedTinyInteger('volume')->default(80)->comment('0–100');
            $table->boolean('loop')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['vibe_id', 'sound_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vibe_sounds');
    }
};
