<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\SceneActionExecution;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Vibe;
use App\Services\Scheduling\RecurrenceType;
use App\SmartHome\Services\VibeSmartHomeDispatchService;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
//
// P08 / ADR-036 Decision 5 — scheduler-initiated actions whose provider does
// not declare ScheduledExecution are individually skipped rather than
// blocking the whole dispatch. Manual dispatch (requireScheduledExecution=false)
// is never affected.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a device wired to a ProviderConnection with the given provider slug.
 *
 * For home_assistant: uses the factory default (encrypted credentials present).
 * For google_home: encrypted_credentials is null (ADR-036 Decision 4 / P02).
 */
function p08Device(User $user, string $provider): Device
{
    $connectionOverrides = ['user_id' => $user->id, 'provider' => $provider];

    if ($provider !== 'home_assistant') {
        $connectionOverrides['encrypted_credentials'] = null;
        $connectionOverrides['config'] = [];
    }

    $connection = ProviderConnection::factory()->create($connectionOverrides);

    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $provider,
    ]);
}

/**
 * Build a Vibe owned by $user with a scene containing one HA action + one
 * Google Home action. Used by scheduled-dispatch tests.
 *
 * @return array{vibe: Vibe, ha_action: SceneAction, gh_action: SceneAction}
 */
function p08MixedVibe(User $user): array
{
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $vibe = Vibe::factory()->create(['user_id' => $user->id, 'scene_id' => $scene->id]);

    $haDevice = p08Device($user, 'home_assistant');
    $ghDevice = p08Device($user, 'google_home');

    $haAction = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $haDevice->id,
        'sort_order' => 0,
    ]);

    $ghAction = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $ghDevice->id,
        'sort_order' => 1,
    ]);

    return ['vibe' => $vibe, 'ha_action' => $haAction, 'gh_action' => $ghAction];
}

/**
 * Create a due schedule owned by $user linked to $vibe.
 */
function p08DueSchedule(User $user, Vibe $vibe): Schedule
{
    $nowUtc = CarbonImmutable::now('UTC');

    return Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
        'timezone' => 'UTC',
        'start_time' => $nowUtc->subMinute(),
        'recurrence_type' => RecurrenceType::Once->value,
        'recurrence_config' => null,
        'is_enabled' => true,
        'next_run_at' => $nowUtc->subMinute(),
        'last_run_at' => null,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Core behavior — mixed schedule with HA + Google Home actions
// ─────────────────────────────────────────────────────────────────────────────

test('scheduler dispatch: HA action is dispatched and Google Home action is skipped', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['vibe' => $vibe, 'ha_action' => $haAction, 'gh_action' => $ghAction] = p08MixedVibe($user);
    p08DueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    // HA action → SceneActionJob enqueued
    Bus::assertDispatched(
        SceneActionJob::class,
        fn (SceneActionJob $job) => $job->sceneActionId === $haAction->id,
    );

    // Google Home action → NOT enqueued
    Bus::assertNotDispatched(
        SceneActionJob::class,
        fn (SceneActionJob $job) => $job->sceneActionId === $ghAction->id,
    );

    Bus::assertDispatchedTimes(SceneActionJob::class, 1);
});

test('skipped Google Home action produces a scene_action_executions row with SkippedUnsupportedExecution outcome', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['vibe' => $vibe, 'gh_action' => $ghAction] = p08MixedVibe($user);
    p08DueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $execution = SceneActionExecution::query()
        ->where('scene_action_id', $ghAction->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->outcome)->toBe(SmartHomeActionOutcome::SkippedUnsupportedExecution->value)
        ->and($execution->failure_category)->toBeNull()
        ->and($execution->attempt)->toBe(1);
});

test('SkippedUnsupportedExecution result does not dispatch a push notification for failure', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['vibe' => $vibe] = p08MixedVibe($user);
    p08DueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    // Only one job (HA action) — no push notification job for the skipped GH action
    Bus::assertDispatchedTimes(SceneActionJob::class, 1);
    // PushNotificationJob is dispatched only by SceneActionJob itself on real failure —
    // since the GH action never enqueues a SceneActionJob, no push can be triggered for it
});

