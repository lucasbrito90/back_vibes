<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->index();
            $table->foreignId('vibe_id')->constrained()->cascadeOnDelete()->index();
            $table->string('name');
            $table->dateTime('start_time');
            $table->string('recurrence_type')->default('none')->comment('none, daily, weekly, custom');
            $table->json('recurrence_config')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
