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
 *
 * activeSpan() was added in Phase 7B.1 (documented blocker — see
 * backend-http-routing-instrumentation.md §"Tracer::activeSpan() — a
 * documented, additive contract change"): the HTTP boundary must enrich the
 * span already started by opentelemetry-auto-laravel, not create a second
 * one, and the Phase 7A contract had no way to reach an ambient span — only
 * to start a new one or read its raw trace/span id. This is a pure addition;
 * no existing method signature changed.
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
     * A handle to the currently active span (e.g. the HTTP server span
     * started by auto-instrumentation) for attribute-only enrichment —
     * never null, falls back to a safe no-op Span when no span is active or
     * telemetry is disabled, exactly like startSpan().
     *
     * The caller never owns this span's lifecycle: end() on the returned
     * handle is guaranteed to be a no-op. Only the code that originally
     * started the span (auto-instrumentation, or a prior startSpan() call)
     * may end it.
     */
    public function activeSpan(): Span;

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
