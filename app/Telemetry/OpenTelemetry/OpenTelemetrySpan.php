<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry;

use App\Telemetry\Contracts\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * Wraps an OpenTelemetry SDK SpanInterface + activation ScopeInterface. This
 * is the ONLY place a domain-facing Span implementation touches the SDK.
 */
final class OpenTelemetrySpan implements Span
{
    public function __construct(
        private readonly SpanInterface $span,
        private readonly ScopeInterface $scope,
    ) {}

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

    public function end(): void
    {
        $this->scope->detach();
        $this->span->end();
    }
}
