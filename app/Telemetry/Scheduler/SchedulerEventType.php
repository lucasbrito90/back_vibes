<?php

declare(strict_types=1);

namespace App\Telemetry\Scheduler;

/**
 * Bounded classification of what kind of scheduled event ran, used as the
 * `event_type` label on ixora.scheduler.event.* metrics (backend-generic-
 * scheduler-instrumentation.md §"Event normalization").
 *
 * Verified, evidence-based deviation from the four types the phase brief
 * names ("command, closure, callback, and job where possible"): this
 * installed Laravel version implements Schedule::job() as a *named*
 * Illuminate\Console\Scheduling\CallbackEvent — created exactly the same
 * way as Schedule::call($closure)->name('...'). Neither the
 * ScheduledTaskStarting/Finished/Failed/Skipped events nor CallbackEvent's
 * public API expose anything that distinguishes "this CallbackEvent came
 * from ->job()" from "this CallbackEvent came from ->call()->name(...)".
 * Reaching into CallbackEvent's protected $callback property via
 * reflection to guess would violate the normalizer's own "never include
 * closure source code" / "never infer without evidence" rules just as
 * much as string-sniffing the description would. `Callback` is therefore
 * used for both — see SchedulerEventNormalizer's docblock for the full
 * reasoning. A dedicated `Job` case is intentionally not declared: an
 * unused enum case would misrepresent this as observable when it is not.
 *
 * `Shell` covers Schedule::exec()/a raw shell command event — distinct
 * from `Command` (an Artisan command Laravel built via
 * Application::formatCommandString()) per Part 4's explicit guidance to
 * never conflate the two or export the full command line for either.
 */
enum SchedulerEventType: string
{
    case Command = 'command';
    case Shell = 'shell';
    case Closure = 'closure';
    case Callback = 'callback';
    case Unknown = 'unknown';
}
