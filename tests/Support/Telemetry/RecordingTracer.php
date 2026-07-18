<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;

final class RecordingTracer implements Tracer
{
    private readonly RecordingActiveSpan $activeSpan;

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

        $span = new RecordingActiveSpan($this->recorder);
        $span->setAttributes($attributes);

        return $span;
    }

    public function activeSpan(): Span
    {
        return $this->activeSpan;
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
