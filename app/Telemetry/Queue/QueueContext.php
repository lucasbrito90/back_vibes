<?php

declare(strict_types=1);

namespace App\Telemetry\Queue;

/**
 * Normalized, bounded snapshot of the job currently being processed —
 * captured once at JobProcessing time and read by both
 * QueueExecutionTelemetry (metrics/span enrichment) and
 * App\Telemetry\Logging\QueueErrorContextLogTap (safe error-log context).
 *
 * Deliberately holds no domain-specific data, payload, job ID, or UUID —
 * see backend-queue-console-instrumentation.md §"Cardinality safety".
 */
final class QueueContext
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly string $jobName,
        public readonly int $attempt,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toLogContext(): array
    {
        return [
            'queue' => $this->queue,
            'connection' => $this->connection,
            'job_name' => $this->jobName,
            'attempt' => $this->attempt,
        ];
    }
}
