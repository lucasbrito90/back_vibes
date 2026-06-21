<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use App\SmartHome\ActionType;
use App\SmartHome\DeviceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers (uniquely named to avoid clashing with other SmartHome test helpers)
// ─────────────────────────────────────────────────────────────────────────────

function vdaJwt(User $user): UnencryptedToken
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

function vdaAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(vdaJwt($user)));
}

function vdaHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function vdaUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-vda-'.uniqid()]);
}

function vdaDeviceFor(User $user, string $name = 'Living Room Light'): Device
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

// ─────────────────────────────────────────────────────────────────────────────
// Authentication — 401 for all endpoints
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot list vibe device actions', function () {
    $vibe = Vibe::factory()->create();

    $this->getJson("/api/vibes/{$vibe->id}/device-actions")->assertUnauthorized();
});

test('unauthenticated cannot create vibe device action', function () {
    $vibe = Vibe::factory()->create();

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [])->assertUnauthorized();
});

test('unauthenticated cannot update vibe device action', function () {
    $action = VibeDeviceAction::factory()->create();

    $this->patchJson("/api/vibes/{$action->vibe_id}/device-actions/{$action->id}", [])
        ->assertUnauthorized();
});

test('unauthenticated cannot delete vibe device action', function () {
    $action = VibeDeviceAction::factory()->create();

    $this->deleteJson("/api/vibes/{$action->vibe_id}/device-actions/{$action->id}")
        ->assertUnauthorized();
});

test('unauthenticated cannot reorder vibe device actions', function () {
    $vibe = Vibe::factory()->create();

    $this->postJson("/api/vibes/{$vibe->id}/device-actions/reorder", ['ordered_ids' => [1]])
        ->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// Index
// ─────────────────────────────────────────────────────────────────────────────

test('owner can list device actions', function () {
    $user = vdaUser('fb-vda-list');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'sort_order' => 0,
    ]);

    vdaAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}/device-actions", vdaHeaders())
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('index returns actions ordered by sort_order', function () {
    $user = vdaUser('fb-vda-order');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    $third = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 2,
    ]);
    $first = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 0,
    ]);
    $second = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 1,
    ]);

    vdaAuth($user);

    $response = $this->getJson("/api/vibes/{$vibe->id}/device-actions", vdaHeaders())->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$first->id, $second->id, $third->id]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Store
// ─────────────────────────────────────────────────────────────────────────────

test('owner can create device action', function () {
    $user = vdaUser('fb-vda-store');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'delay_seconds' => 30,
    ], vdaHeaders())
        ->assertCreated()
        ->assertJsonPath('data.device_id', $device->id)
        ->assertJsonPath('data.action_type', ActionType::TurnOn->value)
        ->assertJsonPath('data.delay_seconds', 30)
        ->assertJsonPath('data.vibe_id', $vibe->id);
});

test('store defaults delay_seconds to 0 and parameters to null', function () {
    $user = vdaUser('fb-vda-store-defaults');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [
        'device_id' => $device->id,
        'action_type' => ActionType::Toggle->value,
    ], vdaHeaders())
        ->assertCreated()
        ->assertJsonPath('data.delay_seconds', 0)
        ->assertJsonPath('data.parameters', null);
});

test('store appends sort_order when missing', function () {
    $user = vdaUser('fb-vda-store-append');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 0,
    ]);
    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 1,
    ]);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOff->value,
    ], vdaHeaders())
        ->assertCreated()
        ->assertJsonPath('data.sort_order', 2);
});

test('store on empty vibe sets sort_order to 0', function () {
    $user = vdaUser('fb-vda-store-first');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
    ], vdaHeaders())
        ->assertCreated()
        ->assertJsonPath('data.sort_order', 0);
});

test('store rejects foreign device', function () {
    $alice = vdaUser('fb-vda-store-foreign-alice');
    $bob = vdaUser('fb-vda-store-foreign-bob');

    $vibe = Vibe::factory()->create(['user_id' => $alice->id]);
    $bobDevice = vdaDeviceFor($bob);

    vdaAuth($alice);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [
        'device_id' => $bobDevice->id,
        'action_type' => ActionType::TurnOn->value,
    ], vdaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['device_id']);
});

