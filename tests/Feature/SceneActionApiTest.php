<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\User;
use App\SmartHome\ActionType;
use App\SmartHome\DeviceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function saaJwt(User $user): UnencryptedToken
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

function saaAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(saaJwt($user)));
}

function saaHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function saaUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-saa-'.uniqid()]);
}

function saaDeviceFor(User $user, string $name = 'Living Room Light'): Device
{
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'name' => $name,
        'status' => DeviceStatus::Online->value,
    ]);
}

function saaSceneFor(User $user, array $overrides = []): Scene
{
    return Scene::factory()->create([
        'user_id' => $user->id,
        ...$overrides,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot list scene actions', function () {
    $scene = Scene::factory()->create();

    $this->getJson("/api/scenes/{$scene->id}/actions")->assertUnauthorized();
});

test('unauthenticated cannot create scene action', function () {
    $scene = Scene::factory()->create();

    $this->postJson("/api/scenes/{$scene->id}/actions", [])->assertUnauthorized();
});

test('unauthenticated cannot update scene action', function () {
    $action = SceneAction::factory()->create();

    $this->patchJson("/api/scenes/{$action->scene_id}/actions/{$action->id}", [])
        ->assertUnauthorized();
});

test('unauthenticated cannot delete scene action', function () {
    $action = SceneAction::factory()->create();

    $this->deleteJson("/api/scenes/{$action->scene_id}/actions/{$action->id}")
        ->assertUnauthorized();
});

test('unauthenticated cannot reorder scene actions', function () {
    $scene = Scene::factory()->create();

    $this->postJson("/api/scenes/{$scene->id}/actions/reorder", ['ordered_ids' => [1]])
        ->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// Index
// ─────────────────────────────────────────────────────────────────────────────

test('owner can list scene actions', function () {
    $user = saaUser('fb-saa-list');
    $scene = saaSceneFor($user);
    $device = saaDeviceFor($user);

    SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'sort_order' => 0,
    ]);

    saaAuth($user);

    $this->getJson("/api/scenes/{$scene->id}/actions", saaHeaders())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.scene_id', $scene->id);
});

test('cross-user cannot list another users scene actions', function () {
    $alice = saaUser('fb-saa-xuser-list-alice');
    $bob = saaUser('fb-saa-xuser-list-bob');

    $bobScene = saaSceneFor($bob);

    saaAuth($alice);

    $this->getJson("/api/scenes/{$bobScene->id}/actions", saaHeaders())
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Store
// ─────────────────────────────────────────────────────────────────────────────

test('owner can add action to scene', function () {
    $user = saaUser('fb-saa-store');
    $scene = saaSceneFor($user);
    $device = saaDeviceFor($user);

    saaAuth($user);

    $this->postJson("/api/scenes/{$scene->id}/actions", [
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'delay_seconds' => 30,
    ], saaHeaders())
        ->assertCreated()
        ->assertJsonPath('data.device_id', $device->id)
        ->assertJsonPath('data.action_type', ActionType::TurnOn->value)
        ->assertJsonPath('data.delay_seconds', 30)
        ->assertJsonPath('data.scene_id', $scene->id);
});

test('store appends sort_order when missing', function () {
    $user = saaUser('fb-saa-store-append');
    $scene = saaSceneFor($user);
    $device = saaDeviceFor($user);

    SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'sort_order' => 0,
    ]);
    SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'sort_order' => 1,
    ]);

    saaAuth($user);

    $this->postJson("/api/scenes/{$scene->id}/actions", [
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOff->value,
    ], saaHeaders())
        ->assertCreated()
        ->assertJsonPath('data.sort_order', 2);
});

test('store on empty scene sets sort_order to 0', function () {
    $user = saaUser('fb-saa-store-first');
    $scene = saaSceneFor($user);
    $device = saaDeviceFor($user);

    saaAuth($user);

    $this->postJson("/api/scenes/{$scene->id}/actions", [
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
    ], saaHeaders())
        ->assertCreated()
        ->assertJsonPath('data.sort_order', 0);
});

test('store rejects foreign device', function () {
    $alice = saaUser('fb-saa-store-foreign-alice');
    $bob = saaUser('fb-saa-store-foreign-bob');

    $scene = saaSceneFor($alice);
    $bobDevice = saaDeviceFor($bob);

    saaAuth($alice);

    $this->postJson("/api/scenes/{$scene->id}/actions", [
        'device_id' => $bobDevice->id,
        'action_type' => ActionType::TurnOn->value,
    ], saaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['device_id']);
});

test('store rejects invalid action_type', function () {
    $user = saaUser('fb-saa-store-bad-action');
    $scene = saaSceneFor($user);
    $device = saaDeviceFor($user);

    saaAuth($user);

    $this->postJson("/api/scenes/{$scene->id}/actions", [
        'device_id' => $device->id,
        'action_type' => 'set_brightness',
    ], saaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['action_type']);
});

