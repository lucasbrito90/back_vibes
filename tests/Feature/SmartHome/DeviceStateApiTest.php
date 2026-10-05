<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\Adapters\FakeProviderAdapter;
use App\SmartHome\Adapters\HomeAssistantCanonicalMapper;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\ConnectionStatus;
use App\SmartHome\DeviceStateFreshness;
use App\SmartHome\DeviceStatus;
use App\SmartHome\ProviderType;
use App\SmartHome\Services\DeviceStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const DST_HA_BASE = 'https://ha.devstate.test';

function dstJwt(User $user): UnencryptedToken
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

function dstAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(dstJwt($user)));
}

function dstUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-dst-'.uniqid()]);
}

function dstHaConnection(User $user): ProviderConnection
{
    $conn = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => ProviderType::HomeAssistant->value,
        'config' => ['base_url' => DST_HA_BASE],
    ]);
    $conn->setEncryptedCredentials(['access_token' => 'dst-token']);
    $conn->save();

    return $conn;
}

function dstGhConnection(User $user): ProviderConnection
{
    return ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Google Home '.uniqid(),
        'provider' => ProviderType::GoogleHome->value,
        'config' => [],
        'encrypted_credentials' => null,
    ]);
}

/**
 * A dimmable HA light device owned by $user on $connection.
 */
function dstLight(User $user, ProviderConnection $connection, string $entityId = 'light.living_room'): Device
{
    return Device::factory()->dimmableLight()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => $entityId,
        'status' => DeviceStatus::Online->value,
        'state' => null,
        'state_read_at' => null,
    ]);
}

/**
 * HA /api/states/<entity> body: on, brightness 166/255 → canonical 65 percent.
 */
function dstHaLightBody(string $entityId = 'light.living_room', string $state = 'on', int $brightness = 166): array
{
    return [
        'entity_id' => $entityId,
        'state' => $state,
        'attributes' => [
            'friendly_name' => 'Living Room',
            'brightness' => $brightness,
            'supported_features' => 1,
        ],
        'last_changed' => '2026-10-05T12:00:00+00:00',
    ];
}

function dstFakeAdapter(): FakeProviderAdapter
{
    $adapter = new FakeProviderAdapter;

    config(['smart_home.adapters.fake' => FakeProviderAdapter::class]);
    app()->singleton(FakeProviderAdapter::class, fn () => $adapter);

    return $adapter;
}

// ─────────────────────────────────────────────────────────────────────────────
// Schema — the new columns, and the timestamps they must NOT collapse into
// ─────────────────────────────────────────────────────────────────────────────

test('devices table has state and state_read_at columns', function () {
    $columns = Schema::getColumnListing('devices');

    expect($columns)->toContain('state')->toContain('state_read_at');
});

test('state_read_at is a distinct column from last_seen_at', function () {
    $device = Device::factory()->create([
        'last_seen_at' => now()->subDays(3),
        'state_read_at' => now(),
    ]);

    $fresh = $device->fresh();

    expect($fresh->last_seen_at->toDateString())->not->toBe($fresh->state_read_at->toDateString());
});

test('state and state_read_at default to null for a new device', function () {
    $device = Device::factory()->create();

    expect($device->fresh()->state)->toBeNull()
        ->and($device->fresh()->state_read_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Happy path — readStatus() reaches the API as canonical state
// ─────────────────────────────────────────────────────────────────────────────

test('device detail returns canonical state values and read_at from a HA read', function () {
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $response = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}");

    $response->assertOk()
        ->assertJsonPath('data.state.values.power', true)
        ->assertJsonPath('data.state.values.brightness', 65)
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Fresh->value);

    expect($response->json('data.state.read_at'))->not->toBeNull();
});

test('a canonical state read is persisted to the device row', function () {
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $this->withHeaders(['Authorization' => 'Bearer tok'])->getJson("/api/devices/{$device->id}");

    $fresh = $device->fresh();

    expect($fresh->state)->toBe(['power' => true, 'brightness' => 65])
        ->and($fresh->state_read_at)->not->toBeNull();
});

test('brightness is reported on the canonical percent scale, not the provider scale', function () {
    // 255/255 is full brightness on Home Assistant's scale; the contract says 100.
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody(brightness: 255))]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.values.brightness', 100);
});

