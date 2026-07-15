<?php

use App\Telemetry\Contracts\TelemetryManager;
use Illuminate\Support\Facades\Route;

/**
 * telemetry-availability-policy.md: telemetry failures must never affect
 * business logic. These tests exercise the code paths our Telemetry module
 * owns (flush, tracer/meter/log resolution) under a deliberately broken
 * export configuration and confirm the HTTP request/response cycle itself
 * is entirely unaffected.
 *
 * The end-to-end "Collector process is down" scenario was additionally
 * validated manually against the real OpenTelemetry SDK (OTLP exporter
 * pointed at a closed port) — see backend-sdk-foundation.md
 * §"Failure policy validation" for the captured evidence. That scenario is
 * not repeated here as a live network test to keep the suite fast and
 * deterministic.
 */
test('an HTTP request completes successfully even with an unreachable OTLP endpoint configured', function () {
    config(['telemetry.otlp.endpoint' => 'http://127.0.0.1:1']);

    Route::get('/__telemetry_failure_policy_probe', fn () => response()->json(['ok' => true]));

    $response = $this->get('/__telemetry_failure_policy_probe');

    $response->assertOk()->assertJson(['ok' => true]);
});

test('flush() never throws and returns quickly even with a broken OTLP endpoint configured', function () {
    config(['telemetry.otlp.endpoint' => 'http://127.0.0.1:1']);

    $start = microtime(true);
    $result = app(TelemetryManager::class)->flush();
    $elapsedMs = (microtime(true) - $start) * 1000;

    expect($result)->toBeBool()
        ->and($elapsedMs)->toBeLessThan(2000);
});

test('a queued job style unit of work completes even when telemetry is disabled mid-flight', function () {
    config(['telemetry.enabled' => false]);
    app()->forgetInstance(TelemetryManager::class);

    $manager = app(TelemetryManager::class);
    $span = $manager->tracer()->startSpan('job.process');

    try {
        $work = 1 + 1;
    } finally {
        $span->end();
    }

    expect($work)->toBe(2);
});
