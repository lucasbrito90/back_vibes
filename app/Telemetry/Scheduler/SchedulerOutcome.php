<?php

declare(strict_types=1);

namespace App\Telemetry\Scheduler;

/**
 * Bounded classification of a single scheduled event's terminal result,
 * used as the `outcome` label on ixora.scheduler.event.* metrics and as
 * the `ixora.scheduler.outcome` span attribute (backend-generic-scheduler-
 * instrumentation.md §"Metrics"). Only includes outcomes
 * SchedulerExecutionTelemetry can determine from a reliable Laravel
 * signal — see that class for exactly which event/property maps to which
 * case.
 *
 * `OverlapPrevented` is populated from the public
 * Illuminate\Console\Scheduling\Event::$skippedBecauseOverlapping flag —
 * the *only* reliable signal Laravel exposes for this (Part 9 review: no
 * dedicated event fires for it; ScheduledTaskStarting/Finished still fire
 * normally around a no-op run() call). A generic Scheduler-level skip
 * (paused schedule, filtersPass()/rejects() callback, one-server-check
 * loss) never reaches SchedulerExecutionTelemetry with a *reason* — those
 * all surface as the same bounded `Skipped`, never guessed apart.
 *
 * `BackgroundCompleted` is used at ScheduledTaskFinished time for a
 * background event specifically *because* the real pass/fail exit code is
 * not yet known in this process at that point (Illuminate\Console\
 * Scheduling\Event::finish() — which sets the real $exitCode — is not
 * called for a background event until `schedule:finish` runs, in a
 * different PHP process later). It intentionally does not claim success
 * or failure it cannot verify.
 *
 * `Cancelled` is part of the bounded set for forward compatibility (mirrors
 * App\Telemetry\Console\ConsoleOutcome::Cancelled and App\Telemetry\Queue\
 * QueueOutcome::Cancelled) but is never produced today — Laravel's
 * Scheduler has no dedicated "cancelled" signal distinct from a skip or a
 * mutex-prevented overlap.
 */
enum SchedulerOutcome: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case OverlapPrevented = 'overlap_prevented';
    case BackgroundCompleted = 'background_completed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
