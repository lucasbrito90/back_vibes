<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS "schedules" CASCADE');

        Schema::create('schedules', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('vibe_id');

            $table->string('name');
            $table->dateTime('start_time');
            $table->string('recurrence_type')->default('none')->comment('none, daily, weekly, custom');
            $table->json('recurrence_config')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id', 'fk_schedules_user')
                  ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('vibe_id', 'fk_schedules_vibe')
                  ->references('id')->on('vibes')->cascadeOnDelete();

            $table->index('user_id', 'idx_schedules_user');
            $table->index('vibe_id', 'idx_schedules_vibe');
            $table->index(['user_id', 'is_enabled'], 'idx_schedules_user_enabled');
            $table->index(['user_id', 'start_time'],  'idx_schedules_user_start');
        });
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS "schedules" CASCADE');
    }
};
