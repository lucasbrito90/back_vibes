<?php

declare(strict_types=1);

use App\Jobs\PushNotifications\PushNotificationJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\ConnectionStatus;
use App\SmartHome\DeviceStatus;
use App\SmartHome\ProviderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const SYNC_HA_BASE = 'https://ha.sync.test';

function syncJwt(User $user): UnencryptedToken
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

function syncAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(syncJwt($user)));
}

function syncHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function syncUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-sync-'.uniqid()]);
}

/**
 * Create a ProviderConnection for $user pointing to SYNC_HA_BASE.
 */
function syncConnection(User $user, string $token = 'sync-test-token'): ProviderConnection
{
    $conn = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'config' => ['base_url' => SYNC_HA_BASE],
    ]);
    $conn->setEncryptedCredentials(['access_token' => $token]);
    $conn->save();

    return $conn;
}

/**
 * A minimal HA /api/states response body with one light and one switch.
 */
function twoDeviceStates(): array
{
    return [
        [
            'entity_id' => 'light.living_room',
            'state' => 'on',
            'attributes' => ['friendly_name' => 'Living Room Light'],
            'last_changed' => '2026-06-20T10:00:00+00:00',
        ],
        [
            'entity_id' => 'switch.kitchen',
            'state' => 'off',
            'attributes' => ['friendly_name' => 'Kitchen Switch'],
        ],
    ];
}

function fakeHaStates(array $states, int $status = 200): void
{
    Http::fake([SYNC_HA_BASE.'/api/states' => Http::response($states, $status)]);
}

function syncUrl(ProviderConnection $connection): string
{
    return "/api/provider-connections/{$connection->id}/sync";
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot sync provider connection', function () {
    $conn = ProviderConnection::factory()->create();

    $this->postJson(syncUrl($conn))->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// Authorization
// ─────────────────────────────────────────────────────────────────────────────

test('foreign provider connection returns 403', function () {
    $alice = syncUser('fb-sync-authz-alice');
    $bob = syncUser('fb-sync-authz-bob');

    $bobConn = syncConnection($bob);

    syncAuth($alice);

    $this->postJson(syncUrl($bobConn), [], syncHeaders())->assertForbidden();
});

test('owner can sync own connection', function () {
    $user = syncUser('fb-sync-authz-owner');
    $conn = syncConnection($user);

    fakeHaStates(twoDeviceStates());
    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();
});

// ─────────────────────────────────────────────────────────────────────────────
// Sync — happy path
// ─────────────────────────────────────────────────────────────────────────────

test('sync creates devices from provider DTOs', function () {
    $user = syncUser('fb-sync-create');
    $conn = syncConnection($user);

    fakeHaStates(twoDeviceStates());
    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    expect(Device::where('provider_connection_id', $conn->id)->count())->toBe(2);

    $light = Device::where('provider_connection_id', $conn->id)
        ->where('provider_device_id', 'light.living_room')
        ->first();

    expect($light)->not->toBeNull()
        ->and($light->name)->toBe('Living Room Light')
        ->and($light->type)->toBe('light')
        ->and($light->status)->toBe(DeviceStatus::Online->value)
        ->and($light->user_id)->toBe($user->id)
        ->and($light->provider)->toBe(ProviderType::HomeAssistant->value);
});

test('sync updates existing devices instead of duplicating them', function () {
    $user = syncUser('fb-sync-update');
    $conn = syncConnection($user);

    Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'provider_device_id' => 'light.living_room',
        'name' => 'Old Name',
        'status' => DeviceStatus::Unknown->value,
    ]);

    fakeHaStates([[
        'entity_id' => 'light.living_room',
        'state' => 'on',
        'attributes' => ['friendly_name' => 'New Name'],
    ]]);

    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    expect(Device::where('provider_connection_id', $conn->id)->count())->toBe(1);

    $device = Device::where('provider_connection_id', $conn->id)->first();
    expect($device->name)->toBe('New Name')
        ->and($device->status)->toBe(DeviceStatus::Online->value);
});

test('repeated sync does not create duplicates', function () {
    $user = syncUser('fb-sync-dedup');
    $conn = syncConnection($user);

    fakeHaStates(twoDeviceStates());
    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    fakeHaStates(twoDeviceStates());

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    expect(Device::where('provider_connection_id', $conn->id)->count())->toBe(2);
});

test('absent devices are marked offline', function () {
    $user = syncUser('fb-sync-offline');
    $conn = syncConnection($user);

    $gone = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'provider_device_id' => 'light.gone_device',
        'status' => DeviceStatus::Online->value,
    ]);

    fakeHaStates(twoDeviceStates());
    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    expect($gone->fresh()->status)->toBe(DeviceStatus::Offline->value);
});

