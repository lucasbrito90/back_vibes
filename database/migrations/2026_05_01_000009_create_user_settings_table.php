<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedTinyInteger('default_volume')->default(80)->comment('0–100');
            $table->unsignedSmallInteger('sleep_timer_default')->default(30)->comment('Minutes');
            $table->foreignId('preferred_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