test('skipped action does not consume retry attempts — SceneActionJob is never enqueued for it', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['vibe' => $vibe, 'gh_action' => $ghAction] = p08MixedVibe($user);
    p08DueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    Bus::assertNotDispatched(
        SceneActionJob::class,
        fn (SceneActionJob $job) => $job->sceneActionId === $ghAction->id,
    );
    // No retry possible if no job is ever enqueued ✓
});

// ─────────────────────────────────────────────────────────────────────────────
// Manual dispatch is unaffected (requireScheduledExecution = false by default)
// ─────────────────────────────────────────────────────────────────────────────

test('manual dispatch (requireScheduledExecution=false) enqueues BOTH HA and Google Home actions', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['vibe' => $vibe, 'ha_action' => $haAction, 'gh_action' => $ghAction] = p08MixedVibe($user);

    $result = app(VibeSmartHomeDispatchService::class)->dispatch($vibe);

    expect($result->dispatched)->toBe(2)
        ->and($result->skipped)->toBe(0)
        ->and($result->skipped_unsupported_execution)->toBe(0)
        ->and($result->action_ids)->toBe([$haAction->id, $ghAction->id]);

    Bus::assertDispatchedTimes(SceneActionJob::class, 2);
    Bus::assertDispatched(SceneActionJob::class, fn (SceneActionJob $job) => $job->sceneActionId === $haAction->id);
    Bus::assertDispatched(SceneActionJob::class, fn (SceneActionJob $job) => $job->sceneActionId === $ghAction->id);
});

test('manual dispatch creates no scene_action_executions rows (no recording for scheduled-skip path)', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['vibe' => $vibe] = p08MixedVibe($user);

    $before = SceneActionExecution::query()->count();

    app(VibeSmartHomeDispatchService::class)->dispatch($vibe);

    expect(SceneActionExecution::query()->count())->toBe($before);
});

// ─────────────────────────────────────────────────────────────────────────────
// DispatchResult fields
// ─────────────────────────────────────────────────────────────────────────────

test('dispatch with requireScheduledExecution=true counts skipped_unsupported_execution correctly', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['vibe' => $vibe] = p08MixedVibe($user);

    $result = app(VibeSmartHomeDispatchService::class)->dispatch($vibe, requireScheduledExecution: true);

    expect($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and($result->skipped_unsupported_execution)->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Ownership failure still blocks everything (ownership is all-or-nothing)
// ─────────────────────────────────────────────────────────────────────────────

test('schedule with action owned by another user is blocked entirely — no HA or GH action dispatched', function () {
    Bus::fake();

    $scheduleOwner = User::factory()->create();
    $otherUser = User::factory()->create();

    // Vibe belongs to otherUser but schedule belongs to scheduleOwner → ownership mismatch
    $scene = Scene::factory()->create(['user_id' => $otherUser->id]);
    $vibe = Vibe::factory()->create(['user_id' => $otherUser->id, 'scene_id' => $scene->id]);
    $device = p08Device($otherUser, 'home_assistant');
    SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $device->id]);

    p08DueSchedule($scheduleOwner, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    Bus::assertNothingDispatched();
    expect(SceneActionExecution::query()->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// SceneActionExecution accessibility (readable via existing controller path)
// ─────────────────────────────────────────────────────────────────────────────

test('scene_action_executions row for skipped GH action has the correct scene_id linking it to the vibe\'s scene', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['vibe' => $vibe, 'gh_action' => $ghAction] = p08MixedVibe($user);
    p08DueSchedule($user, $vibe);

    $this->artisan('schedules:dispatch-due')->assertSuccessful();

    $execution = SceneActionExecution::query()
        ->where('scene_action_id', $ghAction->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->scene_id)->toBe($ghAction->scene_id);
});
