<?php

declare(strict_types=1);

use App\Http\Resources\DeviceDetailResource;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\Adapters\FakeProviderAdapter;
use App\SmartHome\Adapters\HomeAssistantCanonicalMapper;
use App\SmartHome\Canonical\CanonicalCapabilitiesDocument;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\ContractVersion;
use App\SmartHome\Canonical\EnumConstraint;
use App\SmartHome\Canonical\LegacyCapabilitiesReader;
use App\SmartHome\DeviceStateFreshness;
use App\SmartHome\DeviceStatus;
use App\SmartHome\ProviderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const BFF_HA_BASE = 'https://ha.bff.test';

function bffJwt(User $user): UnencryptedToken
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

function bffAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(bffJwt($user)));
}

function bffUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-bff-'.uniqid()]);
}

function bffHaConnection(User $user): ProviderConnection
{
    $conn = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => ProviderType::HomeAssistant->value,
        'config' => ['base_url' => BFF_HA_BASE],
    ]);
    $conn->setEncryptedCredentials(['access_token' => 'bff-token']);
    $conn->save();

    return $conn;
}

function bffGhConnection(User $user): ProviderConnection
{
    return ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'Google Home '.uniqid(),
        'provider' => ProviderType::GoogleHome->value,
        'config' => [],
        'encrypted_credentials' => null,
    ]);
}

function bffHaLightBody(string $entityId = 'light.living_room', string $state = 'on', int $brightness = 166): array
{
    return [
        'entity_id' => $entityId,
        'state' => $state,
        'attributes' => [
            'friendly_name' => 'Living Room Light',
            'brightness' => $brightness,
            'supported_features' => 1,
        ],
        'last_changed' => '2026-10-05T12:00:00+00:00',
    ];
}

/** Returns a dimmable HA light device with canonical capability payload. */
function bffDimmableLight(User $user, ProviderConnection $connection, string $entityId = 'light.living_room'): Device
{
    $mapper = new HomeAssistantCanonicalMapper;
    $capabilities = $mapper->toStoredPayload(
        $mapper->capabilitiesFor('light', ['supported_features' => 1], true),
    );

    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => $entityId,
        'type' => 'lighting',
        'status' => DeviceStatus::Online->value,
        'capabilities' => $capabilities,
        'state' => null,
        'state_read_at' => null,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// BFF contract structure
// ─────────────────────────────────────────────────────────────────────────────

test('device detail BFF has exactly the expected top-level keys', function () {
    $user = bffUser('fb-bff-structure');
    $conn = bffGhConnection($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
    ]);

    bffAuth($user);

    $response = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk();

    expect(array_keys($response->json('data')))->toEqualCanonicalizing([
        'id',
        'name',
        'type',
        'connectivity',
        'capabilities',
        'state',
    ]);
});

