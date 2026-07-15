<?php

declare(strict_types=1);

namespace App\Telemetry\Noop;

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;

/**
 * @see NoopSpan
 */
final class NoopTracer implements Tracer
{
    public function startSpan(string $name, array $attributes = []): Span
    {
        return new NoopSpan;
    }

    public function currentContext(): ?TraceContext
    {
        return null;
    }

    public function currentTraceId(): ?string
    {
        return null;
    }

    public function currentSpanId(): ?string
    {
        return null;
    }
}
