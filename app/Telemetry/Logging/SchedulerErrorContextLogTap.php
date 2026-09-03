<?php

declare(strict_types=1);

namespace App\Telemetry\Logging;

use App\Telemetry\Scheduler\SchedulerExecutionTelemetry;
use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/**
 * Laravel log "tap" (Part 10, Phase 7B.3) that enriches existing
 * scheduled-event-failure log records with safe, bounded Scheduler context
 * — added to every channel the same way TraceCorrelationLogTap,
 * HttpErrorContextLogTap, QueueErrorContextLogTap, and
 * ConsoleErrorContextLogTap already are (TelemetryServiceProvider), so no
 * channel definition needs editing.
 *
 * Deliberately narrow, mirroring App\Telemetry\Logging\QueueErrorContextLogTap:
 * - Only touches records whose `context.exception` is a Throwable — in
 *   practice, either Illuminate\Contracts\Debug\ExceptionHandler::report()
 *   called directly from Illuminate\Console\Scheduling\ScheduleRunCommand::
 *   runEvent()'s catch block, for a real ScheduledTaskFailed.
 * - Only adds context when that exact exception object was seen by
 *   SchedulerExecutionTelemetry::scheduledTaskFailed() — looked up by
 *   object identity via SchedulerExecutionTelemetry::contextForException(),
 *   never by "the most recently executed event" (see that class's
 *   docblock §"Log correlation" for why an ambient lookup would be unsafe
 *   for a long-running `schedule:work` process running many unrelated
 *   events over its lifetime).
 * - Adds only `scheduler_event`, `scheduler_event_type`,
 *   `scheduler_execution_mode`, `scheduler_outcome`, `scheduler_exit_code`,
 *   `scheduler_timezone` — never a Schedule model ID, Vibe ID, user/device
 *   ID, full command line, argument/option values, or mutex key.
 * - Never touches `message` or `context` — only `extra`, exactly like the
 *   other Phase 7A/7B log taps (logs-philosophy.md §6).
 * - A record with no matching exception (e.g. an HTTP/Queue/Console log,
 *   or an application log unrelated to Scheduler processing) is left
 *   completely untouched — this is what keeps Scheduler context from ever
 *   appearing on an unrelated log record, and vice versa (Part 10's
 *   log-separation requirement).
 */
final class SchedulerErrorContextLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! method_exists($monolog, 'pushProcessor')) {
            return;
        }

        $monolog->pushProcessor(static function (LogRecord $record): LogRecord {
            $exception = $record->context['exception'] ?? null;

            if (! $exception instanceof Throwable) {
                return $record;
            }

            try {
                if (! app()->bound(SchedulerExecutionTelemetry::class)) {
                    return $record;
                }

                $context = app(SchedulerExecutionTelemetry::class)->contextForException($exception);

                if ($context === null) {
                    return $record;
                }
            } catch (Throwable) {
                return $record;
            }

            $record->extra = array_merge($record->extra, $context->toLogContext());

            return $record;
        });
    }
}
