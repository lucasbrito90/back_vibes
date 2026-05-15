<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hard-reset any partial state left by previous failed runs.
        //
        // DigitalOcean managed PostgreSQL routes connections through PgBouncer
        // (port 25060) in transaction mode, which can break transactional DDL
        // and leave orphaned constraints in the system catalog even after an
        // apparent rollback. Using CASCADE ensures the table and every object
        // that depends on it (constraints, indexes, sequences) is fully removed
        // before we try to create a clean copy.
        DB::statement('DROP TABLE IF EXISTS "vibe_sounds" CASCADE');

        // Explicit constraint names prevent any auto-naming collision.
        // On PostgreSQL the generated name must be unique across the whole
        // schema; explicit short names are safe and predictable.
        Schema::create('vibe_sounds', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('vibe_id');
            $table->unsignedBigInteger('sound_id');

            $table->unsignedTinyInteger('volume')->default(80)->comment('0–100');
            $table->boolean('loop')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            // Explicit constraint names — safe regardless of PostgreSQL naming rules.
            $table->foreign('vibe_id',  'fk_vibe_sounds_vibe')
                  ->references('id')->on('vibes')->cascadeOnDelete();
            $table->foreign('sound_id', 'fk_vibe_sounds_sound')
                  ->references('id')->on('sounds')->cascadeOnDelete();

            $table->index('vibe_id',  'idx_vibe_sounds_vibe');
            $table->index('sound_id', 'idx_vibe_sounds_sound');

            $table->unique(['vibe_id', 'sound_id'], 'uq_vibe_sounds_vibe_sound');
        });
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS "vibe_sounds" CASCADE');
    }
};
