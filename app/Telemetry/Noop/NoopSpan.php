<?php

declare(strict_types=1);

namespace App\Telemetry\Noop;

use App\Telemetry\Contracts\Span;
use Throwable;

/**
 * Does nothing. Bound whenever telemetry is disabled (config('telemetry.enabled')
 * === false) or when the OpenTelemetry implementation could not resolve a
 * live span, so domain code that starts a span never has to branch on
 * whether telemetry is actually active (telemetry-availability-policy.md).
 */
final class NoopSpan implements Span
{
    public function setAttribute(string $key, $value): static
    {
        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        return $this;
    }

    public function addEvent(string $name, array $attributes = []): static
    {
        return $this;
    }

    public function recordException(Throwable $exception): static
    {
        return $this;
    }

    public function setError(?string $description = null): static
    {
        return $this;
    }

    public function end(): void {}
}
