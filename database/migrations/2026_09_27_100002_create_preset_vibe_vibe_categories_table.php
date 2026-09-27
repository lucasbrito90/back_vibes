<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preset_vibe_vibe_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preset_vibe_id')->constrained('preset_vibes')->cascadeOnDelete();
            $table->foreignId('vibe_category_id')->constrained('vibe_categories')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['preset_vibe_id', 'vibe_category_id'], 'uq_preset_vibe_vibe_categories');
            $table->index('preset_vibe_id', 'idx_preset_vibe_vibe_categories_preset');
            $table->index('vibe_category_id', 'idx_preset_vibe_vibe_categories_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preset_vibe_vibe_categories');
    }
};
