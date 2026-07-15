<?php

declare(strict_types=1);

namespace App\Telemetry\Context;

/**
 * Immutable, vendor-neutral snapshot of the active trace identifiers.
 *
 * This is the only "current span" value type domain code and the logging
 * correlation layer ever see — never an OpenTelemetry SpanContext. Kept in
 * its own namespace (sibling of Contracts/ and OpenTelemetry/) because both
 * Tracer and LoggerCorrelation return it (telemetry-naming-convention.md §2).
 */
final class TraceContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly bool $isSampled,
    ) {}

    /**
     * @return array{trace_id: string, span_id: string}
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
        ];
    }
}