test('device detail BFF state has exactly values, read_at, and freshness', function () {
    $user = bffUser('fb-bff-state-keys');
    $conn = bffGhConnection($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
    ]);

    bffAuth($user);

    $state = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.state');

    expect(array_keys($state))->toEqualCanonicalizing(['values', 'read_at', 'freshness']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Light — power + brightness capabilities
// ─────────────────────────────────────────────────────────────────────────────

test('dimmable light detail exposes power and brightness in canonical CSDM format', function () {
    Http::fake([BFF_HA_BASE.'/api/states/*' => Http::response(bffHaLightBody())]);

    $user = bffUser('fb-bff-light-caps');
    $conn = bffHaConnection($user);
    $device = bffDimmableLight($user, $conn);

    bffAuth($user);

    $response = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk();

    $caps = $response->json('data.capabilities');

    expect($caps)->toBeArray()
        ->toHaveKey('power')
        ->toHaveKey('brightness');

    // power capability
    expect($caps['power']['id'])->toBe('power')
        ->and($caps['power']['access'])->toBe('read_write')
        ->and($caps['power']['operations'])->toContain('on')
        ->and($caps['power']['operations'])->toContain('off')
        ->and($caps['power']['operations'])->toContain('toggle')
        ->and($caps['power']['constraints']['type'])->toBe('boolean');

    // brightness capability
    expect($caps['brightness']['id'])->toBe('brightness')
        ->and($caps['brightness']['access'])->toBe('read_write')
        ->and($caps['brightness']['operations'])->toBe(['set'])
        ->and($caps['brightness']['constraints']['type'])->toBe('number')
        ->and($caps['brightness']['constraints']['min'])->toEqual(0)
        ->and($caps['brightness']['constraints']['max'])->toEqual(100)
        ->and($caps['brightness']['constraints']['step'])->toEqual(1)
        ->and($caps['brightness']['constraints']['unit'])->toBe('percent');
});

test('device detail exposes state values for a dimmable light read from HA', function () {
    Http::fake([BFF_HA_BASE.'/api/states/*' => Http::response(bffHaLightBody())]);

    $user = bffUser('fb-bff-light-state');
    $conn = bffHaConnection($user);
    $device = bffDimmableLight($user, $conn);

    bffAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.state.values.power', true)
        ->assertJsonPath('data.state.values.brightness', 65)
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Fresh->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// Read-only capability
// ─────────────────────────────────────────────────────────────────────────────

test('read-only current_temperature capability has access read and empty operations', function () {
    $user = bffUser('fb-bff-readonly-cap');
    $conn = bffGhConnection($user);

    $mapper = new HomeAssistantCanonicalMapper;
    // A thermostat has current_temperature (read-only) among its capabilities.
    $capabilities = $mapper->toStoredPayload(
        $mapper->capabilitiesFor('climate', ['supported_features' => 3], true),
    );

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'capabilities' => $capabilities,
        'state' => null,
        'state_read_at' => null,
    ]);

    bffAuth($user);

    $caps = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.capabilities');

    if (isset($caps['current_temperature'])) {
        expect($caps['current_temperature']['access'])->toBe('read')
            ->and($caps['current_temperature']['operations'])->toBe([]);
    }

    // power is never read-only
    if (isset($caps['power'])) {
        expect($caps['power']['access'])->not->toBe('read');
    }
});

test('energy capability is read-only with no operations', function () {
    $user = bffUser('fb-bff-energy-readonly');
    $conn = bffGhConnection($user);

    // Build canonical capabilities manually with energy (read-only)
    $mapper = new HomeAssistantCanonicalMapper;
    $capabilities = $mapper->toStoredPayload(
        $mapper->capabilitiesFor('switch', ['supported_features' => 0, 'device_class' => 'outlet'], true),
    );

    // If energy is not in the fixture, construct minimal stored canonical doc directly
    $doc = (new LegacyCapabilitiesReader)->read($capabilities);

    if ($doc === null || ! isset($doc->capabilities['energy'])) {
        // Build a stored canonical doc that includes energy explicitly
        $energyCap = Capability::fromCatalog(CapabilityId::Energy);
        $powerCap = Capability::fromCatalog(CapabilityId::Power);
        $storedCapabilities = (new CanonicalCapabilitiesDocument(
            ContractVersion::CURRENT,
            ['power' => $powerCap, 'energy' => $energyCap],
        ))->toArray();

        $device = Device::factory()->create([
            'user_id' => $user->id,
            'provider_connection_id' => $conn->id,
            'provider' => $conn->provider,
            'capabilities' => $storedCapabilities,
            'state' => null,
            'state_read_at' => null,
        ]);
    } else {
        $device = Device::factory()->create([
            'user_id' => $user->id,
            'provider_connection_id' => $conn->id,
            'provider' => $conn->provider,
            'capabilities' => $capabilities,
            'state' => null,
            'state_read_at' => null,
        ]);
    }

    bffAuth($user);

    $caps = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.capabilities');

    expect($caps)->toHaveKey('energy')
        ->and($caps['energy']['access'])->toBe('read')
        ->and($caps['energy']['operations'])->toBe([])
        ->and($caps['energy']['constraints']['type'])->toBe('number')
        ->and($caps['energy']['constraints']['unit'])->toBe('kWh');
});

// ─────────────────────────────────────────────────────────────────────────────
// Unknown state
// ─────────────────────────────────────────────────────────────────────────────

test('device with no state reports freshness unknown and empty values', function () {
    $user = bffUser('fb-bff-unknown-state');
    $conn = bffGhConnection($user);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => ProviderType::GoogleHome->value,
        'state' => null,
        'state_read_at' => null,
    ]);

    bffAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Unknown->value)
        ->assertJsonPath('data.state.values', [])
        ->assertJsonPath('data.state.read_at', null);
});

// ─────────────────────────────────────────────────────────────────────────────
// Stale state
// ─────────────────────────────────────────────────────────────────────────────

test('device with expired state reports freshness stale and preserves last values', function () {
    config(['smart_home.device_state.ttl_seconds' => 60]);
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $user = bffUser('fb-bff-stale-state');
    $conn = bffHaConnection($user);
    $device = bffDimmableLight($user, $conn);
    $device->update([
        'state' => ['power' => true, 'brightness' => 72],
        'state_read_at' => now()->subMinutes(15),
    ]);

    bffAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Stale->value)
        ->assertJsonPath('data.state.values.power', true)
        ->assertJsonPath('data.state.values.brightness', 72);
});

// ─────────────────────────────────────────────────────────────────────────────
// Provider boundary — no provider internals in the response
// ─────────────────────────────────────────────────────────────────────────────

