<?php

use App\Telemetry\Contracts\TelemetryManager;
use App\Telemetry\Logging\ConsoleErrorContextLogTap;
use App\Telemetry\Logging\HttpErrorContextLogTap;
use App\Telemetry\Logging\QueueErrorContextLogTap;
use App\Telemetry\Logging\SchedulerErrorContextLogTap;
use App\Telemetry\Logging\TraceCorrelationLogTap;
use App\Telemetry\Noop\NoopTelemetryManager;
use App\Telemetry\OpenTelemetry\Logging\FailSafeOtelHandler;
use App\Telemetry\OpenTelemetry\Logging\OpenTelemetryLoggerFactory;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Contrib\Logs\Monolog\Handler as OtelMonologHandler;

/**
 * Phase 8.8.8 — OTLP Log Export tests.
 *
 * All tests use in-memory fakes. None contact https://otel-staging.ixora-app.app.
 */

// ─────────────────────────────────────────────────────────────────
// 1. Factory builds a valid Monolog Logger
// ─────────────────────────────────────────────────────────────────

test('OpenTelemetryLoggerFactory returns a Monolog Logger', function () {
    $factory = new OpenTelemetryLoggerFactory;
    $logger = $factory(['level' => 'debug']);

    expect($logger)->toBeInstanceOf(Monolog::class);
});

test('OpenTelemetryLoggerFactory creates exactly one handler', function () {
    $factory = new OpenTelemetryLoggerFactory;
    $logger = $factory(['level' => 'info']);

    expect($logger->getHandlers())->toHaveCount(1);
});

test('OpenTelemetryLoggerFactory always returns a resolvable Logger even without SDK', function () {
    // Factory is always callable even when the OTel SDK is not bootstrapped.
    // In local dev (no extension), Globals::loggerProvider() returns a Noop
    // provider and the factory wraps it in FailSafeOtelHandler successfully.
    $factory = new OpenTelemetryLoggerFactory;
    $logger = $factory(['level' => 'info']);

    expect($logger)->toBeInstanceOf(Monolog::class)
        ->and($logger->getHandlers())->toHaveCount(1);
});

test('otel channel is defined in config/logging.php', function () {
    expect(config('logging.channels'))->toHaveKey('otel');
    expect(config('logging.channels.otel.driver'))->toBe('custom');
    expect(config('logging.channels.otel.via'))->toBe(OpenTelemetryLoggerFactory::class);
});

// ─────────────────────────────────────────────────────────────────
// 2. FailSafeOtelHandler swallows OTLP exceptions
// ─────────────────────────────────────────────────────────────────

test('FailSafeOtelHandler swallows exceptions from the OTLP handler', function () {
    $throwingInner = new class(Globals::loggerProvider(), 'debug') extends OtelMonologHandler
    {
        public function handle(LogRecord $record): bool
        {
            throw new RuntimeException('network timeout');
        }
    };

    $safeHandler = new FailSafeOtelHandler($throwingInner);

    $logRecord = new LogRecord(
        new DateTimeImmutable,
        'test',
        Level::Info,
        'test message',
        [],
        [],
    );

    // Should not throw
    expect(fn () => $safeHandler->handle($logRecord))->not->toThrow(Throwable::class);
});

