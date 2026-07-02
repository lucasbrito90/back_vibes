<?php

declare(strict_types=1);

use App\Jobs\PushNotifications\PushNotificationJob;
use App\Jobs\SmartHome\SmartHomeActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Schedule;
use App\Models\ScheduleExecution;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use App\Services\Scheduling\RecurrenceType;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a user + vibe owned by that user (to satisfy FK constraints).
 */
function dispatchUser(): User
{
    return User::factory()->create(['firebase_uid' => 'fb-dispatch-'.uniqid()]);
}

/**
 * Build a schedule that is due now (next_run_at = 1 minute ago).
 * All required columns are set explicitly so tests are deterministic.
 */
function dueSchedule(User $user, Vibe $vibe, array $overrides = []): Schedule
{
    $nowUtc = CarbonImmutable::now('UTC');
    $nextRunAt = $nowUtc->subMinute();

    return Schedule::factory()->create(array_merge([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => $nextRunAt,
        'recurrence_type' => RecurrenceType::Once->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'next_run_at' => $nextRunAt,
        'last_run_at' => null,
    ], $overrides));
}

/**
 * Build a due schedule whose vibe has Smart Home device actions owned by the same user.
 */
function dueScheduleWithDeviceActions(User $user, int $actionCount = 1, array $overrides = []): Schedule
{
    $vibe = Vibe::factory()->for($user)->create();
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    for ($i = 0; $i < $actionCount; $i++) {
        $device = Device::factory()->create([
            'user_id' => $user->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'provider_device_id' => "light.room_{$i}",
        ]);

        VibeDeviceAction::factory()->create([
            'vibe_id' => $vibe->id,
            'device_id' => $device->id,
            'sort_order' => $i,
        ]);
    }

    return dueSchedule($user, $vibe, $overrides);
}

/**
 * Fake push jobs only and route Smart Home enqueue through a controllable dispatcher.
 *
 * @param  callable(SmartHomeActionJob, int): void  $onSmartHomeEnqueue  Receives the job and 1-based attempt index.
 */
function fakeSmartHomeDispatchWithEnqueueHandler(callable $onSmartHomeEnqueue): void
{
    /** @var Dispatcher $realDispatcher */
    $realDispatcher = app(Dispatcher::class);
    $attempt = 0;

    $mock = Mockery::mock($realDispatcher)->makePartial();
    $mock->shouldReceive('dispatch')
        ->andReturnUsing(function ($command, $handler = null) use ($onSmartHomeEnqueue, &$attempt, $realDispatcher) {
            if ($command instanceof SmartHomeActionJob) {
                $attempt++;
                $onSmartHomeEnqueue($command, $attempt);

                return null;
            }

            return $realDispatcher->dispatch($command, $handler);
        });

    app()->instance(Illuminate\Contracts\Bus\Dispatcher::class, $mock);
    app()->instance(Dispatcher::class, $mock);

    Bus::fake([PushNotificationJob::class]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Basic behaviour — once schedule
// ─────────────────────────────────────────────────────────────────────────────

test('command processes one due once schedule', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $schedule = dueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')
        ->assertSuccessful();

    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
});

test('creates schedule_execution with status dispatched', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $schedule = dueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $execution = ScheduleExecution::query()->where('schedule_id', $schedule->id)->first();
    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe('dispatched');
});

test('occurrence_key uses expected format schedule_id colon unix', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $nextRunAt = CarbonImmutable::now('UTC')->subMinutes(2);
    $schedule = dueSchedule($user, $vibe, ['next_run_at' => $nextRunAt]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $execution = ScheduleExecution::query()->where('schedule_id', $schedule->id)->first();
    $expectedKey = "{$schedule->id}:{$nextRunAt->utc()->timestamp}";
    expect($execution->occurrence_key)->toBe($expectedKey);
});

test('scheduled_for equals old next_run_at', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $nextRunAt = CarbonImmutable::now('UTC')->subMinutes(3);
    $schedule = dueSchedule($user, $vibe, ['next_run_at' => $nextRunAt]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $execution = ScheduleExecution::query()->where('schedule_id', $schedule->id)->first();
    expect($execution->scheduled_for->utc()->timestamp)->toBe($nextRunAt->utc()->timestamp);
});

