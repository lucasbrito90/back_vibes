<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\User;
use App\SmartHome\Services\SceneDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// P15 / ADR-036 Decision 7 — SceneDispatchService (manual "Execute scene")
// no longer enqueues a SceneActionJob for a provider that does not declare
// ServerSideExecution (today, in practice, Google Home). Such actions are
// returned as `device_action_ids` instead — the mobile runtime executes
// them locally and reports the outcome via
// POST /api/scene-action-executions/report.
//
// Decision is resolved purely via ProviderDescriptorRegistry's
// execution_capabilities — never a provider slug comparison
// (ProviderExtensibilityBoundaryTest enforces this for this exact file).
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a device wired to a ProviderConnection with the given provider slug.
 * Mirrors p08Device() from ScheduledSkipUnsupportedExecutionTest.php.
 */
function sdsseDevice(User $user, string $provider): Device
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
 * @return array{scene: Scene, ha_action: SceneAction, gh_action: SceneAction}
 */
function sdsseMixedScene(User $user): array
{
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    $haDevice = sdsseDevice($user, 'home_assistant');
    $ghDevice = sdsseDevice($user, 'google_home');

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

    return ['scene' => $scene, 'ha_action' => $haAction, 'gh_action' => $ghAction];
}

test('an action whose provider lacks server_side_execution does not enqueue a job and is returned as device-side', function () {
    Bus::fake();

    $user = User::factory()->create();
    $ghDevice = sdsseDevice($user, 'google_home');
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $ghAction = SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $ghDevice->id]);

    $result = app(SceneDispatchService::class)->dispatch($scene);

    expect($result->dispatched)->toBe(0)
        ->and($result->skipped)->toBe(0)
        ->and($result->action_ids)->toBe([])
        ->and($result->device_action_ids)->toBe([$ghAction->id]);

    Bus::assertNothingDispatched();
});

test('a Home Assistant action keeps enqueuing exactly as before — server-side execution unchanged', function () {
    Bus::fake();

    $user = User::factory()->create();
    $haDevice = sdsseDevice($user, 'home_assistant');
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $haAction = SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $haDevice->id]);

    $result = app(SceneDispatchService::class)->dispatch($scene);

    expect($result->dispatched)->toBe(1)
        ->and($result->action_ids)->toBe([$haAction->id])
        ->and($result->device_action_ids)->toBe([]);

    Bus::assertDispatchedTimes(SceneActionJob::class, 1);
    Bus::assertDispatched(SceneActionJob::class, fn (SceneActionJob $job) => $job->sceneActionId === $haAction->id);
});

test('a mixed-provider scene dispatches the HA action and delegates the Google Home action under the same scene_execution_id', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['scene' => $scene, 'ha_action' => $haAction, 'gh_action' => $ghAction] = sdsseMixedScene($user);

    $result = app(SceneDispatchService::class)->dispatch($scene);

    expect($result->dispatched)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and($result->action_ids)->toBe([$haAction->id])
        ->and($result->device_action_ids)->toBe([$ghAction->id])
        ->and($result->scene_execution_id)->not->toBeEmpty();

    Bus::assertDispatchedTimes(SceneActionJob::class, 1);
    Bus::assertDispatched(
        SceneActionJob::class,
        fn (SceneActionJob $job) => $job->sceneActionId === $haAction->id
            && $job->sceneExecutionId === $result->scene_execution_id,
    );
    Bus::assertNotDispatched(SceneActionJob::class, fn (SceneActionJob $job) => $job->sceneActionId === $ghAction->id);
});

test('device-side delegation never resolves an adapter or makes HTTP requests', function () {
    Bus::fake();

    $user = User::factory()->create();
    ['scene' => $scene] = sdsseMixedScene($user);

    // No adapter is registered for google_home at all (config/smart_home.php
    // `adapters`) — if SceneDispatchService ever tried to resolve one for the
    // device-side action, this would throw before the assertion below runs.
    $result = app(SceneDispatchService::class)->dispatch($scene);

    expect($result->device_action_ids)->not->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// API level — POST /api/scenes/{scene}/execute — device_action_ids is an
// additive field on the existing response shape.
// ─────────────────────────────────────────────────────────────────────────────

function sdsseJwt(User $user): UnencryptedToken
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

function sdsseAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(sdsseJwt($user)));
}

test('POST /api/scenes/{scene}/execute response includes device_action_ids additively', function () {
    Bus::fake();

    $user = User::factory()->create(['firebase_uid' => 'fb-sdsse-'.uniqid()]);
    ['scene' => $scene, 'ha_action' => $haAction, 'gh_action' => $ghAction] = sdsseMixedScene($user);

    sdsseAuth($user);

    $response = $this->postJson(
        "/api/scenes/{$scene->id}/execute",
        [],
        ['Authorization' => 'Bearer tok'],
    )->assertOk()->assertJsonStructure([
        'data' => ['scene_id', 'dispatched', 'skipped', 'action_ids', 'scene_execution_id', 'device_action_ids'],
    ]);

    expect($response->json('data.dispatched'))->toBe(1)
        ->and($response->json('data.action_ids'))->toBe([$haAction->id])
        ->and($response->json('data.device_action_ids'))->toBe([$ghAction->id]);
});
