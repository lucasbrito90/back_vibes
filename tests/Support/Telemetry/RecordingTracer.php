<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Noop\NoopSpan;

final class RecordingTracer implements Tracer
{
    private readonly RecordingActiveSpan $activeSpan;

    public function __construct(private readonly TelemetryRecorder $recorder)
    {
        $this->activeSpan = new RecordingActiveSpan($recorder);
    }

    public function startSpan(string $name, array $attributes = []): Span
    {
        return new NoopSpan;
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
