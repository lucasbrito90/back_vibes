<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\DeviceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function rdsJwt(User $user): UnencryptedToken
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

function rdsAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(rdsJwt($user)));
}

function rdsHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function rdsUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-rds-'.uniqid()]);
}

function reportedDevicesSyncUrl(ProviderConnection $connection): string
{
    return "/api/provider-connections/{$connection->id}/devices/sync";
}

function validReportedDevicesPayload(): array
{
    return [
        'devices' => [
            [
                'provider_device_id' => 'light.living_room',
                'name' => 'Living Room Light',
                'type' => 'lighting',
                'capabilities' => [
                    'can_turn_on' => [],
                    'can_turn_off' => [],
                    'can_toggle' => [],
                    'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
                ],
            ],
            [
                'provider_device_id' => 'switch.kitchen',
                'name' => 'Kitchen Switch',
                'type' => null,
                'capabilities' => null,
            ],
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot sync reported devices', function () {
    $conn = ProviderConnection::factory()->create();

    $this->postJson(reportedDevicesSyncUrl($conn), validReportedDevicesPayload())
        ->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// Happy path — persistence (P05)
// ─────────────────────────────────────────────────────────────────────────────

test('valid reported devices payload creates devices and returns a sync summary', function () {
    $user = rdsUser('fb-rds-valid');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);
    $payload = validReportedDevicesPayload();

    rdsAuth($user);

    $this->postJson(reportedDevicesSyncUrl($conn), $payload, rdsHeaders())
        ->assertOk()
        ->assertJson(['data' => [
            'provider_connection_id' => $conn->id,
            'synced' => 2,
            'created' => 2,
            'updated' => 0,
            'offline' => 0,
        ]]);

    $light = Device::where('provider_connection_id', $conn->id)
        ->where('provider_device_id', 'light.living_room')
        ->first();

    expect($light)->not->toBeNull()
        ->and($light->user_id)->toBe($user->id)
        ->and($light->name)->toBe('Living Room Light')
        ->and($light->type)->toBe('lighting')
        ->and($light->provider)->toBe($conn->provider)
        ->and($light->status)->toBe(DeviceStatus::Online->value)
        ->and($light->capabilities)->toMatchArray([
            'can_turn_on' => [],
            'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
        ]);

    $switch = Device::where('provider_connection_id', $conn->id)
        ->where('provider_device_id', 'switch.kitchen')
        ->first();

    expect($switch)->not->toBeNull()
        ->and($switch->type)->toBeNull()
        ->and($switch->capabilities)->toBeNull();
});

test('valid payload with devices that omit capabilities creates a device with null capabilities', function () {
    $user = rdsUser('fb-rds-no-caps');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    $payload = [
        'devices' => [
            [
                'provider_device_id' => 'device.only',
                'name' => 'Simple Device',
                'type' => 'switchable',
            ],
        ],
    ];

    rdsAuth($user);

    $this->postJson(reportedDevicesSyncUrl($conn), $payload, rdsHeaders())
        ->assertOk()
        ->assertJson(['data' => ['created' => 1, 'updated' => 0]]);

    $device = Device::where('provider_connection_id', $conn->id)
        ->where('provider_device_id', 'device.only')
        ->first();

    expect($device)->not->toBeNull()
        ->and($device->capabilities)->toBeNull();
});

test('re-syncing the same catalog updates rather than duplicates devices', function () {
    $user = rdsUser('fb-rds-resync');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);
    $payload = validReportedDevicesPayload();

    rdsAuth($user);

    $this->postJson(reportedDevicesSyncUrl($conn), $payload, rdsHeaders())->assertOk();

    $renamed = $payload;
    $renamed['devices'][0]['name'] = 'Living Room Light (renamed)';

    $this->postJson(reportedDevicesSyncUrl($conn), $renamed, rdsHeaders())
        ->assertOk()
        ->assertJson(['data' => ['synced' => 2, 'created' => 0, 'updated' => 2, 'offline' => 0]]);

    expect(Device::where('provider_connection_id', $conn->id)->count())->toBe(2);

    $light = Device::where('provider_connection_id', $conn->id)
        ->where('provider_device_id', 'light.living_room')
        ->first();

    expect($light->name)->toBe('Living Room Light (renamed)');
});

test('a device missing from a subsequent report is marked offline, not deleted', function () {
    $user = rdsUser('fb-rds-offline');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    rdsAuth($user);

    $this->postJson(reportedDevicesSyncUrl($conn), validReportedDevicesPayload(), rdsHeaders())->assertOk();

    // Second report omits switch.kitchen entirely.
    $this->postJson(reportedDevicesSyncUrl($conn), [
        'devices' => [
            [
                'provider_device_id' => 'light.living_room',
                'name' => 'Living Room Light',
                'type' => 'lighting',
                'capabilities' => null,
            ],
        ],
    ], rdsHeaders())
        ->assertOk()
        ->assertJson(['data' => ['synced' => 1, 'offline' => 1]]);

    expect(Device::where('provider_connection_id', $conn->id)->count())->toBe(2);

    $switch = Device::where('provider_connection_id', $conn->id)
        ->where('provider_device_id', 'switch.kitchen')
        ->first();

    expect($switch->status)->toBe(DeviceStatus::Offline->value);

    $light = Device::where('provider_connection_id', $conn->id)
        ->where('provider_device_id', 'light.living_room')
        ->first();

    expect($light->status)->toBe(DeviceStatus::Online->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// Ownership (P05)
// ─────────────────────────────────────────────────────────────────────────────

test('syncing devices for a connection owned by another user returns 404', function () {
    $owner = rdsUser('fb-rds-owner');
    $attacker = rdsUser('fb-rds-attacker');
    $conn = ProviderConnection::factory()->create(['user_id' => $owner->id]);

    rdsAuth($attacker);

    $this->postJson(reportedDevicesSyncUrl($conn), validReportedDevicesPayload(), rdsHeaders())
        ->assertNotFound();

    expect(Device::where('provider_connection_id', $conn->id)->count())->toBe(0);
});

test('devices reported for one user connection never collide with another user devices sharing the same provider_device_id', function () {
    $userA = rdsUser('fb-rds-iso-a');
    $userB = rdsUser('fb-rds-iso-b');
    $connA = ProviderConnection::factory()->create(['user_id' => $userA->id, 'name' => 'A conn']);
    $connB = ProviderConnection::factory()->create(['user_id' => $userB->id, 'name' => 'B conn']);

    $payload = [
        'devices' => [
            [
                'provider_device_id' => 'light.shared_id',
                'name' => 'Device A',
                'type' => 'lighting',
            ],
        ],
    ];

    rdsAuth($userA);
    $this->postJson(reportedDevicesSyncUrl($connA), $payload, rdsHeaders())->assertOk();

    rdsAuth($userB);
    $payloadB = $payload;
    $payloadB['devices'][0]['name'] = 'Device B';
    $this->postJson(reportedDevicesSyncUrl($connB), $payloadB, rdsHeaders())->assertOk();

    expect(Device::where('provider_connection_id', $connA->id)->count())->toBe(1)
        ->and(Device::where('provider_connection_id', $connB->id)->count())->toBe(1);

    $deviceA = Device::where('provider_connection_id', $connA->id)->first();
    $deviceB = Device::where('provider_connection_id', $connB->id)->first();

    expect($deviceA->id)->not->toBe($deviceB->id)
        ->and($deviceA->user_id)->toBe($userA->id)
        ->and($deviceB->user_id)->toBe($userB->id)
        ->and($deviceA->name)->toBe('Device A')
        ->and($deviceB->name)->toBe('Device B');
});

// ─────────────────────────────────────────────────────────────────────────────
// Validation errors
// ─────────────────────────────────────────────────────────────────────────────

test('missing provider_device_id returns 422', function () {
    $user = rdsUser('fb-rds-missing-id');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    rdsAuth($user);

    $this->postJson(reportedDevicesSyncUrl($conn), [
        'devices' => [
            [
                'name' => 'No ID Device',
                'type' => 'lighting',
            ],
        ],
    ], rdsHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['devices.0.provider_device_id']);
});

test('capability outside ADR-033 vocabulary returns 422 with identifying message', function () {
    $user = rdsUser('fb-rds-bad-cap');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    rdsAuth($user);

    $response = $this->postJson(reportedDevicesSyncUrl($conn), [
        'devices' => [
            [
                'provider_device_id' => 'light.flying',
                'name' => 'Flying Light',
                'type' => 'lighting',
                'capabilities' => [
                    'can_fly' => [],
                ],
            ],
        ],
    ], rdsHeaders())
        ->assertUnprocessable();

    $errors = $response->json('errors');
    $errorKeys = array_keys($errors ?? []);

    expect($errorKeys)->not->toBeEmpty();

    $messages = collect($errors)->flatten()->implode(' ');
    expect($messages)->toContain('ADR-033');
});

// ─────────────────────────────────────────────────────────────────────────────
// Validation still short-circuits before any persistence (P04 boundary,
// still true now that P05 adds real persistence to the happy path)
// ─────────────────────────────────────────────────────────────────────────────

test('an invalid payload does not change the devices table row count', function () {
    $user = rdsUser('fb-rds-no-write');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    $before = DB::table('devices')->count();

    rdsAuth($user);

    $this->postJson(reportedDevicesSyncUrl($conn), [
        'devices' => [['name' => 'No ID Device']],
    ], rdsHeaders())->assertUnprocessable();

    expect(DB::table('devices')->count())->toBe($before);
});
