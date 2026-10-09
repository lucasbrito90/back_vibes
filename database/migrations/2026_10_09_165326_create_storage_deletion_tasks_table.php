<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable cleanup ledger: rows are written in the same transaction that deletes the owning
     * record, so a crash between the database and Spaces never loses track of what to remove.
     */
    public function up(): void
    {
        Schema::create('storage_deletion_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 8);
            $table->string('target', 1024);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'target'], 'uq_storage_deletion_tasks_kind_target');
            $table->index('status', 'idx_storage_deletion_tasks_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_deletion_tasks');
    }
};
