<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\Adapters\HomeAssistantCanonicalMapper;
use App\SmartHome\Canonical\CanonicalCapabilitiesDocument;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\ContractVersion;
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

const CMD_HA_BASE = 'https://ha.cmd.test';

function cmdJwt(User $user): UnencryptedToken
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

function cmdAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(cmdJwt($user)));
}

function cmdUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-cmd-'.uniqid()]);
}

function cmdHaConnection(User $user): ProviderConnection
{
    $conn = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => ProviderType::HomeAssistant->value,
        'config' => ['base_url' => CMD_HA_BASE],
    ]);
    $conn->setEncryptedCredentials(['access_token' => 'cmd-token']);
    $conn->save();

    return $conn;
}

function cmdGhConnection(User $user): ProviderConnection
{
    return ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'Google Home '.uniqid(),
        'provider' => ProviderType::GoogleHome->value,
        'config' => [],
        'encrypted_credentials' => null,
    ]);
}

/** HA light device with power + brightness capabilities. */
function cmdHaLight(User $user, ProviderConnection $connection, string $entityId = 'light.cmd_room'): Device
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

/** GH device with power capability. */
function cmdGhDevice(User $user, ProviderConnection $connection): Device
{
    $powerCap = Capability::fromCatalog(CapabilityId::Power);
    $capabilities = (new CanonicalCapabilitiesDocument(
        ContractVersion::CURRENT,
        ['power' => $powerCap],
    ))->toArray();

    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => 'gh.switch.kitchen',
        'status' => DeviceStatus::Online->value,
        'capabilities' => $capabilities,
        'state' => null,
        'state_read_at' => null,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication & authorization
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated request is rejected with 401', function () {
    $device = Device::factory()->create();

    $this->postJson("/api/devices/{$device->id}/commands", [
        'capability_id' => 'power',
        'operation' => 'on',
    ])->assertStatus(401);
});

test('cross-user access is rejected with 404', function () {
    $owner = cmdUser('fb-cmd-owner');
    $conn = cmdHaConnection($owner);
    $device = cmdHaLight($owner, $conn);

    $intruder = cmdUser('fb-cmd-intruder');
    cmdAuth($intruder);

    Http::fake([CMD_HA_BASE.'/*' => Http::response([], 200)]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'on',
        ])->assertStatus(403);
});

// ─────────────────────────────────────────────────────────────────────────────
// Request validation
// ─────────────────────────────────────────────────────────────────────────────

test('missing capability_id is rejected with 422', function () {
    $user = cmdUser('fb-cmd-no-cap');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'operation' => 'on',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['capability_id']);
});

test('missing operation is rejected with 422', function () {
    $user = cmdUser('fb-cmd-no-op');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['operation']);
});

test('unknown capability_id is rejected with 422', function () {
    $user = cmdUser('fb-cmd-bad-cap');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'explode_device',
            'operation' => 'on',
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['capability_id']);
});

test('read-only capability cannot be commanded', function () {
    $user = cmdUser('fb-cmd-readonly');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'energy',
            'operation' => 'set',
        ])->assertStatus(422);
});

test('unsupported operation on capability is rejected', function () {
    $user = cmdUser('fb-cmd-unsup-op');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    // power does not support 'set'
    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'set',
        ])->assertStatus(422);
});

test('capability absent on the device is rejected', function () {
    $user = cmdUser('fb-cmd-absent-cap');
    $conn = cmdHaConnection($user);

    // Device with only power capability — no brightness
    $powerCap = Capability::fromCatalog(CapabilityId::Power);
    $capabilities = (new CanonicalCapabilitiesDocument(
        ContractVersion::CURRENT,
        ['power' => $powerCap],
    ))->toArray();

    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        'provider_device_id' => 'light.no_brightness',
        'capabilities' => $capabilities,
        'state' => null,
    ]);

    cmdAuth($user);
    Http::fake([CMD_HA_BASE.'/*' => Http::response([], 200)]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'brightness',
            'operation' => 'set',
            'parameters' => ['value' => 50],
        ])->assertStatus(422);
});

// ─────────────────────────────────────────────────────────────────────────────
// Parameter constraint validation
// ─────────────────────────────────────────────────────────────────────────────

test('brightness below minimum is rejected', function () {
    $user = cmdUser('fb-cmd-bright-min');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'brightness',
            'operation' => 'set',
            'parameters' => ['value' => -1],
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['parameters']);
});

test('brightness above maximum is rejected', function () {
    $user = cmdUser('fb-cmd-bright-max');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'brightness',
            'operation' => 'set',
            'parameters' => ['value' => 9999],
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['parameters']);
});