test('executed_at is set to a non-null datetime', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $schedule = dueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $execution = ScheduleExecution::query()->where('schedule_id', $schedule->id)->first();
    expect($execution->executed_at)->not->toBeNull();
});

test('schedule last_run_at equals scheduled_for after dispatch', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $nextRunAt = CarbonImmutable::now('UTC')->subMinutes(5);
    $schedule = dueSchedule($user, $vibe, ['next_run_at' => $nextRunAt]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $fresh = $schedule->fresh();
    expect($fresh->last_run_at->utc()->timestamp)->toBe($nextRunAt->utc()->timestamp);
});

test('once schedule becomes disabled and next_run_at null after dispatch', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $schedule = dueSchedule($user, $vibe, ['recurrence_type' => RecurrenceType::Once->value]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $fresh = $schedule->fresh();
    expect($fresh->is_enabled)->toBeFalse()
        ->and($fresh->next_run_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Not due — command must ignore these schedules
// ─────────────────────────────────────────────────────────────────────────────

test('schedule with next_run_at in the future is ignored', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => CarbonImmutable::now('UTC')->addHour(),
        'recurrence_type' => RecurrenceType::Daily->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'next_run_at' => CarbonImmutable::now('UTC')->addHour(),
        'last_run_at' => null,
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    expect(ScheduleExecution::query()->count())->toBe(0);
});

test('disabled schedule is ignored', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => CarbonImmutable::now('UTC')->subHour(),
        'recurrence_type' => RecurrenceType::Daily->value,
        'recurrence_config' => null,
        'is_enabled' => false,
        'next_run_at' => CarbonImmutable::now('UTC')->subHour(),
        'last_run_at' => null,
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    expect(ScheduleExecution::query()->count())->toBe(0);
});

test('schedule with next_run_at null is ignored', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => CarbonImmutable::now('UTC')->subHour(),
        'recurrence_type' => RecurrenceType::Once->value,
        'recurrence_config' => null,
        'is_enabled' => false,
        'next_run_at' => null,
        'last_run_at' => null,
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    expect(ScheduleExecution::query()->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Recurring — next_run_at advances correctly
// ─────────────────────────────────────────────────────────────────────────────

test('daily schedule advances next_run_at by one day', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();

    // Use a fixed anchor in UTC: yesterday at 10:00 UTC
    $anchor = CarbonImmutable::now('UTC')->subDay()->setTime(10, 0, 0);
    $schedule = dueSchedule($user, $vibe, [
        'timezone' => 'UTC',
        'start_time' => $anchor,
        'recurrence_type' => RecurrenceType::Daily->value,
        'recurrence_config' => null,
        'next_run_at' => $anchor,
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $fresh = $schedule->fresh();
    // next_run_at should be 1 day after the old next_run_at
    expect($fresh->next_run_at)->not->toBeNull()
        ->and($fresh->next_run_at->utc()->timestamp)
        ->toBeGreaterThan($anchor->timestamp);

    $diff = $fresh->next_run_at->utc()->timestamp - $anchor->timestamp;
    expect($diff)->toBe(86400); // exactly 24 hours
});

test('weekdays schedule advances to next weekday', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();

    // Find a Monday as the due time (ISO dow = 1)
    $monday = CarbonImmutable::now('UTC');
    while ($monday->dayOfWeekIso !== 1) {
        $monday = $monday->subDay();
    }
    $monday = $monday->subWeek()->setTime(9, 0, 0);

    $schedule = dueSchedule($user, $vibe, [
        'timezone' => 'UTC',
        'start_time' => $monday,
        'recurrence_type' => RecurrenceType::Weekdays->value,
        'recurrence_config' => null,
        'next_run_at' => $monday,
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $fresh = $schedule->fresh();
    expect($fresh->next_run_at)->not->toBeNull()
        ->and($fresh->is_enabled)->toBeTrue();

    // The next occurrence must be a weekday (Mon=1 … Fri=5)
    expect($fresh->next_run_at->dayOfWeekIso)->toBeLessThanOrEqual(5);
});

test('weekly schedule advances to next configured weekday', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();

    // Anchor on a Wednesday (ISO 3) two weeks ago
    $wednesday = CarbonImmutable::now('UTC');
    while ($wednesday->dayOfWeekIso !== 3) {
        $wednesday = $wednesday->subDay();
    }
    $wednesday = $wednesday->subWeek()->setTime(9, 0, 0);

    $schedule = dueSchedule($user, $vibe, [
        'timezone' => 'UTC',
        'start_time' => $wednesday,
        'recurrence_type' => RecurrenceType::Weekly->value,
        'recurrence_config' => ['days_of_week' => [3]], // Wednesdays only
        'next_run_at' => $wednesday,
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $fresh = $schedule->fresh();
    expect($fresh->next_run_at)->not->toBeNull()
        ->and($fresh->next_run_at->dayOfWeekIso)->toBe(3); // still a Wednesday
});

// ─────────────────────────────────────────────────────────────────────────────
// Batch
// ─────────────────────────────────────────────────────────────────────────────

test('batch=1 processes only one due schedule', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();

    dueSchedule($user, $vibe);
    dueSchedule($user, $vibe, ['next_run_at' => CarbonImmutable::now('UTC')->subMinutes(2)]);

    $this->artisan('schedules:dispatch-due', ['--batch' => 1])->assertSuccessful();

    expect(ScheduleExecution::query()->count())->toBe(1);
});

test('schedules are processed in next_run_at ascending order', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();

    $older = CarbonImmutable::now('UTC')->subMinutes(10);
    $newer = CarbonImmutable::now('UTC')->subMinutes(2);

    $scheduleOlder = dueSchedule($user, $vibe, ['next_run_at' => $older]);
    $scheduleNewer = dueSchedule($user, $vibe, ['next_run_at' => $newer]);

    $this->artisan('schedules:dispatch-due', ['--batch' => 1])->assertSuccessful();

    // Only the schedule with the older (earlier) next_run_at should have been processed
    expect(ScheduleExecution::query()->where('schedule_id', $scheduleOlder->id)->count())->toBe(1);
    expect(ScheduleExecution::query()->where('schedule_id', $scheduleNewer->id)->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Dry run
// ─────────────────────────────────────────────────────────────────────────────

test('dry-run creates no executions', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    dueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due', ['--dry-run' => true])->assertSuccessful();

    expect(ScheduleExecution::query()->count())->toBe(0);
});

test('dry-run does not update last_run_at', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $schedule = dueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due', ['--dry-run' => true])->assertSuccessful();

    $fresh = $schedule->fresh();
    expect($fresh->last_run_at)->toBeNull();
});

test('dry-run does not update next_run_at', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $nextRunAt = CarbonImmutable::now('UTC')->subMinutes(5);
    $schedule = dueSchedule($user, $vibe, ['next_run_at' => $nextRunAt]);

    $this->artisan('schedules:dispatch-due', ['--dry-run' => true])->assertSuccessful();

    $fresh = $schedule->fresh();
    expect($fresh->next_run_at->utc()->timestamp)->toBe($nextRunAt->utc()->timestamp);
});

test('dry-run does not change is_enabled', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $schedule = dueSchedule($user, $vibe, ['recurrence_type' => RecurrenceType::Once->value]);

    $this->artisan('schedules:dispatch-due', ['--dry-run' => true])->assertSuccessful();

    $fresh = $schedule->fresh();
    expect($fresh->is_enabled)->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// Idempotency
// ─────────────────────────────────────────────────────────────────────────────

test('running command twice does not create duplicate execution', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();

    // Use a shared CarbonImmutable base so start_time and next_run_at are consistent.
    $nowUtc = CarbonImmutable::now('UTC');
    $dueAt = $nowUtc->subMinutes(5);

    $schedule = Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => $dueAt,
        'recurrence_type' => RecurrenceType::Daily->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'next_run_at' => $dueAt,
        'last_run_at' => null,
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    // Simulate a double-tick: reset next_run_at back to the same due instant.
    $schedule->forceFill(['next_run_at' => $dueAt, 'last_run_at' => null])->save();

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
});

test('pre-existing execution with same occurrence_key skips without double-advancing', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    $nextRunAt = CarbonImmutable::now('UTC')->subMinutes(5);
    $schedule = dueSchedule($user, $vibe, [
        'recurrence_type' => RecurrenceType::Daily->value,
        'next_run_at' => $nextRunAt,
    ]);

    // Pre-insert an execution with the same occurrence_key
    $occurrenceKey = "{$schedule->id}:{$nextRunAt->utc()->timestamp}";
    ScheduleExecution::query()->create([
        'schedule_id' => $schedule->id,
        'occurrence_key' => $occurrenceKey,
        'scheduled_for' => $nextRunAt,
        'executed_at' => CarbonImmutable::now('UTC'),
        'status' => 'dispatched',
        'log' => null,
    ]);

    $originalNextRunAt = $schedule->next_run_at;

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    // Must still be exactly one execution row
    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);

    // next_run_at must not have been advanced by the duplicate tick
    $fresh = $schedule->fresh();
    expect($fresh->next_run_at->utc()->timestamp)->toBe($originalNextRunAt->utc()->timestamp);
});

// ─────────────────────────────────────────────────────────────────────────────
// Failure isolation
// ─────────────────────────────────────────────────────────────────────────────

test('unsupported monthly schedule fails gracefully and command continues processing another valid schedule', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();

    // The 'monthly' value bypasses API validation by being inserted directly.
    $badSchedule = Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => CarbonImmutable::now('UTC')->subMinutes(5),
        'recurrence_type' => RecurrenceType::Monthly->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'next_run_at' => CarbonImmutable::now('UTC')->subMinutes(5),
        'last_run_at' => null,
    ]);

    $goodSchedule = dueSchedule($user, $vibe, [
        'recurrence_type' => RecurrenceType::Daily->value,
        'next_run_at' => CarbonImmutable::now('UTC')->subMinutes(3),
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    // The valid schedule must have been dispatched
    expect(ScheduleExecution::query()->where('schedule_id', $goodSchedule->id)->count())->toBe(1);

    // The bad schedule must not have a successful execution row
    $badExecution = ScheduleExecution::query()
        ->where('schedule_id', $badSchedule->id)
        ->where('status', 'dispatched')
        ->first();
    expect($badExecution)->toBeNull();
});

test('invalid recurrence config triggers failure without aborting batch', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();

    // weekly without days_of_week — will throw InvalidRecurrenceConfigurationException on advance
    $badSchedule = Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => CarbonImmutable::now('UTC')->subMinutes(4),
        'recurrence_type' => RecurrenceType::Weekly->value,
        'recurrence_config' => null, // intentionally invalid
        'is_enabled' => true,
        'next_run_at' => CarbonImmutable::now('UTC')->subMinutes(4),
        'last_run_at' => null,
    ]);

    $goodSchedule = dueSchedule($user, $vibe, [
        'recurrence_type' => RecurrenceType::Daily->value,
        'next_run_at' => CarbonImmutable::now('UTC')->subMinutes(2),
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    // The good schedule must still be dispatched
    expect(ScheduleExecution::query()->where('schedule_id', $goodSchedule->id)->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Push notification integration (Phase 8)
// ─────────────────────────────────────────────────────────────────────────────

test('a failed schedule notifies the owner via PushNotificationEvents', function () {
    Bus::fake();

    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();

    // weekly without days_of_week — throws on recurrence advance → failure path
    $badSchedule = Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => CarbonImmutable::now('UTC')->subMinutes(4),
        'recurrence_type' => RecurrenceType::Weekly->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'next_run_at' => CarbonImmutable::now('UTC')->subMinutes(4),
        'last_run_at' => null,
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    // PushNotificationEvents → PushNotificationService → PushNotificationJob
    Bus::assertDispatched(PushNotificationJob::class, function (PushNotificationJob $job) use ($user, $badSchedule) {
        return $job->userId === $user->id
            && $job->payload->data['type'] === 'schedule_execution_failed'
            && $job->payload->data['schedule_id'] === (string) $badSchedule->id;
    });
});

test('a successful schedule does not notify via PushNotificationEvents', function () {
    Bus::fake();

    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    dueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    Bus::assertNotDispatched(PushNotificationJob::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Output
// ─────────────────────────────────────────────────────────────────────────────

test('command output includes dispatched count', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    dueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')
        ->expectsOutputToContain('dispatched')
        ->assertSuccessful();
});

test('command output includes dry-run indication when --dry-run is used', function () {
    $user = dispatchUser();
    $vibe = Vibe::factory()->for($user)->create();
    dueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
});

// ─────────────────────────────────────────────────────────────────────────────
// Smart Home integration (Phase 4B)
// ─────────────────────────────────────────────────────────────────────────────

test('dispatched schedule enqueues Smart Home action jobs', function () {
    Bus::fake();

    $user = dispatchUser();
    $schedule = dueScheduleWithDeviceActions($user, actionCount: 2);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
    Bus::assertDispatchedTimes(SmartHomeActionJob::class, 2);
});

test('skipped duplicate schedule does not enqueue Smart Home jobs', function () {
    Bus::fake();

    $user = dispatchUser();
    $nextRunAt = CarbonImmutable::now('UTC')->subMinutes(5);
    $schedule = dueScheduleWithDeviceActions($user, overrides: [
        'recurrence_type' => RecurrenceType::Daily->value,
        'next_run_at' => $nextRunAt,
    ]);

    $occurrenceKey = "{$schedule->id}:{$nextRunAt->utc()->timestamp}";
    ScheduleExecution::query()->create([
        'schedule_id' => $schedule->id,
        'occurrence_key' => $occurrenceKey,
        'scheduled_for' => $nextRunAt,
        'executed_at' => CarbonImmutable::now('UTC'),
        'status' => 'dispatched',
        'log' => null,
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    Bus::assertNotDispatched(SmartHomeActionJob::class);
});

test('dry-run does not enqueue Smart Home jobs', function () {
    Bus::fake();

    $user = dispatchUser();
    dueScheduleWithDeviceActions($user);

    $this->artisan('schedules:dispatch-due', ['--dry-run' => true])->assertSuccessful();

    Bus::assertNotDispatched(SmartHomeActionJob::class);
});

test('validator failure skips Smart Home dispatch but keeps schedule execution', function () {
    Bus::fake();

    $owner = dispatchUser();
    $other = dispatchUser();
    $foreignVibe = Vibe::factory()->for($other)->create();
    $schedule = dueSchedule($owner, $foreignVibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
    Bus::assertNotDispatched(SmartHomeActionJob::class);
});

test('Smart Home dispatch exception does not fail scheduler or emit failure push', function () {
    fakeSmartHomeDispatchWithEnqueueHandler(function (): void {
        throw new RuntimeException('queue unavailable');
    });

    $user = dispatchUser();
    $schedule = dueScheduleWithDeviceActions($user);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
    Bus::assertNotDispatched(PushNotificationJob::class);
});

test('Smart Home dispatch failure on first schedule does not block second schedule', function () {
    $enqueued = [];

    fakeSmartHomeDispatchWithEnqueueHandler(function (SmartHomeActionJob $job, int $attempt) use (&$enqueued): void {
        if ($attempt === 1) {
            throw new RuntimeException('queue unavailable');
        }

        $enqueued[] = $job;
    });

    $user = dispatchUser();
    $vibeOne = Vibe::factory()->for($user)->create();
    $vibeTwo = Vibe::factory()->for($user)->create();
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    foreach ([$vibeOne, $vibeTwo] as $index => $vibe) {
        $device = Device::factory()->create([
            'user_id' => $user->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'provider_device_id' => "light.block_{$index}",
        ]);

        VibeDeviceAction::factory()->create([
            'vibe_id' => $vibe->id,
            'device_id' => $device->id,
            'sort_order' => 0,
        ]);
    }

    $scheduleOne = dueSchedule($user, $vibeOne, [
        'next_run_at' => CarbonImmutable::now('UTC')->subMinutes(10),
    ]);
    $scheduleTwo = dueSchedule($user, $vibeTwo, [
        'next_run_at' => CarbonImmutable::now('UTC')->subMinutes(5),
    ]);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    expect(ScheduleExecution::query()->where('schedule_id', $scheduleOne->id)->count())->toBe(1)
        ->and(ScheduleExecution::query()->where('schedule_id', $scheduleTwo->id)->count())->toBe(1)
        ->and($enqueued)->toHaveCount(1);
});
