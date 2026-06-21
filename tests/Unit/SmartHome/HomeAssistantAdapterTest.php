<?php

declare(strict_types=1);

use App\Models\ProviderConnection;
use App\SmartHome\Adapters\HomeAssistantAdapter;
use App\SmartHome\DeviceStatus;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\DTOs\ConnectionHealth;
use App\SmartHome\DTOs\DeviceStatusResult;
use App\SmartHome\DTOs\ProviderDevice;
use App\SmartHome\Exceptions\ProviderConnectionException;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use App\SmartHome\ProviderType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — build a non-persisted ProviderConnection (no DB needed for unit tests)
// ─────────────────────────────────────────────────────────────────────────────

const HA_BASE = 'https://ha.example.test';
const HA_TOKEN = 'llat-super-secret-token';

function haConnection(string $token = HA_TOKEN, string $baseUrl = HA_BASE): ProviderConnection
{
    $connection = new ProviderConnection;
    $connection->name = 'Home HA';
    $connection->provider = ProviderType::HomeAssistant->value;
    $connection->config = ['base_url' => $baseUrl];
    $connection->setEncryptedCredentials(['access_token' => $token]);

    return $connection;
}

function haAdapter(): HomeAssistantAdapter
{
    return new HomeAssistantAdapter;
}

/** Index a list of ProviderDevice by provider_device_id. */
function indexDevices(array $devices): array
{
    $byId = [];
    foreach ($devices as $device) {
        $byId[$device->provider_device_id] = $device;
    }

    return $byId;
}

// ─────────────────────────────────────────────────────────────────────────────
// testConnection
// ─────────────────────────────────────────────────────────────────────────────

test('testConnection returns reachable on 200', function () {
    Http::fake([HA_BASE.'/api/' => Http::response(['message' => 'API running.'], 200)]);

    $health = haAdapter()->testConnection(haConnection());

    expect($health)->toBeInstanceOf(ConnectionHealth::class)
        ->and($health->reachable)->toBeTrue()
        ->and($health->status_code)->toBe(200)
        ->and($health->error_message)->toBeNull()
        ->and($health->latency_ms)->toBeInt();
});

test('testConnection returns not reachable on 401', function () {
    Http::fake([HA_BASE.'/api/' => Http::response(['message' => 'Unauthorized.'], 401)]);

    $health = haAdapter()->testConnection(haConnection());

    expect($health->reachable)->toBeFalse()
        ->and($health->status_code)->toBe(401)
        ->and($health->error_message)->not->toBeNull();
});

test('testConnection returns not reachable on 500', function () {
    Http::fake([HA_BASE.'/api/' => Http::response('error', 500)]);

    $health = haAdapter()->testConnection(haConnection());

    expect($health->reachable)->toBeFalse()
        ->and($health->status_code)->toBe(500)
        ->and($health->error_message)->not->toBeNull();
});

test('testConnection returns not reachable on connection failure', function () {
    Http::fake(fn (Request $request) => throw new ConnectionException('Connection timed out'));

    $health = haAdapter()->testConnection(haConnection());

    expect($health->reachable)->toBeFalse()
        ->and($health->status_code)->toBeNull()
        ->and($health->error_message)->not->toBeNull()
        ->and($health->latency_ms)->toBeInt();
});

test('testConnection sends Authorization Bearer token', function () {
    Http::fake([HA_BASE.'/api/' => Http::response(['message' => 'API running.'], 200)]);

    haAdapter()->testConnection(haConnection('my-bearer-token'));

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer my-bearer-token'));
});

test('ConnectionHealth never contains the access token', function () {
    Http::fake([HA_BASE.'/api/' => Http::response([], 200)]);

    $health = haAdapter()->testConnection(haConnection('top-secret-xyz'));

    expect(json_encode($health))->not->toContain('top-secret-xyz');
});

// ─────────────────────────────────────────────────────────────────────────────
// listDevices
// ─────────────────────────────────────────────────────────────────────────────