test('device detail BFF contains no provider_device_id', function () {
    Http::fake([BFF_HA_BASE.'/api/states/*' => Http::response(bffHaLightBody())]);

    $user = bffUser('fb-bff-boundary-pdid');
    $conn = bffHaConnection($user);
    $device = bffDimmableLight($user, $conn);

    bffAuth($user);

    $data = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data');

    expect($data)->not->toHaveKey('provider_device_id');
});

test('device detail BFF contains no provider, metadata, entity_id, supported_features, or friendly_name', function () {
    Http::fake([BFF_HA_BASE.'/api/states/*' => Http::response(bffHaLightBody())]);

    $user = bffUser('fb-bff-boundary-multi');
    $conn = bffHaConnection($user);
    $device = bffDimmableLight($user, $conn, 'light.test_boundary');

    bffAuth($user);

    $response = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk();

    $body = $response->getContent();
    $data = $response->json('data');

    expect($data)
        ->not->toHaveKey('provider')
        ->not->toHaveKey('provider_device_id')
        ->not->toHaveKey('entity_id')
        ->not->toHaveKey('metadata')
        ->not->toHaveKey('provider_connection_id')
        ->not->toHaveKey('last_seen_at')
        ->not->toHaveKey('created_at')
        ->not->toHaveKey('updated_at');

    // HA internals must not appear anywhere in the serialized body
    expect($body)
        ->not->toContain('supported_features')
        ->not->toContain('friendly_name')
        ->not->toContain('light.test_boundary');
});

test('device detail BFF never exposes credentials', function () {
    Http::fake([BFF_HA_BASE.'/api/states/*' => Http::response(bffHaLightBody())]);

    $user = bffUser('fb-bff-no-creds');
    $conn = bffHaConnection($user);
    $device = bffDimmableLight($user, $conn);

    bffAuth($user);

    $body = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->getContent();

    expect($body)
        ->not->toContain('bff-token')
        ->not->toContain('access_token')
        ->not->toContain('encrypted_credentials');
});

// ─────────────────────────────────────────────────────────────────────────────
// Constraints preserved
// ─────────────────────────────────────────────────────────────────────────────

test('brightness constraints are preserved on the canonical 0-100 scale', function () {
    $user = bffUser('fb-bff-brightness-constraints');
    $conn = bffGhConnection($user);
    $device = bffDimmableLight($user, $conn);

    bffAuth($user);

    $constraints = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.capabilities.brightness.constraints');

    expect($constraints)->not->toBeNull()
        ->and($constraints['type'])->toBe('number')
        ->and($constraints['min'])->toEqual(0)
        ->and($constraints['max'])->toEqual(100)
        ->and($constraints['step'])->toEqual(1)
        ->and($constraints['unit'])->toBe('percent');
});

test('hvac_mode capability exposes enum constraints with allowed_values', function () {
    $user = bffUser('fb-bff-hvac-enum');
    $conn = bffGhConnection($user);

    $hvacCap = Capability::fromCatalog(
        CapabilityId::HvacMode,
        new EnumConstraint(['off', 'heat', 'cool', 'auto']),
    );
    $storedCapabilities = (new CanonicalCapabilitiesDocument(
        ContractVersion::CURRENT,
        ['hvac_mode' => $hvacCap],
    ))->toArray();

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'capabilities' => $storedCapabilities,
    ]);

    bffAuth($user);

    $cap = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.capabilities.hvac_mode');

    expect($cap['access'])->toBe('read_write')
        ->and($cap['operations'])->toBe(['set'])
        ->and($cap['constraints']['type'])->toBe('enum')
        ->and($cap['constraints']['allowed_values'])->toContain('off')
        ->and($cap['constraints']['allowed_values'])->toContain('heat');
});

// ─────────────────────────────────────────────────────────────────────────────
// Capabilities keyed by canonical IDs only
// ─────────────────────────────────────────────────────────────────────────────

test('capability keys in BFF response are canonical CapabilityId values only', function () {
    Http::fake([BFF_HA_BASE.'/api/states/*' => Http::response(bffHaLightBody())]);

    $user = bffUser('fb-bff-cap-keys');
    $conn = bffHaConnection($user);
    $device = bffDimmableLight($user, $conn);

    bffAuth($user);

    $caps = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.capabilities');

    foreach (array_keys($caps) as $key) {
        expect(CapabilityId::tryFrom($key))->not->toBeNull(
            "Capability key [{$key}] is not a canonical CapabilityId.",
        );
    }
});

