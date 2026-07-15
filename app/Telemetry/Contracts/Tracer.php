<?php

declare(strict_types=1);

namespace App\Telemetry\Contracts;

use App\Telemetry\Context\TraceContext;

/**
 * Vendor-neutral tracer contract. Domain code depends on this interface —
 * or on TelemetryManager::tracer() — and never on the OpenTelemetry SDK
 * directly (Dependency Rule, Phase 7A).
 *
 * Phase 7A registers no callers of startSpan() outside the auto-instrumentation
 * packages (which do not use this contract at all — see
 * backend-sdk-foundation.md §"Two integration points"). Phase 7B is the
 * first phase permitted to create domain spans through this contract.
 */
interface Tracer
{
    /**
     * Start a new span as a child of the current active span (if any) and
     * activate it as the current span for the duration of the returned handle.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function startSpan(string $name, array $attributes = []): Span;

    /**
     * The full trace context of the currently active span, or null when no
     * span is active in the current execution context.
     */
    public function currentContext(): ?TraceContext;

    /**
     * The trace_id of the currently active span, or null when no span is active.
     */
    public function currentTraceId(): ?string;

    /**
     * The span_id of the currently active span, or null when no span is active.
     */
    public function currentSpanId(): ?string;
}
