<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;

final class RecordingTracer implements Tracer
{
    private readonly RecordingActiveSpan $activeSpan;

    private ?TraceContext $startedSpanContext = null;

    public function __construct(private readonly TelemetryRecorder $recorder)
    {
        $this->activeSpan = new RecordingActiveSpan($recorder);
    }

    /**
     * Returns a distinct RecordingActiveSpan per call (a real Tracer starts
     * a genuinely new span every time) that still records into the same
     * shared recorder — added in Phase 7B.3 for SchedulerExecutionTelemetry,
     * the first caller of startSpan() in this Telemetry Abstraction Layer.
     */
    public function startSpan(string $name, array $attributes = []): Span
    {
        $this->recorder->recordStartSpan($name, $attributes);

        $this->startedSpanContext = new TraceContext(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: '00f067aa0ba902b7',
            isSampled: true,
        );

        $span = new RecordingStartedSpan($this->recorder, $this);
        $span->setAttributes($attributes);

        return $span;
    }

    public function activeSpan(): Span
    {
        return $this->activeSpan;
    }

    public function currentContext(): ?TraceContext
    {
        return $this->startedSpanContext;
    }

    public function currentTraceId(): ?string
    {
        return $this->startedSpanContext?->traceId;
    }

    public function currentSpanId(): ?string
    {
        return $this->startedSpanContext?->spanId;
    }

    public function clearStartedSpanContext(): void
    {
        $this->startedSpanContext = null;
    }
}
