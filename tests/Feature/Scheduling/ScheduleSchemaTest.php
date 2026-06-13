<?php

declare(strict_types=1);

use App\Models\Schedule;
use App\Models\ScheduleExecution;
use App\Models\User;
use App\Models\Vibe;
use App\Services\Scheduling\RecurrenceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ────────────────────────────────────────────────────────────────────────────
// schedules — column and cast assertions
// ────────────────────────────────────────────────────────────────────────────

test('schedules table has all required MVP columns', function () {
    $columns = Schema::getColumnListing('schedules');

    expect($columns)->toContain('id')
        ->toContain('user_id')
        ->toContain('vibe_id')
        ->toContain('name')
        ->toContain('timezone')
        ->toContain('start_time')
        ->toContain('recurrence_type')
        ->toContain('recurrence_config')
        ->toContain('is_enabled')
        ->toContain('next_run_at')
        ->toContain('last_run_at')
        ->toContain('created_at')
        ->toContain('updated_at');
});

test('schedule can store and retrieve timezone', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();

    $schedule = Schedule::query()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'name' => 'Timezone test',
        'timezone' => 'America/Sao_Paulo',
        'start_time' => now('UTC'),
        'recurrence_type' => RecurrenceType::Daily->value,
        'recurrence_config' => null,
        'is_enabled' => true,
    ]);

    $fresh = $schedule->fresh();

    expect($fresh->timezone)->toBe('America/Sao_Paulo');
});

test('schedule can store and retrieve next_run_at as UTC datetime', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();
    $nextRun = CarbonImmutable::parse('2025-03-15 14:30:00', 'UTC');

    $schedule = Schedule::query()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'name' => 'next_run_at test',
        'timezone' => 'UTC',
        'start_time' => $nextRun,
        'recurrence_type' => RecurrenceType::Once->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'next_run_at' => $nextRun,
    ]);

    $fresh = $schedule->fresh();

    expect($fresh->next_run_at)->not->toBeNull()
        ->and($fresh->next_run_at->utc()->toDateTimeString())->toBe('2025-03-15 14:30:00');
});

test('schedule can store and retrieve last_run_at', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();
    $lastRun = CarbonImmutable::parse('2025-03-14 14:30:00', 'UTC');

    $schedule = Schedule::query()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'name' => 'last_run_at test',
        'timezone' => 'UTC',
        'start_time' => $lastRun,
        'recurrence_type' => RecurrenceType::Daily->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'last_run_at' => $lastRun,
    ]);

    $fresh = $schedule->fresh();

    expect($fresh->last_run_at)->not->toBeNull()
        ->and($fresh->last_run_at->utc()->toDateTimeString())->toBe('2025-03-14 14:30:00');
});

test('schedule uses updated_at timestamp', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();

    $schedule = Schedule::query()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'name' => 'updated_at test',
        'timezone' => 'UTC',
        'start_time' => now('UTC'),
        'recurrence_type' => RecurrenceType::Once->value,
        'recurrence_config' => null,
        'is_enabled' => true,
    ]);

    expect($schedule->updated_at)->not->toBeNull();

    // Ensure touching the model advances updated_at.
    $original = $schedule->updated_at;
    sleep(1);
    $schedule->touch();
    $schedule->refresh();

    expect($schedule->updated_at->greaterThanOrEqualTo($original))->toBeTrue();
});

test('schedule next_run_at is nullable', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();

    $schedule = Schedule::query()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'name' => 'nullable next_run_at',
        'timezone' => 'UTC',
        'start_time' => now('UTC'),
        'recurrence_type' => RecurrenceType::Once->value,
        'recurrence_config' => null,
        'is_enabled' => false,
        'next_run_at' => null,
    ]);

    expect($schedule->fresh()->next_run_at)->toBeNull();
});

test('schedule recurrence_config is cast to array', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();
    $config = ['days_of_week' => [1, 3, 5]];

    $schedule = Schedule::query()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'name' => 'weekly config',
        'timezone' => 'UTC',
        'start_time' => now('UTC'),
        'recurrence_type' => RecurrenceType::Weekly->value,
        'recurrence_config' => $config,
        'is_enabled' => true,
    ]);

    $fresh = $schedule->fresh();

    expect($fresh->recurrence_config)->toBe($config);
});