test('cross-user cannot create action on another users scene', function () {
    $alice = saaUser('fb-saa-xuser-store-alice');
    $bob = saaUser('fb-saa-xuser-store-bob');

    $bobScene = saaSceneFor($bob);
    $aliceDevice = saaDeviceFor($alice);

    saaAuth($alice);

    $this->postJson("/api/scenes/{$bobScene->id}/actions", [
        'device_id' => $aliceDevice->id,
        'action_type' => ActionType::TurnOn->value,
    ], saaHeaders())
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Update
// ─────────────────────────────────────────────────────────────────────────────

test('owner can update own scene action', function () {
    $user = saaUser('fb-saa-update');
    $scene = saaSceneFor($user);
    $device = saaDeviceFor($user);

    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'delay_seconds' => 0,
    ]);

    saaAuth($user);

    $this->patchJson("/api/scenes/{$scene->id}/actions/{$action->id}", [
        'action_type' => ActionType::TurnOff->value,
        'delay_seconds' => 120,
    ], saaHeaders())
        ->assertOk()
        ->assertJsonPath('data.action_type', ActionType::TurnOff->value)
        ->assertJsonPath('data.delay_seconds', 120);

    expect($action->fresh()->delay_seconds)->toBe(120);
});

test('update rejects foreign device', function () {
    $alice = saaUser('fb-saa-update-foreign-alice');
    $bob = saaUser('fb-saa-update-foreign-bob');

    $scene = saaSceneFor($alice);
    $aliceDevice = saaDeviceFor($alice);
    $bobDevice = saaDeviceFor($bob);

    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $aliceDevice->id,
    ]);

    saaAuth($alice);

    $this->patchJson("/api/scenes/{$scene->id}/actions/{$action->id}", [
        'device_id' => $bobDevice->id,
    ], saaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['device_id']);
});

test('update rejects action not belonging to scene', function () {
    $user = saaUser('fb-saa-update-wrong-scene');
    $sceneA = saaSceneFor($user);
    $sceneB = saaSceneFor($user);
    $device = saaDeviceFor($user);

    $action = SceneAction::factory()->create([
        'scene_id' => $sceneB->id,
        'device_id' => $device->id,
    ]);

    saaAuth($user);

    $this->patchJson("/api/scenes/{$sceneA->id}/actions/{$action->id}", [
        'delay_seconds' => 10,
    ], saaHeaders())
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Destroy
// ─────────────────────────────────────────────────────────────────────────────

test('owner can delete own scene action', function () {
    $user = saaUser('fb-saa-delete');
    $scene = saaSceneFor($user);
    $device = saaDeviceFor($user);

    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
    ]);

    saaAuth($user);

    $this->deleteJson("/api/scenes/{$scene->id}/actions/{$action->id}", [], saaHeaders())
        ->assertNoContent();

    expect(SceneAction::find($action->id))->toBeNull();
});

test('delete rejects action not belonging to scene', function () {
    $user = saaUser('fb-saa-delete-wrong-scene');
    $sceneA = saaSceneFor($user);
    $sceneB = saaSceneFor($user);
    $device = saaDeviceFor($user);

    $action = SceneAction::factory()->create([
        'scene_id' => $sceneB->id,
        'device_id' => $device->id,
    ]);

    saaAuth($user);

    $this->deleteJson("/api/scenes/{$sceneA->id}/actions/{$action->id}", [], saaHeaders())
        ->assertNotFound();

    expect(SceneAction::find($action->id))->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Reorder
// ─────────────────────────────────────────────────────────────────────────────

test('owner can reorder scene actions', function () {
    $user = saaUser('fb-saa-reorder');
    $scene = saaSceneFor($user);
    $device = saaDeviceFor($user);

    $a = SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $device->id, 'sort_order' => 0]);
    $b = SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $device->id, 'sort_order' => 1]);
    $c = SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $device->id, 'sort_order' => 2]);

    saaAuth($user);

    $response = $this->postJson("/api/scenes/{$scene->id}/actions/reorder", [
        'ordered_ids' => [$c->id, $a->id, $b->id],
    ], saaHeaders())->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$c->id, $a->id, $b->id]);

    expect($c->fresh()->sort_order)->toBe(0)
        ->and($a->fresh()->sort_order)->toBe(1)
        ->and($b->fresh()->sort_order)->toBe(2);
});

test('reorder rejects action from another scene', function () {
    $user = saaUser('fb-saa-reorder-foreign');
    $scene = saaSceneFor($user);
    $otherScene = saaSceneFor($user);
    $device = saaDeviceFor($user);

    $mine = SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $device->id, 'sort_order' => 0]);
    $foreign = SceneAction::factory()->create(['scene_id' => $otherScene->id, 'device_id' => $device->id, 'sort_order' => 0]);

    saaAuth($user);

    $this->postJson("/api/scenes/{$scene->id}/actions/reorder", [
        'ordered_ids' => [$mine->id, $foreign->id],
    ], saaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ordered_ids']);

    expect($mine->fresh()->sort_order)->toBe(0)
        ->and($foreign->fresh()->sort_order)->toBe(0);
});

test('cross-user cannot reorder another users scene actions', function () {
    $alice = saaUser('fb-saa-reorder-xuser-alice');
    $bob = saaUser('fb-saa-reorder-xuser-bob');

    $bobScene = saaSceneFor($bob);
    $device = saaDeviceFor($bob);
    $action = SceneAction::factory()->create([
        'scene_id' => $bobScene->id,
        'device_id' => $device->id,
        'sort_order' => 0,
    ]);

    saaAuth($alice);

    $this->postJson("/api/scenes/{$bobScene->id}/actions/reorder", [
        'ordered_ids' => [$action->id],
    ], saaHeaders())
        ->assertNotFound();
});
