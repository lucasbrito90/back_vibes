<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\DeviceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runDeviceTypeBackfillMigration(): void
{
    $migration = require database_path('migrations/2026_09_03_000001_backfill_device_types_to_ixora_vocabulary.php');
    $migration->up();
}

test('backfill migration converts legacy HA domain types to IXORA vocabulary', function () {
    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    $legacyTypes = [
        'light' => DeviceType::Lighting->value,
        'switch' => DeviceType::Switchable->value,
        'media_player' => DeviceType::Media->value,
        'fan' => DeviceType::Ventilation->value,
    ];

    foreach ($legacyTypes as $legacy => $expected) {
        Device::factory()->create([
            'user_id' => $user->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'provider_device_id' => "entity.{$legacy}",
            'type' => $legacy,
        ]);
    }

    Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => 'cover.garage',
        'type' => 'cover',
    ]);

    $nullTypeDevice = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => 'manual.no_type',
        'type' => null,
    ]);

    expect(DB::table('devices')->whereIn('type', array_keys($legacyTypes))->count())->toBe(4);

    runDeviceTypeBackfillMigration();

    foreach ($legacyTypes as $legacy => $expected) {
        $device = Device::where('provider_device_id', "entity.{$legacy}")->first();

        expect($device)->not->toBeNull()
            ->and($device->type)->toBe($expected)
            ->and($device->type)->not->toBe($legacy);
    }

    expect(Device::where('provider_device_id', 'cover.garage')->first()->type)
        ->toBe(DeviceType::Other->value);

    expect($nullTypeDevice->fresh()->type)->toBeNull();

    expect(DB::table('devices')->whereIn('type', array_keys($legacyTypes))->count())->toBe(0)
        ->and(DB::table('devices')->whereNull('type')->count())->toBe(1);
});

test('backfill migration is idempotent for already-normalised types', function () {
    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
        'provider_device_id' => 'light.already_normalised',
        'type' => DeviceType::Lighting->value,
    ]);

    runDeviceTypeBackfillMigration();
    runDeviceTypeBackfillMigration();

    expect(Device::where('provider_device_id', 'light.already_normalised')->first()->type)
        ->toBe(DeviceType::Lighting->value);
});
