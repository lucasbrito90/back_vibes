<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS "vibe_device_actions" CASCADE');

        Schema::create('vibe_device_actions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('vibe_id');
            $table->unsignedBigInteger('device_id');

            $table->string('action_type');
            $table->json('parameters')->nullable();
            $table->unsignedSmallInteger('delay_seconds')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('vibe_id',   'fk_vda_vibe')
                  ->references('id')->on('vibes')->cascadeOnDelete();
            $table->foreign('device_id', 'fk_vda_device')
                  ->references('id')->on('devices')->cascadeOnDelete();

            $table->index('vibe_id',   'idx_vda_vibe');
            $table->index('device_id', 'idx_vda_device');
        });
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS "vibe_device_actions" CASCADE');
    }
};