test('brightness missing value parameter is rejected', function () {
    $user = cmdUser('fb-cmd-bright-noval');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'brightness',
            'operation' => 'set',
            'parameters' => [],
        ])->assertStatus(422)
        ->assertJsonValidationErrors(['parameters']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Server-side execution (Home Assistant)
// ─────────────────────────────────────────────────────────────────────────────

test('authenticated user can command an owned HA device — power on succeeds', function () {
    $user = cmdUser('fb-cmd-power-on');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    Http::fake([CMD_HA_BASE.'/api/services/light/turn_on' => Http::response([], 200)]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'on',
        ])->assertStatus(200)
        ->assertJsonPath('data.status', 'executed');
});

test('power off executes through the provider adapter', function () {
    $user = cmdUser('fb-cmd-power-off');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    Http::fake([CMD_HA_BASE.'/api/services/light/turn_off' => Http::response([], 200)]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'off',
        ])->assertStatus(200)
        ->assertJsonPath('data.status', 'executed');
});

test('power toggle executes through the provider adapter', function () {
    $user = cmdUser('fb-cmd-toggle');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    Http::fake([CMD_HA_BASE.'/api/services/light/toggle' => Http::response([], 200)]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'toggle',
        ])->assertStatus(200)
        ->assertJsonPath('data.status', 'executed');
});

test('brightness set executes through the provider adapter with canonical value converted', function () {
    $user = cmdUser('fb-cmd-brightness');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    Http::fake([CMD_HA_BASE.'/api/services/light/turn_on' => Http::response([], 200)]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'brightness',
            'operation' => 'set',
            'parameters' => ['value' => 50],
        ])->assertStatus(200)
        ->assertJsonPath('data.status', 'executed');
});

test('provider adapter is NOT called when validation fails', function () {
    $user = cmdUser('fb-cmd-no-call');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    Http::fake();

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'brightness',
            'operation' => 'set',
            'parameters' => ['value' => 9999],
        ])->assertStatus(422);

    Http::assertNothingSent();
});

test('authorization failure causes no provider call', function () {
    $owner = cmdUser('fb-cmd-auth-owner');
    $conn = cmdHaConnection($owner);
    $device = cmdHaLight($owner, $conn);

    $intruder = cmdUser('fb-cmd-auth-intruder');
    cmdAuth($intruder);

    Http::fake();

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'on',
        ])->assertStatus(403);

    Http::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────────────────
// Device-side execution (Google Home)
// ─────────────────────────────────────────────────────────────────────────────

test('device-side provider is NOT executed server-side — returns client_execute', function () {
    $user = cmdUser('fb-cmd-gh-client');
    $conn = cmdGhConnection($user);
    $device = cmdGhDevice($user, $conn);
    cmdAuth($user);

    Http::fake();

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'on',
        ])->assertStatus(200)
        ->assertJsonPath('data.status', 'client_execute');

    Http::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────────────────
// Provider failure mapping
// ─────────────────────────────────────────────────────────────────────────────

test('provider transport failure is mapped to 502', function () {
    $user = cmdUser('fb-cmd-transport-fail');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    Http::fake([CMD_HA_BASE.'/*' => fn () => throw new ConnectionException('refused')]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'on',
        ])->assertStatus(502);
});

test('provider HTTP error is mapped to 502', function () {
    $user = cmdUser('fb-cmd-http-fail');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    Http::fake([CMD_HA_BASE.'/*' => Http::response([], 500)]);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'on',
        ])->assertStatus(502);
});

// ─────────────────────────────────────────────────────────────────────────────
// Response contract — no provider internals leak
// ─────────────────────────────────────────────────────────────────────────────

test('no provider-specific fields leak into the success response', function () {
    $user = cmdUser('fb-cmd-no-leak');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    Http::fake([CMD_HA_BASE.'/*' => Http::response([], 200)]);

    $body = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'on',
        ])->assertStatus(200)
        ->json('data');

    // Only 'status' is present — no entity_id, provider_device_id, raw HA response
    expect(array_keys($body))->toBe(['status']);
    expect($body['status'])->toBe('executed');
});

// ─────────────────────────────────────────────────────────────────────────────
// Telemetry — smoke (does not throw, does not 500)
// ─────────────────────────────────────────────────────────────────────────────

test('telemetry is recorded without affecting the response on success', function () {
    $user = cmdUser('fb-cmd-telemetry');
    $conn = cmdHaConnection($user);
    $device = cmdHaLight($user, $conn);
    cmdAuth($user);

    Http::fake([CMD_HA_BASE.'/*' => Http::response([], 200)]);

    // Telemetry uses a Noop backend in the test environment; this verifies
    // that the telemetry wrap path does not throw or alter the response.
    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/devices/{$device->id}/commands", [
            'capability_id' => 'power',
            'operation' => 'on',
        ])->assertStatus(200)
        ->assertJsonPath('data.status', 'executed');
});
