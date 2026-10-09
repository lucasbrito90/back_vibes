<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sound_audio_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sound_id')->constrained('sounds')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('queued');
            // upload (admin file) | reprocess (existing published object re-run through the pipeline) | backfill (legacy object adopted as-is)
            $table->string('origin', 16)->default('upload');
            // Distribution profile that produced the file (null for backfilled legacy objects).
            $table->string('profile', 64)->nullable();

            $table->string('source_key', 512);
            $table->string('source_mime', 128)->nullable();
            $table->unsignedBigInteger('source_bytes')->nullable();
            $table->string('distribution_key', 512)->nullable();
            $table->unsignedBigInteger('distribution_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['sound_id', 'version'], 'uq_sound_audio_revisions_sound_version');
            $table->index(['status', 'heartbeat_at'], 'idx_sound_audio_revisions_status_heartbeat');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sound_audio_revisions');
    }
};
