<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SmartHomeActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use App\Services\Scheduling\RecurrenceType;
use App\SmartHome\ActionType;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\SmartHome\SmartHomeDispatchTelemetry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

uses(RefreshDatabase::class);

/**
 * Phase 7B.4.2 — proves the `smart_home.dispatch` Business Span is really
 * wired into both real entry points (VibeSmartHomeDispatchController and
 * DispatchDueSchedulesCommand), not just the isolated
 * SmartHomeDispatchTelemetry unit exercised in
 * SmartHomeDispatchTelemetryTest.php.
 */
function fakeBoundaryTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->forgetInstance(SmartHomeDispatchTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, attributes: array<string, mixed>}>
 */
function boundarySpanCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->startSpanCalls,
        fn (array $call) => $call['name'] === 'smart_home.dispatch',
    ));
}

function boundaryUser(): User
{
    return User::factory()->create(['firebase_uid' => 'fb-boundary-'.uniqid()]);
}

function boundaryJwt(User $user): UnencryptedToken
{
    $dataset = new DataSet([
        'sub' => $user->firebase_uid,
        'email' => $user->email,
        'name' => $user->name,
    ], 'e30.');

    $jwt = Mockery::mock(UnencryptedToken::class);
    $jwt->shouldReceive('claims')->andReturn($dataset);

    return $jwt;
}

function boundaryAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(boundaryJwt($user)));
}

function boundaryDevice(User $user): Device
{
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
    ]);
}

function boundaryAction(Vibe $vibe, Device $device, int $sortOrder = 0): VibeDeviceAction
{
    return VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'sort_order' => $sortOrder,
    ]);
}

afterEach(function () {
    Mockery::close();
});

// ─────────────────────────────────────────────────────────────────────────────
// Manual entry point — real HTTP controller
// ─────────────────────────────────────────────────────────────────────────────

test('a manual mobile dispatch tags the span entry_point as manual', function () {
    $recorder = fakeBoundaryTelemetry();
    Bus::fake();

    $user = boundaryUser();
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = boundaryDevice($user);
    boundaryAction($vibe, $device, 0);
    boundaryAction($vibe, $device, 1);

    boundaryAuth($user);

    $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        ['Authorization' => 'Bearer tok'],
    )->assertOk();

    $spans = boundarySpanCalls($recorder);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['attributes']['ixora.dispatch.entry_point'])->toBe('manual');

    $attributes = $recorder->mergedSpanAttributes();
    expect($attributes['ixora.dispatch.dispatched_actions'])->toBe(2)
        ->and($attributes['ixora.dispatch.skipped_actions'])->toBe(0)
        ->and($recorder->spanEndCalls)->toBe(1);
});

test('the manual dispatch span never overlaps job or provider execution', function () {
    $recorder = fakeBoundaryTelemetry();
    Bus::fake();
    Http::fake();

    $user = boundaryUser();
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = boundaryDevice($user);
    boundaryAction($vibe, $device);

    boundaryAuth($user);

    $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        ['Authorization' => 'Bearer tok'],
    )->assertOk();

    // The span has already ended (by the time the HTTP response was built),
    // and — because Bus::fake() kept SmartHomeActionJob off a real
    // queue/worker — no provider HTTP call happened at all during the
    // request, proving the dispatch boundary cannot have included any
    // action/provider execution time.
    expect($recorder->spanEndCalls)->toBe(1);
    Bus::assertDispatched(SmartHomeActionJob::class);
    Http::assertNothingSent();
});

test('an unauthorized manual dispatch attempt creates no dispatch span at all', function () {
    $recorder = fakeBoundaryTelemetry();
    Bus::fake();

    $owner = boundaryUser();
    $other = boundaryUser();
    $vibe = Vibe::factory()->create(['user_id' => $owner->id]);

    boundaryAuth($other);

    $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        ['Authorization' => 'Bearer tok'],
    )->assertStatus(403);

    expect(boundarySpanCalls($recorder))->toBe([]);
    Bus::assertNothingDispatched();
});

