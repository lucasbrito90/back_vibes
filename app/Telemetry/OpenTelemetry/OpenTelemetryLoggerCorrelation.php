<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry;

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\LoggerCorrelation;
use OpenTelemetry\API\Trace\Span;

/**
 * Reads whatever span is currently active — created either by an
 * auto-instrumentation hook (Phase 7A) or by domain code through the
 * Tracer contract (Phase 7B onward) — and exposes it for Laravel log
 * correlation (logs-philosophy.md, ADR-029).
 *
 * Never creates a span. Never throws: an invalid/missing span context
 * simply yields an empty array (telemetry-availability-policy.md).
 */
final class OpenTelemetryLoggerCorrelation implements LoggerCorrelation
{
    public function current(): array
    {
        return $this->context()?->toArray() ?? [];
    }

    public function context(): ?TraceContext
    {
        $context = Span::getCurrent()->getContext();

        if (! $context->isValid()) {
            return null;
        }

        return new TraceContext(
            traceId: $context->getTraceId(),
            spanId: $context->getSpanId(),
            isSampled: $context->isSampled(),
        );
    }

    public function traceId(): ?string
    {
        return $this->context()?->traceId;
    }

    public function spanId(): ?string
    {
        return $this->context()?->spanId;
    }
}