test('listDevices parses actionable HA states into ProviderDevice DTOs', function () {
    Http::fake([HA_BASE.'/api/states' => Http::response([
        ['entity_id' => 'light.living_room', 'state' => 'on', 'attributes' => ['friendly_name' => 'Living Room']],
        ['entity_id' => 'switch.kitchen', 'state' => 'off', 'attributes' => []],
        ['entity_id' => 'media_player.tv', 'state' => 'playing', 'attributes' => ['friendly_name' => 'TV']],
        ['entity_id' => 'fan.bedroom', 'state' => 'on', 'attributes' => []],
        ['entity_id' => 'sensor.temperature', 'state' => '21.5', 'attributes' => ['device_class' => 'temperature']],
        ['entity_id' => 'binary_sensor.door', 'state' => 'on'],
        ['entity_id' => 'climate.thermostat', 'state' => 'heat'],
    ], 200)]);

    $devices = haAdapter()->listDevices(haConnection());

    expect($devices)->toHaveCount(4)
        ->and($devices[0])->toBeInstanceOf(ProviderDevice::class);

    $ids = array_keys(indexDevices($devices));

    expect($ids)->toContain('light.living_room')
        ->toContain('switch.kitchen')
        ->toContain('media_player.tv')
        ->toContain('fan.bedroom')
        ->not->toContain('sensor.temperature')
        ->not->toContain('binary_sensor.door')
        ->not->toContain('climate.thermostat');
});

test('listDevices uses friendly_name when present and falls back to entity_id', function () {
    Http::fake([HA_BASE.'/api/states' => Http::response([
        ['entity_id' => 'light.living_room', 'state' => 'on', 'attributes' => ['friendly_name' => 'Living Room']],
        ['entity_id' => 'switch.kitchen', 'state' => 'off', 'attributes' => []],
    ], 200)]);

    $byId = indexDevices(haAdapter()->listDevices(haConnection()));

    expect($byId['light.living_room']->name)->toBe('Living Room')
        ->and($byId['switch.kitchen']->name)->toBe('switch.kitchen');
});

test('listDevices maps HA state values to DeviceStatus', function () {
    Http::fake([HA_BASE.'/api/states' => Http::response([
        ['entity_id' => 'light.on', 'state' => 'on'],
        ['entity_id' => 'switch.gone', 'state' => 'unavailable'],
        ['entity_id' => 'fan.dunno', 'state' => 'unknown'],
    ], 200)]);

    $byId = indexDevices(haAdapter()->listDevices(haConnection()));

    expect($byId['light.on']->status)->toBe(DeviceStatus::Online)
        ->and($byId['switch.gone']->status)->toBe(DeviceStatus::Offline)
        ->and($byId['fan.dunno']->status)->toBe(DeviceStatus::Unknown);
});

test('listDevices populates metadata with domain, raw_state, supported_features, device_class', function () {
    Http::fake([HA_BASE.'/api/states' => Http::response([
        ['entity_id' => 'light.living_room', 'state' => 'on', 'attributes' => [
            'friendly_name' => 'Living Room',
            'supported_features' => 44,
            'device_class' => 'tv',
        ]],
    ], 200)]);

    $device = haAdapter()->listDevices(haConnection())[0];

    expect($device->type)->toBe('light')
        ->and($device->metadata['domain'])->toBe('light')
        ->and($device->metadata['raw_state'])->toBe('on')
        ->and($device->metadata['supported_features'])->toBe(44)
        ->and($device->metadata['device_class'])->toBe('tv');
});

test('listDevices parses last_changed into a Carbon last_seen_at', function () {
    Http::fake([HA_BASE.'/api/states' => Http::response([
        ['entity_id' => 'light.living_room', 'state' => 'on', 'last_changed' => '2026-06-20T10:00:00+00:00'],
    ], 200)]);

    $device = haAdapter()->listDevices(haConnection())[0];

    expect($device->last_seen_at)->not->toBeNull()
        ->and($device->last_seen_at->toIso8601String())->toContain('2026-06-20');
});

test('listDevices sends Authorization Bearer token', function () {
    Http::fake([HA_BASE.'/api/states' => Http::response([], 200)]);

    haAdapter()->listDevices(haConnection('states-token'));

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer states-token'));
});

test('listDevices throws ProviderConnectionException on connection failure', function () {
    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    expect(fn () => haAdapter()->listDevices(haConnection()))
        ->toThrow(ProviderConnectionException::class);
});