test('power false is reported as false, not as absent', function () {
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody(state: 'off'))]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.values.power', false)
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Fresh->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// Unknown — a failed read invents nothing
// ─────────────────────────────────────────────────────────────────────────────

test('a device whose state was never read reports unknown with no values', function () {
    $user = dstUser();
    dstAuth($user);
    // google_home: no server-side adapter, so nothing is read and nothing invented.
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => dstGhConnection($user)->id,
        'provider' => ProviderType::GoogleHome->value,
        'state' => null,
        'state_read_at' => null,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Unknown->value)
        ->assertJsonPath('data.state.values', [])
        ->assertJsonPath('data.state.read_at', null);
});

test('a transport failure yields unknown rather than an exception or an invented value', function () {
    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Unknown->value)
        ->assertJsonPath('data.state.values', []);

    expect($device->fresh()->state)->toBeNull();
});

test('a provider HTTP error yields unknown and does not stamp a read time', function () {
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response([], 500)]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Unknown->value);

    expect($device->fresh()->state_read_at)->toBeNull();
});

test('a read that reports no canonical value presents as unknown', function () {
    // HA 'unavailable' maps to no canonical power value at all.
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response([
        'entity_id' => 'light.living_room',
        'state' => 'unavailable',
        'attributes' => ['friendly_name' => 'Living Room'],
    ])]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Unknown->value)
        ->assertJsonPath('data.state.values', []);
});

// ─────────────────────────────────────────────────────────────────────────────
// Stale — the freshness policy
// ─────────────────────────────────────────────────────────────────────────────

test('a previously known value past the TTL is reported as stale, not fresh', function () {
    config(['smart_home.device_state.ttl_seconds' => 60]);
    // The read fails, so the stored value cannot be refreshed — it must be
    // presented as stale rather than discarded or asserted as current.
    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));
    $device->update([
        'state' => ['power' => true, 'brightness' => 40],
        'state_read_at' => now()->subMinutes(10),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Stale->value)
        ->assertJsonPath('data.state.values.power', true)
        ->assertJsonPath('data.state.values.brightness', 40);
});

test('a stored value within the TTL is served without contacting the provider', function () {
    config(['smart_home.device_state.ttl_seconds' => 300]);
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));
    $device->update([
        'state' => ['power' => false],
        'state_read_at' => now()->subSeconds(5),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.values.power', false)
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Fresh->value);

    Http::assertNothingSent();
});

test('a stale stored value is refreshed from the provider when the read succeeds', function () {
    config(['smart_home.device_state.ttl_seconds' => 60]);
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));
    $device->update([
        'state' => ['power' => false],
        'state_read_at' => now()->subMinutes(10),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.values.power', true)
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Fresh->value);
});

test('the device list reports stored state without contacting any provider', function () {
    config(['smart_home.device_state.ttl_seconds' => 60]);
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);

    $user = dstUser();
    dstAuth($user);
    $connection = dstHaConnection($user);

    foreach (['light.a', 'light.b', 'light.c'] as $entityId) {
        dstLight($user, $connection, $entityId)->update([
            'state' => ['power' => true],
            // Stale on purpose: the list must still not fan out to the provider.
            'state_read_at' => now()->subHours(2),
        ]);
    }

    $response = $this->withHeaders(['Authorization' => 'Bearer tok'])->getJson('/api/devices');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);

    foreach ($response->json('data') as $device) {
        expect($device['state']['freshness'])->toBe(DeviceStateFreshness::Stale->value);
    }

    Http::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────────────────
// Device-side providers (ADR-036 §1-3) — never polled, never faked
// ─────────────────────────────────────────────────────────────────────────────

test('a device-side provider device is never polled server-side', function () {
    Http::fake();

    $user = dstUser();
    dstAuth($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => dstGhConnection($user)->id,
        'provider' => ProviderType::GoogleHome->value,
        'state' => null,
        'state_read_at' => null,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Unknown->value);

    Http::assertNothingSent();
});