// ─────────────────────────────────────────────────────────────────────────────
// Scheduled entry point — real DispatchDueSchedulesCommand
// ─────────────────────────────────────────────────────────────────────────────

function boundaryDueSchedule(User $user, Vibe $vibe): Schedule
{
    $nowUtc = CarbonImmutable::now('UTC');
    $nextRunAt = $nowUtc->subMinute();

    return Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => $nextRunAt,
        'recurrence_type' => RecurrenceType::Once->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'next_run_at' => $nextRunAt,
        'last_run_at' => null,
    ]);
}

test('a scheduled dispatch tags the span entry_point as scheduled', function () {
    $recorder = fakeBoundaryTelemetry();
    Bus::fake();

    $user = boundaryUser();
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = boundaryDevice($user);
    boundaryAction($vibe, $device, 0);
    boundaryAction($vibe, $device, 1);
    boundaryDueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $spans = boundarySpanCalls($recorder);

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['attributes']['ixora.dispatch.entry_point'])->toBe('scheduled');

    $attributes = $recorder->mergedSpanAttributes();
    expect($attributes['ixora.dispatch.dispatched_actions'])->toBe(2)
        ->and($attributes['ixora.dispatch.skipped_actions'])->toBe(0)
        ->and($recorder->spanEndCalls)->toBe(1);

    Bus::assertDispatchedTimes(SmartHomeActionJob::class, 2);
});

test('a schedule with no vibe device actions creates a scheduled span with zero counts', function () {
    $recorder = fakeBoundaryTelemetry();
    Bus::fake();

    $user = boundaryUser();
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    boundaryDueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $attributes = $recorder->mergedSpanAttributes();
    expect($attributes['ixora.dispatch.dispatched_actions'])->toBe(0)
        ->and($attributes['ixora.dispatch.skipped_actions'])->toBe(0);

    Bus::assertNothingDispatched();
});

test('multiple due schedules in one command run each create their own separate dispatch span — never merged or duplicated', function () {
    $recorder = fakeBoundaryTelemetry();
    Bus::fake();

    $userA = boundaryUser();
    $vibeA = Vibe::factory()->create(['user_id' => $userA->id]);
    $deviceA = boundaryDevice($userA);
    boundaryAction($vibeA, $deviceA, 0);
    boundaryDueSchedule($userA, $vibeA);

    $userB = boundaryUser();
    $vibeB = Vibe::factory()->create(['user_id' => $userB->id]);
    $deviceB = boundaryDevice($userB);
    boundaryAction($vibeB, $deviceB, 0);
    boundaryAction($vibeB, $deviceB, 1);
    boundaryDueSchedule($userB, $vibeB);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $spans = boundarySpanCalls($recorder);

    expect($spans)->toHaveCount(2)
        ->and($recorder->spanEndCalls)->toBe(2);

    foreach ($spans as $span) {
        expect($span['attributes']['ixora.dispatch.entry_point'])->toBe('scheduled');
    }
});

test('a validator failure never creates a dispatch span — the boundary owns only the dispatch() call, not validation', function () {
    $recorder = fakeBoundaryTelemetry();
    Bus::fake();

    $vibeOwner = boundaryUser();
    $scheduleOwner = boundaryUser();
    $vibe = Vibe::factory()->create(['user_id' => $vibeOwner->id]);
    $device = boundaryDevice($vibeOwner);
    boundaryAction($vibe, $device, 0);

    // ScheduleAutomationValidator::validate() fails ownership integrity
    // (schedule->user_id !== vibe->user_id) before dispatch() is ever
    // called — see ScheduleAutomationValidator::validate().
    boundaryDueSchedule($scheduleOwner, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    expect(boundarySpanCalls($recorder))->toBe([]);
    Bus::assertNothingDispatched();
});
