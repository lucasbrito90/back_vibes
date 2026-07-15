<?php

declare(strict_types=1);

namespace App\Telemetry\Noop;

use App\Telemetry\Contracts\LoggerCorrelation;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\TelemetryManager;
use App\Telemetry\Contracts\Tracer;

/**
 * Bound by TelemetryServiceProvider instead of OpenTelemetryManager whenever
 * telemetry.enabled is false (OTEL_SDK_DISABLED=true) or the OpenTelemetry
 * implementation fails to construct — see backend-sdk-foundation.md
 * §"Future compatibility". Every method is a safe, allocation-free no-op;
 * this class never imports anything from App\Telemetry\OpenTelemetry or the
 * OpenTelemetry SDK.
 */
final class NoopTelemetryManager implements TelemetryManager
{
    public function tracer(): Tracer
    {
        return new NoopTracer;
    }

    public function meter(): Meter
    {
        return new NoopMeter;
    }

    public function loggerCorrelation(): LoggerCorrelation
    {
        return new NoopLoggerCorrelation;
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function flush(): bool
    {
        return true;
    }
}