test('readFromProvider called directly on a device-side provider returns null without polling', function () {
    Http::fake();

    $user = dstUser();
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => dstGhConnection($user)->id,
        'provider' => ProviderType::GoogleHome->value,
        'state' => null,
        'state_read_at' => null,
    ]);

    $result = app(DeviceStateService::class)->readFromProvider($device);

    expect($result)->toBeNull();

    Http::assertNothingSent();

    $fresh = $device->fresh();
    expect($fresh->state)->toBeNull()
        ->and($fresh->state_read_at)->toBeNull();
});

test('a device-side provider reports its stored client-observed state with freshness', function () {
    config(['smart_home.device_state.ttl_seconds' => 300]);

    $user = dstUser();
    dstAuth($user);
    $device = Device::factory()->dimmableLight()->create([
        'user_id' => $user->id,
        'provider_connection_id' => dstGhConnection($user)->id,
        'provider' => ProviderType::GoogleHome->value,
        'state' => ['power' => true, 'brightness' => 80],
        'state_read_at' => now()->subSeconds(10),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.values.power', true)
        ->assertJsonPath('data.state.values.brightness', 80)
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Fresh->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// Semantic separation — connection status, connectivity and functional state
// ─────────────────────────────────────────────────────────────────────────────

test('a connected connection does not imply the device is powered on', function () {
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody(state: 'off'))]);

    $user = dstUser();
    dstAuth($user);
    $connection = dstHaConnection($user);
    $connection->update(['status' => ConnectionStatus::Connected->value]);
    $device = dstLight($user, $connection);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.state.values.power', false);

    expect($connection->fresh()->status)->toBe(ConnectionStatus::Connected->value);
});

test('an offline device can still carry a last-known functional state', function () {
    config(['smart_home.device_state.ttl_seconds' => 300]);
    Http::fake();

    $user = dstUser();
    dstAuth($user);
    $device = Device::factory()->dimmableLight()->create([
        'user_id' => $user->id,
        'provider_connection_id' => dstGhConnection($user)->id,
        'provider' => ProviderType::GoogleHome->value,
        'status' => DeviceStatus::Offline->value,
        'state' => ['power' => true],
        'state_read_at' => now()->subSeconds(5),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertJsonPath('data.status', DeviceStatus::Offline->value)
        ->assertJsonPath('data.state.values.power', true);
});

test('state does not repeat connectivity', function () {
    $user = dstUser();
    dstAuth($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => dstGhConnection($user)->id,
        'provider' => ProviderType::GoogleHome->value,
        'status' => DeviceStatus::Online->value,
    ]);

    $state = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.state');

    expect($state)->not->toHaveKey('status')
        ->and($state)->not->toHaveKey('connectivity')
        ->and(array_keys($state))->toEqualCanonicalizing(['values', 'read_at', 'freshness']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Boundary — provider internals must not cross into the state contract
// ─────────────────────────────────────────────────────────────────────────────

test('the state contract exposes no provider identifiers or raw attributes', function () {
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $state = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.state');

    $encoded = json_encode($state);

    expect($state)->not->toHaveKey('provider_device_id')
        ->and($state)->not->toHaveKey('entity_id')
        ->and($state)->not->toHaveKey('attributes')
        ->and($state)->not->toHaveKey('raw_state')
        ->and($state)->not->toHaveKey('metadata')
        ->and($encoded)->not->toContain('light.living_room')
        ->and($encoded)->not->toContain('supported_features')
        ->and($encoded)->not->toContain('friendly_name');
});

test('state values are keyed only by canonical capability ids', function () {
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $values = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.state.values');

    foreach (array_keys($values) as $key) {
        expect(CapabilityId::tryFrom($key))->not->toBeNull();
    }
});

test('the state contract never carries a credential', function () {
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);

    $user = dstUser();
    dstAuth($user);
    $device = dstLight($user, dstHaConnection($user));

    $body = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->getContent();

    expect($body)->not->toContain('dst-token');
});

// ─────────────────────────────────────────────────────────────────────────────
// Provider normalisation — one canonical shape, whatever the origin
// ─────────────────────────────────────────────────────────────────────────────

test('two providers reporting the same functional state produce the same canonical shape', function () {
    $user = dstUser();

    // Home Assistant: on, brightness 166 on its native 0-255 scale.
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);
    $haDevice = dstLight($user, dstHaConnection($user));
    $haState = app(DeviceStateService::class)->readFromProvider($haDevice);

    // Fake provider: on, brightness already canonical at 65 percent.
    $fake = dstFakeAdapter();
    $fakeConnection = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'Fake '.uniqid(),
        'provider' => FakeProviderAdapter::PROVIDER_SLUG,
    ]);
    $fakeDevice = Device::factory()->dimmableLight()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $fakeConnection->id,
        'provider' => FakeProviderAdapter::PROVIDER_SLUG,
        'provider_device_id' => 'fake.light.living',
    ]);

    $fakeResult = $fake->readStatus($fakeConnection, 'fake.light.living');

    expect($haState)->not->toBeNull()
        ->and($haState->state->values)->toBe(['power' => true, 'brightness' => 65])
        ->and($fakeResult->state)->not->toBeNull()
        ->and($fakeResult->state->values)->toBe($haState->state->values);

    unset($fakeDevice);
});

