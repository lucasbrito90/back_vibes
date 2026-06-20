<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\DeviceStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function devJwt(User $user): UnencryptedToken
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

function devAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(devJwt($user)));
}

function devHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function devUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-dev-'.uniqid()]);
}

function connectionFor(User $user): ProviderConnection
{
    return ProviderConnection::factory()->create(['user_id' => $user->id]);
}

function validDevicePayload(ProviderConnection $connection): array
{
    return [
        'provider_connection_id' => $connection->id,
        'name' => 'Living Room Light',
        'type' => 'light',
        'provider_device_id' => 'light.living_room',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot list devices', function () {
    $this->getJson('/api/devices')->assertUnauthorized();
});

test('unauthenticated cannot create device', function () {
    $conn = ProviderConnection::factory()->create();
    $this->postJson('/api/devices', validDevicePayload($conn))->assertUnauthorized();
});

test('unauthenticated cannot show device', function () {
    $device = Device::factory()->create();
    $this->getJson("/api/devices/{$device->id}")->assertUnauthorized();
});

test('unauthenticated cannot update device', function () {
    $device = Device::factory()->create();
    $this->patchJson("/api/devices/{$device->id}", ['name' => 'x'])->assertUnauthorized();
});

test('unauthenticated cannot delete device', function () {
    $device = Device::factory()->create();
    $this->deleteJson("/api/devices/{$device->id}")->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// Index
// ─────────────────────────────────────────────────────────────────────────────

test('index returns only own devices', function () {
    $alice = devUser('fb-dev-idx-alice');
    $bob = devUser('fb-dev-idx-bob');

    $aliceConn = connectionFor($alice);
    $bobConn = connectionFor($bob);

    $mine = Device::factory()->create([
        'user_id' => $alice->id,
        'provider_connection_id' => $aliceConn->id,
        'provider' => $aliceConn->provider,
        'name' => 'Alice Light',
    ]);

    Device::factory()->create([
        'user_id' => $bob->id,
        'provider_connection_id' => $bobConn->id,
        'provider' => $bobConn->provider,
        'name' => 'Bob Speaker',
    ]);

    devAuth($alice);

    $response = $this->getJson('/api/devices', devHeaders())->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$mine->id])
        ->and($response->json('data.0.name'))->toBe('Alice Light');
});

test('index returns empty array when user has no devices', function () {
    $user = devUser('fb-dev-idx-empty');

    devAuth($user);

    $this->getJson('/api/devices', devHeaders())
        ->assertOk()
        ->assertJsonPath('data', []);
});

// ─────────────────────────────────────────────────────────────────────────────
// Show
// ─────────────────────────────────────────────────────────────────────────────

test('user can show own device', function () {
    $user = devUser('fb-dev-show-own');
    $conn = connectionFor($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'name' => 'My Device',
    ]);

    devAuth($user);

    $this->getJson("/api/devices/{$device->id}", devHeaders())
        ->assertOk()
        ->assertJsonPath('data.id', $device->id)
        ->assertJsonPath('data.name', 'My Device');
});

