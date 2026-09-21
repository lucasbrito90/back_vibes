<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\User;
use App\Models\Vibe;
use App\SmartHome\Services\SceneDispatchService;
use App\SmartHome\Services\VibeSmartHomeDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| delay_seconds is honored at dispatch — server-side half
|--------------------------------------------------------------------------
|
| The field has been validated, persisted, exposed by SceneActionResource and
| editable in the app since v1.3.0, and never read at dispatch: every action
| fired at once. The app told the user "Wait this long before the action runs
| (0-3600s)" and nothing waited.
|
| Semantics (PO decision, 20/09/2026 — option (a)): per action, absolute from
| the moment the scene is dispatched. Two actions delayed 10s each both run at
| +10s, not at +10s and +20s. Cumulative semantics would have been a different
| promise than the copy the user already reads.
*/

function sadDevice(User $user, string $provider = 'home_assistant'): Device
{
    $overrides = ['user_id' => $user->id, 'provider' => $provider];

    if ($provider !== 'home_assistant') {
        $overrides['encrypted_credentials'] = null;
        $overrides['config'] = [];
    }

    $connection = ProviderConnection::factory()->create($overrides);

    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $provider,
    ]);
}

/** The delay attached to the queued job for one scene action, or false if it never ran. */
function sadDelayFor(int $sceneActionId): mixed
{
    $pushed = collect(Queue::pushedJobs()[SceneActionJob::class] ?? [])
        ->map(fn (array $entry) => $entry['job'])
        ->first(fn (SceneActionJob $job) => $job->sceneActionId === $sceneActionId);

    return $pushed === null ? false : $pushed->delay;
}

// ── Manual Scene execution ──────────────────────────────────────────────────

test('a delayed action is enqueued with its delay', function () {
    Queue::fake();

    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => sadDevice($user)->id,
        'delay_seconds' => 30,
    ]);

    app(SceneDispatchService::class)->dispatch($scene);

    expect(sadDelayFor($action->id))->toBe(30);
});

test('a zero delay is enqueued exactly as before, with no delay at all', function () {
    // Guards the path every existing scene takes: attaching delay(0) instead of
    // nothing would push these jobs through the delayed-queue machinery for no
    // reason, and would change behaviour for users who never set a delay.
    Queue::fake();

    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => sadDevice($user)->id,
        'delay_seconds' => 0,
    ]);

    app(SceneDispatchService::class)->dispatch($scene);

    expect(sadDelayFor($action->id))->toBeNull();
});

test('each action carries its own delay, absolute from dispatch rather than cumulative', function () {
    // The distinguishing assertion for option (a). Under cumulative semantics
    // the second action would be at 25s and the third at 35s.
    Queue::fake();

    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $device = sadDevice($user);

    $first = SceneAction::factory()->create([
        'scene_id' => $scene->id, 'device_id' => $device->id,
        'sort_order' => 0, 'delay_seconds' => 15,
    ]);
    $second = SceneAction::factory()->create([
        'scene_id' => $scene->id, 'device_id' => $device->id,
        'sort_order' => 1, 'delay_seconds' => 10,
    ]);
    $third = SceneAction::factory()->create([
        'scene_id' => $scene->id, 'device_id' => $device->id,
        'sort_order' => 2, 'delay_seconds' => 10,
    ]);

    app(SceneDispatchService::class)->dispatch($scene);

    expect(sadDelayFor($first->id))->toBe(15)
        ->and(sadDelayFor($second->id))->toBe(10)
        ->and(sadDelayFor($third->id))->toBe(10);
});

test('the dispatch result is unchanged by a delay', function () {
    // A delayed action is dispatched, not skipped — the API contract for
    // POST /scenes/{id}/execute must read the same as before.
    Queue::fake();

    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => sadDevice($user)->id,
        'delay_seconds' => 600,
    ]);

    $result = app(SceneDispatchService::class)->dispatch($scene);

    expect($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and($result->action_ids)->toBe([$action->id]);
});

test('a device-side action is still not enqueued, delay or no delay', function () {
    // Its delay is the mobile runtime's to honor (ADR-036 Decision 7). Enqueuing
    // it here would execute it twice.
    Queue::fake();

    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => sadDevice($user, 'google_home')->id,
        'delay_seconds' => 45,
    ]);

    $result = app(SceneDispatchService::class)->dispatch($scene);

    expect($result->dispatched)->toBe(0)
        ->and($result->device_action_ids)->toBe([$action->id]);

    Queue::assertNothingPushed();
});

// ── Vibe play and the scheduler ─────────────────────────────────────────────

test('the vibe path honors the same delay as the scene path', function () {
    // Before this fix the two paths shared a defect rather than a rule. The
    // point of routing both through SceneActionDispatcher is that they cannot
    // now disagree about what a delay means.
    Queue::fake();

    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $vibe = Vibe::factory()->create(['user_id' => $user->id, 'scene_id' => $scene->id]);
    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => sadDevice($user)->id,
        'delay_seconds' => 90,
    ]);

    app(VibeSmartHomeDispatchService::class)->dispatch($vibe);

    expect(sadDelayFor($action->id))->toBe(90);
});

test('the scheduler path honors the delay too', function () {
    Queue::fake();

    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $vibe = Vibe::factory()->create(['user_id' => $user->id, 'scene_id' => $scene->id]);
    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => sadDevice($user)->id,
        'delay_seconds' => 120,
    ]);

    app(VibeSmartHomeDispatchService::class)->dispatch($vibe, requireScheduledExecution: true);

    expect(sadDelayFor($action->id))->toBe(120);
});

test('the maximum the API accepts survives dispatch intact', function () {
    // 3600 is the validation ceiling in StoreSceneActionRequest. delay_seconds
    // is an unsignedSmallInteger, so nothing here may silently truncate it.
    Queue::fake();

    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => sadDevice($user)->id,
        'delay_seconds' => 3600,
    ]);

    app(SceneDispatchService::class)->dispatch($scene);

    expect(sadDelayFor($action->id))->toBe(3600);
});
