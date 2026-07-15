<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry;

use App\Telemetry\Contracts\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/**
 * Wraps the ambient OpenTelemetry SDK SpanInterface returned by
 * ApiSpan::getCurrent() — e.g. the HTTP server span started by
 * opentelemetry-auto-laravel — for attribute-only enrichment
 * (Tracer::activeSpan(), Phase 7B.1).
 *
 * Unlike OpenTelemetrySpan (which wraps a span + the ScopeInterface that
 * activated it), this class holds no ScopeInterface and end() is
 * deliberately a no-op: the caller never started this span and must never
 * be able to end or detach it, even by mistake.
 */
final class OpenTelemetryActiveSpan implements Span
{
    public function __construct(private readonly SpanInterface $span) {}

    public function setAttribute(string $key, $value): static
    {
        $this->span->setAttribute($key, $value);

        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        $this->span->setAttributes($attributes);

        return $this;
    }

    public function addEvent(string $name, array $attributes = []): static
    {
        $this->span->addEvent($name, $attributes);

        return $this;
    }

    public function recordException(Throwable $exception): static
    {
        $this->span->recordException($exception);
        $this->span->setStatus(StatusCode::STATUS_ERROR);

        return $this;
    }

    public function setError(?string $description = null): static
    {
        $this->span->setStatus(StatusCode::STATUS_ERROR, $description);

        return $this;
    }

    /**
     * No-op by design — this class never owns the span's lifecycle.
     */
    public function end(): void {}
}
