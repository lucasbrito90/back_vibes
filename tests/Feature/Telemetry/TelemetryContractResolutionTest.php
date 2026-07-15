<?php

use App\Telemetry\Contracts\LoggerCorrelation;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\TelemetryManager;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Noop\NoopLoggerCorrelation;
use App\Telemetry\Noop\NoopMeter;
use App\Telemetry\Noop\NoopTelemetryManager;
use App\Telemetry\Noop\NoopTracer;
use App\Telemetry\OpenTelemetry\OpenTelemetryLoggerCorrelation;
use App\Telemetry\OpenTelemetry\OpenTelemetryManager;
use App\Telemetry\OpenTelemetry\OpenTelemetryMeter;
use App\Telemetry\OpenTelemetry\OpenTelemetryTracer;

test('TelemetryManager contract resolves to the OpenTelemetry implementation by default', function () {
    expect(app(TelemetryManager::class))->toBeInstanceOf(OpenTelemetryManager::class);
});

test('Tracer, Meter, and LoggerCorrelation contracts resolve to their OpenTelemetry implementations', function () {
    expect(app(Tracer::class))->toBeInstanceOf(OpenTelemetryTracer::class)
        ->and(app(Meter::class))->toBeInstanceOf(OpenTelemetryMeter::class)
        ->and(app(LoggerCorrelation::class))->toBeInstanceOf(OpenTelemetryLoggerCorrelation::class);
});

test('TelemetryManager binds to the Noop implementation when telemetry is disabled', function () {
    config(['telemetry.enabled' => false]);
    app()->forgetInstance(TelemetryManager::class);

    $manager = app(TelemetryManager::class);

    expect($manager)->toBeInstanceOf(NoopTelemetryManager::class)
        ->and($manager->isEnabled())->toBeFalse()
        ->and($manager->tracer())->toBeInstanceOf(NoopTracer::class)
        ->and($manager->meter())->toBeInstanceOf(NoopMeter::class)
        ->and($manager->loggerCorrelation())->toBeInstanceOf(NoopLoggerCorrelation::class);
});

test('TelemetryManager is a singleton — the same instance is returned on every resolution', function () {
    expect(app(TelemetryManager::class))->toBe(app(TelemetryManager::class));
});

test('starting and ending a span never throws, with or without a live SDK export target', function () {
    $tracer = app(Tracer::class);

    $span = $tracer->startSpan('telemetry.test.span', ['test.attribute' => 'value']);
    $span->setAttribute('another', 1)->addEvent('checkpoint')->setError('example');
    $span->end();
})->throwsNoExceptions();

test('Noop implementations never throw for any contract method', function () {
    $manager = new NoopTelemetryManager;

    $span = $manager->tracer()->startSpan('x');
    $span->setAttribute('a', 1)->setAttributes(['b' => 2])->addEvent('e')->recordException(new Exception('boom'))->setError('bad');
    $span->end();

    $manager->meter()->counter('c')->add(1);
    $manager->meter()->histogram('h')->record(1.0);
    $manager->meter()->upDownCounter('u')->add(-1);

    expect($manager->loggerCorrelation()->current())->toBe([])
        ->and($manager->flush())->toBeTrue()
        ->and($manager->isEnabled())->toBeFalse();
});
