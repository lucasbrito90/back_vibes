<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use App\Telemetry\Contracts\Span;
use Throwable;

/**
 * Stands in for the ambient span App\Telemetry\OpenTelemetry\OpenTelemetryActiveSpan
 * would wrap in production. end() is intentionally a no-op AND recorded —
 * tests assert it is never called, since HttpRequestTelemetry never owns
 * this span's lifecycle.
 */
final class RecordingActiveSpan implements Span
{
    public function __construct(private readonly TelemetryRecorder $recorder) {}

    public function setAttribute(string $key, $value): static
    {
        $this->recorder->recordSpanAttributes([$key => $value]);

        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        $this->recorder->recordSpanAttributes($attributes);

        return $this;
    }

    public function addEvent(string $name, array $attributes = []): static
    {
        return $this;
    }

    public function recordException(Throwable $exception): static
    {
        $this->recorder->spanExceptions[] = $exception;

        return $this;
    }

    public function setError(?string $description = null): static
    {
        $this->recorder->spanErrorCalls++;

        return $this;
    }

    public function end(): void
    {
        $this->recorder->spanEndCalls++;
    }
}
