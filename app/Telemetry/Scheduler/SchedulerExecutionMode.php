<?php

declare(strict_types=1);

namespace App\Telemetry\Scheduler;

/**
 * Bounded classification of how a scheduled event actually runs
 * (backend-generic-scheduler-instrumentation.md §"Foreground and background
 * execution"). Read directly from the public
 * Illuminate\Console\Scheduling\Event::$runInBackground flag Laravel itself
 * maintains — never inferred.
 *
 * Foreground and background are not equally observable: a foreground
 * event's completion is always seen in the same process it started in; a
 * background event's *real* completion is reported later by
 * `schedule:finish`, in a separate PHP process that shares no memory with
 * SchedulerExecutionTelemetry (see SchedulerOutcome::BackgroundCompleted).
 */
enum SchedulerExecutionMode: string
{
    case Foreground = 'foreground';
    case Background = 'background';
    case Unknown = 'unknown';
}
