<?php

use App\Telemetry\Contracts\TelemetryManager;
use App\Telemetry\Providers\TelemetryServiceProvider;
use OpenTelemetry\API\Globals;

test('TelemetryServiceProvider is registered', function () {
    expect(app()->getLoadedProviders())->toHaveKey(TelemetryServiceProvider::class);
});

test('the OpenTelemetry Globals accessors never throw, whether or not the SDK autoloader ran', function () {
    Globals::tracerProvider();
    Globals::meterProvider();
    Globals::propagator();
})->throwsNoExceptions();

test('TelemetryManager::flush() completes without throwing and returns a boolean', function () {
    $result = app(TelemetryManager::class)->flush();

    expect($result)->toBeBool();
});

test('the application boots successfully regardless of telemetry.enabled', function (bool $enabled) {
    config(['telemetry.enabled' => $enabled]);
    app()->forgetInstance(TelemetryManager::class);

    expect(fn () => app(TelemetryManager::class))->not->toThrow(Throwable::class);
})->with([true, false]);
