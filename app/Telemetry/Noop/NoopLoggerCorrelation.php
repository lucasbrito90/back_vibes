<?php

declare(strict_types=1);

namespace App\Telemetry\Noop;

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\LoggerCorrelation;

/**
 * Always reports "no active span" — used when telemetry is disabled so the
 * logging processor adds nothing to the log context instead of failing
 * (logs-philosophy.md, telemetry-availability-policy.md).
 */
final class NoopLoggerCorrelation implements LoggerCorrelation
{
    public function current(): array
    {
        return [];
    }

    public function context(): ?TraceContext
    {
        return null;
    }

    public function traceId(): ?string
    {
        return null;
    }

    public function spanId(): ?string
    {
        return null;
    }
}
