<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scene_id');
            $table->unsignedBigInteger('device_id');
            $table->string('action_type');
            $table->json('parameters')->nullable();
            $table->unsignedSmallInteger('delay_seconds')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('scene_id', 'fk_sa_scene')
                ->references('id')->on('scenes')->cascadeOnDelete();
            $table->foreign('device_id', 'fk_sa_device')
                ->references('id')->on('devices')->cascadeOnDelete();

            $table->index(['scene_id', 'sort_order'], 'idx_sa_scene_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_actions');
    }
};
