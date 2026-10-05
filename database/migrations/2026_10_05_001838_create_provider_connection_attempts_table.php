<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connection_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_connection_id')
                ->constrained('provider_connections')
                ->cascadeOnDelete();
            // 'server' = initiated by backend /test endpoint
            // 'client' = reported by mobile via /report-health endpoint
            $table->string('source', 16);
            // 'success' | 'failure_host' | 'failure_credentials'
            $table->string('outcome', 32);
            // Brief human-readable reason — MUST NOT contain credential values.
            // Examples: "HTTP 401", "Host unreachable", "HTTP 503".
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connection_attempts');
    }
};
