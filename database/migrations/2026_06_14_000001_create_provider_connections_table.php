<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('provider', 32);
            $table->json('config');
            $table->text('encrypted_credentials');
            $table->string('status', 32)->default('unknown');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider'], 'uq_provider_connections_user_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connections');
    }
};
