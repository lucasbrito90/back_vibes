<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vibe_device_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vibe_id')->constrained()->cascadeOnDelete()->index();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete()->index();
            $table->string('action_type');
            $table->json('parameters')->nullable();
            $table->unsignedSmallInteger('delay_seconds')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vibe_device_actions');
    }
};