// ─────────────────────────────────────────────────────────────────────────────
// Authorization — state is not a side door into another user's devices
// ─────────────────────────────────────────────────────────────────────────────

test('device detail requires authentication', function () {
    $device = Device::factory()->create();

    $this->getJson("/api/devices/{$device->id}")->assertUnauthorized();
});

test('a user cannot read another user_s device state', function () {
    Http::fake([DST_HA_BASE.'/api/states/*' => Http::response(dstHaLightBody())]);

    $alice = dstUser('fb-dst-alice');
    $bob = dstUser('fb-dst-bob');
    dstAuth($bob);

    $aliceDevice = dstLight($alice, dstHaConnection($alice));

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$aliceDevice->id}")
        ->assertForbidden();

    Http::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────────────────
// Stored-state resilience — a value the contract can no longer express
// ─────────────────────────────────────────────────────────────────────────────

test('a stored value outside the device_s declared capabilities degrades to unknown', function () {
    config(['smart_home.device_state.ttl_seconds' => 300]);
    Http::fake();

    $user = dstUser();
    dstAuth($user);
    // A switch declares power only; a stored brightness can no longer be
    // expressed, and the honest answer is unknown rather than a 500.
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => dstGhConnection($user)->id,
        'provider' => ProviderType::GoogleHome->value,
        'type' => 'switch',
        'capabilities' => ['can_turn_on' => [], 'can_turn_off' => []],
        'state' => ['power' => true, 'brightness' => 50],
        'state_read_at' => now()->subSeconds(5),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Unknown->value);
});

test('stored state is readable when capabilities are in the canonical envelope shape', function () {
    config(['smart_home.device_state.ttl_seconds' => 300]);
    Http::fake();

    $user = dstUser();
    dstAuth($user);

    // What a real Home-Assistant-synced row holds: the canonical envelope AND
    // the legacy can_* keys beside it (ADR-037 §8 dual-shape window). The
    // factory fixtures are legacy-only, so without this the production shape
    // would go untested on the read-back path.
    $mapper = new HomeAssistantCanonicalMapper;
    $capabilities = $mapper->toStoredPayload(
        $mapper->capabilitiesFor('light', ['supported_features' => 1], true),
    );

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => dstGhConnection($user)->id,
        'provider' => ProviderType::GoogleHome->value,
        'capabilities' => $capabilities,
        'state' => ['power' => true, 'brightness' => 42],
        'state_read_at' => now()->subSeconds(5),
    ]);

    expect($capabilities)->toHaveKey('contract_version');

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.state.values.power', true)
        ->assertJsonPath('data.state.values.brightness', 42)
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Fresh->value);
});

test('a device with null capabilities reports unknown rather than failing', function () {
    config(['smart_home.device_state.ttl_seconds' => 300]);
    Http::fake();

    $user = dstUser();
    dstAuth($user);
    $device = Device::factory()->withoutCapabilities()->create([
        'user_id' => $user->id,
        'provider_connection_id' => dstGhConnection($user)->id,
        'provider' => ProviderType::GoogleHome->value,
        'state' => ['power' => true],
        'state_read_at' => now()->subSeconds(5),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Unknown->value);
});