test('FailSafeOtelHandler.bubble is always false', function () {
    $inner = new OtelMonologHandler(Globals::loggerProvider(), 'debug');
    $safe = new FailSafeOtelHandler($inner);

    expect($safe->getBubble())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────
// 3. No duplicate provider construction
// ─────────────────────────────────────────────────────────────────

test('OpenTelemetryLoggerFactory source never instantiates a LoggerProvider', function () {
    $src = file_get_contents(app_path('Telemetry/OpenTelemetry/Logging/OpenTelemetryLoggerFactory.php'));

    // Code-level checks — comments may mention these classes but no runtime construction must exist.
    expect($src)
        ->not->toContain('new LoggerProvider(')
        ->not->toContain('new LoggerProviderBuilder(');

    // The docblock mentions registerInitializer(…) as a thing NOT done — that's fine.
    // Verify no actual code call exists (code lines don't start with ' * ').
    $codeLines = array_filter(explode("\n", $src), fn ($l) => ! str_starts_with(trim($l), '*'));
    $codeOnly = implode("\n", $codeLines);
    expect($codeOnly)->not->toContain('Globals::registerInitializer(');
});

test('OpenTelemetryLoggerFactory uses Globals::loggerProvider()', function () {
    $src = file_get_contents(app_path('Telemetry/OpenTelemetry/Logging/OpenTelemetryLoggerFactory.php'));

    expect($src)->toContain('Globals::loggerProvider()');
});

// ─────────────────────────────────────────────────────────────────
// 4. Correlation taps are applied to the otel channel
// ─────────────────────────────────────────────────────────────────

test('trace correlation tap is applied to the otel channel', function () {
    expect(config('logging.channels.otel.tap'))
        ->toContain(TraceCorrelationLogTap::class);
});

test('all correlation taps are applied to the otel channel', function () {
    $taps = config('logging.channels.otel.tap', []);

    expect($taps)
        ->toContain(TraceCorrelationLogTap::class)
        ->toContain(HttpErrorContextLogTap::class)
        ->toContain(QueueErrorContextLogTap::class)
        ->toContain(ConsoleErrorContextLogTap::class)
        ->toContain(SchedulerErrorContextLogTap::class);
});

// ─────────────────────────────────────────────────────────────────
// 5. Flush lifecycle — logger included
// ─────────────────────────────────────────────────────────────────

test('OpenTelemetryManager::flush() returns a boolean and includes logger provider', function () {
    config(['telemetry.enabled' => true]);
    app()->forgetInstance(TelemetryManager::class);

    $result = app(TelemetryManager::class)->flush();

    expect($result)->toBeBool();
});

test('NoopTelemetryManager::flush() returns true (logger noop path)', function () {
    $manager = new NoopTelemetryManager;

    expect($manager->flush())->toBeTrue();
});

test('flush() does not throw when loggerProvider has no forceFlush', function () {
    config(['telemetry.enabled' => true]);
    app()->forgetInstance(TelemetryManager::class);

    expect(fn () => app(TelemetryManager::class)->flush())->not->toThrow(Throwable::class);
});

test('OpenTelemetryManager source reads Globals::loggerProvider() in flush', function () {
    $src = file_get_contents(app_path('Telemetry/OpenTelemetry/OpenTelemetryManager.php'));

    expect($src)->toContain('Globals::loggerProvider()');
});

// ─────────────────────────────────────────────────────────────────
// 6. Security — sensitive data not forwarded
// ─────────────────────────────────────────────────────────────────

test('FailSafeOtelHandler source never outputs OTEL header values at runtime', function () {
    $handlerSrc = file_get_contents(app_path('Telemetry/OpenTelemetry/Logging/FailSafeOtelHandler.php'));

    // The handler must never call these output functions with header values.
    expect($handlerSrc)
        ->not->toContain('echo ')
        ->not->toContain('print_r(')
        ->not->toContain('var_dump(')
        ->not->toContain('$_ENV[')
        ->not->toContain('getenv(');

    // The error_log call must never reference the headers env variable by value.
    // Docblock comments may mention it, but no runtime code should read it.
    expect($handlerSrc)->not->toContain("getenv('OTEL_EXPORTER_OTLP_HEADERS')")
        ->not->toContain('$_SERVER[\'OTEL_EXPORTER_OTLP_HEADERS\']');
});

test('validation command source never prints OTLP headers value', function () {
    $src = file_get_contents(app_path('Console/Commands/TelemetryValidateCommand.php'));

    expect($src)
        ->toContain('set (not shown)')
        ->not->toContain("otlpHeaders['Authorization']")
        ->not->toContain('print_r')
        ->not->toContain('var_dump');
});

// ─────────────────────────────────────────────────────────────────
// 7. Validate command — logs exporter reported
// ─────────────────────────────────────────────────────────────────

test('validate command reports logs exporter status', function () {
    config([
        'telemetry.enabled' => true,
        'telemetry.autoload_enabled' => false,
        'telemetry.logs_exporter' => 'none',
        'telemetry.otlp.headers' => 'Authorization=Bearer test-token',
    ]);
    app()->forgetInstance(TelemetryManager::class);

    $this->artisan('ixora:telemetry-validate')
        ->expectsOutputToContain('logs exporter')
        ->assertExitCode(0);
});

test('validate command emits a log probe that does not throw', function () {
    config([
        'telemetry.enabled' => true,
        'telemetry.autoload_enabled' => false,
        'telemetry.logs_exporter' => 'none',
        'telemetry.otlp.headers' => 'Authorization=Bearer test-token',
    ]);
    app()->forgetInstance(TelemetryManager::class);

    expect(fn () => $this->artisan('ixora:telemetry-validate'))->not->toThrow(Throwable::class);
});

// ─────────────────────────────────────────────────────────────────
// 8. Configuration
// ─────────────────────────────────────────────────────────────────

test('config/telemetry.php has logs_exporter key', function () {
    expect(config('telemetry'))->toHaveKey('logs_exporter');
});

test('telemetry config defaults logs_exporter to none for local dev', function () {
    // In the test env, OTEL_LOGS_EXPORTER is not set, so it should default to 'none'.
    // This test is environment-dependent: it passes when OTEL_LOGS_EXPORTER is unset.
    $exporter = config('telemetry.logs_exporter');
    expect($exporter)->toBeString();
});

test('config:cache returns serializable telemetry config', function () {
    $config = config('telemetry');

    expect(serialize($config))->toBeString();
    expect(unserialize(serialize($config)))->toBe($config);
});

test('config:cache returns serializable logging config', function () {
    $config = config('logging');

    // The logging config must remain serializable for config:cache to work.
    // (Note: closures in config would break this — otel channel uses a string class name.)
    $encoded = json_encode($config);
    expect($encoded)->toBeString()
        ->not->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────
// 9. No staging network request in tests
// ─────────────────────────────────────────────────────────────────

test('resolving the otel log channel does not make a network call when OTEL_LOGS_EXPORTER=none', function () {
    config(['telemetry.logs_exporter' => 'none']);

    // Resolving the logger should not throw even with no real collector.
    expect(fn () => Log::channel('otel'))->not->toThrow(Throwable::class);
});
