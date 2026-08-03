<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry;

use App\Telemetry\Contracts\LoggerCorrelation;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\TelemetryManager;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Noop\NoopMeter;
use App\Telemetry\Noop\NoopTracer;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\SdkAutoloader;
use Throwable;

/**
 * Concrete TelemetryManager backed by the OpenTelemetry SDK.
 *
 * IMPORTANT — this class does NOT build or register the TracerProvider /
 * MeterProvider itself. The SDK's own {@see SdkAutoloader}
 * already does that from raw OTEL_* environment variables at Composer
 * autoload time (composer.json "files" autoload, gated by
 * OTEL_PHP_AUTOLOAD_ENABLED=true) — this is the officially documented,
 * race-free bootstrap path and is also what every auto-instrumentation
 * package (opentelemetry-auto-laravel, -guzzle, -pdo) relies on for the
 * exact same providers. Building our own providers here and registering
 * them a second time via Globals::registerInitializer() at Laravel's
 * (much later) service-provider-boot time would either be ignored or race
 * with the auto-instrumentation hooks, which read Globals directly and can
 * fire before Laravel finishes booting (see backend-sdk-foundation.md
 * §"SDK bootstrap timing").
 *
 * This class therefore only ever *reads* Globals::tracerProvider() /
 * Globals::meterProvider() / Globals::loggerProvider() — whatever they
 * resolve to (real exporting providers when the autoloader ran, or the SDK's
 * own Noop providers when it did not) is accepted as-is. Every read is wrapped in try/catch so that a
 * misbehaving Global (or the absence of the SDK autoloader entirely) can
 * never surface as an application error (telemetry-availability-policy.md).
 */
final class OpenTelemetryManager implements TelemetryManager
{
    private const INSTRUMENTATION_SCOPE = 'ixora.telemetry';

    private const INSTRUMENTATION_SCOPE_VERSION = '1.0.0';

    public function __construct(private readonly bool $enabled) {}

    public function tracer(): Tracer
    {
        try {
            $provider = Globals::tracerProvider();

            return new OpenTelemetryTracer(
                $provider->getTracer(self::INSTRUMENTATION_SCOPE, self::INSTRUMENTATION_SCOPE_VERSION),
            );
        } catch (Throwable) {
            return new NoopTracer;
        }
    }

    public function meter(): Meter
    {
        try {
            $provider = Globals::meterProvider();

            return new OpenTelemetryMeter(
                $provider->getMeter(self::INSTRUMENTATION_SCOPE, self::INSTRUMENTATION_SCOPE_VERSION),
            );
        } catch (Throwable) {
            return new NoopMeter;
        }
    }

    public function loggerCorrelation(): LoggerCorrelation
    {
        return new OpenTelemetryLoggerCorrelation;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function flush(): bool
    {
        try {
            $tracerProvider = Globals::tracerProvider();
            $meterProvider = Globals::meterProvider();
            $loggerProvider = Globals::loggerProvider();
        } catch (Throwable) {
            return false;
        }

        $tracerFlushed = ! $tracerProvider instanceof TracerProviderInterface || $this->forceFlush($tracerProvider);
        $meterFlushed = ! $meterProvider instanceof MeterProviderInterface || $this->forceFlush($meterProvider);
        $loggerFlushed = ! $loggerProvider instanceof LoggerProviderInterface || $this->forceFlush($loggerProvider);

        return $tracerFlushed && $meterFlushed && $loggerFlushed;
    }

    /**
     * forceFlush() is only declared on the SDK provider classes, not on the
     * API interfaces (the Noop providers do not implement it) — guarded
     * with method_exists so a disabled/noop provider never throws.
     */
    private function forceFlush(object $provider): bool
    {
        if (! method_exists($provider, 'forceFlush')) {
            return true;
        }

        try {
            return (bool) $provider->forceFlush();
        } catch (Throwable) {
            return false;
        }
    }
}