test('store rejects invalid action_type', function () {
    $user = vdaUser('fb-vda-store-bad-action');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [
        'device_id' => $device->id,
        'action_type' => 'set_brightness',
    ], vdaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['action_type']);
});

test('store rejects delay_seconds over 3600', function () {
    $user = vdaUser('fb-vda-store-delay');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'delay_seconds' => 3601,
    ], vdaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['delay_seconds']);
});

test('store rejects missing required fields', function () {
    $user = vdaUser('fb-vda-store-missing');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [], vdaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['device_id', 'action_type']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Update
// ─────────────────────────────────────────────────────────────────────────────

test('owner can update own action', function () {
    $user = vdaUser('fb-vda-update');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    $action = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'delay_seconds' => 0,
    ]);

    vdaAuth($user);

    $this->patchJson("/api/vibes/{$vibe->id}/device-actions/{$action->id}", [
        'action_type' => ActionType::TurnOff->value,
        'delay_seconds' => 120,
    ], vdaHeaders())
        ->assertOk()
        ->assertJsonPath('data.action_type', ActionType::TurnOff->value)
        ->assertJsonPath('data.delay_seconds', 120);

    expect($action->fresh()->delay_seconds)->toBe(120);
});

test('update can switch to another owned device', function () {
    $user = vdaUser('fb-vda-update-device');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user, 'First');
    $otherDevice = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $device->provider_connection_id,
        'provider' => $device->provider,
        'name' => 'Second',
    ]);

    $action = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
    ]);

    vdaAuth($user);

    $this->patchJson("/api/vibes/{$vibe->id}/device-actions/{$action->id}", [
        'device_id' => $otherDevice->id,
    ], vdaHeaders())
        ->assertOk()
        ->assertJsonPath('data.device_id', $otherDevice->id);
});

test('update rejects foreign device', function () {
    $alice = vdaUser('fb-vda-update-foreign-alice');
    $bob = vdaUser('fb-vda-update-foreign-bob');

    $vibe = Vibe::factory()->create(['user_id' => $alice->id]);
    $aliceDevice = vdaDeviceFor($alice);
    $bobDevice = vdaDeviceFor($bob);

    $action = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $aliceDevice->id,
    ]);

    vdaAuth($alice);

    $this->patchJson("/api/vibes/{$vibe->id}/device-actions/{$action->id}", [
        'device_id' => $bobDevice->id,
    ], vdaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['device_id']);
});

test('update rejects action not belonging to vibe', function () {
    $user = vdaUser('fb-vda-update-wrong-vibe');
    $vibeA = Vibe::factory()->create(['user_id' => $user->id]);
    $vibeB = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    $action = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibeB->id,
        'device_id' => $device->id,
    ]);

    vdaAuth($user);

    $this->patchJson("/api/vibes/{$vibeA->id}/device-actions/{$action->id}", [
        'delay_seconds' => 10,
    ], vdaHeaders())
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Destroy
// ─────────────────────────────────────────────────────────────────────────────

test('owner can delete own action', function () {
    $user = vdaUser('fb-vda-delete');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    $action = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
    ]);

    vdaAuth($user);

    $this->deleteJson("/api/vibes/{$vibe->id}/device-actions/{$action->id}", [], vdaHeaders())
        ->assertNoContent();

    expect(VibeDeviceAction::find($action->id))->toBeNull();
});

test('delete rejects action not belonging to vibe', function () {
    $user = vdaUser('fb-vda-delete-wrong-vibe');
    $vibeA = Vibe::factory()->create(['user_id' => $user->id]);
    $vibeB = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    $action = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibeB->id,
        'device_id' => $device->id,
    ]);

    vdaAuth($user);

    $this->deleteJson("/api/vibes/{$vibeA->id}/device-actions/{$action->id}", [], vdaHeaders())
        ->assertNotFound();

    expect(VibeDeviceAction::find($action->id))->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Reorder
// ─────────────────────────────────────────────────────────────────────────────