test('listDevices throws ProviderConnectionException on 401', function () {
    Http::fake([HA_BASE.'/api/states' => Http::response([], 401)]);

    expect(fn () => haAdapter()->listDevices(haConnection()))
        ->toThrow(ProviderConnectionException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// readStatus
// ─────────────────────────────────────────────────────────────────────────────

test('readStatus parses a single entity status', function () {
    Http::fake([HA_BASE.'/api/states/light.living_room' => Http::response([
        'entity_id' => 'light.living_room',
        'state' => 'on',
        'attributes' => ['friendly_name' => 'Living Room', 'brightness' => 200],
        'last_changed' => '2026-06-20T10:00:00+00:00',
    ], 200)]);

    $result = haAdapter()->readStatus(haConnection(), 'light.living_room');

    expect($result)->toBeInstanceOf(DeviceStatusResult::class)
        ->and($result->provider_device_id)->toBe('light.living_room')
        ->and($result->status)->toBe(DeviceStatus::Online)
        ->and($result->raw_state)->toBe('on')
        ->and($result->attributes['brightness'])->toBe(200)
        ->and($result->last_changed)->toBe('2026-06-20T10:00:00+00:00');
});

test('readStatus maps unavailable state to Offline', function () {
    Http::fake([HA_BASE.'/api/states/switch.kitchen' => Http::response([
        'entity_id' => 'switch.kitchen',
        'state' => 'unavailable',
    ], 200)]);

    $result = haAdapter()->readStatus(haConnection(), 'switch.kitchen');

    expect($result->status)->toBe(DeviceStatus::Offline)
        ->and($result->raw_state)->toBe('unavailable');
});

test('readStatus returns Unknown on 404', function () {
    Http::fake([HA_BASE.'/api/states/light.ghost' => Http::response(['message' => 'Entity not found.'], 404)]);

    $result = haAdapter()->readStatus(haConnection(), 'light.ghost');

    expect($result->status)->toBe(DeviceStatus::Unknown)
        ->and($result->raw_state)->toBeNull()
        ->and($result->provider_device_id)->toBe('light.ghost');
});

test('readStatus returns Unknown on connection failure', function () {
    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    $result = haAdapter()->readStatus(haConnection(), 'light.living_room');

    expect($result->status)->toBe(DeviceStatus::Unknown)
        ->and($result->raw_state)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// executeAction
// ─────────────────────────────────────────────────────────────────────────────

test('executeAction turn_on posts to the domain turn_on service', function () {
    Http::fake([HA_BASE.'/api/services/light/turn_on' => Http::response([], 200)]);

    $result = haAdapter()->executeAction(haConnection(), 'light.living_room', 'turn_on');

    expect($result)->toBeInstanceOf(ActionResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->status_code)->toBe(200);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === HA_BASE.'/api/services/light/turn_on'
        && $request['entity_id'] === 'light.living_room');
});

test('executeAction turn_off posts to the domain turn_off service', function () {
    Http::fake([HA_BASE.'/api/services/switch/turn_off' => Http::response([], 200)]);

    $result = haAdapter()->executeAction(haConnection(), 'switch.kitchen', 'turn_off');

    expect($result->success)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === HA_BASE.'/api/services/switch/turn_off'
        && $request['entity_id'] === 'switch.kitchen');
});

test('executeAction toggle posts to the domain toggle service', function () {
    Http::fake([HA_BASE.'/api/services/fan/toggle' => Http::response([], 200)]);

    $result = haAdapter()->executeAction(haConnection(), 'fan.bedroom', 'toggle');

    expect($result->success)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === HA_BASE.'/api/services/fan/toggle'
        && $request['entity_id'] === 'fan.bedroom');
});

test('executeAction forwards extra parameters in the payload', function () {
    Http::fake([HA_BASE.'/api/services/media_player/turn_on' => Http::response([], 200)]);

    haAdapter()->executeAction(haConnection(), 'media_player.tv', 'turn_on', ['source' => 'HDMI1']);

    Http::assertSent(fn (Request $request) => $request['entity_id'] === 'media_player.tv'
        && $request['source'] === 'HDMI1');
});

test('executeAction sends Authorization Bearer token', function () {
    Http::fake([HA_BASE.'/api/services/light/turn_on' => Http::response([], 200)]);

    haAdapter()->executeAction(haConnection('exec-token'), 'light.living_room', 'turn_on');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer exec-token'));
});

test('executeAction throws on unsupported action', function () {
    Http::fake();

    expect(fn () => haAdapter()->executeAction(haConnection(), 'light.living_room', 'set_brightness'))
        ->toThrow(UnsupportedSmartHomeActionException::class);
});

test('executeAction returns a failed ActionResult on 500', function () {
    Http::fake([HA_BASE.'/api/services/light/turn_on' => Http::response(['error' => 'boom'], 500)]);

    $result = haAdapter()->executeAction(haConnection(), 'light.living_room', 'turn_on');

    expect($result->success)->toBeFalse()
        ->and($result->status_code)->toBe(500)
        ->and($result->error_message)->not->toBeNull();
});

test('executeAction returns a failed ActionResult on connection failure', function () {
    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    $result = haAdapter()->executeAction(haConnection(), 'light.living_room', 'turn_on');

    expect($result->success)->toBeFalse()
        ->and($result->status_code)->toBeNull()
        ->and($result->error_message)->not->toBeNull();
});
