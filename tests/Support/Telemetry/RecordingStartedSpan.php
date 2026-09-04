<?php

declare(strict_types=1);

namespace Tests\Support\Telemetry;

use App\Telemetry\Contracts\Span;
use Throwable;

/**
 * Span returned from RecordingTracer::startSpan() — clears active trace
 * context when ended, mirroring production scope detachment.
 *
 * Composes a RecordingActiveSpan (final — cannot be extended) and delegates
 * every Span method to it, so both classes keep the exact same recording
 * behaviour; only end() adds the context-clearing step.
 */
final class RecordingStartedSpan implements Span
{
    private readonly RecordingActiveSpan $delegate;

    public function __construct(
        RecordingActiveSpan|TelemetryRecorder $recorderOrDelegate,
        private readonly RecordingTracer $tracer,
    ) {
        $this->delegate = $recorderOrDelegate instanceof RecordingActiveSpan
            ? $recorderOrDelegate
            : new RecordingActiveSpan($recorderOrDelegate);
    }

    public function setAttribute(string $key, $value): static
    {
        $this->delegate->setAttribute($key, $value);

        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        $this->delegate->setAttributes($attributes);

        return $this;
    }

    public function addEvent(string $name, array $attributes = []): static
    {
        $this->delegate->addEvent($name, $attributes);

        return $this;
    }

    public function recordException(Throwable $exception): static
    {
        $this->delegate->recordException($exception);

        return $this;
    }

    public function setError(?string $description = null): static
    {
        $this->delegate->setError($description);

        return $this;
    }

    public function end(): void
    {
        $this->delegate->end();
        $this->tracer->clearStartedSpanContext();
    }
}
