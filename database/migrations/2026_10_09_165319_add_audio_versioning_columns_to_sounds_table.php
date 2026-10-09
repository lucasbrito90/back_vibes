<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `audio_version` is the last *published* version (null until a version has been verified).
     * `audio_last_reserved_version` is the monotonic reservation counter; failed attempts leave gaps.
     * `audio_status`: legacy (pre-versioning URL, not yet verified by backfill) | pending (nothing published yet) | ready.
     * `audio_optimized`: the published file came out of the FFmpeg pipeline (never set by the backfill).
     */
    public function up(): void
    {
        Schema::table('sounds', function (Blueprint $table): void {
            $table->unsignedInteger('audio_version')->nullable()->after('file_url');
            $table->unsignedInteger('audio_last_reserved_version')->default(0)->after('audio_version');
            $table->string('audio_status', 16)->default('legacy')->after('audio_last_reserved_version');
            // True only when the current file was produced by the distribution pipeline. Backfilled legacy
            // objects (e.g. original WAVs) stay false until they are reprocessed.
            $table->boolean('audio_optimized')->default(false)->after('audio_status');
        });
    }

    public function down(): void
    {
        Schema::table('sounds', function (Blueprint $table): void {
            $table->dropColumn(['audio_version', 'audio_last_reserved_version', 'audio_status', 'audio_optimized']);
        });
    }
};
