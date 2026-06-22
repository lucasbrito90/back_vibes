<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('platform', 32);
            $table->string('provider', 32)->default('fcm');
            $table->string('device_id', 255)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('device_model', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('token', 'uq_push_tokens_token');
            $table->index(['user_id', 'is_active'], 'idx_push_tokens_user_active');

            // Optional future dedupe when device_id is guaranteed stable:
            // $table->unique(['user_id', 'device_id', 'provider'], 'uq_push_tokens_user_device_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