test('user cannot show another users device', function () {
    $alice = devUser('fb-dev-show-alice');
    $bob = devUser('fb-dev-show-bob');

    $bobConn = connectionFor($bob);
    $bobDevice = Device::factory()->create([
        'user_id' => $bob->id,
        'provider_connection_id' => $bobConn->id,
        'provider' => $bobConn->provider,
    ]);

    devAuth($alice);

    $this->getJson("/api/devices/{$bobDevice->id}", devHeaders())->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Store
// ─────────────────────────────────────────────────────────────────────────────

test('user can create device', function () {
    $user = devUser('fb-dev-store-ok');
    $conn = connectionFor($user);

    devAuth($user);

    $response = $this->postJson('/api/devices', validDevicePayload($conn), devHeaders())
        ->assertCreated();

    expect($response->json('data.name'))->toBe('Living Room Light')
        ->and($response->json('data.type'))->toBe('light')
        ->and($response->json('data.provider_device_id'))->toBe('light.living_room');
});

test('store derives provider from provider_connection', function () {
    $user = devUser('fb-dev-store-prov');
    $conn = connectionFor($user);

    devAuth($user);

    $response = $this->postJson('/api/devices', validDevicePayload($conn), devHeaders())
        ->assertCreated();

    expect($response->json('data.provider'))->toBe($conn->provider);
});

test('store derives user_id from auth user', function () {
    $user = devUser('fb-dev-store-uid');
    $conn = connectionFor($user);

    devAuth($user);

    $response = $this->postJson('/api/devices', validDevicePayload($conn), devHeaders())
        ->assertCreated();

    $device = Device::findOrFail($response->json('data.id'));
    expect($device->user_id)->toBe($user->id);
});

test('store ignores injected user_id', function () {
    $alice = devUser('fb-dev-store-uid-alice');
    $bob = devUser('fb-dev-store-uid-bob');
    $conn = connectionFor($alice);

    devAuth($alice);

    $response = $this->postJson('/api/devices', [
        ...validDevicePayload($conn),
        'user_id' => $bob->id,
    ], devHeaders())->assertCreated();

    $device = Device::findOrFail($response->json('data.id'));
    expect($device->user_id)->toBe($alice->id);
});

test('store ignores injected provider', function () {
    $user = devUser('fb-dev-store-prov-inject');
    $conn = connectionFor($user);

    devAuth($user);

    $response = $this->postJson('/api/devices', [
        ...validDevicePayload($conn),
        'provider' => 'alexa',
    ], devHeaders())->assertCreated();

    $device = Device::findOrFail($response->json('data.id'));
    expect($device->provider)->toBe($conn->provider);
});

test('store sets status to unknown by default', function () {
    $user = devUser('fb-dev-store-status');
    $conn = connectionFor($user);

    devAuth($user);

    $response = $this->postJson('/api/devices', validDevicePayload($conn), devHeaders())
        ->assertCreated();

    expect($response->json('data.status'))->toBe(DeviceStatus::Unknown->value);
});

test('store rejects prohibited status field', function () {
    $user = devUser('fb-dev-store-prohib-status');
    $conn = connectionFor($user);

    devAuth($user);

    $this->postJson('/api/devices', [
        ...validDevicePayload($conn),
        'status' => DeviceStatus::Online->value,
    ], devHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('store rejects prohibited last_seen_at field', function () {
    $user = devUser('fb-dev-store-prohib-lsa');
    $conn = connectionFor($user);

    devAuth($user);

    $this->postJson('/api/devices', [
        ...validDevicePayload($conn),
        'last_seen_at' => now()->toISOString(),
    ], devHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['last_seen_at']);
});

test('store rejects foreign provider_connection_id', function () {
    $alice = devUser('fb-dev-store-foreign-alice');
    $bob = devUser('fb-dev-store-foreign-bob');

    $bobConn = connectionFor($bob);

    devAuth($alice);

    $this->postJson('/api/devices', [
        ...validDevicePayload($bobConn),
    ], devHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['provider_connection_id']);
});

test('store rejects missing required fields', function () {
    $user = devUser('fb-dev-store-missing');

    devAuth($user);

    $this->postJson('/api/devices', [], devHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['provider_connection_id', 'name', 'provider_device_id']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Update
// ─────────────────────────────────────────────────────────────────────────────

test('user can update own device name', function () {
    $user = devUser('fb-dev-upd-name');
    $conn = connectionFor($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'name' => 'Before',
    ]);

    devAuth($user);

    $this->patchJson("/api/devices/{$device->id}", ['name' => 'After'], devHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'After');

    expect($device->fresh()->name)->toBe('After');
});

test('user cannot update another users device', function () {
    $alice = devUser('fb-dev-upd-xuser-alice');
    $bob = devUser('fb-dev-upd-xuser-bob');

    $bobConn = connectionFor($bob);
    $bobDevice = Device::factory()->create([
        'user_id' => $bob->id,
        'provider_connection_id' => $bobConn->id,
        'provider' => $bobConn->provider,
        'name' => 'Original',
    ]);

    devAuth($alice);

    $this->patchJson("/api/devices/{$bobDevice->id}", ['name' => 'Stolen'], devHeaders())
        ->assertForbidden();

    expect($bobDevice->fresh()->name)->toBe('Original');
});

test('update rejects prohibited status field', function () {
    $user = devUser('fb-dev-upd-status');
    $conn = connectionFor($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
    ]);

    devAuth($user);

    $this->patchJson("/api/devices/{$device->id}", [
        'status' => DeviceStatus::Online->value,
    ], devHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('update rejects foreign provider_connection_id', function () {
    $alice = devUser('fb-dev-upd-foreign-alice');
    $bob = devUser('fb-dev-upd-foreign-bob');

    $aliceConn = connectionFor($alice);
    $bobConn = connectionFor($bob);

    $device = Device::factory()->create([
        'user_id' => $alice->id,
        'provider_connection_id' => $aliceConn->id,
        'provider' => $aliceConn->provider,
    ]);

    devAuth($alice);

    $this->patchJson("/api/devices/{$device->id}", [
        'provider_connection_id' => $bobConn->id,
    ], devHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['provider_connection_id']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Destroy
// ─────────────────────────────────────────────────────────────────────────────

test('user can delete own device', function () {
    $user = devUser('fb-dev-del-own');
    $conn = connectionFor($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
    ]);

    devAuth($user);

    $this->deleteJson("/api/devices/{$device->id}", [], devHeaders())
        ->assertNoContent();

    expect(Device::find($device->id))->toBeNull();
});

test('user cannot delete another users device', function () {
    $alice = devUser('fb-dev-del-alice');
    $bob = devUser('fb-dev-del-bob');

    $bobConn = connectionFor($bob);
    $bobDevice = Device::factory()->create([
        'user_id' => $bob->id,
        'provider_connection_id' => $bobConn->id,
        'provider' => $bobConn->provider,
    ]);

    devAuth($alice);

    $this->deleteJson("/api/devices/{$bobDevice->id}", [], devHeaders())
        ->assertForbidden();

    expect(Device::find($bobDevice->id))->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Resource shape
// ─────────────────────────────────────────────────────────────────────────────

test('DeviceResource returns all expected fields', function () {
    $user = devUser('fb-dev-res-shape');
    $conn = connectionFor($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
    ]);

    devAuth($user);

    $this->getJson("/api/devices/{$device->id}", devHeaders())
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'provider_connection_id',
                'name',
                'type',
                'provider',
                'provider_device_id',
                'status',
                'last_seen_at',
                'metadata',
                'created_at',
                'updated_at',
            ],
        ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Unique constraint
// ─────────────────────────────────────────────────────────────────────────────

test('same provider_connection_id and provider_device_id fails with unique constraint', function () {
    $user = devUser('fb-dev-uniq-dup');
    $conn = connectionFor($user);

    Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'provider_device_id' => 'light.living_room',
    ]);

    devAuth($user);

    $this->withoutExceptionHandling();

    $this->postJson('/api/devices', [
        'provider_connection_id' => $conn->id,
        'name' => 'Duplicate',
        'provider_device_id' => 'light.living_room',
    ], devHeaders());
})->throws(QueryException::class);

test('same provider_device_id on different provider_connection_id is allowed', function () {
    // One user can only have one HA connection (DB unique on user_id+provider).
    // Use two different users, each with their own connection and the same entity_id.
    $alice = devUser('fb-dev-uniq-diff-alice');
    $bob = devUser('fb-dev-uniq-diff-bob');

    $aliceConn = connectionFor($alice);

    $bobConn = connectionFor($bob);

    Device::factory()->create([
        'user_id' => $alice->id,
        'provider_connection_id' => $aliceConn->id,
        'provider' => $aliceConn->provider,
        'provider_device_id' => 'light.living_room',
    ]);

    devAuth($bob);

    $this->postJson('/api/devices', [
        'provider_connection_id' => $bobConn->id,
        'name' => 'Same Entity ID Different Owner',
        'provider_device_id' => 'light.living_room',
    ], devHeaders())->assertCreated();
});