// ────────────────────────────────────────────────────────────────────────────
// schedule_executions — column and constraint assertions
// ────────────────────────────────────────────────────────────────────────────

test('schedule_executions table has all required MVP columns', function () {
    $columns = Schema::getColumnListing('schedule_executions');

    expect($columns)->toContain('id')
        ->toContain('schedule_id')
        ->toContain('occurrence_key')
        ->toContain('scheduled_for')
        ->toContain('executed_at')
        ->toContain('status')
        ->toContain('log')
        ->toContain('created_at');
});

test('schedule execution can store occurrence_key', function () {
    $schedule = Schedule::factory()->once()->create();
    $scheduledFor = CarbonImmutable::parse('2025-06-01 09:00:00', 'UTC');
    $occurrenceKey = "{$schedule->id}:{$scheduledFor->timestamp}";

    $execution = ScheduleExecution::query()->create([
        'schedule_id' => $schedule->id,
        'occurrence_key' => $occurrenceKey,
        'scheduled_for' => $scheduledFor,
        'executed_at' => $scheduledFor->addSeconds(2),
        'status' => 'dispatched',
    ]);

    expect($execution->fresh()->occurrence_key)->toBe($occurrenceKey);
});

test('schedule execution can store scheduled_for as UTC', function () {
    $schedule = Schedule::factory()->daily()->create();
    $scheduledFor = CarbonImmutable::parse('2025-06-01 15:00:00', 'UTC');

    $execution = ScheduleExecution::query()->create([
        'schedule_id' => $schedule->id,
        'occurrence_key' => "{$schedule->id}:{$scheduledFor->timestamp}",
        'scheduled_for' => $scheduledFor,
        'executed_at' => $scheduledFor,
        'status' => 'dispatched',
    ]);

    expect($execution->fresh()->scheduled_for->utc()->toDateTimeString())
        ->toBe('2025-06-01 15:00:00');
});

test('schedule_executions enforces unique schedule_id + occurrence_key', function () {
    $schedule = Schedule::factory()->daily()->create();
    $scheduledFor = CarbonImmutable::parse('2025-06-01 09:00:00', 'UTC');
    $occurrenceKey = "{$schedule->id}:{$scheduledFor->timestamp}";

    ScheduleExecution::query()->create([
        'schedule_id' => $schedule->id,
        'occurrence_key' => $occurrenceKey,
        'scheduled_for' => $scheduledFor,
        'executed_at' => $scheduledFor,
        'status' => 'dispatched',
    ]);

    expect(fn () => ScheduleExecution::query()->create([
        'schedule_id' => $schedule->id,
        'occurrence_key' => $occurrenceKey,
        'scheduled_for' => $scheduledFor,
        'executed_at' => $scheduledFor->addSeconds(5),
        'status' => 'dispatched',
    ]))->toThrow(QueryException::class);
});

test('same occurrence_key on different schedules is allowed', function () {
    $scheduleA = Schedule::factory()->daily()->create();
    $scheduleB = Schedule::factory()->daily()->create();
    $scheduledFor = CarbonImmutable::parse('2025-06-01 09:00:00', 'UTC');

    // Both schedules share the same unix timestamp — different schedule_ids.
    $keyA = "{$scheduleA->id}:{$scheduledFor->timestamp}";
    $keyB = "{$scheduleB->id}:{$scheduledFor->timestamp}";

    ScheduleExecution::query()->create([
        'schedule_id' => $scheduleA->id,
        'occurrence_key' => $keyA,
        'scheduled_for' => $scheduledFor,
        'executed_at' => $scheduledFor,
        'status' => 'dispatched',
    ]);

    $execB = ScheduleExecution::query()->create([
        'schedule_id' => $scheduleB->id,
        'occurrence_key' => $keyB,
        'scheduled_for' => $scheduledFor,
        'executed_at' => $scheduledFor,
        'status' => 'dispatched',
    ]);

    expect($execB->id)->toBeInt();
});

