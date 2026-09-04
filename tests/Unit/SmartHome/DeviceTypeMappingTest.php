<?php

declare(strict_types=1);

use App\SmartHome\Adapters\HomeAssistantAdapter;
use App\SmartHome\DeviceType;
use App\Telemetry\SmartHome\SmartHomeProviderTelemetry;
use Tests\TestCase;

uses(TestCase::class);

function haAdapterForTypeMapping(): HomeAssistantAdapter
{
    return new HomeAssistantAdapter(app(SmartHomeProviderTelemetry::class));
}

function mapHaDeviceType(string $domain): DeviceType
{
    $method = new ReflectionMethod(HomeAssistantAdapter::class, 'mapDeviceType');
    $method->setAccessible(true);

    return $method->invoke(haAdapterForTypeMapping(), $domain);
}

test('mapDeviceType maps the four known HA domains to IXORA DeviceType cases', function () {
    expect(mapHaDeviceType('light'))->toBe(DeviceType::Lighting)
        ->and(mapHaDeviceType('switch'))->toBe(DeviceType::Switchable)
        ->and(mapHaDeviceType('media_player'))->toBe(DeviceType::Media)
        ->and(mapHaDeviceType('fan'))->toBe(DeviceType::Ventilation);
});

test('mapDeviceType falls back to Other for an unrecognised HA domain', function () {
    expect(mapHaDeviceType('climate'))->toBe(DeviceType::Other)
        ->and(mapHaDeviceType('cover'))->toBe(DeviceType::Other)
        ->and(mapHaDeviceType('anything-unrecognised'))->toBe(DeviceType::Other);
});

test('mapDeviceType never returns a raw HA domain string as the enum value', function () {
    foreach (['light', 'switch', 'media_player', 'fan', 'climate'] as $domain) {
        $mapped = mapHaDeviceType($domain);

        expect($mapped->value)->not->toBe($domain);
    }
});

test('DeviceType values are distinct from Home Assistant domain slugs', function () {
    $haDomains = ['light', 'switch', 'media_player', 'fan'];

    foreach (DeviceType::values() as $ixoraType) {
        expect($ixoraType)->not->toBeIn($haDomains);
    }
});
