<?php

declare(strict_types=1);

namespace App\Telemetry\Contracts;

use App\Telemetry\Context\TraceContext;

/**
 * Reads the currently active trace context for log correlation
 * (logs-philosophy.md, ADR-029 correlation rule). Never creates spans —
 * only observes whatever span is already active (started by auto-
 * instrumentation today; by domain code from Phase 7B onward).
 *
 * Implementations MUST NOT throw and MUST NOT modify existing log messages —
 * only return structured context to merge alongside them.
 */
interface LoggerCorrelation
{
    /**
     * Structured context to merge into Laravel's log context, e.g.
     * ['trace_id' => '...', 'span_id' => '...']. Empty array when no span
     * is active — never null, never throws.
     *
     * @return array<string, string>
     */
    public function current(): array;

    public function context(): ?TraceContext;

    public function traceId(): ?string;

    public function spanId(): ?string;
}
