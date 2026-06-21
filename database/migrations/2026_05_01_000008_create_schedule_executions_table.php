<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE IF EXISTS "schedule_executions" CASCADE');
        } else {
            Schema::dropIfExists('schedule_executions');
        }

        Schema::create('schedule_executions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('schedule_id');

            $table->dateTime('executed_at');
            $table->string('status')->comment('success, failed');
            $table->text('log')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('schedule_id', 'fk_sch_exec_schedule')
                ->references('id')->on('schedules')->cascadeOnDelete();

            $table->index('schedule_id', 'idx_sch_exec_schedule');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE IF EXISTS "schedule_executions" CASCADE');
        } else {
            Schema::dropIfExists('schedule_executions');
        }
    }
};
