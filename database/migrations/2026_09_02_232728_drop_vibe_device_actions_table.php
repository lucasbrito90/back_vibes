<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.3.0 — drops vibe_device_actions.
 *
 * Vibe Smart Home dispatch now resolves its actions from the Scene linked via
 * vibes.scene_id (scene_actions), so the per-vibe action table has no remaining
 * reader or writer. See the Scene migration series (v1.3.0 T11–T13).
 *
 * down() recreates the table structure so the migration is reversible, but the
 * rows themselves are not recoverable — this is a destructive forward-only
 * change in terms of data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE IF EXISTS "vibe_device_actions" CASCADE');
        } else {
            Schema::dropIfExists('vibe_device_actions');
        }
    }

    public function down(): void
    {
        Schema::create('vibe_device_actions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('vibe_id');
            $table->unsignedBigInteger('device_id');

            $table->string('action_type');
            $table->json('parameters')->nullable();
            $table->unsignedSmallInteger('delay_seconds')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('vibe_id', 'fk_vda_vibe')
                ->references('id')->on('vibes')->cascadeOnDelete();
            $table->foreign('device_id', 'fk_vda_device')
                ->references('id')->on('devices')->cascadeOnDelete();

            $table->index('vibe_id', 'idx_vda_vibe');
            $table->index('device_id', 'idx_vda_device');
            $table->index(['vibe_id', 'sort_order'], 'idx_vda_vibe_sort');
        });
    }
};
