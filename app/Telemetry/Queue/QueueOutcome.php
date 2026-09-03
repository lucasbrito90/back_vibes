<?php

declare(strict_types=1);

namespace App\Telemetry\Queue;

/**
 * Bounded classification of a single queue job attempt's result, used as
 * the `outcome` label on ixora.queue.job.* metrics and as the
 * `ixora.queue.outcome` span attribute (backend-queue-console-
 * instrumentation.md §"Metrics"). Only includes outcomes that can be
 * determined reliably from a Laravel queue event — see
 * QueueExecutionTelemetry for exactly which event maps to which case.
 *
 * `Cancelled` is part of the bounded set for forward compatibility (mirrors
 * App\Telemetry\Http\HttpOutcome::Cancelled) but is never produced today —
 * Laravel has no dedicated "job cancelled" event distinct from a normal
 * delete/release; a batch-cancelled job that checks `$this->batch()
 * ?->cancelled()` and returns early still just fires JobProcessed.
 */
enum QueueOutcome: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Released = 'released';
    case Retried = 'retried';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