test('owner can reorder actions', function () {
    $user = vdaUser('fb-vda-reorder');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    $a = VibeDeviceAction::factory()->create(['vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 0]);
    $b = VibeDeviceAction::factory()->create(['vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 1]);
    $c = VibeDeviceAction::factory()->create(['vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 2]);

    vdaAuth($user);

    $response = $this->postJson("/api/vibes/{$vibe->id}/device-actions/reorder", [
        'ordered_ids' => [$c->id, $a->id, $b->id],
    ], vdaHeaders())->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$c->id, $a->id, $b->id]);

    expect($c->fresh()->sort_order)->toBe(0)
        ->and($a->fresh()->sort_order)->toBe(1)
        ->and($b->fresh()->sort_order)->toBe(2);
});

test('reorder rejects missing action id', function () {
    $user = vdaUser('fb-vda-reorder-missing');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    $a = VibeDeviceAction::factory()->create(['vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 0]);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions/reorder", [
        'ordered_ids' => [$a->id, 999999],
    ], vdaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ordered_ids']);
});

test('reorder rejects action from another vibe', function () {
    $user = vdaUser('fb-vda-reorder-foreign');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $otherVibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    $mine = VibeDeviceAction::factory()->create(['vibe_id' => $vibe->id, 'device_id' => $device->id, 'sort_order' => 0]);
    $foreign = VibeDeviceAction::factory()->create(['vibe_id' => $otherVibe->id, 'device_id' => $device->id, 'sort_order' => 0]);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions/reorder", [
        'ordered_ids' => [$mine->id, $foreign->id],
    ], vdaHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ordered_ids']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Cross-user authorization
// ─────────────────────────────────────────────────────────────────────────────

test('cross-user cannot list another users vibe actions', function () {
    $alice = vdaUser('fb-vda-xuser-list-alice');
    $bob = vdaUser('fb-vda-xuser-list-bob');

    $bobVibe = Vibe::factory()->create(['user_id' => $bob->id]);

    vdaAuth($alice);

    $this->getJson("/api/vibes/{$bobVibe->id}/device-actions", vdaHeaders())
        ->assertForbidden();
});

test('cross-user cannot create action on another users vibe', function () {
    $alice = vdaUser('fb-vda-xuser-store-alice');
    $bob = vdaUser('fb-vda-xuser-store-bob');

    $bobVibe = Vibe::factory()->create(['user_id' => $bob->id]);
    $aliceDevice = vdaDeviceFor($alice);

    vdaAuth($alice);

    $this->postJson("/api/vibes/{$bobVibe->id}/device-actions", [
        'device_id' => $aliceDevice->id,
        'action_type' => ActionType::TurnOn->value,
    ], vdaHeaders())
        ->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Resource shape
// ─────────────────────────────────────────────────────────────────────────────

test('resource includes nested device name, status and fields', function () {
    $user = vdaUser('fb-vda-resource');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user, 'Bedroom Lamp');

    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'sort_order' => 0,
    ]);

    vdaAuth($user);

    $response = $this->getJson("/api/vibes/{$vibe->id}/device-actions", vdaHeaders())
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'vibe_id',
                    'device_id',
                    'action_type',
                    'parameters',
                    'sort_order',
                    'delay_seconds',
                    'created_at',
                    'updated_at',
                    'device' => [
                        'id',
                        'name',
                        'type',
                        'provider',
                        'status',
                        'provider_device_id',
                    ],
                ],
            ],
        ]);

    expect($response->json('data.0.device.name'))->toBe('Bedroom Lamp')
        ->and($response->json('data.0.device.status'))->toBe(DeviceStatus::Online->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// No execution / job / HA call
// ─────────────────────────────────────────────────────────────────────────────

test('store does not dispatch jobs or call Home Assistant', function () {
    Bus::fake();
    Http::preventStrayRequests();
    Http::fake();

    $user = vdaUser('fb-vda-no-exec');
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $device = vdaDeviceFor($user);

    vdaAuth($user);

    $this->postJson("/api/vibes/{$vibe->id}/device-actions", [
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
    ], vdaHeaders())->assertCreated();

    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});
