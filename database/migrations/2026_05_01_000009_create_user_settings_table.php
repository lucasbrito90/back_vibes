<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS "user_settings" CASCADE');

        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('preferred_device_id')->nullable();

            $table->unsignedTinyInteger('default_volume')->default(80)->comment('0–100');
            $table->unsignedSmallInteger('sleep_timer_default')->default(30)->comment('Minutes');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id',             'fk_user_settings_user')
                  ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('preferred_device_id', 'fk_user_settings_device')
                  ->references('id')->on('devices')->nullOnDelete();

            $table->unique('user_id', 'uq_user_settings_user');
        });
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS "user_settings" CASCADE');
    }
};
