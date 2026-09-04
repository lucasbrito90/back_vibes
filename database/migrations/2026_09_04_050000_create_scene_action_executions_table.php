<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_action_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('scene_execution_id');
            $table->unsignedBigInteger('scene_id');
            $table->unsignedBigInteger('scene_action_id')->nullable();
            $table->unsignedBigInteger('device_id');
            $table->string('provider', 32);
            $table->unsignedBigInteger('provider_connection_id');
            $table->string('action_type', 32);
            $table->string('outcome', 16);
            $table->string('failure_category', 32)->nullable();
            $table->smallInteger('http_status_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamp('executed_at');
            $table->timestamp('created_at');

            $table->foreign('scene_action_id', 'fk_sae_scene_action')
                ->references('id')->on('scene_actions')->nullOnDelete();

            $table->index('scene_execution_id', 'idx_sae_scene_execution');
            $table->index(['scene_id', 'created_at'], 'idx_sae_scene_created');
            $table->index('created_at', 'idx_sae_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_action_executions');
    }
};
