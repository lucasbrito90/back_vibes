<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard against a partially-run migration leaving the table in a
        // half-created state on PostgreSQL (which does not auto-rollback DDL
        // outside an explicit transaction). Dropping first ensures a clean slate.
        Schema::create('vibe_sounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vibe_id')->constrained()->cascadeOnDelete()->index();
            $table->foreignId('sound_id')->constrained()->cascadeOnDelete()->index();
            $table->unsignedTinyInteger('volume')->default(80)->comment('0–100');
            $table->boolean('loop')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['vibe_id', 'sound_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vibe_sounds');
    }
};
