<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

/**
 * Bounded classification of what triggered a Smart Home dispatch call.
 *
 * Determined and supplied by the caller — VibeSmartHomeDispatchController
 * (Manual), SceneDispatchController (SceneManual), or
 * DispatchDueSchedulesCommand (Scheduled) — never by
 * SmartHomeDispatchTelemetry or the dispatch services themselves, so
 * neither the dispatch service nor this Telemetry module ever needs to know
 * what a controller or a scheduler is (Phase 7B.4.2 "Entry point"
 * requirement — see backend-smart-home-dispatch-boundary.md).
 *
 * Future is a reserved, currently-unused case — matches the enum-reservation
 * convention already used across this Telemetry layer (e.g.
 * App\Telemetry\Scheduler\SchedulerOutcome::Cancelled/Unknown) — available
 * for an entry point added later (e.g. a voice-assistant trigger or a
 * webhook) without a breaking change to this enum.
 */
enum SmartHomeDispatchEntryPoint: string
{
    case Manual = 'manual';
    case SceneManual = 'scene_manual';
    case Scheduled = 'scheduled';
    case Future = 'future';
}
