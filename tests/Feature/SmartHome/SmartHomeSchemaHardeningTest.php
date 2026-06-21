<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use App\SmartHome\ActionType;
use App\SmartHome\DeviceStatus;
use App\SmartHome\ProviderType;
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
        ->toContain('last_seen_at')
        ->toContain('created_at')
        ->toContain('updated_at');
});

test('devices table does not have old external_id column', function () {
    $columns = Schema::getColumnListing('devices');

    expect($columns)->not->toContain('external_id');
});

// ─────────────────────────────────────────────────────────────────────────────
// Schema — vibe_device_actions table
// ─────────────────────────────────────────────────────────────────────────────

test('vibe_device_actions table has sort_order and updated_at', function () {
    $columns = Schema::getColumnListing('vibe_device_actions');

    expect($columns)
        ->toContain('sort_order')
        ->toContain('updated_at');
});

test('vibe_device_actions table retains all original columns', function () {
    $columns = Schema::getColumnListing('vibe_device_actions');

    expect($columns)
        ->toContain('id')
        ->toContain('vibe_id')
        ->toContain('device_id')
        ->toContain('action_type')
        ->toContain('parameters')
        ->toContain('delay_seconds')
        ->toContain('created_at');
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
// VibeDeviceAction model — creation and casts
// ─────────────────────────────────────────────────────────────────────────────

test('VibeDeviceAction can be created with sort_order and delay_seconds', function () {
    $action = VibeDeviceAction::factory()->create([
        'sort_order' => 2,
        'delay_seconds' => 5,
    ]);

    $fresh = $action->fresh();

    expect($fresh->sort_order)->toBe(2)
        ->and($fresh->delay_seconds)->toBe(5);
});

test('VibeDeviceAction sort_order defaults to 0', function () {
    $action = VibeDeviceAction::factory()->create();

    expect($action->fresh()->sort_order)->toBe(0);
});

test('VibeDeviceAction sort_order and delay_seconds cast to integer', function () {
    $action = VibeDeviceAction::factory()->create([
        'sort_order' => 3,
        'delay_seconds' => 10,
    ]);

    expect($action->fresh()->sort_order)->toBeInt()
        ->and($action->fresh()->delay_seconds)->toBeInt();
});

test('VibeDeviceAction updated_at is populated on create', function () {
    $action = VibeDeviceAction::factory()->create();

    expect($action->fresh()->updated_at)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// VibeDeviceAction — no unique constraint (multiple actions per device/vibe)
// ─────────────────────────────────────────────────────────────────────────────

test('multiple VibeDeviceActions on same vibe and device are allowed', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'sort_order' => 0,
    ]);

    // Second action on the same vibe + device — must not throw
    $second = VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOff->value,
        'sort_order' => 1,
        'delay_seconds' => 30,
    ]);

    expect($second->id)->toBeInt()
        ->and(VibeDeviceAction::query()->where('vibe_id', $vibe->id)->count())->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Vibe::deviceActions — ordered by sort_order
// ─────────────────────────────────────────────────────────────────────────────

test('Vibe deviceActions are returned ordered by sort_order ascending', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    // Insert in reverse order so we can confirm the ordering is by sort_order.
    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'action_type' => ActionType::Toggle->value,
        'sort_order' => 10,
    ]);
    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'sort_order' => 1,
    ]);
    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOff->value,
        'sort_order' => 5,
    ]);

    $actions = $vibe->deviceActions()->get();

    expect($actions->pluck('sort_order')->all())->toBe([1, 5, 10]);
});

// ─────────────────────────────────────────────────────────────────────────────
// VibeDeviceActionFactory states
// ─────────────────────────────────────────────────────────────────────────────

test('VibeDeviceActionFactory turnOn state sets action_type turn_on', function () {
    $action = VibeDeviceAction::factory()->turnOn()->create();

    expect($action->action_type)->toBe(ActionType::TurnOn->value);
});

test('VibeDeviceActionFactory turnOff state sets action_type turn_off', function () {
    $action = VibeDeviceAction::factory()->turnOff()->create();

    expect($action->action_type)->toBe(ActionType::TurnOff->value);
});

test('VibeDeviceActionFactory toggle state sets action_type toggle', function () {
    $action = VibeDeviceAction::factory()->toggle()->create();

    expect($action->action_type)->toBe(ActionType::Toggle->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// ActionType enum
// ─────────────────────────────────────────────────────────────────────────────

test('ActionType mvpAllowed returns turn_on, turn_off, and toggle', function () {
    $allowed = ActionType::mvpAllowed();

    expect($allowed)->toHaveCount(3)
        ->and($allowed)->toContain(ActionType::TurnOn)
        ->and($allowed)->toContain(ActionType::TurnOff)
        ->and($allowed)->toContain(ActionType::Toggle);
});

test('ActionType values are the expected strings', function () {
    expect(ActionType::TurnOn->value)->toBe('turn_on')
        ->and(ActionType::TurnOff->value)->toBe('turn_off')
        ->and(ActionType::Toggle->value)->toBe('toggle');
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
