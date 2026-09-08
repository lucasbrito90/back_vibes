<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\User;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function saerJwt(User $user): UnencryptedToken
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

function saerAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(saerJwt($user)));
}

function saerHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function saerUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-saer-'.uniqid()]);
}

function sceneActionExecutionReportUrl(): string
{
    return '/api/scene-action-executions/report';
}

/**
 * A SceneAction fully owned by $user — Scene, Device, and ProviderConnection
 * all belong to the same user, mirroring the ownership chain the controller
 * walks (SceneAction->scene, SceneAction->device->providerConnection).
 */
function saerOwnedSceneAction(User $user, array $overrides = []): SceneAction
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

function validExecutionReportPayload(array $overrides = []): array
{
    return array_merge([
        'scene_execution_id' => '550e8400-e29b-41d4-a716-446655440000',
        'scene_action_id' => 42,
        'outcome' => SmartHomeActionOutcome::Success->value,
        'duration_ms' => 1500,
    ], $overrides);
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot report scene action execution', function () {
    $this->postJson(sceneActionExecutionReportUrl(), validExecutionReportPayload())
        ->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// Happy path — payload accepted for an owned scene action
// ─────────────────────────────────────────────────────────────────────────────

test('valid execution report with duration_ms returns 202 and echoes submitted payload', function () {
    $user = saerUser('fb-saer-valid');
    $action = saerOwnedSceneAction($user);
    $payload = validExecutionReportPayload(['scene_action_id' => $action->id]);

    saerAuth($user);

    $this->postJson(sceneActionExecutionReportUrl(), $payload, saerHeaders())
        ->assertAccepted()
        ->assertJsonPath('data', $payload);
});

test('valid execution report without duration_ms returns 202', function () {
    $user = saerUser('fb-saer-no-duration');
    $action = saerOwnedSceneAction($user);
    $payload = validExecutionReportPayload(['scene_action_id' => $action->id]);
    unset($payload['duration_ms']);

    saerAuth($user);

    $this->postJson(sceneActionExecutionReportUrl(), $payload, saerHeaders())
        ->assertAccepted()
        ->assertJsonPath('data.scene_execution_id', $payload['scene_execution_id'])
        ->assertJsonPath('data.scene_action_id', $payload['scene_action_id'])
        ->assertJsonPath('data.outcome', $payload['outcome']);
});

test('each SmartHomeActionOutcome value is accepted individually', function (SmartHomeActionOutcome $outcome) {
    $user = saerUser('fb-saer-outcome-'.$outcome->value);
    $action = saerOwnedSceneAction($user);
    $payload = validExecutionReportPayload([
        'scene_action_id' => $action->id,
        'outcome' => $outcome->value,
    ]);

    saerAuth($user);

    $this->postJson(sceneActionExecutionReportUrl(), $payload, saerHeaders())
        ->assertAccepted()
        ->assertJsonPath('data.outcome', $outcome->value);
})->with([
    SmartHomeActionOutcome::Success,
    SmartHomeActionOutcome::Failure,
    SmartHomeActionOutcome::Unsupported,
    SmartHomeActionOutcome::Unknown,
]);

// ─────────────────────────────────────────────────────────────────────────────
// Validation errors — shape only, checked before any ownership lookup
// ─────────────────────────────────────────────────────────────────────────────

test('malformed scene_execution_id returns 422', function () {
    $user = saerUser('fb-saer-bad-uuid');

    saerAuth($user);

    $this->postJson(sceneActionExecutionReportUrl(), validExecutionReportPayload([
        'scene_execution_id' => 'not-a-valid-uuid',
    ]), saerHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scene_execution_id']);
});

test('missing scene_action_id returns 422', function () {
    $user = saerUser('fb-saer-missing-action');

    saerAuth($user);

    $payload = validExecutionReportPayload();
    unset($payload['scene_action_id']);

    saerAuth($user);

    $this->postJson(sceneActionExecutionReportUrl(), $payload, saerHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scene_action_id']);
});

test('outcome outside SmartHomeActionOutcome vocabulary returns 422 with identifying message', function () {
    $user = saerUser('fb-saer-bad-outcome');

    saerAuth($user);

    $response = $this->postJson(sceneActionExecutionReportUrl(), validExecutionReportPayload([
        'outcome' => 'flying',
    ]), saerHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    $messages = collect($response->json('errors.outcome'))->implode(' ');
    expect($messages)->toContain('SmartHomeActionOutcome');
});

test('negative duration_ms returns 422', function () {
    $user = saerUser('fb-saer-neg-duration');

    saerAuth($user);

    $this->postJson(sceneActionExecutionReportUrl(), validExecutionReportPayload([
        'duration_ms' => -1,
    ]), saerHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['duration_ms']);
});