test('already-offline absent devices stay offline', function () {
    $user = syncUser('fb-sync-offline-stay');
    $conn = syncConnection($user);

    $alreadyOffline = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'provider_device_id' => 'light.already_offline',
        'status' => DeviceStatus::Offline->value,
    ]);

    fakeHaStates([]);
    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    expect($alreadyOffline->fresh()->status)->toBe(DeviceStatus::Offline->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// Sync — connection status updates
// ─────────────────────────────────────────────────────────────────────────────

test('successful sync marks connection as connected and sets last_tested_at', function () {
    $user = syncUser('fb-sync-connected');
    $conn = syncConnection($user);

    fakeHaStates([]);
    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    $fresh = $conn->fresh();
    expect($fresh->status)->toBe(ConnectionStatus::Connected->value)
        ->and($fresh->last_tested_at)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Sync — response shape
// ─────────────────────────────────────────────────────────────────────────────

test('response summary includes synced, created, updated, offline counts', function () {
    $user = syncUser('fb-sync-summary');
    $conn = syncConnection($user);

    Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'provider_device_id' => 'light.existing',
        'status' => DeviceStatus::Online->value,
    ]);

    Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'provider_device_id' => 'fan.absent',
        'status' => DeviceStatus::Online->value,
    ]);

    fakeHaStates([
        ['entity_id' => 'light.existing', 'state' => 'on', 'attributes' => []],
        ['entity_id' => 'light.brand_new', 'state' => 'off', 'attributes' => []],
    ]);

    syncAuth($user);

    $response = $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    expect($response->json('data.provider_connection_id'))->toBe($conn->id)
        ->and($response->json('data.synced'))->toBe(2)
        ->and($response->json('data.created'))->toBe(1)
        ->and($response->json('data.updated'))->toBe(1)
        ->and($response->json('data.offline'))->toBe(1)
        ->and($response->json('data.status'))->toBe(ConnectionStatus::Connected->value);
});

test('response does not contain access_token', function () {
    $user = syncUser('fb-sync-no-token');
    $conn = syncConnection($user, 'top-secret-llat');

    fakeHaStates([]);
    syncAuth($user);

    $response = $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    expect($response->content())->not->toContain('top-secret-llat')
        ->and($response->json())->not->toHaveKey('access_token')
        ->and($response->json())->not->toHaveKey('encrypted_credentials');
});

// ─────────────────────────────────────────────────────────────────────────────
// Sync — provider unreachable
// ─────────────────────────────────────────────────────────────────────────────

test('provider unreachable returns 502', function () {
    $user = syncUser('fb-sync-unreachable');
    $conn = syncConnection($user);

    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertStatus(502);
});

test('provider 401 response returns 502', function () {
    $user = syncUser('fb-sync-401');
    $conn = syncConnection($user);

    fakeHaStates([], 401);
    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertStatus(502);
});

test('provider 500 response returns 502', function () {
    $user = syncUser('fb-sync-500');
    $conn = syncConnection($user);

    fakeHaStates([], 500);
    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertStatus(502);
});

test('provider unreachable marks connection as unreachable', function () {
    $user = syncUser('fb-sync-ur-conn');
    $conn = syncConnection($user);

    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertStatus(502);

    expect($conn->fresh()->status)->toBe(ConnectionStatus::Unreachable->value);
});

test('provider unreachable notifies the owner via PushNotificationEvents', function () {
    Bus::fake();

    $user = syncUser('fb-sync-ur-notify');
    $conn = syncConnection($user);

    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertStatus(502);

    Bus::assertDispatched(
        PushNotificationJob::class,
        function (PushNotificationJob $job) use ($user, $conn) {
            return $job->userId === $user->id
                && $job->payload->data['type'] === 'smart_home_provider_unreachable'
                && $job->payload->data['provider_connection_id'] === (string) $conn->id;
        }
    );
});

test('provider unreachable marks all connection devices as unknown', function () {
    $user = syncUser('fb-sync-ur-devices');
    $conn = syncConnection($user);

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'status' => DeviceStatus::Online->value,
    ]);

    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertStatus(502);

    expect($device->fresh()->status)->toBe(DeviceStatus::Unknown->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// Sync — deduplication by provider_device_id
// ─────────────────────────────────────────────────────────────────────────────

test('provider_device_id uniqueness respected — same entity appears once', function () {
    $user = syncUser('fb-sync-uniq');
    $conn = syncConnection($user);

    fakeHaStates([
        ['entity_id' => 'light.living_room', 'state' => 'on', 'attributes' => []],
        ['entity_id' => 'light.living_room', 'state' => 'off', 'attributes' => []],
    ]);

    syncAuth($user);

    $this->postJson(syncUrl($conn), [], syncHeaders())->assertOk();

    expect(Device::where('provider_connection_id', $conn->id)
        ->where('provider_device_id', 'light.living_room')
        ->count())->toBe(1);
});

test('devices from different connections are isolated', function () {
    $alice = syncUser('fb-sync-iso-alice');
    $bob = syncUser('fb-sync-iso-bob');

    $aliceConn = syncConnection($alice);
    $bobConn = syncConnection($bob);

    fakeHaStates(twoDeviceStates());
    syncAuth($alice);

    $this->postJson(syncUrl($aliceConn), [], syncHeaders())->assertOk();

    expect(Device::where('provider_connection_id', $aliceConn->id)->count())->toBe(2)
        ->and(Device::where('provider_connection_id', $bobConn->id)->count())->toBe(0);
});
