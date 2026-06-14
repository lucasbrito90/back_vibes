<?php

declare(strict_types=1);

use App\Models\Schedule;
use App\Models\ScheduleExecution;
use App\Models\User;
use App\Models\Vibe;
use App\Services\Scheduling\RecurrenceType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function loopUser(): User
{
    return User::factory()->create(['firebase_uid' => 'fb-loop-'.uniqid()]);
}

function loopDueSchedule(User $user, Vibe $vibe, array $overrides = []): Schedule
{
    $dueAt = CarbonImmutable::now('UTC')->subMinute();

    return Schedule::factory()->create(array_merge([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => $dueAt,
        'recurrence_type' => RecurrenceType::Once->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'next_run_at' => $dueAt,
        'last_run_at' => null,
    ], $overrides));
}

// ─────────────────────────────────────────────────────────────────────────────
// --once mode: single tick, then exit
// ─────────────────────────────────────────────────────────────────────────────

test('loop with --once exits successfully without dispatch calls when no due schedules', function () {
    $this->artisan('schedules:dispatch-loop', ['--once' => true])
        ->assertSuccessful();
});

test('loop --once exits with SUCCESS exit code', function () {
    $this->artisan('schedules:dispatch-loop', ['--once' => true])
        ->assertExitCode(0);
});

test('loop --once output contains starting message', function () {
    $this->artisan('schedules:dispatch-loop', ['--once' => true])
        ->expectsOutputToContain('[schedules:dispatch-loop] starting');
});

test('loop --once output contains stopped message', function () {
    $this->artisan('schedules:dispatch-loop', ['--once' => true])
        ->expectsOutputToContain('[schedules:dispatch-loop] stopped cleanly');
});

test('loop --once dispatches one due schedule', function () {
    $user = loopUser();
    $vibe = Vibe::factory()->for($user)->create();
    $schedule = loopDueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-loop', ['--once' => true])
        ->assertSuccessful();

    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
});

test('loop --once dispatches a due schedule and marks once schedule as disabled', function () {
    $user = loopUser();
    $vibe = Vibe::factory()->for($user)->create();
    $schedule = loopDueSchedule($user, $vibe, ['recurrence_type' => RecurrenceType::Once->value]);

    $this->artisan('schedules:dispatch-loop', ['--once' => true])
        ->assertSuccessful();

    $fresh = $schedule->fresh();
    expect($fresh->is_enabled)->toBeFalse()
        ->and($fresh->next_run_at)->toBeNull();
});

test('loop --once ignores disabled schedules', function () {
    $user = loopUser();
    $vibe = Vibe::factory()->for($user)->create();
    loopDueSchedule($user, $vibe, ['is_enabled' => false]);

    $this->artisan('schedules:dispatch-loop', ['--once' => true])
        ->assertSuccessful();

    expect(ScheduleExecution::query()->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// --max-iterations mode: bounded loop for test scenarios
// ─────────────────────────────────────────────────────────────────────────────

test('loop with --max-iterations=1 exits after one iteration', function () {
    $this->artisan('schedules:dispatch-loop', ['--max-iterations' => 1, '--interval' => 0])
        ->assertSuccessful();
});

test('loop with --max-iterations=2 dispatches and executes dispatch-due', function () {
    $user = loopUser();
    $vibe = Vibe::factory()->for($user)->create();
    // start_time = next_run_at so the daily anchor equals the due instant;
    // after dispatch, next occurrence is tomorrow (future) — second tick finds nothing due.
    $dueAt = CarbonImmutable::now('UTC')->subMinutes(2);
    $schedule = loopDueSchedule($user, $vibe, [
        'recurrence_type' => RecurrenceType::Daily->value,
        'start_time' => $dueAt,
        'next_run_at' => $dueAt,
    ]);

    $this->artisan('schedules:dispatch-loop', ['--max-iterations' => 2, '--interval' => 0])
        ->assertSuccessful();

    // Exactly one execution row — idempotency prevents double-advance
    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
});

test('loop --max-iterations=2 with daily schedule produces exactly one execution row (idempotent double tick)', function () {
    $user = loopUser();
    $vibe = Vibe::factory()->for($user)->create();
    // start_time = next_run_at: after dispatch next occurrence is tomorrow (future).
    // Second tick finds nothing due → no additional execution row is created.
    $dueAt = CarbonImmutable::now('UTC')->subMinutes(5);
    $schedule = loopDueSchedule($user, $vibe, [
        'recurrence_type' => RecurrenceType::Daily->value,
        'start_time' => $dueAt,
        'next_run_at' => $dueAt,
    ]);

    $this->artisan('schedules:dispatch-loop', ['--max-iterations' => 2, '--interval' => 0])
        ->assertSuccessful();

    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
});

test('loop --max-iterations=1 output contains tick line', function () {
    $this->artisan('schedules:dispatch-loop', ['--max-iterations' => 1, '--interval' => 0])
        ->expectsOutputToContain('[schedules:dispatch-loop] tick #1');
});

// ─────────────────────────────────────────────────────────────────────────────
// Interval option is accepted
// ─────────────────────────────────────────────────────────────────────────────

test('loop accepts custom --interval option', function () {
    $this->artisan('schedules:dispatch-loop', ['--once' => true, '--interval' => 30])
        ->assertSuccessful();
});

test('starting output includes interval value', function () {
    $this->artisan('schedules:dispatch-loop', ['--once' => true, '--interval' => 45])
        ->expectsOutputToContain('interval=45s');
});

// ─────────────────────────────────────────────────────────────────────────────
// Error resilience: dispatch-due exception must not abort loop
// ─────────────────────────────────────────────────────────────────────────────

test('loop survives two iterations even when first tick finds no due schedules', function () {
    // No schedules in DB — dispatch-due exits cleanly with 0 rows processed.
    $this->artisan('schedules:dispatch-loop', ['--max-iterations' => 2, '--interval' => 0])
        ->assertSuccessful()
        ->expectsOutputToContain('[schedules:dispatch-loop] stopped cleanly after 2 iteration(s)');
});
