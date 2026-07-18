<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

/**
 * Bounded outcome classification for one SmartHomeActionTelemetry::wrap()
 * call, for the `ixora.action.outcome` span attribute only.
 *
 * Success/Failure/Unsupported are the three values the Phase 7B.4.3 brief
 * allows. Unknown is a reserved, currently-unused case — mirrors the
 * enum-reservation convention already used elsewhere in this Telemetry
 * layer (e.g. App\Telemetry\Queue\QueueOutcome::Unknown,
 * App\Telemetry\Scheduler\SchedulerOutcome::Unknown) — used only as a
 * fail-open fallback if a caller-supplied classifier closure itself throws
 * (SmartHomeActionTelemetry::wrap() never lets that possibility affect
 * business execution; see that class's docblock).
 */
enum SmartHomeActionOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
}