test('legacy ADR-033 can_ keys do not appear as capability keys in BFF response', function () {
    $user = bffUser('fb-bff-no-legacy-keys');
    $conn = bffGhConnection($user);

    // The factory uses legacy ADR-033 format by default.
    $device = Device::factory()->dimmableLight()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
    ]);

    bffAuth($user);

    $caps = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.capabilities');

    if ($caps !== null) {
        foreach (array_keys($caps) as $key) {
            expect(str_starts_with($key, 'can_'))->toBeFalse(
                "Legacy key [{$key}] leaked into BFF capabilities.",
            );
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Provider equivalence — same canonical payload regardless of provider
// ─────────────────────────────────────────────────────────────────────────────

test('HA and Fake providers produce equivalent canonical capabilities for a dimmable light', function () {
    $user = bffUser('fb-bff-provider-equiv');

    // HA device with canonical capabilities
    $haConn = bffHaConnection($user);
    $haDevice = bffDimmableLight($user, $haConn, 'light.ha_equiv');

    // Fake provider device with the same canonical capabilities
    config(['smart_home.adapters.fake' => FakeProviderAdapter::class]);
    $fakeConn = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'Fake '.uniqid(),
        'provider' => FakeProviderAdapter::PROVIDER_SLUG,
    ]);

    $mapper = new HomeAssistantCanonicalMapper;
    $capabilities = $mapper->toStoredPayload(
        $mapper->capabilitiesFor('light', ['supported_features' => 1], true),
    );

    $fakeDevice = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $fakeConn->id,
        'provider' => FakeProviderAdapter::PROVIDER_SLUG,
        'capabilities' => $capabilities,
        'state' => null,
        'state_read_at' => null,
    ]);

    $haResource = DeviceDetailResource::make($haDevice->fresh())->resolve();
    $fakeResource = DeviceDetailResource::make($fakeDevice->fresh())->resolve();

    expect($haResource['capabilities'])->toBe($fakeResource['capabilities']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Connectivity — separate from state
// ─────────────────────────────────────────────────────────────────────────────

test('connectivity field uses the canonical DeviceStatus values', function () {
    $user = bffUser('fb-bff-connectivity');
    $conn = bffGhConnection($user);

    $onlineDevice = Device::factory()->online()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
    ]);

    $offlineDevice = Device::factory()->offline()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'provider_device_id' => 'offline.device',
    ]);

    bffAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$onlineDevice->id}")
        ->assertOk()
        ->assertJsonPath('data.connectivity', DeviceStatus::Online->value);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$offlineDevice->id}")
        ->assertOk()
        ->assertJsonPath('data.connectivity', DeviceStatus::Offline->value);
});

test('connectivity does not appear inside state', function () {
    $user = bffUser('fb-bff-conn-not-in-state');
    $conn = bffGhConnection($user);
    $device = Device::factory()->online()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
    ]);

    bffAuth($user);

    $state = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->json('data.state');

    expect($state)
        ->not->toHaveKey('connectivity')
        ->not->toHaveKey('status');
});

// ─────────────────────────────────────────────────────────────────────────────
// Null capabilities
// ─────────────────────────────────────────────────────────────────────────────

test('device with no capabilities reports null capabilities', function () {
    $user = bffUser('fb-bff-null-caps');
    $conn = bffGhConnection($user);
    $device = Device::factory()->withoutCapabilities()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
    ]);

    bffAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.capabilities', null);
});

// ─────────────────────────────────────────────────────────────────────────────
// Authorization
// ─────────────────────────────────────────────────────────────────────────────

test('authenticated user can access their own device detail', function () {
    Http::fake([BFF_HA_BASE.'/api/states/*' => Http::response(bffHaLightBody())]);

    $user = bffUser('fb-bff-auth-own');
    $conn = bffHaConnection($user);
    $device = bffDimmableLight($user, $conn);

    bffAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $device->id);
});

test('user cannot access another users device detail', function () {
    $alice = bffUser('fb-bff-auth-alice');
    $bob = bffUser('fb-bff-auth-bob');

    $aliceConn = ProviderConnection::factory()->create(['user_id' => $alice->id]);
    $aliceDevice = Device::factory()->create([
        'user_id' => $alice->id,
        'provider_connection_id' => $aliceConn->id,
        'provider' => $aliceConn->provider,
    ]);

    bffAuth($bob);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$aliceDevice->id}")
        ->assertForbidden();
});

test('provider is not contacted before authorization check on device detail', function () {
    Http::fake();

    $alice = bffUser('fb-bff-authz-no-provider-alice');
    $bob = bffUser('fb-bff-authz-no-provider-bob');

    $aliceConn = bffHaConnection($alice);
    $aliceDevice = bffDimmableLight($alice, $aliceConn);

    bffAuth($bob);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$aliceDevice->id}")
        ->assertForbidden();

    Http::assertNothingSent();
});

test('unauthenticated request to device detail is rejected before any provider read', function () {
    Http::fake();

    $device = Device::factory()->create();

    $this->getJson("/api/devices/{$device->id}")->assertUnauthorized();

    Http::assertNothingSent();
});
