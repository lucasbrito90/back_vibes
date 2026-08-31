<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\User;
use App\SmartHome\ActionType;
use App\SmartHome\DeviceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function sliJwt(User $user): UnencryptedToken
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

function sliAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(sliJwt($user)));
}

function sliHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function sliUser(string $uid): User
{
    return User::factory()->create(['firebase_uid' => $uid]);
}

function sliDeviceFor(User $user, string $name, ?ProviderConnection $connection = null): Device
{
    $connection ??= ProviderConnection::factory()->create(['user_id' => $user->id]);

    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'name' => $name,
        'status' => DeviceStatus::Online->value,
    ]);
}

/**
 * Executable documentation: user A runs the full Scene lifecycle (create → actions → execute),
 * then user B is blocked with 404 on every Scene endpoint for that resource.
 *
 * Complements the per-endpoint 404 tests in SceneApiTest, SceneActionApiTest, and
 * SceneExecuteApiTest by narrating the end-to-end story in one place.
 */
test('scene lifecycle succeeds for owner and blocks cross-user on every endpoint', function () {
    Queue::fake();

    $alice = sliUser('fb-sli-alice');
    $bob = sliUser('fb-sli-bob');

    $connection = ProviderConnection::factory()->create(['user_id' => $alice->id]);
    $livingRoom = sliDeviceFor($alice, 'Living Room Light', $connection);
    $bedroom = sliDeviceFor($alice, 'Bedroom Lamp', $connection);

    // ── User A: create scene ──────────────────────────────────────────────────
    sliAuth($alice);

    $createResponse = $this->postJson('/api/scenes', [
        'name' => 'Movie Night',
        'description' => 'Dim lights for cinema',
    ], sliHeaders())->assertCreated();

    $sceneId = $createResponse->json('data.id');
    expect($sceneId)->toBeInt()
        ->and(Scene::where('user_id', $alice->id)->count())->toBe(1);

    // ── User A: add two actions on different devices ────────────────────────
    $actionOne = $this->postJson("/api/scenes/{$sceneId}/actions", [
        'device_id' => $livingRoom->id,
        'action_type' => ActionType::TurnOn->value,
        'delay_seconds' => 0,
    ], sliHeaders())->assertCreated()->json('data');

    $actionTwo = $this->postJson("/api/scenes/{$sceneId}/actions", [
        'device_id' => $bedroom->id,
        'action_type' => ActionType::TurnOff->value,
        'delay_seconds' => 5,
    ], sliHeaders())->assertCreated()->json('data');

    expect(SceneAction::where('scene_id', $sceneId)->count())->toBe(2);

    $listResponse = $this->getJson("/api/scenes/{$sceneId}/actions", sliHeaders())
        ->assertOk();

    expect(collect($listResponse->json('data'))->pluck('id')->all())
        ->toBe([$actionOne['id'], $actionTwo['id']]);

    // ── User A: execute scene (jobs enqueued, not run) ──────────────────────
    $executeResponse = $this->postJson("/api/scenes/{$sceneId}/execute", [], sliHeaders())
        ->assertOk()
        ->assertJsonStructure(['data' => ['scene_id', 'dispatched', 'skipped', 'action_ids']]);

    expect($executeResponse->json('data.scene_id'))->toBe($sceneId)
        ->and($executeResponse->json('data.dispatched'))->toBe(2)
        ->and($executeResponse->json('data.skipped'))->toBe(0)
        ->and($executeResponse->json('data.action_ids'))->toBe([$actionOne['id'], $actionTwo['id']]);

    Queue::assertPushed(SceneActionJob::class, 2);

    // ── User B: 404 on scene CRUD ───────────────────────────────────────────
    sliAuth($bob);

    $this->getJson("/api/scenes/{$sceneId}", sliHeaders())->assertNotFound();

    $this->patchJson("/api/scenes/{$sceneId}", ['name' => 'Hijacked'], sliHeaders())
        ->assertNotFound();

    $this->deleteJson("/api/scenes/{$sceneId}", [], sliHeaders())->assertNotFound();

    expect(Scene::find($sceneId))->not->toBeNull();

    // ── User B: 404 on scene actions (list/create/update/delete/reorder) ────
    $bobDevice = sliDeviceFor($bob, 'Bob Lamp');

    $this->getJson("/api/scenes/{$sceneId}/actions", sliHeaders())->assertNotFound();

    $this->postJson("/api/scenes/{$sceneId}/actions", [
        'device_id' => $bobDevice->id,
        'action_type' => ActionType::Toggle->value,
    ], sliHeaders())->assertNotFound();

    $this->patchJson("/api/scenes/{$sceneId}/actions/{$actionOne['id']}", [
        'delay_seconds' => 99,
    ], sliHeaders())->assertNotFound();

    $this->deleteJson("/api/scenes/{$sceneId}/actions/{$actionOne['id']}", [], sliHeaders())
        ->assertNotFound();

    $this->postJson("/api/scenes/{$sceneId}/actions/reorder", [
        'ordered_ids' => [$actionTwo['id'], $actionOne['id']],
    ], sliHeaders())->assertNotFound();

    expect(SceneAction::find($actionOne['id']))->not->toBeNull()
        ->and(SceneAction::find($actionTwo['id']))->not->toBeNull();

    // ── User B: 404 on execute (no jobs pushed) ─────────────────────────────
    Queue::fake();

    $this->postJson("/api/scenes/{$sceneId}/execute", [], sliHeaders())->assertNotFound();

    Queue::assertNothingPushed();
});
