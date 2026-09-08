<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\DeviceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function capabilitiesMigration(): object
{
    return require database_path('migrations/2026_09_04_000001_add_capabilities_to_devices_table.php');
}

function dropCapabilitiesColumnIfPresent(): void
{
    if (Schema::hasColumn('devices', 'capabilities')) {
        Schema::table('devices', function ($table) {
            $table->dropColumn('capabilities');
        });
    }
}

/**
 * Insert device rows without a capabilities column (pre-T13 schema).
 *
 * @param  array<string, mixed>  $overrides
 */
function insertPreCapabilitiesDevice(User $user, ProviderConnection $connection, array $overrides = []): void
{
    $now = now();

    DB::table('devices')->insert(array_merge([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'name' => 'Pre-capabilities device',
        'type' => 'light',
        'provider' => $connection->provider,
        'provider_device_id' => 'light.pre_'.fake()->unique()->randomNumber(5),
        'status' => DeviceStatus::Unknown->value,
        'metadata' => json_encode(['domain' => 'light', 'supported_features' => 1]),
        'last_seen_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

test('capabilities migration adds nullable json column and rolls back cleanly', function () {
    dropCapabilitiesColumnIfPresent();

    expect(Schema::hasColumn('devices', 'capabilities'))->toBeFalse();

    $migration = capabilitiesMigration();
    $migration->up();

    expect(Schema::hasColumn('devices', 'capabilities'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('devices', 'capabilities'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('devices', 'capabilities'))->toBeTrue();
});

test('capabilities migration backfills pre-existing rows to null without inferring from metadata or type', function () {
    dropCapabilitiesColumnIfPresent();

    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    insertPreCapabilitiesDevice($user, $connection, [
        'provider_device_id' => 'light.kitchen',
        'type' => 'light',
        'metadata' => json_encode(['domain' => 'light', 'supported_features' => 1]),
    ]);

    insertPreCapabilitiesDevice($user, $connection, [
        'provider_device_id' => 'switch.hall',
        'type' => 'switch',
        'metadata' => json_encode(['domain' => 'switch']),
    ]);

    insertPreCapabilitiesDevice($user, $connection, [
        'provider_device_id' => 'media_player.lounge',
        'type' => 'media_player',
        'metadata' => json_encode(['domain' => 'media_player', 'supported_features' => 512]),
    ]);

    insertPreCapabilitiesDevice($user, $connection, [
        'provider_device_id' => 'manual.unknown',
        'type' => null,
        'metadata' => null,
    ]);

    $beforeCount = 4;

    expect(DB::table('devices')->count())->toBe($beforeCount)
        ->and(Schema::hasColumn('devices', 'capabilities'))->toBeFalse();

    capabilitiesMigration()->up();

    expect(DB::table('devices')->count())->toBe($beforeCount)
        ->and(DB::table('devices')->whereNull('capabilities')->count())->toBe($beforeCount)
        ->and(DB::table('devices')->whereNotNull('capabilities')->count())->toBe(0);

    $light = Device::where('provider_device_id', 'light.kitchen')->first();

    expect($light)->not->toBeNull()
        ->and($light->capabilities)->toBeNull()
        ->and($light->metadata)->toBe(['domain' => 'light', 'supported_features' => 1])
        ->and($light->type)->toBe('light');
});

test('capabilities migration backfill update is idempotent', function () {
    dropCapabilitiesColumnIfPresent();

    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);
    insertPreCapabilitiesDevice($user, $connection);
    insertPreCapabilitiesDevice($user, $connection);

    capabilitiesMigration()->up();

    DB::table('devices')->update(['capabilities' => null]);

    expect(DB::table('devices')->whereNull('capabilities')->count())->toBe(2);
});
