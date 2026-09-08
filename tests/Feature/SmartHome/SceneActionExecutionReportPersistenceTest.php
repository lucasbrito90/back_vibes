<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\SceneActionExecution;
use App\Models\User;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
//
// This file covers real persistence for POST /api/scene-action-executions/report
// (P07): ownership enforcement and check-then-insert idempotency on
// (scene_execution_id, scene_action_id), reusing SceneActionExecutionRecorder.
// Kept separate from SceneActionExecutionReportScaffoldTest.php (P06), which
// only exercises payload-shape validation.
// ─────────────────────────────────────────────────────────────────────────────

function saexJwt(User $user): UnencryptedToken
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

function saexAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(saexJwt($user)));
}

function saexHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function saexUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-saex-'.uniqid()]);
}

function saexUrl(): string
{
    return '/api/scene-action-executions/report';
}

/**
 * A SceneAction fully owned by $user — Scene, Device, and ProviderConnection
 * all belong to the same user, mirroring the ownership chain the controller
 * walks (SceneAction->scene, SceneAction->device->providerConnection).
 */
function saexOwnedSceneAction(User $user, array $overrides = []): SceneAction
{
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
    ]);
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    return SceneAction::factory()->create(array_merge([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
    ], $overrides));
}

function saexPayload(SceneAction $action, array $overrides = []): array
{
    return array_merge([
        'scene_execution_id' => '550e8400-e29b-41d4-a716-446655440000',
        'scene_action_id' => $action->id,
        'outcome' => SmartHomeActionOutcome::Success->value,
        'duration_ms' => 1500,
    ], $overrides);
}

// ─────────────────────────────────────────────────────────────────────────────
// Persistence — happy path
// ─────────────────────────────────────────────────────────────────────────────

test('valid report from the owning user persists one execution row with correct data', function () {
    $user = saexUser('fb-saex-persist');
    $action = saexOwnedSceneAction($user);
    $payload = saexPayload($action, ['outcome' => SmartHomeActionOutcome::Success->value, 'duration_ms' => 842]);

    saexAuth($user);

    $this->postJson(saexUrl(), $payload, saexHeaders())->assertAccepted();

    expect(SceneActionExecution::query()->count())->toBe(1);

    $execution = SceneActionExecution::query()->first();

    $action->refresh();
    $device = $action->device;
    $connection = $device->providerConnection;

    expect($execution->scene_execution_id)->toBe($payload['scene_execution_id'])
        ->and($execution->scene_action_id)->toBe($action->id)
        ->and($execution->scene_id)->toBe($action->scene_id)
        ->and($execution->device_id)->toBe($device->id)
        ->and($execution->provider)->toBe($connection->provider)
        ->and($execution->provider_connection_id)->toBe($connection->id)
        ->and($execution->action_type)->toBe($action->action_type)
        ->and($execution->outcome)->toBe(SmartHomeActionOutcome::Success->value)
        ->and($execution->failure_category)->toBeNull()
        ->and($execution->duration_ms)->toBe(842)
        ->and($execution->attempt)->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Idempotency — check-then-insert on (scene_execution_id, scene_action_id)
// ─────────────────────────────────────────────────────────────────────────────

test('reporting the same scene_execution_id and scene_action_id twice persists only one row', function () {
    $user = saexUser('fb-saex-idempotent');
    $action = saexOwnedSceneAction($user);
    $payload = saexPayload($action);

    saexAuth($user);

    $this->postJson(saexUrl(), $payload, saexHeaders())->assertAccepted();
    $this->postJson(saexUrl(), $payload, saexHeaders())->assertAccepted();

    expect(SceneActionExecution::query()
        ->where('scene_execution_id', $payload['scene_execution_id'])
        ->where('scene_action_id', $action->id)
        ->count())->toBe(1);
});

test('duplicate report with a different outcome still does not create a second row', function () {
    $user = saexUser('fb-saex-idempotent-diff-outcome');
    $action = saexOwnedSceneAction($user);
    $payload = saexPayload($action, ['outcome' => SmartHomeActionOutcome::Success->value]);

    saexAuth($user);

    $this->postJson(saexUrl(), $payload, saexHeaders())->assertAccepted();

    $secondPayload = saexPayload($action, [
        'scene_execution_id' => $payload['scene_execution_id'],
        'outcome' => SmartHomeActionOutcome::Failure->value,
    ]);
    $this->postJson(saexUrl(), $secondPayload, saexHeaders())->assertAccepted();

    expect(SceneActionExecution::query()->count())->toBe(1)
        ->and(SceneActionExecution::query()->first()->outcome)->toBe(SmartHomeActionOutcome::Success->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// Ownership — 404, not 403, for nonexistent or foreign scene_action_id
// ─────────────────────────────────────────────────────────────────────────────

test('nonexistent scene_action_id returns 404 and persists nothing', function () {
    $user = saexUser('fb-saex-missing-action');

    $before = DB::table('scene_action_executions')->count();

    saexAuth($user);

    $this->postJson(saexUrl(), [
        'scene_execution_id' => '550e8400-e29b-41d4-a716-446655440000',
        'scene_action_id' => 999999,
        'outcome' => SmartHomeActionOutcome::Success->value,
        'duration_ms' => 100,
    ], saexHeaders())->assertNotFound();

    expect(DB::table('scene_action_executions')->count())->toBe($before);
});

test('scene_action_id belonging to another user returns 404, not 403, and persists nothing', function () {
    $owner = saexUser('fb-saex-owner');
    $intruder = saexUser('fb-saex-intruder');
    $action = saexOwnedSceneAction($owner);

    $before = DB::table('scene_action_executions')->count();

    saexAuth($intruder);

    $this->postJson(saexUrl(), saexPayload($action), saexHeaders())
        ->assertNotFound();

    expect(DB::table('scene_action_executions')->count())->toBe($before);
});

// ─────────────────────────────────────────────────────────────────────────────
// failure_category — matches SceneActionExecutionRecorder's resolution per outcome
// ─────────────────────────────────────────────────────────────────────────────

test('failure_category is resolved per outcome exactly as SceneActionExecutionRecorder already does', function (SmartHomeActionOutcome $outcome, ?string $expectedCategory) {
    $user = saexUser('fb-saex-category-'.$outcome->value);
    $action = saexOwnedSceneAction($user);
    $payload = saexPayload($action, ['outcome' => $outcome->value]);

    saexAuth($user);

    $this->postJson(saexUrl(), $payload, saexHeaders())->assertAccepted();

    $execution = SceneActionExecution::query()
        ->where('scene_action_id', $action->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->outcome)->toBe($outcome->value)
        ->and($execution->failure_category)->toBe($expectedCategory);
})->with([
    'success' => [SmartHomeActionOutcome::Success, null],
    'failure' => [SmartHomeActionOutcome::Failure, 'provider_error'],
    'unsupported' => [SmartHomeActionOutcome::Unsupported, 'unsupported_action'],
    'unknown' => [SmartHomeActionOutcome::Unknown, 'unexpected'],
]);