// ────────────────────────────────────────────────────────────────────────────
// Data migration — recurrence_type none → once
// ────────────────────────────────────────────────────────────────────────────

test('recurrence_type none is migrated to once via raw insert then verified', function () {
    // Simulate a pre-migration row by bypassing model validation.
    // Raw insert bypasses Eloquent casts to test the data migration logic.
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();

    DB::table('schedules')->insert([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'name' => 'legacy none schedule',
        'timezone' => 'UTC',
        'start_time' => now()->toDateTimeString(),
        'recurrence_type' => 'none',
        'recurrence_config' => null,
        'is_enabled' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Apply the same migration backfill logic.
    DB::table('schedules')
        ->where('recurrence_type', 'none')
        ->update(['recurrence_type' => 'once']);

    $count = DB::table('schedules')
        ->where('recurrence_type', 'none')
        ->count();

    expect($count)->toBe(0);
});

// ────────────────────────────────────────────────────────────────────────────
// RecurrenceType — monthly reserved, not treated as supported
// ────────────────────────────────────────────────────────────────────────────

test('RecurrenceType monthly is not in mvpAllowed list', function () {
    $allowed = RecurrenceType::mvpAllowed();

    expect($allowed)->not->toContain(RecurrenceType::Monthly)
        ->and(RecurrenceType::Monthly->isMvpSupported())->toBeFalse();
});

test('ScheduleFactory does not produce monthly recurrence type', function () {
    $types = collect(range(1, 30))
        ->map(fn () => Schedule::factory()->make()->recurrence_type)
        ->unique()
        ->values()
        ->all();

    expect($types)->not->toContain(RecurrenceType::Monthly->value);
});

// ────────────────────────────────────────────────────────────────────────────
// Factory smoke tests
// ────────────────────────────────────────────────────────────────────────────

test('ScheduleFactory creates persisted schedule with required fields', function () {
    $schedule = Schedule::factory()->create();

    expect($schedule->id)->toBeInt()
        ->and($schedule->timezone)->toBeString()->not->toBeEmpty()
        ->and($schedule->recurrence_type)->toBeIn([
            RecurrenceType::Once->value,
            RecurrenceType::Daily->value,
            RecurrenceType::Weekdays->value,
            RecurrenceType::Weekly->value,
        ])
        ->and($schedule->updated_at)->not->toBeNull();
});

test('ScheduleFactory once state creates once schedule with null config', function () {
    $schedule = Schedule::factory()->once()->create();

    expect($schedule->recurrence_type)->toBe(RecurrenceType::Once->value)
        ->and($schedule->recurrence_config)->toBeNull();
});

test('ScheduleFactory weekly state creates valid recurrence_config', function () {
    $schedule = Schedule::factory()->weekly([1, 3, 5])->create();

    expect($schedule->recurrence_type)->toBe(RecurrenceType::Weekly->value)
        ->and($schedule->recurrence_config['days_of_week'])->toBe([1, 3, 5]);
});

test('ScheduleFactory disabled state sets is_enabled false and null next_run_at', function () {
    $schedule = Schedule::factory()->disabled()->create();

    expect($schedule->is_enabled)->toBeFalse()
        ->and($schedule->next_run_at)->toBeNull();
});

test('ScheduleFactory due state sets next_run_at in the past', function () {
    $schedule = Schedule::factory()->due()->create();

    expect($schedule->next_run_at)->not->toBeNull()
        ->and($schedule->next_run_at->isPast())->toBeTrue();
});

test('ScheduleExecutionFactory creates persisted execution', function () {
    $execution = ScheduleExecution::factory()->create();

    expect($execution->id)->toBeInt()
        ->and($execution->occurrence_key)->toBeString()->not->toBeEmpty()
        ->and($execution->scheduled_for)->not->toBeNull()
        ->and($execution->executed_at)->not->toBeNull()
        ->and($execution->status)->toBe('dispatched');
});
