<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\ActionType;
use App\SmartHome\DeviceStatus;
use App\SmartHome\ProviderType;
use Database\Factories\DeviceFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Schema — devices table
// ─────────────────────────────────────────────────────────────────────────────

test('devices table has all hardened MVP columns', function () {
    $columns = Schema::getColumnListing('devices');

    expect($columns)
        ->toContain('id')
        ->toContain('user_id')
        ->toContain('provider_connection_id')
        ->toContain('name')
        ->toContain('type')
        ->toContain('provider')
        ->toContain('provider_device_id')
        ->toContain('status')
        ->toContain('metadata')
        ->toContain('capabilities')
        ->toContain('last_seen_at')
        ->toContain('created_at')
        ->toContain('updated_at');
});

test('devices table does not have old external_id column', function () {
    $columns = Schema::getColumnListing('devices');

    expect($columns)->not->toContain('external_id');
});

// ─────────────────────────────────────────────────────────────────────────────
// Device model — creation and relationships
// ─────────────────────────────────────────────────────────────────────────────

test('Device can be created with a ProviderConnection', function () {
    $device = Device::factory()->create();

    expect($device->id)->toBeInt()
        ->and($device->provider_connection_id)->toBeInt()
        ->and($device->provider)->toBe(ProviderType::HomeAssistant->value)
        ->and($device->status)->toBe(DeviceStatus::Unknown->value);
});

test('Device provider_connection_id belongs to correct connection', function () {
    $connection = ProviderConnection::factory()->create();
    $device = Device::factory()->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
    ]);

    expect($device->providerConnection->id)->toBe($connection->id);
});

