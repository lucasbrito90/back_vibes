<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vibe_vibe_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vibe_id')->constrained('vibes')->cascadeOnDelete();
            $table->foreignId('vibe_category_id')->constrained('vibe_categories')->restrictOnDelete();

            $table->unique(['vibe_id', 'vibe_category_id'], 'uq_vibe_vibe_categories');
            $table->index('vibe_id', 'idx_vibe_vibe_categories_vibe');
            $table->index('vibe_category_id', 'idx_vibe_vibe_categories_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vibe_vibe_categories');
    }
};
