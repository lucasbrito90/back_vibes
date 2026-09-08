<?php

declare(strict_types=1);

use App\Models\ProviderConnection;
use App\Models\User;
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
// Happy path — validation scaffold only (no persistence)
// ─────────────────────────────────────────────────────────────────────────────

test('valid reported devices payload returns 200 and echoes submitted devices', function () {
    $user = rdsUser('fb-rds-valid');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);
    $payload = validReportedDevicesPayload();

    rdsAuth($user);

    $this->postJson(reportedDevicesSyncUrl($conn), $payload, rdsHeaders())
        ->assertOk()
        ->assertJsonPath('data.devices', $payload['devices']);
});

test('valid payload with devices that omit capabilities returns 200', function () {
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
        ->assertJsonPath('data.devices', $payload['devices']);
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
// No persistence (P04 scope boundary — P05 owns upsert)
// ─────────────────────────────────────────────────────────────────────────────

test('valid reported devices payload does not change devices table row count', function () {
    $user = rdsUser('fb-rds-no-write');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    $before = DB::table('devices')->count();

    rdsAuth($user);

    $this->postJson(reportedDevicesSyncUrl($conn), validReportedDevicesPayload(), rdsHeaders())
        ->assertOk();

    expect(DB::table('devices')->count())->toBe($before);
});