test('ProviderConnection devices() returns associated devices', function () {
    $connection = ProviderConnection::factory()->create();
    Device::factory()->count(3)->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => fn () => 'light.device_'.fake()->unique()->randomNumber(4),
    ]);

    $devices = $connection->devices;

    expect($devices)->toHaveCount(3)
        ->and($devices->first())->toBeInstanceOf(Device::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Device model — casts
// ─────────────────────────────────────────────────────────────────────────────

test('Device metadata casts to array', function () {
    $meta = ['domain' => 'light', 'supported_features' => 1];
    $device = Device::factory()->create(['metadata' => $meta]);

    expect($device->fresh()->metadata)->toBe($meta)
        ->and($device->fresh()->metadata)->toBeArray();
});

test('Device capabilities casts to array and preserves ADR-033 map shape', function () {
    $capabilities = [
        'can_turn_on' => [],
        'can_turn_off' => [],
        'can_toggle' => [],
        'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
    ];

    $device = Device::factory()->create(['capabilities' => $capabilities]);

    expect($device->fresh()->capabilities)->toBe($capabilities)
        ->and($device->fresh()->capabilities)->toBeArray()
        ->and(array_is_list($device->capabilities))->toBeFalse()
        ->and($device->capabilities['can_set_brightness'])->toMatchArray(['min' => 0, 'max' => 255, 'step' => 1]);
});

test('Device capabilities is nullable for unknown state', function () {
    $device = Device::factory()->withoutCapabilities()->create();

    expect($device->fresh()->capabilities)->toBeNull();
});

test('DeviceFactory generates ADR-033 capabilities map for lights', function () {
    $device = Device::factory()->dimmableLight()->create();

    expect($device->capabilities)->toBeArray()
        ->and($device->capabilities)->toHaveKeys(['can_turn_on', 'can_turn_off', 'can_toggle', 'can_set_brightness'])
        ->and($device->capabilities['can_turn_on'])->toBe([])
        ->and($device->capabilities['can_set_brightness'])->toMatchArray(['min' => 0, 'max' => 255, 'step' => 1]);
});

test('DeviceFactory generates boolean-only capabilities for switch-type fixtures', function () {
    $device = Device::factory()->create([
        'type' => 'switch',
        'capabilities' => DeviceFactory::adr033CapabilitiesForType('switch'),
    ]);

    expect($device->capabilities)->toMatchArray([
        'can_turn_on' => [],
        'can_turn_off' => [],
        'can_toggle' => [],
    ])->and($device->capabilities)->not->toHaveKey('can_set_brightness');
});

test('Device last_seen_at casts to Carbon datetime', function () {
    $device = Device::factory()->online()->create();

    expect($device->last_seen_at)->not->toBeNull()
        ->and($device->last_seen_at)->toBeInstanceOf(Carbon::class);
});

test('Device last_seen_at is nullable', function () {
    $device = Device::factory()->unknown()->create();

    expect($device->fresh()->last_seen_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Device model — updated_at
// ─────────────────────────────────────────────────────────────────────────────

test('Device updated_at is populated on create', function () {
    $device = Device::factory()->create();

    expect($device->updated_at)->not->toBeNull();
});

test('Device updated_at advances on update', function () {
    $device = Device::factory()->create();
    $original = $device->updated_at;

    sleep(1);
    $device->touch();
    $device->refresh();

    expect($device->updated_at->greaterThanOrEqualTo($original))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// Unique constraint — (provider_connection_id, provider_device_id)
// ─────────────────────────────────────────────────────────────────────────────

test('unique constraint prevents duplicate provider_device_id on same connection', function () {
    $connection = ProviderConnection::factory()->create();

    Device::factory()->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => 'light.living_room',
    ]);

    expect(fn () => Device::factory()->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => 'light.living_room',
    ]))->toThrow(QueryException::class);
});

test('same provider_device_id is allowed under different provider connections', function () {
    $connectionA = ProviderConnection::factory()->create();
    $connectionB = ProviderConnection::factory()->create();

    $deviceA = Device::factory()->create([
        'user_id' => $connectionA->user_id,
        'provider_connection_id' => $connectionA->id,
        'provider' => $connectionA->provider,
        'provider_device_id' => 'light.living_room',
    ]);

    $deviceB = Device::factory()->create([
        'user_id' => $connectionB->user_id,
        'provider_connection_id' => $connectionB->id,
        'provider' => $connectionB->provider,
        'provider_device_id' => 'light.living_room',
    ]);

    expect($deviceA->id)->not->toBe($deviceB->id)
        ->and(Device::query()->count())->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// DeviceFactory states
// ─────────────────────────────────────────────────────────────────────────────

test('DeviceFactory online state sets status online and last_seen_at', function () {
    $device = Device::factory()->online()->create();

    expect($device->status)->toBe(DeviceStatus::Online->value)
        ->and($device->last_seen_at)->not->toBeNull();
});

test('DeviceFactory offline state sets status offline and last_seen_at', function () {
    $device = Device::factory()->offline()->create();

    expect($device->status)->toBe(DeviceStatus::Offline->value)
        ->and($device->last_seen_at)->not->toBeNull();
});

test('DeviceFactory unknown state sets status unknown and null last_seen_at', function () {
    $device = Device::factory()->unknown()->create();

    expect($device->status)->toBe(DeviceStatus::Unknown->value)
        ->and($device->last_seen_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// ActionType enum
// ─────────────────────────────────────────────────────────────────────────────

test('ActionType mvpAllowed returns turn_on, turn_off, toggle, and set_brightness', function () {
    $allowed = ActionType::mvpAllowed();

    expect($allowed)->toHaveCount(4)
        ->and($allowed)->toContain(ActionType::TurnOn)
        ->and($allowed)->toContain(ActionType::TurnOff)
        ->and($allowed)->toContain(ActionType::Toggle)
        ->and($allowed)->toContain(ActionType::SetBrightness);
});

test('ActionType values are the expected strings', function () {
    expect(ActionType::TurnOn->value)->toBe('turn_on')
        ->and(ActionType::TurnOff->value)->toBe('turn_off')
        ->and(ActionType::Toggle->value)->toBe('toggle')
        ->and(ActionType::SetBrightness->value)->toBe('set_brightness');
});

test('ActionType all cases are MVP supported', function () {
    foreach (ActionType::cases() as $case) {
        expect($case->isMvpSupported())->toBeTrue();
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// DeviceStatus enum
// ─────────────────────────────────────────────────────────────────────────────

test('DeviceStatus values returns online, offline, and unknown', function () {
    $values = DeviceStatus::values();

    expect($values)->toContain('online')
        ->toContain('offline')
        ->toContain('unknown')
        ->toHaveCount(3);
});

test('DeviceStatus values are the expected strings', function () {
    expect(DeviceStatus::Online->value)->toBe('online')
        ->and(DeviceStatus::Offline->value)->toBe('offline')
        ->and(DeviceStatus::Unknown->value)->toBe('unknown');
});

// ─────────────────────────────────────────────────────────────────────────────
// User → Device relationship (pre-existing, still works)
// ─────────────────────────────────────────────────────────────────────────────

test('User devices relationship returns devices after hardening', function () {
    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);
    Device::factory()->count(2)->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => fn () => 'light.device_'.fake()->unique()->randomNumber(4),
    ]);

    expect($user->devices)->toHaveCount(2);
});
