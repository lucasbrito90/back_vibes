<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vibe_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40);
            $table->json('names');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('slug', 'uq_vibe_categories_slug');
            $table->index(['is_active', 'sort_order'], 'idx_vibe_categories_active_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vibe_categories');
    }
};
