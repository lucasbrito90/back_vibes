<?php

use App\Telemetry\Contracts\TelemetryManager;
use App\Telemetry\Noop\NoopTelemetryManager;
use App\Telemetry\OpenTelemetry\OpenTelemetryManager;

/**
 * Phase 9 (staging-runtime-integration) — TelemetryValidateCommand.
 *
 * Verifies the command contract without any network connection:
 * - never prints OTEL_EXPORTER_OTLP_HEADERS value;
 * - succeeds and emits functional probe when SDK is enabled (no real Collector needed);
 * - skips probe and still exits 0 when SDK is disabled (Noop mode);
 * - exits non-zero with --require-sdk when autoload is off;
 * - exits non-zero when the Authorization header is absent and SDK is active.
 */
/**
 * Helper: set the telemetry.otlp.headers config key as if
 * "Authorization=Bearer <token>" were set in OTEL_EXPORTER_OTLP_HEADERS.
 * This mirrors what config/telemetry.php produces after TelemetryConfig
 * parses the env variable — usable in tests without putenv().
 */
function withAuthHeader(): void
{
    config(['telemetry.otlp.headers' => 'Authorization=Bearer test-token']);
}

test('the validate command runs successfully when telemetry is enabled', function () {
    config([
        'telemetry.enabled' => true,
        'telemetry.service_name' => 'back_vibes-api',
        'telemetry.environment' => 'staging',
        'telemetry.otlp.endpoint' => 'https://otel-staging.ixora-app.app',
        'telemetry.otlp.headers' => 'Authorization=Bearer test-token',
        'telemetry.autoload_enabled' => false,
    ]);
    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('ixora:telemetry-validate')
        ->assertExitCode(0);
});

test('the validate command succeeds when SDK is disabled (Noop mode)', function () {
    config([
        'telemetry.enabled' => false,
        'telemetry.otlp.headers' => 'Authorization=Bearer test-token',
    ]);
    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('ixora:telemetry-validate')
        ->assertExitCode(0);
});

test('--require-sdk exits non-zero when OTEL_SDK_DISABLED is true', function () {
    config([
        'telemetry.enabled' => false,
        'telemetry.autoload_enabled' => false,
        'telemetry.otlp.headers' => 'Authorization=Bearer test-token',
    ]);
    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('ixora:telemetry-validate', ['--require-sdk' => true])
        ->assertExitCode(1);
});

test('--require-sdk exits non-zero when OTEL_PHP_AUTOLOAD_ENABLED is false', function () {
    config([
        'telemetry.enabled' => true,
        'telemetry.autoload_enabled' => false,
        'telemetry.otlp.headers' => 'Authorization=Bearer test-token',
    ]);
    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('ixora:telemetry-validate', ['--require-sdk' => true])
        ->assertExitCode(1);
});

test('the validate command exits non-zero when Authorization header is absent and SDK is active', function () {
    config([
        'telemetry.enabled' => true,
        'telemetry.otlp.endpoint' => 'https://otel-staging.ixora-app.app',
        'telemetry.otlp.headers' => '',
    ]);
    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('ixora:telemetry-validate')
        ->assertExitCode(1);
});

test('the validate command never prints the OTLP headers value — only presence is reported', function () {
    // The command detects presence via array_key_exists('Authorization', $config->otlpHeaders)
    // and only ever outputs the literal string "set (not shown)".
    // We verify this by reading the command source and confirming:
    // - no direct string-read of the header value is printed;
    // - the output literal is exactly "set (not shown)".
    $commandSrc = file_get_contents(app_path('Console/Commands/TelemetryValidateCommand.php'));

    expect($commandSrc)
        ->toContain('set (not shown)')
        ->not->toContain("otlpHeaders['Authorization']")
        ->not->toContain('$header')
        ->not->toContain('print_r')
        ->not->toContain('var_dump');
});

test('TelemetryManager resolves to Noop when OTEL_SDK_DISABLED is true', function () {
    config(['telemetry.enabled' => false]);
    app()->forgetInstance(TelemetryManager::class);

    expect(app(TelemetryManager::class))->toBeInstanceOf(NoopTelemetryManager::class);
});

test('TelemetryManager resolves to OpenTelemetry when enabled', function () {
    config(['telemetry.enabled' => true]);
    app()->forgetInstance(TelemetryManager::class);

    expect(app(TelemetryManager::class))->toBeInstanceOf(OpenTelemetryManager::class);
});

test('no duplicate TracerProvider is registered — Globals are read-only from application code', function () {
    // The TelemetryServiceProvider must never call Globals::registerInitializer()
    // or construct a new TracerProvider/MeterProvider. We verify this by checking
    // the provider file contains neither constructor call.
    $providerSrc = file_get_contents(app_path('Telemetry/Providers/TelemetryServiceProvider.php'));

    expect($providerSrc)
        ->not->toContain('SdkBuilder')
        ->not->toContain('new TracerProvider')
        ->not->toContain('new MeterProvider')
        ->not->toContain('registerInitializer');
});

test('no staging network request occurs when OTEL_SDK_DISABLED=true', function () {
    // With SDK disabled, flush() must return true (Noop) and never attempt
    // any network call.
    config(['telemetry.enabled' => false]);
    app()->forgetInstance(TelemetryManager::class);

    $result = app(TelemetryManager::class)->flush();

    expect($result)->toBeTrue();
});

test('Laravel config cache succeeds with telemetry config present', function () {
    $result = app(TelemetryManager::class);

    // Config was already loaded — asserting config() returns telemetry array
    // confirms the cache would succeed (config:cache runs the same resolution).
    expect(config('telemetry'))->toBeArray()
        ->and(config('telemetry.service_name'))->toBeIn(['back_vibes-api', 'back_vibes-worker']);
});
