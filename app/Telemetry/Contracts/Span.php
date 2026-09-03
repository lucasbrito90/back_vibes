<?php

declare(strict_types=1);

namespace App\Telemetry\Contracts;

use Throwable;

/**
 * Handle for a single in-flight span started via Tracer::startSpan().
 *
 * Implementation rules:
 * - Callers MUST call end() exactly once, typically in a finally block.
 * - No method may throw for telemetry-related failures — a broken exporter
 *   or misconfigured Collector must never surface here
 *   (telemetry-availability-policy.md).
 *
 * Phase 7A creates no spans through this contract (no manual spans, no
 * domain instrumentation). Phase 7B is the first permitted caller.
 */
interface Span
{
    /**
     * @param  scalar|null  $value
     */
    public function setAttribute(string $key, $value): static;

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function setAttributes(array $attributes): static;

    /**
     * Named, timestamped annotation on the span (telemetry-naming-convention.md §10).
     * Prefer events over deeper span nesting when duration is not meaningful
     * (traces-philosophy.md §5).
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function addEvent(string $name, array $attributes = []): static;

    /**
     * Record an exception on the span without altering control flow — the
     * exception must still propagate normally after this call
     * (traces-philosophy.md §8).
     */
    public function recordException(Throwable $exception): static;

    /**
     * Mark the span as failed. Use for handled/expected error outcomes that
     * do not raise a Throwable (e.g. a validation failure surfaced as a 4xx).
     */
    public function setError(?string $description = null): static;

    /**
     * End the span and detach it from the active context.
     */
    public function end(): void;
}
