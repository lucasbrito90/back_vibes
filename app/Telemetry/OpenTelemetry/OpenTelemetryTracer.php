<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry;

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Noop\NoopSpan;
use OpenTelemetry\API\Trace\Span as ApiSpan;
use OpenTelemetry\API\Trace\TracerInterface;

/**
 * Wraps an OpenTelemetry SDK TracerInterface. Resolved lazily from
 * Globals::tracerProvider() by OpenTelemetryManager so that spans created
 * here nest under the same trace as spans created by the auto-instrumentation
 * packages (backend-sdk-foundation.md §"Two integration points").
 */
final class OpenTelemetryTracer implements Tracer
{
    public function __construct(private readonly TracerInterface $tracer) {}

    public function startSpan(string $name, array $attributes = []): Span
    {
        $span = $this->tracer->spanBuilder($name)
            ->setAttributes($attributes)
            ->startSpan();

        $scope = $span->activate();

        return new OpenTelemetrySpan($span, $scope);
    }

    public function activeSpan(): Span
    {
        $span = ApiSpan::getCurrent();

        if (! $span->getContext()->isValid()) {
            return new NoopSpan;
        }

        return new OpenTelemetryActiveSpan($span);
    }

    public function currentContext(): ?TraceContext
    {
        $context = ApiSpan::getCurrent()->getContext();

        if (! $context->isValid()) {
            return null;
        }

        return new TraceContext(
            traceId: $context->getTraceId(),
            spanId: $context->getSpanId(),
            isSampled: $context->isSampled(),
        );
    }

    public function currentTraceId(): ?string
    {
        return $this->currentContext()?->traceId;
    }

    public function currentSpanId(): ?string
    {
        return $this->currentContext()?->spanId;
    }
}
